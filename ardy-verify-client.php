<?php
// -----------------------------------------------------------
// ARDY LAB — Verifica Cliente per Widget Lavorazione
// -----------------------------------------------------------

require_once __DIR__ . '/ardy-config.php';
require_once __DIR__ . '/ardy-db.php';

header('Access-Control-Allow-Origin: https://ardy-lab.it');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);
$telefono = trim($input['telefono'] ?? '');
$codiceIn = strtoupper(trim($input['codice'] ?? ''));
$wpPostId = trim($input['wp_post_id'] ?? '');

if (($telefono === '' && $codiceIn === '') || empty($wpPostId)) {
    echo json_encode(['verified' => false, 'error' => 'Dati mancanti']);
    exit();
}

// Rate limiting leggero anti-forza-bruta sul telefono.
// Fail-open: se la scrittura del file fallisce, NON blocca i clienti veri.
$ipAddr = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$rlFile = sys_get_temp_dir() . '/ardy_verify_' . md5($ipAddr) . '.json';
$now    = time();
$window = 600;  // 10 minuti
$maxTry = 20;   // ampio: un cliente vero verifica in 1-2 tentativi
$tries  = [];
if (is_readable($rlFile)) {
    $tries = json_decode(@file_get_contents($rlFile), true) ?: [];
    $tries = array_values(array_filter($tries, fn($t) => $t > $now - $window));
}
if (count($tries) >= $maxTry) {
    echo json_encode(['verified' => false, 'error' => 'Troppi tentativi. Riprova tra qualche minuto.']);
    exit();
}
$tries[] = $now;
@file_put_contents($rlFile, json_encode($tries));

// Normalizza telefono: rimuovi spazi, +, trattini
$telNorm = preg_replace('/[\s\+\-\(\)]/', '', $telefono);
// Se inizia con 39, toglilo per il confronto
$telShort = preg_replace('/^39/', '', $telNorm);

try {
    $db = ardyDB();

    // ── Ramo CODICE: il codice personale deve corrispondere a QUESTA lavorazione ──
    if ($codiceIn !== '') {
        $cod = preg_replace('/[^A-Z0-9]/', '', $codiceIn);
        if (preg_match('/^ARD([A-Z0-9]{4})([A-Z0-9]{4})$/', $cod, $m)) {
            $cod = 'ARD-' . $m[1] . '-' . $m[2];
        }
        try {
            $stc = $db->prepare("SELECT nome, cognome FROM clienti WHERE codice_accesso = :c AND wp_post_id = :wid LIMIT 1");
            $stc->execute([':c' => $cod, ':wid' => $wpPostId]);
            $rc = $stc->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            // colonna codice_accesso non ancora presente su questa installazione
            $rc = false;
        }
        if ($rc) {
            echo json_encode(['verified' => true, 'nome' => trim(($rc['nome'] ?? '') . ' ' . ($rc['cognome'] ?? ''))]);
        } else {
            echo json_encode(['verified' => false, 'error' => 'Codice non valido per questa lavorazione']);
        }
        exit();
    }

    // ── Ramo TELEFONO ──
    $stmt = $db->prepare("SELECT nome, cognome, telefono FROM clienti WHERE wp_post_id = :wid");
    $stmt->execute([':wid' => $wpPostId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo json_encode(['verified' => false, 'error' => 'Lavorazione non trovata']);
        exit();
    }

    // Normalizza telefono dal DB
    $dbTel = preg_replace('/[\s\+\-\(\)]/', '', $row['telefono']);
    $dbTelShort = preg_replace('/^39/', '', $dbTel);

    // Confronto: ultimi 7 cifre (per evitare problemi di prefisso)
    $match = (substr($telShort, -7) === substr($dbTelShort, -7));

    if ($match) {
        $nome = trim(($row['nome'] ?? '') . ' ' . ($row['cognome'] ?? ''));
        echo json_encode(['verified' => true, 'nome' => $nome]);
    } else {
        echo json_encode(['verified' => false, 'error' => 'Numero non corrispondente']);
    }

} catch (PDOException $e) {
    error_log('ARDY VERIFY ERROR: ' . $e->getMessage());
    echo json_encode(['verified' => false, 'error' => 'Errore di sistema']);
}
