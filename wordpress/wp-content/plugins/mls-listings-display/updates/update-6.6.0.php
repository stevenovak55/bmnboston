<?php
/**
 * Update to version 6.6.0
 *
 * AI-Powered Chatbot System with Multi-Provider Support
 * Database changes:
 * - Add mld_chat_conversations table for conversation tracking
 * - Add mld_chat_messages table for individual messages
 * - Add mld_chat_sessions table for active session management
 * - Add mld_chat_admin_notifications table for real-time admin alerts
 * - Add mld_chat_email_summaries table for conversation summaries
 * - Add mld_chat_settings table for AI configuration and API keys
 * - Add mld_chat_knowledge_base table for daily website scanning
 * - Add mld_chat_faq_library table for fallback responses
 *
 * @package MLS_Listings_Display
 * @since 6.6.0
 */

if (!defined('ABSPATH')) {
    exit;
}

function mld_update_to_6_6_0() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    $table_prefix = $wpdb->prefix;

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

    // Table 1: Chat Conversations
    $table_name = $table_prefix . 'mld_chat_conversations';
    $sql = "CREATE TABLE {$table_name} (
        id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        session_id varchar(100) NOT NULL,
        user_id bigint(20) UNSIGNED DEFAULT NULL,
        user_email varchar(255) DEFAULT NULL,
        user_name varchar(255) DEFAULT NULL,
        user_phone varchar(50) DEFAULT NULL,
        conversation_status varchar(20) DEFAULT 'active',
        conversation_summary text DEFAULT NULL,
        total_messages int(11) DEFAULT 0,
        last_message_at datetime DEFAULT NULL,
        started_at datetime DEFAULT CURRENT_TIMESTAMP,
        ended_at datetime DEFAULT NULL,
        idle_since datetime DEFAULT NULL,
        summary_sent tinyint(1) DEFAULT 0,
        summary_sent_at datetime DEFAULT NULL,
        user_ip varchar(45) DEFAULT NULL,
        user_agent text DEFAULT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY session_id (session_id),
        KEY user_id (user_id),
        KEY user_email (user_email),
        KEY conversation_status (conversation_status),
        KEY started_at (started_at),
        KEY idle_since (idle_since)
    ) $charset_collate;";

    dbDelta($sql);

    // Table 2: Chat Messages
    $table_name = $table_prefix . 'mld_chat_messages';
    $sql = "CREATE TABLE {$table_name} (
        id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        conversation_id bigint(20) UNSIGNED NOT NULL,
        session_id varchar(100) NOT NULL,
        sender_type varchar(20) NOT NULL,
        message_text text NOT NULL,
        ai_provider varchar(50) DEFAULT NULL,
        ai_model varchar(100) DEFAULT NULL,
        ai_tokens_used int(11) DEFAULT NULL,
        ai_context_injected text DEFAULT NULL,
        response_time_ms int(11) DEFAULT NULL,
        is_fallback tinyint(1) DEFAULT 0,
        fallback_reason varchar(255) DEFAULT NULL,
        message_metadata longtext DEFAULT NULL,
        admin_notified tinyint(1) DEFAULT 0,
        admin_notification_sent_at datetime DEFAULT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY conversation_id (conversation_id),
        KEY session_id (session_id),
        KEY sender_type (sender_type),
        KEY created_at (created_at),
        KEY admin_notified (admin_notified),
        CONSTRAINT fk_message_conversation FOREIGN KEY (conversation_id)
            REFERENCES {$table_prefix}mld_chat_conversations(id)
            ON DELETE CASCADE ON UPDATE CASCADE
    ) $charset_collate;";

    dbDelta($sql);

    // Table 3: Chat Sessions
    $table_name = $table_prefix . 'mld_chat_sessions';
    $sql = "CREATE TABLE {$table_name} (
        id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        session_id varchar(100) NOT NULL,
        conversation_id bigint(20) UNSIGNED DEFAULT NULL,
        session_status varchar(20) DEFAULT 'active',
        last_activity_at datetime DEFAULT CURRENT_TIMESTAMP,
        idle_timeout_minutes int(11) DEFAULT 10,
        window_closed tinyint(1) DEFAULT 0,
        window_closed_at datetime DEFAULT NULL,
        page_url varchar(500) DEFAULT NULL,
        referrer_url varchar(500) DEFAULT NULL,
        device_type varchar(50) DEFAULT NULL,
        browser varchar(100) DEFAULT NULL,
        session_data longtext DEFAULT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY session_id (session_id),
        KEY conversation_id (conversation_id),
        KEY session_status (session_status),
        KEY last_activity_at (last_activity_at),
        KEY window_closed (window_closed),
        CONSTRAINT fk_session_conversation FOREIGN KEY (conversation_id)
            REFERENCES {$table_prefix}mld_chat_conversations(id)
            ON DELETE CASCADE ON UPDATE CASCADE
    ) $charset_collate;";

    dbDelta($sql);

    // Table 4: Admin Notifications Queue
    $table_name = $table_prefix . 'mld_chat_admin_notifications';
    $sql = "CREATE TABLE {$table_name} (
        id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        conversation_id bigint(20) UNSIGNED NOT NULL,
        message_id bigint(20) UNSIGNED NOT NULL,
        notification_type varchar(50) DEFAULT 'new_message',
        admin_email varchar(255) NOT NULL,
        notification_status varchar(20) DEFAULT 'pending',
        sent_at datetime DEFAULT NULL,
        error_message text DEFAULT NULL,
        retry_count int(11) DEFAULT 0,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY conversation_id (conversation_id),
        KEY message_id (message_id),
        KEY notification_status (notification_status),
        KEY admin_email (admin_email),
        KEY created_at (created_at),
        CONSTRAINT fk_admin_notif_conversation FOREIGN KEY (conversation_id)
            REFERENCES {$table_prefix}mld_chat_conversations(id)
            ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT fk_admin_notif_message FOREIGN KEY (message_id)
            REFERENCES {$table_prefix}mld_chat_messages(id)
            ON DELETE CASCADE ON UPDATE CASCADE
    ) $charset_collate;";

    dbDelta($sql);

    // Table 5: Email Summaries
    $table_name = $table_prefix . 'mld_chat_email_summaries';
    $sql = "CREATE TABLE {$table_name} (
        id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        conversation_id bigint(20) UNSIGNED NOT NULL,
        recipient_email varchar(255) NOT NULL,
        recipient_name varchar(255) DEFAULT NULL,
        summary_html longtext DEFAULT NULL,
        summary_text longtext DEFAULT NULL,
        properties_discussed text DEFAULT NULL,
        next_steps text DEFAULT NULL,
        trigger_reason varchar(50) DEFAULT NULL,
        email_status varchar(20) DEFAULT 'pending',
        sent_at datetime DEFAULT NULL,
        error_message text DEFAULT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY conversation_id (conversation_id),
        KEY recipient_email (recipient_email),
        KEY email_status (email_status),
        KEY created_at (created_at),
        CONSTRAINT fk_email_summary_conversation FOREIGN KEY (conversation_id)
            REFERENCES {$table_prefix}mld_chat_conversations(id)
            ON DELETE CASCADE ON UPDATE CASCADE
    ) $charset_collate;";

    dbDelta($sql);

    // Table 6: Chatbot Settings
    $table_name = $table_prefix . 'mld_chat_settings';
    $sql = "CREATE TABLE {$table_name} (
        id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        setting_key varchar(100) NOT NULL,
        setting_value longtext DEFAULT NULL,
        setting_type varchar(50) DEFAULT 'string',
        setting_category varchar(50) DEFAULT 'general',
        is_encrypted tinyint(1) DEFAULT 0,
        description text DEFAULT NULL,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY unique_setting_key (setting_key),
        KEY setting_category (setting_category)
    ) $charset_collate;";

    dbDelta($sql);

    // Table 7: Knowledge Base
    $table_name = $table_prefix . 'mld_chat_knowledge_base';
    $sql = "CREATE TABLE {$table_name} (
        id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        content_type varchar(50) NOT NULL,
        content_id varchar(100) DEFAULT NULL,
        content_title varchar(500) DEFAULT NULL,
        content_text longtext DEFAULT NULL,
        content_summary text DEFAULT NULL,
        content_url varchar(500) DEFAULT NULL,
        content_metadata longtext DEFAULT NULL,
        embedding_vector longtext DEFAULT NULL,
        relevance_score float DEFAULT 1.0,
        scan_date datetime DEFAULT CURRENT_TIMESTAMP,
        is_active tinyint(1) DEFAULT 1,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY content_type (content_type),
        KEY content_id (content_id),
        KEY scan_date (scan_date),
        KEY is_active (is_active),
        FULLTEXT KEY content_search (content_title, content_text, content_summary)
    ) $charset_collate;";

    dbDelta($sql);

    // Table 8: FAQ Library
    $table_name = $table_prefix . 'mld_chat_faq_library';
    $sql = "CREATE TABLE {$table_name} (
        id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        question text NOT NULL,
        answer longtext NOT NULL,
        keywords varchar(500) DEFAULT NULL,
        category varchar(100) DEFAULT NULL,
        usage_count int(11) DEFAULT 0,
        last_used_at datetime DEFAULT NULL,
        is_active tinyint(1) DEFAULT 1,
        priority int(11) DEFAULT 0,
        created_by bigint(20) UNSIGNED DEFAULT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY category (category),
        KEY is_active (is_active),
        KEY priority (priority),
        FULLTEXT KEY faq_search (question, answer, keywords)
    ) $charset_collate;";

    dbDelta($sql);

    // Insert default chatbot settings
    mld_insert_default_chatbot_settings();

    // Insert default FAQ entries
    mld_insert_default_faq_entries();

    // Update plugin version
    update_option('mld_db_version', '6.6.0');

    // Clear any existing caches
    wp_cache_flush();

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[MLD Update 6.6.0] AI Chatbot system database tables created successfully');
    }

    return true;
}

