<?php
/**
 * Update to version 6.70.1
 *
 * Open House Database Schema Fix
 * - Adds missing updated_at column to wp_mld_open_house_attendees table
 *
 * This patch addresses an issue where the v6.69.0 migration may not have
 * created the updated_at column due to dbDelta limitations with complex
 * column definitions (DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP).
 *
 * @package MLS_Listings_Display
 * @since 6.70.1
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Run the 6.70.1 update
 *
 * @return bool Success status
 */
function mld_update_to_6_70_1() {
    global $wpdb;

    $attendees_table = $wpdb->prefix . 'mld_open_house_attendees';

    // Check if table exists
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$attendees_table}'");
    if (!$table_exists) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[MLD Update 6.70.1] Attendees table does not exist, skipping');
        }
        // Return true since this is not an error - table will be created on fresh install
        return true;
    }

    $errors = array();

    // Check and add updated_at column if missing
    $updated_at_exists = $wpdb->get_var("SHOW COLUMNS FROM {$attendees_table} LIKE 'updated_at'");
    if (!$updated_at_exists) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[MLD Update 6.70.1] Adding missing updated_at column to attendees table');
        }

        $result = $wpdb->query("ALTER TABLE {$attendees_table}
            ADD COLUMN updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            AFTER created_at");

        if ($result === false) {
            $errors[] = 'updated_at: ' . $wpdb->last_error;
        } else {
            // Backfill updated_at with created_at for existing rows
            $wpdb->query("UPDATE {$attendees_table} SET updated_at = created_at WHERE updated_at IS NULL");
        }
    } else {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[MLD Update 6.70.1] updated_at column already exists, skipping');
        }
    }

    // Also verify the open_houses table has updated_at (belt and suspenders)
    $open_houses_table = $wpdb->prefix . 'mld_open_houses';
    $oh_table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$open_houses_table}'");
    if ($oh_table_exists) {
        $oh_updated_at_exists = $wpdb->get_var("SHOW COLUMNS FROM {$open_houses_table} LIKE 'updated_at'");
        if (!$oh_updated_at_exists) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[MLD Update 6.70.1] Adding missing updated_at column to open_houses table');
            }

            $result = $wpdb->query("ALTER TABLE {$open_houses_table}
                ADD COLUMN updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                AFTER created_at");

            if ($result === false) {
                $errors[] = 'open_houses.updated_at: ' . $wpdb->last_error;
            } else {
                // Backfill with created_at
                $wpdb->query("UPDATE {$open_houses_table} SET updated_at = created_at WHERE updated_at IS NULL");
            }
        }
    }

    // Log any errors
    if (!empty($errors)) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[MLD Update 6.70.1] Errors: ' . implode(', ', $errors));
        }
        return false;
    }

    // Update version
    update_option('mld_db_version', '6.70.1');

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[MLD Update 6.70.1] Schema fix applied successfully');
    }

    return true;
}
