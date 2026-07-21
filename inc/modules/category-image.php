<?php
/**
 * Category images.
 *
 * Lets each category carry a background image (stored as a URL in term meta
 * '_themify_cat_image'), uploaded straight from the category edit screen. The
 * homepage "Browse by topic" showcase uses it as the card background, with the
 * category name + post count overlaid. Categories without an image fall back to
 * a tasteful gradient, so the grid always looks intentional.
 *
 * @package Themify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The image URL to show behind a category card. Resolution order:
 *   1. An image explicitly set on the category (Category image field).
 *   2. Otherwise, the featured image of the most recent post in that category —
 *      so the grid automatically fills with your real photos with zero setup.
 *   3. Otherwise '' → the caller uses a gradient.
 *
 * @param int $term_id Category term ID.
 * @return string
 */
function themify_get_category_image( $term_id ) {
	$term_id = (int) $term_id;

	// 1. Explicit category image.
	$url = get_term_meta( $term_id, '_themify_cat_image', true );
	if ( is_string( $url ) && '' !== $url ) {
		return $url;
	}

	// 2. Latest post-in-category featured image.
	$q = new WP_Query( array(
		'cat'                 => $term_id,
		'posts_per_page'      => 1,
		'post_status'         => 'publish',
		'ignore_sticky_posts' => 1,
		'no_found_rows'       => true,
		'fields'              => 'ids',
		'meta_query'          => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			array( 'key' => '_thumbnail_id', 'compare' => 'EXISTS' ),
		),
	) );
	if ( ! empty( $q->posts ) ) {
		$img = get_the_post_thumbnail_url( $q->posts[0], 'themify-card' );
		if ( $img ) {
			return $img;
		}
	}

	return '';
}

/**
 * A rotating palette of dark, premium gradients for categories that have no
 * image yet. The white name/count sit legibly on top of any of them.
 *
 * @return string[]
 */
function themify_category_gradients() {
	return array(
		'linear-gradient(135deg,#141e30,#243b55)',
		'linear-gradient(135deg,#c31432,#240b36)',
		'linear-gradient(135deg,#0f2027,#2c5364)',
		'linear-gradient(135deg,#42275a,#734b6d)',
		'linear-gradient(135deg,#1e3c72,#2a5298)',
		'linear-gradient(135deg,#3a1c71,#d76d77)',
		'linear-gradient(135deg,#232526,#414345)',
		'linear-gradient(135deg,#16222a,#3a6073)',
	);
}

/* -------------------------------------------------------------------------
 * ADMIN — category image field
 * ---------------------------------------------------------------------- */

/**
 * Render the image control on the "Edit Category" screen.
 *
 * @param WP_Term $term The category being edited.
 */
function themify_category_image_edit_field( $term ) {
	$url = themify_get_category_image( $term->term_id );
	wp_nonce_field( 'themify_cat_image', 'themify_cat_image_nonce' );
	?>
	<tr class="form-field">
		<th scope="row"><label for="themify_cat_image"><?php esc_html_e( 'Category image', 'themify' ); ?></label></th>
		<td>
			<div class="tf-catimg-field" style="display:flex;align-items:center;gap:14px;">
				<img src="<?php echo esc_url( $url ); ?>" class="tf-catimg-preview" alt="" style="width:90px;height:60px;object-fit:cover;border-radius:8px;background:#eef2f3;<?php echo $url ? '' : 'display:none;'; ?>" />
				<span>
					<input type="hidden" id="themify_cat_image" name="themify_cat_image" value="<?php echo esc_attr( $url ); ?>" />
					<button type="button" class="button tf-catimg-pick"><?php esc_html_e( 'Choose image', 'themify' ); ?></button>
					<button type="button" class="button tf-catimg-clear" <?php echo $url ? '' : 'style="display:none;"'; ?>><?php esc_html_e( 'Remove', 'themify' ); ?></button>
				</span>
			</div>
			<p class="description"><?php esc_html_e( 'Shown behind this category on the homepage "Browse by topic" grid. Leave empty for a coloured gradient.', 'themify' ); ?></p>
		</td>
	</tr>
	<?php
}
add_action( 'category_edit_form_fields', 'themify_category_image_edit_field' );

/**
 * Save the category image URL.
 *
 * @param int $term_id Category term ID.
 */
function themify_category_image_save( $term_id ) {
	if ( ! current_user_can( 'manage_categories' ) ) {
		return;
	}
	$nonce = isset( $_POST['themify_cat_image_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['themify_cat_image_nonce'] ) ) : '';
	if ( ! wp_verify_nonce( $nonce, 'themify_cat_image' ) ) {
		return;
	}
	if ( ! isset( $_POST['themify_cat_image'] ) ) {
		return;
	}
	$url = esc_url_raw( trim( wp_unslash( $_POST['themify_cat_image'] ) ) );
	if ( $url ) {
		update_term_meta( $term_id, '_themify_cat_image', $url );
	} else {
		delete_term_meta( $term_id, '_themify_cat_image' );
	}
}
add_action( 'edited_category', 'themify_category_image_save' );

/**
 * Load the media library + the picker script on the category edit screen.
 *
 * @param string $hook Current admin page.
 */
function themify_category_image_assets( $hook ) {
	if ( 'term.php' === $hook || 'edit-tags.php' === $hook ) {
		wp_enqueue_media();
	}
}
add_action( 'admin_enqueue_scripts', 'themify_category_image_assets' );

/**
 * Print the picker script in the admin footer of the category screens.
 */
function themify_category_image_footer_script() {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || 'category' !== $screen->taxonomy ) {
		return;
	}
	?>
	<script>
	( function () {
		function init() {
			var wrap = document.querySelector( '.tf-catimg-field' );
			if ( ! wrap || ! window.wp || ! wp.media ) { return; }
			var input = wrap.querySelector( '#themify_cat_image' );
			var preview = wrap.querySelector( '.tf-catimg-preview' );
			var clearBtn = wrap.querySelector( '.tf-catimg-clear' );
			var frame;
			wrap.querySelector( '.tf-catimg-pick' ).addEventListener( 'click', function ( e ) {
				e.preventDefault();
				if ( frame ) { frame.open(); return; }
				frame = wp.media( { title: 'Select category image', library: { type: 'image' }, multiple: false } );
				frame.on( 'select', function () {
					var a = frame.state().get( 'selection' ).first().toJSON();
					var u = ( a.sizes && a.sizes.medium ) ? a.sizes.medium.url : a.url;
					input.value = a.url;
					preview.src = u;
					preview.style.display = '';
					clearBtn.style.display = '';
				} );
				frame.open();
			} );
			clearBtn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				input.value = '';
				preview.style.display = 'none';
				clearBtn.style.display = 'none';
			} );
		}
		if ( document.readyState !== 'loading' ) { init(); }
		else { document.addEventListener( 'DOMContentLoaded', init ); }
	} )();
	</script>
	<?php
}
add_action( 'admin_print_footer_scripts', 'themify_category_image_footer_script' );
