<?php
// -----------------------------------------------------------
// ARDY LAB — Area cliente (ardyagent.ardy-lab.it/lavoro/): API pubblica
// Il cliente vede il suo lavoro (fasi, foto, comunicazioni) entrando col codice di
// accesso ARD-XXXX-XXXX che ha già ricevuto. Sostituirà le pagine "lavori in corso"
// su WordPress: vedi ANALISI-AREA-CLIENTE.md. Dal passo 2 (24/11) le fasi nuove
// arrivano solo qui, con le foto sul server (ardy-fasi-foto-lib.php).
//
//   POST {mode:'accedi', codice}  → verifica il codice, imposta il cookie di sessione
//   GET  ?mode=lavoro             → il lavoro del cliente col cookie valido
//   GET  ?mode=foto&f=<file>      → una foto di una fase, solo del cliente del cookie
//   POST {mode:'esci'}            → cancella il cookie
//
// PUBBLICO di proposito (fuori dal Basic Auth staff): il controllo è il codice, poi
// il cookie firmato. Nessuna chat qui (decisione 23/11): le domande vanno su
// WhatsApp, dove Sole riconosce il cliente dal telefono.
// -----------------------------------------------------------

require_once __DIR__ . '/ardy-config.php';
require_once __DIR__ . '/ardy-db.php';
require_once __DIR__ . '/ardy-net.php';   // ardyClientIp() (IP reale dietro Cloudflare)
require_once __DIR__ . '/ardy-fasi-foto-lib.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

const AREA_COOKIE      = 'ardy_area';
const AREA_DURATA      = 180 * 86400;   // il lavoro resta consultabile anche dopo la consegna
const AREA_WA_NUMERO   = '393793756437';
const AREA_RL_FINESTRA = 600;           // 10 minuti
const AREA_RL_MAX      = 15;            // tentativi FALLITI per IP nella finestra

// Etichette per il cliente: gli stati interni della pipeline non vanno mostrati così
// come sono (PERSO, ACCONTO… parlano a Michela, non a chi aspetta il suo mobile).
const AREA_STATI = [
    'SOPRALLUOGO'    => 'Sopralluogo in programma',
    'PREVENTIVO'     => 'Preventivo in preparazione',
    'ACCONTO'        => 'In attesa di iniziare',
    'RITIRATI'       => 'Il tuo pezzo è in laboratorio',
    'IN_LAVORAZIONE' => 'In lavorazione',
    'COMPLETATO'     => 'Pronto per la consegna',
    'CONSEGNATO'     => 'Consegnato',
    'PAGATO'         => 'Consegnato',
];

