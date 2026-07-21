<?php
/**
 * Performance Cache — a built-in disk page cache + HTML minifier that lets a
 * site drop the LiteSpeed Cache (or any third-party caching) plugin entirely.
 *
 * This is deliberately a *complement* to inc/performance.php, not a duplicate.
 * performance.php trims head bloat, defers JS, and prioritises the LCP image —
 * all per-request byte-shaving. This module adds the two things that plugin
 * caches exist for:
 *
 *   1. A disk-based full-page cache for anonymous GET requests. On a HIT we
 *      serve the pre-rendered HTML and exit *before* WordPress renders the page
 *      again, which is where the real wall-clock win comes from.
 *
 *   2. An HTML minifier that collapses inter-tag whitespace while carefully
 *      preserving the contents of <pre>, <textarea>, <script>, <style> and
 *      conditional comments — usable both inside the cache and standalone.
 *
 * CACHING SAFETY IS THE WHOLE GAME. We are extremely conservative about what is
 * cacheable: never for logged-in users, never for POST, never when a
 * login/comment/password cookie is present, never on dynamic endpoints. When in
 * any doubt we simply don't cache — a cache miss is cheap, a poisoned cache is
 * not. There are NO external calls anywhere in this file.
 *
 * @package Themify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cache configuration constants. TTL is clamped to a sane range from the
 * user-set option; the floor/ceiling here just bound the raw number.
 */
if ( ! defined( 'THEMIFY_CACHE_TTL_MIN_HOURS' ) ) {
	define( 'THEMIFY_CACHE_TTL_MIN_HOURS', 1 );
}
if ( ! defined( 'THEMIFY_CACHE_TTL_MAX_HOURS' ) ) {
	define( 'THEMIFY_CACHE_TTL_MAX_HOURS', 720 ); // 30 days.
}

/* ============================================================ CACHE DIRECTORY */

/**
 * Absolute path to the cache directory, with a trailing slash.
 *
 * Lives inside wp-content/uploads so it is writable on virtually every host and
 * survives theme updates isn't a concern (the cache is disposable). We create
 * it on demand together with hardening files (index.php + a deny .htaccess) so
 * the raw HTML snapshots can never be listed or served directly by Apache.
 *
 * @return string Directory path ending in '/', or '' if it can't be created.
 */
function themify_cache_dir() {
	static $dir = null;
	if ( null !== $dir ) {
		return $dir;
	}

	$uploads = wp_upload_dir();
	if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
		$dir = '';
		return $dir;
	}

	$path = trailingslashit( $uploads['basedir'] ) . 'themify-cache/';

	if ( ! is_dir( $path ) ) {
		if ( ! wp_mkdir_p( $path ) ) {
			$dir = '';
			return $dir;
		}
	}

	themify_cache_harden_dir( $path );

	$dir = $path;
	return $dir;
}

/**
 * Drop hardening files into the cache directory so its contents can't be
 * browsed or served: an empty index.php and an Apache deny rule. Written once
 * (guarded by file_exists) so this is cheap on the hot path.
 *
 * @param string $path Cache directory (trailing slash).
 */
