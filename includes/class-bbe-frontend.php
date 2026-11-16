<?php
if (!defined('ABSPATH')) {
    exit;
}

class BBE_Frontend
{
    public static function init()
    {
        // 1. Conditional removal of native Add to Cart hooks based on product structure
        add_action('wp', [__CLASS__, 'manage_product_cart_hooks'], 20);

        // 2. Scoped CSS: hides native Add to Cart button & qty box ONLY for variable services
        add_action('wp_head', [__CLASS__, 'hide_service_add_to_cart_css']);

        // 3. Force quantity to 1 for service products
        add_filter('woocommerce_is_sold_individually', [__CLASS__, 'force_sold_individually'], 10, 2);

        // 4. Parse duration slug from variation
        add_filter('woocommerce_add_cart_item_data', [__CLASS__, 'capture_service_duration_meta'], 10, 3);

        // 5. Render appropriate booking flow buttons based on variant structure
        add_action('woocommerce_after_variations_table', [__CLASS__, 'render_variable_service_booking_flow']);
        add_action('woocommerce_single_product_summary', [__CLASS__, 'render_single_service_booking_flow'], 30);
    }

    /**
     * Determine whether the current product is a service
     */
    private static function is_service_product($product): bool
    {
        if (class_exists('BBE_Product_Type') && method_exists('BBE_Product_Type', 'is_service')) {
            return BBE_Product_Type::is_service($product);
        }
        return false;
    }
    /**
     * Determine whether the current product is a package
     */
    private static function is_package_product($product): bool
    {
        if (class_exists('BBE_Product_Type') && method_exists('BBE_Product_Type', 'is_package_product')) {
            return BBE_Product_Type::is_package_product($product);
        }
        return false;
    }

    /**
     * Determine whether the service product has multiple variations
     */
    private static function has_multiple_variations($product): bool
    {
        if (class_exists('BBE_Product_Type') && method_exists('BBE_Product_Type', 'has_multiple_variations')) {
            return BBE_Product_Type::has_multiple_variations($product);
        }
        return false;
    }

