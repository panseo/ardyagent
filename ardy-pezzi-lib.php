<?php
// -----------------------------------------------------------
// ARDY LAB — Libreria "Racconta" (pezzi raccontati da foto + voce)
// Condivisa da ardy-pezzi-api.php, ardy-pezzi-ai.php, ardy-pubblica-pezzo.php e
// dalla Sole del sito (ardy-proxy.php). Vedi PIANO-RACCONTA.md.
// (File interno: negato all'accesso diretto via .htaccess.)
// -----------------------------------------------------------

require_once __DIR__ . '/ardy-sanitize.php';   // ardy_strip_tool_syntax()

/** Campi della scheda di un pezzo, nell'ordine in cui si mostrano (chiave → etichetta). */
const PEZZI_SCHEDA_CAMPI = [
    'tipologia'  => 'Tipologia',
    'epoca'      => 'Epoca',
    'stile'      => 'Stile',
    'materiali'  => 'Legno e materiali',
    'dimensioni' => 'Dimensioni',
    'problemi'   => 'Com\'è arrivato',
    'tecniche'   => 'Lavorazioni',
    'prodotti'   => 'Prodotti e finiture',
];

/** Cartella su disco delle foto di un capitolo. */
function pezziFotoDir(int $pezzoId, int $faseId): string {
    return rtrim(ARDY_UPLOAD_DIR, '/') . '/pezzi/' . $pezzoId . '/fasi/' . $faseId . '/';
}

/** Scheda normalizzata: solo i campi noti, stringhe ripulite. */
function pezziSchedaNorm($scheda): array {
    if (is_string($scheda)) $scheda = json_decode($scheda, true);
    if (!is_array($scheda)) $scheda = [];
    $out = [];
    foreach (array_keys(PEZZI_SCHEDA_CAMPI) as $k) {
        $out[$k] = mb_substr(trim((string) ($scheda[$k] ?? '')), 0, 600);
    }
    return $out;
}

/**
 * Avvisi su dati che non dovrebbero finire su una pagina pubblica. Il prompt di Sole
 * li toglie già quando scrive, ma il testo si corregge anche a mano o a voce dopo:
 * questo controllo deterministico è la seconda rete, prima di pubblicare.
 * Non blocca: elenca, e chi pubblica decide.
 */
function pezziAvvisiPrivacy(string $testo): array {
    $avvisi = [];
    if (preg_match('/(?:\+?\d[\s.\-]?){9,}/', $testo))                         $avvisi[] = 'sembra esserci un numero di telefono';
    if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $testo))       $avvisi[] = 'c\'è un indirizzo email';
    if (preg_match('/€\s?\d|\d\s?€|\beuro\b/iu', $testo))                        $avvisi[] = 'si parla di prezzi o importi';
    if (preg_match('/\b(?:via|viale|piazza|largo|corso)\s+[A-ZÀ-Ü]/u', $testo)) $avvisi[] = 'sembra esserci un indirizzo';
    if (preg_match('/\b(?:signor|signora|sig\.|sig\.ra|dott\.|cliente)\b/iu', $testo)) $avvisi[] = 'si nomina una persona o "il cliente"';
    return $avvisi;
}

/**
 * Ciò che Sole sa di un pezzo, a partire dall'id del post WordPress su cui si trova
 * il visitatore. Legge SOLO l'istantanea scritta alla pubblicazione (pubblico_json):
 * bozze, note dettate e modifiche non ancora pubblicate non escono mai di qui.
 * null se il post non è la pagina di un pezzo.
 */
function pezziProiezionePubblica(PDO $db, int $wpPostId): ?array {
    if ($wpPostId <= 0) return null;
    $st = $db->prepare("SELECT pubblico_json FROM pezzi WHERE wp_post_id = ? AND deleted_at IS NULL LIMIT 1");
    $st->execute([$wpPostId]);
    $json = $st->fetchColumn();
    if (!is_string($json) || $json === '') return null;
    $p = json_decode($json, true);
    return is_array($p) ? $p : null;
}

