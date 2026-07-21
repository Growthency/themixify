<?php
/**
 * Sticky post bar — a slim, fixed strip of 10 posts (thumbnail + title) that
 * slides down from the top of the viewport once the visitor scrolls a little,
 * like the discovery bars on big content sites.
 *
 *   - Posts: the site's most-viewed articles (the theme's own _themify_views
 *     counter), topped up with recent posts; the post being read is excluded.
 *   - Appears after ~350px of scrolling (handled in main.js) and hides again
 *     at the top, so it never covers the real header on first paint.
 *   - Toggle: `sticky_postbar_enabled` under Themixify → General → Reading
 *     (on by default). When off, no markup is printed at all.
 *
 * No external HTTP — pure local WordPress content, rendered on wp_footer.
 *
 * @package Themify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The posts shown in the bar: most-viewed first, recent as top-up.
 *
 * @param int $count How many posts.
 * @return WP_Post[]
 */
function themify_postbar_posts( $count = 10 ) {
	$count   = max( 1, (int) $count );
	$exclude = is_singular( 'post' ) ? array( get_the_ID() ) : array();

	$popular = get_posts( array(
		'post_type'           => 'post',
		'post_status'         => 'publish',
		'posts_per_page'      => $count,
		'post__not_in'        => $exclude,
		'meta_key'            => '_themify_views', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		'orderby'             => 'meta_value_num',
		'order'               => 'DESC',
		'ignore_sticky_posts' => true,
	) );

	if ( count( $popular ) >= $count ) {
		return $popular;
	}

	foreach ( $popular as $p ) {
		$exclude[] = (int) $p->ID;
	}
	$recent = get_posts( array(
		'post_type'           => 'post',
		'post_status'         => 'publish',
		'posts_per_page'      => $count - count( $popular ),
		'post__not_in'        => array_filter( $exclude ),
		'ignore_sticky_posts' => true,
	) );

	return array_merge( $popular, $recent );
}

/**
 * Print the sticky bar markup in the footer (front-end only). main.js shows
 * and hides it on scroll via the .is-visible class.
 */
function themify_postbar_render() {
	if ( is_admin() || is_feed() || is_robots() || is_trackback() ) {
		return;
	}
	if ( ! themify_is_enabled( 'sticky_postbar_enabled', true ) ) {
		return;
	}

	$posts = themify_postbar_posts( 10 );
	if ( empty( $posts ) ) {
		return;
	}

	echo '<div class="tf-postbar" aria-label="' . esc_attr__( 'Trending posts', 'themify' ) . '">';
	echo '<div class="tf-postbar__track">';
	foreach ( $posts as $post_item ) {
		$title = get_the_title( $post_item );
		$short = function_exists( 'mb_strlen' ) && mb_strlen( $title ) > 34
			? mb_substr( $title, 0, 33 ) . '…'
			: ( strlen( $title ) > 34 ? substr( $title, 0, 33 ) . '…' : $title );

		echo '<a class="tf-postbar__item" href="' . esc_url( get_permalink( $post_item ) ) . '" title="' . esc_attr( $title ) . '">';
		if ( has_post_thumbnail( $post_item ) ) {
			echo get_the_post_thumbnail( $post_item, 'thumbnail', array( 'loading' => 'lazy', 'alt' => '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- core markup.
		}
		echo '<span class="tf-postbar__title">' . esc_html( $short ) . '</span>';
		echo '</a>';
	}
	echo '</div>';
	echo '</div>';
}
add_action( 'wp_footer', 'themify_postbar_render' );
