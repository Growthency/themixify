<?php
/**
 * Site Overview — everything about the site on one screen.
 *
 * Combines four data sources into a single, glanceable report:
 *
 *   1. DOMAIN — registration date, registrar, expiry and the domain's age,
 *      fetched once from the public RDAP directory (rdap.org bootstrap, which
 *      redirects to the registry's own RDAP server) and cached for 30 days in
 *      an option. RDAP is the modern, JSON replacement for WHOIS and needs no
 *      API key.
 *   2. CONTENT — when publishing started (first post), the latest post, totals
 *      for posts/pages/comments/terms and the average publishing rate.
 *   3. TRAFFIC — average / highest / lowest daily visitors over the last 365
 *      days, computed from the GA4 daily series the Analytics module already
 *      fetches (and caches) with the site's service-account credentials.
 *   4. SYSTEM — WordPress/PHP/theme versions, HTTPS, language, timezone.
 *
 * EXTERNAL HTTP RULE: the single RDAP call happens only in the admin, at most
 * once every 30 days (or on explicit refresh). Traffic reuses the Analytics
 * module's own cached, admin-only GA4 report. Nothing here runs on a public
 * page load.
 *
 * @package Themify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Option caching the RDAP domain lookup. */
if ( ! defined( 'THEMIFY_DOMAIN_INFO_OPT' ) ) {
	define( 'THEMIFY_DOMAIN_INFO_OPT', 'themify_domain_info' );
}

/** How long the RDAP lookup is trusted before an automatic re-fetch (30 days). */
if ( ! defined( 'THEMIFY_DOMAIN_INFO_TTL' ) ) {
	define( 'THEMIFY_DOMAIN_INFO_TTL', 30 * DAY_IN_SECONDS );
}

/* =========================================================================
 * DOMAIN (RDAP)
 * ====================================================================== */

/**
 * The domain's registration facts, from RDAP, cached in an option.
 *
 * @param bool $force Re-fetch even when the cache is fresh.
 * @return array { registered:int, expires:int, changed:int, registrar:string,
 *                 error:string, fetched:int }
 */
function themify_overview_domain_info( $force = false ) {
	$defaults = array(
		'registered' => 0,
		'expires'    => 0,
		'changed'    => 0,
		'registrar'  => '',
		'error'      => '',
		'fetched'    => 0,
	);

	$stored = get_option( THEMIFY_DOMAIN_INFO_OPT, array() );
	$stored = is_array( $stored ) ? wp_parse_args( $stored, $defaults ) : $defaults;

	if ( ! $force && $stored['fetched'] && ( time() - (int) $stored['fetched'] ) < THEMIFY_DOMAIN_INFO_TTL ) {
		return $stored;
	}

	// RDAP is only ever queried from the admin.
	if ( ! is_admin() ) {
		return $stored;
	}

	$domain = themify_site_host();
	if ( '' === $domain ) {
		return $stored;
	}

	$fresh            = $defaults;
	$fresh['fetched'] = time();

	$response = themify_remote_json(
		'https://rdap.org/domain/' . rawurlencode( $domain ),
		array( 'timeout' => 15 )
	);

	if ( is_wp_error( $response ) ) {
		// Keep any previously-good facts; only refresh the error + timestamp.
		$stored['error']   = $response->get_error_message();
		$stored['fetched'] = time();
		update_option( THEMIFY_DOMAIN_INFO_OPT, $stored, false );
		return $stored;
	}

	// Events carry the dates (registration / expiration / last changed).
	if ( isset( $response['events'] ) && is_array( $response['events'] ) ) {
		foreach ( $response['events'] as $event ) {
			$action = isset( $event['eventAction'] ) ? strtolower( (string) $event['eventAction'] ) : '';
			$ts     = isset( $event['eventDate'] ) ? strtotime( (string) $event['eventDate'] ) : 0;
			if ( ! $ts ) {
				continue;
			}
			if ( 'registration' === $action ) {
				$fresh['registered'] = $ts;
			} elseif ( 'expiration' === $action ) {
				$fresh['expires'] = $ts;
			} elseif ( 'last changed' === $action || 'last update of rdap database' === $action ) {
				$fresh['changed'] = max( $fresh['changed'], $ts );
			}
		}
	}

	// The registrar entity's vCard "fn" is the human name (where it was bought).
	if ( isset( $response['entities'] ) && is_array( $response['entities'] ) ) {
		foreach ( $response['entities'] as $entity ) {
			$roles = isset( $entity['roles'] ) && is_array( $entity['roles'] ) ? $entity['roles'] : array();
			if ( ! in_array( 'registrar', $roles, true ) ) {
				continue;
			}
			if ( isset( $entity['vcardArray'][1] ) && is_array( $entity['vcardArray'][1] ) ) {
				foreach ( $entity['vcardArray'][1] as $prop ) {
					if ( is_array( $prop ) && isset( $prop[0], $prop[3] ) && 'fn' === $prop[0] && is_string( $prop[3] ) ) {
						$fresh['registrar'] = trim( $prop[3] );
						break;
					}
				}
			}
			if ( '' !== $fresh['registrar'] ) {
				break;
			}
		}
	}

	update_option( THEMIFY_DOMAIN_INFO_OPT, $fresh, false );
	return $fresh;
}

