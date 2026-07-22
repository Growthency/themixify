<?php
/**
 * XML sitemap glue + search-engine auto-ping.
 *
 * This module deliberately does NOT reinvent sitemap generation — WordPress
 * core ships a perfectly good XML sitemap (wp-sitemap.xml) since 5.5, and this
 * file just makes sure it stays enabled and is discoverable:
 *
 *   1. Guarantees the core sitemap provider is not disabled by some other code
 *      (re-enables it late, without clobbering an existing "discourage search
 *      engines" setting — a private site legitimately has no sitemap).
 *   2. Rewrites robots.txt to advertise the sitemap URL, allow crawling of the
 *      public site, and keep crawlers out of /wp-admin/ (while still allowing
 *      admin-ajax.php, which some front-end features hit).
 *   3. Auto-pings the indexing pipeline (IndexNow + Google) whenever a public
 *      post or page is published or updated, so new/changed content gets picked
 *      up fast. The actual API calls live in the indexing module; here we only
 *      collect the affected URLs and hand them off — and only if that module is
 *      present. A short debounce transient prevents duplicate pings from the
 *      autosave/revision churn WordPress fires around a single save.
 *
 * No external HTTP happens here on a front-end request: robots.txt is cheap and
 * static, and the ping runs on the (admin/cron) save request via the indexing
 * module, which does its own transient caching.
 *
 * @package Themify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ============================================================ CORE SITEMAP */

/**
 * Make sure WordPress core's XML sitemaps stay switched on.
 *
 * WordPress disables its sitemap when the site is set to discourage search
 * engines (blog_public = 0); we honour that. Otherwise we force the provider
 * back on at a late priority so a stray `add_filter( 'wp_sitemaps_enabled',
 * '__return_false' )` from another plugin doesn't silently kill discovery.
 *
 * @param bool $enabled Whether core currently intends to serve sitemaps.
 * @return bool
 */
function themify_sitemaps_enabled( $enabled ) {
	// Respect the site owner's "discourage search engines" choice.
	if ( '0' === (string) get_option( 'blog_public' ) ) {
		return false;
	}
	return true;
}
add_filter( 'wp_sitemaps_enabled', 'themify_sitemaps_enabled', 99 );

/**
 * The canonical sitemap index URL for this site.
 *
 * Prefers core's wp_sitemaps_get_server()->index (which honours custom sitemap
 * URLs and permalink structure) and falls back to home_url('/wp-sitemap.xml').
 *
 * @return string Absolute sitemap URL.
 */
function themify_sitemap_url() {
	// The theme's own pretty, paginated sitemap takes over when enabled
	// (Themixify → SEO → XML sitemap) — robots.txt, the Search Console
	// submit button and SEO Health all follow automatically.
	if ( function_exists( 'themify_xsm_enabled' ) && themify_xsm_enabled() ) {
		return home_url( '/sitemap_index.xml' );
	}

	if ( function_exists( 'wp_sitemaps_get_server' ) ) {
		$server = wp_sitemaps_get_server();
		if ( $server && method_exists( $server, 'index' ) ) {
			$index = $server->index();
			if ( is_object( $index ) && method_exists( $index, 'get_index_url' ) ) {
				$url = $index->get_index_url();
				if ( $url ) {
					return $url;
				}
			}
		}
	}
	return home_url( '/wp-sitemap.xml' );
}

/* ============================================================ ROBOTS.TXT */

/**
 * Rewrite the virtual robots.txt output.
 *
 * WordPress only serves this virtual file when there is no static robots.txt on
 * disk. We append (never replace) sensible directives:
 *   - allow the whole public site,
 *   - disallow /wp-admin/ but explicitly allow admin-ajax.php,
 *   - advertise the sitemap index — unless the site discourages search engines,
 *     in which case core has already emitted a blanket Disallow and we add no
 *     sitemap line.
 *
 * @param string $output The robots.txt content assembled so far.
 * @param bool   $public Whether the site is public (blog_public option).
 * @return string
 */
