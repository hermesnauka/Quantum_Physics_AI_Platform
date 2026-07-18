<?php
/**
 * SR-02: Authors cannot publish directly; Editors must approve.
 *
 * This is entirely enforced by WordPress core's own capability checks — no
 * custom publish-blocking logic needed. Both the block editor and the REST
 * API decide whether a post can be set to status=publish based on whether
 * the current user has the 'publish_posts' capability; strip that from the
 * Author role and core itself downgrades "Publish" to "Submit for Review"
 * (status=pending) in the UI, and rejects a status=publish REST request
 * with a 403 (rest_cannot_publish) if attempted directly.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs on every request but only writes to the DB (via WP_Role::remove_cap)
 * the first time — has_cap() is checked first so this is a cheap no-op on
 * every subsequent request.
 */
function quantumai_restrict_author_publishing() {
	$author = get_role( 'author' );

	if ( $author && $author->has_cap( 'publish_posts' ) ) {
		$author->remove_cap( 'publish_posts' );
	}
}
add_action( 'init', 'quantumai_restrict_author_publishing' );

/**
 * US-03: notify Editors/Admins when a draft is submitted for review, so the
 * "pending" queue doesn't rely on someone remembering to check it.
 */
function quantumai_notify_editors_of_pending_post( $new_status, $old_status, $post ) {
	if ( 'pending' !== $new_status || 'pending' === $old_status || 'post' !== $post->post_type ) {
		return;
	}

	$reviewers = get_users(
		array(
			'role__in' => array( 'administrator', 'editor' ),
			'fields'   => array( 'user_email', 'display_name' ),
		)
	);

	if ( empty( $reviewers ) ) {
		return;
	}

	$author  = get_userdata( $post->post_author );
	$subject = sprintf(
		/* translators: %s: post title */
		__( '[Review needed] %s', 'quantumai' ),
		html_entity_decode( get_the_title( $post ), ENT_QUOTES )
	);
	$message = sprintf(
		/* translators: 1: author display name, 2: post title, 3: edit link */
		__( "%1\$s submitted \"%2\$s\" for review.\n\nReview it here: %3\$s", 'quantumai' ),
		$author ? $author->display_name : __( 'A user', 'quantumai' ),
		html_entity_decode( get_the_title( $post ), ENT_QUOTES ),
		get_edit_post_link( $post->ID, 'raw' )
	);

	foreach ( $reviewers as $reviewer ) {
		wp_mail( $reviewer->user_email, $subject, $message );
	}
}
add_action( 'transition_post_status', 'quantumai_notify_editors_of_pending_post', 10, 3 );
