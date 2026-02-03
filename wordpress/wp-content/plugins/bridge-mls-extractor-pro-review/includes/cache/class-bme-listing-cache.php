<?php
/**
 * BridgeMLS Listing Cache
 *
 * Advanced caching system for Bridge MLS listing data with intelligent invalidation,
 * multi-tier caching, and performance optimization for large datasets.
 *
 * @package Bridge_MLS_Extractor_Pro
 * @version 1.0.0
 */

namespace BridgeMLS\Cache;

if (!defined('ABSPATH')) {
    exit;
}

class ListingCache {

    /**
     * Cache key prefix
     */
    const CACHE_PREFIX = 'bme_listing_';

    /**
     * Cache TTL configurations
     */
    const TTL_SHORT = 300;   // 5 minutes - for active listings
    const TTL_MEDIUM = 1800; // 30 minutes - for general data
    const TTL_LONG = 3600;   // 1 hour - for historical data
    const TTL_EXTENDED = 86400; // 24 hours - for static data

    /**
     * Cache groups for targeted invalidation
     */
    const CACHE_GROUPS = [
        'listings' => 'listings',
        'details' => 'details',
        'location' => 'location',
        'financial' => 'financial',
        'features' => 'features',
        'media' => 'media',
        'agents' => 'agents',
        'offices' => 'offices',
        'statistics' => 'statistics',
        'searches' => 'searches'
    ];

    /**
     * Cache statistics
     */
    private static $stats = [
        'hits' => 0,
        'misses' => 0,
        'sets' => 0,
        'deletes' => 0,
        'invalidations' => 0
    ];

    /**
     * Cache instance
     */
    private static $instance = null;

    /**
     * Memory cache for request-level caching
     */
    private $memory_cache = [];

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
     * Multi-tier cache get: Memory -> WordPress Transients
     *
     * @param string $key Cache key
     * @param string $group Cache group
     * @return mixed|false Cached data or false if not found
     */
    public function get($key, $group = 'listings') {
        $cache_key = $this->build_cache_key($key, $group);

        // First, check memory cache
        if (isset($this->memory_cache[$cache_key])) {
            self::$stats['hits']++;
            $this->log_cache_action('MEMORY_HIT', $cache_key);
            return $this->memory_cache[$cache_key]['data'];
        }

        // Then check WordPress transients
        $data = get_transient($cache_key);

        if (false !== $data) {
            // Store in memory cache for subsequent requests
            $this->memory_cache[$cache_key] = $data;
            self::$stats['hits']++;
            $this->log_cache_action('TRANSIENT_HIT', $cache_key);
            return $data['data'];
        }

        self::$stats['misses']++;
        $this->log_cache_action('MISS', $cache_key);
        return false;
    }

    /**
     * Multi-tier cache set: Memory + WordPress Transients
     *
     * @param string $key Cache key
     * @param mixed $data Data to cache
     * @param int $ttl Time to live in seconds
     * @param string $group Cache group
     * @return bool True on success
     */
    public function set($key, $data, $ttl = self::TTL_MEDIUM, $group = 'listings') {
        $cache_key = $this->build_cache_key($key, $group);

        // Prepare cache data with metadata
        $cache_data = [
            'data' => $data,
            'created' => time(),
            'ttl' => $ttl,
            'group' => $group,
            'key' => $key,
            'size' => $this->estimate_size($data),
            'listing_ids' => $this->extract_listing_ids($data)
        ];

        // Set in memory cache
        $this->memory_cache[$cache_key] = $cache_data;

        // Set in WordPress transients
        $result = set_transient($cache_key, $cache_data, $ttl);

        if ($result) {
            self::$stats['sets']++;
            $this->log_cache_action('SET', $cache_key, $ttl);
            $this->track_cache_key($cache_key, $group);
            $this->track_listing_cache($cache_key, $cache_data['listing_ids']);
        }

        return $result;
    }

    /**
     * Delete cached data from all tiers
     *
     * @param string $key Cache key
     * @param string $group Cache group
     * @return bool True on success
     */
    public function delete($key, $group = 'listings') {
        $cache_key = $this->build_cache_key($key, $group);

        // Remove from memory cache
        unset($this->memory_cache[$cache_key]);

        // Remove from WordPress transients
        $result = delete_transient($cache_key);

        if ($result) {
            self::$stats['deletes']++;
            $this->log_cache_action('DELETE', $cache_key);
            $this->untrack_cache_key($cache_key, $group);
        }

        return $result;
    }

    /**
     * Cache single listing data
     *
     * @param string $listing_id Listing ID
     * @param array $listing_data Complete listing data
     * @param int $ttl Cache TTL
     * @return bool Success status
     */
    public function cache_listing($listing_id, $listing_data, $ttl = self::TTL_SHORT) {
        $key = 'single_' . $listing_id;
        return $this->set($key, $listing_data, $ttl, 'listings');
    }

