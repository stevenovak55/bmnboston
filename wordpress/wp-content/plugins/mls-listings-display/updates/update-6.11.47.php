<?php
/**
 * MLS Listings Display Update Script - Version 6.11.47
 *
 * COMPREHENSIVE FIX: Add missing columns to ALL 8 chatbot tables
 *
 * Tables fixed:
 * 1. mld_chat_conversations
 * 2. mld_chat_messages
 * 3. mld_chat_sessions
 * 4. mld_chat_settings
 * 5. mld_chat_knowledge_base
 * 6. mld_chat_faq_library
 * 7. mld_chat_admin_notifications
 * 8. mld_chat_email_summaries
 *
 * @package MLS_Listings_Display
 * @since 6.11.47
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Helper function to add missing columns to a table
 */
function mld_add_missing_columns($table_name, $columns, &$all_added, &$all_errors) {
    global $wpdb;
    
    $full_table = $wpdb->prefix . $table_name;
    
    // Check if table exists
    if ($wpdb->get_var("SHOW TABLES LIKE '{$full_table}'") != $full_table) {
        return;
    }
    
    $existing = $wpdb->get_col("SHOW COLUMNS FROM {$full_table}");
    
    foreach ($columns as $col => $sql) {
        if (!in_array($col, $existing)) {
            $result = $wpdb->query("ALTER TABLE {$full_table} {$sql}");
            if ($result !== false) {
                $all_added[] = "{$table_name}.{$col}";
            } else {
                $all_errors[] = "{$table_name}.{$col}: " . $wpdb->last_error;
            }
        }
    }
}

/**
 * Update to version 6.11.47
 * Comprehensive fix for ALL 8 chatbot table schemas
 */
