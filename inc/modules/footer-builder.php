<?php
/**
 * Footer builder.
 *
 * Renders the site footer (called by footer.php via themify_render_footer())
 * and provides a "Footer" admin screen to configure it. The footer has three
 * bands:
 *
 *   1. WIDGET COLUMNS — if any of the four footer widget areas (footer-1..4,
 *      registered in setup.php) is active, each active one is printed as a
 *      column inside .tf-footer-widgets.
 *
 *   2. BOTTOM BAR (.tf-footer-bottom):
 *        left  — a copyright line (option 'footer_copyright', with a {year}
 *                token replaced by the current year and a sensible fallback of
 *                "© {year} {site name}. All rights reserved.") plus the footer
 *                nav menu (location 'footer', .tf-footer-menu, one level deep).
 *        right — social icons built from option 'themify_social' (rows of
 *                network + url), each an on-brand round link, and payment
 *                badges built from option 'themify_payment_badges' (image URLs)
 *                as lazy-loaded ~26px-tall images.
 *
 * Everything is escaped on output. The admin screen (custom UI, not the
 * declarative renderer) uses a nonce ('themify_footer') + THEMIFY_CAP and
 * rebuilds themify_social + themify_payment_badges + the footer_copyright
 * option on save. No external HTTP happens here, so the whole thing is safe on
 * a public front-end request.
 *
 * Data model:
 *   - option 'footer_copyright'       — scalar, stored in THEMIFY_OPT.
 *   - option 'themify_social'         — its OWN option: indexed array of
 *                                       array( 'network' => (string), 'url' => (string) ).
 *   - option 'themify_payment_badges' — its OWN option: indexed array of image
 *                                       URL strings.
 *
 * @package Themify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Option name holding the indexed array of social links.
 */
if ( ! defined( 'THEMIFY_SOCIAL_OPT' ) ) {
	define( 'THEMIFY_SOCIAL_OPT', 'themify_social' );
}

/**
 * Option name holding the indexed array of payment badge image URLs.
 */
if ( ! defined( 'THEMIFY_PAYMENT_BADGES_OPT' ) ) {
	define( 'THEMIFY_PAYMENT_BADGES_OPT', 'themify_payment_badges' );
}

/* -------------------------------------------------------------------------
 * DATA ACCESS
 * ---------------------------------------------------------------------- */

/**
 * The fixed list of supported social networks: machine key => human label.
 * Used to validate the network select on save and to label the social links.
 *
 * @return array<string,string>
 */
function themify_social_networks() {
	return array(
		'facebook'  => __( 'Facebook', 'themify' ),
		'x'         => __( 'X (Twitter)', 'themify' ),
		'instagram' => __( 'Instagram', 'themify' ),
		'youtube'   => __( 'YouTube', 'themify' ),
		'linkedin'  => __( 'LinkedIn', 'themify' ),
		'pinterest' => __( 'Pinterest', 'themify' ),
		'tiktok'    => __( 'TikTok', 'themify' ),
		'github'    => __( 'GitHub', 'themify' ),
		'medium'    => __( 'Medium', 'themify' ),
		'whatsapp'  => __( 'WhatsApp', 'themify' ),
		'telegram'  => __( 'Telegram', 'themify' ),
		'discord'   => __( 'Discord', 'themify' ),
		'reddit'    => __( 'Reddit', 'themify' ),
		'twitch'    => __( 'Twitch', 'themify' ),
		'threads'   => __( 'Threads', 'themify' ),
		'snapchat'  => __( 'Snapchat', 'themify' ),
		'email'     => __( 'Email', 'themify' ),
		'rss'       => __( 'RSS', 'themify' ),
		'link'      => __( 'Website / other', 'themify' ),
	);
}

/**
 * Work out which icon best fits a social link from its URL (and the typed name
 * as a hint). Returns a known network key, or 'link' for anything unrecognised
 * so every entry still shows a sensible icon.
 *
 * @param string $url   The profile URL.
 * @param string $label The name the owner typed (optional hint).
 * @return string A network key understood by themify_social_icon().
 */
function themify_detect_social_network( $url, $label = '' ) {
	$url_l = strtolower( trim( (string) $url ) );

	// Host-based matches (most reliable): the URL's host must equal the domain or
	// be a subdomain of it. A plain substring test would wrongly match "x.com"
	// inside "dropbox.com", so we compare against host boundaries.
	$domains = array(
		'facebook'  => array( 'facebook.com', 'fb.com', 'fb.me', 'fb.watch' ),
		'x'         => array( 'twitter.com', 'x.com', 't.co' ),
		'instagram' => array( 'instagram.com', 'instagr.am' ),
		'youtube'   => array( 'youtube.com', 'youtu.be' ),
		'linkedin'  => array( 'linkedin.com', 'lnkd.in' ),
		'pinterest' => array( 'pinterest.com', 'pinterest.co.uk', 'pin.it' ),
		'tiktok'    => array( 'tiktok.com' ),
		'github'    => array( 'github.com' ),
		'medium'    => array( 'medium.com' ),
		'whatsapp'  => array( 'whatsapp.com', 'wa.me' ),
		'telegram'  => array( 't.me', 'telegram.me', 'telegram.org' ),
		'discord'   => array( 'discord.gg', 'discord.com', 'discordapp.com' ),
		'reddit'    => array( 'reddit.com', 'redd.it' ),
		'twitch'    => array( 'twitch.tv' ),
		'threads'   => array( 'threads.net' ),
		'snapchat'  => array( 'snapchat.com' ),
	);

	$host   = '';
	$parsed = wp_parse_url( $url_l );
	if ( is_array( $parsed ) && ! empty( $parsed['host'] ) ) {
		$host = preg_replace( '/^www\./', '', $parsed['host'] );
	}
	if ( '' !== $host ) {
		foreach ( $domains as $key => $list ) {
			foreach ( $list as $domain ) {
				if ( $host === $domain || substr( $host, -strlen( '.' . $domain ) ) === '.' . $domain ) {
					return $key;
				}
			}
		}
	}

	// Scheme / path shaped links.
	if ( 0 === strpos( $url_l, 'mailto:' ) ) {
		return 'email';
	}
	if ( preg_match( '#/(feed|rss)(/|$|\?)|\.xml($|\?)#', $url_l ) ) {
		return 'rss';
	}

	// Last resort: match the typed NAME (not the URL) against a known network's
	// key or label, as a whole word. Word boundaries stop a stray letter — like
	// the "x" in "example.com" or a name such as "Box" — from being mistaken for
	// a one-letter network key like "x".
	$name = strtolower( trim( (string) $label ) );
	if ( '' !== $name ) {
		foreach ( themify_social_networks() as $key => $network_label ) {
			if ( 'link' === $key ) {
				continue;
			}
			foreach ( array( $key, strtolower( $network_label ) ) as $needle ) {
				if ( '' !== $needle && preg_match( '/\b' . preg_quote( $needle, '/' ) . '\b/', $name ) ) {
					return $key;
				}
			}
		}
	}

	return 'link';
}

