<?php
/**
 * The site footer: closes <main>, renders the footer (delegated to the footer
 * module when present, else a minimal fallback), the back-to-top control and
 * the closing document tags. Templates include this via get_footer().
 *
 * @package Themify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
</main>

<?php
if ( function_exists( 'themify_render_footer' ) ) {
	themify_render_footer();
} else {
	?>
	<footer class="tf-site-footer">
		<div class="tf-container tf-footer-bottom">
			<?php
			printf(
				/* translators: 1: current year, 2: site name */
				esc_html__( '&copy; %1$s %2$s', 'themify' ),
				esc_html( gmdate( 'Y' ) ),
				esc_html( get_bloginfo( 'name' ) )
			);
			?>
		</div>
	</footer>
	<?php
}
?>

<?php if ( themify_is_enabled( 'back_to_top', true ) ) : ?>
<button class="tf-back-to-top" aria-label="<?php esc_attr_e( 'Back to top', 'themify' ); ?>">&uarr;</button>
<?php endif; ?>

<?php wp_footer(); ?>
</body>
</html>
