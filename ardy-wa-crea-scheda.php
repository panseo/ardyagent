<?php
// -----------------------------------------------------------
// ARDY LAB — Sole crea scheda cliente da WhatsApp (Scenario 1)
//
// Chiamato server-to-server da n8n quando, nella modalità TITOLARE,
// Sole emette il marker [[CREA_SCHEDA]]{...json...} dopo che Michela
// ha confermato i dati di un nuovo cliente. Il nodo Code di n8n estrae
// il JSON dal marker e lo POSTa qui.
//
//   POST (JSON)  →  crea/aggiorna la scheda in `clienti` e ritorna un
//                   riepilogo testuale pronto da rimandare a Michela.
//
// Riusa la stessa logica di upsert di ardy-import-scheda-pdf.php /
// ardy-save-lead.php: session_id deterministico per telefono/nome →
// niente doppioni se Michela ridetta lo stesso cliente.
//
// Protetto come ardy-wa-lookup.php: se WA_LOOKUP_SECRET è definito,
// va passato nell'header X-Ardy-Secret (o ?secret=).
// -----------------------------------------------------------

date_default_timezone_set('Europe/Rome');

require_once __DIR__ . '/ardy-config.php';
require_once __DIR__ . '/ardy-db.php';

header('Content-Type: application/json; charset=utf-8');

// ── Protezione via segreto condiviso con n8n ──
// A differenza di ardy-wa-lookup.php (read-only), QUESTO endpoint SCRIVE nel CRM:
// fail-closed → se il segreto non è configurato, rifiuta tutto (niente write aperti).
if (!defined('WA_LOOKUP_SECRET') || WA_LOOKUP_SECRET === '') {
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'endpoint non configurato']);
    exit();
}
$sent = $_SERVER['HTTP_X_ARDY_SECRET'] ?? ($_GET['secret'] ?? '');
if (!hash_equals(WA_LOOKUP_SECRET, (string)$sent)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'non autorizzato']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Metodo non consentito']);
    exit();
}

// Stati cliente ammessi (allineati al CRM / dashboard).
$STATI_CLIENTE = ['LEAD', 'SOPRALLUOGO', 'PREVENTIVO', 'ACCONTO', 'STANDBY', 'PERSO'];

// Input: JSON nel body. Accetta sia il payload "piatto" sia {"dati":{...}}.
$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'JSON non valido']);
    exit();
}
if (isset($in['dati']) && is_array($in['dati'])) $in = $in['dati'];

// ── Normalizzazione campi (1:1 con la scheda CRM) ──
$nome     = trim((string)($in['nome'] ?? ''));
$cognome  = trim((string)($in['cognome'] ?? ''));
$telefono = preg_replace('/[^0-9+]/', '', (string)($in['telefono'] ?? ''));
$email    = trim((string)($in['email'] ?? ''));
$indirizzo= trim((string)($in['indirizzo'] ?? ''));
$zona     = trim((string)($in['zona'] ?? ''));
$servizio = trim((string)($in['servizio'] ?? ''));
$mobile   = trim((string)($in['mobile'] ?? ''));
$note     = trim((string)($in['note'] ?? ''));

// Serve almeno un identificativo per non creare schede vuote.
if ($nome === '' && $cognome === '' && $telefono === '') {
    echo json_encode(['success' => false, 'error' => 'Serve almeno nome, cognome o telefono.']);
    exit();
}

// Email palesemente non valida → scartata (niente dati sporchi nel CRM).
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $email = '';

// Stato: default LEAD (cliente nuovo dettato da Michela).
$stato = strtoupper(trim((string)($in['stato'] ?? '')));
if (!in_array($stato, $STATI_CLIENTE, true)) $stato = 'LEAD';

// session_id deterministico: stesso telefono/nome → stessa scheda (upsert).
$chiave    = $telefono !== '' ? $telefono : strtolower($nome . '|' . $cognome);
$sessionId = 'wa-' . substr(md5($chiave), 0, 16);
$cliNome   = trim($nome . ' ' . $cognome);

try {
    $db = ardyDB();

    // È già presente questa scheda? (per dire a Michela "creata" vs "aggiornata")
    $esiste = false;
    try {
        $chk = $db->prepare("SELECT 1 FROM clienti WHERE session_id = :sid LIMIT 1");
        $chk->execute([':sid' => $sessionId]);
        $esiste = (bool)$chk->fetchColumn();
    } catch (PDOException $e) { /* non bloccante */ }

    // Upsert: non sovrascrive con vuoto i campi già valorizzati.
    $sql = "INSERT INTO clienti
              (session_id, nome, cognome, telefono, email, indirizzo, zona, servizio, mobile, stato, note, created_at, updated_at)
            VALUES
              (:sid, :nome, :cognome, :tel, :email, :indir, :zona, :serv, :mobile, :stato, :note, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
              nome      = IF(VALUES(nome)     <> '', VALUES(nome),     nome),
              cognome   = IF(VALUES(cognome)  <> '', VALUES(cognome),  cognome),
              telefono  = IF(VALUES(telefono) <> '', VALUES(telefono), telefono),
              email     = IF(VALUES(email)    <> '', VALUES(email),    email),
              indirizzo = IF(VALUES(indirizzo)<> '', VALUES(indirizzo),indirizzo),
              zona      = IF(VALUES(zona)     <> '', VALUES(zona),     zona),
              servizio  = IF(VALUES(servizio) <> '', VALUES(servizio), servizio),
              mobile    = IF(VALUES(mobile)   <> '', VALUES(mobile),   mobile),
              stato     = VALUES(stato),
              note      = IF(VALUES(note)     <> '', VALUES(note),     note),
              updated_at= NOW()";
    $db->prepare($sql)->execute([
        ':sid'    => $sessionId,
        ':nome'   => $nome     ?: null,
        ':cognome'=> $cognome  ?: null,
        ':tel'    => $telefono ?: null,
        ':email'  => $email    ?: null,
        ':indir'  => $indirizzo?: null,
        ':zona'   => $zona     ?: null,
        ':serv'   => $servizio ?: null,
        ':mobile' => $mobile   ?: null,
        ':stato'  => $stato,
        ':note'   => $note     ?: null,
    ]);

    // Riepilogo per Michela (testo semplice, stile WhatsApp).
    $righe = [];
    if ($cliNome !== '')   $righe[] = $cliNome;
    if ($telefono !== '')  $righe[] = '📞 ' . $telefono;
    if ($email !== '')     $righe[] = '✉️ ' . $email;
    if ($servizio !== '')  $righe[] = 'Servizio: ' . $servizio;
    if ($mobile !== '')    $righe[] = 'Mobile: ' . $mobile;
    if ($zona !== '')      $righe[] = 'Zona: ' . $zona;
    if ($indirizzo !== '') $righe[] = 'Indirizzo: ' . $indirizzo;
    $righe[] = 'Stato: ' . $stato;
    $verbo = $esiste ? 'aggiornata' : 'creata';
    $riepilogo = "Scheda $verbo ✅\n" . implode("\n", $righe);

    echo json_encode([
        'success'    => true,
        'created'    => !$esiste,
        'session_id' => $sessionId,
        'cliente'    => $cliNome,
        'riepilogo'  => $riepilogo,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (PDOException $e) {
    error_log('ARDY WA CREA SCHEDA ERROR: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Errore interno']);
}
