<?php
// -----------------------------------------------------------
// ARDY LAB — Grafica dei contenuti social nello stile dei reel di Michela
// Foto a tutto campo, logo Ardy bianco, indirizzo e servizi in piccolo, frase grande
// bianca col font Inter che si apre con un asterisco ("*Let's give Vintage a second
// life"). La usano il reel (ardy-crea-reel.php) e la copertina dei capitoli di
// Racconta per Facebook/Instagram/Google (ardy-pezzi-copertina.php): un solo posto,
// così lo stile resta lo stesso ovunque.
// Font: Inter (SIL Open Font License, assets/fonts/OFL-Inter.txt).
// (File interno: negato all'accesso diretto via .htaccess.)
// -----------------------------------------------------------

const GRAF_INDIRIZZO = ['Via Joyce 4, 00143', 'Roma (RM), Italia'];
const GRAF_SERVIZI   = ['Restauro, Design', 'Corsi & Interior'];

/** Percorso del font Inter (regular per le frasi, medium per i testi piccoli), o null. */
function grafFont(string $peso = 'regular'): ?string {
    $f = __DIR__ . '/assets/fonts/' . ($peso === 'medium' ? 'Inter-Medium.ttf' : 'Inter-Regular.ttf');
    return is_file($f) ? $f : null;
}

/** GD con FreeType e JPEG: senza, niente testi sulle immagini (si pubblica la foto com'è). */
function grafDisponibile(): bool {
    return function_exists('imagettftext') && function_exists('imagecreatefromstring') && function_exists('imagejpeg') && grafFont() !== null;
}

/** Spezza un testo in righe entro $maxW pixel. */
function grafRighe(string $t, string $font, float $size, int $maxW): array {
    $righe = []; $cur = '';
    foreach (preg_split('/\s+/u', trim($t)) as $p) {
        $prova = $cur === '' ? $p : $cur . ' ' . $p;
        $bb = imagettfbbox($size, 0, $font, $prova);
        if (($bb[2] - $bb[0]) > $maxW && $cur !== '') { $righe[] = $cur; $cur = $p; }
        else $cur = $prova;
    }
    if ($cur !== '') $righe[] = $cur;
    return $righe;
}

/** Foto ritagliata "a riempimento" su un'immagine W×H (come object-fit: cover). */
function grafRiempi($src, int $W, int $H) {
    $sw = imagesx($src); $sh = imagesy($src);
    $k  = max($W / $sw, $H / $sh);
    $cw = (int) round($W / $k); $ch = (int) round($H / $k);
    $dst = imagecreatetruecolor($W, $H);
    imagecopyresampled($dst, $src, 0, 0, (int) (($sw - $cw) / 2), (int) (($sh - $ch) / 2), $W, $H, $cw, $ch);
    return $dst;
}

/** Velatura scura verticale tra due altezze (alpha GD: 127 = trasparente, 0 = pieno). */
function grafVela($im, int $da, int $a, int $alphaDa, int $alphaA): void {
    imagealphablending($im, true);
    $W = imagesx($im); $passi = max(1, $a - $da);
    for ($y = $da; $y < $a; $y++) {
        $al = (int) round($alphaDa + ($alphaA - $alphaDa) * (($y - $da) / $passi));
        imagefilledrectangle($im, 0, $y, $W, $y, imagecolorallocatealpha($im, 0, 0, 0, max(0, min(127, $al))));
    }
}

/** Il logo Ardy (assets/logo.png, tratto nero) ricolorato in bianco, alto $h pixel. */
function grafLogoBianco($im, int $x, int $y, int $h): void {
    $f = __DIR__ . '/assets/logo.png';
    $lg = is_file($f) ? @imagecreatefrompng($f) : false;
    if (!$lg) return;
    $w = (int) round(imagesx($lg) * $h / imagesy($lg));
    $tmp = imagecreatetruecolor($w, $h);
    imagealphablending($tmp, false); imagesavealpha($tmp, true);
    imagefill($tmp, 0, 0, imagecolorallocatealpha($tmp, 0, 0, 0, 127));
    imagecopyresampled($tmp, $lg, 0, 0, 0, 0, $w, $h, imagesx($lg), imagesy($lg));
    // Tiene la trasparenza del logo e rende bianco il tratto.
    for ($i = 0; $i < $w; $i++) for ($j = 0; $j < $h; $j++) {
        $a = (imagecolorat($tmp, $i, $j) >> 24) & 0x7F;
        imagesetpixel($tmp, $i, $j, imagecolorallocatealpha($tmp, 255, 255, 255, $a));
    }
    imagealphablending($im, true);
    imagecopy($im, $tmp, $x, $y, 0, 0, $w, $h);
}

