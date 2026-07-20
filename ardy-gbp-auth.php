<?php
// -----------------------------------------------------------
// ARDY LAB — OAuth flow DEDICATO Google Business Profile
// -----------------------------------------------------------
// Crea/aggiorna il token dedicato `ardy-gbp-token.json` con il SOLO
// scope `business.manage`. Serve perché quello stesso scope, messo in
// bundle con Calendar/Gmail in ardy-gcal-auth.php su un'app non
// verificata, non veniva concesso (403 HTML al front-end). Richiesto da
// solo, invece, viene concesso: verificato con OAuth Playground.
//
// Uso (una tantum): aprire nel browser
//   https://ardyagent.ardy-lab.it/ardy-gbp-auth.php
// completare il consenso Google con l'account che gestisce la scheda
// (ardy.documenti@gmail.com), poi rilanciare ardy-gbp-check.php.
//
// Client OAuth: ARDY_GBP_CLIENT_* se definiti in ardy-config.php,
// altrimenti fallback su ARDY_GCAL_CLIENT_*. Il redirect qui sotto deve
// essere tra gli "URI di reindirizzamento autorizzati" del client.
// -----------------------------------------------------------

require_once __DIR__ . '/ardy-config.php';

// Difesa in profondità: come ardy-gcal-auth.php, questo flusso è dietro
// Basic Auth (.htaccess). Se non venisse applicato, il guard rifiuta.
require_once __DIR__ . '/ardy-auth.php';
ardyRequireAuth();

$CLIENT_ID     = defined('ARDY_GBP_CLIENT_ID')     ? ARDY_GBP_CLIENT_ID     : ARDY_GCAL_CLIENT_ID;
$CLIENT_SECRET = defined('ARDY_GBP_CLIENT_SECRET') ? ARDY_GBP_CLIENT_SECRET : ARDY_GCAL_CLIENT_SECRET;

$REDIRECT_URI  = 'https://ardyagent.ardy-lab.it/ardy-gbp-auth.php';
$TOKEN_FILE    = __DIR__ . '/ardy-gbp-token.json';

if (isset($_GET['code'])) {
    // Verifica state anti-CSRF
    session_start();
    if (empty($_GET['state']) || empty($_SESSION['oauth_state']) || !hash_equals($_SESSION['oauth_state'], $_GET['state'])) {
        http_response_code(400);
        echo '<h2 style="color:red;font-family:sans-serif">Errore: state OAuth non valido.</h2>';
        exit();
    }
    unset($_SESSION['oauth_state']);

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'code'          => $_GET['code'],
        'client_id'     => $CLIENT_ID,
        'client_secret' => $CLIENT_SECRET,
        'redirect_uri'  => $REDIRECT_URI,
        'grant_type'    => 'authorization_code'
    ]));
    $token = json_decode(curl_exec($ch), true);
    curl_close($ch);
    if (isset($token['refresh_token'])) {
        $token['created_at'] = time();
        file_put_contents($TOKEN_FILE, json_encode($token, JSON_PRETTY_PRINT));
        echo '<h2 style="color:green;font-family:sans-serif">Token Business Profile salvato! Ora rilancia ardy-gbp-check.php.</h2>';
    } else {
        echo '<h2 style="color:red;font-family:sans-serif">Errore</h2><pre>' . htmlspecialchars(json_encode($token, JSON_PRETTY_PRINT)) . '</pre>';
    }
    exit();
}

// Genera state casuale e salvalo in sessione prima del redirect
session_start();
$state = bin2hex(random_bytes(16));
$_SESSION['oauth_state'] = $state;

$params = http_build_query([
    'client_id'     => $CLIENT_ID,
    'redirect_uri'  => $REDIRECT_URI,
    'response_type' => 'code',
    // SOLO business.manage: è la chiave del fix (niente bundle con Calendar/Gmail).
    'scope'         => 'https://www.googleapis.com/auth/business.manage',
    'access_type'   => 'offline',
    'prompt'        => 'consent',
    'state'         => $state,
]);
header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . $params);
exit();
