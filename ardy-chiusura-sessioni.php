<?php
// -----------------------------------------------------------
// ARDY LAB — Notifica a Michela alla "chiusura" delle chat con Sole
//
// Le chat cliente↔Sole non hanno un evento esplicito di chiusura: la sessione
// resta aperta finché il cliente scrive. Questo job considera "chiusa" una
// conversazione quando NON arrivano nuovi messaggi da almeno 1 ora, e manda a
// Michela una notifica WhatsApp con i dati essenziali (nome, contatto, canale,
// n° messaggi, stato CRM, orario ultimo messaggio).
//
// Copre entrambi i canali: chat web (web_messaggi) e WhatsApp (wa_messaggi).
// Ogni sessione viene notificata UNA SOLA volta grazie al dedupe persistente di
// notificaMichela() (chiave = canale + id sessione + timestamp ultimo messaggio):
// finché la conversazione resta ferma non si ripete; se il cliente riscrive e
// poi tace di nuovo, la chiave cambia e parte una nuova notifica.
//
// INVOCAZIONE — pensato per essere chiamato ogni ora da un cron/n8n:
//   curl -s "https://ardyagent.ardy-lab.it/ardy-chiusura-sessioni.php?secret=XXX"
// oppure da CLI:  php ardy-chiusura-sessioni.php
//
// Protezione: se WA_LOOKUP_SECRET è definito, va passato in ?secret= o header
// X-Ardy-Secret (come ardy-notifica-michela.php). Da CLI non serve il segreto.
//
// Config richiesta (ardy-config.php): DB + WA_TOKEN / WA_PHONE_NUMBER_ID /
// WA_MICHELA_NUMBER (per l'invio a Michela). Vedi ardy-notifica-michela.php.
// -----------------------------------------------------------

date_default_timezone_set('Europe/Rome');

require_once __DIR__ . '/ardy-config.php';
require_once __DIR__ . '/ardy-db.php';
require_once __DIR__ . '/ardy-notifica-michela.php';

// Finestra: conversazione ferma da almeno IDLE_MIN minuti, ma attiva nelle
// ultime LOOKBACK_H ore (evita di rielaborare lo storico al primo avvio).
const ARDY_CHIUSURA_IDLE_MIN  = 60;   // 1 ora di silenzio = sessione "chiusa"
const ARDY_CHIUSURA_LOOKBACK_H = 24;  // considera solo sessioni delle ultime 24h

$isCli = (PHP_SAPI === 'cli');

if (!$isCli) {
    header('Content-Type: application/json; charset=utf-8');
    // Protezione col segreto condiviso. Fail-closed: senza segreto configurato
    // l'endpoint HTTP NON è accessibile (la modalità CLI/cron resta esente).
    if (!defined('WA_LOOKUP_SECRET') || WA_LOOKUP_SECRET === '') {
        error_log('ARDY CHIUSURA SESSIONI: WA_LOOKUP_SECRET non configurato — richiesta rifiutata');
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'configurazione mancante']);
        exit();
    }
    $sent = $_SERVER['HTTP_X_ARDY_SECRET'] ?? ($_GET['secret'] ?? '');
    if (!hash_equals(WA_LOOKUP_SECRET, (string) $sent)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'non autorizzato']);
        exit();
    }
}

/** Ultime 9 cifre di un numero (per agganciare il cliente in CRM). */
function ardy_chiusura_p9(string $phone): string {
    $d = preg_replace('/\D+/', '', $phone);
    return strlen($d) > 9 ? substr($d, -9) : $d;
}

/** Costruisce il testo della notifica con i dati essenziali. */
function ardy_chiusura_messaggio(string $canale, ?array $cli, string $contattoFallback, int $tot, string $lastAt): string {
    $nome = $cli ? trim(($cli['nome'] ?? '') . ' ' . ($cli['cognome'] ?? '')) : '';
    if ($nome === '') $nome = $cli ? '(senza nome)' : '(non in CRM)';

    $contatti = [];
    $tel = $cli['telefono'] ?? '';
    $em  = $cli['email'] ?? '';
    if ($tel !== '') $contatti[] = '📞 ' . $tel;
    if ($em  !== '') $contatti[] = '✉ ' . $em;
    if (!$contatti && $contattoFallback !== '') $contatti[] = '📞 ' . $contattoFallback;

    $stato = $cli && !empty($cli['stato']) ? $cli['stato'] : '—';

    try { $quando = ardy_data_ita(new DateTime($lastAt)); }
    catch (Throwable $e) { $quando = $lastAt; }

    $righe = [];
    $righe[] = '💬 Chat conclusa — ' . $nome;
    $righe[] = 'Canale: ' . $canale . ' · ' . $tot . ' messaggi';
    if ($contatti) $righe[] = implode(' · ', $contatti);
    $righe[] = 'Stato CRM: ' . $stato;
    $righe[] = 'Ultimo messaggio: ' . $quando;
    return implode("\n", $righe);
}

