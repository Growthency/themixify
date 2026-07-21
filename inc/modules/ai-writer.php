<?php
/**
 * AI Writer — generate complete, SEO-optimized blog drafts with Claude.
 *
 * This module adds an "AI Writer" admin console that talks to Anthropic's
 * Claude API (the /v1/messages endpoint). The site owner enters a topic, a
 * focus keyword, a tone and a target length; Claude returns a full article in
 * clean semantic HTML which is previewed in the admin and can be saved as a
 * draft post with one click.
 *
 * DESIGN + SAFETY NOTES
 * - The API key, model and default category live in THEMIFY_OPT (read/written
 *   through the shared helpers). The key is never echoed back into any page
 *   other than its own settings field.
 * - The Anthropic API is expensive and slow, so it is ONLY ever called from the
 *   admin AJAX handler below (nonce + capability verified). It is never touched
 *   on a public/front-end request. A short transient de-dupes accidental
 *   double-clicks, and the most recent draft is cached in a transient so the
 *   "Save as draft" button can persist exactly what was previewed.
 * - The generation form is driven by the shared `.tf-run` AJAX button. A tiny
 *   inline script serialises the form fields into the button's data-payload
 *   (as JSON) right before the request fires, so admin.js can POST it unchanged
 *   as $_POST['payload']; the handler json-decodes and sanitises every field.
 * - The generated article is model output destined for post_content, so it is
 *   run through wp_kses_post() before being previewed or inserted — trusted
 *   enough to render, but never raw.
 *
 * @package Themify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Anthropic Messages API endpoint.
 */
if ( ! defined( 'THEMIFY_AI_ENDPOINT' ) ) {
	define( 'THEMIFY_AI_ENDPOINT', 'https://api.anthropic.com/v1/messages' );
}

/**
 * Anthropic API version header value.
 */
if ( ! defined( 'THEMIFY_AI_API_VERSION' ) ) {
	define( 'THEMIFY_AI_API_VERSION', '2023-06-01' );
}

/**
 * Transient key holding the most recently generated draft (per user) so the
 * "Save as draft" action can persist exactly what was previewed.
 */
if ( ! defined( 'THEMIFY_AI_LAST_DRAFT' ) ) {
	define( 'THEMIFY_AI_LAST_DRAFT', 'themify_ai_last_draft_' );
}

/* ============================================================ OPTIONS / MODEL */

/**
 * The selectable Claude models. Keys are the exact Anthropic model IDs; values
 * are human-friendly labels shown in the settings dropdown.
 *
 * @return array<string,string>
 */
function themify_ai_models() {
	return array(
		'claude-opus-4-8'            => __( 'Opus 4.8 (highest quality)', 'themify' ),
		'claude-sonnet-5'           => __( 'Sonnet 5 (balanced)', 'themify' ),
		'claude-haiku-4-5-20251001' => __( 'Haiku 4.5 (fastest)', 'themify' ),
	);
}

/**
 * The default model used when none is chosen or an unknown value is stored.
 *
 * @return string
 */
function themify_ai_default_model() {
	return 'claude-sonnet-5';
}

/**
 * Resolve the configured model, falling back to the default when the stored
 * value is empty or not one of the allowed IDs.
 *
 * @return string
 */
function themify_ai_get_model() {
	$model  = (string) themify_get_option( 'ai_model', themify_ai_default_model() );
	$models = themify_ai_models();
	return isset( $models[ $model ] ) ? $model : themify_ai_default_model();
}

/**
 * The saved Anthropic API key (trimmed). Empty string when unset.
 *
 * @return string
 */
function themify_ai_get_key() {
	return trim( (string) themify_get_option( 'anthropic_api_key', '' ) );
}

/**
 * The allowed tone values mapped to their labels.
 *
 * @return array<string,string>
 */
function themify_ai_tones() {
	return array(
		'friendly'     => __( 'Friendly', 'themify' ),
		'professional' => __( 'Professional', 'themify' ),
		'persuasive'   => __( 'Persuasive', 'themify' ),
		'casual'       => __( 'Casual', 'themify' ),
	);
}

/**
 * The allowed length presets. Each maps to an approximate word count and a
 * human label used in the dropdown.
 *
 * @return array<string,array{words:int,label:string}>
 */
function themify_ai_lengths() {
	return array(
		'short'  => array( 'words' => 600, 'label' => __( 'Short (~600 words)', 'themify' ) ),
		'medium' => array( 'words' => 1200, 'label' => __( 'Medium (~1,200 words)', 'themify' ) ),
		'long'   => array( 'words' => 2000, 'label' => __( 'Long (~2,000 words)', 'themify' ) ),
	);
}

