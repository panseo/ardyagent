<?php
// -----------------------------------------------------------
// ARDY LAB — Conversazione di un cliente (per la dashboard)
// -----------------------------------------------------------
// Restituisce lo storico unificato dei messaggi di UN cliente:
//   - WhatsApp  (tabella wa_messaggi, per numero)
//   - Chat sito (tabella web_messaggi, per session_id)
// in ordine cronologico, così la scheda cliente può mostrare la
// conversazione (chi ha scritto, cosa, quando).
//
//   GET ?session_id=wa-XXXX[&phone=+39...]
//
// Dietro Basic Auth via .htaccess (come gli altri endpoint chiamati
// in fetch dalla dashboard): NON usa ardyRequireAuth.
// -----------------------------------------------------------

date_default_timezone_set('Europe/Rome');

header('Access-Control-Allow-Origin: https://ardyagent.ardy-lab.it');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

require_once __DIR__ . '/ardy-db.php';

$sessionId = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_GET['session_id'] ?? '');
$phoneRaw  = (string)($_GET['phone'] ?? '');
$last9     = substr(preg_replace('/\D+/', '', $phoneRaw), -9);

if ($sessionId === '' && $last9 === '') {
    echo json_encode(['success' => true, 'messaggi' => []]);
    exit();
}

$messaggi = [];

try {
    $db = ardyDB();

    // Se non è arrivato il telefono ma ho il session_id, provo a ricavarlo dal CRM
    // (così la scheda può passare solo il session_id).
    if ($last9 === '' && $sessionId !== '') {
        try {
            $st = $db->prepare("SELECT telefono FROM clienti WHERE session_id = :s LIMIT 1");
            $st->execute([':s' => $sessionId]);
            $tel = (string)($st->fetchColumn() ?: '');
            $last9 = substr(preg_replace('/\D+/', '', $tel), -9);
        } catch (PDOException $e) { /* salta */ }
    }

    // WhatsApp: per numero (la colonna phone è già solo cifre, normalizzata).
    if ($last9 !== '') {
        try {
            $st = $db->prepare(
                "SELECT role, content, created_at FROM wa_messaggi
                  WHERE RIGHT(phone, 9) = :p ORDER BY id ASC LIMIT 200"
            );
            $st->execute([':p' => $last9]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $messaggi[] = [
                    'via'        => 'whatsapp',
                    'role'       => $r['role'] === 'assistant' ? 'assistant' : 'user',
                    'content'    => (string)$r['content'],
                    'created_at' => (string)$r['created_at'],
                ];
            }
        } catch (PDOException $e) { /* tabella assente: salta */ }
    }

    // Chat sito: per session_id.
    if ($sessionId !== '') {
        try {
            $st = $db->prepare(
                "SELECT role, content, created_at FROM web_messaggi
                  WHERE session_id = :s ORDER BY id ASC LIMIT 200"
            );
            $st->execute([':s' => $sessionId]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $messaggi[] = [
                    'via'        => 'web',
                    'role'       => $r['role'] === 'assistant' ? 'assistant' : 'user',
                    'content'    => (string)$r['content'],
                    'created_at' => (string)$r['created_at'],
                ];
            }
        } catch (PDOException $e) { /* tabella assente: salta */ }
    }

    // Aprire la conversazione in dashboard = "ho visto chi ha scritto": salva il
    // marker così il badge "ha risposto" in lista si spegne (vedi ardy-crm-api.php).
    if ($sessionId !== '') {
        try {
            $st = $db->prepare(
                "UPDATE clienti SET conversazione_letta_at = NOW() WHERE session_id = :s"
            );
            $st->execute([':s' => $sessionId]);
        } catch (PDOException $e) { /* colonna non ancora creata o cliente assente: ignora */ }
    }

    // Ordine cronologico complessivo (i due canali intrecciati per data).
    usort($messaggi, function ($a, $b) {
        $ta = strtotime($a['created_at']); $tb = strtotime($b['created_at']);
        return $ta <=> $tb;
    });

} catch (PDOException $e) {
    error_log('ARDY CONVERSAZIONI ERROR: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Errore interno']);
    exit();
}

echo json_encode(['success' => true, 'messaggi' => $messaggi]);
