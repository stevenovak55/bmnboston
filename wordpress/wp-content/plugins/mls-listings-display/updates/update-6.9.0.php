<?php
/**
 * Update to version 6.9.0
 *
 * Adds:
 * - Prompt variants table for A/B testing
 * - Prompt performance tracking
 * - Extended prompt variables (business hours, specialties, service areas)
 *
 * @package MLS_Listings_Display
 * @since 6.9.0
 */

function mld_update_to_6_9_0() {
    global $wpdb;
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

    $charset_collate = $wpdb->get_charset_collate();

    // Create prompt variants table for A/B testing
    $table_name = $wpdb->prefix . 'mld_prompt_variants';
    $sql = "CREATE TABLE {$table_name} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        variant_name varchar(100) NOT NULL,
        prompt_content text NOT NULL,
        is_active tinyint(1) DEFAULT 1,
        weight int DEFAULT 50 COMMENT 'Traffic percentage (0-100)',
        total_uses int DEFAULT 0,
        total_ratings int DEFAULT 0,
        average_rating decimal(3,2) DEFAULT NULL,
        positive_feedback int DEFAULT 0,
        negative_feedback int DEFAULT 0,
        created_by bigint(20) unsigned DEFAULT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_active (is_active),
        KEY idx_created_at (created_at)
    ) $charset_collate;";

    dbDelta($sql);

    // Verify prompt variants table creation
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'");
    if (!$table_exists) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[MLD Update 6.9.0] Failed to create prompt variants table');
        }
        return false;
    }

    // Create prompt usage tracking table
    $table_name = $wpdb->prefix . 'mld_prompt_usage';
    $sql = "CREATE TABLE {$table_name} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        conversation_id bigint(20) unsigned NOT NULL,
        variant_id bigint(20) unsigned DEFAULT NULL,
        prompt_used text NOT NULL,
        user_rating tinyint DEFAULT NULL,
        response_time_ms int DEFAULT NULL,
        tokens_used int DEFAULT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_conversation (conversation_id),
        KEY idx_variant (variant_id),
        KEY idx_created_at (created_at),
        KEY idx_rating (user_rating)
    ) $charset_collate;";

    dbDelta($sql);

    // Verify prompt usage table creation
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'");
    if (!$table_exists) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[MLD Update 6.9.0] Failed to create prompt usage table');
        }
        return false;
    }

    // Add extended prompt variables settings
    $settings_to_add = array(
        array(
            'setting_key' => 'business_hours',
            'setting_value' => 'Monday - Friday: 9:00 AM - 6:00 PM, Saturday: 10:00 AM - 4:00 PM, Sunday: Closed',
            'setting_category' => 'prompt_variables',
            'setting_type' => 'text',
            'description' => 'Business hours for {business_hours} placeholder'
        ),
        array(
            'setting_key' => 'specialties',
            'setting_value' => 'Residential Sales, First-Time Home Buyers, Investment Properties',
            'setting_category' => 'prompt_variables',
            'setting_type' => 'text',
            'description' => 'Business specialties for {specialties} placeholder'
        ),
        array(
            'setting_key' => 'service_areas',
            'setting_value' => 'Greater Boston Area, Cambridge, Somerville, Brookline',
            'setting_category' => 'prompt_variables',
            'setting_type' => 'text',
            'description' => 'Service areas for {service_areas} placeholder'
        ),
        array(
            'setting_key' => 'contact_phone',
            'setting_value' => '',
            'setting_category' => 'prompt_variables',
            'setting_type' => 'text',
            'description' => 'Contact phone for {contact_phone} placeholder'
        ),
        array(
            'setting_key' => 'contact_email',
            'setting_value' => get_option('admin_email'),
            'setting_category' => 'prompt_variables',
            'setting_type' => 'text',
            'description' => 'Contact email for {contact_email} placeholder'
        ),
        array(
            'setting_key' => 'team_size',
            'setting_value' => '',
            'setting_category' => 'prompt_variables',
            'setting_type' => 'text',
            'description' => 'Team size for {team_size} placeholder'
        ),
        array(
            'setting_key' => 'years_in_business',
            'setting_value' => '',
            'setting_category' => 'prompt_variables',
            'setting_type' => 'text',
            'description' => 'Years in business for {years_in_business} placeholder'
        ),
        array(
            'setting_key' => 'enable_ab_testing',
            'setting_value' => '0',
            'setting_category' => 'ai_config',
            'setting_type' => 'boolean',
            'description' => 'Enable A/B testing for system prompts'
        ),
    );

    foreach ($settings_to_add as $setting) {
        // Check if setting already exists
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}mld_chat_settings WHERE setting_key = %s",
            $setting['setting_key']
        ));

        if (!$existing) {
            $wpdb->insert(
                $wpdb->prefix . 'mld_chat_settings',
                $setting,
                array('%s', '%s', '%s', '%s', '%s')
            );
        }
    }

    // Create default "Control" variant using current system prompt if A/B testing is enabled
    $current_prompt = $wpdb->get_var($wpdb->prepare(
        "SELECT setting_value FROM {$wpdb->prefix}mld_chat_settings WHERE setting_key = %s",
        'system_prompt'
    ));

    if ($current_prompt) {
        // Check if control variant exists
        $control_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}mld_prompt_variants WHERE variant_name = %s",
            'Control (Original)'
        ));

        if (!$control_exists) {
            $wpdb->insert(
                $wpdb->prefix . 'mld_prompt_variants',
                array(
                    'variant_name' => 'Control (Original)',
                    'prompt_content' => $current_prompt,
                    'is_active' => 1,
                    'weight' => 100,
                ),
                array('%s', '%s', '%d', '%d')
            );
        }
    }

    // Update version
    update_option('mld_db_version', '6.9.0');

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[MLD Update 6.9.0] Update completed successfully');
    }
    return true;
}
