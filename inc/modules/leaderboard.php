<?php
/**
 * Leaderboard — which content (and keywords) earn the site its traffic.
 *
 * Ranks every page by GA4 pageviews (and every search keyword by Search
 * Console clicks) over a selectable date range. The top three stand on a
 * podium — #1 centre, #2 left, #3 right — and everything else follows in a
 * numbered list, so the money-makers are always obvious at a glance.
 *
 * Data comes from the same admin-only, cached Google connections the
 * Analytics module owns (service account → GA4 Data API + Search Console).
 * Each leaderboard is cached in a transient for 30 minutes per range. No
 * external HTTP ever runs on a public page load.
 *
 * @package Themify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Transient prefix for the cached leaderboards. */
if ( ! defined( 'THEMIFY_LB_CACHE' ) ) {
	define( 'THEMIFY_LB_CACHE', 'themify_leaderboard_v2' );
}

/* =========================================================================
 * DATA
 * ====================================================================== */

/**
 * The selected range key (?tflb_range=…), validated against the shared
 * Analytics range list. Defaults to lifetime — "best of all time".
 *
 * @return string
 */
function themify_lb_range() {
	$key = isset( $_GET['tflb_range'] ) ? sanitize_key( wp_unslash( $_GET['tflb_range'] ) ) : 'lifetime'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view state.
	$all = function_exists( 'themify_analytics_ranges' ) ? themify_analytics_ranges() : array( 'lifetime' => 'Lifetime' );
	return array_key_exists( $key, $all ) ? $key : 'lifetime';
}

/**
 * Top pages by views for a range (up to 250 rows), cached 30 minutes.
 *
 * @param string $range_key Range key from themify_analytics_ranges().
 * @return array|WP_Error [ ['title','path','views','users'], … ] best first.
 */
function themify_lb_pages( $range_key ) {
	if ( ! function_exists( 'themify_analytics_has_creds' ) || ! themify_analytics_has_creds() ) {
		return new WP_Error( 'themify_lb_creds', __( 'Google credentials are missing. Add them under Themixify → Analytics first.', 'themify' ) );
	}

	$cache_key = THEMIFY_LB_CACHE . '_pages_' . $range_key;
	$cached    = get_transient( $cache_key );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	$property = themify_ga4_property_id();
	if ( '' === $property ) {
		return new WP_Error( 'themify_lb_property', __( 'A GA4 property ID is required.', 'themify' ) );
	}
	$token = themify_google_access_token( array( THEMIFY_GA4_SCOPE ) );
	if ( is_wp_error( $token ) ) {
		return $token;
	}

	$dates = themify_analytics_range_dates( $range_key );
	$res   = themify_ga4_run_report( $token, $property, array(
		'dateRanges' => array(
			array(
				'startDate' => $dates['ga_start'],
				'endDate'   => $dates['ga_end'],
			),
		),
		'dimensions' => array(
			array( 'name' => 'pageTitle' ),
			array( 'name' => 'pagePath' ),
		),
		'metrics'    => array(
			array( 'name' => 'screenPageViews' ),
			array( 'name' => 'activeUsers' ),
		),
		'orderBys'   => array(
			array(
				'metric' => array( 'metricName' => 'screenPageViews' ),
				'desc'   => true,
			),
		),
		'limit'      => 250,
	) );
	if ( is_wp_error( $res ) ) {
		return $res;
	}

	// Keep BLOG POSTS only: resolve every GA4 path to a WordPress post and drop
	// everything else (pages, category/tag archives, author pages, 404s, the
	// homepage…). Duplicate paths for the same post (tracking parameters etc.)
	// are merged, and the clean WP title/permalink replace GA4's raw strings.
	$by_post = array();
	if ( isset( $res['rows'] ) && is_array( $res['rows'] ) ) {
		foreach ( $res['rows'] as $row ) {
			$path = isset( $row['dimensionValues'][1]['value'] ) ? (string) $row['dimensionValues'][1]['value'] : '';
			if ( '' === $path ) {
				continue;
			}
			// Strip any query string before mapping the path to a post.
			$clean_path = (string) wp_parse_url( $path, PHP_URL_PATH );
			if ( '' === $clean_path || '/' === $clean_path ) {
				continue;
			}

			$post_id = url_to_postid( home_url( $clean_path ) );
			if ( ! $post_id || 'post' !== get_post_type( $post_id ) || 'publish' !== get_post_status( $post_id ) ) {
				continue;
			}

			$views = isset( $row['metricValues'][0]['value'] ) ? (int) round( (float) $row['metricValues'][0]['value'] ) : 0;
			$users = isset( $row['metricValues'][1]['value'] ) ? (int) round( (float) $row['metricValues'][1]['value'] ) : 0;

			if ( ! isset( $by_post[ $post_id ] ) ) {
				$permalink            = (string) get_permalink( $post_id );
				$rel                  = (string) wp_parse_url( $permalink, PHP_URL_PATH );
				$by_post[ $post_id ] = array(
					'title' => get_the_title( $post_id ),
					'path'  => '' !== $rel ? $rel : $clean_path,
					'views' => 0,
					'users' => 0,
				);
			}
			$by_post[ $post_id ]['views'] += $views;
			$by_post[ $post_id ]['users'] += $users;
		}
	}

	$rows = array_values( $by_post );
	usort( $rows, function ( $a, $b ) {
		return $b['views'] <=> $a['views'];
	} );

	set_transient( $cache_key, $rows, 30 * MINUTE_IN_SECONDS );
	return $rows;
}

