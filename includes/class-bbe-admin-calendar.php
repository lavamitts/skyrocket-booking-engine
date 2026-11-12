<?php
if (!defined('ABSPATH'))
    exit;

class BBE_Admin_Calendar
{

    public static function init()
    {
        add_action('admin_menu', [__CLASS__, 'register_calendar_page']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_admin_assets']);
    }

    public static function register_calendar_page()
    {
        add_submenu_page(
            'woocommerce', // Moved under WooCommerce top-level menu
            __('Booking Calendar', 'bbe'),
            __('Booking Calendar', 'bbe'),
            'manage_woocommerce',
            'bbe-booking-calendar',
            [__CLASS__, 'render_calendar_page']
        );
    }

    public static function enqueue_admin_assets($hook)
    {
        wp_enqueue_style('bbe-admin-calendar-css', BBE_URL . 'assets/css/admin/admin.css', [], '1.0.0');
        if (strpos($hook, 'bbe-booking-calendar') === false) {
            return;
        }
        wp_enqueue_script('bbe-admin-calendar-js', BBE_URL . 'assets/js/admin/admin-calendar.js', ['jquery'], '1.0.0', true);
    }

    public static function render_calendar_page()
    {
        global $wpdb;

        // Define grid row height variable to match SCSS $hour-height (90px = 1.5px per minute)
        $hour_height = 90;
        $scale_factor = $hour_height / 60; // 2px per minute scaling factor

        // Get filter inputs
        $location_id = isset($_GET['location_id']) ? intval($_GET['location_id']) : 0;
        $view_mode = isset($_GET['view_mode']) ? sanitize_text_field($_GET['view_mode']) : 'grid';
        $week_offset = isset($_GET['week_offset']) ? intval($_GET['week_offset']) : 0;

        // Fetch all locations for dropdown
        $locations = get_posts([
            'post_type' => 'bbe_location',
            'numberposts' => -1,
            'orderby' => 'title',
            'order' => 'ASC'
        ]);

        if (!$location_id && !empty($locations)) {
            $location_id = $locations[0]->ID;
        }

        // Calculate Week Start (Monday) and End (Sunday) based on offset
        $start_of_week_ts = strtotime("Monday this week +{$week_offset} weeks");
        $end_of_week_ts = strtotime("Sunday this week +{$week_offset} weeks 23:59:59");

        $week_start_str = date('Y-m-d', $start_of_week_ts);
        $week_end_str = date('Y-m-d', $end_of_week_ts);

        // Fetch Booked Orders for this location & week
        $bookings = self::get_week_bookings($location_id, $week_start_str, $week_end_str);

        // Fetch scheduled shifts for this location to map operating vs non-operating hours in View 1
        $shifts = BBE_Availability::get_location_shifts_for_week($location_id, $week_start_str);
        ?>
        <div class="wrap bbe-calendar-wrap">
            <h1>
                <?php _e('Clinic Booking Calendar', 'bbe'); ?>
            </h1>

            <!-- Top Filter & Toggle Toolbar -->
            <form method="get" action="" class="bbe-toolbar">
                <input type="hidden" name="page" value="bbe-booking-calendar">
                <input type="hidden" name="view_mode" id="bbe-view-mode-input" value="<?php echo esc_attr($view_mode); ?>">
                <input type="hidden" name="week_offset" id="bbe-week-offset" value="<?php echo esc_attr($week_offset); ?>">

                <div class="bbe-toolbar-group">
                    <label for="bbe-location-filter"><strong>
                            <?php _e('Select Clinic:', 'bbe'); ?>
                        </strong></label>
                    <select name="location_id" id="bbe-location-filter" onchange="this.form.submit()">
                        <?php foreach ($locations as $loc): ?>
                            <option value="<?php echo $loc->ID; ?>" <?php selected($location_id, $loc->ID); ?>>
                                <?php echo esc_html($loc->post_title); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="bbe-toolbar-group bbe-week-navigator">
                    <button type="submit" name="week_offset" value="<?php echo $week_offset - 1; ?>" class="button">&laquo;
                        <?php _e('Previous Week', 'bbe'); ?>
                    </button>
                    <span class="bbe-week-label">
                        <?php echo date('M j, Y', $start_of_week_ts); ?> &mdash;
                        <?php echo date('M j, Y', $end_of_week_ts); ?>
                    </span>
                    <button type="submit" name="week_offset" value="<?php echo $week_offset + 1; ?>" class="button">
                        <?php _e('Next Week', 'bbe'); ?> &raquo;
                    </button>
                    <?php if ($week_offset !== 0): ?>
                        <a href="admin.php?page=bbe-booking-calendar&location_id=<?php echo $location_id; ?>&view_mode=<?php echo esc_attr($view_mode); ?>"
                            class="button">
                            <?php _e('Current Week', 'bbe'); ?>
                        </a>
                    <?php endif; ?>
                </div>

                <div class="bbe-toolbar-group bbe-view-toggle">
                    <button type="button" class="button <?php echo ($view_mode === 'grid') ? 'button-primary' : ''; ?>"
                        onclick="setBbeView('grid')">
                        <?php _e('Grid View', 'bbe'); ?>
                    </button>
                    <button type="button" class="button <?php echo ($view_mode === 'list') ? 'button-primary' : ''; ?>"
                        onclick="setBbeView('list')">
                        <?php _e('List View', 'bbe'); ?>
                    </button>
                </div>
            </form>

            <!-- VIEW 1: Tabular Grid Calendar -->
            <div id="bbe-view-grid" class="bbe-view-container"
                style="<?php echo ($view_mode === 'grid') ? '' : 'display:none;'; ?>">
                <div class="bbe-grid-timetable">
                    <div class="bbe-time-col-header"></div>
                    <?php
                    for ($i = 0; $i < 7; $i++) {
                        $day_ts = strtotime("+$i days", $start_of_week_ts);
                        echo '<div class="bbe-day-col-header"><strong>' . date('D', $day_ts) . '</strong><br><span>' . date('M j', $day_ts) . '</span></div>';
                    }

                    // Render hourly rows from 08:00 to 20:00 cleanly without skipping DOM nodes
                    for ($hour = 8; $hour <= 20; $hour++):
                        $time_label = sprintf('%02d:00', $hour);
                        ?>
                        <div class="bbe-time-label" style="height: <?php echo intval($hour_height); ?>px;">
                            <?php echo $time_label; ?>
                        </div>
                        <?php for ($d = 0; $d < 7; $d++):
                            $slot_date = date('Y-m-d', strtotime("+$d days", $start_of_week_ts));
                            $is_working = self::is_working_hour($location_id, $slot_date, $hour, $shifts);

                            // Explicitly check cell state: true = working, 'exception' = blocked/unavailable, false = closed
                            if ($is_working === true) {
                                $cell_class = 'bbe-working-hour';
                            } elseif ($is_working === 'exception') {
                                $cell_class = 'bbe-exception-hour';
                            } else {
                                $cell_class = 'bbe-closed-hour';
                            }
                            ?>
                            <div class="bbe-time-cell <?php echo $cell_class; ?>" data-date="<?php echo $slot_date; ?>"
                                data-hour="<?php echo $hour; ?>"
                                style="height: <?php echo intval($hour_height); ?>px; min-height: <?php echo intval($hour_height); ?>px;">
                                <?php
                                // Output bookings whose start time matches this specific date and hour row
                                foreach ($bookings as $b) {
                                    $b_date = $b['date'];
                                    $b_start_hour = intval(date('H', strtotime($b['start_time'])));
                                    $b_start_min = intval(date('i', strtotime($b['start_time'])));

                                    if ($b_date === $slot_date && $b_start_hour === $hour) {
                                        $duration_mins = intval($b['duration']);

                                        $chip_height = ($duration_mins * $scale_factor) - 8;
                                        $top_offset = ($b_start_min / 60) * ($hour_height);

                                        $chip_style = 'height: ' . max(40, $chip_height) . 'px; top: ' . ($top_offset + 4) . 'px;';

                                        // Strict slug check based on confirmed database values ('cod' vs 'stripe_cc')
                                        $payment_method = trim(strtolower($b['payment_method']));
                                        $is_cod = ($payment_method === 'cod');

                                        $payment_label = $is_cod ? __('TO PAY', 'bbe') : __('PAID', 'bbe');
                                        $payment_class = $is_cod ? 'bbe-pay-arrival' : 'bbe-pay-paid';

                                        echo '<div class="bbe-grid-booking-chip" style="' . esc_attr($chip_style) . '" title="' . esc_attr($b['service_name'] . ' - ' . $b['customer_name'] . ' (' . $b['duration'] . 'm)') . '">';
                                        echo '<span class="block bold navy">' . esc_html($b['service_name']) . '</span>';
                                        echo '<span class="block">' . esc_html($b['customer_name']) . ' (' . esc_html($b['duration']) . 'm)</span>';
                                        echo '<div class="bbe-grid-booking-chip__order-payment">';
                                        echo '<a href="' . esc_url($b['order_url']) . '" target="_blank">Order #' . $b['order_id'] . '</a>';
                                        echo '<span class="bbe-payment-badge ' . esc_attr($payment_class) . '">' . esc_html($payment_label) . '</span>';
                                        echo '</div>';
                                        echo '</div>';
                                    }
                                }
                                ?>
                            </div>
                        <?php endfor; ?>
                    <?php endfor; ?>
                </div>
            </div>

            <!-- VIEW 2: Sequential List View -->
            <div id="bbe-view-list" class="bbe-view-container"
                style="<?php echo ($view_mode === 'list') ? '' : 'display:none;'; ?>">
                <div class="bbe-list-timeline">
                    <?php
                    for ($i = 0; $i < 7; $i++):
                        $day_ts = strtotime("+$i days", $start_of_week_ts);
                        $day_date_str = date('Y-m-d', $day_ts);
                        $day_bookings = array_filter($bookings, function ($b) use ($day_date_str) {
                            return $b['date'] === $day_date_str;
                        });
                        ?>
                        <div class="bbe-list-day-card">
                            <h3>
                                <?php echo date('l, F j, Y', $day_ts); ?>
                            </h3>
                            <?php if (empty($day_bookings)): ?>
                                <p class="bbe-no-bookings">
                                    <?php _e('No bookings scheduled for this day.', 'bbe'); ?>
                                </p>
                            <?php else: ?>
                                <table class="wp-list-table widefat fixed striped bbe-booking-calendar-list-view-table">
                                    <thead>
                                        <tr>
                                            <th>
                                                <?php _e('Time & Duration', 'bbe'); ?>
                                            </th>
                                            <th>
                                                <?php _e('Service Name', 'bbe'); ?>
                                            </th>
                                            <th>
                                                <?php _e('Customer Name', 'bbe'); ?>
                                            </th>
                                            <th>
                                                <?php _e('Order Link', 'bbe'); ?>
                                            </th>
                                            <th>
                                                <?php _e('Status', 'bbe'); ?>
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($day_bookings as $b):
                                            // Determine payment status for list view
                                            $payment_method = trim(strtolower($b['payment_method']));
                                            $is_cod = ($payment_method === 'cod');
                                            $payment_label = $is_cod ? __('TO PAY', 'bbe') : __('PAID', 'bbe');
                                            $payment_class = $is_cod ? 'bbe-pay-arrival' : 'bbe-pay-paid';
                                            ?>
                                            <tr>
                                                <td><strong>
                                                        <?php echo date('H:i', strtotime($b['start_time'])); ?> &mdash;
                                                        <?php echo date('H:i', strtotime($b['end_time'])); ?>
                                                    </strong> (<?php echo $b['duration']; ?> mins)
                                                </td>
                                                <td>
                                                    <?php echo esc_html($b['service_name']); ?>
                                                </td>
                                                <td>
                                                    <?php
                                                    $customer_name = esc_html($b['customer_name']);
                                                    $customer_email = esc_html($b['customer_email']);

                                                    if (!empty($customer_email)) {
                                                        // Link to WooCommerce orders list filtered by this customer's email
                                                        $admin_url = admin_url('edit.php?post_type=shop_order&_customer_user=' . urlencode($customer_email));
                                                        echo '<a href="' . esc_url($admin_url) . '" title="' . esc_attr($customer_email) . '">' . $customer_name . '</a>';
                                                    } else {
                                                        echo $customer_name;
                                                    }
                                                    ?>
                                                </td>
                                                <td>
                                                    <a href="<?php echo esc_url($b['order_url']); ?>" target="_blank"
                                                        class="button button-small">
                                                        <?php _e('View Order #', 'bbe'); ?>
                                                        <?php echo $b['order_id']; ?>
                                                    </a>
                                                </td>
                                                <td>
                                                    <span class="bbe-payment-badge <?php echo esc_attr($payment_class); ?>"
                                                        style="margin-left: 6px;">
                                                        <?php echo esc_html($payment_label); ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </div>
                    <?php endfor; ?>
                </div>
            </div>

        </div>
        <script>
            function setBbeView(mode) {
                document.getElementById('bbe-view-mode-input').value = mode;
                document.forms[0].submit();
            }
        </script>
        <?php
    }

    /**
     * Helper to query orders containing bookings for a specific location and date range
     */
    private static function get_week_bookings($location_id, $start_date, $end_date)
    {
        $bookings = [];

        $orders = wc_get_orders([
            'limit' => -1,
            'status' => ['wc-processing', 'wc-completed', 'wc-on-hold'],
            'date_created' => '>' . (strtotime('-90 days'))
        ]);

        foreach ($orders as $order) {
            foreach ($order->get_items() as $item_id => $item) {

                // 1. Try checking structured array meta (_bbe_booking)
                $booking_meta = wc_get_order_item_meta($item_id, '_bbe_booking', true);

                // 2. Fallback: check individual item meta fields if structured array is missing
                if (empty($booking_meta)) {
                    $item_loc_id = wc_get_order_item_meta($item_id, '_bbe_location_id', true);
                    $item_start = wc_get_order_item_meta($item_id, '_bbe_booking_start', true);

                    if (!empty($item_start) && !empty($item_loc_id)) {
                        $booking_meta = [
                            'location_id' => intval($item_loc_id),
                            'start' => $item_start
                        ];
                    }
                }

                if ($booking_meta && isset($booking_meta['location_id']) && intval($booking_meta['location_id']) === intval($location_id)) {
                    $start_dt = $booking_meta['start'];
                    $b_date = date('Y-m-d', strtotime($start_dt));

                    if ($b_date >= $start_date && $b_date <= $end_date) {
                        /** @var WC_Order_Item_Product $item */
                        $product = $item->get_product();
                        $duration = $product ? intval($product->get_meta('_bbe_duration', true)) : 30;
                        if (!$duration)
                            $duration = 30;

                        $end_dt = date('Y-m-d H:i:s', strtotime($start_dt . " +{$duration} minutes"));

                        $bookings[] = [
                            'date' => $b_date,
                            'start_time' => $start_dt,
                            'end_time' => $end_dt,
                            'duration' => $duration,
                            'service_name' => $item->get_name(),
                            'customer_name' => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
                            'customer_email' => $order->get_billing_email(),
                            'order_id' => $order->get_id(),
                            'order_url' => $order->get_edit_order_url(),
                            'payment_method' => $order->get_payment_method()
                        ];
                    }
                }
            }
        }

        // Sort bookings chronologically
        usort($bookings, function ($a, $b) {
            return strcmp($a['start_time'], $b['start_time']);
        });

        return $bookings;
    }

    /**
     * Check if a given hour falls into an active working shift, factoring in specific date exceptions/exclusions.
     */
    private static function is_working_hour($location_id, $date_str, $hour, $shifts)
    {
        global $wpdb;
        $t_sched = $wpdb->prefix . 'bbe_schedules';

        // 1. Check for specific date exceptions
        $query = $wpdb->prepare(
            "SELECT start_time, end_time FROM {$t_sched} WHERE location_id = %d AND override_type = 'exclude' AND specific_date = %s",
            $location_id,
            $date_str
        );
        $exclusion = $wpdb->get_row($query);

        if ($exclusion) {
            if (!empty($exclusion->start_time) && $exclusion->start_time !== '00:00:00') {
                $ex_start_h = intval(substr($exclusion->start_time, 0, 2));
                $ex_end_h = intval(substr($exclusion->end_time, 0, 2));
                $ex_end_m = intval(substr($exclusion->end_time, 3, 2));

                $is_in_exception = ($ex_end_m > 0)
                    ? ($hour >= $ex_start_h && $hour <= $ex_end_h)
                    : ($hour >= $ex_start_h && $hour < $ex_end_h);

                if ($is_in_exception) {
                    return 'exception';
                }
            } else {
                return 'exception';
            }
        }

        // 2. Fall back to standard weekly recurring shifts for this location
        $day_of_week = strtolower(date('D', strtotime($date_str)));
        $shift_query = $wpdb->prepare(
            "SELECT start_time, end_time FROM {$t_sched} WHERE location_id = %d AND day_of_week = %s AND override_type = 'shift'",
            $location_id,
            $day_of_week
        );
        $matching_shifts = $wpdb->get_results($shift_query);

        if (empty($matching_shifts)) {
            return false;
        }

        foreach ($matching_shifts as $shift) {
            $start_h = intval(substr($shift->start_time, 0, 2));
            $end_h = intval(substr($shift->end_time, 0, 2));
            $end_m = intval(substr($shift->end_time, 3, 2));

            $max_active_hour = ($end_m > 0) ? $end_h : $end_h - 1;

            if ($hour >= $start_h && $hour <= $max_active_hour) {
                return true;
            }
        }

        return false;
    }
}
BBE_Admin_Calendar::init();