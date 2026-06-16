<?php
// -----------------------------------------------------------
// ARDY LAB — Estrai fasi e dati economici da un preventivo PDF esterno
//
// Usato dal modale "Allega PDF": prima di allegare un preventivo fatto altrove
// (Canva, Word, altro gestionale...), un bottone manda lo stesso file a Claude
// (input documento, come ardy-import-scheda-pdf.php) per leggere oggetto,
// totale e le fasi/lavorazioni descritte, così Michela non deve ritrascriverle
// a mano. Estrazione pura: non scrive nulla nel DB.
//
//   POST (multipart): pdf (obbl.)
//   → { ok:true, oggetto, totale, fasi:[{nome,descrizione}, ...] }
//
// Protetto da Basic Auth (.htaccess), come ardy-allega-preventivo.php.
// -----------------------------------------------------------

require_once __DIR__ . '/ardy-config.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Metodo non consentito']);
    exit();
}

try {
    echo json_encode(estraiPreventivo());
} catch (Throwable $e) {
    error_log('ARDY ESTRAI PREVENTIVO ERROR: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Errore interno']);
}
exit();

// ═══════════════════════════════════════════════════════════════════════════

function estraiPreventivo(): array {
    if (empty($_FILES['pdf']['tmp_name']) || !is_uploaded_file($_FILES['pdf']['tmp_name'])
        || ($_FILES['pdf']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Allega un file PDF.'];
    }
    if (($_FILES['pdf']['size'] ?? 0) > 15 * 1024 * 1024) {
        return ['ok' => false, 'error' => 'PDF troppo grande (max 15 MB).'];
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    if ($finfo->file($_FILES['pdf']['tmp_name']) !== 'application/pdf') {
        return ['ok' => false, 'error' => 'Il file non è un PDF valido.'];
    }
    if (!defined('ARDY_API_KEY') || ARDY_API_KEY === '') {
        return ['ok' => false, 'error' => 'Chiave API non configurata sul server.'];
    }

    $b64 = base64_encode(file_get_contents($_FILES['pdf']['tmp_name']));

    $system = "Sei un estrattore di dati per Ardy Lab, bottega artigianale di restauro e laccatura mobili. "
        . "Ricevi un preventivo di lavoro in PDF, fatto con qualsiasi strumento (Canva, Word, altro gestionale). "
        . "Estrai: l'oggetto del lavoro, l'importo totale, e l'elenco delle fasi/lavorazioni descritte "
        . "(es. Sverniciatura, Carteggiatura, Laccatura, Restauro ferramenta, Trasporto). "
        . "Rispondi ESCLUSIVAMENTE con un oggetto JSON valido, senza testo prima o dopo, senza code fence. "
        . "Chiavi obbligatorie: oggetto (string, breve), totale (string col formato \"1450,00\", stringa vuota "
        . "se non c'è un totale chiaro), fasi (ARRAY di oggetti {\"nome\":..., \"descrizione\":...}, uno per "
        . "ogni voce/lavorazione distinta elencata nel preventivo; 'nome' breve, max 6 parole; 'descrizione' "
        . "il dettaglio della voce se presente, altrimenti stringa vuota). "
        . "Non inventare voci che non sono nel documento. Se non trovi nessuna fase, 'fasi' è un array vuoto.";

    $messages = [[
        'role' => 'user',
        'content' => [
            ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => $b64]],
            ['type' => 'text', 'text' => 'Estrai i dati di questo preventivo e restituisci solo il JSON.'],
        ],
    ]];

    $resp = callAnthropicDocEstrai($messages, $system, ARDY_API_KEY);
    if (!$resp['ok']) {
        return ['ok' => false, 'error' => $resp['error']];
    }

    $dati = parseJsonDaTestoEstrai($resp['text']);
    if ($dati === null) {
        return ['ok' => false, 'error' => 'Non sono riuscito a leggere i dati dal PDF. Controlla che contenga testo (non sia una scansione/immagine).'];
    }

    $fasi = [];
    if (!empty($dati['fasi']) && is_array($dati['fasi'])) {
        foreach ($dati['fasi'] as $f) {
            if (!is_array($f)) continue;
            $nome  = trim((string)($f['nome'] ?? ''));
            $descr = trim((string)($f['descrizione'] ?? ''));
            if ($nome === '' && $descr === '') continue;
            $fasi[] = ['nome' => $nome ?: $descr, 'descrizione' => $descr];
        }
    }

    return [
        'ok'      => true,
        'oggetto' => trim((string)($dati['oggetto'] ?? '')),
        'totale'  => trim((string)($dati['totale']  ?? '')),
        'fasi'    => $fasi,
    ];
}

// ─── helper ─────────────────────────────────────────────────────────────────

/** Chiama Anthropic con un documento PDF; ritorna ['ok'=>bool,'text'=>..,'error'=>..]. */
function callAnthropicDocEstrai(array $messages, string $system, string $apiKey): array {
    $payload = json_encode([
        'model'      => 'claude-sonnet-4-6',
        'max_tokens' => 1200,
        'system'     => $system,
        'messages'   => $messages,
    ]);
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
        ],
    ]);
    $response = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err) {
        error_log('ARDY ESTRAI PREVENTIVO CURL: ' . $err);
        return ['ok' => false, 'error' => 'Errore di rete con il servizio AI.'];
    }
    $data = json_decode($response, true);
    if (!is_array($data) || isset($data['error'])) {
        error_log('ARDY ESTRAI PREVENTIVO API: ' . substr((string)$response, 0, 500));
        return ['ok' => false, 'error' => 'Il servizio AI ha risposto con un errore.'];
    }
    $text = '';
    foreach ($data['content'] ?? [] as $block) {
        if (($block['type'] ?? '') === 'text') $text .= $block['text'];
    }
    return ['ok' => true, 'text' => $text];
}

/** Estrae il primo oggetto JSON da un testo (tollera code fence/testo extra). */
function parseJsonDaTestoEstrai(string $t): ?array {
    $t = trim($t);
    $t = preg_replace('/^```(?:json)?|```$/m', '', $t);
    $start = strpos($t, '{');
    $end   = strrpos($t, '}');
    if ($start === false || $end === false || $end <= $start) return null;
    $json = substr($t, $start, $end - $start + 1);
    $dati = json_decode($json, true);
    return is_array($dati) ? $dati : null;
}
