<?php
// -----------------------------------------------------------
// ARDY LAB — Lookup numero WhatsApp
// Dato un numero (come arriva dalla Cloud API, es. "393519677973")
// dice a n8n con chi sta parlando e fornisce il contesto:
//   mode = lead | cliente | cliente_lavorazione
// Così n8n sceglie il prompt giusto (vedi ardy-whatsapp-system.txt).
//
// Chiamato server-to-server da n8n. Se in ardy-config.php è definito
// WA_LOOKUP_SECRET, va passato nell'header X-Ardy-Secret.
// -----------------------------------------------------------

date_default_timezone_set('Europe/Rome');

require_once __DIR__ . '/ardy-config.php';
require_once __DIR__ . '/ardy-db.php';

header('Content-Type: application/json');

// Protezione opzionale via segreto condiviso (consigliata)
if (defined('WA_LOOKUP_SECRET') && WA_LOOKUP_SECRET !== '') {
    $sent = $_SERVER['HTTP_X_ARDY_SECRET'] ?? ($_GET['secret'] ?? '');
    if (!hash_equals(WA_LOOKUP_SECRET, (string)$sent)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'non autorizzato']);
        exit();
    }
}

// Numero: da querystring (?phone=) o da body JSON {"phone": "..."}
$phone = $_GET['phone'] ?? '';
if ($phone === '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $phone = $in['phone'] ?? '';
}
$digits = preg_replace('/\D+/', '', (string)$phone);
if ($digits === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'phone mancante']);
    exit();
}

// Match robusto: confronta le ultime 9 cifre (ignora prefisso +39 / spazi nel DB)
$last9 = substr($digits, -9);

try {
    $db = ardyDB();
    $stmt = $db->prepare(
        "SELECT session_id, nome, cognome, telefono, email, servizio, mobile, stato,
                wp_post_id, wp_post_link
           FROM clienti
          WHERE REPLACE(REPLACE(REPLACE(REPLACE(telefono,' ',''),'+',''),'-',''),'.','') LIKE :p
       ORDER BY updated_at DESC, id DESC
          LIMIT 1"
    );
    $stmt->execute([':p' => '%' . $last9]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('ARDY WA LOOKUP ERROR: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'errore database']);
    exit();
}

// Costruisce il system prompt completo per n8n:
// wrapper WhatsApp + contesto (mode/cliente) + documento principale Ardy Lab.
function ardy_wa_system_prompt(string $mode, ?array $cliente): string {
    $wrap = @file_get_contents(__DIR__ . '/ardy-whatsapp-system.txt') ?: '';
    $base = @file_get_contents(__DIR__ . '/ardy-system.txt') ?: '';
    $ctx  = "\n\n## CONTESTO DI QUESTA CONVERSAZIONE\nmode: {$mode}\n";
    if ($cliente) {
        $ctx .= "cliente:\n" . json_encode($cliente, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    } else {
        $ctx .= "cliente: (nessuno — nuovo contatto)\n";
    }
    return $wrap . $ctx . "\n" . $base;
}

// Nessun match → nuovo contatto
if (!$row) {
    echo json_encode([
        'success'       => true,
        'mode'          => 'lead',
        'cliente'       => null,
        'system_prompt' => ardy_wa_system_prompt('lead', null),
    ]);
    exit();
}

$hasLavorazione = !empty($row['wp_post_id']);
$mode = $hasLavorazione ? 'cliente_lavorazione' : 'cliente';

// Ultima fase pubblicata (contesto per la modalità cliente_lavorazione)
$ultimaFase = null;
if ($hasLavorazione) {
    try {
        $f = $db->prepare("SELECT fase_nome, testo_generato, created_at
                             FROM fasi WHERE session_id = :sid
                         ORDER BY id DESC LIMIT 1");
        $f->execute([':sid' => $row['session_id']]);
        $ultimaFase = $f->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (PDOException $e) {
        error_log('ARDY WA LOOKUP FASE ERROR: ' . $e->getMessage());
    }
}

$clienteOut = [
    'session_id'   => $row['session_id'],
    'nome'         => trim(($row['nome'] ?? '') . ' ' . ($row['cognome'] ?? '')),
    'email'        => $row['email'] ?? '',
    'servizio'     => $row['servizio'] ?? '',
    'mobile'       => $row['mobile'] ?? '',
    'stato'        => $row['stato'] ?? '',
    'wp_post_link' => $row['wp_post_link'] ?? '',
    'ultima_fase'  => $ultimaFase,
];

echo json_encode([
    'success'       => true,
    'mode'          => $mode,
    'cliente'       => $clienteOut,
    'system_prompt' => ardy_wa_system_prompt($mode, $clienteOut),
]);
