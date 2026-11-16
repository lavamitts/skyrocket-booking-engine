<?php
if (!defined('ABSPATH'))
    exit;

/**
 * Completed-order email customisation.
 *
 * Scenarios (decided by order contents only; payment method just tweaks wording):
 *   product_only    -> untouched WooCommerce default
 *   product_service -> default look + dispatch note + upcoming appointment details
 *   service_only    -> post-visit "thank you" email (prepaid OR pay-at-clinic/COD)
 *
 * Requires bbe_get_order_item_types() (defined elsewhere in the plugin).
 */

/* -------------------------------------------------------------------------
 * Helpers
 * ---------------------------------------------------------------------- */

function bbe_completed_scenario($order)
{
    $types = bbe_get_order_item_types($order);
    if ($types['has_service'] && $types['has_physical']) {
        return 'product_service';
    }
    if ($types['has_service']) {
        return 'service_only';
    }
    return 'product_only';
}

function bbe_order_is_cod($order)
{
    return trim(strtolower($order->get_payment_method())) === 'cod';
}

/**
 * Collect every booked service line (not just the first).
 * Returns array of ['name','date','time','location'].
 */
function bbe_get_order_bookings($order)
{
    $bookings = [];
    foreach ($order->get_items() as $item) {
        $start = $item->get_meta('_bbe_booking_start', true);
        if (!$start || !strtotime($start)) {
            continue;
        }
        $location_id = $item->get_meta('_bbe_location_id', true);
        $ts = strtotime($start); // stored as site-local time, so date() (not wp_date) is correct
        $bookings[] = [
            'name' => $item->get_name(),
            'date' => date('j M Y', $ts),
            'time' => date('H:i', $ts),
            'location' => $location_id ? get_the_title($location_id) : '',
        ];
    }
    return $bookings;
}

function bbe_format_booking($b, $html = true)
{
    $loc = $b['location'] ? ' (' . $b['location'] . ')' : '';
    if ($html) {
        return sprintf(
            '<strong>%s</strong> on <strong>%s</strong> at <strong>%s</strong>%s',
            esc_html($b['name']),
            esc_html($b['date']),
            esc_html($b['time']),
            esc_html($loc)
        );
    }
    return sprintf('%s on %s at %s%s', $b['name'], $b['date'], $b['time'], $loc);
}

/** "A", "A and B", "A, B and C" */
function bbe_join_bookings($bookings, $html = true)
{
    $parts = array_map(function ($b) use ($html) {
        return bbe_format_booking($b, $html);
    }, $bookings);
    $last = array_pop($parts);
    return $parts ? implode(', ', $parts) . ' and ' . $last : (string) $last;
}

/* -------------------------------------------------------------------------
 * 1. Body content before the order table
 * ---------------------------------------------------------------------- */

add_action('woocommerce_email_before_order_table', function ($order, $sent_to_admin, $plain_text, $email) {
    if ($sent_to_admin || !$email || $email->id !== 'customer_completed_order') {
        return;
    }

    $scenario = bbe_completed_scenario($order);
    if ($scenario === 'product_only') {
        return; // default WooCommerce behaviour
    }

    $is_cod = bbe_order_is_cod($order);
    $bookings = bbe_get_order_bookings($order);

    if ($scenario === 'product_service') {
        // Product dispatched + appointment still to come.
        $lines = [__('Thank you for your order! Your products have been dispatched and are on their way to you.', 'bbe')];
        if ($bookings) {
            $lines[] = $is_cod
                ? sprintf(__('Your appointment is still to come: %s. Payment for your appointment is taken at the clinic.', 'bbe'), bbe_join_bookings($bookings, !$plain_text))
                : sprintf(__('Your appointment is still to come: %s. We look forward to seeing you then!', 'bbe'), bbe_join_bookings($bookings, !$plain_text));
        } else {
            $lines[] = __('Your appointment is still to come, and we look forward to seeing you then!', 'bbe');
        }
        $border = '#0288d1';
    } else {
        // service_only: after the visit, same wording whether prepaid or paid in clinic.
        $lines = [__('Thank you for visiting us!', 'bbe')];
        if ($bookings) {
            $lines[] = sprintf(__('We hope you enjoyed your appointment: %s.', 'bbe'), bbe_join_bookings($bookings, !$plain_text));
        } else {
            $lines[] = __('We hope you enjoyed your appointment.', 'bbe');
        }
        if ($is_cod) {
            $lines[] = __('Thank you for your payment in the clinic today.', 'bbe');
        }
        $lines[] = __('We truly appreciate your custom and hope to see you again soon.', 'bbe');
        $border = '#2e7d32';

        // Hide the payment method row for this email only (removed again after the table).
        add_filter('woocommerce_get_order_item_totals', 'bbe_strip_payment_row', 10, 1);
    }

    if ($plain_text) {
        echo "\n" . implode("\n\n", array_map('wp_strip_all_tags', $lines)) . "\n\n";
        return;
    }

    // $lines may contain our own already-escaped <strong> markup, so output via wp_kses_post.
    echo '<div style="margin:0 0 20px 0;padding:15px 20px;background-color:#f9f9f9;border-left:4px solid ' . esc_attr($border) . ';font-size:15px;">';
    foreach ($lines as $i => $line) {
        $margin = ($i === count($lines) - 1) ? '0' : '0 0 10px 0';
        echo '<p style="margin:' . $margin . ';">' . wp_kses_post($line) . '</p>';
    }
    echo '</div>';
}, 10, 4);

function bbe_strip_payment_row($rows)
{
    unset($rows['payment_method']);
    return $rows;
}

// Stop the payment-row suppression leaking into other emails in the same request (e.g. bulk complete).
add_action('woocommerce_email_after_order_table', function () {
    remove_filter('woocommerce_get_order_item_totals', 'bbe_strip_payment_row', 10);
}, 10, 0);

/* -------------------------------------------------------------------------
 * 2. Subject line
 * ---------------------------------------------------------------------- */

add_filter('woocommerce_email_subject_customer_completed_order', function ($subject, $order, $email = null) {
    if (!$order instanceof WC_Order) {
        return $subject;
    }

    $id = $order->get_id();
    switch (bbe_completed_scenario($order)) {
        case 'product_service':
            return sprintf(__('Your order has been dispatched & your appointment details (#%d)', 'bbe'), $id);
        case 'service_only':
            return sprintf(__('Thank you for your visit! (#%d)', 'bbe'), $id);
    }
    return $subject; // product_only: default
}, 10, 3);

/* -------------------------------------------------------------------------
 * 3. Email heading + intro line (service-only: drop "Good things are on their way")
 * ---------------------------------------------------------------------- */

add_filter('woocommerce_email_heading_customer_completed_order', function ($heading, $order = null, $email = null) {
    if (!$order instanceof WC_Order || bbe_completed_scenario($order) !== 'service_only') {
        return $heading;
    }

    // The template hard-codes "We have finished processing your order." - swap it for this email only.
    add_filter('gettext', 'bbe_service_only_intro_text', 10, 3);

    return __('Thank you for your visit', 'bbe');
}, 10, 3);

function bbe_service_only_intro_text($translated, $text, $domain)
{
    if ($domain === 'woocommerce' && $text === 'We have finished processing your order.') {
        return __('Your appointment is now complete. Here is a summary for your records.', 'bbe');
    }
    return $translated;
}

// Always unhook once the email footer renders so other emails are unaffected.
add_action('woocommerce_email_footer', function () {
    remove_filter('gettext', 'bbe_service_only_intro_text', 10);
}, 1, 0);