<?php
/**
 * The archive template — category, tag, taxonomy, author, and date archives.
 *
 * Shows an archive header built from the core get_the_archive_title() /
 * get_the_archive_description() helpers, followed by the post grid, numbered
 * pagination, and the shared sidebar.
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
