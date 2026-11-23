<?php
// -----------------------------------------------------------
// ARDY LAB — "Racconta": Sole scrive la storia del pezzo
// Da foto + nota dettata (in fretta, in bottega) Sole scrive il capitolo per la
// pagina pubblica e propone l'aggiornamento della scheda del pezzo. PROPONE, non
// salva: il risultato torna nei campi della mini-app e si salva col capitolo.
//
//   POST {mode:'racconta', pezzo_id, nota, fase_id?, foto_salvate[], immagini_nuove[]}
//        → {success, capitolo_titolo, capitolo_testo, titolo_pezzo, scheda{}, storia, domande[], avvisi[]}
//   POST {mode:'rifinisci', testo, istruzione} → {success, testo, avvisi[]}
//        ("togli la parte sul prezzo", "più corto": la correzione a voce)
//
// Uscita strutturata con tool_choice forzato: niente JSON da ripescare nel testo.
// Protetto via .htaccess (Basic Auth) — elencato nel <FilesMatch>.
// -----------------------------------------------------------

require_once __DIR__ . '/ardy-db.php';
require_once __DIR__ . '/ardy-sanitize.php';
require_once __DIR__ . '/ardy-pezzi-lib.php';

header('Access-Control-Allow-Origin: https://ardyagent.ardy-lab.it');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }
// Difesa in profondità: se il Basic Auth a monte (.htaccess) non venisse
// applicato, questo guard rifiuta comunque le richieste non autenticate.
require_once __DIR__ . '/ardy-auth.php';
ardyRequireAuth();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'error' => 'Metodo non valido']); exit(); }
set_time_limit(120);
// Tetto di spesa AI (SECURITY-AUDIT §9.12): entrambi i mode chiamano Claude.
require_once __DIR__ . '/ardy-costcap.php';
ardyCostCap('ai');

const PEZZI_AI_MAX_FOTO = 4;   // bastano a capire il pezzo; oltre si paga senza guadagno

/** Chiamata a Claude. Con $tool forza quell'uscita strutturata e ne ritorna l'input; senza, il testo. */
function pezziClaude(string $system, array $content, int $maxTokens, ?array $tool = null) {
    if (!defined('ARDY_API_KEY') || ARDY_API_KEY === '') return null;
    $body = [
        'model'      => 'claude-sonnet-4-6',   // stesso modello del resto del repo
        'max_tokens' => $maxTokens,
        'system'     => $system,
        'messages'   => [['role' => 'user', 'content' => $content]],
    ];
    if ($tool) {
        $body['tools']       = [$tool];
        $body['tool_choice'] = ['type' => 'tool', 'name' => $tool['name']];
    }
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 90,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . ARDY_API_KEY,
            'anthropic-version: 2023-06-01',
        ],
    ]);
    $res  = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($http !== 200) { error_log('ARDY PEZZI AI: HTTP ' . $http . ' — ' . substr((string) $res, 0, 300)); return null; }
    $data = json_decode($res, true);
    foreach ($data['content'] ?? [] as $b) {
        if ($tool && ($b['type'] ?? '') === 'tool_use') return $b['input'] ?? null;
        if (!$tool && ($b['type'] ?? '') === 'text')    return ardy_strip_tool_syntax(trim((string) $b['text']));
    }
    return null;
}

