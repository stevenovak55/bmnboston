<?php
/**
 * MLD Query Optimizer
 *
 * Optimizes database queries for better performance
 * Implements connection pooling, query optimization, and index management
 *
 * @package MLS_Listings_Display
 * @since 4.6.4
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Query_Optimizer_Enhanced {

    /**
     * Singleton instance
     */
    private static $instance = null;

    /**
     * Query execution statistics
     */
    private $query_stats = [];

    /**
     * Index recommendations
     */
    private $index_recommendations = [];

    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        // Hook into query execution
        add_filter('mld_pre_query_execution', [$this, 'optimize_query'], 10, 2);
        add_action('mld_post_query_execution', [$this, 'analyze_query_performance'], 10, 3);

        // Add index check on activation
        add_action('mld_check_indexes', [$this, 'check_and_create_indexes']);
    }

    /**
     * Optimize query before execution
     *
     * @param string $query The SQL query
     * @param string $context Query context
     * @return string Optimized query
     */
    public function optimize_query($query, $context = '') {
        // Add query hints for better performance
        $query = $this->add_index_hints($query, $context);

        // Optimize JOIN order
        $query = $this->optimize_join_order($query);

        // Add LIMIT optimization for count queries
        $query = $this->optimize_count_queries($query);

        return $query;
    }

    /**
     * Add index hints to queries
     *
     * @param string $query
     * @param string $context
     * @return string
     */
    private function add_index_hints($query, $context) {
        // Add index hints for listings table
        if (strpos($query, 'wp_bme_listings') !== false && strpos($query, 'USE_INDEX') === false) {
            // For city-based queries
            if (strpos($query, 'city') !== false) {
                $query = str_replace(
                    'FROM wp_bme_listings AS l',
                    'FROM wp_bme_listings AS l USE INDEX (idx_city_status)',
                    $query
                );
            }
            // For status-based queries
            elseif (strpos($query, 'standard_status') !== false) {
                $query = str_replace(
                    'FROM wp_bme_listings AS l',
                    'FROM wp_bme_listings AS l USE INDEX (idx_status_price)',
                    $query
                );
            }
            // For map viewport queries
            elseif (strpos($query, 'ST_Contains') !== false || strpos($query, 'coordinates') !== false) {
                $query = str_replace(
                    'FROM wp_bme_listing_location AS ll',
                    'FROM wp_bme_listing_location AS ll USE INDEX (idx_coordinates)',
                    $query
                );
            }
        }

        return $query;
    }

    /**
     * Optimize JOIN order for better performance
     *
     * @param string $query
     * @return string
     */
    private function optimize_join_order($query) {
        // Use STRAIGHT_JOIN for complex queries to force optimal join order
        if (substr_count($query, 'JOIN') > 3) {
            $query = str_replace('SELECT ', 'SELECT STRAIGHT_JOIN ', $query);
        }

        return $query;
    }

    /**
     * Optimize COUNT queries
     *
     * @param string $query
     * @return string
     */
    private function optimize_count_queries($query) {
        // For EXISTS subqueries, add LIMIT 1
        if (strpos($query, 'EXISTS') !== false) {
            $query = preg_replace('/EXISTS\s*\((.*?)\)/s', 'EXISTS ($1 LIMIT 1)', $query);
        }

        // For COUNT queries without GROUP BY, use SQL_CALC_FOUND_ROWS alternative
        if (strpos($query, 'COUNT(') !== false && strpos($query, 'GROUP BY') === false) {
            // Add SQL_NO_CACHE for frequently changing data
            if (strpos($query, 'WHERE') !== false &&
                (strpos($query, 'modification_timestamp') !== false ||
                 strpos($query, 'original_entry_timestamp') !== false)) {
                $query = str_replace('SELECT COUNT', 'SELECT SQL_NO_CACHE COUNT', $query);
            }
        }

        return $query;
    }

    /**
     * Analyze query performance after execution
     *
     * @param string $query
     * @param float $execution_time
     * @param string $context
     */
    public function analyze_query_performance($query, $execution_time, $context = '') {
        // Log slow queries
        if ($execution_time > 1.0) { // Queries taking more than 1 second
            $this->log_slow_query($query, $execution_time, $context);

            // Generate index recommendations
            $this->generate_index_recommendations($query);
        }

        // Store statistics
        $this->query_stats[] = [
            'query' => $query,
            'time' => $execution_time,
            'context' => $context,
            'timestamp' => current_time('mysql')
        ];

        // Keep only last 100 queries in memory
        if (count($this->query_stats) > 100) {
            array_shift($this->query_stats);
        }
    }

    /**
     * Log slow queries
     *
     * @param string $query
     * @param float $execution_time
     * @param string $context
     */
    private function log_slow_query($query, $execution_time, $context) {
        if (class_exists('MLD_Logger')) {
            MLD_Logger::warning('Slow query detected', [
                'execution_time' => $execution_time,
                'context' => $context,
                'query' => substr($query, 0, 500) // Log first 500 chars
            ]);
        }
    }

    /**
     * Generate index recommendations based on query patterns
     *
     * @param string $query
     */
    private function generate_index_recommendations($query) {
        $recommendations = [];

        // Check for missing indexes on WHERE conditions
        if (preg_match_all('/WHERE.*?(\w+)\.(\w+)\s*=/', $query, $matches)) {
            foreach ($matches[2] as $column) {
                if (!$this->index_exists_for_column($column)) {
                    $recommendations[] = "Consider adding index on column: {$column}";
                }
            }
        }

        // Check for missing indexes on JOIN conditions
        if (preg_match_all('/JOIN.*?ON\s+(\w+)\.(\w+)\s*=\s*(\w+)\.(\w+)/', $query, $matches)) {
            for ($i = 0; $i < count($matches[0]); $i++) {
                $col1 = $matches[2][$i];
                $col2 = $matches[4][$i];

                if (!$this->index_exists_for_column($col1)) {
                    $recommendations[] = "Consider adding index on join column: {$col1}";
                }
                if (!$this->index_exists_for_column($col2)) {
                    $recommendations[] = "Consider adding index on join column: {$col2}";
                }
            }
        }

        // Check for ORDER BY without index
        if (preg_match('/ORDER BY\s+(\w+)\.(\w+)/', $query, $matches)) {
            $column = $matches[2];
            if (!$this->index_exists_for_column($column)) {
                $recommendations[] = "Consider adding index on ORDER BY column: {$column}";
            }
        }

        $this->index_recommendations = array_unique(array_merge($this->index_recommendations, $recommendations));
    }

    /**
     * Check if index exists for column
     *
     * @param string $column
     * @return bool
     */
    private function index_exists_for_column($column) {
        // Common indexed columns in MLS database
        $indexed_columns = [
            'listing_id', 'listing_key', 'id', 'standard_status',
            'list_price', 'city', 'postal_code', 'property_type',
            'property_sub_type', 'modification_timestamp', 'coordinates',
            'bedrooms_total', 'bathrooms_full', 'living_area', 'year_built'
        ];

        return in_array($column, $indexed_columns);
    }

    /**
     * Check and create missing indexes
     */
    public function check_and_create_indexes() {
        global $wpdb;

        $indexes_to_create = [
            // Composite indexes for common query patterns
            [
                'table' => 'wp_bme_listings',
                'name' => 'idx_city_status',
                'columns' => 'city, standard_status',
                'check_query' => "SHOW INDEX FROM wp_bme_listings WHERE Key_name = 'idx_city_status'"
            ],
            [
                'table' => 'wp_bme_listings',
                'name' => 'idx_status_price',
                'columns' => 'standard_status, list_price',
                'check_query' => "SHOW INDEX FROM wp_bme_listings WHERE Key_name = 'idx_status_price'"
            ],
            [
                'table' => 'wp_bme_listing_location',
                'name' => 'idx_city_lookup',
                'columns' => 'city, listing_id',
                'check_query' => "SHOW INDEX FROM wp_bme_listing_location WHERE Key_name = 'idx_city_lookup'"
            ],
            [
                'table' => 'wp_bme_listing_details',
                'name' => 'idx_beds_baths',
                'columns' => 'bedrooms_total, bathrooms_full',
                'check_query' => "SHOW INDEX FROM wp_bme_listing_details WHERE Key_name = 'idx_beds_baths'"
            ]
        ];

        foreach ($indexes_to_create as $index) {
            // Check if index exists
            $exists = $wpdb->get_var($index['check_query']);

            if (!$exists) {
                // Create index
                $create_query = sprintf(
                    "ALTER TABLE %s ADD INDEX %s (%s)",
                    $index['table'],
                    $index['name'],
                    $index['columns']
                );

                $wpdb->query($create_query);

                if (class_exists('MLD_Logger')) {
                    MLD_Logger::info("Created database index: {$index['name']} on {$index['table']}");
                }
            }
        }
    }

    /**
     * Get query statistics
     *
     * @return array
     */
    public function get_query_statistics() {
        $total_queries = count($this->query_stats);
        $total_time = array_sum(array_column($this->query_stats, 'time'));
        $avg_time = $total_queries > 0 ? $total_time / $total_queries : 0;

        $slow_queries = array_filter($this->query_stats, function($stat) {
            return $stat['time'] > 1.0;
        });

        return [
            'total_queries' => $total_queries,
            'total_time' => round($total_time, 3),
            'average_time' => round($avg_time, 3),
            'slow_queries' => count($slow_queries),
            'recommendations' => $this->index_recommendations
        ];
    }

    /**
     * Clear query statistics
     */
    public function clear_statistics() {
        $this->query_stats = [];
        $this->index_recommendations = [];
    }
}

// Initialize the optimizer
add_action('init', function() {
    if (class_exists('MLD_Query_Optimizer_Enhanced')) {
        MLD_Query_Optimizer_Enhanced::get_instance();
    }
});