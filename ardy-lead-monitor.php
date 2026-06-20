<?php
// -----------------------------------------------------------
// ARDY LAB — Monitor portali lead (multi-portale via Gmail)
//
// Chiamato da n8n ogni 60 minuti via POST.
// Legge le email non lette dai portali lead, le classifica con
// Claude (punteggio 1-5) e notifica Michela su WhatsApp solo
// per i lead interessanti (score >= LEAD_MIN_SCORE).
//
// Protezione: header X-Ardy-Secret = WA_LOOKUP_SECRET
//
// Scope Gmail richiesti nel token Google (ardy-gcal-auth.php):
//   gmail.readonly   — lettura messaggi
//   gmail.modify     — marca letti + applica etichetta
// -----------------------------------------------------------

date_default_timezone_set('Europe/Rome');

require_once __DIR__ . '/ardy-config.php';
require_once __DIR__ . '/ardy-gcal.php';         // gcal_get_access_token()
require_once __DIR__ . '/ardy-notifica-michela.php';

// -----------------------------------------------------------
// Config
// -----------------------------------------------------------

// Punteggio minimo per notificare Michela (1-5)
define('LEAD_MIN_SCORE', 3);

// Nome etichetta Gmail da applicare alle email processate
define('GMAIL_LABEL_NAME', 'lead-processato');

// Portali attivi: mittente (sottostringa del From) → nome visualizzato
// Aggiungere nuovi portali qui senza toccare altro codice.
$PORTALI = [
    'prontopro.it'   => 'ProntoPro',
    'homedeal.it'    => 'Homedeal',
    'cronoshare.it'  => 'Cronoshare',
    'instapro.com'   => 'Instapro',
    'habitissimo.it' => 'Habitissimo',
];

// -----------------------------------------------------------
// Protezione endpoint
// -----------------------------------------------------------
header('Content-Type: application/json');

if (PHP_SAPI !== 'cli') {
    // Fail-closed: senza segreto configurato l'endpoint HTTP NON è accessibile
    // (la modalità CLI/cron resta esente).
    if (!defined('WA_LOOKUP_SECRET') || WA_LOOKUP_SECRET === '') {
        error_log('ARDY LEAD MONITOR: WA_LOOKUP_SECRET non configurato — richiesta rifiutata');
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'configurazione mancante']);
        exit();
    }
    $sent = $_SERVER['HTTP_X_ARDY_SECRET'] ?? ($_GET['secret'] ?? '');
    if (!hash_equals(WA_LOOKUP_SECRET, (string) $sent)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'non autorizzato']);
        exit();
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'metodo non consentito']);
        exit();
    }
}

// Modalità debug (?debug=1): riprende le email recenti ignorando lo stato
// letto/etichetta, classifica con Claude ma NON marca come processate e NON
// invia WhatsApp. Serve solo per testare la classificazione senza effetti.
$DEBUG = !empty($_GET['debug']) || !empty($_POST['debug']);

// -----------------------------------------------------------
// Ottieni access token Google (riusa quello del Calendar)
// -----------------------------------------------------------
$accessToken = gcal_get_access_token();
if (!$accessToken) {
    error_log('ARDY LEAD MONITOR: impossibile ottenere access token Google');
    echo json_encode(['ok' => false, 'error' => 'token Google non disponibile']);
    exit();
}

// -----------------------------------------------------------
// Gmail helpers
// -----------------------------------------------------------

function gmail_request(string $url, string $accessToken, array $opts = []): ?array {
    $method  = $opts['method']  ?? 'GET';
    $payload = $opts['payload'] ?? null;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER     => array_merge(
            ['Authorization: Bearer ' . $accessToken],
            $payload ? ['Content-Type: application/json'] : []
        ),
    ]);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($payload !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    } elseif ($method !== 'GET') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        if ($payload !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    }

    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err) {
        error_log('ARDY LEAD MONITOR: curl error ' . $err . ' — ' . $url);
        return null;
    }
    if ($code >= 400) {
        error_log('ARDY LEAD MONITOR: HTTP ' . $code . ' — ' . substr($resp, 0, 300));
        return null;
    }
    return json_decode($resp, true);
}

