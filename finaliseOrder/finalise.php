<?php
/**
 * Send spa orders to the MasterSpa server when they reach "processing".
 *
 * The request runs in the background (Action Scheduler), so checkout never waits for the server.
 * Every request carries an "Idempotency-Key" header (the order key) so the server can ignore duplicates.
 *
 * If the server cannot be reached (or answers with a non-2xx status):
 * - the order is put "on-hold" (În așteptare) with an order note
 * - the customer gets the on-hold email and sees a message on the order-received page
 * - an email is sent to the support address (default support@masterspa.ro)
 * - the sync is retried with backoff (5, 15, 30 min, then hourly for ~24h); on success the
 *   order goes back to "processing"; when the retries run out support gets a final email
 *
 * @package MasterSpaWpPlugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const MASTERSPA_SYNC_META      = '_masterspa_sync_status'; // 'pending' | 'synced' | 'failed'
const MASTERSPA_SYNC_ATTEMPTS  = '_masterspa_sync_attempts';
const MASTERSPA_SYNC_ERROR     = '_masterspa_sync_error';
const MASTERSPA_SYNC_ACTION    = 'masterspa_sync_order'; // Action Scheduler hook, args: array( $order_id ).
const MASTERSPA_SYNC_MAX_TRIES = 27; // 1 attempt + 26 retries: 5, 15, 30 min, then 23 x 1h (~24h).

/**
 * Delay before the next retry, after $attempts failed attempts.
 *
 * @param int $attempts Failed attempts so far (>= 1).
 * @return int|null Seconds, or null when no retry is left.
 */
function masterspa_sync_retry_delay( $attempts ) {
	$attempts = (int) $attempts;
	if ( $attempts < 1 || $attempts >= MASTERSPA_SYNC_MAX_TRIES ) {
		return null;
	}
	$first_minutes = array( 5, 15, 30 );
	$minutes       = isset( $first_minutes[ $attempts - 1 ] ) ? $first_minutes[ $attempts - 1 ] : 60;
	return $minutes * 60;
}

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
	// No fallback to api_endpoint: that is the product feed, orders must never be posted there.
	return ! empty( $settings['order_completed_webhook_url'] ) ? (string) $settings['order_completed_webhook_url'] : '';
}

/**
 * Whether an order meta key is sent to the server.
 * Billing/shipping fields (including custom ones such as _billing_cui) and the order info are sent;
 * internal data (payment gateway, attribution, sync status, debug) is not.
 *
 * @param string $key Meta key.
 * @return bool
 */
function masterspa_order_meta_key_allowed( $key ) {
	$key = (string) $key;
	$allowed = false !== strpos( $key, '_billing_' ) || false !== strpos( $key, '_shipping_' ) || in_array( $key, array( '_order_info', 'is_vat_exempt' ), true );
	return (bool) apply_filters( 'masterspa_order_meta_key_allowed', $allowed, $key );
}

/**
 * Product meta sent with each item (product_meta_input), same shape as get_post_meta( $id ).
 *
 * @return string[]
 */
