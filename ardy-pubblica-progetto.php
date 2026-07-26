<?php
// -----------------------------------------------------------
// ARDY LAB — Dash Design: pubblica l'ARTICOLO "madre" di un PROGETTO su WordPress
// Crea il post del progetto (intro + galleria render/foto finite) in una categoria
// dedicata. Le fasi-racconto poi vi si agganciano in append (ardy-pubblica-fase-progetto).
// Gemello di ardy-pubblica-lavorazione.php (lato cliente), ma sul progetto.
//
//   POST {progetto_id, testo}  → crea l'articolo (se non esiste) e ritorna il link.
//
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
// Difesa in profondità: se il Basic Auth a monte (.htaccess) non venisse
// applicato, questo guard rifiuta comunque le richieste non autenticate.
require_once __DIR__ . '/ardy-auth.php';
ardyRequireAuth();
if ($_SERVER['REQUEST_METHOD'] !== 'POST')    { http_response_code(405); echo json_encode(['success' => false, 'error' => 'Metodo non valido']); exit(); }

$in         = json_decode(file_get_contents('php://input'), true) ?: [];
$progettoId = (int) ($in['progetto_id'] ?? 0);
$testo      = trim((string) ($in['testo'] ?? ''));
if ($progettoId <= 0) { echo json_encode(['success' => false, 'error' => 'progetto mancante']); exit(); }
if ($testo === '')    { echo json_encode(['success' => false, 'error' => 'Testo dell\'articolo mancante']); exit(); }

try {
    $db = ardyDB();
    $st = $db->prepare("SELECT titolo, wp_post_id FROM progetti WHERE id = ? AND deleted_at IS NULL");
    $st->execute([$progettoId]);
    $p = $st->fetch();
    if (!$p) { echo json_encode(['success' => false, 'error' => 'Progetto non trovato']); exit(); }
    if (!empty($p['wp_post_id'])) {
        // Già pubblicato: non ricreare (le fasi si agganciano a quel post).
        echo json_encode(['success' => true, 'already' => true, 'wp_post_id' => (int) $p['wp_post_id'],
            'post_link' => (string) ($db->query("SELECT wp_post_link FROM progetti WHERE id = " . $progettoId)->fetchColumn() ?: '')]);
        exit();
    }
    $titolo = trim((string) $p['titolo']) ?: 'Progetto Ardy';

    // Immagini della galleria, nell'ordine del racconto: com'era prima, i render, il pezzo finito.
    $gst = $db->prepare("SELECT id, tipo, storage, nome_file, storage_key FROM progetto_galleria WHERE progetto_id = ? ORDER BY FIELD(tipo,'prima','render','foto'), ordine ASC, id ASC");
    $gst->execute([$progettoId]);
    $galleria = $gst->fetchAll();
} catch (PDOException $e) {
    error_log('ARDY PUBBLICA PROGETTO DB ERROR: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Errore database']); exit();
}

/** Byte di un'immagine di galleria (da disco o da B2). */
function progettoGalleriaBytes(array $row, int $progettoId): ?string {
    if (($row['storage'] ?? 'local') === 'b2' && $row['storage_key']) {
        return ardyStorageGet($row['storage_key']);
    }
    $path = rtrim(ARDY_UPLOAD_DIR, '/') . '/progetti/' . $progettoId . '/galleria/' . basename($row['nome_file']);
    if (!is_file($path)) return null;
    $b = @file_get_contents($path);
    return $b !== false ? $b : null;
}

// Pre-carico i BYTE della galleria PRIMA di caricare WordPress. Le foto su Backblaze
// B2 si leggono con una richiesta HTTPS in uscita (URL firmato → ardyStorageGet): se
// quella lettura avviene DOPO require ARDY_WP_LOAD, dentro l'ambiente WordPress la
// richiesta verso B2 fallisce e le immagini non arrivano all'articolo. Leggendole qui,
// nel contesto "pulito" prima di wp-load, il problema sparisce (stesso fix di
// ardy-pubblica-fase-progetto.php). Su disco file_get_contents funzionava comunque.
$galPronte = [];   // [['mime'=>.., 'ext'=>.., 'bytes'=>.., 'tipo'=>.., 'gid'=>..], ...]
$extMap = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
foreach (array_slice($galleria, 0, 20) as $g) {
    $bytes = progettoGalleriaBytes($g, $progettoId);
    if (!is_string($bytes) || strlen($bytes) < 12) continue;
    $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
    if (!isset($extMap[$mime])) continue;
    $galPronte[] = ['mime' => $mime, 'ext' => $extMap[$mime], 'bytes' => $bytes, 'tipo' => $g['tipo'], 'gid' => $g['id']];
}

if (!file_exists(ARDY_WP_LOAD)) { echo json_encode(['success' => false, 'error' => 'wp-load.php non trovato']); exit(); }
$_SERVER['HTTP_HOST']   = 'ardy-lab.it';
$_SERVER['REQUEST_URI'] = '/';
if (!defined('WP_USE_THEMES')) define('WP_USE_THEMES', false);
require_once ARDY_WP_LOAD;
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

