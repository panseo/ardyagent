<?php
// -----------------------------------------------------------
// ARDY LAB — Crea Reel video (MP4 9:16) da tutte le fasi pubblicate
// Monta uno slideshow verticale 1080x1920 con FFmpeg a partire dalle
// foto salvate nella tabella `fasi`, con musica royalty-free scelta.
// -----------------------------------------------------------

date_default_timezone_set('Europe/Rome');
set_time_limit(600);

require_once __DIR__ . '/ardy-config.php';
require_once __DIR__ . '/ardy-db.php';

header('Access-Control-Allow-Origin: https://ardyagent.ardy-lab.it');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

// -- Parametri --------------------------------------------------------------
const FFMPEG       = '/usr/local/bin/ffmpeg';
const SITE_URL     = 'https://ardyagent.ardy-lab.it';
const REEL_W       = 1080;
const REEL_H       = 1920;
const SEC_PER_FOTO = 2.5;     // durata di ogni foto
const SEC_TITOLO   = 3.5;     // durata slide-titolo iniziale
const SEC_FINALE   = 4.0;     // durata slide finale "Prima -> Dopo"
const MAX_FOTO     = 40;      // tetto di sicurezza

function fail(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit();
}

// -- Input (HTTP o CLI per test) -------------------------------------------
$isCli = (PHP_SAPI === 'cli');
if ($isCli) {
    // uso: php ardy-crea-reel.php SESSION_ID [musica] [mobile]
    $input = [
        'session_id' => $argv[1] ?? '',
        'musica'     => $argv[2] ?? 'nessuna',
        'mobile'     => $argv[3] ?? '',
    ];
} else {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
}
$sessionId = preg_replace('/[^a-zA-Z0-9_\-]/', '', $input['session_id'] ?? '');
$musica    = $input['musica'] ?? '';   // nome file, 'casuale' oppure '' / 'nessuna'
$mobile    = trim($input['mobile'] ?? '');

if ($sessionId === '') fail('session_id mancante');

if (!is_file(FFMPEG)) fail('FFmpeg non trovato sul server', 500);

// -- Raccogli le foto dalle fasi -------------------------------------------
try {
    $db   = ardyDB();
    $stmt = $db->prepare("SELECT fase_nome, foto_urls FROM fasi WHERE session_id = ? ORDER BY created_at ASC");
    $stmt->execute([$sessionId]);
    $rows = $stmt->fetchAll();
    if ($mobile === '') {
        $st2 = $db->prepare("SELECT mobile FROM clienti WHERE session_id = ? LIMIT 1");
        $st2->execute([$sessionId]);
        $mobile = trim((string)($st2->fetchColumn() ?: ''));
    }
} catch (PDOException $e) {
    error_log('ARDY REEL DB ERROR: ' . $e->getMessage());
    fail('Errore database', 500);
}

$items = [];   // [{url, fase}] mantenendo l'abbinamento foto -> fase
foreach ($rows as $r) {
    $urls = json_decode($r['foto_urls'] ?? '[]', true) ?: [];
    foreach ($urls as $u) {
        if (is_string($u) && $u !== '') {
            $items[] = ['url' => $u, 'fase' => trim($r['fase_nome'] ?? '')];
        }
    }
}
if (count($items) < 2) fail('Servono almeno 2 foto pubblicate per creare il reel');
if (count($items) > MAX_FOTO) $items = array_slice($items, 0, MAX_FOTO);

// Font per le didascalie: prima assets/fonts/, poi font di sistema.
// Se nessuno è disponibile, il reel viene creato senza testo.
function trovaFont(): ?string {
    foreach (glob(__DIR__ . '/assets/fonts/*.{ttf,otf}', GLOB_BRACE) ?: [] as $f) return $f;
    $sys = [
        '/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/dejavu-sans-fonts/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/liberation/LiberationSans-Bold.ttf',
        '/usr/share/fonts/liberation-sans-fonts/LiberationSans-Bold.ttf',
        '/usr/share/fonts/google-noto/NotoSans-Bold.ttf',
    ];
    foreach ($sys as $f) if (is_file($f)) return $f;
    return null;
}
$font = trovaFont();
// Le scritte si disegnano con GD (il drawtext di FFmpeg non è sempre incluso
// nei build statici). Servono GD + supporto FreeType per i font TTF.
$gdOk = $font && function_exists('imagettftext') && function_exists('imagecreatefromjpeg');

