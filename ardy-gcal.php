<?php
// -----------------------------------------------------------
// ARDY LAB — Google Calendar Helper
// -----------------------------------------------------------

$GCAL_CLIENT_ID     = ARDY_GCAL_CLIENT_ID;
$GCAL_CLIENT_SECRET = ARDY_GCAL_CLIENT_SECRET;
$GCAL_TOKEN_FILE    = __DIR__ . '/ardy-gcal-token.json';
$GCAL_CALENDAR_ID   = 'primary';

// -----------------------------------------------------------
// Ottieni un access_token valido (rinnova se scaduto)
// -----------------------------------------------------------
function gcal_get_access_token() {
    global $GCAL_CLIENT_ID, $GCAL_CLIENT_SECRET, $GCAL_TOKEN_FILE;

    if (!file_exists($GCAL_TOKEN_FILE)) {
        error_log('ARDY GCAL: token file non trovato');
        return null;
    }

    $token = json_decode(file_get_contents($GCAL_TOKEN_FILE), true);
    if (!$token) {
        error_log('ARDY GCAL: token file non valido');
        return null;
    }

    $expiresAt = ($token['created_at'] ?? 0) + ($token['expires_in'] ?? 3600) - 60;

    if (time() < $expiresAt && isset($token['access_token'])) {
        error_log('ARDY GCAL: token valido, uso esistente');
        return $token['access_token'];
    }

    if (!isset($token['refresh_token'])) {
        error_log('ARDY GCAL: no refresh_token');
        return null;
    }

    error_log('ARDY GCAL: rinnovo token...');
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt($ch, CURLOPT_POST,            true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER,  true);
    curl_setopt($ch, CURLOPT_TIMEOUT,         15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER,  true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'client_id'     => $GCAL_CLIENT_ID,
        'client_secret' => $GCAL_CLIENT_SECRET,
        'refresh_token' => $token['refresh_token'],
        'grant_type'    => 'refresh_token'
    ]));
    $response = curl_exec($ch);
    $err      = curl_error($ch);
    curl_close($ch);

    if ($err) {
        error_log('ARDY GCAL: errore rinnovo token: ' . $err);
        return null;
    }

    $newToken = json_decode($response, true);
    if (!isset($newToken['access_token'])) {
        error_log('ARDY GCAL: rinnovo fallito: ' . $response);
        return null;
    }

    $newToken['refresh_token'] = $token['refresh_token'];
    $newToken['created_at']    = time();
    file_put_contents($GCAL_TOKEN_FILE, json_encode($newToken, JSON_PRETTY_PRINT));
    error_log('ARDY GCAL: token rinnovato ok');

    return $newToken['access_token'];
}

