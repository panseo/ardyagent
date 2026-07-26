<?php
// -----------------------------------------------------------
// ARDY LAB — Archiviazione di fine ciclo su Backblaze B2
//
// L'idea, in una riga: B2 non sta più NEL lavoro, sta DOPO. Finché un progetto o un
// cliente sono in corso, tutto vive su disco e si serve da lì. Quando il ciclo si
// chiude — progetto a CATALOGATO, cliente CONSEGNATO/PAGATO — e tutto è già al suo
// posto (articolo pubblicato, prodotto in vetrina, documenti letti), un gesto
// esplicito deposita l'intera documentazione su B2 come archivio a freddo.
//
// Perché è diverso dall'offload automatico che c'era prima: lì B2 era nel percorso di
// SERVIZIO (l'articolo doveva rileggere le foto dal bucket per pubblicarle, e se la
// lettura non rispondeva usciva un articolo spoglio). Qui no: quando si archivia, ciò
// che doveva essere pubblico è già su WordPress e su Woo. L'archivio è una copia di
// sicurezza, e nessuno ci lavora sopra.
//
// NON cancella nulla dal disco: archiviare è copiare. Liberare spazio è un'altra
// decisione, da prendere solo su un archivio verificato.
//
//   POST {mode:'stato',     tipo:'progetto'|'cliente', id}          → checklist + stato archivio
//   POST {mode:'archivia',  tipo, id, offset?}                      → carica un lotto di file
//
// L'archiviazione è a LOTTI: la dash richiama l'endpoint finché 'finito' non è true,
// così un progetto con cento foto non muore in un timeout PHP.
//
// Protetto via .htaccess (Basic Auth) — elencato nel <FilesMatch>.
// -----------------------------------------------------------

require_once __DIR__ . '/ardy-db.php';
require_once __DIR__ . '/ardy-storage.php';
require_once __DIR__ . '/ardy-dossier.php';   // ardy_genera_dossier() per i clienti

header('Access-Control-Allow-Origin: https://ardyagent.ardy-lab.it');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }
require_once __DIR__ . '/ardy-auth.php';
ardyRequireAuth();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'error' => 'Metodo non valido']); exit(); }

const ARCH_LOTTO      = 12;                              // file per chiamata
const ARCH_STATI_PROG = ['CATALOGATO'];                  // progetto: ciclo chiuso
const ARCH_STATI_CLI  = ['CONSEGNATO', 'PAGATO'];        // cliente: lavoro consegnato

/** Prefisso leggibile dell'archivio sul bucket (con la data: uno storico, non un sovrascrivi). */
function archPrefisso(string $tipo, string $chiave): string {
    $chiave = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $chiave), '-.');
    // Il ripiego va DOPO la ripulitura: un titolo fatto di soli simboli si riduce a
    // stringa vuota, e un prefisso vuoto scriverebbe tutto nella cartella del tipo.
    if ($chiave === '') $chiave = 'senza-nome';
    return 'archivio/' . $tipo . '/' . $chiave;
}

/** Content-type indicativo dall'estensione: l'archivio resta leggibile anche a mano. */
function archMime(string $file): string {
    static $map = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp',
        'gif' => 'image/gif', 'pdf' => 'application/pdf', 'md' => 'text/markdown; charset=utf-8',
        'txt' => 'text/plain; charset=utf-8', 'json' => 'application/json', 'zip' => 'application/zip',
        'stl' => 'model/stl', '3mf' => 'model/3mf', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];
    return $map[strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? 'application/octet-stream';
}

/**
 * Elenco dei file su disco da archiviare per un PROGETTO, in ordine stabile (l'ordine
 * non deve cambiare tra un lotto e l'altro, o l'offset punterebbe altrove).
 * Ritorna [['path'=>.., 'rel'=>.., 'nota'=>..], ...] + le voci NON archiviabili.
 */
