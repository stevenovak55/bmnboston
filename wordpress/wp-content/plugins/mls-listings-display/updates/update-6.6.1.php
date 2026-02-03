<?php
/**
 * Update to version 6.6.1
 *
 * Creates all chatbot database tables:
 * - Chat conversations
 * - Chat messages
 * - Chat sessions
 * - Chat settings
 * - FAQ library
 * - Knowledge base
 * - Admin notifications
 * - Email summaries
 *
 * @package MLS_Listings_Display
 * @since 6.6.1
 */

if (!defined('ABSPATH')) {
    exit;
}

function mld_update_to_6_6_1() {
    global $wpdb;

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[MLD Update 6.6.1] Starting chatbot tables installation');
    }

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

    $charset_collate = $wpdb->get_charset_collate();
    $tables_created = array();
    $tables_failed = array();

    // Table 1: Chat Conversations
    $sql = "CREATE TABLE {$wpdb->prefix}mld_chat_conversations (
        id bigint unsigned NOT NULL AUTO_INCREMENT,
        session_id varchar(100) NOT NULL,
        user_id bigint unsigned DEFAULT NULL,
        user_email varchar(255) DEFAULT NULL,
        user_name varchar(255) DEFAULT NULL,
        user_phone varchar(50) DEFAULT NULL,
        conversation_status varchar(20) DEFAULT 'active',
        conversation_summary text,
        total_messages int DEFAULT 0,
        last_message_at datetime DEFAULT NULL,
        started_at datetime DEFAULT CURRENT_TIMESTAMP,
        ended_at datetime DEFAULT NULL,
        idle_since datetime DEFAULT NULL,
        summary_sent tinyint(1) DEFAULT 0,
        summary_sent_at datetime DEFAULT NULL,
        user_ip varchar(45) DEFAULT NULL,
        user_agent text,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY session_id (session_id),
        KEY user_id (user_id),
        KEY user_email (user_email),
        KEY conversation_status (conversation_status),
        KEY started_at (started_at),
        KEY idle_since (idle_since)
    ) $charset_collate;";
    dbDelta($sql);
    $tables_created[] = 'mld_chat_conversations';

    // Table 2: Chat Messages
    $sql = "CREATE TABLE {$wpdb->prefix}mld_chat_messages (
        id bigint unsigned NOT NULL AUTO_INCREMENT,
        conversation_id bigint unsigned NOT NULL,
        session_id varchar(100) NOT NULL,
        sender_type varchar(20) NOT NULL,
        message_text text NOT NULL,
        ai_provider varchar(50) DEFAULT NULL,
        ai_model varchar(100) DEFAULT NULL,
        ai_tokens_used int DEFAULT NULL,
        ai_context_injected text,
        response_time_ms int DEFAULT NULL,
        is_fallback tinyint(1) DEFAULT 0,
        fallback_reason varchar(255) DEFAULT NULL,
        message_metadata longtext,
        admin_notified tinyint(1) DEFAULT 0,
        admin_notification_sent_at datetime DEFAULT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY conversation_id (conversation_id),
        KEY session_id (session_id),
        KEY sender_type (sender_type),
        KEY created_at (created_at),
        KEY admin_notified (admin_notified)
    ) $charset_collate;";
    dbDelta($sql);
    $tables_created[] = 'mld_chat_messages';

    // Add foreign key constraint for messages table
    $wpdb->query("ALTER TABLE {$wpdb->prefix}mld_chat_messages
        ADD CONSTRAINT fk_message_conversation
        FOREIGN KEY (conversation_id)
        REFERENCES {$wpdb->prefix}mld_chat_conversations(id)
        ON DELETE CASCADE ON UPDATE CASCADE");

    // Table 3: Chat Sessions
    $sql = "CREATE TABLE {$wpdb->prefix}mld_chat_sessions (
        id bigint unsigned NOT NULL AUTO_INCREMENT,
        session_id varchar(100) NOT NULL,
        conversation_id bigint unsigned DEFAULT NULL,
        session_status varchar(20) DEFAULT 'active',
        last_activity_at datetime DEFAULT CURRENT_TIMESTAMP,
        idle_timeout_minutes int DEFAULT 10,
        window_closed tinyint(1) DEFAULT 0,
        window_closed_at datetime DEFAULT NULL,
        page_url varchar(500) DEFAULT NULL,
        referrer_url varchar(500) DEFAULT NULL,
        device_type varchar(50) DEFAULT NULL,
        browser varchar(100) DEFAULT NULL,
        session_data longtext,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY session_id (session_id),
        KEY conversation_id (conversation_id),
        KEY session_status (session_status),
        KEY last_activity_at (last_activity_at),
        KEY window_closed (window_closed)
    ) $charset_collate;";
    dbDelta($sql);
    $tables_created[] = 'mld_chat_sessions';

    // Add foreign key constraint for sessions table
    $wpdb->query("ALTER TABLE {$wpdb->prefix}mld_chat_sessions
        ADD CONSTRAINT fk_session_conversation
        FOREIGN KEY (conversation_id)
        REFERENCES {$wpdb->prefix}mld_chat_conversations(id)
        ON DELETE CASCADE ON UPDATE CASCADE");

    // Table 4: Chat Settings
    $sql = "CREATE TABLE {$wpdb->prefix}mld_chat_settings (
        id bigint unsigned NOT NULL AUTO_INCREMENT,
        setting_key varchar(100) NOT NULL,
        setting_value longtext,
        setting_type varchar(50) DEFAULT 'string',
        setting_category varchar(50) DEFAULT 'general',
        is_encrypted tinyint(1) DEFAULT 0,
        description text,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY unique_setting_key (setting_key),
        KEY setting_category (setting_category)
    ) $charset_collate;";
    dbDelta($sql);
    $tables_created[] = 'mld_chat_settings';

    // Table 5: FAQ Library
    $sql = "CREATE TABLE {$wpdb->prefix}mld_chat_faq_library (
        id bigint unsigned NOT NULL AUTO_INCREMENT,
        question text NOT NULL,
        answer longtext NOT NULL,
        keywords varchar(500) DEFAULT NULL,
        category varchar(100) DEFAULT NULL,
        usage_count int DEFAULT 0,
        last_used_at datetime DEFAULT NULL,
        is_active tinyint(1) DEFAULT 1,
        priority int DEFAULT 0,
        created_by bigint unsigned DEFAULT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY category (category),
        KEY is_active (is_active),
        KEY priority (priority)
    ) $charset_collate;";
    dbDelta($sql);
    $tables_created[] = 'mld_chat_faq_library';

    // Add fulltext index to FAQ library
    $wpdb->query("ALTER TABLE {$wpdb->prefix}mld_chat_faq_library
        ADD FULLTEXT KEY faq_search (question, answer, keywords)");

    // Table 6: Knowledge Base
    $sql = "CREATE TABLE {$wpdb->prefix}mld_chat_knowledge_base (
        id bigint unsigned NOT NULL AUTO_INCREMENT,
        content_type varchar(50) NOT NULL,
        content_id varchar(100) DEFAULT NULL,
        content_title varchar(500) DEFAULT NULL,
        content_text longtext,
        content_summary text,
        content_url varchar(500) DEFAULT NULL,
        content_metadata longtext,
        embedding_vector longtext,
        relevance_score float DEFAULT 1,
        scan_date datetime DEFAULT CURRENT_TIMESTAMP,
        is_active tinyint(1) DEFAULT 1,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY content_type (content_type),
        KEY content_id (content_id),
        KEY scan_date (scan_date),
        KEY is_active (is_active)
    ) $charset_collate;";
    dbDelta($sql);
    $tables_created[] = 'mld_chat_knowledge_base';

    // Add fulltext index to knowledge base
    $wpdb->query("ALTER TABLE {$wpdb->prefix}mld_chat_knowledge_base
        ADD FULLTEXT KEY content_search (content_title, content_text, content_summary)");

    // Table 7: Admin Notifications
    $sql = "CREATE TABLE {$wpdb->prefix}mld_chat_admin_notifications (
        id bigint unsigned NOT NULL AUTO_INCREMENT,
        conversation_id bigint unsigned NOT NULL,
        message_id bigint unsigned NOT NULL,
        notification_type varchar(50) DEFAULT 'new_message',
        admin_email varchar(255) NOT NULL,
        notification_status varchar(20) DEFAULT 'pending',
        notification_data longtext,
        sent_at datetime DEFAULT NULL,
        error_message text,
        retry_count int DEFAULT 0,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY conversation_id (conversation_id),
        KEY message_id (message_id),
        KEY notification_status (notification_status),
        KEY admin_email (admin_email),
        KEY created_at (created_at)
    ) $charset_collate;";
    dbDelta($sql);
    $tables_created[] = 'mld_chat_admin_notifications';

    // Add foreign key constraints for admin notifications table
    $wpdb->query("ALTER TABLE {$wpdb->prefix}mld_chat_admin_notifications
        ADD CONSTRAINT fk_admin_notif_conversation
        FOREIGN KEY (conversation_id)
        REFERENCES {$wpdb->prefix}mld_chat_conversations(id)
        ON DELETE CASCADE ON UPDATE CASCADE");

    $wpdb->query("ALTER TABLE {$wpdb->prefix}mld_chat_admin_notifications
        ADD CONSTRAINT fk_admin_notif_message
        FOREIGN KEY (message_id)
        REFERENCES {$wpdb->prefix}mld_chat_messages(id)
        ON DELETE CASCADE ON UPDATE CASCADE");

    // Table 8: Email Summaries
    $sql = "CREATE TABLE {$wpdb->prefix}mld_chat_email_summaries (
        id bigint unsigned NOT NULL AUTO_INCREMENT,
        conversation_id bigint unsigned NOT NULL,
        recipient_email varchar(255) NOT NULL,
        recipient_name varchar(255) DEFAULT NULL,
        summary_html longtext,
        summary_text longtext,
        properties_discussed text,
        next_steps text,
        trigger_reason varchar(50) DEFAULT NULL,
        email_status varchar(20) DEFAULT 'pending',
        sent_at datetime DEFAULT NULL,
        error_message text,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY conversation_id (conversation_id),
        KEY recipient_email (recipient_email),
        KEY email_status (email_status),
        KEY created_at (created_at)
    ) $charset_collate;";
    dbDelta($sql);
    $tables_created[] = 'mld_chat_email_summaries';

    // Add foreign key constraint for email summaries table
    $wpdb->query("ALTER TABLE {$wpdb->prefix}mld_chat_email_summaries
        ADD CONSTRAINT fk_email_summary_conversation
        FOREIGN KEY (conversation_id)
        REFERENCES {$wpdb->prefix}mld_chat_conversations(id)
        ON DELETE CASCADE ON UPDATE CASCADE");

    // Verify all tables were created
    foreach ($tables_created as $table_suffix) {
        $table_name = $wpdb->prefix . $table_suffix;
        $exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'");
        if (!$exists) {
            $tables_failed[] = $table_suffix;
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("[MLD Update 6.6.1] FAILED to create table: {$table_name}");
            }
        }
    }

    // Insert default chatbot settings
    $default_settings = array(
        array('chatbot_enabled', '1', 'string', 'general', 'Enable/disable chatbot'),
        array('chatbot_greeting', 'Hi! I\'m your real estate assistant. How can I help you find your dream home today?', 'string', 'general', 'Initial chatbot greeting'),
        array('ai_provider', 'test', 'string', 'ai_config', 'AI provider to use'),
        array('ai_model', 'gpt-3.5-turbo', 'string', 'ai_config', 'AI model'),
        array('ai_temperature', '0.7', 'string', 'ai_config', 'AI temperature'),
        array('ai_max_tokens', '500', 'string', 'ai_config', 'Maximum tokens per response'),
        array('admin_notifications_enabled', '1', 'string', 'notifications', 'Enable admin email notifications'),
        array('admin_notification_email', get_option('admin_email'), 'string', 'notifications', 'Admin notification email'),
        array('knowledge_scan_enabled', '1', 'string', 'knowledge', 'Enable automatic knowledge scanning'),
        array('fallback_to_faq', '1', 'string', 'general', 'Check FAQ before using AI'),
    );

    foreach ($default_settings as $setting) {
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}mld_chat_settings WHERE setting_key = %s",
            $setting[0]
        ));

        if (!$exists) {
            $wpdb->insert(
                $wpdb->prefix . 'mld_chat_settings',
                array(
                    'setting_key' => $setting[0],
                    'setting_value' => $setting[1],
                    'setting_type' => $setting[2],
                    'setting_category' => $setting[3],
                    'description' => $setting[4],
                ),
                array('%s', '%s', '%s', '%s', '%s')
            );
        }
    }

    // Update version number
    update_option('mld_db_version', '6.6.1');

    $success = empty($tables_failed);

    if (defined('WP_DEBUG') && WP_DEBUG) {
        if ($success) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[MLD Update 6.6.1] Successfully created all ' . count($tables_created) . ' chatbot tables');
            }
        } else {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[MLD Update 6.6.1] Failed to create ' . count($tables_failed) . ' tables: ' . implode(', ', $tables_failed));
            }
        }
    }

    return $success;
}
