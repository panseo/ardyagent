<?php
// -----------------------------------------------------------
// ARDY LAB — Proxy AI v6.0
// -----------------------------------------------------------

// CORS dinamico: accetta sia ardy-lab.it che ardyagent
$allowedOrigins = ['https://ardy-lab.it', 'https://www.ardy-lab.it', 'https://ardyagent.ardy-lab.it'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins)) {
    header('Access-Control-Allow-Origin: ' . $origin);
} else {
    header('Access-Control-Allow-Origin: https://ardy-lab.it');
}
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/ardy-config.php';
require_once __DIR__ . '/ardy-gcal.php';
require_once __DIR__ . '/phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/phpmailer/src/SMTP.php';
require_once __DIR__ . '/phpmailer/src/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;




// -----------------------------------------------------------
// IP DELL'UTENTE
// -----------------------------------------------------------
$clientIp = $_SERVER['HTTP_CF_CONNECTING_IP']
         ?? $_SERVER['HTTP_X_FORWARDED_FOR']
         ?? $_SERVER['REMOTE_ADDR']
         ?? 'unknown';
if (strpos($clientIp, ',') !== false) {
    $clientIp = trim(explode(',', $clientIp)[0]);
}
$cleanIp = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $clientIp);

// -----------------------------------------------------------
// CREA CARTELLE SE NON ESISTONO
// -----------------------------------------------------------
foreach ([ARDY_RATE_LIMIT_DIR, ARDY_UPLOAD_DIR] as $dir) {
    if (!is_dir($dir)) mkdir($dir, 0755, true);
}

// -----------------------------------------------------------
// PULIZIA AUTOMATICA FILE RATE-LIMIT VECCHI
// -----------------------------------------------------------
if (rand(1, 100) === 1) {
    $cutoff = time() - (ARDY_RATE_LIMIT_TTL_HOURS * 3600);
    foreach (glob(ARDY_RATE_LIMIT_DIR . '*.txt') as $f) {
        if (filemtime($f) < $cutoff) @unlink($f);
    }
}

// -----------------------------------------------------------
// RATE LIMIT PER IP
// -----------------------------------------------------------
$ipFile      = ARDY_RATE_LIMIT_DIR . 'ip_' . $cleanIp . '.txt';
$ipCountFile = ARDY_RATE_LIMIT_DIR . 'ipc_' . $cleanIp . '.txt';
$currentTime = time();

if (file_exists($ipFile)) {
    $lastIpTime = (int) file_get_contents($ipFile);
    if (($currentTime - $lastIpTime) < ARDY_IP_RATE_LIMIT_SECONDS) {
        echo json_encode(['reply' => 'Troppe richieste. Attendi un momento.']);
        exit();
    }
}
file_put_contents($ipFile, $currentTime);

$ipCountData = [];
if (file_exists($ipCountFile)) {
    $ipCountData = json_decode(file_get_contents($ipCountFile), true) ?? [];
    $ipCountData = array_filter($ipCountData, function($t) use ($currentTime) {
        return ($currentTime - $t) < 3600;
    });
}
$ipCountData[] = $currentTime;
file_put_contents($ipCountFile, json_encode(array_values($ipCountData)));

if (count($ipCountData) > ARDY_IP_MAX_REQUESTS_HOUR) {
    echo json_encode(['reply' => 'Hai superato il limite orario. Riprova tra poco.']);
    exit();
}

// -----------------------------------------------------------
// INPUT
// -----------------------------------------------------------
$input     = json_decode(file_get_contents('php://input'), true);
$message   = $input['message']   ?? '';
$history   = $input['history']   ?? [];
$images    = $input['images']    ?? [];
$sessionId = $input['sessionId'] ?? 'unknown_' . time();
$cleanSession = preg_replace('/[^a-zA-Z0-9_\-]/', '', $sessionId);

