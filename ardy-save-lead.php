<?php
// -----------------------------------------------------------
// ARDY LAB — SAVE Lead su DB (INSERT o UPDATE)
// Chiamato da ardy-proxy.php quando Claude usa il tool salva_lead_crm
// -----------------------------------------------------------

require_once __DIR__ . '/ardy-config.php';
require_once __DIR__ . '/ardy-db.php';
require_once __DIR__ . '/ardy-net.php';

header('Access-Control-Allow-Origin: https://ardyagent.ardy-lab.it');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Ardy-Internal');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

// IP reale del client (a prova di spoofing — vedi ardy-net.php)
$ip = ardyClientIp();

// -----------------------------------------------------------
// RATE LIMIT PER IP — endpoint pubblico: evita che si riempia la
// tabella `clienti` di lead spazzatura con POST diretti.
// Le chiamate interne (proxy chatbot) portano un secret e sono esenti,
// così il flusso legittimo server-side non viene mai strozzato.
// -----------------------------------------------------------
$internalSecret = defined('ARDY_INTERNAL_SECRET') ? ARDY_INTERNAL_SECRET : '';
$providedSecret = $_SERVER['HTTP_X_ARDY_INTERNAL'] ?? '';
$isTrusted = $internalSecret !== '' && hash_equals($internalSecret, $providedSecret);

if (!$isTrusted && defined('ARDY_RATE_LIMIT_DIR')) {
    $dir = ARDY_RATE_LIMIT_DIR;
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $now     = time();
    $cleanIp = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $ip);
    $rlFile  = $dir . 'savelead_' . md5($cleanIp) . '.json';
    $hits    = file_exists($rlFile) ? (json_decode(file_get_contents($rlFile), true) ?: []) : [];
    // tieni solo gli ultimi 24h
    $hits = array_values(array_filter($hits, fn($t) => ($now - $t) < 86400));
    $lastHour = count(array_filter($hits, fn($t) => ($now - $t) < 3600));
    if ($lastHour >= 15 || count($hits) >= 50) {
        http_response_code(429);
        echo json_encode(['success' => false, 'error' => 'Troppe richieste. Riprova più tardi.']);
        exit();
    }
    $hits[] = $now;
    file_put_contents($rlFile, json_encode($hits));
}

$input     = json_decode(file_get_contents('php://input'), true);
$sessionId = $input['session_id'] ?? '';

if (empty($sessionId)) {
    echo json_encode(['success' => false, 'error' => 'session_id mancante']);
    exit();
}

try {
    $db = ardyDB();

    ardyEnsureTelefonoLast9($db);

    // Campi accettati in INSERT/UPDATE
    $fields = [
        'nome', 'cognome', 'telefono', 'telefono_last9', 'email',
        'servizio', 'mobile', 'zona', 'budget',
        'indirizzo', 'stato', 'note',
        'data_followup', 'wp_post_id', 'wp_post_link'
    ];

    // Costruisci array dei valori presenti nell'input
    $values = ['session_id' => $sessionId, 'ip_address' => $ip];
    foreach ($fields as $f) {
        $values[$f] = isset($input[$f]) && $input[$f] !== ''
            ? $input[$f]
            : null;
    }
    // telefono_last9 non arriva dall'input: si calcola dal telefono.
    $values['telefono_last9'] = $values['telefono'] !== null ? ardyTelefonoLast9((string)$values['telefono']) : null;
    // Default stato
    if (empty($values['stato'])) {
        $values['stato'] = 'LEAD';
    }
    // Scarta email palesemente non valide (evita dati sporchi nel CRM)
    if (!empty($values['email']) && !filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
        $values['email'] = null;
    }

    // INSERT ... ON DUPLICATE KEY UPDATE
    // La UNIQUE KEY su session_id garantisce l'upsert
    $cols    = implode(', ', array_map(fn($k) => "`$k`", array_keys($values)));
    $placeholders = implode(', ', array_map(fn($k) => ":$k", array_keys($values)));

    // Per l'UPDATE parte: aggiorna solo i campi non NULL
    $updateParts = [];
    foreach ($fields as $f) {
        // Aggiorna solo se il valore è presente (non null) — non sovrascrivere
        // dati esistenti con null se il campo non è stato inviato
        $updateParts[] = "`$f` = IF(:upd_$f IS NOT NULL, :upd_$f, `$f`)";
        $values["upd_$f"] = $values[$f]; // duplica per il binding ON DUPLICATE
    }
    $updateParts[] = "`updated_at` = NOW()";

    $sql = "INSERT INTO `clienti` ($cols) VALUES ($placeholders)
            ON DUPLICATE KEY UPDATE " . implode(', ', $updateParts);

    $db->prepare($sql)->execute($values);

    echo json_encode(['success' => true]);

} catch (PDOException $e) {
    error_log('ARDY SAVE LEAD ERROR: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Errore interno']);
}
