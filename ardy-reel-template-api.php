<?php
// -----------------------------------------------------------
// ARDY LAB — Libreria Template Reel (stili riutilizzabili)
// Gestisce i preset di stile usati da ardy-crea-reel.php.
//   GET                      → lista template
//   POST {mode:'save', ...}  → crea/aggiorna (upsert)
//   POST {mode:'delete', id} → elimina
// -----------------------------------------------------------

require_once __DIR__ . '/ardy-db.php';

header('Access-Control-Allow-Origin: https://ardyagent.ardy-lab.it');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

// Preset iniziali (seeding alla creazione della tabella)
function reelTemplateDefaults(): array {
    // [id, nome, sec_foto, sec_titolo, sec_finale, mostra_titolo, mostra_didascalie, mostra_finale, musica_default]
    return [
        ['classico',   'Classico',          2.5, 3.5, 4.0, 1, 1, 1, 'nessuna'],
        ['veloce',     'Veloce (Reels)',    1.5, 2.0, 2.5, 0, 1, 1, 'casuale'],
        ['cinematico', 'Cinematico',        3.5, 4.0, 5.0, 1, 1, 1, 'casuale'],
        ['solo_foto',  'Solo foto',         2.5, 0.0, 0.0, 0, 0, 0, 'nessuna'],
    ];
}

// La tabella è creata da ardy-migrate.php. Qui inseriamo i default una sola
// volta, se la tabella è ancora vuota (DML, non DDL).
function ensureReelTemplateTable(PDO $db): void {
    $vuota = (int) $db->query("SELECT COUNT(*) FROM `reel_template`")->fetchColumn() === 0;
    if ($vuota) {
        $stmt = $db->prepare(
            "INSERT INTO `reel_template`
             (`id`,`nome`,`sec_foto`,`sec_titolo`,`sec_finale`,`mostra_titolo`,`mostra_didascalie`,`mostra_finale`,`musica_default`)
             VALUES (?,?,?,?,?,?,?,?,?)"
        );
        foreach (reelTemplateDefaults() as $t) { $stmt->execute($t); }
    }
}

function rowOut(array $r): array {
    return [
        'id'                => $r['id'],
        'nome'              => $r['nome'],
        'sec_foto'          => (float) $r['sec_foto'],
        'sec_titolo'        => (float) $r['sec_titolo'],
        'sec_finale'        => (float) $r['sec_finale'],
        'mostra_titolo'     => (int) $r['mostra_titolo'],
        'mostra_didascalie' => (int) $r['mostra_didascalie'],
        'mostra_finale'     => (int) $r['mostra_finale'],
        'musica_default'    => $r['musica_default'] ?? 'nessuna',
    ];
}

try {
    $db = ardyDB();
    ensureReelTemplateTable($db);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $rows = $db->query("SELECT * FROM `reel_template` ORDER BY `nome` ASC")->fetchAll();
        echo json_encode(array_map('rowOut', $rows));
        exit();
    }

    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $mode  = $input['mode'] ?? 'save';

    if ($mode === 'delete') {
        $id = $input['id'] ?? '';
        if ($id === '') { echo json_encode(['success' => false, 'error' => 'id mancante']); exit(); }
        $db->prepare("DELETE FROM `reel_template` WHERE `id` = ?")->execute([$id]);
        echo json_encode(['success' => true]);
        exit();
    }

    // save (upsert)
    $nome = trim($input['nome'] ?? '');
    if ($nome === '') { echo json_encode(['success' => false, 'error' => 'Nome mancante']); exit(); }
    $id = trim($input['id'] ?? '');
    if ($id === '') $id = 'tpl_' . round(microtime(true) * 1000);

    $clamp = fn($v, $min, $max, $def) => is_numeric($v) ? max($min, min($max, (float)$v)) : $def;
    $params = [
        'id'    => $id,
        'nome'  => $nome,
        'sf'    => $clamp($input['sec_foto']   ?? null, 0.5, 10, 2.5),
        'st'    => $clamp($input['sec_titolo'] ?? null, 0,   10, 3.5),
        'sfin'  => $clamp($input['sec_finale'] ?? null, 0,   10, 4.0),
        'mt'    => !empty($input['mostra_titolo'])     ? 1 : 0,
        'md'    => !empty($input['mostra_didascalie']) ? 1 : 0,
        'mf'    => !empty($input['mostra_finale'])     ? 1 : 0,
        'mus'   => trim($input['musica_default'] ?? 'nessuna') ?: 'nessuna',
    ];

    $db->prepare("
        INSERT INTO `reel_template`
          (`id`,`nome`,`sec_foto`,`sec_titolo`,`sec_finale`,`mostra_titolo`,`mostra_didascalie`,`mostra_finale`,`musica_default`)
        VALUES (:id,:nome,:sf,:st,:sfin,:mt,:md,:mf,:mus)
        ON DUPLICATE KEY UPDATE
          `nome`=VALUES(`nome`), `sec_foto`=VALUES(`sec_foto`), `sec_titolo`=VALUES(`sec_titolo`),
          `sec_finale`=VALUES(`sec_finale`), `mostra_titolo`=VALUES(`mostra_titolo`),
          `mostra_didascalie`=VALUES(`mostra_didascalie`), `mostra_finale`=VALUES(`mostra_finale`),
          `musica_default`=VALUES(`musica_default`)
    ")->execute($params);

    echo json_encode(['success' => true, 'id' => $id]);

} catch (PDOException $e) {
    error_log('ARDY REEL TEMPLATE ERROR: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Errore interno']);
}
