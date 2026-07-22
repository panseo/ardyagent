<?php
// -----------------------------------------------------------
// ARDY LAB — Health check / cruscotto stato produzione
//
// Endpoint di monitoraggio: verifica in un colpo solo lo stato delle parti
// che possono rompersi in silenzio (DB, token Google, config integrazioni,
// cartelle scrivibili, errori PHP recenti, spazio disco).
//
// USO
//   • Browser:        https://.../ardy-health.php          (semaforo HTML)
//   • Monitor esterno: https://.../ardy-health.php?format=json
//
// L'endpoint è protetto da Basic Auth (via .htaccess, come le altre pagine
// admin). Su UptimeRobot / Better Stack imposta le stesse credenziali admin
// nel monitor (supportano l'HTTP Basic Auth nativamente) e usa un monitor
// "keyword" che si aspetta la stringa  "status":"ok"  sull'URL JSON — se
// compare "down" o l'HTTP è 503, scatta l'alert.
//
// Ritorna HTTP 200 se tutto verde/giallo, HTTP 503 se c'è almeno un rosso
// (così i monitor che guardano solo il codice HTTP allertano sui guasti veri).
// -----------------------------------------------------------

require_once __DIR__ . '/ardy-config.php';
require_once __DIR__ . '/ardy-db.php';
require_once __DIR__ . '/ardy-auth.php';

date_default_timezone_set('Europe/Rome');

// Difesa in profondità: il Basic Auth a monte (.htaccess) è la protezione
// primaria; questo guard rifiuta comunque se la richiesta arriva a PHP senza
// essere autenticata (deploy errato, FilesMatch non combaciante).
ardyRequireAuth();

$wantJson = (($_GET['format'] ?? '') === 'json')
    || (stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false);

// -----------------------------------------------------------
// Raccolta check. Ogni check è isolato in try/catch: un check che esplode
// non deve mai far cadere l'intero endpoint di monitoraggio.
//   livello: 'ok' | 'warn' | 'down'
// -----------------------------------------------------------
$checks = [];

function chk(string $nome, string $livello, string $dettaglio, array $extra = []): array {
    return ['nome' => $nome, 'livello' => $livello, 'dettaglio' => $dettaglio] + $extra;
}

// --- 1) PHP -------------------------------------------------
$checks[] = chk('PHP', 'ok', 'Versione ' . PHP_VERSION);

// --- 2) Database + tabella core -----------------------------
try {
    $db = ardyDB();
    $db->query('SELECT 1');
    $nClienti = (int) $db->query('SELECT COUNT(*) FROM clienti')->fetchColumn();
    $checks[] = chk('Database', 'ok', "Connesso · $nClienti clienti in anagrafica");
} catch (Throwable $e) {
    $checks[] = chk('Database', 'down', 'Connessione fallita: ' . $e->getMessage());
}

// --- 3) Config integrazioni ---------------------------------
$attesi = [
    'DB'              => ['ARDY_DB_HOST', 'ARDY_DB_NAME', 'ARDY_DB_USER', 'ARDY_DB_PASS'],
    'WhatsApp'        => ['WA_TOKEN', 'WA_PHONE_NUMBER_ID'],
    'Google Business' => ['ARDY_GBP_CLIENT_ID', 'ARDY_GBP_CLIENT_SECRET'],
    'Google Calendar' => ['ARDY_GCAL_CLIENT_ID', 'ARDY_GCAL_CLIENT_SECRET'],
    'Email (Brevo)'   => ['ARDY_BREVO_API_KEY'],
];
$mancanti = [];
foreach ($attesi as $gruppo => $costanti) {
    foreach ($costanti as $c) {
        if (!defined($c) || constant($c) === '' || constant($c) === null) {
            $mancanti[] = "$gruppo:$c";
        }
    }
}
$checks[] = $mancanti
    ? chk('Config', 'warn', 'Costanti mancanti/vuote: ' . implode(', ', $mancanti))
    : chk('Config', 'ok', 'Tutte le costanti chiave definite');

