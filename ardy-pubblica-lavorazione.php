<?php
// -----------------------------------------------------------
// ARDY LAB — Pubblica Lavorazione su WordPress
// v2.0 — Ordine caricamento corretto + webhook n8n
// -----------------------------------------------------------

date_default_timezone_set('Europe/Rome');

define('ARDY_WP_LOAD', '/home/micoperibg/public_html/archivio/wp-load.php');

// -----------------------------------------------------------
// 1. CONFIG E DATABASE (nessun conflitto)
// -----------------------------------------------------------
require_once __DIR__ . '/ardy-config.php';
require_once __DIR__ . '/ardy-db.php';
require_once __DIR__ . '/ardy-net.php';
require_once __DIR__ . '/ardy-email.php';
require_once __DIR__ . '/ardy-storage.php';   // pulizia copie bozza su B2 dopo il publish
require_once __DIR__ . '/ardy-img.php';       // WEBP → JPEG: i social non prendono il WEBP

// -----------------------------------------------------------
// 2. CORS E PREFLIGHT (deve rispondere PRIMA di caricare WP)
// -----------------------------------------------------------
header('Access-Control-Allow-Origin: https://ardyagent.ardy-lab.it');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Difesa in profondità: se il Basic Auth a monte (.htaccess) non venisse
// applicato, questo guard rifiuta comunque le richieste non autenticate.
require_once __DIR__ . '/ardy-auth.php';
ardyRequireAuth();

// -----------------------------------------------------------
// 3. INPUT
// -----------------------------------------------------------
$input        = json_decode(file_get_contents('php://input'), true);
$sessionId    = $input['session_id'] ?? '';
$noteBrevi    = $input['note_brevi'] ?? '';
$faseNome     = $input['fase_nome']  ?? '';
$clienteEmail = $input['email']      ?? '';
$clienteNome  = $input['nome']       ?? '';
$mobileTitolo = $input['mobile']     ?? '';
$wpPostId     = !empty($input['wp_post_id']) ? (int)$input['wp_post_id'] : null;
$faseId       = !empty($input['fase_id'])    ? (int)$input['fase_id']    : null; // se presente: pubblica una bozza esistente invece di crearne una nuova
$immagini     = $input['immagini']   ?? [];
$videoUrls    = $input['video_urls'] ?? [];   // URL video già caricati via ardy-upload-video.php
$prezzo       = parseImportoLavorazione((string)($input['prezzo'] ?? '')); // dato interno, non va al cliente

// Tipo di pubblicazione: 'fase' (avanzamento normale) o 'comunicazione' (comunicazione straordinaria)
$tipo = ($input['tipo'] ?? 'fase') === 'comunicazione' ? 'comunicazione' : 'fase';
$isComunicazione = ($tipo === 'comunicazione');
// Per le comunicazioni straordinarie il "titolo fase" è opzionale: usa un default
if ($isComunicazione && trim($faseNome) === '') {
    $faseNome = 'Comunicazione importante';
}

if (empty($sessionId) || empty($noteBrevi)) {
    echo json_encode(['success' => false, 'error' => 'Dati mancanti']);
    exit();
}

// -----------------------------------------------------------
// 4. CARICA WORDPRESS (con fix anti-redirect)
// -----------------------------------------------------------
if (!file_exists(ARDY_WP_LOAD)) {
    echo json_encode(['success' => false, 'error' => 'wp-load.php non trovato']);
    exit();
}

// Impedisce a WordPress di fare redirect al dominio principale
$_SERVER['HTTP_HOST']   = 'ardy-lab.it';
$_SERVER['REQUEST_URI'] = '/';
define('WP_USE_THEMES', false);

require_once ARDY_WP_LOAD;

// -----------------------------------------------------------
// 5. PHPMAILER (DOPO WordPress, con guardia anti-conflitto)
// -----------------------------------------------------------
if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
    require_once __DIR__ . '/phpmailer/src/PHPMailer.php';
    require_once __DIR__ . '/phpmailer/src/SMTP.php';
    require_once __DIR__ . '/phpmailer/src/Exception.php';
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// -----------------------------------------------------------
// 6. SALVA IMMAGINI E CARICA SU WP MEDIA LIBRARY
// -----------------------------------------------------------
$cleanSession = preg_replace('/[^a-zA-Z0-9_\-]/', '', $sessionId);
$sessionDir   = ARDY_UPLOAD_DIR . $cleanSession . '/lavorazioni/';
if (!is_dir($sessionDir) && !mkdir($sessionDir, 0755, true) && !is_dir($sessionDir)) {
    echo json_encode(['success' => false, 'error' => 'Impossibile creare la cartella di upload']);
    exit();
}
ardyHardenUploadDir(rtrim(ARDY_UPLOAD_DIR, '/')); // niente esecuzione script tra gli upload