// -----------------------------------------------------------
// Leggi gli slot liberi
// -----------------------------------------------------------
// $startHour/$endHour: fascia oraria in cui cercare (es. 17–18 per le chiamate).
// $durationMin: durata dell'appuntamento; gli slot restituiti sono ORARI ESATTI
//   di inizio–fine ("17:00–17:30"), così Sole non deve "inventare" un orario
//   dentro una finestra più larga (è così che nascevano le conferme sbagliate).
// Ritorna null se il calendario non è leggibile (token, rete, HTTP ≠ 200): MAI
// un array vuoto in quel caso, altrimenti un guasto sembra "nessuno slot libero".
function gcal_get_free_slots($daysAhead = 14, $startHour = 9, $endHour = 18, $fromDate = null, $durationMin = 120) {
    global $GCAL_CALENDAR_ID;

    $accessToken = gcal_get_access_token();
    if (!$accessToken) {
        error_log('ARDY GCAL: no access token — slots null');
        return null;
    }

    $tz      = new DateTimeZone('Europe/Rome');
    $now     = new DateTime('now', $tz);
    $startDt = $fromDate instanceof DateTime ? (clone $fromDate) : clone $now;
    $startDt->setTimezone($tz);
    $timeMin = $startDt->format(DateTime::RFC3339);
    $maxDate = clone $startDt;
    $maxDate->modify('+' . ($daysAhead * 2) . ' days'); // weekend inclusi nel conteggio del loop
    $timeMax = $maxDate->format(DateTime::RFC3339);

    $url = 'https://www.googleapis.com/calendar/v3/calendars/' .
           urlencode($GCAL_CALENDAR_ID) .
           '/events?timeMin=' . urlencode($timeMin) .
           '&timeMax=' . urlencode($timeMax) .
           '&singleEvents=true&orderBy=startTime&maxResults=2500';

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT,        30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER,     ['Authorization: Bearer ' . $accessToken]);
    $response = curl_exec($ch);
    $err      = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err) {
        error_log('ARDY GCAL: curl error: ' . $err);
        return null;
    }
    $data = json_decode((string) $response, true);
    if ((int) $httpCode !== 200 || !is_array($data) || !isset($data['items'])) {
        error_log('ARDY GCAL: lettura fallita HTTP ' . $httpCode . ' — ' . substr((string) $response, 0, 300));
        return null;
    }

    // Intervalli occupati (confronto per sovrapposizione: copre anche eventi su più giorni)
    $busy = [];
    foreach ($data['items'] as $event) {
        if (($event['status'] ?? '') === 'cancelled')        continue;
        if (($event['transparency'] ?? '') === 'transparent') continue; // segnato "disponibile"
        $start = $event['start']['dateTime'] ?? ($event['start']['date'] ?? null);
        $end   = $event['end']['dateTime']   ?? ($event['end']['date']   ?? null);
        if (!$start || !$end) continue;
        // Eventi "tutto il giorno": la data va letta nel fuso di Roma
        $s = isset($event['start']['dateTime']) ? strtotime($start) : (new DateTime($start, $tz))->getTimestamp();
        $e = isset($event['end']['dateTime'])   ? strtotime($end)   : (new DateTime($end, $tz))->getTimestamp();
        $busy[] = ['start' => $s, 'end' => $e];
    }

    // Orari di inizio candidati (in minuti dalla mezzanotte)
    $durationMin = max(15, min(480, (int) $durationMin));
    $startMin    = (int) round($startHour * 60);
    $endMin      = (int) round($endHour * 60);
    if ($durationMin === 120 && $startMin === 540 && $endMin === 1080) {
        $candidates = [540, 660, 840, 960];          // 9, 11, 14, 16: sopralluoghi classici
    } else {
        $step = min($durationMin, 60);
        $candidates = [];
        for ($m = $startMin; $m + $durationMin <= $endMin; $m += $step) $candidates[] = $m;
    }

    $freeSlots = [];
    $current   = clone $startDt;
    if ($startDt->format('Y-m-d') > $now->format('Y-m-d')) {
        $current->setTime(0, 0, 0);
    } else {
        $current->modify('+1 day');
        $current->setTime(0, 0, 0);
    }

    $giorni = [1=>'Lunedì',2=>'Martedì',3=>'Mercoledì',4=>'Giovedì',5=>'Venerdì',6=>'Sabato',7=>'Domenica'];
    $mesi   = [1=>'gennaio',2=>'febbraio',3=>'marzo',4=>'aprile',5=>'maggio',6=>'giugno',
               7=>'luglio',8=>'agosto',9=>'settembre',10=>'ottobre',11=>'novembre',12=>'dicembre'];
    $fmt = function ($m) { return sprintf('%02d:%02d', intdiv($m, 60), $m % 60); };

    for ($i = 0; $i < ($daysAhead * 2); $i++) {
        if (count($freeSlots) >= 5) break;

        $dayOfWeek = (int) $current->format('N');
        if ($dayOfWeek >= 6) {
            $current->modify('+1 day');
            continue;
        }

        $dayStr   = $current->format('Y-m-d');
        $dayLabel = $giorni[$dayOfWeek] . ' ' . $current->format('j') . ' ' . $mesi[(int) $current->format('n')] . ' ' . $current->format('Y');

        $daySlots = [];
        foreach ($candidates as $m) {
            $slotStart = (new DateTime($dayStr . ' ' . $fmt($m), $tz))->getTimestamp();
            $slotEnd   = $slotStart + $durationMin * 60;
            if ($slotStart <= time()) continue;
            $free = true;
            foreach ($busy as $b) {
                if ($slotStart < $b['end'] && $slotEnd > $b['start']) { $free = false; break; }
            }
            if ($free) $daySlots[] = $fmt($m) . '–' . $fmt($m + $durationMin);
        }

        if (!empty($daySlots)) {
            $freeSlots[] = [
                'date'  => $dayStr,
                'label' => $dayLabel,
                'slots' => $daySlots
            ];
        }

        $current->modify('+1 day');
    }

    error_log('ARDY GCAL: ' . count($freeSlots) . ' giorni con slot liberi trovati');
    return $freeSlots;
}