/**
 * Top search keywords by clicks for a range (up to 250 rows), cached 30 min.
 *
 * @param string $range_key Range key.
 * @return array|WP_Error [ ['query','clicks','impressions'], … ] best first.
 */
function themify_lb_queries( $range_key ) {
	if ( ! function_exists( 'themify_analytics_has_creds' ) || ! themify_analytics_has_creds() ) {
		return new WP_Error( 'themify_lb_creds', __( 'Google credentials are missing. Add them under Themixify → Analytics first.', 'themify' ) );
	}

	$cache_key = THEMIFY_LB_CACHE . '_queries_' . $range_key;
	$cached    = get_transient( $cache_key );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	$token = themify_google_access_token( array( THEMIFY_GSC_SCOPE ) );
	if ( is_wp_error( $token ) ) {
		return $token;
	}

	$site  = themify_gsc_site_url();
	$dates = themify_analytics_range_dates( $range_key );
	$res   = themify_gsc_query( $token, $site, 'query', $dates['gsc_start'], $dates['gsc_end'], 250 );
	if ( is_wp_error( $res ) ) {
		return $res;
	}

	$rows = array();
	if ( ! empty( $res['rows'] ) && is_array( $res['rows'] ) ) {
		foreach ( $res['rows'] as $row ) {
			$query = isset( $row['keys'][0] ) ? (string) $row['keys'][0] : '';
			if ( '' === $query ) {
				continue;
			}
			$rows[] = array(
				'query'       => $query,
				'clicks'      => isset( $row['clicks'] ) ? (int) round( (float) $row['clicks'] ) : 0,
				'impressions' => isset( $row['impressions'] ) ? (int) round( (float) $row['impressions'] ) : 0,
			);
		}
		usort( $rows, function ( $a, $b ) {
			return $b['clicks'] <=> $a['clicks'];
		} );
	}

	set_transient( $cache_key, $rows, 30 * MINUTE_IN_SECONDS );
	return $rows;
}

/* =========================================================================
 * ADMIN PAGE REGISTRATION
 * ====================================================================== */

themify_register_admin_page( array(
	'slug'       => 'themify-leaderboard',
	'title'      => __( 'Leaderboard', 'themify' ),
	'menu_title' => __( 'Leaderboard', 'themify' ),
	'callback'   => 'themify_leaderboard_page',
	'position'   => 13,
) );

add_filter( 'themify_dashboard_cards', 'themify_lb_dashboard_card' );

/**
 * Append the Leaderboard dashboard card.
 *
 * @param array $cards Existing cards.
 * @return array
 */
function themify_lb_dashboard_card( $cards ) {
	$cards[] = array(
		'slug'     => 'themify-leaderboard',
		'title'    => __( 'Leaderboard', 'themify' ),
		'desc'     => __( 'Your best content & keywords, ranked', 'themify' ),
		'icon'     => 'dashicons-awards',
		'position' => 13,
	);
	return $cards;
}

/* =========================================================================
 * PAGE ASSETS
 * ====================================================================== */

/**
 * Print the Leaderboard CSS + tab JS (brand palette).
 */
