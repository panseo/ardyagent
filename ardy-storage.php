<?php
// -----------------------------------------------------------
// ARDY LAB — Storage astratto: disco locale O Backblaze B2 (S3-compatibile).
//
// Se in ardy-config.php sono definite le costanti ARDY_B2_* l'app salva i file
// pesanti (STL/CAD, video) su Backblaze B2 e li serve con URL FIRMATI a scadenza
// (download diretto dal bucket, zero banda dal VPS). Senza quelle costanti tutto
// resta su disco com'era: fallback automatico, niente si rompe.
//
// La firma AWS Signature V4 è fatta a mano via curl: niente SDK pesanti. Path-style
// (https://<endpoint>/<bucket>/<key>) per evitare dipendenze dal DNS virtual-host.
//
//   ardyB2Configured()                         → bool: B2 attivo?
//   ardyB2ScritturaAttiva()                    → bool: ci si può ancora SCRIVERE?
//   ardyStoragePutFile(key, path, mime)        → carica un file (streaming) su B2
//   ardyStorageDelete(key)                     → elimina un oggetto da B2
//   ardyStoragePresignedGet(key, ttl, dlName)  → URL firmato per scaricare da B2
//   ardyStorageGet(key)                        → byte di un oggetto (proxy lato server)
//
// L'offload automatico su B2 è SPENTO (26/07/2026): i file nuovi restano su disco e B2
// serve solo all'archiviazione di fine ciclo (ardy-archivia-b2.php). I file già lassù si
// riportano giù con ardy-b2-rimpatrio.php. Vedi TODO-PROSSIMI-TASK.md.
// -----------------------------------------------------------

require_once __DIR__ . '/ardy-config.php';

/** True se TUTTE le costanti B2 sono presenti e non vuote. */
function ardyB2Configured(): bool {
    foreach (['ARDY_B2_KEY_ID', 'ARDY_B2_APP_KEY', 'ARDY_B2_BUCKET', 'ARDY_B2_ENDPOINT', 'ARDY_B2_REGION'] as $c) {
        if (!defined($c) || constant($c) === '' || constant($c) === null) return false;
    }
    return true;
}

/**
 * Offload automatico: SPENTO (deciso 26/07/2026). I file che carichi dalla dash
 * restano sul disco del server, dove la dash e WordPress li leggono senza passare da
 * una richiesta HTTPS verso Backblaze. B2 esce così dal percorso di lavoro: un suo
 * guasto non può più rovinare una pubblicazione.
 *
 * Non è un interruttore da usare tutti i giorni: sta qui perché la scelta sia esplicita
 * e reversibile. Per riaccenderlo: `define('ARDY_B2_OFFLOAD', true);` in ardy-config.php.
 *
 * NB: questo non tocca l'ARCHIVIAZIONE di fine ciclo (ardyStorageArchivia*), che è il
 * ruolo che B2 mantiene — né i file GIÀ su B2, che si continuano a leggere finché non
 * si rimpatriano su disco con ardy-b2-rimpatrio.php.
 */
function ardyB2ScritturaAttiva(): bool {
    return ardyB2Configured() && defined('ARDY_B2_OFFLOAD') && ARDY_B2_OFFLOAD;
}

function ardyB2BaseUrl(): string { return 'https://' . ARDY_B2_ENDPOINT; }

/** URL-encode di un object key preservando gli slash tra i segmenti. */
function ardyB2EncodeKey(string $key): string {
    return implode('/', array_map('rawurlencode', explode('/', ltrim($key, '/'))));
}

/** Chiave di firma SigV4 (derivata da data → region → service → aws4_request). */
function ardyB2SigningKey(string $dateStamp): string {
    $kDate    = hash_hmac('sha256', $dateStamp, 'AWS4' . ARDY_B2_APP_KEY, true);
    $kRegion  = hash_hmac('sha256', ARDY_B2_REGION, $kDate, true);
    $kService = hash_hmac('sha256', 's3', $kRegion, true);
    return hash_hmac('sha256', 'aws4_request', $kService, true);
}

/** URI canonica path-style: /<bucket>/<key> (entrambi URL-encoded). */
function ardyB2CanonicalUri(string $key): string {
    return '/' . ardyB2EncodeKey(ARDY_B2_BUCKET) . '/' . ardyB2EncodeKey($key);
}