function archFileProgetto(PDO $db, int $id): array {
    $base   = rtrim(ARDY_UPLOAD_DIR, '/') . '/progetti/' . $id;
    $file   = [];
    $saltat = [];

    $agg = function (string $path, string $rel) use (&$file, &$saltat) {
        if (is_file($path) && filesize($path) > 0) $file[] = ['path' => $path, 'rel' => $rel];
        else $saltat[] = ['rel' => $rel, 'motivo' => 'non presente su disco'];
    };

    // Galleria, foto vendita, archivio file/documenti: i binari con lo strato storage.
    $tab = [
        ['progetto_galleria',     'galleria', "SELECT id, tipo AS etichetta, nome_file, storage, storage_key FROM progetto_galleria WHERE progetto_id = ? ORDER BY id ASC"],
        ['progetto_foto_vendita', 'vendita',  "SELECT id, '' AS etichetta, nome_file, storage, storage_key FROM progetto_foto_vendita WHERE progetto_id = ? ORDER BY id ASC"],
        ['progetto_file',         'file',     "SELECT id, categoria AS etichetta, nome_file, storage, storage_key FROM progetto_file WHERE progetto_id = ? ORDER BY id ASC"],
    ];
    foreach ($tab as [$_, $cartella, $sql]) {
        $st = $db->prepare($sql);
        $st->execute([$id]);
        foreach ($st->fetchAll() as $r) {
            // Già su B2 dal vecchio offload: sta nel bucket, solo sotto un'altra chiave.
            // Non lo ricopiamo (servirebbe rileggerlo) ma lo scriviamo nel manifest.
            if (($r['storage'] ?? 'local') === 'b2' && $r['storage_key']) {
                $saltat[] = ['rel' => $cartella . '/' . $r['nome_file'], 'motivo' => 'già su B2 (offload): ' . $r['storage_key']];
                continue;
            }
            $agg($base . '/' . $cartella . '/' . basename($r['nome_file']), $cartella . '/' . basename($r['nome_file']));
        }
    }

    // Foto delle fasi-racconto del progetto (nomi file in foto_urls).
    $st = $db->prepare("SELECT id, foto_urls FROM fasi WHERE progetto_id = ? ORDER BY id ASC");
    $st->execute([$id]);
    foreach ($st->fetchAll() as $f) {
        foreach ((json_decode($f['foto_urls'] ?? '[]', true) ?: []) as $fn) {
            if (!is_string($fn) || $fn === '') continue;
            $nome = basename($fn);
            $agg($base . '/fasi/' . (int) $f['id'] . '/' . $nome, 'fasi/' . (int) $f['id'] . '/' . $nome);
        }
    }

    return ['file' => $file, 'saltati' => $saltat];
}

/** Come sopra, per un CLIENTE: PDF dei preventivi e foto caricate in fase di lead. */
function archFileCliente(PDO $db, string $session): array {
    $file = []; $saltat = [];

    $st = $db->prepare("SELECT numero, file_pdf FROM preventivi WHERE session_id = ? AND file_pdf IS NOT NULL AND file_pdf <> '' ORDER BY id ASC");
    $st->execute([$session]);
    foreach ($st->fetchAll() as $p) {
        $path = __DIR__ . '/preventivi_pdf/' . basename((string) $p['file_pdf']);
        if (is_file($path)) $file[] = ['path' => $path, 'rel' => 'preventivi/' . basename((string) $p['file_pdf'])];
        else $saltat[] = ['rel' => 'preventivi/' . basename((string) $p['file_pdf']), 'motivo' => 'PDF non trovato su disco'];
    }

    $dirLead = rtrim(ARDY_UPLOAD_DIR, '/') . '/' . $session . '/';
    foreach (glob($dirLead . '*') ?: [] as $p) {
        if (is_file($p) && filesize($p) > 0) $file[] = ['path' => $p, 'rel' => 'foto-lead/' . basename($p)];
    }

    return ['file' => $file, 'saltati' => $saltat];
}

