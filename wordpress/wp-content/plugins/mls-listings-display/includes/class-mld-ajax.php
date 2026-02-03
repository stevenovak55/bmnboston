<?php
/**
 * Handles AJAX requests for the MLS Listings Display plugin.
 * Version 4.0
 * 
 * Version 4.0 Changes:
 * - Added zoom level parameter to get_map_listings_callback
 * - Removed get_all_listings_for_cache_callback endpoint (background cache removed)
 * - Improved request handling for viewport-based loading
 * 
 * @package MLS_Listings_Display
 */
class MLD_Ajax {

    /**
     * Initialize AJAX handlers.
     * 
     * Registers all AJAX actions for both authenticated and non-authenticated users.
     * Handles map listings, filters, autocomplete, and property data requests.
     */
    public function __construct() {
        add_action( 'wp_ajax_get_map_listings', [ $this, 'get_map_listings_callback' ] );
        add_action( 'wp_ajax_nopriv_get_map_listings', [ $this, 'get_map_listings_callback' ] );

        add_action( 'wp_ajax_get_autocomplete_suggestions', [ $this, 'get_autocomplete_suggestions_callback' ] );
        add_action( 'wp_ajax_nopriv_get_autocomplete_suggestions', [ $this, 'get_autocomplete_suggestions_callback' ] );

        add_action( 'wp_ajax_check_city_exists', [ $this, 'check_city_exists_callback' ] );
        add_action( 'wp_ajax_nopriv_check_city_exists', [ $this, 'check_city_exists_callback' ] );

        add_action( 'wp_ajax_get_agent_suggestions', [ $this, 'get_agent_suggestions_callback' ] );
        add_action( 'wp_ajax_nopriv_get_agent_suggestions', [ $this, 'get_agent_suggestions_callback' ] );

        add_action( 'wp_ajax_get_filter_options', [ $this, 'get_filter_options_callback' ] );
        add_action( 'wp_ajax_nopriv_get_filter_options', [ $this, 'get_filter_options_callback' ] );

        add_action( 'wp_ajax_get_price_distribution', [ $this, 'get_price_distribution_callback' ] );
        add_action( 'wp_ajax_nopriv_get_price_distribution', [ $this, 'get_price_distribution_callback' ] );

        add_action( 'wp_ajax_get_filtered_count', [ $this, 'get_filtered_count_callback' ] );
        add_action( 'wp_ajax_nopriv_get_filtered_count', [ $this, 'get_filtered_count_callback' ] );

        add_action( 'wp_ajax_get_listing_details', [ $this, 'get_listing_details_callback' ] );
        add_action( 'wp_ajax_nopriv_get_listing_details', [ $this, 'get_listing_details_callback' ] );

        add_action( 'wp_ajax_get_all_listings_for_cache', [ $this, 'get_all_listings_for_cache_callback' ] );
        add_action( 'wp_ajax_nopriv_get_all_listings_for_cache', [ $this, 'get_all_listings_for_cache_callback' ] );
        
        add_action( 'wp_ajax_get_walk_score', [ $this, 'get_walk_score_callback' ] );
        add_action( 'wp_ajax_nopriv_get_walk_score', [ $this, 'get_walk_score_callback' ] );
        
        
        // Contact form handlers
        add_action( 'wp_ajax_mld_contact_agent', [ $this, 'handle_contact_form' ] );
        add_action( 'wp_ajax_nopriv_mld_contact_agent', [ $this, 'handle_contact_form' ] );
        
        add_action( 'wp_ajax_mld_schedule_tour', [ $this, 'handle_tour_form' ] );
        add_action( 'wp_ajax_nopriv_mld_schedule_tour', [ $this, 'handle_tour_form' ] );
        
        // Save/favorite property handlers
        add_action( 'wp_ajax_mld_save_property', [ $this, 'handle_save_property' ] );
        add_action( 'wp_ajax_nopriv_mld_save_property', [ $this, 'handle_save_property' ] );

        // Hide property handler (v6.31.9 - logged-in users only)
        add_action( 'wp_ajax_mld_hide_property', [ $this, 'handle_hide_property' ] );
        
        
        // Similar homes handler
        add_action( 'wp_ajax_get_similar_homes', [ $this, 'get_similar_homes_callback' ] );
        add_action( 'wp_ajax_nopriv_get_similar_homes', [ $this, 'get_similar_homes_callback' ] );
        
        // Open house calendar handler
        add_action( 'wp_ajax_mld_track_calendar_add', [ $this, 'handle_calendar_tracking' ] );
        add_action( 'wp_ajax_nopriv_mld_track_calendar_add', [ $this, 'handle_calendar_tracking' ] );
        
        // JavaScript error logging
        add_action( 'wp_ajax_mld_log_js_error', [ $this, 'handle_js_error_logging' ] );
        add_action( 'wp_ajax_nopriv_mld_log_js_error', [ $this, 'handle_js_error_logging' ] );

        // Debug query analysis
        add_action( 'wp_ajax_mld_analyze_query', [ $this, 'analyze_query_callback' ] );

        // Property preferences for saved searches
        add_action( 'wp_ajax_mld_load_property_preferences', [ $this, 'load_property_preferences_callback' ] );
        add_action( 'wp_ajax_nopriv_mld_load_property_preferences', [ $this, 'load_property_preferences_callback' ] );

        // Listing cards infinite scroll
        add_action( 'wp_ajax_mld_load_more_cards', [ $this, 'load_more_cards_callback' ] );
        add_action( 'wp_ajax_nopriv_mld_load_more_cards', [ $this, 'load_more_cards_callback' ] );

        // Admin preview for shortcode generator
        add_action( 'wp_ajax_mld_preview_listing_cards', [ $this, 'preview_listing_cards_callback' ] );

        // Universal contact form handlers (v6.21.0)
        add_action( 'wp_ajax_mld_submit_contact_form', [ $this, 'handle_universal_contact_form' ] );
        add_action( 'wp_ajax_nopriv_mld_submit_contact_form', [ $this, 'handle_universal_contact_form' ] );

        // Admin AJAX for contact form builder
        add_action( 'wp_ajax_mld_save_contact_form', [ $this, 'save_contact_form_callback' ] );
        add_action( 'wp_ajax_mld_get_contact_form', [ $this, 'get_contact_form_callback' ] );

    }

    /**
     * Validate and sanitize filter array from AJAX input
     * 
     * @param mixed $filters Raw filter input
     * @return array|null Sanitized filters or null if invalid
     */
    private function validate_and_sanitize_filters($filters) {
        if (!is_array($filters)) {
            return null;
        }
        
        $sanitized = [];
        $allowed_keys = [
            'min_price', 'max_price', 'min_sqft', 'max_sqft',
            'min_bedrooms', 'max_bedrooms', 'min_bathrooms', 'max_bathrooms',
            'property_type', 'property_subtype', 'city', 'state',
            'status', 'sort_by', 'sort_order', 'polygon',
            'garage_spaces', 'year_built_min', 'year_built_max',
            'lot_size_min', 'lot_size_max', 'days_on_market',
            'open_house', 'virtual_tour', 'waterfront', 'pool'
        ];
        
        foreach ($filters as $key => $value) {
            if (!in_array($key, $allowed_keys)) {
                continue; // Skip unknown keys
            }
            
            // Sanitize based on key type
            switch ($key) {
                case 'min_price':
                case 'max_price':
                case 'min_sqft':
                case 'max_sqft':
                case 'min_bedrooms':
                case 'max_bedrooms':
                case 'min_bathrooms':
                case 'max_bathrooms':
                case 'garage_spaces':
                case 'year_built_min':
                case 'year_built_max':
                case 'lot_size_min':
                case 'lot_size_max':
                case 'days_on_market':
                    $sanitized[$key] = absint($value);
                    break;
                    
                case 'property_type':
                case 'property_subtype':
                case 'status':
                    if (is_array($value)) {
                        $sanitized[$key] = array_map('sanitize_text_field', $value);
                    } else {
                        $sanitized[$key] = sanitize_text_field($value);
                    }
                    break;
                    
                case 'city':
                case 'state':
                case 'sort_by':
                case 'sort_order':
                    $sanitized[$key] = sanitize_text_field($value);
                    break;
                    
                case 'polygon':
                    if (is_array($value)) {
                        $sanitized[$key] = array_map(function($point) {
                            if (is_array($point) && isset($point['lat']) && isset($point['lng'])) {
                                return [
                                    'lat' => floatval($point['lat']),
                                    'lng' => floatval($point['lng'])
                                ];
                            }
                            return null;
                        }, $value);
                        $sanitized[$key] = array_filter($sanitized[$key]); // Remove null values
                    }
                    break;
                    
                case 'open_house':
                case 'virtual_tour':
                case 'waterfront':
                case 'pool':
                    $sanitized[$key] = filter_var($value, FILTER_VALIDATE_BOOLEAN);
                    break;

            }
        }
        
        return !empty($sanitized) ? $sanitized : null;
    }
    
    /**
     * Validate numeric range parameters
     * 
     * @param mixed $value Raw value
     * @param int $min Minimum allowed value
     * @param int $max Maximum allowed value
     * @return int|null Validated integer or null
     */
    private function validate_numeric_range($value, $min = 0, $max = PHP_INT_MAX) {
        $num = absint($value);
        if ($num < $min || $num > $max) {
            return null;
        }
        return $num;
    }

    /**
     * Set no-cache headers for AJAX responses.
     * Prevents Kinsta and other hosts from caching dynamic listing data.
     *
     * This is critical for real-time MLS data where cached responses would
     * show stale listings to users.
     *
     * @since 6.13.18
     */
    private function set_nocache_headers() {
        // Use WordPress's built-in nocache headers for broad host compatibility
        $headers = wp_get_nocache_headers();
        foreach ($headers as $header => $value) {
            if (!headers_sent()) {
                header("{$header}: {$value}");
            }
        }

        // Additional headers for aggressive cache bypass (Kinsta/Cloudflare)
        if (!headers_sent()) {
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('Expires: Wed, 11 Jan 1984 05:00:00 GMT');
            header('X-Accel-Expires: 0'); // Nginx proxy cache bypass
        }
    }