$savedImageUrls = [];
$savedImageIds  = [];   // ID attachment WP, in parallelo a $savedImageUrls
$allowedMimes   = ['image/jpeg', 'image/png', 'image/webp'];
$maxImmagini    = 15;                 // numero massimo di foto per pubblicazione
$maxByte        = 12 * 1024 * 1024;   // dimensione massima per foto (12 MB)

// Limita il numero di immagini elaborate (evita riempimento disco)
if (is_array($immagini) && count($immagini) > $maxImmagini) {
    $immagini = array_slice($immagini, 0, $maxImmagini);
}

require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

foreach ($immagini as $idx => $imgData) {
    $decoded = base64_decode($imgData, true);
    if (!$decoded) continue;
    if (strlen($decoded) > $maxByte) continue;   // salta foto troppo grandi
    $finfo   = new finfo(FILEINFO_MIME_TYPE);
    $mime    = $finfo->buffer($decoded);
    if (!in_array($mime, $allowedMimes)) continue;
    // WEBP → JPEG: queste foto vanno anche sui social, e Google/Instagram il WEBP
    // non lo accettano (Google risponde 500 senza spiegare). Vedi ardy-img.php.
    $norm    = ardyImgNormalizza($decoded, $mime);
    $decoded = $norm['bytes'];
    $mime    = $norm['mime'];
    $ext     = $norm['ext'];
    $filename = date('Ymd_His') . '_' . uniqid() . '_' . $idx . '.' . $ext;
    $filepath = $sessionDir . $filename;
    if (file_put_contents($filepath, $decoded) === false) continue;

    // Carica su WP Media Library
    $attachment = [
        'post_mime_type' => $mime,
        'post_title'     => sanitize_file_name($filename),
        'post_content'   => '',
        'post_status'    => 'inherit',
    ];
    $wpFile = [
        'name'     => $filename,
        'type'     => $mime,
        'tmp_name' => $filepath,
        'error'    => 0,
        'size'     => filesize($filepath),
    ];
    $attachId = media_handle_sideload($wpFile, 0);
    if (!is_wp_error($attachId)) {
        $savedImageUrls[] = wp_get_attachment_url($attachId);
        $savedImageIds[]  = $attachId;
    } else {
        // Prima questi errori erano silenziosi: ora li logghiamo per diagnosi.
        error_log('ARDY PUBBLICA SIDELOAD ERROR (img ' . $idx . ', mime ' . $mime . '): ' . $attachId->get_error_message());
    }
}
error_log('ARDY PUBBLICA IMG: ricevute=' . (is_array($immagini) ? count($immagini) : 0)
    . ' salvate_su_wp=' . count($savedImageIds));

// -----------------------------------------------------------
// 7. GENERA TESTO CON CLAUDE
// -----------------------------------------------------------
if ($isComunicazione) {
    // Comunicazione straordinaria: spiega un imprevisto con calma e professionalità.
    $testoGenerato = generaTestoComunicazione($noteBrevi, $faseNome, $mobileTitolo);
    $testoSocial   = ''; // una comunicazione al cliente non va sui social
} else {
    $testoGenerato = generaTestoFase($noteBrevi, $faseNome, $mobileTitolo);
    $testoSocial   = generaTestoSocial($noteBrevi, $faseNome, $mobileTitolo);
}

// -----------------------------------------------------------
// 8. COSTRUISCI BLOCCO HTML DELLA FASE
// -----------------------------------------------------------
$dataOra  = date('d/m/Y H:i');

// Copertina FISSA: la prima foto del lavoro diventa l'immagine in evidenza.
// La impostiamo solo se il post non ne ha già una (così resta la prima foto
// in assoluto). Quella foto NON viene ripetuta nell'editor.
$existingThumb   = $wpPostId ? has_post_thumbnail($wpPostId) : false;
$featuredImageId = (!$existingThumb && !empty($savedImageIds)) ? $savedImageIds[0] : null;

$fotoHtml = '';
foreach ($savedImageUrls as $idx => $url) {
    if ($featuredImageId !== null && $idx === 0) continue; // la copertina non va nell'editor
    $fotoHtml .= '<img src="' . esc_url($url) . '" style="max-width:100%;margin:10px 0;border-radius:6px;" />' . "\n";
}

