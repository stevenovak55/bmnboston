<?php
/**
 * MLS Listings Display - Smart Query Router
 *
 * Intelligently routes queries to the most optimal data source:
 * - Summary table for active listings (8.5x faster)
 * - Traditional JOINs for detailed data
 * - Archive tables for historical data
 *
 * @package MLS_Listings_Display
 * @subpackage Core
 * @since 5.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Query_Router {

    /**
     * In-memory cache for table existence checks
     * @since 6.11.4
     */
    private static $table_exists_cache = [];

    /**
     * Query routing statistics
     */
    private static $stats = [
        'summary_queries' => 0,
        'traditional_queries' => 0,
        'archive_queries' => 0,
        'cache_hits' => 0,
        'total_time_saved' => 0
    ];

    /**
     * Available query methods in order of preference
     */
    private static $query_methods = [
        'summary' => [
            'available' => null,
            'supports' => ['active', 'basic_fields', 'map', 'grid', 'search'],
            'performance_factor' => 8.5
        ],
        'traditional' => [
            'available' => true,
            'supports' => ['all'],
            'performance_factor' => 1
        ],
        'archive' => [
            'available' => true,
            'supports' => ['sold', 'expired', 'withdrawn', 'canceled'],
            'performance_factor' => 1
        ]
    ];

    /**
     * Initialize the query router
     */
    public static function init() {
        // Check if summary table is available
        self::$query_methods['summary']['available'] = self::check_summary_availability();

        // Add hooks for performance monitoring
        add_action('shutdown', [__CLASS__, 'log_performance_stats']);
    }

    /**
     * Route a query to the optimal method
     *
     * @param string $query_type Type of query (map, search, detail, etc.)
     * @param array $params Query parameters
     * @return mixed Query results
     */
    public static function route_query($query_type, $params) {
        $start_time = microtime(true);

        // Determine the best method for this query
        $method = self::determine_best_method($query_type, $params);

        // Log the routing decision
        self::log_routing_decision($query_type, $method, $params);

        // Execute the query using the chosen method
        $result = self::execute_query($method, $query_type, $params);

        // Track performance
        $end_time = microtime(true);
        self::track_performance($method, $end_time - $start_time);

        return $result;
    }

    /**
     * Determine the best query method based on type and parameters
     *
     * @param string $query_type
     * @param array $params
     * @return string Method name
     */
    private static function determine_best_method($query_type, $params) {
        // Check if we're querying archive statuses
        if (self::needs_archive_tables($params)) {
            return 'archive';
        }

        // Check if summary table can handle this query
        if (self::can_use_summary($query_type, $params)) {
            return 'summary';
        }

        // Default to traditional JOINs
        return 'traditional';
    }

    /**
     * Check if query needs archive tables
     *
     * @param array $params
     * @return bool
     */
    private static function needs_archive_tables($params) {
        $archive_statuses = ['Closed', 'Expired', 'Withdrawn', 'Canceled', 'Sold'];

        if (isset($params['status'])) {
            $statuses = is_array($params['status']) ? $params['status'] : [$params['status']];
            return !empty(array_intersect($statuses, $archive_statuses));
        }

        // Check for date-based archive queries
        if (isset($params['close_date_min']) || isset($params['close_date_max'])) {
            return true;
        }

        return false;
    }

    /**
     * Check if summary table can handle this query
     *
     * @param string $query_type
     * @param array $params
     * @return bool
     */
    private static function can_use_summary($query_type, $params) {
        // Check if summary table is available
        if (!self::$query_methods['summary']['available']) {
            return false;
        }

        // Check if query type is supported
        $supported_types = ['map', 'search', 'grid', 'similar', 'featured'];
        if (!in_array($query_type, $supported_types)) {
            return false;
        }

        // Check if all required fields are in summary
        $summary_fields = self::get_summary_fields();
        $required_fields = self::extract_required_fields($params);

        foreach ($required_fields as $field) {
            if (!in_array($field, $summary_fields)) {
                return false;
            }
        }

        // Check status compatibility
        if (isset($params['status'])) {
            $allowed_statuses = ['Active', 'Active Under Contract', 'Pending'];
            $statuses = is_array($params['status']) ? $params['status'] : [$params['status']];

            foreach ($statuses as $status) {
                if (!in_array($status, $allowed_statuses)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Execute query using the chosen method
     *
     * @param string $method
     * @param string $query_type
     * @param array $params
     * @return mixed
     */
    private static function execute_query($method, $query_type, $params) {
        $provider = MLD_BME_Data_Provider::get_instance();

        switch ($method) {
            case 'summary':
                self::$stats['summary_queries']++;
                return self::execute_summary_query($query_type, $params);

            case 'archive':
                self::$stats['archive_queries']++;
                if (method_exists($provider, 'get_archive_listings')) {
                    return $provider->get_archive_listings(
                        $params['filters'] ?? [],
                        $params['limit'] ?? 20,
                        $params['offset'] ?? 0
                    );
                }
                break;

            case 'traditional':
            default:
                self::$stats['traditional_queries']++;
                return $provider->get_listings(
                    $params['filters'] ?? [],
                    $params['limit'] ?? 20,
                    $params['offset'] ?? 0
                );
        }
    }

    /**
     * Execute a query using the summary table
     *
     * @param string $query_type
     * @param array $params
     * @return array
     */
    private static function execute_summary_query($query_type, $params) {
        global $wpdb;
        $summary_table = $wpdb->prefix . 'bme_listing_summary';

        switch ($query_type) {
            case 'map':
                return self::execute_map_query_optimized($params);

            case 'search':
            case 'grid':
                return self::execute_search_query_optimized($params);

            case 'similar':
                return self::execute_similar_query_optimized($params);

            case 'featured':
                return self::execute_featured_query_optimized($params);

            default:
                // Fallback to provider method
                $provider = MLD_BME_Data_Provider::get_instance();
                if (method_exists($provider, 'get_listings_optimized')) {
                    return $provider->get_listings_optimized(
                        $params['filters'] ?? [],
                        $params['limit'] ?? 20,
                        $params['offset'] ?? 0
                    );
                }
        }
    }

    /**
     * Execute optimized map query
     */
    private static function execute_map_query_optimized($params) {
        global $wpdb;
        $summary_table = $wpdb->prefix . 'bme_listing_summary';

        $where_clauses = ["standard_status IN ('Active', 'Active Under Contract')"];

        // Add viewport bounds
        if (isset($params['bounds'])) {
            $bounds = $params['bounds'];
            $where_clauses[] = $wpdb->prepare(
                "latitude BETWEEN %f AND %f AND longitude BETWEEN %f AND %f",
                $bounds['south'], $bounds['north'],
                $bounds['west'], $bounds['east']
            );
        }

        // Add filters
        if (isset($params['filters'])) {
            $where_clauses = array_merge($where_clauses, self::build_summary_where_clauses($params['filters']));
        }

        $where = implode(' AND ', $where_clauses);
        $limit = $params['limit'] ?? 200;

        $query = "SELECT * FROM {$summary_table} WHERE {$where} ORDER BY list_price DESC LIMIT {$limit}";
        $results = $wpdb->get_results($query, ARRAY_A);

        return [
            'listings' => $results,
            'total' => count($results),
            'method' => 'summary'
        ];
    }

    /**
     * Execute optimized search query
     */
    private static function execute_search_query_optimized($params) {
        global $wpdb;
        $summary_table = $wpdb->prefix . 'bme_listing_summary';

        $where_clauses = ["standard_status IN ('Active', 'Active Under Contract')"];

        if (isset($params['filters'])) {
            $where_clauses = array_merge($where_clauses, self::build_summary_where_clauses($params['filters']));
        }

        $where = implode(' AND ', $where_clauses);
        $limit = $params['limit'] ?? 20;
        $offset = $params['offset'] ?? 0;

        $query = "SELECT * FROM {$summary_table} WHERE {$where}
                 ORDER BY modification_timestamp DESC
                 LIMIT {$offset}, {$limit}";

        $results = $wpdb->get_results($query, ARRAY_A);

        // Get total count
        $count_query = "SELECT COUNT(*) FROM {$summary_table} WHERE {$where}";
        $total = (int) $wpdb->get_var($count_query);

        return [
            'listings' => $results,
            'total' => $total,
            'method' => 'summary'
        ];
    }

    /**
     * Execute optimized similar listings query
     */
    private static function execute_similar_query_optimized($params) {
        global $wpdb;
        $summary_table = $wpdb->prefix . 'bme_listing_summary';

        $current = $params['current_listing'];
        $count = $params['count'] ?? 4;

        $price = (float) $current['list_price'];
        $price_min = $price * 0.85;
        $price_max = $price * 1.15;

        $query = $wpdb->prepare(
            "SELECT *, ABS(list_price - %f) as price_diff
             FROM {$summary_table}
             WHERE standard_status = 'Active'
               AND listing_id != %s
               AND city = %s
               AND property_sub_type = %s
               AND list_price BETWEEN %f AND %f
             ORDER BY price_diff ASC
             LIMIT %d",
            $price,
            $current['listing_id'],
            $current['city'],
            $current['property_sub_type'],
            $price_min,
            $price_max,
            $count
        );

        return $wpdb->get_results($query, ARRAY_A);
    }

    /**
     * Execute optimized featured listings query
     */
    private static function execute_featured_query_optimized($params) {
        global $wpdb;
        $summary_table = $wpdb->prefix . 'bme_listing_summary';

        $limit = $params['limit'] ?? 6;

        $query = "SELECT * FROM {$summary_table}
                 WHERE standard_status = 'Active'
                   AND main_photo_url IS NOT NULL
                 ORDER BY modification_timestamp DESC
                 LIMIT {$limit}";

        return $wpdb->get_results($query, ARRAY_A);
    }

    /**
     * Build WHERE clauses for summary table queries
     */
    private static function build_summary_where_clauses($filters) {
        global $wpdb;
        $clauses = [];

        // Price range
        if (isset($filters['min_price'])) {
            $clauses[] = $wpdb->prepare("list_price >= %d", $filters['min_price']);
        }
        if (isset($filters['max_price'])) {
            $clauses[] = $wpdb->prepare("list_price <= %d", $filters['max_price']);
        }

        // Property details
        if (isset($filters['min_beds'])) {
            $clauses[] = $wpdb->prepare("bedrooms_total >= %d", $filters['min_beds']);
        }
        if (isset($filters['min_baths'])) {
            $clauses[] = $wpdb->prepare("bathrooms_total >= %d", $filters['min_baths']);
        }
        if (isset($filters['min_sqft'])) {
            $clauses[] = $wpdb->prepare("building_area_total >= %d", $filters['min_sqft']);
        }

        // Location
        if (isset($filters['city'])) {
            $clauses[] = $wpdb->prepare("city = %s", $filters['city']);
        }

        // Property type
        if (isset($filters['property_type'])) {
            $clauses[] = $wpdb->prepare("property_type = %s", $filters['property_type']);
        }

        return $clauses;
    }

    /**
     * Check if summary table is available
     * Uses cached table existence check for performance
     * @since 6.11.4 - Added caching
     */
    private static function check_summary_availability() {
        global $wpdb;
        $summary_table = $wpdb->prefix . 'bme_listing_summary';

        // Use cached table existence check
        if (!self::table_exists($summary_table)) {
            return false;
        }

        // Check if it has data (this query is fast with LIMIT 1)
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$summary_table} LIMIT 1");
        return $count > 0;
    }

    /**
     * Check if a database table exists with caching
     * Uses in-memory cache for same-request + transient for cross-request
     *
     * @param string $table_name Full table name (including prefix)
     * @return bool Whether the table exists
     * @since 6.11.4
     */
    private static function table_exists($table_name) {
        // Check in-memory cache first (fastest)
        if (isset(self::$table_exists_cache[$table_name])) {
            return self::$table_exists_cache[$table_name];
        }

        // Check transient cache (cross-request)
        $transient_key = 'mld_table_exists_' . md5($table_name);
        $cached = get_transient($transient_key);

        if ($cached !== false) {
            $exists = ($cached === 'yes');
            self::$table_exists_cache[$table_name] = $exists;
            return $exists;
        }

        // Query the database
        global $wpdb;
        $result = $wpdb->get_var(
            $wpdb->prepare("SHOW TABLES LIKE %s", $table_name)
        );
        $exists = ($result === $table_name);

        // Cache the result
        self::$table_exists_cache[$table_name] = $exists;
        set_transient($transient_key, $exists ? 'yes' : 'no', HOUR_IN_SECONDS);

        return $exists;
    }

    /**
     * Get list of fields available in summary table
     */
    private static function get_summary_fields() {
        return [
            'listing_id', 'listing_key', 'mls_id', 'property_type', 'property_sub_type',
            'standard_status', 'list_price', 'original_list_price', 'close_price',
            'bedrooms_total', 'bathrooms_total', 'bathrooms_full', 'bathrooms_half',
            'building_area_total', 'lot_size_acres', 'year_built',
            'street_number', 'street_name', 'unit_number', 'city', 'state_or_province',
            'postal_code', 'county', 'latitude', 'longitude',
            'garage_spaces', 'has_pool', 'has_fireplace', 'has_basement', 'has_hoa',
            'pet_friendly', 'main_photo_url', 'photo_count', 'virtual_tour_url',
            'listing_contract_date', 'close_date', 'days_on_market', 'modification_timestamp'
        ];
    }

    /**
     * Extract required fields from query parameters
     */
    private static function extract_required_fields($params) {
        $fields = [];

        // Extract fields from filters
        if (isset($params['filters'])) {
            foreach ($params['filters'] as $key => $value) {
                // Map filter keys to field names
                $field_map = [
                    'min_price' => 'list_price',
                    'max_price' => 'list_price',
                    'min_beds' => 'bedrooms_total',
                    'min_baths' => 'bathrooms_total',
                    'min_sqft' => 'building_area_total',
                    'city' => 'city',
                    'property_type' => 'property_type'
                ];

                if (isset($field_map[$key])) {
                    $fields[] = $field_map[$key];
                }
            }
        }

        // Extract fields from select clause if specified
        if (isset($params['fields'])) {
            $fields = array_merge($fields, $params['fields']);
        }

        return array_unique($fields);
    }

    /**
     * Track query performance
     */
    private static function track_performance($method, $execution_time) {
        if ($method === 'summary') {
            // Calculate time saved vs traditional query
            $traditional_time = $execution_time * 8.5; // Summary is 8.5x faster
            $time_saved = $traditional_time - $execution_time;
            self::$stats['total_time_saved'] += $time_saved;
        }
    }

    /**
     * Log routing decision for debugging
     */
    private static function log_routing_decision($query_type, $method, $params) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                '[MLD Query Router] Type: %s | Method: %s | Filters: %d',
                $query_type,
                $method,
                isset($params['filters']) ? count($params['filters']) : 0
            ));
        }
    }

    /**
     * Log performance statistics
     */
    public static function log_performance_stats() {
        if (self::$stats['summary_queries'] > 0 || self::$stats['traditional_queries'] > 0) {
            $total_queries = self::$stats['summary_queries'] +
                           self::$stats['traditional_queries'] +
                           self::$stats['archive_queries'];

            if ($total_queries > 0) {
                $summary_percentage = round((self::$stats['summary_queries'] / $total_queries) * 100, 1);
                $time_saved_ms = round(self::$stats['total_time_saved'] * 1000, 2);

                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log(sprintf(
                        '[MLD Performance] Queries: %d | Summary: %d (%.1f%%) | Time Saved: %.2fms',
                        $total_queries,
                        self::$stats['summary_queries'],
                        $summary_percentage,
                        $time_saved_ms
                    ));
                }
            }
        }
    }

    /**
     * Get current statistics
     */
    public static function get_stats() {
        return self::$stats;
    }

    /**
     * Reset statistics
     */
    public static function reset_stats() {
        self::$stats = [
            'summary_queries' => 0,
            'traditional_queries' => 0,
            'archive_queries' => 0,
            'cache_hits' => 0,
            'total_time_saved' => 0
        ];
    }
}

// Initialize the query router
add_action('init', ['MLD_Query_Router', 'init']);