// Regole comuni a scrittura e correzione: valgono per tutto ciò che può finire sul sito.
const PEZZI_REGOLE_PUBBLICO = "REGOLE PER TUTTO CIÒ CHE VA SUL SITO (inderogabili):
- Anonimato: MAI nomi di persone (proprietari, clienti, parenti), indirizzi, zone o luoghi riconducibili a chi possiede il pezzo, numeri di telefono, email. Se la nota li contiene, ignorali: \"la credenza della signora Rossi\" diventa \"questa credenza\".
- MAI prezzi, costi, preventivi, tempi di consegna.
- Non inventare. Tecniche e prodotti: solo quelli detti nella nota. Epoca e stile: solo se detti, o riconoscibili con buona sicurezza dalle foto, e allora con prudenza (\"probabilmente\", \"circa\").
- Tono: caldo, concreto, artigianale, prima persona plurale (\"abbiamo\", \"in bottega\"). Niente enfasi pubblicitaria, niente emoji, niente hashtag.";

$in   = json_decode(file_get_contents('php://input'), true) ?: [];
$mode = $in['mode'] ?? '';

if ($mode === 'rifinisci') {
    $testo      = trim((string) ($in['testo'] ?? ''));
    $istruzione = trim((string) ($in['istruzione'] ?? ''));
    if ($testo === '' || $istruzione === '') { echo json_encode(['success' => false, 'error' => 'Servono testo e istruzione']); exit(); }
    $system = "Sei Sole, la voce di Ardy Lab, bottega di restauro a Roma. Correggi il testo seguendo l'istruzione di chi lavora in bottega, cambiando solo ciò che serve. Rispondi SOLO con il testo corretto.\n\n" . PEZZI_REGOLE_PUBBLICO;
    $out = pezziClaude($system, [['type' => 'text', 'text' =>
        "TESTO:\n" . mb_substr($testo, 0, 6000) . "\n\nISTRUZIONE:\n" . mb_substr($istruzione, 0, 1000)]], 1200);
    if (!is_string($out) || $out === '') { echo json_encode(['success' => false, 'error' => 'Sole non ha risposto, riprova']); exit(); }
    echo json_encode(['success' => true, 'testo' => $out, 'avvisi' => pezziAvvisiPrivacy($out)]);
    exit();
}

if ($mode !== 'racconta') { echo json_encode(['success' => false, 'error' => 'mode non valido']); exit(); }

$pezzoId = (int) ($in['pezzo_id'] ?? 0);
$faseId  = (int) ($in['fase_id'] ?? 0);
$nota    = mb_substr(trim((string) ($in['nota'] ?? '')), 0, 6000);

try {
    $db = ardyDB();
    $st = $db->prepare("SELECT titolo, scheda, storia FROM pezzi WHERE id = ? AND deleted_at IS NULL");
    $st->execute([$pezzoId]);
    $pezzo = $st->fetch(PDO::FETCH_ASSOC);
    if (!$pezzo) { echo json_encode(['success' => false, 'error' => 'Pezzo non trovato']); exit(); }
    // Gli altri capitoli danno il filo del racconto (testo rivisto, non le note grezze).
    $cs = $db->prepare("SELECT fase_nome, testo_generato FROM fasi WHERE pezzo_id = ? AND id <> ? ORDER BY ordine ASC, created_at ASC");
    $cs->execute([$pezzoId, $faseId]);
    $altri = $cs->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('ARDY PEZZI AI DB: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Errore interno']); exit();
}

// Foto: prima quelle già salvate del capitolo, poi le nuove (base64, già ridotte dal telefono).
$immagini = [];
foreach ((array) ($in['foto_salvate'] ?? []) as $fn) {
    if (count($immagini) >= PEZZI_AI_MAX_FOTO || $faseId <= 0) break;
    $path = pezziFotoDir($pezzoId, $faseId) . basename((string) $fn);
    if (is_file($path)) $immagini[] = (string) file_get_contents($path);
}
foreach ((array) ($in['immagini_nuove'] ?? []) as $b64) {
    if (count($immagini) >= PEZZI_AI_MAX_FOTO) break;
    $raw = is_string($b64) ? base64_decode($b64, true) : false;
    if ($raw !== false && strlen($raw) > 12 && strlen($raw) < 8 * 1024 * 1024) $immagini[] = $raw;
}
if ($nota === '' && !$immagini) { echo json_encode(['success' => false, 'error' => 'Serve almeno una foto o una nota']); exit(); }

$content = [];
foreach ($immagini as $raw) {
    $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($raw);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) continue;
    $content[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => base64_encode($raw)]];
}

