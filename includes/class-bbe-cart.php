<?php
if (!defined('ABSPATH'))
    exit;

class BBE_Cart
{

    public static function init()
    {
        // Prevent adding to cart if booking parameters are missing
        add_filter('woocommerce_add_to_cart_validation', [__CLASS__, 'validate_booking_before_cart'], 10, 5);

        // Display booking details (Date, Time, Location) in Cart & Checkout
        add_filter('woocommerce_get_item_data', [__CLASS__, 'display_booking_cart_item_data'], 10, 2);

        // Validate at checkout that the hold hasn't expired or been stolen
        add_action('woocommerce_checkout_process', [__CLASS__, 'validate_booking_at_checkout']);

        // Clean up holds once an order is successfully created
        add_action('woocommerce_checkout_order_processed', [__CLASS__, 'clear_hold_after_order'], 10, 3);

        // FORCE SOLD INDIVIDUALLY: This natively removes the qty input in WooCommerce cart for services
        add_filter('woocommerce_is_sold_individually', [__CLASS__, 'force_service_sold_individually'], 10, 2);
    }

    /**
     * Force service products to be treated as "sold individually", natively removing the cart qty input.
     */
    public static function force_service_sold_individually($return, $product)
    {
        if ($product && $product->get_meta('_bbe_is_service') === 'yes') {
            return true;
        }
        return $return;
    }

    /**
     * Block adding service products to cart if location or slot time is missing
     */
    public static function validate_booking_before_cart($passed, $product_id, $quantity, $variation_id = 0, $variations = [])
    {
        $is_service = get_post_meta($product_id, '_bbe_is_service', true) === 'yes';

        if ($is_service) {
            $location_id = isset($_POST['bbe_location_id']) ? intval($_POST['bbe_location_id']) : 0;
            $start_time = isset($_POST['bbe_start_time']) ? sanitize_text_field($_POST['bbe_start_time']) : '';

            if (!$location_id || empty($start_time)) {
                wc_add_notice(__('Please select a valid location and time slot before adding this service to your cart.', 'bbe'), 'error');
                return false;
            }
        }

        return $passed;
    }

    /**
     * Show booking slot details inside the cart item view
     */
    public static function display_booking_cart_item_data($item_data, $cart_item)
    {
        if (isset($cart_item['bbe_booking'])) {
            $booking = $cart_item['bbe_booking'];
            $location_post = get_post($booking['location_id']);
            $location_name = $location_post ? $location_post->post_title : __('Standard Location', 'bbe');

            $item_data[] = [
                'name' => __('Date & Time', 'bbe'),
                'value' => date('l, F j, Y \ H:i', strtotime($booking['start']))
            ];

            $item_data[] = [
                'name' => __('Location', 'bbe'),
                'value' => $location_name
            ];
        }
        return $item_data;
    }

    /**
     * Validate that the temporary hold is still valid right when checkout is submitted
     */
    public static function validate_booking_at_checkout()
    {
        foreach (WC()->cart->get_cart() as $cart_item_key => $values) {
            if (isset($values['bbe_booking'])) {
                $product_id = $values['product_id'];
                $booking = $values['bbe_booking'];

                // Ensure hold still exists in database for this session/slot
                global $wpdb;
                $t_holds = $wpdb->prefix . 'bbe_holds';

                if (!session_id())
                    session_start();
                $session_id = session_id();

                $exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM $t_holds WHERE session_id = %s AND product_id = %d AND location_id = %d AND booking_start = %s AND expires_at > NOW()",
                    $session_id,
                    $product_id,
                    $booking['location_id'],
                    $booking['start']
                ));

                if (!$exists) {
                    wc_add_notice(sprintf(__('Your 15-minute hold for %s has expired. Please re-select your slot.', 'bbe'), get_the_title($product_id)), 'error');
                }
            }
        }
    }

    /**
     * Remove hold record once order is placed successfully
     */
    public static function clear_hold_after_order($order_id, $posted_data, $order)
    {
        global $wpdb;
        if (!session_id())
            session_start();
        $session_id = session_id();

        $wpdb->delete($wpdb->prefix . 'bbe_holds', ['session_id' => $session_id]);
    }
}
BBE_Cart::init();