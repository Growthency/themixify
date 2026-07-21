<?php
/**
 * Template part: the tabbed post author box.
 *
 * Rendered at the foot of single posts. Two tabs:
 *   • Author       — avatar + name (linked to the author archive) + biography.
 *   • Recent Posts — avatar + "Latest posts by {name} (see all)" + the author's
 *                    latest 3 posts with dates. "(see all)" links to the author
 *                    archive, which lists every article they have published.
 *
 * Tab switching is handled by main.js; with JS off both panels are readable.
 *
 * @package Themify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$themify_author_id = (int) get_the_author_meta( 'ID' );
if ( ! $themify_author_id ) {
	$themify_author_id = (int) get_post_field( 'post_author', get_queried_object_id() );
}
if ( ! $themify_author_id ) {
	return;
}

$themify_author_name = get_the_author_meta( 'display_name', $themify_author_id );
$themify_author_bio  = trim( (string) get_the_author_meta( 'description', $themify_author_id ) );
$themify_author_url  = get_author_posts_url( $themify_author_id );
$themify_uid         = 'tfauthor-' . $themify_author_id;

// The author's latest 3 posts (excluding the one being read).
$themify_author_recent = new WP_Query( array(
	'author'              => $themify_author_id,
	'post_type'           => 'post',
	'post_status'         => 'publish',
	'posts_per_page'      => 3,
	'post__not_in'        => array( (int) get_the_ID() ),
	'ignore_sticky_posts' => 1,
	'no_found_rows'       => true,
) );
?>
<section class="tf-authorbox" data-tf-authorbox aria-label="<?php esc_attr_e( 'About the author', 'themify' ); ?>">
	<div class="tf-authorbox__tabs" role="tablist">
		<button type="button" id="<?php echo esc_attr( $themify_uid ); ?>-tab-author" class="tf-authorbox__tab is-active" role="tab" aria-selected="true" aria-controls="<?php echo esc_attr( $themify_uid ); ?>-author" data-tab="author">
			<?php esc_html_e( 'Author', 'themify' ); ?>
		</button>
		<?php if ( $themify_author_recent->have_posts() ) : ?>
			<button type="button" id="<?php echo esc_attr( $themify_uid ); ?>-tab-recent" class="tf-authorbox__tab" role="tab" aria-selected="false" aria-controls="<?php echo esc_attr( $themify_uid ); ?>-recent" data-tab="recent">
				<?php esc_html_e( 'Recent Posts', 'themify' ); ?>
			</button>
		<?php endif; ?>
	</div>

	<!-- Author tab -->
	<div id="<?php echo esc_attr( $themify_uid ); ?>-author" class="tf-authorbox__panel is-active" role="tabpanel" aria-labelledby="<?php echo esc_attr( $themify_uid ); ?>-tab-author" data-panel="author">
		<div class="tf-authorbox__avatar">
			<a href="<?php echo esc_url( $themify_author_url ); ?>" rel="author">
				<?php echo get_avatar( $themify_author_id, 96, '', $themify_author_name ); // phpcs:ignore WordPress.Security.EscapeOutput -- core avatar markup. ?>
			</a>
		</div>
		<div class="tf-authorbox__body">
			<a class="tf-authorbox__name" href="<?php echo esc_url( $themify_author_url ); ?>" rel="author"><?php echo esc_html( $themify_author_name ); ?></a>
			<?php if ( '' !== $themify_author_bio ) : ?>
				<p class="tf-authorbox__bio"><?php echo esc_html( $themify_author_bio ); ?></p>
			<?php endif; ?>
		</div>
	</div>

	<!-- Recent Posts tab -->
	<?php if ( $themify_author_recent->have_posts() ) : ?>
		<div id="<?php echo esc_attr( $themify_uid ); ?>-recent" class="tf-authorbox__panel" role="tabpanel" aria-labelledby="<?php echo esc_attr( $themify_uid ); ?>-tab-recent" data-panel="recent">
			<div class="tf-authorbox__avatar">
				<a href="<?php echo esc_url( $themify_author_url ); ?>" rel="author">
					<?php echo get_avatar( $themify_author_id, 96, '', $themify_author_name ); // phpcs:ignore WordPress.Security.EscapeOutput -- core avatar markup. ?>
				</a>
			</div>
			<div class="tf-authorbox__body">
				<h3 class="tf-authorbox__heading">
					<?php
					/* translators: %s: author name */
					echo esc_html( sprintf( __( 'Latest posts by %s', 'themify' ), $themify_author_name ) );
					?>
					<a class="tf-authorbox__seeall" href="<?php echo esc_url( $themify_author_url ); ?>"><?php esc_html_e( '(see all)', 'themify' ); ?></a>
				</h3>
				<ul class="tf-authorbox__posts">
					<?php
					while ( $themify_author_recent->have_posts() ) :
						$themify_author_recent->the_post();
						?>
						<li>
							<a href="<?php the_permalink(); ?>"><?php echo esc_html( get_the_title() ); ?></a>
							<span class="tf-authorbox__date"> - <?php echo esc_html( get_the_date() ); ?></span>
						</li>
						<?php
					endwhile;
					wp_reset_postdata();
					?>
				</ul>
			</div>
		</div>
	<?php endif; ?>
</section>
