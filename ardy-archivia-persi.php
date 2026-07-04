<?php
// -----------------------------------------------------------
// ARDY LAB — Archivia foto dei clienti PERSI ("Libera spazio")
// -----------------------------------------------------------
// Cosa fa: SPOSTA (non cancella) in una cartella di quarantena le foto e i
// reel dei clienti in stato PERSO, così Michela può poi cancellarli a mano
// dal server in un colpo solo. Idempotente: rilanciabile senza danni.
//
//   Foto  ->  ARDY_UPLOAD_DIR/_da_liberare/<session_id>/
//   Reel  ->  reels/_da_liberare/
//
// NON tocca il DB, NON tocca il sito WordPress, NON cancella nulla.
// Protetta da Basic Auth via .htaccess (stessa cartella degli altri endpoint).
// -----------------------------------------------------------

require_once __DIR__ . '/ardy-db.php';
require_once __DIR__ . '/ardy-net.php'; // ardyHardenUploadDir()
date_default_timezone_set('Europe/Rome');

header('Access-Control-Allow-Origin: https://ardyagent.ardy-lab.it');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }
// Difesa in profondità: se il Basic Auth a monte (.htaccess) non venisse
// applicato, questo guard rifiuta comunque le richieste non autenticate.
require_once __DIR__ . '/ardy-auth.php';
ardyRequireAuth();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Usa POST.']);
    exit();
}

// -- helper: dimensione ricorsiva di una cartella (in byte) ------------------
function dirSize(string $dir): int {
    $tot = 0;
    if (!is_dir($dir)) return 0;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $f) { if ($f->isFile()) $tot += $f->getSize(); }
    return $tot;
}
function umano(int $b): string {
    if ($b >= 1073741824) return round($b / 1073741824, 2) . ' GB';
    if ($b >= 1048576)    return round($b / 1048576, 1) . ' MB';
    if ($b >= 1024)       return round($b / 1024, 0) . ' KB';
    return $b . ' B';
}

try {
    $db = ardyDB();

    // Cartelle di quarantena (stesso filesystem -> rename veloce, niente copia)
    $uploadBase = rtrim(ARDY_UPLOAD_DIR, '/') . '/';
    $fotoQuar   = $uploadBase . '_da_liberare/';
    $reelsDir   = __DIR__ . '/reels/';
    $reelsQuar  = $reelsDir . '_da_liberare/';

    foreach ([$fotoQuar, $reelsQuar] as $q) {
        if (!is_dir($q)) @mkdir($q, 0755, true);
    }
    ardyHardenUploadDir(rtrim($fotoQuar, '/'));  // quarantena: niente esecuzione script
    ardyHardenUploadDir(rtrim($reelsQuar, '/'));

    // Tutti i clienti PERSI
    $rows = $db->query(
        "SELECT session_id, nome, cognome FROM clienti WHERE UPPER(stato) = 'PERSO'"
    )->fetchAll(PDO::FETCH_ASSOC);

    $dettagli   = [];
    $bytesTot   = 0;
    $fotoMosse  = 0;  // cartelle foto spostate
    $reelMossi  = 0;  // file reel spostati

    foreach ($rows as $r) {
        $sid = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)($r['session_id'] ?? ''));
        if ($sid === '') continue;

        $nome = trim(($r['nome'] ?? '') . ' ' . ($r['cognome'] ?? ''));
        $voce = ['nome' => $nome !== '' ? $nome : $sid, 'foto' => false, 'reel' => 0, 'size' => 0];

        // 1) Cartella foto del cliente -> quarantena
        $src = $uploadBase . $sid . '/';
        if (is_dir($src)) {
            $sz  = dirSize($src);
            $dst = $fotoQuar . $sid . '/';
            // se già archiviato in passato, aggiungo un suffisso per non sovrascrivere
            if (is_dir($dst)) $dst = $fotoQuar . $sid . '_' . date('Ymd_His') . '/';
            if (@rename($src, rtrim($dst, '/'))) {
                $voce['foto'] = true;
                $voce['size'] += $sz;
                $bytesTot     += $sz;
                $fotoMosse++;
            }
        }

        // 2) Reel del cliente (reel_<sid>_*.mp4) -> quarantena
        foreach (glob($reelsDir . 'reel_' . $sid . '_*.mp4') ?: [] as $reel) {
            if (!is_file($reel)) continue;
            $sz  = filesize($reel) ?: 0;
            $dst = $reelsQuar . basename($reel);
            if (is_file($dst)) $dst = $reelsQuar . date('Ymd_His') . '_' . basename($reel);
            if (@rename($reel, $dst)) {
                $voce['reel']++;
                $voce['size'] += $sz;
                $bytesTot     += $sz;
                $reelMossi++;
            }
        }

        if ($voce['foto'] || $voce['reel'] > 0) {
            $voce['size_u'] = umano($voce['size']);
            $dettagli[] = $voce;
        }
    }

    echo json_encode([
        'ok'            => true,
        'clienti_persi' => count($rows),
        'cartelle_foto' => $fotoMosse,
        'reel_spostati' => $reelMossi,
        'spazio'        => umano($bytesTot),
        'percorso_foto' => $fotoQuar,
        'percorso_reel' => $reelsQuar,
        'dettagli'      => $dettagli,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    error_log('ARDY ARCHIVIA PERSI ERROR: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Errore interno']);
}
