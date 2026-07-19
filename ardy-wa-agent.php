<?php
// -----------------------------------------------------------
// ARDY LAB — Cervello WhatsApp lato cliente (tool calendario)
//
// PIANO B (un solo cervello, n8n = tubo). Per i canali CLIENTE su WhatsApp
// (lead / cliente / cliente_lavorazione / lead_portale) Sole ha ora i tool
// VERI del calendario, con lo stesso loop agentico del sito (ardy-proxy.php):
//   - ottieni_disponibilita_calendario  → legge gli slot liberi
//   - fissa_appuntamento_calendario      → fissa il sopralluogo (solo su conferma)
// Così Sole può proporre 2 slot reali e chiudere l'appuntamento, invece di
// "recitare" la sintassi di un tool che su WhatsApp non esisteva (caso Alberto).
//
// Anche la modalità TITOLARE (staff) passa ora di qui, con un set di tool "_staff"
// dedicati (ricerca/creazione scheda, calendario per nome, nota settimanale). Il solo
// marker n8n rimasto è [[CONTATTA_LEAD]] (primo contatto a freddo); la creazione scheda
// è ora un tool VERO (crea_scheda_cliente), sincrona, così Sole crea e prenota nello
// stesso giro senza aspettare nessuna "sincronizzazione".
//
// Chiamato server-to-server da n8n. Riusa: lookup (system già pronto), memoria
// e invio a WhatsApp restano in n8n. Qui si fa SOLO il loop con i tool.
// Auth: header X-Ardy-Secret == WA_LOOKUP_SECRET (come gli altri endpoint WA).
// -----------------------------------------------------------

date_default_timezone_set('Europe/Rome');

require_once __DIR__ . '/ardy-config.php';
require_once __DIR__ . '/ardy-db.php';
require_once __DIR__ . '/ardy-gcal.php';
require_once __DIR__ . '/ardy-sopralluoghi-lib.php';  // sopralluoghi multipli (staff): lista/salva/elimina + mirror
require_once __DIR__ . '/ardy-net.php';       // ardyCompressImage() per le foto WhatsApp
require_once __DIR__ . '/ardy-sanitize.php';
require_once __DIR__ . '/ardy-notifica-michela.php';
require_once __DIR__ . '/ardy-email.php';
require_once __DIR__ . '/phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/phpmailer/src/SMTP.php';
require_once __DIR__ . '/phpmailer/src/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;

header('Content-Type: application/json');

// ── Auth (stesso segreto condiviso degli altri endpoint WhatsApp) ──
// Fail-closed: senza segreto configurato l'endpoint NON è accessibile (non
// fidarsi del fatto che il config sia presente). Stesso pattern del webhook.
if (!defined('WA_LOOKUP_SECRET') || WA_LOOKUP_SECRET === '') {
    error_log('ARDY WA AGENT: WA_LOOKUP_SECRET non configurato — richiesta rifiutata');
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'configurazione mancante']);
    exit();
}
$sent = $_SERVER['HTTP_X_ARDY_SECRET'] ?? '';
if (!hash_equals(WA_LOOKUP_SECRET, (string) $sent)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'non autorizzato']);
    exit();
}

// ── Input ──
$in        = json_decode(file_get_contents('php://input'), true) ?: [];
$system    = (string) ($in['system'] ?? '');
$messages  = is_array($in['messages'] ?? null) ? $in['messages'] : [];
$sessionId = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) ($in['session_id'] ?? ''));
$cliente   = is_array($in['cliente'] ?? null) ? $in['cliente'] : [];
$phone     = preg_replace('/\D+/', '', (string) ($in['phone'] ?? ''));   // numero WhatsApp del mittente
$mediaId   = trim((string) ($in['media_id'] ?? ''));                     // id foto WhatsApp (se presente)
$staff     = !empty($in['staff']);                                       // true se chiamato dal canale TITOLARE (staff)
// Cartella di storage foto = quella della scheda. Per un lead nuovo (senza
// scheda ancora) usiamo l'id deterministico dal telefono: combacia con quello
// che genererà ardy-wa-crea-scheda.php → le foto si agganciano alla scheda.
$cardSession = $sessionId !== '' ? $sessionId : ($phone !== '' ? 'wa-' . substr(md5($phone), 0, 16) : '');

// Trascrizione leggibile della conversazione (per la notifica a Michela), dai
// messaggi in ingresso PRIMA che il loop li arricchisca con i blocchi tool.
$transcript = '';
foreach ($messages as $mm) {
    if (isset($mm['role']) && is_string($mm['content'] ?? null) && $mm['content'] !== '') {
        $lab = strtoupper((string) $mm['role']) === 'USER' ? 'CLIENTE' : 'SOLE';
        $transcript .= $lab . ': ' . $mm['content'] . "\n\n";
    }
}

// Dati lead per le email (default dal contesto lookup; aggiornati se Sole salva la scheda).
$leadNome    = trim((string) ($cliente['nome']  ?? ''));
$leadZona    = trim((string) ($cliente['zona']  ?? ''));
$leadEmail   = trim((string) ($cliente['email'] ?? ''));
$leadCreated = false;     // true se è stata creata una scheda NUOVA in questo giro
$accessCode  = null;      // codice di accesso da inviare al lead via email
$accessEmail = null;      // email a cui inviare il codice

if ($system === '' || empty($messages)) {
    echo json_encode(['success' => false, 'reply' => 'Scusa, ora non riesco a rispondere. Ti ricontatto a breve.']);
    exit();
}

// -----------------------------------------------------------
// Chiamata ad Anthropic (identica al sito: prompt caching su system + tools)
// -----------------------------------------------------------
function waCallAnthropic(array $messages, string $system, array $tools, string $apiKey): array {
    $systemPayload = [
        ['type' => 'text', 'text' => $system, 'cache_control' => ['type' => 'ephemeral']]
    ];
    $cachedTools = $tools;
    if (!empty($cachedTools)) {
        $cachedTools[count($cachedTools) - 1]['cache_control'] = ['type' => 'ephemeral'];
    }
    $payload = json_encode([
        'model'      => 'claude-sonnet-4-6',
        'max_tokens' => 1000,
        'system'     => $systemPayload,
        'tools'      => $cachedTools,
        'messages'   => $messages,
    ]);
    // Fino a 3 tentativi: gli errori TRANSITORI di Anthropic (429 rate limit,
    // 529 overloaded, 5xx) sono comuni e passeggeri → riprova con breve attesa,
    // invece di mostrare subito "ora non riesco a rispondere". Gli errori di
    // richiesta (4xx ≠ 429) NON si ritentano: si logga il motivo per capirli.
    $attempts = 0;
    while (true) {
        $attempts++;
        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt($ch, CURLOPT_POST,           true);
        curl_setopt($ch, CURLOPT_POSTFIELDS,     $payload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT,        120);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTP_VERSION,   CURL_HTTP_VERSION_1_1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Connection: close',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
            'anthropic-beta: prompt-caching-2024-07-31',
        ]);
        $response = curl_exec($ch);
        $code     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err      = curl_error($ch);
        curl_close($ch);

        if ($err) {
            error_log('ARDY WA-AGENT CURL ERROR: ' . $err);
            return ['error' => 'curl', 'message' => $err];
        }
        $decoded = json_decode($response, true);
        // Risposta valida (200, niente blocco "error") → ritornala.
        if (is_array($decoded) && $code < 300 && (($decoded['type'] ?? '') !== 'error')) {
            return $decoded;
        }
        // Errore: logga SEMPRE il motivo (così è diagnosticabile dal log).
        error_log('ARDY WA-AGENT ANTHROPIC ERROR http=' . $code . ' resp=' . substr((string) $response, 0, 600));
        // Ritenta solo gli errori transitori.
        if ($attempts < 3 && ($code === 429 || $code === 529 || $code >= 500)) {
            usleep(900000 * $attempts);   // 0,9s · 1,8s
            continue;
        }
        return is_array($decoded) ? ($decoded + ['error' => 'api']) : ['error' => 'json'];
    }
}

// PHPMailer preconfigurato su Brevo (stesse impostazioni di ardy-proxy.php).
function waNewMailer(): PHPMailer {
    $m = new PHPMailer(true);
    $m->isSMTP();
    $m->Host       = 'smtp-relay.brevo.com';
    $m->SMTPAuth   = true;
    $m->Username   = ARDY_SMTP_USER;
    $m->Password   = ARDY_SMTP_PASSWORD;
    $m->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $m->Port       = 587;
    $m->CharSet    = 'UTF-8';
    return $m;
}

// Codice di accesso ARD-XXXX-XXXX (alfabeto senza caratteri ambigui) — come ardy-proxy.php.
function waGeneraCodiceAccesso(): string {
    $alpha = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $n = strlen($alpha);
    $out = '';
    for ($i = 0; $i < 8; $i++) {
        $out .= $alpha[random_int(0, $n - 1)];
        if ($i === 3) $out .= '-';
    }
    return 'ARD-' . $out;
}

