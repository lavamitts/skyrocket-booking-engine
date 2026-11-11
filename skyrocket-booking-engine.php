<?php
/**
 * Plugin Name: Skyrocket Booking Engine
 * Description: WooCommerce-integrated booking engine with multi-location, buffer times, and temporary slot holds.
 * Version: 1.0.0
 * Author: Custom Developer
 */

if (!defined('ABSPATH'))
    exit;

define('BBE_PATH', plugin_dir_path(__FILE__));
define('BBE_URL', plugin_dir_url(__FILE__));

// Plugin version constant, used for cache-busting CSS and JavaScript.
define('BBE_VERSION', '1.0.0');

// Load includes
require_once BBE_PATH . 'includes/class-bbe-db.php';
require_once BBE_PATH . 'includes/class-bbe-locations.php';
require_once BBE_PATH . 'includes/class-bbe-product-type.php';
require_once BBE_PATH . 'includes/class-bbe-availability.php';
require_once BBE_PATH . 'includes/class-bbe-holds.php';
require_once BBE_PATH . 'includes/class-bbe-cart.php';
require_once BBE_PATH . 'includes/class-bbe-admin-schedule.php';
require_once BBE_PATH . 'includes/class-bbe-frontend.php';
require_once BBE_PATH . 'includes/class-bbe-checkout.php';
require_once BBE_PATH . 'includes/class-bbe-admin-calendar.php';

register_activation_hook(__FILE__, 'bbe_create_custom_tables');

function bbe_create_custom_tables()
{
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();

    $table_schedules = $wpdb->prefix . 'bbe_schedules';
    $sql_schedules = "CREATE TABLE $table_schedules (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        location_id bigint(20) NOT NULL DEFAULT 0,
        day_of_week varchar(10) NULL,
        specific_date date NULL,
        end_date date NULL,
        start_time time NOT NULL,
        end_time time NOT NULL,
        override_type varchar(20) NOT NULL DEFAULT 'block',
        PRIMARY KEY (id),
        KEY location_date (location_id, specific_date),
        KEY location_range (location_id, specific_date, end_date)
    ) $charset_collate;";

    $table_holds = $wpdb->prefix . 'bbe_holds';
    $sql_holds = "CREATE TABLE $table_holds (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        session_id varchar(64) NOT NULL,
        product_id bigint(20) NOT NULL,
        location_id bigint(20) NOT NULL,
        booking_start datetime NOT NULL,
        booking_end datetime NOT NULL,
        created_at datetime NOT NULL,
        expires_at datetime NOT NULL,
        PRIMARY KEY (id),
        KEY session_lookup (session_id),
        KEY expiry_lookup (expires_at),
        KEY slot_check (location_id, booking_start, booking_end)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql_schedules);
    dbDelta($sql_holds);

    // FORCE ALTER: Ensure columns exist even if dbDelta skipped them
    $row = $wpdb->get_results("DESCRIBE {$table_schedules};");
    $columns = [];
    if ($row) {
        foreach ($row as $col) {
            $columns[] = $col->Field;
        }
    }

    if (!in_array('end_date', $columns)) {
        $wpdb->query("ALTER TABLE {$table_schedules} ADD COLUMN end_date date NULL AFTER specific_date;");
    }

    if (!in_array('override_type', $columns)) {
        $wpdb->query("ALTER TABLE {$table_schedules} ADD COLUMN override_type varchar(20) NOT NULL DEFAULT 'block' AFTER end_time;");
    }
}