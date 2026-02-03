<?php
/**
 * MLS Listings Display Update Script - Version 6.11.46
 *
 * COMPREHENSIVE FIX: Add missing columns to ALL chatbot tables
 *
 * Tables fixed:
 * - wp_mld_chat_settings (setting_category, setting_type, is_encrypted, description)
 * - wp_mld_chat_sessions (last_activity_at, session_status, window_closed, window_closed_at, etc.)
 * - wp_mld_chat_messages (conversation_id, etc.)
 *
 * @package MLS_Listings_Display
 * @since 6.11.46
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Update to version 6.11.46
 * Comprehensive fix for all chatbot table schemas
 *
 * @return bool True on success
 */
function mld_update_to_6_11_46() {
    global $wpdb;

    $all_errors = array();
    $all_added = array();

    // ========================================
    // TABLE 1: wp_mld_chat_settings
    // ========================================
    $table_name = $wpdb->prefix . 'mld_chat_settings';
    if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") == $table_name) {
        $existing = $wpdb->get_col("SHOW COLUMNS FROM {$table_name}");
        
        $columns = array(
            'setting_type' => "ADD COLUMN setting_type varchar(50) DEFAULT 'string' AFTER setting_value",
            'setting_category' => "ADD COLUMN setting_category varchar(50) DEFAULT 'general' AFTER setting_type",
            'is_encrypted' => "ADD COLUMN is_encrypted tinyint(1) DEFAULT 0 AFTER setting_category",
            'description' => "ADD COLUMN description text DEFAULT NULL AFTER is_encrypted"
        );
        
        foreach ($columns as $col => $sql) {
            if (!in_array($col, $existing)) {
                $result = $wpdb->query("ALTER TABLE {$table_name} {$sql}");
                if ($result !== false) {
                    $all_added[] = "chat_settings.{$col}";
                } else {
                    $all_errors[] = "chat_settings.{$col}: " . $wpdb->last_error;
                }
            }
        }
    }

    // ========================================
    // TABLE 2: wp_mld_chat_sessions
    // ========================================
    $table_name = $wpdb->prefix . 'mld_chat_sessions';
    if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") == $table_name) {
        $existing = $wpdb->get_col("SHOW COLUMNS FROM {$table_name}");
        
        $columns = array(
            'conversation_id' => "ADD COLUMN conversation_id bigint(20) UNSIGNED DEFAULT NULL AFTER session_id",
            'session_status' => "ADD COLUMN session_status varchar(20) DEFAULT 'active' AFTER conversation_id",
            'last_activity_at' => "ADD COLUMN last_activity_at datetime DEFAULT CURRENT_TIMESTAMP AFTER session_status",
            'idle_timeout_minutes' => "ADD COLUMN idle_timeout_minutes int(11) DEFAULT 10 AFTER last_activity_at",
            'window_closed' => "ADD COLUMN window_closed tinyint(1) DEFAULT 0 AFTER idle_timeout_minutes",
            'window_closed_at' => "ADD COLUMN window_closed_at datetime DEFAULT NULL AFTER window_closed",
            'page_url' => "ADD COLUMN page_url varchar(500) DEFAULT NULL AFTER window_closed_at",
            'referrer_url' => "ADD COLUMN referrer_url varchar(500) DEFAULT NULL AFTER page_url",
            'device_type' => "ADD COLUMN device_type varchar(50) DEFAULT NULL AFTER referrer_url",
            'browser' => "ADD COLUMN browser varchar(100) DEFAULT NULL AFTER device_type",
            'session_data' => "ADD COLUMN session_data longtext DEFAULT NULL AFTER browser"
        );
        
        foreach ($columns as $col => $sql) {
            if (!in_array($col, $existing)) {
                $result = $wpdb->query("ALTER TABLE {$table_name} {$sql}");
                if ($result !== false) {
                    $all_added[] = "chat_sessions.{$col}";
                } else {
                    $all_errors[] = "chat_sessions.{$col}: " . $wpdb->last_error;
                }
            }
        }

        // Add indexes if missing
        $indexes_to_add = array(
            'session_status' => "ADD INDEX session_status (session_status)",
            'last_activity_at' => "ADD INDEX last_activity_at (last_activity_at)",
            'window_closed' => "ADD INDEX window_closed (window_closed)"
        );

        foreach ($indexes_to_add as $idx_name => $idx_sql) {
            $idx_exists = $wpdb->get_results("SHOW INDEX FROM {$table_name} WHERE Key_name = '{$idx_name}'");
            if (empty($idx_exists) && in_array($idx_name, array_merge($existing, array_keys($columns)))) {
                $wpdb->query("ALTER TABLE {$table_name} {$idx_sql}");
            }
        }
    }

    // ========================================
    // TABLE 3: wp_mld_chat_messages
    // ========================================
    $table_name = $wpdb->prefix . 'mld_chat_messages';
    if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") == $table_name) {
        $existing = $wpdb->get_col("SHOW COLUMNS FROM {$table_name}");
        
        $columns = array(
            'conversation_id' => "ADD COLUMN conversation_id bigint(20) UNSIGNED NOT NULL DEFAULT 0 AFTER id",
            'session_id' => "ADD COLUMN session_id varchar(100) NOT NULL DEFAULT '' AFTER conversation_id",
            'sender_type' => "ADD COLUMN sender_type varchar(20) NOT NULL DEFAULT 'user' AFTER session_id",
            'message_text' => "ADD COLUMN message_text text AFTER sender_type",
            'ai_provider' => "ADD COLUMN ai_provider varchar(50) DEFAULT NULL AFTER message_text",
            'ai_model' => "ADD COLUMN ai_model varchar(100) DEFAULT NULL AFTER ai_provider",
            'ai_tokens_used' => "ADD COLUMN ai_tokens_used int(11) DEFAULT NULL AFTER ai_model",
            'ai_context_injected' => "ADD COLUMN ai_context_injected text DEFAULT NULL AFTER ai_tokens_used",
            'response_time_ms' => "ADD COLUMN response_time_ms int(11) DEFAULT NULL AFTER ai_context_injected",
            'is_fallback' => "ADD COLUMN is_fallback tinyint(1) DEFAULT 0 AFTER response_time_ms",
            'fallback_reason' => "ADD COLUMN fallback_reason varchar(255) DEFAULT NULL AFTER is_fallback",
            'message_metadata' => "ADD COLUMN message_metadata longtext DEFAULT NULL AFTER fallback_reason",
            'admin_notified' => "ADD COLUMN admin_notified tinyint(1) DEFAULT 0 AFTER message_metadata",
            'admin_notification_sent_at' => "ADD COLUMN admin_notification_sent_at datetime DEFAULT NULL AFTER admin_notified"
        );
        
        foreach ($columns as $col => $sql) {
            if (!in_array($col, $existing)) {
                $result = $wpdb->query("ALTER TABLE {$table_name} {$sql}");
                if ($result !== false) {
                    $all_added[] = "chat_messages.{$col}";
                } else {
                    $all_errors[] = "chat_messages.{$col}: " . $wpdb->last_error;
                }
            }
        }

        // Add indexes if missing
        $indexes_to_add = array(
            'conversation_id' => "ADD INDEX conversation_id (conversation_id)",
            'session_id' => "ADD INDEX session_id (session_id)",
            'sender_type' => "ADD INDEX sender_type (sender_type)",
            'admin_notified' => "ADD INDEX admin_notified (admin_notified)"
        );

        foreach ($indexes_to_add as $idx_name => $idx_sql) {
            $idx_exists = $wpdb->get_results("SHOW INDEX FROM {$table_name} WHERE Key_name = '{$idx_name}'");
            if (empty($idx_exists) && in_array($idx_name, array_merge($existing, array_keys($columns)))) {
                $wpdb->query("ALTER TABLE {$table_name} {$idx_sql}");
            }
        }
    }

    // Update version
    update_option('mld_db_version', '6.11.46');

    // Log summary
    if (defined('WP_DEBUG') && WP_DEBUG) {
        if (!empty($all_added)) {
            error_log("MLD Update 6.11.46: Added columns: " . implode(', ', $all_added));
        }
        if (!empty($all_errors)) {
            error_log("MLD Update 6.11.46: Errors: " . implode('; ', $all_errors));
        }
        if (empty($all_added) && empty($all_errors)) {
            error_log("MLD Update 6.11.46: All chatbot table schemas already complete");
        }
    }

    return empty($all_errors);
}
