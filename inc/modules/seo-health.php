<?php
/**
 * SEO Health — an on-demand, self-hosted SEO audit crawler.
 *
 * This module gives Themify a lightweight "Site Auditor". On demand (never on a
 * public page load) it fetches a small sample of the site's own pages — the home
 * page plus the most recent published posts/pages — and inspects each response's
 * raw HTML for the on-page signals that matter most for SEO and accessibility:
 *
 *   • <title> presence + length (sweet spot 30–60 chars)
 *   • meta description presence + length (sweet spot 120–160 chars)
 *   • exactly one <h1>
 *   • a canonical <link>
 *   • images missing alt text
 *   • a responsive viewport <meta>
 *   • a language on <html lang>
 *   • an accidental noindex directive
 *   • Open Graph tags (og:title / og:image)
 *   • JSON-LD structured data
 *   • a rough word count (thin-content warning)
 *   • load time + HTML weight
 *
 * Each page gets a list of issues (severity critical / warning / info) with a
 * concrete fix hint and a 0–100 score; the site gets rolled-up averages. The
 * result is cached in an option and re-rendered on page load so the console is
 * never blank.
 *
 * PERFORMANCE / EXTERNAL-HTTP RULE: the crawl only runs from the admin AJAX
 * handler (which requires nonce + capability). It is hard-capped to ~16 requests
 * with short timeouts so it finishes well within a normal AJAX timeout, and it
 * only ever fetches this site's own URLs. Nothing here touches a public request.
 *
 * The regex-based HTML inspectors are intentionally local to this module so it
 * has no dependency on any other module.
 *
 * @package Themify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Option that stores the last audit result ( [ 'result' => [...], 'time' => int ] ).
 */
if ( ! defined( 'THEMIFY_SEO_HEALTH_OPT' ) ) {
	define( 'THEMIFY_SEO_HEALTH_OPT', 'themify_seo_health' );
}

/**
 * Hard cap on how many URLs a single audit run will fetch. Keeps the whole run
 * inside a normal AJAX/PHP timeout (home + ~15 posts/pages = 16 requests).
 */
if ( ! defined( 'THEMIFY_SEO_HEALTH_MAX_URLS' ) ) {
	define( 'THEMIFY_SEO_HEALTH_MAX_URLS', 16 );
}

/**
 * Per-request HTTP timeout (seconds). Short so one slow page can't stall the run.
 */
if ( ! defined( 'THEMIFY_SEO_HEALTH_TIMEOUT' ) ) {
	define( 'THEMIFY_SEO_HEALTH_TIMEOUT', 8 );
}

/* ============================================================ ADMIN PAGE WIRING */

/**
 * Register the "SEO Health" submenu (position 50).
 */
themify_register_admin_page( array(
	'slug'       => 'themify-seo-health',
	'title'      => __( 'SEO Health', 'themify' ),
	'menu_title' => __( 'SEO Health', 'themify' ),
	'callback'   => 'themify_seo_health_page',
	'position'   => 18,
) );

/**
 * Add the SEO Health card to the dashboard grid.
 */
add_filter( 'themify_dashboard_cards', 'themify_seo_health_dashboard_card' );

/**
 * Append the SEO Health dashboard card.
 *
 * @param array $cards Existing cards.
 * @return array
 */
function themify_seo_health_dashboard_card( $cards ) {
	$cards[] = array(
		'slug'     => 'themify-seo-health',
		'title'    => __( 'SEO Health', 'themify' ),
		'desc'     => __( 'On-demand on-page SEO audit', 'themify' ),
		'icon'     => 'dashicons-search',
		'position' => 18,
	);
	return $cards;
}

/* ============================================================ RESULT STORAGE */

/**
 * Read the last cached audit ( or null when none has run yet ).
 *
 * @return array|null { @type array $result, @type int $time }
 */
function themify_seo_health_get_cached() {
	$stored = get_option( THEMIFY_SEO_HEALTH_OPT, array() );
	if ( ! is_array( $stored ) || empty( $stored['result'] ) || ! is_array( $stored['result'] ) ) {
		return null;
	}
	$stored['time'] = isset( $stored['time'] ) ? (int) $stored['time'] : 0;
	return $stored;
}

/**
 * Persist an audit result with a timestamp.
 *
 * @param array $result Result produced by themify_run_seo_audit().
 */
function themify_seo_health_store( array $result ) {
	update_option(
		THEMIFY_SEO_HEALTH_OPT,
		array(
			'result' => $result,
			'time'   => time(),
		),
		false
	);
}

/* ============================================================ URL SAMPLE */

/**
 * Build the URL sample to audit: the home page plus the most recent published
 * posts and pages, de-duplicated and capped to THEMIFY_SEO_HEALTH_MAX_URLS.
 *
 * @return string[] Absolute URLs on this site (home first).
 */
function themify_seo_health_sample_urls() {
	$max  = (int) THEMIFY_SEO_HEALTH_MAX_URLS;
	$urls = array();

	// Home always first — it is the most important page to get right.
	$home              = home_url( '/' );
	$urls[ $home ]     = $home;

	// Latest ~15 published posts + pages by modified date.
	$query = new WP_Query( array(
		'post_type'              => array( 'post', 'page' ),
		'post_status'            => 'publish',
		'posts_per_page'         => max( 1, $max - 1 ),
		'orderby'                => 'modified',
		'order'                  => 'DESC',
		'has_password'           => false,
		'fields'                 => 'ids',
		'no_found_rows'          => true,
		'update_post_meta_cache' => false,
		'update_post_term_cache' => false,
		'ignore_sticky_posts'    => true,
	) );

	foreach ( (array) $query->posts as $post_id ) {
		$link = get_permalink( $post_id );
		if ( $link && ! isset( $urls[ $link ] ) ) {
			$urls[ $link ] = $link;
		}
		if ( count( $urls ) >= $max ) {
			break;
		}
	}

	return array_slice( array_values( $urls ), 0, $max );
}

/* ============================================================ HTML INSPECTORS */
/*
 * Small, dependency-free regex helpers. HTML parsing with regex is fragile in
 * general, but here we only need coarse presence/length/count signals over a
 * site's own well-formed WordPress output, which is exactly what these do.
 */

/**
 * Extract the trimmed text of the first <title> element.
 *
 * @param string $html Raw HTML.
 * @return string Title text (may be '').
 */
function themify_seo_extract_title( $html ) {
	if ( preg_match( '/<title\b[^>]*>(.*?)<\/title>/is', $html, $m ) ) {
		return trim( html_entity_decode( wp_strip_all_tags( $m[1] ), ENT_QUOTES, 'UTF-8' ) );
	}
	return '';
}

/**
 * Extract the content of the first <meta name="description"> tag (order of
 * attributes does not matter).
 *
 * @param string $html Raw HTML.
 * @return string Description text (may be '').
 */
function themify_seo_extract_meta_description( $html ) {
	if ( ! preg_match_all( '/<meta\b[^>]*>/is', $html, $tags ) ) {
		return '';
	}
	foreach ( $tags[0] as $tag ) {
		if ( preg_match( '/\bname\s*=\s*("|\')\s*description\s*\1/i', $tag )
			&& preg_match( '/\bcontent\s*=\s*("|\')(.*?)\1/is', $tag, $c ) ) {
			return trim( html_entity_decode( $c[2], ENT_QUOTES, 'UTF-8' ) );
		}
	}
	return '';
}

/**
 * Count the number of <h1> elements.
 *
 * @param string $html Raw HTML.
 * @return int
 */
function themify_seo_count_h1( $html ) {
	return (int) preg_match_all( '/<h1\b[^>]*>/i', $html );
}

/**
 * Whether a rel="canonical" <link> is present.
 *
 * @param string $html Raw HTML.
 * @return bool
 */
