<?php
// -----------------------------------------------------------
// ARDY LAB — GET Fasi Lavorazione
// -----------------------------------------------------------

require_once __DIR__ . '/ardy-db.php';

header('Access-Control-Allow-Origin: https://ardyagent.ardy-lab.it');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

$sessionId = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_GET['session_id'] ?? '');

if (empty($sessionId)) {
    echo json_encode([]);
    exit();
}

try {
    $db   = ardyDB();
    $stmt = $db->prepare("SELECT * FROM fasi WHERE session_id = ? ORDER BY created_at ASC");
    $stmt->execute([$sessionId]);
    $fasi = $stmt->fetchAll();

    // Decode foto_urls JSON
    foreach ($fasi as &$f) {
        $f['foto_urls'] = json_decode($f['foto_urls'] ?? '[]', true) ?? [];
    }

    echo json_encode($fasi);

} catch (PDOException $e) {
    error_log('ARDY GET FASI ERROR: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Errore interno']);
}
