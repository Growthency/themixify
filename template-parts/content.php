<?php
/**
 * Template part: a single blog post card for archive / index loops.
 *
 * Rendered inside a .tf-grid via get_template_part( 'template-parts/content' )
 * while the main loop is active, so it relies on the global $post. It outputs
 * the shared post-card surface: a linked featured thumbnail (with a graceful
 * fallback when the post has no image), category pills, the linked title, a
 * trimmed excerpt and the compact entry meta line.
 *
 * @package Themify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$themify_permalink = get_permalink();
?>
<article id="post-<?php the_ID(); ?>" <?php post_class( 'tf-card-post' ); ?>>
	<a class="tf-card-post__thumb" href="<?php echo esc_url( $themify_permalink ); ?>" aria-hidden="true" tabindex="-1">
		<?php
		if ( has_post_thumbnail() ) {
			the_post_thumbnail(
				'themify-card',
				array(
					'alt'     => the_title_attribute( array( 'echo' => false ) ),
					'loading' => 'lazy',
				)
			);
		} else {
			// Graceful no-image fallback so the card keeps its 16:9 frame.
			echo '<span class="tf-card-post__thumb-fallback" aria-hidden="true"></span>';
		}
		?>
	</a>

	<div class="tf-card-post__body">
		<?php themify_category_pills(); ?>

		<h2 class="tf-card-post__title">
			<a href="<?php echo esc_url( $themify_permalink ); ?>"><?php the_title(); ?></a>
		</h2>

		<p class="tf-card-post__excerpt">
			<?php echo esc_html( wp_trim_words( get_the_excerpt(), 24 ) ); ?>
		</p>

		<div class="tf-card-post__footer">
			<?php themify_entry_meta(); ?>
		</div>
	</div>
</article>
