<?php
// -----------------------------------------------------------
// ARDY LAB — Diagnostica Google Business Profile API
// -----------------------------------------------------------
// Strumento di verifica MANUALE (dietro Basic Auth via .htaccess):
// fa una chiamata reale alla Business Profile API e dice in modo
// inequivocabile se la quota è stata sbloccata, oppure cosa manca.
//
// Uso: aprire https://ardyagent.ardy-lab.it/ardy-gbp-check.php nel browser.
//
// Usa il token DEDICATO Business Profile (ardy-gbp-token.json), con il
// solo scope `business.manage`. Se manca, autorizzare da ardy-gbp-auth.php.
// -----------------------------------------------------------

require_once __DIR__ . '/ardy-config.php';
require_once __DIR__ . '/ardy-gbp.php';    // per gbp_get_access_token() (token dedicato)

// Difesa in profondità: se il Basic Auth a monte (.htaccess) non venisse
// applicato, questo guard rifiuta comunque le richieste non autenticate.
require_once __DIR__ . '/ardy-auth.php';
ardyRequireAuth();

header('Content-Type: text/html; charset=utf-8');

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Endpoint "leggero": elenca gli account Business Profile collegati.
// È la chiamata più semplice che attraversa il gate di quota.
$API_URL = 'https://mybusinessaccountmanagement.googleapis.com/v1/accounts';

$token = gbp_get_access_token();

echo "<!doctype html><html lang='it'><head><meta charset='utf-8'>";
echo "<title>Ardy — Diagnostica Google Business Profile API</title>";
echo "<style>body{font-family:system-ui,sans-serif;max-width:760px;margin:40px auto;padding:0 16px;line-height:1.5;color:#222}"
   . "h1{font-size:20px}.box{border-radius:10px;padding:16px 18px;margin:16px 0}"
   . ".ok{background:#e7f6e9;border:1px solid #34a853}.warn{background:#fff4e5;border:1px solid #f9ab00}"
   . ".err{background:#fdecea;border:1px solid #ea4335}.muted{color:#666;font-size:13px}"
   . "pre{background:#f5f5f5;padding:12px;border-radius:8px;overflow:auto;font-size:12px}</style></head><body>";
echo "<h1>🔎 Diagnostica Business Profile API</h1>";
echo "<p class='muted'>Controllo eseguito il " . date('d/m/Y H:i:s') . " (Europe/Rome)</p>";

if (!$token) {
    echo "<div class='box err'><b>❌ Nessun access token disponibile.</b><br>"
       . "Il file <code>ardy-gbp-token.json</code> manca o è scaduto senza refresh_token. "
       . "Autorizza da <code>ardy-gbp-auth.php</code>.</div></body></html>";
    exit;
}

