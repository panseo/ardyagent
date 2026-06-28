<?php
// -----------------------------------------------------------
// ARDY LAB — Fasi in bozza generate dai template di libreria
// Il box "Note" del sopralluogo permette di scegliere uno o più
// template dalla libreria fasi: questo endpoint le trasforma in
// righe "bozza" nella tabella fasi (non pubblicate), che Michela
// modifica e pubblica una per una dal pannello Lavorazione.
//
//   GET  ?session_id=...         → lista bozze del cliente (in ordine)
//   GET  ?session_id=...&id=..&file=..  → serve una foto di bozza (anteprima/ricarico nel form)
//   POST {mode:'genera', ...}        → crea N bozze dai template di libreria scelti
//   POST {mode:'genera_custom', ...} → crea N bozze da voci libere (es. lette da un
//                                       preventivo PDF esterno con ardy-estrai-preventivo-pdf.php)
//   POST {mode:'salva', ...}     → upsert di UNA bozza completa (nome, note, prezzo, FOTO, video):
//                                  "salva sul momento, pubblica la sera". Le foto sono persistite
//                                  su disco (ARDY_UPLOAD_DIR/<session>/fasi-bozza/<id>/); al publish
//                                  vengono ricaricate nel form e ardy-pubblica-lavorazione le porta su WP.
//   POST {mode:'delete', id}     → elimina una bozza (solo se stato='bozza')
// -----------------------------------------------------------

require_once __DIR__ . '/ardy-db.php';
require_once __DIR__ . '/ardy-net.php';       // ardyCompressImage + ardyHardenUploadDir per le foto di bozza
require_once __DIR__ . '/ardy-storage.php';   // B2 (S3) con fallback su disco

header('Access-Control-Allow-Origin: https://ardyagent.ardy-lab.it');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

// Cartella delle foto di UNA bozza (per cliente + id fase).
function ardyBozzaFotoDir(string $sessionId, int $id): string {
    $clean = preg_replace('/[^a-zA-Z0-9_\-]/', '', $sessionId);
    return rtrim(ARDY_UPLOAD_DIR, '/') . '/' . $clean . '/fasi-bozza/' . $id . '/';
}
// Object key su B2 (stesso layout della cartella, sotto prefisso clienti/).
function ardyBozzaB2Key(string $sessionId, int $id, string $file): string {
    $clean = preg_replace('/[^a-zA-Z0-9_\-]/', '', $sessionId);
    return 'clienti/' . $clean . '/fasi-bozza/' . $id . '/' . basename($file);
}

// GET con ?file= → serve una foto di bozza da disco (anteprima + ricarico nel form).
// Dietro Basic Auth (.htaccess). basename() evita path traversal.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['file'])) {
    $session = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_GET['session_id'] ?? '');
    $id      = (int) ($_GET['id'] ?? 0);
    $file    = basename((string) $_GET['file']);
    if ($session === '' || $id <= 0 || $file === '') { http_response_code(400); exit(); }
    $path = ardyBozzaFotoDir($session, $id) . $file;
    if (is_file($path)) {
        $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
        if (!in_array($mime, $allowed, true)) { http_response_code(403); exit(); }
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: private, max-age=3600');
        readfile($path);
        exit();
    }
    // Non su disco → la foto vive su B2. La servo come PROXY same-origin (non un
    // redirect): così funziona sia <img> sia il fetch()→blob() della dash (niente CORS).
    if (ardyB2Configured()) {
        $bytes = ardyStorageGet(ardyBozzaB2Key($session, $id, $file));
        if ($bytes !== null) {
            $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
            if (!in_array($mime, $allowed, true)) { http_response_code(403); exit(); }
            header('Content-Type: ' . $mime);
            header('Content-Length: ' . strlen($bytes));
            header('Cache-Control: private, max-age=3600');
            echo $bytes;
            exit();
        }
    }
    http_response_code(404);
    exit();
}