/** La proiezione pubblica in forma di testo, per il prompt di Sole. */
function pezziProiezioneTesto(array $p): string {
    $righe = ['Pezzo: ' . ($p['titolo'] ?? '')];
    foreach (PEZZI_SCHEDA_CAMPI as $k => $label) {
        $v = trim((string) ($p['scheda'][$k] ?? ''));
        if ($v !== '') $righe[] = $label . ': ' . $v;
    }
    if (!empty($p['in_breve'])) $righe[] = "\nIn breve:\n" . $p['in_breve'];
    if (!empty($p['storia'])) $righe[] = "\nIntroduzione:\n" . $p['storia'];
    foreach ($p['capitoli'] ?? [] as $c) {
        $righe[] = "\nCapitolo del " . ($c['data'] ?? '') . ' — ' . ($c['titolo'] ?? '') . "\n" . ($c['testo'] ?? '');
    }
    foreach ($p['faq'] ?? [] as $f) {
        $righe[] = "\nDomanda frequente: " . ($f['domanda'] ?? '') . "\nRisposta: " . ($f['risposta'] ?? '');
    }
    return mb_substr(implode("\n", $righe), 0, 11000);
}

/** Chiamata a Claude. Con $tool forza quell'uscita strutturata e ne ritorna l'input; senza, il testo. */
function pezziClaude(string $system, array $content, int $maxTokens, ?array $tool = null) {
    if (!defined('ARDY_API_KEY') || ARDY_API_KEY === '') return null;
    $body = [
        'model'      => 'claude-sonnet-4-6',   // stesso modello del resto del repo
        'max_tokens' => $maxTokens,
        'system'     => $system,
        'messages'   => [['role' => 'user', 'content' => $content]],
    ];
    if ($tool) {
        $body['tools']       = [$tool];
        $body['tool_choice'] = ['type' => 'tool', 'name' => $tool['name']];
    }
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 90,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . ARDY_API_KEY,
            'anthropic-version: 2023-06-01',
        ],
    ]);
    $res  = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($http !== 200) { error_log('ARDY PEZZI AI: HTTP ' . $http . ' — ' . substr((string) $res, 0, 300)); return null; }
    $data = json_decode($res, true);
    foreach ($data['content'] ?? [] as $b) {
        if ($tool && ($b['type'] ?? '') === 'tool_use') return $b['input'] ?? null;
        if (!$tool && ($b['type'] ?? '') === 'text')    return ardy_strip_tool_syntax(trim((string) $b['text']));
    }
    return null;
}

