<?php
/**
 * Update 1.8.0 - Push Notifications Support
 *
 * Adds device tokens table and push reminder columns.
 *
 * @package SN_Appointment_Booking
 * @since 1.8.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Run the 1.8.0 update.
 */
function snab_update_1_8_0() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();

    // Create device tokens table
    $table_name = $wpdb->prefix . 'snab_device_tokens';
    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        user_id bigint(20) unsigned NOT NULL,
        device_token varchar(255) NOT NULL,
        device_type varchar(20) NOT NULL DEFAULT 'ios',
        is_sandbox tinyint(1) NOT NULL DEFAULT 0,
        is_active tinyint(1) NOT NULL DEFAULT 1,
        created_at datetime NOT NULL,
        updated_at datetime NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY device_token (device_token),
        KEY user_id (user_id),
        KEY is_active (is_active)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);

    // Add push reminder columns to appointments table if they don't exist
    $appointments_table = $wpdb->prefix . 'snab_appointments';

    // Check if columns exist
    $columns = $wpdb->get_col("DESCRIBE $appointments_table");

    if (!in_array('push_reminder_24h_sent', $columns)) {
        $wpdb->query("ALTER TABLE $appointments_table ADD COLUMN push_reminder_24h_sent tinyint(1) NOT NULL DEFAULT 0 AFTER reminder_1h_sent");
    }

    if (!in_array('push_reminder_1h_sent', $columns)) {
        $wpdb->query("ALTER TABLE $appointments_table ADD COLUMN push_reminder_1h_sent tinyint(1) NOT NULL DEFAULT 0 AFTER push_reminder_24h_sent");
    }

    // Add default options for push notifications
    add_option('snab_enable_push_notifications', false);
    add_option('snab_apns_auth_key', '');
    add_option('snab_apns_key_id', '');
    add_option('snab_apns_team_id', '');

    SNAB_Logger::info('Update 1.8.0 completed - Push notifications support added');
}

// Run the update
snab_update_1_8_0();
