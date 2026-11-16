<?php
if (!defined('ABSPATH'))
    exit;

class BBE_Availability
{
    /**
     * Helper to reliably extract duration in minutes (always referenced in minutes e.g. '15 minutes', '60 minutes').
     */
    public static function get_product_duration($target_id): int
    {
        $target_product = wc_get_product($target_id);
        if (!$target_product) {
            return 15; // Safe fallback
        }

        $duration = 0;

        // 1. Check if it's a variation or simple product with attributes
        $raw_d = $target_product->get_attribute('pa_duration') ?: $target_product->get_attribute('duration');
        if (empty($raw_d)) {
            $raw_d = $target_product->get_meta('attribute_pa_duration') ?: $target_product->get_meta('attribute_duration');
        }
        if (preg_match('/(\d+)/', sanitize_title($raw_d), $m)) {
            $duration = (int) $m[1];
        }

        // 2. Check product-level post meta '_bbe_duration'
        if (!$duration) {
            $duration = (int) get_post_meta($target_id, '_bbe_duration', true);
        }

        // 3. If parent product, check if parent has meta
        if (!$duration && $target_product->is_type('variation')) {
            $parent_id = $target_product->get_parent_id();
            if ($parent_id) {
                $duration = (int) get_post_meta($parent_id, '_bbe_duration', true);
            }
        }

        // Final failsafe
        return $duration ? $duration : 15;
    }

    /**
     * Helper to reliably extract buffer time in minutes (always stored as an integer).
     */
    public static function get_product_buffer($product_id, $variation_id = 0): int
    {
        $buffer = (int) get_post_meta($product_id, '_bbe_buffer', true);
        if (!$buffer && $variation_id) {
            $parent_id = wp_get_post_parent_id($variation_id);
            if ($parent_id) {
                $buffer = (int) get_post_meta($parent_id, '_bbe_buffer', true);
            }
        }
        return $buffer;
    }

