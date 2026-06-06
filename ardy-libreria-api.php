<?php
// -----------------------------------------------------------
// ARDY LAB — Libreria Fasi (template riutilizzabili nei preventivi)
// Sostituisce il localStorage della dashboard: così la libreria
// è condivisa tra telefono e computer.
//
//   GET                      → lista di tutte le fasi
//   POST {mode:'save', ...}  → crea/aggiorna una fase (upsert)
//   POST {mode:'delete', id} → elimina una fase
// -----------------------------------------------------------

require_once __DIR__ . '/ardy-db.php';

header('Access-Control-Allow-Origin: https://ardyagent.ardy-lab.it');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

// Fasi predefinite (allineate a FASI_DEFAULT nella dashboard) — usate solo
// per il seeding iniziale alla creazione della tabella.
function libreriaDefaults(): array {
    return [
        ['def1',  'Carteggiatura manuale grana fine',       'Preparazione', 'Carteggiatura superficiale a mano con carta abrasiva a grana fine (180-240) per aprire il poro del legno e garantire l\'ancoraggio dei successivi strati di finitura.'],
        ['def2',  'Sverniciatura completa',                 'Preparazione', 'Rimozione totale a mano dei vecchi strati di finitura mediante applicazione di sverniciatore specifico e successiva raschiatura meccanica, per preservare le fibre e l\'integrità del legno antico senza stress termici o chimici aggressivi.'],
        ['def3',  'Stuccatura e chiusura imperfezioni',     'Preparazione', 'Chiusura di eventuali fori, crepe o piccole imperfezioni del legno con stucco specifico a base acqua o vinilico, carteggiatura a secco dopo asciugatura completa.'],
        ['def4',  'Applicazione primer ancorante',          'Verniciatura', 'Applicazione di fondo ancorante specifico per legno a pennello o rullo a pelo corto, per garantire l\'adesione degli strati di finitura successivi e uniformare l\'assorbimento del supporto.'],
        ['def5',  'Laccatura a Chalky Paint',               'Verniciatura', 'Applicazione di Chalky Paint (vernice gessosa) in 2-3 mani con carteggiatura intermedia a grana finissima. Garantisce finitura opaca e materica tipica del gusto contemporaneo.'],
        ['def6',  'Verniciatura con smalto a pennello',     'Verniciatura', 'Stesura di smalto sintetico o all\'acqua con pennello di qualità o rullo a pelo corto. Minimo 2 mani con carteggiatura intermedia per finitura liscia e uniforme.'],
        ['def7',  'Finitura con protettivo vetrificante',   'Finitura',     'Applicazione di protettivo vetrificante opaco o satinato per sigillare e proteggere la finitura, rendendola idrorepellente e resistente ai graffi e alle macchie.'],
        ['def8',  'Patinatura con cera vergine d\'api',     'Finitura',     'Applicazione di cera vergine d\'api pregiata a tampone su tutta la superficie. Lavorazione circolare seguita da tiratura per ottenere effetto satinato, morbido e duraturo con proprietà protettive naturali.'],
        ['def9',  'Lucidatura a gommalacca e tampone',      'Finitura',     'Applicazione di gommalacca decerata di alta qualità con tecnica a tampone. Lavorazione manuale in più passaggi per ottenere finitura brillante, profonda e tradizionale tipica del restauro conservativo.'],
        ['def10', 'Pulizia e lucidatura ottoni originali',  'Restauro',     'Smontaggio, pulizia profonda e lucidatura a specchio di tutta la ferramenta originale (pomelli, cerniere, chiavi). Rimontaggio con verifica allineamento ante e cassetti.'],
        ['def11', 'Consolidamento strutturale',             'Falegnameria', 'Interventi di falegnameria fine per il ripristino strutturale: serraggio giunzioni, incollaggio fessure, ricostruzione parti degradate con legno della stessa essenza dell\'originale.'],
        ['def12', 'Smontaggio, imballaggio e trasporto',    'Logistica',    'Smontaggio accurato del manufatto in loco, imballaggio protettivo professionale con materiali imbottiti e trasporto presso il laboratorio. Incluso rimontaggio e messa in bolla a fine lavorazione.'],
    ];
}

// Crea la tabella se non esiste e, alla prima creazione, inserisce i default.
function ensureLibreriaTable(PDO $db): void {
    $existed = (bool) $db->query("SHOW TABLES LIKE 'libreria_fasi'")->fetch();

    $db->exec("
        CREATE TABLE IF NOT EXISTS `libreria_fasi` (
            `id`         VARCHAR(64) NOT NULL PRIMARY KEY,
            `nome`       VARCHAR(255) NOT NULL,
            `cat`        VARCHAR(64)  NOT NULL DEFAULT 'Altro',
            `descr`      TEXT,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    if (!$existed) {
        $stmt = $db->prepare(
            "INSERT INTO `libreria_fasi` (`id`, `nome`, `cat`, `descr`) VALUES (?, ?, ?, ?)"
        );
        foreach (libreriaDefaults() as $f) {
            $stmt->execute($f);
        }
    }
}

try {
    $db = ardyDB();
    ensureLibreriaTable($db);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $rows = $db->query("SELECT * FROM `libreria_fasi` ORDER BY `cat` ASC, `nome` ASC")->fetchAll();
        $out  = array_map(function ($r) {
            return [
                'id'   => $r['id'],
                'nome' => $r['nome'],
                'cat'  => $r['cat'],
                'desc' => $r['descr'] ?? '',
            ];
        }, $rows);
        echo json_encode($out);
        exit();
    }

    // POST
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $mode  = $input['mode'] ?? 'save';

    if ($mode === 'delete') {
        $id = $input['id'] ?? '';
        if ($id === '') {
            echo json_encode(['success' => false, 'error' => 'id mancante']);
            exit();
        }
        $stmt = $db->prepare("DELETE FROM `libreria_fasi` WHERE `id` = ?");
        $stmt->execute([$id]);
        echo json_encode(['success' => true]);
        exit();
    }

    // mode === 'save' (upsert)
    $nome = trim($input['nome'] ?? '');
    $cat  = trim($input['cat']  ?? 'Altro');
    $desc = trim($input['desc'] ?? '');
    $id   = trim($input['id']   ?? '');

    if ($nome === '') {
        echo json_encode(['success' => false, 'error' => 'Nome fase mancante']);
        exit();
    }
    if ($id === '') {
        $id = 'custom_' . round(microtime(true) * 1000);
    }
    if ($cat === '') { $cat = 'Altro'; }

    $stmt = $db->prepare("
        INSERT INTO `libreria_fasi` (`id`, `nome`, `cat`, `descr`)
        VALUES (:id, :nome, :cat, :descr)
        ON DUPLICATE KEY UPDATE
            `nome`  = VALUES(`nome`),
            `cat`   = VALUES(`cat`),
            `descr` = VALUES(`descr`)
    ");
    $stmt->execute([
        'id'    => $id,
        'nome'  => $nome,
        'cat'   => $cat,
        'descr' => $desc,
    ]);

    echo json_encode(['success' => true, 'id' => $id]);

} catch (PDOException $e) {
    error_log('ARDY LIBRERIA API ERROR: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Errore interno']);
}