/**
 * Read the stored social links, normalised into a predictable shape. Each row
 * has a free-text label (the name the owner typed), the sanitized URL and a
 * network key used to pick the icon (auto-detected when it's missing). Any
 * network is allowed — unknown ones fall back to a generic icon. Rows with an
 * empty URL are dropped.
 *
 * @return array<int,array{network:string,label:string,url:string}>
 */
function themify_get_social_links() {
	$raw = get_option( THEMIFY_SOCIAL_OPT, array() );
	if ( ! is_array( $raw ) ) {
		return array();
	}

	$known = themify_social_networks();
	$clean = array();

	foreach ( $raw as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$url = isset( $row['url'] ) ? esc_url_raw( trim( (string) $row['url'] ) ) : '';
		if ( '' === $url ) {
			continue;
		}

		$label   = isset( $row['label'] ) ? sanitize_text_field( (string) $row['label'] ) : '';
		$network = isset( $row['network'] ) ? sanitize_key( $row['network'] ) : '';
		if ( '' === $network || ! isset( $known[ $network ] ) ) {
			$network = themify_detect_social_network( $url, $label );
		}
		if ( '' === $label ) {
			$label = $known[ $network ] ?? ucfirst( $network );
		}

		$clean[] = array(
			'network' => $network,
			'label'   => $label,
			'url'     => $url,
		);
	}

	return $clean;
}

/**
 * Read the stored payment badge image URLs, normalised: each is run through
 * esc_url_raw and empties are dropped.
 *
 * @return string[]
 */
function themify_get_payment_badges() {
	$raw = get_option( THEMIFY_PAYMENT_BADGES_OPT, array() );
	if ( ! is_array( $raw ) ) {
		return array();
	}

	$clean = array();
	foreach ( $raw as $url ) {
		$url = esc_url_raw( trim( (string) $url ) );
		if ( '' !== $url ) {
			$clean[] = $url;
		}
	}

	return $clean;
}

/**
 * Resolve the copyright line: read the option, fall back to a sensible default,
 * then replace the {year} token with the current (site-timezone) year.
 *
 * @return string Copyright text (unescaped — caller escapes on output).
 */
function themify_footer_copyright_text() {
	$default = sprintf(
		/* translators: %s: site name. The literal {year} token is replaced with the current year after translation. */
		__( '&copy; {year} %s. All rights reserved.', 'themify' ),
		get_bloginfo( 'name' )
	);

	$text = (string) themify_get_option( 'footer_copyright', $default );
	if ( '' === trim( $text ) ) {
		$text = $default;
	}

	$year = date_i18n( 'Y' );

	return str_replace( '{year}', $year, $text );
}

/* -------------------------------------------------------------------------
 * SOCIAL ICONS
 * ---------------------------------------------------------------------- */

/**
 * Return an inline SVG icon (or a Dashicons span fallback) for a known network.
 * The SVG uses currentColor so it inherits the link colour set by the CSS.
 *
 * @param string $network Network key (facebook, x, instagram, …).
 * @return string Safe HTML markup for the icon.
 */
