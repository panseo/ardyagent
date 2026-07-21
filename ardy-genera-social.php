<?php
// -----------------------------------------------------------
// ARDY LAB — Genera/recupera la caption SOCIAL di una fase
// -----------------------------------------------------------
// La caption social (FB/IG, e su Google senza hashtag) è DIVERSA dal
// testo del sito cliente (testo_generato, rivolto al cliente: "la sua
// cucina…"). Le fasi pubblicate PRIMA di questa modifica non hanno la
// caption salvata: questo endpoint la genera al volo dalle note della
// fase e la mette in cache in fasi.testo_social, così la si genera una
// volta sola.
//
// POST JSON { session_id, fase_id } → { success, testo_social }
// Se la fase ha già testo_social, lo ritorna senza rigenerare (a meno di
// force=true).
// -----------------------------------------------------------

require_once __DIR__ . '/ardy-config.php';
require_once __DIR__ . '/ardy-db.php';
require_once __DIR__ . '/ardy-auth.php';
ardyRequireAuth();

header('Content-Type: application/json');

$in       = json_decode(file_get_contents('php://input'), true) ?: [];
$sessionId = trim((string)($in['session_id'] ?? ''));
$faseId    = (int)($in['fase_id'] ?? 0);
$force     = !empty($in['force']);

if ($sessionId === '' || $faseId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'session_id e fase_id obbligatori']);
    exit;
}

try {
    $db = ardyDB();
    $stmt = $db->prepare("SELECT id, fase_nome, fase_tipo, testo_breve, testo_generato, testo_social
                          FROM fasi WHERE id = ? AND session_id = ?");
    $stmt->execute([$faseId, $sessionId]);
    $fase = $stmt->fetch();

    if (!$fase) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Fase non trovata']);
        exit;
    }

    // Le comunicazioni straordinarie non vanno sui social.
    if (($fase['fase_tipo'] ?? '') === 'comunicazione') {
        echo json_encode(['success' => false, 'error' => 'Le comunicazioni non vanno sui social']);
        exit;
    }

    // Già in cache: ritorna senza spendere una chiamata AI.
    if (!$force && !empty(trim((string)($fase['testo_social'] ?? '')))) {
        echo json_encode(['success' => true, 'testo_social' => $fase['testo_social'], 'cached' => true]);
        exit;
    }

    // Materia prima per il prompt: le note brevi; se assenti, il testo pubblicato.
    $note = trim((string)($fase['testo_breve'] ?? '')) !== ''
        ? $fase['testo_breve']
        : (string)($fase['testo_generato'] ?? '');

    $testoSocial = generaCaptionSocial($note, (string)($fase['fase_nome'] ?? ''));
    if ($testoSocial === '') {
        http_response_code(502);
        echo json_encode(['success' => false, 'error' => 'Generazione non riuscita']);
        exit;
    }

    // Cache in DB così non si rigenera più.
    try {
        $db->prepare("UPDATE fasi SET testo_social = ? WHERE id = ? AND session_id = ?")
           ->execute([$testoSocial, $faseId, $sessionId]);
    } catch (PDOException $e) { /* best effort: ritorna comunque */ }

    echo json_encode(['success' => true, 'testo_social' => $testoSocial, 'cached' => false]);

} catch (PDOException $e) {
    error_log('ARDY GENERA SOCIAL ERROR: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Errore interno']);
}

// -----------------------------------------------------------
// Caption Instagram/Facebook (stesso prompt di generaTestoSocial in
// ardy-pubblica-lavorazione.php — tenuto allineato). Tono brand/pubblico,
// NON rivolto al cliente. Su Google gli hashtag vengono tolti lato client.
// -----------------------------------------------------------
function generaCaptionSocial(string $noteBrevi, string $faseNome): string {
    $prompt = "Scrivi un post per Instagram/Facebook per Ardy Lab, bottega di restauro mobili a Roma.

Fase: $faseNome
Note: $noteBrevi

Regole:
- Max 80 parole
- Tono autentico, artigianale, visuale
- Inizia con una frase d'impatto sulla lavorazione
- Chiudi con call to action verso ardy-lab.it
- Aggiungi 8-10 hashtag pertinenti su una riga separata
- Usa max 2 emoji nel testo
- NON usare elenchi puntati
- NON rivolgerti al cliente (niente \"lei\"/\"la sua\"): è un post pubblico di brand";

    $payload = json_encode([
        'model'      => 'claude-sonnet-4-6',
        'max_tokens' => 400,
        'messages'   => [['role' => 'user', 'content' => $prompt]],
    ]);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt($ch, CURLOPT_POST,           true);
    curl_setopt($ch, CURLOPT_POSTFIELDS,     $payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT,        60);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'x-api-key: ' . ARDY_API_KEY,
        'anthropic-version: 2023-06-01',
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($res, true);
    return trim((string)($data['content'][0]['text'] ?? ''));
}
