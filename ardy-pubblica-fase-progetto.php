<?php
// -----------------------------------------------------------
// ARDY LAB — Dash Design: pubblica una FASE-RACCONTO sull'articolo del progetto
// Aggancia (append) la fase all'articolo "madre" creato da ardy-pubblica-progetto.php:
// testo + foto della fase si aggiungono in coda al post WordPress del progetto.
//
//   POST {progetto_id, fase_id}  → aggancia (o ri-sincronizza) la fase all'articolo; segna wp_pubblicata_at.
//
// Idempotente: ogni fase è racchiusa fra marcatori <!-- ardy-fase-ID -->. Alla prima
// pubblicazione il blocco si aggiunge in coda; ripubblicando (es. dopo aver aggiunto
// una foto) il blocco già presente viene SOSTITUITO, così non si creano doppioni.
//
// Richiede che l'articolo del progetto sia già pubblicato (progetti.wp_post_id).
// Protetto via .htaccess (Basic Auth) — elencato nel <FilesMatch>.
// -----------------------------------------------------------

date_default_timezone_set('Europe/Rome');
define('ARDY_WP_LOAD', '/home/micoperibg/public_html/archivio/wp-load.php');

require_once __DIR__ . '/ardy-config.php';
require_once __DIR__ . '/ardy-db.php';
require_once __DIR__ . '/ardy-net.php';
require_once __DIR__ . '/ardy-storage.php';

header('Access-Control-Allow-Origin: https://ardyagent.ardy-lab.it');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST')    { http_response_code(405); echo json_encode(['success' => false, 'error' => 'Metodo non valido']); exit(); }

$in         = json_decode(file_get_contents('php://input'), true) ?: [];
$progettoId = (int) ($in['progetto_id'] ?? 0);
$faseId     = (int) ($in['fase_id'] ?? 0);
if ($progettoId <= 0 || $faseId <= 0) { echo json_encode(['success' => false, 'error' => 'dati mancanti']); exit(); }

try {
    $db = ardyDB();
    $pst = $db->prepare("SELECT wp_post_id FROM progetti WHERE id = ? AND deleted_at IS NULL");
    $pst->execute([$progettoId]);
    $wpPostId = (int) ($pst->fetchColumn() ?: 0);
    if ($wpPostId <= 0) { echo json_encode(['success' => false, 'error' => 'Pubblica prima l\'articolo del progetto']); exit(); }

    $fst = $db->prepare("SELECT fase_nome, testo_breve, testo_generato, foto_urls FROM fasi WHERE id = ? AND progetto_id = ?");
    $fst->execute([$faseId, $progettoId]);
    $fase = $fst->fetch();
    if (!$fase) { echo json_encode(['success' => false, 'error' => 'Fase non trovata']); exit(); }
    $faseNome = trim((string) $fase['fase_nome']);
    $testo    = trim((string) ($fase['testo_generato'] ?: $fase['testo_breve']));
    $foto     = json_decode($fase['foto_urls'] ?? '[]', true) ?: [];
} catch (PDOException $e) {
    error_log('ARDY PUBBLICA FASE PROG DB: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Errore database']); exit();
}

// Byte di una foto della fase (disco-prima, poi B2).
function faseProgFotoBytes(int $pid, int $fid, string $file): ?string {
    $base = 'progetti/' . $pid . '/fasi/' . $fid . '/' . basename($file);
    $path = rtrim(ARDY_UPLOAD_DIR, '/') . '/' . $base;
    if (is_file($path)) { $b = @file_get_contents($path); return $b !== false ? $b : null; }
    if (ardyB2Configured()) return ardyStorageGet($base);
    return null;
}

// Pre-carico i BYTE delle foto PRIMA di caricare WordPress. Le foto su Backblaze B2
// si leggono con una richiesta HTTPS in uscita (URL firmato → ardyStorageGet): se
// quella lettura avviene DOPO require ARDY_WP_LOAD, dentro l'ambiente WordPress la
// richiesta verso B2 fallisce e la foto non arriva mai all'articolo (il testo sì).
// Leggendola qui — stesso contesto "pulito" del proxy `?file=` che già mostra le
// anteprime in dashboard — il problema sparisce. Con le foto su disco (prima della
// migrazione a B2) file_get_contents funzionava anche dopo wp-load: ecco perché
// "prima di B2 funzionava".
$fotoPronte = [];   // [['mime'=>.., 'ext'=>.., 'bytes'=>..], ...] già lette in RAM
$extMap = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
foreach (array_slice($foto, 0, 12) as $fn) {
    if (!is_string($fn) || $fn === '') continue;
    $bytes = faseProgFotoBytes($progettoId, $faseId, $fn);
    if (!is_string($bytes) || strlen($bytes) < 12) continue;
    $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
    if (!isset($extMap[$mime])) continue;
    $fotoPronte[] = ['mime' => $mime, 'ext' => $extMap[$mime], 'bytes' => $bytes];
}

