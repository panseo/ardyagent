<?php
// -----------------------------------------------------------
// ARDY LAB — Import temporaneo preventivi storici (CSV)
//
// Strumento UNA TANTUM per migrare i preventivi già fatti in passato
// (senza CRM) dentro il database, così da ritrovarli nella dashboard e
// cominciare a lavorarci. Per ogni riga del CSV:
//   1. crea/aggiorna un cliente in `clienti` (con session_id deterministico)
//   2. inserisce il preventivo in `preventivi` collegato a quel session_id
//
// Protetto da Basic Auth (.htaccess) + guard ardyRequireAuth().
// È idempotente: rilanciarlo con lo stesso CSV non duplica i record
// (clienti raggruppati per telefono/nome, preventivi per `numero`).
//
// Per disattivarlo a migrazione finita, basta definire in ardy-config.php:
//   define('ARDY_IMPORT_DISABILITATO', true);
// oppure rimuovere il file dal server.
//
//   GET  ?mode=template → scarica il CSV-modello da compilare
//   GET  (default)      → pagina con istruzioni + form di upload
//   POST (file CSV)     → anteprima (dry-run) o import effettivo
// -----------------------------------------------------------

require_once __DIR__ . '/ardy-auth.php';
require_once __DIR__ . '/ardy-db.php';

ardyRequireAuth();

if (defined('ARDY_IMPORT_DISABILITATO') && ARDY_IMPORT_DISABILITATO === true) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Import disattivato (ARDY_IMPORT_DISABILITATO).";
    exit;
}

date_default_timezone_set('Europe/Rome');

// Cartella dei PDF dei preventivi (stessa usata da ardy-preventivo.php per il
// download dallo "Storico"). I PDF caricati qui vengono salvati con questo nome.
define('PDF_OUTPUT_DIR', __DIR__ . '/preventivi_pdf/');

// Colonne attese nel CSV (intestazione). L'ordine è libero: si va per nome.
// Solo `oggetto` e `totale` sono di fatto necessari per un preventivo utile;
// il resto è opzionale.
const CSV_COLONNE = [
    'nome', 'cognome', 'telefono', 'email',      // cliente
    'indirizzo', 'servizio', 'zona', 'mobile', 'budget', // cliente (opzionali)
    'stato_cliente',                             // default PREVENTIVO
    'numero', 'oggetto', 'totale',               // preventivo
    'stato_preventivo', 'data', 'scadenza', 'file_pdf', 'note', // preventivo (opzionali)
];

$STATI_CLIENTE    = ['LEAD', 'SOPRALLUOGO', 'PREVENTIVO', 'ACCONTO', 'STANDBY', 'PERSO'];
$STATI_PREVENTIVO = ['bozza', 'inviato', 'accettato', 'rifiutato'];

$mode = $_GET['mode'] ?? '';

// ─── CSV-modello da scaricare ───────────────────────────────────────────────
if ($mode === 'template') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="ardy-import-preventivi-modello.csv"');
    // BOM UTF-8: fa aprire correttamente gli accenti a Excel
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, CSV_COLONNE, ';');
    // Riga d'esempio (da cancellare prima dell'import)
    fputcsv($out, [
        'Alessandra', 'Masu', '', '',
        '', 'Restauro', 'Roma', 'Coppia di comodini d\'epoca con piani in marmo', '',
        'PREVENTIVO',
        'ARD-2026-0001', 'Restauro conservativo coppia comodini con piani in marmo', '360,00',
        'inviato', '18/05/2026', '', 'Preventivo_Restauro_Alessandra_Masu.pdf', 'Preventivo storico migrato',
    ], ';');
    fclose($out);
    exit;
}

