<?php
/**
 * The comments template.
 *
 * Loaded by comments_template() from single.php. Renders the existing comment
 * thread (HTML5 markup, threaded, with avatars), pagination for long threads,
 * and the comment form with tidy defaults. Nothing is shown for password
 * protected posts whose password has not been entered.
 *
 * @package Themify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Never leak comments for a post that is still password protected.
if ( post_password_required() ) {
	return;
}
?>
<div id="comments" class="tf-comments">
	<?php if ( have_comments() ) : ?>
		<h2 class="tf-comments__title">
			<?php
			$themify_comment_count = get_comments_number();
			if ( '1' === (string) $themify_comment_count ) {
				esc_html_e( 'One comment', 'themify' );
			} else {
				printf(
					/* translators: %s: formatted comment count. */
					esc_html( _n( '%s comment', '%s comments', $themify_comment_count, 'themify' ) ),
					esc_html( number_format_i18n( $themify_comment_count ) )
				);
			}
			?>
		</h2>

		<ol class="tf-comment-list">
			<?php
			wp_list_comments(
				array(
					'style'       => 'ol',
					'avatar_size' => 48,
				)
			);
			?>
		</ol>

		<?php
		the_comments_pagination(
			array(
				'prev_text'          => __( '&lsaquo; Older comments', 'themify' ),
				'next_text'          => __( 'Newer comments &rsaquo;', 'themify' ),
				'screen_reader_text' => __( 'Comments navigation', 'themify' ),
				'class'              => 'tf-pagination tf-comments__pagination',
			)
		);
		?>

		<?php if ( ! comments_open() ) : ?>
			<p class="tf-comments__closed"><?php esc_html_e( 'Comments are closed.', 'themify' ); ?></p>
		<?php endif; ?>
	<?php endif; ?>

	<?php
	comment_form(
		array(
			'class_form'         => 'comment-form tf-comment-form',
			'title_reply'        => __( 'Leave a comment', 'themify' ),
			'title_reply_to'     => __( 'Reply to %s', 'themify' ),
			'cancel_reply_link'  => __( 'Cancel reply', 'themify' ),
			'label_submit'       => __( 'Post comment', 'themify' ),
			'class_submit'       => 'tf-btn',
			'comment_notes_before' => '<p class="comment-notes tf-comment-form__notes">' . esc_html__( 'Your email address will not be published. Required fields are marked with an asterisk.', 'themify' ) . '</p>',
		)
	);
	?>
</div>