/* =========================================================================
 * CONTENT FACTS
 * ====================================================================== */

/**
 * The very first published post/page (when content publishing started).
 *
 * @return array|null { id:int, ts:int, title:string } or null when none.
 */
function themify_overview_first_post() {
	$query = new WP_Query( array(
		'post_type'              => array( 'post', 'page' ),
		'post_status'            => 'publish',
		'posts_per_page'         => 1,
		'orderby'                => 'date',
		'order'                  => 'ASC',
		'no_found_rows'          => true,
		'update_post_meta_cache' => false,
		'update_post_term_cache' => false,
		'ignore_sticky_posts'    => true,
	) );

	if ( empty( $query->posts ) ) {
		return null;
	}
	$post = $query->posts[0];
	return array(
		'id'    => (int) $post->ID,
		'ts'    => (int) get_post_time( 'U', true, $post ),
		'title' => get_the_title( $post ),
	);
}

/**
 * The most recently published post.
 *
 * @return array|null { id:int, ts:int, title:string } or null when none.
 */
function themify_overview_latest_post() {
	$query = new WP_Query( array(
		'post_type'              => 'post',
		'post_status'            => 'publish',
		'posts_per_page'         => 1,
		'orderby'                => 'date',
		'order'                  => 'DESC',
		'no_found_rows'          => true,
		'update_post_meta_cache' => false,
		'update_post_term_cache' => false,
		'ignore_sticky_posts'    => true,
	) );

	if ( empty( $query->posts ) ) {
		return null;
	}
	$post = $query->posts[0];
	return array(
		'id'    => (int) $post->ID,
		'ts'    => (int) get_post_time( 'U', true, $post ),
		'title' => get_the_title( $post ),
	);
}

/**
 * A short "1y 4m" style age for a past timestamp (big-number display).
 *
 * @param int $from Unix timestamp in the past.
 * @return string
 */
function themify_overview_age_short( $from ) {
	$from = (int) $from;
	if ( $from <= 0 || $from > time() ) {
		return '—';
	}
	$diff = ( new DateTime( '@' . $from ) )->diff( new DateTime( '@' . time() ) );
	if ( $diff->y > 0 ) {
		return sprintf( /* translators: 1: years, 2: months */ __( '%1$dy %2$dm', 'themify' ), $diff->y, $diff->m );
	}
	if ( $diff->m > 0 ) {
		return sprintf( /* translators: 1: months, 2: days */ __( '%1$dm %2$dd', 'themify' ), $diff->m, $diff->d );
	}
	return sprintf( /* translators: %d: days */ __( '%dd', 'themify' ), $diff->d );
}

/**
 * A full "1 year, 4 months, 12 days" age string.
 *
 * @param int $from Unix timestamp in the past.
 * @return string
 */