// -----------------------------------------------------------
// RATE LIMIT PER SESSIONE
// -----------------------------------------------------------
$cooldownFile = ARDY_RATE_LIMIT_DIR . 'sess_' . $cleanSession . '.txt';
if (file_exists($cooldownFile)) {
    $lastTime = (int) file_get_contents($cooldownFile);
    if (($currentTime - $lastTime) < ARDY_SESSION_RATE_SECONDS) {
        echo json_encode(['reply' => 'Attendi qualche secondo prima di inviare un altro messaggio.']);
        exit();
    }
}
file_put_contents($cooldownFile, $currentTime);

// -----------------------------------------------------------
// VALIDAZIONE INPUT
// -----------------------------------------------------------
if (strlen($message) > ARDY_MAX_MESSAGE_LENGTH) {
    echo json_encode(['reply' => 'Messaggio troppo lungo.']);
    exit();
}

if (count($history) > ARDY_MAX_HISTORY_ITEMS) {
    $history = array_merge(
        array_slice($history, 0, 2),
        array_slice($history, -(ARDY_MAX_HISTORY_ITEMS - 2))
    );
}

if (count($images) > ARDY_MAX_IMAGES_PER_MSG) {
    $images = array_slice($images, 0, ARDY_MAX_IMAGES_PER_MSG);
}

// -----------------------------------------------------------
// VERIFICA MIME REALE DELLE IMMAGINI
// -----------------------------------------------------------
$allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
$validImages  = [];
foreach ($images as $img) {
    $data = $img['data'] ?? '';
    if (empty($data)) continue;
    $decoded = base64_decode($data, true);
    if ($decoded === false || strlen($decoded) < 12) continue;
    $finfo    = new finfo(FILEINFO_MIME_TYPE);
    $realMime = $finfo->buffer($decoded);
    if (in_array($realMime, $allowedMimes)) {
        $img['type'] = $realMime;
        $validImages[] = $img;
    }
}
$images = $validImages;

// -----------------------------------------------------------
// SYSTEM PROMPT
// -----------------------------------------------------------
$system = file_get_contents(__DIR__ . '/ardy-system.txt');

// -----------------------------------------------------------
// STRUMENTI PER CLAUDE
// -----------------------------------------------------------
$tools = [
    [
        'name'        => 'ottieni_disponibilita_calendario',
        'description' => 'Controlla la disponibilità del calendario di Ardy Lab in una finestra temporale. Usalo per proporre al cliente 2 finestre orarie disponibili per un sopralluogo o appuntamento.',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'start' => ['type' => 'string', 'description' => 'Data e ora inizio, ISO 8601. Es: 2026-06-10T09:00:00+02:00'],
                'end'   => ['type' => 'string', 'description' => 'Data e ora fine, ISO 8601. Es: 2026-06-10T18:00:00+02:00']
            ],
            'required' => ['start', 'end']
        ]
    ],
    [
        'name'        => 'fissa_appuntamento_calendario',
        'description' => 'Crea un evento nel calendario di Ardy Lab. Usalo solo dopo conferma del cliente.',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'start'       => ['type' => 'string', 'description' => 'Data e ora inizio, ISO 8601'],
                'end'         => ['type' => 'string', 'description' => 'Data e ora fine, ISO 8601'],
                'summary'     => ['type' => 'string', 'description' => 'Titolo evento. Es: Sopralluogo Ardy Lab — Prati / Mario Rossi'],
                'description' => ['type' => 'string', 'description' => 'Scheda completa: nome, servizio, mobile, condizioni, zona, email, indirizzo, budget, note.']
            ],
            'required' => ['start', 'end', 'summary', 'description']
        ]
    ],
    [
        'name'        => 'salva_lead_crm',
        'description' => 'Salva i dati del cliente nel CRM. Usalo SEMPRE dopo aver fissato un appuntamento o quando il cliente ha fornito contatti e info sul progetto.',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'nome'      => ['type' => 'string', 'description' => 'Nome del cliente'],
                'cognome'   => ['type' => 'string', 'description' => 'Cognome del cliente'],
                'telefono'  => ['type' => 'string', 'description' => 'Numero di telefono'],
                'email'     => ['type' => 'string', 'description' => 'Email del cliente'],
                'indirizzo' => ['type' => 'string', 'description' => 'Indirizzo completo: via, numero, piano, citofono, città'],
                'servizio'  => ['type' => 'string', 'description' => 'Tipo di servizio richiesto'],
                'mobile'    => ['type' => 'string', 'description' => 'Descrizione del mobile o pezzo'],
                'zona'      => ['type' => 'string', 'description' => 'Zona o città del cliente'],
                'budget'    => ['type' => 'string', 'description' => 'Forbice di budget comunicata'],
                'stato'     => ['type' => 'string', 'description' => 'Stato: LEAD, SOPRALLUOGO, PREVENTIVO, ACCONTO, STANDBY, PERSO'],
                'note'      => ['type' => 'string', 'description' => 'Note aggiuntive per Michela']
            ],
            'required' => ['nome', 'stato']
        ]
    ]
];

