<?php
// -----------------------------------------------------------
// ARDY LAB — Proxy AI v6.0
// -----------------------------------------------------------

date_default_timezone_set('Europe/Rome');

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
require_once __DIR__ . '/ardy-db.php';
require_once __DIR__ . '/ardy-net.php';
require_once __DIR__ . '/ardy-sanitize.php';
require_once __DIR__ . '/ardy-notifica-michela.php';
require_once __DIR__ . '/ardy-email.php';
require_once __DIR__ . '/phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/phpmailer/src/SMTP.php';
require_once __DIR__ . '/phpmailer/src/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;




// -----------------------------------------------------------
// IP DELL'UTENTE
// CF-Connecting-IP / X-Forwarded-For sono fidati SOLO se la richiesta arriva
// da un edge Cloudflare noto (vedi ardyClientIp in ardy-net.php), altrimenti
// si usa REMOTE_ADDR: così l'IP del rate-limit non è falsificabile da chi
// colpisce l'origin direttamente, evitando abusi sull'API a pagamento.
// -----------------------------------------------------------
$clientIp = ardyClientIp();
$cleanIp = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $clientIp);

// -----------------------------------------------------------
// CREA CARTELLE SE NON ESISTONO
// -----------------------------------------------------------
foreach ([ARDY_RATE_LIMIT_DIR, ARDY_UPLOAD_DIR] as $dir) {
    if (!is_dir($dir)) mkdir($dir, 0755, true);
}
// Le cartelle di upload non devono eseguire script (foto/file caricati).
ardyHardenUploadDir(ARDY_UPLOAD_DIR);
ardyHardenUploadDir(ARDY_RATE_LIMIT_DIR);

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

// Limite giornaliero per IP
$today      = date('Y-m-d');
$ipDayFile  = ARDY_RATE_LIMIT_DIR . 'ipday_' . $cleanIp . '.json';
$ipDayData  = file_exists($ipDayFile) ? json_decode(file_get_contents($ipDayFile), true) : [];
if (($ipDayData['date'] ?? '') !== $today) { $ipDayData = ['date' => $today, 'count' => 0]; }
$ipDayData['count']++;
file_put_contents($ipDayFile, json_encode($ipDayData));
if ($ipDayData['count'] > 40) {
    echo json_encode(['reply' => 'Hai raggiunto il limite giornaliero di messaggi. Riprova domani oppure scrivici su ardy-lab.it.']);
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

// ── Lead da portale: link firmato ?lead=<sid>&tok=<hmac16> ──
$leadSessionId = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)($input['leadSessionId'] ?? ''));
$leadToken     = (string)($input['leadToken'] ?? '');
$leadContext    = null;
if ($leadSessionId !== '' && $leadToken !== '') {
    $expectedTok = substr(hash_hmac('sha256', $leadSessionId, WA_LOOKUP_SECRET), 0, 16);
    if (hash_equals($expectedTok, $leadToken)) {
        try {
            $ldb = ardyDB();
            $lstm = $ldb->prepare("SELECT nome, cognome, servizio, mobile, zona FROM clienti WHERE session_id = :sid AND deleted_at IS NULL LIMIT 1");
            $lstm->execute([':sid' => $leadSessionId]);
            $leadContext = $lstm->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('ARDY PROXY LEAD CONTEXT: ' . $e->getMessage());
        }
    }
}

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
        // Comprimi una volta sola: alleggerisce il disco, abbassa il costo API e
        // tiene le immagini sotto il limite di 10MB di Claude (riusa i byte compressi).
        $compressed = ardyCompressImage($decoded, $realMime);
        if ($compressed !== $decoded) {
            $img['data'] = base64_encode($compressed);
        }
        $img['type'] = $realMime;
        $validImages[] = $img;
    }
}
$images = $validImages;

// -----------------------------------------------------------
// SYSTEM PROMPT
// -----------------------------------------------------------
$system = file_get_contents(__DIR__ . '/ardy-system.txt');

// Istruzioni SOLO-WEB sul tool cerca_cliente / codice di accesso. Stanno qui (non in
// ardy-system.txt) perché quel documento è condiviso anche col canale WhatsApp, dove
// il tool NON esiste: includerle là faceva "recitare" la sintassi del tool come testo.
$system .= "\n\n## CODICE DI ACCESSO E STATO DEL LAVORO (tool cerca_cliente)\n\n"
    . "Quando salvi un cliente nel CRM, il sistema genera per lui un **codice personale** (formato ARD-XXXX-XXXX) e glielo invia via email. Quando il tool `salva_lead_crm` ti restituisce un codice, comunicalo al cliente in modo naturale: spiega che con quel codice, tornando a scriverti, potrà sapere a che punto è il suo lavoro senza ricominciare da capo. Dillo una volta, con calore — non ripeterlo a ogni messaggio.\n\n"
    . "Se un cliente ti scrive che vuole sapere lo stato del suo lavoro/sopralluogo:\n"
    . "- Se ti fornisce il suo **codice**, chiama `cerca_cliente` con quel codice e rispondi con i dati che ricevi (saluta per nome, riassumi lo stato, condividi l'eventuale link alla pagina del lavoro).\n"
    . "- Se NON ha un codice, **non** cercare per nome o telefono (sulla chat non è possibile per tutela della privacy). Invitalo a lasciare i suoi dati così gliene generiamo uno, oppure a contattare direttamente Ardy Lab.\n"
    . "Non inventare mai uno stato: riferisci solo ciò che il tool ti restituisce.\n";