// Video della fase (già caricati su WP Media Library via ardy-upload-video.php).
// Accetta solo URL http(s) per evitare injection nel post.
$videoUrlsClean = [];
if (is_array($videoUrls)) {
    foreach (array_slice($videoUrls, 0, 5) as $vurl) {
        if (is_string($vurl) && preg_match('#^https?://#i', $vurl)) $videoUrlsClean[] = $vurl;
    }
}
$videoHtml = '';
if (!empty($videoUrlsClean)) {
    foreach ($videoUrlsClean as $vurl) {
        $videoHtml .= '<video controls preload="metadata" playsinline '
            . 'style="max-width:100%;margin:10px 0;border-radius:6px;display:block;">'
            . '<source src="' . esc_url($vurl) . '" />'
            . 'Il tuo browser non supporta il video.'
            . '</video>' . "\n";
    }
}

if ($isComunicazione) {
    // Blocco "Comunicazione importante": bordo arancione, icona ⚠, intestazione dedicata
    $nuovoBlocko = '
<div style="border-left:4px solid #e08a3c;padding:16px 20px;margin:24px 0;background:#fff7ef;">
  <p style="font-size:13px;color:#c4701a;margin-bottom:8px;font-family:sans-serif;font-weight:bold;">⚠ Comunicazione importante</p>
  <p style="font-size:12px;color:#999;margin-bottom:8px;font-family:monospace;">' . esc_html($dataOra) . ' — ' . esc_html($faseNome) . '</p>
  <div style="font-size:15px;line-height:1.7;color:#333;">' . nl2br(esc_html($testoGenerato)) . '</div>
  ' . $fotoHtml . $videoHtml . '
</div>';
} else {
    $nuovoBlocko = '
<div style="border-left:3px solid #c8a96e;padding:16px 20px;margin:24px 0;background:#fafaf8;">
  <p style="font-size:12px;color:#999;margin-bottom:8px;font-family:monospace;">' . esc_html($dataOra) . ' — ' . esc_html($faseNome) . '</p>
  <div style="font-size:15px;line-height:1.7;color:#333;">' . nl2br(esc_html($testoGenerato)) . '</div>
  ' . $fotoHtml . $videoHtml . '
</div>';
}

// -----------------------------------------------------------
// 9. CREA O AGGIORNA POST WORDPRESS
// -----------------------------------------------------------
// La categoria "Lavori in corso" pilota anche il permalink pubblico e i
// controlli lato tema (widget chat cliente, pulsante flottante) che si
// basano sullo slug/ID categoria. La cerchiamo per slug invece di un ID
// fisso: se viene ricreata da WP admin con un ID diverso (es. dopo una
// pulizia che l'ha cancellata per sbaglio), l'endpoint si autoallinea
// invece di pubblicare pagine senza categoria (→ link ?p=ID invece che
// /lavori-in-corso/...).
$catSlug = 'lavori-in-corso';
$catTerm = get_category_by_slug($catSlug);
if ($catTerm && !is_wp_error($catTerm)) {
    $categoria = (int) $catTerm->term_id;
} else {
    $newCat   = wp_insert_term('Lavori in corso', 'category', ['slug' => $catSlug]);
    $categoria = !is_wp_error($newCat) ? (int) $newCat['term_id'] : (int) get_option('default_category');
    error_log('ARDY PUBBLICA: categoria "lavori-in-corso" non trovata, ricreata con ID ' . $categoria);
}

// Questo endpoint gira senza utente WordPress loggato: di default WP applica
// kses al contenuto e rimuove <video>/<source>, gli attributi style e può
// alterare gli <img>. Il contenuto qui è generato dal nostro sistema (testo
// Claude + URL immagini/video nostri), quindi disattiviamo il filtro attorno
// all'inserimento per non perdere foto, video e impaginazione.
kses_remove_filters();