// -----------------------------------------------------------
// Appuntamenti futuri già creati da Sole per un cliente, cercati per le
// proprietà private messe sull'evento alla creazione (es. ardy_phone, ardy_session).
// È la "memoria" dell'appuntamento tra un messaggio e l'altro: la cronologia
// della chat conserva solo il testo, non le chiamate ai tool.
// Ritorna array di ['id','start'(DateTime),'end'(DateTime),'summary'] o null se illeggibile.
// -----------------------------------------------------------
function gcal_find_client_events(array $props) {
    global $GCAL_CALENDAR_ID;

    $props = array_filter($props, function ($v) { return (string) $v !== ''; });
    if (empty($props)) return [];

    $accessToken = gcal_get_access_token();
    if (!$accessToken) return null;

    $tz  = new DateTimeZone('Europe/Rome');
    $out = [];
    // Una query per proprietà (i filtri multipli di Google sono in AND)
    foreach ($props as $k => $v) {
        $url = 'https://www.googleapis.com/calendar/v3/calendars/' . urlencode($GCAL_CALENDAR_ID) .
               '/events?timeMin=' . urlencode((new DateTime('now', $tz))->format(DateTime::RFC3339)) .
               '&singleEvents=true&orderBy=startTime&maxResults=10' .
               '&privateExtendedProperty=' . urlencode($k . '=' . $v);
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT,        15);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER,     ['Authorization: Bearer ' . $accessToken]);
        $response = curl_exec($ch);
        $code     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err      = curl_error($ch);
        curl_close($ch);
        if ($err || $code !== 200) {
            error_log('ARDY GCAL FIND ERROR: HTTP ' . $code . ' ' . $err);
            return null;
        }
        foreach ((json_decode((string) $response, true)['items'] ?? []) as $ev) {
            if (($ev['status'] ?? '') === 'cancelled' || empty($ev['start']['dateTime'])) continue;
            $out[$ev['id']] = [
                'id'      => $ev['id'],
                'start'   => (new DateTime($ev['start']['dateTime']))->setTimezone($tz),
                'end'     => (new DateTime($ev['end']['dateTime'] ?? $ev['start']['dateTime']))->setTimezone($tz),
                'summary' => (string) ($ev['summary'] ?? ''),
            ];
        }
    }
    return array_values($out);
}

// Data leggibile per Sole: "giovedì 1 ottobre 2026 alle 17:00".
function gcal_label_ita(DateTime $dt) {
    $g = [1=>'lunedì',2=>'martedì',3=>'mercoledì',4=>'giovedì',5=>'venerdì',6=>'sabato',7=>'domenica'];
    $m = [1=>'gennaio',2=>'febbraio',3=>'marzo',4=>'aprile',5=>'maggio',6=>'giugno',
          7=>'luglio',8=>'agosto',9=>'settembre',10=>'ottobre',11=>'novembre',12=>'dicembre'];
    return $g[(int) $dt->format('N')] . ' ' . $dt->format('j') . ' ' . $m[(int) $dt->format('n')] . ' ' . $dt->format('Y') . ' alle ' . $dt->format('H:i');
}

