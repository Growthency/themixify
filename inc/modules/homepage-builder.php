<?php
/**
 * Homepage builder.
 *
 * A pragmatic, no-page-builder way to compose the site's front page from a
 * small set of purpose-built blocks — a marketing hero, blog post grids,
 * category showcases, free-form rich text and call-to-action bands. An
 * administrator arranges the blocks in the admin screen; front-page.php calls
 * themify_render_homepage() to paint them.
 *
 * The blocks live in their own option ('themify_homepage_blocks') as an ordered
 * (indexed) array — NOT in THEMIFY_OPT — because they are list-shaped data.
 * Each block is an associative array whose 'type' selects a renderer, plus the
 * fields that type needs:
 *
 *   hero        => heading, subheading, cta1_text, cta1_url, cta2_text,
 *                  cta2_url, bg
 *   posts_grid  => heading, source ('latest'|'category'), category (term id),
 *                  count
 *   categories  => heading, count
 *   richtext    => heading, html
 *   cta         => heading, text, button_text, button_url
 *
 * Every renderer escapes its own output. External data is never fetched here —
 * the whole page is built from local WordPress content, so it is safe to run on
 * a public request.
 *
 * @package Themify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Option name holding the ordered array of homepage blocks.
 */
if ( ! defined( 'THEMIFY_HOMEPAGE_OPT' ) ) {
	define( 'THEMIFY_HOMEPAGE_OPT', 'themify_homepage_blocks' );
}

/**
 * The block types this builder understands, mapped to a human label. The keys
 * double as the whitelist used when sanitizing posted data and when dispatching
 * to a renderer.
 *
 * @return array<string,string> type slug => label.
 */
function themify_homepage_block_types() {
	return array(
		'hero'       => __( 'Hero', 'themify' ),
		'posts_grid' => __( 'Post grid', 'themify' ),
		'categories' => __( 'Categories', 'themify' ),
		'richtext'   => __( 'Rich text', 'themify' ),
		'cta'        => __( 'Call to action', 'themify' ),
	);
}

/**
 * Read all stored homepage blocks, normalised into a predictable shape. Every
 * block is guaranteed to have a valid 'type' and every field key its type uses,
 * so renderers can read keys without isset() gymnastics.
 *
 * @return array<int,array> Ordered list of block rows.
 */
function themify_get_homepage_blocks() {
	$raw = get_option( THEMIFY_HOMEPAGE_OPT, array() );
	if ( ! is_array( $raw ) ) {
		return array();
	}

	$types = themify_homepage_block_types();
	$clean = array();

	foreach ( $raw as $block ) {
		if ( ! is_array( $block ) ) {
			continue;
		}
		$type = isset( $block['type'] ) ? (string) $block['type'] : '';
		if ( ! isset( $types[ $type ] ) ) {
			continue;
		}
		$clean[] = themify_normalise_homepage_block( $block );
	}

	return $clean;
}

/**
 * Fill in every field key a block might use with a safe default, so both the
 * renderers and the admin form can rely on a complete shape. Unknown keys are
 * dropped. This does NOT sanitize values for storage — that happens on save.
 *
 * @param array $block Raw block data.
 * @return array Normalised block.
 */
function themify_normalise_homepage_block( array $block ) {
	$defaults = array(
		'type'        => 'hero',
		'sort'        => 0,
		// hero.
		'heading'     => '',
		'subheading'  => '',
		'cta1_text'   => '',
		'cta1_url'    => '',
		'cta2_text'   => '',
		'cta2_url'    => '',
		'bg'          => '',
		'bg_image'    => '', // optional CTA-band background image URL.
		'banners'     => array(), // optional banner-slider image URLs.
		'autoplay'    => 6000,     // slider auto-advance interval (ms).
		// posts_grid.
		'source'      => 'latest',
		'category'    => 0,
		'count'       => 6,
		// richtext.
		'html'        => '',
		// cta.
		'text'        => '',
		'button_text' => '',
		'button_url'  => '',
	);

	$out          = wp_parse_args( array_intersect_key( $block, $defaults ), $defaults );
	$out['type']  = isset( $block['type'] ) ? (string) $block['type'] : 'hero';
	$out['sort']  = (int) $out['sort'];
	$out['count'] = (int) $out['count'];
	if ( $out['count'] < 1 ) {
		$out['count'] = 6;
	}
	$out['category'] = (int) $out['category'];
	$out['source']   = in_array( $out['source'], array( 'category', 'popular' ), true ) ? $out['source'] : 'latest';
	$out['banners']  = themify_hero_clean_banners( $out['banners'] );
	$out['autoplay'] = (int) $out['autoplay'];
	if ( $out['autoplay'] < 1500 ) {
		$out['autoplay'] = 6000;
	}

	return $out;
}

/* -------------------------------------------------------------------------
 * FRONT-END RENDERING
 * ---------------------------------------------------------------------- */

/**
 * The first hero banner image URL (custom blocks first, else the automatic
 * hero) — this is the homepage's LCP element.
 *
 * @return string Image URL or ''.
 */
function themify_hero_first_banner() {
	foreach ( themify_get_homepage_blocks() as $block ) {
		if ( 'hero' === $block['type'] ) {
			return ! empty( $block['banners'][0] ) ? (string) $block['banners'][0] : '';
		}
	}
	$hero = themify_get_hero();
	return ! empty( $hero['banners'][0] ) ? (string) $hero['banners'][0] : '';
}

/**
 * Preload the first hero banner with high priority. It renders as a CSS
 * background, so without this hint the browser only discovers it after CSS
 * parses — the single biggest LCP delay on the homepage.
 */
function themify_preload_hero_banner() {
	if ( is_admin() || ! is_front_page() ) {
		return;
	}
	$url = themify_hero_first_banner();
	if ( '' !== $url ) {
		printf( '<link rel="preload" as="image" href="%s" fetchpriority="high">' . "\n", esc_url( $url ) );
	}
}
add_action( 'wp_head', 'themify_preload_hero_banner', 4 );

/**
 * Render the whole homepage. Called by front-page.php.
 *
 * If any blocks are configured, they are rendered in stored order; each is
 * dispatched to themify_render_block_{type}(). If no blocks exist, a sensible
 * default homepage is painted instead (a hero built from the site name/tagline
 * plus a grid of the latest posts) so a fresh install still looks intentional.
 */
function themify_render_homepage() {
	$blocks = themify_get_homepage_blocks();

	if ( empty( $blocks ) ) {
		themify_render_homepage_default();
		return;
	}

	foreach ( $blocks as $i => $block ) {
		$callback = 'themify_render_block_' . $block['type'];
		if ( function_exists( $callback ) ) {
			call_user_func( $callback, $block );
		}
		// The optional homepage search band slots in right after the first block
		// (usually the hero).
		if ( 0 === $i ) {
			themify_render_home_search();
		}
	}
}

/**
 * The homepage search band — a big, centred search box shown right under the
 * hero. Toggled on/off (and worded) from Themixify → Homepage.
 */
function themify_render_home_search() {
	if ( ! themify_is_enabled( 'home_search_enabled', false ) ) {
		return;
	}

	$heading     = trim( (string) themify_get_option( 'home_search_heading', '' ) );
	$placeholder = trim( (string) themify_get_option( 'home_search_placeholder', '' ) );
	if ( '' === $placeholder ) {
		$placeholder = __( 'Search articles…', 'themify' );
	}

	echo '<section class="tf-home-section tf-home-search">';
	echo '<div class="tf-container">';
	if ( '' !== $heading ) {
		echo '<h2 class="tf-home-section__title">' . esc_html( $heading ) . '</h2>';
	}
	printf(
		'<form role="search" method="get" class="tf-home-search__form" action="%s"><input type="search" name="s" class="tf-home-search__input" placeholder="%s" aria-label="%s" /><button type="submit" class="tf-btn tf-home-search__btn">%s</button></form>',
		esc_url( home_url( '/' ) ),
		esc_attr( $placeholder ),
		esc_attr__( 'Search this site', 'themify' ),
		esc_html__( 'Search', 'themify' )
	);
	echo '</div>';
	echo '</section>';
}

/**
 * Read the automatic-homepage hero settings (its own option, list-shaped
 * because of the banners array). Any blank field falls back to the site
 * identity, so a brand-new install still shows a polished hero.
 *
 * @return array Hero config for themify_render_hero().
 */
