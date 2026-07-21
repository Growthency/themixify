<?php
/**
 * Floating social share bar.
 *
 * A small toggle button pinned to a corner (default: bottom-left, opposite the
 * back-to-top button). Clicking it expands a stack of share buttons — Facebook,
 * WhatsApp, Telegram, LinkedIn, X and a copy-link button — that share the
 * current page. Clicking again collapses them. The whole thing is progressive:
 * server renders real share links (work without JS); main.js adds the
 * open/close toggle and the copy-to-clipboard behaviour.
 *
 * All settings live in THEMIFY_OPT. Nothing is fetched externally.
 *
 * @package Themify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* -------------------------------------------------------------------------
 * SETTINGS METADATA
 * ---------------------------------------------------------------------- */

/**
 * The share networks the bar can show, in display order, each mapped to its
 * option key and label. 'copy' is the copy-link button (handled by JS).
 *
 * @return array<string,array{key:string,label:string}>
 */
function themify_share_networks() {
	return array(
		'facebook' => array( 'key' => 'share_facebook', 'label' => __( 'Facebook', 'themify' ) ),
		'whatsapp' => array( 'key' => 'share_whatsapp', 'label' => __( 'WhatsApp', 'themify' ) ),
		'telegram' => array( 'key' => 'share_telegram', 'label' => __( 'Telegram', 'themify' ) ),
		'linkedin' => array( 'key' => 'share_linkedin', 'label' => __( 'LinkedIn', 'themify' ) ),
		'x'        => array( 'key' => 'share_x', 'label' => __( 'X (Twitter)', 'themify' ) ),
		'youtube'  => array( 'key' => 'share_youtube', 'label' => __( 'YouTube', 'themify' ) ),
		'copy'     => array( 'key' => 'share_copy', 'label' => __( 'Copy link', 'themify' ) ),
	);
}

/**
 * The channel URL used by the "YouTube" button (it links to your channel rather
 * than sharing the page). Empty when no channel is configured, so the button is
 * hidden instead of rendering a dead link.
 *
 * @return string
 */
function themify_share_youtube_url() {
	if ( function_exists( 'themify_youtube_url' ) ) {
		return themify_youtube_url();
	}
	return trim( (string) themify_get_option( 'yt_channel_url', '' ) );
}

/**
 * Whether a given network is switched on. Sensible defaults so the bar is
 * useful out of the box (all on except X).
 *
 * @param string $key Option key.
 * @return bool
 */
function themify_share_network_on( $key ) {
	$defaults = array(
		'share_facebook' => true,
		'share_whatsapp' => true,
		'share_telegram' => true,
		'share_linkedin' => true,
		'share_x'        => false,
		'share_youtube'  => true,
		'share_copy'     => true,
	);
	$opts = themify_get_options();
	if ( ! array_key_exists( $key, $opts ) ) {
		return ! empty( $defaults[ $key ] );
	}
	return themify_is_enabled( $key );
}

/* -------------------------------------------------------------------------
 * CONTEXT
 * ---------------------------------------------------------------------- */

/** URL of the page being shared. */
function themify_share_current_url() {
	if ( is_singular() ) {
		return get_permalink();
	}
	$req  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
	$path = wp_parse_url( $req, PHP_URL_PATH );
	return home_url( $path ? $path : '/' );
}

/** Title of the page being shared. */
function themify_share_current_title() {
	if ( is_singular() ) {
		return get_the_title();
	}
	return wp_get_document_title();
}

/** Should the share bar render on this request? */
function themify_share_should_show() {
	if ( is_admin() || is_feed() || is_embed() || is_404() ) {
		return false;
	}
	if ( ! themify_is_enabled( 'share_enabled', true ) ) {
		return false;
	}
	// Single posts have the rich in-article sharing (top/bottom rows + the left
	// floating bar), so the corner toggle steps aside there. It shows on EVERY
	// other page — home, archives, categories, single pages.
	if ( is_singular( 'post' ) ) {
		return false;
	}
	$where = themify_get_option( 'share_show', 'everywhere' );
	if ( 'singular' === $where && ! is_singular() ) {
		return false;
	}
	if ( 'posts' === $where && ! is_singular( 'post' ) ) {
		return false;
	}
	// At least one network must actually be renderable. YouTube only counts when
	// a channel URL is set, matching the render loop — otherwise the bar could
	// open to an empty list.
	$yt = themify_share_youtube_url();
	foreach ( themify_share_networks() as $slug => $net ) {
		if ( 'youtube' === $slug && '' === $yt ) {
			continue;
		}
		if ( themify_share_network_on( $net['key'] ) ) {
			return true;
		}
	}
	return false;
}

