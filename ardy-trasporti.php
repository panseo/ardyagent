<?php
// -----------------------------------------------------------
// ARDY LAB — Consegne/ritiri: "è pronto" + giornata Trasporti
//
// Due momenti, due email al cliente (footer cliente condiviso incluso):
//   1) "È PRONTO"  — automatico quando il cliente passa a COMPLETATO (aggancio
//      in ardy-update-lead.php, come il ringraziamento a CONSEGNATO). Una volta
//      sola (guard `trasporto_pronto_at`).
//   2) "TRASPORTO" — quando in dashboard definisci la GIORNATA TRASPORTI e ci
//      assegni i clienti pronti: confermando, ognuno riceve l'email con la data
//      di consegna/ritiro. Una volta sola per data (guard `trasporto_avviso_data`).
//
// Per ora SOLO EMAIL. Il WhatsApp si aggiungerà in seguito (serve template Meta
// approvato, come per fasi/grazie): i punti d'innesto sono già predisposti.
//
// Doppio uso:
//   - LIBRERIA: include il file e chiama ardy_invia_pronto() /
//     ardy_invia_avviso_trasporto().
//   - ENDPOINT (dashboard, Basic Auth via .htaccess):
//       GET  ?mode=lista                      → clienti COMPLETATO + data trasporto
//       POST {mode:'assegna', data, sessions} → assegna i clienti a una data
//       POST {mode:'rimuovi', session}        → toglie un cliente dalla giornata
//       POST {mode:'conferma', data}          → avvisa i clienti di quella data
// -----------------------------------------------------------

date_default_timezone_set('Europe/Rome');

require_once __DIR__ . '/ardy-config.php';
require_once __DIR__ . '/ardy-db.php';
require_once __DIR__ . '/ardy-email.php';
require_once __DIR__ . '/phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/phpmailer/src/SMTP.php';
require_once __DIR__ . '/phpmailer/src/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// -----------------------------------------------------------
// Migrazione idempotente: colonne per la logistica di consegna/ritiro.
// -----------------------------------------------------------
if (!function_exists('ardy_trasporti_ensure_cols')) {
function ardy_trasporti_ensure_cols(PDO $db): void {
    try {
        if (!$db->query("SHOW COLUMNS FROM clienti LIKE 'trasporto_data'")->fetch()) {
            $db->exec("ALTER TABLE clienti ADD COLUMN trasporto_data DATE NULL");
        }
        if (!$db->query("SHOW COLUMNS FROM clienti LIKE 'trasporto_pronto_at'")->fetch()) {
            $db->exec("ALTER TABLE clienti ADD COLUMN trasporto_pronto_at DATETIME NULL");
        }
        if (!$db->query("SHOW COLUMNS FROM clienti LIKE 'trasporto_avviso_data'")->fetch()) {
            $db->exec("ALTER TABLE clienti ADD COLUMN trasporto_avviso_data DATE NULL");
        }
    } catch (PDOException $e) {
        error_log('ARDY TRASPORTI ENSURE COLS: ' . $e->getMessage());
    }
}
}

// -----------------------------------------------------------
// Data in italiano (solo giorno, senza orario). Es: "lunedì 23 giugno 2026".
// -----------------------------------------------------------
if (!function_exists('ardy_trasporti_data_ita')) {
function ardy_trasporti_data_ita(string $ymd): string {
    try { $dt = new DateTime($ymd); } catch (Throwable $e) { return $ymd; }
    $giorni = ['Monday'=>'lunedì','Tuesday'=>'martedì','Wednesday'=>'mercoledì','Thursday'=>'giovedì','Friday'=>'venerdì','Saturday'=>'sabato','Sunday'=>'domenica'];
    $mesi   = ['January'=>'gennaio','February'=>'febbraio','March'=>'marzo','April'=>'aprile','May'=>'maggio','June'=>'giugno','July'=>'luglio','August'=>'agosto','September'=>'settembre','October'=>'ottobre','November'=>'novembre','December'=>'dicembre'];
    $gg = $giorni[$dt->format('l')] ?? '';
    $mm = $mesi[$dt->format('F')] ?? '';
    return trim(ucfirst($gg) . ' ' . $dt->format('j') . ' ' . $mm . ' ' . $dt->format('Y'));
}
}

