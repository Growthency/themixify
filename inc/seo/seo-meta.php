<?php
/**
 * SEO meta engine (Yoast-lite).
 *
 * A self-contained, opinionated SEO layer that outputs canonical URLs, robots
 * directives, meta descriptions, Open Graph + Twitter cards, article time
 * stamps and site-verification metas — plus a per-post meta box and a
 * settings screen for the site-wide defaults.
 *
 * The whole engine NO-OPS when a major SEO plugin (Yoast, Rank Math, All in
 * One SEO, The SEO Framework) is active, so it never double-prints tags or
 * fights another plugin. The schema module reuses the three getters at the
 * bottom (themify_get_seo_title / _desc / og_image) so the two modules always
 * agree on what to say about a post.
 *
 * @package Themify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Is a major third-party SEO plugin handling meta output?
 *
 * When true the theme's built-in SEO engine steps aside entirely (no title
 * filter, no head tags, no schema). The meta box + settings screen still load
 * so stored data is preserved, but nothing is emitted on the front end.
 *
 * @return bool
 */
function themify_seo_plugin_active() {
	return defined( 'WPSEO_VERSION' )
		|| defined( 'RANK_MATH_VERSION' )
		|| defined( 'AIOSEO_VERSION' )
		|| class_exists( 'The_SEO_Framework\\Load' );
}

/* ============================================================ HEAD OUTPUT */

/**
 * Emit all SEO <head> tags. Bound to wp_head at priority 1 so our canonical /
 * robots / description come before anything else. Bails on admin requests and
 * whenever another SEO plugin is active.
 */
function themify_seo_head() {
	if ( is_admin() || themify_seo_plugin_active() ) {
		return;
	}

	// We emit our own canonical below, so drop WordPress core's rel_canonical
	// (wp_head, priority 10) to avoid a duplicate <link rel="canonical">.
	remove_action( 'wp_head', 'rel_canonical' );

	$queried_id = ( is_singular() && ! is_front_page() ) ? get_queried_object_id() : 0;

	$canonical = themify_seo_canonical_url( $queried_id );
	$robots    = themify_seo_robots_value( $queried_id );
	$desc      = themify_get_seo_desc( $queried_id );
	$title     = themify_seo_full_title( $queried_id );
	$image     = themify_get_og_image( $queried_id );

	echo "\n<!-- Themify SEO -->\n";

	// Robots meta.
	if ( '' !== $robots ) {
		printf( "<meta name=\"robots\" content=\"%s\" />\n", esc_attr( $robots ) );
	}

	// Canonical.
	if ( $canonical ) {
		printf( "<link rel=\"canonical\" href=\"%s\" />\n", esc_url( $canonical ) );
	}

	// Meta description.
	if ( $desc ) {
		printf( "<meta name=\"description\" content=\"%s\" />\n", esc_attr( $desc ) );
	}

	// Open Graph.
	$og_type     = is_singular() ? 'article' : 'website';
	$site_name   = get_bloginfo( 'name' );
	$og_locale   = get_bloginfo( 'language' );
	$og_locale   = $og_locale ? str_replace( '-', '_', $og_locale ) : 'en_US';

	printf( "<meta property=\"og:type\" content=\"%s\" />\n", esc_attr( $og_type ) );
	printf( "<meta property=\"og:title\" content=\"%s\" />\n", esc_attr( $title ) );
	if ( $canonical ) {
		printf( "<meta property=\"og:url\" content=\"%s\" />\n", esc_url( $canonical ) );
	}
	if ( $site_name ) {
		printf( "<meta property=\"og:site_name\" content=\"%s\" />\n", esc_attr( $site_name ) );
	}
	printf( "<meta property=\"og:locale\" content=\"%s\" />\n", esc_attr( $og_locale ) );
	if ( $desc ) {
		printf( "<meta property=\"og:description\" content=\"%s\" />\n", esc_attr( $desc ) );
	}
	if ( $image ) {
		printf( "<meta property=\"og:image\" content=\"%s\" />\n", esc_url( $image ) );
	}

	// Twitter card.
	printf(
		"<meta name=\"twitter:card\" content=\"%s\" />\n",
		esc_attr( $image ? 'summary_large_image' : 'summary' )
	);
	printf( "<meta name=\"twitter:title\" content=\"%s\" />\n", esc_attr( $title ) );
	if ( $desc ) {
		printf( "<meta name=\"twitter:description\" content=\"%s\" />\n", esc_attr( $desc ) );
	}
	if ( $image ) {
		printf( "<meta name=\"twitter:image\" content=\"%s\" />\n", esc_url( $image ) );
	}
	$twitter_site = themify_get_option( 'twitter_site', '' );
	if ( $twitter_site ) {
		$twitter_site = '@' . ltrim( $twitter_site, '@' );
		printf( "<meta name=\"twitter:site\" content=\"%s\" />\n", esc_attr( $twitter_site ) );
	}

	// Article timestamps on singular content.
	if ( $queried_id ) {
		$published = get_post_time( 'c', true, $queried_id );
		$modified  = get_post_modified_time( 'c', true, $queried_id );
		if ( $published ) {
			printf( "<meta property=\"article:published_time\" content=\"%s\" />\n", esc_attr( $published ) );
		}
		if ( $modified ) {
			printf( "<meta property=\"article:modified_time\" content=\"%s\" />\n", esc_attr( $modified ) );
		}
	}

	// Site verification metas.
	themify_seo_verification_metas();

	echo "<!-- /Themify SEO -->\n";
}
add_action( 'wp_head', 'themify_seo_head', 1 );

