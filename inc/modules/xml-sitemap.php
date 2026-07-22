<?php
/**
 * Pretty, paginated XML sitemap.
 *
 * Serves the theme's own styled sitemap instead of the bare core one:
 *
 *   /sitemap_index.xml   — the sitemap index search engines are pointed at
 *                          (robots.txt + Search Console submit both use it).
 *   /sitemap.xml?paged=N — ONE sitemap, paginated: each page lists a fixed
 *                          number of URLs (default 200) and the styled view
 *                          has Previous / Next buttons — no post-sitemap1,
 *                          post-sitemap2… file sprawl.
 *   /sitemap.xsl         — the XSL stylesheet that renders both views as a
 *                          branded, human-readable table (Yoast-style).
 *
 * URL order: homepage → posts (newest first) → pages → categories → tags.
 * Toggle + per-page size live under Themixify → SEO → XML sitemap. When the
 * feature is off (or the site discourages search engines) everything here
 * steps aside and WordPress core's wp-sitemap.xml serves as before.
 *
 * @package Themify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Is the custom sitemap active?
 *
 * @return bool
 */
function themify_xsm_enabled() {
	return themify_is_enabled( 'sitemap_custom_enabled', true ) && '0' !== (string) get_option( 'blog_public' );
}

/**
 * URLs per sitemap page (50–1000, default 200).
 *
 * @return int
 */
function themify_xsm_per_page() {
	$n = (int) themify_get_option( 'sitemap_per_page', 200 );
	return max( 50, min( 1000, $n ? $n : 200 ) );
}

/**
 * When ours is on, core's wp-sitemap.xml steps aside (one sitemap per site).
 *
 * @param bool $enabled Core's intent.
 * @return bool
 */
function themify_xsm_disable_core( $enabled ) {
	return themify_xsm_enabled() ? false : $enabled;
}
add_filter( 'wp_sitemaps_enabled', 'themify_xsm_disable_core', 100 );

/* ============================================================ ROUTER */

/**
 * Intercept sitemap URLs before WordPress routes the request. Path-based, so
 * it works with any permalink structure and needs no rewrite flush.
 */
function themify_xsm_router() {
	if ( is_admin() || ! themify_xsm_enabled() || empty( $_SERVER['REQUEST_URI'] ) ) {
		return;
	}

	$req  = (string) wp_parse_url( (string) $_SERVER['REQUEST_URI'], PHP_URL_PATH );
	$base = rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
	if ( $base && 0 === strpos( $req, $base ) ) {
		$req = substr( $req, strlen( $base ) );
	}
	$req = '/' . ltrim( $req, '/' );

	if ( '/sitemap_index.xml' === $req ) {
		themify_xsm_render_index();
	} elseif ( '/sitemap.xml' === $req ) {
		themify_xsm_render_urlset();
	} elseif ( '/sitemap.xsl' === $req ) {
		themify_xsm_render_xsl();
	}
}
add_action( 'init', 'themify_xsm_router', 1 );

/* ============================================================ DATA */

/**
 * How many URLs each content segment contributes, in output order.
 *
 * @return array{home:int,post:int,page:int,category:int,post_tag:int,total:int}
 */
function themify_xsm_totals() {
	$posts = wp_count_posts( 'post' );
	$pages = wp_count_posts( 'page' );
	$cats  = wp_count_terms( array( 'taxonomy' => 'category', 'hide_empty' => true ) );
	$tags  = wp_count_terms( array( 'taxonomy' => 'post_tag', 'hide_empty' => true ) );

	$t = array(
		'home'     => 1,
		'post'     => $posts ? (int) $posts->publish : 0,
		'page'     => $pages ? (int) $pages->publish : 0,
		'category' => is_wp_error( $cats ) ? 0 : (int) $cats,
		'post_tag' => is_wp_error( $tags ) ? 0 : (int) $tags,
	);
	$t['total'] = $t['home'] + $t['post'] + $t['page'] + $t['category'] + $t['post_tag'];

	return $t;
}

