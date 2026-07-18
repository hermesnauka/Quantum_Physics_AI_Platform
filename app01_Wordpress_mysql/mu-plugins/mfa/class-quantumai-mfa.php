<?php
/**
 * SR-01: mandatory MFA for every role above Subscriber (Author, Editor,
 * Administrator).
 *
 * Flow:
 *  1. `authenticate` filter (priority 30, after core's password checks at
 *     priority 20) intercepts an otherwise-successful login for a
 *     qualifying user. It never returns the WP_User back to core — instead
 *     it redirects to a token-gated screen on wp-login.php and exit()s, so
 *     there is no code path that establishes a session without passing the
 *     second factor.
 *  2. `login_init` (fires before wp-login.php's own action switch) renders
 *     that screen: TOTP enrollment (first time) or verification
 *     (subsequent logins), including a recovery-code fallback.
 *  3. Session establishment (wp_set_auth_cookie + the wp_login action) only
 *     happens once the second factor is confirmed, mirroring what
 *     wp_signon() does internally on a normal login.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class QuantumAI_MFA {

	const SECRET_META         = '_quantumai_mfa_secret';
	const RECOVERY_META       = '_quantumai_mfa_recovery_codes';
	const TOKEN_PARAM         = 'quantumai_mfa_token';
	const PENDING_TTL         = 300; // 5 minutes
	const MAX_ATTEMPTS        = 5;
	const LOCKOUT_TTL         = 600; // 10 minutes
	const RECOVERY_CODE_COUNT = 8;

	public static function init() {
		add_filter( 'authenticate', array( __CLASS__, 'intercept_login' ), 30, 1 );
		add_action( 'login_init', array( __CLASS__, 'handle_login_init' ) );

		add_action( 'show_user_profile', array( __CLASS__, 'render_own_profile_section' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'render_other_profile_section' ) );

		add_action( 'admin_post_quantumai_mfa_regenerate_recovery_codes', array( __CLASS__, 'handle_regenerate_recovery_codes' ) );
		add_action( 'admin_post_quantumai_mfa_reset_user', array( __CLASS__, 'handle_reset_user' ) );

		add_action( 'admin_notices', array( __CLASS__, 'maybe_show_low_recovery_codes_notice' ) );
	}

	public static function required_roles() {
		return array( 'administrator', 'editor', 'author' );
	}

	public static function user_requires_mfa( WP_User $user ) {
		return (bool) array_intersect( self::required_roles(), (array) $user->roles );
	}

	public static function user_has_enrolled( $user_id ) {
		return (bool) get_user_meta( $user_id, self::SECRET_META, true );
	}

	// ---------------------------------------------------------------
	// Step 1: intercept an otherwise-successful password check.
	// ---------------------------------------------------------------

	public static function intercept_login( $user ) {
		if ( ! ( $user instanceof WP_User ) ) {
			return $user;
		}

		if ( ! self::user_requires_mfa( $user ) ) {
			return $user;
		}

		$token = bin2hex( random_bytes( 32 ) );
		$stage = self::user_has_enrolled( $user->ID ) ? 'verify' : 'enroll';

		$pending = array(
			'user_id'     => $user->ID,
			'stage'       => $stage,
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- core's own wp-login.php POST carries no nonce (username+password is the proof); nothing to verify against here.
			'remember'    => ! empty( $_POST['rememberme'] ),
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- same: reading core's own unauthenticated login-form redirect target, not a state change.
			'redirect_to' => ! empty( $_REQUEST['redirect_to'] ) ? wp_unslash( $_REQUEST['redirect_to'] ) : admin_url(),
		);

		if ( 'enroll' === $stage ) {
			$pending['secret'] = QuantumAI_TOTP::generate_secret();
		}

		set_transient( 'quantumai_mfa_' . $token, $pending, self::PENDING_TTL );

		wp_safe_redirect(
			add_query_arg( self::TOKEN_PARAM, $token, wp_login_url() )
		);
		exit;
	}

	// ---------------------------------------------------------------
	// Step 2: render/process the challenge screen.
	// ---------------------------------------------------------------

	public static function handle_login_init() {
		if ( empty( $_REQUEST[ self::TOKEN_PARAM ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- this token *is* the anti-CSRF control: an unguessable 256-bit value (bin2hex(random_bytes(32)) above) looked up server-side via get_transient(), functionally equivalent to a nonce rather than absent one.
			return;
		}

		$token   = sanitize_text_field( wp_unslash( $_REQUEST[ self::TOKEN_PARAM ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see above.
		$pending = get_transient( 'quantumai_mfa_' . $token );

		if ( ! is_array( $pending ) || empty( $pending['user_id'] ) ) {
			self::render_message_screen(
				__( 'Verification link expired', 'quantumai' ),
				__( 'This verification link has expired or was already used. Please log in again.', 'quantumai' ),
				true
			);
			exit;
		}

		$user = get_user_by( 'id', $pending['user_id'] );
		if ( ! $user ) {
			delete_transient( 'quantumai_mfa_' . $token );
			self::render_message_screen(
				__( 'Account not found', 'quantumai' ),
				__( 'Please log in again.', 'quantumai' ),
				true
			);
			exit;
		}

		$is_post = ( 'POST' === $_SERVER['REQUEST_METHOD'] );

		if ( 'enroll' === $pending['stage'] ) {
			self::handle_enroll( $token, $pending, $user, $is_post );
		} else {
			self::handle_verify( $token, $pending, $user, $is_post );
		}

		exit;
	}

	private static function handle_enroll( $token, $pending, WP_User $user, $is_post ) {
		$error = '';

		if ( $is_post ) {
			if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['_wpnonce'] ), 'quantumai_mfa_' . $token ) ) {
				$error = __( 'Security check failed. Please try again.', 'quantumai' );
			} elseif ( self::is_locked_out( $user->ID ) ) {
				$error = __( 'Too many failed attempts. Please wait a few minutes and log in again.', 'quantumai' );
			} else {
				$code = isset( $_POST['quantumai_mfa_code'] ) ? sanitize_text_field( wp_unslash( $_POST['quantumai_mfa_code'] ) ) : '';

				if ( QuantumAI_TOTP::verify( $pending['secret'], $code ) ) {
					update_user_meta( $user->ID, self::SECRET_META, $pending['secret'] );

					$recovery_codes = self::generate_recovery_codes();
					self::store_recovery_codes( $user->ID, $recovery_codes );

					self::clear_failures( $user->ID );
					delete_transient( 'quantumai_mfa_' . $token );

					self::establish_session( $user, $pending['remember'] );

					self::render_recovery_codes_screen( $recovery_codes, $pending['redirect_to'] );
					return;
				}

				self::register_failure( $user->ID );
				$error = __( 'That code did not match. Double-check your authenticator app and try again.', 'quantumai' );
			}
		}

		self::render_enroll_screen( $token, $pending, $user, $error );
	}

	private static function handle_verify( $token, $pending, WP_User $user, $is_post ) {
		$error = '';

		if ( $is_post ) {
			if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['_wpnonce'] ), 'quantumai_mfa_' . $token ) ) {
				$error = __( 'Security check failed. Please try again.', 'quantumai' );
			} elseif ( self::is_locked_out( $user->ID ) ) {
				$error = __( 'Too many failed attempts. Please wait a few minutes and log in again.', 'quantumai' );
			} elseif ( ! empty( $_POST['quantumai_mfa_recovery_code'] ) ) {
				$submitted = sanitize_text_field( wp_unslash( $_POST['quantumai_mfa_recovery_code'] ) );
				$remaining = self::consume_recovery_code( $user->ID, $submitted );

				if ( false !== $remaining ) {
					self::clear_failures( $user->ID );
					delete_transient( 'quantumai_mfa_' . $token );
					self::notify_recovery_code_used( $user, $remaining );
					self::establish_session( $user, $pending['remember'] );
					wp_safe_redirect( $pending['redirect_to'] );
					return;
				}

				self::register_failure( $user->ID );
				$error = __( 'That recovery code is not valid. Codes can only be used once.', 'quantumai' );
			} else {
				$secret = get_user_meta( $user->ID, self::SECRET_META, true );
				$code   = isset( $_POST['quantumai_mfa_code'] ) ? sanitize_text_field( wp_unslash( $_POST['quantumai_mfa_code'] ) ) : '';

				if ( $secret && QuantumAI_TOTP::verify( $secret, $code ) ) {
					self::clear_failures( $user->ID );
					delete_transient( 'quantumai_mfa_' . $token );
					self::establish_session( $user, $pending['remember'] );
					wp_safe_redirect( $pending['redirect_to'] );
					return;
				}

				self::register_failure( $user->ID );
				$error = __( 'That code did not match. Try again.', 'quantumai' );
			}
		}

		self::render_verify_screen( $token, $user, $error );
	}

	private static function establish_session( WP_User $user, $remember ) {
		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, (bool) $remember );
		do_action( 'wp_login', $user->user_login, $user );
	}

	// ---------------------------------------------------------------
	// Rate limiting (per account; complements infra-level Fail2Ban).
	// ---------------------------------------------------------------

	private static function is_locked_out( $user_id ) {
		$fails = get_transient( 'quantumai_mfa_fails_' . $user_id );
		return is_array( $fails ) && $fails['count'] >= self::MAX_ATTEMPTS;
	}

	private static function register_failure( $user_id ) {
		$fails = get_transient( 'quantumai_mfa_fails_' . $user_id );
		if ( ! is_array( $fails ) ) {
			$fails = array( 'count' => 0 );
		}
		++$fails['count'];
		set_transient( 'quantumai_mfa_fails_' . $user_id, $fails, self::LOCKOUT_TTL );
	}

	private static function clear_failures( $user_id ) {
		delete_transient( 'quantumai_mfa_fails_' . $user_id );
	}

	// ---------------------------------------------------------------
	// Recovery codes.
	// ---------------------------------------------------------------

	private static function generate_recovery_codes( $count = self::RECOVERY_CODE_COUNT ) {
		$codes = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$raw     = strtoupper( substr( bin2hex( random_bytes( 5 ) ), 0, 10 ) );
			$codes[] = substr( $raw, 0, 5 ) . '-' . substr( $raw, 5, 5 );
		}
		return $codes;
	}

	private static function store_recovery_codes( $user_id, array $plaintext_codes ) {
		$hashed = array_map( 'wp_hash_password', $plaintext_codes );
		update_user_meta( $user_id, self::RECOVERY_META, $hashed );
	}

	/**
	 * @return int|false Remaining code count on success, false if no match.
	 */
	private static function consume_recovery_code( $user_id, $submitted ) {
		$hashed = get_user_meta( $user_id, self::RECOVERY_META, true );
		if ( ! is_array( $hashed ) || empty( $hashed ) ) {
			return false;
		}

		$submitted = strtoupper( trim( $submitted ) );

		foreach ( $hashed as $index => $hash ) {
			if ( wp_check_password( $submitted, $hash ) ) {
				unset( $hashed[ $index ] );
				$hashed = array_values( $hashed );
				update_user_meta( $user_id, self::RECOVERY_META, $hashed );
				return count( $hashed );
			}
		}

		return false;
	}

	private static function notify_recovery_code_used( WP_User $user, $remaining ) {
		$subject = sprintf(
			/* translators: %s: site name */
			__( '[%s] A recovery code was used to sign in', 'quantumai' ),
			get_bloginfo( 'name' )
		);

		$message = sprintf(
			/* translators: 1: number of codes left, 2: site name */
			__( "A two-factor recovery code was just used to sign in to your account (%2\$s).\n\n%1\$d recovery code(s) remain. If this wasn't you, change your password immediately.\n\nIf you're running low on codes, generate a fresh set from your profile page.", 'quantumai' ),
			$remaining,
			get_bloginfo( 'name' )
		);

		wp_mail( $user->user_email, $subject, $message );
	}

	public static function maybe_show_low_recovery_codes_notice() {
		$user_id = get_current_user_id();
		if ( ! $user_id || ! self::user_has_enrolled( $user_id ) ) {
			return;
		}

		$hashed = get_user_meta( $user_id, self::RECOVERY_META, true );
		$count  = is_array( $hashed ) ? count( $hashed ) : 0;

		if ( $count > 2 ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %d: number of recovery codes left */
					_n(
						'Only %d two-factor recovery code left. Generate a new set from your profile page.',
						'Only %d two-factor recovery codes left. Generate a new set from your profile page.',
						$count,
						'quantumai'
					),
					$count
				)
			)
		);
	}

	// ---------------------------------------------------------------
	// Profile page: status + self-service recovery code regeneration,
	// plus an admin-only reset for another user's MFA enrollment.
	// ---------------------------------------------------------------

	public static function render_own_profile_section( WP_User $user ) {
		if ( ! self::user_requires_mfa( $user ) ) {
			return;
		}

		$enrolled = self::user_has_enrolled( $user->ID );

		if ( $enrolled && ! empty( $_GET['quantumai_mfa_reveal'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- presence check only; the actual codes come from a transient set by handle_regenerate_recovery_codes(), which does verify a nonce before creating it.
			$codes = get_transient( 'quantumai_mfa_reveal_' . $user->ID );
			if ( is_array( $codes ) ) {
				delete_transient( 'quantumai_mfa_reveal_' . $user->ID );
				?>
				<div class="notice notice-success">
					<p><strong><?php esc_html_e( "New recovery codes generated. Save these now — they won't be shown again:", 'quantumai' ); ?></strong></p>
					<pre style="background:#f0f0f1; padding:1em; font-size:1.1em; line-height:1.8;"><?php echo esc_html( implode( "\n", $codes ) ); ?></pre>
				</div>
				<?php
			}
		}
		?>
		<h2><?php esc_html_e( 'Two-Factor Authentication', 'quantumai' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><?php esc_html_e( 'Status', 'quantumai' ); ?></th>
				<td>
					<?php if ( $enrolled ) : ?>
						<p><span style="color:#2c4bd4;">&#10003;</span> <?php esc_html_e( 'Enabled. Required for your role.', 'quantumai' ); ?></p>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="quantumai_mfa_regenerate_recovery_codes" />
							<?php wp_nonce_field( 'quantumai_mfa_regenerate_' . $user->ID ); ?>
							<?php submit_button( __( 'Generate new recovery codes', 'quantumai' ), 'secondary', 'submit', false ); ?>
							<p class="description"><?php esc_html_e( 'This invalidates any recovery codes you were given before.', 'quantumai' ); ?></p>
						</form>
					<?php else : ?>
						<p><?php esc_html_e( "Not yet set up — you'll be asked to enroll the next time you log in. This is mandatory for your role.", 'quantumai' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
		</table>
		<?php
	}

	public static function render_other_profile_section( WP_User $user ) {
		if ( ! self::user_requires_mfa( $user ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$enrolled = self::user_has_enrolled( $user->ID );
		?>
		<h2><?php esc_html_e( 'Two-Factor Authentication', 'quantumai' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><?php esc_html_e( 'Status', 'quantumai' ); ?></th>
				<td>
					<p><?php echo $enrolled ? esc_html__( 'Enabled', 'quantumai' ) : esc_html__( 'Not yet set up', 'quantumai' ); ?></p>
					<?php if ( $enrolled ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'This signs the user out of two-factor and forces them to re-enroll at next login. Continue?', 'quantumai' ) ); ?>');">
							<input type="hidden" name="action" value="quantumai_mfa_reset_user" />
							<input type="hidden" name="user_id" value="<?php echo esc_attr( $user->ID ); ?>" />
							<?php wp_nonce_field( 'quantumai_mfa_reset_' . $user->ID ); ?>
							<?php submit_button( __( 'Reset two-factor enrollment', 'quantumai' ), 'delete', 'submit', false ); ?>
							<p class="description"><?php esc_html_e( 'Use this if the user lost their device and needs to re-enroll.', 'quantumai' ); ?></p>
						</form>
					<?php endif; ?>
				</td>
			</tr>
		</table>
		<?php
	}

	public static function handle_regenerate_recovery_codes() {
		$user_id = get_current_user_id();

		if ( ! $user_id || ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['_wpnonce'] ), 'quantumai_mfa_regenerate_' . $user_id ) ) {
			wp_die( esc_html__( 'Security check failed.', 'quantumai' ) );
		}

		$codes = self::generate_recovery_codes();
		self::store_recovery_codes( $user_id, $codes );

		set_transient( 'quantumai_mfa_reveal_' . $user_id, $codes, 60 );

		wp_safe_redirect( add_query_arg( 'quantumai_mfa_reveal', '1', admin_url( 'profile.php' ) ) );
		exit;
	}

	public static function handle_reset_user() {
		$target_id = isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0;

		if ( ! current_user_can( 'manage_options' ) || ! $target_id
			|| ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['_wpnonce'] ), 'quantumai_mfa_reset_' . $target_id )
		) {
			wp_die( esc_html__( 'Security check failed.', 'quantumai' ) );
		}

		delete_user_meta( $target_id, self::SECRET_META );
		delete_user_meta( $target_id, self::RECOVERY_META );

		wp_safe_redirect( add_query_arg( 'quantumai_mfa_reset', '1', admin_url( 'user-edit.php?user_id=' . $target_id ) ) );
		exit;
	}

	// ---------------------------------------------------------------
	// wp-login.php screens.
	// ---------------------------------------------------------------

	private static function render_enroll_screen( $token, $pending, WP_User $user, $error ) {
		$uri = QuantumAI_TOTP::provisioning_uri( $pending['secret'], $user->user_login, get_bloginfo( 'name' ) );

		login_header( __( 'Set up two-factor authentication', 'quantumai' ), '', $error ? new WP_Error( 'quantumai_mfa', $error ) : '' );
		?>
		<form method="post" action="<?php echo esc_url( add_query_arg( self::TOKEN_PARAM, $token, wp_login_url() ) ); ?>">
			<p><?php esc_html_e( 'Your role requires two-factor authentication. Scan this into an authenticator app (Google Authenticator, 1Password, Authy, etc.), or enter the key manually:', 'quantumai' ); ?></p>
			<p><code style="font-size:1.1em; word-break:break-all;"><?php echo esc_html( $pending['secret'] ); ?></code></p>
			<p class="description"><?php echo esc_html( $uri ); ?></p>
			<p>
				<label for="quantumai_mfa_code"><?php esc_html_e( 'Enter the 6-digit code from your app to confirm setup', 'quantumai' ); ?></label>
				<input type="text" name="quantumai_mfa_code" id="quantumai_mfa_code" class="input" inputmode="numeric" pattern="[0-9]*" autocomplete="one-time-code" maxlength="6" autofocus />
			</p>
			<?php wp_nonce_field( 'quantumai_mfa_' . $token ); ?>
			<p class="submit">
				<input type="submit" class="button button-primary button-large" value="<?php esc_attr_e( 'Confirm and enable', 'quantumai' ); ?>" />
			</p>
		</form>
		<?php
		login_footer();
	}

	private static function render_verify_screen( $token, WP_User $user, $error ) {
		login_header( __( 'Two-factor verification', 'quantumai' ), '', $error ? new WP_Error( 'quantumai_mfa', $error ) : '' );
		?>
		<form method="post" action="<?php echo esc_url( add_query_arg( self::TOKEN_PARAM, $token, wp_login_url() ) ); ?>">
			<p><?php esc_html_e( 'Enter the 6-digit code from your authenticator app.', 'quantumai' ); ?></p>
			<p>
				<label for="quantumai_mfa_code"><?php esc_html_e( 'Verification code', 'quantumai' ); ?></label>
				<input type="text" name="quantumai_mfa_code" id="quantumai_mfa_code" class="input" inputmode="numeric" pattern="[0-9]*" autocomplete="one-time-code" maxlength="6" autofocus />
			</p>
			<?php wp_nonce_field( 'quantumai_mfa_' . $token ); ?>
			<p class="submit">
				<input type="submit" class="button button-primary button-large" value="<?php esc_attr_e( 'Verify', 'quantumai' ); ?>" />
			</p>
		</form>
		<form method="post" action="<?php echo esc_url( add_query_arg( self::TOKEN_PARAM, $token, wp_login_url() ) ); ?>">
			<p><?php esc_html_e( 'Lost your device?', 'quantumai' ); ?></p>
			<p>
				<label for="quantumai_mfa_recovery_code"><?php esc_html_e( 'Enter a recovery code instead', 'quantumai' ); ?></label>
				<input type="text" name="quantumai_mfa_recovery_code" id="quantumai_mfa_recovery_code" class="input" autocomplete="off" />
			</p>
			<?php wp_nonce_field( 'quantumai_mfa_' . $token ); ?>
			<p class="submit">
				<input type="submit" class="button button-secondary" value="<?php esc_attr_e( 'Use recovery code', 'quantumai' ); ?>" />
			</p>
		</form>
		<p id="backtoblog"><a href="<?php echo esc_url( wp_login_url() ); ?>"><?php esc_html_e( '&larr; Back to login', 'quantumai' ); ?></a></p>
		<?php
		login_footer();
	}

	private static function render_recovery_codes_screen( array $codes, $redirect_to ) {
		login_header( __( 'Save your recovery codes', 'quantumai' ), '', '' );
		?>
		<p><strong><?php esc_html_e( "Two-factor authentication is now enabled. Save these one-time recovery codes somewhere safe — this is the only time they'll be shown.", 'quantumai' ); ?></strong></p>
		<p><?php esc_html_e( 'Each code can be used once if you lose access to your authenticator app.', 'quantumai' ); ?></p>
		<pre style="background:#f0f0f1; padding:1em; font-size:1.1em; line-height:1.8;"><?php echo esc_html( implode( "\n", $codes ) ); ?></pre>
		<p class="submit">
			<a class="button button-primary button-large" href="<?php echo esc_url( $redirect_to ); ?>"><?php esc_html_e( "I've saved these — continue", 'quantumai' ); ?></a>
		</p>
		<?php
		login_footer();
	}

	private static function render_message_screen( $title, $message, $is_error ) {
		login_header( $title, '', $is_error ? new WP_Error( 'quantumai_mfa', $message ) : '' );
		if ( ! $is_error ) {
			echo '<p>' . esc_html( $message ) . '</p>';
		}
		?>
		<p id="backtoblog"><a href="<?php echo esc_url( wp_login_url() ); ?>"><?php esc_html_e( '&larr; Back to login', 'quantumai' ); ?></a></p>
		<?php
		login_footer();
	}
}
