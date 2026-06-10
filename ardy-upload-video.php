<?php
// -----------------------------------------------------------
// ARDY LAB — Upload video lavorazione
// Riceve UN video via multipart/form-data (memory-friendly per file grandi),
// lo valida, lo salva in ARDY_UPLOAD_DIR/<session>/lavorazioni/ e lo carica
// nella Media Library di WordPress. Ritorna l'URL pubblico.
//
// Protetto da Basic Auth via .htaccess (area riservata dashboard).
// Usato da ardy-michela-app.html prima di "Pubblica fase".
// -----------------------------------------------------------

date_default_timezone_set('Europe/Rome');

define('ARDY_WP_LOAD', '/home/micoperibg/public_html/archivio/wp-load.php');

require_once __DIR__ . '/ardy-config.php';

header('Access-Control-Allow-Origin: https://ardyagent.ardy-lab.it');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'metodo non consentito']);
    exit();
}

// -----------------------------------------------------------
// 1. INPUT E VALIDAZIONE
// -----------------------------------------------------------
function ardy_clean_session($s) {
    return preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$s);
}

$session = ardy_clean_session($_POST['session_id'] ?? '');
if ($session === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'session mancante']);
    exit();
}

if (empty($_FILES['video']) || !is_uploaded_file($_FILES['video']['tmp_name'] ?? '')) {
    // Se l'upload supera post_max_size, $_FILES è vuoto: messaggio chiaro
    $err = ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0
        ? 'video troppo grande per il server (post_max_size)'
        : 'nessun video ricevuto';
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $err]);
    exit();
}

$file = $_FILES['video'];
if ((int)$file['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'errore upload (codice ' . (int)$file['error'] . ')']);
    exit();
}

$maxByte = 150 * 1024 * 1024; // 150 MB per video
if ((int)$file['size'] > $maxByte) {
    http_response_code(413);
    echo json_encode(['success' => false, 'error' => 'video oltre 150 MB']);
    exit();
}

// MIME reale (non quello dichiarato dal browser)
$allowedMimes = [
    'video/mp4'        => 'mp4',
    'video/quicktime'  => 'mov',
    'video/webm'       => 'webm',
    'video/x-m4v'      => 'm4v',
    'video/3gpp'       => '3gp',
];
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
if (!isset($allowedMimes[$mime])) {
    http_response_code(415);
    echo json_encode(['success' => false, 'error' => 'formato non supportato: ' . $mime]);
    exit();
}
$ext = $allowedMimes[$mime];

// -----------------------------------------------------------
// 2. SALVA SU DISCO
// -----------------------------------------------------------
$dir = rtrim(ARDY_UPLOAD_DIR, '/') . '/' . $session . '/lavorazioni/';
if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'cartella non creabile']);
    exit();
}

$filename = 'video_' . date('Ymd_His') . '_' . uniqid() . '.' . $ext;
$filepath = $dir . $filename;
if (!move_uploaded_file($file['tmp_name'], $filepath)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'salvataggio fallito']);
    exit();
}

// -----------------------------------------------------------
// 3. CARICA NELLA MEDIA LIBRARY DI WORDPRESS
// -----------------------------------------------------------
if (!file_exists(ARDY_WP_LOAD)) {
    // Fallback: niente WP, ma il file è salvato. Nessun URL pubblico WP.
    echo json_encode(['success' => false, 'error' => 'wp-load.php non trovato']);
    exit();
}

$_SERVER['HTTP_HOST']   = 'ardy-lab.it';
$_SERVER['REQUEST_URI'] = '/';
if (!defined('WP_USE_THEMES')) define('WP_USE_THEMES', false);
require_once ARDY_WP_LOAD;
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

$wpFile = [
    'name'     => $filename,
    'type'     => $mime,
    'tmp_name' => $filepath,
    'error'    => 0,
    'size'     => filesize($filepath),
];
$attachId = media_handle_sideload($wpFile, 0);
if (is_wp_error($attachId)) {
    error_log('ARDY UPLOAD VIDEO WP ERROR: ' . $attachId->get_error_message());
    echo json_encode(['success' => false, 'error' => 'Caricamento del media non riuscito']);
    exit();
}

echo json_encode([
    'success' => true,
    'url'     => wp_get_attachment_url($attachId),
    'id'      => (int)$attachId,
    'mime'    => $mime,
]);