// ── Contesto lead da portale (link firmato) ──
// Se il lead arriva dal link nel WhatsApp di primo contatto, ha già una scheda:
// Sole lo saluta per nome e sa già cosa ha chiesto, senza ripartire da zero.
if ($leadContext) {
    $ln = trim(($leadContext['nome'] ?? '') . ' ' . ($leadContext['cognome'] ?? ''));
    $ls = trim((string)($leadContext['servizio'] ?? ''));
    $lm = trim((string)($leadContext['mobile'] ?? ''));
    $lz = trim((string)($leadContext['zona'] ?? ''));
    $system .= "\n\n## LEAD DA PORTALE — CONTESTO PRECARICATO\n"
        . "Questo cliente arriva da un link che gli abbiamo mandato su WhatsApp dopo aver visto la sua richiesta su un portale (ProntoPro o simili). "
        . "Ha già una scheda nel CRM. NON ripartire da zero con la qualifica: salutalo per nome, mostra che sai già cosa ha chiesto e prosegui da lì.\n";
    if ($ln) $system .= "- Nome: {$ln}\n";
    if ($ls) $system .= "- Servizio richiesto: {$ls}\n";
    if ($lm) $system .= "- Mobile/oggetto: {$lm}\n";
    if ($lz) $system .= "- Zona: {$lz}\n";
    $system .= "Parti con un saluto caldo che mostra che lo conosci già, es: \"Ciao {$ln}! Ho visto la tua richiesta per {$ls}. Raccontami di più, così posso darti un'idea...\" e prosegui la qualifica da dove serve (foto, misure, sopralluogo).\n";
}

// Conoscenza di bottega (legno/restauro/cura): arricchisce il linguaggio e la competenza
// di Sole. Sta in un file a sé (sapere ≠ regole) e resta DENTRO il blocco system cacheato
// → costo token trascurabile dal 2° messaggio in poi.
$conoscenza = @file_get_contents(__DIR__ . '/ardy-conoscenza-restauro.txt');
if ($conoscenza !== false && $conoscenza !== '') {
    $system .= "\n\n---\n" . $conoscenza;
}

// Conoscenza APPRESA dalle fasi di lavoro reali (autoapprendimento, blocco DB
// separato, curato/approvato da Michela). Resta dentro il blocco system cacheato.
require_once __DIR__ . '/ardy-conoscenza-appresa.php';
$appresa = ardy_conoscenza_appresa_blocco(ardyDB());
if ($appresa !== '') {
    $system .= "\n\n---\n" . $appresa;
}

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
                'stato'     => ['type' => 'string', 'description' => 'Stato: LEAD, SOPRALLUOGO, PREVENTIVO, ACCONTO, RITIRATI (mobile ritirato, in laboratorio, lavori non avviati), IN_LAVORAZIONE, STANDBY, PERSO'],
                'note'      => ['type' => 'string', 'description' => 'Note aggiuntive per Michela']
            ],
            'required' => ['nome', 'stato']
        ]
    ],
    [
        'name'        => 'sposta_appuntamento',
        'description' => 'Sposta un sopralluogo GIÀ fissato a una nuova data/ora. Usalo quando un cliente che ha già un appuntamento chiede di spostarlo. Prima verifica la disponibilità del nuovo periodo con ottieni_disponibilita_calendario e fatti confermare dal cliente un orario preciso; poi chiama questo strumento. Identifica il cliente col suo numero di telefono.',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'telefono' => ['type' => 'string', 'description' => 'Numero di telefono con cui il cliente è registrato (serve a ritrovare il suo appuntamento)'],
                'start'    => ['type' => 'string', 'description' => 'Nuova data e ora di inizio, ISO 8601. Es: 2026-06-20T11:00:00+02:00']
            ],
            'required' => ['telefono', 'start']
        ]
    ],
    [
        'name'        => 'avvisa_michela',
        'description' => 'Invia a Michela una notifica WhatsApp breve, come farebbe una segretaria efficiente. Usalo SOLO quando emerge qualcosa che Michela deve sapere subito e che NON è già coperto dal salvataggio lead/appuntamento: un reclamo o insoddisfazione, un problema di pagamento, una richiesta di modifica a un lavoro già concordato, oppure una richiesta fuori standard (tempi urgenti, lavoro particolare). Non usarlo per conversazioni di routine.',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'messaggio' => ['type' => 'string', 'description' => 'Riepilogo breve, diretto e azionabile per Michela. Includi nome del cliente, di cosa si tratta e cosa serve fare. Es: "Mario Rossi (Prati) si lamenta: dice che il preventivo era diverso da quanto concordato. Vuole essere richiamato."'],
                'motivo'    => ['type' => 'string', 'description' => 'Categoria: reclamo | pagamento | modifica | fuori_standard | altro']
            ],
            'required' => ['messaggio']
        ]
    ],
    [
        'name'        => 'cerca_cliente',
        'description' => 'Recupera lo stato del lavoro di un cliente che possiede un CODICE DI ACCESSO Ardy Lab (formato ARD-XXXX-XXXX), ricevuto via email quando ha lasciato i suoi dati. Usalo SOLO quando il cliente ti comunica spontaneamente il suo codice e vuole sapere a che punto è il suo lavoro o il suo sopralluogo. NON cercare MAI per nome, telefono o email: serve esclusivamente il codice. Se il cliente non ha un codice, invitalo a lasciare i suoi dati (così gliene generiamo uno) oppure a contattare Ardy Lab.',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'codice' => ['type' => 'string', 'description' => 'Il codice di accesso comunicato dal cliente, es. ARD-7F3K-9Q2P']
            ],
            'required' => ['codice']
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
// Assicura le colonne per il sopralluogo sulla tabella clienti (idempotente)
//   sopralluogo_at = data/ora dell'appuntamento · gcal_event_id = id evento Google
// -----------------------------------------------------------
function ardy_ensure_sopralluogo_cols(PDO $db): void {
    // colonne garantite da ardy-migrate.php
}

