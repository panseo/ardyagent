<?php
// -----------------------------------------------------------
// ARDY LAB — Cervello WhatsApp lato cliente (tool calendario)
//
// PIANO B (un solo cervello, n8n = tubo). Per i canali CLIENTE su WhatsApp
// (lead / cliente / cliente_lavorazione / lead_portale) Sole ha ora i tool
// VERI del calendario, con lo stesso loop agentico del sito (ardy-proxy.php):
//   - ottieni_disponibilita_calendario  → legge gli slot liberi
//   - fissa_appuntamento_calendario      → fissa il sopralluogo (solo su conferma)
// Così Sole può proporre 2 slot reali e chiudere l'appuntamento, invece di
// "recitare" la sintassi di un tool che su WhatsApp non esisteva (caso Alberto).
//
// La modalità TITOLARE (staff) NON passa di qui: resta sul flusso n8n esistente
// (single-shot + marker [[CREA_SCHEDA]]/[[CONTATTA_LEAD]]). Zero regressioni lì.
//
// Chiamato server-to-server da n8n. Riusa: lookup (system già pronto), memoria
// e invio a WhatsApp restano in n8n. Qui si fa SOLO il loop con i tool.
// Auth: header X-Ardy-Secret == WA_LOOKUP_SECRET (come gli altri endpoint WA).
// -----------------------------------------------------------

date_default_timezone_set('Europe/Rome');

require_once __DIR__ . '/ardy-config.php';
require_once __DIR__ . '/ardy-db.php';
require_once __DIR__ . '/ardy-gcal.php';
require_once __DIR__ . '/ardy-net.php';       // ardyCompressImage() per le foto WhatsApp
require_once __DIR__ . '/ardy-sanitize.php';
require_once __DIR__ . '/ardy-notifica-michela.php';
require_once __DIR__ . '/ardy-email.php';
require_once __DIR__ . '/phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/phpmailer/src/SMTP.php';
require_once __DIR__ . '/phpmailer/src/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;

header('Content-Type: application/json');

// ── Auth (stesso segreto condiviso degli altri endpoint WhatsApp) ──
if (defined('WA_LOOKUP_SECRET') && WA_LOOKUP_SECRET !== '') {
    $sent = $_SERVER['HTTP_X_ARDY_SECRET'] ?? '';
    if (!hash_equals(WA_LOOKUP_SECRET, (string) $sent)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'non autorizzato']);
        exit();
    }
}

// ── Input ──
$in        = json_decode(file_get_contents('php://input'), true) ?: [];
$system    = (string) ($in['system'] ?? '');
$messages  = is_array($in['messages'] ?? null) ? $in['messages'] : [];
$sessionId = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) ($in['session_id'] ?? ''));
$cliente   = is_array($in['cliente'] ?? null) ? $in['cliente'] : [];
$phone     = preg_replace('/\D+/', '', (string) ($in['phone'] ?? ''));   // numero WhatsApp del mittente
$mediaId   = trim((string) ($in['media_id'] ?? ''));                     // id foto WhatsApp (se presente)
// Cartella di storage foto = quella della scheda. Per un lead nuovo (senza
// scheda ancora) usiamo l'id deterministico dal telefono: combacia con quello
// che genererà ardy-wa-crea-scheda.php → le foto si agganciano alla scheda.
$cardSession = $sessionId !== '' ? $sessionId : ($phone !== '' ? 'wa-' . substr(md5($phone), 0, 16) : '');

// Trascrizione leggibile della conversazione (per la notifica a Michela), dai
// messaggi in ingresso PRIMA che il loop li arricchisca con i blocchi tool.
$transcript = '';
foreach ($messages as $mm) {
    if (isset($mm['role']) && is_string($mm['content'] ?? null) && $mm['content'] !== '') {
        $lab = strtoupper((string) $mm['role']) === 'USER' ? 'CLIENTE' : 'SOLE';
        $transcript .= $lab . ': ' . $mm['content'] . "\n\n";
    }
}

// Dati lead per le email (default dal contesto lookup; aggiornati se Sole salva la scheda).
$leadNome    = trim((string) ($cliente['nome']  ?? ''));
$leadZona    = trim((string) ($cliente['zona']  ?? ''));
$leadEmail   = trim((string) ($cliente['email'] ?? ''));
$leadCreated = false;     // true se è stata creata una scheda NUOVA in questo giro
$accessCode  = null;      // codice di accesso da inviare al lead via email
$accessEmail = null;      // email a cui inviare il codice

if ($system === '' || empty($messages)) {
    echo json_encode(['success' => false, 'reply' => 'Scusa, ora non riesco a rispondere. Ti ricontatto a breve.']);
    exit();
}

