<?php
// -----------------------------------------------------------
// ARDY LAB — Dash Design: FOTO VENDITA del progetto (Modulo 2 / WooCommerce)
// Set di immagini SEPARATO dalla galleria (Modulo 1 → ardy-lab.it): sono le foto
// professionali del pezzo finito che vanno sul prodotto Woo. Storage LOCALE di
// default (Woo le scarica affidabile da disco; niente auto-push su B2 — Opzione A).
//
//   GET ?progetto_id=N                          → lista foto (metadati)
//   GET ?progetto_id=N&file_id=M&file=1         → serve una foto (disco/B2)
//   POST multipart {mode:'upload', progetto_id, file=@…}  → carica una foto
//   POST JSON {mode:'delete', id}               → elimina una foto (+ binario)
//   POST JSON {mode:'ordina', ids:[...]}        → riordina
//
// Protetto via .htaccess (Basic Auth) — elencato nel <FilesMatch>.
// -----------------------------------------------------------

require_once __DIR__ . '/ardy-db.php';
require_once __DIR__ . '/ardy-net.php';       // ardyCompressImage + ardyHardenUploadDir
require_once __DIR__ . '/ardy-storage.php';   // B2 (per il serve, se una foto è stata spostata)

header('Access-Control-Allow-Origin: https://ardyagent.ardy-lab.it');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }
require_once __DIR__ . '/ardy-auth.php';
ardyRequireAuth();

function fotoVenditaDir(int $progettoId): string {
    return rtrim(ARDY_UPLOAD_DIR, '/') . '/progetti/' . $progettoId . '/vendita/';
}

// GET con ?file= → serve una foto (disco-prima, poi B2 se spostata a freddo).
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['file'])) {
    try {
        $db         = ardyDB();
        $progettoId = (int) ($_GET['progetto_id'] ?? 0);
        $fileId     = (int) ($_GET['file_id'] ?? 0);
        if ($progettoId <= 0 || $fileId <= 0) { http_response_code(400); exit(); }
        $st = $db->prepare("SELECT storage, nome_file, storage_key FROM progetto_foto_vendita WHERE id = ? AND progetto_id = ?");
        $st->execute([$fileId, $progettoId]);
        $row = $st->fetch();
        if (!$row) { http_response_code(404); exit(); }
        $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

        if (($row['storage'] ?? 'local') === 'b2' && $row['storage_key'] && ardyB2Configured()) {
            $bytes = ardyStorageGet($row['storage_key']);
            if ($bytes !== null) {
                $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
                if (!in_array($mime, $allowed, true)) { http_response_code(403); exit(); }
                header('Content-Type: ' . $mime);
                header('Content-Length: ' . strlen($bytes));
                header('Cache-Control: private, max-age=3600');
                echo $bytes; exit();
            }
            http_response_code(404); exit();
        }
        $path = fotoVenditaDir($progettoId) . basename($row['nome_file']);
        if (!is_file($path)) { http_response_code(404); exit(); }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
        if (!in_array($mime, $allowed, true)) { http_response_code(403); exit(); }
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: private, max-age=3600');
        readfile($path);
    } catch (PDOException $e) {
        error_log('ARDY FOTOVENDITA SERVE ERROR: ' . $e->getMessage());
        http_response_code(500);
    }
    exit();
}

header('Content-Type: application/json');