/**
 * One page worth of sitemap entries.
 *
 * @param int $offset Global offset into the combined URL list.
 * @param int $limit  Max entries to return.
 * @return array<int,array{loc:string,lastmod:string}>
 */
function themify_xsm_items( $offset, $limit ) {
	$t     = themify_xsm_totals();
	$items = array();

	// --- Homepage. ---
	if ( $offset < 1 ) {
		$latest = get_posts( array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'orderby'        => 'modified',
			'order'          => 'DESC',
			'fields'         => 'ids',
		) );
		$items[] = array(
			'loc'     => home_url( '/' ),
			'lastmod' => $latest ? (string) get_post_modified_time( 'c', true, $latest[0] ) : '',
		);
		$limit--;
	} else {
		$offset--;
	}

	// --- Posts, then pages (newest first). ---
	foreach ( array( 'post', 'page' ) as $type ) {
		if ( $limit <= 0 ) {
			break;
		}
		if ( $offset >= $t[ $type ] ) {
			$offset -= $t[ $type ];
			continue;
		}
		$ids = get_posts( array(
			'post_type'      => $type,
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'offset'         => $offset,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'fields'         => 'ids',
		) );
		foreach ( $ids as $id ) {
			$items[] = array(
				'loc'     => (string) get_permalink( $id ),
				'lastmod' => (string) get_post_modified_time( 'c', true, $id ),
			);
		}
		$limit -= count( $ids );
		$offset = 0;
	}

	// --- Category, then tag archives (stable id order for clean pagination). ---
	foreach ( array( 'category', 'post_tag' ) as $tax ) {
		if ( $limit <= 0 ) {
			break;
		}
		if ( $offset >= $t[ $tax ] ) {
			$offset -= $t[ $tax ];
			continue;
		}
		$terms = get_terms( array(
			'taxonomy'   => $tax,
			'hide_empty' => true,
			'number'     => $limit,
			'offset'     => $offset,
			'orderby'    => 'id',
			'order'      => 'ASC',
		) );
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				$link = get_term_link( $term );
				if ( ! is_wp_error( $link ) ) {
					$items[] = array(
						'loc'     => (string) $link,
						'lastmod' => '',
					);
				}
			}
			$limit -= count( $terms );
		}
		$offset = 0;
	}

	return $items;
}

/* ============================================================ OUTPUT */

/**
 * XML-escape a value (WP's esc_xml when present).
 *
 * @param string $value Raw value.
 * @return string
 */
function themify_xsm_esc( $value ) {
	return function_exists( 'esc_xml' ) ? esc_xml( $value ) : htmlspecialchars( (string) $value, ENT_QUOTES | ENT_XML1, 'UTF-8' );
}

/**
 * Shared response headers + XML prologue with the stylesheet reference.
 *
 * @param string $view Query args for the XSL (already url-encoded).
 */
function themify_xsm_prologue( $view ) {
	header( 'Content-Type: application/xml; charset=UTF-8' );
	header( 'X-Robots-Tag: noindex, follow' );
	echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
	$xsl = home_url( '/sitemap.xsl' ) . '?' . $view;
	echo '<?xml-stylesheet type="text/xsl" href="' . themify_xsm_esc( $xsl ) . '"?>' . "\n";
}

/**
 * /sitemap_index.xml — the entry point for search engines: every page of the
 * paginated sitemap, so crawlers discover all of them.
 */
function themify_xsm_render_index() {
	$per   = themify_xsm_per_page();
	$t     = themify_xsm_totals();
	$pages = max( 1, (int) ceil( $t['total'] / $per ) );

	$latest  = get_posts( array(
		'post_type'      => 'post',
		'post_status'    => 'publish',
		'posts_per_page' => 1,
		'orderby'        => 'modified',
		'order'          => 'DESC',
		'fields'         => 'ids',
	) );
	$lastmod = $latest ? (string) get_post_modified_time( 'c', true, $latest[0] ) : '';

	themify_xsm_prologue( 'view=index&pages=' . $pages . '&urls=' . $t['total'] . '&per=' . $per );

	echo '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
	for ( $p = 1; $p <= $pages; $p++ ) {
		$loc = home_url( '/sitemap.xml' ) . ( $p > 1 ? '?paged=' . $p : '' );
		echo "\t<sitemap>\n";
		echo "\t\t<loc>" . themify_xsm_esc( $loc ) . "</loc>\n";
		if ( $lastmod ) {
			echo "\t\t<lastmod>" . themify_xsm_esc( $lastmod ) . "</lastmod>\n";
		}
		echo "\t</sitemap>\n";
	}
	echo '</sitemapindex>';
	exit;
}

