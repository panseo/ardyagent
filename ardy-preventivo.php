<?php
/**
 * ardy-preventivo.php
 * Generatore preventivi PDF — Ardy Lab
 * Richiede: wkhtmltopdf installato sul VPS
 *
 * POST /ardy-preventivo.php             → genera PDF e lo scarica
 * POST /ardy-preventivo.php?mode=preview → restituisce HTML (anteprima)
 * POST /ardy-preventivo.php?mode=save    → salva PDF su disco, risponde JSON
 */

header('X-Content-Type-Options: nosniff');

date_default_timezone_set('Europe/Rome');

require_once __DIR__ . '/ardy-net.php';

define('MPDF_VENDOR', __DIR__ . '/vendor/autoload.php');
define('PDF_OUTPUT_DIR', __DIR__ . '/preventivi_pdf/');
define('LOGO_PATH',      __DIR__ . '/assets/logo.png');

if (!is_dir(PDF_OUTPUT_DIR) && !mkdir(PDF_OUTPUT_DIR, 0755, true) && !is_dir(PDF_OUTPUT_DIR)) {
    http_response_code(500);
    echo json_encode(['error' => 'Impossibile creare la cartella PDF']);
    exit;
}
ardyHardenUploadDir(PDF_OUTPUT_DIR); // i PDF generati non devono eseguire script
if (!file_exists(MPDF_VENDOR)) {
    http_response_code(500);
    echo json_encode(['error' => 'mPDF non installato. Esegui: php composer.phar require mpdf/mpdf']);
    exit;
}
require_once MPDF_VENDOR;

$mode = $_GET['mode'] ?? 'download';

// ─── Cambio stato preventivo (MUTAZIONE) ────────────────────────────────────────
// Operazione che modifica lo stato: ammessa solo via POST e con header custom
// (anti-CSRF). Una richiesta forgiata via <img>/<form> cross-site non può
// impostare X-Requested-With, e una fetch cross-origin scatenerebbe un preflight
// CORS che questo endpoint non autorizza. Sostituisce la vecchia GET CSRF-able.
if ($mode === 'stato') {
    header('Content-Type: application/json');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        echo json_encode(['error' => 'Metodo non consentito. Usa POST.']);
        exit;
    }
    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest') {
        http_response_code(403);
        echo json_encode(['error' => 'Richiesta non autorizzata']);
        exit;
    }
    $id    = intval($_POST['id'] ?? 0);
    $stato = $_POST['stato'] ?? 'bozza';
    $stati = ['bozza','inviato','accettato','rifiutato'];
    if (!$id || !in_array($stato, $stati, true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Parametri non validi']);
        exit;
    }
    $db   = dbConnect();
    $stmt = $db->prepare("UPDATE preventivi SET stato=? WHERE id=?");
    $stmt->bind_param('si', $stato, $id);
    $stmt->execute();
    echo json_encode(['success' => true, 'stato' => $stato]);
    exit;
}

// ─── Endpoint GET ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {

    // GET ?mode=lista&session_id=XXX → lista preventivi di un cliente
    if ($mode === 'lista') {
        $sid = $_GET['session_id'] ?? '';
        if (!$sid) { echo json_encode([]); exit; }
        $db   = dbConnect();
        $stmt = $db->prepare("SELECT id, numero, tipo, oggetto, subtotale, grand_total, file_pdf, stato, data_emissione, data_scadenza, created_at FROM preventivi WHERE session_id=? ORDER BY created_at DESC");
        $stmt->bind_param('s', $sid);
        $stmt->execute();
        $res  = $stmt->get_result();
        $rows = [];
        while ($r = $res->fetch_assoc()) $rows[] = $r;
        header('Content-Type: application/json');
        echo json_encode($rows);
        exit;
    }

    // GET ?mode=download&file=XXX → scarica PDF già generato
    if ($mode === 'download') {
        $file = basename($_GET['file'] ?? '');
        $path = PDF_OUTPUT_DIR . $file;
        if (!$file || !file_exists($path)) {
            http_response_code(404);
            echo json_encode(['error' => 'File non trovato']);
            exit;
        }
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $file . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Mode non valido']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Metodo non consentito. Usa POST.']);
    exit;
}

// ─── Raccolta dati ─────────────────────────────────────────────────────────────

