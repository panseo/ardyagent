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

/**
 * Cerca un'attività nell'elenco contatti outreach (import B&B/antiquari/altre
 * campagne), per telefono e/o nome — usata da WhatsApp e webchat per riconoscere
 * un'attività già nel nostro elenco prima di trattarla come sconosciuta.
 * outreach_contatti non ha una colonna last9 indicizzata (tabella piccola,
 * niente migrazione dedicata): confronto le ultime 9 cifre al volo in SQL.
 */
function ardyOutreachCerca(PDO $db, string $telefono = '', string $nome = ''): ?array {
    $found = null;
    $last9 = $telefono !== '' ? ardyTelefonoLast9($telefono) : '';
    if ($last9 !== '') {
        $q = $db->prepare(
            "SELECT id, nome, categoria, referente, sito, indirizzo, stato, note
               FROM outreach_contatti
              WHERE telefono IS NOT NULL AND telefono <> ''
                AND RIGHT(REPLACE(REPLACE(REPLACE(telefono,' ',''),'-',''),'+',''), 9) = :p
           ORDER BY updated_at DESC LIMIT 1"
        );
        $q->execute([':p' => $last9]);
        $found = $q->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if (!$found && trim($nome) !== '') {
        $q = $db->prepare(
            "SELECT id, nome, categoria, referente, sito, indirizzo, stato, note
               FROM outreach_contatti
              WHERE nome LIKE :n
           ORDER BY updated_at DESC LIMIT 1"
        );
        $q->execute([':n' => '%' . trim($nome) . '%']);
        $found = $q->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    return $found;
}

/**
 * Completa SOLO i campi vuoti di un contatto outreach (mai sovrascrive un dato
 * già presente — stessa regola dell'arricchimento in blocco). Usata da Sole per
 * registrare un dato mancante (es. il referente) appena il contatto lo dà.
 */
function ardyOutreachAggiorna(PDO $db, int $id, array $campi): array {
    $consentiti = ['referente', 'email', 'sito', 'indirizzo', 'note'];
    $set    = [];
    $params = [':id' => $id];
    foreach ($consentiti as $c) {
        if (!empty($campi[$c])) {
            $set[]         = "{$c} = CASE WHEN ({$c} IS NULL OR {$c} = '') THEN :{$c} ELSE {$c} END";
            $params[":{$c}"] = trim((string) $campi[$c]);
        }
    }
    if (!$set) return ['ok' => false, 'aggiornati' => []];
    $set[] = 'updated_at = NOW()';
    $db->prepare('UPDATE outreach_contatti SET ' . implode(', ', $set) . ' WHERE id = :id')->execute($params);
    return ['ok' => true, 'aggiornati' => array_values(array_intersect($consentiti, array_keys($campi)))];
}
