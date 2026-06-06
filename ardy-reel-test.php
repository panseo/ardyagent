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
line('');

// 7) Cartella reels scrivibile
line('7) Cartella output');
$reels = __DIR__ . '/reels/';
if (!is_dir($reels)) @mkdir($reels, 0755, true);
line('   ' . $reels . ' : ' . (is_writable($reels) ? 'SCRIVIBILE' : 'NON scrivibile'));

// cleanup
foreach (glob($tmp . '*') as $f) @unlink($f);
@rmdir($tmp);
line('');
line('=== FINE ===');
