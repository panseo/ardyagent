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
        // Colonna stretta (circa 60% della larghezza) e righe strette, come nel reel di Michela:
        // la frase va su 2-3 righe e si legge come un titolo, non come una riga di testo.
        $fs = 84 * $u; $lh = (int) round(90 * $u);
        $ast = imagettfbbox($fs, 0, $reg, '*');
        $rientro = $ast[2] - $ast[0];     // le righe successive partono dopo l'asterisco
        $righe = grafRighe($frase, $reg, $fs, (int) round($W * 0.62) - $rientro);
        $y = $top + $hLogo + (int) round(105 * $u);
        foreach (array_slice($righe, 0, 4) as $i => $r) {
            grafTesto($im, $fs, $m + ($i === 0 ? 0 : $rientro), $y, $reg, ($i === 0 ? '*' : '') . $r);
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