    /**
     * Manage cart hooks: 
     * - Physical products: untouched.
     * - Single flat services: remove standard summary add-to-cart so we can replace it with our direct button.
     * - Variable services: strip single variation add-to-cart button while preserving dropdowns.
     */
    public static function manage_product_cart_hooks()
    {
        if (!is_product()) {
            return;
        }

        $product = wc_get_product(get_queried_object_id());
        if (!self::is_service_product($product)) {
            return; // Physical product: do nothing, keep native ATB
        }

        if (self::has_multiple_variations($product)) {
            // Variable service: remove just the button inside the variation form
            remove_action('woocommerce_single_variation', 'woocommerce_single_variation_add_to_cart_button', 20);
        } else {
            // Single flat service (e.g. 15-min fixed): remove the native add to cart entirely
            remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30);
        }
    }

    /**
     * CSS failsafe: hides native Add to Basket button & qty box ONLY on variable service PDPs
     */
    public static function hide_service_add_to_cart_css()
    {
        if (!is_product()) {
            return;
        }

        $product = wc_get_product(get_queried_object_id());
        if (!self::is_service_product($product) || !self::has_multiple_variations($product)) {
            return;
        }
        ?>
        <style type="text/css">
            .single-product form.cart .single_add_to_cart_button,
            .single-product form.cart .quantity {
                display: none !important;
            }
        </style>
        <?php
    }

    /**
     * Force quantity to 1 for service items so they cannot be multiplied
     */
    public static function force_sold_individually($return, $product)
    {
        if (self::is_service_product($product)) {
            return true;
        }
        return $return;
    }

    /**
     * Parse duration slug ('30', '45', '60', etc.) from the selected variation attribute
     */
    public static function capture_service_duration_meta($cart_item_data, $product_id, $variation_id)
    {
        $target_id = $variation_id ? $variation_id : $product_id;
        $product = wc_get_product($target_id);

        if (!self::is_service_product($product)) {
            return $cart_item_data;
        }

        $duration_mins = 0;

        if ($product->is_type('variation')) {
            $attributes = $product->get_attributes();
            $raw_slug = '';
            foreach (['pa_duration', 'duration'] as $attr_key) {
                if (isset($attributes[$attr_key])) {
                    $raw_slug = $attributes[$attr_key];
                    break;
                }
            }

            if (empty($raw_slug) && is_array($attributes)) {
                $raw_slug = reset($attributes);
            }

            $clean_slug = sanitize_title($raw_slug);
            if (preg_match('/(\d+)/', $clean_slug, $matches)) {
                $duration_mins = intval($matches[1]);
            }
        }

        if ($duration_mins > 0) {
            $cart_item_data['bbe_duration'] = $duration_mins;
            $cart_item_data['_bbe_booking_duration'] = $duration_mins;
        }

        return $cart_item_data;
    }

    /**
     * Render direct, ALREADY ENABLED booking button for single services with no variants (e.g. 15 mins)
     */
    public static function render_single_service_booking_flow()
    {
        global $product;
        if (!self::is_service_product($product) || self::has_multiple_variations($product)) {
            return;
        }

        $booking_page_url = add_query_arg('product_id', $product->get_id(), home_url('/book-session/'));
        ?>
        <div id="bbe-single-service-booking-container" style="margin: 20px 0;">
            <a href="<?php echo esc_url($booking_page_url); ?>" id="bbe-book-session-btn" class="button alt"
                style="display: block; text-align: center; padding: 12px; font-size: 16px; background: #0288d1; color: #fff; text-decoration: none; border-radius: 4px; cursor: pointer;">
                <?php esc_html_e('Book my session', 'bbe'); ?>
            </a>
        </div>
        <?php
    }

    /**
     * Render DISABLED-BY-DEFAULT booking button for services with multiple variations
     */
    public static function render_variable_service_booking_flow()
    {
        global $product;
        if (!self::is_service_product($product) || !self::has_multiple_variations($product)) {
            return;
        }

        $booking_page_url = home_url('/book-session/');
        $is_package = self::is_package_product($product);
        if ($is_package) {
            $book_session_button_text = 'Book my first session';
        } else {
            $book_session_button_text = 'Book my session';
        }
        ?>
        <div id="bbe-service-booking-container ct-container" style="margin: 20px 0;">
            <button type="button" id="bbe-book-session-btn" class="button alt disabled" disabled="disabled"
                style="opacity: 0.5; cursor: not-allowed; width: 100%; text-align: center; padding: 12px; font-size: 16px;">
                <?php esc_html_e($book_session_button_text, 'bbe'); ?>
            </button>
        </div>

        <script type="text/javascript">
            jQuery(function ($) {
                const $form = $('form.variations_form');
                const $btn = $('#bbe-book-session-btn');
                const bookingBaseUrl = <?php echo wp_json_encode(esc_url_raw($booking_page_url)); ?>;

                if (!$form.length || !$btn.length) {
                    return;
                }

                function evaluateButtonState() {
                    let hasDurationSelected = false;

                    $form.find('.variations select').each(function () {
                        const name = $(this).attr('name') || '';
                        const val = $(this).val();
                        if (name.includes('duration') && val && val !== '') {
                            hasDurationSelected = true;
                            return false;
                        }
                    });

                    if (hasDurationSelected) {
                        $btn.removeClass('disabled')
                            .removeAttr('disabled')
                            .css({ 'opacity': '1', 'cursor': 'pointer' });
                    } else {
                        $btn.addClass('disabled')
                            .attr('disabled', 'disabled')
                            .css({ 'opacity': '0.5', 'cursor': 'not-allowed' });
                    }
                }

                $form.on('found_variation reset_data hide_variation check_variations change', 'select', function () {
                    setTimeout(evaluateButtonState, 50);
                });

                evaluateButtonState();

                $btn.on('click', function (e) {
                    e.preventDefault();
                    if ($(this).is(':disabled') || $(this).hasClass('disabled')) {
                        return;
                    }

                    let variationId = $form.find('input.variation_id').val();
                    const productId = $form.find('input[name="product_id"]').val() || <?php echo (int) $product->get_id(); ?>;

                    const url = new URL(bookingBaseUrl);
                    url.searchParams.set('product_id', productId);

                    if (variationId && variationId !== '0' && variationId !== '') {
                        url.searchParams.set('variation_id', variationId);
                    } else {
                        $form.find('.variations select').each(function () {
                            const name = $(this).attr('name');
                            const val = $(this).val();
                            if (name && val) {
                                url.searchParams.set(name, val);
                            }
                        });
                    }

                    window.location.href = url.toString();
                });
            });
        </script>
        <?php
    }

    public static function get_booking_request_context(): ?array
    {
        $variation_id = isset($_GET['variation_id']) ? absint($_GET['variation_id']) : 0;
        $product_id = isset($_GET['product_id']) ? absint($_GET['product_id']) : 0;

        // If no variation ID passed directly, check attributes or handle single flat service products
        if (!$variation_id && $product_id) {
            $product = wc_get_product($product_id);
            if ($product) {
                if ($product->is_type('variable')) {
                    $matched_attributes = [];
                    foreach ($_GET as $key => $value) {
                        if (strpos($key, 'attribute_') === 0) {
                            $matched_attributes[$key] = sanitize_text_field(wp_unslash($value));
                        }
                    }

                    if (!empty($matched_attributes)) {
                        $data_store = WC_Data_Store::load('product');
                        $found_variation_id = $data_store->find_matching_product_variation($product, $matched_attributes);
                        if ($found_variation_id) {
                            $variation_id = $found_variation_id;
                        }
                    }
                }
            }
        }

        // If it's a variation service
        if ($variation_id) {
            $variation = wc_get_product($variation_id);
            if (!$variation || !$variation->is_type('variation') || !self::is_service_product($variation)) {
                return null;
            }

            $parent_id = $variation->get_parent_id();
            $raw_duration = $variation->get_attribute('pa_duration') ?: $variation->get_attribute('duration');
            preg_match('/(\d+)/', sanitize_title($raw_duration), $matches);

            return [
                'product_id' => $parent_id,
                'variation_id' => $variation_id,
                'service_name' => $variation->get_name(),
                'price' => $variation->get_price(),
                'duration_mins' => isset($matches[1]) ? (int) $matches[1] : 15, // fallback to 15 if unparsed
                'buffer_mins' => (int) get_post_meta($parent_id, '_bbe_buffer', true),
                'location_override' => (int) get_post_meta($parent_id, '_bbe_location_override', true),
            ];
        }

        // Fallback context builder for flat single service products (e.g. 15-min fixed items with no variants)
        if ($product_id) {
            $product = wc_get_product($product_id);
            if ($product && self::is_service_product($product) && !self::has_multiple_variations($product)) {
                return [
                    'product_id' => $product_id,
                    'variation_id' => 0,
                    'service_name' => $product->get_name(),
                    'price' => $product->get_price(),
                    'duration_mins' => 15, // Default flat service duration
                    'buffer_mins' => (int) get_post_meta($product_id, '_bbe_buffer', true),
                    'location_override' => (int) get_post_meta($product_id, '_bbe_location_override', true),
                ];
            }
        }

        return null;
    }
}
BBE_Frontend::init();