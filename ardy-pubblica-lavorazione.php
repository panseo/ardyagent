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
$immagini     = $input['immagini']   ?? [];
$videoUrls    = $input['video_urls'] ?? [];   // URL video già caricati via ardy-upload-video.php

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
    $ext      = $mime === 'image/png' ? 'png' : ($mime === 'image/webp' ? 'webp' : 'jpg');
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
    }
}

// -----------------------------------------------------------
// 7. GENERA TESTO CON CLAUDE
// -----------------------------------------------------------
$testoGenerato = generaTestoFase($noteBrevi, $faseNome, $mobileTitolo);
$testoSocial = generaTestoSocial($noteBrevi, $faseNome, $mobileTitolo);

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

$nuovoBlocko = '
<div style="border-left:3px solid #c8a96e;padding:16px 20px;margin:24px 0;background:#fafaf8;">
  <p style="font-size:12px;color:#999;margin-bottom:8px;font-family:monospace;">' . esc_html($dataOra) . ' — ' . esc_html($faseNome) . '</p>
  <div style="font-size:15px;line-height:1.7;color:#333;">' . nl2br(esc_html($testoGenerato)) . '</div>
  ' . $fotoHtml . $videoHtml . '
</div>';

// -----------------------------------------------------------
// 9. CREA O AGGIORNA POST WORDPRESS
// -----------------------------------------------------------
$categoria = 102;

if ($wpPostId) {
    $postAttuale      = get_post($wpPostId);
    $contenutoAttuale = $postAttuale ? $postAttuale->post_content : '';
    $nuovoContenuto   = $contenutoAttuale . "\n" . $nuovoBlocko;

    $result = wp_update_post([
        'ID'           => $wpPostId,
        'post_content' => $nuovoContenuto,
    ], true);

    if (is_wp_error($result)) {
        echo json_encode(['success' => false, 'error' => $result->get_error_message()]);
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
        echo json_encode(['success' => false, 'error' => $result->get_error_message()]);
        exit();
    }

    $wpPostId = $result;
    $postLink = get_permalink($wpPostId);
}

if (!$wpPostId) {
    echo json_encode(['success' => false, 'error' => 'Errore pubblicazione WordPress']);
    exit();
}

// Imposta l'immagine in evidenza (copertina per il modulo DIVI Blog in home)
if ($featuredImageId !== null) {
    set_post_thumbnail($wpPostId, $featuredImageId);
}

// -----------------------------------------------------------
// 10. EMAIL AL CLIENTE
// -----------------------------------------------------------
if ($clienteEmail) {
    inviaEmailCliente($clienteEmail, $clienteNome, $mobileTitolo, $faseNome, $testoGenerato, $postLink);
}

// -----------------------------------------------------------
// 11. SALVA FASE NEL DATABASE
// -----------------------------------------------------------
try {
    $db = ardyDB();
    // Migrazione idempotente: assicura la colonna video_urls sulla tabella fasi.
    $hasCol = $db->query("SHOW COLUMNS FROM fasi LIKE 'video_urls'")->fetch();
    if (!$hasCol) {
        $db->exec("ALTER TABLE fasi ADD COLUMN video_urls TEXT NULL AFTER foto_urls");
    }
    $db->prepare("INSERT INTO fasi (session_id, fase_nome, testo_breve, testo_generato, foto_urls, video_urls) VALUES (:sid, :fase, :breve, :generato, :foto, :video)")
       ->execute([
           ':sid'      => $sessionId,
           ':fase'     => $faseNome,
           ':breve'    => $noteBrevi,
           ':generato' => $testoGenerato,
           ':foto'     => json_encode($savedImageUrls),
           ':video'    => json_encode($videoUrlsClean),
       ]);
} catch (PDOException $e) {
    error_log('ARDY SALVA FASE ERROR: ' . $e->getMessage());
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

function inviaEmailCliente(string $email, string $nome, string $mobile, string $fase, string $testo, string $link): void {
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
        $mail->addAddress($email, $nome);
        $mail->Subject = '🪵 Aggiornamento lavorazione — ' . $mobile;
        $mail->isHTML(true);
        $mail->Body = '
<div style="font-family:Georgia,serif;max-width:600px;margin:0 auto;padding:32px;color:#333;">
  <h2 style="font-family:sans-serif;color:#c8a96e;font-size:20px;margin-bottom:4px;">Ardy Lab</h2>
  <p style="color:#999;font-size:13px;margin-bottom:24px;">Aggiornamento lavorazione</p>
  <h3 style="font-size:18px;margin-bottom:8px;">' . htmlspecialchars($mobile) . '</h3>
  <p style="font-size:13px;color:#999;margin-bottom:20px;">Fase: ' . htmlspecialchars($fase) . '</p>
  <div style="border-left:3px solid #c8a96e;padding:12px 20px;background:#fafaf8;margin-bottom:28px;">
    ' . nl2br(htmlspecialchars($testo)) . '
  </div>
  <a href="' . htmlspecialchars($link) . '" style="background:#c8a96e;color:#fff;padding:12px 24px;text-decoration:none;border-radius:4px;font-family:sans-serif;font-size:14px;">
    Vedi la lavorazione completa →
  </a>
  <p style="margin-top:32px;font-size:12px;color:#bbb;">Ardy Lab — Restauro e laccatura mobili · Roma</p>
</div>';
        $mail->send();
        error_log("ARDY MAIL OK: inviata a " . $email);
    } catch (Exception $e) {
        error_log('ARDY MAIL CLIENTE ERROR: ' . $e->getMessage() . ' - Code: ' . $e->getCode());
    }
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
