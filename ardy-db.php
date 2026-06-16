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

/** Ultime 9 cifre numeriche di un telefono (ignora +/spazi/trattini), o stringa vuota. */
function ardyTelefonoLast9(string $tel): string {
    $digits = preg_replace('/\D+/', '', $tel);
    return substr($digits, -9);
}

// Migrazione idempotente: colonna 'telefono_last9' (+ indice) sulla tabella clienti.
// La ricerca per numero (lookup WhatsApp, sposta appuntamento) usava
// REPLACE(...) LIKE '%...' sulla colonna telefono, full-scan ad ogni richiesta.
// Questa colonna calcolata permette un match esatto e indicizzato.
function ardyEnsureTelefonoLast9(PDO $db): void {
    if (!$db->query("SHOW COLUMNS FROM clienti LIKE 'telefono_last9'")->fetch()) {
        $db->exec("ALTER TABLE clienti ADD COLUMN telefono_last9 VARCHAR(9) NULL AFTER telefono");
        $db->exec("CREATE INDEX idx_clienti_telefono_last9 ON clienti (telefono_last9)");
        // Backfill una tantum: eseguito solo qui, non ad ogni richiesta.
        $db->exec(
            "UPDATE clienti
                SET telefono_last9 = RIGHT(REPLACE(REPLACE(REPLACE(REPLACE(telefono,' ',''),'+',''),'-',''),'.',''), 9)
              WHERE telefono IS NOT NULL AND telefono <> ''"
        );
    }
}
