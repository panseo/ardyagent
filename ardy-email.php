<?php
// -----------------------------------------------------------
// ARDY LAB — Helper header email condiviso (logo)
// -----------------------------------------------------------
// Centralizza il logo in cima alle email. Due meccanismi a seconda di come
// l'email viene spedita:
//   - PHPMailer (SMTP Brevo): logo INCORPORATO via CID → ardy_email_logo_cid($mail)
//   - Brevo HTTP API (no CID): logo via URL ASSOLUTO    → ardy_email_logo_url()
// In entrambi i casi, se il file logo manca o l'embed fallisce, si ricade su un
// header testuale "Ardy Lab" (nessuna email resta senza intestazione).
//
// Il logo statico (`assets/logo.png`) è pubblico (non protetto da Basic Auth nel
// .htaccess) → l'URL assoluto è raggiungibile dai client di posta.
// -----------------------------------------------------------

if (!function_exists('ardy_email_logo_url')) {

// URL pubblico del logo (per le email via API, dove il CID non è disponibile).
define('ARDY_EMAIL_LOGO_URL', 'https://ardyagent.ardy-lab.it/assets/logo.png');
// Percorso su disco del logo (per l'embed CID con PHPMailer).
define('ARDY_EMAIL_LOGO_FILE', __DIR__ . '/assets/logo.png');

// Header testuale di ripiego, usato quando il logo immagine non è disponibile.
function ardy_email_logo_fallback(): string {
    return '<h2 style="font-family:sans-serif;color:#c8a96e;font-size:20px;margin:0 0 4px;letter-spacing:1px;">Ardy Lab</h2>';
}

// Tag <img> del logo con URL assoluto. Per le email spedite via Brevo HTTP API.
function ardy_email_logo_url(int $h = 48): string {
    if (!is_file(ARDY_EMAIL_LOGO_FILE)) return ardy_email_logo_fallback();
    return '<img src="' . ARDY_EMAIL_LOGO_URL . '" alt="Ardy Lab" height="' . $h . '"'
         . ' style="height:' . $h . 'px;margin-bottom:8px;display:block;border:0;outline:none;text-decoration:none;" />';
}

// Tag <img> del logo incorporato via CID. Per le email spedite con PHPMailer:
// va passato l'oggetto $mail (PHPMailer\PHPMailer\PHPMailer). Ricade sul
// fallback testuale se il file manca o l'embed non riesce.
function ardy_email_logo_cid($mail, int $h = 48): string {
    if (is_file(ARDY_EMAIL_LOGO_FILE)) {
        try {
            if ($mail->addEmbeddedImage(ARDY_EMAIL_LOGO_FILE, 'ardylogo')) {
                return '<img src="cid:ardylogo" alt="Ardy Lab" height="' . $h . '"'
                     . ' style="height:' . $h . 'px;margin-bottom:8px;display:block;border:0;outline:none;text-decoration:none;" />';
            }
        } catch (\Throwable $e) { /* ripiego sul fallback testuale */ }
    }
    return ardy_email_logo_fallback();
}

}
