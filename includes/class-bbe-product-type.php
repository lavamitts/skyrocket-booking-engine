<?php
if (!defined('ABSPATH')) {
    exit;
}

class BBE_Product_Type
{
    const SERVICE_ROOT_SLUG = 'services';

    public static function init()
    {
        add_action('woocommerce_product_options_general_product_data', [__CLASS__, 'add_booking_fields']);
        add_action('woocommerce_process_product_meta', [__CLASS__, 'save_booking_fields']);
        add_action('admin_footer', [__CLASS__, 'enqueue_admin_toggle_js']);

        // Compatibility bridge for legacy service checks
        add_filter('get_post_metadata', [__CLASS__, 'filter_legacy_service_meta'], 10, 4);
    }

    /**
     * Canonical check: determines if a product is a service
     * based on category ancestry under the 'services' root.
     */
    public static function is_service($product): bool
    {
        // return (false);
        if (is_numeric($product)) {
            $product = wc_get_product($product);
        }

        if (!$product instanceof WC_Product) {
            return false;
        }

        $product_id = $product->is_type('variation') ? $product->get_parent_id() : $product->get_id();
        $terms = wp_get_post_terms($product_id, 'product_cat', ['fields' => 'all']);

        if (empty($terms) || is_wp_error($terms)) {
            return false;
        }

        $service_root = get_term_by('slug', self::SERVICE_ROOT_SLUG, 'product_cat');
        $service_root_id = $service_root ? (int) $service_root->term_id : 0;

        foreach ($terms as $term) {
            if ($term->slug === self::SERVICE_ROOT_SLUG) {
                return true;
            }
            if ($service_root_id && term_is_ancestor_of($service_root_id, (int) $term->term_id, 'product_cat')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Checks if a product is a variable product and has more than one variant created.
     */
    public static function has_multiple_variations($product): bool
    {
        if (is_numeric($product)) {
            $product = wc_get_product($product);
        }

        if (!$product instanceof WC_Product) {
            return false;
        }

        // Must be a variable product type
        if (!$product->is_type('variable')) {
            return false;
        }

        // Fetch children/variation IDs
        $children = $product->get_children();

        // Check if there are more than 1 variation items created
        return is_array($children) && count($children) > 1;
    }

    /**
     * Checks if the attribute 'package' is assigned to the product with the value 'yes'.
     */
    public static function is_package_product($product): bool
    {
        if (is_numeric($product)) {
            $product = wc_get_product($product);
        }

        if (!$product instanceof WC_Product) {
            return false;
        }

        // Check attributes for 'package' or 'pa_package'
        $attributes = $product->get_attributes();
        $package_val = '';

        foreach (['pa_package', 'package'] as $attr_key) {
            if (isset($attributes[$attr_key])) {
                $attr = $attributes[$attr_key];
                if (is_a($attr, 'WC_Product_Attribute')) {
                    $options = $attr->get_options();
                    // If it's a term-based attribute, get term slug/name
                    if ($attr->is_taxonomy() && !empty($options)) {
                        $term = get_term($options[0]);
                        if ($term && !is_wp_error($term)) {
                            $package_val = $term->slug;
                        }
                    } else {
                        // Custom string-based attribute
                        $package_val = reset($options);
                    }
                } elseif (is_string($attr)) {
                    $package_val = $attr;
                }
                break;
            }
        }

        // Fallback: check direct attribute method
        if (empty($package_val) && method_exists($product, 'get_attribute')) {
            $package_val = $product->get_attribute('package') ?: $product->get_attribute('pa_package');
        }

        return strtolower(trim((string) $package_val)) === 'yes';
    }

    public static function filter_legacy_service_meta($value, $object_id, $meta_key, $single)
    {
        if ($meta_key !== '_bbe_is_service') {
            return $value;
        }

        static $resolving = false;
        if ($resolving) {
            return $value;
        }

        if (get_post_type($object_id) !== 'product') {
            return $value;
        }

        $resolving = true;
        $derived = self::is_service($object_id) ? 'yes' : 'no';
        $resolving = false;

        return $single ? $derived : [$derived];
    }

    public static function add_booking_fields()
    {
        global $post;
        $is_service_by_cat = $post ? self::is_service($post->ID) : false;

        echo '<div class="options_group bbe_booking_options">';

        echo '<p class="form-field"><strong>' . esc_html__('Product Type:', 'bbe') . '</strong> ';
        echo $is_service_by_cat
            ? '<span style="color:#2e7d32;">' . esc_html__('Service (Derived from Services category)', 'bbe') . '</span>'
            : '<span style="color:#50575e;">' . esc_html__('Physical Product (Assign to a Services category to enable booking options)', 'bbe') . '</span>';
        echo '</p>';

        // Wrapper for parent-level service rules (Buffer & Location Override)
        echo '<div id="bbe_service_meta_wrapper" style="' . ($is_service_by_cat ? '' : 'display:none;') . '">';

        woocommerce_wp_text_input([
            'id' => '_bbe_buffer',
            'label' => __('Downtime / Prep Time (mins)', 'bbe'),
            'type' => 'number',
            'custom_attributes' => ['step' => '5', 'min' => '0'],
            'desc_tip' => true,
            'description' => __('Required turnaround time after any variation of this service before the next booking can start.', 'bbe')
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
            'description' => __('Override category-level location availability for this specific service line.', 'bbe')
        ]);

        echo '</div>'; // #bbe_service_meta_wrapper
        echo '</div>'; // .bbe_booking_options
    }

    public static function save_booking_fields($post_id)
    {
        delete_post_meta($post_id, '_bbe_is_service');

        if (self::is_service($post_id)) {
            update_post_meta($post_id, '_bbe_buffer', !empty($_POST['_bbe_buffer']) ? intval($_POST['_bbe_buffer']) : 0);
            update_post_meta($post_id, '_bbe_location_override', !empty($_POST['_bbe_location_override']) ? intval($_POST['_bbe_location_override']) : '');
        } else {
            update_post_meta($post_id, '_bbe_buffer', 0);
            update_post_meta($post_id, '_bbe_location_override', '');
        }
    }

    public static function enqueue_admin_toggle_js()
    {
        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== 'product') {
            return;
        }

        $service_root = get_term_by('slug', self::SERVICE_ROOT_SLUG, 'product_cat');
        $service_term_ids = [];

        if ($service_root && !is_wp_error($service_root)) {
            $service_term_ids = get_term_children((int) $service_root->term_id, 'product_cat');
            $service_term_ids[] = (int) $service_root->term_id;
        }
        ?>
        <script type="text/javascript">
            jQuery(function ($) {
                const serviceTermIds = <?php echo wp_json_encode(array_map('intval', $service_term_ids)); ?>;

                function syncBookingFieldsWithCategories() {
                    let isService = false;
                    $('#product_catchecklist input[type="checkbox"]:checked').each(function () {
                        const termId = parseInt($(this).val(), 10);
                        if (serviceTermIds.includes(termId)) {
                            isService = true;
                            return false;
                        }
                    });

                    if (isService) {
                        $('#bbe_service_meta_wrapper').show();
                    } else {
                        $('#bbe_service_meta_wrapper').hide();
                    }
                }

                $('#product_catchecklist').on('change', 'input[type="checkbox"]', syncBookingFieldsWithCategories);
                syncBookingFieldsWithCategories();
            });
        </script>
        <?php
    }
}
BBE_Product_Type::init();