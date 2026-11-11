<?php
if (!defined('ABSPATH'))
    exit;

class BBE_Locations
{

    public static function init()
    {
        add_action('init', [__CLASS__, 'register_location_cpt']);
        add_action('product_cat_add_form_fields', [__CLASS__, 'add_category_location_fields']);
        add_action('product_cat_edit_form_fields', [__CLASS__, 'edit_category_location_fields']);
        add_action('edited_product_cat', [__CLASS__, 'save_category_location_fields']);
        add_action('create_product_cat', [__CLASS__, 'save_category_location_fields']);
    }

    public static function register_location_cpt()
    {
        register_post_type('bbe_location', [
            'labels' => [
                'name' => __('Locations', 'bbe'),
                'singular_name' => __('Location', 'bbe'),
            ],
            'public' => true,
            'show_in_menu' => true,
            'supports' => ['title', 'editor'],
            'menu_icon' => 'dashicons-location',
        ]);
    }

    // Category Settings: Select allowed locations for this service category
    public static function add_category_location_fields()
    {
        $locations = get_posts(['post_type' => 'bbe_location', 'numberposts' => -1]);
        ?>
        <div class="form-field">
            <label>
                <?php _e('Allowed Locations', 'bbe'); ?>
            </label>
            <?php foreach ($locations as $loc): ?>
                <label><input type="checkbox" name="bbe_allowed_locations[]" value="<?php echo $loc->ID; ?>">
                    <?php echo esc_html($loc->post_title); ?>
                </label><br>
            <?php endforeach; ?>
        </div>
        <?php
    }

    public static function edit_category_location_fields($term)
    {
        $locations = get_posts(['post_type' => 'bbe_location', 'numberposts' => -1]);
        $saved = get_term_meta($term->term_id, '_bbe_allowed_locations', true) ?: [];
        ?>
        <tr class="form-field">
            <th scope="row"><label>
                    <?php _e('Allowed Locations', 'bbe'); ?>
                </label></th>
            <td>
                <?php foreach ($locations as $loc): ?>
                    <label>
                        <input type="checkbox" name="bbe_allowed_locations[]" value="<?php echo $loc->ID; ?>" <?php checked(in_array($loc->ID, $saved)); ?>>
                        <?php echo esc_html($loc->post_title); ?>
                    </label><br>
                <?php endforeach; ?>
            </td>
        </tr>
        <?php
    }

    public static function save_category_location_fields($term_id)
    {
        $locations = isset($_POST['bbe_allowed_locations']) ? array_map('intval', $_POST['bbe_allowed_locations']) : [];
        update_term_meta($term_id, '_bbe_allowed_locations', $locations);
    }
}
BBE_Locations::init();