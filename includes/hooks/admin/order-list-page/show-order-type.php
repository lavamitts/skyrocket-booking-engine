<?php
/**
 * 1. Register the custom 'Item Types' column after the order/customer column.
 */
add_filter('woocommerce_shop_order_list_table_columns', function ($columns) {
    $new_columns = [];

    foreach ($columns as $key => $column_name) {
        $new_columns[$key] = $column_name;

        // Insert immediately after order_number (or order_title depending on WC version context)
        if ($key === 'order_number' || $key === 'order_title') {
            $new_columns['bbe_item_types'] = __('Item Types', 'bbe');
        }
    }

    // Fallback if key wasn't matched
    if (!isset($new_columns['bbe_item_types'])) {
        $new_columns['bbe_item_types'] = __('Item Types', 'bbe');
    }

    return $new_columns;
});

/**
 * 2. Render the lozenges inside the custom column cells.
 */
add_action('woocommerce_shop_order_list_table_custom_column', function ($column, $order) {
    if ($column !== 'bbe_item_types') {
        return;
    }

    if (!$order instanceof WC_Order) {
        return;
    }

    $item_types = bbe_get_order_item_types($order);
    $has_service = $item_types['has_service'];
    $has_physical = $item_types['has_physical'];

    echo '<div class="bbe-order-type-lozenges" style="display: flex; gap: 4px; align-items: center; flex-wrap: wrap;">';

    if ($has_service) {
        echo '<span class="bbe-lozenge bbe-lozenge--service">' . __('SERVICE', 'bbe') . '</span>';
    }

    if ($has_physical) {
        echo '<span class="bbe-lozenge bbe-lozenge--product">' . __('PRODUCT', 'bbe') . '</span>';
    }

    if (!$has_physical && !$has_service) {
        echo '<span style="color: #999; font-size: 11px;">&mdash;</span>';
    }

    echo '</div>';
}, 10, 2);