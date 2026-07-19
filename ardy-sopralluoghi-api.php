<?php
// -----------------------------------------------------------
// ARDY LAB — Sopralluoghi di un cliente (lista N visite) — endpoint dashboard
// Un cliente può avere più sopralluoghi (1°, 2°, sopralluogo colori, ecc.).
// La logica vera (calendario + allineamento) sta in ardy-sopralluoghi-lib.php,
// condivisa con Sole su WhatsApp.
//
//   GET  ?session_id=...            → lista sopralluoghi del cliente (ordinati)
//   POST {mode:'salva', session_id, id?, data_ora, tipo?, etichetta?, note?}
//                                   → crea (id=0) o sposta/aggiorna (id>0)
//                                   → tipo: 'sopralluogo' (default) | 'ritiro' | 'intervento' | 'consegna'
//   POST {mode:'delete', session_id, id}
//                                   → elimina (+ cancella l'evento gcal)
//
// Protetto via .htaccess (Basic Auth) — è elencato nel <FilesMatch>.
// -----------------------------------------------------------

require_once __DIR__ . '/ardy-db.php';
require_once __DIR__ . '/ardy-sopralluoghi-lib.php';

header('Access-Control-Allow-Origin: https://ardyagent.ardy-lab.it');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }
// Difesa in profondità: se il Basic Auth a monte (.htaccess) non venisse
// applicato, questo guard rifiuta comunque le richieste non autenticate.
require_once __DIR__ . '/ardy-auth.php';
ardyRequireAuth();

function sopr_clean_sid(string $s): string {
    return preg_replace('/[^a-zA-Z0-9_\-]/', '', $s);
}

try {
    $db = ardyDB();

    // ── GET: lista ──────────────────────────────────────────
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $sid = sopr_clean_sid($_GET['session_id'] ?? '');
        if ($sid === '') { echo json_encode(['success' => false, 'error' => 'session_id mancante']); exit(); }
        echo json_encode(['success' => true, 'items' => sopr_list($db, $sid)]);
        exit();
    }

    // ── POST: salva / delete ────────────────────────────────
    $in   = json_decode(file_get_contents('php://input'), true) ?: [];
    $mode = $in['mode'] ?? '';
    $sid  = sopr_clean_sid($in['session_id'] ?? '');
    if ($sid === '') { echo json_encode(['success' => false, 'error' => 'session_id mancante']); exit(); }

    if ($mode === 'delete') {
        $id = (int) ($in['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['success' => false, 'error' => 'id mancante']); exit(); }
        $ok = sopr_elimina($db, $sid, $id);
        echo json_encode($ok ? ['success' => true] : ['success' => false, 'error' => 'Sopralluogo non trovato']);
        exit();
    }

    if ($mode === 'salva') {
        $id        = (int) ($in['id'] ?? 0);
        $dataOra   = sopr_norm_data($in['data_ora'] ?? '');
        $tipo      = in_array($in['tipo'] ?? '', sopr_tipi_validi(), true) ? (string) $in['tipo'] : 'sopralluogo';
        $etichetta = trim((string) ($in['etichetta'] ?? ''));
        $note      = trim((string) ($in['note'] ?? ''));
        if ($dataOra === null) { echo json_encode(['success' => false, 'error' => 'Data/ora non valida']); exit(); }
        $cli = sopr_get_cliente($db, $sid);
        $res = sopr_salva($db, $sid, $id, $dataOra, $etichetta, $note, $cli, $tipo);
        if ($res === null) { echo json_encode(['success' => false, 'error' => 'Sopralluogo non trovato']); exit(); }
        echo json_encode(['success' => true, 'id' => $res['id']]);
        exit();
    }

    echo json_encode(['success' => false, 'error' => 'mode non valido']);

} catch (PDOException $e) {
    error_log('ARDY SOPRALLUOGHI API ERROR: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Errore interno']);
}
