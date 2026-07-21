<?php
/**
 * Colors & Fonts customizer.
 *
 * Lets the site owner re-brand the whole theme from one screen. Every visible
 * colour and the typography stacks are just CSS custom properties declared in
 * :root (see assets/css/main.css); this module reads the owner's chosen values
 * and prints a small `:root{ … }` override block.
 *
 * The critical piece is themify_color_overrides_css(): enqueue.php calls it
 * (guarded by function_exists) and inlines the result inside the critical
 * <head> CSS, so the very first paint already uses the brand palette with no
 * flash of the default (green) theme. performance.php + enqueue.php also read
 * the `load_google_fonts` option to decide whether to load Inter + Playfair.
 *
 * Option keys → CSS variables (only the ones the owner has set are emitted):
 *   color_accent    → --tf-accent (+ derived --tf-accent-glow / --tf-accent-bg)
 *   color_btn       → --tf-btn
 *   color_btn_hover → --tf-btn-hover
 *   color_text      → --tf-text
 *   color_nav_bg    → --tf-bg-nav
 *   color_card_bg   → --tf-bg-card
 *   color_border    → --tf-border
 *   color_body_bg   → --tf-body-bg  (solid colour OR a CSS gradient string)
 *   font_body       → --tf-font-body stack (system | inter)
 *   font_head       → --tf-font-head stack (serif | sans | inter)
 *   font_base_size  → --tf-fs-base  (pixels)
 *   load_google_fonts (checkbox, read by enqueue.php / performance.php)
 *
 * @package Themify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the "Colors & Fonts" submenu page (position 10).
 */
themify_register_admin_page( array(
	'slug'       => 'themify-colors',
	'title'      => __( 'Colors & Fonts', 'themify' ),
	'menu_title' => __( 'Colors & Fonts', 'themify' ),
	'callback'   => 'themify_colors_page',
	'position'   => 46,
) );

/**
 * Add the Colors & Fonts card to the dashboard grid.
 */
add_filter( 'themify_dashboard_cards', 'themify_colors_dashboard_card' );

/**
 * Append the Colors & Fonts dashboard card.
 *
 * @param array $cards Existing dashboard cards.
 * @return array
 */
function themify_colors_dashboard_card( $cards ) {
	$cards[] = array(
		'slug'     => 'themify-colors',
		'title'    => __( 'Colors & Fonts', 'themify' ),
		'desc'     => __( 'Brand palette and typography', 'themify' ),
		'icon'     => 'dashicons-art',
		'position' => 46,
	);
	return $cards;
}

/**
 * The field definitions for this screen, grouped into cards. Shared by the
 * save handler (for sanitisation) and the renderer (for output) so the two can
 * never drift apart.
 *
 * @return array Groups: array of array{ title:string, desc:string, fields:array }.
 */