function themify_social_icon( $network ) {
	$network = sanitize_key( $network );

	$open = '<svg class="tf-social__icon" width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false">';

	switch ( $network ) {
		case 'facebook':
			$path = '<path d="M22 12.06C22 6.5 17.52 2 12 2S2 6.5 2 12.06c0 5 3.66 9.15 8.44 9.94v-7.03H7.9v-2.91h2.54V9.85c0-2.52 1.49-3.92 3.78-3.92 1.1 0 2.24.2 2.24.2v2.48h-1.26c-1.24 0-1.63.78-1.63 1.57v1.88h2.78l-.44 2.91h-2.34V22c4.78-.79 8.43-4.94 8.43-9.94Z"/>';
			break;
		case 'x':
			$path = '<path d="M18.24 2.25h3.31l-7.23 8.26 8.5 11.24h-6.65l-5.21-6.82-5.97 6.82H1.62l7.73-8.83L1.2 2.25h6.82l4.71 6.23 5.51-6.23Zm-1.16 17.52h1.83L7.01 4.13H5.05l12.03 15.64Z"/>';
			break;
		case 'instagram':
			$path = '<path d="M12 2.16c3.2 0 3.58.01 4.85.07 1.17.05 1.8.25 2.23.41.56.22.96.48 1.38.9.42.42.68.82.9 1.38.16.42.36 1.06.41 2.23.06 1.27.07 1.65.07 4.85s-.01 3.58-.07 4.85c-.05 1.17-.25 1.8-.41 2.23-.22.56-.48.96-.9 1.38-.42.42-.82.68-1.38.9-.42.16-1.06.36-2.23.41-1.27.06-1.65.07-4.85.07s-3.58-.01-4.85-.07c-1.17-.05-1.8-.25-2.23-.41a3.7 3.7 0 0 1-1.38-.9 3.7 3.7 0 0 1-.9-1.38c-.16-.42-.36-1.06-.41-2.23-.06-1.27-.07-1.65-.07-4.85s.01-3.58.07-4.85c.05-1.17.25-1.8.41-2.23.22-.56.48-.96.9-1.38.42-.42.82-.68 1.38-.9.42-.16 1.06-.36 2.23-.41C8.42 2.17 8.8 2.16 12 2.16Zm0 1.62c-3.15 0-3.52.01-4.76.07-1.15.05-1.77.24-2.19.41-.55.21-.94.47-1.35.88-.41.41-.67.8-.88 1.35-.16.42-.36 1.04-.41 2.19-.06 1.24-.07 1.61-.07 4.76s.01 3.52.07 4.76c.05 1.15.24 1.77.41 2.19.21.55.47.94.88 1.35.41.41.8.67 1.35.88.42.16 1.04.36 2.19.41 1.24.06 1.61.07 4.76.07s3.52-.01 4.76-.07c1.15-.05 1.77-.24 2.19-.41.55-.21.94-.47 1.35-.88.41-.41.67-.8.88-1.35.16-.42.36-1.04.41-2.19.06-1.24.07-1.61.07-4.76s-.01-3.52-.07-4.76c-.05-1.15-.24-1.77-.41-2.19a3.6 3.6 0 0 0-.88-1.35 3.6 3.6 0 0 0-1.35-.88c-.42-.16-1.04-.36-2.19-.41-1.24-.06-1.61-.07-4.76-.07Zm0 2.76a5.3 5.3 0 1 1 0 10.6 5.3 5.3 0 0 1 0-10.6Zm0 8.74a3.44 3.44 0 1 0 0-6.88 3.44 3.44 0 0 0 0 6.88Zm6.75-8.93a1.24 1.24 0 1 1-2.48 0 1.24 1.24 0 0 1 2.48 0Z"/>';
			break;
		case 'youtube':
			$path = '<path d="M23.5 6.5a3.02 3.02 0 0 0-2.12-2.14C19.5 3.86 12 3.86 12 3.86s-7.5 0-9.38.5A3.02 3.02 0 0 0 .5 6.5C0 8.38 0 12 0 12s0 3.62.5 5.5a3.02 3.02 0 0 0 2.12 2.14c1.88.5 9.38.5 9.38.5s7.5 0 9.38-.5A3.02 3.02 0 0 0 23.5 17.5C24 15.62 24 12 24 12s0-3.62-.5-5.5ZM9.6 15.57V8.43L15.82 12 9.6 15.57Z"/>';
			break;
		case 'linkedin':
			$path = '<path d="M20.45 20.45h-3.56v-5.57c0-1.33-.02-3.04-1.85-3.04-1.85 0-2.14 1.45-2.14 2.94v5.67H9.35V9h3.41v1.56h.05c.48-.9 1.63-1.85 3.36-1.85 3.6 0 4.27 2.37 4.27 5.45v6.29ZM5.34 7.43a2.07 2.07 0 1 1 0-4.14 2.07 2.07 0 0 1 0 4.14Zm1.78 13.02H3.55V9h3.57v11.45ZM22.22 0H1.77C.8 0 0 .78 0 1.75v20.5C0 23.22.8 24 1.77 24h20.45c.98 0 1.78-.78 1.78-1.75V1.75C24 .78 23.2 0 22.22 0Z"/>';
			break;
		case 'pinterest':
			$path = '<path d="M12 2C6.48 2 2 6.48 2 12c0 4.24 2.64 7.86 6.36 9.32-.09-.79-.17-2 .03-2.86.18-.78 1.18-4.98 1.18-4.98s-.3-.6-.3-1.49c0-1.4.81-2.44 1.82-2.44.86 0 1.27.64 1.27 1.41 0 .86-.55 2.15-.83 3.34-.24 1 .5 1.81 1.48 1.81 1.78 0 3.14-1.87 3.14-4.57 0-2.39-1.72-4.06-4.17-4.06-2.84 0-4.51 2.13-4.51 4.34 0 .86.33 1.78.74 2.28.08.1.09.19.07.29-.08.32-.25 1-.28 1.14-.05.19-.15.23-.35.14-1.28-.6-2.08-2.46-2.08-3.96 0-3.23 2.34-6.19 6.76-6.19 3.55 0 6.31 2.53 6.31 5.91 0 3.53-2.22 6.36-5.31 6.36-1.04 0-2.01-.54-2.34-1.18l-.64 2.43c-.23.89-.85 2-1.27 2.68.96.3 1.97.45 3.03.45 5.52 0 10-4.48 10-10S17.52 2 12 2Z"/>';
			break;
		case 'tiktok':
			$path = '<path d="M16.6 5.82a4.28 4.28 0 0 1-1.02-2.82h-3.3v13.4a2.59 2.59 0 0 1-2.58 2.5 2.6 2.6 0 0 1-.55-5.13V10.4a5.87 5.87 0 0 0-5.13 5.82A5.86 5.86 0 0 0 9.7 22a5.86 5.86 0 0 0 5.87-5.85V9.01a7.55 7.55 0 0 0 4.43 1.42V7.13a4.29 4.29 0 0 1-3.4-1.31Z"/>';
			break;
		case 'github':
			$path = '<path d="M12 2a10 10 0 0 0-3.16 19.49c.5.09.68-.22.68-.48v-1.7c-2.78.6-3.37-1.34-3.37-1.34-.45-1.16-1.11-1.47-1.11-1.47-.91-.62.07-.6.07-.6 1 .07 1.53 1.03 1.53 1.03.9 1.53 2.36 1.09 2.94.83.09-.65.35-1.09.63-1.34-2.22-.25-4.55-1.11-4.55-4.94 0-1.09.39-1.98 1.03-2.68-.1-.25-.45-1.27.1-2.65 0 0 .84-.27 2.75 1.02a9.56 9.56 0 0 1 5 0c1.91-1.29 2.75-1.02 2.75-1.02.55 1.38.2 2.4.1 2.65.64.7 1.03 1.59 1.03 2.68 0 3.84-2.34 4.68-4.57 4.93.36.31.68.92.68 1.85v2.74c0 .27.18.58.69.48A10 10 0 0 0 12 2Z"/>';
			break;
		case 'rss':
			$path = '<path d="M6.18 15.64a2.18 2.18 0 1 1 0 4.36 2.18 2.18 0 0 1 0-4.36ZM4 4.44v3.06c6.9 0 12.5 5.6 12.5 12.5h3.06C19.56 11.4 12.6 4.44 4 4.44Zm0 5.66v3.06A6.78 6.78 0 0 1 10.78 20h3.06A9.84 9.84 0 0 0 4 10.1Z"/>';
			break;
		case 'medium':
			$path = '<path d="M13.54 12a6.8 6.8 0 0 1-6.77 6.82A6.8 6.8 0 0 1 0 12a6.8 6.8 0 0 1 6.77-6.82A6.8 6.8 0 0 1 13.54 12Zm7.42 0c0 3.54-1.51 6.42-3.38 6.42-1.87 0-3.39-2.88-3.39-6.42s1.52-6.42 3.39-6.42S20.96 8.46 20.96 12ZM24 12c0 3.17-.53 5.75-1.19 5.75s-1.19-2.58-1.19-5.75.53-5.75 1.19-5.75S24 8.83 24 12Z"/>';
			break;
		case 'whatsapp':
			$path = '<path d="M17.5 14.4c-.3-.15-1.77-.87-2.04-.97-.27-.1-.47-.15-.67.15-.2.3-.77.97-.94 1.17-.17.2-.35.22-.65.07-.3-.15-1.26-.46-2.4-1.48-.89-.79-1.49-1.77-1.66-2.07-.17-.3-.02-.46.13-.61.13-.13.3-.35.45-.52.15-.17.2-.3.3-.5.1-.2.05-.37-.02-.52-.07-.15-.67-1.62-.92-2.22-.24-.58-.49-.5-.67-.51h-.57c-.2 0-.52.07-.8.37-.27.3-1.04 1.02-1.04 2.48 0 1.46 1.07 2.88 1.22 3.08.15.2 2.1 3.2 5.08 4.49.71.31 1.26.49 1.69.62.71.23 1.36.2 1.87.12.57-.09 1.77-.72 2.02-1.42.25-.7.25-1.29.17-1.42-.07-.13-.27-.2-.57-.35Zm-5.45 7.1h-.01a9.4 9.4 0 0 1-4.79-1.31l-.34-.2-3.56.93.95-3.47-.22-.36a9.38 9.38 0 0 1-1.44-5.01c0-5.18 4.22-9.4 9.41-9.4 2.51 0 4.87.98 6.65 2.76a9.35 9.35 0 0 1 2.75 6.65c0 5.18-4.22 9.4-9.4 9.4Zm8-17.4A11.32 11.32 0 0 0 12.04.75C5.8.75.72 5.83.72 12.06c0 1.99.52 3.94 1.51 5.65L.63 23.25l5.67-1.49a11.28 11.28 0 0 0 5.74 1.47h.01c6.23 0 11.31-5.08 11.31-11.31 0-3.02-1.18-5.86-3.31-8Z"/>';
			break;
		case 'telegram':
			$path = '<path d="M22.05 2.3 2.6 9.8c-1.33.53-1.32 1.28-.24 1.61l4.99 1.56 1.93 6.06c.23.63.11.88.78.88.51 0 .74-.24 1.02-.52l2.46-2.39 5.12 3.78c.94.52 1.62.25 1.86-.87l3.37-15.88c.34-1.37-.5-2.02-1.77-1.44Zm-4.36 3.7-8.84 8.01-.35 3.72-1.85-5.78 11.04-6.14c.51-.31.98-.14.6.19Z"/>';
			break;
		case 'discord':
			$path = '<path d="M20.32 4.37A19.8 19.8 0 0 0 15.4 2.9c-.24.42-.44.85-.63 1.28a18.3 18.3 0 0 0-5.5 0C9.08 3.75 8.87 3.32 8.63 2.9a19.7 19.7 0 0 0-4.93 1.47C.57 9.05-.28 13.6.14 18.1a19.9 19.9 0 0 0 6.06 3.06c.49-.66.92-1.36 1.29-2.1-.7-.26-1.38-.59-2.03-.97.17-.12.34-.25.5-.38a14.2 14.2 0 0 0 12.08 0c.16.14.33.26.5.38-.65.38-1.33.71-2.04.98.37.73.8 1.43 1.29 2.09a19.8 19.8 0 0 0 6.07-3.06c.5-5.22-.85-9.73-3.56-13.74ZM8.02 15.33c-1.18 0-2.15-1.08-2.15-2.41 0-1.33.95-2.42 2.15-2.42 1.2 0 2.17 1.09 2.15 2.42 0 1.33-.95 2.41-2.15 2.41Zm7.96 0c-1.18 0-2.15-1.08-2.15-2.41 0-1.33.95-2.42 2.15-2.42 1.2 0 2.17 1.09 2.15 2.42 0 1.33-.94 2.41-2.15 2.41Z"/>';
			break;
		case 'reddit':
			$path = '<path d="M24 11.78a2.34 2.34 0 0 0-3.96-1.68 11.5 11.5 0 0 0-6.24-1.97l1.06-4.98 3.47.74a1.67 1.67 0 1 0 .17-1.02l-3.87-.82a.51.51 0 0 0-.6.39l-1.18 5.56a11.55 11.55 0 0 0-6.33 1.98 2.34 2.34 0 1 0-2.6 3.87 4.6 4.6 0 0 0-.06.72c0 3.64 4.24 6.6 9.46 6.6 5.23 0 9.47-2.96 9.47-6.6a4.6 4.6 0 0 0-.05-.7A2.35 2.35 0 0 0 24 11.78Zm-16.5 1.67a1.67 1.67 0 1 1 3.34 0 1.67 1.67 0 0 1-3.34 0Zm9.34 4.42c-1.14 1.14-3.32 1.23-3.96 1.23-.64 0-2.82-.09-3.96-1.23a.43.43 0 0 1 .61-.61c.72.72 2.26.98 3.35.98s2.63-.26 3.35-.98a.43.43 0 1 1 .61.61Zm-.29-2.75a1.67 1.67 0 1 1 0-3.34 1.67 1.67 0 0 1 0 3.34Z"/>';
			break;
		case 'twitch':
			$path = '<path d="M4.27 0 1 3.27v17.46h5.82V24h3.27l3.27-3.27h4.9L23.73 15V0H4.27Zm17.28 14.18-3.27 3.28h-5.82l-3.27 3.27v-3.27H4.9V1.64h16.65v12.54Zm-4.9-8.18v5.45h-1.63V6h1.63Zm-4.9 0v5.45h-1.64V6h1.64Z"/>';
			break;
		case 'threads':
			$path = '<path d="M16.5 11.15c-.08-.04-.17-.08-.25-.11-.15-2.7-1.62-4.24-4.1-4.26h-.03c-1.48 0-2.72.63-3.48 1.79l1.36.93c.57-.86 1.46-1.05 2.12-1.05h.02c.82 0 1.44.24 1.84.71.29.34.48.82.58 1.42-.72-.12-1.5-.16-2.33-.11-2.34.13-3.84 1.5-3.74 3.39.05.96.53 1.78 1.34 2.32.69.45 1.57.67 2.49.62 1.22-.07 2.17-.53 2.84-1.38.5-.64.82-1.47.96-2.51.58.35 1.01.81 1.25 1.37.4.95.43 2.5-.84 3.77-1.11 1.11-2.45 1.59-4.47 1.6-2.24-.02-3.94-.74-5.04-2.15C4.5 15.5 3.97 13.6 3.95 12v-.02c.02-1.6.55-3.5 1.58-4.81 1.1-1.4 2.8-2.13 5.04-2.14 2.25.02 3.98.75 5.13 2.17.57.7.99 1.58 1.28 2.6l1.6-.43c-.34-1.25-.88-2.34-1.61-3.24C17.1 3.9 14.94 2.97 12.17 2.95h-.01c-2.77.02-4.9.95-6.34 2.77C4.55 7.35 3.9 9.65 3.88 11.98v.02c.02 2.08.67 4.63 2.05 6.38 1.44 1.82 3.57 2.75 6.34 2.77h.01c2.46-.02 4.2-.66 5.62-2.08 1.87-1.87 1.81-4.21 1.2-5.65-.44-1.03-1.28-1.87-2.42-2.42-.06-.03-.12-.06-.18-.08Z"/>';
			break;
		case 'snapchat':
			$path = '<path d="M12.2 2.2c.33 0 2.93.02 4.31 3.1.46 1.03.35 2.79.26 4.19l-.04.65c.08.05.26.13.58.13.42 0 .93-.13 1.5-.4.09-.04.2-.06.32-.06.43 0 .97.29 1.06.73.07.35-.09.86-1.24 1.32-.1.04-.22.08-.35.12-.44.14-1.1.34-1.28.76-.09.23-.05.5.12.85l.01.02c.06.13 1.5 3.13 4.42 3.61a.47.47 0 0 1 .38.46.5.5 0 0 1-.05.2c-.24.57-1.26.98-3.12 1.27-.06.08-.12.35-.16.53-.04.17-.09.36-.14.55-.05.17-.18.37-.52.37-.15 0-.34-.03-.55-.07-.33-.07-.8-.16-1.4-.16-.34 0-.69.03-1.02.09-.67.12-1.22.51-1.87.97-.92.64-1.96 1.38-3.55 1.38h-.24c-1.59 0-2.64-.74-3.56-1.39-.64-.45-1.2-.85-1.86-.96a6.2 6.2 0 0 0-1.02-.09c-.57 0-1 .08-1.4.16-.22.05-.4.07-.53.07h-.03c-.26 0-.43-.12-.5-.36-.06-.19-.1-.37-.14-.55-.04-.17-.1-.45-.16-.53-1.86-.29-2.88-.7-3.12-1.26a.5.5 0 0 1-.05-.2c-.01-.23.15-.43.38-.47 2.92-.48 4.36-3.48 4.42-3.61l.01-.01c.17-.35.21-.63.12-.85-.18-.42-.84-.63-1.28-.77-.13-.04-.25-.08-.35-.12-.86-.34-1.28-.73-1.28-1.19.01-.36.27-.67.69-.82.16-.06.34-.1.53-.1.18 0 .35.03.5.09.53.25 1 .38 1.4.4.27 0 .45-.07.55-.13l-.05-.8c-.09-1.4-.2-3.15.26-4.18C9.27 2.22 11.87 2.2 12.2 2.2Z"/>';
			break;
		case 'email':
			$path = '<path d="M2 4h20a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1H2a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1Zm10 7.5L4 6.2V18h16V6.2l-8 5.3ZM4.4 5l7.6 5 7.6-5H4.4Z"/>';
			break;
		case 'link':
		default:
			// Unknown network → a neutral globe icon (front-end safe, no dashicons dependency).
			$path = '<path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm6.93 6h-2.95a15.7 15.7 0 0 0-1.38-3.56A8.03 8.03 0 0 1 18.93 8ZM12 4.04c.83 1.2 1.48 2.53 1.91 3.96h-3.82c.43-1.43 1.08-2.76 1.91-3.96ZM4.26 14a7.96 7.96 0 0 1 0-4h3.38a16.6 16.6 0 0 0 0 4H4.26Zm.81 2h2.95c.32 1.25.78 2.45 1.38 3.56A8 8 0 0 1 5.07 16Zm2.95-8H5.07a8 8 0 0 1 4.33-3.56A15.7 15.7 0 0 0 8.02 8ZM12 19.96A13.4 13.4 0 0 1 10.09 16h3.82A13.4 13.4 0 0 1 12 19.96ZM14.34 14H9.66a14.8 14.8 0 0 1 0-4h4.68a14.8 14.8 0 0 1 0 4Zm.26 5.56c.6-1.11 1.06-2.31 1.38-3.56h2.95a8 8 0 0 1-4.33 3.56ZM16.36 14a16.6 16.6 0 0 0 0-4h3.38a7.96 7.96 0 0 1 0 4h-3.38Z"/>';
			break;
	}

	return $open . $path . '</svg>';
}

