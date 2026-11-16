<?php
if (!defined('ABSPATH'))
    exit;

class BBE_Shortcodes
{
    public static function init()
    {
        add_shortcode('bbe_booking_calendar', [__CLASS__, 'render_booking_calendar_shortcode']);

        // AJAX endpoint for fetching available slots in the calendar engine
        add_action('wp_ajax_bbe_get_slots', [__CLASS__, 'ajax_get_slots']);
        add_action('wp_ajax_nopriv_bbe_get_slots', [__CLASS__, 'ajax_get_slots']);

        // Enqueue frontend scripts & styles on the booking page
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_booking_assets']);
    }

    public static function enqueue_booking_assets()
    {
        // Enqueue on the page containing our shortcode or matching /book-session/ slug
        if (is_page('book-session') || (function_exists('has_shortcode') && is_singular() && has_shortcode(get_post()->post_content, 'bbe_booking_calendar'))) {
            // wp_enqueue_style('bbe-booking-css', BBE_URL . 'assets/css/booking.css', [], '2.0.1');
            wp_enqueue_script('bbe-booking-js', BBE_URL . 'assets/js/booking.js', ['jquery'], '2.0.1', true);

            wp_localize_script('bbe-booking-js', 'bbe_vars', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('bbe_booking_nonce')
            ]);
        }
    }

    public static function render_booking_calendar_shortcode($atts = [])
    {

        $context = BBE_Frontend::get_booking_request_context();
        if (!$context) {
            error_log("BBE DEBUG: get_booking_request_context() returned null for URL query: " . print_r($_GET, true));
        }

        if (!class_exists('BBE_Frontend') || !method_exists('BBE_Frontend', 'get_booking_request_context')) {
            return '<div class="bbe-notice bbe-error" style="padding: 20px; background: #fff3f3; border: 1px solid #ffcdd2; color: #c62828; border-radius: 4px;">' .
                esc_html__('Booking system context handler is unavailable.', 'bbe') . '</div>';
        }

        $context = BBE_Frontend::get_booking_request_context();

        if (!$context) {
            return '<div class="bbe-notice bbe-error" style="padding: 20px; background: #fff3f3; border: 1px solid #ffcdd2; color: #c62828; border-radius: 4px;">' .
                esc_html__('No valid service or duration was selected. Please return to the shop and select a service option.', 'bbe') . '</div>';
        }

        $service_name = $context['service_name'];
        $price = $context['price'];
        $duration_mins = $context['duration_mins'];
        $buffer_mins = $context['buffer_mins'];
        $variation_id = $context['variation_id'];
        $product_id = $context['product_id'];

        // For flat single services with no variations, add-to-cart needs the parent product ID.
        // For variable services, it needs the specific variation ID.
        $cart_add_id = $variation_id ? $variation_id : $product_id;

        // Determine Allowed Locations based on Product Override or Category Rules
        $locations = get_posts([
            'post_type' => 'bbe_location',
            'numberposts' => -1,
            'orderby' => 'title',
            'order' => 'ASC'
        ]);

        $product_location_override = get_post_meta($product_id, '_bbe_location_override', true);
        $allowed_location_ids = [];
        if (!empty($product_location_override)) {
            $allowed_location_ids = [$product_location_override];
        } else {
            $categories = wp_get_post_terms($product_id, 'product_cat', ['fields' => 'ids']);
            foreach ($categories as $cat_id) {
                $cat_locs = get_term_meta($cat_id, '_bbe_allowed_locations', true);
                if (is_array($cat_locs)) {
                    $allowed_location_ids = array_merge($allowed_location_ids, $cat_locs);
                }
            }
            $allowed_location_ids = array_unique($allowed_location_ids);
        }

        $valid_locations = [];
        foreach ($locations as $loc) {
            if (empty($allowed_location_ids) || in_array($loc->ID, $allowed_location_ids)) {
                $valid_locations[] = $loc;
            }
        }
        $valid_count = count($valid_locations);

        ob_start();
        ?>
        <div class="bbe-booking-calendar-wrapper" xstyle="max-width: 100%; margin: 30px auto; font-family: inherit;">

            <!-- Service Summary Card -->
            <div class="bbe-selected-service-summary ct-container"
                style="background: #f8f9fa; border: 1px solid #e9ecef; padding: 20px; border-radius: 6px; margin-bottom: 25px;">
                <h3 style="margin-top: 0; margin-bottom: 10px; font-size: 20px; color: #212529;">
                    <?php echo esc_html($service_name); ?>
                </h3>
                <div style="display: flex; gap: 20px; font-size: 14px; color: #495057;">
                    <div><strong><?php esc_html_e('Duration:', 'bbe'); ?></strong> <?php echo esc_html($duration_mins); ?>
                        <?php esc_html_e('mins', 'bbe'); ?>
                    </div>
                    <div><strong><?php esc_html_e('Price:', 'bbe'); ?></strong> <?php echo wc_price($price); ?></div>
                    <?php if ($buffer_mins > 0): ?>
                        <div><strong><?php esc_html_e('Turnaround Buffer:', 'bbe'); ?></strong>
                            <?php echo esc_html($buffer_mins); ?>             <?php esc_html_e('mins', 'bbe'); ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Booking Engine UI Container with Data Attributes -->
            <div id="bbe-booking-container" class="ct-container"
                style="background: #ffffff; border: 1px solid #dee2e6; padding: 30px; border-radius: 6px;"
                data-vertical-spacing="top:bottom" data-product-id="<?php echo esc_attr($product_id); ?>"
                data-variation-id="<?php echo esc_attr($variation_id); ?>">


                <!-- 1. Location Selection -->
                <div class="bbe-step-location"
                    style="margin-bottom: 25px; <?php echo ($valid_count === 1) ? 'display:none;' : ''; ?>">
                    <label class="bbe-label"
                        style="display:block; font-weight: bold; margin-bottom: 8px;"><?php _e('1. Select Location / Clinic', 'bbe'); ?></label>
                    <select id="bbe-location-select" name="bbe_location_id" class="bbe-select"
                        xstyle="width: 100%; padding: 10px; border-radius: 4px; border: 1px solid #ccc;">
                        <option value=""><?php _e('-- Choose Location --', 'bbe'); ?></option>
                        <?php foreach ($valid_locations as $loc): ?>
                            <option value="<?php echo $loc->ID; ?>" <?php selected($valid_count, 1); ?>>
                                <?php echo esc_html($loc->post_title); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php if ($valid_count === 1): ?>
                    <input type="hidden" id="bbe-location-select" name="bbe_location_id"
                        value="<?php echo esc_attr($valid_locations[0]->ID); ?>">
                <?php endif; ?>

                <!-- 2. Calendar Date & Slot Picker -->
                <?php
                if ($valid_count > 1) {
                    $select_date_text = '2. Select Date & Time Slot';
                } else {
                    $select_date_text = 'Select Date & Time Slot';


                }
                ?>
                <div class="bbe-step-calendar"
                    style="margin-bottom: 25px; <?php echo ($valid_count > 1) ? 'display:none;' : ''; ?>">
                    <label class="bbe-label"
                        style="display:block; font-weight: bold; margin-bottom: 8px;"><?php _e($select_date_text, 'bbe'); ?></label>

                    <div class="bbe-calendar-nav"
                        style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                        <button type="button" id="bbe-prev-week" class="button"
                            style="padding: 6px 12px; cursor: pointer;">&laquo;
                            <?php _e('Previous Week', 'bbe'); ?></button>
                        <span id="bbe-date-range-label" style="font-weight: bold; font-size: 14px;"></span>
                        <button type="button" id="bbe-next-week" class="button"
                            style="padding: 6px 12px; cursor: pointer;"><?php _e('Next Week', 'bbe'); ?> &raquo;</button>
                    </div>

                    <div id="bbe-slots-grid" class="bbe-slots-grid">
                        <p class="bbe-notice" style="text-align: center; color: #6c757d; margin-top: 70px;">
                            <?php _e('Please select a location above to view available slots.', 'bbe'); ?>
                        </p>
                    </div>
                </div>

                <!-- 3. WooCommerce Add to Cart Form Wrapper -->
                <div class="bbe-step-cart">
                    <form class="cart" method="post" action="<?php echo esc_url(wc_get_cart_url()); ?>"
                        enctype="multipart/form-data" id="bbe-cart-form"
                        style="display:none; border-top: 1px solid #e9ecef; padding-top: 20px;">
                        <input type="hidden" name="add-to-cart" value="<?php echo esc_attr($cart_add_id); ?>">
                        <input type="hidden" name="bbe_start_time" id="bbe_input_start_time">
                        <input type="hidden" name="bbe_location_id" id="bbe_input_location_id">

                        <div id="bbe-selected-summary" style="margin-bottom: 15px; font-size: 1.05em;">
                            <strong><?php _e('Selected Slot:', 'bbe'); ?></strong><br>
                            <span id="bbe-summary-text" style="color: #0288d1; font-weight: bold;"></span>
                        </div>

                        <button type="submit" class="single_add_to_cart_button button alt"
                            style="background: #0288d1; color: #fff; border: none; padding: 12px 30px; font-size: 16px; border-radius: 4px; cursor: pointer; width: 100%;">
                            <?php _e('Confirm Appointment & Add to Cart', 'bbe'); ?>
                        </button>
                    </form>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    public static function ajax_get_slots()
    {
        check_ajax_referer('bbe_booking_nonce', 'nonce');

        $product_id = intval($_POST['product_id']);
        $variation_id = intval($_POST['variation_id']);
        $location_id = intval($_POST['location_id']);
        $start_date = sanitize_text_field($_POST['start_date']);

        if (!$product_id || !$location_id || !$start_date) {
            wp_send_json_error(['message' => __('Invalid parameters.', 'bbe')]);
        }

        $week_days = [];
        $base_ts = strtotime($start_date);

        for ($i = 0; $i < 7; $i++) {
            $day_ts = strtotime("+$i days", $base_ts);
            $date_str = date('Y-m-d', $day_ts);

            // Fetch slots using the availability engine passing variation context
            $slots = BBE_Availability::get_available_slots($product_id, $variation_id, $location_id, $date_str);

            $week_days[] = [
                'date' => $date_str,
                'formatted' => date('D, M j', $day_ts),
                'slots' => $slots
            ];
        }

        wp_send_json_success([
            'week_days' => $week_days,
            'range' => html_entity_decode(
                date('M j, Y', $base_ts) . ' &mdash; ' . date('M j, Y', strtotime('+6 days', $base_ts))
            )
        ]);
    }
}

BBE_Shortcodes::init();