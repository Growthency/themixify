<?php
/**
 * General settings screen.
 *
 * The theme's catch-all options page: branding, blog layout toggles and the
 * performance switches that drive inc/performance.php. Everything here is
 * plain "fields → Save", so it uses the declarative settings renderer; the
 * only front-end behaviour the module adds itself is wiring the
 * `excerpt_length` option into WordPress's excerpt system.
 *
 * The option keys below are a contract shared with the foundation:
 *   - blog_layout, sticky_header, show_author, show_breadcrumbs, back_to_top
 *     are read by setup.php / template-tags.php / the templates.
 *   - perf_clean_head, perf_defer_js, perf_no_jquery_migrate,
 *     perf_strip_block_css, perf_preconnect are read by performance.php.
 * Do not rename them without updating those readers.
 *
 * @package Themify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the "General" submenu page (position 5, first in the group).
 */
themify_register_admin_page( array(
	'slug'       => 'themify-general',
	'title'      => __( 'General', 'themify' ),
	'menu_title' => __( 'General', 'themify' ),
	'callback'   => 'themify_general_settings_page',
	'position'   => 44,
) );

/**
 * Add the General card to the dashboard grid.
 */
add_filter( 'themify_dashboard_cards', 'themify_general_dashboard_card' );

/**
 * Append the General settings card.
 *
 * @param array $cards Existing dashboard cards.
 * @return array
 */
function themify_general_dashboard_card( $cards ) {
	$cards[] = array(
		'slug'     => 'themify-general',
		'title'    => __( 'General', 'themify' ),
		'desc'     => __( 'Branding, layout and performance', 'themify' ),
		'icon'     => 'dashicons-admin-settings',
		'position' => 44,
	);
	return $cards;
}

/**
 * Render the General settings page via the declarative renderer.
 */
function themify_general_settings_page() {
	themify_render_settings_page( array(
		'title'  => __( 'General', 'themify' ),
		'intro'  => __( 'Brand the theme, tune the blog layout, and control the performance optimisations. These options power the header, templates and the performance engine.', 'themify' ),
		'nonce'  => 'themify_general',
		'groups' => array(

			// Branding.
			array(
				'title'  => __( 'Branding', 'themify' ),
				'desc'   => __( 'Upload your logo here, or leave it empty to show your site title as text.', 'themify' ),
				'fields' => array(
					array(
						'key'         => 'brand_logo',
						'label'       => __( 'Logo', 'themify' ),
						'type'        => 'media',
						'placeholder' => __( 'https://…/logo.png', 'themify' ),
						'desc'        => __( 'Click “Choose” to upload or pick a logo image. It replaces the site-title text in the header. Leave empty to show the site title instead.', 'themify' ),
					),
					array(
						'key'         => 'logo_height',
						'label'       => __( 'Logo height (px)', 'themify' ),
						'type'        => 'number',
						'placeholder' => '56',
						'desc'        => __( 'Height of the logo in the header. Default 56. Increase (e.g. 64–80) for a bigger logo.', 'themify' ),
					),
					array(
						'key'         => 'brand_tagline',
						'label'       => __( 'Tagline', 'themify' ),
						'type'        => 'text',
						'placeholder' => __( 'A short line that describes your site', 'themify' ),
						'desc'        => __( 'Shown near the brand where the theme supports it.', 'themify' ),
					),
					array(
						'key'         => 'cta_text',
						'label'       => __( 'Header button text', 'themify' ),
						'type'        => 'text',
						'placeholder' => __( 'Subscribe', 'themify' ),
						'desc'        => __( 'Optional call-to-action button in the header. Leave blank to hide it.', 'themify' ),
					),
					array(
						'key'         => 'cta_url',
						'label'       => __( 'Header button link', 'themify' ),
						'type'        => 'url',
						'placeholder' => 'https://',
						'desc'        => __( 'Where the header button points. Only used when the button text is set.', 'themify' ),
					),
					array(
						'key'     => 'header_search_enabled',
						'label'   => __( 'Header search bar', 'themify' ),
						'type'    => 'checkbox',
						'default' => '',
						'desc'    => __( 'Show a search box in the header, next to the menu. Turn off to hide it completely.', 'themify' ),
					),
				),
			),

			// Layout.
			array(
				'title'  => __( 'Layout', 'themify' ),
				'desc'   => __( 'Control the reading experience across the blog and single posts.', 'themify' ),
				'fields' => array(
					array(
						'key'         => 'container_width',
						'label'       => __( 'Site width (px)', 'themify' ),
						'type'        => 'number',
						'placeholder' => '1200',
						'desc'        => __( 'How wide the whole site is. Default 1200. Feeling cramped? Try 1360–1500. Blank or 0 = default.', 'themify' ),
					),
					array(
						'key'         => 'sidebar_width',
						'label'       => __( 'Sidebar width (px)', 'themify' ),
						'type'        => 'number',
						'placeholder' => '320',
						'desc'        => __( 'Width of the article sidebar column. Default 320. Smaller sidebar = wider article. Blank or 0 = default.', 'themify' ),
					),
					array(
						'key'     => 'blog_layout',
						'label'   => __( 'Blog layout', 'themify' ),
						'type'    => 'select',
						'default' => 'sidebar',
						'options' => array(
							'sidebar' => __( 'Right sidebar', 'themify' ),
							'full'    => __( 'Full width', 'themify' ),
						),
						'desc'    => __( 'Full width hides the sidebar on blog and archive pages.', 'themify' ),
					),
					array(
						'key'     => 'sticky_header',
						'label'   => __( 'Sticky header', 'themify' ),
						'type'    => 'checkbox',
						'default' => '1',
						'desc'    => __( 'Keep the header pinned to the top as visitors scroll.', 'themify' ),
					),
					array(
						'key'     => 'show_author',
						'label'   => __( 'Show author in post meta', 'themify' ),
						'type'    => 'checkbox',
						'default' => '1',
					),
					array(
						'key'     => 'show_breadcrumbs',
						'label'   => __( 'Show breadcrumbs', 'themify' ),
						'type'    => 'checkbox',
						'default' => '1',
					),
					array(
						'key'     => 'back_to_top',
						'label'   => __( 'Back-to-top button', 'themify' ),
						'type'    => 'checkbox',
						'default' => '1',
					),
					array(
						'key'     => 'related_inline_enabled',
						'label'   => __( '"Related" links box after posts', 'themify' ),
						'type'    => 'checkbox',
						'default' => '1',
						'desc'    => __( 'The compact grey box with three related-post links at the end of every article.', 'themify' ),
					),
					array(
						'key'     => 'post_nav_enabled',
						'label'   => __( 'Previous / Next post navigation', 'themify' ),
						'type'    => 'checkbox',
						'default' => '1',
						'desc'    => __( 'Two-column links to the previous and next article, shown after the Related box.', 'themify' ),
					),
					array(
						'key'     => 'similar_posts_enabled',
						'label'   => __( '"Similar Posts" carousel', 'themify' ),
						'type'    => 'checkbox',
						'default' => '1',
						'desc'    => __( 'The sliding card carousel (arrows + dots) at the very end of every article.', 'themify' ),
					),
					array(
						'key'     => 'sticky_postbar_enabled',
						'label'   => __( 'Sticky trending-posts bar', 'themify' ),
						'type'    => 'checkbox',
						'default' => '1',
						'desc'    => __( 'A slim bar of 10 popular posts that slides down from the top once the visitor scrolls.', 'themify' ),
					),
					array(
						'key'         => 'excerpt_length',
						'label'       => __( 'Excerpt length (words)', 'themify' ),
						'type'        => 'number',
						'default'     => 28,
						'placeholder' => '28',
						'desc'        => __( 'How many words to show in automatic post excerpts.', 'themify' ),
					),
				),
			),

			// Performance — keys must match inc/performance.php.
			array(
				'title'  => __( 'Performance', 'themify' ),
				'desc'   => __( 'These switches drive the performance engine. The defaults target a 100/100 score; dial them back only if a plugin needs something they remove.', 'themify' ),
				'fields' => array(
					array(
						'key'     => 'perf_clean_head',
						'label'   => __( 'Clean up <head>', 'themify' ),
						'type'    => 'checkbox',
						'default' => '1',
						'desc'    => __( 'Remove emoji scripts, generator tags, oEmbed discovery and other head bloat.', 'themify' ),
					),
					array(
						'key'     => 'perf_defer_js',
						'label'   => __( 'Defer JavaScript', 'themify' ),
						'type'    => 'checkbox',
						'default' => '1',
						'desc'    => __( 'Add defer to front-end scripts so they never block rendering.', 'themify' ),
					),
					array(
						'key'     => 'perf_no_jquery_migrate',
						'label'   => __( 'Remove jQuery Migrate', 'themify' ),
						'type'    => 'checkbox',
						'default' => '1',
						'desc'    => __( 'Drop the legacy jQuery Migrate shim when jQuery is loaded.', 'themify' ),
					),
					array(
						'key'     => 'perf_strip_block_css',
						'label'   => __( 'Strip block-library CSS', 'themify' ),
						'type'    => 'checkbox',
						'default' => '',
						'desc'    => __( 'Remove the WordPress block and global-styles CSS. Off by default because it can affect block-built pages.', 'themify' ),
					),
					array(
						'key'         => 'perf_preconnect',
						'label'       => __( 'Preconnect origins', 'themify' ),
						'type'        => 'text',
						'placeholder' => 'https://cdn.example.com, https://analytics.example.com',
						'desc'        => __( 'Comma-separated origins to warm up early with a preconnect resource hint.', 'themify' ),
					),
				),
			),
		),
	) );
}