// -----------------------------------------------------------
// Invio email cliente (PHPMailer SMTP Brevo) con logo + footer condiviso.
// -----------------------------------------------------------
if (!function_exists('ardy_trasporti_email')) {
function ardy_trasporti_email(string $to, string $nome, string $subject, string $kicker, string $titolo, string $corpoHtml, string $codice): bool {
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
        $mail->Subject = $subject;
        $mail->isHTML(true);
        $mail->Body = '
<div style="font-family:Georgia,serif;max-width:600px;margin:0 auto;padding:32px;color:#333;">
  ' . ardy_email_logo_cid($mail) . '
  <p style="color:#999;font-size:13px;margin-bottom:24px;">' . htmlspecialchars($kicker) . '</p>
  <h3 style="font-size:18px;margin-bottom:16px;">' . htmlspecialchars($titolo) . '</h3>
  <div style="font-size:15px;line-height:1.7;color:#333;">' . $corpoHtml . '</div>
  ' . ardy_email_footer_cliente($codice) . '
  <p style="font-size:14px;line-height:1.7;color:#555;margin-top:28px;">A presto 🌿</p>
  <p style="margin-top:24px;font-size:12px;color:#bbb;">Ardy Lab — Restauro e laccatura mobili · Roma</p>
</div>';
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('ARDY TRASPORTI MAIL ERROR: ' . (isset($mail) ? $mail->ErrorInfo : $e->getMessage()));
        return false;
    }
}
}

// -----------------------------------------------------------
// 1) "È PRONTO" — una volta sola (guard trasporto_pronto_at).
//    Ritorna ['email'=>bool, 'gia_inviato'=>bool].
// -----------------------------------------------------------
if (!function_exists('ardy_invia_pronto')) {
function ardy_invia_pronto(PDO $db, string $sessionId): array {
    $out = ['email' => false, 'gia_inviato' => false];
    $sid = preg_replace('/[^a-zA-Z0-9_\-]/', '', $sessionId);
    if ($sid === '') return $out;

    ardy_trasporti_ensure_cols($db);
    $q = $db->prepare("SELECT nome, cognome, email, mobile, codice_accesso, trasporto_pronto_at FROM clienti WHERE session_id = :sid LIMIT 1");
    $q->execute([':sid' => $sid]);
    $cli = $q->fetch(PDO::FETCH_ASSOC);
    if (!$cli) return $out;

    if (!empty($cli['trasporto_pronto_at'])) { $out['gia_inviato'] = true; return $out; }

    $email = trim((string) ($cli['email'] ?? ''));
    if ($email === '') return $out;

    $nome   = trim((string) ($cli['nome'] ?? ''));
    $mobile = trim((string) ($cli['mobile'] ?? '')) ?: 'mobile';
    $codice = trim((string) ($cli['codice_accesso'] ?? ''));
    $saluto = $nome !== '' ? 'Ciao ' . htmlspecialchars($nome) . ',' : 'Ciao,';

    $corpo = '
  <p style="margin:0 0 16px;">' . $saluto . '</p>
  <p style="margin:0 0 16px;">una bella notizia: il restauro del tuo <strong>' . htmlspecialchars($mobile) . '</strong> è <strong>completato</strong> ✨.</p>
  <p style="margin:0 0 16px;">Adesso organizziamo la <strong>consegna o il ritiro</strong>. Di solito definiamo le giornate di trasporto con circa una settimana di anticipo: ti riscriviamo appena abbiamo la <strong>data</strong>. Se nel frattempo hai preferenze, rispondi pure a questa email.</p>';

    $ok = ardy_trasporti_email(
        $email, $nome,
        'Ardy Lab — Il tuo ' . $mobile . ' è pronto 🎉',
        'Lavoro completato', $mobile, $corpo, $codice
    );
    $out['email'] = $ok;
    if ($ok) {
        try { $db->prepare("UPDATE clienti SET trasporto_pronto_at = NOW() WHERE session_id = :sid")->execute([':sid' => $sid]); }
        catch (PDOException $e) { error_log('ARDY TRASPORTI PRONTO FLAG: ' . $e->getMessage()); }
    }
    return $out;
}
}

