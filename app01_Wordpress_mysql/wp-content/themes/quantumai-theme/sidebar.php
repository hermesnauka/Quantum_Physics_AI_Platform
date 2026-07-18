<?php
/**
 * Sidebar template, only rendered when the 'sidebar-1' widget area has
 * active widgets.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! is_active_sidebar( 'sidebar-1' ) ) {
	return;
}
?>
<aside class="qa-sidebar" aria-label="<?php esc_attr_e( 'Sidebar', 'quantumai' ); ?>">
	<?php dynamic_sidebar( 'sidebar-1' ); ?>
</aside>