/**
 * Print search-engine verification <meta> tags for any that are configured.
 * Values are stored as either the bare content token or the full tag; we take
 * only the token so owners can paste either form.
 */
function themify_seo_verification_metas() {
	$map = array(
		'google_verify'    => 'google-site-verification',
		'bing_verify'      => 'msvalidate.01',
		'pinterest_verify' => 'p:domain_verify',
		'yandex_verify'    => 'yandex-verification',
	);

	foreach ( $map as $opt_key => $meta_name ) {
		$raw = themify_get_option( $opt_key, '' );
		if ( '' === $raw ) {
			continue;
		}
		// Accept a pasted full <meta> tag by extracting its content attribute.
		if ( false !== stripos( $raw, 'content=' ) && preg_match( '/content=("|\')([^"\']+)\1/i', $raw, $m ) ) {
			$raw = $m[2];
		}
		$token = sanitize_text_field( $raw );
		if ( '' === $token ) {
			continue;
		}
		printf(
			"<meta name=\"%s\" content=\"%s\" />\n",
			esc_attr( $meta_name ),
			esc_attr( $token )
		);
	}
}

/**
 * Best canonical URL for the current request.
 *
 * @param int $post_id Queried singular post ID (0 for non-singular).
 * @return string
 */
function themify_seo_canonical_url( $post_id = 0 ) {
	if ( is_front_page() ) {
		return home_url( '/' );
	}
	if ( $post_id ) {
		$link = get_permalink( $post_id );
		return $link ? $link : '';
	}
	if ( is_category() || is_tag() || is_tax() ) {
		$link = get_term_link( get_queried_object() );
		return is_wp_error( $link ) ? '' : $link;
	}
	if ( is_author() ) {
		return get_author_posts_url( (int) get_query_var( 'author' ) );
	}
	if ( is_post_type_archive() ) {
		$link = get_post_type_archive_link( get_query_var( 'post_type' ) );
		return $link ? $link : '';
	}
	if ( is_search() ) {
		return get_search_link();
	}
	if ( is_home() ) {
		$blog_page = (int) get_option( 'page_for_posts' );
		if ( $blog_page ) {
			$link = get_permalink( $blog_page );
			return $link ? $link : home_url( '/' );
		}
		return home_url( '/' );
	}
	// Date / other archives: canonicalise to the paged request URL sans query.
	$request = home_url( add_query_arg( array() ) );
	$request = strtok( $request, '?' );
	return $request ? $request : '';
}

/**
 * Compute the robots meta value for the current request.
 *
 * Honours a per-post noindex flag, the noindex_archives / noindex_search
 * toggles for taxonomy/author/date archives and search, plus WordPress core's
 * blog_public option. Returns '' when nothing needs saying (which lets search
 * engines use their default of index,follow).
 *
 * @param int $post_id Queried singular post ID (0 for non-singular).
 * @return string
 */