// -- Cartelle di lavoro -----------------------------------------------------
$reelsDir = __DIR__ . '/reels/';
if (!is_dir($reelsDir) && !mkdir($reelsDir, 0755, true) && !is_dir($reelsDir)) {
    fail('Impossibile creare la cartella reels', 500);
}
$tmpDir = $reelsDir . 'tmp_' . $sessionId . '_' . uniqid() . '/';
if (!mkdir($tmpDir, 0755, true)) fail('Impossibile creare cartella temporanea', 500);

function pulisci(string $dir): void {
    foreach (glob($dir . '*') as $f) { if (is_file($f)) @unlink($f); }
    @rmdir($dir);
}

// -- Scarica e normalizza ogni foto a 1080x1920 ----------------------------
$normFiles  = [];
$usedItems  = [];   // gli item effettivamente elaborati (per prima/dopo)
$idx = 0;
foreach ($items as $it) {
    $url = $it['url'];
    $bin = @file_get_contents($url);
    if ($bin === false || strlen($bin) < 100) continue;
    $src = $tmpDir . 'src_' . $idx . '.img';
    file_put_contents($src, $bin);

    $norm   = $tmpDir . sprintf('norm_%03d.jpg', $idx);
    $filter = '[0:v]scale=' . REEL_W . ':' . REEL_H . ':force_original_aspect_ratio=decrease[fg];'
            . '[0:v]scale=' . REEL_W . ':' . REEL_H . ':force_original_aspect_ratio=increase,'
            . 'crop=' . REEL_W . ':' . REEL_H . ',boxblur=20:2[bg];'
            . '[bg][fg]overlay=(W-w)/2:(H-h)/2,setsar=1';
    $cmd = FFMPEG . ' -y -i ' . escapeshellarg($src)
         . ' -filter_complex ' . escapeshellarg($filter)
         . ' -frames:v 1 -q:v 2 ' . escapeshellarg($norm) . ' 2>&1';
    exec($cmd, $o, $rc);
    @unlink($src);
    if ($rc === 0 && is_file($norm)) {
        // Didascalia della fase disegnata con GD (in basso)
        if ($gdOk && $it['fase'] !== '') gdCaption($norm, $it['fase'], $font);
        $normFiles[] = $norm; $usedItems[] = $it; $idx++;
    }
}

if (count($normFiles) < 2) { pulisci($tmpDir); fail('Non sono riuscito a elaborare abbastanza foto', 500); }

$logo = __DIR__ . '/assets/logo.png';

// -- Slide-titolo iniziale (nome mobile + logo su sfondo sfocato) -----------
$titleSlide = slideTitolo($tmpDir, $normFiles[0], $logo, $font, $mobile);

// -- Slide finale "Prima -> Dopo" (prima e ultima foto + logo) -------------
$finalSlide = null;
$primaBin = @file_get_contents($usedItems[0]['url']);
$dopoBin  = @file_get_contents(end($usedItems)['url']);
if ($primaBin !== false && $dopoBin !== false) {
    $primaSrc = $tmpDir . 'prima.img';
    $dopoSrc  = $tmpDir . 'dopo.img';
    file_put_contents($primaSrc, $primaBin);
    file_put_contents($dopoSrc, $dopoBin);
    $finalSlide = slideFinale($tmpDir, $primaSrc, $dopoSrc, $logo, $font);
}

