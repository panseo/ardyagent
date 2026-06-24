<?php
// -----------------------------------------------------------
// ARDY LAB — Nota settimanale "cose da fare" dello staff — endpoint dashboard
// Stessa fonte che Sole legge/scrive su WhatsApp (tabella note_staff): ogni
// salvataggio è una riga nuova (storico settimane), si legge sempre la più
// recente. Così la lista vive sia su WhatsApp sia in dashboard, allineata.
//
//   GET                 → nota più recente {testo, settimana, created_at}
//   POST {testo}        → salva (INSERT nuova riga, settimana = ISO corrente)
//
// Protetto via .htaccess (Basic Auth) — è elencato nel <FilesMatch>.
// -----------------------------------------------------------

require_once __DIR__ . '/ardy-db.php';

header('Access-Control-Allow-Origin: https://ardyagent.ardy-lab.it');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

try {
    $db = ardyDB();

    // ── GET: nota più recente ───────────────────────────────
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $row = $db->query("SELECT testo, settimana, created_at FROM note_staff ORDER BY id DESC LIMIT 1")
                  ->fetch(PDO::FETCH_ASSOC);
        echo json_encode([
            'success'    => true,
            'testo'      => $row ? (string) $row['testo'] : '',
            'settimana'  => $row ? (string) $row['settimana'] : '',
            'created_at' => $row ? (string) $row['created_at'] : '',
        ]);
        exit();
    }

    // ── POST: salva (nuova riga) ────────────────────────────
    $in    = json_decode(file_get_contents('php://input'), true) ?: [];
    $testo = trim((string) ($in['testo'] ?? ''));
    if ($testo === '') { echo json_encode(['success' => false, 'error' => 'Nota vuota']); exit(); }

    $sett = date('o-\WW'); // es. 2026-W26 — stessa codifica di Sole su WhatsApp
    $db->prepare("INSERT INTO note_staff (settimana, testo, created_at) VALUES (:s, :t, NOW())")
       ->execute([':s' => $sett, ':t' => $testo]);

    echo json_encode(['success' => true, 'settimana' => $sett, 'created_at' => date('Y-m-d H:i:s')]);

} catch (PDOException $e) {
    error_log('ARDY NOTA SETTIMANALE API ERROR: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Errore interno']);
}
