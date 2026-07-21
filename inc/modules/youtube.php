<?php
/**
 * YouTube channel section.
 *
 * A drop-in, brandable "Official YouTube Channel" section for any site: a
 * headline + a Subscribe button, and (optionally) the channel's latest videos
 * as click-to-play thumbnails. The owner just pastes their channel URL/handle —
 * everything else is resolved automatically:
 *
 *   • The channel ID is discovered from the URL (or the channel page) and
 *     cached for a week.
 *   • The latest videos come from YouTube's public RSS feed and are cached for
 *     a few hours, so a visitor's page load never waits on YouTube.
 *
 * Show it on the homepage (toggle), or place it anywhere with [themify_youtube].
 *
 * @package Themify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* -------------------------------------------------------------------------
 * DATA
 * ---------------------------------------------------------------------- */

/** The configured channel URL, trimmed. */
function themify_youtube_url() {
	return trim( (string) themify_get_option( 'yt_channel_url', '' ) );
}

/** Is the section switched on and configured? */
function themify_youtube_enabled() {
	return themify_is_enabled( 'yt_enabled', true ) && '' !== themify_youtube_url();
}

/**
 * The subscribe link — appends ?sub_confirmation=1 so YouTube shows the
 * one-click subscribe prompt.
 *
 * @return string
 */
function themify_youtube_subscribe_url() {
	$url = themify_youtube_url();
	return $url ? add_query_arg( 'sub_confirmation', 1, $url ) : '';
}

/**
 * Resolve the channel ID (UC…) from the configured URL. Reads it straight from
 * a /channel/UC… URL, otherwise fetches the channel page once and caches the
 * result (a week on success, an hour on failure so we retry).
 *
 * @return string Channel ID, or '' if it can't be resolved.
 */
function themify_youtube_channel_id() {
	$url = themify_youtube_url();
	if ( '' === $url ) {
		return '';
	}
	if ( preg_match( '#/channel/(UC[\w-]{20,})#', $url, $m ) ) {
		return $m[1];
	}

	$key    = 'themify_yt_cid_' . md5( $url );
	$cached = get_transient( $key );
	if ( false !== $cached ) {
		return $cached;
	}

	$cid  = '';
	$resp = wp_remote_get( $url, array(
		'timeout'    => 6,
		'user-agent' => 'Mozilla/5.0 (compatible; Themify/' . THEMIFY_VERSION . ')',
	) );
	if ( ! is_wp_error( $resp ) ) {
		$html = wp_remote_retrieve_body( $resp );
		if ( preg_match( '#"(?:channelId|externalId)":"(UC[\w-]{20,})"#', $html, $m ) ) {
			$cid = $m[1];
		} elseif ( preg_match( '#/channel/(UC[\w-]{20,})#', $html, $m ) ) {
			$cid = $m[1];
		}
	}
	set_transient( $key, $cid, $cid ? WEEK_IN_SECONDS : HOUR_IN_SECONDS );
	return $cid;
}

/**
 * Latest videos for the channel, from the public RSS feed, cached 3 hours.
 *
 * @param int $count How many to return; 0 (default) returns every cached video
 *                   so the caller can split them into long/short rows itself.
 * @return array<int,array{id:string,title:string,url:string,thumb:string,short:bool}>
 */
