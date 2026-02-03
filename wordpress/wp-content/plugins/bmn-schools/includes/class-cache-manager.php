<?php
/**
 * Cache Manager Class
 *
 * Handles caching for API responses and expensive queries.
 *
 * @package BMN_Schools
 * @since 0.4.0
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Cache Manager Class
 *
 * @since 0.4.0
 */
class BMN_Schools_Cache_Manager {

    /**
     * Cache group name.
     *
     * @var string
     */
    const CACHE_GROUP = 'bmn_schools';

    /**
     * Default cache duration in seconds (30 minutes).
     *
     * @var int
     */
    const DEFAULT_EXPIRATION = 1800;

    /**
     * Cache durations by type.
     *
     * @var array
     */
    private static $expirations = [
        'schools_list' => 1800,      // 30 minutes
        'school_detail' => 3600,     // 1 hour
        'districts_list' => 3600,    // 1 hour
        'district_detail' => 3600,   // 1 hour
        'district_boundary' => 86400, // 24 hours (boundaries rarely change)
        'district_lookup' => 86400,  // 24 hours
        'nearby_schools' => 900,     // 15 minutes
        'autocomplete' => 1800,      // 30 minutes
        'stats' => 3600,             // 1 hour
        'comparison' => 1800,        // 30 minutes
        'trends' => 3600,            // 1 hour
    ];

    /**
     * Check if caching is enabled.
     *
     * @since 0.4.0
     * @return bool True if caching is enabled.
     */
    public static function is_enabled() {
        $settings = get_option('bmn_schools_settings', []);
        return !empty($settings['enable_cache']);
    }

    /**
     * Get cached value.
     *
     * @since 0.4.0
     * @param string $key   Cache key.
     * @param string $type  Cache type for expiration lookup.
     * @return mixed|false Cached value or false if not found.
     */
    public static function get($key, $type = 'default') {
        if (!self::is_enabled()) {
            return false;
        }

        $cache_key = self::build_key($key);
        $value = wp_cache_get($cache_key, self::CACHE_GROUP);

        if ($value !== false) {
            // Log cache hit in debug mode
            if (defined('BMN_SCHOOLS_DEBUG') && BMN_SCHOOLS_DEBUG) {
                error_log("BMN Schools Cache HIT: {$cache_key}");
            }
        }

        return $value;
    }

    /**
     * Set cached value.
     *
     * @since 0.4.0
     * @param string $key   Cache key.
     * @param mixed  $value Value to cache.
     * @param string $type  Cache type for expiration lookup.
     * @return bool True on success.
     */
    public static function set($key, $value, $type = 'default') {
        if (!self::is_enabled()) {
            return false;
        }

        $cache_key = self::build_key($key);
        $expiration = self::get_expiration($type);

        if (defined('BMN_SCHOOLS_DEBUG') && BMN_SCHOOLS_DEBUG) {
            error_log("BMN Schools Cache SET: {$cache_key} (expires in {$expiration}s)");
        }

        return wp_cache_set($cache_key, $value, self::CACHE_GROUP, $expiration);
    }

    /**
     * Delete cached value.
     *
     * @since 0.4.0
     * @param string $key Cache key.
     * @return bool True on success.
     */
    public static function delete($key) {
        $cache_key = self::build_key($key);
        return wp_cache_delete($cache_key, self::CACHE_GROUP);
    }

    /**
     * Clear all plugin caches.
     *
     * @since 0.4.0
     * @return bool True on success.
     */
    public static function flush() {
        // WordPress doesn't support flushing a single group in all object cache implementations
        // So we'll use a version key approach
        $version = get_option('bmn_schools_cache_version', 1);
        update_option('bmn_schools_cache_version', $version + 1);

        // Also try to flush the group if supported
        if (function_exists('wp_cache_flush_group')) {
            wp_cache_flush_group(self::CACHE_GROUP);
        }

        return true;
    }

    /**
     * Build cache key with version.
     *
     * @since 0.4.0
     * @param string $key Base key.
     * @return string Full cache key.
     */
    private static function build_key($key) {
        $version = get_option('bmn_schools_cache_version', 1);
        return "v{$version}_{$key}";
    }