// callN8n rimossa — calendario gestito direttamente via ardy-gcal.php

// -----------------------------------------------------------
// FUNZIONE: CHIAMA ANTHROPIC
// -----------------------------------------------------------
function callAnthropic(array $messages, string $system, array $tools, string $apiKey): array {
    // Prompt caching — system prompt e tools vengono cachati
    $systemPayload = [
        ['type' => 'text', 'text' => $system, 'cache_control' => ['type' => 'ephemeral']]
    ];

    // Aggiungi cache_control all'ultimo tool
    $cachedTools = $tools;
    if (!empty($cachedTools)) {
        $lastIdx = count($cachedTools) - 1;
        $cachedTools[$lastIdx]['cache_control'] = ['type' => 'ephemeral'];
    }

    $payload = json_encode([
        'model'      => 'claude-sonnet-4-6',
        'max_tokens' => 1000,
        'system'     => $systemPayload,
        'tools'      => $cachedTools,
        'messages'   => $messages
    ]);
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt($ch, CURLOPT_POST,           true);
    curl_setopt($ch, CURLOPT_POSTFIELDS,     $payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT,        120);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
    curl_setopt($ch, CURLOPT_HTTP_VERSION,   CURL_HTTP_VERSION_1_1);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Connection: close',
        'x-api-key: ' . $apiKey,
        'anthropic-version: 2023-06-01',
        'anthropic-beta: prompt-caching-2024-07-31'
    ]);
    $response = curl_exec($ch);
    $err      = curl_error($ch);
    curl_close($ch);
    if ($err) {
        error_log('ARDY CURL ERROR: ' . $err);
        return ['error' => 'curl', 'message' => $err];
    }
    return json_decode($response, true) ?? ['error' => 'json'];
}

// -----------------------------------------------------------
// SALVATAGGIO IMMAGINI
// -----------------------------------------------------------
$savedImages = [];
if (!empty($images) && is_array($images)) {
    $sessionDir = ARDY_UPLOAD_DIR . $cleanSession . '/';
    $dirOk      = is_dir($sessionDir) || mkdir($sessionDir, 0755, true) || is_dir($sessionDir);
    $allowedImg = ['image/png', 'image/gif', 'image/webp', 'image/jpeg'];
    $maxImgByte = 12 * 1024 * 1024;   // 12 MB per immagine
    foreach (array_slice($images, 0, 8, true) as $idx => $img) {
        if (!$dirOk) break;
        $raw = base64_decode($img['data'] ?? '', true);
        if ($raw === false || strlen($raw) > $maxImgByte) continue;
        $mimeType = (new finfo(FILEINFO_MIME_TYPE))->buffer($raw);  // tipo reale, non quello dichiarato
        if (!in_array($mimeType, $allowedImg, true)) continue;
        switch($mimeType) {
            case 'image/png':  $ext = 'png'; break;
            case 'image/gif':  $ext = 'gif'; break;
            case 'image/webp': $ext = 'webp'; break;
            default:           $ext = 'jpg';
        }
        $filename = date('Ymd_His') . '_' . uniqid() . '_' . $idx . '.' . $ext;
        $filepath = $sessionDir . $filename;
        if (file_put_contents($filepath, $raw) === false) continue;
        $savedImages[] = $filepath;
    }
}

