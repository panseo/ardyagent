<?php
// -----------------------------------------------------------
// ARDY LAB — Allega un preventivo PDF già pronto allo Storico
//
// Caso d'uso: Michela ha un preventivo fatto altrove (Word, altro gestionale)
// in PDF e vuole semplicemente allegarlo allo Storico del cliente, SENZA
// rigenerarlo nel template Ardy e senza passare dall'estrazione AI.
//
//   POST (multipart, default/mode=crea): session_id (obbl.) + pdf (obbl.)
//                     + oggetto? numero? totale? stato_preventivo? data_emissione?
//   → salva il PDF in preventivi_pdf/ e inserisce una voce in `preventivi`
//     con file_pdf valorizzato → compare nello Storico col bottone ⬇ PDF.
//
//   POST (multipart, mode=aggiorna): id (obbl.) + session_id (obbl.)
//                     + oggetto? numero? totale? stato_preventivo? data_emissione?
//                     + pdf? (facoltativo: se assente mantiene il PDF già salvato)
//   → corregge i dati di un "Preventivo (allegato)" già presente nello Storico
//     (es. il totale letto male dall'estrazione AI). Solo righe di questo tipo.
//
// Protetto da Basic Auth (.htaccess), come ardy-preventivo.php / ardy-crm-api.php
// (no ardyRequireAuth(): su questo server l'header Authorization non arriva a PHP
//  in CGI/FPM, e una seconda guardia rifarebbe la login al fetch).
// -----------------------------------------------------------

require_once __DIR__ . '/ardy-config.php';
require_once __DIR__ . '/ardy-db.php';
require_once __DIR__ . '/ardy-net.php';

date_default_timezone_set('Europe/Rome');
header('Content-Type: application/json; charset=utf-8');

define('PDF_OUTPUT_DIR', __DIR__ . '/preventivi_pdf/');

$STATI_PREVENTIVO = ['bozza', 'inviato', 'accettato', 'rifiutato'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Metodo non consentito']);
    exit();
}

$mode = $_POST['mode'] ?? 'crea';

// --- input comune ---
$sessionId = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)($_POST['session_id'] ?? ''));
if ($sessionId === '') {
    echo json_encode(['ok' => false, 'error' => 'Cliente mancante.']);
    exit();
}

$oggetto = trim((string)($_POST['oggetto'] ?? ''));
if ($oggetto === '') $oggetto = 'Preventivo allegato';
$numero  = trim((string)($_POST['numero'] ?? ''));
$stato   = strtolower(trim((string)($_POST['stato_preventivo'] ?? 'inviato')));
if (!in_array($stato, $STATI_PREVENTIVO, true)) $stato = 'inviato';
$totale  = parseImportoAllegato((string)($_POST['totale'] ?? ''));
$dataEm  = trim((string)($_POST['data_emissione'] ?? ''));
if ($dataEm === '') $dataEm = date('d/m/Y');

// --- PDF: validato solo se presente (sempre obbligatorio in 'crea') ---
$nuovoFilePdf = null;
$pdfCaricato  = !empty($_FILES['pdf']['tmp_name']);
if ($pdfCaricato) {
    if (!is_uploaded_file($_FILES['pdf']['tmp_name'])
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
    $nuovoFilePdf = salvaPdfAllegato($_FILES['pdf']['tmp_name'], $_FILES['pdf']['name'] ?? 'preventivo.pdf');
    if ($nuovoFilePdf === null) {
        echo json_encode(['ok' => false, 'error' => 'Salvataggio del PDF non riuscito.']);
        exit();
    }
} elseif ($mode !== 'aggiorna') {
    echo json_encode(['ok' => false, 'error' => 'Allega un file PDF.']);
    exit();
}

try {
    $db = ardyDB();

    if ($mode === 'aggiorna') {
        $id = (int) ($_POST['id'] ?? 0);
        if (!$id) {
            echo json_encode(['ok' => false, 'error' => 'Preventivo da modificare non specificato.']);
            exit();
        }
        $st = $db->prepare("SELECT file_pdf, numero FROM preventivi WHERE id = ? AND session_id = ? AND tipo = 'Preventivo (allegato)' LIMIT 1");
        $st->execute([$id, $sessionId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            echo json_encode(['ok' => false, 'error' => 'Preventivo allegato non trovato.']);
            exit();
        }

        $db->prepare(
            "UPDATE preventivi SET oggetto=:oggetto, numero=:numero, stato=:stato,
                data_emissione=:dataem, subtotale=:tot, grand_total=:tot,
                file_pdf=:filepdf, updated_at=NOW()
             WHERE id=:id AND session_id=:sid"
        )->execute([
            ':oggetto' => $oggetto, ':numero' => $numero !== '' ? $numero : $row['numero'],
            ':stato' => $stato, ':dataem' => $dataEm, ':tot' => $totale ?? 0,
            ':filepdf' => $nuovoFilePdf ?? $row['file_pdf'],
            ':id' => $id, ':sid' => $sessionId,
        ]);

        // PDF sostituito: rimuovi il vecchio file (best-effort, non blocca la risposta)
        if ($nuovoFilePdf !== null && $row['file_pdf'] && $row['file_pdf'] !== $nuovoFilePdf) {
            @unlink(PDF_OUTPUT_DIR . $row['file_pdf']);
        }

        echo json_encode(['ok' => true, 'id' => $id], JSON_UNESCAPED_UNICODE);
        exit();
    }

    // --- mode 'crea' (default): comportamento originale ---
    if ($numero === '') $numero = 'ALL-' . date('Y') . '-' . substr(md5($sessionId . microtime()), 0, 6);

    // dati cliente per riempire le colonne descrittive (best-effort)
    $cliNome = ''; $cliEmail = '';
    $st = $db->prepare("SELECT nome, cognome, email FROM clienti WHERE session_id = ? LIMIT 1");
    $st->execute([$sessionId]);
    if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $cliNome  = trim(($row['nome'] ?? '') . ' ' . ($row['cognome'] ?? ''));
        $cliEmail = (string)($row['email'] ?? '');
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
        ':filepdf' => $nuovoFilePdf, ':stato' => $stato, ':dataem' => $dataEm,
    ]);

    echo json_encode(['ok' => true, 'numero' => $numero, 'file' => $nuovoFilePdf], JSON_UNESCAPED_UNICODE);

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
