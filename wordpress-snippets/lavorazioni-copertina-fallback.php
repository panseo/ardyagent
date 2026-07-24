<?php
/**
 * WPCode snippet — DA INCOLLARE IN WPCode (Code Snippets → Aggiungi nuovo →
 * tipo "PHP Snippet" → posizione "Esegui ovunque" / everywhere).
 * NB: in WPCode il codice gira SENZA il tag <?php (lo aggiunge WPCode).
 *     Qui il tag c'è solo per avere un .php valido/lintabile.
 *
 * Le pagine "Lavori in corso" (categoria 102) non hanno un'immagine in evidenza
 * impostata (il publish flow — ardy-pubblica-lavorazione.php — non ci prova più).
 * Qui diciamo a WordPress di usare al volo la PRIMA foto trovata nel contenuto
 * del post come se fosse la copertina, così i box di anteprima del modulo Blog
 * di Divi (es. in home) mostrano comunque un'immagine invece del placeholder.
 *
 * Nessuna scrittura sul post: è tutto calcolato "on the fly" filtrando
 * get_post_thumbnail_id(), il punto da cui passano sia has_post_thumbnail()
 * che get_the_post_thumbnail() (quindi anche Divi). Le foto sono già in Media
 * Library (caricate dal publish flow), quindi attachment_url_to_postid() le
 * riconosce e da lì in poi funziona tutto come con una copertina vera
 * (dimensioni, srcset, ecc.).
 */
add_filter('get_post_thumbnail_id', function ($thumbnail_id, $post) {
    if ($thumbnail_id) return $thumbnail_id; // copertina già impostata: non tocchiamo nulla
    if (!$post || !has_category(102, $post)) return $thumbnail_id; // solo pagine lavorazione

    // Cache per richiesta: evita di rifare regex/query per lo stesso post
    // quando la funzione viene chiamata più volte nella stessa pagina.
    static $cache = [];
    if (array_key_exists($post->ID, $cache)) return $cache[$post->ID];

    if (!preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', (string) $post->post_content, $m)) {
        return $cache[$post->ID] = $thumbnail_id;
    }
    $attachmentId = attachment_url_to_postid($m[1]);
    return $cache[$post->ID] = ($attachmentId ?: $thumbnail_id);
}, 10, 2);
