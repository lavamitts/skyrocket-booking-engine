<?php
if (!defined('ABSPATH'))
    exit;

class BBE_Holds
{

    public static function init()
    {
        add_action('wp_ajax_bbe_create_hold', [__CLASS__, 'ajax_create_hold']);
        add_action('wp_ajax_nopriv_bbe_create_hold', [__CLASS__, 'ajax_create_hold']);
        add_filter('woocommerce_add_cart_item_data', [__CLASS__, 'attach_booking_to_cart_item'], 10, 3);
        add_action('woocommerce_checkout_create_order_line_item', [__CLASS__, 'save_booking_to_order_item'], 10, 4);
    }

    public static function ajax_create_hold()
    {
        global $wpdb;

        $product_id = intval($_POST['product_id']);
        $location_id = intval($_POST['location_id']);
        $start = sanitize_text_field($_POST['start_time']); // Y-m-d H:i:s

        $duration = (int) get_post_meta($product_id, '_bbe_duration', true);
        $buffer = (int) get_post_meta($product_id, '_bbe_buffer', true);

        $start_ts = strtotime($start);
        $block_end = date('Y-m-d H:i:s', $start_ts + (($duration + $buffer) * 60));

        if (!session_id())
            session_start();
        $session_id = session_id();

        // Remove previous holds by this session
        $wpdb->delete($wpdb->prefix . 'bbe_holds', ['session_id' => $session_id]);

        // Insert new hold (Expires in 15 Minutes)
        $wpdb->insert($wpdb->prefix . 'bbe_holds', [
            'session_id' => $session_id,
            'product_id' => $product_id,
            'location_id' => $location_id,
            'booking_start' => $start,
            'booking_end' => $block_end,
            'created_at' => current_time('mysql'),
            'expires_at' => date('Y-m-d H:i:s', current_time('timestamp') + (15 * 60))
        ]);

        wp_send_json_success(['status' => 'held', 'expires_in' => 900]);
    }

    // Bind hold metadata to Woo Cart Item
    public static function attach_booking_to_cart_item($cart_item_data, $product_id, $variation_id)
    {
        if (isset($_POST['bbe_start_time'])) {
            $cart_item_data['bbe_booking'] = [
                'start' => sanitize_text_field($_POST['bbe_start_time']),
                'location_id' => intval($_POST['bbe_location_id'])
            ];
        }
        return $cart_item_data;
    }

    // Save Booking Info to WooCommerce Order Line Item
    public static function save_booking_to_order_item($item, $cart_item_key, $values, $order)
    {
        if (isset($values['bbe_booking'])) {
            $item->add_meta_data('_bbe_booking_start', $values['bbe_booking']['start']);
            $item->add_meta_data('_bbe_location_id', $values['bbe_booking']['location_id']);
        }
    }
}
BBE_Holds::init();