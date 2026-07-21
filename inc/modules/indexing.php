<?php
/**
 * Indexing — IndexNow + (optional) Google Indexing API + a submission report.
 *
 * Search engines index new/changed content much faster when you *tell* them
 * about it instead of waiting for a crawl. This module gives Themify two push
 * back-ends and an admin console to drive + audit them:
 *
 *   1. IndexNow (Bing, Yandex, Seznam, Naver, …) — a keyless-to-set-up, open
 *      protocol. We auto-generate the site's IndexNow key on first load, serve
 *      the required /{key}.txt verification file, and expose
 *      themify_indexnow_submit() which POSTs a batch of URLs to the API.
 *
 *   2. Google Indexing API (optional) — only fires when a companion module has
 *      provided themify_google_access_token() and OAuth credentials exist. It
 *      is guarded at every step so a site with no Google creds simply skips it.
 *
 * The seo-sitemap module auto-pings both of these on publish/update; this file
 * just has to make the functions exist and be safe to call.
 *
 * Every submission is logged (URL count, time, HTTP status, back-end) to a
 * dedicated option, capped at 100 rows, and rendered as a report table.
 *
 * EXTERNAL HTTP RULE: the only public (front-end) work this module does is
 * serving the tiny static key file. All API calls happen from the publish hook
 * (admin/cron context) or from the admin console's AJAX/POST handlers — never
 * on a public page view — and results are rate-limited with transients.
 *
 * @package Themify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Option holding the site's IndexNow key (a 32-char lowercase hex string).
 */
if ( ! defined( 'THEMIFY_INDEXNOW_KEY_OPT' ) ) {
	define( 'THEMIFY_INDEXNOW_KEY_OPT', 'themify_indexnow_key' );
}

/**
 * Option holding the submission log (indexed array, newest first, capped).
 */
if ( ! defined( 'THEMIFY_INDEXING_LOG_OPT' ) ) {
	define( 'THEMIFY_INDEXING_LOG_OPT', 'themify_indexing_log' );
}

/**
 * How many submission log rows to keep.
 */
if ( ! defined( 'THEMIFY_INDEXING_LOG_MAX' ) ) {
	define( 'THEMIFY_INDEXING_LOG_MAX', 100 );
}

/**
 * Option holding the latest URL-Inspection scan (queue, results, timestamps).
 */
if ( ! defined( 'THEMIFY_INDEX_SCAN_OPT' ) ) {
	define( 'THEMIFY_INDEX_SCAN_OPT', 'themify_index_scan' );
}

/**
 * Option mapping URL => last per-engine submission timestamps.
 */
if ( ! defined( 'THEMIFY_INDEX_SUBMITS_OPT' ) ) {
	define( 'THEMIFY_INDEX_SUBMITS_OPT', 'themify_index_submits' );
}

/* ============================================================ INDEXNOW KEY */

/**
 * Get the site's IndexNow key, generating and persisting one on first use.
 *
 * The key must be a hex string 8–128 chars long. We generate a 32-char
 * lowercase hex value from random bytes (falling back to a hashed
 * wp_generate_password if the CSPRNG is unavailable) so the value is stable and
 * matches the /{key}.txt file we serve.
 *
 * @return string 32-char lowercase hex key.
 */
function themify_indexnow_key() {
	$key = get_option( THEMIFY_INDEXNOW_KEY_OPT, '' );

	// Validate the stored value; regenerate if it is missing or malformed.
	if ( ! is_string( $key ) || ! preg_match( '/^[a-f0-9]{32}$/', $key ) ) {
		$key = themify_indexnow_generate_key();
		update_option( THEMIFY_INDEXNOW_KEY_OPT, $key, false );
	}

	return $key;
}

/**
 * Produce a fresh 32-char lowercase hex key.
 *
 * @return string
 */
function themify_indexnow_generate_key() {
	// Preferred: 16 cryptographically-secure random bytes → 32 hex chars.
	if ( function_exists( 'random_bytes' ) ) {
		try {
			return bin2hex( random_bytes( 16 ) );
		} catch ( \Exception $e ) {
			// Fall through to the wp_generate_password path below.
		}
	}

	// Fallback: hash a strong random password down to 32 hex chars.
	$seed = wp_generate_password( 64, false, false );
	return substr( md5( $seed . microtime() . wp_rand() ), 0, 32 );
}

/**
 * Ensure a key exists as early as possible so the verification file is always
 * serveable. Cheap (a single option read once the key is set).
 */
function themify_indexnow_ensure_key() {
	themify_indexnow_key();
}
add_action( 'init', 'themify_indexnow_ensure_key' );

/**
 * The public URL of the IndexNow key verification file.
 *
 * @return string
 */
function themify_indexnow_key_url() {
	return home_url( '/' . themify_indexnow_key() . '.txt' );
}

/**
 * Serve the IndexNow key verification file at /{key}.txt.
 *
 * IndexNow requires a text file at the site root whose name is the key and
 * whose body is the key. We intercept the front-end request for exactly that
 * path and emit the key as text/plain, then exit. Because the filename IS the
 * secret, only a caller who already knows the key can fetch it — there is no
 * information disclosure.
 *
 * Runs on template_redirect (front-end only), so it never touches the admin.
 */
function themify_indexnow_serve_key_file() {
	$key = themify_indexnow_key();
	if ( '' === $key ) {
		return;
	}

	// Compare against the request path only (ignore query string / host).
	$request = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Parsed to a path and compared, not stored/echoed.
	$path    = (string) wp_parse_url( $request, PHP_URL_PATH );
	$path    = rawurldecode( $path );

	if ( '/' . $key . '.txt' !== $path ) {
		return;
	}

	// Emit the key as a plain-text body and stop WordPress rendering a template.
	nocache_headers();
	if ( ! headers_sent() ) {
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'X-Robots-Tag: noindex' );
	}
	echo esc_html( $key );
	exit;
}
add_action( 'template_redirect', 'themify_indexnow_serve_key_file' );

/* ============================================================ SUBMISSION LOG */

/**
 * Read the submission log (newest first).
 *
 * @return array<int,array> Log rows.
 */
function themify_indexing_get_log() {
	$log = get_option( THEMIFY_INDEXING_LOG_OPT, array() );
	return is_array( $log ) ? $log : array();
}

/**
 * Prepend one entry to the submission log and cap its length.
 *
 * @param array $entry {
 *   @type string $backend One of 'indexnow' | 'google'.
 *   @type int    $count   Number of URLs in the submission.
 *   @type int    $status  HTTP status code (0 when the request never completed).
 *   @type bool   $ok      Whether the submission succeeded.
 *   @type string $message Short human-readable result/error.
 *   @type int    $time    Unix timestamp.
 * }
 */
function themify_indexing_log( array $entry ) {
	$entry = wp_parse_args( $entry, array(
		'backend' => 'indexnow',
		'count'   => 0,
		'status'  => 0,
		'ok'      => false,
		'message' => '',
		'time'    => time(),
	) );

	// Normalise types so the report renderer can trust them.
	$entry['backend'] = in_array( $entry['backend'], array( 'indexnow', 'google' ), true ) ? $entry['backend'] : 'indexnow';
	$entry['count']   = max( 0, (int) $entry['count'] );
	$entry['status']  = (int) $entry['status'];
	$entry['ok']      = (bool) $entry['ok'];
	$entry['message'] = sanitize_text_field( (string) $entry['message'] );
	$entry['time']    = (int) $entry['time'];

	$log = themify_indexing_get_log();
	array_unshift( $log, $entry );

	if ( count( $log ) > THEMIFY_INDEXING_LOG_MAX ) {
		$log = array_slice( $log, 0, THEMIFY_INDEXING_LOG_MAX );
	}

	update_option( THEMIFY_INDEXING_LOG_OPT, $log, false );
}

/**
 * Reduce an arbitrary list to a clean array of absolute, http(s) URLs on this
 * site's host, de-duplicated. Protects every submission path from bad input.
 *
 * @param mixed $urls A URL string or list of URL strings.
 * @return string[] Sanitized absolute URLs (may be empty).
 */
