<?php
/**
 * Translate raw BBE booking item meta keys into human-readable labels in WooCommerce orders.
 */
add_filter('woocommerce_order_item_display_meta_key', function ($display_key, $meta, $item) {
    if ($meta->key === '_bbe_booking_start') {
        return __('Scheduled date & time', 'bbe');
    }
    if ($meta->key === '_bbe_location_id') {
        return __('Location', 'bbe');
    }
    return $display_key;
}, 10, 3);

/**
 * Format the meta values nicely (translating dates, times, and location IDs to post titles).
 */
add_filter('woocommerce_order_item_display_meta_value', function ($display_value, $meta, $item) {
    if ($meta->key === '_bbe_booking_start') {
        $timestamp = strtotime($meta->value);
        if ($timestamp) {
            $formatted_date = date('j M Y', $timestamp); // e.g., 17 Nov 2026
            $formatted_time = date('H:i', $timestamp);    // e.g., 09:00 (24hr clock)
            return sprintf('<strong>%s</strong> at <strong>%s</strong>', $formatted_date, $formatted_time);
        }
    }

    if ($meta->key === '_bbe_location_id') {
        $location_id = intval($meta->value);
        $location_post = get_post($location_id);
        if ($location_post && $location_post->post_type === 'bbe_location') {
            return esc_html($location_post->post_title); // e.g., Clinic 1
        }
    }

    return $display_value;
}, 10, 3);