<?php
// -----------------------------------------------------------
// ARDY LAB — Dash Design: ARCHIVIO FILE di un PROGETTO (binari di stampa + documenti)
// I file veri (STL, 3MF, G-code, CAD/STEP…) che poi si prelevano e si stampano, e i
// DOCUMENTI di riferimento (categoria 'doc': PDF, DOCX, ODT, RTF, TXT, MD) da cui l'AI
// pesca il materiale per scrivere il racconto — vedi ardy-progetti-ai.php (leggi_doc).
// Gemello di ardy-progetti-fasi-api.php (foto), ma per i binari di stampa: upload
// via multipart (i file sono grandi, niente base64 JSON), download via ?download=.
// Il DB (tabella progetto_file) salva solo i NOMI file + metadati; i binari vivono
// su disco in ARDY_UPLOAD_DIR/progetti/<id>/file/. Stesso seam delle foto (Backblaze
// B2 alla migrazione: cambiano solo path di scrittura + ramo di serving).
//
//   GET ?progetto_id=N                              → lista file del progetto (metadati)
//   GET ?progetto_id=N&file_id=M&download=1         → scarica un file da disco
//   POST multipart {mode:'upload', progetto_id, categoria, note, file=@…}  → carica un file
//   POST JSON {mode:'note', id, note}               → aggiorna la nota di un file
//   POST JSON {mode:'delete', id}                   → elimina un file (+ binario)
//
// Protetto via .htaccess (Basic Auth) — elencato nel <FilesMatch>.
// -----------------------------------------------------------

require_once __DIR__ . '/ardy-db.php';
require_once __DIR__ . '/ardy-net.php';       // ardyHardenUploadDir
require_once __DIR__ . '/ardy-storage.php';   // B2 (S3) con fallback su disco

header('Access-Control-Allow-Origin: https://ardyagent.ardy-lab.it');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }
// Difesa in profondità: se il Basic Auth a monte (.htaccess) non venisse
// applicato, questo guard rifiuta comunque le richieste non autenticate.
require_once __DIR__ . '/ardy-auth.php';
ardyRequireAuth();

// Categorie file e mappa estensione → categoria (whitelist: solo queste si caricano).
// 'doc' è la categoria dei DOCUMENTI DI RIFERIMENTO (appunti, schede, ricerche): stanno
// nella stessa tabella dei binari di stampa ma la dash li mostra in un modulo a parte,
// perché servono a un'altra cosa — dare all'AI il materiale per scrivere il racconto.
const FILE_CATEGORIE = ['stl', '3mf', 'gcode', 'cad', 'altro', 'doc'];
const FILE_EXT_MAP = [
    'stl' => 'stl', 'obj' => 'stl',
    '3mf' => '3mf',
    'gcode' => 'gcode', 'gco' => 'gcode', 'g' => 'gcode', 'bgcode' => 'gcode',
    'step' => 'cad', 'stp' => 'cad', 'f3d' => 'cad', 'scad' => 'cad',
    'dwg' => 'cad', 'dxf' => 'cad', 'igs' => 'cad', 'iges' => 'cad',
    'zip' => 'altro',
    'pdf' => 'doc', 'docx' => 'doc', 'odt' => 'doc',
    'txt' => 'doc', 'md' => 'doc', 'markdown' => 'doc', 'rtf' => 'doc',
];
const FILE_MAX_BYTE = 150 * 1024 * 1024; // 150 MB per file (oltre il limite PHP fa fede ini)
const DOC_MAX_BYTE  = 20 * 1024 * 1024;  // 20 MB per i documenti: vanno letti, non stampati

// Cartella archivio file di UN progetto.
function progettoFileDir(int $progettoId): string {
    return rtrim(ARDY_UPLOAD_DIR, '/') . '/progetti/' . $progettoId . '/file/';
}

