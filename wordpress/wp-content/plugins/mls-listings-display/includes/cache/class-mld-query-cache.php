<?php
/**
 * MLSDisplay Query Cache
 *
 * Comprehensive caching system for MLS Display queries with intelligent invalidation
 * and performance optimization. Uses WordPress transients with advanced cache strategies.
 *
 * @package MLS_Listings_Display
 * @version 1.0.0
 */

namespace MLSDisplay\Cache;

if (!defined('ABSPATH')) {
    exit;
}

class QueryCache {

    /**
     * Cache key prefix
     */
    const CACHE_PREFIX = 'mld_query_';

    /**
     * Default cache TTL (1 hour)
     */
    const DEFAULT_TTL = 3600;

    /**
     * Cache groups for targeted invalidation
     */
    const CACHE_GROUPS = [
        'listings' => 'listings',
        'searches' => 'searches',
        'filters' => 'filters',
        'agents' => 'agents',
        'geography' => 'geography',
        'notifications' => 'notifications',
        'statistics' => 'statistics'
    ];

    /**
     * Cache statistics
     */
    private static $stats = [
        'hits' => 0,
        'misses' => 0,
        'sets' => 0,
        'deletes' => 0
    ];

    /**
     * Cache instance
     */
    private static $instance = null;

    /**
     * Get singleton instance
     */
    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Get cached data
     *
     * @param string $key Cache key
     * @param string $group Cache group
     * @return mixed|false Cached data or false if not found
     */
    public function get($key, $group = 'listings') {
        $cache_key = $this->build_cache_key($key, $group);
        $data = get_transient($cache_key);

        if (false !== $data) {
            self::$stats['hits']++;
            $this->log_cache_action('HIT', $cache_key);
            return $data;
        }

        self::$stats['misses']++;
        $this->log_cache_action('MISS', $cache_key);
        return false;
    }

    /**
     * Set cached data
     *
     * @param string $key Cache key
     * @param mixed $data Data to cache
     * @param int $ttl Time to live in seconds
     * @param string $group Cache group
     * @return bool True on success
     */
    public function set($key, $data, $ttl = self::DEFAULT_TTL, $group = 'listings') {
        $cache_key = $this->build_cache_key($key, $group);

        // Add metadata for cache management
        $cache_data = [
            'data' => $data,
            'created' => time(),
            'ttl' => $ttl,
            'group' => $group,
            'key' => $key,
            'size' => $this->estimate_size($data)
        ];

        $result = set_transient($cache_key, $cache_data, $ttl);

        if ($result) {
            self::$stats['sets']++;
            $this->log_cache_action('SET', $cache_key, $ttl);
            $this->track_cache_key($cache_key, $group);
        }

        return $result;
    }

    /**
     * Delete cached data
     *
     * @param string $key Cache key
     * @param string $group Cache group
     * @return bool True on success
     */
    public function delete($key, $group = 'listings') {
        $cache_key = $this->build_cache_key($key, $group);
        $result = delete_transient($cache_key);

        if ($result) {
            self::$stats['deletes']++;
            $this->log_cache_action('DELETE', $cache_key);
            $this->untrack_cache_key($cache_key, $group);
        }

        return $result;
    }

    /**
     * Invalidate entire cache group
     *
     * @param string $group Cache group to invalidate
     * @return int Number of keys deleted
     */
    public function invalidate_group($group) {
        $keys = $this->get_group_keys($group);
        $deleted = 0;

        foreach ($keys as $key) {
            if (delete_transient($key)) {
                $deleted++;
            }
        }

        $this->clear_group_tracking($group);
        $this->log_cache_action('INVALIDATE_GROUP', $group, null, $deleted);

        return $deleted;
    }

    /**
     * Cache listings query results
     *
     * @param array $params Query parameters
     * @param array $results Query results
     * @param int $ttl Cache TTL
     * @return bool Success status
     */
    public function cache_listings_query($params, $results, $ttl = self::DEFAULT_TTL) {
        $key = $this->generate_listings_key($params);
        return $this->set($key, $results, $ttl, 'listings');
    }

    /**
     * Get cached listings query results
     *
     * @param array $params Query parameters
     * @return array|false Cached results or false
     */
    public function get_cached_listings($params) {
        $key = $this->generate_listings_key($params);
        $cached = $this->get($key, 'listings');
        return $cached !== false ? $cached['data'] : false;
    }

    /**
     * Cache search results
     *
     * @param string $search_hash Search parameters hash
     * @param array $results Search results
     * @param int $ttl Cache TTL
     * @return bool Success status
     */
    public function cache_search_results($search_hash, $results, $ttl = 1800) {
        $key = 'search_results_' . $search_hash;
        return $this->set($key, $results, $ttl, 'searches');
    }

    /**
     * Get cached search results
     *
     * @param string $search_hash Search parameters hash
     * @return array|false Cached results or false
     */
    public function get_cached_search($search_hash) {
        $key = 'search_results_' . $search_hash;
        $cached = $this->get($key, 'searches');
        return $cached !== false ? $cached['data'] : false;
    }