function areaEsci(array $dati, int $code = 200): void {
    http_response_code($code);
    echo json_encode($dati, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}

/** Chiave HMAC del cookie. Fail-closed: senza segreto l'area non apre a nessuno. */
function areaChiave(): string {
    if (!defined('WA_LOOKUP_SECRET') || WA_LOOKUP_SECRET === '') {
        error_log('ARDY AREA CLIENTE: WA_LOOKUP_SECRET mancante, area chiusa');
        areaEsci(['success' => false, 'error' => 'Area momentaneamente non disponibile'], 503);
    }
    // Prefisso di dominio: la stessa chiave firma anche i link lead, e una firma valida
    // per quelli non deve mai valere come cookie dell'area (e viceversa).
    return 'area-cliente|' . WA_LOOKUP_SECRET;
}

/** Cookie = base64(session_id) . scadenza . hmac. Il codice non ci finisce mai dentro. */
function areaFirmaCookie(string $sessionId): string {
    $exp  = time() + AREA_DURATA;
    $base = rtrim(strtr(base64_encode($sessionId), '+/', '-_'), '=') . '.' . $exp;
    return $base . '.' . hash_hmac('sha256', $base, areaChiave());
}

/** session_id del cliente dal cookie, o '' se assente/scaduto/manomesso. */
function areaLeggiCookie(): string {
    $c = (string) ($_COOKIE[AREA_COOKIE] ?? '');
    if (substr_count($c, '.') !== 2) return '';
    [$b64, $exp, $sig] = explode('.', $c);
    if (!hash_equals(hash_hmac('sha256', $b64 . '.' . $exp, areaChiave()), $sig)) return '';
    if ((int) $exp < time()) return '';
    return (string) base64_decode(strtr($b64, '-_', '+/'), true);
}

function areaImpostaCookie(string $valore, int $scadenza): void {
    setcookie(AREA_COOKIE, $valore, [
        'expires'  => $scadenza,
        'path'     => '/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/** Stessa normalizzazione di ardy_normalizza_codice() in ardy-proxy.php. */
function areaNormalizzaCodice(string $raw): string {
    $s = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $raw));
    return preg_match('/^ARD([A-Z0-9]{4})([A-Z0-9]{4})$/', $s, $m) ? 'ARD-' . $m[1] . '-' . $m[2] : '';
}

/**
 * Solo URL http(s). Le foto delle fasi dal passo 2 hanno l'indirizzo interno della dash
 * (dietro la password dello staff): qui diventano quello dell'area, che le serve solo al
 * cliente giusto. Quelle delle fasi vecchie stanno su WordPress e passano com'erano.
 */
function areaUrlValidi($json, string $sid): array {
    $out = [];
    foreach ((json_decode((string) $json, true) ?: []) as $u) {
        if (!is_string($u) || !preg_match('#^https?://#i', $u)) continue;
        if (strpos($u, FASI_FOTO_ENDPOINT . '?') === 0) {
            $int = fasiFotoDaUrl($u);
            // Un indirizzo interno di un'altra sessione non deve mai comparire qui.
            if ($int === null || $int['session'] !== fasiFotoSessione($sid)) continue;
            $u = '/ardy-area-cliente-api.php?mode=foto&f=' . rawurlencode($int['file']);   // assoluto: la pagina sta su /lavoro/
        }
        $out[] = $u;
    }
    return $out;
}

$in   = $_SERVER['REQUEST_METHOD'] === 'POST' ? (json_decode(file_get_contents('php://input'), true) ?: []) : [];
$mode = (string) ($_SERVER['REQUEST_METHOD'] === 'GET' ? ($_GET['mode'] ?? '') : ($in['mode'] ?? ''));

if ($mode === 'esci') {
    areaImpostaCookie('', time() - 3600);
    areaEsci(['success' => true]);
}

try {
    $db = ardyDB();

    if ($mode === 'accedi') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') areaEsci(['success' => false, 'error' => 'Metodo non valido'], 405);

        // Anti forza bruta, come ardy-verify-client.php: per IP reale, contano solo i
        // tentativi falliti. Con ~40 bit di codice, 15 tentativi ogni 10 minuti rendono
        // impraticabile indovinarne uno anche ruotando molti IP.
        $rlDir = (defined('ARDY_RATE_LIMIT_DIR') && ARDY_RATE_LIMIT_DIR) ? rtrim(ARDY_RATE_LIMIT_DIR, '/') . '/' : rtrim(sys_get_temp_dir(), '/') . '/';
        if (!is_dir($rlDir)) @mkdir($rlDir, 0755, true);
        $rlFile = $rlDir . 'ardy_area_' . md5(ardyClientIp()) . '.json';
        $now    = time();
        $tries  = is_readable($rlFile) ? (json_decode((string) @file_get_contents($rlFile), true) ?: []) : [];
        $tries  = array_values(array_filter($tries, fn($t) => $t > $now - AREA_RL_FINESTRA));
        if (count($tries) >= AREA_RL_MAX) areaEsci(['success' => false, 'error' => 'Troppi tentativi. Riprova tra qualche minuto.'], 429);

        $codice = areaNormalizzaCodice((string) ($in['codice'] ?? ''));
        $sid = '';
        if ($codice !== '') {
            $st = $db->prepare("SELECT session_id FROM clienti WHERE codice_accesso = ? AND deleted_at IS NULL LIMIT 1");
            $st->execute([$codice]);
            $sid = (string) ($st->fetchColumn() ?: '');
        }
        if ($sid === '') {
            $tries[] = $now;
            @file_put_contents($rlFile, json_encode($tries));
            areaEsci(['success' => false, 'error' => 'Codice non riconosciuto. Controlla di averlo scritto bene, o scrivici su WhatsApp.']);
        }
        areaImpostaCookie(areaFirmaCookie($sid), time() + AREA_DURATA);
        areaEsci(['success' => true]);
    }

    if ($mode === 'foto') {
        // La sessione viene dal cookie, mai dalla richiesta: un cliente non può chiedere
        // le foto di un altro nemmeno conoscendone il nome file.
        $sid = areaLeggiCookie();
        $f   = (string) ($_GET['f'] ?? '');
        if ($sid === '' || !fasiFotoNomeValido($f)) { http_response_code(403); exit(); }
        $path = fasiFotoDir($sid) . $f;
        if (!is_file($path)) { http_response_code(404); exit(); }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) { http_response_code(403); exit(); }
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: private, max-age=86400');   // sovrascrive il no-store dell'API
        readfile($path);
        exit();
    }

    if ($mode === 'lavoro') {
        $sid = areaLeggiCookie();
        if ($sid === '') areaEsci(['success' => false, 'accesso' => false]);

        $st = $db->prepare("SELECT nome, mobile, servizio, stato FROM clienti WHERE session_id = ? AND deleted_at IS NULL LIMIT 1");
        $st->execute([$sid]);
        $c = $st->fetch(PDO::FETCH_ASSOC);
        if (!$c) {
            // Scheda eliminata dopo l'accesso: il cookie non vale più.
            areaImpostaCookie('', time() - 3600);
            areaEsci(['success' => false, 'accesso' => false]);
        }

        // Solo fasi PUBBLICATE: le bozze di Michela non sono ancora per il cliente.
        $fs = $db->prepare(
            "SELECT fase_nome, fase_tipo, testo_generato, foto_urls, video_urls, created_at
               FROM fasi
              WHERE session_id = ? AND COALESCE(stato, 'pubblicata') = 'pubblicata'
           ORDER BY COALESCE(ordine, 999999) ASC, created_at ASC"
        );
        $fs->execute([$sid]);
        $fasi = [];
        foreach ($fs->fetchAll(PDO::FETCH_ASSOC) as $f) {
            $fasi[] = [
                'titolo'        => trim((string) $f['fase_nome']),
                'comunicazione' => ($f['fase_tipo'] ?? '') === 'comunicazione',
                'testo'         => trim((string) $f['testo_generato']),
                'foto'          => areaUrlValidi($f['foto_urls'], $sid),
                'video'         => areaUrlValidi($f['video_urls'], $sid),
                'data'          => substr((string) $f['created_at'], 0, 10),
            ];
        }

        // Nome di battesimo soltanto: è un saluto, non serve altro dato personale.
        $nome = trim(explode(' ', trim((string) $c['nome']))[0] ?? '');
        areaEsci([
            'success'  => true,
            'nome'     => $nome,
            'mobile'   => trim((string) $c['mobile']),
            'servizio' => trim((string) $c['servizio']),
            'stato'    => AREA_STATI[strtoupper((string) $c['stato'])] ?? '',
            'fasi'     => $fasi,
            'whatsapp' => AREA_WA_NUMERO,
        ]);
    }

    areaEsci(['success' => false, 'error' => 'Richiesta non valida'], 400);

} catch (PDOException $e) {
    error_log('ARDY AREA CLIENTE: ' . $e->getMessage());
    areaEsci(['success' => false, 'error' => 'Errore di sistema, riprova fra poco'], 500);
}
