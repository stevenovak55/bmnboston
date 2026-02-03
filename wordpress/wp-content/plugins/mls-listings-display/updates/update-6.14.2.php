<?php
/**
 * MLS Listings Display - Update to version 6.14.2
 *
 * Adds missing columns to mld_form_submissions table for tour scheduling:
 * - property_address: Full property address for reference
 * - tour_type: Type of tour (in_person, virtual)
 * - preferred_date: User's preferred tour date
 * - preferred_time: User's preferred time slot (morning, afternoon, evening)
 *
 * @package MLS_Listings_Display
 * @since 6.14.2
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Run the 6.14.2 update
 *
 * @return bool True on success
 */
function mld_update_to_6_14_2() {
    global $wpdb;

    $table_name = $wpdb->prefix . 'mld_form_submissions';
    $results = array();

    // Check if table exists
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'");

    if (!$table_exists) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Update 6.14.2: form_submissions table does not exist, will be created by database verification');
        }
        return true; // Table will be created by database verification tool
    }

    // Get existing columns
    $existing_columns = array();
    $columns = $wpdb->get_results("SHOW COLUMNS FROM {$table_name}");
    foreach ($columns as $column) {
        $existing_columns[] = $column->Field;
    }

    // Columns to add with their definitions
    $columns_to_add = array(
        'property_address' => array(
            'definition' => 'TEXT NULL',
            'after' => 'property_mls'
        ),
        'tour_type' => array(
            'definition' => 'VARCHAR(50) NULL',
            'after' => 'message'
        ),
        'preferred_date' => array(
            'definition' => 'DATE NULL',
            'after' => 'tour_type'
        ),
        'preferred_time' => array(
            'definition' => 'VARCHAR(50) NULL',
            'after' => 'preferred_date'
        )
    );

    foreach ($columns_to_add as $column_name => $config) {
        if (!in_array($column_name, $existing_columns)) {
            $sql = "ALTER TABLE {$table_name} ADD COLUMN {$column_name} {$config['definition']} AFTER {$config['after']}";
            $result = $wpdb->query($sql);

            if ($result !== false) {
                $results[$column_name] = 'added';
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log("MLD Update 6.14.2: Added column {$column_name} to form_submissions table");
                }
            } else {
                $results[$column_name] = 'failed: ' . $wpdb->last_error;
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log("MLD Update 6.14.2: Failed to add column {$column_name}: " . $wpdb->last_error);
                }
            }
        } else {
            $results[$column_name] = 'already exists';
        }
    }

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('MLD Update 6.14.2: Form submissions table update completed - ' . json_encode($results));
    }

    return true;
}