/** Header firmati (SigV4) per un PUT. Riusato da put-file (streaming) e put (in RAM). */
function ardyB2SignPut(string $key, string $payloadHash, string $contentType, string $now): array {
    $dateStamp = substr($now, 0, 8);
    $host      = ARDY_B2_ENDPOINT;
    $uri       = ardyB2CanonicalUri($key);
    $scope     = "$dateStamp/" . ARDY_B2_REGION . '/s3/aws4_request';

    $signedHeaders    = 'content-type;host;x-amz-content-sha256;x-amz-date';
    $canonicalHeaders = "content-type:$contentType\nhost:$host\nx-amz-content-sha256:$payloadHash\nx-amz-date:$now\n";
    $canonicalRequest = "PUT\n$uri\n\n$canonicalHeaders\n$signedHeaders\n$payloadHash";
    $stringToSign     = "AWS4-HMAC-SHA256\n$now\n$scope\n" . hash('sha256', $canonicalRequest);
    $signature        = hash_hmac('sha256', $stringToSign, ardyB2SigningKey($dateStamp));
    $auth = 'AWS4-HMAC-SHA256 Credential=' . ARDY_B2_KEY_ID . "/$scope, SignedHeaders=$signedHeaders, Signature=$signature";

    return [ardyB2BaseUrl() . $uri, [
        "Authorization: $auth",
        "Content-Type: $contentType",
        "x-amz-content-sha256: $payloadHash",
        "x-amz-date: $now",
    ]];
}

/**
 * Carica un file su B2 in streaming (niente file intero in RAM: l'hash si calcola
 * con hash_file e il corpo si invia via CURLOPT_INFILE). Ritorna true su HTTP 200.
 *
 * Le due funzioni pubbliche che ci passano sopra hanno regole diverse:
 *   ardyStoragePutFile()      — offload automatico: oggi spento (vedi ardyB2ScritturaAttiva)
 *   ardyStorageArchiviaFile() — archiviazione a fine ciclo: gesto esplicito, passa sempre
 */
function ardyB2PutFileRaw(string $key, string $localPath, string $contentType): bool {
    if (!ardyB2Configured() || !is_file($localPath)) return false;

    $payloadHash = hash_file('sha256', $localPath);
    $size        = filesize($localPath);
    [$url, $headers] = ardyB2SignPut($key, $payloadHash, $contentType, gmdate('Ymd\THis\Z'));

    $fp = fopen($localPath, 'rb');
    if ($fp === false) return false;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_PUT            => true,
        CURLOPT_INFILE         => $fp,
        CURLOPT_INFILESIZE     => $size,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => array_merge($headers, ["Content-Length: $size", 'Expect:']),
        CURLOPT_TIMEOUT        => 600,
        CURLOPT_CONNECTTIMEOUT => 20,
    ]);
    $resp = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    fclose($fp);

    if ($code === 200) return true;
    error_log("ARDY B2 PUT $key fallito: HTTP $code $err " . substr((string) $resp, 0, 500));
    return false;
}

/** Carica byte già in memoria su B2 (per file piccoli: foto compresse). HTTP 200 → true. */
function ardyB2PutRaw(string $key, string $body, string $contentType): bool {
    if (!ardyB2Configured()) return false;

    $payloadHash = hash('sha256', $body);
    [$url, $headers] = ardyB2SignPut($key, $payloadHash, $contentType, gmdate('Ymd\THis\Z'));

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => 'PUT',
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => array_merge($headers, ['Content-Length: ' . strlen($body), 'Expect:']),
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_CONNECTTIMEOUT => 20,
    ]);
    $resp = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($code === 200) return true;
    error_log("ARDY B2 PUT(mem) $key fallito: HTTP $code $err " . substr((string) $resp, 0, 500));
    return false;
}

// ── Le due porte d'ingresso a B2 ────────────────────────────────────────────
// OFFLOAD AUTOMATICO (ardyStoragePut*): l'upload che avviene da solo quando carichi
// un file dalla dash. Oggi è SPENTO — B2 è fuori dal percorso di servizio e nessun file
// "vivo" dipende più da una rilettura dal bucket.
//
// ARCHIVIAZIONE (ardyStorageArchivia*): il gesto esplicito di fine ciclo — progetto a
// CATALOGATO, cliente CONSEGNATO — che deposita su B2 la documentazione di qualcosa che
// è già tutto al suo posto altrove (WordPress, Woo, disco). Non è offload: è un
// deposito di sicurezza, quindi passa anche a offload spento.

function ardyStoragePut(string $key, string $body, string $contentType = 'application/octet-stream'): bool {
    if (!ardyB2ScritturaAttiva()) return false;
    return ardyB2PutRaw($key, $body, $contentType);
}

function ardyStoragePutFile(string $key, string $localPath, string $contentType = 'application/octet-stream'): bool {
    if (!ardyB2ScritturaAttiva()) return false;
    return ardyB2PutFileRaw($key, $localPath, $contentType);
}

function ardyStorageArchivia(string $key, string $body, string $contentType = 'application/octet-stream'): bool {
    return ardyB2PutRaw($key, $body, $contentType);
}

