<?php
// -----------------------------------------------------------
// ARDY LAB — Solleciti di pagamento ("segretaria antipatica")
// Gestione clienti morosi: storico solleciti, generazione messaggi con AI
// (4 livelli di escalation), verifica preventivo e invio WhatsApp/email.
//
// Protetto da Basic Auth via .htaccess (uso esclusivo dashboard Michela).
//
// Modi (querystring ?mode= o campo JSON "mode"):
//   lista     GET           — elenco solleciti (filtro opzionale ?stato=)
//   crea      POST          — nuovo caso moroso
//   aggiorna  POST          — aggiorna campi di un caso (stato, note, risposta...)
//   elimina   POST {id}     — rimuove un caso
//   verifica  POST {id}     — controlla il preventivo collegato prima di sollecitare
//   genera    POST {id,livello} — genera col modello AI il testo del sollecito
//   invia     POST {id,livello,testo,canali[]} — invia (WhatsApp/email) e registra
//
// Config richiesta in ardy-config.php: ARDY_API_KEY, DB, SMTP.
// Per l'invio WhatsApp: WA_TOKEN, WA_PHONE_NUMBER_ID (+ opzionale
// WA_TEMPLATE_SOLLECITO / WA_TEMPLATE_LANG per uscire dalla finestra 24h).
// -----------------------------------------------------------

date_default_timezone_set('Europe/Rome');

require_once __DIR__ . '/ardy-config.php';
require_once __DIR__ . '/ardy-db.php';
require_once __DIR__ . '/ardy-auth.php';
require_once __DIR__ . '/ardy-email.php';
require_once __DIR__ . '/phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/phpmailer/src/SMTP.php';
require_once __DIR__ . '/phpmailer/src/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

header('Access-Control-Allow-Origin: https://ardyagent.ardy-lab.it');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

// Difesa in profondità: invio solleciti (WhatsApp/email) + eliminazione casi.
// Non affidarsi solo al Basic Auth dell'.htaccess.
ardyRequireAuth();

// -----------------------------------------------------------
// DB (tabella solleciti_pagamento creata da ardy-migrate.php)
// -----------------------------------------------------------
try {
    $db = ardyDB();
} catch (PDOException $e) {
    error_log('ARDY SOLLECITI DB ERROR: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'errore database']);
    exit();
}

// -----------------------------------------------------------
// Input unificato (querystring + JSON body)
// -----------------------------------------------------------
$mode = $_GET['mode'] ?? '';
$in   = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $in   = json_decode(file_get_contents('php://input'), true) ?: [];
    $mode = $in['mode'] ?? $mode;
}

$STATI_VALIDI = ['APERTO', 'PAGATO', 'DIFFIDA', 'ARCHIVIATO'];