// -----------------------------------------------------------
// 2) "TRASPORTO" — avvisa il cliente con la data della giornata Trasporti.
//    Una volta sola per data (guard trasporto_avviso_data == trasporto_data).
//    Ritorna ['email'=>bool, 'gia_inviato'=>bool, 'no_data'=>bool].
// -----------------------------------------------------------
if (!function_exists('ardy_invia_avviso_trasporto')) {
function ardy_invia_avviso_trasporto(PDO $db, string $sessionId): array {
    $out = ['email' => false, 'gia_inviato' => false, 'no_data' => false];
    $sid = preg_replace('/[^a-zA-Z0-9_\-]/', '', $sessionId);
    if ($sid === '') return $out;

    ardy_trasporti_ensure_cols($db);
    $q = $db->prepare("SELECT nome, email, mobile, codice_accesso, trasporto_data, trasporto_avviso_data FROM clienti WHERE session_id = :sid LIMIT 1");
    $q->execute([':sid' => $sid]);
    $cli = $q->fetch(PDO::FETCH_ASSOC);
    if (!$cli) return $out;

    $dataTrasp = trim((string) ($cli['trasporto_data'] ?? ''));
    if ($dataTrasp === '' || $dataTrasp === '0000-00-00') { $out['no_data'] = true; return $out; }
    if (trim((string) ($cli['trasporto_avviso_data'] ?? '')) === $dataTrasp) { $out['gia_inviato'] = true; return $out; }

    $email = trim((string) ($cli['email'] ?? ''));
    if ($email === '') return $out;

    $nome    = trim((string) ($cli['nome'] ?? ''));
    $mobile  = trim((string) ($cli['mobile'] ?? '')) ?: 'mobile';
    $codice  = trim((string) ($cli['codice_accesso'] ?? ''));
    $saluto  = $nome !== '' ? 'Ciao ' . htmlspecialchars($nome) . ',' : 'Ciao,';
    $dataIta = ardy_trasporti_data_ita($dataTrasp);

    $corpo = '
  <p style="margin:0 0 16px;">' . $saluto . '</p>
  <p style="margin:0 0 16px;">ci siamo: abbiamo fissato la <strong>consegna/ritiro</strong> del tuo <strong>' . htmlspecialchars($mobile) . '</strong> per <strong>' . htmlspecialchars($dataIta) . '</strong>.</p>
  <p style="margin:0 0 16px;">Ti ricontattiamo per concordare l\'<strong>orario</strong> preciso. Se quel giorno non ti va bene, scrivici rispondendo a questa email e troviamo subito un\'alternativa.</p>';

    $ok = ardy_trasporti_email(
        $email, $nome,
        'Ardy Lab — Consegna del tuo ' . $mobile . ' — ' . $dataIta,
        'Consegna / ritiro', $mobile, $corpo, $codice
    );
    $out['email'] = $ok;
    if ($ok) {
        try { $db->prepare("UPDATE clienti SET trasporto_avviso_data = :d WHERE session_id = :sid")->execute([':d' => $dataTrasp, ':sid' => $sid]); }
        catch (PDOException $e) { error_log('ARDY TRASPORTI AVVISO FLAG: ' . $e->getMessage()); }
    }
    return $out;
}
}

