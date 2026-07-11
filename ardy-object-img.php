<?php
// -----------------------------------------------------------
// ARDY LAB — Serve pubblico di una FOTO VENDITA di un oggetto
// Le foto vendita (Modulo 2, tabella progetto_foto_vendita) sono le immagini pubbliche
// del pezzo in vendita — DISTINTE dalla galleria del progetto (Modulo 1 → ardy-lab.it).
// Questo endpoint le serve in chiaro (sono le foto pubbliche del prodotto in vendita,
// indipendenti dalla chat di Sole), così WooCommerce può scaricarle durante il push
// (ardy-object-push.php). Gestisce disco locale e, se una foto è stata spostata, B2.
//   GET ?pid=N&gid=M → bytes immagine (gid = id in progetto_foto_vendita)
// PUBBLICO (fuori dal Basic Auth del .htaccess). Vedi PIANO §7.
// -----------------------------------------------------------

require_once __DIR__ . '/ardy-db.php';
require_once __DIR__ . '/ardy-net.php';       // ardyClientIp
require_once __DIR__ . '/ardy-storage.php';   // B2 con fallback su disco

$pid = (int) ($_GET['pid'] ?? 0);
$gid = (int) ($_GET['gid'] ?? 0);
if ($pid <= 0 || $gid <= 0) { http_response_code(400); exit(); }

// Rate limit leggero per IP (banda).
$rlDir = __DIR__ . '/ardy-rate-limit/';
if (!is_dir($rlDir)) @mkdir($rlDir, 0755, true);
$ip     = function_exists('ardyClientIp') ? ardyClientIp() : ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
$rlFile = $rlDir . md5($ip) . '_objimg.json';
$now    = time();
$rl     = is_file($rlFile) ? json_decode((string) file_get_contents($rlFile), true) : ['count' => 0, 'reset' => $now + 3600];
if (!is_array($rl) || $now > ($rl['reset'] ?? 0)) { $rl = ['count' => 0, 'reset' => $now + 3600]; }
$rl['count']++;
@file_put_contents($rlFile, json_encode($rl));
if ($rl['count'] > 600) { http_response_code(429); exit(); }

try {
    $db = ardyDB();

    // Le foto sono quelle di VENDITA (Modulo 2), NON la galleria (Modulo 1). Sono
    // immagini pubbliche del prodotto in vendita: si servono a prescindere dalla
    // visibilità a Sole (un pezzo può essere venduto anche senza chat). Gate = solo
    // progetto non eliminato + riga esistente.
    $st = $db->prepare(
        "SELECT v.storage, v.nome_file, v.storage_key
         FROM progetto_foto_vendita v
         JOIN progetti p ON p.id = v.progetto_id
         WHERE v.id = ? AND v.progetto_id = ? AND p.deleted_at IS NULL
         LIMIT 1"
    );
    $st->execute([$gid, $pid]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) { http_response_code(404); exit(); }

    $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    if (($row['storage'] ?? 'local') === 'b2' && $row['storage_key'] && ardyB2Configured()) {
        $bytes = ardyStorageGet($row['storage_key']);
        if ($bytes === null) { http_response_code(404); exit(); }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (!in_array($mime, $allowed, true)) { http_response_code(403); exit(); }
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . strlen($bytes));
        header('Cache-Control: public, max-age=3600');
        echo $bytes;
        exit();
    }

    $path = rtrim(ARDY_UPLOAD_DIR, '/') . '/progetti/' . $pid . '/vendita/' . basename($row['nome_file']);
    if (!is_file($path)) { http_response_code(404); exit(); }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
    if (!in_array($mime, $allowed, true)) { http_response_code(403); exit(); }
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: public, max-age=3600');
    readfile($path);
} catch (Throwable $e) {
    error_log('ARDY OBJECT IMG: ' . $e->getMessage());
    http_response_code(500);
}