function themify_seo_robots_value( $post_id = 0 ) {
	$index = true;

	// Site-wide discourage-search-engines core setting always wins.
	if ( '0' === (string) get_option( 'blog_public' ) ) {
		$index = false;
	}

	if ( $index ) {
		if ( $post_id ) {
			// Per-post noindex checkbox.
			if ( '1' === (string) get_post_meta( $post_id, '_themify_noindex', true ) ) {
				$index = false;
			}
		} elseif ( is_search() ) {
			if ( themify_is_enabled( 'noindex_search', true ) ) {
				$index = false;
			}
		} elseif ( is_category() || is_tag() || is_tax() || is_author() || is_date() || is_post_type_archive() ) {
			if ( themify_is_enabled( 'noindex_archives', false ) ) {
				$index = false;
			}
		} elseif ( is_paged() ) {
			// Deep pagination of the posts page: index but keep following.
			$index = true;
		}
	}

	if ( $index ) {
		// Only emit an explicit directive when there is something worth saying
		// (max-image-preview helps rich results). Follow is implied.
		return 'index, follow, max-image-preview:large';
	}
	return 'noindex, follow';
}

/* ============================================================ TITLE FILTER */

/**
 * Filter the pieces WordPress assembles into the document title so a custom
 * per-post SEO title (or the homepage title from options) takes over.
 *
 * @param array $parts Title parts (title, page, tagline, site).
 * @return array
 */
function themify_seo_title_parts( $parts ) {
	if ( themify_seo_plugin_active() ) {
		return $parts;
	}

	if ( is_front_page() ) {
		$home = themify_get_option( 'home_title', '' );
		if ( $home ) {
			return array( 'title' => $home );
		}
		return $parts;
	}

	if ( is_singular() ) {
		$custom = get_post_meta( get_queried_object_id(), '_themify_seo_title', true );
		if ( $custom ) {
			// A full custom SEO title replaces everything; owners include their
			// own brand suffix if they want one.
			return array( 'title' => $custom );
		}
	}

	// Optionally drop the "– Site Name" suffix so post/page titles stay inside
	// the ~60-character limit Google actually displays. Archives keep the site
	// name — their own titles ("Car Battery") are too short without it.
	if ( is_singular() && ! themify_is_enabled( 'seo_title_suffix', true ) ) {
		unset( $parts['site'], $parts['tagline'] );
	}

	return $parts;
}
add_filter( 'document_title_parts', 'themify_seo_title_parts' );

/**
 * Change the separator used between title parts (option seo_separator, default
 * en-dash). Applied via document_title_separator.
 *
 * @param string $sep Default separator.
 * @return string
 */
function themify_seo_title_separator( $sep ) {
	if ( themify_seo_plugin_active() ) {
		return $sep;
	}
	$custom = themify_get_option( 'seo_separator', '' );
	return $custom ? $custom : $sep;
}
add_filter( 'document_title_separator', 'themify_seo_title_separator' );

/**
 * Short-circuit the whole document title with a fully custom string when we
 * have one, so themes that don't call wp_get_document_title() through the
 * theme-support path still get our title.
 *
 * @param string $title Pre-computed title (empty by default).
 * @return string
 */
function themify_seo_pre_document_title( $title ) {
	if ( '' !== $title || themify_seo_plugin_active() ) {
		return $title;
	}

	if ( is_front_page() ) {
		$home = themify_get_option( 'home_title', '' );
		if ( $home ) {
			return $home;
		}
	} elseif ( is_singular() ) {
		$custom = get_post_meta( get_queried_object_id(), '_themify_seo_title', true );
		if ( $custom ) {
			return $custom;
		}
	}

	return $title;
}
add_filter( 'pre_get_document_title', 'themify_seo_pre_document_title' );

/**
 * The full page title as we would want it in <og:title> / <twitter:title>.
 * Mirrors wp_get_document_title() but is safe to call from wp_head.
 *
 * @param int $post_id Queried singular post ID (0 for non-singular).
 * @return string
 */
