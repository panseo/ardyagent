<?php
// -----------------------------------------------------------
// ARDY LAB — Cattura le risposte email dei clienti (IMAP → email_log)
//
// ardy-email-cliente-api.php spedisce con Reply-To ardy.documenti@gmail.com
// (vedi ardy_email_footer_cliente / addReplyTo in quel file): quando il
// cliente risponde, la mail atterra in quella casella. Questo job la legge
// via IMAP, prova ad agganciare il mittente a un cliente in CRM (match sulla
// colonna clienti.email) e salva la riga in email_log con direzione
// 'entrata' — così finisce nella sezione "Storico email" del Dossier
// (ardy-dossier.php) accanto alle email inviate e alla chat WhatsApp/web.
//
// Legge solo i messaggi UNSEEN e li marca \Seen dopo averli processati
// (che siano stati abbinati o no), così ogni run riparte dai soli nuovi
// arrivi. message_id (UNIQUE, vedi ardy-migrate.php) è una rete di
// sicurezza in più contro doppioni in caso di run sovrapposti.
//
// INVOCAZIONE — pensato per girare ogni N minuti da cron (stessa forma di
// ardy-chiusura-sessioni.php):
//   curl -s "https://ardyagent.ardy-lab.it/ardy-email-inbox-sync.php?secret=XXX"
// oppure da CLI:  php ardy-email-inbox-sync.php
//
// Protezione: come ardy-chiusura-sessioni.php — da CLI nessun segreto; via
// HTTP serve WA_LOOKUP_SECRET in ?secret= o header X-Ardy-Secret.
//
// Config richiesta in ardy-config.php (nuove costanti):
//   ARDY_IMAP_USER      indirizzo Gmail da leggere (es. ardy.documenti@gmail.com)
//   ARDY_IMAP_PASSWORD  password per app di quel Gmail (richiede 2FA attivo
//                       sull'account: myaccount.google.com/apppasswords —
//                       la password normale dell'account NON funziona con IMAP)
//   ARDY_IMAP_HOST      opzionale, default '{imap.gmail.com:993/imap/ssl}INBOX'
//
// Richiede l'estensione PHP imap (su cPanel: "Select PHP Version" → estensione
// "imap"). Se manca, il job fallisce con un errore chiaro invece di un fatal.
// -----------------------------------------------------------

date_default_timezone_set('Europe/Rome');

require_once __DIR__ . '/ardy-config.php';
require_once __DIR__ . '/ardy-db.php';

$isCli = (PHP_SAPI === 'cli');

