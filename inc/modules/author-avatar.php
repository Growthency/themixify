<?php
/**
 * Local author avatars.
 *
 * Lets each user upload a profile picture straight from their WordPress profile
 * (Users → Profile → Profile picture) instead of relying on Gravatar. The
 * chosen image then replaces the Gravatar everywhere the theme calls
 * get_avatar() — the author box, the sidebar author card, comment avatars, etc.
 *
 * Stored as a URL in user meta '_themify_avatar'.
 *
 * @package Themify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolve a user ID from whatever get_avatar() was given (id, email, WP_User,
 * WP_Post, WP_Comment).
 *
 * @param mixed $id_or_email The avatar identifier.
 * @return int User ID, or 0 if it can't be resolved to a registered user.
 */
function themify_avatar_resolve_user( $id_or_email ) {
	if ( is_numeric( $id_or_email ) ) {
		return (int) $id_or_email;
	}
	if ( $id_or_email instanceof WP_User ) {
		return (int) $id_or_email->ID;
	}
	if ( $id_or_email instanceof WP_Post ) {
		return (int) $id_or_email->post_author;
	}
	if ( $id_or_email instanceof WP_Comment ) {
		if ( ! empty( $id_or_email->user_id ) ) {
			return (int) $id_or_email->user_id;
		}
		return 0; // guest commenter → let Gravatar handle it.
	}
	if ( is_string( $id_or_email ) && is_email( $id_or_email ) ) {
		$user = get_user_by( 'email', $id_or_email );
		return $user ? (int) $user->ID : 0;
	}
	return 0;
}

/**
 * Swap in the locally-uploaded avatar URL before WordPress builds the <img>.
 *
 * @param array $args        Avatar data args.
 * @param mixed $id_or_email Avatar identifier.
 * @return array
 */
function themify_avatar_use_local( $args, $id_or_email ) {
	$user_id = themify_avatar_resolve_user( $id_or_email );
	if ( ! $user_id ) {
		return $args;
	}
	$url = get_user_meta( $user_id, '_themify_avatar', true );
	if ( $url ) {
		$args['url']          = $url;
		$args['found_avatar'] = true;
	}
	return $args;
}
add_filter( 'pre_get_avatar_data', 'themify_avatar_use_local', 10, 2 );

/**
 * Render the "Profile picture" control on the user profile screen.
 *
 * @param WP_User $user The user being edited.
 */
