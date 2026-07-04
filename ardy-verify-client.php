<?php
// -----------------------------------------------------------
// ARDY LAB — Verifica Cliente per Widget Lavorazione
// -----------------------------------------------------------

require_once __DIR__ . '/ardy-config.php';
require_once __DIR__ . '/ardy-db.php';
require_once __DIR__ . '/ardy-net.php';   // ardyClientIp() (IP reale dietro Cloudflare)

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

// -----------------------------------------------------------
// Rate limiting anti-forza-bruta.
// - IP REALE del client (ardyClientIp): dietro Cloudflare REMOTE_ADDR è l'IP
//   dell'edge, che accomunerebbe tutti gli utenti nello stesso bucket (sia
//   falsi positivi sui clienti veri, sia mascheramento di un attacco).
// - Storage persistente in ARDY_RATE_LIMIT_DIR quando disponibile (sopravvive
//   alla pulizia di /tmp); fallback su sys_get_temp_dir().
// - Conta solo i tentativi effettivamente FALLITI: un cliente vero che azzecca
//   subito non consuma budget.
// Fail-open sulla SCRITTURA (non blocca i clienti veri se il disco è pieno),
// ma il match resta stretto (ultime 9 cifre) così il brute-force è impraticabile
// anche ruotando gli IP.
// -----------------------------------------------------------
$ipAddr = ardyClientIp();
$rlDir  = (defined('ARDY_RATE_LIMIT_DIR') && ARDY_RATE_LIMIT_DIR)
    ? rtrim(ARDY_RATE_LIMIT_DIR, '/') . '/'
    : rtrim(sys_get_temp_dir(), '/') . '/';
if (!is_dir($rlDir)) @mkdir($rlDir, 0755, true);
$rlFile = $rlDir . 'ardy_verify_' . md5($ipAddr) . '.json';
$now    = time();
$window = 600;  // 10 minuti
$maxTry = 15;   // ampio per i clienti veri (verificano in 1-2 tentativi)
$tries  = [];
if (is_readable($rlFile)) {
    $tries = json_decode(@file_get_contents($rlFile), true) ?: [];
    $tries = array_values(array_filter($tries, fn($t) => $t > $now - $window));
}
if (count($tries) >= $maxTry) {
    echo json_encode(['verified' => false, 'error' => 'Troppi tentativi. Riprova tra qualche minuto.']);
    exit();
}

/** Registra un tentativo fallito nel bucket per-IP (solo i fallimenti pesano). */
$registraFallimento = function () use (&$tries, $now, $rlFile) {
    $tries[] = $now;
    @file_put_contents($rlFile, json_encode($tries));
};

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
            $registraFallimento();
            echo json_encode(['verified' => false, 'error' => 'Codice non valido per questa lavorazione']);
        }
        exit();
    }

    // ── Ramo TELEFONO ──
    $stmt = $db->prepare("SELECT nome, cognome, telefono FROM clienti WHERE wp_post_id = :wid");
    $stmt->execute([':wid' => $wpPostId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        $registraFallimento();
        echo json_encode(['verified' => false, 'error' => 'Lavorazione non trovata']);
        exit();
    }

    // Confronto sulle ultime 9 cifre (canonico in tutto il sistema, vedi
    // ardyTelefonoLast9): tollera prefisso +39/spazi ma rende il brute-force
    // ~1000x più costoso rispetto alle vecchie 7 cifre.
    $match = ardyTelefonoLast9($telefono) !== ''
        && ardyTelefonoLast9($telefono) === ardyTelefonoLast9((string) $row['telefono']);

    if ($match) {
        $nome = trim(($row['nome'] ?? '') . ' ' . ($row['cognome'] ?? ''));
        echo json_encode(['verified' => true, 'nome' => $nome]);
    } else {
        $registraFallimento();
        echo json_encode(['verified' => false, 'error' => 'Numero non corrispondente']);
    }

} catch (PDOException $e) {
    error_log('ARDY VERIFY ERROR: ' . $e->getMessage());
    echo json_encode(['verified' => false, 'error' => 'Errore di sistema']);
}