// Ottieni (o crea) il label Gmail "lead-processato"; ritorna l'ID label.
function gmail_get_or_create_label(string $accessToken): ?string {
    $base   = 'https://gmail.googleapis.com/gmail/v1/users/me/labels';
    $labels = gmail_request($base, $accessToken);
    if (!$labels) return null;

    foreach ($labels['labels'] ?? [] as $l) {
        if (strtolower($l['name']) === GMAIL_LABEL_NAME) return $l['id'];
    }

    // Non esiste: lo creiamo
    $new = gmail_request($base, $accessToken, [
        'method'  => 'POST',
        'payload' => [
            'name'                  => GMAIL_LABEL_NAME,
            'labelListVisibility'   => 'labelShow',
            'messageListVisibility' => 'show',
        ],
    ]);
    return $new['id'] ?? null;
}

// Cerca messaggi da un mittente specifico (max 10 per portale).
// Normale: solo non letti e non ancora processati.
// Debug: email recenti (14gg) a prescindere da stato letto/etichetta.
function gmail_list_unread(string $accessToken, string $fromDomain, bool $debug = false): array {
    $q   = $debug
        ? 'from:' . $fromDomain . ' newer_than:14d'
        : 'from:' . $fromDomain . ' is:unread -label:' . GMAIL_LABEL_NAME;
    $url = 'https://gmail.googleapis.com/gmail/v1/users/me/messages?maxResults=10&q=' . urlencode($q);
    $res = gmail_request($url, $accessToken);
    return $res['messages'] ?? [];
}

// Leggi il messaggio completo e restituisci [subject, body_text, from].
function gmail_get_message(string $accessToken, string $msgId): ?array {
    $url = 'https://gmail.googleapis.com/gmail/v1/users/me/messages/' . $msgId . '?format=full';
    $msg = gmail_request($url, $accessToken);
    if (!$msg) return null;

    $headers = [];
    foreach (($msg['payload']['headers'] ?? []) as $h) {
        $headers[strtolower($h['name'])] = $h['value'];
    }

    $body = gmail_extract_body($msg['payload'] ?? []);

    return [
        'id'      => $msgId,
        'from'    => $headers['from']    ?? '',
        'subject' => $headers['subject'] ?? '(senza oggetto)',
        'body'    => $body,
    ];
}

// Estrae il testo del body (preferisce text/plain, fallback da HTML strip).
// Le email dei portali sono quasi sempre multipart nested:
// multipart/mixed → multipart/alternative → text/plain | text/html
// Raccogliamo ricorsivamente TUTTI i candidati prima di scegliere.
function gmail_extract_body(array $payload): string {
    $candidates = [];
    gmail_collect_parts($payload, $candidates);

    // Preferenza: text/plain prima di text/html
    foreach ($candidates as $c) {
        if ($c['mime'] === 'text/plain' && trim($c['text']) !== '') return $c['text'];
    }
    foreach ($candidates as $c) {
        if (strpos($c['mime'], 'text/html') !== false && trim($c['text']) !== '') {
            return gmail_html_to_text($c['text']);
        }
    }
    return '';
}

// Raccoglie ricorsivamente tutte le parti con contenuto testuale.
function gmail_collect_parts(array $part, array &$out): void {
    $mime = $part['mimeType'] ?? '';
    $data = $part['body']['data'] ?? '';

    if ($data !== '') {
        $decoded = base64_decode(strtr($data, '-_', '+/'));
        if ($decoded !== false) {
            $out[] = ['mime' => $mime, 'text' => $decoded];
        }
    }

    foreach ($part['parts'] ?? [] as $sub) {
        gmail_collect_parts($sub, $out);
    }
}

