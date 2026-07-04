<?php
// -----------------------------------------------------------
// ARDY LAB — Lista tracce musicali per i Reel
// Elenca i file audio royalty-free presenti in assets/reel-music/
// -----------------------------------------------------------

header('Access-Control-Allow-Origin: https://ardyagent.ardy-lab.it');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }
// Difesa in profondità: se il Basic Auth a monte (.htaccess) non venisse
// applicato, questo guard rifiuta comunque le richieste non autenticate.
require_once __DIR__ . '/ardy-auth.php';
ardyRequireAuth();

$dir = __DIR__ . '/assets/reel-music/';
$out = [];

if (is_dir($dir)) {
    foreach (scandir($dir) as $f) {
        if ($f === '.' || $f === '..') continue;
        if (!preg_match('/\.(mp3|m4a|aac|wav)$/i', $f)) continue;
        $out[] = [
            'file'  => $f,
            // Etichetta leggibile: nome file senza estensione, _ -> spazio
            'label' => ucfirst(str_replace(['_', '-'], ' ', preg_replace('/\.[^.]+$/', '', $f))),
        ];
    }
}

echo json_encode($out);
