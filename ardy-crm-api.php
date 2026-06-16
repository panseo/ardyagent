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

function ardy_map_cliente(array $r, bool $withDeletedAt = false, bool $haFasi = false): array {
    $out = [
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
        // Vero se esiste già almeno una fase (bozza o pubblicata) per questo cliente:
        // usato in dashboard per il badge "nota senza fasi generate".
        'ha_fasi'       => $haFasi,
    ];
    if ($withDeletedAt) {
        $out['deleted_at'] = $r['deleted_at'] ?? '';
        // Giorni rimasti prima della purga automatica
        $giorni = null;
        if (!empty($r['deleted_at'])) {
            $diff   = (new DateTime())->diff(new DateTime($r['deleted_at']));
            $giorni = max(0, 30 - (int) $diff->days);
        }
        $out['giorni_rimasti'] = $giorni;
    }
    return $out;
}

try {
    $db   = ardyDB();

    // Assicura che la colonna deleted_at esista (DDL idempotente)
    try { $db->exec("ALTER TABLE clienti ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL"); }
    catch (PDOException $e) { /* già presente */ }

    // ── Vista Cestino ──────────────────────────────────────
    if (($_GET['vista'] ?? '') === 'cestino') {
        $rows = $db->query(
            "SELECT * FROM clienti WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC"
        )->fetchAll();
        echo json_encode(array_map(fn($r) => ardy_map_cliente($r, true), $rows));
        exit();
    }

    // ── Lista normale (esclusi i cestinati) ────────────────
    // Una sola query aggregata per sapere quali clienti hanno già almeno una
    // fase (bozza o pubblicata), evitando una query per cliente.
    $fasiMap = [];
    try {
        $fr = $db->query("SELECT session_id, COUNT(*) AS c FROM fasi GROUP BY session_id")->fetchAll();
        foreach ($fr as $row) { $fasiMap[$row['session_id']] = ((int) $row['c']) > 0; }
    } catch (PDOException $e) { /* tabella fasi non ancora creata: nessun cliente ha fasi */ }

    $rows = $db->query(
        "SELECT * FROM clienti WHERE deleted_at IS NULL ORDER BY updated_at DESC"
    )->fetchAll();
    echo json_encode(array_map(fn($r) => ardy_map_cliente($r, false, $fasiMap[$r['session_id']] ?? false), $rows));

} catch (PDOException $e) {
    error_log('ARDY CRM API ERROR: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Errore interno']);
}