try {
    $db = ardyDB();

    // ── GET: lista foto del progetto ─────────────────────────
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $progettoId = (int) ($_GET['progetto_id'] ?? 0);
        if ($progettoId <= 0) { echo json_encode([]); exit(); }
        $stmt = $db->prepare(
            "SELECT id, dimensione, ordine, created_at FROM progetto_foto_vendita
             WHERE progetto_id = ? ORDER BY ordine ASC, id ASC"
        );
        $stmt->execute([$progettoId]);
        echo json_encode($stmt->fetchAll());
        exit();
    }

    // ── POST upload (multipart/form-data) — storage LOCALE ───
    if (($_POST['mode'] ?? '') === 'upload') {
        $progettoId = (int) ($_POST['progetto_id'] ?? 0);
        if ($progettoId <= 0) { echo json_encode(['success' => false, 'error' => 'progetto mancante']); exit(); }
        $chk = $db->prepare("SELECT id FROM progetti WHERE id = ? AND deleted_at IS NULL");
        $chk->execute([$progettoId]);
        if ($chk->fetchColumn() === false) { echo json_encode(['success' => false, 'error' => 'progetto non trovato']); exit(); }

        if (!isset($_FILES['file']) || (int) ($_FILES['file']['error'] ?? 1) !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'error' => 'Nessuna immagine ricevuta']); exit();
        }
        $f = $_FILES['file'];
        if (!is_uploaded_file($f['tmp_name'])) { echo json_encode(['success' => false, 'error' => 'Upload non valido']); exit(); }
        if ((int) $f['size'] <= 0 || (int) $f['size'] > 20 * 1024 * 1024) {
            echo json_encode(['success' => false, 'error' => 'Immagine vuota o oltre 20 MB']); exit();
        }

        $raw     = file_get_contents($f['tmp_name']);
        $mime    = (new finfo(FILEINFO_MIME_TYPE))->buffer($raw);
        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
        if (!isset($allowed[$mime])) { echo json_encode(['success' => false, 'error' => 'Formato non supportato']); exit(); }
        $raw = ardyCompressImage($raw, $mime);
        $ext = $allowed[$mime];

        $diskName = 'v_' . uniqid('', true) . '.' . $ext;

        // Storage LOCALE (nessun auto-push su B2): Woo scarica affidabile da disco.
        $dir = fotoVenditaDir($progettoId);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            echo json_encode(['success' => false, 'error' => 'cartella non creabile']); exit();
        }
        ardyHardenUploadDir(rtrim(ARDY_UPLOAD_DIR, '/'));
        if (file_put_contents($dir . $diskName, $raw) === false) {
            echo json_encode(['success' => false, 'error' => 'Salvataggio fallito']); exit();
        }

        $ordine = (int) $db->query("SELECT COALESCE(MAX(ordine),0)+1 FROM progetto_foto_vendita WHERE progetto_id = " . $progettoId)->fetchColumn();
        $db->prepare(
            "INSERT INTO progetto_foto_vendita (progetto_id, storage, nome_file, storage_key, dimensione, ordine)
             VALUES (:pid, 'local', :nf, NULL, :dim, :ord)"
        )->execute([':pid' => $progettoId, ':nf' => $diskName, ':dim' => strlen($raw), ':ord' => $ordine]);
        echo json_encode(['success' => true, 'id' => (int) $db->lastInsertId()]);
        exit();
    }

    // ── POST JSON (delete / ordina) ──────────────────────────
    $in = json_decode(file_get_contents('php://input'), true) ?: [];

    if (($in['mode'] ?? '') === 'delete') {
        $id = (int) ($in['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['success' => false, 'error' => 'id mancante']); exit(); }
        $st = $db->prepare("SELECT progetto_id, storage, nome_file, storage_key FROM progetto_foto_vendita WHERE id = ?");
        $st->execute([$id]);
        $row = $st->fetch();
        if ($row) {
            $db->prepare("DELETE FROM progetto_foto_vendita WHERE id = ?")->execute([$id]);
            if (($row['storage'] ?? 'local') === 'b2' && $row['storage_key']) {
                ardyStorageDelete($row['storage_key']);
            } else {
                $path = fotoVenditaDir((int) $row['progetto_id']) . basename($row['nome_file']);
                if (is_file($path)) @unlink($path);
            }
        }
        echo json_encode(['success' => true]);
        exit();
    }

    if (($in['mode'] ?? '') === 'ordina') {
        $ids = is_array($in['ids'] ?? null) ? $in['ids'] : [];
        $ord = 1;
        $up  = $db->prepare("UPDATE progetto_foto_vendita SET ordine = ? WHERE id = ?");
        foreach ($ids as $id) { $up->execute([$ord++, (int) $id]); }
        echo json_encode(['success' => true]);
        exit();
    }

    echo json_encode(['success' => false, 'error' => 'mode non valido']);

} catch (PDOException $e) {
    error_log('ARDY FOTOVENDITA API ERROR: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Errore interno']);
}
