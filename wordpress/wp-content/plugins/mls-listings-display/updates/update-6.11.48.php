<?php
/**
 * MLS Listings Display Update Script - Version 6.11.48
 *
 * CRITICAL FIX: Add missing columns that code actually uses
 *
 * The chatbot code uses column names that don't exist in the schema.
 * This migration adds the missing columns to fix email notifications.
 *
 * FIXES:
 * 1. mld_chat_admin_notifications - missing 'notification_data' column
 * 2. mld_chat_email_summaries - code uses different column names than schema
 *
 * @package MLS_Listings_Display
 * @since 6.11.48
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Update to version 6.11.48
 * Fix missing columns that PHP code actually uses
 */
function mld_update_to_6_11_48() {
    global $wpdb;

    $all_errors = array();
    $all_added = array();

    // ========================================
    // FIX 1: mld_chat_admin_notifications
    // Code tries to insert 'notification_data' but column doesn't exist
    // ========================================
    $table_name = $wpdb->prefix . 'mld_chat_admin_notifications';
    if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") == $table_name) {
        $existing = $wpdb->get_col("SHOW COLUMNS FROM {$table_name}");

        // Add notification_data column - the code needs this for storing notification payload
        if (!in_array('notification_data', $existing)) {
            $result = $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN notification_data longtext DEFAULT NULL AFTER notification_status");
            if ($result !== false) {
                $all_added[] = 'admin_notifications.notification_data';
            } else {
                $all_errors[] = 'admin_notifications.notification_data: ' . $wpdb->last_error;
            }
        }
    }

    // ========================================
    // FIX 2: mld_chat_email_summaries
    // Code uses: key_topics, properties_mentioned, ai_provider, ai_model, delivery_status
    // Schema has: properties_discussed, next_steps, trigger_reason, email_status
    // Need to add the columns the code actually uses
    // ========================================
    $table_name = $wpdb->prefix . 'mld_chat_email_summaries';
    if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") == $table_name) {
        $existing = $wpdb->get_col("SHOW COLUMNS FROM {$table_name}");

        $columns_to_add = array(
            'key_topics' => "ADD COLUMN key_topics text DEFAULT NULL AFTER summary_text",
            'properties_mentioned' => "ADD COLUMN properties_mentioned text DEFAULT NULL AFTER key_topics",
            'ai_provider' => "ADD COLUMN ai_provider varchar(50) DEFAULT NULL AFTER properties_mentioned",
            'ai_model' => "ADD COLUMN ai_model varchar(100) DEFAULT NULL AFTER ai_provider",
            'delivery_status' => "ADD COLUMN delivery_status varchar(20) DEFAULT 'pending' AFTER ai_model",
        );

        foreach ($columns_to_add as $col => $sql) {
            if (!in_array($col, $existing)) {
                $result = $wpdb->query("ALTER TABLE {$table_name} {$sql}");
                if ($result !== false) {
                    $all_added[] = "email_summaries.{$col}";
                } else {
                    $all_errors[] = "email_summaries.{$col}: " . $wpdb->last_error;
                }
            }
        }
    }

    // ========================================
    // FIX 3: Ensure conversations table has all required columns
    // for the summary generator to work
    // ========================================
    $table_name = $wpdb->prefix . 'mld_chat_conversations';
    if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") == $table_name) {
        $existing = $wpdb->get_col("SHOW COLUMNS FROM {$table_name}");

        // summary_sent is used by summary generator
        if (!in_array('summary_sent', $existing)) {
            $result = $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN summary_sent tinyint(1) DEFAULT 0");
            if ($result !== false) {
                $all_added[] = 'conversations.summary_sent';
            } else {
                $all_errors[] = 'conversations.summary_sent: ' . $wpdb->last_error;
            }
        }

        // page_url is used by admin notifier
        if (!in_array('page_url', $existing)) {
            $result = $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN page_url varchar(500) DEFAULT NULL");
            if ($result !== false) {
                $all_added[] = 'conversations.page_url';
            } else {
                $all_errors[] = 'conversations.page_url: ' . $wpdb->last_error;
            }
        }
    }

    // Update version
    update_option('mld_db_version', '6.11.48');

    // Log summary
    if (defined('WP_DEBUG') && WP_DEBUG) {
        if (!empty($all_added)) {
            error_log("MLD Update 6.11.48: Added columns that code requires: " . implode(', ', $all_added));
        }
        if (!empty($all_errors)) {
            error_log("MLD Update 6.11.48: Errors: " . implode('; ', $all_errors));
        }
        if (empty($all_added) && empty($all_errors)) {
            error_log("MLD Update 6.11.48: All required columns already exist");
        }
    }

    return empty($all_errors);
}
