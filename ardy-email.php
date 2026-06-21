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

// -----------------------------------------------------------
// Footer cliente condiviso: codice personale + WhatsApp + social.
// Va incluso in TUTTE le email che il cliente riceve da Sole, così che in
// ogni messaggio ritrovi il suo codice, come scriverci e dove seguirci.
// Restituisce HTML inline (usabile sia con PHPMailer sia con Brevo HTTP API).
// -----------------------------------------------------------

// Numero WhatsApp pubblico di Sole (overridabile da config).
function ardy_email_wa_number(): string {
    return defined('ARDY_WA_PUBLIC_NUMBER') && ARDY_WA_PUBLIC_NUMBER !== '' ? ARDY_WA_PUBLIC_NUMBER : '393793756437';
}

// Blocco "chat personale" con il codice; se il codice manca rimanda a quello
// ricevuto nell'email di benvenuto (così il blocco c'è sempre).
function ardy_email_codice_block(string $codice = ''): string {
    if (trim($codice) !== '') {
        return '
  <div style="border-radius:8px;background:#fbf8f2;padding:16px 20px;margin:24px 0;font-size:14px;line-height:1.6;color:#555;">
    💬 <strong>La tua chat personale.</strong> Per qualsiasi domanda o per sapere a che punto siamo, scrivi a Sole nella <a href="https://ardy-lab.it/ardy-agent/" style="color:#c8a96e;">chat di Ardy Lab</a> e indicale il tuo codice personale:
    <div style="font-family:monospace;font-size:18px;letter-spacing:2px;color:#333;margin:10px 0;"><strong>' . htmlspecialchars($codice) . '</strong></div>
    Con questo ti riconosce subito e ti aggiorna sul tuo mobile — senza ricominciare da capo. Lo trovi anche nell\'email di benvenuto.
  </div>';
    }
    return '
  <div style="border-radius:8px;background:#fbf8f2;padding:16px 20px;margin:24px 0;font-size:14px;line-height:1.6;color:#555;">
    💬 <strong>La tua chat personale.</strong> Per qualsiasi domanda o per sapere a che punto siamo, scrivi a Sole nella <a href="https://ardy-lab.it/ardy-agent/" style="color:#c8a96e;">chat di Ardy Lab</a>: indicale il <strong>codice personale</strong> che hai ricevuto nell\'email di benvenuto e ti riconosce subito, senza ricominciare da capo.
  </div>';
}

// Blocco WhatsApp (testo + pulsante) verso il numero di Sole.
function ardy_email_whatsapp_block(): string {
    $n = ardy_email_wa_number();
    return '
  <p style="font-size:14px;line-height:1.7;color:#555;margin-top:18px;">💬 <strong>Preferisci WhatsApp?</strong> Trovi Sole anche lì: scrivile al <strong>+39 379 375 6437</strong> e continui la conversazione come in chat.</p>
  <p style="margin:8px 0 0;"><a href="https://wa.me/' . htmlspecialchars($n) . '" style="display:inline-block;background:#25D366;color:#ffffff;text-decoration:none;font-family:sans-serif;font-size:14px;font-weight:600;padding:11px 22px;border-radius:6px;">Apri la chat WhatsApp</a></p>';
}

// Link social (Instagram/Facebook), con override da config (gli stessi
// dell'email di ringraziamento).
function ardy_email_social_links(string $accent = '#c8a96e'): string {
    $ig = defined('GRAZIE_IG_URL') && GRAZIE_IG_URL !== '' ? GRAZIE_IG_URL : 'https://www.instagram.com/ardy.lab/';
    $fb = defined('GRAZIE_FB_URL') && GRAZIE_FB_URL !== '' ? GRAZIE_FB_URL : 'https://www.facebook.com/376551605541671';
    return '
  <p style="font-size:14px;line-height:1.7;color:#555;margin-top:24px;">Ci trovi anche sui social, dove raccontiamo le lavorazioni passo passo:</p>
  <p style="font-size:14px;line-height:1.7;color:#555;margin:6px 0 0;">
    👉 <a href="' . htmlspecialchars($ig) . '" style="color:' . $accent . ';">Instagram</a> &nbsp;·&nbsp;
    👉 <a href="' . htmlspecialchars($fb) . '" style="color:' . $accent . ';">Facebook</a>
  </p>';
}

// Codice etico AI: riga sul modo in cui Ardy Lab usa l'AI (l'assistente Sole).
// Va citato in TUTTE le email/messaggi (qui in HTML; per lettere cartacee e
// WhatsApp esiste la versione testuale in ardy_codice_etico_testo()). Tre punti:
// non aggressione, tutela privacy/sicurezza dati, nessun uso fraudolento.
function ardy_email_codice_etico(string $accent = '#c8a96e'): string {
    return '
  <p style="font-size:12px;line-height:1.6;color:#999;font-family:sans-serif;margin:18px 0 0;border-top:1px solid #eee;padding-top:14px;">
    🤝 <strong style="color:#777;">Come usiamo l\'AI.</strong> Da Ardy Lab l\'assistente Sole è un\'intelligenza artificiale che segue un codice etico: nessuna aggressività, rispetto della tua privacy e della sicurezza dei tuoi dati, che non vengono mai usati per scopi fraudolenti.
  </p>';
}

// Versione in testo semplice del codice etico (lettere cartacee, WhatsApp, plain text).
function ardy_codice_etico_testo(): string {
    return 'Da Ardy Lab usiamo l\'AI (l\'assistente Sole) con un codice etico: nessuna aggressività, '
         . 'rispetto della privacy e della sicurezza dei tuoi dati, mai usati per scopi fraudolenti.';
}

// Footer cliente completo: codice + WhatsApp + social + codice etico. Da inserire prima della firma.
function ardy_email_footer_cliente(string $codice = '', string $accent = '#c8a96e'): string {
    return ardy_email_codice_block($codice) . ardy_email_whatsapp_block() . ardy_email_social_links($accent) . ardy_email_codice_etico($accent);
}

}