/** Testo bianco con un'ombra morbida sotto (leggibile anche sulle foto chiare). */
function grafTesto($im, float $size, int $x, int $y, string $font, string $t): void {
    imagettftext($im, $size, 0, $x + 2, $y + 2, imagecolorallocatealpha($im, 0, 0, 0, 80), $font, $t);
    imagettftext($im, $size, 0, $x, $y, imagecolorallocate($im, 255, 255, 255), $font, $t);
}

/**
 * Copertina nello stile di Michela: la foto a tutto campo W×H, velatura scura in alto
 * per la leggibilità, logo bianco con indirizzo e servizi accanto, e la frase grande
 * che si apre con l'asterisco (le righe dopo la prima allineate al testo, non all'asterisco).
 * $fotoBytes: i byte della foto. Ritorna i byte JPEG, o null se GD non c'è.
 */
function grafCopertina(string $fotoBytes, string $frase, int $W, int $H): ?string {
    if (!grafDisponibile()) return null;
    $src = @imagecreatefromstring($fotoBytes);
    if (!$src) return null;
    $im = grafRiempi($src, $W, $H);
    imagedestroy($src);

    $u = $W / 1080;                       // unità: le misure sono pensate su 1080 di larghezza
    $m = (int) round(80 * $u);            // margine sinistro
    $top = (int) round($H * ($H > $W * 1.5 ? 0.27 : 0.18));   // reel più in basso, post più in alto
    grafVela($im, 0, (int) round($H * 0.62), 50, 127);

    $reg = grafFont('regular'); $med = grafFont('medium') ?? $reg;
    $hLogo = (int) round(110 * $u);
    grafLogoBianco($im, $m, $top, $hLogo);
    $sz = 17 * $u;                        // testi piccoli accanto al logo, come nel reel
    $xInfo = $m + (int) round(150 * $u);
    foreach ([GRAF_INDIRIZZO, GRAF_SERVIZI] as $col => $righe) {
        $xc = $xInfo + (int) round($col * 230 * $u);
        foreach ($righe as $i => $r) grafTesto($im, $sz, $xc, $top + (int) round((22 + $i * 26) * $u), $med, $r);
    }

    $frase = trim(ltrim(trim($frase), '*'));
    if ($frase !== '') {
        // Colonna stretta e righe strette, come nel reel di Michela: la frase va su 2-3 righe e
        // si legge come un titolo. Sul post 4:5 la colonna è un po' più larga (lo spazio in
        // altezza è meno). Se la frase non sta in 3 righe il corpo scende fino a starci:
        // nessuna parola deve sparire tagliata.
        $colonna = (int) round($W * ($H > $W * 1.5 ? 0.62 : 0.74)) - (int) round(50 * $u);
        for ($fs = 84 * $u; ; $fs -= 4 * $u) {
            $righe = grafRighe($frase, $reg, $fs, $colonna);
            if (count($righe) <= 3 || $fs <= 56 * $u) break;
        }
        $lh = (int) round($fs * 1.07);
        // Allineamento "dopo l'asterisco" sull'inchiostro, non sulla larghezza del carattere:
        // ogni lettera ha il suo margine (una "a" parte in un punto diverso da una "L"), quindi
        // ogni riga si sposta quanto serve perché il suo bordo sinistro cada esattamente sotto
        // la prima lettera dopo l'asterisco.
        $primo   = mb_substr($righe[0] ?? 'A', 0, 1);
        $bbPrimo = imagettfbbox($fs, 0, $reg, $primo);
        $avanzaAst = imagettfbbox($fs, 0, $reg, '*' . $primo)[2] - $bbPrimo[2];   // avanzamento dell'asterisco (con crenatura)
        $bordo   = $m + $avanzaAst + $bbPrimo[0];                                  // dove inizia l'inchiostro della prima lettera
        // Più aria tra il blocco logo/indirizzo e la frase (richiesta al collaudo del 23/11).
        $y = $top + $hLogo + (int) round(190 * $u);
        foreach ($righe as $i => $r) {
            $x = $i === 0 ? $m : $bordo - imagettfbbox($fs, 0, $reg, mb_substr($r, 0, 1))[0];
            grafTesto($im, $fs, $x, $y, $reg, ($i === 0 ? '*' : '') . $r);
            $y += $lh;
        }
    }

    ob_start(); imagejpeg($im, null, 90); $out = ob_get_clean();
    imagedestroy($im);
    return $out;
}

