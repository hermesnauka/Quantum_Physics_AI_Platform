<?php
/**
 * Full single-post content, including rendered LaTeX in the body.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
	<header>
		<h1 class="qa-entry-title"><?php the_title(); ?></h1>

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

	<div class="qa-entry-content">
		<?php
		the_content();

		wp_link_pages(
			array(
				'before' => '<nav class="qa-page-links">' . esc_html__( 'Pages:', 'quantumai' ),
				'after'  => '</nav>',
			)
		);
		?>
	</div>
</article>
