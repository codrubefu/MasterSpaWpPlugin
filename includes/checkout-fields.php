<?php
/**
 * Checkout fields: Județ (county) and Telefon.
 *
 * - no default county (WooCommerce pre-fills the store base county, Iași)
 * - Județ and Telefon are required (classic checkout and Checkout block)
 * - server-side validation for both flows
 * - helper that maps the WooCommerce county code to the ID from the `judet` table
 *
 * @package MasterSpaWpPlugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * 1. No default county.
 * wc_get_customer_default_location() returns the store base location (RO:IS) for new customers.
 * Keep the country, drop the county so the customer has to choose it.
 */
add_filter(
	'woocommerce_customer_default_location_array',
	function ( $location ) {
		if ( is_array( $location ) ) {
			$location['state'] = '';
		}
		return $location;
	}
);

add_filter( 'default_checkout_billing_state', '__return_empty_string' );
add_filter( 'default_checkout_shipping_state', '__return_empty_string' );

/*
 * 2. Telefon required.
 * The option is read by both the classic checkout and the Checkout block,
 * so the "(opțional)" suffix disappears from the label.
 */
add_filter(
	'pre_option_woocommerce_checkout_phone_field',
	function () {
		return 'required';
	}
);

/*
 * 3. Județ required, with a Romanian label (locale is used by both checkout types).
 */
add_filter(
	'woocommerce_get_country_locale',
	function ( $locale ) {
		if ( ! isset( $locale['RO'] ) || ! is_array( $locale['RO'] ) ) {
			$locale['RO'] = array();
		}
		$locale['RO']['state'] = array_merge(
			isset( $locale['RO']['state'] ) && is_array( $locale['RO']['state'] ) ? $locale['RO']['state'] : array(),
			array(
				'label'    => __( 'Județ', 'masterspa-wp-plugin' ),
				'required' => true,
				'hidden'   => false,
			)
		);
		return $locale;
	}
);

// Classic checkout fields.
add_filter(
	'woocommerce_billing_fields',
	function ( $fields ) {
		if ( isset( $fields['billing_state'] ) ) {
			$fields['billing_state']['required'] = true;
			$fields['billing_state']['default']  = '';
		}
		if ( isset( $fields['billing_phone'] ) ) {
			$fields['billing_phone']['required'] = true;
		}
		return $fields;
	},
	20
);

/**
 * Validation errors for an address (shared by classic and block checkout).
 *
 * @param string $country Country code.
 * @param string $state   State code.
 * @param string $phone   Phone.
 * @return string[] Error messages.
 */
function masterspa_checkout_address_errors( $country, $state, $phone ) {
	$errors = array();
	$country = strtoupper( (string) $country );
	$state   = strtoupper( trim( (string) $state ) );

	if ( 'RO' === $country || '' === $country ) {
		$states = WC()->countries ? WC()->countries->get_states( 'RO' ) : array();
		if ( '' === $state || ( ! empty( $states ) && ! isset( $states[ $state ] ) ) ) {
			$errors[] = __( 'Vă rugăm să selectați județul.', 'masterspa-wp-plugin' );
		}
	}
	if ( '' === trim( (string) $phone ) ) {
		$errors[] = __( 'Vă rugăm să completați numărul de telefon.', 'masterspa-wp-plugin' );
	}
	return $errors;
}

// Classic checkout validation.
add_action(
	'woocommerce_after_checkout_validation',
	function ( $data, $errors ) {
		$country = isset( $data['billing_country'] ) ? $data['billing_country'] : '';
		$state   = isset( $data['billing_state'] ) ? $data['billing_state'] : '';
		$phone   = isset( $data['billing_phone'] ) ? $data['billing_phone'] : '';
		foreach ( masterspa_checkout_address_errors( $country, $state, $phone ) as $i => $message ) {
			if ( ! $errors->get_error_message( 'masterspa_checkout_' . $i ) ) {
				$errors->add( 'masterspa_checkout_' . $i, $message );
			}
		}
	},
	10,
	2
);

// Checkout block (Store API) validation.
add_action(
	'woocommerce_store_api_checkout_update_order_from_request',
	function ( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$errors = masterspa_checkout_address_errors( $order->get_billing_country(), $order->get_billing_state(), $order->get_billing_phone() );
		if ( empty( $errors ) ) {
			return;
		}
		if ( class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'masterspa_invalid_address', implode( ' ', $errors ), 400 );
		}
	}
);

/**
 * County info for an order: WooCommerce code, name and the ID from the `judet` table
 * (mapping configured in Settings > MasterSpa Settings).
 *
 * @param WC_Order $order Order.
 * @return array{id: int|null, cod: string, nume: string}
 */
function masterspa_get_order_judet( $order ) {
	$code = strtoupper( (string) $order->get_billing_state() );
	if ( '' === $code ) {
		$code = strtoupper( (string) $order->get_shipping_state() );
	}
	$states   = WC()->countries ? WC()->countries->get_states( 'RO' ) : array();
	$settings = get_option( 'masterspa_settings', array() );
	$map      = isset( $settings['judet_map'] ) && is_array( $settings['judet_map'] ) ? $settings['judet_map'] : array();

	return array(
		'id'   => isset( $map[ $code ] ) && '' !== $map[ $code ] ? (int) $map[ $code ] : null,
		'cod'  => $code,
		'nume' => isset( $states[ $code ] ) ? html_entity_decode( $states[ $code ], ENT_QUOTES, 'UTF-8' ) : '',
	);
}
