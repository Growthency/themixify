<?php
/**
 * Built-in article sidebar.
 *
 * Gives single posts a rich, zero-config sidebar (like the reference design):
 * a search box, an author card, a "Popular posts" list and a "Recent posts"
 * list — each with thumbnails — followed by any widgets the user added to the
 * Blog Sidebar area. Everything is driven from local content; nothing external.
 *
 * single.php calls themify_render_post_sidebar(); the layout column comes from
 * the tf-has-sidebar body class (see setup.php). All colours come from --tf-*.
 *
 * @package Themify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* -------------------------------------------------------------------------
 * DATA
 * ---------------------------------------------------------------------- */

/**
 * Popular posts by view count (the theme's own _themify_views meta), topped up
 * with recent posts when a young site hasn't accumulated enough views yet, so
 * the widget is never awkwardly short. The current post is excluded.
 *
 * @param int $number How many to return.
 * @return WP_Post[]
 */
function themify_get_popular_posts( $number ) {
	$number  = max( 1, (int) $number );
	$exclude = array_filter( array( is_singular() ? (int) get_queried_object_id() : 0 ) );

	$viewed = new WP_Query( array(
		'post_type'           => 'post',
		'post_status'         => 'publish',
		'posts_per_page'      => $number,
		'ignore_sticky_posts' => 1,
		'no_found_rows'       => true,
		'post__not_in'        => $exclude,
		'meta_key'            => '_themify_views', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		'orderby'             => 'meta_value_num',
		'order'               => 'DESC',
	) );
	$posts = $viewed->posts;

	if ( count( $posts ) < $number ) {
		$fill = new WP_Query( array(
			'post_type'           => 'post',
			'post_status'         => 'publish',
			'posts_per_page'      => $number - count( $posts ),
			'ignore_sticky_posts' => 1,
			'no_found_rows'       => true,
			'post__not_in'        => array_merge( $exclude, wp_list_pluck( $posts, 'ID' ) ),
			'orderby'             => 'date',
			'order'               => 'DESC',
		) );
		$posts = array_merge( $posts, $fill->posts );
	}

	return $posts;
}

/**
 * Recent posts (latest first), excluding the current post.
 *
 * @param int $number How many to return.
 * @return WP_Post[]
 */
function themify_get_recent_posts_list( $number ) {
	$q = new WP_Query( array(
		'post_type'           => 'post',
		'post_status'         => 'publish',
		'posts_per_page'      => max( 1, (int) $number ),
		'ignore_sticky_posts' => 1,
		'no_found_rows'       => true,
		'post__not_in'        => array_filter( array( is_singular() ? (int) get_queried_object_id() : 0 ) ),
		'orderby'             => 'date',
		'order'               => 'DESC',
	) );
	return $q->posts;
}

/* -------------------------------------------------------------------------
 * WIDGET RENDERERS
 * ---------------------------------------------------------------------- */

/**
 * Render a compact, thumbnailed post list (used by Popular + Recent).
 *
 * @param WP_Post[] $posts    Posts to list.
 * @param bool      $numbered Show 1..N rank badges (for Popular).
 * @param bool      $views    Show the view count in the meta line.
 */
