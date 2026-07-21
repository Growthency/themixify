<?php
/**
 * The blog sidebar.
 *
 * Loaded by the archive / blog-index / search templates via get_sidebar(). It
 * renders the theme's built-in sidebar — a search box plus Popular and Recent
 * post lists (the author card only appears on single posts). Single posts call
 * themify_render_post_sidebar() directly from single.php, so this file gives
 * every other listing context the same, consistent sidebar.
 *
 * @package Themify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( function_exists( 'themify_render_post_sidebar' ) ) {
	themify_render_post_sidebar();
}