// -----------------------------------------------------------
// Chiamata ad Anthropic (identica al sito: prompt caching su system + tools)
// -----------------------------------------------------------
function waCallAnthropic(array $messages, string $system, array $tools, string $apiKey): array {
    $systemPayload = [
        ['type' => 'text', 'text' => $system, 'cache_control' => ['type' => 'ephemeral']]
    ];
    $cachedTools = $tools;
    if (!empty($cachedTools)) {
        $cachedTools[count($cachedTools) - 1]['cache_control'] = ['type' => 'ephemeral'];
    }
    $payload = json_encode([
        'model'      => 'claude-sonnet-4-6',
        'max_tokens' => 1000,
        'system'     => $systemPayload,
        'tools'      => $cachedTools,
        'messages'   => $messages,
    ]);
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt($ch, CURLOPT_POST,           true);
    curl_setopt($ch, CURLOPT_POSTFIELDS,     $payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT,        120);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
    curl_setopt($ch, CURLOPT_HTTP_VERSION,   CURL_HTTP_VERSION_1_1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Connection: close',
        'x-api-key: ' . $apiKey,
        'anthropic-version: 2023-06-01',
        'anthropic-beta: prompt-caching-2024-07-31',
    ]);
    $response = curl_exec($ch);
    $err      = curl_error($ch);
    curl_close($ch);
    if ($err) {
        error_log('ARDY WA-AGENT CURL ERROR: ' . $err);
        return ['error' => 'curl', 'message' => $err];
    }
    return json_decode($response, true) ?? ['error' => 'json'];
}

// PHPMailer preconfigurato su Brevo (stesse impostazioni di ardy-proxy.php).
function waNewMailer(): PHPMailer {
    $m = new PHPMailer(true);
    $m->isSMTP();
    $m->Host       = 'smtp-relay.brevo.com';
    $m->SMTPAuth   = true;
    $m->Username   = ARDY_SMTP_USER;
    $m->Password   = ARDY_SMTP_PASSWORD;
    $m->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $m->Port       = 587;
    $m->CharSet    = 'UTF-8';
    return $m;
}

// Codice di accesso ARD-XXXX-XXXX (alfabeto senza caratteri ambigui) — come ardy-proxy.php.
function waGeneraCodiceAccesso(): string {
    $alpha = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $n = strlen($alpha);
    $out = '';
    for ($i = 0; $i < 8; $i++) {
        $out .= $alpha[random_int(0, $n - 1)];
        if ($i === 3) $out .= '-';
    }
    return 'ARD-' . $out;
}

// Colonne codice accesso sulla tabella clienti (idempotente) — come ardy-proxy.php.
function waEnsureCodiceCol(PDO $db): void {
    static $done = false;
    if ($done) return;
    try {
        if (!$db->query("SHOW COLUMNS FROM clienti LIKE 'codice_accesso'")->fetch()) {
            $db->exec("ALTER TABLE clienti ADD COLUMN codice_accesso VARCHAR(20) NULL");
            try { $db->exec("CREATE INDEX idx_codice_accesso ON clienti (codice_accesso)"); }
            catch (PDOException $e) { /* indice già presente */ }
        }
        if (!$db->query("SHOW COLUMNS FROM clienti LIKE 'codice_email_inviato'")->fetch()) {
            $db->exec("ALTER TABLE clienti ADD COLUMN codice_email_inviato DATETIME NULL");
        }
    } catch (PDOException $e) {
        error_log('ARDY WA-AGENT ENSURE CODICE: ' . $e->getMessage());
    }
    $done = true;
}

// Scarica una foto WhatsApp via Cloud API: media id → url firmato → byte.
// Richiede WA_TOKEN (Bearer). Ritorna [bytes, mime] oppure null.
function waScaricaMediaWhatsApp(string $mediaId): ?array {
    if (!defined('WA_TOKEN') || WA_TOKEN === '') {
        error_log('ARDY WA-AGENT MEDIA: WA_TOKEN mancante');
        return null;
    }
    // 1) media id → metadati (url firmato, breve durata)
    $ch = curl_init('https://graph.facebook.com/v21.0/' . urlencode($mediaId));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT,        20);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . WA_TOKEN]);
    $res = curl_exec($ch);
    curl_close($ch);
    $meta = json_decode((string) $res, true);
    $url  = is_array($meta) ? ($meta['url'] ?? '') : '';
    if ($url === '') {
        error_log('ARDY WA-AGENT MEDIA: url mancante per ' . $mediaId . ' res=' . substr((string) $res, 0, 200));
        return null;
    }
    // 2) scarica i byte (stesso Bearer richiesto anche sull'url CDN)
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT,        30);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . WA_TOKEN]);
    $bytes = curl_exec($ch);
    $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || !is_string($bytes) || strlen($bytes) < 12) {
        error_log('ARDY WA-AGENT MEDIA: download fallito HTTP ' . $code);
        return null;
    }
    return [$bytes, (string) ($meta['mime_type'] ?? '')];
}