$dati = sanitizeInput([
    'tipo_preventivo'   => $_POST['tipo_preventivo']   ?? 'Preventivo Servizi',
    'numero_preventivo' => $_POST['numero_preventivo'] ?? generaNumero(),
    'data_emissione'    => $_POST['data_emissione']    ?? date('d/m/Y'),
    'data_scadenza'     => $_POST['data_scadenza']     ?? '',
    'oggetto'           => $_POST['oggetto']           ?? '',
    // Azienda — dati fissi forfettario
    'azienda_nome'      => 'Ardy di Michela Panella',
    'azienda_indirizzo' => 'Via James Joyce 4, 00143 Roma (RM)',
    'azienda_piva'      => '17633931005',
    'azienda_cf'        => 'PNLMHL99A48H501E',
    'azienda_email'     => 'ardy.documenti@gmail.com',
    'azienda_web'       => 'www.ardy-lab.it',
    // Cliente
    'cliente_nome'      => $_POST['cliente_nome']      ?? '',
    'cliente_azienda'   => $_POST['cliente_azienda']   ?? '',
    'cliente_indirizzo' => $_POST['cliente_indirizzo'] ?? '',
    'cliente_piva'      => $_POST['cliente_piva']      ?? '',
    'cliente_email'     => $_POST['cliente_email']     ?? '',
    'cliente_cf'        => $_POST['cliente_cf']        ?? '',
    // Opzioni
    'valuta'            => $_POST['valuta']            ?? 'EUR',
    'note'              => $_POST['note']              ?? '',
    'condizioni'        => $_POST['condizioni']        ?? '',
    'tempi_consegna'    => $_POST['tempi_consegna']    ?? '20 giorni lavorativi',
    'acconto_perc'      => $_POST['acconto_perc']      ?? '30',
    'mostra_storia'     => $_POST['mostra_storia']     ?? '1',
    'mostra_firma'      => $_POST['mostra_firma']      ?? '1',
    'bollo'             => $_POST['bollo']             ?? '2',
    'spedizione'        => $_POST['spedizione']        ?? 'gratis',
]);

// Parser di una lista di voci grezze → voci normalizzate con importo.
$parseVoci = function ($raw): array {
    $out = [];
    if (!empty($raw) && is_array($raw)) {
        foreach ($raw as $v) {
            $desc   = htmlspecialchars(trim($v['descrizione'] ?? ''), ENT_QUOTES, 'UTF-8');
            $qty    = floatval($v['quantita'] ?? 1);
            $um     = htmlspecialchars(trim($v['unita'] ?? ''), ENT_QUOTES, 'UTF-8');
            $prezzo = floatval($v['prezzo'] ?? 0);
            $sconto = floatval($v['sconto'] ?? 0);
            if ($desc === '') continue;
            $importo = $qty * $prezzo * (1 - $sconto / 100);
            $out[] = compact('desc', 'qty', 'um', 'prezzo', 'sconto', 'importo');
        }
    }
    return $out;
};

// Bollo / spedizione: per-documento, inclusi nel totale di OGNI opzione (il
// cliente ne sceglie una, quindi ciascuna mostra il suo prezzo finale).
$bollo    = floatval($dati['bollo']);
$sped_val = strtolower(trim($dati['spedizione']));
$sped_num = (is_numeric($sped_val)) ? floatval($sped_val) : 0;

// Opzioni a pacchetto: ognuna con le sue voci e il suo totale.
// Fallback legacy: se arriva 'voci' piatto, diventa un'opzione unica senza nome.
$opzioni = [];
if (!empty($_POST['opzioni']) && is_array($_POST['opzioni'])) {
    foreach ($_POST['opzioni'] as $o) {
        $voci = $parseVoci($o['voci'] ?? null);
        if (empty($voci)) continue;
        $sub = array_sum(array_column($voci, 'importo'));
        $prima = parseImgDataUris(isset($o['prima']) && is_string($o['prima']) ? [$o['prima']] : []);
        $dopo  = parseImgDataUris(isset($o['dopo'])  && is_string($o['dopo'])  ? [$o['dopo']]  : []);
        $opzioni[] = [
            'nome'      => htmlspecialchars(trim($o['nome'] ?? ''), ENT_QUOTES, 'UTF-8'),
            'voci'      => $voci,
            'subtotale' => $sub,
            'totale'    => $sub + $bollo + $sped_num,
            'prima'     => $prima[0] ?? '',
            'dopo'      => $dopo[0] ?? '',
        ];
    }
}
if (empty($opzioni) && !empty($_POST['voci'])) {
    $voci = $parseVoci($_POST['voci']);
    if (!empty($voci)) {
        $sub = array_sum(array_column($voci, 'importo'));
        $opzioni[] = ['nome' => '', 'voci' => $voci, 'subtotale' => $sub, 'totale' => $sub + $bollo + $sped_num, 'prima' => '', 'dopo' => ''];
    }
}
if (empty($opzioni)) {
    http_response_code(400);
    echo json_encode(['error' => 'Inserisci almeno una voce.']);
    exit;
}

