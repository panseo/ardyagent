<?php
// -----------------------------------------------------------
// ARDY LAB — WhatsApp Webhook Endpoint
// Gestisce verifica Meta + messaggi in arrivo
// -----------------------------------------------------------

// Token di verifica (deve corrispondere a quello inserito su Meta)
define('WA_VERIFY_TOKEN', 'ardy_wa_verify_2026');

// ── VERIFICA WEBHOOK (GET) ──
// Meta invia una GET per verificare l'endpoint
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $mode      = $_GET['hub_mode'] ?? '';
    $token     = $_GET['hub_verify_token'] ?? '';
    $challenge = $_GET['hub_challenge'] ?? '';

    if ($mode === 'subscribe' && $token === WA_VERIFY_TOKEN) {
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
    $input = json_decode(file_get_contents('php://input'), true);

    // Log per debug
    $logFile = __DIR__ . '/ardy-wa-log.json';
    $logs = file_exists($logFile) ? json_decode(file_get_contents($logFile), true) : [];
    $logs[] = ['time' => date('Y-m-d H:i:s'), 'data' => $input];
    // Tieni solo gli ultimi 100 log
    if (count($logs) > 100) $logs = array_slice($logs, -100);
    file_put_contents($logFile, json_encode($logs, JSON_PRETTY_PRINT));

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
    $msgId      = $message['id'] ?? '';
    $timestamp  = $message['timestamp'] ?? '';
    $contactName = $contact['profile']['name'] ?? 'Sconosciuto';
    $phoneNumId  = $metadata['phone_number_id'] ?? '';

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