// -----------------------------------------------------------
// Codice di accesso cliente — capability per la chat web PUBBLICA/anonima.
//   `codice_accesso` è un codice corto ad alta entropia dato al cliente:
//   con quello, tornando sulla chat, può chiedere lo stato del suo lavoro.
//   NON è il session_id (timestamp → enumerabile): è casuale, stabile e
//   legato a UNA scheda → niente ricerca per nome/telefono, niente PII di terzi.
// -----------------------------------------------------------
function ardy_ensure_codice_col(PDO $db): void {
    // colonne garantite da ardy-migrate.php
    $done = true;
}

// Genera un codice ARD-XXXX-XXXX (alfabeto senza caratteri ambigui 0/O/1/I/L):
// 8 simboli su 31 → ~40 bit, non indovinabile ma dettabile a voce.
function ardy_genera_codice_accesso(): string {
    $alpha = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $n = strlen($alpha);
    $out = '';
    for ($i = 0; $i < 8; $i++) {
        $out .= $alpha[random_int(0, $n - 1)];
        if ($i === 3) $out .= '-';
    }
    return 'ARD-' . $out; // es. ARD-7F3K-9Q2P
}

// Normalizza un codice digitato dal cliente per il confronto (maiuscolo,
// via separatori; tollera trattini mancanti). Restituisce il formato canonico
// ARD-XXXX-XXXX se possibile, altrimenti la stringa ripulita (non matcherà).
function ardy_normalizza_codice(string $raw): string {
    $s = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $raw));
    if (preg_match('/^ARD([A-Z0-9]{4})([A-Z0-9]{4})$/', $s, $m)) {
        return 'ARD-' . $m[1] . '-' . $m[2];
    }
    return $s;
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
$bookingMade   = false;   // diventa true se viene fissato un sopralluogo
$bookingWhen   = null;    // DateTime dell'appuntamento
$bookingEventId = null;   // id evento Google Calendar appena creato
$leadSaved     = false;   // diventa true quando il lead è salvato nel CRM
$leadData      = [];      // ultimi dati lead salvati (per il riepilogo a Michela)
$rescheduleNote = null;   // riepilogo per Michela se un appuntamento viene spostato
$accessCode    = null;    // codice di accesso appena generato (da emailare al cliente)
$accessEmail   = null;    // email a cui inviare il codice

