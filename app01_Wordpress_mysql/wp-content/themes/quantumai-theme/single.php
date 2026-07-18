<?php
/**
 * Single post template. MathJax is enqueued (when needed) by
 * inc/mathjax.php's wp_enqueue_scripts hook, keyed off is_singular().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
?>

<main id="qa-content" class="qa-content">
	<?php
	while ( have_posts() ) :
		the_post();
		get_template_part( 'template-parts/content', 'single' );

		if ( comments_open() || get_comments_number() ) {
			comments_template();
		}
	endwhile;
	?>
</main>

<?php
get_sidebar();
get_footer();