function themify_get_hero() {
	$saved = get_option( 'themify_hero', array() );
	if ( ! is_array( $saved ) ) {
		$saved = array();
	}

	$name     = get_bloginfo( 'name' );
	$tagline  = get_bloginfo( 'description' );
	$blog_url = get_permalink( get_option( 'page_for_posts' ) );

	return array(
		'heading'    => '' !== trim( (string) ( $saved['heading'] ?? '' ) )
			? $saved['heading']
			: ( '' !== trim( (string) $name ) ? $name : __( 'Welcome', 'themify' ) ),
		'subheading' => isset( $saved['subheading'] ) && '' !== trim( (string) $saved['subheading'] )
			? $saved['subheading']
			: $tagline,
		'cta1_text'  => isset( $saved['cta1_text'] ) && '' !== trim( (string) $saved['cta1_text'] )
			? $saved['cta1_text']
			: __( 'Read the blog', 'themify' ),
		'cta1_url'   => isset( $saved['cta1_url'] ) && '' !== trim( (string) $saved['cta1_url'] )
			? $saved['cta1_url']
			: ( $blog_url ? $blog_url : home_url( '/' ) ),
		'cta2_text'  => $saved['cta2_text'] ?? '',
		'cta2_url'   => $saved['cta2_url'] ?? '',
		'bg'         => $saved['bg'] ?? '',
		'banners'    => themify_hero_clean_banners( $saved['banners'] ?? array() ),
		'autoplay'   => isset( $saved['autoplay'] ) ? (int) $saved['autoplay'] : 6000,
	);
}

/**
 * The zero-config fallback homepage: the hero (from the Homepage Hero settings,
 * with an optional banner slider) and a grid of the latest posts. Runs when no
 * builder blocks have been configured yet.
 */
function themify_render_homepage_default() {
	themify_render_hero( themify_get_hero() );

	// Optional search band, right under the hero.
	themify_render_home_search();

	themify_render_block_posts_grid( themify_normalise_homepage_block( array(
		'type'    => 'posts_grid',
		'heading' => __( 'Latest posts', 'themify' ),
		'source'  => 'latest',
		'count'   => 9,
	) ) );

	// Popular posts (by view count) in the same featured + grid layout.
	themify_render_block_posts_grid( themify_normalise_homepage_block( array(
		'type'    => 'posts_grid',
		'heading' => __( 'Popular posts', 'themify' ),
		'source'  => 'popular',
		'count'   => 9,
	) ) );

	// A category showcase so the front page feels designed even with zero setup.
	// Renders nothing when the site has no categories yet. The high count means
	// "show every category", so new ones appear automatically as they are added.
	themify_render_block_categories( themify_normalise_homepage_block( array(
		'type'    => 'categories',
		'heading' => __( 'Browse by topic', 'themify' ),
		'count'   => 500,
	) ) );

	// YouTube channel section (subscribe + latest videos) when configured.
	if ( function_exists( 'themify_render_youtube_section' ) ) {
		themify_render_youtube_section();
	}

	// A closing call-to-action band — fully editable (and toggleable) from
	// Themixify → Homepage → Call-to-action band. Blank fields fall back to
	// sensible site-identity defaults.
	if ( themify_is_enabled( 'cta_enabled', true ) ) {
		$themify_blog = get_permalink( (int) get_option( 'page_for_posts' ) );
		$cta_heading  = trim( (string) themify_get_option( 'cta_heading', '' ) );
		$cta_text     = trim( (string) themify_get_option( 'cta_text', '' ) );
		$cta_btn_txt  = trim( (string) themify_get_option( 'cta_button_text', '' ) );
		$cta_btn_url  = trim( (string) themify_get_option( 'cta_button_url', '' ) );

		themify_render_block_cta( themify_normalise_homepage_block( array(
			'type'        => 'cta',
			'heading'     => '' !== $cta_heading
				? $cta_heading
				/* translators: %s: site name */
				: sprintf( __( 'Explore more of %s', 'themify' ), get_bloginfo( 'name' ) ),
			'text'        => '' !== $cta_text
				? $cta_text
				: ( get_bloginfo( 'description' ) ? get_bloginfo( 'description' ) : __( 'Fresh guides, honest reviews and practical how-tos — updated regularly.', 'themify' ) ),
			'button_text' => '' !== $cta_btn_txt ? $cta_btn_txt : __( 'Read the blog', 'themify' ),
			'button_url'  => '' !== $cta_btn_url ? $cta_btn_url : ( $themify_blog ? $themify_blog : home_url( '/' ) ),
		) ) );
	}
}

/**
 * Normalise a set of banner image URLs: coerce to a flat array of trimmed,
 * non-empty strings. Values are escaped again at render time.
 *
 * @param mixed $banners Raw value (array or anything).
 * @return string[] Clean list of URLs.
 */
function themify_hero_clean_banners( $banners ) {
	if ( ! is_array( $banners ) ) {
		return array();
	}
	$out = array();
	foreach ( $banners as $url ) {
		$url = trim( (string) $url );
		if ( '' !== $url ) {
			$out[] = $url;
		}
	}
	return array_values( $out );
}

/**
 * Sanitize a posted list of banner URLs for storage: each through esc_url_raw,
 * blanks dropped. Accepts either an array or a newline-separated string.
 *
 * @param mixed $raw Posted banners value.
 * @return string[] Clean, escaped URLs.
 */
function themify_hero_sanitize_banners( $raw ) {
	if ( is_string( $raw ) ) {
		$raw = preg_split( '/[\r\n]+/', $raw );
	}
	if ( ! is_array( $raw ) ) {
		return array();
	}
	$out = array();
	foreach ( $raw as $url ) {
		$url = esc_url_raw( trim( (string) $url ) );
		if ( '' !== $url ) {
			$out[] = $url;
		}
	}
	return array_values( $out );
}

/**
 * Hero block. Delegates to the shared hero renderer so the block builder and
 * the automatic homepage hero share one implementation.
 *
 * @param array $block Normalised block data.
 */
function themify_render_block_hero( array $block ) {
	themify_render_hero( array(
		'heading'    => $block['heading'],
		'subheading' => $block['subheading'],
		'cta1_text'  => $block['cta1_text'],
		'cta1_url'   => $block['cta1_url'],
		'cta2_text'  => $block['cta2_text'],
		'cta2_url'   => $block['cta2_url'],
		'bg'         => $block['bg'],
		'banners'    => $block['banners'],
		'autoplay'   => $block['autoplay'],
	) );
}

/**
 * The shared hero renderer. Two looks from one function:
 *
 *   - With banner images → a full-bleed image slider (crossfade, auto-advancing,
 *     with arrows + dots when there is more than one) and the heading/subheading/
 *     buttons overlaid on a readability scrim. Uploading more images just adds
 *     more slides — there is no upper limit.
 *   - Without banner images → the clean gradient hero (optionally a custom CSS
 *     background), so a site with no images still looks intentional.
 *
 * Every value is escaped on output. Banner URLs only ever appear inside a
 * background-image:url() and an esc_url() wrapper.
 *
 * @param array $cfg heading, subheading, cta1_text, cta1_url, cta2_text,
 *                    cta2_url, bg, banners (array), autoplay (int ms).
 */
function themify_render_hero( array $cfg ) {
	$heading   = trim( (string) ( $cfg['heading'] ?? '' ) );
	$sub       = trim( (string) ( $cfg['subheading'] ?? '' ) );
	$cta1_text = trim( (string) ( $cfg['cta1_text'] ?? '' ) );
	$cta1_url  = trim( (string) ( $cfg['cta1_url'] ?? '' ) );
	$cta2_text = trim( (string) ( $cfg['cta2_text'] ?? '' ) );
	$cta2_url  = trim( (string) ( $cfg['cta2_url'] ?? '' ) );
	$banners   = themify_hero_clean_banners( $cfg['banners'] ?? array() );
	$has_cta   = ( '' !== $cta1_text && '' !== $cta1_url ) || ( '' !== $cta2_text && '' !== $cta2_url );
	$has_text  = '' !== $heading || '' !== $sub || $has_cta;

	// Nothing to render at all.
	if ( ! $has_text && empty( $banners ) ) {
		return;
	}

	$is_slider = ! empty( $banners );
	$autoplay  = isset( $cfg['autoplay'] ) ? max( 1500, (int) $cfg['autoplay'] ) : 6000;

	// Optional CSS background only applies to the plain (non-slider) hero.
	$style = '';
	$bg    = trim( (string) ( $cfg['bg'] ?? '' ) );
	if ( ! $is_slider && '' !== $bg ) {
		$style = ' style="background:' . esc_attr( $bg ) . ';"';
	}

	$classes = 'tf-hero' . ( $is_slider ? ' tf-hero--slider' : '' );
	echo '<section class="' . esc_attr( $classes ) . '"' . $style . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $style built from esc_attr'd value.

	// Slider background layer.
	if ( $is_slider ) {
		$multi = count( $banners ) > 1;
		printf( '<div class="tf-slider" data-autoplay="%d">', (int) $autoplay );
		echo '<div class="tf-slider__slides">';
		foreach ( $banners as $i => $url ) {
			printf(
				'<div class="tf-slide%s" style="background-image:url(%s)" role="img" aria-label="%s"></div>',
				0 === $i ? ' is-active' : '',
				esc_url( $url ),
				/* translators: %d: banner number */
				esc_attr( sprintf( __( 'Banner image %d', 'themify' ), $i + 1 ) )
			);
		}
		echo '</div>'; // .tf-slider__slides
		echo '<span class="tf-slider__scrim" aria-hidden="true"></span>';

		if ( $multi ) {
			// Dots only — the prev/next arrows were removed per request; the
			// slider auto-advances and the dots let visitors jump between slides.
			echo '<div class="tf-slider__dots">';
			foreach ( $banners as $i => $url ) {
				printf(
					'<button type="button" class="tf-slider__dot%s" aria-label="%s"></button>',
					0 === $i ? ' is-active' : '',
					/* translators: %d: slide number */
					esc_attr( sprintf( __( 'Go to slide %d', 'themify' ), $i + 1 ) )
				);
			}
			echo '</div>';
		}
		echo '</div>'; // .tf-slider
	}

	// Text / content layer (overlays the slider, or sits in the gradient hero).
	if ( $has_text ) {
		echo '<div class="tf-hero__inner"><div class="tf-container">';
		if ( '' !== $heading ) {
			echo '<h1>' . esc_html( $heading ) . '</h1>';
		}
		if ( '' !== $sub ) {
			echo '<p class="tf-hero__sub">' . esc_html( $sub ) . '</p>';
		}
		if ( $has_cta ) {
			echo '<div class="tf-hero__cta">';
			if ( '' !== $cta1_text && '' !== $cta1_url ) {
				printf( '<a class="tf-btn" href="%s">%s</a>', esc_url( $cta1_url ), esc_html( $cta1_text ) );
			}
			if ( '' !== $cta2_text && '' !== $cta2_url ) {
				printf( '<a class="tf-btn tf-btn--ghost" href="%s">%s</a>', esc_url( $cta2_url ), esc_html( $cta2_text ) );
			}
			echo '</div>';
		}
		echo '</div></div>'; // .tf-container .tf-hero__inner
	}

	echo '</section>';
}

