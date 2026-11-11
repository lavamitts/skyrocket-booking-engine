<?php
if (!defined('ABSPATH'))
    exit;

class BBE_DB
{

    /**
     * Clean up expired holds from the bbe_holds table.
     * Can be hooked into WP-Cron or run on demand.
     */
    public static function cleanup_expired_holds()
    {
        global $wpdb;
        $table_holds = $wpdb->prefix . 'bbe_holds';

        $wpdb->query(
            "DELETE FROM $table_holds WHERE expires_at < NOW()"
        );
    }

    /**
     * Fetch all confirmed booking blocks from completed/processing WooCommerce orders
     * for a given location and date.
     */
    public static function get_confirmed_order_blocks($location_id, $date_str)
    {
        global $wpdb;
        $busy_blocks = [];

        // Query WooCommerce orders that are processing or completed
        $orders = wc_get_orders([
            'limit' => -1,
            'status' => ['processing', 'completed'],
            'date_created' => $date_str . ' 00:00:00...' . $date_str . ' 23:59:59'
        ]);

        foreach ($orders as $order) {
            foreach ($order->get_items() as $item) {
                $item_location = (int) $item->get_meta('_bbe_location_id');
                $start_time = $item->get_meta('_bbe_booking_start');

                if ($item_location === $location_id && !empty($start_time)) {
                    $product_id = $item->get_product_id();
                    $duration = (int) get_post_meta($product_id, '_bbe_duration', true);
                    $buffer = (int) get_post_meta($product_id, '_bbe_buffer', true);
                    $total_mins = $duration + $buffer;

                    $start_ts = strtotime($start_time);
                    $end_block_ts = $start_ts + ($total_mins * 60);

                    $busy_blocks[] = [
                        'start' => $start_time,
                        'end' => date('Y-m-d H:i:s', $end_block_ts)
                    ];
                }
            }
        }

        return $busy_blocks;
    }
}