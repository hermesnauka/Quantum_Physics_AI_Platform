<?php
/**
 * FR-02: rendering of complex mathematical / quantum-state equations via
 * LaTeX (MathJax).
 *
 * MathJax is vendored locally at assets/vendor/mathjax/tex-svg.js rather than
 * pulled from a CDN, so it works under the site's CSP (script-src 'self',
 * see nginx/conf.d/wordpress.conf) without loosening it, and doesn't depend
 * on a third-party host being reachable at render time. The "tex-svg" build
 * renders to inline SVG, so there's no external web-font request either.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'QUANTUMAI_MATHJAX_VERSION', '3.2.2' );

/**
 * Cheap heuristic so MathJax (~700KB gzipped) only loads on posts/pages that
 * actually contain math (NFR-01: page loads under 2s).
 */
function quantumai_post_has_math( $post_id ) {
	$content = get_post_field( 'post_content', $post_id );

	if ( '' === $content ) {
		return false;
	}

	return (bool) preg_match( '/\\\\\(|\\\\\[|\$\$|\[latex\b/i', $content );
}

function quantumai_enqueue_mathjax() {
	if ( ! is_singular( array( 'post', 'page' ) ) ) {
		return;
	}

	if ( ! quantumai_post_has_math( get_the_ID() ) ) {
		return;
	}

	// Config must be registered as a dependency that loads (and executes)
	// before tex-svg.js, since MathJax reads window.MathJax at parse time.
	wp_enqueue_script(
		'quantumai-mathjax-config',
		get_template_directory_uri() . '/assets/js/mathjax-config.js',
		array(),
		QUANTUMAI_THEME_VERSION,
		false
	);

	wp_enqueue_script(
		'quantumai-mathjax',
		get_template_directory_uri() . '/assets/vendor/mathjax/tex-svg.js',
		array( 'quantumai-mathjax-config' ),
		QUANTUMAI_MATHJAX_VERSION,
		false
	);

	wp_script_add_data( 'quantumai-mathjax', 'id', 'MathJax-script' );
}
add_action( 'wp_enqueue_scripts', 'quantumai_enqueue_mathjax' );

/**
 * Convenience [latex]...[/latex] shortcode for authors who'd rather not
 * remember the \( \) / \[ \] delimiter syntax directly.
 *
 * Output is escaped (SR-04 / late-escaping per PLAN.md Phase 3): this content
 * can originate from an Author's draft before Editor review, so it's treated
 * as untrusted at render time like any other post content. esc_html() is
 * safe here because MathJax reads the rendered element's text content, not
 * its markup, so HTML-entity-escaped TeX (e.g. "a &lt; b") still typesets
 * correctly as "a < b".
 *
 * Known caveat: wpautop runs before shortcodes are processed, so a blank
 * line inside [latex]...[/latex] becomes a stray "<br />" in the output.
 * Keep multi-line equations on one line and use TeX's own "\\" for line
 * breaks, or just write \[ ... \] directly in the content instead.
 */
function quantumai_latex_shortcode( $atts, $content = '' ) {
	$atts    = shortcode_atts( array( 'display' => 'true' ), $atts, 'latex' );
	$content = trim( (string) $content );

	if ( '' === $content ) {
		return '';
	}

	$wrapped = filter_var( $atts['display'], FILTER_VALIDATE_BOOLEAN )
		? '\\[' . $content . '\\]'
		: '\\(' . $content . '\\)';

	return '<span class="quantumai-latex">' . esc_html( $wrapped ) . '</span>';
}
add_shortcode( 'latex', 'quantumai_latex_shortcode' );