/**
 * Post grid block. Runs a WP_Query for either the latest posts or a specific
 * category, then renders the shared post-card grid by reusing the theme's
 * template part inside the loop.
 *
 * @param array $block Normalised block data.
 */
function themify_render_block_posts_grid( array $block ) {
	$count  = max( 1, min( 24, (int) $block['count'] ) );
	$source = $block['source'];

	$args = array(
		'post_type'           => 'post',
		'post_status'         => 'publish',
		'posts_per_page'      => $count,
		'ignore_sticky_posts' => 1,
		'no_found_rows'       => true,
	);

	if ( 'category' === $source && (int) $block['category'] > 0 ) {
		$args['cat'] = (int) $block['category'];
	} elseif ( 'popular' === $source ) {
		$args['meta_key'] = '_themify_views'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		$args['orderby']  = 'meta_value_num';
		$args['order']    = 'DESC';
	}

	$query = new WP_Query( $args );

	// Popular but nothing has views yet → fall back to latest so the section
	// never comes up empty.
	if ( 'popular' === $source && ! $query->have_posts() ) {
		unset( $args['meta_key'], $args['orderby'], $args['order'] );
		$query = new WP_Query( $args );
	}

	if ( ! $query->have_posts() ) {
		wp_reset_postdata();
		return;
	}

	$heading = trim( (string) $block['heading'] );
	$posts   = $query->posts; // WP_Post[]

	echo '<section class="tf-home-section">';
	echo '<div class="tf-container">';
	if ( '' !== $heading ) {
		echo '<h2 class="tf-home-section__title">' . esc_html( $heading ) . '</h2>';
	}

	// A large featured card for the first post, then the rest in the grid —
	// but only when there are enough posts to make the split look intentional.
	if ( count( $posts ) >= 3 ) {
		$featured        = array_shift( $posts );
		$GLOBALS['post'] = $featured; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		setup_postdata( $featured );
		themify_render_featured_post();
	}

	if ( $posts ) {
		echo '<div class="tf-grid">';
		foreach ( $posts as $themify_p ) {
			$GLOBALS['post'] = $themify_p; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
			setup_postdata( $themify_p );
			get_template_part( 'template-parts/content' );
		}
		echo '</div>'; // .tf-grid
	}

	echo '</div>'; // .tf-container
	echo '</section>';

	wp_reset_postdata();
}

/**
 * Render the large "featured" post card (image + content side by side). Assumes
 * the global post is already set up (setup_postdata) by the caller.
 */
function themify_render_featured_post() {
	$link = get_permalink();
	echo '<article class="tf-featured-post">';
	if ( has_post_thumbnail() ) {
		printf(
			'<a class="tf-featured-post__thumb" href="%s" tabindex="-1" aria-label="%s">%s</a>',
			esc_url( $link ),
			esc_attr( get_the_title() ),
			get_the_post_thumbnail( null, 'themify-hero', array( 'loading' => 'lazy', 'alt' => '' ) ) // phpcs:ignore WordPress.Security.EscapeOutput -- core markup.
		);
	}
	echo '<div class="tf-featured-post__body">';
	themify_category_pills();
	printf( '<h2 class="tf-featured-post__title"><a href="%s">%s</a></h2>', esc_url( $link ), esc_html( get_the_title() ) );
	printf( '<p class="tf-featured-post__excerpt">%s</p>', esc_html( wp_trim_words( get_the_excerpt(), 42 ) ) );
	themify_entry_meta();
	echo '</div>'; // .tf-featured-post__body
	echo '</article>';
}

/**
 * Categories block. A grid of cards, one per top-level (or all) category, each
 * linking to its archive and showing its post count.
 *
 * @param array $block Normalised block data.
 */
function themify_render_block_categories( array $block ) {
	$limit = (int) $block['count'];
	if ( $limit < 1 ) {
		$limit = 0; // 0 = no cap (get_terms treats <=0 as "all").
	}

	// Show EVERY category (even ones with no posts yet), ordered by name.
	$terms = get_terms( array(
		'taxonomy'   => 'category',
		'hide_empty' => false,
		'number'     => $limit,
		'orderby'    => 'name',
		'order'      => 'ASC',
	) );

	if ( is_wp_error( $terms ) || empty( $terms ) ) {
		return;
	}

	// Hide only the literal "Uncategorized" placeholder when it's empty and
	// other categories exist. Every real category — even a renamed default
	// category with no posts yet — always shows.
	if ( count( $terms ) > 1 ) {
		$terms = array_values( array_filter( $terms, function ( $t ) {
			return ! ( 'uncategorized' === $t->slug && (int) $t->count === 0 );
		} ) );
	}

	$heading   = trim( (string) $block['heading'] );
	$gradients = function_exists( 'themify_category_gradients' ) ? themify_category_gradients() : array( 'linear-gradient(135deg,#141e30,#243b55)' );

	echo '<section class="tf-home-section">';
	echo '<div class="tf-container">';
	if ( '' !== $heading ) {
		echo '<h2 class="tf-home-section__title">' . esc_html( $heading ) . '</h2>';
	}
	echo '<div class="tf-cat-grid">';
	foreach ( $terms as $i => $term ) {
		$img = function_exists( 'themify_get_category_image' ) ? themify_get_category_image( $term->term_id ) : '';
		if ( $img ) {
			// A dark scrim over the photo keeps the white text readable.
			$style = 'background-image:linear-gradient(rgba(8,20,12,.35),rgba(8,20,12,.68)),url(' . esc_url( $img ) . ')';
		} else {
			$style = 'background:' . $gradients[ $i % count( $gradients ) ];
		}
		printf(
			'<a class="tf-cat-card" href="%s" style="%s"><span class="tf-cat-card__name">%s</span><span class="tf-cat-card__count">%s</span></a>',
			esc_url( get_category_link( $term->term_id ) ),
			$style, // phpcs:ignore WordPress.Security.EscapeOutput -- url is esc_url'd, gradients are static.
			esc_html( $term->name ),
			esc_html( sprintf(
				/* translators: %s: number of posts */
				_n( '%s post', '%s posts', $term->count, 'themify' ),
				number_format_i18n( $term->count )
			) )
		);
	}
	echo '</div>'; // .tf-cat-grid
	echo '</div>'; // .tf-container
	echo '</section>';
}

/**
 * Rich text block. Free-form HTML in a comfortable reading measure. The stored
 * HTML was already run through wp_kses_post on save; it is filtered through
 * wp_kses_post again on output as defence in depth.
 *
 * @param array $block Normalised block data.
 */
