<?php
// -----------------------------------------------------------
// ARDY LAB — Ringraziamento alla consegna (email + WhatsApp)
// -----------------------------------------------------------
// Quando un cliente passa allo stato CONSEGNATO, gli inviamo un messaggio di
// ringraziamento con: invito a recensione su Google, link ai social, e nota
// newsletter (con link di disiscrizione). Email via Brevo; WhatsApp via
// template Meta (necessario fuori dalla finestra 24h).
//
// Si invia UNA SOLA volta per cliente (guard `consegnato_grazie_at`).
//
// Uso:
//   - come LIBRERIA: ardy_invia_grazie_consegna($db, $sessionId)
//       (chiamata automaticamente da ardy-update-lead.php alla transizione → CONSEGNATO)
//   - come ENDPOINT (test/reinvio manuale): POST {session_id, force?}
//       Auth: Basic Auth (.htaccess) oppure header X-Ardy-Internal = ARDY_INTERNAL_SECRET.
//
// Config in ardy-config.php (link, opzionali — i link vuoti non vengono mostrati):
//   GRAZIE_GOOGLE_REVIEW_URL  link "lascia una recensione" su Google Maps
//   GRAZIE_IG_URL             profilo Instagram (default ardy.lab)
//   GRAZIE_FB_URL             pagina Facebook (default pagina "Ardy")
//   WA_TEMPLATE_GRAZIE        nome template Meta approvato (1 var {{1}} = nome). Senza, niente WhatsApp.
// -----------------------------------------------------------

require_once __DIR__ . '/ardy-config.php';
require_once __DIR__ . '/ardy-db.php';

date_default_timezone_set('Europe/Rome');

