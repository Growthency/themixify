<?php
/**
 * External Links — per-domain / per-URL nofollow rules for outbound links.
 *
 * Built for affiliate publishing: add a domain and every outbound link to that
 * domain (and its subdomains) in post content automatically gains
 * rel="nofollow"; add a specific URL to nofollow only that exact link. Each
 * rule carries an optional note and can be paused (Active toggle) without
 * deleting it.
 *
 *   - Rules live in their own option (list-shaped):
 *       array( 'pattern' => string, 'type' => 'domain'|'url',
 *              'note' => string, 'active' => bool )
 *   - The content filter runs on the front end only, merges rel tokens (an
 *     existing rel is preserved, nothing is duplicated) and never touches
 *     internal links, mailto:, tel: or in-page anchors.
 *   - No external HTTP anywhere in this module.
 *
 * This complements the Affiliate Links module: that one cloaks /go/ links and
 * has a global "nofollow everything external" switch; this one is the precise,
 * per-target rulebook.
 *
 * @package Themify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Option holding the rule list. */
if ( ! defined( 'THEMIFY_EXTLINKS_OPT' ) ) {
	define( 'THEMIFY_EXTLINKS_OPT', 'themify_external_links' );
}

/* -------------------------------------------------------------------------
 * DATA ACCESS
 * ---------------------------------------------------------------------- */

/**
 * Read all stored rules, normalised. Every row has pattern/type/note/active.
 *
 * @return array<int,array{pattern:string,type:string,note:string,active:bool}>
 */
function themify_extlinks_rules() {
	$raw = get_option( THEMIFY_EXTLINKS_OPT, array() );
	if ( ! is_array( $raw ) ) {
		return array();
	}
	$clean = array();
	foreach ( $raw as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$pattern = isset( $row['pattern'] ) ? trim( (string) $row['pattern'] ) : '';
		if ( '' === $pattern ) {
			continue;
		}
		$type    = ( isset( $row['type'] ) && 'url' === $row['type'] ) ? 'url' : 'domain';
		$clean[] = array(
			'pattern' => $pattern,
			'type'    => $type,
			'note'    => isset( $row['note'] ) ? (string) $row['note'] : '',
			'active'  => ! isset( $row['active'] ) || (bool) $row['active'],
		);
	}
	return $clean;
}

/**
 * Persist the rule list, re-indexed.
 *
 * @param array $rules Rule rows.
 */
function themify_extlinks_save( array $rules ) {
	update_option( THEMIFY_EXTLINKS_OPT, array_values( $rules ), false );
}

/**
 * Classify raw admin input as a domain or an exact-URL pattern and normalise
 * it: domains lose scheme/www/trailing bits and lowercase; URLs keep their
 * full form (scheme added when missing).
 *
 * @param string $input Raw "domain or URL" input.
 * @return array|null { pattern, type } or null when unusable.
 */
function themify_extlinks_parse_input( $input ) {
	$input = trim( (string) $input );
	if ( '' === $input ) {
		return null;
	}

	$has_scheme = (bool) preg_match( '#^https?://#i', $input );
	$has_path   = false;

	if ( $has_scheme ) {
		$path      = (string) wp_parse_url( $input, PHP_URL_PATH );
		$query     = (string) wp_parse_url( $input, PHP_URL_QUERY );
		$has_path  = ( '' !== $path && '/' !== $path ) || '' !== $query;
	} else {
		$has_path = false !== strpos( $input, '/' );
	}

	// A path/query means "this exact URL"; a bare host means "whole domain".
	if ( $has_path ) {
		$url = $has_scheme ? $input : 'https://' . ltrim( $input, '/' );
		$url = esc_url_raw( $url );
		if ( '' === $url ) {
			return null;
		}
		return array(
			'pattern' => $url,
			'type'    => 'url',
		);
	}

	$host = $has_scheme ? (string) wp_parse_url( $input, PHP_URL_HOST ) : $input;
	$host = strtolower( trim( $host ) );
	$host = preg_replace( '/^www\./', '', $host );
	$host = rtrim( $host, '/.' );
	if ( '' === $host || false === strpos( $host, '.' ) ) {
		return null;
	}
	return array(
		'pattern' => $host,
		'type'    => 'domain',
	);
}

