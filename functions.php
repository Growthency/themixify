<?php
/**
 * Themify functions and definitions.
 *
 * This file is intentionally tiny. It only defines constants and hands off to
 * the loader, which pulls in every module under /inc. Add features by dropping
 * a new file into inc/modules/ (or inc/seo/, inc/admin/) — the loader will
 * auto-require it. Do NOT add feature logic here.
 *
 * @package Themify
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once get_template_directory() . '/inc/constants.php';
require_once get_template_directory() . '/inc/loader.php';
