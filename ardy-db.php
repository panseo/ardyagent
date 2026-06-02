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