function themify_youtube_videos( $count = 0 ) {
	$cid = themify_youtube_channel_id();
	if ( '' === $cid ) {
		return array();
	}

	$key    = 'themify_yt_videos_' . $cid;
	$videos = get_transient( $key );
	if ( false === $videos ) {
		$videos = array();
		$resp   = wp_remote_get(
			'https://www.youtube.com/feeds/videos.xml?channel_id=' . rawurlencode( $cid ),
			array( 'timeout' => 6 )
		);
		if ( ! is_wp_error( $resp ) ) {
			$body = wp_remote_retrieve_body( $resp );
			$xml  = $body ? @simplexml_load_string( $body ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( $xml instanceof SimpleXMLElement && isset( $xml->entry ) ) {
				$ns = $xml->getNamespaces( true );
				foreach ( $xml->entry as $entry ) {
					$yt  = isset( $ns['yt'] ) ? $entry->children( $ns['yt'] ) : null;
					$vid = $yt ? (string) $yt->videoId : '';
					if ( '' === $vid ) {
						continue;
					}
					// The rel="alternate" link is /shorts/… for Shorts and
					// /watch?v=… for regular videos — the reliable way to tell
					// them apart (the feed carries no duration).
					$href = '';
					foreach ( $entry->link as $ln ) {
						if ( 'alternate' === (string) $ln['rel'] ) {
							$href = (string) $ln['href'];
							break;
						}
					}
					if ( '' === $href ) {
						$href = 'https://www.youtube.com/watch?v=' . $vid;
					}
					$videos[] = array(
						'id'    => $vid,
						'title' => (string) $entry->title,
						'url'   => $href,
						'thumb' => 'https://i.ytimg.com/vi/' . $vid . '/hqdefault.jpg',
						'short' => ( false !== strpos( $href, '/shorts/' ) ),
					);
					if ( count( $videos ) >= 25 ) {
						break;
					}
				}
			}
		}
		set_transient( $key, $videos, 3 * HOUR_IN_SECONDS );
	}

	$videos = (array) $videos;
	return $count > 0 ? array_slice( $videos, 0, (int) $count ) : $videos;
}

/**
 * Pull the 11-character video id out of any YouTube URL shape (youtu.be/…,
 * /watch?v=…, /shorts/…, /embed/…, /live/…) or a bare id.
 *
 * @param string $url A single line from the manual list.
 * @return string Video id, or '' if the line isn't a YouTube link.
 */
function themify_youtube_parse_id( $url ) {
	$url = trim( (string) $url );
	if ( '' === $url ) {
		return '';
	}
	// A bare id pasted on its own.
	if ( preg_match( '#^[A-Za-z0-9_-]{11}$#', $url ) ) {
		return $url;
	}
	if ( preg_match( '#(?:youtu\.be/|youtube\.com/(?:watch\?(?:[^&]*&)*v=|shorts/|embed/|live/|v/))([A-Za-z0-9_-]{11})#i', $url, $m ) ) {
		return $m[1];
	}
	return '';
}

/**
 * The hand-picked video list (option 'yt_video_urls', one link per line). When
 * the site owner fills this in, these exact videos are shown — nothing is
 * fetched from YouTube, so the section never comes up empty because of a blocked
 * outbound request or an RSS quirk. All plays still count for the channel.
 *
 * @return array<int,array{id:string,title:string,url:string,thumb:string,short:bool}>
 */
function themify_youtube_manual_videos() {
	$raw = (string) themify_get_option( 'yt_video_urls', '' );
	if ( '' === trim( $raw ) ) {
		return array();
	}

	$out  = array();
	$seen = array();
	foreach ( preg_split( '/[\r\n]+/', $raw ) as $line ) {
		$id = themify_youtube_parse_id( $line );
		if ( '' === $id || isset( $seen[ $id ] ) ) {
			continue;
		}
		$seen[ $id ] = true;
		$out[]       = array(
			'id'    => $id,
			'title' => '',
			'url'   => 'https://www.youtube.com/watch?v=' . $id,
			'thumb' => 'https://i.ytimg.com/vi/' . $id . '/hqdefault.jpg',
			'short' => false,
		);
	}
	return $out;
}

/**
 * The videos to display: the hand-picked list when it's configured, otherwise
 * the channel's latest videos from the RSS feed.
 *
 * @param int $count How many to return.
 * @return array
 */
function themify_youtube_display_videos( $count ) {
	$manual = themify_youtube_manual_videos();
	if ( $manual ) {
		return array_slice( $manual, 0, (int) $count );
	}
	return themify_youtube_videos( $count );
}

/** Clear the cached channel ID + video list (used by the admin refresh button). */
function themify_youtube_flush_cache() {
	$url = themify_youtube_url();
	if ( $url ) {
		delete_transient( 'themify_yt_cid_' . md5( $url ) );
	}
	$cid = themify_youtube_channel_id();
	if ( $cid ) {
		delete_transient( 'themify_yt_videos_' . $cid );
	}
}

/* -------------------------------------------------------------------------
 * FRONT-END RENDER
 * ---------------------------------------------------------------------- */

/**
 * Render the YouTube section.
 */
function themify_render_youtube_section() {
	if ( ! themify_youtube_enabled() ) {
		return;
	}

	$name     = themify_get_option( 'yt_channel_name', get_bloginfo( 'name' ) );
	$headline = themify_get_option( 'yt_headline', __( 'Official YouTube channel of', 'themify' ) );
	$sub_text = themify_get_option( 'yt_sub_text', __( 'Subscribe on YouTube', 'themify' ) );
	$sub_url  = themify_youtube_subscribe_url();
	$visit_text = themify_get_option( 'yt_visit_text', __( 'Visit our channel', 'themify' ) );
	$visit_url  = themify_youtube_url();

	// One clean, uniform grid — 4 per row, so 12 videos fill three tidy rows.
	$count  = (int) themify_get_option( 'yt_video_count', 12 );
	$count  = $count > 0 ? $count : 12;
	$videos = themify_is_enabled( 'yt_show_videos', true ) ? themify_youtube_display_videos( $count ) : array();

	echo '<section class="tf-youtube"><div class="tf-container">';

	echo '<div class="tf-youtube__head">';
	echo '<span class="tf-youtube__logo" aria-hidden="true">';
	echo '<svg width="34" height="24" viewBox="0 0 28 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M27.4 3.1A3.5 3.5 0 0 0 24.9.6C22.7 0 14 0 14 0S5.3 0 3.1.6A3.5 3.5 0 0 0 .6 3.1C0 5.3 0 10 0 10s0 4.7.6 6.9a3.5 3.5 0 0 0 2.5 2.5C5.3 20 14 20 14 20s8.7 0 10.9-.6a3.5 3.5 0 0 0 2.5-2.5c.6-2.2.6-6.9.6-6.9s0-4.7-.6-6.9Z" fill="#FF0000"/><path d="M11.2 14.3 18.5 10l-7.3-4.3v8.6Z" fill="#fff"/></svg>';
	echo '</span>';
	if ( $headline ) {
		echo '<p class="tf-youtube__eyebrow">' . esc_html( $headline ) . '</p>';
	}
	echo '<h2 class="tf-youtube__title">' . esc_html( $name ) . '</h2>';
	echo '<div class="tf-youtube__actions">';
	if ( $sub_url && $sub_text ) {
		printf(
			'<a class="tf-youtube__btn" href="%s" target="_blank" rel="noopener noreferrer"><svg width="20" height="14" viewBox="0 0 28 20" fill="none" aria-hidden="true"><path d="M27.4 3.1A3.5 3.5 0 0 0 24.9.6C22.7 0 14 0 14 0S5.3 0 3.1.6A3.5 3.5 0 0 0 .6 3.1C0 5.3 0 10 0 10s0 4.7.6 6.9a3.5 3.5 0 0 0 2.5 2.5C5.3 20 14 20 14 20s8.7 0 10.9-.6a3.5 3.5 0 0 0 2.5-2.5c.6-2.2.6-6.9.6-6.9s0-4.7-.6-6.9Z" fill="currentColor"/><path d="M11.2 14.3 18.5 10l-7.3-4.3v8.6Z" fill="#fff"/></svg> %s</a>',
			esc_url( $sub_url ),
			esc_html( $sub_text )
		);
	}
	if ( $visit_url && $visit_text ) {
		printf(
			'<a class="tf-youtube__btn tf-youtube__btn--ghost" href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
			esc_url( $visit_url ),
			esc_html( $visit_text )
		);
	}
	echo '</div>'; // .tf-youtube__actions
	echo '</div>'; // .tf-youtube__head

	if ( $videos ) {
		themify_youtube_render_row( $videos, false );
	}

	echo '</div></section>';
}

/**
 * Render one row of YouTube video thumbnails.
 *
 * @param array $videos Videos to show.
 * @param bool  $shorts Whether this is the vertical Shorts row.
 */
function themify_youtube_render_row( array $videos, $shorts = false ) {
	printf( '<div class="tf-youtube__grid%s" data-tf-youtube>', $shorts ? ' tf-youtube__grid--shorts' : '' );
	foreach ( $videos as $v ) {
		$title = trim( (string) ( $v['title'] ?? '' ) );
		$label = '' !== $title
			? sprintf( /* translators: %s: video title */ __( 'Play: %s', 'themify' ), $title )
			: __( 'Play video', 'themify' );
		printf(
			'<button type="button" class="tf-youtube__video%s" data-video="%s" aria-label="%s"><img src="%s" alt="" loading="lazy" width="480" height="360" /><span class="tf-youtube__play" aria-hidden="true"></span>%s</button>',
			$shorts ? ' tf-youtube__video--short' : '',
			esc_attr( $v['id'] ),
			esc_attr( $label ),
			esc_url( $v['thumb'] ),
			'' !== $title ? '<span class="tf-youtube__vtitle">' . esc_html( $title ) . '</span>' : ''
		);
	}
	echo '</div>';
}

/** Preconnect to YouTube image + embed hosts when the section is on. */
function themify_youtube_resource_hints( $hints, $relation ) {
	if ( 'preconnect' === $relation && themify_youtube_enabled() && themify_is_enabled( 'yt_show_videos', true ) ) {
		$hints[] = 'https://i.ytimg.com';
		$hints[] = 'https://www.youtube-nocookie.com';
	}
	return $hints;
}
add_filter( 'wp_resource_hints', 'themify_youtube_resource_hints', 10, 2 );

/** Shortcode: [themify_youtube] */
function themify_youtube_shortcode() {
	ob_start();
	themify_render_youtube_section();
	return ob_get_clean();
}
add_shortcode( 'themify_youtube', 'themify_youtube_shortcode' );

/* -------------------------------------------------------------------------
 * ADMIN
 * ---------------------------------------------------------------------- */

themify_register_admin_page( array(
	'slug'       => 'themify-youtube',
	'title'      => __( 'YouTube', 'themify' ),
	'menu_title' => __( 'YouTube', 'themify' ),
	'callback'   => 'themify_youtube_page',
	'position'   => 42,
) );

add_filter( 'themify_dashboard_cards', function ( $cards ) {
	$cards[] = array(
		'slug'     => 'themify-youtube',
		'title'    => __( 'YouTube', 'themify' ),
		'desc'     => __( 'Channel subscribe + latest videos', 'themify' ),
		'icon'     => 'dashicons-video-alt3',
		'position' => 42,
	);
	return $cards;
} );

/** AJAX: refresh the cached videos. */
function themify_youtube_refresh_ajax() {
	check_ajax_referer( 'themify_admin', 'nonce' );
	if ( ! current_user_can( THEMIFY_CAP ) ) {
		wp_send_json_error( array( 'message' => __( 'Denied', 'themify' ) ) );
	}

	// If the owner hand-picked videos, we don't touch YouTube at all.
	$manual = themify_youtube_manual_videos();
	if ( $manual ) {
		$msg = sprintf(
			/* translators: %d: number of videos */
			_n( 'Showing your %d hand-picked video.', 'Showing your %d hand-picked videos.', count( $manual ), 'themify' ),
			count( $manual )
		);
		wp_send_json_success( array( 'html' => '<div class="tf-notice tf-notice--info">' . esc_html( $msg ) . '</div>' ) );
	}

	themify_youtube_flush_cache();
	$videos = themify_youtube_videos( 12 );
	$cid    = themify_youtube_channel_id();
	if ( '' === $cid ) {
		wp_send_json_success( array( 'html' => '<div class="tf-notice tf-notice--warn">' . esc_html__( 'Could not find the channel. Double-check the URL (e.g. https://www.youtube.com/@YourChannel).', 'themify' ) . '</div>' ) );
	}
	$msg = sprintf(
		/* translators: 1: channel id, 2: number of videos */
		__( 'Connected (channel %1$s). Loaded %2$d videos.', 'themify' ),
		$cid,
		count( $videos )
	);
	wp_send_json_success( array( 'html' => '<div class="tf-notice tf-notice--info">' . esc_html( $msg ) . '</div>' ) );
}
add_action( 'wp_ajax_themify_youtube_refresh', 'themify_youtube_refresh_ajax' );

/** The "YouTube" settings screen. */
function themify_youtube_page() {
	themify_render_settings_page( array(
		'title'  => __( 'YouTube Channel', 'themify' ),
		'intro'  => __( 'Paste your channel link and the theme builds an “Official YouTube channel” section — a subscribe button plus your latest videos (click to play). Works for any channel.', 'themify' ),
		'nonce'  => 'themify_youtube',
		'groups' => array(
			array(
				'title'  => __( 'Channel', 'themify' ),
				'fields' => array(
					array( 'key' => 'yt_enabled', 'label' => __( 'Show the YouTube section on the homepage', 'themify' ), 'type' => 'checkbox', 'default' => '1' ),
					array( 'key' => 'yt_channel_url', 'label' => __( 'Channel URL', 'themify' ), 'type' => 'url', 'placeholder' => 'https://www.youtube.com/@YourChannel', 'desc' => __( 'Your channel handle URL (…/@name) or channel URL (…/channel/UC…).', 'themify' ) ),
					array( 'key' => 'yt_channel_name', 'label' => __( 'Channel name', 'themify' ), 'type' => 'text', 'placeholder' => get_bloginfo( 'name' ), 'desc' => __( 'Shown as the big title. Defaults to your site name.', 'themify' ) ),
					array( 'key' => 'yt_headline', 'label' => __( 'Small headline above the name', 'themify' ), 'type' => 'text', 'placeholder' => __( 'Official YouTube channel of', 'themify' ) ),
					array( 'key' => 'yt_sub_text', 'label' => __( 'Subscribe button text', 'themify' ), 'type' => 'text', 'placeholder' => __( 'Subscribe on YouTube', 'themify' ) ),
					array( 'key' => 'yt_visit_text', 'label' => __( 'Channel button text', 'themify' ), 'type' => 'text', 'placeholder' => __( 'Visit our channel', 'themify' ), 'desc' => __( 'A second button that opens your channel page. Leave blank to hide it.', 'themify' ) ),
				),
			),
			array(
				'title'  => __( 'Videos', 'themify' ),
				'fields' => array(
					array( 'key' => 'yt_show_videos', 'label' => __( 'Show the videos grid', 'themify' ), 'type' => 'checkbox', 'default' => '1' ),
					array(
						'key'         => 'yt_video_urls',
						'label'       => __( 'Video links (one per line)', 'themify' ),
						'type'        => 'textarea',
						'rows'        => 7,
						'placeholder' => "https://youtu.be/xxxxxxxxxxx\nhttps://youtu.be/yyyyyyyyyyy",
						'desc'        => __( 'Paste the exact videos you want shown — one link per line (up to the count below). This is the reliable way to choose your videos; leave it empty to auto-pull the latest from your channel. Every play still counts for your channel.', 'themify' ),
					),
					array( 'key' => 'yt_video_count', 'label' => __( 'How many videos', 'themify' ), 'type' => 'number', 'default' => '12', 'desc' => __( 'Shown 4 per row. Default 12 (three rows).', 'themify' ) ),
				),
			),
		),
	) );

	// Connection tester / cache refresh.
	echo '<div class="tf-card">';
	echo '<h2 class="tf-card__title">' . esc_html__( 'Connection', 'themify' ) . '</h2>';
	echo '<p class="tf-card__desc">' . esc_html__( 'Videos are cached for a few hours. Save your channel URL above first, then use this to test the connection and pull the newest videos now.', 'themify' ) . '</p>';
	echo '<div class="tf-actions"><button type="button" class="button button-secondary tf-run" data-action="themify_youtube_refresh" data-target="#tf-yt-result" data-running="' . esc_attr__( 'Checking…', 'themify' ) . '">' . esc_html__( 'Test connection & refresh videos', 'themify' ) . '</button></div>';
	echo '<div id="tf-yt-result"></div>';
	echo '</div>';
}
