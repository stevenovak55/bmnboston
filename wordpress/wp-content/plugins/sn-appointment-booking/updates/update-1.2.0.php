<?php
/**
 * Update to version 1.2.0
 *
 * Creates shortcode presets table.
 *
 * @package SN_Appointment_Booking
 * @since 1.2.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Run the 1.2.0 update.
 *
 * @return bool Success or failure.
 */
function snab_update_to_1_2_0() {
    global $wpdb;

    $table_name = $wpdb->prefix . 'snab_shortcode_presets';
    $charset_collate = $wpdb->get_charset_collate();

    // Check if table already exists
    if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name) {
        SNAB_Logger::info('Shortcode presets table already exists');
        return true;
    }

    $sql = "CREATE TABLE {$table_name} (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        slug VARCHAR(50) NOT NULL,
        description TEXT,
        appointment_types TEXT COMMENT 'Comma-separated type IDs or empty for all',
        allowed_days VARCHAR(50) COMMENT 'Comma-separated day numbers (0=Sun, 6=Sat) or empty for all',
        start_hour TINYINT UNSIGNED DEFAULT NULL COMMENT 'Earliest hour (0-23)',
        end_hour TINYINT UNSIGNED DEFAULT NULL COMMENT 'Latest hour (0-23)',
        weeks_to_show TINYINT UNSIGNED DEFAULT 2,
        default_location VARCHAR(255) DEFAULT NULL,
        custom_title VARCHAR(255) DEFAULT NULL,
        css_class VARCHAR(100) DEFAULT NULL,
        is_active TINYINT(1) DEFAULT 1,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        UNIQUE KEY slug (slug),
        KEY is_active (is_active)
    ) {$charset_collate};";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);

    // Verify table was created
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name;

    if ($table_exists) {
        SNAB_Logger::info('Shortcode presets table created successfully');
    } else {
        SNAB_Logger::error('Failed to create shortcode presets table');
    }

    return $table_exists;
}