    /**
     * Get diagnostic information about the summary table
     *
     * Returns counts and status that help diagnose why listings might not appear.
     * This data is included in AJAX responses for console logging.
     *
     * @since 6.13.20
     * @return array Diagnostic information
     */
    private function get_summary_table_diagnostics() {
        global $wpdb;

        $summary_table = $wpdb->prefix . 'bme_listing_summary';
        $listings_table = $wpdb->prefix . 'bme_listings';

        // Get counts
        $summary_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$summary_table}");
        $summary_active = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$summary_table} WHERE standard_status = 'Active'");
        $active_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$listings_table} WHERE standard_status = 'Active'");

        // Check if stored procedure exists
        $proc_exists = (bool) $wpdb->get_var(
            "SELECT COUNT(*) FROM information_schema.ROUTINES
             WHERE ROUTINE_SCHEMA = DATABASE()
             AND ROUTINE_NAME = 'populate_listing_summary'"
        );

        // Detect hosting environment
        $is_kinsta = defined('KINSTAMU_VERSION') || defined('KINSTA_CACHE_ZONE');
        $has_redis = function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache();

        // Calculate sync status
        $sync_diff = abs($active_count - $summary_active);
        $sync_percent = $active_count > 0 ? round(($summary_active / $active_count) * 100, 1) : 0;

        return array(
            'summary_count' => $summary_count,
            'summary_active' => $summary_active,
            'active_count' => $active_count,
            'sync_percent' => $sync_percent,
            'sync_diff' => $sync_diff,
            'stored_proc_exists' => $proc_exists,
            'is_kinsta' => $is_kinsta,
            'has_object_cache' => $has_redis,
            'server_time' => current_time('mysql'),
            'table_empty' => ($summary_count === 0),
            'out_of_sync' => ($sync_percent < 90)
        );
    }

    /**
     * Handle AJAX request for listing cache data.
     * 
     * Fetches paginated listings for frontend cache with proper security validation.
     * Includes performance logging and comprehensive error handling.
     * 
     * @since 3.0.0
     * @return void Outputs JSON response
     */
    public function get_all_listings_for_cache_callback() {
        $this->set_nocache_headers();
        $start_time = microtime(true);
        
        // Verify nonce for security
        if (!check_ajax_referer('bme_map_nonce', 'security', false)) {
            MLD_Logger::warning('AJAX security check failed', [
                'action' => 'get_all_listings_for_cache',
                'user_id' => get_current_user_id(),
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ]);
            wp_send_json_error('Security check failed', 403);
            return;
        }

        // Sanitize and validate input
        $raw_filters = isset($_POST['filters']) ? json_decode(wp_unslash($_POST['filters']), true) : null;
        $filters = $this->validate_and_sanitize_filters($raw_filters);
        if ($raw_filters !== null && $filters === null) {
            if (class_exists('MLD_Logger')) {
                MLD_Logger::warning('Invalid filters provided', ['filters' => $_POST['filters']]);
            }
        }
        
        $page = isset($_POST['page']) ? absint($_POST['page']) : 1;
        $limit = isset($_POST['limit']) ? absint($_POST['limit']) : 50; // Optimized default limit
        
        // Validate limits with optimized defaults
        if ($page < 1) $page = 1;
        if ($limit < 1) $limit = 50;
        if ($limit > 200) $limit = 200; // Maximum limit to prevent memory issues

        try {
            $result = MLD_Query::get_all_listings_for_cache($filters, $page, $limit);
            
            $response_time = microtime(true) - $start_time;
            MLD_Logger::ajax('get_all_listings_for_cache', [
                'page' => $page,
                'limit' => $limit,
                'result_count' => $result['total'] ?? 0
            ], $response_time);
            
            wp_send_json_success($result);
            
        } catch (Exception $e) {
            $response_time = microtime(true) - $start_time;
            MLD_Logger::error('Failed to fetch listings for cache', [
                'error' => $e->getMessage(),
                'filters' => $filters,
                'page' => $page,
                'limit' => $limit,
                'response_time_ms' => round($response_time * 1000, 2)
            ]);
            
            wp_send_json_error('Unable to load property listings. Please try again.', 500);
        }
    }

    public function get_price_distribution_callback() {
        $this->set_nocache_headers();
        check_ajax_referer( 'bme_map_nonce', 'security' );
        $filters = isset( $_POST['filters'] ) ? json_decode( wp_unslash( $_POST['filters'] ), true ) : [];
        if ( ! is_array( $filters ) ) {
            $filters = [];
        }
        try {
            $distribution = MLD_Query::get_price_distribution( $filters );
            wp_send_json_success( $distribution );
        } catch ( Exception $e ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[MLD AJAX] get_price_distribution error: ' . $e->getMessage() );
            }
            wp_send_json_error( 'An error occurred while fetching price distribution. Please try again.' );
        }
    }

    public function get_listing_details_callback() {
        $this->set_nocache_headers();
        check_ajax_referer( 'bme_map_nonce', 'security' );
        $listing_id = isset( $_POST['listing_id'] ) ? sanitize_text_field( wp_unslash( $_POST['listing_id'] ) ) : '';
        if ( empty( $listing_id ) ) {
            wp_send_json_error( 'No Listing ID provided.' );
        }
        try {
            $details = MLD_Query::get_listing_details( $listing_id );
            if ( $details ) {
                wp_send_json_success( $details );
            } else {
                wp_send_json_error( 'Listing not found.' );
            }
        } catch ( Exception $e ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[MLD AJAX] get_listing_details error: ' . $e->getMessage() );
            }
            wp_send_json_error( 'An error occurred while fetching listing details. Please try again.' );
        }
    }

    public function get_filtered_count_callback() {
        $this->set_nocache_headers();
        check_ajax_referer( 'bme_map_nonce', 'security' );
        $filters = isset( $_POST['filters'] ) ? json_decode( wp_unslash( $_POST['filters'] ), true ) : null;
        if ( ! is_array( $filters ) ) {
            $filters = null;
        }

        // Debug: Log school filter values (v6.30.3)
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $school_filters = array_filter($filters ?? [], function($key) {
                return strpos($key, 'near_a') === 0 || strpos($key, 'near_ab') === 0;
            }, ARRAY_FILTER_USE_KEY);
            if (!empty($school_filters)) {
                error_log('[MLD Filter Count] School filters received: ' . json_encode($school_filters));
            }
        }

        try {
            $count = MLD_Query::get_listings_for_map( null, null, null, null, $filters, true, true, false );
            wp_send_json_success( $count );
        } catch ( Exception $e ) {
            wp_send_json_error( 'An error occurred while fetching count. Please try again.' );
        }
    }

    public function get_filter_options_callback() {
        $this->set_nocache_headers();
        check_ajax_referer( 'bme_map_nonce', 'security' );
        $filters = isset($_POST['filters']) ? json_decode(wp_unslash($_POST['filters']), true) : [];
        if ( ! is_array( $filters ) ) {
            $filters = [];
        }
        
        try {
            $options = MLD_Query::get_distinct_filter_options($filters);
            wp_send_json_success( $options );
        } catch ( Exception $e ) {
            wp_send_json_error( 'An error occurred while fetching filter options. Please try again.' );
        }
    }

    public function get_map_listings_callback() {
        $this->set_nocache_headers();
        check_ajax_referer( 'bme_map_nonce', 'security' );

        $north = isset( $_POST['north'] ) ? floatval( $_POST['north'] ) : null;
        $south = isset( $_POST['south'] ) ? floatval( $_POST['south'] ) : null;
        $east  = isset( $_POST['east'] ) ? floatval( $_POST['east'] ) : null;
        $west  = isset( $_POST['west'] ) ? floatval( $_POST['west'] ) : null;
        $zoom  = isset( $_POST['zoom'] ) ? intval( $_POST['zoom'] ) : 13;

        // Validate geographic coordinates to prevent invalid/malicious values
        if ( $north !== null && ( $north < -90 || $north > 90 || is_nan( $north ) || is_infinite( $north ) ) ) {
            $north = null;
        }
        if ( $south !== null && ( $south < -90 || $south > 90 || is_nan( $south ) || is_infinite( $south ) ) ) {
            $south = null;
        }
        if ( $east !== null && ( $east < -180 || $east > 180 || is_nan( $east ) || is_infinite( $east ) ) ) {
            $east = null;
        }
        if ( $west !== null && ( $west < -180 || $west > 180 || is_nan( $west ) || is_infinite( $west ) ) ) {
            $west = null;
        }

        $is_new_filter = isset( $_POST['is_new_filter'] ) && $_POST['is_new_filter'] === 'true';
        $is_initial_load = isset( $_POST['is_initial_load'] ) && $_POST['is_initial_load'] === 'true';
        $is_state_restoration = isset( $_POST['is_state_restoration'] ) && $_POST['is_state_restoration'] === 'true';

        // Handle filters - can be either JSON string or array
        $filters = null;
        if (isset($_POST['filters'])) {
            if (is_string($_POST['filters'])) {
                // If it's a string, decode it as JSON
                $filters = json_decode(wp_unslash($_POST['filters']), true);
            } elseif (is_array($_POST['filters'])) {
                // If it's already an array, use it directly
                $filters = $_POST['filters'];
            }
        }
        if (!is_array($filters)) {
            $filters = null;
        }

        try {
            // Add debug parameter if requested
            $debug_mode = isset($_POST['debug']) && $_POST['debug'] === 'true';

            // v6.13.20: Get diagnostic info BEFORE the query
            $diagnostics = $this->get_summary_table_diagnostics();

            $data = MLD_Query::get_listings_for_map( $north, $south, $east, $west, $filters, $is_new_filter, false, $is_initial_load, $zoom, $debug_mode, $is_state_restoration );

            // v6.35.9: Add shared_by_agent info for "Recommended by [Agent]" badge
            if (is_user_logged_in() && !empty($data['listings'])) {
                $user_id = get_current_user_id();

                // Get shared properties with agent info for this user (uses transient cache)
                $cache_key = 'mld_shared_agent_info_' . $user_id;
                $shared_agent_map = get_transient($cache_key);
                if ($shared_agent_map === false) {
                    global $wpdb;
                    $shares_table = $wpdb->prefix . 'mld_shared_properties';
                    $profiles_table = $wpdb->prefix . 'mld_agent_profiles';
                    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$shares_table}'");
                    $shared_agent_map = array();
                    if ($table_exists) {
                        // Get shares with agent profile info
                        $shares = $wpdb->get_results($wpdb->prepare(
                            "SELECT sp.listing_key, ap.display_name, ap.photo_url
                             FROM {$shares_table} sp
                             LEFT JOIN {$profiles_table} ap ON ap.user_id = sp.agent_id
                             WHERE sp.client_id = %d AND sp.is_dismissed = 0",
                            $user_id
                        ));
                        foreach ($shares as $share) {
                            // Extract first name from display_name
                            $first_name = explode(' ', trim($share->display_name))[0];
                            $shared_agent_map[$share->listing_key] = array(
                                'first_name' => $first_name,
                                'photo_url' => $share->photo_url ?: ''
                            );
                        }
                    }
                    set_transient($cache_key, $shared_agent_map, 60);
                }

                // Add agent info to each shared listing
                foreach ($data['listings'] as &$listing) {
                    $listing_key = isset($listing['ListingKey']) ? $listing['ListingKey'] : (isset($listing['listing_key']) ? $listing['listing_key'] : '');
                    if (!empty($listing_key) && isset($shared_agent_map[$listing_key])) {
                        $listing['is_shared_by_agent'] = true;
                        $listing['shared_by_agent_name'] = $shared_agent_map[$listing_key]['first_name'];
                        $listing['shared_by_agent_photo'] = $shared_agent_map[$listing_key]['photo_url'];
                    } else {
                        $listing['is_shared_by_agent'] = false;
                    }
                }
                unset($listing); // Break reference

                // v6.35.11: Prioritize agent-recommended properties at the top of results
                if (!empty($shared_agent_map) && !empty($data['listings'])) {
                    $shared_listings = array();
                    $other_listings = array();
                    foreach ($data['listings'] as $listing) {
                        if (!empty($listing['is_shared_by_agent'])) {
                            $shared_listings[] = $listing;
                        } else {
                            $other_listings[] = $listing;
                        }
                    }
                    $data['listings'] = array_merge($shared_listings, $other_listings);
                }
            }

            // v6.13.20: Add diagnostics to response for console logging
            $data['_diagnostics'] = $diagnostics;
            $data['_diagnostics']['listings_returned'] = isset($data['listings']) ? count($data['listings']) : 0;
            $data['_diagnostics']['request_time'] = current_time('mysql');

            // Log warning if summary table appears empty
            if ($diagnostics['summary_count'] === 0 && $diagnostics['active_count'] > 0) {
                error_log('[MLD CRITICAL] Summary table is EMPTY but active listings exist! Count: ' . $diagnostics['active_count']);
            }

            wp_send_json_success( $data );
        } catch ( Exception $e ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log('MLD AJAX Error at zoom ' . $zoom . ': ' . $e->getMessage());
            }
            wp_send_json_error( 'An error occurred while fetching listings. Please try again.' );
        }
    }

    public function get_autocomplete_suggestions_callback() {
        $this->set_nocache_headers();
        check_ajax_referer( 'bme_map_nonce', 'security' );
        $search_term = isset( $_POST['term'] ) ? sanitize_text_field( wp_unslash( $_POST['term'] ) ) : '';
        if ( strlen( $search_term ) < 2 ) {
            wp_send_json_success( [] );
            return;
        }
        try {
            $suggestions = MLD_Query::get_autocomplete_suggestions( $search_term );
            wp_send_json_success( $suggestions );
        } catch ( Exception $e ) {
            wp_send_json_error( 'An error occurred while fetching suggestions: ' . $e->getMessage() );
        }
    }

    /**
     * Check if a city exists in the database with active listings
     */
    public function check_city_exists_callback() {
        $this->set_nocache_headers();
        $start_time = microtime(true);

        // Log the request for debugging
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( 'MLD check_city_exists_callback called at ' . date('H:i:s.u') );
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log( 'POST data: ' . json_encode( $_POST ) );
            }
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log( 'Request URI: ' . $_SERVER['REQUEST_URI'] );
            }
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log( 'Request method: ' . $_SERVER['REQUEST_METHOD'] );
            }
        }

        // More flexible nonce verification - check multiple possible field names
        $nonce = null;
        if ( isset( $_POST['security'] ) ) {
            $nonce = $_POST['security'];
        } elseif ( isset( $_POST['nonce'] ) ) {
            $nonce = $_POST['nonce'];
        } elseif ( isset( $_POST['_wpnonce'] ) ) {
            $nonce = $_POST['_wpnonce'];
        }

        if ( ! $nonce || ! wp_verify_nonce( $nonce, 'bme_map_nonce' ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'MLD check_city_exists: Nonce verification failed. Nonce: ' . $nonce );
            }
            wp_send_json_error( 'Security check failed' );
            return;
        }

        $city = isset( $_POST['city'] ) ? sanitize_text_field( wp_unslash( $_POST['city'] ) ) : '';

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( 'MLD City to check: ' . $city );
        }

        if ( empty( $city ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'MLD check_city_exists: Empty city parameter' );
            }
            wp_send_json_error( 'City parameter is required' );
            return;
        }

        try {
            global $wpdb;

            // Get BME tables using the data provider approach
            // Load interface first, then the factory that uses it
            if (!interface_exists('MLD_Data_Provider_Interface')) {
                require_once MLD_PLUGIN_PATH . 'includes/interface-mld-data-provider.php';
            }
            if (!class_exists('MLD_Data_Provider_Factory')) {
                require_once MLD_PLUGIN_PATH . 'includes/class-mld-data-provider-factory.php';
            }

            $provider = mld_get_data_provider();
            $bme_tables = null;

            if ($provider && $provider->is_available()) {
                $bme_tables = $provider->get_tables();
            } else {
                // Fallback to direct access for backward compatibility
                if (function_exists('bme_pro') && method_exists(bme_pro()->get('db'), 'get_tables')) {
                    $bme_tables = bme_pro()->get('db')->get_tables();
                }
            }

            if ( !$bme_tables ) {
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( 'MLD check_city_exists: No database tables available' );
                }
                wp_send_json_error( 'Database tables not available' );
                return;
            }

            $query_start = microtime(true);

            // OPTIMIZED: Single query to check city and get exact name
            // Uses the idx_city_lookup index directly on listing_location table
            $city_result = $wpdb->get_row( $wpdb->prepare(
                "SELECT ll.city as exact_city,
                        COUNT(DISTINCT ll.listing_id) as count
                FROM {$bme_tables['listing_location']} ll
                WHERE LOWER(ll.city) = LOWER(%s)
                AND EXISTS (
                    SELECT 1 FROM {$bme_tables['listings']} l
                    WHERE l.listing_id = ll.listing_id
                    AND l.standard_status = 'Active'
                    LIMIT 1
                )
                GROUP BY ll.city
                LIMIT 1",
                $city
            ) );

            $query_time = (microtime(true) - $query_start) * 1000;
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( sprintf('MLD City query took %.2fms', $query_time) );
            }

            $count = $city_result ? intval( $city_result->count ) : 0;
            $exact_city_name = $city_result ? $city_result->exact_city : null;

            // No additional fallback needed with optimized query

            $response = [
                'exists' => $count > 0,
                'count' => $count,
                'exact_name' => $exact_city_name ?: $city,
                'searched_city' => $city
            ];

            $total_time = (microtime(true) - $start_time) * 1000;
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log( sprintf('MLD City Check completed in %.2fms - Result: %s', $total_time, json_encode( $response ) ) );
            }

            wp_send_json_success( $response );

        } catch ( Exception $e ) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log( 'MLD City Check Error: ' . $e->getMessage() );
            }
            wp_send_json_error( 'An error occurred while checking city: ' . $e->getMessage() );
        }
    }

    public function get_agent_suggestions_callback() {
        $this->set_nocache_headers();
        check_ajax_referer( 'bme_map_nonce', 'security' );
        $search_term = isset( $_POST['term'] ) ? sanitize_text_field( wp_unslash( $_POST['term'] ) ) : '';
        if ( strlen( $search_term ) < 2 ) {
            wp_send_json_success( [] );
            return;
        }
        try {
            $suggestions = MLD_Query::get_agent_autocomplete_suggestions( $search_term );
            wp_send_json_success( $suggestions );
        } catch ( Exception $e ) {
            wp_send_json_error( 'An error occurred while fetching agent suggestions: ' . $e->getMessage() );
        }
    }
    
    /**
     * Handle Walk Score API requests
     */
    public function get_walk_score_callback() {
        $this->set_nocache_headers();
        // Check nonce
        check_ajax_referer( 'mld_ajax_nonce', 'nonce' );
        
        // Get parameters
        $address = isset($_POST['address']) ? sanitize_text_field($_POST['address']) : '';
        $lat = isset($_POST['lat']) ? floatval($_POST['lat']) : 0;
        $lng = isset($_POST['lng']) ? floatval($_POST['lng']) : 0;
        $transit = isset($_POST['transit']) ? (bool)$_POST['transit'] : true;
        $bike = isset($_POST['bike']) ? (bool)$_POST['bike'] : true;
        
        // Get Walk Score API key
        $api_key = MLD_Settings::get_walk_score_api_key();
        
        if (empty($api_key)) {
            wp_send_json_error('Walk Score API key not configured');
            return;
        }
        
        if (empty($lat) || empty($lng)) {
            wp_send_json_error('Invalid coordinates');
            return;
        }
        
        // Build API URL
        $api_url = 'https://api.walkscore.com/score';
        $params = array(
            'format' => 'json',
            'address' => $address,
            'lat' => $lat,
            'lon' => $lng,
            'transit' => $transit ? 1 : 0,
            'bike' => $bike ? 1 : 0,
            'wsapikey' => $api_key
        );
        
        $url = $api_url . '?' . http_build_query($params);
        
        // Make API request
        $response = wp_remote_get($url, array(
            'timeout' => 10,
            'sslverify' => true
        ));
        
        if (is_wp_error($response)) {
            wp_send_json_error('Failed to fetch Walk Score: ' . $response->get_error_message());
            return;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            wp_send_json_error('Invalid response from Walk Score API');
            return;
        }
        
        // Check if API returned an error
        if (isset($data['status']) && $data['status'] != 1) {
            wp_send_json_error($data['description'] ?? 'Walk Score API error');
            return;
        }
        
        // Return the data
        wp_send_json_success($data);
    }
    
    
    /**
     * Calculate distance between two coordinates in miles
     */
    private function calculate_distance($lat1, $lon1, $lat2, $lon2) {
        $earth_radius = 3959; // Miles
        
        $lat1 = deg2rad($lat1);
        $lon1 = deg2rad($lon1);
        $lat2 = deg2rad($lat2);
        $lon2 = deg2rad($lon2);
        
        $dlat = $lat2 - $lat1;
        $dlon = $lon2 - $lon1;
        
        $a = sin($dlat/2) * sin($dlat/2) + cos($lat1) * cos($lat2) * sin($dlon/2) * sin($dlon/2);
        $c = 2 * atan2(sqrt($a), sqrt(1-$a));
        
        return $earth_radius * $c;
    }
    
    /**
     * Handle contact form submission
     */
    public function handle_contact_form() {
        // Verify nonce
        check_ajax_referer('mld_ajax_nonce', 'nonce');
        
        // Validate required fields
        $required_fields = ['first_name', 'last_name', 'email'];
        foreach ($required_fields as $field) {
            if (empty($_POST[$field])) {
                wp_send_json_error('Please fill in all required fields.');
                return;
            }
        }
        
        // Prepare submission data
        $submission_data = [
            'form_type' => 'contact',
            'property_mls' => sanitize_text_field($_POST['mls_number'] ?? ''),
            'property_address' => sanitize_text_field($_POST['property_address'] ?? ''),
            'first_name' => sanitize_text_field($_POST['first_name']),
            'last_name' => sanitize_text_field($_POST['last_name']),
            'email' => sanitize_email($_POST['email']),
            'phone' => sanitize_text_field($_POST['phone'] ?? ''),
            'message' => sanitize_textarea_field($_POST['message'] ?? ''),
            'agent_email' => sanitize_email($_POST['agent_email'] ?? '') // Assigned agent or site contact
        ];
        
        // Save to database
        $submission_id = MLD_Form_Submissions::insert_submission($submission_data);
        
        if (!$submission_id) {
            wp_send_json_error('Failed to save submission. Please try again.');
            return;
        }
        
        // Send email notification to admin
        $this->send_email_notification($submission_data);
        
        // Send confirmation email to user
        $this->send_confirmation_email($submission_data);
        
        wp_send_json_success(['message' => 'Your message has been sent successfully!']);
    }
    
    /**
     * Handle tour request form submission
     */
    public function handle_tour_form() {
        // Verify nonce
        check_ajax_referer('mld_ajax_nonce', 'nonce');
        
        // Validate required fields
        $required_fields = ['first_name', 'last_name', 'email', 'phone'];
        foreach ($required_fields as $field) {
            if (empty($_POST[$field])) {
                wp_send_json_error('Please fill in all required fields.');
                return;
            }
        }
        
        // Prepare submission data
        $submission_data = [
            'form_type' => 'tour',
            'property_mls' => sanitize_text_field($_POST['mls_number'] ?? ''),
            'property_address' => sanitize_text_field($_POST['property_address'] ?? ''),
            'first_name' => sanitize_text_field($_POST['first_name']),
            'last_name' => sanitize_text_field($_POST['last_name']),
            'email' => sanitize_email($_POST['email']),
            'phone' => sanitize_text_field($_POST['phone']),
            'tour_type' => sanitize_text_field($_POST['tour_type'] ?? 'in_person'),
            'preferred_date' => sanitize_text_field($_POST['preferred_date'] ?? ''),
            'preferred_time' => sanitize_text_field($_POST['preferred_time'] ?? ''),
            'agent_email' => sanitize_email($_POST['agent_email'] ?? '') // Assigned agent or site contact
        ];
        
        // Save to database
        $submission_id = MLD_Form_Submissions::insert_submission($submission_data);
        
        if (!$submission_id) {
            wp_send_json_error('Failed to save submission. Please try again.');
            return;
        }
        
        // Send email notification to admin
        $this->send_email_notification($submission_data);
        
        // Send confirmation email to user
        $this->send_confirmation_email($submission_data);
        
        wp_send_json_success(['message' => 'Your tour request has been sent successfully!']);
    }
    
    /**
     * Send email notification for form submission
     */
    private function send_email_notification($submission_data) {
        // Get settings
        $settings = get_option('mld_admin_notification_settings', []);

        // Check if notifications are enabled (default to enabled if not set)
        $notifications_enabled = isset($settings['enable_notifications']) ? $settings['enable_notifications'] : 1;
        if (!$notifications_enabled) {
            return;
        }

        // Get recipient email - use agent_email from form if provided (assigned agent or site contact)
        // Otherwise fall back to admin notification settings, then admin email
        $to = !empty($submission_data['agent_email'])
            ? $submission_data['agent_email']
            : ($settings['notification_email'] ?? get_option('admin_email'));
        if (!$to) {
            return;
        }
        
        // Prepare email subject
        $subject_template = $settings['email_subject'] ?? 'New Property Inquiry - {property_address}';
        $subject = str_replace(
            ['{property_address}', '{property_mls}', '{form_type}'],
            [
                $submission_data['property_address'] ?: 'Unknown Property',
                $submission_data['property_mls'] ?: 'N/A',
                $submission_data['form_type'] === 'tour' ? 'Tour Request' : 'Contact Form'
            ],
            $subject_template
        );
        
        // Prepare email body
        $message = "You have received a new ";
        $message .= $submission_data['form_type'] === 'tour' ? 'tour request' : 'contact form submission';
        $message .= " from your property listing website.\n\n";
        
        $message .= "CONTACT INFORMATION\n";
        $message .= "==================\n";
        $message .= "Name: " . $submission_data['first_name'] . ' ' . $submission_data['last_name'] . "\n";
        $message .= "Email: " . $submission_data['email'] . "\n";
        $message .= "Phone: " . ($submission_data['phone'] ?: 'Not provided') . "\n\n";
        
        if ($submission_data['property_mls']) {
            $message .= "PROPERTY INFORMATION\n";
            $message .= "===================\n";
            $message .= "Property: " . ($submission_data['property_address'] ?: 'Not specified') . "\n";
            $message .= "MLS #: " . $submission_data['property_mls'] . "\n";
            $message .= "View Property: " . home_url('/property/' . $submission_data['property_mls'] . '/') . "\n\n";
        }
        
        if ($submission_data['form_type'] === 'tour') {
            $message .= "TOUR DETAILS\n";
            $message .= "============\n";
            $message .= "Tour Type: " . ucfirst(str_replace('_', ' ', $submission_data['tour_type'])) . "\n";
            if ($submission_data['preferred_date']) {
                $message .= "Preferred Date: " . date('F j, Y', strtotime($submission_data['preferred_date'])) . "\n";
            }
            if ($submission_data['preferred_time']) {
                $message .= "Preferred Time: " . $submission_data['preferred_time'] . "\n";
            }
            $message .= "\n";
        }
        
        if (!empty($submission_data['message'])) {
            $message .= "MESSAGE\n";
            $message .= "=======\n";
            $message .= $submission_data['message'] . "\n\n";
        }
        
        $message .= "---\n";
        $message .= "This message was sent from " . get_bloginfo('name') . "\n";
        $message .= "View all submissions: " . admin_url('admin.php?page=mld_form_submissions');
        
        // Set headers
        $headers = [
            'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>',
            'Reply-To: ' . $submission_data['first_name'] . ' ' . $submission_data['last_name'] . ' <' . $submission_data['email'] . '>'
        ];
        
        // Send email
        wp_mail($to, $subject, $message, $headers);
    }
    
    /**
     * Send confirmation email to user
     */
    private function send_confirmation_email($submission_data) {
        // Get settings based on form type
        $settings_key = $submission_data['form_type'] === 'tour' ? 'mld_tour_confirmation_settings' : 'mld_contact_confirmation_settings';
        $settings = get_option($settings_key, []);

        // Check if confirmation emails are enabled (default to enabled for contact/tour forms)
        $confirmation_enabled = isset($settings['enable']) ? $settings['enable'] : 1;
        if (!$confirmation_enabled) {
            return;
        }

        // Get recipient (the user who submitted the form)
        $to = $submission_data['email'];
        if (!$to) {
            return;
        }

        // Prepare email subject with placeholders
        $default_subject = $submission_data['form_type'] === 'tour' ?
            'Tour Request Confirmation - {property_address}' :
            'Thank you for your inquiry';
        $subject_template = $settings['subject'] ?? $default_subject;
        $subject = $this->replace_email_placeholders($subject_template, $submission_data);

        // Get email body and replace placeholders
        // Use different default message for tour requests
        if ($submission_data['form_type'] === 'tour') {
            $default_message = 'Dear {first_name},

Thank you for requesting a tour of {property_address_linked}. We have received your tour request and appreciate your interest.

Your tour preferences:
Tour Type: {tour_type_formatted}
Preferred Date: {preferred_date_formatted}
Preferred Time: {preferred_time}

One of our real estate professionals will contact you within 24 hours to confirm your tour appointment.

In the meantime, feel free to:
- View more details about this property: {property_address_linked}
- Browse similar properties at {site_url}
- Contact us directly if you have urgent questions

Best regards,
The {site_name} Team';
        } else {
            $default_message = 'Dear {first_name},

Thank you for your inquiry about {property_address_linked}. We have received your message and appreciate your interest.

One of our real estate professionals will review your inquiry and get back to you within 24 hours.

Best regards,
The {site_name} Team';
        }

        $message_template = $settings['message'] ?? $default_message;
        $message = $this->replace_email_placeholders($message_template, $submission_data);

        // Look up recipient user ID for dynamic from address
        $recipient_user_id = null;
        if (class_exists('MLD_Email_Utilities')) {
            $recipient_user_id = MLD_Email_Utilities::get_user_id_from_email($to);
        }

        // Use dynamic from address if available
        if (class_exists('MLD_Email_Utilities') && $recipient_user_id) {
            $headers = MLD_Email_Utilities::get_email_headers($recipient_user_id, false);
        } else {
            // Fallback to static from address
            $sender_email = $settings['sender_email'] ?? get_option('admin_email');
            $sender_name = $settings['sender_name'] ?? get_bloginfo('name');
            $headers = [
                'From: ' . $sender_name . ' <' . $sender_email . '>',
            ];
        }

        // Always use HTML email for consistency with unified footer
        $headers[] = 'Content-Type: text/html; charset=UTF-8';
        $message = $this->build_html_email($message, $settings);

        // Send the confirmation email
        wp_mail($to, $subject, $message, $headers);
    }
    
    /**
     * Replace placeholders in email templates
     */
    private function replace_email_placeholders($template, $submission_data) {
        // First, handle the special case of linked property address
        $property_address = $submission_data['property_address'] ?? 'your selected property';
        $property_url = $submission_data['property_mls'] ? home_url('/property/' . $submission_data['property_mls'] . '/') : '';
        
        // Check if HTML email is enabled to create linked address
        $settings_key = ($submission_data['form_type'] ?? 'contact') === 'tour' ? 'mld_tour_confirmation_settings' : 'mld_contact_confirmation_settings';
        $settings = get_option($settings_key, []);
        $is_html = isset($settings['enable_html']) && $settings['enable_html'];
        
        // Format tour-specific fields
        $tour_type_formatted = '';
        if (!empty($submission_data['tour_type'])) {
            $tour_type_formatted = ucfirst(str_replace('_', ' ', $submission_data['tour_type']));
        }
        
        $preferred_date_formatted = '';
        if (!empty($submission_data['preferred_date'])) {
            $preferred_date_formatted = date('F j, Y', strtotime($submission_data['preferred_date']));
        }
        
        $replacements = [
            '{first_name}' => $submission_data['first_name'] ?? '',
            '{last_name}' => $submission_data['last_name'] ?? '',
            '{property_address}' => $property_address,
            '{property_address_linked}' => $is_html && $property_url ? '<a href="' . esc_url($property_url) . '">' . esc_html($property_address) . '</a>' : $property_address,
            '{property_mls}' => $submission_data['property_mls'] ?? '',
            '{property_url}' => $property_url,
            '{form_type}' => $submission_data['form_type'] === 'tour' ? 'tour request' : 'inquiry',
            '{message}' => $submission_data['message'] ?? '',
            '{site_name}' => get_bloginfo('name'),
            '{site_url}' => home_url(),
            // Tour-specific placeholders
            '{tour_type}' => $submission_data['tour_type'] ?? '',
            '{tour_type_formatted}' => $tour_type_formatted,
            '{preferred_date}' => $submission_data['preferred_date'] ?? '',
            '{preferred_date_formatted}' => $preferred_date_formatted,
            '{preferred_time}' => $submission_data['preferred_time'] ?? '',
        ];
        
        return str_replace(array_keys($replacements), array_values($replacements), $template);
    }
    
    /**
     * Build HTML email template
     */
    private function build_html_email($message, $settings) {
        $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email</title>
    <style>
        body {
            margin: 0;
            padding: 0;
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            background-color: #f4f4f4;
        }
        .email-container {
            max-width: 600px;
            margin: 0 auto;
            background-color: #ffffff;
        }
        .email-header {
            background-color: #f8f9fa;
            padding: 20px;
            text-align: center;
            border-bottom: 3px solid #007cba;
        }
        .email-header img {
            max-width: 300px;
            height: auto;
        }
        .email-body {
            padding: 30px;
        }
        .email-footer {
            background-color: #f8f9fa;
            padding: 20px;
            text-align: center;
            font-size: 12px;
            color: #666;
        }
        a {
            color: #007cba;
            text-decoration: none;
        }
        a:hover {
            text-decoration: underline;
        }
        .button {
            display: inline-block;
            padding: 10px 20px;
            background-color: #007cba;
            color: #ffffff;
            text-decoration: none;
            border-radius: 4px;
            margin: 10px 0;
        }
        .button:hover {
            background-color: #005a87;
            text-decoration: none;
        }
    </style>
</head>
<body>
    <div class="email-container">';
        
        // Add header image if set
        if (!empty($settings['header_image'])) {
            $html .= '
        <div class="email-header">
            <img src="' . esc_url($settings['header_image']) . '" alt="' . esc_attr(get_bloginfo('name')) . '">
        </div>';
        }
        
        // Add email body
        $html .= '
        <div class="email-body">
            ' . nl2br($message) . '
        </div>';
        
        // Add unified footer with social links and App Store promotion
        if (class_exists('MLD_Email_Utilities')) {
            $html .= '
        <div class="email-footer" style="background-color: #f8f9fa; padding: 20px;">
            ' . MLD_Email_Utilities::get_unified_footer([
                'context' => 'general',
                'show_social' => true,
                'show_app_download' => true,
                'compact' => true,
            ]) . '
        </div>';
        } else {
            // Fallback to simple footer
            $html .= '
        <div class="email-footer">
            <p>&copy; ' . date('Y') . ' ' . get_bloginfo('name') . '. All rights reserved.</p>
            <p>This is an automated email. Please do not reply directly to this message.</p>
        </div>';
        }

        $html .= '
    </div>
