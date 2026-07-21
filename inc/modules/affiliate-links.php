<?php
/**
 * Affiliate / external link manager (with link cloaking).
 *
 * A small, self-contained affiliate toolkit:
 *
 *   1. CLOAKING — pretty, on-domain redirect links. An admin defines a set of
 *      "links", each with a slug, a target URL, a human label and a rel string.
 *      A rewrite rule maps  /{base}/{slug}/  (base defaults to "go") to a 302
 *      redirect to the real target. Each hit bumps a per-link click counter so
 *      the admin can see which links get traffic. Because the visitor only ever
 *      sees the on-domain URL, affiliate targets can be swapped in one place and
 *      the destination is hidden from casual view / scrapers.
 *
 *   2. AUTO-REL — optionally rewrite outbound links in post content so external
 *      <a> tags automatically gain rel="nofollow sponsored noopener" and open in
 *      a new tab. Internal links, mailto:, tel:, and in-page anchors are skipped,
 *      and an existing rel is preserved (tokens are merged, never duplicated).
 *
 *   3. SHORTCODES — [themify_button] renders a styled .tf-btn call-to-action and
 *      [themify_link] renders an inline cloaked link, both escaping everything.
 *
 *   4. ADMIN — an "Affiliate Links" screen (custom UI, not the declarative
 *      renderer) with a .tf-repeater to manage links plus the base-path and
 *      auto-rel settings. Saving rebuilds the link list, MERGES click counts by
 *      slug so counters are never lost on save, and flushes the rewrite rules.
 *
 * Data model:
 *   - option 'themify_affiliate_links' (list-shaped, its OWN option — not
 *     THEMIFY_OPT): an indexed array of rows, each:
 *         array(
 *           'slug'   => (string) sanitize_title'd slug, unique,
 *           'url'    => (string) esc_url_raw'd target,
 *           'label'  => (string) human label,
 *           'rel'    => (string) space-separated rel tokens,
 *           'clicks' => (int)    lifetime click count,
 *         )
 *   - scalar settings live in THEMIFY_OPT via the helpers:
 *         'affiliate_base'     => base path segment (default 'go'),
 *         'affiliate_auto_rel' => checkbox toggle.
 *
 * No external HTTP happens here — cloaked redirects are a local option lookup +
 * wp_redirect, safe to run on a front-end request.
 *
 * @package Themify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Option name holding the indexed array of affiliate links.
 */
if ( ! defined( 'THEMIFY_AFFILIATE_OPT' ) ) {
	define( 'THEMIFY_AFFILIATE_OPT', 'themify_affiliate_links' );
}

/**
 * Default rel string applied to cloaked links and auto-rel outbound links when
 * none is supplied.
 */
if ( ! defined( 'THEMIFY_AFFILIATE_DEFAULT_REL' ) ) {
	define( 'THEMIFY_AFFILIATE_DEFAULT_REL', 'nofollow sponsored noopener' );
}

/* -------------------------------------------------------------------------
 * DATA ACCESS
 * ---------------------------------------------------------------------- */

/**
 * The cloak base path segment, sanitized to a single URL-safe slug. Falls back
 * to 'go' when unset or emptied. Used to build the rewrite rule and pretty URLs.
 *
 * @return string
 */
function themify_affiliate_base() {
	$base = sanitize_title( (string) themify_get_option( 'affiliate_base', 'go' ) );
	return '' !== $base ? $base : 'go';
}

/**
 * Read all stored affiliate links, normalised into a predictable shape. Every
 * row is guaranteed to have all keys with the right types; rows without a slug
 * are dropped, and duplicate slugs collapse to the first occurrence.
 *
 * @return array<int,array{slug:string,url:string,label:string,rel:string,clicks:int}>
 */
function themify_get_affiliate_links() {
	$raw = get_option( THEMIFY_AFFILIATE_OPT, array() );
	if ( ! is_array( $raw ) ) {
		return array();
	}

	$clean = array();
	$seen  = array();

	foreach ( $raw as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$slug = isset( $row['slug'] ) ? sanitize_title( (string) $row['slug'] ) : '';
		if ( '' === $slug || isset( $seen[ $slug ] ) ) {
			continue;
		}
		$seen[ $slug ] = true;

		$clean[] = array(
			'slug'   => $slug,
			'url'    => isset( $row['url'] ) ? esc_url_raw( (string) $row['url'] ) : '',
			'label'  => isset( $row['label'] ) ? (string) $row['label'] : '',
			'rel'    => isset( $row['rel'] ) ? themify_normalize_rel( (string) $row['rel'] ) : '',
			'clicks' => isset( $row['clicks'] ) ? max( 0, (int) $row['clicks'] ) : 0,
		);
	}

	return $clean;
}

