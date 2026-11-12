<?php
/**
 * 1. Add 'Mark as Paid' to the single order actions dropdown
 */
add_filter('woocommerce_order_actions', function ($actions, $order) {
    // Only show if the order isn't already paid/completed and is a COD or unpaid type
    if ($order->get_total() > 0 && !$order->get_date_paid()) {
        $actions['bbe_mark_as_paid'] = __('Mark as paid', 'bbe');
    }
    return $actions;
}, 10, 2);

/**
 * 2. Process the custom action when selected and saved
 */
/**
 * Process the custom 'Mark as Paid' action: 
 * Marks payment complete, sets status to completed, and triggers all order completion hooks.
 */
add_action('woocommerce_order_action_bbe_mark_as_paid', function ($order) {
    if (!$order instanceof WC_Order) {
        return;
    }

    // 1. Record payment timestamp and internal transaction flags
    $order->payment_complete();

    // 2. Transition the order status directly to 'completed'
    // This fires standard WooCommerce hooks (like woocommerce_order_status_completed) 
    // which email triggers and automated workflows listen for.
    $order->update_status('completed', __('Payment received in-clinic and appointment fulfilled.', 'bbe'));
});