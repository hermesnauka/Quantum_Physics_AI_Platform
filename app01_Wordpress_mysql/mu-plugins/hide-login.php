<?php
/**
 * AS-01 "Hide /wp-admin URL": moves the login form to a secret path so an
 * attacker can't even find wp-login.php to brute-force it in the first
 * place (PLAN.md Phase 2 "Custom admin URL"). This is on top of, not
 * instead of, the actual auth hardening (MFA, brute-force lockout below) —
 * obscurity buys time against automated scanners, nothing more.
 *
 * Set the real path via `define('QUANTUMAI_LOGIN_SLUG', '...')` (wired to
 * the WP_LOGIN_SLUG env var in docker-compose.yml); the fallback below is
 * only for local dev where secrecy doesn't matter.
 *
 * How it works:
 *  1. `site_url` filter rewrites every core-generated wp-login.php URL
 *     (login, logout, register, lost-password — they all funnel through
 *     site_url() before their own more specific filters fire) to the
 *     secret slug instead.
 *  2. A request for that slug is served by directly `require`-ing
 *     wp-login.php, from the `wp_loaded` hook — the last thing
 *     wp-settings.php does before returning control to the calling
 *     script. Hooking any earlier (e.g. `plugins_loaded`) breaks: several
 *     core constants — AUTOSAVE_INTERVAL among them — and the `init`
 *     action itself don't run until *after* `plugins_loaded`, and
 *     wp-login.php's rendering assumes all of that already happened, the
 *     same way it would have if wp-login.php had been hit directly.
 *     Because we're already mid-way through this same request's
 *     wp-settings.php execution, wp-login.php's own `require wp-load.php`
 *     resolves as a no-op (PHP's require_once already loaded it) and
 *     execution just continues into wp-login.php's actual login handling
 *     — no separate HTTP round-trip needed.
 *  3. A direct request for the real /wp-login.php, or for /wp-admin/*
 *     while logged out (except admin-ajax.php, which logged-out visitors
 *     legitimately need for things like the comment-reply heartbeat), gets
 *     a plain 404 instead of WordPress's normal "redirect to login" —
 *     that redirect is itself the discovery mechanism this is meant to
 *     close off.
 *
 * Skips all of this while `is_blog_installed()` is false, so it never
 * interferes with the install wizard itself.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class QuantumAI_Hide_Login {

	const DEFAULT_SLUG = 'portal-access';

	public static function init() {
		add_action( 'wp_loaded', array( __CLASS__, 'maybe_serve_login_page' ) );
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_block_direct_access' ), 2 );
		add_filter( 'site_url', array( __CLASS__, 'rewrite_login_links' ), 10, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_warn_default_admin_username' ) );
	}

	private static function slug() {
		return ( defined( 'QUANTUMAI_LOGIN_SLUG' ) && QUANTUMAI_LOGIN_SLUG ) ? QUANTUMAI_LOGIN_SLUG : self::DEFAULT_SLUG;
	}

	private static function request_path() {
		$path = isset( $_SERVER['REQUEST_URI'] ) ? wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ) : '';
		return trim( (string) $path, '/' );
	}

	public static function maybe_serve_login_page() {
		if ( ! is_blog_installed() || self::request_path() !== self::slug() ) {
			return;
		}

		global $pagenow, $error, $interim_login, $action;
		$pagenow = 'wp-login.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- deliberate; see the doc comment below for why this exact global needs setting here.

		// wp-login.php's own top-level code assigns these as bare
		// variables (correct when it's the real entry script, where
		// "top-level" and "global" are the same scope). Since we're
		// `require`-ing it from inside this method instead, those bare
		// assignments would otherwise land in *this method's* local
		// scope — not $GLOBALS — leaving login_header()/login_footer()'s
		// `global $error, $interim_login, $action;` reads with nothing.
		// Declaring them global here first means the required code's
		// later assignments bind to the same $GLOBALS entries instead of
		// shadowing them.
		require ABSPATH . 'wp-login.php';
		exit;
	}

	public static function maybe_block_direct_access() {
		if ( ! is_blog_installed() ) {
			return;
		}

		$path = strtolower( self::request_path() );

		if ( 'wp-login.php' === $path ) {
			self::deny();
		}

		if ( 0 === strpos( $path, 'wp-admin' ) && 'wp-admin/admin-ajax.php' !== $path && ! is_user_logged_in() ) {
			self::deny();
		}
	}

	private static function deny() {
		status_header( 404 );
		nocache_headers();
		wp_die( esc_html__( 'Not Found', 'quantumai' ), '', array( 'response' => 404 ) );
	}

	public static function rewrite_login_links( $url, $path ) {
		if ( is_string( $path ) && false !== strpos( $path, 'wp-login.php' ) ) {
			$url = preg_replace( '#/wp-login\.php#', '/' . self::slug(), $url, 1 );
		}
		return $url;
	}

	public static function maybe_warn_default_admin_username() {
		if ( ! current_user_can( 'manage_options' ) || ! get_user_by( 'login', 'admin' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html__( 'A user with the username "admin" exists — it\'s the first username any brute-force attempt tries. Rename or remove it (AS-01).', 'quantumai' )
		);
	}
}

QuantumAI_Hide_Login::init();
