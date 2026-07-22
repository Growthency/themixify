<?php
/**
 * Auto-load next post — "infinite reading".
 *
 * When a reader scrolls to the end of an article the next post is fetched
 * and appended below it, magazine-style, so reading never stops. The queue
 * is the next-older posts; when the archive runs out it wraps around to the
 * newest posts so the loop never runs dry.
 *
 * Toggle + max count live under Themixify → General → Auto-load next post.
 * The front-end logic is in assets/js/main.js (themifyAutoload config).
 *
 * @package Themify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Build the list of post URLs to auto-load after the current article.
 *
 * @param int $max How many posts to queue.
 * @return string[] Permalinks, oldest-first reading order.
 */
function themify_autoload_next_queue( $max ) {
	$current = get_queried_object_id();
	$date    = get_post_field( 'post_date_gmt', $current );
	$urls    = array();
	$ids     = array( $current );

	// Posts published just before the current one — natural "keep reading".
	$older = get_posts( array(
		'post_type'      => 'post',
		'post_status'    => 'publish',
		'posts_per_page' => $max,
		'post__not_in'   => array( $current ),
		'date_query'     => array(
			array(
				'before' => $date,
				'column' => 'post_date_gmt',
			),
		),
		'orderby'        => 'date',
		'order'          => 'DESC',
		'fields'         => 'ids',
	) );
	foreach ( $older as $id ) {
		$ids[]  = $id;
		$urls[] = get_permalink( $id );
	}

	// Wrap around to the newest posts so the loop continues on old articles.
	if ( count( $urls ) < $max ) {
		$newest = get_posts( array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => $max - count( $urls ),
			'post__not_in'   => $ids,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'fields'         => 'ids',
		) );
		foreach ( $newest as $id ) {
			$urls[] = get_permalink( $id );
		}
	}

	return $urls;
}

/**
 * Print the front-end config on single posts when the feature is enabled.
 * Runs early in wp_footer so it precedes the deferred main.js.
 */
function themify_autoload_next_config() {
	if ( ! is_singular( 'post' ) || ! themify_is_enabled( 'autoload_next_enabled', true ) ) {
		return;
	}

	$max = (int) themify_get_option( 'autoload_next_max', 3 );
	$max = max( 1, min( 10, $max ) );

	$queue = themify_autoload_next_queue( $max );
	if ( ! $queue ) {
		return;
	}

	printf(
		'<script>window.themifyAutoload=%s;</script>' . "\n",
		wp_json_encode( array(
			'queue'   => array_map( 'esc_url_raw', $queue ),
			'divider' => __( 'Continue Reading', 'themify' ),
			'loading' => __( 'Loading next post…', 'themify' ),
		) )
	);
}
add_action( 'wp_footer', 'themify_autoload_next_config', 5 );