if ($wpPostId) {
    $postAttuale      = get_post($wpPostId);
    $contenutoAttuale = $postAttuale ? $postAttuale->post_content : '';
    $nuovoContenuto   = $contenutoAttuale . "\n" . $nuovoBlocko;

    $result = wp_update_post([
        'ID'           => $wpPostId,
        'post_content' => $nuovoContenuto,
    ], true);

    if (is_wp_error($result)) {
        error_log('ARDY PUBBLICA WP UPDATE ERROR: ' . $result->get_error_message());
        echo json_encode(['success' => false, 'error' => 'Aggiornamento della pagina non riuscito']);
        exit();
    }
    $postLink = get_permalink($wpPostId);

} else {
    $titoloPost = 'Lavorazione — ' . $mobileTitolo;
    $introHtml  = '<div style="font-family:Georgia,serif;font-size:16px;line-height:1.7;color:#444;margin-bottom:32px;">Segui qui l\'avanzamento della lavorazione del tuo mobile. Aggiorniamo questa pagina ad ogni fase completata.</div>' . $nuovoBlocko;

    $result = wp_insert_post([
        'post_title'    => $titoloPost,
        'post_content'  => $introHtml,
        'post_status'   => 'publish',
        'post_category' => [$categoria],
    ], true);

    if (is_wp_error($result)) {
        error_log('ARDY PUBBLICA WP INSERT ERROR: ' . $result->get_error_message());
        echo json_encode(['success' => false, 'error' => 'Pubblicazione della pagina non riuscita']);
        exit();
    }

    $wpPostId = $result;
    $postLink = get_permalink($wpPostId);
}

// Riattiva i filtri kses per il resto della richiesta.
kses_init_filters();

if (!$wpPostId) {
    echo json_encode(['success' => false, 'error' => 'Errore pubblicazione WordPress']);
    exit();
}

// Imposta l'immagine in evidenza (copertina per il modulo DIVI Blog in home)
if ($featuredImageId !== null) {
    set_post_thumbnail($wpPostId, $featuredImageId);
}

// -----------------------------------------------------------
// 10. NOTIFICHE AL CLIENTE (email + WhatsApp)
// -----------------------------------------------------------
if ($clienteEmail) {
    // Recupera (o genera) il codice personale del cliente, da includere nell'email.
    $codiceCliente = '';
    try { $codiceCliente = ardy_codice_per_sessione(ardyDB(), $sessionId); }
    catch (Throwable $e) { error_log('ARDY CODICE LAVORAZIONE: ' . $e->getMessage()); }
    inviaEmailCliente($clienteEmail, $clienteNome, $mobileTitolo, $faseNome, $testoGenerato, $postLink, $isComunicazione, $codiceCliente);
}

// WhatsApp al cliente: solo per le FASI normali (non le comunicazioni straordinarie) e solo
// se il template Meta è configurato (fuori dalle 24h serve il template approvato).
// Il telefono non arriva nell'input → lo leggo dal CRM tramite session_id.
if (!$isComunicazione && defined('WA_TEMPLATE_FASI') && WA_TEMPLATE_FASI !== '') {
    try {
        $dbTel = ardyDB();
        $stTel = $dbTel->prepare("SELECT telefono FROM clienti WHERE session_id = :sid LIMIT 1");
        $stTel->execute([':sid' => $sessionId]);
        $telCliente = (string)($stTel->fetchColumn() ?: '');
        if ($telCliente !== '' && inviaWhatsAppCliente($telCliente, $clienteNome, $mobileTitolo, $faseNome, $postLink)) {
            // Registra la notifica in uscita nelle Conversazioni della dash, così
            // l'eventuale risposta del cliente (salvata dal webhook) ha contesto.
            require_once __DIR__ . '/ardy-wa-store.php';
            ardy_wa_save_message($dbTel, $telCliente, 'assistant',
                '📢 Aggiornamento lavorazione: ' . $faseNome . ($postLink !== '' ? "\n" . $postLink : ''));
        }
    } catch (Throwable $e) {
        error_log('ARDY PUBBLICA WA CLIENTE: ' . $e->getMessage());
    }
}

