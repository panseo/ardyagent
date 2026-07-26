<?php
// -----------------------------------------------------------
// ARDY LAB — Normalizzazione immagini per la pubblicazione
// -----------------------------------------------------------
// I social non accettano tutto quello che accetta il nostro upload.
// Google Business vuole JPG o PNG (min 250×250, < 5 MB) e su un WEBP
// risponde 500 "Internal error encountered." — senza dire perché.
// Instagram, via Graph API, vuole JPEG. WEBP e GIF vanno benissimo
// sul sito, ma se finiscono su WordPress così come sono diventano
// l'immagine che poi mandiamo ai social, e lì si rompe tutto.
//
// Perciò si converte PRIMA del caricamento su WordPress: il file che
// nasce lì è l'unico che i social vedranno, e deve essere di un formato
// che tutti prendono.
//
// Diagnosticato il 26/07/2026: render WEBP in galleria → articolo con
// immagine WEBP → 500 da Google. Vedi TODO-PROSSIMI-TASK.md.
// -----------------------------------------------------------

// Quello che i social prendono senza discutere. Il resto si converte.
if (!defined('ARDY_IMG_SOCIAL_OK')) {
    define('ARDY_IMG_SOCIAL_OK', 'image/jpeg,image/png');
}

/** Il mime è già buono per i social? */
function ardyImgOkPerSocial(string $mime): bool {
    return in_array($mime, explode(',', ARDY_IMG_SOCIAL_OK), true);
}

/**
 * Converte in JPEG i formati che i social non accettano (WEBP, GIF).
 * JPG e PNG passano intatti: ricomprimerli sarebbe perdere qualità per niente.
 *
 * Ritorna ['bytes'=>..., 'mime'=>..., 'ext'=>...]. Se la conversione non è
 * possibile (GD assente o immagine illeggibile) restituisce l'originale: meglio
 * un'immagine che i social forse rifiutano che nessuna immagine — l'articolo sul
 * sito la vuole comunque.
 */
function ardyImgNormalizza(string $bytes, string $mime): array {
    $extDi = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    $orig  = ['bytes' => $bytes, 'mime' => $mime, 'ext' => $extDi[$mime] ?? 'jpg'];

    if (ardyImgOkPerSocial($mime)) return $orig;
    if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) {
        error_log('ARDY IMG: GD non disponibile, ' . $mime . ' resta com\'è (i social potrebbero rifiutarlo)');
        return $orig;
    }

    $im = @imagecreatefromstring($bytes);
    if ($im === false) {
        error_log('ARDY IMG: ' . $mime . ' non decodificabile, resta com\'è');
        return $orig;
    }

    // Trasparenza (GIF, e WEBP con alpha): senza fondo diventerebbe nera sul JPEG.
    $w = imagesx($im); $h = imagesy($im);
    $piano = imagecreatetruecolor($w, $h);
    imagefill($piano, 0, 0, imagecolorallocate($piano, 255, 255, 255));
    imagecopy($piano, $im, 0, 0, 0, 0, $w, $h);
    imagedestroy($im);

    ob_start();
    $ok = imagejpeg($piano, null, 88);
    $jpg = (string) ob_get_clean();
    imagedestroy($piano);

    if (!$ok || strlen($jpg) < 12) {
        error_log('ARDY IMG: conversione ' . $mime . ' → JPEG fallita, resta com\'è');
        return $orig;
    }
    error_log('ARDY IMG: ' . $mime . ' convertito in JPEG (' . $w . '×' . $h . ', '
            . round(strlen($jpg) / 1024) . ' KB)');
    return ['bytes' => $jpg, 'mime' => 'image/jpeg', 'ext' => 'jpg'];
}
