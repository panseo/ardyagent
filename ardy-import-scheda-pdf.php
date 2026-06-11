<?php
// -----------------------------------------------------------
// ARDY LAB — Importa scheda cliente da PDF
//
// Riceve un PDF compilato sul modello "Scheda Cliente" (etichette fisse:
// Nome:, Telefono:, …), ne estrae i campi con Claude (input documento) e crea
// la scheda nel CRM. Pensato per essere usato dalla dashboard:
//
//   POST ?mode=extract  (multipart: pdf)            → estrae i campi, NON scrive
//   POST ?mode=save      (multipart: campi + pdf?)   → crea cliente (+ preventivo) e
//                                                       allega il PDF allo "Storico"
//
// Stesso backend riusabile in futuro per "Sole crea scheda da WhatsApp".
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

$STATI_CLIENTE    = ['LEAD', 'SOPRALLUOGO', 'PREVENTIVO', 'ACCONTO', 'STANDBY', 'PERSO'];
$STATI_PREVENTIVO = ['bozza', 'inviato', 'accettato', 'rifiutato'];

$mode = $_GET['mode'] ?? '';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Metodo non consentito']);
    exit;
}

try {
    if ($mode === 'extract') {
        echo json_encode(estraiDaPdf());
        exit;
    }
    if ($mode === 'save') {
        echo json_encode(salvaScheda($STATI_CLIENTE, $STATI_PREVENTIVO));
        exit;
    }
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'mode non valido (extract|save)']);
} catch (Throwable $e) {
    error_log('ARDY IMPORT PDF ERROR: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Errore interno']);
}
exit;


// ═══════════════════════════════════════════════════════════════════════════

/** Valida il PDF caricato e ne ritorna il percorso temporaneo, o null. */
function pdfTmpValido(): ?string {
    if (empty($_FILES['pdf']['tmp_name']) || !is_uploaded_file($_FILES['pdf']['tmp_name'])) return null;
    if (($_FILES['pdf']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return null;
    if (($_FILES['pdf']['size'] ?? 0) > 15 * 1024 * 1024) return null; // max 15 MB
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    if ($finfo->file($_FILES['pdf']['tmp_name']) !== 'application/pdf') return null;
    return $_FILES['pdf']['tmp_name'];
}

/** Estrae i campi della scheda dal PDF tramite Claude (input documento). */
function estraiDaPdf(): array {
    $tmp = pdfTmpValido();
    if ($tmp === null) {
        return ['ok' => false, 'error' => 'Carica un PDF valido (max 15 MB).'];
    }
    if (!defined('ARDY_API_KEY') || ARDY_API_KEY === '') {
        return ['ok' => false, 'error' => 'Chiave API non configurata sul server.'];
    }

    $b64 = base64_encode(file_get_contents($tmp));

    $system = "Sei un estrattore di dati. Ricevi un PDF \"Scheda Cliente\" di Ardy Lab "
        . "con etichette fisse (Nome:, Cognome:, Telefono:, Email:, Indirizzo:, Zona:, "
        . "Servizio:, Mobile/Pezzo:, Oggetto:, Manodopera:, Materiali:, Trasporti:, Totale:, Stato:, Note:). "
        . "Estrai i valori e rispondi ESCLUSIVAMENTE con un oggetto JSON valido, senza "
        . "testo prima o dopo, senza code fence. Chiavi obbligatorie: nome, cognome, "
        . "telefono, email, indirizzo, zona, servizio, mobili, oggetto, manodopera, "
        . "materiali, trasporti, stato, note. "
        . "'mobili' è un ARRAY di stringhe, una per ogni pezzo elencato sotto 'Mobile/Pezzo:' "
        . "(es. [\"credenza anni 60\", \"coppia di comodini\"]); array vuoto se non indicato. "
        . "Se un campo non è presente usa stringa vuota. Gli importi (manodopera, trasporti) "
        . "come numero con la virgola decimale (es. 850,00) o stringa vuota. "
        . "'materiali' è un ARRAY di oggetti {\"descrizione\":..., \"importo\":\"60,00\"}, uno per "
        . "ogni voce di materiale elencata (es. \"3 confezioni vernice — 60,00 €\"); array vuoto se "
        . "non ce ne sono. Non sommare i materiali in un'unica voce: mantienili separati. "
        . "Per 'stato' usa uno tra LEAD, SOPRALLUOGO, PREVENTIVO, ACCONTO, STANDBY, PERSO (maiuscolo).";

    $messages = [[
        'role' => 'user',
        'content' => [
            ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => $b64]],
            ['type' => 'text', 'text' => 'Estrai i dati di questa scheda e restituisci solo il JSON.'],
        ],
    ]];

    $resp = callAnthropicDoc($messages, $system, ARDY_API_KEY);
    if (!$resp['ok']) {
        return ['ok' => false, 'error' => $resp['error']];
    }

    $dati = parseJsonDaTesto($resp['text']);
    if ($dati === null) {
        return ['ok' => false, 'error' => 'Non sono riuscito a leggere i dati dal PDF. Controlla che sia il modello scheda con testo (non una scansione).'];
    }

    // Normalizza le chiavi attese
    $out = [];
    foreach (['nome','cognome','telefono','email','indirizzo','zona','servizio','oggetto','manodopera','trasporti','stato','note'] as $k) {
        $out[$k] = isset($dati[$k]) ? trim((string)$dati[$k]) : '';
    }
    // Mobili: lista di pezzi (accetta array o stringa con separatori comuni)
    $out['mobili'] = [];
    if (!empty($dati['mobili']) && is_array($dati['mobili'])) {
        foreach ($dati['mobili'] as $m) {
            $v = trim((string)(is_array($m) ? ($m['descrizione'] ?? '') : $m));
            if ($v !== '') $out['mobili'][] = $v;
        }
    } elseif (!empty($dati['mobile'])) {
        foreach (preg_split('/[;,·\n]+/', (string)$dati['mobile']) as $v) {
            $v = trim($v);
            if ($v !== '') $out['mobili'][] = $v;
        }
    }
    // Materiali: lista di {descrizione, importo}
    $out['materiali'] = [];
    if (!empty($dati['materiali']) && is_array($dati['materiali'])) {
        foreach ($dati['materiali'] as $m) {
            if (!is_array($m)) continue;
            $desc = trim((string)($m['descrizione'] ?? $m['desc'] ?? ''));
            $imp  = trim((string)($m['importo'] ?? $m['imp'] ?? ''));
            if ($desc === '' && $imp === '') continue;
            $out['materiali'][] = ['descrizione' => $desc, 'importo' => $imp];
        }
    }
    return ['ok' => true, 'dati' => $out];
}

