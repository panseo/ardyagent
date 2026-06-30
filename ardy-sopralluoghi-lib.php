<?php
// -----------------------------------------------------------
// ARDY LAB — Libreria sopralluoghi (logica condivisa)
// Un cliente può avere N sopralluoghi (1°, 2°, sopralluogo colori, ecc.), ognuno
// con la sua data/ora e il suo evento Google Calendar. Questa libreria contiene
// le operazioni pure (lista / salva / elimina / allineamento), usate sia
// dall'endpoint dashboard (ardy-sopralluoghi-api.php) sia da Sole su WhatsApp
// (ardy-wa-agent.php), così la logica (calendario + mirror) è UNA SOLA.
//
// "Mirror": dopo ogni modifica riallinea clienti.sopralluogo_at/gcal_event_id
// alla visita "prossima", così i flussi che leggono ancora i vecchi campi
// (es. tool cliente su WhatsApp) restano coerenti.
// -----------------------------------------------------------

require_once __DIR__ . '/ardy-gcal.php';

if (!function_exists('sopr_norm_data')) {

// "YYYY-MM-DDTHH:MM" / "YYYY-MM-DD HH:MM[:SS]" / ISO 8601 → "YYYY-MM-DD HH:MM:SS".
// Ritorna null se non è una data/ora valida.
function sopr_norm_data($raw): ?string {
    $s = trim((string) $raw);
    if ($s === '') return null;
    // ISO 8601 con timezone (es. da Sole: 2026-06-23T10:00:00+02:00)
    try {
        if (preg_match('/[T ]\d{2}:\d{2}.*[+\-]\d{2}:?\d{2}$/', $s) || preg_match('/Z$/', $s)) {
            $dt = new DateTime($s);
            $dt->setTimezone(new DateTimeZone('Europe/Rome'));
            return $dt->format('Y-m-d H:i:s');
        }
    } catch (Exception $e) { /* prova i formati semplici sotto */ }
    $s = str_replace('T', ' ', $s);
    if (strlen($s) === 16) $s .= ':00';            // manca il campo secondi
    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $s);
    return $dt ? $dt->format('Y-m-d H:i:s') : null;
}

// Etichetta predefinita per tipo (usata anche per non ripeterla nel titolo evento).
function sopr_tipo_label(string $tipo): string {
    return $tipo === 'ritiro' ? 'Ritiro' : 'Sopralluogo';
}

// Titolo evento calendario coerente con quelli creati altrove. Il tipo decide
// "Sopralluogo Ardy Lab" vs "Ritiro Ardy Lab"; l'etichetta libera (se diversa dal
// default del tipo) finisce tra parentesi.
function sopr_summary(array $cli, string $etichetta, string $tipo = 'sopralluogo'): string {
    $nm    = trim(($cli['nome'] ?? '') . ' ' . ($cli['cognome'] ?? ''));
    $label = sopr_tipo_label($tipo);
    $et = ($etichetta !== '' && strcasecmp($etichetta, $label) !== 0) ? ' (' . $etichetta . ')' : '';
    return $label . ' Ardy Lab' . $et
         . (!empty($cli['zona']) ? ' — ' . $cli['zona'] : '')
         . ($nm !== '' ? ' / ' . $nm : '');
}

// Dati del cliente utili per l'evento.
function sopr_get_cliente(PDO $db, string $sid): array {
    $c = $db->prepare("SELECT nome, cognome, zona, telefono, email FROM clienti WHERE session_id = :sid LIMIT 1");
    $c->execute([':sid' => $sid]);
    return $c->fetch(PDO::FETCH_ASSOC) ?: [];
}

// Riallinea clienti.sopralluogo_at/gcal_event_id alla visita "prossima" (la prima
// futura; se non ce ne sono, la più recente passata; se nessuna riga, li azzera).
function sopr_mirror(PDO $db, string $sid): void {
    $st = $db->prepare(
        "SELECT data_ora, gcal_event_id FROM sopralluoghi
          WHERE session_id = :sid
          ORDER BY (data_ora >= NOW()) DESC,
                   CASE WHEN data_ora >= NOW() THEN data_ora END ASC,
                   data_ora DESC
          LIMIT 1"
    );
    $st->execute([':sid' => $sid]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $db->prepare("UPDATE clienti SET sopralluogo_at = :d, gcal_event_id = :e, updated_at = NOW() WHERE session_id = :sid")
           ->execute([':d' => $row['data_ora'], ':e' => $row['gcal_event_id'], ':sid' => $sid]);
    } else {
        $db->prepare("UPDATE clienti SET sopralluogo_at = NULL, gcal_event_id = NULL, updated_at = NOW() WHERE session_id = :sid")
           ->execute([':sid' => $sid]);
    }
}