/**
 * Find one affiliate link row by slug.
 *
 * @param string $slug Slug to look up.
 * @return array|null The row, or null when not found.
 */
function themify_get_affiliate_link( $slug ) {
	$slug = sanitize_title( (string) $slug );
	if ( '' === $slug ) {
		return null;
	}
	foreach ( themify_get_affiliate_links() as $row ) {
		if ( $row['slug'] === $slug ) {
			return $row;
		}
	}
	return null;
}

/**
 * Persist the affiliate-link list. Callers must have already sanitized the
 * rows; this only guarantees the value is a re-indexed array before storing.
 *
 * @param array $links List of link rows.
 * @return bool Whether the option was updated.
 */
function themify_save_affiliate_links( array $links ) {
	return update_option( THEMIFY_AFFILIATE_OPT, array_values( $links ) );
}

/**
 * Normalise a rel string: split on whitespace, drop empties, de-duplicate while
 * preserving order, and re-join with single spaces. Keeps stored + output rel
 * strings tidy and lets us merge without ever double-adding a token.
 *
 * @param string $rel Raw rel string.
 * @return string
 */
function themify_normalize_rel( $rel ) {
	$tokens = preg_split( '/\s+/', trim( (string) $rel ) );
	if ( ! is_array( $tokens ) ) {
		return '';
	}
	$out = array();
	foreach ( $tokens as $token ) {
		$token = sanitize_html_class( $token );
		if ( '' !== $token && ! in_array( $token, $out, true ) ) {
			$out[] = $token;
		}
	}
	return implode( ' ', $out );
}

/* -------------------------------------------------------------------------
 * CLOAKING — rewrite rule, query var, redirect
 * ---------------------------------------------------------------------- */

/**
 * The public URL for a cloaked link: home_url( '/{base}/{slug}/' ). Returned
 * regardless of whether the slug currently exists so templates can build links
 * predictably; unknown slugs simply 404 / fall through at redirect time.
 *
 * @param string $slug Link slug.
 * @return string Absolute cloaked URL (trailing slash), or '' for an empty slug.
 */
function themify_go_url( $slug ) {
	$slug = sanitize_title( (string) $slug );
	if ( '' === $slug ) {
		return '';
	}
	return home_url( '/' . themify_affiliate_base() . '/' . $slug . '/' );
}

/**
 * Register the cloak rewrite rule + query var so /{base}/{slug}/ is routed to
 * the redirect handler. Hooked on 'init'; the rules themselves are only flushed
 * on settings save and theme switch (see below), never on every request.
 */
function themify_affiliate_add_rewrite() {
	$base = themify_affiliate_base();

	add_rewrite_tag( '%themify_go%', '([^/]+)' );
	add_rewrite_rule(
		'^' . preg_quote( $base, '#' ) . '/([^/]+)/?$',
		'index.php?themify_go=$matches[1]',
		'top'
	);
}
add_action( 'init', 'themify_affiliate_add_rewrite' );

/**
 * Register the query var so WordPress will populate get_query_var('themify_go').
 *
 * @param string[] $vars Registered public query vars.
 * @return string[]
 */
function themify_affiliate_query_var( $vars ) {
	$vars[] = 'themify_go';
	return $vars;
}
add_filter( 'query_vars', 'themify_affiliate_query_var' );

/**
 * Resolve a cloaked request: look up the slug, bump its click count, and 302 to
 * the target. Runs on template_redirect so it fires before any template output.
 *
 * A rel attribute cannot travel on an HTTP redirect (rel is an HTML-anchor
 * concept), so we simply redirect — the rel lives on the on-page <a> that points
 * at the cloak URL. We send X-Robots-Tag: noindex so the redirector URL itself
 * never gets indexed, and use a 302 (temporary) so the affiliate target can be
 * swapped without search engines caching a permanent move.
 */
