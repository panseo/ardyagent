<?php
// -----------------------------------------------------------
// ARDY LAB — "Racconta": pubblica la pagina di un pezzo su WordPress
// Una pagina per pezzo, nella categoria lavori-in-corso (come le lavorazioni dei
// clienti) con in più il tag `storia-pezzo`: è quel tag che dice al widget di aprire
// direttamente la chat di Sole, senza chiedere "sei il cliente?".
//
//   POST {pezzo_id, fase_id?, conferma?}
//     - crea la pagina se non c'è (titolo + introduzione + scheda);
//     - riscrive sempre il blocco introduzione/scheda (delimitato da marcatori);
//     - con fase_id aggiunge il capitolo, o lo SOSTITUISCE se era già pubblicato
//       (marcatori <!-- ardy-fase-ID -->, come ardy-pubblica-fase-progetto.php);
//     - toglie i blocchi dei capitoli eliminati dalla mini-app;
//     - scrive pezzi.pubblico_json, l'istantanea di ciò che è sul sito: è l'unica
//       cosa che la Sole del sito legge (ardy-pezzi-lib.php).
//   Se il testo da pubblicare contiene dati sospetti (telefono, prezzi, nomi…) e non
//   c'è conferma:true, risponde {serve_conferma:true, avvisi[]} senza pubblicare.
//
// Nessun social e nessuna notifica: la pubblicazione sugli altri canali è un passo
// separato e manuale (Fase 2 del piano). Nessun cliente da avvisare: il pezzo non ne ha.
// Protetto via .htaccess (Basic Auth) — elencato nel <FilesMatch>.
// -----------------------------------------------------------

date_default_timezone_set('Europe/Rome');
define('ARDY_WP_LOAD', '/home/micoperibg/public_html/archivio/wp-load.php');

require_once __DIR__ . '/ardy-db.php';
require_once __DIR__ . '/ardy-net.php';
require_once __DIR__ . '/ardy-pezzi-lib.php';

header('Access-Control-Allow-Origin: https://ardyagent.ardy-lab.it');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }
// Difesa in profondità: se il Basic Auth a monte (.htaccess) non venisse
// applicato, questo guard rifiuta comunque le richieste non autenticate.
require_once __DIR__ . '/ardy-auth.php';
ardyRequireAuth();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'error' => 'Metodo non valido']); exit(); }
set_time_limit(180);

const INTRO_START = '<!--ardy-pezzo-intro-->';
const INTRO_END   = '<!--/ardy-pezzo-intro-->';

$in       = json_decode(file_get_contents('php://input'), true) ?: [];
$pezzoId  = (int) ($in['pezzo_id'] ?? 0);
$faseId   = (int) ($in['fase_id'] ?? 0);
$conferma = !empty($in['conferma']);

try {
    $db = ardyDB();
    $st = $db->prepare("SELECT id, titolo, scheda, storia, wp_post_id, pubblico_json FROM pezzi WHERE id = ? AND deleted_at IS NULL");
    $st->execute([$pezzoId]);
    $pezzo = $st->fetch(PDO::FETCH_ASSOC);
    if (!$pezzo) { echo json_encode(['success' => false, 'error' => 'Pezzo non trovato']); exit(); }
    $cs = $db->prepare("SELECT id, fase_nome, testo_generato, foto_urls, stato, wp_pubblicata_at, created_at
                          FROM fasi WHERE pezzo_id = ? ORDER BY ordine ASC, created_at ASC");
    $cs->execute([$pezzoId]);
    $capitoli = $cs->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('ARDY PUBBLICA PEZZO DB: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Errore interno']); exit();
}

$titolo = trim((string) $pezzo['titolo']);
$scheda = pezziSchedaNorm($pezzo['scheda']);
$storia = trim((string) $pezzo['storia']);
if ($titolo === '' || $titolo === 'Nuovo pezzo') { echo json_encode(['success' => false, 'error' => 'Dai prima un titolo al pezzo']); exit(); }