/* ============================================================ ADMIN PAGE / CARD */

/**
 * Register the "AI Writer" submenu (position 70).
 */
themify_register_admin_page( array(
	'slug'       => 'themify-ai-writer',
	'title'      => __( 'AI Writer', 'themify' ),
	'menu_title' => __( 'AI Writer', 'themify' ),
	'callback'   => 'themify_ai_writer_page',
	'position'   => 26,
) );

/**
 * Add the AI Writer card to the dashboard grid.
 */
add_filter( 'themify_dashboard_cards', 'themify_ai_dashboard_card' );

/**
 * Append the AI Writer dashboard card.
 *
 * @param array $cards Existing cards.
 * @return array
 */
function themify_ai_dashboard_card( $cards ) {
	$cards[] = array(
		'slug'     => 'themify-ai-writer',
		'title'    => __( 'AI Writer', 'themify' ),
		'desc'     => __( 'Draft SEO articles with Claude', 'themify' ),
		'icon'     => 'dashicons-edit',
		'position' => 26,
	);
	return $cards;
}

/* ============================================================ SETTINGS SAVE */

/**
 * Handle the settings POST (API key + model + default category). Uses its own
 * nonce + capability check via the shared themify_verify_save() guard.
 *
 * @return bool True when a valid save happened.
 */
function themify_ai_handle_settings_save() {
	if ( ! themify_verify_save( 'themify_ai_settings' ) ) {
		return false;
	}

	$raw = isset( $_POST['themify_ai'] ) && is_array( $_POST['themify_ai'] )
		? wp_unslash( $_POST['themify_ai'] )
		: array();

	$key      = isset( $raw['anthropic_api_key'] ) ? sanitize_text_field( $raw['anthropic_api_key'] ) : '';
	$model    = isset( $raw['ai_model'] ) ? sanitize_text_field( $raw['ai_model'] ) : '';
	$models   = themify_ai_models();
	$model    = isset( $models[ $model ] ) ? $model : themify_ai_default_model();
	$category = isset( $raw['ai_default_category'] ) ? absint( $raw['ai_default_category'] ) : 0;

	// Only persist a category that actually exists as a category term.
	if ( $category && ! term_exists( $category, 'category' ) ) {
		$category = 0;
	}

	themify_set_options( array(
		'anthropic_api_key'   => $key,
		'ai_model'            => $model,
		'ai_default_category' => $category ? (string) $category : '',
	) );

	add_settings_error( 'themify', 'ai_saved', __( 'AI Writer settings saved.', 'themify' ), 'success' );
	return true;
}

/* ============================================================ PAGE RENDER */

/**
 * Render the "AI Writer" admin console.
 */
