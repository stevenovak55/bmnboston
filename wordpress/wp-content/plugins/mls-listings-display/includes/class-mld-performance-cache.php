<?php
/**
 * Performance Caching Layer for MLS Listings Display
 *
 * Provides WordPress object cache wrapper for expensive database queries.
 * Expected performance improvement: 40-60% on city pages with repeat visits.
 *
 * @package MLS_Listings_Display
 * @since 6.4.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Performance_Cache {

    /**
     * Cache group for all MLD performance caches
     */
    const CACHE_GROUP = 'mld_performance';

    /**
     * Cache expiration times (in seconds)
     */
    const CACHE_CITY_STATS = 3600;      // 1 hour - city market statistics
    const CACHE_NEARBY_CITIES = 21600;  // 6 hours - nearby cities list
    const CACHE_PROPERTY_TYPES = 3600;  // 1 hour - property type breakdown
    const CACHE_NEIGHBORHOODS = 3600;   // 1 hour - neighborhood data

    /**
     * Remember pattern: Get from cache or execute callback and cache result
     *
     * @param string $key Cache key
     * @param callable $callback Function to execute on cache miss
     * @param int $expiration Cache expiration in seconds
     * @return mixed Cached or fresh data
     */
    public static function remember($key, $callback, $expiration = 3600) {
        $cached = wp_cache_get($key, self::CACHE_GROUP);

        if (false !== $cached) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("[MLD Cache] HIT: {$key}");
            }
            return $cached;
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("[MLD Cache] MISS: {$key}");
        }

        $data = call_user_func($callback);

        if ($data !== false && $data !== null) {
            wp_cache_set($key, $data, self::CACHE_GROUP, $expiration);
        }

        return $data;
    }

    /**
     * Get city stats cache key
     *
     * @param string $city City name
     * @param string $state State abbreviation
     * @return string Cache key
     */
    public static function get_city_stats_key($city, $state) {
        $city_slug = sanitize_title($city);
        $state_slug = strtolower($state);
        return "city_stats_{$city_slug}_{$state_slug}";
    }

    /**
     * Get city listing count cache key
     *
     * @param string $city City name
     * @param string $state State abbreviation
     * @return string Cache key
     */
    public static function get_city_count_key($city, $state) {
        $city_slug = sanitize_title($city);
        $state_slug = strtolower($state);
        return "city_count_{$city_slug}_{$state_slug}";
    }

    /**
     * Get nearby cities cache key
     *
     * @param string $state State abbreviation
     * @return string Cache key
     */
    public static function get_nearby_cities_key($state) {
        $state_slug = strtolower($state);
        return "nearby_cities_{$state_slug}";
    }

    /**
     * Clear all cache for a specific city
     *
     * @param string $city City name
     * @param string $state State abbreviation
     */
    public static function clear_city_cache($city, $state) {
        $city_slug = sanitize_title($city);
        $state_slug = strtolower($state);

        wp_cache_delete("city_stats_{$city_slug}_{$state_slug}", self::CACHE_GROUP);
        wp_cache_delete("city_count_{$city_slug}_{$state_slug}", self::CACHE_GROUP);
        wp_cache_delete("nearby_cities_{$state_slug}", self::CACHE_GROUP);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("[MLD Cache] Cleared cache for {$city}, {$state}");
        }
    }

    /**
     * Clear all performance caches
     */
    public static function clear_all() {
        // WordPress doesn't provide a way to clear all keys in a group
        // So we'll need to track keys or use a versioning approach
        wp_cache_flush();

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("[MLD Cache] Flushed all caches");
        }
    }

    /**
     * Forget (delete) a specific cache key
     *
     * @param string $key Cache key
     */
    public static function forget($key) {
        wp_cache_delete($key, self::CACHE_GROUP);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("[MLD Cache] Forgot key: {$key}");
        }
    }

    /**
     * Check if caching is available
     *
     * @return bool True if external object cache is available
     */
    public static function is_available() {
        return wp_using_ext_object_cache();
    }

    /**
     * Get cache statistics (for debugging)
     *
     * @return array Cache stats
     */
    public static function get_stats() {
        return array(
            'external_cache' => wp_using_ext_object_cache() ? 'Yes' : 'No (WordPress transients)',
            'cache_group' => self::CACHE_GROUP,
            'expiration_times' => array(
                'city_stats' => self::CACHE_CITY_STATS . 's (1 hour)',
                'nearby_cities' => self::CACHE_NEARBY_CITIES . 's (6 hours)',
                'property_types' => self::CACHE_PROPERTY_TYPES . 's (1 hour)',
                'neighborhoods' => self::CACHE_NEIGHBORHOODS . 's (1 hour)',
            )
        );
    }
}