// Colonne sopralluogo sulla tabella clienti (idempotente) — come ardy-proxy.php.
function waEnsureSopralluogoCols(PDO $db): void {
    static $done = false;
    if ($done) return;
    try {
        if (!$db->query("SHOW COLUMNS FROM clienti LIKE 'gcal_event_id'")->fetch()) {
            $db->exec("ALTER TABLE clienti ADD COLUMN gcal_event_id VARCHAR(128) NULL");
        }
        if (!$db->query("SHOW COLUMNS FROM clienti LIKE 'sopralluogo_at'")->fetch()) {
            $db->exec("ALTER TABLE clienti ADD COLUMN sopralluogo_at DATETIME NULL");
        }
    } catch (PDOException $e) {
        error_log('ARDY WA-AGENT ENSURE COLS: ' . $e->getMessage());
    }
    $done = true;
}

// -----------------------------------------------------------
// Tool calendario (stesse definizioni del sito — lettura + prenotazione)
// -----------------------------------------------------------
$tools = [
    [
        'name'        => 'ottieni_disponibilita_calendario',
        'description' => 'Controlla la disponibilità del calendario di Ardy Lab in una finestra temporale. Usalo per proporre al cliente 2 finestre orarie disponibili per un sopralluogo o appuntamento.',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'start' => ['type' => 'string', 'description' => 'Data e ora inizio, ISO 8601. Es: 2026-06-10T09:00:00+02:00'],
                'end'   => ['type' => 'string', 'description' => 'Data e ora fine, ISO 8601. Es: 2026-06-10T18:00:00+02:00'],
            ],
            'required' => ['start', 'end'],
        ],
    ],
    [
        'name'        => 'fissa_appuntamento_calendario',
        'description' => 'Crea un evento nel calendario di Ardy Lab. Usalo solo dopo conferma esplicita del cliente.',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'start'       => ['type' => 'string', 'description' => 'Data e ora inizio, ISO 8601'],
                'end'         => ['type' => 'string', 'description' => 'Data e ora fine, ISO 8601'],
                'summary'     => ['type' => 'string', 'description' => 'Titolo evento. Es: Sopralluogo Ardy Lab — Prati / Mario Rossi'],
                'description' => ['type' => 'string', 'description' => 'Scheda completa: nome, servizio, mobile, condizioni, zona, email, indirizzo, budget, note.'],
            ],
            'required' => ['start', 'end', 'summary', 'description'],
        ],
    ],
    [
        'name'        => 'salva_lead_crm',
        'description' => 'Salva i dati del cliente nel CRM. Usalo quando un nuovo contatto ti ha fornito i suoi dati (almeno il nome e un recapito) e di cosa ha bisogno, così il contatto non si perde e Michela lo ritrova. Se non ti ha dato il telefono va bene: useremo in automatico il suo numero WhatsApp.',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'nome'      => ['type' => 'string', 'description' => 'Nome del cliente'],
                'cognome'   => ['type' => 'string', 'description' => 'Cognome del cliente'],
                'telefono'  => ['type' => 'string', 'description' => 'Numero di telefono (se non lo dà, lascia vuoto: usiamo il suo numero WhatsApp)'],
                'email'     => ['type' => 'string', 'description' => 'Email del cliente'],
                'indirizzo' => ['type' => 'string', 'description' => 'Indirizzo completo: via, numero, piano, citofono, città'],
                'servizio'  => ['type' => 'string', 'description' => 'Tipo di servizio richiesto'],
                'mobile'    => ['type' => 'string', 'description' => 'Descrizione del mobile o pezzo'],
                'zona'      => ['type' => 'string', 'description' => 'Zona o città del cliente'],
                'budget'    => ['type' => 'string', 'description' => 'Forbice di budget comunicata'],
                'stato'     => ['type' => 'string', 'description' => 'Stato: LEAD, SOPRALLUOGO, PREVENTIVO, ACCONTO, STANDBY, PERSO'],
                'note'      => ['type' => 'string', 'description' => 'Note aggiuntive per Michela'],
            ],
            'required' => ['nome', 'stato'],
        ],
    ],
    [
        'name'        => 'sposta_appuntamento',
        'description' => 'Sposta un sopralluogo GIÀ fissato a una nuova data/ora. Usalo quando il cliente con cui stai parlando, che ha già un appuntamento, chiede di spostarlo. Prima verifica la disponibilità del nuovo periodo con ottieni_disponibilita_calendario e fatti confermare un orario preciso; poi chiama questo strumento. L\'appuntamento viene identificato in automatico dal numero WhatsApp del cliente.',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'start' => ['type' => 'string', 'description' => 'Nuova data e ora di inizio, ISO 8601. Es: 2026-06-20T11:00:00+02:00'],
            ],
            'required' => ['start'],
        ],
    ],
];

