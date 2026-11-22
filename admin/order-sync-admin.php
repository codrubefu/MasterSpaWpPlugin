<?php
/**
 * Admin UI for the order sync with the MasterSpa server:
 * - "MasterSpa" column in the orders list (HPOS and legacy) with the sync status
 * - ?masterspa_sync=failed|pending|synced filter on the orders list
 * - order action "MasterSpa: retrimite comanda către server"
 *
 * @package MasterSpaWpPlugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Badge HTML for an order's sync status.
 *
 * @param WC_Order $order Order.
 * @return string
 */
function masterspa_sync_badge_html( $order ) {
	$status = (string) $order->get_meta( MASTERSPA_SYNC_META );
	if ( '' === $status ) {
		return '<span aria-hidden="true">–</span>';
	}
	$labels = array(
		'synced'  => array( __( 'Sincronizat', 'masterspa-wp-plugin' ), '#c6e1c6', '#2c4700' ),
		'pending' => array( __( 'În așteptare', 'masterspa-wp-plugin' ), '#e5e5e5', '#454545' ),
		'failed'  => array( __( 'Eșuat', 'masterspa-wp-plugin' ), '#f8dda7', '#94660c' ),
	);
	if ( ! isset( $labels[ $status ] ) ) {
		return esc_html( $status );
	}
	list( $label, $bg, $fg ) = $labels[ $status ];
	$title                   = '';
	if ( 'failed' === $status ) {
		$attempts = (int) $order->get_meta( MASTERSPA_SYNC_ATTEMPTS );
		$label   .= ' (' . $attempts . ')';
		$title    = (string) $order->get_meta( MASTERSPA_SYNC_ERROR );
		if ( $attempts >= MASTERSPA_SYNC_MAX_TRIES ) {
			$bg = '#eba3a3';
			$fg = '#761919';
		}
	}
	return sprintf(
		'<mark class="order-status" style="background:%1$s;color:%2$s;" title="%3$s"><span>%4$s</span></mark>',
		esc_attr( $bg ),
		esc_attr( $fg ),
		esc_attr( $title ),
		esc_html( $label )
	);
}

/**
 * Add the column after "Status".
 *
 * @param array $columns Columns.
 * @return array
 */
function masterspa_orders_add_sync_column( $columns ) {
	$out = array();
	foreach ( $columns as $key => $label ) {
		$out[ $key ] = $label;
		if ( 'order_status' === $key ) {
			$out['masterspa_sync'] = __( 'MasterSpa', 'masterspa-wp-plugin' );
		}
	}
	if ( ! isset( $out['masterspa_sync'] ) ) {
		$out['masterspa_sync'] = __( 'MasterSpa', 'masterspa-wp-plugin' );
	}
	return $out;
}
add_filter( 'manage_woocommerce_page_wc-orders_columns', 'masterspa_orders_add_sync_column' );
add_filter( 'manage_edit-shop_order_columns', 'masterspa_orders_add_sync_column' );

// HPOS list.
add_action(
	'manage_woocommerce_page_wc-orders_custom_column',
	function ( $column, $order ) {
		if ( 'masterspa_sync' === $column && $order instanceof WC_Order ) {
			echo wp_kses_post( masterspa_sync_badge_html( $order ) );
		}
	},
	10,
	2
);

// Legacy (posts) list.
add_action(
	'manage_shop_order_posts_custom_column',
	function ( $column, $post_id ) {
		if ( 'masterspa_sync' !== $column ) {
			return;
		}
		$order = wc_get_order( $post_id );
		if ( $order ) {
			echo wp_kses_post( masterspa_sync_badge_html( $order ) );
		}
	},
	10,
	2
);

/**
 * Requested sync-status filter from the URL.
 *
 * @return string
 */
function masterspa_orders_sync_filter() {
	$value = isset( $_GET['masterspa_sync'] ) ? sanitize_key( wp_unslash( $_GET['masterspa_sync'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	return in_array( $value, array( 'failed', 'pending', 'synced' ), true ) ? $value : '';
}

// HPOS list filter.
add_filter(
	'woocommerce_order_list_table_prepare_items_query_args',
	function ( $args ) {
		$filter = masterspa_orders_sync_filter();
		if ( '' !== $filter ) {
			$args['meta_query']   = isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) ? $args['meta_query'] : array(); // phpcs:ignore WordPress.DB.SlowDBQuery
			$args['meta_query'][] = array(
				'key'   => MASTERSPA_SYNC_META,
				'value' => $filter,
			);
		}
		return $args;
	}
);

// Legacy list filter.
add_action(
	'pre_get_posts',
	function ( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() || 'shop_order' !== $query->get( 'post_type' ) ) {
			return;
		}
		$filter = masterspa_orders_sync_filter();
		if ( '' === $filter ) {
			return;
		}
		$meta_query   = (array) $query->get( 'meta_query' );
		$meta_query[] = array(
			'key'   => MASTERSPA_SYNC_META,
			'value' => $filter,
		);
		$query->set( 'meta_query', $meta_query );
	}
);

/**
 * URL of the orders list filtered by sync status (HPOS or legacy).
 *
 * @param string $status failed|pending|synced.
 * @return string
 */
function masterspa_orders_list_url( $status ) {
	$hpos = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	$base = $hpos ? admin_url( 'admin.php?page=wc-orders' ) : admin_url( 'edit.php?post_type=shop_order' );
	return add_query_arg( 'masterspa_sync', $status, $base );
}

// Order action: resend now.
add_filter(
	'woocommerce_order_actions',
	function ( $actions, $order = null ) {
		if ( $order instanceof WC_Order && 'synced' !== $order->get_meta( MASTERSPA_SYNC_META ) && masterspa_order_has_spa( $order ) ) {
			$actions['masterspa_resync'] = __( 'MasterSpa: retrimite comanda către server', 'masterspa-wp-plugin' );
		}
		return $actions;
	},
	10,
	2
);

add_action(
	'woocommerce_order_action_masterspa_resync',
	function ( $order ) {
		if ( ! $order instanceof WC_Order || ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}
		$order_id = $order->get_id();

		// Manual resend restarts the retry schedule.
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( MASTERSPA_SYNC_ACTION, array( $order_id ), MASTERSPA_AS_GROUP );
		}
		$fresh = wc_get_order( $order_id );
		if ( $fresh ) {
			$fresh->update_meta_data( MASTERSPA_SYNC_ATTEMPTS, 0 );
			$fresh->save();
		}

		$result = masterspa_sync_order_job( $order_id );
		$fresh  = wc_get_order( $order_id );
		if ( ! $fresh ) {
			return;
		}
		if ( true === $result ) {
			$fresh->add_order_note( __( 'Comanda a fost retrimisă manual către serverul MasterSpa: succes.', 'masterspa-wp-plugin' ), false, true );
		} else {
			$fresh->add_order_note( __( 'Retrimiterea manuală către serverul MasterSpa a eșuat: ', 'masterspa-wp-plugin' ) . $result->get_error_message(), false, true );
		}
	}
);