function themify_robots_txt( $output, $public ) {
	// Normalise trailing whitespace so our block appends cleanly.
	$output = rtrim( (string) $output ) . "\n";

	// admin-ajax.php lives inside /wp-admin/, so allow it before the broad
	// Disallow. Order matters: more specific Allow first.
	$lines   = array();
	$lines[] = 'Allow: /wp-admin/admin-ajax.php';
	$lines[] = 'Disallow: /wp-admin/';

	// Only advertise a sitemap when the site actually wants to be indexed.
	// A non-public site has no sitemap (core disables the provider) and core
	// has already printed "Disallow: /", so a Sitemap line would be misleading.
	if ( $public && '0' !== (string) get_option( 'blog_public' ) ) {
		$lines[] = 'Allow: /';
		$lines[] = 'Sitemap: ' . esc_url_raw( themify_sitemap_url() );
	}

	/**
	 * Let other modules add robots.txt lines (e.g. extra sitemaps).
	 *
	 * @param string[] $lines  Directive lines this module contributes.
	 * @param bool     $public Whether the site is public.
	 */
	$lines = apply_filters( 'themify_robots_txt_lines', $lines, $public );

	foreach ( (array) $lines as $line ) {
		$line = trim( (string) $line );
		if ( '' !== $line ) {
			$output .= esc_html( $line ) . "\n";
		}
	}

	return $output;
}
add_filter( 'robots_txt', 'themify_robots_txt', 20, 2 );

/* ============================================================ AUTO-PING */

/**
 * Fire the indexing pipeline when a public post/page is published or updated.
 *
 * Bound to transition_post_status so we catch:
 *   - draft/pending/future/auto-draft → publish (a fresh publish),
 *   - publish → publish (an edit to already-live content).
 *
 * We skip revisions, autosaves, non-public post types, and anything that isn't
 * actually reaching a public "publish" state. A short debounce transient keyed
 * to the post collapses the burst of hooks WordPress fires around one save
 * (revision write, meta save, etc.) into a single ping.
 *
 * @param string  $new_status New post status.
 * @param string  $old_status Previous post status.
 * @param WP_Post $post       The post object.
 */
function themify_sitemap_auto_ping( $new_status, $old_status, $post ) {
	if ( ! $post instanceof WP_Post ) {
		return;
	}

	// Only care about content that is now public.
	if ( 'publish' !== $new_status ) {
		return;
	}

	// Never ping for autosaves or revision rows.
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( wp_is_post_revision( $post->ID ) || wp_is_post_autosave( $post->ID ) ) {
		return;
	}

	// Only public, publicly-queryable post types are worth submitting.
	$post_type = get_post_type_object( $post->post_type );
	if ( ! $post_type || empty( $post_type->public ) ) {
		return;
	}

	// Password-protected posts should not be pushed to indexers.
	if ( '' !== (string) $post->post_password ) {
		return;
	}

	// Respect "discourage search engines".
	if ( '0' === (string) get_option( 'blog_public' ) ) {
		return;
	}

	// Debounce: one ping per post per short window, regardless of how many
	// status transitions fire during a single save.
	$debounce_key = 'themify_ping_' . (int) $post->ID;
	if ( get_transient( $debounce_key ) ) {
		return;
	}
	set_transient( $debounce_key, 1, MINUTE_IN_SECONDS );

	$urls = themify_sitemap_ping_urls( $post );
	if ( empty( $urls ) ) {
		return;
	}

	/**
	 * Final list of URLs about to be submitted to indexing services.
	 *
	 * @param string[] $urls Absolute URLs.
	 * @param WP_Post  $post The post that changed.
	 */
	$urls = apply_filters( 'themify_ping_urls', $urls, $post );
	$urls = array_values( array_unique( array_filter( array_map( 'esc_url_raw', (array) $urls ) ) ) );

	if ( empty( $urls ) ) {
		return;
	}

	// Hand off to whichever indexing back-ends are installed. Both are optional;
	// this module works fine (just robots + sitemap) without them.
	if ( function_exists( 'themify_indexnow_submit' ) ) {
		themify_indexnow_submit( $urls );
	}
	if ( function_exists( 'themify_google_index_notify' ) ) {
		themify_google_index_notify( $urls );
	}
}
add_action( 'transition_post_status', 'themify_sitemap_auto_ping', 10, 3 );

/**
 * Collect the URLs affected by a change to one post: its own permalink, the
 * site home, and the archive pages that list it (categories, tags, custom
 * taxonomies and the post-type archive). Search engines can then re-crawl the
 * listing pages too, not just the post itself.
 *
 * @param WP_Post $post The changed post.
 * @return string[] Absolute URLs (unfiltered, may contain duplicates/WP_Errors removed).
 */
