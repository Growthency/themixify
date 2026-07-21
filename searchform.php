<?php
/**
 * The search form. Rendered by get_search_form() wherever a search box is
 * needed (404 page, search results, widgets). Accessible HTML5 form posting
 * to the site root with the standard "s" query var.
 *
 * @package Themify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$themify_search_id = 'tf-search-' . wp_unique_id();
?>
<form role="search" method="get" class="tf-search-form" action="<?php echo esc_url( home_url( '/' ) ); ?>">
	<label class="tf-visually-hidden" for="<?php echo esc_attr( $themify_search_id ); ?>"><?php esc_html_e( 'Search for:', 'themify' ); ?></label>
	<input
		type="search"
		id="<?php echo esc_attr( $themify_search_id ); ?>"
		class="tf-input tf-search-form__input"
		name="s"
		value="<?php echo esc_attr( get_search_query() ); ?>"
		placeholder="<?php esc_attr_e( 'Search&hellip;', 'themify' ); ?>"
	/>
	<button type="submit" class="tf-btn tf-search-form__submit">
		<?php esc_html_e( 'Search', 'themify' ); ?>
	</button>
</form>
