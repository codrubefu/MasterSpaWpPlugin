<?php
/**
 * Send spa orders to the MasterSpa server when they reach "processing".
 *
 * If the server cannot be reached (or answers with a non-2xx status):
 * - the order is put "on-hold" (În așteptare) with an order note
 * - the customer sees a message on the order-received page and gets the on-hold email
 * - an email is sent to the support address (default support@masterspa.ro)
 * - a WP-Cron job retries the sync every 15 minutes; on success the order goes back to "processing"
 *
 * @package MasterSpaWpPlugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const MASTERSPA_SYNC_META      = '_masterspa_sync_status'; // 'synced' | 'failed'
const MASTERSPA_SYNC_ATTEMPTS  = '_masterspa_sync_attempts';
const MASTERSPA_SYNC_ERROR     = '_masterspa_sync_error';
const MASTERSPA_SYNC_CRON_HOOK = 'masterspa_retry_order_sync';
const MASTERSPA_SYNC_MAX_TRIES = 96; // ~24h at one retry every 15 minutes

/**
 * Whether the order contains at least one spa product.
 *
 * @param WC_Order $order Order.
 * @return bool
 */
function masterspa_order_has_spa( $order ) {
	foreach ( $order->get_items() as $item ) {
		$product_id = $item->get_product_id();
		if ( $product_id && ( has_term( 'spa', 'product_tag', $product_id ) || has_term( 'spa', 'product_cat', $product_id ) ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Webhook URL from settings.
 *
 * @return string
 */
function masterspa_get_order_webhook_url() {
	$settings = get_option( 'masterspa_settings', array() );
	if ( ! is_array( $settings ) ) {
		return '';
	}
	if ( ! empty( $settings['order_completed_webhook_url'] ) ) {
		return $settings['order_completed_webhook_url'];
	}
	// Fallback to api_endpoint if the webhook was stored there.
	return ! empty( $settings['api_endpoint'] ) ? $settings['api_endpoint'] : '';
}

/**
 * Build the JSON payload sent to the server.
 *
 * @param WC_Order $order Order.
 * @return array
 */
function masterspa_build_order_payload( $order ) {
	$order_data          = $order->get_data();
	$order_data['items'] = array();
	foreach ( $order->get_items() as $item ) {
		$item_data  = $item->get_data();
		$product_id = $item_data['product_id'];
		$item_data['product_meta_input'] = $product_id ? get_post_meta( $product_id ) : array();
		$order_data['items'][]           = $item_data;
	}
	$order_data['meta'] = array();
	foreach ( $order->get_meta_data() as $meta ) {
		$order_data['meta'][ $meta->key ] = $meta->value;
	}

	// County: WooCommerce code + name + ID from the `judet` table.
	if ( function_exists( 'masterspa_get_order_judet' ) ) {
		$judet                             = masterspa_get_order_judet( $order );
		$order_data['judet']               = $judet;
		$order_data['billing']['judet_id'] = $judet['id'];
	}

	$custom_info = $order->get_meta( '_order_info', true );
	if ( $custom_info ) {
		$decoded                   = json_decode( $custom_info, true );
		$order_data['custom_info'] = $decoded ? $decoded : $custom_info;
	}
	return $order_data;
}

/**
 * Post the order to the server.
 *
 * @param WC_Order $order Order.
 * @return true|WP_Error
 */
function masterspa_send_order_to_server( $order ) {
	$webhook_url = masterspa_get_order_webhook_url();
	if ( empty( $webhook_url ) ) {
		return new WP_Error( 'masterspa_no_webhook', 'Webhook URL nu este configurat.' );
	}

	if ( ! class_exists( 'MasterSpaCurlHelper' ) ) {
		require_once dirname( __FILE__, 2 ) . '/includes/MasterSpaCurlHelper.php';
	}

	$result = MasterSpaCurlHelper::post_json( $webhook_url, masterspa_build_order_payload( $order ) );
	$code   = isset( $result['http_code'] ) ? (int) $result['http_code'] : 0;

	if ( $code >= 200 && $code < 300 ) {
		return true;
	}

	if ( ! empty( $result['error'] ) ) {
		$message = 'Eroare conexiune: ' . $result['error'];
	} else {
		$body    = isset( $result['response'] ) ? (string) $result['response'] : '';
		$message = 'Serverul a răspuns cu HTTP ' . $code . ( '' !== $body ? ': ' . substr( wp_strip_all_tags( $body ), 0, 300 ) : '' );
	}
	return new WP_Error( 'masterspa_sync_failed', $message );
}

/**
 * Notify support that an order could not be sent to the server.
 *
 * @param WC_Order $order   Order.
 * @param string   $message Error message.
 */
function masterspa_notify_support_sync_failed( $order, $message ) {
	$settings = get_option( 'masterspa_settings', array() );
	$to       = ! empty( $settings['support_email'] ) ? $settings['support_email'] : 'support@masterspa.ro';

	$subject = sprintf( '[%s] Comanda #%s nu a putut fi trimisă către server', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), $order->get_order_number() );
	$body    = implode(
		"\n",
		array(
			'Comanda #' . $order->get_order_number() . ' nu a putut fi înregistrată pe serverul MasterSpa.',
			'Comanda a fost pusă în așteptare (on-hold). Site-ul reîncearcă automat trimiterea la fiecare 15 minute.',
			'',
			'Client: ' . trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			'Email: ' . $order->get_billing_email(),
			'Telefon: ' . $order->get_billing_phone(),
			'Total: ' . wp_strip_all_tags( html_entity_decode( $order->get_formatted_order_total(), ENT_QUOTES, 'UTF-8' ) ),
			'',
			'Eroare: ' . $message,
			'',
			'Detalii comandă: ' . $order->get_edit_order_url(),
		)
	);
	wp_mail( $to, $subject, $body );
}

/**
 * Main hook: order reached "processing".
 *
 * @param int $order_id Order ID.
 */
function masterspa_on_order_processing( $order_id ) {
	$order = wc_get_order( $order_id );
	if ( ! $order || ! masterspa_order_has_spa( $order ) ) {
		return;
	}
	// Already registered on the server (e.g. order returned to processing after a successful retry).
	if ( 'synced' === $order->get_meta( MASTERSPA_SYNC_META ) ) {
		return;
	}
	if ( empty( masterspa_get_order_webhook_url() ) ) {
		return;
	}

	$result = masterspa_send_order_to_server( $order );

	if ( true === $result ) {
		$order->update_meta_data( MASTERSPA_SYNC_META, 'synced' );
		$order->delete_meta_data( MASTERSPA_SYNC_ERROR );
		$order->save();
		if ( class_exists( 'MasterSpa_Logger' ) ) {
			MasterSpa_Logger::log( 'info', 'Webhook posted successfully for order ' . $order_id );
		}
		return;
	}

	$message = $result->get_error_message();
	if ( class_exists( 'MasterSpa_Logger' ) ) {
		MasterSpa_Logger::log( 'error', 'Webhook post failed for order ' . $order_id . ': ' . $message );
	}

	$order->update_meta_data( MASTERSPA_SYNC_META, 'failed' );
	$order->update_meta_data( MASTERSPA_SYNC_ATTEMPTS, 1 );
	$order->update_meta_data( MASTERSPA_SYNC_ERROR, $message );
	$order->save();

	$order->update_status( 'on-hold', 'Comanda nu a putut fi trimisă către serverul MasterSpa (' . $message . '). Se reîncearcă automat.' );

	// The "processing" email is suppressed (filter below); send the on-hold email instead.
	$mailer = WC()->mailer();
	$emails = $mailer ? $mailer->get_emails() : array();
	if ( isset( $emails['WC_Email_Customer_On_Hold_Order'] ) ) {
		$emails['WC_Email_Customer_On_Hold_Order']->trigger( $order->get_id(), $order );
	}

	masterspa_notify_support_sync_failed( $order, $message );
}
add_action( 'woocommerce_order_status_processing', 'masterspa_on_order_processing', 5 );

// Do not send the "comanda este în procesare" email for orders that failed to sync.
add_filter(
	'woocommerce_email_enabled_customer_processing_order',
	function ( $enabled, $order ) {
		if ( $order instanceof WC_Order && 'failed' === $order->get_meta( MASTERSPA_SYNC_META ) ) {
			return false;
		}
		return $enabled;
	},
	10,
	2
);

// Message on the order-received page (classic and block checkout).
add_filter(
	'woocommerce_thankyou_order_received_text',
	function ( $text, $order ) {
		if ( $order instanceof WC_Order && 'failed' === $order->get_meta( MASTERSPA_SYNC_META ) ) {
			return esc_html__( 'Vă mulțumim! Comanda dumneavoastră a fost primită și este în așteptare. Momentan nu am putut finaliza înregistrarea ei în sistemul nostru; echipa MasterSpa a fost notificată și vă va contacta în cel mai scurt timp.', 'masterspa-wp-plugin' );
		}
		return $text;
	},
	20,
	2
);

/*
 * Retry failed syncs with WP-Cron.
 */
add_filter(
	'cron_schedules',
	function ( $schedules ) {
		if ( ! isset( $schedules['masterspa_fifteen_minutes'] ) ) {
			$schedules['masterspa_fifteen_minutes'] = array(
				'interval' => 15 * MINUTE_IN_SECONDS,
				'display'  => 'MasterSpa – la 15 minute',
			);
		}
		return $schedules;
	}
);

add_action(
	'init',
	function () {
		if ( ! wp_next_scheduled( MASTERSPA_SYNC_CRON_HOOK ) ) {
			wp_schedule_event( time() + 15 * MINUTE_IN_SECONDS, 'masterspa_fifteen_minutes', MASTERSPA_SYNC_CRON_HOOK );
		}
	}
);

/**
 * Retry sending orders that failed.
 */
function masterspa_retry_failed_order_syncs() {
	$orders = wc_get_orders(
		array(
			'limit'      => 20,
			'status'     => array( 'on-hold' ),
			'meta_key'   => MASTERSPA_SYNC_META, // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_value' => 'failed', // phpcs:ignore WordPress.DB.SlowDBQuery
		)
	);

	foreach ( $orders as $order ) {
		$attempts = (int) $order->get_meta( MASTERSPA_SYNC_ATTEMPTS );
		if ( $attempts >= MASTERSPA_SYNC_MAX_TRIES ) {
			continue;
		}

		$result = masterspa_send_order_to_server( $order );
		if ( true === $result ) {
			$order->update_meta_data( MASTERSPA_SYNC_META, 'synced' );
			$order->delete_meta_data( MASTERSPA_SYNC_ERROR );
			$order->save();
			$order->update_status( 'processing', 'Comanda a fost trimisă cu succes către serverul MasterSpa (reîncercare).' );
			if ( class_exists( 'MasterSpa_Logger' ) ) {
				MasterSpa_Logger::log( 'info', 'Webhook retry succeeded for order ' . $order->get_id() );
			}
			continue;
		}

		$attempts++;
		$order->update_meta_data( MASTERSPA_SYNC_ATTEMPTS, $attempts );
		$order->update_meta_data( MASTERSPA_SYNC_ERROR, $result->get_error_message() );
		if ( $attempts >= MASTERSPA_SYNC_MAX_TRIES ) {
			$order->add_order_note( 'Trimiterea către serverul MasterSpa a eșuat după ' . $attempts . ' încercări. Reîncercarea automată a fost oprită.' );
		}
		$order->save();
	}
}
add_action( MASTERSPA_SYNC_CRON_HOOK, 'masterspa_retry_failed_order_syncs' );
