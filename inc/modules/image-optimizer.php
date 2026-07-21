<?php
/**
 * Image Optimizer — a built-in replacement for image compression / WebP plugins
 * (Smush, EWWW, ShortPixel) using PHP's bundled GD library only.
 *
 * What it does, all locally (never any external API):
 *   1. On upload it can downscale oversized images, re-encode them at a chosen
 *      quality (which also strips EXIF/other metadata implicitly), and write a
 *      sibling .webp file next to the original and every generated thumbnail.
 *   2. On the front end it transparently serves those .webp siblings to browsers
 *      that advertise `image/webp` support, by rewriting <img> src/srcset URLs in
 *      post content and attachment HTML — but only for same-host uploads that
 *      actually have a .webp on disk.
 *   3. A "Bulk optimize" console walks existing attachments in bounded batches
 *      so a whole media library can be converted without a plugin or WP-CLI.
 *
 * Everything degrades gracefully: if GD (or GD's WebP support) is missing the
 * module still loads, the admin page explains the situation, and no hooks do any
 * work — the site is never broken. Failures during processing are swallowed and,
 * when WP_DEBUG is on, logged via error_log().
 *
 * @package Themify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Option key that stores the bulk-optimize progress offset (next attachment
 * index to process). Small scalar, but kept out of THEMIFY_OPT so a reset is a
 * single delete_option() and it never bloats the settings blob.
 */
if ( ! defined( 'THEMIFY_IMGOPT_OFFSET_OPT' ) ) {
	define( 'THEMIFY_IMGOPT_OFFSET_OPT', 'themify_imgopt_offset' );
}

/**
 * How many attachments the bulk optimizer processes per AJAX click. Kept small
 * so each request finishes well within PHP time/memory limits on shared hosts.
 */
if ( ! defined( 'THEMIFY_IMGOPT_BATCH' ) ) {
	define( 'THEMIFY_IMGOPT_BATCH', 20 );
}

/* ============================================================ CAPABILITY GATES */

/**
 * Whether the server can encode WebP at all. Requires GD compiled with WebP
 * support (imagewebp()). Everything WebP-related is gated behind this.
 *
 * @return bool
 */
function themify_imgopt_webp_supported() {
	return function_exists( 'imagewebp' ) && function_exists( 'imagecreatetruecolor' );
}

/**
 * Whether GD is available for the basic resize/re-encode work (JPEG/PNG). This
 * is a weaker requirement than WebP support; a server may have GD but no WebP.
 *
 * @return bool
 */
function themify_imgopt_gd_supported() {
	return function_exists( 'imagecreatetruecolor' )
		&& function_exists( 'imagecreatefromjpeg' )
		&& function_exists( 'imagecreatefrompng' );
}

/**
 * The image MIME types this module converts to WebP: JPEG and PNG always, plus
 * GIF and BMP when GD can read them. Single source of truth so every gate (the
 * upload hook, the bulk runner, the stats and the serving filter) stays in sync
 * and "any uploaded image becomes WebP" holds for every format GD supports.
 *
 * @return string[]
 */
function themify_imgopt_supported_mimes() {
	$mimes = array( 'image/jpeg', 'image/png' );
	if ( function_exists( 'imagecreatefromgif' ) ) {
		$mimes[] = 'image/gif';
	}
	if ( function_exists( 'imagecreatefrombmp' ) ) {
		$mimes[] = 'image/bmp';
	}
	return $mimes;
}

/**
 * Detect an animated GIF by counting its frame (Graphic Control Extension)
 * blocks. Animated GIFs are left untouched — GD only reads the first frame, so a
 * WebP copy would silently drop the animation.
 *
 * @param string $path GIF file path.
 * @return bool
 */