function themify_ai_writer_page() {
	themify_ai_handle_settings_save();

	themify_admin_header(
		__( 'AI Writer', 'themify' ),
		__( 'Generate complete, SEO-optimized blog drafts with Claude, then save them straight to your posts. Set your Anthropic API key once and start writing.', 'themify' )
	);

	settings_errors( 'themify' );

	$key      = themify_ai_get_key();
	$model    = themify_ai_get_model();
	$category = (int) themify_get_option( 'ai_default_category', 0 );
	$models   = themify_ai_models();
	$tones    = themify_ai_tones();
	$lengths  = themify_ai_lengths();

	// -------------------------------------------------------------- Settings.
	echo '<form method="post" class="tf-form">';
	wp_nonce_field( 'themify_ai_settings', 'themify_nonce' );
	echo '<div class="tf-card">';
	echo '<h2 class="tf-card__title">' . esc_html__( 'Connection', 'themify' ) . '</h2>';
	echo '<p class="tf-card__desc">' . wp_kses_post( __( 'Your key is stored on this site and used only to generate drafts from the admin. Get one at <a href="https://console.anthropic.com/" target="_blank" rel="noopener">console.anthropic.com</a>.', 'themify' ) ) . '</p>';

	// API key.
	echo '<div class="tf-field tf-field--text">';
	echo '<label class="tf-field__label" for="tf-ai-key">' . esc_html__( 'Anthropic API key', 'themify' ) . '</label>';
	printf(
		'<input type="text" id="tf-ai-key" name="themify_ai[anthropic_api_key]" value="%s" placeholder="%s" class="tf-input" autocomplete="off" spellcheck="false" />',
		esc_attr( $key ),
		esc_attr__( 'sk-ant-...', 'themify' )
	);
	echo '<p class="tf-field__desc">' . esc_html__( 'Kept private. Never sent anywhere except Anthropic when you generate a draft.', 'themify' ) . '</p>';
	echo '</div>';

	// Model.
	echo '<div class="tf-field tf-field--select">';
	echo '<label class="tf-field__label" for="tf-ai-model">' . esc_html__( 'Model', 'themify' ) . '</label>';
	echo '<select id="tf-ai-model" name="themify_ai[ai_model]" class="tf-input tf-select">';
	foreach ( $models as $mv => $ml ) {
		printf(
			'<option value="%s" %s>%s</option>',
			esc_attr( $mv ),
			selected( $model, $mv, false ),
			esc_html( $ml )
		);
	}
	echo '</select>';
	echo '</div>';

	// Default category.
	echo '<div class="tf-field tf-field--select">';
	echo '<label class="tf-field__label" for="tf-ai-category">' . esc_html__( 'Default category for new drafts', 'themify' ) . '</label>';
	wp_dropdown_categories( array(
		'show_option_none'  => __( '— Uncategorized —', 'themify' ),
		'option_none_value' => 0,
		'hide_empty'        => false,
		'hierarchical'      => true,
		'name'              => 'themify_ai[ai_default_category]',
		'id'                => 'tf-ai-category',
		'class'             => 'tf-input tf-select',
		'selected'          => $category,
		'taxonomy'          => 'category',
	) );
	echo '<p class="tf-field__desc">' . esc_html__( 'Saved drafts are filed under this category.', 'themify' ) . '</p>';
	echo '</div>';

	echo '<p class="tf-form__actions"><button type="submit" class="button button-primary button-hero">' . esc_html__( 'Save settings', 'themify' ) . '</button></p>';
	echo '</div>'; // .tf-card
	echo '</form>';

	// ---------------------------------------------------------- Generation UI.
	echo '<div class="tf-card">';
	echo '<h2 class="tf-card__title">' . esc_html__( 'Generate a draft', 'themify' ) . '</h2>';

	if ( '' === $key ) {
		echo '<div class="tf-notice tf-notice--warn">' . esc_html__( 'Add your Anthropic API key above before generating a draft.', 'themify' ) . '</div>';
	} else {
		echo '<p class="tf-card__desc">' . esc_html__( 'Describe what you want. Claude returns a full article in clean HTML that you can preview and save as a draft.', 'themify' ) . '</p>';
	}

	// Topic / title.
	echo '<div class="tf-field tf-field--text">';
	echo '<label class="tf-field__label" for="tf-ai-topic">' . esc_html__( 'Topic or title', 'themify' ) . '</label>';
	printf(
		'<input type="text" id="tf-ai-topic" class="tf-input" placeholder="%s" />',
		esc_attr__( 'e.g. The complete beginner’s guide to composting at home', 'themify' )
	);
	echo '</div>';

	// Focus keyword.
	echo '<div class="tf-field tf-field--text">';
	echo '<label class="tf-field__label" for="tf-ai-keyword">' . esc_html__( 'Focus keyword', 'themify' ) . '</label>';
	printf(
		'<input type="text" id="tf-ai-keyword" class="tf-input" placeholder="%s" />',
		esc_attr__( 'e.g. home composting', 'themify' )
	);
	echo '<p class="tf-field__desc">' . esc_html__( 'The primary phrase the article should rank for.', 'themify' ) . '</p>';
	echo '</div>';

	// Tone.
	echo '<div class="tf-field tf-field--select">';
	echo '<label class="tf-field__label" for="tf-ai-tone">' . esc_html__( 'Tone', 'themify' ) . '</label>';
	echo '<select id="tf-ai-tone" class="tf-input tf-select">';
	foreach ( $tones as $tv => $tl ) {
		printf( '<option value="%s">%s</option>', esc_attr( $tv ), esc_html( $tl ) );
	}
	echo '</select>';
	echo '</div>';

	// Length.
	echo '<div class="tf-field tf-field--select">';
	echo '<label class="tf-field__label" for="tf-ai-length">' . esc_html__( 'Length', 'themify' ) . '</label>';
	echo '<select id="tf-ai-length" class="tf-input tf-select">';
	foreach ( $lengths as $lv => $ldata ) {
		printf(
			'<option value="%s" %s>%s</option>',
			esc_attr( $lv ),
			selected( 'medium', $lv, false ),
			esc_html( $ldata['label'] )
		);
	}
	echo '</select>';
	echo '</div>';

	// Include FAQ.
	echo '<div class="tf-field tf-field--checkbox">';
	echo '<label class="tf-switch">';
	echo '<input type="checkbox" id="tf-ai-faq" value="1" checked />';
	echo '<span class="tf-switch__track"></span>';
	echo '<span class="tf-switch__label">' . esc_html__( 'Include an FAQ section', 'themify' ) . '</span>';
	echo '</label>';
	echo '</div>';

	// Run button (drives the shared .tf-run AJAX handler). A tiny inline script
	// collects the form values into the button's data-payload before the click
	// bubbles to admin.js, which POSTs it as $_POST['payload'].
	echo '<div class="tf-actions">';
	printf(
		'<button type="button" id="tf-ai-generate" class="button button-primary tf-run" data-action="themify_ai_generate" data-target="#tf-ai-result" data-running="%s">%s</button>',
		esc_attr__( 'Generating…', 'themify' ),
		esc_html__( 'Generate draft', 'themify' )
	);
	echo '</div>';

	echo '<div id="tf-ai-result"></div>';
	echo '</div>'; // .tf-card

	themify_ai_writer_inline_script();

	themify_admin_footer();
}

