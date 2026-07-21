<?php
/**
 * Custom CSS — global (site-wide) and per-entry.
 *
 * Two independent layers of user-authored CSS, both edited only by holders of
 * THEMIFY_CAP (manage_options — the same capability WordPress requires for the
 * Customizer's "Additional CSS"):
 *
 *   1. GLOBAL   — one option, 'themify_custom_css', printed on every front-end
 *                 page in <style id="themify-custom-css">. Edited on the
 *                 "Custom CSS" admin screen.
 *   2. PER-ENTRY — post meta '_themify_post_css', printed only on that single
 *                 post/page in <style id="themify-post-css">. Edited in a meta
 *                 box on the post/page editor.
 *
 * SECURITY / ESCAPING NOTE — this is *CSS*, authored by an administrator, and
 * it must reach the browser verbatim (escaping selectors/braces would break
 * it). It is therefore output raw, exactly like the Customizer's Additional
 * CSS. The only defensive step is stripping any '</style>' or '<script' so the
 * value can never break out of its own <style> element into executable markup.
 * Both the global option and the post meta are written behind a nonce +
 * capability check, so no lower-privileged user (and no CSRF) can inject CSS.
 *
 * The global CSS lives in its OWN option ('themify_custom_css'), NOT inside
 * THEMIFY_OPT, because it is a large free-form blob rather than a simple
 * scalar setting.
 *
 * @package Themify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Option name holding the global custom CSS blob.
 */
if ( ! defined( 'THEMIFY_CSS_OPT' ) ) {
	define( 'THEMIFY_CSS_OPT', 'themify_custom_css' );
}

/**
 * Post meta key holding the per-entry custom CSS.
 */
if ( ! defined( 'THEMIFY_POST_CSS_META' ) ) {
	define( 'THEMIFY_POST_CSS_META', '_themify_post_css' );
}

/* -------------------------------------------------------------------------
 * SANITIZING
 * ---------------------------------------------------------------------- */

/**
 * Make an admin-authored CSS blob safe to drop inside a <style> element.
 *
 * The value is kept RAW so real CSS survives untouched; the ONLY thing removed
 * is anything that could terminate the <style> element or open a script — i.e.
 * a literal '</style>' (any casing / inner whitespace) or a '<script' opener.
 * This mirrors the guarantee WordPress makes for the Customizer's custom CSS:
 * it stays inside its own tag and can never become executable markup.
 *
 * Line endings are normalised to LF for tidy storage/output.
 *
 * @param string $css Raw CSS input.
 * @return string Sanitized CSS (LF line endings, no style/script breakout).
 */
function themify_sanitize_css( $css ) {
	$css = (string) $css;
	$css = str_replace( array( "\r\n", "\r" ), "\n", $css );

	// Strip any attempt to close the <style> element early.
	$css = preg_replace( '#</\s*style#i', '', $css );
	// Strip any attempt to open a <script>.
	$css = preg_replace( '#<\s*script#i', '', $css );

	return (string) $css;
}

/**
 * Read the global custom CSS blob.
 *
 * @return string
 */
function themify_get_custom_css() {
	$css = get_option( THEMIFY_CSS_OPT, '' );
	return is_string( $css ) ? $css : '';
}

/* -------------------------------------------------------------------------
 * FRONT-END OUTPUT
 * ---------------------------------------------------------------------- */

/**
 * Whether custom CSS should be printed for the current request. Only real,
 * public HTML page views — never admin, feeds, or REST/AJAX/cron contexts.
 *
 * @return bool
 */
function themify_css_should_output() {
	if ( is_admin() || is_feed() || is_robots() || is_trackback() ) {
		return false;
	}
	if ( ( defined( 'DOING_AJAX' ) && DOING_AJAX ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
		return false;
	}
	if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) ) {
		return false;
	}
	return true;
}

/**
 * Print the global custom CSS in its own <style id="themify-custom-css">.
 *
 * Hooked LATE on wp_head (priority 99) so it wins the cascade over the theme's
 * own critical CSS and any color-customizer overrides. Skipped when empty.
 */
function themify_output_global_css() {
	if ( ! themify_css_should_output() ) {
		return;
	}

	$css = themify_sanitize_css( themify_get_custom_css() );
	if ( '' === trim( $css ) ) {
		return;
	}

	// $css is admin-authored CSS with any style/script breakout already stripped.
	echo "\n<style id=\"themify-custom-css\">\n" . $css . "\n</style>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Admin-authored CSS (manage_options), style/script breakout stripped in themify_sanitize_css(); escaping would break the CSS.
}
add_action( 'wp_head', 'themify_output_global_css', 99 );

