<?php
// -----------------------------------------------------------
// ARDY LAB — Bozze post social ("salva per dopo")
// -----------------------------------------------------------
// Persiste sul server i post social messi in attesa dalla dashboard, così
// sono visibili da OGNI dispositivo (telefono + desktop) e da entrambi gli
// utenti — prima vivevano solo nel localStorage del browser (si perdevano
// cambiando dispositivo o pulendo i dati).
//
//   GET                              → { success, bozze: [ {…}, … ] }
//   POST { azione:'salva', bozza }   → upsert della bozza (per id)
//   POST { azione:'elimina', id }    → cancella la bozza
//
// Dietro Basic Auth via .htaccess (come gli altri endpoint chiamati in fetch
// dalla dashboard): NON usa ardyRequireAuth.
// -----------------------------------------------------------

date_default_timezone_set('Europe/Rome');

header('Access-Control-Allow-Origin: https://ardyagent.ardy-lab.it');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

require_once __DIR__ . '/ardy-db.php';

try {
    $db = ardyDB();

    // Tabella social_bozze creata da ardy-migrate.php.
    // payload = oggetto bozza completo (testo, immagini, piattaforme, fase) in JSON.

    // ── LETTURA ────────────────────────────────────────────
    // Filtra per session_id: i post in attesa appartengono al cliente da cui
    // sono stati creati (la sezione "Post social in attesa" sta nella sua scheda).
    // Senza session_id non si restituisce nulla, così non compaiono su TUTTI i
    // clienti (era il bug: la lista non era filtrata).
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $sid = isset($_GET['session_id'])
            ? substr(preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $_GET['session_id']), 0, 120)
            : '';
        if ($sid === '') {
            echo json_encode(['success' => true, 'bozze' => []]);
            exit();
        }
        $stmt = $db->prepare("SELECT id, payload FROM social_bozze WHERE session_id = :sid ORDER BY created_at ASC");
        $stmt->execute([':sid' => $sid]);
        $rows = $stmt->fetchAll();
        $bozze = [];
        foreach ($rows as $r) {
            $obj = json_decode((string) $r['payload'], true);
            if (!is_array($obj)) { continue; }
            $obj['id'] = $r['id']; // l'id autorevole è quello in colonna
            $bozze[]   = $obj;
        }
        echo json_encode(['success' => true, 'bozze' => $bozze]);
        exit();
    }

    // ── SCRITTURA ──────────────────────────────────────────
    $input  = json_decode(file_get_contents('php://input'), true) ?: [];
    $azione = $input['azione'] ?? '';

    if ($azione === 'salva') {
        $bozza = $input['bozza'] ?? null;
        if (!is_array($bozza) || empty($bozza['id'])) {
            echo json_encode(['success' => false, 'error' => 'Bozza non valida']);
            exit();
        }
        // id sanificato: solo i caratteri che la dashboard genera (es. sp_1718…)
        $id  = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $bozza['id']);
        $sid = isset($bozza['session_id'])
            ? substr(preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $bozza['session_id']), 0, 120)
            : null;
        if ($id === '') {
            echo json_encode(['success' => false, 'error' => 'id non valido']);
            exit();
        }
        $st = $db->prepare(
            "INSERT INTO social_bozze (id, session_id, payload)
                  VALUES (:id, :sid, :p)
             ON DUPLICATE KEY UPDATE session_id = VALUES(session_id), payload = VALUES(payload)"
        );
        $st->execute([
            ':id'  => $id,
            ':sid' => $sid,
            ':p'   => json_encode($bozza, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        echo json_encode(['success' => true, 'id' => $id]);
        exit();
    }

    if ($azione === 'elimina') {
        $id = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) ($input['id'] ?? ''));
        if ($id === '') {
            echo json_encode(['success' => false, 'error' => 'id non valido']);
            exit();
        }
        $st = $db->prepare("DELETE FROM social_bozze WHERE id = :id");
        $st->execute([':id' => $id]);
        echo json_encode(['success' => true]);
        exit();
    }

    echo json_encode(['success' => false, 'error' => 'Azione non riconosciuta']);

} catch (PDOException $e) {
    error_log('ARDY SOCIAL BOZZE ERROR: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Errore interno']);
}