function waEnsureCodiceCol(PDO $db): void {
    // colonne garantite da ardy-migrate.php
}

// Scarica una foto WhatsApp via Cloud API: media id → url firmato → byte.
// Richiede WA_TOKEN (Bearer). Ritorna [bytes, mime] oppure null.
function waScaricaMediaWhatsApp(string $mediaId): ?array {
    if (!defined('WA_TOKEN') || WA_TOKEN === '') {
        error_log('ARDY WA-AGENT MEDIA: WA_TOKEN mancante');
        return null;
    }
    // 1) media id → metadati (url firmato, breve durata)
    $ch = curl_init('https://graph.facebook.com/v21.0/' . urlencode($mediaId));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT,        20);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . WA_TOKEN]);
    $res = curl_exec($ch);
    curl_close($ch);
    $meta = json_decode((string) $res, true);
    $url  = is_array($meta) ? ($meta['url'] ?? '') : '';
    if ($url === '') {
        error_log('ARDY WA-AGENT MEDIA: url mancante per ' . $mediaId . ' res=' . substr((string) $res, 0, 200));
        return null;
    }
    // 2) scarica i byte (stesso Bearer richiesto anche sull'url CDN)
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT,        30);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . WA_TOKEN]);
    $bytes = curl_exec($ch);
    $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || !is_string($bytes) || strlen($bytes) < 12) {
        error_log('ARDY WA-AGENT MEDIA: download fallito HTTP ' . $code);
        return null;
    }
    return [$bytes, (string) ($meta['mime_type'] ?? '')];
}

function waEnsureSopralluogoCols(PDO $db): void {
    // colonne garantite da ardy-migrate.php
}

// ── STAFF (titolare): trova le schede attive per nome/cognome/"nome cognome".
function waTrovaSchedePerNome(PDO $db, string $nome): array {
    $q = trim($nome);
    if ($q === '') return [];
    $like = '%' . $q . '%';
    $st = $db->prepare(
        "SELECT session_id, nome, cognome, zona, stato, email, sopralluogo_at, gcal_event_id
           FROM clienti
          WHERE deleted_at IS NULL
            AND (nome LIKE :q OR cognome LIKE :q OR CONCAT(COALESCE(nome,''),' ',COALESCE(cognome,'')) LIKE :q)
       ORDER BY updated_at DESC, id DESC
          LIMIT 10"
    );
    $st->execute([':q' => $like]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

// Risolve la scheda per i tool staff. Ritorna [stato, payload]:
//   ['ok', $card]       → una sola scheda (o session_id già fornito dal modello)
//   ['none', null]      → nessuna scheda
//   ['ambiguo', $cards] → più schede con quel nome: vanno disambiguate.
function waRisolviScheda(PDO $db, string $nome, string $sessionIdHint): array {
    $sessionIdHint = preg_replace('/[^a-zA-Z0-9_\-]/', '', $sessionIdHint);
    if ($sessionIdHint !== '') {
        $st = $db->prepare(
            "SELECT session_id, nome, cognome, zona, stato, email, sopralluogo_at, gcal_event_id
               FROM clienti WHERE session_id = :sid AND deleted_at IS NULL LIMIT 1"
        );
        $st->execute([':sid' => $sessionIdHint]);
        $c = $st->fetch(PDO::FETCH_ASSOC);
        return $c ? ['ok', $c] : ['none', null];
    }
    $cards = waTrovaSchedePerNome($db, $nome);
    if (count($cards) === 0) return ['none', null];
    if (count($cards) === 1) return ['ok', $cards[0]];
    return ['ambiguo', $cards];
}

// Lista leggibile delle schede candidate (per far disambiguare il modello/lo staff).
function waFormattaSchedeAmbigue(array $cards): string {
    $lines = [];
    foreach ($cards as $c) {
        $nm = trim(($c['nome'] ?? '') . ' ' . ($c['cognome'] ?? ''));
        $extra = [];
        if (!empty($c['zona']))           $extra[] = 'zona ' . $c['zona'];
        if (!empty($c['stato']))          $extra[] = $c['stato'];
        if (!empty($c['sopralluogo_at'])) $extra[] = 'sopralluogo ' . date('d/m/Y H:i', strtotime((string) $c['sopralluogo_at']));
        $lines[] = '- ' . ($nm !== '' ? $nm : '(senza nome)')
                 . ($extra ? ' (' . implode(', ', $extra) . ')' : '')
                 . ' · session_id=' . $c['session_id'];
    }
    return implode("\n", $lines);
}

// Lista leggibile dei sopralluoghi di un cliente (per elencare/disambiguare).
function waFormattaSopralluoghi(array $rows): string {
    if (empty($rows)) return '(nessun sopralluogo)';
    $lines = [];
    foreach ($rows as $r) {
        $quando = !empty($r['data_ora']) ? date('d/m/Y \a\l\l\e H:i', strtotime((string) $r['data_ora'])) : '(senza data)';
        $lines[] = '- ' . ($r['etichetta'] ?: 'Sopralluogo') . ': ' . $quando . ' · sopralluogo_id=' . $r['id'];
    }
    return implode("\n", $lines);
}

// -----------------------------------------------------------
// Tool calendario (stesse definizioni del sito — lettura + prenotazione)
// -----------------------------------------------------------
$tools = [
    [
        'name'        => 'ottieni_disponibilita_calendario',
        'description' => 'Controlla la disponibilità del calendario di Ardy Lab in una finestra temporale. Usalo per proporre al cliente 2 finestre orarie disponibili per un sopralluogo o appuntamento.',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'start' => ['type' => 'string', 'description' => 'Data e ora inizio, ISO 8601. Es: 2026-06-10T09:00:00+02:00'],
                'end'   => ['type' => 'string', 'description' => 'Data e ora fine, ISO 8601. Es: 2026-06-10T18:00:00+02:00'],
            ],
            'required' => ['start', 'end'],
        ],
    ],
    [
        'name'        => 'fissa_appuntamento_calendario',
        'description' => 'Crea un evento nel calendario di Ardy Lab. Usalo solo dopo conferma esplicita del cliente.',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'start'       => ['type' => 'string', 'description' => 'Data e ora inizio, ISO 8601'],
                'end'         => ['type' => 'string', 'description' => 'Data e ora fine, ISO 8601'],
                'summary'     => ['type' => 'string', 'description' => 'Titolo evento. Es: Sopralluogo Ardy Lab — Prati / Mario Rossi'],
                'description' => ['type' => 'string', 'description' => 'Scheda completa: nome, servizio, mobile, condizioni, zona, email, indirizzo, budget, note.'],
            ],
            'required' => ['start', 'end', 'summary', 'description'],
        ],
    ],
    [
        'name'        => 'salva_lead_crm',
        'description' => 'Salva i dati del cliente nel CRM. Usalo quando un nuovo contatto ti ha fornito i suoi dati (almeno il nome e un recapito) e di cosa ha bisogno, così il contatto non si perde e Michela lo ritrova. Se non ti ha dato il telefono va bene: useremo in automatico il suo numero WhatsApp.',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'nome'      => ['type' => 'string', 'description' => 'Nome del cliente'],
                'cognome'   => ['type' => 'string', 'description' => 'Cognome del cliente'],
                'telefono'  => ['type' => 'string', 'description' => 'Numero di telefono (se non lo dà, lascia vuoto: usiamo il suo numero WhatsApp)'],
                'email'     => ['type' => 'string', 'description' => 'Email del cliente'],
                'indirizzo' => ['type' => 'string', 'description' => 'Indirizzo completo: via, numero, piano, citofono, città'],
                'servizio'  => ['type' => 'string', 'description' => 'Tipo di servizio richiesto'],
                'mobile'    => ['type' => 'string', 'description' => 'Descrizione del mobile o pezzo'],
                'zona'      => ['type' => 'string', 'description' => 'Zona o città del cliente'],
                'budget'    => ['type' => 'string', 'description' => 'Forbice di budget comunicata'],
                'stato'     => ['type' => 'string', 'description' => 'Stato: LEAD, SOPRALLUOGO, PREVENTIVO, ACCONTO, RITIRATI (mobile ritirato, in laboratorio, lavori non avviati), IN_LAVORAZIONE, STANDBY, PERSO'],
                'note'      => ['type' => 'string', 'description' => 'Note aggiuntive per Michela'],
            ],
            'required' => ['nome', 'stato'],
        ],
    ],
    [
        'name'        => 'sposta_appuntamento',
        'description' => 'Sposta un sopralluogo GIÀ fissato a una nuova data/ora. Usalo quando il cliente con cui stai parlando, che ha già un appuntamento, chiede di spostarlo. Prima verifica la disponibilità del nuovo periodo con ottieni_disponibilita_calendario e fatti confermare un orario preciso; poi chiama questo strumento. L\'appuntamento viene identificato in automatico dal numero WhatsApp del cliente.',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'start' => ['type' => 'string', 'description' => 'Nuova data e ora di inizio, ISO 8601. Es: 2026-06-20T11:00:00+02:00'],
            ],
            'required' => ['start'],
        ],
    ],
];

