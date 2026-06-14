<?php
// -----------------------------------------------------------
// ARDY LAB — Gestione spazio/eliminazione cliente per session_id
//
// Due azioni (parametro `azione`, default 'elimina'):
//
//   azione = 'elimina' (hard-delete, conferma 'ELIMINA')
//     Cancella DEFINITIVAMENTE la scheda e tutto il collegato:
//       DB: clienti, preventivi, fasi, wa_messaggi, solleciti
//       File: foto della sessione + reel
//     Pensato per ripulire i lead di test.
//
//   azione = 'libera_spazio' (conferma 'LIBERA')
//     A lavoro consegnato/pagato: cancella SOLO i file pesanti (cartella foto
//     della sessione + reel) per liberare spazio sul server. TIENE scheda,
//     dati, preventivi + PDF, fasi, storico WhatsApp e pagina sito.
//     I PDF dei preventivi incorporano le immagini in base64 -> restano
//     completi anche dopo. Segna `foto_archiviate_at` sulla scheda.
//
// Versione minima del task "Gestione archivio cliente / Cestino" (vedi TODO).
//
// Auth: come gli altri endpoint chiamati in fetch dal browser, ci si
// affida al Basic Auth del .htaccess (NON ardyRequireAuth, che su questo
// server in CGI/FPM non riceve l'header Authorization).
// Sicurezza: richiede la parola di conferma giusta e session_id sanificato.
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

// Dimensione ricorsiva di una cartella (byte) — per riportare lo spazio liberato
function ardy_dir_size($dir) {
    if (!is_dir($dir)) return 0;
    $tot = 0;
    foreach (scandir($dir) as $f) {
        if ($f === '.' || $f === '..') continue;
        $p = $dir . '/' . $f;
        $tot += is_dir($p) ? ardy_dir_size($p) : (filesize($p) ?: 0);
    }
    return $tot;
}
function ardy_umano($b) {
    if ($b >= 1073741824) return round($b / 1073741824, 2) . ' GB';
    if ($b >= 1048576)    return round($b / 1048576, 1) . ' MB';
    if ($b >= 1024)       return round($b / 1024, 0) . ' KB';
    return $b . ' B';
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

// Cancella i file pesanti di una sessione (foto + reel) e ritorna byte liberati
function ardy_elimina_file_sessione($sessionId) {
    $bytes = 0;
    $reel  = 0;
    if (defined('ARDY_UPLOAD_DIR')) {
        $fotoDir = rtrim(ARDY_UPLOAD_DIR, '/') . '/' . $sessionId;
        $bytes  += ardy_dir_size($fotoDir);
        ardy_rrmdir($fotoDir);
    }
    foreach (glob(__DIR__ . '/reels/reel_' . $sessionId . '_*.mp4') ?: [] as $r) {
        $bytes += filesize($r) ?: 0;
        if (@unlink($r)) $reel++;
    }
    return ['bytes' => $bytes, 'reel_file' => $reel];
}

$input     = json_decode(file_get_contents('php://input'), true);
$sessionId = ardy_clean_session($input['session_id'] ?? '');
$conferma  = (string) ($input['conferma'] ?? '');
$azione    = (string) ($input['azione'] ?? 'elimina');

if ($sessionId === '') {
    echo json_encode(['success' => false, 'error' => 'session_id mancante']);
    exit();
}

// ═══════════════════════════════════════════════════════════
// AZIONE: libera_spazio (cancella solo foto + reel, tiene tutto il resto)
// ═══════════════════════════════════════════════════════════
if ($azione === 'libera_spazio') {
    if ($conferma !== 'LIBERA') {
        echo json_encode(['success' => false, 'error' => 'Conferma mancante: serve "LIBERA"']);
        exit();
    }
    try {
        $db  = ardyDB();
        $chk = $db->prepare("SELECT 1 FROM clienti WHERE session_id = :sid LIMIT 1");
        $chk->execute([':sid' => $sessionId]);
        if (!$chk->fetchColumn()) {
            echo json_encode(['success' => false, 'error' => 'Nessuna scheda per questa sessione']);
            exit();
        }

        $res = ardy_elimina_file_sessione($sessionId);

        // Segna la data dell'archiviazione foto (colonna auto-creata se manca)
        try {
            $db->exec("ALTER TABLE clienti ADD COLUMN foto_archiviate_at DATETIME NULL");
        } catch (PDOException $e) { /* colonna già presente */ }
        try {
            $up = $db->prepare("UPDATE clienti SET foto_archiviate_at = NOW() WHERE session_id = :sid");
            $up->execute([':sid' => $sessionId]);
        } catch (PDOException $e) {
            error_log('ARDY LIBERA SPAZIO (foto_archiviate_at): ' . $e->getMessage());
        }

        echo json_encode([
            'success'       => true,
            'azione'        => 'libera_spazio',
            'reel_file'     => $res['reel_file'],
            'spazio'        => ardy_umano($res['bytes']),
            'bytes_liberati'=> $res['bytes'],
        ]);
    } catch (PDOException $e) {
        error_log('ARDY LIBERA SPAZIO ERROR: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Errore interno']);
    }
    exit();
}

// ═══════════════════════════════════════════════════════════
// AZIONE: elimina (hard-delete completo) — default
// ═══════════════════════════════════════════════════════════
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
$res = ardy_elimina_file_sessione($sessionId);
$deleted['reel_file'] = $res['reel_file'];

echo json_encode(['success' => true, 'deleted' => $deleted]);
