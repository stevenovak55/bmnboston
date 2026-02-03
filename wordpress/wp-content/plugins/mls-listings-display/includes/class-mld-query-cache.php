<?php
/**
 * MLD Query Cache Handler
 *
 * Enhanced performance caching system with automatic invalidation
 * Enable/disable via wp-config.php or admin settings
 *
 * @package MLS_Listings_Display
 * @since 3.2.0
 * @updated 4.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Query_Cache {

    /**
     * Cache enabled flag
     */
    private static $enabled = null;

    /**
     * Cache duration in seconds (default 5 minutes)
     */
    private static $cache_duration = 300;

    /**
     * Cache statistics
     */
    private static $stats = [
        'hits' => 0,
        'misses' => 0,
        'writes' => 0
    ];

    /**
     * Check if cache is enabled
     *
     * Cache is disabled on Kinsta and other managed hosts with persistent
     * object cache (Redis/Memcached) to prevent stale data issues.
     *
     * @since 6.13.19 Added Kinsta/managed host detection
     */
    public static function is_enabled() {
        if (self::$enabled === null) {
            // First check if explicitly disabled (highest priority)
            if (defined('MLD_DISABLE_QUERY_CACHE') && MLD_DISABLE_QUERY_CACHE === true) {
                self::$enabled = false;
                return self::$enabled;
            }

            // Detect Kinsta hosting - disable cache due to Redis persistence issues
            if (self::is_kinsta_hosting()) {
                self::$enabled = false;
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('[MLD Cache] Disabled on Kinsta - Redis object cache causes stale data');
                }
                return self::$enabled;
            }

            // Detect persistent object cache (Redis/Memcached) - can cause stale data
            if (self::has_persistent_object_cache()) {
                // Allow override via constant
                if (!defined('MLD_ALLOW_CACHE_WITH_OBJECT_CACHE') || MLD_ALLOW_CACHE_WITH_OBJECT_CACHE !== true) {
                    self::$enabled = false;
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log('[MLD Cache] Disabled - persistent object cache detected');
                    }
                    return self::$enabled;
                }
            }

            // Check if explicitly enabled via constant
            if (defined('MLD_ENABLE_QUERY_CACHE')) {
                self::$enabled = MLD_ENABLE_QUERY_CACHE === true;
            } else {
                // Check admin setting
                $options = get_option('mld_performance_settings', []);
                self::$enabled = !empty($options['enable_query_cache']);
            }
        }
        return self::$enabled;
    }

    /**
     * Detect if running on Kinsta hosting
     *
     * @since 6.13.19
     * @return bool
     */
    private static function is_kinsta_hosting() {
        // Kinsta sets this constant
        if (defined('KINSTAMU_VERSION')) {
            return true;
        }

        // Check for Kinsta MU plugin
        if (defined('KINSTA_CACHE_ZONE')) {
            return true;
        }

        // Check for Kinsta-specific paths
        if (strpos(ABSPATH, '/www/') !== false && file_exists('/etc/kinsta')) {
            return true;
        }

        // Check for Kinsta cache headers in server vars
        if (isset($_SERVER['HTTP_X_KINSTA_CACHE'])) {
            return true;
        }

        return false;
    }

    /**
     * Detect if a persistent object cache is in use (Redis, Memcached)
     *
     * @since 6.13.19
     * @return bool
     */
    private static function has_persistent_object_cache() {
        // Check if wp_using_ext_object_cache is available and returns true
        if (function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache()) {
            return true;
        }

        // Check for Redis
        if (defined('WP_REDIS_DISABLED') && WP_REDIS_DISABLED === false) {
            return true;
        }
        if (class_exists('Redis') && defined('WP_REDIS_HOST')) {
            return true;
        }

        // Check for Memcached
        if (class_exists('Memcached') && defined('WP_CACHE') && WP_CACHE) {
            return true;
        }

        return false;
    }
    
    /**
     * Get cached query result
     *
     * @param string $cache_key Unique cache key
     * @return mixed|false Cached data or false if not found/expired
     */
    public static function get($cache_key) {
        if (!self::is_enabled()) {
            return false;
        }

        MLD_Performance_Monitor::startTimer('cache_lookup', ['key' => substr($cache_key, 0, 50)]);

        // Use WordPress transient API for caching
        $cached = get_transient('mld_query_' . md5($cache_key));

        if ($cached !== false) {
            self::$stats['hits']++;
            // Log cache hit for debugging
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD Cache HIT: ' . substr($cache_key, 0, 50));
            }
            MLD_Performance_Monitor::recordMetric('cache_hit', 1, 'counter');
        } else {
            self::$stats['misses']++;
            MLD_Performance_Monitor::recordMetric('cache_miss', 1, 'counter');
        }

        MLD_Performance_Monitor::endTimer('cache_lookup');

        return $cached;
    }
    
    /**
     * Set cached query result
     *
     * @param string $cache_key Unique cache key
     * @param mixed $data Data to cache
     * @param int $duration Optional cache duration in seconds
     * @return bool Success
     */
    public static function set($cache_key, $data, $duration = null) {
        if (!self::is_enabled()) {
            return false;
        }

        if ($duration === null) {
            $duration = self::$cache_duration;
        }

        MLD_Performance_Monitor::startTimer('cache_write', ['key' => substr($cache_key, 0, 50)]);

        // Use WordPress transient API for caching
        $result = set_transient('mld_query_' . md5($cache_key), $data, $duration);

        if ($result) {
            self::$stats['writes']++;
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD Cache SET: ' . substr($cache_key, 0, 50));
            }
            MLD_Performance_Monitor::recordMetric('cache_write', 1, 'counter');
        }

        MLD_Performance_Monitor::endTimer('cache_write');

        return $result;
    }
    
    /**
     * Clear specific cache entry
     * 
     * @param string $cache_key Cache key to clear
     * @return bool Success
     */
    public static function delete($cache_key) {
        if (!self::is_enabled()) {
            return false;
        }
        
        return delete_transient('mld_query_' . md5($cache_key));
    }
    
    /**
     * Clear all MLD query cache
     *
     * @return int Number of cache entries cleared
     */
    public static function flush_all() {
        global $wpdb;

        MLD_Performance_Monitor::startTimer('cache_flush');

        if (!self::is_enabled()) {
            MLD_Performance_Monitor::endTimer('cache_flush');
            return 0;
        }
        
        // Delete all MLD query transients
        $sql = "DELETE FROM {$wpdb->options} 
                WHERE option_name LIKE '_transient_mld_query_%' 
                OR option_name LIKE '_transient_timeout_mld_query_%'";
        
        $deleted = $wpdb->query($sql);
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Cache FLUSH: Cleared ' . ($deleted / 2) . ' cache entries');
        }
        
        return $deleted / 2; // Each transient has 2 rows (value and timeout)
    }
    
    /**
     * Generate cache key from query parameters
     * 
     * @param string $query_type Type of query (listings, featured, details, etc.)
     * @param array $params Query parameters
     * @return string Cache key
     */
    public static function generate_key($query_type, $params = []) {
        // Sort params to ensure consistent keys
        ksort($params);
        
        // Create unique key based on query type and parameters
        $key = $query_type . '_' . serialize($params);
        
        // Add user context if needed (for saved properties, etc.)
        if (is_user_logged_in()) {
            $key .= '_user_' . get_current_user_id();
        }
        
        return $key;
    }
    
    /**
     * Wrap a query function with caching
     * 
     * @param callable $callback Query function to wrap
     * @param string $cache_key Cache key
     * @param int $duration Cache duration
     * @return mixed Query result
     */
    public static function remember($callback, $cache_key, $duration = null) {
        // If cache is disabled, just run the callback
        if (!self::is_enabled()) {
            return call_user_func($callback);
        }
        
        // Try to get from cache
        $cached = self::get($cache_key);
        if ($cached !== false) {
            return $cached;
        }
        
        // Run the query
        $result = call_user_func($callback);
        
        // Cache the result
        if ($result !== false && $result !== null) {
            self::set($cache_key, $result, $duration);
        }
        
        return $result;
    }

    /**
     * Get cache statistics
     *
     * @return array Cache statistics
     */
    public static function getStats() {
        return self::$stats;
    }

    /**
     * Reset cache statistics
     */
    public static function resetStats() {
        self::$stats = [
            'hits' => 0,
            'misses' => 0,
            'writes' => 0
        ];
    }

    /**
     * Get cache hit ratio
     *
     * @return float Hit ratio percentage
     */
    public static function getHitRatio() {
        $total = self::$stats['hits'] + self::$stats['misses'];
        if ($total === 0) {
            return 0;
        }
        return (self::$stats['hits'] / $total) * 100;
    }

    /**
     * Set cache duration
     *
     * @param int $seconds Cache duration in seconds
     */
    public static function setCacheDuration($seconds) {
        self::$cache_duration = max(60, min(3600, $seconds)); // Between 1 minute and 1 hour
    }

    /**
     * Get current cache configuration
     *
     * @return array Configuration details
     */
    public static function getConfig() {
        return [
            'enabled' => self::is_enabled(),
            'duration' => self::$cache_duration,
            'stats' => self::getStats(),
            'hit_ratio' => self::getHitRatio()
        ];
    }
}

