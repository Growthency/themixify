<?php
/**
 * SEO — JSON-LD structured data.
 *
 * Emits a single <script type="application/ld+json"> in wp_head containing an
 * @graph of the site's structured data: an Organization + WebSite node on every
 * page, an Article/BlogPosting node on single posts, and a BreadcrumbList on
 * inner pages. Also registers a [themify_faq] shortcode that renders an
 * accessible <details> list AND contributes a FAQPage node for that post.
 *
 * The whole module no-ops when a major SEO plugin is active so we never emit
 * duplicate/competing schema. All output is JSON-encoded (never hand-built) and
 * escaped, and no external API is ever touched — this is pure page metadata.
 *
 * @package Themify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detect a major SEO plugin so the theme's own SEO output can stand down.
 *
 * Defined by the seo-meta module too; guarded here so this file is
 * self-sufficient regardless of module load order.
 *
 * @return bool
 */
if ( ! function_exists( 'themify_seo_plugin_active' ) ) {
	function themify_seo_plugin_active() {
		return defined( 'WPSEO_VERSION' )
			|| defined( 'RANK_MATH_VERSION' )
			|| defined( 'AIOSEO_VERSION' )
			|| class_exists( 'The_SEO_Framework\\Load' );
	}
}

/**
 * True when the theme should own the schema output (no rival SEO plugin, and
 * we're rendering a normal front-end request — not a feed or admin screen).
 *
 * @return bool
 */
function themify_schema_should_output() {
	if ( is_admin() || is_feed() || is_embed() ) {
		return false;
	}
	if ( function_exists( 'themify_seo_plugin_active' ) && themify_seo_plugin_active() ) {
		return false;
	}
	return true;
}

/**
 * Print the site-wide JSON-LD @graph in the document head.
 *
 * Runs early in wp_head (priority 2) so it sits near the other meta. Builds the
 * node list, lets modules filter it, then encodes the whole thing once.
 */