function themify_seo_full_title( $post_id = 0 ) {
	if ( is_front_page() ) {
		$home = themify_get_option( 'home_title', '' );
		if ( $home ) {
			return $home;
		}
	} elseif ( $post_id ) {
		$custom = get_post_meta( $post_id, '_themify_seo_title', true );
		if ( $custom ) {
			return $custom;
		}
	}
	// Fall back to WordPress' own assembled title.
	return wp_strip_all_tags( wp_get_document_title() );
}

/* ============================================================ META BOX */

/**
 * Register the "Themify SEO" meta box on posts and pages.
 */
function themify_seo_add_meta_box() {
	foreach ( array( 'post', 'page' ) as $screen ) {
		add_meta_box(
			'themify_seo',
			__( 'Themify SEO', 'themify' ),
			'themify_seo_render_meta_box',
			$screen,
			'normal',
			'high'
		);
	}
}
add_action( 'add_meta_boxes', 'themify_seo_add_meta_box' );

/**
 * Render the SEO meta box UI.
 *
 * @param WP_Post $post Current post.
 */
function themify_seo_render_meta_box( $post ) {
	wp_nonce_field( 'themify_seo_save', 'themify_seo_nonce' );

	$seo_title = (string) get_post_meta( $post->ID, '_themify_seo_title', true );
	$seo_desc  = (string) get_post_meta( $post->ID, '_themify_seo_desc', true );
	$focus_kw  = (string) get_post_meta( $post->ID, '_themify_focus_kw', true );
	$og_image  = (string) get_post_meta( $post->ID, '_themify_og_image', true );
	$noindex   = '1' === (string) get_post_meta( $post->ID, '_themify_noindex', true );

	$title_max = 60;
	$desc_max  = 155;
	?>
	<div class="themify-seo-box">
		<style>
			.themify-seo-box .tf-seo-row { margin: 0 0 16px; }
			.themify-seo-box label.tf-seo-label { display: block; font-weight: 600; margin-bottom: 4px; }
			.themify-seo-box input[type="text"].tf-seo-input,
			.themify-seo-box textarea.tf-seo-input { width: 100%; }
			.themify-seo-box .tf-seo-counter { float: right; font-weight: 400; color: #6a7b72; font-size: 12px; }
			.themify-seo-box .tf-seo-counter.is-over { color: #c0392b; }
			.themify-seo-box .tf-seo-hint { color: #6a7b72; font-size: 12px; margin: 4px 0 0; }
			.themify-seo-box .tf-seo-media { display: flex; gap: 8px; align-items: center; }
			.themify-seo-box .tf-seo-media input { flex: 1; }
			.themify-seo-box .tf-seo-check { display: flex; gap: 8px; align-items: center; }
		</style>

		<div class="tf-seo-row">
			<label class="tf-seo-label" for="themify_seo_title">
				<?php esc_html_e( 'SEO title', 'themify' ); ?>
				<span class="tf-seo-counter" data-max="<?php echo esc_attr( $title_max ); ?>" data-for="themify_seo_title"></span>
			</label>
			<input type="text" class="tf-seo-input" id="themify_seo_title" name="themify_seo_title"
				value="<?php echo esc_attr( $seo_title ); ?>"
				placeholder="<?php echo esc_attr( wp_strip_all_tags( get_the_title( $post ) ) ); ?>" />
			<p class="tf-seo-hint"><?php esc_html_e( 'Overrides the browser tab title and social title. Aim for under 60 characters.', 'themify' ); ?></p>
		</div>

		<div class="tf-seo-row">
			<label class="tf-seo-label" for="themify_seo_desc">
				<?php esc_html_e( 'Meta description', 'themify' ); ?>
				<span class="tf-seo-counter" data-max="<?php echo esc_attr( $desc_max ); ?>" data-for="themify_seo_desc"></span>
			</label>
			<textarea class="tf-seo-input" id="themify_seo_desc" name="themify_seo_desc" rows="3"
				placeholder="<?php esc_attr_e( 'Falls back to the excerpt, then the site tagline.', 'themify' ); ?>"><?php echo esc_textarea( $seo_desc ); ?></textarea>
			<p class="tf-seo-hint"><?php esc_html_e( 'The snippet search engines and social networks show. Around 155 characters is ideal.', 'themify' ); ?></p>
		</div>

		<div class="tf-seo-row">
			<label class="tf-seo-label" for="themify_focus_kw"><?php esc_html_e( 'Focus keyword', 'themify' ); ?></label>
			<input type="text" class="tf-seo-input" id="themify_focus_kw" name="themify_focus_kw"
				value="<?php echo esc_attr( $focus_kw ); ?>"
				placeholder="<?php esc_attr_e( 'e.g. best hiking boots', 'themify' ); ?>" />
			<p class="tf-seo-hint"><?php esc_html_e( 'The main phrase you want this content to rank for.', 'themify' ); ?></p>
		</div>

		<div class="tf-seo-row">
			<label class="tf-seo-label" for="themify_og_image"><?php esc_html_e( 'Social share image', 'themify' ); ?></label>
			<span class="tf-seo-media">
				<input type="text" class="tf-seo-input" id="themify_og_image" name="themify_og_image"
					value="<?php echo esc_attr( $og_image ); ?>"
					placeholder="<?php esc_attr_e( 'Defaults to the featured image', 'themify' ); ?>" />
				<button type="button" class="button" id="themify_og_image_pick"><?php esc_html_e( 'Choose', 'themify' ); ?></button>
			</span>
			<p class="tf-seo-hint"><?php esc_html_e( 'The image used when the post is shared on Facebook, X and others. 1200×630 works best.', 'themify' ); ?></p>
		</div>

		<div class="tf-seo-row tf-seo-check">
			<input type="checkbox" id="themify_noindex" name="themify_noindex" value="1" <?php checked( $noindex ); ?> />
			<label for="themify_noindex"><?php esc_html_e( 'Hide this content from search engines (noindex)', 'themify' ); ?></label>
		</div>
	</div>

	<script>
	( function () {
		var box = document.querySelector( '.themify-seo-box' );
		if ( ! box ) { return; }

		// Live character counters.
		box.querySelectorAll( '.tf-seo-counter' ).forEach( function ( counter ) {
			var target = document.getElementById( counter.getAttribute( 'data-for' ) );
			var max    = parseInt( counter.getAttribute( 'data-max' ), 10 ) || 0;
			if ( ! target ) { return; }
			var update = function () {
				var len = target.value.length;
				counter.textContent = len + ' / ' + max;
				counter.classList.toggle( 'is-over', max > 0 && len > max );
			};
			target.addEventListener( 'input', update );
			update();
		} );

		// Media picker (self-contained; admin.js is not loaded on this screen).
		var pick = document.getElementById( 'themify_og_image_pick' );
		var url  = document.getElementById( 'themify_og_image' );
		if ( pick && url && window.wp && window.wp.media ) {
			var frame;
			pick.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				if ( frame ) { frame.open(); return; }
				frame = window.wp.media( { title: '<?php echo esc_js( __( 'Select share image', 'themify' ) ); ?>', multiple: false } );
				frame.on( 'select', function () {
					var att = frame.state().get( 'selection' ).first().toJSON();
					url.value = att.url;
				} );
				frame.open();
			} );
		}
	} )();
	</script>
	<?php
}

/**
 * Persist the meta box fields on save. Guards autosave, verifies the dedicated
 * nonce and the edit capability for the specific post.
 *
 * @param int $post_id Post being saved.
 */
function themify_seo_save_meta_box( $post_id ) {
	// Autosave / revision guards.
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}

	// Nonce.
	$nonce = isset( $_POST['themify_seo_nonce'] )
		? sanitize_text_field( wp_unslash( $_POST['themify_seo_nonce'] ) )
		: '';
	if ( ! wp_verify_nonce( $nonce, 'themify_seo_save' ) ) {
		return;
	}

	// Capability for this specific post.
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	// SEO title.
	$title = isset( $_POST['themify_seo_title'] )
		? sanitize_text_field( wp_unslash( $_POST['themify_seo_title'] ) )
		: '';
	themify_seo_update_or_delete_meta( $post_id, '_themify_seo_title', $title );

	// Meta description.
	$desc = isset( $_POST['themify_seo_desc'] )
		? sanitize_textarea_field( wp_unslash( $_POST['themify_seo_desc'] ) )
		: '';
	themify_seo_update_or_delete_meta( $post_id, '_themify_seo_desc', $desc );

	// Focus keyword.
	$kw = isset( $_POST['themify_focus_kw'] )
		? sanitize_text_field( wp_unslash( $_POST['themify_focus_kw'] ) )
		: '';
	themify_seo_update_or_delete_meta( $post_id, '_themify_focus_kw', $kw );

	// OG image URL.
	$og = isset( $_POST['themify_og_image'] )
		? esc_url_raw( wp_unslash( $_POST['themify_og_image'] ) )
		: '';
	themify_seo_update_or_delete_meta( $post_id, '_themify_og_image', $og );

	// Noindex checkbox.
	$noindex = ! empty( $_POST['themify_noindex'] ) ? '1' : '';
	themify_seo_update_or_delete_meta( $post_id, '_themify_noindex', $noindex );
}
add_action( 'save_post', 'themify_seo_save_meta_box' );