// Riconciliazione "pigra": se il cliente ha un sopralluogo nei vecchi campi (es.
// appena fissato dal canale cliente su WhatsApp) non ancora presente come riga,
// lo inserisce — così quella prenotazione compare nella lista.
function sopr_reconcile(PDO $db, string $sid): void {
    $c = $db->prepare("SELECT sopralluogo_at, gcal_event_id FROM clienti WHERE session_id = :sid LIMIT 1");
    $c->execute([':sid' => $sid]);
    $cli = $c->fetch(PDO::FETCH_ASSOC);
    if (!$cli || empty($cli['sopralluogo_at'])) return;
    if (!empty($cli['gcal_event_id'])) {
        $chk = $db->prepare("SELECT id FROM sopralluoghi WHERE session_id = :sid AND gcal_event_id = :e LIMIT 1");
        $chk->execute([':sid' => $sid, ':e' => $cli['gcal_event_id']]);
    } else {
        $chk = $db->prepare("SELECT id FROM sopralluoghi WHERE session_id = :sid AND data_ora = :d LIMIT 1");
        $chk->execute([':sid' => $sid, ':d' => $cli['sopralluogo_at']]);
    }
    if (!$chk->fetch()) {
        // Il mirror legacy (clienti.sopralluogo_at) è sempre un sopralluogo.
        $db->prepare("INSERT INTO sopralluoghi (session_id, tipo, data_ora, etichetta, gcal_event_id, created_at, updated_at)
                      VALUES (:sid, 'sopralluogo', :d, 'Sopralluogo', :e, NOW(), NOW())")
           ->execute([':sid' => $sid, ':d' => $cli['sopralluogo_at'], ':e' => $cli['gcal_event_id'] ?: null]);
    }
}

// Lista dei sopralluoghi/ritiri del cliente (riconcilia prima, poi ordina per data).
function sopr_list(PDO $db, string $sid): array {
    sopr_reconcile($db, $sid);
    $st = $db->prepare("SELECT id, tipo, data_ora, etichetta, note, gcal_event_id
                          FROM sopralluoghi WHERE session_id = :sid ORDER BY data_ora ASC");
    $st->execute([':sid' => $sid]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

// Upsert di un sopralluogo. $id=0 → nuovo; $id>0 → aggiorna/sposta. Cura l'evento
// Google Calendar e riallinea il mirror. Ritorna ['id'=>int, 'gcal_event_id'=>?]
// oppure null se $id>0 ma la riga non esiste per quel cliente.
function sopr_salva(PDO $db, string $sid, int $id, string $dataOraNorm, string $etichetta, string $note, array $cli, string $tipo = 'sopralluogo'): ?array {
    $tipo      = in_array($tipo, ['sopralluogo', 'ritiro'], true) ? $tipo : 'sopralluogo';
    $etichetta = $etichetta !== '' ? $etichetta : sopr_tipo_label($tipo);
    $dt        = new DateTime($dataOraNorm);
    $dateStr   = $dt->format('Y-m-d');
    $timeStr   = $dt->format('H:i');
    $summary   = sopr_summary($cli, $etichetta, $tipo);
    $eid       = null;

    if ($id > 0) {
        $st = $db->prepare("SELECT gcal_event_id FROM sopralluoghi WHERE id = :id AND session_id = :sid LIMIT 1");
        $st->execute([':id' => $id, ':sid' => $sid]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $eid = $row['gcal_event_id'] ?? null;
        try {
            if (!empty($eid)) {
                // Passo anche il titolo: se cambia il tipo (sopralluogo↔ritiro) o
                // l'etichetta, l'evento in calendario si aggiorna di conseguenza.
                gcal_update_event($eid, $dateStr, $timeStr, 2, $summary);
            } else {
                $ev = gcal_create_event($dateStr, $timeStr, $summary, (string) ($cli['telefono'] ?? ''), (string) ($cli['email'] ?? ''), '', '');
                if (is_array($ev) && !empty($ev['id'])) $eid = $ev['id'];
            }
        } catch (Throwable $e) { error_log('ARDY SOPR LIB salva gcal(upd): ' . $e->getMessage()); }
        $db->prepare("UPDATE sopralluoghi SET tipo = :tp, data_ora = :d, etichetta = :et, note = :nt, gcal_event_id = :e, updated_at = NOW()
                       WHERE id = :id AND session_id = :sid")
           ->execute([':tp' => $tipo, ':d' => $dataOraNorm, ':et' => $etichetta, ':nt' => ($note !== '' ? $note : null), ':e' => $eid, ':id' => $id, ':sid' => $sid]);
    } else {
        try {
            $ev = gcal_create_event($dateStr, $timeStr, $summary, (string) ($cli['telefono'] ?? ''), (string) ($cli['email'] ?? ''), '', '');
            if (is_array($ev) && !empty($ev['id'])) $eid = $ev['id'];
        } catch (Throwable $e) { error_log('ARDY SOPR LIB salva gcal(new): ' . $e->getMessage()); }
        $db->prepare("INSERT INTO sopralluoghi (session_id, tipo, data_ora, etichetta, note, gcal_event_id, created_at, updated_at)
                      VALUES (:sid, :tp, :d, :et, :nt, :e, NOW(), NOW())")
           ->execute([':sid' => $sid, ':tp' => $tipo, ':d' => $dataOraNorm, ':et' => $etichetta, ':nt' => ($note !== '' ? $note : null), ':e' => $eid]);
        $id = (int) $db->lastInsertId();
    }

    sopr_mirror($db, $sid);
    return ['id' => $id, 'gcal_event_id' => $eid];
}

// Elimina un sopralluogo (+ cancella l'evento calendario) e riallinea il mirror.
function sopr_elimina(PDO $db, string $sid, int $id): bool {
    $st = $db->prepare("SELECT gcal_event_id FROM sopralluoghi WHERE id = :id AND session_id = :sid LIMIT 1");
    $st->execute([':id' => $id, ':sid' => $sid]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return false;
    if (!empty($row['gcal_event_id'])) {
        try { gcal_delete_event($row['gcal_event_id']); }
        catch (Throwable $e) { error_log('ARDY SOPR LIB delete gcal: ' . $e->getMessage()); }
    }
    $db->prepare("DELETE FROM sopralluoghi WHERE id = :id AND session_id = :sid")->execute([':id' => $id, ':sid' => $sid]);
    sopr_mirror($db, $sid);
    return true;
}

} // function_exists guard