// -----------------------------------------------------------
// MODALITÀ STAFF (titolare): Sole NON parla con un cliente ma con lo staff, e
// agisce sul calendario PER CONTO di un cliente NOMINATO nel CRM. Quindi i tool
// non sono quelli legati al numero WhatsApp di chi scrive (che qui è lo staff),
// ma versioni "_staff" che identificano il cliente per nome (con disambiguazione
// in caso di omonimi). La creazione scheda è il tool crea_scheda_cliente (sincrono):
// solo il primo contatto a freddo del lead resta sul marker n8n [[CONTATTA_LEAD]].
// -----------------------------------------------------------
if ($staff) {
    $tools = [
        $tools[0],   // ottieni_disponibilita_calendario — identico al canale cliente
        [
            'name'        => 'cerca_scheda_cliente',
            'description' => 'Cerca nel CRM le schede cliente per nome o cognome. Usalo per controllare di star agendo sul cliente giusto, o quando ci sono possibili omonimi. Ritorna le schede trovate con il loro session_id, da riusare con fissa_appuntamento_staff / sposta_appuntamento_staff per disambiguare.',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'nome' => ['type' => 'string', 'description' => 'Nome (o nome e cognome) del cliente da cercare.'],
                ],
                'required' => ['nome'],
            ],
        ],
        [
            'name'        => 'crea_scheda_cliente',
            'description' => 'CREA (o aggiorna) una scheda cliente nel CRM quando lo staff ti detta un nuovo contatto. Salva SUBITO e in modo definitivo: la scheda è disponibile all\'ISTANTE, non c\'è nessuna sincronizzazione da aspettare. Ritorna il session_id della scheda: riusalo SUBITO con fissa_appuntamento_staff (campo session_id) per fissare l\'appuntamento nello stesso giro, senza doverla ricercare. Usalo solo DOPO che lo staff ha confermato i dati. Se rifai la stessa scheda (stesso telefono o stesso nome) non crei un doppione, la aggiorni.',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'nome'      => ['type' => 'string', 'description' => 'Nome del cliente.'],
                    'cognome'   => ['type' => 'string', 'description' => 'Cognome del cliente.'],
                    'telefono'  => ['type' => 'string', 'description' => 'Telefono del cliente (quello che ti detta lo staff). NON è il numero di chi ti sta scrivendo: se lo staff non lo dà, lascialo vuoto.'],
                    'email'     => ['type' => 'string', 'description' => 'Email del cliente.'],
                    'indirizzo' => ['type' => 'string', 'description' => 'Indirizzo completo: via, numero, città.'],
                    'zona'      => ['type' => 'string', 'description' => 'Zona o città del cliente.'],
                    'servizio'  => ['type' => 'string', 'description' => 'Tipo di servizio/lavoro (es. "wrapping armadio + Ardy Express", "montaggio vetro e ritocchi").'],
                    'mobile'    => ['type' => 'string', 'description' => 'Descrizione del mobile o pezzo.'],
                    'stato'     => ['type' => 'string', 'description' => 'Stato: LEAD, SOPRALLUOGO, PREVENTIVO, ACCONTO, RITIRATI, IN_LAVORAZIONE, STANDBY, PERSO. Se lo staff non lo dice, usa LEAD.'],
                    'note'      => ['type' => 'string', 'description' => 'Note aggiuntive per lo staff.'],
                ],
                'required' => ['nome'],
            ],
        ],
        [
            'name'        => 'elenca_sopralluoghi_staff',
            'description' => 'Elenca gli appuntamenti (anche più di uno) di un cliente del CRM — sopralluoghi, consegne e ritiri — con data/ora ed etichetta. Usalo quando lo staff chiede "che appuntamenti/sopralluoghi ha X?" o quando devi sapere QUALE spostare/eliminare prima di agire.',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'nome'       => ['type' => 'string', 'description' => 'Nome (o nome e cognome) del cliente.'],
                    'session_id' => ['type' => 'string', 'description' => 'Opzionale: session_id della scheda esatta, per disambiguare gli omonimi.'],
                ],
                'required' => ['nome'],
            ],
        ],
        [
            'name'        => 'fissa_appuntamento_staff',
            'description' => 'AGGIUNGE un appuntamento nel calendario di Ardy Lab PER CONTO di un cliente del CRM (lo stai facendo tu, dello staff). L\'appuntamento può essere di quattro tipi (campo `tipo`): un SOPRALLUOGO (visita/valutazione), un RITIRO (vai a prendere gli oggetti dal cliente), un INTERVENTO SUL POSTO (lavoro fatto a casa del cliente tra ritiro e consegna: verniciatura telai, tagli o restauri strutturali, ecc.) o una CONSEGNA (riporti il lavoro finito al cliente). Scegli il tipo giusto: una consegna NON è un sopralluogo. Un cliente può avere PIÙ appuntamenti: questo tool ne aggiunge SEMPRE uno nuovo, non è un doppione. Identifica il cliente per nome; se esistono più schede con quel nome ti verrà restituito l\'elenco e dovrai richiamare il tool col session_id giusto. Usalo solo dopo che lo staff ha indicato giorno e ora precisi.',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'nome'       => ['type' => 'string', 'description' => 'Nome (o nome e cognome) del cliente.'],
                    'start'      => ['type' => 'string', 'description' => 'Data e ora inizio, ISO 8601. Es: 2026-06-23T10:00:00+02:00'],
                    'tipo'       => ['type' => 'string', 'enum' => ['sopralluogo', 'ritiro', 'intervento', 'consegna'], 'description' => 'Tipo di appuntamento: "sopralluogo" (visita, default), "ritiro" (presa in carico degli oggetti), "intervento" (lavoro sul posto tra ritiro e consegna: verniciatura telai, tagli/restauri strutturali, ecc.), "consegna" (riconsegna del lavoro al cliente). Se lo staff dice "consegna"/"consegnare" usa "consegna"; se dice "ritiro"/"ritirare" usa "ritiro"; se dice "intervento"/"lavoro sul posto"/"verniciare"/"tagliare"/"restaurare sul posto" usa "intervento".'],
                    'session_id' => ['type' => 'string', 'description' => 'Opzionale: session_id della scheda esatta, per disambiguare gli omonimi (formato wa-XXXXXXXXXXXXXXXX).'],
                    'etichetta'  => ['type' => 'string', 'description' => 'Opzionale: etichetta libera dell\'appuntamento (es. "2° sopralluogo", "consegna comodini"). Se la ometti si usa il nome del tipo.'],
                ],
                'required' => ['nome', 'start'],
            ],
        ],
        [
            'name'        => 'sposta_appuntamento_staff',
            'description' => 'Sposta a una nuova data/ora un sopralluogo GIÀ esistente di un cliente del CRM, per conto dello staff. Identifica il cliente per nome (disambigua col session_id se più schede). Se il cliente ha PIÙ sopralluoghi e non hai indicato sopralluogo_id, ti verrà restituito l\'elenco e dovrai chiedere allo staff QUALE spostare, poi richiamare col sopralluogo_id giusto. Prima verifica la disponibilità del nuovo periodo con ottieni_disponibilita_calendario.',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'nome'          => ['type' => 'string', 'description' => 'Nome (o nome e cognome) del cliente.'],
                    'start'         => ['type' => 'string', 'description' => 'Nuova data e ora di inizio, ISO 8601.'],
                    'session_id'    => ['type' => 'string', 'description' => 'Opzionale: session_id della scheda esatta, per disambiguare gli omonimi.'],
                    'sopralluogo_id'=> ['type' => 'integer', 'description' => 'Opzionale: id del sopralluogo da spostare (necessario se il cliente ne ha più di uno). Lo trovi con elenca_sopralluoghi_staff.'],
                ],
                'required' => ['nome', 'start'],
            ],
        ],
        [
            'name'        => 'leggi_nota_settimanale',
            'description' => 'Restituisce la NOTA SETTIMANALE "cose da fare" dello staff salvata più di recente (un promemoria libero: sopralluoghi da prendere, materiali da ordinare, montaggi, ecc.). Usala quando lo staff chiede "cosa devo fare questa settimana / leggimi la lista / le cose da fare", e SEMPRE prima di aggiungere o spuntare una voce (così parti dal testo aggiornato).',
            'input_schema' => [
                'type'       => 'object',
                'properties' => (object) [],
            ],
        ],
        [
            'name'        => 'salva_nota_settimanale',
            'description' => 'Salva/aggiorna la NOTA SETTIMANALE "cose da fare" dello staff. Passa il TESTO COMPLETO e aggiornato della nota (non solo la modifica): se lo staff chiede di aggiungere, togliere o spuntare una voce, prima leggi con leggi_nota_settimanale, modifica il testo intero e risalvalo qui. Mantieni un elenco ordinato e leggibile.',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'testo' => ['type' => 'string', 'description' => 'Testo COMPLETO e aggiornato della nota settimanale (l\'elenco intero, non il singolo punto).'],
                ],
                'required' => ['testo'],
            ],
        ],
    ];
}

