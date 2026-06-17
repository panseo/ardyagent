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
// Riusa il token OAuth di Calendar/Gmail (ardy-gcal-token.json). Per leggere
// gli account serve però lo scope `business.manage`: se non c'è ancora,
// lo script lo segnala e basta ri-autorizzare (vedi nota in fondo).
// -----------------------------------------------------------

require_once __DIR__ . '/ardy-gcal.php'; // per gcal_get_access_token()

header('Content-Type: text/html; charset=utf-8');

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Endpoint "leggero": elenca gli account Business Profile collegati.
// È la chiamata più semplice che attraversa il gate di quota.
$API_URL = 'https://mybusinessaccountmanagement.googleapis.com/v1/accounts';

$token = gcal_get_access_token();

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
       . "Il file <code>ardy-gcal-token.json</code> manca o è scaduto senza refresh_token. "
       . "Ri-autorizza da <code>ardy-gcal-auth.php</code>.</div></body></html>";
    exit;
}

$ch = curl_init($API_URL);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT,        30);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER,     ['Authorization: Bearer ' . $token]);
$resp     = curl_exec($ch);
$err      = curl_error($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

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
    echo "<div class='box ok'><b>✅ QUOTA SBLOCCATA — l'API risponde!</b><br>"
       . "La Business Profile API è accessibile con questo progetto. "
       . "Account collegati trovati: <b>$n</b>.<br>"
       . "Puoi procedere a costruire la pubblicazione dei post delle fasi.</div>";
} elseif ($httpCode === 401) {
    echo "<div class='box err'><b>❌ Token non valido / scaduto (401).</b><br>"
       . "Ri-autorizza da <code>ardy-gcal-auth.php</code>.</div>";
} elseif ($httpCode === 403 && (stripos($status, 'PERMISSION_DENIED') !== false || stripos($reason, 'SCOPE') !== false)
          && stripos($msg, 'scope') !== false) {
    echo "<div class='box warn'><b>⚠️ Manca lo scope <code>business.manage</code>.</b><br>"
       . "Il progetto sembra autorizzato ma il token attuale (Calendar/Gmail) non include lo scope giusto. "
       . "Aggiungi <code>https://www.googleapis.com/auth/business.manage</code> in "
       . "<code>ardy-gcal-auth.php</code> e ri-autorizza (consenso) per ottenere un nuovo token. "
       . "Poi rilancia questo check.</div>";
} elseif ($httpCode === 429 || stripos($status, 'RESOURCE_EXHAUSTED') !== false) {
    echo "<div class='box err'><b>⛔ QUOTA ANCORA A ZERO / esaurita (429).</b><br>"
       . "La richiesta di aumento quota <b>non è ancora stata approvata</b> "
       . "(o il limite è 0). Controlla il valore in Cloud Console → "
       . "<i>Business Profile API → Quotas</i> e, se fermo da troppo, ri-sottometti il form di accesso.</div>";
} elseif ($httpCode === 403) {
    echo "<div class='box err'><b>⛔ Accesso negato (403).</b><br>"
       . "Tipicamente: API <b>non abilitata</b> nel progetto, oppure accesso/quota "
       . "<b>non ancora concessi</b>. Verifica in Cloud Console che la Business Profile API "
       . "(e My Business Account Management / Business Information) sia <i>Enabled</i> e con quota &gt; 0.</div>";
} else {
    echo "<div class='box warn'><b>Risposta inattesa (HTTP $httpCode).</b> Vedi il dettaglio sotto.</div>";
}

// --- Dettaglio tecnico -------------------------------------------------
echo "<h2 style='font-size:15px'>Dettaglio risposta</h2>";
echo "<p class='muted'>HTTP <b>$httpCode</b>" . ($status ? " · status <b>" . h($status) . "</b>" : "")
   . ($reason ? " · reason <b>" . h($reason) . "</b>" : "") . "</p>";
echo "<pre>" . h(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "</pre>";

echo "<hr><p class='muted'>Per abilitare lo scope la prima volta: in <code>ardy-gcal-auth.php</code> "
   . "aggiungi <code>https://www.googleapis.com/auth/business.manage</code> all'array degli scope, "
   . "apri quel file nel browser, completa il consenso Google, poi torna qui.</p>";
echo "</body></html>";