/* -------------------------------------------------------------------------
 * ICONS
 * ---------------------------------------------------------------------- */

/**
 * Inline SVG for a share network / control. Returns a 20x20 currentColor icon.
 *
 * @param string $name Network or control name.
 * @return string SVG markup.
 */
function themify_share_icon( $name ) {
	$svg = array(
		'facebook' => '<path d="M22 12.06C22 6.5 17.52 2 12 2S2 6.5 2 12.06c0 5 3.66 9.14 8.44 9.9v-7H7.9v-2.9h2.54V9.85c0-2.5 1.49-3.89 3.77-3.89 1.09 0 2.24.2 2.24.2v2.46h-1.26c-1.24 0-1.63.77-1.63 1.56v1.88h2.78l-.44 2.9h-2.34v7A9.97 9.97 0 0 0 22 12.06Z"/>',
		'whatsapp' => '<path d="M12.04 2a9.9 9.9 0 0 0-8.4 15.16L2 22l4.96-1.3A9.9 9.9 0 1 0 12.04 2Zm0 1.8a8.1 8.1 0 1 1-4.13 15.06l-.3-.18-2.94.77.78-2.87-.2-.3A8.1 8.1 0 0 1 12.04 3.8Zm4.66 11.5c-.25-.13-1.48-.73-1.71-.82-.23-.08-.4-.12-.56.13-.17.25-.64.81-.79.98-.14.17-.29.19-.54.06-.25-.13-1.06-.39-2.01-1.24-.74-.66-1.24-1.48-1.39-1.73-.14-.25-.01-.38.11-.5.11-.11.25-.29.37-.43.13-.14.17-.25.25-.41.08-.17.04-.31-.02-.44-.06-.13-.56-1.35-.77-1.85-.2-.48-.41-.42-.56-.43h-.48c-.17 0-.44.06-.67.31-.23.25-.88.86-.88 2.1 0 1.24.9 2.43 1.03 2.6.13.17 1.77 2.7 4.3 3.78.6.26 1.07.41 1.43.53.6.19 1.15.16 1.58.1.48-.07 1.48-.6 1.69-1.19.21-.58.21-1.08.14-1.19-.06-.11-.23-.17-.48-.3Z"/>',
		'telegram' => '<path d="M21.94 4.9 18.9 19.2c-.23 1.02-.84 1.27-1.7.79l-4.7-3.47-2.27 2.18c-.25.25-.46.46-.94.46l.34-4.78 8.7-7.86c.38-.34-.08-.53-.59-.19L6.78 13.2 2.13 11.74c-1.01-.32-1.03-1.01.21-1.5l18.16-7c.84-.31 1.58.2 1.44.66Z"/>',
		'linkedin' => '<path d="M6.94 5a2 2 0 1 1-4 0 2 2 0 0 1 4 0ZM3.3 8.5h3.28V21H3.3V8.5Zm5.34 0h3.14v1.71h.05c.44-.83 1.5-1.71 3.1-1.71 3.31 0 3.92 2.18 3.92 5.01V21h-3.27v-4.87c0-1.16-.02-2.65-1.62-2.65-1.62 0-1.87 1.27-1.87 2.57V21H8.64V8.5Z"/>',
		'x'        => '<path d="M17.53 3H20.5l-6.49 7.42L21.75 21h-5.98l-4.68-6.12L5.7 21H2.73l6.94-7.93L2.25 3h6.13l4.23 5.6L17.53 3Zm-1.05 16.2h1.65L7.62 4.71H5.85L16.48 19.2Z"/>',
		'copy'     => '<path d="M10.6 13.4a4 4 0 0 0 5.66 0l2.83-2.83a4 4 0 1 0-5.66-5.66l-1.5 1.5 1.42 1.42 1.5-1.5a2 2 0 1 1 2.82 2.82l-2.82 2.83a2 2 0 0 1-2.83 0l-1.42 1.42Zm2.8-2.8a4 4 0 0 0-5.66 0l-2.83 2.83a4 4 0 1 0 5.66 5.66l1.5-1.5-1.42-1.42-1.5 1.5a2 2 0 1 1-2.82-2.82l2.82-2.83a2 2 0 0 1 2.83 0l1.42-1.42Z"/>',
		'pinterest' => '<path d="M12 2C6.48 2 2 6.48 2 12c0 4.24 2.64 7.86 6.36 9.32-.09-.79-.17-2 .03-2.87.18-.78 1.17-4.97 1.17-4.97s-.3-.6-.3-1.48c0-1.39.8-2.42 1.8-2.42.85 0 1.26.64 1.26 1.4 0 .86-.54 2.14-.83 3.33-.24.99.5 1.8 1.48 1.8 1.77 0 3.14-1.87 3.14-4.57 0-2.39-1.72-4.06-4.17-4.06-2.84 0-4.5 2.13-4.5 4.33 0 .86.33 1.78.74 2.28.08.1.09.19.07.29-.08.31-.24.98-.28 1.12-.04.18-.15.22-.34.13-1.25-.58-2.03-2.4-2.03-3.87 0-3.15 2.29-6.04 6.6-6.04 3.47 0 6.16 2.47 6.16 5.77 0 3.45-2.17 6.22-5.19 6.22-1.01 0-1.97-.53-2.29-1.15l-.62 2.37c-.23.86-.83 1.94-1.24 2.6.94.29 1.92.44 2.95.44 5.52 0 10-4.48 10-10S17.52 2 12 2Z"/>',
		'tumblr'   => '<path d="M14.06 3v3.28h3.2v3.03h-3.2v5.03c0 1.14.06 1.87.18 2.19.12.32.34.58.66.77.43.26.92.39 1.47.39.98 0 1.95-.32 2.92-.95v3.06c-.83.39-1.58.66-2.25.82-.67.16-1.4.24-2.18.24-.89 0-1.68-.11-2.37-.34a5.3 5.3 0 0 1-1.8-.97 3.6 3.6 0 0 1-1.08-1.5c-.2-.55-.3-1.35-.3-2.4V9.31H7.06V6.57c.75-.24 1.4-.6 1.94-1.06.54-.47.98-1.03 1.3-1.68.33-.66.56-1.5.68-2.53h3.08V3Z"/>',
		'email'    => '<path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2Zm0 2v.01L12 11l8-4.99V6H4Zm16 2.24-7.47 4.67a1 1 0 0 1-1.06 0L4 8.24V18h16V8.24Z"/>',
		'youtube'  => '<path d="M23.5 6.5a3.02 3.02 0 0 0-2.12-2.14C19.5 3.86 12 3.86 12 3.86s-7.5 0-9.38.5A3.02 3.02 0 0 0 .5 6.5C0 8.38 0 12 0 12s0 3.62.5 5.5a3.02 3.02 0 0 0 2.12 2.14c1.88.5 9.38.5 9.38.5s7.5 0 9.38-.5A3.02 3.02 0 0 0 23.5 17.5C24 15.62 24 12 24 12s0-3.62-.5-5.5ZM9.6 15.57V8.43L15.82 12 9.6 15.57Z"/>',
		'share'    => '<path d="M18 16.08c-.76 0-1.44.3-1.96.77L8.91 12.7c.05-.23.09-.46.09-.7s-.04-.47-.09-.7l7.05-4.11c.54.5 1.25.81 2.04.81a3 3 0 1 0-3-3c0 .24.04.47.09.7L8.04 9.81A3 3 0 1 0 6 15c.79 0 1.5-.31 2.04-.81l7.12 4.16c-.05.21-.08.43-.08.65a2.92 2.92 0 1 0 2.92-2.92Z"/>',
		'close'    => '<path d="M18.3 5.71 12 12l6.3 6.29-1.42 1.42L10.6 13.4 4.29 19.7l-1.42-1.42L9.17 12 2.87 5.71 4.29 4.29 10.6 10.6l6.29-6.3 1.41 1.41Z"/>',
	);
	$path = isset( $svg[ $name ] ) ? $svg[ $name ] : $svg['share'];
	return '<svg class="tf-share__icon" width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false">' . $path . '</svg>';
}

