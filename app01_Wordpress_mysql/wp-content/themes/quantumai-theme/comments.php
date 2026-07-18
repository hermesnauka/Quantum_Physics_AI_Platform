<?php
/**
 * Comments template.
 *
 * AS-02 (XSS via comments) is mitigated entirely by WordPress core here:
 * comment_form() emits its own CSRF nonce, and wp_list_comments() /
 * get_comment_text() run comment output through the 'comment_text' filter,
 * which applies wp_kses() with a restrictive allowed-tags list by default.
 * There is no raw/unescaped comment output in this theme to introduce a
 * regression.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( post_password_required() ) {
	return;
}
?>

<div class="qa-comments" id="comments">
	<?php if ( have_comments() ) : ?>
		<h2 class="qa-comments-title">
			<?php
			printf(
				/* translators: %s: number of comments */
				esc_html( _n( '%s comment', '%s comments', get_comments_number(), 'quantumai' ) ),
				number_format_i18n( get_comments_number() )
			);
			?>
		</h2>

		<ol class="qa-comment-list">
			<?php
			wp_list_comments(
				array(
					'style'      => 'ol',
					'short_ping' => true,
				)
			);
			?>
		</ol>

		<?php the_comments_pagination(); ?>
	<?php endif; ?>

	<?php if ( ! comments_open() && get_comments_number() ) : ?>
		<p class="qa-comments-closed"><?php esc_html_e( 'Comments are closed.', 'quantumai' ); ?></p>
	<?php endif; ?>

	<?php comment_form(); ?>
</div>