function masterspa_order_product_meta_keys() {
	return (array) apply_filters(
		'masterspa_order_product_meta_keys',
		array( '_sku', '_masterspa_art', '_masterspa_pretdesc', '_regular_price', '_sale_price', '_price', '_masterspa_web_sale' )
	);
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
		$meta_input = array();
		if ( $product_id ) {
			foreach ( masterspa_order_product_meta_keys() as $key ) {
				if ( metadata_exists( 'post', $product_id, $key ) ) {
					$meta_input[ $key ] = get_post_meta( $product_id, $key, false );
				}
			}
		}
		$item_data['product_meta_input'] = $meta_input;
		$order_data['items'][]           = $item_data;
	}

	// Order meta: only billing/shipping/order info (see masterspa_order_meta_key_allowed()).
	$order_data['meta_data'] = array();
	$order_data['meta']      = array();
	foreach ( $order->get_meta_data() as $meta ) {
		if ( ! masterspa_order_meta_key_allowed( $meta->key ) ) {
			continue;
		}
		$order_data['meta_data'][]        = array(
			'id'    => $meta->id,
			'key'   => $meta->key,
			'value' => $meta->value,
		);
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
	return apply_filters( 'masterspa_order_payload', $order_data, $order );
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

	$result = MasterSpaCurlHelper::post_json(
		$webhook_url,
		masterspa_build_order_payload( $order ),
		array( 'Idempotency-Key' => $order->get_order_key() )
	);
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
 * @param bool     $final   True when the automatic retries ran out.
 */
function masterspa_notify_support_sync_failed( $order, $message, $final = false ) {
	$settings = get_option( 'masterspa_settings', array() );
	$to       = ! empty( $settings['support_email'] ) ? $settings['support_email'] : 'support@masterspa.ro';
	$site     = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

	if ( $final ) {
		$subject = sprintf( '[%s] URGENT: comanda #%s nu a putut fi trimisă către server după %d încercări', $site, $order->get_order_number(), MASTERSPA_SYNC_MAX_TRIES );
		$intro   = array(
			'Comanda #' . $order->get_order_number() . ' NU a putut fi înregistrată pe serverul MasterSpa, iar reîncercarea automată s-a oprit.',
			'Comanda a rămas în așteptare (on-hold). Retrimiteți-o manual din pagina comenzii (Acțiuni comandă > MasterSpa: retrimite comanda către server) după ce serverul funcționează.',
		);
	} else {
		$subject = sprintf( '[%s] Comanda #%s nu a putut fi trimisă către server', $site, $order->get_order_number() );
		$intro   = array(
			'Comanda #' . $order->get_order_number() . ' nu a putut fi înregistrată pe serverul MasterSpa.',
			'Comanda a fost pusă în așteptare (on-hold). Site-ul reîncearcă automat trimiterea (după 5, 15, 30 de minute, apoi din oră în oră, timp de ~24 de ore).',
		);
	}

	$body = implode(
		"\n",
		array_merge(
			$intro,
			array(
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
		)
	);
	wp_mail( $to, $subject, $body );
}

/**
 * Whether a sync job for the order is waiting in the queue (not counting the one running now).
 *
 * @param int $order_id Order ID.
 * @return bool
 */
function masterspa_sync_has_pending( $order_id ) {
	if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
		return false;
	}
	$ids = as_get_scheduled_actions(
		array(
			'hook'     => MASTERSPA_SYNC_ACTION,
			'args'     => array( (int) $order_id ),
			'group'    => MASTERSPA_AS_GROUP,
			'status'   => ActionScheduler_Store::STATUS_PENDING,
			'per_page' => 1,
		),
		'ids'
	);
	return ! empty( $ids );
}

/**
 * Queue a sync job for the order.
 *
 * @param int $order_id Order ID.
 * @param int $delay    Seconds from now (0 = as soon as possible).
 * @return bool True when a job was queued (or one is already waiting).
 */
function masterspa_queue_order_sync( $order_id, $delay = 0 ) {
	$order_id = (int) $order_id;
	if ( masterspa_sync_has_pending( $order_id ) ) {
		return true;
	}
	if ( ! function_exists( 'as_enqueue_async_action' ) ) {
		return false;
	}
	if ( $delay > 0 ) {
		as_schedule_single_action( time() + $delay, MASTERSPA_SYNC_ACTION, array( $order_id ), MASTERSPA_AS_GROUP );
	} else {
		as_enqueue_async_action( MASTERSPA_SYNC_ACTION, array( $order_id ), MASTERSPA_AS_GROUP );
	}
	return true;
}

/**
 * Main hook: order reached "processing". Queues the sync; the request runs in the background.
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

	if ( ! masterspa_queue_order_sync( $order_id ) ) {
		// Action Scheduler missing (should not happen with WooCommerce): send right away.
		masterspa_sync_order_job( $order_id );
		return;
	}

	if ( 'failed' !== $order->get_meta( MASTERSPA_SYNC_META ) ) {
		$order->update_meta_data( MASTERSPA_SYNC_META, 'pending' );
		$order->save();
	}
}
add_action( 'woocommerce_order_status_processing', 'masterspa_on_order_processing', 5 );

/**
 * Background job: send the order and handle the result.
 *
 * @param int $order_id Order ID.
 * @return true|WP_Error
 */
function masterspa_sync_order_job( $order_id ) {
	$order = wc_get_order( $order_id );
	if ( ! $order ) {
		return new WP_Error( 'masterspa_no_order', 'Comanda nu există.' );
	}
	if ( 'synced' === $order->get_meta( MASTERSPA_SYNC_META ) ) {
		return true;
	}

	$was_failed = 'failed' === $order->get_meta( MASTERSPA_SYNC_META );
	$result     = masterspa_send_order_to_server( $order );

	if ( true === $result ) {
		$order->update_meta_data( MASTERSPA_SYNC_META, 'synced' );
		$order->delete_meta_data( MASTERSPA_SYNC_ERROR );
		$order->save();
		// Drop retries still waiting (e.g. after a manual resend).
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( MASTERSPA_SYNC_ACTION, array( (int) $order_id ), MASTERSPA_AS_GROUP );
		}
		if ( $was_failed && $order->has_status( 'on-hold' ) ) {
			$order->update_status( 'processing', 'Comanda a fost trimisă cu succes către serverul MasterSpa (reîncercare).' );
		}
		MasterSpa_Logger::log( 'info', ( $was_failed ? 'Webhook retry succeeded for order ' : 'Webhook posted successfully for order ' ) . $order_id );
		return true;
	}

	$message  = $result->get_error_message();
	$attempts = (int) $order->get_meta( MASTERSPA_SYNC_ATTEMPTS ) + 1;
	MasterSpa_Logger::log( 'error', 'Webhook post failed for order ' . $order_id . ' (attempt ' . $attempts . '): ' . $message );

	$order->update_meta_data( MASTERSPA_SYNC_META, 'failed' );
	$order->update_meta_data( MASTERSPA_SYNC_ATTEMPTS, $attempts );
	$order->update_meta_data( MASTERSPA_SYNC_ERROR, $message );
	$order->save();

	if ( ! $was_failed ) {
		// First failure: put the order on hold and tell the customer and support.
		// Only orders still in processing are held (do not touch orders an admin already completed).
		if ( $order->has_status( 'processing' ) ) {
			$order->update_status( 'on-hold', 'Comanda nu a putut fi trimisă către serverul MasterSpa (' . $message . '). Se reîncearcă automat.' );
		}
		$mailer = WC()->mailer();
		$emails = $mailer ? $mailer->get_emails() : array();
		if ( isset( $emails['WC_Email_Customer_On_Hold_Order'] ) ) {
			$emails['WC_Email_Customer_On_Hold_Order']->trigger( $order->get_id(), $order );
		}
		masterspa_notify_support_sync_failed( $order, $message );
	}

	$delay = masterspa_sync_retry_delay( $attempts );
	if ( null !== $delay ) {
		masterspa_queue_order_sync( $order_id, $delay );
	} else {
		$order->add_order_note( 'Trimiterea către serverul MasterSpa a eșuat după ' . $attempts . ' încercări. Reîncercarea automată a fost oprită.' );
		masterspa_notify_support_sync_failed( $order, $message, true );
	}

	return $result;
}
add_action( MASTERSPA_SYNC_ACTION, 'masterspa_sync_order_job' );