// Converte HTML email in testo leggibile (meglio di strip_tags grezzo).
function gmail_html_to_text(string $html): string {
    // Rimuovi style/script prima di tutto
    $html = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $html);
    $html = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $html);
    // <br>, </p>, </div>, </tr>, </li> → newline
    $html = preg_replace('/<br\s*\/?>/i', "\n", $html);
    $html = preg_replace('/<\/(p|div|tr|li|h[1-6])>/i', "\n", $html);
    // Strip tag rimanenti
    $text = strip_tags($html);
    // Decodifica entità HTML
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    // Comprimi spazi bianchi multipli (ma preserva newline)
    $text = preg_replace('/[ \t]+/', ' ', $text);
    $text = preg_replace('/\n{3,}/', "\n\n", $text);
    return trim($text);
}

// Marca il messaggio come letto e applica il label.
function gmail_mark_processed(string $accessToken, string $msgId, string $labelId): void {
    $url     = 'https://gmail.googleapis.com/gmail/v1/users/me/messages/' . $msgId . '/modify';
    $payload = [
        'addLabelIds'    => [$labelId],
        'removeLabelIds' => ['UNREAD'],
    ];
    gmail_request($url, $accessToken, ['method' => 'POST', 'payload' => $payload]);
}

// -----------------------------------------------------------
// Claude: scoring lead
// -----------------------------------------------------------

function classify_lead(string $portale, string $subject, string $body): array {
    $apiKey = defined('ARDY_API_KEY') ? ARDY_API_KEY : '';
    if (!$apiKey) {
        error_log('ARDY LEAD MONITOR: ARDY_API_KEY mancante');
        return ['score' => 0, 'motivo' => 'API key mancante'];
    }

    // Tronca il body per non sprecare token
    $bodyTrunc = mb_substr(trim($body), 0, 1200);

    $prompt = <<<PROMPT
Sei un assistente che valuta le richieste di lavoro ricevute via email da portali lead (ProntoPro, Homedeal ecc.) per un laboratorio artigianale di restauro mobili a Roma chiamato Ardy Lab.

Il laboratorio esegue: restauro mobili antichi/classici, laccatura, verniciatura, lucidatura a tampone, sverniciatura. NON fa: tappezzeria/rivestimento sedute, montaggio Ikea, falegnameria generica da costruzione, traslochi, pulizie.

Zona servita: Roma e dintorni, max ~30 km (Castelli Romani, Tivoli, Guidonia, Fiumicino, Ostia). Zone oltre i 40 km o fuori Lazio = scartare.

Valuta questa email dal portale {$portale}:

<oggetto>{$subject}</oggetto>
<body>{$bodyTrunc}</body>

Rispondi SOLO con JSON valido (niente testo fuori):
{
  "score": <intero 1-5>,
  "tipo_lavoro": "<tipo lavoro estratto, max 40 caratteri>",
  "zona": "<zona/distanza estratta o dedotta, max 30 caratteri>",
  "motivo": "<motivazione 1-2 righe del punteggio>"
}

Punteggio:
5 = lavoro perfetto per il laboratorio, zona ottima
4 = buona corrispondenza
3 = possibile, vale un'occhiata
2 = lavoro parzialmente pertinente o zona ai limiti
1 = non pertinente (montaggio, falegnameria generica, zona troppo lontana, ecc.)
PROMPT;

    $payload = [
        'model'      => 'claude-haiku-4-5-20251001',
        'max_tokens' => 200,
        'messages'   => [
            ['role' => 'user', 'content' => $prompt],
        ],
    ];

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
        ],
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err || $code >= 400) {
        error_log('ARDY LEAD MONITOR: Claude error http=' . $code . ' err=' . $err);
        return ['score' => 0, 'motivo' => 'errore API Claude'];
    }

    $data    = json_decode($resp, true);
    $content = trim($data['content'][0]['text'] ?? '');

    // Estrai JSON dalla risposta (Claude a volte aggiunge testo extra)
    if (preg_match('/\{[\s\S]*\}/', $content, $m)) {
        $result = json_decode($m[0], true);
        if ($result && isset($result['score'])) return $result;
    }

    error_log('ARDY LEAD MONITOR: risposta Claude non parsabile: ' . $content);
    return ['score' => 0, 'tipo_lavoro' => '', 'zona' => '', 'motivo' => 'risposta non parsabile'];
}