function themify_avatar_profile_field( $user ) {
	if ( ! current_user_can( 'edit_user', $user->ID ) ) {
		return;
	}
	$url = get_user_meta( $user->ID, '_themify_avatar', true );
	wp_nonce_field( 'themify_avatar_' . $user->ID, 'themify_avatar_nonce' );
	?>
	<h2><?php esc_html_e( 'Profile picture', 'themify' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th><label for="themify_avatar"><?php esc_html_e( 'Author image', 'themify' ); ?></label></th>
			<td>
				<div class="tf-avatar-field" style="display:flex;align-items:center;gap:14px;">
					<img src="<?php echo esc_url( $url ? $url : get_avatar_url( $user->ID, array( 'size' => 96 ) ) ); ?>" class="tf-avatar-preview" width="80" height="80" style="width:80px;height:80px;border-radius:50%;object-fit:cover;background:#eef2f3;" alt="" />
					<span>
						<input type="hidden" id="themify_avatar" name="themify_avatar" value="<?php echo esc_attr( $url ); ?>" />
						<button type="button" class="button tf-avatar-upload"><?php esc_html_e( 'Choose image', 'themify' ); ?></button>
						<button type="button" class="button tf-avatar-remove" <?php echo $url ? '' : 'style="display:none;"'; ?>><?php esc_html_e( 'Remove', 'themify' ); ?></button>
					</span>
				</div>
				<p class="description"><?php esc_html_e( 'Upload or pick an image to use as this author\'s picture across the site. Leave empty to fall back to Gravatar.', 'themify' ); ?></p>
			</td>
		</tr>
		<tr>
			<th><label for="themify_author_slug"><?php esc_html_e( 'Author URL slug', 'themify' ); ?></label></th>
			<td>
				<code><?php echo esc_html( trailingslashit( home_url( '/' . themify_author_base() ) ) ); ?></code>
				<input type="text" id="themify_author_slug" name="themify_author_slug" value="<?php echo esc_attr( $user->user_nicename ); ?>" class="regular-text" style="max-width:260px;" />
				<p class="description"><?php esc_html_e( 'The word that appears in the author archive URL. Use a clean name like "ryan-carter" instead of an email-based slug. Changing it updates the author link.', 'themify' ); ?></p>
			</td>
		</tr>
	</table>
	<?php
}

/**
 * The author URL base segment (WordPress default is "author").
 *
 * @return string
 */
function themify_author_base() {
	global $wp_rewrite;
	$base = ( $wp_rewrite && ! empty( $wp_rewrite->author_base ) ) ? $wp_rewrite->author_base : 'author';
	return $base;
}
add_action( 'show_user_profile', 'themify_avatar_profile_field' );
add_action( 'edit_user_profile', 'themify_avatar_profile_field' );

/**
 * Load the media library on the profile / user-edit screens so the picker
 * works. Enqueuing here (not inside the field render) guarantees wp.media is
 * available before the footer script below runs.
 *
 * @param string $hook Current admin page.
 */
function themify_avatar_admin_assets( $hook ) {
	if ( 'profile.php' === $hook || 'user-edit.php' === $hook ) {
		wp_enqueue_media();
	}
}
add_action( 'admin_enqueue_scripts', 'themify_avatar_admin_assets' );

/**
 * Print the avatar-picker script in the admin FOOTER (after wp.media has
 * loaded) so the "Choose image" button actually opens the media library. Only
 * on the profile / user-edit screens.
 */
function themify_avatar_footer_script() {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || ! in_array( $screen->id, array( 'profile', 'user-edit' ), true ) ) {
		return;
	}
	?>
	<script>
	( function () {
		function init() {
			var wrap = document.querySelector( '.tf-avatar-field' );
			if ( ! wrap || ! window.wp || ! wp.media ) { return; }
			var input = wrap.querySelector( '#themify_avatar' );
			var preview = wrap.querySelector( '.tf-avatar-preview' );
			var removeBtn = wrap.querySelector( '.tf-avatar-remove' );
			var uploadBtn = wrap.querySelector( '.tf-avatar-upload' );
			var frame;
			uploadBtn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				if ( frame ) { frame.open(); return; }
				frame = wp.media( { title: 'Select profile picture', button: { text: 'Use image' }, library: { type: 'image' }, multiple: false } );
				frame.on( 'select', function () {
					var a = frame.state().get( 'selection' ).first().toJSON();
					var u = ( a.sizes && a.sizes.thumbnail ) ? a.sizes.thumbnail.url : a.url;
					input.value = a.url;
					preview.src = u;
					removeBtn.style.display = '';
				} );
				frame.open();
			} );
			removeBtn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				input.value = '';
				removeBtn.style.display = 'none';
			} );
		}
		if ( document.readyState !== 'loading' ) { init(); }
		else { document.addEventListener( 'DOMContentLoaded', init ); }
	} )();
	</script>
	<?php
}
add_action( 'admin_print_footer_scripts', 'themify_avatar_footer_script' );

/**
 * Persist the uploaded avatar URL.
 *
 * @param int $user_id The user being saved.
 */
function themify_avatar_save( $user_id ) {
	if ( ! current_user_can( 'edit_user', $user_id ) ) {
		return;
	}
	$nonce = isset( $_POST['themify_avatar_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['themify_avatar_nonce'] ) ) : '';
	if ( ! wp_verify_nonce( $nonce, 'themify_avatar_' . $user_id ) ) {
		return;
	}
	$url = isset( $_POST['themify_avatar'] ) ? esc_url_raw( trim( wp_unslash( $_POST['themify_avatar'] ) ) ) : '';
	if ( $url ) {
		update_user_meta( $user_id, '_themify_avatar', $url );
	} else {
		delete_user_meta( $user_id, '_themify_avatar' );
	}

	// Author URL slug (user_nicename). Only touch it when the admin actually
	// changed it, and guard against re-entrancy since wp_update_user fires
	// profile hooks of its own.
	static $busy = false;
	if ( ! $busy && isset( $_POST['themify_author_slug'] ) ) {
		$new_slug = sanitize_title( wp_unslash( $_POST['themify_author_slug'] ) );
		$user     = get_userdata( $user_id );
		if ( $new_slug && $user && $new_slug !== $user->user_nicename ) {
			$busy   = true;
			wp_update_user( array( 'ID' => $user_id, 'user_nicename' => $new_slug ) );
			$busy   = false;
		}
	}
}
add_action( 'personal_options_update', 'themify_avatar_save' );
add_action( 'edit_user_profile_update', 'themify_avatar_save' );