while ($iteration < $maxIterations) {
    $iteration++;
    $data = callAnthropic($messages, $system, $tools, ARDY_API_KEY);

    // Ritenta in caso di intoppo momentaneo dell'API (sovraccarico/timeout):
    // così il cliente non vede l'errore per un singhiozzo passeggero.
    $retry = 0;
    while (isset($data['error']) && $retry < 2) {
        $retry++;
        error_log('ARDY API retry ' . $retry . ': ' . json_encode($data));
        usleep(800000); // 0,8 secondi
        $data = callAnthropic($messages, $system, $tools, ARDY_API_KEY);
    }

    if (isset($data['error'])) {
        error_log('ARDY API ERROR (dopo ' . $retry . ' ritentativi): ' . json_encode($data));
        $reply = 'Errore nella risposta AI. Riprova.';
        break;
    }

    // Audit prompt caching: logga l'usage per verificare che gli hit arrivino davvero.
    // Se cache_read resta a 0 c'è un invalidatore nascosto nel prefisso (system/tools).
    if (isset($data['usage'])) {
        $u = $data['usage'];
        error_log(sprintf(
            'ARDY USAGE iter=%d in=%d out=%d cache_read=%d cache_write=%d',
            $iteration,
            (int)($u['input_tokens'] ?? 0),
            (int)($u['output_tokens'] ?? 0),
            (int)($u['cache_read_input_tokens'] ?? 0),
            (int)($u['cache_creation_input_tokens'] ?? 0)
        ));
    }

    $stopReason = $data['stop_reason'] ?? 'end_turn';
    $content    = $data['content']     ?? [];
    $messages[] = ['role' => 'assistant', 'content' => $content];

    if ($stopReason === 'end_turn') {
        foreach ($content as $block) {
            if ($block['type'] === 'text') { $reply = ardy_strip_tool_syntax($block['text']); break; }
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
                // Guard anti-doppione: se la scheda ha GIÀ un appuntamento (in questo
                // giro o sul CRM), non crearne un secondo. Reindirizza allo spostamento.
                $existingEventId = $bookingEventId;
                if (empty($existingEventId)) {
                    try {
                        $db = ardyDB();
                        ardy_ensure_sopralluogo_cols($db);
                        $qb = $db->prepare("SELECT gcal_event_id FROM clienti WHERE session_id = :sid LIMIT 1");
                        $qb->execute([':sid' => $cleanSession]);
                        $existingEventId = trim((string) $qb->fetchColumn());
                    } catch (PDOException $e) { $existingEventId = ''; }
                }
                if (!empty($existingEventId)) {
                    $toolResult = 'Questo cliente ha GIÀ un appuntamento fissato: NON crearne un altro (creeresti un doppione nel calendario). Se vuole cambiare data usa sposta_appuntamento; altrimenti conferma l\'appuntamento già esistente.';
                } else try {
                    if (empty($toolInput['start'])) {
                        $toolResult = 'Errore: data/ora mancante. Chiedi al cliente di confermare giorno e ora.';
                    } else {
                        $startDt  = new DateTime($toolInput['start']);
                        $dateStr  = $startDt->format('Y-m-d');
                        $timeStr  = $startDt->format('H:i');
                        // Usa summary e description direttamente come li fornisce Claude
                        $summary  = $toolInput['summary']     ?? 'Sopralluogo Ardy Lab';
                        $desc     = $toolInput['description'] ?? '';
                        $r = gcal_create_event($dateStr, $timeStr, $summary, '', '', '', $desc);
                        if ($r) {
                            $bookingMade = true;
                            $bookingWhen = $startDt;
                            // gcal_create_event ritorna l'evento completo: tieni l'id per poterlo spostare in futuro
                            $bookingEventId = is_array($r) ? ($r['id'] ?? null) : null;
                        }
                        $toolResult = $r
                            ? 'Appuntamento creato con successo nel calendario di Michela.'
                            : 'Errore nella creazione dell\'appuntamento. Riprova.';
                    }
                } catch (Exception $e) {
                    error_log('ARDY BOOKING ERROR: ' . $e->getMessage() . ' input=' . json_encode($toolInput));
                    $toolResult = 'Errore tecnico nella prenotazione. Chiedi al cliente di riprovare.';
                }

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
                $saveHeaders = ['Content-Type: application/json'];
                if (defined('ARDY_INTERNAL_SECRET') && ARDY_INTERNAL_SECRET !== '') {
                    // marca la chiamata come interna → esente dal rate-limit pubblico
                    $saveHeaders[] = 'X-Ardy-Internal: ' . ARDY_INTERNAL_SECRET;
                }
                curl_setopt($ch, CURLOPT_HTTPHEADER,     $saveHeaders);
                $r = json_decode(curl_exec($ch), true);
                curl_close($ch);
                if (isset($r['success'])) {
                    $leadSaved = true;
                    $leadData  = $toolInput;   // per il riepilogo WhatsApp a Michela

                    // Codice di accesso: generato UNA volta sola per scheda. Con questo
                    // il cliente potrà poi chiedere lo stato del lavoro (tool cerca_cliente).
                    $codice    = '';
                    $giaInviata = false;
                    try {
                        $db = ardyDB();
                        ardy_ensure_codice_col($db);
                        $sel = $db->prepare("SELECT codice_accesso, codice_email_inviato FROM clienti WHERE session_id = :sid LIMIT 1");
                        $sel->execute([':sid' => $cleanSession]);
                        $row = $sel->fetch(PDO::FETCH_ASSOC) ?: [];
                        $codice     = trim((string) ($row['codice_accesso'] ?? ''));
                        $giaInviata = !empty($row['codice_email_inviato']);
                        if ($codice === '') {
                            // genera un codice unico (riprova in caso di collisione rarissima)
                            for ($t = 0; $t < 5; $t++) {
                                $cand = ardy_genera_codice_accesso();
                                $chk  = $db->prepare("SELECT 1 FROM clienti WHERE codice_accesso = :c LIMIT 1");
                                $chk->execute([':c' => $cand]);
                                if (!$chk->fetchColumn()) { $codice = $cand; break; }
                            }
                            if ($codice !== '') {
                                $db->prepare("UPDATE clienti SET codice_accesso = :c, updated_at = NOW() WHERE session_id = :sid")
                                   ->execute([':c' => $codice, ':sid' => $cleanSession]);
                            }
                        }
                        // Invio email: appena abbiamo codice + email valida e NON ancora inviata,
                        // a prescindere da quale salvataggio porta l'email (Sole salva a step).
                        if ($codice !== '' && !$giaInviata) {
                            $emailLead = trim((string) ($toolInput['email'] ?? ''));
                            if ($emailLead === '') {
                                // l'email può non essere in QUESTO input: ripescala dal CRM
                                $qe = $db->prepare("SELECT email FROM clienti WHERE session_id = :sid LIMIT 1");
                                $qe->execute([':sid' => $cleanSession]);
                                $emailLead = trim((string) $qe->fetchColumn());
                            }
                            if ($emailLead !== '' && filter_var($emailLead, FILTER_VALIDATE_EMAIL)) {
                                $accessCode  = $codice;
                                $accessEmail = $emailLead;
                            }
                        }
                    } catch (PDOException $e) {
                        error_log('ARDY CODICE ACCESSO ERROR: ' . $e->getMessage());
                        $codice = '';
                    }

                    $toolResult = 'Cliente salvato nel CRM.';
                    if ($codice !== '') {
                        $toolResult .= ' Codice di accesso del cliente: ' . $codice . '.';
                        if ($accessCode) {
                            $toolResult .= ' Glielo stiamo inviando via email; comunicaglielo anche a voce.';
                        } elseif ($giaInviata) {
                            $toolResult .= ' Già inviato via email; puoi ricordarglielo.';
                        } else {
                            $toolResult .= ' Comunicaglielo a voce: con questo codice potrà chiederti lo stato del lavoro. Se ti lascia un\'email te lo inviamo anche scritto.';
                        }
                    }
                } else {
                    $toolResult = 'Errore CRM: ' . json_encode($r);
                }

            } elseif ($toolName === 'sposta_appuntamento') {
                $tel   = preg_replace('/\D+/', '', (string)($toolInput['telefono'] ?? ''));
                $start = $toolInput['start'] ?? '';
                if ($tel === '' || $start === '') {
                    $toolResult = 'Errore: servono il telefono del cliente e la nuova data/ora.';
                } else {
                    try {
                        $startDt = new DateTime($start);
                        if ($startDt < new DateTime('now')) {
                            $toolResult = 'La nuova data è nel passato: chiedi al cliente una data futura.';
                        } else {
                            // Trova il cliente e l'evento collegato tramite le ultime 9 cifre del telefono
                            $db = ardyDB();
                            ardy_ensure_sopralluogo_cols($db);
                            ardyEnsureTelefonoLast9($db);
                            $q = $db->prepare(
                                "SELECT session_id, nome, cognome, gcal_event_id FROM clienti
                                  WHERE telefono_last9 = :p
                                    AND gcal_event_id IS NOT NULL AND gcal_event_id <> ''
                               ORDER BY updated_at DESC, id DESC LIMIT 1"
                            );
                            $q->execute([':p' => substr($tel, -9)]);
                            $cli = $q->fetch(PDO::FETCH_ASSOC);

                            if (!$cli) {
                                $toolResult = 'Non trovo un appuntamento collegato a questo numero. Raccogli la richiesta e di\' che Michela ricontatta il cliente per riorganizzare.';
                            } else {
                                $dateStr = $startDt->format('Y-m-d');
                                $timeStr = $startDt->format('H:i');
                                $free = gcal_is_slot_free($dateStr, $timeStr, 2);
                                if ($free === false) {
                                    $toolResult = 'Quel nuovo orario è già occupato. Proponi al cliente un altro slot tra quelli liberi.';
                                } else {
                                    // free === true (libero) oppure null (impossibile verificare): procediamo comunque
                                    $upd = gcal_update_event($cli['gcal_event_id'], $dateStr, $timeStr, 2);
                                    if (!$upd) {
                                        $toolResult = 'Non sono riuscita a spostare l\'appuntamento sul calendario. Riprova o di\' che Michela ricontatta.';
                                    } else {
                                        $db->prepare("UPDATE clienti SET sopralluogo_at = :dt, stato = 'SOPRALLUOGO', updated_at = NOW() WHERE session_id = :sid")
                                           ->execute([':dt' => $startDt->format('Y-m-d H:i:s'), ':sid' => $cli['session_id']]);
                                        $bookingMade = true;
                                        $bookingWhen = $startDt;
                                        $nomeCli = trim(($cli['nome'] ?? '') . ' ' . ($cli['cognome'] ?? '')) ?: 'cliente';
                                        $rescheduleNote = "📅 Sopralluogo SPOSTATO: " . $nomeCli . " → " . ardy_data_ita($startDt);
                                        $toolResult = 'Appuntamento spostato con successo al nuovo orario nel calendario di Michela.';
                                    }
                                }
                            }
                        }
                    } catch (Exception $e) {
                        error_log('ARDY SPOSTA APPUNTAMENTO ERROR: ' . $e->getMessage());
                        $toolResult = 'Errore tecnico nello spostamento. Chiedi al cliente di riprovare.';
                    }
                }

            } elseif ($toolName === 'avvisa_michela') {
                $msg = trim((string) ($toolInput['messaggio'] ?? ''));
                if ($msg === '') {
                    $toolResult = 'Errore: messaggio mancante.';
                } else {
                    $motivo = $toolInput['motivo'] ?? 'altro';
                    $testo  = "🔔 Sole — segnalazione (" . $motivo . ")\n\n" . $msg;
                    // Dedupe sul contenuto: evita doppioni se il tool viene richiamato nello stesso giro
                    $ok = notificaMichela($testo, 'avvisa:' . $cleanSession . ':' . md5($msg));
                    $toolResult = $ok
                        ? 'Michela è stata avvisata su WhatsApp.'
                        : 'Avviso registrato (eventuale invio WhatsApp gestito a parte).';
                }

            } elseif ($toolName === 'cerca_cliente') {
                $codice = ardy_normalizza_codice((string) ($toolInput['codice'] ?? ''));
                // Anti-bruteforce: cap sui tentativi per sessione (validi o no) nell'ultima ora.
                $attemptsFile = ARDY_RATE_LIMIT_DIR . 'cerca_' . $cleanSession . '.json';
                $nowTs = time();
                $att   = file_exists($attemptsFile) ? (json_decode(file_get_contents($attemptsFile), true) ?: []) : [];
                $att   = array_values(array_filter($att, fn($t) => ($nowTs - $t) < 3600));
                if (count($att) >= 8) {
                    $toolResult = 'Troppi tentativi con il codice in questa sessione. Di\' al cliente di riprovare più tardi o di contattare Ardy Lab al 351 967 7973.';
                } elseif (!preg_match('/^ARD-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $codice)) {
                    $att[] = $nowTs; @file_put_contents($attemptsFile, json_encode($att));
                    $toolResult = 'Codice non valido: il formato è ARD-XXXX-XXXX. Chiedi al cliente di ricontrollarlo nell\'email che gli abbiamo inviato.';
                } else {
                    $att[] = $nowTs; @file_put_contents($attemptsFile, json_encode($att));
                    try {
                        $db = ardyDB();
                        ardy_ensure_codice_col($db);
                        ardy_ensure_sopralluogo_cols($db);
                        $q = $db->prepare("SELECT session_id, nome, stato, servizio, mobile, sopralluogo_at, wp_post_link FROM clienti WHERE codice_accesso = :c LIMIT 1");
                        $q->execute([':c' => $codice]);
                        $cli = $q->fetch(PDO::FETCH_ASSOC);
                        if (!$cli) {
                            $toolResult = 'Nessun cliente trovato con questo codice. Chiedi di ricontrollarlo nell\'email, oppure di lasciare i dati per generarne uno nuovo.';
                        } else {
                            // Data minimization: SOLO stato/avanzamento. Niente email/telefono/indirizzo.
                            $info = ['nome' => $cli['nome'] ?: '', 'stato' => $cli['stato'] ?: 'LEAD'];
                            if (!empty($cli['servizio'])) $info['servizio'] = $cli['servizio'];
                            if (!empty($cli['mobile']))   $info['mobile']   = $cli['mobile'];
                            if (!empty($cli['sopralluogo_at'])) {
                                try {
                                    $sdt = new DateTime($cli['sopralluogo_at']);
                                    $info['sopralluogo'] = ardy_data_ita($sdt);
                                    $info['sopralluogo_passato'] = ($sdt < new DateTime('now'));
                                } catch (Exception $e) { /* data illeggibile: la salto */ }
                            }
                            // ultima fase di lavorazione pubblicata (se presente)
                            try {
                                $qf = $db->prepare("SELECT fase_nome FROM fasi WHERE session_id = :sid AND (fase_tipo IS NULL OR fase_tipo <> 'comunicazione') ORDER BY created_at DESC LIMIT 1");
                                $qf->execute([':sid' => $cli['session_id']]);
                                $fase = $qf->fetchColumn();
                                if ($fase) $info['ultima_fase'] = $fase;
                            } catch (PDOException $e) { /* tabella fasi assente: ignora */ }
                            if (!empty($cli['wp_post_link'])) $info['pagina_lavoro'] = $cli['wp_post_link'];
                            $toolResult = 'Cliente trovato. Rispondi con tono caldo e personalizzato: saluta per nome, riassumi lo stato del lavoro e, se c\'è una pagina lavoro, condividi il link. Dati: ' . json_encode($info, JSON_UNESCAPED_UNICODE);
                            // Contesto completo (client-safe: niente note interne) per dare a Sole
                            // il quadro del cliente verificato. Usalo come background, non recitarlo.
                            try {
                                require_once __DIR__ . '/ardy-dossier.php';
                                $dossier = ardy_genera_dossier($db, (string) $cli['session_id'], true, true);
                                if ($dossier) {
                                    $toolResult .= "\n\n--- SCHEDA COMPLETA DEL CLIENTE (riservata a te, Sole: usala come contesto, NON elencarla; rispondi solo a ciò che chiede) ---\n" . $dossier;
                                }
                            } catch (Throwable $e) { error_log('ARDY CERCA CLIENTE dossier: ' . $e->getMessage()); }
                        }
                    } catch (PDOException $e) {
                        error_log('ARDY CERCA CLIENTE ERROR: ' . $e->getMessage());
                        $toolResult = 'Errore tecnico nel recupero dei dati. Chiedi al cliente di riprovare tra poco.';
                    }
                }
            }

            $toolResults[] = ['type' => 'tool_result', 'tool_use_id' => $toolId, 'content' => $toolResult];
        }
        $messages[] = ['role' => 'user', 'content' => $toolResults];
        continue;
    }
    break;
}