function themify_indexing_clean_urls( $urls ) {
	$urls  = is_array( $urls ) ? $urls : array( $urls );
	$host  = themify_site_host();
	$clean = array();

	foreach ( $urls as $url ) {
		$url = esc_url_raw( trim( (string) $url ) );
		if ( '' === $url ) {
			continue;
		}
		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		if ( 'http' !== $scheme && 'https' !== $scheme ) {
			continue;
		}
		// Only submit URLs that belong to this site (IndexNow rejects others,
		// and we should never leak / ping foreign hosts on the owner's behalf).
		$url_host = preg_replace( '/^www\./i', '', (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( $host && strtolower( $url_host ) !== strtolower( $host ) ) {
			continue;
		}
		$clean[ $url ] = $url;
	}

	return array_values( $clean );
}

/* ============================================================ INDEXNOW SUBMIT */

/**
 * Submit a batch of URLs to IndexNow.
 *
 * Builds the documented JSON payload ({ host, key, keyLocation, urlList }) and
 * POSTs it to the IndexNow aggregator endpoint, which fans it out to every
 * participating search engine. Each call is logged. A short transient collapses
 * accidental duplicate submissions of the same URL set.
 *
 * Safe to call with a single URL string or an array of URLs. Never throws.
 *
 * @param array|string $urls Absolute URL or list of absolute URLs.
 * @return array {
 *   @type bool   $ok      Whether IndexNow accepted the batch.
 *   @type int    $status  HTTP status code (0 on transport failure).
 *   @type int    $count   Number of URLs submitted.
 *   @type string $message Human-readable result.
 * }
 */
function themify_indexnow_submit( $urls ) {
	$urls = themify_indexing_clean_urls( $urls );

	if ( empty( $urls ) ) {
		return array(
			'ok'      => false,
			'status'  => 0,
			'count'   => 0,
			'message' => __( 'No valid URLs to submit.', 'themify' ),
		);
	}

	// IndexNow accepts up to 10,000 URLs per request; stay well under that.
	if ( count( $urls ) > 1000 ) {
		$urls = array_slice( $urls, 0, 1000 );
	}

	$key  = themify_indexnow_key();
	$host = themify_site_host();

	if ( '' === $key || '' === $host ) {
		return array(
			'ok'      => false,
			'status'  => 0,
			'count'   => count( $urls ),
			'message' => __( 'IndexNow key or site host is unavailable.', 'themify' ),
		);
	}

	// De-duplicate identical batches fired in quick succession (autosave churn).
	$dedupe_key = 'themify_indexnow_' . md5( implode( "\n", $urls ) );
	if ( get_transient( $dedupe_key ) ) {
		return array(
			'ok'      => true,
			'status'  => 0,
			'count'   => count( $urls ),
			'message' => __( 'Skipped — identical batch was just submitted.', 'themify' ),
		);
	}
	set_transient( $dedupe_key, 1, 5 * MINUTE_IN_SECONDS );

	$payload = array(
		'host'        => $host,
		'key'         => $key,
		'keyLocation' => themify_indexnow_key_url(),
		'urlList'     => $urls,
	);

	$response = themify_remote_json( 'https://api.indexnow.org/indexnow', array(
		'method'  => 'POST',
		'headers' => array(
			'Content-Type' => 'application/json; charset=utf-8',
			'Accept'       => 'application/json',
		),
		'body'    => wp_json_encode( $payload ),
	) );

	if ( is_wp_error( $response ) ) {
		$data   = $response->get_error_data();
		$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;
		// IndexNow returns 200/202 on success; 400/403/422/429 carry meaning.
		$message = themify_indexnow_status_message( $status, $response->get_error_message() );

		themify_indexing_log( array(
			'backend' => 'indexnow',
			'count'   => count( $urls ),
			'status'  => $status,
			'ok'      => false,
			'message' => $message,
		) );

		return array(
			'ok'      => false,
			'status'  => $status,
			'count'   => count( $urls ),
			'message' => $message,
		);
	}

	// themify_remote_json only returns a non-error array for 2xx responses.
	$message = themify_indexnow_status_message( 200, '' );
	themify_indexing_log( array(
		'backend' => 'indexnow',
		'count'   => count( $urls ),
		'status'  => 200,
		'ok'      => true,
		'message' => $message,
	) );

	return array(
		'ok'      => true,
		'status'  => 200,
		'count'   => count( $urls ),
		'message' => $message,
	);
}

/**
 * Map an IndexNow HTTP status to a short, human-readable explanation.
 *
 * @param int    $status   HTTP status code.
 * @param string $fallback Fallback message (e.g. a transport error).
 * @return string
 */
function themify_indexnow_status_message( $status, $fallback = '' ) {
	switch ( (int) $status ) {
		case 200:
			return __( 'Submitted — URLs accepted.', 'themify' );
		case 202:
			return __( 'Accepted — key validation pending.', 'themify' );
		case 400:
			return __( 'Bad request — invalid URL format.', 'themify' );
		case 403:
			return __( 'Forbidden — key not valid for this host.', 'themify' );
		case 422:
			return __( 'Unprocessable — URL does not match the host or key.', 'themify' );
		case 429:
			return __( 'Rate limited — too many requests, try again later.', 'themify' );
		case 0:
			return '' !== $fallback ? $fallback : __( 'Request failed to complete.', 'themify' );
		default:
			return '' !== $fallback
				? $fallback
				: sprintf(
					/* translators: %d: HTTP status code */
					__( 'IndexNow responded with HTTP %d.', 'themify' ),
					(int) $status
				);
	}
}

/* ============================================================ GOOGLE INDEXING */

/**
 * Notify Google's Indexing API that a URL was updated or deleted.
 *
 * This is entirely optional and self-guarding: it does nothing unless a
 * companion integration has defined themify_google_access_token() (which must
 * return a valid OAuth2 bearer token for the indexing scope) and that call
 * yields a token. The Google Indexing API is officially limited to JobPosting
 * and BroadcastEvent pages, but the endpoint is here for sites that qualify.
 *
 * Accepts either a single URL string or an array of URLs (the sitemap module
 * hands us the same URL list it gives IndexNow), so callers can use one shape.
 *
 * @param array|string $url  Absolute URL, or a list of URLs.
 * @param string       $type 'URL_UPDATED' | 'URL_DELETED'.
 * @return array Result summary (ok/status/count/message).
 */
function themify_google_index_notify( $url, $type = 'URL_UPDATED' ) {
	$noop = array(
		'ok'      => false,
		'status'  => 0,
		'count'   => 0,
		'message' => __( 'Google Indexing API is not configured.', 'themify' ),
	);

	// Hard requirement: a token provider must be present.
	if ( ! function_exists( 'themify_google_access_token' ) ) {
		return $noop;
	}

	$type = in_array( $type, array( 'URL_UPDATED', 'URL_DELETED' ), true ) ? $type : 'URL_UPDATED';
	$urls = themify_indexing_clean_urls( $url );
	if ( empty( $urls ) ) {
		return $noop;
	}

	// Fetch an access token for the indexing scope. The provider is responsible
	// for its own caching; we just consume the token.
	$token = themify_google_access_token( 'https://www.googleapis.com/auth/indexing' );
	if ( is_wp_error( $token ) || ! is_string( $token ) || '' === $token ) {
		return array(
			'ok'      => false,
			'status'  => 0,
			'count'   => count( $urls ),
			'message' => __( 'Could not obtain a Google access token.', 'themify' ),
		);
	}

	$ok_count  = 0;
	$last_code = 0;
	$last_msg  = '';

	// The publish endpoint takes one URL per request.
	foreach ( $urls as $one ) {
		$response = themify_remote_json( 'https://indexing.googleapis.com/v3/urlNotifications:publish', array(
			'method'  => 'POST',
			'headers' => array(
				'Content-Type'  => 'application/json; charset=utf-8',
				'Authorization' => 'Bearer ' . $token,
			),
			'body'    => wp_json_encode( array(
				'url'  => $one,
				'type' => $type,
			) ),
		) );

		if ( is_wp_error( $response ) ) {
			$data      = $response->get_error_data();
			$last_code = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;
			$last_msg  = $response->get_error_message();
			continue;
		}
		$ok_count++;
		$last_code = 200;
	}

	$ok      = $ok_count > 0;
	$message = $ok
		? sprintf(
			/* translators: 1: succeeded count, 2: total count */
			__( 'Google notified: %1$d of %2$d URLs.', 'themify' ),
			$ok_count,
			count( $urls )
		)
		: ( '' !== $last_msg ? $last_msg : __( 'Google Indexing API rejected the request.', 'themify' ) );

	themify_indexing_log( array(
		'backend' => 'google',
		'count'   => count( $urls ),
		'status'  => $last_code,
		'ok'      => $ok,
		'message' => $message,
	) );

	return array(
		'ok'      => $ok,
		'status'  => $last_code,
		'count'   => count( $urls ),
		'message' => $message,
	);
}

/* ============================================================ ADMIN PAGE */

/**
 * Register the "Indexing" submenu (position 30).
 */
themify_register_admin_page( array(
	'slug'       => 'themify-indexing',
	'title'      => __( 'Indexing Report', 'themify' ),
	'menu_title' => __( 'Indexing Report', 'themify' ),
	'callback'   => 'themify_indexing_page',
	'position'   => 20,
) );

/**
 * Add the Indexing card to the dashboard grid.
 */
add_filter( 'themify_dashboard_cards', 'themify_indexing_dashboard_card' );

/**
 * Append the Indexing dashboard card.
 *
 * @param array $cards Existing cards.
 * @return array
 */
function themify_indexing_dashboard_card( $cards ) {
	$cards[] = array(
		'slug'     => 'themify-indexing',
		'title'    => __( 'Indexing', 'themify' ),
		'desc'     => __( 'IndexNow + Google instant indexing', 'themify' ),
		'icon'     => 'dashicons-admin-site-alt3',
		'position' => 20,
	);
	return $cards;
}

/* ------------------------------------------------------------ AJAX RUNNERS */

/**
 * Shared guard for the indexing AJAX runners: nonce + capability.
 *
 * @return void Sends a JSON error and dies on failure.
 */
function themify_indexing_ajax_guard() {
	check_ajax_referer( 'themify_admin', 'nonce' );
	if ( ! current_user_can( THEMIFY_CAP ) ) {
		wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'themify' ) ) );
	}
}

/**
 * Simple per-action rate limit so the buttons can't be hammered into hitting
 * IndexNow's rate limits. Returns the remaining seconds when throttled, else 0.
 *
 * @param string $bucket Unique action bucket.
 * @param int    $window Seconds between allowed runs.
 * @return int Seconds remaining before the next allowed run (0 = allowed now).
 */
function themify_indexing_rate_limited( $bucket, $window = 30 ) {
	$key  = 'themify_idx_rl_' . $bucket;
	$last = (int) get_transient( $key );
	if ( $last ) {
		return $window; // A live transient means the window has not elapsed.
	}
	set_transient( $key, time(), $window );
	return 0;
}

