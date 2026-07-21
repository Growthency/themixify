<?php
/**
 * Asset pipeline — front-end and editor.
 *
 * Strategy for a 100/100 score:
 *   1. Inline a tiny "critical CSS" block in <head> so the page paints its
 *      background, container and header instantly, with zero network wait.
 *   2. Ship ONE main stylesheet (assets/css/main.css) for everything else.
 *   3. Ship ONE tiny deferred script (assets/js/main.js), no jQuery.
 *   4. Fonts default to a fast native stack; Google Fonts load only if the
 *      owner opts in (Appearance → Themify → Typography).
 *
 * @package Themify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Front-end styles and scripts.
 */
function themify_enqueue_assets() {
	$ver = THEMIFY_VERSION;

	// Main stylesheet — the single source of truth for front-end styles.
	wp_enqueue_style(
		'themify-main',
		THEMIFY_ASSETS . '/css/main.css',
		array(),
		$ver
	);

	// Brand color / typography overrides. These MUST be attached to the main
	// stylesheet (not just inlined in the critical <head> block) so they print
	// AFTER main.css and actually win the cascade — otherwise main.css's own
	// :root defaults, loading later, would clobber the admin-chosen palette and
	// saved colors would never show. Still render-blocking-in-head, so no flash.
	if ( function_exists( 'themify_color_overrides_css' ) ) {
		$overrides = themify_color_overrides_css();
		if ( $overrides ) {
			wp_add_inline_style( 'themify-main', $overrides );
		}
	}

	// Optional Google Fonts (Inter + Playfair Display) — only if enabled.
	if ( themify_is_enabled( 'load_google_fonts', false ) ) {
		wp_enqueue_style(
			'themify-fonts',
			'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Playfair+Display:ital,wght@0,700;1,700&display=swap',
			array(),
			null
		);
	}

	// Theme JS — no dependencies, loaded in the footer, deferred by the
	// performance module. Keep it framework-free.
	wp_enqueue_script(
		'themify-main',
		THEMIFY_ASSETS . '/js/main.js',
		array(),
		$ver,
		true
	);

	wp_localize_script( 'themify-main', 'themifyData', array(
		'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
		'restUrl'   => esc_url_raw( rest_url() ),
		'nonce'     => wp_create_nonce( 'themify_front' ),
		'stickyNav' => themify_is_enabled( 'sticky_header', true ),
	) );

	// Threaded comments where enabled.
	if ( is_singular() && comments_open() && get_option( 'thread_comments' ) ) {
		wp_enqueue_script( 'comment-reply' );
	}
}
add_action( 'wp_enqueue_scripts', 'themify_enqueue_assets' );

/**
 * Inline critical CSS as early as possible in <head>. This is the paint the
 * user sees before main.css lands. It mirrors the default design tokens; the
 * theme-colors module may print an override block after this that wins.
 *
 * Printed at priority 1 so it precedes everything else in the head.
 */
function themify_critical_css() {
	// Default tokens — kept in sync with :root in assets/css/main.css.
	$css = <<<CSS
:root{--tf-body-bg:linear-gradient(145deg,#ecfaf1 0%,#d6efde 40%,#c8e8d2 70%,#ecfaf1 100%);--tf-bg-card:#ffffff;--tf-bg-nav:rgba(236,249,241,.92);--tf-text:#081a0c;--tf-text-muted:rgba(8,26,12,.72);--tf-border:rgba(20,100,38,.13);--tf-accent:#156b28;--tf-btn:#156b28;--tf-btn-hover:#1e8f38;--tf-radius:16px;--tf-maxw:1200px;--tf-nav-h:68px;--tf-font-body:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;--tf-font-head:"Playfair Display",Georgia,"Times New Roman",serif}
*{box-sizing:border-box}html{-webkit-text-size-adjust:100%}
html,body{margin:0;padding:0}
body{background:var(--tf-body-bg);color:var(--tf-text);font-family:var(--tf-font-body);line-height:1.65;min-height:100vh;-webkit-font-smoothing:antialiased;overflow-x:hidden}
img{max-width:100%;height:auto}
.tf-container{max-width:var(--tf-maxw);margin-inline:auto;padding-inline:clamp(16px,4vw,32px)}
.tf-site-header{position:relative;z-index:50;min-height:var(--tf-nav-h);background:var(--tf-bg-nav);border-bottom:1px solid var(--tf-border);backdrop-filter:saturate(1.4) blur(10px)}
.tf-skip-link{position:absolute;left:-9999px}
a{color:var(--tf-accent);text-decoration:none}
h1,h2,h3,h4{font-family:var(--tf-font-head);line-height:1.15;font-weight:700}
CSS;

	// Owner-managed theme color overrides are applied here too when present,
	// so the very first paint already uses the brand palette (no flash).
	if ( function_exists( 'themify_color_overrides_css' ) ) {
		$override = themify_color_overrides_css();
		if ( $override ) {
			$css .= "\n" . $override;
		}
	}

	echo "<style id=\"themify-critical\">" . themify_minify_css( $css ) . "</style>\n";
}
add_action( 'wp_head', 'themify_critical_css', 1 );

/**
 * Ultra-light CSS minifier for inline blocks: strips comments and collapses
 * whitespace. Good enough for our small hand-written critical CSS.
 *
 * @param string $css Raw CSS.
 * @return string
 */
function themify_minify_css( $css ) {
	$css = preg_replace( '#/\*.*?\*/#s', '', $css );
	$css = preg_replace( '/\s+/', ' ', $css );
	$css = str_replace( array( ' {', '{ ', ' }', '} ', '; ', ': ', ', ' ), array( '{', '{', '}', '}', ';', ':', ',' ), $css );
	return trim( $css );
}

/**
 * Admin assets — only on Themify screens (identified by the page slug prefix).
 *
 * @param string $hook Current admin page hook.
 */
function themify_admin_assets( $hook ) {
	if ( false === strpos( (string) $hook, THEMIFY_ADMIN_SLUG ) ) {
		return;
	}
	wp_enqueue_style(
		'themify-admin',
		THEMIFY_ASSETS . '/css/admin.css',
		array(),
		THEMIFY_VERSION
	);
	wp_enqueue_script(
		'themify-admin',
		THEMIFY_ASSETS . '/js/admin.js',
		array( 'wp-color-picker' ),
		THEMIFY_VERSION,
		true
	);
	wp_enqueue_style( 'wp-color-picker' );
	wp_enqueue_media();
	wp_localize_script( 'themify-admin', 'themifyAdmin', array(
		'ajaxUrl' => admin_url( 'admin-ajax.php' ),
		'nonce'   => wp_create_nonce( 'themify_admin' ),
	) );
}
add_action( 'admin_enqueue_scripts', 'themify_admin_assets' );