if (!$isCli) {
    header('Content-Type: application/json; charset=utf-8');
    if (!defined('WA_LOOKUP_SECRET') || WA_LOOKUP_SECRET === '') {
        error_log('ARDY EMAIL INBOX SYNC: WA_LOOKUP_SECRET non configurato — richiesta rifiutata');
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

/** Corpo testuale di un messaggio: preferisce text/plain, altrimenti text/html ripulito. */
function ardy_imap_estrai_testo($conn, $uid): string {
    $struct = @imap_fetchstructure($conn, $uid, FT_UID);
    $piano  = ardy_imap_trova_parte($conn, $uid, $struct, 'text/plain');
    if ($piano !== null && trim($piano) !== '') return trim($piano);

    $html = ardy_imap_trova_parte($conn, $uid, $struct, 'text/html');
    if ($html !== null && trim($html) !== '') {
        $testo = html_entity_decode(strip_tags(preg_replace('/<(br|p|div)[^>]*>/i', "\n", $html)), ENT_QUOTES, 'UTF-8');
        return trim(preg_replace("/\n{3,}/", "\n\n", $testo));
    }

    // Messaggio senza MIME multipart: il body intero è la parte 1 (o il corpo diretto).
    $raw = @imap_fetchbody($conn, $uid, '1', FT_UID);
    return trim((string) ($raw !== false && $raw !== '' ? $raw : @imap_body($conn, $uid, FT_UID)));
}

/** Cerca ricorsivamente, nella struttura MIME, la prima parte del mimetype richiesto. */
function ardy_imap_trova_parte($conn, $uid, $part, string $mimeWanted, string $prefix = ''): ?string {
    if (!$part) return null;
    $tipi = ['text', 'multipart', 'message', 'application', 'audio', 'image', 'video', 'other'];
    $tipo = strtolower($tipi[$part->type] ?? 'other') . '/' . strtolower($part->subtype ?? '');

    if (!empty($part->parts)) {
        foreach ($part->parts as $i => $sub) {
            $num = ($prefix !== '' ? $prefix . '.' : '') . ($i + 1);
            $trovato = ardy_imap_trova_parte($conn, $uid, $sub, $mimeWanted, $num);
            if ($trovato !== null) return $trovato;
        }
        return null;
    }

    if ($tipo !== $mimeWanted) return null;

    $num  = $prefix !== '' ? $prefix : '1';
    $body = @imap_fetchbody($conn, $uid, $num, FT_UID);
    if ($body === false) return null;

    switch ((int) ($part->encoding ?? 0)) {
        case 3: $body = base64_decode($body); break;                 // BASE64
        case 4: $body = quoted_printable_decode($body); break;       // QUOTED-PRINTABLE
    }
    return $body;
}

/** Charset dichiarato dalla parte (fallback UTF-8), per riportare il testo a UTF-8. */
function ardy_imap_decodifica(string $testo): string {
    $enc = mb_detect_encoding($testo, ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true);
    return ($enc && $enc !== 'UTF-8') ? mb_convert_encoding($testo, 'UTF-8', $enc) : $testo;
}

$esaminati = 0;
$abbinati  = 0;
$scartati  = 0;
$errori    = [];

try {
    if (!function_exists('imap_open')) {
        throw new RuntimeException("Estensione PHP 'imap' non disponibile su questo server");
    }
    if (!defined('ARDY_IMAP_USER') || !defined('ARDY_IMAP_PASSWORD')) {
        throw new RuntimeException('ARDY_IMAP_USER / ARDY_IMAP_PASSWORD non configurate in ardy-config.php');
    }

    $host = defined('ARDY_IMAP_HOST') ? ARDY_IMAP_HOST : '{imap.gmail.com:993/imap/ssl}INBOX';

    $conn = @imap_open($host, ARDY_IMAP_USER, ARDY_IMAP_PASSWORD, 0, 1);
    if (!$conn) {
        throw new RuntimeException('Connessione IMAP fallita: ' . imap_last_error());
    }

    $db = ardyDB();
    $cliSt = $db->prepare("SELECT session_id FROM clienti WHERE LOWER(email) = LOWER(:em) ORDER BY id DESC LIMIT 1");
    $insSt = $db->prepare(
        "INSERT IGNORE INTO email_log (session_id, direzione, destinatario, mittente, oggetto, testo, message_id)
         VALUES (:sid, 'entrata', :dest, :mitt, :ogg, :txt, :mid)"
    );

    $uids = imap_search($conn, 'UNSEEN', SE_UID) ?: [];

    foreach ($uids as $uid) {
        $esaminati++;
        try {
            $head = imap_headerinfo($conn, imap_msgno($conn, $uid));
            $da   = '';
            if (!empty($head->from[0]->mailbox) && !empty($head->from[0]->host)) {
                $da = strtolower(trim($head->from[0]->mailbox . '@' . $head->from[0]->host));
            }
            $oggetto = $head->subject ?? '';
            if ($oggetto !== '') $oggetto = ardy_imap_decodifica(imap_utf8($oggetto));
            $messageId = trim((string) ($head->message_id ?? ''));

            $cli = null;
            if ($da !== '') {
                $cliSt->execute([':em' => $da]);
                $cli = $cliSt->fetch(PDO::FETCH_ASSOC);
            }

            if (!$cli) {
                // Mittente non in CRM (newsletter, spam, indirizzo diverso da
                // quello in scheda...): non scartiamo silenziosamente il fatto,
                // solo la mail — resta comunque leggibile su Gmail.
                $scartati++;
                imap_setflag_full($conn, (string) $uid, '\\Seen', ST_UID);
                continue;
            }

            $testo = ardy_imap_decodifica(ardy_imap_estrai_testo($conn, $uid));

            $insSt->execute([
                ':sid'  => $cli['session_id'],
                ':dest' => ARDY_IMAP_USER,
                ':mitt' => $da,
                ':ogg'  => mb_substr($oggetto, 0, 255),
                ':txt'  => $testo !== '' ? $testo : '(corpo vuoto)',
                ':mid'  => $messageId !== '' ? mb_substr($messageId, 0, 255) : null,
            ]);
            $abbinati++;

            imap_setflag_full($conn, (string) $uid, '\\Seen', ST_UID);
        } catch (Throwable $e) {
            // Un singolo messaggio malformato non deve far fallire l'intero run:
            // lo lascio UNSEEN (niente ->setflag qui) così viene ritentato al prossimo giro.
            $errori[] = 'uid ' . $uid . ': ' . $e->getMessage();
            error_log('ARDY EMAIL INBOX SYNC msg ' . $uid . ': ' . $e->getMessage());
        }
    }

    imap_close($conn);

} catch (Throwable $e) {
    error_log('ARDY EMAIL INBOX SYNC ERROR: ' . $e->getMessage());
    if (!$isCli) { http_response_code(500); }
    $out = ['success' => false, 'error' => $e->getMessage(), 'esaminati' => $esaminati, 'abbinati' => $abbinati];
    echo $isCli ? json_encode($out, JSON_PRETTY_PRINT) . "\n" : json_encode($out);
    exit();
}

$out = ['success' => true, 'esaminati' => $esaminati, 'abbinati' => $abbinati, 'scartati' => $scartati];
if ($errori) $out['errori'] = $errori;
echo $isCli ? json_encode($out, JSON_PRETTY_PRINT) . "\n" : json_encode($out);
