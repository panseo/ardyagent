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

if ($sessionId === '') fail('session_id mancante');

if (!is_file(FFMPEG)) fail('FFmpeg non trovato sul server', 500);

// -- Raccogli le foto dalle fasi -------------------------------------------
try {
    $db   = ardyDB();
    $stmt = $db->prepare("SELECT fase_nome, foto_urls FROM fasi WHERE session_id = ? ORDER BY created_at ASC");
    $stmt->execute([$sessionId]);
    $rows = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log('ARDY REEL DB ERROR: ' . $e->getMessage());
    fail('Errore database', 500);
}

$fotoUrls = [];
foreach ($rows as $r) {
    $urls = json_decode($r['foto_urls'] ?? '[]', true) ?: [];
    foreach ($urls as $u) {
        if (is_string($u) && $u !== '') $fotoUrls[] = $u;
    }
}
if (count($fotoUrls) < 2) fail('Servono almeno 2 foto pubblicate per creare il reel');
if (count($fotoUrls) > MAX_FOTO) $fotoUrls = array_slice($fotoUrls, 0, MAX_FOTO);

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
$normFiles = [];
$idx = 0;
foreach ($fotoUrls as $url) {
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
    if ($rc === 0 && is_file($norm)) { $normFiles[] = $norm; $idx++; }
}

if (count($normFiles) < 2) { pulisci($tmpDir); fail('Non sono riuscito a elaborare abbastanza foto', 500); }

$durataTot = count($normFiles) * SEC_PER_FOTO;

// -- Lista per il concat demuxer -------------------------------------------
$listFile = $tmpDir . 'list.txt';
$lines    = '';
foreach ($normFiles as $nf) {
    $lines .= "file '" . $nf . "'\n";
    $lines .= 'duration ' . SEC_PER_FOTO . "\n";
}
$lines .= "file '" . end($normFiles) . "'\n"; // ripeti l'ultima (quirk concat)
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
