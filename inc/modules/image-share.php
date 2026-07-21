<?php
/**
 * Image share buttons — hover any content image and share icons appear on it
 * (Pinterest-style), each opening the network's share screen in a popup with
 * the image, the page URL and the title pre-filled.
 *
 *   - Ships with Pinterest configured out of the box.
 *   - The owner can add ANY network with just a name + share-link template
 *     (placeholders {url} {image} {title}); a brand icon is matched
 *     automatically from the name/link for all the major networks, and
 *     anything unknown gets a clean letter badge.
 *   - Master toggle + network list live on the Share Bar settings screen
 *     (Themixify → Share Bar → "Share icons on images").
 *
 * Front end: markup-free — main.js wraps eligible images at runtime using a
 * config object this module prints in wp_footer. Buttons open share popups;
 * nothing external is loaded (icons are inline SVGs bundled in main.js).
 *
 * @package Themify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Option holding the image-share config. */
if ( ! defined( 'THEMIFY_IMGSHARE_OPT' ) ) {
	define( 'THEMIFY_IMGSHARE_OPT', 'themify_image_share' );
}

/* -------------------------------------------------------------------------
 * DATA
 * ---------------------------------------------------------------------- */

/**
 * The stored config, normalised. First run defaults to Pinterest, enabled.
 *
 * @return array { enabled:bool, networks: array<int,array{name:string,url:string}> }
 */
function themify_imgshare_config() {
	$raw = get_option( THEMIFY_IMGSHARE_OPT, null );

	if ( ! is_array( $raw ) ) {
		return array(
			'enabled'  => true,
			'networks' => array(
				array(
					'name' => 'Pinterest',
					'url'  => 'https://pinterest.com/pin/create/button/?url={url}&media={image}&description={title}',
				),
			),
		);
	}

	$networks = array();
	if ( isset( $raw['networks'] ) && is_array( $raw['networks'] ) ) {
		foreach ( $raw['networks'] as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$name = isset( $row['name'] ) ? trim( (string) $row['name'] ) : '';
			$url  = isset( $row['url'] ) ? trim( (string) $row['url'] ) : '';
			if ( '' === $name || '' === $url ) {
				continue;
			}
			$networks[] = array(
				'name' => $name,
				'url'  => $url,
			);
		}
	}

	return array(
		'enabled'  => ! empty( $raw['enabled'] ),
		'networks' => $networks,
	);
}

/**
 * Match a network to a bundled brand icon + colour by its name/share URL.
 * main.js holds the actual SVG paths keyed by these slugs; anything unknown
 * falls back to a letter badge in a neutral colour.
 *
 * @param string $name Network name.
 * @param string $url  Share URL template.
 * @return array { icon:string, color:string }
 */
function themify_imgshare_brand( $name, $url ) {
	$haystack = strtolower( $name . ' ' . $url );
	$brands   = array(
		'pinterest' => array( 'pinterest', '#bd081c' ),
		'facebook'  => array( 'facebook', '#1877f2' ),
		'twitter'   => array( 'x', '#111111' ),
		'x.com'     => array( 'x', '#111111' ),
		'whatsapp'  => array( 'whatsapp', '#25d366' ),
		'telegram'  => array( 'telegram', '#26a5e4' ),
		'linkedin'  => array( 'linkedin', '#0a66c2' ),
		'reddit'    => array( 'reddit', '#ff4500' ),
		'tumblr'    => array( 'tumblr', '#35465c' ),
		'mail'      => array( 'email', '#6b7280' ),
		'email'     => array( 'email', '#6b7280' ),
	);
	foreach ( $brands as $needle => $brand ) {
		if ( false !== strpos( $haystack, $needle ) ) {
			return array(
				'icon'  => $brand[0],
				'color' => $brand[1],
			);
		}
	}
	return array(
		'icon'  => '',
		'color' => '#374151',
	);
}

/* -------------------------------------------------------------------------
 * FRONT END — print the JS config
 * ---------------------------------------------------------------------- */

/**
 * Print the config object main.js consumes. Front end only, and only when
 * enabled with at least one network.
 */
function themify_imgshare_print_config() {
	if ( is_admin() || is_feed() || is_robots() ) {
		return;
	}
	$config = themify_imgshare_config();
	if ( ! $config['enabled'] || empty( $config['networks'] ) ) {
		return;
	}

	$networks = array();
	foreach ( $config['networks'] as $network ) {
		$brand      = themify_imgshare_brand( $network['name'], $network['url'] );
		$networks[] = array(
			'name'  => $network['name'],
			'url'   => $network['url'],
			'icon'  => $brand['icon'],
			'color' => $brand['color'],
		);
	}

	printf(
		'<script>window.tfImgShare = %s;</script>',
		wp_json_encode( array(
			'networks' => $networks,
			'title'    => wp_strip_all_tags( is_singular() ? get_the_title() : get_bloginfo( 'name' ) ),
		) )
	);
}
add_action( 'wp_footer', 'themify_imgshare_print_config', 5 );

/* -------------------------------------------------------------------------
 * ADMIN — settings card on the Share Bar screen
 * ---------------------------------------------------------------------- */

/**
 * Handle the card's own POST save (separate nonce from the main share form).
 *
 * @return bool True when a valid save happened.
 */
