<?php
/**
 * Admin settings framework.
 *
 * A tiny declarative layer so every Themify admin screen looks and behaves
 * the same. Modules describe their fields as an array and call
 * themify_render_settings_page(); saving, nonce/cap checks, sanitizing and
 * the page chrome are all handled here.
 *
 * Screens that need bespoke UI (rank tracker, indexing report, SEO health,
 * analytics dashboard) skip the declarative renderer and instead call
 * themify_admin_header() / themify_admin_footer() directly to keep the shared
 * look, while doing their own body markup.
 *
 * @package Themify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Print the shared page header (gradient bar + title + tab-free chrome).
 *
 * @param string $title    Page H1.
 * @param string $subtitle Optional description under the title.
 */
function themify_admin_header( $title, $subtitle = '' ) {
	echo '<div class="wrap themify-admin">';
	echo '<div class="themify-admin__head">';
	echo '<div class="themify-admin__brand"><span class="themify-admin__logo" style="display:inline-flex;vertical-align:-4px;margin-right:6px;">'
		. '<svg width="20" height="20" viewBox="0 0 1024 1024" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">'
		. '<defs><linearGradient id="tfxlogo" x1="6%" y1="4%" x2="94%" y2="96%">'
		. '<stop offset="0%" stop-color="#b795f4"/><stop offset="42%" stop-color="#ee5f9d"/>'
		. '<stop offset="78%" stop-color="#f79f45"/><stop offset="100%" stop-color="#fbc93d"/>'
		. '</linearGradient></defs>'
		. '<rect x="0" y="0" width="1024" height="1024" rx="234" fill="url(#tfxlogo)"/>'
		. '<path d="M 300 738 L 300 348 L 512 600 L 724 348 L 724 738" fill="none" stroke="#fff" stroke-width="96" stroke-linecap="round" stroke-linejoin="round"/>'
		. '<circle cx="724" cy="238" r="52" fill="#fff"/>'
		. '</svg></span> Themixify <span style="opacity:.75;font-weight:600;text-transform:none;letter-spacing:0;">by <a href="https://www.writerify.org/" target="_blank" rel="noopener noreferrer" style="color:#fff;text-decoration:underline;">Writerify</a></span></div>';
	echo '<h1 class="themify-admin__title">' . esc_html( $title ) . '</h1>';
	if ( $subtitle ) {
		echo '<p class="themify-admin__subtitle">' . wp_kses_post( $subtitle ) . '</p>';
	}
	echo '</div>';
	echo '<div class="themify-admin__body">';
}

/**
 * Close the shared page wrapper.
 */
function themify_admin_footer() {
	echo '</div></div>'; // .themify-admin__body .themify-admin
}

/**
 * Guard: verify capability + nonce for a settings POST. Returns true only
 * when the current request is a valid, authorised save for $nonce_action.
 *
 * @param string $nonce_action Unique action name for this form.
 * @return bool
 */