// -----------------------------------------------------------
// FOTO IN ARRIVO DA WHATSAPP: scarica, valida, comprime, salva su scheda
// (visibile in dashboard) e attaccala al messaggio così Sole la "vede" e valuta.
// -----------------------------------------------------------
if ($mediaId !== '') {
    $media = waScaricaMediaWhatsApp($mediaId);
    if ($media) {
        $bytes    = $media[0];
        $allowed  = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $realMime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);   // tipo reale, non dichiarato
        if (in_array($realMime, $allowed, true)) {
            $bytes = ardyCompressImage($bytes, $realMime);             // stessa compressione del sito

            // Salva nella cartella della scheda → compare in dashboard.
            if ($cardSession !== '' && defined('ARDY_UPLOAD_DIR')) {
                $dir = rtrim(ARDY_UPLOAD_DIR, '/') . '/' . $cardSession . '/';
                if (is_dir($dir) || @mkdir($dir, 0755, true) || is_dir($dir)) {
                    $ext = $realMime === 'image/png' ? 'png' : ($realMime === 'image/webp' ? 'webp' : ($realMime === 'image/gif' ? 'gif' : 'jpg'));
                    $fp  = $dir . date('Ymd_His') . '_' . substr(md5($mediaId), 0, 8) . '.' . $ext;
                    @file_put_contents($fp, $bytes);
                }
            }

            // Attacca la foto all'ULTIMO messaggio utente (formato a blocchi).
            for ($i = count($messages) - 1; $i >= 0; $i--) {
                if (($messages[$i]['role'] ?? '') === 'user') {
                    $txt = is_string($messages[$i]['content'] ?? null) ? $messages[$i]['content'] : '';
                    $messages[$i]['content'] = [
                        ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $realMime, 'data' => base64_encode($bytes)]],
                        ['type' => 'text',  'text' => $txt !== '' ? $txt : 'Il cliente ha inviato questa foto del mobile.'],
                    ];
                    break;
                }
            }
        }
    }
}

// -----------------------------------------------------------
// LOOP AGENTICO (identico al sito, ridotto ai tool calendario)
// -----------------------------------------------------------
$reply          = '';
$maxIterations  = 5;
$iteration      = 0;
$bookingMade    = false;
$bookingWhen    = null;   // DateTime dell'appuntamento (nuovo o spostato)
$bookingEventId = null;   // id evento Google appena creato/spostato
$bookingTipo    = 'sopralluogo';  // tipo dell'appuntamento fissato/spostato (per l'email al cliente)
$rescheduled    = false;  // true se un appuntamento è stato SPOSTATO