function themify_colors_fields() {
	return array(

		// Brand colours (solid colours → wp-color-picker).
		array(
			'title' => __( 'Brand colors', 'themify' ),
			'desc'  => __( 'Pick your palette. Each colour maps to a CSS variable used across the whole theme, so the site re-brands instantly. Leave a field blank to keep the theme default.', 'themify' ),
			'fields' => array(
				array(
					'key'   => 'color_accent',
					'label' => __( 'Accent', 'themify' ),
					'type'  => 'color',
					'desc'  => __( 'Links, pills, highlights and focus rings. Lighter glow and tint shades are derived from this automatically.', 'themify' ),
				),
				array(
					'key'   => 'color_btn',
					'label' => __( 'Button', 'themify' ),
					'type'  => 'color',
					'desc'  => __( 'Background colour of primary buttons.', 'themify' ),
				),
				array(
					'key'   => 'color_btn_hover',
					'label' => __( 'Button (hover)', 'themify' ),
					'type'  => 'color',
					'desc'  => __( 'Button background on hover.', 'themify' ),
				),
				array(
					'key'   => 'color_text',
					'label' => __( 'Body text', 'themify' ),
					'type'  => 'color',
					'desc'  => __( 'Main text colour.', 'themify' ),
				),
				array(
					'key'   => 'color_nav_bg',
					'label' => __( 'Header background', 'themify' ),
					'type'  => 'color',
					'desc'  => __( 'Background of the sticky site header.', 'themify' ),
				),
				array(
					'key'   => 'color_card_bg',
					'label' => __( 'Card background', 'themify' ),
					'type'  => 'color',
					'desc'  => __( 'Background of post cards, articles, widgets and the header menu.', 'themify' ),
				),
				array(
					'key'   => 'color_border',
					'label' => __( 'Borders', 'themify' ),
					'type'  => 'color',
					'desc'  => __( 'Hairline borders around cards, inputs and dividers.', 'themify' ),
				),
			),
		),

		// Page background — accepts a solid colour OR a full CSS gradient, so it
		// is a free-text field rather than a colour picker.
		array(
			'title' => __( 'Page background', 'themify' ),
			'desc'  => __( 'Set the whole-page background. You can use a solid colour (e.g. #f7faf8) or a full CSS gradient.', 'themify' ),
			'fields' => array(
				array(
					'key'         => 'color_body_bg',
					'label'       => __( 'Body background', 'themify' ),
					'type'        => 'text',
					'placeholder' => 'linear-gradient(145deg, #ecfaf1 0%, #c8e8d2 70%, #ecfaf1 100%)',
					'desc'        => __( 'A CSS colour or gradient. Examples: <code>#0f172a</code> or <code>linear-gradient(160deg, #0f172a, #1e293b)</code>.', 'themify' ),
				),
			),
		),

		// Typography.
		array(
			'title' => __( 'Typography', 'themify' ),
			'desc'  => __( 'Choose the font families and base size. The Inter and Playfair Display webfonts only load when you enable Google Fonts below.', 'themify' ),
			'fields' => array(
				array(
					'key'     => 'font_body',
					'label'   => __( 'Body font', 'themify' ),
					'type'    => 'select',
					'default' => 'system',
					'options' => array(
						'system' => __( 'System UI (fastest)', 'themify' ),
						'inter'  => __( 'Inter', 'themify' ),
					),
					'desc'    => __( 'Font used for paragraphs and UI text.', 'themify' ),
				),
				array(
					'key'     => 'font_head',
					'label'   => __( 'Heading font', 'themify' ),
					'type'    => 'select',
					'default' => 'serif',
					'options' => array(
						'serif' => __( 'Playfair Display (elegant serif)', 'themify' ),
						'sans'  => __( 'System sans-serif', 'themify' ),
						'inter' => __( 'Inter', 'themify' ),
					),
					'desc'    => __( 'Font used for headings and the brand.', 'themify' ),
				),
				array(
					'key'         => 'font_base_size',
					'label'       => __( 'Base font size (px)', 'themify' ),
					'type'        => 'number',
					'placeholder' => '17',
					'desc'        => __( 'Root body font size in pixels. The theme default is 17. Leave blank to keep it.', 'themify' ),
				),
				array(
					'key'     => 'load_google_fonts',
					'label'   => __( 'Load Google Fonts (Inter + Playfair Display)', 'themify' ),
					'type'    => 'checkbox',
					'default' => '',
					'desc'    => __( 'Off by default for maximum speed. Enable this if you selected Inter or Playfair above so the webfonts actually load.', 'themify' ),
				),
			),
		),
	);
}

/**
 * Sanitise the page background value. It may be a solid colour or a full CSS
 * gradient, so we cannot restrict it to a hex. We keep it on a single line and
 * defensively strip anything that could break out of the inline <style> block
 * it ends up in.
 *
 * @param mixed $raw Raw (unslashed) posted value.
 * @return string
 */
function themify_sanitize_css_value( $raw ) {
	$val = trim( (string) $raw );
	if ( '' === $val ) {
		return '';
	}
	// Collapse newlines/tabs so it stays a single declaration value.
	$val = preg_replace( '/\s+/', ' ', $val );
	// Defensively neutralise any attempt to close the style block or inject a
	// script — this value is printed inside an inline <style>.
	$val = str_ireplace( array( '</style', '<script', '</script', '{', '}', ';' ), '', $val );
	return trim( $val );
}

/**
 * Render the Colors & Fonts page. We handle the save ourselves (rather than
 * using themify_render_settings_page) because color_body_bg needs the bespoke
 * gradient-safe sanitiser above; every other field is sanitised through the
 * shared themify_sanitize_field() so behaviour stays consistent.
 */
