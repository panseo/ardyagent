<?php
// -----------------------------------------------------------
// ARDY LAB — Pubblica sui social (passo separato e manuale)
// Chiamato dalla dashboard quando Michela decide di pubblicare
// (subito o in un secondo momento) un post già rivisto/modificato.
// -----------------------------------------------------------

date_default_timezone_set('Europe/Rome');

header('Access-Control-Allow-Origin: https://ardyagent.ardy-lab.it');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

$input       = json_decode(file_get_contents('php://input'), true);
$testoSocial = trim($input['testo_social'] ?? '');
$fase        = $input['fase']      ?? '';
$mobile      = $input['mobile']    ?? '';
$postLink    = $input['post_link'] ?? '';
$immagini    = $input['immagini']  ?? [];
$cliente     = $input['cliente']   ?? '';
$testo       = $input['testo']     ?? $testoSocial;

if ($testoSocial === '') {
    echo json_encode(['success' => false, 'error' => 'Testo del post mancante']);
    exit();
}

$webhookUrl  = 'https://n8n.ardy-lab.it/webhook/7d01db65-cc21-4192-ab15-0bdbfd070362';
$webhookData = [
    'testo'        => $testo,
    'fase'         => $fase,
    'mobile'       => $mobile,
    'post_link'    => $postLink,
    'immagini'     => is_array($immagini) ? $immagini : [],
    'cliente'      => $cliente,
    'testo_social' => $testoSocial,
];

$ch = curl_init($webhookUrl);
curl_setopt($ch, CURLOPT_POST,           true);
curl_setopt($ch, CURLOPT_POSTFIELDS,     json_encode($webhookData));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT,        15);
curl_setopt($ch, CURLOPT_HTTPHEADER,     ['Content-Type: application/json']);
$res      = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err      = curl_error($ch);
curl_close($ch);

if ($err || $httpCode >= 400) {
    error_log('ARDY SOCIAL PUBLISH ERROR: http=' . $httpCode . ' err=' . $err);
    echo json_encode(['success' => false, 'error' => 'Errore durante l\'invio ai social']);
    exit();
}

echo json_encode(['success' => true]);