// Testo "questo cliente ha già un appuntamento" da dare a Sole nei risultati dei tool.
function gcal_existing_note(array $events) {
    $righe = [];
    foreach ($events as $ev) $righe[] = '- ' . gcal_label_ita($ev['start']) . ' (' . $ev['summary'] . ')';
    return "ATTENZIONE — questo cliente ha GIÀ un appuntamento fissato da te sul calendario:\n" . implode("\n", $righe) .
           "\nÈ la verità del calendario: NON dire che quell'orario non è disponibile (è occupato proprio dal suo appuntamento) e NON crearne un altro. " .
           "Se il cliente sta solo confermando o ringraziando, conferma questo appuntamento. Se vuole davvero cambiarlo, usa lo strumento per spostarlo.";
}

// -----------------------------------------------------------
// Handler condiviso del tool `ottieni_disponibilita_calendario` (sito + WhatsApp).
// $ownProps: proprietà per riconoscere gli appuntamenti già fissati per questo cliente.
// -----------------------------------------------------------
function gcal_tool_disponibilita(array $input, array $ownProps = []) {
    $tz  = new DateTimeZone('Europe/Rome');
    $now = new DateTime('now', $tz);

    $from = null; $to = null;
    try { if (!empty($input['start'])) $from = (new DateTime($input['start']))->setTimezone($tz); } catch (Exception $e) { $from = null; }
    try { if (!empty($input['end']))   $to   = (new DateTime($input['end']))->setTimezone($tz);   } catch (Exception $e) { $to = null; }

    // Fascia oraria: quella richiesta (es. 17:00–18:00), altrimenti 9–18
    $startHour = 9; $endHour = 18;
    if ($from && $to) {
        $sh = (int) $from->format('G') + (int) $from->format('i') / 60;
        $eh = (int) $to->format('G')   + (int) $to->format('i') / 60;
        if ($eh > $sh && $sh >= 7 && $eh <= 21) { $startHour = $sh; $endHour = $eh; }
    }
    // Data mancante o nel passato: come prima, si riparte da +7 giorni (buffer sopralluoghi)
    if (!$from || $from < $now) { $from = (clone $now)->modify('+7 days'); $to = null; }
    $daysAhead = 14;
    if ($to && $to > $from) $daysAhead = max(1, min(30, (int) ceil(($to->getTimestamp() - $from->getTimestamp()) / 86400)));

    $durata = (int) ($input['durata_minuti'] ?? 0);
    if ($durata <= 0) $durata = min(120, (int) round(($endHour - $startHour) * 60));

    $slots = gcal_get_free_slots($daysAhead, $startHour, $endHour, $from, $durata);
    if ($slots === null) {
        return 'Errore calendario: in questo momento non riesco a leggere il calendario. NON proporre né confermare orari: ' .
               'scusati, di\' che verifichi e che Michela ricontatta il cliente per fissare.';
    }

    $existing = gcal_find_client_events($ownProps);
    $prefix   = !empty($existing) ? gcal_existing_note($existing) . "\n\n" : '';

    if (empty($slots)) return $prefix . 'Nessuno slot disponibile nel periodo richiesto.';
    return $prefix . "Slot liberi (durata {$durata} minuti). Proponi SOLO questi orari esatti, senza spostarli o accorciarli:\n" . json_encode($slots, JSON_UNESCAPED_UNICODE);
}

