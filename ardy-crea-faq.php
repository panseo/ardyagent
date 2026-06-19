<?php
// -----------------------------------------------------------
// ARDY LAB — Crea FAQ della lavorazione (su stato CONSEGNATO)
// -----------------------------------------------------------
// Due azioni:
//   action=genera   → genera con Claude un set di FAQ pertinenti a partire da
//                     mobile/servizio/fasi della lavorazione. Ritorna un array
//                     JSON [{q,a}] da mostrare in anteprima MODIFICABILE.
//   action=pubblica → riceve le FAQ (eventualmente ritoccate da Michela) e le
//                     pubblica come aggiornamento dell'articolo WordPress della
//                     lavorazione, con dati strutturati schema.org/FAQPage
//                     (JSON-LD) per la SEO. Operazione idempotente: un eventuale
//                     blocco FAQ precedente viene sostituito.
//
// Visibile in dashboard solo per CONSEGNATO (vedi ardy-michela-app.html).
// Sinergia col Dossier: stessa fonte dati (tabelle clienti + fasi).
// -----------------------------------------------------------

date_default_timezone_set('Europe/Rome');
set_time_limit(120);

define('ARDY_WP_LOAD', '/home/micoperibg/public_html/archivio/wp-load.php');

require_once __DIR__ . '/ardy-config.php';
require_once __DIR__ . '/ardy-db.php';

header('Access-Control-Allow-Origin: https://ardyagent.ardy-lab.it');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

// Marcatori che delimitano il blocco FAQ nel post: permettono di sostituirlo
// (anziché accumularne uno nuovo a ogni ripubblicazione).
const FAQ_MARK_START = '<!-- ARDY_FAQ_START -->';
const FAQ_MARK_END   = '<!-- ARDY_FAQ_END -->';

function fail(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit();
}

// -- Input ------------------------------------------------------------------
$input     = json_decode(file_get_contents('php://input'), true) ?: [];
$action    = $input['action'] ?? 'genera';
$sessionId = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)($input['session_id'] ?? ''));

if ($sessionId === '') fail('session_id mancante');

// -- Carica scheda cliente (anagrafica + stato) -----------------------------
try {
    $db = ardyDB();
    $q  = $db->prepare("SELECT * FROM clienti WHERE session_id = :sid LIMIT 1");
    $q->execute([':sid' => $sessionId]);
    $cliente = $q->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('ARDY FAQ DB ERROR: ' . $e->getMessage());
    fail('Errore database', 500);
}
if (!$cliente) fail('Nessuna scheda per questa sessione', 404);

// Solo lavorazioni concluse (alias legacy PAGATO ancora tollerato).
$stato = strtoupper(trim((string)($cliente['stato'] ?? '')));
if (!in_array($stato, ['CONSEGNATO', 'PAGATO'], true)) {
    fail('Le FAQ si creano solo per una lavorazione CONSEGNATA');
}

$mobile   = trim((string)($cliente['mobile']   ?? ''));
$servizio = trim((string)($cliente['servizio'] ?? ''));

if ($action === 'genera') {
    echo json_encode(azioneGenera($db, $sessionId, $mobile, $servizio));
    exit();
}

if ($action === 'pubblica') {
    $faq      = $input['faq'] ?? [];
    $wpPostId = !empty($input['wp_post_id']) ? (int)$input['wp_post_id'] : (int)($cliente['wp_post_id'] ?? 0);
    echo json_encode(azionePubblica($db, $sessionId, $mobile, $faq, $wpPostId));
    exit();
}

fail('Azione non valida');

