<?php
// -----------------------------------------------------------
// ARDY LAB — Allega un preventivo PDF già pronto allo Storico
//
// Caso d'uso: Michela ha un preventivo fatto altrove (Word, altro gestionale)
// in PDF e vuole semplicemente allegarlo allo Storico del cliente, SENZA
// rigenerarlo nel template Ardy e senza passare dall'estrazione AI.
//
//   POST (multipart): session_id (obbl.) + pdf (obbl.) + oggetto? numero?
//                     totale? stato_preventivo? data_emissione?
//   → salva il PDF in preventivi_pdf/ e inserisce una voce in `preventivi`
//     con file_pdf valorizzato → compare nello Storico col bottone ⬇ PDF.
//
// Protetto da Basic Auth (.htaccess) + guard ardyRequireAuth().
// -----------------------------------------------------------

require_once __DIR__ . '/ardy-config.php';
require_once __DIR__ . '/ardy-auth.php';
require_once __DIR__ . '/ardy-db.php';
require_once __DIR__ . '/ardy-net.php';

ardyRequireAuth();
date_default_timezone_set('Europe/Rome');
header('Content-Type: application/json; charset=utf-8');

define('PDF_OUTPUT_DIR', __DIR__ . '/preventivi_pdf/');

$STATI_PREVENTIVO = ['bozza', 'inviato', 'accettato', 'rifiutato'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Metodo non consentito']);
    exit();
}

// --- input ---
$sessionId = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)($_POST['session_id'] ?? ''));
if ($sessionId === '') {
    echo json_encode(['ok' => false, 'error' => 'Cliente mancante.']);
    exit();
}

// --- PDF obbligatorio, validato ---
if (empty($_FILES['pdf']['tmp_name']) || !is_uploaded_file($_FILES['pdf']['tmp_name'])
    || ($_FILES['pdf']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    echo json_encode(['ok' => false, 'error' => 'Allega un file PDF.']);
    exit();
}
if (($_FILES['pdf']['size'] ?? 0) > 15 * 1024 * 1024) {
    echo json_encode(['ok' => false, 'error' => 'PDF troppo grande (max 15 MB).']);
    exit();
}
$finfo = new finfo(FILEINFO_MIME_TYPE);
if ($finfo->file($_FILES['pdf']['tmp_name']) !== 'application/pdf') {
    echo json_encode(['ok' => false, 'error' => 'Il file non è un PDF valido.']);
    exit();
}

// --- campi opzionali ---
$oggetto = trim((string)($_POST['oggetto'] ?? ''));
if ($oggetto === '') $oggetto = 'Preventivo allegato';
$numero  = trim((string)($_POST['numero'] ?? ''));
if ($numero === '') $numero = 'ALL-' . date('Y') . '-' . substr(md5($sessionId . microtime()), 0, 6);
$stato   = strtolower(trim((string)($_POST['stato_preventivo'] ?? 'inviato')));
if (!in_array($stato, $STATI_PREVENTIVO, true)) $stato = 'inviato';
$totale  = parseImportoAllegato((string)($_POST['totale'] ?? ''));
$dataEm  = trim((string)($_POST['data_emissione'] ?? ''));
if ($dataEm === '') $dataEm = date('d/m/Y');

try {
    $db = ardyDB();

    // dati cliente per riempire le colonne descrittive (best-effort)
    $cliNome = ''; $cliEmail = '';
    $st = $db->prepare("SELECT nome, cognome, email FROM clienti WHERE session_id = ? LIMIT 1");
    $st->execute([$sessionId]);
    if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $cliNome  = trim(($row['nome'] ?? '') . ' ' . ($row['cognome'] ?? ''));
        $cliEmail = (string)($row['email'] ?? '');
    }

    // salva il PDF
    $filePdf = salvaPdfAllegato($_FILES['pdf']['tmp_name'], $_FILES['pdf']['name'] ?? 'preventivo.pdf');
    if ($filePdf === null) {
        echo json_encode(['ok' => false, 'error' => 'Salvataggio del PDF non riuscito.']);
        exit();
    }

    // inserisci la voce nello Storico
    $sql = "INSERT INTO preventivi
              (session_id, numero, tipo, oggetto, cliente_nome, cliente_email,
               note, condizioni, voci_json, subtotale, grand_total,
               file_pdf, stato, data_emissione, data_scadenza, created_at, updated_at)
            VALUES
              (:sid, :numero, :tipo, :oggetto, :clinome, :cliemail,
               '', '', '[]', :sub, :tot, :filepdf, :stato, :dataem, '', NOW(), NOW())";
    $db->prepare($sql)->execute([
        ':sid' => $sessionId, ':numero' => $numero, ':tipo' => 'Preventivo (allegato)',
        ':oggetto' => $oggetto, ':clinome' => $cliNome, ':cliemail' => $cliEmail,
        ':sub' => $totale ?? 0, ':tot' => $totale ?? 0,
        ':filepdf' => $filePdf, ':stato' => $stato, ':dataem' => $dataEm,
    ]);

    echo json_encode(['ok' => true, 'numero' => $numero, 'file' => $filePdf], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log('ARDY ALLEGA PREVENTIVO ERROR: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Errore nel salvataggio.']);
}

// ─── helper ─────────────────────────────────────────────────────────────────

/** Salva un PDF in preventivi_pdf/ (MIME già validato). Ritorna il nome o null. */
function salvaPdfAllegato(string $tmp, string $nomeOriginale): ?string {
    if (!is_dir(PDF_OUTPUT_DIR) && !mkdir(PDF_OUTPUT_DIR, 0755, true) && !is_dir(PDF_OUTPUT_DIR)) return null;
    ardyHardenUploadDir(PDF_OUTPUT_DIR);
    $base = preg_replace('/[^A-Za-z0-9._-]+/', '_', basename($nomeOriginale));
    $base = trim($base, '._-');
    if ($base === '') $base = 'preventivo';
    if (!preg_match('/\.pdf$/i', $base)) $base .= '.pdf';
    $base = 'allegato_' . substr(md5($base . microtime()), 0, 6) . '_' . $base;
    $dest = PDF_OUTPUT_DIR . $base;
    if (!copy($tmp, $dest)) return null;
    @chmod($dest, 0644);
    return $base;
}

/** "1.450,00" / "1450.00" / "€ 1.450" → float, oppure null se vuoto. */
function parseImportoAllegato(string $s): ?float {
    $s = trim($s);
    if ($s === '') return null;
    $s = preg_replace('/[^\d,.\-]/', '', $s);
    if ($s === '') return null;
    if (strpos($s, ',') !== false && strpos($s, '.') !== false) {
        if (strrpos($s, ',') > strrpos($s, '.')) { $s = str_replace('.', '', $s); $s = str_replace(',', '.', $s); }
        else { $s = str_replace(',', '', $s); }
    } elseif (strpos($s, ',') !== false) {
        $s = str_replace(',', '.', $s);
    }
    return is_numeric($s) ? (float)$s : null;
}
