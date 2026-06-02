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

    $fields = [
        'nome', 'cognome', 'telefono', 'email',
        'servizio', 'mobile', 'zona', 'budget',
        'indirizzo', 'stato', 'note',
        'data_followup', 'wp_post_id', 'wp_post_link'
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
    $sql = "UPDATE `clienti` SET " . implode(', ', $set) . " WHERE `session_id` = :session_id";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    if ($stmt->rowCount() === 0) {
        echo json_encode(['success' => false, 'error' => 'Nessun record trovato per questa sessione']);
        exit();
    }

    echo json_encode(['success' => true]);

} catch (PDOException $e) {
    error_log('ARDY UPDATE LEAD ERROR: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
