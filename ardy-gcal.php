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
function gcal_get_free_slots($daysAhead = 14, $startHour = 9, $endHour = 18, $fromDate = null) {
    global $GCAL_CALENDAR_ID;

    $accessToken = gcal_get_access_token();
    if (!$accessToken) {
        error_log('ARDY GCAL: no access token — slots null');
        return null;
    }

    $now     = new DateTime('now', new DateTimeZone('Europe/Rome'));
    $startDt = $fromDate instanceof DateTime ? $fromDate : $now;
    $timeMin = $startDt->format(DateTime::RFC3339);
    $maxDate = clone $startDt;
    $maxDate->modify("+{$daysAhead} days");
    $timeMax = $maxDate->format(DateTime::RFC3339);

    $url = 'https://www.googleapis.com/calendar/v3/calendars/' .
           urlencode($GCAL_CALENDAR_ID) .
           '/events?timeMin=' . urlencode($timeMin) .
           '&timeMax=' . urlencode($timeMax) .
           '&singleEvents=true&orderBy=startTime';

    error_log('ARDY GCAL: chiamo API — ' . $url);

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

    error_log('ARDY GCAL: HTTP ' . $httpCode . ' — risposta: ' . substr($response, 0, 300));

    $data   = json_decode($response, true);
    $events = $data['items'] ?? [];

    error_log('ARDY GCAL: ' . count($events) . ' eventi trovati');

    // Costruisci mappa slot occupati
    $busy = [];
    foreach ($events as $event) {
        $start = $event['start']['dateTime'] ?? ($event['start']['date'] ?? null);
        $end   = $event['end']['dateTime']   ?? ($event['end']['date']   ?? null);
        if (!$start || !$end) continue;
        $day = (new DateTime($start))->format('Y-m-d');
        $busy[$day][] = [
            'start' => strtotime($start),
            'end'   => strtotime($end)
        ];
    }

    // Genera slot liberi
    $freeSlots = [];
    $current   = clone $startDt;
    if ($startDt->format('Y-m-d') > $now->format('Y-m-d')) {
        $current->setTime(0, 0, 0);
    } else {
        $current->modify('+1 day');
        $current->setTime(0, 0, 0);
    }

    $giorni = ['Monday'=>'Lunedì','Tuesday'=>'Martedì','Wednesday'=>'Mercoledì',
               'Thursday'=>'Giovedì','Friday'=>'Venerdì','Saturday'=>'Sabato','Sunday'=>'Domenica'];
    $mesi   = ['January'=>'gennaio','February'=>'febbraio','March'=>'marzo','April'=>'aprile',
               'May'=>'maggio','June'=>'giugno','July'=>'luglio','August'=>'agosto',
               'September'=>'settembre','October'=>'ottobre','November'=>'novembre','December'=>'dicembre'];

    for ($i = 0; $i < ($daysAhead * 2); $i++) {
        if (count($freeSlots) >= 5) break;

        $dayOfWeek = (int)$current->format('N');
        if ($dayOfWeek >= 6) {
            $current->modify('+1 day');
            continue;
        }

        $dayStr  = $current->format('Y-m-d');
        $dayBusy = $busy[$dayStr] ?? [];
        $eng     = $current->format('l d F Y');
        preg_match('/(\w+) (\d+) (\w+) (\d+)/', $eng, $m);
        $dayLabel = ($giorni[$m[1]] ?? $m[1]) . ' ' . $m[2] . ' ' . ($mesi[$m[3]] ?? $m[3]) . ' ' . $m[4];

        $candidateHours = [9, 11, 14, 16];
        $daySlots = [];

        foreach ($candidateHours as $hour) {
            $slotStart = strtotime($dayStr . ' ' . sprintf('%02d:00:00', $hour));
            $slotEnd   = $slotStart + 7200;
            $free      = true;
            foreach ($dayBusy as $b) {
                if ($slotStart < $b['end'] && $slotEnd > $b['start']) {
                    $free = false;
                    break;
                }
            }
            if ($free && $slotStart > time()) {
                $daySlots[] = sprintf('%02d:00–%02d:00', $hour, $hour + 2);
            }
        }

        if (!empty($daySlots)) {
            $freeSlots[] = [
                'date'  => $dayStr,
                'label' => ucfirst($dayLabel),
                'slots' => $daySlots
            ];
        }

        $current->modify('+1 day');
    }

    error_log('ARDY GCAL: ' . count($freeSlots) . ' giorni con slot liberi trovati');
    return $freeSlots;
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
function gcal_create_event($date, $startTime, $clientName, $clientPhone, $clientEmail, $address, $notes = '') {
    global $GCAL_CALENDAR_ID;

    $accessToken = gcal_get_access_token();
    if (!$accessToken) return false;

    $startDt = new DateTime($date . ' ' . $startTime, new DateTimeZone('Europe/Rome'));
    $endDt   = clone $startDt;
    $endDt->modify('+2 hours');

    $description  = "📋 Sopralluogo Ardy Lab\n\n";
    $description .= "Cliente: $clientName\n";
    if ($clientPhone) $description .= "Telefono: $clientPhone\n";
    if ($clientEmail) $description .= "Email: $clientEmail\n";
    if ($notes)       $description .= "\nNote: $notes";

    $event = [
        'summary'     => "🏠 Sopralluogo — $clientName",
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
// Verifica se uno slot specifico è libero (per spostamenti/conferme)
// Ritorna true (libero), false (occupato) o null (impossibile verificare)
// -----------------------------------------------------------
function gcal_is_slot_free($date, $startTime, $durationHours = 2) {
    global $GCAL_CALENDAR_ID;

    $accessToken = gcal_get_access_token();
    if (!$accessToken) return null;

    $startDt = new DateTime($date . ' ' . $startTime, new DateTimeZone('Europe/Rome'));
    $endDt   = clone $startDt;
    $endDt->modify("+{$durationHours} hours");

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
    curl_close($ch);
    if ($err) { error_log('ARDY GCAL SLOTCHECK ERROR: ' . $err); return null; }

    $events   = json_decode($response, true)['items'] ?? [];
    $slotS    = $startDt->getTimestamp();
    $slotE    = $endDt->getTimestamp();
    foreach ($events as $event) {
        if (($event['status'] ?? '') === 'cancelled') continue;
        $s = $event['start']['dateTime'] ?? ($event['start']['date'] ?? null);
        $e = $event['end']['dateTime']   ?? ($event['end']['date']   ?? null);
        if (!$s || !$e) continue;
        if ($slotS < strtotime($e) && $slotE > strtotime($s)) return false; // sovrapposizione
    }
    return true;
}

// -----------------------------------------------------------
// Sposta un appuntamento esistente (cambia data/ora dell'evento)
// -----------------------------------------------------------
function gcal_update_event($eventId, $date, $startTime, $durationHours = 2) {
    global $GCAL_CALENDAR_ID;

    $accessToken = gcal_get_access_token();
    if (!$accessToken || !$eventId) return false;

    $startDt = new DateTime($date . ' ' . $startTime, new DateTimeZone('Europe/Rome'));
    $endDt   = clone $startDt;
    $endDt->modify("+{$durationHours} hours");

    $patch = [
        'start' => ['dateTime' => $startDt->format(DateTime::RFC3339), 'timeZone' => 'Europe/Rome'],
        'end'   => ['dateTime' => $endDt->format(DateTime::RFC3339),   'timeZone' => 'Europe/Rome'],
    ];

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