/**
 * AJAX: submit a set of site URLs to IndexNow.
 *
 * Driven by the .tf-run buttons. The button's data-payload selects the mode:
 *   - 'home'   → just the site home URL,
 *   - 'latest' → up to the latest 50 public URLs (via themify_all_public_urls()
 *                when available, else a recent-posts fallback).
 */
function themify_indexing_ajax_run() {
	themify_indexing_ajax_guard();

	$mode = isset( $_POST['payload'] ) ? sanitize_key( wp_unslash( $_POST['payload'] ) ) : 'latest';
	$mode = in_array( $mode, array( 'home', 'latest', 'notindexed' ), true ) ? $mode : 'latest';

	// Rate limit both buttons together — they hit the same external API.
	$wait = themify_indexing_rate_limited( 'run', 30 );
	if ( $wait > 0 ) {
		wp_send_json_error( array(
			'message' => sprintf(
				/* translators: %d: seconds */
				__( 'Please wait a moment before submitting again (rate limited ~%ds).', 'themify' ),
				(int) $wait
			),
		) );
	}

	if ( 'home' === $mode ) {
		$urls = array( home_url( '/' ) );
	} elseif ( 'notindexed' === $mode ) {
		$urls = themify_index_not_indexed_urls();
		if ( empty( $urls ) ) {
			wp_send_json_error( array( 'message' => __( 'No not-indexed URLs found — run a scan first.', 'themify' ) ) );
		}
	} else {
		$urls = themify_indexing_collect_urls( 50 );
	}

	$result = themify_indexnow_submit( $urls );
	if ( 'notindexed' === $mode && ! empty( $result['ok'] ) ) {
		themify_index_record_submit( $urls, 'indexnow' );
	}
	wp_send_json_success( array( 'html' => themify_indexing_notice_html( $result ) ) );
}
add_action( 'wp_ajax_themify_indexnow_run', 'themify_indexing_ajax_run' );

/**
 * Collect up to $limit public URLs, preferring the sitemap module's richer map
 * and falling back to recent published posts when it is unavailable.
 *
 * @param int $limit Max URLs.
 * @return string[]
 */
function themify_indexing_collect_urls( $limit = 50 ) {
	$limit = max( 1, (int) $limit );

	if ( function_exists( 'themify_all_public_urls' ) ) {
		$urls = themify_all_public_urls( $limit );
	} else {
		// Fallback: home + most recent published posts.
		$urls  = array( home_url( '/' ) );
		$query = new WP_Query( array(
			'post_type'              => 'post',
			'post_status'            => 'publish',
			'posts_per_page'         => $limit,
			'orderby'                => 'modified',
			'order'                  => 'DESC',
			'has_password'           => false,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'ignore_sticky_posts'    => true,
		) );
		foreach ( $query->posts as $post_id ) {
			$link = get_permalink( $post_id );
			if ( $link ) {
				$urls[] = $link;
			}
		}
	}

	$urls = themify_indexing_clean_urls( $urls );
	if ( count( $urls ) > $limit ) {
		$urls = array_slice( $urls, 0, $limit );
	}
	return $urls;
}

/**
 * Build the small result panel + refreshed log table returned to the AJAX
 * caller after a submission.
 *
 * @param array $result Submission result summary.
 * @param array $log    Full submission log (already refreshed).
 * @return string HTML.
 */
function themify_indexing_result_html( array $result, array $log ) {
	$ok      = ! empty( $result['ok'] );
	$class   = $ok ? 'tf-notice--info' : 'tf-notice--warn';
	$count   = isset( $result['count'] ) ? (int) $result['count'] : 0;
	$message = isset( $result['message'] ) ? (string) $result['message'] : '';

	$html  = '<div class="tf-notice ' . esc_attr( $class ) . '">';
	$html .= esc_html( sprintf(
		/* translators: 1: URL count, 2: result message */
		_n( 'Submitted %1$d URL. %2$s', 'Submitted %1$d URLs. %2$s', $count, 'themify' ),
		$count,
		$message
	) );
	$html .= '</div>';

	$html .= themify_indexing_log_table_html( $log );

	return $html;
}

/**
 * Render the submission log as a .tf-table with status badges.
 *
 * @param array $log Submission log rows (newest first).
 * @return string HTML.
 */
function themify_indexing_log_table_html( array $log ) {
	if ( empty( $log ) ) {
		return '<p class="tf-card__desc">' . esc_html__( 'No submissions yet.', 'themify' ) . '</p>';
	}

	$backends = array(
		'indexnow' => __( 'IndexNow', 'themify' ),
		'google'   => __( 'Google', 'themify' ),
	);

	$html  = '<table class="tf-table">';
	$html .= '<thead><tr>';
	$html .= '<th>' . esc_html__( 'When', 'themify' ) . '</th>';
	$html .= '<th>' . esc_html__( 'Service', 'themify' ) . '</th>';
	$html .= '<th>' . esc_html__( 'URLs', 'themify' ) . '</th>';
	$html .= '<th>' . esc_html__( 'Status', 'themify' ) . '</th>';
	$html .= '<th>' . esc_html__( 'Result', 'themify' ) . '</th>';
	$html .= '</tr></thead><tbody>';

	// Show at most the most recent 50 rows in the UI (log stores up to 100).
	$rows = array_slice( $log, 0, 50 );

	foreach ( $rows as $row ) {
		$backend = isset( $row['backend'] ) ? (string) $row['backend'] : 'indexnow';
		$label   = isset( $backends[ $backend ] ) ? $backends[ $backend ] : $backend;
		$count   = isset( $row['count'] ) ? (int) $row['count'] : 0;
		$status  = isset( $row['status'] ) ? (int) $row['status'] : 0;
		$ok      = ! empty( $row['ok'] );
		$message = isset( $row['message'] ) ? (string) $row['message'] : '';
		$time    = isset( $row['time'] ) ? (int) $row['time'] : 0;

		if ( $ok ) {
			$badge_class = 'tf-badge--ok';
			$badge_text  = $status ? (string) $status : __( 'OK', 'themify' );
		} elseif ( $status >= 400 || 0 === $status ) {
			$badge_class = 'tf-badge--bad';
			$badge_text  = $status ? (string) $status : __( 'Failed', 'themify' );
		} else {
			$badge_class = 'tf-badge--warn';
			$badge_text  = (string) $status;
		}

		$html .= '<tr>';
		$html .= '<td>' . esc_html( themify_time_ago( $time ) ) . '</td>';
		$html .= '<td><span class="tf-badge tf-badge--muted">' . esc_html( $label ) . '</span></td>';
		$html .= '<td>' . esc_html( number_format_i18n( $count ) ) . '</td>';
		$html .= '<td><span class="tf-badge ' . esc_attr( $badge_class ) . '">' . esc_html( $badge_text ) . '</span></td>';
		$html .= '<td>' . esc_html( $message ) . '</td>';
		$html .= '</tr>';
	}

	$html .= '</tbody></table>';

	return $html;
}

/**
 * A lightweight notice-only result panel (the report page shows its own data,
 * so the full log table is not re-rendered into AJAX targets).
 *
 * @param array $result Submission result summary.
 * @return string HTML.
 */
function themify_indexing_notice_html( array $result ) {
	$ok      = ! empty( $result['ok'] );
	$class   = $ok ? 'tf-notice--info' : 'tf-notice--warn';
	$count   = isset( $result['count'] ) ? (int) $result['count'] : 0;
	$message = isset( $result['message'] ) ? (string) $result['message'] : '';

	return '<div class="tf-notice ' . esc_attr( $class ) . '">' . esc_html( sprintf(
		/* translators: 1: URL count, 2: result message */
		_n( 'Submitted %1$d URL. %2$s', 'Submitted %1$d URLs. %2$s', $count, 'themify' ),
		$count,
		$message
	) ) . '</div>';
}

/* ============================================================ INDEX SCAN */

/**
 * The stored scan blob (queue/results/timestamps), always an array.
 *
 * @return array
 */
function themify_index_scan_data() {
	$scan = get_option( THEMIFY_INDEX_SCAN_OPT, array() );
	return is_array( $scan ) ? $scan : array();
}

/**
 * The per-URL results of the latest scan.
 *
 * @return array<string,array> url => { state, verdict, crawled, published }.
 */
function themify_index_results() {
	$scan = themify_index_scan_data();
	return isset( $scan['results'] ) && is_array( $scan['results'] ) ? $scan['results'] : array();
}

/**
 * Whether one scan row counts as indexed (Google verdict PASS).
 *
 * @param array $row Result row.
 * @return bool
 */
function themify_index_is_indexed( $row ) {
	return isset( $row['verdict'] ) && 'PASS' === $row['verdict'];
}

/**
 * All not-indexed URLs from the latest scan.
 *
 * @return string[]
 */
function themify_index_not_indexed_urls() {
	$out = array();
	foreach ( themify_index_results() as $url => $row ) {
		if ( ! themify_index_is_indexed( (array) $row ) ) {
			$out[] = (string) $url;
		}
	}
	return $out;
}

/**
 * Publish timestamp for a site URL (0 when it does not map to a post/page).
 *
 * @param string $url Absolute URL.
 * @return int
 */
function themify_index_published_ts( $url ) {
	$post_id = url_to_postid( $url );
	if ( ! $post_id ) {
		return 0;
	}
	$ts = get_post_time( 'U', true, $post_id );
	return $ts ? (int) $ts : 0;
}