function themify_imgshare_handle_save() {
	if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! isset( $_POST['themify_imgshare_nonce'] ) ) {
		return false;
	}
	if ( ! current_user_can( THEMIFY_CAP ) ) {
		return false;
	}
	$nonce = sanitize_text_field( wp_unslash( $_POST['themify_imgshare_nonce'] ) );
	if ( ! wp_verify_nonce( $nonce, 'themify_imgshare' ) ) {
		return false;
	}

	$rows_in = isset( $_POST['themify_imgshare_rows'] ) && is_array( $_POST['themify_imgshare_rows'] )
		? wp_unslash( $_POST['themify_imgshare_rows'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per field below.
		: array();

	$networks = array();
	foreach ( $rows_in as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$name = isset( $row['name'] ) ? sanitize_text_field( $row['name'] ) : '';
		$url  = isset( $row['url'] ) ? trim( sanitize_text_field( $row['url'] ) ) : '';
		if ( '' === trim( $name ) || '' === $url ) {
			continue;
		}
		// Allow only http(s) templates.
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			continue;
		}
		$networks[] = array(
			'name' => $name,
			'url'  => $url,
		);
	}

	update_option( THEMIFY_IMGSHARE_OPT, array(
		'enabled'  => isset( $_POST['themify_imgshare_enabled'] ) ? '1' : '',
		'networks' => $networks,
	), false );

	return true;
}

/**
 * Render one network repeater row.
 *
 * @param int|string $index Row index ('__INDEX__' for the JS template).
 * @param array      $row   ['name'=>, 'url'=>].
 */
function themify_imgshare_render_row( $index, array $row = array() ) {
	$row  = wp_parse_args( $row, array(
		'name' => '',
		'url'  => '',
	) );
	$base = 'themify_imgshare_rows[' . $index . ']';

	echo '<div class="tf-repeater__row" style="grid-template-columns: 200px 1fr auto; align-items:end;">';

	echo '<div class="tf-field tf-field--text" style="margin-bottom:0;">';
	printf( '<label class="tf-field__label">%s</label>', esc_html__( 'Name', 'themify' ) );
	printf(
		'<input type="text" name="%s[name]" value="%s" class="tf-input" placeholder="%s" />',
		esc_attr( $base ),
		esc_attr( $row['name'] ),
		esc_attr__( 'e.g. Pinterest', 'themify' )
	);
	echo '</div>';

	echo '<div class="tf-field tf-field--text" style="margin-bottom:0;">';
	printf( '<label class="tf-field__label">%s</label>', esc_html__( 'Share link', 'themify' ) );
	printf(
		'<input type="text" name="%s[url]" value="%s" class="tf-input" style="max-width:none;" placeholder="%s" />',
		esc_attr( $base ),
		esc_attr( $row['url'] ),
		esc_attr( 'https://pinterest.com/pin/create/button/?url={url}&media={image}&description={title}' )
	);
	echo '</div>';

	echo '<div class="tf-field" style="margin-bottom:0;"><a href="#" class="tf-remove">' . esc_html__( 'Remove', 'themify' ) . '</a></div>';

	echo '</div>';
}

/**
 * The settings card, rendered at the end of the Share Bar screen via the
 * settings-page 'after' hook.
 */
function themify_imgshare_render_card() {
	$saved  = themify_imgshare_handle_save();
	$config = themify_imgshare_config();

	if ( $saved ) {
		echo '<div class="tf-notice tf-notice--info">' . esc_html__( 'Image share icons saved.', 'themify' ) . '</div>';
	}

	echo '<form method="post" class="tf-form">';
	wp_nonce_field( 'themify_imgshare', 'themify_imgshare_nonce' );

	echo '<div class="tf-card">';
	echo '<h2 class="tf-card__title">' . esc_html__( 'Share icons on images', 'themify' ) . '</h2>';
	echo '<p class="tf-card__desc">' . wp_kses_post( __( 'Hovering any article image shows these icons on the image; clicking opens that network\'s share screen with the image, page link and title pre-filled. Add any network — placeholders: <code>{url}</code> the page link, <code>{image}</code> the image link, <code>{title}</code> the post title. The icon is matched automatically from the name (Pinterest, Facebook, X, WhatsApp, Telegram, LinkedIn, Reddit, Tumblr, Email); unknown networks get a letter badge.', 'themify' ) ) . '</p>';

	echo '<div class="tf-field tf-field--checkbox">';
	echo '<label class="tf-switch">';
	printf(
		'<input type="checkbox" name="themify_imgshare_enabled" value="1" %s />',
		checked( $config['enabled'], true, false )
	);
	echo '<span class="tf-switch__track"></span>';
	echo '<span class="tf-switch__label">' . esc_html__( 'Show share icons when hovering images', 'themify' ) . '</span>';
	echo '</label>';
	echo '</div>';

	echo '<div class="tf-repeater">';
	echo '<script type="text/html" class="tf-repeater__template">';
	themify_imgshare_render_row( '__INDEX__' );
	echo '</script>';

	echo '<div class="tf-repeater__rows">';
	if ( ! empty( $config['networks'] ) ) {
		foreach ( $config['networks'] as $i => $row ) {
			themify_imgshare_render_row( $i, $row );
		}
	} else {
		themify_imgshare_render_row( 0, array(
			'name' => 'Pinterest',
			'url'  => 'https://pinterest.com/pin/create/button/?url={url}&media={image}&description={title}',
		) );
	}
	echo '</div>';

	echo '<p><button type="button" class="button tf-repeater__add">' . esc_html__( '+ Add network', 'themify' ) . '</button></p>';
	echo '</div>'; // .tf-repeater
	echo '</div>'; // .tf-card

	echo '<p class="tf-form__actions"><button type="submit" class="button button-primary button-hero">' . esc_html__( 'Save image share icons', 'themify' ) . '</button></p>';
	echo '</form>';
}