function themify_imgopt_is_animated_gif( $path ) {
	if ( ! is_readable( $path ) ) {
		return false;
	}
	// GIFs are small and their first two frames sit near the start, so a bounded
	// single read (4 MB) is enough and avoids chunk-boundary double counting.
	$data = @file_get_contents( $path, false, null, 0, 4 * 1024 * 1024 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	if ( false === $data || '' === $data ) {
		return false;
	}
	// Count Graphic Control Extension blocks: 0x21 0xF9 0x04 <4 bytes> 0x00 then an
	// image (0x2C) or another extension (0x21). Two or more ⇒ animated. We do NOT
	// require a preceding 0x00 — the first frame's GCE can follow the colour table
	// directly on GIFs without a NETSCAPE2.0 loop extension.
	return preg_match_all( '#\x21\xF9\x04.{4}\x00[\x2C\x21]#s', $data ) > 1;
}

/* ============================================================ SETTINGS ACCESS */

/**
 * Read the configured JPEG/WebP encode quality, clamped to a sane 40–100 range.
 *
 * @return int
 */
function themify_imgopt_quality() {
	$q = (int) themify_get_option( 'img_quality', 82 );
	if ( $q < 40 ) {
		$q = 40;
	}
	if ( $q > 100 ) {
		$q = 100;
	}
	return $q;
}

/**
 * Read the configured max width for downscaling. 0 (or negative) means "off".
 *
 * @return int
 */
function themify_imgopt_max_width() {
	$w = (int) themify_get_option( 'img_max_width', 2048 );
	return $w > 0 ? $w : 0;
}

/**
 * Debug logger — no-op unless WP_DEBUG is on. Keeps the upload pipeline silent
 * in production while giving developers a breadcrumb when a convert fails.
 *
 * @param string $message Message to log.
 */
function themify_imgopt_log( $message ) {
	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		error_log( '[themify image-optimizer] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Guarded by WP_DEBUG for developers only.
	}
}

/* ============================================================ GD PIPELINE */

/**
 * Load a GD image resource from a file, dispatching on MIME type. Returns null
 * on any failure (unsupported type, corrupt file, missing GD function).
 *
 * @param string $path File path.
 * @param string $mime MIME type ('image/jpeg' | 'image/png').
 * @return \GdImage|resource|null
 */
function themify_imgopt_load( $path, $mime ) {
	if ( ! is_readable( $path ) ) {
		return null;
	}
	// GD can fatal-free on malformed data but may emit warnings; suppress them.
	if ( 'image/jpeg' === $mime && function_exists( 'imagecreatefromjpeg' ) ) {
		$img = @imagecreatefromjpeg( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	} elseif ( 'image/png' === $mime && function_exists( 'imagecreatefrompng' ) ) {
		$img = @imagecreatefrompng( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	} elseif ( 'image/gif' === $mime && function_exists( 'imagecreatefromgif' ) ) {
		$img = @imagecreatefromgif( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	} elseif ( 'image/bmp' === $mime && function_exists( 'imagecreatefrombmp' ) ) {
		$img = @imagecreatefrombmp( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	} else {
		return null;
	}
	if ( ! $img ) {
		return null;
	}
	// Palette images (GIFs, 8-bit PNGs) must become truecolor or imagewebp() fails
	// and downscaling loses colour. Converting keeps any transparency intact.
	if ( function_exists( 'imageistruecolor' ) && ! imageistruecolor( $img ) && function_exists( 'imagepalettetotruecolor' ) ) {
		imagepalettetotruecolor( $img );
	}
	return $img;
}

/**
 * Preserve transparency when working with a PNG source so re-encoding and WebP
 * output do not turn transparent areas black.
 *
 * @param \GdImage|resource $dst Destination truecolor image.
 */
function themify_imgopt_prepare_alpha( $dst ) {
	if ( function_exists( 'imagealphablending' ) ) {
		imagealphablending( $dst, false );
	}
	if ( function_exists( 'imagesavealpha' ) ) {
		imagesavealpha( $dst, true );
	}
}

/**
 * Downscale $src into a new truecolor image no wider than $max_width, keeping
 * the aspect ratio. Returns the resampled image, or null if no resize is needed
 * or possible.
 *
 * @param \GdImage|resource $src       Source image.
 * @param int               $max_width Target maximum width in px.
 * @param bool              $is_png    Whether to preserve alpha.
 * @return \GdImage|resource|null New image, or null when no downscale applies.
 */
function themify_imgopt_downscale( $src, $max_width, $is_png ) {
	$w = imagesx( $src );
	$h = imagesy( $src );
	if ( $max_width <= 0 || $w <= $max_width || $w < 1 || $h < 1 ) {
		return null;
	}
	$new_w = $max_width;
	$new_h = (int) round( $h * ( $new_w / $w ) );
	if ( $new_h < 1 ) {
		$new_h = 1;
	}
	$dst = imagecreatetruecolor( $new_w, $new_h );
	if ( ! $dst ) {
		return null;
	}
	if ( $is_png ) {
		themify_imgopt_prepare_alpha( $dst );
	}
	imagecopyresampled( $dst, $src, 0, 0, 0, 0, $new_w, $new_h, $w, $h );
	return $dst;
}

/**
 * Write a GD image to a .webp sibling of $source_path at the given quality.
 * The sibling path is the source path with `.webp` appended (e.g.
 * photo.jpg → photo.jpg.webp) so it never collides with a real upload and is
 * trivially resolvable from a URL.
 *
 * @param \GdImage|resource $img         Image to encode.
 * @param string            $source_path Original file path (jpg/png).
 * @param int               $quality     WebP quality 0–100.
 * @return bool True on success.
 */
function themify_imgopt_write_webp( $img, $source_path, $quality ) {
	if ( ! themify_imgopt_webp_supported() ) {
		return false;
	}
	$webp_path = $source_path . '.webp';
	// GD requires alpha flags for correct WebP transparency.
	if ( function_exists( 'imagesavealpha' ) ) {
		imagesavealpha( $img, true );
	}
	$ok = @imagewebp( $img, $webp_path, $quality ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	if ( ! $ok ) {
		themify_imgopt_log( 'Failed to write WebP: ' . $webp_path );
		// GD occasionally leaves a truncated/zero-byte file on failure; remove it.
		if ( file_exists( $webp_path ) && 0 === (int) @filesize( $webp_path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@unlink( $webp_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}
	return (bool) $ok;
}

/**
 * Re-encode a GD image back over the original file at $quality, matching the
 * source format. Re-encoding drops embedded metadata (EXIF/IPTC/XMP), which is
 * how we "strip metadata". Returns true on success.
 *
 * @param \GdImage|resource $img     Image to encode.
 * @param string            $path    Destination path (the original file).
 * @param string            $mime    'image/jpeg' | 'image/png'.
 * @param int               $quality JPEG quality 0–100 (ignored for PNG).
 * @return bool
 */
function themify_imgopt_reencode( $img, $path, $mime, $quality ) {
	if ( 'image/jpeg' === $mime && function_exists( 'imagejpeg' ) ) {
		return (bool) @imagejpeg( $img, $path, $quality ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}
	if ( 'image/png' === $mime && function_exists( 'imagepng' ) ) {
		// Map 0–100 quality to PNG's 0–9 compression (higher quality = less
		// compression level). Always lossless; this mainly strips metadata.
		$level = (int) round( ( 100 - $quality ) / 11 );
		if ( $level < 0 ) {
			$level = 0;
		}
		if ( $level > 9 ) {
			$level = 9;
		}
		return (bool) @imagepng( $img, $path, $level ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}
	return false;
}

/**
 * Free a GD image resource if it is one. Safe to call with null.
 *
 * @param mixed $img Possibly a GD image.
 */
function themify_imgopt_free( $img ) {
	if ( $img && function_exists( 'imagedestroy' ) && ( $img instanceof \GdImage || is_resource( $img ) ) ) {
		imagedestroy( $img );
	}
}

/**
 * Process a single image file: optionally downscale + re-encode the original,
 * and optionally write a .webp sibling. This is the shared worker used by both
 * the upload pipeline and the bulk optimizer.
 *
 * All work is best-effort and exception-safe; it returns a small status array
 * and never throws or emits output.
 *
 * @param string $path File path on disk.
 * @param string $mime MIME type; only image/jpeg and image/png are handled.
 * @return array {
 *   @type bool $resized   Whether the original was downscaled/re-encoded.
 *   @type bool $webp      Whether a .webp sibling now exists (created or pre-existing).
 *   @type bool $processed Whether any work was attempted successfully.
 * }
 */
function themify_imgopt_process_file( $path, $mime ) {
	$result = array(
		'resized'   => false,
		'webp'      => false,
		'processed' => false,
	);

	if ( ! themify_imgopt_gd_supported() ) {
		return $result;
	}
	if ( ! in_array( $mime, themify_imgopt_supported_mimes(), true ) ) {
		return $result;
	}
	if ( ! is_string( $path ) || '' === $path || ! is_file( $path ) || ! is_readable( $path ) ) {
		return $result;
	}
	// Leave animated GIFs alone — a WebP copy from GD would drop the animation.
	if ( 'image/gif' === $mime && themify_imgopt_is_animated_gif( $path ) ) {
		return $result;
	}

	$quality    = themify_imgopt_quality();
	$max_width  = themify_imgopt_max_width();
	$keep_alpha = in_array( $mime, array( 'image/png', 'image/gif' ), true ); // PNG alpha / GIF index transparency.
	$want_webp  = themify_is_enabled( 'img_webp', true ) && themify_imgopt_webp_supported();

	try {
		$src = themify_imgopt_load( $path, $mime );
		if ( ! $src ) {
			return $result;
		}

		// (a) Downscale oversized originals and re-encode in place.
		$working = $src;
		if ( $max_width > 0 ) {
			$scaled = themify_imgopt_downscale( $src, $max_width, $keep_alpha );
			if ( $scaled ) {
				if ( themify_imgopt_reencode( $scaled, $path, $mime, $quality ) ) {
					$result['resized'] = true;
				}
				// Use the scaled image for the WebP sibling too.
				$working = $scaled;
			}
		}

		// (b) Write a .webp sibling from the (possibly downscaled) image.
		if ( $want_webp ) {
			$webp_path = $path . '.webp';
			$exists    = file_exists( $webp_path ) && (int) @filesize( $webp_path ) > 0; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( $exists || themify_imgopt_write_webp( $working, $path, $quality ) ) {
				$result['webp'] = true;
			}
		}

		$result['processed'] = true;

		// Free both images (avoid double-free when they are the same handle).
		if ( $working !== $src ) {
			themify_imgopt_free( $working );
		}
		themify_imgopt_free( $src );
	} catch ( \Throwable $e ) {
		themify_imgopt_log( 'Exception processing ' . $path . ': ' . $e->getMessage() );
	}

	return $result;
}

/* ============================================================ UPLOAD PIPELINE */

/**
 * Process an attachment's original file and all of its generated intermediate
 * sizes after WordPress builds its metadata. Hooked on
 * wp_generate_attachment_metadata so the thumbnail files already exist and we
 * can create a WebP sibling for each one.
 *
 * Returns the metadata untouched (we only side-effect the files on disk).
 *
 * @param array $metadata      Attachment metadata (sizes, file, …).
 * @param int   $attachment_id Attachment post ID.
 * @return array The unchanged metadata.
 */
function themify_imgopt_handle_attachment( $metadata, $attachment_id ) {
	if ( ! themify_is_enabled( 'img_optimize', true ) || ! themify_imgopt_gd_supported() ) {
		return $metadata;
	}

	$mime = get_post_mime_type( $attachment_id );
	if ( ! in_array( $mime, themify_imgopt_supported_mimes(), true ) ) {
		return $metadata;
	}

	$original = get_attached_file( $attachment_id );
	if ( ! $original || ! is_file( $original ) ) {
		return $metadata;
	}

	// Process the full-size original.
	themify_imgopt_process_file( $original, $mime );

	// Process every generated intermediate size that sits next to the original.
	if ( is_array( $metadata ) && ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
		$dir = trailingslashit( dirname( $original ) );
		foreach ( $metadata['sizes'] as $size ) {
			if ( empty( $size['file'] ) ) {
				continue;
			}
			// Guard against path traversal from unexpected metadata: keep basename only.
			$file = wp_basename( (string) $size['file'] );
			$size_mime = ! empty( $size['mime-type'] ) ? (string) $size['mime-type'] : $mime;
			$size_path = $dir . $file;
			if ( is_file( $size_path ) ) {
				themify_imgopt_process_file( $size_path, $size_mime );
			}
		}
	}

	return $metadata;
}
add_filter( 'wp_generate_attachment_metadata', 'themify_imgopt_handle_attachment', 20, 2 );

/**
 * Clean up the .webp siblings we created when an attachment is deleted, so we do
 * not leave orphaned files behind. Hooked on delete_attachment which fires
 * before the attached files are removed.
 *
 * @param int $attachment_id Attachment being deleted.
 */
function themify_imgopt_cleanup_attachment( $attachment_id ) {
	$mime = get_post_mime_type( $attachment_id );
	if ( ! in_array( $mime, themify_imgopt_supported_mimes(), true ) ) {
		return;
	}

	$original = get_attached_file( $attachment_id );
	if ( $original && file_exists( $original . '.webp' ) ) {
		@unlink( $original . '.webp' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	$metadata = wp_get_attachment_metadata( $attachment_id );
	if ( is_array( $metadata ) && ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) && $original ) {
		$dir = trailingslashit( dirname( $original ) );
		foreach ( $metadata['sizes'] as $size ) {
			if ( empty( $size['file'] ) ) {
				continue;
			}
			$webp = $dir . wp_basename( (string) $size['file'] ) . '.webp';
			if ( file_exists( $webp ) ) {
				@unlink( $webp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}
	}
}
add_action( 'delete_attachment', 'themify_imgopt_cleanup_attachment' );

/* ============================================================ WEBP SERVING */

/**
 * Whether the current request accepts WebP (browser advertised image/webp in
 * its Accept header). Cached per request.
 *
 * @return bool
 */
function themify_imgopt_accepts_webp() {
	static $accepts = null;
	if ( null !== $accepts ) {
		return $accepts;
	}
	$accept  = isset( $_SERVER['HTTP_ACCEPT'] ) ? (string) wp_unslash( $_SERVER['HTTP_ACCEPT'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only substring test, never stored/echoed.
	$accepts = ( '' !== $accept && false !== stripos( $accept, 'image/webp' ) );
	return $accepts;
}

/**
 * Whether WebP rewriting should run for this request at all: serving must be
 * enabled, WebP must be supported, we must not be in admin/feed, and the client
 * must accept WebP.
 *
 * @return bool
 */
function themify_imgopt_should_rewrite() {
	if ( is_admin() || is_feed() ) {
		return false;
	}
	if ( ! themify_is_enabled( 'img_webp', true ) || ! themify_imgopt_webp_supported() ) {
		return false;
	}
	return themify_imgopt_accepts_webp();
}

/**
 * Return the uploads base URL and base dir once (cached per request) for URL↔path
 * translation. Returns null when the uploads dir is unavailable/errored.
 *
 * @return array{baseurl:string,basedir:string}|null
 */
function themify_imgopt_uploads() {
	static $cache = null;
	if ( null !== $cache ) {
		return $cache ?: null;
	}
	$up = wp_get_upload_dir();
	if ( ! is_array( $up ) || ! empty( $up['error'] ) || empty( $up['baseurl'] ) || empty( $up['basedir'] ) ) {
		$cache = false;
		return null;
	}
	$cache = array(
		'baseurl' => untrailingslashit( $up['baseurl'] ),
		'basedir' => untrailingslashit( $up['basedir'] ),
	);
	return $cache;
}

/**
 * Given a same-host uploads image URL, return its .webp URL if (a) it is a
 * jpg/png under this site's uploads directory and (b) the .webp sibling exists
 * on disk. Otherwise return the original URL unchanged.
 *
 * Protocol-relative and scheme differences are tolerated by matching on the
 * host+path portion of the uploads baseurl.
 *
 * @param string $url Image URL.
 * @return string Possibly-rewritten URL.
 */
function themify_imgopt_webp_url( $url ) {
	$url = trim( (string) $url );
	if ( '' === $url ) {
		return $url;
	}
	// Only touch jpg/jpeg/png URLs (ignore query strings when testing extension).
	$path_part = (string) wp_parse_url( $url, PHP_URL_PATH );
	if ( ! preg_match( '/\.(jpe?g|png|gif|bmp)$/i', $path_part ) ) {
		return $url;
	}

	$up = themify_imgopt_uploads();
	if ( ! $up ) {
		return $url;
	}

	// Compare on a scheme-insensitive basis: reduce both to //host/path form.
	$baseurl_rel = preg_replace( '#^https?:#i', '', $up['baseurl'] );
	$url_rel     = preg_replace( '#^https?:#i', '', $url );

	if ( 0 !== strpos( $url_rel, $baseurl_rel ) ) {
		return $url; // Not an uploads URL on this host — never rewrite foreign URLs.
	}

	// Map URL → filesystem path by swapping the uploads baseurl for basedir.
	$relative = substr( $url_rel, strlen( $baseurl_rel ) );
	$relative = ltrim( $relative, '/' );
	// Strip any query string / fragment before hitting the filesystem.
	$relative = preg_replace( '/[?#].*$/', '', $relative );
	$relative = rawurldecode( $relative );

	// Reject path traversal outright.
	if ( '' === $relative || false !== strpos( $relative, '..' ) ) {
		return $url;
	}

	$file_path = $up['basedir'] . '/' . $relative;
	$webp_path = $file_path . '.webp';

	if ( is_file( $webp_path ) && (int) @filesize( $webp_path ) > 0 ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return $url . '.webp';
	}
	return $url;
}

/**
 * Rewrite src and srcset URLs inside a chunk of HTML to their .webp siblings
 * where available. Only affects <img> attributes; other markup is left intact.
 *
 * @param string $html HTML containing <img> tags.
 * @return string
 */
function themify_imgopt_rewrite_html( $html ) {
	if ( '' === $html || false === strpos( $html, '<img' ) ) {
		return $html;
	}

	// Rewrite each src="..." on an <img> tag.
	$html = preg_replace_callback(
		'#(<img\b[^>]*?\bsrc=)(["\'])(.*?)\2#i',
		function ( $m ) {
			return $m[1] . $m[2] . themify_imgopt_webp_url( $m[3] ) . $m[2];
		},
		$html
	);

	// Rewrite srcset="url1 1x, url2 2x, ..." on <img>/<source> tags.
	$html = preg_replace_callback(
		'#\bsrcset=(["\'])(.*?)\1#i',
		function ( $m ) {
			$parts = explode( ',', $m[2] );
			$out   = array();
			foreach ( $parts as $part ) {
				$part = trim( $part );
				if ( '' === $part ) {
					continue;
				}
				// Each candidate is "URL [descriptor]"; split on the first run of
				// whitespace so descriptors like "768w" or "2x" are preserved.
				$bits       = preg_split( '/\s+/', $part, 2 );
				$candidate  = $bits[0];
				$descriptor = isset( $bits[1] ) ? ' ' . $bits[1] : '';
				$out[]      = themify_imgopt_webp_url( $candidate ) . $descriptor;
			}
			return 'srcset=' . $m[1] . implode( ', ', $out ) . $m[1];
		},
		$html
	);

	return null === $html ? '' : $html;
}

/**
 * the_content filter: rewrite in-content images to WebP for capable browsers.
 *
 * @param string $content Post content.
 * @return string
 */
function themify_imgopt_filter_content( $content ) {
	if ( ! themify_imgopt_should_rewrite() ) {
		return $content;
	}
	return themify_imgopt_rewrite_html( $content );
}
add_filter( 'the_content', 'themify_imgopt_filter_content', 30 );

/**
 * Rewrite attachment image HTML (wp_get_attachment_image / featured images).
 *
 * @param string $html Attachment <img> HTML.
 * @return string
 */
function themify_imgopt_filter_attachment_html( $html ) {
	if ( ! is_string( $html ) || ! themify_imgopt_should_rewrite() ) {
		return $html;
	}
	return themify_imgopt_rewrite_html( $html );
}
add_filter( 'wp_get_attachment_image', 'themify_imgopt_filter_attachment_html', 30 );
add_filter( 'post_thumbnail_html', 'themify_imgopt_filter_attachment_html', 30 );

/* ============================================================ STATS */

/**
 * Count total JPEG/PNG image attachments in the media library.
 *
 * @return int
 */
function themify_imgopt_count_images() {
	$q = new WP_Query( array(
		'post_type'              => 'attachment',
		'post_status'            => 'inherit',
		'post_mime_type'         => themify_imgopt_supported_mimes(),
		'posts_per_page'         => 1,
		'fields'                 => 'ids',
		'no_found_rows'          => false,
		'update_post_meta_cache' => false,
		'update_post_term_cache' => false,
	) );
	return (int) $q->found_posts;
}

/**
 * Count how many image attachments already have a full-size .webp sibling on
 * disk. Bounded (scans up to 2000 attachments) so the stat stays cheap on very
 * large libraries; that is plenty to give the owner a meaningful progress read.
 *
 * @return int
 */
function themify_imgopt_count_with_webp() {
	if ( ! themify_imgopt_webp_supported() ) {
		return 0;
	}
	$ids = get_posts( array(
		'post_type'              => 'attachment',
		'post_status'            => 'inherit',
		'post_mime_type'         => themify_imgopt_supported_mimes(),
		'posts_per_page'         => 2000,
		'fields'                 => 'ids',
		'no_found_rows'          => true,
		'update_post_meta_cache' => false,
		'update_post_term_cache' => false,
	) );
	$count = 0;
	foreach ( $ids as $id ) {
		$file = get_attached_file( $id );
		if ( $file && file_exists( $file . '.webp' ) ) {
			$count++;
		}
	}
	return $count;
}

/* ============================================================ ADMIN PAGE */

/**
 * Register the "Image Optimizer" submenu (position 65).
 */
themify_register_admin_page( array(
	'slug'       => 'themify-image-optimizer',
	'title'      => __( 'Image Optimizer', 'themify' ),
	'menu_title' => __( 'Image Optimizer', 'themify' ),
	'callback'   => 'themify_imgopt_page',
	'position'   => 58,
) );

/**
 * Add the Image Optimizer card to the dashboard grid.
 *
 * @param array $cards Existing cards.
 * @return array
 */
function themify_imgopt_dashboard_card( $cards ) {
	$cards[] = array(
		'slug'     => 'themify-image-optimizer',
		'title'    => __( 'Image Optimizer', 'themify' ),
		'desc'     => __( 'Compress + serve WebP, no plugin', 'themify' ),
		'icon'     => 'dashicons-format-image',
		'position' => 58,
	);
	return $cards;
}
add_filter( 'themify_dashboard_cards', 'themify_imgopt_dashboard_card' );

/**
 * The settings fields for this module (shared by render + save so the two never
 * drift apart).
 *
 * @return array<int,array> Field definitions.
 */
function themify_imgopt_fields() {
	return array(
		array(
			'key'     => 'img_optimize',
			'label'   => __( 'Optimize images on upload', 'themify' ),
			'type'    => 'checkbox',
			'default' => '1',
			'desc'    => __( 'Downscale oversized uploads and re-encode JPEG/PNG at the quality below (also strips camera metadata).', 'themify' ),
		),
		array(
			'key'     => 'img_webp',
			'label'   => __( 'Generate &amp; serve WebP', 'themify' ),
			'type'    => 'checkbox',
			'default' => '1',
			'desc'    => __( 'Auto-convert every uploaded image — JPEG, PNG, GIF and BMP (and their thumbnails) — to a .webp copy and serve it automatically to browsers that support WebP. Animated GIFs are kept as-is so they keep moving.', 'themify' ),
		),
		array(
			'key'         => 'img_quality',
			'label'       => __( 'Quality', 'themify' ),
			'type'        => 'number',
			'default'     => 82,
			'placeholder' => '82',
			'desc'        => __( 'JPEG and WebP encode quality, 40–100. 80–85 is a great size/quality balance.', 'themify' ),
		),
		array(
			'key'         => 'img_max_width',
			'label'       => __( 'Max width (px)', 'themify' ),
			'type'        => 'number',
			'default'     => 2048,
			'placeholder' => '2048',
			'desc'        => __( 'Downscale any upload wider than this. Set to 0 to disable downscaling.', 'themify' ),
		),
		array(
			'key'     => 'img_strip_meta',
			'label'   => __( 'Strip metadata', 'themify' ),
			'type'    => 'checkbox',
			'default' => '1',
			'desc'    => __( 'Remove EXIF/IPTC/XMP metadata (this happens implicitly when images are re-encoded).', 'themify' ),
		),
	);
}

/**
 * Handle the settings form save. Uses this module's own nonce so it can live on
 * the same custom page as the bulk console.
 */
function themify_imgopt_handle_save() {
	if ( ! themify_verify_save( 'themify_imgopt' ) ) {
		return;
	}
	$posted = isset( $_POST[ THEMIFY_OPT ] ) && is_array( $_POST[ THEMIFY_OPT ] )
		? wp_unslash( $_POST[ THEMIFY_OPT ] )
		: array();

	$to_save = array();
	foreach ( themify_imgopt_fields() as $field ) {
		$k             = $field['key'];
		$raw           = $posted[ $k ] ?? '';
		$to_save[ $k ] = themify_sanitize_field( $raw, $field );
	}
	themify_set_options( $to_save );
	add_settings_error( 'themify', 'saved', __( 'Settings saved.', 'themify' ), 'success' );
}

/**
 * Render the "Image Optimizer" admin console: capability notice, stats, settings
 * form and the bulk-optimize runner — all on one custom page.
 */
function themify_imgopt_page() {
	themify_imgopt_handle_save();

	$gd_ok   = themify_imgopt_gd_supported();
	$webp_ok = themify_imgopt_webp_supported();

	themify_admin_header(
		__( 'Image Optimizer', 'themify' ),
		__( 'Compress uploads and serve modern WebP images automatically — a built-in replacement for image optimization plugins. All processing happens on your server; nothing is uploaded anywhere.', 'themify' )
	);
	settings_errors( 'themify' );

	// --- Capability notice -------------------------------------------------
	if ( ! $webp_ok ) {
		echo '<div class="tf-notice tf-notice--warn">';
		if ( ! $gd_ok ) {
			echo esc_html__( 'PHP\'s GD image library is not available on this server, so on-upload compression and WebP generation are disabled. Ask your host to enable the GD extension. Everything else on this page is safe to view; no changes will be made until GD is available.', 'themify' );
		} else {
			echo esc_html__( 'GD is available but was built without WebP support (imagewebp() is missing), so WebP generation and serving are disabled. Basic compression still works. Ask your host to enable WebP support in GD to unlock modern-format delivery.', 'themify' );
		}
		echo '</div>';
	}

	// --- Stats -------------------------------------------------------------
	$total     = themify_imgopt_count_images();
	$with_webp = themify_imgopt_count_with_webp();
	$without   = max( 0, $total - $with_webp );

	echo '<div class="tf-stats">';
	printf(
		'<div class="tf-stat"><div class="tf-stat__num">%s</div><div class="tf-stat__label">%s</div></div>',
		esc_html( number_format_i18n( $total ) ),
		esc_html__( 'Image attachments', 'themify' )
	);
	printf(
		'<div class="tf-stat"><div class="tf-stat__num">%s</div><div class="tf-stat__label">%s</div></div>',
		esc_html( number_format_i18n( $with_webp ) ),
		esc_html__( 'With WebP copy', 'themify' )
	);
	printf(
		'<div class="tf-stat %s"><div class="tf-stat__num">%s</div><div class="tf-stat__label">%s</div></div>',
		$without > 0 ? 'tf-stat--warn' : '',
		esc_html( number_format_i18n( $without ) ),
		esc_html__( 'Without WebP', 'themify' )
	);
	echo '</div>';

	// --- Settings form -----------------------------------------------------
	echo '<form method="post" class="tf-form">';
	wp_nonce_field( 'themify_imgopt', 'themify_nonce' );
	echo '<div class="tf-card">';
	echo '<h2 class="tf-card__title">' . esc_html__( 'Settings', 'themify' ) . '</h2>';
	echo '<p class="tf-card__desc">' . esc_html__( 'These apply to every new upload. Use the bulk tool below to convert images you already have.', 'themify' ) . '</p>';
	foreach ( themify_imgopt_fields() as $field ) {
		themify_render_field( $field );
	}
	echo '<p class="tf-form__actions"><button type="submit" class="button button-primary button-hero">' . esc_html__( 'Save Changes', 'themify' ) . '</button></p>';
	echo '</div>'; // .tf-card
	echo '</form>';

	// --- Bulk optimize -----------------------------------------------------
	echo '<div class="tf-card">';
	echo '<h2 class="tf-card__title">' . esc_html__( 'Bulk optimize existing images', 'themify' ) . '</h2>';
	echo '<p class="tf-card__desc">' . esc_html__( 'Compress and generate WebP copies for images already in your media library. This runs in small batches — keep clicking "Continue" until it reports 100% done. Safe to stop and resume any time.', 'themify' ) . '</p>';

	$offset = (int) get_option( THEMIFY_IMGOPT_OFFSET_OPT, 0 );

	if ( ! $gd_ok ) {
		echo '<div class="tf-notice tf-notice--warn">' . esc_html__( 'Bulk optimization needs the GD library, which is unavailable on this server.', 'themify' ) . '</div>';
	} else {
		echo '<div class="tf-actions">';
		printf(
			'<button type="button" class="button button-primary tf-run" data-action="themify_img_bulk" data-payload="run" data-target="#tf-imgopt-result" data-running="%s">%s</button>',
			esc_attr__( 'Optimizing…', 'themify' ),
			$offset > 0
				? esc_html__( 'Continue bulk optimize', 'themify' )
				: esc_html__( 'Start bulk optimize', 'themify' )
		);
		printf(
			'<button type="button" class="button tf-run" data-action="themify_img_bulk" data-payload="reset" data-target="#tf-imgopt-result" data-running="%s">%s</button>',
			esc_attr__( 'Resetting…', 'themify' ),
			esc_html__( 'Reset progress', 'themify' )
		);
		echo '</div>';
		echo '<div id="tf-imgopt-result">';
		if ( $offset > 0 ) {
			printf(
				'<div class="tf-notice tf-notice--info">%s</div>',
				esc_html(
					sprintf(
						/* translators: %s: number of images processed so far */
						__( 'Resuming from a previous run — %s images processed so far.', 'themify' ),
						number_format_i18n( $offset )
					)
				)
			);
		}
		echo '</div>';
	}
	echo '</div>'; // .tf-card

	themify_admin_footer();
}

/* ============================================================ BULK AJAX RUNNER */

/**
 * AJAX: process the next batch of existing image attachments, or reset progress.
 *
 * Driven by the .tf-run buttons on the admin page. Tracks its position in the
 * THEMIFY_IMGOPT_OFFSET_OPT option so it is safely re-clickable to continue.
 * Returns a .tf-notice progress panel that admin.js injects into the target.
 */
function themify_imgopt_ajax_bulk() {
	check_ajax_referer( 'themify_admin', 'nonce' );
	if ( ! current_user_can( THEMIFY_CAP ) ) {
		wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'themify' ) ) );
	}

	if ( ! themify_imgopt_gd_supported() ) {
		wp_send_json_error( array( 'message' => __( 'The GD image library is unavailable on this server.', 'themify' ) ) );
	}

	$mode = isset( $_POST['payload'] ) ? sanitize_key( wp_unslash( $_POST['payload'] ) ) : 'run';

	if ( 'reset' === $mode ) {
		delete_option( THEMIFY_IMGOPT_OFFSET_OPT );
		wp_send_json_success( array(
			'html' => '<div class="tf-notice tf-notice--info">' . esc_html__( 'Progress reset. Click "Start bulk optimize" to begin from the first image.', 'themify' ) . '</div>',
		) );
	}

	$total  = themify_imgopt_count_images();
	$offset = (int) get_option( THEMIFY_IMGOPT_OFFSET_OPT, 0 );
	if ( $offset < 0 ) {
		$offset = 0;
	}

	if ( 0 === $total ) {
		delete_option( THEMIFY_IMGOPT_OFFSET_OPT );
		wp_send_json_success( array(
			'html' => '<div class="tf-notice tf-notice--info">' . esc_html__( 'No JPEG or PNG images found in the media library.', 'themify' ) . '</div>',
		) );
	}

	// Fetch this batch of attachment IDs, oldest first for stable paging.
	$ids = get_posts( array(
		'post_type'              => 'attachment',
		'post_status'            => 'inherit',
		'post_mime_type'         => themify_imgopt_supported_mimes(),
		'posts_per_page'         => THEMIFY_IMGOPT_BATCH,
		'offset'                 => $offset,
		'orderby'                => 'ID',
		'order'                  => 'ASC',
		'fields'                 => 'ids',
		'no_found_rows'          => true,
		'update_post_meta_cache' => false,
		'update_post_term_cache' => false,
	) );

	$processed = 0;
	$webp_made = 0;

	foreach ( $ids as $id ) {
		$mime = get_post_mime_type( $id );
		if ( ! in_array( $mime, themify_imgopt_supported_mimes(), true ) ) {
			continue;
		}
		$original = get_attached_file( $id );
		if ( ! $original || ! is_file( $original ) ) {
			continue;
		}

		$res = themify_imgopt_process_file( $original, $mime );
		if ( ! empty( $res['processed'] ) ) {
			$processed++;
		}
		if ( ! empty( $res['webp'] ) ) {
			$webp_made++;
		}

		// Also process each intermediate size so thumbnails get WebP too.
		$metadata = wp_get_attachment_metadata( $id );
		if ( is_array( $metadata ) && ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			$dir = trailingslashit( dirname( $original ) );
			foreach ( $metadata['sizes'] as $size ) {
				if ( empty( $size['file'] ) ) {
					continue;
				}
				$size_path = $dir . wp_basename( (string) $size['file'] );
				$size_mime = ! empty( $size['mime-type'] ) ? (string) $size['mime-type'] : $mime;
				if ( is_file( $size_path ) ) {
					themify_imgopt_process_file( $size_path, $size_mime );
				}
			}
		}
	}

	$new_offset = $offset + count( $ids );
	$done       = ( empty( $ids ) || $new_offset >= $total );

	if ( $done ) {
		delete_option( THEMIFY_IMGOPT_OFFSET_OPT );
		$new_offset = $total;
	} else {
		update_option( THEMIFY_IMGOPT_OFFSET_OPT, $new_offset, false );
	}

	wp_send_json_success( array(
		'html'     => themify_imgopt_bulk_progress_html( $new_offset, $total, $processed, $webp_made, $done ),
		'done'     => $done,
		'offset'   => $new_offset,
	) );
}
add_action( 'wp_ajax_themify_img_bulk', 'themify_imgopt_ajax_bulk' );

/**
 * Build the progress .tf-notice returned after a bulk batch.
 *
 * @param int  $offset    Images processed so far (X).
 * @param int  $total     Total image attachments (N).
 * @param int  $processed Images touched this batch.
 * @param int  $webp_made WebP siblings confirmed this batch.
 * @param bool $done       Whether the run is complete.
 * @return string HTML.
 */
function themify_imgopt_bulk_progress_html( $offset, $total, $processed, $webp_made, $done ) {
	$offset = (int) $offset;
	$total  = (int) $total;

	if ( $done ) {
		$html  = '<div class="tf-notice tf-notice--info">';
		$html .= esc_html(
			sprintf(
				/* translators: %s: total number of images */
				__( 'Done — all %s images have been optimized. WebP copies now serve automatically to supported browsers.', 'themify' ),
				number_format_i18n( $total )
			)
		);
		$html .= '</div>';
		return $html;
	}

	$html  = '<div class="tf-notice tf-notice--info">';
	$html .= esc_html(
		sprintf(
			/* translators: 1: processed count, 2: total count */
			__( 'Optimized %1$s of %2$s images. Click "Continue" to process the next batch.', 'themify' ),
			number_format_i18n( $offset ),
			number_format_i18n( $total )
		)
	);
	if ( $webp_made > 0 ) {
		$html .= ' ' . esc_html(
			sprintf(
				/* translators: %s: number of WebP copies made in this batch */
				__( '(%s WebP copies created in this batch.)', 'themify' ),
				number_format_i18n( $webp_made )
			)
		);
	}
	$html .= '</div>';
	return $html;
}