/* -------------------------------------------------------------------------
 * FRONT-END CONTENT FILTER
 * ---------------------------------------------------------------------- */

/**
 * Add rel="nofollow" to outbound links in post content that match an active
 * rule. Existing rel tokens are preserved and merged.
 *
 * @param string $content Post content HTML.
 * @return string
 */
function themify_extlinks_filter_content( $content ) {
	if ( is_admin() || '' === $content || false === stripos( $content, '<a' ) ) {
		return $content;
	}

	$domains = array();
	$urls    = array();
	foreach ( themify_extlinks_rules() as $rule ) {
		if ( ! $rule['active'] ) {
			continue;
		}
		if ( 'domain' === $rule['type'] ) {
			$domains[] = strtolower( $rule['pattern'] );
		} else {
			$urls[] = untrailingslashit( strtolower( $rule['pattern'] ) );
		}
	}
	if ( empty( $domains ) && empty( $urls ) ) {
		return $content;
	}

	$site_host = strtolower( preg_replace( '/^www\./', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) );

	$filtered = preg_replace_callback(
		'/<a\b[^>]*>/i',
		function ( $m ) use ( $domains, $urls, $site_host ) {
			$tag = $m[0];

			if ( ! preg_match( '/\bhref\s*=\s*("|\')(.*?)\1/is', $tag, $h ) ) {
				return $tag;
			}
			$href = trim( $h[2] );
			if ( '' === $href || '#' === $href[0]
				|| 0 === stripos( $href, 'mailto:' )
				|| 0 === stripos( $href, 'tel:' )
				|| 0 === strpos( $href, '/' ) ) {
				return $tag;
			}

			$host = strtolower( (string) wp_parse_url( $href, PHP_URL_HOST ) );
			if ( '' === $host ) {
				return $tag;
			}
			$bare_host = preg_replace( '/^www\./', '', $host );

			// Never touch internal links.
			if ( $bare_host === $site_host ) {
				return $tag;
			}

			$match = false;
			foreach ( $domains as $domain ) {
				if ( $bare_host === $domain || $host === $domain
					|| str_ends_with( $bare_host, '.' . $domain ) ) {
					$match = true;
					break;
				}
			}
			if ( ! $match && ! empty( $urls ) ) {
				$norm = untrailingslashit( strtolower( $href ) );
				$alt  = preg_replace( '#^(https?://)www\.#', '$1', $norm );
				if ( in_array( $norm, $urls, true ) || in_array( $alt, $urls, true ) ) {
					$match = true;
				}
			}
			if ( ! $match ) {
				return $tag;
			}

			// Merge nofollow into any existing rel (never duplicate tokens).
			if ( preg_match( '/\brel\s*=\s*("|\')(.*?)\1/is', $tag, $r ) ) {
				$tokens = preg_split( '/\s+/', strtolower( trim( $r[2] ) ) );
				if ( ! in_array( 'nofollow', $tokens, true ) ) {
					$tokens[] = 'nofollow';
					$tag      = str_replace( $r[0], 'rel=' . $r[1] . esc_attr( implode( ' ', array_filter( $tokens ) ) ) . $r[1], $tag );
				}
				return $tag;
			}
			return preg_replace( '/^<a\b/i', '<a rel="nofollow"', $tag );
		},
		$content
	);

	return is_string( $filtered ) ? $filtered : $content;
}
add_filter( 'the_content', 'themify_extlinks_filter_content', 99 );

/* -------------------------------------------------------------------------
 * ADMIN PAGE
 * ---------------------------------------------------------------------- */

themify_register_admin_page( array(
	'slug'       => 'themify-external-links',
	'title'      => __( 'External Links', 'themify' ),
	'menu_title' => __( 'External Links', 'themify' ),
	'callback'   => 'themify_extlinks_page',
	'position'   => 22,
) );

add_filter( 'themify_dashboard_cards', 'themify_extlinks_dashboard_card' );

/**
 * Append the External Links dashboard card.
 *
 * @param array $cards Existing cards.
 * @return array
 */
function themify_extlinks_dashboard_card( $cards ) {
	$cards[] = array(
		'slug'     => 'themify-external-links',
		'title'    => __( 'External Links', 'themify' ),
		'desc'     => __( 'Nofollow rules for outbound links', 'themify' ),
		'icon'     => 'dashicons-external',
		'position' => 22,
	);
	return $cards;
}

