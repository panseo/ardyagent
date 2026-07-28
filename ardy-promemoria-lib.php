<?php
// -----------------------------------------------------------
// ARDY LAB — Promemoria datati creati da Sole
// -----------------------------------------------------------
// Quando un cliente dice "il lavoro lo farò a settembre" o chiede di essere
// richiamato più avanti, la data NON deve restare in una frase di Sole ("ti
// faccio chiamare a fine agosto"): quella è una promessa che nessuno registra
// e che Michela scopre solo se rilegge la conversazione.
//
// Qui la data diventa una riga in `todo_datati` — la stessa tabella delle
// "cose da fare con data" della dashboard (ardy-todo-datati-api.php) — con il
// suo evento su Google Calendar. Così il promemoria ricompare da solo:
//   · nella lista "cose da fare" in dashboard;
//   · sul calendario di Michela nel giorno indicato;
//   · nel briefing del mattino, che legge gli eventi della settimana.
//
// Usata dai due canali dove Sole parla con i clienti: ardy-proxy.php (web) e
// ardy-wa-agent.php (WhatsApp).
// -----------------------------------------------------------

require_once __DIR__ . '/ardy-db.php';
require_once __DIR__ . '/ardy-gcal.php';

// Un promemoria non ha senso a distanza indefinita: oltre l'anno e mezzo è
// quasi certamente un errore di data del modello (anno sbagliato).
if (!defined('ARDY_PROMEMORIA_MAX_GIORNI')) define('ARDY_PROMEMORIA_MAX_GIORNI', 550);

/**
 * Crea un promemoria datato per Michela.
 *
 * @param string $testo  Cosa c'è da fare, con nome e telefono del cliente dentro.
 * @param string $data   Giorno in formato YYYY-MM-DD.
 * @param string $ora    Ora in formato HH:MM (default 09:00, inizio giornata).
 * @return array ['success' => bool, 'error' => string, 'id' => int, 'quando' => string, 'duplicato' => bool]
 */
function ardy_crea_promemoria(PDO $db, string $testo, string $data, string $ora = '09:00'): array {
    // Il testo finisce in un titolo di evento: niente a capo, e sta in VARCHAR(255).
    $testo = trim(preg_replace('/\s+/u', ' ', $testo));
    if ($testo === '') {
        return ['success' => false, 'error' => 'Scrivi cosa c\'è da ricordare a Michela (con nome e telefono del cliente).'];
    }
    if (mb_strlen($testo) > 240) $testo = mb_substr($testo, 0, 237) . '...';

    $data = trim($data);
    $ora  = trim($ora);

    // Tolleranza sul formato: gli altri tool di Sole usano l'ISO 8601, quindi
    // può arrivare "2026-08-28T09:00:00+02:00" invece del solo giorno. Se c'è
    // un orario dentro la data e il campo `ora` è vuoto, si tiene quello.
    if (preg_match('/^(\d{4}-\d{1,2}-\d{1,2})[T ](\d{1,2}:\d{2})/', $data, $m)) {
        $data = $m[1];
        if ($ora === '') $ora = $m[2];
    }
    // Mese/giorno a una cifra ("2026-9-5"): normalizzati, non rifiutati.
    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $data, $m)) {
        $data = sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
    }
    if ($ora === '') $ora = '09:00';
    if (preg_match('/^(\d{1,2}):(\d{2})$/', $ora, $m)) $ora = sprintf('%02d:%02d', $m[1], $m[2]);

    // createFromFormat accetta anche date impossibili (2026-02-31 → 3 marzo):
    // il confronto col format() ricostruito scarta quelle.
    $dt = DateTime::createFromFormat('Y-m-d H:i', $data . ' ' . $ora, new DateTimeZone('Europe/Rome'));
    if (!$dt || $dt->format('Y-m-d') !== $data) {
        return ['success' => false, 'error' => 'Data non valida: serve il formato YYYY-MM-DD (e l\'ora HH:MM).'];
    }

    $oggi = new DateTime('today', new DateTimeZone('Europe/Rome'));
    if ($dt < $oggi) {
        return ['success' => false, 'error' => 'Quella data è già passata: ricontrolla il giorno (e l\'anno) e riprova.'];
    }
    $limite = (clone $oggi)->modify('+' . ARDY_PROMEMORIA_MAX_GIORNI . ' days');
    if ($dt > $limite) {
        return ['success' => false, 'error' => 'Data troppo lontana nel tempo: ricontrolla l\'anno e riprova.'];
    }

    $dataOra = $dt->format('Y-m-d H:i:s');
    $quando  = $dt->format('d/m/Y') . ' alle ' . $dt->format('H:i');

    try {
        // Dedupe: se Sole richiama il tool nella stessa conversazione (o il
        // cliente ripete la richiesta) non si duplica la voce nello stesso giorno.
        $chk = $db->prepare("SELECT id FROM todo_datati WHERE testo = :t AND DATE(data_ora) = :d LIMIT 1");
        $chk->execute([':t' => $testo, ':d' => $dt->format('Y-m-d')]);
        $esistente = (int) ($chk->fetchColumn() ?: 0);
        if ($esistente > 0) {
            return ['success' => true, 'id' => $esistente, 'quando' => $quando, 'duplicato' => true];
        }

        // L'evento calendario è un di più: se Google non risponde il promemoria
        // resta comunque in dashboard, quindi non blocca il salvataggio.
        $eid = null;
        try {
            $ev = gcal_create_simple(
                $dt->format('Y-m-d'),
                $dt->format('H:i'),
                '✅ Da fare: ' . $testo,
                "Promemoria registrato da Sole durante una conversazione con il cliente.",
                1
            );
            if (is_array($ev) && !empty($ev['id'])) $eid = $ev['id'];
        } catch (Throwable $e) {
            error_log('ARDY PROMEMORIA gcal: ' . $e->getMessage());
        }

        $db->prepare("INSERT INTO todo_datati (testo, data_ora, gcal_event_id, created_at, updated_at)
                      VALUES (:t, :d, :e, NOW(), NOW())")
           ->execute([':t' => $testo, ':d' => $dataOra, ':e' => $eid]);

        return ['success' => true, 'id' => (int) $db->lastInsertId(), 'quando' => $quando, 'duplicato' => false];

    } catch (PDOException $e) {
        error_log('ARDY PROMEMORIA ERROR: ' . $e->getMessage());
        return ['success' => false, 'error' => 'Errore tecnico nel salvataggio del promemoria.'];
    }
}

