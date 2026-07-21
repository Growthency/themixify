<?php
/**
 * Rank Tracker module — SerpAPI keyword position tracking.
 *
 * Mirrors the reference rank-tracker route, re-implemented in PHP. It lets an
 * administrator track a list of keywords and, for each, find where this site
 * ranks in Google's organic results (top 100) via SerpAPI. Rankings are stored
 * with a running history (current + previous) so the admin sees movement, and
 * a live quota card shows how many SerpAPI searches are left across the keys.
 *
 * DATA MODEL
 *   - Options (in THEMIFY_OPT, via the settings framework):
 *       serpapi_key         — primary SerpAPI key.
 *       serpapi_key_backup  — fallback key, tried on quota exhaustion / error.
 *   - Option 'themify_rank_keywords' (its own option — list-shaped data):
 *       array of [ 'keyword' => (string), 'target' => (string URL/path, optional) ].
 *   - Option 'themify_rank_data' (its own option — the results/history):
 *       keyed by keyword => [
 *         'current'    => (int|null) 1-based position, null = not in top 100,
 *         'previous'   => (int|null) the prior 'current' before the last check,
 *         'url'        => (string)   the ranking result URL,
 *         'checked_at' => (int)      unix timestamp of the last check.
 *       ]
 *
 * SECURITY / PERFORMANCE POSTURE
 *   - SerpAPI is NEVER called on a public (front-end) page load. Every remote
 *     call is guarded to admin/cron context, and each is cached in a transient
 *     (search results 6h, account/quota 10m) so even the admin rarely hits the
 *     network.
 *   - Every write (keyword save, rank check) is gated behind a nonce +
 *     current_user_can( THEMIFY_CAP ).
 *   - API keys are stored via the settings framework and are never echoed back
 *     into any front-end HTML, nor into query strings that appear on screen.
 *
 * @package Themify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* =========================================================================
 * CONSTANTS
 * ====================================================================== */

/** SerpAPI search endpoint (Google engine). */
if ( ! defined( 'THEMIFY_SERPAPI_SEARCH_URL' ) ) {
	define( 'THEMIFY_SERPAPI_SEARCH_URL', 'https://serpapi.com/search.json' );
}

/** SerpAPI account (quota) endpoint. */
if ( ! defined( 'THEMIFY_SERPAPI_ACCOUNT_URL' ) ) {
	define( 'THEMIFY_SERPAPI_ACCOUNT_URL', 'https://serpapi.com/account.json' );
}

/** SearchApi.io search endpoint (Google engine, SerpAPI-compatible shape). */
if ( ! defined( 'THEMIFY_SEARCHAPI_SEARCH_URL' ) ) {
	define( 'THEMIFY_SEARCHAPI_SEARCH_URL', 'https://www.searchapi.io/api/v1/search' );
}

/** SearchApi.io account (quota) endpoint. */
if ( ! defined( 'THEMIFY_SEARCHAPI_ACCOUNT_URL' ) ) {
	define( 'THEMIFY_SEARCHAPI_ACCOUNT_URL', 'https://www.searchapi.io/api/v1/account' );
}

/** Option holding the tracked-keyword list (list-shaped, its own option). */
if ( ! defined( 'THEMIFY_RANK_KEYWORDS_OPT' ) ) {
	define( 'THEMIFY_RANK_KEYWORDS_OPT', 'themify_rank_keywords' );
}

/** Option holding the per-keyword results/history. */
if ( ! defined( 'THEMIFY_RANK_DATA_OPT' ) ) {
	define( 'THEMIFY_RANK_DATA_OPT', 'themify_rank_data' );
}

/** How long a single SERP query is cached (6 hours). */
if ( ! defined( 'THEMIFY_RANK_SEARCH_TTL' ) ) {
	define( 'THEMIFY_RANK_SEARCH_TTL', 6 * HOUR_IN_SECONDS );
}

/** How long a per-key quota lookup is cached (10 minutes). */
if ( ! defined( 'THEMIFY_RANK_QUOTA_TTL' ) ) {
	define( 'THEMIFY_RANK_QUOTA_TTL', 10 * MINUTE_IN_SECONDS );
}

/* =========================================================================
 * KEYS / CONTEXT
 * ====================================================================== */

/** Option holding the multi-provider API-key list (list-shaped, own option). */
if ( ! defined( 'THEMIFY_SERP_KEYS_OPT' ) ) {
	define( 'THEMIFY_SERP_KEYS_OPT', 'themify_serp_api_keys' );
}

/**
 * Every SERP provider the tracker can talk to (key => human label). All of
 * them return Google's organic results as JSON; the request builder and the
 * response parser below absorb the per-provider differences.
 *
 * @return array<string,string>
 */
function themify_serp_providers() {
	return array(
		'serpapi'   => 'SerpAPI (serpapi.com)',
		'searchapi' => 'SearchApi.io',
		'serper'    => 'Serper.dev',
		'valueserp' => 'ValueSERP (valueserp.com)',
		'scaleserp' => 'Scale SERP (scaleserp.com)',
		'serpwow'   => 'SerpWow (serpwow.com)',
		'zenserp'   => 'Zenserp (zenserp.com)',
		'serpstack' => 'Serpstack (serpstack.com)',
	);
}

/**
 * The configured API keys, in failover order. Each row: { provider, key }.
 * Falls back to the legacy single-provider options so existing setups keep
 * working without re-entering anything.
 *
 * @return array<int,array{provider:string,key:string}>
 */
function themify_serp_key_rows() {
	$providers = array_keys( themify_serp_providers() );

	$raw  = get_option( THEMIFY_SERP_KEYS_OPT, array() );
	$rows = array();
	if ( is_array( $raw ) ) {
		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$provider = isset( $row['provider'] ) ? (string) $row['provider'] : 'serpapi';
			$key      = isset( $row['key'] ) ? trim( (string) $row['key'] ) : '';
			if ( '' === $key ) {
				continue;
			}
			$rows[] = array(
				'provider' => in_array( $provider, $providers, true ) ? $provider : 'serpapi',
				'key'      => $key,
			);
		}
	}
	if ( ! empty( $rows ) ) {
		return $rows;
	}

	// Legacy migration: the old provider dropdown + primary/backup key fields.
	$legacy_provider = (string) themify_get_option( 'serp_provider', 'serpapi' );
	$legacy_provider = in_array( $legacy_provider, $providers, true ) ? $legacy_provider : 'serpapi';
	foreach ( array( 'serpapi_key', 'serpapi_key_backup' ) as $opt ) {
		$val = trim( (string) themify_get_option( $opt, '' ) );
		if ( '' !== $val ) {
			$rows[] = array(
				'provider' => $legacy_provider,
				'key'      => $val,
			);
		}
	}
	return $rows;
}

/**
 * Whether at least one API key is configured (any provider).
 *
 * @return bool
 */
function themify_serp_has_key() {
	return ! empty( themify_serp_key_rows() );
}

/**
 * Build the HTTP request (url + wp_remote args) for one provider/key/query.
 *
 * @param string $provider Provider slug.
 * @param string $key      API key.
 * @param string $query    Search query.
 * @return array|null { url:string, args:array } or null for unknown providers.
 */