function themify_colors_page() {
	$groups     = themify_colors_fields();
	$all_fields = array();
	foreach ( $groups as $g ) {
		$all_fields = array_merge( $all_fields, $g['fields'] );
	}

	// Handle save.
	if ( themify_verify_save( 'themify_colors' ) ) {
		$posted  = isset( $_POST[ THEMIFY_OPT ] ) && is_array( $_POST[ THEMIFY_OPT ] )
			? wp_unslash( $_POST[ THEMIFY_OPT ] ) // phpcs:ignore WordPress.Security.ValidatedSanitized
			: array();
		$to_save = array();
		foreach ( $all_fields as $field ) {
			$k   = $field['key'];
			$raw = $posted[ $k ] ?? '';
			if ( 'color_body_bg' === $k ) {
				$to_save[ $k ] = themify_sanitize_css_value( $raw );
			} else {
				$to_save[ $k ] = themify_sanitize_field( $raw, $field );
			}
		}
		themify_set_options( $to_save );
		add_settings_error( 'themify', 'saved', __( 'Colors & fonts saved.', 'themify' ), 'success' );
	}

	themify_admin_header(
		__( 'Colors & Fonts', 'themify' ),
		__( 'Brand the theme in seconds. These options print a handful of CSS variables into the page head, so your palette shows on the very first paint with no flash.', 'themify' )
	);
	settings_errors( 'themify' );

	echo '<form method="post" class="tf-form">';
	wp_nonce_field( 'themify_colors', 'themify_nonce' );

	foreach ( $groups as $group ) {
		echo '<div class="tf-card">';
		echo '<h2 class="tf-card__title">' . esc_html( $group['title'] ) . '</h2>';
		if ( ! empty( $group['desc'] ) ) {
			echo '<p class="tf-card__desc">' . wp_kses_post( $group['desc'] ) . '</p>';
		}
		foreach ( $group['fields'] as $field ) {
			themify_render_field( $field );
		}
		echo '</div>';
	}

	echo '<p class="tf-form__actions"><button type="submit" class="button button-primary button-hero">' . esc_html__( 'Save Changes', 'themify' ) . '</button></p>';
	echo '</form>';

	themify_admin_footer();
}

/**
 * Font stacks for each body/heading option value. Kept in sync with the
 * defaults in assets/css/main.css so switching back to the theme defaults is
 * a no-op.
 *
 * @return array{ body:array<string,string>, head:array<string,string> }
 */
function themify_font_stacks() {
	$system = '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif';
	$inter  = '"Inter", ' . $system;
	$serif  = '"Playfair Display", Georgia, "Times New Roman", serif';

	return array(
		'body' => array(
			'system' => $system,
			'inter'  => $inter,
		),
		'head' => array(
			'serif' => $serif,
			'sans'  => $system,
			'inter' => $inter,
		),
	);
}

/**
 * Turn a hex colour into an "r, g, b" triplet for building rgba() values.
 *
 * @param string $hex A #rgb or #rrggbb colour.
 * @return string|false "r, g, b" or false if the input is not a hex colour.
 */
function themify_hex_to_rgb( $hex ) {
	$hex = ltrim( trim( (string) $hex ), '#' );
	if ( 3 === strlen( $hex ) ) {
		$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
	}
	if ( ! preg_match( '/^[0-9a-f]{6}$/i', $hex ) ) {
		return false;
	}
	return sprintf(
		'%d, %d, %d',
		hexdec( substr( $hex, 0, 2 ) ),
		hexdec( substr( $hex, 2, 2 ) ),
		hexdec( substr( $hex, 4, 2 ) )
	);
}

/**
 * Lighten a hex colour by a percentage (0–100) toward white. Used to derive an
 * accent "glow" shade when the owner only sets the base accent.
 *
 * @param string $hex     A #rgb or #rrggbb colour.
 * @param int    $percent How far toward white (0–100).
 * @return string|false #rrggbb or false when the input is not a hex colour.
 */
