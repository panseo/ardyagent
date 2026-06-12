<?php
// -----------------------------------------------------------
// ARDY LAB — Notifiche WhatsApp a Michela (Sole come segretaria)
//
// Doppio uso:
//   1) LIBRERIA  — include il file e chiama notificaMichela($testo, $dedupeKey)
//      (usato da ardy-proxy.php dopo lead salvato / appuntamento fissato /
//       quando Sole rileva reclami, problemi di pagamento, richieste fuori standard).
//   2) ENDPOINT  — chiamato in HTTP server-to-server da n8n (ramo WhatsApp), così
//      anche le conversazioni WhatsApp possono avvisare Michela riusando lo stesso
//      codice. POST JSON { "messaggio": "...", "dedupe": "..." } protetto dal
//      segreto condiviso WA_LOOKUP_SECRET (header X-Ardy-Secret).
//
// Config richiesta in ardy-config.php (NON in repo):
//   WA_TOKEN              token permanente Cloud API (Bearer)
//   WA_PHONE_NUMBER_ID    phone number id del numero "Sole" (es. 1151535311377293)
//   WA_MICHELA_NUMBER     numero di Michela in formato internazionale (es. 393519677973)
// Opzionali:
//   WA_TEMPLATE_NOTIFICA  nome template Meta approvato (body con 1 variabile {{1}}).
//                         Necessario per scrivere a Michela FUORI dalla finestra 24h.
//                         Se assente, si invia testo libero (funziona solo se Michela
//                         ha scritto al numero Sole nelle ultime 24h).
//   WA_TEMPLATE_LANG      lingua del template (default "it")
//   WA_LOOKUP_SECRET      segreto condiviso per l'uso come endpoint (già usato da
//                         ardy-wa-lookup.php / ardy-wa-memoria.php)
// -----------------------------------------------------------

date_default_timezone_set('Europe/Rome');

if (file_exists(__DIR__ . '/ardy-config.php')) {
    require_once __DIR__ . '/ardy-config.php';
}

// -----------------------------------------------------------
// Sanifica un testo per usarlo come parametro {{1}} di un template WhatsApp.
// Meta (err 132018) vieta nei parametri: a-capo, tab e 4+ spazi consecutivi.
// -----------------------------------------------------------
if (!function_exists('ardy_wa_template_param')) {
function ardy_wa_template_param(string $t): string {
    $t = str_replace(["\r\n", "\r"], "\n", $t);
    $t = preg_replace('/\n+/', ' · ', $t);  // a-capo (anche multipli) → separatore
    $t = str_replace("\t", ' ', $t);          // tab → spazio
    $t = preg_replace('/ {2,}/', ' ', $t);    // spazi multipli → uno (copre il limite dei 4)
    return trim($t);
}
}

// -----------------------------------------------------------
// Invio grezzo del messaggio a Michela via WhatsApp Cloud API
// -----------------------------------------------------------
if (!function_exists('ardy_wa_send_michela')) {
function ardy_wa_send_michela(string $messaggio): bool {
    if (!defined('WA_TOKEN') || !defined('WA_PHONE_NUMBER_ID') || !defined('WA_MICHELA_NUMBER')
        || WA_TOKEN === '' || WA_PHONE_NUMBER_ID === '' || WA_MICHELA_NUMBER === '') {
        error_log('ARDY NOTIFICA MICHELA: config WhatsApp mancante (WA_TOKEN / WA_PHONE_NUMBER_ID / WA_MICHELA_NUMBER)');
        return false;
    }

    $to        = preg_replace('/\D+/', '', (string) WA_MICHELA_NUMBER);
    $messaggio = trim($messaggio);
    if ($to === '' || $messaggio === '') return false;
    if (mb_strlen($messaggio) > 1000) $messaggio = mb_substr($messaggio, 0, 1000);

    if (defined('WA_TEMPLATE_NOTIFICA') && WA_TEMPLATE_NOTIFICA !== '') {
        // Template pre-approvato: aggira la finestra 24h. Body con una sola variabile {{1}}.
        // ⚠️ Meta rifiuta (err 132018) i parametri template con a-capo/tab o 4+ spazi:
        // appiattisco il testo (newline → " · ") prima di inviarlo.
        $payload = [
            'messaging_product' => 'whatsapp',
            'to'                => $to,
            'type'              => 'template',
            'template'          => [
                'name'       => WA_TEMPLATE_NOTIFICA,
                'language'   => ['code' => defined('WA_TEMPLATE_LANG') && WA_TEMPLATE_LANG !== '' ? WA_TEMPLATE_LANG : 'it'],
                'components'  => [[
                    'type'       => 'body',
                    'parameters' => [['type' => 'text', 'text' => ardy_wa_template_param($messaggio)]],
                ]],
            ],
        ];
    } else {
        // Testo libero: valido solo entro la finestra 24h dall'ultimo messaggio di Michela.
        $payload = [
            'messaging_product' => 'whatsapp',
            'to'                => $to,
            'type'              => 'text',
            'text'              => ['preview_url' => false, 'body' => $messaggio],
        ];
    }

    $url = 'https://graph.facebook.com/v21.0/' . rawurlencode((string) WA_PHONE_NUMBER_ID) . '/messages';
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . WA_TOKEN,
        ],
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err || $code >= 300) {
        error_log('ARDY NOTIFICA MICHELA ERROR: http=' . $code . ' err=' . $err . ' resp=' . $resp);
        return false;
    }
    return true;
}
}

