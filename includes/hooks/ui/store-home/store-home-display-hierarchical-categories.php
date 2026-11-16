<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * WooCommerce Store Category Landing Page
 *
 * Replaces the standard WooCommerce Shop archive with a
 * custom two-tier product category landing page.
 *
 * Displays:
 * - Top-level category heading and description
 * - Immediate child categories as linked cards
 * - Category thumbnails and short descriptions
 *
 * Only affects the main WooCommerce Shop archive.
 */

/**
 * Initialise the custom Shop page.
 */
add_action('wp', 'wc_category_store_landing_setup');

function wc_category_store_landing_setup(): void
{
    if (!function_exists('is_shop') || !is_shop()) {
        return;
    }

    // Remove standard category output.
    remove_action(
        'woocommerce_before_shop_loop',
        'woocommerce_output_product_categories',
        10
    );

    // Remove catalogue controls.
    remove_action(
        'woocommerce_before_shop_loop',
        'woocommerce_result_count',
        20
    );

    remove_action(
        'woocommerce_before_shop_loop',
        'woocommerce_catalog_ordering',
        30
    );

    // Remove pagination.
    remove_action(
        'woocommerce_after_shop_loop',
        'woocommerce_pagination',
        10
    );

    // Render our custom category landing page.
    add_action(
        'woocommerce_before_main_content',
        'wc_category_store_landing_render',
        20
    );
}


/**
 * Render the complete Store landing page.
 */
function wc_category_store_landing_render(): void
{
    $parents = get_terms([
        'taxonomy' => 'product_cat',
        'parent' => 0,
        'hide_empty' => false,
        'orderby' => 'name',
        'order' => 'ASC',
    ]);

    // Sort the categories according to priority
    usort($parents, function ($a, $b) {
        $priority_comparison =
            swu_category_get_display_priority((int) $a->term_id)
            <=>
            swu_category_get_display_priority((int) $b->term_id);

        return $priority_comparison !== 0
            ? $priority_comparison
            : strcasecmp($a->name, $b->name);
    });

    if (is_wp_error($parents) || empty($parents)) {
        return;
    }

    echo '<div class="wc-store-category-landing">';

    foreach ($parents as $parent) {

        $children = get_terms([
            'taxonomy' => 'product_cat',
            'parent' => $parent->term_id,
            'hide_empty' => false,
            'orderby' => 'name',
            'order' => 'ASC',
        ]);

        if (is_wp_error($children) || empty($children)) {
            continue;
        }

        echo '<section class="wc-store-category-section">';

        // Parent heading.
        echo '<header class="wc-store-category-section__header">';

        echo '<h2 class="wc-store-category-section__title">';
        echo esc_html($parent->name);
        echo '</h2>';

        $description = swu_category_get_short_description(
            (int) $parent->term_id
        );

        if ($description !== '') {
            echo '<div class="wc-store-category-section__description">';
            echo wpautop(esc_html($description));
            echo '</div>';
        }

        echo '</header>';

        // Child category cards.
        echo '<div class="wc-store-category-grid">';

        foreach ($children as $child) {

            $link = get_term_link($child);

            if (is_wp_error($link)) {
                continue;
            }

            $thumbnail_id = (int) get_term_meta(
                $child->term_id,
                'thumbnail_id',
                true
            );

            // Retrieve existing category short description.
            $short_description = (string) get_term_meta(
                $child->term_id,
                '_wc_category_short_description',
                true
            );

            echo '<div class="wc-store-category-card">';

            echo '<h3 class="wc-store-category-card__title">';
            echo '<a href="' . esc_url($link) . '">';
            echo esc_html($child->name);
            echo '</a>';
            echo '</h3>';

            echo '<div class="wc-store-category-card__image">';

            if ($thumbnail_id) {
                echo '<a href="' . esc_url($link) . '">';

                echo wp_get_attachment_image(
                    $thumbnail_id,
                    'woocommerce_full',
                    false,
                    [
                        'class' => 'wc-store-category-card__thumbnail',
                        'loading' => 'lazy',
                    ]
                );

                echo '</a>';
            }

            echo '</div>';

            echo '<div class="wc-store-category-card__content">';



            if ($short_description !== '') {
                echo '<div class="wc-store-category-card__description">';
                echo wp_kses_post(wpautop($short_description));
                echo '</div>';
            }

            echo '</div>';

            echo '</div>';
        }

        echo '</div>';

        echo '</section>';
    }

    echo '</div>';
}