// -----------------------------------------------------------
// 11. SALVA FASE NEL DATABASE
// -----------------------------------------------------------
try {
    $db = ardyDB();
    ardyEnsureFasiStatoOrdine($db);

    // Cattura i nomi delle foto bozza PRIMA di sovrascrivere foto_urls con gli URL WP:
    // servono per ripulire le copie bozza su B2 dopo la pubblicazione.
    $bozzaFotoPrev = [];
    if ($faseId) {
        try {
            $bp = $db->prepare("SELECT foto_urls FROM fasi WHERE id = ? AND session_id = ?");
            $bp->execute([$faseId, $sessionId]);
            $bozzaFotoPrev = json_decode((string) $bp->fetchColumn() ?: '[]', true) ?: [];
        } catch (PDOException $e) { /* best effort */ }
    }

    if ($faseId) {
        // Pubblica una bozza pre-compilata da template: aggiorna la riga esistente
        // invece di crearne una nuova, mantenendo il suo "ordine" di lavoro.
        $db->prepare(
            "UPDATE fasi SET fase_nome=:fase, fase_tipo=:tipo, testo_breve=:breve, testo_generato=:generato,
                testo_social=:social, foto_urls=:foto, video_urls=:video, stato='pubblicata', prezzo=:prezzo
             WHERE id=:id AND session_id=:sid"
        )->execute([
            ':fase'     => $faseNome,
            ':tipo'     => $tipo,
            ':breve'    => $noteBrevi,
            ':generato' => $testoGenerato,
            ':social'   => $testoSocial,
            ':foto'     => json_encode($savedImageUrls),
            ':video'    => json_encode($videoUrlsClean),
            ':prezzo'   => $prezzo,
            ':id'       => $faseId,
            ':sid'      => $sessionId,
        ]);
    } else {
        $db->prepare("INSERT INTO fasi (session_id, fase_nome, fase_tipo, testo_breve, testo_generato, testo_social, foto_urls, video_urls, prezzo) VALUES (:sid, :fase, :tipo, :breve, :generato, :social, :foto, :video, :prezzo)")
           ->execute([
               ':sid'      => $sessionId,
               ':fase'     => $faseNome,
               ':tipo'     => $tipo,
               ':breve'    => $noteBrevi,
               ':generato' => $testoGenerato,
               ':social'   => $testoSocial,
               ':foto'     => json_encode($savedImageUrls),
               ':video'    => json_encode($videoUrlsClean),
               ':prezzo'   => $prezzo,
           ]);
    }
} catch (PDOException $e) {
    error_log('ARDY SALVA FASE ERROR: ' . $e->getMessage());
}

// Pubblicata una bozza: pulisce la cartella temporanea delle sue foto
// (ora sono su WP e in foto_urls definitivo). Niente file orfani su disco.
if ($faseId && defined('ARDY_UPLOAD_DIR')) {
    $bozzaDir = rtrim(ARDY_UPLOAD_DIR, '/') . '/' . $cleanSession . '/fasi-bozza/' . (int) $faseId . '/';
    if (is_dir($bozzaDir)) {
        foreach (glob($bozzaDir . '*') as $f) { if (is_file($f)) @unlink($f); }
        @rmdir($bozzaDir);
    }
    // Copie bozza su B2 (il glob non le vede): cancellale dai nomi salvati prima del publish.
    if (ardyB2Configured()) {
        foreach ($bozzaFotoPrev as $fn) {
            $fn = basename((string) $fn);
            if ($fn !== '' && preg_match('/^[A-Za-z0-9_.\-]+$/', $fn)) {
                ardyStorageDelete('clienti/' . $cleanSession . '/fasi-bozza/' . (int) $faseId . '/' . $fn);
            }
        }
    }
}

// -----------------------------------------------------------
// 11b. AGGIORNA CRM
// -----------------------------------------------------------
try {
    $db = ardyDB();
    $db->prepare("UPDATE clienti SET wp_post_id=:wid, wp_post_link=:wlink, updated_at=NOW() WHERE session_id=:sid")
       ->execute([':wid' => (string)$wpPostId, ':wlink' => $postLink, ':sid' => $sessionId]);
} catch (PDOException $e) {
    error_log('ARDY CRM UPDATE ERROR: ' . $e->getMessage());
}

// -----------------------------------------------------------
// 12. RISPOSTA
// NB: la pubblicazione sui social NON è più automatica.
// Avviene come passo separato e manuale dalla dashboard
// (vedi ardy-pubblica-social.php), così Michela può rivedere,
// modificare, posticipare o saltare il post.
// -----------------------------------------------------------
echo json_encode([
    'success'      => true,
    'tipo'         => $tipo,
    'wp_post_id'   => (string)$wpPostId,
    'post_link'    => $postLink,
    'testo'        => $testoGenerato,
    'testo_social' => $testoSocial,
    'immagini'     => $savedImageUrls,
    'video_urls'   => $videoUrlsClean,
    'fase'         => $faseNome,
    'mobile'       => $mobileTitolo,
    'cliente'      => $clienteNome,
]);

// ============================================================
// FUNZIONI
// ============================================================

/** "350,00" / "350.00" → float, oppure null se vuoto/non numerico. */
function parseImportoLavorazione(string $s): ?float {
    $s = trim($s);
    if ($s === '') return null;
    $s = preg_replace('/[^\d,.\-]/', '', $s);
    if ($s === '') return null;
    if (strpos($s, ',') !== false && strpos($s, '.') !== false) {
        if (strrpos($s, ',') > strrpos($s, '.')) { $s = str_replace('.', '', $s); $s = str_replace(',', '.', $s); }
        else { $s = str_replace(',', '', $s); }
    } elseif (strpos($s, ',') !== false) {
        $s = str_replace(',', '.', $s);
    }
    return is_numeric($s) ? (float)$s : null;
}

