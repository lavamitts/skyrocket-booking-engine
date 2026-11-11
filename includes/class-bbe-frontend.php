<?php
if (!defined('ABSPATH'))
    exit;

class BBE_Frontend
{

    public static function init()
    {
        // Hook into Blocksy's full-width slot right before the product tabs
        add_action('woocommerce_after_single_product_summary', [__CLASS__, 'render_booking_widget'], 5);

        // Dynamically remove standard add-to-cart ONLY if the current product is a bookable service
        add_action('wp', [__CLASS__, 'conditional_remove_native_cart']);

        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_assets']);

        add_action('wp_ajax_bbe_get_slots', [__CLASS__, 'ajax_get_slots']);
        add_action('wp_ajax_nopriv_bbe_get_slots', [__CLASS__, 'ajax_get_slots']);
    }

    /**
     * Check if current single product is a bookable service. If so, remove native add-to-cart.
     */
    public static function conditional_remove_native_cart()
    {
        if (is_product()) {
            global $post;
            $is_service = get_post_meta($post->ID, '_bbe_is_service', true) === 'yes';
            if ($is_service) {
                remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30);
            }
        }
    }

    public static function enqueue_assets()
    {
        if (is_product()) {
            global $post;
            $is_service = get_post_meta($post->ID, '_bbe_is_service', true) === 'yes';
            if ($is_service) {
                wp_enqueue_style('bbe-booking-css', BBE_URL . 'assets/css/booking.css', [], '1.0.6');
                wp_enqueue_script('bbe-booking-js', BBE_URL . 'assets/js/booking.js', ['jquery'], '1.0.6', true);

                wp_localize_script('bbe-booking-js', 'bbe_vars', [
                    'ajax_url' => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce('bbe_booking_nonce')
                ]);
            }
        }
    }

    public static function render_booking_widget()
    {
        global $product;
        if (!$product)
            return;

        $is_service = get_post_meta($product->get_id(), '_bbe_is_service', true) === 'yes';
        if (!$is_service)
            return; // Physical products bypass this entirely and use native UI

        $locations = get_posts([
            'post_type' => 'bbe_location',
            'numberposts' => -1,
            'orderby' => 'title',
            'order' => 'ASC'
        ]);

        $product_location_override = get_post_meta($product->get_id(), '_bbe_location_override', true);

        $allowed_location_ids = [];
        if (!empty($product_location_override)) {
            $allowed_location_ids = [$product_location_override];
        } else {
            $categories = wp_get_post_terms($product->get_id(), 'product_cat', ['fields' => 'ids']);
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
        ?>

        <!-- Full-Width Booking Engine inside Blocksy layout -->
        <div class="ct-container" style="clear: both; width: 100%; margin-top: 40px; margin-bottom: 40px;">
            <div id="bbe-booking-container" data-product-id="<?php echo esc_attr($product->get_id()); ?>">

                <h2><?php _e('Book Your Appointment', 'bbe'); ?></h2>

                <!-- 1. Location Selection -->
                <div class="bbe-step-location"
                    style="margin-bottom: 25px; <?php echo ($valid_count === 1) ? 'display:none;' : ''; ?>">
                    <label class="bbe-label"><?php _e('1. Select Location / Clinic', 'bbe'); ?></label>
                    <select id="bbe-location-select" name="bbe_location_id" class="bbe-select">
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
                        value="<?php echo $valid_locations[0]->ID; ?>">
                <?php endif; ?>

                <!-- 2. Calendar Date & Slot Picker -->
                <div class="bbe-step-calendar"
                    style="margin-bottom: 25px; <?php echo ($valid_count > 1) ? 'display:none;' : ''; ?>">
                    <label class="bbe-label"><?php _e('2. Select Date & Time Slot', 'bbe'); ?></label>

                    <div class="bbe-calendar-nav">
                        <button type="button" id="bbe-prev-week" class="button">&laquo;
                            <?php _e('Previous Week', 'bbe'); ?></button>
                        <span id="bbe-date-range-label" style="font-weight: bold; font-size: 14px;"></span>
                        <button type="button" id="bbe-next-week" class="button"><?php _e('Next Week', 'bbe'); ?>
                            &raquo;</button>
                    </div>

                    <div id="bbe-slots-grid" class="bbe-slots-grid">
                        <p class="bbe-notice"><?php _e('Please select a location above to view available slots.', 'bbe'); ?></p>
                    </div>
                </div>

                <!-- 3. WooCommerce Add to Cart Form Wrapper (Naturally below selection tools) -->
                <div class="bbe-step-cart">
                    <form class="cart" method="post" enctype="multipart/form-data" id="bbe-cart-form" style="display:none;">
                        <input type="hidden" name="add-to-cart" value="<?php echo esc_attr($product->get_id()); ?>">
                        <input type="hidden" name="bbe_start_time" id="bbe_input_start_time">
                        <input type="hidden" name="bbe_location_id" id="bbe_input_location_id">

                        <div id="bbe-selected-summary" style="margin-bottom: 15px; font-size: 1.05em;">
                            <strong><?php _e('Selected Slot:', 'bbe'); ?></strong><br>
                            <span id="bbe-summary-text" style="color: #007cba; font-weight: bold;"></span>
                        </div>

                        <button type="submit" class="single_add_to_cart_button button alt">
                            <?php echo esc_html($product->single_add_to_cart_text()); ?>
                        </button>
                    </form>
                </div>

            </div>
        </div>
        <?php
    }

    public static function ajax_get_slots()
    {
        check_ajax_referer('bbe_booking_nonce', 'nonce');

        $product_id = intval($_POST['product_id']);
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

            $slots = BBE_Availability::get_available_slots($product_id, $location_id, $date_str);

            $week_days[] = [
                'date' => $date_str,
                'formatted' => date('D, M j', $day_ts),
                'slots' => $slots
            ];
        }

        wp_send_json_success([
            'week_days' => $week_days,
            'range' => date('M j, Y', $base_ts) . ' &mdash; ' . date('M j, Y', strtotime('+6 days', $base_ts))
        ]);
    }
}
BBE_Frontend::init();