function ardyStorageArchiviaFile(string $key, string $localPath, string $contentType = 'application/octet-stream'): bool {
    return ardyB2PutFileRaw($key, $localPath, $contentType);
}

/** Elimina un oggetto da B2. Ritorna true su 200/204 (o 404: già assente). */
function ardyStorageDelete(string $key): bool {
    if (!ardyB2Configured()) return false;

    $payloadHash = hash('sha256', '');
    $now         = gmdate('Ymd\THis\Z');
    $dateStamp   = substr($now, 0, 8);
    $host        = ARDY_B2_ENDPOINT;
    $uri         = ardyB2CanonicalUri($key);
    $scope       = "$dateStamp/" . ARDY_B2_REGION . '/s3/aws4_request';

    $signedHeaders    = 'host;x-amz-content-sha256;x-amz-date';
    $canonicalHeaders = "host:$host\nx-amz-content-sha256:$payloadHash\nx-amz-date:$now\n";
    $canonicalRequest = "DELETE\n$uri\n\n$canonicalHeaders\n$signedHeaders\n$payloadHash";
    $stringToSign     = "AWS4-HMAC-SHA256\n$now\n$scope\n" . hash('sha256', $canonicalRequest);
    $signature        = hash_hmac('sha256', $stringToSign, ardyB2SigningKey($dateStamp));
    $auth = 'AWS4-HMAC-SHA256 Credential=' . ARDY_B2_KEY_ID . "/$scope, SignedHeaders=$signedHeaders, Signature=$signature";

    $ch = curl_init(ardyB2BaseUrl() . $uri);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => 'DELETE',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            "Authorization: $auth",
            "x-amz-content-sha256: $payloadHash",
            "x-amz-date: $now",
        ],
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_CONNECTTIMEOUT => 20,
    ]);
    $resp = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($code === 200 || $code === 204 || $code === 404) return true;
    error_log("ARDY B2 DELETE $key fallito: HTTP $code $err " . substr((string) $resp, 0, 500));
    return false;
}

/**
 * URL firmato (query-string auth) per scaricare un oggetto direttamente da B2.
 * $ttl = validità in secondi (default 5 min). $downloadName forza il nome del file
 * scaricato via response-content-disposition.
 */
function ardyStoragePresignedGet(string $key, int $ttl = 300, ?string $downloadName = null): string {
    if (!ardyB2Configured()) return '';

    $now       = gmdate('Ymd\THis\Z');
    $dateStamp = substr($now, 0, 8);
    $host      = ARDY_B2_ENDPOINT;
    $uri       = ardyB2CanonicalUri($key);
    $scope     = "$dateStamp/" . ARDY_B2_REGION . '/s3/aws4_request';

    $q = [
        'X-Amz-Algorithm'     => 'AWS4-HMAC-SHA256',
        'X-Amz-Credential'    => ARDY_B2_KEY_ID . '/' . $scope,
        'X-Amz-Date'          => $now,
        'X-Amz-Expires'       => (string) max(1, min($ttl, 604800)),
        'X-Amz-SignedHeaders' => 'host',
    ];
    if ($downloadName !== null && $downloadName !== '') {
        $safe = preg_replace('/[^\w.\- ]+/u', '_', $downloadName) ?: 'file';
        $q['response-content-disposition'] = 'attachment; filename="' . $safe . '"';
    }
    ksort($q, SORT_STRING);
    $canonicalQuery = implode('&', array_map(
        fn($k) => rawurlencode($k) . '=' . rawurlencode($q[$k]),
        array_keys($q)
    ));

    $canonicalHeaders = "host:$host\n";
    $canonicalRequest = "GET\n$uri\n$canonicalQuery\nhost:$host\n\nhost\nUNSIGNED-PAYLOAD";
    $stringToSign     = "AWS4-HMAC-SHA256\n$now\n$scope\n" . hash('sha256', $canonicalRequest);
    $signature        = hash_hmac('sha256', $stringToSign, ardyB2SigningKey($dateStamp));

    return ardyB2BaseUrl() . $uri . '?' . $canonicalQuery . '&X-Amz-Signature=' . $signature;
}

/**
 * Scarica i byte di un oggetto da B2 LATO SERVER (via URL firmato + curl). Serve a
 * fare da proxy same-origin per le immagini: il browser le legge dal nostro dominio
 * (niente CORS sul redirect a Backblaze), ma il file vive comunque su B2. Null su errore.
 */
function ardyStorageGet(string $key): ?string {
    if (!ardyB2Configured()) return null;
    $url = ardyStoragePresignedGet($key, 120);
    if ($url === '') return null;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_CONNECTTIMEOUT => 20,
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($code === 200 && is_string($body) && $body !== '') return $body;
    error_log("ARDY B2 GET $key fallito: HTTP $code $err");
    return null;
}