    /**
     * Get expiration time for cache type.
     *
     * @since 0.4.0
     * @param string $type Cache type.
     * @return int Expiration in seconds.
     */
    private static function get_expiration($type) {
        // Check for custom duration in settings
        $settings = get_option('bmn_schools_settings', []);
        if (!empty($settings['cache_duration'])) {
            return intval($settings['cache_duration']);
        }

        return isset(self::$expirations[$type]) ? self::$expirations[$type] : self::DEFAULT_EXPIRATION;
    }

    /**
     * Generate cache key from request parameters.
     *
     * @since 0.4.0
     * @param string $endpoint Endpoint name.
     * @param array  $params   Request parameters.
     * @return string Cache key.
     */
    public static function generate_key($endpoint, $params = []) {
        // Sort params for consistent key generation
        ksort($params);

        // Remove empty values
        $params = array_filter($params, function($v) {
            return $v !== null && $v !== '';
        });

        $param_string = !empty($params) ? '_' . md5(serialize($params)) : '';

        return $endpoint . $param_string;
    }

    /**
     * Cache wrapper for expensive operations.
     *
     * @since 0.4.0
     * @param string   $key      Cache key.
     * @param string   $type     Cache type.
     * @param callable $callback Function to call if cache miss.
     * @return mixed Cached or fresh value.
     */
    public static function remember($key, $type, $callback) {
        $cached = self::get($key, $type);

        if ($cached !== false) {
            return $cached;
        }

        $value = call_user_func($callback);

        if ($value !== null && $value !== false) {
            self::set($key, $value, $type);
        }

        return $value;
    }

    /**
     * Invalidate caches related to a school.
     *
     * Targets specific school caches instead of flushing all caches.
     *
     * @since 0.4.0
     * @since 0.6.36 Changed to targeted invalidation instead of full flush
     * @param int $school_id School ID.
     */
    public static function invalidate_school($school_id) {
        // Delete school-specific caches
        self::delete("school_{$school_id}");
        self::delete("school_detail_{$school_id}");
        self::delete("school_ranking_{$school_id}");
        self::delete("school_highlights_{$school_id}");

        // Delete nearby schools caches that might include this school
        // Note: These use location-based keys, so we invalidate by type version
        self::invalidate_cache_type('nearby_schools');

        // Delete list caches that might include this school
        self::invalidate_cache_type('schools_list');
    }

    /**
     * Invalidate caches related to a district.
     *
     * Targets specific district caches instead of flushing all caches.
     *
     * @since 0.4.0
     * @since 0.6.36 Changed to targeted invalidation instead of full flush
     * @param int $district_id District ID.
     */
    public static function invalidate_district($district_id) {
        // Delete district-specific caches
        self::delete("district_{$district_id}");
        self::delete("district_detail_{$district_id}");
        self::delete("district_boundary_{$district_id}");
        self::delete("district_ranking_{$district_id}");

        // Delete list caches that might include this district
        self::invalidate_cache_type('districts_list');
    }

    /**
     * Invalidate all caches of a specific type.
     *
     * Uses a type-specific version number to invalidate all caches of that type
     * without affecting other cache types.
     *
     * @since 0.6.36
     * @param string $type Cache type (e.g., 'nearby_schools', 'schools_list')
     */
    public static function invalidate_cache_type($type) {
        $option_key = "bmn_schools_cache_type_{$type}";
        $version = (int) get_option($option_key, 1);
        update_option($option_key, $version + 1, false); // false = no autoload
    }

    /**
     * Get the type version for cache key generation.
     *
     * @since 0.6.36
     * @param string $type Cache type.
     * @return int Type version number.
     */
    public static function get_type_version($type) {
        return (int) get_option("bmn_schools_cache_type_{$type}", 1);
    }

    /**
     * Get cache statistics.
     *
     * @since 0.4.0
     * @return array Cache stats.
     */
    public static function get_stats() {
        global $wp_object_cache;

        $stats = [
            'enabled' => self::is_enabled(),
            'version' => get_option('bmn_schools_cache_version', 1),
            'persistent' => wp_using_ext_object_cache(),
        ];

        // Try to get hit/miss stats if available
        if (is_object($wp_object_cache) && method_exists($wp_object_cache, 'stats')) {
            $stats['details'] = $wp_object_cache->stats();
        }

        return $stats;
    }
}
