<?php
/**
 * FR-03: archive for the 'topic' taxonomy (Quantum Computing, AI/LLMs/LRMs,
 * Quantum Physics, Post-Quantum Cryptography).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

$term = get_queried_object();
?>

<main id="qa-content" class="qa-content">
	<header>
		<h1><?php echo esc_html( single_term_title( '', false ) ); ?></h1>
		<?php if ( $term instanceof WP_Term && $term->description ) : ?>
			<div class="qa-entry-meta"><?php echo wp_kses_post( $term->description ); ?></div>
		<?php endif; ?>
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

		<p><?php esc_html_e( 'No articles have been published under this topic yet.', 'quantumai' ); ?></p>

	<?php endif; ?>
</main>

<?php
get_sidebar();
get_footer();
