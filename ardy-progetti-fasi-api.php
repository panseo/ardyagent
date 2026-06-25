<?php
// -----------------------------------------------------------
// ARDY LAB — Dash Design: fasi-contenuto di un PROGETTO (binario racconto)
// Gemello di ardy-fasi-bozza-api.php, ma le fasi sono agganciate al progetto
// (progetto_id) invece che al cliente (session_id). Stessa tabella `fasi`, stessi
// helper immagine. È la base che i motori reel/social consumeranno (slice futura).
// Vedi PIANO-DASH-DESIGN.md §2.4 (binario racconto).
//
//   GET ?progetto_id=N                          → lista fasi del progetto (in ordine)
//   GET ?progetto_id=N&fase_id=..&file=..        → serve una foto da disco
//   POST {mode:'salva', progetto_id, id, fase_nome, testo_breve, immagini[], video_urls[]}
//                                                → upsert di UNA fase completa (foto su disco)
//   POST {mode:'delete', id}                     → elimina una fase (+ foto)
//   POST {mode:'riordina', progetto_id, ordine:[id,…]} → riordina le fasi
//
// Protetto via .htaccess (Basic Auth) — elencato nel <FilesMatch>.
// -----------------------------------------------------------

require_once __DIR__ . '/ardy-db.php';
require_once __DIR__ . '/ardy-net.php';   // ardyCompressImage + ardyHardenUploadDir

header('Access-Control-Allow-Origin: https://ardyagent.ardy-lab.it');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

// Cartella foto di UNA fase di progetto.
// NB migrazione Backblaze B2: il DB salva solo i NOMI file in foto_urls (non path
// assoluti) e le foto si servono via questo endpoint (?file=). Quando lo storage
// passerà a B2, cambiano solo il path di scrittura qui e il ramo di serving ?file=;
// il modello dati e la dashboard restano identici. Stesso seam del resto dell'app.
function progettoFaseFotoDir(int $progettoId, int $faseId): string {
    return rtrim(ARDY_UPLOAD_DIR, '/') . '/progetti/' . $progettoId . '/fasi/' . $faseId . '/';
}

// GET con ?file= → serve una foto da disco (anteprima + ricarico nel form).
// Dietro Basic Auth (.htaccess). basename() evita path traversal.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['file'])) {
    $progettoId = (int) ($_GET['progetto_id'] ?? 0);
    $faseId     = (int) ($_GET['fase_id'] ?? 0);
    $file       = basename((string) $_GET['file']);
    if ($progettoId <= 0 || $faseId <= 0 || $file === '') { http_response_code(400); exit(); }
    $path = progettoFaseFotoDir($progettoId, $faseId) . $file;
    if (!is_file($path)) { http_response_code(404); exit(); }
    $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
    if (!in_array($mime, $allowed, true)) { http_response_code(403); exit(); }
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: private, max-age=3600');
    readfile($path);
    exit();
}