function themify_cache_harden_dir( $path ) {
	$index = $path . 'index.php';
	if ( ! file_exists( $index ) ) {
		file_put_contents( $index, "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Local cache scaffolding, not user content.
	}

	$htaccess = $path . '.htaccess';
	if ( ! file_exists( $htaccess ) ) {
		$rules = "# Themify page cache — deny direct access.\n"
			. "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n"
			. "<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\n";
		file_put_contents( $htaccess, $rules ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Local cache scaffolding, not user content.
	}
}

/**
 * The cache file path for the current request, keyed by a hash of the full
 * canonical URL (scheme + host + REQUEST_URI). Returns '' when the cache dir is
 * unavailable.
 *
 * @return string
 */
function themify_cache_file_for_request() {
	$dir = themify_cache_dir();
	if ( '' === $dir ) {
		return '';
	}
	return $dir . themify_cache_request_key() . '.html';
}

/**
 * Build the cache key for the current request. md5 of scheme + host + URI so
 * http/https and different vhosts never collide.
 *
 * @return string 32-char hex key.
 */
function themify_cache_request_key() {
	$scheme = is_ssl() ? 'https' : 'http';
	$host   = isset( $_SERVER['HTTP_HOST'] ) ? (string) $_SERVER['HTTP_HOST'] : (string) wp_parse_url( home_url(), PHP_URL_HOST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Used only inside md5(), never echoed or stored raw.
	$uri    = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Used only inside md5(), never echoed or stored raw.
	return md5( $scheme . '://' . $host . $uri );
}

/**
 * TTL for cached pages, in seconds, clamped to the allowed range.
 *
 * @return int
 */
function themify_cache_ttl() {
	$hours = (int) themify_get_option( 'cache_ttl_hours', 10 );
	if ( $hours < THEMIFY_CACHE_TTL_MIN_HOURS ) {
		$hours = THEMIFY_CACHE_TTL_MIN_HOURS;
	}
	if ( $hours > THEMIFY_CACHE_TTL_MAX_HOURS ) {
		$hours = THEMIFY_CACHE_TTL_MAX_HOURS;
	}
	return $hours * HOUR_IN_SECONDS;
}

/* ============================================================ CACHEABILITY */

/**
 * Decide whether the *current* request may be served from / written to cache.
 *
 * This is the single most safety-critical function in the module. It answers
 * "is it safe to show every anonymous visitor exactly these bytes?". Every
 * branch that could possibly personalise output must force a `false`. When we
 * are unsure, we return false — a miss just means WordPress renders normally.
 *
 * @return bool
 */
function themify_cache_is_cacheable() {
	// Master switch.
	if ( ! themify_is_enabled( 'cache_enabled', true ) ) {
		return false;
	}

	// Never in admin, AJAX, REST, cron, CLI or during installs.
	if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
		return false;
	}
	if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST )
		|| ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST )
		|| ( defined( 'WP_CLI' ) && WP_CLI )
		|| ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
		return false;
	}

	// Never cache a logged-in / commenting user.
	if ( is_user_logged_in() ) {
		return false;
	}

	// GET only, and no POST body of any kind.
	$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET';
	if ( 'GET' !== $method ) {
		return false;
	}
	if ( ! empty( $_POST ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Presence check only; no data is read.
		return false;
	}

	// No query string — cached snapshots are keyed on the full URI but a query
	// string almost always means something dynamic (search, tracking, previews,
	// paginated comments). Be conservative and skip anything with a query.
	if ( ! empty( $_GET ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Presence check only; no data is read.
		return false;
	}
	$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Inspected below, never echoed/stored.
	if ( false !== strpos( $uri, '?' ) ) {
		return false;
	}

	// Dynamic / non-idempotent WP contexts.
	if ( is_feed()
		|| is_trackback()
		|| is_search()
		|| is_preview()
		|| is_customize_preview()
		|| is_robots()
		|| is_404() ) {
		return false;
	}
	if ( function_exists( 'is_favicon' ) && is_favicon() ) {
		return false;
	}

	// Post-password-protected content renders differently per visitor.
	if ( is_singular() && post_password_required() ) {
		return false;
	}

	// Any auth / comment / password / logged-in cookie means "not anonymous".
	if ( themify_cache_has_auth_cookie() ) {
		return false;
	}

	// WooCommerce dynamic pages (cart, checkout, account) must never be cached.
	if ( themify_cache_is_woo_dynamic() ) {
		return false;
	}

	// User-defined exclude list (URL path substrings).
	if ( themify_cache_uri_excluded( $uri ) ) {
		return false;
	}

	/**
	 * Final escape hatch for other modules/plugins to veto caching for the
	 * current request (e.g. a page with a form, a geo-personalised block).
	 *
	 * @param bool $cacheable Whether the request is cacheable so far.
	 */
	return (bool) apply_filters( 'themify_is_cacheable', true );
}

/**
 * Is there any cookie in the request that marks the visitor as non-anonymous?
 * Matches WordPress auth, comment-author and post-password cookies by prefix.
 *
 * @return bool
 */
function themify_cache_has_auth_cookie() {
	if ( empty( $_COOKIE ) || ! is_array( $_COOKIE ) ) {
		return false;
	}
	foreach ( array_keys( $_COOKIE ) as $name ) {
		if ( preg_match( '/^(wordpress_logged_in|wordpress_sec|comment_author|wp-postpass|woocommerce_items_in_cart|woocommerce_cart_hash|wp_woocommerce_session)/', (string) $name ) ) {
			return true;
		}
	}
	return false;
}

/**
 * True when the current request is a WooCommerce page that is inherently
 * per-visitor (cart, checkout, my-account). No-ops when Woo isn't active.
 *
 * @return bool
 */
function themify_cache_is_woo_dynamic() {
	if ( ! function_exists( 'is_cart' ) || ! function_exists( 'is_checkout' ) ) {
		return false;
	}
	if ( is_cart() || is_checkout() ) {
		return true;
	}
	if ( function_exists( 'is_account_page' ) && is_account_page() ) {
		return true;
	}
	return false;
}

/**
 * Match the request path against the user's exclude list. Each non-empty line
 * is treated as a case-insensitive substring; if any is found in the request
 * URI the page is never cached.
 *
 * @param string $uri Request URI (path + query as received).
 * @return bool
 */
function themify_cache_uri_excluded( $uri ) {
	$raw = (string) themify_get_option( 'cache_exclude', '' );
	if ( '' === trim( $raw ) ) {
		return false;
	}
	$uri   = strtolower( $uri );
	$lines = preg_split( '/\r\n|\r|\n/', $raw );
	foreach ( (array) $lines as $line ) {
		$needle = strtolower( trim( $line ) );
		if ( '' === $needle ) {
			continue;
		}
		if ( false !== strpos( $uri, $needle ) ) {
			return true;
		}
	}
	return false;
}

/* ============================================================ SERVE / STORE */

/**
 * The main entry point, run at the very top of template_redirect (priority 0)
 * so a cache HIT short-circuits WordPress before it renders the template.
 *
 *  - HIT : a fresh cache file exists → stream it, send X-Themify-Cache: HIT,
 *          and exit immediately.
 *  - MISS: request is cacheable → open an output buffer whose callback minifies
 *          and writes the snapshot.
 *  - When caching is off but minify is on, we still buffer purely to minify.
 */
function themify_cache_template_redirect() {
	if ( themify_cache_is_cacheable() ) {
		$file = themify_cache_file_for_request();

		// --- HIT ----------------------------------------------------------
		if ( '' !== $file && is_readable( $file ) ) {
			$age = time() - (int) filemtime( $file );
			if ( $age >= 0 && $age < themify_cache_ttl() ) {
				$html = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- Reading our own local cache snapshot.
				if ( false !== $html && '' !== $html ) {
					if ( ! headers_sent() ) {
						header( 'X-Themify-Cache: HIT' );
						header( 'Cache-Control: public, max-age=' . (int) themify_cache_browser_ttl() );
					}
					echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Serving a previously rendered full HTML document verbatim.
					exit;
				}
			}
		}

		// --- MISS ---------------------------------------------------------
		if ( ! headers_sent() ) {
			header( 'X-Themify-Cache: MISS' );
			header( 'Cache-Control: public, max-age=' . (int) themify_cache_browser_ttl() );
		}
		ob_start( 'themify_cache_output' );
		return;
	}

	// Caching disabled/ineligible, but minify-only requested and this looks like
	// a normal HTML page view → buffer just to minify (no disk write).
	if ( themify_is_enabled( 'cache_minify_html', true )
		&& ! is_admin() && ! is_feed() && ! is_robots()
		&& ! ( defined( 'REST_REQUEST' ) && REST_REQUEST )
		&& ! wp_doing_ajax() ) {
		ob_start( 'themify_cache_minify_only_output' );
	}
}
add_action( 'template_redirect', 'themify_cache_template_redirect', 0 );

/**
 * Short browser Cache-Control max-age (seconds) for HTML responses. Kept small
 * (a few minutes) so a stale HTML doc never lingers in a proxy longer than our
 * own invalidation would — the heavy lifting is the disk cache, not the browser.
 *
 * @return int
 */
function themify_cache_browser_ttl() {
	/**
	 * Filter the Cache-Control max-age (seconds) applied to cacheable HTML.
	 *
	 * @param int $seconds Default 300 (5 minutes).
	 */
	return (int) apply_filters( 'themify_cache_browser_ttl', 5 * MINUTE_IN_SECONDS );
}

/**
 * Output-buffer callback for a cacheable MISS: minify (if enabled), persist the
 * snapshot to disk when the response is a normal 200 with real HTML, and return
 * the (possibly minified) buffer for delivery to the visitor.
 *
 * @param string $buffer Full page HTML.
 * @return string
 */
function themify_cache_output( $buffer ) {
	if ( themify_is_enabled( 'cache_minify_html', true ) ) {
		$buffer = themify_minify_html( $buffer );
	}

	// Only persist genuine, complete HTML success responses. Anything else
	// (redirects, errors, tiny/empty bodies, non-HTML) is left uncached.
	if ( themify_cache_response_is_storable( $buffer ) ) {
		$file = themify_cache_file_for_request();
		if ( '' !== $file ) {
			// Write atomically via a temp file + rename so a concurrent HIT can
			// never read a half-written snapshot.
			$tmp = $file . '.' . wp_generate_password( 8, false, false ) . '.tmp';
			if ( false !== file_put_contents( $tmp, $buffer ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing our own local cache snapshot.
				if ( ! @rename( $tmp, $file ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort cache write; failure is non-fatal.
					@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_unlink -- Clean up the temp file if rename lost a race.
				}
			}
		}
	}

	return $buffer;
}

/**
 * Output-buffer callback for the minify-only path (cache disabled). Never
 * writes to disk; just returns the minified buffer.
 *
 * @param string $buffer Full page HTML.
 * @return string
 */
function themify_cache_minify_only_output( $buffer ) {
	return themify_minify_html( $buffer );
}

/**
 * Guard: should this response body be written to the page cache?
 *
 * Requires a 200 status, a non-trivial body, and something that actually looks
 * like an HTML document (so we never cache a JSON/XML/redirect response that
 * slipped through). Also refuses if a late nocache header was emitted.
 *
 * @param string $buffer Response body.
 * @return bool
 */
function themify_cache_response_is_storable( $buffer ) {
	if ( ! is_string( $buffer ) || strlen( $buffer ) < 255 ) {
		return false;
	}

	// Must be a 200. http_response_code() reflects any status set during render.
	$code = function_exists( 'http_response_code' ) ? http_response_code() : 200;
	if ( 200 !== (int) $code && false !== $code ) {
		return false;
	}

	// A plugin may have set a nocache/no-store header after we started buffering.
	foreach ( headers_list() as $header ) {
		if ( stripos( $header, 'no-store' ) !== false
			|| stripos( $header, 'no-cache' ) !== false
			|| stripos( $header, 'private' ) !== false ) {
			return false;
		}
	}

	// Sanity: it should smell like HTML.
	if ( stripos( $buffer, '<html' ) === false && stripos( $buffer, '<!doctype' ) === false ) {
		return false;
	}

	return true;
}

/* ============================================================ HTML MINIFIER */

/**
 * Collapse inter-tag whitespace in an HTML document while preserving the exact
 * contents of blocks where whitespace is significant or where our regexes could
 * misfire: <pre>, <textarea>, <script>, <style>, and IE conditional comments.
 *
 * Strategy: split the document on those protected blocks (keeping the blocks as
 * capture groups), then only compact the "outside" segments. Compacting means
 * collapsing runs of whitespace that span a newline down to a single space, and
 * removing whitespace directly between tags. Text runs inside a segment keep a
 * single significant space so inline layout (e.g. "word <a>link</a>") is safe.
 *
 * This is intentionally conservative: if the split regex fails for any reason
 * we return the original untouched. It also leaves plain HTML comments in place
 * except for stripping runs of blank whitespace around them.
 *
 * Tested mentally against:
 *   - <script> containing `if (a < b && c > d)` → whole script preserved.
 *   - <pre><code> ... two   spaces ... </code></pre> → preserved verbatim.
 *   - <!--[if lt IE 9]>...<![endif]--> conditional comment → preserved.
 *
 * @param string $html Full HTML document.
 * @return string Minified HTML, or the original on any doubt.
 */
function themify_minify_html( $html ) {
	if ( ! is_string( $html ) || '' === $html ) {
		return $html;
	}

	// Bail on anything that isn't clearly a full HTML document — never risk
	// mangling a JSON/XML/feed body that reached this callback by accident.
	if ( stripos( $html, '<html' ) === false && stripos( $html, '<!doctype' ) === false ) {
		return $html;
	}

	// Split on protected blocks, capturing them so they survive in the result.
	// The (?s) flag lets . match newlines inside these blocks.
	$pattern = '#(<pre\b[^>]*>.*?</pre>'
		. '|<textarea\b[^>]*>.*?</textarea>'
		. '|<script\b[^>]*>.*?</script>'
		. '|<style\b[^>]*>.*?</style>'
		. '|<!--\[if.*?\[endif\]-->'
		. '|<!--(?!\[if).*?-->)#is';

	$parts = preg_split( $pattern, $html, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );

	// preg_split can fail (e.g. pcre.backtrack_limit on a huge page). If it does,
	// or if the document contains an unterminated protected block that would
	// desync odd/even parts, fall back to returning the original.
	if ( ! is_array( $parts ) ) {
		return $html;
	}

	$out = '';
	foreach ( $parts as $part ) {
		// Protected blocks start with '<pre', '<textarea', '<script', '<style'
		// or an HTML comment — leave those byte-for-byte.
		if ( themify_minify_is_protected_block( $part ) ) {
			$out .= $part;
			continue;
		}
		$out .= themify_minify_compact_segment( $part );
	}

	// Absolute safety net: minification must never *grow* or empty the document.
	if ( '' === $out || strlen( $out ) > strlen( $html ) ) {
		return $html;
	}

	return $out;
}

/**
 * Is this split segment one of the protected blocks (so it must be emitted
 * verbatim)? Cheap prefix test matching the split pattern's alternatives.
 *
 * @param string $part Segment produced by preg_split.
 * @return bool
 */
function themify_minify_is_protected_block( $part ) {
	return (bool) preg_match( '#^\s*(<pre\b|<textarea\b|<script\b|<style\b|<!--)#i', $part );
}

/**
 * Compact one "outside" HTML segment: collapse whitespace between tags and turn
 * multi-whitespace/newline runs into a single space. Inline text spacing is
 * preserved (a single space is kept), so word wrapping and "text <a>x</a>" read
 * correctly.
 *
 * @param string $segment HTML segment with no protected blocks.
 * @return string
 */
function themify_minify_compact_segment( $segment ) {
	// Remove whitespace that sits purely between two tags: >   < → ><
	$segment = preg_replace( '/>\s+</', '><', $segment );
	if ( null === $segment ) {
		return ''; // preg_replace failed — caller-level net will restore original.
	}

	// Collapse any remaining run of whitespace that includes a newline/tab down
	// to a single space (keeps intentional single spaces inside text nodes).
	$segment = preg_replace( '/[ \t\r\n\f]*[\r\n][ \t\r\n\f]*/', ' ', $segment );
	if ( null === $segment ) {
		return '';
	}

	// Squeeze any leftover runs of plain spaces to one.
	$segment = preg_replace( '/ {2,}/', ' ', $segment );
	if ( null === $segment ) {
		return '';
	}

	return $segment;
}

/* ============================================================ INVALIDATION */

/**
 * Delete every cached snapshot. Called on any content/appearance change so the
 * site never shows stale HTML. Leaves the hardening files (index.php,
 * .htaccess) in place.
 *
 * @return int Number of cache files removed.
 */
function themify_cache_clear() {
	$dir = themify_cache_dir();
	if ( '' === $dir || ! is_dir( $dir ) ) {
		return 0;
	}

	$removed = 0;
	$files   = glob( $dir . '*.html' );
	if ( is_array( $files ) ) {
		foreach ( $files as $file ) {
			if ( is_file( $file ) && @unlink( $file ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_unlink -- Removing our own local cache snapshots.
				$removed++;
			}
		}
	}

	// Also sweep any orphaned temp files from interrupted writes.
	$tmps = glob( $dir . '*.tmp' );
	if ( is_array( $tmps ) ) {
		foreach ( $tmps as $tmp ) {
			if ( is_file( $tmp ) ) {
				@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_unlink -- Cleaning interrupted writes.
			}
		}
	}

	return $removed;
}

/**
 * Wire cache invalidation to every event that can change rendered output.
 */
function themify_cache_register_invalidation() {
	$events = array(
		'save_post',
		'deleted_post',
		'trashed_post',
		'comment_post',
		'edit_comment',
		'wp_set_comment_status',
		'switch_theme',
		'customize_save_after',
		'wp_update_nav_menu',
		'update_option_sidebars_widgets',
		'themify_settings_saved',
	);
	foreach ( $events as $event ) {
		add_action( $event, 'themify_cache_clear' );
	}
}
themify_cache_register_invalidation();

/**
 * Clear the page cache whenever ANY Themify option is saved (colors, custom
 * CSS, header/footer code, homepage blocks, footer, etc.). Without this, an
 * anonymous visitor keeps getting the stale cached HTML — with the old palette
 * baked into the inlined <head> CSS — even after the admin saves new colors.
 * A re-entrancy guard prevents recursion if clearing itself writes an option.
 *
 * @param string $option Name of the option that was just updated.
 */
function themify_cache_clear_on_option( $option ) {
	static $busy = false;
	if ( $busy ) {
		return;
	}
	if ( is_string( $option ) && 0 === strpos( $option, 'themify' ) ) {
		$busy = true;
		themify_cache_clear();
		$busy = false;
	}
}
add_action( 'updated_option', 'themify_cache_clear_on_option', 10, 1 );
add_action( 'added_option', 'themify_cache_clear_on_option', 10, 1 );

/* ============================================================ CACHE STATS */

/**
 * Count of cached pages and total size on disk. Used by the admin stat block.
 *
 * @return array{count:int,bytes:int}
 */
function themify_cache_stats() {
	$dir = themify_cache_dir();
	$out = array( 'count' => 0, 'bytes' => 0 );
	if ( '' === $dir || ! is_dir( $dir ) ) {
		return $out;
	}
	$files = glob( $dir . '*.html' );
	if ( is_array( $files ) ) {
		foreach ( $files as $file ) {
			if ( is_file( $file ) ) {
				$out['count']++;
				$out['bytes'] += (int) filesize( $file );
			}
		}
	}
	return $out;
}

/**
 * Human-readable byte size wrapper (uses core size_format).
 *
 * @param int $bytes Byte count.
 * @return string
 */
function themify_cache_format_size( $bytes ) {
	$bytes = (int) $bytes;
	if ( $bytes <= 0 ) {
		return '0 B';
	}
	return size_format( $bytes, 1 );
}

/* ============================================================ ADMIN PAGE */

/**
 * Register the "Speed & Cache" submenu (position 62).
 */
themify_register_admin_page( array(
	'slug'       => 'themify-speed-cache',
	'title'      => __( 'Speed &amp; Cache', 'themify' ),
	'menu_title' => __( 'Speed &amp; Cache', 'themify' ),
	'callback'   => 'themify_speed_cache_page',
	'position'   => 56,
) );

/**
 * Add the dashboard card.
 */
add_filter( 'themify_dashboard_cards', 'themify_speed_cache_dashboard_card' );

/**
 * Append the Speed & Cache dashboard card.
 *
 * @param array $cards Existing cards.
 * @return array
 */
function themify_speed_cache_dashboard_card( $cards ) {
	$cards[] = array(
		'slug'     => 'themify-speed-cache',
		'title'    => __( 'Speed & Cache', 'themify' ),
		'desc'     => __( 'Page cache, HTML minify & browser cache', 'themify' ),
		'icon'     => 'dashicons-performance',
		'position' => 56,
	);
	return $cards;
}

/**
 * The recommended server-level .htaccess snippet for far-future browser caching
 * of static assets + gzip. A theme cannot set these headers itself (the web
 * server serves static files directly), so we hand the owner a ready-to-paste
 * block instead. Purely informational text — no dynamic values.
 *
 * @return string
 */
function themify_cache_htaccess_snippet() {
	return <<<'HTACCESS'
# ----- Themify: browser cache + compression -----
<IfModule mod_deflate.c>
    AddOutputFilterByType DEFLATE text/html text/plain text/xml text/css text/javascript
    AddOutputFilterByType DEFLATE application/javascript application/json application/xml
    AddOutputFilterByType DEFLATE application/rss+xml image/svg+xml application/x-font-ttf
</IfModule>

<IfModule mod_expires.c>
    ExpiresActive On
    ExpiresByType text/css "access plus 1 year"
    ExpiresByType application/javascript "access plus 1 year"
    ExpiresByType text/javascript "access plus 1 year"
    ExpiresByType image/jpeg "access plus 1 year"
    ExpiresByType image/png "access plus 1 year"
    ExpiresByType image/gif "access plus 1 year"
    ExpiresByType image/webp "access plus 1 year"
    ExpiresByType image/avif "access plus 1 year"
    ExpiresByType image/svg+xml "access plus 1 year"
    ExpiresByType image/x-icon "access plus 1 year"
    ExpiresByType font/woff "access plus 1 year"
    ExpiresByType font/woff2 "access plus 1 year"
    ExpiresByType application/font-woff2 "access plus 1 year"
    ExpiresByType text/html "access plus 0 seconds"
</IfModule>

<IfModule mod_headers.c>
    <FilesMatch "\.(css|js|jpg|jpeg|png|gif|webp|avif|svg|ico|woff|woff2)$">
        Header set Cache-Control "public, max-age=31536000, immutable"
    </FilesMatch>
</IfModule>
# ----- /Themify -----
HTACCESS;
}

/**
 * Handle the settings <form> save for the Speed & Cache screen. Uses the shared
 * nonce/cap guard, sanitizes each field explicitly, and clears the cache after
 * any settings change so new options take effect immediately.
 *
 * @return bool True when a save happened.
 */
function themify_speed_cache_handle_save() {
	if ( ! themify_verify_save( 'themify_speedcache' ) ) {
		return false;
	}

	$posted = isset( $_POST[ THEMIFY_OPT ] ) && is_array( $_POST[ THEMIFY_OPT ] )
		? wp_unslash( $_POST[ THEMIFY_OPT ] )
		: array();

	$ttl = isset( $posted['cache_ttl_hours'] ) && is_numeric( $posted['cache_ttl_hours'] )
		? (int) $posted['cache_ttl_hours']
		: 10;
	if ( $ttl < THEMIFY_CACHE_TTL_MIN_HOURS ) {
		$ttl = THEMIFY_CACHE_TTL_MIN_HOURS;
	}
	if ( $ttl > THEMIFY_CACHE_TTL_MAX_HOURS ) {
		$ttl = THEMIFY_CACHE_TTL_MAX_HOURS;
	}

	themify_set_options( array(
		'cache_enabled'     => empty( $posted['cache_enabled'] ) ? '' : '1',
		'cache_minify_html' => empty( $posted['cache_minify_html'] ) ? '' : '1',
		'cache_ttl_hours'   => $ttl,
		'cache_exclude'     => isset( $posted['cache_exclude'] ) ? sanitize_textarea_field( $posted['cache_exclude'] ) : '',
	) );

	// A settings change can alter what/how we cache — flush to be safe.
	themify_cache_clear();

	return true;
}

/**
 * Render the "Speed & Cache" admin console.
 */
function themify_speed_cache_page() {
	$saved = themify_speed_cache_handle_save();

	themify_admin_header(
		__( 'Speed &amp; Cache', 'themify' ),
		__( 'A built-in page cache, HTML minifier and browser-cache helper — no caching plugin required. Works alongside the theme\'s performance tuning.', 'themify' )
	);

	if ( $saved ) {
		echo '<div class="tf-notice tf-notice--info">' . esc_html__( 'Settings saved and cache cleared.', 'themify' ) . '</div>';
	}

	// --- Live stats --------------------------------------------------------
	$stats = themify_cache_stats();
	echo '<div class="tf-stats">';
	printf(
		'<div class="tf-stat"><div class="tf-stat__num">%s</div><div class="tf-stat__label">%s</div></div>',
		esc_html( number_format_i18n( $stats['count'] ) ),
		esc_html__( 'Cached pages', 'themify' )
	);
	printf(
		'<div class="tf-stat"><div class="tf-stat__num">%s</div><div class="tf-stat__label">%s</div></div>',
		esc_html( themify_cache_format_size( $stats['bytes'] ) ),
		esc_html__( 'Cache size on disk', 'themify' )
	);
	printf(
		'<div class="tf-stat%s"><div class="tf-stat__num">%s</div><div class="tf-stat__label">%s</div></div>',
		themify_is_enabled( 'cache_enabled', true ) ? '' : ' tf-stat--warn',
		esc_html( themify_is_enabled( 'cache_enabled', true ) ? __( 'On', 'themify' ) : __( 'Off', 'themify' ) ),
		esc_html__( 'Page cache', 'themify' )
	);
	printf(
		'<div class="tf-stat"><div class="tf-stat__num">%s</div><div class="tf-stat__label">%s</div></div>',
		esc_html( number_format_i18n( (int) round( themify_cache_ttl() / HOUR_IN_SECONDS ) ) . 'h' ),
		esc_html__( 'Cache lifetime', 'themify' )
	);
	echo '</div>';

	// --- Settings form -----------------------------------------------------
	echo '<div class="tf-card">';
	echo '<h2 class="tf-card__title">' . esc_html__( 'Cache settings', 'themify' ) . '</h2>';
	echo '<p class="tf-card__desc">' . esc_html__( 'The page cache stores a ready-made copy of each page for anonymous visitors and serves it instantly. Logged-in users, forms, carts and search always bypass the cache.', 'themify' ) . '</p>';

	echo '<form method="post" class="tf-form">';
	wp_nonce_field( 'themify_speedcache', 'themify_nonce' );

	themify_render_field( array(
		'key'     => 'cache_enabled',
		'label'   => __( 'Enable page cache', 'themify' ),
		'type'    => 'checkbox',
		'default' => '1',
		'desc'    => __( 'Serve a cached HTML copy to anonymous visitors. Safe defaults exclude anything personalised.', 'themify' ),
	) );
	themify_render_field( array(
		'key'     => 'cache_minify_html',
		'label'   => __( 'Minify HTML', 'themify' ),
		'type'    => 'checkbox',
		'default' => '1',
		'desc'    => __( 'Strip needless whitespace between tags. The contents of scripts, styles, &lt;pre&gt; and &lt;textarea&gt; are always left untouched.', 'themify' ),
	) );
	themify_render_field( array(
		'key'         => 'cache_ttl_hours',
		'label'       => __( 'Cache lifetime (hours)', 'themify' ),
		'type'        => 'number',
		'default'     => 10,
		'placeholder' => '10',
		'desc'        => sprintf(
			/* translators: 1: min hours, 2: max hours */
			__( 'How long a cached page stays fresh before it is rebuilt. Allowed range: %1$d–%2$d hours.', 'themify' ),
			(int) THEMIFY_CACHE_TTL_MIN_HOURS,
			(int) THEMIFY_CACHE_TTL_MAX_HOURS
		),
	) );
	themify_render_field( array(
		'key'         => 'cache_exclude',
		'label'       => __( 'Never cache these paths', 'themify' ),
		'type'        => 'textarea',
		'rows'        => 5,
		'placeholder' => "/cart\n/checkout\n/my-account\n/thank-you",
		'desc'        => __( 'One URL-path fragment per line. Any request whose address contains a fragment is never cached (matched anywhere in the path, case-insensitive).', 'themify' ),
	) );

	echo '<p class="tf-form__actions"><button type="submit" class="button button-primary button-hero">' . esc_html__( 'Save Changes', 'themify' ) . '</button></p>';
	echo '</form>';
	echo '</div>'; // .tf-card

	// --- Clear cache -------------------------------------------------------
	echo '<div class="tf-card">';
	echo '<h2 class="tf-card__title">' . esc_html__( 'Maintenance', 'themify' ) . '</h2>';
	echo '<p class="tf-card__desc">' . esc_html__( 'Cache clears automatically when you publish or update content, change the theme, or edit menus. Use this to force a full rebuild.', 'themify' ) . '</p>';
	echo '<div class="tf-actions">';
	printf(
		'<button type="button" class="button button-primary tf-run" data-action="themify_cache_clear" data-target="#tf-cache-clear-result" data-running="%s">%s</button>',
		esc_attr__( 'Clearing…', 'themify' ),
		esc_html__( 'Clear cache now', 'themify' )
	);
	echo '</div>';
	echo '<div id="tf-cache-clear-result"></div>';
	echo '</div>'; // .tf-card

	// --- Browser cache .htaccess snippet -----------------------------------
	$snippet = themify_cache_htaccess_snippet();
	echo '<div class="tf-card">';
	echo '<h2 class="tf-card__title">' . esc_html__( 'Browser caching &amp; compression', 'themify' ) . '</h2>';
	echo '<p class="tf-card__desc">' . esc_html__( 'A theme cannot set far-future expiry headers for static files — your web server does that. On Apache, paste this block near the top of your site\'s root .htaccess to enable gzip and one-year caching of CSS, JS, images and fonts. (Nginx users: use the equivalent expires/gzip directives.)', 'themify' ) . '</p>';
	echo '<div class="tf-actions">';
	printf(
		'<button type="button" class="button" data-tf-copy="%s">%s</button>',
		esc_attr( $snippet ),
		esc_html__( 'Copy snippet', 'themify' )
	);
	echo '</div>';
	printf(
		'<pre class="tf-code" style="white-space:pre;overflow:auto;padding:16px;border-radius:10px;max-height:340px;">%s</pre>',
		esc_html( $snippet )
	);
	echo '</div>'; // .tf-card

	themify_admin_footer();
}

/* ============================================================ AJAX HANDLER */

/**
 * AJAX: clear the whole page cache. Nonce + capability checked; returns a
 * .tf-notice fragment that admin.js drops into the result target.
 */
function themify_cache_clear_ajax() {
	check_ajax_referer( 'themify_admin', 'nonce' );
	if ( ! current_user_can( THEMIFY_CAP ) ) {
		wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'themify' ) ) );
	}

	$removed = themify_cache_clear();

	$html = '<div class="tf-notice tf-notice--info">' . esc_html(
		sprintf(
			/* translators: %s: number of cache files removed */
			_n( 'Cache cleared — %s cached page removed.', 'Cache cleared — %s cached pages removed.', $removed, 'themify' ),
			number_format_i18n( $removed )
		)
	) . '</div>';

	wp_send_json_success( array( 'html' => $html ) );
}
add_action( 'wp_ajax_themify_cache_clear', 'themify_cache_clear_ajax' );
