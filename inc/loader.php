<?php
/**
 * Module loader.
 *
 * Requires the core files in a deterministic order (helpers before anything
 * that uses them), then auto-includes every drop-in module under inc/seo,
 * inc/modules and inc/admin. A module is just a PHP file that registers its
 * own WordPress hooks at include time — it never needs to be wired up here.
 *
 * @package Themify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Core files, loaded in order. These define the shared contract (helpers,
 * theme setup, asset pipeline, template tags, performance tuning) that every
 * drop-in module depends on.
 */
$themify_core = array(
	'helpers.php',
	'setup.php',
	'performance.php',
	'enqueue.php',
	'template-tags.php',
	'admin/settings-api.php', // admin helpers must exist before admin modules
	'admin/admin-menu.php',   // registers the top-level parent menu
);

foreach ( $themify_core as $file ) {
	$path = THEMIFY_INC . '/' . $file;
	if ( is_readable( $path ) ) {
		require_once $path;
	}
}

/**
 * Drop-in modules. Each directory is globbed and every *.php inside is
 * required. Files load alphabetically within a directory; if a module has an
 * ordering dependency, prefix its filename (e.g. 00-foo.php) — but prefer
 * making modules order-independent by hooking into WordPress actions.
 */
$themify_module_dirs = array(
	THEMIFY_INC . '/seo',
	THEMIFY_INC . '/modules',
	THEMIFY_INC . '/admin/pages',
);

foreach ( $themify_module_dirs as $dir ) {
	if ( ! is_dir( $dir ) ) {
		continue;
	}
	$files = glob( $dir . '/*.php' );
	if ( ! is_array( $files ) ) {
		continue;
	}
	sort( $files );
	foreach ( $files as $file ) {
		require_once $file;
	}
}
