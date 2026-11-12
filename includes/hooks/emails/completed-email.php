<?php
/**
 * Plugin-driven completed order email content based on payment & item composition.
 */
add_action('woocommerce_email_before_order_table', function ($order, $sent_to_admin, $plain_text, $email) {
    // Only target the Customer Completed Order email sent to the client
    if ($sent_to_admin || $email->id !== 'customer_completed_order') {
        return;
    }

    $payment_method = trim(strtolower($order->get_payment_method()));
    $is_cod = ($payment_method === 'cod');
    $item_types = bbe_get_order_item_types($order);

    $has_service = $item_types['has_service'];
    $has_physical = $item_types['has_physical'];

    // Extract booking metadata if a service exists
    $booking_info = '';
    if ($has_service) {
        foreach ($order->get_items() as $item) {
            $start_dt = wc_get_order_item_meta($item->get_id(), '_bbe_booking_start', true);
            $location_id = wc_get_order_item_meta($item->get_id(), '_bbe_location_id', true);

            if ($start_dt && $location_id) {
                $location_name = get_the_title($location_id);
                $formatted_date = date('j M Y', strtotime($start_dt));
                $formatted_time = date('H:i', strtotime($start_dt));

                $booking_info = sprintf(
                    '<strong>%s</strong> on <strong>%s</strong> at <strong>%s</strong> (%s)',
                    esc_html($item->get_name()),
                    $formatted_date,
                    $formatted_time,
                    esc_html($location_name)
                );
                break;
            }
        }
    }

    // -------------------------------------------------------------
    // CONDITION 1: Paid in Advance | Mixed (Physical + Service)
    // -------------------------------------------------------------
    if (!$is_cod && $has_physical && $has_service) {
        echo '<div style="margin-bottom: 20px; padding: 15px; background-color: #f9f9f9; border-left: 4px solid #0288d1;">';
        echo '<p>Thank you for your order! Your physical items have been dispatched and are on their way to you.</p>';
        if ($booking_info) {
            echo '<p>You have also booked: ' . $booking_info . '. We look forward to seeing you then!</p>';
        }
        echo '</div>';
    }

    // -------------------------------------------------------------
    // CONDITION 2: Paid in Advance | Service Only (Post-Appointment)
    // -------------------------------------------------------------
    elseif (!$is_cod && $has_service && !$has_physical) {
        echo '<div style="margin-bottom: 20px; padding: 15px; background-color: #f9f9f9; border-left: 4px solid #2e7d32;">';
        echo '<p>Thank you for visiting us! We hope you enjoyed your service appointment today. We appreciate your custom and look forward to welcoming you back soon.</p>';
        echo '</div>';
    }

    // -------------------------------------------------------------
    // CONDITION 3: Cash on Delivery (COD) | Paid In-Clinic
    // -------------------------------------------------------------
    elseif ($is_cod) {
        echo '<div style="margin-bottom: 20px; padding: 15px; background-color: #f9f9f9; border-left: 4px solid #f57f17;">';
        echo '<p>Thank you for your payment and for visiting our clinic! Your order has been marked as completed.</p>';
        if ($has_physical) {
            echo '<p>Your items have been handed over / dispatched to you.</p>';
        }
        echo '</div>';
    }

}, 10, 4);