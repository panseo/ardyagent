<?php
// -----------------------------------------------------------
// ARDY LAB — Verifica Cliente per Widget Lavorazione
// -----------------------------------------------------------

require_once __DIR__ . '/ardy-config.php';
require_once __DIR__ . '/ardy-db.php';

header('Access-Control-Allow-Origin: https://ardy-lab.it');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);
$telefono = trim($input['telefono'] ?? '');
$wpPostId = trim($input['wp_post_id'] ?? '');

if (empty($telefono) || empty($wpPostId)) {
    echo json_encode(['verified' => false, 'error' => 'Dati mancanti']);
    exit();
}

// Normalizza telefono: rimuovi spazi, +, trattini
$telNorm = preg_replace('/[\s\+\-\(\)]/', '', $telefono);
// Se inizia con 39, toglilo per il confronto
$telShort = preg_replace('/^39/', '', $telNorm);

try {
    $db = ardyDB();
    $stmt = $db->prepare("SELECT nome, cognome, telefono FROM clienti WHERE wp_post_id = :wid");
    $stmt->execute([':wid' => $wpPostId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo json_encode(['verified' => false, 'error' => 'Lavorazione non trovata']);
        exit();
    }

    // Normalizza telefono dal DB
    $dbTel = preg_replace('/[\s\+\-\(\)]/', '', $row['telefono']);
    $dbTelShort = preg_replace('/^39/', '', $dbTel);

    // Confronto: ultimi 7 cifre (per evitare problemi di prefisso)
    $match = (substr($telShort, -7) === substr($dbTelShort, -7));

    if ($match) {
        $nome = trim(($row['nome'] ?? '') . ' ' . ($row['cognome'] ?? ''));
        echo json_encode(['verified' => true, 'nome' => $nome]);
    } else {
        echo json_encode(['verified' => false, 'error' => 'Numero non corrispondente']);
    }

} catch (PDOException $e) {
    error_log('ARDY VERIFY ERROR: ' . $e->getMessage());
    echo json_encode(['verified' => false, 'error' => 'Errore di sistema']);
}
