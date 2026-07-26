<?php
// -----------------------------------------------------------
// ARDY LAB — Dash Design: pubblica l'ARTICOLO "madre" di un PROGETTO su WordPress
// Crea il post del progetto (intro + galleria render/foto finite) in una categoria
// dedicata. Le fasi-racconto poi vi si agganciano in append (ardy-pubblica-fase-progetto).
// Gemello di ardy-pubblica-lavorazione.php (lato cliente), ma sul progetto.
//
//   POST {progetto_id, testo}                      → crea l'articolo (se non esiste)
//   POST {mode:'aggiorna_immagini', progetto_id}   → rifà il blocco immagini di un
//        articolo già pubblicato (intro e fasi-racconto accodate restano intatte).
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
$mode       = ($in['mode'] ?? 'crea') === 'aggiorna_immagini' ? 'aggiorna_immagini' : 'crea';
$progettoId = (int) ($in['progetto_id'] ?? 0);
$testo      = trim((string) ($in['testo'] ?? ''));
if ($progettoId <= 0) { echo json_encode(['success' => false, 'error' => 'progetto mancante']); exit(); }
if ($mode === 'crea' && $testo === '') { echo json_encode(['success' => false, 'error' => 'Testo dell\'articolo mancante']); exit(); }

// Blocco immagini delimitato: così «aggiorna immagini» lo sostituisce senza toccare
// né l'introduzione né le fasi-racconto già accodate all'articolo.
const GAL_START = '<!--ardy-galleria-->';
const GAL_END   = '<!--/ardy-galleria-->';

