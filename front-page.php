<?php
/**
 * The front page template.
 *
 * Used whenever the site's front page is set to a static page (Settings →
 * Reading). All of the front-page composition lives in the homepage builder
 * module: front-page.php just wraps the shared header/footer around the blocks
 * that themify_render_homepage() paints. Keep this file intentionally bare —
 * add homepage features to inc/modules/homepage-builder.php, not here.
 *
 * @package Themify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
themify_render_homepage();
get_footer();