// ─── Import (POST con file CSV) ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $dryRun = !isset($_POST['conferma']) || $_POST['conferma'] !== '1';

    if (empty($_FILES['csv']['tmp_name']) || !is_uploaded_file($_FILES['csv']['tmp_name'])) {
        renderPagina('<p class="err">Nessun file CSV caricato.</p>');
        exit;
    }

    $righe = leggiCsv($_FILES['csv']['tmp_name']);
    if ($righe === null) {
        renderPagina('<p class="err">CSV non leggibile o intestazione mancante. Scarica il modello e riprova.</p>');
        exit;
    }

    // PDF caricati insieme al CSV: mappa basename-normalizzato → tmp_name.
    // Il collegamento con la riga avviene tramite la colonna `file_pdf`.
    $pdfMap = raccogliPdf();

    $report   = [];
    $okCount  = 0;
    $skipCount = 0;
    $errCount = 0;

    $db = ardyDB();
    if (!$dryRun) {
        ardyEnsureTelefonoLast9($db);
        $db->beginTransaction();
    }

    foreach ($righe as $i => $r) {
        $numRiga = $i + 2; // +1 header, +1 base-1 per l'utente
        $res = importaRiga($db, $r, $dryRun, $STATI_CLIENTE, $STATI_PREVENTIVO, $pdfMap);
        $report[] = ['riga' => $numRiga, 'esito' => $res['esito'], 'msg' => $res['msg']];
        if ($res['esito'] === 'ok')   $okCount++;
        if ($res['esito'] === 'skip') $skipCount++;
        if ($res['esito'] === 'err')  $errCount++;
    }

    if (!$dryRun) {
        if ($errCount > 0) {
            $db->rollBack();
        } else {
            $db->commit();
        }
    }

    renderReport($report, $okCount, $skipCount, $errCount, $dryRun);
    exit;
}

// ─── Pagina di default (form) ───────────────────────────────────────────────
renderPagina('');
exit;


// ═══════════════════════════════════════════════════════════════════════════
// FUNZIONI
// ═══════════════════════════════════════════════════════════════════════════

/** Legge il CSV (delimitatore ; o ,) e ritorna array di righe associative,
 *  con chiavi normalizzate sui nomi colonna. null se illeggibile. */
function leggiCsv(string $path): ?array {
    $raw = file_get_contents($path);
    if ($raw === false || $raw === '') return null;
    // Togli eventuale BOM UTF-8
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);

    // Rileva delimitatore dalla prima riga
    $primaRiga = strtok($raw, "\r\n");
    $delim = (substr_count($primaRiga, ';') >= substr_count($primaRiga, ',')) ? ';' : ',';

    $fh = fopen('php://temp', 'r+');
    fwrite($fh, $raw);
    rewind($fh);

    $header = fgetcsv($fh, 0, $delim);
    if (!$header) { fclose($fh); return null; }
    $header = array_map(fn($h) => strtolower(trim((string)$h)), $header);

    $righe = [];
    while (($row = fgetcsv($fh, 0, $delim)) !== false) {
        // Salta righe completamente vuote
        if (count(array_filter($row, fn($c) => trim((string)$c) !== '')) === 0) continue;
        $assoc = [];
        foreach ($header as $idx => $col) {
            $assoc[$col] = isset($row[$idx]) ? trim((string)$row[$idx]) : '';
        }
        $righe[] = $assoc;
    }
    fclose($fh);
    return $righe;
}

