<?php
// -----------------------------------------------------------
// ARDY LAB — Contatore visite pagina (chat lead)
// Chiamato dal widget al caricamento pagina. Registra visite
// totali e visitatori unici/giorno nella tabella visite_pagina.
// -----------------------------------------------------------

require_once __DIR__ . '/ardy-db.php';

// Pagine che si possono contare (SECURITY-AUDIT §10.5). L'endpoint è pubblico:
// senza elenco chiuso chiunque poteva creare righe con nomi inventati e
// gonfiare/sporcare le statistiche in ardy-stats.php. Una pagina nuova col
// contatore si aggiunge qui oppure, senza toccare il codice, in ardy-config.php:
//   define('ARDY_VISITA_PAGINE', ['chat-lead', 'nuova-pagina']);
const ARDY_VISITA_PAGINE_DEFAULT = ['chat-lead'];

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
$pagineAmmesse = (defined('ARDY_VISITA_PAGINE') && is_array(constant('ARDY_VISITA_PAGINE')))
    ? constant('ARDY_VISITA_PAGINE')
    : ARDY_VISITA_PAGINE_DEFAULT;
if (!in_array($pagina, $pagineAmmesse, true)) {
    // 200 e non 4xx: il widget non deve mostrare errori al visitatore. Il nome
    // resta nel log (troncato) così una pagina legittima dimenticata si nota.
    error_log('ARDY VISITA: pagina non in elenco, ignorata: ' . substr($pagina, 0, 40));
    echo json_encode(['success' => false]);
    exit();
}
$unique = !empty($input['unique']) ? 1 : 0;

try {
    $db = ardyDB();
    // Tabella visite_pagina creata da ardy-migrate.php.
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