/**
 * /sitemap.xml?paged=N — one page of URLs.
 */
function themify_xsm_render_urlset() {
	$per   = themify_xsm_per_page();
	$t     = themify_xsm_totals();
	$pages = max( 1, (int) ceil( $t['total'] / $per ) );
	$paged = isset( $_GET['paged'] ) ? (int) $_GET['paged'] : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$paged = max( 1, min( $pages, $paged ) );

	$items = themify_xsm_items( ( $paged - 1 ) * $per, $per );

	themify_xsm_prologue( 'view=urls&current=' . $paged . '&pages=' . $pages . '&urls=' . $t['total'] . '&per=' . $per );

	echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
	foreach ( $items as $item ) {
		echo "\t<url>\n";
		echo "\t\t<loc>" . themify_xsm_esc( $item['loc'] ) . "</loc>\n";
		if ( '' !== $item['lastmod'] ) {
			echo "\t\t<lastmod>" . themify_xsm_esc( $item['lastmod'] ) . "</lastmod>\n";
		}
		echo "\t</url>\n";
	}
	echo '</urlset>';
	exit;
}

/**
 * /sitemap.xsl — the stylesheet. Pagination state arrives as query args
 * (ints only) and is baked into the XSL server-side, so the browser gets a
 * ready-made Previous / Next bar.
 */
