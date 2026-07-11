<?php
// -----------------------------------------------------------
// ARDY LAB — Push Dash → WooCommerce (a SENSO UNICO)
// Crea/aggiorna il prodotto Woo di un progetto usando lo STESSO slug (chiave
// d'aggancio della chat), e salva woo_product_id sul progetto. Woo non riscrive
// mai la dash. Vedi PIANO-ECOMMERCE-OBJECT-WOO.md §7.
//
//   POST {progetto_id}[, pubblica:true] → {success, woo_product_id, link, created, status}
//
// Credenziali Woo REST in ardy-config.php (fuori dal repo):
//   define('WOO_STORE_URL', 'https://object.ardy-lab.it');
//   define('WOO_CK', 'ck_...'); define('WOO_CS', 'cs_...');
// Protetto via .htaccess (Basic Auth) — elencato nel <FilesMatch>.
// -----------------------------------------------------------

require_once __DIR__ . '/ardy-config.php';
require_once __DIR__ . '/ardy-db.php';

header('Access-Control-Allow-Origin: https://ardyagent.ardy-lab.it');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }
require_once __DIR__ . '/ardy-auth.php';
ardyRequireAuth();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Metodo non valido']);
    exit();
}

// Config Woo obbligatoria.
foreach (['WOO_STORE_URL', 'WOO_CK', 'WOO_CS'] as $c) {
    if (!defined($c) || constant($c) === '') {
        echo json_encode(['success' => false, 'error' => "Config WooCommerce mancante ($c): aggiungi WOO_STORE_URL / WOO_CK / WOO_CS in ardy-config.php."]);
        exit();
    }
}

/** Chiamata autenticata alla REST API di WooCommerce (Basic Auth CK/CS su HTTPS). */
function wooRequest(string $method, string $path, ?array $body = null): array {
    $url = rtrim(WOO_STORE_URL, '/') . '/wp-json/wc/v3/' . ltrim($path, '/');
    $ch  = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST,  $method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT,        30);
    curl_setopt($ch, CURLOPT_USERPWD,        WOO_CK . ':' . WOO_CS);
    $headers = ['Accept: application/json'];
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return ['code' => $code, 'body' => json_decode((string) $res, true), 'raw' => $res, 'err' => $err];
}

/** Foto VENDITA del progetto (Modulo 2) come URL che Woo scarica — NON la galleria.
 *  URL con estensione immagine (via rewrite /object-img/{pid}-{gid}.{ext}) perché Woo
 *  ricava il tipo file dall'estensione nel path, non dal Content-Type. */
function objectFotoVendita(PDO $db, int $pid): array {
    $st = $db->prepare("SELECT id, nome_file FROM progetto_foto_vendita WHERE progetto_id = ? ORDER BY ordine ASC, id ASC");
    $st->execute([$pid]);
    $imgs = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $ext = strtolower(pathinfo($r['nome_file'], PATHINFO_EXTENSION));
        if ($ext === '' || $ext === 'jpeg') { $ext = 'jpg'; }
        $imgs[] = ['src' => 'https://ardyagent.ardy-lab.it/object-img/' . $pid . '-' . (int) $r['id'] . '.' . $ext];
    }
    return $imgs;
}

$in       = json_decode(file_get_contents('php://input'), true) ?: [];
$pid      = (int) ($in['progetto_id'] ?? $in['id'] ?? 0);
$pubblica = !empty($in['pubblica']);
$syncFoto = !empty($in['sync_foto']);
if ($pid <= 0) { echo json_encode(['success' => false, 'error' => 'progetto mancante']); exit(); }

