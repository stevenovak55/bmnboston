<?php
/**
 * MLD Public Analytics Tracker
 *
 * Enqueues the lightweight JavaScript tracker for all visitors
 * and processes incoming tracking events.
 *
 * @package MLS_Listings_Display
 * @subpackage Analytics
 * @since 6.39.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class MLD_Public_Analytics_Tracker
 *
 * Handles frontend tracking script enqueuing and event processing.
 */
class MLD_Public_Analytics_Tracker {

    /**
     * Singleton instance
     *
     * @var MLD_Public_Analytics_Tracker
     */
    private static $instance = null;

    /**
     * Database instance
     *
     * @var MLD_Public_Analytics_Database
     */
    private $db;

    /**
     * Device detector instance
     *
     * @var MLD_Public_Device_Detector
     */
    private $device_detector;

    /**
     * Geolocation service instance
     *
     * @var MLD_Geolocation_Service
     */
    private $geolocation;

    /**
     * Get singleton instance
     *
     * @return MLD_Public_Analytics_Tracker
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->db = MLD_Public_Analytics_Database::get_instance();
        $this->device_detector = MLD_Public_Device_Detector::get_instance();
        $this->geolocation = MLD_Geolocation_Service::get_instance();
    }

    /**
     * Initialize the tracker
     */
    public static function init() {
        $instance = self::get_instance();

        // Enqueue tracker script on frontend
        add_action('wp_enqueue_scripts', array($instance, 'enqueue_tracker'), 5);

        // Add inline config
        add_action('wp_footer', array($instance, 'output_tracker_config'), 1);
    }

    /**
     * Enqueue the tracker JavaScript
     */
    public function enqueue_tracker() {
        // Don't track admin users unless explicitly enabled
        if (is_admin()) {
            return;
        }

        // Check if tracking is enabled
        if (!$this->is_tracking_enabled()) {
            return;
        }

        // Skip for bots detected server-side
        if ($this->device_detector->is_bot(strtolower($_SERVER['HTTP_USER_AGENT'] ?? ''))) {
            return;
        }

        wp_enqueue_script(
            'mld-public-tracker',
            MLD_PLUGIN_URL . 'assets/js/mld-public-tracker.js',
            array(), // No dependencies - standalone
            MLD_VERSION,
            true // Load in footer
        );
    }

    /**
     * Output tracker configuration in footer
     */
    public function output_tracker_config() {
        if (!wp_script_is('mld-public-tracker', 'enqueued')) {
            return;
        }

        $config = $this->get_tracker_config();
        ?>
        <script type="text/javascript">
        window.mldTrackerConfig = <?php echo wp_json_encode($config); ?>;
        </script>
        <?php
    }

    /**
     * Get tracker configuration
     *
     * @return array Configuration for JavaScript tracker
     */
    private function get_tracker_config() {
        global $post;

        // Determine page type
        $page_type = $this->detect_page_type();

        // Get property data if on property page (v6.45.5 fix)
        $property_data = null;
        if ($page_type === 'property_detail') {
            // Try global first (for backwards compatibility)
            if (isset($GLOBALS['mld_current_listing'])) {
                $listing = $GLOBALS['mld_current_listing'];
            } else {
                // Extract listing ID from URL and fetch data
                $listing = $this->get_listing_from_url();
            }

            if ($listing) {
                $property_data = array(
                    'listing_id'  => $listing->listing_id ?? null,
                    'listing_key' => $listing->listing_key ?? null,
                    'city'        => $listing->city ?? null,
                    'price'       => (int) ($listing->list_price ?? 0),
                    'beds'        => (int) ($listing->bedrooms_total ?? 0),
                    'baths'       => (float) ($listing->bathrooms_total ?? 0),
                );
            }
        }

        return array(
            'endpoint'       => rest_url('mld-analytics/v1/track'),
            'heartbeat_url'  => rest_url('mld-analytics/v1/heartbeat'),
            'nonce'          => wp_create_nonce('mld_analytics_track'),
            'user_id'        => get_current_user_id() ?: null,
            'page_type'      => $page_type,
            'page_url'       => $this->get_current_url(),
            'page_path'      => wp_parse_url($this->get_current_url(), PHP_URL_PATH) ?: '/',
            'page_title'     => wp_get_document_title(),
            'property'       => $property_data,
            'session_timeout' => 30, // Minutes
            'flush_interval' => 30,  // Seconds
            'heartbeat_interval' => 60, // Seconds
            'debug'          => defined('WP_DEBUG') && WP_DEBUG,
        );
    }

