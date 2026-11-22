<?php
/**
 * Frontend tweaks for the single product page of spa packages.
 *
 * - hides the SKU / Categories / Tags block (classic templates and block themes)
 * - adds a body class used by the CSS (text-align: justify on the description)
 *
 * @package MasterSpaWpPlugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether a product was imported by MasterSpa (spa package).
 *
 * @param int $product_id Product ID.
 * @return bool
 */
function masterspa_is_spa_product( $product_id ) {
	$product_id = (int) $product_id;
	if ( ! $product_id ) {
		return false;
	}
	if ( '' !== (string) get_post_meta( $product_id, '_masterspa_web_sale', true ) ) {
		return true;
	}
	return has_term( 'spa', 'product_tag', $product_id ) || has_term( 'spa', 'product_cat', $product_id );
}

/**
 * Whether the current request is the single page of a spa product.
 *
 * @return bool
 */
function masterspa_is_spa_product_page() {
	return function_exists( 'is_product' ) && is_product() && masterspa_is_spa_product( get_queried_object_id() );
}

// Classic templates: remove "SKU / Categorii / Etichetă".
add_action(
	'wp',
	function () {
		if ( masterspa_is_spa_product_page() ) {
			remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_meta', 40 );
		}
	}
);

// Block themes: drop the product meta / SKU blocks on spa product pages.
add_filter(
	'render_block',
	function ( $content, $block ) {
		$name = isset( $block['blockName'] ) ? $block['blockName'] : '';
		if ( 'woocommerce/product-meta' !== $name && 'woocommerce/product-sku' !== $name ) {
			return $content;
		}
		return masterspa_is_spa_product_page() ? '' : $content;
	},
	10,
	2
);

// Body class used by assets/css/style.css.
add_filter(
	'body_class',
	function ( $classes ) {
		if ( masterspa_is_spa_product_page() ) {
			$classes[] = 'masterspa-spa-product';
		}
		return $classes;
	}
);