// -----------------------------------------------------------
// MODALITÀ ENDPOINT — solo se il file è chiamato direttamente via HTTP.
// -----------------------------------------------------------
if (PHP_SAPI !== 'cli'
    && isset($_SERVER['SCRIPT_FILENAME'])
    && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {

    header('Access-Control-Allow-Origin: https://ardyagent.ardy-lab.it');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Content-Type: application/json; charset=utf-8');
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

    try {
        $db = ardyDB();
        ardy_trasporti_ensure_cols($db);

        $method = $_SERVER['REQUEST_METHOD'];
        $in     = $method === 'POST' ? (json_decode(file_get_contents('php://input'), true) ?: []) : [];
        $mode   = $_GET['mode'] ?? ($in['mode'] ?? '');

        if ($method === 'GET' && $mode === 'lista') {
            // Clienti completati (pronti) con eventuale data trasporto già assegnata.
            $st = $db->query("SELECT session_id, nome, cognome, mobile, email,
                                     trasporto_data, trasporto_avviso_data
                              FROM clienti
                              WHERE stato = 'COMPLETATO'
                              ORDER BY trasporto_data IS NULL, trasporto_data ASC, updated_at DESC");
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                $r['avvisato'] = ($r['trasporto_avviso_data'] ?? null) !== null
                              && $r['trasporto_avviso_data'] === $r['trasporto_data'];
            }
            echo json_encode(['success' => true, 'items' => $rows]);
            exit();
        }

        if ($method === 'POST' && $mode === 'assegna') {
            $data     = trim((string) ($in['data'] ?? ''));
            $sessions = $in['sessions'] ?? [];
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) {
                echo json_encode(['success' => false, 'error' => 'Data non valida']); exit();
            }
            if (!is_array($sessions) || !$sessions) {
                echo json_encode(['success' => false, 'error' => 'Nessun cliente selezionato']); exit();
            }
            $upd = $db->prepare("UPDATE clienti SET trasporto_data = :d, updated_at = NOW() WHERE session_id = :sid");
            $n = 0;
            foreach ($sessions as $sid) {
                $sid = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $sid);
                if ($sid === '') continue;
                $upd->execute([':d' => $data, ':sid' => $sid]);
                $n += $upd->rowCount();
            }
            echo json_encode(['success' => true, 'assegnati' => $n]);
            exit();
        }

        if ($method === 'POST' && $mode === 'rimuovi') {
            $sid = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) ($in['session'] ?? ''));
            if ($sid === '') { echo json_encode(['success' => false, 'error' => 'session mancante']); exit(); }
            $db->prepare("UPDATE clienti SET trasporto_data = NULL, trasporto_avviso_data = NULL, updated_at = NOW() WHERE session_id = :sid")
               ->execute([':sid' => $sid]);
            echo json_encode(['success' => true]);
            exit();
        }

        if ($method === 'POST' && $mode === 'conferma') {
            $data = trim((string) ($in['data'] ?? ''));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) {
                echo json_encode(['success' => false, 'error' => 'Data non valida']); exit();
            }
            $sel = $db->prepare("SELECT session_id FROM clienti
                                 WHERE trasporto_data = :d AND stato <> 'CONSEGNATO'");
            $sel->execute([':d' => $data]);
            $ids = $sel->fetchAll(PDO::FETCH_COLUMN);
            $inviate = 0; $gia = 0;
            foreach ($ids as $sid) {
                $r = ardy_invia_avviso_trasporto($db, (string) $sid);
                if ($r['email']) $inviate++;
                elseif ($r['gia_inviato']) $gia++;
            }
            echo json_encode(['success' => true, 'totali' => count($ids), 'inviate' => $inviate, 'gia_avvisati' => $gia]);
            exit();
        }

        echo json_encode(['success' => false, 'error' => 'mode non valido']);
    } catch (Throwable $e) {
        error_log('ARDY TRASPORTI ENDPOINT ERROR: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Errore interno']);
    }
}