/**
 * Store a meta value, deleting the row entirely when empty to keep post meta
 * tidy (and so "empty" reliably means "fall back to the default").
 *
 * @param int    $post_id Post ID.
 * @param string $key     Meta key.
 * @param string $value   Sanitized value.
 */
function themify_seo_update_or_delete_meta( $post_id, $key, $value ) {
	if ( '' === $value || null === $value ) {
		delete_post_meta( $post_id, $key );
	} else {
		update_post_meta( $post_id, $key, $value );
	}
}

/* ============================================================ SHARED GETTERS */

/**
 * The SEO title for a post: custom title → post title.
 *
 * @param int $post_id Post ID.
 * @return string
 */
function themify_get_seo_title( $post_id = 0 ) {
	$post_id = $post_id ? $post_id : get_the_ID();
	if ( ! $post_id ) {
		return get_bloginfo( 'name' );
	}
	$custom = get_post_meta( $post_id, '_themify_seo_title', true );
	if ( $custom ) {
		return $custom;
	}
	return wp_strip_all_tags( get_the_title( $post_id ) );
}

/**
 * The meta description for the current context: per-post SEO description →
 * post excerpt → homepage description option (front page) → site tagline.
 * Trimmed to a natural ~155-character length on a word boundary.
 *
 * @param int $post_id Queried singular post ID (0 for non-singular / archives).
 * @return string
 */
