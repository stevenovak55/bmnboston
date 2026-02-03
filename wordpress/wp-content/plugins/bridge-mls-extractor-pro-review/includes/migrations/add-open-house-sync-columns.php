<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Migration to add sync tracking columns to open houses table
 *
 * @since 3.30.2
 */
class BME_Add_Open_House_Sync_Columns {

    /**
     * Run the migration
     */
    public static function run() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'bme_open_houses';

        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name;

        if (!$table_exists) {
            error_log("BME Migration: Open houses table doesn't exist, skipping column addition");
            return false;
        }

        // Check which columns already exist
        $columns = $wpdb->get_results("SHOW COLUMNS FROM {$table_name}");
        $existing_columns = array_column($columns, 'Field');

        $columns_added = 0;

        // Add open_house_key column if it doesn't exist
        if (!in_array('open_house_key', $existing_columns)) {
            $result = $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN open_house_key VARCHAR(128) AFTER listing_key");
            if ($result !== false) {
                $columns_added++;
                error_log("BME Migration: Added open_house_key column to {$table_name}");
            }
        }

        // Add sync_status column if it doesn't exist
        if (!in_array('sync_status', $existing_columns)) {
            $result = $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN sync_status VARCHAR(20) DEFAULT 'current' AFTER expires_at");
            if ($result !== false) {
                $columns_added++;
                error_log("BME Migration: Added sync_status column to {$table_name}");
            }
        }

        // Add sync_timestamp column if it doesn't exist
        if (!in_array('sync_timestamp', $existing_columns)) {
            $result = $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN sync_timestamp DATETIME AFTER sync_status");
            if ($result !== false) {
                $columns_added++;
                error_log("BME Migration: Added sync_timestamp column to {$table_name}");
            }
        }

        // Add indexes for new columns if they don't exist
        $indexes = $wpdb->get_results("SHOW INDEX FROM {$table_name}");
        $existing_indexes = array_column($indexes, 'Key_name');

        if (!in_array('idx_sync_status', $existing_indexes)) {
            $wpdb->query("ALTER TABLE {$table_name} ADD INDEX idx_sync_status (sync_status)");
            error_log("BME Migration: Added idx_sync_status index to {$table_name}");
        }

        if (!in_array('idx_sync_timestamp', $existing_indexes)) {
            $wpdb->query("ALTER TABLE {$table_name} ADD INDEX idx_sync_timestamp (sync_timestamp)");
            error_log("BME Migration: Added idx_sync_timestamp index to {$table_name}");
        }

        // Add unique index for listing_id and open_house_key if it doesn't exist
        if (!in_array('idx_unique_open_house', $existing_indexes) && in_array('open_house_key', $existing_columns)) {
            // First remove any duplicate open house keys for the same listing
            $wpdb->query("
                DELETE oh1 FROM {$table_name} oh1
                INNER JOIN {$table_name} oh2
                WHERE oh1.id > oh2.id
                AND oh1.listing_id = oh2.listing_id
                AND oh1.open_house_key = oh2.open_house_key
                AND oh1.open_house_key IS NOT NULL
            ");

            // Try to add unique index
            $result = $wpdb->query("ALTER TABLE {$table_name} ADD UNIQUE INDEX idx_unique_open_house (listing_id, open_house_key)");
            if ($result !== false) {
                error_log("BME Migration: Added idx_unique_open_house index to {$table_name}");
            }
        }

        // Update the migration status
        update_option('bme_open_house_sync_columns_added', true);
        update_option('bme_open_house_sync_columns_version', '3.30.2');

        error_log("BME Migration: Open house sync columns migration completed. Added {$columns_added} columns.");

        return true;
    }

    /**
     * Check if migration has been run
     */
    public static function is_migrated() {
        return get_option('bme_open_house_sync_columns_added', false);
    }
}