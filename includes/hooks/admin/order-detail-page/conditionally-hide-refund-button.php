<?php
/**
 * Hide the refund button on Cash on Delivery (COD) orders that are in 'processing' status.
 */
add_action('admin_head', function () {
    // 1. Let WooCommerce handle context detection automatically
    $the_order = false;

    // Check if we are viewing a single order screen in HPOS or legacy CPT
    if (function_exists('wc_get_order_id_by_order_key') || isset($_GET['id']) || isset($_GET['post'])) {
        // Try getting the order object directly from current query/screen context
        global $post;

        if (isset($_GET['id'])) {
            $the_order = wc_get_order(intval($_GET['id']));
        } elseif (isset($_GET['post'])) {
            $the_order = wc_get_order(intval($_GET['post']));
        } elseif ($post && in_array($post->post_type, ['shop_order', 'woocommerce_page_wc-orders'], true)) {
            $the_order = wc_get_order($post->ID);
        }
    }

    // Fallback: Check if wc_get_container() is available (HPOS environment) and query current request page
    if (!$the_order instanceof WC_Order && isset($_GET['page']) && $_GET['page'] === 'wc-orders' && isset($_GET['action']) && $_GET['action'] === 'edit') {
        if (isset($_GET['id'])) {
            $the_order = wc_get_order(intval($_GET['id']));
        }
    }

    if (!$the_order instanceof WC_Order) {
        return;
    }

    error_log("Made it here 2 - Successfully loaded order ID: " . $the_order->get_id());

    // Check if payment method is COD and order status is processing
    $is_cod = (trim(strtolower($the_order->get_payment_method())) === 'cod');
    $is_processing = ($the_order->get_status() === 'processing');

    if ($is_cod && $is_processing) {
        echo '<style>
            .order_data_column .refund-actions, 
            button.refund-items, 
            .wc-order-refund-items {
                display: none !important;
            }
        </style>';
    }
});