function generaTestoFase(string $noteBrevi, string $faseNome, string $mobile): string {
    $prompt = "Sei un esperto restauratore di mobili antichi che scrive aggiornamenti per i clienti. 
Scrivi un paragrafo professionale e caldo (max 120 parole) che descrive questa fase di lavorazione:

Mobile: $mobile
Fase: $faseNome
Note tecniche di Michela: $noteBrevi

Il tono deve essere artigianale, competente e rassicurante. Non usare elenchi puntati. Solo testo fluido.";

    $payload = json_encode([
        'model'      => 'claude-sonnet-4-6',
        'max_tokens' => 300,
        'messages'   => [['role' => 'user', 'content' => $prompt]]
    ]);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt($ch, CURLOPT_POST,           true);
    curl_setopt($ch, CURLOPT_POSTFIELDS,     $payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT,        60);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'x-api-key: ' . ARDY_API_KEY,
        'anthropic-version: 2023-06-01'
    ]);
    $res      = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode !== 200) {
        error_log('ARDY GENERA TESTO FASE: HTTP ' . $httpCode);
    }
    $data = json_decode($res, true);
    return $data['content'][0]['text'] ?? $noteBrevi;
}

// Sanifica un valore per i parametri {{n}} dei template (Meta vieta a-capo/tab/4+ spazi).
function ardy_wa_param_clean(string $t): string {
    $t = str_replace(["\r\n", "\r", "\n", "\t"], ' ', $t);
    $t = preg_replace('/ {2,}/', ' ', $t);
    return trim($t);
}

// Notifica WhatsApp al cliente sull'avanzamento di una fase.
// Usa il template Meta a 4 variabili WA_TEMPLATE_FASI:
//   {{1}} nome · {{2}} mobile/oggetto · {{3}} fase · {{4}} link pagina lavorazione
function inviaWhatsAppCliente(string $telefono, string $nome, string $mobile, string $fase, string $link): bool {
    if (!defined('WA_TOKEN') || !defined('WA_PHONE_NUMBER_ID') || WA_TOKEN === '' || WA_PHONE_NUMBER_ID === '') {
        error_log('ARDY PUBBLICA WA CLIENTE: config WA mancante');
        return false;
    }
    $to = preg_replace('/\D+/', '', $telefono);
    if ($to === '') return false;
    if (strlen($to) === 10 && $to[0] === '3') $to = '39' . $to; // numeri IT salvati senza prefisso

    $pNome   = ardy_wa_param_clean($nome)   ?: 'cliente';
    $pMobile = ardy_wa_param_clean($mobile) ?: 'lavoro';
    $pFase   = ardy_wa_param_clean($fase)   ?: 'aggiornamento';
    $pLink   = ardy_wa_param_clean($link);

    $payload = [
        'messaging_product' => 'whatsapp',
        'to'                => $to,
        'type'              => 'template',
        'template'          => [
            'name'       => WA_TEMPLATE_FASI,
            'language'   => ['code' => defined('WA_TEMPLATE_LANG') && WA_TEMPLATE_LANG !== '' ? WA_TEMPLATE_LANG : 'it'],
            'components'  => [[
                'type'       => 'body',
                'parameters' => [
                    ['type' => 'text', 'text' => $pNome],
                    ['type' => 'text', 'text' => $pMobile],
                    ['type' => 'text', 'text' => $pFase],
                    ['type' => 'text', 'text' => $pLink],
                ],
            ]],
        ],
    ];

    $ch = curl_init('https://graph.facebook.com/v21.0/' . rawurlencode((string)WA_PHONE_NUMBER_ID) . '/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . WA_TOKEN],
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($err || $code >= 300) {
        error_log('ARDY PUBBLICA WA CLIENTE ERROR: http=' . $code . ' err=' . $err . ' resp=' . $resp);
        return false;
    }
    return true;
}

// Recupera il codice personale del cliente (ARD-XXXX-XXXX); se manca lo genera
// e lo salva, così ogni cliente in lavorazione ne ha uno stabile da usare in chat.
if (!function_exists('ardy_codice_per_sessione')) {
function ardy_codice_per_sessione(PDO $db, string $sessionId): string {
    $sessionId = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $sessionId);
    if ($sessionId === '') return '';
    try {
        $sel = $db->prepare("SELECT codice_accesso FROM clienti WHERE session_id = :sid LIMIT 1");
        $sel->execute([':sid' => $sessionId]);
        $codice = trim((string) $sel->fetchColumn());
        if ($codice !== '') return $codice;
        // genera un codice unico (alfabeto senza caratteri ambigui)
        $alpha = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $n = strlen($alpha);
        for ($t = 0; $t < 5; $t++) {
            $out = '';
            for ($i = 0; $i < 8; $i++) { $out .= $alpha[random_int(0, $n - 1)]; if ($i === 3) $out .= '-'; }
            $cand = 'ARD-' . $out;
            $chk = $db->prepare("SELECT 1 FROM clienti WHERE codice_accesso = :c LIMIT 1");
            $chk->execute([':c' => $cand]);
            if (!$chk->fetchColumn()) {
                $db->prepare("UPDATE clienti SET codice_accesso = :c, updated_at = NOW() WHERE session_id = :sid")
                   ->execute([':c' => $cand, ':sid' => $sessionId]);
                return $cand;
            }
        }
    } catch (PDOException $e) {
        error_log('ARDY CODICE PER SESSIONE ERROR: ' . $e->getMessage());
    }
    return '';
}
}