function themify_schema_output() {
	if ( ! themify_schema_should_output() ) {
		return;
	}

	$graph = array();

	$organization = themify_schema_organization();
	$graph[]      = $organization;
	$graph[]      = themify_schema_website();

	if ( is_singular( 'post' ) ) {
		$article = themify_schema_article();
		if ( $article ) {
			$graph[] = $article;
		}
	}

	// BreadcrumbList on every page except the front page.
	if ( ! is_front_page() && function_exists( 'themify_breadcrumb_items' ) ) {
		$breadcrumbs = themify_schema_breadcrumbs();
		if ( $breadcrumbs ) {
			$graph[] = $breadcrumbs;
		}
	}

	/**
	 * Filter the full list of JSON-LD nodes before they are emitted. Modules
	 * (e.g. the FAQ shortcode collector) can append their own graph nodes here.
	 *
	 * @param array $graph List of associative arrays, each a schema.org node.
	 */
	$graph = apply_filters( 'themify_schema_graph', $graph );

	$graph = array_values( array_filter( (array) $graph ) );
	if ( empty( $graph ) ) {
		return;
	}

	$document = array(
		'@context' => 'https://schema.org',
		'@graph'   => $graph,
	);

	echo "\n" . themify_schema_script_tag( $document ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- Output built by wp_json_encode + esc_attr wrapper in themify_schema_script_tag().
}
add_action( 'wp_head', 'themify_schema_output', 2 );

/**
 * Wrap an encoded structure in a ready-to-print application/ld+json script tag.
 *
 * @param array $data Structure to encode.
 * @return string Complete <script> element, or '' when encoding fails.
 */
function themify_schema_script_tag( $data ) {
	$json = wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	if ( false === $json ) {
		return '';
	}
	// Neutralise any "</script" that could appear inside encoded strings so the
	// tag can never be broken out of. JSON stays valid ("<\/script").
	$json = str_replace( '</', '<\/', $json );
	return '<script type="application/ld+json">' . $json . '</script>';
}

/**
 * Build the Organization node (@id …#organization).
 *
 * @return array
 */
function themify_schema_organization() {
	$home = themify_site_url();
	$node = array(
		'@type' => 'Organization',
		'@id'   => $home . '/#organization',
		'name'  => get_bloginfo( 'name' ),
		'url'   => trailingslashit( home_url( '/' ) ),
	);

	// Custom logo → ImageObject when one is set.
	$logo_id = (int) get_theme_mod( 'custom_logo' );
	if ( $logo_id ) {
		$logo = wp_get_attachment_image_src( $logo_id, 'full' );
		if ( is_array( $logo ) && ! empty( $logo[0] ) ) {
			$node['logo'] = array(
				'@type'  => 'ImageObject',
				'@id'    => $home . '/#logo',
				'url'    => $logo[0],
				'width'  => (int) ( $logo[1] ?? 0 ),
				'height' => (int) ( $logo[2] ?? 0 ),
			);
			$node['image'] = array( '@id' => $home . '/#logo' );
		}
	}

	// sameAs from the social profiles option: array of ['network','url'].
	$same_as = themify_schema_social_urls();
	if ( ! empty( $same_as ) ) {
		$node['sameAs'] = $same_as;
	}

	return $node;
}

/**
 * Collect valid social profile URLs from the themify_social option.
 *
 * The option is stored as a list of rows, each ['network' => .., 'url' => ..].
 * We only need the URLs (schema.org sameAs) and drop anything unparseable.
 *
 * @return array List of URL strings.
 */
function themify_schema_social_urls() {
	$rows = get_option( 'themify_social', array() );
	if ( ! is_array( $rows ) ) {
		return array();
	}
	$urls = array();
	foreach ( $rows as $row ) {
		if ( is_array( $row ) ) {
			$url = isset( $row['url'] ) ? $row['url'] : '';
		} else {
			// Tolerate a plain list of URLs too.
			$url = $row;
		}
		$url = esc_url_raw( (string) $url );
		if ( $url && ! in_array( $url, $urls, true ) ) {
			$urls[] = $url;
		}
	}
	return $urls;
}

/**
 * Build the WebSite node (@id …#website) with a SearchAction.
 *
 * @return array
 */
function themify_schema_website() {
	$home = themify_site_url();
	$node = array(
		'@type'           => 'WebSite',
		'@id'             => $home . '/#website',
		'url'             => trailingslashit( home_url( '/' ) ),
		'name'            => get_bloginfo( 'name' ),
		'publisher'       => array( '@id' => $home . '/#organization' ),
		'inLanguage'      => get_bloginfo( 'language' ),
		'potentialAction' => array(
			array(
				'@type'       => 'SearchAction',
				'target'      => array(
					'@type'       => 'EntryPoint',
					'urlTemplate' => home_url( '/?s={search_term_string}' ),
				),
				'query-input' => 'required name=search_term_string',
			),
		),
	);

	$description = get_bloginfo( 'description' );
	if ( $description ) {
		$node['description'] = $description;
	}

	return $node;
}

/**
 * Build the Article / BlogPosting node for the current single post.
 *
 * @return array|null Node array, or null when not on a valid post.
 */
function themify_schema_article() {
	$post = get_queried_object();
	if ( ! $post instanceof WP_Post ) {
		return null;
	}

	$home    = themify_site_url();
	$post_id = $post->ID;
	$url     = get_permalink( $post_id );

	// Prefer the SEO module's computed title; fall back to the plain title.
	if ( function_exists( 'themify_get_seo_title' ) ) {
		$headline = themify_get_seo_title();
	} else {
		$headline = get_the_title( $post_id );
	}
	$headline = wp_strip_all_tags( (string) $headline );

	$node = array(
		'@type'            => 'BlogPosting',
		'@id'              => $url . '#article',
		'isPartOf'         => array( '@id' => $home . '/#website' ),
		'headline'         => $headline,
		'datePublished'    => get_the_date( DATE_W3C, $post_id ),
		'dateModified'     => get_the_modified_date( DATE_W3C, $post_id ),
		'author'           => themify_schema_author( $post ),
		'publisher'        => array( '@id' => $home . '/#organization' ),
		'mainEntityOfPage' => array(
			'@type' => 'WebPage',
			'@id'   => $url,
		),
		'url'              => $url,
		'inLanguage'       => get_bloginfo( 'language' ),
	);

	// Description / excerpt.
	$excerpt = has_excerpt( $post_id ) ? get_the_excerpt( $post_id ) : '';
	if ( ! $excerpt ) {
		$excerpt = wp_trim_words( wp_strip_all_tags( (string) $post->post_content ), 55, '' );
	}
	$excerpt = trim( wp_strip_all_tags( (string) $excerpt ) );
	if ( $excerpt ) {
		$node['description'] = $excerpt;
	}

	// Primary image: reuse the OG image resolver when available.
	$image = function_exists( 'themify_get_og_image' ) ? themify_get_og_image() : '';
	if ( ! $image && has_post_thumbnail( $post_id ) ) {
		$image = get_the_post_thumbnail_url( $post_id, 'full' );
	}
	$image = esc_url_raw( (string) $image );
	if ( $image ) {
		$node['image'] = array(
			'@type' => 'ImageObject',
			'url'   => $image,
		);
		$dims = themify_schema_image_dimensions( $post_id );
		if ( $dims ) {
			$node['image'] = array_merge( $node['image'], $dims );
		}
	}

	// Word count from the raw content.
	$word_count = str_word_count( wp_strip_all_tags( strip_shortcodes( (string) $post->post_content ) ) );
	if ( $word_count > 0 ) {
		$node['wordCount'] = $word_count;
	}

	// Primary category → articleSection.
	$cats = get_the_category( $post_id );
	if ( ! empty( $cats ) && isset( $cats[0]->name ) ) {
		$node['articleSection'] = $cats[0]->name;
	}

	// Comment count.
	$comments = (int) get_comments_number( $post_id );
	if ( $comments > 0 ) {
		$node['commentCount'] = $comments;
	}

	return $node;
}

/**
 * Best-effort width/height for the featured image, for the Article image node.
 *
 * @param int $post_id Post ID.
 * @return array Empty, or ['width'=>int,'height'=>int].
 */
function themify_schema_image_dimensions( $post_id ) {
	$thumb_id = get_post_thumbnail_id( $post_id );
	if ( ! $thumb_id ) {
		return array();
	}
	$src = wp_get_attachment_image_src( $thumb_id, 'full' );
	if ( is_array( $src ) && ! empty( $src[1] ) && ! empty( $src[2] ) ) {
		return array(
			'width'  => (int) $src[1],
			'height' => (int) $src[2],
		);
	}
	return array();
}

/**
 * Build the Person node for a post's author.
 *
 * @param WP_Post $post Post object.
 * @return array
 */
function themify_schema_author( $post ) {
	$author_id = (int) $post->post_author;
	$home      = themify_site_url();
	$node      = array(
		'@type' => 'Person',
		'@id'   => $home . '/#/author/' . $author_id,
		'name'  => get_the_author_meta( 'display_name', $author_id ),
	);
	$author_url = get_author_posts_url( $author_id );
	if ( $author_url ) {
		$node['url'] = $author_url;
	}
	$profile = get_the_author_meta( 'user_url', $author_id );
	if ( $profile ) {
		$node['sameAs'] = array( esc_url_raw( $profile ) );
	}
	return $node;
}

/**
 * Build the BreadcrumbList node from the shared breadcrumb trail.
 *
 * @return array|null
 */
function themify_schema_breadcrumbs() {
	$items = themify_breadcrumb_items();
	if ( ! is_array( $items ) || count( $items ) < 2 ) {
		return null;
	}

	$elements = array();
	$position = 1;
	foreach ( $items as $item ) {
		$name = isset( $item['name'] ) ? wp_strip_all_tags( (string) $item['name'] ) : '';
		if ( '' === $name ) {
			continue;
		}
		$element = array(
			'@type'    => 'ListItem',
			'position' => $position,
			'name'     => $name,
		);
		// The last crumb (current page) intentionally omits an item URL per
		// Google's guidance; earlier crumbs link out.
		if ( ! empty( $item['url'] ) ) {
			$element['item'] = esc_url_raw( (string) $item['url'] );
		}
		$elements[] = $element;
		++$position;
	}

	if ( count( $elements ) < 2 ) {
		return null;
	}

	return array(
		'@type'           => 'BreadcrumbList',
		'@id'             => get_permalink() ? get_permalink() . '#breadcrumb' : themify_site_url() . '/#breadcrumb',
		'itemListElement' => $elements,
	);
}

/* -------------------------------------------------------------------------
 * [themify_faq] shortcode — accessible <details> list + FAQPage schema.
 * ---------------------------------------------------------------------- */

/**
 * Accumulate the FAQ items parsed out of [themify_faq] on the current request
 * so a single FAQPage node can be added to the graph in wp_head/footer.
 *
 * @param array|null $add Optional pair ['q'=>.., 'a'=>..] to record.
 * @return array The full list collected so far.
 */
function themify_faq_store( $add = null ) {
	static $items = array();
	if ( is_array( $add ) && isset( $add['q'], $add['a'] ) ) {
		$items[] = $add;
	}
	return $items;
}

/**
 * Parse the raw shortcode body into an ordered list of Q/A pairs.
 *
 * Two authoring formats are supported (mixable):
 *   1. Nested shortcodes:  [q]Question?[/q][a]Answer.[/a]
 *   2. Line prefixes:      lines beginning "Q:" start a question, "A:" its
 *      answer (answer lines may continue until the next Q:).
 *
 * @param string $content Raw inner content of [themify_faq].
 * @return array List of ['q'=>string html-question, 'a'=>string html-answer].
 */
function themify_faq_parse( $content ) {
	$content = (string) $content;
	$pairs   = array();

	// Format 1: nested [q]/[a] blocks, taken in document order.
	if ( preg_match_all( '/\[q\](.*?)\[\/q\]\s*\[a\](.*?)\[\/a\]/is', $content, $m, PREG_SET_ORDER ) ) {
		foreach ( $m as $match ) {
			$q = trim( $match[1] );
			$a = trim( $match[2] );
			if ( '' !== $q && '' !== $a ) {
				$pairs[] = array( 'q' => $q, 'a' => $a );
			}
		}
		if ( ! empty( $pairs ) ) {
			return $pairs;
		}
	}

	// Format 2: "Q:" / "A:" line prefixes.
	$lines = preg_split( '/\r\n|\r|\n/', $content );
	$q     = null;
	$a     = array();
	$flush = function () use ( &$q, &$a, &$pairs ) {
		if ( null !== $q ) {
			$answer = trim( implode( "\n", $a ) );
			$ques   = trim( $q );
			if ( '' !== $ques && '' !== $answer ) {
				$pairs[] = array( 'q' => $ques, 'a' => $answer );
			}
		}
		$q = null;
		$a = array();
	};

	foreach ( (array) $lines as $line ) {
		if ( preg_match( '/^\s*Q\s*[:.\-]\s*(.*)$/i', $line, $qm ) ) {
			$flush();
			$q = $qm[1];
		} elseif ( preg_match( '/^\s*A\s*[:.\-]\s*(.*)$/i', $line, $am ) ) {
			$a[] = $am[1];
		} elseif ( null !== $q ) {
			// Continuation line for the current answer.
			$a[] = $line;
		}
	}
	$flush();

	return $pairs;
}

/**
 * Render [themify_faq] as an accessible disclosure list and record its items
 * for the FAQPage schema node.
 *
 * @param array       $atts    Shortcode attributes (title).
 * @param string|null $content Inner content.
 * @return string HTML.
 */
function themify_faq_shortcode( $atts, $content = null ) {
	$atts = shortcode_atts(
		array(
			'title' => '',
		),
		$atts,
		'themify_faq'
	);

	$pairs = themify_faq_parse( $content );
	if ( empty( $pairs ) ) {
		return '';
	}

	$out = '<div class="tf-faq">';
	if ( '' !== $atts['title'] ) {
		$out .= '<h2 class="tf-faq__title">' . esc_html( $atts['title'] ) . '</h2>';
	}

	foreach ( $pairs as $pair ) {
		// Questions are plain text; answers may contain inline markup.
		$question    = wp_strip_all_tags( $pair['q'] );
		$answer_html = wp_kses_post( wpautop( do_shortcode( $pair['a'] ) ) );

		$out .= '<details class="tf-faq__item">';
		$out .= '<summary class="tf-faq__q">' . esc_html( $question ) . '</summary>';
		$out .= '<div class="tf-faq__a">' . $answer_html . '</div>';
		$out .= '</details>';

		// Record the plain-text Q/A for the schema node.
		themify_faq_store(
			array(
				'q' => $question,
				'a' => trim( wp_strip_all_tags( do_shortcode( $pair['a'] ) ) ),
			)
		);
	}

	$out .= '</div>';

	return $out;
}
add_shortcode( 'themify_faq', 'themify_faq_shortcode' );

/**
 * Append a FAQPage node to the schema graph when [themify_faq] produced items.
 *
 * We hook the graph filter rather than emitting a second script so the page
 * still carries exactly one JSON-LD block. Shortcodes for the main content run
 * during the_content (before wp_footer, and — because single.php renders the
 * body before get_footer — also before the late wp_head passes are done), so by
 * the time the graph is built for a footer-side emit the store is populated.
 *
 * To be robust regardless of when wp_head fired relative to the content, we
 * emit the FAQ node in wp_footer as its own tag when the head pass missed it.
 *
 * @param array $graph Existing graph nodes.
 * @return array
 */
function themify_faq_schema_graph( $graph ) {
	$node = themify_faq_schema_node();
	if ( $node ) {
		$graph[]                            = $node;
		$GLOBALS['themify_faq_schema_done'] = true;
	}
	return $graph;
}
add_filter( 'themify_schema_graph', 'themify_faq_schema_graph' );

/**
 * Build the FAQPage node from the collected Q/A pairs, or null when none.
 *
 * @return array|null
 */
function themify_faq_schema_node() {
	$items = themify_faq_store();
	if ( empty( $items ) ) {
		return null;
	}

	$questions = array();
	foreach ( $items as $item ) {
		if ( empty( $item['q'] ) || empty( $item['a'] ) ) {
			continue;
		}
		$questions[] = array(
			'@type'          => 'Question',
			'name'           => $item['q'],
			'acceptedAnswer' => array(
				'@type' => 'Answer',
				'text'  => $item['a'],
			),
		);
	}

	if ( empty( $questions ) ) {
		return null;
	}

	$id = is_singular() && get_permalink() ? get_permalink() . '#faq' : themify_site_url() . '/#faq';

	return array(
		'@type'      => 'FAQPage',
		'@id'        => $id,
		'mainEntity' => $questions,
	);
}

/**
 * Fallback emitter: on a page whose content contained [themify_faq] but where
 * the head-side graph was built before the content ran (so the FAQ node was
 * missed), print the FAQPage schema on its own in the footer. Only fires when
 * the graph filter didn't already include it.
 */
function themify_faq_schema_footer() {
	if ( ! themify_schema_should_output() ) {
		return;
	}
	if ( ! empty( $GLOBALS['themify_faq_schema_done'] ) ) {
		return; // Already emitted inside the head @graph.
	}
	$node = themify_faq_schema_node();
	if ( ! $node ) {
		return;
	}
	$document = array(
		'@context' => 'https://schema.org',
		'@graph'   => array( $node ),
	);
	echo "\n" . themify_schema_script_tag( $document ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- JSON-encoded + script-tag neutralised in themify_schema_script_tag().
}
add_action( 'wp_footer', 'themify_faq_schema_footer', 99 );
