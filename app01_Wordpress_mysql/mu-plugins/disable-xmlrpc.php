<?php
/**
 * Must-use plugin so this runs on every request, before regular plugins,
 * and can't be deactivated from the dashboard.
 *
 * add_filter() isn't available yet inside wp-config.php (WORDPRESS_CONFIG_EXTRA
 * runs before wp-settings.php loads core), so this has to live here rather than
 * in the docker-compose WORDPRESS_CONFIG_EXTRA block.
 */

add_filter( 'xmlrpc_enabled', '__return_false' );
