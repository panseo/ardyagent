<?php
// -----------------------------------------------------------
// ARDY LAB — Agente Arricchimento contatti
//
// Dato un contatto incompleto (spesso solo nome + indirizzo, come arrivano
// dalla ricerca OSM), prova a completare i campi mancanti — email, telefono,
// sito, indirizzo, referente — in due passi:
//
//   1) PASS DETERMINISTICO (gratis): se manca il sito ma c'è l'email, deriva
//      il dominio; poi visita il sito (home + pagine "contatti/chi-siamo") ed
//      estrae email/telefono dai mailto:/tel: e dal testo. Fonte: sito ufficiale.
//
//   2) PASS AGENTE (Claude + web search): per i buchi rimasti, Claude cerca in
//      rete (sito ufficiale, Pagine Gialle, ecc.) e restituisce i campi mancanti
//      ognuno con FONTE e CONFIDENZA. Non inventa: se non trova, lascia null.
//
// L'output è una PROPOSTA: per ogni campo { valore, fonte, confidenza }. NON
// scrive su DB — la conferma campo-per-campo avviene in UI.
// -----------------------------------------------------------

require_once __DIR__ . '/ardy-net.php';    // ardySafeHttpGet, ardyValidatePublicUrl
require_once __DIR__ . '/ardy-places.php'; // ardyPlacesConfigured, ardyPlacesFindOne

// Campi che l'agente prova a completare (corrispondono a colonne reali del DB).
const ARDY_ENRICH_FIELDS = ['sito', 'email', 'telefono', 'indirizzo', 'referente'];

// Provider email "free": un'email @gmail.com NON identifica il dominio del sito.
const ARDY_FREE_MAIL = [
    'gmail.com', 'googlemail.com', 'libero.it', 'yahoo.it', 'yahoo.com',
    'hotmail.it', 'hotmail.com', 'outlook.it', 'outlook.com', 'live.it',
    'live.com', 'icloud.com', 'me.com', 'tin.it', 'virgilio.it', 'alice.it',
    'tiscali.it', 'pec.it', 'aruba.it', 'fastwebnet.it', 'email.it',
];

/**
 * Orchestratore: ritorna la proposta di arricchimento per un contatto.
 *
 * @param array  $contact  riga del DB (nome, email, telefono, sito, indirizzo, referente, categoria, ...)
 * @param string $apiKey   Anthropic API key
 * @return array  ['campi' => [campo => ['valore','fonte','confidenza','passo']], 'log' => [...]]
 */