// -----------------------------------------------------------
// COSTRUISCI MESSAGGI PER ANTHROPIC
// -----------------------------------------------------------
$conversationTranscript = '';
$messages = [];

if (!empty($history)) {
    $previousMessages = array_slice($history, 0, -1);
    foreach ($previousMessages as $msg) {
        $messages[] = $msg;
        if (isset($msg['role'], $msg['content']) && is_string($msg['content'])) {
            $label = strtoupper($msg['role']) === 'USER' ? 'CLIENTE' : 'ARDY';
            $conversationTranscript .= $label . ": " . $msg['content'] . "\n\n";
        }
    }
}

$lastUserContent = [];
foreach ($images as $img) {
    $lastUserContent[] = [
        'type'   => 'image',
        'source' => ['type' => 'base64', 'media_type' => $img['type'], 'data' => $img['data']]
    ];
}
if (!empty($message)) {
    $lastUserContent[] = ['type' => 'text', 'text' => $message];
}
if (empty($lastUserContent)) {
    echo json_encode(['reply' => 'Messaggio vuoto.']);
    exit();
}
$messages[] = ['role' => 'user', 'content' => $lastUserContent];

// -----------------------------------------------------------
// AGENTIC LOOP
// -----------------------------------------------------------
$reply         = '';
$maxIterations = 5;
$iteration     = 0;

while ($iteration < $maxIterations) {
    $iteration++;
    $data = callAnthropic($messages, $system, $tools, ARDY_API_KEY);

    if (isset($data['error'])) {
        error_log('ARDY API ERROR: ' . json_encode($data));
        $reply = 'Errore nella risposta AI. Riprova.';
        break;
    }

    $stopReason = $data['stop_reason'] ?? 'end_turn';
    $content    = $data['content']     ?? [];
    $messages[] = ['role' => 'assistant', 'content' => $content];

    if ($stopReason === 'end_turn') {
        foreach ($content as $block) {
            if ($block['type'] === 'text') { $reply = $block['text']; break; }
        }
        break;
    }

    if ($stopReason === 'tool_use') {
        $toolResults = [];
        foreach ($content as $block) {
            if ($block['type'] !== 'tool_use') continue;
            $toolName  = $block['name'];
            $toolInput = $block['input'];
            $toolId    = $block['id'];
            $toolResult = '';

            if ($toolName === 'ottieni_disponibilita_calendario') {
                // Usa la data di inizio richiesta da Claude se presente
                $fromDate = null;
                if (!empty($toolInput['start'])) {
                    try { $fromDate = new DateTime($toolInput['start']); if ($fromDate < new DateTime('now')) { $fromDate = new DateTime("+7 days"); } } catch (Exception $e) { $fromDate = new DateTime("+7 days"); }
                }
                $slots = gcal_get_free_slots(14, 9, 18, $fromDate);
                if ($slots === null) {
                    $toolResult = 'Errore calendario: impossibile leggere la disponibilità.';
                } elseif (empty($slots)) {
                    $toolResult = 'Nessuno slot disponibile nel periodo richiesto.';
                } else {
                    $toolResult = json_encode($slots);
                    error_log('ARDY SLOTS: ' . $toolResult);
                }

            } elseif ($toolName === 'fissa_appuntamento_calendario') {
                // Estrai data e ora da ISO 8601
                $startDt  = new DateTime($toolInput['start']);
                $dateStr  = $startDt->format('Y-m-d');
                $timeStr  = $startDt->format('H:i');
                // Usa summary e description direttamente come li fornisce Claude
                $summary  = $toolInput['summary']     ?? 'Sopralluogo Ardy Lab';
                $desc     = $toolInput['description'] ?? '';
                $r = gcal_create_event(
                    $dateStr,
                    $timeStr,
                    $summary,
                    '',
                    '',
                    '',
                    $desc
                );
                $toolResult = $r
                    ? 'Appuntamento creato con successo nel calendario di Michela.'
                    : 'Errore nella creazione dell\'appuntamento. Riprova.';

            } elseif ($toolName === 'salva_lead_crm') {
                $ch = curl_init('https://ardyagent.ardy-lab.it/ardy-save-lead.php');
                curl_setopt($ch, CURLOPT_POST,           true);
                curl_setopt($ch, CURLOPT_POSTFIELDS,     json_encode([
                    'session_id' => $cleanSession,
                    'nome'       => $toolInput['nome']      ?? '',
                    'cognome'    => $toolInput['cognome']   ?? '',
                    'telefono'   => $toolInput['telefono']  ?? '',
                    'email'      => $toolInput['email']     ?? '',
                    'indirizzo'  => $toolInput['indirizzo'] ?? '',
                    'servizio'   => $toolInput['servizio']  ?? '',
                    'mobile'     => $toolInput['mobile']    ?? '',
                    'zona'       => $toolInput['zona']      ?? '',
                    'budget'     => $toolInput['budget']    ?? '',
                    'stato'      => $toolInput['stato']     ?? 'LEAD',
                    'note'       => $toolInput['note']      ?? '',
                ]));
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT,        15);
                curl_setopt($ch, CURLOPT_HTTPHEADER,     ['Content-Type: application/json']);
                $r = json_decode(curl_exec($ch), true);
                curl_close($ch);
                $toolResult = isset($r['success']) ? 'Cliente salvato nel CRM.' : 'Errore CRM: ' . json_encode($r);
            }

            $toolResults[] = ['type' => 'tool_result', 'tool_use_id' => $toolId, 'content' => $toolResult];
        }
        $messages[] = ['role' => 'user', 'content' => $toolResults];
        continue;
    }
    break;
}

