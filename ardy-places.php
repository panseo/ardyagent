<?php
// -----------------------------------------------------------
// ARDY LAB — Client Google Places API (New)
//
// Fonte dati più completa di OpenStreetMap per le attività locali (telefono,
// sito, indirizzo formattato). Usata in due punti dell'outreach:
//   1) RICERCA AZIENDE  — discovery per categoria + zona (alternativa a OSM)
//   2) Agente Arricchimento — lookup del singolo contatto per completare i buchi
//
// SICUREZZA / COSTI:
// - La chiave sta in ardy-config.php (gitignored), MAI nel frontend né nei log.
//   Costante attesa: ARDY_GOOGLE_PLACES_KEY. Se non definita → degradazione
//   pulita: ardyPlacesConfigured() ritorna false e l'app resta su OSM.
// - Usiamo l'API "New" (places.googleapis.com/v1) con FIELD MASK ristretta:
//   si pagano solo i campi richiesti e si evita di scaricare dati inutili.
// - Geocoding della zona riusato da Nominatim (osmGeocode, gratis): niente
//   chiamate Google extra per trasformare "Roma" in coordinate.
// -----------------------------------------------------------

const ARDY_PLACES_ENDPOINT = 'https://places.googleapis.com/v1/places:searchText';

// Campi richiesti a Google. Includere telefono/sito alza lo SKU di tariffa
// (Enterprise), ma è esattamente il dato che ci serve. Niente campi "atmosphere"
// (recensioni/foto) → non li paghiamo.
const ARDY_PLACES_FIELDMASK =
    'places.displayName,places.formattedAddress,places.nationalPhoneNumber,' .
    'places.internationalPhoneNumber,places.websiteUri,places.location,' .
    'places.googleMapsUri,places.businessStatus';

/** True se la chiave Google è configurata sul server. */
function ardyPlacesConfigured(): bool {
    return defined('ARDY_GOOGLE_PLACES_KEY') && constant('ARDY_GOOGLE_PLACES_KEY') !== '';
}

/** Mappa categoria Ardy -> query testuale italiana per Google Places. */
function ardyPlacesQuery(string $cat): string {
    switch ($cat) {
        case 'antiquari':         return 'negozio di antiquariato';
        case 'mercatini':         return "mercatino dell'usato e modernariato";
        case 'interior_designer': return 'interior designer e studio di arredamento';
        case 'bb':                return 'bed and breakfast';
        default:                  return 'negozio di antiquariato';
    }
}

/**
 * DISCOVERY — attività di una categoria entro un raggio dalla zona indicata.
 * Riceve le coordinate (già geocodate gratis via Nominatim dal chiamante).
 * Ritorna array di lead normalizzati, oppure null su errore di rete.
 *
 * @param int $radiusM raggio in metri (max 50000, limite Google)
 * @param int $maxPagine pagine da scorrere (ogni pagina = 1 chiamata a pagamento, ~20 risultati)
 */
function ardyPlacesSearch(string $cat, float $lat, float $lon, int $radiusM, int $maxPagine = 3): ?array {
    if (!ardyPlacesConfigured()) return null;
    $radiusM = max(1, min(50000, $radiusM));

    $body = [
        'textQuery'      => ardyPlacesQuery($cat),
        'languageCode'   => 'it',
        'regionCode'     => 'IT',
        'maxResultCount' => 20,
        'locationBias'   => [
            'circle' => [
                'center' => ['latitude' => $lat, 'longitude' => $lon],
                'radius' => (float)$radiusM,
            ],
        ],
    ];

    $out  = [];
    $seen = [];
    $pageToken = null;

    for ($p = 0; $p < max(1, $maxPagine); $p++) {
        if ($pageToken !== null) $body['pageToken'] = $pageToken;
        $resp = ardyPlacesHttpPost(ARDY_PLACES_ENDPOINT, $body);
        if ($resp === null) return ($p === 0) ? null : $out; // errore subito → null; dopo → quel che abbiamo
        $data = json_decode($resp, true);
        if (!is_array($data)) return ($p === 0) ? null : $out;

        foreach (($data['places'] ?? []) as $place) {
            $lead = ardyPlacesNormalize($place);
            if ($lead === null) continue;
            $key = strtolower($lead['nome']);
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $out[] = $lead;
        }

        $pageToken = $data['nextPageToken'] ?? null;
        if (!$pageToken) break;
        usleep(200000); // il pageToken Google va lasciato "respirare"
    }

    usort($out, fn($a, $b) => strcasecmp($a['nome'], $b['nome']));
    return $out;
}

