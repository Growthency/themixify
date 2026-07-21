<?php
/**
 * The 404 (page not found) template.
 *
 * A friendly error screen: a large heading, an explanatory message, a search
 * form to help visitors recover, and a short list of recent posts to keep them
 * on the site. Uses the shared article surface so it matches the rest of the
 * theme, then falls back to the standard sidebar.
 *
 * @package Themify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
?>
<div class="tf-container">
	<div class="tf-layout">
		<div class="tf-primary">
			<?php
			if ( themify_is_enabled( 'show_breadcrumbs', true ) ) {
				themify_breadcrumbs();
			}
			?>
			<section class="tf-article tf-404">
				<header class="tf-article__header tf-404__header">
					<p class="tf-404__code" aria-hidden="true">404</p>
					<h1 class="tf-article__title"><?php esc_html_e( 'Page not found', 'themify' ); ?></h1>
					<p class="tf-404__message">
						<?php esc_html_e( 'Sorry, we could not find the page you were looking for. It may have moved, or the link might be broken. Try searching, or browse the latest posts below.', 'themify' ); ?>
					</p>
				</header>

				<div class="tf-404__search">
					<?php get_search_form(); ?>
				</div>

				<?php
				$themify_recent = new WP_Query(
					array(
						'post_type'           => 'post',
						'posts_per_page'      => 5,
						'post_status'         => 'publish',
						'ignore_sticky_posts' => true,
						'no_found_rows'       => true,
					)
				);

				if ( $themify_recent->have_posts() ) :
					?>
					<nav class="tf-404__recent" aria-label="<?php esc_attr_e( 'Recent posts', 'themify' ); ?>">
						<h2 class="tf-404__recent-title"><?php esc_html_e( 'Recent posts', 'themify' ); ?></h2>
						<ul class="tf-404__list">
							<?php
							while ( $themify_recent->have_posts() ) :
								$themify_recent->the_post();
								?>
								<li class="tf-404__item">
									<a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
									<time class="tf-404__date" datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>"><?php echo esc_html( get_the_date() ); ?></time>
								</li>
								<?php
							endwhile;
							?>
						</ul>
					</nav>
					<?php
					wp_reset_postdata();
				endif;
				?>

				<p class="tf-404__actions">
					<a class="tf-btn" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Back to home', 'themify' ); ?></a>
				</p>
			</section>
		</div>
		<?php get_sidebar(); ?>
	</div>
</div>
<?php
get_footer();