function themify_serp_provider_request( $provider, $key, $query ) {
	$args = array( 'timeout' => 25 );

	switch ( $provider ) {
		case 'serpapi':
			return array(
				'url'  => add_query_arg(
					array(
						'engine'  => 'google',
						'q'       => $query,
						'num'     => 100,
						'api_key' => $key,
					),
					THEMIFY_SERPAPI_SEARCH_URL
				),
				'args' => $args,
			);

		case 'searchapi':
			return array(
				'url'  => add_query_arg(
					array(
						'engine'  => 'google',
						'q'       => $query,
						'num'     => 100,
						'api_key' => $key,
					),
					THEMIFY_SEARCHAPI_SEARCH_URL
				),
				'args' => $args,
			);

		case 'serper':
			return array(
				'url'  => 'https://google.serper.dev/search',
				'args' => array(
					'timeout' => 25,
					'method'  => 'POST',
					'headers' => array(
						'X-API-KEY'    => $key,
						'Content-Type' => 'application/json',
					),
					'body'    => wp_json_encode( array(
						'q'   => $query,
						'num' => 100,
					) ),
				),
			);

		case 'valueserp':
			return array(
				'url'  => add_query_arg(
					array(
						'api_key' => $key,
						'q'       => $query,
						'num'     => 100,
					),
					'https://api.valueserp.com/search'
				),
				'args' => $args,
			);

		case 'scaleserp':
			return array(
				'url'  => add_query_arg(
					array(
						'api_key' => $key,
						'q'       => $query,
						'num'     => 100,
					),
					'https://api.scaleserp.com/search'
				),
				'args' => $args,
			);

		case 'serpwow':
			return array(
				'url'  => add_query_arg(
					array(
						'api_key' => $key,
						'engine'  => 'google',
						'q'       => $query,
						'num'     => 100,
					),
					'https://api.serpwow.com/search'
				),
				'args' => $args,
			);

		case 'zenserp':
			return array(
				'url'  => add_query_arg(
					array(
						'apikey' => $key,
						'q'      => $query,
						'num'    => 100,
					),
					'https://app.zenserp.com/api/v2/search'
				),
				'args' => $args,
			);

		case 'serpstack':
			return array(
				'url'  => add_query_arg(
					array(
						'access_key' => $key,
						'query'      => $query,
						'num'        => 100,
					),
					'https://api.serpstack.com/search'
				),
				'args' => $args,
			);
	}

	return null;
}

/**
 * Extract a provider-reported error string from a decoded 200 response.
 * Providers signal failures differently; '' means the response looks healthy.
 *
 * @param array $response Decoded JSON response.
 * @return string
 */
function themify_serp_response_error( array $response ) {
	// SerpAPI / SearchApi.io / Zenserp: { "error": "..." }.
	if ( isset( $response['error'] ) && is_string( $response['error'] ) && '' !== $response['error'] ) {
		return $response['error'];
	}
	// Serpstack: { "success": false, "error": { "info": "..." } }.
	if ( isset( $response['error']['info'] ) && is_string( $response['error']['info'] ) ) {
		return $response['error']['info'];
	}
	if ( isset( $response['success'] ) && false === $response['success'] ) {
		return __( 'The provider reported the request failed.', 'themify' );
	}
	// ValueSERP / Scale SERP / SerpWow: { "request_info": { "success": false, "message": "..." } }.
	if ( isset( $response['request_info']['success'] ) && ! $response['request_info']['success'] ) {
		return isset( $response['request_info']['message'] ) && is_string( $response['request_info']['message'] )
			? $response['request_info']['message']
			: __( 'The provider reported the request failed.', 'themify' );
	}
	return '';
}

/**
 * Guard: SerpAPI may only be contacted from the admin or from cron — never on a
 * public page view. Centralised so every network entry point can check it.
 *
 * @return bool
 */
function themify_serp_allowed_context() {
	if ( is_admin() ) {
		return true;
	}
	return defined( 'DOING_CRON' ) && DOING_CRON;
}

/* =========================================================================
 * SERP SEARCH
 * ====================================================================== */

/**
 * Search Google (via SerpAPI) for a query and return where THIS site ranks in
 * the top 100 organic results.
 *
 * Tries the primary key first, then the backup on a quota/error response. The
 * SerpAPI response is cached per query for 6 hours in a transient so repeated
 * checks (and re-renders) don't spend the quota. Parses organic_results and
 * finds the first result whose link host matches themify_site_host().
 *
 * @param string $query The search query (keyword) to look up.
 * @return array|WP_Error {
 *   On success an array:
 *     @type int|null $position 1-based rank (prefers the result's own
 *                              'position' field), or null if not in top 100.
 *     @type string   $url      The ranking result URL ('' when not found).
 *   On failure a WP_Error (no keys, all keys errored, transport error…).
 * }
 */
function themify_serp_search( $query ) {
	$query = trim( (string) $query );
	if ( '' === $query ) {
		return new WP_Error( 'themify_serp_query', __( 'An empty query cannot be searched.', 'themify' ) );
	}

	if ( ! themify_serp_allowed_context() ) {
		return new WP_Error( 'themify_serp_context', __( 'SerpAPI calls are only allowed in admin/cron context.', 'themify' ) );
	}

	$rows = themify_serp_key_rows();
	if ( empty( $rows ) ) {
		return new WP_Error( 'themify_serp_nokey', __( 'No SERP API key is configured.', 'themify' ) );
	}

	// Cache per query (not per key/provider) — the SERP is the same regardless
	// of who fetched it, and this is what protects the quotas.
	$cache_key = 'themify_serp_' . md5( strtolower( $query ) );
	$cached    = get_transient( $cache_key );
	if ( is_array( $cached ) && array_key_exists( 'position', $cached ) ) {
		return $cached;
	}

	$last_error = null;

	// Try every configured key in order — when one provider/key errors or runs
	// out of searches, the next one automatically takes over.
	foreach ( $rows as $row ) {
		$request = themify_serp_provider_request( $row['provider'], $row['key'], $query );
		if ( null === $request ) {
			continue;
		}

		$response = themify_remote_json( $request['url'], $request['args'] );

		if ( is_wp_error( $response ) ) {
			// Transport or HTTP error (quota exhaustion surfaces here as a
			// non-2xx). Remember it and fall through to the next key.
			$last_error = $response;
			continue;
		}

		// Providers also report problems (invalid key, out of searches) inside
		// a 200 body. Treat that as a key failure so the next key gets a turn.
		$api_error = themify_serp_response_error( $response );
		if ( '' !== $api_error ) {
			$last_error = new WP_Error( 'themify_serp_api', $api_error );
			continue;
		}

		$result = themify_serp_parse_result( $response );

		// Cache the parsed {position,url} for this query so we don't spend quota
		// again for 6 hours.
		set_transient( $cache_key, $result, THEMIFY_RANK_SEARCH_TTL );
		return $result;
	}

	// Every configured key failed.
	if ( $last_error instanceof WP_Error ) {
		return $last_error;
	}
	return new WP_Error( 'themify_serp_failed', __( 'The SERP API request failed.', 'themify' ) );
}

/**
 * Parse any provider's response and locate this site's first organic result.
 *
 * Providers name the list and the link field differently:
 *   organic_results (SerpAPI, SearchApi.io, ValueSERP, Scale SERP, SerpWow,
 *   Serpstack) or organic (Serper.dev, Zenserp); links live under 'link' or
 *   'url'. Positions prefer the item's own field, falling back to a counter.
 *
 * @param array $response Decoded search response.
 * @return array { @type int|null $position, @type string $url }
 */
function themify_serp_parse_result( array $response ) {
	$not_found = array(
		'position' => null,
		'url'      => '',
	);

	// Find the organic-results list under its known names.
	$items = null;
	foreach ( array( 'organic_results', 'organic', 'results' ) as $list_key ) {
		if ( ! empty( $response[ $list_key ] ) && is_array( $response[ $list_key ] ) ) {
			$items = $response[ $list_key ];
			break;
		}
	}
	if ( null === $items ) {
		return $not_found;
	}

	$site_host = themify_site_host();
	if ( '' === $site_host ) {
		return $not_found;
	}

	$counter = 0;
	foreach ( $items as $item ) {
		++$counter;
		if ( ! is_array( $item ) ) {
			continue;
		}
		$link = '';
		foreach ( array( 'link', 'url' ) as $link_key ) {
			if ( ! empty( $item[ $link_key ] ) && is_string( $item[ $link_key ] ) ) {
				$link = $item[ $link_key ];
				break;
			}
		}
		if ( '' === $link ) {
			continue;
		}
		$host = wp_parse_url( $link, PHP_URL_HOST );
		if ( ! $host ) {
			continue;
		}
		$host = preg_replace( '/^www\./i', '', strtolower( $host ) );

		if ( $host === $site_host ) {
			// Prefer the result's own 'position' field; fall back to the counter.
			$position = isset( $item['position'] ) && (int) $item['position'] > 0
				? (int) $item['position']
				: $counter;
			return array(
				'position' => $position,
				'url'      => $link,
			);
		}
	}

	return $not_found;
}

/* =========================================================================
 * QUOTA
 * ====================================================================== */

