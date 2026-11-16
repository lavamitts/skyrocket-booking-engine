<?php
/**
 * Remove "Add to Basket" buttons on shop and category archive loops
 * for service products (identified as virtual products in WooCommerce).
 */
add_filter('woocommerce_loop_add_to_cart_link', function ($button_html, $product, $args) {
    if (is_admin() || is_product() || !$product instanceof WC_Product) {
        return $button_html;
    }

    // Service products are marked as virtual in WooCommerce
    if ($product->is_virtual()) {
        return '';
    }

    return $button_html;
}, 10, 3);