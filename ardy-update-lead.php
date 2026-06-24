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
        'trasporto_data',
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

    // Transizione → cliente "reale" (firma/Acconto e oltre): aggiunge il cliente
    // ai contatti outreach (categoria "clienti", stato 'cliente' → riattivazione/
    // servizio, NON cold-marketing). Scatta quando entra per la prima volta in uno
    // stato "impegnato" (gestisce anche il salto diretto Acconto→Ritirati/Lavorazione).
    // Idempotente (dedup nella lib), best-effort: non blocca il salvataggio.
    if (array_key_exists('stato', $input)) {
        $statiImpegnati = ['ACCONTO', 'RITIRATI', 'IN_LAVORAZIONE', 'COMPLETATO', 'CONSEGNATO', 'PAGATO'];
        $statoNuovo = strtoupper((string) $input['stato']);
        if (in_array($statoNuovo, $statiImpegnati, true)
            && !in_array((string) $statoVecchio, $statiImpegnati, true)) {
            try {
                require_once __DIR__ . '/ardy-outreach-lib.php';
                ardy_outreach_aggiungi_cliente($db, $sessionId, ucfirst(strtolower($statoNuovo)));
            } catch (Throwable $e) {
                error_log('ARDY UPDATE LEAD outreach add: ' . $e->getMessage());
            }
        }
    }

    // Nota: la "Data e ora sopralluogo" NON passa più da qui — ora i sopralluoghi
    // (anche più d'uno) sono gestiti da ardy-sopralluoghi-api.php, che cura calendario
    // e allineamento di clienti.sopralluogo_at/gcal_event_id.

    // Data di consegna impostata/cambiata dalla dashboard → invia al cliente l'email di
    // conferma (consegna/ritiro) riusando il modulo Trasporti. La funzione ha già il
    // guard "una sola email per data" (trasporto_avviso_data): ri-salvare la stessa data
    // NON re-invia. Best-effort, non blocca il salvataggio.
    if (array_key_exists('trasporto_data', $input) && !empty($input['trasporto_data'])) {
        try {
            require_once __DIR__ . '/ardy-trasporti.php';
            ardy_invia_avviso_trasporto($db, $sessionId);
        } catch (Throwable $e) {
            error_log('ARDY UPDATE LEAD avviso consegna: ' . $e->getMessage());
        }
    }

    echo json_encode(['success' => true]);

} catch (PDOException $e) {
    error_log('ARDY UPDATE LEAD ERROR: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Errore interno']);
}