/**
 * Print the current single entry's per-post CSS in its own
 * <style id="themify-post-css">.
 *
 * Only runs on is_singular() and only when the post actually has stored CSS.
 * Hooked at priority 99 so it lands after the global custom CSS and can refine
 * it for this one entry.
 */
function themify_output_post_css() {
	if ( ! themify_css_should_output() || ! is_singular() ) {
		return;
	}

	$post_id = get_queried_object_id();
	if ( ! $post_id ) {
		return;
	}

	$css = themify_sanitize_css( (string) get_post_meta( $post_id, THEMIFY_POST_CSS_META, true ) );
	if ( '' === trim( $css ) ) {
		return;
	}

	// $css is admin-authored CSS with any style/script breakout already stripped.
	echo "\n<style id=\"themify-post-css\">\n" . $css . "\n</style>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Admin-authored CSS (manage_options), style/script breakout stripped in themify_sanitize_css(); escaping would break the CSS.
}
add_action( 'wp_head', 'themify_output_post_css', 99 );

/* -------------------------------------------------------------------------
 * PER-ENTRY META BOX
 * ---------------------------------------------------------------------- */

/**
 * Register the "Custom CSS (this entry)" meta box on posts and pages.
 */
function themify_add_post_css_metabox() {
	foreach ( array( 'post', 'page' ) as $screen ) {
		add_meta_box(
			'themify_post_css',
			__( 'Custom CSS (this entry)', 'themify' ),
			'themify_render_post_css_metabox',
			$screen,
			'normal',
			'low'
		);
	}
}
add_action( 'add_meta_boxes', 'themify_add_post_css_metabox' );

/**
 * Render the per-entry CSS meta box: a single monospace textarea (class
 * tf-code) plus its nonce.
 *
 * @param WP_Post $post The post being edited.
 */
function themify_render_post_css_metabox( $post ) {
	wp_nonce_field( 'themify_post_css_save', 'themify_post_css_nonce' );

	$css = (string) get_post_meta( $post->ID, THEMIFY_POST_CSS_META, true );

	echo '<p class="description">' . esc_html__( 'CSS added here loads only on this single entry, after the site-wide Custom CSS. It is placed inside its own &lt;style&gt; tag exactly as written.', 'themify' ) . '</p>';

	printf(
		'<textarea name="themify_post_css" id="themify_post_css" rows="8" class="tf-code widefat" spellcheck="false" style="width:100%%;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;" placeholder="%s">%s</textarea>',
		esc_attr__( '/* e.g. .tf-article__title { color: #b8860b; } */', 'themify' ),
		esc_textarea( $css )
	);
}

/**
 * Save the per-entry CSS on save_post.
 *
 * Guards against autosave/revisions, verifies the nonce, and checks the caller
 * can edit THIS post. The CSS is kept raw (only style/script breakout stripped
 * and line endings normalised). An empty value deletes the meta.
 *
 * @param int $post_id The post being saved.
 */
