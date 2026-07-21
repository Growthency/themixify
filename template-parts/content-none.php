<?php
/**
 * Template part: the "nothing found" state.
 *
 * Rendered via get_template_part( 'template-parts/content', 'none' ) whenever a
 * loop has no posts — an empty archive, a search with no matches, or a blog
 * index with no published content. Shows a friendly, context-aware message and
 * a fresh search form so visitors can recover.
 *
 * @package Themify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<section class="tf-none">
	<h2 class="tf-none__title"><?php esc_html_e( 'Nothing found', 'themify' ); ?></h2>

	<div class="tf-none__body">
		<?php if ( is_search() ) : ?>
			<p class="tf-none__message">
				<?php esc_html_e( 'Sorry, nothing matched your search. Try different keywords.', 'themify' ); ?>
			</p>
		<?php else : ?>
			<p class="tf-none__message">
				<?php esc_html_e( 'It looks like there is nothing here yet. Try a search to find what you are looking for.', 'themify' ); ?>
			</p>
		<?php endif; ?>

		<div class="tf-none__search">
			<?php get_search_form(); ?>
		</div>
	</div>
</section>
