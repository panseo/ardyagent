<?php
/**
 * WPCode — "Ardy — schema FAQ pezzi" | tipo: PHP | posizione: Esegui ovunque
 *
 * Sulle pagine dei pezzi di Racconta (tag `storia-pezzo`) aggiunge al grafo schema.org
 * di Yoast un nodo FAQPage con le domande frequenti della pagina. Le domande le scrive
 * ardy-pubblica-pezzo.php nel meta `_ardy_faq` (JSON [{domanda, risposta}]) e le mostra
 * anche nel testo della pagina: lo schema dice ai motori di ricerca e agli assistenti AI
 * che quelle sono domande con le loro risposte, collegate a questa pagina e a questo articolo.
 *
 * Perché un filtro Yoast e non un <script> nel contenuto: così il nodo entra nello STESSO
 * grafo di Yoast (Article, WebPage, LocalBusiness), con gli @id collegati, e il contenuto
 * del post non si porta dietro codice che l'editor potrebbe rovinare.
 * Copia di backup: questo file nel repo ardyagent (cartella wordpress-snippets/).
 */
add_filter('wpseo_schema_graph', function ($graph, $context) {
    if (!is_singular('post') || !has_tag('storia-pezzo')) return $graph;

    $faq = json_decode((string) get_post_meta(get_the_ID(), '_ardy_faq', true), true);
    if (!is_array($faq) || !$faq) return $graph;

    $domande = [];
    foreach ($faq as $f) {
        $d = trim((string) ($f['domanda'] ?? ''));
        $r = trim((string) ($f['risposta'] ?? ''));
        if ($d === '' || $r === '') continue;
        $domande[] = [
            '@type'          => 'Question',
            'name'           => $d,
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => $r],
        ];
    }
    if (!$domande) return $graph;

    $url = $context->canonical;
    $graph[] = [
        '@type'      => 'FAQPage',
        '@id'        => $url . '#faq',
        'url'        => $url,
        'isPartOf'   => ['@id' => $context->main_schema_id],
        'inLanguage' => 'it-IT',
        'mainEntity' => $domande,
    ];
    return $graph;
}, 11, 2);
