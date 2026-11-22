<?php
/**
 * Required subscriber (beneficiar) data for spa products: prenume, nume and a valid email
 * for every unit in the cart. Loaded only when "Beneficiar în coș" is enabled.
 *
 * - classic checkout: woocommerce_after_checkout_validation
 * - Checkout block (Store API): woocommerce_store_api_checkout_update_order_from_request
 * - classic cart: informative notice (does not block the cart)
 *
 * @package MasterSpaWpPlugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Errors for the current cart.
 *
 * @return string[]
 */
function masterspa_current_cart_subscriber_errors() {
	if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
		return array();
	}
	return masterspa_cart_subscriber_errors( WC()->cart->get_cart() );
}

// Classic checkout.
add_action(
	'woocommerce_after_checkout_validation',
	function ( $data, $errors ) {
		$messages = masterspa_current_cart_subscriber_errors();
		if ( empty( $messages ) ) {
			return;
		}
		$link = sprintf(
			' <a href="%s">%s</a>',
			esc_url( wc_get_cart_url() ),
			esc_html__( 'Completează beneficiarii în coș', 'masterspa-wp-plugin' )
		);
		foreach ( $messages as $i => $message ) {
			$errors->add( 'masterspa_subscriber_' . $i, esc_html( $message ) . ( 0 === $i ? $link : '' ) );
		}
	},
	10,
	2
);

// Checkout block (Store API).
add_action(
	'woocommerce_store_api_checkout_update_order_from_request',
	function () {
		$messages = masterspa_current_cart_subscriber_errors();
		if ( empty( $messages ) || ! class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
			return;
		}
		$text = __( 'Datele beneficiarilor sunt incomplete. Reveniți în coș și completați-le:', 'masterspa-wp-plugin' ) . ' ' . implode( ' ', $messages );
		throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'masterspa_subscribers_incomplete', $text, 400 );
	}
);

// Classic cart page: reminder only.
add_action(
	'woocommerce_check_cart_items',
	function () {
		if ( ! function_exists( 'is_cart' ) || ! is_cart() ) {
			return;
		}
		if ( ! empty( masterspa_current_cart_subscriber_errors() ) ) {
			wc_add_notice( __( 'Completați datele beneficiarului (prenume, nume, email) pentru fiecare pachet spa înainte de finalizarea comenzii.', 'masterspa-wp-plugin' ), 'notice' );
		}
	}
);
