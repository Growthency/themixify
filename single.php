<?php
/**
 * The single post template.
 *
 * Renders one blog post inside the shared two-column layout: breadcrumbs, the
 * article header (category pills, title, meta), an optional featured hero, the
 * body content with an auto-generated table of contents, the post tags, an
 * author box, related posts and the comment thread. The view counter is bumped
 * once per render via themify_register_view().
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

			while ( have_posts() ) :
				the_post();
				themify_register_view( get_the_ID() );
				?>
				<article id="post-<?php the_ID(); ?>" <?php post_class( 'tf-article' ); ?>>
					<header class="tf-article__header">
						<?php
						themify_category_pills();
						?>
						<h1 class="tf-article__title"><?php the_title(); ?></h1>
						<?php
						themify_entry_meta();
						?>
					</header>

					<?php
					// Social share row under the title (top).
					if ( function_exists( 'themify_article_share_row' ) ) {
						themify_article_share_row( 'top' );
					}
					?>

					<?php if ( has_post_thumbnail() ) : ?>
						<figure class="tf-article__hero">
							<?php the_post_thumbnail( 'themify-hero' ); ?>
						</figure>
					<?php endif; ?>

					<?php
					// Build the content once, generate a TOC from its headings and
					// print the TOC before the content body.
					$themify_content = apply_filters( 'the_content', get_the_content() );
					$themify_toc     = themify_build_toc( $themify_content, 3 );

					echo $themify_toc['toc']; // phpcs:ignore WordPress.Security.EscapeOutput -- Sanitized markup built by themify_build_toc().
					echo '<div class="tf-content">' . $themify_toc['content'] . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- Post content already run through the_content filters.

					wp_link_pages( array(
						'before'      => '<nav class="tf-page-links" aria-label="' . esc_attr__( 'Post pages', 'themify' ) . '">' . esc_html__( 'Pages:', 'themify' ),
						'after'       => '</nav>',
						'link_before' => '<span class="tf-page-links__num">',
						'link_after'  => '</span>',
					) );

					// Post tags rendered as pills.
					$themify_tags = get_the_tags();
					if ( ! empty( $themify_tags ) && ! is_wp_error( $themify_tags ) ) :
						?>
						<div class="tf-article__tags tf-pills">
							<?php
							foreach ( $themify_tags as $themify_tag ) {
								printf(
									'<a class="tf-pill" href="%s" rel="tag">%s</a>',
									esc_url( get_tag_link( $themify_tag->term_id ) ),
									esc_html( $themify_tag->name )
								);
							}
							?>
						</div>
						<?php
					endif;

					// Author box (only when the author byline feature is on).
					if ( themify_is_enabled( 'show_author', true ) ) {
						get_template_part( 'template-parts/author-box' );
					}

					// Social share row at the foot of the post (bottom).
					if ( function_exists( 'themify_article_share_row' ) ) {
						themify_article_share_row( 'bottom' );
					}

					// Related posts by shared taxonomy — only render if any exist.
					$themify_related = themify_related_posts( 3 );
					if ( ! empty( $themify_related ) ) :
						?>
						<section class="tf-related" aria-label="<?php esc_attr_e( 'Related posts', 'themify' ); ?>">
							<h2 class="tf-related__title"><?php esc_html_e( 'Related posts', 'themify' ); ?></h2>
							<div class="tf-grid">
								<?php foreach ( $themify_related as $themify_related_post ) : ?>
									<article class="tf-card-post">
										<?php if ( has_post_thumbnail( $themify_related_post ) ) : ?>
											<a class="tf-card-post__thumb" href="<?php echo esc_url( get_permalink( $themify_related_post ) ); ?>" aria-hidden="true" tabindex="-1">
												<?php echo get_the_post_thumbnail( $themify_related_post, 'themify-card' ); // phpcs:ignore WordPress.Security.EscapeOutput -- Core markup with escaped attributes. ?>
											</a>
										<?php endif; ?>
										<div class="tf-card-post__body">
											<h3 class="tf-card-post__title">
												<a href="<?php echo esc_url( get_permalink( $themify_related_post ) ); ?>"><?php echo esc_html( get_the_title( $themify_related_post ) ); ?></a>
											</h3>
											<p class="tf-card-post__excerpt"><?php echo esc_html( wp_trim_words( get_the_excerpt( $themify_related_post ), 18 ) ); ?></p>
										</div>
									</article>
								<?php endforeach; ?>
							</div>
						</section>
						<?php
					endif;

					// Comments.
					if ( comments_open() || get_comments_number() ) {
						comments_template();
					}
					?>
				</article>
				<?php
			endwhile;
			?>
		</div>
		<?php
		// The rich, built-in article sidebar (search, author, popular, recent).
		// Falls back to the generic widget sidebar if the module is absent.
		if ( function_exists( 'themify_render_post_sidebar' ) ) {
			themify_render_post_sidebar();
		} else {
			get_sidebar();
		}
		?>
	</div>
</div>
<?php
get_footer();