/** Importa una singola riga. In dry-run non scrive nulla. */
function importaRiga(PDO $db, array $r, bool $dryRun, array $statiCli, array $statiPrev, array $pdfMap = []): array {
    $nome     = $r['nome']     ?? '';
    $cognome  = $r['cognome']  ?? '';
    $telefono = preg_replace('/[^0-9+]/', '', $r['telefono'] ?? '');
    $email    = $r['email']    ?? '';
    $oggetto  = $r['oggetto']  ?? '';
    $totale   = parseImporto($r['totale'] ?? '');

    // Validazione minima: serve almeno un identificativo cliente e un oggetto
    if ($nome === '' && $cognome === '' && $telefono === '') {
        return ['esito' => 'err', 'msg' => 'Manca il cliente (nome/cognome/telefono).'];
    }
    if ($oggetto === '' && $totale === null) {
        return ['esito' => 'err', 'msg' => 'Manca sia oggetto sia totale del preventivo.'];
    }
    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $email = ''; // email sporca: la scartiamo, non blocchiamo
    }

    // session_id deterministico → preventivi dello stesso cliente raggruppati,
    // e re-import idempotente.
    $chiaveCliente = $telefono !== '' ? $telefono : strtolower($nome . '|' . $cognome);
    $sessionId = 'imp-' . substr(md5($chiaveCliente), 0, 16);

    $statoCliente = strtoupper(trim($r['stato_cliente'] ?? ''));
    if (!in_array($statoCliente, $statiCli, true)) $statoCliente = 'PREVENTIVO';

    $statoPrev = strtolower(trim($r['stato_preventivo'] ?? ''));
    if (!in_array($statoPrev, $statiPrev, true)) $statoPrev = 'inviato';

    $numero = trim($r['numero'] ?? '');
    if ($numero === '') {
        $numero = 'IMP-' . date('Y') . '-' . substr(md5($sessionId . $oggetto . $totale), 0, 6);
    }

    $cliNomeCompleto = trim($nome . ' ' . $cognome);

    // PDF allegato: la riga indica il nome del file in `file_pdf`; lo cerchiamo
    // tra i PDF caricati col form (chiave normalizzata = basename minuscolo).
    $filePdfReq = basename(trim($r['file_pdf'] ?? ''));
    $pdfKey     = strtolower($filePdfReq);
    $pdfMsg     = '';
    if ($filePdfReq !== '') {
        $pdfMsg = isset($pdfMap[$pdfKey])
            ? " · PDF \"{$filePdfReq}\" ✓"
            : " · ⚠ PDF \"{$filePdfReq}\" non caricato (la scheda resterà senza file)";
    }

    if ($dryRun) {
        $tot = $totale !== null ? '€ ' . number_format($totale, 2, ',', '.') : '—';
        return ['esito' => 'ok', 'msg' => "Cliente \"{$cliNomeCompleto}\" → preventivo {$numero} ({$tot}, {$statoPrev}){$pdfMsg}."];
    }

    try {
        // 1) Upsert cliente (UNIQUE KEY su session_id)
        $sqlCli = "INSERT INTO clienti
                     (session_id, nome, cognome, telefono, telefono_last9, email, indirizzo, servizio, zona, mobile, budget, stato, note, created_at, updated_at)
                   VALUES
                     (:sid, :nome, :cognome, :tel, :tel9, :email, :indir, :serv, :zona, :mobile, :budget, :stato, :note, NOW(), NOW())
                   ON DUPLICATE KEY UPDATE
                     nome      = IF(VALUES(nome)     <> '', VALUES(nome),     nome),
                     cognome   = IF(VALUES(cognome)  <> '', VALUES(cognome),  cognome),
                     telefono  = IF(VALUES(telefono) <> '', VALUES(telefono), telefono),
                     telefono_last9 = IF(VALUES(telefono) <> '', VALUES(telefono_last9), telefono_last9),
                     email     = IF(VALUES(email)    <> '', VALUES(email),    email),
                     indirizzo = IF(VALUES(indirizzo)<> '', VALUES(indirizzo),indirizzo),
                     servizio  = IF(VALUES(servizio) <> '', VALUES(servizio), servizio),
                     zona      = IF(VALUES(zona)     <> '', VALUES(zona),     zona),
                     mobile    = IF(VALUES(mobile)   <> '', VALUES(mobile),   mobile),
                     budget    = IF(VALUES(budget)   <> '', VALUES(budget),   budget),
                     stato     = VALUES(stato),
                     updated_at = NOW()";
        $db->prepare($sqlCli)->execute([
            ':sid'    => $sessionId,
            ':nome'   => $nome ?: null,
            ':cognome'=> $cognome ?: null,
            ':tel'    => $telefono ?: null,
            ':tel9'   => $telefono ? ardyTelefonoLast9($telefono) : null,
            ':email'  => $email ?: null,
            ':indir'  => ($r['indirizzo'] ?? '') ?: null,
            ':serv'   => ($r['servizio'] ?? '') ?: null,
            ':zona'   => ($r['zona'] ?? '') ?: null,
            ':mobile' => ($r['mobile'] ?? '') ?: null,
            ':budget' => ($r['budget'] ?? '') ?: null,
            ':stato'  => $statoCliente,
            ':note'   => ($r['note'] ?? '') ?: null,
        ]);

        // 2) Preventivo idempotente per `numero`: salta se già presente
        $chk = $db->prepare("SELECT id FROM preventivi WHERE numero = :n LIMIT 1");
        $chk->execute([':n' => $numero]);
        if ($chk->fetchColumn()) {
            return ['esito' => 'skip', 'msg' => "Preventivo {$numero} già presente: saltato."];
        }

        $subtotale = $totale ?? 0;
        // Se il PDF è stato caricato col form, lo salviamo in preventivi_pdf/
        // (nome normalizzato) così dallo "Storico" si riscarica. Se la riga cita
        // un file ma non è stato caricato, lasciamo file_pdf vuoto (niente link).
        $filePdf = '';
        if ($filePdfReq !== '' && isset($pdfMap[$pdfKey])) {
            $salvato = salvaPdf($pdfMap[$pdfKey], $filePdfReq);
            if ($salvato === null) {
                return ['esito' => 'err', 'msg' => "PDF \"{$filePdfReq}\" non valido o non salvabile."];
            }
            $filePdf = $salvato;
        }
        $sqlPrev = "INSERT INTO preventivi
                      (session_id, numero, tipo, oggetto, cliente_nome, cliente_email,
                       note, condizioni, voci_json, subtotale, grand_total,
                       file_pdf, stato, data_emissione, data_scadenza, created_at, updated_at)
                    VALUES
                      (:sid, :numero, :tipo, :oggetto, :clinome, :cliemail,
                       :note, '', '[]', :sub, :tot,
                       :filepdf, :stato, :dataem, :datasc, NOW(), NOW())";
        $db->prepare($sqlPrev)->execute([
            ':sid'     => $sessionId,
            ':numero'  => $numero,
            ':tipo'    => 'Preventivo Servizi',
            ':oggetto' => $oggetto,
            ':clinome' => $cliNomeCompleto,
            ':cliemail'=> $email,
            ':note'    => ($r['note'] ?? '') ?: null,
            ':sub'     => $subtotale,
            ':tot'     => $totale ?? 0,
            ':filepdf' => $filePdf,
            ':stato'   => $statoPrev,
            ':dataem'  => ($r['data'] ?? '') ?: date('d/m/Y'),
            ':datasc'  => ($r['scadenza'] ?? '') ?: '',
        ]);

        $msgPdf = $filePdf !== '' ? ' (PDF allegato)' : '';
        return ['esito' => 'ok', 'msg' => "Importato: cliente \"{$cliNomeCompleto}\" + preventivo {$numero}{$msgPdf}."];

    } catch (PDOException $e) {
        error_log('ARDY IMPORT ERROR riga: ' . $e->getMessage());
        return ['esito' => 'err', 'msg' => 'Errore DB su questa riga (vedi log server).'];
    }
}