/**
 * ENRICHMENT — trova UNA attività per nome (+ indirizzo/zona) e ne ritorna i
 * campi. Include una guardia anti-falso-positivo: scarta risultati il cui nome
 * non ha nulla in comune con quello cercato (evita di prendere un'omonima).
 * Ritorna ['nome','indirizzo','sito','telefono','maps'] oppure null.
 */
function ardyPlacesFindOne(string $nome, string $indirizzo = ''): ?array {
    if (!ardyPlacesConfigured()) return null;
    $nome = trim($nome);
    if ($nome === '') return null;

    $query = $nome . ($indirizzo !== '' ? ' ' . $indirizzo : '');
    $body = [
        'textQuery'      => $query,
        'languageCode'   => 'it',
        'regionCode'     => 'IT',
        'maxResultCount' => 3,
    ];

    $resp = ardyPlacesHttpPost(ARDY_PLACES_ENDPOINT, $body);
    if ($resp === null) return null;
    $data = json_decode($resp, true);
    if (!is_array($data) || empty($data['places'])) return null;

    foreach ($data['places'] as $place) {
        $lead = ardyPlacesNormalize($place);
        if ($lead === null) continue;
        if (!ardyPlacesNameMatch($nome, $lead['nome'])) continue; // guardia omonimi
        return $lead;
    }
    return null;
}

/** Normalizza un oggetto "place" di Google nel formato lead di Ardy. */
function ardyPlacesNormalize(array $place): ?array {
    $nome = trim($place['displayName']['text'] ?? '');
    if ($nome === '') return null;
    // Scarta attività chiuse definitivamente.
    if (($place['businessStatus'] ?? '') === 'CLOSED_PERMANENTLY') return null;

    $tel = trim($place['nationalPhoneNumber'] ?? ($place['internationalPhoneNumber'] ?? ''));

    return [
        'nome'      => $nome,
        'indirizzo' => trim($place['formattedAddress'] ?? ''),
        'sito'      => trim($place['websiteUri'] ?? ''),
        'telefono'  => $tel,
        'email'     => '', // Google Places non fornisce l'email
        'maps'      => trim($place['googleMapsUri'] ?? ''),
    ];
}

/**
 * Guardia anti-omonimi: accetta il match solo se i due nomi condividono almeno
 * un token significativo (>= 4 lettere), ignorando parole generiche.
 */
function ardyPlacesNameMatch(string $cercato, string $trovato): bool {
    $stop = ['antichita', 'antiquariato', 'galleria', 'gallerie', 'studio', 'arte',
             'design', 'casa', 'roma', 'srl', 'snc', 'sas', 'di', 'the', 'and',
             'bed', 'breakfast', 'mercatino', 'interior'];
    $tok = function (string $s) use ($stop): array {
        $s = strtolower($s);
        $s = preg_replace('/[^a-z0-9àèéìòù\s]/u', ' ', $s);
        $parti = preg_split('/\s+/', trim($s), -1, PREG_SPLIT_NO_EMPTY);
        return array_values(array_filter($parti, fn($w) => mb_strlen($w) >= 4 && !in_array($w, $stop, true)));
    };
    $a = $tok($cercato);
    $b = $tok($trovato);
    if (!$a || !$b) return true; // se uno dei due è solo parole generiche, non blocco
    return count(array_intersect($a, $b)) > 0;
}

/** POST JSON a Google Places con field mask. Ritorna il body o null su errore. */
function ardyPlacesHttpPost(string $url, array $body): ?string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'X-Goog-Api-Key: ' . constant('ARDY_GOOGLE_PLACES_KEY'),
            'X-Goog-FieldMask: ' . ARDY_PLACES_FIELDMASK,
        ],
    ]);
    $res  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) {
        error_log('ARDY PLACES CURL: ' . $err);
        return null;
    }
    if ($code < 200 || $code >= 300) {
        // Non logghiamo il body intero per non rischiare di stampare la chiave in
        // eventuali echo dell'URL; logghiamo solo lo status e un estratto safe.
        error_log("ARDY PLACES HTTP $code");
        return null;
    }
    return $res;
}
