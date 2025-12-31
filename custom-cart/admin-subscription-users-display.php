<?php
/**
 * Afiseaza informatiile abonatilor sub fiecare produs in admin order
 */

add_action('woocommerce_admin_order_item_values', function($product, $item, $item_id){
    // $item is WC_Order_Item_Product
    $meta = $item->get_meta_data();
    $abonati = [];
    foreach ($meta as $m) {
        if (strpos($m->key, 'Abonat - ') === 0) {
            $abonati[] = $m->key . ': ' . esc_html($m->value);
        }
    }
    if ($abonati) {
        echo '<div style="margin:8px 0 0 0; padding:8px; background:#f8f8f8; border-radius:4px; font-size:13px; color:#333;">';
        echo '<strong>Abonat:</strong><br>';
        foreach ($abonati as $a) {
            echo $a . '<br>';
        }
        echo '</div>';
    }
}, 10, 3);
