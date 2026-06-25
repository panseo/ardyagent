<?php
// -----------------------------------------------------------
// ARDY LAB — Dash Design: pubblica una FASE di progetto su WordPress
// Clone "snello" di ardy-pubblica-lavorazione.php SENZA il guscio cliente
// (niente codici/email/WhatsApp/pagina-lavorazione): qui è contenuto di BRAND
// (portfolio/blog), non comunicazione a un cliente. Vedi PIANO-DASH-DESIGN.md
// (decisione 25/06: le fasi sono il clone della dash principale, decoupled).
//
// Carica WordPress in-process (wp-load) e usa media_handle_sideload per portare
// le foto della fase (lette dal DISCO, non da URL: stanno dietro Basic Auth) nella
// Media Library → URL pubblici. Crea/aggiorna UN post portfolio per progetto
// (progetti.wp_post_id). Gli URL pubblici tornano alla dash per il post social.
//
//   POST {progetto_id, fase_id, testo}  → {success, post_link, wp_post_id, immagini:[url…]}
//
// Protetto via .htaccess (Basic Auth) — elencato nel <FilesMatch>.
// -----------------------------------------------------------

date_default_timezone_set('Europe/Rome');
define('ARDY_WP_LOAD', '/home/micoperibg/public_html/archivio/wp-load.php');

require_once __DIR__ . '/ardy-config.php';
require_once __DIR__ . '/ardy-db.php';
require_once __DIR__ . '/ardy-net.php';

header('Access-Control-Allow-Origin: https://ardyagent.ardy-lab.it');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

function pubFail(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit();
}

// Cartella foto di una fase di progetto (stessa di ardy-progetti-fasi-api.php).
function progettoFaseFotoDir(int $progettoId, int $faseId): string {
    return rtrim(ARDY_UPLOAD_DIR, '/') . '/progetti/' . $progettoId . '/fasi/' . $faseId . '/';
}

// -----------------------------------------------------------
// 1. INPUT
// -----------------------------------------------------------
$in         = json_decode(file_get_contents('php://input'), true) ?: [];
$progettoId = (int) ($in['progetto_id'] ?? 0);
$faseId     = (int) ($in['fase_id'] ?? 0);
$testo      = trim((string) ($in['testo'] ?? ''));   // il post già rivisto dall'autore
if ($progettoId <= 0 || $faseId <= 0) pubFail('Dati mancanti (progetto/fase)');
if ($testo === '') pubFail('Il testo del post è vuoto: scrivilo (anche con l\'AI) prima di pubblicare');

// -----------------------------------------------------------
// 2. CARICA FASE + PROGETTO DAL DB (prima di WP)
// -----------------------------------------------------------
try {
    $db = ardyDB();
    $fs = $db->prepare("SELECT id, fase_nome, foto_urls FROM fasi WHERE id = ? AND progetto_id = ?");
    $fs->execute([$faseId, $progettoId]);
    $fase = $fs->fetch();
    if (!$fase) pubFail('Fase non trovata per questo progetto', 404);

    $ps = $db->prepare("SELECT id, titolo, wp_post_id FROM progetti WHERE id = ? AND deleted_at IS NULL");
    $ps->execute([$progettoId]);
    $prog = $ps->fetch();
    if (!$prog) pubFail('Progetto non trovato', 404);
} catch (PDOException $e) {
    error_log('ARDY PROGETTI PUBBLICA DB ERROR: ' . $e->getMessage());
    pubFail('Errore database', 500);
}

$titoloProgetto = trim((string) ($prog['titolo'] ?? '')) ?: 'Progetto';
$wpPostId       = !empty($prog['wp_post_id']) ? (int) $prog['wp_post_id'] : null;
$faseNome       = trim((string) ($fase['fase_nome'] ?? ''));
$fotoFiles      = json_decode($fase['foto_urls'] ?? '[]', true) ?: [];

// Path su disco delle foto della fase (basename anti-traversal).
$fotoPaths = [];
$dir = progettoFaseFotoDir($progettoId, $faseId);
foreach ($fotoFiles as $fn) {
    if (!is_string($fn) || $fn === '') continue;
    $p = $dir . basename($fn);
    if (is_file($p)) $fotoPaths[] = $p;
}

// -----------------------------------------------------------
// 3. CARICA WORDPRESS (stesso fix anti-redirect del flusso cliente)
// -----------------------------------------------------------
if (!file_exists(ARDY_WP_LOAD)) pubFail('wp-load.php non trovato', 500);
$_SERVER['HTTP_HOST']   = 'ardy-lab.it';
$_SERVER['REQUEST_URI'] = '/';
define('WP_USE_THEMES', false);
require_once ARDY_WP_LOAD;

require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

// -----------------------------------------------------------
// 4. SIDELOAD DELLE FOTO (dal disco → Media Library → URL pubblici)
// -----------------------------------------------------------
ardyHardenUploadDir(rtrim(ARDY_UPLOAD_DIR, '/'));
$savedImageUrls = [];
$savedImageIds  = [];
$allowedMimes   = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