function ardyEnrichContact(array $contact, string $apiKey): array {
    $log    = [];
    $proposte = []; // campo => ['valore','fonte','confidenza','passo']

    // Cosa manca già adesso?
    $mancanti = [];
    foreach (ARDY_ENRICH_FIELDS as $f) {
        if (trim((string)($contact[$f] ?? '')) === '') $mancanti[] = $f;
    }

    // --- 0) Deriva il sito dal dominio dell'email, se manca il sito ---------
    $sito = trim((string)($contact['sito'] ?? ''));
    if ($sito === '' && !empty($contact['email'])) {
        $derivato = ardyEnrichSiteFromEmail($contact['email']);
        if ($derivato) {
            $proposte['sito'] = ['valore' => $derivato, 'fonte' => 'dominio email ' . $contact['email'], 'confidenza' => 'media', 'passo' => 'dominio'];
            $sito = $derivato;
            $log[] = "Sito derivato dal dominio email: $derivato";
        }
    }

    // --- 1) Scraping del sito ufficiale ------------------------------------
    if ($sito !== '') {
        $scraped = ardyEnrichScrapeSite($sito);
        foreach ($scraped as $campo => $val) {
            // Riempi solo i campi ancora vuoti nel contatto, senza sovrascrivere
            // proposte già fatte. Il sito ufficiale ha priorità (confidenza alta).
            if (trim((string)($contact[$campo] ?? '')) !== '') continue;
            if (isset($proposte[$campo])) continue;
            $proposte[$campo] = $val + ['passo' => 'sito'];
        }
        if ($scraped) $log[] = 'Sito visitato: ' . implode(', ', array_keys($scraped)) . ' trovati';
    }

    // --- 1b) Google Places (se configurato): dato strutturato e affidabile --
    // Riempie i campi ancora vuoti (sito, telefono, indirizzo) prima di pagare
    // l'agente Claude. Email e referente non li fornisce → restano a Claude.
    if (ardyPlacesConfigured()) {
        $serveQualcosa = false;
        foreach (['sito', 'telefono', 'indirizzo'] as $f) {
            if (trim((string)($contact[$f] ?? '')) === '' && !isset($proposte[$f])) { $serveQualcosa = true; break; }
        }
        if ($serveQualcosa) {
            $place = ardyPlacesFindOne((string)($contact['nome'] ?? ''), (string)($contact['indirizzo'] ?? ''));
            if ($place) {
                $fonte = $place['maps'] ?: 'Google Maps';
                foreach (['sito', 'telefono', 'indirizzo'] as $campo) {
                    if (trim((string)($contact[$campo] ?? '')) !== '') continue; // non sovrascrivere il contatto
                    if (isset($proposte[$campo])) continue;                       // né il sito ufficiale
                    $v = trim((string)($place[$campo] ?? ''));
                    if ($v === '') continue;
                    $proposte[$campo] = ['valore' => $v, 'fonte' => $fonte, 'confidenza' => 'alta', 'passo' => 'google'];
                }
                $log[] = 'Google Maps: ' . $place['nome'] . ' trovato';
            } else {
                $log[] = 'Google Maps: nessun match affidabile';
            }
        }
    }

    // --- 2) Pass agente (web search) per i buchi rimasti -------------------
    // Ricalcola cosa manca dopo i passi gratuiti.
    $ancoraMancanti = [];
    foreach (ARDY_ENRICH_FIELDS as $f) {
        $haContatto = trim((string)($contact[$f] ?? '')) !== '';
        $haProposta = isset($proposte[$f]) && trim((string)$proposte[$f]['valore']) !== '';
        if (!$haContatto && !$haProposta) $ancoraMancanti[] = $f;
    }

    if (!empty($ancoraMancanti) && $apiKey !== '') {
        $agent = ardyEnrichAgent($contact, $ancoraMancanti, $sito, $apiKey);
        if (!empty($agent['error'])) {
            $log[] = 'Agente web: ' . $agent['error'];
        } else {
            foreach ($agent['campi'] as $campo => $val) {
                if (!in_array($campo, ARDY_ENRICH_FIELDS, true)) continue;
                if (trim((string)$val['valore']) === '') continue;
                if (isset($proposte[$campo])) continue; // non sovrascrivere il sito ufficiale
                $proposte[$campo] = $val + ['passo' => 'web'];
            }
            $log[] = 'Agente web: ' . (count($agent['campi']) ? implode(', ', array_keys($agent['campi'])) . ' proposti' : 'nulla trovato');
        }
    }

    return ['campi' => $proposte, 'log' => $log];
}

/**
 * Deriva un URL di sito dal dominio dell'email, saltando i provider "free".
 * info@dominio.it -> https://dominio.it   |  tizio@gmail.com -> null
 */
function ardyEnrichSiteFromEmail(string $email): ?string {
    $email = strtolower(trim($email));
    $at = strrpos($email, '@');
    if ($at === false) return null;
    $dominio = substr($email, $at + 1);
    if ($dominio === '' || strpos($dominio, '.') === false) return null;
    if (in_array($dominio, ARDY_FREE_MAIL, true)) return null;
    // Valida che l'host sia pubblico/risolvibile (anti-SSRF + scarta domini morti)
    $url = ardyValidatePublicUrl('https://' . $dominio);
    return $url ?: null;
}

/**
 * Visita il sito (home + pagine tipiche di contatto) ed estrae email e
 * telefono. Si ferma appena ha entrambi. Ritorna campo => ['valore','fonte','confidenza'].
 */