// --- 4) Token Google (Calendar + Business Profile) ----------
// Il refresh_token è la chiave che dura: se manca, l'integrazione è ferma e
// serve ri-autorizzare da ardy-gcal-auth.php / ardy-gbp-auth.php. L'access_token
// si rinnova da solo, ne mostriamo solo la freschezza a titolo informativo.
function tokenCheck(string $nome, string $file): array {
    if (!is_file($file)) {
        return chk($nome, 'warn', 'File token assente — serve autorizzazione OAuth');
    }
    $t = json_decode((string) @file_get_contents($file), true);
    if (!is_array($t) || empty($t['refresh_token'])) {
        return chk($nome, 'warn', 'refresh_token assente — ri-autorizzare');
    }
    $scadeTra = ($t['created_at'] ?? 0) + ($t['expires_in'] ?? 3600) - time();
    $freschezza = $scadeTra > 0
        ? 'access_token valido ancora ~' . max(0, (int) round($scadeTra / 60)) . ' min'
        : 'access_token scaduto (si rinnova alla prossima chiamata)';
    return chk($nome, 'ok', "refresh_token presente · $freschezza");
}
$gcalFile = defined('GCAL_TOKEN_FILE') ? GCAL_TOKEN_FILE : __DIR__ . '/ardy-gcal-token.json';
$gbpFile  = defined('GBP_TOKEN_FILE')  ? GBP_TOKEN_FILE  : __DIR__ . '/ardy-gbp-token.json';
$checks[] = tokenCheck('Token Google Calendar', $gcalFile);
$checks[] = tokenCheck('Token Google Business', $gbpFile);

// --- 5) Cartelle scrivibili ---------------------------------
$dirs = [
    'Upload'     => defined('ARDY_UPLOAD_DIR')     ? ARDY_UPLOAD_DIR     : __DIR__ . '/ardy-uploads',
    'PDF'        => defined('ARDY_PDF_DIR')        ? ARDY_PDF_DIR        : __DIR__ . '/preventivi_pdf',
    'Rate-limit' => defined('ARDY_RATE_LIMIT_DIR') ? ARDY_RATE_LIMIT_DIR : __DIR__ . '/ardy-rate-limit',
];
$dirKo = [];
foreach ($dirs as $etichetta => $path) {
    if (!is_dir($path) || !is_writable($path)) {
        $dirKo[] = $etichetta;
    }
}
$checks[] = $dirKo
    ? chk('Cartelle', 'warn', 'Non scrivibili o assenti: ' . implode(', ', $dirKo))
    : chk('Cartelle', 'ok', 'Upload / PDF / Rate-limit scrivibili');

// --- 6) Log errori PHP (oggi) -------------------------------
$logPath = ini_get('error_log');
if (!$logPath || !is_file($logPath)) {
    // Fallback tipico su cPanel: error_log nel document root.
    $cand = __DIR__ . '/error_log';
    $logPath = is_file($cand) ? $cand : '';
}
if ($logPath && is_readable($logPath)) {
    $oggi = date('d-M-Y'); // formato timestamp PHP error_log: [22-Jul-2026 ...]
    $errOggi = 0;
    $ultima  = '';
    // Legge solo la coda del file per non caricare log enormi in memoria.
    $fh = @fopen($logPath, 'r');
    if ($fh) {
        $size = filesize($logPath);
        $seek = max(0, $size - 262144); // ultimi 256 KB
        fseek($fh, $seek);
        while (($line = fgets($fh)) !== false) {
            if (strpos($line, $oggi) !== false) {
                $errOggi++;
                $ultima = trim($line);
            }
        }
        fclose($fh);
    }
    $livello = $errOggi === 0 ? 'ok' : ($errOggi >= 50 ? 'warn' : 'ok');
    $det = $errOggi === 0
        ? 'Nessun errore PHP registrato oggi'
        : "$errOggi righe di errore oggi · ultima: " . mb_substr($ultima, 0, 160);
    $checks[] = chk('Log errori PHP', $livello, $det, ['errori_oggi' => $errOggi]);
} else {
    $checks[] = chk('Log errori PHP', 'warn', 'Log non trovato/illeggibile (ini error_log non impostato)');
}

// --- 7) Spazio disco ----------------------------------------
try {
    $free  = @disk_free_space(__DIR__);
    $total = @disk_total_space(__DIR__);
    if ($free && $total) {
        $pctLibero = $free / $total * 100;
        $liv = $pctLibero < 5 ? 'warn' : 'ok';
        $checks[] = chk('Disco', $liv, sprintf('%.1f%% libero (%.1f GB / %.1f GB)',
            $pctLibero, $free / 1e9, $total / 1e9));
    } else {
        $checks[] = chk('Disco', 'ok', 'Spazio non rilevabile su questo hosting');
    }
} catch (Throwable $e) {
    $checks[] = chk('Disco', 'ok', 'Spazio non rilevabile');
}