    /**
     * Cache filter options
     *
     * @param string $filter_type Filter type (cities, property_types, etc.)
     * @param array $options Filter options
     * @param int $ttl Cache TTL
     * @return bool Success status
     */
    public function cache_filter_options($filter_type, $options, $ttl = 3600) {
        $key = 'filter_options_' . $filter_type;
        return $this->set($key, $options, $ttl, 'filters');
    }

    /**
     * Get cached filter options
     *
     * @param string $filter_type Filter type
     * @return array|false Cached options or false
     */
    public function get_cached_filter_options($filter_type) {
        $key = 'filter_options_' . $filter_type;
        $cached = $this->get($key, 'filters');
        return $cached !== false ? $cached['data'] : false;
    }

    /**
     * Cache agent data
     *
     * @param string $agent_id Agent ID
     * @param array $agent_data Agent data
     * @param int $ttl Cache TTL
     * @return bool Success status
     */
    public function cache_agent_data($agent_id, $agent_data, $ttl = 7200) {
        $key = 'agent_data_' . $agent_id;
        return $this->set($key, $agent_data, $ttl, 'agents');
    }

    /**
     * Get cached agent data
     *
     * @param string $agent_id Agent ID
     * @return array|false Cached agent data or false
     */
    public function get_cached_agent_data($agent_id) {
        $key = 'agent_data_' . $agent_id;
        $cached = $this->get($key, 'agents');
        return $cached !== false ? $cached['data'] : false;
    }

    /**
     * Cache geographic data (cities, boundaries, etc.)
     *
     * @param string $geo_type Geographic data type
     * @param string $identifier Geographic identifier
     * @param array $geo_data Geographic data
     * @param int $ttl Cache TTL
     * @return bool Success status
     */
    public function cache_geographic_data($geo_type, $identifier, $geo_data, $ttl = 86400) {
        $key = 'geo_' . $geo_type . '_' . md5($identifier);
        return $this->set($key, $geo_data, $ttl, 'geography');
    }

    /**
     * Get cached geographic data
     *
     * @param string $geo_type Geographic data type
     * @param string $identifier Geographic identifier
     * @return array|false Cached geographic data or false
     */
    public function get_cached_geographic_data($geo_type, $identifier) {
        $key = 'geo_' . $geo_type . '_' . md5($identifier);
        $cached = $this->get($key, 'geography');
        return $cached !== false ? $cached['data'] : false;
    }

    /**
     * Cache statistics data
     *
     * @param string $stat_type Statistics type
     * @param array $stats_data Statistics data
     * @param int $ttl Cache TTL
     * @return bool Success status
     */
    public function cache_statistics($stat_type, $stats_data, $ttl = 1800) {
        $key = 'stats_' . $stat_type;
        return $this->set($key, $stats_data, $ttl, 'statistics');
    }

    /**
     * Get cached statistics
     *
     * @param string $stat_type Statistics type
     * @return array|false Cached statistics or false
     */
    public function get_cached_statistics($stat_type) {
        $key = 'stats_' . $stat_type;
        $cached = $this->get($key, 'statistics');
        return $cached !== false ? $cached['data'] : false;
    }

    /**
     * Invalidate listings cache when data changes
     *
     * @param array $listing_ids Specific listing IDs to invalidate
     * @return int Number of cache entries invalidated
     */
    public function invalidate_listings_cache($listing_ids = []) {
        $invalidated = $this->invalidate_group('listings');

        // Also invalidate search cache as it may contain these listings
        $invalidated += $this->invalidate_group('searches');

        // Invalidate statistics that may be affected
        $invalidated += $this->invalidate_group('statistics');

        return $invalidated;
    }

    /**
     * Build cache key
     *
     * @param string $key Base key
     * @param string $group Cache group
     * @return string Full cache key
     */
    private function build_cache_key($key, $group) {
        return self::CACHE_PREFIX . $group . '_' . md5($key);
    }

    /**
     * Generate listings cache key from parameters
     *
     * @param array $params Query parameters
     * @return string Cache key
     */
    private function generate_listings_key($params) {
        // Sort parameters for consistent key generation
        ksort($params);

        // Remove non-cache-relevant parameters
        $cache_params = array_diff_key($params, [
            'nonce' => true,
            'timestamp' => true,
            'action' => true
        ]);

        return 'listings_' . md5(serialize($cache_params));
    }

    /**
     * Track cache keys by group for bulk operations
     *
     * @param string $cache_key Cache key
     * @param string $group Cache group
     */
    private function track_cache_key($cache_key, $group) {
        $tracking_key = self::CACHE_PREFIX . 'tracking_' . $group;
        $keys = get_option($tracking_key, []);

        if (!in_array($cache_key, $keys)) {
            $keys[] = $cache_key;
            // Limit tracking to 1000 keys per group
            if (count($keys) > 1000) {
                $keys = array_slice($keys, -1000);
            }
            update_option($tracking_key, $keys);
        }
    }

