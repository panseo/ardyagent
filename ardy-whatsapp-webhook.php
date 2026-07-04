<?php
// -----------------------------------------------------------
// ARDY LAB — WhatsApp Webhook Endpoint
// Gestisce verifica Meta + messaggi in arrivo
// -----------------------------------------------------------

// Config (non versionata): WA_APP_SECRET (firma) e, idealmente, WA_VERIFY_TOKEN.
if (file_exists(__DIR__ . '/ardy-config.php')) { require_once __DIR__ . '/ardy-config.php'; }

// Token di verifica (deve corrispondere a quello inserito su Meta).
// Va definito in ardy-config.php: niente fallback hardcoded (sarebbe un valore
// pubblico nel repo, quindi indovinabile). Se manca è un errore di config.

// ── VERIFICA WEBHOOK (GET) ──
// Meta invia una GET per verificare l'endpoint
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $mode      = $_GET['hub_mode'] ?? '';
    $token     = $_GET['hub_verify_token'] ?? '';
    $challenge = $_GET['hub_challenge'] ?? '';

    if (!defined('WA_VERIFY_TOKEN') || WA_VERIFY_TOKEN === '') {
        error_log('ARDY WA WEBHOOK: WA_VERIFY_TOKEN non configurato in ardy-config.php');
        http_response_code(500);
        echo 'Configurazione mancante';
        exit();
    }

    if ($mode === 'subscribe' && hash_equals((string) WA_VERIFY_TOKEN, (string) $token)) {
        // Verifica superata — rispondi con il challenge
        http_response_code(200);
        echo $challenge;
        exit();
    } else {
        http_response_code(403);
        echo 'Verifica fallita';
        exit();
    }
}

