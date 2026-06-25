<?php
// -----------------------------------------------------------
// ARDY LAB — Dash Design: generazione AI dei contenuti di progetto
// L'autore scrive poche righe; Claude le riscrive in un POST professionale di
// brand (NON comunicazione a un cliente: è brand awareness verso appassionati e
// potenziali acquirenti). Il testo torna nella dash e si rivede prima di pubblicare.
// Stesso pattern Claude del resto del repo (ARDY_API_KEY, claude-sonnet-4-6).
// Vedi PIANO-DASH-DESIGN.md (binario racconto).
//
//   POST {mode:'genera_post', progetto_id, fase_nome?, bozza} → {success, testo}
//
// Protetto via .htaccess (Basic Auth) — elencato nel <FilesMatch>.
// -----------------------------------------------------------

require_once __DIR__ . '/ardy-config.php';
require_once __DIR__ . '/ardy-db.php';
require_once __DIR__ . '/ardy-sanitize.php';

header('Access-Control-Allow-Origin: https://ardyagent.ardy-lab.it');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST')   { http_response_code(405); echo json_encode(['success' => false, 'error' => 'Metodo non valido']); exit(); }

/** Chiamata a Claude (Messages API). Ritorna il testo, o '' su errore. */
function progettoAiClaude(string $system, string $userMsg): string {
    if (!defined('ARDY_API_KEY') || ARDY_API_KEY === '') return '';

    $payload = json_encode([
        'model'      => 'claude-sonnet-4-6',
        'max_tokens' => 900,
        'system'     => $system,
        'messages'   => [['role' => 'user', 'content' => $userMsg]],
    ]);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt($ch, CURLOPT_POST,           true);
    curl_setopt($ch, CURLOPT_POSTFIELDS,     $payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT,        90);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'x-api-key: ' . ARDY_API_KEY,
        'anthropic-version: 2023-06-01',
    ]);
    $res  = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($http !== 200) { error_log('ARDY PROGETTI AI: HTTP ' . $http . ' — ' . substr((string) $res, 0, 300)); return ''; }

    $data = json_decode($res, true);
    return trim((string) ($data['content'][0]['text'] ?? ''));
}

try {
    $in   = json_decode(file_get_contents('php://input'), true) ?: [];
    $mode = $in['mode'] ?? 'genera_post';

    if ($mode !== 'genera_post') { echo json_encode(['success' => false, 'error' => 'mode non valido']); exit(); }

    $progettoId = (int) ($in['progetto_id'] ?? 0);
    $faseNome   = trim((string) ($in['fase_nome'] ?? ''));
    $bozza      = trim((string) ($in['bozza'] ?? ''));
    if ($bozza === '') { echo json_encode(['success' => false, 'error' => 'Scrivi prima qualche riga da rielaborare']); exit(); }

    // Contesto dal progetto (per ancorare il post, senza inventare nulla).
    $titolo = $tipo = $descr = $materiali = '';
    if ($progettoId > 0) {
        $db = ardyDB();
        $st = $db->prepare("SELECT titolo, tipo, descrizione, materiali FROM progetti WHERE id = ? AND deleted_at IS NULL");
        $st->execute([$progettoId]);
        if ($p = $st->fetch()) {
            $titolo    = trim((string) ($p['titolo'] ?? ''));
            $tipo      = trim((string) ($p['tipo'] ?? ''));
            $descr     = trim((string) ($p['descrizione'] ?? ''));
            $materiali = trim((string) ($p['materiali'] ?? ''));
        }
    }

    $system =
        "Sei il copywriter del brand di design \"Ardy\": uno studio-bottega che progetta e autoproduce "
      . "oggetti di design — lampade, piccoli mobili, complementi d'arredo — con stampa 3D e restyling di pezzi. "
      . "Scrivi contenuti per i social e il sito con l'obiettivo di brand awareness e vendita. "
      . "IMPORTANTE: NON stai parlando con un cliente di un lavoro su commessa; è comunicazione di brand "
      . "rivolta ad appassionati di design e potenziali acquirenti. "
      . "Tono: professionale, curato, caldo ma non sdolcinato; italiano naturale e contemporaneo. "
      . "Sii fedele alla bozza dell'autore: NON inventare materiali, misure, prezzi, tempi o dettagli non presenti.";

    $ctx = '';
    if ($titolo    !== '') $ctx .= "Progetto: $titolo\n";
    if ($tipo      !== '') $ctx .= "Tipo: $tipo\n";
    if ($faseNome  !== '') $ctx .= "Fase di lavorazione: $faseNome\n";
    if ($descr     !== '') $ctx .= "Concept del progetto: $descr\n";
    if ($materiali !== '') $ctx .= "Materiali dichiarati: $materiali\n";

    $userMsg =
        ($ctx !== '' ? "Contesto:\n$ctx\n" : '')
      . "Bozza dell'autore (poche righe):\n\"\"\"\n$bozza\n\"\"\"\n\n"
      . "Riscrivi la bozza in un POST pronto per la pubblicazione. Regole:\n"
      . "- Resta fedele alla bozza; non aggiungere fatti, materiali o numeri non presenti.\n"
      . "- 1-3 paragrafi brevi, niente elenchi puntati.\n"
      . "- Nessun prezzo inventato; nessuna promessa esagerata.\n"
      . "- Chiusura naturale (es. invito a seguire/scoprire), senza risultare markettara.\n"
      . "- Niente hashtag, niente emoji invadenti (al massimo uno, se calza).\n"
      . "Rispondi SOLO con il testo del post, senza introduzioni né virgolette.";

    $testo = progettoAiClaude($system, $userMsg);
    $testo = ardy_strip_tool_syntax($testo); // rete di sicurezza sull'output del modello
    if ($testo === '') { echo json_encode(['success' => false, 'error' => 'Generazione non riuscita (riprova)']); exit(); }

    echo json_encode(['success' => true, 'testo' => $testo]);

} catch (PDOException $e) {
    error_log('ARDY PROGETTI AI ERROR: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Errore interno']);
}
