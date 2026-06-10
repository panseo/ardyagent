<?php
// -----------------------------------------------------------
// ARDY LAB — Guard di autenticazione (difesa in profondità)
//
// Gli endpoint dell'area riservata sono protetti da Basic Auth via .htaccess.
// Questo guard è un SECONDO livello: se il Basic Auth non venisse applicato
// (deploy errato, blocco FilesMatch non combaciante, file servito da altra
// path) gli endpoint che inviano email in massa o cancellano dati resterebbero
// aperti a chiunque. Qui verifichiamo che una richiesta effettivamente
// autenticata sia arrivata fino a PHP: quando Apache applica il Basic Auth
// popola REMOTE_USER (e di norma PHP_AUTH_USER); se nulla di tutto ciò è
// presente, la protezione a monte non ha agito e rifiutiamo.
// -----------------------------------------------------------

/** Ritorna lo username autenticato via Basic Auth, o '' se assente. */
function ardyAuthUser(): string {
    foreach (['PHP_AUTH_USER', 'REMOTE_USER', 'REDIRECT_REMOTE_USER'] as $k) {
        if (!empty($_SERVER[$k])) {
            return (string) $_SERVER[$k];
        }
    }
    // Alcuni setup FastCGI non popolano le variabili sopra: leggi l'header grezzo.
    foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $k) {
        if (!empty($_SERVER[$k]) && stripos($_SERVER[$k], 'Basic ') === 0) {
            $dec = base64_decode(substr($_SERVER[$k], 6), true);
            if ($dec !== false && strpos($dec, ':') !== false) {
                return (string) strstr($dec, ':', true);
            }
        }
    }
    return '';
}

/**
 * Blocca la richiesta con 401 se non risulta autenticata via Basic Auth.
 * Escape hatch: se l'hosting non passa l'utente a PHP ma il Basic Auth è
 * comunque garantito a monte, si può disattivare definendo
 *   define('ARDY_SKIP_AUTH_GUARD', true);
 * in ardy-config.php (non versionato). Default: enforce.
 */
function ardyRequireAuth(): void {
    if (defined('ARDY_SKIP_AUTH_GUARD') && ARDY_SKIP_AUTH_GUARD === true) {
        return;
    }
    if (ardyAuthUser() === '') {
        http_response_code(401);
        header('WWW-Authenticate: Basic realm="Area riservata Ardy Lab"');
        if (!headers_sent()) {
            header('Content-Type: application/json');
        }
        echo json_encode(['error' => 'Autenticazione richiesta']);
        exit;
    }
}