function themify_xsm_render_xsl() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended
	$view    = isset( $_GET['view'] ) && 'index' === $_GET['view'] ? 'index' : 'urls';
	$current = isset( $_GET['current'] ) ? max( 1, (int) $_GET['current'] ) : 1;
	$pages   = isset( $_GET['pages'] ) ? max( 1, (int) $_GET['pages'] ) : 1;
	$urls    = isset( $_GET['urls'] ) ? max( 0, (int) $_GET['urls'] ) : 0;
	$per     = isset( $_GET['per'] ) ? max( 1, (int) $_GET['per'] ) : 200;
	// phpcs:enable

	$base  = esc_url( home_url( '/sitemap.xml' ) );
	$index = esc_url( home_url( '/sitemap_index.xml' ) );
	$site  = esc_html( get_bloginfo( 'name' ) );

	header( 'Content-Type: text/xsl; charset=UTF-8' );
	header( 'X-Robots-Tag: noindex, follow' );

	$css = <<<CSS
	body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background: #f3f8f5; color: #1a2b20; }
	a { color: #156b28; text-decoration: none; }
	a:hover { text-decoration: underline; }
	.wrap { max-width: 980px; margin: 0 auto; padding: 28px 20px 60px; }
	.head { background: linear-gradient(135deg, #156b28, #1e8f38); color: #fff; border-radius: 14px; padding: 26px 28px; margin-bottom: 18px; }
	.head h1 { margin: 0 0 6px; font-size: 26px; }
	.head p { margin: 0; opacity: 0.92; font-size: 14px; }
	.head a { color: #fff; text-decoration: underline; }
	.bar { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin: 16px 0; }
	.bar .info { color: #51665a; font-size: 14px; }
	.btn { display: inline-block; padding: 9px 18px; border-radius: 999px; background: #1e8f38; color: #fff; font-weight: 600; font-size: 14px; }
	.btn:hover { background: #156b28; text-decoration: none; }
	.btn.ghost { background: #fff; color: #156b28; border: 1px solid #cfe4d5; }
	.btn.off { visibility: hidden; }
	table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 14px; overflow: hidden; box-shadow: 0 1px 2px rgba(8,26,12,0.06); }
	th { text-align: left; background: #e3f5e8; color: #156b28; font-size: 13px; text-transform: uppercase; letter-spacing: 0.4px; padding: 12px 16px; }
	td { padding: 11px 16px; border-top: 1px solid #edf4ef; font-size: 14px; word-break: break-all; }
	tr:hover td { background: #f6fbf7; }
	.num { color: #8aa192; width: 40px; }
	.mod { white-space: nowrap; color: #51665a; width: 170px; }
	.foot { margin-top: 16px; color: #8aa192; font-size: 13px; text-align: center; }
CSS;

	echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
	echo '<xsl:stylesheet version="1.0" xmlns:xsl="http://www.w3.org/1999/XSL/Transform" xmlns:s="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
	echo '<xsl:output method="html" encoding="UTF-8"/>' . "\n";
	echo '<xsl:template match="/">' . "\n";
	echo '<html><head><title>XML Sitemap — ' . $site . '</title><meta name="viewport" content="width=device-width, initial-scale=1"/><style>' . $css . '</style></head><body><div class="wrap">' . "\n";

	echo '<div class="head"><h1>XML Sitemap</h1><p>' . $site . ' — generated by the Themixify theme. Search engines read this file to discover every page on the site.</p></div>' . "\n";

	if ( 'index' === $view ) {
		echo '<div class="bar"><span class="info">' . (int) $urls . ' URLs in ' . (int) $pages . ' page(s), ' . (int) $per . ' per page.</span>';
		echo '<a class="btn" href="' . $base . '">Browse the sitemap &#8594;</a></div>' . "\n";
		echo '<table><tr><th class="num">#</th><th>Sitemap page</th><th class="mod">Last modified</th></tr>' . "\n";
		echo '<xsl:for-each select="s:sitemapindex/s:sitemap">' . "\n";
		echo '<tr><td class="num"><xsl:value-of select="position()"/></td>';
		echo '<td><a><xsl:attribute name="href"><xsl:value-of select="s:loc"/></xsl:attribute><xsl:value-of select="s:loc"/></a></td>';
		echo '<td class="mod"><xsl:value-of select="substring(s:lastmod, 1, 10)"/></td></tr>' . "\n";
		echo '</xsl:for-each></table>' . "\n";
	} else {
		$prev = $current > 1 ? $base . ( 2 === $current ? '' : '?paged=' . ( $current - 1 ) ) : '';
		$next = $current < $pages ? $base . '?paged=' . ( $current + 1 ) : '';

		$bar  = '<div class="bar">';
		$bar .= $prev ? '<a class="btn ghost" href="' . $prev . '">&#8592; Previous</a>' : '<span class="btn ghost off">&#8592; Previous</span>';
		$bar .= '<span class="info">Page ' . (int) $current . ' of ' . (int) $pages . ' &#183; ' . (int) $urls . ' URLs total &#183; <a href="' . $index . '">Sitemap index</a></span>';
		$bar .= $next ? '<a class="btn" href="' . $next . '">Next &#8594;</a>' : '<span class="btn off">Next &#8594;</span>';
		$bar .= '</div>';

		echo $bar . "\n";
		echo '<table><tr><th class="num">#</th><th>URL</th><th class="mod">Last modified</th></tr>' . "\n";
		echo '<xsl:for-each select="s:urlset/s:url">' . "\n";
		echo '<tr><td class="num"><xsl:value-of select="position() + ' . ( ( $current - 1 ) * $per ) . '"/></td>';
		echo '<td><a><xsl:attribute name="href"><xsl:value-of select="s:loc"/></xsl:attribute><xsl:value-of select="s:loc"/></a></td>';
		echo '<td class="mod"><xsl:value-of select="concat(substring(s:lastmod, 1, 10), \' \', substring(s:lastmod, 12, 5))"/></td></tr>' . "\n";
		echo '</xsl:for-each></table>' . "\n";
		echo $bar . "\n";
	}

	echo '<p class="foot">Powered by Themixify</p>' . "\n";
	echo '</div></body></html>' . "\n";
	echo '</xsl:template></xsl:stylesheet>';
	exit;
}