/**
 * Query the SerpAPI account endpoint for each configured key and report how
 * many searches are used / left. Each key's lookup is cached for 10 minutes.
 *
 * @return array {
 *   @type array $keys  [ ['label'=>, 'used'=>int, 'left'=>int, 'total'=>int,
 *                         'error'=>string|''], … ] one per configured key.
 *   @type int   $left  Sum of remaining searches across all keys.
 *   @type int   $used  Sum of used searches across all keys.
 *   @type bool  $has_key Whether any key is configured at all.
 * }
 */
function themify_serp_quota() {
	$out = array(
		'keys'    => array(),
		'left'    => 0,
		'used'    => 0,
		'has_key' => false,
		'unknown' => false,
	);

	$rows = themify_serp_key_rows();
	if ( empty( $rows ) ) {
		return $out;
	}
	$out['has_key'] = true;

	if ( ! themify_serp_allowed_context() ) {
		return $out;
	}

	$providers = themify_serp_providers();
	$found_any = false;

	foreach ( $rows as $i => $row ) {
		$label = sprintf(
			/* translators: 1: key number, 2: provider name */
			__( 'Key %1$d (%2$s)', 'themify' ),
			$i + 1,
			isset( $providers[ $row['provider'] ] ) ? $providers[ $row['provider'] ] : $row['provider']
		);

		// Only SerpAPI publishes a stable account/quota endpoint. Other
		// providers still work for searches; their usage lives on their own
		// dashboards, so we list the key without numbers instead of erroring.
		if ( 'serpapi' !== $row['provider'] ) {
			$out['keys'][] = array(
				'label' => $label,
				'used'  => 0,
				'left'  => 0,
				'total' => 0,
				'error' => '',
			);
			continue;
		}

		$key       = $row['key'];
		$cache_key = 'themify_serp_acct_' . md5( 'serpapi|' . $key );
		$account   = get_transient( $cache_key );

		if ( ! is_array( $account ) ) {
			$url      = add_query_arg( array( 'api_key' => $key ), THEMIFY_SERPAPI_ACCOUNT_URL );
			$response = themify_remote_json( $url, array( 'timeout' => 20 ) );

			if ( is_wp_error( $response ) ) {
				$out['keys'][] = array(
					'label' => $label,
					'used'  => 0,
					'left'  => 0,
					'total' => 0,
					'error' => $response->get_error_message(),
				);
				continue;
			}
			if ( isset( $response['error'] ) && '' !== (string) $response['error'] ) {
				$out['keys'][] = array(
					'label' => $label,
					'used'  => 0,
					'left'  => 0,
					'total' => 0,
					'error' => (string) $response['error'],
				);
				continue;
			}

			$account = $response;
			set_transient( $cache_key, $account, THEMIFY_RANK_QUOTA_TTL );
		}

		$left = 0;
		if ( isset( $account['total_searches_left'] ) ) {
			$left = (int) $account['total_searches_left'];
		} elseif ( isset( $account['plan_searches_left'] ) ) {
			$left = (int) $account['plan_searches_left'];
		}
		$used  = isset( $account['this_month_usage'] ) ? (int) $account['this_month_usage'] : 0;
		$total = isset( $account['searches_per_month'] ) ? (int) $account['searches_per_month'] : ( $used + $left );

		$out['keys'][] = array(
			'label' => $label,
			'used'  => $used,
			'left'  => $left,
			'total' => $total,
			'error' => '',
		);
		$found_any    = true;
		$out['left'] += $left;
		$out['used'] += $used;
	}

	// No key produced usable numbers — the hero card shows "—" instead of 0.
	$out['unknown'] = ! $found_any;

	return $out;
}

/* =========================================================================
 * KEYWORD LIST + RESULTS STORAGE
 * ====================================================================== */

/**
 * Read the tracked-keyword list, normalised so every row has both keys.
 *
 * @return array<int,array> [ ['keyword'=>string, 'target'=>string], … ]
 */
function themify_rank_get_keywords() {
	$raw = get_option( THEMIFY_RANK_KEYWORDS_OPT, array() );
	if ( ! is_array( $raw ) ) {
		return array();
	}
	$clean = array();
	foreach ( $raw as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$keyword = isset( $row['keyword'] ) ? trim( (string) $row['keyword'] ) : '';
		if ( '' === $keyword ) {
			continue;
		}
		$clean[] = array(
			'keyword' => $keyword,
			'target'  => isset( $row['target'] ) ? trim( (string) $row['target'] ) : '',
			'volume'  => isset( $row['volume'] ) ? max( 0, (int) $row['volume'] ) : 0,
		);
	}
	return $clean;
}

/**
 * Persist the keyword list (already-sanitized rows), re-indexed.
 *
 * @param array $keywords List of [keyword,target] rows.
 * @return bool
 */
function themify_rank_save_keywords( array $keywords ) {
	return update_option( THEMIFY_RANK_KEYWORDS_OPT, array_values( $keywords ) );
}

/**
 * Read the stored per-keyword results/history.
 *
 * @return array<string,array> keyword => ['current','previous','url','checked_at'].
 */
function themify_rank_get_data() {
	$raw = get_option( THEMIFY_RANK_DATA_OPT, array() );
	return is_array( $raw ) ? $raw : array();
}

/**
 * Persist the per-keyword results/history.
 *
 * @param array $data keyword => row.
 * @return bool
 */
function themify_rank_save_data( array $data ) {
	return update_option( THEMIFY_RANK_DATA_OPT, $data );
}

/* =========================================================================
 * CHECK ALL
 * ====================================================================== */

/**
 * Iterate every tracked keyword, run the SERP search, and update the stored
 * results — moving the prior 'current' into 'previous' so movement can be
 * shown. Keywords whose search errors keep their previous record untouched
 * (we don't clobber good history with a transient API failure).
 *
 * @return array {
 *   @type int   $checked Number of keywords successfully checked.
 *   @type int   $errors  Number of keywords whose search failed.
 *   @type array $data    The full, updated results map (for immediate render).
 * }
 */
function themify_rank_check_all() {
	$keywords = themify_rank_get_keywords();
	$data     = themify_rank_get_data();

	$checked = 0;
	$errors  = 0;

	foreach ( $keywords as $row ) {
		$keyword = $row['keyword'];
		$result  = themify_serp_search( $keyword );

		if ( is_wp_error( $result ) ) {
			++$errors;
			continue;
		}

		$previous = isset( $data[ $keyword ]['current'] ) ? $data[ $keyword ]['current'] : null;

		$data[ $keyword ] = array(
			'current'    => $result['position'], // int|null
			'previous'   => $previous,           // int|null
			'url'        => (string) $result['url'],
			'checked_at' => time(),
		);
		++$checked;
	}

	// Drop stored results for keywords that are no longer tracked, so the data
	// option doesn't accumulate stale entries.
	$tracked = array();
	foreach ( $keywords as $row ) {
		$tracked[ $row['keyword'] ] = true;
	}
	foreach ( array_keys( $data ) as $stored_keyword ) {
		if ( ! isset( $tracked[ $stored_keyword ] ) ) {
			unset( $data[ $stored_keyword ] );
		}
	}

	themify_rank_save_data( $data );

	return array(
		'checked' => $checked,
		'errors'  => $errors,
		'data'    => $data,
	);
}

/* =========================================================================
 * ADMIN PAGE REGISTRATION
 * ====================================================================== */

themify_register_admin_page( array(
	'slug'       => 'themify-rank-tracker',
	'title'      => __( 'Rank Tracker', 'themify' ),
	'menu_title' => __( 'Rank Tracker', 'themify' ),
	'callback'   => 'themify_rank_tracker_page',
	'position'   => 16,
) );

add_filter( 'themify_dashboard_cards', 'themify_rank_dashboard_card' );

/**
 * Append the Rank Tracker dashboard card.
 *
 * @param array $cards Existing cards.
 * @return array
 */
function themify_rank_dashboard_card( $cards ) {
	$cards[] = array(
		'slug'     => 'themify-rank-tracker',
		'title'    => __( 'Rank Tracker', 'themify' ),
		'desc'     => __( 'Track Google keyword positions', 'themify' ),
		'icon'     => 'dashicons-chart-line',
		'position' => 16,
	);
	return $cards;
}

