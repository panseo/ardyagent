<?php
// -----------------------------------------------------------
// ARDY LAB — Google Business Profile Helper (Local Posts)
// -----------------------------------------------------------
// Pubblica un "post" (localPost) sulla scheda Google Business
// "Ardy di Michela Panella" quando viene completata una fase di
// lavorazione.
//
// Token DEDICATO (ardy-gbp-token.json): NON riusa più il token di
// Calendar/Gmail. Quel token, con lo scope `business.manage` messo in
// bundle con Calendar/Gmail su un'app non verificata, non lo otteneva
// davvero (403 HTML al front-end). Un consenso DEDICATO al solo
// `business.manage` (ardy-gbp-auth.php) lo concede correttamente —
// verificato con OAuth Playground: stessa chiamata → 200. Il client
// OAuth è ARDY_GBP_CLIENT_* se definito in ardy-config.php, altrimenti
// fallback su ARDY_GCAL_CLIENT_*.
//
// ⚠️ L'endpoint dei post vive ancora sull'host LEGACY
//    https://mybusiness.googleapis.com/v4 (le API "nuove" v1
//    coprono solo account/location, non i post).
// -----------------------------------------------------------

require_once __DIR__ . '/ardy-config.php';

// Cache di account/location: risolverli costa 2 chiamate API, le
// facciamo una volta sola e salviamo il risultato (id stabili).
if (!defined('GBP_LOCATION_CACHE')) {
    define('GBP_LOCATION_CACHE', __DIR__ . '/ardy-gbp-location.json');
}
if (!defined('GBP_TOKEN_FILE')) {
    define('GBP_TOKEN_FILE', __DIR__ . '/ardy-gbp-token.json');
}

// Credenziali del client OAuth per Business Profile. Default: le stesse
// del client "social" già usato (che ha lo scope business.manage e il
// redirect ardy-gbp-auth.php registrati). Override in ardy-config.php.
function gbp_client_id(): string {
    return defined('ARDY_GBP_CLIENT_ID') ? ARDY_GBP_CLIENT_ID : ARDY_GCAL_CLIENT_ID;
}
function gbp_client_secret(): string {
    return defined('ARDY_GBP_CLIENT_SECRET') ? ARDY_GBP_CLIENT_SECRET : ARDY_GCAL_CLIENT_SECRET;
}

// -----------------------------------------------------------
// Token DEDICATO business.manage (ardy-gbp-token.json): ritorna un
// access_token valido, rinnovandolo col refresh_token se scaduto. Il
// file lo crea ardy-gbp-auth.php dopo il consenso una-tantum.
// -----------------------------------------------------------
function gbp_get_access_token(): ?string {
    if (!is_file(GBP_TOKEN_FILE)) {
        error_log('ARDY GBP: token file non trovato — autorizza da ardy-gbp-auth.php');
        return null;
    }
    $token = json_decode((string)@file_get_contents(GBP_TOKEN_FILE), true);
    if (!$token) {
        error_log('ARDY GBP: token file non valido');
        return null;
    }

    $expiresAt = ($token['created_at'] ?? 0) + ($token['expires_in'] ?? 3600) - 60;
    if (time() < $expiresAt && !empty($token['access_token'])) {
        return $token['access_token'];
    }

    if (empty($token['refresh_token'])) {
        error_log('ARDY GBP: no refresh_token — ri-autorizza da ardy-gbp-auth.php');
        return null;
    }

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt($ch, CURLOPT_POST,           true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT,        15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'client_id'     => gbp_client_id(),
        'client_secret' => gbp_client_secret(),
        'refresh_token' => $token['refresh_token'],
        'grant_type'    => 'refresh_token',
    ]));
    $response = curl_exec($ch);
    $err      = curl_error($ch);
    curl_close($ch);
    if ($err) {
        error_log('ARDY GBP: errore rinnovo token: ' . $err);
        return null;
    }

    $newToken = json_decode((string)$response, true);
    if (empty($newToken['access_token'])) {
        error_log('ARDY GBP: rinnovo fallito: ' . substr((string)$response, 0, 300));
        return null;
    }
    // Google non re-invia il refresh_token ad ogni giro: preservalo.
    $newToken['refresh_token'] = $token['refresh_token'];
    $newToken['created_at']    = time();
    @file_put_contents(GBP_TOKEN_FILE, json_encode($newToken, JSON_PRETTY_PRINT));
    return $newToken['access_token'];
}

// -----------------------------------------------------------
// Piccola GET autenticata, ritorna [httpCode, arrayDecodificato]
// -----------------------------------------------------------
function gbp_api_get(string $url, string $token): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT,        30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER,     ['Authorization: Bearer ' . $token]);
    // Forza IPv4: l'egress IPv6 del server (OVH 2001:41d0…) viene respinto a
    // monte dall'API Business (privata), mentre su IPv4 — come il Playground —
    // risponde 200. Diagnosticato in ardy-gbp-check.php (quarto tentativo).
    if (defined('CURL_IPRESOLVE_V4')) { curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4); }
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($err) { error_log('ARDY GBP GET curl: ' . $err); return [0, null]; }
    return [$code, json_decode((string)$resp, true)];
}