/**
 * Print the small inline script that serialises the generation form into the
 * run button's data-payload (as JSON) just before admin.js reads it.
 *
 * Kept intentionally tiny and dependency-free. It only mirrors the visible form
 * controls into a JSON string; every value is re-sanitised server-side.
 */
function themify_ai_writer_inline_script() {
	?>
	<script>
	( function () {
		var btn = document.getElementById( 'tf-ai-generate' );
		if ( ! btn ) { return; }
		function collect() {
			var val = function ( id ) {
				var el = document.getElementById( id );
				return el ? el.value : '';
			};
			var payload = {
				topic:   val( 'tf-ai-topic' ),
				keyword: val( 'tf-ai-keyword' ),
				tone:    val( 'tf-ai-tone' ),
				length:  val( 'tf-ai-length' ),
				faq:     document.getElementById( 'tf-ai-faq' ) && document.getElementById( 'tf-ai-faq' ).checked ? 1 : 0
			};
			var json = JSON.stringify( payload );
			btn.setAttribute( 'data-payload', json );
			// admin.js reads btn.data('payload') via jQuery, which caches the
			// attribute on first access and won't re-read it on later clicks.
			// Update jQuery's own data store too so repeat generations send the
			// current form values, not the first click's.
			if ( window.jQuery ) { window.jQuery( btn ).data( 'payload', json ); }
		}
		// Run in the capture phase so data-payload is set before admin.js's
		// bubbling click handler reads btn.data( 'payload' ).
		btn.addEventListener( 'click', collect, true );
	} )();
	</script>
	<?php
}

/* ============================================================ AJAX: GENERATE */

/**
 * Shared guard for the AI Writer AJAX handlers: nonce + capability. Sends a JSON
 * error and dies on failure.
 */
function themify_ai_ajax_guard() {
	check_ajax_referer( 'themify_admin', 'nonce' );
	if ( ! current_user_can( THEMIFY_CAP ) ) {
		wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'themify' ) ) );
	}
}

/**
 * Render a .tf-notice string.
 *
 * @param string $message Message text (will be escaped).
 * @param string $type    'info' | 'warn'.
 * @return string
 */
function themify_ai_notice( $message, $type = 'warn' ) {
	$type = ( 'info' === $type ) ? 'info' : 'warn';
	return '<div class="tf-notice tf-notice--' . esc_attr( $type ) . '">' . esc_html( $message ) . '</div>';
}

/**
 * AJAX: generate an article draft with Claude.
 *
 * Reads the JSON payload the run button placed in $_POST['payload'], validates
 * every field, calls the Anthropic API, parses the returned HTML, caches it in
 * a per-user transient, and returns a preview panel plus a "Save as draft"
 * button.
 */
