<?php
// -----------------------------------------------------------
// ARDY LAB — UPDATE Cliente su DB
// Usato dalla dashboard Michela per aggiornare stato/note/followup
// -----------------------------------------------------------

require_once __DIR__ . '/ardy-db.php';

header('Access-Control-Allow-Origin: https://ardyagent.ardy-lab.it');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

$input     = json_decode(file_get_contents('php://input'), true);
$sessionId = $input['session_id'] ?? '';

if (empty($sessionId)) {
    echo json_encode(['success' => false, 'error' => 'session_id mancante']);
    exit();
}

try {
    $db = ardyDB();

    // Colonne lavorazione (idempotente): date inizio/fine prevista del lavoro.
    try {
        if (!$db->query("SHOW COLUMNS FROM clienti LIKE 'inizio_lavoro'")->fetch()) {
            $db->exec("ALTER TABLE clienti ADD COLUMN inizio_lavoro DATE NULL");
        }
        if (!$db->query("SHOW COLUMNS FROM clienti LIKE 'fine_lavoro_prevista'")->fetch()) {
            $db->exec("ALTER TABLE clienti ADD COLUMN fine_lavoro_prevista DATE NULL");
        }
        // Note consegna: promemoria operativo per la consegna (materiali mancanti,
        // bulloni, accordi logistici…). Editabile in dashboard, letto da Sole.
        if (!$db->query("SHOW COLUMNS FROM clienti LIKE 'note_consegna'")->fetch()) {
            $db->exec("ALTER TABLE clienti ADD COLUMN note_consegna TEXT NULL");
        }
    } catch (PDOException $e) {
        error_log('ARDY UPDATE LEAD ENSURE COLS: ' . $e->getMessage());
    }

    $fields = [
        'nome', 'cognome', 'telefono', 'email',
        'servizio', 'mobile', 'zona', 'budget',
        'indirizzo', 'stato', 'note', 'note_consegna',
        'data_followup', 'inizio_lavoro', 'fine_lavoro_prevista',
        'wp_post_id', 'wp_post_link'
    ];

    $set    = ['`updated_at` = NOW()']; // aggiorna sempre il timestamp
    $params = [];

    foreach ($fields as $f) {
        if (array_key_exists($f, $input)) {
            $set[]      = "`$f` = :$f";
            $params[$f] = $input[$f] === '' ? null : $input[$f];
        }
    }

    if (count($set) === 1) { // solo updated_at, nessun campo reale
        echo json_encode(['success' => false, 'error' => 'Nessun campo da aggiornare']);
        exit();
    }

    $params['session_id'] = $sessionId;

    // Stato precedente: serve per rilevare la transizione → CONSEGNATO (ringraziamento)
    $statoVecchio = null;
    if (array_key_exists('stato', $input)) {
        try {
            $qs = $db->prepare("SELECT stato FROM clienti WHERE session_id = :sid LIMIT 1");
            $qs->execute([':sid' => $sessionId]);
            $statoVecchio = strtoupper((string) $qs->fetchColumn());
        } catch (PDOException $e) { /* ignora */ }
    }

    $sql = "UPDATE `clienti` SET " . implode(', ', $set) . " WHERE `session_id` = :session_id";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    if ($stmt->rowCount() === 0) {
        echo json_encode(['success' => false, 'error' => 'Nessun record trovato per questa sessione']);
        exit();
    }

    // Transizione → CONSEGNATO: invia il ringraziamento al cliente (email + WhatsApp),
    // una sola volta (guard interno su consegnato_grazie_at). Non blocca la risposta.
    if (array_key_exists('stato', $input)
        && strtoupper((string) $input['stato']) === 'CONSEGNATO'
        && $statoVecchio !== 'CONSEGNATO') {
        try {
            require_once __DIR__ . '/ardy-grazie-consegna.php';
            ardy_invia_grazie_consegna($db, $sessionId);
        } catch (Throwable $e) {
            error_log('ARDY UPDATE LEAD grazie consegna: ' . $e->getMessage());
        }
    }

    // Transizione → COMPLETATO: avvisa il cliente che il mobile è pronto (email),
    // una sola volta (guard interno su trasporto_pronto_at). La data di consegna
    // arriverà poi dalla "giornata Trasporti" in dashboard.
    if (array_key_exists('stato', $input)
        && strtoupper((string) $input['stato']) === 'COMPLETATO'
        && $statoVecchio !== 'COMPLETATO') {
        try {
            require_once __DIR__ . '/ardy-trasporti.php';
            ardy_invia_pronto($db, $sessionId);
        } catch (Throwable $e) {
            error_log('ARDY UPDATE LEAD pronto: ' . $e->getMessage());
        }
    }

    echo json_encode(['success' => true]);

} catch (PDOException $e) {
    error_log('ARDY UPDATE LEAD ERROR: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Errore interno']);
}
