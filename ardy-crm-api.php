<?php
// -----------------------------------------------------------
// ARDY LAB — GET Clienti da DB
// Restituisce i campi con nomi PascalCase compatibili con la dashboard
// -----------------------------------------------------------

require_once __DIR__ . '/ardy-db.php';

header('Access-Control-Allow-Origin: https://ardyagent.ardy-lab.it');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

try {
    $db   = ardyDB();
    $rows = $db->query("SELECT * FROM clienti ORDER BY updated_at DESC")->fetchAll();

    // Mappa nomi colonne MySQL → PascalCase attesi dalla dashboard JS
    $mapped = array_map(function($r) {
        return [
            'Session_ID'    => $r['session_id']    ?? '',
            'Nome'          => $r['nome']           ?? '',
            'Cognome'       => $r['cognome']        ?? '',
            'Telefono'      => $r['telefono']       ?? '',
            'Email'         => $r['email']          ?? '',
            'Servizio'      => $r['servizio']       ?? '',
            'Mobile'        => $r['mobile']         ?? '',
            'Zona'          => $r['zona']           ?? '',
            'Budget'        => $r['budget']         ?? '',
            'Indirizzo'     => $r['indirizzo']      ?? '',
            'Stato'         => $r['stato']          ?? 'LEAD',
            'Note'          => $r['note']           ?? '',
            'Data_followup' => $r['data_followup']  ?? '',
            'Inizio_lavoro'         => $r['inizio_lavoro']        ?? '',
            'Fine_lavoro_prevista'  => $r['fine_lavoro_prevista'] ?? '',
            'wp_post_id'    => $r['wp_post_id']     ?? '',
            'wp_post_link'  => $r['wp_post_link']   ?? '',
            'foto_archiviate_at' => $r['foto_archiviate_at'] ?? '',
            'faq_pubblicata_at'  => $r['faq_pubblicata_at']  ?? '',
            'created_at'    => $r['created_at']     ?? '',
            'updated_at'    => $r['updated_at']     ?? '',
        ];
    }, $rows);

    echo json_encode($mapped);

} catch (PDOException $e) {
    error_log('ARDY CRM API ERROR: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Errore interno']);
}
