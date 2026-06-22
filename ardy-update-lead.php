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
        'indirizzo', 'stato', 'note', 'note_consegna',
        'data_followup', 'inizio_lavoro', 'fine_lavoro_prevista',
        'sopralluogo_at',
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

    // Data e ora sopralluogo impostata/cambiata dalla dashboard → sincronizza Google
    // Calendar: aggiorna l'evento se la scheda ne ha già uno, altrimenti lo crea
    // (anti-doppione: un solo evento per scheda). Best-effort, non blocca il salvataggio.
    if (array_key_exists('sopralluogo_at', $input) && !empty($input['sopralluogo_at'])) {
        try {
            require_once __DIR__ . '/ardy-gcal.php';
            $qc = $db->prepare("SELECT nome, cognome, zona, telefono, email, gcal_event_id FROM clienti WHERE session_id = :sid LIMIT 1");
            $qc->execute([':sid' => $sessionId]);
            $cli = $qc->fetch(PDO::FETCH_ASSOC);
            if ($cli) {
                $dt      = new DateTime((string) $input['sopralluogo_at']);
                $dateStr = $dt->format('Y-m-d');
                $timeStr = $dt->format('H:i');
                if (!empty($cli['gcal_event_id'])) {
                    // C'è già l'evento: lo SPOSTA alla nuova data/ora (niente doppione).
                    gcal_update_event($cli['gcal_event_id'], $dateStr, $timeStr, 2);
                } else {
                    $nm      = trim(($cli['nome'] ?? '') . ' ' . ($cli['cognome'] ?? ''));
                    $summary = 'Sopralluogo Ardy Lab' . (!empty($cli['zona']) ? ' — ' . $cli['zona'] : '') . ($nm !== '' ? ' / ' . $nm : '');
                    $ev = gcal_create_event($dateStr, $timeStr, $summary, (string) ($cli['telefono'] ?? ''), (string) ($cli['email'] ?? ''), '', '');
                    if (is_array($ev) && !empty($ev['id'])) {
                        $db->prepare("UPDATE clienti SET gcal_event_id = :eid WHERE session_id = :sid")
                           ->execute([':eid' => $ev['id'], ':sid' => $sessionId]);
                    }
                }
            }
        } catch (Throwable $e) {
            error_log('ARDY UPDATE LEAD sopralluogo gcal: ' . $e->getMessage());
        }
    }

    echo json_encode(['success' => true]);

} catch (PDOException $e) {
    error_log('ARDY UPDATE LEAD ERROR: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Errore interno']);
}