function themify_seo_has_canonical( $html ) {
	if ( ! preg_match_all( '/<link\b[^>]*>/is', $html, $links ) ) {
		return false;
	}
	foreach ( $links[0] as $link ) {
		if ( preg_match( '/\brel\s*=\s*("|\')\s*canonical\s*\1/i', $link ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Count <img> tags that lack an alt attribute entirely.
 *
 * An EMPTY alt ( alt="" ) is valid, spec-compliant markup for decorative
 * images (the theme's own card thumbnails use it on purpose), so only images
 * with NO alt attribute at all count as missing.
 *
 * @param string $html Raw HTML.
 * @return array { @type int $total, @type int $missing }
 */
function themify_seo_count_images_missing_alt( $html ) {
	$total   = 0;
	$missing = 0;
	if ( preg_match_all( '/<img\b[^>]*>/is', $html, $imgs ) ) {
		foreach ( $imgs[0] as $img ) {
			$total++;
			if ( ! preg_match( '/\balt\s*=\s*("|\')/is', $img ) ) {
				$missing++;
			}
		}
	}
	return array(
		'total'   => $total,
		'missing' => $missing,
	);
}

/**
 * Whether a responsive viewport <meta> is present.
 *
 * @param string $html Raw HTML.
 * @return bool
 */
function themify_seo_has_viewport( $html ) {
	if ( ! preg_match_all( '/<meta\b[^>]*>/is', $html, $tags ) ) {
		return false;
	}
	foreach ( $tags[0] as $tag ) {
		if ( preg_match( '/\bname\s*=\s*("|\')\s*viewport\s*\1/i', $tag ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Whether the <html> tag carries a non-empty lang attribute.
 *
 * @param string $html Raw HTML.
 * @return bool
 */
function themify_seo_has_html_lang( $html ) {
	if ( preg_match( '/<html\b([^>]*)>/is', $html, $m )
		&& preg_match( '/\blang\s*=\s*("|\')\s*([^"\']+?)\s*\1/i', $m[1] ) ) {
		return true;
	}
	return false;
}

/**
 * Detect an accidental noindex directive in a robots meta tag or the
 * X-Robots-Tag response header.
 *
 * @param string $html    Raw HTML.
 * @param array  $headers Lower-cased response headers ( name => value ).
 * @return bool True when the page tells engines not to index it.
 */
function themify_seo_is_noindex( $html, $headers = array() ) {
	// robots (or googlebot) meta tag with a noindex value.
	if ( preg_match_all( '/<meta\b[^>]*>/is', $html, $tags ) ) {
		foreach ( $tags[0] as $tag ) {
			if ( preg_match( '/\bname\s*=\s*("|\')\s*(?:robots|googlebot)\s*\1/i', $tag )
				&& preg_match( '/\bcontent\s*=\s*("|\')([^"\']*)\1/i', $tag, $c )
				&& false !== stripos( $c[2], 'noindex' ) ) {
				return true;
			}
		}
	}
	// X-Robots-Tag header.
	if ( isset( $headers['x-robots-tag'] ) && false !== stripos( (string) $headers['x-robots-tag'], 'noindex' ) ) {
		return true;
	}
	return false;
}

/**
 * Whether Open Graph og:title and og:image are present.
 *
 * @param string $html Raw HTML.
 * @return array { @type bool $title, @type bool $image }
 */
function themify_seo_open_graph( $html ) {
	$has_title = false;
	$has_image = false;
	if ( preg_match_all( '/<meta\b[^>]*>/is', $html, $tags ) ) {
		foreach ( $tags[0] as $tag ) {
			if ( preg_match( '/\bproperty\s*=\s*("|\')\s*og:title\s*\1/i', $tag ) ) {
				$has_title = true;
			}
			if ( preg_match( '/\bproperty\s*=\s*("|\')\s*og:image\s*\1/i', $tag ) ) {
				$has_image = true;
			}
		}
	}
	return array(
		'title' => $has_title,
		'image' => $has_image,
	);
}

/**
 * Whether at least one JSON-LD structured-data block is present.
 *
 * @param string $html Raw HTML.
 * @return bool
 */
function themify_seo_has_json_ld( $html ) {
	return (bool) preg_match( '/<script\b[^>]*type\s*=\s*("|\')application\/ld\+json\1[^>]*>/i', $html );
}

/**
 * Whether a Twitter Card meta tag is present (name="twitter:card").
 *
 * @param string $html Raw HTML.
 * @return bool
 */
function themify_seo_has_twitter_card( $html ) {
	if ( ! preg_match_all( '/<meta\b[^>]*>/is', $html, $tags ) ) {
		return false;
	}
	foreach ( $tags[0] as $tag ) {
		if ( preg_match( '/\bname\s*=\s*("|\')\s*twitter:card\s*\1/i', $tag ) ) {
			return true;
		}
	}
	return false;
}

/**
 * First skipped heading transition (e.g. h1 → h3), or null when sequential.
 *
 * @param string $html Raw HTML.
 * @return array|null [ from, to ] levels.
 */
function themify_seo_heading_skip( $html ) {
	if ( ! preg_match_all( '/<h([1-6])\b/i', $html, $m ) ) {
		return null;
	}
	$prev = 0;
	foreach ( $m[1] as $raw ) {
		$level = (int) $raw;
		if ( $prev > 0 && $level > $prev + 1 ) {
			return array( $prev, $level );
		}
		$prev = $level;
	}
	return null;
}

/**
 * Count internal links (same-host or root-relative hrefs).
 *
 * @param string $html Raw HTML.
 * @return int
 */
function themify_seo_internal_links( $html ) {
	$count = 0;
	$host  = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	if ( preg_match_all( '/<a\b[^>]*\bhref\s*=\s*("|\')(.*?)\1/is', $html, $m ) ) {
		foreach ( $m[2] as $href ) {
			$href = trim( $href );
			if ( '' === $href || 0 === strpos( $href, '#' ) ) {
				continue;
			}
			if ( 0 === strpos( $href, '/' ) && 0 !== strpos( $href, '//' ) ) {
				$count++;
				continue;
			}
			$link_host = strtolower( (string) wp_parse_url( $href, PHP_URL_HOST ) );
			if ( $link_host && ( $link_host === $host || 'www.' . $host === $link_host || 'www.' . $link_host === $host ) ) {
				$count++;
			}
		}
	}
	return $count;
}

/**
 * Rough visible word count: drop scripts/styles, strip tags, count words.
 *
 * @param string $html Raw HTML.
 * @return int
 */
function themify_seo_word_count( $html ) {
	// Remove elements whose text is not page copy.
	$stripped = preg_replace( '/<(script|style|noscript|template)\b[^>]*>.*?<\/\1>/is', ' ', $html );
	$stripped = is_string( $stripped ) ? $stripped : $html;

	// Prefer the <body> so chrome in <head> does not inflate the count.
	if ( preg_match( '/<body\b[^>]*>(.*)<\/body>/is', $stripped, $bm ) ) {
		$stripped = $bm[1];
	}

	$text = wp_strip_all_tags( $stripped );
	$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
	return (int) str_word_count( $text );
}

/* ============================================================ SCORING */

/**
 * Point penalty for a given severity. Higher severity subtracts more from the
 * per-page score (which starts at 100).
 *
 * @param string $severity One of critical | warning | info.
 * @return int
 */
function themify_seo_severity_weight( $severity ) {
	switch ( $severity ) {
		case 'critical':
			return 20;
		case 'warning':
			return 8;
		case 'info':
		default:
			return 3;
	}
}

/**
 * Audit a single fetched page's HTML and return its issue list + score.
 *
 * @param string $html    Raw HTML body.
 * @param array  $headers Lower-cased response headers.
 * @return array { @type array[] $issues, @type int $score, @type array $signals }
 */
function themify_seo_evaluate_html( $html, $headers = array() ) {
	$issues  = array();
	$signals = array();
	$checks  = array();

	// --- Title -------------------------------------------------------------
	$title           = themify_seo_extract_title( $html );
	$title_len       = function_exists( 'mb_strlen' ) ? mb_strlen( $title ) : strlen( $title );
	$signals['title']     = $title;
	$signals['title_len'] = $title_len;

	$checks['meta_title'] = ( '' !== $title && $title_len >= 30 && $title_len <= 60 );
	if ( '' === $title ) {
		$issues[] = themify_seo_issue( 'critical', __( 'Missing <title> tag', 'themify' ), __( 'Add a unique, descriptive title (30–60 characters).', 'themify' ) );
	} elseif ( $title_len < 30 ) {
		$issues[] = themify_seo_issue( 'warning', sprintf( /* translators: %d: character count */ __( 'Title is too short (%d chars, min 30)', 'themify' ), $title_len ), __( 'Write a descriptive title between 30-60 characters.', 'themify' ) );
	} elseif ( $title_len > 60 ) {
		$issues[] = themify_seo_issue( 'warning', sprintf( /* translators: %d: character count */ __( 'Title is too long (%d chars, max 60)', 'themify' ), $title_len ), __( 'Shorten title to 60 characters or less for full SERP display.', 'themify' ) );
	}

	// --- Meta description --------------------------------------------------
	$desc      = themify_seo_extract_meta_description( $html );
	$desc_len  = function_exists( 'mb_strlen' ) ? mb_strlen( $desc ) : strlen( $desc );
	$signals['desc_len'] = $desc_len;

	$checks['meta_desc'] = ( '' !== $desc && $desc_len >= 120 && $desc_len <= 160 );
	if ( '' === $desc ) {
		$issues[] = themify_seo_issue( 'warning', __( 'No meta description', 'themify' ), __( 'Write a 120–160 character summary to improve click-through from search.', 'themify' ) );
	} elseif ( $desc_len < 120 ) {
		$issues[] = themify_seo_issue( 'warning', sprintf( /* translators: %d: character count */ __( 'Meta description too short (%d chars, min 120)', 'themify' ), $desc_len ), __( 'Expand meta description to at least 120 characters.', 'themify' ) );
	} elseif ( $desc_len > 160 ) {
		$issues[] = themify_seo_issue( 'warning', sprintf( /* translators: %d: character count */ __( 'Meta description too long (%d chars, max 160)', 'themify' ), $desc_len ), __( 'Shorten meta description to 160 characters for full SERP display.', 'themify' ) );
	}

	// --- H1 ----------------------------------------------------------------
	$h1_count            = themify_seo_count_h1( $html );
	$signals['h1_count'] = $h1_count;
	$checks['h1_single'] = ( 1 === $h1_count );
	if ( 0 === $h1_count ) {
		$issues[] = themify_seo_issue( 'critical', __( 'No <h1> heading', 'themify' ), __( 'Every page should have exactly one clear <h1>.', 'themify' ) );
	} elseif ( $h1_count > 1 ) {
		$issues[] = themify_seo_issue( 'warning', sprintf( /* translators: %d: heading count */ __( 'Page has %d H1 tags (should be exactly 1)', 'themify' ), $h1_count ), __( 'Use only one <h1> per page for clear hierarchy.', 'themify' ) );
	}

	// --- Heading hierarchy ---------------------------------------------------
	$skip                    = themify_seo_heading_skip( $html );
	$checks['h_hierarchy']   = ( null === $skip );
	if ( null !== $skip ) {
		$issues[] = themify_seo_issue(
			'warning',
			sprintf( /* translators: 1: from level, 2: to level */ __( 'Heading hierarchy skips level (h%1$d → h%2$d)', 'themify' ), $skip[0], $skip[1] ),
			__( 'Use sequential heading levels without skipping (h1→h2→h3).', 'themify' )
		);
	}

	// --- Canonical ---------------------------------------------------------
	$checks['tech_canonical'] = themify_seo_has_canonical( $html );
	if ( ! $checks['tech_canonical'] ) {
		$issues[] = themify_seo_issue( 'warning', __( 'No canonical link', 'themify' ), __( 'Add a rel="canonical" link to prevent duplicate-content dilution.', 'themify' ) );
	}

	// --- Image alts --------------------------------------------------------
	$img               = themify_seo_count_images_missing_alt( $html );
	$signals['img_missing_alt'] = $img['missing'];
	$checks['img_alt'] = ( 0 === $img['missing'] );
	if ( $img['missing'] > 0 ) {
		$issues[] = themify_seo_issue(
			'warning',
			sprintf(
				/* translators: 1: images missing alt, 2: total images */
				_n( '%1$d of %2$d image is missing alt text', '%1$d of %2$d images are missing alt text', $img['missing'], 'themify' ),
				$img['missing'],
				$img['total']
			),
			__( 'Add descriptive alt attributes for accessibility and image search.', 'themify' )
		);
	}

	// --- Viewport ----------------------------------------------------------
	$checks['tech_viewport'] = themify_seo_has_viewport( $html );
	if ( ! $checks['tech_viewport'] ) {
		$issues[] = themify_seo_issue( 'critical', __( 'No responsive viewport meta', 'themify' ), __( 'Add <meta name="viewport" content="width=device-width, initial-scale=1"> for mobile.', 'themify' ) );
	}

	// --- html lang ---------------------------------------------------------
	$checks['tech_lang'] = themify_seo_has_html_lang( $html );
	if ( ! $checks['tech_lang'] ) {
		$issues[] = themify_seo_issue( 'info', __( 'No lang on <html>', 'themify' ), __( 'Set a language on the <html> tag (e.g. lang="en") for accessibility.', 'themify' ) );
	}

	// --- Accidental noindex ------------------------------------------------
	$checks['tech_indexable'] = ! themify_seo_is_noindex( $html, $headers );
	if ( ! $checks['tech_indexable'] ) {
		$issues[] = themify_seo_issue( 'critical', __( 'Page is set to noindex', 'themify' ), __( 'This page tells search engines not to index it. Remove noindex unless intentional.', 'themify' ) );
	}

	// --- Open Graph --------------------------------------------------------
	$og                  = themify_seo_open_graph( $html );
	$checks['og_title']  = (bool) $og['title'];
	$checks['og_image']  = (bool) $og['image'];
	if ( ! $og['title'] || ! $og['image'] ) {
		$issues[] = themify_seo_issue( 'info', __( 'Incomplete Open Graph tags', 'themify' ), __( 'Add og:title and og:image so shared links show a rich preview.', 'themify' ) );
	}

	// --- Twitter Card --------------------------------------------------------
	$checks['tw_card'] = themify_seo_has_twitter_card( $html );
	if ( ! $checks['tw_card'] ) {
		$issues[] = themify_seo_issue( 'info', __( 'No Twitter Card tag', 'themify' ), __( 'Add a twitter:card meta tag so shared links show a rich preview on X/Twitter.', 'themify' ) );
	}

	// --- Internal links -------------------------------------------------------
	$links                    = themify_seo_internal_links( $html );
	$signals['internal_links'] = $links;
	$checks['links_internal'] = ( $links > 0 );
	if ( 0 === $links ) {
		$issues[] = themify_seo_issue( 'warning', __( 'No internal links on the page', 'themify' ), __( 'Link to related pages so visitors and crawlers can discover more content.', 'themify' ) );
	}

	// --- JSON-LD -----------------------------------------------------------
	$checks['sd_jsonld'] = themify_seo_has_json_ld( $html );
	if ( ! $checks['sd_jsonld'] ) {
		$issues[] = themify_seo_issue( 'info', __( 'No JSON-LD structured data', 'themify' ), __( 'Add schema.org JSON-LD to help search engines understand the page.', 'themify' ) );
	}

	// --- Thin content ------------------------------------------------------
	$words            = themify_seo_word_count( $html );
	$signals['words'] = $words;
	if ( $words < 300 ) {
		$issues[] = themify_seo_issue(
			'warning',
			sprintf(
				/* translators: %s: word count */
				__( 'Thin content (~%s words)', 'themify' ),
				number_format_i18n( $words )
			),
			__( 'Aim for 300+ words of useful copy so the page has substance to rank.', 'themify' )
		);
	}

	// --- Score -------------------------------------------------------------
	$score = 100;
	foreach ( $issues as $issue ) {
		$score -= themify_seo_severity_weight( $issue['severity'] );
	}
	$score = max( 0, min( 100, $score ) );

	return array(
		'issues'  => $issues,
		'score'   => $score,
		'signals' => $signals,
		'checks'  => $checks,
	);
}

/**
 * Build one issue record.
 *
 * @param string $severity critical | warning | info.
 * @param string $label    Short human label.
 * @param string $hint     A concrete fix hint.
 * @return array
 */
function themify_seo_issue( $severity, $label, $hint ) {
	$severity = in_array( $severity, array( 'critical', 'warning', 'info' ), true ) ? $severity : 'info';
	return array(
		'severity' => $severity,
		'label'    => (string) $label,
		'hint'     => (string) $hint,
	);
}

/* ============================================================ THE CRAWLER */

/**
 * Run the full on-demand SEO audit over the URL sample and return a structured
 * result. Bounded to THEMIFY_SEO_HEALTH_MAX_URLS requests with short timeouts.
 *
 * @return array {
 *   @type array $pages   Per-page rows ( url, score, issues, signals, load_ms, size, error ).
 *   @type array $summary Averages/totals ( avg_score, pages, pages_ok, warnings, critical ).
 *   @type int   $time    Unix timestamp of the run.
 * }
 */
function themify_run_seo_audit() {
	$urls = themify_seo_health_sample_urls();

	$pages          = array();
	$score_total    = 0;
	$scored_pages   = 0;
	$pages_ok       = 0;
	$warn_total     = 0;
	$critical_total = 0;

	foreach ( $urls as $url ) {
		$start = microtime( true );

		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => (int) THEMIFY_SEO_HEALTH_TIMEOUT,
				'redirection' => 3,
				'sslverify'   => false, // Auditing our own site; tolerate self-signed/staging certs.
				'user-agent'  => 'Themify-SEO-Audit/' . THEMIFY_VERSION . '; ' . home_url(),
				'headers'     => array( 'Accept' => 'text/html' ),
			)
		);

		$load_ms = (int) round( ( microtime( true ) - $start ) * 1000 );

		// Transport failure — record the page as errored and keep going.
		if ( is_wp_error( $response ) ) {
			$pages[] = array(
				'url'     => $url,
				'score'   => 0,
				'issues'  => array(
					themify_seo_issue( 'critical', __( 'Could not fetch page', 'themify' ), $response->get_error_message() ),
				),
				'signals' => array(),
				'load_ms' => $load_ms,
				'size'    => 0,
				'error'   => true,
			);
			$score_total += 0;
			$scored_pages++;
			$critical_total++;
			continue;
		}

		$code    = (int) wp_remote_retrieve_response_code( $response );
		$html    = (string) wp_remote_retrieve_body( $response );
		$size    = strlen( $html );
		$headers = themify_seo_normalize_headers( wp_remote_retrieve_headers( $response ) );

		// Non-2xx — treat as a critical fetch problem.
		if ( $code < 200 || $code >= 300 ) {
			$pages[] = array(
				'url'     => $url,
				'score'   => 0,
				'issues'  => array(
					themify_seo_issue(
						'critical',
						sprintf( /* translators: %d: HTTP status */ __( 'HTTP %d response', 'themify' ), $code ),
						__( 'The page did not return a normal 200 OK. Fix the redirect/error before it can rank.', 'themify' )
					),
				),
				'signals' => array(),
				'load_ms' => $load_ms,
				'size'    => $size,
				'error'   => true,
			);
			$score_total += 0;
			$scored_pages++;
			$critical_total++;
			continue;
		}

		$eval   = themify_seo_evaluate_html( $html, $headers );
		$issues = $eval['issues'];
		$score  = (int) $eval['score'];

		// Slow-load is a page-specific signal based on the measured time.
		if ( $load_ms > 1500 ) {
			$issues[] = themify_seo_issue(
				'warning',
				sprintf( /* translators: %s: milliseconds */ __( 'Slow response (%s ms)', 'themify' ), number_format_i18n( $load_ms ) ),
				__( 'Improve caching / server response time; aim for under ~800 ms.', 'themify' )
			);
			$score = max( 0, $score - themify_seo_severity_weight( 'warning' ) );
		}

		// Tally severities for the summary.
		foreach ( $issues as $issue ) {
			if ( 'critical' === $issue['severity'] ) {
				$critical_total++;
			} elseif ( 'warning' === $issue['severity'] ) {
				$warn_total++;
			}
		}

		if ( empty( $issues ) ) {
			$pages_ok++;
		}

		$score_total += $score;
		$scored_pages++;

		$pages[] = array(
			'url'     => $url,
			'score'   => $score,
			'issues'  => $issues,
			'signals' => $eval['signals'],
			'load_ms' => $load_ms,
			'size'    => $size,
			'error'   => false,
		);
	}

	$avg_score = $scored_pages > 0 ? (int) round( $score_total / $scored_pages ) : 0;

	return array(
		'pages'   => $pages,
		'summary' => array(
			'avg_score' => $avg_score,
			'pages'     => count( $pages ),
			'pages_ok'  => $pages_ok,
			'warnings'  => $warn_total,
			'critical'  => $critical_total,
		),
		'time'    => time(),
	);
}

/**
 * Normalise wp_remote_retrieve_headers() output (which may be an array or a
 * Requests_Utility_CaseInsensitiveDictionary) into a plain lower-cased array.
 *
 * @param mixed $headers Header collection.
 * @return array<string,string>
 */
function themify_seo_normalize_headers( $headers ) {
	$out = array();
	if ( is_object( $headers ) && method_exists( $headers, 'getAll' ) ) {
		$headers = $headers->getAll();
	}
	if ( is_array( $headers ) ) {
		foreach ( $headers as $name => $value ) {
			$out[ strtolower( (string) $name ) ] = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
		}
	}
	return $out;
}

/* ============================================================ AJAX RUNNER */

/**
 * AJAX: run the audit, cache it, and return the rendered report HTML.
 *
 * Wired to the .tf-run button ( data-action="themify_seo_audit" ). Requires the
 * shared admin nonce + capability.
 */
function themify_seo_health_ajax_run() {
	check_ajax_referer( 'themify_admin', 'nonce' );
	if ( ! current_user_can( THEMIFY_CAP ) ) {
		wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'themify' ) ) );
	}

	// Give the crawl room to finish even on a modest host.
	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort; may be disabled.
	}

	$result = themify_run_seo_audit();
	themify_seo_health_store( $result );

	wp_send_json_success( array( 'html' => themify_seo_health_report_html( $result ) ) );
}
add_action( 'wp_ajax_themify_seo_audit', 'themify_seo_health_ajax_run' );

/* ============================================================ BATCH SCAN */

/**
 * Audit one URL: fetch it and evaluate the HTML. Returns a page row.
 *
 * @param string $url Absolute URL on this site.
 * @return array Page row ( url, score, issues, signals, checks, load_ms, size, error ).
 */
function themify_seo_audit_url( $url ) {
	$start = microtime( true );

	$response = wp_remote_get(
		$url,
		array(
			'timeout'     => (int) THEMIFY_SEO_HEALTH_TIMEOUT,
			'redirection' => 3,
			'sslverify'   => false,
			'user-agent'  => 'Themify-SEO-Audit/' . THEMIFY_VERSION . '; ' . home_url(),
			'headers'     => array( 'Accept' => 'text/html' ),
		)
	);

	$load_ms = (int) round( ( microtime( true ) - $start ) * 1000 );

	if ( is_wp_error( $response ) ) {
		return array(
			'url'     => $url,
			'score'   => 0,
			'issues'  => array( themify_seo_issue( 'critical', __( 'Could not fetch page', 'themify' ), $response->get_error_message() ) ),
			'signals' => array(),
			'checks'  => array(),
			'load_ms' => $load_ms,
			'size'    => 0,
			'error'   => true,
		);
	}

	$code    = (int) wp_remote_retrieve_response_code( $response );
	$html    = (string) wp_remote_retrieve_body( $response );
	$size    = strlen( $html );
	$headers = themify_seo_normalize_headers( wp_remote_retrieve_headers( $response ) );

	if ( $code < 200 || $code >= 300 ) {
		return array(
			'url'     => $url,
			'score'   => 0,
			'issues'  => array(
				themify_seo_issue(
					'critical',
					sprintf( /* translators: %d: HTTP status */ __( 'HTTP %d response', 'themify' ), $code ),
					__( 'The page did not return a normal 200 OK. Fix the redirect/error before it can rank.', 'themify' )
				),
			),
			'signals' => array(),
			'checks'  => array(),
			'load_ms' => $load_ms,
			'size'    => $size,
			'error'   => true,
		);
	}

	$eval   = themify_seo_evaluate_html( $html, $headers );
	$issues = $eval['issues'];
	$score  = (int) $eval['score'];
	$checks = isset( $eval['checks'] ) ? $eval['checks'] : array();

	$checks['perf_speed'] = ( $load_ms <= 1500 );
	if ( $load_ms > 1500 ) {
		$issues[] = themify_seo_issue(
			'warning',
			sprintf( /* translators: %s: milliseconds */ __( 'Slow response (%s ms)', 'themify' ), number_format_i18n( $load_ms ) ),
			__( 'Improve caching / server response time; aim for under ~800 ms.', 'themify' )
		);
		$score = max( 0, $score - themify_seo_severity_weight( 'warning' ) );
	}

	return array(
		'url'     => $url,
		'score'   => $score,
		'issues'  => $issues,
		'signals' => $eval['signals'],
		'checks'  => $checks,
		'load_ms' => $load_ms,
		'size'    => $size,
		'error'   => false,
	);
}

/**
 * The stored batch-scan state ( queue, results, total, time ), always an array.
 *
 * @return array
 */
function themify_seo_scan_state() {
	$state = get_option( THEMIFY_SEO_HEALTH_OPT, array() );
	if ( ! is_array( $state ) || ! isset( $state['results'] ) || ! is_array( $state['results'] ) ) {
		return array(
			'queue'   => array(),
			'results' => array(),
			'total'   => 0,
			'time'    => 0,
		);
	}
	return $state;
}

/**
 * AJAX: scan the next small batch of pages. The client calls this repeatedly
 * so any number of pages can be audited without hitting a PHP/HTTP timeout.
 * POST `reset=1` rebuilds the queue and clears previous results first.
 */
function themify_seo_scan_ajax() {
	check_ajax_referer( 'themify_admin', 'nonce' );
	if ( ! current_user_can( THEMIFY_CAP ) ) {
		wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'themify' ) ) );
	}
	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( 90 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort.
	}

	$reset = ! empty( $_POST['reset'] );
	$state = themify_seo_scan_state();

	if ( $reset || empty( $state['queue'] ) ) {
		$queue = function_exists( 'themify_indexing_collect_urls' )
			? themify_indexing_collect_urls( 300 )
			: themify_seo_health_sample_urls();
		$state = array(
			'queue'   => array_values( $queue ),
			'results' => $reset ? array() : ( isset( $state['results'] ) ? $state['results'] : array() ),
			'total'   => count( $queue ),
			'time'    => $reset ? 0 : ( isset( $state['time'] ) ? (int) $state['time'] : 0 ),
		);
	}

	$total = count( $state['queue'] );
	if ( ! $total ) {
		wp_send_json_error( array( 'message' => __( 'No public URLs found to scan.', 'themify' ) ) );
	}

	// Audit the next unscanned URLs (small batch per request).
	$done_in_batch = 0;
	foreach ( $state['queue'] as $url ) {
		if ( isset( $state['results'][ $url ] ) ) {
			continue;
		}
		$state['results'][ $url ] = themify_seo_audit_url( $url );
		$done_in_batch++;
		if ( $done_in_batch >= 4 ) {
			break;
		}
	}

	$scanned = count( $state['results'] );
	$done    = $scanned >= $total;
	if ( $done ) {
		$state['time'] = time();
	}
	update_option( THEMIFY_SEO_HEALTH_OPT, $state, false );

	wp_send_json_success( array(
		'scanned' => $scanned,
		'total'   => $total,
		'done'    => $done,
	) );
}
add_action( 'wp_ajax_themify_seo_scan', 'themify_seo_scan_ajax' );

