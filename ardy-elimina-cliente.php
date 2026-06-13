<?php
// -----------------------------------------------------------
// ARDY LAB — ELIMINA Cliente (hard-delete) per session_id
// Cancella DEFINITIVAMENTE la scheda e tutto il collegato:
//   DB: clienti, preventivi, fasi, wa_messaggi, solleciti
//   File: foto della sessione + reel
// Pensato per ripulire i lead di test. Versione minima del task
// "Gestione archivio cliente / Cestino" (vedi TODO).
//
// Auth: come gli altri endpoint chiamati in fetch dal browser, ci si
// affida al Basic Auth del .htaccess (NON ardyRequireAuth, che su questo
// server in CGI/FPM non riceve l'header Authorization).
// Sicurezza: richiede conferma === 'ELIMINA' e session_id sanificato.
// -----------------------------------------------------------

require_once __DIR__ . '/ardy-config.php';
require_once __DIR__ . '/ardy-db.php';

header('Access-Control-Allow-Origin: https://ardyagent.ardy-lab.it');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

// session_id sempre sanificato (no path traversal)
function ardy_clean_session($s) {
    return preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $s);
}

// Cancellazione ricorsiva di una cartella
function ardy_rrmdir($dir) {
    if (!is_dir($dir)) return;
    foreach (scandir($dir) as $f) {
        if ($f === '.' || $f === '..') continue;
        $p = $dir . '/' . $f;
        is_dir($p) ? ardy_rrmdir($p) : @unlink($p);
    }
    @rmdir($dir);
}

$input     = json_decode(file_get_contents('php://input'), true);
$sessionId = ardy_clean_session($input['session_id'] ?? '');
$conferma  = (string) ($input['conferma'] ?? '');

if ($sessionId === '') {
    echo json_encode(['success' => false, 'error' => 'session_id mancante']);
    exit();
}
if ($conferma !== 'ELIMINA') {
    echo json_encode(['success' => false, 'error' => 'Conferma mancante: serve "ELIMINA"']);
    exit();
}

$deleted = [];

try {
    $db = ardyDB();

    // 1) Verifica che la scheda esista (evita conferme "a vuoto")
    $chk = $db->prepare("SELECT 1 FROM clienti WHERE session_id = :sid LIMIT 1");
    $chk->execute([':sid' => $sessionId]);
    if (!$chk->fetchColumn()) {
        echo json_encode(['success' => false, 'error' => 'Nessuna scheda per questa sessione']);
        exit();
    }

    // 2) Cancella le righe collegate + la scheda. Ogni tabella in un try
    //    a sé: se una non esiste su questa installazione non blocca le altre.
    $tabelle = ['solleciti', 'wa_messaggi', 'fasi', 'preventivi', 'clienti'];
    foreach ($tabelle as $t) {
        try {
            $st = $db->prepare("DELETE FROM `$t` WHERE session_id = :sid");
            $st->execute([':sid' => $sessionId]);
            $deleted[$t] = $st->rowCount();
        } catch (PDOException $e) {
            // tabella assente o senza colonna session_id: la salto
            error_log('ARDY ELIMINA CLIENTE (tabella ' . $t . '): ' . $e->getMessage());
        }
    }
} catch (PDOException $e) {
    error_log('ARDY ELIMINA CLIENTE ERROR: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Errore interno']);
    exit();
}

// 3) File: cartella foto della sessione + reel collegati
if (defined('ARDY_UPLOAD_DIR')) {
    ardy_rrmdir(rtrim(ARDY_UPLOAD_DIR, '/') . '/' . $sessionId);
}
$reelGlob = glob(__DIR__ . '/reels/reel_' . $sessionId . '_*.mp4') ?: [];
$reelDeleted = 0;
foreach ($reelGlob as $reel) {
    if (@unlink($reel)) $reelDeleted++;
}
$deleted['reel_file'] = $reelDeleted;

echo json_encode(['success' => true, 'deleted' => $deleted]);
