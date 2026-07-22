<?php
/**
 * Theme-wide constants. Loaded first, before anything else.
 *
 * @package Themify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Bumped on every release; used to cache-bust enqueued assets. */
define( 'THEMIFY_VERSION', '1.12.5' );

/** Absolute filesystem path to the theme root, no trailing slash. */
define( 'THEMIFY_DIR', get_template_directory() );

/** Public URL to the theme root, no trailing slash. */
define( 'THEMIFY_URI', get_template_directory_uri() );

/** Absolute path to the /inc directory. */
define( 'THEMIFY_INC', THEMIFY_DIR . '/inc' );

/** Public URL to the /assets directory. */
define( 'THEMIFY_ASSETS', THEMIFY_URI . '/assets' );

/** The translation text domain. Must match style.css "Text Domain". */
define( 'THEMIFY_TEXTDOMAIN', 'themify' );

/**
 * Single wp_options row that stores all simple scalar settings as one array
 * (typography, SEO defaults, API keys, feature toggles, colors, …). Larger or
 * list-shaped data (scripts, affiliate links, homepage blocks, keywords) live
 * in their own dedicated options / custom post types — see each module.
 */
define( 'THEMIFY_OPT', 'themify_settings' );

/** Slug of the top-level "Themify" admin menu. Submenus attach to this. */
define( 'THEMIFY_ADMIN_SLUG', 'themify' );

/** Capability required for every Themify admin screen and AJAX action. */
define( 'THEMIFY_CAP', 'manage_options' );