    /**
     * Remove cache key from tracking
     *
     * @param string $cache_key Cache key
     * @param string $group Cache group
     */
    private function untrack_cache_key($cache_key, $group) {
        $tracking_key = self::CACHE_PREFIX . 'tracking_' . $group;
        $keys = get_option($tracking_key, []);

        $index = array_search($cache_key, $keys);
        if ($index !== false) {
            unset($keys[$index]);
            update_option($tracking_key, array_values($keys));
        }
    }

    /**
     * Get all tracked keys for a group
     *
     * @param string $group Cache group
     * @return array Cache keys
     */
    private function get_group_keys($group) {
        $tracking_key = self::CACHE_PREFIX . 'tracking_' . $group;
        return get_option($tracking_key, []);
    }

    /**
     * Clear group tracking
     *
     * @param string $group Cache group
     */
    private function clear_group_tracking($group) {
        $tracking_key = self::CACHE_PREFIX . 'tracking_' . $group;
        delete_option($tracking_key);
    }

    /**
     * Estimate data size for cache monitoring
     *
     * @param mixed $data Data to estimate
     * @return int Estimated size in bytes
     */
    private function estimate_size($data) {
        return strlen(serialize($data));
    }

    /**
     * Log cache actions for debugging
     *
     * @param string $action Cache action
     * @param string $key Cache key
     * @param int $ttl TTL value
     * @param int $count Count for bulk operations
     */
    private function log_cache_action($action, $key, $ttl = null, $count = null) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $message = "MLD Cache {$action}: {$key}";
            if ($ttl) {
                $message .= " (TTL: {$ttl}s)";
            }
            if ($count) {
                $message .= " (Count: {$count})";
            }
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log($message);
            }
        }
    }

    /**
     * Get cache statistics
     *
     * @return array Cache statistics
     */
    public static function get_stats() {
        return self::$stats;
    }

    /**
     * Get cache hit ratio
     *
     * @return float Hit ratio (0-1)
     */
    public static function get_hit_ratio() {
        $total = self::$stats['hits'] + self::$stats['misses'];
        return $total > 0 ? self::$stats['hits'] / $total : 0;
    }

    /**
     * Get cache information for all groups
     *
     * @return array Cache group information
     */
    public function get_cache_info() {
        $info = [];

        foreach (self::CACHE_GROUPS as $group) {
            $keys = $this->get_group_keys($group);
            $info[$group] = [
                'key_count' => count($keys),
                'keys' => $keys
            ];
        }

        return $info;
    }

    /**
     * Clear all MLD cache
     *
     * @return int Total number of keys cleared
     */
    public function flush_all() {
        $total_cleared = 0;

        foreach (self::CACHE_GROUPS as $group) {
            $total_cleared += $this->invalidate_group($group);
        }

        $this->log_cache_action('FLUSH_ALL', 'all_groups', null, $total_cleared);

        return $total_cleared;
    }

    /**
     * Warm up cache with commonly accessed data
     *
     * @return array Results of cache warming
     */
    public function warm_cache() {
        $results = [];

        // Warm up filter options
        $filter_types = ['cities', 'property_types', 'property_sub_types', 'price_ranges'];

        foreach ($filter_types as $filter_type) {
            // This would be called from the main plugin to populate with actual data
            $results['filters'][$filter_type] = 'ready_for_warming';
        }

        return $results;
    }

    /**
     * Clean expired cache entries
     *
     * @return int Number of expired entries cleaned
     */
    public function clean_expired() {
        $cleaned = 0;

        foreach (self::CACHE_GROUPS as $group) {
            $keys = $this->get_group_keys($group);

            foreach ($keys as $cache_key) {
                $data = get_transient($cache_key);
                if (false === $data) {
                    // Transient has expired, remove from tracking
                    $this->untrack_cache_key($cache_key, $group);
                    $cleaned++;
                }
            }
        }

        return $cleaned;
    }

    /**
     * Get memory usage of cache
     *
     * @return array Memory usage information
     */
    public function get_memory_usage() {
        $usage = [];
        $total_size = 0;

        foreach (self::CACHE_GROUPS as $group) {
            $keys = $this->get_group_keys($group);
            $group_size = 0;

            foreach ($keys as $cache_key) {
                $data = get_transient($cache_key);
                if (false !== $data && isset($data['size'])) {
                    $group_size += $data['size'];
                }
            }

            $usage[$group] = [
                'size_bytes' => $group_size,
                'size_kb' => round($group_size / 1024, 2),
                'key_count' => count($keys)
            ];

            $total_size += $group_size;
        }

        $usage['total'] = [
            'size_bytes' => $total_size,
            'size_kb' => round($total_size / 1024, 2),
            'size_mb' => round($total_size / (1024 * 1024), 2)
        ];

        return $usage;
    }
}