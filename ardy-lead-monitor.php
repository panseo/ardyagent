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
    if (defined('WA_LOOKUP_SECRET') && WA_LOOKUP_SECRET !== '') {
        $sent = $_SERVER['HTTP_X_ARDY_SECRET'] ?? ($_GET['secret'] ?? '');
        if (!hash_equals(WA_LOOKUP_SECRET, (string) $sent)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'non autorizzato']);
            exit();
        }
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'metodo non consentito']);
        exit();
    }
}

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

// Cerca messaggi non letti da un mittente specifico (max 10 per portale).
function gmail_list_unread(string $accessToken, string $fromDomain): array {
    $q   = 'from:' . $fromDomain . ' is:unread -label:' . GMAIL_LABEL_NAME;
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
function gmail_extract_body(array $payload): string {
    // Parte singola
    if (isset($payload['body']['data']) && $payload['body']['data'] !== '') {
        $raw  = base64_decode(strtr($payload['body']['data'], '-_', '+/'));
        $mime = $payload['mimeType'] ?? '';
        if (strpos($mime, 'text/html') !== false) return strip_tags($raw);
        return $raw;
    }

    // Multipart: cerca text/plain prima, poi text/html
    $parts = $payload['parts'] ?? [];
    $html  = '';
    foreach ($parts as $part) {
        $mime = $part['mimeType'] ?? '';
        $data = $part['body']['data'] ?? '';
        if (!$data && !empty($part['parts'])) {
            // nested multipart
            $nested = gmail_extract_body($part);
            if ($nested !== '') return $nested;
            continue;
        }
        if (!$data) continue;
        $decoded = base64_decode(strtr($data, '-_', '+/'));
        if ($mime === 'text/plain') return $decoded;
        if (strpos($mime, 'text/html') !== false) $html = $decoded;
    }
    return $html !== '' ? strip_tags($html) : '';
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
    $apiKey = defined('ANTHROPIC_API_KEY') ? ANTHROPIC_API_KEY : '';
    if (!$apiKey) {
        error_log('ARDY LEAD MONITOR: ANTHROPIC_API_KEY mancante');
        return ['score' => 0, 'motivo' => 'API key mancante'];
    }

    // Tronca il body per non sprecare token
    $bodyTrunc = mb_substr(trim($body), 0, 1200);

    $prompt = <<<PROMPT
Sei un assistente che valuta le richieste di lavoro ricevute via email da portali lead (ProntoPro, Homedeal ecc.) per un laboratorio artigianale di restauro mobili a Roma chiamato Ardy Lab.

Il laboratorio esegue: restauro mobili antichi/classici, laccatura, verniciatura, rivestimento (sedute/testiere), lucidatura a tampone, sverniciatura. NON fa: montaggio Ikea, falegnameria generica da costruzione, traslochi, pulizie.

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
    $messaggi = gmail_list_unread($accessToken, $dominio);
    if (empty($messaggi)) continue;

    error_log('ARDY LEAD MONITOR: ' . count($messaggi) . ' email da ' . $nomePortale);

    foreach ($messaggi as $m) {
        $msgId = $m['id'];
        $totali++;

        $email = gmail_get_message($accessToken, $msgId);
        if (!$email) {
            error_log('ARDY LEAD MONITOR: impossibile leggere msg ' . $msgId);
            // Marca comunque letta per non riprocessare
            gmail_mark_processed($accessToken, $msgId, $labelId);
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
            'notificata' => false,
        ];

        if ((int) $cl['score'] >= LEAD_MIN_SCORE) {
            $testo    = format_notifica($nomePortale, $email['subject'], $cl);
            $dedupeKey = 'lead:' . $msgId;
            $inviato  = notificaMichela($testo, $dedupeKey);
            if ($inviato) {
                $notificati++;
                $entry['notificata'] = true;
            }
        } else {
            $saltati++;
        }

        // Marca sempre come processata (letta + label) anche se score basso
        gmail_mark_processed($accessToken, $msgId, $labelId);
        $log[] = $entry;
    }
}

echo json_encode([
    'ok'         => true,
    'processate' => $totali,
    'notificate' => $notificati,
    'saltate'    => $saltati,
    'dettaglio'  => $log,
]);
