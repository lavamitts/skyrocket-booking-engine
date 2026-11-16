<?php
if (!defined('ABSPATH'))
    exit;

class BBE_Holds
{
    public static function init()
    {
        add_action('wp_ajax_bbe_create_hold', [__CLASS__, 'ajax_create_hold']);
        add_action('wp_ajax_nopriv_bbe_create_hold', [__CLASS__, 'ajax_create_hold']);

        // Intercept cart add to append variation & booking slot data
        add_filter('woocommerce_add_cart_item_data', [__CLASS__, 'attach_booking_to_cart_item'], 10, 3);
        add_action('woocommerce_checkout_create_order_line_item', [__CLASS__, 'save_booking_to_order_item'], 10, 4);

        // Release holds when items are removed from the basket or cart is cleared
        add_action('woocommerce_cart_item_removed', [__CLASS__, 'release_hold_on_cart_removal'], 10, 2);
        add_action('woocommerce_cart_emptied', [__CLASS__, 'release_holds_on_cart_emptied']);
    }

    public static function ajax_create_hold()
    {
        global $wpdb;

        $product_id = intval($_POST['product_id']);
        $variation_id = intval($_POST['variation_id']);
        $location_id = intval($_POST['location_id']);
        $start = sanitize_text_field($_POST['start_time']); // Y-m-d H:i:s

        // DEBUG LOG: See if AJAX is actually reaching PHP
        error_log("BBE AJAX HOLD HIT -> Product: $product_id | Variation: $variation_id | Location: $location_id | Start: $start");

        $target_id = $variation_id ? $variation_id : $product_id;

        $duration = BBE_Availability::get_product_duration($target_id);
        $buffer = BBE_Availability::get_product_buffer($product_id, $variation_id);

        error_log("BBE AJAX HOLD CALC -> Duration: $duration mins | Buffer: $buffer mins");

        $start_ts = strtotime($start);
        $block_end = date('Y-m-d H:i:s', $start_ts + (($duration + $buffer) * 60));

        if (!session_id())
            session_start();
        $session_id = session_id();

        // Remove previous holds by this session
        $wpdb->delete($wpdb->prefix . 'bbe_holds', ['session_id' => $session_id]);

        // Insert new hold (Expires in 15 Minutes)
        $inserted = $wpdb->insert($wpdb->prefix . 'bbe_holds', [
            'session_id' => $session_id,
            'product_id' => $target_id,
            'location_id' => $location_id,
            'booking_start' => $start,
            'booking_end' => $block_end,
            'created_at' => current_time('mysql'),
            'expires_at' => date('Y-m-d H:i:s', current_time('timestamp') + (15 * 60))
        ]);

        if ($inserted === false) {
            error_log("BBE AJAX HOLD DB ERROR -> " . $wpdb->last_error);
            wp_send_json_error(['message' => $wpdb->last_error]);
        }

        error_log("BBE AJAX HOLD SUCCESS -> Block End: $block_end");
        wp_send_json_success(['status' => 'held', 'expires_in' => 900]);
    }

    public static function attach_booking_to_cart_item($cart_item_data, $product_id, $variation_id)
    {
        if (isset($_POST['bbe_start_time']) && isset($_POST['bbe_location_id'])) {
            $cart_item_data['bbe_booking'] = [
                'start' => sanitize_text_field($_POST['bbe_start_time']),
                'location_id' => intval($_POST['bbe_location_id'])
            ];
        }
        return $cart_item_data;
    }

    public static function save_booking_to_order_item($item, $cart_item_key, $values, $order)
    {
        if (isset($values['bbe_booking'])) {
            $item->add_meta_data('_bbe_booking_start', $values['bbe_booking']['start']);
            $item->add_meta_data('_bbe_location_id', $values['bbe_booking']['location_id']);
        }
    }

    /**
     * Release the temporary hold when a booking item is removed from the cart.
     */
    public static function release_hold_on_cart_removal($cart_item_key, $cart)
    {
        global $wpdb;

        if (!session_id()) {
            session_start();
        }
        $session_id = session_id();

        if (!empty($session_id)) {
            $wpdb->delete($wpdb->prefix . 'bbe_holds', ['session_id' => $session_id]);
        }
    }

    /**
     * Release all session holds if the entire cart is cleared.
     */
    public static function release_holds_on_cart_emptied()
    {
        global $wpdb;

        if (!session_id()) {
            session_start();
        }
        $session_id = session_id();

        if (!empty($session_id)) {
            $wpdb->delete($wpdb->prefix . 'bbe_holds', ['session_id' => $session_id]);
        }
    }
}
BBE_Holds::init();