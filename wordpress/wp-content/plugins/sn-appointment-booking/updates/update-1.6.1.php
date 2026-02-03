<?php
/**
 * Update to version 1.6.1
 *
 * Phase 2: Per-Staff Google Calendar Connections
 * - Adds google_access_token column to staff table
 * - Adds google_token_expires column to staff table
 * - Adds title column to staff table for job title display
 *
 * @package SN_Appointment_Booking
 * @since 1.6.1
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Run the 1.6.1 update.
 *
 * @return bool True on success.
 */
function snab_update_to_1_6_1() {
    global $wpdb;

    $staff_table = $wpdb->prefix . 'snab_staff';
    $success = true;

    // 1. Add google_access_token column if it doesn't exist
    $access_token_exists = $wpdb->get_results("SHOW COLUMNS FROM {$staff_table} LIKE 'google_access_token'");
    if (empty($access_token_exists)) {
        $result = $wpdb->query("ALTER TABLE {$staff_table} ADD COLUMN google_access_token text AFTER google_refresh_token");
        if ($result !== false) {
            SNAB_Logger::info('Added google_access_token column to staff table');
        } else {
            SNAB_Logger::error('Failed to add google_access_token column');
            $success = false;
        }
    }

    // 2. Add google_token_expires column if it doesn't exist
    $token_expires_exists = $wpdb->get_results("SHOW COLUMNS FROM {$staff_table} LIKE 'google_token_expires'");
    if (empty($token_expires_exists)) {
        $result = $wpdb->query("ALTER TABLE {$staff_table} ADD COLUMN google_token_expires int AFTER google_access_token");
        if ($result !== false) {
            SNAB_Logger::info('Added google_token_expires column to staff table');
        } else {
            SNAB_Logger::error('Failed to add google_token_expires column');
            $success = false;
        }
    }

    // 3. Add title column if it doesn't exist (for job title)
    $title_exists = $wpdb->get_results("SHOW COLUMNS FROM {$staff_table} LIKE 'title'");
    if (empty($title_exists)) {
        $result = $wpdb->query("ALTER TABLE {$staff_table} ADD COLUMN title varchar(100) AFTER name");
        if ($result !== false) {
            SNAB_Logger::info('Added title column to staff table');
        } else {
            SNAB_Logger::error('Failed to add title column');
            $success = false;
        }
    }

    return $success;
}
