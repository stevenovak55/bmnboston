<?php
/**
 * Database Update to Version 1.0.0
 *
 * Initial database setup - creates all tables and default data.
 *
 * @package SN_Appointment_Booking
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Run update to version 1.0.0.
 *
 * @return bool True on success, false on failure.
 */
function snab_update_to_1_0_0() {
    // Use the activator to create tables
    if (class_exists('SNAB_Activator')) {
        SNAB_Activator::create_tables();
        SNAB_Activator::create_default_data();
        SNAB_Activator::set_default_options();
    }

    // Verify tables were created
    $tables = SNAB_Activator::verify_tables();
    $all_exist = !in_array(false, $tables, true);

    if ($all_exist) {
        // Update version
        update_option('snab_db_version', '1.0.0');

        if (class_exists('SNAB_Logger')) {
            SNAB_Logger::info('Update to 1.0.0 completed successfully');
        }

        return true;
    }

    if (class_exists('SNAB_Logger')) {
        SNAB_Logger::error('Update to 1.0.0 failed - missing tables', array(
            'tables' => $tables,
        ));
    }

    return false;
}
