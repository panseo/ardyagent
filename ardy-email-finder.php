<?php
// -----------------------------------------------------------
// ARDY LAB — Email Finder automatico
// Visita i siti dei contatti senza email e tenta di trovarne una
// Esegui da CLI: php ardy-email-finder.php
// -----------------------------------------------------------

require_once __DIR__ . '/ardy-config.php';
require_once __DIR__ . '/ardy-db.php';
require_once __DIR__ . '/ardy-net.php';

// Difesa in profondità: da CLI resta libero (uso previsto); via web deve
// essere autenticato, così un accesso HTTP non protetto non fa girare lo
// scraping né espone i contatti del CRM.
if (php_sapi_name() !== 'cli') {
    require_once __DIR__ . '/ardy-auth.php';
    ardyRequireAuth();
}

$db = ardyDB();

// Prendi contatti con sito ma senza email
$stmt = $db->query("SELECT id, nome, sito FROM outreach_contatti 
                    WHERE (email IS NULL OR email = '') 
                    AND sito IS NOT NULL AND sito != ''
                    ORDER BY categoria, nome");
$contatti = $stmt->fetchAll();

echo "Trovati " . count($contatti) . " contatti con sito ma senza email\n\n";

$trovate = 0;
$nonTrovate = 0;

foreach ($contatti as $c) {
    echo "→ " . $c['nome'] . " (" . $c['sito'] . ")\n";
    
    $email = cercaEmail($c['sito']);
    
    if ($email) {
        $db->prepare("UPDATE outreach_contatti SET email=:email, updated_at=NOW() WHERE id=:id")
           ->execute([':email' => $email, ':id' => $c['id']]);
        echo "  ✓ Email trovata: $email\n";
        $trovate++;
    } else {
        // Prova con /contatti, /contact, /chi-siamo
        $pagine = ['/contatti', '/contact', '/chi-siamo', '/about', '/contattaci', '/info'];
        foreach ($pagine as $p) {
            $url = rtrim($c['sito'], '/') . $p;
            $email = cercaEmail($url);
            if ($email) {
                $db->prepare("UPDATE outreach_contatti SET email=:email, updated_at=NOW() WHERE id=:id")
                   ->execute([':email' => $email, ':id' => $c['id']]);
                echo "  ✓ Email trovata su $p: $email\n";
                $trovate++;
                break;
            }
        }
        if (!$email) {
            echo "  ✗ Non trovata\n";
            $nonTrovate++;
        }
    }
    
    usleep(500000); // 500ms pausa tra richieste
}

echo "\n==============================\n";
echo "Email trovate:     $trovate\n";
echo "Non trovate:       $nonTrovate\n";
echo "==============================\n";

// ============================================================
function cercaEmail(string $url): ?string {
    if (empty($url)) return null;

    // Fetch anti-SSRF: valida host/schema, blocca IP interni, verifica TLS,
    // ri-valida i redirect (vedi ardy-net.php).
    $resp = ardySafeHttpGet($url, 15, 3);
    if ($resp === null) return null;
    $html = $resp['body'];
    $code = $resp['code'];

    if (!$html || $code >= 400) return null;
    
    // Cerca email nel testo (pattern standard)
    // Gestisce anche obfuscation comuni: [at], (at), @, &#64;
    $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5);
    
    // Pattern 1: email standard
    if (preg_match_all('/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/', $html, $m)) {
        foreach ($m[0] as $email) {
            // Escludi email di sistema/immagini/font
            if (preg_match('/\.(png|jpg|gif|svg|woff|ttf|css|js)$/i', $email)) continue;
            if (preg_match('/@(sentry|example|schema|w3|jquery|wordpress|google|facebook)/i', $email)) continue;
            if (strpos($email, '@') === false) continue;
            return strtolower($email);
        }
    }
    
    // Pattern 2: mailto: links
    if (preg_match_all('/mailto:([a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,})/', $html, $m)) {
        if (!empty($m[1][0])) return strtolower($m[1][0]);
    }
    
    // Pattern 3: obfuscation [at] o (at)
    if (preg_match('/([a-zA-Z0-9._%+\-]+)\s*[\[\(]at[\]\)]\s*([a-zA-Z0-9.\-]+\.[a-zA-Z]{2,})/', $html, $m)) {
        return strtolower($m[1] . '@' . $m[2]);
    }
    
    return null;
}