/* =========================================================================
 * SETTINGS + KEYWORD SAVE
 * ====================================================================== */

/**
 * Handle a POST save of the Rank Tracker form. This one form carries BOTH the
 * API key fields (stored in THEMIFY_OPT) and the keyword repeater (stored in
 * its own option). Gated behind the 'themify_rank' nonce + capability.
 *
 * @return bool True when a valid save happened.
 */
function themify_rank_handle_save() {
	if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
		return false;
	}
	if ( ! current_user_can( THEMIFY_CAP ) ) {
		return false;
	}
	$nonce = isset( $_POST['themify_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['themify_nonce'] ) ) : '';
	if ( ! wp_verify_nonce( $nonce, 'themify_rank' ) ) {
		return false;
	}

	// API-key repeater rows (any number, any provider, in failover order).
	$key_rows_in = isset( $_POST['themify_serp_rows'] ) && is_array( $_POST['themify_serp_rows'] )
		? wp_unslash( $_POST['themify_serp_rows'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per field below.
		: array();

	$providers = array_keys( themify_serp_providers() );
	$key_rows  = array();
	foreach ( $key_rows_in as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$provider = isset( $row['provider'] ) ? sanitize_key( $row['provider'] ) : 'serpapi';
		$api_key  = isset( $row['key'] ) ? trim( sanitize_text_field( $row['key'] ) ) : '';
		if ( '' === $api_key ) {
			continue; // Drop empty/template rows.
		}
		$key_rows[] = array(
			'provider' => in_array( $provider, $providers, true ) ? $provider : 'serpapi',
			'key'      => $api_key,
		);
	}
	update_option( THEMIFY_SERP_KEYS_OPT, array_values( $key_rows ), false );

	// Clear the legacy single-provider options so the migration fallback in
	// themify_serp_key_rows() can never resurrect removed keys.
	themify_set_options( array(
		'serpapi_key'        => '',
		'serpapi_key_backup' => '',
	) );

	return true;
}

/* =========================================================================
 * AJAX: CHECK RANKINGS NOW
 * ====================================================================== */

/**
 * AJAX: run themify_rank_check_all() and return the results table HTML. Uses the
 * shared 'themify_admin' nonce (localized as themifyAdmin.nonce) and the generic
 * .tf-run runner, which injects the returned .data.html into the target node.
 */
function themify_rank_check_ajax() {
	check_ajax_referer( 'themify_admin', 'nonce' );
	if ( ! current_user_can( THEMIFY_CAP ) ) {
		wp_send_json_error( array( 'message' => __( 'Permission denied.', 'themify' ) ) );
	}
	if ( ! themify_serp_has_key() ) {
		wp_send_json_error( array( 'message' => __( 'Add a SERP API key first (any provider).', 'themify' ) ) );
	}
	if ( ! themify_rank_get_keywords() ) {
		wp_send_json_error( array( 'message' => __( 'Add at least one keyword to track first.', 'themify' ) ) );
	}

	$result = themify_rank_check_all();

	$notice = sprintf(
		'<div class="tf-notice tf-notice--info">%s</div>',
		esc_html(
			sprintf(
				/* translators: 1: number checked, 2: number of errors */
				_n( 'Checked %1$d keyword. %2$d could not be fetched.', 'Checked %1$d keywords. %2$d could not be fetched.', (int) $result['checked'], 'themify' ),
				(int) $result['checked'],
				(int) $result['errors']
			)
		)
	);

	wp_send_json_success( array(
		'html' => $notice . themify_rank_results_table_html( $result['data'] ),
	) );
}
add_action( 'wp_ajax_themify_rank_check', 'themify_rank_check_ajax' );

/* =========================================================================
 * RENDER HELPERS
 * ====================================================================== */

/**
 * Background/text colors for the big rank-position tile, bucketed like the
 * bottom legend (Top 3 / 4-10 / 11-30 / 31-50 / 50+ / not ranked). All colors
 * come from the Themixify brand palette.
 *
 * @param int|null $position 1-based rank, or null when not ranked.
 * @return array [ background, text ]
 */
function themify_rank_pos_colors( $position ) {
	if ( null === $position || '' === $position ) {
		return array( '#e7f0e9', '#5a6b62' );
	}
	$p = (int) $position;
	if ( $p <= 3 ) {
		return array( '#156b28', '#ffffff' );
	}
	if ( $p <= 10 ) {
		return array( '#1e8f38', '#ffffff' );
	}
	if ( $p <= 30 ) {
		return array( '#b8860b', '#ffffff' );
	}
	if ( $p <= 50 ) {
		return array( '#8a6d0b', '#ffffff' );
	}
	return array( '#c0392b', '#ffffff' );
}

/**
 * Build the change indicator comparing current vs previous. A lower position
 * number is better, so a decrease is an improvement (▲, green).
 *
 * @param int|null $current  Current position.
 * @param int|null $previous Previous position.
 * @return string HTML fragment (escaped).
 */
function themify_rank_change_html( $current, $previous ) {
	$cur = ( null === $current || '' === $current ) ? null : (int) $current;
	$prv = ( null === $previous || '' === $previous ) ? null : (int) $previous;

	if ( null === $prv && null === $cur ) {
		return '<span class="tfr-chg">— 0</span>';
	}
	if ( null === $prv ) {
		return '<span class="tfr-chg">' . esc_html__( 'New', 'themify' ) . '</span>';
	}
	if ( null === $cur ) {
		return '<span class="tfr-chg tfr-chg--down">▼ ' . esc_html__( 'Dropped', 'themify' ) . '</span>';
	}

	$delta = $prv - $cur; // positive = moved up (rank number went down).
	if ( 0 === $delta ) {
		return '<span class="tfr-chg">— 0</span>';
	}
	if ( $delta > 0 ) {
		return '<span class="tfr-chg tfr-chg--up">▲ ' . esc_html( number_format_i18n( $delta ) ) . '</span>';
	}
	return '<span class="tfr-chg tfr-chg--down">▼ ' . esc_html( number_format_i18n( abs( $delta ) ) ) . '</span>';
}

/**
 * Build the results .tf-table HTML for all tracked keywords, merging the stored
 * results with the keyword list so untracked-yet keywords still show a row.
 *
 * @param array|null $data Optional results map; defaults to the stored data.
 * @return string HTML.
 */
function themify_rank_results_table_html( $data = null ) {
	if ( null === $data ) {
		$data = themify_rank_get_data();
	}
	$keywords = themify_rank_get_keywords();

	if ( empty( $keywords ) ) {
		return '<div class="tfr-empty"><span class="dashicons dashicons-chart-bar"></span>' . esc_html__( 'No keywords tracked yet. Add one above to start tracking.', 'themify' ) . '</div>';
	}

	$home = untrailingslashit( home_url( '/' ) );

	$html  = '<div class="tfx-tablewrap" style="max-height:none;padding:0 22px 8px;">';
	$html .= '<table class="tfx-table tfr-table"><thead><tr>';
	$html .= '<th>#</th>';
	$html .= '<th>' . esc_html__( 'Keyword', 'themify' ) . '</th>';
	$html .= '<th>' . esc_html__( 'Volume', 'themify' ) . ' <span class="dashicons dashicons-sort" style="font-size:12px;width:12px;height:12px;color:#c3cfc7;"></span></th>';
	$html .= '<th>' . esc_html__( 'US Position', 'themify' ) . ' <span class="dashicons dashicons-sort" style="font-size:12px;width:12px;height:12px;color:#c3cfc7;"></span></th>';
	$html .= '<th>' . esc_html__( 'Change', 'themify' ) . '</th>';
	$html .= '<th>' . esc_html__( 'Ranking URL', 'themify' ) . '</th>';
	$html .= '<th class="tfx-r">' . esc_html__( 'Checked', 'themify' ) . '</th>';
	$html .= '<th class="tfx-r"></th>';
	$html .= '</tr></thead><tbody>';

	$i = 0;
	foreach ( $keywords as $row ) {
		$i++;
		$keyword = $row['keyword'];
		$rec     = isset( $data[ $keyword ] ) && is_array( $data[ $keyword ] ) ? $data[ $keyword ] : array();

		$current    = array_key_exists( 'current', $rec ) ? $rec['current'] : null;
		$previous   = array_key_exists( 'previous', $rec ) ? $rec['previous'] : null;
		$url        = isset( $rec['url'] ) ? (string) $rec['url'] : '';
		$checked_at = isset( $rec['checked_at'] ) ? (int) $rec['checked_at'] : 0;

		list( $pos_bg, $pos_fg ) = themify_rank_pos_colors( $current );
		$pos_label = ( null === $current || '' === $current )
			? __( 'N/A', 'themify' )
			: sprintf( /* translators: %d: rank position */ __( '#%d', 'themify' ), (int) $current );

		$html .= '<tr>';
		$html .= '<td class="tfx-rank">' . (int) $i . '</td>';

		// Keyword (+ its optional target beneath).
		$html .= '<td><strong style="font-size:13.5px;color:#1a2b20;">' . esc_html( $keyword ) . '</strong>';
		if ( '' !== $row['target'] ) {
			$html .= '<div style="font-size:11.5px;color:#8fa096;margin-top:2px;">' . esc_html__( 'Target:', 'themify' ) . ' ' . esc_html( $row['target'] ) . '</div>';
		}
		$html .= '</td>';

		// Volume — manually entered, saved inline via AJAX on change.
		$vol   = isset( $row['volume'] ) ? (int) $row['volume'] : 0;
		$html .= '<td><input type="number" class="tfr-volin" data-keyword="' . esc_attr( $keyword ) . '" value="' . esc_attr( $vol > 0 ? (string) $vol : '' ) . '" min="0" step="1" placeholder="—" title="' . esc_attr__( 'Monthly search volume — type a number, it saves automatically', 'themify' ) . '" /></td>';

		// Big position tile.
		$html .= '<td><span class="tfr-pos" style="background:' . esc_attr( $pos_bg ) . ';color:' . esc_attr( $pos_fg ) . ';' . ( null === $current ? 'box-shadow:none;' : '' ) . '">' . esc_html( $pos_label ) . '</span></td>';

		// Change indicator.
		$html .= '<td>' . ( empty( $rec ) ? '<span class="tfr-chg">—</span>' : themify_rank_change_html( $current, $previous ) ) . '</td>';

		// Ranking URL (path only, like the reference).
		if ( '' !== $url ) {
			$label = 0 === strpos( $url, $home ) ? substr( $url, strlen( $home ) ) : themify_rank_shorten_url( $url );
			if ( '' === $label ) {
				$label = '/';
			}
			$html .= '<td><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer" style="color:#33463a;font-weight:600;text-decoration:none;">' . esc_html( $label ) . '</a></td>';
		} else {
			$html .= '<td><span class="tfr-chg">—</span></td>';
		}

		// Checked.
		$html .= '<td class="tfx-r" style="color:#8fa096;">' . ( $checked_at ? esc_html( themify_time_ago( $checked_at ) ) : esc_html__( 'Never', 'themify' ) ) . '</td>';

		// Row actions: re-check + delete.
		$del_url = wp_nonce_url(
			add_query_arg(
				array(
					'page'    => 'themify-rank-tracker',
					'tfr_del' => rawurlencode( $keyword ),
				),
				admin_url( 'admin.php' )
			),
			'themify_rank_del'
		);
		$html .= '<td class="tfx-r" style="white-space:nowrap;">';
		$html .= '<button type="button" class="tfr-act tfr-play" data-keyword="' . esc_attr( $keyword ) . '" title="' . esc_attr__( 'Check this keyword now', 'themify' ) . '"><span class="dashicons dashicons-controls-play"></span></button>';
		$html .= '<a class="tfr-act tfr-del" href="' . esc_url( $del_url ) . '" title="' . esc_attr__( 'Remove keyword', 'themify' ) . '"><span class="dashicons dashicons-trash"></span></a>';
		$html .= '</td>';

		$html .= '</tr>';
	}

	$html .= '</tbody></table>';
	$html .= '</div>';
	return $html;
}

/**
 * Shorten a URL for display (drop the scheme + trailing slash, trim length).
 *
 * @param string $url Full URL.
 * @return string
 */
function themify_rank_shorten_url( $url ) {
	$short = preg_replace( '#^https?://#i', '', (string) $url );
	$short = untrailingslashit( $short );
	if ( strlen( $short ) > 60 ) {
		$short = substr( $short, 0, 57 ) . '…';
	}
	return $short;
}

/**
 * Render one API-key repeater row (provider select + key input + remove).
 *
 * @param int|string $index Row index (numeric, or '__INDEX__' for the template).
 * @param array      $row   ['provider'=>, 'key'=>].
 */
function themify_rank_render_key_row( $index, array $row = array() ) {
	$row  = wp_parse_args( $row, array(
		'provider' => 'serpapi',
		'key'      => '',
	) );
	$base = 'themify_serp_rows[' . $index . ']';

	echo '<div class="tf-repeater__row" style="grid-template-columns: 260px 1fr auto; align-items:end;">';

	// Provider select.
	echo '<div class="tf-field tf-field--select" style="margin-bottom:0;">';
	printf( '<label class="tf-field__label">%s</label>', esc_html__( 'Provider', 'themify' ) );
	printf( '<select name="%s[provider]" class="tf-input tf-select" style="max-width:none;">', esc_attr( $base ) );
	foreach ( themify_serp_providers() as $slug => $label ) {
		printf(
			'<option value="%s" %s>%s</option>',
			esc_attr( $slug ),
			selected( $row['provider'], $slug, false ),
			esc_html( $label )
		);
	}
	echo '</select>';
	echo '</div>';

	// API key.
	echo '<div class="tf-field tf-field--text" style="margin-bottom:0;">';
	printf( '<label class="tf-field__label">%s</label>', esc_html__( 'API key', 'themify' ) );
	printf(
		'<input type="text" name="%s[key]" value="%s" class="tf-input" style="max-width:none;" placeholder="%s" autocomplete="off" />',
		esc_attr( $base ),
		esc_attr( $row['key'] ),
		esc_attr__( 'Paste the private API key from this provider', 'themify' )
	);
	echo '</div>';

	// Remove control.
	echo '<div class="tf-field" style="margin-bottom:0;"><a href="#" class="tf-remove">' . esc_html__( 'Remove', 'themify' ) . '</a></div>';

	echo '</div>'; // .tf-repeater__row
}

/**
 * Render the hero grid: the big quota card (left, spanning both rows) plus the
 * Keywords / Top 10 / Avg Position / Ranking / Last Check stat cards.
 *
 * @param array $quota    Result of themify_serp_quota().
 * @param array $keywords Tracked keywords.
 * @param array $data     Stored results map.
 */
function themify_rank_render_hero( array $quota, array $keywords, array $data ) {
	$tracked = count( $keywords );
	$ranked  = 0;
	$top10   = 0;
	$sum     = 0;
	$last    = 0;

	foreach ( $keywords as $row ) {
		$rec = isset( $data[ $row['keyword'] ] ) && is_array( $data[ $row['keyword'] ] ) ? $data[ $row['keyword'] ] : array();
		$cur = array_key_exists( 'current', $rec ) ? $rec['current'] : null;
		if ( null !== $cur && '' !== $cur ) {
			$ranked++;
			$sum += (int) $cur;
			if ( (int) $cur <= 10 ) {
				$top10++;
			}
		}
		if ( ! empty( $rec['checked_at'] ) ) {
			$last = max( $last, (int) $rec['checked_at'] );
		}
	}
	$avg = $ranked ? round( $sum / $ranked, 1 ) : 0;

	$left  = (int) $quota['left'];
	$used  = (int) $quota['used'];
	$total = max( $used + $left, 1 );

	echo '<div class="tfr-hero">';

	// --- Big quota card (spans both rows). ---
	$quota_unknown = ! empty( $quota['unknown'] );
	echo '<div class="tfx-card tfr-quota">';
	echo '<div class="tfr-quota__num">' . esc_html( $quota_unknown ? '—' : number_format_i18n( $left ) ) . '</div>';
	echo '<div class="tfr-quota__lbl">' . esc_html__( 'Remaining', 'themify' ) . '</div>';
	if ( $quota_unknown ) {
		echo '<div class="tfr-quota__used">' . esc_html__( 'Quota info unavailable', 'themify' ) . '</div>';
		echo '<div class="tfr-quota__sub">' . esc_html__( 'searches still work — check usage on the provider dashboard', 'themify' ) . '</div>';
	} else {
		echo '<div class="tfr-quota__used">' . esc_html( sprintf(
			/* translators: 1: used, 2: total */
			__( '%1$s / %2$s searches used', 'themify' ),
			number_format_i18n( $used ),
			number_format_i18n( $used + $left )
		) ) . '</div>';
		echo '<div class="tfr-quota__sub">' . esc_html__( 'This month', 'themify' ) . '</div>';
	}
	echo '<span class="tfr-pill"><span class="dashicons dashicons-admin-network" style="font-size:14px;width:14px;height:14px;"></span>' . esc_html( sprintf(
		/* translators: %d: number of keys */
		_n( '%d key loaded', '%d keys loaded', count( $quota['keys'] ), 'themify' ),
		count( $quota['keys'] )
	) ) . '</span>';
	echo '</div>';

	// --- Stat cards. ---
	$stats = array(
		array( __( 'Keywords', 'themify' ), esc_html( number_format_i18n( $tracked ) ), __( 'tracked', 'themify' ), 'marker' ),
		array( __( 'Top 10', 'themify' ), esc_html( number_format_i18n( $top10 ) ), __( 'keywords', 'themify' ), 'awards' ),
		array( __( 'Avg Position', 'themify' ), $ranked ? '#' . esc_html( number_format_i18n( $avg, 1 ) ) : '—', __( 'across all', 'themify' ), 'chart-line' ),
		array( __( 'Ranking', 'themify' ), esc_html( number_format_i18n( $ranked ) ) . '<small>/' . esc_html( number_format_i18n( $tracked ) ) . '</small>', __( 'found in top 100', 'themify' ), 'performance' ),
		array( __( 'Last Check', 'themify' ), $last ? esc_html( themify_time_ago( $last ) ) : esc_html__( 'Never', 'themify' ), sprintf( /* translators: %d: keyword count */ __( 'uses %d searches', 'themify' ), $tracked ), 'clock' ),
	);
	foreach ( $stats as $s ) {
		echo '<div class="tfx-card tfr-stat">';
		echo '<div class="tfr-stat__top"><span>' . esc_html( $s[0] ) . '</span><span class="dashicons dashicons-' . esc_attr( $s[3] ) . '"></span></div>';
		echo '<div class="tfr-stat__num">' . wp_kses( $s[1], array( 'small' => array() ) ) . '</div>';
		echo '<div class="tfr-stat__sub">' . esc_html( $s[2] ) . '</div>';
		echo '</div>';
	}

	echo '</div>'; // .tfr-hero

	// Surface any per-key errors (e.g. an invalid backup key) as a warning.
	foreach ( $quota['keys'] as $key_info ) {
		if ( '' !== $key_info['error'] ) {
			printf(
				'<div class="tf-notice tf-notice--warn">%s %s</div>',
				esc_html( sprintf( /* translators: %s: key label */ __( '%s:', 'themify' ), $key_info['label'] ) ),
				esc_html( $key_info['error'] )
			);
		}
	}
}

/* =========================================================================
 * QUICK ADD / DELETE + PER-KEYWORD CHECK
 * ====================================================================== */

/**
 * Handle the quick "Add keyword" form POST. Appends one keyword (de-duped).
 *
 * @return string|null The added keyword, or null when nothing was added.
 */
function themify_rank_handle_quick_add() {
	if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! isset( $_POST['themify_rank_new'] ) ) {
		return null;
	}
	if ( ! current_user_can( THEMIFY_CAP ) ) {
		return null;
	}
	$nonce = isset( $_POST['themify_rank_add_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['themify_rank_add_nonce'] ) ) : '';
	if ( ! wp_verify_nonce( $nonce, 'themify_rank_add' ) ) {
		return null;
	}

	$keyword = sanitize_text_field( wp_unslash( $_POST['themify_rank_new'] ) );
	$keyword = trim( $keyword );
	if ( '' === $keyword ) {
		return null;
	}

	$keywords = themify_rank_get_keywords();
	foreach ( $keywords as $row ) {
		if ( 0 === strcasecmp( $row['keyword'], $keyword ) ) {
			return null; // Already tracked.
		}
	}
	$keywords[] = array(
		'keyword' => $keyword,
		'target'  => '',
	);
	themify_rank_save_keywords( $keywords );
	return $keyword;
}

/**
 * Handle the per-row delete link (?tfr_del=… + nonce). Removes the keyword and
 * its stored history.
 *
 * @return string|null The removed keyword, or null.
 */
function themify_rank_handle_delete() {
	if ( ! isset( $_GET['tfr_del'] ) ) {
		return null;
	}
	if ( ! current_user_can( THEMIFY_CAP ) ) {
		return null;
	}
	$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
	if ( ! wp_verify_nonce( $nonce, 'themify_rank_del' ) ) {
		return null;
	}

	$keyword = sanitize_text_field( rawurldecode( wp_unslash( $_GET['tfr_del'] ) ) );
	if ( '' === trim( $keyword ) ) {
		return null;
	}

	$keywords = themify_rank_get_keywords();
	$kept     = array();
	$removed  = null;
	foreach ( $keywords as $row ) {
		if ( 0 === strcasecmp( $row['keyword'], $keyword ) ) {
			$removed = $row['keyword'];
			continue;
		}
		$kept[] = $row;
	}
	if ( null === $removed ) {
		return null;
	}
	themify_rank_save_keywords( $kept );

	$data = themify_rank_get_data();
	unset( $data[ $removed ] );
	themify_rank_save_data( $data );

	return $removed;
}

/**
 * AJAX: re-check a single keyword (the per-row play button). Busts the 6-hour
 * SERP cache for that query so the result is fresh, then updates its history.
 */
function themify_rank_check_one_ajax() {
	check_ajax_referer( 'themify_admin', 'nonce' );
	if ( ! current_user_can( THEMIFY_CAP ) ) {
		wp_send_json_error( array( 'message' => __( 'Permission denied.', 'themify' ) ) );
	}
	if ( ! themify_serp_has_key() ) {
		wp_send_json_error( array( 'message' => __( 'Add a SERP API key first (any provider).', 'themify' ) ) );
	}

	$keyword = isset( $_POST['keyword'] ) ? sanitize_text_field( wp_unslash( $_POST['keyword'] ) ) : '';
	$keyword = trim( $keyword );

	$tracked = false;
	foreach ( themify_rank_get_keywords() as $row ) {
		if ( 0 === strcasecmp( $row['keyword'], $keyword ) ) {
			$tracked = true;
			$keyword = $row['keyword'];
			break;
		}
	}
	if ( ! $tracked ) {
		wp_send_json_error( array( 'message' => __( 'That keyword is not tracked.', 'themify' ) ) );
	}

	// Force a fresh SERP for this one query.
	delete_transient( 'themify_serp_' . md5( strtolower( $keyword ) ) );

	$result = themify_serp_search( $keyword );
	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ) );
	}

	$data     = themify_rank_get_data();
	$previous = isset( $data[ $keyword ]['current'] ) ? $data[ $keyword ]['current'] : null;

	$data[ $keyword ] = array(
		'current'    => $result['position'],
		'previous'   => $previous,
		'url'        => (string) $result['url'],
		'checked_at' => time(),
	);
	themify_rank_save_data( $data );

	wp_send_json_success( array( 'message' => __( 'Checked.', 'themify' ) ) );
}
add_action( 'wp_ajax_themify_rank_check_one', 'themify_rank_check_one_ajax' );

/**
 * AJAX: save a manually-entered monthly search volume for one keyword (the
 * inline Volume input in the results table).
 */
function themify_rank_set_volume_ajax() {
	check_ajax_referer( 'themify_admin', 'nonce' );
	if ( ! current_user_can( THEMIFY_CAP ) ) {
		wp_send_json_error( array( 'message' => __( 'Permission denied.', 'themify' ) ) );
	}

	$keyword = isset( $_POST['keyword'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['keyword'] ) ) ) : '';
	$volume  = isset( $_POST['volume'] ) ? max( 0, (int) $_POST['volume'] ) : 0;

	$keywords = themify_rank_get_keywords();
	$found    = false;
	foreach ( $keywords as $i => $row ) {
		if ( 0 === strcasecmp( $row['keyword'], $keyword ) ) {
			$keywords[ $i ]['volume'] = $volume;
			$found                    = true;
			break;
		}
	}
	if ( ! $found ) {
		wp_send_json_error( array( 'message' => __( 'That keyword is not tracked.', 'themify' ) ) );
	}

	themify_rank_save_keywords( $keywords );
	wp_send_json_success( array( 'message' => __( 'Volume saved.', 'themify' ) ) );
}
add_action( 'wp_ajax_themify_rank_set_volume', 'themify_rank_set_volume_ajax' );

