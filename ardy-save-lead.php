<?php
// -----------------------------------------------------------
// ARDY LAB — SAVE Lead su DB (INSERT o UPDATE)
// Chiamato da ardy-proxy.php quando Claude usa il tool salva_lead_crm
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

// IP del visitatore (passato dal proxy, o rilevato qui)
$ip = $input['ip_address'] ?? ($_SERVER['REMOTE_ADDR'] ?? null);

try {
    $db = ardyDB();

    // Campi accettati in INSERT/UPDATE
    $fields = [
        'nome', 'cognome', 'telefono', 'email',
        'servizio', 'mobile', 'zona', 'budget',
        'indirizzo', 'stato', 'note',
        'data_followup', 'wp_post_id', 'wp_post_link'
    ];

    // Costruisci array dei valori presenti nell'input
    $values = ['session_id' => $sessionId, 'ip_address' => $ip];
    foreach ($fields as $f) {
        $values[$f] = isset($input[$f]) && $input[$f] !== ''
            ? $input[$f]
            : null;
    }
    // Default stato
    if (empty($values['stato'])) {
        $values['stato'] = 'LEAD';
    }

    // INSERT ... ON DUPLICATE KEY UPDATE
    // La UNIQUE KEY su session_id garantisce l'upsert
    $cols    = implode(', ', array_map(fn($k) => "`$k`", array_keys($values)));
    $placeholders = implode(', ', array_map(fn($k) => ":$k", array_keys($values)));

    // Per l'UPDATE parte: aggiorna solo i campi non NULL
    $updateParts = [];
    foreach ($fields as $f) {
        // Aggiorna solo se il valore è presente (non null) — non sovrascrivere
        // dati esistenti con null se il campo non è stato inviato
        $updateParts[] = "`$f` = IF(:upd_$f IS NOT NULL, :upd_$f, `$f`)";
        $values["upd_$f"] = $values[$f]; // duplica per il binding ON DUPLICATE
    }
    $updateParts[] = "`updated_at` = NOW()";

    $sql = "INSERT INTO `clienti` ($cols) VALUES ($placeholders)
            ON DUPLICATE KEY UPDATE " . implode(', ', $updateParts);

    $db->prepare($sql)->execute($values);

    echo json_encode(['success' => true]);

} catch (PDOException $e) {
    error_log('ARDY SAVE LEAD ERROR: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