// -----------------------------------------------------------
// Handler condiviso del tool `fissa_appuntamento_calendario` (sito + WhatsApp).
// Ricontrolla che lo slot sia ancora libero, usa la durata vera (end − start)
// e marca l'evento con $ownProps per ritrovarlo nei messaggi successivi.
// Ritorna ['result' => testo per Sole, 'event' => evento creato|null, 'when' => DateTime|null].
// -----------------------------------------------------------
function gcal_tool_fissa(array $input, array $ownProps = []) {
    $tz = new DateTimeZone('Europe/Rome');
    if (empty($input['start'])) {
        return ['result' => 'Errore: data/ora mancante. Chiedi al cliente di confermare giorno e ora.', 'event' => null, 'when' => null];
    }
    $startDt = (new DateTime($input['start']))->setTimezone($tz);
    $durMin  = 120;
    if (!empty($input['end'])) {
        try {
            $endDt = (new DateTime($input['end']))->setTimezone($tz);
            $d = (int) round(($endDt->getTimestamp() - $startDt->getTimestamp()) / 60);
            if ($d >= 15 && $d <= 480) $durMin = $d;
        } catch (Exception $e) { /* durata di default */ }
    }
    if ($startDt <= new DateTime('now', $tz)) {
        return ['result' => 'Quell\'orario è nel passato: chiedi al cliente una data futura.', 'event' => null, 'when' => null];
    }

    $existing = gcal_find_client_events($ownProps);
    if (!empty($existing)) {
        return ['result' => gcal_existing_note($existing), 'event' => null, 'when' => null];
    }

    $dateStr = $startDt->format('Y-m-d');
    $timeStr = $startDt->format('H:i');
    $free = gcal_is_slot_free($dateStr, $timeStr, $durMin / 60);
    if ($free === null) {
        return ['result' => 'Errore calendario: non riesco a verificare quell\'orario. NON confermare nulla al cliente: scusati e di\' che Michela lo ricontatta per fissare.', 'event' => null, 'when' => null];
    }
    if ($free === false) {
        return ['result' => 'Quell\'orario (' . gcal_label_ita($startDt) . ') risulta OCCUPATO sul calendario: NON confermarlo. Riverifica con ottieni_disponibilita_calendario e proponi un altro slot.', 'event' => null, 'when' => null];
    }

    $summary = $input['summary']     ?? 'Sopralluogo Ardy Lab';
    $desc    = $input['description'] ?? '';
    $r = gcal_create_event($dateStr, $timeStr, $summary, '', '', '', $desc, null, 'Sopralluogo', $durMin, $ownProps);
    if (!$r) {
        return ['result' => 'Errore nella creazione dell\'appuntamento: NON è stato fissato. Non dire al cliente che è confermato; riprova una volta o di\' che Michela ricontatta.', 'event' => null, 'when' => null];
    }
    $endLbl = (clone $startDt)->modify("+{$durMin} minutes")->format('H:i');
    return [
        'result' => 'Appuntamento creato con successo nel calendario di Michela: ' . gcal_label_ita($startDt) . '–' . $endLbl . '. Conferma al cliente ESATTAMENTE questa data e ora.',
        'event'  => $r,
        'when'   => $startDt,
    ];
}

// -----------------------------------------------------------
// Elenca gli eventi in un intervallo (per il briefing della titolare).
// Ritorna array di ['summary','start','end','all_day','location'] ordinati per
// inizio, oppure null se non è possibile leggere il calendario.
// -----------------------------------------------------------
function gcal_list_events(DateTime $fromDt, DateTime $toDt) {
    global $GCAL_CALENDAR_ID;

    $accessToken = gcal_get_access_token();
    if (!$accessToken) return null;

    $url = 'https://www.googleapis.com/calendar/v3/calendars/' . urlencode($GCAL_CALENDAR_ID) .
           '/events?timeMin=' . urlencode($fromDt->format(DateTime::RFC3339)) .
           '&timeMax=' . urlencode($toDt->format(DateTime::RFC3339)) .
           '&singleEvents=true&orderBy=startTime';

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT,        20);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER,     ['Authorization: Bearer ' . $accessToken]);
    $response = curl_exec($ch);
    $err      = curl_error($ch);
    curl_close($ch);
    if ($err) { error_log('ARDY GCAL LIST ERROR: ' . $err); return null; }

    $items = json_decode($response, true)['items'] ?? [];
    $out = [];
    foreach ($items as $ev) {
        if (($ev['status'] ?? '') === 'cancelled') continue;
        $s = $ev['start']['dateTime'] ?? ($ev['start']['date'] ?? null);
        $e = $ev['end']['dateTime']   ?? ($ev['end']['date']   ?? null);
        if (!$s) continue;
        $out[] = [
            'summary'  => trim((string)($ev['summary'] ?? '(senza titolo)')),
            'start'    => strtotime($s),
            'end'      => $e ? strtotime($e) : null,
            'all_day'  => !isset($ev['start']['dateTime']), // evento "tutto il giorno"
            'location' => trim((string)($ev['location'] ?? '')),
        ];
    }
    return $out;
}