// -----------------------------------------------------------
// Helper: recupera un caso per id
// -----------------------------------------------------------
function sollecito_get(PDO $db, int $id): ?array {
    $s = $db->prepare("SELECT * FROM solleciti_pagamento WHERE id = :id");
    $s->execute([':id' => $id]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: null;
}

// -----------------------------------------------------------
// Helper: preventivo collegato (riga completa) e fasi del cliente
// -----------------------------------------------------------
function carica_preventivo(PDO $db, ?string $sessionId): ?array {
    if (empty($sessionId)) return null;
    try {
        $p = $db->prepare("SELECT numero, oggetto, condizioni, voci_json, subtotale, grand_total,
                                  stato, data_emissione, data_scadenza, file_pdf
                             FROM preventivi
                            WHERE session_id = :sid
                         ORDER BY id DESC LIMIT 1");
        $p->execute([':sid' => $sessionId]);
        return $p->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (PDOException $e) {
        error_log('ARDY SOLLECITI PREV ERROR: ' . $e->getMessage());
        return null;
    }
}
function carica_fasi(PDO $db, ?string $sessionId): array {
    if (empty($sessionId)) return [];
    try {
        $f = $db->prepare("SELECT fase_nome, testo_generato, created_at
                             FROM fasi WHERE session_id = :sid ORDER BY id ASC");
        $f->execute([':sid' => $sessionId]);
        return $f->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        error_log('ARDY SOLLECITI FASI ERROR: ' . $e->getMessage());
        return [];
    }
}
// Storico WhatsApp del cliente (solo se la tabella esiste — Task 1/sessione 7)
function carica_chat_whatsapp(PDO $db, ?string $telefono, int $max = 20): array {
    if (empty($telefono)) return [];
    $digits = preg_replace('/\D+/', '', $telefono);
    if ($digits === '') return [];
    try {
        $c = $db->prepare("SELECT role, content FROM wa_messaggi
                            WHERE phone LIKE :p ORDER BY id DESC LIMIT :lim");
        $c->bindValue(':p', '%' . substr($digits, -9));
        $c->bindValue(':lim', $max, PDO::PARAM_INT);
        $c->execute();
        return array_reverse($c->fetchAll(PDO::FETCH_ASSOC) ?: []);
    } catch (PDOException $e) {
        // tabella assente o altro: non bloccante
        return [];
    }
}

// -----------------------------------------------------------
// Helper: verifica pre-sollecito.
// Ritorna ['bloccanti'=>[...], 'avvisi'=>[...], 'preventivo'=>?array, 'fasi'=>[...]]
//  - bloccanti: condizioni che impediscono di sollecitare (fermano la generazione)
//  - avvisi:    cose da controllare a mano, ma non bloccano
// -----------------------------------------------------------
function verifica_preventivo(PDO $db, array $caso): array {
    $bloccanti = [];
    $avvisi    = [];
    $prev = carica_preventivo($db, $caso['session_id'] ?? null);
    $fasi = carica_fasi($db, $caso['session_id'] ?? null);

    // BLOCCANTE: senza importo non si può chiedere un pagamento
    if (empty($caso['importo_dovuto']) || (float)$caso['importo_dovuto'] <= 0) {
        $bloccanti[] = "Importo dovuto non indicato: inseriscilo prima di sollecitare.";
    }

    if (!empty($caso['session_id'])) {
        if (!$prev) {
            $avvisi[] = "Nessun preventivo trovato nel sistema per questo cliente: verifica a mano che esista un documento accettato.";
        } else {
            $statoPrev = strtolower((string)($prev['stato'] ?? ''));
            // BLOCCANTE: sollecitare su un preventivo non accettato è scorretto
            if (!in_array($statoPrev, ['accettato', 'approvato', 'pagato'], true)) {
                $bloccanti[] = "Il preventivo collegato è in stato \"" . ($prev['stato'] ?: 'non definito') . "\" (non accettato dal cliente). Conferma l'accettazione prima di procedere.";
            }
            if ((float)($prev['grand_total'] ?? 0) <= 0) {
                $avvisi[] = "Il preventivo collegato non ha un totale chiaro (importo a 0).";
            }
            if (trim((string)($prev['condizioni'] ?? '')) === '') {
                $avvisi[] = "Nel preventivo non risultano indicate le condizioni/modalità di pagamento.";
            }
        }
        if (empty($fasi)) {
            $avvisi[] = "Nessuna fase di lavorazione registrata: assicurati che il lavoro sia stato effettivamente svolto/consegnato.";
        }
    } else {
        $avvisi[] = "Caso non collegato a un cliente del CRM (nessun session_id): impossibile verificare preventivo e lavoro a sistema.";
    }

    if (empty($caso['data_scadenza'])) {
        $avvisi[] = "Data di scadenza non indicata.";
    }
    // Dati non memorizzati a sistema: vanno sempre confermati a mano
    $avvisi[] = "Verifica manualmente: firma/accettazione del cliente, data di accettazione ed eventuale acconto versato (con residuo).";

    return ['bloccanti' => $bloccanti, 'avvisi' => $avvisi, 'preventivo' => $prev, 'fasi' => $fasi];
}

// -----------------------------------------------------------
// Helper: genera il testo del sollecito con il modello AI
// -----------------------------------------------------------
function genera_messaggio(PDO $db, array $caso, int $livello): array {
    if (!defined('ARDY_API_KEY') || ARDY_API_KEY === '') {
        return ['ok' => false, 'error' => 'API key non configurata'];
    }
    $system = @file_get_contents(__DIR__ . '/ardy-solleciti-system.txt') ?: '';

    // ── DATI VINCOLANTI (li fissa il codice, l'AI NON li modifica) ──
    $scad           = $caso['data_scadenza'] ?? '';
    $importoNum     = isset($caso['importo_dovuto']) ? (float)$caso['importo_dovuto'] : null;
    $importoTesto   = $importoNum !== null ? ('€ ' . number_format($importoNum, 2, ',', '.')) : '[DA COMPLETARE]';

    $datiVincolanti = [
        'nome_cliente'   => $caso['nome_cliente'] ?? '',
        'importo_dovuto' => $importoTesto,
        'data_scadenza'  => $scad,
        'preventivo_ref' => $caso['preventivo_ref'] ?? '',
    ];

    // ── CONTESTO FATTUALE (sola lettura, dal DB) ──
    $prev = carica_preventivo($db, $caso['session_id'] ?? null);
    $fasi = carica_fasi($db, $caso['session_id'] ?? null);
    $chat = carica_chat_whatsapp($db, $caso['telefono'] ?? null);

    $contesto = [];
    if ($prev) {
        $contesto['preventivo'] = [
            'numero'         => $prev['numero'] ?? '',
            'oggetto'        => $prev['oggetto'] ?? '',
            'totale'         => isset($prev['grand_total']) ? ('€ ' . number_format((float)$prev['grand_total'], 2, ',', '.')) : '',
            'stato'          => $prev['stato'] ?? '',
            'data_emissione' => $prev['data_emissione'] ?? '',
            'condizioni'     => mb_substr((string)($prev['condizioni'] ?? ''), 0, 800),
        ];
    }
    if ($fasi) {
        $contesto['lavoro_svolto'] = array_map(fn($f) => [
            'fase'  => $f['fase_nome'] ?? '',
            'data'  => substr((string)($f['created_at'] ?? ''), 0, 10),
        ], $fasi);
    }
    if ($chat) {
        $contesto['ultimi_messaggi_whatsapp'] = array_map(fn($m) => [
            'da'      => ($m['role'] ?? '') === 'assistant' ? 'Sole' : 'cliente',
            'testo'   => mb_substr((string)($m['content'] ?? ''), 0, 500),
        ], $chat);
    }

    $datiCaso = [
        'note_interne'          => $caso['note_interne'] ?? '',
        'risposta_cliente'      => $caso['risposta_cliente'] ?? '',
        'solleciti_gia_inviati' => (int)($caso['numero_sollecito'] ?? 0),
    ];

    $regole = "REGOLE FERME:\n"
        . "- Usa ESATTAMENTE l'importo e le date dei 'dati_vincolanti': non ricalcolarli, non arrotondarli, non inventarne altri.\n"
        . "- Il 'contesto' (preventivo, lavoro_svolto, messaggi) serve solo a rendere il sollecito fondato e coerente: citalo se utile, ma non contraddire i dati vincolanti.\n"
        . "- Se un dato non c'è, NON inventarlo: lascia [DA COMPLETARE].\n"
        . "- Tieni conto di eventuali messaggi del cliente (es. promesse di pagamento) con una riga asciutta, senza polemiche.";

    $userMsg = "Scrivi il sollecito di LIVELLO {$livello}.\n\n"
             . $regole . "\n\n"
             . "dati_vincolanti:\n" . json_encode($datiVincolanti, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n"
             . "dettagli_caso:\n" . json_encode($datiCaso, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n"
             . "contesto:\n" . json_encode($contesto, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $payload = json_encode([
        'model'      => 'claude-sonnet-4-6',
        'max_tokens' => 1500,
        'system'     => $system,
        'messages'   => [['role' => 'user', 'content' => $userMsg]],
    ]);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . ARDY_API_KEY,
            'anthropic-version: 2023-06-01',
        ],
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) {
        error_log('ARDY SOLLECITI AI CURL: ' . $err);
        return ['ok' => false, 'error' => 'Errore di rete verso il modello AI'];
    }
    $data = json_decode($resp, true);
    if (isset($data['error'])) {
        error_log('ARDY SOLLECITI AI ERROR: ' . $resp);
        return ['ok' => false, 'error' => 'Errore del modello AI'];
    }
    $testo = '';
    foreach (($data['content'] ?? []) as $block) {
        if (($block['type'] ?? '') === 'text') { $testo = $block['text']; break; }
    }
    if ($testo === '') return ['ok' => false, 'error' => 'Risposta AI vuota'];
    return ['ok' => true, 'testo' => trim($testo)];
}

// -----------------------------------------------------------
// Helper: invio WhatsApp al cliente (testo o template)
// -----------------------------------------------------------
// Appiattisce il testo per il parametro {{1}} del template (Meta vieta a-capo/tab/4+ spazi).
function sollecito_wa_template_param(string $t): string {
    $t = str_replace(["\r\n", "\r"], "\n", $t);
    $t = preg_replace('/\n+/', ' · ', $t);
    $t = str_replace("\t", ' ', $t);
    $t = preg_replace('/ {2,}/', ' ', $t);
    return trim($t);
}

function sollecito_invia_whatsapp(string $telefono, string $testo): bool {
    if (!defined('WA_TOKEN') || !defined('WA_PHONE_NUMBER_ID') || WA_TOKEN === '' || WA_PHONE_NUMBER_ID === '') {
        error_log('ARDY SOLLECITI WA: config mancante');
        return false;
    }
    $to = preg_replace('/\D+/', '', $telefono);
    if ($to === '') return false;
    // Aggiungi prefisso Italia se manca (numeri salvati come 3xx...)
    if (strlen($to) === 10 && $to[0] === '3') $to = '39' . $to;

    if (defined('WA_TEMPLATE_SOLLECITO') && WA_TEMPLATE_SOLLECITO !== '') {
        $payload = [
            'messaging_product' => 'whatsapp',
            'to'                => $to,
            'type'              => 'template',
            'template'          => [
                'name'       => WA_TEMPLATE_SOLLECITO,
                'language'   => ['code' => defined('WA_TEMPLATE_LANG') && WA_TEMPLATE_LANG !== '' ? WA_TEMPLATE_LANG : 'it'],
                'components'  => [[
                    'type'       => 'body',
                    // Meta vieta a-capo/tab/4+ spazi nei parametri template (err 132018) → appiattisci.
                    'parameters' => [['type' => 'text', 'text' => sollecito_wa_template_param(mb_substr($testo, 0, 1000))]],
                ]],
            ],
        ];
    } else {
        $payload = [
            'messaging_product' => 'whatsapp',
            'to'                => $to,
            'type'              => 'text',
            'text'              => ['preview_url' => false, 'body' => mb_substr($testo, 0, 4000)],
        ];
    }

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
        error_log('ARDY SOLLECITI WA ERROR: http=' . $code . ' err=' . $err . ' resp=' . $resp);
        return false;
    }
    return true;
}

// -----------------------------------------------------------
// Helper: invio email sollecito (con eventuale allegato PDF preventivo)
// -----------------------------------------------------------
function sollecito_invia_email(string $email, string $nome, int $livello, string $testo, ?string $pdfPath, string $codice = ''): bool {
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return false;
    $oggetti = [
        1 => 'Promemoria pagamento — Ardy Lab',
        2 => 'Sollecito di pagamento — Ardy Lab',
        3 => 'Sollecito formale di pagamento — Ardy Lab',
        4 => 'Diffida ad adempiere — Ardy Lab',
    ];
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
        $mail->addAddress($email, $nome);
        $mail->Subject = $oggetti[$livello] ?? 'Sollecito di pagamento — Ardy Lab';
        // Email HTML con logo + footer cliente: codice e WhatsApp sempre (utili per
        // risolvere il pagamento); i social solo sui livelli morbidi (1-2), per non
        // metterli sotto un sollecito formale.
        $footer = ardy_email_codice_block($codice) . ardy_email_whatsapp_block()
                . ($livello <= 2 ? ardy_email_social_links() : '');
        $mail->isHTML(true);
        $mail->Body = '
<div style="font-family:Georgia,serif;max-width:600px;margin:0 auto;padding:32px;color:#333;">
  ' . ardy_email_logo_cid($mail) . '
  <div style="font-size:15px;line-height:1.7;color:#333;">' . nl2br(htmlspecialchars($testo)) . '</div>
  ' . $footer . '
  <p style="margin-top:32px;font-size:12px;color:#bbb;">Ardy Lab — Restauro e laccatura mobili · Roma</p>
</div>';
        $mail->AltBody = $testo;
        if ($pdfPath && is_file($pdfPath)) {
            $mail->addAttachment($pdfPath);
        }
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('ARDY SOLLECITI MAIL ERROR: ' . (isset($mail) ? $mail->ErrorInfo : $e->getMessage()));
        return false;
    }
}

// -----------------------------------------------------------
// ROUTING
// -----------------------------------------------------------
try {
    switch ($mode) {

        // ---- ELENCO ----
        case 'lista': {
            $stato = $_GET['stato'] ?? '';
            if ($stato !== '' && in_array($stato, $STATI_VALIDI, true)) {
                $st = $db->prepare("SELECT * FROM solleciti_pagamento WHERE stato = :s ORDER BY updated_at DESC");
                $st->execute([':s' => $stato]);
            } else {
                $st = $db->query("SELECT * FROM solleciti_pagamento ORDER BY
                                    FIELD(stato,'APERTO','DIFFIDA','PAGATO','ARCHIVIATO'), updated_at DESC");
            }
            echo json_encode(['success' => true, 'items' => $st->fetchAll(PDO::FETCH_ASSOC)]);
            break;
        }

        // ---- CREA ----
        case 'crea': {
            $nome = trim((string)($in['nome_cliente'] ?? ''));
            if ($nome === '') {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'nome_cliente obbligatorio']);
                break;
            }
            $importo = $in['importo_dovuto'] ?? null;
            $importo = ($importo === '' || $importo === null) ? null : (float)str_replace(',', '.', (string)$importo);
            $scad    = trim((string)($in['data_scadenza'] ?? ''));
            $scad    = ($scad !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $scad)) ? $scad : null;

            $st = $db->prepare(
                "INSERT INTO solleciti_pagamento
                    (session_id, telefono, nome_cliente, email, importo_dovuto, data_scadenza,
                     preventivo_ref, note_interne, stato)
                 VALUES (:sid, :tel, :nome, :email, :imp, :scad, :pref, :note, 'APERTO')"
            );
            $st->execute([
                ':sid'   => trim((string)($in['session_id'] ?? '')) ?: null,
                ':tel'   => trim((string)($in['telefono'] ?? '')) ?: null,
                ':nome'  => $nome,
                ':email' => trim((string)($in['email'] ?? '')) ?: null,
                ':imp'   => $importo,
                ':scad'  => $scad,
                ':pref'  => trim((string)($in['preventivo_ref'] ?? '')) ?: null,
                ':note'  => trim((string)($in['note_interne'] ?? '')) ?: null,
            ]);
            echo json_encode(['success' => true, 'id' => (int)$db->lastInsertId()]);
            break;
        }

        // ---- AGGIORNA ----
        case 'aggiorna': {
            $id = (int)($in['id'] ?? 0);
            if ($id <= 0 || !sollecito_get($db, $id)) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'caso non trovato']);
                break;
            }
            $campi = [];
            $vals  = [':id' => $id];
            $mappa = [
                'telefono' => 'telefono', 'nome_cliente' => 'nome_cliente', 'email' => 'email',
                'preventivo_ref' => 'preventivo_ref', 'note_interne' => 'note_interne',
                'risposta_cliente' => 'risposta_cliente',
            ];
            foreach ($mappa as $k => $col) {
                if (array_key_exists($k, $in)) { $campi[] = "`$col` = :$col"; $vals[":$col"] = trim((string)$in[$k]) ?: null; }
            }
            if (array_key_exists('importo_dovuto', $in)) {
                $campi[] = "`importo_dovuto` = :imp";
                $vals[':imp'] = ($in['importo_dovuto'] === '' || $in['importo_dovuto'] === null) ? null : (float)str_replace(',', '.', (string)$in['importo_dovuto']);
            }
            if (array_key_exists('data_scadenza', $in)) {
                $s = trim((string)$in['data_scadenza']);
                $campi[] = "`data_scadenza` = :scad";
                $vals[':scad'] = ($s !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) ? $s : null;
            }
            if (array_key_exists('stato', $in) && in_array($in['stato'], $STATI_VALIDI, true)) {
                $campi[] = "`stato` = :stato"; $vals[':stato'] = $in['stato'];
            }
            if (!$campi) { echo json_encode(['success' => true, 'nochange' => true]); break; }
            $db->prepare("UPDATE solleciti_pagamento SET " . implode(', ', $campi) . " WHERE id = :id")->execute($vals);
            echo json_encode(['success' => true]);
            break;
        }

        // ---- ELIMINA ----
        case 'elimina': {
            $id = (int)($in['id'] ?? 0);
            if ($id <= 0) { http_response_code(400); echo json_encode(['success' => false, 'error' => 'id mancante']); break; }
            $db->prepare("DELETE FROM solleciti_pagamento WHERE id = :id")->execute([':id' => $id]);
            echo json_encode(['success' => true]);
            break;
        }

        // ---- VERIFICA PREVENTIVO ----
        case 'verifica': {
            $caso = sollecito_get($db, (int)($in['id'] ?? 0));
            if (!$caso) { http_response_code(404); echo json_encode(['success' => false, 'error' => 'caso non trovato']); break; }
            $v = verifica_preventivo($db, $caso);
            echo json_encode([
                'success'   => true,
                'ok'        => empty($v['bloccanti']),
                'bloccanti' => $v['bloccanti'],
                'avvisi'    => $v['avvisi'],
            ]);
            break;
        }

        // ---- GENERA MESSAGGIO ----
        case 'genera': {
            $caso = sollecito_get($db, (int)($in['id'] ?? 0));
            if (!$caso) { http_response_code(404); echo json_encode(['success' => false, 'error' => 'caso non trovato']); break; }
            $livello = (int)($in['livello'] ?? 0);
            if ($livello < 1 || $livello > 4) { http_response_code(400); echo json_encode(['success' => false, 'error' => 'livello non valido (1-4)']); break; }

            // Blocca la generazione se ci sono condizioni bloccanti, a meno di forzatura esplicita
            $v = verifica_preventivo($db, $caso);
            if (!empty($v['bloccanti']) && empty($in['forza'])) {
                echo json_encode(['success' => false, 'bloccato' => true, 'bloccanti' => $v['bloccanti'], 'avvisi' => $v['avvisi']]);
                break;
            }

            $g = genera_messaggio($db, $caso, $livello);
            if (!$g['ok']) { http_response_code(502); echo json_encode(['success' => false, 'error' => $g['error']]); break; }
            echo json_encode(['success' => true, 'testo' => $g['testo'], 'livello' => $livello, 'avvisi' => $v['avvisi']]);
            break;
        }

        // ---- INVIA ----
        case 'invia': {
            $caso = sollecito_get($db, (int)($in['id'] ?? 0));
            if (!$caso) { http_response_code(404); echo json_encode(['success' => false, 'error' => 'caso non trovato']); break; }
            $livello = (int)($in['livello'] ?? 0);
            $testo   = trim((string)($in['testo'] ?? ''));
            $canali  = $in['canali'] ?? [];
            if ($livello < 1 || $livello > 4) { http_response_code(400); echo json_encode(['success' => false, 'error' => 'livello non valido']); break; }
            if ($testo === '') { http_response_code(400); echo json_encode(['success' => false, 'error' => 'testo mancante']); break; }

            $esito = ['whatsapp' => null, 'email' => null];

            // Livello 4: diffida formale → si invia a mano (raccomandata/PEC). Nessun invio automatico.
            if ($livello === 4) {
                $db->prepare("UPDATE solleciti_pagamento
                                 SET numero_sollecito = GREATEST(numero_sollecito, 4),
                                     data_ultimo_sollecito = NOW(), stato = 'DIFFIDA'
                               WHERE id = :id")->execute([':id' => $caso['id']]);
                echo json_encode([
                    'success' => true,
                    'manuale' => true,
                    'message' => 'Bozza diffida registrata. Inviala manualmente (raccomandata A/R o PEC). Stato → DIFFIDA.',
                ]);
                break;
            }

            if (in_array('whatsapp', $canali, true)) {
                $tel = $caso['telefono'] ?? '';
                $esito['whatsapp'] = $tel ? sollecito_invia_whatsapp($tel, $testo) : false;
            }
            if (in_array('email', $canali, true)) {
                $pdfPath = null;
                if ($livello >= 3 && !empty($caso['session_id'])) {
                    // Allega il PDF del preventivo collegato, se presente
                    try {
                        $p = $db->prepare("SELECT file_pdf FROM preventivi WHERE session_id = :sid AND file_pdf IS NOT NULL ORDER BY id DESC LIMIT 1");
                        $p->execute([':sid' => $caso['session_id']]);
                        $fp = $p->fetchColumn();
                        if ($fp) {
                            $cand = (defined('ARDY_PDF_DIR') ? ARDY_PDF_DIR : (__DIR__ . '/preventivi_pdf/')) . basename($fp);
                            if (is_file($cand)) $pdfPath = $cand;
                        }
                    } catch (PDOException $e) { /* non bloccante */ }
                }
                $codiceCli = '';
                if (!empty($caso['session_id'])) {
                    try {
                        $cc = $db->prepare("SELECT codice_accesso FROM clienti WHERE session_id = :sid LIMIT 1");
                        $cc->execute([':sid' => $caso['session_id']]);
                        $codiceCli = trim((string) ($cc->fetchColumn() ?: ''));
                    } catch (PDOException $e) { /* non bloccante */ }
                }
                $esito['email'] = !empty($caso['email'])
                    ? sollecito_invia_email($caso['email'], $caso['nome_cliente'], $livello, $testo, $pdfPath, $codiceCli)
                    : false;
            }

            // Registra il sollecito solo se almeno un canale è andato a buon fine
            $inviatoOk = ($esito['whatsapp'] === true) || ($esito['email'] === true);
            if ($inviatoOk) {
                $db->prepare("UPDATE solleciti_pagamento
                                 SET numero_sollecito = GREATEST(numero_sollecito, :liv),
                                     data_ultimo_sollecito = NOW()
                               WHERE id = :id")->execute([':liv' => $livello, ':id' => $caso['id']]);
            }
            echo json_encode(['success' => $inviatoOk, 'esito' => $esito]);
            break;
        }

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'mode non valido']);
    }
} catch (PDOException $e) {
    error_log('ARDY SOLLECITI ERROR: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'errore interno']);
}