/* -------------------------------------------------------------------------
 * FRONT-END RENDER
 * ---------------------------------------------------------------------- */

/**
 * Render the entire site footer. Called by footer.php (guarded by
 * function_exists). Emits the widget columns (when any footer area is active),
 * then the bottom bar with copyright + footer menu on the left and social icons
 * + payment badges on the right. Everything is escaped.
 */
function themify_render_footer() {
	echo '<footer class="tf-site-footer">';
	echo '<div class="tf-container">';

	themify_render_footer_widgets();
	themify_render_footer_bottom();

	echo '</div>'; // .tf-container
	echo '</footer>';
}

/**
 * Print the footer widget columns band, but only if at least one of the four
 * footer widget areas has widgets. Each active area is wrapped in a column div.
 */
function themify_render_footer_widgets() {
	// Gather the columns that actually have something to show — the theme's
	// "Follow With Us" social column (when links exist) and each active footer
	// widget area — then order them by the numbers set on the Footer screen so
	// the site owner controls which column comes before which.
	$columns = array();
	$seq     = 0; // Preserves declaration order as a stable tie-breaker.

	if ( ! empty( themify_get_social_links() ) ) {
		$columns[] = array(
			'order'  => (int) themify_get_option( 'footer_order_social', 1 ),
			'seq'    => $seq++,
			'social' => true,
		);
	}

	for ( $i = 1; $i <= 4; $i++ ) {
		if ( is_active_sidebar( 'footer-' . $i ) ) {
			$columns[] = array(
				'order'  => (int) themify_get_option( 'footer_order_area' . $i, $i + 1 ),
				'seq'    => $seq++,
				'social' => false,
				'area'   => $i,
			);
		}
	}

	if ( empty( $columns ) ) {
		return;
	}

	// Sort by the chosen order; equal orders keep their natural order (usort is
	// not guaranteed stable on older PHP, so tie-break on the sequence index).
	usort( $columns, function ( $a, $b ) {
		return $a['order'] === $b['order'] ? ( $a['seq'] <=> $b['seq'] ) : ( $a['order'] <=> $b['order'] );
	} );

	echo '<div class="tf-footer-widgets">';
	foreach ( $columns as $col ) {
		if ( ! empty( $col['social'] ) ) {
			themify_render_footer_follow_column();
		} else {
			echo '<div class="tf-footer-col">';
			dynamic_sidebar( 'footer-' . $col['area'] );
			echo '</div>';
		}
	}
	echo '</div>'; // .tf-footer-widgets
}

