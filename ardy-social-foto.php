<?php
// -----------------------------------------------------------
// ARDY LAB — Carica una foto per un post social → URL pubblico
// -----------------------------------------------------------
// Riceve UNA immagine in base64 e la carica nella WP Media Library,
// restituendo l'URL pubblico (quello che serve a Instagram/Facebook via API).
// Usato dalla dashboard quando si AGGIUNGE una foto a un post social
// (composer o bozza) oltre a quelle già provenienti dalla fase.
//
//   POST { immagine: "<base64 senza prefisso data:>" } → { success, url }
//
// Dietro Basic Auth via .htaccess (come gli altri endpoint in fetch).
// -----------------------------------------------------------

date_default_timezone_set('Europe/Rome');

define('ARDY_WP_LOAD', '/home/micoperibg/public_html/archivio/wp-load.php');

require_once __DIR__ . '/ardy-config.php';
require_once __DIR__ . '/ardy-net.php';

header('Access-Control-Allow-Origin: https://ardyagent.ardy-lab.it');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }
// Difesa in profondità: se il Basic Auth a monte (.htaccess) non venisse
// applicato, questo guard rifiuta comunque le richieste non autenticate.
require_once __DIR__ . '/ardy-auth.php';
ardyRequireAuth();

$input  = json_decode(file_get_contents('php://input'), true) ?: [];
$imgB64 = (string) ($input['immagine'] ?? '');
// Tollera l'eventuale prefisso data:...;base64,
if (strpos($imgB64, ',') !== false && stripos($imgB64, 'base64') !== false) {
    $imgB64 = substr($imgB64, strpos($imgB64, ',') + 1);
}

if ($imgB64 === '') {
    echo json_encode(['success' => false, 'error' => 'Nessuna immagine']);
    exit();
}

$decoded = base64_decode($imgB64, true);
if ($decoded === false) {
    echo json_encode(['success' => false, 'error' => 'Immagine non valida']);
    exit();
}

$maxByte = 12 * 1024 * 1024;
if (strlen($decoded) > $maxByte) {
    echo json_encode(['success' => false, 'error' => 'Immagine troppo grande (max 12 MB)']);
    exit();
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime  = $finfo->buffer($decoded);
$allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
if (!in_array($mime, $allowedMimes, true)) {
    echo json_encode(['success' => false, 'error' => 'Formato non supportato (solo JPG/PNG/WEBP)']);
    exit();
}

// ── Carica WordPress (stesso fix anti-redirect di ardy-pubblica-lavorazione) ──
if (!file_exists(ARDY_WP_LOAD)) {
    echo json_encode(['success' => false, 'error' => 'wp-load.php non trovato']);
    exit();
}
$_SERVER['HTTP_HOST']   = 'ardy-lab.it';
$_SERVER['REQUEST_URI'] = '/';
define('WP_USE_THEMES', false);
require_once ARDY_WP_LOAD;
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

// ── Salva su disco (cartella upload protetta) e sideload su WP Media Library ──
$socialDir = ARDY_UPLOAD_DIR . 'social/';
if (!is_dir($socialDir) && !mkdir($socialDir, 0755, true) && !is_dir($socialDir)) {
    echo json_encode(['success' => false, 'error' => 'Impossibile creare la cartella di upload']);
    exit();
}
ardyHardenUploadDir(rtrim(ARDY_UPLOAD_DIR, '/'));

$ext      = $mime === 'image/png' ? 'png' : ($mime === 'image/webp' ? 'webp' : 'jpg');
$filename = date('Ymd_His') . '_' . uniqid() . '.' . $ext;
$filepath = $socialDir . $filename;
if (file_put_contents($filepath, $decoded) === false) {
    echo json_encode(['success' => false, 'error' => 'Scrittura su disco fallita']);
    exit();
}

$wpFile = [
    'name'     => $filename,
    'type'     => $mime,
    'tmp_name' => $filepath,
    'error'    => 0,
    'size'     => filesize($filepath),
];
$attachId = media_handle_sideload($wpFile, 0);
if (is_wp_error($attachId)) {
    error_log('ARDY SOCIAL FOTO SIDELOAD ERROR (' . $mime . '): ' . $attachId->get_error_message());
    echo json_encode(['success' => false, 'error' => 'Caricamento su WordPress fallito']);
    exit();
}

$url = wp_get_attachment_url($attachId);
echo json_encode(['success' => true, 'url' => $url]);