while ($iteration < $maxIterations) {
    $iteration++;
    $data = waCallAnthropic($messages, $system, $tools, ARDY_API_KEY);

    if (isset($data['error'])) {
        $reply = 'Scusa, ora non riesco a rispondere. Ti ricontatto a breve.';
        break;
    }

    $stopReason = $data['stop_reason'] ?? 'end_turn';
    $content    = $data['content']     ?? [];
    // FIX: i tool SENZA argomenti (es. leggi_nota_settimanale) tornano con input {}
    // che json_decode trasforma in array PHP vuoto []; rimandandolo ad Anthropic
    // diventerebbe `[]` (array) e l'API rifiuta con "Input should be an object"
    // (400), bloccando anche i messaggi successivi. Riportiamo gli input vuoti a {}.
    foreach ($content as &$blk) {
        if (($blk['type'] ?? '') === 'tool_use' && isset($blk['input']) && is_array($blk['input']) && empty($blk['input'])) {
            $blk['input'] = (object) [];
        }
    }
    unset($blk);
    $messages[] = ['role' => 'assistant', 'content' => $content];

    if ($stopReason === 'end_turn') {
        foreach ($content as $block) {
            if (($block['type'] ?? '') === 'text') { $reply = ardy_strip_tool_syntax($block['text']); break; }
        }
        break;
    }

    if ($stopReason !== 'tool_use') break;

    $toolResults = [];
    foreach ($content as $block) {
        if (($block['type'] ?? '') !== 'tool_use') continue;
        $toolName  = $block['name'];
        $toolInput = $block['input'] ?? [];
        $toolId    = $block['id'];
        $toolResult = '';

        if ($toolName === 'ottieni_disponibilita_calendario') {
            $fromDate = null;
            if (!empty($toolInput['start'])) {
                try {
                    $fromDate = new DateTime($toolInput['start']);
                    if ($fromDate < new DateTime('now')) { $fromDate = new DateTime('+7 days'); }
                } catch (Exception $e) { $fromDate = new DateTime('+7 days'); }
            }
            $slots = gcal_get_free_slots(14, 9, 18, $fromDate);
            if ($slots === null) {
                $toolResult = 'Errore calendario: impossibile leggere la disponibilità.';
            } elseif (empty($slots)) {
                $toolResult = 'Nessuno slot disponibile nel periodo richiesto.';
            } else {
                $toolResult = json_encode($slots);
            }

        } elseif ($toolName === 'fissa_appuntamento_calendario') {
            // Guardia anti-doppione: se la scheda ha GIÀ un appuntamento, non crearne un secondo.
            $existingEventId = $bookingEventId;
            if (empty($existingEventId) && $sessionId !== '') {
                try {
                    $db = ardyDB();
                    waEnsureSopralluogoCols($db);
                    $qb = $db->prepare("SELECT gcal_event_id FROM clienti WHERE session_id = :sid LIMIT 1");
                    $qb->execute([':sid' => $sessionId]);
                    $existingEventId = trim((string) $qb->fetchColumn());
                } catch (PDOException $e) { $existingEventId = ''; }
            }
            if (!empty($existingEventId)) {
                $toolResult = 'Questo cliente ha GIÀ un appuntamento fissato: NON crearne un altro (creeresti un doppione nel calendario). Conferma l\'appuntamento già esistente; se vuole cambiare data, raccogli la richiesta e di\' che Michela lo riorganizza.';
            } else try {
                if (empty($toolInput['start'])) {
                    $toolResult = 'Errore: data/ora mancante. Chiedi al cliente di confermare giorno e ora.';
                } else {
                    $startDt = new DateTime($toolInput['start']);
                    $dateStr = $startDt->format('Y-m-d');
                    $timeStr = $startDt->format('H:i');
                    $summary = $toolInput['summary']     ?? 'Sopralluogo Ardy Lab';
                    $desc    = $toolInput['description'] ?? '';
                    $r = gcal_create_event($dateStr, $timeStr, $summary, '', '', '', $desc);
                    if ($r) {
                        $bookingMade    = true;
                        $bookingWhen    = $startDt;
                        $bookingEventId = is_array($r) ? ($r['id'] ?? null) : null;
                    }
                    $toolResult = $r
                        ? 'Appuntamento creato con successo nel calendario di Michela.'
                        : 'Errore nella creazione dell\'appuntamento. Riprova.';
                }
            } catch (Exception $e) {
                error_log('ARDY WA-AGENT BOOKING ERROR: ' . $e->getMessage() . ' input=' . json_encode($toolInput));
                $toolResult = 'Errore tecnico nella prenotazione. Chiedi al cliente di riprovare.';
            }

        } elseif ($toolName === 'salva_lead_crm') {
            // Telefono: quello fornito da Sole o, in mancanza, il numero WhatsApp del mittente.
            $tel = preg_replace('/[^0-9+]/', '', (string) ($toolInput['telefono'] ?? ''));
            if ($tel === '' && $phone !== '') $tel = $phone;
            // Budget non è una colonna CRM: lo accodiamo alle note.
            $note = trim((string) ($toolInput['note'] ?? ''));
            if (!empty($toolInput['budget'])) {
                $note = trim($note . "\nBudget: " . $toolInput['budget']);
            }
            $payload = [
                'nome'      => $toolInput['nome']      ?? '',
                'cognome'   => $toolInput['cognome']   ?? '',
                'telefono'  => $tel,
                'email'     => $toolInput['email']     ?? '',
                'indirizzo' => $toolInput['indirizzo'] ?? '',
                'servizio'  => $toolInput['servizio']  ?? '',
                'mobile'    => $toolInput['mobile']    ?? '',
                'zona'      => $toolInput['zona']       ?? '',
                'stato'     => $toolInput['stato']      ?? 'LEAD',
                'note'      => $note,
            ];
            $ch = curl_init('https://ardyagent.ardy-lab.it/ardy-wa-crea-scheda.php');
            curl_setopt($ch, CURLOPT_POST,           true);
            curl_setopt($ch, CURLOPT_POSTFIELDS,     json_encode($payload));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT,        15);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'X-Ardy-Secret: ' . (defined('WA_LOOKUP_SECRET') ? WA_LOOKUP_SECRET : ''),
            ]);
            $r = json_decode(curl_exec($ch), true);
            curl_close($ch);
            if (is_array($r) && !empty($r['success'])) {
                // Aggancia la scheda alla conversazione: una prenotazione successiva si attacca qui.
                if (!empty($r['session_id'])) $sessionId = $r['session_id'];
                $leadCreated = !empty($r['created']);
                // Aggiorna i dati lead per le email con quanto raccolto da Sole.
                $nm = trim(($toolInput['nome'] ?? '') . ' ' . ($toolInput['cognome'] ?? ''));
                if ($nm !== '') $leadNome = $nm;
                if (!empty($toolInput['zona'])) $leadZona = trim((string) $toolInput['zona']);
                $em = trim((string) ($toolInput['email'] ?? ''));
                if ($em !== '' && filter_var($em, FILTER_VALIDATE_EMAIL)) $leadEmail = $em;

                // Codice di accesso: generato UNA volta per scheda. Col codice il cliente,
                // tornando sulla WEBCHAT, potrà chiedere lo stato del lavoro. Prepara l'invio
                // email di benvenuto (spedita dopo la risposta, vedi in fondo).
                if ($sessionId !== '') {
                    try {
                        $db = ardyDB();
                        waEnsureCodiceCol($db);
                        $sel = $db->prepare("SELECT codice_accesso, codice_email_inviato, email FROM clienti WHERE session_id = :sid LIMIT 1");
                        $sel->execute([':sid' => $sessionId]);
                        $row        = $sel->fetch(PDO::FETCH_ASSOC) ?: [];
                        $codice     = trim((string) ($row['codice_accesso'] ?? ''));
                        $giaInviata = !empty($row['codice_email_inviato']);
                        if ($codice === '') {
                            for ($t = 0; $t < 5; $t++) {
                                $cand = waGeneraCodiceAccesso();
                                $chk  = $db->prepare("SELECT 1 FROM clienti WHERE codice_accesso = :c LIMIT 1");
                                $chk->execute([':c' => $cand]);
                                if (!$chk->fetchColumn()) { $codice = $cand; break; }
                            }
                            if ($codice !== '') {
                                $db->prepare("UPDATE clienti SET codice_accesso = :c, updated_at = NOW() WHERE session_id = :sid")
                                   ->execute([':c' => $codice, ':sid' => $sessionId]);
                            }
                        }
                        if ($codice !== '' && !$giaInviata) {
                            $emailLead = $leadEmail !== '' ? $leadEmail : trim((string) ($row['email'] ?? ''));
                            if ($emailLead !== '' && filter_var($emailLead, FILTER_VALIDATE_EMAIL)) {
                                $accessCode  = $codice;
                                $accessEmail = $emailLead;
                            }
                        }
                    } catch (PDOException $e) {
                        error_log('ARDY WA-AGENT CODICE ACCESSO: ' . $e->getMessage());
                    }
                }
                $toolResult = 'Scheda cliente salvata nel CRM. Prosegui con naturalezza, NON elencare i dati al cliente.';
            } else {
                $toolResult = 'Non sono riuscita a salvare la scheda adesso. Prosegui comunque la conversazione; se serve, di\' che Michela la registra.';
            }

        } elseif ($toolName === 'sposta_appuntamento') {
            // Su WhatsApp il cliente può spostare SOLO il proprio appuntamento:
            // lo identifichiamo dal suo numero WhatsApp, non da un telefono fornito
            // (evita che qualcuno sposti l'appuntamento di un altro).
            $start = $toolInput['start'] ?? '';
            if ($phone === '') {
                $toolResult = 'Non riesco a identificare il numero del cliente. Raccogli la richiesta e di\' che Michela ricontatta.';
            } elseif ($start === '') {
                $toolResult = 'Errore: manca la nuova data/ora. Chiedi al cliente giorno e ora precisi.';
            } else {
                try {
                    $startDt = new DateTime($start);
                    if ($startDt < new DateTime('now')) {
                        $toolResult = 'La nuova data è nel passato: chiedi al cliente una data futura.';
                    } else {
                        $db = ardyDB();
                        waEnsureSopralluogoCols($db);
                        ardyEnsureTelefonoLast9($db);
                        $q = $db->prepare(
                            "SELECT session_id, nome, cognome, email, gcal_event_id FROM clienti
                              WHERE telefono_last9 = :p
                                AND gcal_event_id IS NOT NULL AND gcal_event_id <> ''
                                AND deleted_at IS NULL
                           ORDER BY updated_at DESC, id DESC LIMIT 1"
                        );
                        $q->execute([':p' => substr($phone, -9)]);
                        $cli = $q->fetch(PDO::FETCH_ASSOC);
                        if (!$cli) {
                            $toolResult = 'Non trovo un appuntamento collegato a questo numero. Raccogli la richiesta e di\' che Michela ricontatta il cliente per riorganizzare.';
                        } else {
                            $dateStr = $startDt->format('Y-m-d');
                            $timeStr = $startDt->format('H:i');
                            $free = gcal_is_slot_free($dateStr, $timeStr, 2);
                            if ($free === false) {
                                $toolResult = 'Quel nuovo orario è già occupato. Proponi al cliente un altro slot tra quelli liberi (controlla con ottieni_disponibilita_calendario).';
                            } else {
                                // free === true (libero) o null (impossibile verificare): procediamo.
                                $upd = gcal_update_event($cli['gcal_event_id'], $dateStr, $timeStr, 2);
                                if (!$upd) {
                                    $toolResult = 'Non sono riuscita a spostare l\'appuntamento sul calendario. Riprova o di\' che Michela ricontatta.';
                                } else {
                                    $db->prepare("UPDATE clienti SET sopralluogo_at = :dt, stato = 'SOPRALLUOGO', updated_at = NOW() WHERE session_id = :sid")
                                       ->execute([':dt' => $startDt->format('Y-m-d H:i:s'), ':sid' => $cli['session_id']]);
                                    $rescheduled    = true;
                                    $bookingWhen    = $startDt;               // per la conferma email al cliente
                                    $bookingEventId = $cli['gcal_event_id'];
                                    $nm = trim(($cli['nome'] ?? '') . ' ' . ($cli['cognome'] ?? ''));
                                    if ($nm !== '') $leadNome = $nm;
                                    $em = trim((string) ($cli['email'] ?? ''));
                                    if ($em !== '' && filter_var($em, FILTER_VALIDATE_EMAIL)) $leadEmail = $em;
                                    $toolResult = 'Appuntamento spostato con successo al nuovo orario nel calendario di Michela.';
                                }
                            }
                        }
                    }
                } catch (Exception $e) {
                    error_log('ARDY WA-AGENT SPOSTA ERROR: ' . $e->getMessage());
                    $toolResult = 'Errore tecnico nello spostamento. Chiedi al cliente di riprovare.';
                }
            }

        } elseif ($toolName === 'cerca_scheda_cliente') {
            try {
                $db    = ardyDB();
                $cards = waTrovaSchedePerNome($db, (string) ($toolInput['nome'] ?? ''));
                if (empty($cards)) {
                    $toolResult = 'Nessuna scheda trovata per «' . ($toolInput['nome'] ?? '') . '». Verifica il nome con lo staff o crea prima la scheda.';
                } else {
                    $toolResult = "Schede trovate:\n" . waFormattaSchedeAmbigue($cards)
                                . "\nPer agire sulla scheda giusta usa il suo session_id con fissa_appuntamento_staff / sposta_appuntamento_staff.";
                }
            } catch (PDOException $e) {
                error_log('ARDY WA-AGENT STAFF CERCA ERROR: ' . $e->getMessage());
                $toolResult = 'Errore nella ricerca della scheda. Riprova.';
            }

        } elseif ($toolName === 'crea_scheda_cliente') {
            // Staff: crea la scheda SUBITO (server-to-server, stessa logica di upsert
            // deterministico di ardy-wa-crea-scheda.php → niente doppioni). Ritorna il
            // session_id così Sole può fissare l'appuntamento nello stesso giro, senza
            // aspettare nessuna "sincronizzazione" (era la causa della confusione: la
            // creazione stava sul marker n8n post-turno e i tool non la vedevano ancora).
            // NB: il telefono è quello DEL CLIENTE dettato dallo staff, MAI il numero di
            // chi scrive ($phone = Michela): qui non facciamo il fallback su $phone.
            $nomeCli = trim((string) ($toolInput['nome'] ?? ''));
            if ($nomeCli === '' && trim((string) ($toolInput['cognome'] ?? '')) === '' && trim((string) ($toolInput['telefono'] ?? '')) === '') {
                $toolResult = 'Serve almeno nome, cognome o telefono per creare la scheda. Chiedi allo staff.';
            } else {
                $payload = [
                    'nome'      => $toolInput['nome']      ?? '',
                    'cognome'   => $toolInput['cognome']   ?? '',
                    'telefono'  => preg_replace('/[^0-9+]/', '', (string) ($toolInput['telefono'] ?? '')),
                    'email'     => $toolInput['email']     ?? '',
                    'indirizzo' => $toolInput['indirizzo'] ?? '',
                    'zona'      => $toolInput['zona']       ?? '',
                    'servizio'  => $toolInput['servizio']  ?? '',
                    'mobile'    => $toolInput['mobile']    ?? '',
                    'stato'     => $toolInput['stato']      ?? 'LEAD',
                    'note'      => $toolInput['note']       ?? '',
                ];
                $ch = curl_init('https://ardyagent.ardy-lab.it/ardy-wa-crea-scheda.php');
                curl_setopt($ch, CURLOPT_POST,           true);
                curl_setopt($ch, CURLOPT_POSTFIELDS,     json_encode($payload));
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT,        15);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Content-Type: application/json',
                    'X-Ardy-Secret: ' . (defined('WA_LOOKUP_SECRET') ? WA_LOOKUP_SECRET : ''),
                ]);
                $r = json_decode(curl_exec($ch), true);
                curl_close($ch);
                if (is_array($r) && !empty($r['success']) && !empty($r['session_id'])) {
                    $verbo = !empty($r['created']) ? 'creata' : 'aggiornata';
                    $toolResult = 'Scheda ' . $verbo . ' e già salvata nel CRM (session_id=' . $r['session_id'] . '). '
                                . "È disponibile SUBITO: NON dire che il sistema deve ancora \"sincronizzarsi\" o che non la trova.\n"
                                . 'Se questo cliente ha un appuntamento da fissare, chiama ORA fissa_appuntamento_staff passando questo session_id.';
                } else {
                    $toolResult = 'Non sono riuscita a salvare la scheda adesso. Riprova tra poco o di\' allo staff di registrarla dalla dashboard.';
                }
            }

        } elseif ($toolName === 'elenca_sopralluoghi_staff') {
            try {
                $db = ardyDB();
                [$stato, $payload] = waRisolviScheda($db, (string) ($toolInput['nome'] ?? ''), (string) ($toolInput['session_id'] ?? ''));
                if ($stato === 'none') {
                    $toolResult = 'Non trovo nessuna scheda per «' . ($toolInput['nome'] ?? '') . '».';
                } elseif ($stato === 'ambiguo') {
                    $toolResult = "Ci sono PIÙ schede con questo nome: chiedi allo staff QUALE, poi richiama col session_id giusto.\n"
                                . waFormattaSchedeAmbigue($payload);
                } else {
                    $rows = sopr_list($db, $payload['session_id']);
                    $nm   = trim(($payload['nome'] ?? '') . ' ' . ($payload['cognome'] ?? ''));
                    $toolResult = 'Sopralluoghi di ' . ($nm !== '' ? $nm : 'questo cliente') . ":\n" . waFormattaSopralluoghi($rows);
                }
            } catch (Exception $e) {
                error_log('ARDY WA-AGENT STAFF ELENCA ERROR: ' . $e->getMessage());
                $toolResult = 'Errore nel recupero dei sopralluoghi. Riprova.';
            }

        } elseif ($toolName === 'fissa_appuntamento_staff') {
            try {
                if (empty($toolInput['nome']) || empty($toolInput['start'])) {
                    $toolResult = 'Servono il nome del cliente e la data/ora di inizio. Chiedi allo staff di precisare.';
                } else {
                    $db = ardyDB();
                    [$stato, $payload] = waRisolviScheda($db, (string) $toolInput['nome'], (string) ($toolInput['session_id'] ?? ''));
                    if ($stato === 'none') {
                        $toolResult = 'Non trovo nessuna scheda per «' . $toolInput['nome'] . '». Chiedi allo staff di verificare il nome o di creare prima la scheda.';
                    } elseif ($stato === 'ambiguo') {
                        $toolResult = "Ci sono PIÙ schede con questo nome: NON scegliere a caso. Chiedi allo staff QUALE cliente è, poi richiama fissa_appuntamento_staff col session_id giusto.\n"
                                    . waFormattaSchedeAmbigue($payload);
                    } else {
                        $card    = $payload;
                        $dataOra = sopr_norm_data($toolInput['start']);
                        if ($dataOra === null) {
                            $toolResult = 'La data/ora non è valida. Chiedi allo staff di ripeterla (giorno e ora).';
                        } else {
                            // AGGIUNGE sempre un nuovo appuntamento (un cliente può averne più d'uno).
                            // Il tipo (sopralluogo/consegna/ritiro) sceglie titolo evento ed etichetta.
                            $tipo = in_array($toolInput['tipo'] ?? '', sopr_tipi_validi(), true) ? (string) $toolInput['tipo'] : 'sopralluogo';
                            $cli = sopr_get_cliente($db, $card['session_id']);
                            sopr_salva($db, $card['session_id'], 0, $dataOra, (string) ($toolInput['etichetta'] ?? ''), '', $cli, $tipo);
                            $startDt     = new DateTime($dataOra);
                            $bookingTipo = $tipo;         // per l'email di conferma al cliente (sopralluogo/consegna/ritiro)
                            $bookingMade = true;          // → conferma al cliente (se ha email)
                            $bookingWhen = $startDt;       // NB: niente $bookingEventId → il mirror lo cura la lib
                            $nm = trim(($card['nome'] ?? '') . ' ' . ($card['cognome'] ?? ''));
                            if ($nm !== '') $leadNome = $nm;
                            $em = trim((string) ($card['email'] ?? ''));
                            if ($em !== '' && filter_var($em, FILTER_VALIDATE_EMAIL)) $leadEmail = $em;
                            $tLabel = sopr_tipo_label($tipo);
                            $et = trim((string) ($toolInput['etichetta'] ?? '')) ?: $tLabel;
                            $toolResult = $tLabel . ' «' . $et . '» aggiunto per ' . ($nm !== '' ? $nm : 'il cliente')
                                        . ' il ' . $startDt->format('d/m/Y') . ' alle ' . $startDt->format('H:i') . ' (calendario aggiornato).';
                        }
                    }
                }
            } catch (Exception $e) {
                error_log('ARDY WA-AGENT STAFF FISSA ERROR: ' . $e->getMessage() . ' input=' . json_encode($toolInput));
                $toolResult = 'Errore tecnico nella prenotazione. Riprova.';
            }

        } elseif ($toolName === 'sposta_appuntamento_staff') {
            try {
                if (empty($toolInput['nome']) || empty($toolInput['start'])) {
                    $toolResult = 'Servono il nome del cliente e la nuova data/ora. Chiedi allo staff di precisare.';
                } else {
                    $dataOra = sopr_norm_data($toolInput['start']);
                    $startDt = $dataOra !== null ? new DateTime($dataOra) : null;
                    if ($startDt === null) {
                        $toolResult = 'La nuova data/ora non è valida. Chiedi allo staff di ripeterla.';
                    } elseif ($startDt < new DateTime('now')) {
                        $toolResult = 'La nuova data è nel passato: serve una data futura.';
                    } else {
                        $db = ardyDB();
                        [$stato, $payload] = waRisolviScheda($db, (string) $toolInput['nome'], (string) ($toolInput['session_id'] ?? ''));
                        if ($stato === 'none') {
                            $toolResult = 'Non trovo nessuna scheda per «' . $toolInput['nome'] . '».';
                        } elseif ($stato === 'ambiguo') {
                            $toolResult = "Ci sono PIÙ schede con questo nome: chiedi allo staff QUALE cliente è, poi richiama sposta_appuntamento_staff col session_id giusto.\n"
                                        . waFormattaSchedeAmbigue($payload);
                        } else {
                            $card = $payload;
                            $rows = sopr_list($db, $card['session_id']);
                            if (empty($rows)) {
                                $toolResult = 'Questo cliente non ha sopralluoghi da spostare. Se vuoi crearne uno usa fissa_appuntamento_staff.';
                            } else {
                                // Quale visita spostare? (id esplicito, oppure unica, oppure chiedi)
                                $soprId = (int) ($toolInput['sopralluogo_id'] ?? 0);
                                $target = null;
                                if ($soprId > 0) {
                                    foreach ($rows as $r) { if ((int) $r['id'] === $soprId) { $target = $r; break; } }
                                    if (!$target) {
                                        $toolResult = "Quel sopralluogo_id non è di questo cliente. Ecco i suoi sopralluoghi:\n" . waFormattaSopralluoghi($rows);
                                    }
                                } elseif (count($rows) === 1) {
                                    $target = $rows[0];
                                } else {
                                    $toolResult = "Questo cliente ha PIÙ sopralluoghi: chiedi allo staff QUALE spostare, poi richiama sposta_appuntamento_staff col sopralluogo_id giusto.\n"
                                                . waFormattaSopralluoghi($rows);
                                }
                                if ($target) {
                                    $dateStr = $startDt->format('Y-m-d');
                                    $timeStr = $startDt->format('H:i');
                                    $free    = gcal_is_slot_free($dateStr, $timeStr, 2);
                                    if ($free === false) {
                                        $toolResult = 'Quel nuovo orario è già occupato. Proponi un altro slot (controlla con ottieni_disponibilita_calendario).';
                                    } else {
                                        // Preserva il tipo esistente della visita: spostare una consegna/ritiro
                                        // NON deve trasformarla in un sopralluogo (titolo evento + email coerenti).
                                        $tipoT = in_array($target['tipo'] ?? '', sopr_tipi_validi(), true) ? (string) $target['tipo'] : 'sopralluogo';
                                        $cli = sopr_get_cliente($db, $card['session_id']);
                                        sopr_salva($db, $card['session_id'], (int) $target['id'], $dataOra, (string) ($target['etichetta'] ?? ''), (string) ($target['note'] ?? ''), $cli, $tipoT);
                                        $rescheduled = true;
                                        $bookingWhen = $startDt;   // niente $bookingEventId → il mirror lo cura la lib
                                        $bookingTipo = $tipoT;     // per l'email di conferma al cliente
                                        $nm = trim(($card['nome'] ?? '') . ' ' . ($card['cognome'] ?? ''));
                                        if ($nm !== '') $leadNome = $nm;
                                        $em = trim((string) ($card['email'] ?? ''));
                                        if ($em !== '' && filter_var($em, FILTER_VALIDATE_EMAIL)) $leadEmail = $em;
                                        $tLabelT = sopr_tipo_label($tipoT);
                                        $toolResult = $tLabelT . ' «' . ($target['etichetta'] ?: $tLabelT) . '» spostato al '
                                                    . $startDt->format('d/m/Y') . ' alle ' . $timeStr . ' per ' . ($nm !== '' ? $nm : 'il cliente') . '.';
                                    }
                                }
                            }
                        }
                    }
                }
            } catch (Exception $e) {
                error_log('ARDY WA-AGENT STAFF SPOSTA ERROR: ' . $e->getMessage());
                $toolResult = 'Errore tecnico nello spostamento. Riprova.';
            }

        } elseif ($toolName === 'leggi_nota_settimanale') {
            try {
                $db  = ardyDB();
                $row = $db->query("SELECT testo, settimana, created_at FROM note_staff ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
                if (!$row || trim((string) $row['testo']) === '') {
                    $toolResult = 'Non c\'è ancora una nota settimanale salvata. Quando lo staff te la detta, salvala con salva_nota_settimanale.';
                } else {
                    $quando = !empty($row['created_at']) ? date('d/m/Y H:i', strtotime((string) $row['created_at'])) : '';
                    $toolResult = "NOTA SETTIMANALE" . (!empty($row['settimana']) ? ' (' . $row['settimana'] . ')' : '')
                                . ($quando !== '' ? ' — aggiornata il ' . $quando : '') . ":\n" . $row['testo'];
                }
            } catch (PDOException $e) {
                error_log('ARDY WA-AGENT NOTA LEGGI ERROR: ' . $e->getMessage());
                $toolResult = 'Errore nel recupero della nota settimanale. Riprova.';
            }

        } elseif ($toolName === 'salva_nota_settimanale') {
            try {
                $testo = trim((string) ($toolInput['testo'] ?? ''));
                if ($testo === '') {
                    $toolResult = 'La nota è vuota: dimmi cosa scrivere.';
                } else {
                    $db = ardyDB();
                    $db->prepare("INSERT INTO note_staff (settimana, testo, created_at) VALUES (:s, :t, NOW())")
                       ->execute([':s' => date('o-\WW'), ':t' => $testo]);
                    $toolResult = 'Nota settimanale salvata ✅ (la trovi con "leggimi le cose da fare").';
                }
            } catch (PDOException $e) {
                error_log('ARDY WA-AGENT NOTA SALVA ERROR: ' . $e->getMessage());
                $toolResult = 'Errore nel salvataggio della nota settimanale. Riprova.';
            }

        } else {
            // Tool non disponibile su questo canale: lo segnaliamo senza bloccare.
            $toolResult = 'Strumento non disponibile su WhatsApp.';
        }

        $toolResults[] = ['type' => 'tool_result', 'tool_use_id' => $toolId, 'content' => $toolResult];
    }
    $messages[] = ['role' => 'user', 'content' => $toolResults];
}