if (!function_exists('ardy_grazie_link')) {
function ardy_grazie_ig() { return defined('GRAZIE_IG_URL') && GRAZIE_IG_URL !== '' ? GRAZIE_IG_URL : 'https://www.instagram.com/ardy.lab/'; }
function ardy_grazie_fb() { return defined('GRAZIE_FB_URL') && GRAZIE_FB_URL !== '' ? GRAZIE_FB_URL : 'https://www.facebook.com/376551605541671'; }
function ardy_grazie_review() { return defined('GRAZIE_GOOGLE_REVIEW_URL') ? GRAZIE_GOOGLE_REVIEW_URL : ''; }

// Crea il link di disiscrizione firmato (stesso schema di outreach/unsubscribe).
function ardy_grazie_unsub_link($email) {
    $secret = defined('ARDY_UNSUB_SECRET') ? ARDY_UNSUB_SECRET : (defined('ARDY_API_KEY') ? ARDY_API_KEY : '');
    $token  = substr(hash_hmac('sha256', strtolower(trim($email)), $secret), 0, 20);
    return 'https://ardy-lab.it/ardy-unsubscribe.php?email=' . urlencode($email) . '&t=' . $token;
}

// Email di ringraziamento via Brevo HTTP API. Ritorna true/false.
function ardy_grazie_email($cli) {
    if (empty($cli['email']) || !defined('ARDY_BREVO_API_KEY') || ARDY_BREVO_API_KEY === '') return false;

    $nome      = trim((string) ($cli['nome'] ?? '')) ?: 'gentile cliente';
    $mobile    = trim((string) ($cli['mobile'] ?? ''));
    $review    = ardy_grazie_review();
    $ig        = ardy_grazie_ig();
    $fb        = ardy_grazie_fb();
    $unsubLink = ardy_grazie_unsub_link($cli['email']);

    $oggetto = 'Grazie da Ardy Lab' . ($mobile !== '' ? ' — il tuo ' . $mobile . ' è pronto' : '');

    $bottoneReview = $review !== ''
        ? '<div style="text-align:center;margin:28px 0;">
             <a href="' . htmlspecialchars($review) . '" style="display:inline-block;background:#c8a96e;color:#fff;text-decoration:none;padding:14px 26px;border-radius:6px;font-family:sans-serif;font-size:15px;">⭐ Lascia una recensione su Google</a>
           </div>'
        : '';

    $bodyHtml = '
  <p style="font-size:16px;line-height:1.8;color:#333;margin:0 0 16px;">Ciao ' . htmlspecialchars($nome) . ',</p>
  <p style="font-size:15px;line-height:1.8;color:#444;margin:0 0 16px;">grazie di cuore per averci affidato il tuo lavoro' . ($mobile !== '' ? ' (<strong>' . htmlspecialchars($mobile) . '</strong>)' : '') . '. Per noi ogni mobile è un piccolo pezzo di storia da curare, e siamo felici di avertelo riconsegnato come merita.</p>
  <p style="font-size:15px;line-height:1.8;color:#444;margin:0 0 8px;">Se ti sei trovato bene, ci aiuteresti tantissimo con una <strong>recensione su Google</strong>: poche righe fanno una grande differenza per una bottega artigiana come la nostra.</p>
  ' . $bottoneReview . '
  <p style="font-size:15px;line-height:1.8;color:#444;margin:0 0 8px;">Ci trovi anche sui social, dove raccontiamo le lavorazioni passo passo:</p>
  <p style="font-size:15px;line-height:1.8;color:#444;margin:0 0 18px;">
    👉 <a href="' . htmlspecialchars($ig) . '" style="color:#c8a96e;">Instagram</a> &nbsp;·&nbsp;
    👉 <a href="' . htmlspecialchars($fb) . '" style="color:#c8a96e;">Facebook</a>
  </p>
  <p style="font-size:13px;line-height:1.7;color:#777;margin:0;">Resterai nella nostra <strong>newsletter</strong> per ricevere ogni tanto consigli di cura del legno e novità della bottega. Se preferisci non riceverla, puoi disiscriverti quando vuoi col link qui sotto — nessun problema.</p>';

    $htmlEmail = '<!DOCTYPE html><html><body style="font-family:Georgia,serif;background:#f5f5f5;margin:0;padding:20px;">
<div style="max-width:600px;margin:0 auto;background:#fff;padding:40px;border-radius:4px;">
  <div style="border-bottom:2px solid #c8a96e;padding-bottom:20px;margin-bottom:30px;">
    <h2 style="font-family:sans-serif;color:#c8a96e;margin:0;font-size:18px;letter-spacing:2px;">ARDY LAB</h2>
    <p style="color:#999;font-size:12px;margin:4px 0 0;font-family:sans-serif;">Restauro · Laccatura · Stampa 3D · Roma EUR</p>
  </div>
  ' . $bodyHtml . '
  <div style="margin-top:40px;padding-top:20px;border-top:1px solid #eee;font-size:12px;color:#999;font-family:sans-serif;">
    <p style="margin:0;"><strong style="color:#333;">Ardy Lab</strong> · Via James Joyce 4, 00143 Roma EUR</p>
    <p style="margin:4px 0 0;"><a href="https://ardy-lab.it" style="color:#c8a96e;">ardy-lab.it</a></p>
    <p style="margin:8px 0 0;font-size:11px;"><a href="' . $unsubLink . '" style="color:#bbb;">Disiscriviti dalla newsletter</a></p>
  </div>
</div></body></html>';

    $payload = json_encode([
        'sender'      => ['name' => 'Ardy Lab', 'email' => 'noreply@ardy-lab.it'],
        'replyTo'     => ['name' => 'Ardy Lab — Michela', 'email' => 'ardy.documenti@gmail.com'],
        'to'          => [['email' => $cli['email'], 'name' => $nome]],
        'subject'     => $oggetto,
        'htmlContent' => $htmlEmail,
    ]);

    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt($ch, CURLOPT_POST,           true);
    curl_setopt($ch, CURLOPT_POSTFIELDS,     $payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT,        30);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'api-key: ' . ARDY_BREVO_API_KEY]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code < 200 || $code >= 300) {
        error_log("ARDY GRAZIE EMAIL HTTP $code: $res");
        return false;
    }
    return true;
}

