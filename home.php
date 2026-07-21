<?php
/**
 * The blog posts index template.
 *
 * Used for the posts page — i.e. when a static front page is configured under
 * Settings → Reading and the latest-posts list is assigned to a separate page.
 * It renders the standard post grid with pagination and the shared sidebar.
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

			// When this template is the assigned "Posts page", show its title.
			$themify_posts_page_id = (int) get_option( 'page_for_posts' );
			if ( $themify_posts_page_id && is_home() && ! is_front_page() ) :
				?>
				<header class="tf-archive-header">
					<h1 class="tf-archive-header__title"><?php echo esc_html( get_the_title( $themify_posts_page_id ) ); ?></h1>
					<?php
					$themify_posts_page_desc = get_post_field( 'post_content', $themify_posts_page_id );
					if ( '' !== trim( wp_strip_all_tags( (string) $themify_posts_page_desc ) ) ) :
						?>
						<div class="tf-archive-header__desc"><?php echo wp_kses_post( apply_filters( 'the_content', $themify_posts_page_desc ) ); ?></div>
					<?php endif; ?>
				</header>
				<?php
			endif;

			if ( have_posts() ) :
				?>
				<div class="tf-grid">
					<?php
					while ( have_posts() ) :
						the_post();
						get_template_part( 'template-parts/content' );
					endwhile;
					?>
				</div>
				<?php
				themify_pagination();
			else :
				get_template_part( 'template-parts/content', 'none' );
			endif;
			?>
		</div>
		<?php get_sidebar(); ?>
	</div>
</div>
<?php
get_footer();
