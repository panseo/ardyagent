<?php
// -----------------------------------------------------------
// ARDY LAB — "Racconta": copertina grafica di un capitolo per i social
// La prima foto del capitolo diventa un'immagine 4:5 (1080×1350) nello stile dei reel
// di Michela: logo bianco, indirizzo, servizi e la frase con l'asterisco
// (ardy-grafica-lib.php). Il 4:5 è anche il formato più stretto che Instagram accetta.
//
//   GET  ?pezzo_id=N&fase_id=M&frase=…   → l'immagine (JPEG) per l'anteprima nella mini-app
//   POST {pezzo_id, fase_id, frase}      → la carica su WordPress e ritorna {success, url}
//        (URL pubblico: Facebook, Instagram e Google devono poterla scaricare)
//
// Una copertina già caricata con la stessa frase si riusa: niente doppioni in libreria.
// Protetto via .htaccess (Basic Auth) — elencato nel <FilesMatch>.
// -----------------------------------------------------------

date_default_timezone_set('Europe/Rome');
define('ARDY_WP_LOAD', '/home/micoperibg/public_html/archivio/wp-load.php');

require_once __DIR__ . '/ardy-db.php';
require_once __DIR__ . '/ardy-pezzi-lib.php';
require_once __DIR__ . '/ardy-grafica-lib.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }
// Difesa in profondità: se il Basic Auth a monte (.htaccess) non venisse
// applicato, questo guard rifiuta comunque le richieste non autenticate.
require_once __DIR__ . '/ardy-auth.php';
ardyRequireAuth();

$post    = $_SERVER['REQUEST_METHOD'] === 'POST';
$in      = $post ? (json_decode(file_get_contents('php://input'), true) ?: []) : $_GET;
$pezzoId = (int) ($in['pezzo_id'] ?? 0);
$faseId  = (int) ($in['fase_id'] ?? 0);
$frase   = mb_substr(trim((string) ($in['frase'] ?? '')), 0, 80);

function copertinaFail(string $msg, int $code = 400): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $msg]);
    exit();
}

try {
    $db = ardyDB();
    $st = $db->prepare("SELECT p.titolo, p.wp_post_id, f.fase_nome, f.foto_urls FROM pezzi p JOIN fasi f ON f.pezzo_id = p.id
                         WHERE p.id = ? AND f.id = ? AND p.deleted_at IS NULL");
    $st->execute([$pezzoId, $faseId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('ARDY PEZZI COPERTINA DB: ' . $e->getMessage());
    copertinaFail('Errore interno', 500);
}
if (!$r) copertinaFail('Capitolo non trovato', 404);
$foto = json_decode((string) $r['foto_urls'], true) ?: [];
$path = $foto ? pezziFotoDir($pezzoId, $faseId) . basename((string) $foto[0]) : '';
if ($path === '' || !is_file($path)) copertinaFail('Il capitolo non ha foto');
if (!grafDisponibile()) copertinaFail('Grafica non disponibile sul server (GD/FreeType o font Inter mancanti)', 500);

$jpg = grafCopertina((string) file_get_contents($path), $frase, 1080, 1350);
if ($jpg === null) copertinaFail('Copertina non generata', 500);

if (!$post) {
    header('Content-Type: image/jpeg');
    header('Cache-Control: private, max-age=60');
    echo $jpg;
    exit();
}

header('Content-Type: application/json');

// Cache per (capitolo, frase): la stessa copertina non si ricarica a ogni pubblicazione.
$cacheFile = rtrim(ARDY_UPLOAD_DIR, '/') . '/pezzi/' . $pezzoId . '/copertine.json';
$cache = is_file($cacheFile) ? (json_decode((string) file_get_contents($cacheFile), true) ?: []) : [];
$chiave = $faseId . ':' . md5($frase . '|' . $foto[0]);
if (!empty($cache[$chiave])) { echo json_encode(['success' => true, 'url' => $cache[$chiave]]); exit(); }

if (!file_exists(ARDY_WP_LOAD)) copertinaFail('WordPress non raggiungibile', 500);
$_SERVER['HTTP_HOST']   = 'ardy-lab.it';
$_SERVER['REQUEST_URI'] = '/';
if (!defined('WP_USE_THEMES')) define('WP_USE_THEMES', false);
require_once ARDY_WP_LOAD;
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

$tmp = rtrim(ARDY_UPLOAD_DIR, '/') . '/pezzi/' . $pezzoId . '/cop_' . uniqid() . '.jpg';
if (file_put_contents($tmp, $jpg) === false) copertinaFail('Scrittura non riuscita', 500);
$attachId = media_handle_sideload(['name' => 'ardy-' . $pezzoId . '-' . $faseId . '-copertina.jpg', 'type' => 'image/jpeg',
    'tmp_name' => $tmp, 'error' => 0, 'size' => strlen($jpg)], (int) $r['wp_post_id']);
if (is_wp_error($attachId)) {
    @unlink($tmp);
    error_log('ARDY PEZZI COPERTINA SIDELOAD: ' . $attachId->get_error_message());
    copertinaFail('Caricamento su WordPress non riuscito', 500);
}
update_post_meta($attachId, '_wp_attachment_image_alt', trim($r['titolo'] . ': ' . $r['fase_nome']));
$url = (string) wp_get_attachment_url($attachId);

$cache[$chiave] = $url;
@file_put_contents($cacheFile, json_encode($cache, JSON_UNESCAPED_SLASHES));
echo json_encode(['success' => true, 'url' => $url]);