/**
 * URL => last per-engine submission timestamps.
 *
 * @return array
 */
function themify_index_submits() {
	$map = get_option( THEMIFY_INDEX_SUBMITS_OPT, array() );
	return is_array( $map ) ? $map : array();
}

/**
 * Record "this URL was just submitted to {engine}" for the status column.
 *
 * @param array|string $urls   URL or list of URLs.
 * @param string       $engine 'google' | 'indexnow'.
 */
function themify_index_record_submit( $urls, $engine ) {
	$engine = in_array( $engine, array( 'google', 'indexnow' ), true ) ? $engine : 'indexnow';
	$map    = themify_index_submits();

	foreach ( (array) $urls as $url ) {
		$url = (string) $url;
		if ( '' === $url ) {
			continue;
		}
		if ( ! isset( $map[ $url ] ) || ! is_array( $map[ $url ] ) ) {
			$map[ $url ] = array();
		}
		$map[ $url ][ $engine ] = time();
	}

	if ( count( $map ) > 500 ) {
		$map = array_slice( $map, -500, null, true );
	}
	update_option( THEMIFY_INDEX_SUBMITS_OPT, $map, false );
}

/**
 * Inspect one URL with the Search Console URL Inspection API.
 *
 * @param string $token Access token (webmasters scope).
 * @param string $site  The exact GSC property URL.
 * @param string $url   URL to inspect.
 * @return array|WP_Error { state, verdict, crawled }.
 */
function themify_index_inspect( $token, $site, $url ) {
	$res = themify_remote_json( 'https://searchconsole.googleapis.com/v1/urlInspection/index:inspect', array(
		'method'  => 'POST',
		'timeout' => 30,
		'headers' => array(
			'Authorization' => 'Bearer ' . $token,
			'Content-Type'  => 'application/json',
			'Accept'        => 'application/json',
		),
		'body'    => wp_json_encode( array(
			'inspectionUrl' => $url,
			'siteUrl'       => $site,
		) ),
	) );

	if ( is_wp_error( $res ) ) {
		return $res;
	}

	$idx = isset( $res['inspectionResult']['indexStatusResult'] ) && is_array( $res['inspectionResult']['indexStatusResult'] )
		? $res['inspectionResult']['indexStatusResult']
		: array();

	$crawl_raw = isset( $idx['lastCrawlTime'] ) ? strtotime( (string) $idx['lastCrawlTime'] ) : 0;

	return array(
		'state'   => isset( $idx['coverageState'] ) ? (string) $idx['coverageState'] : __( 'Unknown', 'themify' ),
		'verdict' => isset( $idx['verdict'] ) ? (string) $idx['verdict'] : 'VERDICT_UNSPECIFIED',
		'crawled' => $crawl_raw ? (int) $crawl_raw : 0,
	);
}

/**
 * AJAX: run one batch of the index scan. The client JS calls this repeatedly
 * with an increasing offset until `done` is true — keeping every request far
 * below PHP/HTTP timeouts regardless of how many URLs the site has.
 */
function themify_index_scan_ajax() {
	themify_indexing_ajax_guard();

	if ( ! function_exists( 'themify_google_access_token' ) || ! function_exists( 'themify_analytics_has_creds' ) || ! themify_analytics_has_creds() ) {
		wp_send_json_error( array( 'message' => __( 'Google credentials are missing. Add them under Themixify → Analytics first.', 'themify' ) ) );
	}

	$offset = isset( $_POST['offset'] ) ? max( 0, (int) $_POST['offset'] ) : 0;
	$scan   = themify_index_scan_data();

	if ( 0 === $offset ) {
		$queue = themify_indexing_collect_urls( 500 );
		$scan  = array(
			'queue'   => $queue,
			'results' => array(),
			'total'   => count( $queue ),
			'started' => time(),
			'time'    => isset( $scan['time'] ) ? (int) $scan['time'] : 0,
		);
	}

	$queue = isset( $scan['queue'] ) && is_array( $scan['queue'] ) ? $scan['queue'] : array();
	$total = count( $queue );
	if ( ! $total ) {
		wp_send_json_error( array( 'message' => __( 'No public URLs found to scan.', 'themify' ) ) );
	}

	// URL Inspection accepts both webmasters scopes; reuse the read-only one
	// so the same service-account setup as the Analytics page just works.
	$token = themify_google_access_token( array( THEMIFY_GSC_SCOPE ) );
	if ( is_wp_error( $token ) ) {
		wp_send_json_error( array( 'message' => $token->get_error_message() ) );
	}

	$site  = function_exists( 'themify_gsc_site_url' ) ? themify_gsc_site_url() : trailingslashit( home_url( '/' ) );
	$batch = array_slice( $queue, $offset, 6 );

	foreach ( $batch as $url ) {
		$row = themify_index_inspect( $token, $site, $url );
		if ( is_wp_error( $row ) ) {
			$scan['results'][ $url ] = array(
				'state'     => __( 'Inspection failed', 'themify' ),
				'verdict'   => 'ERROR',
				'crawled'   => 0,
				'published' => themify_index_published_ts( $url ),
			);
			continue;
		}
		$row['published']        = themify_index_published_ts( $url );
		$scan['results'][ $url ] = $row;
	}

	$next = $offset + count( $batch );
	$done = $next >= $total;
	if ( $done ) {
		$scan['time'] = time();
		unset( $scan['queue'] );
	}
	update_option( THEMIFY_INDEX_SCAN_OPT, $scan, false );

	wp_send_json_success( array(
		'done'  => $done,
		'next'  => $next,
		'total' => $total,
	) );
}
add_action( 'wp_ajax_themify_index_scan', 'themify_index_scan_ajax' );

/**
 * AJAX: submit one URL to Google and/or IndexNow (the per-row action buttons).
 */
function themify_index_url_ajax() {
	themify_indexing_ajax_guard();

	$url    = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
	$engine = isset( $_POST['engine'] ) ? sanitize_key( wp_unslash( $_POST['engine'] ) ) : 'both';
	$engine = in_array( $engine, array( 'google', 'indexnow', 'both' ), true ) ? $engine : 'both';

	$urls = themify_indexing_clean_urls( $url );
	if ( empty( $urls ) ) {
		wp_send_json_error( array( 'message' => __( 'Invalid URL.', 'themify' ) ) );
	}
	$url = $urls[0];

	$ok    = false;
	$parts = array();

	if ( 'google' === $engine || 'both' === $engine ) {
		$g = themify_google_index_notify( $url );
		if ( ! empty( $g['ok'] ) ) {
			themify_index_record_submit( $url, 'google' );
			$ok = true;
		}
		$parts[] = 'Google: ' . (string) $g['message'];
	}
	if ( 'indexnow' === $engine || 'both' === $engine ) {
		$i = themify_indexnow_submit( $url );
		if ( ! empty( $i['ok'] ) ) {
			themify_index_record_submit( $url, 'indexnow' );
			$ok = true;
		}
		$parts[] = 'IndexNow: ' . (string) $i['message'];
	}

	$payload = array( 'message' => implode( ' ', $parts ) );
	if ( $ok ) {
		wp_send_json_success( $payload );
	}
	wp_send_json_error( $payload );
}
add_action( 'wp_ajax_themify_index_url_run', 'themify_index_url_ajax' );

/**
 * AJAX: submit the sitemap to Google Search Console via the Sitemaps API.
 */
function themify_sitemap_submit_ajax() {
	themify_indexing_ajax_guard();

	if ( ! function_exists( 'themify_google_access_token' ) ) {
		wp_send_json_error( array( 'message' => __( 'Google credentials are missing. Add them under Themixify → Analytics first.', 'themify' ) ) );
	}

	$sitemap = function_exists( 'themify_sitemap_url' ) ? themify_sitemap_url() : home_url( '/wp-sitemap.xml' );
	$site    = function_exists( 'themify_gsc_site_url' ) ? themify_gsc_site_url() : trailingslashit( home_url( '/' ) );

	// Submitting a sitemap is a write, so it needs the full webmasters scope.
	$token = themify_google_access_token( array( 'https://www.googleapis.com/auth/webmasters' ) );
	if ( is_wp_error( $token ) ) {
		wp_send_json_error( array( 'message' => $token->get_error_message() ) );
	}

	$endpoint = 'https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode( $site ) . '/sitemaps/' . rawurlencode( $sitemap );
	$res      = themify_remote_json( $endpoint, array(
		'method'  => 'PUT',
		'timeout' => 25,
		'headers' => array(
			'Authorization' => 'Bearer ' . $token,
			'Accept'        => 'application/json',
		),
	) );

	if ( is_wp_error( $res ) ) {
		wp_send_json_error( array( 'message' => $res->get_error_message() ) );
	}

	wp_send_json_success( array(
		'html' => '<div class="tf-notice tf-notice--info">' . esc_html( sprintf(
			/* translators: %s: sitemap URL */
			__( 'Sitemap submitted to Google Search Console: %s', 'themify' ),
			$sitemap
		) ) . '</div>',
	) );
}
add_action( 'wp_ajax_themify_sitemap_submit', 'themify_sitemap_submit_ajax' );

/* ------------------------------------------------------------ MANUAL SUBMIT */

/**
 * Handle the manual "paste URLs" POST form. Verified with its own nonce + cap.
 *
 * @return array|null Result summary when a valid submit happened, else null.
 */
