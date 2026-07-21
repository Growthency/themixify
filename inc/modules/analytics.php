<?php
/**
 * Analytics module — Google Analytics 4 injection + a GA4 / Search Console
 * reporting dashboard.
 *
 * This mirrors the reference lib/google-analytics.ts, re-implemented in PHP:
 *
 *   PART 1 — Injection (front end):
 *     When both `ga4_id` and `ga4_enabled` are set we print the standard async
 *     gtag.js loader + inline init on wp_head. performance.php's defer filter
 *     takes care of adding `defer` to the external script tag.
 *
 *   PART 2 — Dashboard + settings (admin only):
 *     An "Analytics" admin page shows GA4 KPI stat cards, a "Top pages" table
 *     and a "Top search queries" table from Search Console, plus the settings
 *     needed to talk to Google's APIs via a service account.
 *
 * SECURITY / PERFORMANCE POSTURE
 *   - EVERY remote Google API call happens only in an admin/AJAX context. The
 *     front end never touches the network; it only ever prints the (cached-by-
 *     the-browser) gtag loader. Report data is cached in transients so even the
 *     admin only hits Google occasionally.
 *   - Writes (settings save, transient refresh) are gated behind a nonce +
 *     current_user_can( THEMIFY_CAP ).
 *   - The service-account private key is stored via the settings framework and
 *     is never echoed back into any front-end HTML.
 *
 * @package Themify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* =========================================================================
 * CONSTANTS
 * ====================================================================== */

/** Google OAuth2 token endpoint (service-account JWT-bearer flow). */
if ( ! defined( 'THEMIFY_GOOGLE_TOKEN_URL' ) ) {
	define( 'THEMIFY_GOOGLE_TOKEN_URL', 'https://oauth2.googleapis.com/token' );
}

/** GA4 Data API v1beta base. */
if ( ! defined( 'THEMIFY_GA4_DATA_URL' ) ) {
	define( 'THEMIFY_GA4_DATA_URL', 'https://analyticsdata.googleapis.com/v1beta' );
}

/** Search Console (Webmasters) v3 base. */
if ( ! defined( 'THEMIFY_GSC_URL' ) ) {
	define( 'THEMIFY_GSC_URL', 'https://www.googleapis.com/webmasters/v3' );
}

/** OAuth scopes we request (read-only). */
if ( ! defined( 'THEMIFY_GA4_SCOPE' ) ) {
	define( 'THEMIFY_GA4_SCOPE', 'https://www.googleapis.com/auth/analytics.readonly' );
}
if ( ! defined( 'THEMIFY_GSC_SCOPE' ) ) {
	define( 'THEMIFY_GSC_SCOPE', 'https://www.googleapis.com/auth/webmasters.readonly' );
}

/** Transient keys (v2 — bumped when the cached report shape changes). */
if ( ! defined( 'THEMIFY_GA4_CACHE' ) ) {
	define( 'THEMIFY_GA4_CACHE', 'themify_ga4_report_v2' );
}
if ( ! defined( 'THEMIFY_GSC_CACHE' ) ) {
	define( 'THEMIFY_GSC_CACHE', 'themify_gsc_report_v2' );
}

/* =========================================================================
 * PART 1 — FRONT-END GA4 INJECTION
 * ====================================================================== */

/**
 * The configured GA4 Measurement ID (e.g. "G-XXXXXXX"), trimmed.
 *
 * @return string
 */
function themify_ga4_id() {
	return trim( (string) themify_get_option( 'ga4_id', '' ) );
}

/**
 * Whether GA4 should be loaded on the public site: the toggle is on AND a
 * measurement id is present.
 *
 * @return bool
 */
function themify_ga4_active() {
	return themify_is_enabled( 'ga4_enabled', false ) && '' !== themify_ga4_id();
}

/**
 * Print the standard async gtag.js loader + inline init into <head>.
 *
 * Output only happens on real, public HTML views — never in the admin, feeds,
 * REST/AJAX/cron/robots. The external loader is emitted as a normal <script
 * src> so performance.php's `script_loader_tag`-style defer logic (and the
 * browser's own async semantics) apply; the inline init runs after it loads.
 *
 * The measurement id is validated to the safe GA charset before it is placed
 * into markup, so nothing user-controlled can break out of the attribute or
 * the inline script.
 */
