<?php
// -----------------------------------------------------------
// ARDY LAB — Sopralluoghi di un cliente (lista N visite)
// Un cliente può avere più sopralluoghi (1°, 2°, sopralluogo colori, ecc.).
// Ogni riga ha la sua data/ora e il suo evento Google Calendar.
//
//   GET  ?session_id=...            → lista sopralluoghi del cliente (ordinati)
//   POST {mode:'salva', session_id, id?, data_ora, etichetta?, note?}
//                                   → crea (id=0) o sposta/aggiorna (id>0) un
//                                     sopralluogo + crea/aggiorna l'evento gcal
//   POST {mode:'delete', session_id, id}
//                                   → elimina il sopralluogo + cancella l'evento gcal
//
// Dopo ogni modifica riallinea clienti.sopralluogo_at/gcal_event_id alla visita
// "prossima" (così Sole su WhatsApp continua a funzionare in Fase 1). Protetto
// via .htaccess (Basic Auth) — è elencato nel <FilesMatch>.
// -----------------------------------------------------------

require_once __DIR__ . '/ardy-db.php';
require_once __DIR__ . '/ardy-gcal.php';

header('Access-Control-Allow-Origin: https://ardyagent.ardy-lab.it');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

function sopr_clean_sid(string $s): string {
    return preg_replace('/[^a-zA-Z0-9_\-]/', '', $s);
}

// Normalizza "YYYY-MM-DDTHH:MM" / "YYYY-MM-DD HH:MM[:SS]" → "YYYY-MM-DD HH:MM:SS"
// Ritorna null se non è una data/ora valida.
function sopr_norm_data($raw): ?string {
    $s = str_replace('T', ' ', trim((string) $raw));
    if ($s === '') return null;
    if (strlen($s) === 16) $s .= ':00';           // manca il campo secondi
    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $s);
    return $dt ? $dt->format('Y-m-d H:i:s') : null;
}

// Riallinea i vecchi campi clienti.sopralluogo_at/gcal_event_id alla visita
// "prossima" (la prima futura; se non ce ne sono, la più recente passata; se
// nessuna riga, li azzera). Serve a tenere in piedi Sole su WhatsApp in Fase 1.
function sopr_mirror(PDO $db, string $sid): void {
    $st = $db->prepare(
        "SELECT data_ora, gcal_event_id FROM sopralluoghi
          WHERE session_id = :sid
          ORDER BY (data_ora >= NOW()) DESC,
                   CASE WHEN data_ora >= NOW() THEN data_ora END ASC,
                   data_ora DESC
          LIMIT 1"
    );
    $st->execute([':sid' => $sid]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $db->prepare("UPDATE clienti SET sopralluogo_at = :d, gcal_event_id = :e, updated_at = NOW() WHERE session_id = :sid")
           ->execute([':d' => $row['data_ora'], ':e' => $row['gcal_event_id'], ':sid' => $sid]);
    } else {
        $db->prepare("UPDATE clienti SET sopralluogo_at = NULL, gcal_event_id = NULL, updated_at = NOW() WHERE session_id = :sid")
           ->execute([':sid' => $sid]);
    }
}

// Titolo evento calendario coerente con quelli creati da Sole.
function sopr_summary(array $cli, string $etichetta): string {
    $nm = trim(($cli['nome'] ?? '') . ' ' . ($cli['cognome'] ?? ''));
    $et = ($etichetta !== '' && strcasecmp($etichetta, 'Sopralluogo') !== 0) ? ' (' . $etichetta . ')' : '';
    return 'Sopralluogo Ardy Lab' . $et
         . (!empty($cli['zona']) ? ' — ' . $cli['zona'] : '')
         . ($nm !== '' ? ' / ' . $nm : '');
}

