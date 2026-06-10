<?php
// -----------------------------------------------------------
// ARDY LAB — Fetch HTTP sicuro (anti-SSRF)
//
// Gli URL visitati dal server (siti dei contatti in ardy-email-finder,
// foto_urls dei reel in ardy-crea-reel) provengono dal DB e potrebbero
// puntare a servizi interni, loopback o all'endpoint metadata cloud
// (169.254.169.254). Qui validiamo schema + host, blocchiamo gli IP
// privati/riservati, limitiamo i protocolli a http(s), riattiviamo la
// verifica TLS e ri-validiamo ogni redirect.
// -----------------------------------------------------------

/** True se l'IP è instradabile pubblicamente (no privati/riservati/loopback/link-local). */
function ardyIpIsPublic(string $ip): bool {
    return filter_var(
        $ip,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
    ) !== false;
}

/** Risolve l'host e verifica che TUTTI gli IP (A + AAAA) siano pubblici. */
function ardyHostIsPublic(string $host): bool {
    $host = trim($host, " \t\n\r\0\x0B[]"); // rimuovi eventuali bracket IPv6
    if ($host === '') return false;

    // Host già in forma di IP: validalo direttamente.
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return ardyIpIsPublic($host);
    }

    $ips = [];
    $a = @gethostbynamel($host);
    if (is_array($a)) $ips = array_merge($ips, $a);
    $aaaa = @dns_get_record($host, DNS_AAAA);
    if (is_array($aaaa)) {
        foreach ($aaaa as $r) {
            if (!empty($r['ipv6'])) $ips[] = $r['ipv6'];
        }
    }

    if (!$ips) return false;                 // host non risolvibile → blocca
    foreach ($ips as $ip) {
        if (!ardyIpIsPublic($ip)) return false; // anche un solo IP interno → blocca
    }
    return true;
}

/** Normalizza e valida un URL pubblico http(s). Ritorna l'URL o null se non sicuro. */
function ardyValidatePublicUrl(string $url): ?string {
    $url = trim($url);
    if ($url === '') return null;
    if (!preg_match('#^https?://#i', $url)) $url = 'https://' . $url;

    $parts = parse_url($url);
    if (!$parts || empty($parts['scheme']) || empty($parts['host'])) return null;
    if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) return null;
    if (!ardyHostIsPublic($parts['host'])) return null;

    return $url;
}

/**
 * GET HTTP sicuro: valida l'URL iniziale e ogni redirect, limita protocolli e
 * dimensione, verifica il TLS. Ritorna ['body'=>string,'code'=>int] o null.
 */
function ardySafeHttpGet(string $url, int $timeout = 15, int $maxRedirects = 3, int $maxBytes = 0): ?array {
    $current = ardyValidatePublicUrl($url);
    if ($current === null) return null;

    for ($i = 0; $i <= $maxRedirects; $i++) {
        $ch = curl_init($current);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => false,   // i redirect li gestiamo noi, validandoli
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; ArdyBot/1.0)',
        ]);
        if (defined('CURLOPT_PROTOCOLS')) {
            curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        }
        if ($maxBytes > 0) {
            curl_setopt($ch, CURLOPT_NOPROGRESS, false);
            curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, function ($c, $dt, $dn) use ($maxBytes) {
                return ($dn > $maxBytes) ? 1 : 0; // aborta se supera il limite
            });
        }
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $next = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        curl_close($ch);

        if ($body === false) return null;

        if ($code >= 300 && $code < 400 && $next) {
            $validated = ardyValidatePublicUrl($next);
            if ($validated === null) return null; // redirect verso host non pubblico
            $current = $validated;
            continue;
        }
        return ['body' => $body, 'code' => $code];
    }
    return null; // troppi redirect
}
