<?php
/**
 * Update to version 6.8.0
 *
 * Adds:
 * - Training examples table for conversation feedback
 * - System prompt customization settings
 *
 * @package MLS_Listings_Display
 * @since 6.8.0
 */

function mld_update_to_6_8_0() {
    global $wpdb;
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

    $charset_collate = $wpdb->get_charset_collate();

    // Create training examples table
    $table_name = $wpdb->prefix . 'mld_chat_training';
    $sql = "CREATE TABLE {$table_name} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        example_type enum('good','bad','needs_improvement') NOT NULL DEFAULT 'good',
        user_message text NOT NULL,
        ai_response text NOT NULL,
        feedback_notes text DEFAULT NULL,
        conversation_context text DEFAULT NULL,
        ai_provider varchar(50) DEFAULT NULL,
        ai_model varchar(100) DEFAULT NULL,
        tokens_used int DEFAULT NULL,
        rating tinyint DEFAULT NULL COMMENT 'Optional 1-5 rating',
        tags varchar(255) DEFAULT NULL COMMENT 'Comma-separated tags',
        created_by bigint(20) unsigned DEFAULT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_example_type (example_type),
        KEY idx_created_at (created_at),
        KEY idx_created_by (created_by)
    ) $charset_collate;";

    dbDelta($sql);

    // Verify table creation
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'");

    if (!$table_exists) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[MLD Update 6.8.0] Failed to create training examples table');
        }
        return false;
    }

    // Add default system prompt setting
    $default_prompt = "You are a professional real estate assistant for {business_name}.

Your role:
- Help users find properties that match their needs
- Answer questions about our listings and services
- Provide helpful real estate information
- Be friendly, professional, and knowledgeable

Guidelines:
- Keep responses concise (2-3 paragraphs max)
- Use a warm, conversational tone
- If you don't know something, be honest
- Always encourage users to contact us for detailed help

Available data:
{current_listings_count} active listings
Price range: {price_range}
Property types: Residential, Commercial, Land

When users ask about specific properties, provide general guidance and suggest they use our search tools for detailed results.";

    // Check if system_prompt already exists
    $existing_prompt = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}mld_chat_settings WHERE setting_key = %s",
        'system_prompt'
    ));

    if (!$existing_prompt) {
        $wpdb->insert(
            $wpdb->prefix . 'mld_chat_settings',
            array(
                'setting_key' => 'system_prompt',
                'setting_value' => $default_prompt,
                'setting_category' => 'ai_config',
                'setting_type' => 'textarea',
                'description' => 'The system prompt that guides AI behavior',
            ),
            array('%s', '%s', '%s', '%s', '%s')
        );
    }

    // Update version
    update_option('mld_db_version', '6.8.0');

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[MLD Update 6.8.0] Update completed successfully');
    }
    return true;
}