/** Scheda del progetto in Markdown: l'archivio deve restare leggibile senza la dash. */
function archSchedaProgetto(PDO $db, array $p): string {
    $id = (int) $p['id'];
    $r  = function ($v) { $v = trim((string) $v); return $v !== '' ? $v : '—'; };

    $md  = "# {$p['titolo']}\n\n";
    $md .= "- **Tipo**: " . $r($p['tipo']) . "\n";
    $md .= "- **Stato**: " . $r($p['stato']) . "\n";
    $md .= "- **Metodo**: " . $r($p['metodo'] ?? '') . "\n";
    $md .= "- **Slug**: " . $r($p['slug'] ?? '') . "\n";
    $md .= "- **Prezzo di vendita**: " . $r($p['prezzo_vendita']) . " € · **Costo di produzione**: " . $r($p['costo_produzione']) . " €\n";
    $md .= "- **Articolo**: " . $r($p['wp_post_link'] ?? '') . "\n";
    $md .= "- **Prodotto Woo**: " . $r($p['woo_product_id'] ?? '') . "\n\n";

    foreach ([['Concept', 'descrizione'], ['Storia del pezzo', 'storia'], ['Materiali', 'materiali'],
              ['Scheda tecnica', 'scheda_tecnica'], ['Dimensioni', 'dimensioni'], ['Cura', 'cura']] as [$tit, $col]) {
        if (trim((string) ($p[$col] ?? '')) !== '') $md .= "## $tit\n\n" . trim((string) $p[$col]) . "\n\n";
    }

    $st = $db->prepare("SELECT categoria, voce, qta, unita, costo_unitario, costo_riga FROM progetto_materiali WHERE progetto_id = ? ORDER BY id ASC");
    $st->execute([$id]);
    $mat = $st->fetchAll();
    if ($mat) {
        $md .= "## Distinta materiali\n\n| Categoria | Voce | Q.tà | Unità | € unit. | € riga |\n|---|---|---|---|---|---|\n";
        foreach ($mat as $m) {
            $md .= "| {$m['categoria']} | {$m['voce']} | {$m['qta']} | {$m['unita']} | {$m['costo_unitario']} | {$m['costo_riga']} |\n";
        }
        $md .= "\n";
    }

    $st = $db->prepare("SELECT versione, note, cad_ref, created_at FROM progetto_iterazioni WHERE progetto_id = ? ORDER BY id ASC");
    $st->execute([$id]);
    if ($it = $st->fetchAll()) {
        $md .= "## Iterazioni del prototipo\n\n";
        foreach ($it as $i) $md .= "- **{$i['versione']}** ({$i['created_at']}): " . $r($i['note']) . ($i['cad_ref'] ? " · CAD: {$i['cad_ref']}" : '') . "\n";
        $md .= "\n";
    }

    $st = $db->prepare("SELECT fase_nome, testo_breve, wp_pubblicata_at FROM fasi WHERE progetto_id = ? ORDER BY id ASC");
    $st->execute([$id]);
    if ($fasi = $st->fetchAll()) {
        $md .= "## Fasi-racconto\n\n";
        foreach ($fasi as $f) {
            $md .= "### " . $r($f['fase_nome']) . ($f['wp_pubblicata_at'] ? " _(pubblicata il {$f['wp_pubblicata_at']})_" : '') . "\n\n";
            $md .= trim((string) $f['testo_breve']) . "\n\n";
        }
    }

    // I documenti di riferimento entrano col loro testo già estratto: l'archivio
    // conserva il materiale da cui è nato il racconto, non solo il racconto.
    $st = $db->prepare("SELECT nome_originale, testo_estratto FROM progetto_file WHERE progetto_id = ? AND categoria = 'doc' AND testo_estratto IS NOT NULL ORDER BY id ASC");
    $st->execute([$id]);
    if ($doc = $st->fetchAll()) {
        $md .= "## Documenti di riferimento (testo estratto)\n\n";
        foreach ($doc as $d) $md .= "### {$d['nome_originale']}\n\n" . trim((string) $d['testo_estratto']) . "\n\n";
    }

    return $md;
}

/** Checklist "è tutto al suo posto?" — informativa: decide l'autore, non il sistema. */
function archChecklistProgetto(PDO $db, array $p): array {
    $id  = (int) $p['id'];
    $c   = [];
    $n   = fn(string $sql) => (int) $db->query($sql)->fetchColumn();

    $c[] = ['voce' => 'Ciclo di vita a CATALOGATO', 'ok' => in_array($p['stato'], ARCH_STATI_PROG, true)];
    $c[] = ['voce' => 'Articolo pubblicato su WordPress', 'ok' => !empty($p['wp_post_id'])];
    $c[] = ['voce' => 'Prodotto inviato al negozio', 'ok' => !empty($p['woo_product_id'])];
    $c[] = ['voce' => 'Foto vendita presenti', 'ok' => $n("SELECT COUNT(*) FROM progetto_foto_vendita WHERE progetto_id = $id") > 0];
    $daLeggere = $n("SELECT COUNT(*) FROM progetto_file WHERE progetto_id = $id AND categoria = 'doc' AND testo_estratto IS NULL");
    $c[] = ['voce' => 'Documenti tutti letti', 'ok' => $daLeggere === 0,
            'nota' => $daLeggere > 0 ? "$daLeggere ancora da leggere" : ''];
    return $c;
}