if (!file_exists(ARDY_WP_LOAD)) { echo json_encode(['success' => false, 'error' => 'wp-load.php non trovato']); exit(); }
$_SERVER['HTTP_HOST']   = 'ardy-lab.it';
$_SERVER['REQUEST_URI'] = '/';
if (!defined('WP_USE_THEMES')) define('WP_USE_THEMES', false);
require_once ARDY_WP_LOAD;
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

$tmpDir = rtrim(ARDY_UPLOAD_DIR, '/') . '/progetti/' . $progettoId . '/_wp_tmp/';
if (!is_dir($tmpDir) && !mkdir($tmpDir, 0755, true) && !is_dir($tmpDir)) {
    echo json_encode(['success' => false, 'error' => 'cartella temporanea non creabile']); exit();
}
ardyHardenUploadDir(rtrim(ARDY_UPLOAD_DIR, '/'));

$fotoHtml = '';
foreach ($fotoPronte as $f) {                 // byte già letti da storage prima di wp-load
    $bytes = $f['bytes'];
    $mime  = $f['mime'];
    $tname = 'f_' . uniqid() . '.' . $f['ext'];
    $tpath = $tmpDir . $tname;
    if (file_put_contents($tpath, $bytes) === false) continue;
    $attachId = media_handle_sideload(['name' => $tname, 'type' => $mime, 'tmp_name' => $tpath, 'error' => 0, 'size' => strlen($bytes)], 0);
    if (is_wp_error($attachId)) { @unlink($tpath); error_log('ARDY PUBBLICA FASE PROG SIDELOAD: ' . $attachId->get_error_message()); continue; }
    $fotoHtml .= '<img src="' . esc_url(wp_get_attachment_url($attachId)) . '" style="max-width:100%;margin:10px 0;border-radius:6px;" />' . "\n";
}
foreach (glob($tmpDir . '*') as $tmpf) { if (is_file($tmpf)) @unlink($tmpf); }
@rmdir($tmpDir);

// Marcatori HTML per-fase: delimitano il blocco di QUESTA fase dentro l'articolo.
// Servono a ripubblicare in modo idempotente (sostituire, non duplicare) quando la
// fase viene modificata — es. l'aggiunta di una foto dopo la prima pubblicazione.
$mStart = '<!-- ardy-fase-' . $faseId . ' -->';
$mEnd   = '<!-- /ardy-fase-' . $faseId . ' -->';

$blocco = $mStart . '
<div style="border-left:3px solid #c8a96e;padding:16px 20px;margin:24px 0;background:#fafaf8;">
  <p style="font-size:12px;color:#999;margin-bottom:8px;font-family:monospace;">' . esc_html(date('d/m/Y')) . ($faseNome !== '' ? ' — ' . esc_html($faseNome) : '') . '</p>'
  . ($testo !== '' ? '<div style="font-size:15px;line-height:1.7;color:#333;">' . nl2br(esc_html($testo)) . '</div>' : '')
  . $fotoHtml . '
</div>' . $mEnd;

kses_remove_filters();
$post = get_post($wpPostId);
if (!$post) { echo json_encode(['success' => false, 'error' => 'Articolo non trovato su WordPress']); exit(); }

// Se il blocco di questa fase è già presente (ripubblicazione) lo SOSTITUISCO sul
// posto; altrimenti lo aggiungo in coda. Niente regex: confronto posizioni dei tag.
$content = (string) $post->post_content;
$startPos = strpos($content, $mStart);
$endPos   = $startPos !== false ? strpos($content, $mEnd, $startPos) : false;
if ($startPos !== false && $endPos !== false) {
    $endPos += strlen($mEnd);
    $newContent = substr($content, 0, $startPos) . $blocco . substr($content, $endPos);
} else {
    $newContent = $content . "\n" . $blocco;
}
$result = wp_update_post(['ID' => $wpPostId, 'post_content' => $newContent], true);
if (is_wp_error($result)) {
    error_log('ARDY PUBBLICA FASE PROG WP UPDATE: ' . $result->get_error_message());
    echo json_encode(['success' => false, 'error' => 'Aggiornamento articolo non riuscito']); exit();
}

try {
    $db->prepare("UPDATE fasi SET wp_pubblicata_at = NOW() WHERE id = ? AND progetto_id = ?")->execute([$faseId, $progettoId]);
} catch (PDOException $e) { error_log('ARDY PUBBLICA FASE PROG DB UPD: ' . $e->getMessage()); }

echo json_encode(['success' => true, 'post_link' => get_permalink($wpPostId)]);