/** Raccoglie i PDF caricati col campo file multiplo `pdf[]` in una mappa
 *  basename-minuscolo → percorso temporaneo. Scarta gli upload non riusciti. */
function raccogliPdf(): array {
    $map = [];
    if (empty($_FILES['pdf']) || !is_array($_FILES['pdf']['tmp_name'] ?? null)) {
        return $map;
    }
    foreach ($_FILES['pdf']['tmp_name'] as $i => $tmp) {
        if (($_FILES['pdf']['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
        if (!is_uploaded_file($tmp)) continue;
        $nome = strtolower(basename($_FILES['pdf']['name'][$i] ?? ''));
        if ($nome === '') continue;
        $map[$nome] = $tmp;
    }
    return $map;
}

/** Valida che il file temporaneo sia un PDF e lo salva in preventivi_pdf/ con
 *  un nome normalizzato. Ritorna il nome salvato, o null in caso di errore. */
function salvaPdf(string $tmp, string $nomeOriginale): ?string {
    // Verifica che sia davvero un PDF (MIME reale), non solo l'estensione
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($tmp);
    if ($mime !== 'application/pdf') return null;

    if (!is_dir(PDF_OUTPUT_DIR) && !mkdir(PDF_OUTPUT_DIR, 0755, true) && !is_dir(PDF_OUTPUT_DIR)) {
        return null;
    }

    // Nome sicuro: solo caratteri innocui, sempre con estensione .pdf
    $base = preg_replace('/[^A-Za-z0-9._-]+/', '_', basename($nomeOriginale));
    $base = trim($base, '._-');
    if ($base === '') $base = 'preventivo';
    if (!preg_match('/\.pdf$/i', $base)) $base .= '.pdf';
    $base = 'import_' . $base; // prefisso per non collidere coi PDF generati

    $dest = PDF_OUTPUT_DIR . $base;
    if (!copy($tmp, $dest)) return null;
    @chmod($dest, 0644);
    return $base;
}

/** "1.450,00" / "1450.00" / "€ 1.450" → float, oppure null se vuoto. */
function parseImporto(string $s): ?float {
    $s = trim($s);
    if ($s === '') return null;
    $s = preg_replace('/[^\d,.\-]/', '', $s); // via simboli/spazi
    if ($s === '') return null;
    // Se ci sono sia '.' sia ',', l'ultimo è il separatore decimale
    if (strpos($s, ',') !== false && strpos($s, '.') !== false) {
        if (strrpos($s, ',') > strrpos($s, '.')) {
            $s = str_replace('.', '', $s);   // formato IT: . migliaia, , decimali
            $s = str_replace(',', '.', $s);
        } else {
            $s = str_replace(',', '', $s);   // formato EN: , migliaia
        }
    } elseif (strpos($s, ',') !== false) {
        $s = str_replace(',', '.', $s);      // solo virgola → decimale
    }
    return is_numeric($s) ? (float)$s : null;
}

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function renderReport(array $report, int $ok, int $skip, int $err, bool $dryRun): void {
    $titolo = $dryRun ? 'Anteprima (nessun dato scritto)' : 'Import completato';
    $rows = '';
    foreach ($report as $r) {
        $cls = $r['esito'] === 'ok' ? 'ok' : ($r['esito'] === 'skip' ? 'skip' : 'err');
        $rows .= '<tr class="' . $cls . '"><td>' . $r['riga'] . '</td><td>' . strtoupper($r['esito']) . '</td><td>' . h($r['msg']) . '</td></tr>';
    }
    $banner = '';
    if (!$dryRun && $err > 0) {
        $banner = '<p class="err"><strong>Import annullato:</strong> ci sono ' . $err . ' righe con errore. Niente è stato scritto (rollback). Correggi il CSV e riprova.</p>';
    } elseif (!$dryRun) {
        $banner = '<p class="ok2"><strong>Fatto.</strong> Importati ' . $ok . ' preventivi (' . $skip . ' già presenti, saltati). Apri la dashboard per lavorarci.</p>';
    } else {
        $banner = '<p>Pronte ' . $ok . ' righe da importare, ' . $skip . ' da saltare, ' . $err . ' con errori. '
                . ($err === 0 ? 'Se è tutto giusto, conferma sotto.' : 'Correggi gli errori prima di confermare.') . '</p>';
    }

    $confermaForm = '';
    if ($dryRun && $err === 0 && $ok > 0) {
        $confermaForm = '<form method="post" enctype="multipart/form-data" class="conferma">'
            . '<p><strong>Per scrivere davvero sul database</strong>, riseleziona gli stessi file e spunta la conferma '
            . '(i file caricati non si conservano tra una pagina e l\'altra):</p>'
            . '<p>CSV:<br><input type="file" name="csv" accept=".csv" required></p>'
            . '<p>PDF (se vuoi allegarli):<br><input type="file" name="pdf[]" accept="application/pdf" multiple></p>'
            . '<p><label><input type="checkbox" name="conferma" value="1" required> Confermo l\'import definitivo</label></p>'
            . '<button type="submit">Importa per davvero</button></form>';
    }

    $contenuto = $banner
        . '<table class="rep"><thead><tr><th>Riga</th><th>Esito</th><th>Dettaglio</th></tr></thead><tbody>' . $rows . '</tbody></table>'
        . $confermaForm
        . '<p><a href="ardy-import-preventivi.php">&larr; Torna</a></p>';
    renderPagina($contenuto, $titolo);
}

function renderPagina(string $contenuto, string $titolo = 'Import preventivi storici'): void {
    header('Content-Type: text/html; charset=utf-8');
    $form = '';
    if ($contenuto === '') {
        $colonne = implode(', ', CSV_COLONNE);
        $form = '
        <ol class="guida">
          <li><a href="?mode=template"><strong>Scarica il CSV-modello</strong></a> e aprilo con Excel o Fogli Google.</li>
          <li>Compila una riga per ogni vecchio preventivo (cancella la riga d\'esempio).
              Servono almeno il <em>cliente</em> e l\'<em>oggetto</em> o il <em>totale</em>; il resto è facoltativo.</li>
          <li>(Facoltativo) Per allegare i PDF alle schede: nella colonna <code>file_pdf</code> scrivi
              il nome del file PDF, poi qui sotto seleziona <strong>tutti i PDF</strong> insieme al CSV.</li>
          <li>Carica: prima vedi un\'<strong>anteprima</strong> (non scrive nulla), poi spunta la conferma
              e reinvia per importare davvero.</li>
        </ol>
        <p class="cols"><strong>Colonne:</strong> ' . h($colonne) . '</p>
        <form method="post" enctype="multipart/form-data" class="upload">
          <p><strong>1. File CSV</strong><br><input type="file" name="csv" accept=".csv" required></p>
          <p><strong>2. PDF dei preventivi</strong> (facoltativo, selezione multipla)<br>
             <input type="file" name="pdf[]" accept="application/pdf" multiple></p>
          <p><label><input type="checkbox" name="conferma" value="1"> Conferma import definitivo
             (lascia vuoto per la sola anteprima)</label></p>
          <button type="submit">Carica</button>
        </form>
        <p class="nota">Strumento temporaneo per la migrazione. È idempotente: ricaricarlo non crea doppioni
        (clienti raggruppati per telefono, preventivi per numero). I PDF vengono salvati in
        <code>preventivi_pdf/</code> e collegati allo "Storico" della scheda.</p>';
    } else {
        $form = $contenuto;
    }
    echo '<!DOCTYPE html><html lang="it"><head><meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>' . h($titolo) . ' — Ardy Lab</title>
    <style>
      body{font-family:Arial,Helvetica,sans-serif;max-width:820px;margin:30px auto;padding:0 16px;color:#1a1a1a;line-height:1.5}
      h1{font-size:22px} a{color:#0a6} .guida li{margin:8px 0}
      .cols{background:#f5f5f5;padding:10px;border-radius:6px;font-size:13px}
      .upload,.conferma{margin:18px 0;padding:16px;border:1px solid #ddd;border-radius:8px;background:#fafafa}
      button{background:#111;color:#fff;border:0;padding:8px 16px;border-radius:6px;cursor:pointer}
      table.rep{border-collapse:collapse;width:100%;font-size:13px;margin:14px 0}
      table.rep th,table.rep td{border:1px solid #ddd;padding:6px 8px;text-align:left;vertical-align:top}
      tr.ok td{background:#eefaf0} tr.skip td{background:#fff8e6} tr.err td{background:#fdecec}
      .err{color:#a00} .ok2{color:#080} .nota{font-size:12px;color:#777}
      label{font-size:14px} code{background:#eee;padding:1px 5px;border-radius:3px;font-size:13px}
      .upload p,.conferma p{margin:10px 0}
    </style></head><body>
    <h1>📥 ' . h($titolo) . '</h1>' . $form . '</body></html>';
}
