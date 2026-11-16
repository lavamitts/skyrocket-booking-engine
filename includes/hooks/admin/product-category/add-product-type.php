<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * WooCommerce Product Category – Service Classification
 *
 * Adds a custom category-level setting to identify product categories
 * containing services rather than physical products.
 *
 * Functionality:
 * - Adds a checkbox to the Add New Product Category screen.
 * - Adds an editable checkbox to the Edit Product Category screen.
 * - Saves the setting as WooCommerce product category term metadata.
 * - Makes the classification available to other plugin components
 *   for service-specific functionality and conditional behaviour.
 *
 * Metadata:
 * - Key: bbe_is_service_category
 * - Values: 'yes' or 'no'
 *
 * WordPress Hooks:
 * - product_cat_add_form_fields
 * - product_cat_edit_form_fields
 * - created_product_cat
 * - edited_product_cat
 *
 * Usage:
 * Other components can check whether a product category is classified
 * as a service using:
 *
 * get_term_meta($term_id, 'bbe_is_service_category', true);
 *
 * Note:
 * A category must contain either services or physical products,
 * not a mixture of both.
 *
 * Text Domain: bbe
 */
if (!defined('ABSPATH')) {
    exit;
}

/**
 * 1. Add custom field to "Add New Product Category" screen
 */
add_action('product_cat_add_form_fields', function () {
    ?>
    <div class="form-field">
        <label for="bbe_is_service_category">
            <input type="checkbox" name="bbe_is_service_category" id="bbe_is_service_category" value="yes">
            <?php esc_html_e('Category contains services?', 'bbe'); ?>
        </label>
        <p>
            <?php esc_html_e('Check this if products in this category are services rather than physical stock.', 'bbe'); ?>
        </p>
    </div>
    <?php
});

/**
 * 2. Add custom field to "Edit Product Category" screen
 */
add_action('product_cat_edit_form_fields', function ($term) {
    $is_service = get_term_meta($term->term_id, 'bbe_is_service_category', true);
    ?>
    <tr class="form-field">
        <th scope="row" valign="top">
            <label for="bbe_is_service_category">
                <?php esc_html_e('Category contains services?', 'bbe'); ?>
            </label>
        </th>
        <td>
            <label>
                <input type="checkbox" name="bbe_is_service_category" id="bbe_is_service_category" value="yes" <?php checked($is_service, 'yes'); ?>>
                <?php esc_html_e('Treat products in this category as services', 'bbe'); ?>
            </label>
            <p class="description">
                <?php esc_html_e('Enables booking logic, removes add-to-cart buttons on archives, and triggers service-specific email workflows. A catgegory may not contain items which are both services and physical products', 'bbe'); ?>
            </p>
        </td>
    </tr>
    <?php
});

/**
 * 3. Save the custom category meta field
 */
add_action('created_product_cat', 'bbe_save_product_cat_service_meta');
add_action('edited_product_cat', 'bbe_save_product_cat_service_meta');

function bbe_save_product_cat_service_meta($term_id)
{
    $is_service = isset($_POST['bbe_is_service_category']) ? 'yes' : 'no';
    update_term_meta($term_id, 'bbe_is_service_category', $is_service);
}