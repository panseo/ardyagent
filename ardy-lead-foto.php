<?php
// -----------------------------------------------------------
// ARDY LAB — Foto della scheda cliente (CRM)
// Elenca / serve / carica le foto legate a una sessione lead.
// Usa la stessa cartella delle foto inviate in chat:
//   ARDY_UPLOAD_DIR/<session_id>/
// Protetto da Basic Auth via .htaccess (area riservata).
// -----------------------------------------------------------

require_once __DIR__ . '/ardy-config.php';

header('Access-Control-Allow-Origin: https://ardyagent.ardy-lab.it');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

$allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

// session_id sempre sanificato (no path traversal)
function ardy_clean_session($s) {
    return preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$s);
}

// -----------------------------------------------------------
// GET — serve una singola immagine, oppure elenca le foto
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $session = ardy_clean_session($_GET['session_id'] ?? '');
    if ($session === '') { http_response_code(400); echo 'session mancante'; exit(); }
    $dir = rtrim(ARDY_UPLOAD_DIR, '/') . '/' . $session . '/';

    // Servi un file specifico (per il tag <img>)
    if (!empty($_GET['file'])) {
        $file = basename($_GET['file']); // niente traversal
        $path = $dir . $file;
        if (!is_file($path)) { http_response_code(404); exit(); }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer(file_get_contents($path));
        if (!in_array($mime, $allowedMimes, true)) { http_response_code(403); exit(); }
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: private, max-age=86400');
        readfile($path);
        exit();
    }

    // Elenco foto della scheda
    header('Content-Type: application/json');
    $files = [];
    if (is_dir($dir)) {
        foreach (glob($dir . '*') as $p) {
            if (!is_file($p)) continue;
            $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer(file_get_contents($p));
            if (!in_array($mime, $allowedMimes, true)) continue;
            $files[] = ['file' => basename($p), 'mtime' => filemtime($p)];
        }
        usort($files, fn($a, $b) => $a['mtime'] <=> $b['mtime']);
    }
    echo json_encode(['success' => true, 'foto' => $files]);
    exit();
}

// -----------------------------------------------------------
// POST — carica una o più immagini (base64) sulla scheda
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $input   = json_decode(file_get_contents('php://input'), true) ?: [];
    $session = ardy_clean_session($input['session_id'] ?? '');
    $images  = $input['images'] ?? [];

    if ($session === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'session mancante']);
        exit();
    }
    if (!is_array($images) || empty($images)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'nessuna immagine']);
        exit();
    }

    $dir = rtrim(ARDY_UPLOAD_DIR, '/') . '/' . $session . '/';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'cartella non creabile']);
        exit();
    }

    $maxImgByte = 12 * 1024 * 1024; // 12 MB per immagine
    $saved = 0;
    foreach (array_slice($images, 0, 12) as $idx => $img) {
        $data = $img['data'] ?? '';
        if ($data === '') continue;
        $raw = base64_decode($data, true);
        if ($raw === false || strlen($raw) > $maxImgByte || strlen($raw) < 12) continue;
        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($raw); // tipo reale, non dichiarato
        if (!in_array($mime, $allowedMimes, true)) continue;
        switch ($mime) {
            case 'image/png':  $ext = 'png';  break;
            case 'image/gif':  $ext = 'gif';  break;
            case 'image/webp': $ext = 'webp'; break;
            default:           $ext = 'jpg';
        }
        $filename = 'michela_' . date('Ymd_His') . '_' . uniqid() . '_' . $idx . '.' . $ext;
        if (file_put_contents($dir . $filename, $raw) !== false) $saved++;
    }

    echo json_encode(['success' => $saved > 0, 'salvate' => $saved]);
    exit();
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'metodo non consentito']);