try {
    $db = ardyDB();

    // ── GET: lista fasi del progetto ─────────────────────────
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $progettoId = (int) ($_GET['progetto_id'] ?? 0);
        if ($progettoId <= 0) { echo json_encode([]); exit(); }
        $stmt = $db->prepare(
            "SELECT id, fase_nome, testo_breve, ordine, foto_urls, video_urls, stato, created_at
             FROM fasi WHERE progetto_id = ? ORDER BY ordine ASC, created_at ASC"
        );
        $stmt->execute([$progettoId]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            $r['foto_urls']  = json_decode($r['foto_urls']  ?? '[]', true) ?? [];
            $r['video_urls'] = json_decode($r['video_urls'] ?? '[]', true) ?? [];
        }
        echo json_encode($rows);
        exit();
    }

    // ── POST ─────────────────────────────────────────────────
    $in   = json_decode(file_get_contents('php://input'), true) ?: [];
    $mode = $in['mode'] ?? '';

    if ($mode === 'salva') {
        $progettoId = (int) ($in['progetto_id'] ?? 0);
        if ($progettoId <= 0) { echo json_encode(['success' => false, 'error' => 'progetto mancante']); exit(); }
        $faseNome  = trim((string) ($in['fase_nome']   ?? ''));
        $breve     = trim((string) ($in['testo_breve'] ?? ''));
        $generato  = trim((string) ($in['testo_generato'] ?? ''));   // caption social (FB/IG)
        $id        = (int) ($in['id'] ?? 0);
        $immagini  = is_array($in['immagini']   ?? null) ? array_values($in['immagini'])   : [];
        $videoUrls = is_array($in['video_urls'] ?? null) ? array_values($in['video_urls']) : [];
        if ($faseNome === '' && $breve === '') {
            echo json_encode(['success' => false, 'error' => 'Servono almeno il nome fase o due righe di note']);
            exit();
        }

        // Riga fase: aggiorna se esiste (ed è del progetto), altrimenti creane una in coda.
        if ($id > 0) {
            $chk = $db->prepare("SELECT id FROM fasi WHERE id = ? AND progetto_id = ?");
            $chk->execute([$id, $progettoId]);
            if ($chk->fetchColumn() === false) { echo json_encode(['success' => false, 'error' => 'fase non trovata']); exit(); }
        } else {
            $stOrd = $db->prepare("SELECT COALESCE(MAX(ordine),0) FROM fasi WHERE progetto_id = ?");
            $stOrd->execute([$progettoId]);
            $ordine = (int) $stOrd->fetchColumn() + 1;
            $db->prepare(
                "INSERT INTO fasi (progetto_id, fase_nome, fase_tipo, testo_breve, testo_generato, foto_urls, video_urls, stato, ordine)
                 VALUES (:pid, :nome, 'fase', :breve, '', '[]', '[]', 'pubblicata', :ordine)"
            )->execute([':pid' => $progettoId, ':nome' => $faseNome, ':breve' => $breve, ':ordine' => $ordine]);
            $id = (int) $db->lastInsertId();
        }

        // Foto su disco: la lista inviata è quella DEFINITIVA → riscriviamo da zero.
        $dir = progettoFaseFotoDir($progettoId, $id);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            echo json_encode(['success' => false, 'error' => 'cartella non creabile']);
            exit();
        }
        ardyHardenUploadDir(rtrim(ARDY_UPLOAD_DIR, '/'));
        // Conserva i file già esistenti citati per nome; rimuovi quelli non più referenziati;
        // aggiungi i nuovi (base64). Gli "immagini" sono o nomi-file esistenti o data base64.
        $tenuti = [];
        $nuovi  = [];
        foreach ($immagini as $item) {
            if (!is_string($item) || $item === '') continue;
            // Già un nome file salvato (no base64) → da conservare.
            if (preg_match('/^[A-Za-z0-9_.\-]+\.(?:jpe?g|png|gif|webp)$/i', $item)) { $tenuti[] = basename($item); }
            else { $nuovi[] = $item; }
        }
        // Rimuovi i file su disco non più tenuti (saranno ri-aggiunti solo i 'tenuti').
        foreach (glob($dir . '*') as $f) {
            if (is_file($f) && !in_array(basename($f), $tenuti, true)) @unlink($f);
        }

        $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $maxByte = 12 * 1024 * 1024;
        $fotoFiles = $tenuti;
        foreach (array_slice($nuovi, 0, 8) as $idx => $b64) {   // max 8 nuove per salvataggio
            if (count($fotoFiles) >= 12) break;                  // tetto totale 12 foto/fase
            $raw = base64_decode($b64, true);
            if ($raw === false || strlen($raw) < 12 || strlen($raw) > $maxByte) continue;
            $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($raw);
            if (!in_array($mime, $allowed, true)) continue;
            $raw = ardyCompressImage($raw, $mime);
            $ext = $mime === 'image/png' ? 'png' : ($mime === 'image/webp' ? 'webp' : ($mime === 'image/gif' ? 'gif' : 'jpg'));
            $fname = 'f' . $idx . '_' . uniqid() . '.' . $ext;
            if (file_put_contents($dir . $fname, $raw) !== false) $fotoFiles[] = $fname;
        }

        // Video: URL già caricati a monte → conserviamo i validi.
        $videoClean = [];
        foreach ($videoUrls as $u) {
            $u = trim((string) $u);
            if ($u !== '' && filter_var($u, FILTER_VALIDATE_URL)) $videoClean[] = $u;
        }

        $db->prepare(
            "UPDATE fasi SET fase_nome = :nome, testo_breve = :breve, testo_generato = :gen, foto_urls = :foto, video_urls = :video
             WHERE id = :id AND progetto_id = :pid"
        )->execute([
            ':nome'  => $faseNome,
            ':breve' => $breve,
            ':gen'   => $generato,
            ':foto'  => json_encode($fotoFiles),
            ':video' => json_encode($videoClean),
            ':id'    => $id,
            ':pid'   => $progettoId,
        ]);

        echo json_encode(['success' => true, 'id' => $id, 'foto' => $fotoFiles, 'video_urls' => $videoClean]);
        exit();
    }

    if ($mode === 'delete') {
        $id = (int) ($in['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['success' => false, 'error' => 'id mancante']); exit(); }
        // Recupera il progetto PRIMA di eliminare, per ripulire la cartella foto.
        $sg = $db->prepare("SELECT progetto_id FROM fasi WHERE id = ? AND progetto_id IS NOT NULL");
        $sg->execute([$id]);
        $pid = (int) $sg->fetchColumn();
        $db->prepare("DELETE FROM fasi WHERE id = ? AND progetto_id IS NOT NULL")->execute([$id]);
        if ($pid > 0) {
            $dir = progettoFaseFotoDir($pid, $id);
            if (is_dir($dir)) {
                foreach (glob($dir . '*') as $f) { if (is_file($f)) @unlink($f); }
                @rmdir($dir);
            }
        }
        echo json_encode(['success' => true]);
        exit();
    }

    if ($mode === 'riordina') {
        $progettoId = (int) ($in['progetto_id'] ?? 0);
        $ordine     = is_array($in['ordine'] ?? null) ? array_values($in['ordine']) : [];
        if ($progettoId <= 0 || !$ordine) { echo json_encode(['success' => false, 'error' => 'dati mancanti']); exit(); }
        $st = $db->prepare("UPDATE fasi SET ordine = :o WHERE id = :id AND progetto_id = :pid");
        foreach ($ordine as $pos => $faseId) {
            $st->execute([':o' => $pos + 1, ':id' => (int) $faseId, ':pid' => $progettoId]);
        }
        echo json_encode(['success' => true]);
        exit();
    }

    echo json_encode(['success' => false, 'error' => 'mode non valido']);

} catch (PDOException $e) {
    error_log('ARDY PROGETTI FASI API ERROR: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Errore interno']);
}
