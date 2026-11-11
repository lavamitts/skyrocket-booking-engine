<?php
if (!defined('ABSPATH'))
    exit;

class BBE_Availability
{

    public static function get_available_slots($product_id, $location_id, $date_str)
    {
        global $wpdb;

        // STRICT RULE: Reject any date that is today or in the past
        $today_str = current_time('Y-m-d');
        if ($date_str <= $today_str) {
            return [];
        }

        $duration = (int) get_post_meta($product_id, '_bbe_duration', true);
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
                $shifts[] = $es; // Append extra shift into working pool
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

    /**
     * Fetch all shifts and overrides for a given location across a 7-day week window.
     * Required by the Admin Calendar Grid view to map operating vs. non-operating hours.
     */
    public static function get_location_shifts_for_week($location_id, $week_start_date)
    {
        global $wpdb;
        $t_sched = $wpdb->prefix . 'bbe_schedules';

        $week_end_date = date('Y-m-d', strtotime($week_start_date . ' +6 days'));

        // Fetch recurring weekly shifts + specific date overrides falling into or matching the week range
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
                        $dur = (int) get_post_meta($p_id, '_bbe_duration', true);
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