// -- Sequenza completa con durate per-slide --------------------------------
$slides = [];
if ($titleSlide) $slides[] = ['f' => $titleSlide, 'd' => SEC_TITOLO];
foreach ($normFiles as $nf) $slides[] = ['f' => $nf, 'd' => SEC_PER_FOTO];
if ($finalSlide) $slides[] = ['f' => $finalSlide, 'd' => SEC_FINALE];

$durataTot = 0.0;
foreach ($slides as $s) $durataTot += $s['d'];

// -- Step 1: una mini-clip video per ogni slide (metodo robusto) -----------
// Trasformare ogni immagine in una clip e poi concatenarle (stessi codec)
// è molto più affidabile del concat di immagini, che a volte non avanza.
$clipLines = '';
foreach ($slides as $k => $s) {
    $clip = $tmpDir . sprintf('clip_%03d.mp4', $k);
    $cmd = FFMPEG . ' -y -loop 1 -i ' . escapeshellarg($s['f'])
         . ' -t ' . $s['d'] . ' -r 30'
         . ' -vf ' . escapeshellarg('scale=' . REEL_W . ':' . REEL_H . ',format=yuv420p,setsar=1')
         . ' -c:v libx264 -preset veryfast '
         . escapeshellarg($clip) . ' 2>&1';
    exec($cmd, $oc, $rcc);
    if ($rcc !== 0 || !is_file($clip)) {
        error_log('ARDY REEL CLIP ERROR: ' . implode("\n", $oc));
        pulisci($tmpDir);
        fail('Errore creazione clip video', 500);
    }
    $clipLines .= "file '" . $clip . "'\n";
}
$listFile = $tmpDir . 'list.txt';
file_put_contents($listFile, $clipLines);

// -- Step 2: concatena le clip (copia, niente riconversione) ---------------
$videoRaw = $tmpDir . 'raw.mp4';
$cmd = FFMPEG . ' -y -f concat -safe 0 -i ' . escapeshellarg($listFile)
     . ' -c copy ' . escapeshellarg($videoRaw) . ' 2>&1';
exec($cmd, $o1, $rc1);
if ($rc1 !== 0 || !is_file($videoRaw)) {
    error_log('ARDY REEL CONCAT ERROR: ' . implode("\n", $o1));
    pulisci($tmpDir);
    fail('Errore nel montaggio video', 500);
}

// -- Risolvi la traccia musicale -------------------------------------------
$musicDir  = __DIR__ . '/assets/reel-music/';
$musicPath = null;
if ($musica !== '' && strtolower($musica) !== 'nessuna') {
    if (strtolower($musica) === 'casuale') {
        $tracce = glob($musicDir . '*.{mp3,m4a,aac,wav}', GLOB_BRACE) ?: [];
        if ($tracce) $musicPath = $tracce[array_rand($tracce)];
    } else {
        $safe = basename($musica); // evita path traversal
        if (is_file($musicDir . $safe)) $musicPath = $musicDir . $safe;
    }
}

// -- Step 3: fade in/out video + audio (se presente) -----------------------
$finalName = 'reel_' . $sessionId . '_' . date('Ymd_His') . '.mp4';
$finalPath = $reelsDir . $finalName;
$fadeOut   = max(0, $durataTot - 1);
$vf        = 'fade=t=in:st=0:d=0.5,fade=t=out:st=' . $fadeOut . ':d=1';

if ($musicPath) {
    $afOut = max(0, $durataTot - 2);
    $cmd = FFMPEG . ' -y -i ' . escapeshellarg($videoRaw)
         . ' -stream_loop -1 -i ' . escapeshellarg($musicPath)
         . ' -map 0:v -map 1:a -vf ' . escapeshellarg($vf)
         . ' -c:v libx264 -preset veryfast -pix_fmt yuv420p -c:a aac -b:a 192k -shortest'
         . ' -af ' . escapeshellarg('afade=t=out:st=' . $afOut . ':d=2')
         . ' -movflags +faststart ' . escapeshellarg($finalPath) . ' 2>&1';
} else {
    $cmd = FFMPEG . ' -y -i ' . escapeshellarg($videoRaw)
         . ' -vf ' . escapeshellarg($vf)
         . ' -c:v libx264 -preset veryfast -pix_fmt yuv420p'
         . ' -movflags +faststart ' . escapeshellarg($finalPath) . ' 2>&1';
}
exec($cmd, $o2, $rc2);
if ($rc2 !== 0 || !is_file($finalPath)) {
    error_log('ARDY REEL FINAL ERROR: ' . implode("\n", $o2));
    pulisci($tmpDir);
    fail('Errore nella finalizzazione del reel', 500);
}