/**
 * Handle the add / toggle / delete actions for the External Links screen.
 *
 * @return string|null A notice message when an action ran, else null.
 */
function themify_extlinks_handle_actions() {
	if ( ! current_user_can( THEMIFY_CAP ) ) {
		return null;
	}

	// Add rule (POST).
	if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_POST['tfe_pattern'] ) ) {
		$nonce = isset( $_POST['themify_extlinks_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['themify_extlinks_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'themify_extlinks_add' ) ) {
			return null;
		}
		$parsed = themify_extlinks_parse_input( sanitize_text_field( wp_unslash( $_POST['tfe_pattern'] ) ) );
		if ( null === $parsed ) {
			return __( 'That does not look like a valid domain or URL.', 'themify' );
		}
		$rules = themify_extlinks_rules();
		foreach ( $rules as $rule ) {
			if ( $rule['pattern'] === $parsed['pattern'] && $rule['type'] === $parsed['type'] ) {
				return __( 'That rule already exists.', 'themify' );
			}
		}
		$rules[] = array(
			'pattern' => $parsed['pattern'],
			'type'    => $parsed['type'],
			'note'    => isset( $_POST['tfe_note'] ) ? sanitize_text_field( wp_unslash( $_POST['tfe_note'] ) ) : '',
			'active'  => true,
		);
		themify_extlinks_save( $rules );
		/* translators: %s: the rule pattern */
		return sprintf( __( 'Rule added: %s', 'themify' ), $parsed['pattern'] );
	}

	// Toggle / delete (GET + nonce).
	$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

	if ( isset( $_GET['tfe_toggle'] ) && wp_verify_nonce( $nonce, 'themify_extlinks_act' ) ) {
		$i     = (int) $_GET['tfe_toggle'];
		$rules = themify_extlinks_rules();
		if ( isset( $rules[ $i ] ) ) {
			$rules[ $i ]['active'] = ! $rules[ $i ]['active'];
			themify_extlinks_save( $rules );
			return $rules[ $i ]['active']
				? __( 'Rule activated.', 'themify' )
				: __( 'Rule paused.', 'themify' );
		}
	}

	if ( isset( $_GET['tfe_del'] ) && wp_verify_nonce( $nonce, 'themify_extlinks_act' ) ) {
		$i     = (int) $_GET['tfe_del'];
		$rules = themify_extlinks_rules();
		if ( isset( $rules[ $i ] ) ) {
			$removed = $rules[ $i ]['pattern'];
			unset( $rules[ $i ] );
			themify_extlinks_save( $rules );
			/* translators: %s: the rule pattern */
			return sprintf( __( 'Rule removed: %s', 'themify' ), $removed );
		}
	}

	return null;
}

/**
 * Print the External Links page CSS (brand palette).
 */
