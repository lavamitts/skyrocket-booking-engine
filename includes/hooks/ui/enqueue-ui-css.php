<?php
/**
 * Enqueue plugin UI styles.
 */
add_action('wp_enqueue_scripts', function () {
    wp_enqueue_style(
        'bbe-ui-styles',
        plugin_dir_url(dirname(__FILE__, 3)) . 'assets/css/ui/ui.css',
        [],
        '1.0.0' // Update version string when updating styles to bypass browser cache
    );
});