$capitolo = null;
if ($faseId > 0) {
    foreach ($capitoli as $c) { if ((int) $c['id'] === $faseId) $capitolo = $c; }
    if (!$capitolo) { echo json_encode(['success' => false, 'error' => 'Capitolo non trovato']); exit(); }
    if (trim((string) $capitolo['testo_generato']) === '') { echo json_encode(['success' => false, 'error' => 'Il capitolo non ha ancora un testo: fallo scrivere a Sole o scrivilo tu']); exit(); }
}

// Seconda rete sull'anonimato, su tutto ciò che sta per andare online.
$daControllare = $titolo . "\n" . $storia . "\n" . implode("\n", $scheda)
    . ($capitolo ? "\n" . $capitolo['fase_nome'] . "\n" . $capitolo['testo_generato'] : '');
$avvisi = pezziAvvisiPrivacy($daControllare);
if ($avvisi && !$conferma) {
    echo json_encode(['success' => false, 'serve_conferma' => true, 'avvisi' => $avvisi]); exit();
}

// Byte delle foto letti PRIMA di caricare WordPress (stesso motivo di
// ardy-pubblica-fase-progetto.php: dentro l'ambiente WP le letture esterne falliscono).
$fotoPronte = [];
$fotoAttese = 0;
if ($capitolo) {
    foreach (array_slice(json_decode($capitolo['foto_urls'] ?? '[]', true) ?: [], 0, 12) as $fn) {
        $fotoAttese++;
        $path = pezziFotoDir($pezzoId, $faseId) . basename((string) $fn);
        $bytes = is_file($path) ? @file_get_contents($path) : false;
        if (!is_string($bytes) || strlen($bytes) < 12) { error_log('ARDY PUBBLICA PEZZO: foto assente fase=' . $faseId . ' file=' . $fn); continue; }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (!in_array($mime, ['image/jpeg', 'image/png'], true)) continue;
        $fotoPronte[] = ['mime' => $mime, 'ext' => $mime === 'image/png' ? 'png' : 'jpg', 'bytes' => $bytes];
    }
}

if (!file_exists(ARDY_WP_LOAD)) { echo json_encode(['success' => false, 'error' => 'WordPress non raggiungibile']); exit(); }
$_SERVER['HTTP_HOST']   = 'ardy-lab.it';
$_SERVER['REQUEST_URI'] = '/';
if (!defined('WP_USE_THEMES')) define('WP_USE_THEMES', false);
require_once ARDY_WP_LOAD;
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

/** Testo con righe vuote → paragrafi, già escapato. */
function pezzoParagrafi(string $t): string {
    $out = '';
    foreach (preg_split("/\n\s*\n/", trim($t)) as $p) {
        if (trim($p) !== '') $out .= '<p>' . nl2br(esc_html(trim($p))) . '</p>';
    }
    return $out;
}

// ── Blocco introduzione + scheda ──
$righeScheda = '';
foreach (PEZZI_SCHEDA_CAMPI as $k => $label) {
    if ($scheda[$k] === '') continue;
    $righeScheda .= '<tr><td style="padding:6px 14px 6px 0;color:#8b6f3e;white-space:nowrap;vertical-align:top;">' . esc_html($label)
        . '</td><td style="padding:6px 0;color:#444;">' . esc_html($scheda[$k]) . '</td></tr>';
}
$intro = INTRO_START
    . '<div style="font-family:Georgia,serif;font-size:16px;line-height:1.7;color:#444;margin-bottom:24px;">' . pezzoParagrafi($storia) . '</div>'
    . ($righeScheda !== '' ? '<table style="font-size:14px;margin:0 0 32px;border-collapse:collapse;">' . $righeScheda . '</table>' : '')
    . INTRO_END;

kses_remove_filters();   // contenuto generato da noi: niente strip di style/img

