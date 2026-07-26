<?php
// -----------------------------------------------------------
// ARDY LAB — Dash Design: generazione AI dei contenuti di progetto
// L'autore scrive poche righe; Claude le riscrive in un POST professionale di
// brand (NON comunicazione a un cliente: è brand awareness verso appassionati e
// potenziali acquirenti). Il testo torna nella dash e si rivede prima di pubblicare.
// Stesso pattern Claude del resto del repo (ARDY_API_KEY, claude-sonnet-4-6).
// Vedi PIANO-DASH-DESIGN.md (binario racconto).
//
//   POST {mode:'genera_post', progetto_id, fase_nome?, bozza} → {success, testo}
//   POST {mode:'genera_articolo'|'genera_teaser', progetto_id} → {success, testo}
//   POST {mode:'genera_social', progetto_id, fase_nome?, bozza?} → caption FB/IG
//   POST {mode:'leggi_doc', file_id} → estrae e SALVA il testo di un documento allegato
//
// I documenti di riferimento del progetto (progetto_file categoria 'doc') si leggono
// una volta sola (testo salvato in DB) e alimentano la stesura dell'articolo: restano
// materiale INTERNO, non finiscono nella proiezione pubblica (ardy-object-scheda.php).
//
// Protetto via .htaccess (Basic Auth) — elencato nel <FilesMatch>.
// -----------------------------------------------------------

require_once __DIR__ . '/ardy-config.php';
require_once __DIR__ . '/ardy-db.php';
require_once __DIR__ . '/ardy-sanitize.php';
require_once __DIR__ . '/ardy-storage.php';   // byte dei documenti (disco o B2)

header('Access-Control-Allow-Origin: https://ardyagent.ardy-lab.it');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }
// Difesa in profondità: se il Basic Auth a monte (.htaccess) non venisse
// applicato, questo guard rifiuta comunque le richieste non autenticate.
require_once __DIR__ . '/ardy-auth.php';
ardyRequireAuth();
if ($_SERVER['REQUEST_METHOD'] !== 'POST')   { http_response_code(405); echo json_encode(['success' => false, 'error' => 'Metodo non valido']); exit(); }

/**
 * Chiamata a Claude (Messages API). Ritorna il testo, o '' su errore.
 * $userMsg: stringa, oppure array di blocchi content (per allegare un documento PDF).
 */