if (empty($reply)) {
    error_log('ARDY EMPTY REPLY: iter=' . $iteration . ' stop=' . ($data['stop_reason'] ?? '?') . ' data=' . json_encode($data ?? null));
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
    $subject = '📋 Nuovo lead da Sole AI — ' . date('d/m/Y H:i');
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
// CONFERMA SOPRALLUOGO AL CLIENTE (se prenotato e email disponibile)
// -----------------------------------------------------------
if ($bookingMade && $bookingWhen instanceof DateTime && $userEmail && filter_var($userEmail, FILTER_VALIDATE_EMAIL)) {
    $giorni = ['Monday'=>'lunedì','Tuesday'=>'martedì','Wednesday'=>'mercoledì','Thursday'=>'giovedì','Friday'=>'venerdì','Saturday'=>'sabato','Sunday'=>'domenica'];
    $mesi   = ['January'=>'gennaio','February'=>'febbraio','March'=>'marzo','April'=>'aprile','May'=>'maggio','June'=>'giugno','July'=>'luglio','August'=>'agosto','September'=>'settembre','October'=>'ottobre','November'=>'novembre','December'=>'dicembre'];
    $gg     = $giorni[$bookingWhen->format('l')] ?? '';
    $mm     = $mesi[$bookingWhen->format('F')] ?? '';
    $quando = trim(ucfirst($gg) . ' ' . $bookingWhen->format('j') . ' ' . $mm . ' ' . $bookingWhen->format('Y') . ' alle ' . $bookingWhen->format('H:i'));

    try {
        $mailC = new PHPMailer(true);
        $mailC->isSMTP();
        $mailC->Host       = 'smtp-relay.brevo.com';
        $mailC->SMTPAuth   = true;
        $mailC->Username   = ARDY_SMTP_USER;
        $mailC->Password   = ARDY_SMTP_PASSWORD;
        $mailC->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mailC->Port       = 587;
        $mailC->CharSet    = 'UTF-8';
        $mailC->setFrom('noreply@ardy-lab.it', 'Ardy Lab');
        $mailC->addAddress($userEmail);
        $mailC->Subject = '✅ Sopralluogo Ardy Lab — ' . $quando;
        $mailC->isHTML(true);
        $mailC->Body = '
<div style="font-family:Georgia,serif;max-width:600px;margin:0 auto;padding:32px;color:#333;">
  ' . ardy_email_logo_cid($mailC) . '
  <p style="color:#999;font-size:13px;margin-bottom:24px;">Conferma sopralluogo</p>
  <p style="font-size:15px;line-height:1.7;">Ciao,<br>abbiamo fissato il tuo sopralluogo. Ecco il riepilogo:</p>
  <div style="border-left:3px solid #c8a96e;padding:12px 20px;background:#fafaf8;margin:20px 0;font-size:16px;">
    <strong>' . htmlspecialchars($quando) . '</strong>
  </div>
  <p style="font-size:15px;line-height:1.7;">Per qualsiasi modifica o domanda puoi rispondere a questa email oppure chiamarci al <strong>351 967 7973</strong>. A presto!</p>
  <p style="margin-top:32px;font-size:12px;color:#bbb;">Ardy Lab — Restauro e laccatura mobili · Roma</p>
</div>';
        $mailC->send();
        error_log('ARDY CONFERMA CLIENTE OK: ' . $userEmail);
    } catch (Exception $e) {
        error_log('ARDY CONFERMA CLIENTE ERROR: ' . $mailC->ErrorInfo);
    }
}

// -----------------------------------------------------------
// EMAIL DEL CODICE DI ACCESSO AL CLIENTE (solo alla prima generazione)
// Il cliente lo ritrova in posta: con questo codice, tornando sulla chat,
// può chiedere a Sole lo stato del suo lavoro. È una capability, non PII di terzi.
// -----------------------------------------------------------
if ($accessCode && $accessEmail && filter_var($accessEmail, FILTER_VALIDATE_EMAIL)) {
    try {
        $mailK = new PHPMailer(true);
        $mailK->isSMTP();
        $mailK->Host       = 'smtp-relay.brevo.com';
        $mailK->SMTPAuth   = true;
        $mailK->Username   = ARDY_SMTP_USER;
        $mailK->Password   = ARDY_SMTP_PASSWORD;
        $mailK->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mailK->Port       = 587;
        $mailK->CharSet    = 'UTF-8';
        $mailK->setFrom('noreply@ardy-lab.it', 'Ardy Lab');
        $mailK->addAddress($accessEmail);
        $mailK->Subject = 'Benvenuto in Ardy Lab 🌿 — e il tuo codice personale';
        $mailK->isHTML(true);
        // Logo incorporato via CID: i client di posta lo mostrano senza hotlink esterni
        $logoTag = ardy_email_logo_cid($mailK);
        $mailK->Body = '
<div style="font-family:Georgia,serif;max-width:600px;margin:0 auto;padding:32px;color:#333;">
  ' . $logoTag . '
  <p style="color:#999;font-size:13px;margin-bottom:24px;">Benvenuto</p>
  <p style="font-size:15px;line-height:1.7;">Ciao, e benvenuto in Ardy Lab! 🌿</p>
  <p style="font-size:15px;line-height:1.7;">In chat hai appena conosciuto <strong>Sole</strong>, la nostra assistente: sì, è proprio lei che ti ha risposto. Perché da noi non ci limitiamo a restaurare i tuoi mobili — ti seguiamo con un customer care di nuova generazione, sempre a portata di mano: crediamo di essere tra i primi in Italia a offrirlo così.</p>
  <p style="font-size:15px;line-height:1.7;">E per renderti tutto semplice, da oggi hai un <strong>codice personale</strong> Ardy Lab:</p>
  <div style="border-left:3px solid #c8a96e;padding:16px 24px;background:#fafaf8;margin:20px 0;font-size:24px;letter-spacing:2px;font-family:monospace;">
    <strong>' . htmlspecialchars($accessCode) . '</strong>
  </div>
  <p style="font-size:15px;line-height:1.7;"><strong>A cosa serve.</strong> Quando vuoi un aggiornamento, torna sulla nostra chat — <a href="https://ardy-lab.it/ardy-agent/" style="color:#c8a96e;">ardy-lab.it/ardy-agent</a> — e comunica questo codice a Sole: ti dirà subito a che punto è il tuo lavoro, la data del sopralluogo e i prossimi passi — senza dover rispiegare nulla da capo.</p>
  <div style="border-radius:8px;background:#fbf8f2;padding:16px 20px;margin:20px 0;font-size:14px;line-height:1.6;color:#555;">
    🔒 <strong>È la tua chiave personale.</strong> Protegge i tuoi dati: solo chi possiede il codice può consultare lo stato della tua pratica.<br>
    Per questo non lo pubblichiamo e non lo condividiamo con nessuno — tienilo per te, come faresti con un PIN.
  </div>
  <p style="font-size:15px;line-height:1.7;">💬 <strong>Preferisci WhatsApp?</strong> Trovi Sole anche lì: scrivile al <strong>+39 379 375 6437</strong> e continui la conversazione come in chat — anche solo per sapere come procede il tuo lavoro.</p>
  <p style="margin:8px 0 4px;"><a href="https://wa.me/393793756437" style="display:inline-block;background:#25D366;color:#ffffff;text-decoration:none;font-family:sans-serif;font-size:14px;font-weight:600;padding:11px 22px;border-radius:6px;">Apri la chat WhatsApp</a></p>
  ' . ardy_email_social_links() . '
  <p style="font-size:15px;line-height:1.7;margin-top:24px;">Conserva pure questa email: il tuo codice lo ritrovi quando vuoi. E per qualsiasi cosa ti basta rispondere qui — ti leggiamo sempre. A presto! 🌿</p>
  <p style="margin-top:32px;font-size:12px;color:#bbb;">Ardy Lab — Restauro e laccatura mobili · Roma</p>
</div>';
        $mailK->send();
        error_log('ARDY CODICE EMAIL OK: ' . $accessEmail);
        // segna l'invio così non si ripete ai salvataggi successivi della stessa scheda
        try {
            $db = ardyDB();
            $db->prepare("UPDATE clienti SET codice_email_inviato = NOW() WHERE session_id = :sid")
               ->execute([':sid' => $cleanSession]);
        } catch (PDOException $e) {
            error_log('ARDY CODICE EMAIL FLAG ERROR: ' . $e->getMessage());
        }
    } catch (Exception $e) {
        error_log('ARDY CODICE EMAIL ERROR: ' . $mailK->ErrorInfo);
    }
}

// -----------------------------------------------------------
// PERSISTE L'APPUNTAMENTO NEL CRM (data + id evento Google)
// Così i riepiloghi mostrano la data vera e l'appuntamento è spostabile in futuro.
// -----------------------------------------------------------
if ($bookingEventId && $bookingWhen instanceof DateTime) {
    try {
        $db = ardyDB();
        ardy_ensure_sopralluogo_cols($db);
        $db->prepare("UPDATE clienti SET gcal_event_id = :eid, sopralluogo_at = :dt, updated_at = NOW() WHERE session_id = :sid")
           ->execute([
               ':eid' => $bookingEventId,
               ':dt'  => $bookingWhen->format('Y-m-d H:i:s'),
               ':sid' => $cleanSession,
           ]);
    } catch (PDOException $e) {
        error_log('ARDY SOPRALLUOGO PERSIST ERROR: ' . $e->getMessage());
    }
}

// -----------------------------------------------------------
// NOTIFICA WHATSAPP A MICHELA (lead salvato, sopralluogo fissato o spostato)
// Sole come segretaria: riepilogo breve e azionabile. Dedupe sul contenuto,
// così non si ripete su ogni messaggio della stessa sessione.
// -----------------------------------------------------------
if ($rescheduleNote !== null) {
    // Appuntamento spostato: avviso dedicato
    notificaMichela($rescheduleNote, 'spostato:' . $cleanSession . ':' . md5($rescheduleNote));
} elseif ($leadSaved || $bookingMade) {
    $nomeCompleto = trim(($leadData['nome'] ?? '') . ' ' . ($leadData['cognome'] ?? ''));
    if ($nomeCompleto === '') $nomeCompleto = 'Nuovo contatto';
    $tel = $leadData['telefono'] ?? $userPhone ?? '';

    $righe = ['🔔 Sole — aggiornamento', '', '👤 ' . $nomeCompleto];
    if ($tel !== '')                          $righe[] = '📞 ' . $tel;
    if (!empty($leadData['servizio']))        $righe[] = '🛠️ ' . $leadData['servizio'];
    if (!empty($leadData['mobile']))          $righe[] = '🪑 ' . $leadData['mobile'];
    if (!empty($leadData['zona']))            $righe[] = '📍 ' . $leadData['zona'];
    if (!empty($leadData['budget']))          $righe[] = '💶 ' . $leadData['budget'];

    if ($bookingMade && $bookingWhen instanceof DateTime) {
        $righe[] = '';
        $righe[] = '📅 Sopralluogo fissato: ' . ardy_data_ita($bookingWhen);
    }
    if (!empty($leadData['note'])) {
        $righe[] = '';
        $righe[] = '📝 ' . $leadData['note'];
    }

    $riepilogo = implode("\n", $righe);
    notificaMichela($riepilogo, 'evt:' . $cleanSession . ':' . md5($riepilogo));
}

// -----------------------------------------------------------
// PERSISTENZA CHAT WEB (per il dossier cliente)
// Salva il nuovo turno (messaggio utente + risposta di Sole) legato alla sessione.
// Non deve mai bloccare la risposta: tutto in try/catch best-effort.
// -----------------------------------------------------------
try {
    require_once __DIR__ . '/ardy-web-memoria.php';
    $righeChat = [];
    if (trim((string) $message) !== '') $righeChat[] = ['role' => 'user', 'content' => $message];
    elseif (!empty($images))            $righeChat[] = ['role' => 'user', 'content' => '[immagine inviata]'];
    if (trim((string) $reply) !== '')   $righeChat[] = ['role' => 'assistant', 'content' => $reply];
    if ($righeChat) ardy_web_salva(ardyDB(), $cleanSession, $righeChat);
} catch (Throwable $e) {
    error_log('ARDY PROXY salva chat web: ' . $e->getMessage());
}

// -----------------------------------------------------------
// RISPOSTA AL WIDGET
// -----------------------------------------------------------
echo json_encode(['reply' => $reply]);