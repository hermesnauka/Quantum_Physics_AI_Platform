<?php
/**
 * AS-01 "Implement Rate Limiting": nothing else in this stack limits raw
 * password-guessing attempts against wp-login.php — MFA (mfa.php) only
 * gates the *second* factor, after a password has already checked out, and
 * anti-bot.php's honeypot doesn't slow down a bot that simply omits the
 * hidden field and submits real credentials. Fail2Ban (PLAN.md Phase 5) is
 * a complementary infra-level control; this is the in-app backstop that
 * works even without log-based tooling in front of it.
 *
 * Two independent counters, both must clear to proceed:
 *  - per-IP: catches one attacker cycling through many usernames.
 *  - per-username: catches a distributed/botnet attack against one account
 *    from many IPs, which a per-IP-only limiter would miss entirely.
 *
 * The lockout check runs at 'authenticate' priority 22 — deliberately
 * AFTER core's own password check (20), not before. An earlier attempt to
 * short-circuit before 20 (to skip a wasted bcrypt verify on a locked-out
 * request) turned out to be bypassable: wp_authenticate_username_password()
 * ignores a WP_Error an earlier filter already returned (as long as the
 * posted fields aren't empty) and just re-validates the password anyway,
 * silently overwriting our error with a valid WP_User if it happens to be
 * correct. Confirmed by testing: a priority-1 lockout let a 6th attempt
 * with the *correct* password straight through once the 5-failure
 * threshold had already been hit. Running after 20 means we override
 * *its* result instead of being overridden by it.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class QuantumAI_Brute_Force_Protection {

	const IP_PREFIX     = 'quantumai_bf_ip_';
	const USER_PREFIX   = 'quantumai_bf_user_';
	const IP_MAX_FAILS  = 20;
	const USER_MAX_FAILS = 5;
	const LOCKOUT_TTL   = 900; // 15 minutes

	public static function init() {
		add_filter( 'authenticate', array( __CLASS__, 'block_if_locked_out' ), 22, 1 );
		add_action( 'wp_login_failed', array( __CLASS__, 'register_failure' ), 10, 2 );
		add_action( 'wp_login', array( __CLASS__, 'clear_username_lock' ), 10, 2 );
	}

	public static function block_if_locked_out( $user ) {
		$ip       = self::get_client_ip();
		$username = isset( $_POST['log'] ) ? sanitize_user( wp_unslash( $_POST['log'] ), true ) : '';

		if ( self::is_locked_out( $ip, $username ) ) {
			return new WP_Error(
				'quantumai_locked_out',
				__( '<strong>Error:</strong> Too many failed login attempts. Please wait a while and try again.', 'quantumai' )
			);
		}

		return $user;
	}

	private static function is_locked_out( $ip, $username ) {
		$ip_fails = get_transient( self::IP_PREFIX . md5( $ip ) );
		if ( is_array( $ip_fails ) && $ip_fails['count'] >= self::IP_MAX_FAILS ) {
			return true;
		}

		if ( '' !== $username ) {
			$user_fails = get_transient( self::USER_PREFIX . md5( strtolower( $username ) ) );
			if ( is_array( $user_fails ) && $user_fails['count'] >= self::USER_MAX_FAILS ) {
				return true;
			}
		}

		return false;
	}

	public static function register_failure( $username ) {
		self::bump( self::IP_PREFIX . md5( self::get_client_ip() ) );
		if ( '' !== (string) $username ) {
			self::bump( self::USER_PREFIX . md5( strtolower( $username ) ) );
		}
	}

	private static function bump( $key ) {
		$fails = get_transient( $key );
		if ( ! is_array( $fails ) ) {
			$fails = array( 'count' => 0 );
		}
		$fails['count']++;
		set_transient( $key, $fails, self::LOCKOUT_TTL );
	}

	/**
	 * Only the per-username counter clears on a successful login — the
	 * per-IP counter deliberately does not, since a shared IP (office
	 * NAT, VPN exit node) that's mid-brute-force against other accounts
	 * shouldn't get a clean slate just because one legitimate login
	 * happened to succeed from the same address.
	 */
	public static function clear_username_lock( $user_login ) {
		delete_transient( self::USER_PREFIX . md5( strtolower( $user_login ) ) );
	}

	/**
	 * Trusts X-Real-IP because nginx is the sole entry point (see the same
	 * note in audit-log/class-quantumai-audit-log.php).
	 */
	private static function get_client_ip() {
		if ( ! empty( $_SERVER['HTTP_X_REAL_IP'] ) ) {
			return sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_REAL_IP'] ) );
		}
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	}
}

QuantumAI_Brute_Force_Protection::init();