$scheda = pezziSchedaNorm($pezzo['scheda']);
$contesto = "TITOLO ATTUALE DEL PEZZO: " . ($pezzo['titolo'] ?: 'Nuovo pezzo')
    . "\n\nSCHEDA ATTUALE (JSON):\n" . json_encode($scheda, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
    . "\n\nINTRODUZIONE ATTUALE:\n" . (trim((string) $pezzo['storia']) ?: '(ancora vuota)');
if ($altri) {
    $contesto .= "\n\nCAPITOLI GIÀ SCRITTI:";
    foreach ($altri as $c) $contesto .= "\n- " . ($c['fase_nome'] ?: 'capitolo') . ': ' . mb_substr((string) $c['testo_generato'], 0, 700);
}
$contesto .= "\n\nNOTA DETTATA PER QUESTO CAPITOLO:\n" . ($nota !== '' ? $nota : '(nessuna nota: basati sulle foto, con prudenza)');
$content[] = ['type' => 'text', 'text' => $contesto];

$system = "Sei Sole, la voce di Ardy Lab, bottega di restauro a Roma. Michela o Andrea, mentre lavorano, ti mandano le foto di un mobile e una nota dettata in fretta (anche sgrammaticata, con parole del mestiere). Tu scrivi il nuovo capitolo della storia di quel pezzo per la sua pagina pubblica sul sito, e tieni aggiornata la sua scheda.

Chi legge la pagina è un appassionato o un potenziale cliente: spiega le scelte tecniche in parole semplici (cosa abbiamo fatto e perché), senza diventare un manuale.

" . PEZZI_REGOLE_PUBBLICO . "

COSA RESTITUISCI (con lo strumento scrivi_capitolo):
- capitolo_titolo: breve, concreto (es. \"Il consolidamento del fianco\"). Max 60 caratteri.
- capitolo_testo: 80–180 parole, paragrafi brevi separati da una riga vuota.
- titolo_pezzo: se il titolo attuale è \"Nuovo pezzo\" o generico, proponine uno descrittivo (es. \"Credenza umbertina in noce\"); altrimenti restituisci quello attuale invariato.
- scheda: la scheda COMPLETA. Conserva i valori esistenti, salvo correzioni esplicite nella nota; aggiungi ciò che emerge ora. Lascia vuoto ciò che non sai.
- storia: introduzione del pezzo in 2–4 frasi (cos'è, com'è arrivato in bottega, cosa stiamo facendo). Se quella attuale va ancora bene, restituiscila invariata.
- domande: da 0 a 3 domande brevi a chi lavora, su ciò che renderebbe il racconto più ricco e giusto (es. \"Che finitura avete scelto?\"). Mai domande sul proprietario.";

$schedaProps = [];
foreach (PEZZI_SCHEDA_CAMPI as $k => $label) $schedaProps[$k] = ['type' => 'string', 'description' => $label];
$tool = [
    'name'         => 'scrivi_capitolo',
    'description'  => 'Restituisce il capitolo scritto e la scheda aggiornata del pezzo.',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'capitolo_titolo' => ['type' => 'string'],
            'capitolo_testo'  => ['type' => 'string'],
            'titolo_pezzo'    => ['type' => 'string'],
            'scheda'          => ['type' => 'object', 'properties' => $schedaProps],
            'storia'          => ['type' => 'string'],
            'domande'         => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
        'required' => ['capitolo_titolo', 'capitolo_testo', 'titolo_pezzo', 'scheda', 'storia', 'domande'],
    ],
];

$out = pezziClaude($system, $content, 2000, $tool);
if (!is_array($out) || trim((string) ($out['capitolo_testo'] ?? '')) === '') {
    echo json_encode(['success' => false, 'error' => 'Sole non ha risposto, riprova']); exit();
}

$capTesto = trim((string) $out['capitolo_testo']);
$storia   = trim((string) ($out['storia'] ?? '')) ?: trim((string) $pezzo['storia']);
// Un campo lasciato vuoto dal modello non deve cancellare ciò che la scheda sapeva già.
$nuovaScheda = pezziSchedaNorm($out['scheda'] ?? []);
foreach ($nuovaScheda as $k => $v) { if ($v === '') $nuovaScheda[$k] = $scheda[$k]; }
echo json_encode([
    'success'         => true,
    'capitolo_titolo' => mb_substr(trim((string) ($out['capitolo_titolo'] ?? '')), 0, 200),
    'capitolo_testo'  => $capTesto,
    'titolo_pezzo'    => mb_substr(trim((string) ($out['titolo_pezzo'] ?? '')), 0, 200) ?: $pezzo['titolo'],
    'scheda'          => $nuovaScheda,
    'storia'          => $storia,
    'domande'         => array_slice(array_values(array_filter(array_map('strval', (array) ($out['domande'] ?? [])))), 0, 3),
    'avvisi'          => pezziAvvisiPrivacy($capTesto . "\n" . $storia),
]);