try {
    $db = ardyDB();

    // ── GET: lista ──────────────────────────────────────────
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $sid = sopr_clean_sid($_GET['session_id'] ?? '');
        if ($sid === '') { echo json_encode(['success' => false, 'error' => 'session_id mancante']); exit(); }

        // Riconciliazione "pigra": se il cliente ha un sopralluogo nei vecchi campi
        // (es. appena fissato da Sole su WhatsApp) non ancora presente come riga, lo
        // inserisce — così le prenotazioni WhatsApp compaiono nella lista.
        $c = $db->prepare("SELECT sopralluogo_at, gcal_event_id FROM clienti WHERE session_id = :sid LIMIT 1");
        $c->execute([':sid' => $sid]);
        $cli = $c->fetch(PDO::FETCH_ASSOC);
        if ($cli && !empty($cli['sopralluogo_at'])) {
            if (!empty($cli['gcal_event_id'])) {
                $chk = $db->prepare("SELECT id FROM sopralluoghi WHERE session_id = :sid AND gcal_event_id = :e LIMIT 1");
                $chk->execute([':sid' => $sid, ':e' => $cli['gcal_event_id']]);
            } else {
                $chk = $db->prepare("SELECT id FROM sopralluoghi WHERE session_id = :sid AND data_ora = :d LIMIT 1");
                $chk->execute([':sid' => $sid, ':d' => $cli['sopralluogo_at']]);
            }
            if (!$chk->fetch()) {
                $db->prepare("INSERT INTO sopralluoghi (session_id, data_ora, etichetta, gcal_event_id, created_at, updated_at)
                              VALUES (:sid, :d, 'Sopralluogo', :e, NOW(), NOW())")
                   ->execute([':sid' => $sid, ':d' => $cli['sopralluogo_at'], ':e' => $cli['gcal_event_id'] ?: null]);
            }
        }

        $st = $db->prepare("SELECT id, data_ora, etichetta, note, gcal_event_id
                              FROM sopralluoghi WHERE session_id = :sid ORDER BY data_ora ASC");
        $st->execute([':sid' => $sid]);
        echo json_encode(['success' => true, 'items' => $st->fetchAll(PDO::FETCH_ASSOC)]);
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
        $st = $db->prepare("SELECT gcal_event_id FROM sopralluoghi WHERE id = :id AND session_id = :sid LIMIT 1");
        $st->execute([':id' => $id, ':sid' => $sid]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) { echo json_encode(['success' => false, 'error' => 'Sopralluogo non trovato']); exit(); }
        if (!empty($row['gcal_event_id'])) {
            try { gcal_delete_event($row['gcal_event_id']); }
            catch (Throwable $e) { error_log('ARDY SOPR delete gcal: ' . $e->getMessage()); }
        }
        $db->prepare("DELETE FROM sopralluoghi WHERE id = :id AND session_id = :sid")
           ->execute([':id' => $id, ':sid' => $sid]);
        sopr_mirror($db, $sid);
        echo json_encode(['success' => true]);
        exit();
    }

    if ($mode === 'salva') {
        $id        = (int) ($in['id'] ?? 0);
        $dataOra   = sopr_norm_data($in['data_ora'] ?? '');
        $etichetta = trim((string) ($in['etichetta'] ?? '')) ?: 'Sopralluogo';
        $note      = trim((string) ($in['note'] ?? ''));
        if ($dataOra === null) { echo json_encode(['success' => false, 'error' => 'Data/ora non valida']); exit(); }

        $dt      = new DateTime($dataOra);
        $dateStr = $dt->format('Y-m-d');
        $timeStr = $dt->format('H:i');

        $c = $db->prepare("SELECT nome, cognome, zona, telefono, email FROM clienti WHERE session_id = :sid LIMIT 1");
        $c->execute([':sid' => $sid]);
        $cli = $c->fetch(PDO::FETCH_ASSOC) ?: [];
        $summary = sopr_summary($cli, $etichetta);

        if ($id > 0) {
            // SPOSTA / aggiorna un sopralluogo esistente
            $st = $db->prepare("SELECT gcal_event_id FROM sopralluoghi WHERE id = :id AND session_id = :sid LIMIT 1");
            $st->execute([':id' => $id, ':sid' => $sid]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) { echo json_encode(['success' => false, 'error' => 'Sopralluogo non trovato']); exit(); }
            $eid = $row['gcal_event_id'] ?? null;
            try {
                if (!empty($eid)) {
                    gcal_update_event($eid, $dateStr, $timeStr, 2);
                } else {
                    $ev = gcal_create_event($dateStr, $timeStr, $summary, (string) ($cli['telefono'] ?? ''), (string) ($cli['email'] ?? ''), '', '');
                    if (is_array($ev) && !empty($ev['id'])) $eid = $ev['id'];
                }
            } catch (Throwable $e) { error_log('ARDY SOPR salva gcal(upd): ' . $e->getMessage()); }
            $db->prepare("UPDATE sopralluoghi SET data_ora = :d, etichetta = :et, note = :nt, gcal_event_id = :e, updated_at = NOW()
                           WHERE id = :id AND session_id = :sid")
               ->execute([':d' => $dataOra, ':et' => $etichetta, ':nt' => ($note !== '' ? $note : null), ':e' => $eid, ':id' => $id, ':sid' => $sid]);
        } else {
            // NUOVO sopralluogo
            $eid = null;
            try {
                $ev = gcal_create_event($dateStr, $timeStr, $summary, (string) ($cli['telefono'] ?? ''), (string) ($cli['email'] ?? ''), '', '');
                if (is_array($ev) && !empty($ev['id'])) $eid = $ev['id'];
            } catch (Throwable $e) { error_log('ARDY SOPR salva gcal(new): ' . $e->getMessage()); }
            $db->prepare("INSERT INTO sopralluoghi (session_id, data_ora, etichetta, note, gcal_event_id, created_at, updated_at)
                          VALUES (:sid, :d, :et, :nt, :e, NOW(), NOW())")
               ->execute([':sid' => $sid, ':d' => $dataOra, ':et' => $etichetta, ':nt' => ($note !== '' ? $note : null), ':e' => $eid]);
            $id = (int) $db->lastInsertId();
        }

        sopr_mirror($db, $sid);
        echo json_encode(['success' => true, 'id' => $id]);
        exit();
    }

    echo json_encode(['success' => false, 'error' => 'mode non valido']);

} catch (PDOException $e) {
    error_log('ARDY SOPRALLUOGHI API ERROR: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Errore interno']);
}
