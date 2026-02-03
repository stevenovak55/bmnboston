<?php
/**
 * MLS Listings Display Update Script - Version 6.11.44
 *
 * Fix: Add missing columns to wp_mld_chat_settings table
 *
 * Issue: Production database had incomplete schema missing:
 * - setting_category
 * - setting_type
 * - is_encrypted
 * - description
 *
 * This was caused by the ensure_chatbot_tables() fallback function
 * having an incomplete schema definition.
 *
 * @package MLS_Listings_Display
 * @since 6.11.44
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Update to version 6.11.44
 * Adds missing columns to wp_mld_chat_settings table
 *
 * @return bool True on success
 */
function mld_update_to_6_11_44() {
    global $wpdb;

    $table_name = $wpdb->prefix . 'mld_chat_settings';

    // Check if table exists first
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'");
    if (!$table_exists) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("MLD Update 6.11.44: Table {$table_name} does not exist, skipping column additions");
        }
        update_option('mld_db_version', '6.11.44');
        return true;
    }

    // Get existing columns
    $existing_columns = $wpdb->get_col("SHOW COLUMNS FROM {$table_name}");

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log("MLD Update 6.11.44: Existing columns in {$table_name}: " . implode(', ', $existing_columns));
    }

    // Define columns that should exist with their ALTER TABLE statements
    $columns_to_add = array(
        'setting_type' => array(
            'sql' => "ALTER TABLE {$table_name} ADD COLUMN setting_type varchar(50) DEFAULT 'string' AFTER setting_value",
            'description' => 'Data type for the setting (string, boolean, integer, json, etc.)'
        ),
        'setting_category' => array(
            'sql' => "ALTER TABLE {$table_name} ADD COLUMN setting_category varchar(50) DEFAULT 'general' AFTER setting_type",
            'description' => 'Category grouping for the setting (ai_config, knowledge, notifications, etc.)'
        ),
        'is_encrypted' => array(
            'sql' => "ALTER TABLE {$table_name} ADD COLUMN is_encrypted tinyint(1) DEFAULT 0 AFTER setting_category",
            'description' => 'Flag indicating if the setting value is encrypted (for API keys)'
        ),
        'description' => array(
            'sql' => "ALTER TABLE {$table_name} ADD COLUMN description text DEFAULT NULL AFTER is_encrypted",
            'description' => 'Human-readable description of the setting'
        )
    );

    $columns_added = array();
    $errors = array();

    foreach ($columns_to_add as $column_name => $column_info) {
        if (!in_array($column_name, $existing_columns)) {
            $result = $wpdb->query($column_info['sql']);

            if ($result !== false) {
                $columns_added[] = $column_name;
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log("MLD Update 6.11.44: Added column '{$column_name}' to {$table_name}");
                }
            } else {
                $errors[] = "Failed to add column '{$column_name}': " . $wpdb->last_error;
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log("MLD Update 6.11.44: Failed to add column '{$column_name}' - " . $wpdb->last_error);
                }
            }
        } else {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("MLD Update 6.11.44: Column '{$column_name}' already exists in {$table_name}");
            }
        }
    }

    // Add index on setting_category if it doesn't exist
    $indexes = $wpdb->get_results("SHOW INDEX FROM {$table_name} WHERE Key_name = 'setting_category'");
    if (empty($indexes) && in_array('setting_category', array_merge($existing_columns, $columns_added))) {
        $result = $wpdb->query("ALTER TABLE {$table_name} ADD INDEX setting_category (setting_category)");
        if ($result !== false) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("MLD Update 6.11.44: Added index on setting_category");
            }
        }
    }

    // Update version
    update_option('mld_db_version', '6.11.44');

    // Log summary
    if (defined('WP_DEBUG') && WP_DEBUG) {
        if (!empty($columns_added)) {
            error_log("MLD Update 6.11.44: Successfully added columns: " . implode(', ', $columns_added));
        } else {
            error_log("MLD Update 6.11.44: No columns needed to be added (schema already complete)");
        }
        if (!empty($errors)) {
            error_log("MLD Update 6.11.44: Errors: " . implode('; ', $errors));
        }
    }

    return empty($errors);
}
