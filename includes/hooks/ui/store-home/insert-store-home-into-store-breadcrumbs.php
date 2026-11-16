<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Insert "Shop" after "Home" in Blocksy breadcrumbs on Basket, Checkout,
 * Order Received, and the custom Book Session page.
 */
add_filter('blocksy:breadcrumbs:items-array', function ($items) {
    if (empty($items)) {
        return $items;
    }

    // Target Basket, Checkout (including Order Received), and the 'book-session' page
    $is_target = is_cart() || is_checkout() || is_page('book-session');

    if (!$is_target) {
        return $items;
    }

    $shop_url = wc_get_page_permalink('shop');
    if (!$shop_url) {
        return $items;
    }

    // Prevent duplicates if "Shop" is already in the trail
    foreach ($items as $item) {
        if (isset($item['url']) && untrailingslashit($item['url']) === untrailingslashit($shop_url)) {
            return $items;
        }
    }

    $shop_item = [
        'name' => __('Shop', 'woocommerce'),
        'url' => $shop_url,
    ];

    // Insert right after the first item ("Home")
    array_splice($items, 1, 0, [$shop_item]);

    return $items;
});