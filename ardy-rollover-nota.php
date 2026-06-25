<?php
// -----------------------------------------------------------
// ARDY LAB — Rollover settimanale della nota "cose da fare"
// Chiamato da CRON il LUNEDÌ mattina (prima del briefing delle 9:00). Prende
// l'ultima nota dello staff (tabella note_staff), TOGLIE le voci marcate fatte
// (✔ ✓ ✅ oppure "[x]") e RIPORTA le non evase in una riga nuova con la
// settimana ISO corrente. Così il lunedì la lista riparte pulita.
//
// Auth: segreto condiviso WA_LOOKUP_SECRET (header X-Ardy-Secret o ?secret=).
// NIENTE Basic Auth (lo chiama il cron).
//
// Idempotente: se l'ultima nota è GIÀ della settimana corrente (già rollata o
// già curata a mano), non fa nulla. ?force=1 forza comunque (per i test).
//
// Cron consigliato (server, fuso Europe/Rome) — lunedì 06:00:
//   0 6 * * 1 curl -s -H "X-Ardy-Secret: <SEGRETO>" https://ardyagent.ardy-lab.it/ardy-rollover-nota.php >/dev/null 2>&1
// -----------------------------------------------------------

date_default_timezone_set('Europe/Rome');

require_once __DIR__ . '/ardy-config.php';
require_once __DIR__ . '/ardy-db.php';
require_once __DIR__ . '/ardy-briefing-lib.php';

header('Content-Type: application/json');

// ── Auth: segreto condiviso ──────────────────────────────────────────────────
if (!defined('WA_LOOKUP_SECRET') || WA_LOOKUP_SECRET === '') {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'WA_LOOKUP_SECRET non configurato']);
    exit();
}
$sent = $_SERVER['HTTP_X_ARDY_SECRET'] ?? ($_GET['secret'] ?? '');
if (!hash_equals(WA_LOOKUP_SECRET, (string) $sent)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'non autorizzato']);
    exit();
}

$force = (($_GET['force'] ?? '') === '1');
$sett  = date('o-\WW'); // settimana ISO corrente, es. 2026-W26 — stessa codifica dei salvataggi

try {
    $db  = ardyDB();
    $row = $db->query("SELECT id, testo, settimana FROM note_staff ORDER BY id DESC LIMIT 1")
              ->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo json_encode(['success' => true, 'skipped' => 'nessuna_nota']);
        exit();
    }

    // Già rollata/curata per la settimana corrente → niente da fare (idempotente).
    if (!$force && (string) $row['settimana'] === $sett) {
        echo json_encode(['success' => true, 'skipped' => 'gia_corrente', 'settimana' => $sett]);
        exit();
    }

    $res = ardy_nota_strip_fatte((string) $row['testo']);

    // Niente righe-attività nella nota di partenza → niente da riportare.
    if ($res['totali'] === 0) {
        echo json_encode(['success' => true, 'skipped' => 'nota_vuota', 'settimana' => $sett]);
        exit();
    }

    // Inserisce la nota della nuova settimana (testo può essere '' se era tutto fatto).
    $db->prepare("INSERT INTO note_staff (settimana, testo, created_at) VALUES (:s, :t, NOW())")
       ->execute([':s' => $sett, ':t' => $res['testo']]);

    echo json_encode([
        'success'    => true,
        'settimana'  => $sett,
        'totali'     => $res['totali'],
        'fatte_rimosse' => $res['rimosse'],
        'riportate'  => max(0, $res['totali'] - $res['rimosse']),
    ]);

} catch (PDOException $e) {
    error_log('ARDY ROLLOVER NOTA ERROR: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'errore database']);
}
