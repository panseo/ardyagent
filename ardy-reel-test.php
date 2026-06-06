<?php
// -----------------------------------------------------------
// ARDY LAB — DIAGNOSTICA REEL (da eseguire da CLI, poi rimuovere)
//   uso:  php ardy-reel-test.php session_xxxxx
// -----------------------------------------------------------

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/ardy-config.php';
require_once __DIR__ . '/ardy-db.php';

const FFMPEG = '/usr/local/bin/ffmpeg';

function line(string $s = ''): void { echo $s . "\n"; }

$sessionId = preg_replace('/[^a-zA-Z0-9_\-]/', '', $argv[1] ?? '');
if ($sessionId === '') { line('Uso: php ardy-reel-test.php SESSION_ID'); exit(1); }

line('=== DIAGNOSTICA REEL ===');
line('session_id: ' . $sessionId);
line('PHP SAPI:   ' . PHP_SAPI . '  (versione ' . PHP_VERSION . ')');
line('');

// 1) FFmpeg
line('1) FFmpeg');
line('   file presente: ' . (is_file(FFMPEG) ? 'SI' : 'NO'));
$verOut = @shell_exec(FFMPEG . ' -version 2>&1');
line('   eseguibile:    ' . ($verOut ? 'SI (' . strtok($verOut, "\n") . ')' : 'NO / shell_exec bloccato'));
line('');

// 2) Funzioni shell
line('2) Funzioni di sistema');
$df = ini_get('disable_functions');
foreach (['exec', 'shell_exec', 'proc_open'] as $fn) {
    line('   ' . str_pad($fn, 11) . ': ' . (function_exists($fn) && strpos($df, $fn) === false ? 'OK' : 'DISABILITATA'));
}
line('   allow_url_fopen: ' . (ini_get('allow_url_fopen') ? 'ON' : 'OFF'));
line('   curl ext:        ' . (function_exists('curl_init') ? 'OK' : 'ASSENTE'));
line('');

// 3) Foto dal DB
line('3) Foto nel DB');
$stmt = ardyDB()->prepare("SELECT fase_nome, foto_urls FROM fasi WHERE session_id = ? ORDER BY created_at ASC");
$stmt->execute([$sessionId]);
$rows = $stmt->fetchAll();
$urls = [];
foreach ($rows as $r) {
    foreach (json_decode($r['foto_urls'] ?? '[]', true) ?: [] as $u) {
        if (is_string($u) && $u !== '') $urls[] = $u;
    }
}
line('   fasi trovate:  ' . count($rows));
line('   foto totali:   ' . count($urls));
if ($urls) line('   prima URL:     ' . $urls[0]);
line('');

if (!$urls) { line('STOP: nessuna foto da elaborare.'); exit(0); }

// 4) Download prima foto (file_get_contents + fallback curl)
line('4) Download prima foto');
$bin = @file_get_contents($urls[0]);
line('   file_get_contents: ' . ($bin !== false ? 'OK (' . strlen($bin) . ' byte)' : 'FALLITO'));
if ($bin === false && function_exists('curl_init')) {
    $ch = curl_init($urls[0]);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 30]);
    $bin  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    line('   curl:              ' . ($bin ? "OK ($code, " . strlen($bin) . ' byte)' : "FALLITO ($code) $err"));
}
line('');

if (!$bin) { line('STOP: impossibile scaricare le foto.'); exit(0); }

// 5) Test normalizzazione FFmpeg
line('5) Test FFmpeg su una foto');
$tmp = sys_get_temp_dir() . '/reeltest_' . uniqid() . '/';
@mkdir($tmp, 0755, true);
$src = $tmp . 'src.img';
file_put_contents($src, $bin);
$norm = $tmp . 'norm.jpg';
$filter = '[0:v]scale=1080:1920:force_original_aspect_ratio=decrease[fg];'
        . '[0:v]scale=1080:1920:force_original_aspect_ratio=increase,crop=1080:1920,boxblur=20:2[bg];'
        . '[bg][fg]overlay=(W-w)/2:(H-h)/2,setsar=1';
$cmd = FFMPEG . ' -y -i ' . escapeshellarg($src) . ' -filter_complex ' . escapeshellarg($filter)
     . ' -frames:v 1 -q:v 2 ' . escapeshellarg($norm) . ' 2>&1';
exec($cmd, $o, $rc);
line('   exit code: ' . $rc . (is_file($norm) ? '  (norm.jpg creato)' : '  (NESSUN output)'));
if ($rc !== 0) { line('   --- output ffmpeg ---'); line('   ' . implode("\n   ", $o)); }
line('');

