<?php
// -----------------------------------------------------------
// ARDY LAB — Persistenza messaggi WhatsApp (libreria condivisa, idempotente)
//
// Un solo punto per scrivere in `wa_messaggi`, usato da:
//   - ardy-whatsapp-webhook.php  → registra OGNI messaggio in arrivo del cliente
//     (anche le risposte alle notifiche della dashboard), a prescindere da n8n;
//   - ardy-wa-memoria.php        → salvataggi da n8n (coppia domanda/risposta);
//   - i punti della dashboard che INVIANO notifiche (inizio lavoro, fasi, grazie,
//     solleciti) → registrano il messaggio in USCITA come role='assistant', così
//     in dash si vede "notifica inviata → risposta del cliente".
//
// Schema: wa_messaggi(phone, role, content, wa_msg_id) — colonna e indice UNIQUE
// su wa_msg_id creati da ardy-migrate.php.
//
// Il file definisce SOLO funzioni: è sicuro includerlo ovunque (nessun effetto
// collaterale all'include, nessun output).
// -----------------------------------------------------------

if (!function_exists('ardy_wa_store_norm_phone')) {

    /** Normalizza il numero: solo cifre, ultime 12 (stesso formato usato da n8n). */
    function ardy_wa_store_norm_phone($p): string {
        $d = preg_replace('/\D+/', '', (string) $p);
        return substr($d, -12);
    }

    /**
     * Salva un messaggio in `wa_messaggi` in modo IDEMPOTENTE.
     *
     * - Con $msgId (id-messaggio Meta): dedup ESATTO tramite indice UNIQUE
     *   `wa_msg_id` → le riconsegne del webhook di Meta non creano doppioni.
     * - Senza $msgId (es. salvataggio da n8n): dedup MORBIDO per contenuto — salta
     *   se lo stesso numero ha già lo stesso testo/role negli ultimi 15 minuti →
     *   non duplica ciò che il webhook ha già registrato con l'id.
     *
     * Non lancia mai: in caso di errore logga e ritorna false. I chiamanti — il
     * webhook Meta in primis — non devono mai fallire per un problema di
     * persistenza (il 200 a Meta e l'inoltro a n8n vanno garantiti comunque).
     *
     * @return bool true se una riga è stata inserita, false se saltata/duplicata/errore.
     */
    function ardy_wa_save_message(PDO $db, string $phone, string $role, string $content, ?string $msgId = null): bool {
        $phone   = ardy_wa_store_norm_phone($phone);
        $content = trim($content);
        if ($phone === '' || $content === '') return false;
        if (mb_strlen($content) > 8000) $content = mb_substr($content, 0, 8000);
        $role  = ($role === 'assistant') ? 'assistant' : 'user';
        $msgId = ($msgId !== null && $msgId !== '') ? substr((string) $msgId, 0, 128) : null;

        try {
            if ($msgId !== null) {
                // Dedup esatto per id Meta. INSERT IGNORE: se wa_msg_id esiste già
                // non fa nulla; rowCount()>0 ⇒ inserita davvero adesso.
                $st = $db->prepare(
                    "INSERT IGNORE INTO wa_messaggi (phone, role, content, wa_msg_id)
                     VALUES (:p, :r, :c, :m)"
                );
                $st->execute([':p' => $phone, ':r' => $role, ':c' => $content, ':m' => $msgId]);
                return $st->rowCount() > 0;
            }

            // Nessun id: dedup morbido per contenuto (evita il doppione di n8n
            // rispetto a ciò che il webhook ha già salvato con l'id).
            $chk = $db->prepare(
                "SELECT 1 FROM wa_messaggi
                  WHERE RIGHT(phone, 9) = :p9 AND role = :r AND content = :c
                    AND created_at > (NOW() - INTERVAL 15 MINUTE)
                  LIMIT 1"
            );
            $chk->execute([':p9' => substr($phone, -9), ':r' => $role, ':c' => $content]);
            if ($chk->fetchColumn()) return false;

            $st = $db->prepare(
                "INSERT INTO wa_messaggi (phone, role, content) VALUES (:p, :r, :c)"
            );
            $st->execute([':p' => $phone, ':r' => $role, ':c' => $content]);
            return true;
        } catch (PDOException $e) {
            error_log('ARDY WA STORE: ' . $e->getMessage());
            return false;
        }
    }
}