function themify_render_block_richtext( array $block ) {
	$html = (string) $block['html'];
	if ( '' === trim( wp_strip_all_tags( $html ) ) ) {
		return;
	}

	$heading = trim( (string) $block['heading'] );

	echo '<section class="tf-home-section">';
	echo '<div class="tf-container tf-narrow">';
	if ( '' !== $heading ) {
		echo '<h2 class="tf-home-section__title">' . esc_html( $heading ) . '</h2>';
	}
	echo '<div class="tf-content">' . wp_kses_post( wpautop( $html ) ) . '</div>';
	echo '</div>'; // .tf-container
	echo '</section>';
}

/**
 * Call-to-action block. A centred band with a heading, supporting text and one
 * button.
 *
 * @param array $block Normalised block data.
 */
function themify_render_block_cta( array $block ) {
	$heading = trim( (string) $block['heading'] );
	$text    = trim( (string) $block['text'] );
	$btn_txt = trim( (string) $block['button_text'] );
	$btn_url = trim( (string) $block['button_url'] );

	if ( '' === $heading && '' === $text && '' === $btn_txt ) {
		return;
	}

	// Background: a block's own image/colour wins; otherwise fall back to the
	// global "call-to-action band" settings. With neither, the CSS default
	// (the accent gradient) applies.
	$bg_image = trim( (string) ( $block['bg_image'] ?? '' ) );
	$bg_color = trim( (string) ( $block['bg'] ?? '' ) );
	if ( '' === $bg_image ) {
		$bg_image = trim( (string) themify_get_option( 'cta_bg_image', '' ) );
	}
	if ( '' === $bg_color ) {
		$bg_color = trim( (string) themify_get_option( 'cta_bg', '' ) );
	}

	$band_class = 'tf-cta-band';
	$band_style = '';
	if ( '' !== $bg_image ) {
		// A dark scrim over the photo keeps the white text readable.
		$band_class .= ' tf-cta-band--image';
		$band_style  = ' style="background-image:linear-gradient(rgba(8,12,16,.48),rgba(8,12,16,.62)),url(' . esc_url( $bg_image ) . ');"';
	} elseif ( '' !== $bg_color ) {
		$band_style = ' style="background:' . esc_attr( $bg_color ) . ';"';
	}

	echo '<section class="tf-home-section">';
	echo '<div class="tf-container">';
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- class is esc_attr'd; style is built from esc_url()/esc_attr() values above.
	echo '<div class="' . esc_attr( $band_class ) . '"' . $band_style . '>';
	if ( '' !== $heading ) {
		echo '<h2 class="tf-cta-band__title">' . esc_html( $heading ) . '</h2>';
	}
	if ( '' !== $text ) {
		echo '<p class="tf-cta-band__text">' . esc_html( $text ) . '</p>';
	}
	if ( '' !== $btn_txt && '' !== $btn_url ) {
		printf(
			'<div class="tf-cta-band__actions"><a class="tf-btn" href="%s">%s</a></div>',
			esc_url( $btn_url ),
			esc_html( $btn_txt )
		);
	}
	echo '</div>'; // .tf-cta-band
	echo '</div>'; // .tf-container
	echo '</section>';
}

/* -------------------------------------------------------------------------
 * ADMIN PAGE
 * ---------------------------------------------------------------------- */

/**
 * Register the "Homepage" submenu (position 12).
 */
themify_register_admin_page( array(
	'slug'       => 'themify-homepage',
	'title'      => __( 'Homepage', 'themify' ),
	'menu_title' => __( 'Homepage', 'themify' ),
	'callback'   => 'themify_homepage_page',
	'position'   => 10,
) );

/**
 * Add the homepage-builder card to the dashboard grid.
 */
add_filter( 'themify_dashboard_cards', 'themify_homepage_dashboard_card' );

/**
 * Append the "Homepage" dashboard card.
 *
 * @param array $cards Existing dashboard cards.
 * @return array
 */
function themify_homepage_dashboard_card( $cards ) {
	$cards[] = array(
		'slug'     => 'themify-homepage',
		'title'    => __( 'Homepage', 'themify' ),
		'desc'     => __( 'Build your front page from blocks', 'themify' ),
		'icon'     => 'dashicons-layout',
		'position' => 10,
	);
	return $cards;
}

/**
 * Handle a POST save of the block repeater. Rebuilds the whole ordered list
 * from the posted rows: whitelists the type, sanitizes every field by its data
 * kind, orders rows by their explicit 'sort' index (ties keep form order), and
 * drops rows a user left completely empty.
 *
 * @return bool True when a valid save happened (so the caller can show a notice).
 */