// ===========================================================================
// AZIONE: GENERA
// ===========================================================================
function azioneGenera(PDO $db, string $sessionId, string $mobile, string $servizio): array {
    // Raccogli le fasi (la "storia" della lavorazione) come contesto per Claude.
    $fasiTxt = '';
    try {
        $qf = $db->prepare("SELECT fase_nome, testo_generato, testo_breve
                            FROM fasi WHERE session_id = :sid
                            AND (fase_tipo IS NULL OR fase_tipo <> 'comunicazione')
                            ORDER BY created_at ASC");
        $qf->execute([':sid' => $sessionId]);
        foreach ($qf->fetchAll(PDO::FETCH_ASSOC) as $f) {
            $nome  = trim((string)($f['fase_nome'] ?? ''));
            $testo = trim((string)($f['testo_generato'] ?: ($f['testo_breve'] ?? '')));
            if ($nome === '' && $testo === '') continue;
            $fasiTxt .= '- ' . ($nome !== '' ? $nome : 'Fase') . ': '
                      . mb_substr(preg_replace('/\s+/', ' ', $testo), 0, 400) . "\n";
        }
    } catch (PDOException $e) {
        error_log('ARDY FAQ FASI ERROR: ' . $e->getMessage());
    }

    $faq = generaFaqConClaude($mobile, $servizio, $fasiTxt);
    if (!$faq) fail('Non sono riuscito a generare le FAQ. Riprova.', 502);

    return ['success' => true, 'faq' => $faq, 'mobile' => $mobile];
}

// Chiede a Claude un set di FAQ in JSON stretto: [{"q":"...","a":"..."}].
// Ritorna l'array PHP normalizzato, oppure [] in caso di errore/parse fallito.
function generaFaqConClaude(string $mobile, string $servizio, string $fasiTxt): array {
    if (!defined('ARDY_API_KEY') || ARDY_API_KEY === '') return [];

    $ctx = "Mobile/oggetto: " . ($mobile !== '' ? $mobile : 'non specificato') . "\n"
         . "Servizio: " . ($servizio !== '' ? $servizio : 'restauro / laccatura') . "\n"
         . "Fasi della lavorazione svolta:\n" . ($fasiTxt !== '' ? $fasiTxt : "(non disponibili)\n");

    $prompt = "Sei un esperto restauratore di mobili di Ardy Lab, bottega artigianale di "
        . "restauro e laccatura a Roma. A partire da questa lavorazione realmente svolta, "
        . "scrivi delle FAQ (domande frequenti) utili e pertinenti che un potenziale cliente "
        . "interessato a un lavoro simile potrebbe porsi.\n\n"
        . $ctx . "\n"
        . "Regole:\n"
        . "- Da 5 a 7 FAQ.\n"
        . "- Domande concrete e cercate davvero su Google (durata, costi indicativi senza "
        . "inventare cifre precise, manutenzione, materiali, differenze tra tecniche, garanzie).\n"
        . "- Risposte di 2-4 frasi, tono competente e rassicurante, italiano naturale.\n"
        . "- NON inventare prezzi precisi né tempi esatti: parla in modo indicativo.\n"
        . "- NON usare elenchi puntati dentro le risposte.\n\n"
        . "Rispondi ESCLUSIVAMENTE con un array JSON valido, senza testo prima o dopo, "
        . "in questo formato: [{\"q\":\"domanda\",\"a\":\"risposta\"}]";

    $payload = json_encode([
        'model'      => 'claude-sonnet-4-6',
        'max_tokens' => 1500,
        'messages'   => [['role' => 'user', 'content' => $prompt]],
    ]);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt($ch, CURLOPT_POST,           true);
    curl_setopt($ch, CURLOPT_POSTFIELDS,     $payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT,        90);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'x-api-key: ' . ARDY_API_KEY,
        'anthropic-version: 2023-06-01',
    ]);
    $res  = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($http !== 200) { error_log('ARDY FAQ CLAUDE: HTTP ' . $http); return []; }

    $data = json_decode($res, true);
    $text = $data['content'][0]['text'] ?? '';
    return parseFaqJson($text);
}

// Estrae e normalizza l'array di FAQ dal testo del modello (tollerante: rimuove
// eventuali recinti markdown e isola l'array JSON).
function parseFaqJson(string $text): array {
    $text = trim($text);
    // togli ```json ... ``` se presente
    $text = preg_replace('/^```[a-zA-Z]*\s*|\s*```$/m', '', $text);
    // isola dal primo '[' all'ultimo ']'
    $start = strpos($text, '[');
    $end   = strrpos($text, ']');
    if ($start === false || $end === false || $end <= $start) return [];
    $json = substr($text, $start, $end - $start + 1);

    $arr = json_decode($json, true);
    if (!is_array($arr)) return [];

    $out = [];
    foreach ($arr as $item) {
        if (!is_array($item)) continue;
        $q = trim((string)($item['q'] ?? $item['question'] ?? ''));
        $a = trim((string)($item['a'] ?? $item['answer']   ?? ''));
        if ($q === '' || $a === '') continue;
        $out[] = ['q' => $q, 'a' => $a];
        if (count($out) >= 10) break;
    }
    return $out;
}

