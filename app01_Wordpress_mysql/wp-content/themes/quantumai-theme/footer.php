<?php
/**
 * Displays the site footer and closing </body>/</html>.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
	<footer class="qa-site-footer">
		<nav class="qa-primary-nav" aria-label="<?php esc_attr_e( 'Footer menu', 'quantumai' ); ?>">
			<?php
			wp_nav_menu(
				array(
					'theme_location' => 'footer',
					'container'      => false,
					'fallback_cb'    => false,
				)
			);
			?>
		</nav>
		<p>
			&copy; <?php echo esc_html( gmdate( 'Y' ) ); ?>
			<?php bloginfo( 'name' ); ?>
		</p>
	</footer>

<?php wp_footer(); ?>
</body>
</html>