/**
 * Insert default chatbot settings
 */
function mld_insert_default_chatbot_settings() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'mld_chat_settings';

    $defaults = array(
        // AI Provider settings
        array(
            'setting_key' => 'ai_provider',
            'setting_value' => 'test',
            'setting_type' => 'string',
            'setting_category' => 'ai_config',
            'description' => 'Selected AI provider (test, openai, claude, gemini)'
        ),
        array(
            'setting_key' => 'ai_model',
            'setting_value' => 'gpt-3.5-turbo',
            'setting_type' => 'string',
            'setting_category' => 'ai_config',
            'description' => 'Selected AI model'
        ),
        array(
            'setting_key' => 'ai_temperature',
            'setting_value' => '0.7',
            'setting_type' => 'float',
            'setting_category' => 'ai_config',
            'description' => 'AI response creativity (0.0-1.0)'
        ),
        array(
            'setting_key' => 'ai_max_tokens',
            'setting_value' => '500',
            'setting_type' => 'integer',
            'setting_category' => 'ai_config',
            'description' => 'Maximum tokens per response'
        ),

        // Knowledge scanner settings
        array(
            'setting_key' => 'knowledge_scan_enabled',
            'setting_value' => '1',
            'setting_type' => 'boolean',
            'setting_category' => 'knowledge',
            'description' => 'Enable daily knowledge base scanning'
        ),
        array(
            'setting_key' => 'knowledge_scan_time',
            'setting_value' => '02:00',
            'setting_type' => 'string',
            'setting_category' => 'knowledge',
            'description' => 'Daily scan time (HH:MM format)'
        ),
        array(
            'setting_key' => 'knowledge_scan_listings',
            'setting_value' => '1',
            'setting_type' => 'boolean',
            'setting_category' => 'knowledge',
            'description' => 'Scan property listings'
        ),
        array(
            'setting_key' => 'knowledge_scan_pages',
            'setting_value' => '1',
            'setting_type' => 'boolean',
            'setting_category' => 'knowledge',
            'description' => 'Scan pages and posts'
        ),
        array(
            'setting_key' => 'knowledge_scan_analytics',
            'setting_value' => '1',
            'setting_type' => 'boolean',
            'setting_category' => 'knowledge',
            'description' => 'Scan market analytics'
        ),
        array(
            'setting_key' => 'knowledge_scan_faqs',
            'setting_value' => '1',
            'setting_type' => 'boolean',
            'setting_category' => 'knowledge',
            'description' => 'Scan FAQ content'
        ),

        // Notification settings
        array(
            'setting_key' => 'admin_notification_enabled',
            'setting_value' => '1',
            'setting_type' => 'boolean',
            'setting_category' => 'notifications',
            'description' => 'Send admin notifications for new messages'
        ),
        array(
            'setting_key' => 'admin_notification_emails',
            'setting_value' => get_option('admin_email'),
            'setting_type' => 'string',
            'setting_category' => 'notifications',
            'description' => 'Comma-separated admin emails'
        ),
        array(
            'setting_key' => 'user_summary_enabled',
            'setting_value' => '1',
            'setting_type' => 'boolean',
            'setting_category' => 'notifications',
            'description' => 'Send conversation summaries to users'
        ),
        array(
            'setting_key' => 'idle_timeout_minutes',
            'setting_value' => '10',
            'setting_type' => 'integer',
            'setting_category' => 'notifications',
            'description' => 'Minutes before conversation is considered idle'
        ),

        // Chatbot behavior settings
        array(
            'setting_key' => 'chatbot_enabled',
            'setting_value' => '1',
            'setting_type' => 'boolean',
            'setting_category' => 'general',
            'description' => 'Enable AI chatbot on website'
        ),
        array(
            'setting_key' => 'legacy_chatbot_enabled',
            'setting_value' => '0',
            'setting_type' => 'boolean',
            'setting_category' => 'general',
            'description' => 'Enable legacy property chatbot (for contact agent/schedule tour)'
        ),
        array(
            'setting_key' => 'chatbot_greeting',
            'setting_value' => 'Hi! I\'m your real estate assistant. How can I help you find your dream home today?',
            'setting_type' => 'string',
            'setting_category' => 'general',
            'description' => 'Initial greeting message'
        ),
        array(
            'setting_key' => 'response_mode',
            'setting_value' => 'auto',
            'setting_type' => 'string',
            'setting_category' => 'general',
            'description' => 'Response mode (auto, approval_required, hybrid)'
        ),
        array(
            'setting_key' => 'fallback_to_faq',
            'setting_value' => '1',
            'setting_type' => 'boolean',
            'setting_category' => 'general',
            'description' => 'Use FAQ fallback when AI fails'
        ),
        array(
            'setting_key' => 'daily_message_limit',
            'setting_value' => '1000',
            'setting_type' => 'integer',
            'setting_category' => 'general',
            'description' => 'Maximum messages per day (API limit protection)'
        )
    );

    foreach ($defaults as $default) {
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table_name} WHERE setting_key = %s",
            $default['setting_key']
        ));

        if (!$exists) {
            $wpdb->insert(
                $table_name,
                $default,
                array('%s', '%s', '%s', '%s', '%s')
            );
        }
    }
}