// -----------------------------------------------------------
// FOTO IN ARRIVO DA WHATSAPP: scarica, valida, comprime, salva su scheda
// (visibile in dashboard) e attaccala al messaggio così Sole la "vede" e valuta.
// -----------------------------------------------------------
if ($mediaId !== '') {
    $media = waScaricaMediaWhatsApp($mediaId);
    if ($media) {
        $bytes    = $media[0];
        $allowed  = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $realMime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);   // tipo reale, non dichiarato
        if (in_array($realMime, $allowed, true)) {
            $bytes = ardyCompressImage($bytes, $realMime);             // stessa compressione del sito

            // Salva nella cartella della scheda → compare in dashboard.
            if ($cardSession !== '' && defined('ARDY_UPLOAD_DIR')) {
                $dir = rtrim(ARDY_UPLOAD_DIR, '/') . '/' . $cardSession . '/';
                if (is_dir($dir) || @mkdir($dir, 0755, true) || is_dir($dir)) {
                    $ext = $realMime === 'image/png' ? 'png' : ($realMime === 'image/webp' ? 'webp' : ($realMime === 'image/gif' ? 'gif' : 'jpg'));
                    $fp  = $dir . date('Ymd_His') . '_' . substr(md5($mediaId), 0, 8) . '.' . $ext;
                    @file_put_contents($fp, $bytes);
                }
            }

            // Attacca la foto all'ULTIMO messaggio utente (formato a blocchi).
            for ($i = count($messages) - 1; $i >= 0; $i--) {
                if (($messages[$i]['role'] ?? '') === 'user') {
                    $txt = is_string($messages[$i]['content'] ?? null) ? $messages[$i]['content'] : '';
                    $messages[$i]['content'] = [
                        ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $realMime, 'data' => base64_encode($bytes)]],
                        ['type' => 'text',  'text' => $txt !== '' ? $txt : 'Il cliente ha inviato questa foto del mobile.'],
                    ];
                    break;
                }
            }
        }
    }
}

// -----------------------------------------------------------
// LOOP AGENTICO (identico al sito, ridotto ai tool calendario)
// -----------------------------------------------------------
$reply          = '';
$maxIterations  = 5;
$iteration      = 0;
$bookingMade    = false;
$bookingWhen    = null;   // DateTime dell'appuntamento (nuovo o spostato)
$bookingEventId = null;   // id evento Google appena creato/spostato
$rescheduled    = false;  // true se un appuntamento è stato SPOSTATO