    public static function get_available_slots($product_id, $variation_id, $location_id, $date_str)
    {
        global $wpdb;

        // STRICT RULE: Reject any date that is today or in the past
        $today_str = current_time('Y-m-d');
        if ($date_str <= $today_str) {
            return [];
        }

        $target_id = $variation_id ? $variation_id : $product_id;
        $duration = self::get_product_duration($target_id);
        $buffer = self::get_product_buffer($product_id, $variation_id);
        $total_needed_mins = $duration + $buffer;

        if (!$duration)
            return [];

        $day_of_week = strtolower(date('D', strtotime($date_str))); // 'mon', 'tue', etc.
        $t_sched = $wpdb->prefix . 'bbe_schedules';

        // 1. Check if date falls within a multi-day full closure / holiday range
        $full_block = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $t_sched WHERE location_id = %d AND override_type = 'block' AND %s BETWEEN specific_date AND IFNULL(end_date, specific_date)",
            $location_id,
            $date_str
        ));
        if ($full_block)
            return [];

        // 2. Fetch Weekly Shifts for this day of the week
        $shifts = $wpdb->get_results($wpdb->prepare(
            "SELECT start_time, end_time FROM $t_sched WHERE location_id = %d AND day_of_week = %s AND specific_date IS NULL ORDER BY start_time ASC",
            $location_id,
            $day_of_week
        ));

        // 3. Check for any "extra one-off shifts" added for this specific date
        $extra_shifts = $wpdb->get_results($wpdb->prepare(
            "SELECT start_time, end_time FROM $t_sched WHERE location_id = %d AND override_type = 'extra_shift' AND %s BETWEEN specific_date AND IFNULL(end_date, specific_date)",
            $location_id,
            $date_str
        ));
        if (!empty($extra_shifts)) {
            foreach ($extra_shifts as $es) {
                $shifts[] = $es;
            }
        }

        if (empty($shifts))
            return [];

        // 4. Fetch partial exclusion windows for this date
        $exclusions = $wpdb->get_results($wpdb->prepare(
            "SELECT start_time, end_time FROM $t_sched WHERE location_id = %d AND override_type = 'exclude' AND %s BETWEEN specific_date AND IFNULL(end_date, specific_date)",
            $location_id,
            $date_str
        ));

        // 5. Build candidate slots
        $candidate_slots = [];
        foreach ($shifts as $shift) {
            $shift_start = strtotime("$date_str {$shift->start_time}");
            $shift_end = strtotime("$date_str {$shift->end_time}");

            $curr = $shift_start;
            while (($curr + ($total_needed_mins * 60)) <= $shift_end) {
                $slot_start_dt = $curr;
                $slot_block_end_dt = $curr + ($total_needed_mins * 60);

                $excluded = false;
                foreach ($exclusions as $ex) {
                    $ex_start = strtotime("$date_str {$ex->start_time}");
                    $ex_end = strtotime("$date_str {$ex->end_time}");

                    if ($slot_start_dt < $ex_end && $slot_block_end_dt > $ex_start) {
                        $excluded = true;
                        break;
                    }
                }

                if (!$excluded) {
                    $candidate_slots[] = [
                        'start' => date('Y-m-d H:i:s', $slot_start_dt),
                        'end' => date('Y-m-d H:i:s', $slot_start_dt + ($duration * 60)),
                        'block_end' => date('Y-m-d H:i:s', $slot_block_end_dt)
                    ];
                }

                $curr += 15 * 60;
            }
        }

        // 6. Exclude booked or held slots
        $busy_blocks = self::get_busy_blocks($location_id, $date_str);
        $valid_slots = [];

        foreach ($candidate_slots as $slot) {
            $slot_start = strtotime($slot['start']);
            $slot_block_end = strtotime($slot['block_end']);
            $overlap = false;

            foreach ($busy_blocks as $busy) {
                if ($slot_start < strtotime($busy['end']) && $slot_block_end > strtotime($busy['start'])) {
                    $overlap = true;
                    break;
                }
            }

            if (!$overlap) {
                $valid_slots[] = [
                    'display' => date('H:i', $slot_start),
                    'start' => $slot['start'],
                    'end' => $slot['end']
                ];
            }
        }

        return $valid_slots;
    }

    public static function get_location_shifts_for_week($location_id, $week_start_date)
    {
        global $wpdb;
        $t_sched = $wpdb->prefix . 'bbe_schedules';
        $week_end_date = date('Y-m-d', strtotime($week_start_date . ' +6 days'));

        $shifts = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $t_sched WHERE location_id = %d AND (specific_date IS NULL OR (specific_date BETWEEN %s AND %s))",
            $location_id,
            $week_start_date,
            $week_end_date
        ));

        return $shifts ? $shifts : [];
    }

    private static function get_busy_blocks($location_id, $date_str)
    {
        global $wpdb;
        $busy = [];

        // 1. Fetch active temporary holds for this specific date
        $t_holds = $wpdb->prefix . 'bbe_holds';
        $holds = $wpdb->get_rows ? $wpdb->get_results($wpdb->prepare( // standard get_results
            "SELECT booking_start, booking_end FROM $t_holds WHERE location_id = %d AND DATE(booking_start) = %s AND expires_at > NOW()",
            $location_id,
            $date_str
        )) : [];

        foreach ($holds as $h) {
            $busy[] = ['start' => $h->booking_start, 'end' => $h->booking_end];
        }

        // 2. Fetch processing and completed orders (without restrictive date_created filtering)
        // We fetch orders and check their item meta booking start date directly.
        $orders = wc_get_orders([
            'limit' => 50, // Adjust or remove limit if high volume expected
            'status' => ['processing', 'completed'],
            'orderby' => 'date',
            'order' => 'DESC',
        ]);

        foreach ($orders as $order) {
            foreach ($order->get_items() as $item) {
                if (!is_a($item, 'WC_Order_Item_Product')) {
                    continue;
                }

                if ((int) $item->get_meta('_bbe_location_id') === $location_id) {
                    $start_time = $item->get_meta('_bbe_booking_start'); // e.g., '2026-11-17 09:00:00'

                    if (!empty($start_time)) {
                        // Check if this booked appointment falls on the target date being viewed ($date_str)
                        $appointment_date = substr($start_time, 0, 10);

                        if ($appointment_date === $date_str) {
                            $p_id = $item->get_product_id();
                            $v_id = $item->get_variation_id();
                            $target_id = $v_id ? $v_id : $p_id;

                            $dur = self::get_product_duration($target_id);
                            $buf = self::get_product_buffer($p_id, $v_id);

                            $end_ts = strtotime($start_time) + (($dur + $buf) * 60);

                            $busy[] = [
                                'start' => $start_time,
                                'end' => date('Y-m-d H:i:s', $end_ts)
                            ];
                        }
                    }
                }
            }
        }

        return $busy;
    }
}