function themify_extlinks_print_assets() {
	?>
	<style>
	body[class*="themify-external-links"] #wpcontent{background:#f3f8f5}
	.tfe-pageicon{width:46px;height:46px;border-radius:13px;background:#1e8f38;display:flex;align-items:center;justify-content:center;flex:0 0 auto;box-shadow:0 5px 12px rgba(30,143,56,.35)}
	.tfe-pageicon .dashicons{color:#fff;font-size:23px;width:23px;height:23px}
	.tfe-add{padding:20px 22px}
	.tfe-add__title{display:flex;align-items:center;gap:8px;font-size:14.5px;font-weight:700;color:#1a2b20;margin:0 0 16px}
	.tfe-add__title .dashicons{color:#1e8f38;font-size:18px;width:18px;height:18px}
	.tfe-add__grid{display:grid;grid-template-columns:2fr 1fr;gap:16px}
	@media(max-width:900px){.tfe-add__grid{grid-template-columns:1fr}}
	.tfe-lbl{display:block;font-size:10.5px;font-weight:700;letter-spacing:.7px;text-transform:uppercase;color:#8fa096;margin-bottom:7px}
	.tfe-in{width:100%;border:1px solid #dbe4de;border-radius:10px;padding:11px 14px;font-size:13px;background:#fff;color:#1a2b20;box-shadow:0 1px 2px rgba(16,24,40,.04)}
	.tfe-in:focus{outline:none;border-color:#1e8f38;box-shadow:0 0 0 3px rgba(30,143,56,.12)}
	.tfe-addbtn{display:inline-flex;align-items:center;gap:7px;background:#1e8f38;color:#fff;border:none;border-radius:10px;padding:11px 22px;font-size:13px;font-weight:700;cursor:pointer;box-shadow:0 4px 10px rgba(30,143,56,.3);margin-top:16px;float:right}
	.tfe-addbtn:hover{background:#156b28}
	.tfe-addbtn .dashicons{font-size:16px;width:16px;height:16px}
	.tfe-pattern{font-family:Consolas,Monaco,monospace;font-size:12.5px;color:#1a2b20;font-weight:600;word-break:break-all}
	.tfe-badge{display:inline-flex;align-items:center;gap:5px;border-radius:8px;padding:4px 11px;font-size:11.5px;font-weight:700}
	.tfe-badge .dashicons{font-size:13px;width:13px;height:13px}
	.tfe-badge--domain{background:#e3f5e8;color:#156b28}
	.tfe-badge--url{background:#fdf3d9;color:#8a6d0b}
	.tfe-status{display:inline-flex;align-items:center;gap:5px;border-radius:8px;padding:5px 12px;font-size:11.5px;font-weight:700;text-decoration:none;transition:filter .15s}
	.tfe-status .dashicons{font-size:13px;width:13px;height:13px}
	.tfe-status--on{background:#e3f5e8;color:#156b28}
	.tfe-status--off{background:#eef2ef;color:#8fa096}
	.tfe-status:hover{filter:brightness(.95)}
	.tfe-del{display:inline-flex;padding:6px;border-radius:8px;color:#c0392b;text-decoration:none}
	.tfe-del:hover{background:#fbe3e0;color:#b0281a}
	.tfe-del .dashicons{font-size:17px;width:17px;height:17px}
	.tfe-note{color:#5a6b62;font-size:12.5px}
	.tfe-empty{padding:48px 22px;text-align:center;color:#5a6b62;font-size:14px}
	.tfe-empty .dashicons{font-size:34px;width:34px;height:34px;color:#c3cfc7;display:block;margin:0 auto 10px}
	</style>
	<?php
}

/**
 * Render the "External Links" admin screen.
 */
function themify_extlinks_page() {
	$notice = themify_extlinks_handle_actions();
	$rules  = themify_extlinks_rules();

	echo '<div class="wrap tfx">';
	if ( function_exists( 'themify_analytics_print_assets' ) ) {
		themify_analytics_print_assets();
	}
	themify_extlinks_print_assets();

	// ---- Header ----
	echo '<div class="tfx-head">';
	echo '<div style="display:flex;gap:14px;align-items:flex-start;">';
	echo '<span class="tfe-pageicon"><span class="dashicons dashicons-external"></span></span>';
	echo '<div>';
	echo '<h1>' . esc_html__( 'External Links', 'themify' ) . '</h1>';
	echo '<p class="tfx-sub">' . wp_kses_post( __( 'Add a domain to make every outbound link to that domain (and its subdomains) <code>rel="nofollow"</code>. Or add a specific URL to nofollow only that exact link — perfect for affiliate networks.', 'themify' ) ) . '</p>';
	echo '</div>';
	echo '</div>';
	echo '</div>';

	if ( null !== $notice ) {
		echo '<div class="tf-notice tf-notice--info">' . esc_html( $notice ) . '</div>';
	}

	// ---- Add-a-rule card ----
	echo '<div class="tfx-card tfe-add" style="margin-bottom:20px;">';
	echo '<h2 class="tfe-add__title"><span class="dashicons dashicons-plus-alt2"></span>' . esc_html__( 'Add a rule', 'themify' ) . '</h2>';
	echo '<form method="post">';
	wp_nonce_field( 'themify_extlinks_add', 'themify_extlinks_nonce' );
	echo '<div class="tfe-add__grid">';
	echo '<div>';
	echo '<label class="tfe-lbl" for="tfe-pattern">' . esc_html__( 'Domain or URL', 'themify' ) . '</label>';
	printf(
		'<input type="text" id="tfe-pattern" name="tfe_pattern" class="tfe-in" placeholder="%s" required />',
		esc_attr__( 'amazon.com  —or—  https://amazon.com/dp/B0XXXX', 'themify' )
	);
	echo '</div>';
	echo '<div>';
	echo '<label class="tfe-lbl" for="tfe-note">' . esc_html__( 'Note (optional)', 'themify' ) . '</label>';
	printf(
		'<input type="text" id="tfe-note" name="tfe_note" class="tfe-in" placeholder="%s" />',
		esc_attr__( 'Amazon affiliate', 'themify' )
	);
	echo '</div>';
	echo '</div>';
	echo '<button type="submit" class="tfe-addbtn"><span class="dashicons dashicons-plus-alt2"></span>' . esc_html__( 'Add rule', 'themify' ) . '</button>';
	echo '<div style="clear:both;"></div>';
	echo '</form>';
	echo '</div>';

	// ---- Rules table ----
	echo '<div class="tfx-card">';
	if ( empty( $rules ) ) {
		echo '<div class="tfe-empty"><span class="dashicons dashicons-external"></span>';
		echo esc_html__( 'No rules yet. Add a domain (e.g. amazon.com) above and every outbound link to it automatically becomes nofollow.', 'themify' );
		echo '</div>';
	} else {
		echo '<div class="tfx-tablewrap" style="max-height:none;">';
		echo '<table class="tfx-table"><thead><tr>';
		echo '<th>' . esc_html__( 'Pattern', 'themify' ) . '</th>';
		echo '<th>' . esc_html__( 'Type', 'themify' ) . '</th>';
		echo '<th>' . esc_html__( 'Note', 'themify' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'themify' ) . '</th>';
		echo '<th class="tfx-r">' . esc_html__( 'Actions', 'themify' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rules as $i => $rule ) {
			$toggle_url = wp_nonce_url(
				add_query_arg(
					array(
						'page'       => 'themify-external-links',
						'tfe_toggle' => $i,
					),
					admin_url( 'admin.php' )
				),
				'themify_extlinks_act'
			);
			$del_url = wp_nonce_url(
				add_query_arg(
					array(
						'page'    => 'themify-external-links',
						'tfe_del' => $i,
					),
					admin_url( 'admin.php' )
				),
				'themify_extlinks_act'
			);

			echo '<tr>';
			echo '<td><span class="tfe-pattern">' . esc_html( $rule['pattern'] ) . '</span></td>';
			echo '<td>' . ( 'domain' === $rule['type']
				? '<span class="tfe-badge tfe-badge--domain"><span class="dashicons dashicons-admin-site-alt3"></span>' . esc_html__( 'Domain', 'themify' ) . '</span>'
				: '<span class="tfe-badge tfe-badge--url"><span class="dashicons dashicons-admin-links"></span>' . esc_html__( 'URL', 'themify' ) . '</span>'
			) . '</td>';
			echo '<td><span class="tfe-note">' . esc_html( '' !== $rule['note'] ? $rule['note'] : '—' ) . '</span></td>';
			printf(
				'<td><a class="tfe-status %s" href="%s" title="%s"><span class="dashicons dashicons-controls-%s"></span>%s</a></td>',
				$rule['active'] ? 'tfe-status--on' : 'tfe-status--off',
				esc_url( $toggle_url ),
				esc_attr__( 'Click to toggle', 'themify' ),
				$rule['active'] ? 'pause' : 'play',
				esc_html( $rule['active'] ? __( 'Active', 'themify' ) : __( 'Paused', 'themify' ) )
			);
			printf(
				'<td class="tfx-r"><a class="tfe-del" href="%s" onclick="return confirm(%s);" title="%s"><span class="dashicons dashicons-trash"></span></a></td>',
				esc_url( $del_url ),
				esc_attr( wp_json_encode( __( 'Remove this rule?', 'themify' ) ) ),
				esc_attr__( 'Remove rule', 'themify' )
			);
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</div>';
	}
	echo '</div>';

	echo '<button type="button" class="tfx-top" aria-label="' . esc_attr__( 'Scroll to top', 'themify' ) . '"><span class="dashicons dashicons-arrow-up-alt2"></span></button>';
	echo '</div>'; // .tfx
}
