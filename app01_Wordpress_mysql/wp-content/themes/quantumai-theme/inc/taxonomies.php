<?php
/**
 * FR-03: educational taxonomy structure — Quantum Computing, AI/LLMs/LRMs,
 * Quantum Physics, and Post-Quantum Cryptography.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function quantumai_register_taxonomies() {
	register_taxonomy(
		'topic',
		array( 'post' ),
		array(
			'hierarchical'      => true,
			'public'            => true,
			'show_admin_column' => true,
			'show_in_rest'      => true,
			'rewrite'           => array( 'slug' => 'topic' ),
			'labels'            => array(
				'name'          => __( 'Topics', 'quantumai' ),
				'singular_name' => __( 'Topic', 'quantumai' ),
				'menu_name'     => __( 'Topics', 'quantumai' ),
				'all_items'     => __( 'All Topics', 'quantumai' ),
			),
		)
	);
}
add_action( 'init', 'quantumai_register_taxonomies' );

/**
 * Seed the four required topics once, on theme activation. Uses term_exists()
 * as a guard so re-activating the theme never clobbers edits an Editor made
 * to these terms afterwards.
 */
function quantumai_seed_default_topics() {
	$defaults = array(
		'quantum-computing'         => __( 'Quantum Computing', 'quantumai' ),
		'ai-llms-lrms'              => __( 'AI / LLMs / LRMs', 'quantumai' ),
		'quantum-physics'           => __( 'Quantum Physics', 'quantumai' ),
		'post-quantum-cryptography' => __( 'Post-Quantum Cryptography', 'quantumai' ),
	);

	foreach ( $defaults as $slug => $name ) {
		if ( ! term_exists( $slug, 'topic' ) ) {
			wp_insert_term( $name, 'topic', array( 'slug' => $slug ) );
		}
	}
}
add_action( 'after_switch_theme', 'quantumai_seed_default_topics' );
