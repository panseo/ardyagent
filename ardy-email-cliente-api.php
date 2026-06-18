<?php
// -----------------------------------------------------------
// ARDY LAB — Invio email al cliente (testo generato con AI) + bozze
//
// Il modale "Email cliente" genera il testo con l'AI ma finora non c'era modo
// di spedirlo o di metterlo da parte: questo endpoint aggiunge "Invia al
// cliente" (subito) e "Salva in bozza" (per rivederla/spedirla più avanti),
// in entrambi i casi con la possibilità di allegare un preventivo PDF (uno
// già presente nello Storico, oppure un nuovo file caricato al momento).
//
// Invio via PHPMailer + SMTP Brevo (stesso schema di ardy-solleciti.php).
//
//   GET  ?session_id=...                  → lista bozze email del cliente
//   POST multipart {mode:'salva_bozza', session_id, destinatario, oggetto,
//                    testo, bozza_id?, pdf?, preventivo_file?}
//   POST multipart {mode:'invia', session_id, destinatario, oggetto, testo,
//                    bozza_id?, pdf?, preventivo_file?}  → spedisce subito
//   POST {mode:'delete', id}              → elimina una bozza
//
// Protetto da Basic Auth (.htaccess), come ardy-allega-preventivo.php (no
// ardyRequireAuth(): su questo server l'header Authorization non arriva a
// PHP in CGI/FPM, e l'endpoint deve accettare anche multipart per il PDF).
// -----------------------------------------------------------

require_once __DIR__ . '/ardy-config.php';
require_once __DIR__ . '/ardy-db.php';
require_once __DIR__ . '/ardy-email.php';
require_once __DIR__ . '/phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/phpmailer/src/SMTP.php';
require_once __DIR__ . '/phpmailer/src/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

date_default_timezone_set('Europe/Rome');
header('Content-Type: application/json; charset=utf-8');

define('PREVENTIVI_PDF_DIR', __DIR__ . '/preventivi_pdf/');
define('EMAIL_PDF_DIR', __DIR__ . '/email_pdf/');

