<?php
// -----------------------------------------------------------
// ARDY LAB — Pubblica un post sulla scheda Google Business
// (passo separato e manuale, come per i social: vedi
//  ardy-pubblica-social.php). Chiamato dalla dashboard quando
//  Michela/Andrea decidono di pubblicare l'aggiornamento di una
//  fase anche sul profilo Google "Ardy di Michela Panella".
//
// Input JSON (POST):
//   testo      (string, obbligatorio)  — testo del post
//   immagini   (array URL) | immagine (string URL) — opzionale, usa la prima
//   post_link  (string URL) — opzionale, bottone "Scopri di più" → pagina lavorazione
//   fase, mobile, cliente — opzionali, solo per log
//
// Risposta: { success: bool, name?: string, error?: string }
// -----------------------------------------------------------

date_default_timezone_set('Europe/Rome');

header('Access-Control-Allow-Origin: https://ardyagent.ardy-lab.it');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }
// Difesa in profondità: se il Basic Auth a monte (.htaccess) non venisse
// applicato, questo guard rifiuta comunque le richieste non autenticate.
require_once __DIR__ . '/ardy-auth.php';
ardyRequireAuth();

require_once __DIR__ . '/ardy-gbp.php';

$input    = json_decode(file_get_contents('php://input'), true) ?: [];
$testo    = trim((string)($input['testo'] ?? $input['testo_social'] ?? ''));
$postLink = trim((string)($input['post_link'] ?? ''));
$fase     = (string)($input['fase']   ?? '');
$mobile   = (string)($input['mobile'] ?? '');

// Immagine: accetta sia immagini[] (come ardy-pubblica-social) sia immagine singola.
$immagine = '';
if (!empty($input['immagini']) && is_array($input['immagini'])) {
    $immagine = trim((string)($input['immagini'][0] ?? ''));
} elseif (!empty($input['immagine'])) {
    $immagine = trim((string)$input['immagine']);
}

if ($testo === '') {
    echo json_encode(['success' => false, 'error' => 'Testo del post mancante']);
    exit();
}

$res = gbp_create_local_post($testo, $immagine, $postLink);

if ($res['success']) {
    error_log('ARDY GBP POST pubblicato — fase=' . $fase . ' mobile=' . $mobile);
    echo json_encode(['success' => true, 'name' => $res['name']]);
} else {
    // Messaggio leggibile lato dashboard; il dettaglio tecnico è già nel log.
    $err = $res['error'] ?? 'Errore sconosciuto';
    if (in_array($res['http'], [401, 403, 429], true)) {
        $err = 'Accesso alla Google Business Profile API non ancora attivo '
             . '(in attesa di approvazione Google). Riprova dopo lo sblocco.';
    } elseif ($res['http'] >= 500) {
        // "Internal error encountered." è tutto ciò che Google dice su un 500, e non è
        // colpa del testo: di solito è la scheda che non può ospitare post, o la foto
        // che l'API non riesce a prendere. Mandiamo l'utente dove si scopre quale dei due.
        $err = 'Google ha risposto «' . $err . '» (HTTP ' . $res['http'] . '). Il post non è partito. '
             . 'Apri ardy-gbp-check.php → sezione «Post locali» per vedere se la scheda accetta i post '
             . 'e se l\'immagine è raggiungibile.';
    }
    echo json_encode(['success' => false, 'error' => $err, 'http' => $res['http'] ?? 0]);
}