function themify_affiliate_template_redirect() {
	$slug = get_query_var( 'themify_go' );
	if ( '' === $slug || null === $slug ) {
		return;
	}

	$row = themify_get_affiliate_link( $slug );
	if ( ! $row || '' === $row['url'] ) {
		// Unknown / target-less slug: 404 rather than an open redirect.
		global $wp_query;
		if ( $wp_query instanceof WP_Query ) {
			$wp_query->set_404();
		}
		status_header( 404 );
		nocache_headers();
		return;
	}

	// Count the click (best-effort; a failed write must not block the redirect).
	themify_affiliate_increment_clicks( $row['slug'] );

	// Keep the redirector URL out of the index.
	if ( ! headers_sent() ) {
		header( 'X-Robots-Tag: noindex, nofollow', true );
	}
	nocache_headers();

	wp_redirect( $row['url'], 302 );
	exit;
}
add_action( 'template_redirect', 'themify_affiliate_template_redirect' );

/**
 * Increment the click counter for one slug and persist it, without disturbing
 * the rest of the stored data. Read-modify-write of the option keeps every
 * other field (and other rows) intact.
 *
 * @param string $slug Slug whose counter to bump.
 * @return void
 */
function themify_affiliate_increment_clicks( $slug ) {
	$slug = sanitize_title( (string) $slug );
	if ( '' === $slug ) {
		return;
	}

	$links   = themify_get_affiliate_links();
	$changed = false;
	foreach ( $links as &$row ) {
		if ( $row['slug'] === $slug ) {
			$row['clicks'] = (int) $row['clicks'] + 1;
			$changed       = true;
			break;
		}
	}
	unset( $row );

	if ( $changed ) {
		themify_save_affiliate_links( $links );
	}
}

/**
 * Flush rewrite rules on theme activation so the cloak base works immediately
 * without the admin having to re-save permalinks.
 */
function themify_affiliate_activate() {
	themify_affiliate_add_rewrite();
	flush_rewrite_rules();
}
add_action( 'after_switch_theme', 'themify_affiliate_activate' );

/* -------------------------------------------------------------------------
 * AUTO-REL ON OUTBOUND CONTENT LINKS
 * ---------------------------------------------------------------------- */

/**
 * When the auto-rel toggle is on, add rel="nofollow sponsored noopener" and
 * target="_blank" to every external <a> in post content. Internal links,
 * mailto:/tel:, and pure in-page anchors (#foo) are left untouched, and any
 * existing rel is merged (never duplicated).
 *
 * @param string $content Post content HTML.
 * @return string
 */
function themify_affiliate_auto_rel( $content ) {
	if ( ! themify_is_enabled( 'affiliate_auto_rel' ) ) {
		return $content;
	}
	// Cheap early-out: nothing to do when there are no anchors at all.
	if ( false === stripos( $content, '<a' ) ) {
		return $content;
	}

	return preg_replace_callback(
		'/<a\b[^>]*>/i',
		'themify_affiliate_rewrite_anchor',
		$content
	);
}
add_filter( 'the_content', 'themify_affiliate_auto_rel', 20 );

/**
 * Rewrite a single opening <a ...> tag: if it points to an external http(s)
 * host, ensure it carries the outbound rel tokens and target="_blank". Called by
 * the preg_replace_callback above.
 *
 * @param array $m Regex match; $m[0] is the full opening tag.
 * @return string The (possibly) modified tag.
 */