if ($reply === '') {
    $reply = 'Scusa, ora non riesco a rispondere. Ti ricontatto a breve.';
}
$reply = ardy_strip_tool_syntax($reply);
if ($reply === '') {
    $reply = 'Un attimo che verifico e ti riscrivo subito 🙂';
}

// -----------------------------------------------------------
// Persiste l'appuntamento sulla scheda (data + id evento) — come il sito.
// -----------------------------------------------------------
if ($bookingEventId && $bookingWhen instanceof DateTime && $sessionId !== '') {
    try {
        $db = ardyDB();
        waEnsureSopralluogoCols($db);
        $db->prepare("UPDATE clienti SET gcal_event_id = :eid, sopralluogo_at = :dt, stato = 'SOPRALLUOGO', updated_at = NOW() WHERE session_id = :sid")
           ->execute([
               ':eid' => $bookingEventId,
               ':dt'  => $bookingWhen->format('Y-m-d H:i:s'),
               ':sid' => $sessionId,
           ]);
    } catch (PDOException $e) {
        error_log('ARDY WA-AGENT SOPRALLUOGO PERSIST: ' . $e->getMessage());
    }
}

// ── Rispondi SUBITO a n8n: notifiche ed email qui sotto NON devono ritardare
//    il messaggio al cliente (chiudiamo la richiesta HTTP e proseguiamo). ──
echo json_encode([
    'success' => true,
    'reply'   => $reply,
    'booking' => $bookingMade ? ['when' => $bookingWhen->format('c')] : null,
]);
if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); }

