<?php
/**
 * Theme setup — supports, menus, image sizes, i18n.
 *
 * @package Themify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register theme supports and features.
 */
function themify_setup() {
	load_theme_textdomain( 'themify', THEMIFY_DIR . '/languages' );

	add_theme_support( 'automatic-feed-links' );
	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'custom-logo', array(
		'height'      => 80,
		'width'       => 240,
		'flex-height' => true,
		'flex-width'  => true,
	) );
	add_theme_support( 'html5', array(
		'search-form', 'comment-form', 'comment-list', 'gallery', 'caption',
		'style', 'script', 'navigation-widgets',
	) );
	add_theme_support( 'customize-selective-refresh-widgets' );
	add_theme_support( 'align-wide' );
	add_theme_support( 'responsive-embeds' );
	add_theme_support( 'editor-styles' );
	add_editor_style( 'assets/css/editor.css' );

	// Content-width used by oEmbeds and wide images.
	if ( ! isset( $GLOBALS['content_width'] ) ) {
		$GLOBALS['content_width'] = 760;
	}

	register_nav_menus( array(
		'primary' => __( 'Primary Menu (Header)', 'themify' ),
		'footer'  => __( 'Footer Menu', 'themify' ),
	) );

	// Responsive, sensibly-cropped thumbnails used across the blog grid.
	add_image_size( 'themify-card', 720, 405, true );      // 16:9 blog cards
	add_image_size( 'themify-card-2x', 1200, 675, true );  // retina cards
	add_image_size( 'themify-hero', 1600, 900, true );     // featured / hero
}
add_action( 'after_setup_theme', 'themify_setup' );

/**
 * Register widget areas: a blog sidebar plus up to four footer columns.
 */
function themify_widgets_init() {
	register_sidebar( array(
		'name'          => __( 'Blog Sidebar', 'themify' ),
		'id'            => 'sidebar-blog',
		'description'   => __( 'Shown alongside blog posts and archives.', 'themify' ),
		'before_widget' => '<section id="%1$s" class="widget %2$s">',
		'after_widget'  => '</section>',
		'before_title'  => '<h3 class="widget-title">',
		'after_title'   => '</h3>',
	) );

	for ( $i = 1; $i <= 4; $i++ ) {
		register_sidebar( array(
			/* translators: %d: footer column number */
			'name'          => sprintf( __( 'Footer Column %d', 'themify' ), $i ),
			'id'            => 'footer-' . $i,
			'before_widget' => '<section id="%1$s" class="widget %2$s">',
			'after_widget'  => '</section>',
			'before_title'  => '<h4 class="widget-title">',
			'after_title'   => '</h4>',
		) );
	}
}
add_action( 'widgets_init', 'themify_widgets_init' );

/**
 * Add a friendly body class the CSS can hook layout variants onto
 * (e.g. sidebar on/off from theme options).
 *
 * @param array $classes Existing body classes.
 * @return array
 */
function themify_body_classes( $classes ) {
	$full = 'full' === themify_get_option( 'blog_layout' );

	// The built-in sidebar (search + popular + recent, plus the author card on
	// single posts) shows on single posts AND on blog listings — category/tag/
	// archive pages, the blog index and search — even with no widgets set. The
	// static front page (homepage builder) and stand-alone pages stay full width.
	$sidebar_context = is_singular( 'post' )
		|| ( ! is_front_page() && ( is_home() || is_archive() || is_search() ) );
	if ( ! $full && $sidebar_context && themify_is_enabled( 'sidebar_enabled', true ) ) {
		$has_sidebar = true;
	} else {
		$has_sidebar = ! $full && is_active_sidebar( 'sidebar-blog' );
	}

	$classes[] = $has_sidebar ? 'tf-has-sidebar' : 'tf-no-sidebar';
	if ( is_singular() ) {
		$classes[] = 'tf-singular';
	}
	return $classes;
}
add_filter( 'body_class', 'themify_body_classes' );