    /**
     * Get cached single listing
     *
     * @param string $listing_id Listing ID
     * @return array|false Cached listing data or false
     */
    public function get_cached_listing($listing_id) {
        $key = 'single_' . $listing_id;
        return $this->get($key, 'listings');
    }

    /**
     * Cache multiple listings
     *
     * @param array $listings Array of listings
     * @param string $query_hash Unique hash for this query
     * @param int $ttl Cache TTL
     * @return bool Success status
     */
    public function cache_listings($listings, $query_hash, $ttl = self::TTL_SHORT) {
        $key = 'batch_' . $query_hash;
        return $this->set($key, $listings, $ttl, 'listings');
    }

    /**
     * Get cached multiple listings
     *
     * @param string $query_hash Query hash
     * @return array|false Cached listings or false
     */
    public function get_cached_listings($query_hash) {
        $key = 'batch_' . $query_hash;
        return $this->get($key, 'listings');
    }

    /**
     * Cache listing details
     *
     * @param string $listing_id Listing ID
     * @param array $details Listing details
     * @param int $ttl Cache TTL
     * @return bool Success status
     */
    public function cache_listing_details($listing_id, $details, $ttl = self::TTL_MEDIUM) {
        $key = 'details_' . $listing_id;
        return $this->set($key, $details, $ttl, 'details');
    }

    /**
     * Get cached listing details
     *
     * @param string $listing_id Listing ID
     * @return array|false Cached details or false
     */
    public function get_cached_listing_details($listing_id) {
        $key = 'details_' . $listing_id;
        return $this->get($key, 'details');
    }

    /**
     * Cache listing location data
     *
     * @param string $listing_id Listing ID
     * @param array $location Location data
     * @param int $ttl Cache TTL
     * @return bool Success status
     */
    public function cache_listing_location($listing_id, $location, $ttl = self::TTL_LONG) {
        $key = 'location_' . $listing_id;
        return $this->set($key, $location, $ttl, 'location');
    }

    /**
     * Get cached listing location
     *
     * @param string $listing_id Listing ID
     * @return array|false Cached location or false
     */
    public function get_cached_listing_location($listing_id) {
        $key = 'location_' . $listing_id;
        return $this->get($key, 'location');
    }

    /**
     * Cache listing financial data
     *
     * @param string $listing_id Listing ID
     * @param array $financial Financial data
     * @param int $ttl Cache TTL
     * @return bool Success status
     */
    public function cache_listing_financial($listing_id, $financial, $ttl = self::TTL_SHORT) {
        $key = 'financial_' . $listing_id;
        return $this->set($key, $financial, $ttl, 'financial');
    }

    /**
     * Get cached listing financial data
     *
     * @param string $listing_id Listing ID
     * @return array|false Cached financial data or false
     */
    public function get_cached_listing_financial($listing_id) {
        $key = 'financial_' . $listing_id;
        return $this->get($key, 'financial');
    }

    /**
     * Cache listing features
     *
     * @param string $listing_id Listing ID
     * @param array $features Features data
     * @param int $ttl Cache TTL
     * @return bool Success status
     */
    public function cache_listing_features($listing_id, $features, $ttl = self::TTL_LONG) {
        $key = 'features_' . $listing_id;
        return $this->set($key, $features, $ttl, 'features');
    }

    /**
     * Get cached listing features
     *
     * @param string $listing_id Listing ID
     * @return array|false Cached features or false
     */
    public function get_cached_listing_features($listing_id) {
        $key = 'features_' . $listing_id;
        return $this->get($key, 'features');
    }

    /**
     * Cache listing media
     *
     * @param string $listing_id Listing ID
     * @param array $media Media data
     * @param int $ttl Cache TTL
     * @return bool Success status
     */
    public function cache_listing_media($listing_id, $media, $ttl = self::TTL_EXTENDED) {
        $key = 'media_' . $listing_id;
        return $this->set($key, $media, $ttl, 'media');
    }

    /**
     * Get cached listing media
     *
     * @param string $listing_id Listing ID
     * @return array|false Cached media or false
     */
    public function get_cached_listing_media($listing_id) {
        $key = 'media_' . $listing_id;
        return $this->get($key, 'media');
    }

    /**
     * Cache agent data
     *
     * @param string $agent_id Agent ID
     * @param array $agent_data Agent data
     * @param int $ttl Cache TTL
     * @return bool Success status
     */
    public function cache_agent($agent_id, $agent_data, $ttl = self::TTL_EXTENDED) {
        $key = 'agent_' . $agent_id;
        return $this->set($key, $agent_data, $ttl, 'agents');
    }