/**
 * Print the owner's custom layout widths as a tiny CSS override in <head>.
 * The whole layout is driven by the --tf-maxw variable and the sidebar grid
 * column, so two numbers are all that is needed. Nothing prints when the
 * options are unset, keeping the stylesheet untouched by default.
 */
function themify_layout_width_css() {
	if ( is_admin() ) {
		return;
	}

	$maxw    = (int) themify_get_option( 'container_width', 0 );
	$sidebar = (int) themify_get_option( 'sidebar_width', 0 );

	$css = '';
	if ( $maxw >= 700 && $maxw <= 2400 ) {
		$css .= ':root{--tf-maxw:' . $maxw . 'px;}';
	}
	if ( $sidebar >= 200 && $sidebar <= 600 ) {
		$css .= '@media(min-width:901px){.tf-has-sidebar .tf-layout{grid-template-columns:minmax(0,1fr) ' . $sidebar . 'px;}}';
	}

	if ( '' !== $css ) {
		echo '<style id="themify-layout-widths">' . $css . '</style>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built only from bounded integers.
	}
}
add_action( 'wp_head', 'themify_layout_width_css', 40 );

/**
 * Apply the configured excerpt length to WordPress excerpts.
 *
 * @param int $length Default word count.
 * @return int
 */
function themify_excerpt_length( $length ) {
	$configured = (int) themify_get_option( 'excerpt_length', 28 );
	return $configured > 0 ? $configured : $length;
}
add_filter( 'excerpt_length', 'themify_excerpt_length', 20 );

/**
 * Replace the default "[…]" excerpt suffix with a clean ellipsis.
 *
 * @param string $more Default more string.
 * @return string
 */
function themify_excerpt_more( $more ) {
	return '&hellip;';
}
add_filter( 'excerpt_more', 'themify_excerpt_more' );