$wpPostIdEsistente = 0;
try {
    $db = ardyDB();
    $st = $db->prepare("SELECT titolo, wp_post_id FROM progetti WHERE id = ? AND deleted_at IS NULL");
    $st->execute([$progettoId]);
    $p = $st->fetch();
    if (!$p) { echo json_encode(['success' => false, 'error' => 'Progetto non trovato']); exit(); }
    $wpPostIdEsistente = (int) ($p['wp_post_id'] ?? 0);
    if ($mode === 'crea' && $wpPostIdEsistente > 0) {
        // Già pubblicato: non ricreare (le fasi si agganciano a quel post).
        echo json_encode(['success' => true, 'already' => true, 'wp_post_id' => $wpPostIdEsistente,
            'post_link' => (string) ($db->query("SELECT wp_post_link FROM progetti WHERE id = " . $progettoId)->fetchColumn() ?: '')]);
        exit();
    }
    if ($mode === 'aggiorna_immagini' && $wpPostIdEsistente <= 0) {
        echo json_encode(['success' => false, 'error' => 'Questo progetto non ha ancora un articolo: pubblicalo prima']); exit();
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

/**
 * Byte di un'immagine di galleria. Se sta su B2 e la lettura non riesce (vedi la nota
 * "letture B2" in TODO-PROSSIMI-TASK), si ritenta su disco prima di rinunciare: una
 * copia locale può esserci, e un'immagine in meno nell'articolo è un danno silenzioso.
 */
function progettoGalleriaBytes(array $row, int $progettoId): ?string {
    $bytes = null;
    if (($row['storage'] ?? 'local') === 'b2' && $row['storage_key']) {
        $bytes = ardyStorageGet($row['storage_key']);
        if ($bytes === null) {
            error_log('ARDY PUBBLICA PROGETTO: lettura B2 fallita per ' . $row['storage_key'] . ' — provo su disco');
        }
    }
    if ($bytes !== null) return $bytes;
    $path = rtrim(ARDY_UPLOAD_DIR, '/') . '/progetti/' . $progettoId . '/galleria/' . basename($row['nome_file']);
    if (!is_file($path)) return null;
    $b = @file_get_contents($path);
    return $b !== false ? $b : null;
}

/**
 * Salva gli URL PUBBLICI (WordPress) delle immagini dell'articolo. Servono ai social:
 * Facebook e Instagram devono poter scaricare l'immagine da un indirizzo raggiungibile,
 * e quelle sul nostro server stanno dietro Basic Auth. È il motivo per cui si manda ai
 * social solo ciò che è già passato da WordPress.
 */
function progettoSalvaImmaginiWp(PDO $db, int $progettoId, array $immagini): void {
    $urls = array_values(array_filter(array_map(fn($i) => (string) ($i['url'] ?? ''), $immagini)));
    try {
        $db->prepare("UPDATE progetti SET wp_immagini = :u WHERE id = :id")
           ->execute([':u' => $urls ? json_encode($urls, JSON_UNESCAPED_SLASHES) : null, ':id' => $progettoId]);
    } catch (PDOException $e) {
        error_log('ARDY PUBBLICA PROGETTO DB IMMAGINI: ' . $e->getMessage());
    }
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
    // Ogni scarto va a log col motivo: senza, un articolo esce senza foto e non si
    // capisce perché (storage irraggiungibile? file sparito? formato strano?).
    if (!is_string($bytes) || strlen($bytes) < 12) {
        error_log('ARDY PUBBLICA PROGETTO: immagine ' . $g['id'] . ' scartata — byte non leggibili (storage=' . ($g['storage'] ?? '?') . ', file=' . $g['nome_file'] . ')');
        continue;
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
    if (!isset($extMap[$mime])) {
        error_log('ARDY PUBBLICA PROGETTO: immagine ' . $g['id'] . ' scartata — tipo non ammesso (' . $mime . ')');
        continue;
    }
    $galPronte[] = ['mime' => $mime, 'ext' => $extMap[$mime], 'bytes' => $bytes, 'tipo' => $g['tipo'], 'gid' => $g['id']];
}
if ($galleria && !$galPronte) {
    error_log('ARDY PUBBLICA PROGETTO: progetto ' . $progettoId . ' ha ' . count($galleria) . ' immagini in galleria ma nessuna leggibile');
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

// Mappa gid-galleria → id allegato WordPress, tenuta sul post: «aggiorna immagini»
// riusa ciò che è già su WP invece di ricaricare ogni volta le stesse foto (e le
// immagini già allegate restano nell'articolo anche se oggi lo storage non risponde).
$mappa = [];
if ($wpPostIdEsistente > 0) {
    $salvata = get_post_meta($wpPostIdEsistente, '_ardy_galleria_map', true);
    if (is_array($salvata)) $mappa = $salvata;
}
$pronteByGid = [];
foreach ($galPronte as $g) $pronteByGid[(int) $g['gid']] = $g;

$immagini = [];   // [{url, id, tipo}]
$nuove    = 0;
foreach ($galleria as $riga) {                // ordine del racconto: prima → render → foto
    $gid      = (int) $riga['id'];
    $attachId = isset($mappa[$gid]) ? (int) $mappa[$gid] : 0;
    if ($attachId > 0 && !get_post($attachId)) $attachId = 0;   // allegato cancellato a mano su WP

    if ($attachId === 0) {
        $g = $pronteByGid[$gid] ?? null;
        if ($g === null) continue;            // byte non leggibili: già a log qui sopra
        $fname = 'g_' . $gid . '_' . uniqid() . '.' . $g['ext'];
        $fpath = $tmpDir . $fname;
        if (file_put_contents($fpath, $g['bytes']) === false) continue;
        $res = media_handle_sideload([
            'name' => $fname, 'type' => $g['mime'], 'tmp_name' => $fpath, 'error' => 0, 'size' => strlen($g['bytes']),
        ], 0);
        if (is_wp_error($res)) { @unlink($fpath); error_log('ARDY PUBBLICA PROGETTO SIDELOAD: ' . $res->get_error_message()); continue; }
        $attachId = (int) $res;
        $nuove++;
    }
    $mappa[$gid] = $attachId;
    $immagini[]  = ['url' => wp_get_attachment_url($attachId), 'id' => $attachId, 'tipo' => $riga['tipo']];
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

// Blocco immagini: punto di partenza + render + foto finite. Delimitato, così si può
// rigenerare da solo più avanti.
//
// Nel CORPO ci vanno TUTTE, copertina compresa. Prima la copertina veniva saltata per
// non ripeterla — ma il tema del sito l'immagine in evidenza non la stampa nella pagina
// dell'articolo (verificato: niente og:image, niente <img>), quindi su un progetto con
// UNA SOLA immagine in galleria quella veniva esclusa dal corpo e l'articolo usciva
// senza foto. La copertina resta impostata: serve all'elenco del blog e alle anteprime
// sui social, dove il tema la usa davvero.
$prima = ''; $render = ''; $foto = '';
foreach ($immagini as $im) {
    $tag = '<img src="' . esc_url($im['url']) . '" style="max-width:100%;margin:10px 0;border-radius:6px;" />' . "\n";
    if      ($im['tipo'] === 'prima')  $prima  .= $tag;
    elseif  ($im['tipo'] === 'render') $render .= $tag;
    else                               $foto   .= $tag;
}
$blocco = '';
if ($prima  !== '') $blocco .= '<h3 style="font-family:sans-serif;color:#8b6f3e;">Il punto di partenza</h3>' . "\n" . $prima;
if ($render !== '') $blocco .= '<h3 style="font-family:sans-serif;color:#8b6f3e;">Render</h3>' . "\n" . $render;
if ($foto   !== '') $blocco .= '<h3 style="font-family:sans-serif;color:#8b6f3e;">La creazione</h3>' . "\n" . $foto;
$blocco = GAL_START . "\n" . $blocco . GAL_END;

kses_remove_filters(); // contenuto generato da noi: niente strip di style/img

// ── Aggiornamento immagini di un articolo GIÀ pubblicato ─────────────────────
// Sostituisce solo il blocco delimitato: intro e fasi-racconto accodate non si
// toccano. Sui vecchi articoli (senza delimitatori) il blocco si accoda in fondo:
// è l'unico punto sicuro, non sapendo dove finisca l'introduzione.
if ($mode === 'aggiorna_immagini') {
    $post = get_post($wpPostIdEsistente);
    if (!$post) { echo json_encode(['success' => false, 'error' => 'Articolo non trovato su WordPress']); exit(); }

    $vecchio  = (string) $post->post_content;
    $haBlocco = strpos($vecchio, GAL_START) !== false && strpos($vecchio, GAL_END) !== false;
    if ($haBlocco) {
        $inizio  = strpos($vecchio, GAL_START);
        $fine    = strpos($vecchio, GAL_END) + strlen(GAL_END);
        $nuovo   = substr($vecchio, 0, $inizio) . $blocco . substr($vecchio, $fine);
    } else {
        $nuovo = rtrim($vecchio) . "\n" . $blocco;
    }

    $upd = wp_update_post(['ID' => $wpPostIdEsistente, 'post_content' => $nuovo], true);
    if (is_wp_error($upd)) {
        error_log('ARDY PUBBLICA PROGETTO WP UPDATE: ' . $upd->get_error_message());
        echo json_encode(['success' => false, 'error' => 'Aggiornamento non riuscito']); exit();
    }
    update_post_meta($wpPostIdEsistente, '_ardy_galleria_map', $mappa);
    if ($featuredId !== null) set_post_thumbnail($wpPostIdEsistente, $featuredId);
    progettoSalvaImmaginiWp($db, $progettoId, $immagini);

    echo json_encode([
        'success' => true, 'aggiornato' => true, 'wp_post_id' => $wpPostIdEsistente,
        'post_link' => get_permalink($wpPostIdEsistente),
        'immagini' => count($immagini), 'nuove' => $nuove, 'galleria_totale' => count($galleria),
        'in_fondo' => !$haBlocco,
    ]);
    exit();
}

$introHtml = '<div style="font-family:Georgia,serif;font-size:16px;line-height:1.7;color:#444;margin-bottom:24px;">'
    . nl2br(esc_html($testo)) . '</div>';
$content = $introHtml . $blocco;

// Categoria dedicata ai progetti design (override in ardy-config.php).
$catName = defined('ARDY_DESIGN_WP_CAT') ? ARDY_DESIGN_WP_CAT : 'Creazioni';
$catTerm = get_category_by_slug(sanitize_title($catName));
if ($catTerm && !is_wp_error($catTerm)) { $catId = (int) $catTerm->term_id; }
else { $t = wp_insert_term($catName, 'category'); $catId = (!is_wp_error($t)) ? (int) $t['term_id'] : (int) get_option('default_category'); }

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
update_post_meta($wpPostId, '_ardy_galleria_map', $mappa);   // per i futuri «aggiorna immagini»
progettoSalvaImmaginiWp($db, $progettoId, $immagini);
$postLink = get_permalink($wpPostId);

try {
    $db->prepare("UPDATE progetti SET wp_post_id = :wid, wp_post_link = :wlink WHERE id = :id")
       ->execute([':wid' => $wpPostId, ':wlink' => $postLink, ':id' => $progettoId]);
} catch (PDOException $e) {
    error_log('ARDY PUBBLICA PROGETTO DB UPDATE: ' . $e->getMessage());
}

// 'galleria_totale' vs 'immagini': se non coincidono, qualcosa è rimasto fuori e la
// dash lo dice invece di lasciare scoprire l'articolo spoglio sul sito.
echo json_encode(['success' => true, 'wp_post_id' => $wpPostId, 'post_link' => $postLink,
    'immagini' => count($immagini), 'galleria_totale' => count($galleria)]);