// Regole comuni a scrittura e correzione: valgono per tutto ciò che può finire sul sito.
const PEZZI_REGOLE_PUBBLICO = "REGOLE PER TUTTO CIÒ CHE VA SUL SITO (inderogabili):
- Anonimato: MAI nomi di persone (proprietari, clienti, parenti), indirizzi, zone o luoghi riconducibili a chi possiede il pezzo, numeri di telefono, email. Se la nota li contiene, ignorali: \"la credenza della signora Rossi\" diventa \"questa credenza\".
- MAI prezzi, costi, preventivi, tempi di consegna.
- Non inventare. Tecniche e prodotti: solo quelli detti nella nota. Epoca e stile: solo se detti, o riconoscibili con buona sicurezza dalle foto, e allora con prudenza (\"probabilmente\", \"circa\").
- Tono: caldo, concreto, artigianale, prima persona plurale (\"abbiamo\", \"in bottega\"). Niente enfasi pubblicitaria, niente emoji, niente hashtag.";

// ── Pacchetto SEO/GEO della pagina del pezzo ─────────────────────────────────
// Perché esiste: la pagina deve farsi trovare su Google e farsi citare dai motori
// di risposta AI (ChatGPT search, Perplexity, AI Overviews). Serve a entrambi la
// stessa cosa: un titolo e una descrizione scritti per chi cerca, un riassunto
// fattuale in apertura ("in breve"), domande con risposte brevi e autonome, link
// verso le pagine servizio giuste. Lo scrive Sole UNA volta (poi si ritocca a mano
// nella mini-app) e lo usa ardy-pubblica-pezzo.php: meta Yoast, blocco "In breve",
// FAQ in pagina (+ schema FAQPage via lo snippet WordPress), link interni.

/** Pagine servizio del sito verso cui Sole può linkare: whitelist, mai URL inventati. */
const PEZZI_LINK_INTERNI = [
    'https://ardy-lab.it/restauro-mobili-antichi-roma/'               => 'Restauro di mobili antichi a Roma',
    'https://ardy-lab.it/trattamento-antitarlo-mobili-roma/'          => 'Trattamento antitarlo dei mobili',
    'https://ardy-lab.it/cambio-colore-laccatura-mobili-roma/'        => 'Cambio colore e laccatura dei mobili',
    'https://ardy-lab.it/doratura-foglia-oro-restauro-cornici-roma/'  => 'Doratura a foglia oro e restauro cornici',
    'https://ardy-lab.it/wrapping-mobili-cucine-roma/'                => 'Wrapping di mobili e cucine',
    'https://ardy-lab.it/tecniche-di-restauro/'                       => 'Le tecniche di restauro',
    'https://ardy-lab.it/riconoscere-il-legno/'                       => 'Come riconoscere il legno',
    'https://ardy-lab.it/corsi-di-formazione/'                        => 'Corsi di restauro',
];

/** Pacchetto SEO normalizzato: lunghezze dentro i limiti di Google, link solo in whitelist. */
function pezziSeoNorm($seo): array {
    if (is_string($seo)) $seo = json_decode($seo, true);
    if (!is_array($seo)) $seo = [];
    $faq = [];
    foreach ((array) ($seo['faq'] ?? []) as $f) {
        $d = trim((string) ($f['domanda'] ?? '')); $r = trim((string) ($f['risposta'] ?? ''));
        if ($d !== '' && $r !== '') $faq[] = ['domanda' => mb_substr($d, 0, 200), 'risposta' => mb_substr($r, 0, 900)];
    }
    $link = array_values(array_filter((array) ($seo['link'] ?? []), fn($u) => isset(PEZZI_LINK_INTERNI[$u])));
    return [
        'titolo'      => mb_substr(trim((string) ($seo['titolo'] ?? '')), 0, 70),
        'descrizione' => mb_substr(trim((string) ($seo['descrizione'] ?? '')), 0, 170),
        'keyphrase'   => mb_substr(trim((string) ($seo['keyphrase'] ?? '')), 0, 80),
        'in_breve'    => mb_substr(trim((string) ($seo['in_breve'] ?? '')), 0, 600),
        'faq'         => array_slice($faq, 0, 6),
        'link'        => array_slice($link, 0, 2),
    ];
}

/** True se il pacchetto ha il minimo per pubblicare (titolo e descrizione). */
function pezziSeoPronto(array $seo): bool {
    return $seo['titolo'] !== '' && $seo['descrizione'] !== '';
}

/**
 * Sole scrive il pacchetto SEO/GEO dal contenuto del pezzo (scheda, introduzione e
 * testi dei capitoli, MAI le note dettate). null se la chiamata fallisce.
 */
function pezziGeneraSeo(PDO $db, int $pezzoId): ?array {
    $st = $db->prepare("SELECT titolo, scheda, storia FROM pezzi WHERE id = ? AND deleted_at IS NULL");
    $st->execute([$pezzoId]);
    $p = $st->fetch(PDO::FETCH_ASSOC);
    if (!$p) return null;
    $cs = $db->prepare("SELECT fase_nome, testo_generato FROM fasi WHERE pezzo_id = ? AND testo_generato <> '' ORDER BY ordine ASC, created_at ASC");
    $cs->execute([$pezzoId]);

    $testo = "TITOLO DEL PEZZO: " . $p['titolo'] . "\n\nSCHEDA:\n";
    foreach (pezziSchedaNorm($p['scheda']) as $k => $v) if ($v !== '') $testo .= '- ' . PEZZI_SCHEDA_CAMPI[$k] . ': ' . $v . "\n";
    $testo .= "\nINTRODUZIONE:\n" . trim((string) $p['storia']) . "\n";
    foreach ($cs->fetchAll(PDO::FETCH_ASSOC) as $c) $testo .= "\nCAPITOLO — " . $c['fase_nome'] . ":\n" . mb_substr((string) $c['testo_generato'], 0, 1500) . "\n";
    $testo .= "\nPAGINE SERVIZIO DEL SITO (puoi sceglierne al massimo 2 da linkare, solo se davvero pertinenti):\n";
    foreach (PEZZI_LINK_INTERNI as $u => $t) $testo .= "- " . $u . " — " . $t . "\n";

    $system = "Sei Sole, la voce di Ardy Lab, bottega di restauro a Roma. Prepari la parte \"per i motori di ricerca\" della pagina che racconta il restauro di un pezzo: deve farsi trovare su Google da chi cerca un restauro simile a Roma, e farsi citare correttamente dagli assistenti AI che rispondono a domande sul restauro.

" . PEZZI_REGOLE_PUBBLICO . "

COSA RESTITUISCI (strumento scrivi_seo):
- titolo: il titolo per Google, max 60 caratteri, con il tipo di pezzo, l'intervento principale e \"Roma\" se ci sta (es. \"Restauro di una seggiola in faggio laccato a Roma\"). Niente nome del sito: lo aggiunge Google.
- descrizione: la meta description, 120-155 caratteri, concreta, con il beneficio per chi legge e un invito implicito (es. \"Come abbiamo…\").
- keyphrase: la frase che una persona scriverebbe su Google, 2-5 parole (es. \"restauro seggiola bambino\").
- in_breve: 2-3 frasi fattuali che rispondono subito a \"cos'è e cosa avete fatto\": tipo di pezzo, legno/materiali, epoca se nota, problema, interventi. È la parte che un assistente AI citerà: deve stare in piedi da sola.
- faq: 3-5 domande che un lettore o un cliente farebbe davvero su questo pezzo o su questo tipo di intervento, con risposte di 2-4 frasi, autonome, basate sul testo e su conoscenze di restauro generali e sicure. Mai prezzi né tempi.
- link: 0-2 URL scelti SOLO dalla lista delle pagine servizio, i più pertinenti.";

    $tool = [
        'name' => 'scrivi_seo',
        'description' => 'Restituisce il pacchetto SEO/GEO della pagina del pezzo.',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'titolo'      => ['type' => 'string'],
                'descrizione' => ['type' => 'string'],
                'keyphrase'   => ['type' => 'string'],
                'in_breve'    => ['type' => 'string'],
                'faq'         => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['domanda' => ['type' => 'string'], 'risposta' => ['type' => 'string']], 'required' => ['domanda', 'risposta']]],
                'link'        => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => array_keys(PEZZI_LINK_INTERNI)]],
            ],
            'required' => ['titolo', 'descrizione', 'keyphrase', 'in_breve', 'faq', 'link'],
        ],
    ];
    $out = pezziClaude($system, [['type' => 'text', 'text' => $testo]], 2000, $tool);
    if (!is_array($out)) return null;
    $seo = pezziSeoNorm($out);
    return pezziSeoPronto($seo) ? $seo : null;
}

/** Tutti i testi SEO in una stringa, per il controllo anonimato prima di pubblicare. */
function pezziSeoTesto(array $seo): string {
    $t = $seo['titolo'] . "\n" . $seo['descrizione'] . "\n" . $seo['in_breve'];
    foreach ($seo['faq'] as $f) $t .= "\n" . $f['domanda'] . "\n" . $f['risposta'];
    return $t;
}
