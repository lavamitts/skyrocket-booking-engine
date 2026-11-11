<?php
if (!defined('ABSPATH'))
    exit;

class BBE_Checkout
{

    public static function init()
    {
        // Conditionally enable/disable COD based on whether cart contains physical items
        add_filter('woocommerce_available_payment_gateways', [__CLASS__, 'toggle_cod_gateway'], 10, 1);
    }

    /**
     * Disable Cash on Delivery (COD) if the cart contains any physical (non-service) products.
     */
    public static function toggle_cod_gateway($gateways)
    {
        // Only run on frontend checkout / cart calculations
        if (is_admin() || !is_cart() && !is_checkout()) {
            return $gateways;
        }

        // Check if COD is even active/registered in gateways
        if (!isset($gateways['cod'])) {
            return $gateways;
        }

        $has_physical_items = false;

        foreach (WC()->cart->get_cart() as $cart_item) {
            $product_id = $cart_item['product_id'];
            $is_service = get_post_meta($product_id, '_bbe_is_service', true) === 'yes';

            if (!$is_service) {
                $has_physical_items = true;
                break;
            }
        }

        // If cart contains any physical goods, remove COD from available gateways
        if ($has_physical_items) {
            unset($gateways['cod']);
        }

        return $gateways;
    }
}
BBE_Checkout::init();