/**
 * Insert default FAQ entries
 */
function mld_insert_default_faq_entries() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'mld_chat_faq_library';

    $faqs = array(
        array(
            'question' => 'What areas do you serve?',
            'answer' => 'We serve the Greater Boston area and surrounding communities. For specific coverage areas, please contact us directly.',
            'keywords' => 'areas, service area, location, coverage, cities, towns',
            'category' => 'general',
            'priority' => 10
        ),
        array(
            'question' => 'How do I schedule a property viewing?',
            'answer' => 'You can schedule a property viewing by clicking the "Schedule Tour" button on any listing or by contacting us directly. We\'ll arrange a convenient time for you.',
            'keywords' => 'schedule, viewing, tour, appointment, showing',
            'category' => 'property_viewing',
            'priority' => 10
        ),
        array(
            'question' => 'How do I search for properties?',
            'answer' => 'Use our property search tool to filter by location, price range, number of bedrooms, and more. You can save your searches and get alerts for new listings.',
            'keywords' => 'search, find, filter, properties, homes, listings',
            'category' => 'search',
            'priority' => 10
        ),
        array(
            'question' => 'What is a CMA?',
            'answer' => 'A Comparative Market Analysis (CMA) is a report that shows the estimated value of your home based on recent sales of similar properties in your area.',
            'keywords' => 'cma, market analysis, home value, property value, valuation',
            'category' => 'cma',
            'priority' => 8
        ),
        array(
            'question' => 'How can I save a search?',
            'answer' => 'After performing a search, click the "Save Search" button. You\'ll receive email alerts when new properties matching your criteria become available.',
            'keywords' => 'save search, alerts, notifications, email updates',
            'category' => 'saved_searches',
            'priority' => 8
        ),
        array(
            'question' => 'What information do I need to get pre-approved for a mortgage?',
            'answer' => 'Typically you\'ll need proof of income, employment verification, credit history, and information about your assets and debts. We can connect you with trusted lenders.',
            'keywords' => 'mortgage, pre-approval, financing, loan, lender',
            'category' => 'financing',
            'priority' => 7
        ),
        array(
            'question' => 'How do I contact an agent?',
            'answer' => 'You can contact an agent through the contact form on any property listing, or reach out to us directly via the contact information on our website.',
            'keywords' => 'contact, agent, realtor, reach, call, email',
            'category' => 'contact',
            'priority' => 10
        ),
        array(
            'question' => 'What does "pending" status mean?',
            'answer' => 'A pending status means the seller has accepted an offer and the property is under contract, but the sale hasn\'t closed yet. Sometimes backup offers are still accepted.',
            'keywords' => 'pending, status, under contract, offer accepted',
            'category' => 'listing_status',
            'priority' => 6
        )
    );

    foreach ($faqs as $faq) {
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table_name} WHERE question = %s",
            $faq['question']
        ));

        if (!$exists) {
            $wpdb->insert(
                $table_name,
                $faq,
                array('%s', '%s', '%s', '%s', '%d')
            );
        }
    }
}
