<?php
/**
 * The search results template.
 *
 * Renders a "Search results for X" header, the matching posts in the standard
 * grid with pagination, and the shared sidebar. When nothing matches, the
 * content-none part shows a friendly message plus a fresh search form.
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
				<h1 class="tf-archive-header__title">
					<?php
					printf(
						/* translators: %s: search query. */
						esc_html__( 'Search results for: %s', 'themify' ),
						'<span class="tf-archive-header__query">' . esc_html( get_search_query() ) . '</span>'
					);
					?>
				</h1>
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
