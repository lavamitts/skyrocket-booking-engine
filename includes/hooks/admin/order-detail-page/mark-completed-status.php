<?php
/**
 * Add custom order actions to the WooCommerce Order Actions dropdown based on item types.
 */
add_filter('woocommerce_order_actions', function ($actions, $order) {
    if (!$order instanceof WC_Order) {
        return $actions;
    }

    $payment_method = trim(strtolower($order->get_payment_method()));
    $is_cod = ($payment_method === 'cod');
    $is_completed = ($order->get_status() === 'completed');

    if ($is_cod) {
        // COD Action: Show if total > 0 and payment has not been stamped yet
        if ($order->get_total() > 0 && !$order->get_date_paid()) {
            $actions['bbe_mark_as_paid'] = __('Mark as Paid', 'bbe');
        }
    } else {
        // Non-COD Actions (Prepaid): Only show if order isn't already completed
        if (!$is_completed) {
            $item_types = bbe_get_order_item_types($order);

            if (!$item_types['has_service'] && $item_types['has_physical']) {
                // 1. Physical products only
                $actions['bbe_complete_appointment'] = __('Mark as Fulfilled', 'bbe');
            } elseif ($item_types['has_service'] && $item_types['has_physical']) {
                // 2. Mixed: Physical products + Services
                $actions['bbe_complete_appointment'] = __('Mark as Fulfilled', 'bbe');
            } elseif ($item_types['has_service'] && !$item_types['has_physical']) {
                // 3. Service products only
                $actions['bbe_complete_appointment'] = __('Complete Appointment', 'bbe');
            }
        }
    }

    return $actions;
}, 10, 2);

/**
 * Process the custom completion/fulfillment action for Non-COD orders.
 */
add_action('woocommerce_order_action_bbe_complete_appointment', function ($order) {
    if (!$order instanceof WC_Order) {
        return;
    }

    // Determine context for the order note log
    $item_types = bbe_get_order_item_types($order);
    $note_text = ($item_types['has_service'] && !$item_types['has_physical'])
        ? __('Appointment completed via admin order action.', 'bbe')
        : __('Order items fulfilled via admin order action.', 'bbe');

    // Transition status to completed, triggering all fulfillment/email hooks
    $order->update_status('completed', $note_text);
});