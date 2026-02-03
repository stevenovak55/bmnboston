<?php
/**
 * MLS Cache Manager
 * 
 * Handles caching for saved search system
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
 * Cache Manager Class
 * 
 * Provides caching functionality for improved performance
 */
class MLD_Cache_Manager {
    
    /**
     * Cache group
     * 
     * @var string
     */
    const CACHE_GROUP = 'mld_saved_searches';
    
    /**
     * Default cache expiration (5 minutes)
     * 
     * @var int
     */
    const DEFAULT_EXPIRATION = 300;
    
    /**
     * Get cached data
     * 
     * @param string $key Cache key
     * @return mixed|false Cached data or false
     */
    public static function get($key) {
        return wp_cache_get($key, self::CACHE_GROUP);
    }
    
    /**
     * Set cached data
     * 
     * @param string $key Cache key
     * @param mixed $data Data to cache
     * @param int $expiration Expiration time in seconds
     * @return bool Success
     */
    public static function set($key, $data, $expiration = null) {
        if (null === $expiration) {
            $expiration = self::DEFAULT_EXPIRATION;
        }
        
        return wp_cache_set($key, $data, self::CACHE_GROUP, $expiration);
    }
    
    /**
     * Delete cached data
     * 
     * @param string $key Cache key
     * @return bool Success
     */
    public static function delete($key) {
        return wp_cache_delete($key, self::CACHE_GROUP);
    }
    
    /**
     * Flush entire cache group
     * 
     * @return bool Success
     */
    public static function flush_group() {
        return wp_cache_flush();
    }
    
    /**
     * Get or set cached data
     * 
     * @param string $key Cache key
     * @param callable $callback Callback to generate data
     * @param int $expiration Expiration time
     * @return mixed Cached or generated data
     */
    public static function remember($key, $callback, $expiration = null) {
        $cached = self::get($key);
        
        if (false !== $cached) {
            return $cached;
        }
        
        $data = call_user_func($callback);
        
        if (null !== $data) {
            self::set($key, $data, $expiration);
        }
        
        return $data;
    }
    
    /**
     * Get search results cache key
     * 
     * @param int $search_id Search ID
     * @param string $suffix Optional suffix
     * @return string Cache key
     */
    public static function get_search_key($search_id, $suffix = '') {
        $key = 'search_' . $search_id;
        
        if ($suffix) {
            $key .= '_' . $suffix;
        }
        
        return $key;
    }
    
    /**
     * Get user cache key
     * 
     * @param int $user_id User ID
     * @param string $suffix Optional suffix
     * @return string Cache key
     */
    public static function get_user_key($user_id, $suffix = '') {
        $key = 'user_' . $user_id;
        
        if ($suffix) {
            $key .= '_' . $suffix;
        }
        
        return $key;
    }
    
    /**
     * Get properties cache key
     * 
     * @param array $args Query arguments
     * @return string Cache key
     */
    public static function get_properties_key($args) {
        // Sort args for consistent key
        ksort($args);
        
        // Create hash of arguments
        $hash = md5(serialize($args));
        
        return 'properties_' . $hash;
    }
    
    /**
     * Clear search-related caches
     * 
     * @param int $search_id Search ID
     */
    public static function clear_search_cache($search_id) {
        // Clear search results
        self::delete(self::get_search_key($search_id, 'results'));
        
        // Clear search details
        self::delete(self::get_search_key($search_id, 'details'));
        
        // Clear notification history
        self::delete(self::get_search_key($search_id, 'notifications'));
    }
    
    /**
     * Clear user-related caches
     * 
     * @param int $user_id User ID
     */
    public static function clear_user_cache($user_id) {
        // Clear user searches
        self::delete(self::get_user_key($user_id, 'searches'));
        
        // Clear saved properties
        self::delete(self::get_user_key($user_id, 'saved_properties'));
        
        // Clear preferences
        self::delete(self::get_user_key($user_id, 'preferences'));
        
        // Clear statistics
        self::delete(self::get_user_key($user_id, 'stats'));
    }
    
    /**
     * Clear all notification caches
     */
    public static function clear_notification_cache() {
        // Clear due notifications for each frequency
        $frequencies = ['instant', 'hourly', 'daily', 'weekly'];
        
        foreach ($frequencies as $frequency) {
            self::delete('notifications_due_' . $frequency);
        }
    }
    
    /**
     * Cache search results
     * 
     * @param int $search_id Search ID
     * @param array $properties Properties array
     * @param int $expiration Optional expiration
     * @return bool Success
     */
    public static function cache_search_results($search_id, $properties, $expiration = null) {
        $key = self::get_search_key($search_id, 'results');
        return self::set($key, $properties, $expiration);
    }
    
    /**
     * Get cached search results
     * 
     * @param int $search_id Search ID
     * @return array|false Properties or false
     */
    public static function get_cached_search_results($search_id) {
        $key = self::get_search_key($search_id, 'results');
        return self::get($key);
    }
    
    /**
     * Cache user searches
     * 
     * @param int $user_id User ID
     * @param array $searches Searches array
     * @param int $expiration Optional expiration
     * @return bool Success
     */
    public static function cache_user_searches($user_id, $searches, $expiration = null) {
        $key = self::get_user_key($user_id, 'searches');
        return self::set($key, $searches, $expiration);
    }
    
    /**
     * Get cached user searches
     * 
     * @param int $user_id User ID
     * @return array|false Searches or false
     */
    public static function get_cached_user_searches($user_id) {
        $key = self::get_user_key($user_id, 'searches');
        return self::get($key);
    }
    
    /**
     * Setup cache warming
     */
    public static function setup_cache_warming() {
        // Schedule cache warming
        if (!wp_next_scheduled('mld_warm_search_cache')) {
            wp_schedule_event(time(), 'hourly', 'mld_warm_search_cache');
        }
        
        // Hook the warming function
        add_action('mld_warm_search_cache', [__CLASS__, 'warm_popular_searches']);
    }
    
    /**
     * Warm cache for popular searches
     */
    public static function warm_popular_searches() {
        global $wpdb;
        
        // Get most active searches
        $table = $wpdb->prefix . 'mld_saved_searches';
        
        $popular_searches = $wpdb->get_results($wpdb->prepare(
            "SELECT id FROM {$table}
             WHERE is_active = 1
             AND notification_frequency != 'never'
             ORDER BY last_matched_count DESC, last_notified_at DESC
             LIMIT %d",
            20
        ));
        
        if (empty($popular_searches)) {
            return;
        }
        
        $container = MLD_Service_Container::get_instance();
        $search_service = $container->search_service();
        
        foreach ($popular_searches as $search) {
            // Run search and cache results
            $properties = $search_service->run_search($search->id);
            
            if (!is_wp_error($properties)) {
                self::cache_search_results($search->id, $properties, 3600); // Cache for 1 hour
            }
        }
    }
}