// -----------------------------------------------------------
// Stato complessivo
// -----------------------------------------------------------
$hasDown = false; $hasWarn = false;
foreach ($checks as $c) {
    if ($c['livello'] === 'down') $hasDown = true;
    if ($c['livello'] === 'warn') $hasWarn = true;
}
$statoGlobale = $hasDown ? 'down' : ($hasWarn ? 'warn' : 'ok');

// HTTP 503 solo sui guasti veri (down): i warn non devono far scattare page.
http_response_code($hasDown ? 503 : 200);

// -----------------------------------------------------------
// Output JSON (monitor) o HTML (persona)
// -----------------------------------------------------------
if ($wantJson) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status'    => $statoGlobale,
        'timestamp' => date('c'),
        'checks'    => $checks,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

header('Content-Type: text/html; charset=utf-8');
$emoji = ['ok' => '🟢', 'warn' => '🟡', 'down' => '🔴'];
$titoloStato = ['ok' => 'Tutto operativo', 'warn' => 'Attenzione', 'down' => 'Guasto in corso'];
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="refresh" content="60">
<title>Stato sistema — Ardy Lab</title>
<style>
  :root { --accent:#c8a96e; --bg:#0e0e0e; --surface:#1a1a1a; --border:#333; --dim:#888;
          --ok:#3fb950; --warn:#d29922; --down:#f85149; }
  body { background:var(--bg); color:#eee; font-family:'DM Sans',Arial,sans-serif; margin:0; padding:24px; }
  h1 { color:var(--accent); font-size:20px; margin:0 0 4px; }
  .sub { color:var(--dim); font-size:13px; margin:0 0 20px; }
  .banner { display:inline-flex; align-items:center; gap:10px; font-size:18px; font-weight:600;
            padding:12px 20px; border-radius:10px; margin-bottom:24px; border:1px solid var(--border); background:var(--surface); }
  .banner.ok   { color:var(--ok);   border-color:var(--ok); }
  .banner.warn { color:var(--warn); border-color:var(--warn); }
  .banner.down { color:var(--down); border-color:var(--down); }
  table { border-collapse:collapse; width:100%; max-width:820px; }
  th,td { border:1px solid var(--border); padding:10px 12px; text-align:left; font-size:14px; vertical-align:top; }
  th { background:#222; color:var(--accent); font-size:12px; text-transform:uppercase; letter-spacing:.5px; }
  td.stato { white-space:nowrap; font-weight:600; }
  td.stato.ok { color:var(--ok); } td.stato.warn { color:var(--warn); } td.stato.down { color:var(--down); }
  td.det { color:#ccc; font-family:'DM Mono',monospace; font-size:12.5px; word-break:break-word; }
  .foot { color:var(--dim); font-size:12px; margin-top:18px; }
  code { background:#222; padding:2px 6px; border-radius:4px; color:var(--accent); }
</style>
</head>
<body>
  <h1>🩺 Stato sistema Ardy</h1>
  <p class="sub">Aggiornamento automatico ogni 60s · fuso Europe/Rome · <?= date('d/m/Y H:i:s') ?></p>

  <div class="banner <?= $statoGlobale ?>">
    <?= $emoji[$statoGlobale] ?> <?= $titoloStato[$statoGlobale] ?>
  </div>

  <table>
    <tr><th>Componente</th><th>Stato</th><th>Dettaglio</th></tr>
    <?php foreach ($checks as $c): ?>
      <tr>
        <td><?= htmlspecialchars($c['nome']) ?></td>
        <td class="stato <?= $c['livello'] ?>"><?= $emoji[$c['livello']] ?> <?= strtoupper($c['livello']) ?></td>
        <td class="det"><?= htmlspecialchars($c['dettaglio']) ?></td>
      </tr>
    <?php endforeach; ?>
  </table>

  <p class="foot">
    Versione per monitor esterno: <code>ardy-health.php?format=json</code> —
    HTTP 503 in caso di guasto (rosso), 200 altrimenti.
  </p>
</body>
</html>