// -----------------------------------------------------------
// SIDE-EFFECTS POST-RISPOSTA: WhatsApp a Michela + email (come il sito).
// -----------------------------------------------------------

// 1) Avvisa Michela su WhatsApp di nuovo sopralluogo o spostamento (dedupe).
//    In modalità STAFF è Michela/lo staff a fissare via Sole: non avvisarla di una
//    cosa che ha appena fatto lei (sarebbe rumore nella sua stessa chat).
if (!$staff && $bookingMade && $bookingWhen instanceof DateTime) {
    $quando = $bookingWhen->format('d/m/Y') . ' alle ' . $bookingWhen->format('H:i');
    $testo  = "📅 Nuovo sopralluogo fissato da Sole su WhatsApp:\n" . ($leadNome ?: 'un cliente')
            . ($leadZona !== '' ? " · {$leadZona}" : '') . "\n🗓 {$quando}";
    notificaMichela($testo, 'wa-sopr:' . ($bookingEventId ?: md5($testo)));
} elseif (!$staff && $rescheduled && $bookingWhen instanceof DateTime) {
    $quando = $bookingWhen->format('d/m/Y') . ' alle ' . $bookingWhen->format('H:i');
    $testo  = "📅 Sopralluogo SPOSTATO da Sole su WhatsApp:\n" . ($leadNome ?: 'un cliente')
            . "\n🗓 nuovo orario: {$quando}";
    notificaMichela($testo, 'wa-sposta:' . ($bookingEventId ?: md5($testo)) . ':' . $bookingWhen->format('YmdHi'));
}

// 2) NOTIFICA EMAIL A MICHELA — nuovo lead, sopralluogo fissato o spostato.
//    Saltata in modalità STAFF: l'azione l'ha fatta lo staff stesso via Sole, e il
//    "transcript" qui sarebbe la chat staff↔Sole, non una conversazione cliente.
if (!$staff && ($leadCreated || $bookingMade || $rescheduled)) {
    try {
        $isMove = $rescheduled && !$bookingMade && !$leadCreated;
        $body  = ($isMove ? "Sopralluogo SPOSTATO da Sole su WhatsApp\n" : "Nuovo contatto da Sole su WhatsApp\n") . str_repeat('─', 40) . "\n\n";
        $body .= "DATA:      " . date('d/m/Y H:i') . "\n";
        $body .= "NOME:      " . ($leadNome ?: '—') . "\n";
        $body .= "TELEFONO:  " . ($phone !== '' ? '+' . $phone : '—') . "\n";
        $body .= "EMAIL:     " . ($leadEmail ?: 'non fornita') . "\n";
        $body .= "ZONA:      " . ($leadZona ?: '—') . "\n";
        if ($bookingWhen instanceof DateTime) {
            $body .= ($isMove ? "NUOVO ORARIO: " : "SOPRALLUOGO: ") . $bookingWhen->format('d/m/Y H:i') . "\n";
        }
        $body .= "\n" . str_repeat('─', 40) . "\nCONVERSAZIONE\n" . str_repeat('─', 40) . "\n\n" . $transcript;

        $mail = waNewMailer();
        $mail->setFrom('noreply@ardy-lab.it', 'Ardy AI');
        $mail->addAddress(ARDY_MAIL_MICHELA);
        // Allega le foto inviate dal cliente su WhatsApp (cartella della scheda).
        if ($cardSession !== '' && defined('ARDY_UPLOAD_DIR')) {
            $cd = rtrim(ARDY_UPLOAD_DIR, '/') . '/' . $cardSession . '/';
            if (is_dir($cd)) {
                foreach (glob($cd . '*') as $f) { if (is_file($f)) $mail->addAttachment($f); }
            }
        }
        $mail->Subject = ($isMove ? '📅 Sopralluogo SPOSTATO (WhatsApp) — ' : '📋 Nuovo lead da Sole (WhatsApp) — ') . date('d/m/Y H:i');
        $mail->Body    = $body;
        $mail->send();
    } catch (\Throwable $e) {
        error_log('ARDY WA-AGENT MAIL MICHELA: ' . $e->getMessage());
    }
}