// -----------------------------------------------------------
// Formatta il messaggio WA per Michela
// -----------------------------------------------------------

function format_notifica(string $portale, string $subject, array $cl): string {
    $stelle = str_repeat('⭐', max(1, (int) $cl['score']));
    $tipo   = $cl['tipo_lavoro'] ?? '';
    $zona   = $cl['zona']        ?? '';
    $motivo = $cl['motivo']      ?? '';

    $riga1 = "🔔 *{$portale}*: " . ($tipo ?: $subject);
    if ($zona) $riga1 .= " · {$zona}";
    $riga1 .= " {$stelle}";

    return $riga1 . ($motivo ? "\n_{$motivo}_" : '');
}

// -----------------------------------------------------------
// MAIN
// -----------------------------------------------------------

$labelId = gmail_get_or_create_label($accessToken);
if (!$labelId) {
    error_log('ARDY LEAD MONITOR: impossibile ottenere/creare label Gmail');
    echo json_encode(['ok' => false, 'error' => 'label Gmail non disponibile']);
    exit();
}

$totali     = 0;
$notificati = 0;
$saltati    = 0;
$log        = [];

foreach ($PORTALI as $dominio => $nomePortale) {
    $messaggi = gmail_list_unread($accessToken, $dominio, $DEBUG);
    if (empty($messaggi)) continue;

    error_log('ARDY LEAD MONITOR: ' . count($messaggi) . ' email da ' . $nomePortale . ($DEBUG ? ' [DEBUG]' : ''));

    foreach ($messaggi as $m) {
        $msgId = $m['id'];
        $totali++;

        $email = gmail_get_message($accessToken, $msgId);
        if (!$email) {
            error_log('ARDY LEAD MONITOR: impossibile leggere msg ' . $msgId);
            // Marca comunque letta per non riprocessare (non in debug)
            if (!$DEBUG) gmail_mark_processed($accessToken, $msgId, $labelId);
            $saltati++;
            continue;
        }

        $cl = classify_lead($nomePortale, $email['subject'], $email['body']);

        error_log(sprintf(
            'ARDY LEAD MONITOR: [%s] score=%d tipo="%s" zona="%s" — "%s"',
            $nomePortale, $cl['score'], $cl['tipo_lavoro'] ?? '', $cl['zona'] ?? '', $email['subject']
        ));

        $entry = [
            'portale'    => $nomePortale,
            'subject'    => $email['subject'],
            'score'      => $cl['score'],
            'tipo'       => $cl['tipo_lavoro'] ?? '',
            'zona'       => $cl['zona'] ?? '',
            'motivo'     => $cl['motivo'] ?? '',
            'notificata' => false,
        ];

        if ((int) $cl['score'] >= LEAD_MIN_SCORE) {
            if ($DEBUG) {
                // Debug: non inviare, mostra solo cosa sarebbe stato mandato
                $entry['anteprima_wa'] = format_notifica($nomePortale, $email['subject'], $cl);
            } else {
                $testo     = format_notifica($nomePortale, $email['subject'], $cl);
                $dedupeKey = 'lead:' . $msgId;
                if (notificaMichela($testo, $dedupeKey)) {
                    $notificati++;
                    $entry['notificata'] = true;
                }
            }
        } else {
            $saltati++;
        }

        // Marca processata (letta + label) solo in produzione, mai in debug
        if (!$DEBUG) gmail_mark_processed($accessToken, $msgId, $labelId);
        $log[] = $entry;
    }
}

echo json_encode([
    'ok'         => true,
    'debug'      => $DEBUG,
    'processate' => $totali,
    'notificate' => $notificati,
    'saltate'    => $saltati,
    'dettaglio'  => $log,
], JSON_UNESCAPED_UNICODE);