function mld_update_to_6_11_47() {
    global $wpdb;

    $all_errors = array();
    $all_added = array();

    // ========================================
    // TABLE 1: mld_chat_conversations (18 columns)
    // ========================================
    mld_add_missing_columns('mld_chat_conversations', array(
        'session_id' => "ADD COLUMN session_id varchar(100) NOT NULL AFTER id",
        'user_id' => "ADD COLUMN user_id bigint(20) UNSIGNED DEFAULT NULL AFTER session_id",
        'user_email' => "ADD COLUMN user_email varchar(255) DEFAULT NULL AFTER user_id",
        'user_name' => "ADD COLUMN user_name varchar(255) DEFAULT NULL AFTER user_email",
        'user_phone' => "ADD COLUMN user_phone varchar(50) DEFAULT NULL AFTER user_name",
        'conversation_status' => "ADD COLUMN conversation_status varchar(20) DEFAULT 'active' AFTER user_phone",
        'conversation_summary' => "ADD COLUMN conversation_summary text DEFAULT NULL AFTER conversation_status",
        'total_messages' => "ADD COLUMN total_messages int(11) DEFAULT 0 AFTER conversation_summary",
        'last_message_at' => "ADD COLUMN last_message_at datetime DEFAULT NULL AFTER total_messages",
        'started_at' => "ADD COLUMN started_at datetime DEFAULT CURRENT_TIMESTAMP AFTER last_message_at",
        'ended_at' => "ADD COLUMN ended_at datetime DEFAULT NULL AFTER started_at",
        'idle_since' => "ADD COLUMN idle_since datetime DEFAULT NULL AFTER ended_at",
        'summary_sent' => "ADD COLUMN summary_sent tinyint(1) DEFAULT 0 AFTER idle_since",
        'summary_sent_at' => "ADD COLUMN summary_sent_at datetime DEFAULT NULL AFTER summary_sent",
        'user_ip' => "ADD COLUMN user_ip varchar(45) DEFAULT NULL AFTER summary_sent_at",
        'user_agent' => "ADD COLUMN user_agent text DEFAULT NULL AFTER user_ip"
    ), $all_added, $all_errors);

    // ========================================
    // TABLE 2: mld_chat_messages (15 columns)
    // ========================================
    mld_add_missing_columns('mld_chat_messages', array(
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
    ), $all_added, $all_errors);

    // ========================================
    // TABLE 3: mld_chat_sessions (13 columns)
    // ========================================
    mld_add_missing_columns('mld_chat_sessions', array(
        'session_id' => "ADD COLUMN session_id varchar(100) NOT NULL AFTER id",
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
    ), $all_added, $all_errors);

    // ========================================
    // TABLE 4: mld_chat_settings (9 columns)
    // ========================================
    mld_add_missing_columns('mld_chat_settings', array(
        'setting_key' => "ADD COLUMN setting_key varchar(100) NOT NULL AFTER id",
        'setting_value' => "ADD COLUMN setting_value longtext DEFAULT NULL AFTER setting_key",
        'setting_type' => "ADD COLUMN setting_type varchar(50) DEFAULT 'string' AFTER setting_value",
        'setting_category' => "ADD COLUMN setting_category varchar(50) DEFAULT 'general' AFTER setting_type",
        'is_encrypted' => "ADD COLUMN is_encrypted tinyint(1) DEFAULT 0 AFTER setting_category",
        'description' => "ADD COLUMN description text DEFAULT NULL AFTER is_encrypted"
    ), $all_added, $all_errors);

    // ========================================
    // TABLE 5: mld_chat_knowledge_base (14 columns)
    // ========================================
    mld_add_missing_columns('mld_chat_knowledge_base', array(
        'content_type' => "ADD COLUMN content_type varchar(50) NOT NULL AFTER id",
        'content_id' => "ADD COLUMN content_id varchar(100) DEFAULT NULL AFTER content_type",
        'content_title' => "ADD COLUMN content_title varchar(500) DEFAULT NULL AFTER content_id",
        'content_text' => "ADD COLUMN content_text longtext DEFAULT NULL AFTER content_title",
        'content_summary' => "ADD COLUMN content_summary text DEFAULT NULL AFTER content_text",
        'content_url' => "ADD COLUMN content_url varchar(500) DEFAULT NULL AFTER content_summary",
        'content_metadata' => "ADD COLUMN content_metadata longtext DEFAULT NULL AFTER content_url",
        'embedding_vector' => "ADD COLUMN embedding_vector longtext DEFAULT NULL AFTER content_metadata",
        'relevance_score' => "ADD COLUMN relevance_score float DEFAULT 1.0 AFTER embedding_vector",
        'scan_date' => "ADD COLUMN scan_date datetime DEFAULT CURRENT_TIMESTAMP AFTER relevance_score",
        'is_active' => "ADD COLUMN is_active tinyint(1) DEFAULT 1 AFTER scan_date"
    ), $all_added, $all_errors);

    // ========================================
    // TABLE 6: mld_chat_faq_library (12 columns)
    // ========================================
    mld_add_missing_columns('mld_chat_faq_library', array(
        'question' => "ADD COLUMN question text NOT NULL AFTER id",
        'answer' => "ADD COLUMN answer longtext NOT NULL AFTER question",
        'keywords' => "ADD COLUMN keywords varchar(500) DEFAULT NULL AFTER answer",
        'category' => "ADD COLUMN category varchar(100) DEFAULT NULL AFTER keywords",
        'usage_count' => "ADD COLUMN usage_count int(11) DEFAULT 0 AFTER category",
        'last_used_at' => "ADD COLUMN last_used_at datetime DEFAULT NULL AFTER usage_count",
        'is_active' => "ADD COLUMN is_active tinyint(1) DEFAULT 1 AFTER last_used_at",
        'priority' => "ADD COLUMN priority int(11) DEFAULT 0 AFTER is_active",
        'created_by' => "ADD COLUMN created_by bigint(20) UNSIGNED DEFAULT NULL AFTER priority"
    ), $all_added, $all_errors);

    // ========================================
    // TABLE 7: mld_chat_admin_notifications (10 columns)
    // ========================================
    mld_add_missing_columns('mld_chat_admin_notifications', array(
        'conversation_id' => "ADD COLUMN conversation_id bigint(20) UNSIGNED NOT NULL AFTER id",
        'message_id' => "ADD COLUMN message_id bigint(20) UNSIGNED NOT NULL AFTER conversation_id",
        'notification_type' => "ADD COLUMN notification_type varchar(50) DEFAULT 'new_message' AFTER message_id",
        'admin_email' => "ADD COLUMN admin_email varchar(255) NOT NULL AFTER notification_type",
        'notification_status' => "ADD COLUMN notification_status varchar(20) DEFAULT 'pending' AFTER admin_email",
        'sent_at' => "ADD COLUMN sent_at datetime DEFAULT NULL AFTER notification_status",
        'error_message' => "ADD COLUMN error_message text DEFAULT NULL AFTER sent_at",
        'retry_count' => "ADD COLUMN retry_count int(11) DEFAULT 0 AFTER error_message"
    ), $all_added, $all_errors);

    // ========================================
    // TABLE 8: mld_chat_email_summaries (13 columns)
    // ========================================
    mld_add_missing_columns('mld_chat_email_summaries', array(
        'conversation_id' => "ADD COLUMN conversation_id bigint(20) UNSIGNED NOT NULL AFTER id",
        'recipient_email' => "ADD COLUMN recipient_email varchar(255) NOT NULL AFTER conversation_id",
        'recipient_name' => "ADD COLUMN recipient_name varchar(255) DEFAULT NULL AFTER recipient_email",
        'summary_html' => "ADD COLUMN summary_html longtext DEFAULT NULL AFTER recipient_name",
        'summary_text' => "ADD COLUMN summary_text longtext DEFAULT NULL AFTER summary_html",
        'properties_discussed' => "ADD COLUMN properties_discussed text DEFAULT NULL AFTER summary_text",
        'next_steps' => "ADD COLUMN next_steps text DEFAULT NULL AFTER properties_discussed",
        'trigger_reason' => "ADD COLUMN trigger_reason varchar(50) DEFAULT NULL AFTER next_steps",
        'email_status' => "ADD COLUMN email_status varchar(20) DEFAULT 'pending' AFTER trigger_reason",
        'sent_at' => "ADD COLUMN sent_at datetime DEFAULT NULL AFTER email_status",
        'error_message' => "ADD COLUMN error_message text DEFAULT NULL AFTER sent_at"
    ), $all_added, $all_errors);

    // Update version
    update_option('mld_db_version', '6.11.47');

    // Log summary
    if (defined('WP_DEBUG') && WP_DEBUG) {
        if (!empty($all_added)) {
            error_log("MLD Update 6.11.47: Added " . count($all_added) . " columns across chatbot tables: " . implode(', ', $all_added));
        }
        if (!empty($all_errors)) {
            error_log("MLD Update 6.11.47: Errors: " . implode('; ', $all_errors));
        }
        if (empty($all_added) && empty($all_errors)) {
            error_log("MLD Update 6.11.47: All 8 chatbot table schemas already complete");
        }
    }

    return empty($all_errors);
}
