<?php
/**
 * WPCode snippet — BACKUP (NON è la fonte attiva: vedi README.md)
 * id: 15240 | titolo: "Corsi dato strutturato" | tipo: php
 * posizione: everywhere | auto_insert: 1 | modificato: 2026-06-07 10:55:05
 * NB: in WPCode il codice gira SENZA il tag <?php (lo aggiunge WPCode).
 *     Qui il tag c'è solo per avere un .php valido/lintabile.
 */

add_action('wp_head', function () {
    if (!is_singular()) return;
    global $post;
    if (!$post) return;

    $courses = [
        'corso-di-arti-decorative-shabby-finto-marmo' => [
            'name' => 'Corso di Arti Decorative – Shabby e Finto Marmo',
            'desc' => 'Corso pratico di arti decorative a Roma: tecniche shabby e finto marmo. 4 lezioni da 2 ore (8 ore totali) nell\'arco di un mese.',
            'price' => '150', 'hours' => 8,
        ],
        'pacchetto-5-corsi' => [
            'name' => 'Pacchetto 5 Corsi di Restauro e Falegnameria',
            'desc' => 'Pacchetto completo con tutti i corsi Ardy Lab a Roma: 40 ore complessive di lezioni a prezzo scontato rispetto ai corsi singoli.',
            'price' => '600', 'hours' => 40,
        ],
        'corso-base-di-falegnameria' => [
            'name' => 'Corso Base di Falegnameria',
            'desc' => 'Corso base di falegnameria a Roma: 12 lezioni da 2 ore (24 ore totali) in 3 mesi, con costruzione di un manufatto. Contributo materiali 50€.',
            'price' => '450', 'hours' => 24,
        ],
        'corso-di-incastro-a-coda-di-rondine' => [
            'name' => 'Corso di Incastro a Coda di Rondine',
            'desc' => 'Corso di falegnameria sull\'incastro a coda di rondine a Roma: 4 lezioni da 2 ore (8 ore totali) nell\'arco di un mese.',
            'price' => '150', 'hours' => 8,
        ],
        'corso-di-tarsia-base-del-legno' => [
            'name' => 'Corso di Tarsia – Base del Legno',
            'desc' => 'Corso base di tarsia del legno a Roma: 4 lezioni da 2 ore (8 ore totali) nell\'arco di un mese.',
            'price' => '200', 'hours' => 8,
        ],
        'restaurare-un-mobile-antico' => [
            'name' => 'Corso: Restaurare un Mobile Antico',
            'desc' => 'Corso di restauro di mobili antichi a Roma: 12 lezioni da 2 ore a frequenza settimanale. Tutti i materiali inclusi.',
            'price' => '470', 'hours' => 24,
        ],
        'corso-di-finitura-a-cera-dapi' => [
            'name' => 'Corso di Finitura a Cera d\'Api',
            'desc' => 'Corso di finitura a cera d\'api a Roma: 4 lezioni da 2 ore (8 ore totali) in 2 settimane, mercoledì e venerdì.',
            'price' => '150', 'hours' => 8,
        ],
        'lucidatura-a-tampone-e-gommalacca' => [
            'name' => 'Corso di Lucidatura a Tampone e Gommalacca',
            'desc' => 'Corso di lucidatura a tampone con gommalacca a Roma: 4 lezioni da 2 ore (8 ore totali) in 2 settimane, martedì e giovedì.',
            'price' => '150', 'hours' => 8,
        ],
        'impiallicciare-con-colla-cervione-e-martellina' => [
            'name' => 'Corso di Impiallacciatura con Colla Cervione e Martellina',
            'desc' => 'Corso di impiallacciatura tradizionale con colla cervione e martellina a Roma: 4 lezioni da 2 ore (8 ore totali) in 2 settimane, mercoledì e venerdì.',
            'price' => '150', 'hours' => 8,
        ],
    ];

    $slug = $post->post_name;
    if (!isset($courses[$slug])) return;
    $c = $courses[$slug];

    $schema = [
        '@context'    => 'https://schema.org',
        '@type'       => 'Course',
        'name'        => $c['name'],
        'description' => $c['desc'],
        'url'         => get_permalink($post),
        'provider' => [
            '@id' => home_url('/') . '#/schema/organization',
        ],
        'offers' => [
            '@type'         => 'Offer',
            'category'      => 'Paid',
            'price'         => $c['price'],
            'priceCurrency' => 'EUR',
            'availability'  => 'https://schema.org/InStock',
            'url'           => get_permalink($post),
        ],
        'hasCourseInstance' => [
            '@type'          => 'CourseInstance',
            'courseMode'     => 'Onsite',
            'courseWorkload' => 'PT' . $c['hours'] . 'H',
            'location'       => [
                '@type'   => 'Place',
                'name'    => 'Ardy Lab',
                'address' => [
                    '@type'           => 'PostalAddress',
                    'streetAddress'   => 'Via James Joyce, 4',
                    'addressLocality' => 'Roma',
                    'addressRegion'   => 'RM',
                    'postalCode'      => '00143',
                    'addressCountry'  => 'IT',
                ],
            ],
        ],
    ];

    echo "\n<script type=\"application/ld+json\">"
       . wp_json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
       . "</script>\n";
}, 99);