function themify_lighten_hex( $hex, $percent ) {
	$hex = ltrim( trim( (string) $hex ), '#' );
	if ( 3 === strlen( $hex ) ) {
		$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
	}
	if ( ! preg_match( '/^[0-9a-f]{6}$/i', $hex ) ) {
		return false;
	}
	$percent = max( 0, min( 100, (int) $percent ) ) / 100;
	$out     = '#';
	for ( $i = 0; $i < 3; $i++ ) {
		$channel = hexdec( substr( $hex, $i * 2, 2 ) );
		$channel = (int) round( $channel + ( 255 - $channel ) * $percent );
		$out    .= str_pad( dechex( max( 0, min( 255, $channel ) ) ), 2, '0', STR_PAD_LEFT );
	}
	return $out;
}

/**
 * Read an option and, if it is a valid hex colour, return it; otherwise ''.
 * Guards the override CSS against stray non-colour values.
 *
 * @param string $key Option key.
 * @return string A lowercase hex colour or ''.
 */
function themify_get_hex_option( $key ) {
	$val = trim( (string) themify_get_option( $key, '' ) );
	if ( '' === $val ) {
		return '';
	}
	return preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $val ) ? strtolower( $val ) : '';
}

/**
 * Build the `:root { … }` CSS overriding only the --tf-* variables the owner
 * has actually set. Returns a bare CSS string with NO <style> tag. enqueue.php
 * inlines this into the critical head CSS.
 *
 * Safe to call when nothing is set — returns '' so the page keeps the theme
 * defaults from main.css.
 *
 * @return string CSS (possibly empty).
 */
function themify_color_overrides_css() {
	$vars = array();

	// Accent — plus derived glow + tint when only the base accent is chosen.
	$accent = themify_get_hex_option( 'color_accent' );
	if ( '' !== $accent ) {
		$vars['--tf-accent'] = $accent;

		$glow = themify_lighten_hex( $accent, 22 );
		if ( $glow ) {
			$vars['--tf-accent-glow'] = $glow;
		}
		$rgb = themify_hex_to_rgb( $accent );
		if ( false !== $rgb ) {
			$vars['--tf-accent-bg'] = 'rgba(' . $rgb . ', 0.08)';
		}
	}

	// Straightforward hex → variable mappings.
	$map = array(
		'color_btn'       => '--tf-btn',
		'color_btn_hover' => '--tf-btn-hover',
		'color_text'      => '--tf-text',
		'color_nav_bg'    => '--tf-bg-nav',
		'color_card_bg'   => '--tf-bg-card',
		'color_border'    => '--tf-border',
	);
	foreach ( $map as $opt => $var ) {
		$hex = themify_get_hex_option( $opt );
		if ( '' !== $hex ) {
			$vars[ $var ] = $hex;
		}
	}

	// Page background — solid colour OR gradient (already sanitised on save).
	$body_bg = trim( (string) themify_get_option( 'color_body_bg', '' ) );
	if ( '' !== $body_bg ) {
		// Re-sanitise on the way out as belt-and-braces (values inline into a
		// <style>). This also covers values stored before this module existed.
		$body_bg = themify_sanitize_css_value( $body_bg );
		if ( '' !== $body_bg ) {
			$vars['--tf-body-bg'] = $body_bg;
		}
	}

	// Typography — font stacks from the two selects.
	$stacks = themify_font_stacks();

	$font_body = (string) themify_get_option( 'font_body', '' );
	if ( isset( $stacks['body'][ $font_body ] ) ) {
		$vars['--tf-font-body'] = $stacks['body'][ $font_body ];
	}

	$font_head = (string) themify_get_option( 'font_head', '' );
	if ( isset( $stacks['head'][ $font_head ] ) ) {
		$vars['--tf-font-head'] = $stacks['head'][ $font_head ];
	}

	// Base font size in px → --tf-fs-base.
	$base = themify_get_option( 'font_base_size', '' );
	if ( '' !== trim( (string) $base ) && is_numeric( $base ) ) {
		$px = (int) round( (float) $base );
		if ( $px >= 10 && $px <= 30 ) {
			$vars['--tf-fs-base'] = $px . 'px';
		}
	}

	if ( empty( $vars ) ) {
		return '';
	}

	$decls = '';
	foreach ( $vars as $name => $value ) {
		$decls .= $name . ':' . $value . ';';
	}

	return ':root{' . $decls . '}';
}
