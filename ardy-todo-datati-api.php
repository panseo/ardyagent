<?php
// -----------------------------------------------------------
// ARDY LAB — Cose da fare CON data (staff) — endpoint dashboard
// Gemello "datato" della nota settimanale: le voci senza data restano nel testo
// libero (ardy-nota-settimanale-api.php, anche su Sole); QUESTE invece occupano
// uno slot su Google Calendar, come i sopralluoghi, ma sono dello STAFF (nessun
// cliente). Per ora si gestiscono SOLO da dashboard.
//
//   GET                                   → lista (oggi e futuro, ordinata per data)
//   POST {mode:'salva', id?, testo, data_ora} → crea (id=0) o sposta/aggiorna (id>0)
//   POST {mode:'delete', id}              → elimina (+ cancella l'evento gcal)
//
// Protetto via .htaccess (Basic Auth) — è elencato nel <FilesMatch>.
// -----------------------------------------------------------

date_default_timezone_set('Europe/Rome');
require_once __DIR__ . '/ardy-db.php';    // tira dentro ardy-config
require_once __DIR__ . '/ardy-gcal.php';

header('Access-Control-Allow-Origin: https://ardyagent.ardy-lab.it');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }
// Difesa in profondità: se il Basic Auth a monte (.htaccess) non venisse
// applicato, questo guard rifiuta comunque le richieste non autenticate.
require_once __DIR__ . '/ardy-auth.php';
ardyRequireAuth();

// "YYYY-MM-DDTHH:MM" / "YYYY-MM-DD HH:MM[:SS]" → "YYYY-MM-DD HH:MM:SS" oppure null.
function todo_norm_data($raw): ?string {
    $s = str_replace('T', ' ', trim((string) $raw));
    if ($s === '') return null;
    if (strlen($s) === 16) $s .= ':00';            // manca il campo secondi
    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $s);
    return $dt ? $dt->format('Y-m-d H:i:s') : null;
}

// Titolo evento calendario per una cosa da fare dello staff.
function todo_summary(string $testo): string {
    return '✅ Da fare: ' . $testo;
}

try {
    $db = ardyDB();

    // ── GET: lista (oggi + futuro) ──────────────────────────
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $st = $db->query("SELECT id, testo, data_ora, gcal_event_id
                            FROM todo_datati
                           WHERE data_ora >= CURDATE()
                           ORDER BY data_ora ASC");
        echo json_encode(['success' => true, 'items' => $st->fetchAll(PDO::FETCH_ASSOC) ?: []]);
        exit();
    }

    // ── POST ────────────────────────────────────────────────
    $in   = json_decode(file_get_contents('php://input'), true) ?: [];
    $mode = $in['mode'] ?? '';

    if ($mode === 'delete') {
        $id = (int) ($in['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['success' => false, 'error' => 'id mancante']); exit(); }
        $st = $db->prepare("SELECT gcal_event_id FROM todo_datati WHERE id = ? LIMIT 1");
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) { echo json_encode(['success' => false, 'error' => 'Voce non trovata']); exit(); }
        if (!empty($row['gcal_event_id'])) {
            try { gcal_delete_event($row['gcal_event_id']); }
            catch (Throwable $e) { error_log('ARDY TODO DATATI delete gcal: ' . $e->getMessage()); }
        }
        $db->prepare("DELETE FROM todo_datati WHERE id = ?")->execute([$id]);
        echo json_encode(['success' => true]);
        exit();
    }

    if ($mode === 'salva') {
        $id      = (int) ($in['id'] ?? 0);
        $testo   = trim((string) ($in['testo'] ?? ''));
        $dataOra = todo_norm_data($in['data_ora'] ?? '');
        if ($testo === '')      { echo json_encode(['success' => false, 'error' => 'Scrivi cosa c\'è da fare']); exit(); }
        if (mb_strlen($testo) > 255) $testo = mb_substr($testo, 0, 255);
        if ($dataOra === null)  { echo json_encode(['success' => false, 'error' => 'Data/ora non valida']); exit(); }

        $dt      = new DateTime($dataOra);
        $dateStr = $dt->format('Y-m-d');
        $timeStr = $dt->format('H:i');
        $summary = todo_summary($testo);
        $eid     = null;

        if ($id > 0) {
            $st = $db->prepare("SELECT gcal_event_id FROM todo_datati WHERE id = ? LIMIT 1");
            $st->execute([$id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) { echo json_encode(['success' => false, 'error' => 'Voce non trovata']); exit(); }
            $eid = $row['gcal_event_id'] ?? null;
            try {
                if (!empty($eid)) {
                    // Aggiorna data/ora E titolo (il testo potrebbe essere cambiato).
                    gcal_update_event($eid, $dateStr, $timeStr, 1, $summary);
                } else {
                    $ev = gcal_create_simple($dateStr, $timeStr, $summary, 'Cosa da fare — Ardy Lab', 1);
                    if (is_array($ev) && !empty($ev['id'])) $eid = $ev['id'];
                }
            } catch (Throwable $e) { error_log('ARDY TODO DATATI salva gcal(upd): ' . $e->getMessage()); }
            $db->prepare("UPDATE todo_datati SET testo = :t, data_ora = :d, gcal_event_id = :e, updated_at = NOW() WHERE id = :id")
               ->execute([':t' => $testo, ':d' => $dataOra, ':e' => $eid, ':id' => $id]);
        } else {
            try {
                $ev = gcal_create_simple($dateStr, $timeStr, $summary, 'Cosa da fare — Ardy Lab', 1);
                if (is_array($ev) && !empty($ev['id'])) $eid = $ev['id'];
            } catch (Throwable $e) { error_log('ARDY TODO DATATI salva gcal(new): ' . $e->getMessage()); }
            $db->prepare("INSERT INTO todo_datati (testo, data_ora, gcal_event_id, created_at, updated_at) VALUES (:t, :d, :e, NOW(), NOW())")
               ->execute([':t' => $testo, ':d' => $dataOra, ':e' => $eid]);
            $id = (int) $db->lastInsertId();
        }

        echo json_encode(['success' => true, 'id' => $id]);
        exit();
    }

    echo json_encode(['success' => false, 'error' => 'mode non valido']);

} catch (PDOException $e) {
    error_log('ARDY TODO DATATI API ERROR: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Errore interno']);
}
