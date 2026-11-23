<?php
// -----------------------------------------------------------
// ARDY LAB — "Racconta": API dei pezzi e dei loro capitoli
// Un pezzo è un mobile raccontato da foto + voce, SENZA legame con un cliente
// (PIANO-RACCONTA.md). I capitoli sono righe di `fasi` con pezzo_id: stesso
// motore di clienti e progetti, terzo proprietario possibile.
//
//   GET  ?mode=lista                              → pezzi (non eliminati), con copertina e conteggi
//   GET  ?mode=dettaglio&id=N                     → pezzo + capitoli
//   GET  ?file=..&pezzo_id=N&fase_id=M            → serve una foto da disco
//   POST {mode:'crea', titolo?}                   → nuovo pezzo
//   POST {mode:'salva', id, titolo, scheda{}, storia, seo{}, stato}
//   POST {mode:'elimina', id}                     → cestino (soft delete); la pagina WP resta
//   POST {mode:'capitolo_salva', pezzo_id, id?, titolo, nota, testo, immagini[], pezzo?{titolo,scheda,storia}}
//   POST {mode:'capitolo_elimina', id}
//   POST {mode:'social_salva', pezzo_id, fase_id?, canale, stato, testo, media[], opzioni{}} → {success, avvisi[]}
//
// Capitolo: fase_nome = titolo, testo_breve = la nota dettata (materiale grezzo, mai
// pubblicato), testo_generato = il testo scritto da Sole e rivisto (quello che va sul sito).
// stato 'bozza' finché non va sul sito; wp_pubblicata_at NULL = modifiche da ripubblicare.
//
// Foto: solo disco, JPEG (i social e Google rifiutano WEBP). Il telefono le riduce già
// prima dell'invio; qui si ricomprime comunque, per chi carica da computer.
//
// Protetto via .htaccess (Basic Auth) — elencato nel <FilesMatch>.
// -----------------------------------------------------------

require_once __DIR__ . '/ardy-db.php';
require_once __DIR__ . '/ardy-net.php';       // ardyCompressImage + ardyHardenUploadDir
require_once __DIR__ . '/ardy-img.php';       // ardyImgNormalizza (WEBP/GIF → JPEG)
require_once __DIR__ . '/ardy-pezzi-lib.php';

header('Access-Control-Allow-Origin: https://ardyagent.ardy-lab.it');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }
// Difesa in profondità: se il Basic Auth a monte (.htaccess) non venisse
// applicato, questo guard rifiuta comunque le richieste non autenticate.
require_once __DIR__ . '/ardy-auth.php';
ardyRequireAuth();

function pezziFail(string $msg): void {
    echo json_encode(['success' => false, 'error' => $msg]);
    exit();
}

// GET con ?file= → foto di un capitolo (anteprime nella mini-app). basename() evita path traversal.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['file'])) {
    $pezzoId = (int) ($_GET['pezzo_id'] ?? 0);
    $faseId  = (int) ($_GET['fase_id'] ?? 0);
    $file    = basename((string) $_GET['file']);
    $path    = pezziFotoDir($pezzoId, $faseId) . $file;
    if ($pezzoId <= 0 || $faseId <= 0 || $file === '' || !is_file($path)) { http_response_code(404); exit(); }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
    if (!in_array($mime, ['image/jpeg', 'image/png'], true)) { http_response_code(403); exit(); }
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: private, max-age=86400');
    readfile($path);
    exit();
}