/* -------------------------------------------------------------------------
 * FRONT-END RENDER
 * ---------------------------------------------------------------------- */

/**
 * Build the share href for one network for a given url + title.
 *
 * @param string $network Network slug.
 * @param string $url     Page URL.
 * @param string $title   Page title.
 * @return string
 */
function themify_share_link( $network, $url, $title ) {
	$u = rawurlencode( $url );
	$t = rawurlencode( $title );
	switch ( $network ) {
		case 'facebook':
			return 'https://www.facebook.com/sharer/sharer.php?u=' . $u;
		case 'whatsapp':
			return 'https://api.whatsapp.com/send?text=' . $t . '%20' . $u;
		case 'telegram':
			return 'https://t.me/share/url?url=' . $u . '&text=' . $t;
		case 'linkedin':
			return 'https://www.linkedin.com/sharing/share-offsite/?url=' . $u;
		case 'x':
			return 'https://twitter.com/intent/tweet?url=' . $u . '&text=' . $t;
		case 'pinterest':
			$media = '';
			if ( is_singular() && has_post_thumbnail() ) {
				$img = wp_get_attachment_image_url( get_post_thumbnail_id(), 'full' );
				if ( $img ) {
					$media = '&media=' . rawurlencode( $img );
				}
			}
			return 'https://pinterest.com/pin/create/button/?url=' . $u . $media . '&description=' . $t;
		case 'tumblr':
			return 'https://www.tumblr.com/share/link?url=' . $u . '&name=' . $t;
		case 'email':
			return 'mailto:?subject=' . $t . '&body=' . $u;
		case 'youtube':
			// Not a page share — sends visitors to the channel.
			$yt = themify_share_youtube_url();
			return '' !== $yt ? $yt : '#';
	}
	return '#';
}