/** Didascalia piccola in basso a sinistra (le foto del reel), su velatura sfumata dal basso. */
function grafDidascalia(string $jpgPath, string $testo): void {
    if (!grafDisponibile() || trim($testo) === '') return;
    $im = @imagecreatefromjpeg($jpgPath);
    if (!$im) return;
    $W = imagesx($im); $H = imagesy($im); $u = $W / 1080;
    grafVela($im, (int) round($H * 0.70), $H, 127, 45);
    $font = grafFont('medium') ?? grafFont();
    $fs = 40 * $u; $lh = (int) round(52 * $u); $m = (int) round(80 * $u);
    $righe = array_slice(grafRighe($testo, $font, $fs, $W - 2 * $m), 0, 3);
    $y = $H - (int) round(220 * $u) - (count($righe) - 1) * $lh;
    foreach ($righe as $r) { grafTesto($im, $fs, $m, $y, $font, $r); $y += $lh; }
    imagejpeg($im, $jpgPath, 92);
    imagedestroy($im);
}

// ── Reel nello stile di Michela: foto piene, zoom lento, dissolvenze ─────────────
// Nei reel della bottega solo l'apertura ha logo e frase; poi le immagini sono a tutto
// schermo e pulite, e si passa dall'una all'altra senza scatti. Qui: foto ritagliate a
// riempimento 9:16 (niente bande sfocate né didascalie), un leggero zoom su ogni foto
// (effetto Ken Burns: una foto ferma sembra una ripresa) e dissolvenze incrociate.

const GRAF_DISSOLVENZA = 0.7;   // secondi di sovrapposizione tra una slide e la successiva

/** Foto a riempimento W×H in JPEG, senza testi. null se GD non c'è. */
function grafPiena(string $fotoBytes, int $W, int $H): ?string {
    if (!grafDisponibile()) return null;
    $src = @imagecreatefromstring($fotoBytes);
    if (!$src) return null;
    $im = grafRiempi($src, $W, $H);
    imagedestroy($src);
    ob_start(); imagejpeg($im, null, 92); $out = ob_get_clean();
    imagedestroy($im);
    return $out;
}

/** Slide finale Prima/Dopo: due metà a tutto campo, "*Prima" e "*Dopo" in Inter, logo bianco. */
function grafPrimaDopo(string $primaBytes, string $dopoBytes, int $W, int $H): ?string {
    if (!grafDisponibile()) return null;
    $a = @imagecreatefromstring($primaBytes); $b = @imagecreatefromstring($dopoBytes);
    if (!$a || !$b) return null;
    $meta = (int) ($H / 2);
    $im = imagecreatetruecolor($W, $H);
    $sa = grafRiempi($a, $W, $meta); $sb = grafRiempi($b, $W, $H - $meta);
    imagecopy($im, $sa, 0, 0, 0, 0, $W, $meta); imagecopy($im, $sb, 0, $meta, 0, 0, $W, $H - $meta);
    imagedestroy($a); imagedestroy($b); imagedestroy($sa); imagedestroy($sb);
    $u = $W / 1080; $m = (int) round(80 * $u); $reg = grafFont('regular');
    grafVela($im, 0, (int) round($meta * 0.45), 60, 127);
    grafVela($im, $meta, $meta + (int) round($meta * 0.45), 60, 127);
    grafTesto($im, 84 * $u, $m, (int) round(170 * $u), $reg, '*Prima');
    grafTesto($im, 84 * $u, $m, $meta + (int) round(170 * $u), $reg, '*Dopo');
    grafLogoBianco($im, $W - $m - (int) round(70 * $u), $H - (int) round(190 * $u), (int) round(105 * $u));
    ob_start(); imagejpeg($im, null, 92); $out = ob_get_clean();
    imagedestroy($im);
    return $out;
}

