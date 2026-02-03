<?php
/**
 * MLD Public Analytics REST API
 *
 * REST endpoints for receiving tracking events and heartbeats.
 * Also provides admin endpoints for dashboard data.
 *
 * @package MLS_Listings_Display
 * @subpackage Analytics
 * @since 6.39.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class MLD_Public_Analytics_REST_API
 *
 * Registers and handles REST API endpoints for analytics.
 */
class MLD_Public_Analytics_REST_API {

    /**
     * API namespace
     */
    const NAMESPACE = 'mld-analytics/v1';

    /**
     * Rate limit: requests per minute per session
     */
    const RATE_LIMIT = 100;

    /**
     * Rate limit window in seconds
     */
    const RATE_LIMIT_WINDOW = 60;

    /**
     * Singleton instance
     *
     * @var MLD_Public_Analytics_REST_API
     */
    private static $instance = null;

    /**
     * Tracker instance
     *
     * @var MLD_Public_Analytics_Tracker
     */
    private $tracker;

    /**
     * Database instance
     *
     * @var MLD_Public_Analytics_Database
     */
    private $db;

    /**
     * Get singleton instance
     *
     * @return MLD_Public_Analytics_REST_API
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
        $this->tracker = MLD_Public_Analytics_Tracker::get_instance();
        $this->db = MLD_Public_Analytics_Database::get_instance();
    }

    /**
     * Initialize REST API
     */
    public static function init() {
        add_action('rest_api_init', array(self::get_instance(), 'register_routes'));
    }

