<?php
/**
 * MLS Simple Notifications
 *
 * Simple, reliable notification system that checks for listing updates every 30 minutes
 * and sends clean email notifications to users with matching saved searches.
 *
 * @package MLS_Listings_Display
 * @subpackage Notifications
 * @since 5.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Simple_Notifications {

    /**
     * Singleton instance
     */
    private static $instance = null;

    /**
     * Notification tracker instance
     */
    private $tracker;

    /**
     * BuddyBoss notifier instance
     */
    private $buddyboss_notifier;

    /**
     * Cron hook name
     */
    const CRON_HOOK = 'mld_simple_notifications_check';

    /**
     * Get singleton instance
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
        $this->init();
    }

    /**
     * Initialize the notification system
     */
    private function init() {
        // Load dependencies
        $this->load_dependencies();

        // Set up cron
        $this->setup_cron();

        // Hook into WordPress
        add_action(self::CRON_HOOK, [$this, 'process_notifications']);
        add_action('init', [$this, 'maybe_schedule_cron']);

        // Admin hooks for testing
        if (is_admin()) {
            add_action('wp_ajax_mld_test_notifications', [$this, 'test_notifications']);
        }
    }

    /**
     * Load required classes
     */
    private function load_dependencies() {
        require_once plugin_dir_path(__FILE__) . 'class-mld-notification-tracker.php';

        // Only load BuddyBoss notifier if BuddyBoss is active
        if (function_exists('bp_is_active')) {
            // Try to use enhanced notifier first, fall back to standard if not available
            if (file_exists(plugin_dir_path(__FILE__) . 'class-mld-buddyboss-notifier-enhanced.php')) {
                require_once plugin_dir_path(__FILE__) . 'class-mld-buddyboss-notifier-enhanced.php';
                $this->buddyboss_notifier = MLD_BuddyBoss_Notifier_Enhanced::get_instance();
            } else {
                require_once plugin_dir_path(__FILE__) . 'class-mld-buddyboss-notifier.php';
                $this->buddyboss_notifier = MLD_BuddyBoss_Notifier::get_instance();
            }
        } else {
            $this->buddyboss_notifier = null;
        }

        $this->tracker = MLD_Notification_Tracker::get_instance();
    }

    /**
     * Set up cron schedule
     */
    private function setup_cron() {
        // Add custom 30-minute interval if it doesn't exist
        add_filter('cron_schedules', [$this, 'add_thirty_minute_interval']);
    }

    /**
     * Add 30-minute cron interval
     */
    public function add_thirty_minute_interval($schedules) {
        $schedules['thirty_minutes'] = [
            'interval' => 30 * MINUTE_IN_SECONDS,
            'display'  => __('Every 30 minutes', 'mls-listings-display')
        ];
        return $schedules;
    }

    /**
     * Schedule cron if not already scheduled
     */
    public function maybe_schedule_cron() {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time(), 'thirty_minutes', self::CRON_HOOK);
        }
    }

    /**
     * Main notification processing method
     * Called every 30 minutes by cron
     */
    public function process_notifications() {
        try {
            // Log start
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD Simple Notifications: Starting processing at ' . current_time('mysql'));
            }

            // Get all active saved searches
            $saved_searches = $this->get_active_saved_searches();

            if (empty($saved_searches)) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('MLD Simple Notifications: No active saved searches found');
                }
                return;
            }

            // Get listings that have been updated in the last 30 minutes
            $recent_listings = $this->get_recent_listings();

            if (empty($recent_listings)) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('MLD Simple Notifications: No recent listings found');
                }
                return;
            }

            // Process each saved search
            foreach ($saved_searches as $search) {
                $this->process_saved_search($search, $recent_listings);
            }

            // Clean up old tracking records
            $this->tracker->cleanup_old_records();

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD Simple Notifications: Processing completed successfully');
            }

        } catch (Exception $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD Simple Notifications Error: ' . $e->getMessage());
            }
        }
    }

    /**
     * Get all active saved searches that have notifications enabled
     */
    private function get_active_saved_searches() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'mld_saved_searches';

        // Check which columns exist for compatibility
        $columns = $wpdb->get_col("SHOW COLUMNS FROM {$table_name}");

        // Use the correct column names based on what exists
        if (in_array('is_active', $columns)) {
            // New column structure
            return $wpdb->get_results("
                SELECT * FROM {$table_name}
                WHERE is_active = 1
                AND (notification_frequency IS NOT NULL AND notification_frequency != '')
                ORDER BY user_id, id
            ");
        } else {
            // Old column structure fallback
            return $wpdb->get_results($wpdb->prepare("
                SELECT * FROM {$table_name}
                WHERE notifications_enabled = %d
                AND status = %s
                ORDER BY user_id, id
            ", 1, 'active'));
        }
    }

    /**
     * Get listings that have been created or updated in the last 30 minutes
     */
    private function get_recent_listings() {
        global $wpdb;

        // Try different table names for compatibility
        $table_names = [
            $wpdb->prefix . 'bme_listings',
            $wpdb->prefix . 'extractor_listings'
        ];

        $table_name = null;
        foreach ($table_names as $table) {
            if ($wpdb->get_var("SHOW TABLES LIKE '$table'") === $table) {
                $table_name = $table;
                break;
            }
        }

        if (!$table_name) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD Simple Notifications: No listings table found');
            }
            return [];
        }

        // Temporarily extended to 2 hours for testing - normally 30 minutes
        // Use WordPress timezone-aware date calculation
        $thirty_minutes_ago = wp_date('Y-m-d H:i:s', current_time('timestamp') - (2 * HOUR_IN_SECONDS));

        // Check column names for compatibility
        $columns = $wpdb->get_col("SHOW COLUMNS FROM {$table_name}");

        // Build query based on available columns
        if (in_array('modification_timestamp', $columns)) {
            // BME listings table structure
            // Build query based on available status column
            if (in_array('mls_status', $columns)) {
                $query = $wpdb->prepare("
                    SELECT * FROM {$table_name}
                    WHERE modification_timestamp > %s
                    AND mls_status = 'Active'
                    ORDER BY modification_timestamp DESC
                    LIMIT 100
                ", $thirty_minutes_ago);
            } else {
                // No status filter if column doesn't exist
                $query = $wpdb->prepare("
                    SELECT * FROM {$table_name}
                    WHERE modification_timestamp > %s
                    ORDER BY modification_timestamp DESC
                    LIMIT 100
                ", $thirty_minutes_ago);
            }
            return $wpdb->get_results($query);
        } else {
            // Old structure with created_at/updated_at
            return $wpdb->get_results($wpdb->prepare("
                SELECT * FROM {$table_name}
                WHERE (
                    created_at > %s
                    OR updated_at > %s
                    OR last_import_date > %s
                )
                AND status = %s
                ORDER BY created_at DESC, updated_at DESC
            ", $thirty_minutes_ago, $thirty_minutes_ago, $thirty_minutes_ago, 'active'));
        }
    }

    /**
     * Process a single saved search against recent listings
     */
    private function process_saved_search($search, $recent_listings) {
        try {
            // Find matching listings for this search
            $matching_listings = $this->find_matching_listings($search, $recent_listings);

            if (empty($matching_listings)) {
                return;
            }

            // Filter out listings we've already sent notifications for
            $new_listings = $this->filter_unsent_listings($search->user_id, $matching_listings);

            if (empty($new_listings)) {
                return;
            }

            // Send email notification
            $this->send_email_notification($search, $new_listings);

            // Send BuddyBoss notification if enabled
            if ($this->buddyboss_notifier && function_exists('bp_is_active')) {
                $this->buddyboss_notifier->send_notification($search, $new_listings);
            }

            // Track that we've sent notifications for these listings
            $this->tracker->mark_listings_sent($search->user_id, $new_listings);

        } catch (Exception $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD Simple Notifications: Error processing search ID ' . $search->id . ': ' . $e->getMessage());
            }
        }
    }

    /**
     * Find listings that match the saved search criteria
     */
    private function find_matching_listings($search, $listings) {
        // Handle different column names for criteria/filters
        $criteria_json = isset($search->filters) ? $search->filters :
                        (isset($search->search_criteria) ? $search->search_criteria : null);

        $criteria = json_decode($criteria_json, true);
        if (!is_array($criteria)) {
            return [];
        }

        $matching = [];

        foreach ($listings as $listing) {
            if ($this->listing_matches_criteria($listing, $criteria)) {
                $matching[] = $listing;
            }
        }

        return $matching;
    }

    /**
     * Check if a listing matches the search criteria
     */
    private function listing_matches_criteria($listing, $criteria) {
        // Price range
        if (!empty($criteria['min_price'])) {
            if ($listing->list_price < $criteria['min_price']) {
                return false;
            }
        }

        if (!empty($criteria['max_price'])) {
            if ($listing->list_price > $criteria['max_price']) {
                return false;
            }
        }

        // Bedrooms
        if (!empty($criteria['min_bedrooms'])) {
            if ($listing->bedrooms_total < $criteria['min_bedrooms']) {
                return false;
            }
        }

        if (!empty($criteria['max_bedrooms'])) {
            if ($listing->bedrooms_total > $criteria['max_bedrooms']) {
                return false;
            }
        }

        // Bathrooms
        if (!empty($criteria['min_bathrooms'])) {
            if ($listing->bathrooms_total < $criteria['min_bathrooms']) {
                return false;
            }
        }

        if (!empty($criteria['max_bathrooms'])) {
            if ($listing->bathrooms_total > $criteria['max_bathrooms']) {
                return false;
            }
        }

        // Square footage
        if (!empty($criteria['min_sqft'])) {
            $sqft = isset($listing->living_area) ? $listing->living_area :
                   (isset($listing->square_footage) ? $listing->square_footage : 0);
            if ($sqft < $criteria['min_sqft']) {
                return false;
            }
        }

        if (!empty($criteria['max_sqft'])) {
            $sqft = isset($listing->living_area) ? $listing->living_area :
                   (isset($listing->square_footage) ? $listing->square_footage : 0);
            if ($sqft > $criteria['max_sqft']) {
                return false;
            }
        }

        // Year built
        if (!empty($criteria['min_year_built'])) {
            $year_built = isset($listing->year_built) ? $listing->year_built : 0;
            if ($year_built < $criteria['min_year_built']) {
                return false;
            }
        }

        if (!empty($criteria['max_year_built'])) {
            $year_built = isset($listing->year_built) ? $listing->year_built : 0;
            if ($year_built > $criteria['max_year_built']) {
                return false;
            }
        }

        // Property type
        if (!empty($criteria['property_type'])) {
            // Handle both string and array property types
            $property_types = is_array($criteria['property_type']) ? $criteria['property_type'] : [$criteria['property_type']];
            $listing_type = strtolower($listing->property_type);

            $type_matched = false;
            foreach ($property_types as $type) {
                if (strtolower($type) === $listing_type) {
                    $type_matched = true;
                    break;
                }
            }

            if (!$type_matched) {
                return false;
            }
        }

        // Property subtype
        if (!empty($criteria['property_subtype'])) {
            $listing_subtype = isset($listing->property_subtype) ? strtolower($listing->property_subtype) : '';
            if (strtolower($criteria['property_subtype']) !== $listing_subtype) {
                return false;
            }
        }

        // City - handle both single city and multiple cities (selected_cities)
        if (!empty($criteria['selected_cities'])) {
            // Handle multiple cities
            $selected_cities = is_array($criteria['selected_cities']) ? $criteria['selected_cities'] : explode(',', $criteria['selected_cities']);
            $listing_city = trim($listing->city);

            // Check if listing city matches any of the selected cities
            $city_matched = false;
            foreach ($selected_cities as $city) {
                $city = trim($city);
                if (strcasecmp($listing_city, $city) === 0) {
                    $city_matched = true;
                    break;
                }
            }

            if (!$city_matched) {
                return false;
            }
        } elseif (!empty($criteria['city'])) {
            // Fallback to single city check (exact match, not substring)
            if (strcasecmp(trim($listing->city), trim($criteria['city'])) !== 0) {
                return false;
            }
        }

        // Neighborhoods
        if (!empty($criteria['selected_neighborhoods'])) {
            $neighborhoods = is_array($criteria['selected_neighborhoods']) ? $criteria['selected_neighborhoods'] : [$criteria['selected_neighborhoods']];
            $listing_neighborhood = isset($listing->neighborhood) ? trim($listing->neighborhood) : '';

            if ($listing_neighborhood) {
                $neighborhood_matched = false;
                foreach ($neighborhoods as $neighborhood) {
                    if (strcasecmp(trim($neighborhood), $listing_neighborhood) === 0) {
                        $neighborhood_matched = true;
                        break;
                    }
                }

                if (!$neighborhood_matched) {
                    return false;
                }
            }
        }

        // State
        if (!empty($criteria['state'])) {
            if (strtolower($listing->state_or_province) !== strtolower($criteria['state'])) {
                return false;
            }
        }

        // Zip code
        if (!empty($criteria['zip_code'])) {
            if (strpos($listing->postal_code, $criteria['zip_code']) === false) {
                return false;
            }
        }

        // Listing status (Active, Pending, etc.)
        if (!empty($criteria['listing_status'])) {
            $listing_mls_status = isset($listing->mls_status) ? $listing->mls_status : (isset($listing->status) ? $listing->status : 'Active');
            if (strcasecmp($listing_mls_status, $criteria['listing_status']) !== 0) {
                return false;
            }
        }

        // Keywords (MLS number, street name, etc.)
        if (!empty($criteria['keyword_mls_number'])) {
            if (is_array($criteria['keyword_mls_number'])) {
                $mls_match = false;
                foreach ($criteria['keyword_mls_number'] as $mls) {
                    if (strcasecmp($listing->listing_id, trim($mls)) === 0) {
                        $mls_match = true;
                        break;
                    }
                }
                if (!$mls_match) {
                    return false;
                }
            }
        }

        // Polygon/boundary search
        if (!empty($criteria['polygon_shapes'])) {
            // Check if listing coordinates fall within polygon
            $lat = isset($listing->latitude) ? $listing->latitude : null;
            $lng = isset($listing->longitude) ? $listing->longitude : null;

            if ($lat && $lng && !$this->point_in_polygons($lat, $lng, $criteria['polygon_shapes'])) {
                return false;
            }
        }

        // Additional filters
        if (!empty($criteria['has_garage']) && $criteria['has_garage']) {
            $garage_spaces = isset($listing->garage_spaces) ? $listing->garage_spaces : 0;
            if ($garage_spaces <= 0) {
                return false;
            }
        }

        if (!empty($criteria['has_pool']) && $criteria['has_pool']) {
            $has_pool = isset($listing->pool) ? $listing->pool : false;
            if (!$has_pool) {
                return false;
            }
        }

        if (!empty($criteria['waterfront']) && $criteria['waterfront']) {
            $waterfront = isset($listing->waterfront) ? $listing->waterfront : false;
            if (!$waterfront) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if a point falls within any of the polygons
     */
    private function point_in_polygons($lat, $lng, $polygons) {
        foreach ($polygons as $polygon) {
            if ($this->point_in_polygon($lat, $lng, $polygon)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if a point is inside a polygon using ray casting algorithm
     */
    private function point_in_polygon($lat, $lng, $polygon) {
        $inside = false;
        $n = count($polygon);

        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            $xi = $polygon[$i]['lat'];
            $yi = $polygon[$i]['lng'];
            $xj = $polygon[$j]['lat'];
            $yj = $polygon[$j]['lng'];

            $intersect = (($yi > $lng) !== ($yj > $lng))
                && ($lat < ($xj - $xi) * ($lng - $yi) / ($yj - $yi) + $xi);

            if ($intersect) {
                $inside = !$inside;
            }
        }

        return $inside;
    }

    /**
     * Filter out listings we've already sent notifications for
     */
    private function filter_unsent_listings($user_id, $listings) {
        $unsent = [];

        foreach ($listings as $listing) {
            // Use listing_id as the identifier (MLS number)
            $mls_id = isset($listing->listing_id) ? $listing->listing_id :
                     (isset($listing->mls_number) ? $listing->mls_number : null);

            if ($mls_id && !$this->tracker->was_notification_sent($user_id, $mls_id)) {
                $unsent[] = $listing;
            }
        }

        return $unsent;
    }

    /**
     * Send email notification to user
     */
    private function send_email_notification($search, $listings) {
        $user = get_user_by('id', $search->user_id);
        if (!$user) {
            return false;
        }

        // Load premium email template with modern design
        $template_path = plugin_dir_path(dirname(__FILE__)) . '../templates/emails/listing-updates-premium.php';

        // Fall back to enhanced template if premium doesn't exist
        if (!file_exists($template_path)) {
            $template_path = plugin_dir_path(dirname(__FILE__)) . '../templates/emails/listing-updates-enhanced.php';
        }

        // Fall back to original template if enhanced doesn't exist
        if (!file_exists($template_path)) {
            $template_path = plugin_dir_path(dirname(__FILE__)) . '../templates/emails/listing-updates.php';
        }

        if (!file_exists($template_path)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD Simple Notifications: Email template not found at ' . $template_path);
            }
            return false;
        }

        // Prepare template variables
        $template_vars = [
            'user' => $user,
            'search' => $search,
            'listings' => $listings,
            'search_name' => isset($search->name) ? $search->name : (isset($search->search_name) ? $search->search_name : 'Your Saved Search'),
            'listing_count' => count($listings),
            'site_name' => get_bloginfo('name'),
            'site_url' => home_url(),
            'unsubscribe_url' => $this->get_unsubscribe_url($search)
        ];

        // Generate email content
        ob_start();
        extract($template_vars);
        include $template_path;
        $email_content = ob_get_clean();

        // Email headers with dynamic from address (v6.63.0)
        // Clients with assigned agent get email from agent; others from MLD settings
        if (class_exists('MLD_Email_Utilities')) {
            $headers = MLD_Email_Utilities::get_email_headers($search->user_id);
        } else {
            $headers = [
                'Content-Type: text/html; charset=UTF-8',
                'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>'
            ];
        }

        // Subject - Updated format
        $count = count($listings);
        $search_name = isset($search->name) ? $search->name : (isset($search->search_name) ? $search->search_name : 'Your Saved Search');
        $subject = sprintf(
            _n(
                '%d New Listing Matching your "%s" Saved Search',
                '%d New Listings Matching your "%s" Saved Search',
                $count,
                'mls-listings-display'
            ),
            $count,
            $search_name
        );

        // Send email
        $sent = wp_mail($user->user_email, $subject, $email_content, $headers);

        if ($sent) {
            $search_name_log = isset($search->name) ? $search->name : (isset($search->search_name) ? $search->search_name : 'Unknown');
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD Simple Notifications: Email sent to ' . $user->user_email . ' for search "' . $search_name_log . '"');
            }
        } else {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD Simple Notifications: Failed to send email to ' . $user->user_email);
            }
        }

        return $sent;
    }

    /**
     * Get unsubscribe URL for a saved search
     */
    private function get_unsubscribe_url($search) {
        return add_query_arg([
            'mld_action' => 'unsubscribe',
            'search_id' => $search->id,
            'token' => wp_create_nonce('mld_unsubscribe_' . $search->id)
        ], home_url());
    }

    /**
     * Test notifications (for admin use)
     */
    public function test_notifications() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $this->process_notifications();

        wp_send_json_success('Test notification processing completed. Check error logs for details.');
    }

    /**
     * Unschedule cron on deactivation
     */
    public static function deactivate() {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }
}

// DISABLED v6.13.14: Auto-initialization disabled to prevent duplicate emails
// This legacy system has been replaced by MLD_Fifteen_Minute_Processor
// MLD_Simple_Notifications::get_instance();