<?php
// -----------------------------------------------------------
// ARDY LAB — Fasi in bozza generate dai template di libreria
// Il box "Note" del sopralluogo permette di scegliere uno o più
// template dalla libreria fasi: questo endpoint le trasforma in
// righe "bozza" nella tabella fasi (non pubblicate), che Michela
// modifica e pubblica una per una dal pannello Lavorazione.
//
//   GET  ?session_id=...         → lista bozze del cliente (in ordine)
//   POST {mode:'genera', ...}    → crea N bozze dai template scelti
//   POST {mode:'delete', id}     → elimina una bozza (solo se stato='bozza')
// -----------------------------------------------------------

require_once __DIR__ . '/ardy-db.php';

header('Access-Control-Allow-Origin: https://ardyagent.ardy-lab.it');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

try {
    $db = ardyDB();
    ardyEnsureFasiStatoOrdine($db);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $sessionId = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_GET['session_id'] ?? '');
        if ($sessionId === '') { echo json_encode([]); exit(); }
        $stmt = $db->prepare(
            "SELECT id, fase_nome, testo_breve, ordine, created_at FROM fasi
             WHERE session_id = ? AND stato = 'bozza' ORDER BY ordine ASC, created_at ASC"
        );
        $stmt->execute([$sessionId]);
        echo json_encode($stmt->fetchAll());
        exit();
    }

    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $mode  = $input['mode'] ?? '';

    if ($mode === 'genera') {
        $sessionId   = preg_replace('/[^a-zA-Z0-9_\-]/', '', $input['session_id'] ?? '');
        $templateIds = is_array($input['template_ids'] ?? null) ? array_values($input['template_ids']) : [];
        if ($sessionId === '' || !$templateIds) {
            echo json_encode(['success' => false, 'error' => 'Dati mancanti']);
            exit();
        }

        $placeholders = implode(',', array_fill(0, count($templateIds), '?'));
        $stmt = $db->prepare("SELECT id, nome, descr FROM libreria_fasi WHERE id IN ($placeholders)");
        $stmt->execute($templateIds);
        $byId = [];
        foreach ($stmt->fetchAll() as $r) { $byId[$r['id']] = $r; }

        $stOrd = $db->prepare("SELECT COALESCE(MAX(ordine),0) FROM fasi WHERE session_id = ?");
        $stOrd->execute([$sessionId]);
        $ordine = (int) $stOrd->fetchColumn();

        $ins = $db->prepare(
            "INSERT INTO fasi (session_id, fase_nome, fase_tipo, testo_breve, testo_generato, foto_urls, video_urls, stato, ordine)
             VALUES (:sid, :nome, 'fase', :breve, '', '[]', '[]', 'bozza', :ordine)"
        );

        $bozze = [];
        foreach ($templateIds as $tid) {
            if (!isset($byId[$tid])) continue;
            $ordine++;
            $ins->execute([
                ':sid'    => $sessionId,
                ':nome'   => $byId[$tid]['nome'],
                ':breve'  => $byId[$tid]['descr'] ?? '',
                ':ordine' => $ordine,
            ]);
            $bozze[] = [
                'id'          => (int) $db->lastInsertId(),
                'fase_nome'   => $byId[$tid]['nome'],
                'testo_breve' => $byId[$tid]['descr'] ?? '',
                'ordine'      => $ordine,
            ];
        }

        echo json_encode(['success' => true, 'bozze' => $bozze]);
        exit();
    }

    if ($mode === 'delete') {
        $id = (int) ($input['id'] ?? 0);
        if (!$id) {
            echo json_encode(['success' => false, 'error' => 'id mancante']);
            exit();
        }
        $db->prepare("DELETE FROM fasi WHERE id = ? AND stato = 'bozza'")->execute([$id]);
        echo json_encode(['success' => true]);
        exit();
    }

    echo json_encode(['success' => false, 'error' => 'mode non valido']);

} catch (PDOException $e) {
    error_log('ARDY FASI BOZZA API ERROR: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Errore interno']);
}
