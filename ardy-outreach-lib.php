<?php
// -----------------------------------------------------------
// ARDY LAB — Outreach: helper condivisi
// Funzioni riusabili sull'outreach, fuori dall'endpoint API (che esegue il
// routing al solo include). Qui: aggiungere UN cliente CRM ai contatti outreach.
// -----------------------------------------------------------

if (!function_exists('ardy_outreach_aggiungi_cliente')) {

/**
 * Aggiunge un singolo cliente CRM ai contatti outreach (categoria "clienti").
 * Pensata per l'aggancio automatico quando un cliente "diventa reale" (Acconto):
 * comunicazione di servizio / riattivazione, NON cold-marketing → stato 'cliente'
 * (tenuto distinto dai lead freddi 'da_contattare', che le campagne targetizzano).
 *
 * Idempotente: dedup per email e per nome contro l'outreach esistente, come
 * l'import manuale. Richiede un'email (come l'import manuale).
 *
 * @return array{added:bool, reason?:string} esito (best-effort, non lancia).
 */
function ardy_outreach_aggiungi_cliente(PDO $db, string $sessionId, string $origine = 'Acconto'): array
{
    try {
        // Dati cliente (salta i cestinati). Fallback se manca deleted_at.
        try {
            $q = $db->prepare("SELECT nome, cognome, email, telefono, mobile, indirizzo, zona
                               FROM clienti WHERE session_id = :sid AND deleted_at IS NULL LIMIT 1");
            $q->execute([':sid' => $sessionId]);
            $r = $q->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $q = $db->prepare("SELECT nome, cognome, email, telefono, mobile, indirizzo, zona
                               FROM clienti WHERE session_id = :sid LIMIT 1");
            $q->execute([':sid' => $sessionId]);
            $r = $q->fetch(PDO::FETCH_ASSOC);
        }
        if (!$r) { return ['added' => false, 'reason' => 'cliente_non_trovato']; }

        $email = strtolower(trim((string) ($r['email'] ?? '')));
        if ($email === '') { return ['added' => false, 'reason' => 'no_email']; }

        $nomeFull = trim(trim((string) ($r['nome'] ?? '')) . ' ' . trim((string) ($r['cognome'] ?? '')));
        if ($nomeFull === '') { $nomeFull = $email; }
        $nomeL = strtolower($nomeFull);

        // Dedup per email o nome (stessa logica dell'import manuale).
        $chk = $db->prepare("SELECT 1 FROM outreach_contatti
                             WHERE LOWER(COALESCE(email,'')) = :em OR LOWER(nome) = :nm LIMIT 1");
        $chk->execute([':em' => $email, ':nm' => $nomeL]);
        if ($chk->fetchColumn()) { return ['added' => false, 'reason' => 'gia_presente']; }

        $tel = trim((string) (($r['telefono'] ?? '') ?: ($r['mobile'] ?? '')));
        $ind = trim((string) (($r['indirizzo'] ?? '') ?: ($r['zona'] ?? '')));

        $ins = $db->prepare("INSERT INTO outreach_contatti (nome, referente, categoria, email, telefono, indirizzo, stato, note)
                             VALUES (:nome, :ref, 'clienti', :email, :tel, :ind, 'cliente', :note)");
        $ins->execute([
            ':nome'  => $nomeFull,
            ':ref'   => $nomeFull,
            ':email' => $email,
            ':tel'   => $tel ?: null,
            ':ind'   => $ind ?: null,
            ':note'  => 'Auto da CRM (' . $origine . ')',
        ]);
        return ['added' => true];
    } catch (PDOException $e) {
        error_log('ARDY OUTREACH ADD CLIENTE: ' . $e->getMessage());
        return ['added' => false, 'reason' => 'errore'];
    }
}

} // function_exists
