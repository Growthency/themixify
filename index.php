<?php
/**
 * The main template file — the ultimate fallback in the template hierarchy.
 *
 * WordPress falls back to index.php whenever a more specific template
 * (home.php, archive.php, single.php …) is absent. It renders the standard
 * post list: an optional archive header, the loop of post cards inside a
 * .tf-grid, numbered pagination, and the shared sidebar.
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

			if ( is_archive() ) :
				?>
				<header class="tf-archive-header">
					<h1 class="tf-archive-header__title"><?php the_archive_title(); ?></h1>
					<?php
					$themify_archive_description = get_the_archive_description();
					if ( $themify_archive_description ) :
						?>
						<div class="tf-archive-header__desc"><?php echo wp_kses_post( $themify_archive_description ); ?></div>
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