// -----------------------------------------------------------
// Crea un appuntamento nel calendario di Michela
// -----------------------------------------------------------
// $summary: titolo esplicito dell'evento (se null usa "🏠 $kind — $clientName").
// $kind: etichetta tipo appuntamento ('Sopralluogo' | 'Ritiro') per titolo/descrizione.
// $durationMin: durata in minuti. $privateProps: proprietà private sull'evento
// (es. ['ardy_phone' => '39…']) per ritrovarlo con gcal_find_client_events().
function gcal_create_event($date, $startTime, $clientName, $clientPhone, $clientEmail, $address, $notes = '', $summary = null, $kind = 'Sopralluogo', $durationMin = 120, array $privateProps = []) {
    global $GCAL_CALENDAR_ID;

    $accessToken = gcal_get_access_token();
    if (!$accessToken) return false;

    $startDt = new DateTime($date . ' ' . $startTime, new DateTimeZone('Europe/Rome'));
    $endDt   = clone $startDt;
    $endDt->modify('+' . (int) $durationMin . ' minutes');

    $description  = "📋 $kind Ardy Lab\n\n";
    $description .= "Cliente: $clientName\n";
    if ($clientPhone) $description .= "Telefono: $clientPhone\n";
    if ($clientEmail) $description .= "Email: $clientEmail\n";
    if ($notes)       $description .= "\nNote: $notes";

    $event = [
        'summary'     => ($summary !== null && $summary !== '') ? $summary : "🏠 $kind — $clientName",
        'description' => $description,
        'location'    => $address,
        'start'       => ['dateTime' => $startDt->format(DateTime::RFC3339), 'timeZone' => 'Europe/Rome'],
        'end'         => ['dateTime' => $endDt->format(DateTime::RFC3339),   'timeZone' => 'Europe/Rome'],
        'reminders'   => [
            'useDefault' => false,
            'overrides'  => [
                ['method' => 'email',  'minutes' => 1440],
                ['method' => 'popup',  'minutes' => 60]
            ]
        ]
    ];
    $privateProps = array_filter(array_map('strval', $privateProps), function ($v) { return $v !== ''; });
    if (!empty($privateProps)) $event['extendedProperties'] = ['private' => $privateProps];

    $url = 'https://www.googleapis.com/calendar/v3/calendars/' .
           urlencode($GCAL_CALENDAR_ID) . '/events';

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST,           true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT,        30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS,     json_encode($event));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json'
    ]);
    $response = curl_exec($ch);
    $err      = curl_error($ch);
    curl_close($ch);

    if ($err) {
        error_log('ARDY GCAL CREATE ERROR: ' . $err);
        return false;
    }

    $result = json_decode($response, true);
    return isset($result['id']) ? $result : false;
}

