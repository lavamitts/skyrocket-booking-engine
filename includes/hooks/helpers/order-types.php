<?php
/**
 * Helper to inspect an order's line items for services vs physical products.
 */
function bbe_get_order_item_types($order)
{
    $has_service = false;
    $has_physical = false;

    foreach ($order->get_items() as $item) {
        $product = $item->get_product();
        if (!$product)
            continue;

        // Determine if product is a service (check your custom meta, category, or virtual status)
        $is_service = (bool) wc_get_order_item_meta($item->get_id(), '_bbe_booking_start', true)
            || $product->is_virtual();

        if ($is_service) {
            $has_service = true;
        } else {
            $has_physical = true;
        }
    }

    return [
        'has_service' => $has_service,
        'has_physical' => $has_physical,
    ];
}