function themify_affiliate_rewrite_anchor( $m ) {
	$tag = $m[0];

	// Extract href.
	if ( ! preg_match( '/\shref\s*=\s*("|\')(.*?)\1/i', $tag, $hm ) ) {
		return $tag; // No href (e.g. named anchor) — leave alone.
	}
	$href = trim( html_entity_decode( $hm[2], ENT_QUOTES ) );

	if ( '' === $href ) {
		return $tag;
	}

	// Skip mailto:, tel:, javascript:, in-page anchors and protocol-relative-less
	// relative URLs (which are internal by definition).
	$lower = strtolower( $href );
	if (
		0 === strpos( $lower, 'mailto:' ) ||
		0 === strpos( $lower, 'tel:' ) ||
		0 === strpos( $lower, 'javascript:' ) ||
		0 === strpos( $lower, '#' )
	) {
		return $tag;
	}

	$host = wp_parse_url( $href, PHP_URL_HOST );
	if ( ! $host ) {
		// No host → relative/internal URL → skip.
		return $tag;
	}

	// Same-site (comparing bare, www-stripped hosts) → internal → skip.
	$link_host = preg_replace( '/^www\./i', '', strtolower( $host ) );
	$site_host = themify_site_host();
	if ( $link_host === $site_host ) {
		return $tag;
	}

	// --- External link: merge rel tokens and ensure target="_blank". ---

	// Merge rel: existing tokens + the outbound defaults, de-duplicated.
	$existing_rel = '';
	if ( preg_match( '/\srel\s*=\s*("|\')(.*?)\1/i', $tag, $rm ) ) {
		$existing_rel = $rm[2];
	}
	$merged_rel = themify_normalize_rel( $existing_rel . ' ' . THEMIFY_AFFILIATE_DEFAULT_REL );

	if ( '' !== $existing_rel ) {
		$tag = preg_replace(
			'/(\srel\s*=\s*)("|\')(.*?)\2/i',
			'$1"' . esc_attr( $merged_rel ) . '"',
			$tag,
			1
		);
	} else {
		// Inject a rel attribute right after <a.
		$tag = preg_replace(
			'/^<a\b/i',
			'<a rel="' . esc_attr( $merged_rel ) . '"',
			$tag,
			1
		);
	}

	// Ensure target="_blank".
	if ( preg_match( '/\starget\s*=\s*("|\')(.*?)\1/i', $tag, $tm ) ) {
		if ( '_blank' !== strtolower( trim( $tm[2] ) ) ) {
			$tag = preg_replace(
				'/(\starget\s*=\s*)("|\')(.*?)\2/i',
				'$1"_blank"',
				$tag,
				1
			);
		}
	} else {
		$tag = preg_replace( '/^<a\b/i', '<a target="_blank"', $tag, 1 );
	}

	return $tag;
}

/* -------------------------------------------------------------------------
 * SHORTCODES
 * ---------------------------------------------------------------------- */

/**
 * [themify_button url="" text="" rel="" new_tab="1"]
 *
 * Renders a styled .tf-btn call-to-action. If the given url matches a known
 * affiliate slug (or IS a bare slug), it is cloaked through /{base}/{slug}/;
 * otherwise the url is output as-is (validated with esc_url). The rel defaults
 * to the outbound rel string and new_tab defaults to on.
 *
 * @param array  $atts    Shortcode attributes.
 * @param string $content Enclosed content (used as the label when text is empty).
 * @return string
 */
function themify_shortcode_button( $atts, $content = '' ) {
	$atts = shortcode_atts(
		array(
			'url'     => '',
			'slug'    => '',
			'text'    => '',
			'rel'     => THEMIFY_AFFILIATE_DEFAULT_REL,
			'new_tab' => '1',
		),
		$atts,
		'themify_button'
	);

	// Resolve the destination + rel.
	$resolved = themify_resolve_shortcode_target( $atts['url'], $atts['slug'], $atts['rel'] );
	$href     = $resolved['url'];
	$rel      = $resolved['rel'];

	if ( '' === $href ) {
		return '';
	}

	$text = '' !== trim( (string) $atts['text'] )
		? $atts['text']
		: ( '' !== trim( (string) $content ) ? wp_strip_all_tags( $content ) : __( 'Learn more', 'themify' ) );

	$new_tab = in_array( strtolower( (string) $atts['new_tab'] ), array( '1', 'yes', 'true', 'on' ), true );

	$attributes  = 'class="tf-btn" href="' . esc_url( $href ) . '"';
	if ( '' !== $rel ) {
		$attributes .= ' rel="' . esc_attr( $rel ) . '"';
	}
	if ( $new_tab ) {
		$attributes .= ' target="_blank"';
	}

	return '<a ' . $attributes . '>' . esc_html( $text ) . '</a>';
}
add_shortcode( 'themify_button', 'themify_shortcode_button' );

/**
 * [themify_link slug="" text=""]
 *
 * Renders an inline cloaked /{base}/{slug}/ link carrying the stored rel for
 * that slug. Falls back to the link's label when no text is given. Outputs
 * nothing for an unknown slug.
 *
 * @param array  $atts    Shortcode attributes.
 * @param string $content Enclosed content (used as the label when text is empty).
 * @return string
 */
