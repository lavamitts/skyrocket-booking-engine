<?php
/**
 * Customize the Store Owner 'New Order' email based on payment method and item composition.
 */
add_action('woocommerce_email_before_order_table', function ($order, $sent_to_admin, $plain_text, $email) {
    // Only target the Admin 'New Order' notification email
    if (!$sent_to_admin || $email->id !== 'new_order') {
        return;
    }

    $payment_method = trim(strtolower($order->get_payment_method()));
    $is_cod = ($payment_method === 'cod');
    $item_types = bbe_get_order_item_types($order); // Reuses your existing item inspection helper

    $has_service = $item_types['has_service'];
    $has_physical = $item_types['has_physical'];

    // Extract booking details if a service is present in the order
    $booking_details = [];
    if ($has_service) {
        foreach ($order->get_items() as $item) {
            $start_dt = wc_get_order_item_meta($item->get_id(), '_bbe_booking_start', true);
            $location_id = wc_get_order_item_meta($item->get_id(), '_bbe_location_id', true);

            if ($start_dt && $location_id) {
                $location_name = get_the_title($location_id);
                $formatted_date = date('j M Y', strtotime($start_dt));
                $formatted_time = date('H:i', strtotime($start_dt));

                $booking_details[] = sprintf(
                    '<li><strong>%s</strong> &mdash; Date: %s at %s | Location: %s</li>',
                    esc_html($item->get_name()),
                    $formatted_date,
                    $formatted_time,
                    esc_html($location_name)
                );
            }
        }
    }

    // -------------------------------------------------------------
    // USE CASE 1: Product Only (Paid in Advance)
    // -------------------------------------------------------------
    if (!$is_cod && $has_physical && !$has_service) {
        // Leave completely default; return early or output nothing extra.
        return;
    }

    // Container styling for admin notification notices
    echo '<div style="margin-bottom: 20px; padding: 15px; background-color: #fdfefe; border: 1px solid #ccd0d7; border-left: 4px solid #007cba;">';
    echo '<h3 style="margin-top: 0; font-size: 14px; color: #1d2327;">' . __('Booking & Fulfillment Notice', 'bbe') . '</h3>';

    // -------------------------------------------------------------
    // USE CASE 2: Product and Service (Paid in Advance)
    // -------------------------------------------------------------
    if (!$is_cod && $has_physical && $has_service) {
        echo '<p><strong>[Prepaid Order - Mixed Products & Clinic Booking]</strong></p>';
        echo '<p>This order contains physical shipping items alongside the following on-site clinic appointment:</p>';
        if (!empty($booking_details)) {
            echo '<ul style="margin: 0 0 10px 20px; padding: 0;">' . implode('', $booking_details) . '</ul>';
        }
    }

    // -------------------------------------------------------------
    // USE CASE 3: Service Only (Paid in Advance)
    // -------------------------------------------------------------
    elseif (!$is_cod && $has_service && !$has_physical) {
        echo '<p><strong>[Prepaid Order - Clinic Booking Only]</strong></p>';
        echo '<p>A new prepaid service appointment has been scheduled:</p>';
        if (!empty($booking_details)) {
            echo '<ul style="margin: 0 0 10px 20px; padding: 0;">' . implode('', $booking_details) . '</ul>';
        }
    }

    // -------------------------------------------------------------
    // USE CASE 4: Service Only (Cash on Delivery / Pay In-Clinic)
    // -------------------------------------------------------------
    elseif ($is_cod && $has_service) {
        echo '<p style="color: #b32d2e;"><strong>[COD / Pay In-Clinic Booking]</strong></p>';
        echo '<p>This is a Cash on Delivery order. <strong>Payment will be required upon completion of the service in-clinic.</strong></p>';
        if (!empty($booking_details)) {
            echo '<ul style="margin: 0 0 10px 20px; padding: 0;">' . implode('', $booking_details) . '</ul>';
        }
    }

    echo '</div>';

}, 10, 4);


/**
 * Dynamically customize the Store Owner 'New Order' email subject line based on use case.
 */
add_filter('woocommerce_email_subject_new_order', function ($subject, $order, $email) {
    error_log("I am running the custom email subject function");
    if (!$order instanceof WC_Order) {
        return $subject;
    }

    $payment_method = trim(strtolower($order->get_payment_method()));
    $is_cod = ($payment_method === 'cod');
    $item_types = bbe_get_order_item_types($order);

    $has_service = $item_types['has_service'];
    $has_physical = $item_types['has_physical'];

    // Use Case 1: Product Only (Paid in Advance) -> Keep default subject
    if (!$is_cod && $has_physical && !$has_service) {
        return $subject;
    }

    $order_id = $order->get_id();

    // Use Case 2: Product and Service (Paid in Advance)
    if (!$is_cod && $has_physical && $has_service) {
        return sprintf(__('New Mixed Order & Booking (#%d) - Prepaid', 'bbe'), $order_id);
    }

    // Use Case 3: Service Only (Paid in Advance)
    if (!$is_cod && $has_service && !$has_physical) {
        return sprintf(__('New Clinic Booking (#%d) - Prepaid', 'bbe'), $order_id);
    }

    // Use Case 4: Service Only (Cash on Delivery / Pay In-Clinic)
    if ($is_cod && $has_service) {
        return sprintf(__('New Clinic Booking (#%d) - COD / Pay In-Clinic', 'bbe'), $order_id);
    }

    return $subject;
}, 10, 3);