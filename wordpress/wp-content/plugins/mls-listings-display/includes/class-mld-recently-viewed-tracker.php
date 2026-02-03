<?php
/**
 * MLD Recently Viewed Tracker
 *
 * Tracks property views from the web interface (templates).
 * Works in conjunction with the REST API for iOS tracking.
 *
 * @package MLS_Listings_Display
 * @since 6.57.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class MLD_Recently_Viewed_Tracker
 *
 * Handles web-based property view tracking.
 */
class MLD_Recently_Viewed_Tracker {

    /**
     * Singleton instance
     *
     * @var MLD_Recently_Viewed_Tracker
     */
    private static $instance = null;

    /**
     * Get singleton instance
     *
     * @return MLD_Recently_Viewed_Tracker
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor - private for singleton
     */
    private function __construct() {
        // Nothing to initialize
    }

    /**
     * Initialize the tracker
     * Called from main plugin file
     */
    public static function init() {
        // We'll use a custom action that templates will fire
        add_action('mld_property_viewed', array(self::get_instance(), 'record_view'), 10, 2);
    }

    /**
     * Record a property view from web template
     *
     * @param string $listing_id The MLS listing ID
     * @param array  $listing    Optional listing data (for listing_key lookup)
     * @return bool Success
     */
    public function record_view($listing_id, $listing = array()) {
        global $wpdb;

        // Validate listing_id
        if (empty($listing_id)) {
            return false;
        }

        // Get user ID (0 for anonymous visitors)
        $user_id = get_current_user_id();

        // Get IP address for anonymous visitors
        $ip_address = ($user_id === 0) ? self::get_client_ip() : null;

        // Get listing_key from provided data or look it up
        $listing_key = '';
        if (!empty($listing['listing_key'])) {
            $listing_key = sanitize_text_field($listing['listing_key']);
        } else {
            // Look up the listing_key from the summary table
            $listing_key = $wpdb->get_var($wpdb->prepare(
                "SELECT listing_key FROM {$wpdb->prefix}bme_listing_summary WHERE listing_id = %s LIMIT 1",
                $listing_id
            ));
        }

        // Fallback if no listing_key found
        if (empty($listing_key)) {
            $listing_key = 'web_' . md5($listing_id);
        }

        $table = $wpdb->prefix . 'mld_recently_viewed_properties';

        // Use current_time() for WordPress timezone (Rule 13)
        $now = current_time('mysql');

        // Insert or update (UNIQUE KEY on user_id, listing_id handles duplicates)
        // view_source = 'direct' for direct page view, platform = 'web'
        $result = $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table} (user_id, listing_id, listing_key, viewed_at, view_source, platform, ip_address)
             VALUES (%d, %s, %s, %s, 'direct', 'web', %s)
             ON DUPLICATE KEY UPDATE viewed_at = %s, view_source = 'direct', platform = 'web', ip_address = %s",
            $user_id,
            $listing_id,
            $listing_key,
            $now,
            $ip_address,
            $now,
            $ip_address
        ));

        return $result !== false;
    }

    /**
     * Get the client's IP address
     * Handles CDN/proxy forwarded IPs (Cloudflare, Kinsta, etc.)
     *
     * @return string|null IP address or null if not determinable
     */
    private static function get_client_ip() {
        // Check for CDN/proxy headers in order of reliability
        $headers = array(
            'HTTP_CF_CONNECTING_IP',  // Cloudflare
            'HTTP_X_REAL_IP',         // Nginx proxy
            'HTTP_X_FORWARDED_FOR',   // Standard proxy header
            'REMOTE_ADDR'             // Direct connection
        );

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];

                // X-Forwarded-For can contain multiple IPs, take the first (client)
                if ($header === 'HTTP_X_FORWARDED_FOR') {
                    $ips = explode(',', $ip);
                    $ip = trim($ips[0]);
                }

                // Validate IP format (supports both IPv4 and IPv6)
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return null;
    }

    /**
     * Get recently viewed properties for current user
     *
     * @param int $limit  Number of properties to return
     * @param int $days   Number of days to look back
     * @return array Array of listing IDs
     */
    public static function get_recent_for_user($limit = 10, $days = 7) {
        global $wpdb;

        $user_id = get_current_user_id();
        if (!$user_id) {
            return array();
        }

        $table = $wpdb->prefix . 'mld_recently_viewed_properties';

        // Use current_time() for WordPress timezone (Rule 13)
        $cutoff = wp_date('Y-m-d H:i:s', current_time('timestamp') - ($days * DAY_IN_SECONDS));

        $results = $wpdb->get_col($wpdb->prepare(
            "SELECT listing_id
             FROM {$table}
             WHERE user_id = %d
               AND viewed_at >= %s
             ORDER BY viewed_at DESC
             LIMIT %d",
            $user_id,
            $cutoff,
            $limit
        ));

        return $results ?: array();
    }
}