function themify_lb_print_assets() {
	?>
	<style>
	body[class*="themify-leaderboard"] #wpcontent{background:#f3f8f5}
	.tflb-pageicon{width:46px;height:46px;border-radius:13px;background:#1e8f38;display:flex;align-items:center;justify-content:center;flex:0 0 auto;box-shadow:0 5px 12px rgba(30,143,56,.35)}
	.tflb-pageicon .dashicons{color:#fff;font-size:23px;width:23px;height:23px}
	.tflb-tabs{display:grid;grid-template-columns:1fr 1fr;gap:6px;background:#e9f0ea;border-radius:12px;padding:5px;margin-bottom:24px}
	.tflb-tab{border:none;background:transparent;border-radius:9px;padding:11px 10px;font-size:13.5px;font-weight:700;color:#5a6b62;cursor:pointer;text-align:center}
	.tflb-tab.is-active{background:#fff;color:#1a2b20;box-shadow:0 1px 3px rgba(16,24,40,.08)}
	.tflb-podium{display:grid;grid-template-columns:1fr 1.25fr 1fr;gap:16px;align-items:end;margin-bottom:22px}
	@media(max-width:1000px){.tflb-podium{grid-template-columns:1fr;align-items:stretch}}
	.tflb-po{position:relative;text-align:center;padding:26px 20px 22px;border-width:2px}
	.tflb-po--1{border:2px solid #b8860b;box-shadow:0 14px 34px rgba(184,134,11,.18);padding-top:34px;padding-bottom:30px}
	.tflb-po--2{border:2px solid #c3cfc7}
	.tflb-po--3{border:2px solid #d8c49a}
	.tflb-po__medal{position:absolute;top:-18px;left:50%;transform:translateX(-50%);width:38px;height:38px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:15px;font-weight:800;color:#fff;box-shadow:0 5px 12px rgba(26,43,32,.25)}
	.tflb-po--1 .tflb-po__medal{background:#b8860b;width:46px;height:46px;top:-23px;font-size:18px}
	.tflb-po--2 .tflb-po__medal{background:#8fa096}
	.tflb-po--3 .tflb-po__medal{background:#a8873b}
	.tflb-po__crown{font-size:22px;line-height:1;display:block;margin-bottom:8px}
	.tflb-po__title{font-size:14.5px;font-weight:700;color:#1a2b20;line-height:1.45;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
	.tflb-po--1 .tflb-po__title{font-size:16px}
	.tflb-po__title a{color:inherit;text-decoration:none}
	.tflb-po__title a:hover{color:#1e8f38}
	.tflb-po__path{font-size:11.5px;color:#8fa096;margin-top:4px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
	.tflb-po__num{font-size:30px;font-weight:800;color:#1e8f38;margin-top:14px;line-height:1}
	.tflb-po--1 .tflb-po__num{font-size:38px;color:#b8860b}
	.tflb-po__lbl{font-size:11px;font-weight:700;letter-spacing:.7px;text-transform:uppercase;color:#8fa096;margin-top:5px}
	.tflb-row{display:flex;align-items:center;gap:14px;padding:13px 22px;border-bottom:1px solid #eef4f0}
	.tflb-row:last-child{border-bottom:none}
	.tflb-rank{flex:0 0 34px;font-size:13px;font-weight:800;color:#8fa096}
	.tflb-row__main{flex:1 1 auto;min-width:0}
	.tflb-row__title{font-size:13.5px;font-weight:600;color:#1a2b20;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
	.tflb-row__title a{color:inherit;text-decoration:none}
	.tflb-row__title a:hover{color:#1e8f38}
	.tflb-row__sub{font-size:11.5px;color:#8fa096;margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
	.tflb-row__bar{flex:0 1 220px;min-width:90px}
	.tflb-row__val{flex:0 0 auto;font-size:14px;font-weight:800;color:#1e8f38;min-width:64px;text-align:right}
	.tflb-row__val small{display:block;font-size:10.5px;font-weight:600;color:#8fa096}
	.tflb-empty{padding:48px 22px;text-align:center;color:#5a6b62;font-size:14px}
	.tflb-empty .dashicons{font-size:34px;width:34px;height:34px;color:#c3cfc7;display:block;margin:0 auto 10px}
	</style>
	<script>
	document.addEventListener('click', function (e) {
		var tab = e.target.closest('.tflb-tab');
		if (!tab) { return; }
		e.preventDefault();
		document.querySelectorAll('.tflb-tab').forEach(function (t) { t.classList.remove('is-active'); });
		tab.classList.add('is-active');
		var key = tab.getAttribute('data-tab');
		document.querySelectorAll('.tflb-panel').forEach(function (p) {
			p.style.display = p.getAttribute('data-panel') === key ? '' : 'none';
		});
	});
	</script>
	<?php
}

/* =========================================================================
 * RENDER HELPERS
 * ====================================================================== */

/**
 * Render the top-3 podium: #2 left, #1 centre (bigger, gold), #3 right.
 *
 * @param array  $rows      Ranked rows (best first).
 * @param string $value_key 'views' or 'clicks'.
 * @param string $unit      Label under the big number.
 * @param bool   $is_pages  Whether rows are pages (adds the path + link).
 */
function themify_lb_render_podium( array $rows, $value_key, $unit, $is_pages ) {
	if ( empty( $rows ) ) {
		return;
	}
	// Podium visual order: 2nd, 1st, 3rd.
	$order = array( 1, 0, 2 );

	echo '<div class="tflb-podium">';
	foreach ( $order as $idx ) {
		if ( ! isset( $rows[ $idx ] ) ) {
			echo '<div></div>';
			continue;
		}
		$row   = $rows[ $idx ];
		$place = $idx + 1;

		echo '<div class="tfx-card tflb-po tflb-po--' . (int) $place . '">';
		echo '<span class="tflb-po__medal">#' . (int) $place . '</span>';
		if ( 1 === $place ) {
			echo '<span class="tflb-po__crown">👑</span>';
		}

		if ( $is_pages ) {
			printf(
				'<div class="tflb-po__title"><a href="%s" target="_blank" rel="noopener noreferrer">%s</a></div>',
				esc_url( home_url( (string) $row['path'] ) ),
				esc_html( (string) $row['title'] )
			);
			echo '<div class="tflb-po__path">' . esc_html( (string) $row['path'] ) . '</div>';
		} else {
			echo '<div class="tflb-po__title">' . esc_html( (string) $row['query'] ) . '</div>';
			echo '<div class="tflb-po__path">' . esc_html( sprintf(
				/* translators: %s: impression count */
				__( '%s impressions', 'themify' ),
				number_format_i18n( (int) $row['impressions'] )
			) ) . '</div>';
		}

		echo '<div class="tflb-po__num">' . esc_html( number_format_i18n( (int) $row[ $value_key ] ) ) . '</div>';
		echo '<div class="tflb-po__lbl">' . esc_html( $unit ) . '</div>';
		echo '</div>';
	}
	echo '</div>';
}

/**
 * Render the ranked list below the podium (#4 onwards).
 *
 * @param array  $rows      Ranked rows (best first).
 * @param string $value_key 'views' or 'clicks'.
 * @param bool   $is_pages  Whether rows are pages.
 */
function themify_lb_render_list( array $rows, $value_key, $is_pages ) {
	$rest = array_slice( $rows, 3 );
	if ( empty( $rest ) ) {
		return;
	}
	$max = max( 1, (int) $rows[0][ $value_key ] );

	echo '<div class="tfx-card">';
	foreach ( $rest as $i => $row ) {
		$rank = $i + 4;
		$val  = (int) $row[ $value_key ];
		$pct  = max( 2, (int) round( $val / $max * 100 ) );

		echo '<div class="tflb-row">';
		echo '<span class="tflb-rank">#' . (int) $rank . '</span>';
		echo '<div class="tflb-row__main">';
		if ( $is_pages ) {
			printf(
				'<div class="tflb-row__title"><a href="%s" target="_blank" rel="noopener noreferrer">%s</a></div>',
				esc_url( home_url( (string) $row['path'] ) ),
				esc_html( (string) $row['title'] )
			);
			echo '<div class="tflb-row__sub">' . esc_html( (string) $row['path'] ) . '</div>';
		} else {
			echo '<div class="tflb-row__title">' . esc_html( (string) $row['query'] ) . '</div>';
			echo '<div class="tflb-row__sub">' . esc_html( sprintf(
				/* translators: %s: impression count */
				__( '%s impressions', 'themify' ),
				number_format_i18n( (int) $row['impressions'] )
			) ) . '</div>';
		}
		echo '</div>';
		echo '<div class="tflb-row__bar"><div class="tfx-track"><span style="width:' . (int) $pct . '%;background:#1e8f38;"></span></div></div>';
		echo '<span class="tflb-row__val">' . esc_html( number_format_i18n( $val ) ) . '</span>';
		echo '</div>';
	}
	echo '</div>';
}

/* =========================================================================
 * PAGE RENDER
 * ====================================================================== */

/**
 * Render the Leaderboard screen.
 */
function themify_leaderboard_page() {
	$range_key   = themify_lb_range();
	$ranges      = function_exists( 'themify_analytics_ranges' ) ? themify_analytics_ranges() : array( 'lifetime' => __( 'Lifetime', 'themify' ) );
	$range_label = isset( $ranges[ $range_key ] ) ? $ranges[ $range_key ] : $range_key;

	echo '<div class="wrap tfx">';
	if ( function_exists( 'themify_analytics_print_assets' ) ) {
		themify_analytics_print_assets();
	}
	themify_lb_print_assets();

	// ---- Header + range dropdown ----
	echo '<div class="tfx-head">';
	echo '<div style="display:flex;gap:14px;align-items:flex-start;">';
	echo '<span class="tflb-pageicon"><span class="dashicons dashicons-awards"></span></span>';
	echo '<div>';
	echo '<h1>' . esc_html__( 'Leaderboard', 'themify' ) . '</h1>';
	echo '<p class="tfx-sub">' . esc_html__( 'Your content & keywords ranked by the traffic they bring in — champions on top', 'themify' ) . '</p>';
	echo '</div>';
	echo '</div>';
	echo '<div class="tfx-tools">';
	echo '<span class="tfx-dd">';
	echo '<button type="button" class="tfx-btn tfx-dd__btn"><span class="dashicons dashicons-calendar-alt"></span>' . esc_html( $range_label ) . ' <span class="dashicons dashicons-arrow-down-alt2" style="font-size:13px;width:13px;height:13px;"></span></button>';
	echo '<span class="tfx-dd__menu">';
	foreach ( $ranges as $key => $label ) {
		printf(
			'<a href="%s" class="tfx-dd__item%s">%s</a>',
			esc_url( add_query_arg( array(
				'page'       => 'themify-leaderboard',
				'tflb_range' => $key,
			), admin_url( 'admin.php' ) ) ),
			$key === $range_key ? ' is-active' : '',
			esc_html( $label )
		);
	}
	echo '</span>';
	echo '</span>';
	echo '</div>';
	echo '</div>';

	$pages   = themify_lb_pages( $range_key );
	$queries = themify_lb_queries( $range_key );

	// ---- Tabs ----
	echo '<div class="tflb-tabs">';
	echo '<button type="button" class="tflb-tab is-active" data-tab="content">' . esc_html( sprintf(
		/* translators: %s: count */
		__( 'Top Content (%s)', 'themify' ),
		is_wp_error( $pages ) ? '0' : number_format_i18n( count( $pages ) )
	) ) . '</button>';
	echo '<button type="button" class="tflb-tab" data-tab="keywords">' . esc_html( sprintf(
		/* translators: %s: count */
		__( 'Top Keywords (%s)', 'themify' ),
		is_wp_error( $queries ) ? '0' : number_format_i18n( count( $queries ) )
	) ) . '</button>';
	echo '</div>';

	// ---- Content panel ----
	echo '<div class="tflb-panel" data-panel="content">';
	if ( is_wp_error( $pages ) ) {
		echo '<div class="tf-notice tf-notice--warn">' . esc_html( $pages->get_error_message() ) . '</div>';
	} elseif ( empty( $pages ) ) {
		echo '<div class="tfx-card"><div class="tflb-empty"><span class="dashicons dashicons-awards"></span>' . esc_html__( 'No traffic data for this range yet.', 'themify' ) . '</div></div>';
	} else {
		themify_lb_render_podium( $pages, 'views', __( 'Pageviews', 'themify' ), true );
		themify_lb_render_list( $pages, 'views', true );
	}
	echo '</div>';

	// ---- Keywords panel ----
	echo '<div class="tflb-panel" data-panel="keywords" style="display:none;">';
	if ( is_wp_error( $queries ) ) {
		echo '<div class="tf-notice tf-notice--warn">' . esc_html( $queries->get_error_message() ) . '</div>';
	} elseif ( empty( $queries ) ) {
		echo '<div class="tfx-card"><div class="tflb-empty"><span class="dashicons dashicons-search"></span>' . esc_html__( 'No keyword data for this range yet. Search Console hides rare queries and lags a few days.', 'themify' ) . '</div></div>';
	} else {
		themify_lb_render_podium( $queries, 'clicks', __( 'Clicks', 'themify' ), false );
		themify_lb_render_list( $queries, 'clicks', false );
	}
	echo '</div>';

	echo '<button type="button" class="tfx-top" aria-label="' . esc_attr__( 'Scroll to top', 'themify' ) . '"><span class="dashicons dashicons-arrow-up-alt2"></span></button>';
	echo '</div>'; // .tfx
}
