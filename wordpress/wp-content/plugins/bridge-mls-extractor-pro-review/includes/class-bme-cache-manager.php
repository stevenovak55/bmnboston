<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cache manager using WordPress object cache
 *
 * Provides a high-performance caching layer using WordPress's built-in object cache
 * with automatic fallback to transients. Includes performance monitoring, statistics
 * tracking, and pattern-based cache invalidation.
 *
 * @package Bridge_MLS_Extractor_Pro
 * @since 1.0.0
 * @version 4.0.0
 */
class BME_Cache_Manager {

    /**
     * @var string Cache group identifier for namespacing
     */
    private $cache_group;

    /**
     * @var int Default time-to-live for cache entries in seconds
     */
    private $default_ttl;

    /**
     * @var array Cache performance statistics
     */
    private $cache_stats = [
        'hits' => 0,
        'misses' => 0,
        'sets' => 0,
        'deletes' => 0
    ];

    /**
     * Constructor
     *
     * Initializes cache configuration and sets up performance monitoring.
     */
    public function __construct() {
        $this->cache_group = BME_CACHE_GROUP;
        $this->default_ttl = BME_CACHE_DURATION;
        $this->init_performance_monitoring();
    }
    
    
    /**
     * Initialize performance monitoring
     *
     * Sets up cache statistics tracking and schedules daily cleanup tasks
     * to maintain optimal cache performance.
     *
     * @access private
     * @return void
     */
    private function init_performance_monitoring() {
        // Load cache statistics from persistent storage
        $stored_stats = get_option('bme_cache_stats', $this->cache_stats);
        $this->cache_stats = array_merge($this->cache_stats, $stored_stats);
        
        // Schedule cache statistics cleanup
        if (!wp_next_scheduled('bme_cache_stats_cleanup')) {
            wp_schedule_event(time(), 'daily', 'bme_cache_stats_cleanup');
        }

        add_action('bme_cache_stats_cleanup', [$this, 'cleanup_expired_cache']);
    }
    
    
    /**
     * Get cached data with performance tracking
     *
     * Retrieves data from cache or executes callback to generate fresh data.
     * Automatically caches the result of the callback for future requests.
     *
     * @param string $key Cache key identifier
     * @param callable|null $callback Function to generate data if not cached
     * @param int|null $ttl Time-to-live in seconds (uses default if null)
     * @return mixed Cached or freshly generated data
     */
    public function get($key, $callback = null, $ttl = null) {
        $start_time = microtime(true);
        $ttl = $ttl ?: $this->default_ttl;
        $cache_key = $this->build_cache_key($key);

        // Get from WordPress cache
        $cached_data = wp_cache_get($cache_key, $this->cache_group);

        if ($cached_data !== false) {
            $this->cache_stats['hits']++;
            $this->log_cache_performance('get', $cache_key, microtime(true) - $start_time, 'wp_cache', 'hit');
            return $cached_data;
        }

        $this->cache_stats['misses']++;

        if ($callback && is_callable($callback)) {
            $callback_start = microtime(true);
            $data = $callback();
            $callback_time = microtime(true) - $callback_start;

            $this->set($key, $data, $ttl);
            $this->log_cache_performance('get_callback', $cache_key, $callback_time, 'callback', 'miss');
            return $data;
        }

        $this->log_cache_performance('get', $cache_key, microtime(true) - $start_time, 'none', 'miss');
        return false;
    }
    
    /**
     * Set cache data
     */
    public function set($key, $data, $ttl = null) {
        $start_time = microtime(true);
        $ttl = $ttl ?: $this->default_ttl;
        $cache_key = $this->build_cache_key($key);

        $result = wp_cache_set($cache_key, $data, $this->cache_group, $ttl);

        $this->cache_stats['sets']++;

        $this->log_cache_performance('set', $cache_key, microtime(true) - $start_time,
                                   'wp_cache',
                                   $result ? 'success' : 'failure');

        return $result;
    }
    
    /**
     * Delete cached data
     */
    public function delete($key) {
        $start_time = microtime(true);
        $cache_key = $this->build_cache_key($key);

        $result = wp_cache_delete($cache_key, $this->cache_group);

        $this->cache_stats['deletes']++;

        $this->log_cache_performance('delete', $cache_key, microtime(true) - $start_time,
                                   'wp_cache',
                                   $result ? 'success' : 'failure');

        return $result;
    }
    
