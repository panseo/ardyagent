<?php
// -----------------------------------------------------------
// ARDY LAB — Pubblica una fase della lavorazione di un cliente
// Dal passo 2 dell'area cliente (24/11/2026, ANALISI-AREA-CLIENTE.md) la fase NON va
// più su WordPress: niente articolo in "lavori in corso", foto sul server
// (ardy-fasi-foto-lib.php), e il cliente la vede nella sua area /lavoro/, col link che
// riceve per email e WhatsApp. Le foto diventano pubbliche solo se si pubblicano sui
// social (lo fanno ardy-pubblica-social.php e ardy-gbp-post.php al momento dell'invio).
// -----------------------------------------------------------

date_default_timezone_set('Europe/Rome');

// -----------------------------------------------------------
// 1. CONFIG E DATABASE (nessun conflitto)
// -----------------------------------------------------------
require_once __DIR__ . '/ardy-config.php';
require_once __DIR__ . '/ardy-db.php';
require_once __DIR__ . '/ardy-net.php';
require_once __DIR__ . '/ardy-email.php';
require_once __DIR__ . '/ardy-storage.php';   // pulizia copie bozza su B2 dopo il publish
require_once __DIR__ . '/ardy-img.php';       // WEBP → JPEG: i social non prendono il WEBP
require_once __DIR__ . '/ardy-fasi-foto-lib.php';

// -----------------------------------------------------------
// 2. CORS E PREFLIGHT
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
// Tetto di spesa (SECURITY-AUDIT §9.12): ogni pubblicazione fa 2-3 chiamate Claude
// e un'email al cliente. Si controlla qui all'ingresso e non accanto alle
// chiamate, perché a quel punto le foto sono già salvate e fermarsi
// lascerebbe file orfani.
require_once __DIR__ . '/ardy-costcap.php';
ardyCostCap('ai');

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
$faseId       = !empty($input['fase_id'])    ? (int)$input['fase_id']    : null; // se presente: pubblica una bozza esistente invece di crearne una nuova
$immagini     = $input['immagini']   ?? [];
$videoUrls    = $input['video_urls'] ?? [];   // URL video già caricati via ardy-upload-video.php (restano su WP, vedi README)
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
// 5. PHPMAILER (con guardia: un'altra libreria potrebbe averlo già caricato)
// -----------------------------------------------------------
if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
    require_once __DIR__ . '/phpmailer/src/PHPMailer.php';
    require_once __DIR__ . '/phpmailer/src/SMTP.php';
    require_once __DIR__ . '/phpmailer/src/Exception.php';
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// -----------------------------------------------------------
// 6. SALVA LE FOTO SUL SERVER
// -----------------------------------------------------------
// Restano in ARDY_UPLOAD_DIR/<sessione>/lavorazioni/ (la cartella di sempre); in
// fasi.foto_urls va il loro indirizzo interno, che la dash apre con la sua password e
// l'area del cliente traduce nel proprio (ardy-area-cliente-api.php?mode=foto).
$cleanSession = preg_replace('/[^a-zA-Z0-9_\-]/', '', $sessionId);
$sessionDir   = fasiFotoDir($cleanSession);
if (!is_dir($sessionDir) && !mkdir($sessionDir, 0755, true) && !is_dir($sessionDir)) {
    echo json_encode(['success' => false, 'error' => 'Impossibile creare la cartella di upload']);
    exit();
}
ardyHardenUploadDir(rtrim(ARDY_UPLOAD_DIR, '/')); // niente esecuzione script tra gli upload

$savedImageUrls = [];
$allowedMimes   = ['image/jpeg', 'image/png', 'image/webp'];
$maxImmagini    = 15;                 // numero massimo di foto per pubblicazione
$maxByte        = 12 * 1024 * 1024;   // dimensione massima per foto (12 MB)

// Limita il numero di immagini elaborate (evita riempimento disco)
if (is_array($immagini) && count($immagini) > $maxImmagini) {
    $immagini = array_slice($immagini, 0, $maxImmagini);
}

foreach ((is_array($immagini) ? $immagini : []) as $idx => $imgData) {
    $decoded = base64_decode((string) $imgData, true);
    if (!$decoded) continue;
    if (strlen($decoded) > $maxByte) continue;   // salta foto troppo grandi
    $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($decoded);
    if (!in_array($mime, $allowedMimes, true)) continue;
    // WEBP → JPEG: queste foto possono finire sui social, e Google/Instagram il WEBP
    // non lo accettano (Google risponde 500 senza spiegare). Vedi ardy-img.php.
    $norm    = ardyImgNormalizza($decoded, $mime);
    // Prima le riduceva WordPress; ora lo si fa qui (lato lungo 2000 px).
    $bytes   = ardyCompressImage($norm['bytes'], $norm['mime']);
    $filename = date('Ymd_His') . '_' . uniqid() . '_' . $idx . '.' . $norm['ext'];
    if (file_put_contents($sessionDir . $filename, $bytes) === false) continue;
    $savedImageUrls[] = fasiFotoUrl($cleanSession, $filename);
}
error_log('ARDY PUBBLICA IMG: ricevute=' . (is_array($immagini) ? count($immagini) : 0)
    . ' salvate=' . count($savedImageUrls));

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

