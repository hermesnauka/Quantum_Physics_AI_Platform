<?php
/**
 * Teaser card used in the main loop (index/archive/taxonomy/search).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
	<header>
		<?php if ( is_singular() ) : ?>
			<h2 class="qa-entry-title"><?php the_title(); ?></h2>
		<?php else : ?>
			<h2 class="qa-entry-title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
		<?php endif; ?>

		<div class="qa-entry-meta">
			<?php
			printf(
				/* translators: 1: published date, 2: author display name */
				esc_html__( 'Published %1$s by %2$s', 'quantumai' ),
				esc_html( get_the_date() ),
				esc_html( get_the_author() )
			);
			?>
		</div>

		<?php
		$topics = get_the_terms( get_the_ID(), 'topic' );
		if ( $topics && ! is_wp_error( $topics ) ) :
			?>
			<div class="qa-entry-topics">
				<?php
				$topic_links = array();
				foreach ( $topics as $topic ) {
					$topic_links[] = sprintf(
						'<a href="%1$s">%2$s</a>',
						esc_url( get_term_link( $topic ) ),
						esc_html( $topic->name )
					);
				}
				echo wp_kses_post( implode( ', ', $topic_links ) );
				?>
			</div>
		<?php endif; ?>
	</header>

	<div class="qa-entry-summary">
		<?php the_excerpt(); ?>
	</div>
</article>
