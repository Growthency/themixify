<?php
/**
 * The site header: document head, opening body markup, skip link, the sticky
 * site header (brand + nav toggle + primary menu) and the opening <main>.
 *
 * Everything visible is driven by WordPress content + theme options: the brand
 * is the custom logo when set (else the site title), and the sticky behaviour
 * is a toggle. Templates include this via get_header().
 *
 * @package Themify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<a class="tf-skip-link" href="#tf-main"><?php esc_html_e( 'Skip to content', 'themify' ); ?></a>

<header class="tf-site-header<?php echo themify_is_enabled( 'sticky_header', true ) ? ' is-sticky' : ''; ?>">
	<div class="tf-container">
		<nav class="tf-nav" aria-label="<?php esc_attr_e( 'Primary', 'themify' ); ?>">
			<?php
			$themify_logo = themify_get_option( 'brand_logo', '' );
			if ( $themify_logo ) {
				$themify_logo_h = (int) themify_get_option( 'logo_height', 56 );
				if ( $themify_logo_h < 1 ) {
					$themify_logo_h = 56;
				}

				// Intrinsic dimensions (cached) so the logo never counts as an
				// unsized image; the attributes give the browser its aspect ratio.
				$themify_logo_dims = get_transient( 'themify_logo_dims' );
				if ( ! is_array( $themify_logo_dims ) || ( $themify_logo_dims['url'] ?? '' ) !== $themify_logo ) {
					$themify_logo_dims = array(
						'url' => $themify_logo,
						'w'   => 0,
						'h'   => 0,
					);
					$themify_logo_id = attachment_url_to_postid( $themify_logo );
					if ( $themify_logo_id ) {
						$themify_logo_src = wp_get_attachment_image_src( $themify_logo_id, 'full' );
						if ( $themify_logo_src ) {
							$themify_logo_dims['w'] = (int) $themify_logo_src[1];
							$themify_logo_dims['h'] = (int) $themify_logo_src[2];
						}
					}
					set_transient( 'themify_logo_dims', $themify_logo_dims, WEEK_IN_SECONDS );
				}
				$themify_logo_attrs = ( $themify_logo_dims['w'] > 0 && $themify_logo_dims['h'] > 0 )
					? sprintf( ' width="%d" height="%d"', (int) $themify_logo_dims['w'], (int) $themify_logo_dims['h'] )
					: '';

				printf(
					'<a class="tf-brand tf-brand--logo" href="%s" rel="home"><img src="%s" alt="%s"%s style="max-height:%dpx;width:auto;" /></a>',
					esc_url( home_url( '/' ) ),
					esc_url( $themify_logo ),
					esc_attr( get_bloginfo( 'name' ) ),
					$themify_logo_attrs, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from bounded integers.
					$themify_logo_h
				);
			} elseif ( has_custom_logo() ) {
				the_custom_logo();
			} else {
				printf(
					'<a class="tf-brand" href="%s" rel="home">%s</a>',
					esc_url( home_url( '/' ) ),
					esc_html( get_bloginfo( 'name' ) )
				);
			}
			?>

			<button class="tf-nav-toggle" aria-expanded="false" aria-label="<?php esc_attr_e( 'Menu', 'themify' ); ?>"><span></span><span></span><span></span></button>

			<?php
			wp_nav_menu( array(
				'theme_location' => 'primary',
				'menu_class'     => 'tf-menu',
				'container'      => false,
				'fallback_cb'    => false,
				'depth'          => 2,
			) );

			// Optional header search (Themixify → General → Header search bar).
			if ( themify_is_enabled( 'header_search_enabled', false ) ) :
				?>
				<form role="search" method="get" class="tf-nav-search" action="<?php echo esc_url( home_url( '/' ) ); ?>">
					<input
						type="search"
						name="s"
						class="tf-nav-search__input"
						placeholder="<?php esc_attr_e( 'Search…', 'themify' ); ?>"
						aria-label="<?php esc_attr_e( 'Search this site', 'themify' ); ?>"
						value="<?php echo esc_attr( get_search_query() ); ?>"
					/>
					<button type="submit" class="tf-nav-search__btn" aria-label="<?php esc_attr_e( 'Search', 'themify' ); ?>">
						<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true" focusable="false"><circle cx="11" cy="11" r="7"></circle><line x1="21" y1="21" x2="16.4" y2="16.4"></line></svg>
					</button>
				</form>
				<?php
			endif;
			?>
		</nav>
	</div>
</header>

<main id="tf-main" class="tf-main">
