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

// -- Input ------------------------------------------------------------------
$input     = json_decode(file_get_contents('php://input'), true) ?: [];
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

    // Didascalia della fase (sovrimpressa in basso), se abbiamo un font
    $drawtext = '';
    if ($font && $it['fase'] !== '') {
        $capFile = $tmpDir . sprintf('cap_%03d.txt', $idx);
        file_put_contents($capFile, wordwrap($it['fase'], 24, "\n", true));
        $drawtext = ',drawtext=fontfile=' . $font . ':textfile=' . $capFile
                  . ':fontcolor=white:fontsize=56:box=1:boxcolor=black@0.45:boxborderw=22'
                  . ':x=(w-text_w)/2:y=h-320:line_spacing=10';
    }

    $norm   = $tmpDir . sprintf('norm_%03d.jpg', $idx);
    $filter = '[0:v]scale=' . REEL_W . ':' . REEL_H . ':force_original_aspect_ratio=decrease[fg];'
            . '[0:v]scale=' . REEL_W . ':' . REEL_H . ':force_original_aspect_ratio=increase,'
            . 'crop=' . REEL_W . ':' . REEL_H . ',boxblur=20:2[bg];'
            . '[bg][fg]overlay=(W-w)/2:(H-h)/2,setsar=1' . $drawtext;
    $cmd = FFMPEG . ' -y -i ' . escapeshellarg($src)
         . ' -filter_complex ' . escapeshellarg($filter)
         . ' -frames:v 1 -q:v 2 ' . escapeshellarg($norm) . ' 2>&1';
    exec($cmd, $o, $rc);
    @unlink($src);
    if ($rc === 0 && is_file($norm)) { $normFiles[] = $norm; $usedItems[] = $it; $idx++; }
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

// -- Lista per il concat demuxer -------------------------------------------
$listFile = $tmpDir . 'list.txt';
$lines    = '';
foreach ($slides as $s) {
    $lines .= "file '" . $s['f'] . "'\n";
    $lines .= 'duration ' . $s['d'] . "\n";
}
$lines .= "file '" . end($slides)['f'] . "'\n"; // ripeti l'ultima (quirk concat)
file_put_contents($listFile, $lines);

// -- Step 1: video muto con fade in/out ------------------------------------
$videoMuto = $tmpDir . 'video.mp4';
$fadeOut   = max(0, $durataTot - 1);
$vf = 'fps=30,format=yuv420p,fade=t=in:st=0:d=0.5,fade=t=out:st=' . $fadeOut . ':d=1';
$cmd = FFMPEG . ' -y -f concat -safe 0 -i ' . escapeshellarg($listFile)
     . ' -vf ' . escapeshellarg($vf)
     . ' -c:v libx264 -preset veryfast -pix_fmt yuv420p -movflags +faststart '
     . escapeshellarg($videoMuto) . ' 2>&1';
exec($cmd, $o1, $rc1);
if ($rc1 !== 0 || !is_file($videoMuto)) {
    error_log('ARDY REEL FFMPEG VIDEO ERROR: ' . implode("\n", $o1));
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

// -- Step 2: aggiungi audio (se presente) ----------------------------------
$finalName = 'reel_' . $sessionId . '_' . date('Ymd_His') . '.mp4';
$finalPath = $reelsDir . $finalName;

if ($musicPath) {
    $afOut = max(0, $durataTot - 2);
    $cmd = FFMPEG . ' -y -i ' . escapeshellarg($videoMuto)
         . ' -stream_loop -1 -i ' . escapeshellarg($musicPath)
         . ' -map 0:v -map 1:a -c:v copy -c:a aac -b:a 192k -shortest'
         . ' -af ' . escapeshellarg('afade=t=out:st=' . $afOut . ':d=2')
         . ' -movflags +faststart ' . escapeshellarg($finalPath) . ' 2>&1';
    exec($cmd, $o2, $rc2);
    if ($rc2 !== 0 || !is_file($finalPath)) {
        error_log('ARDY REEL FFMPEG AUDIO ERROR: ' . implode("\n", $o2));
        // fallback: consegna il video muto
        @rename($videoMuto, $finalPath);
    }
} else {
    @rename($videoMuto, $finalPath);
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

// Slide-titolo: nome del mobile + logo su sfondo (prima foto sfocata e scurita).
function slideTitolo(string $tmpDir, string $bgImg, string $logo, ?string $font, string $titolo): ?string {
    $out    = $tmpDir . 'slide_title.jpg';
    $inputs = ' -i ' . escapeshellarg($bgImg);
    $fc     = '[0:v]boxblur=30:3,eq=brightness=-0.30[bg]';
    $next   = '[bg]';

    if (is_file($logo)) {
        $inputs .= ' -i ' . escapeshellarg($logo);
        $fc     .= ';[1:v]scale=460:-1[lg];[bg][lg]overlay=(W-w)/2:280[v1]';
        $next    = '[v1]';
    }
    if ($font && $titolo !== '') {
        $capFile = $tmpDir . 'title.txt';
        file_put_contents($capFile, wordwrap($titolo, 18, "\n", true));
        $fc  .= ';' . $next . 'drawtext=fontfile=' . $font . ':textfile=' . $capFile
              . ':fontcolor=white:fontsize=84:x=(w-text_w)/2:y=(h-text_h)/2+140'
              . ':line_spacing=16:box=1:boxcolor=black@0.35:boxborderw=30[vt]';
        $next = '[vt]';
    }

    $cmd = FFMPEG . ' -y' . $inputs . ' -filter_complex ' . escapeshellarg($fc)
         . ' -map ' . escapeshellarg($next) . ' -frames:v 1 -q:v 2 ' . escapeshellarg($out) . ' 2>&1';
    exec($cmd, $o, $rc);
    return ($rc === 0 && is_file($out)) ? $out : null;
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
        $fc     .= ';[2:v]scale=300:-1[lg];[stk][lg]overlay=(W-w)/2:H-140[vl]';
        $next    = '[vl]';
    }
    if ($font) {
        $fc  .= ';' . $next . "drawtext=fontfile=$font:text=PRIMA:fontcolor=white:fontsize=58"
              . ':x=40:y=40:box=1:boxcolor=black@0.5:boxborderw=16[v2]';
        $fc  .= ';[v2]' . "drawtext=fontfile=$font:text=DOPO:fontcolor=white:fontsize=58"
              . ':x=40:y=1000:box=1:boxcolor=black@0.5:boxborderw=16[v3]';
        $next = '[v3]';
    }

    $cmd = FFMPEG . ' -y' . $inputs . ' -filter_complex ' . escapeshellarg($fc)
         . ' -map ' . escapeshellarg($next) . ' -frames:v 1 -q:v 2 ' . escapeshellarg($out) . ' 2>&1';
    exec($cmd, $o, $rc);
    return ($rc === 0 && is_file($out)) ? $out : null;
}