/* -------------------------------------------------------------------------
 * ARTICLE SHARE ROWS + FLOATING BAR (single posts)
 * ---------------------------------------------------------------------- */

/**
 * The networks available for the in-article share buttons (top row, bottom row
 * and the floating vertical bar), in display order. Each maps to an option key.
 *
 * @return array<string,array{key:string,label:string,default:bool}>
 */
function themify_article_networks() {
	return array(
		'facebook'  => array( 'key' => 'art_facebook', 'label' => __( 'Facebook', 'themify' ), 'default' => true ),
		'x'         => array( 'key' => 'art_x', 'label' => __( 'X (Twitter)', 'themify' ), 'default' => true ),
		'pinterest' => array( 'key' => 'art_pinterest', 'label' => __( 'Pinterest', 'themify' ), 'default' => true ),
		'linkedin'  => array( 'key' => 'art_linkedin', 'label' => __( 'LinkedIn', 'themify' ), 'default' => true ),
		'tumblr'    => array( 'key' => 'art_tumblr', 'label' => __( 'Tumblr', 'themify' ), 'default' => false ),
		'whatsapp'  => array( 'key' => 'art_whatsapp', 'label' => __( 'WhatsApp', 'themify' ), 'default' => false ),
		'telegram'  => array( 'key' => 'art_telegram', 'label' => __( 'Telegram', 'themify' ), 'default' => false ),
		'email'     => array( 'key' => 'art_email', 'label' => __( 'Email', 'themify' ), 'default' => true ),
		'youtube'   => array( 'key' => 'art_youtube', 'label' => __( 'YouTube', 'themify' ), 'default' => true ),
		'copy'      => array( 'key' => 'art_copy', 'label' => __( 'Copy link', 'themify' ), 'default' => false ),
	);
}

