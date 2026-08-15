<?php
// -----------------------------------------------------------
// ARDY LAB — Agente Arricchimento contatti
//
// Dato un contatto incompleto (spesso solo nome + indirizzo, come arrivano
// dalla ricerca OSM), prova a completare i campi mancanti — email, telefono,
// sito, indirizzo, referente, canali social — in due passi:
//
//   1) PASS DETERMINISTICO (gratis): se manca il sito ma c'è l'email, deriva
//      il dominio; poi visita il sito (home + pagine "contatti/chi-siamo") ed
//      estrae email/telefono dai mailto:/tel: e dal testo, più i canali social
//      dalle icone di header/footer. Fonte: sito ufficiale.
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
const ARDY_ENRICH_FIELDS = ['sito', 'email', 'telefono', 'indirizzo', 'referente', 'instagram', 'facebook', 'linkedin'];

// Canali social: sottoinsieme di ARDY_ENRICH_FIELDS trattato a parte, perché
// si estrae dalle icone del sito (link diretti) e non dal testo della pagina.
const ARDY_ENRICH_SOCIAL = ['instagram', 'facebook', 'linkedin'];

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
 * @param string $scope    'tutto' (default) oppure 'social' per cercare SOLO i canali:
 *                         in quel caso salta Google Places (non restituisce social) e
 *                         autorizza il pass agente anche se i dati di contatto sono
 *                         completi — è una richiesta esplicita, la spesa è voluta.
 * @return array  ['campi' => [campo => ['valore','fonte','confidenza','passo']], 'log' => [...]]
 */
