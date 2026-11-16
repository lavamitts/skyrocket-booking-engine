<?php
if (!defined('ABSPATH'))
    exit;

class BBE_Availability
{
    public static function get_available_slots($product_id, $variation_id, $location_id, $date_str)
    {
        global $wpdb;

        // STRICT RULE: Reject any date that is today or in the past
        $today_str = current_time('Y-m-d');
        if ($date_str <= $today_str) {
            return [];
        }

        $target_id = $variation_id ? $variation_id : $product_id;
        $target_product = wc_get_product($target_id);

        if (!$target_product) {
            return [];
        }

        $duration = 0;
        if ($target_product->is_type('variation')) {
            $raw_duration = $target_product->get_attribute('pa_duration') ?: $target_product->get_attribute('duration');
            if (preg_match('/(\d+)/', sanitize_title($raw_duration), $matches)) {
                $duration = (int) $matches[1];
            }
        }

        if (!$duration) {
            $duration = (int) get_post_meta($target_id, '_bbe_duration', true);
        }

        // FAILSAFE: If it's a flat simple service product and still has no duration set, default to 15 mins
        if (!$duration && !$target_product->is_type('variable')) {
            $duration = 15;
        }

        $buffer = (int) get_post_meta($product_id, '_bbe_buffer', true);
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

        $t_holds = $wpdb->prefix . 'bbe_holds';
        $holds = $wpdb->get_results($wpdb->prepare(
            "SELECT booking_start, booking_end FROM $t_holds WHERE location_id = %d AND DATE(booking_start) = %s AND expires_at > NOW()",
            $location_id,
            $date_str
        ));
        foreach ($holds as $h) {
            $busy[] = ['start' => $h->booking_start, 'end' => $h->booking_end];
        }

        $orders = wc_get_orders([
            'limit' => -1,
            'status' => ['processing', 'completed'],
            'date_created' => $date_str . ' 00:00:00...' . $date_str . ' 23:59:59'
        ]);

        foreach ($orders as $order) {
            foreach ($order->get_items() as $item) {
                if ((int) $item->get_meta('_bbe_location_id') === $location_id) {
                    $start_time = $item->get_meta('_bbe_booking_start');
                    if (!empty($start_time)) {
                        $p_id = $item->get_product_id();
                        $v_id = $item->get_variation_id();

                        $target_id = $v_id ? $v_id : $p_id;
                        $target_prod = wc_get_product($target_id);
                        $dur = 0;
                        if ($target_prod && $target_prod->is_type('variation')) {
                            $raw_d = $target_prod->get_attribute('pa_duration') ?: $target_prod->get_attribute('duration');
                            if (preg_match('/(\d+)/', sanitize_title($raw_d), $m)) {
                                $dur = (int) $m[1];
                            }
                        }
                        if (!$dur) {
                            $dur = (int) get_post_meta($target_id, '_bbe_duration', true);
                        }
                        if (!$dur && !$target_prod->is_type('variable')) {
                            $dur = 15;
                        }

                        $buf = (int) get_post_meta($p_id, '_bbe_buffer', true);
                        $end_ts = strtotime($start_time) + (($dur + $buf) * 60);

                        $busy[] = [
                            'start' => $start_time,
                            'end' => date('Y-m-d H:i:s', $end_ts)
                        ];
                    }
                }
            }
        }

        return $busy;
    }
}