function themify_homepage_handle_save() {
	if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
		return false;
	}
	if ( ! current_user_can( THEMIFY_CAP ) ) {
		return false;
	}
	$nonce = isset( $_POST['themify_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['themify_nonce'] ) ) : '';
	if ( ! wp_verify_nonce( $nonce, 'themify_homepage' ) ) {
		return false;
	}

	$rows = isset( $_POST['themify_blocks'] ) && is_array( $_POST['themify_blocks'] )
		? wp_unslash( $_POST['themify_blocks'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each field is sanitized individually below.
		: array();

	$types = themify_homepage_block_types();
	$clean = array();
	$order = 0;

	foreach ( $rows as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}

		$type = isset( $row['type'] ) ? (string) $row['type'] : '';
		if ( ! isset( $types[ $type ] ) ) {
			continue;
		}

		// Sanitize every possible field. A block only "uses" some of these, but
		// storing the whole set keeps the admin form simple (one row template).
		$block = array(
			'type'        => $type,
			'sort'        => isset( $row['sort'] ) ? (int) $row['sort'] : $order,
			'heading'     => isset( $row['heading'] ) ? sanitize_text_field( $row['heading'] ) : '',
			'subheading'  => isset( $row['subheading'] ) ? sanitize_text_field( $row['subheading'] ) : '',
			'cta1_text'   => isset( $row['cta1_text'] ) ? sanitize_text_field( $row['cta1_text'] ) : '',
			'cta1_url'    => isset( $row['cta1_url'] ) ? esc_url_raw( trim( (string) $row['cta1_url'] ) ) : '',
			'cta2_text'   => isset( $row['cta2_text'] ) ? sanitize_text_field( $row['cta2_text'] ) : '',
			'cta2_url'    => isset( $row['cta2_url'] ) ? esc_url_raw( trim( (string) $row['cta2_url'] ) ) : '',
			'bg'          => isset( $row['bg'] ) ? sanitize_text_field( $row['bg'] ) : '',
			'bg_image'    => isset( $row['bg_image'] ) ? esc_url_raw( trim( (string) $row['bg_image'] ) ) : '',
			'banners'     => isset( $row['banners'] ) ? themify_hero_sanitize_banners( $row['banners'] ) : array(),
			'autoplay'    => isset( $row['autoplay'] ) ? (int) $row['autoplay'] : 6000,
			'source'      => ( isset( $row['source'] ) && 'category' === $row['source'] ) ? 'category' : 'latest',
			'category'    => isset( $row['category'] ) ? (int) $row['category'] : 0,
			'count'       => isset( $row['count'] ) ? (int) $row['count'] : 6,
			'html'        => isset( $row['html'] ) ? wp_kses_post( $row['html'] ) : '',
			'text'        => isset( $row['text'] ) ? sanitize_text_field( $row['text'] ) : '',
			'button_text' => isset( $row['button_text'] ) ? sanitize_text_field( $row['button_text'] ) : '',
			'button_url'  => isset( $row['button_url'] ) ? esc_url_raw( trim( (string) $row['button_url'] ) ) : '',
		);

		if ( $block['count'] < 1 ) {
			$block['count'] = 6;
		}

		// Skip rows the user added but never filled in, per type.
		if ( themify_homepage_block_is_empty( $block ) ) {
			continue;
		}

		// Keep the original form position as a tie-breaker for equal sort values.
		$block['_pos'] = $order;
		$clean[]       = $block;
		$order++;
	}

	// Order by the explicit sort index, falling back to form order on ties.
	usort( $clean, function ( $a, $b ) {
		if ( $a['sort'] === $b['sort'] ) {
			return $a['_pos'] <=> $b['_pos'];
		}
		return $a['sort'] <=> $b['sort'];
	} );

	// Strip the internal tie-breaker before persisting.
	foreach ( $clean as &$block ) {
		unset( $block['_pos'] );
	}
	unset( $block );

	update_option( THEMIFY_HOMEPAGE_OPT, array_values( $clean ) );
	return true;
}

/**
 * Whether a block carries no meaningful content for its type — used to discard
 * empty repeater rows the admin added but never filled in.
 *
 * @param array $block Sanitized block.
 * @return bool
 */
function themify_homepage_block_is_empty( array $block ) {
	switch ( $block['type'] ) {
		case 'hero':
			return '' === trim( $block['heading'] ) && '' === trim( $block['subheading'] );
		case 'posts_grid':
		case 'categories':
			// These generate their own content from the site; a heading is
			// optional, so they are never "empty".
			return false;
		case 'richtext':
			return '' === trim( wp_strip_all_tags( $block['html'] ) );
		case 'cta':
			return '' === trim( $block['heading'] ) && '' === trim( $block['text'] ) && '' === trim( $block['button_text'] );
	}
	return false;
}

/**
 * Render one block row of the repeater. Shows a type selector plus the union of
 * every field; each field is tagged with its owning type via a data attribute
 * so the fields for the non-selected types are hidden (progressive disclosure).
 *
 * @param int|string $index Row index (numeric for real rows, '__INDEX__' for
 *                          the JS template).
 * @param array      $block Block data (normalised defaults for the template).
 */
function themify_homepage_render_row( $index, array $block = array() ) {
	$block = themify_normalise_homepage_block( $block );
	$base  = 'themify_blocks[' . $index . ']';
	$type  = $block['type'];

	echo '<div class="tf-repeater__row tf-block-row" data-type="' . esc_attr( $type ) . '">';

	// Row header: type selector + sort order.
	echo '<div class="tf-block-row__head" style="display:grid; grid-template-columns:1fr 140px; gap:12px; align-items:end;">';

	echo '<div class="tf-field tf-field--select" style="margin-bottom:0;">';
	printf( '<label class="tf-field__label">%s</label>', esc_html__( 'Block type', 'themify' ) );
	printf( '<select name="%s[type]" class="tf-input tf-select tf-block-type">', esc_attr( $base ) );
	foreach ( themify_homepage_block_types() as $tv => $tl ) {
		printf(
			'<option value="%s" %s>%s</option>',
			esc_attr( $tv ),
			selected( $type, $tv, false ),
			esc_html( $tl )
		);
	}
	echo '</select>';
	echo '</div>';

	echo '<div class="tf-field tf-field--number" style="margin-bottom:0;">';
	printf( '<label class="tf-field__label">%s</label>', esc_html__( 'Order', 'themify' ) );
	printf(
		'<input type="number" name="%s[sort]" value="%d" class="tf-input" step="1" />',
		esc_attr( $base ),
		(int) $block['sort']
	);
	echo '</div>';

	echo '</div>'; // .tf-block-row__head

	// Shared heading (used by every type except that hero calls it its title).
	themify_homepage_field_text(
		$base . '[heading]',
		__( 'Heading', 'themify' ),
		$block['heading'],
		'',
		array( 'hero', 'posts_grid', 'categories', 'richtext', 'cta' )
	);

	/* ---- hero fields ---- */
	themify_homepage_field_text( $base . '[subheading]', __( 'Subheading', 'themify' ), $block['subheading'], '', array( 'hero' ) );
	themify_homepage_field_text( $base . '[cta1_text]', __( 'Primary button text', 'themify' ), $block['cta1_text'], __( 'e.g. Get started', 'themify' ), array( 'hero' ) );
	themify_homepage_field_url( $base . '[cta1_url]', __( 'Primary button URL', 'themify' ), $block['cta1_url'], array( 'hero' ) );
	themify_homepage_field_text( $base . '[cta2_text]', __( 'Secondary button text', 'themify' ), $block['cta2_text'], __( 'e.g. Learn more', 'themify' ), array( 'hero' ) );
	themify_homepage_field_url( $base . '[cta2_url]', __( 'Secondary button URL', 'themify' ), $block['cta2_url'], array( 'hero' ) );
	themify_homepage_field_text(
		$base . '[bg]',
		__( 'Background (optional)', 'themify' ),
		$block['bg'],
		__( 'e.g. #f6fbf8 or linear-gradient(135deg,#ecfaf1,#c8e8d2)', 'themify' ),
		array( 'hero' )
	);
	themify_homepage_field_gallery(
		$base . '[banners]',
		__( 'Banner slider images (optional)', 'themify' ),
		(array) $block['banners'],
		array( 'hero' )
	);

	/* ---- posts_grid fields ---- */
	themify_homepage_field_select(
		$base . '[source]',
		__( 'Posts source', 'themify' ),
		$block['source'],
		array(
			'latest'   => __( 'Latest posts', 'themify' ),
			'popular'  => __( 'Popular posts (by views)', 'themify' ),
			'category' => __( 'Posts from a category', 'themify' ),
		),
		array( 'posts_grid' )
	);
	themify_homepage_field_category( $base . '[category]', __( 'Category', 'themify' ), (int) $block['category'], array( 'posts_grid' ) );

	/* ---- posts_grid + categories share a count field ---- */
	themify_homepage_field_number(
		$base . '[count]',
		__( 'How many to show', 'themify' ),
		(int) $block['count'],
		__( 'Number of posts / categories. Blank or 0 = a sensible default.', 'themify' ),
		array( 'posts_grid', 'categories' )
	);

	/* ---- richtext field ---- */
	themify_homepage_field_richtext( $base . '[html]', __( 'Content', 'themify' ), $block['html'], array( 'richtext' ) );

	/* ---- cta fields ---- */
	themify_homepage_field_text( $base . '[text]', __( 'Supporting text', 'themify' ), $block['text'], '', array( 'cta' ) );
	themify_homepage_field_text( $base . '[button_text]', __( 'Button text', 'themify' ), $block['button_text'], __( 'e.g. Subscribe', 'themify' ), array( 'cta' ) );
	themify_homepage_field_url( $base . '[button_url]', __( 'Button URL', 'themify' ), $block['button_url'], array( 'cta' ) );

	echo '<p style="margin:0;"><a href="#" class="tf-remove">' . esc_html__( 'Remove block', 'themify' ) . '</a></p>';

	echo '</div>'; // .tf-repeater__row
}

/**
 * Build the data-for attribute that scopes a field to one or more block types.
 * The admin JS shows a field only when the row's selected type is in this list.
 *
 * @param array $types Block type slugs this field belongs to.
 * @return string HTML attribute string (leading space included).
 */
function themify_homepage_field_scope( array $types ) {
	return ' data-for="' . esc_attr( implode( ' ', array_map( 'sanitize_key', $types ) ) ) . '"';
}

/**
 * Render a scoped single-line text field inside a block row.
 *
 * @param string $name  Field name attribute.
 * @param string $label Field label.
 * @param string $value Current value.
 * @param string $ph    Placeholder.
 * @param array  $types Block types this field applies to.
 */
function themify_homepage_field_text( $name, $label, $value, $ph, array $types ) {
	echo '<div class="tf-field tf-field--text tf-block-field"' . themify_homepage_field_scope( $types ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- scope attr built with esc_attr.
	printf( '<label class="tf-field__label">%s</label>', esc_html( $label ) );
	printf(
		'<input type="text" name="%s" value="%s" class="tf-input" placeholder="%s" />',
		esc_attr( $name ),
		esc_attr( $value ),
		esc_attr( $ph )
	);
	echo '</div>';
}

/**
 * Render a scoped URL field inside a block row.
 *
 * @param string $name  Field name attribute.
 * @param string $label Field label.
 * @param string $value Current value.
 * @param array  $types Block types this field applies to.
 */
function themify_homepage_field_url( $name, $label, $value, array $types ) {
	echo '<div class="tf-field tf-field--url tf-block-field"' . themify_homepage_field_scope( $types ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- scope attr built with esc_attr.
	printf( '<label class="tf-field__label">%s</label>', esc_html( $label ) );
	printf(
		'<input type="url" name="%s" value="%s" class="tf-input" placeholder="%s" />',
		esc_attr( $name ),
		esc_attr( $value ),
		esc_attr__( 'https://…', 'themify' )
	);
	echo '</div>';
}

/**
 * Render a scoped number field inside a block row.
 *
 * @param string $name  Field name attribute.
 * @param string $label Field label.
 * @param int    $value Current value.
 * @param string $desc  Help text.
 * @param array  $types Block types this field applies to.
 */
function themify_homepage_field_number( $name, $label, $value, $desc, array $types ) {
	echo '<div class="tf-field tf-field--number tf-block-field"' . themify_homepage_field_scope( $types ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- scope attr built with esc_attr.
	printf( '<label class="tf-field__label">%s</label>', esc_html( $label ) );
	printf(
		'<input type="number" name="%s" value="%s" class="tf-input" min="0" step="1" style="max-width:160px;" />',
		esc_attr( $name ),
		esc_attr( (string) $value )
	);
	if ( $desc ) {
		echo '<p class="tf-field__desc">' . esc_html( $desc ) . '</p>';
	}
	echo '</div>';
}

/**
 * Render a scoped select field inside a block row.
 *
 * @param string $name    Field name attribute.
 * @param string $label   Field label.
 * @param string $value   Current value.
 * @param array  $options value => label map.
 * @param array  $types   Block types this field applies to.
 */
function themify_homepage_field_select( $name, $label, $value, array $options, array $types ) {
	echo '<div class="tf-field tf-field--select tf-block-field"' . themify_homepage_field_scope( $types ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- scope attr built with esc_attr.
	printf( '<label class="tf-field__label">%s</label>', esc_html( $label ) );
	printf( '<select name="%s" class="tf-input tf-select">', esc_attr( $name ) );
	foreach ( $options as $ov => $ol ) {
		printf(
			'<option value="%s" %s>%s</option>',
			esc_attr( $ov ),
			selected( $value, $ov, false ),
			esc_html( $ol )
		);
	}
	echo '</select>';
	echo '</div>';
}

/**
 * Render a scoped category dropdown inside a block row.
 *
 * @param string $name  Field name attribute.
 * @param string $label Field label.
 * @param int    $value Selected term id.
 * @param array  $types Block types this field applies to.
 */
function themify_homepage_field_category( $name, $label, $value, array $types ) {
	echo '<div class="tf-field tf-field--select tf-block-field"' . themify_homepage_field_scope( $types ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- scope attr built with esc_attr.
	printf( '<label class="tf-field__label">%s</label>', esc_html( $label ) );

	$terms = get_terms( array(
		'taxonomy'   => 'category',
		'hide_empty' => false,
		'number'     => 500,
		'orderby'    => 'name',
		'order'      => 'ASC',
	) );

	printf( '<select name="%s" class="tf-input tf-select">', esc_attr( $name ) );
	printf(
		'<option value="0" %s>%s</option>',
		selected( (int) $value, 0, false ),
		esc_html__( '— Select a category —', 'themify' )
	);
	if ( ! is_wp_error( $terms ) ) {
		foreach ( $terms as $term ) {
			printf(
				'<option value="%d" %s>%s</option>',
				(int) $term->term_id,
				selected( (int) $value, (int) $term->term_id, false ),
				esc_html( $term->name )
			);
		}
	}
	echo '</select>';
	echo '<p class="tf-field__desc">' . esc_html__( 'Only used when the source is “Posts from a category”.', 'themify' ) . '</p>';
	echo '</div>';
}

/**
 * Render a scoped rich-text (HTML) textarea inside a block row.
 *
 * @param string $name  Field name attribute.
 * @param string $label Field label.
 * @param string $value Current value (raw HTML).
 * @param array  $types Block types this field applies to.
 */
function themify_homepage_field_richtext( $name, $label, $value, array $types ) {
	echo '<div class="tf-field tf-field--textarea tf-block-field"' . themify_homepage_field_scope( $types ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- scope attr built with esc_attr.
	printf( '<label class="tf-field__label">%s</label>', esc_html( $label ) );
	printf(
		'<textarea name="%s" rows="8" class="tf-input tf-textarea" placeholder="%s">%s</textarea>',
		esc_attr( $name ),
		esc_attr__( 'Write anything — basic HTML is allowed.', 'themify' ),
		esc_textarea( $value )
	);
	echo '<p class="tf-field__desc">' . esc_html__( 'Basic HTML is allowed (headings, lists, links, images). Scripts are stripped.', 'themify' ) . '</p>';
	echo '</div>';
}

/**
 * Render a scoped banner-gallery control inside a block row (or standalone).
 * The "+ Add images" button opens the WordPress media library (multi-select);
 * admin.js appends a thumbnail + hidden input per chosen image. Any number of
 * images is allowed; 2+ become an auto-rotating slider on the front end.
 *
 * @param string $name  Base field name (values submit as $name[]).
 * @param string $label Field label.
 * @param array  $urls  Current image URLs.
 * @param array  $types Block types this field applies to (empty = always show).
 */
function themify_homepage_field_gallery( $name, $label, array $urls, array $types = array() ) {
	$scope = empty( $types ) ? '' : themify_homepage_field_scope( $types );
	echo '<div class="tf-field tf-field--gallery tf-block-field"' . $scope . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- scope built with esc_attr.
	printf( '<label class="tf-field__label">%s</label>', esc_html( $label ) );
	printf( '<div class="tf-gallery" data-name="%s">', esc_attr( $name ) );
	echo '<div class="tf-gallery__items">';
	foreach ( $urls as $u ) {
		$u = trim( (string) $u );
		if ( '' === $u ) {
			continue;
		}
		printf(
			'<div class="tf-gallery__item"><img src="%s" alt="" /><input type="hidden" name="%s[]" value="%s" /><button type="button" class="tf-gallery__remove" aria-label="%s">&times;</button></div>',
			esc_url( $u ),
			esc_attr( $name ),
			esc_url( $u ),
			esc_attr__( 'Remove image', 'themify' )
		);
	}
	echo '</div>'; // .tf-gallery__items
	printf( '<button type="button" class="button tf-gallery__add">%s</button>', esc_html__( '+ Add images', 'themify' ) );
	echo '<p class="tf-field__desc">' . esc_html__( 'Upload or pick any number of images. With 2 or more they auto-rotate as a slider. Leave empty for a clean gradient hero.', 'themify' ) . '</p>';
	echo '</div>'; // .tf-gallery
	echo '</div>'; // .tf-field
}

/**
 * Print the small inline stylesheet + script that powers the block manager:
 * it hides fields whose data-for does not include the row's selected type, and
 * keeps them in sync as the type dropdown changes (including on cloned rows).
 * Scoped to this admin screen, so it lives here rather than in the shared CSS.
 */
function themify_homepage_inline_assets() {
	?>
	<style>
		.tf-block-field[hidden] { display: none !important; }
		.tf-cat-grid-note { color: #6a7b72; font-size: 0.86rem; }
	</style>
	<script>
	( function () {
		function syncRow( row ) {
			var select = row.querySelector( '.tf-block-type' );
			if ( ! select ) { return; }
			var type = select.value;
			row.setAttribute( 'data-type', type );
			var fields = row.querySelectorAll( '.tf-block-field' );
			for ( var i = 0; i < fields.length; i++ ) {
				var scope = ( fields[ i ].getAttribute( 'data-for' ) || '' ).split( /\s+/ );
				fields[ i ].hidden = scope.indexOf( type ) === -1;
			}
		}
		function syncAll() {
			var rows = document.querySelectorAll( '.tf-block-row' );
			for ( var i = 0; i < rows.length; i++ ) { syncRow( rows[ i ] ); }
		}
		document.addEventListener( 'change', function ( e ) {
			if ( e.target && e.target.classList.contains( 'tf-block-type' ) ) {
				syncRow( e.target.closest( '.tf-block-row' ) );
			}
		} );
		// Newly cloned rows: re-sync after the repeater "add" handler runs.
		document.addEventListener( 'click', function ( e ) {
			if ( e.target && e.target.classList.contains( 'tf-repeater__add' ) ) {
				setTimeout( syncAll, 0 );
			}
		} );
		if ( document.readyState !== 'loading' ) {
			syncAll();
		} else {
			document.addEventListener( 'DOMContentLoaded', syncAll );
		}
	} )();
	</script>
	<?php
}

/**
 * Persist the Homepage Hero settings (its own option + nonce). Returns true on
 * a valid save so the page can show a notice.
 *
 * @return bool
 */
function themify_hero_handle_save() {
	if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
		return false;
	}
	if ( ! current_user_can( THEMIFY_CAP ) ) {
		return false;
	}
	$nonce = isset( $_POST['themify_hero_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['themify_hero_nonce'] ) ) : '';
	if ( ! wp_verify_nonce( $nonce, 'themify_hero' ) ) {
		return false;
	}

	$in = isset( $_POST['themify_hero'] ) && is_array( $_POST['themify_hero'] )
		? wp_unslash( $_POST['themify_hero'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each field sanitized below.
		: array();

	$hero = array(
		'heading'    => isset( $in['heading'] ) ? sanitize_text_field( $in['heading'] ) : '',
		'subheading' => isset( $in['subheading'] ) ? sanitize_text_field( $in['subheading'] ) : '',
		'cta1_text'  => isset( $in['cta1_text'] ) ? sanitize_text_field( $in['cta1_text'] ) : '',
		'cta1_url'   => isset( $in['cta1_url'] ) ? esc_url_raw( trim( (string) $in['cta1_url'] ) ) : '',
		'cta2_text'  => isset( $in['cta2_text'] ) ? sanitize_text_field( $in['cta2_text'] ) : '',
		'cta2_url'   => isset( $in['cta2_url'] ) ? esc_url_raw( trim( (string) $in['cta2_url'] ) ) : '',
		'bg'         => isset( $in['bg'] ) ? sanitize_text_field( $in['bg'] ) : '',
		'banners'    => isset( $in['banners'] ) ? themify_hero_sanitize_banners( $in['banners'] ) : array(),
		'autoplay'   => isset( $in['autoplay'] ) ? max( 1500, (int) $in['autoplay'] ) : 6000,
	);

	update_option( 'themify_hero', $hero );
	return true;
}

/**
 * Render the "Homepage Hero & banner slider" settings card + form. Controls the
 * hero shown on the automatic homepage; blank text falls back to site identity.
 */
function themify_hero_render_card() {
	$saved = get_option( 'themify_hero', array() );
	if ( ! is_array( $saved ) ) {
		$saved = array();
	}
	$g = function ( $k ) use ( $saved ) {
		return isset( $saved[ $k ] ) ? (string) $saved[ $k ] : '';
	};

	echo '<form method="post" class="tf-form">';
	wp_nonce_field( 'themify_hero', 'themify_hero_nonce' );
	echo '<div class="tf-card">';
	echo '<h2 class="tf-card__title">' . esc_html__( 'Homepage hero & banner slider', 'themify' ) . '</h2>';
	echo '<p class="tf-card__desc">' . esc_html__( 'The big banner at the very top of your homepage. Add images to turn it into an auto-rotating slider — upload as many as you like. Leave images empty for a clean gradient hero. Blank text fields fall back to your site title and tagline.', 'themify' ) . '</p>';

	$text_field = function ( $key, $label, $ph ) use ( $g ) {
		echo '<div class="tf-field">';
		printf( '<label class="tf-field__label">%s</label>', esc_html( $label ) );
		printf(
			'<input type="text" name="themify_hero[%s]" value="%s" class="tf-input" placeholder="%s" />',
			esc_attr( $key ),
			esc_attr( $g( $key ) ),
			esc_attr( $ph )
		);
		echo '</div>';
	};

	$text_field( 'heading', __( 'Heading', 'themify' ), get_bloginfo( 'name' ) );
	$text_field( 'subheading', __( 'Subheading', 'themify' ), get_bloginfo( 'description' ) );
	$text_field( 'cta1_text', __( 'Primary button text', 'themify' ), __( 'Read the blog', 'themify' ) );
	$text_field( 'cta1_url', __( 'Primary button URL', 'themify' ), home_url( '/' ) );
	$text_field( 'cta2_text', __( 'Secondary button text (optional)', 'themify' ), __( 'e.g. About us', 'themify' ) );
	$text_field( 'cta2_url', __( 'Secondary button URL (optional)', 'themify' ), 'https://…' );

	// Banner gallery (always visible on this card — no type scoping).
	themify_homepage_field_gallery( 'themify_hero[banners]', __( 'Banner slider images', 'themify' ), themify_hero_clean_banners( $saved['banners'] ?? array() ) );

	// Autoplay speed.
	$autoplay = isset( $saved['autoplay'] ) ? (int) $saved['autoplay'] : 6000;
	echo '<div class="tf-field">';
	printf( '<label class="tf-field__label">%s</label>', esc_html__( 'Slider speed (milliseconds per slide)', 'themify' ) );
	printf(
		'<input type="number" name="themify_hero[autoplay]" value="%d" class="tf-input" min="1500" step="500" style="max-width:200px;" />',
		(int) $autoplay
	);
	echo '<p class="tf-field__desc">' . esc_html__( 'How long each image shows before sliding, in milliseconds. 6000 = 6 seconds.', 'themify' ) . '</p>';
	echo '</div>';

	$text_field( 'bg', __( 'Gradient / colour background (used only when there are no images)', 'themify' ), __( 'e.g. linear-gradient(135deg,#ecfaf1,#c8e8d2)', 'themify' ) );

	echo '</div>'; // .tf-card
	echo '<p class="tf-form__actions"><button type="submit" class="button button-primary button-hero">' . esc_html__( 'Save hero', 'themify' ) . '</button></p>';
	echo '</form>';
}

/**
 * Persist the homepage call-to-action band background (its own nonce, stored in
 * THEMIFY_OPT). Returns true on a valid save so the page can show a notice.
 *
 * @return bool
 */
function themify_cta_handle_save() {
	if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
		return false;
	}
	if ( ! current_user_can( THEMIFY_CAP ) ) {
		return false;
	}
	$nonce = isset( $_POST['themify_cta_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['themify_cta_nonce'] ) ) : '';
	if ( ! wp_verify_nonce( $nonce, 'themify_cta' ) ) {
		return false;
	}

	themify_set_options( array(
		'cta_enabled'     => isset( $_POST['cta_enabled'] ) ? '1' : '',
		'cta_heading'     => isset( $_POST['cta_heading'] ) ? sanitize_text_field( wp_unslash( $_POST['cta_heading'] ) ) : '',
		'cta_text'        => isset( $_POST['cta_text'] ) ? sanitize_text_field( wp_unslash( $_POST['cta_text'] ) ) : '',
		'cta_button_text' => isset( $_POST['cta_button_text'] ) ? sanitize_text_field( wp_unslash( $_POST['cta_button_text'] ) ) : '',
		'cta_button_url'  => isset( $_POST['cta_button_url'] ) ? esc_url_raw( trim( (string) wp_unslash( $_POST['cta_button_url'] ) ) ) : '',
		'cta_bg_image'    => isset( $_POST['cta_bg_image'] ) ? esc_url_raw( trim( (string) wp_unslash( $_POST['cta_bg_image'] ) ) ) : '',
		'cta_bg'          => isset( $_POST['cta_bg'] ) ? sanitize_text_field( wp_unslash( $_POST['cta_bg'] ) ) : '',
	) );
	return true;
}

/**
 * Render the "Call-to-action band" settings card: a background image picker plus
 * a colour / gradient field used when no image is set. These style the closing
 * CTA band on the homepage ("Explore more of …").
 */
function themify_cta_render_card() {
	$image = themify_get_option( 'cta_bg_image', '' );
	$color = themify_get_option( 'cta_bg', '' );

	echo '<form method="post" class="tf-form">';
	wp_nonce_field( 'themify_cta', 'themify_cta_nonce' );
	echo '<div class="tf-card">';
	echo '<h2 class="tf-card__title">' . esc_html__( 'Call-to-action band', 'themify' ) . '</h2>';
	echo '<p class="tf-card__desc">' . esc_html__( 'The coloured band near the bottom of the homepage. Everything here is editable — turn it off entirely, write your own heading/text/button, add a background image or colour. Blank text fields fall back to your site title and tagline.', 'themify' ) . '</p>';

	// On/off toggle.
	echo '<div class="tf-field tf-field--checkbox">';
	echo '<label class="tf-switch">';
	printf(
		'<input type="checkbox" name="cta_enabled" value="1" %s />',
		checked( themify_is_enabled( 'cta_enabled', true ), true, false )
	);
	echo '<span class="tf-switch__track"></span>';
	echo '<span class="tf-switch__label">' . esc_html__( 'Show the call-to-action band on the homepage', 'themify' ) . '</span>';
	echo '</label>';
	echo '</div>';

	// Heading / text / button — all optional, with site-identity fallbacks.
	$cta_text_field = function ( $key, $label, $ph ) {
		echo '<div class="tf-field tf-field--text">';
		printf( '<label class="tf-field__label">%s</label>', esc_html( $label ) );
		printf(
			'<input type="text" name="%s" value="%s" class="tf-input" placeholder="%s" />',
			esc_attr( $key ),
			esc_attr( (string) themify_get_option( $key, '' ) ),
			esc_attr( $ph )
		);
		echo '</div>';
	};
	/* translators: %s: site name */
	$cta_text_field( 'cta_heading', __( 'Heading', 'themify' ), sprintf( __( 'Explore more of %s', 'themify' ), get_bloginfo( 'name' ) ) );
	$cta_text_field( 'cta_text', __( 'Supporting text', 'themify' ), get_bloginfo( 'description' ) ? get_bloginfo( 'description' ) : __( 'Fresh guides, honest reviews and practical how-tos.', 'themify' ) );
	$cta_text_field( 'cta_button_text', __( 'Button text', 'themify' ), __( 'Read the blog', 'themify' ) );

	echo '<div class="tf-field tf-field--url">';
	printf( '<label class="tf-field__label">%s</label>', esc_html__( 'Button URL', 'themify' ) );
	printf(
		'<input type="url" name="cta_button_url" value="%s" class="tf-input" placeholder="%s" />',
		esc_attr( (string) themify_get_option( 'cta_button_url', '' ) ),
		esc_attr( home_url( '/blog/' ) )
	);
	echo '<p class="tf-field__desc">' . esc_html__( 'Leave blank to link to your blog page automatically.', 'themify' ) . '</p>';
	echo '</div>';

	// Background image (single media picker — admin.js wires the Choose/Remove buttons).
	echo '<div class="tf-field tf-field--media">';
	printf( '<label class="tf-field__label">%s</label>', esc_html__( 'Background image', 'themify' ) );
	printf(
		'<span class="tf-media"><img src="%s" class="tf-media__preview" alt=""%s /><input type="text" name="cta_bg_image" value="%s" class="tf-input tf-media__url" placeholder="%s" /><button type="button" class="button tf-media__pick">%s</button><button type="button" class="button-link tf-media__clear"%s>%s</button></span>',
		esc_url( $image ),
		$image ? '' : ' style="display:none"',
		esc_attr( $image ),
		esc_attr__( 'https://…/banner.jpg', 'themify' ),
		esc_html__( 'Choose', 'themify' ),
		$image ? '' : ' style="display:none"',
		esc_html__( 'Remove', 'themify' )
	);
	echo '<p class="tf-field__desc">' . esc_html__( 'The text stays white over a subtle dark overlay so it remains readable.', 'themify' ) . '</p>';
	echo '</div>';

	// Colour / gradient (used only when there's no image).
	echo '<div class="tf-field tf-field--text">';
	printf( '<label class="tf-field__label">%s</label>', esc_html__( 'Colour or gradient (used when there is no image)', 'themify' ) );
	printf(
		'<input type="text" name="cta_bg" value="%s" class="tf-input" placeholder="%s" />',
		esc_attr( $color ),
		esc_attr__( 'e.g. #e11d2a or linear-gradient(135deg,#e11d2a,#7a0f17)', 'themify' )
	);
	echo '<p class="tf-field__desc">' . esc_html__( 'Any CSS colour or gradient. Leave blank to use the theme accent colour.', 'themify' ) . '</p>';
	echo '</div>';

	echo '</div>'; // .tf-card
	echo '<p class="tf-form__actions"><button type="submit" class="button button-primary button-hero">' . esc_html__( 'Save CTA band', 'themify' ) . '</button></p>';
	echo '</form>';
}

/**
 * Persist the homepage search-bar settings (its own nonce, stored in
 * THEMIFY_OPT). Returns true on a valid save so the page can show a notice.
 *
 * @return bool
 */
function themify_home_search_handle_save() {
	if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
		return false;
	}
	if ( ! current_user_can( THEMIFY_CAP ) ) {
		return false;
	}
	$nonce = isset( $_POST['themify_home_search_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['themify_home_search_nonce'] ) ) : '';
	if ( ! wp_verify_nonce( $nonce, 'themify_home_search' ) ) {
		return false;
	}

	themify_set_options( array(
		'home_search_enabled'     => isset( $_POST['home_search_enabled'] ) ? '1' : '',
		'home_search_heading'     => isset( $_POST['home_search_heading'] ) ? sanitize_text_field( wp_unslash( $_POST['home_search_heading'] ) ) : '',
		'home_search_placeholder' => isset( $_POST['home_search_placeholder'] ) ? sanitize_text_field( wp_unslash( $_POST['home_search_placeholder'] ) ) : '',
	) );
	return true;
}

/**
 * Render the "Homepage search bar" settings card: an on/off toggle plus the
 * optional heading and placeholder wording.
 */
function themify_home_search_render_card() {
	echo '<form method="post" class="tf-form">';
	wp_nonce_field( 'themify_home_search', 'themify_home_search_nonce' );
	echo '<div class="tf-card">';
	echo '<h2 class="tf-card__title">' . esc_html__( 'Homepage search bar', 'themify' ) . '</h2>';
	echo '<p class="tf-card__desc">' . esc_html__( 'A big, centred search box shown right under the hero. Turn it on or off any time — the wording below is optional.', 'themify' ) . '</p>';

	echo '<div class="tf-field tf-field--checkbox">';
	echo '<label class="tf-switch">';
	printf(
		'<input type="checkbox" name="home_search_enabled" value="1" %s />',
		checked( themify_is_enabled( 'home_search_enabled', false ), true, false )
	);
	echo '<span class="tf-switch__track"></span>';
	echo '<span class="tf-switch__label">' . esc_html__( 'Show a search bar on the homepage', 'themify' ) . '</span>';
	echo '</label>';
	echo '</div>';

	echo '<div class="tf-field tf-field--text">';
	printf( '<label class="tf-field__label">%s</label>', esc_html__( 'Heading above the search box (optional)', 'themify' ) );
	printf(
		'<input type="text" name="home_search_heading" value="%s" class="tf-input" placeholder="%s" />',
		esc_attr( (string) themify_get_option( 'home_search_heading', '' ) ),
		esc_attr__( 'e.g. What are you looking for?', 'themify' )
	);
	echo '</div>';

	echo '<div class="tf-field tf-field--text">';
	printf( '<label class="tf-field__label">%s</label>', esc_html__( 'Placeholder text (optional)', 'themify' ) );
	printf(
		'<input type="text" name="home_search_placeholder" value="%s" class="tf-input" placeholder="%s" />',
		esc_attr( (string) themify_get_option( 'home_search_placeholder', '' ) ),
		esc_attr__( 'e.g. Search car guides, reviews…', 'themify' )
	);
	echo '</div>';

	echo '</div>'; // .tf-card
	echo '<p class="tf-form__actions"><button type="submit" class="button button-primary button-hero">' . esc_html__( 'Save search bar', 'themify' ) . '</button></p>';
	echo '</form>';
}

/**
 * Render the "Homepage" admin screen: the hero/banner card, an intro, then a
 * repeater of block rows with a hidden template for JS-added rows.
 */
function themify_homepage_page() {
	$hero_saved   = themify_hero_handle_save();
	$cta_saved    = themify_cta_handle_save();
	$search_saved = themify_home_search_handle_save();
	$saved        = themify_homepage_handle_save();

	themify_admin_header(
		__( 'Homepage', 'themify' ),
		__( 'Compose your front page from a stack of blocks — a hero, post grids, category showcases, rich text and call-to-action bands.', 'themify' )
	);

	if ( $hero_saved ) {
		echo '<div class="tf-notice tf-notice--info">' . esc_html__( 'Homepage hero saved.', 'themify' ) . '</div>';
	}
	if ( $cta_saved ) {
		echo '<div class="tf-notice tf-notice--info">' . esc_html__( 'Call-to-action band saved.', 'themify' ) . '</div>';
	}
	if ( $search_saved ) {
		echo '<div class="tf-notice tf-notice--info">' . esc_html__( 'Homepage search bar saved.', 'themify' ) . '</div>';
	}
	if ( $saved ) {
		echo '<div class="tf-notice tf-notice--info">' . esc_html__( 'Homepage blocks saved.', 'themify' ) . '</div>';
	}

	// Homepage hero + banner slider (controls the automatic homepage).
	themify_hero_render_card();

	// Homepage search bar (toggle + wording).
	themify_home_search_render_card();

	// Closing call-to-action band (toggle, texts, button, image or colour).
	themify_cta_render_card();

	// Intro / how-it-works card.
	echo '<div class="tf-card">';
	echo '<h2 class="tf-card__title">' . esc_html__( 'How the homepage works', 'themify' ) . '</h2>';
	echo '<p class="tf-card__desc">' . wp_kses_post( __( 'Add blocks below and they render top-to-bottom on your front page. Set the <strong>Order</strong> number to reorder — lower numbers appear first. Pick a <strong>Block type</strong> and only that type’s fields show.', 'themify' ) ) . '</p>';
	echo '<p class="tf-field__desc">' . wp_kses_post( __( 'This screen controls the front page only when your site shows a static homepage. Under <em>Settings → Reading</em>, set <em>Your homepage displays</em> to <em>A static page</em> (any page will do — this builder takes over its output).', 'themify' ) ) . '</p>';
	echo '<p class="tf-field__desc">' . esc_html__( 'Leave the list empty to fall back to an automatic homepage (site title hero + latest posts).', 'themify' ) . '</p>';
	echo '</div>';

	$blocks = themify_get_homepage_blocks();

	echo '<form method="post" class="tf-form">';
	wp_nonce_field( 'themify_homepage', 'themify_nonce' );

	echo '<div class="tf-card">';
	echo '<h2 class="tf-card__title">' . esc_html__( 'Blocks', 'themify' ) . '</h2>';

	echo '<div class="tf-repeater">';

	// Hidden template row — admin.js clones this and swaps __INDEX__ for the
	// next numeric index when "Add block" is clicked.
	echo '<script type="text/html" class="tf-repeater__template">';
	themify_homepage_render_row( '__INDEX__' );
	echo '</script>';

	echo '<div class="tf-repeater__rows">';
	if ( $blocks ) {
		foreach ( $blocks as $i => $block ) {
			themify_homepage_render_row( $i, $block );
		}
	}
	echo '</div>';

	echo '<p><button type="button" class="button tf-repeater__add">' . esc_html__( '+ Add block', 'themify' ) . '</button></p>';
	echo '</div>'; // .tf-repeater

	echo '</div>'; // .tf-card

	echo '<p class="tf-form__actions"><button type="submit" class="button button-primary button-hero">' . esc_html__( 'Save Changes', 'themify' ) . '</button></p>';
	echo '</form>';

	themify_homepage_inline_assets();

	themify_admin_footer();
}
