<?php
// -----------------------------------------------------------
// ARDY LAB — Dash Design: API progetti interni (CRUD + BOM/costi + iterazioni)
// Gemella di ardy-crm-api.php ma sul soggetto "progetto" invece di "cliente".
// Vedi PIANO-DASH-DESIGN.md. Tabelle create da ardy-migrate.php.
//
//   GET                          → lista progetti (non eliminati), con costo/margine
//   GET ?id=N                    → un progetto completo: BOM, iterazioni, fasi, config
//   POST {mode:'save', ...}      → upsert progetto (ritorna id)
//   POST {mode:'stato', id, stato}   → cambia stato del progetto
//   POST {mode:'congela', id, snapshot} → segna file congelato + salva snapshot
//   POST {mode:'delete', id}     → soft delete (deleted_at)
//   POST {mode:'social_fatto', tipo:'progetto'|'fase', id, testo} → segna l'invio ai social
//   POST {mode:'mat_save', progetto_id, ...riga} → upsert riga BOM (ricalcola costo)
//   POST {mode:'mat_save_batch', progetto_id, righe:[...]} → upsert N righe in 1 transazione
//   POST {mode:'mat_delete', id} → elimina riga BOM (ricalcola costo)
//   POST {mode:'iter_save', progetto_id, ...} → upsert iterazione prototipo
//   POST {mode:'iter_delete', id} → elimina iterazione
//
// Protetto via .htaccess (Basic Auth) — elencato nel <FilesMatch>.
// -----------------------------------------------------------

require_once __DIR__ . '/ardy-db.php';

header('Access-Control-Allow-Origin: https://ardyagent.ardy-lab.it');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }
// Difesa in profondità: se il Basic Auth a monte (.htaccess) non venisse
// applicato, questo guard rifiuta comunque le richieste non autenticate.
require_once __DIR__ . '/ardy-auth.php';
ardyRequireAuth();

// Ciclo di vita del progetto (vedi PIANO-DASH-DESIGN.md §3): idea → progetto → prototipo
// → pezzo definitivo → scheda di vendita → esposto in vetrina. Ogni fase è raccontabile e
// pubblicabile (articolo + fasi-racconto sono attivi già da IDEA). 'CATALOGATO' è terminale
// lato dash: stock/ordini/venduto vivono su Woo/Etsy, non qui.
const PROGETTO_STATI = ['IDEA', 'PROGETTAZIONE', 'PROTOTIPO', 'VERSIONE_FINALE', 'SCHEDA_PRODOTTO', 'CATALOGATO'];
const PROGETTO_TIPI  = ['lampada', 'mobile', 'complemento', 'restyling', 'prototipo'];
// Come si realizza il pezzo → guida i moduli tecnici mostrati nella dash.
const PROGETTO_METODI = ['stampa_3d', 'restyling', 'altro'];
// Come va a catalogo → guida stock/quantità su Woo (NON è una fase del ciclo).
const PROGETTO_DISPONIBILITA = ['pronto', 'su_ordinazione', 'pezzo_unico'];
const MAT_CATEGORIE  = ['filamento', 'stampa', 'legno', 'elettrico', 'ferramenta', 'finitura', 'imballo', 'manodopera'];

/** Tariffa oraria manodopera di default (override in ardy-config.php non versionato). */
function progettoCostoOrario(): float {
    return defined('ARDY_DESIGN_COSTO_ORARIO') ? (float) ARDY_DESIGN_COSTO_ORARIO : 50.00;
}

/** Numero: accetta float o stringa "1.450,00" / "350,00" (come ardy-fasi-bozza-api). */
function progettoParseNum($v): float {
    if (is_numeric($v)) return (float) $v;
    if (!is_string($v) || trim($v) === '') return 0.0;
    $s = preg_replace('/[^\d,.\-]/', '', trim($v));
    if ($s === '') return 0.0;
    if (strpos($s, ',') !== false && strpos($s, '.') !== false) {
        if (strrpos($s, ',') > strrpos($s, '.')) { $s = str_replace('.', '', $s); $s = str_replace(',', '.', $s); }
        else { $s = str_replace(',', '', $s); }
    } elseif (strpos($s, ',') !== false) {
        $s = str_replace(',', '.', $s);
    }
    return is_numeric($s) ? (float) $s : 0.0;
}

