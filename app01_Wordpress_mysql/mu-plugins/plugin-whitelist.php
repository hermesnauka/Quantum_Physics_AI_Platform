<?php
/**
 * AS-03 "strict plugin whitelisting policy": this platform's whole design
 * already avoids third-party plugins — MFA, the audit log, anti-bot, etc.
 * are all hand-rolled mu-plugins instead, precisely to avoid this attack
 * surface (see the top-of-file comments on those). This makes that policy
 * concrete: nobody, including an Administrator, can install or activate a
 * *regular* plugin through wp-admin, closing off the most common way a
 * vulnerable third-party plugin ends up on a WordPress site.
 *
 * Escape hatch: define('QUANTUMAI_ALLOW_PLUGIN_MANAGEMENT', true) in
 * wp-config.php if a specific, reviewed plugin genuinely needs installing —
 * deliberately not exposed as an env var like WP_LOGIN_SLUG, since this is
 * meant to require a one-off deliberate edit, not something flipped
 * casually per environment.
 *
 * Scope: this only defends the wp-admin web surface (a malicious or
 * compromised admin account trying to add a backdoored/vulnerable plugin).
 * It does nothing against `wp plugin install/activate` run via WP-CLI
 * inside the container — WP-CLI intentionally bypasses the capability
 * system, and anyone with container shell access has already won regardless
 * of anything this file does.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function quantumai_plugin_management_locked() {
	return ! ( defined( 'QUANTUMAI_ALLOW_PLUGIN_MANAGEMENT' ) && QUANTUMAI_ALLOW_PLUGIN_MANAGEMENT );
}

/**
 * Strips the two capabilities that gate installing/activating a plugin from
 * every role that would otherwise have them, administrator included.
 * WordPress core's own wp-admin/plugins.php and plugin-install.php already
 * check these caps before rendering anything or acting on a request, so
 * this alone is enough to hide/403 the entire "install or turn on a new
 * plugin" path — no separate menu-hiding or request-blocking code needed.
 */
function quantumai_strip_plugin_management_caps( $allcaps, $caps, $args, $user ) {
	if ( ! quantumai_plugin_management_locked() ) {
		return $allcaps;
	}

	unset( $allcaps['install_plugins'], $allcaps['activate_plugins'] );

	return $allcaps;
}
add_filter( 'user_has_cap', 'quantumai_strip_plugin_management_caps', 10, 4 );

/**
 * Belt-and-braces for the WP-CLI case noted above: not a real prevention
 * (activate_plugin() has already `require`d the plugin's main file — and
 * therefore already run its code — by the time this action fires; that's
 * inherent to how WordPress activation works, not something a hook can get
 * ahead of), but it guarantees the plugin doesn't *stay* active afterward,
 * and leaves a clear reason in the deactivation itself rather than a
 * silently-active plugin nobody remembers approving.
 */
function quantumai_deactivate_unapproved_plugin( $plugin ) {
	if ( ! quantumai_plugin_management_locked() ) {
		return;
	}

	deactivate_plugins( $plugin, true );
}
add_action( 'activate_plugin', 'quantumai_deactivate_unapproved_plugin' );

function quantumai_plugin_whitelist_admin_notice() {
	if ( ! quantumai_plugin_management_locked() || ! function_exists( 'get_current_screen' ) ) {
		return;
	}

	$screen = get_current_screen();
	if ( ! $screen || 'plugins' !== $screen->id ) {
		return;
	}

	printf(
		'<div class="notice notice-info"><p>%s</p></div>',
		esc_html__( 'Installing and activating plugins is disabled on this site (AS-03 plugin whitelisting policy). This platform uses hand-rolled mu-plugins instead of third-party plugins wherever possible — see mu-plugins/plugin-whitelist.php for the override if one is genuinely needed.', 'quantumai' )
	);
}
add_action( 'admin_notices', 'quantumai_plugin_whitelist_admin_notice' );
