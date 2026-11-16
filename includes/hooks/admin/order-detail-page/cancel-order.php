<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Add "Cancel order" action to the WooCommerce Order Actions dropdown
 * exclusively when the order is currently in 'processing' status.
 */
add_filter('woocommerce_order_actions', function ($actions, $order) {
    if (!$order instanceof WC_Order) {
        return $actions;
    }

    if ($order->get_status() === 'processing') {
        $actions['bbe_cancel_order'] = __('Cancel order', 'bbe');
    }

    return $actions;
}, 10, 2);

/**
 * Process the custom "Cancel order" action.
 */
add_action('woocommerce_order_action_bbe_cancel_order', function ($order) {
    if (!$order instanceof WC_Order) {
        return;
    }

    // Double-check status before transitioning
    if ($order->get_status() === 'processing') {
        $order->update_status('cancelled', __('Order cancelled via admin order action.', 'bbe'));
    }
});