while ($iteration < $maxIterations) {
    $iteration++;
    $data = waCallAnthropic($messages, $system, $tools, ARDY_API_KEY);

    if (isset($data['error'])) {
        $reply = 'Scusa, ora non riesco a rispondere. Ti ricontatto a breve.';
        break;
    }

    $stopReason = $data['stop_reason'] ?? 'end_turn';
    $content    = $data['content']     ?? [];
    $messages[] = ['role' => 'assistant', 'content' => $content];

    if ($stopReason === 'end_turn') {
        foreach ($content as $block) {
            if (($block['type'] ?? '') === 'text') { $reply = ardy_strip_tool_syntax($block['text']); break; }
        }
        break;
    }

    if ($stopReason !== 'tool_use') break;

    $toolResults = [];
    foreach ($content as $block) {
        if (($block['type'] ?? '') !== 'tool_use') continue;
        $toolName  = $block['name'];
        $toolInput = $block['input'] ?? [];
        $toolId    = $block['id'];
        $toolResult = '';

        if ($toolName === 'ottieni_disponibilita_calendario') {
            $fromDate = null;
            if (!empty($toolInput['start'])) {
                try {
                    $fromDate = new DateTime($toolInput['start']);
                    if ($fromDate < new DateTime('now')) { $fromDate = new DateTime('+7 days'); }
                } catch (Exception $e) { $fromDate = new DateTime('+7 days'); }
            }
            $slots = gcal_get_free_slots(14, 9, 18, $fromDate);
            if ($slots === null) {
                $toolResult = 'Errore calendario: impossibile leggere la disponibilità.';
            } elseif (empty($slots)) {
                $toolResult = 'Nessuno slot disponibile nel periodo richiesto.';
            } else {
                $toolResult = json_encode($slots);
            }

        } elseif ($toolName === 'fissa_appuntamento_calendario') {
            // Guardia anti-doppione: se la scheda ha GIÀ un appuntamento, non crearne un secondo.
            $existingEventId = $bookingEventId;
            if (empty($existingEventId) && $sessionId !== '') {
                try {
                    $db = ardyDB();
                    waEnsureSopralluogoCols($db);
                    $qb = $db->prepare("SELECT gcal_event_id FROM clienti WHERE session_id = :sid LIMIT 1");
                    $qb->execute([':sid' => $sessionId]);
                    $existingEventId = trim((string) $qb->fetchColumn());
                } catch (PDOException $e) { $existingEventId = ''; }
            }
            if (!empty($existingEventId)) {
                $toolResult = 'Questo cliente ha GIÀ un appuntamento fissato: NON crearne un altro (creeresti un doppione nel calendario). Conferma l\'appuntamento già esistente; se vuole cambiare data, raccogli la richiesta e di\' che Michela lo riorganizza.';
            } else try {
                if (empty($toolInput['start'])) {
                    $toolResult = 'Errore: data/ora mancante. Chiedi al cliente di confermare giorno e ora.';
                } else {
                    $startDt = new DateTime($toolInput['start']);
                    $dateStr = $startDt->format('Y-m-d');
                    $timeStr = $startDt->format('H:i');
                    $summary = $toolInput['summary']     ?? 'Sopralluogo Ardy Lab';
                    $desc    = $toolInput['description'] ?? '';
                    $r = gcal_create_event($dateStr, $timeStr, $summary, '', '', '', $desc);
                    if ($r) {
                        $bookingMade    = true;
                        $bookingWhen    = $startDt;
                        $bookingEventId = is_array($r) ? ($r['id'] ?? null) : null;
                    }
                    $toolResult = $r
                        ? 'Appuntamento creato con successo nel calendario di Michela.'
                        : 'Errore nella creazione dell\'appuntamento. Riprova.';
                }
            } catch (Exception $e) {
                error_log('ARDY WA-AGENT BOOKING ERROR: ' . $e->getMessage() . ' input=' . json_encode($toolInput));
                $toolResult = 'Errore tecnico nella prenotazione. Chiedi al cliente di riprovare.';
            }

        } elseif ($toolName === 'salva_lead_crm') {
            // Telefono: quello fornito da Sole o, in mancanza, il numero WhatsApp del mittente.
            $tel = preg_replace('/[^0-9+]/', '', (string) ($toolInput['telefono'] ?? ''));
            if ($tel === '' && $phone !== '') $tel = $phone;
            // Budget non è una colonna CRM: lo accodiamo alle note.
            $note = trim((string) ($toolInput['note'] ?? ''));
            if (!empty($toolInput['budget'])) {
                $note = trim($note . "\nBudget: " . $toolInput['budget']);
            }
            $payload = [
                'nome'      => $toolInput['nome']      ?? '',
                'cognome'   => $toolInput['cognome']   ?? '',
                'telefono'  => $tel,
                'email'     => $toolInput['email']     ?? '',
                'indirizzo' => $toolInput['indirizzo'] ?? '',
                'servizio'  => $toolInput['servizio']  ?? '',
                'mobile'    => $toolInput['mobile']    ?? '',
                'zona'      => $toolInput['zona']       ?? '',
                'stato'     => $toolInput['stato']      ?? 'LEAD',
                'note'      => $note,
            ];
            $ch = curl_init('https://ardyagent.ardy-lab.it/ardy-wa-crea-scheda.php');
            curl_setopt($ch, CURLOPT_POST,           true);
            curl_setopt($ch, CURLOPT_POSTFIELDS,     json_encode($payload));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT,        15);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'X-Ardy-Secret: ' . (defined('WA_LOOKUP_SECRET') ? WA_LOOKUP_SECRET : ''),
            ]);
            $r = json_decode(curl_exec($ch), true);
            curl_close($ch);
            if (is_array($r) && !empty($r['success'])) {
                // Aggancia la scheda alla conversazione: una prenotazione successiva si attacca qui.
                if (!empty($r['session_id'])) $sessionId = $r['session_id'];
                $leadCreated = !empty($r['created']);
                // Aggiorna i dati lead per le email con quanto raccolto da Sole.
                $nm = trim(($toolInput['nome'] ?? '') . ' ' . ($toolInput['cognome'] ?? ''));
                if ($nm !== '') $leadNome = $nm;
                if (!empty($toolInput['zona'])) $leadZona = trim((string) $toolInput['zona']);
                $em = trim((string) ($toolInput['email'] ?? ''));
                if ($em !== '' && filter_var($em, FILTER_VALIDATE_EMAIL)) $leadEmail = $em;

                // Codice di accesso: generato UNA volta per scheda. Col codice il cliente,
                // tornando sulla WEBCHAT, potrà chiedere lo stato del lavoro. Prepara l'invio
                // email di benvenuto (spedita dopo la risposta, vedi in fondo).
                if ($sessionId !== '') {
                    try {
                        $db = ardyDB();
                        waEnsureCodiceCol($db);
                        $sel = $db->prepare("SELECT codice_accesso, codice_email_inviato, email FROM clienti WHERE session_id = :sid LIMIT 1");
                        $sel->execute([':sid' => $sessionId]);
                        $row        = $sel->fetch(PDO::FETCH_ASSOC) ?: [];
                        $codice     = trim((string) ($row['codice_accesso'] ?? ''));
                        $giaInviata = !empty($row['codice_email_inviato']);
                        if ($codice === '') {
                            for ($t = 0; $t < 5; $t++) {
                                $cand = waGeneraCodiceAccesso();
                                $chk  = $db->prepare("SELECT 1 FROM clienti WHERE codice_accesso = :c LIMIT 1");
                                $chk->execute([':c' => $cand]);
                                if (!$chk->fetchColumn()) { $codice = $cand; break; }
                            }
                            if ($codice !== '') {
                                $db->prepare("UPDATE clienti SET codice_accesso = :c, updated_at = NOW() WHERE session_id = :sid")
                                   ->execute([':c' => $codice, ':sid' => $sessionId]);
                            }
                        }
                        if ($codice !== '' && !$giaInviata) {
                            $emailLead = $leadEmail !== '' ? $leadEmail : trim((string) ($row['email'] ?? ''));
                            if ($emailLead !== '' && filter_var($emailLead, FILTER_VALIDATE_EMAIL)) {
                                $accessCode  = $codice;
                                $accessEmail = $emailLead;
                            }
                        }
                    } catch (PDOException $e) {
                        error_log('ARDY WA-AGENT CODICE ACCESSO: ' . $e->getMessage());
                    }
                }
                $toolResult = 'Scheda cliente salvata nel CRM. Prosegui con naturalezza, NON elencare i dati al cliente.';
            } else {
                $toolResult = 'Non sono riuscita a salvare la scheda adesso. Prosegui comunque la conversazione; se serve, di\' che Michela la registra.';
            }

        } elseif ($toolName === 'sposta_appuntamento') {
            // Su WhatsApp il cliente può spostare SOLO il proprio appuntamento:
            // lo identifichiamo dal suo numero WhatsApp, non da un telefono fornito
            // (evita che qualcuno sposti l'appuntamento di un altro).
            $start = $toolInput['start'] ?? '';
            if ($phone === '') {
                $toolResult = 'Non riesco a identificare il numero del cliente. Raccogli la richiesta e di\' che Michela ricontatta.';
            } elseif ($start === '') {
                $toolResult = 'Errore: manca la nuova data/ora. Chiedi al cliente giorno e ora precisi.';
            } else {
                try {
                    $startDt = new DateTime($start);
                    if ($startDt < new DateTime('now')) {
                        $toolResult = 'La nuova data è nel passato: chiedi al cliente una data futura.';
                    } else {
                        $db = ardyDB();
                        waEnsureSopralluogoCols($db);
                        ardyEnsureTelefonoLast9($db);
                        $q = $db->prepare(
                            "SELECT session_id, nome, cognome, email, gcal_event_id FROM clienti
                              WHERE telefono_last9 = :p
                                AND gcal_event_id IS NOT NULL AND gcal_event_id <> ''
                                AND deleted_at IS NULL
                           ORDER BY updated_at DESC, id DESC LIMIT 1"
                        );
                        $q->execute([':p' => substr($phone, -9)]);
                        $cli = $q->fetch(PDO::FETCH_ASSOC);
                        if (!$cli) {
                            $toolResult = 'Non trovo un appuntamento collegato a questo numero. Raccogli la richiesta e di\' che Michela ricontatta il cliente per riorganizzare.';
                        } else {
                            $dateStr = $startDt->format('Y-m-d');
                            $timeStr = $startDt->format('H:i');
                            $free = gcal_is_slot_free($dateStr, $timeStr, 2);
                            if ($free === false) {
                                $toolResult = 'Quel nuovo orario è già occupato. Proponi al cliente un altro slot tra quelli liberi (controlla con ottieni_disponibilita_calendario).';
                            } else {
                                // free === true (libero) o null (impossibile verificare): procediamo.
                                $upd = gcal_update_event($cli['gcal_event_id'], $dateStr, $timeStr, 2);
                                if (!$upd) {
                                    $toolResult = 'Non sono riuscita a spostare l\'appuntamento sul calendario. Riprova o di\' che Michela ricontatta.';
                                } else {
                                    $db->prepare("UPDATE clienti SET sopralluogo_at = :dt, stato = 'SOPRALLUOGO', updated_at = NOW() WHERE session_id = :sid")
                                       ->execute([':dt' => $startDt->format('Y-m-d H:i:s'), ':sid' => $cli['session_id']]);
                                    $rescheduled    = true;
                                    $bookingWhen    = $startDt;               // per la conferma email al cliente
                                    $bookingEventId = $cli['gcal_event_id'];
                                    $nm = trim(($cli['nome'] ?? '') . ' ' . ($cli['cognome'] ?? ''));
                                    if ($nm !== '') $leadNome = $nm;
                                    $em = trim((string) ($cli['email'] ?? ''));
                                    if ($em !== '' && filter_var($em, FILTER_VALIDATE_EMAIL)) $leadEmail = $em;
                                    $toolResult = 'Appuntamento spostato con successo al nuovo orario nel calendario di Michela.';
                                }
                            }
                        }
                    }
                } catch (Exception $e) {
                    error_log('ARDY WA-AGENT SPOSTA ERROR: ' . $e->getMessage());
                    $toolResult = 'Errore tecnico nello spostamento. Chiedi al cliente di riprovare.';
                }
            }

        } else {
            // Tool non disponibile su questo canale: lo segnaliamo senza bloccare.
            $toolResult = 'Strumento non disponibile su WhatsApp.';
        }

        $toolResults[] = ['type' => 'tool_result', 'tool_use_id' => $toolId, 'content' => $toolResult];
    }
    $messages[] = ['role' => 'user', 'content' => $toolResults];
}