function themify_indexing_handle_manual() {
	if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
		return null;
	}
	if ( ! isset( $_POST['themify_indexing_manual'] ) ) {
		return null;
	}
	if ( ! current_user_can( THEMIFY_CAP ) ) {
		return null;
	}
	$nonce = isset( $_POST['themify_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['themify_nonce'] ) ) : '';
	if ( ! wp_verify_nonce( $nonce, 'themify_indexing_manual' ) ) {
		return null;
	}

	$raw = isset( $_POST['themify_urls'] ) ? sanitize_textarea_field( wp_unslash( $_POST['themify_urls'] ) ) : '';
	$raw = str_replace( array( "\r\n", "\r" ), "\n", $raw );
	$urls = array_filter( array_map( 'trim', explode( "\n", $raw ) ) );

	if ( empty( $urls ) ) {
		return array(
			'ok'      => false,
			'status'  => 0,
			'count'   => 0,
			'message' => __( 'Please paste at least one URL.', 'themify' ),
		);
	}

	// Cap manual pastes to a sane batch.
	if ( count( $urls ) > 500 ) {
		$urls = array_slice( $urls, 0, 500 );
	}

	return themify_indexnow_submit( $urls );
}

/* ------------------------------------------------------------ PAGE RENDER */

/**
 * Print the indexing-report CSS + JS (scan loop, per-row submits, search
 * filter, copy-to-clipboard). Complements the shared tfx design system
 * printed by themify_analytics_print_assets().
 */
function themify_indexing_print_assets() {
	$nonce = wp_create_nonce( 'themify_admin' );
	?>
	<style>
	body[class*="themify-indexing"] #wpcontent{background:#f3f8f5}
	.tfi-pageicon{width:46px;height:46px;border-radius:13px;background:#1e8f38;display:flex;align-items:center;justify-content:center;flex:0 0 auto;box-shadow:0 5px 12px rgba(30,143,56,.35)}
	.tfi-pageicon .dashicons{color:#fff;font-size:23px;width:23px;height:23px}
	.tfi-lastscan{display:inline-flex;align-items:center;gap:5px;color:#5a6b62;font-size:13px}
	.tfi-lastscan .dashicons{font-size:16px;width:16px;height:16px;color:#8fa096}
	.tfi-btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;border:none;border-radius:10px;padding:10px 18px;font-size:13px;font-weight:700;cursor:pointer;text-decoration:none;line-height:1.3;color:#fff}
	.tfi-btn .dashicons{font-size:16px;width:16px;height:16px}
	.tfi-btn--green{background:#1e8f38;box-shadow:0 4px 10px rgba(30,143,56,.3)}
	.tfi-btn--green:hover{background:#156b28;color:#fff}
	.tfi-btn--blue{background:linear-gradient(135deg,#2c4636,#1a2b20);width:100%;box-shadow:0 4px 10px rgba(26,43,32,.3)}
	.tfi-btn--blue:hover{color:#fff;filter:brightness(1.06)}
	.tfi-btn--orange{background:linear-gradient(135deg,#d8a713,#b8860b);width:100%;box-shadow:0 4px 10px rgba(245,158,11,.3)}
	.tfi-btn--orange:hover{color:#fff;filter:brightness(1.05)}
	.tfi-btn--red{background:#fef2f2;color:#b0281a;border:1px solid #f2cbc5;box-shadow:none}
	.tfi-btn--red:hover{background:#f8ded9;color:#8f1f14}
	.tfi-btn[disabled]{opacity:.6;cursor:default}
	.tfi-boost{margin-bottom:20px;overflow:hidden}
	.tfi-boost__head{display:flex;align-items:center;gap:12px;padding:15px 22px;background:#f0f7f2;border-bottom:1px solid #eef0f6}
	.tfi-boost__icon{width:34px;height:34px;border-radius:9px;background:#156b28;color:#fff;display:flex;align-items:center;justify-content:center;flex:0 0 auto}
	.tfi-boost__icon .dashicons{font-size:17px;width:17px;height:17px}
	.tfi-boost__head strong{display:block;font-size:14.5px;color:#1a2b20}
	.tfi-boost__head .sub{display:block;font-size:12px;color:#8fa096;margin-top:1px}
	.tfi-boost__grid{display:grid;grid-template-columns:1fr 1fr 1fr}
	@media(max-width:1100px){.tfi-boost__grid{grid-template-columns:1fr}}
	.tfi-tool{padding:20px 22px;border-right:1px solid #eef4f0;display:flex;flex-direction:column;gap:12px}
	.tfi-tool:last-child{border-right:none}
	.tfi-tool__head{display:flex;align-items:center;gap:10px}
	.tfi-tool__icon{width:34px;height:34px;border-radius:9px;display:flex;align-items:center;justify-content:center;color:#fff;flex:0 0 auto}
	.tfi-tool__icon .dashicons{font-size:17px;width:17px;height:17px}
	.tfi-tool__head b{font-size:13.5px;color:#1a2b20;display:block}
	.tfi-tool__head span{font-size:11.5px;color:#8fa096;display:block}
	.tfi-tool p{margin:0;font-size:12.5px;color:#5a6b62;line-height:1.6;flex:1 1 auto}
	.tfi-feedbox{display:flex;align-items:center;gap:8px;border:1px solid #dbe4de;background:#f7faf8;border-radius:9px;padding:9px 12px;font-family:Consolas,Monaco,monospace;font-size:12.5px;color:#b8860b}
	.tfi-feedbox .dashicons{font-size:15px;width:15px;height:15px;color:#d8a713}
	.tfi-active{display:flex;align-items:center;gap:6px;font-size:12px;font-weight:600;color:#1e8f38;background:#e3f5e8;border-radius:8px;padding:8px 12px}
	.tfi-active .dashicons{font-size:15px;width:15px;height:15px}
	.tfi-stat{display:flex;align-items:center;gap:14px;padding:20px 22px}
	.tfi-stat__icon{width:44px;height:44px;border-radius:11px;display:flex;align-items:center;justify-content:center;flex:0 0 auto}
	.tfi-stat__icon .dashicons{font-size:20px;width:20px;height:20px}
	.tfi-stat__label{font-size:11px;font-weight:700;color:#8fa096;text-transform:uppercase;letter-spacing:.6px}
	.tfi-stat__num{font-size:26px;font-weight:800;color:#1a2b20;line-height:1.15}
	.tfi-donutwrap{display:flex;flex-direction:column;align-items:center;justify-content:center;padding:36px 22px}
	.tfi-donut{width:210px;height:210px;border-radius:50%;display:flex;align-items:center;justify-content:center}
	.tfi-donut__hole{width:150px;height:150px;border-radius:50%;background:#fff;display:flex;flex-direction:column;align-items:center;justify-content:center}
	.tfi-donut__pct{font-size:27px;font-weight:800;color:#1a2b20}
	.tfi-donut__lbl{font-size:12px;color:#8fa096;margin-top:2px}
	.tfi-legend{display:flex;gap:24px;margin-top:24px;font-size:13px;color:#43564a}
	.tfi-dot{display:inline-block;width:9px;height:9px;border-radius:50%;margin-right:6px}
	.tfi-covbody{padding:6px 22px 18px}
	.tfi-cov{padding:10px 0;cursor:pointer;border-radius:6px}
	.tfi-cov__line{display:flex;justify-content:space-between;gap:12px;font-size:13px;color:#33463a;font-weight:600;margin-bottom:7px}
	.tfi-cov__val--green{color:#1e8f38;font-weight:700}
	.tfi-cov__val--amber{color:#b8860b;font-weight:700}
	.tfi-note{display:flex;align-items:center;gap:6px;color:#8fa096;font-size:12px;margin-top:12px}
	.tfi-note .dashicons{font-size:14px;width:14px;height:14px}
	.tfi-lastrow{display:flex;justify-content:space-between;align-items:center;border-top:1px solid #eef4f0;margin-top:14px;padding-top:14px;font-size:11px;color:#8fa096;text-transform:uppercase;letter-spacing:.5px;font-weight:700}
	.tfi-lastrow b{color:#1a2b20;font-size:13px;text-transform:none;letter-spacing:0}
	.tfi-searchrow{display:flex;justify-content:space-between;gap:14px;align-items:center;margin-bottom:20px;flex-wrap:wrap}
	.tfi-search{flex:0 1 420px;position:relative}
	.tfi-search .dashicons{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#8fa096;font-size:16px;width:16px;height:16px}
	.tfi-search input{width:100%;border:1px solid #dbe4de;border-radius:10px;padding:10px 14px 10px 36px;font-size:13px;background:#fff;color:#1a2b20;box-shadow:0 1px 2px rgba(16,24,40,.04)}
	.tfi-search input:focus{outline:none;border-color:#1e8f38;box-shadow:0 0 0 3px rgba(30,143,56,.12)}
	.tfi-thead{display:flex;align-items:center;gap:9px;padding:14px 22px;border-radius:13px 13px 0 0;font-weight:700;font-size:14px}
	.tfi-thead--green{background:#e3f5e8;color:#156b28}
	.tfi-thead--red{background:#fbe3e0;color:#c02626}
	.tfi-thead .dashicons{font-size:17px;width:17px;height:17px}
	.tfi-count{display:inline-flex;align-items:center;justify-content:center;min-width:26px;padding:2px 8px;border-radius:999px;font-size:12px;font-weight:800;background:#fff}
	.tfi-badge{display:inline-block;background:#fdf3d9;color:#8a6d0b;border-radius:7px;padding:3px 9px;font-size:11px;font-weight:700;max-width:175px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;vertical-align:middle}
	.tfi-sub{display:flex;align-items:center;gap:5px;font-size:11px;color:#8fa096;margin-top:5px}
	.tfi-sub .dashicons{font-size:13px;width:13px;height:13px}
	.tfi-sub--google{color:#156b28}
	.tfi-sub--inow{color:#8a6d0b}
	.tfi-act{display:inline-flex;align-items:center;gap:5px;border:1px solid #dbe4de;background:#fff;border-radius:8px;padding:6px 12px;font-size:12px;font-weight:700;color:#33463a;cursor:pointer;text-decoration:none;white-space:nowrap}
	.tfi-act:hover{border-color:#c3cfc7;color:#1a2b20}
	.tfi-act--google{background:#e3f5e8;border-color:#bfe6cb;color:#156b28}
	.tfi-act--google:hover{background:#d6eedd;color:#156b28}
	.tfi-act--inow{background:#fdf3d9;border-color:#f0dfa8;color:#8a6d0b}
	.tfi-act--inow:hover{background:#fbeec7;color:#8a6d0b}
	.tfi-act .dashicons{font-size:14px;width:14px;height:14px}
	.tfi-copy{cursor:pointer;color:inherit;background:rgba(255,255,255,.65);border:none;border-radius:7px;padding:4px 6px;display:inline-flex;margin-left:2px}
	.tfi-empty{padding:52px 22px;text-align:center;color:#5a6b62;font-size:14px}
	.tfi-empty .dashicons{font-size:34px;width:34px;height:34px;color:#c3cfc7;display:block;margin:0 auto 10px}
	.tfx-card .tfi-urlcell a{color:#24382b;text-decoration:none;font-weight:600}
	.tfx-card .tfi-urlcell a:hover{color:#1e8f38}
	.tfi-progress{display:none;margin-bottom:20px;padding:16px 22px}
	.tfi-progress.is-on{display:block}
	.tfi-progress__line{display:flex;justify-content:space-between;gap:12px;font-size:13px;color:#33463a;font-weight:600;margin-bottom:9px}
	.tfi-progress__pct{color:#1e8f38;font-weight:800}
	.tfi-progress__track{height:7px;background:#e9f0ea;border-radius:4px;overflow:hidden}
	.tfi-progress__bar{display:block;height:100%;width:0;background:linear-gradient(90deg,#1e8f38,#2a9142);border-radius:4px;transition:width .35s ease}
	.tfi-toast{position:fixed;top:44px;right:30px;background:#e3f5e8;border:1px solid #bfe6cb;color:#156b28;font-weight:700;font-size:13.5px;border-radius:12px;padding:14px 20px;box-shadow:0 12px 28px rgba(15,23,42,.16);z-index:100000;opacity:0;transform:translateY(-10px);transition:opacity .25s,transform .25s;pointer-events:none;max-width:420px}
	.tfi-toast.is-on{opacity:1;transform:translateY(0)}
	.tfi-cov{transition:background .2s;border-radius:8px}
	.tfi-cov.is-copied{background:#e3f5e8;margin:0 -10px;padding:10px 10px}
	.tfi-cov.is-copied .tfi-cov__line span:first-child{color:#156b28}
	.tfi-cov.is-copied .tfi-cov__line span:first-child::before{content:"\2713  ";font-weight:800}
	.tfi-cov.is-copied .tfi-cov__val--green,.tfi-cov.is-copied .tfi-cov__val--amber{color:#156b28}
	</style>
	<script>
	(function(){
		var TFI_NONCE = <?php echo wp_json_encode( $nonce ); ?>;
		var toastTimer = null;

		function tfiToast(msg){
			var t = document.getElementById('tfi-toast');
			if (!t) { return; }
			t.textContent = msg;
			t.classList.add('is-on');
			clearTimeout(toastTimer);
			toastTimer = setTimeout(function(){ t.classList.remove('is-on'); }, 2600);
		}

		function tfiProgress(next, total){
			var prog = document.getElementById('tfi-progress');
			if (!prog) { return; }
			prog.classList.add('is-on');
			var pct = total ? Math.round(next / total * 100) : 0;
			var pl = document.getElementById('tfi-progress-label');
			var pp = document.getElementById('tfi-progress-pct');
			var pb = document.getElementById('tfi-progress-bar');
			if (pl) { pl.textContent = 'Checking URLs… ' + next + ' / ' + total; }
			if (pp) { pp.textContent = pct + '%'; }
			if (pb) { pb.style.width = pct + '%'; }
		}

		document.addEventListener('click', function (e) {
			// Run Scan — batched loop so long scans never hit a timeout.
			var scan = e.target.closest('#tfi-scan');
			if (scan) {
				e.preventDefault();
				if (scan.disabled) { return; }
				scan.disabled = true;
				var orig = scan.innerHTML;
				var step = function (off) {
					var d = new FormData();
					d.append('action', 'themify_index_scan');
					d.append('nonce', TFI_NONCE);
					d.append('offset', off);
					fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: d })
						.then(function (r) { return r.json(); })
						.then(function (res) {
							if (!res || !res.success) {
								var m = res && res.data && res.data.message ? res.data.message : 'Scan failed.';
								var box = document.getElementById('tfi-msg');
								if (box) { box.innerHTML = '<div class="tf-notice tf-notice--warn">' + m + '</div>'; }
								var prog = document.getElementById('tfi-progress');
								if (prog) { prog.classList.remove('is-on'); }
								scan.disabled = false;
								scan.innerHTML = orig;
								return;
							}
							tfiProgress(res.data.next, res.data.total);
							if (res.data.done) {
								tfiToast('Scan complete — ' + res.data.total + ' URLs checked');
								setTimeout(function(){ location.reload(); }, 600);
							} else {
								step(res.data.next);
							}
						})
						.catch(function () {
							var prog = document.getElementById('tfi-progress');
							if (prog) { prog.classList.remove('is-on'); }
							scan.disabled = false;
							scan.innerHTML = orig;
						});
				};
				scan.innerHTML = '<span class="dashicons dashicons-update"></span> Scanning…';
				tfiProgress(0, 0);
				var pl0 = document.getElementById('tfi-progress-label');
				if (pl0) { pl0.textContent = 'Checking URLs…'; }
				step(0);
				return;
			}

			// Per-row submit buttons (Reindex / Google / IndexNow).
			var act = e.target.closest('.tfi-act[data-url]');
			if (act) {
				e.preventDefault();
				if (act.classList.contains('is-busy')) { return; }
				act.classList.add('is-busy');
				var t = act.innerHTML;
				act.innerHTML = '…';
				var d2 = new FormData();
				d2.append('action', 'themify_index_url_run');
				d2.append('nonce', TFI_NONCE);
				d2.append('url', act.getAttribute('data-url'));
				d2.append('engine', act.getAttribute('data-engine') || 'both');
				fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: d2 })
					.then(function (r) { return r.json(); })
					.then(function (res) {
						act.classList.remove('is-busy');
						act.innerHTML = (res && res.success) ? '✓' : '✗';
						if (res && res.data && res.data.message) { act.title = res.data.message; }
						setTimeout(function () { act.innerHTML = t; }, 2600);
					})
					.catch(function () { act.classList.remove('is-busy'); act.innerHTML = t; });
				return;
			}

			// Copy helpers (coverage rows + the copy-all icon) with toast feedback.
			var cp = e.target.closest('[data-tfi-copy]');
			if (cp) {
				e.preventDefault();
				var txt = cp.getAttribute('data-tfi-copy') || '';
				if (!txt) { return; }
				var lines = txt.split('\n').filter(function (l) { return l.trim() !== ''; }).length;
				var label = cp.getAttribute('data-tfi-label') || '';
				var write = (navigator.clipboard && navigator.clipboard.writeText)
					? navigator.clipboard.writeText(txt)
					: Promise.reject();
				write.then(function () {
					tfiToast(label ? lines + ' "' + label + '" URLs copied' : lines + ' URLs copied');
					if (cp.classList.contains('tfi-cov')) {
						var val = cp.querySelector('.tfi-cov__line span:last-child');
						if (val) {
							var old = val.textContent;
							cp.classList.add('is-copied');
							val.textContent = 'Copied!';
							setTimeout(function () {
								val.textContent = old;
								cp.classList.remove('is-copied');
							}, 1800);
						}
					} else {
						cp.style.outline = '2px solid #1e8f38';
						setTimeout(function () { cp.style.outline = ''; }, 700);
					}
				}).catch(function () {
					// Clipboard API unavailable — fall back to a hidden textarea.
					var ta = document.createElement('textarea');
					ta.value = txt;
					document.body.appendChild(ta);
					ta.select();
					try { document.execCommand('copy'); tfiToast(lines + ' URLs copied'); } catch (err) {}
					document.body.removeChild(ta);
				});
			}
		});

		// Live search across both tables.
		document.addEventListener('input', function (e) {
			if (!e.target || e.target.id !== 'tfi-search-input') { return; }
			var q = e.target.value.toLowerCase();
			document.querySelectorAll('.tfi-table tbody tr').forEach(function (tr) {
				var u = (tr.getAttribute('data-url') || '').toLowerCase();
				tr.style.display = u.indexOf(q) > -1 ? '' : 'none';
			});
		});
	})();
	</script>
	<?php
}

/**
 * Short site-relative label for an absolute URL ("/", "/about", …).
 *
 * @param string $url Absolute URL.
 * @return string
 */
function themify_index_url_label( $url ) {
	$home = untrailingslashit( home_url( '/' ) );
	if ( 0 === strpos( $url, $home ) ) {
		$label = substr( $url, strlen( $home ) );
		return '' === $label ? '/' : $label;
	}
	return $url;
}

/**
 * Render the "Indexing Report" admin console (Search Console URL Inspection
 * scan + IndexNow / sitemap / RSS boost tools).
 */
function themify_indexing_page() {
	echo '<div class="wrap tfx">';
	if ( function_exists( 'themify_analytics_print_assets' ) ) {
		themify_analytics_print_assets();
	}
	themify_indexing_print_assets();

	$scan     = themify_index_scan_data();
	$results  = themify_index_results();
	$submits  = themify_index_submits();
	$total    = count( $results );
	$indexed  = 0;
	foreach ( $results as $row ) {
		if ( themify_index_is_indexed( (array) $row ) ) {
			$indexed++;
		}
	}
	$not_indexed = $total - $indexed;
	$rate        = $total ? round( $indexed / $total * 100 ) : 0;
	$rate_f      = $total ? round( $indexed / $total * 100, 1 ) : 0.0;
	$last_scan   = ! empty( $scan['time'] ) ? themify_time_ago( (int) $scan['time'] ) : __( 'never', 'themify' );

	// ---- Header ----
	echo '<div class="tfx-head">';
	echo '<div style="display:flex;gap:14px;align-items:flex-start;">';
	echo '<span class="tfi-pageicon"><span class="dashicons dashicons-admin-site-alt3"></span></span>';
	echo '<div>';
	echo '<h1>' . esc_html__( 'Indexing Report', 'themify' ) . '</h1>';
	echo '<p class="tfx-sub">' . esc_html__( 'Monitor & manage your Google search index', 'themify' ) . '</p>';
	echo '</div>';
	echo '</div>';
	echo '<div class="tfx-tools">';
	echo '<span class="tfi-lastscan"><span class="dashicons dashicons-clock"></span>' . esc_html( sprintf( /* translators: %s: relative time */ __( 'Last scan: %s', 'themify' ), $last_scan ) ) . '</span>';
	echo '<button type="button" id="tfi-scan" class="tfi-btn tfi-btn--green"><span class="dashicons dashicons-update"></span>' . esc_html__( 'Run Scan', 'themify' ) . '</button>';
	echo '</div>';
	echo '</div>';
	echo '<div id="tfi-msg"></div>';

	// ---- Scan progress bar (hidden until a scan starts) + toast. ----
	echo '<div class="tfx-card tfi-progress" id="tfi-progress">';
	echo '<div class="tfi-progress__line"><span id="tfi-progress-label">' . esc_html__( 'Checking URLs…', 'themify' ) . '</span><span class="tfi-progress__pct" id="tfi-progress-pct">0%</span></div>';
	echo '<div class="tfi-progress__track"><span class="tfi-progress__bar" id="tfi-progress-bar"></span></div>';
	echo '</div>';
	echo '<div class="tfi-toast" id="tfi-toast"></div>';

	// ---- SEO Boost Tools ----
	echo '<div class="tfx-card tfi-boost">';
	echo '<div class="tfi-boost__head">';
	echo '<span class="tfi-boost__icon"><span class="dashicons dashicons-superhero"></span></span>';
	echo '<div><strong>' . esc_html__( 'SEO Boost Tools', 'themify' ) . '</strong><span class="sub">' . esc_html__( 'IndexNow, Sitemap Submit, RSS Feed', 'themify' ) . '</span></div>';
	echo '</div>';
	echo '<div class="tfi-boost__grid">';

	// IndexNow column.
	echo '<div class="tfi-tool">';
	echo '<div class="tfi-tool__head"><span class="tfi-tool__icon" style="background:#1a2b20;"><span class="dashicons dashicons-superhero-alt"></span></span><div><b>' . esc_html__( 'IndexNow', 'themify' ) . '</b><span>' . esc_html__( 'Instant crawl notification', 'themify' ) . '</span></div></div>';
	echo '<p>' . esc_html__( 'Submit only not-indexed URLs to Bing, Yandex & search engines. No daily limit.', 'themify' ) . '</p>';
	printf(
		'<button type="button" class="tfi-btn tfi-btn--blue tf-run" data-action="themify_indexnow_run" data-payload="notindexed" data-target="#tfi-msg" data-running="%s"><span class="dashicons dashicons-superhero-alt"></span>%s</button>',
		esc_attr__( 'Submitting…', 'themify' ),
		esc_html( sprintf( /* translators: %d: count */ __( 'Submit Not-Indexed (%d)', 'themify' ), $not_indexed ) )
	);
	echo '</div>';

	// Sitemap column.
	echo '<div class="tfi-tool">';
	echo '<div class="tfi-tool__head"><span class="tfi-tool__icon" style="background:#1e8f38;"><span class="dashicons dashicons-networking"></span></span><div><b>' . esc_html__( 'Sitemap Submit', 'themify' ) . '</b><span>' . esc_html__( 'Google Search Console + Bing', 'themify' ) . '</span></div></div>';
	echo '<p>' . esc_html__( 'Submit sitemap to Google Search Console & Bing. Use after publishing new content.', 'themify' ) . '</p>';
	printf(
		'<button type="button" class="tfi-btn tfi-btn--green tf-run" style="width:100%%;" data-action="themify_sitemap_submit" data-target="#tfi-msg" data-running="%s"><span class="dashicons dashicons-networking"></span>%s</button>',
		esc_attr__( 'Submitting…', 'themify' ),
		esc_html__( 'Submit Sitemap', 'themify' )
	);
	echo '</div>';

	// RSS column.
	$feed = get_feed_link();
	echo '<div class="tfi-tool">';
	echo '<div class="tfi-tool__head"><span class="tfi-tool__icon" style="background:#d8a713;"><span class="dashicons dashicons-rss"></span></span><div><b>' . esc_html__( 'RSS Feed', 'themify' ) . '</b><span>' . esc_html__( 'Auto-discovery for crawlers', 'themify' ) . '</span></div></div>';
	echo '<p>' . esc_html__( 'RSS feed helps search engines discover new content automatically. Active at:', 'themify' ) . '</p>';
	echo '<span class="tfi-feedbox"><span class="dashicons dashicons-rss"></span>' . esc_html( themify_index_url_label( $feed ) ) . '</span>';
	printf(
		'<a class="tfi-btn tfi-btn--orange" href="%s" target="_blank" rel="noopener noreferrer"><span class="dashicons dashicons-external"></span>%s</a>',
		esc_url( $feed ),
		esc_html__( 'View RSS Feed', 'themify' )
	);
	echo '<span class="tfi-active"><span class="dashicons dashicons-yes-alt"></span>' . esc_html__( 'Active — Auto-linked in <head>', 'themify' ) . '</span>';
	echo '</div>';

	echo '</div>'; // grid
	echo '</div>'; // boost card

	// ---- Stat cards ----
	$stats = array(
		array( __( 'Total Pages', 'themify' ), $total, 'media-document', '#e7f0e9', '#1a2b20' ),
		array( __( 'Indexed', 'themify' ), $indexed, 'yes-alt', '#e3f5e8', '#1e8f38' ),
		array( __( 'Not Indexed', 'themify' ), $not_indexed, 'dismiss', '#fbe3e0', '#c0392b' ),
		array( __( 'Index Rate', 'themify' ), $rate . '%', 'shield', '#fdf3d9', '#b8860b' ),
	);
	echo '<div class="tfx-grid4">';
	foreach ( $stats as $s ) {
		echo '<div class="tfx-card tfi-stat">';
		echo '<span class="tfi-stat__icon" style="background:' . esc_attr( $s[3] ) . ';"><span class="dashicons dashicons-' . esc_attr( $s[2] ) . '" style="color:' . esc_attr( $s[4] ) . ';"></span></span>';
		echo '<div><div class="tfi-stat__label">' . esc_html( $s[0] ) . '</div><div class="tfi-stat__num">' . esc_html( (string) $s[1] ) . '</div></div>';
		echo '</div>';
	}
	echo '</div>';

	if ( ! $total ) {
		// No scan yet — everything below needs data.
		echo '<div class="tfx-card"><div class="tfi-empty"><span class="dashicons dashicons-search"></span>';
		echo esc_html__( 'No scan data yet. Click "Run Scan" to check every public URL against the Google index. Requires the Google credentials from Themixify → Analytics.', 'themify' );
		echo '</div></div>';
		echo '<button type="button" class="tfx-top" aria-label="' . esc_attr__( 'Scroll to top', 'themify' ) . '"><span class="dashicons dashicons-arrow-up-alt2"></span></button>';
		echo '</div>';
		return;
	}

	// ---- Donut + coverage breakdown ----
	echo '<div class="tfx-grid2">';

	echo '<div class="tfx-card tfi-donutwrap">';
	printf(
		'<div class="tfi-donut" style="background:conic-gradient(#1e8f38 0 %1$s%%, #c0392b %1$s%% 100%%);"><div class="tfi-donut__hole"><span class="tfi-donut__pct">%2$s%%</span><span class="tfi-donut__lbl">%3$s</span></div></div>',
		esc_attr( (string) $rate_f ),
		esc_html( number_format_i18n( $rate_f, 1 ) ),
		esc_html__( 'Indexed', 'themify' )
	);
	echo '<div class="tfi-legend">';
	echo '<span><span class="tfi-dot" style="background:#1e8f38;"></span>' . esc_html( sprintf( /* translators: %d: count */ __( 'Indexed (%d)', 'themify' ), $indexed ) ) . '</span>';
	echo '<span><span class="tfi-dot" style="background:#c0392b;"></span>' . esc_html( sprintf( /* translators: %d: count */ __( 'Not Indexed (%d)', 'themify' ), $not_indexed ) ) . '</span>';
	echo '</div>';
	echo '</div>';

	// Group results by coverage state.
	$groups = array();
	foreach ( $results as $url => $row ) {
		$state = isset( $row['state'] ) && '' !== (string) $row['state'] ? (string) $row['state'] : __( 'Unknown', 'themify' );
		if ( ! isset( $groups[ $state ] ) ) {
			$groups[ $state ] = array(
				'count'   => 0,
				'urls'    => array(),
				'indexed' => themify_index_is_indexed( (array) $row ),
			);
		}
		$groups[ $state ]['count']++;
		$groups[ $state ]['urls'][] = (string) $url;
	}
	uasort( $groups, function ( $a, $b ) {
		return $b['count'] <=> $a['count'];
	} );

	echo '<div class="tfx-card">';
	echo '<div class="tfx-card__head"><span class="tfx-card__title">' . esc_html__( 'Coverage Breakdown', 'themify' ) . '</span></div>';
	echo '<div class="tfi-covbody">';
	foreach ( $groups as $state => $g ) {
		$pct   = $total ? round( $g['count'] / $total * 100 ) : 0;
		$green = ! empty( $g['indexed'] );
		printf(
			'<div class="tfi-cov" data-tfi-copy="%s" data-tfi-label="%s" title="%s">',
			esc_attr( implode( "\n", $g['urls'] ) ),
			esc_attr( $state ),
			esc_attr__( 'Click to copy the URLs in this group', 'themify' )
		);
		echo '<div class="tfi-cov__line"><span>' . esc_html( $state ) . '</span><span class="' . ( $green ? 'tfi-cov__val--green' : 'tfi-cov__val--amber' ) . '">' . esc_html( $g['count'] . ' (' . $pct . '%)' ) . '</span></div>';
		echo '<div class="tfx-track"><span style="width:' . (int) max( 2, $pct ) . '%;background:' . ( $green ? '#1e8f38' : '#b8860b' ) . ';"></span></div>';
		echo '</div>';
	}
	echo '<div class="tfi-note"><span class="dashicons dashicons-admin-page"></span>' . esc_html__( 'Click any row to copy its URLs', 'themify' ) . '</div>';
	echo '<div class="tfi-lastrow"><span>' . esc_html__( 'Last scan', 'themify' ) . '</span><b>' . esc_html( $last_scan ) . '</b></div>';
	echo '</div>';
	echo '</div>';

	echo '</div>'; // grid2

	// ---- Search + bulk action row ----
	echo '<div class="tfi-searchrow">';
	echo '<div class="tfi-search"><span class="dashicons dashicons-search"></span><input type="text" id="tfi-search-input" placeholder="' . esc_attr__( 'Search pages…', 'themify' ) . '" /></div>';
	printf(
		'<button type="button" class="tfi-btn tfi-btn--red tf-run" data-action="themify_indexnow_run" data-payload="notindexed" data-target="#tfi-msg" data-running="%s"><span class="dashicons dashicons-migrate"></span>%s</button>',
		esc_attr__( 'Submitting…', 'themify' ),
		esc_html( sprintf( /* translators: %d: count */ __( 'Index All Not-Indexed (%d)', 'themify' ), $not_indexed ) )
	);
	echo '</div>';

	// ---- Tables ----
	echo '<div class="tfx-grid2">';

	// Indexed pages.
	echo '<div class="tfx-card">';
	echo '<div class="tfi-thead tfi-thead--green"><span class="dashicons dashicons-yes-alt"></span>' . esc_html__( 'Indexed Pages', 'themify' ) . '<span class="tfi-count">' . (int) $indexed . '</span></div>';
	echo '<div class="tfx-tablewrap">';
	echo '<table class="tfx-table tfi-table"><thead><tr>';
	echo '<th>#</th><th>' . esc_html__( 'URL', 'themify' ) . '</th><th>' . esc_html__( 'Crawled', 'themify' ) . '</th><th class="tfx-r">' . esc_html__( 'Action', 'themify' ) . '</th>';
	echo '</tr></thead><tbody>';
	$i = 0;
	foreach ( $results as $url => $row ) {
		if ( ! themify_index_is_indexed( (array) $row ) ) {
			continue;
		}
		$i++;
		$label = themify_index_url_label( (string) $url );
		echo '<tr data-url="' . esc_attr( $label ) . '">';
		echo '<td class="tfx-rank">' . (int) $i . '</td>';
		printf(
			'<td class="tfi-urlcell"><a href="%s" target="_blank" rel="noopener noreferrer">%s</a></td>',
			esc_url( (string) $url ),
			esc_html( $label )
		);
		echo '<td>' . esc_html( ! empty( $row['crawled'] ) ? themify_time_ago( (int) $row['crawled'] ) : '—' ) . '</td>';
		printf(
			'<td class="tfx-r"><button type="button" class="tfi-act" data-url="%s" data-engine="both"><span class="dashicons dashicons-external"></span>%s</button></td>',
			esc_attr( (string) $url ),
			esc_html__( 'Reindex', 'themify' )
		);
		echo '</tr>';
	}
	echo '</tbody></table>';
	echo '</div>';
	echo '</div>';

	// Not indexed pages.
	$not_urls = themify_index_not_indexed_urls();
	echo '<div class="tfx-card">';
	echo '<div class="tfi-thead tfi-thead--red"><span class="dashicons dashicons-dismiss"></span>' . esc_html__( 'Not Indexed Pages', 'themify' ) . '<span class="tfi-count">' . (int) $not_indexed . '</span>';
	printf(
		'<button type="button" class="tfi-copy" data-tfi-copy="%s" data-tfi-label="%s" title="%s"><span class="dashicons dashicons-admin-page"></span></button>',
		esc_attr( implode( "\n", $not_urls ) ),
		esc_attr__( 'Not indexed', 'themify' ),
		esc_attr__( 'Copy all not-indexed URLs', 'themify' )
	);
	echo '</div>';
	echo '<div class="tfx-tablewrap">';
	echo '<table class="tfx-table tfi-table"><thead><tr>';
	echo '<th>#</th><th>' . esc_html__( 'URL', 'themify' ) . '</th><th>' . esc_html__( 'Status', 'themify' ) . '</th><th class="tfx-r">' . esc_html__( 'Action', 'themify' ) . '</th>';
	echo '</tr></thead><tbody>';
	$i = 0;
	foreach ( $results as $url => $row ) {
		if ( themify_index_is_indexed( (array) $row ) ) {
			continue;
		}
		$i++;
		$url    = (string) $url;
		$label  = themify_index_url_label( $url );
		$state  = isset( $row['state'] ) ? (string) $row['state'] : '';
		$sub    = isset( $submits[ $url ] ) && is_array( $submits[ $url ] ) ? $submits[ $url ] : array();

		echo '<tr data-url="' . esc_attr( $label ) . '">';
		echo '<td class="tfx-rank">' . (int) $i . '</td>';
		printf(
			'<td class="tfi-urlcell"><a href="%s" target="_blank" rel="noopener noreferrer">%s</a></td>',
			esc_url( $url ),
			esc_html( $label )
		);
		echo '<td>';
		echo '<span class="tfi-badge" title="' . esc_attr( $state ) . '">' . esc_html( $state ) . '</span>';
		if ( ! empty( $sub['google'] ) ) {
			echo '<span class="tfi-sub tfi-sub--google"><span class="dashicons dashicons-migrate"></span>' . esc_html( 'Google ' . themify_time_ago( (int) $sub['google'] ) ) . '</span>';
		}
		if ( ! empty( $sub['indexnow'] ) ) {
			echo '<span class="tfi-sub tfi-sub--inow"><span class="dashicons dashicons-superhero-alt"></span>' . esc_html( 'IndexNow ' . themify_time_ago( (int) $sub['indexnow'] ) ) . '</span>';
		}
		if ( ! empty( $row['published'] ) ) {
			echo '<span class="tfi-sub"><span class="dashicons dashicons-clock"></span>' . esc_html( sprintf( /* translators: %s: human time diff */ __( 'Published %s ago', 'themify' ), human_time_diff( (int) $row['published'] ) ) ) . '</span>';
		}
		echo '</td>';
		echo '<td class="tfx-r" style="white-space:nowrap;">';
		printf(
			'<button type="button" class="tfi-act tfi-act--google" data-url="%s" data-engine="google"><span class="dashicons dashicons-migrate"></span>%s</button> ',
			esc_attr( $url ),
			esc_html__( 'Google', 'themify' )
		);
		printf(
			'<button type="button" class="tfi-act tfi-act--inow" data-url="%s" data-engine="indexnow"><span class="dashicons dashicons-superhero-alt"></span>%s</button>',
			esc_attr( $url ),
			esc_html__( 'IndexNow', 'themify' )
		);
		echo '</td>';
		echo '</tr>';
	}
	echo '</tbody></table>';
	echo '</div>';
	echo '</div>';

	echo '</div>'; // grid2

	echo '<button type="button" class="tfx-top" aria-label="' . esc_attr__( 'Scroll to top', 'themify' ) . '"><span class="dashicons dashicons-arrow-up-alt2"></span></button>';
	echo '</div>'; // .tfx
}
