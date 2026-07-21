<?php
/**
 * Shared helpers used across the whole theme and every module.
 *
 * The most important pieces are the option accessors. All simple scalar
 * settings live inside one wp_options array (THEMIFY_OPT); read them with
 * themify_get_option() and write them with themify_set_option(). This keeps
 * the options table tidy and makes the whole config exportable as one blob.
 *
 * @package Themify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Return the entire settings array (cached per-request in a global so the
 * setters can invalidate it after a write).
 *
 * @return array
 */
function themify_get_options() {
	if ( ! isset( $GLOBALS['themify_options_cache'] ) ) {
		$opts = get_option( THEMIFY_OPT, array() );
		$GLOBALS['themify_options_cache'] = is_array( $opts ) ? $opts : array();
	}
	return $GLOBALS['themify_options_cache'];
}

/**
 * Read a single setting.
 *
 * @param string $key     Setting key.
 * @param mixed  $default Fallback when unset or empty-string.
 * @return mixed
 */
function themify_get_option( $key, $default = '' ) {
	$opts = themify_get_options();
	if ( ! array_key_exists( $key, $opts ) ) {
		return $default;
	}
	$val = $opts[ $key ];
	// Treat empty string as "unset" so a blank field falls back to $default,
	// but preserve explicit 0 / '0' / false which are meaningful values.
	if ( '' === $val || null === $val ) {
		return $default;
	}
	return $val;
}

/**
 * Persist a single setting (read-modify-write of the settings array).
 *
 * @param string $key   Setting key.
 * @param mixed  $value Value to store.
 * @return bool
 */
function themify_set_option( $key, $value ) {
	$opts         = get_option( THEMIFY_OPT, array() );
	if ( ! is_array( $opts ) ) {
		$opts = array();
	}
	$opts[ $key ] = $value;
	$ok           = update_option( THEMIFY_OPT, $opts );
	// Bust the per-request cache so subsequent reads see the new value.
	themify_reset_options_cache();
	return $ok;
}

/**
 * Merge many settings at once (used by settings forms). Values are stored
 * as-is; callers are responsible for sanitizing before calling this.
 *
 * @param array $pairs key => value pairs.
 * @return bool
 */
function themify_set_options( array $pairs ) {
	$opts = get_option( THEMIFY_OPT, array() );
	if ( ! is_array( $opts ) ) {
		$opts = array();
	}
	foreach ( $pairs as $k => $v ) {
		$opts[ $k ] = $v;
	}
	$ok = update_option( THEMIFY_OPT, $opts );
	themify_reset_options_cache();
	return $ok;
}

/**
 * Clear the per-request options cache. Call after any direct update_option()
 * on THEMIFY_OPT that bypasses the setters above; the setters call it for you.
 */
function themify_reset_options_cache() {
	unset( $GLOBALS['themify_options_cache'] );
}

/**
 * Echo an escaped option value inline (convenience for templates).
 *
 * @param string $key     Setting key.
 * @param mixed  $default Fallback.
 */
function themify_option_e( $key, $default = '' ) {
	echo esc_html( themify_get_option( $key, $default ) );
}

/**
 * Boolean-ish reading of a toggle option. Checkboxes store '1' / '' or
 * 'on' / '' depending on the form. Normalise all truthy encodings.
 *
 * @param string $key     Setting key.
 * @param bool   $default Default when unset.
 * @return bool
 */
function themify_is_enabled( $key, $default = false ) {
	$opts = themify_get_options();
	if ( ! array_key_exists( $key, $opts ) ) {
		return (bool) $default;
	}
	$val = $opts[ $key ];
	return in_array( $val, array( '1', 1, true, 'on', 'yes', 'true' ), true );
}

/**
 * The canonical site URL with no trailing slash — used by SEO, sitemap,
 * indexing and rank-tracker modules so they all agree on the domain.
 *
 * @return string
 */
function themify_site_url() {
	return untrailingslashit( home_url() );
}

/**
 * Bare host (no scheme, no www, no path) — SerpAPI / IndexNow want this form.
 *
 * @return string
 */
function themify_site_host() {
	$host = wp_parse_url( home_url(), PHP_URL_HOST );
	return $host ? preg_replace( '/^www\./i', '', $host ) : '';
}

/**
 * Safe wrapper around wp_remote_get/post returning the decoded JSON body or
 * a WP_Error. Centralised so every module gets the same timeout + UA.
 *
 * @param string $url  Endpoint.
 * @param array  $args wp_remote_request args (method, body, headers…).
 * @return array|WP_Error Decoded body on success.
 */
function themify_remote_json( $url, $args = array() ) {
	$defaults = array(
		'timeout'    => 20,
		'user-agent' => 'Themify/' . THEMIFY_VERSION . '; ' . home_url(),
		'headers'    => array( 'Accept' => 'application/json' ),
	);
	$args     = wp_parse_args( $args, $defaults );
	$method   = isset( $args['method'] ) ? strtoupper( $args['method'] ) : 'GET';

	$response = ( 'POST' === $method )
		? wp_remote_post( $url, $args )
		: wp_remote_request( $url, $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}
	$code = wp_remote_retrieve_response_code( $response );
	$body = wp_remote_retrieve_body( $response );
	$json = json_decode( $body, true );

	if ( $code < 200 || $code >= 300 ) {
		return new WP_Error(
			'themify_http_' . $code,
			sprintf( 'HTTP %d from %s', $code, $url ),
			array(
				'status' => $code,
				'body'   => is_array( $json ) ? $json : $body,
			)
		);
	}
	return is_array( $json ) ? $json : array( 'raw' => $body );
}

/**
 * Human-readable "x minutes ago". Thin wrapper for consistency in admin lists.
 *
 * @param string|int $when Timestamp or date string.
 * @return string
 */
function themify_time_ago( $when ) {
	$ts = is_numeric( $when ) ? (int) $when : strtotime( (string) $when );
	if ( ! $ts ) {
		return '—';
	}
	return sprintf(
		/* translators: %s: human time difference */
		__( '%s ago', 'themify' ),
		human_time_diff( $ts, current_time( 'timestamp' ) )
	);
}