if ($reply === '') {
    $reply = 'Scusa, ora non riesco a rispondere. Ti ricontatto a breve.';
}
$reply = ardy_strip_tool_syntax($reply);
if ($reply === '') {
    $reply = 'Un attimo che verifico e ti riscrivo subito 🙂';
}

// -----------------------------------------------------------
// Persiste l'appuntamento sulla scheda (data + id evento) — come il sito.
// -----------------------------------------------------------
if ($bookingEventId && $bookingWhen instanceof DateTime && $sessionId !== '') {
    try {
        $db = ardyDB();
        waEnsureSopralluogoCols($db);
        $db->prepare("UPDATE clienti SET gcal_event_id = :eid, sopralluogo_at = :dt, stato = 'SOPRALLUOGO', updated_at = NOW() WHERE session_id = :sid")
           ->execute([
               ':eid' => $bookingEventId,
               ':dt'  => $bookingWhen->format('Y-m-d H:i:s'),
               ':sid' => $sessionId,
           ]);
    } catch (PDOException $e) {
        error_log('ARDY WA-AGENT SOPRALLUOGO PERSIST: ' . $e->getMessage());
    }
}

// ── Rispondi SUBITO a n8n: notifiche ed email qui sotto NON devono ritardare
//    il messaggio al cliente (chiudiamo la richiesta HTTP e proseguiamo). ──
echo json_encode([
    'success' => true,
    'reply'   => $reply,
    'booking' => $bookingMade ? ['when' => $bookingWhen->format('c')] : null,
]);
if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); }