function inviaEmailCliente(string $email, string $nome, string $mobile, string $fase, string $testo, string $link, bool $isComunicazione = false, string $codice = ''): void {
    // Oggetto con prefisso "Ardy Lab —"; accento colore e intestazione cambiano per le comunicazioni
    $subject   = $isComunicazione
        ? 'Ardy Lab — ⚠ Aggiornamento importante sulla tua lavorazione — ' . $mobile
        : 'Ardy Lab — 🪵 Aggiornamento lavorazione — ' . $mobile;
    $accent    = $isComunicazione ? '#e08a3c' : '#c8a96e';
    $kicker    = $isComunicazione ? 'Comunicazione importante' : 'Aggiornamento lavorazione';
    $ctaLabel  = $isComunicazione ? 'Leggi sulla pagina della lavorazione →' : 'Vedi la lavorazione completa →';
    $saluto    = trim($nome) !== '' ? 'Ciao ' . htmlspecialchars($nome) . ',' : 'Ciao,';

    // "Come funziona la pagina": spiega al cliente cosa può fare con il link.
    // Per le comunicazioni straordinarie il taglio è diverso (deve leggere/approvare),
    // per le fasi normali invita a seguire l'avanzamento e le foto.
    $comeFunziona = $isComunicazione
        ? '
  <p style="font-size:14px;line-height:1.7;color:#555;margin:18px 0 0;">Apri la pagina della lavorazione qui sopra per leggere tutti i dettagli. Se serve una tua conferma per procedere, ti basta <strong>rispondere a questa email</strong> o scrivere a Sole in chat: ti ricontattiamo noi.</p>'
        : '
  <p style="font-size:14px;line-height:1.7;color:#555;margin:18px 0 0;"><strong>Cosa trovi nella pagina.</strong> Aprendo il link qui sopra segui l\'avanzamento del tuo mobile passo dopo passo: aggiungiamo le <strong>foto</strong> e una descrizione a ogni fase completata, così puoi vedere come procede il lavoro comodamente da casa. La pagina resta tua e si aggiorna da sola a ogni passaggio.</p>';

    // Footer cliente condiviso: codice personale + WhatsApp + social (helper in ardy-email.php).
    $footerCliente = ardy_email_footer_cliente($codice, $accent);
    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = 'smtp-relay.brevo.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = ARDY_SMTP_USER;
        $mail->Password   = ARDY_SMTP_PASSWORD;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        $mail->CharSet    = 'UTF-8';
        $mail->setFrom('noreply@ardy-lab.it', 'Ardy Lab');
        // From su dominio autenticato (Brevo/DKIM) per la consegna; le risposte
        // del cliente arrivano alla casella reale di Michela.
        $mail->addReplyTo('ardy.documenti@gmail.com', 'Ardy Lab — Michela');
        $mail->addAddress($email, $nome);
        $mail->Subject = $subject;
        $mail->isHTML(true);
        $mail->Body = '
<div style="font-family:Georgia,serif;max-width:600px;margin:0 auto;padding:32px;color:#333;">
  ' . ardy_email_logo_cid($mail) . '
  <p style="color:#999;font-size:13px;margin-bottom:24px;">' . htmlspecialchars($kicker) . '</p>
  <h3 style="font-size:18px;margin-bottom:8px;">' . htmlspecialchars($mobile) . '</h3>
  <p style="font-size:13px;color:#999;margin-bottom:20px;">' . ($isComunicazione ? '' : 'Fase: ') . htmlspecialchars($fase) . '</p>
  <p style="font-size:15px;line-height:1.7;margin-bottom:16px;">' . $saluto . '</p>
  <div style="border-left:3px solid ' . $accent . ';padding:12px 20px;background:' . ($isComunicazione ? '#fff7ef' : '#fafaf8') . ';margin-bottom:28px;">
    ' . nl2br(htmlspecialchars($testo)) . '
  </div>
  <a href="' . htmlspecialchars($link) . '" style="background:' . $accent . ';color:#fff;padding:12px 24px;text-decoration:none;border-radius:4px;font-family:sans-serif;font-size:14px;">
    ' . htmlspecialchars($ctaLabel) . '
  </a>
  ' . $comeFunziona . '
  ' . $footerCliente . '
  <p style="font-size:14px;line-height:1.7;color:#555;margin-top:28px;">Grazie di cuore per la fiducia: ogni fase la curiamo come se il mobile fosse nostro. A presto 🌿</p>
  <p style="margin-top:24px;font-size:12px;color:#bbb;">Ardy Lab — Restauro e laccatura mobili · Roma</p>
</div>';
        $mail->send();
        error_log("ARDY MAIL OK: inviata a " . $email);
    } catch (Exception $e) {
        error_log('ARDY MAIL CLIENTE ERROR: ' . $e->getMessage() . ' - Code: ' . $e->getCode());
    }
}

