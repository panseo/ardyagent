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
require_once __DIR__ . '/ardy-sanitize.php';
require_once __DIR__ . '/ardy-notifica-michela.php';

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
];

// -----------------------------------------------------------
// LOOP AGENTICO (identico al sito, ridotto ai tool calendario)
// -----------------------------------------------------------
$reply          = '';
$maxIterations  = 5;
$iteration      = 0;
$bookingMade    = false;
$bookingWhen    = null;   // DateTime dell'appuntamento
$bookingEventId = null;   // id evento Google appena creato

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

// Avvisa Michela del nuovo sopralluogo fissato via WhatsApp (dedupe su evento).
if ($bookingMade && $bookingWhen instanceof DateTime) {
    $nomeCli = trim((string) ($cliente['nome'] ?? '')) ?: 'un cliente';
    $zona    = trim((string) ($cliente['zona'] ?? ''));
    $quando  = $bookingWhen->format('d/m/Y') . ' alle ' . $bookingWhen->format('H:i');
    $testo   = "📅 Nuovo sopralluogo fissato da Sole su WhatsApp:\n" . $nomeCli
             . ($zona !== '' ? " · {$zona}" : '') . "\n🗓 {$quando}";
    notificaMichela($testo, 'wa-sopr:' . ($bookingEventId ?: md5($testo)));
}

echo json_encode([
    'success' => true,
    'reply'   => $reply,
    'booking' => $bookingMade ? ['when' => $bookingWhen->format('c')] : null,
]);