// Cartella temporanea per i sideload (media_handle_sideload SPOSTA il file, quindi
// passiamo sempre una COPIA, mai l'originale su disco/B2).
$tmpDir = rtrim(ARDY_UPLOAD_DIR, '/') . '/progetti/' . $progettoId . '/_wp_tmp/';
if (!is_dir($tmpDir) && !mkdir($tmpDir, 0755, true) && !is_dir($tmpDir)) {
    echo json_encode(['success' => false, 'error' => 'cartella temporanea non creabile']); exit();
}
ardyHardenUploadDir(rtrim(ARDY_UPLOAD_DIR, '/'));

$immagini = [];   // [{url, id, tipo}]
foreach ($galPronte as $g) {                  // byte già letti da storage prima di wp-load
    $bytes = $g['bytes'];
    $mime  = $g['mime'];
    $fname = 'g_' . $g['gid'] . '_' . uniqid() . '.' . $g['ext'];
    $fpath = $tmpDir . $fname;
    if (file_put_contents($fpath, $bytes) === false) continue;
    $attachId = media_handle_sideload([
        'name' => $fname, 'type' => $mime, 'tmp_name' => $fpath, 'error' => 0, 'size' => strlen($bytes),
    ], 0);
    if (is_wp_error($attachId)) { @unlink($fpath); error_log('ARDY PUBBLICA PROGETTO SIDELOAD: ' . $attachId->get_error_message()); continue; }
    $immagini[] = ['url' => wp_get_attachment_url($attachId), 'id' => (int) $attachId, 'tipo' => $g['tipo']];
}
// Pulisci eventuali residui temporanei.
foreach (glob($tmpDir . '*') as $f) { if (is_file($f)) @unlink($f); }
@rmdir($tmpDir);

// Copertina = prima foto finita, poi primo render. Mai una foto 'prima': quella
// racconta il punto di partenza, non il pezzo — in vetrina ci va il risultato.
$featuredId = null;
foreach ($immagini as $im) { if ($im['tipo'] === 'foto')   { $featuredId = $im['id']; break; } }
if ($featuredId === null) { foreach ($immagini as $im) { if ($im['tipo'] === 'render') { $featuredId = $im['id']; break; } } }
if ($featuredId === null && !empty($immagini)) $featuredId = $immagini[0]['id'];

// Contenuto: intro + punto di partenza + render + foto finite (la copertina non si
// ripete nel corpo).
$introHtml = '<div style="font-family:Georgia,serif;font-size:16px;line-height:1.7;color:#444;margin-bottom:24px;">'
    . nl2br(esc_html($testo)) . '</div>';
$prima = ''; $render = ''; $foto = '';
foreach ($immagini as $im) {
    if ($featuredId !== null && $im['id'] === $featuredId) continue;
    $tag = '<img src="' . esc_url($im['url']) . '" style="max-width:100%;margin:10px 0;border-radius:6px;" />' . "\n";
    if      ($im['tipo'] === 'prima')  $prima  .= $tag;
    elseif  ($im['tipo'] === 'render') $render .= $tag;
    else                               $foto   .= $tag;
}
$content = $introHtml;
if ($prima  !== '') $content .= '<h3 style="font-family:sans-serif;color:#8b6f3e;">Il punto di partenza</h3>' . "\n" . $prima;
if ($render !== '') $content .= '<h3 style="font-family:sans-serif;color:#8b6f3e;">Render</h3>' . "\n" . $render;
if ($foto   !== '') $content .= '<h3 style="font-family:sans-serif;color:#8b6f3e;">La creazione</h3>' . "\n" . $foto;

// Categoria dedicata ai progetti design (override in ardy-config.php).
$catName = defined('ARDY_DESIGN_WP_CAT') ? ARDY_DESIGN_WP_CAT : 'Creazioni';
$catTerm = get_category_by_slug(sanitize_title($catName));
if ($catTerm && !is_wp_error($catTerm)) { $catId = (int) $catTerm->term_id; }
else { $t = wp_insert_term($catName, 'category'); $catId = (!is_wp_error($t)) ? (int) $t['term_id'] : (int) get_option('default_category'); }

kses_remove_filters(); // contenuto generato da noi: niente strip di style/img

$result = wp_insert_post([
    'post_title'    => 'Creazione — ' . $titolo,
    'post_content'  => $content,
    'post_status'   => 'publish',
    'post_category' => [$catId],
], true);

if (is_wp_error($result)) {
    error_log('ARDY PUBBLICA PROGETTO WP INSERT: ' . $result->get_error_message());
    echo json_encode(['success' => false, 'error' => 'Pubblicazione non riuscita']); exit();
}
$wpPostId = (int) $result;
if ($featuredId !== null) set_post_thumbnail($wpPostId, $featuredId);
$postLink = get_permalink($wpPostId);

try {
    $db->prepare("UPDATE progetti SET wp_post_id = :wid, wp_post_link = :wlink WHERE id = :id")
       ->execute([':wid' => $wpPostId, ':wlink' => $postLink, ':id' => $progettoId]);
} catch (PDOException $e) {
    error_log('ARDY PUBBLICA PROGETTO DB UPDATE: ' . $e->getMessage());
}

echo json_encode(['success' => true, 'wp_post_id' => $wpPostId, 'post_link' => $postLink, 'immagini' => count($immagini)]);