// Totali rappresentativi per il DB / risposta: prima opzione.
$subtotale   = $opzioni[0]['subtotale'];
$grand_total = $opzioni[0]['totale'];

// Immagine di copertina (unica, a tutta pagina). Le prima/dopo stanno dentro le opzioni.
$covArr    = parseImgDataUris(isset($_POST['copertina']) && is_string($_POST['copertina']) ? [$_POST['copertina']] : []);
$copertina = $covArr[0] ?? '';

if ($mode === 'preview') {
    header('Content-Type: text/html; charset=utf-8');
    $pagine = buildPagine($dati, $opzioni, $bollo, $sped_val, $copertina);
    $css    = buildCss();
    echo '<!DOCTYPE html><html lang="it"><head><meta charset="UTF-8">'
       . '<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;700&display=swap" rel="stylesheet">'
       . '<style>' . strip_tags($css) . ' .page-sep{border:2px dashed #ccc;margin:20px 0;text-align:center;padding:4px;font-size:10px;color:#999;}</style>'
       . '</head><body>';
    foreach ($pagine as $i => $p) {
        if ($i > 0) echo '<div class="page-sep">— pagina ' . ($i+1) . ' —</div>';
        echo $p;
    }
    echo '</body></html>';
    exit;
}

// DEBUG: scarica HTML grezzo
if ($mode === 'debug') {
    header('Content-Type: text/plain; charset=utf-8');
    $pagine = buildPagine($dati, $opzioni, $bollo, $sped_val, $copertina);
    foreach ($pagine as $i => $p) {
        echo "=== PAGINA " . ($i+1) . " ===\n" . $p . "\n\n";
    }
    exit;
}

$nomeFile = 'preventivo_' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $dati['numero_preventivo']) . '.pdf';
$pdfPath  = PDF_OUTPUT_DIR . $nomeFile;

try {
    $mpdf = new \Mpdf\Mpdf([
        'mode'              => 'utf-8',
        'format'            => 'A4',
        'margin_top'        => 0,
        'margin_bottom'     => 0,
        'margin_left'       => 0,
        'margin_right'      => 0,
        'tempDir'           => sys_get_temp_dir(),
    ]);

    // CSS comune a tutte le pagine
    $css = buildCss();
    $mpdf->WriteHTML($css, \Mpdf\HTMLParserMode::HEADER_CSS);

    // Pagine separate
    $pagine = buildPagine($dati, $opzioni, $bollo, $sped_val, $copertina);
    foreach ($pagine as $i => $pageHtml) {
        if ($i > 0) $mpdf->AddPage();
        $mpdf->WriteHTML($pageHtml, \Mpdf\HTMLParserMode::HTML_BODY);
    }

    $mpdf->Output($pdfPath, 'F');
} catch (\Exception $e) {
    error_log('ARDY PREVENTIVO MPDF ERROR: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Errore nella generazione del PDF']);
    exit;
}

// ─── Salva nel DB ─────────────────────────────────────────────────────────────
$sessionId   = $_POST['session_id'] ?? '';
$vociJson    = json_encode($opzioni);
$db          = dbConnect();
$stmt        = $db->prepare("
    INSERT INTO preventivi
        (session_id, numero, tipo, oggetto, cliente_nome, cliente_email,
         note, condizioni, voci_json, subtotale, grand_total,
         file_pdf, stato, data_emissione, data_scadenza)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ON DUPLICATE KEY UPDATE
        tipo=VALUES(tipo), oggetto=VALUES(oggetto),
        note=VALUES(note), condizioni=VALUES(condizioni),
        voci_json=VALUES(voci_json), subtotale=VALUES(subtotale),
        grand_total=VALUES(grand_total), file_pdf=VALUES(file_pdf),
        stato=VALUES(stato), data_scadenza=VALUES(data_scadenza),
        updated_at=NOW()
");
$stato = 'bozza';
$stmt->bind_param('sssssssssddssss',
    $sessionId,
    $dati['numero_preventivo'],
    $dati['tipo_preventivo'],
    $dati['oggetto'],
    $dati['cliente_nome'],
    $dati['cliente_email'],
    $dati['note'],
    $dati['condizioni'],
    $vociJson,
    $subtotale,
    $grand_total,
    $nomeFile,
    $stato,
    $dati['data_emissione'],
    $dati['data_scadenza']
);
$stmt->execute();
$prevId = $db->insert_id ?: $stmt->insert_id;

if ($mode === 'save') {
    header('Content-Type: application/json');
    echo json_encode([
        'success'     => true,
        'id'          => $prevId,
        'file'        => $nomeFile,
        'numero'      => $dati['numero_preventivo'],
        'cliente'     => $dati['cliente_nome'],
        'totale'      => '€ ' . number_format($grand_total, 2, ',', '.'),
        'generato_il' => date('d/m/Y H:i'),
    ]);
    exit;
}

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $nomeFile . '"');
header('Content-Length: ' . filesize($pdfPath));
readfile($pdfPath);
exit;