/** Ricalcola e salva progetti.costo_produzione = Σ costo_riga × (1 + scarto/100). */
function progettoRicalcolaCosto(PDO $db, int $progettoId): float {
    $somma = (float) $db->query(
        "SELECT COALESCE(SUM(costo_riga),0) FROM progetto_materiali WHERE progetto_id = " . (int) $progettoId
    )->fetchColumn();
    $scarto = (float) $db->query(
        "SELECT COALESCE(scarto_pct,0) FROM progetti WHERE id = " . (int) $progettoId
    )->fetchColumn();
    $costo = round($somma * (1 + $scarto / 100), 2);
    $db->prepare("UPDATE progetti SET costo_produzione = :c WHERE id = :id")
       ->execute([':c' => $costo, ':id' => $progettoId]);
    return $costo;
}

/** Decodifica i campi JSON di una riga progetto e aggiunge il margine. */
function progettoArricchisci(array $r): array {
    if (array_key_exists('wp_immagini', $r)) {
        $r['wp_immagini'] = json_decode($r['wp_immagini'] ?? '[]', true) ?? [];
    }
    foreach (['render_urls', 'cad_urls', 'canali_vendita', 'file_snapshot'] as $k) {
        $r[$k] = json_decode($r[$k] ?? 'null', true);
        if ($r[$k] === null && $k !== 'file_snapshot') $r[$k] = [];
    }
    $prezzo = $r['prezzo_vendita'] !== null ? (float) $r['prezzo_vendita'] : null;
    $costo  = $r['costo_produzione'] !== null ? (float) $r['costo_produzione'] : null;
    $r['margine'] = ($prezzo !== null && $costo !== null) ? round($prezzo - $costo, 2) : null;
    $r['variante']  = $r['variante'] ?? 'standard';
    $r['parent_id'] = isset($r['parent_id']) && $r['parent_id'] !== null ? (int) $r['parent_id'] : null;
    return $r;
}

/** Slug URL-safe da un testo (per /prodotto/{slug} su Woo + chiave scheda-Sole). */
function progettoSlugify(string $s): string {
    $s = trim($s);
    if (function_exists('iconv')) {
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT', $s);
        if ($t !== false) $s = $t;
    }
    $s = preg_replace('/[^a-z0-9]+/', '-', strtolower($s));
    return substr(trim($s, '-'), 0, 70);
}

/** Slug univoco: appende -2, -3… se già preso (escludendo il progetto stesso). */
function progettoSlugUnico(PDO $db, string $base, int $exceptId = 0): string {
    $base = $base !== '' ? $base : 'oggetto';
    $slug = $base; $i = 2;
    $st = $db->prepare("SELECT id FROM progetti WHERE slug = ? AND id <> ? LIMIT 1");
    while (true) {
        $st->execute([$slug, $exceptId]);
        if (!$st->fetch()) return $slug;
        $slug = substr($base, 0, 66) . '-' . $i++;
    }
}

/** Normalizza le FAQ pubbliche in JSON [{q,a}]. Accetta array o testo "Domanda | Risposta" per riga. */
function progettoFaqJson($v): ?string {
    if ($v === null || $v === '') return null;
    $out = [];
    if (is_array($v)) {
        foreach ($v as $item) {
            $q = trim((string) ($item['q'] ?? '')); $a = trim((string) ($item['a'] ?? ''));
            if ($q !== '' || $a !== '') $out[] = ['q' => $q, 'a' => $a];
        }
    } elseif (is_string($v)) {
        foreach (preg_split('/\r?\n/', $v) as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $p = explode('|', $line, 2);
            $q = trim($p[0]); $a = trim($p[1] ?? '');
            if ($q !== '') $out[] = ['q' => $q, 'a' => $a];
        }
    }
    return $out ? json_encode($out, JSON_UNESCAPED_UNICODE) : null;
}

