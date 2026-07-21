<?php
/**
 * Header / Footer / Body code injection.
 *
 * Lets an administrator paste arbitrary code snippets (Google Analytics /
 * gtag, Search Console & Bing verification metas, Meta/Facebook Pixel, chat
 * widgets, custom <style> blocks, etc.) and have them printed into the page
 * at one of three positions:
 *
 *   - head        → wp_head        (inside <head>)
 *   - body_start  → wp_body_open   (immediately after <body>)
 *   - body_end    → wp_footer      (immediately before </body>)
 *
 * SECURITY NOTE — this module INTENTIONALLY outputs raw, un-escaped code.
 * That is the whole point of a code-injection feature: the analytics /
 * verification / pixel snippets vendors give you are literal <script>/<meta>
 * markup that must reach the browser verbatim. Escaping them would break them.
 *
 * This is safe because the data can ONLY be authored by a user who holds
 * THEMIFY_CAP (manage_options) — the same capability WordPress core requires
 * to edit theme/plugin files and the Customizer's "Additional CSS". The save
 * handler is gated behind a nonce + capability check, so no lower-privileged
 * user (and no CSRF) can inject code. Each snippet is wrapped in an HTML
 * comment carrying its (sanitized) name so the source stays auditable, and
 * output is skipped in the admin, in feeds and in REST/robots contexts.
 *
 * The snippets live in their own option ('themify_scripts') as an indexed
 * array — NOT in THEMIFY_OPT — because they are list-shaped data. Each row:
 *   array(
 *     'id'       => (string) stable unique id,
 *     'name'     => (string) human label,
 *     'code'     => (string) raw code, LF line endings,
 *     'position' => 'head' | 'body_start' | 'body_end',
 *     'enabled'  => (bool),
 *   )
 *
 * @package Themify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Option name holding the indexed array of code snippets.
 */
if ( ! defined( 'THEMIFY_SCRIPTS_OPT' ) ) {
	define( 'THEMIFY_SCRIPTS_OPT', 'themify_scripts' );
}

/**
 * The positions a snippet may target, mapped to a human label. The array keys
 * double as the whitelist used when sanitizing posted data and when routing
 * output to the right hook.
 *
 * @return array<string,string> position slug => label.
 */
function themify_script_positions() {
	return array(
		'head'       => __( 'Header (inside &lt;head&gt;)', 'themify' ),
		'body_start' => __( 'Body — top (after opening &lt;body&gt;)', 'themify' ),
		'body_end'   => __( 'Footer (before &lt;/body&gt;)', 'themify' ),
	);
}

/**
 * Read all stored snippets, normalised into a predictable shape. Every row is
 * guaranteed to have all keys, a valid position and a boolean 'enabled'.
 *
 * @return array<int,array> List of snippet rows in stored order.
 */
function themify_get_scripts() {
	$raw = get_option( THEMIFY_SCRIPTS_OPT, array() );
	if ( ! is_array( $raw ) ) {
		return array();
	}

	$positions = themify_script_positions();
	$clean     = array();

	foreach ( $raw as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$position = isset( $row['position'] ) ? (string) $row['position'] : 'head';
		if ( ! isset( $positions[ $position ] ) ) {
			$position = 'head';
		}
		$clean[] = array(
			'id'       => isset( $row['id'] ) && '' !== $row['id'] ? (string) $row['id'] : themify_new_script_id(),
			'name'     => isset( $row['name'] ) ? (string) $row['name'] : '',
			'code'     => isset( $row['code'] ) ? (string) $row['code'] : '',
			'position' => $position,
			'enabled'  => ! empty( $row['enabled'] ),
		);
	}

	return $clean;
}

/**
 * Return the enabled snippets for one position, in stored order.
 *
 * @param string $position One of the themify_script_positions() keys.
 * @return array<int,array>
 */
function themify_get_scripts_for( $position ) {
	$out = array();
	foreach ( themify_get_scripts() as $row ) {
		if ( $row['position'] === $position && $row['enabled'] && '' !== trim( $row['code'] ) ) {
			$out[] = $row;
		}
	}
	return $out;
}

/**
 * Persist the snippet list. Callers must have already sanitized the rows;
 * this only guarantees the value is a re-indexed array before storing.
 *
 * @param array $scripts List of snippet rows.
 * @return bool Whether the option was updated.
 */
function themify_save_scripts( array $scripts ) {
	return update_option( THEMIFY_SCRIPTS_OPT, array_values( $scripts ) );
}

/**
 * Generate a stable, collision-resistant id for a new snippet.
 *
 * @return string
 */
