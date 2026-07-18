<?php
/**
 * QuantumAI theme bootstrap.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'QUANTUMAI_THEME_VERSION', '0.1.0' );

require get_template_directory() . '/inc/setup.php';
require get_template_directory() . '/inc/taxonomies.php';
require get_template_directory() . '/inc/mathjax.php';
require get_template_directory() . '/inc/security.php';