// 3) CONFERMA SOPRALLUOGO AL CLIENTE (se fissato/spostato ed email disponibile).
if (($bookingMade || $rescheduled) && $bookingWhen instanceof DateTime && $leadEmail && filter_var($leadEmail, FILTER_VALIDATE_EMAIL)) {
    $giorni = ['Monday'=>'lunedì','Tuesday'=>'martedì','Wednesday'=>'mercoledì','Thursday'=>'giovedì','Friday'=>'venerdì','Saturday'=>'sabato','Sunday'=>'domenica'];
    $mesi   = ['January'=>'gennaio','February'=>'febbraio','March'=>'marzo','April'=>'aprile','May'=>'maggio','June'=>'giugno','July'=>'luglio','August'=>'agosto','September'=>'settembre','October'=>'ottobre','November'=>'novembre','December'=>'dicembre'];
    $gg     = $giorni[$bookingWhen->format('l')] ?? '';
    $mm     = $mesi[$bookingWhen->format('F')] ?? '';
    $quando = trim(ucfirst($gg) . ' ' . $bookingWhen->format('j') . ' ' . $mm . ' ' . $bookingWhen->format('Y') . ' alle ' . $bookingWhen->format('H:i'));
    try {
        $isMoveC = $rescheduled && !$bookingMade;
        // Testo coerente col tipo: sopralluogo / ritiro / intervento / consegna.
        $tLabelC = ($bookingTipo === 'ritiro') ? 'Ritiro' : ($bookingTipo === 'consegna' ? 'Consegna' : ($bookingTipo === 'intervento' ? 'Intervento sul posto' : 'Sopralluogo'));
        $tLowerC = mb_strtolower($tLabelC);
        $mailC = waNewMailer();
        $mailC->setFrom('noreply@ardy-lab.it', 'Ardy Lab');
        $mailC->addAddress($leadEmail);
        $mailC->Subject = '✅ ' . $tLabelC . ' Ardy Lab' . ($isMoveC ? ' spostato/a — ' : ' — ') . $quando;
        $mailC->isHTML(true);
        $mailC->Body = '
<div style="font-family:Georgia,serif;max-width:600px;margin:0 auto;padding:32px;color:#333;">
  ' . ardy_email_logo_cid($mailC) . '
  <p style="color:#999;font-size:13px;margin-bottom:24px;">' . ($isMoveC ? $tLabelC . ' aggiornato/a' : 'Conferma ' . $tLowerC) . '</p>
  <p style="font-size:15px;line-height:1.7;">Ciao,<br>' . ($isMoveC ? 'abbiamo aggiornato il tuo appuntamento (' . $tLowerC . ') al nuovo orario.' : 'abbiamo fissato il tuo appuntamento (' . $tLowerC . ').') . ' Ecco il riepilogo:</p>
  <div style="border-left:3px solid #c8a96e;padding:12px 20px;background:#fafaf8;margin:20px 0;font-size:16px;">
    <strong>' . htmlspecialchars($quando) . '</strong>
  </div>
  <p style="font-size:15px;line-height:1.7;">Per qualsiasi modifica o domanda puoi rispondere a questa email oppure chiamarci al <strong>351 967 7973</strong>. A presto!</p>
  <p style="margin-top:32px;font-size:12px;color:#bbb;">Ardy Lab — Restauro e laccatura mobili · Roma</p>
</div>';
        $mailC->send();
    } catch (\Throwable $e) {
        error_log('ARDY WA-AGENT CONFERMA CLIENTE: ' . $e->getMessage());
    }
}

// 4) EMAIL BENVENUTO + CODICE DI ACCESSO AL LEAD (solo alla prima generazione).
if ($accessCode && $accessEmail && filter_var($accessEmail, FILTER_VALIDATE_EMAIL)) {
    try {
        $mailK = waNewMailer();
        $mailK->setFrom('noreply@ardy-lab.it', 'Ardy Lab');
        $mailK->addAddress($accessEmail);
        $mailK->Subject = 'Benvenuto in Ardy Lab 🌿 — e il tuo codice personale';
        $mailK->isHTML(true);
        $logoTag = ardy_email_logo_cid($mailK);
        $mailK->Body = '
<div style="font-family:Georgia,serif;max-width:600px;margin:0 auto;padding:32px;color:#333;">
  ' . $logoTag . '
  <p style="color:#999;font-size:13px;margin-bottom:24px;">Benvenuto</p>
  <p style="font-size:15px;line-height:1.7;">Ciao, e benvenuto in Ardy Lab! 🌿</p>
  <p style="font-size:15px;line-height:1.7;">In chat hai appena conosciuto <strong>Sole</strong>, la nostra assistente: sì, è proprio lei che ti ha risposto. Perché da noi non ci limitiamo a restaurare i tuoi mobili — ti seguiamo con un customer care di nuova generazione, sempre a portata di mano: crediamo di essere tra i primi in Italia a offrirlo così.</p>
  <p style="font-size:15px;line-height:1.7;">E per renderti tutto semplice, da oggi hai un <strong>codice personale</strong> Ardy Lab:</p>
  <div style="border-left:3px solid #c8a96e;padding:16px 24px;background:#fafaf8;margin:20px 0;font-size:24px;letter-spacing:2px;font-family:monospace;">
    <strong>' . htmlspecialchars($accessCode) . '</strong>
  </div>
  <p style="font-size:15px;line-height:1.7;"><strong>A cosa serve.</strong> Quando vuoi un aggiornamento, torna sulla nostra chat — <a href="https://ardy-lab.it/ardy-agent/" style="color:#c8a96e;">ardy-lab.it/ardy-agent</a> — e comunica questo codice a Sole: ti dirà subito a che punto è il tuo lavoro, la data del sopralluogo e i prossimi passi — senza dover rispiegare nulla da capo.</p>
  <div style="border-radius:8px;background:#fbf8f2;padding:16px 20px;margin:20px 0;font-size:14px;line-height:1.6;color:#555;">
    🔒 <strong>È la tua chiave personale.</strong> Protegge i tuoi dati: solo chi possiede il codice può consultare lo stato della tua pratica.<br>
    Per questo non lo pubblichiamo e non lo condividiamo con nessuno — tienilo per te, come faresti con un PIN.
  </div>
  <p style="font-size:15px;line-height:1.7;">💬 <strong>Preferisci WhatsApp?</strong> Trovi Sole anche lì: scrivile al <strong>+39 379 375 6437</strong> e continui la conversazione come in chat — anche solo per sapere come procede il tuo lavoro.</p>
  <p style="margin:8px 0 4px;"><a href="https://wa.me/393793756437" style="display:inline-block;background:#25D366;color:#ffffff;text-decoration:none;font-family:sans-serif;font-size:14px;font-weight:600;padding:11px 22px;border-radius:6px;">Apri la chat WhatsApp</a></p>
  ' . ardy_email_social_links() . '
  <p style="font-size:15px;line-height:1.7;margin-top:24px;">Conserva pure questa email: il tuo codice lo ritrovi quando vuoi. E per qualsiasi cosa ti basta rispondere qui — ti leggiamo sempre. A presto! 🌿</p>
  <p style="margin-top:32px;font-size:12px;color:#bbb;">Ardy Lab — Restauro e laccatura mobili · Roma</p>
</div>';
        $mailK->send();
        // Segna l'invio così non si ripete ai salvataggi successivi della stessa scheda.
        try {
            $db = ardyDB();
            $db->prepare("UPDATE clienti SET codice_email_inviato = NOW() WHERE session_id = :sid")
               ->execute([':sid' => $sessionId]);
        } catch (PDOException $e) {
            error_log('ARDY WA-AGENT CODICE EMAIL FLAG: ' . $e->getMessage());
        }
    } catch (\Throwable $e) {
        error_log('ARDY WA-AGENT CODICE EMAIL: ' . $e->getMessage());
    }
}
