<?php
// -----------------------------------------------------------
// ARDY LAB — Foto di una fase cliente, per lo staff (dash)
// Serve le foto che dal passo 2 dell'area cliente restano sul server invece di andare
// su WordPress: il loro indirizzo (fasi.foto_urls) punta qui. Il cliente non passa da
// qui ma dall'area (ardy-area-cliente-api.php?mode=foto, col suo cookie).
//
//   GET ?s=<session_id>&f=<file>  → l'immagine
//
// Protetto da Basic Auth via .htaccess (elencato nel <FilesMatch>) + ardyRequireAuth().
// -----------------------------------------------------------

require_once __DIR__ . '/ardy-auth.php';
ardyRequireAuth();
require_once __DIR__ . '/ardy-fasi-foto-lib.php';

$s = fasiFotoSessione((string) ($_GET['s'] ?? ''));
$f = (string) ($_GET['f'] ?? '');
if ($s === '' || !fasiFotoNomeValido($f)) { http_response_code(400); exit(); }
$path = fasiFotoDir($s) . $f;
if (!is_file($path)) { http_response_code(404); exit(); }
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) { http_response_code(403); exit(); }
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=86400');
header('X-Robots-Tag: noindex');
readfile($path);
