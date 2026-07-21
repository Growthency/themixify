<?php
/**
 * The single page template.
 *
 * A pared-down cousin of single.php for static pages: breadcrumbs, an article
 * with an optional title, an optional featured hero, the page body and (when
 * open) the comment thread. No post meta, tags, related posts or author box.
 *
 * The page title can be suppressed per-page with a "hide title" post meta flag
 * ( _themify_hide_title ) so builder-style pages can supply their own hero.
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

			if ( have_posts() ) :
				while ( have_posts() ) :
					the_post();

					$themify_hide_title = get_post_meta( get_the_ID(), '_themify_hide_title', true );
					?>
					<article id="post-<?php the_ID(); ?>" <?php post_class( 'tf-article' ); ?>>
						<?php if ( ! $themify_hide_title ) : ?>
							<header class="tf-article__header">
								<h1 class="tf-article__title"><?php the_title(); ?></h1>
							</header>
						<?php endif; ?>

						<?php if ( has_post_thumbnail() ) : ?>
							<figure class="tf-article__hero">
								<?php the_post_thumbnail( 'themify-hero' ); ?>
							</figure>
						<?php endif; ?>

						<div class="tf-content">
							<?php
							the_content();

							wp_link_pages( array(
								'before'      => '<nav class="tf-page-links" aria-label="' . esc_attr__( 'Page sections', 'themify' ) . '">' . esc_html__( 'Pages:', 'themify' ),
								'after'       => '</nav>',
								'link_before' => '<span class="tf-page-links__num">',
								'link_after'  => '</span>',
							) );
							?>
						</div>

						<?php
						if ( comments_open() || get_comments_number() ) {
							comments_template();
						}
						?>
					</article>
					<?php
				endwhile;
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