// Crea la tabella delle bozze email se non esiste (DDL idempotente).
function ardyEnsureEmailBozze(PDO $db): void {
    $db->exec("
        CREATE TABLE IF NOT EXISTS `email_bozze` (
            `id`           INT AUTO_INCREMENT PRIMARY KEY,
            `session_id`   VARCHAR(64)  NOT NULL,
            `destinatario` VARCHAR(255) NOT NULL DEFAULT '',
            `oggetto`      VARCHAR(255) NOT NULL DEFAULT '',
            `testo`        TEXT NOT NULL,
            `allegato_pdf` VARCHAR(255) NULL,
            `created_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX (session_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

/** Valida e salva in email_pdf/ il file PDF caricato via upload. Ritorna il nome o null. */
function ardySalvaEmailPdfUpload(array $file): ?string {
    if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])
        || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return null;
    if (($file['size'] ?? 0) > 15 * 1024 * 1024) return null;
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    if ($finfo->file($file['tmp_name']) !== 'application/pdf') return null;

    if (!is_dir(EMAIL_PDF_DIR) && !mkdir(EMAIL_PDF_DIR, 0755, true) && !is_dir(EMAIL_PDF_DIR)) return null;
    ardyHardenUploadDirLocal(EMAIL_PDF_DIR);

    $base = preg_replace('/[^A-Za-z0-9._-]+/', '_', basename($file['name'] ?? 'allegato.pdf'));
    $base = trim($base, '._-') ?: 'allegato';
    if (!preg_match('/\.pdf$/i', $base)) $base .= '.pdf';
    $base = 'mail_' . substr(md5($base . microtime()), 0, 6) . '_' . $base;

    $dest = EMAIL_PDF_DIR . $base;
    if (!copy($file['tmp_name'], $dest)) return null;
    @chmod($dest, 0644);
    return $base;
}

/** Copia un PDF già presente nello Storico preventivi dentro email_pdf/. Ritorna il nome o null. */
function ardyCopiaPreventivoComeAllegato(string $filename): ?string {
    $filename = basename($filename); // niente path traversal
    $src = PREVENTIVI_PDF_DIR . $filename;
    if ($filename === '' || !is_file($src)) return null;

    if (!is_dir(EMAIL_PDF_DIR) && !mkdir(EMAIL_PDF_DIR, 0755, true) && !is_dir(EMAIL_PDF_DIR)) return null;
    ardyHardenUploadDirLocal(EMAIL_PDF_DIR);

    $dest = EMAIL_PDF_DIR . 'mail_' . substr(md5($filename . microtime()), 0, 6) . '_' . $filename;
    return copy($src, $dest) ? basename($dest) : null;
}

/** Versione minimale di ardyHardenUploadDir (evita la dipendenza da ardy-net.php). */
function ardyHardenUploadDirLocal(string $dir): void {
    $htaccess = rtrim($dir, '/') . '/.htaccess';
    if (file_exists($htaccess)) return;
    @file_put_contents($htaccess, "RemoveHandler .php .php3 .php4 .php5 .php7 .php8 .phtml .phar .cgi .pl .py\n"
        . "RemoveType .php .php3 .php4 .php5 .php7 .php8 .phtml .phar\n"
        . "<FilesMatch \"\\.(?:php[0-9]?|phtml|phps|phar|cgi|pl|py|sh|asp|aspx|jsp)\$\">\n"
        . "    Order Allow,Deny\n    Deny from all\n</FilesMatch>\n");
}

/** Determina il PDF da allegare in base ai campi della richiesta. Ritorna il path assoluto o null. */
function ardyRisolviAllegato(?array $pdfFile, string $preventivoFile, ?array $bozzaEsistente): ?string {
    if ($pdfFile !== null) {
        $nome = ardySalvaEmailPdfUpload($pdfFile);
        return $nome ? EMAIL_PDF_DIR . $nome : null;
    }
    if ($preventivoFile !== '') {
        $nome = ardyCopiaPreventivoComeAllegato($preventivoFile);
        return $nome ? EMAIL_PDF_DIR . $nome : null;
    }
    if ($bozzaEsistente !== null && !empty($bozzaEsistente['allegato_pdf'])) {
        $path = EMAIL_PDF_DIR . basename($bozzaEsistente['allegato_pdf']);
        return is_file($path) ? $path : null;
    }
    return null;
}

/** Spedisce l'email al cliente via SMTP Brevo (PHPMailer). Ritorna true/false. */
function ardyInviaEmailCliente(string $to, string $nome, string $oggetto, string $testo, ?string $pdfPath, string $codice = ''): bool {
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
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
        $mail->addReplyTo('ardy.documenti@gmail.com', 'Ardy Lab — Michela');
        $mail->addAddress($to, $nome);
        $mail->Subject = $oggetto !== '' ? $oggetto : 'Ardy Lab';
        // Email HTML con logo in cima e footer cliente (codice + WhatsApp + social) in fondo,
        // coerente con le altre email da Sole. Il testo (scritto/generato) è plain → lo proteggo.
        $mail->isHTML(true);
        $mail->Body = '
<div style="font-family:Georgia,serif;max-width:600px;margin:0 auto;padding:32px;color:#333;">
  ' . ardy_email_logo_cid($mail) . '
  <div style="font-size:15px;line-height:1.7;color:#333;">' . nl2br(htmlspecialchars($testo)) . '</div>
  ' . ardy_email_footer_cliente($codice) . '
  <p style="margin-top:32px;font-size:12px;color:#bbb;">Ardy Lab — Restauro e laccatura mobili · Roma</p>
</div>';
        $mail->AltBody = $testo;
        if ($pdfPath && is_file($pdfPath)) {
            $mail->addAttachment($pdfPath);
        }
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('ARDY EMAIL CLIENTE MAIL ERROR: ' . (isset($mail) ? $mail->ErrorInfo : $e->getMessage()));
        return false;
    }
}

try {
    $db = ardyDB();
    ardyEnsureEmailBozze($db);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $sessionId = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_GET['session_id'] ?? '');
        if ($sessionId === '') { echo json_encode([]); exit(); }
        $stmt = $db->prepare(
            "SELECT id, destinatario, oggetto, testo, allegato_pdf, created_at FROM email_bozze
             WHERE session_id = ? ORDER BY created_at DESC"
        );
        $stmt->execute([$sessionId]);
        echo json_encode($stmt->fetchAll());
        exit();
    }

    $mode = $_POST['mode'] ?? '';

    if ($mode === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if (!$id) { echo json_encode(['success' => false, 'error' => 'id mancante']); exit(); }
        $db->prepare("DELETE FROM email_bozze WHERE id = ?")->execute([$id]);
        echo json_encode(['success' => true]);
        exit();
    }

    if ($mode === 'salva_bozza' || $mode === 'invia') {
        $sessionId    = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_POST['session_id'] ?? '');
        $destinatario = trim((string) ($_POST['destinatario'] ?? ''));
        $oggetto      = trim((string) ($_POST['oggetto'] ?? ''));
        $testo        = trim((string) ($_POST['testo'] ?? ''));
        $bozzaId      = (int) ($_POST['bozza_id'] ?? 0);
        $preventivoFile = trim((string) ($_POST['preventivo_file'] ?? ''));

        if ($sessionId === '' || $testo === '') {
            echo json_encode(['success' => false, 'error' => 'Dati mancanti']);
            exit();
        }

        $bozzaEsistente = null;
        if ($bozzaId) {
            $st = $db->prepare("SELECT * FROM email_bozze WHERE id = ? AND session_id = ?");
            $st->execute([$bozzaId, $sessionId]);
            $bozzaEsistente = $st->fetch() ?: null;
        }

        $pdfFile     = (!empty($_FILES['pdf']['tmp_name'])) ? $_FILES['pdf'] : null;
        $allegatoPath = ardyRisolviAllegato($pdfFile, $preventivoFile, $bozzaEsistente);
        $allegatoNome = $allegatoPath ? basename($allegatoPath) : ($bozzaEsistente['allegato_pdf'] ?? null);

        if ($mode === 'invia') {
            if ($destinatario === '') {
                echo json_encode(['success' => false, 'error' => 'Indirizzo email del cliente mancante']);
                exit();
            }
            $st = $db->prepare("SELECT nome, cognome, codice_accesso FROM clienti WHERE session_id = ? LIMIT 1");
            $st->execute([$sessionId]);
            $cli    = $st->fetch();
            $nome   = $cli ? trim(($cli['nome'] ?? '') . ' ' . ($cli['cognome'] ?? '')) : '';
            $codice = $cli ? trim((string) ($cli['codice_accesso'] ?? '')) : '';

            $ok = ardyInviaEmailCliente($destinatario, $nome, $oggetto, $testo, $allegatoPath, $codice);
            if (!$ok) {
                echo json_encode(['success' => false, 'error' => 'Invio non riuscito']);
                exit();
            }
            if ($bozzaId) {
                $db->prepare("DELETE FROM email_bozze WHERE id = ?")->execute([$bozzaId]);
            }
            echo json_encode(['success' => true, 'inviata' => true]);
            exit();
        }

        // mode === 'salva_bozza'
        if ($bozzaId) {
            $db->prepare(
                "UPDATE email_bozze SET destinatario=:dest, oggetto=:ogg, testo=:txt, allegato_pdf=:pdf
                 WHERE id=:id AND session_id=:sid"
            )->execute([
                ':dest' => $destinatario, ':ogg' => $oggetto, ':txt' => $testo,
                ':pdf' => $allegatoNome, ':id' => $bozzaId, ':sid' => $sessionId,
            ]);
            $id = $bozzaId;
        } else {
            $ins = $db->prepare(
                "INSERT INTO email_bozze (session_id, destinatario, oggetto, testo, allegato_pdf)
                 VALUES (:sid, :dest, :ogg, :txt, :pdf)"
            );
            $ins->execute([
                ':sid' => $sessionId, ':dest' => $destinatario, ':ogg' => $oggetto,
                ':txt' => $testo, ':pdf' => $allegatoNome,
            ]);
            $id = (int) $db->lastInsertId();
        }
        echo json_encode(['success' => true, 'id' => $id]);
        exit();
    }

    echo json_encode(['success' => false, 'error' => 'mode non valido']);

} catch (Throwable $e) {
    error_log('ARDY EMAIL CLIENTE API ERROR: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Errore interno']);
}