    /**
     * Clear all cache for the plugin
     */
    public function flush() {
        $result = false;

        if (function_exists('wp_cache_flush_group')) {
            $result = wp_cache_flush_group($this->cache_group);
        } else {
            $result = wp_cache_flush();
        }

        // Reset cache statistics
        $this->cache_stats = ['hits' => 0, 'misses' => 0, 'sets' => 0, 'deletes' => 0];
        update_option('bme_cache_stats', $this->cache_stats);

        error_log('BME Cache: Full cache flush completed');
        return $result;
    }
    
    
    /**
     * Log cache performance metrics
     */
    private function log_cache_performance($operation, $key, $duration, $source, $result) {
        // Only log if debug is enabled or if operation took longer than threshold
        $log_threshold = 0.1; // 100ms
        
        if ((defined('WP_DEBUG') && WP_DEBUG) || $duration > $log_threshold) {
            $key_preview = strlen($key) > 50 ? substr($key, 0, 47) . '...' : $key;
            error_log(sprintf(
                'BME Cache: %s [%s] %s from %s in %.3fms',
                $operation,
                $result,
                $key_preview,
                $source,
                $duration * 1000
            ));
        }
        
        // Update performance metrics
        $this->update_cache_metrics($operation, $duration, $source, $result);
    }
    
    /**
     * Update cache performance metrics
     */
    private function update_cache_metrics($operation, $duration, $source, $result) {
        $metrics_key = 'bme_cache_metrics_' . date('Y-m-d-H');
        $current_metrics = get_transient($metrics_key) ?: [
            'operations' => 0,
            'total_time' => 0,
            'avg_time' => 0,
            'wp_cache_hits' => 0,
            'misses' => 0,
            'sources' => []
        ];

        $current_metrics['operations']++;
        $current_metrics['total_time'] += $duration;
        $current_metrics['avg_time'] = $current_metrics['total_time'] / $current_metrics['operations'];

        if ($result === 'hit') {
            if ($source === 'wp_cache') {
                $current_metrics['wp_cache_hits']++;
            }
        } elseif ($result === 'miss') {
            $current_metrics['misses']++;
        }

        // Track source distribution
        if (!isset($current_metrics['sources'][$source])) {
            $current_metrics['sources'][$source] = 0;
        }
        $current_metrics['sources'][$source]++;

        set_transient($metrics_key, $current_metrics, HOUR_IN_SECONDS);
    }
    
    /**
     * Build standardized cache key
     */
    private function build_cache_key($key) {
        if (is_array($key)) {
            return md5(serialize($key));
        }
        return sanitize_key($key);
    }
    
    /**
     * Cache search results with smart invalidation
     */
    public function cache_search_results($filters, $results, $count) {
        $cache_key = 'search_' . md5(serialize($filters));
        
        $cache_data = [
            'results' => $results,
            'count' => $count,
            'timestamp' => time(),
            'filters' => $filters
        ];
        
        return $this->set($cache_key, $cache_data, 300); // 5 minutes TTL
    }
    
    /**
     * Get cached search results
     */
    public function get_cached_search($filters) {
        $cache_key = 'search_' . md5(serialize($filters));
        $cached_data = $this->get($cache_key);
        
        if ($cached_data && is_array($cached_data)) {
            // Check if cache is still fresh
            if ((time() - ($cached_data['timestamp'] ?? 0)) < 300) {
                return $cached_data;
            }
        }
        
        return null;
    }
    
    /**
     * Cache agent data with smart expiration
     */
    public function cache_agent_data($agent_mls_id, $agent_data) {
        $cache_key = 'agent_' . $agent_mls_id;
        
        $cache_data = [
            'data' => $agent_data,
            'cached_at' => time(),
            'expires_at' => time() + (24 * HOUR_IN_SECONDS) // 24 hours
        ];
        
        return $this->set($cache_key, $cache_data, 24 * HOUR_IN_SECONDS);
    }
    