function themify_render_post_list( array $posts, $numbered = false, $views = false ) {
	if ( empty( $posts ) ) {
		return;
	}
	printf( '<ol class="tf-post-list%s">', $numbered ? ' tf-post-list--numbered' : '' );
	foreach ( $posts as $p ) {
		$link  = get_permalink( $p );
		$title = get_the_title( $p );
		echo '<li class="tf-post-list__item">';

		echo '<a class="tf-post-list__thumb" href="' . esc_url( $link ) . '" tabindex="-1" aria-label="' . esc_attr( $title ) . '">';
		if ( has_post_thumbnail( $p ) ) {
			echo get_the_post_thumbnail( $p, 'themify-card', array( 'loading' => 'lazy', 'alt' => '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- core markup, escaped attrs.
		} else {
			echo '<span class="tf-post-list__thumb-fallback"></span>';
		}
		echo '</a>';

		echo '<div class="tf-post-list__body">';
		printf( '<a class="tf-post-list__title" href="%s">%s</a>', esc_url( $link ), esc_html( $title ) );
		$meta = esc_html( get_the_date( '', $p ) );
		if ( $views ) {
			$count = (int) get_post_meta( $p->ID, '_themify_views', true );
			if ( $count > 0 ) {
				$meta .= ' <span class="tf-post-list__dot">·</span> ' . esc_html( sprintf(
					/* translators: %s: number of views */
					_n( '%s view', '%s views', $count, 'themify' ),
					number_format_i18n( $count )
				) );
			}
		}
		echo '<span class="tf-post-list__meta">' . $meta . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput -- parts escaped above.
		echo '</div>';

		echo '</li>';
	}
	echo '</ol>';
}

/** Search widget. */
function themify_sidebar_search() {
	echo '<section class="tf-widget tf-widget--search">';
	echo '<h3 class="widget-title">' . esc_html__( 'Search', 'themify' ) . '</h3>';
	get_search_form();
	echo '</section>';
}

/** Author card for the current post's author. */
function themify_sidebar_author_card() {
	$obj = get_queried_object();
	if ( ! $obj || empty( $obj->post_author ) ) {
		return;
	}
	$author_id = (int) $obj->post_author;
	$name      = get_the_author_meta( 'display_name', $author_id );
	$bio       = get_the_author_meta( 'description', $author_id );
	$url       = get_author_posts_url( $author_id );
	$count     = count_user_posts( $author_id, 'post', true );

	echo '<section class="tf-widget tf-widget--author">';
	echo '<div class="tf-author-widget">';
	echo '<a class="tf-author-widget__avatar" href="' . esc_url( $url ) . '">' . get_avatar( $author_id, 96, '', $name ) . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput -- core avatar markup.
	echo '<a class="tf-author-widget__name" href="' . esc_url( $url ) . '">' . esc_html( $name ) . '</a>';
	if ( $bio ) {
		echo '<p class="tf-author-widget__bio">' . esc_html( wp_trim_words( $bio, 26 ) ) . '</p>';
	}
	echo '<a class="tf-btn tf-btn--ghost tf-author-widget__link" href="' . esc_url( $url ) . '">' . esc_html( sprintf(
		/* translators: %s: number of articles */
		_n( 'View %s article', 'View all %s articles', $count, 'themify' ),
		number_format_i18n( $count )
	) ) . '</a>';
	echo '</div>';
	echo '</section>';
}

/** Popular posts widget. */
function themify_sidebar_popular() {
	$count = (int) themify_get_option( 'sidebar_popular_count', 10 );
	$posts = themify_get_popular_posts( $count > 0 ? $count : 10 );
	if ( empty( $posts ) ) {
		return;
	}
	echo '<section class="tf-widget tf-widget--popular">';
	echo '<h3 class="widget-title">' . esc_html__( 'Popular posts', 'themify' ) . '</h3>';
	themify_render_post_list( $posts, true, true );
	echo '</section>';
}

/** Recent posts widget. */
function themify_sidebar_recent() {
	$count = (int) themify_get_option( 'sidebar_recent_count', 10 );
	$posts = themify_get_recent_posts_list( $count > 0 ? $count : 10 );
	if ( empty( $posts ) ) {
		return;
	}
	echo '<section class="tf-widget tf-widget--recent">';
	echo '<h3 class="widget-title">' . esc_html__( 'Recent posts', 'themify' ) . '</h3>';
	themify_render_post_list( $posts, false, false );
	echo '</section>';
}

/**
 * Assemble and print the whole article sidebar. Called by single.php. Prints
 * nothing when the feature is off or the blog layout is full-width.
 */
function themify_render_post_sidebar() {
	if ( ! themify_is_enabled( 'sidebar_enabled', true ) || 'full' === themify_get_option( 'blog_layout' ) ) {
		return;
	}

	$sticky = themify_is_enabled( 'sidebar_sticky', true ) ? ' tf-sidebar--sticky' : '';
	echo '<aside class="tf-sidebar tf-sidebar--post' . esc_attr( $sticky ) . '" role="complementary" aria-label="' . esc_attr__( 'Article sidebar', 'themify' ) . '">';

	if ( themify_is_enabled( 'sidebar_search', true ) ) {
		themify_sidebar_search();
	}
	// The author card only makes sense on a single post; on category/tag/archive
	// listings we show just Search + Popular + Recent.
	if ( themify_is_enabled( 'sidebar_author', true ) && is_singular( 'post' ) ) {
		themify_sidebar_author_card();
	}
	if ( themify_is_enabled( 'sidebar_popular', true ) ) {
		themify_sidebar_popular();
	}
	if ( themify_is_enabled( 'sidebar_recent', true ) ) {
		themify_sidebar_recent();
	}

	// NOTE: the built-in sections above are the whole article sidebar. We do
	// NOT also print the 'sidebar-blog' widget area here — a fresh WordPress
	// install pre-fills that area with Search / Recent Posts / Recent Comments
	// widgets, which would duplicate the built-ins (two search boxes, two
	// recent-post lists). Custom widgets still power the archive/blog sidebar
	// via sidebar.php.

	echo '</aside>';
}

/* -------------------------------------------------------------------------
 * ADMIN
 * ---------------------------------------------------------------------- */

themify_register_admin_page( array(
	'slug'       => 'themify-article',
	'title'      => __( 'Article Sidebar', 'themify' ),
	'menu_title' => __( 'Article Sidebar', 'themify' ),
	'callback'   => 'themify_article_sidebar_page',
	'position'   => 48,
) );

add_filter( 'themify_dashboard_cards', function ( $cards ) {
	$cards[] = array(
		'slug'     => 'themify-article',
		'title'    => __( 'Article Sidebar', 'themify' ),
		'desc'     => __( 'Search, author, popular & recent', 'themify' ),
		'icon'     => 'dashicons-align-pull-right',
		'position' => 48,
	);
	return $cards;
} );

/** The "Article Sidebar" settings screen. */
function themify_article_sidebar_page() {
	themify_render_settings_page( array(
		'title'  => __( 'Article Sidebar', 'themify' ),
		'intro'  => __( 'The sidebar shown next to single posts. Turn sections on or off and set how many posts each list shows. Any widgets you add to the Blog Sidebar area appear below these.', 'themify' ),
		'nonce'  => 'themify_article_sidebar',
		'groups' => array(
			array(
				'title'  => __( 'Sections', 'themify' ),
				'fields' => array(
					array( 'key' => 'sidebar_enabled', 'label' => __( 'Show the article sidebar', 'themify' ), 'type' => 'checkbox', 'default' => '1' ),
					array( 'key' => 'sidebar_sticky', 'label' => __( 'Stick the sidebar while scrolling', 'themify' ), 'type' => 'checkbox', 'default' => '1' ),
					array( 'key' => 'sidebar_search', 'label' => __( 'Search box', 'themify' ), 'type' => 'checkbox', 'default' => '1' ),
					array( 'key' => 'sidebar_author', 'label' => __( 'Author card', 'themify' ), 'type' => 'checkbox', 'default' => '1' ),
					array( 'key' => 'sidebar_popular', 'label' => __( 'Popular posts', 'themify' ), 'type' => 'checkbox', 'default' => '1' ),
					array( 'key' => 'sidebar_recent', 'label' => __( 'Recent posts', 'themify' ), 'type' => 'checkbox', 'default' => '1' ),
				),
			),
			array(
				'title'  => __( 'Counts', 'themify' ),
				'fields' => array(
					array( 'key' => 'sidebar_popular_count', 'label' => __( 'Popular posts to show', 'themify' ), 'type' => 'number', 'default' => '10' ),
					array( 'key' => 'sidebar_recent_count', 'label' => __( 'Recent posts to show', 'themify' ), 'type' => 'number', 'default' => '10' ),
				),
			),
		),
	) );
}