/* =========================================================================
 * PAGE ASSETS
 * ====================================================================== */

/**
 * Print the Rank Tracker CSS + JS (brand palette; per-row check + delete
 * confirm). Complements themify_analytics_print_assets().
 */
function themify_rank_print_assets() {
	$nonce = wp_create_nonce( 'themify_admin' );
	?>
	<style>
	body[class*="themify-rank-tracker"] #wpcontent{background:#f3f8f5}
	.tfr-pageicon{width:46px;height:46px;border-radius:13px;background:#1e8f38;display:flex;align-items:center;justify-content:center;flex:0 0 auto;box-shadow:0 5px 12px rgba(30,143,56,.35)}
	.tfr-pageicon .dashicons{color:#fff;font-size:23px;width:23px;height:23px}
	.tfr-hero{display:grid;grid-template-columns:1.15fr 1fr 1fr 1.15fr;grid-auto-rows:minmax(120px,auto);gap:16px;margin-bottom:22px}
	@media(max-width:1200px){.tfr-hero{grid-template-columns:1fr 1fr}}
	.tfr-quota{grid-row:1 / 3;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;padding:26px 22px}
	@media(max-width:1200px){.tfr-quota{grid-row:auto;grid-column:1 / 3}}
	.tfr-quota__num{font-size:40px;font-weight:800;color:#1e8f38;line-height:1}
	.tfr-quota__lbl{font-size:11px;letter-spacing:.9px;color:#8fa096;font-weight:700;margin-top:7px;text-transform:uppercase}
	.tfr-quota__used{font-size:13.5px;color:#33463a;font-weight:600;margin-top:20px}
	.tfr-quota__sub{font-size:11.5px;color:#8fa096;margin-top:2px}
	.tfr-pill{display:inline-flex;align-items:center;gap:5px;background:#e3f5e8;color:#156b28;border:1px solid #cde9d6;border-radius:999px;padding:5px 13px;font-size:11.5px;font-weight:700;margin-top:15px}
	.tfr-stat{padding:18px 20px}
	.tfr-stat__top{display:flex;justify-content:space-between;align-items:center;font-size:11px;font-weight:700;letter-spacing:.7px;text-transform:uppercase;color:#8fa096}
	.tfr-stat__top .dashicons{color:#8fa096;font-size:17px;width:17px;height:17px}
	.tfr-stat__num{font-size:27px;font-weight:800;color:#1a2b20;margin-top:11px;line-height:1}
	.tfr-stat__num small{font-size:15px;font-weight:700;color:#8fa096}
	.tfr-stat__sub{font-size:12px;color:#8fa096;margin-top:6px}
	.tfr-actions{display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap;margin-bottom:18px}
	.tfr-addform{display:flex;gap:8px;align-items:center;flex:0 1 480px;min-width:280px}
	.tfr-addform input[type=text]{flex:1 1 auto;border:1px solid #dbe4de;border-radius:10px;padding:10px 14px;font-size:13px;background:#fff;color:#1a2b20;box-shadow:0 1px 2px rgba(16,24,40,.04)}
	.tfr-addform input[type=text]:focus{outline:none;border-color:#1e8f38;box-shadow:0 0 0 3px rgba(30,143,56,.12)}
	.tfr-add{background:#8fcf9e;color:#fff;border:none;border-radius:10px;padding:10px 20px;font-weight:700;font-size:13px;cursor:pointer;transition:background .15s}
	.tfr-add:hover{background:#1e8f38}
	.tfr-runbtn{display:inline-flex;align-items:center;gap:8px;background:#1e8f38;color:#fff;border:none;border-radius:12px;padding:13px 26px;font-size:14px;font-weight:700;cursor:pointer;box-shadow:0 6px 18px rgba(30,143,56,.45)}
	.tfr-runbtn:hover{background:#156b28;color:#fff}
	.tfr-runbtn .dashicons{font-size:17px;width:17px;height:17px}
	.tfr-pos{display:inline-flex;align-items:center;justify-content:center;min-width:52px;height:52px;padding:0 10px;border-radius:14px;font-weight:800;font-size:14px;box-shadow:0 5px 12px rgba(26,43,32,.18)}
	.tfr-chg{color:#8fa096;font-weight:600;font-size:13px}
	.tfr-chg--up{color:#1e8f38;font-weight:700}
	.tfr-chg--down{color:#c0392b;font-weight:700}
	.tfr-vol{display:inline-block;background:#f0f6f1;border:1px solid #e2e8ec;border-radius:7px;padding:4px 12px;font-size:12px;color:#43564a}
	.tfr-volin{width:96px;border:1px solid #dbe4de;border-radius:8px;padding:7px 10px;font-size:12.5px;font-weight:600;text-align:center;background:#fff;color:#33463a;-moz-appearance:textfield}
	.tfr-volin::-webkit-outer-spin-button,.tfr-volin::-webkit-inner-spin-button{-webkit-appearance:none;margin:0}
	.tfr-volin:focus{outline:none;border-color:#1e8f38;box-shadow:0 0 0 3px rgba(30,143,56,.12)}
	.tfr-volin.is-saved{border-color:#1e8f38;background:#e3f5e8}
	.tfr-table td{vertical-align:middle}
	.tfr-act{display:inline-flex;align-items:center;justify-content:center;background:none;border:none;cursor:pointer;padding:5px;border-radius:7px;text-decoration:none}
	.tfr-act .dashicons{font-size:17px;width:17px;height:17px}
	.tfr-play .dashicons{color:#1e8f38}
	.tfr-del .dashicons{color:#c0392b}
	.tfr-act:hover{background:#eef4f0}
	.tfr-act.is-busy{opacity:.4;pointer-events:none}
	.tfr-legend{display:flex;justify-content:center;gap:22px;flex-wrap:wrap;margin:18px 0 6px;font-size:12.5px;color:#5a6b62;font-weight:600}
	.tfr-dot{width:10px;height:10px;border-radius:50%;display:inline-block;margin-right:6px;vertical-align:-1px}
	.tfr-empty{padding:48px 22px;text-align:center;color:#5a6b62;font-size:14px}
	.tfr-empty .dashicons{font-size:34px;width:34px;height:34px;color:#c3cfc7;display:block;margin:0 auto 10px}
	</style>
	<script>
	(function(){
		var TFR_NONCE = <?php echo wp_json_encode( $nonce ); ?>;
		var CONFIRM_DEL = <?php echo wp_json_encode( __( 'Remove this keyword and its history?', 'themify' ) ); ?>;

		document.addEventListener('click', function (e) {
			var play = e.target.closest('.tfr-play');
			if (play) {
				e.preventDefault();
				if (play.classList.contains('is-busy')) { return; }
				play.classList.add('is-busy');
				var d = new FormData();
				d.append('action', 'themify_rank_check_one');
				d.append('nonce', TFR_NONCE);
				d.append('keyword', play.getAttribute('data-keyword'));
				fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: d })
					.then(function (r) { return r.json(); })
					.then(function (res) {
						if (res && res.success) {
							location.reload();
						} else {
							play.classList.remove('is-busy');
							var box = document.getElementById('tfr-msg');
							if (box) { box.innerHTML = '<div class="tf-notice tf-notice--warn">' + ((res && res.data && res.data.message) || 'Check failed.') + '</div>'; }
						}
					})
					.catch(function () { play.classList.remove('is-busy'); });
				return;
			}
			var del = e.target.closest('.tfr-del');
			if (del && ! window.confirm(CONFIRM_DEL)) {
				e.preventDefault();
			}
		});

		// Inline volume editing — saves automatically when the value changes.
		document.addEventListener('change', function (e) {
			var vol = e.target.closest('.tfr-volin');
			if (!vol) { return; }
			var d = new FormData();
			d.append('action', 'themify_rank_set_volume');
			d.append('nonce', TFR_NONCE);
			d.append('keyword', vol.getAttribute('data-keyword'));
			d.append('volume', vol.value || '0');
			fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: d })
				.then(function (r) { return r.json(); })
				.then(function (res) {
					if (res && res.success) {
						vol.classList.add('is-saved');
						setTimeout(function () { vol.classList.remove('is-saved'); }, 1200);
					}
				})
				.catch(function () {});
		});
	})();
	</script>
	<?php
}

/* =========================================================================
 * MAIN ADMIN SCREEN
 * ====================================================================== */

/**
 * The Rank Tracker admin screen: hero quota/stat grid, quick-add + Run Check
 * row, the rankings table with position tiles, the color legend, and the
 * keyword-manager + API-key form.
 */
function themify_rank_tracker_page() {
	$saved   = themify_rank_handle_save();
	$added   = themify_rank_handle_quick_add();
	$deleted = themify_rank_handle_delete();

	echo '<div class="wrap tfx">';
	if ( function_exists( 'themify_analytics_print_assets' ) ) {
		themify_analytics_print_assets();
	}
	themify_rank_print_assets();

	// ---- Header ----
	echo '<div class="tfx-head">';
	echo '<div style="display:flex;gap:14px;align-items:flex-start;">';
	echo '<span class="tfr-pageicon"><span class="dashicons dashicons-chart-bar"></span></span>';
	echo '<div>';
	echo '<h1>' . esc_html__( 'Rank Tracker', 'themify' ) . '</h1>';
	echo '<p class="tfx-sub">' . esc_html__( 'Track your Google rankings for target keywords (US)', 'themify' ) . '</p>';
	echo '</div>';
	echo '</div>';
	echo '</div>';
	echo '<div id="tfr-msg"></div>';

	if ( $saved ) {
		echo '<div class="tf-notice tf-notice--info">' . esc_html__( 'Rank Tracker settings saved.', 'themify' ) . '</div>';
	}
	if ( null !== $added ) {
		echo '<div class="tf-notice tf-notice--info">' . esc_html( sprintf( /* translators: %s: keyword */ __( 'Now tracking “%s”. Click Run Check to fetch its position.', 'themify' ), $added ) ) . '</div>';
	}
	if ( null !== $deleted ) {
		echo '<div class="tf-notice tf-notice--info">' . esc_html( sprintf( /* translators: %s: keyword */ __( 'Removed “%s”.', 'themify' ), $deleted ) ) . '</div>';
	}

	$has_key  = themify_serp_has_key();
	$keywords = themify_rank_get_keywords();
	$data     = themify_rank_get_data();

	if ( ! $has_key ) {
		// Setup notice when no key is configured yet.
		echo '<div class="tf-notice tf-notice--warn">';
		echo '<strong>' . esc_html__( 'Add a SERP API key to start tracking', 'themify' ) . '</strong>';
		echo '<p style="margin:8px 0 0;">' . wp_kses_post( __( 'Create a free account at any supported provider — <a href="https://serper.dev/" target="_blank" rel="noopener noreferrer">Serper.dev</a> (2,500 free), <a href="https://www.searchapi.io/" target="_blank" rel="noopener noreferrer">SearchApi.io</a>, <a href="https://serpapi.com/" target="_blank" rel="noopener noreferrer">SerpAPI</a>, ValueSERP, Zenserp & more — copy the private API key, and add it in the form below. Add several keys from different providers and the tracker automatically switches to the next one when a key runs out.', 'themify' ) ) . '</p>';
		echo '</div>';
	} else {
		// ---- Hero grid: quota + stats. ----
		themify_rank_render_hero( themify_serp_quota(), $keywords, $data );

		// ---- Quick add + Run Check row. ----
		echo '<div class="tfr-actions">';
		echo '<form method="post" class="tfr-addform">';
		wp_nonce_field( 'themify_rank_add', 'themify_rank_add_nonce' );
		printf(
			'<input type="text" name="themify_rank_new" placeholder="%s" />',
			esc_attr__( 'e.g. car battery guide', 'themify' )
		);
		echo '<button type="submit" class="tfr-add">' . esc_html__( 'Add', 'themify' ) . '</button>';
		echo '</form>';
		echo '<button class="tfr-runbtn tf-run" data-action="themify_rank_check" data-target="#tf-rank-result" data-running="' . esc_attr__( 'Checking…', 'themify' ) . '"><span class="dashicons dashicons-controls-play"></span>' . esc_html__( 'Run Check', 'themify' ) . '</button>';
		echo '</div>';

		// ---- Results table (replaced in place by the Run Check AJAX). ----
		echo '<div class="tfx-card" style="padding-top:6px;">';
		echo '<div id="tf-rank-result">';
		echo themify_rank_results_table_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- builder escapes every field internally.
		echo '</div>';
		echo '</div>';

		// ---- Position color legend. ----
		$legend = array(
			array( '#156b28', __( 'Top 3', 'themify' ) ),
			array( '#1e8f38', __( '4-10', 'themify' ) ),
			array( '#b8860b', __( '11-30', 'themify' ) ),
			array( '#8a6d0b', __( '31-50', 'themify' ) ),
			array( '#c0392b', __( '50+', 'themify' ) ),
		);
		echo '<div class="tfr-legend">';
		foreach ( $legend as $item ) {
			echo '<span><span class="tfr-dot" style="background:' . esc_attr( $item[0] ) . ';"></span>' . esc_html( $item[1] ) . '</span>';
		}
		echo '</div>';
	}

	echo '<form method="post" class="tf-form">';
	wp_nonce_field( 'themify_rank', 'themify_nonce' );

	// API keys — unlimited rows, any mix of providers, in failover order.
	$key_rows = themify_serp_key_rows();

	echo '<div class="tf-card">';
	echo '<h2 class="tf-card__title">' . esc_html__( 'SERP API keys', 'themify' ) . '</h2>';
	echo '<p class="tf-card__desc">' . wp_kses_post( __( 'Add as many API keys as you like, from any mix of providers — <a href="https://serpapi.com/" target="_blank" rel="noopener noreferrer">SerpAPI</a>, <a href="https://www.searchapi.io/" target="_blank" rel="noopener noreferrer">SearchApi.io</a>, <a href="https://serper.dev/" target="_blank" rel="noopener noreferrer">Serper.dev</a>, ValueSERP, Scale SERP, SerpWow, Zenserp, Serpstack (all have free tiers). Keys are tried <strong>top to bottom</strong>: when one runs out of searches or errors, the next automatically takes over. Keys are stored securely and never shown on the public site.', 'themify' ) ) . '</p>';

	echo '<div class="tf-repeater">';

	// Hidden template row for JS-added rows.
	echo '<script type="text/html" class="tf-repeater__template">';
	themify_rank_render_key_row( '__INDEX__' );
	echo '</script>';

	echo '<div class="tf-repeater__rows">';
	if ( $key_rows ) {
		foreach ( $key_rows as $i => $key_row ) {
			themify_rank_render_key_row( $i, $key_row );
		}
	} else {
		themify_rank_render_key_row( 0 );
	}
	echo '</div>';

	echo '<p><button type="button" class="button tf-repeater__add">' . esc_html__( '+ Add API key', 'themify' ) . '</button></p>';
	echo '</div>'; // .tf-repeater
	echo '</div>';

	echo '<p class="tf-form__actions"><button type="submit" class="button button-primary button-hero">' . esc_html__( 'Save Changes', 'themify' ) . '</button></p>';
	echo '</form>';

	echo '<button type="button" class="tfx-top" aria-label="' . esc_attr__( 'Scroll to top', 'themify' ) . '"><span class="dashicons dashicons-arrow-up-alt2"></span></button>';
	echo '</div>'; // .tfx
}