$wpPostId = (int) ($pezzo['wp_post_id'] ?? 0);
$post     = $wpPostId > 0 ? get_post($wpPostId) : null;
if (!$post) {
    $catTerm = get_category_by_slug('lavori-in-corso');
    if ($catTerm && !is_wp_error($catTerm)) { $catId = (int) $catTerm->term_id; }
    else {
        $t = wp_insert_term('Lavori in corso', 'category', ['slug' => 'lavori-in-corso']);
        $catId = !is_wp_error($t) ? (int) $t['term_id'] : (int) get_option('default_category');
    }
    $res = wp_insert_post([
        'post_title'    => $titolo,
        'post_content'  => $intro,
        'post_status'   => 'publish',
        'post_category' => [$catId],
    ], true);
    if (is_wp_error($res)) {
        error_log('ARDY PUBBLICA PEZZO WP INSERT: ' . $res->get_error_message());
        echo json_encode(['success' => false, 'error' => 'Creazione della pagina non riuscita']); exit();
    }
    $wpPostId = (int) $res;
    $post     = get_post($wpPostId);
    // Il tag che distingue la pagina di un pezzo da quella di un cliente (widget + snippet).
    $tag = term_exists('storia-pezzo', 'post_tag');
    if (!$tag) $tag = wp_insert_term('Storia di un pezzo', 'post_tag', ['slug' => 'storia-pezzo']);
    if (!is_wp_error($tag)) wp_set_post_terms($wpPostId, [(int) $tag['term_id']], 'post_tag', true);
}

$content = (string) $post->post_content;

// Introduzione: sostituita sul posto (o messa in testa, se mancano i marcatori).
$a = strpos($content, INTRO_START);
$b = $a !== false ? strpos($content, INTRO_END, $a) : false;
$content = ($a !== false && $b !== false)
    ? substr($content, 0, $a) . $intro . substr($content, $b + strlen(INTRO_END))
    : $intro . "\n" . $content;

// Capitoli eliminati dalla mini-app: i loro blocchi spariscono dalla pagina.
$idVivi = array_map(fn($c) => (int) $c['id'], $capitoli);
if (preg_match_all('/<!-- ardy-fase-(\d+) -->/', $content, $m)) {
    foreach (array_unique($m[1]) as $idBlocco) {
        if (in_array((int) $idBlocco, $idVivi, true)) continue;
        $s = strpos($content, '<!-- ardy-fase-' . $idBlocco . ' -->');
        $e = strpos($content, '<!-- /ardy-fase-' . $idBlocco . ' -->', $s);
        if ($s !== false && $e !== false) $content = substr($content, 0, $s) . substr($content, $e + strlen('<!-- /ardy-fase-' . $idBlocco . ' -->'));
    }
}

$fotoPubblicate = 0;
$fotoUrlPubbliche = [];
if ($capitolo) {
    $tmpDir = rtrim(ARDY_UPLOAD_DIR, '/') . '/pezzi/' . $pezzoId . '/_wp_tmp/';
    if (!is_dir($tmpDir)) @mkdir($tmpDir, 0755, true);
    $fotoHtml = '';
    foreach ($fotoPronte as $f) {
        $tname = 'f_' . uniqid() . '.' . $f['ext'];
        if (file_put_contents($tmpDir . $tname, $f['bytes']) === false) continue;
        $attachId = media_handle_sideload(['name' => $tname, 'type' => $f['mime'], 'tmp_name' => $tmpDir . $tname, 'error' => 0, 'size' => strlen($f['bytes'])], $wpPostId);
        if (is_wp_error($attachId)) { @unlink($tmpDir . $tname); error_log('ARDY PUBBLICA PEZZO SIDELOAD: ' . $attachId->get_error_message()); continue; }
        $url = (string) wp_get_attachment_url($attachId);
        $fotoHtml .= '<img src="' . esc_url($url) . '" alt="' . esc_attr($titolo) . '" style="max-width:100%;margin:10px 0;border-radius:6px;" />' . "\n";
        $fotoUrlPubbliche[] = $url;
        $fotoPubblicate++;
        if (!has_post_thumbnail($wpPostId)) set_post_thumbnail($wpPostId, $attachId);
    }
    foreach (glob($tmpDir . '*') ?: [] as $tmpf) { if (is_file($tmpf)) @unlink($tmpf); }
    @rmdir($tmpDir);

    $mStart = '<!-- ardy-fase-' . $faseId . ' -->';
    $mEnd   = '<!-- /ardy-fase-' . $faseId . ' -->';
    $data   = date('d/m/Y', strtotime((string) $capitolo['created_at']) ?: time());
    $blocco = $mStart . '
<div style="border-left:3px solid #c8a96e;padding:16px 20px;margin:24px 0;background:#fafaf8;">
  <p style="font-size:12px;color:#999;margin-bottom:8px;font-family:monospace;">' . esc_html($data)
    . (trim((string) $capitolo['fase_nome']) !== '' ? ' — ' . esc_html(trim((string) $capitolo['fase_nome'])) : '') . '</p>'
    . '<div style="font-size:15px;line-height:1.7;color:#333;">' . pezzoParagrafi((string) $capitolo['testo_generato']) . '</div>'
    . $fotoHtml . '
</div>' . $mEnd;

    $s = strpos($content, $mStart);
    $e = $s !== false ? strpos($content, $mEnd, $s) : false;
    $content = ($s !== false && $e !== false)
        ? substr($content, 0, $s) . $blocco . substr($content, $e + strlen($mEnd))
        : $content . "\n" . $blocco;
}