function themify_shortcode_link( $atts, $content = '' ) {
	$atts = shortcode_atts(
		array(
			'slug'    => '',
			'text'    => '',
			'new_tab' => '1',
		),
		$atts,
		'themify_link'
	);

	$row = themify_get_affiliate_link( $atts['slug'] );
	if ( ! $row ) {
		return '';
	}

	$href = themify_go_url( $row['slug'] );
	if ( '' === $href ) {
		return '';
	}

	$rel = '' !== $row['rel'] ? $row['rel'] : THEMIFY_AFFILIATE_DEFAULT_REL;

	$text = '' !== trim( (string) $atts['text'] )
		? $atts['text']
		: ( '' !== trim( (string) $content ) ? wp_strip_all_tags( $content ) : $row['label'] );
	if ( '' === trim( (string) $text ) ) {
		$text = $row['label'] ? $row['label'] : $row['slug'];
	}

	$new_tab = in_array( strtolower( (string) $atts['new_tab'] ), array( '1', 'yes', 'true', 'on' ), true );

	$attributes  = 'href="' . esc_url( $href ) . '"';
	$attributes .= ' rel="' . esc_attr( $rel ) . '"';
	if ( $new_tab ) {
		$attributes .= ' target="_blank"';
	}

	return '<a ' . $attributes . '>' . esc_html( $text ) . '</a>';
}
add_shortcode( 'themify_link', 'themify_shortcode_link' );

/**
 * Work out the destination URL + rel for the [themify_button] shortcode. The url
 * attribute may be: (a) a bare known slug, (b) a full cloak URL, or (c) any
 * external URL. Known slugs are cloaked and inherit the stored rel; explicit
 * URLs use the shortcode's rel attribute.
 *
 * @param string $url  The url= attribute (may be a slug or a URL).
 * @param string $slug Optional explicit slug= attribute (takes precedence).
 * @param string $rel  The rel= attribute (fallback for non-slug URLs).
 * @return array{url:string,rel:string}
 */
function themify_resolve_shortcode_target( $url, $slug, $rel ) {
	$url  = trim( (string) $url );
	$slug = trim( (string) $slug );

	// Explicit slug wins.
	if ( '' !== $slug ) {
		$row = themify_get_affiliate_link( $slug );
		if ( $row ) {
			return array(
				'url' => themify_go_url( $row['slug'] ),
				'rel' => '' !== $row['rel'] ? $row['rel'] : themify_normalize_rel( $rel ),
			);
		}
		// Unknown slug with no URL fallback → nothing.
		if ( '' === $url ) {
			return array( 'url' => '', 'rel' => '' );
		}
	}

	// If the url attribute is itself a bare known slug, cloak it.
	if ( '' !== $url && false === strpos( $url, '/' ) && false === strpos( $url, ':' ) ) {
		$row = themify_get_affiliate_link( $url );
		if ( $row ) {
			return array(
				'url' => themify_go_url( $row['slug'] ),
				'rel' => '' !== $row['rel'] ? $row['rel'] : themify_normalize_rel( $rel ),
			);
		}
	}

	// Otherwise treat url as a literal destination.
	return array(
		'url' => esc_url_raw( $url ),
		'rel' => themify_normalize_rel( $rel ),
	);
}

/* -------------------------------------------------------------------------
 * ADMIN PAGE
 * ---------------------------------------------------------------------- */

/**
 * Register the "Affiliate Links" submenu (position 45).
 */
themify_register_admin_page( array(
	'slug'       => 'themify-affiliate',
	'title'      => __( 'Affiliate Links', 'themify' ),
	'menu_title' => __( 'Affiliate Links', 'themify' ),
	'callback'   => 'themify_affiliate_page',
	'position'   => 24,
) );

/**
 * Add the affiliate card to the dashboard grid.
 */
add_filter( 'themify_dashboard_cards', 'themify_affiliate_dashboard_card' );

/**
 * Append the "Affiliate Links" dashboard card.
 *
 * @param array $cards Existing dashboard cards.
 * @return array
 */
function themify_affiliate_dashboard_card( $cards ) {
	$cards[] = array(
		'slug'     => 'themify-affiliate',
		'title'    => __( 'Affiliate Links', 'themify' ),
		'desc'     => __( 'Cloak, track & auto-nofollow links', 'themify' ),
		'icon'     => 'dashicons-admin-links',
		'position' => 24,
	);
	return $cards;
}

/**
 * Handle a POST save of the affiliate screen. Rebuilds the link list from the
 * posted repeater rows, MERGES the click counts by slug (so existing counters
 * survive an edit / re-order), stores the base + auto-rel settings, and flushes
 * the rewrite rules so a changed base path takes effect immediately.
 *
 * @return bool True when a valid save happened (so the caller can show a notice).
 */