/** Crea/aggiorna cliente + eventuale preventivo, allega il PDF se presente. */
function salvaScheda(array $statiCli, array $statiPrev): array {
    $nome     = trim($_POST['nome'] ?? '');
    $cognome  = trim($_POST['cognome'] ?? '');
    $telefono = preg_replace('/[^0-9+]/', '', $_POST['telefono'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $oggetto  = trim($_POST['oggetto'] ?? '');

    // Voci del preventivo: manodopera + materiali (lista) + trasporti.
    $manodopera = parseImporto($_POST['manodopera'] ?? '');
    $trasporti  = parseImporto($_POST['trasporti'] ?? '');
    $materiali  = [];
    if (!empty($_POST['materiali'])) {
        $dec = json_decode($_POST['materiali'], true);
        if (is_array($dec)) {
            foreach ($dec as $m) {
                $d = trim((string)($m['descrizione'] ?? ''));
                $imp = parseImporto((string)($m['importo'] ?? ''));
                if ($d === '' && $imp === null) continue;
                $materiali[] = ['descrizione' => $d, 'importo' => $imp];
            }
        }
    }
    // Totale = somma delle voci; se non ci sono voci, usa l'eventuale 'totale' inviato.
    $sommaVoci = ($manodopera ?? 0) + ($trasporti ?? 0);
    foreach ($materiali as $m) $sommaVoci += ($m['importo'] ?? 0);
    $totale = $sommaVoci > 0 ? $sommaVoci : parseImporto($_POST['totale'] ?? '');

    $creaPrev = ($_POST['crea_preventivo'] ?? '1') === '1';

    if ($nome === '' && $cognome === '' && $telefono === '') {
        return ['ok' => false, 'error' => 'Serve almeno nome, cognome o telefono.'];
    }
    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) $email = '';

    $statoCliente = strtoupper(trim($_POST['stato'] ?? ''));
    if (!in_array($statoCliente, $statiCli, true)) $statoCliente = 'PREVENTIVO';

    // session_id deterministico per telefono/nome → niente doppioni se reimporta.
    $chiave    = $telefono !== '' ? $telefono : strtolower($nome . '|' . $cognome);
    $sessionId = 'pdf-' . substr(md5($chiave), 0, 16);
    $cliNome   = trim($nome . ' ' . $cognome);

    $db = ardyDB();
    $db->beginTransaction();
    try {
        $sqlCli = "INSERT INTO clienti
                     (session_id, nome, cognome, telefono, email, indirizzo, servizio, zona, mobile, stato, note, created_at, updated_at)
                   VALUES
                     (:sid, :nome, :cognome, :tel, :email, :indir, :serv, :zona, :mobile, :stato, :note, NOW(), NOW())
                   ON DUPLICATE KEY UPDATE
                     nome      = IF(VALUES(nome)     <> '', VALUES(nome),     nome),
                     cognome   = IF(VALUES(cognome)  <> '', VALUES(cognome),  cognome),
                     telefono  = IF(VALUES(telefono) <> '', VALUES(telefono), telefono),
                     email     = IF(VALUES(email)    <> '', VALUES(email),    email),
                     indirizzo = IF(VALUES(indirizzo)<> '', VALUES(indirizzo),indirizzo),
                     servizio  = IF(VALUES(servizio) <> '', VALUES(servizio), servizio),
                     zona      = IF(VALUES(zona)     <> '', VALUES(zona),     zona),
                     mobile    = IF(VALUES(mobile)   <> '', VALUES(mobile),   mobile),
                     stato     = VALUES(stato),
                     updated_at= NOW()";
        $db->prepare($sqlCli)->execute([
            ':sid' => $sessionId,
            ':nome' => $nome ?: null, ':cognome' => $cognome ?: null,
            ':tel' => $telefono ?: null, ':email' => $email ?: null,
            ':indir' => (trim($_POST['indirizzo'] ?? '')) ?: null,
            ':serv' => (trim($_POST['servizio'] ?? '')) ?: null,
            ':zona' => (trim($_POST['zona'] ?? '')) ?: null,
            ':mobile' => (trim($_POST['mobile'] ?? '')) ?: null,
            ':stato' => $statoCliente,
            ':note' => (trim($_POST['note'] ?? '')) ?: null,
        ]);

        // Preventivo: creato solo se richiesto (crea_preventivo) e se c'è qualcosa.
        // Quando la dashboard rigenera in stile Ardy, passa crea_preventivo=0 e crea
        // il preventivo col generatore PDF (template Ardy) lato pannello preventivi.
        $numero = '';
        if ($creaPrev && ($oggetto !== '' || $totale !== null)) {
            // Riepilogo voci nelle note del preventivo, così la scomposizione non si perde.
            $righe = [];
            if ($manodopera) $righe[] = 'Manodopera: € ' . number_format($manodopera, 2, ',', '.');
            foreach ($materiali as $m) {
                $righe[] = 'Materiale: ' . $m['descrizione']
                    . ($m['importo'] !== null ? ' — € ' . number_format($m['importo'], 2, ',', '.') : '');
            }
            if ($trasporti) $righe[] = 'Trasporti: € ' . number_format($trasporti, 2, ',', '.');
            $noteBase = trim($_POST['note'] ?? '');
            $notePrev = trim($noteBase . ($righe ? "\n" . implode("\n", $righe) : ''));
            $numero  = 'PDF-' . date('Y') . '-' . substr(md5($sessionId . $oggetto . microtime()), 0, 6);
            $filePdf = '';
            $tmp = pdfTmpValido();
            if ($tmp !== null) {
                $nomeFile = $_FILES['pdf']['name'] ?? 'scheda.pdf';
                $salvato  = salvaPdf($tmp, $nomeFile);
                if ($salvato !== null) $filePdf = $salvato;
            }
            $sqlPrev = "INSERT INTO preventivi
                          (session_id, numero, tipo, oggetto, cliente_nome, cliente_email,
                           note, condizioni, voci_json, subtotale, grand_total,
                           file_pdf, stato, data_emissione, data_scadenza, created_at, updated_at)
                        VALUES
                          (:sid, :numero, :tipo, :oggetto, :clinome, :cliemail,
                           :note, '', '[]', :sub, :tot, :filepdf, :stato, :dataem, '', NOW(), NOW())";
            $statoPrev = strtolower(trim($_POST['stato_preventivo'] ?? 'inviato'));
            if (!in_array($statoPrev, $statiPrev, true)) $statoPrev = 'inviato';
            $db->prepare($sqlPrev)->execute([
                ':sid' => $sessionId, ':numero' => $numero, ':tipo' => 'Preventivo Servizi',
                ':oggetto' => $oggetto, ':clinome' => $cliNome, ':cliemail' => $email,
                ':note' => $notePrev ?: null,
                ':sub' => $totale ?? 0, ':tot' => $totale ?? 0,
                ':filepdf' => $filePdf, ':stato' => $statoPrev, ':dataem' => date('d/m/Y'),
            ]);
        }

        $db->commit();
        return ['ok' => true, 'session_id' => $sessionId, 'cliente' => $cliNome, 'numero' => $numero];
    } catch (PDOException $e) {
        $db->rollBack();
        error_log('ARDY IMPORT PDF SAVE ERROR: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Errore nel salvataggio.'];
    }
}

// ─── helper ─────────────────────────────────────────────────────────────────

/** Chiama Anthropic con un documento PDF; ritorna ['ok'=>bool,'text'=>..,'error'=>..]. */
function callAnthropicDoc(array $messages, string $system, string $apiKey): array {
    $payload = json_encode([
        'model'      => 'claude-sonnet-4-6',
        'max_tokens' => 700,
        'system'     => $system,
        'messages'   => $messages,
    ]);
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
        ],
    ]);
    $response = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err) {
        error_log('ARDY IMPORT PDF CURL: ' . $err);
        return ['ok' => false, 'error' => 'Errore di rete con il servizio AI.'];
    }
    $data = json_decode($response, true);
    if (!is_array($data) || isset($data['error'])) {
        error_log('ARDY IMPORT PDF API: ' . substr((string)$response, 0, 500));
        return ['ok' => false, 'error' => 'Il servizio AI ha risposto con un errore.'];
    }
    $text = '';
    foreach ($data['content'] ?? [] as $block) {
        if (($block['type'] ?? '') === 'text') $text .= $block['text'];
    }
    return ['ok' => true, 'text' => $text];
}