// -----------------------------------------------------------
// Evento "semplice": titolo + descrizione liberi, durata in ore. Per appuntamenti
// NON legati a un cliente (es. cose da fare dello staff con slot a calendario).
// A differenza di gcal_create_event() non forza testi "Sopralluogo". Ritorna
// l'array dell'evento creato (con 'id') o false.
function gcal_create_simple($date, $startTime, $summary, $description = '', $durationHours = 1) {
    global $GCAL_CALENDAR_ID;

    $accessToken = gcal_get_access_token();
    if (!$accessToken) return false;

    $startDt = new DateTime($date . ' ' . $startTime, new DateTimeZone('Europe/Rome'));
    $endDt   = clone $startDt;
    $endDt->modify("+{$durationHours} hours");

    $event = [
        'summary'     => $summary,
        'description' => $description,
        'start'       => ['dateTime' => $startDt->format(DateTime::RFC3339), 'timeZone' => 'Europe/Rome'],
        'end'         => ['dateTime' => $endDt->format(DateTime::RFC3339),   'timeZone' => 'Europe/Rome'],
        'reminders'   => [
            'useDefault' => false,
            'overrides'  => [
                ['method' => 'popup', 'minutes' => 60],
            ],
        ],
    ];

    $url = 'https://www.googleapis.com/calendar/v3/calendars/' .
           urlencode($GCAL_CALENDAR_ID) . '/events';

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST,           true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT,        30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS,     json_encode($event));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json'
    ]);
    $response = curl_exec($ch);
    $err      = curl_error($ch);
    curl_close($ch);

    if ($err) {
        error_log('ARDY GCAL CREATE SIMPLE ERROR: ' . $err);
        return false;
    }
    $result = json_decode($response, true);
    return isset($result['id']) ? $result : false;
}

// -----------------------------------------------------------
// Verifica se uno slot specifico è libero (per spostamenti/conferme)
// Ritorna true (libero), false (occupato) o null (impossibile verificare)
// -----------------------------------------------------------
// $excludeEventId: l'evento che si sta SPOSTANDO non conta come occupato — senza,
// spostarlo di mezz'ora (sovrapposto a sé stesso) risultava "orario occupato".
function gcal_is_slot_free($date, $startTime, $durationHours = 2, $excludeEventId = null) {
    global $GCAL_CALENDAR_ID;

    $accessToken = gcal_get_access_token();
    if (!$accessToken) return null;

    $startDt = new DateTime($date . ' ' . $startTime, new DateTimeZone('Europe/Rome'));
    $endDt   = clone $startDt;
    $endDt->modify('+' . (int) round($durationHours * 60) . ' minutes');

    // Allarga la finestra di query per intercettare eventi che iniziano prima
    $qMin = clone $startDt; $qMin->modify('-3 hours');
    $url  = 'https://www.googleapis.com/calendar/v3/calendars/' . urlencode($GCAL_CALENDAR_ID) .
            '/events?timeMin=' . urlencode($qMin->format(DateTime::RFC3339)) .
            '&timeMax=' . urlencode($endDt->format(DateTime::RFC3339)) .
            '&singleEvents=true&orderBy=startTime';

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT,        30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER,     ['Authorization: Bearer ' . $accessToken]);
    $response = curl_exec($ch);
    $err      = curl_error($ch);
    $code     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($err || $code !== 200) { error_log('ARDY GCAL SLOTCHECK ERROR: HTTP ' . $code . ' ' . $err); return null; }

    $data = json_decode((string) $response, true);
    if (!is_array($data) || !isset($data['items'])) return null;
    $events   = $data['items'];
    $slotS    = $startDt->getTimestamp();
    $slotE    = $endDt->getTimestamp();
    foreach ($events as $event) {
        if (($event['status'] ?? '') === 'cancelled') continue;
        if (($event['transparency'] ?? '') === 'transparent') continue;
        if ($excludeEventId && ($event['id'] ?? '') === $excludeEventId) continue;
        $s = $event['start']['dateTime'] ?? ($event['start']['date'] ?? null);
        $e = $event['end']['dateTime']   ?? ($event['end']['date']   ?? null);
        if (!$s || !$e) continue;
        if ($slotS < strtotime($e) && $slotE > strtotime($s)) return false; // sovrapposizione
    }
    return true;
}

