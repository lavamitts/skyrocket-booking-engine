<?php
/**
 * 1. Dynamically customize the Customer Email subject lines based on use case.
 */
add_filter('woocommerce_email_subject_customer_processing_order', 'bbe_customize_customer_email_subject', 10, 3);
add_filter('woocommerce_email_subject_customer_on_hold_order', 'bbe_customize_customer_email_subject', 10, 3);

function bbe_customize_customer_email_subject($subject, $order, $email)
{
    if (!$order instanceof WC_Order) {
        return $subject;
    }

    $payment_method = trim(strtolower($order->get_payment_method()));
    $is_cod = ($payment_method === 'cod');
    $item_types = bbe_get_order_item_types($order);

    $has_service = $item_types['has_service'];
    $has_physical = $item_types['has_physical'];

    $order_id = $order->get_id();

    // Use Case 1: Product Only (Paid in Advance) -> Keep default subject
    if (!$is_cod && $has_physical && !$has_service) {
        return $subject;
    }

    // Use Case 2 & 3: Prepaid Mixed or Service Only
    if (!$is_cod && $has_service) {
        return sprintf(__('Your Booking & Order Confirmation (#%d)', 'bbe'), $order_id);
    }

    // Use Case 4: Service Only COD (On-Hold status awaiting in-clinic payment)
    if ($is_cod && $has_service) {
        return sprintf(__('Your Clinic Booking Confirmation (#%d) - Pay In-Clinic', 'bbe'), $order_id);
    }

    return $subject;
}

/**
 * 2. Customize the Customer Email content (Processing & On-Hold) based on item composition.
 */
add_action('woocommerce_email_before_order_table', function ($order, $sent_to_admin, $plain_text, $email) {
    // Only target customer-facing emails for processing or on-hold states
    if ($sent_to_admin || !in_array($email->id, ['customer_processing_order', 'customer_on_hold_order'], true)) {
        return;
    }

    $payment_method = trim(strtolower($order->get_payment_method()));
    $is_cod = ($payment_method === 'cod');
    $item_types = bbe_get_order_item_types($order);

    $has_service = $item_types['has_service'];
    $has_physical = $item_types['has_physical'];

    // Extract booking details if a service is present
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
                    '<li><strong>%s</strong><br>Date: %s at %s<br>Location: %s</li>',
                    esc_html($item->get_name()),
                    $formatted_date,
                    $formatted_time,
                    esc_html($location_name)
                );
            }
        }
    }

    // Use Case 1: Product Only (Paid in Advance) -> Leave default body
    if (!$is_cod && $has_physical && !$has_service) {
        return;
    }

    // Container styling for customer notices
    echo '<div style="margin: 20px 0; padding: 20px; background-color: #f7f9fa; border: 1px solid #e1e4e8; border-radius: 4px; border-left: 4px solid #007cba;">';

    // Use Case 2: Product and Service (Paid in Advance)
    if (!$is_cod && $has_physical && $has_service) {
        echo '<h3 style="margin-top: 0; color: #1d2327; font-size: 16px;">' . __('Your Order & Appointment Details', 'bbe') . '</h3>';
        echo '<p>' . __('Thank you for your order! Your physical items are being prepared for dispatch. You have also booked the following on-site clinic appointment:', 'bbe') . '</p>';
        if (!empty($booking_details)) {
            echo '<ul style="margin: 10px 0 0 20px; padding: 0; line-height: 1.6;">' . implode('', $booking_details) . '</ul>';
        }
        echo '<p style="margin-top: 15px;">' . __('We look forward to seeing you at the clinic.', 'bbe') . '</p>';
    }

    // Use Case 3: Service Only (Paid in Advance)
    elseif (!$is_cod && $has_service && !$has_physical) {
        echo '<h3 style="margin-top: 0; color: #1d2327; font-size: 16px;">' . __('Your Clinic Booking Confirmation', 'bbe') . '</h3>';
        echo '<p>' . __('Thank you for your booking! Your appointment has been successfully reserved:', 'bbe') . '</p>';
        if (!empty($booking_details)) {
            echo '<ul style="margin: 10px 0 0 20px; padding: 0; line-height: 1.6;">' . implode('', $booking_details) . '</ul>';
        }
        echo '<p style="margin-top: 15px;">' . __('We look forward to seeing you then!', 'bbe') . '</p>';
    }

    // Use Case 4: Service Only (Cash on Delivery / Pay In-Clinic)
    elseif ($is_cod && $has_service) {
        echo '<h3 style="margin-top: 0; color: #b32d2e; font-size: 16px;">' . __('Clinic Booking & Payment Notice', 'bbe') . '</h3>';
        echo '<p>' . __('Thank you for your booking! Your appointment has been reserved.', 'bbe') . '</p>';
        if (!empty($booking_details)) {
            echo '<ul style="margin: 10px 0 0 20px; padding: 0; line-height: 1.6;">' . implode('', $booking_details) . '</ul>';
        }
        echo '<p style="margin-top: 15px; background: #fff3f3; padding: 10px; border-radius: 3px;"><strong>' . __('Please note:', 'bbe') . '</strong> ' . __('As this is a Pay In-Clinic booking, payment will be required upon completion of your service.', 'bbe') . '</p>';
    }

    echo '</div>';

}, 10, 4);