/**
 * A short, human-friendly hint of what's in a footer widget area — the titles of
 * the widgets it holds (falling back to the widget type). Used to label the
 * order controls so "Footer area 2" reads as e.g. "Categorys" to the owner.
 *
 * @param int $index Footer area number (1–4).
 * @return string Comma-separated widget titles, or '' when the area is empty.
 */
function themify_footer_area_widget_titles( $index ) {
	$sidebars = wp_get_sidebars_widgets();
	$ids      = isset( $sidebars[ 'footer-' . $index ] ) && is_array( $sidebars[ 'footer-' . $index ] )
		? $sidebars[ 'footer-' . $index ]
		: array();

	$titles = array();
	foreach ( $ids as $widget_id ) {
		if ( ! preg_match( '/^(.+)-(\d+)$/', (string) $widget_id, $m ) ) {
			continue;
		}
		$base      = $m[1];
		$number    = (int) $m[2];
		$instances = get_option( 'widget_' . $base );
		if ( is_array( $instances ) && isset( $instances[ $number ]['title'] ) && '' !== trim( (string) $instances[ $number ]['title'] ) ) {
			$titles[] = $instances[ $number ]['title'];
		} else {
			$titles[] = ucwords( str_replace( array( '_', '-' ), ' ', $base ) );
		}
	}

	return implode( ', ', array_slice( $titles, 0, 3 ) );
}