/**
 * Re-queue orders that wait for a sync and have no job queued
 * (upgrade from the WP-Cron retry, or plugin re-activation):
 * - on-hold orders whose sync failed (what the old WP-Cron retry picked up)
 * - processing orders whose job was queued but never ran
 * Processing orders marked "failed" are left alone: the old retry never touched them.
 */
function masterspa_requeue_failed_orders() {
	if ( ! function_exists( 'wc_get_orders' ) ) {
		return;
	}
	$groups = array(
		array( 'on-hold', 'failed' ),
		array( 'processing', 'pending' ),
	);
	$i = 0;
	foreach ( $groups as $group ) {
		$orders = wc_get_orders(
			array(
				'limit'      => -1,
				'status'     => array( $group[0] ),
				// meta_key/meta_value: the posts order store ignores meta_query in wc_get_orders().
				'meta_key'   => MASTERSPA_SYNC_META, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value' => $group[1], // phpcs:ignore WordPress.DB.SlowDBQuery
				'return'     => 'ids',
			)
		);
		foreach ( $orders as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order || (int) $order->get_meta( MASTERSPA_SYNC_ATTEMPTS ) >= MASTERSPA_SYNC_MAX_TRIES ) {
				continue;
			}
			// Spread the requests a little.
			masterspa_queue_order_sync( $order_id, 30 * $i++ );
		}
	}
}

// Do not send the "comanda este în procesare" email for orders that failed to sync
// (applies when an order is moved back to processing while its sync is still failing).
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
		if ( ! $order instanceof WC_Order ) {
			return $text;
		}
		$status = $order->get_meta( MASTERSPA_SYNC_META );
		if ( 'failed' === $status ) {
			return esc_html__( 'Vă mulțumim! Comanda dumneavoastră a fost primită și este în așteptare. Momentan nu am putut finaliza înregistrarea ei în sistemul nostru; echipa MasterSpa a fost notificată și vă va contacta în cel mai scurt timp.', 'masterspa-wp-plugin' );
		}
		if ( 'pending' === $status ) {
			return esc_html__( 'Vă mulțumim! Comanda dumneavoastră a fost primită și este în curs de înregistrare în sistemul nostru. Veți primi confirmarea pe email.', 'masterspa-wp-plugin' );
		}
		return $text;
	},
	20,
	2
);