    /**
     * Get cached agent data
     */
    public function get_cached_agent($agent_mls_id) {
        $cache_key = 'agent_' . $agent_mls_id;
        $cached_data = $this->get($cache_key);
        
        if ($cached_data && isset($cached_data['data'])) {
            return $cached_data['data'];
        }
        
        return null;
    }
    
    /**
     * Cache office data with smart expiration
     */
    public function cache_office_data($office_mls_id, $office_data) {
        $cache_key = 'office_' . $office_mls_id;
        
        $cache_data = [
            'data' => $office_data,
            'cached_at' => time(),
            'expires_at' => time() + (24 * HOUR_IN_SECONDS) // 24 hours
        ];
        
        return $this->set($cache_key, $cache_data, 24 * HOUR_IN_SECONDS);
    }
    
    /**
     * Get cached office data
     */
    public function get_cached_office($office_mls_id) {
        $cache_key = 'office_' . $office_mls_id;
        $cached_data = $this->get($cache_key);
        
        if ($cached_data && isset($cached_data['data'])) {
            return $cached_data['data'];
        }
        
        return null;
    }

    /**
     * Cache extraction statistics
     */
    public function cache_extraction_stats($extraction_id, $stats) {
        $cache_key = 'extraction_stats_' . $extraction_id;
        return $this->set($cache_key, $stats, HOUR_IN_SECONDS);
    }
    
    /**
     * Get cached extraction statistics
     */
    public function get_extraction_stats($extraction_id) {
        $cache_key = 'extraction_stats_' . $extraction_id;
        return $this->get($cache_key);
    }
    
    /**
     * Get cached filter values, or generate them if they don't exist.
     */
    public function get_filter_values($field) {
        $cache_key = 'filter_values_' . $field;

        // Use the generic get() method with a callback to fetch data if cache is missed.
        $values = $this->get($cache_key, function() use ($field) {
            global $wpdb;
            
            // Get the DB manager instance from the global plugin function.
            $db_manager = bme_pro()->get('db');

            $allowed_fields = [
                'standard_status' => 'listings',
                'property_type' => 'listings',
                'city' => 'listing_location',
                'state_or_province' => 'listing_location'
            ];

            if (!array_key_exists($field, $allowed_fields)) {
                return []; // Return empty array if field is not allowed
            }

            $table_key = $allowed_fields[$field];
            $table_active_name = $db_manager->get_table($table_key);
            $table_archive_name = $db_manager->get_table($table_key . '_archive');

            $sql = "
                (SELECT DISTINCT `{$field}` FROM `{$table_active_name}` WHERE `{$field}` IS NOT NULL AND `{$field}` != '')
                UNION
                (SELECT DISTINCT `{$field}` FROM `{$table_archive_name}` WHERE `{$field}` IS NOT NULL AND `{$field}` != '')
                ORDER BY 1 ASC
            ";

            return $wpdb->get_col($sql);
        }, HOUR_IN_SECONDS); // Cache for 1 hour

        return $values;
    }
    