// ═══════════════════════════════════════════════════════════════════════════════
// FUNZIONI
// ═══════════════════════════════════════════════════════════════════════════════

function dbConnect(): mysqli {
    if (!file_exists(__DIR__ . '/ardy-config.php')) {
        http_response_code(500);
        echo json_encode(['error' => 'ardy-config.php non trovato']);
        exit;
    }
    require_once __DIR__ . '/ardy-config.php';
    $db = new mysqli(ARDY_DB_HOST, ARDY_DB_USER, ARDY_DB_PASS, ARDY_DB_NAME);
    if ($db->connect_error) {
        error_log('ARDY PREVENTIVO DB ERROR: ' . $db->connect_error);
        http_response_code(500);
        echo json_encode(['error' => 'Errore interno']);
        exit;
    }
    $db->set_charset('utf8mb4');
    return $db;
}

function sanitizeInput(array $data): array {
    return array_map(fn($v) => htmlspecialchars(trim((string)$v), ENT_QUOTES, 'UTF-8'), $data);
}

function generaNumero(): string {
    return 'ARD-' . date('Y') . '-' . str_pad(mt_rand(1,9999), 4, '0', STR_PAD_LEFT);
}

function fmtEuro(float $v): string {
    return '€' . number_format($v, 2, ',', '.');
}

function logoBase64(): string {
    if (!file_exists(LOGO_PATH)) return '';
    return 'data:' . mime_content_type(LOGO_PATH) . ';base64,' . base64_encode(file_get_contents(LOGO_PATH));
}

/** Valida una lista di data-URL immagine (jpeg/png/webp), scartando le non valide
 *  o troppo grandi. Ritorna al massimo $max elementi pronti per <img src>. */
function parseImgDataUris($arr, int $max = 8): array {
    if (!is_array($arr)) return [];
    $out = [];
    foreach ($arr as $d) {
        if (!is_string($d)) continue;
        if (!preg_match('#^data:image/(jpeg|jpg|png|webp);base64,[A-Za-z0-9+/=\s]+$#i', $d)) continue;
        if (strlen($d) > 4000000) continue; // ~3 MB per immagine, già ridotte dal client
        $out[] = $d;
        if (count($out) >= $max) break;
    }
    return $out;
}


// ═══════════════════════════════════════════════════════════════════════════════
// CSS — iniettato come header CSS in mPDF
// ═══════════════════════════════════════════════════════════════════════════════