/** Capitoli di un pezzo, in ordine, con le foto decodificate. */
function pezziCapitoli(PDO $db, int $pezzoId): array {
    $st = $db->prepare(
        "SELECT id, fase_nome AS titolo, testo_breve AS nota, testo_generato AS testo, foto_urls, foto_wp_urls, stato,
                wp_pubblicata_at, created_at
           FROM fasi WHERE pezzo_id = ? ORDER BY ordine ASC, created_at ASC"
    );
    $st->execute([$pezzoId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['id']   = (int) $r['id'];
        $r['foto'] = json_decode($r['foto_urls'] ?? '[]', true) ?: [];
        // URL pubblici su WordPress (dopo la pubblicazione): sono quelli che possono
        // scaricare Facebook, Instagram e Google; le foto su disco stanno dietro login.
        $r['foto_wp'] = json_decode($r['foto_wp_urls'] ?? '[]', true) ?: [];
        unset($r['foto_urls'], $r['foto_wp_urls']);
    }
    return $rows;
}

try {
    $db = ardyDB();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $mode = $_GET['mode'] ?? 'lista';

        if ($mode === 'lista') {
            // Copertina = prima foto del primo capitolo; "da_pubblicare" = capitoli con
            // modifiche non ancora sul sito (bozze o ripubblicazioni pendenti).
            $rows = $db->query(
                "SELECT p.id, p.titolo, p.stato, p.wp_post_link, p.updated_at,
                        (SELECT COUNT(*) FROM fasi f WHERE f.pezzo_id = p.id) AS capitoli,
                        (SELECT COUNT(*) FROM fasi f WHERE f.pezzo_id = p.id AND f.wp_pubblicata_at IS NULL) AS da_pubblicare,
                        (SELECT CONCAT(f.id, '|', f.foto_urls) FROM fasi f
                          WHERE f.pezzo_id = p.id AND f.foto_urls <> '[]'
                          ORDER BY f.ordine ASC, f.created_at ASC LIMIT 1) AS cop
                   FROM pezzi p
                  WHERE p.deleted_at IS NULL
               ORDER BY p.updated_at DESC
                  LIMIT 200"
            )->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                $r['id'] = (int) $r['id'];
                $r['capitoli'] = (int) $r['capitoli'];
                $r['da_pubblicare'] = (int) $r['da_pubblicare'];
                $r['copertina'] = null;
                if (!empty($r['cop'])) {
                    [$fid, $json] = explode('|', $r['cop'], 2);
                    $foto = json_decode($json, true) ?: [];
                    if ($foto) $r['copertina'] = ['fase_id' => (int) $fid, 'file' => (string) $foto[0]];
                }
                unset($r['cop']);
            }
            echo json_encode(['success' => true, 'pezzi' => $rows]);
            exit();
        }

        if ($mode === 'dettaglio') {
            $id = (int) ($_GET['id'] ?? 0);
            $st = $db->prepare("SELECT id, titolo, scheda, storia, seo, stato, wp_post_id, wp_post_link, created_by, created_at
                                  FROM pezzi WHERE id = ? AND deleted_at IS NULL");
            $st->execute([$id]);
            $p = $st->fetch(PDO::FETCH_ASSOC);
            if (!$p) pezziFail('Pezzo non trovato');
            $p['id']       = (int) $p['id'];
            $p['scheda']   = pezziSchedaNorm($p['scheda']);
            $p['seo']      = pezziSeoNorm($p['seo']);
            $p['capitoli'] = pezziCapitoli($db, $id);
            $p['social']   = pezziSocialLista($db, $id);
            echo json_encode(['success' => true, 'pezzo' => $p, 'campi' => PEZZI_SCHEDA_CAMPI, 'link_interni' => PEZZI_LINK_INTERNI],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit();
        }

        pezziFail('mode non valido');
    }

    $in   = json_decode(file_get_contents('php://input'), true) ?: [];
    $mode = $in['mode'] ?? '';

    // Aggiorna i campi del pezzo presenti in $dati (titolo/scheda/storia/stato).
    $salvaPezzo = function (int $id, array $dati) use ($db): void {
        $set = []; $par = [':id' => $id];
        if (array_key_exists('titolo', $dati)) { $set[] = 'titolo = :t';  $par[':t'] = mb_substr(trim((string) $dati['titolo']), 0, 200); }
        if (array_key_exists('scheda', $dati)) { $set[] = 'scheda = :s';  $par[':s'] = json_encode(pezziSchedaNorm($dati['scheda']), JSON_UNESCAPED_UNICODE); }
        if (array_key_exists('storia', $dati)) { $set[] = 'storia = :st'; $par[':st'] = mb_substr(trim((string) $dati['storia']), 0, 8000); }
        if (array_key_exists('seo', $dati))    { $set[] = 'seo = :seo';   $par[':seo'] = json_encode(pezziSeoNorm($dati['seo']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
        if (array_key_exists('stato', $dati))  { $set[] = 'stato = :ss';  $par[':ss'] = ($dati['stato'] === 'finito') ? 'finito' : 'in_corso'; }
        if (!$set) return;
        $db->prepare("UPDATE pezzi SET " . implode(', ', $set) . " WHERE id = :id AND deleted_at IS NULL")->execute($par);
    };

    if ($mode === 'crea') {
        $titolo = mb_substr(trim((string) ($in['titolo'] ?? '')), 0, 200) ?: 'Nuovo pezzo';
        $db->prepare("INSERT INTO pezzi (titolo, scheda, created_by) VALUES (?, ?, ?)")
           ->execute([$titolo, json_encode(pezziSchedaNorm([])), ardyAuthUser() ?: null]);
        echo json_encode(['success' => true, 'id' => (int) $db->lastInsertId()]);
        exit();
    }

    if ($mode === 'salva') {
        $id = (int) ($in['id'] ?? 0);
        if ($id <= 0) pezziFail('id mancante');
        $salvaPezzo($id, array_intersect_key($in, array_flip(['titolo', 'scheda', 'storia', 'seo', 'stato'])));
        echo json_encode(['success' => true]);
        exit();
    }

    if ($mode === 'elimina') {
        // Soft delete come per progetti e clienti. La pagina WordPress NON si tocca:
        // sparisce dalla mini-app, e Sole smette di riconoscerla (deleted_at filtrato).
        $id = (int) ($in['id'] ?? 0);
        $db->prepare("UPDATE pezzi SET deleted_at = NOW() WHERE id = ?")->execute([$id]);
        echo json_encode(['success' => true]);
        exit();
    }

    if ($mode === 'capitolo_salva') {
        $pezzoId = (int) ($in['pezzo_id'] ?? 0);
        $id      = (int) ($in['id'] ?? 0);
        $titolo  = mb_substr(trim((string) ($in['titolo'] ?? '')), 0, 200);
        $nota    = mb_substr(trim((string) ($in['nota'] ?? '')), 0, 8000);
        $testo   = mb_substr(trim((string) ($in['testo'] ?? '')), 0, 8000);
        $immagini = is_array($in['immagini'] ?? null) ? array_values($in['immagini']) : [];
        if ($pezzoId <= 0) pezziFail('pezzo mancante');
        $chk = $db->prepare("SELECT id FROM pezzi WHERE id = ? AND deleted_at IS NULL");
        $chk->execute([$pezzoId]);
        if ($chk->fetchColumn() === false) pezziFail('Pezzo non trovato');
        if ($titolo === '' && $nota === '' && $testo === '' && !$immagini) pezziFail('Il capitolo è vuoto');

        if ($id > 0) {
            $chk = $db->prepare("SELECT id FROM fasi WHERE id = ? AND pezzo_id = ?");
            $chk->execute([$id, $pezzoId]);
            if ($chk->fetchColumn() === false) pezziFail('Capitolo non trovato');
        } else {
            $stOrd = $db->prepare("SELECT COALESCE(MAX(ordine),0) FROM fasi WHERE pezzo_id = ?");
            $stOrd->execute([$pezzoId]);
            $db->prepare(
                "INSERT INTO fasi (pezzo_id, fase_nome, fase_tipo, testo_breve, testo_generato, foto_urls, video_urls, stato, ordine)
                 VALUES (?, '', 'fase', '', '', '[]', '[]', 'bozza', ?)"
            )->execute([$pezzoId, (int) $stOrd->fetchColumn() + 1]);
            $id = (int) $db->lastInsertId();
        }

        // 'immagini' è la lista DEFINITIVA: nomi già salvati (tenuti) + base64 (nuove).
        $tenuti = []; $nuovi = [];
        foreach ($immagini as $item) {
            if (!is_string($item) || $item === '') continue;
            if (preg_match('/^[A-Za-z0-9_.\-]+\.(?:jpe?g|png)$/i', $item)) $tenuti[] = basename($item);
            else $nuovi[] = $item;
        }

        $dir = pezziFotoDir($pezzoId, $id);
        foreach (glob($dir . '*') ?: [] as $old) {
            if (is_file($old) && !in_array(basename($old), $tenuti, true)) @unlink($old);
        }

        $fotoFiles = $tenuti;
        foreach (array_slice($nuovi, 0, 12) as $idx => $b64) {
            if (count($fotoFiles) >= 12) break;   // tetto per capitolo, come le fasi cliente
            $raw = base64_decode($b64, true);
            if ($raw === false || strlen($raw) < 12 || strlen($raw) > 12 * 1024 * 1024) continue;
            $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($raw);
            if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) continue;
            $raw  = ardyCompressImage($raw, $mime);
            $norm = ardyImgNormalizza($raw, $mime);
            if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) pezziFail('Cartella foto non creabile');
            ardyHardenUploadDir(rtrim(ARDY_UPLOAD_DIR, '/'));
            $fname = 'f' . $idx . '_' . uniqid() . '.' . $norm['ext'];
            if (file_put_contents($dir . $fname, $norm['bytes']) !== false) $fotoFiles[] = $fname;
        }

        // wp_pubblicata_at = NULL: se il capitolo era già sul sito, ora ha modifiche da
        // ripubblicare (la ripubblicazione sostituisce il blocco, non lo duplica).
        $db->prepare(
            "UPDATE fasi SET fase_nome = :t, testo_breve = :n, testo_generato = :g, foto_urls = :f, wp_pubblicata_at = NULL
              WHERE id = :id AND pezzo_id = :pid"
        )->execute([':t' => $titolo, ':n' => $nota, ':g' => $testo, ':f' => json_encode($fotoFiles),
                    ':id' => $id, ':pid' => $pezzoId]);

        // Aggiornamenti alla scheda proposti da Sole e accettati: viaggiano col capitolo,
        // così un solo "Salva" non lascia mai capitolo e scheda disallineati.
        if (is_array($in['pezzo'] ?? null)) {
            $salvaPezzo($pezzoId, array_intersect_key($in['pezzo'], array_flip(['titolo', 'scheda', 'storia'])));
        }
        $db->prepare("UPDATE pezzi SET updated_at = NOW() WHERE id = ?")->execute([$pezzoId]);

        echo json_encode(['success' => true, 'id' => $id, 'foto' => $fotoFiles]);
        exit();
    }

    // Social: salva la bozza ("salva per dopo") o registra l'esito di una pubblicazione.
    // La pubblicazione vera la fanno gli endpoint di sempre (ardy-pubblica-social.php,
    // ardy-gbp-post.php, ardy-pubblica-reel.php): qui si tiene lo storico, e prima di
    // pubblicare si passa sempre da qui con stato 'bozza', così il testo è al sicuro e
    // torna il controllo anonimato.
    if ($mode === 'social_salva') {
        $pezzoId = (int) ($in['pezzo_id'] ?? 0);
        $faseId  = (int) ($in['fase_id'] ?? 0) ?: null;
        $canale  = (string) ($in['canale'] ?? '');
        $stato   = (string) ($in['stato'] ?? 'bozza');
        if (!in_array($canale, ['facebook', 'google', 'reel'], true)) pezziFail('canale non valido');
        if (!in_array($stato, ['bozza', 'pubblicato', 'da_verificare'], true)) pezziFail('stato non valido');
        $chk = $db->prepare("SELECT id FROM pezzi WHERE id = ? AND deleted_at IS NULL");
        $chk->execute([$pezzoId]);
        if ($chk->fetchColumn() === false) pezziFail('Pezzo non trovato');
        $testo = mb_substr(trim((string) ($in['testo'] ?? '')), 0, 3000);
        $media = is_array($in['media'] ?? null) ? array_values(array_filter($in['media'], fn($u) => is_string($u) && preg_match('#^https://#', $u))) : [];
        $opz   = is_array($in['opzioni'] ?? null) ? array_intersect_key($in['opzioni'], ['instagram' => 1, 'musica' => 1, 'frase' => 1]) : [];

        // Una voce per (capitolo, canale): si aggiorna finché non è uscita; dopo una
        // pubblicazione la volta successiva ne nasce una nuova (lo storico resta).
        $sel = $db->prepare("SELECT id FROM pezzi_social WHERE pezzo_id = ? AND " . ($faseId ? "fase_id = ?" : "fase_id IS NULL")
            . " AND canale = ? AND stato = 'bozza' ORDER BY id DESC LIMIT 1");
        $sel->execute($faseId ? [$pezzoId, $faseId, $canale] : [$pezzoId, $canale]);
        $sid = (int) ($sel->fetchColumn() ?: 0);
        $pubAt = $stato === 'bozza' ? null : date('Y-m-d H:i:s');
        if ($sid > 0) {
            $db->prepare("UPDATE pezzi_social SET stato = ?, testo = ?, media = ?, opzioni = ?, pubblicato_at = ? WHERE id = ?")
               ->execute([$stato, $testo, json_encode($media, JSON_UNESCAPED_SLASHES), json_encode((object) $opz), $pubAt, $sid]);
        } else {
            $db->prepare("INSERT INTO pezzi_social (pezzo_id, fase_id, canale, stato, testo, media, opzioni, pubblicato_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
               ->execute([$pezzoId, $faseId, $canale, $stato, $testo, json_encode($media, JSON_UNESCAPED_SLASHES), json_encode((object) $opz), $pubAt]);
        }
        echo json_encode(['success' => true, 'avvisi' => pezziAvvisiPrivacy($testo)]);
        exit();
    }

    if ($mode === 'capitolo_elimina') {
        $id = (int) ($in['id'] ?? 0);
        $st = $db->prepare("SELECT pezzo_id FROM fasi WHERE id = ? AND pezzo_id IS NOT NULL");
        $st->execute([$id]);
        $pid = (int) ($st->fetchColumn() ?: 0);
        if ($pid <= 0) pezziFail('Capitolo non trovato');
        $db->prepare("DELETE FROM fasi WHERE id = ? AND pezzo_id = ?")->execute([$id, $pid]);
        $dir = pezziFotoDir($pid, $id);
        foreach (glob($dir . '*') ?: [] as $f) { if (is_file($f)) @unlink($f); }
        @rmdir($dir);
        // Sole smette subito di conoscerlo (lo tolgo dall'istantanea pubblica); il blocco
        // sulla pagina WordPress sparisce alla prossima pubblicazione del pezzo, che
        // ripulisce i blocchi dei capitoli non più esistenti (ardy-pubblica-pezzo.php).
        $sj = $db->prepare("SELECT pubblico_json FROM pezzi WHERE id = ?");
        $sj->execute([$pid]);
        $pub = json_decode((string) $sj->fetchColumn(), true);
        if (is_array($pub) && !empty($pub['capitoli'])) {
            $pub['capitoli'] = array_values(array_filter($pub['capitoli'], fn($c) => (int) ($c['id'] ?? 0) !== $id));
            $db->prepare("UPDATE pezzi SET pubblico_json = ? WHERE id = ?")
               ->execute([json_encode($pub, JSON_UNESCAPED_UNICODE), $pid]);
        }
        echo json_encode(['success' => true]);
        exit();
    }

    pezziFail('mode non valido');

} catch (PDOException $e) {
    error_log('ARDY PEZZI API ERROR: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Errore interno']);
}