function themify_get_seo_desc( $post_id = 0 ) {
	$desc = '';

	if ( $post_id ) {
		$desc = (string) get_post_meta( $post_id, '_themify_seo_desc', true );
		if ( '' === $desc ) {
			$post = get_post( $post_id );
			if ( $post ) {
				if ( ! empty( $post->post_excerpt ) ) {
					$desc = $post->post_excerpt;
				} else {
					$desc = wp_strip_all_tags( strip_shortcodes( $post->post_content ) );
				}
			}
		}
	} elseif ( is_front_page() ) {
		$desc = themify_get_option( 'home_desc', '' );
	} elseif ( is_category() || is_tag() || is_tax() ) {
		$term = get_queried_object();
		if ( $term && ! empty( $term->description ) ) {
			$desc = $term->description;
		} elseif ( $term && ! empty( $term->name ) ) {
			// Auto description so empty archives still get a full-length snippet.
			$desc = sprintf(
				/* translators: 1: category name, 2: site name */
				__( 'Browse every %1$s article on %2$s — practical guides, honest reviews and step-by-step how-tos, updated regularly.', 'themify' ),
				$term->name,
				get_bloginfo( 'name' )
			);
		}
	} elseif ( is_author() ) {
		$desc = get_the_author_meta( 'description', (int) get_query_var( 'author' ) );
		if ( '' === trim( (string) $desc ) ) {
			$desc = sprintf(
				/* translators: 1: author name, 2: site name */
				__( 'Read every article written by %1$s on %2$s — practical guides, honest reviews and step-by-step how-tos.', 'themify' ),
				get_the_author_meta( 'display_name', (int) get_query_var( 'author' ) ),
				get_bloginfo( 'name' )
			);
		}
	}

	// Site tagline as the universal fallback.
	if ( '' === trim( (string) $desc ) ) {
		$desc = get_bloginfo( 'description' );
	}

	$desc = wp_strip_all_tags( (string) $desc );
	$desc = preg_replace( '/\s+/', ' ', $desc );
	$desc = trim( $desc );

	return themify_seo_trim_words( $desc, 155 );
}