function themify_ai_generate() {
	themify_ai_ajax_guard();

	$key = themify_ai_get_key();
	if ( '' === $key ) {
		wp_send_json_error( array( 'html' => themify_ai_notice( __( 'No Anthropic API key is configured. Add one in the Connection card above.', 'themify' ) ) ) );
	}

	// Decode + sanitise the posted payload.
	$fields  = themify_ai_read_payload();
	$topic   = $fields['topic'];
	$keyword = $fields['keyword'];

	if ( '' === $topic ) {
		wp_send_json_error( array( 'html' => themify_ai_notice( __( 'Please enter a topic or title to write about.', 'themify' ) ) ) );
	}

	// Light rate limit so the (slow, costly) button can't be hammered.
	if ( themify_ai_rate_limited() ) {
		wp_send_json_error( array( 'html' => themify_ai_notice( __( 'Please wait a few seconds before generating another draft.', 'themify' ) ) ) );
	}

	$result = themify_ai_request( $key, $fields );

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'html' => themify_ai_notice( $result->get_error_message() ) ) );
	}

	$title = $result['title'];
	$html  = $result['html'];

	if ( '' === $html ) {
		wp_send_json_error( array( 'html' => themify_ai_notice( __( 'Claude returned an empty article. Try again or adjust your topic.', 'themify' ) ) ) );
	}

	// Cache the exact previewed draft so "Save as draft" persists what was shown.
	themify_ai_store_draft( $title, $html, $keyword );

	wp_send_json_success( array( 'html' => themify_ai_preview_html( $title, $html ) ) );
}
add_action( 'wp_ajax_themify_ai_generate', 'themify_ai_generate' );

/**
 * Read and sanitise the generation form payload from $_POST['payload'].
 *
 * The value is a JSON string set client-side by the run button. Every field is
 * validated against its allow-list here; unknown/invalid values fall back to
 * safe defaults.
 *
 * @return array{topic:string,keyword:string,tone:string,length:string,faq:bool}
 */
