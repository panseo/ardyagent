<?php
// -----------------------------------------------------------
// ARDY LAB — Dash Design: pubblica una FASE-RACCONTO sull'articolo del progetto
// Aggancia (append) la fase all'articolo "madre" creato da ardy-pubblica-progetto.php:
// testo + foto della fase si aggiungono in coda al post WordPress del progetto.
//
//   POST {progetto_id, fase_id}  → append della fase all'articolo; segna wp_pubblicata_at.
//
// Richiede che l'articolo del progetto sia già pubblicato (progetti.wp_post_id).
// Protetto via .htaccess (Basic Auth) — elencato nel <FilesMatch>.
// -----------------------------------------------------------

date_default_timezone_set('Europe/Rome');
define('ARDY_WP_LOAD', '/home/micoperibg/public_html/archivio/wp-load.php');

require_once __DIR__ . '/ardy-config.php';
require_once __DIR__ . '/ardy-db.php';
require_once __DIR__ . '/ardy-net.php';
require_once __DIR__ . '/ardy-storage.php';

header('Access-Control-Allow-Origin: https://ardyagent.ardy-lab.it');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST')    { http_response_code(405); echo json_encode(['success' => false, 'error' => 'Metodo non valido']); exit(); }

$in         = json_decode(file_get_contents('php://input'), true) ?: [];
$progettoId = (int) ($in['progetto_id'] ?? 0);
$faseId     = (int) ($in['fase_id'] ?? 0);
if ($progettoId <= 0 || $faseId <= 0) { echo json_encode(['success' => false, 'error' => 'dati mancanti']); exit(); }

try {
    $db = ardyDB();
    $pst = $db->prepare("SELECT wp_post_id FROM progetti WHERE id = ? AND deleted_at IS NULL");
    $pst->execute([$progettoId]);
    $wpPostId = (int) ($pst->fetchColumn() ?: 0);
    if ($wpPostId <= 0) { echo json_encode(['success' => false, 'error' => 'Pubblica prima l\'articolo del progetto']); exit(); }

    $fst = $db->prepare("SELECT fase_nome, testo_breve, testo_generato, foto_urls FROM fasi WHERE id = ? AND progetto_id = ?");
    $fst->execute([$faseId, $progettoId]);
    $fase = $fst->fetch();
    if (!$fase) { echo json_encode(['success' => false, 'error' => 'Fase non trovata']); exit(); }
    $faseNome = trim((string) $fase['fase_nome']);
    $testo    = trim((string) ($fase['testo_generato'] ?: $fase['testo_breve']));
    $foto     = json_decode($fase['foto_urls'] ?? '[]', true) ?: [];
} catch (PDOException $e) {
    error_log('ARDY PUBBLICA FASE PROG DB: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Errore database']); exit();
}

if (!file_exists(ARDY_WP_LOAD)) { echo json_encode(['success' => false, 'error' => 'wp-load.php non trovato']); exit(); }
$_SERVER['HTTP_HOST']   = 'ardy-lab.it';
$_SERVER['REQUEST_URI'] = '/';
if (!defined('WP_USE_THEMES')) define('WP_USE_THEMES', false);
require_once ARDY_WP_LOAD;
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

// Byte di una foto della fase (disco-prima, poi B2).
function faseProgFotoBytes(int $pid, int $fid, string $file): ?string {
    $base = 'progetti/' . $pid . '/fasi/' . $fid . '/' . basename($file);
    $path = rtrim(ARDY_UPLOAD_DIR, '/') . '/' . $base;
    if (is_file($path)) { $b = @file_get_contents($path); return $b !== false ? $b : null; }
    if (ardyB2Configured()) return ardyStorageGet($base);
    return null;
}

$tmpDir = rtrim(ARDY_UPLOAD_DIR, '/') . '/progetti/' . $progettoId . '/_wp_tmp/';
if (!is_dir($tmpDir) && !mkdir($tmpDir, 0755, true) && !is_dir($tmpDir)) {
    echo json_encode(['success' => false, 'error' => 'cartella temporanea non creabile']); exit();
}
ardyHardenUploadDir(rtrim(ARDY_UPLOAD_DIR, '/'));

$fotoHtml = '';
foreach (array_slice($foto, 0, 12) as $fn) {
    if (!is_string($fn) || $fn === '') continue;
    $bytes = faseProgFotoBytes($progettoId, $faseId, $fn);
    if (!is_string($bytes) || strlen($bytes) < 12) continue;
    $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
    $extMap = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    if (!isset($extMap[$mime])) continue;
    $tname = 'f_' . uniqid() . '.' . $extMap[$mime];
    $tpath = $tmpDir . $tname;
    if (file_put_contents($tpath, $bytes) === false) continue;
    $attachId = media_handle_sideload(['name' => $tname, 'type' => $mime, 'tmp_name' => $tpath, 'error' => 0, 'size' => strlen($bytes)], 0);
    if (is_wp_error($attachId)) { @unlink($tpath); error_log('ARDY PUBBLICA FASE PROG SIDELOAD: ' . $attachId->get_error_message()); continue; }
    $fotoHtml .= '<img src="' . esc_url(wp_get_attachment_url($attachId)) . '" style="max-width:100%;margin:10px 0;border-radius:6px;" />' . "\n";
}
foreach (glob($tmpDir . '*') as $f) { if (is_file($f)) @unlink($f); }
@rmdir($tmpDir);

$blocco = '
<div style="border-left:3px solid #c8a96e;padding:16px 20px;margin:24px 0;background:#fafaf8;">
  <p style="font-size:12px;color:#999;margin-bottom:8px;font-family:monospace;">' . esc_html(date('d/m/Y')) . ($faseNome !== '' ? ' — ' . esc_html($faseNome) : '') . '</p>'
  . ($testo !== '' ? '<div style="font-size:15px;line-height:1.7;color:#333;">' . nl2br(esc_html($testo)) . '</div>' : '')
  . $fotoHtml . '
</div>';

kses_remove_filters();
$post = get_post($wpPostId);
if (!$post) { echo json_encode(['success' => false, 'error' => 'Articolo non trovato su WordPress']); exit(); }
$result = wp_update_post(['ID' => $wpPostId, 'post_content' => $post->post_content . "\n" . $blocco], true);
if (is_wp_error($result)) {
    error_log('ARDY PUBBLICA FASE PROG WP UPDATE: ' . $result->get_error_message());
    echo json_encode(['success' => false, 'error' => 'Aggiornamento articolo non riuscito']); exit();
}

try {
    $db->prepare("UPDATE fasi SET wp_pubblicata_at = NOW() WHERE id = ? AND progetto_id = ?")->execute([$faseId, $progettoId]);
} catch (PDOException $e) { error_log('ARDY PUBBLICA FASE PROG DB UPD: ' . $e->getMessage()); }

echo json_encode(['success' => true, 'post_link' => get_permalink($wpPostId)]);
