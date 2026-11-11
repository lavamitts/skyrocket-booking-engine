<?php
if (!defined('ABSPATH'))
    exit;

class BBE_Product_Type
{

    public static function init()
    {
        add_action('woocommerce_product_options_general_product_data', [__CLASS__, 'add_booking_fields']);
        add_action('woocommerce_process_product_meta', [__CLASS__, 'save_booking_fields']);
        add_action('admin_footer', [__CLASS__, 'enqueue_admin_toggle_js']);
    }

    public static function add_booking_fields()
    {
        echo '<div class="options_group bbe_booking_options">';

        woocommerce_wp_checkbox([
            'id' => '_bbe_is_service',
            'label' => __('Bookable Service?', 'bbe'),
            'description' => __('Check if this is a time-slot service, not a physical product.', 'bbe'),
        ]);

        // Wrapper to dynamically hide/show fields via JS
        echo '<div id="bbe_service_meta_wrapper">';

        woocommerce_wp_text_input([
            'id' => '_bbe_duration',
            'label' => __('Session Duration (mins)', 'bbe'),
            'type' => 'number',
            'custom_attributes' => ['step' => '5', 'min' => '0'],
            'desc_tip' => true,
            'description' => __('e.g., 60 for a 1-hour session.', 'bbe')
        ]);

        woocommerce_wp_text_input([
            'id' => '_bbe_buffer',
            'label' => __('Downtime / Prep Time (mins)', 'bbe'),
            'type' => 'number',
            'custom_attributes' => ['step' => '5', 'min' => '0'],
            'desc_tip' => true,
            'description' => __('Time required after session before the next booking can start.', 'bbe')
        ]);

        $locations = get_posts(['post_type' => 'bbe_location', 'numberposts' => -1]);
        $options = ['' => __('Inherit from Category', 'bbe')];
        foreach ($locations as $loc) {
            $options[$loc->ID] = $loc->post_title;
        }

        woocommerce_wp_select([
            'id' => '_bbe_location_override',
            'label' => __('Location Override', 'bbe'),
            'options' => $options,
            'description' => __('Override category-level location availability for this specific service.', 'bbe')
        ]);

        echo '</div>'; // #bbe_service_meta_wrapper
        echo '</div>'; // .bbe_booking_options
    }

    public static function save_booking_fields($post_id)
    {
        $is_service = isset($_POST['_bbe_is_service']) ? 'yes' : 'no';
        update_post_meta($post_id, '_bbe_is_service', $is_service);

        if ($is_service === 'yes') {
            update_post_meta($post_id, '_bbe_duration', !empty($_POST['_bbe_duration']) ? intval($_POST['_bbe_duration']) : 0);
            update_post_meta($post_id, '_bbe_buffer', !empty($_POST['_bbe_buffer']) ? intval($_POST['_bbe_buffer']) : 0);
            update_post_meta($post_id, '_bbe_location_override', isset($_POST['_bbe_location_override']) ? intval($_POST['_bbe_location_override']) : '');
        } else {
            // Clear or set defaults for non-service / physical products
            update_post_meta($post_id, '_bbe_duration', 0);
            update_post_meta($post_id, '_bbe_buffer', 0);
            update_post_meta($post_id, '_bbe_location_override', '');
        }
    }

    /**
     * Show/Hide duration fields depending on whether "Bookable Service?" is checked
     */
    public static function enqueue_admin_toggle_js()
    {
        $screen = get_current_screen();
        if ($screen && $screen->post_type === 'product') {
            ?>
            <script type="text/javascript">
                jQuery(document).ready(function ($) {
                    function toggleBookingFields() {
                        if ($('#_bbe_is_service').is(':checked')) {
                            $('#bbe_service_meta_wrapper').show();
                        } else {
                            $('#bbe_service_meta_wrapper').hide();
                        }
                    }
                    $('#_bbe_is_service').on('change', toggleBookingFields);
                    toggleBookingFields();
                });
            </script>
            <?php
        }
    }
}
BBE_Product_Type::init();