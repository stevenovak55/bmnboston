<?php
/**
 * MLD Update to Version 6.14.0
 * AI Chatbot Context Persistence & Comprehensive Property Data
 *
 * Features:
 * - Search context persistence (remembers city, price, bedrooms between messages)
 * - Shown properties tracking (enables "show me #5" references)
 * - Active property storage (full property data for follow-up questions)
 * - Collected user info (name, phone, email - never ask twice)
 * - Returning visitor recognition
 *
 * @since 6.14.0
 */

function mld_update_to_6_14_0() {
    global $wpdb;

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[MLD Update 6.14.0] Starting chatbot context persistence database update');
    }

    // 1. Add context columns to conversations table
    $table_name = $wpdb->prefix . 'mld_chat_conversations';
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'");

    if ($table_exists) {
        $columns_to_add = array(
            // Store collected user info (name, phone, email, preferences)
            'collected_info' => "LONGTEXT NULL COMMENT 'JSON: Collected user info'",

            // Store active search criteria for context persistence
            'search_context' => "LONGTEXT NULL COMMENT 'JSON: Active search criteria'",

            // Store recently shown properties for reference resolution
            'shown_properties' => "LONGTEXT NULL COMMENT 'JSON: Recently shown properties'",

            // Store active property ID for detailed Q&A
            'active_property_id' => "VARCHAR(50) NULL COMMENT 'Current property being discussed'",

            // Conversation state for flow management
            'conversation_state' => "VARCHAR(50) DEFAULT 'initial_greeting' COMMENT 'Current conversation state'",

            // Agent assignment tracking
            'agent_assigned_id' => "INT NULL COMMENT 'Assigned agent user ID'",
            'agent_assigned_at' => "DATETIME NULL",
            'agent_connected_at' => "DATETIME NULL",

            // Lead tracking
            'property_interest' => "VARCHAR(255) NULL COMMENT 'Primary property interest'",
            'lead_score' => "INT DEFAULT 0 COMMENT 'Lead quality score 0-100'",
        );

        foreach ($columns_to_add as $column => $definition) {
            $column_exists = $wpdb->get_var("SHOW COLUMNS FROM $table_name LIKE '$column'");
            if (!$column_exists) {
                $result = $wpdb->query("ALTER TABLE $table_name ADD COLUMN $column $definition");
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    if ($result !== false) {
                        error_log("[MLD Update 6.14.0] Added column $column to conversations table");
                    } else {
                        error_log("[MLD Update 6.14.0] Failed to add column $column: " . $wpdb->last_error);
                    }
                }
            }
        }

        // Add indexes for performance
        $indexes_to_add = array(
            'idx_conversation_state' => 'conversation_state',
            'idx_active_property' => 'active_property_id',
            'idx_agent_assigned' => 'agent_assigned_id',
        );

        foreach ($indexes_to_add as $index_name => $column) {
            $index_exists = $wpdb->get_var("SHOW INDEX FROM $table_name WHERE Key_name = '$index_name'");
            if (!$index_exists) {
                $column_exists = $wpdb->get_var("SHOW COLUMNS FROM $table_name LIKE '$column'");
                if ($column_exists) {
                    $wpdb->query("ALTER TABLE $table_name ADD INDEX $index_name ($column)");
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log("[MLD Update 6.14.0] Added index $index_name");
                    }
                }
            }
        }
    }

    // 2. Add index on user_email for returning visitor lookup
    if ($table_exists) {
        $index_exists = $wpdb->get_var("SHOW INDEX FROM $table_name WHERE Key_name = 'idx_user_email_lookup'");
        if (!$index_exists) {
            $wpdb->query("ALTER TABLE $table_name ADD INDEX idx_user_email_lookup (user_email, user_name)");
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[MLD Update 6.14.0] Added index for returning visitor lookup');
            }
        }
    }

    // 3. Add new settings for context features
    $settings_table = $wpdb->prefix . 'mld_chat_settings';
    $settings_exists = $wpdb->get_var("SHOW TABLES LIKE '$settings_table'");

    if ($settings_exists) {
        $new_settings = array(
            array(
                'setting_key' => 'context_persistence_enabled',
                'setting_value' => '1',
                'setting_category' => 'conversation',
            ),
            array(
                'setting_key' => 'returning_visitor_recognition',
                'setting_value' => '1',
                'setting_category' => 'conversation',
            ),
            array(
                'setting_key' => 'comprehensive_property_data',
                'setting_value' => '1',
                'setting_category' => 'conversation',
            ),
            array(
                'setting_key' => 'shown_properties_limit',
                'setting_value' => '10',
                'setting_category' => 'conversation',
            ),
            array(
                'setting_key' => 'search_context_timeout_hours',
                'setting_value' => '24',
                'setting_category' => 'conversation',
            ),
        );

        foreach ($new_settings as $setting) {
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $settings_table WHERE setting_key = %s",
                $setting['setting_key']
            ));

            if (!$exists) {
                $wpdb->insert($settings_table, array(
                    'setting_key' => $setting['setting_key'],
                    'setting_value' => $setting['setting_value'],
                    'setting_category' => $setting['setting_category'],
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql'),
                ));
            }
        }
    }

    // Update the database version
    update_option('mld_db_version', '6.14.0');
    update_option('mld_version', '6.14.0');

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[MLD Update 6.14.0] Database update completed successfully');
    }

    return true;
}
