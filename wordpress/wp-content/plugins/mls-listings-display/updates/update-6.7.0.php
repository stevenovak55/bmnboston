<?php
/**
 * MLD Update to Version 6.7.0
 * Enhanced Chatbot System with Natural Conversation Flow
 */

function mld_update_to_6_7_0() {
    global $wpdb;
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[MLD Update 6.7.0] Starting enhanced chatbot system database update');
    }

    $charset_collate = $wpdb->get_charset_collate();

    // 1. Create new chat_data_references table
    $table_name = $wpdb->prefix . 'mld_chat_data_references';
    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id int(11) NOT NULL AUTO_INCREMENT,
        reference_key varchar(100) NOT NULL,
        table_name varchar(100) NOT NULL,
        query_template text NOT NULL,
        join_tables text NULL,
        filter_params text NULL,
        cache_duration int(11) DEFAULT 3600,
        description text NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_reference_key (reference_key)
    ) $charset_collate;";

    dbDelta($sql);

    // 2. Create chat_response_cache table
    $table_name = $wpdb->prefix . 'mld_chat_response_cache';
    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id int(11) NOT NULL AUTO_INCREMENT,
        question_hash varchar(64) NOT NULL,
        question text NOT NULL,
        response text NOT NULL,
        response_type varchar(50) DEFAULT 'database',
        confidence_score float DEFAULT 0,
        hit_count int(11) DEFAULT 0,
        last_accessed datetime DEFAULT CURRENT_TIMESTAMP,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        expires_at datetime NULL,
        PRIMARY KEY (id),
        UNIQUE KEY idx_question_hash (question_hash),
        KEY idx_expires (expires_at)
    ) $charset_collate;";

    dbDelta($sql);

    // 3. Create chat_state_history table
    $table_name = $wpdb->prefix . 'mld_chat_state_history';
    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id int(11) NOT NULL AUTO_INCREMENT,
        conversation_id int(11) NOT NULL,
        state_from varchar(50) NULL,
        state_to varchar(50) NOT NULL,
        transition_reason text NULL,
        metadata longtext NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_conversation (conversation_id),
        KEY idx_created (created_at)
    ) $charset_collate;";

    dbDelta($sql);

    // 4. Create chat_agent_assignments table
    $table_name = $wpdb->prefix . 'mld_chat_agent_assignments';
    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id int(11) NOT NULL AUTO_INCREMENT,
        conversation_id int(11) NOT NULL,
        agent_id int(11) NOT NULL,
        assignment_type varchar(50) DEFAULT 'round_robin',
        priority varchar(20) DEFAULT 'normal',
        notification_sent tinyint(1) DEFAULT 0,
        notification_method varchar(50) NULL,
        assigned_at datetime DEFAULT CURRENT_TIMESTAMP,
        accepted_at datetime NULL,
        completed_at datetime NULL,
        status varchar(50) DEFAULT 'pending',
        notes text NULL,
        PRIMARY KEY (id),
        KEY idx_conversation (conversation_id),
        KEY idx_agent (agent_id),
        KEY idx_status (status)
    ) $charset_collate;";

    dbDelta($sql);

    // 5. Create chat_query_patterns table
    $table_name = $wpdb->prefix . 'mld_chat_query_patterns';
    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id int(11) NOT NULL AUTO_INCREMENT,
        pattern_regex text NOT NULL,
        pattern_category varchar(100) NOT NULL,
        data_source varchar(100) NOT NULL,
        priority int(11) DEFAULT 10,
        example_questions text NULL,
        response_template text NULL,
        required_params text NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_category (pattern_category),
        KEY idx_priority (priority)
    ) $charset_collate;";

    dbDelta($sql);

    // 6. Update existing conversations table with new columns (check if columns exist first)
    $table_name = $wpdb->prefix . 'mld_chatbot_conversations';

    // Check if table exists
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'");

    if ($table_exists) {
        // Check and add each column if it doesn't exist
        $columns_to_add = array(
            'conversation_state' => "VARCHAR(50) DEFAULT 'initial_greeting'",
            'collected_info' => "LONGTEXT NULL",
            'agent_assigned_id' => "INT NULL",
            'agent_assigned_at' => "DATETIME NULL",
            'agent_connected_at' => "DATETIME NULL",
            'property_interest' => "VARCHAR(255) NULL",
            'lead_score' => "INT DEFAULT 0"
        );

        foreach ($columns_to_add as $column => $definition) {
            $column_exists = $wpdb->get_var("SHOW COLUMNS FROM $table_name LIKE '$column'");
            if (!$column_exists) {
                $wpdb->query("ALTER TABLE $table_name ADD COLUMN $column $definition");
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log("[MLD Update 6.7.0] Added column $column to conversations table");
                }
            }
        }

        // Add indexes if they don't exist
        $index_exists = $wpdb->get_var("SHOW INDEX FROM $table_name WHERE Key_name = 'idx_conversation_state'");
        if (!$index_exists) {
            $wpdb->query("ALTER TABLE $table_name ADD INDEX idx_conversation_state (conversation_state)");
        }

        $index_exists = $wpdb->get_var("SHOW INDEX FROM $table_name WHERE Key_name = 'idx_agent_assigned'");
        if (!$index_exists) {
            $wpdb->query("ALTER TABLE $table_name ADD INDEX idx_agent_assigned (agent_assigned_id)");
        }
    }

    // 7. Update knowledge_base table with new columns
    $table_name = $wpdb->prefix . 'mld_chat_knowledge_base';
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'");

    if ($table_exists) {
        $columns_to_add = array(
            'entry_type' => "VARCHAR(50) DEFAULT 'content'",
            'reference_table' => "VARCHAR(100) NULL",
            'reference_query' => "TEXT NULL",
            'cache_ttl' => "INT DEFAULT 3600",
            'hit_count' => "INT DEFAULT 0"
        );

        foreach ($columns_to_add as $column => $definition) {
            $column_exists = $wpdb->get_var("SHOW COLUMNS FROM $table_name LIKE '$column'");
            if (!$column_exists) {
                $wpdb->query("ALTER TABLE $table_name ADD COLUMN $column $definition");
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log("[MLD Update 6.7.0] Added column $column to knowledge_base table");
                }
            }
        }

        // Add indexes if they don't exist
        $index_exists = $wpdb->get_var("SHOW INDEX FROM $table_name WHERE Key_name = 'idx_entry_type'");
        if (!$index_exists) {
            $wpdb->query("ALTER TABLE $table_name ADD INDEX idx_entry_type (entry_type)");
        }

        $index_exists = $wpdb->get_var("SHOW INDEX FROM $table_name WHERE Key_name = 'idx_hit_count'");
        if (!$index_exists) {
            $wpdb->query("ALTER TABLE $table_name ADD INDEX idx_hit_count (hit_count)");
        }
    }

    // 8. Insert default query patterns for property questions
    $table_name = $wpdb->prefix . 'mld_chat_query_patterns';
    $default_patterns = array(
        array(
            'pattern_regex' => '/(how many|count|number of).*(properties|listings|homes).*(in|for|at)?\s+([a-zA-Z\s]+)/i',
            'pattern_category' => 'property_count',
            'data_source' => 'bme_listings',
            'priority' => 10,
            'example_questions' => 'How many properties are in Boston?|Count of homes in Cambridge|Number of listings in Brookline',
            'response_template' => 'There are {count} active properties in {location}.',
            'required_params' => 'location'
        ),
        array(
            'pattern_regex' => '/(average|median|typical).*(price|cost).*(in|for|at)?\s+([a-zA-Z\s]+)/i',
            'pattern_category' => 'price_analytics',
            'data_source' => 'market_analytics',
            'priority' => 10,
            'example_questions' => 'Average price in Boston?|Median cost in Cambridge|Typical home price in Brookline',
            'response_template' => 'The {metric} price in {location} is ${value:number}.',
            'required_params' => 'location,metric'
        ),
        array(
            'pattern_regex' => '/(show|list|find|search).*(properties|homes|listings).*(under|below|less than)\s+\$?([0-9,]+)/i',
            'pattern_category' => 'property_search_price',
            'data_source' => 'bme_listings',
            'priority' => 10,
            'example_questions' => 'Show homes under 500000|List properties below $600k|Find listings less than 400,000',
            'response_template' => 'I found {count} properties under ${max_price:number}. Would you like to see them?',
            'required_params' => 'max_price'
        )
    );

    foreach ($default_patterns as $pattern) {
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table_name WHERE pattern_category = %s AND pattern_regex = %s",
            $pattern['pattern_category'],
            $pattern['pattern_regex']
        ));

        if (!$exists) {
            $wpdb->insert($table_name, $pattern);
        }
    }

    // 9. Insert default data references
    $table_name = $wpdb->prefix . 'mld_chat_data_references';
    $default_refs = array(
        array(
            'reference_key' => 'active_listings_count',
            'table_name' => 'bme_listings',
            'query_template' => "SELECT COUNT(*) as count FROM {prefix}bme_listings WHERE standard_status = 'Active'",
            'cache_duration' => 3600,
            'description' => 'Count of active property listings'
        ),
        array(
            'reference_key' => 'city_listings',
            'table_name' => 'bme_listings',
            'query_template' => "SELECT * FROM {prefix}bme_listings WHERE city = %s AND standard_status = 'Active' ORDER BY list_price DESC LIMIT %d",
            'filter_params' => 'city,limit',
            'cache_duration' => 1800,
            'description' => 'Listings by city'
        ),
        array(
            'reference_key' => 'price_range_listings',
            'table_name' => 'bme_listings',
            'query_template' => "SELECT * FROM {prefix}bme_listings WHERE list_price BETWEEN %d AND %d AND standard_status = 'Active' ORDER BY list_price ASC",
            'filter_params' => 'min_price,max_price',
            'cache_duration' => 1800,
            'description' => 'Listings within price range'
        ),
        array(
            'reference_key' => 'market_stats_city',
            'table_name' => 'bme_listing_summary',
            'query_template' => "SELECT AVG(list_price) as avg_price, MIN(list_price) as min_price, MAX(list_price) as max_price, COUNT(*) as total FROM {prefix}bme_listing_summary WHERE city = %s AND standard_status = 'Active'",
            'filter_params' => 'city',
            'cache_duration' => 7200,
            'description' => 'Market statistics by city'
        )
    );

    foreach ($default_refs as $ref) {
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table_name WHERE reference_key = %s",
            $ref['reference_key']
        ));

        if (!$exists) {
            $wpdb->insert($table_name, $ref);
        }
    }

    // 10. Update settings table for new chatbot features
    $table_name = $wpdb->prefix . 'mld_chat_settings';
    $new_settings = array(
        'conversation_flow_enabled' => '1',
        'collect_user_info' => '1',
        'agent_handoff_enabled' => '1',
        'response_caching_enabled' => '1',
        'cache_duration_hours' => '24',
        'token_optimization' => '1',
        'natural_conversation_mode' => '1',
        'lead_scoring_enabled' => '1',
        'auto_agent_assignment' => '1',
        'assignment_method' => 'round_robin'
    );

    foreach ($new_settings as $key => $value) {
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table_name WHERE setting_key = %s",
            $key
        ));

        if (!$exists) {
            $wpdb->insert($table_name, array(
                'setting_key' => $key,
                'setting_value' => $value,
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ));
        }
    }

    // Update the database version
    update_option('mld_db_version', '6.7.0');
    update_option('mld_version', '6.7.0');

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[MLD Update 6.7.0] Database update completed successfully');
    }

    return true;
}