try {
    $db = ardyDB();

    // ── GET ──────────────────────────────────────────────────
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $id = (int) ($_GET['id'] ?? 0);

        if ($id <= 0) {
            // Lista (sommario) — esclude gli eliminati.
            $rows = $db->query(
                "SELECT * FROM progetti WHERE deleted_at IS NULL ORDER BY updated_at DESC"
            )->fetchAll();
            echo json_encode(['success' => true, 'progetti' => array_map('progettoArricchisci', $rows)]);
            exit();
        }

        // Dettaglio completo.
        $st = $db->prepare("SELECT * FROM progetti WHERE id = ? AND deleted_at IS NULL");
        $st->execute([$id]);
        $p = $st->fetch();
        if (!$p) { http_response_code(404); echo json_encode(['success' => false, 'error' => 'Progetto non trovato']); exit(); }
        $p = progettoArricchisci($p);

        $mat = $db->prepare("SELECT * FROM progetto_materiali WHERE progetto_id = ? ORDER BY id ASC");
        $mat->execute([$id]);

        $iter = $db->prepare("SELECT * FROM progetto_iterazioni WHERE progetto_id = ? ORDER BY v_num ASC, id ASC");
        $iter->execute([$id]);
        $iterRows = $iter->fetchAll();
        foreach ($iterRows as &$it) { $it['foto_urls'] = json_decode($it['foto_urls'] ?? '[]', true) ?? []; }
        unset($it);

        $fasi = $db->prepare("SELECT * FROM fasi WHERE progetto_id = ? ORDER BY ordine ASC, created_at ASC");
        $fasi->execute([$id]);
        $fasiRows = $fasi->fetchAll();
        // foto_urls = nomi dei file su disco (anteprime in dash); foto_wp_urls = gli stessi
        // scatti dopo la pubblicazione su WordPress, con URL pubblici → sono quelli che
        // vanno ai social, che devono poter scaricare l'immagine.
        foreach ($fasiRows as &$f) {
            $f['foto_urls']    = json_decode($f['foto_urls'] ?? '[]', true) ?? [];
            $f['foto_wp_urls'] = json_decode($f['foto_wp_urls'] ?? '[]', true) ?? [];
        }
        unset($f);

        // Archivio file di stampa/CAD (metadati; i binari si scaricano da ardy-progetti-file-api.php).
        // Binari di stampa + documenti di riferimento (categoria 'doc'). Del testo
        // estratto dai documenti serve solo sapere se c'è e quanto è lungo: il testo
        // vero resta sul server, lo legge ardy-progetti-ai.php quando scrive l'articolo.
        $file = $db->prepare(
            "SELECT id, categoria, nome_originale, dimensione, note, created_at, testo_estratto_at,
                    CHAR_LENGTH(COALESCE(testo_estratto,'')) AS testo_caratteri
             FROM progetto_file WHERE progetto_id = ? ORDER BY created_at DESC, id DESC"
        );
        $file->execute([$id]);

        // Galleria (render + foto finite) per l'articolo WordPress.
        $gall = $db->prepare(
            "SELECT id, tipo, dimensione, ordine, created_at
             FROM progetto_galleria WHERE progetto_id = ? ORDER BY ordine ASC, id ASC"
        );
        $gall->execute([$id]);

        // Foto vendita (Modulo 2 / Woo): set separato dalla galleria.
        $fv = $db->prepare(
            "SELECT id, dimensione, ordine, created_at
             FROM progetto_foto_vendita WHERE progetto_id = ? ORDER BY ordine ASC, id ASC"
        );
        $fv->execute([$id]);

        echo json_encode([
            'success'      => true,
            'progetto'     => $p,
            'materiali'    => $mat->fetchAll(),
            'iterazioni'   => $iterRows,
            'fasi'         => $fasiRows,
            'file'         => $file->fetchAll(),
            'galleria'     => $gall->fetchAll(),
            'foto_vendita' => $fv->fetchAll(),
            'config'     => [
                'stati'             => PROGETTO_STATI,
                'tipi'              => PROGETTO_TIPI,
                'metodi'            => PROGETTO_METODI,
                'disponibilita'     => PROGETTO_DISPONIBILITA,
                'categorie'         => MAT_CATEGORIE,
                'costo_orario'      => progettoCostoOrario(),
            ],
        ]);
        exit();
    }

    // ── POST ─────────────────────────────────────────────────
    $in   = json_decode(file_get_contents('php://input'), true) ?: [];
    $mode = $in['mode'] ?? 'save';

    // — upsert progetto —
    if ($mode === 'save') {
        $id     = (int) ($in['id'] ?? 0);
        $titolo = trim((string) ($in['titolo'] ?? ''));
        if ($titolo === '') { echo json_encode(['success' => false, 'error' => 'Titolo mancante']); exit(); }

        $tipo  = in_array($in['tipo'] ?? '', PROGETTO_TIPI, true) ? $in['tipo'] : 'lampada';

        $fields = [
            'titolo'         => $titolo,
            'tipo'           => $tipo,
            'metodo'         => in_array($in['metodo'] ?? '', PROGETTO_METODI, true) ? $in['metodo'] : 'stampa_3d',
            'disponibilita'  => in_array($in['disponibilita'] ?? '', PROGETTO_DISPONIBILITA, true) ? $in['disponibilita'] : 'pronto',
            'descrizione'    => trim((string) ($in['descrizione'] ?? '')),
            'storia'         => trim((string) ($in['storia'] ?? '')),
            'materiali'      => trim((string) ($in['materiali'] ?? '')),
            'scheda_tecnica' => trim((string) ($in['scheda_tecnica'] ?? '')),
            'dimensioni'     => trim((string) ($in['dimensioni'] ?? '')),
            'cura'           => trim((string) ($in['cura'] ?? '')),
            'teaser_vendita' => trim((string) ($in['teaser_vendita'] ?? '')),
            'faq_pubbliche'  => progettoFaqJson($in['faq_pubbliche'] ?? ($in['faq'] ?? null)),
            'scheda_sole_pubblica' => !empty($in['scheda_sole_pubblica']) ? 1 : 0,
            'scarto_pct'     => max(0, progettoParseNum($in['scarto_pct'] ?? 10)),
            'prezzo_vendita' => isset($in['prezzo_vendita']) && $in['prezzo_vendita'] !== '' ? progettoParseNum($in['prezzo_vendita']) : null,
            'tempo_lavoro'   => trim((string) ($in['tempo_lavoro'] ?? '')),
        ];

        // Lo stato si imposta SOLO alla creazione (default IDEA) o se fornito ed è
        // valido: il salvataggio della scheda NON deve azzerare il ciclo di vita
        // (che si cambia dalla pipeline). Altrimenti ogni "Salva" riportava a IDEA.
        if ($id <= 0) {
            $fields['stato'] = in_array($in['stato'] ?? '', PROGETTO_STATI, true) ? $in['stato'] : 'IDEA';
        } elseif (isset($in['stato']) && in_array($in['stato'], PROGETTO_STATI, true)) {
            $fields['stato'] = $in['stato'];
        }

        if ($id > 0) {
            $set = implode(', ', array_map(fn($k) => "`$k` = :$k", array_keys($fields)));
            $st  = $db->prepare("UPDATE progetti SET $set WHERE id = :id AND deleted_at IS NULL");
            $st->execute($fields + [':id' => $id]);
        } else {
            $cols = implode(', ', array_map(fn($k) => "`$k`", array_keys($fields)));
            $phs  = implode(', ', array_map(fn($k) => ":$k", array_keys($fields)));
            $db->prepare("INSERT INTO progetti ($cols) VALUES ($phs)")->execute($fields);
            $id = (int) $db->lastInsertId();
        }
        // Slug (chiave prodotto Woo + scheda-Sole): se l'utente lo fornisce lo si usa
        // (reso univoco); altrimenti si genera dal titolo SOLO se ancora vuoto — non si
        // cambia uno slug esistente, per non rompere l'URL/aggancio di un prodotto già
        // pubblicato.
        $slugCur = (string) $db->query("SELECT slug FROM progetti WHERE id = " . (int) $id)->fetchColumn();
        $slugIn  = isset($in['slug']) ? progettoSlugify((string) $in['slug']) : '';
        if ($slugIn !== '') {
            $db->prepare("UPDATE progetti SET slug = :s WHERE id = :id")
               ->execute([':s' => progettoSlugUnico($db, $slugIn, $id), ':id' => $id]);
        } elseif ($slugCur === '') {
            $db->prepare("UPDATE progetti SET slug = :s WHERE id = :id")
               ->execute([':s' => progettoSlugUnico($db, progettoSlugify($titolo), $id), ':id' => $id]);
        }

        progettoRicalcolaCosto($db, $id); // lo scarto può essere cambiato qui
        echo json_encode(['success' => true, 'id' => $id]);
        exit();
    }

    // — duplica progetto come variante (Premium): copia agganciata via parent_id —
    // Copia scheda + distinta materiali in un NUOVO progetto collegato all'originale.
    // Slug/Woo/WP/media NON si copiano: la variante è un prodotto a sé (branch).
    if ($mode === 'duplica') {
        $srcId = (int) ($in['id'] ?? 0);
        if ($srcId <= 0) { echo json_encode(['success' => false, 'error' => 'id mancante']); exit(); }
        $variante = in_array($in['variante'] ?? '', ['standard', 'premium'], true) ? $in['variante'] : 'premium';

        $st = $db->prepare("SELECT * FROM progetti WHERE id = ? AND deleted_at IS NULL");
        $st->execute([$srcId]);
        $src = $st->fetch();
        if (!$src) { echo json_encode(['success' => false, 'error' => 'Progetto d\'origine non trovato']); exit(); }

        // Campi di contenuto copiati; l'identità di prodotto (slug, Woo, WP, media,
        // congelamento) riparte da zero perché la variante è un oggetto vendibile a sé.
        $suffix = $variante === 'premium' ? ' — Premium' : ' — Copia';
        $fields = [
            'titolo'         => mb_substr(trim((string) $src['titolo']) . $suffix, 0, 200),
            'tipo'           => $src['tipo'],
            'variante'       => $variante,
            'parent_id'      => $srcId,
            'stato'          => $src['stato'],
            'metodo'         => $src['metodo'] ?? 'stampa_3d',
            'disponibilita'  => $src['disponibilita'] ?? 'pronto',
            'descrizione'    => $src['descrizione'],
            'storia'         => $src['storia'] ?? null,
            'materiali'      => $src['materiali'],
            'scheda_tecnica' => $src['scheda_tecnica'],
            'dimensioni'     => $src['dimensioni'] ?? null,
            'cura'           => $src['cura'] ?? null,
            'teaser_vendita' => $src['teaser_vendita'] ?? null,
            'faq_pubbliche'  => $src['faq_pubbliche'] ?? null,
            'scheda_sole_pubblica' => 0,
            'scarto_pct'     => $src['scarto_pct'],
            'prezzo_vendita' => $src['prezzo_vendita'] !== null ? (float) $src['prezzo_vendita'] : null,
            'tempo_lavoro'   => $src['tempo_lavoro'] ?? null,
        ];
        $cols = implode(', ', array_map(fn($k) => "`$k`", array_keys($fields)));
        $phs  = implode(', ', array_map(fn($k) => ":$k", array_keys($fields)));
        $db->prepare("INSERT INTO progetti ($cols) VALUES ($phs)")->execute($fields);
        $newId = (int) $db->lastInsertId();

        // Slug proprio (dal titolo con suffisso), univoco.
        $db->prepare("UPDATE progetti SET slug = :s WHERE id = :id")
           ->execute([':s' => progettoSlugUnico($db, progettoSlugify($fields['titolo']), $newId), ':id' => $newId]);

        // Copia la distinta materiali (base da cui la Premium viene rifinita).
        $mats = $db->prepare("SELECT categoria, voce, qta, unita, costo_unitario, costo_riga, note FROM progetto_materiali WHERE progetto_id = ?");
        $mats->execute([$srcId]);
        $ins = $db->prepare(
            "INSERT INTO progetto_materiali (progetto_id, categoria, voce, qta, unita, costo_unitario, costo_riga, note)
             VALUES (:pid, :c, :v, :q, :u, :cu, :cr, :n)"
        );
        foreach ($mats->fetchAll() as $m) {
            $ins->execute([':pid'=>$newId, ':c'=>$m['categoria'], ':v'=>$m['voce'], ':q'=>$m['qta'],
                ':u'=>$m['unita'], ':cu'=>$m['costo_unitario'], ':cr'=>$m['costo_riga'], ':n'=>$m['note']]);
        }
        progettoRicalcolaCosto($db, $newId);
        echo json_encode(['success' => true, 'id' => $newId]);
        exit();
    }

    // — cambia stato —
    if ($mode === 'stato') {
        $id    = (int) ($in['id'] ?? 0);
        $stato = $in['stato'] ?? '';
        if ($id <= 0 || !in_array($stato, PROGETTO_STATI, true)) {
            echo json_encode(['success' => false, 'error' => 'Stato non valido']); exit();
        }
        $db->prepare("UPDATE progetti SET stato = :s WHERE id = :id AND deleted_at IS NULL")
           ->execute([':s' => $stato, ':id' => $id]);
        echo json_encode(['success' => true]);
        exit();
    }

    // — congela file (scatta lo snapshot, click manuale "a naso") —
    if ($mode === 'congela') {
        $id = (int) ($in['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['success' => false, 'error' => 'id mancante']); exit(); }
        $snapshot = $in['snapshot'] ?? null; // {stl, profilo_orca, grammi, ore, scheda, ...}
        $db->prepare(
            "UPDATE progetti SET file_congelato_at = NOW(), file_snapshot = :snap, stato = 'VERSIONE_FINALE'
             WHERE id = :id AND deleted_at IS NULL"
        )->execute([':snap' => $snapshot !== null ? json_encode($snapshot) : null, ':id' => $id]);
        echo json_encode(['success' => true]);
        exit();
    }

    // — soft delete —
    // Segna l'avvenuto invio ai social e conserva la caption usata (per rileggerla e
    // per non rigenerarla ogni volta). Vale sia per l'articolo del progetto sia per una
    // singola fase-racconto: è lo stesso gesto su due soggetti.
    if ($mode === 'social_fatto') {
        $tipo  = ($in['tipo'] ?? 'fase') === 'progetto' ? 'progetto' : 'fase';
        $id    = (int) ($in['id'] ?? 0);
        $testo = trim((string) ($in['testo'] ?? ''));
        if ($id <= 0) { echo json_encode(['success' => false, 'error' => 'id mancante']); exit(); }
        if ($tipo === 'progetto') {
            $db->prepare("UPDATE progetti SET social_pubblicato_at = NOW(), testo_social = :t WHERE id = :id")
               ->execute([':t' => $testo !== '' ? $testo : null, ':id' => $id]);
        } else {
            $db->prepare("UPDATE fasi SET social_pubblicata_at = NOW(), testo_social = :t WHERE id = :id AND progetto_id IS NOT NULL")
               ->execute([':t' => $testo !== '' ? $testo : null, ':id' => $id]);
        }
        echo json_encode(['success' => true]);
        exit();
    }

    if ($mode === 'delete') {
        $id = (int) ($in['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['success' => false, 'error' => 'id mancante']); exit(); }
        $db->prepare("UPDATE progetti SET deleted_at = NOW() WHERE id = ?")->execute([$id]);
        echo json_encode(['success' => true]);
        exit();
    }

    // — upsert riga BOM —
    if ($mode === 'mat_save') {
        $progettoId = (int) ($in['progetto_id'] ?? 0);
        if ($progettoId <= 0) { echo json_encode(['success' => false, 'error' => 'progetto mancante']); exit(); }
        $id        = (int) ($in['id'] ?? 0);
        $categoria = in_array($in['categoria'] ?? '', MAT_CATEGORIE, true) ? $in['categoria'] : 'filamento';
        $voce      = trim((string) ($in['voce'] ?? ''));
        $qta       = progettoParseNum($in['qta'] ?? 0);
        $unita     = trim((string) ($in['unita'] ?? 'pz')) ?: 'pz';
        $costoUnit = progettoParseNum($in['costo_unitario'] ?? 0);
        $note      = trim((string) ($in['note'] ?? ''));
        $costoRiga = round($qta * $costoUnit, 2);

        if ($id > 0) {
            $db->prepare(
                "UPDATE progetto_materiali SET categoria=:c, voce=:v, qta=:q, unita=:u, costo_unitario=:cu, costo_riga=:cr, note=:n
                 WHERE id=:id AND progetto_id=:pid"
            )->execute([':c'=>$categoria, ':v'=>$voce, ':q'=>$qta, ':u'=>$unita, ':cu'=>$costoUnit, ':cr'=>$costoRiga, ':n'=>$note, ':id'=>$id, ':pid'=>$progettoId]);
        } else {
            $db->prepare(
                "INSERT INTO progetto_materiali (progetto_id, categoria, voce, qta, unita, costo_unitario, costo_riga, note)
                 VALUES (:pid, :c, :v, :q, :u, :cu, :cr, :n)"
            )->execute([':pid'=>$progettoId, ':c'=>$categoria, ':v'=>$voce, ':q'=>$qta, ':u'=>$unita, ':cu'=>$costoUnit, ':cr'=>$costoRiga, ':n'=>$note]);
            $id = (int) $db->lastInsertId();
        }
        $costo = progettoRicalcolaCosto($db, $progettoId);
        echo json_encode(['success' => true, 'id' => $id, 'costo_riga' => $costoRiga, 'costo_produzione' => $costo]);
        exit();
    }

    // — upsert in blocco di più righe BOM (usato da «Salva tutto») —
    // Stessa semantica di 'mat_save' riga per riga, ma in UNA transazione e con
    // UN solo ricalcolo del costo. Evita le N chiamate sequenziali del frontend.
    if ($mode === 'mat_save_batch') {
        $progettoId = (int) ($in['progetto_id'] ?? 0);
        if ($progettoId <= 0) { echo json_encode(['success' => false, 'error' => 'progetto mancante']); exit(); }
        $righe = $in['righe'] ?? null;
        if (!is_array($righe)) { echo json_encode(['success' => false, 'error' => 'righe mancanti']); exit(); }

        $upd = $db->prepare(
            "UPDATE progetto_materiali SET categoria=:c, voce=:v, qta=:q, unita=:u, costo_unitario=:cu, costo_riga=:cr, note=:n
             WHERE id=:id AND progetto_id=:pid"
        );
        $ins = $db->prepare(
            "INSERT INTO progetto_materiali (progetto_id, categoria, voce, qta, unita, costo_unitario, costo_riga, note)
             VALUES (:pid, :c, :v, :q, :u, :cu, :cr, :n)"
        );

        $ids = [];
        $db->beginTransaction();
        try {
            foreach ($righe as $riga) {
                if (!is_array($riga)) { continue; }
                $id        = (int) ($riga['id'] ?? 0);
                $categoria = in_array($riga['categoria'] ?? '', MAT_CATEGORIE, true) ? $riga['categoria'] : 'filamento';
                $voce      = trim((string) ($riga['voce'] ?? ''));
                $qta       = progettoParseNum($riga['qta'] ?? 0);
                $unita     = trim((string) ($riga['unita'] ?? 'pz')) ?: 'pz';
                $costoUnit = progettoParseNum($riga['costo_unitario'] ?? 0);
                $note      = trim((string) ($riga['note'] ?? ''));
                $costoRiga = round($qta * $costoUnit, 2);
                $par = [':c'=>$categoria, ':v'=>$voce, ':q'=>$qta, ':u'=>$unita, ':cu'=>$costoUnit, ':cr'=>$costoRiga, ':n'=>$note];
                if ($id > 0) {
                    $upd->execute($par + [':id'=>$id, ':pid'=>$progettoId]);
                } else {
                    $ins->execute($par + [':pid'=>$progettoId]);
                    $id = (int) $db->lastInsertId();
                }
                $ids[] = $id;
            }
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            echo json_encode(['success' => false, 'error' => 'salvataggio distinta fallito']);
            exit();
        }

        $costo = progettoRicalcolaCosto($db, $progettoId);
        echo json_encode(['success' => true, 'ids' => $ids, 'costo_produzione' => $costo]);
        exit();
    }

    // — elimina riga BOM —
    if ($mode === 'mat_delete') {
        $id = (int) ($in['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['success' => false, 'error' => 'id mancante']); exit(); }
        $pid = (int) $db->query("SELECT progetto_id FROM progetto_materiali WHERE id = " . $id)->fetchColumn();
        $db->prepare("DELETE FROM progetto_materiali WHERE id = ?")->execute([$id]);
        $costo = $pid > 0 ? progettoRicalcolaCosto($db, $pid) : null;
        echo json_encode(['success' => true, 'costo_produzione' => $costo]);
        exit();
    }

    // — upsert iterazione prototipo —
    if ($mode === 'iter_save') {
        $progettoId = (int) ($in['progetto_id'] ?? 0);
        if ($progettoId <= 0) { echo json_encode(['success' => false, 'error' => 'progetto mancante']); exit(); }
        $id    = (int) ($in['id'] ?? 0);
        $note  = trim((string) ($in['note'] ?? ''));
        $cad   = trim((string) ($in['cad_ref'] ?? ''));

        if ($id > 0) {
            $vnum = (int) ($in['v_num'] ?? 1);
            $db->prepare("UPDATE progetto_iterazioni SET v_num=:vn, note=:n, cad_ref=:c WHERE id=:id AND progetto_id=:pid")
               ->execute([':vn'=>$vnum, ':n'=>$note, ':c'=>$cad, ':id'=>$id, ':pid'=>$progettoId]);
        } else {
            // Numerazione automatica: prossima versione in coda.
            $vnum = (int) $db->query("SELECT COALESCE(MAX(v_num),0)+1 FROM progetto_iterazioni WHERE progetto_id = " . $progettoId)->fetchColumn();
            $db->prepare("INSERT INTO progetto_iterazioni (progetto_id, v_num, note, cad_ref, foto_urls) VALUES (:pid, :vn, :n, :c, '[]')")
               ->execute([':pid'=>$progettoId, ':vn'=>$vnum, ':n'=>$note, ':c'=>$cad]);
            $id = (int) $db->lastInsertId();
        }
        echo json_encode(['success' => true, 'id' => $id, 'v_num' => $vnum]);
        exit();
    }

    // — elimina iterazione —
    if ($mode === 'iter_delete') {
        $id = (int) ($in['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['success' => false, 'error' => 'id mancante']); exit(); }
        $db->prepare("DELETE FROM progetto_iterazioni WHERE id = ?")->execute([$id]);
        echo json_encode(['success' => true]);
        exit();
    }

    echo json_encode(['success' => false, 'error' => 'mode non valido']);

} catch (PDOException $e) {
    error_log('ARDY PROGETTI API ERROR: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Errore interno']);
}