if (empty($reply)) {
    $reply = 'Errore nella risposta AI. Riprova.';
}

// -----------------------------------------------------------
// RILEVAMENTO CONTATTI E NOTIFICA MAIL A MICHELA
// -----------------------------------------------------------
$fullConversation =
    $conversationTranscript .
    "CLIENTE: " . $message . "\n\n" .
    "ARDY: " . $reply;

$userEmail = null;
$userPhone = null;

if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $fullConversation, $m)) {
    $userEmail = $m[0];
}
if (preg_match('/(\+39)?\s?3\d{2}[\s\-]?\d{6,7}/', $fullConversation, $m)) {
    $userPhone = $m[0];
}

if ($userEmail || $userPhone) {
    $subject = '📋 Nuovo lead da Ardy AI — ' . date('d/m/Y H:i');
    $body    = "Nuovo contatto dal widget AI di Ardy Lab\n";
    $body   .= str_repeat('─', 40) . "\n\n";
    $body   .= "DATA:      " . date('d/m/Y H:i') . "\n";
    $body   .= "IP:        " . $clientIp . "\n";
    $body   .= "SESSIONE:  " . $cleanSession . "\n";
    $body   .= "EMAIL:     " . ($userEmail ?: 'non fornita') . "\n";
    $body   .= "TELEFONO:  " . ($userPhone ?: 'non fornito') . "\n\n";
    $body   .= str_repeat('─', 40) . "\n";
    $body   .= "CONVERSAZIONE\n";
    $body   .= str_repeat('─', 40) . "\n\n";
    $body   .= $fullConversation . "\n";

    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = 'smtp-relay.brevo.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = ARDY_SMTP_USER;
        $mail->Password   = ARDY_SMTP_PASSWORD;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        $mail->CharSet    = 'UTF-8';
        $mail->setFrom('noreply@ardy-lab.it', 'Ardy AI');
        $mail->addAddress(ARDY_MAIL_MICHELA);
        $mail->Subject = $subject;
        $mail->Body    = $body;
        $sessionImgDir = ARDY_UPLOAD_DIR . $cleanSession . '/';
        if (is_dir($sessionImgDir)) {
            foreach (glob($sessionImgDir . '*') as $imgPath) {
                if (file_exists($imgPath)) $mail->addAttachment($imgPath);
            }
        }
        $mail->send();
    } catch (Exception $e) {
        error_log('ARDY MAIL ERROR: ' . $mail->ErrorInfo);
    }
}

// -----------------------------------------------------------
// RISPOSTA AL WIDGET
// -----------------------------------------------------------
echo json_encode(['reply' => $reply]);