$ch = curl_init($API_URL);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT,        30);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER,     ['Authorization: Bearer ' . $token]);
curl_setopt($ch, CURLOPT_HEADER,         true); // includi header nella risposta
// Esce su IPv4 come fa la libreria vera (gbp_api_get / POST dei localPost): l'egress
// IPv6 del server OVH viene respinto dall'API Business con un 403 HTML. Finché questa
// pagina usava il default, mostrava un 403 che l'applicazione non incontra più — e si
// fermava lì, senza arrivare a diagnosticare i problemi veri (vedi "Post locali").
// L'uscita IPv6 resta comunque provata più sotto, per non perdere quella conoscenza.
if (defined('CURL_IPRESOLVE_V4')) { curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4); }
$raw      = curl_exec($ch);
$err      = curl_error($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$hdrSize  = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$primaryIp = (string)curl_getinfo($ch, CURLINFO_PRIMARY_IP);   // IP di Google contattato
$localIp   = (string)curl_getinfo($ch, CURLINFO_LOCAL_IP);     // IP/egress della richiesta
$httpVer   = (int)curl_getinfo($ch, CURLINFO_HTTP_VERSION);    // 2=HTTP/2, 3=1.1...
curl_close($ch);

$rawHeaders = substr((string)$raw, 0, $hdrSize);
$resp       = substr((string)$raw, $hdrSize);

if ($err) {
    echo "<div class='box err'><b>❌ Errore di rete:</b> " . h($err) . "</div></body></html>";
    exit;
}

$data   = json_decode($resp, true);
$errObj = $data['error'] ?? null;
$status = $errObj['status']  ?? '';
$reason = $errObj['errors'][0]['reason'] ?? ($errObj['details'][0]['reason'] ?? '');
$msg    = $errObj['message'] ?? '';

error_log("ARDY GBP CHECK: HTTP $httpCode status=$status reason=$reason");

// --- Interpretazione ---------------------------------------------------
if ($httpCode === 200) {
    $n = isset($data['accounts']) ? count($data['accounts']) : 0;
    echo "<div class='box ok'><b>✅ L'API risponde (uscendo su IPv4).</b><br>"
       . "La Business Profile API è accessibile con questo progetto: accesso concesso, token buono, "
       . "quota &gt; 0. Account collegati trovati: <b>$n</b>.<br>"
       . "<span class='muted'>Vale per leggere account e schede. Se <i>pubblicare</i> fallisce lo stesso, "
       . "la causa sta nella sezione «Post locali» qui sotto: non è né l'accesso né il token.</span></div>";
} elseif ($httpCode === 401) {
    echo "<div class='box err'><b>❌ Token non valido / scaduto (401).</b><br>"
       . "Ri-autorizza da <code>ardy-gbp-auth.php</code>.</div>";
} elseif ($httpCode === 403 && (stripos($status, 'PERMISSION_DENIED') !== false || stripos($reason, 'SCOPE') !== false)
          && stripos($msg, 'scope') !== false) {
    echo "<div class='box warn'><b>⚠️ Manca lo scope <code>business.manage</code>.</b><br>"
       . "Il token dedicato non include lo scope giusto. "
       . "Ri-autorizza da <code>ardy-gbp-auth.php</code> (consenso a business.manage) "
       . "per ottenere un nuovo token, poi rilancia questo check.</div>";
} elseif ($httpCode === 429 || stripos($status, 'RESOURCE_EXHAUSTED') !== false) {
    echo "<div class='box err'><b>⛔ QUOTA ANCORA A ZERO / esaurita (429).</b><br>"
       . "La richiesta di aumento quota <b>non è ancora stata approvata</b> "
       . "(o il limite è 0). Controlla il valore in Cloud Console → "
       . "<i>Business Profile API → Quotas</i> e, se fermo da troppo, ri-sottometti il form di accesso.</div>";
} elseif ($httpCode === 403 && (stripos($resp, 'does not have permission to get URL') !== false
          || stripos($resp, 'Error 403 (Forbidden)') !== false)) {
    echo "<div class='box err'><b>⛔ ACCESSO ALLA BUSINESS PROFILE API NON CONCESSO.</b><br>"
       . "Risposta HTML generica del front-end Google (non un errore JSON dell'API): la "
       . "richiesta è respinta <b>prima</b> di arrivare al servizio. Significa che il progetto "
       . "Cloud <b>non è nell'allow-list</b> della Business Profile API → la <b>richiesta di "
       . "accesso non è ancora stata approvata</b> (o è stata inviata da/per un progetto diverso).<br><br>"
       . "<b>Da fare:</b> (1) verifica che il progetto del client OAuth qui sotto sia lo stesso dove "
       . "hai inviato il form di accesso; (2) controlla l'email dell'account (anche SPAM) per "
       . "richieste di Google rimaste senza risposta; (3) se sono passate &gt;2 settimane, "
       . "ri-sottometti il form o apri un ticket al supporto Business Profile.</div>";
} elseif ($httpCode === 403) {
    echo "<div class='box err'><b>⛔ Accesso negato (403).</b><br>"
       . "Tipicamente: API <b>non abilitata</b> nel progetto, oppure accesso/quota "
       . "<b>non ancora concessi</b>. Verifica in Cloud Console che la Business Profile API "
       . "(e My Business Account Management / Business Information) sia <i>Enabled</i> e con quota &gt; 0.</div>";
} else {
    echo "<div class='box warn'><b>Risposta inattesa (HTTP $httpCode).</b> Vedi il dettaglio sotto.</div>";
}

// --- Controprova IPv6: la ragione per cui tutto il codice GBP forza IPv4 ------
// Con la chiamata principale a 200 (IPv4) i "tentativi" storici qui sotto non
// partono più. Questa controprova conserva la conoscenza: rifà la stessa GET
// sull'egress di default e mostra che l'IPv6 del server resta respinto.
if ($httpCode === 200) {
    $ch6 = curl_init($API_URL);
    curl_setopt($ch6, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch6, CURLOPT_TIMEOUT,        20);
    curl_setopt($ch6, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch6, CURLOPT_HTTPHEADER,     ['Authorization: Bearer ' . $token]);
    curl_exec($ch6);
    $code6  = (int)curl_getinfo($ch6, CURLINFO_HTTP_CODE);
    $local6 = (string)curl_getinfo($ch6, CURLINFO_LOCAL_IP);
    curl_close($ch6);
    echo "<p class='muted'>Controprova sull'uscita di default (<code>" . h($local6 ?: '—') . "</code>): "
       . "HTTP <b>$code6</b>. " . ($code6 === 200
            ? "Ora passa anche da lì: il vincolo IPv4 non serve più (si può togliere da <code>ardy-gbp.php</code>)."
            : "Respinta, come da luglio — per questo <code>ardy-gbp.php</code> forza IPv4 su tutte le chiamate.")
       . "</p>";
}

// --- Diagnostica del TOKEN in uso -------------------------------------
// Il 403-HTML "does not have permission to get URL /v1/accounts" arriva dal
// front-end Google PRIMA del servizio. Con progetto allowlisted (verificato
// a 200 dal Playground) l'unica variabile residua è il TOKEN che il codice
// sta davvero usando. Qui lo smascheriamo: (a) cosa c'è salvato nel file,
// (b) cosa dice Google (tokeninfo) sugli scope realmente concessi e sul
// client (aud) dietro l'access_token. Serve a capire se ardy-gbp.php sta
// ancora servendo il vecchio token gcal (bytecode vecchio in OPcache).
echo "<h2 style='font-size:15px'>Diagnostica del token in uso</h2>";

$tokFile  = defined('GBP_TOKEN_FILE') ? GBP_TOKEN_FILE : (__DIR__ . '/ardy-gbp-token.json');
$stored   = is_file($tokFile) ? json_decode((string)@file_get_contents($tokFile), true) : null;
$storedScope = is_array($stored) ? ($stored['scope'] ?? '') : '';
$hasRefresh  = is_array($stored) && !empty($stored['refresh_token']);
$fileMtime   = is_file($tokFile) ? date('d/m/Y H:i:s', filemtime($tokFile)) : '—';

// tokeninfo: rivela scope realmente concessi + client (aud) dell'access_token.
[$tiCode, $tiData] = [0, null];
$tiCh = curl_init('https://oauth2.googleapis.com/tokeninfo?access_token=' . rawurlencode($token));
curl_setopt($tiCh, CURLOPT_RETURNTRANSFER, true);
curl_setopt($tiCh, CURLOPT_TIMEOUT,        15);
curl_setopt($tiCh, CURLOPT_SSL_VERIFYPEER, true);
$tiRaw  = curl_exec($tiCh);
$tiCode = (int)curl_getinfo($tiCh, CURLINFO_HTTP_CODE);
curl_close($tiCh);
$tiData = json_decode((string)$tiRaw, true);
$liveScope = is_array($tiData) ? ($tiData['scope'] ?? '') : '';
$liveAud   = is_array($tiData) ? ($tiData['aud'] ?? ($tiData['azp'] ?? '')) : '';
// email dell'account che ha dato il consenso (torna solo se il token ha lo
// scope openid/email — aggiunto in ardy-gbp-auth.php proprio per smascherare
// eventuali consensi partiti dall'account sbagliato).
$liveEmail = is_array($tiData) ? ($tiData['email'] ?? '') : '';
// Account allowlisted (nel Google Group). Solo questi passano il gate.
$allowedAccounts = ['ardy.documenti@gmail.com', 'a.panseo@gmail.com'];
$emailAllowed = $liveEmail !== '' && in_array(strtolower($liveEmail), $allowedAccounts, true);

$hasBusinessManage = stripos($liveScope, 'business.manage') !== false
                  || stripos($storedScope, 'business.manage') !== false;
$looksLikeGcal = (stripos($liveScope, 'calendar') !== false || stripos($liveScope, 'gmail') !== false)
              && stripos($liveScope, 'business.manage') === false;
$gbpClient = gbp_client_id();
$audMatchesGbp = $liveAud !== '' && $gbpClient !== '' && strpos($gbpClient, (string)$liveAud) === 0;

if ($looksLikeGcal) {
    echo "<div class='box err'><b>🎯 Token SBAGLIATO: è quello di Calendar/Gmail, non quello dedicato.</b><br>"
       . "Gli scope realmente concessi (sotto) contengono <code>calendar</code>/<code>gmail</code> ma "
       . "<b>non</b> <code>business.manage</code>. Significa che <code>ardy-gbp.php</code> sta ancora "
       . "eseguendo la versione VECCHIA (<code>return gcal_get_access_token()</code>): è <b>OPcache</b> "
       . "che serve bytecode vecchio. <b>Da fare sul server:</b> svuota PHP OPcache / riavvia PHP-FPM "
       . "(es. <code>systemctl reload php*-fpm</code>), poi rilancia questo check. Il file token è già "
       . "corretto — è solo il codice a non essere ricaricato.</div>";
} elseif (!$hasBusinessManage) {
    echo "<div class='box warn'><b>⚠️ Il token NON ha lo scope <code>business.manage</code>.</b><br>"
       . "Il consenso è partito senza concedere lo scope giusto (o con l'account sbagliato). "
       . "Ri-autorizza da <code>ardy-gbp-auth.php</code> scegliendo esplicitamente l'account che "
       . "gestisce la scheda (<code>ardy.documenti</code> o <code>a.panseo</code>) e verifica che nel "
       . "consenso compaia <b>“Google Business”</b>.</div>";
} elseif ($httpCode === 200) {
    echo "<div class='box ok'><b>✅ Token corretto e funzionante</b> — scope <code>business.manage</code> "
       . "concesso e l'API risponde 200.</div>";
} elseif ($liveEmail !== '' && !$emailAllowed) {
    echo "<div class='box err'><b>🎯 ACCOUNT SBAGLIATO: il token è di <code>" . h($liveEmail) . "</code>.</b><br>"
       . "Ha lo scope <code>business.manage</code> (per questo il consenso mostrava “Google Business”) ma "
       . "<b>questo account NON è nel Google Group allowlisted</b> (solo <code>ardy.documenti@gmail.com</code> "
       . "e <code>a.panseo@gmail.com</code> lo sono). Il client è lo stesso per qualunque utente, perciò "
       . "<code>aud</code> tornava giusto e traeva in inganno: il gate scatta sull'<b>account</b>, non sul "
       . "progetto. Ecco perché il Playground (dove hai loggato l'account giusto) dà 200 e il server 403.<br>"
       . "<b>Fix:</b> ri-autorizza da <code>ardy-gbp-auth.php</code> e nel <b>selettore account</b> scegli "
       . "esplicitamente <code>ardy.documenti@gmail.com</code>, poi rilancia questo check.</div>";
} elseif ($liveEmail === '') {
    echo "<div class='box warn'><b>Token con <code>business.manage</code> ma l'API risponde $httpCode — e non so di quale account è.</b><br>"
       . "Il token è stato creato <b>prima</b> di aggiungere lo scope <code>email</code>, quindi non posso "
       . "mostrarti l'account che l'ha autorizzato: è proprio il sospetto n.1 (consenso partito da un account "
       . "NON allowlisted, che il browser può aver auto-scelto). <b>Ri-autorizza da <code>ardy-gbp-auth.php</code></b> "
       . "(ora forza il selettore account e chiede anche <code>email</code>): scegli <code>ardy.documenti@gmail.com</code>, "
       . "poi torna qui — il box ti dirà nero su bianco l'account del token.</div>";
} else {
    // Account giusto+allowlisted, scope giusto, client giusto, non OPcache, non
    // quota project, header non strippato (gcal gira). Non resta nulla di
    // esprimibile via token: l'unica variabile è la RICHIESTA in uscita dal
    // server (PHP-curl vs IP/egress) rispetto al Playground. Un curl da shell,
    // stesso token, disambigua: 200 ⇒ è la config PHP-curl del server; 403 ⇒ è
    // l'IP/egress del server (bloccato a monte da Google per questa API).
    $shellCmd = "TOK=$(php -r 'echo json_decode(file_get_contents(\"" . $tokFile . "\"),true)[\"access_token\"] ?? \"\";')\n"
              . "curl -sS -o /dev/null -w '%{http_code}\\n' \\\n"
              . "  -H \"Authorization: Bearer \$TOK\" \\\n"
              . "  '" . $API_URL . "'";
    echo "<div class='box warn'><b>Token di <code>" . h($liveEmail) . "</code> (allowlisted) con <code>business.manage</code>, ma l'API risponde $httpCode.</b><br>"
       . "🔒 <b>Ogni ipotesi lato token è esclusa:</b> account giusto+allowlisted, scope giusto, client giusto, "
       . "non OPcache, non quota project, header non strippato (Calendar/Gmail girano con lo stesso <code>Bearer</code>). "
       . "Resta solo la <b>richiesta in uscita dal server</b> vs il Playground. <b>Test decisivo</b> — lancia questo "
       . "<b>dalla shell SSH del server</b> (legge il token dal file, niente da incollare):</div>";
    echo "<pre>" . h($shellCmd) . "</pre>";
    echo "<div class='box muted'>Come leggere l'esito:<br>"
       . "• stampa <b>200</b> → il problema è la <b>config PHP-curl</b> del server (es. <code>http_proxy</code>/"
       . "<code>https_proxy</code> nell'env di PHP-FPM, o una <code>curl.*</code> in <code>php.ini</code>): la "
       . "chiamata di per sé va, è PHP a instradarla male. Confronta l'env di FPM con quello della shell.<br>"
       . "• stampa <b>403</b> → è l'<b>IP/egress del server</b> ad essere respinto a monte da Google per questa "
       . "API: verifica lo stesso comando da un'altra macchina (deve dare 200 come il Playground) e, se confermato, "
       . "il canale giusto è Google col <b>public IP del server</b>, non più token/account.</div>";
}

echo "<table class='muted' style='border-collapse:collapse;width:100%;font-size:13px'>";
echo "<tr><td style='padding:4px 8px'>File token</td><td style='padding:4px 8px'><code>" . h(basename($tokFile)) . "</code>" . (is_file($tokFile) ? '' : ' <b>(MANCANTE)</b>') . "</td></tr>";
echo "<tr><td style='padding:4px 8px'>Ultima modifica file</td><td style='padding:4px 8px'>" . h($fileMtime) . "</td></tr>";
echo "<tr><td style='padding:4px 8px'>refresh_token salvato</td><td style='padding:4px 8px'>" . ($hasRefresh ? '✅ sì' : '❌ no') . "</td></tr>";
echo "<tr><td style='padding:4px 8px'>Scope salvato nel file</td><td style='padding:4px 8px'><code>" . h($storedScope ?: '(assente)') . "</code></td></tr>";
echo "<tr><td style='padding:4px 8px'>Scope concessi (tokeninfo)</td><td style='padding:4px 8px'><code>" . h($liveScope ?: ('(tokeninfo HTTP ' . $tiCode . ')')) . "</code></td></tr>";
echo "<tr><td style='padding:4px 8px'>Account del token (email)</td><td style='padding:4px 8px'>" . ($liveEmail !== '' ? ('<code>' . h($liveEmail) . '</code>' . ($emailAllowed ? ' ✅ allowlisted' : ' ⛔ NON allowlisted')) : '<i>ignoto — token creato prima dello scope email; ri-autorizza per vederlo</i>') . "</td></tr>";
echo "<tr><td style='padding:4px 8px'>Client dell'access_token (aud)</td><td style='padding:4px 8px'><code>" . h($liveAud ?: '—') . "</code>" . ($liveAud ? ($audMatchesGbp ? ' ✅ = client GBP' : ' ⚠️ ≠ client GBP configurato') : '') . "</td></tr>";
echo "<tr><td style='padding:4px 8px'>Client GBP configurato</td><td style='padding:4px 8px'><code>" . h($gbpClient) . "</code></td></tr>";
echo "</table>";

// --- Ambiente di rete PHP (proxy / curl) -------------------------------
// La richiesta ARRIVA a Google (il 403 ha gli header google: alt-svc, logo)
// ma il suo front-end la respinge, mentre lo STESSO token via oauth2/tokeninfo
// passa e dal Playground l'API dà 200. Se PHP-FPM ha un proxy in env, la
// richiesta esce da un IP/percorso diverso da quello che ci si aspetta: qui lo
// mostriamo, insieme all'IP di egress e alla versione HTTP effettiva.
$proxyEnv = [];
foreach (['http_proxy','https_proxy','HTTP_PROXY','HTTPS_PROXY','no_proxy','NO_PROXY','ALL_PROXY'] as $k) {
    $v = getenv($k);
    if ($v !== false && $v !== '') { $proxyEnv[$k] = $v; }
}
$curlVer = function_exists('curl_version') ? (curl_version()['version'] ?? '?') : '?';
$httpVerLabel = $httpVer === 3 ? 'HTTP/1.1' : ($httpVer === 2 ? 'HTTP/2' : ($httpVer === 30 ? 'HTTP/3' : ('code ' . $httpVer)));
echo "<h2 style='font-size:15px'>Ambiente di rete PHP</h2>";
if ($proxyEnv) {
    echo "<div class='box warn'><b>⚠️ Proxy configurato nell'ambiente PHP:</b> <code>"
       . h(implode('  ', array_map(fn($k,$v)=>"$k=$v", array_keys($proxyEnv), $proxyEnv))) . "</code>.<br>"
       . "Le chiamate curl escono attraverso questo proxy: se non è lo stesso percorso di Calendar/Gmail (o "
       . "tratta diversamente <code>mybusinessaccountmanagement.googleapis.com</code>), è un forte candidato "
       . "per il 403. Il test <code>curl</code> da shell (che NON eredita l'env di PHP-FPM) lo conferma: se da "
       . "shell dà 200, il colpevole è il proxy nell'env di FPM.</div>";
} else {
    echo "<div class='box muted'>Nessuna variabile proxy (<code>http_proxy</code>/<code>https_proxy</code>/…) "
       . "nell'ambiente di PHP: la richiesta non passa da un proxy noto a livello env.</div>";
}
echo "<table class='muted' style='border-collapse:collapse;width:100%;font-size:13px'>";
echo "<tr><td style='padding:4px 8px'>IP di egress (LOCAL_IP)</td><td style='padding:4px 8px'><code>" . h($localIp ?: '—') . "</code> ← è questo che Google vede come sorgente</td></tr>";
echo "<tr><td style='padding:4px 8px'>IP Google contattato</td><td style='padding:4px 8px'><code>" . h($primaryIp ?: '—') . "</code></td></tr>";
echo "<tr><td style='padding:4px 8px'>Versione HTTP negoziata</td><td style='padding:4px 8px'>" . h($httpVerLabel) . "</td></tr>";
echo "<tr><td style='padding:4px 8px'>Versione libcurl</td><td style='padding:4px 8px'><code>" . h($curlVer) . "</code></td></tr>";
echo "</table>";

// --- Secondo tentativo: X-Goog-User-Project ---------------------------
// Playground 200 vs server 403 con TOKEN IDENTICO ⇒ la differenza è nel
// transporto, non nel token. Il Playground allega automaticamente l'header
// `X-Goog-User-Project` (quota project); alcune API private di Google lo
// pretendono quando le chiami con credenziali utente e, senza, il front-end
// può respingere a monte con proprio l'HTML "does not have permission to
// get URL". Qui rifacciamo la STESSA chiamata aggiungendo quell'header e
// confrontiamo: se passa a 200, è quello il fix (va messo in gbp_api_get).
$projForHeader = preg_match('/^(\d+)-/', $gbpClient, $qm) ? $qm[1] : '';
if ($projForHeader !== '' && $httpCode !== 200) {
    echo "<h2 style='font-size:15px'>Secondo tentativo — con <code>X-Goog-User-Project</code></h2>";
    $ch2 = curl_init($API_URL);
    curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch2, CURLOPT_TIMEOUT,        30);
    curl_setopt($ch2, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch2, CURLOPT_HTTPHEADER,     [
        'Authorization: Bearer ' . $token,
        'X-Goog-User-Project: ' . $projForHeader,
    ]);
    $resp2 = curl_exec($ch2);
    $code2 = (int)curl_getinfo($ch2, CURLINFO_HTTP_CODE);
    $err2  = curl_error($ch2);
    curl_close($ch2);

    if ($err2) {
        echo "<div class='box warn'><b>Errore di rete sul secondo tentativo:</b> " . h($err2) . "</div>";
    } elseif ($code2 === 200) {
        echo "<div class='box ok'><b>🎯 TROVATO: con l'header <code>X-Goog-User-Project: "
           . h($projForHeader) . "</code> l'API risponde <b>200</b>.</b><br>"
           . "Era questa la differenza col Playground (che lo allega da solo). <b>Fix:</b> aggiungere "
           . "questo header a tutte le chiamate in <code>ardy-gbp.php</code> "
           . "(<code>gbp_api_get()</code> e il POST dei localPost). Fatto quello, il flusso è sbloccato.</div>";
    } else {
        echo "<div class='box muted'><b>Nessun cambiamento: HTTP $code2 anche con <code>X-Goog-User-Project</code>.</b><br>"
           . "Non è (solo) il quota project. Con token provatamente valido (business.manage, client giusto) "
           . "e questo header, il 403 resta ⇒ la richiesta dal server è respinta a monte per un motivo che "
           . "NON dipende dal token. Prossimo test decisivo: lanciare la stessa <code>curl -H 'Authorization: "
           . "Bearer &lt;token&gt;' " . h($API_URL) . "</code> (a) dalla shell del server e (b) da un'altra macchina "
           . "col MEDESIMO access_token, e confrontare: se (a) fallisce e (b) va, è l'IP/rete del server.</div>";
        echo "<pre>" . h(substr((string)$resp2, 0, 400)) . "</pre>";
    }
}

