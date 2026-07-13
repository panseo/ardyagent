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
// Difesa in profondità: se il Basic Auth a monte (.htaccess) non venisse
// applicato, questo guard rifiuta comunque le richieste non autenticate.
require_once __DIR__ . '/ardy-auth.php';
ardyRequireAuth();

$input       = json_decode(file_get_contents('php://input'), true);
$testoSocial = trim($input['testo_social'] ?? '');
$fase        = $input['fase']      ?? '';
$mobile      = $input['mobile']    ?? '';
$postLink    = $input['post_link'] ?? '';
$immagini    = $input['immagini']  ?? [];
$cliente     = $input['cliente']   ?? '';
$testo       = $input['testo']     ?? $testoSocial;

// Piattaforme di destinazione: si può pubblicare anche su un solo social.
// Whitelist + default a entrambi se non specificato (retro-compatibilità).
$VALIDE      = ['facebook', 'instagram'];
$piattaforme = $input['piattaforme'] ?? [];
if (!is_array($piattaforme)) $piattaforme = [];
$piattaforme = array_values(array_intersect(
    array_map('strtolower', array_map('strval', $piattaforme)),
    $VALIDE
));
if (!$piattaforme) $piattaforme = $VALIDE; // nessuna indicazione valida => entrambi

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
    'piattaforme'  => $piattaforme,            // es. ["facebook"] | ["instagram"] | entrambi
    'facebook'     => in_array('facebook',  $piattaforme, true),
    'instagram'    => in_array('instagram', $piattaforme, true),
];

$ch = curl_init($webhookUrl);
curl_setopt($ch, CURLOPT_POST,           true);
curl_setopt($ch, CURLOPT_POSTFIELDS,     json_encode($webhookData));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT,        30);   // il workflow ora risponde a FINE pubblicazione
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

// HTTP 200 dal webhook NON basta: n8n può rispondere 200 anche quando Graph API
// rifiuta la pubblicazione (o quando non pubblica nulla). Interpretiamo il CORPO
// per confermare l'esito reale ed evitare il "semaforo verde" ingannevole.
$esito = ardySocialInterpretaEsito($res, $piattaforme);
error_log('ARDY SOCIAL PUBLISH ESITO: ' . $esito['stato'] . ' http=' . $httpCode
    . ' confermate=' . implode(',', $esito['piattaforme'])
    . ' body=' . substr((string) $res, 0, 500));

if ($esito['stato'] === 'fail') {
    echo json_encode(['success' => false, 'error' => $esito['error'] ?: 'I social non hanno confermato la pubblicazione']);
    exit();
}

echo json_encode([
    'success'     => true,
    'piattaforme' => $esito['piattaforme'] ?: $piattaforme,
    // false = inviato ma il workflow non ha confermato l'esito (200 "muto"): la dash
    // lo segnala come da verificare invece di dare un verde pieno.
    'confermato'  => ($esito['stato'] === 'ok'),
]);

/**
 * Interpreta la risposta del webhook n8n. Distingue tre stati:
 *   'ok'      → il workflow conferma la pubblicazione per le piattaforme richieste
 *   'unknown' → 200 senza segnali riconoscibili: inviato, esito non confermabile
 *   'fail'    → segnale esplicito di errore (dal workflow o da Graph API)
 * Conservativo di proposito: fallisce SOLO su un segnale negativo esplicito, così
 * non trasforma in errore i successi reali finché il workflow n8n non espone
 * l'esito per-piattaforma (vedi ardy-pubblica-social-n8n.md).
 */
function ardySocialInterpretaEsito($raw, array $richieste): array {
    $body = json_decode((string) $raw, true);
    // n8n a volte incapsula la risposta in [ {json:{...}} ] o [ {...} ].
    if (is_array($body) && $body && array_keys($body) === range(0, count($body) - 1)) {
        $body = $body[0] ?? null;
        if (is_array($body) && isset($body['json']) && is_array($body['json'])) $body = $body['json'];
    }
    if (!is_array($body)) {
        return ['stato' => 'unknown', 'error' => '', 'piattaforme' => []]; // corpo vuoto/non-JSON
    }

    $errMsg = ardySocialEstraiErrore($body);
    if ($errMsg !== '') return ['stato' => 'fail', 'error' => $errMsg, 'piattaforme' => []];

    if (array_key_exists('ok', $body) && !in_array($body['ok'], [true, 'true', 1, '1'], true)) {
        return ['stato' => 'fail', 'error' => 'I social hanno rifiutato la pubblicazione', 'piattaforme' => []];
    }

    // Dettaglio per-piattaforma, se il workflow lo espone.
    $pub = $body['pubblicato'] ?? $body['published'] ?? null;
    if (is_array($pub)) {
        $confermate = [];
        foreach ($richieste as $p) {
            $v = $pub[$p] ?? null;
            if ($v === 'ok' || $v === true || (is_array($v) && $v && !isset($v['error']))) $confermate[] = $p;
        }
        if (!$confermate) return ['stato' => 'fail', 'error' => 'Nessun social ha confermato la pubblicazione', 'piattaforme' => []];
        return ['stato' => 'ok', 'error' => '', 'piattaforme' => $confermate];
    }

    if (array_key_exists('ok', $body) && in_array($body['ok'], [true, 'true', 1, '1'], true)) {
        return ['stato' => 'ok', 'error' => '', 'piattaforme' => $richieste]; // ok esplicito, senza dettaglio
    }

    return ['stato' => 'unknown', 'error' => '', 'piattaforme' => []]; // JSON senza segnali → nessuna regressione
}

/** Estrae un messaggio d'errore da una risposta Graph API / workflow, '' se assente. */
function ardySocialEstraiErrore($body): string {
    if (!is_array($body)) return '';
    if (isset($body['error'])) {
        $e = $body['error'];
        if (is_array($e))  return 'Social: ' . (string) ($e['message'] ?? ($e['error_user_msg'] ?? 'errore Graph API'));
        if (is_string($e) && $e !== '') return 'Social: ' . $e;
    }
    if (isset($body['errors']) && is_array($body['errors']) && $body['errors']) {
        $first = reset($body['errors']);
        if (is_array($first))  return 'Social: ' . (string) ($first['message'] ?? 'errore');
        if (is_string($first)) return 'Social: ' . $first;
    }
    return '';
}