function themify_affiliate_handle_save() {
	if ( ! themify_verify_save( 'themify_affiliate' ) ) {
		return false;
	}

	// Preserve existing click counts, keyed by slug, before rebuilding.
	$existing_clicks = array();
	foreach ( themify_get_affiliate_links() as $old ) {
		$existing_clicks[ $old['slug'] ] = (int) $old['clicks'];
	}

	// The repeater posts a parallel array under 'themify_affiliate_links'.
	$rows = isset( $_POST['themify_affiliate_links'] ) && is_array( $_POST['themify_affiliate_links'] )
		? wp_unslash( $_POST['themify_affiliate_links'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each field is sanitized individually below.
		: array();

	$clean = array();
	$seen  = array();

	foreach ( $rows as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}

		$slug  = isset( $row['slug'] ) ? sanitize_title( $row['slug'] ) : '';
		$url   = isset( $row['url'] ) ? esc_url_raw( trim( (string) $row['url'] ) ) : '';
		$label = isset( $row['label'] ) ? sanitize_text_field( $row['label'] ) : '';
		$rel   = isset( $row['rel'] ) ? themify_normalize_rel( $row['rel'] ) : '';

		// If no slug given but a label is, derive the slug from the label.
		if ( '' === $slug && '' !== $label ) {
			$slug = sanitize_title( $label );
		}

		// Drop wholly empty rows (no slug and no url).
		if ( '' === $slug && '' === $url ) {
			continue;
		}

		// A slug with no target is useless (would only 404) — skip it.
		if ( '' === $slug || '' === $url ) {
			continue;
		}

		// De-duplicate slugs; first row wins.
		if ( isset( $seen[ $slug ] ) ) {
			continue;
		}
		$seen[ $slug ] = true;

		$clean[] = array(
			'slug'   => $slug,
			'url'    => $url,
			'label'  => $label,
			'rel'    => '' !== $rel ? $rel : THEMIFY_AFFILIATE_DEFAULT_REL,
			// Merge in the prior click count for this slug; new links start at 0.
			'clicks' => isset( $existing_clicks[ $slug ] ) ? (int) $existing_clicks[ $slug ] : 0,
		);
	}

	themify_save_affiliate_links( $clean );

	// Scalar settings live in THEMIFY_OPT via the helpers.
	$base = isset( $_POST['affiliate_base'] ) ? sanitize_title( wp_unslash( $_POST['affiliate_base'] ) ) : '';
	if ( '' === $base ) {
		$base = 'go';
	}
	$auto_rel = ! empty( $_POST['affiliate_auto_rel'] ) ? '1' : '';

	themify_set_options( array(
		'affiliate_base'     => $base,
		'affiliate_auto_rel' => $auto_rel,
	) );

	// Register the (possibly new) base rule, then flush so it takes effect now.
	themify_affiliate_add_rewrite();
	flush_rewrite_rules();

	return true;
}

/**
 * Render one link row of the repeater.
 *
 * @param int|string $index Row index (numeric for real rows, '__INDEX__' for
 *                          the JS template).
 * @param array      $row   Link data (empty defaults for the template).
 */
function themify_affiliate_render_row( $index, array $row = array() ) {
	$row = wp_parse_args( $row, array(
		'slug'   => '',
		'url'    => '',
		'label'  => '',
		'rel'    => THEMIFY_AFFILIATE_DEFAULT_REL,
		'clicks' => 0,
	) );

	$base = 'themify_affiliate_links[' . $index . ']';

	echo '<div class="tf-repeater__row">';

	// Label.
	echo '<div class="tf-field tf-field--text" style="margin-bottom:0;">';
	printf( '<label class="tf-field__label">%s</label>', esc_html__( 'Label', 'themify' ) );
	printf(
		'<input type="text" name="%s[label]" value="%s" class="tf-input" placeholder="%s" />',
		esc_attr( $base ),
		esc_attr( $row['label'] ),
		esc_attr__( 'e.g. My favourite tool', 'themify' )
	);
	echo '</div>';

	// Slug.
	echo '<div class="tf-field tf-field--text" style="margin-bottom:0;">';
	printf( '<label class="tf-field__label">%s</label>', esc_html__( 'Slug', 'themify' ) );
	printf(
		'<input type="text" name="%s[slug]" value="%s" class="tf-input" placeholder="%s" />',
		esc_attr( $base ),
		esc_attr( $row['slug'] ),
		esc_attr__( 'e.g. best-widget (leave blank to derive from label)', 'themify' )
	);
	echo '</div>';

	// Target URL.
	echo '<div class="tf-field tf-field--url" style="margin-bottom:0;">';
	printf( '<label class="tf-field__label">%s</label>', esc_html__( 'Target URL', 'themify' ) );
	printf(
		'<input type="url" name="%s[url]" value="%s" class="tf-input" placeholder="%s" />',
		esc_attr( $base ),
		esc_attr( $row['url'] ),
		esc_attr__( 'https://example.com/affiliate-offer', 'themify' )
	);
	echo '</div>';

	// Rel text.
	echo '<div class="tf-field tf-field--text" style="margin-bottom:0;">';
	printf( '<label class="tf-field__label">%s</label>', esc_html__( 'Rel attributes', 'themify' ) );
	printf(
		'<input type="text" name="%s[rel]" value="%s" class="tf-input" placeholder="%s" />',
		esc_attr( $base ),
		esc_attr( $row['rel'] ),
		esc_attr( THEMIFY_AFFILIATE_DEFAULT_REL )
	);
	echo '<p class="tf-field__desc">' . esc_html__( 'Space-separated, e.g. nofollow sponsored noopener.', 'themify' ) . '</p>';
	echo '</div>';

	// Cloaked URL + click count (only meaningful once a slug exists).
	if ( '' !== $row['slug'] ) {
		$go = themify_go_url( $row['slug'] );
		echo '<div class="tf-field" style="margin-bottom:0;">';
		printf( '<label class="tf-field__label">%s</label>', esc_html__( 'Cloaked URL', 'themify' ) );
		echo '<div class="tf-actions" style="margin-bottom:0;">';
		printf(
			'<code style="background:#0d1f17;color:#d6efde;padding:6px 10px;border-radius:8px;font-size:0.82rem;word-break:break-all;">%s</code>',
			esc_html( $go )
		);
		printf(
			'<button type="button" class="button" data-tf-copy="%s">%s</button>',
			esc_attr( $go ),
			esc_html__( 'Copy', 'themify' )
		);
		printf(
			'<span class="tf-badge tf-badge--muted">%s</span>',
			esc_html(
				sprintf(
					/* translators: %s: number of clicks */
					_n( '%s click', '%s clicks', (int) $row['clicks'], 'themify' ),
					number_format_i18n( (int) $row['clicks'] )
				)
			)
		);
		echo '</div>';
		echo '</div>';
	}

	// Remove control.
	echo '<p style="margin:0;"><a href="#" class="tf-remove">' . esc_html__( 'Remove link', 'themify' ) . '</a></p>';

	echo '</div>'; // .tf-repeater__row
}

/**
 * Render the "Affiliate Links" admin screen.
 */
function themify_affiliate_page() {
	$saved = themify_affiliate_handle_save();

	themify_admin_header(
		__( 'Affiliate Links', 'themify' ),
		__( 'Create clean, on-domain links that redirect to your affiliate offers, track their clicks, and auto-nofollow outbound links in your posts.', 'themify' )
	);

	if ( $saved ) {
		echo '<div class="tf-notice tf-notice--info">' . esc_html__( 'Affiliate links saved.', 'themify' ) . '</div>';
	}

	$links     = themify_get_affiliate_links();
	$base      = themify_affiliate_base();
	$auto_rel  = themify_is_enabled( 'affiliate_auto_rel' );
	$total     = 0;
	foreach ( $links as $l ) {
		$total += (int) $l['clicks'];
	}

	// At-a-glance stats.
	echo '<div class="tf-stats">';
	printf(
		'<div class="tf-stat"><div class="tf-stat__num">%s</div><div class="tf-stat__label">%s</div></div>',
		esc_html( number_format_i18n( count( $links ) ) ),
		esc_html__( 'Links', 'themify' )
	);
	printf(
		'<div class="tf-stat"><div class="tf-stat__num">%s</div><div class="tf-stat__label">%s</div></div>',
		esc_html( number_format_i18n( $total ) ),
		esc_html__( 'Total clicks', 'themify' )
	);
	printf(
		'<div class="tf-stat"><div class="tf-stat__num">/%s/</div><div class="tf-stat__label">%s</div></div>',
		esc_html( $base ),
		esc_html__( 'Base path', 'themify' )
	);
	echo '</div>';

	echo '<form method="post" class="tf-form">';
	wp_nonce_field( 'themify_affiliate', 'themify_nonce' );

	/* ---- Settings card: base path + auto-rel ---- */
	echo '<div class="tf-card">';
	echo '<h2 class="tf-card__title">' . esc_html__( 'Settings', 'themify' ) . '</h2>';

	echo '<div class="tf-field tf-field--text">';
	printf( '<label class="tf-field__label" for="tf_affiliate_base">%s</label>', esc_html__( 'Cloak base path', 'themify' ) );
	printf(
		'<input type="text" id="tf_affiliate_base" name="affiliate_base" value="%s" class="tf-input" placeholder="go" />',
		esc_attr( $base )
	);
	echo '<p class="tf-field__desc">' . wp_kses(
		sprintf(
			/* translators: %s: example cloaked URL */
			__( 'Cloaked links look like %s. Changing this re-flushes permalinks automatically.', 'themify' ),
			'<code>' . esc_html( home_url( '/' . $base . '/your-slug/' ) ) . '</code>'
		),
		array( 'code' => array() )
	) . '</p>';
	echo '</div>';

	echo '<div class="tf-field tf-field--checkbox">';
	echo '<label class="tf-switch">';
	printf(
		'<input type="checkbox" name="affiliate_auto_rel" value="1" %s />',
		checked( $auto_rel, true, false )
	);
	echo '<span class="tf-switch__track"></span>';
	echo '<span class="tf-switch__label">' . esc_html__( 'Auto-add rel="nofollow sponsored noopener" + open external post links in a new tab', 'themify' ) . '</span>';
	echo '</label>';
	echo '<p class="tf-field__desc">' . esc_html__( 'Applies to outbound links in your post content only. Internal links, mailto: and anchors are left untouched.', 'themify' ) . '</p>';
	echo '</div>';

	echo '</div>'; // .tf-card

	/* ---- Links repeater ---- */
	echo '<div class="tf-card">';
	echo '<h2 class="tf-card__title">' . esc_html__( 'Links', 'themify' ) . '</h2>';
	echo '<p class="tf-card__desc">' . esc_html__( 'Each link gets an on-domain URL that 302-redirects to the target and counts clicks. Use it anywhere, or with the [themify_link] / [themify_button] shortcodes.', 'themify' ) . '</p>';

	echo '<div class="tf-repeater">';

	// Hidden template row — admin.js clones this and swaps __INDEX__ for the
	// next numeric index when "+ Add link" is clicked.
	echo '<script type="text/html" class="tf-repeater__template">';
	themify_affiliate_render_row( '__INDEX__' );
	echo '</script>';

	echo '<div class="tf-repeater__rows">';
	if ( $links ) {
		foreach ( $links as $i => $row ) {
			themify_affiliate_render_row( $i, $row );
		}
	}
	echo '</div>';

	echo '<p><button type="button" class="button tf-repeater__add">' . esc_html__( '+ Add link', 'themify' ) . '</button></p>';
	echo '</div>'; // .tf-repeater

	echo '</div>'; // .tf-card

	/* ---- Shortcode help ---- */
	echo '<div class="tf-card">';
	echo '<h2 class="tf-card__title">' . esc_html__( 'Shortcodes', 'themify' ) . '</h2>';
	echo '<ul style="margin:0 0 4px 18px; list-style:disc; color:#5a6b62; font-size:0.92rem;">';
	echo '<li>' . wp_kses_post( __( '<code>[themify_button url="best-widget" text="Buy now"]</code> — a styled button. Pass a link <em>slug</em> to cloak &amp; track it, or any URL.', 'themify' ) ) . '</li>';
	echo '<li>' . wp_kses_post( __( '<code>[themify_link slug="best-widget" text="my pick"]</code> — an inline cloaked link using the slug\'s stored rel.', 'themify' ) ) . '</li>';
	echo '</ul>';
	echo '</div>';

	echo '<p class="tf-form__actions"><button type="submit" class="button button-primary button-hero">' . esc_html__( 'Save Changes', 'themify' ) . '</button></p>';
	echo '</form>';

	themify_admin_footer();
}