// --- Terzo tentativo: User-Agent + X-Goog-User-Project ----------------
// PHP-curl di DEFAULT non manda alcun `User-Agent`. Diversi backend privati
// di Google respingono a monte le richieste senza UA con proprio l'HTML
// "does not have permission to get URL". Playground e browser un UA ce
// l'hanno (Calendar/Gmail sono più permissive → per questo girano lo stesso).
// Qui rifacciamo la GET con un UA esplicito (+ quota project): se passa a 200,
// è un fix di codice pulito (CURLOPT_USERAGENT in gbp_api_get e nel POST).
if ($httpCode !== 200) {
    echo "<h2 style='font-size:15px'>Terzo tentativo — con <code>User-Agent</code></h2>";
    $hdr3 = ['Authorization: Bearer ' . $token];
    if ($projForHeader !== '') { $hdr3[] = 'X-Goog-User-Project: ' . $projForHeader; }
    $ch3 = curl_init($API_URL);
    curl_setopt($ch3, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch3, CURLOPT_TIMEOUT,        30);
    curl_setopt($ch3, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch3, CURLOPT_HTTPHEADER,     $hdr3);
    curl_setopt($ch3, CURLOPT_USERAGENT,      'ardyagent-gbp/1.0 (+https://ardyagent.ardy-lab.it)');
    $resp3 = curl_exec($ch3);
    $code3 = (int)curl_getinfo($ch3, CURLINFO_HTTP_CODE);
    $err3  = curl_error($ch3);
    curl_close($ch3);

    if ($err3) {
        echo "<div class='box warn'><b>Errore di rete sul terzo tentativo:</b> " . h($err3) . "</div>";
    } elseif ($code3 === 200) {
        echo "<div class='box ok'><b>🎯 TROVATO: con un <code>User-Agent</code> esplicito l'API risponde <b>200</b>.</b><br>"
           . "PHP-curl non ne mandava uno e il front-end Google respingeva a monte. <b>Fix:</b> aggiungere "
           . "<code>curl_setopt(\$ch, CURLOPT_USERAGENT, 'ardyagent-gbp/1.0');</code> a <code>gbp_api_get()</code> "
           . "e alla POST dei localPost in <code>ardy-gbp.php</code>. Fatto quello, il flusso è sbloccato.</div>";
    } else {
        echo "<div class='box muted'><b>Nessun cambiamento: HTTP $code3 anche con <code>User-Agent</code>.</b><br>"
           . "Nemmeno l'UA. A questo punto va escluso il livello PHP-curl col test da shell qui sopra.</div>";
        echo "<pre>" . h(substr((string)$resp3, 0, 400)) . "</pre>";
    }
}