    /**
     * Detect current page type
     *
     * @return string Page type identifier
     */
    private function detect_page_type() {
        global $post;

        // Property detail page
        if (is_singular() && get_query_var('property_id')) {
            return 'property_detail';
        }

        // Check for property page by URL pattern (handles both simple /property/123/ and SEO-friendly /property/slug-123/)
        $path = wp_parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        if (preg_match('#^/property/[^/]+/?$#', $path)) {
            return 'property_detail';
        }

        // Search/map page
        if ($path === '/search/' || $path === '/search') {
            return 'search';
        }

        // Home page
        if (is_front_page() || is_home()) {
            return 'home';
        }

        // School pages
        if (strpos($path, '/schools/') === 0) {
            return 'school';
        }

        // City pages
        if (preg_match('#^/[a-z\-]+/$#', $path) && !is_page()) {
            return 'city';
        }

        // Blog post
        if (is_single()) {
            return 'blog_post';
        }

        // Regular page
        if (is_page()) {
            return 'page';
        }

        // Archive
        if (is_archive()) {
            return 'archive';
        }

        return 'other';
    }

    /**
     * Get current page URL
     *
     * @return string Current URL
     */
    private function get_current_url() {
        $protocol = is_ssl() ? 'https://' : 'http://';
        return $protocol . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '/');
    }

    /**
     * Get listing data from current URL (v6.45.5)
     *
     * Extracts listing ID from property page URL and fetches basic listing data.
     * Handles both formats: /property/73278288/ and /property/slug-73278288/
     *
     * @return object|null Listing data or null if not found
     */
    private function get_listing_from_url() {
        $path = wp_parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);

        // Extract listing ID from URL - handles /property/73278288/ or /property/slug-73278288/
        if (!preg_match('#^/property/(?:.*-)?(\d+)/?$#', $path, $matches)) {
            return null;
        }

        $listing_id = $matches[1];
        if (empty($listing_id)) {
            return null;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'bme_listing_summary';

        // Fetch minimal listing data for analytics
        $listing = $wpdb->get_row($wpdb->prepare(
            "SELECT listing_id, listing_key, city, list_price, bedrooms_total, bathrooms_total
             FROM {$table}
             WHERE listing_id = %s
             LIMIT 1",
            $listing_id
        ));

        return $listing;
    }

    /**
     * Check if tracking is enabled
     *
     * @return bool
     */
    private function is_tracking_enabled() {
        // Check option (can be disabled via settings)
        $enabled = get_option('mld_public_analytics_enabled', true);

        // Allow filtering
        return apply_filters('mld_public_analytics_enabled', $enabled);
    }

    /**
     * Process a batch of tracking events
     *
     * @param string $session_id Session ID from client
     * @param array $events Array of events
     * @param array $session_data Session metadata
     * @return array Processing result
     */
    public function process_events($session_id, $events, $session_data = array()) {
        if (empty($session_id) || empty($events)) {
            return array(
                'success' => false,
                'error'   => 'Invalid request',
            );
        }

        // Get device and geo data (server-side for accuracy)
        $device_info = $this->device_detector->get_device_info();
        $geo_data = $this->geolocation->get_location();

        // Skip bot traffic
        if ($device_info['is_bot']) {
            return array(
                'success' => true,
                'message' => 'Bot traffic ignored',
                'tracked' => 0,
            );
        }

        // Prepare session data
        $session = array_merge(array(
            'session_id'      => $session_id,
            'visitor_hash'    => $session_data['visitor_hash'] ?? null,
            'user_id'         => get_current_user_id() ?: ($session_data['user_id'] ?? null),
            'platform'        => $device_info['platform'],
            'ip_address'      => $this->geolocation->get_client_ip(),
            'country_code'    => $geo_data['country_code'],
            'country_name'    => $geo_data['country_name'],
            'region'          => $geo_data['region'],
            'city'            => $geo_data['city'],
            'latitude'        => $geo_data['latitude'],
            'longitude'       => $geo_data['longitude'],
            'referrer_url'    => $session_data['referrer'] ?? null,
            'referrer_domain' => $this->extract_domain($session_data['referrer'] ?? ''),
            'utm_source'      => $session_data['utm_source'] ?? null,
            'utm_medium'      => $session_data['utm_medium'] ?? null,
            'utm_campaign'    => $session_data['utm_campaign'] ?? null,
            'utm_term'        => $session_data['utm_term'] ?? null,
            'utm_content'     => $session_data['utm_content'] ?? null,
            'device_type'     => $device_info['device_type'],
            'browser'         => $device_info['browser'],
            'browser_version' => $device_info['browser_version'],
            'os'              => $device_info['os'],
            'os_version'      => $device_info['os_version'],
            'screen_width'    => $session_data['screen_width'] ?? null,
            'screen_height'   => $session_data['screen_height'] ?? null,
            'is_bot'          => 0,
        ), $this->count_event_types($events));

        // Upsert session
        $this->db->upsert_session($session);

        // Insert events
        $tracked = 0;
        foreach ($events as $event) {
            $event_data = array(
                'session_id'           => $session_id,
                'event_type'           => $event['type'] ?? 'unknown',
                'event_category'       => $event['category'] ?? $this->categorize_event($event['type'] ?? ''),
                'platform'             => $device_info['platform'],
                'page_url'             => $event['page_url'] ?? null,
                'page_path'            => $event['page_path'] ?? null,
                'page_title'           => $event['page_title'] ?? null,
                'page_type'            => $event['page_type'] ?? null,
                'listing_id'           => $event['listing_id'] ?? null,
                'listing_key'          => $event['listing_key'] ?? null,
                'property_city'        => $event['property_city'] ?? null,
                'property_price'       => $event['property_price'] ?? null,
                'property_beds'        => $event['property_beds'] ?? null,
                'property_baths'       => $event['property_baths'] ?? null,
                'search_query'         => isset($event['search_query']) ? wp_json_encode($event['search_query']) : null,
                'search_results_count' => $event['search_results_count'] ?? null,
                'click_target'         => $event['click_target'] ?? null,
                'click_element'        => $event['click_element'] ?? null,
                'scroll_depth'         => $event['scroll_depth'] ?? null,
                'time_on_page'         => $event['time_on_page'] ?? null,
                'event_data'           => $event['data'] ?? null,
                'event_timestamp'      => $event['timestamp'] ?? current_time('mysql'),
            );

            if ($this->db->insert_event($event_data)) {
                $tracked++;
            }
        }

        return array(
            'success' => true,
            'tracked' => $tracked,
        );
    }

    /**
     * Process heartbeat (presence update)
     *
     * @param string $session_id Session ID
     * @param array $data Presence data
     * @return array Result
     */
    public function process_heartbeat($session_id, $data = array()) {
        if (empty($session_id)) {
            return array('success' => false, 'error' => 'Invalid session');
        }

        $device_info = $this->device_detector->get_device_info();
        $geo_data = $this->geolocation->get_location();

        // Update presence
        $presence_data = array(
            'session_id'         => $session_id,
            'user_id'            => get_current_user_id() ?: null,
            'platform'           => $device_info['platform'],
            'current_page'       => $data['page_url'] ?? null,
            'current_page_type'  => $data['page_type'] ?? null,
            'current_listing_id' => $data['listing_id'] ?? null,
            'device_type'        => $device_info['device_type'],
            'country_code'       => $geo_data['country_code'],
            'city'               => $geo_data['city'],
        );

        $this->db->update_presence($presence_data);

        // Also update session last_seen
        $this->db->upsert_session(array(
            'session_id' => $session_id,
            'platform'   => $device_info['platform'],
        ));

        return array(
            'success'        => true,
            'active_visitors' => $this->db->get_active_visitors_count(),
        );
    }

    /**
     * Count event types in batch
     *
     * @param array $events Events array
     * @return array Counts for session update
     */
    private function count_event_types($events) {
        $counts = array(
            'page_views'     => 0,
            'property_views' => 0,
            'searches'       => 0,
        );

        foreach ($events as $event) {
            $type = $event['type'] ?? '';
            switch ($type) {
                case 'page_view':
                    $counts['page_views']++;
                    break;
                case 'property_view':
                    $counts['property_views']++;
                    break;
                case 'search':
                case 'search_execute':
                    $counts['searches']++;
                    break;
            }
        }

        return $counts;
    }

    /**
     * Categorize event type
     *
     * @param string $event_type Event type
     * @return string Category
     */
    private function categorize_event($event_type) {
        $categories = array(
            'page_view'       => 'navigation',
            'property_view'   => 'navigation',
            'search'          => 'engagement',
            'search_execute'  => 'engagement',
            'filter_apply'    => 'engagement',
            'map_zoom'        => 'engagement',
            'map_pan'         => 'engagement',
            'map_draw'        => 'engagement',
            'marker_click'    => 'engagement',
            'photo_view'      => 'engagement',
            'scroll_depth'    => 'engagement',
            'time_on_page'    => 'engagement',
            'contact_click'   => 'conversion',
            'contact_submit'  => 'conversion',
            'favorite_add'    => 'conversion',
            'share_click'     => 'conversion',
            'schedule_click'  => 'conversion',
            'external_click'  => 'outbound',
        );

        return $categories[$event_type] ?? 'other';
    }

    /**
     * Extract domain from URL
     *
     * @param string $url URL
     * @return string|null Domain
     */
    private function extract_domain($url) {
        if (empty($url)) {
            return null;
        }

        $host = wp_parse_url($url, PHP_URL_HOST);
        if (!$host) {
            return null;
        }

        // Remove www prefix
        return preg_replace('/^www\./', '', $host);
    }
}