// -----------------------------------------------------------
// Notifica Michela una sola volta per chiave (dedupe persistente).
//   $dedupeKey: es. "evt:<session>:<hash>" — se già inviata di recente, salta.
// Ritorna true se inviata, false se duplicata o errore d'invio.
// -----------------------------------------------------------
if (!function_exists('notificaMichela')) {
function notificaMichela(string $messaggio, string $dedupeKey = ''): bool {
    if ($dedupeKey !== '') {
        $dir = defined('ARDY_RATE_LIMIT_DIR') ? ARDY_RATE_LIMIT_DIR : (sys_get_temp_dir() . '/');
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $logFile = $dir . 'notifiche_michela.json';
        $log = file_exists($logFile) ? (json_decode((string) file_get_contents($logFile), true) ?: []) : [];
        $now = time();
        // Scarta le chiavi più vecchie di 7 giorni per non far crescere il file
        $log = array_filter($log, fn($t) => ($now - (int) $t) < 7 * 86400);
        if (isset($log[$dedupeKey])) return false; // già notificato
        $log[$dedupeKey] = $now;
        @file_put_contents($logFile, json_encode($log), LOCK_EX);
    }
    return ardy_wa_send_michela($messaggio);
}
}

// -----------------------------------------------------------
// Helper: data/ora in italiano (per i riepiloghi appuntamento)
// -----------------------------------------------------------
if (!function_exists('ardy_data_ita')) {
function ardy_data_ita(DateTime $dt): string {
    $giorni = ['Monday'=>'lunedì','Tuesday'=>'martedì','Wednesday'=>'mercoledì','Thursday'=>'giovedì','Friday'=>'venerdì','Saturday'=>'sabato','Sunday'=>'domenica'];
    $mesi   = ['January'=>'gennaio','February'=>'febbraio','March'=>'marzo','April'=>'aprile','May'=>'maggio','June'=>'giugno','July'=>'luglio','August'=>'agosto','September'=>'settembre','October'=>'ottobre','November'=>'novembre','December'=>'dicembre'];
    $gg = $giorni[$dt->format('l')] ?? '';
    $mm = $mesi[$dt->format('F')] ?? '';
    return trim(ucfirst($gg) . ' ' . $dt->format('j') . ' ' . $mm . ' ' . $dt->format('Y') . ' alle ' . $dt->format('H:i'));
}
}

// -----------------------------------------------------------
// MODALITÀ ENDPOINT — solo se il file è chiamato direttamente via HTTP
// -----------------------------------------------------------
if (PHP_SAPI !== 'cli'
    && isset($_SERVER['SCRIPT_FILENAME'])
    && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {

    header('Content-Type: application/json');

    // Protezione col segreto condiviso (come lookup/memoria)
    if (defined('WA_LOOKUP_SECRET') && WA_LOOKUP_SECRET !== '') {
        $sent = $_SERVER['HTTP_X_ARDY_SECRET'] ?? ($_GET['secret'] ?? '');
        if (!hash_equals(WA_LOOKUP_SECRET, (string) $sent)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'non autorizzato']);
            exit();
        }
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'metodo non consentito']);
        exit();
    }

    $in        = json_decode(file_get_contents('php://input'), true) ?: [];
    $messaggio = trim((string) ($in['messaggio'] ?? $in['message'] ?? ''));
    $dedupe    = trim((string) ($in['dedupe'] ?? ''));

    if ($messaggio === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'messaggio mancante']);
        exit();
    }

    $ok = notificaMichela($messaggio, $dedupe);
    echo json_encode(['success' => $ok]);
    exit();
}