// WhatsApp di ringraziamento via template Meta (1 var {{1}} = nome).
// Senza template approvato (WA_TEMPLATE_GRAZIE) non si può scrivere fuori
// dalla finestra 24h → ritorna false senza errori bloccanti.
function ardy_grazie_whatsapp($cli) {
    if (!defined('WA_TOKEN') || !defined('WA_PHONE_NUMBER_ID') || WA_TOKEN === '' || WA_PHONE_NUMBER_ID === '') return false;
    if (!defined('WA_TEMPLATE_GRAZIE') || WA_TEMPLATE_GRAZIE === '') return false;

    $tel = preg_replace('/\D/', '', (string) ($cli['telefono'] ?? ''));
    if ($tel === '') return false;
    if (strlen($tel) <= 10 && strpos($tel, '39') !== 0) $tel = '39' . $tel; // normalizza prefisso IT

    $nome = trim((string) ($cli['nome'] ?? '')) ?: 'cliente';

    $payload = json_encode([
        'messaging_product' => 'whatsapp',
        'to'                => $tel,
        'type'              => 'template',
        'template'          => [
            'name'       => WA_TEMPLATE_GRAZIE,
            'language'   => ['code' => defined('WA_TEMPLATE_LANG') && WA_TEMPLATE_LANG !== '' ? WA_TEMPLATE_LANG : 'it'],
            'components' => [[
                'type'       => 'body',
                'parameters' => [['type' => 'text', 'text' => $nome]],
            ]],
        ],
    ]);

    $ch = curl_init('https://graph.facebook.com/v21.0/' . rawurlencode((string) WA_PHONE_NUMBER_ID) . '/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . WA_TOKEN],
    ]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code < 200 || $code >= 300) {
        error_log("ARDY GRAZIE WA HTTP $code: $res");
        return false;
    }
    return true;
}

/**
 * Invia il ringraziamento alla consegna (email + WhatsApp), una sola volta.
 * Ritorna ['email'=>bool, 'whatsapp'=>bool, 'gia_inviato'=>bool].
 */
function ardy_invia_grazie_consegna(PDO $db, string $sessionId, bool $force = false): array {
    $out = ['email' => false, 'whatsapp' => false, 'gia_inviato' => false];
    $sid = preg_replace('/[^a-zA-Z0-9_\-]/', '', $sessionId);
    if ($sid === '') return $out;

    // Colonna guard (idempotente)
    try { $db->exec("ALTER TABLE clienti ADD COLUMN consegnato_grazie_at DATETIME NULL"); }
    catch (PDOException $e) { /* già presente */ }

    $q = $db->prepare("SELECT nome, cognome, email, telefono, mobile, consegnato_grazie_at FROM clienti WHERE session_id = :sid LIMIT 1");
    $q->execute([':sid' => $sid]);
    $cli = $q->fetch(PDO::FETCH_ASSOC);
    if (!$cli) return $out;

    if (!$force && !empty($cli['consegnato_grazie_at'])) {
        $out['gia_inviato'] = true;
        return $out;
    }

    $out['email']    = ardy_grazie_email($cli);
    $out['whatsapp'] = ardy_grazie_whatsapp($cli);

    // Segna come inviato se almeno un canale è partito (evita loop di retry infiniti).
    if ($out['email'] || $out['whatsapp']) {
        try {
            $u = $db->prepare("UPDATE clienti SET consegnato_grazie_at = NOW() WHERE session_id = :sid");
            $u->execute([':sid' => $sid]);
        } catch (PDOException $e) { error_log('ARDY GRAZIE mark: ' . $e->getMessage()); }
    }
    return $out;
}
} // end function guard

// ===========================================================================
// MODALITÀ ENDPOINT HTTP (test / reinvio manuale)
// ===========================================================================
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    header('Access-Control-Allow-Origin: https://ardyagent.ardy-lab.it');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-Ardy-Internal');
    header('Content-Type: application/json');
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(200); exit(); }

    $body      = json_decode(file_get_contents('php://input'), true) ?: [];
    $sessionId = $body['session_id'] ?? ($_GET['session_id'] ?? '');
    $force     = !empty($body['force']) || !empty($_GET['force']);

    if (trim((string) $sessionId) === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'session_id mancante']);
        exit();
    }
    try {
        $res = ardy_invia_grazie_consegna(ardyDB(), $sessionId, $force);
        echo json_encode(['success' => true] + $res);
    } catch (Throwable $e) {
        error_log('ARDY GRAZIE ENDPOINT: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Errore interno']);
    }
    exit();
}
