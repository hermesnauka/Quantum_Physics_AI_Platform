<?php
/**
 * SR-06: append-only audit trail of security-relevant events.
 *
 * Stored in a dedicated table (not wp_options/postmeta) so a compromised
 * low-privilege account, or a plugin with SQL access but no schema
 * knowledge, can't casually alter it, and so volume here never bloats the
 * autoloaded options WordPress pulls on every request.
 *
 * mu-plugins have no activation hook (WordPress never "activates" them —
 * they just run), so the table is created lazily on `plugins_loaded` the
 * first time the stored schema version doesn't match.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class QuantumAI_Audit_Log {

	const DB_VERSION_OPTION = 'quantumai_audit_log_db_version';
	const DB_VERSION        = '1.0';
	const CRON_HOOK         = 'quantumai_audit_log_prune';
	const MENU_SLUG         = 'quantumai-audit-log';
	const PER_PAGE          = 50;
	const EXPORT_ROW_CAP    = 10000;

	/**
	 * Options whose *value* isn't logged (some, like active_plugins, are
	 * large serialized arrays with no security-relevant content of their
	 * own) — only the fact that one of them changed, and by whom.
	 */
	const WATCHED_OPTIONS = array(
		'active_plugins',
		'template',
		'stylesheet',
		'users_can_register',
		'default_role',
		'siteurl',
		'home',
		'admin_email',
	);

	public static function init() {
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_upgrade_db' ) );
		add_action( 'init', array( __CLASS__, 'maybe_schedule_prune' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'prune' ) );

		add_action( 'wp_login', array( __CLASS__, 'log_login_success' ), 10, 2 );
		add_action( 'wp_login_failed', array( __CLASS__, 'log_login_failed' ), 10, 2 );
		add_action( 'wp_logout', array( __CLASS__, 'log_logout' ) );

		add_action( 'user_register', array( __CLASS__, 'log_user_register' ) );
		add_action( 'delete_user', array( __CLASS__, 'log_user_delete' ), 10, 3 );
		add_action( 'set_user_role', array( __CLASS__, 'log_role_change' ), 10, 3 );
		add_action( 'password_reset', array( __CLASS__, 'log_password_reset' ), 10, 2 );

		add_action( 'transition_post_status', array( __CLASS__, 'log_post_status_transition' ), 10, 3 );
		add_action( 'before_delete_post', array( __CLASS__, 'log_post_delete' ) );

		add_action( 'activated_plugin', array( __CLASS__, 'log_plugin_activated' ) );
		add_action( 'deactivated_plugin', array( __CLASS__, 'log_plugin_deactivated' ) );
		add_action( 'switch_theme', array( __CLASS__, 'log_theme_switch' ) );
		add_action( 'updated_option', array( __CLASS__, 'log_option_update' ), 10, 3 );

		add_action( 'admin_menu', array( __CLASS__, 'register_admin_page' ) );
		add_action( 'admin_post_quantumai_audit_log_export', array( __CLASS__, 'handle_export' ) );
	}

	// ---------------------------------------------------------------
	// Schema.
	// ---------------------------------------------------------------

	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'quantumai_audit_log';
	}

	public static function maybe_upgrade_db() {
		if ( get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) {
			return;
		}

		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			event_time DATETIME NOT NULL,
			event_type VARCHAR(64) NOT NULL,
			user_id BIGINT UNSIGNED NULL,
			user_login VARCHAR(60) NULL,
			ip_address VARCHAR(45) NULL,
			object_type VARCHAR(32) NULL,
			object_id BIGINT UNSIGNED NULL,
			message TEXT NULL,
			PRIMARY KEY  (id),
			KEY event_time (event_time),
			KEY event_type (event_type),
			KEY user_id (user_id)
		) {$charset_collate};";

		dbDelta( $sql );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, true );
	}

	public static function maybe_schedule_prune() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Default 400-day retention (a bit over a year, enough to cover an
	 * annual security review) — override with
	 * `define( 'QUANTUMAI_AUDIT_LOG_RETENTION_DAYS', 90 )` in wp-config.php.
	 */
	public static function prune() {
		global $wpdb;

		$days = defined( 'QUANTUMAI_AUDIT_LOG_RETENTION_DAYS' ) ? (int) QUANTUMAI_AUDIT_LOG_RETENTION_DAYS : 400;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( max( 1, $days ) * DAY_IN_SECONDS ) );

		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table_name() . ' WHERE event_time < %s', $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name isn't user input.
	}

	// ---------------------------------------------------------------
	// Recording.
	// ---------------------------------------------------------------

	private static function insert( $event_type, $user_id = null, $user_login = null, $object_type = null, $object_id = null, $message = null ) {
		global $wpdb;

		$wpdb->insert(
			self::table_name(),
			array(
				'event_time'  => current_time( 'mysql', true ),
				'event_type'  => $event_type,
				'user_id'     => $user_id ?: null,
				'user_login'  => $user_login,
				'ip_address'  => self::get_client_ip(),
				'object_type' => $object_type,
				'object_id'   => $object_id ?: null,
				'message'     => $message,
			),
			array( '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%s' )
		);
	}

	/**
	 * Only trust X-Real-IP because, per docker-compose.yml, nginx is the
	 * sole entry point on the `web` network — WordPress itself is never
	 * reachable directly, so this header can't be spoofed by an external
	 * client. If this stack is ever fronted by another proxy (e.g.
	 * Cloudflare, per the README's post-install checklist), that proxy's
	 * own client-IP header needs to replace this, not stack on top of it.
	 */
	private static function get_client_ip() {
		if ( ! empty( $_SERVER['HTTP_X_REAL_IP'] ) ) {
			return sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_REAL_IP'] ) );
		}
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	}

	public static function log_login_success( $user_login, $user ) {
		self::insert( 'login_success', $user->ID, $user_login );
	}

	public static function log_login_failed( $username, $error = null ) {
		$user = get_user_by( 'login', $username );
		self::insert(
			'login_failed',
			$user ? $user->ID : null,
			$username,
			null,
			null,
			$error instanceof WP_Error ? $error->get_error_code() : null
		);
	}

	public static function log_logout( $user_id ) {
		$user = $user_id ? get_userdata( $user_id ) : false;
		self::insert( 'logout', $user_id ?: null, $user ? $user->user_login : null );
	}

	public static function log_user_register( $user_id ) {
		$user = get_userdata( $user_id );
		self::insert( 'user_register', $user_id, $user ? $user->user_login : null, 'user', $user_id );
	}

	public static function log_user_delete( $user_id, $reassign, $user ) {
		self::insert(
			'user_delete',
			get_current_user_id(),
			null,
			'user',
			$user_id,
			sprintf( 'Deleted user #%d (%s)', $user_id, $user instanceof WP_User ? $user->user_login : 'unknown' )
		);
	}

	public static function log_role_change( $user_id, $role, $old_roles ) {
		self::insert(
			'role_change',
			get_current_user_id(),
			null,
			'user',
			$user_id,
			sprintf( 'User #%d role: [%s] -> %s', $user_id, implode( ',', (array) $old_roles ), $role )
		);
	}

	public static function log_password_reset( $user, $new_pass ) {
		self::insert( 'password_reset', $user->ID, $user->user_login );
	}

	public static function log_post_status_transition( $new_status, $old_status, $post ) {
		if ( $new_status === $old_status || 'revision' === $post->post_type || 'auto-draft' === $old_status ) {
			return;
		}
		self::insert(
			'post_status_change',
			get_current_user_id(),
			null,
			'post',
			$post->ID,
			sprintf( '"%s" (#%d) %s -> %s', get_the_title( $post ), $post->ID, $old_status, $new_status )
		);
	}

	public static function log_post_delete( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || 'revision' === $post->post_type ) {
			return;
		}
		self::insert(
			'post_delete',
			get_current_user_id(),
			null,
			'post',
			$post_id,
			sprintf( 'Deleted "%s" (#%d)', $post->post_title, $post_id )
		);
	}

	public static function log_plugin_activated( $plugin ) {
		self::insert( 'plugin_activated', get_current_user_id(), null, 'plugin', null, $plugin );
	}

	public static function log_plugin_deactivated( $plugin ) {
		self::insert( 'plugin_deactivated', get_current_user_id(), null, 'plugin', null, $plugin );
	}

	public static function log_theme_switch( $new_name ) {
		self::insert( 'theme_switch', get_current_user_id(), null, 'theme', null, sprintf( 'Switched to "%s"', $new_name ) );
	}

	public static function log_option_update( $option, $old_value, $value ) {
		if ( ! in_array( $option, self::WATCHED_OPTIONS, true ) || $old_value === $value ) {
			return;
		}
		self::insert( 'option_change', get_current_user_id(), null, 'option', null, sprintf( '"%s" changed', $option ) );
	}

	// ---------------------------------------------------------------
	// Admin screen: Tools -> Audit Log.
	// ---------------------------------------------------------------

	public static function register_admin_page() {
		add_submenu_page(
			'tools.php',
			__( 'Audit Log', 'quantumai' ),
			__( 'Audit Log', 'quantumai' ),
			'manage_options',
			self::MENU_SLUG,
			array( __CLASS__, 'render_admin_page' )
		);
	}

	/**
	 * Builds the WHERE clause + prepared values shared by the admin list
	 * view and the CSV export, from the same GET filters, so the export
	 * always matches what's on screen.
	 */
	private static function filters_from_request() {
		global $wpdb;

		$where  = array( '1=1' );
		$values = array();

		if ( ! empty( $_GET['event_type'] ) ) {
			$where[]  = 'event_type = %s';
			$values[] = sanitize_text_field( wp_unslash( $_GET['event_type'] ) );
		}

		if ( ! empty( $_GET['user_login'] ) ) {
			$where[]  = 'user_login LIKE %s';
			$values[] = '%' . $wpdb->esc_like( sanitize_text_field( wp_unslash( $_GET['user_login'] ) ) ) . '%';
		}

		if ( ! empty( $_GET['date_from'] ) ) {
			$where[]  = 'event_time >= %s';
			$values[] = sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) . ' 00:00:00';
		}

		if ( ! empty( $_GET['date_to'] ) ) {
			$where[]  = 'event_time <= %s';
			$values[] = sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) . ' 23:59:59';
		}

		return array( implode( ' AND ', $where ), $values );
	}

	public static function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'quantumai' ) );
		}

		global $wpdb;
		$table = self::table_name();

		list( $where, $values ) = self::filters_from_request();

		$paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$offset = ( $paged - 1 ) * self::PER_PAGE;

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where}"; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $where is built from a fixed set of placeholders above.
		$total     = $values ? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $values ) ) : (int) $wpdb->get_var( $count_sql );

		$rows_sql = "SELECT * FROM {$table} WHERE {$where} ORDER BY event_time DESC LIMIT %d OFFSET %d"; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows     = $wpdb->get_results( $wpdb->prepare( $rows_sql, array_merge( $values, array( self::PER_PAGE, $offset ) ) ) );

		$event_types = $wpdb->get_col( "SELECT DISTINCT event_type FROM {$table} ORDER BY event_type" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Audit Log', 'quantumai' ) . '</h1>';

		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::MENU_SLUG ) . '" />';
		echo '<p>';

		echo '<select name="event_type"><option value="">' . esc_html__( 'All event types', 'quantumai' ) . '</option>';
		foreach ( $event_types as $type ) {
			printf(
				'<option value="%1$s" %2$s>%1$s</option>',
				esc_attr( $type ),
				selected( isset( $_GET['event_type'] ) ? wp_unslash( $_GET['event_type'] ) : '', $type, false )
			);
		}
		echo '</select> ';

		printf(
			'<input type="text" name="user_login" placeholder="%s" value="%s" /> ',
			esc_attr__( 'Username contains…', 'quantumai' ),
			esc_attr( isset( $_GET['user_login'] ) ? wp_unslash( $_GET['user_login'] ) : '' )
		);
		printf(
			'<input type="date" name="date_from" value="%s" /> ',
			esc_attr( isset( $_GET['date_from'] ) ? wp_unslash( $_GET['date_from'] ) : '' )
		);
		printf(
			'<input type="date" name="date_to" value="%s" /> ',
			esc_attr( isset( $_GET['date_to'] ) ? wp_unslash( $_GET['date_to'] ) : '' )
		);

		submit_button( __( 'Filter', 'quantumai' ), 'secondary', '', false );
		echo ' ';

		$export_url = wp_nonce_url(
			add_query_arg(
				array_merge( $_GET, array( 'action' => 'quantumai_audit_log_export' ) ),
				admin_url( 'admin-post.php' )
			),
			'quantumai_audit_log_export'
		);
		printf( '<a class="button" href="%s">%s</a>', esc_url( $export_url ), esc_html__( 'Export CSV', 'quantumai' ) );

		echo '</p></form>';

		echo '<table class="wp-list-table widefat fixed striped">';
		echo '<thead><tr>';
		foreach ( array( 'Time (UTC)', 'Event', 'User', 'IP', 'Object', 'Details' ) as $col ) {
			echo '<th>' . esc_html__( $col, 'quantumai' ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		if ( ! $rows ) {
			echo '<tr><td colspan="6">' . esc_html__( 'No matching events.', 'quantumai' ) . '</td></tr>';
		}

		foreach ( $rows as $row ) {
			echo '<tr>';
			echo '<td>' . esc_html( $row->event_time ) . '</td>';
			echo '<td>' . esc_html( $row->event_type ) . '</td>';
			echo '<td>' . esc_html( $row->user_login ?: ( $row->user_id ? '#' . $row->user_id : '—' ) ) . '</td>';
			echo '<td>' . esc_html( $row->ip_address ) . '</td>';
			echo '<td>' . esc_html( $row->object_type ? $row->object_type . ( $row->object_id ? ' #' . $row->object_id : '' ) : '—' ) . '</td>';
			echo '<td>' . esc_html( $row->message ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		$total_pages = (int) ceil( $total / self::PER_PAGE );
		if ( $total_pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			echo paginate_links( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- paginate_links() escapes its own output.
				array(
					'base'      => add_query_arg( 'paged', '%#%' ),
					'format'    => '',
					'current'   => $paged,
					'total'     => $total_pages,
					'prev_text' => __( '&laquo;', 'quantumai' ),
					'next_text' => __( '&raquo;', 'quantumai' ),
				)
			);
			echo '</div></div>';
		}

		echo '</div>';
	}

	public static function handle_export() {
		if ( ! current_user_can( 'manage_options' ) || ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( wp_unslash( $_GET['_wpnonce'] ), 'quantumai_audit_log_export' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'quantumai' ) );
		}

		global $wpdb;
		$table = self::table_name();

		list( $where, $values ) = self::filters_from_request();

		$sql  = "SELECT * FROM {$table} WHERE {$where} ORDER BY event_time DESC LIMIT %d"; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $values, array( self::EXPORT_ROW_CAP ) ) ) );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=quantumai-audit-log-' . gmdate( 'Y-m-d' ) . '.csv' );

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'event_time_utc', 'event_type', 'user_id', 'user_login', 'ip_address', 'object_type', 'object_id', 'message' ) );
		foreach ( $rows as $row ) {
			fputcsv( $out, array( $row->event_time, $row->event_type, $row->user_id, $row->user_login, $row->ip_address, $row->object_type, $row->object_id, $row->message ) );
		}
		fclose( $out );
		exit;
	}
}