function generaTestoComunicazione(string $noteBrevi, string $oggetto, string $mobile): string {
    $prompt = "Sei Michela, restauratrice di Ardy Lab (bottega artigianale a Roma). Devi scrivere al cliente una COMUNICAZIONE IMPORTANTE su un imprevisto o una situazione emersa durante la lavorazione del suo mobile. NON è un normale aggiornamento di avanzamento: è qualcosa che il cliente deve sapere, e in molti casi approvare, PRIMA di procedere.

Mobile: $mobile
Oggetto della comunicazione: $oggetto
Cosa è emerso (note di Michela): $noteBrevi

Scrivi un messaggio (max 150 parole) che:
- spiega con chiarezza e onestà cosa è emerso, senza tecnicismi inutili e senza allarmare;
- fa capire perché è importante e quali sono le opzioni o il modo in cui intendete procedere;
- se serve una decisione o un'approvazione del cliente, la chiede con garbo;
- mantiene un tono rassicurante, competente e umano: il cliente deve sentirsi in buone mani.
Non usare elenchi puntati. Solo testo fluido. Non inventare costi o tempi precisi se non indicati nelle note. Non firmare (la firma viene aggiunta a parte).";

    $payload = json_encode([
        'model'      => 'claude-sonnet-4-6',
        'max_tokens' => 400,
        'messages'   => [['role' => 'user', 'content' => $prompt]]
    ]);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt($ch, CURLOPT_POST,           true);
    curl_setopt($ch, CURLOPT_POSTFIELDS,     $payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT,        60);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'x-api-key: ' . ARDY_API_KEY,
        'anthropic-version: 2023-06-01'
    ]);
    $res      = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode !== 200) {
        error_log('ARDY GENERA COMUNICAZIONE: HTTP ' . $httpCode);
    }
    $data = json_decode($res, true);
    return $data['content'][0]['text'] ?? $noteBrevi;
}

function generaTestoSocial(string $noteBrevi, string $faseNome, string $mobile): string {
    $prompt = "Scrivi un post per Instagram/Facebook per Ardy Lab, bottega di restauro mobili a Roma.

Mobile: $mobile
Fase: $faseNome
Note: $noteBrevi

Regole:
- Max 80 parole
- Tono autentico, artigianale, visuale
- Inizia con una frase d'impatto sulla lavorazione
- Chiudi con call to action verso ardy-lab.it
- Aggiungi 8-10 hashtag pertinenti su una riga separata
- Usa max 2 emoji nel testo
- NON usare elenchi puntati";

    $payload = json_encode([
        "model"      => "claude-sonnet-4-6",
        "max_tokens" => 400,
        "messages"   => [["role" => "user", "content" => $prompt]]
    ]);

    $ch = curl_init("https://api.anthropic.com/v1/messages");
    curl_setopt($ch, CURLOPT_POST,           true);
    curl_setopt($ch, CURLOPT_POSTFIELDS,     $payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT,        60);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json",
        "x-api-key: " . ARDY_API_KEY,
        "anthropic-version: 2023-06-01"
    ]);
    $res  = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($res, true);
    return $data["content"][0]["text"] ?? "";
}
