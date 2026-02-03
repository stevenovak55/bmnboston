<?php
/**
 * Update to version 1.1.0
 *
 * Adds reschedule columns to appointments table.
 *
 * @package SN_Appointment_Booking
 * @since 1.1.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Run the 1.1.0 update.
 *
 * @return bool Success or failure.
 */
function snab_update_to_1_1_0() {
    global $wpdb;

    $table = $wpdb->prefix . 'snab_appointments';

    // Check if columns already exist
    $columns = $wpdb->get_col("DESCRIBE {$table}");

    $success = true;

    // Add reschedule_count column
    if (!in_array('reschedule_count', $columns)) {
        $result = $wpdb->query(
            "ALTER TABLE {$table} ADD COLUMN reschedule_count INT DEFAULT 0 AFTER cancelled_at"
        );
        if ($result === false) {
            SNAB_Logger::error('Failed to add reschedule_count column');
            $success = false;
        }
    }

    // Add original_datetime column
    if (!in_array('original_datetime', $columns)) {
        $result = $wpdb->query(
            "ALTER TABLE {$table} ADD COLUMN original_datetime DATETIME NULL AFTER reschedule_count"
        );
        if ($result === false) {
            SNAB_Logger::error('Failed to add original_datetime column');
            $success = false;
        }
    }

    // Add rescheduled_by column
    if (!in_array('rescheduled_by', $columns)) {
        $result = $wpdb->query(
            "ALTER TABLE {$table} ADD COLUMN rescheduled_by VARCHAR(50) NULL AFTER original_datetime"
        );
        if ($result === false) {
            SNAB_Logger::error('Failed to add rescheduled_by column');
            $success = false;
        }
    }

    // Add reschedule_reason column
    if (!in_array('reschedule_reason', $columns)) {
        $result = $wpdb->query(
            "ALTER TABLE {$table} ADD COLUMN reschedule_reason TEXT NULL AFTER rescheduled_by"
        );
        if ($result === false) {
            SNAB_Logger::error('Failed to add reschedule_reason column');
            $success = false;
        }
    }

    // Add created_by column (for manual appointments)
    if (!in_array('created_by', $columns)) {
        $result = $wpdb->query(
            "ALTER TABLE {$table} ADD COLUMN created_by VARCHAR(50) DEFAULT 'client' AFTER reschedule_reason"
        );
        if ($result === false) {
            SNAB_Logger::error('Failed to add created_by column');
            $success = false;
        }
    }

    if ($success) {
        SNAB_Logger::info('Update to 1.1.0 completed successfully');
    }

    return $success;
}
