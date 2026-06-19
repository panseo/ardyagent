<?php
// -----------------------------------------------------------
// ARDY LAB — Primo contatto WhatsApp verso un lead da portale
//
// Chiamato server-to-server da n8n quando Sole (modalità titolare) emette
// il marker [[CONTATTA_LEAD]] dopo conferma di Michela.
//
//   POST JSON { "session_id": "wa-abc123..." }
//
// 1. Legge la scheda dal DB (nome, servizio, telefono).
// 2. Genera un link webchat firmato (HMAC) → il lead ci clicca e la chat
//    lo riconosce già (nome + cosa ha chiesto).
// 3. Invia il template WA `primo_contatto_lead` (Marketing/Utility, 3 var)
//    al numero del lead.
// 4. Salva il timestamp di invio sulla scheda (`primo_contatto_wa_at`).
//
// Protetto con WA_LOOKUP_SECRET (stessa protezione di ardy-wa-crea-scheda.php).
//
// Config richiesta in ardy-config.php:
//   WA_TOKEN, WA_PHONE_NUMBER_ID, WA_LOOKUP_SECRET
//   WA_TEMPLATE_PRIMO_CONTATTO  (nome template Meta, default 'primo_contatto_lead')
// -----------------------------------------------------------

date_default_timezone_set('Europe/Rome');

require_once __DIR__ . '/ardy-config.php';
require_once __DIR__ . '/ardy-db.php';

header('Content-Type: application/json; charset=utf-8');

// ── Protezione ──
if (!defined('WA_LOOKUP_SECRET') || WA_LOOKUP_SECRET === '') {
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'endpoint non configurato']);
    exit();
}
$sent = $_SERVER['HTTP_X_ARDY_SECRET'] ?? ($_GET['secret'] ?? '');
if (!hash_equals(WA_LOOKUP_SECRET, (string)$sent)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'non autorizzato']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Metodo non consentito']);
    exit();
}

$in = json_decode(file_get_contents('php://input'), true);
$sessionId = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)($in['session_id'] ?? ''));

if ($sessionId === '') {
    echo json_encode(['success' => false, 'error' => 'session_id mancante']);
    exit();
}

// ── Leggi la scheda ──
try {
    $db  = ardyDB();
    $stm = $db->prepare("SELECT nome, cognome, telefono, servizio, mobile FROM clienti WHERE session_id = :sid AND (deleted_at IS NULL) LIMIT 1");
    $stm->execute([':sid' => $sessionId]);
    $cli = $stm->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('ARDY LEAD CONTATTO DB ERROR: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Errore interno']);
    exit();
}

if (!$cli) {
    echo json_encode(['success' => false, 'error' => 'Scheda non trovata']);
    exit();
}

$nome     = trim(($cli['nome'] ?? '') . ' ' . ($cli['cognome'] ?? ''));
$telefono = preg_replace('/[^0-9]/', '', (string)($cli['telefono'] ?? ''));
$servizio = trim((string)($cli['servizio'] ?? ''));
$mobile   = trim((string)($cli['mobile'] ?? ''));

if ($telefono === '') {
    echo json_encode(['success' => false, 'error' => 'Telefono mancante nella scheda']);
    exit();
}

// ── Genera link webchat firmato ──
// Il token è un HMAC-SHA256 del session_id con la chiave WA_LOOKUP_SECRET.
// La webchat lo valida chiamando ardy-verify-client.php (già esistente)
// oppure passandolo al proxy che lo riconosce come lead prequalificato.
$token    = hash_hmac('sha256', $sessionId, WA_LOOKUP_SECRET);
$shortTok = substr($token, 0, 16); // i primi 16 char bastano per l'URL
$chatLink = 'https://ardy-lab.it/ardy-agent/?lead=' . urlencode($sessionId) . '&tok=' . $shortTok;

// ── Descrizione lavoro per {{2}} ──
$descLavoro = '';
if ($servizio !== '' && $mobile !== '') {
    $descLavoro = $servizio . ' — ' . $mobile;
} elseif ($servizio !== '') {
    $descLavoro = $servizio;
} elseif ($mobile !== '') {
    $descLavoro = $mobile;
} else {
    $descLavoro = 'il lavoro richiesto';
}
// Tronca a 100 char (limite pratico parametri template)
if (mb_strlen($descLavoro) > 100) $descLavoro = mb_substr($descLavoro, 0, 97) . '...';

// ── Invia template WA ──
$templateName = defined('WA_TEMPLATE_PRIMO_CONTATTO') && WA_TEMPLATE_PRIMO_CONTATTO !== ''
    ? WA_TEMPLATE_PRIMO_CONTATTO
    : 'primo_contatto_lead';
$templateLang = defined('WA_TEMPLATE_LANG') && WA_TEMPLATE_LANG !== '' ? WA_TEMPLATE_LANG : 'it';

// Sanitizza i parametri template (Meta rifiuta newline/tab/4+ spazi)
function ardy_tpl_param(string $t): string {
    $t = str_replace(["\r\n", "\r", "\n", "\t"], ' ', $t);
    $t = preg_replace('/ {2,}/', ' ', $t);
    return trim($t);
}

$to = $telefono;
// Aggiungi prefisso 39 se manca (numeri italiani)
if (strlen($to) === 10 && $to[0] === '3') $to = '39' . $to;

$payload = [
    'messaging_product' => 'whatsapp',
    'to'                => $to,
    'type'              => 'template',
    'template'          => [
        'name'       => $templateName,
        'language'   => ['code' => $templateLang],
        'components' => [[
            'type'       => 'body',
            'parameters' => [
                ['type' => 'text', 'text' => ardy_tpl_param($nome ?: 'Buongiorno')],
                ['type' => 'text', 'text' => ardy_tpl_param($descLavoro)],
                ['type' => 'text', 'text' => $chatLink],
            ],
        ]],
    ],
];

$url = 'https://graph.facebook.com/v21.0/' . rawurlencode((string)WA_PHONE_NUMBER_ID) . '/messages';
$ch  = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . WA_TOKEN,
    ],
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);

if ($err || $code >= 300) {
    error_log('ARDY LEAD CONTATTO WA ERROR: http=' . $code . ' err=' . $err . ' resp=' . $resp);
    echo json_encode([
        'success' => false,
        'error'   => 'Invio WhatsApp fallito (HTTP ' . $code . ')',
        'detail'  => json_decode($resp, true),
    ]);
    exit();
}

// ── Salva timestamp primo contatto sulla scheda ──
try {
    $db->prepare("UPDATE clienti SET primo_contatto_wa_at = NOW(), updated_at = NOW() WHERE session_id = :sid")
       ->execute([':sid' => $sessionId]);
} catch (PDOException $e) {
    error_log('ARDY LEAD CONTATTO (salva timestamp): ' . $e->getMessage());
}

// ── Risposta ──
$riepilogo = "Primo contatto inviato ✅\n"
    . "A: {$nome} ({$telefono})\n"
    . "Lavoro: {$descLavoro}\n"
    . "Link webchat incluso nel messaggio.";

echo json_encode([
    'success'    => true,
    'session_id' => $sessionId,
    'telefono'   => $telefono,
    'chat_link'  => $chatLink,
    'riepilogo'  => $riepilogo,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
