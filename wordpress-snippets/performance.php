<?php
/**
 * WPCode snippet — BACKUP (NON è la fonte attiva: vedi README.md)
 * id: 15267 | titolo: "performance" | tipo: php
 * posizione: everywhere | auto_insert: 1 | modificato: 2026-06-08 05:55:11
 * NB: in WPCode il codice gira SENZA il tag <?php (lo aggiunge WPCode).
 *     Qui il tag c'è solo per avere un .php valido/lintabile.
 */

/**
 * ARDY LAB — Performance / "Riduci il codice JavaScript inutilizzato"
 *
 * Risolve i rilievi di PageSpeed Insights sul sito ardy-lab.it (Divi):
 *   - Google Maps (~222 KB)  → caricato solo sulle pagine che hanno davvero una mappa
 *   - mediaelement (~31 KB)  → caricato solo dove serve il player audio/video
 *   - scripts.min.js (Divi)  → gestito dalle opzioni Divi (vedi nota in fondo)
 *
 * COME INSTALLARLO (WPCode):
 *   - Tipo snippet: PHP Snippet
 *   - Inserimento : Auto Insert → Run Everywhere
 *   - Posizione   : (default) gestita da WPCode tramite gli hook qui sotto
 *
 * NOTA: se NON usi MAI mappe o player audio/video sul sito, puoi lasciare
 * tutto così com'è: il codice rileva automaticamente quando servono.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Helper: la pagina/post corrente usa un modulo mappa Divi?
 * Rileva sia il modulo classico [et_pb_map] sia il fullwidth map.
 */
function ardy_pagina_usa_mappa() {
	if ( ! is_singular() ) {
		return false;
	}

	$post = get_post();
	if ( ! $post || empty( $post->post_content ) ) {
		return false;
	}

	return ( false !== strpos( $post->post_content, 'et_pb_map' ) );
}

/**
 * Helper: la pagina/post corrente usa un player audio/video?
 * Copre gli shortcode core [audio]/[video] e il modulo Divi et_pb_video.
 */
function ardy_pagina_usa_media() {
	if ( ! is_singular() ) {
		return false;
	}

	$post = get_post();
	if ( ! $post || empty( $post->post_content ) ) {
		return false;
	}

	$content = $post->post_content;

	return (
		has_shortcode( $content, 'audio' ) ||
		has_shortcode( $content, 'video' ) ||
		false !== strpos( $content, 'et_pb_video' ) ||
		false !== strpos( $content, 'wp-block-audio' ) ||
		false !== strpos( $content, 'wp-block-video' )
	);
}

/**
 * Rimuove gli script inutilizzati dalle pagine che non ne hanno bisogno.
 * Priorità alta per agire dopo che Divi/WordPress li hanno messi in coda.
 */
function ardy_rimuovi_js_inutilizzato() {

	// --- Google Maps: solo dove c'è davvero una mappa ---
	if ( ! ardy_pagina_usa_mappa() ) {
		wp_dequeue_script( 'google-maps-api' );      // handle Divi
		wp_deregister_script( 'google-maps-api' );
		wp_dequeue_script( 'et-builder-googlemaps-api' );
		wp_dequeue_script( 'google-maps' );
	}

	// --- mediaelement (player audio/video): solo dove serve ---
	if ( ! ardy_pagina_usa_media() ) {
		wp_dequeue_script( 'wp-mediaelement' );
		wp_dequeue_style( 'wp-mediaelement' );
		wp_dequeue_script( 'mediaelement' );
		wp_dequeue_script( 'mediaelement-core' );
		wp_dequeue_script( 'mediaelement-migrate' );
		wp_dequeue_style( 'mediaelement' );
	}
}
add_action( 'wp_enqueue_scripts', 'ardy_rimuovi_js_inutilizzato', 100 );

/**
 * Forza "font-display: swap" sul font icone di Divi (ETmodules → modules.woff).
 * Risolve il rilievo PageSpeed "Carattere visualizzato" (~1900 ms): il testo
 * resta visibile durante il caricamento del font invece di sparire.
 *
 * Nota: un @font-face "vuoto" con solo font-display NON funziona — il browser
 * lo ignora. Serve ri-dichiarare il @font-face con lo stesso family e src,
 * aggiungendo font-display: swap (vince l'ultima dichiarazione caricata).
 */
function ardy_font_display_swap() {
	$fonts = get_template_directory_uri() . '/core/admin/fonts';
	?>
	<style id="ardy-font-display">
		@font-face {
			font-family: 'ETmodules';
			src: url('<?php echo esc_url( $fonts ); ?>/modules.woff') format('woff'),
			     url('<?php echo esc_url( $fonts ); ?>/modules.ttf') format('truetype');
			font-weight: normal;
			font-style: normal;
			font-display: swap;
		}
	</style>
	<?php
}
add_action( 'wp_head', 'ardy_font_display_swap', 99 );

/*
 * -----------------------------------------------------------------------------
 * NOTA sul deferimento degli script:
 *
 * Il defer NON è gestito da questo snippet: lo fa già Divi tramite le opzioni
 * "Librerie JavaScript dinamiche" e "Carica moduli Divi in modo differito"
 * (Opzioni Tema → Generale → Prestazione), che sono già ATTIVE sul sito.
 * Aggiungere un defer anche qui creerebbe conflitti, quindi è stato rimosso.
 *
 * Opzioni Divi consigliate (Opzioni Tema → Generale → Prestazione):
 *   - Struttura del modulo dinamico       : ATTIVA  ✔ (già attiva)
 *   - CSS dinamico                        : ATTIVA  ✔ (già attiva)
 *   - Librerie JavaScript dinamiche       : ATTIVA  ✔ (già attiva) ← taglia scripts.min.js
 *   - Migliora il caricamento Google Fonts: ATTIVA  → da attivare (sicura)
 *   - Rinvia jQuery e jQuery Migrate       : valuta  → attiva e TESTA il sito
 *
 * Google Maps e mediaelement NON sono coperti dalle opzioni Divi:
 * è qui che questo snippet fa il risparmio grosso (~250 KB).
 * -----------------------------------------------------------------------------
 */