/** Esegue un comando (proc_open: su PHP-FPM exec/shell_exec sono spesso disabilitate). */
function grafEsegui(string $cmd, ?array &$out = null): int {
    $p = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($p)) { $out = ['proc_open non disponibile']; return 127; }
    $txt = stream_get_contents($pipes[1]) . "\n" . stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $out = preg_split("/\r?\n/", trim($txt)) ?: [];
    return proc_close($p);
}

/**
 * Una mini-clip da un'immagine. Con $zoom, avvicinamento lento (1,00 → 1,06) sul centro:
 * l'immagine si ingrandisce prima del zoompan per non avere il tremolio a scatti tipico
 * dello zoom su risoluzioni piccole.
 */
function grafClip(string $ffmpeg, string $img, float $sec, string $out, bool $zoom, int $W, int $H): bool {
    $fps = 30; $frames = (int) ceil($sec * $fps);
    $vf = $zoom
        ? 'scale=' . ($W * 3 / 2) . ':' . ($H * 3 / 2) . ',zoompan=z=\'1+0.06*on/' . $frames . '\':d=' . $frames
          . ':x=\'iw/2-(iw/zoom/2)\':y=\'ih/2-(ih/zoom/2)\':s=' . $W . 'x' . $H . ':fps=' . $fps . ',format=yuv420p,setsar=1'
        : 'scale=' . $W . ':' . $H . ',format=yuv420p,setsar=1';
    $cmd = $ffmpeg . ' -y ' . ($zoom ? '' : '-loop 1 ') . '-i ' . escapeshellarg($img)
         . ($zoom ? '' : ' -t ' . $sec) . ' -r ' . $fps . ' -vf ' . escapeshellarg($vf)
         . ($zoom ? ' -frames:v ' . $frames : '')
         . ' -c:v libx264 -preset veryfast -crf 20 ' . escapeshellarg($out) . ' 2>&1';
    return grafEsegui($cmd, $o) === 0 && is_file($out);
}

/**
 * Unisce le clip con dissolvenze incrociate (xfade). Ritorna la durata totale, o null se
 * xfade non è riuscito (versioni vecchie di FFmpeg): chi chiama ripiega sul concat.
 */
function grafUnisci(string $ffmpeg, array $clip, array $durate, string $out): ?float {
    $n = count($clip);
    if ($n === 1) return copy($clip[0], $out) ? (float) $durate[0] : null;
    $T = GRAF_DISSOLVENZA;
    $in = ''; $fc = '';
    foreach ($clip as $k => $c) {
        $in .= ' -i ' . escapeshellarg($c);
        $fc .= '[' . $k . ':v]settb=AVTB,fps=30,format=yuv420p[c' . $k . '];';
    }
    $durata = (float) $durate[0]; $prec = '[c0]';
    for ($k = 1; $k < $n; $k++) {
        $offset = round($durata - $T, 3);
        $lab = $k === $n - 1 ? '[v]' : '[x' . $k . ']';
        $fc .= $prec . '[c' . $k . ']xfade=transition=fade:duration=' . $T . ':offset=' . $offset . $lab . ';';
        $prec = $lab;
        $durata += (float) $durate[$k] - $T;
    }
    $cmd = $ffmpeg . ' -y' . $in . ' -filter_complex ' . escapeshellarg(rtrim($fc, ';'))
         . ' -map ' . escapeshellarg('[v]') . ' -c:v libx264 -preset veryfast -crf 20 -pix_fmt yuv420p ' . escapeshellarg($out) . ' 2>&1';
    if (grafEsegui($cmd, $o) !== 0 || !is_file($out)) {
        error_log('ARDY REEL XFADE: ' . implode(' | ', array_slice($o, -5)));
        return null;
    }
    return $durata;
}
