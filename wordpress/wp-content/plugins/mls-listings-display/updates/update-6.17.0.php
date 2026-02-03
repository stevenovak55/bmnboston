<?php
/**
 * MLD Update to Version 6.17.0
 * Standalone CMA Feature: Create CMAs without existing MLS listings
 *
 * Features:
 * - Allow users to create CMAs by manually entering property details
 * - Public shareable URLs for standalone CMAs (/cma/{address-slug}/)
 * - No login required to create, prompt to save to account
 * - Admin dashboard to manage all CMA sessions
 *
 * Database Changes:
 * - Add is_standalone column to identify standalone CMAs
 * - Add standalone_slug column for URL routing
 *
 * @since 6.17.0
 */

function mld_update_to_6_17_0() {
    global $wpdb;

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[MLD Update 6.17.0] Starting Standalone CMA database update');
    }

    $table_name = $wpdb->prefix . 'mld_cma_saved_sessions';

    // Check if table exists
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'");
    if (!$table_exists) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[MLD Update 6.17.0] CMA sessions table does not exist, skipping column additions');
        }
        return false;
    }

    $columns_added = 0;

    // Check and add is_standalone column
    $is_standalone_exists = $wpdb->get_var(
        "SHOW COLUMNS FROM $table_name LIKE 'is_standalone'"
    );

    if (!$is_standalone_exists) {
        $result = $wpdb->query(
            "ALTER TABLE $table_name
             ADD COLUMN is_standalone TINYINT(1) DEFAULT 0
             AFTER is_favorite"
        );

        if ($result !== false) {
            $columns_added++;
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[MLD Update 6.17.0] Added is_standalone column');
            }
        } else {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[MLD Update 6.17.0] ERROR: Failed to add is_standalone column: ' . $wpdb->last_error);
            }
        }
    }

    // Check and add standalone_slug column
    $standalone_slug_exists = $wpdb->get_var(
        "SHOW COLUMNS FROM $table_name LIKE 'standalone_slug'"
    );

    if (!$standalone_slug_exists) {
        $result = $wpdb->query(
            "ALTER TABLE $table_name
             ADD COLUMN standalone_slug VARCHAR(255) DEFAULT NULL
             AFTER is_standalone"
        );

        if ($result !== false) {
            $columns_added++;
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[MLD Update 6.17.0] Added standalone_slug column');
            }
        } else {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[MLD Update 6.17.0] ERROR: Failed to add standalone_slug column: ' . $wpdb->last_error);
            }
        }
    }

    // Add index for standalone_slug if not exists
    $index_exists = $wpdb->get_row(
        "SHOW INDEX FROM $table_name WHERE Key_name = 'idx_standalone_slug'"
    );

    if (!$index_exists) {
        $result = $wpdb->query(
            "ALTER TABLE $table_name
             ADD INDEX idx_standalone_slug (standalone_slug)"
        );

        if ($result !== false) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[MLD Update 6.17.0] Added idx_standalone_slug index');
            }
        } else {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[MLD Update 6.17.0] WARNING: Failed to add standalone_slug index: ' . $wpdb->last_error);
            }
        }
    }

    // Add index for is_standalone if not exists (useful for filtering)
    $is_standalone_index_exists = $wpdb->get_row(
        "SHOW INDEX FROM $table_name WHERE Key_name = 'idx_is_standalone'"
    );

    if (!$is_standalone_index_exists) {
        $result = $wpdb->query(
            "ALTER TABLE $table_name
             ADD INDEX idx_is_standalone (is_standalone)"
        );

        if ($result !== false) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[MLD Update 6.17.0] Added idx_is_standalone index');
            }
        }
    }

    // Update the database version
    update_option('mld_db_version', '6.17.0');
    update_option('mld_version', '6.17.0');

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[MLD Update 6.17.0] Standalone CMA database update completed. Columns added: ' . $columns_added);
    }

    return true;
}
