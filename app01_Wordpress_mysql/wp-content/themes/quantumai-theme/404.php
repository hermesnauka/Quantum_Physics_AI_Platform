<?php
/**
 * 404 template.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
?>

<main id="qa-content" class="qa-content">
	<h1><?php esc_html_e( 'Page not found', 'quantumai' ); ?></h1>
	<p><?php esc_html_e( 'The page you were looking for doesn\'t exist or has moved.', 'quantumai' ); ?></p>
	<?php get_search_form(); ?>
</main>

<?php
get_footer();
