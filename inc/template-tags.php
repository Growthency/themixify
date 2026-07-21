<?php
/**
 * Template tags — reusable output helpers for the front-end templates.
 *
 * Templates (header.php, single.php, content-*.php …) call these so markup
 * stays consistent and DRY. All functions are prefixed themify_ and echo
 * their output unless the name says otherwise.
 *
 * @package Themify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Estimated reading time for the current (or given) post.
 *
 * @param int $post_id Optional post ID.
 * @return int Minutes (minimum 1).
 */
function themify_reading_time( $post_id = 0 ) {
	$post_id = $post_id ? $post_id : get_the_ID();
	$content = get_post_field( 'post_content', $post_id );
	$words   = str_word_count( wp_strip_all_tags( (string) $content ) );
	$wpm     = (int) apply_filters( 'themify_words_per_minute', 220 );
	return max( 1, (int) ceil( $words / max( 1, $wpm ) ) );
}

/**
 * The compact post meta line: date · reading time · author.
 */
function themify_entry_meta() {
	$parts = array();

	$parts[] = sprintf(
		'<time class="tf-meta__date" datetime="%s">%s</time>',
		esc_attr( get_the_date( 'c' ) ),
		esc_html( get_the_date() )
	);

	$parts[] = sprintf(
		/* translators: %d: minutes */
		'<span class="tf-meta__read">%s</span>',
		esc_html( sprintf( _n( '%d min read', '%d min read', themify_reading_time(), 'themify' ), themify_reading_time() ) )
	);

	if ( themify_is_enabled( 'show_author', true ) ) {
		$parts[] = sprintf(
			'<span class="tf-meta__author">%s <a href="%s" rel="author">%s</a></span>',
			esc_html__( 'by', 'themify' ),
			esc_url( get_author_posts_url( get_the_author_meta( 'ID' ) ) ),
			esc_html( get_the_author() )
		);
	}

	echo '<div class="tf-meta">' . implode( '<span class="tf-meta__sep">·</span>', $parts ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
}

/**
 * Category pills for the current post.
 */
function themify_category_pills() {
	$cats = get_the_category();
	if ( empty( $cats ) ) {
		return;
	}
	echo '<div class="tf-pills">';
	foreach ( $cats as $cat ) {
		printf(
			'<a class="tf-pill" href="%s">%s</a>',
			esc_url( get_category_link( $cat->term_id ) ),
			esc_html( $cat->name )
		);
	}
	echo '</div>';
}

/**
 * Accessible numbered pagination for archives.
 */
function themify_pagination() {
	the_posts_pagination( array(
		'mid_size'           => 1,
		'prev_text'          => __( '‹ Prev', 'themify' ),
		'next_text'          => __( 'Next ›', 'themify' ),
		'screen_reader_text' => __( 'Posts navigation', 'themify' ),
		'class'              => 'tf-pagination',
	) );
}

/**
 * Breadcrumb trail with BreadcrumbList JSON-LD emitted alongside it (the SEO
 * schema module reuses themify_breadcrumb_items()).
 */
function themify_breadcrumbs() {
	if ( is_front_page() ) {
		return;
	}
	$items = themify_breadcrumb_items();
	if ( count( $items ) < 2 ) {
		return;
	}
	echo '<nav class="tf-breadcrumbs" aria-label="' . esc_attr__( 'Breadcrumb', 'themify' ) . '"><ol>';
	$last = count( $items ) - 1;
	foreach ( $items as $i => $item ) {
		if ( $i === $last ) {
			printf( '<li class="is-current" aria-current="page">%s</li>', esc_html( $item['name'] ) );
		} else {
			printf( '<li><a href="%s">%s</a></li>', esc_url( $item['url'] ), esc_html( $item['name'] ) );
		}
	}
	echo '</ol></nav>';
}

/**
 * Build the breadcrumb trail as an array of ['name'=>, 'url'=>] — shared by
 * the visual breadcrumb and the JSON-LD schema so they never drift apart.
 *
 * @return array
 */
function themify_breadcrumb_items() {
	$items = array( array( 'name' => __( 'Home', 'themify' ), 'url' => home_url( '/' ) ) );

	if ( is_singular( 'post' ) ) {
		$cats = get_the_category();
		if ( ! empty( $cats ) ) {
			$primary = $cats[0];
			$items[] = array( 'name' => $primary->name, 'url' => get_category_link( $primary->term_id ) );
		}
		$items[] = array( 'name' => get_the_title(), 'url' => get_permalink() );
	} elseif ( is_page() ) {
		$ancestors = array_reverse( get_post_ancestors( get_the_ID() ) );
		foreach ( $ancestors as $anc ) {
			$items[] = array( 'name' => get_the_title( $anc ), 'url' => get_permalink( $anc ) );
		}
		$items[] = array( 'name' => get_the_title(), 'url' => get_permalink() );
	} elseif ( is_category() || is_tag() || is_tax() ) {
		$items[] = array( 'name' => single_term_title( '', false ), 'url' => '' );
	} elseif ( is_author() ) {
		$themify_author = get_queried_object();
		$themify_aname  = ( $themify_author instanceof WP_User ) ? $themify_author->display_name : get_the_author();
		$items[] = array(
			/* translators: %s: author name */
			'name' => sprintf( __( 'Author: %s', 'themify' ), $themify_aname ),
			'url'  => '',
		);
	} elseif ( is_search() ) {
		$items[] = array( 'name' => sprintf( __( 'Search: %s', 'themify' ), get_search_query() ), 'url' => '' );
	} elseif ( is_archive() ) {
		// get_the_archive_title() wraps the label in a <span>; the breadcrumb is
		// escaped on output, so strip the markup to avoid literal tags showing.
		$items[] = array( 'name' => wp_strip_all_tags( get_the_archive_title() ), 'url' => '' );
	}
	return $items;
}

/**
 * Build a table of contents from the post's H2/H3 headings AND inject matching
 * id attributes into the content. Returns '' when there aren't enough headings.
 *
 * @param string $content Post content HTML.
 * @param int    $min     Minimum headings required to show a TOC.
 * @return array {@type string $content Modified content, @type string $toc TOC HTML}
 */
function themify_build_toc( $content, $min = 3 ) {
	if ( ! preg_match_all( '/<h([23])\b([^>]*)>(.*?)<\/h\1>/is', $content, $m, PREG_SET_ORDER ) ) {
		return array( 'content' => $content, 'toc' => '' );
	}
	if ( count( $m ) < $min ) {
		return array( 'content' => $content, 'toc' => '' );
	}

	$toc_items = array();
	$used      = array();
	foreach ( $m as $heading ) {
		$level = (int) $heading[1];
		$attrs = $heading[2];
		$text  = trim( wp_strip_all_tags( $heading[3] ) );
		if ( '' === $text ) {
			continue;
		}
		// Reuse an existing id if present, else derive a unique slug.
		if ( preg_match( '/\bid=("|\')([^"\']+)\1/i', $attrs, $idm ) ) {
			$id = $idm[2];
		} else {
			$base = sanitize_title( $text );
			$id   = $base;
			$n    = 2;
			while ( isset( $used[ $id ] ) ) {
				$id = $base . '-' . $n++;
			}
			// Inject the id into this heading occurrence in the content.
			$content = preg_replace(
				'/<h' . $level . '\b([^>]*)>' . preg_quote( $heading[3], '/' ) . '<\/h' . $level . '>/is',
				'<h' . $level . '$1 id="' . $id . '">' . $heading[3] . '</h' . $level . '>',
				$content,
				1
			);
		}
		$used[ $id ] = true;
		$toc_items[] = array( 'level' => $level, 'id' => $id, 'text' => $text );
	}

	if ( count( $toc_items ) < $min ) {
		return array( 'content' => $content, 'toc' => '' );
	}

	$toc  = '<nav class="tf-toc" aria-label="' . esc_attr__( 'Table of contents', 'themify' ) . '">';
	$toc .= '<button class="tf-toc__toggle" aria-expanded="true"><span class="tf-toc__label">' . esc_html__( 'Table of Contents', 'themify' ) . '</span><span class="tf-toc__caret" aria-hidden="true"></span></button><ol>';
	foreach ( $toc_items as $item ) {
		$toc .= sprintf(
			'<li class="tf-toc__l%d"><a href="#%s">%s</a></li>',
			$item['level'],
			esc_attr( $item['id'] ),
			esc_html( $item['text'] )
		);
	}
	$toc .= '</ol></nav>';

	return array( 'content' => $content, 'toc' => $toc );
}

/**
 * Related posts by shared category/tag, newest first.
 *
 * @param int $limit How many to show.
 * @return WP_Post[]
 */
function themify_related_posts( $limit = 3 ) {
	$post_id = get_the_ID();
	$cats    = wp_get_post_categories( $post_id );
	$tags    = wp_get_post_tags( $post_id, array( 'fields' => 'ids' ) );

	$args = array(
		'post__not_in'        => array( $post_id ),
		'posts_per_page'      => $limit,
		'ignore_sticky_posts' => 1,
		'no_found_rows'       => true,
		'orderby'             => 'date',
	);
	if ( $cats || $tags ) {
		$args['tax_query'] = array( 'relation' => 'OR' );
		if ( $cats ) {
			$args['tax_query'][] = array( 'taxonomy' => 'category', 'terms' => $cats );
		}
		if ( $tags ) {
			$args['tax_query'][] = array( 'taxonomy' => 'post_tag', 'terms' => $tags );
		}
	}
	$q = new WP_Query( $args );
	return $q->posts;
}

/**
 * Post view counter helpers (stored in post meta). Counting is triggered from
 * single.php; the count is shown in meta and used for "popular posts".
 *
 * @param int $post_id Post ID.
 */
function themify_register_view( $post_id ) {
	if ( ! $post_id || is_admin() ) {
		return;
	}
	$key   = '_themify_views';
	$count = (int) get_post_meta( $post_id, $key, true );
	update_post_meta( $post_id, $key, $count + 1 );
}

/**
 * Read the view count.
 *
 * @param int $post_id Post ID.
 * @return int
 */
function themify_get_views( $post_id = 0 ) {
	$post_id = $post_id ? $post_id : get_the_ID();
	return (int) get_post_meta( $post_id, '_themify_views', true );
}