function archChecklistCliente(PDO $db, array $cl): array {
    $s = $cl['session_id'];
    $c = [];
    $st = $db->prepare("SELECT COUNT(*) FROM fasi WHERE session_id = ?");           $st->execute([$s]); $nFasi = (int) $st->fetchColumn();
    $st = $db->prepare("SELECT COUNT(*) FROM preventivi WHERE session_id = ?");     $st->execute([$s]); $nPrev = (int) $st->fetchColumn();

    $c[] = ['voce' => 'Lavoro consegnato', 'ok' => in_array($cl['stato'], ARCH_STATI_CLI, true)];
    $c[] = ['voce' => 'Fasi di lavorazione registrate', 'ok' => $nFasi > 0, 'nota' => "$nFasi fasi"];
    $c[] = ['voce' => 'Preventivo agli atti', 'ok' => $nPrev > 0];
    $c[] = ['voce' => 'Dossier generabile', 'ok' => true];
    return $c;
}

try {
    $in   = json_decode(file_get_contents('php://input'), true) ?: [];
    $mode = $in['mode'] ?? 'stato';
    $tipo = ($in['tipo'] ?? '') === 'cliente' ? 'cliente' : 'progetto';
    $db   = ardyDB();

    // ── Soggetto: progetto o cliente ─────────────────────────
    if ($tipo === 'progetto') {
        $id = (int) ($in['id'] ?? 0);
        $st = $db->prepare("SELECT * FROM progetti WHERE id = ? AND deleted_at IS NULL");
        $st->execute([$id]);
        $sog = $st->fetch();
        if (!$sog) { echo json_encode(['success' => false, 'error' => 'Progetto non trovato']); exit(); }
        $etichetta  = trim((string) $sog['titolo']) ?: ('progetto-' . $id);
        $prefisso   = archPrefisso('progetti', $id . '-' . $etichetta);
        $checklist  = archChecklistProgetto($db, $sog);
        $statoOk    = in_array($sog['stato'], ARCH_STATI_PROG, true);
        $elenco     = archFileProgetto($db, $id);
        $tabella    = 'progetti'; $doveId = 'id'; $valId = $id;
    } else {
        $sess = trim((string) ($in['id'] ?? ''));
        $st = $db->prepare("SELECT * FROM clienti WHERE session_id = ? AND deleted_at IS NULL");
        $st->execute([$sess]);
        $sog = $st->fetch();
        if (!$sog) { echo json_encode(['success' => false, 'error' => 'Cliente non trovato']); exit(); }
        $etichetta  = trim(($sog['nome'] ?? '') . ' ' . ($sog['cognome'] ?? '')) ?: $sess;
        $prefisso   = archPrefisso('clienti', $sess . '-' . $etichetta);
        $checklist  = archChecklistCliente($db, $sog);
        $statoOk    = in_array($sog['stato'], ARCH_STATI_CLI, true);
        $elenco     = archFileCliente($db, $sess);
        $tabella    = 'clienti'; $doveId = 'session_id'; $valId = $sess;
    }

    $file    = $elenco['file'];
    $saltati = $elenco['saltati'];

    if ($mode === 'stato') {
        echo json_encode([
            'success'    => true,
            'archiviabile' => $statoOk,
            'checklist'  => $checklist,
            'file'       => count($file),
            'byte'       => array_sum(array_map(fn($f) => filesize($f['path']) ?: 0, $file)),
            'saltati'    => $saltati,
            'b2'         => ardyB2Configured(),
            'archiviato_at' => $sog['archiviato_at'] ?? null,
            'archivio_prefisso' => $sog['archivio_prefisso'] ?? null,
        ]);
        exit();
    }

    if ($mode !== 'archivia') { echo json_encode(['success' => false, 'error' => 'mode non valido']); exit(); }
    if (!ardyB2Configured()) { echo json_encode(['success' => false, 'error' => 'Backblaze B2 non è configurato']); exit(); }
    if (!$statoOk) { echo json_encode(['success' => false, 'error' => 'Si archivia a ciclo chiuso: progetto CATALOGATO o cliente CONSEGNATO']); exit(); }

    @set_time_limit(300);
    $offset = max(0, (int) ($in['offset'] ?? 0));
    $lotto  = array_slice($file, $offset, ARCH_LOTTO);
    $ok = 0; $ko = 0; $errori = [];

    foreach ($lotto as $f) {
        $chiave = $prefisso . '/' . $f['rel'];
        if (ardyStorageArchiviaFile($chiave, $f['path'], archMime($f['rel']))) $ok++;
        else { $ko++; if (count($errori) < 5) $errori[] = $f['rel']; }
    }

    $fatti  = $offset + count($lotto);
    $finito = $fatti >= count($file);

    if (!$finito) {
        echo json_encode(['success' => true, 'finito' => false, 'offset' => $fatti,
            'totale' => count($file), 'caricati' => $ok, 'falliti' => $ko, 'errori' => $errori]);
        exit();
    }

    // ── Ultimo lotto: dossier + manifest, verifica e registrazione ───────────
    $manifest = [
        'tipo'        => $tipo,
        'id'          => $valId,
        'etichetta'   => $etichetta,
        'archiviato'  => date('c'),
        'file'        => array_map(fn($f) => ['nome' => $f['rel'], 'byte' => filesize($f['path']) ?: 0], $file),
        'non_inclusi' => $saltati,
        'checklist'   => $checklist,
    ];

    if ($tipo === 'progetto') {
        ardyStorageArchivia($prefisso . '/scheda.md', archSchedaProgetto($db, $sog), 'text/markdown; charset=utf-8');
        // Le foto delle fasi già pubblicate vivono anche sull'articolo WordPress:
        // il manifest tiene il link, così l'archivio dice dov'è finito il racconto.
        $manifest['articolo_wp'] = $sog['wp_post_link'] ?? null;
    } else {
        $dossier = ardy_genera_dossier($db, (string) $valId);
        if ($dossier !== null) ardyStorageArchivia($prefisso . '/dossier.md', $dossier, 'text/markdown; charset=utf-8');
        // Le foto delle fasi cliente sono già su WordPress (foto_urls = URL): non si
        // ricaricano, se ne conserva l'indirizzo.
        $st = $db->prepare("SELECT fase_nome, foto_urls, wp_pubblicata_at FROM fasi WHERE session_id = ? ORDER BY id ASC");
        $st->execute([$valId]);
        $manifest['fasi_su_wordpress'] = $st->fetchAll();
    }

    $manifestJson = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $manifestOk   = ardyStorageArchivia($prefisso . '/manifest.json', (string) $manifestJson, 'application/json');

    // Verifica: si rilegge il manifest appena scritto. Un archivio che non si riesce a
    // rileggere non è un archivio — meglio saperlo adesso che il giorno del bisogno.
    $riletto  = ardyStorageGet($prefisso . '/manifest.json');
    $verificato = is_string($riletto) && $riletto !== '' && json_decode($riletto, true) !== null;

    try {
        $db->prepare("UPDATE `$tabella` SET archiviato_at = NOW(), archivio_prefisso = :pre,
                          archivio_file = :nf, archivio_verificato = :ver WHERE `$doveId` = :id")
           ->execute([':pre' => $prefisso, ':nf' => count($file), ':ver' => $verificato ? 1 : 0, ':id' => $valId]);
    } catch (PDOException $e) {
        error_log('ARDY ARCHIVIA B2 UPDATE: ' . $e->getMessage());
    }

    echo json_encode([
        'success' => true, 'finito' => true, 'totale' => count($file),
        'caricati' => $ok, 'falliti' => $ko, 'errori' => $errori,
        'manifest' => (bool) $manifestOk, 'verificato' => $verificato,
        'prefisso' => $prefisso, 'non_inclusi' => count($saltati),
    ]);

} catch (PDOException $e) {
    error_log('ARDY ARCHIVIA B2 ERROR: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Errore interno']);
}