    /**
     * Register REST routes
     */
    public function register_routes() {
        // Public tracking endpoints (no auth required, uses nonce)
        register_rest_route(self::NAMESPACE, '/track', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'handle_track'),
            'permission_callback' => '__return_true', // Public endpoint
        ));

        register_rest_route(self::NAMESPACE, '/heartbeat', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'handle_heartbeat'),
            'permission_callback' => '__return_true', // Public endpoint
        ));

        // Admin dashboard endpoints (requires manage_options)
        register_rest_route(self::NAMESPACE, '/admin/realtime', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'handle_admin_realtime'),
            'permission_callback' => array($this, 'check_admin_permission'),
        ));

        register_rest_route(self::NAMESPACE, '/admin/stats', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'handle_admin_stats'),
            'permission_callback' => array($this, 'check_admin_permission'),
            'args'                => array(
                'start_date' => array(
                    'required'          => false,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'default'           => date('Y-m-d', strtotime('-7 days')),
                ),
                'end_date' => array(
                    'required'          => false,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'default'           => date('Y-m-d'),
                ),
                'platform' => array(
                    'required'          => false,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
            ),
        ));

        register_rest_route(self::NAMESPACE, '/admin/trends', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'handle_admin_trends'),
            'permission_callback' => array($this, 'check_admin_permission'),
            'args'                => array(
                'range' => array(
                    'required'          => false,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'default'           => '7d',
                ),
                'start_date' => array(
                    'required'          => false,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'end_date' => array(
                    'required'          => false,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'granularity' => array(
                    'required'          => false,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'enum'              => array('hourly', 'daily'),
                ),
            ),
        ));

        register_rest_route(self::NAMESPACE, '/admin/activity-stream', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'handle_admin_activity_stream'),
            'permission_callback' => array($this, 'check_admin_permission'),
            'args'                => array(
                'limit' => array(
                    'required'          => false,
                    'type'              => 'integer',
                    'sanitize_callback' => 'absint',
                    'default'           => 50,
                ),
                'page' => array(
                    'required'          => false,
                    'type'              => 'integer',
                    'sanitize_callback' => 'absint',
                    'default'           => 1,
                ),
                'range' => array(
                    'required'          => false,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'default'           => '15m',
                ),
                'platform' => array(
                    'required'          => false,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'logged_in_only' => array(
                    'required'          => false,
                    'type'              => 'boolean',
                    'default'           => false,
                ),
                'event_type' => array(
                    'required'          => false,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
            ),
        ));

        // Session journey endpoint (v6.47.0)
        register_rest_route(self::NAMESPACE, '/admin/session/(?P<session_id>[a-zA-Z0-9-]+)/journey', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'handle_admin_session_journey'),
            'permission_callback' => array($this, 'check_admin_permission'),
            'args'                => array(
                'session_id' => array(
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
            ),
        ));

        register_rest_route(self::NAMESPACE, '/admin/top-content', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'handle_admin_top_content'),
            'permission_callback' => array($this, 'check_admin_permission'),
            'args'                => array(
                'start_date' => array(
                    'required'          => false,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'default'           => date('Y-m-d', strtotime('-7 days')),
                ),
                'end_date' => array(
                    'required'          => false,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'default'           => date('Y-m-d'),
                ),
                'type' => array(
                    'required'          => false,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'default'           => 'pages',
                    'enum'              => array('pages', 'properties'),
                ),
                'limit' => array(
                    'required'          => false,
                    'type'              => 'integer',
                    'sanitize_callback' => 'absint',
                    'default'           => 10,
                ),
            ),
        ));

        register_rest_route(self::NAMESPACE, '/admin/traffic-sources', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'handle_admin_traffic_sources'),
            'permission_callback' => array($this, 'check_admin_permission'),
            'args'                => array(
                'start_date' => array(
                    'required'          => false,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'default'           => date('Y-m-d', strtotime('-7 days')),
                ),
                'end_date' => array(
                    'required'          => false,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'default'           => date('Y-m-d'),
                ),
            ),
        ));

        register_rest_route(self::NAMESPACE, '/admin/geographic', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'handle_admin_geographic'),
            'permission_callback' => array($this, 'check_admin_permission'),
            'args'                => array(
                'start_date' => array(
                    'required'          => false,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'default'           => date('Y-m-d', strtotime('-7 days')),
                ),
                'end_date' => array(
                    'required'          => false,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'default'           => date('Y-m-d'),
                ),
                'level' => array(
                    'required'          => false,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'default'           => 'city',
                    'enum'              => array('country', 'city'),
                ),
                'type' => array(
                    'required'          => false,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'default'           => 'cities',
                    'enum'              => array('cities', 'countries'),
                ),
            ),
        ));

        register_rest_route(self::NAMESPACE, '/admin/db-stats', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'handle_admin_db_stats'),
            'permission_callback' => array($this, 'check_admin_permission'),
        ));

        // Top Searches endpoint (v6.54.0)
        register_rest_route(self::NAMESPACE, '/admin/top-searches', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'handle_admin_top_searches'),
            'permission_callback' => array($this, 'check_admin_permission'),
            'args'                => array(
                'start_date' => array(
                    'required'          => false,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'default'           => date('Y-m-d', strtotime('-7 days')),
                ),
                'end_date' => array(
                    'required'          => false,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'default'           => date('Y-m-d'),
                ),
                'limit' => array(
                    'required'          => false,
                    'type'              => 'integer',
                    'sanitize_callback' => 'absint',
                    'default'           => 20,
                ),
            ),
        ));
    }

    /**
     * Check admin permission
     *
     * @return bool
     */
    public function check_admin_permission() {
        return current_user_can('manage_options');
    }

    /**
     * Handle tracking request
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public function handle_track($request) {
        $body = $request->get_json_params();

        // Validate session ID
        $session_id = sanitize_text_field($body['session_id'] ?? '');
        if (empty($session_id)) {
            return new WP_REST_Response(array(
                'success' => false,
                'error'   => 'Missing session_id',
            ), 400);
        }

        // Rate limiting
        if ($this->is_rate_limited($session_id)) {
            return new WP_REST_Response(array(
                'success' => false,
                'error'   => 'Rate limit exceeded',
            ), 429);
        }

        // Validate events
        $events = $body['events'] ?? array();
        if (!is_array($events) || empty($events)) {
            return new WP_REST_Response(array(
                'success' => false,
                'error'   => 'No events provided',
            ), 400);
        }

        // Limit batch size
        if (count($events) > 50) {
            $events = array_slice($events, 0, 50);
        }

        // Sanitize events
        $sanitized_events = array_map(array($this, 'sanitize_event'), $events);

        // Session data
        $session_data = array(
            'visitor_hash'  => sanitize_text_field($body['visitor_hash'] ?? $body['session_data']['visitor_hash'] ?? ''),
            'user_id'       => absint($body['session_data']['user_id'] ?? 0),
            'referrer'      => esc_url_raw($body['session_data']['referrer'] ?? ''),
            'utm_source'    => sanitize_text_field($body['session_data']['utm_source'] ?? ''),
            'utm_medium'    => sanitize_text_field($body['session_data']['utm_medium'] ?? ''),
            'utm_campaign'  => sanitize_text_field($body['session_data']['utm_campaign'] ?? ''),
            'utm_term'      => sanitize_text_field($body['session_data']['utm_term'] ?? ''),
            'utm_content'   => sanitize_text_field($body['session_data']['utm_content'] ?? ''),
            'screen_width'  => absint($body['session_data']['screen_width'] ?? 0),
            'screen_height' => absint($body['session_data']['screen_height'] ?? 0),
        );

        // Process events
        $result = $this->tracker->process_events($session_id, $sanitized_events, $session_data);

        // Record rate limit
        $this->record_rate_limit($session_id);

        return new WP_REST_Response($result, $result['success'] ? 200 : 500);
    }

    /**
     * Handle heartbeat request
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public function handle_heartbeat($request) {
        $body = $request->get_json_params();

        $session_id = sanitize_text_field($body['session_id'] ?? '');
        if (empty($session_id)) {
            return new WP_REST_Response(array(
                'success' => false,
                'error'   => 'Missing session_id',
            ), 400);
        }

        $data = array(
            'page_url'   => esc_url_raw($body['page_url'] ?? ''),
            'page_type'  => sanitize_text_field($body['page_type'] ?? ''),
            'listing_id' => sanitize_text_field($body['listing_id'] ?? ''),
        );

        $result = $this->tracker->process_heartbeat($session_id, $data);

        return new WP_REST_Response($result, 200);
    }

    /**
     * Handle admin realtime request
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public function handle_admin_realtime($request) {
        $data = $this->db->get_realtime_data();

        return new WP_REST_Response(array(
            'success' => true,
            'data'    => $data,
        ), 200);
    }

    /**
     * Handle admin stats request
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public function handle_admin_stats($request) {
        $start_date = $request->get_param('start_date');
        $end_date = $request->get_param('end_date');
        $platform = $request->get_param('platform');

        $stats = $this->db->get_stats($start_date, $end_date, $platform);

        return new WP_REST_Response(array(
            'success' => true,
            'data'    => $stats,
        ), 200);
    }

    /**
     * Handle admin trends request
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public function handle_admin_trends($request) {
        // Handle range parameter (v6.45.6 fix - JS sends range like '24h', '7d', '30d')
        $range = $request->get_param('range');
        $start_date = $request->get_param('start_date');
        $end_date = $request->get_param('end_date');
        $granularity = $request->get_param('granularity');

        // Convert range to start/end dates using WordPress timezone
        $wp_timestamp = current_time('timestamp');
        $today = date('Y-m-d', $wp_timestamp);

        if (!empty($range) && empty($start_date)) {
            switch ($range) {
                case '24h':
                    $start_date = date('Y-m-d', $wp_timestamp - 86400);
                    $end_date = $today;
                    $granularity = 'hourly';
                    break;
                case '7d':
                    $start_date = date('Y-m-d', $wp_timestamp - (7 * 86400));
                    $end_date = $today;
                    $granularity = 'daily';
                    break;
                case '30d':
                    $start_date = date('Y-m-d', $wp_timestamp - (30 * 86400));
                    $end_date = $today;
                    $granularity = 'daily';
                    break;
                case 'today':
                    $start_date = $today;
                    $end_date = $today;
                    $granularity = 'hourly';
                    break;
                default:
                    // Default to 7 days
                    $start_date = date('Y-m-d', $wp_timestamp - (7 * 86400));
                    $end_date = $today;
                    $granularity = 'daily';
            }
        }

        // Fallback defaults using WordPress time
        if (empty($start_date)) {
            $start_date = date('Y-m-d', $wp_timestamp - (7 * 86400));
        }
        if (empty($end_date)) {
            $end_date = $today;
        }
        if (empty($granularity)) {
            $granularity = 'daily';
        }

        $trends = $this->db->get_trends($start_date, $end_date, $granularity);

        // Transform data for Chart.js format (v6.45.5 fix)
        $labels = array();
        $sessions = array();
        $page_views = array();

        foreach ($trends as $row) {
            $timestamp = $row->timestamp ?? '';
            // Format label based on granularity
            if ($granularity === 'hourly' && $timestamp) {
                $labels[] = date('g:i A', strtotime($timestamp));
            } else if ($timestamp) {
                $labels[] = date('M j', strtotime($timestamp));
            }
            $sessions[] = (int) ($row->unique_sessions ?? 0);
            $page_views[] = (int) ($row->page_views ?? 0);
        }

        return new WP_REST_Response(array(
            'success' => true,
            'data'    => array(
                'labels'     => $labels,
                'sessions'   => $sessions,
                'page_views' => $page_views,
                'raw'        => $trends, // Include raw data for debugging
            ),
        ), 200);
    }

    /**
     * Handle admin activity stream request
     *
     * v6.47.0: Enhanced with filtering, pagination, and user/referrer data
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public function handle_admin_activity_stream($request) {
        $limit = min($request->get_param('limit'), 100);
        $page = max($request->get_param('page'), 1);
        $range = $request->get_param('range');
        $platform = $request->get_param('platform');
        $logged_in_only = (bool) $request->get_param('logged_in_only');
        $event_type = $request->get_param('event_type');

        // Convert range to start/end dates using UTC (events are stored in UTC)
        // v6.47.1 fix: Use time() instead of current_time() to match database timestamps
        $utc_timestamp = time();
        $end_date = gmdate('Y-m-d H:i:s', $utc_timestamp);

        switch ($range) {
            case '15m':
                $start_date = gmdate('Y-m-d H:i:s', $utc_timestamp - (15 * 60));
                break;
            case '1h':
                $start_date = gmdate('Y-m-d H:i:s', $utc_timestamp - (60 * 60));
                break;
            case '4h':
                $start_date = gmdate('Y-m-d H:i:s', $utc_timestamp - (4 * 60 * 60));
                break;
            case '24h':
                $start_date = gmdate('Y-m-d H:i:s', $utc_timestamp - (24 * 60 * 60));
                break;
            case '7d':
                $start_date = gmdate('Y-m-d H:i:s', $utc_timestamp - (7 * 24 * 60 * 60));
                break;
            default:
                // Default to 15 minutes for live view
                $start_date = gmdate('Y-m-d H:i:s', $utc_timestamp - (15 * 60));
        }

        // Calculate offset from page
        $offset = ($page - 1) * $limit;

        // Build args for enhanced query
        $args = array(
            'limit'          => $limit,
            'offset'         => $offset,
            'start_date'     => $start_date,
            'end_date'       => $end_date,
            'platform'       => $platform,
            'logged_in_only' => $logged_in_only,
            'event_type'     => $event_type,
        );

        // Get enhanced activity stream with user info and pagination
        $result = $this->db->get_activity_stream_enhanced($args);

        return new WP_REST_Response(array(
            'success' => true,
            'data'    => array(
                'events'     => $result['events'] ?? array(),
                'total'      => $result['total'] ?? 0,
                'page'       => $result['page'] ?? 1,
                'per_page'   => $result['per_page'] ?? $limit,
                'has_more'   => $result['has_more'] ?? false,
                'range'      => $range,
                'start_date' => $start_date,
                'end_date'   => $end_date,
            ),
        ), 200);
    }

    /**
     * Handle admin session journey request
     *
     * v6.47.0: Returns all events for a session in chronological order
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public function handle_admin_session_journey($request) {
        $session_id = $request->get_param('session_id');

        if (empty($session_id)) {
            return new WP_REST_Response(array(
                'success' => false,
                'error'   => 'Missing session_id',
            ), 400);
        }

        $journey = $this->db->get_session_journey($session_id);

        if (empty($journey)) {
            return new WP_REST_Response(array(
                'success' => false,
                'error'   => 'Session not found',
            ), 404);
        }

        return new WP_REST_Response(array(
            'success' => true,
            'data'    => $journey,
        ), 200);
    }

    /**
     * Handle admin top content request
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public function handle_admin_top_content($request) {
        $start_date = $request->get_param('start_date');
        $end_date = $request->get_param('end_date');
        $type = $request->get_param('type');
        $limit = min($request->get_param('limit'), 50);

        $content = $this->db->get_top_content($start_date, $end_date, $type, $limit);

        return new WP_REST_Response(array(
            'success' => true,
            'data'    => $content,
        ), 200);
    }

    /**
     * Handle admin traffic sources request
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public function handle_admin_traffic_sources($request) {
        $start_date = $request->get_param('start_date');
        $end_date = $request->get_param('end_date');

        // v6.46.0: Increased default limit from 10 to 30 for more traffic source visibility
        $limit = absint($request->get_param('limit')) ?: 30;
        $sources = $this->db->get_traffic_sources($start_date, $end_date, $limit);

        return new WP_REST_Response(array(
            'success' => true,
            'data'    => $sources,
        ), 200);
    }

    /**
     * Handle admin geographic request
     *
     * v6.46.0: Increased default limit from 10 to 50 for more comprehensive geographic data
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public function handle_admin_geographic($request) {
        $start_date = $request->get_param('start_date') ?: date('Y-m-d', strtotime('-7 days'));
        $end_date = $request->get_param('end_date') ?: date('Y-m-d');

        // Accept both 'level' and 'type' parameters (JS sends 'type')
        $level = $request->get_param('level') ?: $request->get_param('type');

        // Map plural JS values to singular database values
        if ($level === 'cities') {
            $level = 'city';
        } elseif ($level === 'countries') {
            $level = 'country';
        }
        $level = $level ?: 'city'; // Default to city

        // v6.46.0: Increased limit from 10 to 50 for better geographic distribution visibility
        $limit = absint($request->get_param('limit')) ?: 50;
        $geo = $this->db->get_geographic_data($start_date, $end_date, $level, $limit);

        return new WP_REST_Response(array(
            'success' => true,
            'data'    => $geo,
        ), 200);
    }

    /**
     * Handle admin database stats request
     *
     * Returns stats in flat format for JS dashboard:
     * - sessions, events, hourly, daily (table counts)
     * - platforms (breakdown by platform)
     * - devices (breakdown by device type)
     * - browsers (top 5 browsers)
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public function handle_admin_db_stats($request) {
        $stats = $this->db->get_db_stats();
        $geo_status = MLD_Geolocation_Service::get_instance()->get_status();

        // Flatten stats for JS dashboard (expects data.sessions, data.platforms, etc.)
        $response_data = array(
            'sessions'    => $stats['sessions'] ?? 0,
            'events'      => $stats['events'] ?? 0,
            'hourly'      => $stats['hourly'] ?? 0,
            'daily'       => $stats['daily'] ?? 0,
            'presence'    => $stats['presence'] ?? 0,
            'platforms'   => $stats['platforms'] ?? array(),
            'devices'     => $stats['devices'] ?? array(),
            'browsers'    => $stats['browsers'] ?? array(),
            'geolocation' => $geo_status,
        );

        return new WP_REST_Response(array(
            'success' => true,
            'data'    => $response_data,
        ), 200);
    }

    /**
     * Handle admin top searches request
     *
     * v6.54.0: Returns aggregated top search queries
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public function handle_admin_top_searches($request) {
        $start_date = $request->get_param('start_date');
        $end_date = $request->get_param('end_date');
        $limit = min($request->get_param('limit'), 50);

        $aggregator = MLD_Public_Analytics_Aggregator::get_instance();
        $searches = $aggregator->get_top_searches($start_date, $end_date, $limit);

        // Calculate total searches for percentage
        $total = 0;
        foreach ($searches as $search) {
            $total += $search['count'];
        }

        // Add percentage to each result
        foreach ($searches as &$search) {
            $search['percentage'] = $total > 0 ? round(($search['count'] / $total) * 100, 1) : 0;
        }

        return new WP_REST_Response(array(
            'success' => true,
            'data'    => array(
                'searches'   => $searches,
                'total'      => $total,
                'start_date' => $start_date,
                'end_date'   => $end_date,
            ),
        ), 200);
    }

    /**
     * Sanitize event data
     *
     * @param array $event Raw event data
     * @return array Sanitized event
     */
    private function sanitize_event($event) {
        return array(
            'type'                 => sanitize_text_field($event['type'] ?? 'unknown'),
            'category'             => sanitize_text_field($event['category'] ?? ''),
            'timestamp'            => sanitize_text_field($event['timestamp'] ?? ''),
            'page_url'             => esc_url_raw($event['page_url'] ?? ''),
            'page_path'            => sanitize_text_field($event['page_path'] ?? ''),
            'page_title'           => sanitize_text_field(substr($event['page_title'] ?? '', 0, 255)),
            'page_type'            => sanitize_text_field($event['page_type'] ?? ''),
            'listing_id'           => sanitize_text_field($event['listing_id'] ?? ''),
            'listing_key'          => sanitize_text_field($event['listing_key'] ?? ''),
            'property_city'        => sanitize_text_field($event['property_city'] ?? ''),
            'property_price'       => absint($event['property_price'] ?? 0) ?: null,
            'property_beds'        => absint($event['property_beds'] ?? 0) ?: null,
            'property_baths'       => floatval($event['property_baths'] ?? 0) ?: null,
            'search_query'         => $event['search_query'] ?? null,
            'search_results_count' => isset($event['search_results_count']) ? absint($event['search_results_count']) : null,
            'click_target'         => sanitize_text_field(substr($event['click_target'] ?? '', 0, 255)),
            'click_element'        => sanitize_text_field(substr($event['click_element'] ?? '', 0, 100)),
            'scroll_depth'         => isset($event['scroll_depth']) ? min(absint($event['scroll_depth']), 100) : null,
            'time_on_page'         => isset($event['time_on_page']) ? absint($event['time_on_page']) : null,
            'data'                 => $event['data'] ?? null,
        );
    }

    /**
     * Check if session is rate limited
     *
     * @param string $session_id Session ID
     * @return bool
     */
    private function is_rate_limited($session_id) {
        $key = 'mld_analytics_rate_' . md5($session_id);
        $data = get_transient($key);

        if (!$data) {
            return false;
        }

        return $data['count'] >= self::RATE_LIMIT;
    }

    /**
     * Record rate limit request
     *
     * @param string $session_id Session ID
     */
    private function record_rate_limit($session_id) {
        $key = 'mld_analytics_rate_' . md5($session_id);
        $data = get_transient($key);

        if (!$data) {
            $data = array('count' => 0, 'start' => time());
        }

        $data['count']++;

        set_transient($key, $data, self::RATE_LIMIT_WINDOW);
    }
}