    /**
     * Get cached agent data
     *
     * @param string $agent_id Agent ID
     * @return array|false Cached agent data or false
     */
    public function get_cached_agent($agent_id) {
        $key = 'agent_' . $agent_id;
        return $this->get($key, 'agents');
    }

    /**
     * Cache office data
     *
     * @param string $office_id Office ID
     * @param array $office_data Office data
     * @param int $ttl Cache TTL
     * @return bool Success status
     */
    public function cache_office($office_id, $office_data, $ttl = self::TTL_EXTENDED) {
        $key = 'office_' . $office_id;
        return $this->set($key, $office_data, $ttl, 'offices');
    }

    /**
     * Get cached office data
     *
     * @param string $office_id Office ID
     * @return array|false Cached office data or false
     */
    public function get_cached_office($office_id) {
        $key = 'office_' . $office_id;
        return $this->get($key, 'offices');
    }

    /**
     * Cache search results
     *
     * @param string $search_hash Search parameters hash
     * @param array $results Search results
     * @param int $ttl Cache TTL
     * @return bool Success status
     */
    public function cache_search_results($search_hash, $results, $ttl = self::TTL_SHORT) {
        $key = 'search_' . $search_hash;
        return $this->set($key, $results, $ttl, 'searches');
    }

    /**
     * Get cached search results
     *
     * @param string $search_hash Search parameters hash
     * @return array|false Cached results or false
     */
    public function get_cached_search_results($search_hash) {
        $key = 'search_' . $search_hash;
        return $this->get($key, 'searches');
    }

    /**
     * Cache statistics data
     *
     * @param string $stat_type Statistics type
     * @param array $stats_data Statistics data
     * @param int $ttl Cache TTL
     * @return bool Success status
     */
    public function cache_statistics($stat_type, $stats_data, $ttl = self::TTL_MEDIUM) {
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
        return $this->get($key, 'statistics');
    }

    /**
     * Invalidate cache for specific listings
     *
     * @param array $listing_ids Array of listing IDs
     * @return int Number of cache entries invalidated
     */
    public function invalidate_listings($listing_ids) {
        $invalidated = 0;

        foreach ($listing_ids as $listing_id) {
            // Invalidate all cache types for this listing
            $cache_types = ['single', 'details', 'location', 'financial', 'features', 'media'];

            foreach ($cache_types as $type) {
                $group = $type === 'single' ? 'listings' : $type;
                $key = $type . '_' . $listing_id;

                if ($this->delete($key, $group)) {
                    $invalidated++;
                }
            }
        }

        // Invalidate related batch caches
        $invalidated += $this->invalidate_listing_batches($listing_ids);

        // Invalidate search caches that might contain these listings
        $invalidated += $this->invalidate_group('searches');

        // Invalidate statistics that might be affected
        $invalidated += $this->invalidate_group('statistics');

        self::$stats['invalidations'] += $invalidated;
        $this->log_cache_action('INVALIDATE_LISTINGS', implode(',', $listing_ids), null, $invalidated);

        return $invalidated;
    }

    /**
     * Invalidate batch caches that contain specific listings
     *
     * @param array $listing_ids Array of listing IDs
     * @return int Number of batch caches invalidated
     */
    private function invalidate_listing_batches($listing_ids) {
        $invalidated = 0;
        $batch_tracking = get_option(self::CACHE_PREFIX . 'batch_tracking', []);

        foreach ($listing_ids as $listing_id) {
            if (isset($batch_tracking[$listing_id])) {
                foreach ($batch_tracking[$listing_id] as $cache_key) {
                    if (delete_transient($cache_key)) {
                        $invalidated++;
                        unset($this->memory_cache[$cache_key]);
                    }
                }
                unset($batch_tracking[$listing_id]);
            }
        }

        update_option(self::CACHE_PREFIX . 'batch_tracking', $batch_tracking);

        return $invalidated;
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
            // Remove from memory cache
            unset($this->memory_cache[$key]);

            // Remove from transients
            if (delete_transient($key)) {
                $deleted++;
            }
        }

        $this->clear_group_tracking($group);
        $this->log_cache_action('INVALIDATE_GROUP', $group, null, $deleted);

        return $deleted;
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
     * Extract listing IDs from data for tracking
     *
     * @param mixed $data Data to analyze
     * @return array Array of listing IDs
     */
    private function extract_listing_ids($data) {
        $listing_ids = [];

        if (is_array($data)) {
            // Check if it's a single listing
            if (isset($data['listing_id'])) {
                $listing_ids[] = $data['listing_id'];
            }
            // Check if it's multiple listings
            elseif (isset($data[0]['listing_id'])) {
                foreach ($data as $listing) {
                    if (isset($listing['listing_id'])) {
                        $listing_ids[] = $listing['listing_id'];
                    }
                }
            }
            // Look for listing_id in nested arrays
            else {
                array_walk_recursive($data, function($value, $key) use (&$listing_ids) {
                    if ($key === 'listing_id') {
                        $listing_ids[] = $value;
                    }
                });
            }
        }

        return array_unique($listing_ids);
    }