// -----------------------------------------------------------
// SIDE-EFFECTS POST-RISPOSTA: WhatsApp a Michela + email (come il sito).
// -----------------------------------------------------------

// 1) Avvisa Michela su WhatsApp di nuovo sopralluogo o spostamento (dedupe).
if ($bookingMade && $bookingWhen instanceof DateTime) {
    $quando = $bookingWhen->format('d/m/Y') . ' alle ' . $bookingWhen->format('H:i');
    $testo  = "📅 Nuovo sopralluogo fissato da Sole su WhatsApp:\n" . ($leadNome ?: 'un cliente')
            . ($leadZona !== '' ? " · {$leadZona}" : '') . "\n🗓 {$quando}";
    notificaMichela($testo, 'wa-sopr:' . ($bookingEventId ?: md5($testo)));
} elseif ($rescheduled && $bookingWhen instanceof DateTime) {
    $quando = $bookingWhen->format('d/m/Y') . ' alle ' . $bookingWhen->format('H:i');
    $testo  = "📅 Sopralluogo SPOSTATO da Sole su WhatsApp:\n" . ($leadNome ?: 'un cliente')
            . "\n🗓 nuovo orario: {$quando}";
    notificaMichela($testo, 'wa-sposta:' . ($bookingEventId ?: md5($testo)) . ':' . $bookingWhen->format('YmdHi'));
}

// 2) NOTIFICA EMAIL A MICHELA — nuovo lead, sopralluogo fissato o spostato.
if ($leadCreated || $bookingMade || $rescheduled) {
    try {
        $isMove = $rescheduled && !$bookingMade && !$leadCreated;
        $body  = ($isMove ? "Sopralluogo SPOSTATO da Sole su WhatsApp\n" : "Nuovo contatto da Sole su WhatsApp\n") . str_repeat('─', 40) . "\n\n";
        $body .= "DATA:      " . date('d/m/Y H:i') . "\n";
        $body .= "NOME:      " . ($leadNome ?: '—') . "\n";
        $body .= "TELEFONO:  " . ($phone !== '' ? '+' . $phone : '—') . "\n";
        $body .= "EMAIL:     " . ($leadEmail ?: 'non fornita') . "\n";
        $body .= "ZONA:      " . ($leadZona ?: '—') . "\n";
        if ($bookingWhen instanceof DateTime) {
            $body .= ($isMove ? "NUOVO ORARIO: " : "SOPRALLUOGO: ") . $bookingWhen->format('d/m/Y H:i') . "\n";
        }
        $body .= "\n" . str_repeat('─', 40) . "\nCONVERSAZIONE\n" . str_repeat('─', 40) . "\n\n" . $transcript;

        $mail = waNewMailer();
        $mail->setFrom('noreply@ardy-lab.it', 'Ardy AI');
        $mail->addAddress(ARDY_MAIL_MICHELA);
        // Allega le foto inviate dal cliente su WhatsApp (cartella della scheda).
        if ($cardSession !== '' && defined('ARDY_UPLOAD_DIR')) {
            $cd = rtrim(ARDY_UPLOAD_DIR, '/') . '/' . $cardSession . '/';
            if (is_dir($cd)) {
                foreach (glob($cd . '*') as $f) { if (is_file($f)) $mail->addAttachment($f); }
            }
        }
        $mail->Subject = ($isMove ? '📅 Sopralluogo SPOSTATO (WhatsApp) — ' : '📋 Nuovo lead da Sole (WhatsApp) — ') . date('d/m/Y H:i');
        $mail->Body    = $body;
        $mail->send();
    } catch (\Throwable $e) {
        error_log('ARDY WA-AGENT MAIL MICHELA: ' . $e->getMessage());
    }
}

