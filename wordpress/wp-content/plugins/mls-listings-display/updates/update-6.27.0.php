<?php
/**
 * Update to version 6.27.0
 *
 * Lead Capture Gate Form for Chatbot Widget
 * - Adds lead_gate_enabled setting to require user info before chatting
 *
 * @package MLS_Listings_Display
 * @since 6.27.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Run the 6.27.0 update
 *
 * @return bool Success status
 */
function mld_update_to_6_27_0() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'mld_chat_settings';

    // Check if table exists
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'");
    if (!$table_exists) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[MLD Update 6.27.0] Settings table does not exist, skipping');
        }
        update_option('mld_db_version', '6.27.0');
        return true;
    }

    // Check if setting already exists
    $exists = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$table_name} WHERE setting_key = %s",
        'lead_gate_enabled'
    ));

    if (!$exists) {
        $result = $wpdb->insert(
            $table_name,
            array(
                'setting_key' => 'lead_gate_enabled',
                'setting_value' => '1',
                'setting_type' => 'boolean',
                'setting_category' => 'general',
                'description' => 'Require name and contact info before chatting',
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ),
            array('%s', '%s', '%s', '%s', '%s', '%s', '%s')
        );

        if ($result === false) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[MLD Update 6.27.0] Failed to insert lead_gate_enabled setting: ' . $wpdb->last_error);
            }
        } else {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[MLD Update 6.27.0] lead_gate_enabled setting added successfully');
            }
        }
    } else {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[MLD Update 6.27.0] lead_gate_enabled setting already exists');
        }
    }

    // Update version
    update_option('mld_db_version', '6.27.0');

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[MLD Update 6.27.0] Update completed successfully');
    }

    return true;
}
