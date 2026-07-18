<?php
/**
 * Theme-level hardening that maps directly to abuser stories in
 * USER_STORIES.md. This is deliberately small — most of the SSDLC posture
 * (MFA, WAF, DAST, backups, TLS) lives at the infra layer, not the theme.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// AS-03: don't advertise the exact WordPress version to unauthenticated
// visitors; it's free reconnaissance for matching a version to a known CVE.
remove_action( 'wp_head', 'wp_generator' );
add_filter( 'the_generator', '__return_empty_string' );

// These <link> tags advertise XML-RPC-era discovery endpoints. XML-RPC is
// already disabled (mu-plugins/disable-xmlrpc.php and the nginx 403 block),
// so drop the pointers too.
remove_action( 'wp_head', 'rsd_link' );
remove_action( 'wp_head', 'wlwmanifest_link' );

/**
 * AS-01: block the classic ?author=1 style username-enumeration probe used
 * to find valid login names ahead of a brute-force attempt.
 *
 * Must run at a priority earlier than core's redirect_canonical() (also
 * hooked on template_redirect, at the default priority 10) — otherwise core
 * resolves ?author=1 to the pretty /author/<username>/ URL and issues its
 * own 301 first, handing over the username before this ever runs.
 */
function quantumai_block_author_enumeration() {
	if ( is_admin() || ! isset( $_GET['author'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- presence check only (value never read/used), redirecting away from a probe pattern isn't a state change.
		return;
	}

	wp_safe_redirect( home_url( '/' ), 301 );
	exit;
}
add_action( 'template_redirect', 'quantumai_block_author_enumeration', 0 );