// 3) CONFERMA SOPRALLUOGO AL CLIENTE (se fissato/spostato ed email disponibile).
if (($bookingMade || $rescheduled) && $bookingWhen instanceof DateTime && $leadEmail && filter_var($leadEmail, FILTER_VALIDATE_EMAIL)) {
    $giorni = ['Monday'=>'lunedì','Tuesday'=>'martedì','Wednesday'=>'mercoledì','Thursday'=>'giovedì','Friday'=>'venerdì','Saturday'=>'sabato','Sunday'=>'domenica'];
    $mesi   = ['January'=>'gennaio','February'=>'febbraio','March'=>'marzo','April'=>'aprile','May'=>'maggio','June'=>'giugno','July'=>'luglio','August'=>'agosto','September'=>'settembre','October'=>'ottobre','November'=>'novembre','December'=>'dicembre'];
    $gg     = $giorni[$bookingWhen->format('l')] ?? '';
    $mm     = $mesi[$bookingWhen->format('F')] ?? '';
    $quando = trim(ucfirst($gg) . ' ' . $bookingWhen->format('j') . ' ' . $mm . ' ' . $bookingWhen->format('Y') . ' alle ' . $bookingWhen->format('H:i'));
    try {
        $isMoveC = $rescheduled && !$bookingMade;
        $mailC = waNewMailer();
        $mailC->setFrom('noreply@ardy-lab.it', 'Ardy Lab');
        $mailC->addAddress($leadEmail);
        $mailC->Subject = ($isMoveC ? '✅ Sopralluogo Ardy Lab spostato — ' : '✅ Sopralluogo Ardy Lab — ') . $quando;
        $mailC->isHTML(true);
        $mailC->Body = '
<div style="font-family:Georgia,serif;max-width:600px;margin:0 auto;padding:32px;color:#333;">
  ' . ardy_email_logo_cid($mailC) . '
  <p style="color:#999;font-size:13px;margin-bottom:24px;">' . ($isMoveC ? 'Sopralluogo aggiornato' : 'Conferma sopralluogo') . '</p>
  <p style="font-size:15px;line-height:1.7;">Ciao,<br>' . ($isMoveC ? 'abbiamo aggiornato il tuo sopralluogo al nuovo orario.' : 'abbiamo fissato il tuo sopralluogo.') . ' Ecco il riepilogo:</p>
  <div style="border-left:3px solid #c8a96e;padding:12px 20px;background:#fafaf8;margin:20px 0;font-size:16px;">
    <strong>' . htmlspecialchars($quando) . '</strong>
  </div>
  <p style="font-size:15px;line-height:1.7;">Per qualsiasi modifica o domanda puoi rispondere a questa email oppure chiamarci al <strong>351 967 7973</strong>. A presto!</p>
  <p style="margin-top:32px;font-size:12px;color:#bbb;">Ardy Lab — Restauro e laccatura mobili · Roma</p>
</div>';
        $mailC->send();
    } catch (\Throwable $e) {
        error_log('ARDY WA-AGENT CONFERMA CLIENTE: ' . $e->getMessage());
    }
}

// 4) EMAIL BENVENUTO + CODICE DI ACCESSO AL LEAD (solo alla prima generazione).
if ($accessCode && $accessEmail && filter_var($accessEmail, FILTER_VALIDATE_EMAIL)) {
    try {
        $mailK = waNewMailer();
        $mailK->setFrom('noreply@ardy-lab.it', 'Ardy Lab');
        $mailK->addAddress($accessEmail);
        $mailK->Subject = 'Benvenuto in Ardy Lab 🌿 — e il tuo codice personale';
        $mailK->isHTML(true);
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
  <p style="font-size:15px;line-height:1.7;margin-top:24px;">Conserva pure questa email: il tuo codice lo ritrovi quando vuoi. E per qualsiasi cosa ti basta rispondere qui — ti leggiamo sempre. A presto! 🌿</p>
  <p style="margin-top:32px;font-size:12px;color:#bbb;">Ardy Lab — Restauro e laccatura mobili · Roma</p>
</div>';
        $mailK->send();
        // Segna l'invio così non si ripete ai salvataggi successivi della stessa scheda.
        try {
            $db = ardyDB();
            $db->prepare("UPDATE clienti SET codice_email_inviato = NOW() WHERE session_id = :sid")
               ->execute([':sid' => $sessionId]);
        } catch (PDOException $e) {
            error_log('ARDY WA-AGENT CODICE EMAIL FLAG: ' . $e->getMessage());
        }
    } catch (\Throwable $e) {
        error_log('ARDY WA-AGENT CODICE EMAIL: ' . $e->getMessage());
    }
}
