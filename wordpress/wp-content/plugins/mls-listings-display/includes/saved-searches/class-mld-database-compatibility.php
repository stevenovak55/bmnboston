<?php
/**
 * MLS Listings Display - Database Compatibility Layer
 * 
 * Handles listing_id type conversions between VARCHAR (MLS#) and BIGINT (internal ID)
 * and provides performance optimizations for saved search queries
 * 
 * @package MLS_Listings_Display
 * @subpackage Saved_Searches
 * @since 3.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Database_Compatibility {
    
    /**
     * Create compatibility views and functions
     */
    public static function create_compatibility_layer() {
        global $wpdb;
        
        // Create a function to convert MLS number (VARCHAR) to listing internal ID (BIGINT)
        $sql = "
        CREATE FUNCTION IF NOT EXISTS mld_get_listing_id_by_mls(mls_number VARCHAR(50))
        RETURNS BIGINT
        DETERMINISTIC
        READS SQL DATA
        BEGIN
            DECLARE listing_id BIGINT;
            
            -- First check active listings
            SELECT id INTO listing_id 
            FROM {$wpdb->prefix}bme_listings 
            WHERE listing_id = mls_number 
            LIMIT 1;
            
            -- If not found, check archive
            IF listing_id IS NULL THEN
                SELECT id INTO listing_id 
                FROM {$wpdb->prefix}bme_listings_archive 
                WHERE listing_id = mls_number 
                LIMIT 1;
            END IF;
            
            RETURN listing_id;
        END";
        
        // Note: Functions require special privileges, so we'll use a mapping table instead
        self::create_listing_id_mapping_table();
    }
    
    /**
     * Create a mapping table for listing_id conversions
     */
    private static function create_listing_id_mapping_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'mld_listing_id_map';
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            mls_number VARCHAR(50) NOT NULL,
            internal_id BIGINT(20) UNSIGNED NOT NULL,
            table_source ENUM('active', 'archive') NOT NULL,
            last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (mls_number),
            KEY idx_internal_id (internal_id),
            KEY idx_source (table_source)
        ) $charset_collate";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        // Populate the mapping table
        self::refresh_listing_id_mapping();
    }
    
    /**
     * Refresh the listing_id mapping table
     */
    public static function refresh_listing_id_mapping() {
        global $wpdb;
        
        $mapping_table = $wpdb->prefix . 'mld_listing_id_map';
        
        // Clear existing mappings
        $wpdb->query("TRUNCATE TABLE $mapping_table");
        
        // Insert active listings
        $wpdb->query("
            INSERT INTO $mapping_table (mls_number, internal_id, table_source)
            SELECT listing_id, id, 'active'
            FROM {$wpdb->prefix}bme_listings
            WHERE listing_id IS NOT NULL
        ");
        
        // Insert archive listings
        $wpdb->query("
            INSERT INTO $mapping_table (mls_number, internal_id, table_source)
            SELECT listing_id, id, 'archive'
            FROM {$wpdb->prefix}bme_listings_archive
            WHERE listing_id IS NOT NULL
            ON DUPLICATE KEY UPDATE 
                internal_id = VALUES(internal_id),
                table_source = 'archive'
        ");
    }
    
    /**
     * Add performance indexes to saved search tables
     */
    public static function add_performance_indexes() {
        global $wpdb;
        
        // Add composite indexes for saved searches
        $indexes = [
            // For user dashboard queries
            "ALTER TABLE {$wpdb->prefix}mld_saved_searches 
             ADD INDEX idx_user_active (user_id, is_active)",
            
            // For notification processing
            "ALTER TABLE {$wpdb->prefix}mld_saved_searches 
             ADD INDEX idx_active_frequency (is_active, notification_frequency)",
            
            // For saved search results
            "ALTER TABLE {$wpdb->prefix}mld_saved_search_results 
             ADD INDEX idx_search_listing (saved_search_id, listing_id)",
            
            // For property preferences
            "ALTER TABLE {$wpdb->prefix}mld_property_preferences 
             ADD INDEX idx_user_type (user_id, preference_type)",
            
            // For cron log queries
            "ALTER TABLE {$wpdb->prefix}mld_saved_search_cron_log 
             ADD INDEX idx_search_time (saved_search_id, executed_at)",
            
            // For agent-client relationships
            "ALTER TABLE {$wpdb->prefix}mld_agent_client_relationships 
             ADD INDEX idx_agent_client (agent_id, client_id)",
            "ALTER TABLE {$wpdb->prefix}mld_agent_client_relationships 
             ADD INDEX idx_client_status (client_id, status)"
        ];
        
        foreach ($indexes as $index_sql) {
            // Check if index exists before adding
            $table_info = $wpdb->get_results("SHOW CREATE TABLE " . self::extract_table_name($index_sql));
            if (!empty($table_info)) {
                $create_sql = $table_info[0]->{'Create Table'} ?? '';
                $index_name = self::extract_index_name($index_sql);
                
                if ($index_name && strpos($create_sql, "KEY `$index_name`") === false) {
                    $wpdb->query($index_sql);
                }
            }
        }
    }
    
    /**
     * Get internal listing ID from MLS number
     * 
     * @param string $mls_number
     * @return int|null
     */
    public static function get_internal_id($mls_number) {
        global $wpdb;
        
        $result = $wpdb->get_var($wpdb->prepare(
            "SELECT internal_id FROM {$wpdb->prefix}mld_listing_id_map WHERE mls_number = %s",
            $mls_number
        ));
        
        return $result ? (int) $result : null;
    }
    
    /**
     * Get MLS number from internal ID
     * 
     * @param int $internal_id
     * @return string|null
     */
    public static function get_mls_number($internal_id) {
        global $wpdb;
        
        return $wpdb->get_var($wpdb->prepare(
            "SELECT mls_number FROM {$wpdb->prefix}mld_listing_id_map WHERE internal_id = %d",
            $internal_id
        ));
    }
    
    /**
     * Convert array of MLS numbers to internal IDs
     * 
     * @param array $mls_numbers
     * @return array
     */
    public static function convert_mls_to_internal_ids($mls_numbers) {
        global $wpdb;
        
        if (empty($mls_numbers)) {
            return [];
        }
        
        $placeholders = array_fill(0, count($mls_numbers), '%s');
        $query = "SELECT mls_number, internal_id FROM {$wpdb->prefix}mld_listing_id_map 
                  WHERE mls_number IN (" . implode(',', $placeholders) . ")";
        
        $results = $wpdb->get_results($wpdb->prepare($query, $mls_numbers), ARRAY_A);
        
        $mapping = [];
        foreach ($results as $row) {
            $mapping[$row['mls_number']] = (int) $row['internal_id'];
        }
        
        return $mapping;
    }
    
    /**
     * Extract table name from ALTER TABLE query
     */
    private static function extract_table_name($sql) {
        if (preg_match('/ALTER TABLE\s+`?(\w+)`?/i', $sql, $matches)) {
            return $matches[1];
        }
        return null;
    }
    
    /**
     * Extract index name from ADD INDEX query
     */
    private static function extract_index_name($sql) {
        if (preg_match('/ADD INDEX\s+`?(\w+)`?/i', $sql, $matches)) {
            return $matches[1];
        }
        return null;
    }
    
    /**
     * Schedule refresh of mapping table
     */
    public static function schedule_mapping_refresh() {
        if (!wp_next_scheduled('mld_refresh_listing_id_mapping')) {
            wp_schedule_event(time(), 'hourly', 'mld_refresh_listing_id_mapping');
        }
    }
    
    /**
     * Unschedule mapping refresh
     */
    public static function unschedule_mapping_refresh() {
        wp_clear_scheduled_hook('mld_refresh_listing_id_mapping');
    }
}

// Hook for refreshing the mapping
add_action('mld_refresh_listing_id_mapping', ['MLD_Database_Compatibility', 'refresh_listing_id_mapping']);