/**
 * AJAX: download the last scan as a CSV file.
 */
function themify_seo_export_ajax() {
	check_ajax_referer( 'themify_admin', 'nonce' );
	if ( ! current_user_can( THEMIFY_CAP ) ) {
		wp_die( esc_html__( 'You are not allowed to do this.', 'themify' ) );
	}

	$state   = themify_seo_scan_state();
	$results = $state['results'];

	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename=seo-health-' . gmdate( 'Y-m-d' ) . '.csv' );

	$out = fopen( 'php://output', 'w' );
	fputcsv( $out, array( 'URL', 'Score', 'Critical', 'Warnings', 'Info', 'Load (ms)', 'Issues' ) );
	foreach ( $results as $row ) {
		$n = array( 'critical' => 0, 'warning' => 0, 'info' => 0 );
		$labels = array();
		foreach ( (array) ( $row['issues'] ?? array() ) as $issue ) {
			$sev = isset( $issue['severity'] ) ? $issue['severity'] : 'info';
			if ( isset( $n[ $sev ] ) ) {
				$n[ $sev ]++;
			}
			$labels[] = (string) ( $issue['label'] ?? '' );
		}
		fputcsv( $out, array(
			(string) ( $row['url'] ?? '' ),
			(int) ( $row['score'] ?? 0 ),
			$n['critical'],
			$n['warning'],
			$n['info'],
			(int) ( $row['load_ms'] ?? 0 ),
			implode( ' | ', $labels ),
		) );
	}
	fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Streaming CSV.
	exit;
}
add_action( 'wp_ajax_themify_seo_export', 'themify_seo_export_ajax' );