function themify_overview_age_long( $from ) {
	$from = (int) $from;
	if ( $from <= 0 || $from > time() ) {
		return '—';
	}
	$diff  = ( new DateTime( '@' . $from ) )->diff( new DateTime( '@' . time() ) );
	$parts = array();
	if ( $diff->y > 0 ) {
		/* translators: %d: years */
		$parts[] = sprintf( _n( '%d year', '%d years', $diff->y, 'themify' ), $diff->y );
	}
	if ( $diff->m > 0 ) {
		/* translators: %d: months */
		$parts[] = sprintf( _n( '%d month', '%d months', $diff->m, 'themify' ), $diff->m );
	}
	if ( $diff->d > 0 || empty( $parts ) ) {
		/* translators: %d: days */
		$parts[] = sprintf( _n( '%d day', '%d days', $diff->d, 'themify' ), $diff->d );
	}
	return implode( ', ', $parts );
}

/**
 * Format a timestamp with the site's date format.
 *
 * @param int $ts Unix timestamp (0 → '—').
 * @return string
 */
function themify_overview_date( $ts ) {
	$ts = (int) $ts;
	return $ts ? date_i18n( get_option( 'date_format' ), $ts + ( (int) get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) ) : '—';
}

/* =========================================================================
 * TRAFFIC FACTS (from the Analytics module's cached GA4 report)
 * ====================================================================== */

/**
 * Average / highest / lowest daily visitors over the last 365 days.
 *
 * @return array|null { avg:int, total:int, max:int, max_date:int, min:int,
 *                      min_date:int, days:int, pageviews:int, sessions:int }
 *                    or null when GA4 is not configured / errored.
 */
function themify_overview_traffic() {
	if ( ! function_exists( 'themify_ga4_report' ) || ! function_exists( 'themify_analytics_has_creds' ) || ! themify_analytics_has_creds() ) {
		return null;
	}

	$report = themify_ga4_report( '365d' );
	if ( is_wp_error( $report ) ) {
		return null;
	}

	$daily = isset( $report['daily'] ) && is_array( $report['daily'] ) ? $report['daily'] : array();
	if ( empty( $daily ) ) {
		return null;
	}

	$total    = 0;
	$max      = null;
	$min      = null;
	$max_date = 0;
	$min_date = 0;

	foreach ( $daily as $day ) {
		$users = (int) $day['users'];
		$ts    = strtotime( (string) $day['date'] );
		$total += $users;
		if ( null === $max || $users > $max ) {
			$max      = $users;
			$max_date = $ts ? $ts : 0;
		}
		if ( null === $min || $users < $min ) {
			$min      = $users;
			$min_date = $ts ? $ts : 0;
		}
	}

	$totals = isset( $report['totals'] ) && is_array( $report['totals'] ) ? $report['totals'] : array();

	return array(
		'avg'       => (int) round( $total / max( 1, count( $daily ) ) ),
		'total'     => $total,
		'max'       => (int) $max,
		'max_date'  => $max_date,
		'min'       => (int) $min,
		'min_date'  => $min_date,
		'days'      => count( $daily ),
		'pageviews' => isset( $totals['screenPageViews'] ) ? (int) $totals['screenPageViews'] : 0,
		'sessions'  => isset( $totals['sessions'] ) ? (int) $totals['sessions'] : 0,
	);
}

/* =========================================================================
 * ADMIN PAGE REGISTRATION
 * ====================================================================== */

themify_register_admin_page( array(
	'slug'       => 'themify-site-overview',
	'title'      => __( 'Site Overview', 'themify' ),
	'menu_title' => __( 'Site Overview', 'themify' ),
	'callback'   => 'themify_site_overview_page',
	'position'   => 12,
) );

add_filter( 'themify_dashboard_cards', 'themify_overview_dashboard_card' );

/**
 * Append the Site Overview dashboard card.
 *
 * @param array $cards Existing cards.
 * @return array
 */
function themify_overview_dashboard_card( $cards ) {
	$cards[] = array(
		'slug'     => 'themify-site-overview',
		'title'    => __( 'Site Overview', 'themify' ),
		'desc'     => __( 'Domain, content & traffic facts', 'themify' ),
		'icon'     => 'dashicons-info',
		'position' => 12,
	);
	return $cards;
}