function progettoAiClaude(string $system, $userMsg, int $maxTokens = 900): string {
    if (!defined('ARDY_API_KEY') || ARDY_API_KEY === '') return '';

    $payload = json_encode([
        'model'      => 'claude-sonnet-4-6',
        'max_tokens' => $maxTokens,
        'system'     => $system,
        'messages'   => [['role' => 'user', 'content' => $userMsg]],
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
    if ($http !== 200) { error_log('ARDY PROGETTI AI: HTTP ' . $http . ' — ' . substr((string) $res, 0, 300)); return ''; }

    $data = json_decode($res, true);
    return trim((string) ($data['content'][0]['text'] ?? ''));
}

// ── Documenti di riferimento (categoria 'doc' in progetto_file) ──────────────
// Il testo si estrae UNA VOLTA SOLA e si salva in progetto_file.testo_estratto:
// la generazione dell'articolo lo rilegge dal DB a costo zero. TXT/MD/DOCX/ODT/RTF
// si leggono in locale (nessuna chiamata al modello); solo il PDF costa una chiamata,
// una per documento, mai ripetuta se non la si chiede di nuovo.
const DOC_TESTO_MAX = 12000;   // caratteri salvati per documento
const DOC_CTX_MAX   = 8000;    // caratteri totali di documenti messi nel prompt

/** Byte di un file di progetto (disco o B2), o null. */
function progettoFileBytes(array $row): ?string {
    if (($row['storage'] ?? 'local') === 'b2' && $row['storage_key']) {
        return ardyStorageGet($row['storage_key']);
    }
    // Stessa convenzione di ardy-progetti-file-api.php (progettoFileDir).
    $path = rtrim(ARDY_UPLOAD_DIR, '/') . '/progetti/' . (int) $row['progetto_id'] . '/file/' . basename((string) $row['nome_file']);
    if (!is_file($path)) return null;
    $b = @file_get_contents($path);
    return $b === false ? null : $b;
}

/** Testo pulito: niente byte di controllo, righe vuote compattate, taglio a $max. */
function progettoTestoPulito(string $t, int $max = DOC_TESTO_MAX): string {
    if (!mb_check_encoding($t, 'UTF-8')) {
        $conv = @iconv('Windows-1252', 'UTF-8//IGNORE', $t);
        $t = $conv !== false ? $conv : mb_convert_encoding($t, 'UTF-8', 'UTF-8');
    }
    $t = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', ' ', $t) ?? $t;
    $t = preg_replace('/[ \t]+/u', ' ', $t) ?? $t;
    $t = preg_replace('/\n{3,}/u', "\n\n", $t) ?? $t;
    $t = trim($t);
    return mb_strlen($t) > $max ? mb_substr($t, 0, $max) . "\n[…documento troncato]" : $t;
}

/** Testo da un file OOXML/ODF (docx → word/document.xml, odt → content.xml). */
function progettoTestoDaZipXml(string $bytes, string $entry): string {
    $tmp = tempnam(sys_get_temp_dir(), 'ardydoc');
    if ($tmp === false || @file_put_contents($tmp, $bytes) === false) { if ($tmp) @unlink($tmp); return ''; }
    $xml = '';
    $zip = new ZipArchive();
    if ($zip->open($tmp) === true) {
        $xml = (string) $zip->getFromName($entry);
        $zip->close();
    }
    @unlink($tmp);
    if ($xml === '') return '';
    // Fine paragrafo/riga → newline, poi via tutti i tag.
    $xml = preg_replace('#</(w:p|text:p|text:h)>#', "\n", $xml) ?? $xml;
    $xml = preg_replace('#<(w:br|text:line-break)\s*/?>#', "\n", $xml) ?? $xml;
    return html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/** Testo da un RTF: via i gruppi di controllo, restano le parole. */
function progettoTestoDaRtf(string $bytes): string {
    $t = preg_replace('/\\\\\'[0-9a-fA-F]{2}/', '', $bytes) ?? $bytes;   // caratteri escapati
    // Via le intestazioni (tabella font/colori/stili, metadati, immagini): sono gruppi
    // annidati, quindi il gruppo bilanciato va descritto ricorsivamente ((?1)).
    $t = preg_replace(
        '/\{\\\\(?:fonttbl|colortbl|stylesheet|info|pict|\*)(?:[^{}]++|(\{(?:[^{}]++|(?1))*+\}))*+\}/',
        ' ', $t
    ) ?? $t;
    $t = preg_replace('/\\\\par[d]?\b/', "\n", $t) ?? $t;                // paragrafi
    $t = preg_replace('/\\\\[a-zA-Z]+-?\d* ?/', '', $t) ?? $t;           // altre parole di controllo
    return str_replace(['{', '}'], '', $t);
}

/**
 * Estrae il testo di un documento e lo salva su progetto_file.testo_estratto.
 * Ritorna la risposta JSON-ready.
 */
function progettoLeggiDoc(PDO $db, int $fileId): array {
    $st = $db->prepare(
        "SELECT f.id, f.progetto_id, f.categoria, f.storage, f.nome_file, f.storage_key, f.nome_originale
         FROM progetto_file f JOIN progetti p ON p.id = f.progetto_id
         WHERE f.id = ? AND p.deleted_at IS NULL"
    );
    $st->execute([$fileId]);
    $row = $st->fetch();
    if (!$row)                          return ['success' => false, 'error' => 'Documento non trovato'];
    if (($row['categoria'] ?? '') !== 'doc') return ['success' => false, 'error' => 'Non è un documento di riferimento'];

    $bytes = progettoFileBytes($row);
    if ($bytes === null || $bytes === '') return ['success' => false, 'error' => 'File non leggibile dallo storage'];

    $ext   = strtolower(pathinfo((string) $row['nome_originale'], PATHINFO_EXTENSION));
    $fonte = 'locale';
    switch ($ext) {
        case 'txt': case 'md': case 'markdown':
            $testo = progettoTestoPulito($bytes); break;
        case 'docx':
            $testo = progettoTestoPulito(progettoTestoDaZipXml($bytes, 'word/document.xml')); break;
        case 'odt':
            $testo = progettoTestoPulito(progettoTestoDaZipXml($bytes, 'content.xml')); break;
        case 'rtf':
            $testo = progettoTestoPulito(progettoTestoDaRtf($bytes)); break;
        case 'pdf':
            // Unica via che costa: il PDF va al modello, che ne trascrive il contenuto.
            $fonte = 'ai';
            $testo = progettoAiClaude(
                'Sei un estrattore di testo. Trascrivi fedelmente il contenuto del documento, '
              . 'senza commentarlo, riassumerlo o aggiungere nulla di tuo. Salta intestazioni, '
              . 'piè di pagina e numeri di pagina ripetuti. Rispondi solo col testo.',
                [
                    ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => base64_encode($bytes)]],
                    ['type' => 'text', 'text' => 'Trascrivi il contenuto utile di questo documento.'],
                ],
                4000
            );
            $testo = progettoTestoPulito($testo);
            break;
        default:
            return ['success' => false, 'error' => 'Formato non leggibile (.' . $ext . ')'];
    }

    if ($testo === '') {
        return ['success' => false, 'error' => $ext === 'pdf'
            ? 'Non sono riuscito a leggere il PDF: se è una scansione senza testo serve un OCR, se è molto lungo allega solo le pagine utili.'
            : 'Il documento risulta vuoto'];
    }

    $db->prepare("UPDATE progetto_file SET testo_estratto = :t, testo_estratto_at = NOW() WHERE id = :id")
       ->execute([':t' => $testo, ':id' => $fileId]);

    return ['success' => true, 'caratteri' => mb_strlen($testo), 'fonte' => $fonte];
}

try {
    $in   = json_decode(file_get_contents('php://input'), true) ?: [];
    $mode = $in['mode'] ?? 'genera_post';

    if (!in_array($mode, ['genera_post', 'genera_articolo', 'genera_teaser', 'genera_social', 'leggi_doc'], true)) { echo json_encode(['success' => false, 'error' => 'mode non valido']); exit(); }

    if ($mode === 'leggi_doc') {
        $fileId = (int) ($in['file_id'] ?? 0);
        if ($fileId <= 0) { echo json_encode(['success' => false, 'error' => 'file_id mancante']); exit(); }
        echo json_encode(progettoLeggiDoc(ardyDB(), $fileId));
        exit();
    }

    $progettoId = (int) ($in['progetto_id'] ?? 0);
    $faseNome   = trim((string) ($in['fase_nome'] ?? ''));
    $bozza      = trim((string) ($in['bozza'] ?? ''));
    // genera_post rielabora una bozza dell'autore; genera_articolo scrive l'intro
    // dell'articolo partendo dalla descrizione del progetto (nessuna bozza richiesta).
    if ($mode === 'genera_post' && $bozza === '') {
        echo json_encode(['success' => false, 'error' => 'Scrivi prima qualche riga da rielaborare']); exit();
    }

    // Contesto dal progetto (per ancorare il testo, senza inventare nulla).
    $titolo = $tipo = $descr = $storia = $materiali = $scheda = '';
    if ($progettoId > 0) {
        $db = ardyDB();
        $st = $db->prepare("SELECT titolo, tipo, descrizione, storia, materiali, scheda_tecnica FROM progetti WHERE id = ? AND deleted_at IS NULL");
        $st->execute([$progettoId]);
        if ($p = $st->fetch()) {
            $titolo    = trim((string) ($p['titolo'] ?? ''));
            $tipo      = trim((string) ($p['tipo'] ?? ''));
            $descr     = trim((string) ($p['descrizione'] ?? ''));
            $storia    = trim((string) ($p['storia'] ?? ''));
            $materiali = trim((string) ($p['materiali'] ?? ''));
            $scheda    = trim((string) ($p['scheda_tecnica'] ?? ''));
        }
    }
    // Documenti di riferimento già letti: entrano SOLO nella stesura dell'articolo
    // (il post di fase parte dalla bozza dell'autore, il teaser è emozionale e corto).
    // Testo già in DB ⇒ nessuna rilettura del file e nessuna chiamata extra al modello.
    $docs = '';
    if ($mode === 'genera_articolo' && $progettoId > 0) {
        $dst = $db->prepare(
            "SELECT nome_originale, testo_estratto FROM progetto_file
             WHERE progetto_id = ? AND categoria = 'doc' AND testo_estratto IS NOT NULL
             ORDER BY created_at ASC, id ASC"
        );
        $dst->execute([$progettoId]);
        foreach ($dst->fetchAll() as $d) {
            $t = trim((string) $d['testo_estratto']);
            if ($t === '') continue;
            $resta = DOC_CTX_MAX - mb_strlen($docs);
            if ($resta < 400) break;                       // spazio finito: meglio troncare qui
            if (mb_strlen($t) > $resta) $t = mb_substr($t, 0, $resta) . "\n[…]";
            $docs .= "--- Documento: " . trim((string) $d['nome_originale']) . " ---\n" . $t . "\n\n";
        }
    }

    if ($mode === 'genera_articolo' && $descr === '' && $materiali === '' && $scheda === '' && $docs === '') {
        echo json_encode(['success' => false, 'error' => 'Compila prima la descrizione del progetto (o allega un documento e fallo leggere)']); exit();
    }
    if ($mode === 'genera_social' && $bozza === '' && $descr === '' && $storia === '') {
        echo json_encode(['success' => false, 'error' => 'Serve almeno il testo della fase o la descrizione del progetto']); exit();
    }
    if ($mode === 'genera_teaser' && $descr === '' && $storia === '' && $materiali === '') {
        echo json_encode(['success' => false, 'error' => 'Compila prima descrizione o storia del pezzo']); exit();
    }

    $system =
        "Sei il copywriter del brand di design \"Ardy\": uno studio-bottega che progetta e autoproduce "
      . "oggetti di design — lampade, piccoli mobili, complementi d'arredo — con stampa 3D e restyling di pezzi. "
      . "Scrivi contenuti per i social e il sito con l'obiettivo di brand awareness e vendita. "
      . "IMPORTANTE: NON stai parlando con un cliente di un lavoro su commessa; è comunicazione di brand "
      . "rivolta ad appassionati di design e potenziali acquirenti. "
      . "Tono: professionale, curato, caldo ma non sdolcinato; italiano naturale e contemporaneo. "
      . "Sii fedele alla bozza dell'autore: NON inventare materiali, misure, prezzi, tempi o dettagli non presenti.";

    $ctx = '';
    if ($titolo    !== '') $ctx .= "Progetto: $titolo\n";
    if ($tipo      !== '') $ctx .= "Tipo: $tipo\n";
    if ($faseNome  !== '') $ctx .= "Fase di lavorazione: $faseNome\n";
    if ($descr     !== '') $ctx .= "Concept del progetto: $descr\n";
    if ($mode === 'genera_teaser' && $storia !== '') $ctx .= "Storia del pezzo: $storia\n";
    if ($materiali !== '') $ctx .= "Materiali dichiarati: $materiali\n";
    if ($mode === 'genera_articolo' && $scheda !== '') $ctx .= "Scheda tecnica: $scheda\n";

    if ($mode === 'genera_teaser') {
        // Teaser breve ed emozionale per la scheda prodotto Woo: i dettagli li dà Sole.
        $userMsg =
            ($ctx !== '' ? "Dati del progetto:\n$ctx\n" : '')
          . "Scrivi un testo EMOZIONALE di massimo 3 righe (2-3 frasi brevi) per la scheda prodotto del negozio online. Regole:\n"
          . "- Evoca il pezzo e il desiderio di averlo, tono caldo e curato, italiano naturale.\n"
          . "- NIENTE dettagli tecnici, materiali, misure, tempi o prezzi: quelli li racconta l'assistente Sole in chat.\n"
          . "- Niente elenchi, niente hashtag, niente titolo, niente virgolette. Al massimo una emoji se calza.\n"
          . "- Resta fedele al progetto: non inventare fatti.\n"
          . "Rispondi SOLO con le 2-3 frasi.";
    } elseif ($mode === 'genera_social') {
        // Caption per Facebook/Instagram: parla al pubblico, non al cliente. Corta,
        // concreta, con qualche hashtag — è l'unico posto del repo dove gli hashtag
        // ci stanno (sul sito e nella scheda prodotto sono banditi).
        $userMsg =
            ($ctx !== '' ? "Dati del progetto:\n$ctx\n" : '')
          . ($bozza !== '' ? "Testo di partenza (la fase o l'articolo già scritto):\n\"\"\"\n$bozza\n\"\"\"\n\n" : '')
          . "Scrivi la caption per un post su Facebook e Instagram. Regole:\n"
          . "- 3-6 righe, tono di brand: artigiano che progetta e autoproduce, mai markettaro.\n"
          . "- Racconta questo passaggio del lavoro, non il catalogo: chi legge segue una storia che avanza.\n"
          . "- Resta fedele ai dati: non inventare materiali, misure, prezzi o tempi.\n"
          . "- Chiudi con 4-8 hashtag pertinenti in italiano/inglese, sulla stessa riga in fondo.\n"
          . "- Niente titolo, niente virgolette, al massimo un paio di emoji se calzano.\n"
          . "Rispondi SOLO con la caption.";
    } elseif ($mode === 'genera_articolo') {
        // Intro dell'articolo "madre" del progetto: presenta il pezzo finito.
        $userMsg =
            ($ctx !== '' ? "Dati del progetto:\n$ctx\n" : '')
          . ($docs !== '' ? "Documentazione allegata dall'autore (materiale di riferimento, NON da copiare alla lettera):\n$docs\n" : '')
          . "Scrivi l'introduzione dell'articolo che presenta questa creazione sul sito. Regole:\n"
          . "- Presenta il pezzo finito: cos'è, l'idea dietro, materiali e finiture (solo quelli indicati).\n"
          . ($docs !== '' ? "- Usa la documentazione allegata per i fatti e i dettagli: è la fonte più affidabile, ma resta un testo tuo, non un riassunto del documento.\n" : '')
          . "- 2-4 paragrafi brevi, niente elenchi puntati.\n"
          . "- Resta fedele ai dati: non inventare materiali, misure, prezzi o tempi non presenti.\n"
          . "- Questo è l'inizio di un articolo che poi racconterà le fasi di lavoro: chiusura che invita a seguire il racconto.\n"
          . "- Niente hashtag, niente emoji invadenti (al massimo uno, se calza).\n"
          . "Rispondi SOLO con il testo dell'articolo, senza titolo, introduzioni o virgolette.";
    } else {
        $userMsg =
            ($ctx !== '' ? "Contesto:\n$ctx\n" : '')
          . "Bozza dell'autore (poche righe):\n\"\"\"\n$bozza\n\"\"\"\n\n"
          . "Riscrivi la bozza in un POST pronto per la pubblicazione. Regole:\n"
          . "- Resta fedele alla bozza; non aggiungere fatti, materiali o numeri non presenti.\n"
          . "- 1-3 paragrafi brevi, niente elenchi puntati.\n"
          . "- Nessun prezzo inventato; nessuna promessa esagerata.\n"
          . "- Chiusura naturale (es. invito a seguire/scoprire), senza risultare markettara.\n"
          . "- Niente hashtag, niente emoji invadenti (al massimo uno, se calza).\n"
          . "Rispondi SOLO con il testo del post, senza introduzioni né virgolette.";
    }

    $testo = progettoAiClaude($system, $userMsg);
    $testo = ardy_strip_tool_syntax($testo); // rete di sicurezza sull'output del modello
    if ($testo === '') { echo json_encode(['success' => false, 'error' => 'Generazione non riuscita (riprova)']); exit(); }

    echo json_encode(['success' => true, 'testo' => $testo]);

} catch (PDOException $e) {
    error_log('ARDY PROGETTI AI ERROR: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Errore interno']);
}