/* ============================================================ AGGREGATION */

/**
 * Check-category definitions for the "Category Breakdown" grid.
 *
 * @return array key => { label, icon, checks[] }
 */
function themify_seo_categories() {
	return array(
		'meta'     => array( 'label' => __( 'Meta Tags', 'themify' ), 'icon' => 'media-text', 'checks' => array( 'meta_title', 'meta_desc' ) ),
		'og'       => array( 'label' => __( 'Open Graph', 'themify' ), 'icon' => 'share', 'checks' => array( 'og_title', 'og_image' ) ),
		'twitter'  => array( 'label' => __( 'Twitter Cards', 'themify' ), 'icon' => 'twitter', 'checks' => array( 'tw_card' ) ),
		'headings' => array( 'label' => __( 'Headings', 'themify' ), 'icon' => 'heading', 'checks' => array( 'h1_single', 'h_hierarchy' ) ),
		'images'   => array( 'label' => __( 'Images', 'themify' ), 'icon' => 'format-image', 'checks' => array( 'img_alt' ) ),
		'sd'       => array( 'label' => __( 'Structured Data', 'themify' ), 'icon' => 'editor-code', 'checks' => array( 'sd_jsonld' ) ),
		'tech'     => array( 'label' => __( 'Technical', 'themify' ), 'icon' => 'shield-alt', 'checks' => array( 'tech_canonical', 'tech_viewport', 'tech_lang', 'tech_indexable' ) ),
		'perf'     => array( 'label' => __( 'Performance', 'themify' ), 'icon' => 'performance', 'checks' => array( 'perf_speed' ) ),
		'links'    => array( 'label' => __( 'Internal Links', 'themify' ), 'icon' => 'admin-links', 'checks' => array( 'links_internal' ) ),
	);
}

/**
 * Roll the per-page results up into everything the report needs.
 *
 * @param array $results url => page row.
 * @return array { avg, critical, warnings, info, passed, issues_total, categories, top_issues }
 */
function themify_seo_aggregate( array $results ) {
	$sum      = 0;
	$scored   = 0;
	$critical = 0;
	$warnings = 0;
	$info     = 0;
	$passed   = 0;
	$top      = array();

	$categories = array();
	foreach ( themify_seo_categories() as $key => $cat ) {
		$categories[ $key ] = array(
			'label'  => $cat['label'],
			'icon'   => $cat['icon'],
			'passed' => 0,
			'total'  => 0,
		);
	}
	$cat_defs = themify_seo_categories();

	foreach ( $results as $row ) {
		$sum += (int) ( $row['score'] ?? 0 );
		$scored++;

		$page_critical = 0;
		foreach ( (array) ( $row['issues'] ?? array() ) as $issue ) {
			$sev   = isset( $issue['severity'] ) ? $issue['severity'] : 'info';
			$label = (string) ( $issue['label'] ?? '' );
			if ( 'critical' === $sev ) {
				$critical++;
				$page_critical++;
			} elseif ( 'warning' === $sev ) {
				$warnings++;
			} else {
				$info++;
			}
			if ( '' !== $label ) {
				if ( ! isset( $top[ $label ] ) ) {
					$top[ $label ] = array(
						'label'    => $label,
						'severity' => $sev,
						'hint'     => (string) ( $issue['hint'] ?? '' ),
						'pages'    => 0,
					);
				}
				$top[ $label ]['pages']++;
			}
		}
		if ( 0 === $page_critical && empty( $row['error'] ) ) {
			$passed++;
		}

		$checks = isset( $row['checks'] ) && is_array( $row['checks'] ) ? $row['checks'] : array();
		if ( ! empty( $checks ) ) {
			foreach ( $cat_defs as $key => $cat ) {
				foreach ( $cat['checks'] as $check ) {
					if ( array_key_exists( $check, $checks ) ) {
						$categories[ $key ]['total']++;
						if ( $checks[ $check ] ) {
							$categories[ $key ]['passed']++;
						}
					}
				}
			}
		}
	}

	usort( $top, function ( $a, $b ) {
		$rank = array( 'critical' => 0, 'warning' => 1, 'info' => 2 );
		$sa   = $rank[ $a['severity'] ] ?? 2;
		$sb   = $rank[ $b['severity'] ] ?? 2;
		if ( $sa !== $sb ) {
			return $sa <=> $sb;
		}
		return $b['pages'] <=> $a['pages'];
	} );

	return array(
		'avg'          => $scored ? (int) round( $sum / $scored ) : 0,
		'critical'     => $critical,
		'warnings'     => $warnings,
		'info'         => $info,
		'passed'       => $passed,
		'issues_total' => $critical + $warnings + $info,
		'categories'   => $categories,
		'top_issues'   => $top,
	);
}