function ardyEnrichContact(array $contact, string $apiKey, string $model = 'claude-haiku-4-5', string $scope = 'tutto'): array {
    $soloSocial = ($scope === 'social');
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
    // Con scope 'social' si salta: Places non restituisce profili social, sarebbe
    // solo una chiamata a pagamento buttata via.
    if (!$soloSocial && ardyPlacesConfigured()) {
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
            } elseif (ardyPlacesCapHit()) {
                $log[] = 'Google Maps: tetto giornaliero raggiunto, saltato';
            } else {
                $log[] = 'Google Maps: nessun match affidabile';
            }
        }
    }

    // --- 1c) Sito scoperto da Google: visitalo ORA -------------------------
    // Se all'inizio il sito non c'era, lo scraping (passo 1) è stato saltato.
    // Ora che Google Places lo ha trovato, vai a leggere la pagina "Contatti":
    // è lì che di solito sta l'email (mailto:) che ci manca ancora. Gratis e
    // affidabile → spesso evita anche la chiamata a pagamento dell'agente web.
    if ($sito === '' && isset($proposte['sito']) && trim((string)$proposte['sito']['valore']) !== '') {
        $sito = trim((string)$proposte['sito']['valore']);
        $scraped2 = ardyEnrichScrapeSite($sito);
        foreach ($scraped2 as $campo => $val) {
            if (trim((string)($contact[$campo] ?? '')) !== '') continue; // non sovrascrivere il contatto
            if (isset($proposte[$campo])) continue;                       // né proposte già fatte
            $proposte[$campo] = $val + ['passo' => 'sito'];
        }
        if ($scraped2) $log[] = 'Sito (da Google) visitato: ' . implode(', ', array_keys($scraped2)) . ' trovati';
    }

    // --- 2) Pass agente (web search) per i buchi rimasti -------------------
    // Ricalcola cosa manca dopo i passi gratuiti. Con scope 'social' guardiamo
    // solo i canali: i dati di contatto non sono affar nostro in questo giro.
    $campiScope     = $soloSocial ? ARDY_ENRICH_SOCIAL : ARDY_ENRICH_FIELDS;
    $ancoraMancanti = [];
    $mancantiCore   = []; // i mancanti che NON sono canali social
    foreach ($campiScope as $f) {
        $haContatto = trim((string)($contact[$f] ?? '')) !== '';
        $haProposta = isset($proposte[$f]) && trim((string)$proposte[$f]['valore']) !== '';
        if ($haContatto || $haProposta) continue;
        $ancoraMancanti[] = $f;
        if (!in_array($f, ARDY_ENRICH_SOCIAL, true)) $mancantiCore[] = $f;
    }

    // Quando l'agente (a pagamento) può partire:
    //  - scope 'tutto'  → solo se manca un dato di contatto vero. I social da
    //    soli non valgono la chiamata: quasi nessuno ha tutti e tre i canali, e
    //    finiremmo per pagare una ricerca web a ogni singolo arricchimento.
    //    Se però parte per altro, gli chiediamo anche i canali — stessa risposta.
    //  - scope 'social' → l'ha chiesto Michela esplicitamente, quindi basta che
    //    manchi un canale: qui la spesa è voluta, non un effetto collaterale.
    $agentePuoPartire = $soloSocial ? !empty($ancoraMancanti) : !empty($mancantiCore);
    if ($agentePuoPartire && $apiKey !== '') {
        $agent = ardyEnrichAgent($contact, $ancoraMancanti, $sito, $apiKey, $model);
        if (!empty($agent['error'])) {
            $log[] = 'Agente web: ' . $agent['error'];
        } else {
            foreach ($agent['campi'] as $campo => $val) {
                if (!in_array($campo, $campiScope, true)) continue;
                if (trim((string)$val['valore']) === '') continue;
                if (isset($proposte[$campo])) continue; // non sovrascrivere il sito ufficiale
                $proposte[$campo] = $val + ['passo' => 'web'];
            }
            $log[] = 'Agente web: ' . (count($agent['campi']) ? implode(', ', array_keys($agent['campi'])) . ' proposti' : 'nulla trovato');
        }
    }

    // Con scope 'social' i passi gratuiti possono aver raccolto anche email,
    // telefono o il sito dedotto dal dominio: fuori tema per questo giro, si
    // buttano. Chi vuole quei campi lancia l'arricchimento completo.
    if ($soloSocial) {
        $proposte = array_intersect_key($proposte, array_flip(ARDY_ENRICH_SOCIAL));
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
 * Visita il sito (home + pagine tipiche di contatto) ed estrae email, telefono
 * e canali social. Si ferma appena ha email e telefono. Ritorna
 * campo => ['valore','fonte','confidenza'].
 */
function ardyEnrichScrapeSite(string $sito): array {
    $base = rtrim($sito, '/');
    $pagine = ['', '/contatti', '/contact', '/chi-siamo', '/about', '/contattaci', '/info', '/dove-siamo'];
    $out = [];
    $socialFatto = false;

    foreach ($pagine as $p) {
        if (isset($out['email']) && isset($out['telefono'])) break; // abbiamo abbastanza
        $url = $base . $p;
        $resp = ardySafeHttpGet($url, 12, 3, 2_000_000);
        if ($resp === null || ($resp['code'] ?? 0) >= 400 || empty($resp['body'])) continue;
        $html = html_entity_decode($resp['body'], ENT_QUOTES | ENT_HTML5);

        // Le icone social stanno nell'header/footer, quindi sono uguali su tutte
        // le pagine: basta guardarle sulla prima pagina che risponde.
        if (!$socialFatto) {
            foreach (ardyEnrichExtractSocial($html) as $canale => $profilo) {
                $out[$canale] = ['valore' => $profilo, 'fonte' => $url, 'confidenza' => 'alta'];
            }
            $socialFatto = true;
        }

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

/**
 * Estrae i link ai profili social dall'HTML — in pratica le icone che quasi
 * tutti i siti mettono in header/footer. È la fonte più affidabile che
 * abbiamo: è il soggetto stesso a dichiarare i propri canali.
 *
 * Scarta tutto ciò che NON è un profilo (pulsanti di condivisione, singoli
 * post, gruppi, pagine di login): in dubbio non salviamo nulla, perché un
 * canale sbagliato è peggio di un canale mancante — si finirebbe per
 * scrivere a un estraneo.
 *
 * @return array canale => URL del profilo (es. 'instagram' => 'https://instagram.com/tizio')
 */
function ardyEnrichExtractSocial(string $html): array {
    $canali = [
        'instagram' => [
            'host'   => '(?:www\.)?instagram\.com',
            'prefix' => '',
            // p/reel/tv = singoli post; explore/accounts/direct = pagine di servizio.
            'scarta' => ['p', 'reel', 'reels', 'tv', 'stories', 'explore', 'accounts', 'direct', 'about', 'legal', 'privacy'],
        ],
        'facebook' => [
            'host'   => '(?:www\.|m\.|[a-z]{2}-[a-z]{2}\.|web\.)?facebook\.com',
            'prefix' => '',
            // sharer/dialog/plugins/tr = widget di condivisione e pixel;
            // pages = vecchio formato /pages/Nome/ID, che questo regex
            // troncherebbe a un URL rotto → meglio saltarlo.
            'scarta' => ['sharer', 'sharer.php', 'share.php', 'share', 'dialog', 'plugins', 'tr',
                         'groups', 'events', 'hashtag', 'login', 'login.php', 'help',
                         'policies', 'watch', 'pages', 'people'],
        ],
        'linkedin' => [
            'host'   => '(?:[a-z]{2}\.)?(?:www\.)?linkedin\.com',
            // Su LinkedIn solo /in/ (persona) e /company/ (azienda) sono profili:
            // vincolando il prefisso, i link di condivisione cadono da soli.
            'prefix' => '(?:in|company|school)/',
            'scarta' => [],
        ],
    ];

    $out = [];
    foreach ($canali as $nome => $cfg) {
        $re = '~https?://' . $cfg['host'] . '/' . $cfg['prefix'] . '([A-Za-z0-9_.\-]{2,60})(\?id=\d+)?~i';
        if (!preg_match_all($re, $html, $m, PREG_SET_ORDER)) continue;
        foreach ($m as $match) {
            $slug  = strtolower($match[1]);
            $query = $match[2] ?? '';
            if (in_array($slug, $cfg['scarta'], true)) continue;
            // facebook.com/profile.php vale solo con l'id in coda.
            if ($slug === 'profile.php' && $query === '') continue;
            $out[$nome] = $match[0];
            break; // il primo profilo buono basta: gli altri sono ripetizioni dell'icona
        }
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
function ardyEnrichAgent(array $contact, array $mancanti, string $sito, string $apiKey, string $model = 'claude-haiku-4-5'): array {
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
        . "- 'instagram', 'facebook', 'linkedin' sono gli URL dei PROFILI UFFICIALI dell'azienda "
        . "(es. https://instagram.com/nomeazienda). NON mettere: singoli post, gruppi, pagine di "
        . "fan non ufficiali, profili di omonimi o di dipendenti a titolo personale. Se il profilo "
        . "non è chiaramente quello dell'azienda, lascialo a null: un canale sbagliato ci farebbe "
        . "scrivere a un estraneo.\n"
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

    $resp = ardyEnrichCallAnthropic($system, $user, $tools, $apiKey, $model);
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
function ardyEnrichCallAnthropic(string $system, string $user, array $tools, string $apiKey, string $model = 'claude-haiku-4-5'): array {
    $payload = json_encode([
        // Modello scelto dalla dash. Default Haiku 4.5: ~3x piu' economico di
        // Sonnet ($1/$5 vs $3/$15 per Mtok) e sufficiente per estrarre i campi.
        'model'      => $model,
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
