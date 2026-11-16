<?php
if (!defined('ABSPATH'))
    exit;

class BBE_Checkout
{

    public static function init()
    {
        // Conditionally enable/disable COD based on whether cart contains only service items
        add_filter('woocommerce_available_payment_gateways', [__CLASS__, 'toggle_cod_gateway'], 10, 1);

        // Force WooCommerce to clear gateway availability cache and refresh fragments
        add_action('woocommerce_add_to_cart', [__CLASS__, 'clear_gateway_cache']);
        add_action('woocommerce_cart_item_removed', [__CLASS__, 'clear_gateway_cache']);
        add_action('woocommerce_update_cart_action_cart_updated', [__CLASS__, 'clear_gateway_cache']);
        add_action('woocommerce_after_cart_item_quantity_update', [__CLASS__, 'clear_gateway_cache'], 10, 4);

        // Force frontend checkout fragment refresh so JS blocks update instantly
        add_filter('woocommerce_update_order_review_fragments', [__CLASS__, 'force_gateway_fragment_refresh']);

        // ULTIMATE FAIL-SAFE: Block checkout submission server-side if COD is used on a non-service cart
        add_action('woocommerce_checkout_process', [__CLASS__, 'validate_cod_checkout']);
    }

    /**
     * Disable Cash on Delivery (COD) unless the cart contains *only* service items.
     */
    public static function toggle_cod_gateway($gateways)
    {
        // Only run on frontend checkout / cart calculations
        if (is_admin() || (!is_cart() && !is_checkout())) {
            return $gateways;
        }

        // Safety check if cart is initialized
        if (!WC()->cart) {
            return $gateways;
        }

        $cart_items = WC()->cart->get_cart();

        // If cart is empty, hide COD
        if (empty($cart_items)) {
            unset($gateways['cod']);
            return $gateways;
        }

        $only_services = true;

        foreach ($cart_items as $cart_item) {
            $product_id = !empty($cart_item['variation_id']) ? $cart_item['variation_id'] : $cart_item['product_id'];
            $is_service = get_post_meta($product_id, '_bbe_is_service', true) === 'yes';

            if (!$is_service && !empty($cart_item['product_id'])) {
                $is_service = get_post_meta($cart_item['product_id'], '_bbe_is_service', true) === 'yes';
            }

            if (!$is_service) {
                $only_services = false;
                break;
            }
        }

        // If the cart does NOT consist exclusively of services, remove COD
        if (!$only_services) {
            unset($gateways['cod']);

            // FORCE SESSION RESET: If the user's session currently has COD selected, 
            // wipe it out immediately so the frontend checkout radio doesn't stick.
            if (function_exists('WC') && WC()->session) {
                $chosen_gateway = WC()->session->get('chosen_payment_method');
                if ($chosen_gateway === 'cod') {
                    WC()->session->set('chosen_payment_method', '');
                }
            }
        }

        return $gateways;
    }

    /**
     * Clear cached gateway session fragments when cart items change.
     */
    public static function clear_gateway_cache()
    {
        if (function_exists('WC') && WC()->session) {
            WC()->session->set('refresh_totals', true);
            delete_transient('wc_available_payment_gateways');

            // If cart changes, verify if chosen method is still valid, else clear session payment
            $cart_items = WC()->cart ? WC()->cart->get_cart() : [];
            $only_services = true;
            foreach ($cart_items as $item) {
                $pid = !empty($item['variation_id']) ? $item['variation_id'] : $item['product_id'];
                if (get_post_meta($pid, '_bbe_is_service', true) !== 'yes') {
                    $only_services = false;
                    break;
                }
            }
            if (!$only_services && WC()->session->get('chosen_payment_method') === 'cod') {
                WC()->session->set('chosen_payment_method', '');
            }
        }
    }

    /**
     * Force checkout fragments to update payment methods dynamically via AJAX.
     */
    public static function force_gateway_fragment_refresh($fragments)
    {
        WC()->payment_gateways()->get_available_payment_gateways();
        return $fragments;
    }

    /**
     * Server-side fail-safe check during checkout submission.
     */
    public static function validate_cod_checkout()
    {
        if (isset($_POST['payment_method']) && $_POST['payment_method'] === 'cod') {
            if (!WC()->cart) {
                return;
            }

            foreach (WC()->cart->get_cart() as $cart_item) {
                $product_id = !empty($cart_item['variation_id']) ? $cart_item['variation_id'] : $cart_item['product_id'];
                $is_service = get_post_meta($product_id, '_bbe_is_service', true) === 'yes';

                if (!$is_service && !empty($cart_item['product_id'])) {
                    $is_service = get_post_meta($cart_item['product_id'], '_bbe_is_service', true) === 'yes';
                }

                if (!$is_service) {
                    wc_add_notice(__('Cash on Delivery is only available for service-only bookings. Please select an alternative payment method.', 'bbe'), 'error');
                    break;
                }
            }
        }
    }
}
BBE_Checkout::init();