/**
 * Print the bottom bar: copyright + footer menu (left) and social + payment
 * badges (right).
 */
function themify_render_footer_bottom() {
	echo '<div class="tf-footer-bottom">';

	// ---- Left: copyright + footer nav menu. ----
	echo '<div class="tf-footer-bottom__left">';

	printf(
		'<div class="tf-copyright">%s</div>',
		wp_kses_post( themify_footer_copyright_text() )
	);

	echo '</div>'; // .tf-footer-bottom__left

	// ---- Right: social icons + payment badges. ----
	echo '<div class="tf-footer-bottom__right">';

	// Skip the icons here if the "Follow With Us" column already showed them, so
	// the same links never appear twice in the footer.
	if ( empty( $GLOBALS['themify_follow_rendered'] ) ) {
		themify_render_social_links();
	}
	themify_render_payment_badges();

	echo '</div>'; // .tf-footer-bottom__right

	echo '</div>'; // .tf-footer-bottom
}

/**
 * Print the social icon links (nothing when none are configured). Each link
 * opens its URL, carries an aria-label with the network name and holds the
 * inline SVG icon.
 */
function themify_render_social_links( $extra_class = '' ) {
	$links = themify_get_social_links();
	if ( empty( $links ) ) {
		return;
	}

	$class = 'tf-social' . ( '' !== $extra_class ? ' ' . $extra_class : '' );
	printf( '<div class="%s">', esc_attr( $class ) );
	foreach ( $links as $link ) {
		$label = '' !== $link['label'] ? $link['label'] : ucfirst( $link['network'] );
		printf(
			'<a href="%s" class="tf-social__link" aria-label="%s" title="%s" rel="noopener" target="_blank">%s</a>',
			esc_url( $link['url'] ),
			esc_attr( $label ),
			esc_attr( $label ),
			themify_social_icon( $link['network'] ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted inline SVG markup built in-house.
		);
	}
	echo '</div>'; // .tf-social
}

/**
 * Render the "Follow With Us" footer column: a heading plus the social icons in
 * a 3-per-row grid. Sets a flag so the bottom bar doesn't repeat the same icons.
 * Shows nothing when no social links are configured.
 */
function themify_render_footer_follow_column() {
	$links = themify_get_social_links();
	if ( empty( $links ) ) {
		return;
	}
	$GLOBALS['themify_follow_rendered'] = true;

	$heading = (string) themify_get_option( 'footer_social_heading', __( 'Follow With Us', 'themify' ) );

	echo '<div class="tf-footer-col tf-footer-follow">';
	if ( '' !== trim( $heading ) ) {
		echo '<h2 class="tf-footer-col__title">' . esc_html( $heading ) . '</h2>';
	}
	themify_render_social_links( 'tf-social--grid' );
	echo '</div>';
}

/**
 * Print the payment badge images (nothing when none are configured). Images are
 * lazy-loaded and constrained to ~26px tall to match the CSS.
 */
function themify_render_payment_badges() {
	$badges = themify_get_payment_badges();
	if ( empty( $badges ) ) {
		return;
	}

	echo '<div class="tf-payment-badges">';
	foreach ( $badges as $url ) {
		$src = esc_url( $url );
		// A stored URL can survive the getter yet still escape to '' (bad
		// scheme etc.) — never print a broken, unsized <img src="">.
		if ( '' === $src ) {
			continue;
		}
		printf(
			'<img src="%s" alt="%s" width="40" height="26" style="width:auto;" loading="lazy" decoding="async" />',
			$src, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_url'd above.
			esc_attr__( 'Accepted payment method', 'themify' )
		);
	}
	echo '</div>'; // .tf-payment-badges
}

/* -------------------------------------------------------------------------
 * ADMIN PAGE
 * ---------------------------------------------------------------------- */

/**
 * Register the "Footer" submenu (position 55).
 */
themify_register_admin_page( array(
	'slug'       => 'themify-footer',
	'title'      => __( 'Footer', 'themify' ),
	'menu_title' => __( 'Footer', 'themify' ),
	'callback'   => 'themify_footer_page',
	'position'   => 50,
) );

/**
 * Add the footer card to the dashboard grid.
 */
add_filter( 'themify_dashboard_cards', 'themify_footer_dashboard_card' );

/**
 * Append the "Footer" dashboard card.
 *
 * @param array $cards Existing dashboard cards.
 * @return array
 */
function themify_footer_dashboard_card( $cards ) {
	$cards[] = array(
		'slug'     => 'themify-footer',
		'title'    => __( 'Footer', 'themify' ),
		'desc'     => __( 'Copyright, social links & payment badges', 'themify' ),
		'icon'     => 'dashicons-align-center',
		'position' => 50,
	);
	return $cards;
}

/**
 * Handle a POST save of the Footer screen: verify nonce + cap, then rebuild the
 * footer_copyright option, the themify_social list and the themify_payment_badges
 * list from the posted fields. Every value is sanitized before storing.
 *
 * @return bool True when a valid save happened (so the caller can show a notice).
 */
function themify_footer_handle_save() {
	if ( ! themify_verify_save( 'themify_footer' ) ) {
		return false;
	}

	// ---- Copyright + "Follow" heading (scalars, in THEMIFY_OPT). ----
	$copyright = isset( $_POST['footer_copyright'] )
		? sanitize_text_field( wp_unslash( $_POST['footer_copyright'] ) )
		: '';
	themify_set_option( 'footer_copyright', $copyright );

	$social_heading = isset( $_POST['footer_social_heading'] )
		? sanitize_text_field( wp_unslash( $_POST['footer_social_heading'] ) )
		: '';
	themify_set_option( 'footer_social_heading', $social_heading );

	// ---- Footer column order (lower number = further left / first). ----
	$orders = array(
		'footer_order_social' => isset( $_POST['footer_order_social'] ) ? max( 0, (int) $_POST['footer_order_social'] ) : 1,
	);
	for ( $i = 1; $i <= 4; $i++ ) {
		$k            = 'footer_order_area' . $i;
		$orders[ $k ] = isset( $_POST[ $k ] ) ? max( 0, (int) $_POST[ $k ] ) : $i + 1;
	}
	themify_set_options( $orders );

	// ---- Social links (own option): free Name + URL, icon auto-detected. ----
	$social_rows = isset( $_POST['themify_social'] ) && is_array( $_POST['themify_social'] )
		? wp_unslash( $_POST['themify_social'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each field sanitized individually below.
		: array();

	$social = array();
	foreach ( $social_rows as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$url = isset( $row['url'] ) ? esc_url_raw( trim( (string) $row['url'] ) ) : '';

		// Drop rows without a URL (a name alone can't be a link).
		if ( '' === $url ) {
			continue;
		}

		$label = isset( $row['label'] ) ? sanitize_text_field( $row['label'] ) : '';

		$social[] = array(
			'network' => themify_detect_social_network( $url, $label ),
			'label'   => $label,
			'url'     => $url,
		);
	}
	update_option( THEMIFY_SOCIAL_OPT, array_values( $social ) );

	// ---- Payment badges (own option). ----
	$badge_rows = isset( $_POST['themify_payment_badges'] ) && is_array( $_POST['themify_payment_badges'] )
		? wp_unslash( $_POST['themify_payment_badges'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized with esc_url_raw below.
		: array();

	$badges = array();
	foreach ( $badge_rows as $url ) {
		$url = esc_url_raw( trim( (string) $url ) );
		if ( '' !== $url ) {
			$badges[] = $url;
		}
	}
	update_option( THEMIFY_PAYMENT_BADGES_OPT, array_values( $badges ) );

	return true;
}

/**
 * Render one social-link row of the repeater.
 *
 * @param int|string $index Row index (numeric for real rows, '__INDEX__' for
 *                          the JS template).
 * @param array      $row   Row data (empty defaults for the template).
 */
function themify_footer_render_social_row( $index, array $row = array() ) {
	$row = wp_parse_args( $row, array(
		'label'   => '',
		'url'     => '',
	) );

	$base = 'themify_social[' . $index . ']';

	echo '<div class="tf-repeater__row">';

	// Name (free text — the icon is picked automatically from the link).
	echo '<div class="tf-field tf-field--text" style="margin-bottom:0;">';
	printf( '<label class="tf-field__label">%s</label>', esc_html__( 'Name', 'themify' ) );
	printf(
		'<input type="text" name="%s[label]" value="%s" class="tf-input" placeholder="%s" />',
		esc_attr( $base ),
		esc_attr( $row['label'] ),
		esc_attr__( 'e.g. YouTube, Facebook, Medium…', 'themify' )
	);
	echo '</div>';

	// URL.
	echo '<div class="tf-field tf-field--url" style="margin-bottom:0;">';
	printf( '<label class="tf-field__label">%s</label>', esc_html__( 'Link', 'themify' ) );
	printf(
		'<input type="url" name="%s[url]" value="%s" class="tf-input" placeholder="%s" />',
		esc_attr( $base ),
		esc_attr( $row['url'] ),
		esc_attr__( 'https://…', 'themify' )
	);
	echo '</div>';

	// Remove control.
	echo '<p style="margin:0;"><a href="#" class="tf-remove">' . esc_html__( 'Remove', 'themify' ) . '</a></p>';

	echo '</div>'; // .tf-repeater__row
}

/**
 * Render one payment-badge row of the repeater (a media picker for the image
 * URL).
 *
 * @param int|string $index Row index (numeric for real rows, '__INDEX__' for
 *                          the JS template).
 * @param string     $url   Stored image URL (empty for the template).
 */
function themify_footer_render_badge_row( $index, $url = '' ) {
	$name = 'themify_payment_badges[' . $index . ']';

	echo '<div class="tf-repeater__row">';

	echo '<div class="tf-field tf-field--media" style="margin-bottom:0;">';
	printf( '<label class="tf-field__label">%s</label>', esc_html__( 'Badge image', 'themify' ) );
	echo '<span class="tf-media">';
	printf(
		'<input type="text" name="%s" value="%s" class="tf-input tf-media__url" placeholder="%s" />',
		esc_attr( $name ),
		esc_attr( $url ),
		esc_attr__( 'https://…/visa.svg', 'themify' )
	);
	printf(
		'<button type="button" class="button tf-media__pick">%s</button>',
		esc_html__( 'Choose', 'themify' )
	);
	echo '</span>';
	echo '</div>';

	echo '<p style="margin:0;"><a href="#" class="tf-remove">' . esc_html__( 'Remove', 'themify' ) . '</a></p>';

	echo '</div>'; // .tf-repeater__row
}

/**
 * Render the "Footer" admin screen (custom UI).
 */
function themify_footer_page() {
	$saved = themify_footer_handle_save();

	themify_admin_header(
		__( 'Footer', 'themify' ),
		__( 'Control the footer copyright line, your social profile links and the payment badges shown in the footer bar.', 'themify' )
	);

	if ( $saved ) {
		echo '<div class="tf-notice tf-notice--info">' . esc_html__( 'Footer settings saved.', 'themify' ) . '</div>';
	}

	$copyright      = themify_get_option( 'footer_copyright', '' );
	$social_heading = themify_get_option( 'footer_social_heading', __( 'Follow With Us', 'themify' ) );
	$social         = themify_get_social_links();
	$badges         = themify_get_payment_badges();

	echo '<form method="post" class="tf-form">';
	wp_nonce_field( 'themify_footer', 'themify_nonce' );

	/* ---- Copyright card ---- */
	echo '<div class="tf-card">';
	echo '<h2 class="tf-card__title">' . esc_html__( 'Copyright', 'themify' ) . '</h2>';

	echo '<div class="tf-field tf-field--text">';
	printf( '<label class="tf-field__label" for="tf_footer_copyright">%s</label>', esc_html__( 'Copyright line', 'themify' ) );
	printf(
		'<input type="text" id="tf_footer_copyright" name="footer_copyright" value="%s" class="tf-input" placeholder="%s" />',
		esc_attr( $copyright ),
		esc_attr__( '&copy; {year} Your Site. All rights reserved.', 'themify' )
	);
	echo '<p class="tf-field__desc">' . wp_kses(
		__( 'Use the <code>{year}</code> token to insert the current year automatically. Leave blank to use the default line.', 'themify' ),
		array( 'code' => array() )
	) . '</p>';
	echo '</div>';

	echo '</div>'; // .tf-card

	/* ---- Social links repeater ---- */
	echo '<div class="tf-card">';
	echo '<h2 class="tf-card__title">' . esc_html__( 'Social links (Follow With Us)', 'themify' ) . '</h2>';
	echo '<p class="tf-card__desc">' . esc_html__( 'Add any social profile — just type a name and paste the link. The matching icon is picked automatically (and a neutral icon is used for anything unusual). These show in the footer under the heading below, 3 per row, and anywhere else the theme shows your social icons.', 'themify' ) . '</p>';

	// Heading shown above the icons in the footer column.
	echo '<div class="tf-field tf-field--text">';
	printf( '<label class="tf-field__label" for="tf_footer_social_heading">%s</label>', esc_html__( 'Heading', 'themify' ) );
	printf(
		'<input type="text" id="tf_footer_social_heading" name="footer_social_heading" value="%s" class="tf-input" placeholder="%s" />',
		esc_attr( $social_heading ),
		esc_attr__( 'Follow With Us', 'themify' )
	);
	echo '</div>';

	echo '<div class="tf-repeater">';

	echo '<script type="text/html" class="tf-repeater__template">';
	themify_footer_render_social_row( '__INDEX__' );
	echo '</script>';

	echo '<div class="tf-repeater__rows">';
	if ( $social ) {
		foreach ( $social as $i => $row ) {
			themify_footer_render_social_row( $i, $row );
		}
	}
	echo '</div>';

	echo '<p><button type="button" class="button tf-repeater__add">' . esc_html__( '+ Add social link', 'themify' ) . '</button></p>';
	echo '</div>'; // .tf-repeater

	echo '</div>'; // .tf-card

	/* ---- Footer column order ---- */
	echo '<div class="tf-card">';
	echo '<h2 class="tf-card__title">' . esc_html__( 'Footer column order', 'themify' ) . '</h2>';
	echo '<p class="tf-card__desc">' . esc_html__( 'Set the left-to-right order of the footer columns. Lower numbers come first (left). This covers the “Follow With Us” column and each footer widget block below.', 'themify' ) . '</p>';

	// "Follow With Us" (social) column order.
	echo '<div class="tf-field tf-field--number">';
	printf( '<label class="tf-field__label" for="tf_footer_order_social">%s</label>', esc_html__( 'Follow With Us (social icons)', 'themify' ) );
	printf(
		'<input type="number" id="tf_footer_order_social" name="footer_order_social" value="%d" class="tf-input" step="1" min="0" style="max-width:140px;" />',
		(int) themify_get_option( 'footer_order_social', 1 )
	);
	echo '</div>';

	// One order field per footer widget area, labelled with what it holds.
	for ( $i = 1; $i <= 4; $i++ ) {
		$order = (int) themify_get_option( 'footer_order_area' . $i, $i + 1 );
		$hint  = themify_footer_area_widget_titles( $i );

		echo '<div class="tf-field tf-field--number">';
		printf(
			'<label class="tf-field__label" for="tf_footer_order_area%1$d">%2$s</label>',
			$i,
			/* translators: %d: footer widget area number */
			esc_html( sprintf( __( 'Footer widget block %d', 'themify' ), $i ) )
		);
		printf(
			'<input type="number" id="tf_footer_order_area%1$d" name="footer_order_area%1$d" value="%2$d" class="tf-input" step="1" min="0" style="max-width:140px;" />',
			$i,
			$order
		);
		if ( '' !== $hint ) {
			/* translators: %s: comma-separated widget titles */
			printf( '<p class="tf-field__desc">%s</p>', esc_html( sprintf( __( 'Contains: %s', 'themify' ), $hint ) ) );
		} else {
			/* translators: %d: footer widget area number */
			printf( '<p class="tf-field__desc">%s</p>', esc_html( sprintf( __( '(empty — add widgets under Appearance → Widgets → “Footer %d”)', 'themify' ), $i ) ) );
		}
		echo '</div>';
	}

	echo '<p class="tf-field__desc">' . wp_kses_post( __( 'The <strong>Legal Pages</strong>, <strong>Categorys</strong> and <strong>TMA Disclaimer</strong> columns are footer widget blocks — change what’s inside them under <em>Appearance → Widgets</em>.', 'themify' ) ) . '</p>';

	echo '</div>'; // .tf-card

	/* ---- Payment badges repeater ---- */
	echo '<div class="tf-card">';
	echo '<h2 class="tf-card__title">' . esc_html__( 'Payment badges', 'themify' ) . '</h2>';
	echo '<p class="tf-card__desc">' . esc_html__( 'Upload or link to small payment / trust badge images (Visa, PayPal, etc). They appear at about 26px tall in the footer.', 'themify' ) . '</p>';

	echo '<div class="tf-repeater">';

	echo '<script type="text/html" class="tf-repeater__template">';
	themify_footer_render_badge_row( '__INDEX__' );
	echo '</script>';

	echo '<div class="tf-repeater__rows">';
	if ( $badges ) {
		foreach ( $badges as $i => $url ) {
			themify_footer_render_badge_row( $i, $url );
		}
	}
	echo '</div>';

	echo '<p><button type="button" class="button tf-repeater__add">' . esc_html__( '+ Add payment badge', 'themify' ) . '</button></p>';
	echo '</div>'; // .tf-repeater

	echo '</div>'; // .tf-card

	echo '<p class="tf-form__actions"><button type="submit" class="button button-primary button-hero">' . esc_html__( 'Save Changes', 'themify' ) . '</button></p>';
	echo '</form>';

	themify_admin_footer();
}