</body>
</html>';

        return $html;
    }
    
    /**
     * Handle save/unsave property requests
     */
    public function handle_save_property() {
        // Verify nonce using WordPress standard method
        // Accept both nonce names for compatibility (mld_ajax_nonce from property pages, mld_nonce from other contexts)
        if ( ! check_ajax_referer( 'mld_ajax_nonce', 'nonce', false ) &&
             ! check_ajax_referer( 'mld_nonce', 'nonce', false ) ) {
            wp_send_json_error( 'Invalid security token' );
            return;
        }

        $mls_number = isset($_POST['mls_number']) ? sanitize_text_field($_POST['mls_number']) : '';
        $action_type = isset($_POST['action_type']) ? sanitize_text_field($_POST['action_type']) : 'toggle';

        if (empty($mls_number)) {
            wp_send_json_error('MLS number is required');
        }

        // Get current saved properties
        if (is_user_logged_in()) {
            // For logged-in users, use user meta AND database table for compatibility
            $user_id = get_current_user_id();
            $saved_properties = get_user_meta($user_id, 'mld_saved_properties', true);
            if (!is_array($saved_properties)) {
                $saved_properties = array();
            }

            // Toggle or set based on action
            if ($action_type === 'save' && !in_array($mls_number, $saved_properties)) {
                $saved_properties[] = $mls_number;
                $is_saved = true;
            } elseif ($action_type === 'unsave') {
                $saved_properties = array_diff($saved_properties, array($mls_number));
                $is_saved = false;
            } else {
                // Toggle
                if (in_array($mls_number, $saved_properties)) {
                    $saved_properties = array_diff($saved_properties, array($mls_number));
                    $is_saved = false;
                } else {
                    $saved_properties[] = $mls_number;
                    $is_saved = true;
                }
            }

            // Update user meta (legacy)
            update_user_meta($user_id, 'mld_saved_properties', array_values($saved_properties));

            // ALSO update the database table for My Saved Properties page compatibility
            // Sync the database table to match the $is_saved state (don't just toggle)
            if (class_exists('MLD_Property_Preferences')) {
                $current_pref = MLD_Property_Preferences::get_property_preference($user_id, $mls_number);

                if ($is_saved && $current_pref !== 'liked') {
                    // Need to add/update to 'liked'
                    MLD_Property_Preferences::toggle_property($user_id, $mls_number, 'liked');
                } elseif (!$is_saved && $current_pref !== null) {
                    // Need to remove
                    MLD_Property_Preferences::remove_preference($user_id, $mls_number);
                }
            }

        } else {
            // For non-logged-in users, use cookies
            $cookie_name = 'mld_saved_properties';
            $saved_properties = isset($_COOKIE[$cookie_name]) ? json_decode(wp_unslash($_COOKIE[$cookie_name]), true) : array();
            
            if (!is_array($saved_properties)) {
                $saved_properties = array();
            }
            
            // Toggle or set based on action
            if ($action_type === 'save' && !in_array($mls_number, $saved_properties)) {
                $saved_properties[] = $mls_number;
                $is_saved = true;
            } elseif ($action_type === 'unsave') {
                $saved_properties = array_diff($saved_properties, array($mls_number));
                $is_saved = false;
            } else {
                // Toggle
                if (in_array($mls_number, $saved_properties)) {
                    $saved_properties = array_diff($saved_properties, array($mls_number));
                    $is_saved = false;
                } else {
                    $saved_properties[] = $mls_number;
                    $is_saved = true;
                }
            }
            
            // Set cookie (expires in 30 days) with enhanced security
            $cookie_value = json_encode(array_values($saved_properties));
            $cookie_expires = time() + (30 * DAY_IN_SECONDS);

            // Use PHP 7.3+ options array for SameSite support, with fallback
            if ( PHP_VERSION_ID >= 70300 ) {
                setcookie( $cookie_name, $cookie_value, [
                    'expires'  => $cookie_expires,
                    'path'     => COOKIEPATH,
                    'domain'   => COOKIE_DOMAIN,
                    'secure'   => is_ssl(),
                    'httponly' => true,
                    'samesite' => 'Lax'  // Prevents CSRF while allowing normal navigation
                ]);
            } else {
                // Fallback for older PHP - SameSite via path hack
                setcookie( $cookie_name, $cookie_value, $cookie_expires, COOKIEPATH . '; SameSite=Lax', COOKIE_DOMAIN, is_ssl(), true );
            }
        }
        
        wp_send_json_success(array(
            'is_saved' => $is_saved,
            'saved_count' => count($saved_properties)
        ));
    }

    /**
     * Handle hide/unhide property requests (v6.31.9)
     * Only for logged-in users - uses MLD_Property_Preferences table
     */
    public function handle_hide_property() {
        // Verify nonce
        if ( ! check_ajax_referer( 'mld_ajax_nonce', 'nonce', false ) &&
             ! check_ajax_referer( 'mld_nonce', 'nonce', false ) ) {
            wp_send_json_error( 'Invalid security token' );
            return;
        }

        // Require logged-in user
        if (!is_user_logged_in()) {
            wp_send_json_error('Login required to hide properties');
            return;
        }

        $mls_number = isset($_POST['mls_number']) ? sanitize_text_field($_POST['mls_number']) : '';
        if (empty($mls_number)) {
            wp_send_json_error('MLS number is required');
            return;
        }

        $user_id = get_current_user_id();
        if (!class_exists('MLD_Property_Preferences')) {
            wp_send_json_error('Property preferences not available');
            return;
        }

        // Toggle the disliked preference
        $current_pref = MLD_Property_Preferences::get_property_preference($user_id, $mls_number);
        $is_hidden = $current_pref === 'disliked';

        if ($is_hidden) {
            // Currently hidden, unhide it
            MLD_Property_Preferences::remove_preference($user_id, $mls_number);
            $is_hidden = false;
        } else {
            // Not hidden, hide it (set to disliked)
            MLD_Property_Preferences::toggle_property($user_id, $mls_number, 'disliked');
            $is_hidden = true;
        }

        wp_send_json_success(array(
            'is_hidden' => $is_hidden
        ));
    }

    /**
     * Get list of saved properties
     */
    public function get_saved_properties() {
        // Verify nonce using WordPress standard method
        if ( ! check_ajax_referer( 'mld_nonce', 'nonce', false ) ) {
            wp_send_json_error( 'Invalid security token' );
            return;
        }
        
        // Get saved properties
        if (is_user_logged_in()) {
            $user_id = get_current_user_id();
            $saved_properties = get_user_meta($user_id, 'mld_saved_properties', true);
        } else {
            $cookie_name = 'mld_saved_properties';
            $saved_properties = isset($_COOKIE[$cookie_name]) ? json_decode(wp_unslash($_COOKIE[$cookie_name]), true) : array();
        }
        
        if (!is_array($saved_properties)) {
            $saved_properties = array();
        }
        
        // Get property details for saved properties
        $properties_data = array();
        if (!empty($saved_properties)) {
            foreach ($saved_properties as $mls_number) {
                $property = MLD_Query::get_listing_details($mls_number);
                if ($property) {
                    $properties_data[] = array(
                        'mls_number' => $mls_number,
                        'address' => $property['unparsed_address'] ?? '',
                        'price' => $property['list_price'] ?? 0,
                        'beds' => $property['bedrooms_total'] ?? 0,
                        'baths' => ($property['bathrooms_full'] ?? 0) + (($property['bathrooms_half'] ?? 0) * 0.5),
                        'sqft' => $property['living_area'] ?? null,
                        'photo' => isset($property['Media'][0]['MediaURL']) ? $property['Media'][0]['MediaURL'] : '',
                        'url' => home_url('/property/' . $mls_number . '/')
                    );
                }
            }
        }
        
        wp_send_json_success(array(
            'saved_properties' => array_values($saved_properties),
            'properties_data' => $properties_data
        ));
    }
    
    /**
     * Get similar homes based on property criteria
     */
    public function get_similar_homes_callback() {
        $this->set_nocache_headers();
        // Check nonce
        check_ajax_referer( 'mld_ajax_nonce', 'nonce' );
        
        // Get parameters
        $property_id = isset($_POST['property_id']) ? sanitize_text_field($_POST['property_id']) : '';
        $lat = isset($_POST['lat']) ? floatval($_POST['lat']) : 0;
        $lng = isset($_POST['lng']) ? floatval($_POST['lng']) : 0;
        $price = isset($_POST['price']) ? floatval($_POST['price']) : 0;
        $beds = isset($_POST['beds']) ? intval($_POST['beds']) : 0;
        $baths = isset($_POST['baths']) ? floatval($_POST['baths']) : 0;
        $sqft = isset($_POST['sqft']) ? intval($_POST['sqft']) : 0;
        $property_type = isset($_POST['property_type']) ? sanitize_text_field($_POST['property_type']) : '';
        $property_sub_type = isset($_POST['property_sub_type']) ? sanitize_text_field($_POST['property_sub_type']) : '';
        $status = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : 'Active';
        $close_date = isset($_POST['close_date']) ? sanitize_text_field($_POST['close_date']) : '';
        $year_built = isset($_POST['year_built']) ? intval($_POST['year_built']) : 0;
        $lot_size = isset($_POST['lot_size']) ? floatval($_POST['lot_size']) : 0;
        $is_waterfront = isset($_POST['is_waterfront']) ? filter_var($_POST['is_waterfront'], FILTER_VALIDATE_BOOLEAN) : false;
        $garage_spaces = isset($_POST['garage_spaces']) ? intval($_POST['garage_spaces']) : 0;
        $parking_total = isset($_POST['parking_total']) ? intval($_POST['parking_total']) : 0;
        $entry_level = isset($_POST['entry_level']) ? intval($_POST['entry_level']) : 0;
        $city = isset($_POST['city']) ? sanitize_text_field($_POST['city']) : '';
        $page = isset($_POST['page']) ? intval($_POST['page']) : 1;
        $per_page = 9; // 3x3 grid
        $limit = 20; // Total results to fetch
        $radius = 3; // 3 miles
        
        // NEW: Get selected statuses from UI (defaults to all if not provided)
        $selected_statuses = isset($_POST['selected_statuses']) ? json_decode(wp_unslash($_POST['selected_statuses']), true) : null;
        
        // Validate required fields
        if (!$lat || !$lng) {
            wp_send_json_error('Missing location data');
            return;
        }
        
        try {
            // Determine status filter - now supports user selection
            $status_filter = array();
            $is_sold = false;
            
            if (!empty($selected_statuses) && is_array($selected_statuses)) {
                // Use user-selected statuses
                $status_filter = $selected_statuses;
                $is_sold = in_array('Closed', $selected_statuses);
            } else {
                // Default behavior based on subject property status
                if (strtolower($status) === 'closed' || strtolower($status) === 'sold') {
                    $status_filter = array('Closed');
                    $is_sold = true;
                } elseif (strtolower($status) === 'pending' || strtolower($status) === 'active under contract') {
                    $status_filter = array('Pending', 'Active Under Contract', 'Active');
                } else {
                    $status_filter = array('Active');
                }
            }
            
            // Get similar properties using existing query methods
            $filters = array(
                'status' => $status_filter,
                'bounds' => array(
                    'north' => $lat + ($radius / 69), // Roughly 1 degree latitude = 69 miles
                    'south' => $lat - ($radius / 69),
                    'east' => $lng + ($radius / (69 * cos(deg2rad($lat)))),
                    'west' => $lng - ($radius / (69 * cos(deg2rad($lat))))
                ),
                'priceRange' => array(
                    'min' => $price * 0.85, // 15% below
                    'max' => $price * 1.15  // 15% above
                )
            );
            
            // Add property type filters
            if (!empty($property_type)) {
                $filters['PropertyType'] = array($property_type);
            }
            
            if (!empty($property_sub_type)) {
                $filters['home_type'] = array($property_sub_type);
            }
            
            // Add beds/baths filters with some flexibility
            if ($beds > 0) {
                $filters['beds'] = $beds; // The query method handles the range internally
            }
            
            if ($baths > 0) {
                $filters['baths_min'] = max(1, $baths - 0.5);
            }
            
            // Add square footage filter if available
            if ($sqft > 0) {
                $filters['sqft_min'] = $sqft * 0.8; // 20% below
                $filters['sqft_max'] = $sqft * 1.2; // 20% above
            }
            
            // IMPORTANT: Filter by waterfront status if subject property is waterfront
            // This ensures waterfront properties only match with other waterfront properties
            if ($is_waterfront) {
                $filters['WaterfrontYN'] = true;
            }
            
            // Add city filter if available
            if (!empty($city)) {
                $filters['city'] = array($city);
            }
            
            // Get properties using the map query method
            $result = MLD_Query::get_listings_for_map(
                $filters['bounds']['north'],
                $filters['bounds']['south'],
                $filters['bounds']['east'],
                $filters['bounds']['west'],
                $filters,
                false, // is_new_filter
                false, // count_only
                false  // is_initial_load
            );
            
            $similar_homes = array();
            $subject_property_data = null;

            if (!empty($result['listings'])) {
                // Convert objects to arrays if needed
                $listings = array_map(function($item) {
                    return is_object($item) ? (array)$item : $item;
                }, $result['listings']);

                foreach ($listings as $listing) {
                    // Save the subject property data (don't skip it!)
                    if (($listing['ListingId'] ?? $listing['listing_id']) === $property_id) {
                        $subject_property_data = $listing;
                        continue; // Still skip it in the loop, we'll add it separately
                    }
                    
                    // IMPORTANT: Skip properties not in the same city
                    if (!empty($city) && strcasecmp($listing['City'] ?? '', $city) !== 0) {
                        continue;
                    }
                    
                    // For closed properties, only include those sold within the past year
                    if (strtolower($listing['StandardStatus'] ?? '') === 'closed') {
                        $close_date = $listing['close_date'] ?? null;
                        if (!empty($close_date)) {
                            $close_timestamp = strtotime($close_date);
                            $one_year_ago = strtotime('-1 year');
                            if ($close_timestamp < $one_year_ago) {
                                continue; // Skip properties sold more than 1 year ago
                            }
                        }
                    }
                    
                    // Check waterfront status for weighted scoring
                    $listing_waterfront = isset($listing['WaterfrontYN']) && ($listing['WaterfrontYN'] === 'Y' || $listing['WaterfrontYN'] === '1' || $listing['WaterfrontYN'] === 1);
                    
                    // Calculate distance - coordinates are returned as Latitude/Longitude (capital letters)
                    $distance = $this->calculate_distance($lat, $lng, $listing['Latitude'], $listing['Longitude']);
                    
                    // Calculate similarity score with new weights (max 100 points)
                    $similarity_score = 0;
                    
                    // Size match score (max 30 points) - most important
                    if ($sqft > 0 && $listing['LivingArea'] > 0) {
                        $size_diff_percent = abs(($listing['LivingArea'] - $sqft) / $sqft) * 100;
                        if ($size_diff_percent <= 5) {
                            $size_score = 30;
                        } elseif ($size_diff_percent <= 10) {
                            $size_score = 25;
                        } elseif ($size_diff_percent <= 15) {
                            $size_score = 20;
                        } elseif ($size_diff_percent <= 20) {
                            $size_score = 15;
                        } elseif ($size_diff_percent <= 30) {
                            $size_score = 10;
                        } else {
                            $size_score = max(0, 30 - ($size_diff_percent * 0.6));
                        }
                        $similarity_score += $size_score;
                    } else {
                        // If no size data, give partial points
                        $similarity_score += 15;
                    }
                    
                    // Bedroom match score (max 15 points)
                    $bed_diff = abs($listing['BedroomsTotal'] - $beds);
                    if ($bed_diff === 0) {
                        $bed_score = 15;
                    } elseif ($bed_diff === 1) {
                        $bed_score = 8;
                    } elseif ($bed_diff === 2) {
                        $bed_score = 3;
                    } else {
                        $bed_score = 0;
                    }
                    $similarity_score += $bed_score;
                    
                    // Bathroom match score (max 15 points)
                    $listing_baths = ($listing['BathroomsFull'] ?? 0) + (($listing['BathroomsHalf'] ?? 0) * 0.5);
                    $bath_diff = abs($listing_baths - $baths);
                    if ($bath_diff === 0) {
                        $bath_score = 15;
                    } elseif ($bath_diff <= 0.5) {
                        $bath_score = 10;
                    } elseif ($bath_diff === 1) {
                        $bath_score = 5;
                    } else {
                        $bath_score = 0;
                    }
                    $similarity_score += $bath_score;
                    
                    // Price difference score (max 15 points)
                    if ($price > 0) {
                        $price_diff_percent = abs(($listing['ListPrice'] - $price) / $price) * 100;
                        if ($price_diff_percent <= 5) {
                            $price_score = 15;
                        } elseif ($price_diff_percent <= 10) {
                            $price_score = 12;
                        } elseif ($price_diff_percent <= 15) {
                            $price_score = 8;
                        } elseif ($price_diff_percent <= 20) {
                            $price_score = 4;
                        } else {
                            $price_score = 0;
                        }
                        $similarity_score += $price_score;
                    }
                    
                    // Distance score (max 15 points) - increased weight for proximity
                    // Very close properties get significantly more weight
                    if ($distance <= 0.1) {
                        // Within 0.1 miles (about 528 feet) - extremely close
                        $distance_score = 15;
                    } elseif ($distance <= 0.25) {
                        // Within 0.25 miles (about 1320 feet) - very close neighborhood
                        $distance_score = 13;
                    } elseif ($distance <= 0.5) {
                        // Within 0.5 miles - same neighborhood
                        $distance_score = 10;
                    } elseif ($distance <= 1) {
                        // Within 1 mile - nearby neighborhood
                        $distance_score = 6;
                    } elseif ($distance <= 2) {
                        // Within 2 miles - same area
                        $distance_score = 3;
                    } else {
                        // Over 2 miles but within city limits
                        $distance_score = 1;
                    }
                    $similarity_score += $distance_score;
                    
                    // Waterfront match score (max 10 points) - reduced from 15 to balance increased distance weight
                    // This is treated as a feature match, not a bonus
                    if ($is_waterfront && $listing_waterfront) {
                        // Both are waterfront - perfect feature match
                        $waterfront_score = 10;
                    } else if (!$is_waterfront && !$listing_waterfront) {
                        // Neither is waterfront - also a perfect match for this feature
                        $waterfront_score = 10;
                    } else {
                        // Mismatch - significant penalty but not overwhelming
                        $waterfront_score = 0;
                    }
                    $similarity_score += $waterfront_score;
                    
                    // Unit level scoring for condominiums (additional points, not part of base 100)
                    // This is a bonus/penalty system for condos based on floor level differences
                    $unit_level_adjustment = 0;
                    $subject_entry_level = $entry_level;
                    $listing_entry_level = isset($listing['EntryLevel']) ? intval($listing['EntryLevel']) : 0;
                    
                    // Only apply unit level scoring for condominiums
                    if ((stripos($property_sub_type, 'condo') !== false || stripos($property_type, 'condo') !== false) &&
                        $subject_entry_level > 0 && $listing_entry_level > 0) {
                        
                        $floor_diff = abs($subject_entry_level - $listing_entry_level);
                        
                        if ($floor_diff === 0) {
                            // Same floor - perfect match, add bonus
                            $unit_level_adjustment = 5;
                        } elseif ($floor_diff <= 2) {
                            // Within 2 floors - very similar
                            $unit_level_adjustment = 3;
                        } elseif ($floor_diff <= 5) {
                            // Within 5 floors - somewhat similar
                            $unit_level_adjustment = 1;
                        } elseif ($floor_diff <= 10) {
                            // 6-10 floors difference - neutral
                            $unit_level_adjustment = 0;
                        } elseif ($floor_diff <= 20) {
                            // 11-20 floors difference - somewhat different
                            $unit_level_adjustment = -3;
                        } else {
                            // More than 20 floors difference - very different
                            $unit_level_adjustment = -5;
                        }
                        
                        // Apply the adjustment
                        $similarity_score += $unit_level_adjustment;
                    }
                    
                    // Ensure score doesn't exceed 100 or go below 0
                    $similarity_score = min(100, max(0, $similarity_score));
                    
                    // Calculate days on market using the same logic as V3 display
                    $days_on_market = 0;
                    $status_lower = strtolower($listing['StandardStatus'] ?? '');
                    $original_entry = $listing['original_entry_timestamp'] ?? null;
                    $off_market_date = $listing['off_market_date'] ?? null;
                    
                    if (!empty($original_entry)) {
                        $start_timestamp = strtotime($original_entry);
                        
                        if ($start_timestamp !== false) {
                            // For Active properties: calculate from original_entry_timestamp to now
                            if ($status_lower === 'active') {
                                $current_timestamp = time();
                                $diff_seconds = $current_timestamp - $start_timestamp;
                                $days_on_market = floor($diff_seconds / 86400);
                            }
                            // For Closed, Pending, and Active Under Contract: calculate from original_entry to off_market_date
                            elseif (in_array($status_lower, ['closed', 'pending', 'active under contract'])) {
                                if (!empty($off_market_date)) {
                                    $end_timestamp = strtotime($off_market_date);
                                } elseif ($status_lower === 'closed' && !empty($listing['close_date'])) {
                                    $end_timestamp = strtotime($listing['close_date']);
                                } else {
                                    // If no end date, use current time as fallback
                                    $end_timestamp = time();
                                }
                                
                                if ($end_timestamp !== false) {
                                    $diff_seconds = $end_timestamp - $start_timestamp;
                                    $days_on_market = floor($diff_seconds / 86400);
                                }
                            }
                        }
                    }
                    
                    // Prepare property data - field names are capitalized in the query results
                    $similar_home = array(
                        'listing_id' => $listing['ListingId'] ?? $listing['listing_id'],
                        'address' => $listing['StreetNumber'] . ' ' . $listing['StreetName'] . ($listing['UnitNumber'] ? ' #' . $listing['UnitNumber'] : ''),
                        'city' => $listing['City'] ?? '',
                        'state_or_province' => $listing['StateOrProvince'] ?? '',
                        'postal_code' => $listing['PostalCode'] ?? '',
                        'price' => $listing['ListPrice'] ?? 0,
                        'beds' => $listing['BedroomsTotal'] ?? 0,
                        'baths' => ($listing['BathroomsFull'] ?? 0) + (($listing['BathroomsHalf'] ?? 0) * 0.5),
                        'sqft' => $listing['LivingArea'] ?? null,
                        'year_built' => $listing['YearBuilt'] ?? null,
                        'lot_size_acres' => $listing['LotSizeAcres'] ?? null,
                        'lot_size_sqft' => $listing['LotSizeSquareFeet'] ?? null,
                        'garage_spaces' => $listing['GarageSpaces'] ?? 0,
                        'parking_total' => $listing['ParkingTotal'] ?? 0,
                        'waterfront' => $listing_waterfront,
                        'entry_level' => $listing['EntryLevel'] ?? null,
                        'photo_url' => $listing['photo_url'] ?? '',
                        'url' => home_url('/property/' . ($listing['ListingId'] ?? $listing['listing_id']) . '/'),
                        'distance' => round($distance, 1),
                        'status' => $listing['StandardStatus'] ?? '',
                        'property_type' => $listing['PropertySubType'] ?? $listing['PropertyType'] ?? '',
                        'similarity_score' => $similarity_score,
                        'close_date' => $listing['close_date'] ?? null,
                        'days_on_market' => $days_on_market,
                        'creation_timestamp' => $listing['creation_timestamp'] ?? null
                    );
                    
                    // Add status badge if needed
                    $status_badge = null;
                    
                    // Check for new listings - only show "New" if actually new to market
                    if (strtolower($listing['StandardStatus']) === 'active') {
                        // Try different methods to determine if new
                        $is_new = false;
                        
                        // Method 1: Check days_on_market field if available
                        if (isset($listing['days_on_market']) && $listing['days_on_market'] <= 7) {
                            $is_new = true;
                        }
                        // Method 2: Calculate from creation_timestamp
                        elseif (isset($listing['creation_timestamp'])) {
                            $days_since_created = (time() - strtotime($listing['creation_timestamp'])) / (60 * 60 * 24);
                            if ($days_since_created <= 7) {
                                $is_new = true;
                            }
                        }
                        
                        if ($is_new) {
                            $status_badge = array('text' => 'New', 'class' => 'new');
                        }
                    } elseif (strtolower($listing['StandardStatus']) === 'pending' || strtolower($listing['StandardStatus']) === 'active under contract') {
                        $status_badge = array('text' => 'Pending', 'class' => 'pending');
                    } elseif (strtolower($listing['StandardStatus']) === 'closed') {
                        $status_badge = array('text' => 'Sold', 'class' => 'sold');
                    }
                    
                    if ($status_badge) {
                        $similar_home['status_badge'] = $status_badge;
                    }
                    
                    $similar_homes[] = $similar_home;
                    
                    // Stop if we have enough
                    if (count($similar_homes) >= $limit) {
                        break;
                    }
                }
            }

            // Add the subject property as the first item if we found it
            if ($subject_property_data) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('MLD: Found subject property data for property_id: ' . $property_id);
                }
                $listing_waterfront = isset($subject_property_data['WaterfrontYN']) &&
                    ($subject_property_data['WaterfrontYN'] === 'Y' ||
                     $subject_property_data['WaterfrontYN'] === '1' ||
                     $subject_property_data['WaterfrontYN'] === 1);

                $subject_home = array(
                    'listing_id' => $subject_property_data['ListingId'] ?? $subject_property_data['listing_id'],
                    'address' => $subject_property_data['StreetNumber'] . ' ' . $subject_property_data['StreetName'] .
                                ($subject_property_data['UnitNumber'] ? ' #' . $subject_property_data['UnitNumber'] : ''),
                    'city' => $subject_property_data['City'] ?? '',
                    'state_or_province' => $subject_property_data['StateOrProvince'] ?? '',
                    'postal_code' => $subject_property_data['PostalCode'] ?? '',
                    'price' => $subject_property_data['ListPrice'] ?? 0,
                    'beds' => $subject_property_data['BedroomsTotal'] ?? 0,
                    'baths' => ($subject_property_data['BathroomsFull'] ?? 0) +
                              (($subject_property_data['BathroomsHalf'] ?? 0) * 0.5),
                    'sqft' => $subject_property_data['LivingArea'] ?? null,
                    'year_built' => $subject_property_data['YearBuilt'] ?? null,
                    'lot_size_acres' => $subject_property_data['LotSizeAcres'] ?? null,
                    'lot_size_sqft' => $subject_property_data['LotSizeSquareFeet'] ?? null,
                    'garage_spaces' => $subject_property_data['GarageSpaces'] ?? 0,
                    'parking_total' => $subject_property_data['ParkingTotal'] ?? 0,
                    'waterfront' => $listing_waterfront,
                    'entry_level' => $subject_property_data['EntryLevel'] ?? null,
                    'photo_url' => $subject_property_data['photo_url'] ?? '',
                    'url' => home_url('/property/' . ($subject_property_data['ListingId'] ?? $subject_property_data['listing_id']) . '/'),
                    'distance' => 0, // Distance is 0 for subject property
                    'status' => $subject_property_data['StandardStatus'] ?? '',
                    'property_type' => $subject_property_data['PropertySubType'] ?? $subject_property_data['PropertyType'] ?? '',
                    'similarity_score' => 100, // Perfect match - it's the subject!
                    'close_date' => $subject_property_data['close_date'] ?? null,
                    'days_on_market' => 0,
                    'creation_timestamp' => $subject_property_data['creation_timestamp'] ?? null,
                    'is_subject' => true // FLAG to identify this as the subject property
                );

                // Add status badge for subject property
                $status_badge = array('text' => 'Subject Property', 'class' => 'subject');
                $subject_home['status_badge'] = $status_badge;

                // Prepend subject property to the beginning of the array
                array_unshift($similar_homes, $subject_home);
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('MLD: Added subject property as first item. Total properties: ' . count($similar_homes));
                }
            } else {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('MLD: WARNING - Subject property data NOT found for property_id: ' . $property_id);
                }
            }

            // Sort by similarity score (subject will stay first with score of 100)
            usort($similar_homes, function($a, $b) {
                return $b['similarity_score'] - $a['similarity_score'];
            });

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD: After sorting, first property is: ' . ($similar_homes[0]['address'] ?? 'unknown') . ' (is_subject: ' . ($similar_homes[0]['is_subject'] ?? 'false') . ')');
            }
            
            // Calculate market statistics before pagination
            $market_stats = $this->calculate_market_statistics($similar_homes);
            
            // Apply pagination
            $total_count = count($similar_homes);
            $total_pages = ceil($total_count / $per_page);
            $offset = ($page - 1) * $per_page;
            $paged_homes = array_slice($similar_homes, $offset, $per_page);
            
            // Include all properties for client-side market calculations
            $all_properties = array();
            if ($page === 1) {
                // Return all similar homes for market calculations
                $all_properties = $similar_homes;
            }
            
            wp_send_json_success(array(
                'properties' => $paged_homes,
                'all_properties' => $all_properties,
                'total' => $total_count,
                'page' => $page,
                'total_pages' => $total_pages,
                'per_page' => $per_page,
                'market_stats' => $market_stats
            ));
            
        } catch (Exception $e) {
            wp_send_json_error('Error fetching similar homes: ' . $e->getMessage());
        }
    }
    
    /**
     * Calculate market statistics from similar homes
     */
    private function calculate_market_statistics($homes) {
        if (empty($homes)) {
            return null;
        }
        
        $stats = array(
            'total_homes' => count($homes),
            'avg_price' => 0,
            'avg_price_per_sqft' => 0,
            'avg_days_on_market' => 0,
            'avg_beds' => 0,
            'avg_baths' => 0,
            'avg_sqft' => 0,
            'price_range' => array('min' => 0, 'max' => 0),
            'homes_with_sqft' => 0,
            'homes_with_dom' => 0
        );
        
        $total_price = 0;
        $total_price_per_sqft = 0;
        $total_days_on_market = 0;
        $total_beds = 0;
        $total_baths = 0;
        $total_sqft = 0;
        $prices = array();
        
        foreach ($homes as $home) {
            // Price
            if ($home['price'] > 0) {
                $total_price += $home['price'];
                $prices[] = $home['price'];
            }
            
            // Beds and baths
            $total_beds += $home['beds'];
            $total_baths += $home['baths'];
            
            // Square footage and price per sqft
            if ($home['sqft'] > 0) {
                $total_sqft += $home['sqft'];
                $stats['homes_with_sqft']++;
                
                if ($home['price'] > 0) {
                    $total_price_per_sqft += ($home['price'] / $home['sqft']);
                }
            }
            
            // Days on market
            $dom = null;
            if (isset($home['days_on_market']) && $home['days_on_market'] > 0) {
                $dom = $home['days_on_market'];
            } elseif (isset($home['creation_timestamp'])) {
                $dom = (time() - strtotime($home['creation_timestamp'])) / (60 * 60 * 24);
            }
            
            if ($dom !== null && $dom > 0) {
                $total_days_on_market += $dom;
                $stats['homes_with_dom']++;
            }
        }
        
        // Calculate averages
        if (count($homes) > 0) {
            $stats['avg_price'] = round($total_price / count($homes));
            $stats['avg_beds'] = round($total_beds / count($homes), 1);
            $stats['avg_baths'] = round($total_baths / count($homes), 1);
        }
        
        if ($stats['homes_with_sqft'] > 0) {
            $stats['avg_sqft'] = round($total_sqft / $stats['homes_with_sqft']);
            $stats['avg_price_per_sqft'] = round($total_price_per_sqft / $stats['homes_with_sqft']);
        }
        
        if ($stats['homes_with_dom'] > 0) {
            $stats['avg_days_on_market'] = round($total_days_on_market / $stats['homes_with_dom']);
        }
        
        // Price range
        if (!empty($prices)) {
            $stats['price_range']['min'] = min($prices);
            $stats['price_range']['max'] = max($prices);
        }
        
        return $stats;
    }
    
    /**
     * Handle calendar tracking when user adds open house to calendar
     */
    public function handle_calendar_tracking() {
        // Verify nonce
        check_ajax_referer('mld_ajax_nonce', 'nonce');
        
        // Get submission data
        $property_mls = sanitize_text_field($_POST['mls_number'] ?? '');
        $property_address = sanitize_text_field($_POST['property_address'] ?? '');
        $open_house_date = sanitize_text_field($_POST['open_house_date'] ?? '');
        $open_house_time = sanitize_text_field($_POST['open_house_time'] ?? '');
        
        // Get user info if available
        $user_info = [];
        if (is_user_logged_in()) {
            $current_user = wp_get_current_user();
            $user_info = [
                'user_id' => $current_user->ID,
                'user_email' => $current_user->user_email,
                'user_name' => $current_user->display_name
            ];
        }
        
        // Check if calendar notifications are enabled
        $settings = get_option('mld_calendar_notification_settings', []);
        if (!isset($settings['enable_notifications']) || !$settings['enable_notifications']) {
            wp_send_json_success(['message' => 'Calendar event tracked']);
            return;
        }
        
        // Send email notification to admin
        $this->send_calendar_notification($property_mls, $property_address, $open_house_date, $open_house_time, $user_info);
        
        wp_send_json_success(['message' => 'Calendar event tracked and notification sent']);
    }
    
    /**
     * Send calendar notification email to admin
     */
    private function send_calendar_notification($property_mls, $property_address, $open_house_date, $open_house_time, $user_info = []) {
        // Get settings
        $settings = get_option('mld_calendar_notification_settings', []);
        
        // Get recipient email
        $to = $settings['notification_email'] ?? get_option('admin_email');
        if (!$to) {
            return;
        }
        
        // Prepare email subject
        $subject = 'Open House Calendar Event Added - ' . ($property_address ?: 'Property');
        
        // Prepare email body
        $message = "A user has added an open house event to their calendar.\n\n";
        
        $message .= "PROPERTY INFORMATION\n";
        $message .= "===================\n";
        $message .= "Property: " . ($property_address ?: 'Not specified') . "\n";
        if ($property_mls) {
            $message .= "MLS #: " . $property_mls . "\n";
            $message .= "View Property: " . home_url('/property/' . $property_mls . '/') . "\n";
        }
        $message .= "\n";
        
        $message .= "OPEN HOUSE DETAILS\n";
        $message .= "==================\n";
        $message .= "Date: " . $open_house_date . "\n";
        $message .= "Time: " . $open_house_time . "\n\n";
        
        if (!empty($user_info)) {
            $message .= "USER INFORMATION\n";
            $message .= "================\n";
            if (isset($user_info['user_name'])) {
                $message .= "Name: " . $user_info['user_name'] . "\n";
            }
            if (isset($user_info['user_email'])) {
                $message .= "Email: " . $user_info['user_email'] . "\n";
            }
            $message .= "\n";
        } else {
            $message .= "USER INFORMATION\n";
            $message .= "================\n";
            $message .= "Anonymous user (not logged in)\n\n";
        }
        
        $message .= "BROWSER INFORMATION\n";
        $message .= "==================\n";
        $message .= "IP Address: " . $_SERVER['REMOTE_ADDR'] . "\n";
        $message .= "User Agent: " . $_SERVER['HTTP_USER_AGENT'] . "\n\n";
        
        $message .= "---\n";
        $message .= "This notification was sent from " . get_bloginfo('name') . "\n";
        $message .= "Timestamp: " . current_time('mysql') . " (server time)";
        
        // Set headers
        $headers = [
            'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>'
        ];
        
        // Send email
        wp_mail($to, $subject, $message, $headers);
    }

    /**
     * Handle JavaScript error logging from client-side
     */
    public function handle_js_error_logging() {
        // Verify nonce for security
        if (!check_ajax_referer('bme_map_nonce', 'security', false)) {
            wp_send_json_error('Security check failed', 403);
            return;
        }

        // Sanitize input data
        $level = sanitize_text_field($_POST['level'] ?? 'ERROR');
        $message = sanitize_text_field($_POST['message'] ?? 'Unknown JavaScript error');
        $context = sanitize_text_field($_POST['context'] ?? '{}');
        $url = sanitize_url($_POST['url'] ?? '');
        $user_agent = sanitize_text_field($_POST['user_agent'] ?? '');

        // Parse context
        $context_data = json_decode($context, true) ?? [];
        if (!is_array($context_data)) {
            $context_data = [];
        }

        // Add additional context
        $context_data['url'] = $url;
        $context_data['user_agent'] = $user_agent;
        $context_data['user_id'] = get_current_user_id();
        $context_data['ip'] = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

        // Log the JavaScript error
        switch (strtoupper($level)) {
            case 'WARNING':
                MLD_Logger::warning("JavaScript: {$message}", $context_data);
                break;
            case 'INFO':
                MLD_Logger::info("JavaScript: {$message}", $context_data);
                break;
            case 'DEBUG':
                MLD_Logger::debug("JavaScript: {$message}", $context_data);
                break;
            default:
                MLD_Logger::error("JavaScript: {$message}", $context_data);
                break;
        }

        wp_send_json_success(['status' => 'logged']);
    }

    /**
     * Analyze query building for debugging zoom issues
     */
    public function analyze_query_callback() {
        $this->set_nocache_headers();
        check_ajax_referer('bme_map_nonce', 'security');

        // Test different scenarios
        $test_scenarios = [
            [
                'name' => 'No filters, zoom 14',
                'bounds' => ['north' => 42.55, 'south' => 42.48, 'east' => -71.08, 'west' => -71.13],
                'filters' => [],
                'zoom' => 14
            ],
            [
                'name' => 'Single city, zoom 14',
                'bounds' => ['north' => 42.55, 'south' => 42.48, 'east' => -71.08, 'west' => -71.13],
                'filters' => ['City' => ['Reading']],
                'zoom' => 14
            ],
            [
                'name' => 'Multiple cities, zoom 14',
                'bounds' => ['north' => 42.55, 'south' => 42.48, 'east' => -71.08, 'west' => -71.13],
                'filters' => ['City' => ['Reading', 'Wakefield']],
                'zoom' => 14
            ],
            [
                'name' => 'Multiple cities, zoom 15',
                'bounds' => ['north' => 42.55, 'south' => 42.48, 'east' => -71.08, 'west' => -71.13],
                'filters' => ['City' => ['Reading', 'Wakefield']],
                'zoom' => 15
            ]
        ];

        $analysis = [];

        foreach ($test_scenarios as $scenario) {
            // Get debug info without actually running the full query
            $debug_result = MLD_Query::get_listings_for_map(
                $scenario['bounds']['north'],
                $scenario['bounds']['south'],
                $scenario['bounds']['east'],
                $scenario['bounds']['west'],
                $scenario['filters'],
                false,
                true, // count_only to make it faster
                false,
                $scenario['zoom'],
                true // debug mode
            );

            $analysis[] = [
                'scenario' => $scenario['name'],
                'has_city_filter' => $debug_result['debug']['has_city_filter'] ?? false,
                'used_spatial_filter' => $debug_result['debug']['used_spatial_filter'] ?? false,
                'conditions_count' => count($debug_result['debug']['conditions'] ?? []),
                'total' => $debug_result['total']
            ];
        }

        wp_send_json_success([
            'analysis' => $analysis,
            'note' => 'Query analysis complete. Check has_city_filter and used_spatial_filter values.'
        ]);
    }

    /**
     * Load property preferences for saved searches
     * Returns user's liked/disliked properties and saved searches
     *
     * @since 4.3.0
     */
    public function load_property_preferences_callback() {
        $this->set_nocache_headers();
        // Check if user is logged in
        if (!is_user_logged_in()) {
            wp_send_json_success([
                'liked' => [],
                'disliked' => [],
                'saved_searches' => []
            ]);
            return;
        }

        $user_id = get_current_user_id();

        // Get liked and disliked properties from user meta
        $liked = get_user_meta($user_id, 'mld_liked_properties', true);
        $disliked = get_user_meta($user_id, 'mld_disliked_properties', true);

        // Get saved searches if the repository exists
        $saved_searches = [];
        if (class_exists('MLD_Search_Repository')) {
            $search_repo = new MLD_Search_Repository();
            $searches = $search_repo->get_user_searches($user_id);
            if ($searches && !is_wp_error($searches)) {
                $saved_searches = $searches;
            }
        }

        wp_send_json_success([
            'liked' => is_array($liked) ? $liked : [],
            'disliked' => is_array($disliked) ? $disliked : [],
            'saved_searches' => $saved_searches
        ]);
    }

    /**
     * Handle infinite scroll pagination for listing cards
     *
     * Returns rendered HTML cards for the next page of results.
     *
     * @since 6.11.21
     */
    public function load_more_cards_callback() {
        $this->set_nocache_headers();
        check_ajax_referer( 'mld_cards_nonce', 'security' );

        $page = isset( $_POST['page'] ) ? absint( $_POST['page'] ) : 1;
        $per_page = isset( $_POST['per_page'] ) ? min( absint( $_POST['per_page'] ), 50 ) : 12;
        $raw_filters = isset( $_POST['filters'] ) ? json_decode( wp_unslash( $_POST['filters'] ), true ) : [];
        $sort_by = isset( $_POST['sort_by'] ) ? sanitize_text_field( $_POST['sort_by'] ) : 'newest';

        // Filters from data attributes are already in provider format (converted by shortcode)
        // Just need to ensure sort is applied
        $filters = is_array( $raw_filters ) ? $raw_filters : [];
        $filters = $this->apply_cards_sort_order( $filters, $sort_by );

        // Calculate offset
        $offset = ( $page - 1 ) * $per_page;

        // Get data provider
        $provider = null;
        if ( class_exists( 'MLD_BME_Data_Provider' ) ) {
            $provider = MLD_BME_Data_Provider::get_instance();
        }

        if ( ! $provider || ! $provider->is_available() ) {
            wp_send_json_error( 'Data provider not available' );
            return;
        }

        // Get listings
        $listings = $provider->get_listings( $filters, $per_page, $offset );
        $total = $provider->get_listing_count( $filters );

        // Render HTML for each listing
        ob_start();
        foreach ( $listings as $listing ) {
            include MLD_PLUGIN_PATH . 'templates/partials/listing-card.php';
        }
        $html = ob_get_clean();

        wp_send_json_success([
            'html'     => $html,
            'count'    => count( $listings ),
            'total'    => $total,
            'has_more' => ( $page * $per_page ) < $total,
        ]);
    }

    /**
     * Handle admin preview for shortcode generator
     *
     * Returns sample listings for preview in the admin generator.
     *
     * @since 6.11.21
     */
    public function preview_listing_cards_callback() {
        $this->set_nocache_headers();
        check_ajax_referer( 'mld_generator_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
            return;
        }

        $raw_filters = isset( $_POST['filters'] ) ? json_decode( wp_unslash( $_POST['filters'] ), true ) : [];
        $limit = 6; // Preview shows 6 cards

        // Convert shortcode-style filter names to data provider filter names
        $filters = $this->convert_shortcode_filters_to_provider( $raw_filters );

        // Get data provider
        $provider = null;
        if ( class_exists( 'MLD_BME_Data_Provider' ) ) {
            $provider = MLD_BME_Data_Provider::get_instance();
        }

        if ( ! $provider || ! $provider->is_available() ) {
            wp_send_json_error( 'Data provider not available' );
            return;
        }

        // Get sample listings
        $listings = $provider->get_listings( $filters, $limit, 0 );
        $total = $provider->get_listing_count( $filters );

        // Format listings for preview
        $formatted_listings = array_map( function( $listing ) {
            $total_baths = ( $listing['bathrooms_full'] ?? 0 ) + ( ( $listing['bathrooms_half'] ?? 0 ) * 0.5 );
            $address = trim( sprintf( '%s %s', $listing['street_number'] ?? '', $listing['street_name'] ?? '' ) );

            return [
                'listing_id'  => $listing['listing_id'] ?? '',
                'address'     => $address,
                'city'        => $listing['city'] ?? '',
                'state'       => $listing['state_or_province'] ?? 'MA',
                'price'       => $listing['list_price'] ?? 0,
                'beds'        => $listing['bedrooms_total'] ?? 0,
                'baths'       => $total_baths,
                'sqft'        => $listing['living_area'] ?? 0,
                'photo_url'   => $listing['photo_url'] ?? '',
                'status'      => $listing['standard_status'] ?? 'Active',
            ];
        }, $listings );

        wp_send_json_success([
            'listings' => $formatted_listings,
            'total'    => $total,
        ]);
    }

    /**
     * Apply sort order to filters for listing cards
     *
     * @param array  $filters Current filters
     * @param string $sort_by Sort option
     * @return array Filters with sort applied
     * @since 6.11.21
     */
    private function apply_cards_sort_order( $filters, $sort_by ) {
        switch ( $sort_by ) {
            case 'price_asc':
                $filters['orderby'] = 'list_price';
                $filters['order'] = 'ASC';
                break;
            case 'price_desc':
                $filters['orderby'] = 'list_price';
                $filters['order'] = 'DESC';
                break;
            case 'oldest':
                $filters['orderby'] = 'modification_timestamp';
                $filters['order'] = 'ASC';
                break;
            case 'newest':
            default:
                $filters['orderby'] = 'modification_timestamp';
                $filters['order'] = 'DESC';
                break;
        }
        return $filters;
    }

    /**
     * Convert shortcode-style filter names to data provider format
     *
     * Shortcode attributes use different naming than the data provider.
     * This method maps between them for consistency.
     *
     * @param array $raw_filters Filters from shortcode generator UI
     * @return array Filters in data provider format
     * @since 6.11.22
     */
    private function convert_shortcode_filters_to_provider( $raw_filters ) {
        if ( ! is_array( $raw_filters ) ) {
            return [];
        }

        $filters = [];

        // Direct mappings (same name)
        $direct_keys = [
            'status', 'city', 'postal_code', 'street_name', 'listing_id',
            'neighborhood', 'property_type', 'home_type', 'structure_type',
            'architectural_style', 'lot_size_min', 'lot_size_max',
            'year_built_min', 'year_built_max', 'garage_spaces_min',
            // Boolean amenity filters
            'has_pool', 'has_fireplace', 'has_basement', 'pet_friendly',
            'waterfront', 'view', 'spa', 'has_hoa', 'senior_community', 'horse_property',
            'open_house_only',
            // Agent filters
            'agent_ids', 'listing_agent_id', 'buyer_agent_id',
            'orderby', 'order'
        ];

        foreach ( $direct_keys as $key ) {
            if ( isset( $raw_filters[ $key ] ) && $raw_filters[ $key ] !== '' ) {
                $filters[ $key ] = $raw_filters[ $key ];
            }
        }

        // Convert comma-separated string filters to arrays (for multi-value support)
        // Note: home_type, structure_type, architectural_style come as arrays from JS multi-select
        $array_filters = [ 'agent_ids', 'city', 'postal_code', 'listing_id', 'status',
                           'home_type', 'structure_type', 'architectural_style' ];
        foreach ( $array_filters as $key ) {
            if ( isset( $filters[ $key ] ) && is_string( $filters[ $key ] ) && strpos( $filters[ $key ], ',' ) !== false ) {
                $filters[ $key ] = array_map( 'sanitize_text_field', array_map( 'trim', explode( ',', $filters[ $key ] ) ) );
            }
        }

        // Renamed mappings (shortcode name => provider name)
        $renamed_keys = [
            'price_min' => 'min_price',
            'price_max' => 'max_price',
            'sqft_min' => 'min_sqft',
            'sqft_max' => 'max_sqft',
            'baths_min' => 'min_baths',
        ];

        foreach ( $renamed_keys as $from => $to ) {
            if ( isset( $raw_filters[ $from ] ) && $raw_filters[ $from ] !== '' ) {
                $filters[ $to ] = $raw_filters[ $from ];
            }
        }

        // Handle beds specially (can be "3+" format)
        if ( isset( $raw_filters['beds'] ) && $raw_filters['beds'] !== '' ) {
            $beds_value = $raw_filters['beds'];
            if ( strpos( $beds_value, '+' ) !== false ) {
                $filters['min_beds'] = absint( str_replace( '+', '', $beds_value ) );
            } else {
                // For specific values, use the minimum
                $bed_values = array_map( 'absint', explode( ',', $beds_value ) );
                $filters['min_beds'] = min( $bed_values );
            }
        }

        // Apply sort order if present
        if ( isset( $raw_filters['sort_by'] ) ) {
            $filters = $this->apply_cards_sort_order( $filters, $raw_filters['sort_by'] );
        }

        return $filters;
    }

    /**
     * Handle universal contact form submission (v6.21.0)
     *
     * @since 6.21.0
     */
    public function handle_universal_contact_form() {
        // Verify nonce
        if (!check_ajax_referer('mld_contact_form_nonce', 'security', false)) {
            wp_send_json_error([
                'message' => __('Security verification failed. Please refresh the page and try again.', 'mls-listings-display')
            ]);
            return;
        }

        // Get form ID
        $form_id = isset($_POST['form_id']) ? absint($_POST['form_id']) : 0;
        if (!$form_id) {
            wp_send_json_error([
                'message' => __('Invalid form submission.', 'mls-listings-display')
            ]);
            return;
        }

        // Load required classes
        $this->load_contact_form_classes();

        // Get form configuration
        $manager = MLD_Contact_Form_Manager::get_instance();
        $form = $manager->get_form($form_id);

        if (!$form || $form->status !== 'active') {
            wp_send_json_error([
                'message' => __('This form is no longer available.', 'mls-listings-display')
            ]);
            return;
        }

        // Check honeypot
        if (!empty($_POST['mld_cf_hp'])) {
            // Silently fail for bots but appear successful
            wp_send_json_success([
                'message' => $this->get_form_success_message($form)
            ]);
            return;
        }

        // Validate submission
        $validator = new MLD_Contact_Form_Validator();
        $fields = isset($form->fields['fields']) ? $form->fields['fields'] : [];
        $is_valid = $validator->validate($fields, $_POST);

        if (!$is_valid) {
            wp_send_json_error([
                'message' => __('Please correct the errors below.', 'mls-listings-display'),
                'errors' => $validator->get_errors()
            ]);
            return;
        }

        // Sanitize data
        $sanitized_data = $validator->sanitize_data($fields, $_POST);

        // Add metadata
        $sanitized_data['_page_url'] = isset($_POST['_page_url']) ? esc_url_raw($_POST['_page_url']) : '';
        $sanitized_data['_user_agent'] = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field($_SERVER['HTTP_USER_AGENT']) : '';
        $sanitized_data['_ip_address'] = $this->get_client_ip();

        // Save submission to database
        $submission_id = $this->save_contact_form_submission($form_id, $sanitized_data, $form);

        if (!$submission_id) {
            wp_send_json_error([
                'message' => __('There was an error saving your submission. Please try again.', 'mls-listings-display')
            ]);
            return;
        }

        // Send notifications
        $notifications = new MLD_Contact_Form_Notifications($form, $sanitized_data);
        $notifications->send_all_notifications();

        // Increment submission count
        $manager->increment_submission_count($form_id);

        // Get success response
        $settings = isset($form->settings) ?
            (is_array($form->settings) ? $form->settings : json_decode($form->settings, true)) :
            [];

        $response = [
            'message' => $this->get_form_success_message($form)
        ];

        // Add redirect if configured
        if (!empty($settings['redirect_url'])) {
            $response['redirect'] = esc_url($settings['redirect_url']);
        }

        wp_send_json_success($response);
    }

    /**
     * Save contact form callback (admin)
     *
     * @since 6.21.0
     */
    public function save_contact_form_callback() {
        // Verify nonce
        if (!check_ajax_referer('mld_contact_form_admin', 'nonce', false)) {
            wp_send_json_error(['message' => __('Security verification failed.', 'mls-listings-display')]);
            return;
        }

        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('You do not have permission to perform this action.', 'mls-listings-display')]);
            return;
        }

        // Load manager
        $this->load_contact_form_classes();
        $manager = MLD_Contact_Form_Manager::get_instance();

        // Get form data - handle both flat data and nested form_data
        $form_id = isset($_POST['form_id']) ? absint($_POST['form_id']) : 0;

        // Check if data is sent as nested form_data or flat
        if (isset($_POST['form_data']) && is_array($_POST['form_data'])) {
            $form_data = $_POST['form_data'];
        } else {
            // Flat data format from JavaScript
            $form_data = [
                'form_name' => isset($_POST['form_name']) ? $_POST['form_name'] : '',
                'description' => isset($_POST['description']) ? $_POST['description'] : '',
                'fields' => isset($_POST['fields']) ? $_POST['fields'] : '',
                'settings' => isset($_POST['settings']) ? $_POST['settings'] : '',
                'notification_settings' => isset($_POST['notification_settings']) ? $_POST['notification_settings'] : '',
                'status' => isset($_POST['status']) ? $_POST['status'] : 'draft',
            ];
        }

        $form_name = isset($form_data['form_name']) ? sanitize_text_field($form_data['form_name']) : '';
        if (empty($form_name)) {
            wp_send_json_error(['message' => __('Form name is required.', 'mls-listings-display')]);
            return;
        }

        // Parse JSON strings if needed
        $fields = $form_data['fields'];
        if (is_string($fields)) {
            $fields = json_decode(stripslashes($fields), true);
        }
        if (!is_array($fields)) {
            $fields = ['fields' => []];
        }

        $settings = $form_data['settings'];
        if (is_string($settings)) {
            $settings = json_decode(stripslashes($settings), true);
        }
        if (!is_array($settings)) {
            $settings = [];
        }

        $notification_settings = $form_data['notification_settings'];
        if (is_string($notification_settings)) {
            $notification_settings = json_decode(stripslashes($notification_settings), true);
        }
        if (!is_array($notification_settings)) {
            $notification_settings = [];
        }

        // Sanitize form data
        $sanitized = [
            'form_name' => $form_name,
            'description' => isset($form_data['description']) ? sanitize_textarea_field($form_data['description']) : '',
            'fields' => $fields,
            'settings' => $settings,
            'notification_settings' => $notification_settings,
            'status' => isset($form_data['status']) ? sanitize_text_field($form_data['status']) : 'draft',
        ];

        // Save or update
        if ($form_id > 0) {
            $result = $manager->update_form($form_id, $sanitized);
            if ($result) {
                wp_send_json_success([
                    'message' => __('Form saved successfully.', 'mls-listings-display'),
                    'form_id' => $form_id,
                    'shortcode' => $manager->generate_shortcode($form_id)
                ]);
            } else {
                wp_send_json_error(['message' => __('Failed to update form.', 'mls-listings-display')]);
            }
        } else {
            $new_id = $manager->create_form($sanitized);
            if ($new_id) {
                wp_send_json_success([
                    'message' => __('Form created successfully.', 'mls-listings-display'),
                    'form_id' => $new_id,
                    'shortcode' => $manager->generate_shortcode($new_id),
                    'redirect' => admin_url('admin.php?page=mld_contact_forms&action=edit&form_id=' . $new_id)
                ]);
            } else {
                wp_send_json_error(['message' => __('Failed to create form.', 'mls-listings-display')]);
            }
        }
    }

    /**
     * Get contact form callback (admin)
     *
     * @since 6.21.0
     */
    public function get_contact_form_callback() {
        // Verify nonce
        if (!check_ajax_referer('mld_contact_form_admin', 'nonce', false)) {
            wp_send_json_error(['message' => __('Security verification failed.', 'mls-listings-display')]);
            return;
        }

        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('You do not have permission to perform this action.', 'mls-listings-display')]);
            return;
        }

        $form_id = isset($_POST['form_id']) ? absint($_POST['form_id']) : 0;
        if (!$form_id) {
            wp_send_json_error(['message' => __('Invalid form ID.', 'mls-listings-display')]);
            return;
        }

        // Load manager
        $this->load_contact_form_classes();
        $manager = MLD_Contact_Form_Manager::get_instance();

        $form = $manager->get_form($form_id);
        if (!$form) {
            wp_send_json_error(['message' => __('Form not found.', 'mls-listings-display')]);
            return;
        }

        wp_send_json_success([
            'form' => [
                'id' => $form->id,
                'form_name' => $form->form_name,
                'form_slug' => $form->form_slug,
                'description' => $form->description,
                'fields' => $form->fields,
                'settings' => $form->settings,
                'notification_settings' => $form->notification_settings,
                'status' => $form->status,
            ]
        ]);
    }

    /**
     * Load contact form classes
     *
     * @since 6.21.0
     */
    private function load_contact_form_classes() {
        $classes = [
            'class-mld-contact-form-manager.php',
            'class-mld-contact-form-validator.php',
            'class-mld-contact-form-notifications.php',
        ];

        foreach ($classes as $class) {
            $path = MLD_PLUGIN_PATH . 'includes/contact-forms/' . $class;
            if (file_exists($path) && !class_exists(str_replace(['-', '.php'], ['_', ''], 'MLD_Contact_Form_' . $class))) {
                require_once $path;
            }
        }
    }

    /**
     * Save contact form submission to database
     *
     * @param int    $form_id Form ID
     * @param array  $data    Sanitized submission data
     * @param object $form    Form object
     * @return int|false Submission ID or false
     * @since 6.21.0
     */
    private function save_contact_form_submission($form_id, $data, $form) {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_form_submissions';

        // Extract common fields if they exist
        $email = '';
        $phone = '';
        $first_name = '';
        $last_name = '';
        $message = '';

        $fields = isset($form->fields['fields']) ? $form->fields['fields'] : [];
        foreach ($fields as $field) {
            $field_id = $field['id'];
            $value = isset($data[$field_id]) ? $data[$field_id] : '';

            if ($field['type'] === 'email' && !$email) {
                $email = $value;
            } elseif ($field['type'] === 'phone' && !$phone) {
                $phone = $value;
            } elseif ($field['type'] === 'textarea' && !$message) {
                $message = is_array($value) ? implode(', ', $value) : $value;
            } elseif ($field['type'] === 'text') {
                $label_lower = strtolower($field['label']);
                if (strpos($label_lower, 'first') !== false && strpos($label_lower, 'name') !== false && !$first_name) {
                    $first_name = $value;
                } elseif (strpos($label_lower, 'last') !== false && strpos($label_lower, 'name') !== false && !$last_name) {
                    $last_name = $value;
                }
            }
        }

        $result = $wpdb->insert(
            $table,
            [
                'form_type' => 'universal_contact',
                'form_id' => $form_id,
                'first_name' => $first_name,
                'last_name' => $last_name,
                'email' => $email,
                'phone' => $phone,
                'message' => $message,
                'form_data' => wp_json_encode($data),
                'ip_address' => isset($data['_ip_address']) ? $data['_ip_address'] : '',
                'user_agent' => isset($data['_user_agent']) ? $data['_user_agent'] : '',
                'created_at' => current_time('mysql'),
            ],
            ['%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );

        return $result ? $wpdb->insert_id : false;
    }

    /**
     * Get form success message
     *
     * @param object $form Form object
     * @return string Success message
     * @since 6.21.0
     */
    private function get_form_success_message($form) {
        $settings = isset($form->settings) ?
            (is_array($form->settings) ? $form->settings : json_decode($form->settings, true)) :
            [];

        return !empty($settings['success_message'])
            ? $settings['success_message']
            : __('Thank you! Your message has been sent successfully.', 'mls-listings-display');
    }

    /**
     * Get client IP address
     *
     * @return string IP address
     * @since 6.21.0
     */
    private function get_client_ip() {
        $ip_keys = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR'];

        foreach ($ip_keys as $key) {
            if (array_key_exists($key, $_SERVER) === true) {
                foreach (explode(',', $_SERVER[$key]) as $ip) {
                    $ip = trim($ip);
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                        return $ip;
                    }
                }
            }
        }

        return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
    }

}