function themify_sitemap_ping_urls( $post ) {
	$urls = array();

	$permalink = get_permalink( $post );
	if ( $permalink ) {
		$urls[] = $permalink;
	}

	// The home page usually surfaces the newest content.
	$urls[] = home_url( '/' );

	// A separate static "Posts page", if configured.
	$blog_page = (int) get_option( 'page_for_posts' );
	if ( $blog_page ) {
		$blog_link = get_permalink( $blog_page );
		if ( $blog_link ) {
			$urls[] = $blog_link;
		}
	}

	// Every archive that lists this post (all public taxonomies it belongs to).
	$taxonomies = get_object_taxonomies( $post->post_type, 'objects' );
	foreach ( $taxonomies as $taxonomy ) {
		if ( empty( $taxonomy->public ) ) {
			continue;
		}
		$terms = get_the_terms( $post, $taxonomy->name );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			continue;
		}
		foreach ( $terms as $term ) {
			$term_link = get_term_link( $term );
			if ( ! is_wp_error( $term_link ) && $term_link ) {
				$urls[] = $term_link;
			}
		}
	}

	// The post-type archive (e.g. /blog/ for a CPT with has_archive).
	$archive = get_post_type_archive_link( $post->post_type );
	if ( $archive ) {
		$urls[] = $archive;
	}

	return array_values( array_unique( array_filter( $urls ) ) );
}

/* ============================================================ PUBLIC URL MAP */

/**
 * A compact list of the site's most important public URLs — home, the posts
 * page, recent published posts/pages, and the busiest category/tag archives.
 *
 * The indexing module reuses this for a "submit everything" / bulk-ping action.
 * Kept deliberately cheap: ID-only WP_Query with no_found_rows so it can run in
 * an admin/cron context without a heavy query. Never called on a front-end
 * page load.
 *
 * @param int $limit Soft cap on how many URLs to return.
 * @return string[] Absolute URLs, de-duplicated, capped at $limit.
 */
function themify_all_public_urls( $limit = 200 ) {
	$limit = max( 1, (int) $limit );
	$urls  = array();

	// Always include the canonical home.
	$urls[] = home_url( '/' );

	// The static posts page, if any.
	$blog_page = (int) get_option( 'page_for_posts' );
	if ( $blog_page ) {
		$link = get_permalink( $blog_page );
		if ( $link ) {
			$urls[] = $link;
		}
	}

	// Split the budget across posts, pages and archives so no single type can
	// crowd the others out on a large site.
	$post_budget = max( 1, (int) floor( $limit * 0.6 ) );
	$page_budget = max( 1, (int) floor( $limit * 0.2 ) );
	$term_budget = max( 1, (int) floor( $limit * 0.2 ) );

	// Recent published posts (ID-only, no pagination overhead).
	$post_query = new WP_Query( array(
		'post_type'              => 'post',
		'post_status'            => 'publish',
		'posts_per_page'         => $post_budget,
		'orderby'                => 'modified',
		'order'                  => 'DESC',
		'has_password'           => false,
		'fields'                 => 'ids',
		'no_found_rows'          => true,
		'update_post_meta_cache' => false,
		'update_post_term_cache' => false,
		'ignore_sticky_posts'    => true,
	) );
	foreach ( $post_query->posts as $post_id ) {
		$link = get_permalink( $post_id );
		if ( $link ) {
			$urls[] = $link;
		}
	}

	// Published pages.
	$page_query = new WP_Query( array(
		'post_type'              => 'page',
		'post_status'            => 'publish',
		'posts_per_page'         => $page_budget,
		'orderby'                => 'modified',
		'order'                  => 'DESC',
		'has_password'           => false,
		'fields'                 => 'ids',
		'no_found_rows'          => true,
		'update_post_meta_cache' => false,
		'update_post_term_cache' => false,
	) );
	foreach ( $page_query->posts as $page_id ) {
		if ( $page_id === $blog_page ) {
			continue; // Already added above.
		}
		$link = get_permalink( $page_id );
		if ( $link ) {
			$urls[] = $link;
		}
	}

	// The busiest category/tag archives (most posts first — most link equity).
	$terms = get_terms( array(
		'taxonomy'     => array( 'category', 'post_tag' ),
		'hide_empty'   => true,
		'orderby'      => 'count',
		'order'        => 'DESC',
		'number'       => $term_budget,
		'fields'       => 'all',
		'cache_domain' => 'themify_urls',
	) );
	if ( ! is_wp_error( $terms ) ) {
		foreach ( $terms as $term ) {
			$term_link = get_term_link( $term );
			if ( ! is_wp_error( $term_link ) && $term_link ) {
				$urls[] = $term_link;
			}
		}
	}

	// De-duplicate and enforce the cap.
	$urls = array_values( array_unique( array_filter( $urls ) ) );
	if ( count( $urls ) > $limit ) {
		$urls = array_slice( $urls, 0, $limit );
	}

	return $urls;
}
