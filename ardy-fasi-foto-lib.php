<?php
// -----------------------------------------------------------
// ARDY LAB — Foto delle fasi dei clienti sul server (passo 2 dell'area cliente)
// Dal 24/11 le foto di una fase non vanno più nella libreria media di WordPress:
// restano su disco in ARDY_UPLOAD_DIR/<sessione>/lavorazioni/ e in fasi.foto_urls si
// salva un indirizzo INTERNO (ardy-fase-foto.php, dietro Basic Auth). Così la dash le
// mostra come prima, l'area /lavoro/ le serve col controllo del cookie del cliente, e
// diventano pubbliche solo quando si sceglie di pubblicarle sui social: in quel momento
// fasiFotoPubbliche() le carica su WordPress (una volta sola). Vedi ANALISI-AREA-CLIENTE.md §3.
// Le fasi pubblicate prima hanno URL di WordPress: restano valide ovunque, non si toccano.
// (File interno: negato all'accesso diretto via .htaccess.)
// -----------------------------------------------------------

require_once __DIR__ . '/ardy-config.php';

const FASI_FOTO_ENDPOINT = 'https://ardyagent.ardy-lab.it/ardy-fase-foto.php';
const FASI_AREA_URL      = 'https://ardyagent.ardy-lab.it/lavoro/';

if (!defined('ARDY_WP_LOAD')) define('ARDY_WP_LOAD', '/home/micoperibg/public_html/archivio/wp-load.php');

function fasiFotoSessione(string $s): string {
    return preg_replace('/[^a-zA-Z0-9_\-]/', '', $s);
}

/** Cartella delle foto pubblicate di un cliente (la stessa di prima del passo 2). */
function fasiFotoDir(string $sessionId): string {
    return rtrim(ARDY_UPLOAD_DIR, '/') . '/' . fasiFotoSessione($sessionId) . '/lavorazioni/';
}

/** Nome file accettabile: niente percorsi, niente file nascosti o di servizio. */
function fasiFotoNomeValido(string $f): bool {
    return (bool) preg_match('/^[A-Za-z0-9][A-Za-z0-9_\-]*\.(jpe?g|png|webp)$/i', $f);
}

/** Indirizzo interno di una foto, quello che si salva in fasi.foto_urls. */
function fasiFotoUrl(string $sessionId, string $file): string {
    return FASI_FOTO_ENDPOINT . '?s=' . rawurlencode(fasiFotoSessione($sessionId)) . '&f=' . rawurlencode($file);
}

/**
 * Se $url è un nostro indirizzo interno, ritorna sessione, file e percorso su disco
 * (il file deve esistere); altrimenti null. Un URL di WordPress (fasi vecchie) dà null.
 */
function fasiFotoDaUrl(string $url): ?array {
    if (strpos($url, FASI_FOTO_ENDPOINT . '?') !== 0) return null;
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
    $s = fasiFotoSessione((string) ($q['s'] ?? ''));
    $f = (string) ($q['f'] ?? '');
    if ($s === '' || !fasiFotoNomeValido($f)) return null;
    $path = fasiFotoDir($s) . $f;
    return is_file($path) ? ['session' => $s, 'file' => $f, 'path' => $path] : null;
}

/** C'è almeno una foto interna nella lista? Chi chiama carica WordPress solo in quel caso. */
function fasiFotoServeWp(array $urls): bool {
    foreach ($urls as $u) if (is_string($u) && fasiFotoDaUrl($u) !== null) return true;
    return false;
}

/**
 * Le foto interne diventano pubbliche: caricate nella libreria media di WordPress, una
 * volta sola (l'URL si ricorda in un file "<foto>.wp" accanto alla foto). Gli altri URL
 * passano invariati. Le foto che non si riescono a caricare si tolgono: un social che
 * riceve un indirizzo protetto da password rifiuterebbe tutto il post.
 * WordPress va caricato PRIMA, nello scope globale di chi chiama (vedi fasiFotoServeWp):
 * wp-load.php incluso dentro una funzione lascerebbe $wpdb e gli altri globali locali.
 */
function fasiFotoPubbliche(array $urls): array {
    $out = [];
    foreach ($urls as $u) {
        if (!is_string($u) || $u === '') continue;
        $int = fasiFotoDaUrl($u);
        if ($int === null) { $out[] = $u; continue; }
        $memo = $int['path'] . '.wp';
        $gia  = is_file($memo) ? trim((string) @file_get_contents($memo)) : '';
        if ($gia !== '') { $out[] = $gia; continue; }
        if (!function_exists('media_handle_sideload')) { error_log('ARDY FASI FOTO: WordPress non caricato'); continue; }
        // Il sideload SPOSTA il file temporaneo: gli si dà una copia, l'originale resta all'area.
        $tmp = sys_get_temp_dir() . '/ardy_fase_' . uniqid() . '_' . $int['file'];
        if (!@copy($int['path'], $tmp)) continue;
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
        $id = media_handle_sideload(['name' => $int['file'], 'type' => $mime, 'tmp_name' => $tmp, 'error' => 0, 'size' => filesize($tmp)], 0);
        if (is_wp_error($id)) {
            @unlink($tmp);
            error_log('ARDY FASI FOTO SIDELOAD: ' . $id->get_error_message());
            continue;
        }
        $pub = (string) wp_get_attachment_url($id);
        if ($pub !== '') { @file_put_contents($memo, $pub); $out[] = $pub; }
    }
    return $out;
}

/** Link all'area del cliente col codice già dentro: il cliente non lo deve digitare. */
function fasiLinkArea(string $codice): string {
    return $codice !== '' ? FASI_AREA_URL . '?codice=' . rawurlencode($codice) : FASI_AREA_URL;
}