function ardyEnrichScrapeSite(string $sito): array {
    $base = rtrim($sito, '/');
    $pagine = ['', '/contatti', '/contact', '/chi-siamo', '/about', '/contattaci', '/info', '/dove-siamo'];
    $out = [];

    foreach ($pagine as $p) {
        if (isset($out['email']) && isset($out['telefono'])) break; // abbiamo abbastanza
        $url = $base . $p;
        $resp = ardySafeHttpGet($url, 12, 3, 2_000_000);
        if ($resp === null || ($resp['code'] ?? 0) >= 400 || empty($resp['body'])) continue;
        $html = html_entity_decode($resp['body'], ENT_QUOTES | ENT_HTML5);

        if (!isset($out['email'])) {
            $em = ardyEnrichExtractEmail($html);
            if ($em) $out['email'] = ['valore' => $em, 'fonte' => $url, 'confidenza' => 'alta'];
        }
        if (!isset($out['telefono'])) {
            $tel = ardyEnrichExtractPhone($html);
            if ($tel) $out['telefono'] = ['valore' => $tel, 'fonte' => $url, 'confidenza' => 'alta'];
        }
        usleep(200000); // gentile col server remoto
    }
    return $out;
}

/** Estrae la prima email "vera" dall'HTML, scartando asset e servizi noti. */
function ardyEnrichExtractEmail(string $html): ?string {
    // mailto: ha priorità (è quasi sempre l'email giusta)
    if (preg_match('/mailto:([a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,})/', $html, $m)) {
        return strtolower($m[1]);
    }
    if (preg_match_all('/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/', $html, $m)) {
        foreach ($m[0] as $email) {
            if (preg_match('/\.(png|jpg|jpeg|gif|svg|webp|woff2?|ttf|css|js)$/i', $email)) continue;
            if (preg_match('/@(sentry|example|schema|w3|jquery|wordpress|google|facebook|sentry\.io|wixpress)/i', $email)) continue;
            return strtolower($email);
        }
    }
    // Offuscamento [at] / (at)
    if (preg_match('/([a-zA-Z0-9._%+\-]+)\s*[\[\(]\s*at\s*[\]\)]\s*([a-zA-Z0-9.\-]+\.[a-zA-Z]{2,})/i', $html, $m)) {
        return strtolower($m[1] . '@' . $m[2]);
    }
    return null;
}

/** Estrae un telefono dall'HTML: prima i link tel:, poi un pattern italiano. */
function ardyEnrichExtractPhone(string $html): ?string {
    if (preg_match('/tel:\s*(\+?[0-9][0-9\s\.\-\/]{6,18})/i', $html, $m)) {
        return ardyEnrichCleanPhone($m[1]);
    }
    // Pattern grezzo: prefisso italiano +39 opzionale, poi 8-11 cifre con separatori
    if (preg_match('/(\+39[\s\.\-]?)?0?\d{2,4}[\s\.\-]?\d{5,8}/', strip_tags($html), $m)) {
        $cand = ardyEnrichCleanPhone($m[0]);
        if (strlen(preg_replace('/\D/', '', $cand)) >= 8) return $cand;
    }
    return null;
}

function ardyEnrichCleanPhone(string $raw): string {
    $raw = trim($raw);
    // tieni un eventuale + iniziale, normalizza gli spazi
    $plus = (strpos($raw, '+') === 0) ? '+' : '';
    $digits = preg_replace('/[^0-9]/', '', $raw);
    return $plus . $digits;
}

/**
 * Pass agente: Claude con web search completa i campi mancanti.
 * Ritorna ['campi' => [campo => ['valore','fonte','confidenza']]] oppure ['error'=>...].
 */