$res = wp_update_post(['ID' => $wpPostId, 'post_title' => $titolo, 'post_content' => $content], true);
kses_init_filters();
if (is_wp_error($res)) {
    error_log('ARDY PUBBLICA PEZZO WP UPDATE: ' . $res->get_error_message());
    echo json_encode(['success' => false, 'error' => 'Aggiornamento della pagina non riuscito']); exit();
}
$postLink = get_permalink($wpPostId);

// Il capitolo risulta pubblicato (✓ in mini-app) solo se tutte le foto sono arrivate:
// altrimenti resta da ripubblicare, e la ripubblicazione è idempotente.
$completo = $capitolo ? ($fotoPubblicate >= $fotoAttese) : true;

// Istantanea pubblica per Sole: per ogni capitolo, il testo che sta DAVVERO sulla pagina.
// Un capitolo modificato ma non ripubblicato tiene il testo dell'istantanea precedente.
$prec = [];
foreach ((json_decode((string) $pezzo['pubblico_json'], true)['capitoli'] ?? []) as $c) $prec[(int) ($c['id'] ?? 0)] = $c;
$capPubblici = [];
foreach ($capitoli as $c) {
    $cid = (int) $c['id'];
    $voce = ['id' => $cid, 'data' => date('d/m/Y', strtotime((string) $c['created_at']) ?: time()),
             'titolo' => trim((string) $c['fase_nome']), 'testo' => trim((string) $c['testo_generato'])];
    if ($cid === $faseId || $c['wp_pubblicata_at'] !== null) $capPubblici[] = $voce;
    elseif (isset($prec[$cid]))                               $capPubblici[] = $prec[$cid];
}
$istantanea = ['titolo' => $titolo, 'scheda' => $scheda, 'storia' => $storia, 'link' => $postLink, 'capitoli' => $capPubblici];

try {
    $db->prepare("UPDATE pezzi SET wp_post_id = ?, wp_post_link = ?, pubblico_json = ? WHERE id = ?")
       ->execute([$wpPostId, $postLink, json_encode($istantanea, JSON_UNESCAPED_UNICODE), $pezzoId]);
    if ($capitolo) {
        $db->prepare("UPDATE fasi SET stato = 'pubblicata', wp_pubblicata_at = " . ($completo ? 'NOW()' : 'NULL') . ", foto_wp_urls = ? WHERE id = ? AND pezzo_id = ?")
           ->execute([$fotoUrlPubbliche ? json_encode($fotoUrlPubbliche, JSON_UNESCAPED_SLASHES) : null, $faseId, $pezzoId]);
    }
} catch (PDOException $e) {
    error_log('ARDY PUBBLICA PEZZO DB UPD: ' . $e->getMessage());
}

$resp = ['success' => true, 'post_link' => $postLink, 'pubblicata' => $completo];
if (!$completo) {
    $resp['foto_warning'] = 'Testo pubblicato, ma ' . ($fotoAttese - $fotoPubblicate) . ' di ' . $fotoAttese
        . ' foto non sono arrivate sulla pagina. Riprova fra poco.';
}
echo json_encode($resp);