function themify_ga4_print_snippet() {
	if ( is_admin() || is_feed() || is_robots() || is_trackback() ) {
		return;
	}
	if ( ( defined( 'DOING_AJAX' ) && DOING_AJAX ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
		return;
	}
	if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) ) {
		return;
	}
	if ( ! themify_ga4_active() ) {
		return;
	}

	$id = themify_ga4_id();
	// GA4 ids are of the form G-XXXXXXXX (letters, digits, dash). Anything else
	// is refused rather than emitted, so we never inject arbitrary text.
	if ( ! preg_match( '/^[A-Za-z0-9\-]+$/', $id ) ) {
		return;
	}

	$src = esc_url( 'https://www.googletagmanager.com/gtag/js?id=' . rawurlencode( $id ) );
	$js  = 'window.dataLayer = window.dataLayer || [];'
		. 'function gtag(){dataLayer.push(arguments);}'
		. "gtag('js', new Date());"
		. "gtag('config', '" . esc_js( $id ) . "');";

	echo "\n<!-- Themify: Google Analytics 4 -->\n";

	if ( themify_is_enabled( 'ga4_delay', true ) ) {
		// Delayed loader: the config queue starts immediately (nothing is
		// lost), but the heavy gtag.js only loads on the first interaction or
		// after 3.5s — keeping it out of the critical path entirely.
		$loader = $js
			. '(function(){var d=false;function l(){if(d)return;d=true;'
			. 'var s=document.createElement("script");s.src="' . esc_js( $src ) . '";s.async=true;document.head.appendChild(s);}'
			. 'var t=setTimeout(l,3500);'
			. '["scroll","click","touchstart","keydown","mousemove"].forEach(function(e){'
			. 'window.addEventListener(e,function h(){clearTimeout(t);l();window.removeEventListener(e,h);},{passive:true,once:true});'
			. '});})();';
		printf( '<script>%s</script>' . "\n", $loader ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped id/url only.
	} else {
		printf( '<script async src="%s"></script>' . "\n", $src ); // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- gtag loader is intentionally a raw async tag so performance.php can manage it.
		printf( '<script>%s</script>' . "\n", $js ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $js is built from an escaped, charset-validated id only.
	}

	echo "<!-- /Themify: Google Analytics 4 -->\n";
}
add_action( 'wp_head', 'themify_ga4_print_snippet', 20 );

/* =========================================================================
 * PART 2a — SERVICE-ACCOUNT OAUTH (self-contained JWT-bearer flow)
 * ====================================================================== */

/**
 * base64url encode (RFC 7515) — standard base64 with +/ swapped for -_ and
 * padding stripped. Used for the JWT header, claims and signature.
 *
 * @param string $data Raw bytes.
 * @return string
 */
function themify_base64url( $data ) {
	return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
}

/**
 * Normalise a pasted service-account private key. Users often paste the JSON
 * form where newlines are literal "\n" sequences; convert those back to real
 * newlines so openssl can parse the PEM.
 *
 * @param string $key Raw stored key.
 * @return string
 */
function themify_normalize_private_key( $key ) {
	$key = trim( (string) $key );
	if ( '' === $key ) {
		return '';
	}
	// Literal backslash-n (from JSON) → real newline.
	if ( false !== strpos( $key, '\\n' ) ) {
		$key = str_replace( '\\n', "\n", $key );
	}
	return str_replace( "\r\n", "\n", $key );
}

/**
 * Obtain a Google API access token for the given scopes using the stored
 * service-account credentials. Self-contained: builds and RS256-signs a JWT
 * with openssl, exchanges it at the token endpoint, and caches the resulting
 * access token in a transient for ~50 minutes (Google tokens live 1 hour).
 *
 * @param array $scopes One or more OAuth scope URLs.
 * @return string|WP_Error Access token on success.
 */
function themify_google_access_token( $scopes ) {
	$scopes = array_filter( array_map( 'trim', (array) $scopes ) );
	if ( empty( $scopes ) ) {
		return new WP_Error( 'themify_ga_scope', __( 'No OAuth scope requested.', 'themify' ) );
	}

	// Never talk to Google from a public page load.
	if ( ! is_admin() && ! ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
		return new WP_Error( 'themify_ga_context', __( 'Google API calls are only allowed in admin/cron context.', 'themify' ) );
	}

	if ( ! function_exists( 'openssl_sign' ) ) {
		return new WP_Error( 'themify_ga_openssl', __( 'The PHP OpenSSL extension is required to sign the request but is not available on this server.', 'themify' ) );
	}

	$email = trim( (string) themify_get_option( 'google_sa_email', '' ) );
	$key   = themify_normalize_private_key( themify_get_option( 'google_sa_key', '' ) );
	if ( '' === $email || '' === $key ) {
		return new WP_Error( 'themify_ga_creds', __( 'Service-account email and private key are required.', 'themify' ) );
	}

	// Cache per unique scope set + account so different callers don't collide.
	sort( $scopes );
	$scope_str = implode( ' ', $scopes );
	$cache_key = 'themify_gtok_' . md5( $email . '|' . $scope_str );

	$cached = get_transient( $cache_key );
	if ( is_string( $cached ) && '' !== $cached ) {
		return $cached;
	}

	$now    = time();
	$header = array(
		'alg' => 'RS256',
		'typ' => 'JWT',
	);
	$claims = array(
		'iss'   => $email,
		'scope' => $scope_str,
		'aud'   => THEMIFY_GOOGLE_TOKEN_URL,
		'iat'   => $now,
		'exp'   => $now + 3600,
	);

	$segments      = array(
		themify_base64url( wp_json_encode( $header ) ),
		themify_base64url( wp_json_encode( $claims ) ),
	);
	$signing_input = implode( '.', $segments );

	$signature = '';
	$signed    = openssl_sign( $signing_input, $signature, $key, 'sha256WithRSAEncryption' );
	if ( ! $signed || '' === $signature ) {
		// Surface any OpenSSL error text without leaking the key itself.
		$err = function_exists( 'openssl_error_string' ) ? (string) openssl_error_string() : '';
		return new WP_Error(
			'themify_ga_sign',
			__( 'Could not sign the request. Check that the private key is a valid service-account key (PEM).', 'themify' )
				. ( $err ? ' (' . $err . ')' : '' )
		);
	}

	$jwt = $signing_input . '.' . themify_base64url( $signature );

	$response = themify_remote_json(
		THEMIFY_GOOGLE_TOKEN_URL,
		array(
			'method'  => 'POST',
			'timeout' => 20,
			'headers' => array(
				'Content-Type' => 'application/x-www-form-urlencoded',
				'Accept'       => 'application/json',
			),
			'body'    => array(
				'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
				'assertion'  => $jwt,
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	if ( empty( $response['access_token'] ) || ! is_string( $response['access_token'] ) ) {
		$msg = isset( $response['error_description'] ) ? (string) $response['error_description']
			: ( isset( $response['error'] ) ? (string) $response['error'] : __( 'No access token returned.', 'themify' ) );
		return new WP_Error( 'themify_ga_token', $msg );
	}

	$token   = $response['access_token'];
	$expires = isset( $response['expires_in'] ) ? (int) $response['expires_in'] : 3600;
	// Cache slightly under the real lifetime (max ~50 min) to leave a margin.
	$ttl = max( 60, min( 50 * MINUTE_IN_SECONDS, $expires - 300 ) );
	set_transient( $cache_key, $token, $ttl );

	return $token;
}

/**
 * Small helper: POST a JSON body to a Google API endpoint with a bearer token,
 * returning decoded JSON or WP_Error.
 *
 * @param string $url    Endpoint URL.
 * @param array  $body   Request body (encoded to JSON).
 * @param string $token  Bearer access token.
 * @return array|WP_Error
 */
function themify_google_api_post( $url, array $body, $token ) {
	return themify_remote_json(
		$url,
		array(
			'method'  => 'POST',
			'timeout' => 25,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
			),
			'body'    => wp_json_encode( $body ),
		)
	);
}

/* =========================================================================
 * PART 2b — GA4 DATA API REPORT
 * ====================================================================== */

/**
 * Whether all credentials required for the reporting APIs are present.
 *
 * @return bool
 */
function themify_analytics_has_creds() {
	$email = trim( (string) themify_get_option( 'google_sa_email', '' ) );
	$key   = themify_normalize_private_key( themify_get_option( 'google_sa_key', '' ) );
	$prop  = trim( (string) themify_get_option( 'ga4_property_id', '' ) );
	return '' !== $email && '' !== $key && '' !== $prop;
}

/**
 * The configured Search Console site URL (defaults to the site home).
 *
 * @return string
 */
function themify_gsc_site_url() {
	$site = trim( (string) themify_get_option( 'gsc_site_url', '' ) );
	if ( '' === $site ) {
		$site = trailingslashit( home_url( '/' ) );
	}
	return $site;
}

/**
 * Extract just the numeric GA4 property id from whatever the user pasted
 * ("properties/123456789" or "123456789").
 *
 * @return string Digits only, or ''.
 */
function themify_ga4_property_id() {
	$raw = trim( (string) themify_get_option( 'ga4_property_id', '' ) );
	if ( preg_match( '/(\d{4,})/', $raw, $m ) ) {
		return $m[1];
	}
	return '';
}

/**
 * Run a single GA4 runReport request and return decoded JSON or WP_Error.
 *
 * @param string $token      Access token.
 * @param string $property   Numeric property id.
 * @param array  $report_body runReport request body.
 * @return array|WP_Error
 */
function themify_ga4_run_report( $token, $property, array $report_body ) {
	$url = THEMIFY_GA4_DATA_URL . '/properties/' . rawurlencode( $property ) . ':runReport';
	return themify_google_api_post( $url, $report_body, $token );
}

/**
 * Fetch a full GA4 report bundle (KPIs + top pages + top countries + daily
 * active users), cached in a transient for 30 minutes.
 *
 * @param bool $force When true, bypass and refresh the cache.
 * @return array|WP_Error {
 *   @type array $totals    activeUsers|sessions|screenPageViews|newUsers ints.
 *   @type array $pages     [ ['path'=>, 'views'=>], … ]
 *   @type array $countries [ ['country'=>, 'users'=>], … ]
 *   @type array $daily     [ ['date'=>'YYYYMMDD', 'users'=>], … ] ascending.
 * }
 */
function themify_ga4_report( $range_key = '30d', $force = false ) {
	$ranges = themify_analytics_ranges();
	if ( ! array_key_exists( $range_key, $ranges ) ) {
		$range_key = '30d';
	}
	$cache_key = THEMIFY_GA4_CACHE . '_' . $range_key;

	if ( ! $force ) {
		$cached = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
	}

	$property = themify_ga4_property_id();
	if ( '' === $property ) {
		return new WP_Error( 'themify_ga4_property', __( 'A GA4 property ID is required.', 'themify' ) );
	}

	$token = themify_google_access_token( array( THEMIFY_GA4_SCOPE ) );
	if ( is_wp_error( $token ) ) {
		return $token;
	}

	$dates = themify_analytics_range_dates( $range_key );
	$range = array(
		'startDate' => $dates['ga_start'],
		'endDate'   => $dates['ga_end'],
	);

	// 1) Headline totals.
	$totals_res = themify_ga4_run_report(
		$token,
		$property,
		array(
			'dateRanges' => array( $range ),
			'metrics'    => array(
				array( 'name' => 'activeUsers' ),
				array( 'name' => 'sessions' ),
				array( 'name' => 'screenPageViews' ),
				array( 'name' => 'newUsers' ),
			),
		)
	);
	if ( is_wp_error( $totals_res ) ) {
		return $totals_res;
	}

	$totals = array(
		'activeUsers'     => 0,
		'sessions'        => 0,
		'screenPageViews' => 0,
		'newUsers'        => 0,
	);
	if ( isset( $totals_res['rows'][0]['metricValues'] ) && is_array( $totals_res['rows'][0]['metricValues'] ) ) {
		$vals = $totals_res['rows'][0]['metricValues'];
		$keys = array_keys( $totals );
		foreach ( $keys as $i => $k ) {
			$totals[ $k ] = isset( $vals[ $i ]['value'] ) ? (int) round( (float) $vals[ $i ]['value'] ) : 0;
		}
	}

	// 1b) 7-day + today active users (two extra date ranges in a single call —
	// GA4 adds an implicit dateRange dimension to the rows).
	$users_7d    = 0;
	$users_today = 0;
	$extra_res   = themify_ga4_run_report(
		$token,
		$property,
		array(
			'dateRanges' => array(
				array(
					'startDate' => '7daysAgo',
					'endDate'   => 'today',
				),
				array(
					'startDate' => 'today',
					'endDate'   => 'today',
				),
			),
			'metrics'    => array( array( 'name' => 'activeUsers' ) ),
		)
	);
	if ( ! is_wp_error( $extra_res ) && isset( $extra_res['rows'] ) && is_array( $extra_res['rows'] ) ) {
		foreach ( $extra_res['rows'] as $row ) {
			$slot = isset( $row['dimensionValues'][0]['value'] ) ? (string) $row['dimensionValues'][0]['value'] : '';
			$val  = isset( $row['metricValues'][0]['value'] ) ? (int) round( (float) $row['metricValues'][0]['value'] ) : 0;
			if ( 'date_range_0' === $slot ) {
				$users_7d = $val;
			} elseif ( 'date_range_1' === $slot ) {
				$users_today = $val;
			}
		}
	}

	// 2) Top pages by views (title + path).
	$pages     = array();
	$pages_res = themify_ga4_run_report(
		$token,
		$property,
		array(
			'dateRanges' => array( $range ),
			'dimensions' => array(
				array( 'name' => 'pageTitle' ),
				array( 'name' => 'pagePath' ),
			),
			'metrics'    => array( array( 'name' => 'screenPageViews' ) ),
			'orderBys'   => array(
				array(
					'metric' => array( 'metricName' => 'screenPageViews' ),
					'desc'   => true,
				),
			),
			'limit'      => 25,
		)
	);
	if ( ! is_wp_error( $pages_res ) && isset( $pages_res['rows'] ) && is_array( $pages_res['rows'] ) ) {
		foreach ( $pages_res['rows'] as $row ) {
			$title = isset( $row['dimensionValues'][0]['value'] ) ? (string) $row['dimensionValues'][0]['value'] : '';
			$path  = isset( $row['dimensionValues'][1]['value'] ) ? (string) $row['dimensionValues'][1]['value'] : '';
			$views = isset( $row['metricValues'][0]['value'] ) ? (int) round( (float) $row['metricValues'][0]['value'] ) : 0;
			if ( '' === $path ) {
				continue;
			}
			$pages[] = array(
				'title' => $title,
				'path'  => $path,
				'views' => $views,
			);
		}
	}

	// 3) Top countries by active users.
	$countries     = array();
	$countries_res = themify_ga4_run_report(
		$token,
		$property,
		array(
			'dateRanges' => array( $range ),
			'dimensions' => array( array( 'name' => 'country' ) ),
			'metrics'    => array( array( 'name' => 'activeUsers' ) ),
			'orderBys'   => array(
				array(
					'metric' => array( 'metricName' => 'activeUsers' ),
					'desc'   => true,
				),
			),
			'limit'      => 25,
		)
	);
	if ( ! is_wp_error( $countries_res ) && isset( $countries_res['rows'] ) && is_array( $countries_res['rows'] ) ) {
		foreach ( $countries_res['rows'] as $row ) {
			$name  = isset( $row['dimensionValues'][0]['value'] ) ? (string) $row['dimensionValues'][0]['value'] : '';
			$users = isset( $row['metricValues'][0]['value'] ) ? (int) round( (float) $row['metricValues'][0]['value'] ) : 0;
			if ( '' === $name ) {
				continue;
			}
			$countries[] = array(
				'country' => $name,
				'users'   => $users,
			);
		}
	}

	// 4) Daily active users (for a trend), ascending by date.
	$daily     = array();
	$daily_res = themify_ga4_run_report(
		$token,
		$property,
		array(
			'dateRanges' => array( $range ),
			'dimensions' => array( array( 'name' => 'date' ) ),
			'metrics'    => array( array( 'name' => 'activeUsers' ) ),
			'orderBys'   => array(
				array(
					'dimension' => array( 'dimensionName' => 'date' ),
				),
			),
			'limit'      => 400,
		)
	);
	if ( ! is_wp_error( $daily_res ) && isset( $daily_res['rows'] ) && is_array( $daily_res['rows'] ) ) {
		foreach ( $daily_res['rows'] as $row ) {
			$date  = isset( $row['dimensionValues'][0]['value'] ) ? (string) $row['dimensionValues'][0]['value'] : '';
			$users = isset( $row['metricValues'][0]['value'] ) ? (int) round( (float) $row['metricValues'][0]['value'] ) : 0;
			if ( '' === $date ) {
				continue;
			}
			$daily[] = array(
				'date'  => $date,
				'users' => $users,
			);
		}
	}

	$report = array(
		'totals'      => $totals,
		'users_7d'    => $users_7d,
		'users_today' => $users_today,
		'pages'       => $pages,
		'countries'   => $countries,
		'daily'       => $daily,
		'fetched'     => time(),
	);

	set_transient( $cache_key, $report, 30 * MINUTE_IN_SECONDS );
	return $report;
}

/* =========================================================================
 * PART 2c — SEARCH CONSOLE REPORT
 * ====================================================================== */

/**
 * Run a Search Console searchAnalytics query for a single dimension.
 *
 * @param string $token     Access token.
 * @param string $site      Property URL (as verified in GSC).
 * @param string $dimension 'query' or 'page'.
 * @param string $start     Start date (Y-m-d).
 * @param string $end       End date (Y-m-d).
 * @param int    $limit     Row limit.
 * @return array|WP_Error
 */
function themify_gsc_query( $token, $site, $dimension, $start, $end, $limit = 25 ) {
	$url = THEMIFY_GSC_URL . '/sites/' . rawurlencode( $site ) . '/searchAnalytics/query';
	return themify_google_api_post(
		$url,
		array(
			'startDate'  => $start,
			'endDate'    => $end,
			'dimensions' => array( $dimension ),
			'rowLimit'   => (int) $limit,
		),
		$token
	);
}

/**
 * Map GSC searchAnalytics rows into a flat array. The single dimension value
 * lands under $key; clicks/impressions/ctr/position come along.
 *
 * @param array  $res GSC response.
 * @param string $key Output key for the dimension ('query' or 'page').
 * @return array
 */
function themify_gsc_map_rows( $res, $key ) {
	$out = array();
	if ( ! is_array( $res ) || empty( $res['rows'] ) || ! is_array( $res['rows'] ) ) {
		return $out;
	}
	foreach ( $res['rows'] as $row ) {
		$label = isset( $row['keys'][0] ) ? (string) $row['keys'][0] : '';
		if ( '' === $label ) {
			continue;
		}
		$out[] = array(
			$key          => $label,
			'clicks'      => isset( $row['clicks'] ) ? (int) round( (float) $row['clicks'] ) : 0,
			'impressions' => isset( $row['impressions'] ) ? (int) round( (float) $row['impressions'] ) : 0,
			'ctr'         => isset( $row['ctr'] ) ? (float) $row['ctr'] : 0.0,
			'position'    => isset( $row['position'] ) ? (float) $row['position'] : 0.0,
		);
	}
	return $out;
}

/**
 * Fetch the Search Console report (top queries + top pages) over the last 28
 * days, cached in a transient for 30 minutes.
 *
 * @param bool $force When true, bypass and refresh the cache.
 * @return array|WP_Error {
 *   @type array $queries [ ['query'=>, 'clicks'=>, 'impressions'=>, 'ctr'=>, 'position'=>], … ]
 *   @type array $pages   [ ['page'=>, 'clicks'=>, 'impressions'=>, 'ctr'=>, 'position'=>], … ]
 * }
 */
function themify_gsc_report( $range_key = '30d', $force = false ) {
	$ranges = themify_analytics_ranges();
	if ( ! array_key_exists( $range_key, $ranges ) ) {
		$range_key = '30d';
	}
	$cache_key = THEMIFY_GSC_CACHE . '_' . $range_key;

	if ( ! $force ) {
		$cached = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
	}

	$site = themify_gsc_site_url();
	if ( '' === $site ) {
		return new WP_Error( 'themify_gsc_site', __( 'A Search Console site URL is required.', 'themify' ) );
	}

	$token = themify_google_access_token( array( THEMIFY_GSC_SCOPE ) );
	if ( is_wp_error( $token ) ) {
		return $token;
	}

	// GSC data lags ~2-3 days, so the resolver shifts rolling windows back.
	$dates = themify_analytics_range_dates( $range_key );
	$start = $dates['gsc_start'];
	$end   = $dates['gsc_end'];

	$queries_res = themify_gsc_query( $token, $site, 'query', $start, $end, 25 );
	if ( is_wp_error( $queries_res ) ) {
		return $queries_res;
	}
	$queries = themify_gsc_map_rows( $queries_res, 'query' );

	$pages_res = themify_gsc_query( $token, $site, 'page', $start, $end, 25 );
	// A failure on the second call shouldn't wipe the first; degrade gracefully.
	$pages = is_wp_error( $pages_res ) ? array() : themify_gsc_map_rows( $pages_res, 'page' );

	// Daily clicks trend for the chart's "Daily Active Clicks" view.
	$daily     = array();
	$daily_res = themify_gsc_query( $token, $site, 'date', $start, $end, 400 );
	if ( ! is_wp_error( $daily_res ) && ! empty( $daily_res['rows'] ) && is_array( $daily_res['rows'] ) ) {
		foreach ( $daily_res['rows'] as $row ) {
			$date = isset( $row['keys'][0] ) ? (string) $row['keys'][0] : '';
			if ( '' === $date ) {
				continue;
			}
			$daily[] = array(
				'date'   => $date,
				'clicks' => isset( $row['clicks'] ) ? (int) round( (float) $row['clicks'] ) : 0,
			);
		}
		// The API sorts by clicks desc; the chart needs ascending dates.
		usort( $daily, function ( $a, $b ) {
			return strcmp( $a['date'], $b['date'] );
		} );
	}

	$report = array(
		'queries' => $queries,
		'pages'   => $pages,
		'daily'   => $daily,
		'fetched' => time(),
	);

	set_transient( $cache_key, $report, 30 * MINUTE_IN_SECONDS );
	return $report;
}

/**
 * Supported dashboard date ranges (key => menu label).
 *
 * @return array
 */
function themify_analytics_ranges() {
	return array(
		'7d'         => __( 'Last 7 Days', 'themify' ),
		'30d'        => __( 'Last 30 Days', 'themify' ),
		'this_month' => __( 'This Month', 'themify' ),
		'last_month' => __( 'Last Month', 'themify' ),
		'365d'       => __( 'Last 365 Days', 'themify' ),
		'lifetime'   => __( 'Lifetime', 'themify' ),
	);
}

/**
 * The currently selected range key (?tfx_range=…), validated against the
 * whitelist. Read-only view state, so no nonce is involved.
 *
 * @return string
 */
function themify_analytics_current_range() {
	$key = isset( $_GET['tfx_range'] ) ? sanitize_key( wp_unslash( $_GET['tfx_range'] ) ) : '30d'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view state.
	return array_key_exists( $key, themify_analytics_ranges() ) ? $key : '30d';
}

/**
 * Short suffix for the KPI card labels ("Users (7d)", "Users (This Month)").
 *
 * @param string $key Range key.
 * @return string
 */
function themify_analytics_range_suffix( $key ) {
	$map = array(
		'7d'         => '7d',
		'30d'        => '30d',
		'this_month' => __( 'This Month', 'themify' ),
		'last_month' => __( 'Last Month', 'themify' ),
		'365d'       => '365d',
		'lifetime'   => __( 'Lifetime', 'themify' ),
	);
	return isset( $map[ $key ] ) ? $map[ $key ] : '30d';
}

/**
 * Resolve a range key to GA4 + Search Console start/end dates. GSC data lags
 * ~2 days, so its rolling windows are shifted back accordingly.
 *
 * @param string $key Range key.
 * @return array { ga_start, ga_end, gsc_start, gsc_end }
 */
function themify_analytics_range_dates( $key ) {
	$now     = time();
	$gsc_end = gmdate( 'Y-m-d', $now - 2 * DAY_IN_SECONDS );

	switch ( $key ) {
		case '7d':
			return array(
				'ga_start'  => '7daysAgo',
				'ga_end'    => 'today',
				'gsc_start' => gmdate( 'Y-m-d', $now - 9 * DAY_IN_SECONDS ),
				'gsc_end'   => $gsc_end,
			);
		case 'this_month':
			return array(
				'ga_start'  => gmdate( 'Y-m-01' ),
				'ga_end'    => 'today',
				'gsc_start' => gmdate( 'Y-m-01' ),
				'gsc_end'   => $gsc_end,
			);
		case 'last_month':
			$first = strtotime( 'first day of last month', $now );
			return array(
				'ga_start'  => gmdate( 'Y-m-01', $first ),
				'ga_end'    => gmdate( 'Y-m-t', $first ),
				'gsc_start' => gmdate( 'Y-m-01', $first ),
				'gsc_end'   => gmdate( 'Y-m-t', $first ),
			);
		case '365d':
			return array(
				'ga_start'  => '365daysAgo',
				'ga_end'    => 'today',
				'gsc_start' => gmdate( 'Y-m-d', $now - 367 * DAY_IN_SECONDS ),
				'gsc_end'   => $gsc_end,
			);
		case 'lifetime':
			// GA4 has no "all time" token; 2015-08-14 predates every GA4
			// property. Search Console keeps ~16 months of data.
			return array(
				'ga_start'  => '2015-08-14',
				'ga_end'    => 'today',
				'gsc_start' => gmdate( 'Y-m-d', $now - 480 * DAY_IN_SECONDS ),
				'gsc_end'   => $gsc_end,
			);
		case '30d':
		default:
			return array(
				'ga_start'  => '30daysAgo',
				'ga_end'    => 'today',
				'gsc_start' => gmdate( 'Y-m-d', $now - 32 * DAY_IN_SECONDS ),
				'gsc_end'   => $gsc_end,
			);
	}
}

/**
 * Clear all cached reports across every range (used by the Clear Cache
 * button / AJAX handler and the settings save).
 */
function themify_analytics_clear_cache() {
	foreach ( array_keys( themify_analytics_ranges() ) as $key ) {
		delete_transient( THEMIFY_GA4_CACHE . '_' . $key );
		delete_transient( THEMIFY_GSC_CACHE . '_' . $key );
	}
}

/* =========================================================================
 * PART 2d — ADMIN PAGE REGISTRATION
 * ====================================================================== */

themify_register_admin_page( array(
	'slug'       => 'themify-analytics',
	'title'      => __( 'Analytics', 'themify' ),
	'menu_title' => __( 'Analytics', 'themify' ),
	'callback'   => 'themify_analytics_page',
	'position'   => 14,
) );

add_filter( 'themify_dashboard_cards', 'themify_analytics_dashboard_card' );

/**
 * Append the Analytics dashboard card.
 *
 * @param array $cards Existing cards.
 * @return array
 */
function themify_analytics_dashboard_card( $cards ) {
	$cards[] = array(
		'slug'     => 'themify-analytics',
		'title'    => __( 'Analytics', 'themify' ),
		'desc'     => __( 'GA4 + Search Console', 'themify' ),
		'icon'     => 'dashicons-chart-area',
		'position' => 14,
	);
	return $cards;
}

/* =========================================================================
 * PART 2e — SETTINGS SAVE
 * ====================================================================== */

/**
 * The settings field definitions for the Analytics page. Shared by the save
 * handler and the render so they never drift.
 *
 * @return array
 */
function themify_analytics_fields() {
	return array(
		array( 'key' => 'ga4_id', 'type' => 'text' ),
		array( 'key' => 'ga4_enabled', 'type' => 'checkbox' ),
		array( 'key' => 'ga4_delay', 'type' => 'checkbox' ),
		array( 'key' => 'google_sa_email', 'type' => 'email' ),
		array( 'key' => 'google_sa_key', 'type' => 'code' ),
		array( 'key' => 'ga4_property_id', 'type' => 'text' ),
		array( 'key' => 'gsc_site_url', 'type' => 'url' ),
	);
}

/**
 * Handle a POST save of the Analytics settings form. Sanitizes each field by
 * its declared type via the shared sanitizer and stores them in THEMIFY_OPT.
 * Refreshing the reports is done separately (AJAX) so a settings save also
 * clears the caches to reflect the new credentials.
 *
 * @return bool True when a valid save happened.
 */
function themify_analytics_handle_save() {
	if ( ! themify_verify_save( 'themify_analytics' ) ) {
		return false;
	}
	$posted = isset( $_POST[ THEMIFY_OPT ] ) && is_array( $_POST[ THEMIFY_OPT ] )
		? wp_unslash( $_POST[ THEMIFY_OPT ] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each field is sanitized individually below.
		: array();

	$to_save = array();
	foreach ( themify_analytics_fields() as $field ) {
		$k             = $field['key'];
		$raw           = array_key_exists( $k, $posted ) ? $posted[ $k ] : '';
		$to_save[ $k ] = themify_sanitize_field( $raw, $field );
	}
	themify_set_options( $to_save );

	// Credentials may have changed — drop cached reports so the next view is fresh.
	themify_analytics_clear_cache();
	return true;
}

/* =========================================================================
 * PART 2f — REFRESH (AJAX + POST fallback)
 * ====================================================================== */

/**
 * AJAX: clear the analytics transients so the next dashboard load re-fetches.
 * Uses the shared themify_admin nonce (localized as themifyAdmin.nonce) and the
 * generic .tf-run runner.
 */
function themify_ga_refresh_ajax() {
	check_ajax_referer( 'themify_admin', 'nonce' );
	if ( ! current_user_can( THEMIFY_CAP ) ) {
		wp_send_json_error( array( 'message' => __( 'Permission denied.', 'themify' ) ) );
	}
	themify_analytics_clear_cache();
	wp_send_json_success( array(
		'html' => '<div class="tf-notice tf-notice--info">' . esc_html__( 'Analytics cache cleared. Reload this page to pull fresh data.', 'themify' ) . '</div>',
	) );
}
add_action( 'wp_ajax_themify_ga_refresh', 'themify_ga_refresh_ajax' );

/* =========================================================================
 * PART 2g — DASHBOARD RENDER
 * ====================================================================== */

/**
 * Render a .tf-notice setup guide shown when credentials are missing.
 */
function themify_analytics_render_setup_guide() {
	echo '<div class="tf-notice tf-notice--info">';
	echo '<strong>' . esc_html__( 'Connect Google to see your stats', 'themify' ) . '</strong>';
	echo '<p style="margin:8px 0 0;">' . esc_html__( 'Live GA4 and Search Console data needs a Google service account. One-time setup:', 'themify' ) . '</p>';
	echo '</div>';

	echo '<div class="tf-card">';
	echo '<h2 class="tf-card__title">' . esc_html__( 'Setup guide', 'themify' ) . '</h2>';
	echo '<ol style="margin:0 0 0 18px; color:#5a6b62; font-size:0.92rem; line-height:1.7;">';
	echo '<li>' . wp_kses_post( __( 'In the <strong>Google Cloud Console</strong>, create a project and a <strong>service account</strong>; add a <strong>JSON key</strong> and download it.', 'themify' ) ) . '</li>';
	echo '<li>' . wp_kses_post( __( 'Enable the <strong>Google Analytics Data API</strong> and the <strong>Search Console API</strong> for that project.', 'themify' ) ) . '</li>';
	echo '<li>' . wp_kses_post( __( 'In <strong>GA4 → Admin → Property Access Management</strong>, add the service-account email as a <em>Viewer</em>. Note the numeric <strong>Property ID</strong> (GA4 → Admin → Property Settings).', 'themify' ) ) . '</li>';
	echo '<li>' . wp_kses_post( __( 'In <strong>Search Console → Settings → Users and permissions</strong>, add the service-account email as an <em>Owner</em> (or full user) of the verified property.', 'themify' ) ) . '</li>';
	echo '<li>' . wp_kses_post( __( 'Below, paste the service-account <strong>email</strong>, its <strong>private key</strong> (the <code>private_key</code> value from the JSON), your GA4 <strong>Property ID</strong>, and confirm the <strong>Search Console site URL</strong>.', 'themify' ) ) . '</li>';
	echo '</ol>';
	echo '</div>';
}

/**
 * Render a WP_Error as a warning notice (used when an API call fails).
 *
 * @param WP_Error $error The error.
 * @param string   $label Which report failed.
 */
function themify_analytics_render_error( $error, $label ) {
	printf(
		'<div class="tf-notice tf-notice--warn"><strong>%s</strong> %s</div>',
		esc_html( sprintf( /* translators: %s: report name */ __( '%s could not be loaded:', 'themify' ), $label ) ),
		esc_html( $error->get_error_message() )
	);
}

/**
 * Print the scoped CSS + tiny scroll-to-top JS for the analytics dashboard.
 * Inline on purpose so the redesign is self-contained in this module and
 * cannot clash with the shared admin stylesheet.
 */
function themify_analytics_print_assets() {
	?>
	<style>
	body[class*="themify-analytics"] #wpcontent{background:#f3f8f5}
	.tfx{max-width:1320px;margin:20px auto 0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Oxygen-Sans,Ubuntu,Cantarell,"Helvetica Neue",sans-serif;color:#1a2b20}
	.tfx,.tfx *{box-sizing:border-box}
	.tfx-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;margin:6px 0 24px}
	.tfx-head h1{font-size:28px;font-weight:800;margin:0;padding:0;color:#1a2b20;letter-spacing:-.4px}
	.tfx-sub{margin:7px 0 0;color:#5a6b62;font-size:14px}
	.tfx-tools{display:flex;align-items:center;gap:12px;flex-wrap:wrap;padding-top:4px}
	.tfx-btn{display:inline-flex;align-items:center;gap:7px;background:#fff;border:1px solid #dbe4de;border-radius:10px;padding:8px 16px;font-size:13px;font-weight:600;color:#33463a;cursor:pointer;box-shadow:0 1px 2px rgba(16,24,40,.05);line-height:1.4;text-decoration:none}
	.tfx-btn:hover{border-color:#c3cfc7;color:#1a2b20}
	.tfx-btn .dashicons{font-size:16px;width:16px;height:16px;color:#5a6b62}
	.tfx-badge{display:inline-flex;align-items:center;gap:5px;font-size:12.5px;font-weight:600;color:#1e8f38}
	.tfx-badge--warn{color:#b8860b}
	.tfx-badge .dashicons{font-size:16px;width:16px;height:16px}
	.tfx-grid4{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:16px}
	.tfx-grid3{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:16px}
	.tfx-grid2{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px;align-items:start}
	@media(max-width:1200px){.tfx-grid4{grid-template-columns:repeat(2,1fr)}.tfx-grid3{grid-template-columns:1fr}.tfx-grid2{grid-template-columns:1fr}}
	.tfx-card{background:#fff;border:1px solid #e2e8ec;border-radius:14px;box-shadow:0 1px 3px rgba(16,24,40,.05)}
	.tfx-stat{padding:18px 22px 22px}
	.tfx-stat__top{display:flex;justify-content:space-between;align-items:center;color:#5a6b62;font-size:13px;font-weight:500}
	.tfx-stat__top .dashicons{color:#8fa096;font-size:18px;width:18px;height:18px}
	.tfx-stat__num{font-size:30px;font-weight:800;color:#1a2b20;margin-top:12px;letter-spacing:-.6px;line-height:1}
	.tfx-card__head{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;padding:18px 22px 12px}
	.tfx-card__title{display:flex;align-items:center;gap:8px;font-size:15px;font-weight:700;color:#1a2b20}
	.tfx-card__title .dashicons{color:#1e8f38;font-size:18px;width:18px;height:18px}
	.tfx-card__meta{font-size:12px;color:#8fa096}
	.tfx-chart{margin-bottom:20px}
	.tfx-chart__body{padding:8px 22px 16px}
	.tfx-bars{display:flex;align-items:flex-end;gap:6px;height:200px}
	.tfx-bar{flex:1 1 0;min-width:3px;background:linear-gradient(180deg,#1e8f38 0%,#a3d8b0 100%);border-radius:6px 6px 2px 2px;transition:opacity .15s}
	.tfx-bar:hover{opacity:.75}
	.tfx-chart__dates{display:flex;justify-content:space-between;color:#8fa096;font-size:12px;margin-top:10px}
	.tfx-select{display:inline-flex;align-items:center;gap:6px;border:1px solid #dbe4de;border-radius:9px;padding:6px 12px;font-size:12.5px;font-weight:600;color:#43564a;background:#fff}
	.tfx-list{max-height:540px;overflow-y:auto;padding:0 22px 14px;scrollbar-width:thin;scrollbar-color:#1e8f38 #eef4f0}
	.tfx-list::-webkit-scrollbar{width:6px}
	.tfx-list::-webkit-scrollbar-thumb{background:#1e8f38;border-radius:3px}
	.tfx-list::-webkit-scrollbar-track{background:#eef4f0;border-radius:3px}
	.tfx-row{padding:12px 0 10px;border-bottom:1px solid #f0f6f1}
	.tfx-row:last-child{border-bottom:none}
	.tfx-row__line{display:flex;align-items:flex-start;gap:12px}
	.tfx-rank{flex:0 0 auto;font-size:12px;font-weight:700;color:#8fa096;padding-top:2px;min-width:26px}
	.tfx-row__main{flex:1 1 auto;min-width:0}
	.tfx-row__title{font-size:13.5px;font-weight:600;color:#24382b;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
	.tfx-row__title a{color:inherit;text-decoration:none}
	.tfx-row__title a:hover{color:#1e8f38}
	.tfx-row__path{font-size:12px;color:#8fa096;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;margin-top:1px}
	.tfx-row__val{flex:0 0 auto;display:inline-flex;align-items:center;gap:5px;font-size:13.5px;font-weight:700;color:#1e8f38;padding-top:2px}
	.tfx-row__val--blue{color:#b8860b}
	.tfx-row__val .dashicons{font-size:15px;width:15px;height:15px;color:#8fa096}
	.tfx-track{height:4px;background:#e9f0ea;border-radius:2px;margin-top:8px;overflow:hidden}
	.tfx-track span{display:block;height:100%;border-radius:2px;background:#1e8f38}
	.tfx-track--blue span{background:#b8860b}
	.tfx-tablewrap{max-height:560px;overflow-y:auto;padding:0 22px 14px;scrollbar-width:thin;scrollbar-color:#1e8f38 #eef4f0}
	.tfx-tablewrap::-webkit-scrollbar{width:6px}
	.tfx-tablewrap::-webkit-scrollbar-thumb{background:#1e8f38;border-radius:3px}
	.tfx-tablewrap::-webkit-scrollbar-track{background:#eef4f0;border-radius:3px}
	.tfx-table{width:100%;border-collapse:collapse;font-size:13px}
	.tfx-table th{text-transform:uppercase;font-size:10.5px;color:#8fa096;letter-spacing:.5px;text-align:left;padding:10px 8px;border-bottom:1px solid #eef4f0;font-weight:700;position:sticky;top:0;background:#fff}
	.tfx-table th.tfx-r,.tfx-table td.tfx-r{text-align:right}
	.tfx-table td{padding:12px 8px;border-bottom:1px solid #f7faf8;color:#33463a;vertical-align:top}
	.tfx-table tr:last-child td{border-bottom:none}
	.tfx-table .tfx-rank{padding-top:0}
	.tfx-kw{font-weight:600;color:#24382b;word-break:break-word}
	.tfx-num--green{color:#1e8f38;font-weight:700}
	.tfx-num--blue{color:#b8860b;font-weight:600}
	.tfx-top{position:fixed;right:30px;bottom:30px;width:46px;height:46px;border-radius:50%;background:#1e8f38;color:#fff;display:flex;align-items:center;justify-content:center;box-shadow:0 8px 18px rgba(30,143,56,.45);z-index:9990;text-decoration:none;border:none;cursor:pointer}
	.tfx-top:hover{background:#156b28;color:#fff}
	.tfx-top .dashicons{font-size:20px;width:20px;height:20px;line-height:46px}
	.tfx .tf-form{margin-top:8px}
	.tfx-dd{position:relative;display:inline-flex}
	.tfx-dd__btn{cursor:pointer}
	.tfx-dd__menu{display:none;position:absolute;top:calc(100% + 6px);right:0;background:#fff;border:1px solid #dfe8e2;border-radius:12px;box-shadow:0 12px 30px rgba(15,23,42,.14);min-width:175px;padding:6px;z-index:10000;flex-direction:column}
	.tfx-dd.is-open .tfx-dd__menu{display:flex}
	.tfx-dd__item{padding:9px 13px;font-size:13px;font-weight:600;color:#33463a;border-radius:8px;text-decoration:none;white-space:nowrap}
	.tfx-dd__item:hover{background:#eef4f0;color:#1a2b20}
	.tfx-dd__item.is-active{color:#1e8f38;background:#e3f5e8}
	.tfx-chart{position:relative}
	.tfx-tip{position:absolute;background:#fff;border:1px solid #dbe4de;border-radius:7px;box-shadow:0 4px 14px rgba(15,23,42,.14);padding:4px 10px;font-size:12px;pointer-events:none;transform:translateX(-50%);white-space:nowrap;z-index:10001}
	.tfx-tip b{color:#1a2b20;font-weight:700}
	.tfx-tip span{color:#8fa096;margin-left:5px}
	.tfx-bar--blue{background:linear-gradient(180deg,#d8a713 0%,#f0dfa8 100%)}
	.tfx-bars--both{gap:4px}
	.tfx-pair{flex:1 1 0;display:flex;align-items:flex-end;gap:2px;height:100%;min-width:5px}
	.tfx-pair .tfx-bar{flex:1 1 0;min-width:2px}
	</style>
	<script>
	document.addEventListener('click',function(e){
		var btn=e.target.closest('.tfx-dd__btn');
		if(btn){
			e.preventDefault();
			var dd=btn.parentNode;
			document.querySelectorAll('.tfx-dd.is-open').forEach(function(o){if(o!==dd){o.classList.remove('is-open');}});
			dd.classList.toggle('is-open');
			return;
		}
		var item=e.target.closest('.tfx-dd__item[data-metric]');
		if(item){
			e.preventDefault();
			var dd2=item.closest('.tfx-dd');
			dd2.classList.remove('is-open');
			dd2.querySelectorAll('.tfx-dd__item').forEach(function(a){a.classList.remove('is-active');});
			item.classList.add('is-active');
			var lbl=dd2.querySelector('.tfx-dd__label');
			if(lbl){lbl.textContent=item.getAttribute('data-title');}
			var m=item.getAttribute('data-metric');
			document.querySelectorAll('.tfx-chart__body[data-chart]').forEach(function(el){el.style.display=el.getAttribute('data-chart')===m?'':'none';});
			var card=item.closest('.tfx-chart');
			var t=document.getElementById('tfx-chart-title');
			if(t&&card){t.textContent=item.getAttribute('data-title')+' — '+(card.getAttribute('data-range')||'');}
			var s=document.getElementById('tfx-chart-source');
			if(s){s.textContent=item.getAttribute('data-source');}
			return;
		}
		if(!e.target.closest('.tfx-dd')){
			document.querySelectorAll('.tfx-dd.is-open').forEach(function(o){o.classList.remove('is-open');});
		}
		var b=e.target.closest('.tfx-top');
		if(b){e.preventDefault();window.scrollTo({top:0,behavior:'smooth'});}
	});
	document.addEventListener('mouseover',function(e){
		var bar=e.target.closest('.tfx-bar');
		var tip=document.getElementById('tfx-tip');
		if(bar&&tip){
			tip.style.display='block';
			tip.querySelector('b').textContent=bar.getAttribute('data-v')||'';
			tip.querySelector('span').textContent=bar.getAttribute('data-d')||'';
			var card=bar.closest('.tfx-chart');
			if(card){
				var r=bar.getBoundingClientRect(),cr=card.getBoundingClientRect();
				tip.style.left=(r.left-cr.left+r.width/2)+'px';
				tip.style.top=(r.top-cr.top-34)+'px';
			}
		}
	});
	document.addEventListener('mouseout',function(e){
		if(e.target.closest&&e.target.closest('.tfx-bar')){
			var tip=document.getElementById('tfx-tip');
			if(tip){tip.style.display='none';}
		}
	});
	</script>
	<?php
}

/**
 * Render one KPI stat card (label, big number, dashicon top-right).
 *
 * @param string $label Card label.
 * @param int    $value Numeric value.
 * @param string $icon  Dashicon slug (without the "dashicons-" prefix).
 */
function themify_analytics_stat_card( $label, $value, $icon ) {
	echo '<div class="tfx-card tfx-stat">';
	echo '<div class="tfx-stat__top"><span>' . esc_html( $label ) . '</span><span class="dashicons dashicons-' . esc_attr( $icon ) . '"></span></div>';
	echo '<div class="tfx-stat__num">' . esc_html( number_format_i18n( (int) $value ) ) . '</div>';
	echo '</div>';
}

/**
 * Render the two KPI card rows (4 cards + 3 cards).
 *
 * @param array  $ga4 Report from themify_ga4_report().
 * @param string $sfx Range suffix for the card labels ("30d", "This Month"…).
 */
function themify_analytics_render_kpis( array $ga4, $sfx = '30d' ) {
	$totals = isset( $ga4['totals'] ) && is_array( $ga4['totals'] ) ? $ga4['totals'] : array();

	echo '<div class="tfx-grid4">';
	/* translators: %s: selected date range, e.g. "30d" or "This Month". */
	themify_analytics_stat_card( sprintf( __( 'Users (%s)', 'themify' ), $sfx ), $totals['activeUsers'] ?? 0, 'groups' );
	themify_analytics_stat_card( __( 'Users (7d)', 'themify' ), $ga4['users_7d'] ?? 0, 'calendar-alt' );
	themify_analytics_stat_card( __( 'Today', 'themify' ), $ga4['users_today'] ?? 0, 'clock' );
	/* translators: %s: selected date range. */
	themify_analytics_stat_card( sprintf( __( 'New Users (%s)', 'themify' ), $sfx ), $totals['newUsers'] ?? 0, 'chart-line' );
	echo '</div>';

	echo '<div class="tfx-grid3">';
	/* translators: %s: selected date range. */
	themify_analytics_stat_card( sprintf( __( 'Sessions (%s)', 'themify' ), $sfx ), $totals['sessions'] ?? 0, 'chart-bar' );
	/* translators: %s: selected date range. */
	themify_analytics_stat_card( sprintf( __( 'Page Views (%s)', 'themify' ), $sfx ), $totals['screenPageViews'] ?? 0, 'visibility' );
	themify_analytics_stat_card( __( 'Total Active Users', 'themify' ), $totals['activeUsers'] ?? 0, 'layout' );
	echo '</div>';
}

/**
 * Render one bar-chart body for a single daily series.
 *
 * @param array  $rows    Daily rows; the value key is $metric.
 * @param string $metric  'users' (green, GA4) or 'clicks' (blue, Search Console).
 * @param bool   $visible Whether this variant starts visible.
 */
function themify_analytics_chart_series( array $rows, $metric, $visible ) {
	printf( '<div class="tfx-chart__body" data-chart="%s"%s>', esc_attr( $metric ), $visible ? '' : ' style="display:none;"' );

	if ( empty( $rows ) ) {
		echo '<p class="tfx-card__meta" style="padding:20px 0;">' . esc_html__( 'No daily data yet.', 'themify' ) . '</p>';
		echo '</div>';
		return;
	}

	$max = 1;
	foreach ( $rows as $d ) {
		$max = max( $max, (int) $d[ $metric ] );
	}

	$gap = count( $rows ) > 120 ? ' style="gap:1px;"' : '';
	echo '<div class="tfx-bars"' . $gap . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static attribute string.
	foreach ( $rows as $d ) {
		$val = (int) $d[ $metric ];
		$pct = max( 3, (int) round( $val / $max * 100 ) );
		$ts  = strtotime( (string) $d['date'] );
		printf(
			'<span class="tfx-bar%s" style="height:%d%%" data-v="%s" data-d="%s"></span>',
			'clicks' === $metric ? ' tfx-bar--blue' : '',
			(int) $pct,
			esc_attr( number_format_i18n( $val ) ),
			esc_attr( $ts ? gmdate( 'n/j', $ts ) : (string) $d['date'] )
		);
	}
	echo '</div>';

	$first = strtotime( (string) $rows[0]['date'] );
	$last  = strtotime( (string) $rows[ count( $rows ) - 1 ]['date'] );
	echo '<div class="tfx-chart__dates">';
	echo '<span>' . esc_html( $first ? date_i18n( 'M j', $first ) : '' ) . '</span>';
	echo '<span>' . esc_html( $last ? date_i18n( 'M j', $last ) : '' ) . '</span>';
	echo '</div>';
	echo '</div>';
}

/**
 * Render the combined "Clicks vs Users" body: for every GA4 day a green
 * users bar and a blue clicks bar side by side.
 *
 * @param array $ga_daily  [ ['date'=>'YYYYMMDD','users'=>int], … ].
 * @param array $gsc_daily [ ['date'=>'Y-m-d','clicks'=>int], … ].
 */
function themify_analytics_chart_pair( array $ga_daily, array $gsc_daily ) {
	echo '<div class="tfx-chart__body" data-chart="both" style="display:none;">';

	if ( empty( $ga_daily ) ) {
		echo '<p class="tfx-card__meta" style="padding:20px 0;">' . esc_html__( 'No daily data yet.', 'themify' ) . '</p>';
		echo '</div>';
		return;
	}

	$clicks_by_date = array();
	foreach ( $gsc_daily as $d ) {
		$clicks_by_date[ (string) $d['date'] ] = (int) $d['clicks'];
	}

	$max = 1;
	foreach ( $ga_daily as $d ) {
		$max = max( $max, (int) $d['users'] );
	}
	foreach ( $clicks_by_date as $c ) {
		$max = max( $max, $c );
	}

	echo '<div class="tfx-bars tfx-bars--both">';
	foreach ( $ga_daily as $d ) {
		$raw = (string) $d['date']; // YYYYMMDD from GA4.
		$iso = 8 === strlen( $raw ) ? substr( $raw, 0, 4 ) . '-' . substr( $raw, 4, 2 ) . '-' . substr( $raw, 6, 2 ) : $raw;
		$u   = (int) $d['users'];
		$c   = isset( $clicks_by_date[ $iso ] ) ? $clicks_by_date[ $iso ] : 0;
		$ts  = strtotime( $raw );
		$dl  = $ts ? gmdate( 'n/j', $ts ) : $raw;

		echo '<span class="tfx-pair">';
		printf(
			'<span class="tfx-bar" style="height:%d%%" data-v="%s" data-d="%s"></span>',
			max( 2, (int) round( $u / $max * 100 ) ),
			/* translators: %s: number of users. */
			esc_attr( sprintf( __( '%s users', 'themify' ), number_format_i18n( $u ) ) ),
			esc_attr( $dl )
		);
		printf(
			'<span class="tfx-bar tfx-bar--blue" style="height:%d%%" data-v="%s" data-d="%s"></span>',
			max( 2, (int) round( $c / $max * 100 ) ),
			/* translators: %s: number of clicks. */
			esc_attr( sprintf( __( '%s clicks', 'themify' ), number_format_i18n( $c ) ) ),
			esc_attr( $dl )
		);
		echo '</span>';
	}
	echo '</div>';

	$first = strtotime( (string) $ga_daily[0]['date'] );
	$last  = strtotime( (string) $ga_daily[ count( $ga_daily ) - 1 ]['date'] );
	echo '<div class="tfx-chart__dates">';
	echo '<span>' . esc_html( $first ? date_i18n( 'M j', $first ) : '' ) . '</span>';
	echo '<span>' . esc_html( $last ? date_i18n( 'M j', $last ) : '' ) . '</span>';
	echo '</div>';
	echo '</div>';
}

/**
 * Render the daily chart card with the metric switcher (GA4 users / Search
 * Console clicks / both) and the hover tooltip. All three variants render
 * server-side and are toggled client-side — no AJAX round-trips.
 *
 * @param array  $ga_daily    GA4 daily rows.
 * @param array  $gsc_daily   Search Console daily rows.
 * @param string $range_label Human range label, e.g. "Last 30 Days".
 */
function themify_analytics_render_chart( array $ga_daily, array $gsc_daily, $range_label ) {
	echo '<div class="tfx-card tfx-chart" data-range="' . esc_attr( $range_label ) . '">';
	echo '<div class="tfx-card__head">';
	echo '<span class="tfx-card__title"><span class="dashicons dashicons-chart-bar"></span><span id="tfx-chart-title">'
		/* translators: %s: selected date range label. */
		. esc_html( sprintf( __( 'Daily Active Users — %s', 'themify' ), $range_label ) ) . '</span></span>';

	echo '<span class="tfx-card__meta" style="display:inline-flex;align-items:center;gap:8px;">';
	echo '<span class="tfx-dd">';
	echo '<button type="button" class="tfx-select tfx-dd__btn"><span class="tfx-dd__label">' . esc_html__( 'Daily Active Users', 'themify' ) . '</span> <span class="dashicons dashicons-arrow-down-alt2" style="font-size:12px;width:12px;height:12px;"></span></button>';
	echo '<span class="tfx-dd__menu">';
	echo '<a href="#" class="tfx-dd__item is-active" data-metric="users" data-title="' . esc_attr__( 'Daily Active Users', 'themify' ) . '" data-source="' . esc_attr__( 'from Google Analytics', 'themify' ) . '">' . esc_html__( 'Daily Active Users', 'themify' ) . '</a>';
	echo '<a href="#" class="tfx-dd__item" data-metric="clicks" data-title="' . esc_attr__( 'Daily Active Clicks', 'themify' ) . '" data-source="' . esc_attr__( 'from Search Console', 'themify' ) . '">' . esc_html__( 'Daily Active Clicks', 'themify' ) . '</a>';
	echo '<a href="#" class="tfx-dd__item" data-metric="both" data-title="' . esc_attr__( 'Clicks vs Users', 'themify' ) . '" data-source="' . esc_attr__( 'GA4 + Search Console', 'themify' ) . '">' . esc_html__( 'Clicks vs Users', 'themify' ) . '</a>';
	echo '</span>';
	echo '</span>';
	echo '<span id="tfx-chart-source">' . esc_html__( 'from Google Analytics', 'themify' ) . '</span>';
	echo '</span>';
	echo '</div>';

	themify_analytics_chart_series( $ga_daily, 'users', true );
	themify_analytics_chart_series( $gsc_daily, 'clicks', false );
	themify_analytics_chart_pair( $ga_daily, $gsc_daily );

	echo '<div class="tfx-tip" id="tfx-tip" style="display:none;"><b></b><span></span></div>';
	echo '</div>';
}

/**
 * Render the "Top 25 Pages" list (rank, title, path, views, green bar).
 *
 * @param array $pages Rows [ ['title'=>, 'path'=>, 'views'=>], … ].
 */
function themify_analytics_render_pages( array $pages ) {
	echo '<div class="tfx-card">';
	echo '<div class="tfx-card__head">';
	echo '<span class="tfx-card__title"><span class="dashicons dashicons-media-document"></span>' . esc_html__( 'Top 25 Pages', 'themify' ) . '</span>';
	echo '<span class="tfx-card__meta">' . esc_html__( 'by pageviews', 'themify' ) . '</span>';
	echo '</div>';

	if ( empty( $pages ) ) {
		echo '<p class="tfx-card__meta" style="padding:0 22px 18px;">' . esc_html__( 'No page data yet.', 'themify' ) . '</p>';
		echo '</div>';
		return;
	}

	$max = 1;
	foreach ( $pages as $row ) {
		$max = max( $max, (int) $row['views'] );
	}

	echo '<div class="tfx-list">';
	$i = 0;
	foreach ( $pages as $row ) {
		$i++;
		$path  = (string) $row['path'];
		$title = '' !== trim( (string) ( $row['title'] ?? '' ) ) ? (string) $row['title'] : $path;
		$views = (int) $row['views'];
		$pct   = max( 2, (int) round( $views / $max * 100 ) );

		echo '<div class="tfx-row">';
		echo '<div class="tfx-row__line">';
		echo '<span class="tfx-rank">#' . (int) $i . '</span>';
		echo '<div class="tfx-row__main">';
		printf(
			'<div class="tfx-row__title"><a href="%s" target="_blank" rel="noopener noreferrer">%s</a></div>',
			esc_url( home_url( $path ) ),
			esc_html( $title )
		);
		echo '<div class="tfx-row__path">' . esc_html( $path ) . '</div>';
		echo '</div>';
		echo '<span class="tfx-row__val" style="color:#33463a;"><span class="dashicons dashicons-visibility"></span>' . esc_html( number_format_i18n( $views ) ) . '</span>';
		echo '</div>';
		echo '<div class="tfx-track"><span style="width:' . (int) $pct . '%"></span></div>';
		echo '</div>';
	}
	echo '</div>';
	echo '</div>';
}

/**
 * Render the "Top 25 Countries" list (rank, country, users, blue bar).
 *
 * @param array $countries Rows [ ['country'=>, 'users'=>], … ].
 */
function themify_analytics_render_countries( array $countries ) {
	echo '<div class="tfx-card">';
	echo '<div class="tfx-card__head">';
	echo '<span class="tfx-card__title"><span class="dashicons dashicons-admin-site-alt3"></span>' . esc_html__( 'Top 25 Countries', 'themify' ) . '</span>';
	echo '<span class="tfx-card__meta">' . esc_html__( 'by active users', 'themify' ) . '</span>';
	echo '</div>';

	if ( empty( $countries ) ) {
		echo '<p class="tfx-card__meta" style="padding:0 22px 18px;">' . esc_html__( 'No country data yet.', 'themify' ) . '</p>';
		echo '</div>';
		return;
	}

	$max = 1;
	foreach ( $countries as $row ) {
		$max = max( $max, (int) $row['users'] );
	}

	echo '<div class="tfx-list">';
	$i = 0;
	foreach ( $countries as $row ) {
		$i++;
		$users = (int) $row['users'];
		$pct   = max( 2, (int) round( $users / $max * 100 ) );

		echo '<div class="tfx-row">';
		echo '<div class="tfx-row__line">';
		echo '<span class="tfx-rank">#' . (int) $i . '</span>';
		echo '<div class="tfx-row__main"><div class="tfx-row__title">' . esc_html( (string) $row['country'] ) . '</div></div>';
		echo '<span class="tfx-row__val tfx-row__val--blue">' . esc_html( number_format_i18n( $users ) ) . '</span>';
		echo '</div>';
		echo '<div class="tfx-track tfx-track--blue"><span style="width:' . (int) $pct . '%"></span></div>';
		echo '</div>';
	}
	echo '</div>';
	echo '</div>';
}

/**
 * Render the "Top 25 Search Keywords" table from Search Console.
 *
 * @param array $queries Rows with query/clicks/impressions/ctr/position.
 */
function themify_analytics_render_queries( array $queries ) {
	echo '<div class="tfx-card">';
	echo '<div class="tfx-card__head">';
	echo '<span class="tfx-card__title"><span class="dashicons dashicons-search"></span>' . esc_html__( 'Top 25 Search Keywords', 'themify' ) . '</span>';
	echo '<span class="tfx-card__meta">' . esc_html__( 'Google Search Console', 'themify' ) . '</span>';
	echo '</div>';

	if ( empty( $queries ) ) {
		echo '<p class="tfx-card__meta" style="padding:0 22px 18px;">' . esc_html__( 'No query data yet. Search Console hides rare queries, and new properties can take a few days to report.', 'themify' ) . '</p>';
		echo '</div>';
		return;
	}

	echo '<div class="tfx-tablewrap">';
	echo '<table class="tfx-table"><thead><tr>';
	echo '<th>#</th>';
	echo '<th>' . esc_html__( 'Keyword', 'themify' ) . '</th>';
	echo '<th class="tfx-r">' . esc_html__( 'Clicks', 'themify' ) . '</th>';
	echo '<th class="tfx-r">' . esc_html__( 'Impr.', 'themify' ) . '</th>';
	echo '<th class="tfx-r">' . esc_html__( 'CTR', 'themify' ) . '</th>';
	echo '<th class="tfx-r">' . esc_html__( 'Pos.', 'themify' ) . '</th>';
	echo '</tr></thead><tbody>';
	$i = 0;
	foreach ( $queries as $row ) {
		$i++;
		echo '<tr>';
		echo '<td class="tfx-rank">#' . (int) $i . '</td>';
		echo '<td class="tfx-kw">' . esc_html( (string) $row['query'] ) . '</td>';
		echo '<td class="tfx-r tfx-num--green">' . esc_html( number_format_i18n( (int) $row['clicks'] ) ) . '</td>';
		echo '<td class="tfx-r">' . esc_html( number_format_i18n( (int) $row['impressions'] ) ) . '</td>';
		echo '<td class="tfx-r tfx-num--blue">' . esc_html( number_format_i18n( (float) $row['ctr'] * 100, 1 ) . '%' ) . '</td>';
		echo '<td class="tfx-r">' . esc_html( number_format_i18n( (float) $row['position'], 1 ) ) . '</td>';
		echo '</tr>';
	}
	echo '</tbody></table>';
	echo '</div>';
	echo '</div>';
}

/**
 * Render the "Top 25 Search Pages" table from Search Console. Shown in
 * addition to keywords because Search Console anonymises low-volume queries —
 * on smaller sites the keyword list can be empty while page data exists.
 *
 * @param array $pages Rows with page/clicks/impressions/ctr/position.
 */
function themify_analytics_render_gsc_pages( array $pages ) {
	echo '<div class="tfx-card">';
	echo '<div class="tfx-card__head">';
	echo '<span class="tfx-card__title"><span class="dashicons dashicons-star-filled"></span>' . esc_html__( 'Top 25 Search Pages', 'themify' ) . '</span>';
	echo '<span class="tfx-card__meta">' . esc_html__( 'by clicks', 'themify' ) . '</span>';
	echo '</div>';

	if ( empty( $pages ) ) {
		echo '<p class="tfx-card__meta" style="padding:0 22px 18px;">' . esc_html__( 'No Search Console page data yet. Google typically needs a few days after access is granted before report data appears.', 'themify' ) . '</p>';
		echo '</div>';
		return;
	}

	$home = untrailingslashit( home_url( '/' ) );

	echo '<div class="tfx-tablewrap">';
	echo '<table class="tfx-table"><thead><tr>';
	echo '<th>#</th>';
	echo '<th>' . esc_html__( 'Page', 'themify' ) . '</th>';
	echo '<th class="tfx-r">' . esc_html__( 'Clicks', 'themify' ) . '</th>';
	echo '<th class="tfx-r">' . esc_html__( 'Impr.', 'themify' ) . '</th>';
	echo '<th class="tfx-r">' . esc_html__( 'Pos.', 'themify' ) . '</th>';
	echo '</tr></thead><tbody>';
	$i = 0;
	foreach ( $pages as $row ) {
		$i++;
		$url = (string) $row['page'];
		// Show the short path (like the reference design) but link the full URL.
		$label = $url;
		if ( 0 === strpos( $url, $home ) ) {
			$label = substr( $url, strlen( $home ) );
			if ( '' === $label ) {
				$label = '/';
			}
		}
		echo '<tr>';
		echo '<td class="tfx-rank">#' . (int) $i . '</td>';
		printf(
			'<td class="tfx-kw"><a href="%s" target="_blank" rel="noopener noreferrer" style="color:inherit;text-decoration:none;">%s</a></td>',
			esc_url( $url ),
			esc_html( $label )
		);
		echo '<td class="tfx-r tfx-num--green">' . esc_html( number_format_i18n( (int) $row['clicks'] ) ) . '</td>';
		echo '<td class="tfx-r">' . esc_html( number_format_i18n( (int) $row['impressions'] ) ) . '</td>';
		echo '<td class="tfx-r">' . esc_html( number_format_i18n( (float) $row['position'], 1 ) ) . '</td>';
		echo '</tr>';
	}
	echo '</tbody></table>';
	echo '</div>';
	echo '</div>';
}

/**
 * The main Analytics admin screen. Handles the settings save, shows either the
 * setup guide (no creds) or the live dashboard (KPI cards, chart, top lists,
 * Search Console tables), and always renders the settings form at the bottom.
 */
function themify_analytics_page() {
	$saved = themify_analytics_handle_save();

	echo '<div class="wrap tfx">';
	themify_analytics_print_assets();

	if ( ! themify_analytics_has_creds() ) {
		echo '<div class="tfx-head"><div>';
		echo '<h1>' . esc_html__( 'Dashboard', 'themify' ) . '</h1>';
		echo '<p class="tfx-sub">' . esc_html__( 'Real-time data from Google Analytics & Search Console', 'themify' ) . '</p>';
		echo '</div></div>';
		if ( $saved ) {
			echo '<div class="tf-notice tf-notice--info">' . esc_html__( 'Analytics settings saved.', 'themify' ) . '</div>';
		}
		themify_analytics_render_setup_guide();
		themify_analytics_render_settings_form();
		echo '</div>';
		return;
	}

	$range_key   = themify_analytics_current_range();
	$ranges      = themify_analytics_ranges();
	$range_label = $ranges[ $range_key ];
	$range_sfx   = themify_analytics_range_suffix( $range_key );

	$ga4 = themify_ga4_report( $range_key );
	$gsc = themify_gsc_report( $range_key );

	$ga4_ok = ! is_wp_error( $ga4 );
	$gsc_ok = ! is_wp_error( $gsc );

	// ---- Header: title left, tools + connection badges right. ----
	echo '<div class="tfx-head">';
	echo '<div>';
	echo '<h1>' . esc_html__( 'Dashboard', 'themify' ) . '</h1>';
	echo '<p class="tfx-sub">' . esc_html__( 'Real-time data from Google Analytics & Search Console', 'themify' ) . '</p>';
	echo '</div>';
	echo '<div class="tfx-tools">';
	echo '<button class="tfx-btn tf-run" data-action="themify_ga_refresh" data-target="#tf-ga-refresh-result" data-running="' . esc_attr__( 'Clearing…', 'themify' ) . '">'
		. '<span class="dashicons dashicons-update"></span>' . esc_html__( 'Clear Cache', 'themify' ) . '</button>';
	echo '<span class="tfx-dd">';
	echo '<button type="button" class="tfx-btn tfx-dd__btn"><span class="dashicons dashicons-calendar-alt"></span>' . esc_html( $range_label ) . ' <span class="dashicons dashicons-arrow-down-alt2" style="font-size:13px;width:13px;height:13px;"></span></button>';
	echo '<span class="tfx-dd__menu">';
	foreach ( $ranges as $key => $label ) {
		printf(
			'<a href="%s" class="tfx-dd__item%s">%s</a>',
			esc_url( add_query_arg( array( 'page' => 'themify-analytics', 'tfx_range' => $key ), admin_url( 'admin.php' ) ) ),
			$key === $range_key ? ' is-active' : '',
			esc_html( $label )
		);
	}
	echo '</span>';
	echo '</span>';
	printf(
		'<span class="tfx-badge %s"><span class="dashicons %s"></span>%s</span>',
		$ga4_ok ? '' : 'tfx-badge--warn',
		$ga4_ok ? 'dashicons-yes-alt' : 'dashicons-warning',
		esc_html( $ga4_ok ? __( 'GA4 Connected', 'themify' ) : __( 'GA4 Error', 'themify' ) )
	);
	printf(
		'<span class="tfx-badge %s"><span class="dashicons %s"></span>%s</span>',
		$gsc_ok ? '' : 'tfx-badge--warn',
		$gsc_ok ? 'dashicons-yes-alt' : 'dashicons-warning',
		esc_html( $gsc_ok ? __( 'Search Console', 'themify' ) : __( 'Search Console Error', 'themify' ) )
	);
	echo '</div>';
	echo '</div>';

	if ( $saved ) {
		echo '<div class="tf-notice tf-notice--info">' . esc_html__( 'Analytics settings saved.', 'themify' ) . '</div>';
	}
	echo '<div id="tf-ga-refresh-result"></div>';

	// ---- GA4: KPI cards + daily chart + pages/countries. ----
	if ( ! $ga4_ok ) {
		themify_analytics_render_error( $ga4, __( 'Google Analytics', 'themify' ) );
	} else {
		themify_analytics_render_kpis( $ga4, $range_sfx );
		themify_analytics_render_chart(
			isset( $ga4['daily'] ) && is_array( $ga4['daily'] ) ? $ga4['daily'] : array(),
			$gsc_ok && isset( $gsc['daily'] ) && is_array( $gsc['daily'] ) ? $gsc['daily'] : array(),
			$range_label
		);

		echo '<div class="tfx-grid2">';
		themify_analytics_render_pages( isset( $ga4['pages'] ) && is_array( $ga4['pages'] ) ? $ga4['pages'] : array() );
		themify_analytics_render_countries( isset( $ga4['countries'] ) && is_array( $ga4['countries'] ) ? $ga4['countries'] : array() );
		echo '</div>';
	}

	// ---- Search Console: keywords + search pages. ----
	if ( ! $gsc_ok ) {
		themify_analytics_render_error( $gsc, __( 'Search Console', 'themify' ) );
	} else {
		echo '<div class="tfx-grid2">';
		themify_analytics_render_queries( isset( $gsc['queries'] ) && is_array( $gsc['queries'] ) ? $gsc['queries'] : array() );
		themify_analytics_render_gsc_pages( isset( $gsc['pages'] ) && is_array( $gsc['pages'] ) ? $gsc['pages'] : array() );
		echo '</div>';
	}

	// ---- Settings form + floating scroll-to-top. ----
	themify_analytics_render_settings_form();
	echo '<button type="button" class="tfx-top" aria-label="' . esc_attr__( 'Scroll to top', 'themify' ) . '"><span class="dashicons dashicons-arrow-up-alt2"></span></button>';

	echo '</div>';
}

/**
 * Render the Analytics settings form. Kept separate from the declarative
 * renderer because this screen also shows a live report above it, but reuses
 * the same field renderer, nonce field name and .tf-card chrome.
 */
function themify_analytics_render_settings_form() {
	echo '<form method="post" class="tf-form">';
	wp_nonce_field( 'themify_analytics', 'themify_nonce' );

	// GA4 injection group.
	echo '<div class="tf-card">';
	echo '<h2 class="tf-card__title">' . esc_html__( 'Google Analytics 4', 'themify' ) . '</h2>';
	echo '<p class="tf-card__desc">' . esc_html__( 'Loads gtag.js on the public site so GA4 records traffic.', 'themify' ) . '</p>';
	themify_render_field( array(
		'key'         => 'ga4_id',
		'label'       => __( 'Measurement ID', 'themify' ),
		'type'        => 'text',
		'placeholder' => 'G-XXXXXXXXXX',
		'desc'        => __( 'From GA4 → Admin → Data Streams → your web stream.', 'themify' ),
	) );
	themify_render_field( array(
		'key'     => 'ga4_enabled',
		'label'   => __( 'Load GA4 on the site', 'themify' ),
		'type'    => 'checkbox',
		'default' => '',
	) );
	themify_render_field( array(
		'key'     => 'ga4_delay',
		'label'   => __( 'Delay GA4 until interaction (faster PageSpeed)', 'themify' ),
		'type'    => 'checkbox',
		'default' => '1',
		'desc'    => __( 'Loads the Google tag on the first scroll/click or after 3.5s instead of blocking the initial render. No data is lost.', 'themify' ),
	) );
	echo '</div>';

	// Reporting API credentials group.
	echo '<div class="tf-card">';
	echo '<h2 class="tf-card__title">' . esc_html__( 'Reporting API credentials', 'themify' ) . '</h2>';
	echo '<p class="tf-card__desc">' . wp_kses_post( __( 'Service-account credentials used to read GA4 &amp; Search Console data for the dashboard above. These are used only in the admin and never exposed on the front end.', 'themify' ) ) . '</p>';
	themify_render_field( array(
		'key'         => 'google_sa_email',
		'label'       => __( 'Service-account email', 'themify' ),
		'type'        => 'email',
		'placeholder' => 'name@project-id.iam.gserviceaccount.com',
		'desc'        => __( 'The client_email from the service-account JSON key.', 'themify' ),
	) );
	themify_render_field( array(
		'key'         => 'google_sa_key',
		'label'       => __( 'Service-account private key', 'themify' ),
		'type'        => 'code',
		'rows'        => 7,
		'placeholder' => "-----BEGIN PRIVATE KEY-----\n…\n-----END PRIVATE KEY-----",
		'desc'        => __( 'The private_key value from the JSON key (PEM). Paste it exactly; escaped \\n sequences are handled automatically.', 'themify' ),
	) );
	themify_render_field( array(
		'key'         => 'ga4_property_id',
		'label'       => __( 'GA4 Property ID', 'themify' ),
		'type'        => 'text',
		'placeholder' => '123456789',
		'desc'        => __( 'Numeric property ID from GA4 → Admin → Property Settings.', 'themify' ),
	) );
	themify_render_field( array(
		'key'         => 'gsc_site_url',
		'label'       => __( 'Search Console site URL', 'themify' ),
		'type'        => 'url',
		'placeholder' => trailingslashit( home_url( '/' ) ),
		'desc'        => __( 'The exact property URL as verified in Search Console. Defaults to your site home.', 'themify' ),
	) );
	echo '</div>';

	echo '<p class="tf-form__actions"><button type="submit" class="button button-primary button-hero">' . esc_html__( 'Save Changes', 'themify' ) . '</button></p>';
	echo '</form>';
}