// --- Quarto tentativo: forza IPv4 -------------------------------------
// L'"Ambiente di rete PHP" ha rivelato un egress IPv6 (2001:41d0… = OVH).
// L'API Business (privata/allowlisted) respinge l'egress IPv6 mentre gli
// endpoint pubblici (oauth2/tokeninfo, Calendar/Gmail) lo accettano — e il
// Playground gira su IPv4 di Google → 200. Qui rifacciamo la GET forzando
// IPv4: se passa a 200, è LA causa e il fix è CURL_IPRESOLVE_V4 su tutte le
// chiamate GBP (già applicato in ardy-gbp.php).
if ($httpCode !== 200 && defined('CURL_IPRESOLVE_V4')) {
    echo "<h2 style='font-size:15px'>Quarto tentativo — forza <code>IPv4</code></h2>";
    $ch4 = curl_init($API_URL);
    curl_setopt($ch4, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch4, CURLOPT_TIMEOUT,        30);
    curl_setopt($ch4, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch4, CURLOPT_HTTPHEADER,     ['Authorization: Bearer ' . $token]);
    curl_setopt($ch4, CURLOPT_IPRESOLVE,      CURL_IPRESOLVE_V4);
    $resp4     = curl_exec($ch4);
    $code4     = (int)curl_getinfo($ch4, CURLINFO_HTTP_CODE);
    $err4      = curl_error($ch4);
    $localIp4  = (string)curl_getinfo($ch4, CURLINFO_LOCAL_IP);
    curl_close($ch4);

    if ($err4) {
        echo "<div class='box warn'><b>Errore di rete sul quarto tentativo:</b> " . h($err4) . "</div>";
    } elseif ($code4 === 200) {
        echo "<div class='box ok'><b>🎯 CAUSA TROVATA: forzando <code>IPv4</code> l'API risponde <b>200</b>.</b><br>"
           . "Egress IPv4 <code>" . h($localIp4) . "</code> (contro l'IPv6 <code>2001:41d0…</code> di default). "
           . "L'API Business rifiutava l'uscita IPv6 del server OVH. <b>Fix già applicato in "
           . "<code>ardy-gbp.php</code></b> (<code>CURL_IPRESOLVE_V4</code> su <code>gbp_api_get()</code> e sulla "
           . "POST dei localPost): il flusso di pubblicazione ora esce su IPv4. Puoi riabilitare il toggle Google "
           . "e pubblicare una fase di test.</div>";
    } else {
        echo "<div class='box muted'><b>Nessun cambiamento: HTTP $code4 anche forzando IPv4</b> (egress "
           . "<code>" . h($localIp4 ?: '—') . "</code>).<br>Allora non è l'IPv6: resta il test <code>curl</code> "
           . "da shell (config PHP-curl vs IP) descritto sopra.</div>";
        echo "<pre>" . h(substr((string)$resp4, 0, 400)) . "</pre>";
    }
}

