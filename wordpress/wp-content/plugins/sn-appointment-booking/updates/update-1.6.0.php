<?php
/**
 * Update to version 1.6.0
 *
 * Phase 2: Admin Experience Improvements
 * - Creates staff_services table (staff-to-appointment-type linking)
 * - Adds admin notification preference options
 * - Adds color column to appointment_types for calendar display
 *
 * @package SN_Appointment_Booking
 * @since 1.6.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Run the 1.6.0 update.
 *
 * @return bool True on success.
 */
function snab_update_to_1_6_0() {
    global $wpdb;

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

    $charset_collate = $wpdb->get_charset_collate();
    $success = true;

    // 1. Create staff_services table (links which staff can perform which appointment types)
    $staff_services_table = $wpdb->prefix . 'snab_staff_services';
    $sql = "CREATE TABLE {$staff_services_table} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        staff_id bigint(20) unsigned NOT NULL,
        appointment_type_id bigint(20) unsigned NOT NULL,
        is_active tinyint(1) DEFAULT 1,
        created_at datetime NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY staff_type (staff_id, appointment_type_id),
        KEY staff_id (staff_id),
        KEY appointment_type_id (appointment_type_id)
    ) {$charset_collate};";

    dbDelta($sql);

    // Verify table was created
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$staff_services_table}'");
    if ($table_exists) {
        SNAB_Logger::info('Created staff_services table');

        // Auto-link existing staff to all existing appointment types
        $staff = $wpdb->get_results("SELECT id FROM {$wpdb->prefix}snab_staff WHERE is_active = 1");
        $types = $wpdb->get_results("SELECT id FROM {$wpdb->prefix}snab_appointment_types WHERE is_active = 1");

        if ($staff && $types) {
            $now = current_time('mysql');
            foreach ($staff as $s) {
                foreach ($types as $t) {
                    $wpdb->replace(
                        $staff_services_table,
                        array(
                            'staff_id' => $s->id,
                            'appointment_type_id' => $t->id,
                            'is_active' => 1,
                            'created_at' => $now,
                        ),
                        array('%d', '%d', '%d', '%s')
                    );
                }
            }
            SNAB_Logger::info('Auto-linked existing staff to appointment types');
        }
    } else {
        SNAB_Logger::error('Failed to create staff_services table');
        $success = false;
    }

    // 2. Add color column to appointment_types if it doesn't exist
    $types_table = $wpdb->prefix . 'snab_appointment_types';
    $column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$types_table} LIKE 'color'");
    if (empty($column_exists)) {
        $wpdb->query("ALTER TABLE {$types_table} ADD COLUMN color varchar(7) DEFAULT '#3788d8' AFTER description");
        SNAB_Logger::info('Added color column to appointment_types table');

        // Set default colors for existing types
        $colors = array('#3788d8', '#28a745', '#dc3545', '#ffc107', '#17a2b8', '#6f42c1');
        $existing_types = $wpdb->get_results("SELECT id FROM {$types_table}");
        foreach ($existing_types as $index => $type) {
            $color = $colors[$index % count($colors)];
            $wpdb->update(
                $types_table,
                array('color' => $color),
                array('id' => $type->id),
                array('%s'),
                array('%d')
            );
        }
    }

    // 3. Add bio column to staff table if it doesn't exist
    $staff_table = $wpdb->prefix . 'snab_staff';
    $bio_exists = $wpdb->get_results("SHOW COLUMNS FROM {$staff_table} LIKE 'bio'");
    if (empty($bio_exists)) {
        $wpdb->query("ALTER TABLE {$staff_table} ADD COLUMN bio text AFTER phone");
        SNAB_Logger::info('Added bio column to staff table');
    }

    // 4. Add avatar_url column to staff table if it doesn't exist
    $avatar_exists = $wpdb->get_results("SHOW COLUMNS FROM {$staff_table} LIKE 'avatar_url'");
    if (empty($avatar_exists)) {
        $wpdb->query("ALTER TABLE {$staff_table} ADD COLUMN avatar_url varchar(255) AFTER bio");
        SNAB_Logger::info('Added avatar_url column to staff table');
    }

    // 5. Set default admin notification preferences
    $notification_defaults = array(
        'snab_notify_new_booking' => true,
        'snab_notify_cancellation' => true,
        'snab_notify_reschedule' => true,
        'snab_notify_reminder' => false,
        'snab_notification_email' => get_option('admin_email'),
        'snab_notification_frequency' => 'instant', // instant, daily_digest
        'snab_secondary_notification_email' => '',
    );

    foreach ($notification_defaults as $option => $default) {
        if (get_option($option) === false) {
            update_option($option, $default);
            SNAB_Logger::info("Set default notification option: {$option}");
        }
    }

    // 6. Add staff selection mode option for booking widget
    if (get_option('snab_staff_selection_mode') === false) {
        update_option('snab_staff_selection_mode', 'auto'); // auto, manual, hidden
        SNAB_Logger::info('Set default staff selection mode: auto');
    }

    return $success;
}