/** Is an article-share network enabled? Uses per-network defaults. */
function themify_article_network_on( $key, $default ) {
	$opts = themify_get_options();
	if ( ! array_key_exists( $key, $opts ) ) {
		return (bool) $default;
	}
	return themify_is_enabled( $key );
}

/** The enabled article networks as a flat slug list. */
function themify_article_enabled_networks() {
	$out   = array();
	$yt    = themify_share_youtube_url();
	foreach ( themify_article_networks() as $slug => $net ) {
		// The YouTube button needs a channel URL; hide it when none is set.
		if ( 'youtube' === $slug && '' === $yt ) {
			continue;
		}
		if ( themify_article_network_on( $net['key'], $net['default'] ) ) {
			$out[] = $slug;
		}
	}
	return $out;
}

/** Brand class hook so each button can carry its network colour. */
function themify_article_share_button( $slug, $url, $title, $labelled = false ) {
	$networks = themify_article_networks();
	$label    = isset( $networks[ $slug ] ) ? $networks[ $slug ]['label'] : ucfirst( $slug );
	$icon     = themify_share_icon( $slug ); // phpcs:ignore -- static SVG.
	$text     = $labelled ? '<span class="tf-artshare__text">' . esc_html( $label ) . '</span>' : '';

	if ( 'copy' === $slug ) {
		return sprintf(
			'<button type="button" class="tf-artshare__btn tf-artshare__btn--copy" data-tf-copy-link="%s" aria-label="%s" title="%s">%s%s</button>',
			esc_url( $url ),
			esc_attr( $label ),
			esc_attr( $label ),
			$icon, // phpcs:ignore WordPress.Security.EscapeOutput
			$text  // phpcs:ignore WordPress.Security.EscapeOutput
		);
	}

	$rel  = 'email' === $slug ? '' : ' target="_blank" rel="noopener noreferrer nofollow"';
	$aria = 'youtube' === $slug
		? __( 'Visit our YouTube channel', 'themify' )
		: sprintf( /* translators: %s: network */ __( 'Share on %s', 'themify' ), $label );
	return sprintf(
		'<a class="tf-artshare__btn tf-artshare__btn--%1$s" href="%2$s"%3$s aria-label="%4$s" title="%4$s">%5$s%6$s</a>',
		esc_attr( $slug ),
		esc_url( themify_share_link( $slug, $url, $title ) ),
		$rel, // phpcs:ignore WordPress.Security.EscapeOutput -- static string.
		esc_attr( $aria ),
		$icon, // phpcs:ignore WordPress.Security.EscapeOutput
		$text  // phpcs:ignore WordPress.Security.EscapeOutput
	);
}

/**
 * A horizontal in-article share row. $place is 'top' (below the title, coloured
 * pill buttons with labels) or 'bottom' (a "SHARE." labelled strip).
 *
 * @param string $place 'top' | 'bottom'.
 */
function themify_article_share_row( $place = 'top' ) {
	if ( ! is_singular( 'post' ) ) {
		return;
	}
	$opt = 'bottom' === $place ? 'article_share_bottom' : 'article_share_top';
	if ( ! themify_is_enabled( $opt, true ) ) {
		return;
	}
	$nets = themify_article_enabled_networks();
	if ( empty( $nets ) ) {
		return;
	}
	$url   = get_permalink();
	$title = get_the_title();

	echo '<div class="tf-artshare tf-artshare--' . esc_attr( $place ) . '">';
	if ( 'bottom' === $place ) {
		echo '<span class="tf-artshare__label">' . esc_html__( 'Share.', 'themify' ) . '</span>';
	}
	echo '<div class="tf-artshare__row">';
	foreach ( $nets as $slug ) {
		echo themify_article_share_button( $slug, $url, $title, 'top' === $place ); // phpcs:ignore WordPress.Security.EscapeOutput -- built with escaped parts.
	}
	echo '</div></div>';
}

/**
 * Whether the floating vertical share bar should render on this request. It
 * shows on EVERY front-end page (posts, pages, home, archives) on wide screens.
 *
 * @return bool
 */