try {
    $db = ardyDB();
    $st = $db->prepare("SELECT * FROM progetti WHERE id = ? AND deleted_at IS NULL");
    $st->execute([$pid]);
    $p = $st->fetch(PDO::FETCH_ASSOC);
    if (!$p) { echo json_encode(['success' => false, 'error' => 'Progetto non trovato']); exit(); }

    $slug = trim((string) ($p['slug'] ?? ''));
    if ($slug === '')                        { echo json_encode(['success' => false, 'error' => 'Il progetto non ha uno slug: salva prima la scheda.']); exit(); }
    if (trim((string) $p['titolo']) === '')  { echo json_encode(['success' => false, 'error' => 'Titolo mancante']); exit(); }

    // Descrizione lunga = concept + storia; breve = materiali + dimensioni.
    $descr = trim((string) $p['descrizione']);
    if (!empty($p['storia'])) { $descr = trim($descr . "\n\n" . $p['storia']); }
    $shortParts = [];
    if (!empty($p['materiali']))  { $shortParts[] = $p['materiali']; }
    if (!empty($p['dimensioni'])) { $shortParts[] = 'Dimensioni: ' . $p['dimensioni']; }
    $short = implode("\n", $shortParts);

    $payload = [
        'name'              => $p['titolo'],
        'slug'              => $slug,
        'type'              => 'simple',
        'description'       => $descr,
        'short_description' => $short,
        'status'            => $pubblica ? 'publish' : 'draft',
    ];
    if ($p['prezzo_vendita'] !== null && $p['prezzo_vendita'] !== '') {
        $payload['regular_price'] = (string) round((float) $p['prezzo_vendita'], 2);
    }

    // Risolvi il prodotto Woo: id salvato → oppure adotta un prodotto già esistente
    // con lo stesso slug (es. creato a mano) per non generare doppioni/slug -2.
    $wooId = (int) ($p['woo_product_id'] ?? 0);
    if ($wooId <= 0) {
        $found = wooRequest('GET', 'products?slug=' . urlencode($slug) . '&status=any');
        if (is_array($found['body'] ?? null) && isset($found['body'][0]['id'])) {
            $wooId = (int) $found['body'][0]['id'];
        }
    }

    // Foto vendita: le manda il push quando il prodotto Woo non ne ha ancora (auto-fill),
    // o quando si forza con sync_foto. Su un prodotto che ha già immagini (es. foto messa
    // a mano in Woo) NON le sovrascrive, per non cancellare scelte manuali.
    $hasImg = false;
    if ($wooId > 0) {
        $cur    = wooRequest('GET', "products/{$wooId}");
        $hasImg = is_array($cur['body']['images'] ?? null) && count($cur['body']['images']) > 0;
    }
    if ($wooId <= 0 || !$hasImg || $syncFoto) {
        $foto = objectFotoVendita($db, $pid);
        if ($foto) { $payload['images'] = $foto; }
    }

    if ($wooId > 0) {
        // Update: non toccare lo status se non è un "pubblica" esplicito.
        if (!$pubblica) { unset($payload['status']); }
        $r = wooRequest('PUT', "products/{$wooId}", $payload);
        $created = false;
    } else {
        $r = wooRequest('POST', 'products', $payload);
        $created = true;
    }

    if ($r['code'] < 200 || $r['code'] >= 300 || !isset($r['body']['id'])) {
        $msg = $r['body']['message'] ?? ($r['err'] !== '' ? $r['err'] : ('HTTP ' . $r['code']));
        error_log('ARDY OBJECT PUSH: HTTP ' . $r['code'] . ' — ' . substr((string) $r['raw'], 0, 400));
        echo json_encode(['success' => false, 'error' => 'WooCommerce: ' . $msg]);
        exit();
    }

    $newId = (int) $r['body']['id'];
    $link  = (string) ($r['body']['permalink'] ?? '');
    $db->prepare("UPDATE progetti SET woo_product_id = :wid WHERE id = :id")
       ->execute([':wid' => $newId, ':id' => $pid]);

    echo json_encode([
        'success'        => true,
        'woo_product_id' => $newId,
        'link'           => $link,
        'created'        => $created,
        'status'         => $r['body']['status'] ?? '',
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('ARDY OBJECT PUSH ERR: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Errore server']);
}
