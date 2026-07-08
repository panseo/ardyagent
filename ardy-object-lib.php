<?php
// -----------------------------------------------------------
// ARDY LAB — Libreria scheda-Sole (proiezione PUBBLICA di un oggetto)
// UNICA fonte della whitelist dei campi pubblici: la usano sia l'endpoint
// (ardy-object-scheda.php) sia il proxy chat (ardy-object-proxy.php), così la
// regola "cosa può vedere Sole" vive in un solo posto e non diverge.
// Costi, BOM, margine, file STL/CAD e iterazioni R&D NON sono selezionati qui.
// Vedi PIANO-ECOMMERCE-OBJECT-WOO.md §3.
// (File interno: negato all'accesso diretto via .htaccess.)
// -----------------------------------------------------------

/**
 * Ritorna la scheda-Sole pubblica di un oggetto per slug, o null se non esiste /
 * non è pubblicato (scheda_sole_pubblica != 1) / è eliminato.
 */
function objectSchedaSole(PDO $db, string $slug): ?array {
    $slug = substr(preg_replace('/[^a-z0-9\-]/', '', strtolower(trim($slug))), 0, 80);
    if ($slug === '') return null;

    // WHITELIST esplicita: SOLO campi pubblici.
    $st = $db->prepare(
        "SELECT slug, titolo, tipo, descrizione, storia, materiali, scheda_tecnica,
                dimensioni, cura, faq_pubbliche, prezzo_vendita, copertina_url
         FROM progetti
         WHERE slug = ? AND scheda_sole_pubblica = 1 AND deleted_at IS NULL
         LIMIT 1"
    );
    $st->execute([$slug]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;

    $faq = json_decode($r['faq_pubbliche'] ?? 'null', true);

    return [
        'slug'           => $r['slug'],
        'titolo'         => $r['titolo'],
        'tipo'           => $r['tipo'],
        'descrizione'    => $r['descrizione'],
        'storia'         => $r['storia'],
        'materiali'      => $r['materiali'],
        'scheda_tecnica' => $r['scheda_tecnica'],
        'dimensioni'     => $r['dimensioni'],
        'cura'           => $r['cura'],
        'faq'            => is_array($faq) ? $faq : [],
        'prezzo_vendita' => $r['prezzo_vendita'] !== null ? (float) $r['prezzo_vendita'] : null,
        'copertina_url'  => $r['copertina_url'],
    ];
}