function themify_save_post_css( $post_id ) {
	// Skip autosaves and revisions — the box isn't posted then.
	if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
		return;
	}

	// Nonce must be present and valid.
	$nonce = isset( $_POST['themify_post_css_nonce'] )
		? sanitize_text_field( wp_unslash( $_POST['themify_post_css_nonce'] ) )
		: '';
	if ( ! wp_verify_nonce( $nonce, 'themify_post_css_save' ) ) {
		return;
	}

	// Capability: must be able to edit this specific post.
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	$raw = isset( $_POST['themify_post_css'] )
		? (string) wp_unslash( $_POST['themify_post_css'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Raw admin-authored CSS; sanitized (style/script breakout stripped) by themify_sanitize_css() below.
		: '';
	$css = themify_sanitize_css( $raw );

	if ( '' === trim( $css ) ) {
		delete_post_meta( $post_id, THEMIFY_POST_CSS_META );
	} else {
		update_post_meta( $post_id, THEMIFY_POST_CSS_META, $css );
	}
}
add_action( 'save_post', 'themify_save_post_css' );

/* -------------------------------------------------------------------------
 * ADMIN PAGE (global CSS)
 * ---------------------------------------------------------------------- */

/**
 * Register the "Custom CSS" submenu (position 60).
 */
themify_register_admin_page( array(
	'slug'       => 'themify-custom-css',
	'title'      => __( 'Custom CSS', 'themify' ),
	'menu_title' => __( 'Custom CSS', 'themify' ),
	'callback'   => 'themify_custom_css_page',
	'position'   => 60,
) );

/**
 * Add the Custom CSS card to the dashboard grid.
 */
add_filter( 'themify_dashboard_cards', 'themify_custom_css_dashboard_card' );

/**
 * Append the "Custom CSS" dashboard card.
 *
 * @param array $cards Existing dashboard cards.
 * @return array
 */
function themify_custom_css_dashboard_card( $cards ) {
	$cards[] = array(
		'slug'     => 'themify-custom-css',
		'title'    => __( 'Custom CSS', 'themify' ),
		'desc'     => __( 'Site-wide style tweaks', 'themify' ),
		'icon'     => 'dashicons-editor-code',
		'position' => 60,
	);
	return $cards;
}

/**
 * Handle a POST save of the global custom CSS. Verifies method, capability and
 * nonce, then stores the (sanitized) blob directly in its own option.
 *
 * @return bool True when a valid save happened (so the caller can show a notice).
 */
function themify_custom_css_handle_save() {
	if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
		return false;
	}
	if ( ! current_user_can( THEMIFY_CAP ) ) {
		return false;
	}
	$nonce = isset( $_POST['themify_nonce'] )
		? sanitize_text_field( wp_unslash( $_POST['themify_nonce'] ) )
		: '';
	if ( ! wp_verify_nonce( $nonce, 'themify_customcss' ) ) {
		return false;
	}

	$raw = isset( $_POST['themify_custom_css'] )
		? (string) wp_unslash( $_POST['themify_custom_css'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Raw admin-authored CSS; sanitized (style/script breakout stripped) by themify_sanitize_css() below.
		: '';

	update_option( THEMIFY_CSS_OPT, themify_sanitize_css( $raw ) );
	return true;
}

/**
 * Render the "Custom CSS" admin screen: a single monospace textarea saving the
 * global CSS option directly, plus a short primer on the two layers.
 */
function themify_custom_css_page() {
	$saved = themify_custom_css_handle_save();

	themify_admin_header(
		__( 'Custom CSS', 'themify' ),
		__( 'Add your own CSS to fine-tune the look of the whole site — no child theme required.', 'themify' )
	);

	if ( $saved ) {
		echo '<div class="tf-notice tf-notice--info">' . esc_html__( 'Custom CSS saved.', 'themify' ) . '</div>';
	}

	$css = themify_get_custom_css();

	echo '<form method="post" class="tf-form">';
	wp_nonce_field( 'themify_customcss', 'themify_nonce' );

	echo '<div class="tf-card">';
	echo '<h2 class="tf-card__title">' . esc_html__( 'Site-wide CSS', 'themify' ) . '</h2>';
	echo '<p class="tf-card__desc">' . wp_kses_post( __( 'This CSS loads on every page, inside its own <code>&lt;style&gt;</code> tag, late in the <code>&lt;head&gt;</code> so it overrides the theme defaults. Use the theme <code>--tf-*</code> variables and <code>.tf-*</code> classes to target elements. To style a single entry only, use the <strong>Custom CSS (this entry)</strong> box on that post or page.', 'themify' ) ) . '</p>';

	echo '<div class="tf-field tf-field--code">';
	printf(
		'<label class="tf-field__label" for="themify_custom_css">%s</label>',
		esc_html__( 'CSS', 'themify' )
	);
	printf(
		'<textarea id="themify_custom_css" name="themify_custom_css" rows="18" class="tf-input tf-textarea tf-code" spellcheck="false" placeholder="%s">%s</textarea>',
		esc_attr__( "/* Example */\n.tf-site-header { box-shadow: none; }\n:root { --tf-accent: #b8860b; }", 'themify' ),
		esc_textarea( $css )
	);
	echo '<p class="tf-field__desc">' . esc_html__( 'CSS is saved and output exactly as written; only administrators can edit it.', 'themify' ) . '</p>';
	echo '</div>';

	echo '</div>'; // .tf-card

	echo '<p class="tf-form__actions"><button type="submit" class="button button-primary button-hero">' . esc_html__( 'Save Changes', 'themify' ) . '</button></p>';
	echo '</form>';

	themify_admin_footer();
}