foreach (array_slice($fotoPaths, 0, 15) as $idx => $path) {
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
    if (!in_array($mime, $allowedMimes, true)) continue;
    $ext  = $mime === 'image/png' ? 'png' : ($mime === 'image/webp' ? 'webp' : ($mime === 'image/gif' ? 'gif' : 'jpg'));
    // media_handle_sideload SPOSTA il file: lavoriamo su una copia temporanea per
    // non distruggere l'originale (serve ancora a dash/reel).
    $tmp  = trailingslashit(get_temp_dir()) . 'ardyprog_' . $progettoId . '_' . $faseId . '_' . $idx . '_' . uniqid() . '.' . $ext;
    if (!@copy($path, $tmp)) continue;
    $wpFile = ['name' => basename($tmp), 'type' => $mime, 'tmp_name' => $tmp, 'error' => 0, 'size' => filesize($tmp)];
    $attachId = media_handle_sideload($wpFile, 0);
    if (is_wp_error($attachId)) {
        @unlink($tmp);
        error_log('ARDY PROGETTI SIDELOAD ERROR (img ' . $idx . '): ' . $attachId->get_error_message());
        continue;
    }
    $savedImageUrls[] = wp_get_attachment_url($attachId);
    $savedImageIds[]  = $attachId;
}

// -----------------------------------------------------------
// 5. BLOCCO HTML DELLA FASE (copertina = prima foto, non ripetuta nel corpo)
// -----------------------------------------------------------
$dataOra         = date('d/m/Y');
$existingThumb   = $wpPostId ? has_post_thumbnail($wpPostId) : false;
$featuredImageId = (!$existingThumb && !empty($savedImageIds)) ? $savedImageIds[0] : null;

$fotoHtml = '';
foreach ($savedImageUrls as $idx => $url) {
    if ($featuredImageId !== null && $idx === 0) continue;
    $fotoHtml .= '<img src="' . esc_url($url) . '" style="max-width:100%;margin:10px 0;border-radius:6px;" />' . "\n";
}

$nuovoBlocco = '
<div style="border-left:3px solid #c8a96e;padding:16px 20px;margin:24px 0;background:#fafaf8;">
  <p style="font-size:12px;color:#999;margin-bottom:8px;font-family:monospace;">' . esc_html($dataOra) . ($faseNome !== '' ? ' — ' . esc_html($faseNome) : '') . '</p>
  <div style="font-size:15px;line-height:1.7;color:#333;">' . nl2br(esc_html($testo)) . '</div>
  ' . $fotoHtml . '
</div>';

// -----------------------------------------------------------
// 6. CREA O AGGIORNA IL POST PORTFOLIO DEL PROGETTO
// -----------------------------------------------------------
// Categoria progetti opzionale: ARDY_WP_CAT_PROGETTI in ardy-config.php (non
// versionato). Se assente, il post va nella categoria di default di WordPress
// (NON nella 102 dei clienti).
$catProgetti = defined('ARDY_WP_CAT_PROGETTI') ? (int) ARDY_WP_CAT_PROGETTI : 0;

kses_remove_filters();
if ($wpPostId) {
    $postAttuale    = get_post($wpPostId);
    $contenuto      = ($postAttuale ? $postAttuale->post_content : '') . "\n" . $nuovoBlocco;
    $result = wp_update_post(['ID' => $wpPostId, 'post_content' => $contenuto], true);
    if (is_wp_error($result)) {
        kses_init_filters();
        error_log('ARDY PROGETTI WP UPDATE ERROR: ' . $result->get_error_message());
        pubFail('Aggiornamento del post non riuscito', 500);
    }
} else {
    $introHtml = '<div style="font-family:Georgia,serif;font-size:16px;line-height:1.7;color:#444;margin-bottom:32px;">'
        . esc_html($titoloProgetto) . ' — un progetto Ardy. Il racconto della lavorazione, fase per fase.</div>' . $nuovoBlocco;
    $postArgs = [
        'post_title'   => $titoloProgetto,
        'post_content' => $introHtml,
        'post_status'  => 'publish',
    ];
    if ($catProgetti > 0) $postArgs['post_category'] = [$catProgetti];
    $result = wp_insert_post($postArgs, true);
    if (is_wp_error($result)) {
        kses_init_filters();
        error_log('ARDY PROGETTI WP INSERT ERROR: ' . $result->get_error_message());
        pubFail('Pubblicazione del post non riuscita', 500);
    }
    $wpPostId = (int) $result;
    // Memorizza il post sul progetto (le fasi successive lo aggiornano).
    try { ardyDB()->prepare("UPDATE progetti SET wp_post_id = ? WHERE id = ?")->execute([$wpPostId, $progettoId]); }
    catch (PDOException $e) { error_log('ARDY PROGETTI WP SAVE POSTID: ' . $e->getMessage()); }
}
kses_init_filters();

if ($featuredImageId !== null) set_post_thumbnail($wpPostId, $featuredImageId);
$postLink = get_permalink($wpPostId);

// -----------------------------------------------------------
// 7. MARCA LA FASE COME PUBBLICATA + SALVA GLI URL PUBBLICI (per il social)
// -----------------------------------------------------------
try {
    ardyDB()->prepare("UPDATE fasi SET wp_pubblicata_at = NOW(), wp_foto_urls = :u WHERE id = :id AND progetto_id = :pid")
            ->execute([':u' => json_encode($savedImageUrls), ':id' => $faseId, ':pid' => $progettoId]);
} catch (PDOException $e) {
    error_log('ARDY PROGETTI PUBBLICA MARK: ' . $e->getMessage());
}

echo json_encode([
    'success'    => true,
    'post_link'  => $postLink,
    'wp_post_id' => $wpPostId,
    'immagini'   => $savedImageUrls,   // URL pubblici → utilizzabili da ardy-pubblica-social.php
]);
