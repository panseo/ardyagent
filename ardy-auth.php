<?php
// -----------------------------------------------------------
// ARDY LAB — Guard di autenticazione (difesa in profondità)
//
// Gli endpoint dell'area riservata sono protetti da Basic Auth via .htaccess.
// Questo guard è un SECONDO livello: se il Basic Auth non venisse applicato
// (deploy errato, blocco FilesMatch non combaciante, file servito da altra
// path) gli endpoint che inviano email in massa o cancellano dati resterebbero
// aperti a chiunque.
//
// IMPORTANTE: la sola presenza di PHP_AUTH_USER/PHP_AUTH_PW NON prova che
// Apache abbia validato nulla — PHP li popola da qualunque header
// "Authorization: Basic ..." il client invii, anche quando il file non è
// coperto dal blocco Basic Auth di .htaccess. Per essere un secondo livello
// reale, quando abbiamo anche la password la verifichiamo qui davvero contro
// .htpasswd (bcrypt, generato da ardy-setup-login.php). Solo quando la
// richiesta arriva già autenticata da Apache (REMOTE_USER, password non
// disponibile a PHP) ci fidiamo della sola presenza, perché in quel caso la
// verifica l'ha già fatta Apache stesso.
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
 * Ritorna la password Basic Auth se disponibile insieme allo username già
 * risolto da ardyAuthUser(), altrimenti null. null significa "nessuna
 * password da verificare qui" — o perché non è stata inviata alcuna
 * credenziale, o perché l'utente risulta da REMOTE_USER/REDIRECT_REMOTE_USER,
 * cioè da un Basic Auth che Apache ha già validato a monte.
 */
function ardyAuthPassword(): ?string {
    if (!empty($_SERVER['PHP_AUTH_USER'])) {
        return (string) ($_SERVER['PHP_AUTH_PW'] ?? '');
    }
    if (!empty($_SERVER['REMOTE_USER']) || !empty($_SERVER['REDIRECT_REMOTE_USER'])) {
        return null;
    }
    foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $k) {
        if (!empty($_SERVER[$k]) && stripos($_SERVER[$k], 'Basic ') === 0) {
            $dec = base64_decode(substr($_SERVER[$k], 6), true);
            if ($dec !== false && strpos($dec, ':') !== false) {
                return (string) substr($dec, strpos($dec, ':') + 1);
            }
        }
    }
    return null;
}

/**
 * Verifica utente+password contro .htpasswd (bcrypt, generato da
 * ardy-setup-login.php — stesso path relativo di quel file).
 *
 * Ritorna:
 *   true  → credenziali corrette
 *   false → utente assente dal file, o password errata
 *   null  → impossibile verificare (file .htpasswd assente/illeggibile):
 *           il chiamante deve ricadere sul solo controllo di presenza, per
 *           non rischiare un lockout in produzione per un problema di
 *           permessi sul file invece che per una reale mancanza di auth.
 */
function ardyVerifyHtpasswd(string $user, string $pass): ?bool {
    static $lines = null;
    if ($lines === null) {
        $path  = __DIR__ . '/.htpasswd';
        $lines = is_readable($path) ? file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : false;
    }
    if ($lines === false) {
        return null;
    }
    foreach ($lines as $line) {
        $pos = strpos($line, ':');
        if ($pos === false) {
            continue;
        }
        if (substr($line, 0, $pos) === $user) {
            return password_verify($pass, substr($line, $pos + 1));
        }
    }
    return false;
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
    $user = ardyAuthUser();
    if ($user === '') {
        ardyDenyAuth();
    }
    $pass = ardyAuthPassword();
    if ($pass !== null) {
        $valid = ardyVerifyHtpasswd($user, $pass);
        if ($valid === false) {
            ardyDenyAuth();
        }
        // $valid === true → password verificata.
        // $valid === null → .htpasswd non leggibile da PHP: non possiamo
        // verificare, ci fidiamo della sola presenza (comportamento storico).
    }
    // $pass === null → REMOTE_USER: Apache ha già autenticato la richiesta.
}

/** Risponde 401 e termina. Estratta per evitare duplicazione in ardyRequireAuth(). */
function ardyDenyAuth(): void {
    http_response_code(401);
    header('WWW-Authenticate: Basic realm="Area riservata Ardy Lab"');
    if (!headers_sent()) {
        header('Content-Type: application/json');
    }
    echo json_encode(['error' => 'Autenticazione richiesta']);
    exit;
}