function themify_ai_read_payload() {
	$raw     = isset( $_POST['payload'] ) ? wp_unslash( $_POST['payload'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON string decoded + each field sanitised below.
	$decoded = is_string( $raw ) ? json_decode( $raw, true ) : array();
	if ( ! is_array( $decoded ) ) {
		$decoded = array();
	}

	$tones   = themify_ai_tones();
	$lengths = themify_ai_lengths();

	$tone   = isset( $decoded['tone'] ) ? sanitize_key( $decoded['tone'] ) : 'professional';
	$length = isset( $decoded['length'] ) ? sanitize_key( $decoded['length'] ) : 'medium';

	return array(
		'topic'   => isset( $decoded['topic'] ) ? sanitize_text_field( $decoded['topic'] ) : '',
		'keyword' => isset( $decoded['keyword'] ) ? sanitize_text_field( $decoded['keyword'] ) : '',
		'tone'    => isset( $tones[ $tone ] ) ? $tone : 'professional',
		'length'  => isset( $lengths[ $length ] ) ? $length : 'medium',
		'faq'     => ! empty( $decoded['faq'] ),
	);
}

/**
 * Very small per-user rate limit for the generate button.
 *
 * @return bool True when the caller should be throttled.
 */
function themify_ai_rate_limited() {
	$bucket = 'themify_ai_rl_' . get_current_user_id();
	if ( get_transient( $bucket ) ) {
		return true;
	}
	set_transient( $bucket, 1, 5 );
	return false;
}

/* ============================================================ ANTHROPIC CALL */

/**
 * Build the system + user prompt and call the Anthropic Messages API.
 *
 * @param string $key    API key.
 * @param array  $fields Sanitised form fields (topic/keyword/tone/length/faq).
 * @return array|WP_Error { @type string $title, @type string $html } or error.
 */
function themify_ai_request( $key, array $fields ) {
	$lengths     = themify_ai_lengths();
	$length_key  = isset( $lengths[ $fields['length'] ] ) ? $fields['length'] : 'medium';
	$target_words = (int) $lengths[ $length_key ]['words'];

	$tones     = themify_ai_tones();
	$tone_key  = isset( $tones[ $fields['tone'] ] ) ? $fields['tone'] : 'professional';
	$tone_word = strtolower( wp_strip_all_tags( $tones[ $tone_key ] ) );

	$model = themify_ai_get_model();

	// Give the model comfortable output headroom relative to the target length
	// (~1.7 tokens/word plus structure) but stay within sane bounds.
	$max_tokens = (int) min( 8000, max( 1500, round( $target_words * 2.2 ) ) );

	$system = themify_ai_system_prompt();
	$user   = themify_ai_user_prompt( $fields, $target_words, $tone_word );

	$body = array(
		'model'      => $model,
		'max_tokens' => $max_tokens,
		'system'     => $system,
		'messages'   => array(
			array(
				'role'    => 'user',
				'content' => $user,
			),
		),
	);

	$response = wp_remote_post( THEMIFY_AI_ENDPOINT, array(
		'timeout'     => 120,
		'redirection' => 0,
		'headers'     => array(
			'x-api-key'         => $key,
			'anthropic-version' => THEMIFY_AI_API_VERSION,
			'content-type'      => 'application/json',
			'accept'            => 'application/json',
		),
		'body'        => wp_json_encode( $body ),
	) );

	if ( is_wp_error( $response ) ) {
		return new WP_Error(
			'themify_ai_http',
			sprintf(
				/* translators: %s: transport error message */
				__( 'Could not reach the Anthropic API: %s', 'themify' ),
				$response->get_error_message()
			)
		);
	}

	$code    = (int) wp_remote_retrieve_response_code( $response );
	$raw     = wp_remote_retrieve_body( $response );
	$decoded = json_decode( $raw, true );

	if ( $code < 200 || $code >= 300 ) {
		return new WP_Error( 'themify_ai_status', themify_ai_error_message( $code, $decoded ) );
	}

	// Extract response.content[0].text — the returned article HTML.
	$text = '';
	if ( is_array( $decoded ) && isset( $decoded['content'] ) && is_array( $decoded['content'] ) ) {
		foreach ( $decoded['content'] as $block ) {
			if ( is_array( $block ) && isset( $block['type'], $block['text'] ) && 'text' === $block['type'] ) {
				$text .= (string) $block['text'];
			}
		}
	}

	$text = trim( $text );
	if ( '' === $text ) {
		return new WP_Error( 'themify_ai_empty', __( 'Claude returned no article text. Please try again.', 'themify' ) );
	}

	// Strip any stray markdown code fences the model may have wrapped around it.
	$text = themify_ai_strip_fences( $text );

	// Derive a title from the first heading, else fall back to the topic.
	$title = themify_ai_extract_title( $text, $fields['topic'] );

	// Sanitise the article for storage/preview (trusted admin content, but never raw).
	$html = wp_kses_post( $text );

	return array(
		'title' => $title,
		'html'  => $html,
	);
}

/**
 * The system prompt: defines Claude's role and the exact output contract.
 *
 * @return string
 */
function themify_ai_system_prompt() {
	return implode( ' ', array(
		'You are an expert SEO content writer and editor.',
		'You write engaging, accurate, well-structured blog articles that read naturally and rank well in search engines.',
		'You always return a single complete article as clean, semantic HTML only.',
		'Use <h2> and <h3> for section headings (never <h1> — the site adds the page title).',
		'Use <p> for paragraphs, <ul>/<ol> for lists, <strong>/<em> for emphasis, and <blockquote> where a pull-quote helps.',
		'Do NOT include <html>, <head>, <body>, inline styles, class attributes, scripts, or markdown code fences.',
		'Do NOT wrap the output in backticks. Return only the article HTML, nothing before or after it.',
	) );
}

/**
 * The user prompt: the concrete brief built from the form fields.
 *
 * @param array  $fields       Sanitised form fields.
 * @param int    $target_words Target word count.
 * @param string $tone_word    Lowercase tone label.
 * @return string
 */
function themify_ai_user_prompt( array $fields, $target_words, $tone_word ) {
	$topic   = $fields['topic'];
	$keyword = $fields['keyword'];

	$lines = array();
	$lines[] = sprintf( 'Write a complete, original, SEO-optimized blog article about: "%s".', $topic );

	if ( '' !== $keyword ) {
		$lines[] = sprintf(
			'The focus keyword is "%s". Use it naturally in the opening paragraph, in at least one <h2> heading, and a few more times throughout — never keyword-stuff.',
			$keyword
		);
	}

	$lines[] = sprintf( 'Write in a %s tone.', $tone_word );
	$lines[] = sprintf( 'Target roughly %d words.', (int) $target_words );
	$lines[] = 'Structure: a compelling introduction (no heading before it), then several logically organised sections each with an <h2> heading (use <h3> for sub-points where useful), and a short concluding section.';

	if ( ! empty( $fields['faq'] ) ) {
		$lines[] = 'End with a "Frequently Asked Questions" section: an <h2>Frequently Asked Questions</h2> heading followed by 3–5 question/answer pairs, each question as an <h3> and its answer as one or more <p> paragraphs.';
	}

	$lines[] = 'Make it genuinely useful and specific — concrete tips, examples, and clear explanations rather than filler.';
	$lines[] = 'Start the article with an <h2> that works as the post title.';
	$lines[] = 'Return only the article HTML.';

	return implode( "\n\n", $lines );
}

/**
 * Map an Anthropic error response to a clear, human-readable message.
 *
 * @param int   $code    HTTP status code.
 * @param mixed $decoded Decoded JSON body (array) or null.
 * @return string
 */
function themify_ai_error_message( $code, $decoded ) {
	// Prefer the API's own error message when present.
	$api_message = '';
	if ( is_array( $decoded ) && isset( $decoded['error'] ) && is_array( $decoded['error'] ) && isset( $decoded['error']['message'] ) ) {
		$api_message = sanitize_text_field( (string) $decoded['error']['message'] );
	}

	switch ( (int) $code ) {
		case 401:
			return __( 'Authentication failed — check that your Anthropic API key is correct.', 'themify' );
		case 403:
			return __( 'Access denied by Anthropic. Your API key may not have permission for this model.', 'themify' );
		case 404:
			return __( 'The selected model was not found. Pick a different model in the Connection card.', 'themify' );
		case 429:
			return __( 'Rate limited by Anthropic — you have sent too many requests. Wait a moment and try again.', 'themify' );
		case 400:
			return $api_message
				? sprintf( /* translators: %s: API error detail */ __( 'The request was rejected: %s', 'themify' ), $api_message )
				: __( 'The request was rejected by Anthropic. Please try a different topic.', 'themify' );
		case 500:
		case 529:
			return __( 'Anthropic is temporarily overloaded. Please try again in a moment.', 'themify' );
		default:
			if ( $api_message ) {
				return sprintf(
					/* translators: 1: HTTP status code, 2: API error detail */
					__( 'Anthropic returned an error (HTTP %1$d): %2$s', 'themify' ),
					(int) $code,
					$api_message
				);
			}
			return sprintf(
				/* translators: %d: HTTP status code */
				__( 'Anthropic returned an unexpected error (HTTP %d).', 'themify' ),
				(int) $code
			);
	}
}

/* ============================================================ TEXT HELPERS */

/**
 * Remove leading/trailing markdown code fences the model may have added around
 * the HTML (e.g. ```html ... ```), defensively.
 *
 * @param string $text Raw model text.
 * @return string
 */
function themify_ai_strip_fences( $text ) {
	$text = trim( $text );
	// Opening fence with optional language hint on its own line.
	$text = preg_replace( '/^```[a-z0-9]*\s*\n?/i', '', $text );
	// Closing fence.
	$text = preg_replace( '/\n?```\s*$/', '', $text );
	return trim( $text );
}

/**
 * Derive a post title from the article: the first heading's text, else the
 * user's topic. The chosen heading is removed from the body so it is not
 * duplicated once WordPress renders the post title.
 *
 * @param string $html  Article HTML (passed by reference so the heading can be stripped).
 * @param string $topic Fallback title (the user's topic).
 * @return string Plain-text title.
 */
function themify_ai_extract_title( &$html, $topic ) {
	$title = '';

	if ( preg_match( '/<h[12][^>]*>(.*?)<\/h[12]>/is', $html, $m ) ) {
		$candidate = trim( wp_strip_all_tags( $m[1] ) );
		if ( '' !== $candidate ) {
			$title = $candidate;
			// Remove that first heading from the body to avoid a duplicate H1/H2.
			$html = preg_replace( '/<h[12][^>]*>.*?<\/h[12]>/is', '', $html, 1 );
			$html = trim( (string) $html );
		}
	}

	if ( '' === $title ) {
		$title = trim( wp_strip_all_tags( $topic ) );
	}

	// Keep titles to a sane length.
	if ( function_exists( 'mb_substr' ) && mb_strlen( $title ) > 160 ) {
		$title = rtrim( mb_substr( $title, 0, 157 ) ) . '…';
	} elseif ( strlen( $title ) > 160 ) {
		$title = rtrim( substr( $title, 0, 157 ) ) . '…';
	}

	return $title;
}

/* ============================================================ PREVIEW / DRAFT CACHE */

/**
 * Build the preview panel returned to the browser after a successful generate.
 * Shows the derived title, a rendered preview of the article, and a "Save as
 * draft" run button.
 *
 * @param string $title Derived post title.
 * @param string $html  Sanitised article HTML.
 * @return string
 */
function themify_ai_preview_html( $title, $html ) {
	$out  = themify_ai_notice( __( 'Draft generated. Review it below, then save it as a post draft.', 'themify' ), 'info' );

	$out .= '<div class="tf-card" style="margin-top:16px;">';
	$out .= '<h2 class="tf-card__title">' . esc_html( $title ) . '</h2>';

	// Actions row with the Save-as-draft run button.
	$out .= '<div class="tf-actions">';
	$out .= sprintf(
		'<button type="button" class="button button-primary tf-run" data-action="themify_ai_save" data-target="#tf-ai-save-result" data-running="%s">%s</button>',
		esc_attr__( 'Saving…', 'themify' ),
		esc_html__( 'Save as draft', 'themify' )
	);
	$out .= '</div>';
	$out .= '<div id="tf-ai-save-result"></div>';

	// The preview itself — trusted admin-generated content, already run through
	// wp_kses_post(), rendered inside a bordered box.
	$out .= '<div class="tf-content" style="margin-top:16px;padding:18px;border:1px solid #e2e8ec;border-radius:12px;background:#fff;">';
	$out .= $html; // Already sanitised via wp_kses_post() in themify_ai_request().
	$out .= '</div>';

	$out .= '</div>'; // .tf-card

	return $out;
}

/**
 * Cache the most recently generated draft for the current user (transient).
 *
 * @param string $title   Post title.
 * @param string $html    Sanitised article HTML.
 * @param string $keyword Focus keyword (stored for reference).
 */
function themify_ai_store_draft( $title, $html, $keyword ) {
	set_transient(
		THEMIFY_AI_LAST_DRAFT . get_current_user_id(),
		array(
			'title'   => $title,
			'html'    => $html,
			'keyword' => $keyword,
			'time'    => time(),
		),
		HOUR_IN_SECONDS
	);
}

/**
 * Read the current user's cached draft.
 *
 * @return array|null
 */
function themify_ai_get_stored_draft() {
	$draft = get_transient( THEMIFY_AI_LAST_DRAFT . get_current_user_id() );
	return is_array( $draft ) ? $draft : null;
}

/* ============================================================ AJAX: SAVE DRAFT */

/**
 * AJAX: save the previously generated article as a draft post.
 *
 * Persists the transient-cached draft with wp_insert_post (status 'draft'),
 * files it under the configured default category, tags the focus keyword, and
 * returns a link to edit the new draft.
 */
function themify_ai_save() {
	themify_ai_ajax_guard();

	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_send_json_error( array( 'html' => themify_ai_notice( __( 'You are not allowed to create posts.', 'themify' ) ) ) );
	}

	$draft = themify_ai_get_stored_draft();
	if ( ! $draft || empty( $draft['html'] ) ) {
		wp_send_json_error( array( 'html' => themify_ai_notice( __( 'No generated draft was found. Generate one first, then save.', 'themify' ) ) ) );
	}

	$title = isset( $draft['title'] ) && '' !== $draft['title']
		? (string) $draft['title']
		: __( 'AI draft', 'themify' );

	// Re-sanitise defensively even though the stored HTML was already filtered.
	$content = wp_kses_post( (string) $draft['html'] );

	$postarr = array(
		'post_title'   => $title,
		'post_content' => $content,
		'post_status'  => 'draft',
		'post_type'    => 'post',
		'post_author'  => get_current_user_id(),
	);

	$category = (int) themify_get_option( 'ai_default_category', 0 );
	if ( $category && term_exists( $category, 'category' ) ) {
		$postarr['post_category'] = array( $category );
	}

	$post_id = wp_insert_post( wp_slash( $postarr ), true );

	if ( is_wp_error( $post_id ) || ! $post_id ) {
		$message = is_wp_error( $post_id ) ? $post_id->get_error_message() : __( 'The draft could not be saved.', 'themify' );
		wp_send_json_error( array( 'html' => themify_ai_notice( $message ) ) );
	}

	// Add the focus keyword as a tag, if one was provided.
	if ( ! empty( $draft['keyword'] ) ) {
		wp_set_post_tags( $post_id, array( sanitize_text_field( (string) $draft['keyword'] ) ), true );
	}

	// One-time use: clear the cached draft so it can't be double-saved.
	delete_transient( THEMIFY_AI_LAST_DRAFT . get_current_user_id() );

	$edit_link = get_edit_post_link( $post_id, 'raw' );

	$html  = themify_ai_notice( __( 'Saved as a draft.', 'themify' ), 'info' );
	$html .= '<div class="tf-actions" style="margin-top:12px;">';
	$html .= sprintf(
		'<a class="button button-primary" href="%s">%s</a>',
		esc_url( $edit_link ),
		esc_html__( 'Edit draft', 'themify' )
	);
	$view_link = get_preview_post_link( $post_id );
	if ( $view_link ) {
		$html .= sprintf(
			'<a class="button" href="%s" target="_blank" rel="noopener">%s</a>',
			esc_url( $view_link ),
			esc_html__( 'Preview', 'themify' )
		);
	}
	$html .= '</div>';

	wp_send_json_success( array( 'html' => $html ) );
}
add_action( 'wp_ajax_themify_ai_save', 'themify_ai_save' );