// -----------------------------------------------------------
// Risolve "accounts/{id}/locations/{id}" (parent dei localPost).
// Override possibile da config: GBP_PARENT (es. "accounts/1/locations/2").
// Altrimenti usa la cache; altrimenti interroga le API v1 e cache-a.
// Ritorna la stringa parent oppure null.
// -----------------------------------------------------------
function gbp_get_parent(string $token, bool $forceRefresh = false): ?string {
    if (defined('GBP_PARENT') && GBP_PARENT !== '') {
        return GBP_PARENT;
    }

    if (!$forceRefresh && is_file(GBP_LOCATION_CACHE)) {
        $cached = json_decode((string)@file_get_contents(GBP_LOCATION_CACHE), true);
        if (!empty($cached['parent'])) return $cached['parent'];
    }

    // 1) Account: prima scheda gestita dall'account.
    [$ac, $accounts] = gbp_api_get(
        'https://mybusinessaccountmanagement.googleapis.com/v1/accounts', $token
    );
    if ($ac !== 200 || empty($accounts['accounts'][0]['name'])) {
        error_log('ARDY GBP: accounts non risolti (http ' . $ac . ')');
        return null;
    }
    $accountName = $accounts['accounts'][0]['name']; // "accounts/123"

    // 2) Location: prima sede dell'account.
    [$lc, $locations] = gbp_api_get(
        'https://mybusinessbusinessinformation.googleapis.com/v1/' .
        rawurlencode($accountName) . '/locations?readMask=name,title&pageSize=1',
        $token
    );
    // rawurlencode rovinerebbe lo slash: lo ricostruiamo a mano.
    if ($lc !== 200) {
        [$lc, $locations] = gbp_api_get(
            'https://mybusinessbusinessinformation.googleapis.com/v1/' .
            $accountName . '/locations?readMask=name,title&pageSize=1',
            $token
        );
    }
    if ($lc !== 200 || empty($locations['locations'][0]['name'])) {
        error_log('ARDY GBP: locations non risolte (http ' . $lc . ')');
        return null;
    }
    $locationName = $locations['locations'][0]['name']; // "locations/456"

    // parent per i localPost v4: "accounts/{id}/locations/{id}"
    $accId = preg_replace('#^accounts/#',  '', $accountName);
    $locId = preg_replace('#^locations/#', '', $locationName);
    $parent = "accounts/{$accId}/locations/{$locId}";

    @file_put_contents(GBP_LOCATION_CACHE, json_encode([
        'account'     => $accountName,
        'location'    => $locationName,
        'parent'      => $parent,
        'resolved_at' => date('c'),
    ], JSON_PRETTY_PRINT));

    return $parent;
}

// -----------------------------------------------------------
// Crea un localPost STANDARD con testo (+ foto e link opzionali).
// Ritorna ['success'=>bool, 'http'=>int, 'name'=>..., 'error'=>...].
// -----------------------------------------------------------
function gbp_create_local_post(string $summary, string $imageUrl = '', string $ctaUrl = ''): array {
    $token = gbp_get_access_token();
    if (!$token) {
        return ['success' => false, 'http' => 0, 'error' => 'Nessun access token (autorizza da ardy-gbp-auth.php)'];
    }

    $parent = gbp_get_parent($token);
    if (!$parent) {
        return ['success' => false, 'http' => 0, 'error' => 'Account/location Business Profile non risolti (accesso API non ancora approvato?)'];
    }

    $summary = trim($summary);
    if ($summary === '') {
        return ['success' => false, 'http' => 0, 'error' => 'Testo del post mancante'];
    }
    // Limite Google per il testo dei localPost: 1500 caratteri.
    if (mb_strlen($summary) > 1500) {
        $summary = mb_substr($summary, 0, 1497) . '…';
    }

    $post = [
        'languageCode' => 'it',
        'summary'      => $summary,
        'topicType'    => 'STANDARD',
    ];
    if ($ctaUrl !== '' && filter_var($ctaUrl, FILTER_VALIDATE_URL)) {
        $post['callToAction'] = ['actionType' => 'LEARN_MORE', 'url' => $ctaUrl];
    }
    if ($imageUrl !== '' && filter_var($imageUrl, FILTER_VALIDATE_URL)) {
        // sourceUrl deve essere un URL pubblicamente raggiungibile (lo sono: ardy-lab.it).
        $post['media'] = [['mediaFormat' => 'PHOTO', 'sourceUrl' => $imageUrl]];
    }

    $url = 'https://mybusiness.googleapis.com/v4/' . $parent . '/localPosts';
    $ch  = curl_init($url);
    curl_setopt($ch, CURLOPT_POST,           true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT,        30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    // Forza IPv4 (vedi gbp_api_get): l'API Business rifiuta l'egress IPv6 OVH.
    if (defined('CURL_IPRESOLVE_V4')) { curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4); }
    curl_setopt($ch, CURLOPT_POSTFIELDS,     json_encode($post));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json',
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) {
        error_log('ARDY GBP POST curl: ' . $err);
        return ['success' => false, 'http' => 0, 'error' => 'Errore di rete: ' . $err];
    }

    $data = json_decode((string)$resp, true);
    if ($code === 200 && !empty($data['name'])) {
        error_log('ARDY GBP POST ok: ' . $data['name']);
        return ['success' => true, 'http' => 200, 'name' => $data['name']];
    }

    $msg = $data['error']['message'] ?? substr((string)$resp, 0, 300);
    error_log('ARDY GBP POST fail: http=' . $code . ' msg=' . $msg);
    return ['success' => false, 'http' => $code, 'error' => $msg];
}
