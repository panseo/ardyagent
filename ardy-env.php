<?php
// DIAGNOSTICA AMBIENTE WEB (temporaneo — rimuovere dopo l'uso)
header('Content-Type: text/plain; charset=utf-8');

echo "SAPI:                " . PHP_SAPI . " (PHP " . PHP_VERSION . ")\n";
echo "disable_functions:   " . (ini_get('disable_functions') ?: '(nessuna)') . "\n";
foreach (['exec', 'shell_exec', 'proc_open', 'system'] as $f) {
    echo str_pad("$f:", 21) . (function_exists($f) ? 'OK' : 'DISABILITATA') . "\n";
}
echo "max_execution_time:  " . ini_get('max_execution_time') . "\n";
echo "allow_url_fopen:     " . (ini_get('allow_url_fopen') ? 'ON' : 'OFF') . "\n";
echo "GD imagettftext:     " . (function_exists('imagettftext') ? 'OK' : 'ASSENTE') . "\n";

$out = function_exists('shell_exec') ? @shell_exec('/usr/local/bin/ffmpeg -version 2>&1') : null;
echo "ffmpeg via web:      " . ($out ? trim(strtok($out, "\n")) : 'NO / bloccato') . "\n";

echo "server software:     " . ($_SERVER['SERVER_SOFTWARE'] ?? '?') . "\n";
echo "cloudflare header:   " . (isset($_SERVER['HTTP_CF_RAY']) ? 'SI (CF-Ray ' . $_SERVER['HTTP_CF_RAY'] . ')' : 'no') . "\n";
