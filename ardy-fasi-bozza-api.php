<?php
// -----------------------------------------------------------
// ARDY LAB — Fasi in bozza generate dai template di libreria
// Il box "Note" del sopralluogo permette di scegliere uno o più
// template dalla libreria fasi: questo endpoint le trasforma in
// righe "bozza" nella tabella fasi (non pubblicate), che Michela
// modifica e pubblica una per una dal pannello Lavorazione.
//
//   GET  ?session_id=...         → lista bozze del cliente (in ordine)
//   POST {mode:'genera', ...}        → crea N bozze dai template di libreria scelti
//   POST {mode:'genera_custom', ...} → crea N bozze da voci libere (es. lette da un
//                                       preventivo PDF esterno con ardy-estrai-preventivo-pdf.php)
//   POST {mode:'delete', id}     → elimina una bozza (solo se stato='bozza')
// -----------------------------------------------------------

require_once __DIR__ . '/ardy-db.php';

header('Access-Control-Allow-Origin: https://ardyagent.ardy-lab.it');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

// Inserisce N bozze (nome + testo_breve) per un cliente, in coda all'ordine
// esistente. Condiviso dai modi 'genera' (da libreria) e 'genera_custom'
// (da voci libere, es. estratte da un PDF).
/** Accetta sia un numero (già float, dall'estrazione AI) sia una stringa "1.450,00" / "350,00". */
function ardyParseImportoFase($v): ?float {
    if (is_numeric($v)) return (float) $v;
    if (!is_string($v) || trim($v) === '') return null;
    $s = preg_replace('/[^\d,.\-]/', '', trim($v));
    if ($s === '') return null;
    if (strpos($s, ',') !== false && strpos($s, '.') !== false) {
        if (strrpos($s, ',') > strrpos($s, '.')) { $s = str_replace('.', '', $s); $s = str_replace(',', '.', $s); }
        else { $s = str_replace(',', '', $s); }
    } elseif (strpos($s, ',') !== false) {
        $s = str_replace(',', '.', $s);
    }
    return is_numeric($s) ? (float) $s : null;
}

function ardyInserisciBozzeFasi(PDO $db, string $sessionId, array $items): array {
    $stOrd = $db->prepare("SELECT COALESCE(MAX(ordine),0) FROM fasi WHERE session_id = ?");
    $stOrd->execute([$sessionId]);
    $ordine = (int) $stOrd->fetchColumn();

    $ins = $db->prepare(
        "INSERT INTO fasi (session_id, fase_nome, fase_tipo, testo_breve, testo_generato, foto_urls, video_urls, stato, ordine, prezzo)
         VALUES (:sid, :nome, 'fase', :breve, '', '[]', '[]', 'bozza', :ordine, :prezzo)"
    );

    $bozze = [];
    foreach ($items as $it) {
        $nome   = trim((string) ($it['nome']  ?? ''));
        $breve  = trim((string) ($it['breve'] ?? ''));
        $prezzo = ardyParseImportoFase($it['prezzo'] ?? null);
        if ($nome === '') continue;
        $ordine++;
        $ins->execute([':sid' => $sessionId, ':nome' => $nome, ':breve' => $breve, ':ordine' => $ordine, ':prezzo' => $prezzo]);
        $bozze[] = ['id' => (int) $db->lastInsertId(), 'fase_nome' => $nome, 'testo_breve' => $breve, 'ordine' => $ordine, 'prezzo' => $prezzo];
    }
    return $bozze;
}

try {
    $db = ardyDB();
    ardyEnsureFasiStatoOrdine($db);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $sessionId = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_GET['session_id'] ?? '');
        if ($sessionId === '') { echo json_encode([]); exit(); }
        $stmt = $db->prepare(
            "SELECT id, fase_nome, testo_breve, ordine, prezzo, created_at FROM fasi
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

        // Mantiene l'ordine di selezione scelto dall'operatore, ignorando i template non trovati.
        $items = [];
        foreach ($templateIds as $tid) {
            if (!isset($byId[$tid])) continue;
            $items[] = ['nome' => $byId[$tid]['nome'], 'breve' => $byId[$tid]['descr'] ?? ''];
        }

        echo json_encode(['success' => true, 'bozze' => ardyInserisciBozzeFasi($db, $sessionId, $items)]);
        exit();
    }

    if ($mode === 'genera_custom') {
        // Bozze da voci libere (es. lette da un preventivo PDF esterno con
        // ardy-estrai-preventivo-pdf.php): niente libreria, il testo arriva già pronto.
        $sessionId = preg_replace('/[^a-zA-Z0-9_\-]/', '', $input['session_id'] ?? '');
        $fasiInput = is_array($input['fasi'] ?? null) ? array_values($input['fasi']) : [];
        if ($sessionId === '' || !$fasiInput) {
            echo json_encode(['success' => false, 'error' => 'Dati mancanti']);
            exit();
        }

        $items = [];
        foreach ($fasiInput as $f) {
            if (!is_array($f)) continue;
            $items[] = [
                'nome'   => $f['nome'] ?? '',
                'breve'  => $f['descrizione'] ?? ($f['testo_breve'] ?? ''),
                'prezzo' => $f['prezzo'] ?? null,
            ];
        }

        echo json_encode(['success' => true, 'bozze' => ardyInserisciBozzeFasi($db, $sessionId, $items)]);
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
