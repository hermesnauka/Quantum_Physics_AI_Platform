<?php
/**
 * SR-05: anti-bot protection on login, registration, and comment forms.
 *
 * Deliberately a honeypot + minimum-fill-time check rather than an
 * image/puzzle CAPTCHA, for the same reason MFA and the audit log are
 * hand-rolled instead of pulled in as plugins: no third-party JS, no
 * external service call (a real CAPTCHA widget is itself a script-src
 * exception in the CSP, and often a third-party tracker), and it degrades
 * gracefully for screen-reader/keyboard users since there's no puzzle to
 * solve. Trade-off: it stops unsophisticated/scripted bots (the vast
 * majority of WP login and comment spam), not a targeted human attacker or
 * a bot built specifically against this site.
 *
 * - Honeypot: a field styled off-screen (not display:none, which some
 *   scrapers already know to detect) that a human never sees or fills, but
 *   generic form-filling bots often do.
 * - Timing: a signed timestamp rejects submissions faster than a human
 *   could plausibly complete the form. Not applied to the login form,
 *   where a password manager can legitimately autofill+submit in well
 *   under a second.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class QuantumAI_Anti_Bot {

	const HONEYPOT_FIELD   = 'quantumai_hp_field';
	const TIMESTAMP_FIELD  = 'quantumai_hp_ts';
	const MIN_FILL_SECONDS = 3;

	public static function init() {
		add_action( 'login_form', array( __CLASS__, 'render_honeypot_only' ) );
		// Priority 21: must run AFTER core's own password check (20), not
		// before. wp_authenticate_username_password() doesn't respect a
		// WP_Error an earlier filter already set (unless the posted fields
		// are empty) — it re-validates the password regardless and
		// happily overwrites our error with a valid WP_User if it's
		// correct. So the honeypot only has teeth if it runs after and
		// overrides that result, not before it.
		add_filter( 'authenticate', array( __CLASS__, 'check_login' ), 21, 1 );

		add_action( 'register_form', array( __CLASS__, 'render_honeypot_and_timing' ) );
		add_filter( 'registration_errors', array( __CLASS__, 'check_registration' ), 10, 1 );

		add_action( 'comment_form_after_fields', array( __CLASS__, 'render_honeypot_and_timing' ) );
		add_action( 'comment_form_logged_in_after', array( __CLASS__, 'render_honeypot_and_timing' ) );
		add_filter( 'preprocess_comment', array( __CLASS__, 'check_comment' ) );
	}

	// ---------------------------------------------------------------
	// Rendering.
	// ---------------------------------------------------------------

	private static function honeypot_markup() {
		return sprintf(
			'<div style="position:absolute;left:-9999px;top:-9999px;" aria-hidden="true">' .
			'<label for="%1$s">Leave this field empty</label>' .
			'<input type="text" name="%1$s" id="%1$s" tabindex="-1" autocomplete="off" value="" />' .
			'</div>',
			esc_attr( self::HONEYPOT_FIELD )
		);
	}

	private static function timestamp_markup() {
		return sprintf(
			'<input type="hidden" name="%s" value="%s" />',
			esc_attr( self::TIMESTAMP_FIELD ),
			esc_attr( self::sign_timestamp( time() ) )
		);
	}

	public static function render_honeypot_only() {
		echo self::honeypot_markup(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_attr() above.
	}

	public static function render_honeypot_and_timing() {
		echo self::honeypot_markup() . self::timestamp_markup(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	// ---------------------------------------------------------------
	// Signed timestamp: prevents a bot from just sending an old-looking
	// timestamp to skip the timing check.
	// ---------------------------------------------------------------

	private static function sign_timestamp( $ts ) {
		return $ts . '.' . hash_hmac( 'sha256', (string) $ts, wp_salt( 'auth' ) );
	}

	/**
	 * @return int|false Seconds elapsed since the form was rendered, or
	 *                    false if the field is missing/tampered with.
	 */
	private static function elapsed_seconds_since_render() {
		if ( empty( $_POST[ self::TIMESTAMP_FIELD ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- this *is* our own HMAC-signed anti-bot check (sign_timestamp()/hash_equals() below), not a state change needing a WP nonce.
			return false;
		}

		$value = sanitize_text_field( wp_unslash( $_POST[ self::TIMESTAMP_FIELD ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- see the check above; same HMAC-signed value, not a nonce.
		$parts = explode( '.', $value, 2 );
		if ( 2 !== count( $parts ) || ! ctype_digit( $parts[0] ) ) {
			return false;
		}

		if ( ! hash_equals( self::sign_timestamp( (int) $parts[0] ), $value ) ) {
			return false;
		}

		return time() - (int) $parts[0];
	}

	private static function honeypot_filled() {
		return ! empty( $_POST[ self::HONEYPOT_FIELD ] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- presence check only, no state change; a nonce here would just be one more field for a bot to helpfully echo back.
	}

	// ---------------------------------------------------------------
	// Checks. Errors are deliberately generic — never mention the
	// honeypot/timing so scanners can't fingerprint and route around it.
	// ---------------------------------------------------------------

	public static function check_login( $user ) {
		if ( self::honeypot_filled() ) {
			return new WP_Error( 'quantumai_bot', __( '<strong>Error:</strong> Invalid username, email address or incorrect password.', 'quantumai' ) );
		}
		return $user;
	}

	public static function check_registration( WP_Error $errors ) {
		if ( self::honeypot_filled() ) {
			$errors->add( 'quantumai_bot', __( '<strong>Error:</strong> Registration could not be completed. Please try again.', 'quantumai' ) );
			return $errors;
		}

		$elapsed = self::elapsed_seconds_since_render();
		if ( false === $elapsed || $elapsed < self::MIN_FILL_SECONDS ) {
			$errors->add( 'quantumai_bot', __( '<strong>Error:</strong> Registration could not be completed. Please try again.', 'quantumai' ) );
		}

		return $errors;
	}

	public static function check_comment( $commentdata ) {
		if ( self::honeypot_filled() ) {
			wp_die( esc_html__( 'Your comment could not be submitted. Please go back and try again.', 'quantumai' ), 403 );
		}

		$elapsed = self::elapsed_seconds_since_render();
		if ( false === $elapsed || $elapsed < self::MIN_FILL_SECONDS ) {
			wp_die( esc_html__( 'Your comment could not be submitted. Please go back and try again.', 'quantumai' ), 403 );
		}

		return $commentdata;
	}
}

QuantumAI_Anti_Bot::init();