/**
 * The site-wide "Global Checks" list (cheap option/function checks, no HTTP).
 *
 * @return array[] Each: { label, desc, pass }
 */
function themify_seo_global_checks() {
	$checks = array();

	$checks[] = array(
		'label' => __( 'Site is visible to search engines', 'themify' ),
		'desc'  => __( 'Settings → Reading → "Discourage search engines" must be off.', 'themify' ),
		'pass'  => (bool) get_option( 'blog_public' ),
	);
	$checks[] = array(
		'label' => __( 'XML sitemap available', 'themify' ),
		'desc'  => function_exists( 'themify_sitemap_url' ) ? themify_sitemap_url() : home_url( '/wp-sitemap.xml' ),
		'pass'  => function_exists( 'wp_sitemaps_get_server' ) && (bool) get_option( 'blog_public' ),
	);
	$checks[] = array(
		'label' => __( 'RSS feed active', 'themify' ),
		'desc'  => get_feed_link(),
		'pass'  => '' !== (string) get_feed_link(),
	);
	$checks[] = array(
		'label' => __( 'IndexNow key served', 'themify' ),
		'desc'  => function_exists( 'themify_indexnow_key_url' ) ? themify_indexnow_key_url() : '',
		'pass'  => function_exists( 'themify_indexnow_key' ) && '' !== themify_indexnow_key(),
	);
	$checks[] = array(
		'label' => __( 'Pretty permalinks enabled', 'themify' ),
		'desc'  => __( 'Readable URLs rank and share better than ?p=123.', 'themify' ),
		'pass'  => '' !== (string) get_option( 'permalink_structure' ),
	);
	$checks[] = array(
		'label' => __( 'HTTPS in use', 'themify' ),
		'desc'  => home_url( '/' ),
		'pass'  => 0 === strpos( home_url( '/' ), 'https://' ),
	);
	$checks[] = array(
		'label' => __( 'Site title set', 'themify' ),
		'desc'  => (string) get_option( 'blogname' ),
		'pass'  => '' !== trim( (string) get_option( 'blogname' ) ),
	);
	$checks[] = array(
		'label' => __( 'Tagline set', 'themify' ),
		'desc'  => (string) get_option( 'blogdescription' ),
		'pass'  => '' !== trim( (string) get_option( 'blogdescription' ) ),
	);
	$checks[] = array(
		'label' => __( 'Google Analytics 4 connected', 'themify' ),
		'desc'  => __( 'GA4 tag loads on the public site.', 'themify' ),
		'pass'  => function_exists( 'themify_ga4_active' ) && themify_ga4_active(),
	);
	$checks[] = array(
		'label' => __( 'Search Console credentials configured', 'themify' ),
		'desc'  => __( 'Used by the Analytics and Indexing Report screens.', 'themify' ),
		'pass'  => function_exists( 'themify_analytics_has_creds' ) && themify_analytics_has_creds(),
	);

	return $checks;
}

/* ============================================================ REPORT RENDERING */

/**
 * Map a 0–100 score to a badge modifier class.
 *
 * @param int $score Score.
 * @return string One of tf-badge--ok | tf-badge--warn | tf-badge--bad.
 */
function themify_seo_score_badge_class( $score ) {
	$score = (int) $score;
	if ( $score >= 90 ) {
		return 'tf-badge--ok';
	}
	if ( $score >= 70 ) {
		return 'tf-badge--warn';
	}
	return 'tf-badge--bad';
}

/**
 * Map a severity to a badge modifier class.
 *
 * @param string $severity critical | warning | info.
 * @return string
 */
function themify_seo_severity_badge_class( $severity ) {
	switch ( $severity ) {
		case 'critical':
			return 'tf-badge--bad';
		case 'warning':
			return 'tf-badge--warn';
		case 'info':
		default:
			return 'tf-badge--muted';
	}
}

/**
 * Short, human title-status label for a page row.
 *
 * @param array $signals Signals bag from the evaluator.
 * @return array { @type string $class, @type string $text }
 */
function themify_seo_title_status( array $signals ) {
	$len = isset( $signals['title_len'] ) ? (int) $signals['title_len'] : 0;

	if ( 0 === $len ) {
		return array(
			'class' => 'tf-badge--bad',
			'text'  => __( 'Missing', 'themify' ),
		);
	}
	if ( $len < 30 || $len > 60 ) {
		return array(
			'class' => 'tf-badge--warn',
			/* translators: %d: character count */
			'text'  => sprintf( __( '%d chars', 'themify' ), $len ),
		);
	}
	return array(
		'class' => 'tf-badge--ok',
		/* translators: %d: character count */
		'text'  => sprintf( __( '%d chars', 'themify' ), $len ),
	);
}

/**
 * A short, relative label for a full URL (path only, home shown as "/").
 *
 * @param string $url Absolute URL.
 * @return string
 */
function themify_seo_url_label( $url ) {
	$path = (string) wp_parse_url( $url, PHP_URL_PATH );
	if ( '' === $path ) {
		$path = '/';
	}
	return $path;
}

/**
 * Build the full report HTML: stat cards + a results table with expandable
 * per-page issue lists. All output is escaped here.
 *
 * @param array $result Result from themify_run_seo_audit().
 * @return string HTML.
 */
function themify_seo_health_report_html( array $result ) {
	$summary = isset( $result['summary'] ) && is_array( $result['summary'] ) ? $result['summary'] : array();
	$pages   = isset( $result['pages'] ) && is_array( $result['pages'] ) ? $result['pages'] : array();
	$time    = isset( $result['time'] ) ? (int) $result['time'] : 0;

	$avg      = isset( $summary['avg_score'] ) ? (int) $summary['avg_score'] : 0;
	$ok       = isset( $summary['pages_ok'] ) ? (int) $summary['pages_ok'] : 0;
	$warnings = isset( $summary['warnings'] ) ? (int) $summary['warnings'] : 0;
	$critical = isset( $summary['critical'] ) ? (int) $summary['critical'] : 0;

	if ( empty( $pages ) ) {
		return '<div class="tf-notice tf-notice--info">' . esc_html__( 'No pages were audited. Publish some content and run the audit again.', 'themify' ) . '</div>';
	}

	// Choose the average-score stat modifier from the score itself.
	$avg_mod = $avg >= 90 ? '' : ( $avg >= 70 ? ' tf-stat--warn' : ' tf-stat--bad' );

	$html = '';

	if ( $time ) {
		$html .= '<p class="tf-card__desc">' . esc_html(
			sprintf(
				/* translators: %s: human time difference */
				__( 'Last audited %s.', 'themify' ),
				themify_time_ago( $time )
			)
		) . '</p>';
	}

	// --- Stat cards --------------------------------------------------------
	$html .= '<div class="tf-stats">';
	$html .= '<div class="tf-stat' . esc_attr( $avg_mod ) . '"><div class="tf-stat__num">' . esc_html( number_format_i18n( $avg ) ) . '</div><div class="tf-stat__label">' . esc_html__( 'Avg score', 'themify' ) . '</div></div>';
	$html .= '<div class="tf-stat"><div class="tf-stat__num">' . esc_html( number_format_i18n( $ok ) . ' / ' . number_format_i18n( count( $pages ) ) ) . '</div><div class="tf-stat__label">' . esc_html__( 'Pages OK', 'themify' ) . '</div></div>';
	$html .= '<div class="tf-stat' . ( $warnings > 0 ? ' tf-stat--warn' : '' ) . '"><div class="tf-stat__num">' . esc_html( number_format_i18n( $warnings ) ) . '</div><div class="tf-stat__label">' . esc_html__( 'Warnings', 'themify' ) . '</div></div>';
	$html .= '<div class="tf-stat' . ( $critical > 0 ? ' tf-stat--bad' : '' ) . '"><div class="tf-stat__num">' . esc_html( number_format_i18n( $critical ) ) . '</div><div class="tf-stat__label">' . esc_html__( 'Critical issues', 'themify' ) . '</div></div>';
	$html .= '</div>';

	// --- Results table -----------------------------------------------------
	$html .= '<table class="tf-table">';
	$html .= '<thead><tr>';
	$html .= '<th>' . esc_html__( 'URL', 'themify' ) . '</th>';
	$html .= '<th>' . esc_html__( 'Score', 'themify' ) . '</th>';
	$html .= '<th>' . esc_html__( 'Title', 'themify' ) . '</th>';
	$html .= '<th>' . esc_html__( 'Issues', 'themify' ) . '</th>';
	$html .= '</tr></thead><tbody>';

	foreach ( $pages as $page ) {
		$url     = isset( $page['url'] ) ? (string) $page['url'] : '';
		$score   = isset( $page['score'] ) ? (int) $page['score'] : 0;
		$issues  = isset( $page['issues'] ) && is_array( $page['issues'] ) ? $page['issues'] : array();
		$signals = isset( $page['signals'] ) && is_array( $page['signals'] ) ? $page['signals'] : array();
		$load_ms = isset( $page['load_ms'] ) ? (int) $page['load_ms'] : 0;

		$title_status = themify_seo_title_status( $signals );

		// Count severities for this row's summary line.
		$n_crit = 0;
		$n_warn = 0;
		$n_info = 0;
		foreach ( $issues as $issue ) {
			if ( 'critical' === $issue['severity'] ) {
				$n_crit++;
			} elseif ( 'warning' === $issue['severity'] ) {
				$n_warn++;
			} else {
				$n_info++;
			}
		}

		$html .= '<tr>';

		// URL cell: path label linking to the live page + full URL in title.
		$html .= '<td><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener" title="' . esc_attr( $url ) . '">' . esc_html( themify_seo_url_label( $url ) ) . '</a>';
		if ( $load_ms > 0 ) {
			$html .= ' <span class="tf-badge tf-badge--muted">' . esc_html( sprintf( /* translators: %s: milliseconds */ __( '%s ms', 'themify' ), number_format_i18n( $load_ms ) ) ) . '</span>';
		}
		$html .= '</td>';

		// Score badge.
		$html .= '<td><span class="tf-badge ' . esc_attr( themify_seo_score_badge_class( $score ) ) . '">' . esc_html( number_format_i18n( $score ) ) . '</span></td>';

		// Title status badge.
		$html .= '<td><span class="tf-badge ' . esc_attr( $title_status['class'] ) . '">' . esc_html( $title_status['text'] ) . '</span></td>';

		// Issues summary + expandable detail.
		$html .= '<td>';
		if ( empty( $issues ) ) {
			$html .= '<span class="tf-badge tf-badge--ok">' . esc_html__( 'No issues', 'themify' ) . '</span>';
		} else {
			$summary_bits = array();
			if ( $n_crit > 0 ) {
				$summary_bits[] = '<span class="tf-badge tf-badge--bad">' . esc_html( sprintf( /* translators: %d: count */ _n( '%d critical', '%d critical', $n_crit, 'themify' ), $n_crit ) ) . '</span>';
			}
			if ( $n_warn > 0 ) {
				$summary_bits[] = '<span class="tf-badge tf-badge--warn">' . esc_html( sprintf( /* translators: %d: count */ _n( '%d warning', '%d warnings', $n_warn, 'themify' ), $n_warn ) ) . '</span>';
			}
			if ( $n_info > 0 ) {
				$summary_bits[] = '<span class="tf-badge tf-badge--muted">' . esc_html( sprintf( /* translators: %d: count */ _n( '%d info', '%d info', $n_info, 'themify' ), $n_info ) ) . '</span>';
			}

			$html .= '<details class="tf-seo-issues"><summary>' . implode( ' ', $summary_bits ) . '</summary>';
			$html .= '<ul class="tf-seo-issue-list">';
			foreach ( $issues as $issue ) {
				$sev  = isset( $issue['severity'] ) ? $issue['severity'] : 'info';
				$html .= '<li>'
					. '<span class="tf-badge ' . esc_attr( themify_seo_severity_badge_class( $sev ) ) . '">' . esc_html( themify_seo_severity_label( $sev ) ) . '</span> '
					. '<strong>' . esc_html( isset( $issue['label'] ) ? $issue['label'] : '' ) . '</strong>'
					. ( ! empty( $issue['hint'] ) ? ' — <span class="tf-seo-hint">' . esc_html( $issue['hint'] ) . '</span>' : '' )
					. '</li>';
			}
			$html .= '</ul></details>';
		}
		$html .= '</td>';

		$html .= '</tr>';
	}

	$html .= '</tbody></table>';

	return $html;
}

