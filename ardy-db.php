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

// Le colonne 'stato', 'ordine' e 'prezzo' su fasi sono create da ardy-migrate.php
// (al deploy). Funzione mantenuta come no-op per non rompere i call site.
function ardyEnsureFasiStatoOrdine(PDO $db): void {
    // no-op: DDL centralizzato in ardy-migrate.php
}

/** Ultime 9 cifre numeriche di un telefono (ignora +/spazi/trattini), o stringa vuota. */
function ardyTelefonoLast9(string $tel): string {
    $digits = preg_replace('/\D+/', '', $tel);
    return substr($digits, -9);
}

// La colonna 'telefono_last9' (+ indice + backfill) su clienti è creata da
// ardy-migrate.php (al deploy). Funzione mantenuta come no-op.
// La ricerca per numero usa un match esatto e indicizzato su questa colonna.
function ardyEnsureTelefonoLast9(PDO $db): void {
    // no-op: DDL centralizzato in ardy-migrate.php
}
