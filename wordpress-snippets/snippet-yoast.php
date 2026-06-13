<?php
/**
 * WPCode snippet — BACKUP (NON è la fonte attiva: vedi README.md)
 * id: 15241 | titolo: "Snippet yoast" | tipo: php
 * posizione: everywhere | auto_insert: 1 | modificato: 2026-06-07 09:47:57
 * NB: in WPCode il codice gira SENZA il tag <?php (lo aggiunge WPCode).
 *     Qui il tag c'è solo per avere un .php valido/lintabile.
 */

add_filter('wpseo_schema_organization', function ($data) {
    // Rende l'Organizzazione di Yoast anche una LocalBusiness
    $data['@type'] = ['Organization', 'LocalBusiness'];
    $data['telephone']  = '+39 351 967 7973';
    $data['email']      = 'ardy.documenti@gmail.com';
    $data['priceRange'] = '€€';
    $data['address'] = [
        '@type'           => 'PostalAddress',
        'streetAddress'   => 'Via James Joyce, 4',
        'addressLocality' => 'Roma',
        'addressRegion'   => 'RM',
        'postalCode'      => '00143',
        'addressCountry'  => 'IT',
    ];
    $data['areaServed'] = ['@type' => 'City', 'name' => 'Roma'];
    $data['openingHoursSpecification'] = [[
        '@type'     => 'OpeningHoursSpecification',
        'dayOfWeek' => ['Monday','Tuesday','Wednesday','Thursday','Friday'],
        'opens'     => '09:00',
        'closes'    => '18:00',
    ]];
	    $data['geo'] = [
        '@type'     => 'GeoCoordinates',
        'latitude'  => '41.8152523',
        'longitude' => '12.4824641',
    ];
    return $data;
}, 20);