    /**
     * Invalidate related caches when data changes.
     */
    /**
     * Invalidate listing-related caches
     * @param int|null $listing_id Specific listing ID to invalidate, or null for all
     * @param array $related_keys Additional cache keys to invalidate
     */
    public function invalidate_listing_caches($listing_id = null, $related_keys = []) {
        $start_time = microtime(true);
        $invalidated_count = 0;

        // Build list of cache keys to invalidate
        $keys_to_invalidate = [];

        if ($listing_id) {
            // Specific listing cache keys
            $keys_to_invalidate[] = 'listing_' . $listing_id;
            $keys_to_invalidate[] = 'listing_details_' . $listing_id;
            $keys_to_invalidate[] = 'listing_media_' . $listing_id;
            $keys_to_invalidate[] = 'listing_similar_' . $listing_id;
            $keys_to_invalidate[] = 'listing_history_' . $listing_id;

            // Get property type and city for related cache invalidation
            global $wpdb;
            $tables = $this->get_bme_tables();
            if ($tables) {
                $listing_info = $wpdb->get_row($wpdb->prepare(
                    "SELECT l.property_type, l.property_sub_type, ll.city
                     FROM {$tables['listings']} l
                     LEFT JOIN {$tables['listing_location']} ll ON l.listing_id = ll.listing_id
                     WHERE l.listing_id = %d",
                    $listing_id
                ));

                if ($listing_info) {
                    // Invalidate related filter caches
                    $keys_to_invalidate[] = 'filter_city_' . md5($listing_info->city);
                    $keys_to_invalidate[] = 'filter_property_type_' . md5($listing_info->property_type);
                    $keys_to_invalidate[] = 'filter_property_subtype_' . md5($listing_info->property_sub_type);
                }
            }
        } else {
            // Invalidate all listing-related caches
            $keys_to_invalidate[] = 'all_listings';
            $keys_to_invalidate[] = 'active_listings';
            $keys_to_invalidate[] = 'closed_listings';
            $keys_to_invalidate[] = 'filter_values_*';
            $keys_to_invalidate[] = 'property_type_counts';
            $keys_to_invalidate[] = 'city_counts';
            $keys_to_invalidate[] = 'price_distribution';
        }

        // Add any additional related keys
        $keys_to_invalidate = array_merge($keys_to_invalidate, $related_keys);

        // Perform invalidation
        foreach ($keys_to_invalidate as $key) {
            if (strpos($key, '*') !== false) {
                // Pattern-based invalidation
                $this->invalidate_by_pattern($key);
            } else {
                // Direct key invalidation
                $cache_key = $this->build_cache_key($key);

                // Delete from WordPress cache
                wp_cache_delete($cache_key, $this->cache_group);
                $invalidated_count++;
            }
        }

        $elapsed = microtime(true) - $start_time;
        error_log(sprintf(
            'BME Cache: Invalidated %d cache keys in %.3f seconds %s',
            $invalidated_count,
            $elapsed,
            $listing_id ? "for listing $listing_id" : "for all listings"
        ));

        return $invalidated_count;
    }
    
    /**
     * Invalidate cache keys by pattern
     */
    private function invalidate_by_pattern($pattern) {
        $pattern = str_replace('*', '', $pattern);

        // WordPress cache doesn't support pattern deletion, so we track keys
        $tracked_keys = get_option('bme_cache_tracked_keys', []);
        foreach ($tracked_keys as $key) {
            if (strpos($key, $pattern) === 0) {
                wp_cache_delete($this->build_cache_key($key), $this->cache_group);
            }
        }
    }
    
    /**
     * Get BME database tables
     */
    private function get_bme_tables() {
        if (function_exists('bme_pro')) {
            $bme = bme_pro();
            if ($bme && method_exists($bme, 'get')) {
                $db = $bme->get('db');
                if ($db && method_exists($db, 'get_tables')) {
                    return $db->get_tables();
                }
            }
        }
        return null;
    }
    
    /**
     * Warm up frequently accessed caches, such as filter dropdown options and extraction stats.
     */
    public function warm_up_caches() {
        $this->warm_up_filter_caches();
        $this->warm_up_extraction_stats();
    }
    
    /**
     * Warm up filter value caches by querying distinct values from the database.
     */
    private function warm_up_filter_caches() {
        $filter_fields = ['standard_status', 'property_type', 'city', 'state_or_province'];
        
        foreach ($filter_fields as $field) {
            // This will trigger the callback in get_filter_values if the cache is empty
            $this->get_filter_values($field);
        }
    }
    
    /**
     * Warm up extraction statistics for recently updated or frequently viewed extractions.
     */
    private function warm_up_extraction_stats() {
        $extractions = get_posts([
            'post_type' => 'bme_extraction',
            'posts_per_page' => 10,
            'post_status' => 'publish',
            'orderby' => 'modified',
            'order' => 'DESC',
            'fields' => 'ids'
        ]);
        
        if (empty($extractions)) return;

        $data_processor = bme_pro()->get('processor');
        
        foreach ($extractions as $extraction_id) {
            $cache_key = 'extraction_stats_' . $extraction_id;
            
            if (!$this->get($cache_key)) { // Only warm up if not already cached
                $stats = $data_processor->get_extraction_stats($extraction_id);
                $this->cache_extraction_stats($extraction_id, $stats);
            }
        }
    }
    
