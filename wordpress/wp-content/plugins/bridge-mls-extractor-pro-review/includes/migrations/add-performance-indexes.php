<?php
/**
 * Add performance indexes to database tables
 * 
 * This migration adds indexes to commonly queried fields to improve performance.
 * It's safe to run multiple times as it checks for existing indexes first.
 * 
 * @package Bridge_MLS_Extractor_Pro
 * @since 3.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class BME_Add_Performance_Indexes {
    
    /**
     * Run the migration
     */
    public static function run() {
        global $wpdb;
        
        $results = [];
        
        // Get table names
        $listings_table = $wpdb->prefix . 'bme_listings';
        $open_houses_table = $wpdb->prefix . 'bme_open_houses';
        $virtual_tours_table = $wpdb->prefix . 'bme_virtual_tours';
        $saved_properties_table = $wpdb->prefix . 'mld_saved_properties';
        $saved_searches_table = $wpdb->prefix . 'mld_saved_searches';
        $search_alerts_table = $wpdb->prefix . 'mld_search_alerts';
        $activity_log_table = $wpdb->prefix . 'bme_activity_log';
        
        // Add indexes for listings table
        $results['listings'] = self::add_indexes($listings_table, [
            'idx_status_modified' => ['standard_status', 'modification_timestamp'],
            'idx_city_status' => ['city', 'standard_status'],
            'idx_price_status' => ['list_price', 'standard_status'],
            'idx_beds_baths' => ['bedrooms_total', 'bathrooms_total'],
            'idx_property_type' => ['property_type'],
            'idx_listing_id' => ['ListingId'],
            'idx_postal_code' => ['postal_code'],
            'idx_county' => ['county_or_parish']
        ]);
        
        // Add indexes for open houses table
        $results['open_houses'] = self::add_indexes($open_houses_table, [
            'idx_listing_id' => ['listing_id'],
            'idx_event_date' => ['event_date'],
            'idx_expires' => ['expires_at'],
            'idx_listing_expires' => ['listing_id', 'expires_at']
        ]);
        
        // Add indexes for virtual tours table
        $results['virtual_tours'] = self::add_indexes($virtual_tours_table, [
            'idx_listing_id' => ['listing_id'],
            'idx_modified' => ['modification_timestamp']
        ]);
        
        // Add indexes for saved properties table
        $results['saved_properties'] = self::add_indexes($saved_properties_table, [
            'idx_user_listing' => ['user_id', 'listing_id'],
            'idx_user_created' => ['user_id', 'created_at'],
            'idx_listing' => ['listing_id']
        ]);
        
        // Add indexes for saved searches table
        $results['saved_searches'] = self::add_indexes($saved_searches_table, [
            'idx_user_id' => ['user_id'],
            'idx_user_created' => ['user_id', 'created_at'],
            'idx_alert_enabled' => ['alert_enabled', 'last_alert_sent']
        ]);
        
        // Add indexes for search alerts table
        $results['search_alerts'] = self::add_indexes($search_alerts_table, [
            'idx_search_id' => ['search_id'],
            'idx_sent_at' => ['sent_at'],
            'idx_search_sent' => ['search_id', 'sent_at']
        ]);
        
        // Add indexes for activity log table
        $results['activity_log'] = self::add_indexes($activity_log_table, [
            'idx_activity_type' => ['activity_type'],
            'idx_created_at' => ['created_at'],
            'idx_type_created' => ['activity_type', 'created_at']
        ]);
        
        // Log results
        self::log_results($results);
        
        return $results;
    }
    
    /**
     * Add indexes to a table
     * 
     * @param string $table Table name
     * @param array $indexes Array of index_name => columns
     * @return array Results
     */
    private static function add_indexes($table, $indexes) {
        global $wpdb;
        
        $results = [];
        
        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table'");
        if (!$table_exists) {
            return ['error' => "Table $table does not exist"];
        }
        
        foreach ($indexes as $index_name => $columns) {
            // Check if index already exists
            $index_exists = $wpdb->get_var(
                "SELECT COUNT(1) FROM INFORMATION_SCHEMA.STATISTICS 
                WHERE table_schema = DATABASE() 
                AND table_name = '$table' 
                AND index_name = '$index_name'"
            );
            
            if ($index_exists) {
                $results[$index_name] = 'already_exists';
                continue;
            }
            
            // Create the index
            $columns_sql = implode(', ', array_map(function($col) {
                // Limit varchar columns in index to avoid key length issues
                if (in_array($col, ['city', 'county_or_parish', 'postal_code', 'property_type'])) {
                    return "`$col`(50)";
                }
                return "`$col`";
            }, $columns));
            
            $sql = "ALTER TABLE `$table` ADD INDEX `$index_name` ($columns_sql)";
            
            // Suppress errors and check result
            $wpdb->suppress_errors(true);
            $result = $wpdb->query($sql);
            $wpdb->suppress_errors(false);
            
            if ($result === false) {
                $error = $wpdb->last_error;
                // If error is about duplicate key name, that's okay
                if (strpos($error, 'Duplicate key name') !== false) {
                    $results[$index_name] = 'already_exists';
                } else {
                    $results[$index_name] = 'error: ' . $error;
                }
            } else {
                $results[$index_name] = 'created';
            }
        }
        
        return $results;
    }
    
    /**
     * Log migration results
     * 
     * @param array $results Migration results
     */
    private static function log_results($results) {
        $log_message = "BME Performance Indexes Migration Results:\n";
        
        foreach ($results as $table => $table_results) {
            $log_message .= "\n$table:\n";
            
            if (isset($table_results['error'])) {
                $log_message .= "  - Error: {$table_results['error']}\n";
                continue;
            }
            
            foreach ($table_results as $index => $status) {
                $log_message .= "  - $index: $status\n";
            }
        }
        
        // Log to error log if debug is enabled
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log($log_message);
        }
        
        // Store in option for admin review
        update_option('bme_performance_indexes_migration_' . date('Y-m-d'), $results);
    }
    
    /**
     * Check if migration has been run
     * 
     * @return bool
     */
    public static function has_run() {
        $options = wp_load_alloptions();
        foreach ($options as $key => $value) {
            if (strpos($key, 'bme_performance_indexes_migration_') === 0) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Remove indexes (for rollback)
     */
    public static function rollback() {
        global $wpdb;
        
        $results = [];
        
        // Get table names
        $tables = [
            $wpdb->prefix . 'bme_listings',
            $wpdb->prefix . 'bme_open_houses',
            $wpdb->prefix . 'bme_virtual_tours',
            $wpdb->prefix . 'mld_saved_properties',
            $wpdb->prefix . 'mld_saved_searches',
            $wpdb->prefix . 'mld_search_alerts',
            $wpdb->prefix . 'bme_activity_log'
        ];
        
        $index_prefixes = ['idx_'];
        
        foreach ($tables as $table) {
            $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table'");
            if (!$table_exists) {
                continue;
            }
            
            // Get all indexes for this table
            $indexes = $wpdb->get_results(
                "SELECT DISTINCT index_name 
                FROM INFORMATION_SCHEMA.STATISTICS 
                WHERE table_schema = DATABASE() 
                AND table_name = '$table' 
                AND index_name != 'PRIMARY'"
            );
            
            foreach ($indexes as $index) {
                // Only drop indexes we created (starting with idx_)
                $should_drop = false;
                foreach ($index_prefixes as $prefix) {
                    if (strpos($index->index_name, $prefix) === 0) {
                        $should_drop = true;
                        break;
                    }
                }
                
                if ($should_drop) {
                    $sql = "ALTER TABLE `$table` DROP INDEX `{$index->index_name}`";
                    $wpdb->suppress_errors(true);
                    $result = $wpdb->query($sql);
                    $wpdb->suppress_errors(false);
                    
                    $results[$table][$index->index_name] = $result !== false ? 'dropped' : 'error';
                }
            }
        }
        
        // Remove migration option
        $options = wp_load_alloptions();
        foreach ($options as $key => $value) {
            if (strpos($key, 'bme_performance_indexes_migration_') === 0) {
                delete_option($key);
            }
        }
        
        return $results;
    }
}