<?php
/**
 * Generic archive template (category, tag, date, author archives).
 * The 'topic' taxonomy has its own, more descriptive template: taxonomy.php.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
?>

<main id="qa-content" class="qa-content">
	<header>
		<h1><?php the_archive_title(); ?></h1>
		<?php the_archive_description( '<div class="qa-entry-meta">', '</div>' ); ?>
	</header>

	<?php if ( have_posts() ) : ?>

		<?php
		while ( have_posts() ) :
			the_post();
			get_template_part( 'template-parts/content' );
		endwhile;
		?>

		<?php the_posts_pagination(); ?>

	<?php else : ?>

		<p><?php esc_html_e( 'Nothing found.', 'quantumai' ); ?></p>

	<?php endif; ?>
</main>

<?php
get_sidebar();
get_footer();
