<?php
/**
 * ARDY LAB — Migrazione schema DB (idempotente)
 *
 * Centralizza TUTTI i DDL (CREATE TABLE, ALTER TABLE ADD COLUMN) che prima
 * erano sparsi nei singoli endpoint e giravano su ogni request HTTP.
 * Questo script gira una volta sola ad ogni deploy (chiamato da deploy.sh).
 *
 * È sicuro rieseguirlo: ogni operazione è protetta da IF NOT EXISTS o try/catch.
 */

require_once __DIR__ . '/ardy-config.php';

$pdo = new PDO(
    'mysql:host=' . ARDY_DB_HOST . ';dbname=' . ARDY_DB_NAME . ';charset=utf8mb4',
    ARDY_DB_USER,
    ARDY_DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$ok = 0; $skip = 0;

function ddl(PDO $db, string $sql, string $label): void {
    global $ok, $skip;
    try {
        $db->exec($sql);
        echo "  OK   $label\n";
        $ok++;
    } catch (PDOException $e) {
        // 1060 = Duplicate column, 1050 = Table already exists
        if (in_array($e->errorInfo[1], [1060, 1050], true)) {
            echo "  skip $label\n";
            $skip++;
        } else {
            echo "  ERR  $label — " . $e->getMessage() . "\n";
        }
    }
}

function colExists(PDO $db, string $table, string $col): bool {
    return (bool) $db->query("SHOW COLUMNS FROM `$table` LIKE '$col'")->fetch();
}

function indexExists(PDO $db, string $table, string $index): bool {
    $st = $db->prepare("SHOW INDEX FROM `$table` WHERE Key_name = ?");
    $st->execute([$index]);
    return (bool) $st->fetch();
}

echo "=== Ardy Migrate ===\n";

// ── TABELLE ──────────────────────────────────────────────────────────────────

ddl($pdo, "CREATE TABLE IF NOT EXISTS `wa_messaggi` (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    phone VARCHAR(32) NOT NULL,
    role VARCHAR(16) NOT NULL,
    content MEDIUMTEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_phone (phone, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "CREATE wa_messaggi");

ddl($pdo, "CREATE TABLE IF NOT EXISTS `web_messaggi` (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    session_id VARCHAR(64) NOT NULL,
    role VARCHAR(16) NOT NULL,
    content MEDIUMTEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_session (session_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "CREATE web_messaggi");

ddl($pdo, "CREATE TABLE IF NOT EXISTS `social_bozze` (
    id         VARCHAR(40)  NOT NULL PRIMARY KEY,
    session_id VARCHAR(120) NULL,
    payload    MEDIUMTEXT   NOT NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "CREATE social_bozze");

ddl($pdo, "CREATE TABLE IF NOT EXISTS `solleciti_pagamento` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_id VARCHAR(64) NULL,
    telefono VARCHAR(32) NULL,
    nome_cliente VARCHAR(160) NOT NULL,
    email VARCHAR(190) NULL,
    importo_dovuto DECIMAL(10,2) NULL,
    data_scadenza DATE NULL,
    numero_sollecito TINYINT NOT NULL DEFAULT 0,
    data_ultimo_sollecito DATETIME NULL,
    risposta_cliente TEXT NULL,
    stato VARCHAR(16) NOT NULL DEFAULT 'APERTO',
    preventivo_ref TEXT NULL,
    note_interne TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_stato (stato),
    INDEX idx_session (session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "CREATE solleciti_pagamento");

ddl($pdo, "CREATE TABLE IF NOT EXISTS `visite_pagina` (
    `pagina` VARCHAR(64) NOT NULL,
    `giorno` DATE NOT NULL,
    `visite` INT NOT NULL DEFAULT 0,
    `unici`  INT NOT NULL DEFAULT 0,
    PRIMARY KEY (`pagina`, `giorno`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "CREATE visite_pagina");

ddl($pdo, "CREATE TABLE IF NOT EXISTS `email_bozze` (
    `id`           INT AUTO_INCREMENT PRIMARY KEY,
    `session_id`   VARCHAR(64)  NOT NULL,
    `destinatario` VARCHAR(255) NOT NULL DEFAULT '',
    `oggetto`      VARCHAR(255) NOT NULL DEFAULT '',
    `testo`        TEXT NOT NULL,
    `allegato_pdf` VARCHAR(255) NULL,
    `created_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX (session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "CREATE email_bozze");

ddl($pdo, "CREATE TABLE IF NOT EXISTS `libreria_fasi` (
    `id`         VARCHAR(64) NOT NULL PRIMARY KEY,
    `nome`       VARCHAR(255) NOT NULL,
    `cat`        VARCHAR(64)  NOT NULL DEFAULT 'Altro',
    `descr`      TEXT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "CREATE libreria_fasi");

ddl($pdo, "CREATE TABLE IF NOT EXISTS `reel_template` (
    `id`                VARCHAR(64) NOT NULL PRIMARY KEY,
    `nome`              VARCHAR(120) NOT NULL,
    `sec_foto`          DECIMAL(4,2) NOT NULL DEFAULT 2.50,
    `sec_titolo`        DECIMAL(4,2) NOT NULL DEFAULT 3.50,
    `sec_finale`        DECIMAL(4,2) NOT NULL DEFAULT 4.00,
    `mostra_titolo`     TINYINT(1) NOT NULL DEFAULT 1,
    `mostra_didascalie` TINYINT(1) NOT NULL DEFAULT 1,
    `mostra_finale`     TINYINT(1) NOT NULL DEFAULT 1,
    `musica_default`    VARCHAR(120) NOT NULL DEFAULT 'nessuna',
    `created_at`        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "CREATE reel_template");

ddl($pdo, "CREATE TABLE IF NOT EXISTS `outreach_contatti` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `nome`         VARCHAR(200) NOT NULL,
    `referente`    VARCHAR(100) DEFAULT NULL,
    `categoria`    VARCHAR(50) NOT NULL DEFAULT 'antiquari',
    `email`        VARCHAR(191) DEFAULT NULL,
    `telefono`     VARCHAR(50) DEFAULT NULL,
    `sito`         VARCHAR(500) DEFAULT NULL,
    `indirizzo`    VARCHAR(400) DEFAULT NULL,
    `stato`        VARCHAR(50) NOT NULL DEFAULT 'da_contattare',
    `note`         TEXT DEFAULT NULL,
    `data_contatto` DATE DEFAULT NULL,
    `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_categoria` (`categoria`),
    KEY `idx_stato` (`stato`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci", "CREATE outreach_contatti");

ddl($pdo, "CREATE TABLE IF NOT EXISTS `outreach_template` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `nome`       VARCHAR(100) NOT NULL,
    `categoria`  VARCHAR(50) DEFAULT NULL,
    `oggetto`    VARCHAR(200) NOT NULL,
    `corpo`      TEXT NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci", "CREATE outreach_template");

ddl($pdo, "CREATE TABLE IF NOT EXISTS `conoscenza_appresa` (
    `id`           INT AUTO_INCREMENT PRIMARY KEY,
    `titolo`       VARCHAR(200) NOT NULL DEFAULT '',
    `contenuto`    MEDIUMTEXT NOT NULL,
    `attiva`       TINYINT(1) NOT NULL DEFAULT 1,
    `origine_fasi` TEXT NULL,
    `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "CREATE conoscenza_appresa");

// Sopralluoghi: un cliente → N visite (1°, 2°, sopralluogo colori, ecc.). Ogni
// riga ha la sua data/ora e il suo evento Google Calendar. Il "prossimo"
// sopralluogo resta rispecchiato su clienti.sopralluogo_at/gcal_event_id (così
// Sole su WhatsApp continua a funzionare finché non passa anche lei alla lista).
ddl($pdo, "CREATE TABLE IF NOT EXISTS `sopralluoghi` (
    `id`            BIGINT AUTO_INCREMENT PRIMARY KEY,
    `session_id`    VARCHAR(64) NOT NULL,
    `data_ora`      DATETIME NOT NULL,
    `etichetta`     VARCHAR(80) NOT NULL DEFAULT 'Sopralluogo',
    `note`          TEXT NULL,
    `gcal_event_id` VARCHAR(255) NULL,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_sopr_session (session_id, data_ora)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "CREATE sopralluoghi");

// Travaso (idempotente): per ogni cliente attivo che ha già un sopralluogo nei
// vecchi campi ma NESSUNA riga in sopralluoghi, crea la prima riga. Re-eseguibile
// senza duplicare (grazie al NOT EXISTS).
ddl($pdo, "INSERT INTO sopralluoghi (session_id, data_ora, etichetta, gcal_event_id, created_at, updated_at)
    SELECT c.session_id, c.sopralluogo_at, 'Sopralluogo', c.gcal_event_id, NOW(), NOW()
      FROM clienti c
     WHERE c.sopralluogo_at IS NOT NULL
       AND c.deleted_at IS NULL
       AND NOT EXISTS (SELECT 1 FROM sopralluoghi s WHERE s.session_id = c.session_id)",
    "BACKFILL sopralluoghi");

// Nota settimanale "cose da fare" dello staff (un promemoria libero che Michela
// detta a Sole su WhatsApp e si fa rileggere/aggiornare). Ogni salvataggio è una
// riga nuova (storico settimane); si legge sempre la più recente.
ddl($pdo, "CREATE TABLE IF NOT EXISTS `note_staff` (
    `id`         BIGINT AUTO_INCREMENT PRIMARY KEY,
    `settimana`  VARCHAR(12) NOT NULL DEFAULT '',
    `testo`      MEDIUMTEXT NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "CREATE note_staff");

// ── DASH DESIGN (progetti interni) — vedi PIANO-DASH-DESIGN.md ────────────────
// Progetto interno di design (lampade, mobili, complementi, restyling, prototipi):
// soggetto della dash design, gemella della dash clienti. Non ha campi "cliente";
// il ciclo di vita finisce a 'A_CATALOGO' (stock/vendita vivono su Woo/Etsy, fuori
// dalla dash). 'costo_produzione' è CALCOLATO dalla BOM (progetto_materiali) × scarto.
ddl($pdo, "CREATE TABLE IF NOT EXISTS `progetti` (
    `id`                BIGINT AUTO_INCREMENT PRIMARY KEY,
    `slug`              VARCHAR(80)  NULL,
    `titolo`            VARCHAR(200) NOT NULL DEFAULT '',
    `tipo`              VARCHAR(40)  NOT NULL DEFAULT 'lampada',
    `stato`             VARCHAR(30)  NOT NULL DEFAULT 'IDEA',
    `descrizione`       TEXT NULL,
    `materiali`         TEXT NULL,                 -- descrizione PUBBLICA per la listing
    `scheda_tecnica`    TEXT NULL,
    `scarto_pct`        DECIMAL(5,2)  NOT NULL DEFAULT 10.00,
    `costo_produzione`  DECIMAL(10,2) NULL,        -- calcolato: Σ BOM × (1 + scarto)
    `prezzo_vendita`    DECIMAL(10,2) NULL,
    `tempo_lavoro`      VARCHAR(80) NULL,
    `file_congelato_at` DATETIME NULL,             -- click manuale 'congela file'
    `file_snapshot`     MEDIUMTEXT NULL,           -- JSON: STL + profilo Orca + scheda al congelamento
    `render_urls`       TEXT NULL,                 -- JSON
    `cad_urls`          TEXT NULL,                 -- JSON
    `canali_vendita`    TEXT NULL,                 -- JSON: dove è pubblicato + link annunci
    `woo_product_id`    BIGINT NULL,
    `copertina_url`     VARCHAR(500) NULL,
    `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at`        DATETIME NULL,
    INDEX idx_progetti_deleted_updated (deleted_at, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "CREATE progetti");

// Distinta costi (BOM) interna di un progetto. Le voci 'filamento' (g) e 'stampa'
// (h) si pre-compilano coi numeri di OrcaSlicer; le altre a mano. costo_riga è
// calcolato (qta × costo_unitario); la somma × scarto alimenta progetti.costo_produzione.
ddl($pdo, "CREATE TABLE IF NOT EXISTS `progetto_materiali` (
    `id`             BIGINT AUTO_INCREMENT PRIMARY KEY,
    `progetto_id`    BIGINT NOT NULL,
    `categoria`      VARCHAR(30)  NOT NULL DEFAULT 'filamento', -- filamento|stampa|elettrico|ferramenta|finitura|imballo|manodopera
    `voce`           VARCHAR(200) NOT NULL DEFAULT '',
    `qta`            DECIMAL(12,3) NOT NULL DEFAULT 0,          -- grammi | ore | pezzi
    `unita`          VARCHAR(8)   NOT NULL DEFAULT 'pz',        -- g | h | pz
    `costo_unitario` DECIMAL(12,4) NOT NULL DEFAULT 0,
    `costo_riga`     DECIMAL(12,2) NOT NULL DEFAULT 0,
    `note`           TEXT NULL,
    `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_mat_progetto (progetto_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "CREATE progetto_materiali");

// Binario R&D: iterazioni del prototipo (v1, v2, v3…) con note di iterazione.
// Interne di default; 'promossa_a_fase_id' != NULL = promossa a contenuto pubblico.
ddl($pdo, "CREATE TABLE IF NOT EXISTS `progetto_iterazioni` (
    `id`                 BIGINT AUTO_INCREMENT PRIMARY KEY,
    `progetto_id`        BIGINT NOT NULL,
    `v_num`              INT NOT NULL DEFAULT 1,
    `note`               TEXT NULL,
    `foto_urls`          TEXT NULL,                 -- JSON
    `cad_ref`            VARCHAR(255) NULL,         -- file/profilo CAD-slicer usato
    `promossa_a_fase_id` BIGINT NULL,
    `created_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_iter_progetto (progetto_id, v_num)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "CREATE progetto_iterazioni");

// Archivio file di stampa/CAD del progetto: i file veri (STL, 3MF, G-code, STEP…)
// che poi si prelevano e si stampano. Il DB salva solo i NOMI file (su disco) + i
// metadati; i binari vivono in ARDY_UPLOAD_DIR/progetti/<id>/file/ e si scaricano via
// ardy-progetti-file-api.php (?download=). Stesso seam delle foto (migrabile a B2).
ddl($pdo, "CREATE TABLE IF NOT EXISTS `progetto_file` (
    `id`             BIGINT AUTO_INCREMENT PRIMARY KEY,
    `progetto_id`    BIGINT NOT NULL,
    `categoria`      VARCHAR(20)  NOT NULL DEFAULT 'stl',   -- stl|3mf|gcode|cad|altro
    `storage`        VARCHAR(10)  NOT NULL DEFAULT 'local', -- local|b2 (dove vive il binario)
    `nome_file`      VARCHAR(255) NOT NULL,                 -- nome su disco (univoco)
    `storage_key`    VARCHAR(300) NULL,                     -- object key su B2 (se storage=b2)
    `nome_originale` VARCHAR(255) NOT NULL,                 -- nome scelto dall'utente
    `dimensione`     BIGINT       NOT NULL DEFAULT 0,       -- byte
    `note`           VARCHAR(500) NULL,
    `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_file_progetto (progetto_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "CREATE progetto_file");

// Colonne aggiunte dopo l'intro della tabella (installazioni dove esiste già senza
// lo strato storage): dove vive il binario (local|b2) e la sua object key su B2.
$progettoFileCols = [
    'storage'     => "ALTER TABLE progetto_file ADD COLUMN storage VARCHAR(10) NOT NULL DEFAULT 'local' AFTER categoria",
    'storage_key' => "ALTER TABLE progetto_file ADD COLUMN storage_key VARCHAR(300) NULL AFTER nome_file",
];
foreach ($progettoFileCols as $col => $sql) {
    if (!colExists($pdo, 'progetto_file', $col)) { ddl($pdo, $sql, "progetto_file.$col"); }
    else { echo "  skip progetto_file.$col\n"; $skip++; }
}

// Galleria del progetto: render e foto della creazione finita (immagini pubbliche
// per l'articolo WordPress). Distinta dall'archivio file (STL/CAD, interni) e dalle
// foto delle fasi-racconto. Storage come il resto: nomi su DB, binari su disco/B2.
ddl($pdo, "CREATE TABLE IF NOT EXISTS `progetto_galleria` (
    `id`          BIGINT AUTO_INCREMENT PRIMARY KEY,
    `progetto_id` BIGINT NOT NULL,
    `tipo`        VARCHAR(10)  NOT NULL DEFAULT 'render', -- render | foto
    `storage`     VARCHAR(10)  NOT NULL DEFAULT 'local',  -- local | b2
    `nome_file`   VARCHAR(255) NOT NULL,
    `storage_key` VARCHAR(300) NULL,
    `dimensione`  BIGINT       NOT NULL DEFAULT 0,
    `ordine`      INT          NOT NULL DEFAULT 0,
    `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_galleria_progetto (progetto_id, ordine)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "CREATE progetto_galleria");

// Colonne WordPress sul progetto: id e link dell'articolo "madre" (pubblicato dalla
// galleria + descrizione; le fasi-racconto poi vi si agganciano in append).
$progettiWpCols = [
    'wp_post_id'   => "ALTER TABLE progetti ADD COLUMN wp_post_id BIGINT NULL AFTER woo_product_id",
    'wp_post_link' => "ALTER TABLE progetti ADD COLUMN wp_post_link VARCHAR(500) NULL AFTER wp_post_id",
];
foreach ($progettiWpCols as $col => $sql) {
    if (!colExists($pdo, 'progetti', $col)) { ddl($pdo, $sql, "progetti.$col"); }
    else { echo "  skip progetti.$col\n"; $skip++; }
}

// ── COLONNE clienti ───────────────────────────────────────────────────────────

$clientiCols = [
    'deleted_at'              => "ALTER TABLE clienti ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL",
    'conversazione_letta_at'  => "ALTER TABLE clienti ADD COLUMN conversazione_letta_at DATETIME NULL DEFAULT NULL",
    'consegnato_grazie_at'    => "ALTER TABLE clienti ADD COLUMN consegnato_grazie_at DATETIME NULL",
    'primo_contatto_wa_at'    => "ALTER TABLE clienti ADD COLUMN primo_contatto_wa_at DATETIME NULL",
    'gcal_event_id'           => "ALTER TABLE clienti ADD COLUMN gcal_event_id VARCHAR(128) NULL",
    'sopralluogo_at'          => "ALTER TABLE clienti ADD COLUMN sopralluogo_at DATETIME NULL",
    'codice_accesso'          => "ALTER TABLE clienti ADD COLUMN codice_accesso VARCHAR(20) NULL",
    'codice_email_inviato'    => "ALTER TABLE clienti ADD COLUMN codice_email_inviato DATETIME NULL",
    'inizio_lavoro'           => "ALTER TABLE clienti ADD COLUMN inizio_lavoro DATE NULL",
    'fine_lavoro_prevista'    => "ALTER TABLE clienti ADD COLUMN fine_lavoro_prevista DATE NULL",
    'note_consegna'           => "ALTER TABLE clienti ADD COLUMN note_consegna TEXT NULL",
    'trasporto_data'          => "ALTER TABLE clienti ADD COLUMN trasporto_data DATE NULL",
    'trasporto_pronto_at'     => "ALTER TABLE clienti ADD COLUMN trasporto_pronto_at DATETIME NULL",
    'trasporto_avviso_data'   => "ALTER TABLE clienti ADD COLUMN trasporto_avviso_data DATE NULL",
    'faq_pubblicata_at'       => "ALTER TABLE clienti ADD COLUMN faq_pubblicata_at DATETIME NULL",
    'foto_archiviate_at'      => "ALTER TABLE clienti ADD COLUMN foto_archiviate_at DATETIME NULL",
    'telefono_last9'          => "ALTER TABLE clienti ADD COLUMN telefono_last9 VARCHAR(9) NULL AFTER telefono",
];
foreach ($clientiCols as $col => $sql) {
    if (!colExists($pdo, 'clienti', $col)) {
        ddl($pdo, $sql, "clienti.$col");
        if ($col === 'telefono_last9') {
            ddl($pdo, "CREATE INDEX idx_clienti_telefono_last9 ON clienti (telefono_last9)", "INDEX telefono_last9");
            $pdo->exec("UPDATE clienti SET telefono_last9 = RIGHT(REPLACE(REPLACE(REPLACE(REPLACE(telefono,' ',''),'+',''),'-',''),'.',''), 9) WHERE telefono IS NOT NULL AND telefono <> ''");
            echo "  OK   backfill telefono_last9\n";
        }
    } else {
        echo "  skip clienti.$col\n"; $skip++;
    }
}

// ── COLONNE fasi ─────────────────────────────────────────────────────────────

$fasiCols = [
    'fase_tipo'  => "ALTER TABLE fasi ADD COLUMN fase_tipo VARCHAR(20) NOT NULL DEFAULT 'fase' AFTER fase_nome",
    'stato'      => "ALTER TABLE fasi ADD COLUMN stato VARCHAR(20) NOT NULL DEFAULT 'pubblicata' AFTER fase_tipo",
    'ordine'     => "ALTER TABLE fasi ADD COLUMN ordine INT NULL AFTER stato",
    'prezzo'     => "ALTER TABLE fasi ADD COLUMN prezzo DECIMAL(10,2) NULL AFTER ordine",
    'video_urls' => "ALTER TABLE fasi ADD COLUMN video_urls TEXT NULL AFTER foto_urls",
    // Aggancio della stessa tabella fasi ai progetti di design (vedi PIANO-DASH-DESIGN.md):
    // una fase appartiene O a un cliente (session_id) O a un progetto (progetto_id).
    'progetto_id' => "ALTER TABLE fasi ADD COLUMN progetto_id BIGINT NULL AFTER session_id",
    // Quando una fase-racconto di progetto è stata pubblicata (append) sull'articolo WP.
    'wp_pubblicata_at' => "ALTER TABLE fasi ADD COLUMN wp_pubblicata_at DATETIME NULL",
];
foreach ($fasiCols as $col => $sql) {
    if (!colExists($pdo, 'fasi', $col)) {
        ddl($pdo, $sql, "fasi.$col");
        if ($col === 'progetto_id') {
            ddl($pdo, "CREATE INDEX idx_fasi_progetto ON fasi (progetto_id, ordine)", "INDEX fasi.progetto_id");
        }
    } else {
        echo "  skip fasi.$col\n"; $skip++;
    }
}

// La tabella `fasi` è nata per i clienti con session_id NOT NULL. Da quando una fase
// può appartenere a un PROGETTO di design (progetto_id) invece che a un cliente, il
// session_id deve poter essere NULL (altrimenti l'INSERT di una fase-progetto fallisce
// con "Field 'session_id' doesn't have a default value"). Reso nullable preservando il
// tipo esatto, una sola volta (idempotente via information_schema).
$sidCol = $pdo->query(
    "SELECT COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fasi' AND COLUMN_NAME = 'session_id'"
)->fetch(PDO::FETCH_ASSOC);
if ($sidCol && strtoupper($sidCol['IS_NULLABLE']) === 'NO') {
    ddl($pdo, "ALTER TABLE fasi MODIFY `session_id` {$sidCol['COLUMN_TYPE']} NULL", "fasi.session_id nullable");
} else {
    echo "  skip fasi.session_id nullable\n"; $skip++;
}

// ── INDICI ───────────────────────────────────────────────────────────────────

// Lista clienti in dashboard: WHERE deleted_at IS NULL ORDER BY updated_at DESC
// (e la vista Cestino: WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC).
if (!indexExists($pdo, 'clienti', 'idx_clienti_deleted_updated')) {
    ddl($pdo, "CREATE INDEX idx_clienti_deleted_updated ON clienti (deleted_at, updated_at)", "INDEX clienti.deleted_updated");
} else {
    echo "  skip INDEX clienti.deleted_updated\n"; $skip++;
}

// ── preventivi.voci_json → LONGTEXT (una tantum, via file marker) ─────────────
$marker = __DIR__ . '/preventivi_pdf/.voci_longtext';
if (!file_exists($marker)) {
    ddl($pdo, "ALTER TABLE preventivi MODIFY voci_json LONGTEXT", "preventivi.voci_json LONGTEXT");
    @file_put_contents($marker, '1');
} else {
    echo "  skip preventivi.voci_json LONGTEXT\n"; $skip++;
}

echo "\n=== Fine: $ok eseguiti, $skip già presenti ===\n";
