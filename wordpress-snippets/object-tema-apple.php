<?php
/**
 * ARDY LAB — Tema negozio "Apple-like" per object.ardy-lab.it (Kadence + WooCommerce)
 *
 * DOVE VA: nel WordPress di **object.ardy-lab.it** (il negozio, NON il sito Divi
 *          principale). Due modi:
 *          A) Snippet PHP attivo in Code Snippets / WPCode (posizione: front-end / site-wide).
 *          B) In alternativa, copiare SOLO il blocco CSS (fra i marcatori `--- CSS ---`)
 *             in Aspetto → Personalizza → CSS aggiuntivo (o Kadence → Custom CSS).
 *
 * COSA FA: applica un look pulito stile Apple sopra Kadence — molto bianco/grigio
 *          chiaro, tipografia di sistema (San Francisco su Apple), spazi ampi,
 *          bottoni "pill" blu Apple, card prodotto senza bordi pesanti con hover
 *          morbido. È ADDITIVO: non tocca il tema, si può disattivare in un click.
 *
 * PERSONALIZZAZIONE RAPIDA: tutti i valori chiave sono variabili CSS in :root
 *          (colori, raggio, accento). Cambia lì per ritoccare tutto il negozio.
 *
 * NB: questo è un BACKUP versionato — modificarlo qui NON aggiorna WordPress
 *     (va ricopiato nello snippet). Vedi PIANO-ECOMMERCE-OBJECT-WOO.md §17 / punto ①.
 */

add_action('wp_head', function () {
    // Solo front-end (wp_head non gira in admin). Niente output nell'editor/preview REST.
    if (is_admin()) {
        return;
    }
    ?>
<style id="ardy-tema-apple">
/* ------------------------------- CSS ------------------------------- */
:root {
  --apple-bg:        #ffffff;   /* fondo pagina                    */
  --apple-bg-soft:   #f5f5f7;   /* grigio chiaro Apple (card/sezioni) */
  --apple-text:      #1d1d1f;   /* quasi-nero Apple                */
  --apple-text-soft: #6e6e73;   /* testo secondario                */
  --apple-line:      #d2d2d7;   /* linee/bordi sottili             */
  --apple-accent:    #0071e3;   /* blu Apple (bottoni/link)        */
  --apple-accent-h:  #0077ed;   /* blu hover                       */
  --apple-radius:    18px;      /* raggio card                     */
  --apple-font: -apple-system, BlinkMacSystemFont, "SF Pro Display", "SF Pro Text",
                "Helvetica Neue", Helvetica, Arial, sans-serif;
}

/* Tipografia di sistema + rendering morbido (come su apple.com) */
body {
  font-family: var(--apple-font);
  color: var(--apple-text);
  -webkit-font-smoothing: antialiased;
  -moz-osx-font-smoothing: grayscale;
  letter-spacing: .01em;
}
h1, h2, h3, h4,
.entry-title, .woocommerce-loop-product__title, .product_title {
  font-family: var(--apple-font);
  font-weight: 600;
  letter-spacing: -.022em;
  color: var(--apple-text);
}
p, li, .woocommerce-Price-amount { line-height: 1.5; }

/* Link accento */
a { color: var(--apple-accent); }
a:hover { color: var(--apple-accent-h); }

/* Bottoni "pill" blu Apple: add-to-cart, checkout, blocchi, form Woo */
.woocommerce a.button,
.woocommerce button.button,
.woocommerce input.button,
.woocommerce .button,
.woocommerce #respond input#submit,
.wc-block-components-button,
.wp-block-button__link,
.single_add_to_cart_button,
.checkout-button,
.wc-proceed-to-checkout .button {
  background: var(--apple-accent) !important;
  color: #fff !important;
  border: none !important;
  border-radius: 980px !important;   /* pill piena           */
  padding: .72em 1.5em !important;
  font-weight: 500 !important;
  font-size: 1rem !important;
  line-height: 1.2 !important;
  box-shadow: none !important;
  transition: background .25s ease, transform .25s ease;
}
.woocommerce a.button:hover,
.woocommerce button.button:hover,
.woocommerce input.button:hover,
.woocommerce .button:hover,
.wc-block-components-button:hover,
.wp-block-button__link:hover,
.single_add_to_cart_button:hover,
.checkout-button:hover {
  background: var(--apple-accent-h) !important;
  transform: translateY(-1px);
}

/* Bottone "secondario" (già-nel-carrello / view cart): contorno, non pieno */
.woocommerce a.added_to_cart,
.woocommerce .button.wc-forward,
.woocommerce a.button.alt.outline {
  background: transparent !important;
  color: var(--apple-accent) !important;
  border: 1px solid var(--apple-accent) !important;
}

/* Griglia prodotti: card morbide, senza bordi pesanti, hover che "solleva" */
.woocommerce ul.products li.product,
.wc-block-grid__product {
  background: var(--apple-bg-soft);
  border-radius: var(--apple-radius);
  padding: 24px 22px 26px;
  text-align: center;
  box-shadow: none;
  transition: box-shadow .3s ease, transform .3s ease;
}
.woocommerce ul.products li.product:hover,
.wc-block-grid__product:hover {
  box-shadow: 0 14px 44px rgba(0,0,0,.10);
  transform: translateY(-4px);
}
.woocommerce ul.products li.product a img,
.wc-block-grid__product-image img {
  border-radius: 12px;
  margin-bottom: 1.1em;
}
.woocommerce ul.products li.product .woocommerce-loop-product__title {
  font-size: 1.05rem;
  font-weight: 500;
  padding-bottom: .3em;
}
.woocommerce ul.products li.product .price,
.woocommerce div.product p.price,
.woocommerce div.product span.price {
  color: var(--apple-text) !important;
  font-weight: 500;
}
/* Via il badge "In offerta!" squadrato: pill discreta */
.woocommerce span.onsale {
  background: var(--apple-text);
  color: #fff;
  border-radius: 980px;
  min-height: 0;
  min-width: 0;
  padding: .25em .7em;
  line-height: 1.4;
  font-weight: 500;
}

/* Scheda prodotto singola: titolo grande e arioso, prezzo leggero */
.single-product div.product .product_title {
  font-size: clamp(2rem, 4vw, 2.9rem);
  line-height: 1.07;
  margin-bottom: .35em;
}
.single-product div.product .woocommerce-product-details__short-description {
  color: var(--apple-text-soft);
  font-size: 1.05rem;
}
.single-product div.product div.images img { border-radius: 16px; }

/* Categorie: pill morbide invece dei link squadrati */
.woocommerce ul.products li.product-category h2 { font-weight: 600; }

/* Header/pagina Kadence: fondo pulito, ombre più delicate, contenitore ampio */
.site-header { box-shadow: 0 1px 0 var(--apple-line) !important; }
.wp-site-blocks, .content-bg, body.wp-singular .entry-content { background: var(--apple-bg); }
hr, .wc-tabs-wrapper, .woocommerce-tabs { border-color: var(--apple-line); }

/* Focus visibile (accessibilità, stile Apple: anello netto) */
a:focus-visible, button:focus-visible, input:focus-visible, .button:focus-visible {
  outline: 3px solid rgba(0,113,227,.5);
  outline-offset: 2px;
}
/* ----------------------------- /CSS ------------------------------- */
</style>
    <?php
});
