<?php
/**
 * Subscriber (beneficiar) UI for the WooCommerce Cart Block.
 *
 * The Cart Block is rendered in React from the Store API and does not fire the
 * classic cart hooks used in subscription-users-cart.php. This file exposes the
 * subscriber data through the Store API and loads a small script that renders
 * the editing UI in the cart sidebar (ExperimentalOrderMeta slot).
 *
 * @package MasterSpaWpPlugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const MASTERSPA_STORE_API_NAMESPACE = 'masterspa';

/**
 * Empty subscriber row.
 *
 * @return array
 */
function masterspa_empty_subscriber() {
	return array(
		'prenume'    => '',
		'nume'       => '',
		'email'      => '',
		'phone'      => '',
		'send_email' => '',
	);
}

/**
 * Return the saved subscribers of a cart item, padded/truncated to its quantity.
 *
 * @param array $cart_item Cart item.
 * @return array
 */
function masterspa_get_cart_item_subscribers( $cart_item ) {
	$qty   = max( 1, (int) ( $cart_item['quantity'] ?? 1 ) );
	$saved = is_array( $cart_item['subscription_user'] ?? null ) ? array_values( $cart_item['subscription_user'] ) : array();
	$list  = array();

	for ( $i = 0; $i < $qty; $i++ ) {
		$row    = is_array( $saved[ $i ] ?? null ) ? $saved[ $i ] : array();
		$list[] = array(
			'prenume'    => (string) ( $row['prenume'] ?? '' ),
			'nume'       => (string) ( $row['nume'] ?? '' ),
			'email'      => (string) ( $row['email'] ?? '' ),
			'phone'      => (string) ( $row['phone'] ?? '' ),
			'send_email' => ! empty( $row['send_email'] ) ? '1' : '',
		);
	}

	return $list;
}

/**
 * Register Store API data + update callback.
 */
function masterspa_register_store_api_extension() {
	if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
		return;
	}

	woocommerce_store_api_register_endpoint_data(
		array(
			'endpoint'        => \Automattic\WooCommerce\StoreApi\Schemas\V1\CartItemSchema::IDENTIFIER,
			'namespace'       => MASTERSPA_STORE_API_NAMESPACE,
			'data_callback'   => function ( $cart_item ) {
				return array(
					'subscription_user' => masterspa_get_cart_item_subscribers( $cart_item ),
				);
			},
			'schema_callback' => function () {
				$string = array(
					'type'     => 'string',
					'context'  => array( 'view', 'edit' ),
					'readonly' => true,
				);
				return array(
					'subscription_user' => array(
						'description' => __( 'Beneficiari abonament', 'masterspa-wp-plugin' ),
						'type'        => 'array',
						'context'     => array( 'view', 'edit' ),
						'readonly'    => true,
						'items'       => array(
							'type'       => 'object',
							'properties' => array(
								'prenume'    => $string,
								'nume'       => $string,
								'email'      => $string,
								'phone'      => $string,
								'send_email' => $string,
							),
						),
					),
				);
			},
			'schema_type'     => ARRAY_A,
		)
	);

	woocommerce_store_api_register_update_callback(
		array(
			'namespace' => MASTERSPA_STORE_API_NAMESPACE,
			'callback'  => function ( $data ) {
				$cart = WC()->cart;
				if ( ! $cart || ! is_array( $data ) ) {
					return;
				}

				$key = isset( $data['cart_item_key'] ) ? sanitize_text_field( (string) $data['cart_item_key'] ) : '';
				if ( '' === $key || ! isset( $cart->cart_contents[ $key ] ) ) {
					return;
				}

				$item  = $cart->cart_contents[ $key ];
				$qty   = max( 1, (int) ( $item['quantity'] ?? 1 ) );
				$index = isset( $data['index'] ) ? (int) $data['index'] : -1;
				if ( $index < 0 || $index >= $qty ) {
					return;
				}

				$list           = masterspa_get_cart_item_subscribers( $item );
				$list[ $index ] = array(
					'prenume'    => sanitize_text_field( $data['prenume'] ?? '' ),
					'nume'       => sanitize_text_field( $data['nume'] ?? '' ),
					'email'      => sanitize_email( $data['email'] ?? '' ),
					'phone'      => sanitize_text_field( $data['phone'] ?? '' ),
					'send_email' => ! empty( $data['send_email'] ) ? '1' : '',
				);

				$cart->cart_contents[ $key ]['subscription_user'] = $list;
				$cart->set_session();
			},
		)
	);
}

// This file is loaded on plugins_loaded, after WooCommerce has usually fired woocommerce_blocks_loaded.
if ( did_action( 'woocommerce_blocks_loaded' ) ) {
	masterspa_register_store_api_extension();
} else {
	add_action( 'woocommerce_blocks_loaded', 'masterspa_register_store_api_extension' );
}

/**
 * Show saved subscribers under each item name (Cart + Checkout blocks render this natively).
 */
add_filter( 'woocommerce_get_item_data', function ( $item_data, $cart_item ) {
	$list = masterspa_get_cart_item_subscribers( $cart_item );
	$qty  = count( $list );

	foreach ( $list as $i => $row ) {
		$name  = trim( $row['prenume'] . ' ' . $row['nume'] );
		$parts = array_filter( array( $name, $row['email'], $row['phone'] ) );
		if ( empty( $parts ) ) {
			continue;
		}
		$item_data[] = array(
			'key'   => sprintf( 'Abonat (%d/%d)', $i + 1, $qty ),
			'value' => implode( ' · ', $parts ),
		);
	}

	return $item_data;
}, 10, 2 );

/**
 * Modal styles (wp.components) only where the Cart block is rendered.
 */
add_filter( 'render_block_woocommerce/cart', function ( $content ) {
	wp_enqueue_style( 'wp-components' );
	return $content;
} );

/**
 * Load the frontend script with the Cart block.
 */
add_action( 'woocommerce_blocks_cart_block_registration', function ( $integration_registry ) {
	if ( ! interface_exists( '\Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface' ) ) {
		return;
	}

	$integration_registry->register(
		new class() implements \Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface {
			public function get_name() {
				return 'masterspa-subscribers';
			}

			public function initialize() {
				$file = MASTERSPA_PLUGIN_DIR . 'assets/js/cart-block-subscribers.js';
				wp_register_script(
					'masterspa-cart-block',
					MASTERSPA_PLUGIN_URL . 'assets/js/cart-block-subscribers.js',
					array( 'wc-blocks-checkout', 'wp-plugins', 'wp-element', 'wp-components' ),
					file_exists( $file ) ? (string) filemtime( $file ) : MASTERSPA_VERSION,
					true
				);
			}

			public function get_script_handles() {
				return array( 'masterspa-cart-block' );
			}

			public function get_editor_script_handles() {
				return array();
			}

			public function get_script_data() {
				return array();
			}
		}
	);
} );
