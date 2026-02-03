<?php
/**
 * MLS Query Optimizer
 * 
 * Optimizes database queries for better performance
 * 
 * @package MLS_Listings_Display
 * @subpackage Saved_Searches
 * @since 3.3.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Query Optimizer Class
 * 
 * Provides query optimization techniques
 */
class MLD_Query_Optimizer {
    
    /**
     * Optimize property query
     * 
     * @param array $args Query arguments
     * @return array Optimized arguments
     */
    public static function optimize_property_query($args) {
        // Force specific columns if not selecting all
        if (!isset($args['fields']) || $args['fields'] !== '*') {
            $args['fields'] = self::get_essential_property_fields();
        }
        
        // Add index hints for better performance
        if (isset($args['city']) && !isset($args['index_hint'])) {
            $args['index_hint'] = 'idx_city_status_price';
        }
        
        // Limit default results if not specified
        if (!isset($args['limit'])) {
            $args['limit'] = 500; // Reasonable default
        }
        
        return $args;
    }
    
    /**
     * Get essential property fields
     * 
     * @return array Field list
     */
    private static function get_essential_property_fields() {
        return [
            'l.id',
            'l.listing_id',
            'l.standard_status',
            'l.list_price',
            'l.close_price',
            'l.property_type',
            'l.property_sub_type',
            'l.bedrooms_total',
            'l.bathrooms_total',
            'l.living_area',
            'l.lot_size_area',
            'l.year_built',
            'l.list_agent_mls_id',
            'l.list_office_mls_id',
            'l.original_entry_timestamp',
            'l.off_market_date',
            'll.street_number',
            'll.street_name',
            'll.street_suffix',
            'll.unit_number',
            'll.city',
            'll.state_or_province',
            'll.postal_code',
            'll.latitude',
            'll.longitude'
        ];
    }
    
    /**
     * Batch property queries
     * 
     * @param array $listing_ids Array of listing IDs
     * @param int $batch_size Batch size
     * @return array Properties
     */
    public static function batch_property_query($listing_ids, $batch_size = 100) {
        $all_properties = [];
        $batches = array_chunk($listing_ids, $batch_size);
        
        foreach ($batches as $batch) {
            $properties = MLD_Query::get_map_properties([
                'listing_ids' => $batch,
                'fields' => self::get_essential_property_fields()
            ]);
            
            $all_properties = array_merge($all_properties, $properties);
        }
        
        return $all_properties;
    }
    
    /**
     * Optimize search notification query
     * 
     * @param string $frequency Notification frequency
     * @return array Query options
     */
    public static function optimize_notification_query($frequency) {
        $options = [
            'fields' => ['id', 'user_id', 'filters', 'polygon_shapes'],
            'limit' => 50 // Process in batches
        ];
        
        // Adjust based on frequency
        switch ($frequency) {
            case 'instant':
                $options['limit'] = 20; // Smaller batches for instant
                break;
            case 'hourly':
                $options['limit'] = 30;
                break;
            case 'daily':
                $options['limit'] = 50;
                break;
            case 'weekly':
                $options['limit'] = 100; // Larger batches for weekly
                break;
        }
        
        return $options;
    }
    
    /**
     * Create query explain plan
     * 
     * @param string $query SQL query
     * @return array Explain results
     */
    public static function explain_query($query) {
        global $wpdb;
        
        $explain = $wpdb->get_results("EXPLAIN " . $query, ARRAY_A);
        
        $analysis = [
            'query' => $query,
            'explain' => $explain,
            'issues' => []
        ];
        
        // Analyze explain results
        foreach ($explain as $row) {
            // Check for full table scans
            if ($row['type'] === 'ALL') {
                $analysis['issues'][] = "Full table scan on {$row['table']}";
            }
            
            // Check for missing indexes
            if (empty($row['key'])) {
                $analysis['issues'][] = "No index used for {$row['table']}";
            }
            
            // Check for large row examinations
            if (isset($row['rows']) && $row['rows'] > 1000) {
                $analysis['issues'][] = "Large row examination ({$row['rows']} rows) on {$row['table']}";
            }
        }
        
        return $analysis;
    }
    
    /**
     * Suggest indexes for common queries
     * 
     * @return array Index suggestions
     */
    public static function suggest_indexes() {
        global $wpdb;
        
        $suggestions = [];
        
        // Check saved searches table
        $table = $wpdb->prefix . 'mld_saved_searches';
        $indexes = $wpdb->get_results("SHOW INDEX FROM {$table}", ARRAY_A);
        $existing = array_column($indexes, 'Key_name');
        
        if (!in_array('idx_user_active', $existing)) {
            $suggestions[] = [
                'table' => $table,
                'name' => 'idx_user_active',
                'columns' => ['user_id', 'is_active'],
                'reason' => 'Speed up user search queries'
            ];
        }
        
        if (!in_array('idx_frequency_active', $existing)) {
            $suggestions[] = [
                'table' => $table,
                'name' => 'idx_frequency_active',
                'columns' => ['notification_frequency', 'is_active', 'last_notified_at'],
                'reason' => 'Speed up notification queries'
            ];
        }
        
        // Check search results table
        $table = $wpdb->prefix . 'mld_saved_search_results';
        $indexes = $wpdb->get_results("SHOW INDEX FROM {$table}", ARRAY_A);
        $existing = array_column($indexes, 'Key_name');
        
        if (!in_array('idx_search_listing', $existing)) {
            $suggestions[] = [
                'table' => $table,
                'name' => 'idx_search_listing',
                'columns' => ['search_id', 'listing_id'],
                'reason' => 'Prevent duplicate notifications'
            ];
        }
        
        return $suggestions;
    }
    
    /**
     * Apply query suggestions
     * 
     * @param array $suggestions Index suggestions
     * @return array Results
     */
    public static function apply_suggestions($suggestions) {
        global $wpdb;
        $results = [];
        
        foreach ($suggestions as $suggestion) {
            $columns = implode(', ', $suggestion['columns']);
            
            $sql = "ALTER TABLE {$suggestion['table']} 
                    ADD INDEX {$suggestion['name']} ({$columns})";
            
            $result = $wpdb->query($sql);
            
            $results[] = [
                'suggestion' => $suggestion,
                'applied' => $result !== false,
                'error' => $result === false ? $wpdb->last_error : null
            ];
        }
        
        return $results;
    }
    
    /**
     * Monitor slow queries
     * 
     * @param string $query Query string
     * @param float $execution_time Execution time in seconds
     * @param array $context Additional context
     */
    public static function log_slow_query($query, $execution_time, $context = []) {
        if ($execution_time < 1.0) {
            return; // Only log queries slower than 1 second
        }
        
        $log_entry = [
            'query' => $query,
            'execution_time' => $execution_time,
            'context' => $context,
            'timestamp' => current_time('mysql'),
            'backtrace' => wp_debug_backtrace_summary()
        ];
        
        // Log to error log
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Slow Query: ' . json_encode($log_entry));
        }
        
        // Optionally store in database for analysis
        if (get_option('mld_log_slow_queries', false)) {
            global $wpdb;
            
            $wpdb->insert(
                $wpdb->prefix . 'mld_slow_query_log',
                [
                    'query_hash' => md5($query),
                    'query_text' => $query,
                    'execution_time' => $execution_time,
                    'context' => maybe_serialize($context),
                    'logged_at' => current_time('mysql')
                ],
                ['%s', '%s', '%f', '%s', '%s']
            );
        }
    }
}