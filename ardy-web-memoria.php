<?php
// -----------------------------------------------------------
// ARDY LAB — Memoria conversazione chat WEB (per session_id)
// -----------------------------------------------------------
// La chat del sito (ardy-proxy.php) era stateless: la conversazione viveva solo
// nel browser. Qui la persistiamo (come wa_messaggi per WhatsApp) così Sole può
// avere lo storico nel dossier cliente.
//
// SOLO LIBRERIA (niente endpoint HTTP): funzioni usate da ardy-proxy.php (scrive)
// e ardy-dossier.php (legge). Tabella web_messaggi auto-creata.
//
// Privacy: la chat web è anonima finché il cliente non lascia i dati; la lega al
// cliente solo il session_id (lo stesso salvato in clienti.session_id quando
// diventa lead). Retention: massimo ~100 righe per sessione.
// -----------------------------------------------------------

if (!function_exists('ardy_web_ensure_table')) {

function ardy_web_ensure_table(PDO $db): void {
    static $done = false;
    if ($done) return;
    $db->exec(
        "CREATE TABLE IF NOT EXISTS web_messaggi (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            session_id VARCHAR(64) NOT NULL,
            role VARCHAR(16) NOT NULL,
            content MEDIUMTEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_session (session_id, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $done = true;
}

function ardy_web_clean_session($s): string {
    return preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $s);
}

/**
 * Salva una o più righe { role, content } per la sessione.
 * $righe: [ ['role'=>'user','content'=>'...'], ['role'=>'assistant','content'=>'...'] ]
 * Non blocca mai il chiamante: in caso di errore logga e ritorna 0.
 */
function ardy_web_salva(PDO $db, string $sessionId, array $righe): int {
    $sid = ardy_web_clean_session($sessionId);
    if ($sid === '') return 0;
    try {
        ardy_web_ensure_table($db);
        $ins   = $db->prepare("INSERT INTO web_messaggi (session_id, role, content) VALUES (:s, :r, :c)");
        $saved = 0;
        foreach ($righe as $m) {
            $role    = (($m['role'] ?? '') === 'assistant') ? 'assistant' : 'user';
            $content = trim((string) ($m['content'] ?? ''));
            if ($content === '') continue;
            if (mb_strlen($content) > 8000) $content = mb_substr($content, 0, 8000);
            $ins->execute([':s' => $sid, ':r' => $role, ':c' => $content]);
            $saved++;
        }
        if ($saved > 0) {
            // Retention: tieni al massimo ~100 righe per sessione.
            $db->prepare(
                "DELETE FROM web_messaggi WHERE session_id = :s AND id NOT IN (
                    SELECT id FROM (SELECT id FROM web_messaggi WHERE session_id = :s2 ORDER BY id DESC LIMIT 100) t
                 )"
            )->execute([':s' => $sid, ':s2' => $sid]);
        }
        return $saved;
    } catch (PDOException $e) {
        error_log('ARDY WEB MEMORIA SALVA: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Storico recente della sessione (ordine cronologico). [] se assente/errore.
 */
function ardy_web_storico(PDO $db, string $sessionId, int $limit = 40): array {
    $sid = ardy_web_clean_session($sessionId);
    if ($sid === '') return [];
    try {
        ardy_web_ensure_table($db);
        $st = $db->prepare(
            "SELECT role, content, created_at FROM web_messaggi
              WHERE session_id = :s ORDER BY id DESC LIMIT :lim"
        );
        $st->bindValue(':s', $sid);
        $st->bindValue(':lim', $limit, PDO::PARAM_INT);
        $st->execute();
        return array_reverse($st->fetchAll(PDO::FETCH_ASSOC));
    } catch (PDOException $e) {
        error_log('ARDY WEB MEMORIA STORICO: ' . $e->getMessage());
        return [];
    }
}

} // end function guard