function buildCss(): string {
    return '<style>
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family: Arial, sans-serif; font-size: 10pt; color: #111; line-height: 1.55; }

/* COPERTINA */
.cover { background: #000; color: #fff; padding: 40px 40px 32px; }
.cover-logo { font-size: 72pt; font-weight: 700; color: #fff; line-height: 0.9; letter-spacing: -3px; }
.cover-services { font-size: 13pt; color: #ddd; line-height: 1.8; margin-top: 16px; }
.cover-divider { border-top: 1px solid #333; margin: 24px 0 16px; }
.cover-footer-addr { font-size: 9pt; color: #aaa; line-height: 1.7; text-decoration: underline; }
.cover-footer-web { font-size: 9pt; font-weight: 700; color: #fff; line-height: 1.9; margin-top: 8px; }
.cover-project { font-size: 13pt; color: #7bb8d4; margin-top: 20px; }

/* COPERTINA A TUTTA PAGINA (immagine unica, full-bleed) */
.cover-full { width: 210mm; height: 297mm; margin: 0; padding: 0; }

/* PROPOSTA per opzione (prima/dopo) — niente object-fit: mPDF non lo supporta */
.proposal-h { font-size: 20pt; font-weight: 700; margin-bottom: 6px; }
.ba-row { width: 100%; border-collapse: collapse; margin: 8px 0 14px; }
.ba-cell { width: 50%; padding: 5px; vertical-align: top; text-align: center; }
.ba-img { width: 100%; height: auto; border: 1px solid #eee; border-radius: 4px; }
.ba-tag { font-size: 9pt; font-weight: 700; text-transform: uppercase; color: #777; margin-bottom: 4px; letter-spacing: 0.5px; }
.ba-empty { color: #bbb; padding: 28px 0; border: 1px dashed #ddd; border-radius: 4px; }

/* PAGINE INTERNE */
.inner { padding: 22mm 18mm; }
.footer-line { border-top: 1px solid #ddd; padding-top: 10px; margin-top: 20px; font-size: 8pt; color: #aaa; }
.footer-left { float: left; }
.footer-right { float: right; }

/* STORIA */
.storia-h { font-size: 22pt; font-weight: 700; margin-bottom: 18px; }
.storia-p { font-size: 10pt; line-height: 1.7; margin-bottom: 12px; }

/* TECNICA */
.prev-title { font-size: 20pt; font-weight: 700; text-align: right; margin-bottom: 20px; }
.prev-oggetto { font-size: 10pt; font-weight: 700; margin-bottom: 18px; text-transform: uppercase; }
.proc-h { font-size: 11pt; font-weight: 700; margin: 14px 0 6px; }
.proc-p { font-size: 10pt; line-height: 1.7; }

/* TABELLA COSTI */
.budget-h1 { font-size: 18pt; font-weight: 700; text-align: right; line-height: 1.1; }
.budget-h2 { font-size: 18pt; font-style: italic; text-align: right; line-height: 1.1; }
.budget-sub { font-size: 11pt; font-weight: 700; font-style: italic; color: #555; margin: 16px 0 8px; }
table.costi { width: 100%; border-collapse: collapse; font-size: 10pt; }
table.costi th { background: #111; color: #fff; padding: 8px 10px; font-size: 9.5pt; text-align: left; }
table.costi td { padding: 7px 10px; border: 1px solid #ddd; font-size: 10pt; }
.tr { text-align: right; }
.tc { text-align: center; }
.bold { font-weight: 700; font-style: italic; }
.row-sub td { background: #e0e0e0; font-weight: 700; }
.row-grand td { background: #111; color: #fff; font-weight: 700; font-size: 11pt; }
.cond-h { font-size: 11pt; font-weight: 700; font-style: italic; margin: 20px 0 8px; }
.cond-p { font-size: 9.5pt; line-height: 1.75; font-style: italic; }

/* FIRMA */
.firma-h { font-size: 16pt; font-weight: 700; text-align: center; margin-bottom: 16px; }
.firma-intro { font-size: 10pt; margin-bottom: 18px; }
table.firma-t { width: 100%; border-collapse: collapse; margin-bottom: 24px; }
.fl { font-weight: 700; font-size: 8.5pt; padding: 7px 10px 7px 0; width: 120px; color: #555; text-transform: uppercase; }
.fv { border-bottom: 1px solid #ccc; padding: 7px 0; font-size: 10pt; }
.validita { font-size: 10pt; font-weight: 700; margin-top: 16px; padding: 10px 14px; background: #f5f5f5; border-left: 4px solid #111; }
.privacy-h { font-size: 11pt; font-weight: 700; margin: 22px 0 6px; }
.privacy-p { font-size: 7.8pt; line-height: 1.5; color: #444; text-align: justify; margin-bottom: 6px; }
.privacy-consent { font-size: 9pt; line-height: 1.5; margin-top: 12px; padding: 10px 12px; border: 1px solid #111; }
.privacy-box { display: inline-block; width: 11px; height: 11px; border: 1.5px solid #111; margin-right: 7px; vertical-align: middle; }
.firma-line-l { width: 48%; float: left; border-top: 1.5px solid #888; padding-top: 6px; font-size: 9pt; color: #666; text-align: center; margin-top: 32px; }
.firma-line-r { width: 48%; float: right; border-top: 1.5px solid #888; padding-top: 6px; font-size: 9pt; color: #666; text-align: center; margin-top: 32px; }

/* GRAZIE */
.grazie-page { background: #7bb8d4; padding: 50px 40px 40px; }
.grazie-h { font-size: 80pt; font-weight: 400; color: #000; line-height: 1; margin-bottom: 40px; }
.grazie-ardy { font-size: 80pt; font-weight: 700; color: #000; text-align: right; margin-top: 60px; }
.grazie-addr { font-size: 9pt; color: #1a3a4a; line-height: 1.7; margin-top: 40px; text-decoration: underline; }
.grazie-web { font-size: 9pt; color: #1a3a4a; line-height: 1.9; }
</style>';
}

// ═══════════════════════════════════════════════════════════════════════════════
// PAGINE — array di stringhe HTML, una per pagina
// ═══════════════════════════════════════════════════════════════════════════════

function buildPagine(array $d, array $opzioni, float $bollo, string $spedizione, string $copertina = ''): array {

    $mostraStoria = ($d['mostra_storia'] ?? '1') === '1';
    $valuta       = $d['valuta'];
    $multiOpz     = count($opzioni) > 1;

    // Renderer delle righe di UNA opzione (voci + subtotale/bollo/sped/totale).
    $spedDisplay = (strtolower($spedizione) === 'gratis' || $spedizione === '0') ? 'gratis' : fmtEuro(floatval($spedizione));
    $righeOpzione = function (array $opz) use ($bollo, $spedDisplay): string {
        $r = '';
        foreach ($opz['voci'] as $v) {
            $qty = ($v['qty'] != 1) ? number_format($v['qty'], 0, ',', '.') : '1';
            $um  = $v['um'] ? ' ' . $v['um'] : '';
            $r .= '<tr>'
                . '<td>' . $v['desc'] . '</td>'
                . '<td class="tc">' . $qty . $um . '</td>'
                . '<td class="tr">' . fmtEuro($v['prezzo']) . '</td>'
                . '<td class="tr bold">' . fmtEuro($v['importo']) . '</td>'
                . '</tr>';
        }
        $r .= '<tr class="row-sub"><td colspan="3" class="tr">Subtotale</td><td class="tr bold">' . fmtEuro($opz['subtotale']) . '</td></tr>';
        $r .= '<tr><td colspan="3" class="tr">Bollo</td><td class="tr">' . fmtEuro($bollo) . '</td></tr>';
        $r .= '<tr><td colspan="3" class="tr">Spedizione</td><td class="tr">' . $spedDisplay . '</td></tr>';
        $r .= '<tr class="row-grand"><td colspan="3" class="tr">Totale</td><td class="tr bold">' . fmtEuro($opz['totale']) . '</td></tr>';
        return $r;
    };

    // Condizioni
    $acconto = floatval($d['acconto_perc'] ?? 30);
    $condDefault = "Tempi di consegna: L'intervento sarà completato entro " . ($d['tempi_consegna'] ?? '20 giorni lavorativi') . ".\n\n"
        . "Modalità di pagamento:\n"
        . "• Opzione A: Acconto del {$acconto}% sulla manodopera + costo materiali; Saldo alla consegna.\n"
        . "• Opzione B: Pagamento dilazionato tramite PayPal Rate.\n\n"
        . "Garanzia: Ogni intervento è accompagnato da regolare fattura.\n\n"
        . "Validità preventivo: 30 giorni dalla data odierna.";
    $condHtml = nl2br($d['condizioni'] ?: $condDefault);

    // Dati cliente
    $clienteRows = '';
    foreach (['Nome' => $d['cliente_nome'], 'Indirizzo' => $d['cliente_indirizzo'], 'P.IVA' => $d['cliente_piva'] ?: $d['cliente_cf'], 'Email' => $d['cliente_email']] as $l => $v) {
        if ($v) $clienteRows .= '<tr><td class="fl">' . $l . '</td><td class="fv">' . $v . '</td></tr>';
    }
    $clienteRows .= '<tr><td class="fl">PEC</td><td class="fv">&nbsp;</td></tr>';

    $footerLeft  = 'Ardy di Michela Panella — P.IVA ' . $d['azienda_piva'];
    $footerRight = 'N° ' . $d['numero_preventivo'] . ' — ' . $d['data_emissione'];
    $footer      = '<div class="footer-line"><span class="footer-left">' . $footerLeft . '</span><span class="footer-right">' . $footerRight . '</span><br style="clear:both"></div>';

    // Informativa privacy / GDPR (Reg. UE 2016/679) — clausola da accettare e firmare
    $privacyHtml = '
  <div class="privacy-h">Informativa sul trattamento dei dati personali (art. 13 Reg. UE 2016/679 - GDPR)</div>
  <p class="privacy-p"><strong>Titolare del trattamento:</strong> ' . $d['azienda_nome'] . ', ' . $d['azienda_indirizzo'] . ' - P.IVA ' . $d['azienda_piva'] . ' - email ' . $d['azienda_email'] . '.</p>
  <p class="privacy-p"><strong>Finalità e base giuridica:</strong> i dati forniti (nome, indirizzo, P.IVA/C.F., email e dati di contatto) sono trattati per dare esecuzione al presente preventivo/contratto e ai relativi rapporti precontrattuali (art. 6.1.b GDPR), per la fatturazione e per l\'adempimento degli obblighi fiscali, contabili e di legge (art. 6.1.c GDPR).</p>
  <p class="privacy-p"><strong>Destinatari:</strong> i dati possono essere comunicati a consulente fiscale/commercialista, istituti di pagamento e Autorità competenti, esclusivamente per le finalità sopra indicate. Non sono diffusi né trasferiti fuori dall\'Unione Europea.</p>
  <p class="privacy-p"><strong>Conservazione:</strong> per la durata del rapporto e, successivamente, per i termini imposti dalla legge (in particolare 10 anni per la documentazione fiscale e contabile).</p>
  <p class="privacy-p"><strong>Diritti dell\'interessato:</strong> in qualsiasi momento è possibile esercitare i diritti di accesso, rettifica, cancellazione, limitazione, opposizione e portabilità (artt. 15-22 GDPR) scrivendo a ' . $d['azienda_email'] . ', nonché proporre reclamo al Garante per la protezione dei dati personali. Il conferimento dei dati è necessario per la stipula e l\'esecuzione del contratto: il rifiuto rende impossibile dar corso al servizio.</p>
  <div class="privacy-consent"><span class="privacy-box">&nbsp;</span>Dichiaro di aver letto e compreso l\'informativa che precede e <strong>acconsento al trattamento</strong> dei miei dati personali per le finalità ivi indicate, sottoscrivendo per accettazione il presente preventivo.</div>';

    $oggettoDiv = $d['oggetto'] ? '<div class="prev-oggetto">Oggetto: ' . $d['oggetto'] . '</div>' : '';
    $noteDiv    = $d['note']    ? '<p class="proc-p" style="margin-top:12px;">' . nl2br($d['note']) . '</p>' : '';
    $scadDiv    = $d['data_scadenza'] ? '<div class="validita" style="margin-top:8px;">Offerta valida fino al: <strong>' . $d['data_scadenza'] . '</strong></div>' : '';
    $coverProj  = $d['oggetto'] ? '<div class="cover-project">' . $d['oggetto'] . '</div>' : '';

    $pagine = [];

    // ── PAGINA 1: COPERTINA ───────────────────────────────────────────────────
    // Se c'è un'immagine di copertina, occupa l'intera pagina (full-bleed).
    // Altrimenti si usa la copertina testuale nera classica.
    if ($copertina !== '') {
        $pagine[] = '<div class="cover-full" style="background-image:url(\'' . $copertina . '\');background-size:cover;background-position:center;background-repeat:no-repeat;"></div>';
    } else {
        $pagine[] = '
<div class="cover">
  <div class="cover-logo">ardy<br>lab</div>
  <div class="cover-services">Restauro,<br>ammodernamento<br>InterioDesign<br>Stampa 3D<br>Scenografie</div>
  ' . $coverProj . '
  <div class="cover-divider"></div>
  <div class="cover-footer-addr">Via James Joyce 4, 00143 Roma (RM)</div>
  <div class="cover-footer-web">www.ardy-lab.it<br>ardy.documenti@gmail.com</div>
</div>';
    }

    // ── STORIA (opzionale) ────────────────────────────────────────────────────
    if ($mostraStoria) {
        $pagine[] = '
<div class="inner">
  <div class="storia-h">Un po\' di storia...</div>
  <p class="storia-p">Nel cuore dell\'Italia, dove storia e arte si intrecciano, nasce Ardy Lab, una bottega di restauro che simboleggia una passione tramandata di generazione in generazione. Il fulcro è Andrea Panella, un maestro ebanista e restauratore con quarant\'anni di esperienza. Le sue mani hanno ridato vita a pezzi inestimabili, inclusi oggetti di grande prestigio come il <strong>Trono</strong> del Re.</p>
  <p class="storia-p">Andrea ha trasformato la sua famiglia in un team di esperti, insegnando alla moglie l\'arte della foglia d\'oro e guidando i figli nel restauro.</p>
  <p class="storia-p">Il vero salto avviene con la figlia <strong>Michela</strong>. Insieme, hanno creato Ardy Lab, un progetto che unisce passato e futuro. Andrea, con la sua profonda conoscenza delle tecniche tradizionali, e Michela, esperta di innovazione e <strong>stampa 3D</strong>, hanno dato vita a un laboratorio ibrido.</p>
  <p class="storia-p">Ardy Lab non solo restaura, ma innova. Utilizzando la stampa 3D per ricreare parti mancanti con precisione millimetrica, l\'azienda preserva l\'integrità e il valore storico dei mobili con tecnologie all\'avanguardia. Questa fusione unica di antico e moderno è la firma di Ardy Lab, il luogo dove la tradizione incontra il futuro, e dove ogni pezzo rinasce.</p>
  ' . $footer . '
</div>';
    }

    // ── PAGINA 3: DETTAGLIO TECNICO ───────────────────────────────────────────
    $pagine[] = '
<div class="inner">
  <div class="prev-title">' . $d['tipo_preventivo'] . '</div>
  ' . $oggettoDiv . '
  <div class="proc-h">Procedimento Tecnico Dettagliato</div>
  <p class="proc-p">Il lavoro verrà eseguito seguendo rigorosi standard artigianali per garantire la massima qualità e durata nel tempo.</p>
  ' . $noteDiv . '
  ' . $footer . '
</div>';

    // ── UNA PAGINA PER OPZIONE: prima/dopo in testa + costi di quell'opzione ───
    $thead = '<thead><tr>'
        . '<th style="width:45%">Descrizione</th>'
        . '<th class="tc" style="width:13%">Quantità</th>'
        . '<th class="tr" style="width:18%">Prezzo unitario</th>'
        . '<th class="tr" style="width:18%">Totale</th>'
        . '</tr></thead>';
    $cellaImg = function (string $img, string $tag): string {
        $contenuto = $img !== '' ? '<img class="ba-img" src="' . $img . '">' : '<div class="ba-empty">—</div>';
        return '<td class="ba-cell"><div class="ba-tag">' . $tag . '</div>' . $contenuto . '</td>';
    };
    foreach ($opzioni as $idx => $opz) {
        $titolo = $multiOpz
            ? 'Opzione ' . chr(65 + $idx) . ($opz['nome'] !== '' ? ' — ' . $opz['nome'] : '')
            : ($opz['nome'] !== '' ? $opz['nome'] : 'La nostra proposta');

        $primaDopo = '';
        if ($opz['prima'] !== '' || $opz['dopo'] !== '') {
            $primaDopo = '<table class="ba-row"><tr>'
                . $cellaImg($opz['prima'], 'Prima')
                . $cellaImg($opz['dopo'], 'Dopo')
                . '</tr></table>';
        }

        $pagine[] = '
<div class="inner">
  <div class="proposal-h">' . $titolo . '</div>
  ' . ($d['oggetto'] ? '<div class="prev-oggetto">' . $d['oggetto'] . '</div>' : '') . '
  ' . $primaDopo . '
  <div class="budget-sub">Dettaglio dei Costi</div>
  <table class="costi">' . $thead . '<tbody>' . $righeOpzione($opz) . '</tbody></table>
  ' . $footer . '
</div>';
    }

    // ── CONDIZIONI (+ nota scelta opzione) ────────────────────────────────────
    $notaScelta = $multiOpz
        ? '<p class="cond-p" style="font-style:normal;">Le opzioni presentate sono <strong>alternative tra loro</strong>: il prezzo indicato per ciascuna è il totale finale di quell\'opzione. Indica nella pagina di accettazione l\'opzione prescelta.</p>'
        : '';
    $pagine[] = '
<div class="inner">
  ' . $notaScelta . '
  <div class="cond-h">Condizioni di Fornitura</div>
  <div class="cond-p">' . $condHtml . '</div>
  ' . $footer . '
</div>';

    // ── PAGINA 5: FIRMA ───────────────────────────────────────────────────────
    $pagine[] = '
<div class="inner">
  <div class="firma-h">Accettazione e Sottoscrizione<br>Dati per Fatturazione</div>
  <p class="firma-intro">Il sottoscritto ________________________________, accettando il presente preventivo, fornisce i propri dati di fatturazione:</p>
  <table class="firma-t"><tbody>' . $clienteRows . '</tbody></table>
  <div class="validita">Validità preventivo: 30 giorni dalla data odierna — ' . $d['data_emissione'] . '</div>
  ' . ($multiOpz ? '<div class="validita" style="margin-top:8px;">Opzione prescelta (A / B / C ...): ______________________________</div>' : '') . '
  ' . $scadDiv . '
  ' . $privacyHtml . '
  <div class="firma-line-l">Data di firma: ____ / ____ / ________</div>
  <div class="firma-line-r">Firma del Committente _________________</div>
  <br style="clear:both">
  <div class="footer-line" style="margin-top:40px;">
    <span class="footer-left">P.IVA ' . $d['azienda_piva'] . ' — C.F. ' . $d['azienda_cf'] . '</span>
    <span class="footer-right">Regime forfettario art. 1 c. 54-89 L. 190/2014</span>
    <br style="clear:both">
  </div>
</div>';

    // ── PAGINA 6: GRAZIE ──────────────────────────────────────────────────────
    $pagine[] = '
<div class="grazie-page">
  <div class="grazie-h">Grazie!</div>
  <div class="grazie-ardy">Ardy</div>
  <div class="grazie-addr">Via James Joyce 4, 00143 Roma (RM)</div>
  <div class="grazie-web">www.ardy-lab.it<br>ardy.documenti@gmail.com</div>
</div>';

    return $pagine;
}
