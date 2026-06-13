<?php
/**
 * WPCode snippet — BACKUP (NON è la fonte attiva: vedi README.md)
 * id: 15245 | titolo: "Pulsante corsi" | tipo: php
 * posizione: everywhere | auto_insert: 1 | modificato: 2026-06-07 14:38:54
 * NB: in WPCode il codice gira SENZA il tag <?php (lo aggiunge WPCode).
 *     Qui il tag c'è solo per avere un .php valido/lintabile.
 */

add_filter('the_content', function ($content) {
    if (!is_singular() || !in_the_loop() || !is_main_query()) return $content;

    $corsi = [
        'corso-di-arti-decorative-shabby-finto-marmo'     => 'Corso di Arti Decorative – Shabby e Finto Marmo',
        'pacchetto-5-corsi'                               => 'Pacchetto 5 Corsi',
        'corso-base-di-falegnameria'                      => 'Corso Base di Falegnameria',
        'corso-di-incastro-a-coda-di-rondine'             => 'Corso di Incastro a Coda di Rondine',
        'corso-di-tarsia-base-del-legno'                  => 'Corso di Tarsia – Base del Legno',
        'restaurare-un-mobile-antico'                     => 'Restaurare un Mobile Antico',
        'corso-di-finitura-a-cera-dapi'                   => 'Corso di Finitura a Cera d\'Api',
        'lucidatura-a-tampone-e-gommalacca'               => 'Lucidatura a Tampone e Gommalacca',
        'impiallicciare-con-colla-cervione-e-martellina'  => 'Impiallacciatura con Colla Cervione e Martellina',
    ];

    global $post;
    $slug = $post->post_name ?? '';
    if (!isset($corsi[$slug])) return $content;

    $link = 'https://ardy-lab.it/ardy-agent/?corso=' . rawurlencode($corsi[$slug]);

    $cta = '<a class="ardy-corso-fab" href="' . esc_url($link) . '" aria-label="Chiedi info su questo corso">'
         . '<span class="ardy-corso-fab-icon">💬</span>'
         . '<span class="ardy-corso-fab-text">Info su questo corso</span>'
         . '</a>'
         . '<style>'
         . '.ardy-corso-fab{position:fixed;right:20px;bottom:20px;z-index:99999;display:flex;align-items:center;gap:8px;'
         . 'background:#7a5a3a;color:#fff !important;text-decoration:none;padding:13px 20px;border-radius:50px;'
         . 'font:600 16px/1 system-ui,Arial,sans-serif;box-shadow:0 6px 20px rgba(0,0,0,.25);transition:transform .2s ease;}'
         . '.ardy-corso-fab:hover{transform:translateY(-2px);}'
         . '.ardy-corso-fab-icon{font-size:20px;}'
         . '@media(max-width:600px){.ardy-corso-fab-text{display:none;}.ardy-corso-fab{padding:15px;border-radius:50%;}.ardy-corso-fab-icon{font-size:24px;}}'
         . '</style>';

    return $content . $cta;
}, 20);