// Video della fase (caricati via ardy-upload-video.php, che li mette ancora nella
// libreria media di WordPress). Solo URL http(s).
$videoUrlsClean = [];
if (is_array($videoUrls)) {
    foreach (array_slice($videoUrls, 0, 5) as $vurl) {
        if (is_string($vurl) && preg_match('#^https?://#i', $vurl)) $videoUrlsClean[] = $vurl;
    }
}

// -----------------------------------------------------------
// 8. LINK DELL'AREA DEL CLIENTE
// -----------------------------------------------------------
// Il codice personale fa da chiave: il link lo contiene già, così il cliente entra con
// un tocco. Se manca lo si genera adesso (ardy_codice_per_sessione, in ardy-db.php).
$codiceCliente = '';
try { $codiceCliente = ardy_codice_per_sessione(ardyDB(), $sessionId); }
catch (Throwable $e) { error_log('ARDY CODICE LAVORAZIONE: ' . $e->getMessage()); }
$postLink = fasiLinkArea($codiceCliente);

// -----------------------------------------------------------
// 9. NOTIFICHE AL CLIENTE (email + WhatsApp)
// -----------------------------------------------------------
if ($clienteEmail) {
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

    // Cattura i nomi delle foto bozza PRIMA di sovrascrivere foto_urls con quelle pubblicate:
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
// (ora sono in lavorazioni/ e in foto_urls definitivo). Niente file orfani su disco.
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

// clienti.wp_post_id/wp_post_link non si toccano più: restano solo per i clienti che
// avevano già un articolo su WordPress (passo 3: redirect di quei post verso l'area).

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
    'post_link'    => $postLink,   // link dell'area del cliente (non più l'articolo WP)
    'codice'       => $codiceCliente,
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
//   {{1}} nome · {{2}} mobile/oggetto · {{3}} fase · {{4}} link dell'area del cliente
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

// ardy_codice_per_sessione() sta in ardy-db.php: la usa anche la dash (scheda cliente).

function inviaEmailCliente(string $email, string $nome, string $mobile, string $fase, string $testo, string $link, bool $isComunicazione = false, string $codice = ''): void {
    // Oggetto con prefisso "Ardy Lab —"; accento colore e intestazione cambiano per le comunicazioni
    $subject   = $isComunicazione
        ? 'Ardy Lab — ⚠ Aggiornamento importante sulla tua lavorazione — ' . $mobile
        : 'Ardy Lab — 🪵 Aggiornamento lavorazione — ' . $mobile;
    $accent    = $isComunicazione ? '#e08a3c' : '#c8a96e';
    $kicker    = $isComunicazione ? 'Comunicazione importante' : 'Aggiornamento lavorazione';
    $ctaLabel  = $isComunicazione ? 'Leggi nella tua area →' : 'Vedi la lavorazione completa →';
    $saluto    = trim($nome) !== '' ? 'Ciao ' . htmlspecialchars($nome) . ',' : 'Ciao,';

    // "Come funziona l'area": spiega al cliente cosa può fare con il link.
    // Per le comunicazioni straordinarie il taglio è diverso (deve leggere/approvare),
    // per le fasi normali invita a seguire l'avanzamento e le foto.
    $comeFunziona = $isComunicazione
        ? '
  <p style="font-size:14px;line-height:1.7;color:#555;margin:18px 0 0;">Apri la tua area qui sopra per leggere tutti i dettagli. Se serve una tua conferma per procedere, ti basta <strong>rispondere a questa email</strong> o scriverci su WhatsApp: ti ricontattiamo noi.</p>'
        : '
  <p style="font-size:14px;line-height:1.7;color:#555;margin:18px 0 0;"><strong>Cosa trovi nella tua area.</strong> Aprendo il link qui sopra segui l\'avanzamento del tuo mobile passo dopo passo: aggiungiamo le <strong>foto</strong> e una descrizione a ogni fase completata, così puoi vedere come procede il lavoro comodamente da casa. L\'area è riservata a te (si entra col tuo codice personale, già dentro il link) e si aggiorna da sola a ogni passaggio.</p>';

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