pulisci($tmpDir);

if (!is_file($finalPath)) fail('Reel non generato', 500);

echo json_encode([
    'success'  => true,
    'reel_url' => SITE_URL . '/reels/' . $finalName,
    'foto'     => count($normFiles),
    'durata'   => round($durataTot, 1),
    'musica'   => $musicPath ? basename($musicPath) : null,
]);

// ============================================================
// FUNZIONI SLIDE
// ============================================================

// GD disponibile con supporto FreeType?
function gdOk(?string $font): bool {
    return $font && function_exists('imagettftext') && function_exists('imagecreatefromjpeg');
}

// Spezza il testo su più righe in base alla larghezza max in pixel.
function gdWrap(string $t, string $font, int $size, int $maxW): array {
    $parole = preg_split('/\s+/', trim($t));
    $linee = []; $cur = '';
    foreach ($parole as $p) {
        $try = $cur === '' ? $p : "$cur $p";
        $bb  = imagettfbbox($size, 0, $font, $try);
        if (($bb[2] - $bb[0]) > $maxW && $cur !== '') { $linee[] = $cur; $cur = $p; }
        else $cur = $try;
    }
    if ($cur !== '') $linee[] = $cur;
    return $linee;
}

// Didascalia in basso su fascia semitrasparente (per le foto delle fasi).
function gdCaption(string $jpg, string $testo, string $font): void {
    $im = @imagecreatefromjpeg($jpg);
    if (!$im) return;
    imagealphablending($im, true);
    $W = imagesx($im); $H = imagesy($im);
    $size = 38; $lh = $size + 16; $padY = 26;
    $linee  = gdWrap($testo, $font, $size, $W - 200);
    $blockH = count($linee) * $lh;
    $top    = $H - 300;
    $band   = imagecolorallocatealpha($im, 0, 0, 0, 80);
    imagefilledrectangle($im, 0, $top - $padY, $W, $top + $blockH + $padY, $band);
    $white  = imagecolorallocate($im, 255, 255, 255);
    $y = $top + $size;
    foreach ($linee as $ln) {
        $bb = imagettfbbox($size, 0, $font, $ln);
        $x  = (int)(($W - ($bb[2] - $bb[0])) / 2);
        imagettftext($im, $size, 0, $x, $y, $white, $font, $ln);
        $y += $lh;
    }
    imagejpeg($im, $jpg, 92);
    imagedestroy($im);
}

// Titolo grande centrato (per la slide iniziale col nome del mobile).
function gdTitolo(string $jpg, string $testo, string $font): void {
    $im = @imagecreatefromjpeg($jpg);
    if (!$im) return;
    imagealphablending($im, true);
    $W = imagesx($im); $H = imagesy($im);
    $size = 56; $lh = $size + 20;
    $linee  = gdWrap(mb_strtoupper($testo), $font, $size, $W - 220);
    $blockH = count($linee) * $lh;
    $y = (int)(($H - $blockH) / 2) + $size + 120;
    $white = imagecolorallocate($im, 255, 255, 255);
    foreach ($linee as $ln) {
        $bb = imagettfbbox($size, 0, $font, $ln);
        $x  = (int)(($W - ($bb[2] - $bb[0])) / 2);
        // ombra leggera per leggibilità
        $sh = imagecolorallocatealpha($im, 0, 0, 0, 60);
        imagettftext($im, $size, 0, $x + 3, $y + 3, $sh, $font, $ln);
        imagettftext($im, $size, 0, $x, $y, $white, $font, $ln);
        $y += $lh;
    }
    imagejpeg($im, $jpg, 92);
    imagedestroy($im);
}