// GET con ?download= → serve un file da disco come allegato (dietro Basic Auth).
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['download'])) {
    try {
        $db         = ardyDB();
        $progettoId = (int) ($_GET['progetto_id'] ?? 0);
        $fileId     = (int) ($_GET['file_id'] ?? 0);
        if ($progettoId <= 0 || $fileId <= 0) { http_response_code(400); exit(); }
        $st = $db->prepare("SELECT storage, nome_file, storage_key, nome_originale FROM progetto_file WHERE id = ? AND progetto_id = ?");
        $st->execute([$fileId, $progettoId]);
        $row = $st->fetch();
        if (!$row) { http_response_code(404); exit(); }
        // Nome download ripulito (no caratteri di controllo / quote / path).
        $dl = basename((string) $row['nome_originale']);
        $dl = preg_replace('/[^\w.\- ]+/u', '_', $dl) ?: 'file';
        // Su B2: redirect a un URL firmato a scadenza → download diretto dal bucket.
        if (($row['storage'] ?? 'local') === 'b2' && $row['storage_key'] && ardyB2Configured()) {
            $url = ardyStoragePresignedGet($row['storage_key'], 300, $dl);
            if ($url !== '') { header('Location: ' . $url); exit(); }
            http_response_code(502); exit();
        }
        $path = progettoFileDir($progettoId) . basename($row['nome_file']);
        if (!is_file($path)) { http_response_code(404); exit(); }
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $dl . '"');
        header('Content-Length: ' . filesize($path));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=0, must-revalidate');
        readfile($path);
    } catch (PDOException $e) {
        error_log('ARDY PROGETTI FILE DOWNLOAD ERROR: ' . $e->getMessage());
        http_response_code(500);
    }
    exit();
}

header('Content-Type: application/json');

