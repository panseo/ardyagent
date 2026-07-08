<?php
// -----------------------------------------------------------
// ARDY LAB — Scheda-Sole pubblica di un oggetto (read-only)
// Proiezione PUBBLICA di un progetto (ardy design) che alimenta la chat di Sole sul
// negozio object.ardy-lab.it. Espone SOLO i campi whitelisted qui sotto: costi, BOM,
// margine, file STL/CAD e iterazioni R&D NON transitano MAI da questo endpoint.
// Serve solo progetti con scheda_sole_pubblica = 1 (interruttore) e non eliminati.
//
//   GET ?slug=poltrona-monti-01 → { success, oggetto:{...campi pubblici...} }
//
// PUBBLICO: NON è nel <FilesMatch> Basic Auth del .htaccess (superficie pubblica
// minima e verificabile). Vedi PIANO-ECOMMERCE-OBJECT-WOO.md §3-§4.
// -----------------------------------------------------------

require_once __DIR__ . '/ardy-db.php';
require_once __DIR__ . '/ardy-net.php';

// CORS: allowlist esplicita (il negozio + ardyagent per il proxy/test).
$allowedOrigins = ['https://object.ardy-lab.it', 'https://ardyagent.ardy-lab.it'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
header('Access-Control-Allow-Origin: ' . (in_array($origin, $allowedOrigins, true) ? $origin : 'https://object.ardy-lab.it'));
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

// Rate limit leggero per IP (riuso della cartella dei proxy). IP reale dietro
// Cloudflare via ardyClientIp().
$rlDir = __DIR__ . '/ardy-rate-limit/';
if (!is_dir($rlDir)) @mkdir($rlDir, 0755, true);
$ip     = function_exists('ardyClientIp') ? ardyClientIp() : ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
$rlFile = $rlDir . md5($ip) . '_objsched.json';
$now    = time();
$rl     = is_file($rlFile) ? json_decode((string) file_get_contents($rlFile), true) : ['count' => 0, 'reset' => $now + 3600];
if (!is_array($rl) || $now > ($rl['reset'] ?? 0)) { $rl = ['count' => 0, 'reset' => $now + 3600]; }
$rl['count']++;
@file_put_contents($rlFile, json_encode($rl));
if ($rl['count'] > 300) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Troppe richieste, riprova tra poco.']);
    exit();
}

// Slug: stessa forma di quello generato in ardy-progetti-api.php.
$slug = substr(preg_replace('/[^a-z0-9\-]/', '', strtolower(trim((string) ($_GET['slug'] ?? '')))), 0, 80);
if ($slug === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'slug mancante']);
    exit();
}

try {
    $db = ardyDB();

    // WHITELIST esplicita: SOLO campi pubblici. Nessun costo/margine/BOM/file/iterazione.
    $st = $db->prepare(
        "SELECT slug, titolo, tipo, descrizione, storia, materiali, scheda_tecnica,
                dimensioni, cura, faq_pubbliche, prezzo_vendita, copertina_url
         FROM progetti
         WHERE slug = ? AND scheda_sole_pubblica = 1 AND deleted_at IS NULL
         LIMIT 1"
    );
    $st->execute([$slug]);
    $r = $st->fetch(PDO::FETCH_ASSOC);

    if (!$r) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Oggetto non trovato']);
        exit();
    }

    $faq = json_decode($r['faq_pubbliche'] ?? 'null', true);
    if (!is_array($faq)) { $faq = []; }

    echo json_encode([
        'success' => true,
        'oggetto' => [
            'slug'           => $r['slug'],
            'titolo'         => $r['titolo'],
            'tipo'           => $r['tipo'],
            'descrizione'    => $r['descrizione'],
            'storia'         => $r['storia'],
            'materiali'      => $r['materiali'],
            'scheda_tecnica' => $r['scheda_tecnica'],
            'dimensioni'     => $r['dimensioni'],
            'cura'           => $r['cura'],
            'faq'            => $faq,
            'prezzo_vendita' => $r['prezzo_vendita'] !== null ? (float) $r['prezzo_vendita'] : null,
            'copertina_url'  => $r['copertina_url'],
        ],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('ARDY OBJECT SCHEDA: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Errore server']);
}