// Inserisce N bozze (nome + testo_breve) per un cliente, in coda all'ordine
// esistente. Condiviso dai modi 'genera' (da libreria) e 'genera_custom'
// (da voci libere, es. estratte da un PDF).
/** Accetta sia un numero (già float, dall'estrazione AI) sia una stringa "1.450,00" / "350,00". */
function ardyParseImportoFase($v): ?float {
    if (is_numeric($v)) return (float) $v;
    if (!is_string($v) || trim($v) === '') return null;
    $s = preg_replace('/[^\d,.\-]/', '', trim($v));
    if ($s === '') return null;
    if (strpos($s, ',') !== false && strpos($s, '.') !== false) {
        if (strrpos($s, ',') > strrpos($s, '.')) { $s = str_replace('.', '', $s); $s = str_replace(',', '.', $s); }
        else { $s = str_replace(',', '', $s); }
    } elseif (strpos($s, ',') !== false) {
        $s = str_replace(',', '.', $s);
    }
    return is_numeric($s) ? (float) $s : null;
}

function ardyInserisciBozzeFasi(PDO $db, string $sessionId, array $items): array {
    $stOrd = $db->prepare("SELECT COALESCE(MAX(ordine),0) FROM fasi WHERE session_id = ?");
    $stOrd->execute([$sessionId]);
    $ordine = (int) $stOrd->fetchColumn();

    $ins = $db->prepare(
        "INSERT INTO fasi (session_id, fase_nome, fase_tipo, testo_breve, testo_generato, foto_urls, video_urls, stato, ordine, prezzo)
         VALUES (:sid, :nome, 'fase', :breve, '', '[]', '[]', 'bozza', :ordine, :prezzo)"
    );

    $bozze = [];
    foreach ($items as $it) {
        $nome   = trim((string) ($it['nome']  ?? ''));
        $breve  = trim((string) ($it['breve'] ?? ''));
        $prezzo = ardyParseImportoFase($it['prezzo'] ?? null);
        if ($nome === '') continue;
        $ordine++;
        $ins->execute([':sid' => $sessionId, ':nome' => $nome, ':breve' => $breve, ':ordine' => $ordine, ':prezzo' => $prezzo]);
        $bozze[] = ['id' => (int) $db->lastInsertId(), 'fase_nome' => $nome, 'testo_breve' => $breve, 'ordine' => $ordine, 'prezzo' => $prezzo];
    }
    return $bozze;
}