// -----------------------------------------------------------
// Durata in minuti di un evento esistente, letta da Google Calendar.
// null se non leggibile (token, rete, evento sparito) o se è un evento
// "tutto il giorno": chi chiama ripiega sulla durata classica di 2 ore.
// -----------------------------------------------------------
function gcal_event_duration_min($eventId) {
    global $GCAL_CALENDAR_ID;

    $accessToken = gcal_get_access_token();
    if (!$accessToken || !$eventId) return null;

    $url = 'https://www.googleapis.com/calendar/v3/calendars/' .
           urlencode($GCAL_CALENDAR_ID) . '/events/' . urlencode($eventId);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT,        15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER,     ['Authorization: Bearer ' . $accessToken]);
    $response = curl_exec($ch);
    $code     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err      = curl_error($ch);
    curl_close($ch);
    if ($err || $code !== 200) {
        error_log('ARDY GCAL DURATA ERROR: HTTP ' . $code . ' ' . $err);
        return null;
    }
    $ev = json_decode((string) $response, true);
    if (empty($ev['start']['dateTime']) || empty($ev['end']['dateTime'])) return null;
    $min = (int) round((strtotime($ev['end']['dateTime']) - strtotime($ev['start']['dateTime'])) / 60);
    return ($min >= 15 && $min <= 480) ? $min : null;
}

// -----------------------------------------------------------
// Sposta un appuntamento esistente (cambia data/ora dell'evento)
// $durationHours: durata del nuovo orario; null = CONSERVA quella dell'evento
// (una chiamata da 30' spostata resta da 30', non diventa un sopralluogo da 2 ore).
// -----------------------------------------------------------
function gcal_update_event($eventId, $date, $startTime, $durationHours = 2, $summary = null) {
    global $GCAL_CALENDAR_ID;

    $accessToken = gcal_get_access_token();
    if (!$accessToken || !$eventId) return false;

    $durMin = $durationHours === null
        ? (gcal_event_duration_min($eventId) ?? 120)
        : (int) round($durationHours * 60);

    $startDt = new DateTime($date . ' ' . $startTime, new DateTimeZone('Europe/Rome'));
    $endDt   = clone $startDt;
    $endDt->modify('+' . $durMin . ' minutes');

    $patch = [
        'start' => ['dateTime' => $startDt->format(DateTime::RFC3339), 'timeZone' => 'Europe/Rome'],
        'end'   => ['dateTime' => $endDt->format(DateTime::RFC3339),   'timeZone' => 'Europe/Rome'],
    ];
    // Titolo aggiornato solo se passato (es. cambio tipo sopralluogo↔ritiro): un
    // PATCH senza 'summary' lascia intatto quello esistente.
    if ($summary !== null && $summary !== '') $patch['summary'] = $summary;

    $url = 'https://www.googleapis.com/calendar/v3/calendars/' .
           urlencode($GCAL_CALENDAR_ID) . '/events/' . urlencode($eventId);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST,  'PATCH');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT,        30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS,     json_encode($patch));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json'
    ]);
    $response = curl_exec($ch);
    $err      = curl_error($ch);
    curl_close($ch);

    if ($err) {
        error_log('ARDY GCAL UPDATE ERROR: ' . $err);
        return false;
    }
    $result = json_decode($response, true);
    return isset($result['id']) ? $result : false;
}

// Cancella un evento dal calendario. Ritorna true se cancellato (o se era già
// sparito: 404/410 li trattiamo come "ok, non c'è più").
function gcal_delete_event($eventId) {
    global $GCAL_CALENDAR_ID;

    $accessToken = gcal_get_access_token();
    if (!$accessToken || !$eventId) return false;

    $url = 'https://www.googleapis.com/calendar/v3/calendars/' .
           urlencode($GCAL_CALENDAR_ID) . '/events/' . urlencode($eventId);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST,  'DELETE');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT,        30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $accessToken]);
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) {
        error_log('ARDY GCAL DELETE ERROR: ' . $err);
        return false;
    }
    return in_array($code, [200, 204, 404, 410], true);
}
