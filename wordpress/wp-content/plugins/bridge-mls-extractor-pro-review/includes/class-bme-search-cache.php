<?php
/**
 * Search Cache Manager
 *
 * Manages search result caching for optimized query performance.
 * Implements MD5-based cache key generation and automatic expiration.
 *
 * @package Bridge_MLS_Extractor_Pro
 * @since 4.0.3
 * @version 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class BME_Search_Cache {

    /**
     * @var wpdb WordPress database abstraction object
     */
    private $wpdb;

    /**
     * @var string Cache table name
     */
    private $cache_table;

    /**
     * @var int Default cache TTL in seconds (1 hour)
     */
    private $default_ttl = 3600;

    /**
     * @var array Cache statistics for current request
     */
    private $stats = [
        'hits' => 0,
        'misses' => 0,
        'stores' => 0
    ];

    /**
     * Constructor
     *
     * @param int $ttl Optional custom TTL in seconds
     */
    public function __construct($ttl = null) {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->cache_table = $wpdb->prefix . 'bme_search_cache';

        if ($ttl !== null && is_numeric($ttl)) {
            $this->default_ttl = (int) $ttl;
        }
    }

    /**
     * Get cached search results
     *
     * Retrieves cached results if available and not expired.
     * Automatically increments hit count for cache statistics.
     *
     * @param array $params Search parameters
     * @return array|null Array of listing IDs or null if not cached
     */
    public function get_cached_results($params) {
        // Generate cache key
        $cache_key = $this->generate_cache_key($params);

        // Query cache table
        $cached = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT result_listing_ids, result_count
                 FROM {$this->cache_table}
                 WHERE cache_key = %s AND expires_at > NOW()",
                $cache_key
            )
        );

        if ($cached) {
            // Cache hit - increment counter
            $this->wpdb->query(
                $this->wpdb->prepare(
                    "UPDATE {$this->cache_table}
                     SET hit_count = hit_count + 1
                     WHERE cache_key = %s",
                    $cache_key
                )
            );

            $this->stats['hits']++;

            // Decode and return listing IDs
            $listing_ids = json_decode($cached->result_listing_ids, true);

            error_log(sprintf(
                '[BME Cache] HIT - %d results returned from cache (key: %s)',
                $cached->result_count,
                substr($cache_key, 0, 8)
            ));

            return $listing_ids;
        }

        // Cache miss
        $this->stats['misses']++;

        error_log(sprintf(
            '[BME Cache] MISS - Cache key %s not found or expired',
            substr($cache_key, 0, 8)
        ));

        return null;
    }

    /**
     * Cache search results
     *
     * Stores search results in cache with expiration time.
     * Uses REPLACE to handle both insert and update scenarios.
     *
     * @param array $params Search parameters
     * @param array $listing_ids Array of listing IDs to cache
     * @param int   $ttl Optional custom TTL (uses default if not provided)
     * @return bool True on success, false on failure
     */
    public function cache_results($params, $listing_ids, $ttl = null) {
        // Use default TTL if not specified
        $ttl = $ttl ?? $this->default_ttl;

        // Generate cache key
        $cache_key = $this->generate_cache_key($params);

        // Calculate expiration time
        $expires_at = date('Y-m-d H:i:s', time() + $ttl);

        // Store in cache (REPLACE handles both insert and update)
        $result = $this->wpdb->replace(
            $this->cache_table,
            [
                'cache_key' => $cache_key,
                'search_params' => json_encode($params),
                'result_listing_ids' => json_encode($listing_ids),
                'result_count' => count($listing_ids),
                'created_at' => current_time('mysql'),
                'expires_at' => $expires_at,
                'hit_count' => 0
            ],
            ['%s', '%s', '%s', '%d', '%s', '%s', '%d']
        );

        if ($result !== false) {
            $this->stats['stores']++;

            error_log(sprintf(
                '[BME Cache] STORE - Cached %d results (key: %s, TTL: %d seconds)',
                count($listing_ids),
                substr($cache_key, 0, 8),
                $ttl
            ));

            return true;
        }

        error_log('[BME Cache] ERROR - Failed to store cache entry');
        return false;
    }

    /**
     * Invalidate cache for specific listing
     *
     * Removes all cache entries when a listing is updated.
     * This ensures users always see current data.
     *
     * @param int $listing_id Listing ID that was updated
     * @return int Number of cache entries invalidated
     */
    public function invalidate_cache($listing_id = null) {
        if ($listing_id !== null) {
            // Invalidate specific listing (search for it in cached results)
            // This is complex, so for now we'll just invalidate all cache
            // In future, could store listing_ids in separate column for efficient lookup
            error_log("[BME Cache] Invalidating cache for listing {$listing_id}");
        }

        // For now, invalidate all cache by setting expiration to past
        $deleted = $this->wpdb->query(
            "UPDATE {$this->cache_table} SET expires_at = NOW()"
        );

        if ($deleted > 0) {
            error_log("[BME Cache] Invalidated {$deleted} cache entries");
        }

        return $deleted;
    }

    /**
     * Invalidate all cache entries
     *
     * Useful when bulk updates occur or data integrity is a concern
     *
     * @return int Number of entries invalidated
     */
    public function invalidate_all_cache() {
        $deleted = $this->wpdb->query(
            "DELETE FROM {$this->cache_table}"
        );

        if ($deleted > 0) {
            error_log("[BME Cache] Cleared entire cache: {$deleted} entries removed");
        }

        return $deleted;
    }

    /**
     * Get cache statistics for current request
     *
     * @return array Statistics including hits, misses, and stores
     */
    public function get_stats() {
        return $this->stats;
    }

    /**
     * Get cache effectiveness metrics
     *
     * Returns overall cache performance from database
     *
     * @return array Metrics including hit rate and popular searches
     */
    public function get_effectiveness_metrics() {
        $total_cached = $this->wpdb->get_var("SELECT COUNT(*) FROM {$this->cache_table}");
        $total_hits = $this->wpdb->get_var("SELECT SUM(hit_count) FROM {$this->cache_table}");

        // Get most popular cached searches
        $popular = $this->wpdb->get_results(
            "SELECT search_params, hit_count, result_count
             FROM {$this->cache_table}
             WHERE hit_count > 0
             ORDER BY hit_count DESC
             LIMIT 10"
        );

        $popular_searches = [];
        if ($popular) {
            foreach ($popular as $row) {
                $popular_searches[] = [
                    'params' => json_decode($row->search_params, true),
                    'hits' => (int) $row->hit_count,
                    'results' => (int) $row->result_count
                ];
            }
        }

        return [
            'total_cached_searches' => (int) $total_cached,
            'total_cache_hits' => (int) $total_hits,
            'hit_rate_percentage' => $total_cached > 0 ? round(($total_hits / $total_cached) * 100, 2) : 0,
            'popular_searches' => $popular_searches
        ];
    }

    /**
     * Generate MD5 cache key from parameters
     *
     * Ensures consistent key generation regardless of parameter order.
     * Uses JSON encoding for complex parameter values.
     *
     * @param array $params Search parameters
     * @return string 32-character MD5 hash
     */
    private function generate_cache_key($params) {
        // Sort parameters to ensure consistent key generation
        ksort($params);

        // Remove null and empty values to normalize
        $params = array_filter($params, function($value) {
            return $value !== null && $value !== '';
        });

        // Generate MD5 hash of JSON-encoded parameters
        $key = md5(json_encode($params));

        return $key;
    }

    /**
     * Warm cache with popular searches
     *
     * Pre-populates cache with common search patterns.
     * Should be run during off-peak hours.
     *
     * @param BME_Database_Manager $db Database manager instance
     * @return int Number of searches cached
     */
    public function warm_cache($db) {
        // Define popular search patterns
        $popular_searches = [
            ['city' => 'Reading', 'bedrooms' => 3, 'status' => 'Active'],
            ['city' => 'Reading', 'bedrooms' => 4, 'status' => 'Active'],
            ['city' => 'Reading', 'min_price' => 500000, 'max_price' => 1000000],
            ['city' => 'Reading', 'property_type' => 'Residential'],
            ['bedrooms' => 3, 'bathrooms' => 2, 'status' => 'Active'],
        ];

        $cached_count = 0;

        foreach ($popular_searches as $search_params) {
            // Execute search
            $results = $db->search_listings_optimized($search_params);

            if ($results !== false) {
                // Extract listing IDs
                $listing_ids = array_column($results, 'listing_id');

                // Cache results
                if ($this->cache_results($search_params, $listing_ids)) {
                    $cached_count++;
                }
            }
        }

        error_log("[BME Cache] Cache warming completed: {$cached_count} searches pre-cached");
        return $cached_count;
    }

    /**
     * Get size of cache table
     *
     * @return array Table size information
     */
    public function get_cache_size() {
        $size_info = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT
                    table_rows,
                    ROUND(data_length / 1024 / 1024, 2) as data_mb,
                    ROUND(index_length / 1024 / 1024, 2) as index_mb
                 FROM information_schema.TABLES
                 WHERE table_schema = %s AND table_name = %s",
                DB_NAME,
                $this->cache_table
            )
        );

        return [
            'rows' => (int) $size_info->table_rows ?? 0,
            'data_size_mb' => (float) $size_info->data_mb ?? 0,
            'index_size_mb' => (float) $size_info->index_mb ?? 0,
            'total_size_mb' => round((float) $size_info->data_mb + (float) $size_info->index_mb, 2)
        ];
    }
}