// Etichetta corta con box (PRIMA / DOPO sulla slide finale).
function gdLabel(string $jpg, string $testo, string $font, int $x, int $y): void {
    $im = @imagecreatefromjpeg($jpg);
    if (!$im) return;
    imagealphablending($im, true);
    $size = 40;
    $bb = imagettfbbox($size, 0, $font, $testo);
    $tw = $bb[2] - $bb[0]; $th = $bb[1] - $bb[7];
    $box = imagecolorallocatealpha($im, 0, 0, 0, 70);
    imagefilledrectangle($im, $x - 16, $y - 16, $x + $tw + 16, $y + $th + 16, $box);
    $white = imagecolorallocate($im, 255, 255, 255);
    imagettftext($im, $size, 0, $x, $y + $th, $white, $font, $testo);
    imagejpeg($im, $jpg, 92);
    imagedestroy($im);
}

// Slide-titolo: nome del mobile + logo su sfondo (prima foto sfocata e scurita).
function slideTitolo(string $tmpDir, string $bgImg, string $logo, ?string $font, string $titolo): ?string {
    $out    = $tmpDir . 'slide_title.jpg';
    $inputs = ' -i ' . escapeshellarg($bgImg);
    $fc     = '[0:v]boxblur=30:3,eq=brightness=-0.30[bg]';
    $next   = '[bg]';

    if (is_file($logo)) {
        $inputs .= ' -i ' . escapeshellarg($logo);
        $fc     .= ';[1:v]scale=360:-1[lg];[bg][lg]overlay=(W-w)/2:200[v1]';
        $next    = '[v1]';
    }

    $cmd = FFMPEG . ' -y' . $inputs . ' -filter_complex ' . escapeshellarg($fc)
         . ' -map ' . escapeshellarg($next) . ' -frames:v 1 -q:v 2 ' . escapeshellarg($out) . ' 2>&1';
    exec($cmd, $o, $rc);
    if ($rc !== 0 || !is_file($out)) return null;

    if (gdOk($font) && $titolo !== '') gdTitolo($out, $titolo, $font);
    return $out;
}

// Slide finale "Prima -> Dopo": prima e ultima foto impilate + etichette + logo.
function slideFinale(string $tmpDir, string $primaImg, string $dopoImg, string $logo, ?string $font): ?string {
    $out    = $tmpDir . 'slide_final.jpg';
    $inputs = ' -i ' . escapeshellarg($primaImg) . ' -i ' . escapeshellarg($dopoImg);
    $fc     = '[0:v]scale=1080:960:force_original_aspect_ratio=increase,crop=1080:960[top];'
            . '[1:v]scale=1080:960:force_original_aspect_ratio=increase,crop=1080:960[bot];'
            . '[top][bot]vstack=inputs=2[stk]';
    $next   = '[stk]';

    if (is_file($logo)) {
        $inputs .= ' -i ' . escapeshellarg($logo);
        $fc     .= ';[2:v]scale=240:-1[lg];[stk][lg]overlay=(W-w)/2:H-h-60[vl]';
        $next    = '[vl]';
    }

    $cmd = FFMPEG . ' -y' . $inputs . ' -filter_complex ' . escapeshellarg($fc)
         . ' -map ' . escapeshellarg($next) . ' -frames:v 1 -q:v 2 ' . escapeshellarg($out) . ' 2>&1';
    exec($cmd, $o, $rc);
    if ($rc !== 0 || !is_file($out)) return null;

    if (gdOk($font)) {
        gdLabel($out, 'PRIMA', $font, 40, 40);
        gdLabel($out, 'DOPO',  $font, 40, 1000);
    }
    return $out;
}