$esaminate = 0;
$inviate   = 0;
$errori    = [];

try {
    $db = ardyDB();

    // -------------------- CHAT WEB (web_messaggi) --------------------
    try {
        $idle = (int) ARDY_CHIUSURA_IDLE_MIN;
        $look = (int) ARDY_CHIUSURA_LOOKBACK_H;
        $sql = "SELECT session_id,
                       COUNT(*)                       AS tot,
                       SUM(role = 'user')             AS cnt_user,
                       MAX(created_at)                AS last_at,
                       UNIX_TIMESTAMP(MAX(created_at)) AS last_epoch
                FROM web_messaggi
                GROUP BY session_id
                HAVING last_at < (NOW() - INTERVAL $idle MINUTE)
                   AND last_at > (NOW() - INTERVAL $look HOUR)
                   AND cnt_user >= 1";
        $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);

        $cliSt = $db->prepare("SELECT nome, cognome, telefono, email, stato FROM clienti WHERE session_id = :sid LIMIT 1");
        foreach ($rows as $r) {
            $esaminate++;
            $cliSt->execute([':sid' => $r['session_id']]);
            $cli = $cliSt->fetch(PDO::FETCH_ASSOC) ?: null;

            $msg    = ardy_chiusura_messaggio('Web', $cli, '', (int) $r['tot'], (string) $r['last_at']);
            $dedupe = 'chat-chiusa:web:' . $r['session_id'] . ':' . $r['last_epoch'];
            if (notificaMichela($msg, $dedupe)) $inviate++;
        }
    } catch (PDOException $e) {
        $errori[] = 'web: ' . $e->getMessage();
        error_log('ARDY CHIUSURA SESSIONI WEB: ' . $e->getMessage());
    }

    // -------------------- CHAT WHATSAPP (wa_messaggi) --------------------
    try {
        $idle = (int) ARDY_CHIUSURA_IDLE_MIN;
        $look = (int) ARDY_CHIUSURA_LOOKBACK_H;
        $sql = "SELECT phone,
                       COUNT(*)                       AS tot,
                       SUM(role = 'user')             AS cnt_user,
                       MAX(created_at)                AS last_at,
                       UNIX_TIMESTAMP(MAX(created_at)) AS last_epoch
                FROM wa_messaggi
                GROUP BY phone
                HAVING last_at < (NOW() - INTERVAL $idle MINUTE)
                   AND last_at > (NOW() - INTERVAL $look HOUR)
                   AND cnt_user >= 1";
        $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);

        // Aggancio il cliente in CRM dalle ultime 9 cifre del telefono.
        $cliSt = $db->prepare("SELECT nome, cognome, telefono, email, stato FROM clienti
                                WHERE RIGHT(REPLACE(REPLACE(telefono,' ',''),'+',''), 9) = :p9 LIMIT 1");
        foreach ($rows as $r) {
            $esaminate++;
            $p9 = ardy_chiusura_p9((string) $r['phone']);
            $cli = null;
            if ($p9 !== '') {
                $cliSt->execute([':p9' => $p9]);
                $cli = $cliSt->fetch(PDO::FETCH_ASSOC) ?: null;
            }

            $msg    = ardy_chiusura_messaggio('WhatsApp', $cli, (string) $r['phone'], (int) $r['tot'], (string) $r['last_at']);
            $dedupe = 'chat-chiusa:wa:' . $r['phone'] . ':' . $r['last_epoch'];
            if (notificaMichela($msg, $dedupe)) $inviate++;
        }
    } catch (PDOException $e) {
        $errori[] = 'whatsapp: ' . $e->getMessage();
        error_log('ARDY CHIUSURA SESSIONI WA: ' . $e->getMessage());
    }

} catch (Throwable $e) {
    error_log('ARDY CHIUSURA SESSIONI ERROR: ' . $e->getMessage());
    if (!$isCli) { http_response_code(500); }
    $out = ['success' => false, 'error' => 'Errore interno', 'esaminate' => $esaminate, 'inviate' => $inviate];
    echo $isCli ? json_encode($out, JSON_PRETTY_PRINT) . "\n" : json_encode($out);
    exit();
}

$out = ['success' => true, 'esaminate' => $esaminate, 'inviate' => $inviate];
if ($errori) $out['errori'] = $errori;
echo $isCli ? json_encode($out, JSON_PRETTY_PRINT) . "\n" : json_encode($out);
