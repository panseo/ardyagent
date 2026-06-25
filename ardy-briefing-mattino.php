<?php
// -----------------------------------------------------------
// ARDY LAB — Briefing del mattino via email (push automatico)
// Chiamato da CRON alle 09:00 nei giorni feriali (lun-ven). Costruisce la stessa
// "foto" operativa del "buongiorno" su WhatsApp (ardy_riepilogo_settimana) e la
// manda via email ai titolari.
//
// Auth: segreto condiviso WA_LOOKUP_SECRET (header X-Ardy-Secret o ?secret=),
// come gli altri endpoint server-to-server. NIENTE Basic Auth (così il cron lo chiama).
//
// Config richiesta in ardy-config.php:
//   ARDY_BRIEFING_EMAILS   destinatari (stringa "a@x.it, b@y.it" oppure array)
//   WA_LOOKUP_SECRET       segreto condiviso (già esistente)
//   ARDY_SMTP_USER/_PASSWORD  SMTP Brevo (già esistenti)
//   ARDY_DASHBOARD_URL     (opzionale) link alla dashboard nel CTA dell'email
//
// Cron consigliato (server, fuso Europe/Rome):
//   0 9 * * 1-5 curl -s -H "X-Ardy-Secret: <SEGRETO>" https://ardyagent.ardy-lab.it/ardy-briefing-mattino.php >/dev/null 2>&1
// -----------------------------------------------------------

date_default_timezone_set('Europe/Rome');

require_once __DIR__ . '/ardy-config.php';
require_once __DIR__ . '/ardy-db.php';

// Calendar opzionale (come in ardy-wa-lookup.php): a livello globale.
if (defined('ARDY_GCAL_CLIENT_ID') && defined('ARDY_GCAL_CLIENT_SECRET')) {
    require_once __DIR__ . '/ardy-gcal.php';
}

require_once __DIR__ . '/ardy-briefing-lib.php';
require_once __DIR__ . '/ardy-email.php';
require_once __DIR__ . '/phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/phpmailer/src/SMTP.php';
require_once __DIR__ . '/phpmailer/src/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

header('Content-Type: application/json');

// ── Auth: segreto condiviso ──────────────────────────────────────────────────
if (!defined('WA_LOOKUP_SECRET') || WA_LOOKUP_SECRET === '') {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'WA_LOOKUP_SECRET non configurato']);
    exit();
}
$sent = $_SERVER['HTTP_X_ARDY_SECRET'] ?? ($_GET['secret'] ?? '');
if (!hash_equals(WA_LOOKUP_SECRET, (string) $sent)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'non autorizzato']);
    exit();
}

// ── Solo giorni feriali (lun-ven). ?force=1 per test manuali nel weekend. ─────
$force = (($_GET['force'] ?? '') === '1');
if (!$force && (int) date('N') >= 6) {
    echo json_encode(['success' => true, 'skipped' => 'weekend']);
    exit();
}

// ── Destinatari da config (stringa o array) ──────────────────────────────────
$dest = defined('ARDY_BRIEFING_EMAILS') ? ARDY_BRIEFING_EMAILS : [];
if (is_string($dest)) {
    $dest = preg_split('/[,;\s]+/', trim($dest), -1, PREG_SPLIT_NO_EMPTY) ?: [];
}
$dest = array_values(array_filter(
    array_map('trim', (array) $dest),
    static fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL)
));
if (!$dest) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'ARDY_BRIEFING_EMAILS non configurato o non valido']);
    exit();
}

// Numeri staff: servono solo per escludere i titolari dalle "conversazioni recenti".
$staff = [
    defined('WA_MICHELA_NUMBER') ? (string) WA_MICHELA_NUMBER : '',
    defined('WA_ANDREA_NUMBER')  ? (string) WA_ANDREA_NUMBER  : '',
];

// ── Costruisce il briefing (stessa fonte del "buongiorno" su WhatsApp) ───────
try {
    $testo = ardy_riepilogo_settimana(ardyDB(), $staff);
} catch (Throwable $e) {
    error_log('ARDY BRIEFING MATTINO: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'errore costruzione briefing']);
    exit();
}

$oggi    = date('d/m/Y');
$subject = '☀️ Buongiorno Ardy — briefing del ' . $oggi;
$intro   = 'Buongiorno! Ecco la situazione di oggi (' . $oggi . ').';

// CTA: modifica delle "Cose da fare questa settimana" dalla dashboard.
$dashUrl = defined('ARDY_DASHBOARD_URL') && ARDY_DASHBOARD_URL !== ''
    ? ARDY_DASHBOARD_URL
    : 'https://ardyagent.ardy-lab.it/ardy-michela-app.html';
$ctaHtml = '
  <div style="margin:28px 0 8px;padding:16px;background:#faf7f1;border:1px solid #ece3d3;border-radius:8px;">
    <div style="font-size:14px;color:#555;margin-bottom:12px;">✏️ Vuoi aggiornare le «Cose da fare questa settimana»? Modificale dalla tua dashboard.</div>
    <a href="' . htmlspecialchars($dashUrl, ENT_QUOTES, 'UTF-8') . '"
       style="display:inline-block;background:#c8a96e;color:#0e0e0e;text-decoration:none;font-weight:bold;padding:10px 18px;border-radius:6px;font-size:14px;">Apri la dashboard →</a>
  </div>';

// ── Invio (una email a destinatario) ─────────────────────────────────────────
$inviate = 0;
$errori  = 0;
foreach ($dest as $email) {
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
        $mail->addReplyTo('ardy.documenti@gmail.com', 'Ardy Lab');
        $mail->addAddress($email);
        $mail->Subject = $subject;
        $mail->isHTML(true);
        $mail->Body = '
<div style="font-family:Georgia,serif;max-width:640px;margin:0 auto;padding:32px;color:#333;">
  ' . ardy_email_logo_cid($mail) . '
  <div style="font-size:15px;line-height:1.6;color:#333;margin-bottom:8px;">' . htmlspecialchars($intro, ENT_QUOTES, 'UTF-8') . '</div>
  <div style="font-size:14px;line-height:1.5;">' . ardy_briefing_email_html($testo) . '</div>
  ' . $ctaHtml . '
  <p style="margin-top:28px;font-size:12px;color:#bbb;">Ardy Lab — Restauro e laccatura mobili · Roma · briefing automatico delle 9:00 (lun-ven)</p>
</div>';
        $mail->AltBody = $intro . "\n\n" . $testo . "\n\nModifica le cose da fare dalla dashboard: " . $dashUrl;
        $mail->send();
        $inviate++;
    } catch (Throwable $e) {
        $errori++;
        error_log('ARDY BRIEFING MATTINO invio a ' . $email . ': ' . $e->getMessage());
    }
}

echo json_encode([
    'success'     => $errori === 0,
    'inviate'     => $inviate,
    'errori'      => $errori,
    'destinatari' => count($dest),
]);