function themify_share_float_active() {
	// The floating vertical bar belongs to ARTICLES only (single posts).
	if ( ! is_singular( 'post' ) || ! themify_is_enabled( 'article_share_float', true ) ) {
		return false;
	}
	return ! empty( themify_article_enabled_networks() );
}

/**
 * The floating vertical share bar pinned to the left on wide screens. Shown on
 * every front-end page. Rendered via wp_footer (fixed-position). Hidden on
 * narrow screens (CSS), where the corner toggle serves instead.
 */
function themify_article_share_float() {
	if ( ! themify_share_float_active() ) {
		return;
	}
	$nets  = themify_article_enabled_networks();
	$url   = themify_share_current_url();
	$title = themify_share_current_title();

	echo '<div class="tf-artshare tf-artshare--float" aria-label="' . esc_attr__( 'Share this article', 'themify' ) . '">';
	echo '<span class="tf-artshare__vlabel">' . esc_html__( 'Share', 'themify' ) . '</span>';
	foreach ( $nets as $slug ) {
		echo themify_article_share_button( $slug, $url, $title, false ); // phpcs:ignore WordPress.Security.EscapeOutput
	}
	echo '</div>';
}
add_action( 'wp_footer', 'themify_article_share_float', 15 );

/**
 * Render the floating share bar just before </body>.
 */