// --- Post locali: perché la POST fallisce anche se la GET va -----------------
// Serve quando gli account si leggono (200) ma pubblicare risponde 500 "Internal
// error encountered.", che è tutto quello che Google dice. Le due cause vere sono
// quasi sempre: la scheda non può ospitare post (metadata.canOperateLocalPost), o
// l'immagine non è prendibile/è troppo piccola. Qui si guardano entrambe, in sola
// lettura: niente viene pubblicato se non lo si chiede col bottone in fondo.
if ($httpCode === 200) {
    echo "<h2 style='font-size:15px'>Post locali (<code>localPosts</code>)</h2>";

    $parent = gbp_get_parent($token);
    echo "<p class='muted'>Destinazione dei post: <code>" . h($parent ?: '(non risolta)') . "</code></p>";

    // Tutte le schede di tutti gli account, con il flag che conta. Se le schede sono
    // più di una, quella scelta (la prima) potrebbe non essere quella giusta.
    $accountList = $data['accounts'] ?? [];
    foreach ($accountList as $acc) {
        $accName = (string)($acc['name'] ?? '');
        if ($accName === '') continue;
        [$lc, $locs] = gbp_api_get(
            'https://mybusinessbusinessinformation.googleapis.com/v1/' . $accName
            . '/locations?readMask=name,title,metadata&pageSize=20', $token
        );
        echo "<p class='muted'><b>" . h($accName) . "</b> — " . h((string)($acc['accountName'] ?? '')) . "</p>";
        if ($lc !== 200) { echo "<div class='box warn'>Schede non leggibili (HTTP $lc).</div>"; continue; }
        foreach (($locs['locations'] ?? []) as $loc) {
            $ln   = (string)($loc['name'] ?? '');
            $puo  = !empty($loc['metadata']['canOperateLocalPost']);
            $qui  = $parent && substr($parent, -strlen('/' . $ln)) === '/' . $ln;
            echo "<div class='box " . ($puo ? 'ok' : 'err') . "'>"
               . ($qui ? '➡️ ' : '') . "<b>" . h((string)($loc['title'] ?? '(senza nome)')) . "</b> "
               . "<code>" . h($ln) . "</code><br>"
               . ($puo
                    ? "Può ospitare post locali ✅"
                    : "<b>NON può ospitare post locali ❌</b> — è questa la causa del 500. "
                    . "Non è il codice: è lo stato della scheda su Google. Il perché esatto è qui sotto.")
               . ($qui ? "<br><span class='muted'>È la scheda che la dash sta usando.</span>" : "")
               . "</div>";

            // Perché non può: la "Voice of Merchant" è lo stato che Google usa per decidere
            // se una scheda può pubblicare. La sua risposta elenca il singolo ostacolo —
            // verifica da fare, proprietà contesa, linee guida, o solo attesa — e quindi
            // dice cosa fare invece di lasciare "non verificata, arrangiati".
            if (!$puo) {
                [$vc, $vom] = gbp_api_get(
                    'https://mybusinessverifications.googleapis.com/v1/' . $ln . ':getVoiceOfMerchantState', $token
                );
                if ($vc === 403 || $vc === 404) {
                    echo "<p class='muted'>Stato «Voice of Merchant» non leggibile (HTTP $vc): serve abilitare "
                       . "<code>mybusinessverifications.googleapis.com</code> nel progetto Cloud. "
                       . "Senza, resta da guardare a mano su business.google.com.</p>";
                } elseif ($vc !== 200 || !is_array($vom)) {
                    echo "<p class='muted'>Stato «Voice of Merchant» non leggibile (HTTP $vc).</p>";
                } else {
                    $motivi = [];
                    if (isset($vom['verify']))                  $motivi[] = "<b>La scheda va verificata.</b> Su "
                        . "business.google.com parti dalla verifica (cartolina, telefono o video, secondo quello "
                        . "che Google propone): finché non è verificata, i post non partono.";
                    if (isset($vom['resolveOwnershipConflict'])) $motivi[] = "<b>C'è un conflitto di proprietà</b> "
                        . "sulla scheda (qualcun altro la rivendica, o esiste un duplicato). Va risolto su "
                        . "business.google.com prima di poter pubblicare.";
                    if (isset($vom['complyWithGuidelines']))     $motivi[] = "<b>La scheda è sospesa o non "
                        . "conforme alle linee guida</b>: va sistemata e ri-sottoposta a Google.";
                    if (isset($vom['waitForVoiceOfMerchant']))   $motivi[] = "<b>Google sta ancora elaborando</b> "
                        . "la scheda: non c'è niente da fare se non aspettare e riprovare.";
                    if (!empty($vom['hasVoiceOfMerchant']))      $motivi[] = "Google dice che la scheda ha già "
                        . "«Voice of Merchant»: allora il blocco ai post viene da altro (categoria dell'attività, "
                        . "o restrizione sul singolo account).";
                    if (empty($vom['hasBusinessAuthority']))     $motivi[] = "L'account collegato <b>non risulta "
                        . "avere l'autorità</b> su questa attività: controlla di aver autorizzato "
                        . "(<code>ardy-gbp-auth.php</code>) con l'account che <b>possiede</b> la scheda, non con "
                        . "uno che ci ha solo accesso.";
                    echo "<div class='box warn'><b>Perché non può pubblicare</b>"
                       . ($motivi ? "<ul><li>" . implode("</li><li>", $motivi) . "</li></ul>"
                                  : "<br>Google non indica un ostacolo specifico.")
                       . "<details><summary class='muted'>risposta completa</summary><pre>"
                       . h(json_encode($vom, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
                       . "</pre></details></div>";
                }
            }
        }
    }

    // L'immagine: Google deve poterla SCARICARE (min 250×250, JPG/PNG, < 5 MB).
    // Si passa con ?img=<url> — lo si copia dall'articolo o dal log della pubblicazione.
    $imgTest = trim((string)($_GET['img'] ?? ''));
    echo "<h3 style='font-size:14px'>L'immagine del post</h3>";
    if ($imgTest === '' || !filter_var($imgTest, FILTER_VALIDATE_URL)) {
        echo "<p class='muted'>Aggiungi <code>?img=&lt;url della foto&gt;</code> a questa pagina per "
           . "controllare che Google possa scaricarla (serve pubblica, JPG/PNG, almeno 250×250, sotto i 5 MB). "
           . "L'URL è quello che compare nel log della pubblicazione fallita, campo <code>media=</code>.</p>";
    } else {
        $ci = curl_init($imgTest);
        curl_setopt($ci, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ci, CURLOPT_TIMEOUT,        20);
        curl_setopt($ci, CURLOPT_FOLLOWLOCATION, true);
        $imgBytes = (string)curl_exec($ci);
        $imgCode  = (int)curl_getinfo($ci, CURLINFO_HTTP_CODE);
        $imgType  = (string)curl_getinfo($ci, CURLINFO_CONTENT_TYPE);
        curl_close($ci);
        $dim = $imgBytes !== '' ? @getimagesizefromstring($imgBytes) : false;
        $kb  = round(strlen($imgBytes) / 1024);
        if ($imgCode !== 200) {
            echo "<div class='box err'><b>❌ Google non può scaricarla: HTTP $imgCode.</b><br>"
               . "Se è 401 sta dietro Basic Auth (è il nostro server, non WordPress): al post va l'URL "
               . "pubblico di WP, non quello della dash.</div>";
        } elseif (!$dim) {
            echo "<div class='box err'><b>❌ Scaricata ($kb KB, <code>" . h($imgType) . "</code>) ma non è "
               . "un'immagine leggibile.</b></div>";
        } else {
            $ok = $dim[0] >= 250 && $dim[1] >= 250 && $kb < 5120
               && in_array($dim['mime'] ?? '', ['image/jpeg', 'image/png'], true);
            echo "<div class='box " . ($ok ? 'ok' : 'err') . "'>"
               . ($ok ? '✅ Va bene per Google' : '❌ Google la rifiuta')
               . ": <b>{$dim[0]}×{$dim[1]}</b>, $kb KB, <code>" . h((string)($dim['mime'] ?? '?')) . "</code>."
               . ($ok ? '' : "<br>Servono almeno <b>250×250</b>, formato <b>JPG o PNG</b>, sotto i <b>5 MB</b>.")
               . "</div>";
        }
    }

    // Prova reale, solo su richiesta esplicita: pubblica DAVVERO un post di testo.
    // Se questo passa e con la foto no, il colpevole è la foto; se fallisce anche
    // così, è la scheda. Sta dietro un link apposta: non deve partire per sbaglio.
    echo "<h3 style='font-size:14px'>Prova di pubblicazione (solo testo)</h3>";
    if (($_GET['post_test'] ?? '') === '1') {
        $prova = gbp_create_local_post(
            'Prova tecnica di pubblicazione dalla dashboard Ardy — ' . date('d/m/Y H:i') . '.', '', ''
        );
        if (!empty($prova['success'])) {
            echo "<div class='box ok'><b>✅ Il post di solo testo è passato</b> (<code>"
               . h((string)($prova['name'] ?? '')) . "</code>).<br>Quindi la scheda va bene e il 500 "
               . "arriva dall'<b>immagine</b>: controllala qui sopra con <code>?img=</code>. "
               . "<b>Ricordati di cancellare questo post di prova</b> dalla scheda Google.</div>";
        } else {
            echo "<div class='box err'><b>❌ Fallisce anche di solo testo</b> — HTTP "
               . h((string)($prova['http'] ?? '?')) . " " . h((string)($prova['status'] ?? '')) . ": "
               . h((string)($prova['error'] ?? '')) . "<br>Allora non è l'immagine: è la <b>scheda</b> "
               . "(vedi i riquadri qui sopra) o l'accesso all'API.</div>";
        }
    } else {
        echo "<p class='muted'><a href='?post_test=1'>Pubblica un post di prova di solo testo</a> — "
           . "attenzione: <b>compare davvero</b> sulla scheda Google e va cancellato a mano. "
           . "Serve a separare «è la foto» da «è la scheda».</p>";
    }
}

// --- Dettaglio tecnico -------------------------------------------------
echo "<h2 style='font-size:15px'>Dettaglio risposta</h2>";
echo "<p class='muted'>HTTP <b>$httpCode</b>" . ($status ? " · status <b>" . h($status) . "</b>" : "")
   . ($reason ? " · reason <b>" . h($reason) . "</b>" : "") . "</p>";
$body = is_array($data)
    ? json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    : ($resp !== '' ? $resp : '(corpo vuoto)');
echo "<pre>" . h($body) . "</pre>";
echo "<p class='muted'>Header risposta:</p>";
echo "<pre>" . h(trim($rawHeaders) ?: '(nessun header)') . "</pre>";

// Il prefisso numerico del client_id OAuth = Project Number del progetto Cloud.
// Usa il client GBP effettivamente in uso (non più il gcal): è quello che conta.
$projNum = preg_match('/^(\d+)-/', gbp_client_id(), $pm) ? $pm[1] : '(non ricavabile)';
echo "<hr><p class='muted'><b>Progetto del client OAuth</b> (Project Number ricavato dal "
   . "client_id): <code>" . h($projNum) . "</code><br>Deve coincidere col progetto Cloud dove "
   . "hai <b>abilitato le API My Business</b> e <b>inviato il form di accesso</b>. Se è diverso, "
   . "è quello il motivo del 403.</p>";

echo "<p class='muted'>Per (ri)autorizzare: apri <code>ardy-gbp-auth.php</code> nel browser, "
   . "completa il consenso Google (scope <code>business.manage</code>) con l'account che gestisce "
   . "la scheda, poi torna qui.</p>";
echo "</body></html>";