/**
 * The Open Graph / share image for a post: custom OG image → featured image →
 * site-wide default_og_image option.
 *
 * @param int $post_id Post ID (0 for non-singular).
 * @return string Image URL or ''.
 */
function themify_get_og_image( $post_id = 0 ) {
	if ( $post_id ) {
		$custom = get_post_meta( $post_id, '_themify_og_image', true );
		if ( $custom ) {
			return $custom;
		}
		if ( has_post_thumbnail( $post_id ) ) {
			$src = wp_get_attachment_image_url( get_post_thumbnail_id( $post_id ), 'large' );
			if ( $src ) {
				return $src;
			}
		}
	}
	return themify_get_option( 'default_og_image', '' );
}

/**
 * Trim a string to at most $limit characters without cutting a word in half,
 * appending an ellipsis when truncated.
 *
 * @param string $text  Source text.
 * @param int    $limit Max characters.
 * @return string
 */
function themify_seo_trim_words( $text, $limit = 155 ) {
	$text = trim( (string) $text );
	if ( '' === $text || mb_strlen( $text ) <= $limit ) {
		return $text;
	}
	$clipped = mb_substr( $text, 0, $limit );
	$space   = mb_strrpos( $clipped, ' ' );
	if ( false !== $space && $space > 0 ) {
		$clipped = mb_substr( $clipped, 0, $space );
	}
	return rtrim( $clipped, " \t\n\r\0\x0B.,;:" ) . '…';
}

/* ============================================================ ADMIN PAGE */

themify_register_admin_page( array(
	'slug'       => 'themify-seo',
	'title'      => __( 'SEO', 'themify' ),
	'menu_title' => __( 'SEO', 'themify' ),
	'callback'   => 'themify_seo_settings_page',
	'position'   => 40,
) );

add_filter( 'themify_dashboard_cards', 'themify_seo_dashboard_card' );

/**
 * Add the SEO card to the Themify dashboard grid.
 *
 * @param array $cards Existing cards.
 * @return array
 */
function themify_seo_dashboard_card( $cards ) {
	$cards[] = array(
		'slug'     => 'themify-seo',
		'title'    => __( 'SEO', 'themify' ),
		'desc'     => __( 'Titles, meta, Open Graph, robots & verification', 'themify' ),
		'icon'     => 'dashicons-search',
		'position' => 40,
	);
	return $cards;
}

/**
 * Render the SEO settings screen using the declarative renderer.
 */