function themify_render_share_bar() {
	if ( ! themify_share_should_show() ) {
		return;
	}

	$url      = themify_share_current_url();
	$title    = themify_share_current_title();
	$position = 'right' === themify_get_option( 'share_position', 'left' ) ? 'right' : 'left';

	echo '<div class="tf-share tf-share--' . esc_attr( $position ) . '" data-tf-share>';
	echo '<div class="tf-share__items" aria-hidden="true">';

	foreach ( themify_share_networks() as $slug => $net ) {
		if ( ! themify_share_network_on( $net['key'] ) ) {
			continue;
		}
		// The YouTube button needs a channel URL; hide it when none is set.
		if ( 'youtube' === $slug && '' === themify_share_youtube_url() ) {
			continue;
		}

		if ( 'copy' === $slug ) {
			printf(
				'<button type="button" class="tf-share__btn tf-share__btn--copy" data-tf-copy-link="%s" aria-label="%s" title="%s" tabindex="-1">%s</button>',
				esc_url( $url ),
				esc_attr__( 'Copy link', 'themify' ),
				esc_attr__( 'Copy link', 'themify' ),
				themify_share_icon( 'copy' ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG.
			);
			continue;
		}

		$aria = 'youtube' === $slug
			? __( 'Visit our YouTube channel', 'themify' )
			: sprintf( /* translators: %s: network name */ __( 'Share on %s', 'themify' ), $net['label'] );
		printf(
			'<a class="tf-share__btn tf-share__btn--%1$s" href="%2$s" target="_blank" rel="noopener noreferrer nofollow" aria-label="%3$s" title="%3$s" tabindex="-1">%4$s</a>',
			esc_attr( $slug ),
			esc_url( themify_share_link( $slug, $url, $title ) ),
			esc_attr( $aria ),
			themify_share_icon( $slug ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG.
		);
	}

	echo '</div>'; // .tf-share__items

	printf(
		'<button type="button" class="tf-share__toggle" aria-expanded="false" aria-label="%s"><span class="tf-share__toggle-open">%s</span><span class="tf-share__toggle-close">%s</span></button>',
		esc_attr__( 'Share this page', 'themify' ),
		themify_share_icon( 'share' ) . '<span class="tf-share__toggle-text">' . esc_html__( 'Share', 'themify' ) . '</span>', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		themify_share_icon( 'close' ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	);

	echo '</div>'; // .tf-share
}
add_action( 'wp_footer', 'themify_render_share_bar', 20 );

/* -------------------------------------------------------------------------
 * ADMIN
 * ---------------------------------------------------------------------- */

themify_register_admin_page( array(
	'slug'       => 'themify-share',
	'title'      => __( 'Share Bar', 'themify' ),
	'menu_title' => __( 'Share Bar', 'themify' ),
	'callback'   => 'themify_share_page',
	'position'   => 52,
) );

add_filter( 'themify_dashboard_cards', function ( $cards ) {
	$cards[] = array(
		'slug'     => 'themify-share',
		'title'    => __( 'Share Bar', 'themify' ),
		'desc'     => __( 'Floating social share buttons', 'themify' ),
		'icon'     => 'dashicons-share',
		'position' => 52,
	);
	return $cards;
} );

/**
 * The "Share Bar" settings screen (declarative — plain fields → save).
 */
function themify_share_page() {
	$network_fields = array();
	foreach ( themify_share_networks() as $slug => $net ) {
		$network_fields[] = array(
			'key'     => $net['key'],
			'label'   => $net['label'],
			'type'    => 'checkbox',
			'default' => 'share_x' === $net['key'] ? '' : '1',
		);
	}

	$article_network_fields = array();
	foreach ( themify_article_networks() as $slug => $net ) {
		$article_network_fields[] = array(
			'key'     => $net['key'],
			'label'   => $net['label'],
			'type'    => 'checkbox',
			'default' => $net['default'] ? '1' : '',
		);
	}

	themify_render_settings_page( array(
		'title' => __( 'Social Sharing', 'themify' ),
		'intro' => __( 'Two share systems: a floating corner button for general pages, and rich in-article sharing on blog posts (buttons under the title, at the foot of the post, and a floating bar down the left side).', 'themify' ),
		'nonce' => 'themify_share',
		'after' => function () {
			if ( function_exists( 'themify_imgshare_render_card' ) ) {
				themify_imgshare_render_card();
			}
		},
		'groups' => array(
			array(
				'title'  => __( 'In-article sharing (blog posts)', 'themify' ),
				'desc'   => __( 'Where the share buttons appear on single posts. The floating bar shows on wide screens; the top and bottom rows always show.', 'themify' ),
				'fields' => array(
					array( 'key' => 'article_share_top', 'label' => __( 'Share row under the title', 'themify' ), 'type' => 'checkbox', 'default' => '1' ),
					array( 'key' => 'article_share_bottom', 'label' => __( 'Share row at the end of the post', 'themify' ), 'type' => 'checkbox', 'default' => '1' ),
					array( 'key' => 'article_share_float', 'label' => __( 'Floating vertical bar on the left (wide screens)', 'themify' ), 'type' => 'checkbox', 'default' => '1' ),
				),
			),
			array(
				'title'  => __( 'In-article networks', 'themify' ),
				'desc'   => __( 'Which buttons appear in the in-article share areas, in order.', 'themify' ),
				'fields' => $article_network_fields,
			),
			array(
				'title'  => __( 'Floating corner button (other pages)', 'themify' ),
				'desc'   => __( 'A share button that expands into social buttons. On single posts it is replaced by the in-article sharing above.', 'themify' ),
				'fields' => array(
					array( 'key' => 'share_enabled', 'label' => __( 'Show the floating share bar', 'themify' ), 'type' => 'checkbox', 'default' => '1' ),
					array(
						'key'     => 'share_position',
						'label'   => __( 'Corner', 'themify' ),
						'type'    => 'select',
						'default' => 'left',
						'options' => array(
							'left'  => __( 'Bottom-left (opposite back-to-top)', 'themify' ),
							'right' => __( 'Bottom-right', 'themify' ),
						),
					),
					array(
						'key'     => 'share_show',
						'label'   => __( 'Show on', 'themify' ),
						'type'    => 'select',
						'default' => 'everywhere',
						'options' => array(
							'everywhere' => __( 'Every page', 'themify' ),
							'singular'   => __( 'Posts & pages only', 'themify' ),
							'posts'      => __( 'Blog posts only', 'themify' ),
						),
					),
				),
			),
			array(
				'title'  => __( 'Corner button networks', 'themify' ),
				'desc'   => __( 'Which buttons the corner share button reveals, top to bottom.', 'themify' ),
				'fields' => $network_fields,
			),
		),
	) );
}