/* =========================================================================
 * PAGE ASSETS
 * ====================================================================== */

/**
 * Print the Site Overview CSS (brand palette). Complements the shared design
 * system from themify_analytics_print_assets().
 */
function themify_overview_print_assets() {
	?>
	<style>
	body[class*="themify-site-overview"] #wpcontent{background:#f3f8f5}
	.tfo-pageicon{width:46px;height:46px;border-radius:13px;background:#1e8f38;display:flex;align-items:center;justify-content:center;flex:0 0 auto;box-shadow:0 5px 12px rgba(30,143,56,.35)}
	.tfo-pageicon .dashicons{color:#fff;font-size:23px;width:23px;height:23px}
	.tfo-hero{display:grid;grid-template-columns:1.2fr 1fr 1fr 1.1fr;grid-auto-rows:minmax(120px,auto);gap:16px;margin-bottom:22px}
	@media(max-width:1200px){.tfo-hero{grid-template-columns:1fr 1fr}}
	.tfo-age{grid-row:1 / 3;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;padding:26px 22px}
	@media(max-width:1200px){.tfo-age{grid-row:auto;grid-column:1 / 3}}
	.tfo-age__num{font-size:42px;font-weight:800;color:#1e8f38;line-height:1}
	.tfo-age__lbl{font-size:11px;letter-spacing:.9px;color:#8fa096;font-weight:700;margin-top:8px;text-transform:uppercase}
	.tfo-age__sub{font-size:13px;color:#33463a;font-weight:600;margin-top:18px}
	.tfo-age__date{font-size:11.5px;color:#8fa096;margin-top:2px}
	.tfo-pill{display:inline-flex;align-items:center;gap:5px;background:#e3f5e8;color:#156b28;border:1px solid #cde9d6;border-radius:999px;padding:5px 13px;font-size:11.5px;font-weight:700;margin-top:15px;max-width:100%}
	.tfo-pill .dashicons{font-size:14px;width:14px;height:14px}
	.tfo-stat{padding:18px 20px}
	.tfo-stat__top{display:flex;justify-content:space-between;align-items:center;font-size:11px;font-weight:700;letter-spacing:.7px;text-transform:uppercase;color:#8fa096}
	.tfo-stat__top .dashicons{color:#8fa096;font-size:17px;width:17px;height:17px}
	.tfo-stat__num{font-size:25px;font-weight:800;color:#1a2b20;margin-top:11px;line-height:1.15;word-break:break-word}
	.tfo-stat__sub{font-size:12px;color:#8fa096;margin-top:6px}
	.tfo-row{display:flex;justify-content:space-between;align-items:baseline;gap:16px;padding:12px 22px;border-bottom:1px solid #eef4f0}
	.tfo-row:last-child{border-bottom:none}
	.tfo-row__label{font-size:13px;color:#5a6b62;font-weight:600;flex:0 0 auto}
	.tfo-row__val{font-size:13.5px;color:#1a2b20;font-weight:700;text-align:right;word-break:break-word}
	.tfo-row__val small{display:block;font-weight:600;color:#8fa096;font-size:11.5px;margin-top:2px}
	.tfo-row__val a{color:#1a2b20;text-decoration:none}
	.tfo-row__val a:hover{color:#1e8f38}
	.tfo-val--green{color:#1e8f38}
	.tfo-val--gold{color:#8a6d0b}
	.tfo-val--red{color:#b0281a}
	.tfo-note{padding:14px 22px;font-size:12.5px;color:#8fa096}
	</style>
	<?php
}

/* =========================================================================
 * PAGE RENDER
 * ====================================================================== */

/**
 * One label/value row inside a detail card.
 *
 * @param string $label Row label.
 * @param string $value Escaped-safe HTML for the value side.
 */
function themify_overview_row( $label, $value ) {
	echo '<div class="tfo-row">';
	echo '<span class="tfo-row__label">' . esc_html( $label ) . '</span>';
	echo '<span class="tfo-row__val">' . wp_kses_post( $value ) . '</span>';
	echo '</div>';
}

/**
 * Render the Site Overview screen.
 */
function themify_site_overview_page() {
	// Optional manual refresh of the RDAP cache (?tfo_refresh=1 + nonce).
	$force = false;
	if ( isset( $_GET['tfo_refresh'], $_GET['_wpnonce'] )
		&& current_user_can( THEMIFY_CAP )
		&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'tfo_refresh' ) ) {
		$force = true;
	}

	$domain_info = themify_overview_domain_info( $force );
	$first       = themify_overview_first_post();
	$latest      = themify_overview_latest_post();
	$traffic     = themify_overview_traffic();

	// Lifetime totals (GA4 has no true "all time", so this is the property's
	// full history). Reuses the Analytics module's cached per-range report.
	$lifetime = null;
	if ( function_exists( 'themify_ga4_report' ) && function_exists( 'themify_analytics_has_creds' ) && themify_analytics_has_creds() ) {
		$lt = themify_ga4_report( 'lifetime' );
		if ( ! is_wp_error( $lt ) && isset( $lt['totals']['activeUsers'] ) ) {
			$lifetime = array(
				'users' => (int) $lt['totals']['activeUsers'],
				'views' => isset( $lt['totals']['screenPageViews'] ) ? (int) $lt['totals']['screenPageViews'] : 0,
			);
		}
	}

	$domain = themify_site_host();

	// Content totals.
	$posts_count    = wp_count_posts( 'post' );
	$pages_count    = wp_count_posts( 'page' );
	$posts_n        = isset( $posts_count->publish ) ? (int) $posts_count->publish : 0;
	$pages_n        = isset( $pages_count->publish ) ? (int) $pages_count->publish : 0;
	$comments       = wp_count_comments();
	$comments_n     = isset( $comments->approved ) ? (int) $comments->approved : 0;
	$cats_n         = (int) wp_count_terms( array( 'taxonomy' => 'category', 'hide_empty' => false ) );
	$tags_n         = (int) wp_count_terms( array( 'taxonomy' => 'post_tag', 'hide_empty' => false ) );

	// Publishing rate: posts per month since the first post.
	$per_month = 0;
	if ( $first && $first['ts'] > 0 && $posts_n > 0 ) {
		$months    = max( 1, ( time() - $first['ts'] ) / ( 30.44 * DAY_IN_SECONDS ) );
		$per_month = round( $posts_n / $months, 1 );
	}

	// The site's "age" anchor: domain registration, falling back to first post.
	$age_anchor = $domain_info['registered'] ? (int) $domain_info['registered'] : ( $first ? (int) $first['ts'] : 0 );

	echo '<div class="wrap tfx">';
	if ( function_exists( 'themify_analytics_print_assets' ) ) {
		themify_analytics_print_assets();
	}
	themify_overview_print_assets();

	// ---- Header ----
	$refresh_url = wp_nonce_url(
		add_query_arg(
			array(
				'page'        => 'themify-site-overview',
				'tfo_refresh' => 1,
			),
			admin_url( 'admin.php' )
		),
		'tfo_refresh'
	);
	echo '<div class="tfx-head">';
	echo '<div style="display:flex;gap:14px;align-items:flex-start;">';
	echo '<span class="tfo-pageicon"><span class="dashicons dashicons-info"></span></span>';
	echo '<div>';
	echo '<h1>' . esc_html__( 'Site Overview', 'themify' ) . '</h1>';
	echo '<p class="tfx-sub">' . esc_html__( 'Domain history, content timeline & traffic — everything about your site at a glance', 'themify' ) . '</p>';
	echo '</div>';
	echo '</div>';
	echo '<div class="tfx-tools">';
	printf(
		'<a class="tfx-btn" href="%s"><span class="dashicons dashicons-update"></span>%s</a>',
		esc_url( $refresh_url ),
		esc_html__( 'Refresh domain info', 'themify' )
	);
	echo '</div>';
	echo '</div>';

	if ( '' !== $domain_info['error'] && ! $domain_info['registered'] ) {
		echo '<div class="tf-notice tf-notice--warn"><strong>' . esc_html__( 'Domain lookup failed:', 'themify' ) . '</strong> ' . esc_html( $domain_info['error'] ) . '</div>';
	}

	// ---- Hero grid ----
	echo '<div class="tfo-hero">';

	// Big site-age card.
	echo '<div class="tfx-card tfo-age">';
	echo '<div class="tfo-age__num">' . esc_html( themify_overview_age_short( $age_anchor ) ) . '</div>';
	echo '<div class="tfo-age__lbl">' . esc_html__( 'Site Age', 'themify' ) . '</div>';
	if ( $domain_info['registered'] ) {
		echo '<div class="tfo-age__sub">' . esc_html( sprintf( /* translators: %s: date */ __( 'Domain registered %s', 'themify' ), themify_overview_date( $domain_info['registered'] ) ) ) . '</div>';
	} elseif ( $first ) {
		echo '<div class="tfo-age__sub">' . esc_html( sprintf( /* translators: %s: date */ __( 'Publishing since %s', 'themify' ), themify_overview_date( $first['ts'] ) ) ) . '</div>';
	}
	echo '<div class="tfo-age__date">' . esc_html( $domain ) . '</div>';
	if ( '' !== $domain_info['registrar'] ) {
		echo '<span class="tfo-pill"><span class="dashicons dashicons-cart"></span>' . esc_html( $domain_info['registrar'] ) . '</span>';
	}
	echo '</div>';

	// Stat cards.
	$stats = array(
		array(
			__( 'Content Since', 'themify' ),
			$first ? themify_overview_date( $first['ts'] ) : '—',
			$first ? sprintf( /* translators: %s: human diff */ __( 'first post %s', 'themify' ), themify_time_ago( $first['ts'] ) ) : __( 'no posts yet', 'themify' ),
			'edit-page',
		),
		array(
			__( 'Total Content', 'themify' ),
			number_format_i18n( $posts_n + $pages_n ),
			sprintf( /* translators: 1: posts, 2: pages */ __( '%1$s posts · %2$s pages', 'themify' ), number_format_i18n( $posts_n ), number_format_i18n( $pages_n ) ),
			'admin-post',
		),
		array(
			__( 'Avg Daily Traffic', 'themify' ),
			$traffic ? number_format_i18n( $traffic['avg'] ) : '—',
			$traffic ? sprintf( /* translators: %d: days */ __( 'users/day · last %d days', 'themify' ), $traffic['days'] ) : __( 'connect GA4 in Analytics', 'themify' ),
			'groups',
		),
		array(
			__( 'Peak Traffic Day', 'themify' ),
			$traffic ? number_format_i18n( $traffic['max'] ) : '—',
			$traffic && $traffic['max_date'] ? themify_overview_date( $traffic['max_date'] ) : '—',
			'chart-line',
		),
		array(
			__( 'Lowest Traffic Day', 'themify' ),
			$traffic ? number_format_i18n( $traffic['min'] ) : '—',
			$traffic && $traffic['min_date'] ? themify_overview_date( $traffic['min_date'] ) : '—',
			'arrow-down-alt',
		),
		array(
			__( 'Total Traffic', 'themify' ),
			$lifetime ? number_format_i18n( $lifetime['users'] ) : '—',
			$lifetime
				? sprintf( /* translators: %s: pageview count */ __( 'lifetime visitors · %s pageviews', 'themify' ), number_format_i18n( $lifetime['views'] ) )
				: __( 'connect GA4 in Analytics', 'themify' ),
			'chart-area',
		),
	);
	foreach ( $stats as $s ) {
		echo '<div class="tfx-card tfo-stat">';
		echo '<div class="tfo-stat__top"><span>' . esc_html( $s[0] ) . '</span><span class="dashicons dashicons-' . esc_attr( $s[3] ) . '"></span></div>';
		echo '<div class="tfo-stat__num">' . esc_html( $s[1] ) . '</div>';
		echo '<div class="tfo-stat__sub">' . esc_html( $s[2] ) . '</div>';
		echo '</div>';
	}
	echo '</div>'; // .tfo-hero

	// ---- Detail cards ----
	echo '<div class="tfx-grid2">';

	// Domain information.
	echo '<div class="tfx-card">';
	echo '<div class="tfx-card__head"><span class="tfx-card__title"><span class="dashicons dashicons-admin-site-alt3"></span>' . esc_html__( 'Domain Information', 'themify' ) . '</span></div>';
	themify_overview_row( __( 'Domain', 'themify' ), '<a href="' . esc_url( home_url( '/' ) ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $domain ) . '</a>' );
	themify_overview_row(
		__( 'Registered on', 'themify' ),
		esc_html( themify_overview_date( $domain_info['registered'] ) )
			. ( $domain_info['registered'] ? '<small>' . esc_html( themify_time_ago( $domain_info['registered'] ) ) . '</small>' : '' )
	);
	themify_overview_row( __( 'Registered at (registrar)', 'themify' ), '' !== $domain_info['registrar'] ? esc_html( $domain_info['registrar'] ) : '—' );
	$exp_val = '—';
	if ( $domain_info['expires'] ) {
		$days_left = (int) ceil( ( $domain_info['expires'] - time() ) / DAY_IN_SECONDS );
		$exp_class = $days_left <= 30 ? 'tfo-val--red' : ( $days_left <= 90 ? 'tfo-val--gold' : 'tfo-val--green' );
		$exp_val   = '<span class="' . esc_attr( $exp_class ) . '">' . esc_html( themify_overview_date( $domain_info['expires'] ) ) . '</span>'
			. '<small>' . esc_html( sprintf( /* translators: %s: days */ _n( 'in %s day', 'in %s days', max( 0, $days_left ), 'themify' ), number_format_i18n( max( 0, $days_left ) ) ) ) . '</small>';
	}
	themify_overview_row( __( 'Expires on', 'themify' ), $exp_val );
	themify_overview_row( __( 'Domain age', 'themify' ), esc_html( themify_overview_age_long( $domain_info['registered'] ) ) );
	if ( $domain_info['fetched'] ) {
		echo '<div class="tfo-note">' . esc_html( sprintf( /* translators: %s: human diff */ __( 'Domain facts from RDAP (public registry data), checked %s. Cached for 30 days.', 'themify' ), themify_time_ago( $domain_info['fetched'] ) ) ) . '</div>';
	}
	echo '</div>';

	// Content history.
	echo '<div class="tfx-card">';
	echo '<div class="tfx-card__head"><span class="tfx-card__title"><span class="dashicons dashicons-edit-page"></span>' . esc_html__( 'Content History', 'themify' ) . '</span></div>';
	themify_overview_row(
		__( 'First content published', 'themify' ),
		$first
			? esc_html( themify_overview_date( $first['ts'] ) ) . '<small><a href="' . esc_url( (string) get_permalink( $first['id'] ) ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( wp_trim_words( $first['title'], 8, '…' ) ) . '</a></small>'
			: '—'
	);
	themify_overview_row(
		__( 'Latest post', 'themify' ),
		$latest
			? esc_html( themify_overview_date( $latest['ts'] ) ) . '<small><a href="' . esc_url( (string) get_permalink( $latest['id'] ) ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( wp_trim_words( $latest['title'], 8, '…' ) ) . '</a></small>'
			: '—'
	);
	themify_overview_row( __( 'Publishing for', 'themify' ), $first ? esc_html( themify_overview_age_long( $first['ts'] ) ) : '—' );
	themify_overview_row( __( 'Published posts', 'themify' ), esc_html( number_format_i18n( $posts_n ) ) );
	themify_overview_row( __( 'Published pages', 'themify' ), esc_html( number_format_i18n( $pages_n ) ) );
	themify_overview_row( __( 'Approved comments', 'themify' ), esc_html( number_format_i18n( $comments_n ) ) );
	themify_overview_row( __( 'Categories / tags', 'themify' ), esc_html( number_format_i18n( $cats_n ) . ' / ' . number_format_i18n( $tags_n ) ) );
	themify_overview_row( __( 'Avg publishing rate', 'themify' ), $per_month ? esc_html( sprintf( /* translators: %s: number */ __( '%s posts/month', 'themify' ), number_format_i18n( $per_month, 1 ) ) ) : '—' );
	echo '</div>';

	echo '</div>'; // grid2

	echo '<div class="tfx-grid2">';

	// Traffic.
	echo '<div class="tfx-card">';
	echo '<div class="tfx-card__head"><span class="tfx-card__title"><span class="dashicons dashicons-chart-bar"></span>' . esc_html__( 'Traffic — Last 365 Days', 'themify' ) . '</span><span class="tfx-card__meta">' . esc_html__( 'from Google Analytics', 'themify' ) . '</span></div>';
	if ( $traffic ) {
		themify_overview_row( __( 'Total visitors', 'themify' ), '<span class="tfo-val--green">' . esc_html( number_format_i18n( $traffic['total'] ) ) . '</span>' );
		themify_overview_row( __( 'Average per day', 'themify' ), esc_html( number_format_i18n( $traffic['avg'] ) ) );
		themify_overview_row(
			__( 'Highest in one day', 'themify' ),
			'<span class="tfo-val--green">' . esc_html( number_format_i18n( $traffic['max'] ) ) . '</span>'
				. ( $traffic['max_date'] ? '<small>' . esc_html( themify_overview_date( $traffic['max_date'] ) ) . '</small>' : '' )
		);
		themify_overview_row(
			__( 'Lowest in one day', 'themify' ),
			'<span class="tfo-val--gold">' . esc_html( number_format_i18n( $traffic['min'] ) ) . '</span>'
				. ( $traffic['min_date'] ? '<small>' . esc_html( themify_overview_date( $traffic['min_date'] ) ) . '</small>' : '' )
		);
		themify_overview_row( __( 'Pageviews', 'themify' ), esc_html( number_format_i18n( $traffic['pageviews'] ) ) );
		themify_overview_row( __( 'Sessions', 'themify' ), esc_html( number_format_i18n( $traffic['sessions'] ) ) );
	} else {
		echo '<div class="tfo-note">' . esc_html__( 'Traffic facts need the Google Analytics connection. Add your GA4 service-account credentials under Themixify → Analytics and reload this page.', 'themify' ) . '</div>';
	}
	echo '</div>';

	// System.
	global $wp_version;
	echo '<div class="tfx-card">';
	echo '<div class="tfx-card__head"><span class="tfx-card__title"><span class="dashicons dashicons-admin-tools"></span>' . esc_html__( 'System', 'themify' ) . '</span></div>';
	themify_overview_row( __( 'WordPress version', 'themify' ), esc_html( (string) $wp_version ) );
	themify_overview_row( __( 'PHP version', 'themify' ), esc_html( PHP_VERSION ) );
	themify_overview_row( __( 'Theme version', 'themify' ), esc_html( 'Themixify ' . ( defined( 'THEMIFY_VERSION' ) ? THEMIFY_VERSION : '' ) ) );
	themify_overview_row(
		__( 'HTTPS', 'themify' ),
		0 === strpos( home_url( '/' ), 'https://' )
			? '<span class="tfo-val--green">' . esc_html__( 'Active', 'themify' ) . '</span>'
			: '<span class="tfo-val--red">' . esc_html__( 'Not active', 'themify' ) . '</span>'
	);
	themify_overview_row( __( 'Language', 'themify' ), esc_html( get_bloginfo( 'language' ) ) );
	themify_overview_row( __( 'Timezone', 'themify' ), esc_html( wp_timezone_string() ) );
	echo '</div>';

	echo '</div>'; // grid2

	echo '<button type="button" class="tfx-top" aria-label="' . esc_attr__( 'Scroll to top', 'themify' ) . '"><span class="dashicons dashicons-arrow-up-alt2"></span></button>';
	echo '</div>'; // .tfx
}
