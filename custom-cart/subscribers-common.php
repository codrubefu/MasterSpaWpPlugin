<?php
/**
 * Subscriber (beneficiar) helpers shared by the classic cart, the Cart block and the validation.
 *
 * Subscribers live on the cart item: $cart_item['subscription_user'] = list of rows, one per quantity.
 *
 * @package MasterSpaWpPlugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
 * Sanitize a subscriber row (already unslashed).
 *
 * @param array $row Raw row.
 * @return array
 */
function masterspa_sanitize_subscriber( $row ) {
	$row = is_array( $row ) ? $row : array();
	return array(
		'prenume'    => sanitize_text_field( isset( $row['prenume'] ) ? (string) $row['prenume'] : '' ),
		'nume'       => sanitize_text_field( isset( $row['nume'] ) ? (string) $row['nume'] : '' ),
		'email'      => sanitize_email( isset( $row['email'] ) ? (string) $row['email'] : '' ),
		'phone'      => sanitize_text_field( isset( $row['phone'] ) ? (string) $row['phone'] : '' ),
		'send_email' => ! empty( $row['send_email'] ) ? '1' : '',
	);
}

/**
 * Whether a raw (posted) email is filled in but not valid.
 *
 * @param array $row Raw row.
 * @return bool
 */
function masterspa_subscriber_email_invalid( $row ) {
	$raw = is_array( $row ) && isset( $row['email'] ) ? trim( (string) $row['email'] ) : '';
	return '' !== $raw && ! is_email( sanitize_email( $raw ) );
}

/**
 * Return the saved subscribers of a cart item, padded/truncated to its quantity.
 *
 * @param array $cart_item Cart item.
 * @return array
 */
function masterspa_get_cart_item_subscribers( $cart_item ) {
	$qty   = max( 1, (int) ( isset( $cart_item['quantity'] ) ? $cart_item['quantity'] : 1 ) );
	$saved = isset( $cart_item['subscription_user'] ) && is_array( $cart_item['subscription_user'] ) ? array_values( $cart_item['subscription_user'] ) : array();
	$list  = array();

	for ( $i = 0; $i < $qty; $i++ ) {
		$row    = isset( $saved[ $i ] ) && is_array( $saved[ $i ] ) ? $saved[ $i ] : array();
		$list[] = array(
			'prenume'    => (string) ( isset( $row['prenume'] ) ? $row['prenume'] : '' ),
			'nume'       => (string) ( isset( $row['nume'] ) ? $row['nume'] : '' ),
			'email'      => (string) ( isset( $row['email'] ) ? $row['email'] : '' ),
			'phone'      => (string) ( isset( $row['phone'] ) ? $row['phone'] : '' ),
			'send_email' => ! empty( $row['send_email'] ) ? '1' : '',
		);
	}

	return $list;
}

/**
 * Missing required fields of a subscriber row (prenume, nume, valid email).
 *
 * @param array $row Subscriber row.
 * @return string[] Human-readable field names.
 */
function masterspa_subscriber_missing_fields( $row ) {
	$missing = array();
	if ( '' === trim( (string) ( isset( $row['prenume'] ) ? $row['prenume'] : '' ) ) ) {
		$missing[] = __( 'prenume', 'masterspa-wp-plugin' );
	}
	if ( '' === trim( (string) ( isset( $row['nume'] ) ? $row['nume'] : '' ) ) ) {
		$missing[] = __( 'nume', 'masterspa-wp-plugin' );
	}
	if ( ! is_email( (string) ( isset( $row['email'] ) ? $row['email'] : '' ) ) ) {
		$missing[] = __( 'email valid', 'masterspa-wp-plugin' );
	}
	return $missing;
}

/**
 * Validation errors for the subscribers of the spa products in the cart.
 *
 * @param array $cart_contents Cart items (WC()->cart->get_cart()).
 * @return string[] Error messages.
 */
function masterspa_cart_subscriber_errors( $cart_contents ) {
	$errors = array();
	foreach ( (array) $cart_contents as $cart_item ) {
		$product_id = isset( $cart_item['product_id'] ) ? (int) $cart_item['product_id'] : 0;
		if ( ! $product_id || ! masterspa_is_spa_product( $product_id ) ) {
			continue;
		}
		$name = isset( $cart_item['data'] ) && is_object( $cart_item['data'] ) && method_exists( $cart_item['data'], 'get_name' )
			? $cart_item['data']->get_name()
			: get_the_title( $product_id );
		$list = masterspa_get_cart_item_subscribers( $cart_item );
		$qty  = count( $list );

		foreach ( $list as $i => $row ) {
			$missing = masterspa_subscriber_missing_fields( $row );
			if ( empty( $missing ) ) {
				continue;
			}
			$errors[] = sprintf(
				/* translators: 1: index, 2: quantity, 3: product name, 4: missing fields */
				__( 'Beneficiarul %1$d/%2$d pentru „%3$s”: completați %4$s.', 'masterspa-wp-plugin' ),
				$i + 1,
				$qty,
				wp_strip_all_tags( $name ),
				implode( ', ', $missing )
			);
		}
	}
	return $errors;
}