/**
 * Human label for a severity.
 *
 * @param string $severity critical | warning | info.
 * @return string
 */
function themify_seo_severity_label( $severity ) {
	switch ( $severity ) {
		case 'critical':
			return __( 'Critical', 'themify' );
		case 'warning':
			return __( 'Warning', 'themify' );
		case 'info':
		default:
			return __( 'Info', 'themify' );
	}
}

/* ============================================================ PAGE RENDER */

/**
 * Print the SEO Health page CSS + JS (batched scan loop, tabs). Complements
 * the shared design system from themify_analytics_print_assets(). All colors
 * come from the Themixify brand palette (forest green / gold / brick red).
 */
function themify_seo_print_assets() {
	$nonce = wp_create_nonce( 'themify_admin' );
	?>
	<style>
	body[class*="themify-seo-health"] #wpcontent{background:#f3f8f5}
	.tfs-pageicon{width:46px;height:46px;border-radius:13px;background:#1e8f38;display:flex;align-items:center;justify-content:center;flex:0 0 auto;box-shadow:0 5px 12px rgba(30,143,56,.35)}
	.tfs-pageicon .dashicons{color:#fff;font-size:23px;width:23px;height:23px}
	.tfs-btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;border:none;border-radius:10px;padding:10px 18px;font-size:13px;font-weight:700;cursor:pointer;text-decoration:none;line-height:1.3;color:#fff}
	.tfs-btn .dashicons{font-size:16px;width:16px;height:16px}
	.tfs-btn--green{background:#1e8f38;box-shadow:0 4px 10px rgba(30,143,56,.3)}
	.tfs-btn--green:hover{background:#156b28;color:#fff}
	.tfs-btn--white{background:#fff;color:#33463a;border:1px solid #dbe4de;box-shadow:0 1px 2px rgba(16,24,40,.05)}
	.tfs-btn--white:hover{border-color:#c3cfc7;color:#1a2b20}
	.tfs-btn[disabled]{opacity:.6;cursor:default}
	.tfs-prog{display:flex;align-items:center;gap:18px;justify-content:space-between;flex-wrap:wrap;padding:15px 22px;margin-bottom:20px}
	.tfs-prog__left{flex:1 1 380px;min-width:260px}
	.tfs-prog__line{display:flex;align-items:center;gap:10px;font-size:14px;font-weight:700;color:#1a2b20;margin-bottom:9px}
	.tfs-prog__rem{display:inline-block;background:#fdf3d9;color:#8a6d0b;border-radius:999px;padding:2px 10px;font-size:11.5px;font-weight:700}
	.tfs-prog__rem--done{background:#e3f5e8;color:#156b28}
	.tfs-prog__track{height:7px;background:#e9f0ea;border-radius:4px;overflow:hidden}
	.tfs-prog__bar{display:block;height:100%;width:0;background:linear-gradient(90deg,#1e8f38,#2a9142);border-radius:4px;transition:width .35s ease}
	.tfs-prog__btns{display:flex;gap:10px;flex-wrap:wrap}
	.tfs-score{display:flex;align-items:center;gap:34px;flex-wrap:wrap;padding:28px 30px;margin-bottom:20px}
	.tfs-ring{width:170px;height:170px;border-radius:50%;display:flex;align-items:center;justify-content:center;flex:0 0 auto}
	.tfs-ring__hole{width:132px;height:132px;border-radius:50%;background:#fff;display:flex;flex-direction:column;align-items:center;justify-content:center}
	.tfs-ring__num{font-size:40px;font-weight:800;color:#1a2b20;line-height:1}
	.tfs-ring__of{font-size:12.5px;color:#8fa096;margin-top:4px}
	.tfs-score__verdict{font-size:23px;font-weight:800;color:#1a2b20;margin:0 0 6px}
	.tfs-score__meta{font-size:13.5px;color:#5a6b62;margin:0 0 14px}
	.tfs-score__meta em{color:#b8860b;font-style:normal}
	.tfs-pills{display:flex;gap:9px;flex-wrap:wrap;margin-bottom:14px}
	.tfs-pill{display:inline-block;border-radius:999px;padding:5px 13px;font-size:12px;font-weight:700}
	.tfs-pill--bad{background:#fbe3e0;color:#b0281a}
	.tfs-pill--warn{background:#fdf3d9;color:#8a6d0b}
	.tfs-pill--info{background:#e7f0e9;color:#43564a}
	.tfs-pill--ok{background:#e3f5e8;color:#156b28}
	.tfs-score__time{font-size:12px;color:#8fa096}
	.tfs-stat{padding:18px 22px}
	.tfs-stat__top{display:flex;align-items:center;gap:8px;color:#5a6b62;font-size:13px;font-weight:600}
	.tfs-stat__top .dashicons{font-size:17px;width:17px;height:17px}
	.tfs-stat__num{font-size:29px;font-weight:800;margin-top:10px;line-height:1}
	.tfs-tabs{display:grid;grid-template-columns:1fr 1fr 1fr;gap:6px;background:#e9f0ea;border-radius:12px;padding:5px;margin-bottom:24px}
	.tfs-tab{border:none;background:transparent;border-radius:9px;padding:11px 10px;font-size:13.5px;font-weight:700;color:#5a6b62;cursor:pointer;text-align:center}
	.tfs-tab.is-active{background:#fff;color:#1a2b20;box-shadow:0 1px 3px rgba(16,24,40,.08)}
	.tfs-h3{font-size:15.5px;font-weight:800;color:#1a2b20;margin:0 0 14px}
	.tfs-cat{padding:18px 20px}
	.tfs-cat__head{display:flex;align-items:center;gap:9px;font-size:13.5px;font-weight:700;color:#1a2b20;margin-bottom:13px}
	.tfs-cat__head .dashicons{color:#1e8f38;font-size:17px;width:17px;height:17px}
	.tfs-cat__row{display:flex;align-items:center;gap:10px}
	.tfs-cat__row .tfx-track{flex:1 1 auto;margin:0}
	.tfs-cat__pct{font-size:12.5px;font-weight:800;color:#1e8f38;flex:0 0 auto}
	.tfs-cat__sub{font-size:11.5px;color:#8fa096;margin-top:9px}
	.tfs-issue{display:flex;align-items:flex-start;gap:13px;padding:16px 22px;border-bottom:1px solid #eef4f0}
	.tfs-issue:last-child{border-bottom:none}
	.tfs-issue .dashicons{flex:0 0 auto;margin-top:2px}
	.tfs-issue__main{flex:1 1 auto;min-width:0}
	.tfs-issue__label{font-size:13.5px;font-weight:700;color:#1a2b20}
	.tfs-issue__label .tfs-pill{margin-left:8px;padding:2px 9px;font-size:10.5px;vertical-align:middle}
	.tfs-issue__hint{font-size:12.5px;color:#5a6b62;margin-top:3px}
	.tfs-issue__count{flex:0 0 auto;text-align:right}
	.tfs-issue__count b{display:block;font-size:20px;font-weight:800;color:#1a2b20;line-height:1.1}
	.tfs-issue__count span{font-size:11px;color:#8fa096}
	.tfs-check{display:flex;align-items:center;gap:13px;justify-content:space-between;padding:15px 22px;border-bottom:1px solid #eef4f0}
	.tfs-check:last-child{border-bottom:none}
	.tfs-check__label{font-size:13.5px;font-weight:700;color:#1a2b20}
	.tfs-check__desc{font-size:12px;color:#8fa096;margin-top:2px;word-break:break-all}
	.tfs-empty{padding:52px 22px;text-align:center;color:#5a6b62;font-size:14px}
	.tfs-empty .dashicons{font-size:34px;width:34px;height:34px;color:#c3cfc7;display:block;margin:0 auto 10px}
	.tfs-pagetable .tfi-urlcell a{color:#24382b;text-decoration:none;font-weight:600}
	.tfs-pagetable details summary{cursor:pointer;font-size:12px;color:#5a6b62}
	.tfs-pagetable details ul{margin:8px 0 0 2px;padding:0;list-style:none}
	.tfs-pagetable details li{font-size:12px;color:#43564a;padding:3px 0}
	</style>
	<script>
	(function(){
		var TFS_NONCE = <?php echo wp_json_encode( $nonce ); ?>;
		var busy = false;

		function progUI(scanned, total){
			var pl = document.getElementById('tfs-prog-label');
			var pb = document.getElementById('tfs-prog-bar');
			var rem = document.getElementById('tfs-prog-rem');
			if (pl) { pl.textContent = scanned + ' / ' + total + ' pages scanned'; }
			if (pb) { pb.style.width = (total ? Math.round(scanned / total * 100) : 0) + '%'; }
			if (rem) {
				var left = Math.max(0, total - scanned);
				rem.textContent = left ? left + ' remaining' : 'All scanned';
				rem.className = 'tfs-prog__rem' + (left ? '' : ' tfs-prog__rem--done');
			}
		}

		function scanCall(reset){
			var d = new FormData();
			d.append('action', 'themify_seo_scan');
			d.append('nonce', TFS_NONCE);
			if (reset) { d.append('reset', '1'); }
			return fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: d })
				.then(function (r) { return r.json(); });
		}

		function runLoop(reset, target){
			if (busy) { return; }
			busy = true;
			document.querySelectorAll('.tfs-scanbtn').forEach(function (b) { b.disabled = true; });
			var step = function (isFirst) {
				scanCall(isFirst && reset).then(function (res) {
					if (!res || !res.success) {
						var m = res && res.data && res.data.message ? res.data.message : 'Scan failed.';
						var box = document.getElementById('tfs-msg');
						if (box) { box.innerHTML = '<div class="tf-notice tf-notice--warn">' + m + '</div>'; }
						busy = false;
						document.querySelectorAll('.tfs-scanbtn').forEach(function (b) { b.disabled = false; });
						return;
					}
					progUI(res.data.scanned, res.data.total);
					var reached = target > 0 && res.data.scanned >= target;
					if (res.data.done || reached) {
						location.reload();
					} else {
						step(false);
					}
				}).catch(function () {
					busy = false;
					document.querySelectorAll('.tfs-scanbtn').forEach(function (b) { b.disabled = false; });
				});
			};
			step(true);
		}

		document.addEventListener('click', function (e) {
			var rescan = e.target.closest('#tfs-rescan');
			if (rescan) { e.preventDefault(); runLoop(true, 0); return; }
			var all = e.target.closest('#tfs-all');
			if (all) { e.preventDefault(); runLoop(false, 0); return; }
			var next = e.target.closest('#tfs-next');
			if (next) {
				e.preventDefault();
				runLoop(false, parseInt(next.getAttribute('data-target') || '0', 10));
				return;
			}
			var tab = e.target.closest('.tfs-tab');
			if (tab) {
				e.preventDefault();
				document.querySelectorAll('.tfs-tab').forEach(function (t) { t.classList.remove('is-active'); });
				tab.classList.add('is-active');
				var key = tab.getAttribute('data-tab');
				document.querySelectorAll('.tfs-panel').forEach(function (p) {
					p.style.display = p.getAttribute('data-panel') === key ? '' : 'none';
				});
			}
		});
	})();
	</script>
	<?php
}

/**
 * Render the "SEO Health" admin console — batched full-site audit with score
 * ring, severity stats, category breakdown, top issues, per-page results and
 * site-wide global checks.
 */
function themify_seo_health_page() {
	echo '<div class="wrap tfx">';
	if ( function_exists( 'themify_analytics_print_assets' ) ) {
		themify_analytics_print_assets();
	}
	themify_seo_print_assets();

	$state   = themify_seo_scan_state();
	$results = $state['results'];
	$total   = max( count( $state['queue'] ), count( $results ) );
	$scanned = count( $results );
	$left    = max( 0, $total - $scanned );
	$agg     = themify_seo_aggregate( $results );
	$avg     = $agg['avg'];

	if ( $avg >= 90 ) {
		$verdict = __( 'Excellent', 'themify' );
		$ring    = '#1e8f38';
	} elseif ( $avg >= 75 ) {
		$verdict = __( 'Good', 'themify' );
		$ring    = '#1e8f38';
	} elseif ( $avg >= 50 ) {
		$verdict = __( 'Needs Improvement', 'themify' );
		$ring    = '#b8860b';
	} else {
		$verdict = __( 'Poor', 'themify' );
		$ring    = '#c0392b';
	}

	$export_url = wp_nonce_url( admin_url( 'admin-ajax.php?action=themify_seo_export' ), 'themify_admin', 'nonce' );

	// ---- Header ----
	echo '<div class="tfx-head">';
	echo '<div style="display:flex;gap:14px;align-items:flex-start;">';
	echo '<span class="tfs-pageicon"><span class="dashicons dashicons-shield-alt"></span></span>';
	echo '<div>';
	echo '<h1>' . esc_html__( 'SEO Health', 'themify' ) . '</h1>';
	echo '<p class="tfx-sub">' . esc_html__( 'Comprehensive technical SEO audit for all your pages', 'themify' ) . '</p>';
	echo '</div>';
	echo '</div>';
	echo '<div class="tfx-tools">';
	printf(
		'<a class="tfs-btn tfs-btn--white" href="%s"><span class="dashicons dashicons-download"></span>%s</a>',
		esc_url( $export_url ),
		esc_html__( 'Export CSV', 'themify' )
	);
	echo '<button type="button" id="tfs-rescan" class="tfs-btn tfs-btn--green tfs-scanbtn"><span class="dashicons dashicons-update"></span>' . esc_html( $scanned ? __( 'Re-scan', 'themify' ) : __( 'Run Scan', 'themify' ) ) . '</button>';
	echo '</div>';
	echo '</div>';
	echo '<div id="tfs-msg"></div>';

	// ---- Progress bar ----
	echo '<div class="tfx-card tfs-prog">';
	echo '<div class="tfs-prog__left">';
	echo '<div class="tfs-prog__line"><span id="tfs-prog-label">' . esc_html( $scanned . ' / ' . $total . ' ' . __( 'pages scanned', 'themify' ) ) . '</span>';
	printf(
		'<span class="tfs-prog__rem%s" id="tfs-prog-rem">%s</span>',
		$left ? '' : ' tfs-prog__rem--done',
		esc_html( $left ? sprintf( /* translators: %d: count */ __( '%d remaining', 'themify' ), $left ) : __( 'All scanned', 'themify' ) )
	);
	echo '</div>';
	echo '<div class="tfs-prog__track"><span class="tfs-prog__bar" id="tfs-prog-bar" style="width:' . (int) ( $total ? round( $scanned / $total * 100 ) : 0 ) . '%"></span></div>';
	echo '</div>';
	if ( $left > 0 ) {
		echo '<div class="tfs-prog__btns">';
		printf(
			'<button type="button" id="tfs-next" class="tfs-btn tfs-btn--green tfs-scanbtn" data-target="%d"><span class="dashicons dashicons-controls-play"></span>%s</button>',
			(int) min( $total, $scanned + 10 ),
			esc_html( sprintf( /* translators: %d: count */ __( 'Scan Next %d', 'themify' ), min( 10, $left ) ) )
		);
		printf(
			'<button type="button" id="tfs-all" class="tfs-btn tfs-btn--white tfs-scanbtn"><span class="dashicons dashicons-superhero-alt"></span>%s</button>',
			esc_html( sprintf( /* translators: %d: count */ __( 'Scan All (%d)', 'themify' ), $left ) )
		);
		echo '</div>';
	}
	echo '</div>';

	if ( ! $scanned ) {
		echo '<div class="tfx-card"><div class="tfs-empty"><span class="dashicons dashicons-shield-alt"></span>';
		echo esc_html__( 'No scan data yet. Click "Run Scan" to audit every public page — titles, descriptions, headings, Open Graph, structured data, performance and more.', 'themify' );
		echo '</div></div>';
		echo '<button type="button" class="tfx-top" aria-label="' . esc_attr__( 'Scroll to top', 'themify' ) . '"><span class="dashicons dashicons-arrow-up-alt2"></span></button>';
		echo '</div>';
		return;
	}

	// ---- Score card ----
	$ring_pct = max( 0, min( 100, $avg ) );
	echo '<div class="tfx-card tfs-score">';
	printf(
		'<div class="tfs-ring" style="background:conic-gradient(%1$s 0 %2$d%%, #e9f0ea %2$d%% 100%%);"><div class="tfs-ring__hole"><span class="tfs-ring__num">%3$s</span><span class="tfs-ring__of">/ 100</span></div></div>',
		esc_attr( $ring ),
		(int) $ring_pct,
		esc_html( number_format_i18n( $avg ) )
	);
	echo '<div>';
	echo '<h2 class="tfs-score__verdict">' . esc_html( $verdict ) . '</h2>';
	echo '<p class="tfs-score__meta">' . esc_html( sprintf(
		/* translators: 1: pages scanned, 2: issues found */
		__( '%1$s pages scanned · %2$s issues found', 'themify' ),
		number_format_i18n( $scanned ),
		number_format_i18n( $agg['issues_total'] )
	) );
	if ( $left > 0 ) {
		echo ' <em>' . esc_html( sprintf( /* translators: %d: count */ __( '(%d pages remaining)', 'themify' ), $left ) ) . '</em>';
	}
	echo '</p>';
	echo '<div class="tfs-pills">';
	echo '<span class="tfs-pill tfs-pill--bad">' . esc_html( sprintf( /* translators: %d: count */ __( '%d Critical', 'themify' ), $agg['critical'] ) ) . '</span>';
	echo '<span class="tfs-pill tfs-pill--warn">' . esc_html( sprintf( /* translators: %d: count */ __( '%d Warnings', 'themify' ), $agg['warnings'] ) ) . '</span>';
	echo '<span class="tfs-pill tfs-pill--info">' . esc_html( sprintf( /* translators: %d: count */ __( '%d Info', 'themify' ), $agg['info'] ) ) . '</span>';
	echo '<span class="tfs-pill tfs-pill--ok">' . esc_html( sprintf( /* translators: %d: count */ __( '%d Passed', 'themify' ), $agg['passed'] ) ) . '</span>';
	echo '</div>';
	if ( ! empty( $state['time'] ) ) {
		echo '<div class="tfs-score__time">' . esc_html( sprintf(
			/* translators: %s: date */
			__( 'Last scanned: %s', 'themify' ),
			date_i18n( get_option( 'date_format' ) . ', ' . get_option( 'time_format' ), (int) $state['time'] + ( (int) get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) )
		) ) . '</div>';
	}
	echo '</div>';
	echo '</div>';

	// ---- Severity stat cards ----
	$stats = array(
		array( __( 'Critical', 'themify' ), $agg['critical'], 'dismiss', '#c0392b' ),
		array( __( 'Warnings', 'themify' ), $agg['warnings'], 'warning', '#b8860b' ),
		array( __( 'Info', 'themify' ), $agg['info'], 'info-outline', '#43564a' ),
		array( __( 'Pages Passed', 'themify' ), $agg['passed'], 'yes-alt', '#1e8f38' ),
	);
	echo '<div class="tfx-grid4">';
	foreach ( $stats as $s ) {
		echo '<div class="tfx-card tfs-stat">';
		echo '<div class="tfs-stat__top"><span class="dashicons dashicons-' . esc_attr( $s[2] ) . '" style="color:' . esc_attr( $s[3] ) . ';"></span>' . esc_html( $s[0] ) . '</div>';
		echo '<div class="tfs-stat__num" style="color:' . esc_attr( $s[3] ) . ';">' . esc_html( number_format_i18n( (int) $s[1] ) ) . '</div>';
		echo '</div>';
	}
	echo '</div>';

	// ---- Tabs ----
	$global_checks = themify_seo_global_checks();
	echo '<div class="tfs-tabs">';
	echo '<button type="button" class="tfs-tab is-active" data-tab="overview">' . esc_html__( 'Overview', 'themify' ) . '</button>';
	echo '<button type="button" class="tfs-tab" data-tab="pages">' . esc_html( sprintf( /* translators: %d: count */ __( 'Pages (%d)', 'themify' ), $scanned ) ) . '</button>';
	echo '<button type="button" class="tfs-tab" data-tab="global">' . esc_html( sprintf( /* translators: %d: count */ __( 'Global Checks (%d)', 'themify' ), count( $global_checks ) ) ) . '</button>';
	echo '</div>';

	// ---- Panel: Overview ----
	echo '<div class="tfs-panel" data-panel="overview">';
	echo '<h3 class="tfs-h3">' . esc_html__( 'Category Breakdown', 'themify' ) . '</h3>';
	echo '<div class="tfx-grid4">';
	foreach ( $agg['categories'] as $cat ) {
		$pct = $cat['total'] ? (int) round( $cat['passed'] / $cat['total'] * 100 ) : 0;
		echo '<div class="tfx-card tfs-cat">';
		echo '<div class="tfs-cat__head"><span class="dashicons dashicons-' . esc_attr( $cat['icon'] ) . '"></span>' . esc_html( $cat['label'] ) . '</div>';
		echo '<div class="tfs-cat__row">';
		echo '<div class="tfx-track"><span style="width:' . (int) max( 2, $pct ) . '%;background:' . ( $pct >= 90 ? '#1e8f38' : ( $pct >= 60 ? '#b8860b' : '#c0392b' ) ) . ';"></span></div>';
		echo '<span class="tfs-cat__pct" style="color:' . ( $pct >= 90 ? '#1e8f38' : ( $pct >= 60 ? '#b8860b' : '#c0392b' ) ) . ';">' . (int) $pct . '%</span>';
		echo '</div>';
		echo '<div class="tfs-cat__sub">' . esc_html( sprintf( /* translators: 1: passed, 2: total */ __( '%1$s/%2$s checks passed', 'themify' ), number_format_i18n( $cat['passed'] ), number_format_i18n( $cat['total'] ) ) ) . '</div>';
		echo '</div>';
	}
	echo '</div>';

	echo '<h3 class="tfs-h3" style="margin-top:24px;">' . esc_html__( 'Top Issues', 'themify' ) . '</h3>';
	echo '<div class="tfx-card">';
	if ( empty( $agg['top_issues'] ) ) {
		echo '<div class="tfs-empty"><span class="dashicons dashicons-yes-alt"></span>' . esc_html__( 'No issues found. Excellent!', 'themify' ) . '</div>';
	} else {
		foreach ( array_slice( $agg['top_issues'], 0, 12 ) as $issue ) {
			$sev = $issue['severity'];
			if ( 'critical' === $sev ) {
				$icon  = 'dismiss';
				$color = '#c0392b';
				$pill  = 'tfs-pill--bad';
			} elseif ( 'warning' === $sev ) {
				$icon  = 'warning';
				$color = '#b8860b';
				$pill  = 'tfs-pill--warn';
			} else {
				$icon  = 'info-outline';
				$color = '#43564a';
				$pill  = 'tfs-pill--info';
			}
			echo '<div class="tfs-issue">';
			echo '<span class="dashicons dashicons-' . esc_attr( $icon ) . '" style="color:' . esc_attr( $color ) . ';"></span>';
			echo '<div class="tfs-issue__main">';
			echo '<div class="tfs-issue__label">' . esc_html( $issue['label'] ) . '<span class="tfs-pill ' . esc_attr( $pill ) . '">' . esc_html( themify_seo_severity_label( $sev ) ) . '</span></div>';
			if ( '' !== $issue['hint'] ) {
				echo '<div class="tfs-issue__hint">' . esc_html( $issue['hint'] ) . '</div>';
			}
			echo '</div>';
			echo '<div class="tfs-issue__count"><b>' . esc_html( number_format_i18n( (int) $issue['pages'] ) ) . '</b><span>' . esc_html( _n( 'page', 'pages', (int) $issue['pages'], 'themify' ) ) . '</span></div>';
			echo '</div>';
		}
	}
	echo '</div>';
	echo '</div>'; // overview

	// ---- Panel: Pages ----
	echo '<div class="tfs-panel" data-panel="pages" style="display:none;">';
	echo '<div class="tfx-card tfs-pagetable">';
	echo '<div class="tfx-tablewrap" style="max-height:720px;">';
	echo '<table class="tfx-table"><thead><tr>';
	echo '<th>#</th><th>' . esc_html__( 'URL', 'themify' ) . '</th><th>' . esc_html__( 'Score', 'themify' ) . '</th><th>' . esc_html__( 'Load', 'themify' ) . '</th><th>' . esc_html__( 'Issues', 'themify' ) . '</th>';
	echo '</tr></thead><tbody>';
	$i = 0;
	foreach ( $results as $row ) {
		$i++;
		$url    = (string) ( $row['url'] ?? '' );
		$score  = (int) ( $row['score'] ?? 0 );
		$issues = isset( $row['issues'] ) && is_array( $row['issues'] ) ? $row['issues'] : array();
		echo '<tr>';
		echo '<td class="tfx-rank">' . (int) $i . '</td>';
		printf(
			'<td class="tfi-urlcell"><a href="%s" target="_blank" rel="noopener noreferrer" title="%s">%s</a></td>',
			esc_url( $url ),
			esc_attr( $url ),
			esc_html( themify_seo_url_label( $url ) )
		);
		echo '<td><span class="tf-badge ' . esc_attr( themify_seo_score_badge_class( $score ) ) . '">' . esc_html( number_format_i18n( $score ) ) . '</span></td>';
		echo '<td>' . esc_html( number_format_i18n( (int) ( $row['load_ms'] ?? 0 ) ) . ' ms' ) . '</td>';
		echo '<td>';
		if ( empty( $issues ) ) {
			echo '<span class="tf-badge tf-badge--ok">' . esc_html__( 'No issues', 'themify' ) . '</span>';
		} else {
			echo '<details><summary>' . esc_html( sprintf( /* translators: %d: count */ _n( '%d issue', '%d issues', count( $issues ), 'themify' ), count( $issues ) ) ) . '</summary><ul>';
			foreach ( $issues as $issue ) {
				$sev = isset( $issue['severity'] ) ? $issue['severity'] : 'info';
				echo '<li><span class="tf-badge ' . esc_attr( themify_seo_severity_badge_class( $sev ) ) . '">' . esc_html( themify_seo_severity_label( $sev ) ) . '</span> <strong>' . esc_html( (string) ( $issue['label'] ?? '' ) ) . '</strong>' . ( ! empty( $issue['hint'] ) ? ' — ' . esc_html( (string) $issue['hint'] ) : '' ) . '</li>';
			}
			echo '</ul></details>';
		}
		echo '</td>';
		echo '</tr>';
	}
	echo '</tbody></table>';
	echo '</div>';
	echo '</div>';
	echo '</div>'; // pages

	// ---- Panel: Global checks ----
	echo '<div class="tfs-panel" data-panel="global" style="display:none;">';
	echo '<div class="tfx-card">';
	foreach ( $global_checks as $check ) {
		echo '<div class="tfs-check">';
		echo '<div><div class="tfs-check__label">' . esc_html( $check['label'] ) . '</div>';
		if ( '' !== (string) $check['desc'] ) {
			echo '<div class="tfs-check__desc">' . esc_html( (string) $check['desc'] ) . '</div>';
		}
		echo '</div>';
		echo $check['pass']
			? '<span class="tfs-pill tfs-pill--ok">' . esc_html__( 'Passed', 'themify' ) . '</span>'
			: '<span class="tfs-pill tfs-pill--warn">' . esc_html__( 'Attention', 'themify' ) . '</span>';
		echo '</div>';
	}
	echo '</div>';
	echo '</div>'; // global

	echo '<button type="button" class="tfx-top" aria-label="' . esc_attr__( 'Scroll to top', 'themify' ) . '"><span class="dashicons dashicons-arrow-up-alt2"></span></button>';
	echo '</div>'; // .tfx
}
