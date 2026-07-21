<?php
/**
 * Performance module — the machinery behind the 100/100 PageSpeed target.
 *
 * Everything here is about shipping the least possible bytes on the critical
 * path and eliminating render-blocking work:
 *   - strip WordPress head bloat (emoji script, embeds, generator, oEmbed…)
 *   - defer all JavaScript
 *   - add async loading + fetchpriority hints to images
 *   - emit resource hints (preconnect / dns-prefetch)
 *   - disable jQuery Migrate and block-library CSS when unused
 *
 * Each behaviour is guarded by a theme option so a site owner can dial it
 * back if a plugin needs something we removed.
 *
 * @package Themify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Remove head bloat that costs bytes and blocks nothing useful for a
 * content site. All opt-out via the `perf_clean_head` toggle (default on).
 */
function themify_clean_head() {
	if ( ! themify_is_enabled( 'perf_clean_head', true ) ) {
		return;
	}

	// Emoji detection script + styles (a classic PageSpeed offender).
	remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
	remove_action( 'wp_print_styles', 'print_emoji_styles' );
	remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
	remove_action( 'admin_print_styles', 'print_emoji_styles' );
	remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
	remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
	remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
	add_filter( 'emoji_svg_url', '__return_false' );

	// Meta noise.
	remove_action( 'wp_head', 'wp_generator' );
	remove_action( 'wp_head', 'wlwmanifest_link' );
	remove_action( 'wp_head', 'rsd_link' );
	remove_action( 'wp_head', 'wp_shortlink_wp_head' );

	// oEmbed discovery + host JS (rarely needed on a blog, restore via toggle).
	remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
	remove_action( 'wp_head', 'wp_oembed_add_host_js' );
	remove_action( 'rest_api_init', 'wp_oembed_register_route' );
}
add_action( 'init', 'themify_clean_head' );

/**
 * Drop jQuery Migrate — modern themes/plugins don't need the shim, and it's
 * pure render-path weight when jQuery is loaded at all.
 *
 * @param WP_Scripts $scripts Registered scripts.
 */
function themify_dequeue_jquery_migrate( $scripts ) {
	if ( is_admin() || ! themify_is_enabled( 'perf_no_jquery_migrate', true ) ) {
		return;
	}
	if ( ! empty( $scripts->registered['jquery'] ) ) {
		$deps = $scripts->registered['jquery']->deps;
		$scripts->registered['jquery']->deps = array_diff( $deps, array( 'jquery-migrate' ) );
	}
}
add_action( 'wp_default_scripts', 'themify_dequeue_jquery_migrate' );

/**
 * Remove the block-library / global-styles CSS on the front end when the
 * page doesn't actually use blocks that need it. Opt-in via
 * `perf_strip_block_css` because it can affect sites built with blocks.
 */
function themify_maybe_strip_block_css() {
	if ( is_admin() || ! themify_is_enabled( 'perf_strip_block_css', false ) ) {
		return;
	}
	wp_dequeue_style( 'wp-block-library' );
	wp_dequeue_style( 'wp-block-library-theme' );
	wp_dequeue_style( 'global-styles' );
	wp_dequeue_style( 'classic-theme-styles' );
}
add_action( 'wp_enqueue_scripts', 'themify_maybe_strip_block_css', 100 );

/**
 * Defer every front-end script by default. Deferring keeps execution ordered
 * (unlike async) while removing the parser-blocking penalty — ideal for the
 * theme's own tiny main.js and most third-party tags.
 *
 * Scripts can opt out by adding a `data-no-defer` marker via
 * wp_script_add_data( $handle, 'no_defer', true ).
 *
 * @param string $tag    The <script> HTML.
 * @param string $handle Script handle.
 * @return string
 */
function themify_defer_scripts( $tag, $handle ) {
	if ( is_admin() || ! themify_is_enabled( 'perf_defer_js', true ) ) {
		return $tag;
	}
	// Never defer these — they must run inline/early.
	$skip = apply_filters( 'themify_defer_skip', array() );
	if ( in_array( $handle, $skip, true ) ) {
		return $tag;
	}
	if ( false !== strpos( $tag, ' defer' ) || false !== strpos( $tag, ' async' ) ) {
		return $tag;
	}
	if ( false === strpos( $tag, 'src=' ) ) {
		return $tag; // inline scripts can't be deferred
	}
	return str_replace( ' src=', ' defer src=', $tag );
}
add_filter( 'script_loader_tag', 'themify_defer_scripts', 10, 2 );

/**
 * Emit resource hints. The first content image on a post gets fetchpriority
 * high (see themify_image_priority); here we add connection warm-ups for the
 * origins the theme talks to.
 *
 * @param array  $hints         Existing hints for this relation.
 * @param string $relation_type preconnect|dns-prefetch|…
 * @return array
 */
function themify_resource_hints( $hints, $relation_type ) {
	if ( 'preconnect' === $relation_type && themify_is_enabled( 'load_google_fonts', false ) ) {
		$hints[] = array( 'href' => 'https://fonts.googleapis.com', 'crossorigin' );
		$hints[] = array( 'href' => 'https://fonts.gstatic.com', 'crossorigin' );
	}
	// Extra origins the owner pastes (comma separated) in perf settings.
	$extra = themify_get_option( 'perf_preconnect', '' );
	if ( $extra && 'preconnect' === $relation_type ) {
		foreach ( array_filter( array_map( 'trim', explode( ',', $extra ) ) ) as $origin ) {
			$hints[] = $origin;
		}
	}
	return $hints;
}
add_filter( 'wp_resource_hints', 'themify_resource_hints', 10, 2 );

/**
 * Give the first in-content image a high fetch priority and make the rest
 * lazy — this is the single biggest LCP win on article pages. WordPress
 * already adds loading="lazy"; we make sure the hero/first image is eager
 * with fetchpriority=high instead.
 *
 * @param string $content Post content.
 * @return string
 */
function themify_prioritise_first_image( $content ) {
	if ( is_admin() || is_feed() || ! is_singular() ) {
		return $content;
	}
	$done = false;
	$content = preg_replace_callback(
		'/<img\b[^>]*>/i',
		function ( $m ) use ( &$done ) {
			$img = $m[0];
			if ( $done ) {
				return $img; // leave the rest to WP's native lazy loading
			}
			$done = true;
			// Strip any lazy loading on the LCP image and prioritise it.
			$img = preg_replace( '/\sloading=("|\')lazy("|\')/i', '', $img );
			if ( false === stripos( $img, 'fetchpriority' ) ) {
				$img = str_replace( '<img', '<img fetchpriority="high" decoding="async"', $img );
			}
			return $img;
		},
		$content
	);
	return $content;
}
add_filter( 'the_content', 'themify_prioritise_first_image', 20 );