try {
    $db = ardyDB();
    ardyEnsureFasiStatoOrdine($db);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $sessionId = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_GET['session_id'] ?? '');
        if ($sessionId === '') { echo json_encode([]); exit(); }
        $stmt = $db->prepare(
            "SELECT id, fase_nome, testo_breve, ordine, prezzo, foto_urls, video_urls, created_at FROM fasi
             WHERE session_id = ? AND stato = 'bozza' ORDER BY ordine ASC, created_at ASC"
        );
        $stmt->execute([$sessionId]);
        echo json_encode($stmt->fetchAll());
        exit();
    }

    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $mode  = $input['mode'] ?? '';

    if ($mode === 'genera') {
        $sessionId   = preg_replace('/[^a-zA-Z0-9_\-]/', '', $input['session_id'] ?? '');
        $templateIds = is_array($input['template_ids'] ?? null) ? array_values($input['template_ids']) : [];
        if ($sessionId === '' || !$templateIds) {
            echo json_encode(['success' => false, 'error' => 'Dati mancanti']);
            exit();
        }

        $placeholders = implode(',', array_fill(0, count($templateIds), '?'));
        $stmt = $db->prepare("SELECT id, nome, descr FROM libreria_fasi WHERE id IN ($placeholders)");
        $stmt->execute($templateIds);
        $byId = [];
        foreach ($stmt->fetchAll() as $r) { $byId[$r['id']] = $r; }

        // Mantiene l'ordine di selezione scelto dall'operatore, ignorando i template non trovati.
        $items = [];
        foreach ($templateIds as $tid) {
            if (!isset($byId[$tid])) continue;
            $items[] = ['nome' => $byId[$tid]['nome'], 'breve' => $byId[$tid]['descr'] ?? ''];
        }

        echo json_encode(['success' => true, 'bozze' => ardyInserisciBozzeFasi($db, $sessionId, $items)]);
        exit();
    }

    if ($mode === 'genera_custom') {
        // Bozze da voci libere (es. lette da un preventivo PDF esterno con
        // ardy-estrai-preventivo-pdf.php): niente libreria, il testo arriva già pronto.
        $sessionId = preg_replace('/[^a-zA-Z0-9_\-]/', '', $input['session_id'] ?? '');
        $fasiInput = is_array($input['fasi'] ?? null) ? array_values($input['fasi']) : [];
        if ($sessionId === '' || !$fasiInput) {
            echo json_encode(['success' => false, 'error' => 'Dati mancanti']);
            exit();
        }

        $items = [];
        foreach ($fasiInput as $f) {
            if (!is_array($f)) continue;
            $items[] = [
                'nome'   => $f['nome'] ?? '',
                'breve'  => $f['descrizione'] ?? ($f['testo_breve'] ?? ''),
                'prezzo' => $f['prezzo'] ?? null,
            ];
        }

        echo json_encode(['success' => true, 'bozze' => ardyInserisciBozzeFasi($db, $sessionId, $items)]);
        exit();
    }

    if ($mode === 'salva') {
        // Upsert di UNA bozza completa: "salva sul momento, pubblica la sera".
        $sessionId = preg_replace('/[^a-zA-Z0-9_\-]/', '', $input['session_id'] ?? '');
        if ($sessionId === '') { echo json_encode(['success' => false, 'error' => 'session mancante']); exit(); }
        $faseNome  = trim((string) ($input['fase_nome']   ?? ''));
        $breve     = trim((string) ($input['testo_breve'] ?? ''));
        $prezzo    = ardyParseImportoFase($input['prezzo'] ?? null);
        $id        = (int) ($input['id'] ?? 0);
        $immagini  = is_array($input['immagini']   ?? null) ? array_values($input['immagini'])   : [];
        $videoUrls = is_array($input['video_urls'] ?? null) ? array_values($input['video_urls']) : [];
        if ($faseNome === '' && $breve === '') {
            echo json_encode(['success' => false, 'error' => 'Servono almeno il nome fase o due righe di note']);
            exit();
        }

        // Riga bozza: aggiorna se già esiste (e ancora bozza), altrimenti creane una in coda.
        if ($id > 0) {
            $chk = $db->prepare("SELECT stato FROM fasi WHERE id = ? AND session_id = ?");
            $chk->execute([$id, $sessionId]);
            $st = $chk->fetchColumn();
            if ($st === false)     { echo json_encode(['success' => false, 'error' => 'bozza non trovata']); exit(); }
            if ($st !== 'bozza')   { echo json_encode(['success' => false, 'error' => 'fase già pubblicata']); exit(); }
        } else {
            $stOrd = $db->prepare("SELECT COALESCE(MAX(ordine),0) FROM fasi WHERE session_id = ?");
            $stOrd->execute([$sessionId]);
            $ordine = (int) $stOrd->fetchColumn() + 1;
            $db->prepare(
                "INSERT INTO fasi (session_id, fase_nome, fase_tipo, testo_breve, testo_generato, foto_urls, video_urls, stato, ordine, prezzo)
                 VALUES (:sid, :nome, 'fase', :breve, '', '[]', '[]', 'bozza', :ordine, :prezzo)"
            )->execute([':sid' => $sessionId, ':nome' => $faseNome, ':breve' => $breve, ':ordine' => $ordine, ':prezzo' => $prezzo]);
            $id = (int) $db->lastInsertId();
        }

        // Foto: la lista inviata è quella DEFINITIVA → riscriviamo da zero (aggiunte e
        // rimozioni gestite in modo uniforme). Le nuove vanno su B2 se configurato.
        $dir = ardyBozzaFotoDir($sessionId, $id);
        $ensureDir = function () use ($dir): bool {
            if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) return false;
            ardyHardenUploadDir(rtrim(ARDY_UPLOAD_DIR, '/'));   // niente esecuzione script tra gli upload
            return true;
        };
        // Ripulisci le foto precedenti dallo store dove vivono (disco E/O B2):
        // su disco col glob; su B2 dai nomi salvati nel DB (il glob non le vede).
        $prevStmt = $db->prepare("SELECT foto_urls FROM fasi WHERE id = ? AND session_id = ?");
        $prevStmt->execute([$id, $sessionId]);
        $prevFiles = json_decode((string) $prevStmt->fetchColumn() ?: '[]', true) ?: [];
        foreach (glob($dir . '*') ?: [] as $f) { if (is_file($f)) @unlink($f); }
        if (ardyB2Configured()) {
            foreach ($prevFiles as $fn) { ardyStorageDelete(ardyBozzaB2Key($sessionId, $id, basename((string) $fn))); }
        }

        $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $maxByte = 12 * 1024 * 1024;
        $fotoFiles = [];
        foreach (array_slice($immagini, 0, 6) as $idx => $b64) {   // max 6 foto in bozza
            if (!is_string($b64) || $b64 === '') continue;
            $raw = base64_decode($b64, true);
            if ($raw === false || strlen($raw) < 12 || strlen($raw) > $maxByte) continue;
            $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($raw);
            if (!in_array($mime, $allowed, true)) continue;
            $raw = ardyCompressImage($raw, $mime);
            $ext = $mime === 'image/png' ? 'png' : ($mime === 'image/webp' ? 'webp' : ($mime === 'image/gif' ? 'gif' : 'jpg'));
            $fname = 'f' . $idx . '_' . uniqid() . '.' . $ext;
            // Nuova foto su B2 se configurato; fallback su disco se B2 assente o fallisce.
            $saved = ardyB2Configured() && ardyStoragePut(ardyBozzaB2Key($sessionId, $id, $fname), $raw, $mime);
            if (!$saved && $ensureDir() && file_put_contents($dir . $fname, $raw) !== false) $saved = true;
            if ($saved) $fotoFiles[] = $fname;
        }

        // Video: già caricati su WP a monte (sono URL) → li conserviamo come sono.
        $videoClean = [];
        foreach ($videoUrls as $u) {
            $u = trim((string) $u);
            if ($u !== '' && filter_var($u, FILTER_VALIDATE_URL)) $videoClean[] = $u;
        }

        $db->prepare(
            "UPDATE fasi SET fase_nome = :nome, testo_breve = :breve, prezzo = :prezzo,
                foto_urls = :foto, video_urls = :video, stato = 'bozza'
             WHERE id = :id AND session_id = :sid"
        )->execute([
            ':nome'  => $faseNome,
            ':breve' => $breve,
            ':prezzo' => $prezzo,
            ':foto'  => json_encode($fotoFiles),
            ':video' => json_encode($videoClean),
            ':id'    => $id,
            ':sid'   => $sessionId,
        ]);

        echo json_encode(['success' => true, 'id' => $id, 'foto' => $fotoFiles, 'video_urls' => $videoClean]);
        exit();
    }

    if ($mode === 'delete') {
        $id = (int) ($input['id'] ?? 0);
        if (!$id) {
            echo json_encode(['success' => false, 'error' => 'id mancante']);
            exit();
        }
        // Recupera sessione e foto PRIMA di eliminare, per ripulire disco e/o B2.
        $sg = $db->prepare("SELECT session_id, foto_urls FROM fasi WHERE id = ? AND stato = 'bozza'");
        $sg->execute([$id]);
        $brow = $sg->fetch();
        $sid  = (string) ($brow['session_id'] ?? '');
        $db->prepare("DELETE FROM fasi WHERE id = ? AND stato = 'bozza'")->execute([$id]);
        if ($sid !== '') {
            if (ardyB2Configured()) {
                foreach (json_decode($brow['foto_urls'] ?? '[]', true) ?: [] as $fn) {
                    ardyStorageDelete(ardyBozzaB2Key($sid, $id, basename((string) $fn)));
                }
            }
            $dir = ardyBozzaFotoDir($sid, $id);
            if (is_dir($dir)) {
                foreach (glob($dir . '*') as $f) { if (is_file($f)) @unlink($f); }
                @rmdir($dir);
            }
        }
        echo json_encode(['success' => true]);
        exit();
    }

    echo json_encode(['success' => false, 'error' => 'mode non valido']);

} catch (PDOException $e) {
    error_log('ARDY FASI BOZZA API ERROR: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Errore interno']);
}
