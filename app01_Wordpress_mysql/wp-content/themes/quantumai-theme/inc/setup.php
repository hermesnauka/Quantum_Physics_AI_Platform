<?php
/**
 * Core theme support, menus, sidebars, and asset registration.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function quantumai_setup() {
	load_theme_textdomain( 'quantumai', get_template_directory() . '/languages' );

	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support(
		'html5',
		array( 'search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script' )
	);
	add_theme_support( 'automatic-feed-links' );
	add_theme_support( 'customize-selective-refresh-widgets' );
	add_theme_support( 'editor-styles' );
	add_editor_style( 'assets/css/editor-style.css' );

	register_nav_menus(
		array(
			'primary' => __( 'Primary Menu', 'quantumai' ),
			'footer'  => __( 'Footer Menu', 'quantumai' ),
		)
	);
}
add_action( 'after_setup_theme', 'quantumai_setup' );

function quantumai_content_width() {
	$GLOBALS['content_width'] = apply_filters( 'quantumai_content_width', 780 );
}
add_action( 'after_setup_theme', 'quantumai_content_width', 0 );

function quantumai_widgets_init() {
	register_sidebar(
		array(
			'name'          => __( 'Sidebar', 'quantumai' ),
			'id'            => 'sidebar-1',
			'description'   => __( 'Appears next to article content.', 'quantumai' ),
			'before_widget' => '<section id="%1$s" class="widget %2$s">',
			'after_widget'  => '</section>',
			'before_title'  => '<h2 class="widget-title">',
			'after_title'   => '</h2>',
		)
	);
}
add_action( 'widgets_init', 'quantumai_widgets_init' );

function quantumai_scripts() {
	wp_enqueue_style( 'quantumai-style', get_stylesheet_uri(), array(), QUANTUMAI_THEME_VERSION );
}
add_action( 'wp_enqueue_scripts', 'quantumai_scripts' );