    /**
     * Track which listings are in which batch caches
     *
     * @param string $cache_key Cache key
     * @param array $listing_ids Listing IDs in this cache
     */
    private function track_listing_cache($cache_key, $listing_ids) {
        if (empty($listing_ids)) {
            return;
        }

        $batch_tracking = get_option(self::CACHE_PREFIX . 'batch_tracking', []);

        foreach ($listing_ids as $listing_id) {
            if (!isset($batch_tracking[$listing_id])) {
                $batch_tracking[$listing_id] = [];
            }
            $batch_tracking[$listing_id][] = $cache_key;
        }

        update_option(self::CACHE_PREFIX . 'batch_tracking', $batch_tracking);
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
            // Limit tracking to 2000 keys per group for performance
            if (count($keys) > 2000) {
                $keys = array_slice($keys, -2000);
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
        if (defined('WP_DEBUG') && WP_DEBUG && defined('BME_LOG_CACHE') && BME_LOG_CACHE) {
            $message = "BME Cache {$action}: {$key}";
            if ($ttl) {
                $message .= " (TTL: {$ttl}s)";
            }
            if ($count) {
                $message .= " (Count: {$count})";
            }
            error_log($message);
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
                'memory_keys' => array_keys(array_filter($this->memory_cache, function($item) use ($group) {
                    return $item['group'] === $group;
                }))
            ];
        }

        return $info;
    }

    /**
     * Flush all cache
     *
     * @return int Total number of keys cleared
     */
    public function flush_all() {
        $total_cleared = 0;

        // Clear memory cache
        $this->memory_cache = [];

        // Clear all groups
        foreach (self::CACHE_GROUPS as $group) {
            $total_cleared += $this->invalidate_group($group);
        }

        // Clear tracking
        delete_option(self::CACHE_PREFIX . 'batch_tracking');

        $this->log_cache_action('FLUSH_ALL', 'all_groups', null, $total_cleared);

        return $total_cleared;
    }

    /**
     * Get memory usage information
     *
     * @return array Memory usage details
     */
    public function get_memory_usage() {
        $usage = [
            'memory_cache_count' => count($this->memory_cache),
            'memory_cache_size' => 0,
            'groups' => []
        ];

        foreach ($this->memory_cache as $key => $data) {
            $group = $data['group'];
            if (!isset($usage['groups'][$group])) {
                $usage['groups'][$group] = [
                    'count' => 0,
                    'size' => 0
                ];
            }
            $usage['groups'][$group]['count']++;
            $usage['groups'][$group]['size'] += $data['size'];
            $usage['memory_cache_size'] += $data['size'];
        }

        $usage['memory_cache_size_kb'] = round($usage['memory_cache_size'] / 1024, 2);
        $usage['memory_cache_size_mb'] = round($usage['memory_cache_size'] / (1024 * 1024), 2);

        return $usage;
    }

    /**
     * Warm up cache with commonly accessed data
     *
     * @param array $listing_ids Listing IDs to warm up
     * @return array Results of cache warming
     */
    public function warm_cache($listing_ids = []) {
        $results = [];

        // This would be implemented by the main plugin to populate with actual data
        $results['listings_warmed'] = count($listing_ids);
        $results['ready_for_warming'] = true;

        return $results;
    }

    /**
     * Clean expired cache entries and optimize memory usage
     *
     * @return array Cleanup results
     */
    public function cleanup() {
        $results = [
            'memory_cleaned' => 0,
            'transients_cleaned' => 0,
            'tracking_cleaned' => 0
        ];

        // Clean memory cache of expired items
        $current_time = time();
        foreach ($this->memory_cache as $key => $data) {
            if (($data['created'] + $data['ttl']) < $current_time) {
                unset($this->memory_cache[$key]);
                $results['memory_cleaned']++;
            }
        }

        // Clean expired transients from tracking
        foreach (self::CACHE_GROUPS as $group) {
            $keys = $this->get_group_keys($group);
            $valid_keys = [];

            foreach ($keys as $cache_key) {
                $data = get_transient($cache_key);
                if (false !== $data) {
                    $valid_keys[] = $cache_key;
                } else {
                    $results['transients_cleaned']++;
                }
            }

            // Update tracking with only valid keys
            if (count($valid_keys) !== count($keys)) {
                $tracking_key = self::CACHE_PREFIX . 'tracking_' . $group;
                update_option($tracking_key, $valid_keys);
                $results['tracking_cleaned']++;
            }
        }

        return $results;
    }
}