    /**
     * Get cache statistics
     */
    public function get_cache_stats() {
        $stats = [
            'cache_backend' => 'WordPress Object Cache',
            'group' => $this->cache_group,
            'default_ttl' => $this->default_ttl
        ];

        if (function_exists('wp_cache_get_stats')) {
            $cache_stats = wp_cache_get_stats();
            if ($cache_stats) {
                $stats['cache_stats'] = $cache_stats;
            }
        }

        return $stats;
    }
    
    
    /**
     * Schedules a daily cron job to clean up expired cache entries from database tables.
     */
    public function schedule_cache_cleanup() {
        if (!wp_next_scheduled('bme_pro_cache_cleanup')) {
            wp_schedule_event(time(), 'daily', 'bme_pro_cache_cleanup');
        }
    }
    
    /**
     * Cleans up expired cache entries from the agents and offices database tables.
     */
    public function cleanup_expired_cache() {
        global $wpdb;
        
        $db_manager = bme_pro()->get('db');
        
        $agents_table = $db_manager->get_table('agents');
        $deleted_agents = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$agents_table} WHERE last_updated < %s",
            date('Y-m-d H:i:s', strtotime('-30 days'))
        ));

        $offices_table = $db_manager->get_table('offices');
        $deleted_offices = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$offices_table} WHERE last_updated < %s",
            date('Y-m-d H:i:s', strtotime('-30 days'))
        ));
        
        if ($deleted_agents > 0 || $deleted_offices > 0) {
            error_log("BME Cache Cleanup: Removed {$deleted_agents} expired agents and {$deleted_offices} expired offices");
        }
        
        return [
            'deleted_agents' => $deleted_agents,
            'deleted_offices' => $deleted_offices
        ];
    }
    
    /**
     * Caches large datasets, with a check against memory limits to prevent crashes.
     */
    public function cache_large_dataset($key, $data, $ttl = null) {
        $data_size = strlen(serialize($data));
        $memory_limit = $this->get_memory_limit_bytes();
        
        if ($data_size > ($memory_limit * 0.1)) {
            error_log("BME Cache: Dataset too large to cache ({$data_size} bytes). Memory limit: {$memory_limit} bytes.");
            return false;
        }
        
        return $this->set($key, $data, $ttl);
    }
    
    /**
     * Gets the PHP memory limit in bytes.
     */
    private function get_memory_limit_bytes() {
        $memory_limit = ini_get('memory_limit');
        
        if (preg_match('/^(\d+)(.)$/', $memory_limit, $matches)) {
            $number = (int) $matches[1];
            $suffix = strtoupper($matches[2]);
            
            switch ($suffix) {
                case 'G':
                    return $number * 1024 * 1024 * 1024;
                case 'M':
                    return $number * 1024 * 1024;
                case 'K':
                    return $number * 1024;
                default:
                    return $number;
            }
        }
        
        return 128 * 1024 * 1024;
    }
    
    /**
     * Get multiple cache entries
     */
    public function get_multiple($keys) {
        $results = [];
        foreach ($keys as $key) {
            $results[$key] = $this->get($key);
        }
        return $results;
    }
    
    /**
     * Set multiple cache entries
     */
    public function set_multiple($data_array, $ttl = null) {
        $results = [];
        foreach ($data_array as $key => $data) {
            $results[$key] = $this->set($key, $data, $ttl);
        }
        return $results;
    }
    
    /**
     * Get comprehensive cache statistics
     */
    public function get_advanced_cache_statistics() {
        $stats = $this->cache_stats;
        $total_operations = $stats['hits'] + $stats['misses'];

        $performance_data = [
            'basic_stats' => $stats,
            'hit_rate' => $total_operations > 0 ? round(($stats['hits'] / $total_operations) * 100, 2) : 0,
            'cache_backend' => 'WordPress Object Cache'
        ];

        // Get recent hourly metrics
        $hourly_metrics = [];

        for ($i = 0; $i < 24; $i++) {
            $hour_key = 'bme_cache_metrics_' . date('Y-m-d-H', strtotime("-{$i} hours"));
            $metrics = get_transient($hour_key);
            if ($metrics) {
                $hourly_metrics[date('H:i', strtotime("-{$i} hours"))] = $metrics;
            }
        }

        $performance_data['hourly_metrics'] = array_reverse($hourly_metrics, true);

        return $performance_data;
    }
}
