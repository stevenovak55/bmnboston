<?php
/**
 * Update to version 6.20.12
 *
 * Ensures chatbot notification table has all required columns.
 * Fixes missing notification_data column that caused email notifications to fail.
 *
 * @package MLS_Listings_Display
 * @since 6.20.12
 */

if (!defined('ABSPATH')) {
    exit;
}

function mld_update_to_6_20_12() {
    global $wpdb;

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[MLD Update 6.20.12] Starting chatbot table schema verification');
    }

    $table = $wpdb->prefix . 'mld_chat_admin_notifications';
    $columns_added = array();

    // Check if notification_data column exists
    $column_exists = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
         WHERE TABLE_SCHEMA = %s 
         AND TABLE_NAME = %s 
         AND COLUMN_NAME = 'notification_data'",
        DB_NAME,
        $table
    ));

    if (!$column_exists) {
        $result = $wpdb->query("ALTER TABLE {$table} ADD COLUMN notification_data longtext AFTER notification_status");
        if ($result !== false) {
            $columns_added[] = 'notification_data';
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[MLD Update 6.20.12] Added notification_data column to ' . $table);
            }
        } else {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[MLD Update 6.20.12] Failed to add notification_data column: ' . $wpdb->last_error);
            }
        }
    }

    // Verify the conversations table has page_url column (needed for notification emails)
    $conv_table = $wpdb->prefix . 'mld_chat_conversations';
    $page_url_exists = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
         WHERE TABLE_SCHEMA = %s 
         AND TABLE_NAME = %s 
         AND COLUMN_NAME = 'page_url'",
        DB_NAME,
        $conv_table
    ));

    if (!$page_url_exists) {
        $result = $wpdb->query("ALTER TABLE {$conv_table} ADD COLUMN page_url varchar(500) DEFAULT NULL AFTER user_agent");
        if ($result !== false) {
            $columns_added[] = 'page_url (conversations)';
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[MLD Update 6.20.12] Added page_url column to ' . $conv_table);
            }
        }
    }

    // Update version number
    update_option('mld_db_version', '6.20.12');

    if (defined('WP_DEBUG') && WP_DEBUG) {
        if (!empty($columns_added)) {
            error_log('[MLD Update 6.20.12] Added columns: ' . implode(', ', $columns_added));
        } else {
            error_log('[MLD Update 6.20.12] All required columns already exist');
        }
    }

    return true;
}