function themify_new_script_id() {
	if ( function_exists( 'wp_generate_uuid4' ) ) {
		return wp_generate_uuid4();
	}
	return 'tf_' . substr( md5( uniqid( (string) wp_rand(), true ) ), 0, 16 );
}

/* -------------------------------------------------------------------------
 * FRONT-END OUTPUT
 * ---------------------------------------------------------------------- */

/**
 * Whether code injection should run for the current request. We only want raw
 * snippets on real, public HTML page views — never in the admin, never in
 * feeds, never for REST/AJAX/cron/robots.txt requests.
 *
 * @return bool
 */
function themify_scripts_should_output() {
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
 * Print every enabled snippet for a position, raw, each wrapped in an HTML
 * comment marker built from the snippet's sanitized name so the page source
 * stays auditable.
 *
 * The snippet body is echoed verbatim — see the security note at the top of
 * this file. The comment marker text is the ONLY part that is escaped, and it
 * is stripped of "--" / ">" so a hostile name can never break out of the
 * comment. Output is intentionally NOT run through esc_ / wp_kses helpers.
 *
 * @param string $position One of the themify_script_positions() keys.
 */
function themify_output_scripts( $position ) {
	if ( ! themify_scripts_should_output() ) {
		return;
	}

	foreach ( themify_get_scripts_for( $position ) as $row ) {
		$label = trim( wp_strip_all_tags( $row['name'] ) );
		$label = str_replace( array( '-->', '--', '<', '>' ), ' ', $label );
		$label = '' !== $label ? $label : __( 'snippet', 'themify' );

		echo "\n<!-- Themify code: " . esc_html( $label ) . " -->\n";
		echo $row['code'] . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Admin-authored raw code (manage_options); escaping would break analytics/verification snippets.
		echo "<!-- /Themify code: " . esc_html( $label ) . " -->\n";
	}
}

/**
 * wp_head: emit the 'head' snippets. Late priority so verification metas and
 * analytics land after the theme's own <head> output but still inside <head>.
 */
function themify_output_head_scripts() {
	themify_output_scripts( 'head' );
}
add_action( 'wp_head', 'themify_output_head_scripts', 99 );

/**
 * wp_body_open: emit the 'body_start' snippets (e.g. GTM noscript, pixels that
 * ask to sit immediately after <body>).
 */
function themify_output_body_start_scripts() {
	themify_output_scripts( 'body_start' );
}
add_action( 'wp_body_open', 'themify_output_body_start_scripts', 5 );

/**
 * wp_footer: emit the 'body_end' snippets. Late priority so they come after
 * the theme's footer scripts.
 */
function themify_output_body_end_scripts() {
	themify_output_scripts( 'body_end' );
}
add_action( 'wp_footer', 'themify_output_body_end_scripts', 99 );

/* -------------------------------------------------------------------------
 * ADMIN PAGE
 * ---------------------------------------------------------------------- */

/**
 * Register the "Header & Footer Code" submenu (position 15).
 */
themify_register_admin_page( array(
	'slug'       => 'themify-scripts',
	'title'      => __( 'Header &amp; Footer Code', 'themify' ),
	'menu_title' => __( 'Header &amp; Footer Code', 'themify' ),
	'callback'   => 'themify_scripts_page',
	'position'   => 54,
) );

/**
 * Add the code-injection card to the dashboard grid.
 */
add_filter( 'themify_dashboard_cards', 'themify_scripts_dashboard_card' );

/**
 * Append the "Header & Footer Code" dashboard card.
 *
 * @param array $cards Existing dashboard cards.
 * @return array
 */
function themify_scripts_dashboard_card( $cards ) {
	$cards[] = array(
		'slug'     => 'themify-scripts',
		'title'    => __( 'Header & Footer Code', 'themify' ),
		'desc'     => __( 'Analytics, verification & pixels', 'themify' ),
		'icon'     => 'dashicons-editor-code',
		'position' => 54,
	);
	return $cards;
}

/**
 * Handle a POST save of the snippet repeater. Rebuilds the whole list from the
 * posted rows: sanitizes the name, whitelists the position, coerces enabled to
 * a bool, keeps the code raw (only normalising line endings), drops empty rows
 * and assigns a stable id to each surviving row.
 *
 * @return bool True when a valid save happened (so the caller can show a notice).
 */
function themify_scripts_handle_save() {
	if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
		return false;
	}
	if ( ! current_user_can( THEMIFY_CAP ) ) {
		return false;
	}
	$nonce = isset( $_POST['themify_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['themify_nonce'] ) ) : '';
	if ( ! wp_verify_nonce( $nonce, 'themify_scripts' ) ) {
		return false;
	}

	// The repeater posts a parallel-array under 'themify_scripts'. Each key is
	// an array indexed by row; a row is present in all of them.
	$rows      = isset( $_POST['themify_scripts'] ) && is_array( $_POST['themify_scripts'] )
		? wp_unslash( $_POST['themify_scripts'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each field is sanitized individually below.
		: array();
	$positions = themify_script_positions();
	$clean     = array();

	foreach ( $rows as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}

		// Code stays RAW (admin-authored). Only normalise CRLF/CR to LF, matching
		// how the 'code' field type is handled in the settings sanitizer.
		$code = isset( $row['code'] ) ? (string) $row['code'] : '';
		$code = str_replace( array( "\r\n", "\r" ), "\n", $code );

		$name = isset( $row['name'] ) ? sanitize_text_field( $row['name'] ) : '';

		// Drop rows with no code AND no name — they are empty template leftovers.
		if ( '' === trim( $code ) && '' === trim( $name ) ) {
			continue;
		}

		$position = isset( $row['position'] ) ? (string) $row['position'] : 'head';
		if ( ! isset( $positions[ $position ] ) ) {
			$position = 'head';
		}

		$id = isset( $row['id'] ) && '' !== trim( (string) $row['id'] )
			? sanitize_text_field( $row['id'] )
			: themify_new_script_id();

		$clean[] = array(
			'id'       => $id,
			'name'     => $name,
			'code'     => $code,
			'position' => $position,
			'enabled'  => ! empty( $row['enabled'] ),
		);
	}

	themify_save_scripts( $clean );
	return true;
}

/**
 * Render one snippet row of the repeater.
 *
 * @param int|string $index Row index (numeric for real rows, '__INDEX__' for
 *                          the JS template).
 * @param array      $row   Snippet data (empty defaults for the template).
 */
function themify_scripts_render_row( $index, array $row = array() ) {
	$row = wp_parse_args( $row, array(
		'id'       => '',
		'name'     => '',
		'code'     => '',
		'position' => 'head',
		'enabled'  => true,
	) );

	$base = 'themify_scripts[' . $index . ']';

	echo '<div class="tf-repeater__row">';

	// Hidden stable id (regenerated on save when blank).
	printf(
		'<input type="hidden" name="%s[id]" value="%s" />',
		esc_attr( $base ),
		esc_attr( $row['id'] )
	);

	// Top line: name + position + enabled toggle + remove.
	echo '<div class="tf-field tf-field--text" style="margin-bottom:0;">';
	printf(
		'<label class="tf-field__label">%s</label>',
		esc_html__( 'Name', 'themify' )
	);
	printf(
		'<input type="text" name="%s[name]" value="%s" class="tf-input" placeholder="%s" />',
		esc_attr( $base ),
		esc_attr( $row['name'] ),
		esc_attr__( 'e.g. Google Analytics', 'themify' )
	);
	echo '</div>';

	// Position select.
	echo '<div class="tf-field tf-field--select" style="margin-bottom:0;">';
	printf(
		'<label class="tf-field__label">%s</label>',
		esc_html__( 'Placement', 'themify' )
	);
	printf( '<select name="%s[position]" class="tf-input tf-select">', esc_attr( $base ) );
	foreach ( themify_script_positions() as $pos_val => $pos_label ) {
		printf(
			'<option value="%s" %s>%s</option>',
			esc_attr( $pos_val ),
			selected( $row['position'], $pos_val, false ),
			// Labels contain intentional &lt;/&gt; entities; wp_kses them so the
			// entities render as text rather than being double-escaped.
			wp_kses( $pos_label, array() )
		);
	}
	echo '</select>';
	echo '</div>';

	// Enabled toggle (reuses the .tf-switch styling from the settings framework).
	echo '<div class="tf-field tf-field--checkbox" style="margin-bottom:0;">';
	echo '<label class="tf-switch">';
	printf(
		'<input type="checkbox" name="%s[enabled]" value="1" %s />',
		esc_attr( $base ),
		checked( ! empty( $row['enabled'] ), true, false )
	);
	echo '<span class="tf-switch__track"></span>';
	echo '<span class="tf-switch__label">' . esc_html__( 'Enabled', 'themify' ) . '</span>';
	echo '</label>';
	echo '</div>';

	// Code textarea (raw — class tf-code for the mono/dark editor styling).
	echo '<div class="tf-field tf-field--code" style="margin-bottom:0;">';
	printf(
		'<label class="tf-field__label">%s</label>',
		esc_html__( 'Code', 'themify' )
	);
	printf(
		'<textarea name="%s[code]" rows="6" class="tf-input tf-textarea tf-code" spellcheck="false" placeholder="%s">%s</textarea>',
		esc_attr( $base ),
		esc_attr__( 'Paste the exact snippet your provider gave you, including the <script> or <meta> tags.', 'themify' ),
		esc_textarea( $row['code'] )
	);
	echo '</div>';

	// Remove control.
	echo '<p style="margin:0;"><a href="#" class="tf-remove">' . esc_html__( 'Remove snippet', 'themify' ) . '</a></p>';

	echo '</div>'; // .tf-repeater__row
}

/**
 * Render the "Header & Footer Code" admin screen: a repeater of snippet rows
 * with a hidden template for JS-added rows, saved via a standard POST form.
 */
function themify_scripts_page() {
	$saved = themify_scripts_handle_save();

	themify_admin_header(
		__( 'Header &amp; Footer Code', 'themify' ),
		__( 'Add code snippets to the &lt;head&gt;, right after the opening &lt;body&gt;, or just before &lt;/body&gt; — without editing theme files.', 'themify' )
	);

	if ( $saved ) {
		echo '<div class="tf-notice tf-notice--info">' . esc_html__( 'Code snippets saved.', 'themify' ) . '</div>';
	}

	// Intro card explaining the common uses + the security posture.
	echo '<div class="tf-card">';
	echo '<h2 class="tf-card__title">' . esc_html__( 'What this is for', 'themify' ) . '</h2>';
	echo '<p class="tf-card__desc">' . wp_kses_post( __( 'Paste snippets your services give you and choose where they load:', 'themify' ) ) . '</p>';
	echo '<ul style="margin:0 0 4px 18px; list-style:disc; color:#5a6b62; font-size:0.92rem;">';
	echo '<li>' . wp_kses_post( __( '<strong>Google Analytics / gtag.js</strong> — paste in the <em>Header</em>.', 'themify' ) ) . '</li>';
	echo '<li>' . wp_kses_post( __( '<strong>Google Search Console &amp; Bing site verification</strong> — paste the <code>&lt;meta&gt;</code> tag in the <em>Header</em>.', 'themify' ) ) . '</li>';
	echo '<li>' . wp_kses_post( __( '<strong>Meta (Facebook) Pixel / Google Tag Manager</strong> — the main script in the <em>Header</em>, the <code>&lt;noscript&gt;</code> fallback at <em>Body — top</em>.', 'themify' ) ) . '</li>';
	echo '<li>' . wp_kses_post( __( 'Chat widgets and other deferred scripts usually go in the <em>Footer</em>.', 'themify' ) ) . '</li>';
	echo '</ul>';
	echo '<p class="tf-field__desc">' . wp_kses_post( __( 'For site styling use the <strong>Custom CSS</strong> screen instead of a <code>&lt;style&gt;</code> snippet here.', 'themify' ) ) . '</p>';
	echo '<p class="tf-field__desc">' . wp_kses_post( __( 'Code is output <strong>exactly as entered</strong> so it works as your provider intended. Only administrators can edit these snippets. Double-check anything you paste, and remove snippets you no longer use.', 'themify' ) ) . '</p>';
	echo '</div>';

	$scripts = themify_get_scripts();

	echo '<form method="post" class="tf-form">';
	wp_nonce_field( 'themify_scripts', 'themify_nonce' );

	echo '<div class="tf-card">';
	echo '<h2 class="tf-card__title">' . esc_html__( 'Snippets', 'themify' ) . '</h2>';

	echo '<div class="tf-repeater">';

	// Hidden template row — admin.js clones this and swaps __INDEX__ for the
	// next numeric index when the "Add snippet" button is clicked.
	echo '<script type="text/html" class="tf-repeater__template">';
	themify_scripts_render_row( '__INDEX__' );
	echo '</script>';

	// Existing rows.
	echo '<div class="tf-repeater__rows">';
	if ( $scripts ) {
		foreach ( $scripts as $i => $row ) {
			themify_scripts_render_row( $i, $row );
		}
	}
	echo '</div>';

	echo '<p><button type="button" class="button tf-repeater__add">' . esc_html__( '+ Add snippet', 'themify' ) . '</button></p>';
	echo '</div>'; // .tf-repeater

	echo '</div>'; // .tf-card

	echo '<p class="tf-form__actions"><button type="submit" class="button button-primary button-hero">' . esc_html__( 'Save Changes', 'themify' ) . '</button></p>';
	echo '</form>';

	themify_admin_footer();
}
