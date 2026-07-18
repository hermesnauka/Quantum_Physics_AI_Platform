<?php
/**
 * Main template fallback (used for the blog home and anywhere a more
 * specific template doesn't match).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
?>

<main id="qa-content" class="qa-content">
	<?php if ( have_posts() ) : ?>

		<?php
		while ( have_posts() ) :
			the_post();
			get_template_part( 'template-parts/content' );
		endwhile;
		?>

		<?php the_posts_pagination(); ?>

	<?php else : ?>

		<p><?php esc_html_e( 'Nothing has been published yet.', 'quantumai' ); ?></p>

	<?php endif; ?>
</main>

<?php
get_sidebar();
get_footer();
