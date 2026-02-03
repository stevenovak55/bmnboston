<?php
/**
 * Emergency Migration Script for Live Site Database
 * Adds missing columns that are causing 500 errors
 *
 * Version: 3.30.8
 * Date: October 1, 2025
 */

if (!defined('ABSPATH')) {
    exit;
}

class BME_Fix_Live_Site_Columns {

    /**
     * Run the migration
     */
    public static function run() {
        global $wpdb;

        error_log("BME Migration: Starting emergency column fixes for live site");

        // Fix 1: Add unparsed_address to listings tables if missing
        self::add_unparsed_address_column();

        // Fix 2: Add days_on_market to listings tables if missing
        self::add_days_on_market_column();

        // Fix 3: Ensure location tables have proper structure
        self::fix_location_tables();

        error_log("BME Migration: Emergency column fixes completed");

        return true;
    }

    /**
     * Add unparsed_address column to listings tables
     */
    private static function add_unparsed_address_column() {
        global $wpdb;

        $tables = [
            $wpdb->prefix . 'bme_listings',
            $wpdb->prefix . 'bme_listings_archive'
        ];

        foreach ($tables as $table) {
            // Check if table exists
            if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
                error_log("BME Migration: Table {$table} does not exist, skipping");
                continue;
            }

            // Check if column exists
            $columns = $wpdb->get_results("SHOW COLUMNS FROM {$table}");
            $column_names = array_column($columns, 'Field');

            if (!in_array('unparsed_address', $column_names)) {
                // For listings tables, add at the end since postal_code doesn't exist there
                if (strpos($table, 'bme_listings') !== false && strpos($table, 'location') === false) {
                    $result = $wpdb->query("ALTER TABLE {$table} ADD COLUMN unparsed_address VARCHAR(500) DEFAULT NULL");
                } else {
                    // For location tables, add after postal_code if it exists
                    if (in_array('postal_code', $column_names)) {
                        $result = $wpdb->query("ALTER TABLE {$table} ADD COLUMN unparsed_address VARCHAR(500) DEFAULT NULL AFTER postal_code");
                    } else {
                        $result = $wpdb->query("ALTER TABLE {$table} ADD COLUMN unparsed_address VARCHAR(500) DEFAULT NULL");
                    }
                }

                if ($result !== false) {
                    error_log("BME Migration: Added unparsed_address column to {$table}");
                } else {
                    error_log("BME Migration: Failed to add unparsed_address column to {$table}: " . $wpdb->last_error);
                }
            } else {
                error_log("BME Migration: Column unparsed_address already exists in {$table}");
            }
        }
    }

    /**
     * Add days_on_market column to listings tables
     */
    private static function add_days_on_market_column() {
        global $wpdb;

        $tables = [
            $wpdb->prefix . 'bme_listings',
            $wpdb->prefix . 'bme_listings_archive'
        ];

        foreach ($tables as $table) {
            // Check if table exists
            if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
                error_log("BME Migration: Table {$table} does not exist, skipping");
                continue;
            }

            // Check if column exists
            $columns = $wpdb->get_results("SHOW COLUMNS FROM {$table}");
            $column_names = array_column($columns, 'Field');

            if (!in_array('days_on_market', $column_names)) {
                // First check if mlspin_market_time_property exists (the actual column name)
                if (in_array('mlspin_market_time_property', $column_names)) {
                    // Create an alias/virtual column or copy the data
                    $result = $wpdb->query("ALTER TABLE {$table} ADD COLUMN days_on_market INT DEFAULT NULL AFTER close_price");
                    if ($result !== false) {
                        // Copy data from mlspin_market_time_property
                        $wpdb->query("UPDATE {$table} SET days_on_market = mlspin_market_time_property WHERE mlspin_market_time_property IS NOT NULL");
                        error_log("BME Migration: Added days_on_market column to {$table} and copied data from mlspin_market_time_property");
                    }
                } else {
                    // Just add the column
                    $result = $wpdb->query("ALTER TABLE {$table} ADD COLUMN days_on_market INT DEFAULT NULL AFTER close_price");
                    if ($result !== false) {
                        error_log("BME Migration: Added days_on_market column to {$table}");
                    } else {
                        error_log("BME Migration: Failed to add days_on_market column to {$table}");
                    }
                }
            } else {
                error_log("BME Migration: Column days_on_market already exists in {$table}");
            }
        }
    }

    /**
     * Ensure location tables have unparsed_address
     */
    private static function fix_location_tables() {
        global $wpdb;

        $tables = [
            $wpdb->prefix . 'bme_listing_location',
            $wpdb->prefix . 'bme_listing_location_archive'
        ];

        foreach ($tables as $table) {
            // Check if table exists
            if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
                error_log("BME Migration: Table {$table} does not exist, skipping");
                continue;
            }

            // Check if column exists
            $columns = $wpdb->get_results("SHOW COLUMNS FROM {$table}");
            $column_names = array_column($columns, 'Field');

            // Check for unparsed_address in location tables
            if (!in_array('unparsed_address', $column_names)) {
                if (in_array('postal_code', $column_names)) {
                    $result = $wpdb->query("ALTER TABLE {$table} ADD COLUMN unparsed_address VARCHAR(500) DEFAULT NULL AFTER postal_code");
                } else {
                    $result = $wpdb->query("ALTER TABLE {$table} ADD COLUMN unparsed_address VARCHAR(500) DEFAULT NULL");
                }

                if ($result !== false) {
                    error_log("BME Migration: Added unparsed_address column to {$table}");
                } else {
                    error_log("BME Migration: Failed to add unparsed_address column to {$table}: " . $wpdb->last_error);
                }
            }

            // Ensure normalized_address exists
            if (!in_array('normalized_address', $column_names)) {
                $result = $wpdb->query("ALTER TABLE {$table} ADD COLUMN normalized_address VARCHAR(500) DEFAULT NULL AFTER unparsed_address");
                if ($result !== false) {
                    error_log("BME Migration: Added normalized_address column to {$table}");
                }
            }
        }
    }
}