function ardyEnrichAgent(array $contact, array $mancanti, string $sito, string $apiKey): array {
    $noti = [];
    foreach (['nome', 'indirizzo', 'sito', 'email', 'telefono', 'categoria'] as $f) {
        $v = trim((string)($contact[$f] ?? ''));
        if ($v !== '') $noti[] = "$f: $v";
    }
    $datiNoti = $noti ? implode("\n", $noti) : '(solo il nome)';
    $listaMancanti = implode(', ', $mancanti);

    $system = "Sei un assistente che arricchisce i dati di aziende italiane (antiquari, "
        . "interior designer, B&B, mercatini) per una bottega di restauro di Roma. "
        . "Ricevi i dati parziali di UN'azienda e usi la ricerca web per trovare SOLO i "
        . "campi richiesti come mancanti. Cerca sul sito ufficiale dell'azienda e su "
        . "portali affidabili (Pagine Gialle, PagineBianche, Google Business, registri "
        . "imprese). Regole ferree:\n"
        . "- NON inventare. Se un dato non lo trovi con ragionevole certezza, mettilo a null.\n"
        . "- Verifica che i dati siano della STESSA azienda (nome + città coincidono), non di omonime.\n"
        . "- 'referente' è il nome di una persona di riferimento, non il nome dell'azienda.\n"
        . "- 'sito' deve essere l'URL del sito ufficiale (non un profilo Pagine Gialle/Facebook).\n"
        . "- Per ogni campo indica la fonte (URL) e la confidenza: alta, media o bassa.\n"
        . "- Rispondi ESCLUSIVAMENTE con un blocco JSON, senza testo prima o dopo, in questo formato:\n"
        . '{"campi":{"<campo>":{"valore":"...","fonte":"https://...","confidenza":"alta|media|bassa"}}}'
        . "\nIncludi nel JSON solo i campi richiesti per cui hai trovato un valore.";

    $user = "Azienda da completare.\nDati noti:\n$datiNoti\n\n"
        . "Campi da trovare: $listaMancanti.\n"
        . "Cerca in rete e restituisci il JSON.";

    $tools = [
        ['type' => 'web_search_20250305', 'name' => 'web_search', 'max_uses' => 5],
    ];

    $resp = ardyEnrichCallAnthropic($system, $user, $tools, $apiKey);
    if (!empty($resp['error'])) return ['error' => $resp['error']];

    // Concatena i blocchi di testo della risposta ed estrai il JSON.
    $text = '';
    foreach (($resp['content'] ?? []) as $block) {
        if (($block['type'] ?? '') === 'text') $text .= $block['text'];
    }
    $json = ardyEnrichExtractJson($text);
    if ($json === null || !isset($json['campi']) || !is_array($json['campi'])) {
        return ['error' => 'risposta non interpretabile'];
    }

    $campi = [];
    foreach ($json['campi'] as $campo => $info) {
        if (!is_array($info)) continue;
        $valore = trim((string)($info['valore'] ?? ''));
        if ($valore === '' || strtolower($valore) === 'null') continue;
        $campi[$campo] = [
            'valore'     => $valore,
            'fonte'      => trim((string)($info['fonte'] ?? '')),
            'confidenza' => strtolower(trim((string)($info['confidenza'] ?? 'bassa'))),
        ];
    }
    return ['campi' => $campi];
}

/** Chiamata diretta alle Messages API con tool server-side (web search). */
function ardyEnrichCallAnthropic(string $system, string $user, array $tools, string $apiKey): array {
    $payload = json_encode([
        'model'      => 'claude-sonnet-4-6',
        'max_tokens' => 1500,
        'system'     => $system,
        'tools'      => $tools,
        'messages'   => [['role' => 'user', 'content' => $user]],
    ]);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
        ],
    ]);
    $response = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err) {
        error_log('ARDY ENRICH CURL: ' . $err);
        return ['error' => 'connessione al servizio AI non riuscita'];
    }
    $data = json_decode($response, true);
    if (!is_array($data)) return ['error' => 'risposta AI non valida'];
    if ($code < 200 || $code >= 300 || ($data['type'] ?? '') === 'error') {
        error_log("ARDY ENRICH HTTP $code: $response");
        return ['error' => 'servizio AI non disponibile (HTTP ' . $code . ')'];
    }
    return $data;
}

/** Estrae il primo oggetto JSON bilanciato da un testo. */
function ardyEnrichExtractJson(string $text): ?array {
    $start = strpos($text, '{');
    if ($start === false) return null;
    $depth = 0; $inStr = false; $esc = false;
    for ($i = $start, $n = strlen($text); $i < $n; $i++) {
        $ch = $text[$i];
        if ($inStr) {
            if ($esc)            { $esc = false; }
            elseif ($ch === '\\') { $esc = true; }
            elseif ($ch === '"')  { $inStr = false; }
            continue;
        }
        if ($ch === '"')      $inStr = true;
        elseif ($ch === '{')  $depth++;
        elseif ($ch === '}') {
            $depth--;
            if ($depth === 0) {
                $decoded = json_decode(substr($text, $start, $i - $start + 1), true);
                return is_array($decoded) ? $decoded : null;
            }
        }
    }
    return null;
}