try {
    $db = ardyDB();

    // ── GET: lista file del progetto ─────────────────────────
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $progettoId = (int) ($_GET['progetto_id'] ?? 0);
        if ($progettoId <= 0) { echo json_encode([]); exit(); }
        // Il testo estratto NON si spedisce in lista (può essere lungo): basta sapere
        // se c'è e di quando è, per mostrare "già letto" accanto al documento.
        $stmt = $db->prepare(
            "SELECT id, categoria, nome_originale, dimensione, note, created_at, testo_estratto_at,
                    CHAR_LENGTH(COALESCE(testo_estratto,'')) AS testo_caratteri
             FROM progetto_file WHERE progetto_id = ? ORDER BY created_at DESC, id DESC"
        );
        $stmt->execute([$progettoId]);
        echo json_encode($stmt->fetchAll());
        exit();
    }

    // ── POST upload (multipart/form-data) ────────────────────
    if (($_POST['mode'] ?? '') === 'upload') {
        $progettoId = (int) ($_POST['progetto_id'] ?? 0);
        if ($progettoId <= 0) { echo json_encode(['success' => false, 'error' => 'progetto mancante']); exit(); }

        // Il progetto deve esistere e non essere eliminato.
        $chk = $db->prepare("SELECT id FROM progetti WHERE id = ? AND deleted_at IS NULL");
        $chk->execute([$progettoId]);
        if ($chk->fetchColumn() === false) { echo json_encode(['success' => false, 'error' => 'progetto non trovato']); exit(); }

        if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
            echo json_encode(['success' => false, 'error' => 'Nessun file ricevuto']); exit();
        }
        $f   = $_FILES['file'];
        $err = (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err !== UPLOAD_ERR_OK) {
            $msg = ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE)
                ? 'File troppo grande per il server'
                : 'Errore upload (codice ' . $err . ')';
            echo json_encode(['success' => false, 'error' => $msg]); exit();
        }
        if (!is_uploaded_file($f['tmp_name'])) { echo json_encode(['success' => false, 'error' => 'Upload non valido']); exit(); }

        $size     = (int) ($f['size'] ?? 0);
        $origName = trim((string) ($f['name'] ?? ''));
        $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        if (!isset(FILE_EXT_MAP[$ext])) {
            $hint = $ext === 'doc'
                ? 'Il vecchio formato .doc non è leggibile: salvalo come PDF o DOCX.'
                : 'Ammessi: STL, OBJ, 3MF, G-code, STEP/CAD, ZIP e documenti PDF, DOCX, ODT, RTF, TXT, MD';
            echo json_encode(['success' => false, 'error' => 'Tipo non ammesso (.' . $ext . '). ' . $hint]);
            exit();
        }

        // Categoria: l'estensione comanda sul fatto che sia un documento o un binario di
        // stampa (un PDF non è un G-code e viceversa); dentro i binari il form può scegliere.
        $catExt    = FILE_EXT_MAP[$ext];
        $formCat   = $_POST['categoria'] ?? '';
        $categoria = ($catExt === 'doc' || $formCat === 'doc' || !in_array($formCat, FILE_CATEGORIE, true))
            ? $catExt : $formCat;

        $maxByte = $categoria === 'doc' ? DOC_MAX_BYTE : FILE_MAX_BYTE;
        if ($size <= 0 || $size > $maxByte) {
            echo json_encode(['success' => false, 'error' => 'File vuoto o oltre ' . (int) ($maxByte / 1048576) . ' MB']); exit();
        }

        $note = trim((string) ($_POST['note'] ?? ''));
        if (mb_strlen($note) > 500) $note = mb_substr($note, 0, 500);

        // Nome file univoco (l'originale resta solo nei metadati / nel download).
        $diskName = 'p' . $progettoId . '_' . uniqid('', true) . '.' . $ext;

        // Storage: B2 se configurato (upload diretto al bucket), altrimenti disco.
        // Se B2 fallisce, fallback su disco per non perdere il file.
        // ECCEZIONE — i documenti restano SEMPRE su disco: sono piccoli (≤20 MB) e il
        // server deve poterli RILEGGERE per estrarne il testo, mentre le letture da B2
        // sono ancora quelle rotte annotate in TODO-PROSSIMI-TASK (Opzione A).
        $storage    = 'local';
        $storageKey = null;
        if ($categoria !== 'doc' && ardyB2Configured()) {
            $objectKey = 'progetti/' . $progettoId . '/file/' . $diskName;
            if (ardyStoragePutFile($objectKey, $f['tmp_name'])) {
                $storage = 'b2'; $storageKey = $objectKey;
            }
        }
        if ($storage === 'local') {
            $dir = progettoFileDir($progettoId);
            if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
                echo json_encode(['success' => false, 'error' => 'cartella non creabile']); exit();
            }
            ardyHardenUploadDir(rtrim(ARDY_UPLOAD_DIR, '/'));
            if (!move_uploaded_file($f['tmp_name'], $dir . $diskName)) {
                echo json_encode(['success' => false, 'error' => 'Salvataggio file fallito']); exit();
            }
        }

        $db->prepare(
            "INSERT INTO progetto_file (progetto_id, categoria, storage, nome_file, storage_key, nome_originale, dimensione, note)
             VALUES (:pid, :cat, :st, :nf, :sk, :no, :dim, :note)"
        )->execute([
            ':pid'  => $progettoId,
            ':cat'  => $categoria,
            ':st'   => $storage,
            ':nf'   => $diskName,
            ':sk'   => $storageKey,
            ':no'   => $origName !== '' ? mb_substr($origName, 0, 255) : $diskName,
            ':dim'  => $size,
            ':note' => $note !== '' ? $note : null,
        ]);
        echo json_encode(['success' => true, 'id' => (int) $db->lastInsertId()]);
        exit();
    }

    // ── POST JSON (note / delete) ────────────────────────────
    $in   = json_decode(file_get_contents('php://input'), true) ?: [];
    $mode = $in['mode'] ?? '';

    if ($mode === 'note') {
        $id = (int) ($in['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['success' => false, 'error' => 'id mancante']); exit(); }
        $note = trim((string) ($in['note'] ?? ''));
        if (mb_strlen($note) > 500) $note = mb_substr($note, 0, 500);
        $db->prepare("UPDATE progetto_file SET note = :n WHERE id = :id")
           ->execute([':n' => $note !== '' ? $note : null, ':id' => $id]);
        echo json_encode(['success' => true]);
        exit();
    }

    if ($mode === 'delete') {
        $id = (int) ($in['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['success' => false, 'error' => 'id mancante']); exit(); }
        $st = $db->prepare("SELECT progetto_id, storage, nome_file, storage_key FROM progetto_file WHERE id = ?");
        $st->execute([$id]);
        $row = $st->fetch();
        if ($row) {
            $db->prepare("DELETE FROM progetto_file WHERE id = ?")->execute([$id]);
            if (($row['storage'] ?? 'local') === 'b2' && $row['storage_key']) {
                ardyStorageDelete($row['storage_key']);
            } else {
                $path = progettoFileDir((int) $row['progetto_id']) . basename($row['nome_file']);
                if (is_file($path)) @unlink($path);
            }
        }
        echo json_encode(['success' => true]);
        exit();
    }

    echo json_encode(['success' => false, 'error' => 'mode non valido']);

} catch (PDOException $e) {
    error_log('ARDY PROGETTI FILE API ERROR: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Errore interno']);
}
