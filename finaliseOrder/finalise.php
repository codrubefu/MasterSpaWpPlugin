<?php
// Send order data to webhook when order is completed (spa plugin)
add_action('woocommerce_order_status_processing', function($order_id) {

    if (!class_exists('WC_Order')) return;
    $order = wc_get_order($order_id);
    if (!$order) return;
    // Only proceed if any order item is a 'spa' product (tag or category)
    $has_spa = false;
    foreach ( $order->get_items() as $item ) {
        $product_id = $item->get_product_id();
        if ( $product_id && ( has_term( 'spa', 'product_tag', $product_id ) || has_term( 'spa', 'product_cat', $product_id ) ) ) {
            $has_spa = true;
            break;
        }
    }
   
    if ( ! $has_spa ) {
        return;
    }

    // Read webhook URL from plugin settings
    $settings = get_option( 'masterspa_settings', array() );
    $webhook_url = '';
    if ( is_array( $settings ) ) {
        if ( ! empty( $settings['order_completed_webhook_url'] ) ) {
            $webhook_url = $settings['order_completed_webhook_url'];
        } elseif ( ! empty( $settings['api_endpoint'] ) ) {
            // fallback to api_endpoint if user stored webhook there
            $webhook_url = $settings['api_endpoint'];
        }
    }
    if ( empty( $webhook_url ) ) return;

    // Collect order data
    $order_data = $order->get_data();
    $order_data['items'] = array();
    foreach ($order->get_items() as $item) {
        $item_data = $item->get_data();
        $product_id = $item_data['product_id'];
        $product_meta = array();
        if ($product_id) {
            $meta = get_post_meta($product_id);
            $product_meta = $meta;
        }
        $item_data['product_meta_input'] = $product_meta;
        $order_data['items'][] = $item_data;
    }
    $order_data['meta'] = array();
    foreach ($order->get_meta_data() as $meta) {
        $order_data['meta'][$meta->key] = $meta->value;
    }
    // Add custom meta if exists
    $custom_info = get_post_meta($order_id, '_order_info', true);
    if ($custom_info) {
        $decoded = json_decode($custom_info, true);
        $order_data['custom_info'] = $decoded ? $decoded : $custom_info;
    }
    // Use helper for cURL
    if (!class_exists('MasterSpaCurlHelper')) {
        $helper_path = dirname(__FILE__,2).'/includes/MasterSpaCurlHelper.php';
        if (file_exists($helper_path)) {
            require_once $helper_path;
        }
    }
    if (class_exists('MasterSpaCurlHelper')) {
        $result = MasterSpaCurlHelper::post_json($webhook_url, $order_data);
        if (is_wp_error($result)) {
            $err = $result->get_error_message();
            if ( class_exists('MasterSpa_Logger') ) {
                MasterSpa_Logger::log( 'error', 'Webhook post failed: ' . $err );
            }
            return;
        }
        $code = isset($result['code']) ? $result['code'] : 0;
        if ( $code >= 200 && $code < 300 ) {
            if ( class_exists('MasterSpa_Logger') ) {
                MasterSpa_Logger::log( 'info', 'Webhook posted successfully for order ' . $order_id );
            }
        } else {
            $body = isset($result['body']) ? $result['body'] : '';
            if ( class_exists('MasterSpa_Logger') ) {
                MasterSpa_Logger::log( 'error', 'Webhook returned ' . $code . ' body: ' . substr( $body, 0, 1000 ) );
            }
        }
    }

});