function themify_verify_save( $nonce_action ) {
	if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
		return false;
	}
	if ( ! current_user_can( THEMIFY_CAP ) ) {
		return false;
	}
	$nonce = isset( $_POST['themify_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['themify_nonce'] ) ) : '';
	return (bool) wp_verify_nonce( $nonce, $nonce_action );
}

/**
 * Sanitize one field value according to its declared type.
 *
 * @param mixed $raw   Raw $_POST value (already unslashed by caller).
 * @param array $field Field definition.
 * @return mixed
 */
function themify_sanitize_field( $raw, array $field ) {
	$type = $field['type'] ?? 'text';
	switch ( $type ) {
		case 'checkbox':
			return $raw ? '1' : '';
		case 'textarea':
			return sanitize_textarea_field( $raw );
		case 'code':
			// Raw code (scripts/CSS). Callers store these behind capability
			// checks; do NOT strip tags. Only normalise line endings.
			return str_replace( "\r\n", "\n", (string) $raw );
		case 'email':
			return sanitize_email( $raw );
		case 'url':
			return esc_url_raw( trim( $raw ) );
		case 'number':
			return is_numeric( $raw ) ? $raw + 0 : '';
		case 'color':
			return preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', trim( $raw ) ) ? strtolower( trim( $raw ) ) : '';
		case 'select':
			$allowed = array_keys( $field['options'] ?? array() );
			return in_array( $raw, $allowed, true ) ? $raw : ( $field['default'] ?? '' );
		case 'text':
		default:
			return sanitize_text_field( $raw );
	}
}

/**
 * Render one form field row.
 *
 * @param array $field Field definition.
 */
function themify_render_field( array $field ) {
	$key   = $field['key'];
	$type  = $field['type'] ?? 'text';
	$label = $field['label'] ?? $key;
	$desc  = $field['desc'] ?? '';
	$ph    = $field['placeholder'] ?? '';
	$val   = themify_get_option( $key, $field['default'] ?? '' );
	$id    = 'tf_' . $key;
	$name  = THEMIFY_OPT . '[' . $key . ']';

	echo '<div class="tf-field tf-field--' . esc_attr( $type ) . '">';

	if ( 'checkbox' === $type ) {
		echo '<label class="tf-switch">';
		printf(
			'<input type="checkbox" id="%s" name="%s" value="1" %s />',
			esc_attr( $id ),
			esc_attr( $name ),
			checked( themify_is_enabled( $key, ! empty( $field['default'] ) ), true, false )
		);
		echo '<span class="tf-switch__track"></span>';
		echo '<span class="tf-switch__label">' . esc_html( $label ) . '</span>';
		echo '</label>';
	} else {
		printf( '<label class="tf-field__label" for="%s">%s</label>', esc_attr( $id ), esc_html( $label ) );

		switch ( $type ) {
			case 'textarea':
			case 'code':
				printf(
					'<textarea id="%s" name="%s" rows="%d" placeholder="%s" class="tf-input tf-textarea %s" spellcheck="false">%s</textarea>',
					esc_attr( $id ),
					esc_attr( $name ),
					(int) ( $field['rows'] ?? 5 ),
					esc_attr( $ph ),
					'code' === $type ? 'tf-code' : '',
					esc_textarea( $val )
				);
				break;

			case 'select':
				printf( '<select id="%s" name="%s" class="tf-input tf-select">', esc_attr( $id ), esc_attr( $name ) );
				foreach ( (array) ( $field['options'] ?? array() ) as $ov => $ol ) {
					printf( '<option value="%s" %s>%s</option>', esc_attr( $ov ), selected( $val, $ov, false ), esc_html( $ol ) );
				}
				echo '</select>';
				break;

			case 'color':
				printf(
					'<input type="text" id="%s" name="%s" value="%s" class="tf-color-picker" data-default-color="%s" />',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( $val ),
					esc_attr( $field['default'] ?? '' )
				);
				break;

			case 'media':
				printf(
					'<span class="tf-media"><img src="%s" class="tf-media__preview" alt=""%s /><input type="text" id="%s" name="%s" value="%s" class="tf-input tf-media__url" placeholder="%s" /><button type="button" class="button tf-media__pick">%s</button><button type="button" class="button-link tf-media__clear"%s>%s</button></span>',
					esc_url( $val ),
					$val ? '' : ' style="display:none"',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( $val ),
					esc_attr( $ph ),
					esc_html__( 'Choose', 'themify' ),
					$val ? '' : ' style="display:none"',
					esc_html__( 'Remove', 'themify' )
				);
				break;

			default: // text, url, email, number
				$input_type = in_array( $type, array( 'url', 'email', 'number' ), true ) ? $type : 'text';
				printf(
					'<input type="%s" id="%s" name="%s" value="%s" placeholder="%s" class="tf-input" />',
					esc_attr( $input_type ),
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( $val ),
					esc_attr( $ph )
				);
		}
	}

	if ( $desc ) {
		echo '<p class="tf-field__desc">' . wp_kses_post( $desc ) . '</p>';
	}
	echo '</div>';
}

/**
 * Fully render a declarative settings page: handle save, print chrome, and
 * render the form. Use for any screen that is just "fields → Save".
 *
 * @param array $config {
 *   @type string $title  Page title.
 *   @type string $intro  Optional intro HTML.
 *   @type string $nonce  Unique nonce action.
 *   @type array  $groups Optional array of ['title'=>.., 'fields'=>[..]] sections.
 *   @type array  $fields Flat field list (used when $groups is absent).
 * }
 */
function themify_render_settings_page( array $config ) {
	$nonce_action = $config['nonce'];

	// Collect fields (flat or grouped) into one list for saving.
	$all_fields = array();
	if ( ! empty( $config['groups'] ) ) {
		foreach ( $config['groups'] as $g ) {
			$all_fields = array_merge( $all_fields, $g['fields'] ?? array() );
		}
	} else {
		$all_fields = $config['fields'] ?? array();
	}

	// Handle save.
	if ( themify_verify_save( $nonce_action ) ) {
		$posted = isset( $_POST[ THEMIFY_OPT ] ) && is_array( $_POST[ THEMIFY_OPT ] )
			? wp_unslash( $_POST[ THEMIFY_OPT ] )
			: array();
		$to_save = array();
		foreach ( $all_fields as $field ) {
			$k             = $field['key'];
			$raw           = $posted[ $k ] ?? ( 'checkbox' === ( $field['type'] ?? '' ) ? '' : '' );
			$to_save[ $k ] = themify_sanitize_field( $raw, $field );
		}
		themify_set_options( $to_save );
		add_settings_error( 'themify', 'saved', __( 'Settings saved.', 'themify' ), 'success' );
	}

	themify_admin_header( $config['title'], $config['intro'] ?? '' );
	settings_errors( 'themify' );

	echo '<form method="post" class="tf-form">';
	wp_nonce_field( $nonce_action, 'themify_nonce' );

	if ( ! empty( $config['groups'] ) ) {
		foreach ( $config['groups'] as $group ) {
			echo '<div class="tf-card">';
			if ( ! empty( $group['title'] ) ) {
				echo '<h2 class="tf-card__title">' . esc_html( $group['title'] ) . '</h2>';
			}
			if ( ! empty( $group['desc'] ) ) {
				echo '<p class="tf-card__desc">' . wp_kses_post( $group['desc'] ) . '</p>';
			}
			foreach ( $group['fields'] as $field ) {
				themify_render_field( $field );
			}
			echo '</div>';
		}
	} else {
		echo '<div class="tf-card">';
		foreach ( $all_fields as $field ) {
			themify_render_field( $field );
		}
		echo '</div>';
	}

	echo '<p class="tf-form__actions"><button type="submit" class="button button-primary button-hero">' . esc_html__( 'Save Changes', 'themify' ) . '</button></p>';
	echo '</form>';

	// Optional extra content (its own forms/cards) before the page closes.
	if ( ! empty( $config['after'] ) && is_callable( $config['after'] ) ) {
		call_user_func( $config['after'] );
	}

	themify_admin_footer();
}
