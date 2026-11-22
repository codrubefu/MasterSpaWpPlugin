<?php
/**
 * Products imported with webSale = 0 (not sold online): message instead of the buy button.
 *
 * The purchasable filter lives in MasterSpa_WP_Plugin::filter_web_sale_purchasable().
 * The single product template (templates/single-product.php) prints masterspa_web_sale_message();
 * this file covers the product lists (shop, categories, related products).
 *
 * @package MasterSpaWpPlugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Default text.
 *
 * @return string
 */
function masterspa_web_sale_default_message() {
	return __( 'Acest pachet nu se poate cumpăra online. Pentru rezervare, contactează recepția hotelului.', 'masterspa-wp-plugin' );
}

/**
 * Text shown for products not sold online (configurable in the settings).
 *
 * @return string
 */
function masterspa_web_sale_message() {
	$settings = get_option( 'masterspa_settings', array() );
	$message  = isset( $settings['web_sale_message'] ) ? trim( (string) $settings['web_sale_message'] ) : '';
	return '' !== $message ? $message : masterspa_web_sale_default_message();
}

/**
 * Whether the product is a MasterSpa product with webSale = 0.
 *
 * @param WC_Product $product Product.
 * @return bool
 */
function masterspa_is_not_web_sale( $product ) {
	if ( ! $product instanceof WC_Product ) {
		return false;
	}
	$product_id = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
	return 'no' === get_post_meta( $product_id, '_masterspa_web_sale', true );
}

// Product lists: replace the "Read more" button WooCommerce shows for non-purchasable products.
add_filter(
	'woocommerce_loop_add_to_cart_link',
	function ( $html, $product ) {
		if ( ! masterspa_is_not_web_sale( $product ) ) {
			return $html;
		}
		return '<span class="masterspa-web-sale-note">' . esc_html( masterspa_web_sale_message() ) . '</span>';
	},
	20,
	2
);
