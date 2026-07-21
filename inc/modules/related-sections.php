<?php
/**
 * Post-footer sections — the three blocks shown after single-post content:
 *
 *   1. "Related" — a compact grey box with three text links (title, date and
 *      the category it lives in), like the classic inline related-posts box.
 *   2. Previous / Next — two-column navigation to the adjacent posts.
 *   3. "Similar Posts" — a card carousel (image, title, author • date) with
 *      arrow buttons and dots, two cards per view (one on mobile).
 *
 * Each section has its own on/off toggle under Themixify → General → Reading
 * (related_inline_enabled, post_nav_enabled, similar_posts_enabled — all on
 * by default). single.php calls the three render functions in order; each
 * silently renders nothing when toggled off or when there is nothing to show.
 *
 * No external HTTP — everything is local WordPress content.
 *
 * @package Themify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Related posts for the current post with a recent-posts fallback, so the
 * sections still render on posts without shared categories.
 *
 * @param int $count How many posts.
 * @return WP_Post[]
 */
function themify_related_or_recent( $count = 12 ) {
	$count = max( 1, (int) $count );

	$related = function_exists( 'themify_related_posts' ) ? themify_related_posts( $count ) : array();
	$related = is_array( $related ) ? $related : array();

	if ( count( $related ) >= $count ) {
		return $related;
	}

	// Top up with recent posts (excluding the current one and any already picked).
	$exclude = array( get_the_ID() );
	foreach ( $related as $r ) {
		$exclude[] = is_object( $r ) ? (int) $r->ID : (int) $r;
	}
	$recent = get_posts( array(
		'post_type'           => 'post',
		'post_status'         => 'publish',
		'posts_per_page'      => $count - count( $related ),
		'post__not_in'        => array_filter( $exclude ),
		'ignore_sticky_posts' => true,
	) );

	return array_merge( $related, $recent );
}

/**
 * Section 1 — the compact "Related" text-links box.
 */
function themify_render_related_inline() {
	if ( ! themify_is_enabled( 'related_inline_enabled', true ) ) {
		return;
	}

	$posts = themify_related_or_recent( 3 );
	if ( empty( $posts ) ) {
		return;
	}

	echo '<aside class="tf-relbox" aria-label="' . esc_attr__( 'Related', 'themify' ) . '">';
	echo '<h4 class="tf-relbox__heading">' . esc_html__( 'Related', 'themify' ) . '</h4>';
	echo '<div class="tf-relbox__grid">';
	foreach ( array_slice( $posts, 0, 3 ) as $rel ) {
		$cats = get_the_category( $rel->ID );
		$ctx  = ( ! empty( $cats ) && ! is_wp_error( $cats ) )
			/* translators: %s: category name */
			? sprintf( __( 'In "%s"', 'themify' ), $cats[0]->name )
			: __( 'Similar post', 'themify' );

		echo '<div class="tf-relbox__item">';
		printf(
			'<a class="tf-relbox__link" href="%s">%s</a>',
			esc_url( get_permalink( $rel ) ),
			esc_html( get_the_title( $rel ) )
		);
		echo '<span class="tf-relbox__date">' . esc_html( get_the_date( '', $rel ) ) . '</span>';
		echo '<span class="tf-relbox__ctx">' . esc_html( $ctx ) . '</span>';
		echo '</div>';
	}
	echo '</div>';
	echo '</aside>';
}

/**
 * Section 2 — previous / next post navigation.
 */
function themify_render_post_nav() {
	if ( ! themify_is_enabled( 'post_nav_enabled', true ) ) {
		return;
	}

	$prev = get_previous_post();
	$next = get_next_post();
	if ( ! $prev && ! $next ) {
		return;
	}

	echo '<nav class="tf-postnav" aria-label="' . esc_attr__( 'Posts', 'themify' ) . '">';

	if ( $prev ) {
		printf(
			'<a class="tf-postnav__item tf-postnav__item--prev" href="%s"><span class="tf-postnav__label">&#8592; %s</span><span class="tf-postnav__title">%s</span></a>',
			esc_url( get_permalink( $prev ) ),
			esc_html__( 'Previous', 'themify' ),
			esc_html( get_the_title( $prev ) )
		);
	} else {
		echo '<span class="tf-postnav__item" aria-hidden="true"></span>';
	}

	if ( $next ) {
		printf(
			'<a class="tf-postnav__item tf-postnav__item--next" href="%s"><span class="tf-postnav__label">%s &#8594;</span><span class="tf-postnav__title">%s</span></a>',
			esc_url( get_permalink( $next ) ),
			esc_html__( 'Next', 'themify' ),
			esc_html( get_the_title( $next ) )
		);
	} else {
		echo '<span class="tf-postnav__item" aria-hidden="true"></span>';
	}

	echo '</nav>';
}

/**
 * Section 3 — the "Similar Posts" card carousel.
 */
function themify_render_similar_posts() {
	if ( ! themify_is_enabled( 'similar_posts_enabled', true ) ) {
		return;
	}

	$posts = themify_related_or_recent( 12 );
	if ( empty( $posts ) ) {
		return;
	}

	echo '<section class="tf-similar" aria-label="' . esc_attr__( 'Similar posts', 'themify' ) . '">';
	echo '<h2 class="tf-similar__heading">' . esc_html__( 'Similar Posts', 'themify' ) . '</h2>';
	echo '<div class="tf-similar__wrap">';
	echo '<button type="button" class="tf-similar__arrow tf-similar__arrow--prev" aria-label="' . esc_attr__( 'Previous slide', 'themify' ) . '">&#8249;</button>';
	echo '<div class="tf-similar__viewport">';
	echo '<div class="tf-similar__track">';

	foreach ( $posts as $sim ) {
		$author = get_the_author_meta( 'display_name', (int) $sim->post_author );
		echo '<article class="tf-similar__card">';
		if ( has_post_thumbnail( $sim ) ) {
			printf(
				'<a class="tf-similar__thumb" href="%s" tabindex="-1" aria-label="%s">%s</a>',
				esc_url( get_permalink( $sim ) ),
				esc_attr( get_the_title( $sim ) ),
				get_the_post_thumbnail( $sim, 'themify-card', array( 'loading' => 'lazy', 'alt' => '' ) ) // phpcs:ignore WordPress.Security.EscapeOutput -- core markup.
			);
		}
		echo '<div class="tf-similar__body">';
		printf(
			'<h3 class="tf-similar__title"><a href="%s">%s</a></h3>',
			esc_url( get_permalink( $sim ) ),
			esc_html( get_the_title( $sim ) )
		);
		echo '<p class="tf-similar__meta">';
		if ( $author ) {
			/* translators: %s: author name */
			echo esc_html( sprintf( __( 'By %s', 'themify' ), $author ) ) . ' <span aria-hidden="true">&#8226;</span> ';
		}
		echo esc_html( get_the_date( '', $sim ) );
		echo '</p>';
		echo '</div>';
		echo '</article>';
	}

	echo '</div>'; // .tf-similar__track
	echo '</div>'; // .tf-similar__viewport
	echo '<button type="button" class="tf-similar__arrow tf-similar__arrow--next" aria-label="' . esc_attr__( 'Next slide', 'themify' ) . '">&#8250;</button>';
	echo '</div>'; // .tf-similar__wrap
	echo '<div class="tf-similar__dots" role="tablist"></div>';
	echo '</section>';
}
