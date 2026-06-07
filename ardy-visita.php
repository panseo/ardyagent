<?php
// -----------------------------------------------------------
// ARDY LAB — Contatore visite pagina (chat lead)
// Chiamato dal widget al caricamento pagina. Registra visite
// totali e visitatori unici/giorno nella tabella visite_pagina.
// -----------------------------------------------------------

require_once __DIR__ . '/ardy-db.php';

$allowedOrigins = ['https://ardy-lab.it', 'https://www.ardy-lab.it', 'https://ardyagent.ardy-lab.it'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
header('Access-Control-Allow-Origin: ' . (in_array($origin, $allowedOrigins) ? $origin : 'https://ardy-lab.it'));
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

$input  = json_decode(file_get_contents('php://input'), true) ?: [];
$pagina = preg_replace('/[^a-z0-9_\-]/', '', strtolower($input['pagina'] ?? ''));
if ($pagina === '') $pagina = 'chat-lead';
$unique = !empty($input['unique']) ? 1 : 0;

try {
    $db = ardyDB();
    $db->exec("
        CREATE TABLE IF NOT EXISTS `visite_pagina` (
            `pagina` VARCHAR(64) NOT NULL,
            `giorno` DATE NOT NULL,
            `visite` INT NOT NULL DEFAULT 0,
            `unici`  INT NOT NULL DEFAULT 0,
            PRIMARY KEY (`pagina`, `giorno`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $st = $db->prepare("
        INSERT INTO `visite_pagina` (`pagina`, `giorno`, `visite`, `unici`)
        VALUES (:p, CURDATE(), 1, :u)
        ON DUPLICATE KEY UPDATE `visite` = `visite` + 1, `unici` = `unici` + :u2
    ");
    $st->execute([':p' => $pagina, ':u' => $unique, ':u2' => $unique]);
    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    error_log('ARDY VISITA ERROR: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false]);
}
