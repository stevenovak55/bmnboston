<?php
/**
 * Update script for MLS Listings Display v6.11.14
 *
 * Changes:
 * - Removes legacy chatbot setting from database
 * - Legacy chatbot code has been completely removed from plugin
 * - AI chatbot now has schedule_tour and contact_agent tools
 * - Adds 'source' column to form_submissions table if missing
 *
 * @package MLS_Listings_Display
 * @since 6.11.14
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Run the update to version 6.11.14
 *
 * @return bool True on success, false on failure
 */
function mld_update_to_6_11_14() {
    global $wpdb;

    $success = true;

    // Remove legacy_chatbot_enabled setting from chat_settings table
    $table_name = $wpdb->prefix . 'mld_chat_settings';

    // Check if table exists before attempting delete
    $table_exists = $wpdb->get_var($wpdb->prepare(
        "SHOW TABLES LIKE %s",
        $table_name
    ));

    if ($table_exists) {
        $deleted = $wpdb->delete(
            $table_name,
            array('setting_key' => 'legacy_chatbot_enabled'),
            array('%s')
        );

        if ($deleted !== false) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[MLD Update 6.11.14] Removed legacy_chatbot_enabled setting from database');
            }
        }
    }

    // Ensure form_submissions table has 'source' column for AI chatbot tracking
    $form_table = $wpdb->prefix . 'mld_form_submissions';
    $form_table_exists = $wpdb->get_var($wpdb->prepare(
        "SHOW TABLES LIKE %s",
        $form_table
    ));

    if ($form_table_exists) {
        // Check if 'source' column exists
        $source_column = $wpdb->get_results("SHOW COLUMNS FROM {$form_table} LIKE 'source'");

        if (empty($source_column)) {
            // Add source column if it doesn't exist
            $wpdb->query("ALTER TABLE {$form_table} ADD COLUMN source varchar(100) DEFAULT NULL AFTER status");

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[MLD Update 6.11.14] Added source column to form_submissions table');
            }
        }
    }

    // Update the stored version
    update_option('mld_db_version', '6.11.14');

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[MLD Update 6.11.14] Update completed successfully');
    }

    return $success;
}
