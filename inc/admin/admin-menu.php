<?php
/**
 * Top-level "Themify" admin menu + submenu registry.
 *
 * Modules never call add_submenu_page() directly. Instead they call
 * themify_register_admin_page() at include time; this file reads the registry
 * on the admin_menu hook and registers everything in a controlled order. That
 * keeps menu ordering deterministic no matter what order the loader includes
 * modules in.
 *
 * @package Themify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register a Themify admin page.
 *
 * @param array $args {
 *   @type string   $slug       Unique page slug (must contain THEMIFY_ADMIN_SLUG
 *                              or the admin asset loader won't target it).
 *   @type string   $title      Page <title> / heading.
 *   @type string   $menu_title Sidebar label (defaults to $title).
 *   @type callable $callback   Renders the page.
 *   @type int      $position   Sort order within the submenu (lower = higher).
 * }
 */
function themify_register_admin_page( array $args ) {
	if ( empty( $args['slug'] ) || empty( $args['callback'] ) ) {
		return;
	}
	$args = wp_parse_args( $args, array(
		'title'      => '',
		'menu_title' => $args['title'] ?? '',
		'position'   => 50,
	) );
	$GLOBALS['themify_admin_pages'][ $args['slug'] ] = $args;
}

/**
 * Build the menu tree from the registry.
 */
function themify_build_admin_menu() {
	$pages = isset( $GLOBALS['themify_admin_pages'] ) ? $GLOBALS['themify_admin_pages'] : array();

	// Parent menu → the Themixify dashboard.
	add_menu_page(
		__( 'Themixify', 'themify' ),
		__( 'Themixify', 'themify' ),
		THEMIFY_CAP,
		THEMIFY_ADMIN_SLUG,
		'themify_dashboard_page',
		'dashicons-superhero',
		2
	);

	// Rename the auto-created first submenu (mirrors the parent) to "Dashboard".
	add_submenu_page(
		THEMIFY_ADMIN_SLUG,
		__( 'Themixify Dashboard', 'themify' ),
		__( 'Dashboard', 'themify' ),
		THEMIFY_CAP,
		THEMIFY_ADMIN_SLUG,
		'themify_dashboard_page'
	);

	// Sort registered pages by position, then register each as a submenu.
	uasort( $pages, function ( $a, $b ) {
		return ( $a['position'] ?? 50 ) <=> ( $b['position'] ?? 50 );
	} );

	foreach ( $pages as $page ) {
		add_submenu_page(
			THEMIFY_ADMIN_SLUG,
			$page['title'],
			$page['menu_title'],
			THEMIFY_CAP,
			$page['slug'],
			$page['callback']
		);
	}
}
add_action( 'admin_menu', 'themify_build_admin_menu' );

/**
 * The Themify dashboard landing page: a grid of cards linking to every tool,
 * with a couple of at-a-glance stats. Individual modules can enrich this via
 * the `themify_dashboard_cards` filter.
 */
function themify_dashboard_page() {
	themify_admin_header(
		__( 'Welcome to Themixify', 'themify' ),
		__( 'Your all-in-one growth suite: analytics, indexing, rank tracking, SEO, affiliate tools and more — built into the theme.', 'themify' )
	);

	$cards = apply_filters( 'themify_dashboard_cards', array() );

	if ( $cards ) {
		// Sort by optional position.
		usort( $cards, function ( $a, $b ) {
			return ( $a['position'] ?? 50 ) <=> ( $b['position'] ?? 50 );
		} );
		echo '<div class="tf-dash-grid">';
		foreach ( $cards as $card ) {
			printf(
				'<a class="tf-dash-card" href="%s"><span class="tf-dash-card__icon dashicons %s"></span><span class="tf-dash-card__title">%s</span><span class="tf-dash-card__desc">%s</span></a>',
				esc_url( admin_url( 'admin.php?page=' . $card['slug'] ) ),
				esc_attr( $card['icon'] ?? 'dashicons-admin-generic' ),
				esc_html( $card['title'] ),
				esc_html( $card['desc'] ?? '' )
			);
		}
		echo '</div>';
	}

	themify_admin_footer();
}