// 6) Font
line('6) Font didascalie');
$found = null;
foreach (glob(__DIR__ . '/assets/fonts/*.{ttf,otf}', GLOB_BRACE) ?: [] as $f) { $found = $f; break; }
if (!$found) foreach ([
    '/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf',
    '/usr/share/fonts/dejavu-sans-fonts/DejaVuSans-Bold.ttf',
    '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
    '/usr/share/fonts/liberation/LiberationSans-Bold.ttf',
    '/usr/share/fonts/liberation-sans-fonts/LiberationSans-Bold.ttf',
] as $f) if (is_file($f)) { $found = $f; break; }
line('   font: ' . ($found ?: 'NESSUNO (reel senza testo)'));
line('   GD + FreeType: ' . (function_exists('imagettftext') && function_exists('imagecreatefromjpeg') ? 'OK (scritte abilitate)' : 'ASSENTE (reel senza testo)'));
line('   drawtext FFmpeg: ' . (strpos((string)@shell_exec(FFMPEG . ' -filters 2>&1'), 'drawtext') !== false ? 'presente' : 'ASSENTE (per questo usiamo GD)'));
line('');

// 7) Cartella reels scrivibile
line('7) Cartella output');
$reels = __DIR__ . '/reels/';
if (!is_dir($reels)) @mkdir($reels, 0755, true);
line('   ' . $reels . ' : ' . (is_writable($reels) ? 'SCRIVIBILE' : 'NON scrivibile'));

line('');

// 8) MONTAGGIO COMPLETO (come l'endpoint)
line('8) Montaggio completo');
$work = $reels . 'tmp_test_' . uniqid() . '/';
@mkdir($work, 0755, true);

// normalizza tutte le foto (con didascalia se font)
$normFiles = []; $usedUrls = []; $i = 0;
foreach ($urls as $u) {
    $b = @file_get_contents($u);
    if ($b === false) { $b = function_exists('curl_init') ? curlGet($u) : false; }
    if ($b === false || strlen($b) < 100) { line("   foto $i: download FALLITO"); continue; }
    $s = $work . "src_$i.img"; file_put_contents($s, $b);
    $n = $work . sprintf('norm_%03d.jpg', $i);
    $flt = '[0:v]scale=1080:1920:force_original_aspect_ratio=decrease[fg];[0:v]scale=1080:1920:force_original_aspect_ratio=increase,crop=1080:1920,boxblur=20:2[bg];[bg][fg]overlay=(W-w)/2:(H-h)/2,setsar=1';
    exec(FFMPEG . ' -y -i ' . escapeshellarg($s) . ' -filter_complex ' . escapeshellarg($flt) . ' -frames:v 1 -q:v 2 ' . escapeshellarg($n) . ' 2>&1', $oN, $rcN);
    @unlink($s);
    if ($rcN === 0 && is_file($n)) { $normFiles[] = $n; $usedUrls[] = $u; line("   foto $i: OK"); }
    else { line("   foto $i: FFMPEG ERRORE -> " . implode(' | ', array_slice($oN, -3))); }
    $i++;
}
line('   foto normalizzate: ' . count($normFiles));

if (count($normFiles) >= 2) {
    // lista concat
    $listF = $work . 'list.txt'; $L = '';
    foreach ($normFiles as $nf) { $L .= "file '$nf'\nduration 2.5\n"; }
    $L .= "file '" . end($normFiles) . "'\n";
    file_put_contents($listF, $L);
    $tot = count($normFiles) * 2.5;

    $vid = $work . 'video.mp4';
    $vf = 'fps=30,format=yuv420p,fade=t=in:st=0:d=0.5,fade=t=out:st=' . max(0, $tot - 1) . ':d=1';
    $c = FFMPEG . ' -y -f concat -safe 0 -i ' . escapeshellarg($listF) . ' -vf ' . escapeshellarg($vf) . ' -c:v libx264 -preset veryfast -pix_fmt yuv420p -movflags +faststart ' . escapeshellarg($vid) . ' 2>&1';
    exec($c, $oV, $rcV);
    line('   concat video: exit ' . $rcV . (is_file($vid) ? ' (video.mp4 OK, ' . filesize($vid) . ' byte)' : ' FALLITO'));
    if ($rcV !== 0) { line('   --- output ---'); line('   ' . implode("\n   ", array_slice($oV, -15))); }

    if (is_file($vid)) {
        $finalName = 'reel_TEST_' . date('His') . '.mp4';
        @rename($vid, $reels . $finalName);
        line('   >>> REEL DI TEST CREATO: https://ardyagent.ardy-lab.it/reels/' . $finalName);
    }
} else {
    line('   STOP: meno di 2 foto normalizzate.');
}

function curlGet(string $url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 30]);
    $d = curl_exec($ch); curl_close($ch); return $d;
}

// cleanup
foreach (glob($tmp . '*') as $f) @unlink($f);
@rmdir($tmp);
foreach (glob($work . '*') as $f) @unlink($f);
@rmdir($work);
line('');
line('=== FINE ===');
