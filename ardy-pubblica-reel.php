<?php
// -----------------------------------------------------------
// ARDY LAB — Pubblica il Reel sui social (via webhook n8n)
// Riceve dalla dashboard l'URL del reel + caption (rivista da Michela)
// e li inoltra al workflow "Meta" che fa la pubblicazione su IG/FB.
// -----------------------------------------------------------

date_default_timezone_set('Europe/Rome');

header('Access-Control-Allow-Origin: https://ardyagent.ardy-lab.it');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

const REEL_WEBHOOK = 'https://n8n.ardy-lab.it/webhook/ecf74cbf-719e-4351-97d4-200e16c93a6b';

$input    = json_decode(file_get_contents('php://input'), true) ?: [];
$reelUrl  = trim($input['reel_url'] ?? '');
$caption  = trim($input['caption'] ?? '');
$mobile   = $input['mobile']     ?? '';
$cliente  = $input['cliente']    ?? '';
$session  = $input['session_id'] ?? '';

if ($reelUrl === '') { echo json_encode(['success' => false, 'error' => 'URL del reel mancante']); exit(); }
if ($caption === '') { echo json_encode(['success' => false, 'error' => 'Caption mancante']); exit(); }

$payload = [
    'tipo'       => 'reel',
    'reel_url'   => $reelUrl,
    'caption'    => $caption,
    'mobile'     => $mobile,
    'cliente'    => $cliente,
    'session_id' => $session,
];

$ch = curl_init(REEL_WEBHOOK);
curl_setopt($ch, CURLOPT_POST,           true);
curl_setopt($ch, CURLOPT_POSTFIELDS,     json_encode($payload));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT,        20);
curl_setopt($ch, CURLOPT_HTTPHEADER,     ['Content-Type: application/json']);
$res      = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err      = curl_error($ch);
curl_close($ch);

if ($err || $httpCode >= 400) {
    error_log('ARDY REEL PUBLISH ERROR: http=' . $httpCode . ' err=' . $err);
    echo json_encode(['success' => false, 'error' => 'Errore durante l\'invio del reel a n8n']);
    exit();
}

echo json_encode(['success' => true]);
