<?php
// -----------------------------------------------------------
// ARDY LAB — Connessione Database
// -----------------------------------------------------------

require_once __DIR__ . '/ardy-config.php';

function ardyDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host=' . ARDY_DB_HOST . ';dbname=' . ARDY_DB_NAME . ';charset=utf8mb4',
            ARDY_DB_USER,
            ARDY_DB_PASS,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
    }
    return $pdo;
}

// Migrazione idempotente: garantisce le colonne 'stato' ('bozza'|'pubblicata')
// e 'ordine' sulla tabella fasi, usate per le fasi pre-compilate dai template
// di libreria (generate dal box note del sopralluogo) prima della pubblicazione.
function ardyEnsureFasiStatoOrdine(PDO $db): void {
    if (!$db->query("SHOW COLUMNS FROM fasi LIKE 'stato'")->fetch()) {
        $db->exec("ALTER TABLE fasi ADD COLUMN stato VARCHAR(20) NOT NULL DEFAULT 'pubblicata' AFTER fase_tipo");
    }
    if (!$db->query("SHOW COLUMNS FROM fasi LIKE 'ordine'")->fetch()) {
        $db->exec("ALTER TABLE fasi ADD COLUMN ordine INT NULL AFTER stato");
    }
    if (!$db->query("SHOW COLUMNS FROM fasi LIKE 'prezzo'")->fetch()) {
        $db->exec("ALTER TABLE fasi ADD COLUMN prezzo DECIMAL(10,2) NULL AFTER ordine");
    }
}