// Hook to clear cache when listings are updated
add_action('bme_listing_updated', function() {
    MLD_Query_Cache::flush_all();
});

add_action('bme_extraction_completed', function() {
    MLD_Query_Cache::flush_all();
});

// Add admin bar menu for cache management (only if enabled and user is admin)
add_action('admin_bar_menu', function($wp_admin_bar) {
    if (!MLD_Query_Cache::is_enabled() || !current_user_can('manage_options')) {
        return;
    }
    
    $wp_admin_bar->add_node([
        'id' => 'mld-cache',
        'title' => 'MLD Cache',
        'href' => '#',
        'meta' => ['class' => 'mld-cache-menu']
    ]);
    
    $wp_admin_bar->add_node([
        'id' => 'mld-cache-clear',
        'parent' => 'mld-cache',
        'title' => 'Clear Query Cache',
        'href' => admin_url('admin-post.php?action=mld_clear_cache&_wpnonce=' . wp_create_nonce('mld_clear_cache'))
    ]);
}, 100);

// Handle cache clear action
add_action('admin_post_mld_clear_cache', function() {
    if (!current_user_can('manage_options') || !wp_verify_nonce($_GET['_wpnonce'], 'mld_clear_cache')) {
        wp_die('Unauthorized');
    }
    
    $cleared = MLD_Query_Cache::flush_all();
    
    wp_redirect(add_query_arg('mld_cache_cleared', $cleared, wp_get_referer()));
    exit;
});

// Show admin notice after cache clear
add_action('admin_notices', function() {
    if (isset($_GET['mld_cache_cleared'])) {
        $count = intval($_GET['mld_cache_cleared']);
        ?>
        <div class="notice notice-success is-dismissible">
            <p><?php printf(__('MLD Query Cache cleared: %d entries removed.', 'mld'), $count); ?></p>
        </div>
        <?php
    }
});