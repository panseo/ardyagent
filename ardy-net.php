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

// -----------------------------------------------------------
// IP DEL CLIENT (anti-spoofing del rate-limit)
//
// Dietro Cloudflare, l'IP reale dell'utente arriva in CF-Connecting-IP /
// X-Forwarded-For, mentre REMOTE_ADDR è l'edge Cloudflare. Ma questi header
// sono inviabili da CHIUNQUE: chi colpisce l'origin direttamente (senza
// passare da Cloudflare) può falsificarli e ruotarli a piacere per azzerare
// il rate-limit, e quindi far costare richieste API a pagamento.
// Soluzione: fidarsi degli header SOLO se REMOTE_ADDR è un IP Cloudflare noto;
// altrimenti usare REMOTE_ADDR (non falsificabile a livello TCP).
// -----------------------------------------------------------

/** True se $ip (v4 o v6) appartiene alla rete CIDR $cidr (es. "104.16.0.0/13"). */
function ardyIpInCidr(string $ip, string $cidr): bool {
    if (strpos($cidr, '/') === false) return false;
    [$subnet, $bits] = explode('/', $cidr, 2);
    $bits = (int) $bits;

    $ipBin     = @inet_pton($ip);
    $subnetBin = @inet_pton($subnet);
    if ($ipBin === false || $subnetBin === false) return false;
    // Stessa famiglia (lunghezza in byte uguale: 4 = IPv4, 16 = IPv6).
    if (strlen($ipBin) !== strlen($subnetBin)) return false;

    $bytes = intdiv($bits, 8);
    $rem   = $bits % 8;

    if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($subnetBin, 0, $bytes)) {
        return false;
    }
    if ($rem === 0) return true;

    $mask    = chr(0xff << (8 - $rem) & 0xff);
    $ipByte  = ord($ipBin[$bytes])     & ord($mask);
    $subByte = ord($subnetBin[$bytes]) & ord($mask);
    return $ipByte === $subByte;
}

/** Range IP pubblicati da Cloudflare (https://www.cloudflare.com/ips/). */
function ardyCloudflareRanges(): array {
    return [
        // IPv4
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
        '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
        '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        // IPv6
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
        '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
    ];
}

/** True se $ip è un edge Cloudflare noto. */
function ardyIsCloudflareIp(string $ip): bool {
    foreach (ardyCloudflareRanges() as $cidr) {
        if (ardyIpInCidr($ip, $cidr)) return true;
    }
    return false;
}

/**
 * IP reale del client, a prova di spoofing del rate-limit.
 * Si fida di CF-Connecting-IP / X-Forwarded-For SOLO se la connessione arriva
 * da un edge Cloudflare noto; in caso contrario ritorna REMOTE_ADDR.
 */
function ardyClientIp(): string {
    $remote = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    if ($remote !== 'unknown' && ardyIsCloudflareIp($remote)) {
        // CF-Connecting-IP è il singolo IP reale impostato da Cloudflare.
        $cf = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '';
        if ($cf !== '' && filter_var($cf, FILTER_VALIDATE_IP)) {
            return $cf;
        }
        // Fallback: primo IP valido di X-Forwarded-For.
        $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if ($xff !== '') {
            foreach (explode(',', $xff) as $cand) {
                $cand = trim($cand);
                if (filter_var($cand, FILTER_VALIDATE_IP)) return $cand;
            }
        }
    }

    return $remote;
}

/**
 * Mette un `.htaccess` "no-PHP" in una cartella di upload, così un file
 * caricato (es. una finta foto `.php`) non può essere eseguito dal server.
 * Disabilita SOLO gli script: pdf/mp4/foto restano serviti normalmente.
 * Idempotente: scrive il file una sola volta.
 */
function ardyHardenUploadDir(string $dir): void {
    if ($dir === '' || !is_dir($dir)) return;
    $htaccess = rtrim($dir, '/') . '/.htaccess';
    if (file_exists($htaccess)) return;

    $rules = <<<HT
# Generato da ardyHardenUploadDir() — niente esecuzione di script in questa cartella.
# I file leciti (pdf, mp4, immagini) restano serviti; solo gli script sono bloccati.
RemoveHandler .php .php3 .php4 .php5 .php7 .php8 .phtml .phar .cgi .pl .py
RemoveType .php .php3 .php4 .php5 .php7 .php8 .phtml .phar
<FilesMatch "\.(?:php[0-9]?|phtml|phps|phar|cgi|pl|py|sh|asp|aspx|jsp)\$">
    Order Allow,Deny
    Deny from all
</FilesMatch>
HT;
    @file_put_contents($htaccess, $rules);
}

/**
 * Comprime una foto in upload: ridimensiona se il lato lungo supera $maxSide e
 * ricomprime nello stesso formato, per risparmiare spazio su disco (server condiviso
 * 200GB) e peso quando l'immagine viene rimandata a Claude. Ritorna i byte compressi,
 * oppure i byte ORIGINALI se la compressione non conviene o non è possibile (GD assente,
 * GIF — spesso animate, immagine non decodificabile, risultato non più piccolo).
 *
 * @param string $raw  byte grezzi dell'immagine (già base64-decodificati)
 * @param string $mime tipo MIME reale (image/jpeg|png|webp|gif)
 */
function ardyCompressImage(string $raw, string $mime, int $maxSide = 2000, int $quality = 82): string {
    if (!function_exists('imagecreatefromstring')) return $raw; // GD non disponibile
    if ($mime === 'image/gif') return $raw;                      // GIF: non toccare (animazioni)

    $img = @imagecreatefromstring($raw);
    if ($img === false) return $raw;
    $w = imagesx($img);
    $h = imagesy($img);
    if ($w < 1 || $h < 1) { imagedestroy($img); return $raw; }

    // Ridimensiona se serve, preservando le proporzioni
    $scale = min(1.0, $maxSide / max($w, $h));
    if ($scale < 1.0) {
        $nw  = max(1, (int)round($w * $scale));
        $nh  = max(1, (int)round($h * $scale));
        $dst = imagecreatetruecolor($nw, $nh);
        if ($mime === 'image/png' || $mime === 'image/webp') {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            $tr = imagecolorallocatealpha($dst, 0, 0, 0, 127);
            imagefilledrectangle($dst, 0, 0, $nw, $nh, $tr);
        }
        imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($img);
        $img = $dst;
    }

    // Ricodifica nello stesso formato
    ob_start();
    $ok = false;
    switch ($mime) {
        case 'image/png':
            imagesavealpha($img, true);
            $ok = imagepng($img, null, 7);          // livello compressione 0-9
            break;
        case 'image/webp':
            if (function_exists('imagewebp')) $ok = imagewebp($img, null, $quality);
            break;
        default: // image/jpeg
            $ok = imagejpeg($img, null, $quality);
    }
    $out = ob_get_clean();
    imagedestroy($img);

    if (!$ok || !is_string($out) || $out === '') return $raw;
    return (strlen($out) < strlen($raw)) ? $out : $raw; // tieni il più piccolo
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
