<?php
// -----------------------------------------------------------
// ARDY LAB — Memoria conversazione WhatsApp
// Storico messaggi per numero, così Sole mantiene il filo del discorso.
//   GET  ?phone=...          -> { history: [ {role, content}, ... ] }  (ordine cronologico)
//   POST { phone, messages } -> salva una o più coppie/righe { role, content }
//
// Chiamato server-to-server da n8n. Se in ardy-config.php è definito
// WA_LOOKUP_SECRET, va passato nell'header X-Ardy-Secret.
// -----------------------------------------------------------

date_default_timezone_set('Europe/Rome');

require_once __DIR__ . '/ardy-config.php';
require_once __DIR__ . '/ardy-db.php';

header('Content-Type: application/json');

// Protezione condivisa (stesso segreto del lookup)
if (defined('WA_LOOKUP_SECRET') && WA_LOOKUP_SECRET !== '') {
    $sent = $_SERVER['HTTP_X_ARDY_SECRET'] ?? ($_GET['secret'] ?? '');
    if (!hash_equals(WA_LOOKUP_SECRET, (string)$sent)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'non autorizzato']);
        exit();
    }
}

$MAX_HISTORY = 20; // quante righe di storico recuperare (10 scambi circa)

try {
    $db = ardyDB();
    // Tabella wa_messaggi creata da ardy-migrate.php.
} catch (PDOException $e) {
    error_log('ARDY WA MEMORIA DB ERROR: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'errore database']);
    exit();
}

// Normalizza il numero alle ultime 12 cifre (coerente tra invii)
function ardy_norm_phone($p) {
    $d = preg_replace('/\D+/', '', (string)$p);
    return substr($d, -12);
}

// -----------------------------------------------------------
// GET — recupera lo storico recente (ordine cronologico)
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $phone = ardy_norm_phone($_GET['phone'] ?? '');
    if ($phone === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'phone mancante']);
        exit();
    }
    try {
        $stmt = $db->prepare(
            "SELECT role, content FROM wa_messaggi
              WHERE phone = :p ORDER BY id DESC LIMIT :lim"
        );
        $stmt->bindValue(':p', $phone);
        $stmt->bindValue(':lim', $MAX_HISTORY, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $rows = array_reverse($rows); // dal più vecchio al più recente
    } catch (PDOException $e) {
        error_log('ARDY WA MEMORIA GET ERROR: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'errore database']);
        exit();
    }
    echo json_encode(['success' => true, 'history' => $rows]);
    exit();
}

// -----------------------------------------------------------
// POST — salva una o più righe { role, content }
// body: { "phone": "...", "messages": [ {"role":"user","content":"..."}, ... ] }
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $in    = json_decode(file_get_contents('php://input'), true) ?: [];
    $phone = ardy_norm_phone($in['phone'] ?? '');
    $msgs  = $in['messages'] ?? [];
    // Permetti anche il formato singolo { role, content }
    if (!$msgs && isset($in['role'], $in['content'])) {
        $msgs = [['role' => $in['role'], 'content' => $in['content']]];
    }
    if ($phone === '' || !is_array($msgs) || !$msgs) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'phone o messages mancanti']);
        exit();
    }
    try {
        $ins = $db->prepare("INSERT INTO wa_messaggi (phone, role, content) VALUES (:p, :r, :c)");
        $saved = 0;
        foreach ($msgs as $m) {
            $role    = ($m['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
            $content = trim((string)($m['content'] ?? ''));
            if ($content === '') continue;
            if (mb_strlen($content) > 8000) $content = mb_substr($content, 0, 8000);
            $ins->execute([':p' => $phone, ':r' => $role, ':c' => $content]);
            $saved++;
        }
        // Pulizia leggera: tieni la memoria gestibile (max ~100 righe per numero)
        $db->prepare(
            "DELETE FROM wa_messaggi WHERE phone = :p AND id NOT IN (
                SELECT id FROM (SELECT id FROM wa_messaggi WHERE phone = :p2 ORDER BY id DESC LIMIT 100) t
             )"
        )->execute([':p' => $phone, ':p2' => $phone]);
    } catch (PDOException $e) {
        error_log('ARDY WA MEMORIA POST ERROR: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'errore database']);
        exit();
    }
    echo json_encode(['success' => true, 'salvati' => $saved]);
    exit();
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'metodo non consentito']);