// ===========================================================================
// AZIONE: PUBBLICA
// ===========================================================================
function azionePubblica(PDO $db, string $sessionId, string $mobile, $faq, int $wpPostId): array {
    // Normalizza/valida le FAQ in arrivo dalla dashboard (post-editing).
    $clean = [];
    if (is_array($faq)) {
        foreach ($faq as $item) {
            if (!is_array($item)) continue;
            $q = trim((string)($item['q'] ?? ''));
            $a = trim((string)($item['a'] ?? ''));
            if ($q === '' || $a === '') continue;
            $clean[] = ['q' => $q, 'a' => $a];
            if (count($clean) >= 10) break;
        }
    }
    if (!$clean) fail('Nessuna FAQ valida da pubblicare');
    if ($wpPostId <= 0) fail('Articolo WordPress della lavorazione non trovato: pubblica almeno una fase prima.');

    // --- Carica WordPress (stesso schema di ardy-pubblica-lavorazione.php) ---
    if (!file_exists(ARDY_WP_LOAD)) fail('wp-load.php non trovato', 500);
    $_SERVER['HTTP_HOST']   = 'ardy-lab.it';
    $_SERVER['REQUEST_URI'] = '/';
    if (!defined('WP_USE_THEMES')) define('WP_USE_THEMES', false);
    require_once ARDY_WP_LOAD;

    $post = get_post($wpPostId);
    if (!$post) fail('Articolo WordPress non trovato (id ' . $wpPostId . ')', 404);

    $blocco = costruisciBloccoFaq($clean, $mobile);

    // Rimuovi un eventuale blocco FAQ precedente (idempotenza) e accoda il nuovo.
    $contenuto = rimuoviBloccoFaq($post->post_content);
    $contenuto = rtrim($contenuto) . "\n" . $blocco;

    // Senza utente WP loggato, kses rimuoverebbe <script>/<details>/style:
    // disattiviamo i filtri attorno all'update (contenuto generato da noi).
    kses_remove_filters();
    $result = wp_update_post(['ID' => $wpPostId, 'post_content' => $contenuto], true);
    kses_init_filters();

    if (is_wp_error($result)) {
        error_log('ARDY FAQ WP UPDATE ERROR: ' . $result->get_error_message());
        fail('Aggiornamento della pagina non riuscito', 500);
    }
    $postLink = get_permalink($wpPostId);

    // Segna l'avvenuta pubblicazione FAQ sul CRM (colonna creata da ardy-migrate.php).
    try {
        $db->prepare("UPDATE clienti SET faq_pubblicata_at = NOW(), updated_at = NOW() WHERE session_id = :sid")
           ->execute([':sid' => $sessionId]);
    } catch (PDOException $e) {
        error_log('ARDY FAQ CRM UPDATE ERROR: ' . $e->getMessage());
    }

    return [
        'success'   => true,
        'wp_post_id'=> (string)$wpPostId,
        'post_link' => $postLink,
        'count'     => count($clean),
    ];
}

// Costruisce il blocco HTML delle FAQ + il JSON-LD FAQPage, racchiuso tra i
// marcatori così da poter essere sostituito a una nuova pubblicazione.
function costruisciBloccoFaq(array $faq, string $mobile): string {
    $titolo = $mobile !== '' ? ('Domande frequenti — ' . $mobile) : 'Domande frequenti';

    // Parte visibile: ogni FAQ in un <details> (accordion nativo, senza JS).
    $html  = "\n" . FAQ_MARK_START . "\n";
    $html .= '<div style="border-top:2px solid #c8a96e;margin:40px 0 0;padding-top:24px;">' . "\n";
    $html .= '  <h2 style="font-family:Georgia,serif;font-size:22px;color:#444;margin-bottom:16px;">'
           . htmlspecialchars($titolo, ENT_QUOTES, 'UTF-8') . '</h2>' . "\n";
    foreach ($faq as $f) {
        $q = htmlspecialchars($f['q'], ENT_QUOTES, 'UTF-8');
        $a = nl2br(htmlspecialchars($f['a'], ENT_QUOTES, 'UTF-8'));
        $html .= '  <details style="border:1px solid #ece7dd;border-radius:8px;margin:10px 0;padding:4px 16px;background:#fafaf8;">' . "\n";
        $html .= '    <summary style="cursor:pointer;font-weight:bold;font-size:16px;color:#333;padding:10px 0;font-family:sans-serif;">'
               . $q . '</summary>' . "\n";
        $html .= '    <div style="font-size:15px;line-height:1.7;color:#444;padding:4px 0 14px;">' . $a . '</div>' . "\n";
        $html .= '  </details>' . "\n";
    }
    $html .= '</div>' . "\n";

    // Dati strutturati: schema.org/FAQPage (Google li legge ovunque nel body).
    $entities = [];
    foreach ($faq as $f) {
        $entities[] = [
            '@type'          => 'Question',
            'name'           => $f['q'],
            'acceptedAnswer' => [
                '@type' => 'Answer',
                'text'  => $f['a'],
            ],
        ];
    }
    $ld = [
        '@context'   => 'https://schema.org',
        '@type'      => 'FAQPage',
        'mainEntity' => $entities,
    ];
    $html .= '<script type="application/ld+json">'
           . json_encode($ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG)
           . '</script>' . "\n";
    $html .= FAQ_MARK_END . "\n";

    return $html;
}

// Rimuove dal contenuto un blocco FAQ delimitato dai marcatori (se presente).
function rimuoviBloccoFaq(string $contenuto): string {
    $s = strpos($contenuto, FAQ_MARK_START);
    $e = strpos($contenuto, FAQ_MARK_END);
    if ($s === false || $e === false || $e < $s) return $contenuto;
    $e += strlen(FAQ_MARK_END);
    return substr($contenuto, 0, $s) . substr($contenuto, $e);
}