// ── MESSAGGI IN ARRIVO (POST) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Leggi il corpo grezzo (max 1 MB) — serve sia per la firma sia per il parsing
    $rawBody = file_get_contents('php://input', false, null, 0, 1048576);

    // Verifica firma Meta (X-Hub-Signature-256) — OBBLIGATORIA.
    // Senza app secret l'endpoint accetterebbe payload arbitrari: chiunque
    // potrebbe iniettare messaggi WhatsApp falsi (loggati su disco e inoltrati a
    // n8n/CRM). Se il secret non è configurato è un errore di configurazione,
    // non una richiesta legittima: rifiuta.
    if (!defined('WA_APP_SECRET') || WA_APP_SECRET === '') {
        error_log('ARDY WA WEBHOOK: WA_APP_SECRET non configurato in ardy-config.php — POST rifiutato');
        http_response_code(500);
        echo 'Configurazione mancante';
        exit();
    }
    $sigHeader = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
    $expected  = 'sha256=' . hash_hmac('sha256', $rawBody, WA_APP_SECRET);
    if ($sigHeader === '' || !hash_equals($expected, $sigHeader)) {
        http_response_code(403);
        echo 'Firma non valida';
        exit();
    }

    $input = json_decode($rawBody, true);

    // Estrai il messaggio
    $entry = $input['entry'][0] ?? null;
    if (!$entry) { http_response_code(200); echo 'OK'; exit(); }

    $changes = $entry['changes'][0] ?? null;
    if (!$changes) { http_response_code(200); echo 'OK'; exit(); }

    $value = $changes['value'] ?? null;
    if (!$value || !isset($value['messages'])) {
        // Potrebbe essere una notifica di stato (delivered, read), ignoriamo
        http_response_code(200);
        echo 'OK';
        exit();
    }

    $message   = $value['messages'][0] ?? null;
    $contact   = $value['contacts'][0] ?? null;
    $metadata  = $value['metadata'] ?? null;

    if (!$message) { http_response_code(200); echo 'OK'; exit(); }

    $from       = $message['from'] ?? '';          // Numero mittente (senza +)
    $msgType    = $message['type'] ?? 'text';      // text, image, audio, etc.
    $msgText    = $message['text']['body'] ?? '';   // Testo del messaggio
    // Immagini: Meta NON manda i byte, solo un media id + eventuale didascalia.
    // Inoltriamo l'id a n8n → l'agente scaricherà la foto via Cloud API (WA_TOKEN).
    $mediaId    = '';
    $mediaMime  = '';
    $caption    = '';
    if ($msgType === 'image') {
        $mediaId   = $message['image']['id']        ?? '';
        $mediaMime = $message['image']['mime_type'] ?? '';
        $caption   = $message['image']['caption']   ?? '';
    }
    $msgId      = $message['id'] ?? '';
    $timestamp  = $message['timestamp'] ?? '';
    $contactName = $contact['profile']['name'] ?? 'Sconosciuto';
    $phoneNumId  = $metadata['phone_number_id'] ?? '';

    // Log di debug minimale: niente PII in chiaro. Mascheriamo il numero
    // (solo ultime 4 cifre), non salviamo nome né testo del messaggio (solo
    // tipo e lunghezza), retention ridotta. Serve a verificare il flusso, non
    // a conservare le conversazioni (quelle stanno nel DB wa_messaggi).
    $logFile = __DIR__ . '/ardy-wa-log.json';
    $logs = file_exists($logFile) ? (json_decode(file_get_contents($logFile), true) ?: []) : [];
    $logs[] = [
        'time'     => date('Y-m-d H:i:s'),
        'from'     => $from !== '' ? ('***' . substr($from, -4)) : '',
        'type'     => $msgType,
        'text_len' => mb_strlen($msgText),
        'msg_id'   => $msgId,
    ];
    if (count($logs) > 50) $logs = array_slice($logs, -50);
    file_put_contents($logFile, json_encode($logs, JSON_PRETTY_PRINT));

    // -----------------------------------------------------------
    // Persistenza IDEMPOTENTE del messaggio in arrivo in `wa_messaggi`.
    // Il webhook è l'unico punto da cui passano TUTTI i messaggi del cliente:
    // salvandoli qui, ogni risposta — comprese quelle a una notifica della
    // dashboard (inizio lavoro, fasi, ...) — compare in dash nelle Conversazioni,
    // a prescindere da cosa faccia n8n. Idempotente per msg_id (le riconsegne di
    // Meta non duplicano). Best-effort: nessun errore qui deve impedire il 200 a
    // Meta né l'inoltro a n8n sotto.
    // -----------------------------------------------------------
    if ($from !== '') {
        $waContent = '';
        switch ($msgType) {
            case 'text':     $waContent = (string) $msgText; break;
            case 'image':    $waContent = '📷 ' . ($caption !== '' ? $caption : 'Foto'); break;
            case 'audio':    $waContent = '🎤 Messaggio vocale'; break;
            case 'video':    $waContent = '🎥 Video' . ($caption !== '' ? ': ' . $caption : ''); break;
            case 'document': $waContent = '📎 Documento' . (($message['document']['filename'] ?? '') !== '' ? ': ' . $message['document']['filename'] : ''); break;
            case 'sticker':  $waContent = '🌟 Sticker'; break;
            case 'location': $waContent = '📍 Posizione'; break;
            default:
                // interactive (button/list reply), ecc.: prova a estrarre un testo leggibile
                $waContent = (string) ($message['button']['text']
                    ?? $message['interactive']['button_reply']['title']
                    ?? $message['interactive']['list_reply']['title']
                    ?? '');
                if ($waContent === '') $waContent = '💬 messaggio (' . $msgType . ')';
        }
        if ($waContent !== '') {
            try {
                require_once __DIR__ . '/ardy-db.php';
                require_once __DIR__ . '/ardy-wa-store.php';
                ardy_wa_save_message(ardyDB(), $from, 'user', $waContent, $msgId);
            } catch (Throwable $e) {
                error_log('ARDY WEBHOOK persist: ' . $e->getMessage());
            }
        }
    }

    // Inoltra a n8n per l'elaborazione
    $n8nWebhookUrl = 'https://n8n.ardy-lab.it/webhook/ardy-whatsapp';
    $n8nPayload = [
        'from'           => $from,
        'name'           => $contactName,
        'message'        => $msgText,
        'message_type'   => $msgType,
        'message_id'     => $msgId,
        'timestamp'      => $timestamp,
        'phone_number_id' => $phoneNumId,
        'media_id'       => $mediaId,
        'media_mime'     => $mediaMime,
        'caption'        => $caption,
    ];

    $ch = curl_init($n8nWebhookUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($n8nPayload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_exec($ch);
    curl_close($ch);

    // Meta richiede sempre 200
    http_response_code(200);
    echo 'OK';
    exit();
}

http_response_code(200);
echo 'OK';