/**
 * Definizione del tool `ricorda_a_michela`, identica sui due canali.
 */
function ardy_tool_ricorda_a_michela(): array {
    return [
        'name'        => 'ricorda_a_michela',
        'description' => 'Registra un promemoria CON DATA nelle "cose da fare" di Michela (compare in dashboard, sul suo calendario e nel briefing del mattino di quel giorno). Usalo quando il cliente colloca il lavoro o il ricontatto in un momento futuro ("se ne parla a settembre", "richiamatemi dopo le ferie", "decidiamo a fine mese") oppure quando ti chiede espressamente di essere richiamato in un certo periodo. È questo strumento a far succedere la cosa: senza, la richiesta si perde. Registralo e basta — al cliente non prometti mai una data precisa.',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'testo' => ['type' => 'string', 'description' => 'Cosa deve fare Michela, in una riga, con nome e telefono del cliente dentro. Es: "Richiamare Anna Bianchi 333 1234567 — restauro credenza, vuole partire a settembre (forbice 700-950 già data)"'],
                'data'  => ['type' => 'string', 'description' => 'Giorno del promemoria in formato YYYY-MM-DD. Scegli un giorno POCO PRIMA del momento indicato dal cliente, così Michela lo richiama in tempo: se dice "a settembre" metti fine agosto, se dice "dopo le ferie" fine agosto, se dice "a fine mese" qualche giorno prima. Deve essere una data futura.'],
                'ora'   => ['type' => 'string', 'description' => 'Ora in formato HH:MM. Facoltativa: se non serve un orario preciso lasciala vuota e vale le 09:00.']
            ],
            'required' => ['testo', 'data']
        ]
    ];
}

/**
 * Esecuzione del tool → stringa di `tool_result` per il modello.
 * Identica sui due canali, così Sole si comporta allo stesso modo ovunque.
 */
function ardy_esegui_ricorda_a_michela(array $input): string {
    $testo = (string) ($input['testo'] ?? '');
    $data  = (string) ($input['data']  ?? '');
    $ora   = (string) ($input['ora']   ?? '');

    try {
        $res = ardy_crea_promemoria(ardyDB(), $testo, $data, $ora);
    } catch (Throwable $e) {
        error_log('ARDY RICORDA A MICHELA ERROR: ' . $e->getMessage());
        return 'Errore tecnico nel salvataggio del promemoria. Non dire al cliente che lo hai registrato.';
    }

    if (empty($res['success'])) {
        return 'Promemoria NON registrato: ' . ($res['error'] ?? 'errore sconosciuto')
             . ' Correggi e riprova. Se non ci riesci, non promettere al cliente un ricontatto in quel periodo.';
    }
    return 'Promemoria registrato per il ' . $res['quando']
         . ': Michela lo ritrova nelle sue cose da fare, sul calendario e nel briefing di quel giorno.'
         . ' Al cliente NON dire la data: digli che Michela lo ricontatta al momento giusto.';
}