/** Estrae il primo oggetto JSON da un testo (tollera code fence/testo extra). */
function parseJsonDaTesto(string $t): ?array {
    $t = trim($t);
    $t = preg_replace('/^```(?:json)?|```$/m', '', $t);
    $start = strpos($t, '{');
    $end   = strrpos($t, '}');
    if ($start === false || $end === false || $end <= $start) return null;
    $json = substr($t, $start, $end - $start + 1);
    $dati = json_decode($json, true);
    return is_array($dati) ? $dati : null;
}

/** "1.450,00" / "1450.00" / "€ 1.450" → float, oppure null se vuoto. */
function parseImporto(string $s): ?float {
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

/** Salva un PDF in preventivi_pdf/ (MIME validato a monte). Ritorna il nome o null. */
function salvaPdf(string $tmp, string $nomeOriginale): ?string {
    if (!is_dir(PDF_OUTPUT_DIR) && !mkdir(PDF_OUTPUT_DIR, 0755, true) && !is_dir(PDF_OUTPUT_DIR)) return null;
    ardyHardenUploadDir(PDF_OUTPUT_DIR);
    $base = preg_replace('/[^A-Za-z0-9._-]+/', '_', basename($nomeOriginale));
    $base = trim($base, '._-');
    if ($base === '') $base = 'scheda';
    if (!preg_match('/\.pdf$/i', $base)) $base .= '.pdf';
    $base = 'import_' . substr(md5($base . microtime()), 0, 6) . '_' . $base;
    $dest = PDF_OUTPUT_DIR . $base;
    if (!copy($tmp, $dest)) return null;
    @chmod($dest, 0644);
    return $base;
}
