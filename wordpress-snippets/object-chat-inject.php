<?php
/**
 * ARDY LAB — Iniezione widget chat Sole sulle schede prodotto WooCommerce
 *
 * DOVE VA: nel WordPress di **object.ardy-lab.it** (il negozio, NON il sito Divi
 *          principale). Incollare come snippet PHP attivo in Code Snippets / WPCode,
 *          oppure salvare come mu-plugin in wp-content/mu-plugins/.
 * COSA FA: sulla pagina di ogni prodotto stampa il contenitore con lo slug del
 *          prodotto e carica ardy-object-chat.js dal nostro server (ardyagent).
 *          Il widget manda solo {slug, message} al proxy; il contesto dell'oggetto
 *          lo carica il server dalla scheda-Sole.
 *
 * ⚠️ Lo slug del prodotto Woo DEVE combaciare con lo slug del progetto in ardy design
 *    (è la chiave d'aggancio). Vedi PIANO-ECOMMERCE-OBJECT-WOO.md §5-§6.
 *
 * NB: questo è un BACKUP versionato — modificarlo qui NON aggiorna WordPress.
 */

add_action('wp_footer', function () {
    if (!function_exists('is_product') || !is_product()) {
        return;
    }
    $product = function_exists('wc_get_product') ? wc_get_product(get_the_ID()) : null;
    if (!$product) {
        return;
    }
    $slug = $product->get_slug();
    if (!$slug) {
        return;
    }
    echo '<div id="ardy-object-chat" data-slug="' . esc_attr($slug) . '"></div>' . "\n";
    echo '<script src="https://ardyagent.ardy-lab.it/ardy-object-chat.js" defer></script>' . "\n";
});