function themify_seo_settings_page() {
	$intro = __( 'Site-wide SEO defaults. Per-post titles, descriptions and images are set in the "Themify SEO" box on each post.', 'themify' );

	if ( themify_seo_plugin_active() ) {
		$intro = __( 'A third-party SEO plugin is active, so Themify\'s built-in SEO output is turned off to avoid conflicts. These settings are kept but not used on the front end.', 'themify' );
	}

	themify_render_settings_page( array(
		'title'  => __( 'SEO', 'themify' ),
		'intro'  => $intro,
		'nonce'  => 'themify_seo_settings',
		'groups' => array(
			array(
				'title'  => __( 'General', 'themify' ),
				'desc'   => __( 'Defaults used across the site and for the homepage.', 'themify' ),
				'fields' => array(
					array(
						'key'         => 'seo_separator',
						'label'       => __( 'Title separator', 'themify' ),
						'type'        => 'text',
						'default'     => '–',
						'placeholder' => '–',
						'desc'        => __( 'Character shown between the page title and the site name, e.g. Post Title – Site Name.', 'themify' ),
					),
					array(
						'key'     => 'seo_title_suffix',
						'label'   => __( 'Append site name to titles', 'themify' ),
						'type'    => 'checkbox',
						'default' => '1',
						'desc'    => __( 'Adds “– Site Name” after every page title. Turn OFF to keep titles short — Google truncates titles longer than ~60 characters.', 'themify' ),
					),
					array(
						'key'         => 'home_title',
						'label'       => __( 'Homepage title', 'themify' ),
						'type'        => 'text',
						'placeholder' => wp_strip_all_tags( get_bloginfo( 'name' ) ),
						'desc'        => __( 'The full title tag for the front page. Leave blank to use the WordPress default.', 'themify' ),
					),
					array(
						'key'         => 'home_desc',
						'label'       => __( 'Homepage meta description', 'themify' ),
						'type'        => 'textarea',
						'rows'        => 3,
						'placeholder' => wp_strip_all_tags( get_bloginfo( 'description' ) ),
						'desc'        => __( 'Description used for the front page. Falls back to the site tagline.', 'themify' ),
					),
					array(
						'key'   => 'default_og_image',
						'label' => __( 'Default social image', 'themify' ),
						'type'  => 'media',
						'desc'  => __( 'Used for sharing when a post has no share image and no featured image. 1200×630 recommended.', 'themify' ),
					),
					array(
						'key'         => 'twitter_site',
						'label'       => __( 'X / Twitter username', 'themify' ),
						'type'        => 'text',
						'placeholder' => '@yourhandle',
						'desc'        => __( 'Added as twitter:site on shared pages. The @ is optional.', 'themify' ),
					),
				),
			),
			array(
				'title'  => __( 'Indexing & robots', 'themify' ),
				'desc'   => __( 'Control which pages search engines are allowed to index.', 'themify' ),
				'fields' => array(
					array(
						'key'     => 'noindex_archives',
						'label'   => __( 'Noindex tag, category, author and date archives', 'themify' ),
						'type'    => 'checkbox',
						'default' => '',
						'desc'    => __( 'Keeps thin archive pages out of search results while still following their links.', 'themify' ),
					),
					array(
						'key'     => 'noindex_search',
						'label'   => __( 'Noindex internal search result pages', 'themify' ),
						'type'    => 'checkbox',
						'default' => '1',
						'desc'    => __( 'Recommended. Search result pages are low value for search engines.', 'themify' ),
					),
				),
			),
			array(
				'title'  => __( 'XML sitemap', 'themify' ),
				'desc'   => __( 'Themixify\'s own styled sitemap — one file, paginated with Previous/Next, instead of dozens of sitemap parts.', 'themify' ),
				'fields' => array(
					array(
						'key'     => 'sitemap_custom_enabled',
						'label'   => __( 'Pretty paginated XML sitemap', 'themify' ),
						'type'    => 'checkbox',
						'default' => '1',
						'desc'    => __( 'Serves a styled sitemap at /sitemap.xml with Previous/Next pages, plus /sitemap_index.xml for search engines (robots.txt and the Search Console submit follow automatically). Turn OFF to fall back to the plain WordPress core sitemap.', 'themify' ),
					),
					array(
						'key'     => 'sitemap_per_page',
						'label'   => __( 'URLs per sitemap page', 'themify' ),
						'type'    => 'number',
						'default' => 200,
						'desc'    => __( 'How many links each page lists (50–1000).', 'themify' ),
					),
				),
			),
			array(
				'title'  => __( 'Site verification', 'themify' ),
				'desc'   => __( 'Paste the verification code (or the full meta tag) from each service to prove ownership.', 'themify' ),
				'fields' => array(
					array(
						'key'         => 'google_verify',
						'label'       => __( 'Google Search Console', 'themify' ),
						'type'        => 'text',
						'placeholder' => __( 'google-site-verification code', 'themify' ),
					),
					array(
						'key'         => 'bing_verify',
						'label'       => __( 'Bing Webmaster Tools', 'themify' ),
						'type'        => 'text',
						'placeholder' => __( 'msvalidate.01 code', 'themify' ),
					),
					array(
						'key'         => 'pinterest_verify',
						'label'       => __( 'Pinterest', 'themify' ),
						'type'        => 'text',
						'placeholder' => __( 'p:domain_verify code', 'themify' ),
					),
					array(
						'key'         => 'yandex_verify',
						'label'       => __( 'Yandex', 'themify' ),
						'type'        => 'text',
						'placeholder' => __( 'yandex-verification code', 'themify' ),
					),
				),
			),
		),
	) );
}
