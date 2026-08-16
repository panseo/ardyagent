<?php
// -----------------------------------------------------------
// ARDY LAB — Estrazione B&B dal portale bed-and-breakfast.it
//
// Il portale pubblica gli elenchi per regione (es. /it/regione/lista/puglia/bb)
// e in ogni pagina inserisce un blocco JSON-LD schema.org ItemList con una voce
// BedAndBreakfast per struttura: nome, indirizzo completo, coordinate, voto.
// Leggiamo QUELLO, non l'HTML: è dato strutturato, stabile e pensato per essere
// letto dalle macchine (è lo stesso che usa Google per le rich snippet).
//
// Cosa NON c'è nel portale: telefono, email e sito della struttura. Sono dietro
// endpoint /ajax/ che il robots.txt del sito vieta ai bot, quindi non li tocchiamo:
// i contatti si completano dopo, con l'agente di arricchimento (enrich_contact).
//
// Educazione verso il portale: una pagina per chiamata, User-Agent dichiarato
// (ArdyBot, via ardySafeHttpGet) e pausa tra una pagina e l'altra lato dash.
// -----------------------------------------------------------

require_once __DIR__ . '/ardy-net.php';

if (!function_exists('ardyPortaleBbRegioni')) {

/** Slug di regione ammessi dal portale → nome leggibile (per il campo regione). */
function ardyPortaleBbRegioni(): array
{
    return [
        'abruzzo'               => 'Abruzzo',
        'basilicata'            => 'Basilicata',
        'calabria'              => 'Calabria',
        'campania'              => 'Campania',
        'emilia-romagna'        => 'Emilia-Romagna',
        'friuli-venezia-giulia' => 'Friuli-Venezia Giulia',
        'lazio'                 => 'Lazio',
        'liguria'               => 'Liguria',
        'lombardia'             => 'Lombardia',
        'marche'                => 'Marche',
        'molise'                => 'Molise',
        'piemonte'              => 'Piemonte',
        'puglia'                => 'Puglia',
        'sardegna'              => 'Sardegna',
        'sicilia'               => 'Sicilia',
        'toscana'               => 'Toscana',
        'trentino-alto-adige'   => 'Trentino-Alto Adige',
        'umbria'                => 'Umbria',
        'valle-d-aosta'         => "Valle d'Aosta",
        'veneto'                => 'Veneto',
    ];
}

/** URL della pagina di elenco: la 1 non ha suffisso, dalla 2 in poi /2, /3, ... */
function ardyPortaleBbUrl(string $slug, int $pagina): string
{
    $base = 'https://www.bed-and-breakfast.it/it/regione/lista/' . $slug . '/bb';
    return $pagina > 1 ? $base . '/' . $pagina : $base;
}

/**
 * Tipi schema.org di struttura ricettiva accettati. L'elenco "bb" del portale
 * non è omogeneo: accanto ai BedAndBreakfast compaiono LodgingBusiness e Hotel
 * (case vacanza, affittacamere). Sono lead validi per la Galleria Diffusa —
 * strutture con camere da arredare — quindi li teniamo tutti, ma marcati.
 */
function ardyPortaleBbTipiOk(): array
{
    return ['BedAndBreakfast', 'LodgingBusiness', 'Hotel', 'GuestHouse',
            'Apartment', 'House', 'Resort', 'VacationRental', 'Hostel'];
}

/**
 * Estrae dai blocchi HTML delle schede i due dati che il JSON-LD non ha:
 * provincia e tipologia dichiarata dal portale. Ogni scheda è
 * <div class="bs-box-struttura" id="bbit12345">, contiene
 * <div class="bs-tipologia">Bed &amp; Breakfast</div> e <strong>Comune (BA)</strong>.
 *
 * @return array<string,array{provincia:string,tipologia:string}> id struttura → dati
 */
function ardyPortaleBbDatiBlocchi(string $html): array
{
    $out = [];
    $blocchi = preg_split('/<div class="bs-box-struttura/', $html);
    foreach ($blocchi as $b) {
        if (!preg_match('/id="bbit(\d+)"/', $b, $mId)) { continue; }
        $prov = preg_match('/<strong>\s*[^<(]*\(\s*([A-Z]{2})\s*\)/u', $b, $mPr) ? $mPr[1] : '';
        $tipo = preg_match('/<div class="bs-tipologia">\s*(.*?)\s*<\/div>/su', $b, $mTp)
            ? trim(html_entity_decode(strip_tags($mTp[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'))
            : '';
        $out[$mId[1]] = ['provincia' => $prov, 'tipologia' => $tipo];
    }
    return $out;
}

/**
 * Legge una pagina di elenco e ne estrae le strutture.
 *
 * @return array{ok:bool, error?:string, results:array<int,array<string,mixed>>}
 *         results vuoto = pagina oltre l'ultima (fine elenco).
 */
function ardyPortaleBbPagina(string $slug, int $pagina): array
{
    $regioni = ardyPortaleBbRegioni();
    if (!isset($regioni[$slug])) {
        return ['ok' => false, 'error' => 'Regione non valida: ' . $slug, 'results' => []];
    }
    $pagina = max(1, min(200, $pagina));

    // 2 MB di tetto: una pagina di elenco pesa ~700 KB.
    $res = ardySafeHttpGet(ardyPortaleBbUrl($slug, $pagina), 25, 3, 2 * 1024 * 1024);
    if ($res === null || $res['code'] !== 200) {
        $code = $res['code'] ?? 0;
        return ['ok' => false, 'error' => 'Portale non raggiungibile (HTTP ' . $code . ')', 'results' => []];
    }

    $html    = $res['body'];
    $extra   = ardyPortaleBbDatiBlocchi($html);
    $tipiOk  = ardyPortaleBbTipiOk();
    $results = [];

    // Le pagine hanno due blocchi JSON-LD, entrambi con itemListElement: il
    // BreadcrumbList di navigazione (le sue voci sono stringhe, cadono da sole
    // sul controllo is_array) e l'elenco vero delle strutture.
    if (!preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m)) {
        return ['ok' => true, 'results' => []];
    }

    foreach ($m[1] as $blob) {
        $data = json_decode(trim($blob), true);
        if (!is_array($data) || empty($data['itemListElement']) || !is_array($data['itemListElement'])) { continue; }

        foreach ($data['itemListElement'] as $el) {
            $item = $el['item'] ?? null;
            if (!is_array($item) || !in_array($item['@type'] ?? '', $tipiOk, true)) { continue; }

            $nome = trim((string) ($item['name'] ?? ''));
            $url  = trim((string) ($item['url'] ?? ''));
            if ($nome === '') { continue; }

            $addr    = is_array($item['address'] ?? null) ? $item['address'] : [];
            $via     = trim((string) ($addr['streetAddress'] ?? ''));
            $comune  = trim((string) ($addr['addressLocality'] ?? ''));
            $cap     = trim((string) ($addr['postalCode'] ?? ''));

            // Provincia e tipologia dal blocco HTML della stessa scheda
            // (l'id struttura è in fondo all'URL: .../nome-comune/57600).
            $prov = $tipologia = '';
            if (preg_match('#/(\d+)$#', $url, $mId) && isset($extra[$mId[1]])) {
                $prov      = $extra[$mId[1]]['provincia'];
                $tipologia = $extra[$mId[1]]['tipologia'];
            }

            // "Via Uria, 71019 Vieste (FG)" — formato leggibile in scheda contatto.
            $indirizzo = implode(', ', array_filter([
                $via,
                trim($cap . ' ' . $comune . ($prov !== '' ? " ($prov)" : '')),
            ]));

            $rating = is_array($item['aggregateRating'] ?? null) ? $item['aggregateRating'] : [];
            $geo    = is_array($item['geo'] ?? null) ? $item['geo'] : [];

            $results[] = [
                'nome'       => $nome,
                'indirizzo'  => $indirizzo,
                'comune'     => $comune,
                'provincia'  => $prov,
                'tipologia'  => $tipologia,
                'cap'        => $cap,
                'lat'        => isset($geo['latitude'])  ? (float) $geo['latitude']  : null,
                'lon'        => isset($geo['longitude']) ? (float) $geo['longitude'] : null,
                'voto'       => isset($rating['ratingValue']) ? (float) $rating['ratingValue'] : null,
                'recensioni' => isset($rating['reviewCount']) ? (int) $rating['reviewCount'] : null,
                'scheda'     => $url,
                // Telefono/email/sito non sono pubblicati: li completa l'arricchimento.
                'email'      => '',
                'telefono'   => '',
                'sito'       => '',
            ];
        }
    }

    return ['ok' => true, 'results' => $results];
}

/**
 * Nota da salvare in scheda contatto: la provenienza, più i dati che il portale
 * offre e che non hanno una colonna dedicata (link alla scheda, voto ospiti,
 * tipologia). Servono a Michela per capire chi ha davanti prima di scrivere.
 *
 * @param array<string,mixed> $lead risultato della ricerca (qualsiasi fonte)
 */
function ardyNotaLead(string $base, array $lead): string
{
    $parti = [$base];
    if (!empty($lead['tipologia'])) { $parti[] = trim((string) $lead['tipologia']); }
    if (!empty($lead['voto'])) {
        $voto = 'Voto ospiti ' . rtrim(rtrim(number_format((float) $lead['voto'], 1, ',', ''), '0'), ',') . '/10';
        if (!empty($lead['recensioni'])) { $voto .= ' su ' . (int) $lead['recensioni'] . ' recensioni'; }
        $parti[] = $voto;
    }
    if (!empty($lead['scheda']) && filter_var($lead['scheda'], FILTER_VALIDATE_URL)) {
        $parti[] = (string) $lead['scheda'];
    }
    return implode(' · ', $parti);
}

} // function_exists
