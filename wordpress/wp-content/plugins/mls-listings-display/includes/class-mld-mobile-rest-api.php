<?php
/**
 * MLD Mobile REST API
 *
 * Provides REST API endpoints for the Steve Novak mobile app.
 * Namespace: mld-mobile/v1
 *
 * @package MLS_Listings_Display
 * @since 6.27.4
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Mobile_REST_API {

    /**
     * API namespace
     */
    const NAMESPACE = 'mld-mobile/v1';

    /**
     * JWT secret key option name
     */
    const JWT_SECRET_OPTION = 'mld_mobile_jwt_secret';

    /**
     * Access token expiry in seconds (30 days)
     * @since 6.50.8 - Increased from 15 minutes to prevent unexpected logouts
     */
    const ACCESS_TOKEN_EXPIRY = 2592000;

    /**
     * Refresh token expiry in seconds (30 days)
     * @since 6.50.8 - Increased from 7 days to match access token
     */
    const REFRESH_TOKEN_EXPIRY = 2592000;

    /**
     * Initialize the REST API
     */
    public static function init() {
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));

        // Ensure JWT secret exists
        self::ensure_jwt_secret();
    }

    /**
     * Ensure JWT secret key exists
     * SECURITY: The secret MUST be defined in wp-config.php as MLD_JWT_SECRET
     * Database storage is NO LONGER supported due to security risks
     *
     * @since 6.54.4 - Removed database fallback, now requires wp-config.php constant
     */
    private static function ensure_jwt_secret() {
        // SECURITY: JWT secret MUST be defined in wp-config.php
        if (!defined('MLD_JWT_SECRET') || empty(MLD_JWT_SECRET)) {
            // Log critical error - this should never happen in production
            error_log('[MLD CRITICAL SECURITY] MLD_JWT_SECRET is not defined in wp-config.php! Authentication will fail.');

            // In debug mode, provide helpful message
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[MLD CRITICAL SECURITY] Add to wp-config.php: define(\'MLD_JWT_SECRET\', \'' . wp_generate_password(64, true, true) . '\');');
            }
            return;
        }

        // Verify secret is strong enough (at least 32 characters)
        if (strlen(MLD_JWT_SECRET) < 32) {
            error_log('[MLD SECURITY WARNING] MLD_JWT_SECRET should be at least 32 characters for security.');
        }

        // Clean up any legacy database secret (one-time migration)
        if (get_option(self::JWT_SECRET_OPTION)) {
            delete_option(self::JWT_SECRET_OPTION);
            error_log('[MLD Security] Removed legacy JWT secret from database. Using wp-config.php constant.');
        }
    }

    /**
     * Get JWT secret key
     * SECURITY: ONLY uses wp-config.php constant - database storage is NOT supported
     *
     * Add to wp-config.php:
     * define('MLD_JWT_SECRET', 'your-64-character-random-secret-here');
     *
     * @since 6.54.4 - Removed database fallback entirely
     */
    private static function get_jwt_secret() {
        // ONLY use wp-config.php constant - no database fallback for security
        if (defined('MLD_JWT_SECRET') && !empty(MLD_JWT_SECRET)) {
            return MLD_JWT_SECRET;
        }

        // No fallback - authentication will fail without proper configuration
        // This is intentional - we want to fail loudly rather than use insecure storage
        return null;
    }

    /**
     * Send no-cache headers for authenticated endpoints
     * Prevents CDN from caching user-specific data
     *
     * @since 6.31.2
     */
    private static function send_no_cache_headers() {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
        header('Vary: Authorization');
    }

    /**
     * Convert MySQL datetime from WordPress timezone to ISO8601 format
     *
     * WordPress stores timestamps in its configured timezone (e.g., America/New_York).
     * PHP's strtotime() interprets dates in PHP's default timezone (often UTC on servers).
     * This causes a timezone mismatch where dates appear off by the UTC offset.
     *
     * This helper properly interprets the MySQL datetime in WordPress timezone
     * and outputs ISO8601 with the correct timezone offset.
     *
     * @since 6.50.6
     * @param string|null $mysql_datetime MySQL datetime string (e.g., "2026-01-09 14:30:00")
     * @return string|null ISO8601 formatted datetime with timezone offset, or null if input is null
     */
    private static function format_datetime_iso8601($mysql_datetime) {
        if (empty($mysql_datetime)) {
            return null;
        }

        try {
            // Get WordPress timezone
            $wp_timezone = wp_timezone();

            // Create DateTime object interpreting the input in WordPress timezone
            $date = new DateTime($mysql_datetime, $wp_timezone);

            // Format as ISO8601 (includes timezone offset, e.g., "2026-01-09T14:30:00-05:00")
            return $date->format('c');
        } catch (Exception $e) {
            // Fallback to simple conversion if DateTime fails
            return date('c', strtotime($mysql_datetime));
        }
    }

    /**
     * Convert UTC datetime (from database) to ISO8601 with WordPress timezone
     *
     * Use this for dates stored in server/UTC timezone (like bme_property_history.event_date)
     * NOT for dates stored in WordPress timezone (use format_datetime_iso8601 for those)
     *
     * @since 6.67.3
     * @param string|null $utc_datetime UTC datetime string (e.g., "2026-01-19 05:30:00")
     * @return string|null ISO8601 formatted datetime in WP timezone, or null if input is null
     */
    private static function format_utc_to_local_iso8601($utc_datetime) {
        if (empty($utc_datetime)) {
            return null;
        }

        try {
            // Create DateTime in UTC (how it's stored in the database)
            $utc_tz = new DateTimeZone('UTC');
            $date = new DateTime($utc_datetime, $utc_tz);

            // Convert to WordPress timezone for display
            $date->setTimezone(wp_timezone());

            // Format as ISO8601 with timezone offset
            return $date->format('c');
        } catch (Exception $e) {
            // Fallback: return as-is
            return $utc_datetime;
        }
    }

    /**
     * Check rate limit for authentication endpoints
     * Returns false if under limit, WP_REST_Response if rate limited
     *
     * @since 6.31.5
     * @param string $action The action being rate limited ('login' or 'register')
     * @param string $identifier The identifier to rate limit (email or IP)
     * @return false|WP_REST_Response False if allowed, error response if rate limited
     */
    private static function check_auth_rate_limit($action, $identifier) {
        // v6.50.8: Made rate limiting less aggressive to reduce false lockouts
        // - Increased login attempts from 5 to 20
        // - Reduced lockout from 15 minutes to 5 minutes
        $max_attempts = ($action === 'login') ? 20 : 5;
        $lockout_duration = 5 * MINUTE_IN_SECONDS;  // 5 minute lockout (was 15)
        $window_duration = 15 * MINUTE_IN_SECONDS;  // 15 minute window

        // Create unique key for this action + identifier combination
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field($_SERVER['REMOTE_ADDR']) : 'unknown';
        $rate_key = 'mld_auth_' . $action . '_' . md5($identifier . '_' . $ip);

        // Check if currently locked out
        $lockout_key = $rate_key . '_lockout';
        if (get_transient($lockout_key)) {
            $remaining = get_option('_transient_timeout_' . $lockout_key) - time();
            $minutes = ceil($remaining / 60);
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'rate_limit_exceeded',
                'message' => sprintf('Too many attempts. Please try again in %d minute(s).', $minutes)
            ), 429);
        }

        // Get current attempt count
        $attempts = (int) get_transient($rate_key);

        if ($attempts >= $max_attempts) {
            // Set lockout
            set_transient($lockout_key, true, $lockout_duration);
            delete_transient($rate_key);

            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'rate_limit_exceeded',
                'message' => 'Too many failed attempts. Please try again in 5 minutes.'
            ), 429);
        }

        return false; // Not rate limited
    }

    /**
     * Record a failed authentication attempt
     *
     * @since 6.31.5
     * @param string $action The action ('login' or 'register')
     * @param string $identifier The identifier (email or IP)
     */
    private static function record_failed_auth_attempt($action, $identifier) {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field($_SERVER['REMOTE_ADDR']) : 'unknown';
        $rate_key = 'mld_auth_' . $action . '_' . md5($identifier . '_' . $ip);
        $window_duration = 15 * MINUTE_IN_SECONDS;

        $attempts = (int) get_transient($rate_key);
        set_transient($rate_key, $attempts + 1, $window_duration);
    }

    /**
     * Clear rate limit on successful authentication
     *
     * @since 6.31.5
     * @param string $action The action ('login' or 'register')
     * @param string $identifier The identifier (email or IP)
     */
    private static function clear_auth_rate_limit($action, $identifier) {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field($_SERVER['REMOTE_ADDR']) : 'unknown';
        $rate_key = 'mld_auth_' . $action . '_' . md5($identifier . '_' . $ip);
        delete_transient($rate_key);
        delete_transient($rate_key . '_lockout');
    }

    /**
     * Check rate limit for public API endpoints
     *
     * Prevents DoS attacks by limiting requests per IP.
     * Unlike auth rate limiting, this uses a sliding window counter
     * without lockouts - just returns 429 when over limit.
     *
     * @since 6.54.4
     * @param string $endpoint The endpoint being rate limited (e.g., 'properties', 'autocomplete')
     * @return false|WP_REST_Response False if allowed, 429 response if rate limited
     */
    private static function check_public_rate_limit($endpoint) {
        // Rate limits per endpoint (requests per minute)
        $limits = array(
            'properties' => 60,      // Property list/search - moderate limit
            'property_detail' => 120, // Property detail - higher (users browse quickly)
            'autocomplete' => 120,   // Autocomplete - higher (rapid typing)
            'default' => 60,         // Default for other endpoints
        );

        $max_requests = isset($limits[$endpoint]) ? $limits[$endpoint] : $limits['default'];
        $window_seconds = 60; // 1 minute window

        // Get client IP (handle Kinsta/CDN proxies)
        $ip = self::get_client_ip();

        // Create unique key for this endpoint + IP
        $rate_key = 'mld_public_rate_' . $endpoint . '_' . md5($ip);

        // Get current request count and window start time
        $rate_data = get_transient($rate_key);

        if ($rate_data === false) {
            // First request in this window
            $rate_data = array(
                'count' => 1,
                'window_start' => time(),
            );
            set_transient($rate_key, $rate_data, $window_seconds);
            return false;
        }

        // Check if we're still in the same window
        $window_elapsed = time() - $rate_data['window_start'];

        if ($window_elapsed >= $window_seconds) {
            // Window expired, start new window
            $rate_data = array(
                'count' => 1,
                'window_start' => time(),
            );
            set_transient($rate_key, $rate_data, $window_seconds);
            return false;
        }

        // Increment counter
        $rate_data['count']++;
        set_transient($rate_key, $rate_data, $window_seconds - $window_elapsed);

        // Check if over limit
        if ($rate_data['count'] > $max_requests) {
            $retry_after = $window_seconds - $window_elapsed;

            $response = new WP_REST_Response(array(
                'success' => false,
                'code' => 'rate_limit_exceeded',
                'message' => sprintf('Too many requests. Please try again in %d seconds.', $retry_after)
            ), 429);

            // Add standard rate limit headers
            $response->header('Retry-After', $retry_after);
            $response->header('X-RateLimit-Limit', $max_requests);
            $response->header('X-RateLimit-Remaining', 0);
            $response->header('X-RateLimit-Reset', $rate_data['window_start'] + $window_seconds);

            return $response;
        }

        return false; // Not rate limited
    }

    /**
     * Get client IP address, handling CDN/proxy headers
     *
     * @since 6.54.4
     * @return string Client IP address
     */
    private static function get_client_ip() {
        // Check for CDN/proxy headers (Kinsta uses X-Real-IP)
        $headers = array(
            'HTTP_X_REAL_IP',
            'HTTP_TRUE_CLIENT_IP',
            'HTTP_CF_CONNECTING_IP',    // Cloudflare
            'HTTP_X_FORWARDED_FOR',
            'REMOTE_ADDR',
        );

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = sanitize_text_field($_SERVER[$header]);
                // X-Forwarded-For can contain multiple IPs, take the first
                if (strpos($ip, ',') !== false) {
                    $ips = explode(',', $ip);
                    $ip = trim($ips[0]);
                }
                // Validate IP format
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return 'unknown';
    }

    /**
     * Validate sort parameter against allowed values
     *
     * Prevents SQL injection and ensures only valid sort orders are used.
     *
     * @since 6.31.6
     * @param string $sort_param The sort parameter from the request
     * @return string Validated sort value, defaults to 'list_date_desc' if invalid
     */
    private static function validate_sort_parameter($sort_param) {
        $allowed_sorts = array(
            'price_asc',
            'price_desc',
            'list_date_asc',
            'list_date_desc',
            'beds_desc',
            'sqft_desc'
        );

        if (!empty($sort_param) && in_array($sort_param, $allowed_sorts, true)) {
            return $sort_param;
        }

        return 'list_date_desc'; // Default sort
    }

    /**
     * Normalize iOS camelCase frequency to PHP snake_case
     *
     * @since 6.31.2
     * @param string $frequency The frequency value from request
     * @return string Normalized frequency value
     */
    private static function normalize_frequency($frequency) {
        $frequency_map = array(
            'fifteenMin' => 'fifteen_min',
            'fifteenMinutes' => 'fifteen_min'
        );
        return isset($frequency_map[$frequency]) ? $frequency_map[$frequency] : $frequency;
    }

    /**
     * Format array field values for display
     *
     * Converts JSON array strings (e.g., '["Baseboard","Natural Gas"]') to
     * comma-separated strings (e.g., 'Baseboard, Natural Gas').
     * Returns null for empty arrays or empty values.
     *
     * @since 6.49.9
     * @param mixed $value The field value (could be JSON string, array, or scalar)
     * @return string|null Formatted string or null if empty
     */
    private static function format_array_field($value) {
        if (empty($value)) {
            return null;
        }

        // If it's already an array, format it
        if (is_array($value)) {
            $filtered = array_filter($value);
            return empty($filtered) ? null : implode(', ', $filtered);
        }

        // If it's a string that looks like JSON array, decode and format
        if (is_string($value) && (substr($value, 0, 1) === '[')) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $filtered = array_filter($decoded);
                return empty($filtered) ? null : implode(', ', $filtered);
            }
        }

        // Return as-is if it's a regular string
        return $value;
    }

    /**
     * Normalize filter arrays to ensure consistent storage
     * Converts single string values to arrays for array-type filters
     *
     * @since 6.31.2
     * @param array $filters The filters array from request
     * @return array Normalized filters
     */
    private static function normalize_filter_arrays($filters) {
        $array_fields = array('city', 'zip', 'neighborhood', 'beds', 'baths', 'property_type', 'status');
        foreach ($array_fields as $field) {
            if (isset($filters[$field]) && is_string($filters[$field])) {
                $filters[$field] = array($filters[$field]);
            }
        }
        return $filters;
    }

    /**
     * Normalize room level strings to standard format
     * Handles various MLS level formats: "First", "Main,First", "Second", "Third", "Fourth Floor", etc.
     *
     * @since 6.68.19
     * @param string|null $level The raw room level string from MLS
     * @return string|null Normalized level string or null if no valid level
     */
    private static function normalize_room_level($level) {
        if (empty($level)) {
            return null;
        }

        // Handle combined levels - take the primary one
        if (strpos($level, ',') !== false) {
            $parts = explode(',', $level);
            $level = trim($parts[0]);
        }

        // Normalize variations
        $level_lower = strtolower(trim($level));

        $level_map = array(
            'first' => 'First',
            '1' => 'First',
            '1st' => 'First',
            'main' => 'First',
            'ground' => 'First',
            'entry' => 'First',
            'second' => 'Second',
            '2' => 'Second',
            '2nd' => 'Second',
            'third' => 'Third',
            '3' => 'Third',
            '3rd' => 'Third',
            'fourth' => 'Fourth',
            'fourth floor' => 'Fourth',
            '4' => 'Fourth',
            '4th' => 'Fourth',
            'basement' => 'Basement',
            'lower' => 'Basement',
            'll' => 'Basement',
            'attic' => 'Attic',
            'loft' => 'Attic'
        );

        return isset($level_map[$level_lower]) ? $level_map[$level_lower] : ucfirst($level);
    }

    /**
     * Infer the floor level for special rooms based on context
     * Uses basement info, room data, and property characteristics
     *
     * @since 6.68.19
     * @param string $room_type The type of special room
     * @param array $all_rooms All rooms from the database
     * @param object $details Property details object
     * @return string|null Inferred level or null if cannot determine
     */
    private static function infer_special_room_level($room_type, $all_rooms, $details) {
        $type_lower = strtolower($room_type);

        // If basement exists and room is typically basement-located
        $has_finished_basement = !empty($details->below_grade_finished_area) && $details->below_grade_finished_area > 500;
        if ($has_finished_basement && (
            strpos($type_lower, 'media') !== false ||
            strpos($type_lower, 'game') !== false ||
            strpos($type_lower, 'exercise') !== false ||
            strpos($type_lower, 'au pair') !== false ||
            strpos($type_lower, 'rec') !== false ||
            strpos($type_lower, 'home theater') !== false
        )) {
            return 'Basement';
        }

        // In-law apartments - check if basement is finished
        if (strpos($type_lower, 'in-law') !== false || strpos($type_lower, 'inlaw') !== false) {
            if ($has_finished_basement) {
                return 'Basement';  // Likely in finished basement
            }
            return 'First';  // Otherwise main floor
        }

        // Bonus rooms are typically upstairs
        if (strpos($type_lower, 'bonus') !== false) {
            return 'Second';
        }

        // Sun rooms, mud rooms typically on main floor
        if (strpos($type_lower, 'sun') !== false ||
            strpos($type_lower, 'mud') !== false ||
            strpos($type_lower, 'entry') !== false) {
            return 'First';
        }

        // Check if there's already an Office room with a level
        if (strpos($type_lower, 'office') !== false || strpos($type_lower, 'study') !== false) {
            foreach ($all_rooms as $room) {
                $rt = strtolower($room->room_type ?? '');
                if ((strpos($rt, 'office') !== false || strpos($rt, 'study') !== false) &&
                    !empty($room->room_level)) {
                    return self::normalize_room_level($room->room_level);
                }
            }
        }

        // Den typically on main floor
        if (strpos($type_lower, 'den') !== false) {
            return 'First';
        }

        return null;  // Unknown
    }

    /**
     * Extract special room types from interior_features
     * Identifies rooms like Bonus Room, In-law Apt, Au Pair Suite, etc.
     *
     * @since 6.68.19
     * @param string|null $interior_features The interior_features field (JSON or comma-separated)
     * @param array $all_rooms All rooms from the database
     * @param object $details Property details object
     * @return array Array of special room objects
     */
    private static function extract_special_rooms($interior_features, $all_rooms, $details) {
        if (empty($interior_features)) {
            return array();
        }

        // Parse interior_features - could be JSON array or comma-separated string
        $features_array = array();
        if (is_string($interior_features) && substr($interior_features, 0, 1) === '[') {
            $decoded = json_decode($interior_features, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $features_array = $decoded;
            }
        } elseif (is_string($interior_features)) {
            $features_array = array_map('trim', explode(',', $interior_features));
        } elseif (is_array($interior_features)) {
            $features_array = $interior_features;
        }

        // Special room type patterns with default floor levels
        $special_room_patterns = array(
            'Bonus Room' => 'Second',
            'Inlaw Apt.' => null,          // Will be inferred
            'In-Law Floorplan' => null,    // Will be inferred
            'Au Pair Suite' => 'Basement',
            'Home Office' => null,
            'Office' => null,
            'Study' => null,
            'Den' => 'First',
            'Media Room' => 'Basement',
            'Game Room' => 'Basement',
            'Exercise Room' => 'Basement',
            'Sun Room' => 'First',
            'Mud Room' => 'First',
            'Entry Hall' => 'First',
            'Great Room' => 'First',
            'Home Theater' => 'Basement',
            'Wine Cellar' => 'Basement',
            'Rec Room' => 'Basement',
            'Recreation Room' => 'Basement',
            'Play Room' => null,
            'Sitting Room' => null,
        );

        $special_rooms = array();
        foreach ($features_array as $feature) {
            $feature_trimmed = trim($feature);
            foreach ($special_room_patterns as $type => $default_level) {
                if (stripos($feature_trimmed, $type) !== false) {
                    // Try to infer level from context, fall back to default
                    $inferred_level = self::infer_special_room_level($type, $all_rooms, $details);
                    $level = $inferred_level ?? $default_level;

                    $special_rooms[] = array(
                        'type' => $feature_trimmed,
                        'level' => $level,
                        'dimensions' => null,
                        'features' => null,
                        'has_level' => $level !== null,
                        'is_likely_placeholder' => false,
                        'is_special' => true,
                        'level_inferred' => true,
                    );
                    break; // Only match one pattern per feature
                }
            }
        }

        return $special_rooms;
    }

    /**
     * Register all REST routes
     */
    public static function register_routes() {
        // ============ Health Check (Public) ============

        register_rest_route(self::NAMESPACE, '/health', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'health_check'),
            'permission_callback' => '__return_true',
        ));

        // Unified health check - comprehensive multi-component health (v6.58.0)
        // For external monitoring services (Uptime Robot, Pingdom, etc.)
        register_rest_route(self::NAMESPACE, '/unified-health', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'handle_unified_health'),
            'permission_callback' => '__return_true',
        ));

        // Simple ping endpoint - minimal response for uptime monitoring (v6.58.0)
        register_rest_route(self::NAMESPACE, '/ping', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'handle_ping'),
            'permission_callback' => '__return_true',
        ));

        // ============ Settings Routes (Public) ============

        // MLS Disclosure settings
        register_rest_route(self::NAMESPACE, '/settings/disclosure', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'handle_get_disclosure_settings'),
            'permission_callback' => '__return_true',
        ));

        // Site contact settings (default phone/email for contact forms)
        register_rest_route(self::NAMESPACE, '/settings/site-contact', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'handle_get_site_contact_settings'),
            'permission_callback' => '__return_true',
        ));

        // ============ Authentication Routes ============

        // Login
        register_rest_route(self::NAMESPACE, '/auth/login', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'handle_login'),
            'permission_callback' => '__return_true',
        ));

        // Register
        register_rest_route(self::NAMESPACE, '/auth/register', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'handle_register'),
            'permission_callback' => '__return_true',
        ));

        // Refresh token
        register_rest_route(self::NAMESPACE, '/auth/refresh', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'handle_refresh'),
            'permission_callback' => '__return_true',
        ));

        // Forgot password
        register_rest_route(self::NAMESPACE, '/auth/forgot-password', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'handle_forgot_password'),
            'permission_callback' => '__return_true',
        ));

        // Get current user
        register_rest_route(self::NAMESPACE, '/auth/me', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'handle_get_me'),
            'permission_callback' => array(__CLASS__, 'check_auth'),
        ));

        // Logout
        register_rest_route(self::NAMESPACE, '/auth/logout', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'handle_logout'),
            'permission_callback' => array(__CLASS__, 'check_auth'),
        ));

        // Delete account (v6.51.0 - Apple App Store Guideline 5.1.1(v) compliance)
        register_rest_route(self::NAMESPACE, '/auth/delete-account', array(
            'methods' => 'DELETE',
            'callback' => array(__CLASS__, 'handle_delete_account'),
            'permission_callback' => array(__CLASS__, 'check_auth'),
        ));

        // ============ Device Token Routes (Push Notifications) ============

        // Register device token
        register_rest_route(self::NAMESPACE, '/device-tokens', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'handle_register_device_token'),
            'permission_callback' => array(__CLASS__, 'check_auth'),
        ));

        // Unregister device token
        register_rest_route(self::NAMESPACE, '/device-tokens', array(
            'methods' => 'DELETE',
            'callback' => array(__CLASS__, 'handle_unregister_device_token'),
            'permission_callback' => array(__CLASS__, 'check_auth'),
        ));

        // ============ Property Routes ============
        // Skip property routes if mld-mobile-api plugin is active (it has better implementations)
        if (!defined('MLD_MOBILE_API_VERSION')) {
            // Get properties list
            register_rest_route(self::NAMESPACE, '/properties', array(
                'methods' => 'GET',
                'callback' => array(__CLASS__, 'handle_get_properties'),
                'permission_callback' => '__return_true',
            ));

            // Get single property
            register_rest_route(self::NAMESPACE, '/properties/(?P<id>[^/]+)', array(
                'methods' => 'GET',
                'callback' => array(__CLASS__, 'handle_get_property'),
                'permission_callback' => '__return_true',
            ));

            // Get property history (price changes, status changes)
            register_rest_route(self::NAMESPACE, '/properties/(?P<id>[^/]+)/history', array(
                'methods' => 'GET',
                'callback' => array(__CLASS__, 'handle_get_property_history'),
                'permission_callback' => '__return_true',
            ));

            // Get address history (previous sales at same address) - v6.68.0
            register_rest_route(self::NAMESPACE, '/properties/(?P<id>[^/]+)/address-history', array(
                'methods' => 'GET',
                'callback' => array(__CLASS__, 'handle_get_address_history'),
                'permission_callback' => '__return_true',
            ));
        }

        // ============ Filter Options Route (v6.59.0) ============
        // Returns available filter values (home types/property_sub_type) based on current filter selections
        register_rest_route(self::NAMESPACE, '/filter-options', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'handle_get_filter_options'),
            'permission_callback' => '__return_true',
        ));

        // ============ Favorites Routes ============
        // Skip favorites routes if mld-mobile-api plugin is active (it has better implementations)
        if (!defined('MLD_MOBILE_API_VERSION')) {
            // Get user favorites
            register_rest_route(self::NAMESPACE, '/favorites', array(
                'methods' => 'GET',
                'callback' => array(__CLASS__, 'handle_get_favorites'),
                'permission_callback' => array(__CLASS__, 'check_auth'),
            ));

            // Add to favorites
            register_rest_route(self::NAMESPACE, '/favorites/(?P<listing_id>[^/]+)', array(
                'methods' => 'POST',
                'callback' => array(__CLASS__, 'handle_add_favorite'),
                'permission_callback' => array(__CLASS__, 'check_auth'),
            ));

            // Remove from favorites
            register_rest_route(self::NAMESPACE, '/favorites/(?P<listing_id>[^/]+)', array(
                'methods' => 'DELETE',
                'callback' => array(__CLASS__, 'handle_remove_favorite'),
                'permission_callback' => array(__CLASS__, 'check_auth'),
            ));

            // ============ Hidden Properties Routes ============
            // Get user hidden properties
            register_rest_route(self::NAMESPACE, '/hidden', array(
                'methods' => 'GET',
                'callback' => array(__CLASS__, 'handle_get_hidden'),
                'permission_callback' => array(__CLASS__, 'check_auth'),
            ));

            // Hide a property
            register_rest_route(self::NAMESPACE, '/hidden/(?P<listing_id>[^/]+)', array(
                'methods' => 'POST',
                'callback' => array(__CLASS__, 'handle_hide_property'),
                'permission_callback' => array(__CLASS__, 'check_auth'),
            ));

            // Unhide a property
            register_rest_route(self::NAMESPACE, '/hidden/(?P<listing_id>[^/]+)', array(
                'methods' => 'DELETE',
                'callback' => array(__CLASS__, 'handle_unhide_property'),
                'permission_callback' => array(__CLASS__, 'check_auth'),
            ));
        }

        // ============ Saved Searches Routes ============
        // Skip saved searches routes if mld-mobile-api plugin is active (it has better implementations)
        if (!defined('MLD_MOBILE_API_VERSION')) {
            // Get saved searches
            register_rest_route(self::NAMESPACE, '/saved-searches', array(
                'methods' => 'GET',
                'callback' => array(__CLASS__, 'handle_get_saved_searches'),
                'permission_callback' => array(__CLASS__, 'check_auth'),
            ));

            // Create saved search
            register_rest_route(self::NAMESPACE, '/saved-searches', array(
                'methods' => 'POST',
                'callback' => array(__CLASS__, 'handle_create_saved_search'),
                'permission_callback' => array(__CLASS__, 'check_auth'),
            ));

            // Delete saved search
            register_rest_route(self::NAMESPACE, '/saved-searches/(?P<id>\d+)', array(
                'methods' => 'DELETE',
                'callback' => array(__CLASS__, 'handle_delete_saved_search'),
                'permission_callback' => array(__CLASS__, 'check_auth'),
            ));

            // Update saved search
            register_rest_route(self::NAMESPACE, '/saved-searches/(?P<id>\d+)', array(
                'methods' => 'PUT',
                'callback' => array(__CLASS__, 'handle_update_saved_search'),
                'permission_callback' => array(__CLASS__, 'check_auth'),
            ));

            // Get single saved search
            register_rest_route(self::NAMESPACE, '/saved-searches/(?P<id>\d+)', array(
                'methods' => 'GET',
                'callback' => array(__CLASS__, 'handle_get_saved_search'),
                'permission_callback' => array(__CLASS__, 'check_auth'),
            ));
        }

        // ============ Device Token Routes (for push notifications) ============
        // Register device for push notifications
        register_rest_route(self::NAMESPACE, '/devices', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'handle_register_device'),
            'permission_callback' => array(__CLASS__, 'check_auth'),
        ));

        // Unregister device
        register_rest_route(self::NAMESPACE, '/devices/(?P<token>[^/]+)', array(
            'methods' => 'DELETE',
            'callback' => array(__CLASS__, 'handle_unregister_device'),
            'permission_callback' => array(__CLASS__, 'check_auth'),
        ));

        // ============ Analytics Routes ============
        // Skip analytics routes if mld-mobile-api plugin is active (it has better implementations)
        if (!defined('MLD_MOBILE_API_VERSION')) {
            // Get cities with analytics
            register_rest_route(self::NAMESPACE, '/analytics/cities', array(
                'methods' => 'GET',
                'callback' => array(__CLASS__, 'handle_get_cities'),
                'permission_callback' => '__return_true',
            ));

            // Get city summary
            register_rest_route(self::NAMESPACE, '/analytics/city/(?P<city>[^/]+)', array(
                'methods' => 'GET',
                'callback' => array(__CLASS__, 'handle_get_city_summary'),
                'permission_callback' => '__return_true',
            ));

            // Get trends
            register_rest_route(self::NAMESPACE, '/analytics/trends/(?P<city>[^/]+)', array(
                'methods' => 'GET',
                'callback' => array(__CLASS__, 'handle_get_trends'),
                'permission_callback' => '__return_true',
            ));

            // Compare cities
            register_rest_route(self::NAMESPACE, '/analytics/compare', array(
                'methods' => 'GET',
                'callback' => array(__CLASS__, 'handle_compare_cities'),
                'permission_callback' => '__return_true',
            ));

            // Get market overview
            register_rest_route(self::NAMESPACE, '/analytics/overview', array(
                'methods' => 'GET',
                'callback' => array(__CLASS__, 'handle_get_overview'),
                'permission_callback' => '__return_true',
            ));

            // Get neighborhood analytics for map bounds
            register_rest_route(self::NAMESPACE, '/neighborhoods/analytics', array(
                'methods' => 'GET',
                'callback' => array(__CLASS__, 'handle_get_neighborhood_analytics'),
                'permission_callback' => '__return_true',
            ));

            // ============ CMA Routes ============

            // Get user's CMA sessions
            register_rest_route(self::NAMESPACE, '/cma/sessions', array(
                'methods' => 'GET',
                'callback' => array(__CLASS__, 'handle_get_cma_sessions'),
                'permission_callback' => array(__CLASS__, 'check_auth'),
            ));

            // Get CMA session detail
            register_rest_route(self::NAMESPACE, '/cma/session/(?P<id>\d+)', array(
                'methods' => 'GET',
                'callback' => array(__CLASS__, 'handle_get_cma_session'),
                'permission_callback' => array(__CLASS__, 'check_auth'),
            ));

            // Get property CMA
            register_rest_route(self::NAMESPACE, '/cma/property/(?P<listing_id>[^/]+)', array(
                'methods' => 'GET',
                'callback' => array(__CLASS__, 'handle_get_property_cma'),
                'permission_callback' => '__return_true',
            ));

            // Generate CMA PDF report
            register_rest_route(self::NAMESPACE, '/cma/generate-pdf', array(
                'methods' => 'POST',
                'callback' => array(__CLASS__, 'handle_generate_cma_pdf'),
                'permission_callback' => array(__CLASS__, 'check_auth'),
            ));

            // Analyze property condition with AI (v6.75.0)
            register_rest_route(self::NAMESPACE, '/cma/analyze-condition', array(
                'methods' => 'POST',
                'callback' => array(__CLASS__, 'handle_analyze_condition'),
                'permission_callback' => array(__CLASS__, 'check_auth'),
            ));

            // ============ Chatbot Routes ============

            // Send message
            register_rest_route(self::NAMESPACE, '/chatbot/message', array(
                'methods' => 'POST',
                'callback' => array(__CLASS__, 'handle_chatbot_message'),
                'permission_callback' => '__return_true',
            ));

            // ============ Search & Filter Helper Routes ============

            // Get price distribution for histogram
            register_rest_route(self::NAMESPACE, '/filters/price-distribution', array(
                'methods' => 'GET',
                'callback' => array(__CLASS__, 'handle_get_price_distribution'),
                'permission_callback' => '__return_true',
            ));

        }

        // Always register autocomplete (ensures mobile app always has access)
        register_rest_route(self::NAMESPACE, '/search/autocomplete', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'handle_autocomplete'),
            'permission_callback' => '__return_true',
        ));

        // Always register neighborhood analytics (for map price overlays)
        register_rest_route(self::NAMESPACE, '/neighborhoods/analytics', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'handle_get_neighborhood_analytics'),
            'permission_callback' => '__return_true',
        ));

        // ============ Boundary Routes ============
        // Get city/neighborhood/zipcode boundary polygon (GeoJSON)
        register_rest_route(self::NAMESPACE, '/boundaries/location', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'handle_get_boundary'),
            'permission_callback' => '__return_true',
        ));

        // ============ Agent Routes (v6.32.0) ============

        // Get all active agents
        register_rest_route(self::NAMESPACE, '/agents', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'handle_get_agents'),
            'permission_callback' => '__return_true',
        ));

        // Get single agent profile
        register_rest_route(self::NAMESPACE, '/agents/(?P<id>\d+)', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'handle_get_agent'),
            'permission_callback' => '__return_true',
        ));

        // Get current user's assigned agent
        register_rest_route(self::NAMESPACE, '/my-agent', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'handle_get_my_agent'),
            'permission_callback' => array(__CLASS__, 'check_auth'),
        ));

        // ============ Users Routes (v6.32.0) ============

        // Enhanced user profile with type and agent info
        register_rest_route(self::NAMESPACE, '/users/me', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'handle_get_user_me'),
            'permission_callback' => array(__CLASS__, 'check_auth'),
        ));

        // ============ Agent Collaboration Routes (v6.32.0 Phase 2) ============
        // SECURITY: All agent routes use check_agent_auth to verify user is an agent

        // Get agent's clients with search summaries
        register_rest_route(self::NAMESPACE, '/agent/clients', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'handle_get_agent_clients'),
            'permission_callback' => array(__CLASS__, 'check_agent_auth'),
        ));

        // Create a new client (as agent)
        register_rest_route(self::NAMESPACE, '/agent/clients', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'handle_create_client'),
            'permission_callback' => array(__CLASS__, 'check_agent_auth'),
        ));

        // Get single client details (for agent)
        register_rest_route(self::NAMESPACE, '/agent/clients/(?P<client_id>\d+)', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'handle_get_client_detail'),
            'permission_callback' => array(__CLASS__, 'check_agent_auth'),
        ));

        // Get specific client's searches (for agent)
        register_rest_route(self::NAMESPACE, '/agent/clients/(?P<client_id>\d+)/searches', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'handle_get_client_searches'),
            'permission_callback' => array(__CLASS__, 'check_agent_auth'),
        ));

        // Create a saved search for a client (as agent)
        register_rest_route(self::NAMESPACE, '/agent/clients/(?P<client_id>\d+)/searches', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'handle_create_search_for_client'),
            'permission_callback' => array(__CLASS__, 'check_agent_auth'),
        ));

        // Get specific client's favorites (for agent)
        register_rest_route(self::NAMESPACE, '/agent/clients/(?P<client_id>\d+)/favorites', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'handle_get_client_favorites'),
            'permission_callback' => array(__CLASS__, 'check_agent_auth'),
        ));

        // Get specific client's hidden properties (for agent)
        register_rest_route(self::NAMESPACE, '/agent/clients/(?P<client_id>\d+)/hidden', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'handle_get_client_hidden'),
            'permission_callback' => array(__CLASS__, 'check_agent_auth'),
        ));

        // Get all client searches for agent (across all clients)
        register_rest_route(self::NAMESPACE, '/agent/searches', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'handle_get_agent_all_searches'),
            'permission_callback' => array(__CLASS__, 'check_agent_auth'),
        ));

        // Batch create searches for multiple clients (as agent)
        register_rest_route(self::NAMESPACE, '/agent/searches/batch', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'handle_create_searches_for_clients_batch'),
            'permission_callback' => array(__CLASS__, 'check_agent_auth'),
        ));

        // Get agent dashboard metrics
        register_rest_route(self::NAMESPACE, '/agent/metrics', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'handle_get_agent_metrics'),
            'permission_callback' => array(__CLASS__, 'check_agent_auth'),
        ));

        // ==========================================
        // AGENT NOTIFICATION PREFERENCES (v6.43.0)
        // ==========================================

        // Get agent's notification preferences
        register_rest_route(self::NAMESPACE, '/agent/notification-preferences', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'handle_get_notification_preferences'),
            'permission_callback' => array(__CLASS__, 'check_agent_auth'),
        ));

        // Update agent's notification preferences
        register_rest_route(self::NAMESPACE, '/agent/notification-preferences', array(
            'methods' => 'PUT',
            'callback' => array(__CLASS__, 'handle_update_notification_preferences'),
            'permission_callback' => array(__CLASS__, 'check_agent_auth'),
        ));

        // ==========================================
        // AGENT REFERRAL SYSTEM (v6.52.0)
        // ==========================================

        // Get agent's referral link and statistics
        register_rest_route(self::NAMESPACE, '/agent/referral-link', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'handle_get_agent_referral_link'),
            'permission_callback' => array(__CLASS__, 'check_agent_auth'),
        ));

        // Update agent's custom referral code
        register_rest_route(self::NAMESPACE, '/agent/referral-link', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'handle_update_agent_referral_code'),
            'permission_callback' => array(__CLASS__, 'check_agent_auth'),
        ));

        // Regenerate agent's referral code
        register_rest_route(self::NAMESPACE, '/agent/referral-link/regenerate', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'handle_regenerate_agent_referral_code'),
            'permission_callback' => array(__CLASS__, 'check_agent_auth'),
        ));

        // Get agent's referral statistics
        register_rest_route(self::NAMESPACE, '/agent/referral-stats', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'handle_get_agent_referral_stats'),
            'permission_callback' => array(__CLASS__, 'check_agent_auth'),
        ));

        // Validate a referral code (public endpoint for signup page)
        register_rest_route(self::NAMESPACE, '/referral/validate', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'handle_validate_referral_code'),
            'permission_callback' => '__return_true',
        ));

        // Client reports app opened (for agent activity notifications)
        register_rest_route(self::NAMESPACE, '/app/opened', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'handle_app_opened'),
            'permission_callback' => array(__CLASS__, 'check_auth'),
        ));

        // ==========================================
        // CLIENT NOTIFICATION PREFERENCES (v6.48.0)
        // ==========================================

        // Get client's notification preferences
        register_rest_route(self::NAMESPACE, '/notification-preferences', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'handle_get_client_notification_preferences'),
            'permission_callback' => array(__CLASS__, 'check_auth'),
        ));

        // Update client's notification preferences
        register_rest_route(self::NAMESPACE, '/notification-preferences', array(
            'methods' => 'PUT',
            'callback' => array(__CLASS__, 'handle_update_client_notification_preferences'),
            'permission_callback' => array(__CLASS__, 'check_auth'),
        ));

        // ==========================================
        // BADGE COUNT MANAGEMENT (v6.49.0)
        // ==========================================

        // Get current badge count for authenticated user
        register_rest_route(self::NAMESPACE, '/badge-count', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'handle_get_badge_count'),
            'permission_callback' => array(__CLASS__, 'check_auth'),
        ));

        // Reset badge count to 0 (when user opens app or views notifications)
        register_rest_route(self::NAMESPACE, '/badge-count/reset', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'handle_reset_badge_count'),
            'permission_callback' => array(__CLASS__, 'check_auth'),
        ));

        // Get notification history for in-app notification center (v6.49.16)
        register_rest_route(self::NAMESPACE, '/notifications/history', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'handle_get_notification_history'),
            'permission_callback' => array(__CLASS__, 'check_auth'),
        ));

        // Mark single notification as read (v6.50.0)
        register_rest_route(self::NAMESPACE, '/notifications/(?P<id>\d+)/read', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'handle_mark_notification_read'),
            'permission_callback' => array(__CLASS__, 'check_auth'),
        ));

        // Dismiss/delete single notification (v6.50.0)
        register_rest_route(self::NAMESPACE, '/notifications/(?P<id>\d+)/dismiss', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'handle_dismiss_notification'),
            'permission_callback' => array(__CLASS__, 'check_auth'),
        ));

        // Mark all notifications as read (v6.50.0)
        register_rest_route(self::NAMESPACE, '/notifications/mark-all-read', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'handle_mark_all_notifications_read'),
            'permission_callback' => array(__CLASS__, 'check_auth'),
        ));

        // Dismiss all notifications (v6.50.3)
        register_rest_route(self::NAMESPACE, '/notifications/dismiss-all', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'handle_dismiss_all_notifications'),
            'permission_callback' => array(__CLASS__, 'check_auth'),
        ));

        // Track notification engagement (opened, dismissed, clicked) - v6.49.4
        register_rest_route(self::NAMESPACE, '/notifications/engagement', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'handle_notification_engagement'),
            'permission_callback' => array(__CLASS__, 'check_auth'),
        ));

        // Get notification engagement stats - Admin only (v6.49.4)
        register_rest_route(self::NAMESPACE, '/admin/notification-engagement-stats', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'handle_get_engagement_stats'),
            'permission_callback' => array(__CLASS__, 'check_admin'),
        ));

        // ==========================================
        // NOTIFICATION ANALYTICS (v6.48.0) - Admin Only
        // ==========================================

        // Get notification analytics summary
        register_rest_route(self::NAMESPACE, '/admin/notification-analytics', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'handle_get_notification_analytics'),
            'permission_callback' => array(__CLASS__, 'check_admin'),
        ));

        // Get notification analytics breakdown by type
        register_rest_route(self::NAMESPACE, '/admin/notification-analytics/by-type', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'handle_get_notification_analytics_by_type'),
            'permission_callback' => array(__CLASS__, 'check_admin'),
        ));

        // Get notification analytics trend
        register_rest_route(self::NAMESPACE, '/admin/notification-analytics/trend', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'handle_get_notification_analytics_trend'),
            'permission_callback' => array(__CLASS__, 'check_admin'),
        ));

        // Get activity log for a saved search
        register_rest_route(self::NAMESPACE, '/saved-searches/(?P<id>\d+)/activity', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'handle_get_search_activity'),
            'permission_callback' => array(__CLASS__, 'check_auth'),
        ));

        // Assign a client to the current agent
        register_rest_route(self::NAMESPACE, '/agent/clients/(?P<client_id>\d+)/assign', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'handle_assign_client'),
            'permission_callback' => array(__CLASS__, 'check_agent_auth'),
        ));

        // Unassign a client from the current agent
        register_rest_route(self::NAMESPACE, '/agent/clients/(?P<client_id>\d+)/assign', array(
            'methods' => 'DELETE',
            'callback' => array(__CLASS__, 'handle_unassign_client'),
            'permission_callback' => array(__CLASS__, 'check_agent_auth'),
        ));

        // ==========================================
        // SHARED PROPERTIES ENDPOINTS (v6.35.0)
        // ==========================================

        // Agent: Share properties with client(s) - supports bulk
        register_rest_route(self::NAMESPACE, '/shared-properties', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'handle_share_properties'),
            'permission_callback' => array(__CLASS__, 'check_agent_auth'),
        ));

        // Agent: Get properties they've shared
        register_rest_route(self::NAMESPACE, '/agent/shared-properties', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'handle_get_agent_shared_properties'),
            'permission_callback' => array(__CLASS__, 'check_agent_auth'),
        ));

        // Agent: Revoke a shared property
        register_rest_route(self::NAMESPACE, '/shared-properties/(?P<id>\d+)', array(
            'methods' => 'DELETE',
            'callback' => array(__CLASS__, 'handle_revoke_shared_property'),
            'permission_callback' => array(__CLASS__, 'check_agent_auth'),
        ));

        // Client: Get properties shared with me
        register_rest_route(self::NAMESPACE, '/shared-properties', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'handle_get_shared_properties'),
            'permission_callback' => array(__CLASS__, 'check_auth'),
        ));

        // Client: Update response to a shared property (interested/not)
        register_rest_route(self::NAMESPACE, '/shared-properties/(?P<id>\d+)', array(
            'methods' => 'PUT',
            'callback' => array(__CLASS__, 'handle_update_shared_property_response'),
            'permission_callback' => array(__CLASS__, 'check_auth'),
        ));

        // Client: Record view of shared property
        register_rest_route(self::NAMESPACE, '/shared-properties/(?P<id>\d+)/view', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'handle_shared_property_view'),
            'permission_callback' => array(__CLASS__, 'check_auth'),
        ));

        // Email preferences endpoints
        register_rest_route(self::NAMESPACE, '/email-preferences', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'handle_get_email_preferences'),
            'permission_callback' => array(__CLASS__, 'check_auth'),
        ));

        register_rest_route(self::NAMESPACE, '/email-preferences', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'handle_update_email_preferences'),
            'permission_callback' => array(__CLASS__, 'check_auth'),
        ));

        // Email tracking endpoints (public, no auth required)
        register_rest_route(self::NAMESPACE, '/email/track/open', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'handle_email_open_tracking'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route(self::NAMESPACE, '/email/track/click', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'handle_email_click_tracking'),
            'permission_callback' => '__return_true',
        ));

        // ==========================================
        // CLIENT ANALYTICS ENDPOINTS (v6.37.0)
        // ==========================================

        // Record single activity event (from iOS/Web)
        register_rest_route(self::NAMESPACE, '/analytics/activity', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'handle_record_activity'),
            'permission_callback' => array(__CLASS__, 'check_analytics_auth'),
        ));

        // Record batch of activity events (from iOS/Web)
        // Uses check_analytics_auth to support sendBeacon (no headers)
        register_rest_route(self::NAMESPACE, '/analytics/activity/batch', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'handle_batch_activities'),
            'permission_callback' => array(__CLASS__, 'check_analytics_auth'),
        ));

        // Start/end session
        // Uses check_analytics_auth to support sendBeacon (no headers)
        register_rest_route(self::NAMESPACE, '/analytics/session', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'handle_session_event'),
            'permission_callback' => array(__CLASS__, 'check_analytics_auth'),
        ));

        // Agent: Get analytics summary for a specific client
        register_rest_route(self::NAMESPACE, '/agent/clients/(?P<client_id>\d+)/analytics', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'handle_get_client_analytics'),
            'permission_callback' => array(__CLASS__, 'check_agent_auth'),
        ));

        // Agent: Get activity timeline for a specific client
        register_rest_route(self::NAMESPACE, '/agent/clients/(?P<client_id>\d+)/activity', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'handle_get_client_activity_timeline'),
            'permission_callback' => array(__CLASS__, 'check_agent_auth'),
        ));

        // Agent: Get analytics dashboard for all clients
        register_rest_route(self::NAMESPACE, '/agent/analytics', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'handle_get_agent_analytics'),
            'permission_callback' => array(__CLASS__, 'check_agent_auth'),
        ));

        // Agent: Get clients analytics summary with engagement scores (v6.40.0)
        register_rest_route(self::NAMESPACE, '/agent/clients/analytics/summary', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'handle_get_clients_analytics_summary'),
            'permission_callback' => array(__CLASS__, 'check_agent_auth'),
        ));

        // Agent: Get property interests for a specific client (v6.40.0)
        register_rest_route(self::NAMESPACE, '/agent/clients/(?P<client_id>\d+)/property-interests', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'handle_get_client_property_interests'),
            'permission_callback' => array(__CLASS__, 'check_agent_auth'),
        ));

        // Agent: Get most viewed properties for a client (v6.41.3)
        register_rest_route(self::NAMESPACE, '/agent/clients/(?P<client_id>\d+)/most-viewed', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'handle_get_client_most_viewed'),
            'permission_callback' => array(__CLASS__, 'check_agent_auth'),
        ));

        // Agent: Get client preferences/profile analytics (v6.42.0)
        register_rest_route(self::NAMESPACE, '/agent/clients/(?P<client_id>\d+)/preferences', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'handle_get_client_preferences'),
            'permission_callback' => array(__CLASS__, 'check_agent_auth'),
        ));

        // Agent: Compare multiple clients (v6.40.0)
        register_rest_route(self::NAMESPACE, '/agent/clients/compare', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'handle_compare_clients'),
            'permission_callback' => array(__CLASS__, 'check_agent_auth'),
        ));

        // ============ Recently Viewed Properties Routes (v6.57.0) ============

        // Record a property view
        register_rest_route(self::NAMESPACE, '/recently-viewed', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'handle_record_property_view'),
            'permission_callback' => array(__CLASS__, 'check_auth'),
        ));

        // Get user's recently viewed properties
        register_rest_route(self::NAMESPACE, '/recently-viewed', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'handle_get_recently_viewed'),
            'permission_callback' => array(__CLASS__, 'check_auth'),
        ));
    }

    /**
     * Check authentication via JWT token or WordPress session
     * Supports both mobile (JWT) and web dashboard (nonce/session)
     *
     * @since 6.55.1 Added revocation check for access tokens
     */
    public static function check_auth($request) {
        global $wpdb;

        $auth_header = $request->get_header('Authorization');
        $has_jwt = !empty($auth_header) && strpos($auth_header, 'Bearer ') === 0;

        // IMPORTANT FIX: If JWT is present, ALWAYS use JWT authentication
        // This prevents WordPress session cookies from overriding JWT auth
        if ($has_jwt) {
            $token = substr($auth_header, 7);
            $payload = self::verify_jwt($token);

            if (is_wp_error($payload)) {
                return $payload;
            }

            if ($payload['type'] !== 'access') {
                return new WP_Error('invalid_token_type', 'Invalid token type', array('status' => 401));
            }

            // SECURITY: Check if this token has been revoked (e.g., via logout)
            $token_hash = hash('sha256', $token);
            $revoked_table = $wpdb->prefix . 'mld_revoked_tokens';

            $is_revoked = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $revoked_table WHERE token_hash = %s",
                $token_hash
            ));

            if ($is_revoked) {
                return new WP_Error('token_revoked', 'Token has been revoked. Please log in again.', array('status' => 401));
            }

            // Verify user exists before setting current user
            $user_id = absint($payload['sub']);
            $user = get_user_by('id', $user_id);

            if (!$user) {
                return new WP_Error('user_not_found', 'User no longer exists', array('status' => 401));
            }

            // Set the current user for the request
            wp_set_current_user($user_id);

            return true;
        }

        // No JWT - check if user is already logged in via WordPress session
        // This handles web dashboard with nonce authentication
        if (is_user_logged_in()) {
            return true;
        }

        // No JWT and no WordPress session - require authentication
        return new WP_Error('no_auth', 'Authorization required', array('status' => 401));
    }

    /**
     * Permission callback for analytics endpoints
     *
     * Analytics events are now tracked anonymously or via session.
     * The user_id in body is used for RECORDING analytics (who viewed what),
     * NOT for authentication. Analytics write endpoints are public.
     *
     * SECURITY: This callback allows public access for write-only analytics.
     * It does NOT authenticate users or grant access to protected data.
     *
     * @since 6.37.1
     * @since 6.54.2 Removed user_id impersonation vulnerability
     * @param WP_REST_Request $request Request object
     * @return bool|WP_Error True if allowed, WP_Error otherwise
     */
    public static function check_analytics_auth($request) {
        // Try normal auth first (WordPress session or JWT)
        $normal_auth = self::check_auth($request);
        if ($normal_auth === true) {
            return true;
        }

        // SECURITY FIX: Do NOT accept arbitrary user_id from body
        // Analytics events from unauthenticated users are recorded anonymously.
        // The user_id in the body is only used for analytics RECORDING purposes
        // when the user was previously authenticated and is using sendBeacon.

        // For sendBeacon with no auth headers, allow the request but
        // the handler should treat it as anonymous (user_id = 0)
        // The actual user attribution happens at page load when we have auth.
        return true;
    }

    /**
     * Permission callback for admin-only endpoints
     *
     * @since 6.48.0
     * @param WP_REST_Request $request Request object
     * @return bool|WP_Error True if allowed, WP_Error otherwise
     */
    public static function check_admin($request) {
        // First check authentication
        $auth_result = self::check_auth($request);
        if (is_wp_error($auth_result)) {
            return $auth_result;
        }

        // Check if user has admin capabilities
        if (!current_user_can('manage_options')) {
            return new WP_Error('forbidden', 'Administrator access required', array('status' => 403));
        }

        return true;
    }

    /**
     * Permission callback for agent-only endpoints
     *
     * SECURITY: Verifies the authenticated user is an agent before allowing access.
     * This prevents regular clients from accessing agent endpoints.
     *
     * @since 6.54.2
     * @param WP_REST_Request $request Request object
     * @return bool|WP_Error True if allowed, WP_Error otherwise
     */
    public static function check_agent_auth($request) {
        // First check authentication
        $auth_result = self::check_auth($request);
        if (is_wp_error($auth_result)) {
            return $auth_result;
        }

        $user_id = get_current_user_id();

        // Check if user is an agent using the User Type Manager
        if (class_exists('MLD_User_Type_Manager')) {
            if (MLD_User_Type_Manager::is_agent($user_id)) {
                return true;
            }
            // Also allow admins to access agent endpoints
            if (MLD_User_Type_Manager::is_admin($user_id)) {
                return true;
            }
        }

        // Fallback: check WordPress roles directly
        $user = get_userdata($user_id);
        if ($user) {
            $roles = (array) $user->roles;
            if (in_array('administrator', $roles) || in_array('agent', $roles) || in_array('editor', $roles)) {
                return true;
            }
        }

        return new WP_Error('forbidden', 'Agent access required', array('status' => 403));
    }

    /**
     * Generate JWT token
     */
    private static function generate_jwt($user_id, $type = 'access') {
        $secret = self::get_jwt_secret();
        $expiry = $type === 'access' ? self::ACCESS_TOKEN_EXPIRY : self::REFRESH_TOKEN_EXPIRY;

        $user = get_user_by('id', $user_id);

        $header = array(
            'typ' => 'JWT',
            'alg' => 'HS256'
        );

        $payload = array(
            'iss' => home_url(),
            'iat' => time(),
            'exp' => time() + $expiry,
            'sub' => $user_id,
            'email' => $user->user_email,
            'name' => $user->display_name,
            'type' => $type
        );

        $header_encoded = self::base64url_encode(json_encode($header));
        $payload_encoded = self::base64url_encode(json_encode($payload));

        $signature = hash_hmac('sha256', "$header_encoded.$payload_encoded", $secret, true);
        $signature_encoded = self::base64url_encode($signature);

        return "$header_encoded.$payload_encoded.$signature_encoded";
    }

    /**
     * Verify JWT token
     */
    private static function verify_jwt($token) {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            return new WP_Error('invalid_token', 'Invalid token format', array('status' => 401));
        }

        list($header_encoded, $payload_encoded, $signature_encoded) = $parts;

        // SECURITY: Validate algorithm to prevent "none" algorithm attack
        $header_decoded = self::base64url_decode($header_encoded);
        if ($header_decoded === false) {
            return new WP_Error('invalid_header', 'Could not decode token header', array('status' => 401));
        }

        $header = json_decode($header_decoded, true);
        if (!$header || !isset($header['alg'])) {
            return new WP_Error('invalid_header', 'Token header missing algorithm', array('status' => 401));
        }

        // Only accept HS256 - reject "none", "HS384", "RS256", etc.
        if ($header['alg'] !== 'HS256') {
            return new WP_Error('invalid_algorithm', 'Unsupported token algorithm', array('status' => 401));
        }

        $secret = self::get_jwt_secret();
        $expected_signature = self::base64url_encode(
            hash_hmac('sha256', "$header_encoded.$payload_encoded", $secret, true)
        );

        if (!hash_equals($expected_signature, $signature_encoded)) {
            return new WP_Error('invalid_signature', 'Invalid token signature', array('status' => 401));
        }

        $payload = json_decode(self::base64url_decode($payload_encoded), true);

        if (!$payload) {
            return new WP_Error('invalid_payload', 'Could not decode token payload', array('status' => 401));
        }

        // Validate required fields
        if (empty($payload['sub']) || empty($payload['type']) || empty($payload['exp'])) {
            return new WP_Error('invalid_payload', 'Token missing required fields', array('status' => 401));
        }

        // Check expiration
        if ($payload['exp'] < time()) {
            return new WP_Error('token_expired', 'Token has expired', array('status' => 401));
        }

        // Validate issuer if present (should match our site)
        if (isset($payload['iss']) && $payload['iss'] !== home_url()) {
            return new WP_Error('invalid_issuer', 'Token issued by unknown source', array('status' => 401));
        }

        return $payload;
    }

    /**
     * Base64 URL encode
     */
    private static function base64url_encode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Base64 URL decode
     * Returns false on invalid input for security validation
     */
    private static function base64url_decode($data) {
        // Validate input contains only valid base64url characters
        if (!is_string($data) || preg_match('/[^A-Za-z0-9_-]/', $data)) {
            return false;
        }

        $decoded = base64_decode(strtr($data, '-_', '+/'), true);

        // base64_decode returns false on failure when strict mode is enabled
        return $decoded;
    }

    /**
     * Get user avatar URL
     *
     * Returns the custom profile photo from mld_agent_profiles if available,
     * otherwise falls back to Gravatar.
     *
     * @param int $user_id WordPress user ID
     * @return string|null Avatar URL or null
     */
    public static function get_user_avatar_url($user_id) {
        global $wpdb;

        // First check for custom photo in agent profiles table
        if (class_exists('MLD_Saved_Search_Database')) {
            $table_name = MLD_Saved_Search_Database::get_table_name('agent_profiles');
        } else {
            $table_name = $wpdb->prefix . 'mld_agent_profiles';
        }

        $photo_url = $wpdb->get_var($wpdb->prepare(
            "SELECT photo_url FROM {$table_name} WHERE user_id = %d AND photo_url IS NOT NULL AND photo_url != ''",
            $user_id
        ));

        if (!empty($photo_url)) {
            return $photo_url;
        }

        // Fall back to Gravatar
        $user = get_user_by('ID', $user_id);
        if ($user) {
            return get_avatar_url($user->ID, array('size' => 200));
        }

        return null;
    }

    /**
     * Get the MLS Agent ID for a user
     *
     * Returns the MLS Agent ID from mld_agent_profiles for ShowingTime integration.
     * iOS uses this to build ShowingTime SSO URLs for appointment scheduling.
     *
     * @param int $user_id The WordPress user ID
     * @return string|null The MLS Agent ID or null if not found
     * @since 6.75.3
     */
    public static function get_user_mls_agent_id($user_id) {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_agent_profiles';

        $mls_agent_id = $wpdb->get_var($wpdb->prepare(
            "SELECT mls_agent_id FROM {$table} WHERE user_id = %d AND is_active = 1",
            $user_id
        ));

        return !empty($mls_agent_id) ? $mls_agent_id : null;
    }

    /**
     * Get listing keys that have been shared with a user (client)
     *
     * Used to mark properties as "shared by agent" in API responses.
     *
     * @param int $user_id WordPress user ID (client)
     * @return array Array of listing_key strings
     */
    private static function get_shared_listing_keys_for_user($user_id) {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_shared_properties';

        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table}'");
        if (!$table_exists) {
            return array();
        }

        return $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT listing_key FROM {$table}
             WHERE client_id = %d AND is_dismissed = 0",
            $user_id
        ));
    }

    /**
     * Get shared listing keys with agent info for a user (v6.35.9)
     *
     * Returns a map of listing_key => array with 'first_name' and 'photo_url'
     *
     * @param int $user_id WordPress user ID (client)
     * @return array Associative array mapping listing_key to agent info
     */
    private static function get_shared_agent_map_for_user($user_id) {
        global $wpdb;

        $shares_table = $wpdb->prefix . 'mld_shared_properties';
        $profiles_table = $wpdb->prefix . 'mld_agent_profiles';

        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$shares_table}'");
        if (!$table_exists) {
            return array();
        }

        $shares = $wpdb->get_results($wpdb->prepare(
            "SELECT sp.listing_key, ap.display_name, ap.photo_url
             FROM {$shares_table} sp
             LEFT JOIN {$profiles_table} ap ON ap.user_id = sp.agent_id
             WHERE sp.client_id = %d AND sp.is_dismissed = 0",
            $user_id
        ));

        $map = array();
        foreach ($shares as $share) {
            $first_name = explode(' ', trim($share->display_name))[0];
            $map[$share->listing_key] = array(
                'first_name' => $first_name,
                'photo_url' => $share->photo_url ?: ''
            );
        }

        return $map;
    }

    /**
     * Check if a listing was shared with the current user by their agent
     *
     * Public static method for use in templates.
     *
     * @param string $listing_key The listing key to check
     * @return bool True if shared by agent, false otherwise
     */
    public static function is_listing_shared_by_agent($listing_key) {
        $info = self::get_shared_agent_info($listing_key);
        return $info !== null;
    }

    /**
     * Get agent info for a shared listing (v6.35.9)
     *
     * @param string $listing_key The listing key to check
     * @return array|null Agent info array with 'first_name' and 'photo_url', or null if not shared
     */
    public static function get_shared_agent_info($listing_key) {
        if (!is_user_logged_in() || empty($listing_key)) {
            return null;
        }

        $user_id = get_current_user_id();

        // Use transient cache for performance (1 minute cache per user)
        $cache_key = 'mld_shared_agent_info_' . $user_id;
        $shared_agent_map = get_transient($cache_key);

        if ($shared_agent_map === false) {
            global $wpdb;
            $shares_table = $wpdb->prefix . 'mld_shared_properties';
            $profiles_table = $wpdb->prefix . 'mld_agent_profiles';
            $shared_agent_map = array();

            $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$shares_table}'");
            if ($table_exists) {
                $shares = $wpdb->get_results($wpdb->prepare(
                    "SELECT sp.listing_key, ap.display_name, ap.photo_url
                     FROM {$shares_table} sp
                     LEFT JOIN {$profiles_table} ap ON ap.user_id = sp.agent_id
                     WHERE sp.client_id = %d AND sp.is_dismissed = 0",
                    $user_id
                ));
                foreach ($shares as $share) {
                    $first_name = explode(' ', trim($share->display_name))[0];
                    $shared_agent_map[$share->listing_key] = array(
                        'first_name' => $first_name,
                        'photo_url' => $share->photo_url ?: ''
                    );
                }
            }
            set_transient($cache_key, $shared_agent_map, 60);
        }

        return isset($shared_agent_map[$listing_key]) ? $shared_agent_map[$listing_key] : null;
    }

    // ============ Authentication Handlers ============

    /**
     * Handle login
     */
    public static function handle_login($request) {
        // SECURITY: Prevent CDN from caching token responses
        self::send_no_cache_headers();

        $params = $request->get_json_params();
        $email = isset($params['email']) ? sanitize_email($params['email']) : '';
        $password = isset($params['password']) ? $params['password'] : '';

        if (empty($email) || empty($password)) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'missing_credentials',
                'message' => 'Email and password are required'
            ), 400);
        }

        // Check rate limit before authentication attempt
        $rate_limited = self::check_auth_rate_limit('login', $email);
        if ($rate_limited !== false) {
            return $rate_limited;
        }

        $user = wp_authenticate($email, $password);

        if (is_wp_error($user)) {
            // Record failed attempt
            self::record_failed_auth_attempt('login', $email);

            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'invalid_credentials',
                'message' => 'Invalid email or password'
            ), 401);
        }

        // Clear rate limit on successful login
        self::clear_auth_rate_limit('login', $email);

        $access_token = self::generate_jwt($user->ID, 'access');
        $refresh_token = self::generate_jwt($user->ID, 'refresh');

        // Get user type for agent/client detection
        $user_type = null;
        if (class_exists('MLD_User_Type_Manager')) {
            $user_type = MLD_User_Type_Manager::get_user_type($user->ID);
        }

        // Get assigned agent for client users
        $assigned_agent = null;
        if ($user_type !== 'agent' && class_exists('MLD_Agent_Client_Manager')) {
            $agent_data = MLD_Agent_Client_Manager::get_client_agent($user->ID);
            if ($agent_data) {
                $assigned_agent = MLD_Agent_Client_Manager::get_agent_for_api($agent_data['user_id']);
            }
        }

        // Get custom avatar URL (from agent profile or Gravatar fallback)
        $avatar_url = self::get_user_avatar_url($user->ID);

        // Get MLS Agent ID for ShowingTime integration (v6.75.3)
        $mls_agent_id = self::get_user_mls_agent_id($user->ID);

        // Trigger client login notification for agents (v6.43.0)
        do_action('mld_client_logged_in', $user->ID, 'ios');

        return new WP_REST_Response(array(
            'success' => true,
            'data' => array(
                'user' => array(
                    'id' => $user->ID,
                    'email' => $user->user_email,
                    'name' => $user->display_name,
                    'first_name' => get_user_meta($user->ID, 'first_name', true),
                    'last_name' => get_user_meta($user->ID, 'last_name', true),
                    'phone' => get_user_meta($user->ID, 'phone', true),
                    'avatar_url' => $avatar_url,
                    'user_type' => $user_type,
                    'assigned_agent' => $assigned_agent,
                    'mls_agent_id' => $mls_agent_id,
                ),
                'access_token' => $access_token,
                'refresh_token' => $refresh_token,
                'expires_in' => self::ACCESS_TOKEN_EXPIRY
            )
        ), 200);
    }

    /**
     * Handle registration
     */
    public static function handle_register($request) {
        $params = $request->get_json_params();
        $email = isset($params['email']) ? sanitize_email($params['email']) : '';
        $password = isset($params['password']) ? $params['password'] : '';
        $phone = isset($params['phone']) ? sanitize_text_field($params['phone']) : '';
        $referral_code = isset($params['referral_code']) ? sanitize_text_field($params['referral_code']) : '';

        // Accept first_name/last_name directly (iOS sends these) OR parse from single 'name' field (backwards compatible)
        $first_name = isset($params['first_name']) ? sanitize_text_field($params['first_name']) : '';
        $last_name = isset($params['last_name']) ? sanitize_text_field($params['last_name']) : '';
        $name = isset($params['name']) ? sanitize_text_field($params['name']) : '';

        // If first_name not provided but name is, parse name into first/last
        if (empty($first_name) && !empty($name)) {
            $name_parts = explode(' ', $name, 2);
            $first_name = $name_parts[0];
            $last_name = isset($name_parts[1]) ? $name_parts[1] : '';
        }

        // Check rate limit before registration attempt (use IP for registration)
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field($_SERVER['REMOTE_ADDR']) : 'unknown';
        $rate_limited = self::check_auth_rate_limit('register', $ip);
        if ($rate_limited !== false) {
            return $rate_limited;
        }

        if (empty($email) || empty($password)) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'missing_fields',
                'message' => 'Email and password are required'
            ), 400);
        }

        if (email_exists($email)) {
            // Record as failed attempt to prevent enumeration attacks
            self::record_failed_auth_attempt('register', $ip);

            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'email_exists',
                'message' => 'An account with this email already exists'
            ), 409);
        }

        $username = sanitize_user(explode('@', $email)[0], true);
        $base_username = $username;
        $counter = 1;
        while (username_exists($username)) {
            $username = $base_username . $counter;
            $counter++;
        }

        $user_id = wp_create_user($username, $password, $email);

        if (is_wp_error($user_id)) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'registration_failed',
                'message' => $user_id->get_error_message()
            ), 500);
        }

        // Update user meta with first/last name
        if (!empty($first_name)) {
            update_user_meta($user_id, 'first_name', $first_name);
        }
        if (!empty($last_name)) {
            update_user_meta($user_id, 'last_name', $last_name);
        }
        // Set display name from first/last name if provided
        if (!empty($first_name) || !empty($last_name)) {
            $display_name = trim($first_name . ' ' . $last_name);
            wp_update_user(array('ID' => $user_id, 'display_name' => $display_name));
        }

        if (!empty($phone)) {
            update_user_meta($user_id, 'phone', $phone);
        }

        // Assign client to agent based on referral code or default agent (v6.52.0)
        $assigned_agent = null;
        if (class_exists('MLD_Referral_Manager')) {
            $source = !empty($referral_code) ? 'referral_link' : 'organic';
            $assignment_result = MLD_Referral_Manager::assign_client_on_register(
                $user_id,
                $referral_code,
                $source,
                'ios'
            );

            // If assignment was successful, get agent info for response
            if ($assignment_result && isset($assignment_result['agent_user_id'])) {
                if (class_exists('MLD_Agent_Client_Manager')) {
                    $assigned_agent = MLD_Agent_Client_Manager::get_agent_for_api($assignment_result['agent_user_id']);
                }
            }
        }

        $user = get_user_by('id', $user_id);
        $access_token = self::generate_jwt($user_id, 'access');
        $refresh_token = self::generate_jwt($user_id, 'refresh');

        // Get custom avatar URL
        $avatar_url = self::get_user_avatar_url($user_id);

        return new WP_REST_Response(array(
            'success' => true,
            'data' => array(
                'user' => array(
                    'id' => $user->ID,
                    'email' => $user->user_email,
                    'name' => $user->display_name,
                    'first_name' => get_user_meta($user_id, 'first_name', true),
                    'last_name' => get_user_meta($user_id, 'last_name', true),
                    'phone' => get_user_meta($user_id, 'phone', true),
                    'avatar_url' => $avatar_url,
                    'user_type' => 'client',
                    'assigned_agent' => $assigned_agent,
                ),
                'access_token' => $access_token,
                'refresh_token' => $refresh_token,
                'expires_in' => self::ACCESS_TOKEN_EXPIRY
            )
        ), 201);
    }

    /**
     * Handle token refresh
     *
     * SECURITY: Implements refresh token rotation with blacklist.
     * Each refresh token can only be used ONCE. After use, it's added to the
     * revoked tokens table. If an attacker tries to replay a stolen token,
     * it will be rejected.
     */
    public static function handle_refresh($request) {
        global $wpdb;

        // SECURITY: Prevent CDN from caching token responses
        self::send_no_cache_headers();

        $params = $request->get_json_params();
        $refresh_token = isset($params['refresh_token']) ? $params['refresh_token'] : '';

        if (empty($refresh_token)) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'missing_token',
                'message' => 'Refresh token is required'
            ), 400);
        }

        $payload = self::verify_jwt($refresh_token);

        if (is_wp_error($payload)) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => $payload->get_error_code(),
                'message' => $payload->get_error_message()
            ), 401);
        }

        if ($payload['type'] !== 'refresh') {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'invalid_token_type',
                'message' => 'Invalid token type'
            ), 401);
        }

        // SECURITY: Check if this refresh token has already been used (token rotation)
        $token_hash = hash('sha256', $refresh_token);
        $revoked_table = $wpdb->prefix . 'mld_revoked_tokens';

        $is_revoked = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $revoked_table WHERE token_hash = %s",
            $token_hash
        ));

        if ($is_revoked) {
            // Token reuse detected - this could be a replay attack!
            // Log the incident for security monitoring
            error_log('[MLD SECURITY] Refresh token reuse detected for user ' . $payload['sub'] . '. Possible token theft.');

            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'token_revoked',
                'message' => 'This refresh token has already been used. Please log in again.'
            ), 401);
        }

        // SECURITY: Revoke the old token BEFORE generating new ones
        // Calculate when this token would naturally expire (for cleanup purposes)
        $expires_at = date('Y-m-d H:i:s', $payload['exp']);

        $wpdb->insert(
            $revoked_table,
            array(
                'token_hash' => $token_hash,
                'user_id' => $payload['sub'],
                'revoked_at' => current_time('mysql'),
                'expires_at' => $expires_at
            ),
            array('%s', '%d', '%s', '%s')
        );

        // Generate new tokens
        $user_id = $payload['sub'];
        $access_token = self::generate_jwt($user_id, 'access');
        $new_refresh_token = self::generate_jwt($user_id, 'refresh');

        // Get user data to return (keeps iOS app in sync)
        $user = get_userdata($user_id);
        if (!$user) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'user_not_found',
                'message' => 'User account not found'
            ), 401);
        }

        // Get user type for agent/client detection
        $user_type = null;
        if (class_exists('MLD_User_Type_Manager')) {
            $user_type = MLD_User_Type_Manager::get_user_type($user_id);
        }

        // Get assigned agent for client users
        $assigned_agent = null;
        if ($user_type !== 'agent' && class_exists('MLD_Agent_Client_Manager')) {
            $agent_data = MLD_Agent_Client_Manager::get_client_agent($user_id);
            if ($agent_data) {
                $assigned_agent = MLD_Agent_Client_Manager::get_agent_for_api($agent_data['user_id']);
            }
        }

        // Get custom avatar URL
        $avatar_url = self::get_user_avatar_url($user_id);

        return new WP_REST_Response(array(
            'success' => true,
            'data' => array(
                'user' => array(
                    'id' => $user->ID,
                    'email' => $user->user_email,
                    'name' => $user->display_name,
                    'first_name' => get_user_meta($user_id, 'first_name', true),
                    'last_name' => get_user_meta($user_id, 'last_name', true),
                    'avatar_url' => $avatar_url,
                    'user_type' => $user_type,
                    'assigned_agent' => $assigned_agent,
                ),
                'access_token' => $access_token,
                'refresh_token' => $new_refresh_token,
                'expires_in' => self::ACCESS_TOKEN_EXPIRY
            )
        ), 200);
    }

    /**
     * Handle forgot password
     */
    public static function handle_forgot_password($request) {
        $params = $request->get_json_params();
        $email = isset($params['email']) ? sanitize_email($params['email']) : '';

        if (empty($email)) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'missing_email',
                'message' => 'Email is required'
            ), 400);
        }

        $user = get_user_by('email', $email);

        // Always return success to prevent email enumeration
        if ($user) {
            $reset_key = get_password_reset_key($user);
            if (!is_wp_error($reset_key)) {
                $reset_url = network_site_url("wp-login.php?action=rp&key=$reset_key&login=" . rawurlencode($user->user_login), 'login');

                // Build HTML email with unified footer
                $message = self::build_password_reset_email_html($user, $reset_url);

                // Get headers with dynamic from address
                $headers = [];
                if (class_exists('MLD_Email_Utilities')) {
                    $headers = MLD_Email_Utilities::get_email_headers($user->ID);
                } else {
                    $headers = [
                        'Content-Type: text/html; charset=UTF-8',
                        'From: BMN Boston <' . get_option('admin_email') . '>',
                    ];
                }

                wp_mail($email, 'Password Reset Request - BMN Boston', $message, $headers);
            }
        }

        return new WP_REST_Response(array(
            'success' => true,
            'message' => 'If an account exists with this email, a password reset link has been sent.'
        ), 200);
    }

    /**
     * Handle get current user
     */
    public static function handle_get_me($request) {
        $user = wp_get_current_user();

        // DEBUG: Log what user is being returned
        error_log('[MLD_AUTH_DEBUG] handle_get_me returning user: ' . $user->ID . ' (' . $user->user_email . ')');

        // Get user type info (v6.32.0)
        $user_type_data = [];
        if (class_exists('MLD_User_Type_Manager')) {
            $user_type_data = MLD_User_Type_Manager::get_user_type_for_api($user->ID);
        }

        // Get assigned agent if user is a client (v6.32.0)
        $assigned_agent = null;
        if (class_exists('MLD_Agent_Client_Manager')) {
            $agent_data = MLD_Agent_Client_Manager::get_client_agent($user->ID);
            if ($agent_data) {
                $assigned_agent = MLD_Agent_Client_Manager::get_agent_for_api($agent_data['user_id']);
            }
        }

        // Get custom avatar URL (v6.33.13)
        $avatar_url = self::get_user_avatar_url($user->ID);

        // Get MLS Agent ID for ShowingTime integration (v6.75.3)
        $mls_agent_id = self::get_user_mls_agent_id($user->ID);

        $response = new WP_REST_Response(array(
            'success' => true,
            'data' => array(
                'id' => $user->ID,
                'email' => $user->user_email,
                'name' => $user->display_name,
                'first_name' => get_user_meta($user->ID, 'first_name', true),
                'last_name' => get_user_meta($user->ID, 'last_name', true),
                'phone' => get_user_meta($user->ID, 'phone', true),
                'avatar_url' => $avatar_url,
                // v6.32.0 additions
                'user_type' => $user_type_data['type'] ?? 'client',
                'is_agent' => $user_type_data['is_agent'] ?? false,
                'is_admin' => $user_type_data['is_admin'] ?? false,
                'agent_profile_id' => $user_type_data['agent_profile_id'] ?? null,
                'assigned_agent' => $assigned_agent,
                // v6.75.3 - ShowingTime integration
                'mls_agent_id' => $mls_agent_id,
            )
        ), 200);

        // CRITICAL: Prevent CDN from caching user-specific response
        $response->header('Cache-Control', 'no-store, no-cache, must-revalidate, private');
        $response->header('Pragma', 'no-cache');
        $response->header('X-Kinsta-Cache', 'BYPASS');

        return $response;
    }

    /**
     * Handle logout
     *
     * Revokes the current access token to prevent further use.
     * This is important for security: if a token is compromised, the user
     * can log out to invalidate it server-side.
     *
     * @since 6.55.1 Added token revocation on logout
     */
    public static function handle_logout($request) {
        global $wpdb;

        // Extract the access token from the Authorization header
        $auth_header = $request->get_header('Authorization');
        if (!empty($auth_header) && strpos($auth_header, 'Bearer ') === 0) {
            $token = substr($auth_header, 7);

            // Verify the token to get user info and expiry
            $payload = self::verify_jwt($token);

            if (!is_wp_error($payload)) {
                // Add to revoked tokens table
                $token_hash = hash('sha256', $token);
                $revoked_table = $wpdb->prefix . 'mld_revoked_tokens';
                $expires_at = date('Y-m-d H:i:s', $payload['exp']);

                $wpdb->insert(
                    $revoked_table,
                    array(
                        'token_hash' => $token_hash,
                        'user_id' => $payload['sub'],
                        'revoked_at' => current_time('mysql'),
                        'expires_at' => $expires_at
                    ),
                    array('%s', '%d', '%s', '%s')
                );

                error_log('[MLD_AUTH] Token revoked on logout for user ' . $payload['sub']);
            }
        }

        return new WP_REST_Response(array(
            'success' => true,
            'message' => 'Logged out successfully'
        ), 200);
    }

    /**
     * Handle account deletion request
     * Apple App Store Guideline 5.1.1(v) compliance
     *
     * @since 6.51.0
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response Response object
     */
    public static function handle_delete_account($request) {
        global $wpdb;

        $user_id = get_current_user_id();
        if (!$user_id) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'unauthorized',
                'message' => 'Authentication required'
            ), 401);
        }

        $user = get_userdata($user_id);
        if (!$user) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'user_not_found',
                'message' => 'User not found'
            ), 404);
        }

        // Store email for confirmation before deletion
        $user_email = $user->user_email;
        $user_name = $user->display_name;

        // Check if user is an agent with active clients
        $user_type = self::get_user_type($user_id);
        if ($user_type === 'agent') {
            $relationships_table = $wpdb->prefix . 'mld_agent_client_relationships';
            $active_clients = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$relationships_table} WHERE agent_id = %d AND relationship_status = 'active'",
                $user_id
            ));

            if ($active_clients > 0) {
                // Auto-reassign clients to default admin
                $default_admin_id = get_option('mld_default_agent_id', 1);
                $wpdb->update(
                    $relationships_table,
                    array(
                        'agent_id' => $default_admin_id,
                        'reassigned_at' => current_time('mysql'),
                        'reassigned_reason' => 'Original agent deleted account'
                    ),
                    array('agent_id' => $user_id, 'relationship_status' => 'active'),
                    array('%d', '%s', '%s'),
                    array('%d', '%s')
                );
            }
        }

        // Delete all user data from MLD tables
        $deletion_result = self::delete_user_data($user_id);

        if (!$deletion_result['success']) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'deletion_failed',
                'message' => 'Failed to delete user data: ' . $deletion_result['message']
            ), 500);
        }

        // Send confirmation email before deleting WordPress user
        self::send_account_deletion_email($user_email, $user_name);

        // Delete the WordPress user account
        require_once(ABSPATH . 'wp-admin/includes/user.php');
        $delete_result = wp_delete_user($user_id);

        if (!$delete_result) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'wp_user_deletion_failed',
                'message' => 'Failed to delete WordPress user account'
            ), 500);
        }

        return new WP_REST_Response(array(
            'success' => true,
            'message' => 'Account and all associated data have been permanently deleted',
            'data' => array(
                'deleted_tables' => $deletion_result['tables_cleaned']
            )
        ), 200);
    }

    /**
     * Delete all user data from MLD plugin tables
     *
     * @since 6.51.0
     * @param int $user_id User ID to delete data for
     * @return array Result with success status and tables cleaned
     */
    private static function delete_user_data($user_id) {
        global $wpdb;

        $tables_cleaned = array();
        $errors = array();

        // List of tables and their user ID column
        $tables_to_clean = array(
            // Saved searches and results
            'mld_saved_searches' => 'user_id',
            'mld_saved_search_activity' => 'user_id',
            'mld_property_preferences' => 'user_id',

            // Device tokens and notifications
            'mld_device_tokens' => 'user_id',
            'mld_push_notification_log' => 'user_id',
            'mld_push_retry_queue' => 'user_id',
            'mld_user_badge_counts' => 'user_id',
            'mld_notification_engagement' => 'user_id',
            'mld_notification_throttle' => 'user_id',

            // Email and notification preferences
            'mld_user_email_preferences' => 'user_id',
            'mld_client_notification_preferences' => 'user_id',

            // Client activity and analytics
            'mld_client_activity' => 'user_id',
            'mld_client_sessions' => 'user_id',
            'mld_client_analytics_summary' => 'user_id',
            'mld_client_engagement_scores' => 'user_id',
            'mld_client_property_interest' => 'user_id',
            'mld_client_app_opens' => 'user_id',

            // User types
            'mld_user_types' => 'user_id',

            // Agent-client relationships (as client)
            'mld_agent_client_relationships' => 'client_id',

            // Shared properties (as client)
            'mld_shared_properties' => 'client_id',

            // Referral signups (as client)
            'mld_referral_signups' => 'client_user_id',

            // CMA sessions
            'mld_cma_saved_sessions' => 'user_id',

            // Form submissions
            'mld_form_submissions' => 'user_id',

            // Public analytics (anonymize instead of delete)
            'mld_public_sessions' => 'user_id',
            'mld_public_events' => 'user_id',
        );

        // Additional tables for agent users
        $agent_tables = array(
            'mld_agent_profiles' => 'user_id',
            'mld_agent_notification_preferences' => 'agent_id',
            'mld_agent_notification_log' => 'agent_id',
            'mld_shared_properties' => 'agent_id',
            'mld_agent_referral_codes' => 'agent_user_id',
        );

        // Check if user is an agent and add agent tables
        $user_type = self::get_user_type($user_id);
        if ($user_type === 'agent') {
            $tables_to_clean = array_merge($tables_to_clean, $agent_tables);
        }

        // Delete from each table
        foreach ($tables_to_clean as $table_suffix => $column) {
            $table_name = $wpdb->prefix . $table_suffix;

            // Check if table exists
            $table_exists = $wpdb->get_var($wpdb->prepare(
                "SHOW TABLES LIKE %s",
                $table_name
            ));

            if ($table_exists) {
                $deleted = $wpdb->delete(
                    $table_name,
                    array($column => $user_id),
                    array('%d')
                );

                if ($deleted !== false) {
                    $tables_cleaned[] = $table_suffix;
                } else {
                    $errors[] = $table_suffix . ': ' . $wpdb->last_error;
                }
            }
        }

        // Delete saved search results (cascade from saved searches)
        $saved_search_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}mld_saved_searches WHERE user_id = %d",
            $user_id
        ));

        if (!empty($saved_search_ids)) {
            $ids_placeholder = implode(',', array_fill(0, count($saved_search_ids), '%d'));

            // Delete saved search results
            $results_table = $wpdb->prefix . 'mld_saved_search_results';
            if ($wpdb->get_var("SHOW TABLES LIKE '{$results_table}'")) {
                $wpdb->query($wpdb->prepare(
                    "DELETE FROM {$results_table} WHERE saved_search_id IN ({$ids_placeholder})",
                    ...$saved_search_ids
                ));
                $tables_cleaned[] = 'mld_saved_search_results';
            }

            // Delete email settings for saved searches
            $email_table = $wpdb->prefix . 'mld_saved_search_email_settings';
            if ($wpdb->get_var("SHOW TABLES LIKE '{$email_table}'")) {
                $wpdb->query($wpdb->prepare(
                    "DELETE FROM {$email_table} WHERE saved_search_id IN ({$ids_placeholder})",
                    ...$saved_search_ids
                ));
                $tables_cleaned[] = 'mld_saved_search_email_settings';
            }
        }

        return array(
            'success' => empty($errors),
            'tables_cleaned' => $tables_cleaned,
            'errors' => $errors,
            'message' => empty($errors) ? 'All user data deleted' : implode('; ', $errors)
        );
    }

    /**
     * Send account deletion confirmation email
     *
     * @since 6.51.0
     * @param string $email User's email address
     * @param string $name User's display name
     */
    private static function send_account_deletion_email($email, $name) {
        $site_name = get_bloginfo('name');

        $subject = "Your {$site_name} Account Has Been Deleted";

        $message = "
        <html>
        <head>
            <style>
                body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #0891B2; color: white; padding: 30px; text-align: center; border-radius: 8px 8px 0 0; }
                .content { background: #f9fafb; padding: 30px; border: 1px solid #e5e7eb; }
                .footer { background: #f3f4f6; padding: 20px; text-align: center; font-size: 12px; color: #6b7280; border-radius: 0 0 8px 8px; }
                h1 { margin: 0; font-size: 24px; }
                .checkmark { font-size: 48px; margin-bottom: 10px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <div class='checkmark'>&#x2713;</div>
                    <h1>Account Deleted</h1>
                </div>
                <div class='content'>
                    <p>Hi {$name},</p>
                    <p>This email confirms that your {$site_name} account has been permanently deleted.</p>
                    <p><strong>What was deleted:</strong></p>
                    <ul>
                        <li>Your account and login credentials</li>
                        <li>All saved searches</li>
                        <li>All saved/favorited properties</li>
                        <li>All hidden properties</li>
                        <li>All notification history and preferences</li>
                        <li>All activity data</li>
                    </ul>
                    <p>This action cannot be undone. If you wish to use {$site_name} again in the future, you'll need to create a new account.</p>
                    <p>If you did not request this deletion, please contact us immediately.</p>
                </div>
                <div class='footer'>
                    <p>Questions? Contact us at info@bmnboston.com</p>
                    <p>&copy; " . date('Y') . " {$site_name}. All rights reserved.</p>
                </div>
            </div>
        </body>
        </html>";

        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $site_name . ' <' . get_option('admin_email') . '>'
        );

        wp_mail($email, $subject, $message, $headers);
    }

    /**
     * Build password reset email HTML
     *
     * @since 6.63.0
     * @param WP_User $user User object
     * @param string $reset_url Password reset URL
     * @return string Email HTML
     */
    private static function build_password_reset_email_html($user, $reset_url) {
        $first_name = $user->first_name ?: 'there';
        $site_name = get_bloginfo('name');
        $site_url = home_url();

        $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin:0;padding:0;background:#f4f4f4;font-family:Arial,Helvetica,sans-serif;">
    <table cellpadding="0" cellspacing="0" width="100%" style="background:#f4f4f4;padding:20px 0;">
        <tr>
            <td align="center">
                <table cellpadding="0" cellspacing="0" width="600" style="background:#ffffff;border-radius:10px;overflow:hidden;box-shadow:0 2px 10px rgba(0,0,0,0.1);">
                    <!-- Header -->
                    <tr>
                        <td style="background:#1e3a5f;padding:30px;text-align:center;">
                            <h1 style="color:#ffffff;margin:0;font-size:28px;">' . esc_html($site_name) . '</h1>
                            <p style="color:#94a3b8;margin:10px 0 0 0;font-size:14px;">Password Reset Request</p>
                        </td>
                    </tr>

                    <!-- Content -->
                    <tr>
                        <td style="padding:30px 40px;">
                            <h2 style="color:#1a1a1a;font-size:22px;margin:0 0 20px 0;">Hi ' . esc_html($first_name) . ',</h2>

                            <p style="color:#4a4a4a;font-size:16px;margin:0 0 20px 0;line-height:1.6;">
                                We received a request to reset the password for your account. If you made this request, click the button below to set a new password:
                            </p>

                            <!-- CTA Button -->
                            <div style="text-align:center;margin:30px 0;">
                                <a href="' . esc_url($reset_url) . '" style="display:inline-block;background:#1e3a5f;color:#ffffff;text-decoration:none;padding:14px 30px;border-radius:8px;font-size:16px;font-weight:600;">
                                    Reset Password
                                </a>
                            </div>

                            <p style="color:#6b7280;font-size:14px;margin:20px 0 0 0;line-height:1.6;">
                                This link will expire in 24 hours for security reasons.
                            </p>

                            <p style="color:#6b7280;font-size:14px;margin:15px 0 0 0;line-height:1.6;">
                                If you didn\'t request a password reset, you can safely ignore this email. Your password will remain unchanged.
                            </p>

                            <!-- Security Notice -->
                            <div style="background:#fef3cd;border-radius:8px;padding:15px;margin-top:25px;border-left:4px solid #ffc107;">
                                <p style="margin:0;color:#856404;font-size:13px;">
                                    <strong>Security Tip:</strong> Never share your password or this link with anyone. ' . esc_html($site_name) . ' will never ask for your password via email.
                                </p>
                            </div>
                        </td>
                    </tr>';

        // Add unified footer
        if (class_exists('MLD_Email_Utilities')) {
            $html .= '
                    <tr>
                        <td style="background:#f8f9fa;padding:20px 40px;">
                            ' . MLD_Email_Utilities::get_unified_footer([
                                'context' => 'general',
                                'show_social' => true,
                                'show_app_download' => true,
                                'compact' => true,
                            ]) . '
                        </td>
                    </tr>';
        } else {
            $html .= '
                    <!-- Footer -->
                    <tr>
                        <td style="background:#f8f9fa;padding:20px 40px;text-align:center;">
                            <p style="margin:0;color:#6b7280;font-size:12px;">
                                ' . esc_html($site_name) . ' | <a href="' . esc_url($site_url) . '" style="color:#1e3a5f;">bmnboston.com</a>
                            </p>
                        </td>
                    </tr>';
        }

        $html .= '
                </table>
            </td>
        </tr>
    </table>
</body>
</html>';

        return $html;
    }

    /**
     * Get user type (client, agent, admin)
     *
     * @since 6.51.0
     * @param int $user_id User ID
     * @return string User type
     */
    private static function get_user_type($user_id) {
        global $wpdb;

        // Check MLD user types table first
        $user_types_table = $wpdb->prefix . 'mld_user_types';
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$user_types_table}'");

        if ($table_exists) {
            $user_type = $wpdb->get_var($wpdb->prepare(
                "SELECT user_type FROM {$user_types_table} WHERE user_id = %d",
                $user_id
            ));

            if ($user_type) {
                return $user_type;
            }
        }

        // Fall back to WordPress roles
        $user = get_userdata($user_id);
        if ($user) {
            if (in_array('administrator', (array) $user->roles)) {
                return 'admin';
            }
            if (in_array('agent', (array) $user->roles) || in_array('editor', (array) $user->roles)) {
                return 'agent';
            }
        }

        return 'client';
    }

    // ============ Filter Options Handler (v6.59.0) ============

    /**
     * Handle get filter options
     *
     * Returns available filter values (like home types/property_sub_type) based on
     * current filter selections (listing_type, property_type).
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function handle_get_filter_options($request) {
        global $wpdb;

        $filters = array();

        // Get listing type parameter (for_sale or for_rent)
        $listing_type = sanitize_text_field($request->get_param('listing_type'));

        // Map listing type to property_type values
        if ($listing_type === 'for_sale') {
            $filters['property_type'] = array('Residential', 'Residential Income', 'Commercial Sale', 'Land', 'Business Opportunity');
        } elseif ($listing_type === 'for_rent') {
            $filters['property_type'] = array('Residential Lease', 'Commercial Lease');
        }

        // Get property_type parameter (can override listing_type mapping)
        $property_type = $request->get_param('property_type');
        if (!empty($property_type)) {
            if (is_array($property_type)) {
                $filters['property_type'] = array_map('sanitize_text_field', $property_type);
            } else {
                $filters['property_type'] = array(sanitize_text_field($property_type));
            }
        }

        // Query distinct property_sub_type values from the database
        $summary_table = $wpdb->prefix . 'bme_listing_summary';
        $where_clauses = array("standard_status = 'Active'");
        $params = array();

        // Filter by property_type if specified
        if (!empty($filters['property_type'])) {
            $placeholders = implode(',', array_fill(0, count($filters['property_type']), '%s'));
            $where_clauses[] = "property_type IN ($placeholders)";
            $params = array_merge($params, $filters['property_type']);
        }

        $where_sql = implode(' AND ', $where_clauses);

        // Get distinct property_sub_type values with counts
        $query = "SELECT property_sub_type, COUNT(*) as count
                  FROM {$summary_table}
                  WHERE {$where_sql}
                    AND property_sub_type IS NOT NULL
                    AND property_sub_type != ''
                  GROUP BY property_sub_type
                  ORDER BY count DESC, property_sub_type ASC";

        if (!empty($params)) {
            $query = $wpdb->prepare($query, $params);
        }

        $results = $wpdb->get_results($query);

        // Format home types with counts
        $home_types = array();
        $home_types_with_counts = array();
        foreach ($results as $row) {
            $home_types[] = $row->property_sub_type;
            $home_types_with_counts[] = array(
                'value' => $row->property_sub_type,
                'label' => $row->property_sub_type,
                'count' => (int) $row->count,
            );
        }

        return new WP_REST_Response(array(
            'success' => true,
            'data' => array(
                'home_types' => $home_types,
                'home_types_with_counts' => $home_types_with_counts,
            ),
        ), 200);
    }

    // ============ Property Handlers ============

    /**
     * Handle get properties
     */
    public static function handle_get_properties($request) {
        // v6.54.4: Check rate limit before processing
        $rate_limited = self::check_public_rate_limit('properties');
        if ($rate_limited !== false) {
            return $rate_limited;
        }

        global $wpdb;

        // v6.35.7: Get user ID from JWT token if provided (for is_shared_by_agent field)
        // This is needed BEFORE cache check so we can include user_id in cache key
        $auth_user_id = 0;
        $auth_header = $request->get_header('Authorization');
        if (!empty($auth_header) && strpos($auth_header, 'Bearer ') === 0) {
            $token = substr($auth_header, 7);
            $payload = self::verify_jwt($token);
            if (!is_wp_error($payload) && isset($payload['sub'])) {
                $auth_user_id = absint($payload['sub']);
            }
        }

        // v6.35.12: Fetch shared properties early for SQL-level sort prioritization
        // Shared properties must appear FIRST in search results regardless of other sort order
        $shared_agent_map = array();
        $shared_listing_keys = array();
        if ($auth_user_id > 0) {
            $shared_agent_map = self::get_shared_agent_map_for_user($auth_user_id);
            $shared_listing_keys = array_keys($shared_agent_map);
        }

        // Generate cache key from request parameters + user ID
        $cache_params = $request->get_params();
        $cache_params['_user_id'] = $auth_user_id; // Include user ID in cache key
        ksort($cache_params); // Ensure consistent ordering
        $cache_key = 'mld_mobile_props_' . md5(json_encode($cache_params));

        // Try to get from transient cache (2 minute cache for mobile API)
        $cached_result = get_transient($cache_key);
        if ($cached_result !== false) {
            return new WP_REST_Response($cached_result, 200);
        }

        // Basic pagination
        $page = absint($request->get_param('page')) ?: 1;
        $per_page = absint($request->get_param('per_page')) ?: 20;

        // Location filters
        $city = $request->get_param('city'); // Can be string or array
        $zip = $request->get_param('zip'); // Can be string or array
        $neighborhood = $request->get_param('neighborhood'); // Can be string or array

        // Specific property filters (address, MLS number, street name)
        $address = sanitize_text_field($request->get_param('address'));
        $mls_number = sanitize_text_field($request->get_param('mls_number'));
        $street_name = sanitize_text_field($request->get_param('street_name'));

        // v6.68.0: Detect direct property lookups that should bypass filters
        // When searching for a specific property (MLS number or exact address),
        // return that property regardless of other active filters.
        // This matches the behavior in class-mld-query.php lines 1171-1203
        $has_direct_property_lookup = !empty($mls_number) || !empty($address);

        // Price filters
        $min_price = absint($request->get_param('min_price'));
        $max_price = absint($request->get_param('max_price'));

        // Basic property filters
        $beds = absint($request->get_param('beds'));
        $baths = $request->get_param('baths');
        $property_type = $request->get_param('property_type');
        $property_sub_type = $request->get_param('property_sub_type'); // v6.59.0: Home type filter

        // Square footage
        $sqft_min = absint($request->get_param('sqft_min'));
        $sqft_max = absint($request->get_param('sqft_max'));

        // Year built
        $year_built_min = absint($request->get_param('year_built_min'));
        $year_built_max = absint($request->get_param('year_built_max'));

        // Lot size (in sqft)
        $lot_size_min = absint($request->get_param('lot_size_min'));
        $lot_size_max = absint($request->get_param('lot_size_max'));

        // Parking filters
        $garage_spaces_min = absint($request->get_param('garage_spaces_min'));
        $parking_total_min = absint($request->get_param('parking_total_min'));

        // Days on market
        $max_dom = absint($request->get_param('max_dom'));
        $min_dom = absint($request->get_param('min_dom'));

        // Special filters
        $new_listing_days = absint($request->get_param('new_listing_days'));
        $price_reduced = filter_var($request->get_param('price_reduced'), FILTER_VALIDATE_BOOLEAN);
        $status = $request->get_param('status'); // Can be string or array
        $open_house_only = filter_var($request->get_param('open_house_only'), FILTER_VALIDATE_BOOLEAN);

        // v6.64.0: Exclusive listings filter (listing_id < 1,000,000 = exclusive, MLS IDs are 60M+)
        $exclusive_only = filter_var($request->get_param('exclusive_only'), FILTER_VALIDATE_BOOLEAN);

        // School quality filters (Phase 4 - BMN Schools integration)
        $school_grade = sanitize_text_field($request->get_param('school_grade')); // A, B, C
        $school_district_id = absint($request->get_param('school_district_id'));

        // Elementary school filters (K-4) - within 1 mile
        $near_a_elementary = filter_var($request->get_param('near_a_elementary'), FILTER_VALIDATE_BOOLEAN);
        $near_ab_elementary = filter_var($request->get_param('near_ab_elementary'), FILTER_VALIDATE_BOOLEAN);

        // Middle school filters (4-8) - within 1 mile
        $near_a_middle = filter_var($request->get_param('near_a_middle'), FILTER_VALIDATE_BOOLEAN);
        $near_ab_middle = filter_var($request->get_param('near_ab_middle'), FILTER_VALIDATE_BOOLEAN);

        // High school filters (9-12) - within 1 mile
        $near_a_high = filter_var($request->get_param('near_a_high'), FILTER_VALIDATE_BOOLEAN);
        $near_ab_high = filter_var($request->get_param('near_ab_high'), FILTER_VALIDATE_BOOLEAN);

        // Legacy school filters (v6.29 and earlier) - 2mi for elementary, 3mi for high
        $near_top_elementary = filter_var($request->get_param('near_top_elementary'), FILTER_VALIDATE_BOOLEAN);
        $near_top_high = filter_var($request->get_param('near_top_high'), FILTER_VALIDATE_BOOLEAN);

        // Build school filter criteria array
        $school_criteria = array(
            'school_grade' => $school_grade,
            'near_a_elementary' => $near_a_elementary,
            'near_ab_elementary' => $near_ab_elementary,
            'near_a_middle' => $near_a_middle,
            'near_ab_middle' => $near_ab_middle,
            'near_a_high' => $near_a_high,
            'near_ab_high' => $near_ab_high,
            'near_top_elementary' => $near_top_elementary, // Legacy (v6.29)
            'near_top_high' => $near_top_high,             // Legacy (v6.29)
            'school_district_id' => $school_district_id,
        );
        $has_school_filters = MLD_BMN_Schools_Integration::has_school_filters($school_criteria);

        // Amenity filters (boolean YN fields) - from listing_features table
        $has_pool = filter_var($request->get_param('PoolPrivateYN'), FILTER_VALIDATE_BOOLEAN);
        $has_waterfront = filter_var($request->get_param('WaterfrontYN'), FILTER_VALIDATE_BOOLEAN);
        $has_view = filter_var($request->get_param('ViewYN'), FILTER_VALIDATE_BOOLEAN);
        $has_water_view = filter_var($request->get_param('MLSPIN_WATERVIEW_FLAG'), FILTER_VALIDATE_BOOLEAN);
        $has_spa = filter_var($request->get_param('SpaYN'), FILTER_VALIDATE_BOOLEAN);
        $has_outdoor_space = filter_var($request->get_param('MLSPIN_OUTDOOR_SPACE_AVAILABLE'), FILTER_VALIDATE_BOOLEAN);
        $is_senior_community = filter_var($request->get_param('SeniorCommunityYN'), FILTER_VALIDATE_BOOLEAN);

        // Amenity filters (boolean YN fields) - from listing_details table
        $has_fireplace = filter_var($request->get_param('FireplaceYN'), FILTER_VALIDATE_BOOLEAN);
        $has_garage = filter_var($request->get_param('GarageYN'), FILTER_VALIDATE_BOOLEAN);
        $has_cooling = filter_var($request->get_param('CoolingYN'), FILTER_VALIDATE_BOOLEAN);
        $has_virtual_tour = filter_var($request->get_param('has_virtual_tour'), FILTER_VALIDATE_BOOLEAN);

        // Amenity filters from summary table (v6.30.22 - parity with web)
        $has_basement = filter_var($request->get_param('has_basement'), FILTER_VALIDATE_BOOLEAN);
        $pet_friendly = filter_var($request->get_param('pet_friendly'), FILTER_VALIDATE_BOOLEAN);

        // Rental-specific filters (v6.60.0 - Phase 1 rental filters)
        // pets_allowed: null = any, 1 = yes, 0 = no (legacy parameter)
        $pets_allowed = $request->get_param('pets_allowed');
        if ($pets_allowed !== null) {
            $pets_allowed = (int) $pets_allowed;
        }
        // v6.60.1: Granular pet filters
        // pets_dogs: 1 = dogs allowed
        // pets_cats: 1 = cats allowed
        // pets_none: 1 = no pets allowed
        $pets_dogs = $request->get_param('pets_dogs');
        if ($pets_dogs !== null) {
            $pets_dogs = (int) $pets_dogs;
        }
        $pets_cats = $request->get_param('pets_cats');
        if ($pets_cats !== null) {
            $pets_cats = (int) $pets_cats;
        }
        $pets_none = $request->get_param('pets_none');
        if ($pets_none !== null) {
            $pets_none = (int) $pets_none;
        }
        // v6.60.2: pets_negotiable: 1 = pet policy is negotiable/unknown
        $pets_negotiable = $request->get_param('pets_negotiable');
        if ($pets_negotiable !== null) {
            $pets_negotiable = (int) $pets_negotiable;
        }
        // laundry_features: array of values like ["In Unit", "In Building"]
        $laundry_features = $request->get_param('laundry_features');
        if (!empty($laundry_features) && !is_array($laundry_features)) {
            $laundry_features = array($laundry_features);
        }
        // lease_term: array of values like ["12 months", "6 months", "Monthly"]
        $lease_term = $request->get_param('lease_term');
        if (!empty($lease_term) && !is_array($lease_term)) {
            $lease_term = array($lease_term);
        }
        // available_by: Date string (YYYY-MM-DD) - filter for availability by date
        $available_by = sanitize_text_field($request->get_param('available_by'));
        // available_now: boolean - filter for immediately available rentals
        $available_now = filter_var($request->get_param('MLSPIN_AvailableNow'), FILTER_VALIDATE_BOOLEAN);

        // Sort and bounds
        $sort_param = sanitize_text_field($request->get_param('sort'));
        $sort = self::validate_sort_parameter($sort_param);
        $bounds = $request->get_param('bounds');

        // Polygon filter (v6.30.24 - iOS draw search parity with web)
        // iOS sends: [{"lat": 42.3, "lng": -71.1}, {"lat": 42.4, "lng": -71.0}, ...]
        $polygon = $request->get_param('polygon');
        $polygon_coords = null;
        if (!empty($polygon) && is_array($polygon)) {
            $polygon_coords = array();
            foreach ($polygon as $point) {
                if (isset($point['lat']) && isset($point['lng'])) {
                    $polygon_coords[] = array(floatval($point['lat']), floatval($point['lng']));
                }
            }
            // Need at least 3 points for a valid polygon
            if (count($polygon_coords) < 3) {
                $polygon_coords = null;
            }
        }

        // Determine which table sets we need based on requested statuses
        $archive_status_list = ['Closed', 'Expired', 'Withdrawn', 'Canceled', 'Sold'];
        $active_status_list = ['Active', 'Pending', 'Active Under Contract', 'Under Agreement'];

        $requested_statuses = !empty($status) ? (is_array($status) ? $status : [$status]) : ['Active'];

        // Split statuses into active and archive categories
        $active_statuses_requested = array_intersect($requested_statuses, $active_status_list);
        $archive_statuses_requested = array_intersect($requested_statuses, $archive_status_list);

        $needs_active = !empty($active_statuses_requested);
        $needs_archive = !empty($archive_statuses_requested);

        // v6.68.1: For direct property lookups (MLS number or exact address),
        // search BOTH active and archive tables regardless of status parameter.
        // This ensures the property is found regardless of its current status.
        if ($has_direct_property_lookup) {
            $needs_active = true;
            $needs_archive = true;
        }

        // Common filters array
        $common_filters = array(
            'city' => $city,
            'zip' => $zip,
            'neighborhood' => $neighborhood,
            'address' => $address,
            'mls_number' => $mls_number,
            'street_name' => $street_name,
            'min_price' => $min_price,
            'max_price' => $max_price,
            'beds' => $beds,
            'baths' => $baths,
            'property_type' => $property_type,
            'property_sub_type' => $property_sub_type, // v6.59.0: Home type filter
            'sqft_min' => $sqft_min,
            'sqft_max' => $sqft_max,
            'year_built_min' => $year_built_min,
            'year_built_max' => $year_built_max,
            'polygon_coords' => $polygon_coords, // v6.30.24 - iOS draw search
            'has_direct_property_lookup' => $has_direct_property_lookup, // v6.68.1: Bypass flag for MLS/address
        );

        // If ONLY archive statuses are requested, use archive tables only
        if ($needs_archive && !$needs_active) {
            return self::get_archive_properties($request, $status, $page, $per_page, $sort, $bounds, $common_filters, $cache_key);
        }

        // If BOTH active AND archive statuses are requested, we need to merge results
        if ($needs_archive && $needs_active) {
            return self::get_combined_properties($request, $active_statuses_requested, $archive_statuses_requested, $page, $per_page, $sort, $bounds, $common_filters, $cache_key);
        }

        // Otherwise, continue with active tables only (default behavior)

        $offset = ($page - 1) * $per_page;

        // Determine if we need JOINs for amenity filters
        $needs_features_join = $has_pool || $has_waterfront || $has_view || $has_water_view ||
                               $has_spa || $has_outdoor_space || $is_senior_community;
        // v6.60.0: pets_allowed searches public_remarks since MLSPIN doesn't provide structured pet data
        // v6.60.1: granular pet filters (dogs, cats, none) also need remarks search for fallback
        // v6.60.2: pets_negotiable filter for listings with unknown/negotiable pet policy
        $needs_listings_join = ($pets_allowed !== null) || ($pets_dogs === 1) || ($pets_cats === 1) || ($pets_none === 1) || ($pets_negotiable === 1);
        $needs_details_join = $has_fireplace || $has_garage || $has_cooling || $parking_total_min > 0 ||
                              !empty($laundry_features);  // v6.60.0: Rental laundry filter
        $needs_virtual_tour_join = $has_virtual_tour;
        // Only join location table if we need address filter (for unparsed_address) or neighborhood filter
        $needs_location_join_for_filter = !empty($address) || !empty($neighborhood);
        // Open house join needed only if filtering by open_house_only
        $needs_open_house_join_for_filter = $open_house_only;
        // v6.60.0: Financial table join for rental filters (lease term, availability)
        $needs_financial_join = !empty($lease_term) || !empty($available_by) || $available_now;

        $where = array();
        $params = array();

        // Status filter (default to Active if not specified)
        // Map user-friendly status names to actual database statuses
        // All status conditions are collected and OR'd together
        // v6.68.0: Skip status filter for direct property lookups (MLS number or exact address)
        if (!$has_direct_property_lookup && !empty($status)) {
            $status_conditions = [];
            // Handle array, comma-separated string, or single value
            if (is_array($status)) {
                $statuses_to_check = $status;
            } elseif (strpos($status, ',') !== false) {
                $statuses_to_check = array_map('trim', explode(',', $status));
            } else {
                $statuses_to_check = [$status];
            }

            foreach ($statuses_to_check as $s) {
                $s = sanitize_text_field($s);
                if ($s === 'Under Agreement' || $s === 'Pending') {
                    // Pending/Under Agreement includes both Pending and Active Under Contract
                    $status_conditions[] = "s.standard_status = 'Pending'";
                    $status_conditions[] = "s.standard_status = 'Active Under Contract'";
                } elseif ($s === 'Sold') {
                    $status_conditions[] = "s.standard_status = 'Closed'";
                } else {
                    // All statuses go into status_conditions to be OR'd together
                    $status_conditions[] = $wpdb->prepare("s.standard_status = %s", $s);
                }
            }

            // Combine all status conditions with OR
            if (!empty($status_conditions)) {
                $where[] = "(" . implode(' OR ', $status_conditions) . ")";
            }
        } elseif (!$has_direct_property_lookup) {
            // Only apply default Active status if NOT a direct property lookup
            $where[] = "s.standard_status = 'Active'";
        }
        // If direct property lookup, no status filter applied - return any status

        // City filter (supports array)
        if (!empty($city)) {
            if (is_array($city)) {
                $placeholders = array_fill(0, count($city), '%s');
                $where[] = "s.city IN (" . implode(',', $placeholders) . ")";
                foreach ($city as $c) {
                    $params[] = sanitize_text_field($c);
                }
            } else {
                $where[] = "s.city = %s";
                $params[] = sanitize_text_field($city);
            }
        }

        // ZIP filter (supports array)
        if (!empty($zip)) {
            if (is_array($zip)) {
                $placeholders = array_fill(0, count($zip), '%s');
                $where[] = "s.postal_code IN (" . implode(',', $placeholders) . ")";
                foreach ($zip as $z) {
                    $params[] = sanitize_text_field($z);
                }
            } else {
                $where[] = "s.postal_code = %s";
                $params[] = sanitize_text_field($zip);
            }
        }

        // MLS Number filter (exact match on listing_id)
        if (!empty($mls_number)) {
            $where[] = "s.listing_id = %s";
            $params[] = $mls_number;
        }

        // Address filter (uses location table's unparsed_address for exact match)
        if (!empty($address)) {
            $where[] = "loc.unparsed_address = %s";
            $params[] = $address;
        }

        // Street name filter (partial match on street name)
        if (!empty($street_name)) {
            $where[] = "s.street_name LIKE %s";
            $params[] = '%' . $wpdb->esc_like($street_name) . '%';
        }

        // Neighborhood filter (from location table - searches subdivision_name, mls_area_major, mls_area_minor)
        // v6.49.7 - FIX: This WHERE clause was missing, causing neighborhood filter to have no effect
        if (!empty($neighborhood)) {
            if (is_array($neighborhood)) {
                // Handle array of neighborhoods (multi-select from iOS)
                $placeholders = array_fill(0, count($neighborhood), '%s');
                $placeholder_str = implode(',', $placeholders);
                $where[] = "(loc.subdivision_name IN ({$placeholder_str}) OR loc.mls_area_major IN ({$placeholder_str}) OR loc.mls_area_minor IN ({$placeholder_str}))";
                // Add params 3 times (once for each field)
                foreach ($neighborhood as $n) {
                    $params[] = sanitize_text_field($n);
                }
                foreach ($neighborhood as $n) {
                    $params[] = sanitize_text_field($n);
                }
                foreach ($neighborhood as $n) {
                    $params[] = sanitize_text_field($n);
                }
            } else {
                // Single neighborhood
                $where[] = "(loc.subdivision_name = %s OR loc.mls_area_major = %s OR loc.mls_area_minor = %s)";
                $params[] = sanitize_text_field($neighborhood);
                $params[] = sanitize_text_field($neighborhood);
                $params[] = sanitize_text_field($neighborhood);
            }
        }

        // Price filters
        // v6.68.0: Skip for direct property lookups
        if (!$has_direct_property_lookup) {
            if ($min_price > 0) {
                $where[] = "s.list_price >= %d";
                $params[] = $min_price;
            }

            if ($max_price > 0) {
                $where[] = "s.list_price <= %d";
                $params[] = $max_price;
            }
        }

        // Beds and baths
        // v6.68.0: Skip for direct property lookups
        if (!$has_direct_property_lookup) {
            if ($beds > 0) {
                $where[] = "s.bedrooms_total >= %d";
                $params[] = $beds;
            }

            if (!empty($baths)) {
                $where[] = "s.bathrooms_total >= %f";
                $params[] = floatval($baths);
            }
        }

        // Square footage
        // v6.68.0: Skip for direct property lookups
        if (!$has_direct_property_lookup) {
            if ($sqft_min > 0) {
                $where[] = "s.building_area_total >= %d";
                $params[] = $sqft_min;
            }

            if ($sqft_max > 0) {
                $where[] = "s.building_area_total <= %d";
                $params[] = $sqft_max;
            }
        }

        // Year built
        // v6.68.0: Skip for direct property lookups
        if (!$has_direct_property_lookup) {
            if ($year_built_min > 0) {
                $where[] = "s.year_built >= %d";
                $params[] = $year_built_min;
            }

            if ($year_built_max > 0) {
                $where[] = "s.year_built <= %d";
                $params[] = $year_built_max;
            }
        }

        // Lot size (convert sqft to acres for comparison: 1 acre = 43560 sqft)
        // v6.68.0: Skip for direct property lookups
        if (!$has_direct_property_lookup) {
            if ($lot_size_min > 0) {
                $where[] = "s.lot_size_acres >= %f";
                $params[] = $lot_size_min / 43560.0;
            }

            if ($lot_size_max > 0) {
                $where[] = "s.lot_size_acres <= %f";
                $params[] = $lot_size_max / 43560.0;
            }
        }

        // Garage spaces (from summary table)
        if ($garage_spaces_min > 0) {
            $where[] = "s.garage_spaces >= %d";
            $params[] = $garage_spaces_min;
        }

        // Parking total (combined parking_total + covered_spaces from listing_details)
        if ($parking_total_min > 0) {
            $where[] = "(IFNULL(ld.parking_total, 0) + IFNULL(ld.covered_spaces, 0)) >= %d";
            $params[] = $parking_total_min;
        }

        // Days on market
        if ($max_dom > 0) {
            $where[] = "s.days_on_market <= %d";
            $params[] = $max_dom;
        }
        if ($min_dom > 0) {
            $where[] = "s.days_on_market >= %d";
            $params[] = $min_dom;
        }

        // New listings (within X days)
        if ($new_listing_days > 0) {
            $where[] = "s.listing_contract_date >= DATE_SUB(NOW(), INTERVAL %d DAY)";
            $params[] = $new_listing_days;
        }

        // Property type filter
        // v6.68.0: Skip for direct property lookups
        if (!$has_direct_property_lookup && !empty($property_type) && $property_type !== 'all') {
            // Handle array of property types (from listing type filter)
            if (is_array($property_type)) {
                $placeholders = array_fill(0, count($property_type), '%s');
                $where[] = "s.property_type IN (" . implode(',', $placeholders) . ")";
                foreach ($property_type as $pt) {
                    $params[] = sanitize_text_field($pt);
                }
            } else {
                // Handle comma-separated string
                $types = array_map('trim', explode(',', $property_type));
                if (count($types) > 1) {
                    $placeholders = array_fill(0, count($types), '%s');
                    $where[] = "s.property_type IN (" . implode(',', $placeholders) . ")";
                    foreach ($types as $pt) {
                        $params[] = sanitize_text_field($pt);
                    }
                } else {
                    $where[] = "s.property_type = %s";
                    $params[] = sanitize_text_field($property_type);
                }
            }
        }

        // Property sub type filter (v6.59.0 - Home type filter)
        // v6.68.0: Skip for direct property lookups
        if (!$has_direct_property_lookup && !empty($property_sub_type)) {
            if (is_array($property_sub_type)) {
                $placeholders = array_fill(0, count($property_sub_type), '%s');
                $where[] = "s.property_sub_type IN (" . implode(',', $placeholders) . ")";
                foreach ($property_sub_type as $pst) {
                    $params[] = sanitize_text_field($pst);
                }
            } else {
                // Handle comma-separated string
                $sub_types = array_map('trim', explode(',', $property_sub_type));
                if (count($sub_types) > 1) {
                    $placeholders = array_fill(0, count($sub_types), '%s');
                    $where[] = "s.property_sub_type IN (" . implode(',', $placeholders) . ")";
                    foreach ($sub_types as $pst) {
                        $params[] = sanitize_text_field($pst);
                    }
                } else {
                    $where[] = "s.property_sub_type = %s";
                    $params[] = sanitize_text_field($property_sub_type);
                }
            }
        }

        // Bounds filter for map
        // v6.68.0: Skip for direct property lookups
        if (!$has_direct_property_lookup && !empty($bounds)) {
            $bounds_arr = explode(',', $bounds);
            if (count($bounds_arr) === 4) {
                $where[] = "s.latitude BETWEEN %f AND %f";
                $where[] = "s.longitude BETWEEN %f AND %f";
                $params[] = floatval($bounds_arr[0]);
                $params[] = floatval($bounds_arr[2]);
                $params[] = floatval($bounds_arr[1]);
                $params[] = floatval($bounds_arr[3]);
            }
        }

        // Polygon filter for draw search (v6.30.24 - iOS parity with web)
        // Uses MLD_Spatial_Filter_Service for point-in-polygon SQL condition
        // Pass 's' as table alias since summary table is aliased as 's' in the query
        if (!empty($polygon_coords)) {
            $spatial_service = MLD_Spatial_Filter_Service::get_instance();
            $polygon_condition = $spatial_service->build_summary_polygon_condition($polygon_coords, 's');
            if ($polygon_condition) {
                $where[] = $polygon_condition;
            }
        }

        // Open house only - filter to listings with upcoming open houses
        if ($open_house_only) {
            $open_house_table = $wpdb->prefix . 'bme_open_houses';
            $where[] = "s.listing_id IN (SELECT listing_id FROM {$open_house_table} WHERE expires_at > NOW())";
        }

        // v6.64.0: Exclusive listings only - listing_id < 1,000,000 = exclusive
        if ($exclusive_only) {
            $where[] = "s.listing_id < 1000000";
        }

        // Amenity filters from listing_features table (boolean YN fields)
        if ($has_pool) {
            $where[] = "lfeat.pool_private_yn = 1";
        }
        if ($has_waterfront) {
            $where[] = "lfeat.waterfront_yn = 1";
        }
        if ($has_view) {
            $where[] = "lfeat.view_yn = 1";
        }
        if ($has_water_view) {
            $where[] = "lfeat.mlspin_waterview_flag = 1";
        }
        if ($has_spa) {
            $where[] = "lfeat.spa_yn = 1";
        }
        if ($has_outdoor_space) {
            // Use patio_and_porch_features instead of mlspin_outdoor_space_available (which has no data)
            $where[] = "(lfeat.patio_and_porch_features IS NOT NULL AND lfeat.patio_and_porch_features != '' AND lfeat.patio_and_porch_features != '[]')";
        }
        if ($is_senior_community) {
            $where[] = "lfeat.senior_community_yn = 1";
        }

        // Amenity filters from listing_details table
        if ($has_fireplace) {
            $where[] = "ld.fireplace_yn = 1";
        }
        if ($has_garage) {
            $where[] = "ld.garage_yn = 1";
        }
        if ($has_cooling) {
            $where[] = "ld.cooling_yn = 1";
        }

        // Virtual tour filter
        if ($has_virtual_tour) {
            $where[] = "(vt.virtual_tour_link_1 IS NOT NULL AND vt.virtual_tour_link_1 != '')";
        }

        // Price reduced filter - properties where current price is less than original
        if ($price_reduced) {
            $where[] = "(s.original_list_price IS NOT NULL AND s.original_list_price > 0 AND s.list_price < s.original_list_price)";
        }

        // Summary table amenity filters (v6.30.22 - parity with web)
        if ($has_basement) {
            $where[] = "s.has_basement = 1";
        }
        if ($pet_friendly) {
            $where[] = "s.pet_friendly = 1";
        }

        // v6.60.0: Rental-specific filters (Phase 1)
        // Pets allowed filter - uses BOTH structured data AND remarks search for maximum coverage
        // Structured data: s.pet_friendly (populated from RESO PetsAllowed array during extraction)
        // Remarks fallback: Searches public_remarks for pet mentions (catches legacy data)
        // pets_allowed param: 1 = user wants pet-friendly, 0 = user wants no pets (legacy)
        if ($pets_allowed !== null) {
            if ($pets_allowed === 1) {
                // User wants pet-friendly listings
                // Check structured pet_friendly field OR positive mentions in remarks
                $where[] = "(
                    s.pet_friendly = 1
                    OR (
                        (l.public_remarks REGEXP 'pet[- ]?friendly|pets? (allowed|ok|welcome)|cats? (ok|allowed|welcome)|dogs? (ok|allowed|welcome)')
                        AND l.public_remarks NOT REGEXP 'no pets|pets not allowed|no dogs|no cats'
                    )
                )";
            } else {
                // User wants listings that don't allow pets
                // Check structured pets_no_pets field OR explicit "no pets" in remarks
                $where[] = "(
                    s.pets_no_pets = 1
                    OR l.public_remarks REGEXP 'no pets|pets not allowed|no dogs|no cats'
                )";
            }
        }

        // v6.60.1: Granular pet filters - dogs, cats, no pets
        // These use the new structured columns from RESO PetsAllowed parsing
        if ($pets_dogs === 1) {
            // User wants dogs allowed
            $where[] = "(
                s.pets_dogs_allowed = 1
                OR (
                    (l.public_remarks REGEXP 'dogs? (ok|allowed|welcome)|pet[- ]?friendly')
                    AND l.public_remarks NOT REGEXP 'no dogs|no pets'
                )
            )";
        }
        if ($pets_cats === 1) {
            // User wants cats allowed
            $where[] = "(
                s.pets_cats_allowed = 1
                OR (
                    (l.public_remarks REGEXP 'cats? (ok|allowed|welcome)|pet[- ]?friendly')
                    AND l.public_remarks NOT REGEXP 'no cats|no pets'
                )
            )";
        }
        if ($pets_none === 1) {
            // User wants no pets allowed
            $where[] = "(
                s.pets_no_pets = 1
                OR l.public_remarks REGEXP 'no pets|pets not allowed'
            )";
        }
        // v6.60.2: pets_negotiable filter - listings where pet policy is negotiable or conditional
        if ($pets_negotiable === 1) {
            // User wants listings where pets are explicitly negotiable, conditional, or case-by-case
            // Check structured pets_negotiable field from RESO PetsAllowed parsing
            // OR remarks mentioning negotiable/call for pet info
            // Note: We don't include NULL fields because that would match all unprocessed listings
            $where[] = "(
                s.pets_negotiable = 1
                OR l.public_remarks REGEXP 'pets?[- ]?(negotiable|conditional|case by case)|call (for|about|regarding) pets?'
            )";
        }

        // Laundry features filter - from listing_details table
        // Data is stored as JSON arrays like '["In Unit"]', '["In Building"]', '[]' for none
        if (!empty($laundry_features)) {
            $laundry_conditions = array();
            foreach ($laundry_features as $laundry) {
                $laundry_clean = sanitize_text_field($laundry);
                if (strtolower($laundry_clean) === 'none') {
                    // "None" means empty JSON array or NULL
                    $laundry_conditions[] = "(ld.laundry_features = '[]' OR ld.laundry_features IS NULL OR ld.laundry_features = '')";
                } else {
                    // Search for the value within the JSON array (with quotes)
                    $laundry_conditions[] = "ld.laundry_features LIKE %s";
                    $params[] = '%"' . $wpdb->esc_like($laundry_clean) . '"%';
                }
            }
            $where[] = "(" . implode(" OR ", $laundry_conditions) . ")";
        }

        // Lease term filter - from listing_financial table
        // Data stored as 'Term of Rental(12)', 'Tenant at Will', etc.
        // Map user-friendly values to database search patterns
        // Note: Use %% to escape % in prepared statements
        if (!empty($lease_term)) {
            $lease_conditions = array();
            foreach ($lease_term as $term) {
                $term_clean = sanitize_text_field($term);
                // Map user-friendly terms to database patterns
                switch (strtolower($term_clean)) {
                    case '12 months':
                        $lease_conditions[] = "(lfin.lease_term LIKE '%%Rental(12)%%' OR lfin.lease_term LIKE '%%Rental(12+)%%')";
                        break;
                    case '6 months':
                        $lease_conditions[] = "(lfin.lease_term LIKE '%%Rental(6)%%' OR lfin.lease_term LIKE '%%Rental(6+)%%' OR lfin.lease_term LIKE '%%Rental(6-12)%%')";
                        break;
                    case 'monthly':
                        $lease_conditions[] = "(lfin.lease_term LIKE '%%Tenant at Will%%' OR lfin.lease_term LIKE '%%Monthly%%' OR lfin.lease_term LIKE '%%Taw%%')";
                        break;
                    case 'flexible':
                        $lease_conditions[] = "(lfin.lease_term LIKE '%%Flex%%' OR lfin.lease_term LIKE '%%Short Term%%')";
                        break;
                    default:
                        // Generic search for other terms
                        $lease_conditions[] = "lfin.lease_term LIKE %s";
                        $params[] = '%' . $wpdb->esc_like($term_clean) . '%';
                }
            }
            if (!empty($lease_conditions)) {
                $where[] = "(" . implode(" OR ", $lease_conditions) . ")";
            }
        }

        // Available by date filter - from listing_financial table
        // Filter for rentals available by a specific date
        if (!empty($available_by)) {
            $where[] = "lfin.availability_date <= %s";
            $params[] = $available_by;
        }

        // Available now filter - from listing_financial table
        // Filter for rentals available immediately
        if ($available_now) {
            $where[] = "lfin.mlspin_availablenow = 1";
        }

        $where_sql = implode(' AND ', $where);

        // Determine base sort order (with table alias)
        $base_order_by = 's.listing_contract_date DESC';
        switch ($sort) {
            case 'price_asc':
                $base_order_by = 's.list_price ASC';
                break;
            case 'price_desc':
                $base_order_by = 's.list_price DESC';
                break;
            case 'list_date_asc':
                $base_order_by = 's.listing_contract_date ASC';
                break;
            case 'beds_desc':
                $base_order_by = 's.bedrooms_total DESC';
                break;
            case 'sqft_desc':
                $base_order_by = 's.building_area_total DESC';
                break;
        }

        // v6.65.0: Prioritize exclusive listings (listing_id < 1,000,000) after agent-shared
        // v6.35.12: Prioritize agent-shared properties at top of ALL results
        // Order: 1) Shared by agent (0), 2) Exclusive (1), 3) Regular MLS (2)
        $exclusive_priority = "CASE WHEN s.listing_id < 1000000 THEN 0 ELSE 1 END";

        if (!empty($shared_listing_keys)) {
            $key_placeholders = implode(',', array_fill(0, count($shared_listing_keys), '%s'));
            $shared_expression = $wpdb->prepare(
                "CASE WHEN s.listing_key IN ({$key_placeholders}) THEN 0 ELSE 1 END",
                $shared_listing_keys
            );
            // Shared first (0), then exclusive (1), then regular MLS (2)
            $order_by = "{$shared_expression}, {$exclusive_priority}, {$base_order_by}";
        } else {
            // Just exclusive first (0), then regular MLS (1)
            $order_by = "{$exclusive_priority}, {$base_order_by}";
        }

        $summary_table = $wpdb->prefix . 'bme_listing_summary';
        $details_table = $wpdb->prefix . 'bme_listing_details';
        $features_table = $wpdb->prefix . 'bme_listing_features';
        $virtual_tours_table = $wpdb->prefix . 'bme_virtual_tours';
        $location_table = $wpdb->prefix . 'bme_listing_location';
        $open_houses_table = $wpdb->prefix . 'bme_open_houses';
        $financial_table = $wpdb->prefix . 'bme_listing_financial';  // v6.60.0: For lease term filter
        $listings_table = $wpdb->prefix . 'bme_listings';  // v6.60.0: For pets filter (public_remarks)

        // Build MINIMAL JOINs for COUNT query (only tables needed for WHERE clause filtering)
        $count_joins = "";
        if ($needs_listings_join) {
            $count_joins .= " LEFT JOIN {$listings_table} AS l ON s.listing_id = l.listing_id";
        }
        if ($needs_details_join) {
            $count_joins .= " LEFT JOIN {$details_table} AS ld ON s.listing_id = ld.listing_id";
        }
        if ($needs_features_join) {
            $count_joins .= " LEFT JOIN {$features_table} AS lfeat ON s.listing_id = lfeat.listing_id";
        }
        if ($needs_virtual_tour_join) {
            $count_joins .= " LEFT JOIN {$virtual_tours_table} AS vt ON s.listing_id = vt.listing_id";
        }
        if ($needs_location_join_for_filter) {
            $count_joins .= " LEFT JOIN {$location_table} AS loc ON s.listing_id = loc.listing_id";
        }
        if ($needs_financial_join) {
            $count_joins .= " LEFT JOIN {$financial_table} AS lfin ON s.listing_id = lfin.listing_id";
        }
        // Note: open_house_only uses subquery in WHERE, not JOIN

        // Build FULL JOINs for data query (includes display-only tables)
        $data_joins = $count_joins;
        // Add location JOIN for neighborhood display (if not already added for filtering)
        if (!$needs_location_join_for_filter) {
            $data_joins .= " LEFT JOIN {$location_table} AS loc ON s.listing_id = loc.listing_id";
        }
        // Add open house JOIN for display
        $data_joins .= " LEFT JOIN (
            SELECT listing_id, MIN(expires_at) as next_open_house
            FROM {$open_houses_table}
            WHERE expires_at > NOW()
            GROUP BY listing_id
        ) AS oh ON s.listing_id = oh.listing_id";

        // Get total count using minimal JOINs
        $count_sql = "SELECT COUNT(DISTINCT s.listing_id) FROM {$summary_table} AS s{$count_joins} WHERE {$where_sql}";
        if (!empty($params)) {
            $total = $wpdb->get_var($wpdb->prepare($count_sql, $params));
        } else {
            $total = $wpdb->get_var($count_sql);
        }

        // Get listings using full JOINs (for display data)
        $sql = "SELECT DISTINCT
            s.listing_id, s.listing_key,
            CASE WHEN s.unit_number IS NOT NULL AND s.unit_number != ''
                THEN CONCAT(s.street_number, ' ', s.street_name, ' Unit ', s.unit_number)
                ELSE CONCAT(s.street_number, ' ', s.street_name)
            END as street_address,
            CONCAT(s.street_number, ' ', s.street_name) as grouping_address,
            s.city, s.state_or_province, s.postal_code,
            s.list_price, s.original_list_price,
            s.bedrooms_total as bedrooms, s.bathrooms_total as bathrooms,
            s.bathrooms_full, s.bathrooms_half,
            s.building_area_total as square_feet, s.property_type,
            s.latitude, s.longitude, s.listing_contract_date as list_date,
            s.days_on_market, s.main_photo_url as photo_url,
            s.property_sub_type as property_subtype, s.year_built,
            s.lot_size_acres, s.garage_spaces, s.standard_status,
            s.exclusive_tag,
            loc.subdivision_name as neighborhood,
            oh.next_open_house
            FROM {$summary_table} AS s{$data_joins}
            WHERE {$where_sql}
            ORDER BY {$order_by}
            LIMIT %d OFFSET %d";

        // When school filters are active, over-fetch to ensure we have enough results after filtering
        $fetch_per_page = $per_page;
        $fetch_offset = $offset;
        if ($has_school_filters) {
            // 1-mile radius school filters have ~17% pass rate
            // Need minimum of 150 properties to reliably get results
            $fetch_per_page = max(150, $per_page * 10);
            // Adjust offset proportionally for pagination
            $fetch_offset = (int) ($offset * ($fetch_per_page / $per_page));
        }

        // Merge filter params with pagination params
        $query_params = array_merge($params, array($fetch_per_page, $fetch_offset));
        $listings = $wpdb->get_results($wpdb->prepare($sql, $query_params));

        // Apply school-based filtering if criteria are set
        $pre_filter_count = count($listings);
        if ($has_school_filters && !empty($listings)) {
            $schools_integration = MLD_BMN_Schools_Integration::get_instance();
            $listings = $schools_integration->filter_properties_by_school_criteria($listings, $school_criteria);

            // Calculate filter rate BEFORE trimming to page size
            $post_filter_count = count($listings);

            // Trim to requested page size
            $listings = array_slice($listings, 0, $per_page);

            // Estimate total count based on filter pass rate from this batch
            if ($pre_filter_count > 0) {
                $filter_rate = $post_filter_count / $pre_filter_count;
                $total = max($post_filter_count, (int) round($total * $filter_rate));
            } else {
                $total = 0;
            }
        }

        // Batch fetch photos for all listings (first 5 per listing for carousel)
        $media_table = $wpdb->prefix . 'bme_media';
        $photos_map = array();
        if (!empty($listings)) {
            $listing_ids = array_map(function($l) { return $l->listing_id; }, $listings);
            $placeholders = implode(',', array_fill(0, count($listing_ids), '%s'));

            // Use a subquery with ROW_NUMBER to get first 5 photos per listing
            $photos_sql = "SELECT listing_id, media_url
                FROM (
                    SELECT listing_id, media_url, order_index,
                        @row_num := IF(@prev_listing = listing_id, @row_num + 1, 1) AS rn,
                        @prev_listing := listing_id
                    FROM {$media_table}, (SELECT @row_num := 0, @prev_listing := '') vars
                    WHERE listing_id IN ({$placeholders}) AND media_category = 'Photo'
                    ORDER BY listing_id, order_index ASC
                ) ranked
                WHERE rn <= 5";

            $photos_results = $wpdb->get_results($wpdb->prepare($photos_sql, $listing_ids));

            // Build photos map: listing_id -> array of photo URLs
            foreach ($photos_results as $photo) {
                if (!isset($photos_map[$photo->listing_id])) {
                    $photos_map[$photo->listing_id] = array();
                }
                $photos_map[$photo->listing_id][] = $photo->media_url;
            }
        }

        // Get schools integration for district grade lookups (v6.30.0)
        $schools_integration = class_exists('MLD_BMN_Schools_Integration')
            ? MLD_BMN_Schools_Integration::get_instance()
            : null;

        // v6.35.12: shared_agent_map is now fetched early for SQL sort prioritization
        // (see top of method around line 1320)

        $formatted = array_map(function($listing) use ($photos_map, $schools_integration, $shared_agent_map) {
            // Convert lot_size_acres to square feet (1 acre = 43560 sqft)
            $lot_size_sqft = $listing->lot_size_acres ? round(floatval($listing->lot_size_acres) * 43560) : null;

            // Calculate if price was reduced
            $original_price = isset($listing->original_list_price) ? (int) $listing->original_list_price : null;
            $current_price = (int) $listing->list_price;
            $is_price_reduced = $original_price && $original_price > $current_price;

            // Get photos array for this listing (already limited to 5)
            $photos = isset($photos_map[$listing->listing_id]) ? $photos_map[$listing->listing_id] : array();

            // Get district grade for property city (v6.30.0)
            $district_grade = null;
            $district_percentile = null;
            if ($schools_integration && !empty($listing->city)) {
                $district_info = $schools_integration->get_district_grade_for_city($listing->city);
                if ($district_info) {
                    $district_grade = $district_info['grade'];
                    $district_percentile = $district_info['percentile'];
                }
            }

            return array(
                'id' => $listing->listing_key ?: $listing->listing_id,
                'mls_number' => $listing->listing_id,
                'address' => trim($listing->street_address),
                'grouping_address' => trim($listing->grouping_address),  // For map clustering (no unit number)
                'city' => $listing->city,
                'state' => $listing->state_or_province,
                'zip' => $listing->postal_code,
                'neighborhood' => $listing->neighborhood ?: null,
                'price' => $current_price,
                'original_price' => $is_price_reduced ? $original_price : null,
                'beds' => (int) $listing->bedrooms,
                'baths' => (float) $listing->bathrooms,
                'baths_full' => isset($listing->bathrooms_full) ? (int) $listing->bathrooms_full : null,
                'baths_half' => isset($listing->bathrooms_half) ? (int) $listing->bathrooms_half : null,
                'sqft' => (int) $listing->square_feet,
                'property_type' => $listing->property_type,
                'property_subtype' => $listing->property_subtype,
                'status' => $listing->standard_status,
                'latitude' => (float) $listing->latitude,
                'longitude' => (float) $listing->longitude,
                'list_date' => $listing->list_date,
                'dom' => (int) $listing->days_on_market,
                'photo_url' => $listing->photo_url,
                'photos' => $photos,
                'year_built' => $listing->year_built ? (int) $listing->year_built : null,
                'lot_size' => $lot_size_sqft,
                'garage_spaces' => isset($listing->garage_spaces) ? (int) $listing->garage_spaces : null,
                'has_open_house' => !empty($listing->next_open_house),
                'next_open_house' => $listing->next_open_house ?: null,
                'district_grade' => $district_grade,
                'district_percentile' => $district_percentile,
                'is_shared_by_agent' => isset($shared_agent_map[$listing->listing_key]),
                'shared_by_agent_name' => isset($shared_agent_map[$listing->listing_key]) ? $shared_agent_map[$listing->listing_key]['first_name'] : null,
                'shared_by_agent_photo' => isset($shared_agent_map[$listing->listing_key]) ? $shared_agent_map[$listing->listing_key]['photo_url'] : null,
                // v6.64.0: Exclusive listings flag (listing_id < 1,000,000 = exclusive, MLS IDs are 60M+)
                'is_exclusive' => intval($listing->listing_id) < 1000000,
                // v6.65.0: Custom badge text for exclusive listings
                'exclusive_tag' => (intval($listing->listing_id) < 1000000)
                    ? (!empty($listing->exclusive_tag) ? $listing->exclusive_tag : 'Exclusive')
                    : null,
            );
        }, $listings);

        // v6.35.12: Shared property prioritization now handled at SQL level via ORDER BY
        // (see CASE expression in sort logic around line 1795)

        $response_data = array(
            'success' => true,
            'data' => array(
                'listings' => $formatted,
                'total' => (int) $total,
                'page' => $page,
                'per_page' => $per_page,
                'total_pages' => ceil($total / $per_page)
            )
        );

        // Cache the response for 2 minutes
        set_transient($cache_key, $response_data, 120);

        return new WP_REST_Response($response_data, 200);
    }

    /**
     * Handle get single property
     * Returns comprehensive property details for the detail view
     */
    public static function handle_get_property($request) {
        // v6.54.4: Check rate limit before processing
        $rate_limited = self::check_public_rate_limit('property_detail');
        if ($rate_limited !== false) {
            return $rate_limited;
        }

        // v6.60.6: Optionally process JWT for public endpoint to enable agent-only info
        // This endpoint is public (permission_callback => '__return_true') so JWT isn't processed automatically
        $auth_header = $request->get_header('Authorization');
        if (!empty($auth_header) && strpos($auth_header, 'Bearer ') === 0) {
            $token = substr($auth_header, 7);
            $payload = self::verify_jwt($token);
            if (!is_wp_error($payload) && isset($payload['sub'])) {
                wp_set_current_user($payload['sub']);
            }
        }

        global $wpdb;

        $listing_id = sanitize_text_field($request->get_param('id'));

        // v6.60.3: Detect if user is an agent for agent-only info
        $user_id = get_current_user_id();
        $is_agent = false;
        if ($user_id > 0) {
            if (class_exists('MLD_User_Type_Manager')) {
                $is_agent = MLD_User_Type_Manager::is_agent($user_id) || MLD_User_Type_Manager::is_admin($user_id);
            }
            // Fallback to WordPress roles
            if (!$is_agent) {
                $user = get_userdata($user_id);
                if ($user) {
                    $roles = (array) $user->roles;
                    $is_agent = in_array('administrator', $roles) || in_array('agent', $roles) || in_array('editor', $roles);
                }
            }
        }

        // PERFORMANCE FIX v6.54.3: Cache property details for 1 hour
        // Reduces 7+ queries to 0 on cache hit (7x faster for repeated views)
        // v6.60.3: Include role in cache key to prevent agent data leaking to public users
        $cache_key = 'mld_property_detail_' . md5($listing_id . '_' . ($is_agent ? 'agent' : 'public'));
        $cached_response = get_transient($cache_key);
        if ($cached_response !== false) {
            return new WP_REST_Response($cached_response, 200);
        }

        $summary_table = $wpdb->prefix . 'bme_listing_summary';
        $details_table = $wpdb->prefix . 'bme_listing_details';
        $location_table = $wpdb->prefix . 'bme_listing_location';
        $features_table = $wpdb->prefix . 'bme_listing_features';
        $media_table = $wpdb->prefix . 'bme_media';
        $virtual_tours_table = $wpdb->prefix . 'bme_virtual_tours';
        $open_houses_table = $wpdb->prefix . 'bme_open_houses';
        $agents_table = $wpdb->prefix . 'bme_agents';

        // Get main listing data - search by listing_key
        $listing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$summary_table} WHERE listing_key = %s",
            $listing_id
        ));

        // Flag to track if we're using archive tables
        $is_archive_listing = false;

        // If not found in summary, check archive tables
        // v6.68.9: Check summary_archive FIRST (has all denormalized fields),
        // then fall back to listings_archive (requires joins for location/details)
        if (!$listing) {
            $summary_archive_table = $wpdb->prefix . 'bme_listing_summary_archive';
            $listing = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$summary_archive_table} WHERE listing_key = %s",
                $listing_id
            ));
            if ($listing) {
                $is_archive_listing = true;
                // Use archive table names for subsequent queries
                $details_table = $wpdb->prefix . 'bme_listing_details_archive';
                $location_table = $wpdb->prefix . 'bme_listing_location_archive';
                $features_table = $wpdb->prefix . 'bme_listing_features_archive';
            }
        }

        // If still not found, check main listings_archive table
        if (!$listing) {
            $archive_table = $wpdb->prefix . 'bme_listings_archive';
            $listing = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$archive_table} WHERE listing_key = %s",
                $listing_id
            ));
            if ($listing) {
                $is_archive_listing = true;
                // Use archive table names for subsequent queries
                $details_table = $wpdb->prefix . 'bme_listing_details_archive';
                $location_table = $wpdb->prefix . 'bme_listing_location_archive';
                $features_table = $wpdb->prefix . 'bme_listing_features_archive';
            }
        }

        if (!$listing) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'not_found',
                'message' => 'Property not found'
            ), 404);
        }

        // Get additional details using listing_id (MLS number)
        $details = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$details_table} WHERE listing_id = %s",
            $listing->listing_id
        ));

        // Get location data
        $location = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$location_table} WHERE listing_id = %s",
            $listing->listing_id
        ));

        // Get features data
        $features = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$features_table} WHERE listing_id = %s",
            $listing->listing_id
        ));

        // v6.60.9: Get financial/rental data for rental properties
        $financial_table = $wpdb->prefix . 'bme_listing_financial';
        $financial = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$financial_table} WHERE listing_id = %s",
            $listing->listing_id
        ));

        // Get photos from bme_media table using listing_id
        $photos = $wpdb->get_col($wpdb->prepare(
            "SELECT media_url FROM {$media_table} WHERE listing_id = %s AND media_category = 'Photo' ORDER BY order_index ASC",
            $listing->listing_id
        ));

        if (empty($photos) && !empty($listing->main_photo_url)) {
            $photos = array($listing->main_photo_url);
        }

        // Get virtual tour URLs (up to 3)
        $virtual_tour_row = $wpdb->get_row($wpdb->prepare(
            "SELECT virtual_tour_link_1, virtual_tour_link_2, virtual_tour_link_3 FROM {$virtual_tours_table} WHERE listing_id = %s LIMIT 1",
            $listing->listing_id
        ));
        $virtual_tour = null;
        $virtual_tours = array();
        if ($virtual_tour_row) {
            if (!empty($virtual_tour_row->virtual_tour_link_1)) {
                $virtual_tour = $virtual_tour_row->virtual_tour_link_1;
                $virtual_tours[] = $virtual_tour_row->virtual_tour_link_1;
            }
            if (!empty($virtual_tour_row->virtual_tour_link_2)) {
                $virtual_tours[] = $virtual_tour_row->virtual_tour_link_2;
            }
            if (!empty($virtual_tour_row->virtual_tour_link_3)) {
                $virtual_tours[] = $virtual_tour_row->virtual_tour_link_3;
            }
        }

        // Get photo count
        $photo_count = count($photos);

        // Get upcoming open houses (data is stored as JSON in open_house_data column)
        $open_house_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT open_house_data, expires_at
             FROM {$open_houses_table}
             WHERE listing_id = %s AND expires_at > NOW()
             ORDER BY expires_at ASC
             LIMIT 5",
            $listing->listing_id
        ));

        // Parse the JSON open house data
        $open_houses = array();
        foreach ($open_house_rows as $row) {
            $data = json_decode($row->open_house_data, true);
            if ($data) {
                $open_houses[] = (object) array(
                    'open_house_start_time' => $data['OpenHouseStartTime'] ?? $data['open_house_start_time'] ?? null,
                    'open_house_end_time' => $data['OpenHouseEndTime'] ?? $data['open_house_end_time'] ?? null,
                    'open_house_remarks' => $data['OpenHouseRemarks'] ?? $data['open_house_remarks'] ?? null,
                );
            }
        }

        // Get listing agent info from bme_listings table (summary table doesn't have agent columns)
        $listings_table = $is_archive_listing ? $wpdb->prefix . 'bme_listings_archive' : $wpdb->prefix . 'bme_listings';
        $offices_table = $wpdb->prefix . 'bme_offices';

        $agent_data = $wpdb->get_row($wpdb->prepare(
            "SELECT
                l.list_agent_mls_id,
                l.list_office_mls_id,
                l.public_remarks,
                a.agent_full_name AS agent_name,
                a.agent_email,
                a.agent_phone AS agent_direct_phone,
                o.office_name,
                o.office_phone
             FROM {$listings_table} AS l
             LEFT JOIN {$agents_table} AS a ON l.list_agent_mls_id = a.agent_mls_id
             LEFT JOIN {$offices_table} AS o ON l.list_office_mls_id = o.office_mls_id
             WHERE l.listing_id = %s
             LIMIT 1",
            $listing->listing_id
        ));

        $agent = $agent_data;

        // Build address from components (include unit number if present)
        $address = trim($listing->street_number . ' ' . $listing->street_name);
        if (!empty($listing->unit_number)) {
            $address .= ' Unit ' . $listing->unit_number;
        }

        // Convert lot_size_acres to square feet (1 acre = 43560 sqft)
        $lot_size_sqft = $listing->lot_size_acres ? round(floatval($listing->lot_size_acres) * 43560) : null;
        $lot_size_acres = $listing->lot_size_acres ? (float) $listing->lot_size_acres : null;

        // Calculate price per square foot
        $sqft = (int) $listing->building_area_total;
        $price = (int) $listing->list_price;
        $price_per_sqft = ($sqft > 0 && $price > 0) ? round($price / $sqft) : null;

        // Calculate price reduction
        $original_price = isset($listing->original_list_price) ? (int) $listing->original_list_price : null;
        $is_price_reduced = $original_price && $original_price > $price;

        // Build result array with all data
        $result = array(
            // Core identification
            'id' => $listing->listing_key,
            'mls_number' => $listing->listing_id,
            // v6.64.0: Exclusive listings flag (listing_id < 1,000,000 = exclusive, MLS IDs are 60M+)
            'is_exclusive' => intval($listing->listing_id) < 1000000,
            // v6.65.0: Custom badge text for exclusive listings
            'exclusive_tag' => (intval($listing->listing_id) < 1000000)
                ? (!empty($listing->exclusive_tag) ? $listing->exclusive_tag : 'Exclusive')
                : null,

            // Address & Location
            'address' => $address,
            'city' => $listing->city,
            'state' => $listing->state_or_province,
            'zip' => $listing->postal_code,
            'neighborhood' => $location->subdivision_name ?? null,
            'latitude' => (float) $listing->latitude,
            'longitude' => (float) $listing->longitude,

            // Price & Status
            'price' => $price,
            'original_price' => $is_price_reduced ? $original_price : null,
            'close_price' => isset($listing->close_price) && $listing->close_price ? (int) $listing->close_price : null,
            'close_date' => isset($listing->close_date) && $listing->close_date ? $listing->close_date : null,
            'price_per_sqft' => $price_per_sqft,
            'status' => $listing->standard_status,

            // Sold Statistics (for closed listings)
            'list_to_sale_ratio' => (isset($listing->close_price) && $listing->close_price && $price > 0)
                ? round(($listing->close_price / $price) * 100, 1)
                : null,
            'sold_above_below' => (isset($listing->close_price) && $listing->close_price && $price > 0)
                ? (($listing->close_price > $price) ? 'above' : (($listing->close_price < $price) ? 'below' : 'at'))
                : null,
            'price_difference' => (isset($listing->close_price) && $listing->close_price && $price > 0)
                ? (int) ($listing->close_price - $price)
                : null,

            // Property Type
            'property_type' => $listing->property_type,
            'property_subtype' => $listing->property_sub_type,

            // Core Stats
            'beds' => (int) $listing->bedrooms_total,
            'baths' => (float) $listing->bathrooms_total,
            'baths_full' => isset($listing->bathrooms_full) ? (int) $listing->bathrooms_full : null,
            'baths_half' => isset($listing->bathrooms_half) ? (int) $listing->bathrooms_half : null,
            'sqft' => $sqft,
            'year_built' => $listing->year_built ? (int) $listing->year_built : null,

            // Lot
            'lot_size' => $lot_size_sqft,
            'lot_size_acres' => $lot_size_acres,
            'lot_size_dimensions' => $details->lot_size_dimensions ?? null,
            'lot_features' => self::format_array_field($details->lot_features ?? null),

            // Parking & Garage
            'garage_spaces' => $listing->garage_spaces ? (int) $listing->garage_spaces : null,
            'parking_total' => isset($details->parking_total) ? (int) $details->parking_total : null,
            'parking_features' => self::format_array_field($details->parking_features ?? null),
            'covered_spaces' => isset($details->covered_spaces) ? (int) $details->covered_spaces : null,
            'open_parking_spaces' => isset($details->open_parking_spaces) ? (int) $details->open_parking_spaces : null,

            // Timing
            'list_date' => $listing->listing_contract_date,
            'dom' => (int) $listing->days_on_market,
            // v6.68.0: Data freshness indicator
            'modification_timestamp' => isset($listing->modification_timestamp)
                ? self::format_datetime_iso8601($listing->modification_timestamp)
                : null,

            // Media
            'photos' => $photos,
            'photo_count' => $photo_count,
            'virtual_tour_url' => $virtual_tour ?: null,
            'virtual_tours' => !empty($virtual_tours) ? $virtual_tours : null,

            // Open Houses
            'has_open_house' => !empty($open_houses),
            'open_houses' => array_map(function($oh) {
                return array(
                    'start_time' => $oh->open_house_start_time,
                    'end_time' => $oh->open_house_end_time,
                    'remarks' => $oh->open_house_remarks ?: null,
                );
            }, $open_houses),
        );

        // Description - from bme_listings table (via agent_data query), not bme_listing_details
        $result['description'] = $agent_data->public_remarks ?? null;

        // Add details if available
        if ($details) {
            // Structure
            $result['stories'] = isset($details->stories) && $details->stories ? (int) $details->stories : null;
            $result['construction_materials'] = self::format_array_field($details->construction_materials ?? null);
            $result['architectural_style'] = self::format_array_field($details->architectural_style ?? null);
            $result['roof'] = self::format_array_field($details->roof ?? null);
            $result['foundation_details'] = self::format_array_field($details->foundation_details ?? null);

            // Interior Features
            $result['heating'] = self::format_array_field($details->heating ?? null);
            $result['cooling'] = self::format_array_field($details->cooling ?? null);
            $result['flooring'] = self::format_array_field($details->flooring ?? null);
            $result['appliances'] = self::format_array_field($details->appliances ?? null);
            $result['fireplace_features'] = self::format_array_field($details->fireplace_features ?? null);
            $result['fireplaces_total'] = isset($details->fireplaces_total) ? (int) $details->fireplaces_total : null;
            $result['basement'] = self::format_array_field($details->basement ?? null);
            $result['laundry_features'] = self::format_array_field($details->laundry_features ?? null);
            $result['interior_features'] = self::format_array_field($details->interior_features ?? null);

            // Exterior Features
            $result['exterior_features'] = self::format_array_field($details->exterior_features ?? null);
            $result['patio_and_porch_features'] = self::format_array_field($details->patio_and_porch_features ?? null);
            $result['pool_features'] = self::format_array_field($details->pool_features ?? null);
            $result['spa_features'] = self::format_array_field($details->spa_features ?? null);
            $result['waterfront_features'] = self::format_array_field($details->waterfront_features ?? null);
            $result['view'] = self::format_array_field($details->view ?? null);
            $result['fencing'] = self::format_array_field($details->fencing ?? null);

            // HOA & Community (data is in wp_bme_listing_financial table, not details)
            $result['hoa_fee'] = isset($financial->association_fee) && $financial->association_fee ? (float) $financial->association_fee : null;
            $result['hoa_fee_frequency'] = $financial->association_fee_frequency ?? null;
            $result['association_amenities'] = self::format_array_field($financial->association_amenities ?? null);
            $result['association_fee_includes'] = self::format_array_field($financial->association_fee_includes ?? null);
            $result['pets_allowed'] = self::format_array_field($details->pets_allowed ?? null);
            $result['senior_community'] = !empty($details->senior_community_yn);

            // Financial (tax data is in wp_bme_listing_financial table)
            $result['tax_annual'] = isset($financial->tax_annual_amount) && $financial->tax_annual_amount ? (float) $financial->tax_annual_amount : null;
            $result['tax_year'] = isset($financial->tax_year) ? (int) $financial->tax_year : null;
            $result['tax_assessed_value'] = isset($financial->tax_assessed_value) ? (float) $financial->tax_assessed_value : null;
            $result['tax_legal_description'] = $details->tax_legal_description ?? null;
            $result['tax_lot'] = $details->tax_lot ?? null;
            $result['tax_block'] = $details->tax_block ?? null;
            $result['tax_map_number'] = $details->tax_map_number ?? null;
            $result['parcel_number'] = $details->parcel_number ?? null;
            $result['additional_parcels_yn'] = !empty($details->additional_parcels_yn);
            $result['additional_parcels_description'] = $details->additional_parcels_description ?? null;
            $result['zoning'] = $details->zoning ?? null;
            $result['zoning_description'] = $details->zoning_description ?? null;

            // Additional Interior Features
            $result['window_features'] = self::format_array_field($details->window_features ?? null);
            $result['door_features'] = self::format_array_field($details->door_features ?? null);
            $result['attic'] = self::format_array_field($details->attic ?? null);
            $result['insulation'] = self::format_array_field($details->insulation ?? null);
            $result['accessibility_features'] = self::format_array_field($details->accessibility_features ?? null);
            $result['security_features'] = self::format_array_field($details->security_features ?? null);
            $result['common_walls'] = self::format_array_field($details->common_walls ?? null);
            // entry_level and entry_location are in wp_bme_listing_location table, NOT details
            $result['entry_level'] = $location->entry_level ?? null;
            $result['entry_location'] = $location->entry_location ?? null;
            $result['levels'] = self::format_array_field($details->levels ?? null);
            $result['rooms_total'] = isset($details->rooms_total) ? (int) $details->rooms_total : null;
            $result['main_level_bedrooms'] = isset($details->main_level_bedrooms) ? (int) $details->main_level_bedrooms : null;
            $result['main_level_bathrooms'] = isset($details->main_level_bathrooms) ? (int) $details->main_level_bathrooms : null;
            $result['other_rooms'] = self::format_array_field($details->other_rooms ?? null);
            $result['master_bedroom_level'] = $details->master_bedroom_level ?? null;

            // Area Breakdown
            $result['above_grade_finished_area'] = isset($details->above_grade_finished_area) ? (int) $details->above_grade_finished_area : null;
            $result['below_grade_finished_area'] = isset($details->below_grade_finished_area) ? (int) $details->below_grade_finished_area : null;
            $result['total_area'] = isset($details->total_area) ? (int) $details->total_area : null;

            // Additional Exterior Features
            $result['water_body_name'] = $details->water_body_name ?? null;
            $result['foundation_area'] = isset($details->foundation_area) ? (int) $details->foundation_area : null;

            // Additional Lot & Land
            $result['lot_size_square_feet'] = isset($details->lot_size_square_feet) ? (int) $details->lot_size_square_feet : null;
            $result['land_lease_yn'] = !empty($details->land_lease_yn);
            $result['land_lease_amount'] = isset($details->land_lease_amount) ? (float) $details->land_lease_amount : null;
            $result['land_lease_expiration_date'] = $details->land_lease_expiration_date ?? null;
            $result['horse_yn'] = !empty($details->horse_yn);
            $result['horse_amenities'] = self::format_array_field($details->horse_amenities ?? null);
            $result['vegetation'] = self::format_array_field($details->vegetation ?? null);
            $result['topography'] = self::format_array_field($details->topography ?? null);
            $result['frontage_type'] = self::format_array_field($details->frontage_type ?? null);
            $result['frontage_length'] = isset($details->frontage_length) ? (int) $details->frontage_length : null;
            $result['road_surface_type'] = self::format_array_field($details->road_surface_type ?? null);
            $result['road_frontage_type'] = self::format_array_field($details->road_frontage_type ?? null);

            // Additional Parking
            $result['attached_garage_yn'] = !empty($details->attached_garage_yn);
            $result['carport_spaces'] = isset($details->carport_spaces) ? (int) $details->carport_spaces : null;
            $result['carport_yn'] = !empty($details->carport_yn);
            $result['driveway_surface'] = self::format_array_field($details->driveway_surface ?? null);

            // Utilities & Systems
            $result['utilities'] = self::format_array_field($details->utilities ?? null);
            $result['water_source'] = self::format_array_field($details->water_source ?? null);
            $result['sewer'] = self::format_array_field($details->sewer ?? null);
            $result['electric'] = self::format_array_field($details->electric ?? null);
            $result['electric_on_property_yn'] = !empty($details->electric_on_property_yn);
            $result['gas'] = self::format_array_field($details->gas ?? null);
            $result['internet_type'] = self::format_array_field($details->internet_type ?? null);
            $result['cable_available_yn'] = !empty($details->cable_available_yn);
            $result['smart_home_features'] = self::format_array_field($details->smart_home_features ?? null);
            $result['energy_features'] = self::format_array_field($details->energy_features ?? null);
            $result['green_building_certification'] = self::format_array_field($details->green_building_certification ?? null);
            $result['green_certification_rating'] = $details->green_certification_rating ?? null;
            $result['green_energy_efficient'] = self::format_array_field($details->green_energy_efficient ?? null);
            $result['green_sustainability'] = self::format_array_field($details->green_sustainability ?? null);

            // Additional Community & HOA
            $result['community_features'] = self::format_array_field($details->community_features ?? null);
            $result['association_yn'] = !empty($details->association_yn);
            $result['association_fee2'] = isset($details->association_fee2) ? (float) $details->association_fee2 : null;
            $result['association_fee2_frequency'] = $details->association_fee2_frequency ?? null;
            $result['association_name'] = $details->association_name ?? null;
            $result['association_phone'] = $details->association_phone ?? null;
            $result['master_association_fee'] = isset($details->master_association_fee) ? (float) $details->master_association_fee : null;
            $result['condo_association_fee'] = isset($details->condo_association_fee) ? (float) $details->condo_association_fee : null;
            $result['pet_restrictions'] = self::format_array_field($details->pet_restrictions ?? null);

            // v6.60.10: Enhanced HOA Information
            // NOTE: association_fee_includes is set earlier from $financial (line ~4078) - do NOT overwrite here
            $result['optional_fee'] = isset($details->mlspin_optional_fee) && $details->mlspin_optional_fee ? (float) $details->mlspin_optional_fee : null;
            $result['optional_fee_includes'] = self::format_array_field($details->mlspin_opt_fee_includes ?? null);
            $result['owner_occupied_units'] = isset($details->mlspin_no_units_owner_occ) && $details->mlspin_no_units_owner_occ ? (int) $details->mlspin_no_units_owner_occ : null;

            // v6.60.10: Land/Lot specific fields
            $result['road_responsibility'] = $details->road_responsibility ?? null;
            $result['number_of_lots'] = isset($details->number_of_lots) && $details->number_of_lots ? (int) $details->number_of_lots : null;

            // Schools
            $result['school_district'] = $details->school_district ?? null;
            $result['elementary_school'] = $details->elementary_school ?? null;
            $result['middle_or_junior_school'] = $details->middle_or_junior_school ?? null;
            $result['high_school'] = $details->high_school ?? null;

            // Additional Details
            $result['year_built_source'] = $details->year_built_source ?? null;
            $result['year_built_details'] = $details->year_built_details ?? null;
            $result['year_built_effective'] = isset($details->year_built_effective) ? (int) $details->year_built_effective : null;
            $result['building_name'] = $details->building_name ?? null;
            $result['building_features'] = self::format_array_field($details->building_features ?? null);
            $result['property_attached_yn'] = !empty($details->property_attached_yn);
            $result['property_condition'] = self::format_array_field($details->property_condition ?? null);
            $result['disclosures'] = self::format_array_field($details->disclosures ?? null);
            $result['exclusions'] = self::format_array_field($details->exclusions ?? null);
            $result['inclusions'] = self::format_array_field($details->inclusions ?? null);
            $result['ownership'] = $details->ownership ?? null;
            $result['occupant_type'] = $details->occupant_type ?? null;
            $result['possession'] = $details->possession ?? null;
            $result['listing_terms'] = self::format_array_field($details->listing_terms ?? null);
            $result['listing_service'] = $details->listing_service ?? null;
            $result['special_listing_conditions'] = self::format_array_field($details->special_listing_conditions ?? null);

            // v6.66.0: Additional property details for enhanced iOS display
            // Home warranty
            $result['home_warranty'] = !empty($details->home_warranty_yn);

            // HVAC zone counts
            $result['heat_zones'] = isset($details->mlspin_heat_zones) && $details->mlspin_heat_zones ? (int) $details->mlspin_heat_zones : null;
            $result['cool_zones'] = isset($details->mlspin_cooling_zones) && $details->mlspin_cooling_zones ? (int) $details->mlspin_cooling_zones : null;

            // Year-round vs seasonal property
            $result['year_round'] = !empty($details->mlspin_year_round);

            // Beds/baths breakdown by floor (for multi-story homes)
            $beds_by_floor = array();
            if (isset($details->mlspin_bedrms_1) && $details->mlspin_bedrms_1) {
                $beds_by_floor['floor_1'] = (int) $details->mlspin_bedrms_1;
            }
            if (isset($details->mlspin_bedrms_2) && $details->mlspin_bedrms_2) {
                $beds_by_floor['floor_2'] = (int) $details->mlspin_bedrms_2;
            }
            if (isset($details->mlspin_bedrms_3) && $details->mlspin_bedrms_3) {
                $beds_by_floor['floor_3'] = (int) $details->mlspin_bedrms_3;
            }
            $result['beds_by_floor'] = !empty($beds_by_floor) ? $beds_by_floor : null;

            $baths_by_floor = array();
            if (isset($details->mlspin_f_bths_1) && $details->mlspin_f_bths_1) {
                $baths_by_floor['floor_1'] = (int) $details->mlspin_f_bths_1;
            }
            if (isset($details->mlspin_f_bths_2) && $details->mlspin_f_bths_2) {
                $baths_by_floor['floor_2'] = (int) $details->mlspin_f_bths_2;
            }
            if (isset($details->mlspin_f_bths_3) && $details->mlspin_f_bths_3) {
                $baths_by_floor['floor_3'] = (int) $details->mlspin_f_bths_3;
            }
            $result['baths_by_floor'] = !empty($baths_by_floor) ? $baths_by_floor : null;
        }

        // Get rooms data with enhanced level handling (v6.68.19)
        $rooms_table = $wpdb->prefix . 'bme_rooms';
        $rooms = $wpdb->get_results($wpdb->prepare(
            "SELECT room_type, room_level, room_dimensions, room_features
             FROM {$rooms_table}
             WHERE listing_id = %s
             ORDER BY
               CASE
                 WHEN room_level IS NULL OR room_level = '' THEN 999
                 ELSE 0
               END,
               FIELD(COALESCE(room_level, ''), 'Basement', 'First', 'Main,First', 'Main', 'Second', 'Main,Second', 'Third', 'Fourth', 'Fourth Floor', 'Attic'),
               id ASC",
            $listing->listing_id
        ));

        // v6.68.19: Enhanced room processing with level status tracking
        if (!empty($rooms)) {
            $formatted_rooms = array();
            $bedrooms_count = 0;
            $bathrooms_count = 0;
            $rooms_with_level = 0;
            $rooms_without_level = 0;

            foreach ($rooms as $room) {
                $has_level = !empty($room->room_level);
                $has_dimensions = !empty($room->room_dimensions);
                $has_features = !empty($room->room_features) && $room->room_features !== '[]';

                // Consider a room "placeholder" if it has no level, no dimensions, AND no meaningful features
                $is_likely_placeholder = !$has_level && !$has_dimensions && !$has_features;

                // Count rooms with/without level
                if ($has_level) {
                    $rooms_with_level++;
                } else {
                    $rooms_without_level++;
                }

                // Count bedrooms and bathrooms (only real rooms with level or data)
                // v6.68.20: Check bathroom FIRST to avoid "Master Bathroom" being counted as bedroom
                // (iOS already has this fix in FloorLevel.swift and RoomDetailRow.swift)
                if (!$is_likely_placeholder) {
                    $type_lower = strtolower($room->room_type ?? '');

                    // Check bathroom FIRST (must come before bedroom check)
                    $is_bathroom = (strpos($type_lower, 'bath') !== false ||
                                    strpos($type_lower, 'powder') !== false ||
                                    strpos($type_lower, 'shower') !== false);

                    if ($is_bathroom) {
                        $bathrooms_count++;
                    } elseif (strpos($type_lower, 'bed') !== false ||
                              strpos($type_lower, 'master') !== false ||
                              strpos($type_lower, 'primary') !== false ||
                              strpos($type_lower, 'suite') !== false) {
                        // Only count as bedroom if NOT a bathroom
                        // Note: "suite" added for "Primary Suite", "Master Suite" room types
                        $bedrooms_count++;
                    }
                }

                $formatted_rooms[] = array(
                    'type' => $room->room_type,
                    'level' => self::normalize_room_level($room->room_level),
                    'dimensions' => $room->room_dimensions,
                    'features' => self::format_array_field($room->room_features),
                    'has_level' => $has_level,
                    'is_likely_placeholder' => $is_likely_placeholder,
                    'is_special' => false,
                    'level_inferred' => false,
                );
            }

            // v6.68.19: Infer Master Bathroom level from Master Bedroom level
            // The Master Bathroom is obviously on the same floor as the Master Bedroom
            $master_bedroom_level = null;
            foreach ($formatted_rooms as $room) {
                $type_lower = strtolower($room['type'] ?? '');
                // Match "Master Bedroom", "Primary Bedroom", "MasterBedroom", etc.
                if ((strpos($type_lower, 'master') !== false && strpos($type_lower, 'bed') !== false)
                    || (strpos($type_lower, 'primary') !== false && strpos($type_lower, 'bed') !== false)) {
                    if ($room['has_level']) {
                        $master_bedroom_level = $room['level'];
                        break;
                    }
                }
            }

            // If we found a Master Bedroom level, apply it to Master Bathroom without level
            if ($master_bedroom_level) {
                foreach ($formatted_rooms as &$room) {
                    $type_lower = strtolower($room['type'] ?? '');
                    // Match "Master Bathroom", "Primary Bathroom", "MasterBath", etc.
                    if (((strpos($type_lower, 'master') !== false && strpos($type_lower, 'bath') !== false)
                        || (strpos($type_lower, 'primary') !== false && strpos($type_lower, 'bath') !== false))
                        && !$room['has_level']) {
                        $room['level'] = $master_bedroom_level;
                        $room['has_level'] = true;
                        $room['level_inferred'] = true;
                        $room['is_likely_placeholder'] = false;
                        $rooms_with_level++;
                        $rooms_without_level--;
                    }
                }
                unset($room); // Break the reference
            }

            $result['rooms'] = $formatted_rooms;

            // v6.68.19: Extract special rooms from interior_features
            $special_rooms = self::extract_special_rooms(
                $details->interior_features ?? null,
                $rooms,
                $details
            );
            if (!empty($special_rooms)) {
                $result['special_rooms'] = $special_rooms;
            }

            // v6.68.19: Computed room counts for UI display
            $interior_features_str = self::format_array_field($details->interior_features ?? null);
            $has_in_law = $interior_features_str && (
                stripos($interior_features_str, 'Inlaw Apt.') !== false ||
                stripos($interior_features_str, 'In-Law Floorplan') !== false
            );
            $has_bonus_room = $interior_features_str && stripos($interior_features_str, 'Bonus Room') !== false;

            $result['computed_room_counts'] = array(
                'bedrooms_from_rooms' => $bedrooms_count,
                'bathrooms_from_rooms' => $bathrooms_count,
                'total_rooms_displayed' => count($formatted_rooms) + count($special_rooms),
                'rooms_with_level' => $rooms_with_level,
                'rooms_without_level' => $rooms_without_level,
                'has_in_law' => $has_in_law,
                'has_bonus_room' => $has_bonus_room,
            );
        }

        // Add feature flags from features table
        if ($features) {
            $result['has_pool'] = !empty($features->pool_private_yn);
            $result['has_waterfront'] = !empty($features->waterfront_yn);
            $result['has_view'] = !empty($features->view_yn);
            $result['has_water_view'] = !empty($features->mlspin_waterview_flag);
            $result['has_spa'] = !empty($features->spa_yn);
            $result['has_fireplace'] = !empty($details->fireplace_yn);
            $result['has_garage'] = !empty($details->garage_yn);
            $result['has_cooling'] = !empty($details->cooling_yn);

            // v6.60.10: Land area breakdown (for land/farm properties)
            $result['pasture_area'] = isset($features->pasture_area) && $features->pasture_area ? (float) $features->pasture_area : null;
            $result['cultivated_area'] = isset($features->cultivated_area) && $features->cultivated_area ? (float) $features->cultivated_area : null;
            $result['wooded_area'] = isset($features->wooded_area) && $features->wooded_area ? (float) $features->wooded_area : null;
        }

        // Add agent info if available
        if ($agent) {
            $result['agent'] = array(
                'name' => $agent->agent_name ?? null,
                'email' => $agent->agent_email ?? null,
                'phone' => $agent->agent_direct_phone ?? null,
                'photo_url' => null, // Agent photos not stored in bme_agents table
                'office_name' => $agent->office_name ?? null,
                'office_phone' => $agent->office_phone ?? null,
                'agent_mls_id' => $agent->list_agent_mls_id ?? null,
                'office_mls_id' => $agent->list_office_mls_id ?? null,
            );
        } else {
            // No agent data available
            $result['agent'] = array(
                'name' => null,
                'email' => null,
                'phone' => null,
                'photo_url' => null,
                'office_name' => null,
                'office_phone' => null,
                'agent_mls_id' => null,
                'office_mls_id' => null,
            );
        }

        // v6.60.3: Add agent-only information for agent users
        // These fields are stored in bme_listings, not the summary table
        if ($is_agent) {
            $agent_only_data = $wpdb->get_row($wpdb->prepare(
                "SELECT
                    private_remarks,
                    private_office_remarks,
                    showing_instructions,
                    disclosures as private_disclosures
                 FROM {$listings_table}
                 WHERE listing_id = %s
                 LIMIT 1",
                $listing->listing_id
            ));

            // Simplified agent_only_info - only fields that exist in MLSPIN database
            $result['agent_only_info'] = array(
                'private_remarks' => $agent_only_data->private_remarks ?? null,
                'private_office_remarks' => $agent_only_data->private_office_remarks ?? null,
                'showing_instructions' => $agent_only_data->showing_instructions ?? null,
            );
        }

        // v6.60.10: Set defaults for all new fields BEFORE checking $financial
        // This ensures iOS always gets these fields even if no financial record exists
        $result['gross_scheduled_income'] = null;
        $result['existing_lease_type'] = null;
        $result['tenant_pays'] = null;
        $result['lender_owned'] = false;
        $result['concessions_amount'] = null;
        $result['cap_rate'] = null;
        $result['lead_paint'] = false;
        $result['title_5_compliant'] = false;
        $result['perc_test_done'] = false;
        $result['perc_test_date'] = null;

        // v6.60.9: Add rental/lease information from financial table
        if ($financial) {
            // Rental Details
            $result['availability_date'] = $financial->availability_date ?? null;
            $result['available_now'] = !empty($financial->mlspin_availablenow);
            $result['lease_term'] = $financial->lease_term ?? null;
            $result['rent_includes'] = self::format_array_field($financial->rent_includes ?? null);
            $result['security_deposit'] = isset($financial->mlspin_sec_deposit) && $financial->mlspin_sec_deposit ? (float) $financial->mlspin_sec_deposit : null;
            $result['first_month_required'] = !empty($financial->mlspin_first_mon_reqd);
            $result['last_month_required'] = !empty($financial->mlspin_last_mon_reqd);
            $result['references_required'] = !empty($financial->mlspin_references_reqd);
            $result['deposit_required'] = !empty($financial->mlspin_deposit_reqd);
            $result['insurance_required'] = !empty($financial->mlspin_insurance_reqd);

            // Multi-unit rent breakdown (for investment properties)
            $unit_rents = array();
            if (!empty($financial->mlspin_rent1)) {
                $unit_rents[] = array('unit' => 1, 'rent' => (float) $financial->mlspin_rent1, 'lease' => $financial->mlspin_lease_1 ?? null);
            }
            if (!empty($financial->mlspin_rent2)) {
                $unit_rents[] = array('unit' => 2, 'rent' => (float) $financial->mlspin_rent2, 'lease' => $financial->mlspin_lease_2 ?? null);
            }
            if (!empty($financial->mlspin_rent3)) {
                $unit_rents[] = array('unit' => 3, 'rent' => (float) $financial->mlspin_rent3, 'lease' => $financial->mlspin_lease_3 ?? null);
            }
            if (!empty($financial->mlspin_rent4)) {
                $unit_rents[] = array('unit' => 4, 'rent' => (float) $financial->mlspin_rent4, 'lease' => $financial->mlspin_lease_4 ?? null);
            }
            if (!empty($unit_rents)) {
                $result['unit_rents'] = $unit_rents;
                $result['total_monthly_rent'] = array_sum(array_column($unit_rents, 'rent'));
            }

            // Investment metrics
            $result['gross_income'] = isset($financial->gross_income) && $financial->gross_income ? (float) $financial->gross_income : null;
            $result['net_operating_income'] = isset($financial->net_operating_income) && $financial->net_operating_income ? (float) $financial->net_operating_income : null;
            $result['operating_expense'] = isset($financial->operating_expense) && $financial->operating_expense ? (float) $financial->operating_expense : null;
            $result['total_actual_rent'] = isset($financial->total_actual_rent) && $financial->total_actual_rent ? (float) $financial->total_actual_rent : null;

            // v6.60.10: Commercial property fields
            $result['gross_scheduled_income'] = isset($financial->gross_scheduled_income) && $financial->gross_scheduled_income ? (float) $financial->gross_scheduled_income : null;
            // Handle existing_lease_type - clean up empty values
            $lease_type = $financial->existing_lease_type ?? null;
            if ($lease_type && $lease_type !== '[]' && $lease_type !== '') {
                $result['existing_lease_type'] = $lease_type;
            } else {
                $result['existing_lease_type'] = null;
            }
            $result['tenant_pays'] = self::format_array_field($financial->tenant_pays ?? null);
            $result['lender_owned'] = !empty($financial->mlspin_lender_owned);
            $result['concessions_amount'] = isset($financial->concessions_amount) && $financial->concessions_amount ? (float) $financial->concessions_amount : null;

            // Calculate cap rate if we have NOI and price
            $result['cap_rate'] = null; // Default
            $noi = isset($financial->net_operating_income) ? (float) $financial->net_operating_income : 0;
            if ($noi > 0 && $price > 0) {
                $result['cap_rate'] = round(($noi / $price) * 100, 2);
            }

            // v6.60.10: MA-specific disclosure fields
            $result['lead_paint'] = !empty($financial->mlspin_lead_paint);
            $result['title_5_compliant'] = !empty($financial->mlspin_title5);
            $result['perc_test_done'] = !empty($financial->mlspin_perc_test);
            $result['perc_test_date'] = $financial->mlspin_perc_test_date ?? null;
        }

        // v6.60.10: Get business_type, short_sale, and showing_deferral_date from main listings table
        // Set defaults first
        $result['business_type'] = null;
        $result['short_sale'] = false;
        $result['development_status'] = null;
        $result['showing_deferral_date'] = null;

        $commercial_data = $wpdb->get_row($wpdb->prepare(
            "SELECT business_type, mlspin_short_sale_lender_app_reqd, development_status, mlspin_showings_deferral_date
             FROM {$listings_table}
             WHERE listing_id = %s
             LIMIT 1",
            $listing->listing_id
        ));
        if ($commercial_data) {
            $result['business_type'] = $commercial_data->business_type ?? null;
            $result['short_sale'] = !empty($commercial_data->mlspin_short_sale_lender_app_reqd);
            $result['development_status'] = $commercial_data->development_status ?? null;
            // v6.66.0: Showing deferral date (when showings can begin)
            $result['showing_deferral_date'] = $commercial_data->mlspin_showings_deferral_date ?? null;
        }

        // Cache the response for 1 hour
        $response_data = array(
            'success' => true,
            'data' => $result
        );
        set_transient($cache_key, $response_data, HOUR_IN_SECONDS);

        return new WP_REST_Response($response_data, 200);
    }

    // ============ Favorites Handlers ============

    /**
     * Handle get favorites
     * Uses MLD_Property_Preferences to share data with web dashboard
     */
    public static function handle_get_favorites($request) {
        global $wpdb;

        // Prevent CDN caching of authenticated user data
        self::send_no_cache_headers();

        $user_id = get_current_user_id();

        // Check if MLD_Property_Preferences class exists
        if (!class_exists('MLD_Property_Preferences')) {
            return new WP_REST_Response(array(
                'success' => true,
                'data' => array(
                    'properties' => array(),
                    'count' => 0
                )
            ), 200);
        }

        // Get liked (favorite) property IDs using the preferences class
        $favorite_ids = MLD_Property_Preferences::get_liked_properties($user_id);

        if (empty($favorite_ids)) {
            return new WP_REST_Response(array(
                'success' => true,
                'data' => array(
                    'properties' => array(),
                    'count' => 0
                )
            ), 200);
        }

        $summary_table = $wpdb->prefix . 'bme_listing_summary';
        $placeholders = implode(',', array_fill(0, count($favorite_ids), '%s'));

        // Note: favorite_ids are MLS listing_ids (stored by web), query by listing_id
        $sql = $wpdb->prepare(
            "SELECT * FROM {$summary_table} WHERE listing_id IN ({$placeholders})",
            $favorite_ids
        );

        $properties = $wpdb->get_results($sql);

        $formatted = array_map(function($listing) {
            // Build address from components
            $address_parts = array_filter(array($listing->street_number, $listing->street_name));
            $street_address = implode(' ', $address_parts);

            return array(
                'id' => $listing->listing_key,  // Use listing_key (hash) to match iOS property IDs
                'mls_number' => $listing->listing_id,  // Include MLS number separately
                'address' => $street_address,
                'city' => $listing->city,
                'state' => $listing->state_or_province,
                'zip' => $listing->postal_code,
                'price' => (int) $listing->list_price,
                'beds' => (int) $listing->bedrooms_total,
                'baths' => (float) $listing->bathrooms_total,
                'sqft' => (int) $listing->building_area_total,
                'property_type' => $listing->property_type,
                'latitude' => (float) $listing->latitude,
                'longitude' => (float) $listing->longitude,
                'photo_url' => $listing->main_photo_url,
                'status' => $listing->standard_status,
                // v6.64.0: Exclusive listings flag
                'is_exclusive' => intval($listing->listing_id) < 1000000,
                // v6.65.0: Custom badge text for exclusive listings
                'exclusive_tag' => (intval($listing->listing_id) < 1000000)
                    ? (!empty($listing->exclusive_tag) ? $listing->exclusive_tag : 'Exclusive')
                    : null,
            );
        }, $properties);

        return new WP_REST_Response(array(
            'success' => true,
            'data' => array(
                'properties' => $formatted,
                'count' => count($formatted)
            )
        ), 200);
    }

    /**
     * Handle add favorite (toggle)
     * Uses MLD_Property_Preferences to share data with web dashboard
     */
    public static function handle_add_favorite($request) {
        global $wpdb;

        // Prevent CDN caching of authenticated user data
        self::send_no_cache_headers();

        $user_id = get_current_user_id();
        $listing_key = sanitize_text_field($request->get_param('listing_id'));

        // Check if MLD_Property_Preferences class exists
        if (!class_exists('MLD_Property_Preferences')) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'class_not_found',
                'message' => 'Property preferences not available'
            ), 500);
        }

        // iOS passes listing_key (hash), but preferences table stores listing_id (MLS number)
        // Look up the MLS number from the listing_key
        $summary_table = $wpdb->prefix . 'bme_listing_summary';
        $listing_id = $wpdb->get_var($wpdb->prepare(
            "SELECT listing_id FROM {$summary_table} WHERE listing_key = %s",
            $listing_key
        ));

        if (!$listing_id) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'not_found',
                'message' => 'Property not found'
            ), 404);
        }

        // Use toggle_property with 'liked' type
        $result = MLD_Property_Preferences::toggle_property($user_id, $listing_id, 'liked');

        if (is_wp_error($result)) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => $result->get_error_code(),
                'message' => $result->get_error_message()
            ), 400);
        }

        // Check if the property is now favorited
        $is_favorite = MLD_Property_Preferences::is_property_liked($user_id, $listing_id);

        // Trigger favorite added notification for agents (v6.43.0)
        if ($is_favorite) {
            // Get property address for notification
            $property_address = $wpdb->get_var($wpdb->prepare(
                "SELECT CONCAT(street_number, ' ', street_name, ', ', city)
                 FROM {$summary_table} WHERE listing_id = %s",
                $listing_id
            ));

            do_action('mld_favorite_added', $user_id, $listing_id, array(
                'property_address' => $property_address,
                'listing_key' => $listing_key
            ));
        }

        return new WP_REST_Response(array(
            'success' => true,
            'message' => $is_favorite ? 'Added to favorites' : 'Removed from favorites',
            'data' => array('isFavorite' => $is_favorite)
        ), 200);
    }

    /**
     * Handle remove favorite
     * Uses MLD_Property_Preferences to share data with web dashboard
     */
    public static function handle_remove_favorite($request) {
        global $wpdb;

        // Prevent CDN caching of authenticated user data
        self::send_no_cache_headers();

        $user_id = get_current_user_id();
        $listing_key = sanitize_text_field($request->get_param('listing_id'));

        // Check if MLD_Property_Preferences class exists
        if (!class_exists('MLD_Property_Preferences')) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'class_not_found',
                'message' => 'Property preferences not available'
            ), 500);
        }

        // iOS passes listing_key (hash), but preferences table stores listing_id (MLS number)
        $summary_table = $wpdb->prefix . 'bme_listing_summary';
        $listing_id = $wpdb->get_var($wpdb->prepare(
            "SELECT listing_id FROM {$summary_table} WHERE listing_key = %s",
            $listing_key
        ));

        if (!$listing_id) {
            // If not found by listing_key, try using it directly as listing_id
            $listing_id = $listing_key;
        }

        // Remove the preference
        $result = MLD_Property_Preferences::remove_preference($user_id, $listing_id);

        return new WP_REST_Response(array(
            'success' => true,
            'message' => 'Removed from favorites',
            'data' => array('isFavorite' => false)
        ), 200);
    }

    /**
     * Create favorites table
     */
    private static function create_favorites_table() {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_favorites';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            listing_id VARCHAR(50) NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY user_listing (user_id, listing_id),
            KEY listing_id (listing_id)
        ) {$charset_collate};";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    // ============ Hidden Properties Handlers ============

    /**
     * Handle get hidden properties
     */
    public static function handle_get_hidden($request) {
        global $wpdb;

        // Prevent CDN caching of authenticated user data
        self::send_no_cache_headers();

        $user_id = get_current_user_id();

        // Check if MLD_Property_Preferences class exists
        if (!class_exists('MLD_Property_Preferences')) {
            return new WP_REST_Response(array(
                'success' => true,
                'data' => array(
                    'properties' => array(),
                    'count' => 0
                )
            ), 200);
        }

        // Get disliked property IDs using the preferences class
        $hidden_ids = MLD_Property_Preferences::get_disliked_properties($user_id);

        if (empty($hidden_ids)) {
            return new WP_REST_Response(array(
                'success' => true,
                'data' => array(
                    'properties' => array(),
                    'count' => 0
                )
            ), 200);
        }

        $summary_table = $wpdb->prefix . 'bme_listing_summary';
        $placeholders = implode(',', array_fill(0, count($hidden_ids), '%s'));

        // hidden_ids are MLS listing_ids (stored consistently with web favorites)
        $sql = $wpdb->prepare(
            "SELECT * FROM {$summary_table} WHERE listing_id IN ({$placeholders})",
            $hidden_ids
        );

        $properties = $wpdb->get_results($sql);

        $formatted = array_map(function($listing) {
            // Build address from components
            $address_parts = array_filter(array($listing->street_number, $listing->street_name));
            $street_address = implode(' ', $address_parts);

            return array(
                'id' => $listing->listing_key,  // Use listing_key (hash) to match iOS property IDs
                'mls_number' => $listing->listing_id,  // Include MLS number separately
                'address' => $street_address,
                'city' => $listing->city,
                'state' => $listing->state_or_province,
                'zip' => $listing->postal_code,
                'price' => (int) $listing->list_price,
                'beds' => (int) $listing->bedrooms_total,
                'baths' => (float) $listing->bathrooms_total,
                'sqft' => (int) $listing->building_area_total,
                'property_type' => $listing->property_type,
                'latitude' => (float) $listing->latitude,
                'longitude' => (float) $listing->longitude,
                'photo_url' => $listing->main_photo_url,
                'status' => $listing->standard_status,
                // v6.64.0: Exclusive listings flag
                'is_exclusive' => intval($listing->listing_id) < 1000000,
                // v6.65.0: Custom badge text for exclusive listings
                'exclusive_tag' => (intval($listing->listing_id) < 1000000)
                    ? (!empty($listing->exclusive_tag) ? $listing->exclusive_tag : 'Exclusive')
                    : null,
            );
        }, $properties);

        return new WP_REST_Response(array(
            'success' => true,
            'data' => array(
                'properties' => $formatted,
                'count' => count($formatted)
            )
        ), 200);
    }

    /**
     * Handle hide property
     * iOS passes listing_key (hash), we convert to listing_id (MLS number) for storage
     */
    public static function handle_hide_property($request) {
        global $wpdb;

        // Prevent CDN caching of authenticated user data
        self::send_no_cache_headers();

        $user_id = get_current_user_id();
        $listing_key = sanitize_text_field($request->get_param('listing_id'));

        // Check if MLD_Property_Preferences class exists
        if (!class_exists('MLD_Property_Preferences')) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'class_not_found',
                'message' => 'Property preferences not available'
            ), 500);
        }

        // iOS passes listing_key (hash), but preferences table stores listing_id (MLS number)
        // Look up the MLS number from the listing_key
        $summary_table = $wpdb->prefix . 'bme_listing_summary';
        $listing_id = $wpdb->get_var($wpdb->prepare(
            "SELECT listing_id FROM {$summary_table} WHERE listing_key = %s",
            $listing_key
        ));

        if (!$listing_id) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'not_found',
                'message' => 'Property not found'
            ), 404);
        }

        // Use toggle_property with 'disliked' type to hide (using MLS number)
        $result = MLD_Property_Preferences::toggle_property($user_id, $listing_id, 'disliked');

        if (is_wp_error($result)) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => $result->get_error_code(),
                'message' => $result->get_error_message()
            ), 400);
        }

        // Check if the property is now hidden
        $is_hidden = MLD_Property_Preferences::is_property_disliked($user_id, $listing_id);

        return new WP_REST_Response(array(
            'success' => true,
            'message' => $is_hidden ? 'Property hidden' : 'Property unhidden',
            'data' => array('isHidden' => $is_hidden)
        ), 200);
    }

    /**
     * Handle unhide property
     * iOS passes listing_key (hash), we convert to listing_id (MLS number) for lookup
     */
    public static function handle_unhide_property($request) {
        global $wpdb;

        // Prevent CDN caching of authenticated user data
        self::send_no_cache_headers();

        $user_id = get_current_user_id();
        $listing_key = sanitize_text_field($request->get_param('listing_id'));

        // Check if MLD_Property_Preferences class exists
        if (!class_exists('MLD_Property_Preferences')) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'class_not_found',
                'message' => 'Property preferences not available'
            ), 500);
        }

        // iOS passes listing_key (hash), but preferences table stores listing_id (MLS number)
        // Vue.js dashboard may pass either hash or MLS number
        // Look up the MLS number from the listing_key
        $summary_table = $wpdb->prefix . 'bme_listing_summary';
        $listing_id = $wpdb->get_var($wpdb->prepare(
            "SELECT listing_id FROM {$summary_table} WHERE listing_key = %s",
            $listing_key
        ));

        if (!$listing_id) {
            // If not found by listing_key, try using it directly as listing_id
            // This handles the case where Vue.js passes the MLS number directly
            $listing_id = $listing_key;
        }

        // Remove the preference to unhide (using MLS number)
        $result = MLD_Property_Preferences::remove_preference($user_id, $listing_id);

        if (is_wp_error($result)) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => $result->get_error_code(),
                'message' => $result->get_error_message()
            ), 400);
        }

        return new WP_REST_Response(array(
            'success' => true,
            'message' => 'Property unhidden',
            'data' => array('isHidden' => false)
        ), 200);
    }

    // ============ Saved Searches Handlers ============

    /**
     * Convert MySQL datetime to ISO8601 format for iOS compatibility
     * v6.75.4: Fixed to use WordPress timezone instead of UTC
     * Database stores datetimes in WordPress timezone (America/New_York)
     */
    private static function to_iso8601($mysql_datetime) {
        if (empty($mysql_datetime)) {
            return null;
        }
        // Use WordPress timezone - database stores in WP timezone, not UTC
        // Using UTC caused timestamps to appear 5 hours early on iOS
        $dt = new DateTime($mysql_datetime, wp_timezone());
        return $dt->format('c'); // ISO8601 format with timezone offset
    }

    /**
     * Transform polygon shapes from web format to iOS format
     * Web format: [{"type": "polygon", "coordinates": [[lat, lng], ...], "name": null}]
     * iOS format: [[{"lat": lat, "lng": lng}, ...]]
     */
    private static function transform_polygon_shapes($shapes) {
        if (empty($shapes) || !is_array($shapes)) {
            return null;
        }

        $result = array();
        foreach ($shapes as $shape) {
            // Handle web format with type/coordinates structure
            if (isset($shape['coordinates']) && is_array($shape['coordinates'])) {
                $polygon = array();
                foreach ($shape['coordinates'] as $coord) {
                    if (is_array($coord) && count($coord) >= 2) {
                        $polygon[] = array(
                            'lat' => (float) $coord[0],
                            'lng' => (float) $coord[1]
                        );
                    }
                }
                if (!empty($polygon)) {
                    $result[] = $polygon;
                }
            }
            // Handle iOS format already in correct structure [[{lat, lng}, ...]]
            elseif (is_array($shape) && isset($shape[0]['lat'])) {
                $result[] = $shape;
            }
            // Handle flat array format [[lat, lng], [lat, lng], ...]
            elseif (is_array($shape) && isset($shape[0]) && is_array($shape[0]) && count($shape[0]) === 2 && is_numeric($shape[0][0])) {
                $polygon = array();
                foreach ($shape as $coord) {
                    $polygon[] = array(
                        'lat' => (float) $coord[0],
                        'lng' => (float) $coord[1]
                    );
                }
                if (!empty($polygon)) {
                    $result[] = $polygon;
                }
            }
        }

        return empty($result) ? null : $result;
    }

    /**
     * Format a saved search record for API response
     *
     * @since 6.32.0 Added collaboration fields (created_by, modified_by, is_agent_recommended)
     */
    private static function format_saved_search($search) {
        $raw_shapes = json_decode($search->polygon_shapes, true);
        // Validate JSON decode for polygon shapes
        if (json_last_error() !== JSON_ERROR_NONE) {
            $raw_shapes = null;
        }

        $filters = json_decode($search->filters, true);
        // Validate JSON decode for filters - fallback to empty array
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($filters)) {
            $filters = array();
        }

        $formatted = array(
            'id' => (int) $search->id,
            'name' => $search->name ?? '',
            'description' => $search->description,
            'filters' => $filters,
            'polygon_shapes' => self::transform_polygon_shapes($raw_shapes),
            'notification_frequency' => $search->notification_frequency ?: 'daily',
            'is_active' => (bool) $search->is_active,
            'match_count' => (int) ($search->last_matched_count ?? 0),
            'created_at' => self::to_iso8601($search->created_at),
            'updated_at' => self::to_iso8601($search->updated_at),
            'last_notified_at' => self::to_iso8601($search->last_notified_at),
        );

        // Add collaboration fields (v6.32.0)
        if (isset($search->is_agent_recommended)) {
            $formatted['is_agent_recommended'] = (bool) $search->is_agent_recommended;
        }

        if (isset($search->agent_notes)) {
            $formatted['agent_notes'] = $search->agent_notes;
        }

        if (isset($search->cc_agent_on_notify)) {
            $formatted['cc_agent_on_notify'] = (bool) $search->cc_agent_on_notify;
        }

        // Add creator info if available
        if (!empty($search->created_by_user_id)) {
            $creator = get_userdata($search->created_by_user_id);
            $formatted['created_by'] = array(
                'user_id' => (int) $search->created_by_user_id,
                'name' => $creator ? $creator->display_name : 'Unknown',
                'is_agent' => class_exists('MLD_User_Type_Manager')
                    && MLD_User_Type_Manager::is_agent($search->created_by_user_id),
            );
        }

        // Add last modifier info if available
        if (!empty($search->last_modified_by_user_id)) {
            $modifier = get_userdata($search->last_modified_by_user_id);
            $formatted['last_modified_by'] = array(
                'user_id' => (int) $search->last_modified_by_user_id,
                'name' => $modifier ? $modifier->display_name : 'Unknown',
            );
            $formatted['last_modified_at'] = self::to_iso8601($search->last_modified_at);
        }

        return $formatted;
    }

    /**
     * Handle get saved searches (list)
     */
    public static function handle_get_saved_searches($request) {
        global $wpdb;

        // Prevent CDN caching of authenticated user data
        self::send_no_cache_headers();

        $user_id = get_current_user_id();
        $table = $wpdb->prefix . 'mld_saved_searches';

        $searches = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE user_id = %d AND is_active = 1 ORDER BY created_at DESC",
            $user_id
        ));

        $formatted = array_map(array(__CLASS__, 'format_saved_search'), $searches);

        return new WP_REST_Response(array(
            'success' => true,
            'data' => array(
                'searches' => $formatted,
                'count' => count($formatted)
            )
        ), 200);
    }

    /**
     * Handle get single saved search
     */
    public static function handle_get_saved_search($request) {
        global $wpdb;

        // Prevent CDN caching of authenticated user data
        self::send_no_cache_headers();

        $user_id = get_current_user_id();
        $search_id = absint($request->get_param('id'));
        $table = $wpdb->prefix . 'mld_saved_searches';

        $search = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d AND user_id = %d",
            $search_id,
            $user_id
        ));

        if (!$search) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'not_found',
                'message' => 'Saved search not found'
            ), 404);
        }

        return new WP_REST_Response(array(
            'success' => true,
            'data' => self::format_saved_search($search)
        ), 200);
    }

    /**
     * Handle create saved search
     */
    public static function handle_create_saved_search($request) {
        global $wpdb;

        // Prevent CDN caching of authenticated user data
        self::send_no_cache_headers();

        $user_id = get_current_user_id();

        // Validate user is authenticated (prevent user_id = 0 saves)
        if ($user_id <= 0) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'invalid_user',
                'message' => 'Valid user authentication required'
            ), 401);
        }

        $params = $request->get_json_params();

        // Sanitize and trim name
        $name = trim(isset($params['name']) ? sanitize_text_field($params['name']) : '');
        $description = isset($params['description']) ? sanitize_textarea_field($params['description']) : null;
        $filters = isset($params['filters']) ? $params['filters'] : array();
        $polygon_shapes = isset($params['polygon_shapes']) ? $params['polygon_shapes'] : null;
        $frequency = isset($params['notification_frequency']) ? sanitize_text_field($params['notification_frequency']) : 'daily';

        // Normalize iOS camelCase frequency to PHP snake_case
        $frequency = self::normalize_frequency($frequency);

        // Validate frequency
        $valid_frequencies = array('instant', 'fifteen_min', 'hourly', 'daily', 'weekly', 'none');
        if (!in_array($frequency, $valid_frequencies)) {
            $frequency = 'daily';
        }

        // Validate name is not empty after trimming
        if (empty($name) || strlen($name) < 1) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'missing_name',
                'message' => 'Search name is required'
            ), 400);
        }

        // Normalize filter arrays for consistent storage
        $filters = self::normalize_filter_arrays($filters);

        // Validate polygon size (max 100 points total)
        if (!empty($polygon_shapes)) {
            $total_points = 0;
            foreach ($polygon_shapes as $shape) {
                if (is_array($shape)) {
                    $total_points += count($shape);
                }
            }
            if ($total_points > 100) {
                return new WP_REST_Response(array(
                    'success' => false,
                    'code' => 'polygon_too_complex',
                    'message' => 'Polygon cannot exceed 100 points'
                ), 400);
            }
        }

        $table = $wpdb->prefix . 'mld_saved_searches';
        $now = current_time('mysql');

        $insert_data = array(
            'user_id' => $user_id,
            'name' => $name,
            'description' => $description,
            'filters' => wp_json_encode($filters),
            'polygon_shapes' => $polygon_shapes ? wp_json_encode($polygon_shapes) : null,
            'notification_frequency' => $frequency,
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now
        );

        $wpdb->insert($table, $insert_data);

        if ($wpdb->last_error) {
            // Log error server-side but don't expose to client
            error_log('MLD Saved Search Create Error: ' . $wpdb->last_error);
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'db_error',
                'message' => 'Failed to create saved search. Please try again.'
            ), 500);
        }

        $search_id = $wpdb->insert_id;

        // Trigger saved search created notification for agents (v6.43.0)
        do_action('mld_saved_search_created', $user_id, array(
            'search_id' => $search_id,
            'search_name' => $name
        ));

        // Fetch the created record
        $search = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d",
            $search_id
        ));

        return new WP_REST_Response(array(
            'success' => true,
            'data' => self::format_saved_search($search)
        ), 201);
    }

    /**
     * Handle update saved search
     */
    public static function handle_update_saved_search($request) {
        global $wpdb;

        // Prevent CDN caching of authenticated user data
        self::send_no_cache_headers();

        $user_id = get_current_user_id();
        $search_id = absint($request->get_param('id'));
        $params = $request->get_json_params();

        $table = $wpdb->prefix . 'mld_saved_searches';

        // Get existing search
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d AND user_id = %d",
            $search_id,
            $user_id
        ));

        if (!$existing) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'not_found',
                'message' => 'Saved search not found'
            ), 404);
        }

        // Conflict detection: Check if client's version is older than server's
        if (!empty($params['updated_at'])) {
            $client_updated_at = strtotime($params['updated_at']);
            $server_updated_at = strtotime($existing->updated_at);

            if ($server_updated_at > $client_updated_at) {
                // Server has newer version - return conflict with current data
                return new WP_REST_Response(array(
                    'success' => false,
                    'code' => 'conflict',
                    'message' => 'Server has newer version',
                    'data' => self::format_saved_search($existing)
                ), 409);
            }
        }

        // Build update data
        $update_data = array('updated_at' => current_time('mysql'));

        if (isset($params['name'])) {
            $update_data['name'] = sanitize_text_field($params['name']);
        }
        if (isset($params['description'])) {
            $update_data['description'] = sanitize_textarea_field($params['description']);
        }
        if (isset($params['filters'])) {
            // Normalize filter arrays for consistent storage
            $filters = self::normalize_filter_arrays($params['filters']);
            $update_data['filters'] = wp_json_encode($filters);
        }
        if (isset($params['polygon_shapes'])) {
            // Validate polygon size (max 100 points total)
            $polygon_shapes = $params['polygon_shapes'];
            if (!empty($polygon_shapes)) {
                $total_points = 0;
                foreach ($polygon_shapes as $shape) {
                    if (is_array($shape)) {
                        $total_points += count($shape);
                    }
                }
                if ($total_points > 100) {
                    return new WP_REST_Response(array(
                        'success' => false,
                        'code' => 'polygon_too_complex',
                        'message' => 'Polygon cannot exceed 100 points'
                    ), 400);
                }
            }
            $update_data['polygon_shapes'] = $polygon_shapes ? wp_json_encode($polygon_shapes) : null;
        }
        if (isset($params['notification_frequency'])) {
            $valid_frequencies = array('instant', 'fifteen_min', 'hourly', 'daily', 'weekly', 'none');
            $frequency = sanitize_text_field($params['notification_frequency']);
            // Normalize iOS camelCase frequency to PHP snake_case
            $frequency = self::normalize_frequency($frequency);
            if (in_array($frequency, $valid_frequencies)) {
                $update_data['notification_frequency'] = $frequency;
            }
        }
        if (isset($params['is_active'])) {
            $update_data['is_active'] = (bool) $params['is_active'] ? 1 : 0;
        }

        $wpdb->update(
            $table,
            $update_data,
            array('id' => $search_id, 'user_id' => $user_id)
        );

        // Fetch updated record
        $search = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d",
            $search_id
        ));

        return new WP_REST_Response(array(
            'success' => true,
            'data' => self::format_saved_search($search)
        ), 200);
    }

    /**
     * Handle delete saved search
     */
    public static function handle_delete_saved_search($request) {
        global $wpdb;

        // Prevent CDN caching of authenticated user data
        self::send_no_cache_headers();

        $user_id = get_current_user_id();
        $search_id = absint($request->get_param('id'));

        $table = $wpdb->prefix . 'mld_saved_searches';

        // Verify ownership
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$table} WHERE id = %d AND user_id = %d",
            $search_id,
            $user_id
        ));

        if (!$existing) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'not_found',
                'message' => 'Saved search not found'
            ), 404);
        }

        // Soft delete
        $wpdb->update(
            $table,
            array('is_active' => 0, 'updated_at' => current_time('mysql')),
            array('id' => $search_id, 'user_id' => $user_id),
            array('%d', '%s'),
            array('%d', '%d')
        );

        return new WP_REST_Response(array(
            'success' => true,
            'message' => 'Search deleted'
        ), 200);
    }

    // ============ Device Token Handlers ============

    /**
     * Handle register device for push notifications
     */
    public static function handle_register_device($request) {
        global $wpdb;

        // Prevent CDN caching
        self::send_no_cache_headers();

        $user_id = get_current_user_id();
        $params = $request->get_json_params();

        $device_token = isset($params['device_token']) ? sanitize_text_field($params['device_token']) : '';
        $platform = isset($params['platform']) ? sanitize_text_field($params['platform']) : 'ios';
        $app_version = isset($params['app_version']) ? sanitize_text_field($params['app_version']) : '';
        $device_model = isset($params['device_model']) ? sanitize_text_field($params['device_model']) : '';

        if (empty($device_token)) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'missing_token',
                'message' => 'Device token is required'
            ), 400);
        }

        // Validate platform
        if (!in_array($platform, array('ios', 'android'))) {
            $platform = 'ios';
        }

        // Validate device token format based on platform
        if ($platform === 'ios' && !preg_match('/^[a-f0-9]{64}$/i', $device_token)) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'invalid_token_format',
                'message' => 'Invalid iOS device token format'
            ), 400);
        }
        if ($platform === 'android' && strlen($device_token) < 100) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'invalid_token_format',
                'message' => 'Invalid Android device token format'
            ), 400);
        }

        $table = $wpdb->prefix . 'mld_device_tokens';
        $now = current_time('mysql');

        // Check if token exists
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id, user_id FROM {$table} WHERE device_token = %s",
            $device_token
        ));

        if ($existing) {
            // Update existing token (might be different user now)
            $wpdb->update(
                $table,
                array(
                    'user_id' => $user_id,
                    'platform' => $platform,
                    'app_version' => $app_version,
                    'device_model' => $device_model,
                    'is_active' => 1,
                    'last_used_at' => $now
                ),
                array('device_token' => $device_token)
            );
        } else {
            // Insert new token
            $wpdb->insert($table, array(
                'user_id' => $user_id,
                'device_token' => $device_token,
                'platform' => $platform,
                'app_version' => $app_version,
                'device_model' => $device_model,
                'is_active' => 1,
                'created_at' => $now,
                'last_used_at' => $now
            ));
        }

        return new WP_REST_Response(array(
            'success' => true,
            'message' => 'Device registered successfully'
        ), 200);
    }

    /**
     * Handle unregister device
     */
    public static function handle_unregister_device($request) {
        global $wpdb;

        $user_id = get_current_user_id();
        $device_token = sanitize_text_field($request->get_param('token'));

        if (empty($device_token)) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'missing_token',
                'message' => 'Device token is required'
            ), 400);
        }

        $table = $wpdb->prefix . 'mld_device_tokens';

        // Soft delete (mark as inactive)
        $wpdb->update(
            $table,
            array('is_active' => 0),
            array('device_token' => $device_token, 'user_id' => $user_id),
            array('%d'),
            array('%s', '%d')
        );

        return new WP_REST_Response(array(
            'success' => true,
            'message' => 'Device unregistered successfully'
        ), 200);
    }

    // ============ Analytics Handlers ============

    /**
     * Handle get cities
     */
    public static function handle_get_cities($request) {
        global $wpdb;

        $limit = absint($request->get_param('limit')) ?: 20;
        $table = $wpdb->prefix . 'bme_listing_summary';

        $cities = $wpdb->get_results($wpdb->prepare(
            "SELECT city, state_or_province as state,
                    COUNT(*) as listing_count,
                    AVG(list_price) as avg_price,
                    AVG(days_on_market) as avg_dom
             FROM {$table}
             WHERE standard_status = 'Active' AND city IS NOT NULL AND city != ''
             GROUP BY city, state_or_province
             ORDER BY listing_count DESC
             LIMIT %d",
            $limit
        ));

        $formatted = array_map(function($city) {
            return array(
                'city' => $city->city,
                'state' => $city->state,
                'listing_count' => (int) $city->listing_count,
                'avg_price' => round((float) $city->avg_price),
                'avg_dom' => round((float) $city->avg_dom),
            );
        }, $cities);

        return new WP_REST_Response(array(
            'success' => true,
            'data' => array(
                'cities' => $formatted,
                'count' => count($formatted)
            )
        ), 200);
    }

    /**
     * Handle get city summary
     */
    public static function handle_get_city_summary($request) {
        global $wpdb;

        $city = urldecode($request->get_param('city'));
        $state = sanitize_text_field($request->get_param('state')) ?: 'MA';
        $table = $wpdb->prefix . 'bme_listing_summary';

        $summary = $wpdb->get_row($wpdb->prepare(
            "SELECT
                COUNT(*) as active_listings,
                AVG(list_price) as avg_price,
                MIN(list_price) as min_price,
                MAX(list_price) as max_price,
                AVG(list_price / NULLIF(building_area_total, 0)) as avg_price_per_sqft,
                AVG(days_on_market) as avg_dom,
                (SELECT list_price FROM {$table} WHERE city = %s AND standard_status = 'Active'
                 ORDER BY list_price LIMIT 1 OFFSET (SELECT COUNT(*)/2 FROM {$table} WHERE city = %s AND standard_status = 'Active')) as median_price
             FROM {$table}
             WHERE city = %s AND standard_status = 'Active'",
            $city, $city, $city
        ));

        // Calculate market heat
        $market_heat = self::calculate_market_heat($summary);

        return new WP_REST_Response(array(
            'success' => true,
            'data' => array(
                'city' => $city,
                'state' => $state,
                'summary' => array(
                    'active_listings' => (int) $summary->active_listings,
                    'avg_price' => round((float) $summary->avg_price),
                    'min_price' => (int) $summary->min_price,
                    'max_price' => (int) $summary->max_price,
                    'median_price' => (int) $summary->median_price,
                    'avg_price_per_sqft' => $summary->avg_price_per_sqft ? round((float) $summary->avg_price_per_sqft) : null,
                    'avg_dom' => round((float) $summary->avg_dom, 1),
                ),
                'market_heat' => $market_heat
            )
        ), 200);
    }

    /**
     * Calculate market heat index
     */
    private static function calculate_market_heat($summary) {
        if (!$summary || $summary->active_listings == 0) {
            return array(
                'score' => null,
                'classification' => 'Unknown',
                'description' => 'Insufficient data'
            );
        }

        $dom = (float) $summary->avg_dom;

        // Simple heat calculation based on days on market
        if ($dom < 14) {
            $score = 90;
            $classification = 'Hot';
            $description = 'Properties are selling very quickly';
        } elseif ($dom < 30) {
            $score = 70;
            $classification = 'Warm';
            $description = 'Strong demand with quick sales';
        } elseif ($dom < 60) {
            $score = 50;
            $classification = 'Balanced';
            $description = 'Healthy market with moderate pace';
        } elseif ($dom < 90) {
            $score = 30;
            $classification = 'Cool';
            $description = 'Slower market favoring buyers';
        } else {
            $score = 15;
            $classification = 'Cold';
            $description = 'Properties taking longer to sell';
        }

        return array(
            'score' => $score,
            'classification' => $classification,
            'description' => $description
        );
    }

    /**
     * Handle get neighborhood/city analytics for map bounds
     * Returns median price, listing count, and market heat for cities within bounds
     * (Uses city-level since subdivision_name is not reliably populated)
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function handle_get_neighborhood_analytics($request) {
        global $wpdb;

        // Parse bounds parameter: south,west,north,east
        $bounds_param = sanitize_text_field($request->get_param('bounds'));
        $property_type = sanitize_text_field($request->get_param('property_type')) ?: 'Residential';

        if (empty($bounds_param)) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'missing_bounds',
                'message' => 'bounds parameter is required (format: south,west,north,east)'
            ), 400);
        }

        $bounds = explode(',', $bounds_param);
        if (count($bounds) !== 4) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'invalid_bounds',
                'message' => 'bounds must be in format: south,west,north,east'
            ), 400);
        }

        $south = floatval($bounds[0]);
        $west = floatval($bounds[1]);
        $north = floatval($bounds[2]);
        $east = floatval($bounds[3]);

        // PERFORMANCE FIX: Add 15-minute cache for analytics
        // Round bounds to 2 decimal places for cache key (within ~1km accuracy)
        $cache_key = 'mld_analytics_' . md5(sprintf(
            '%.2f,%.2f,%.2f,%.2f_%s',
            $south, $west, $north, $east, $property_type
        ));
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return new WP_REST_Response($cached, 200);
        }

        $summary_table = $wpdb->prefix . 'bme_listing_summary';

        // Query cities within bounds with their stats (simpler, faster query)
        $cities = $wpdb->get_results($wpdb->prepare(
            "SELECT
                s.city as name,
                AVG(s.latitude) as center_lat,
                AVG(s.longitude) as center_lng,
                COUNT(*) as listing_count,
                AVG(s.list_price) as avg_price,
                AVG(s.days_on_market) as avg_dom
             FROM {$summary_table} s
             WHERE s.standard_status = 'Active'
               AND s.property_type = %s
               AND s.latitude BETWEEN %f AND %f
               AND s.longitude BETWEEN %f AND %f
               AND s.city IS NOT NULL
               AND s.city != ''
             GROUP BY s.city
             HAVING listing_count >= 3
             ORDER BY listing_count DESC
             LIMIT 50",
            $property_type, $south, $north, $west, $east
        ));

        // PERFORMANCE FIX: Calculate median prices in a single batch query
        // Instead of N+1 queries (one per city), we use a single query with row numbering
        $median_prices = array();
        if (!empty($cities)) {
            $city_names = array_map(function($c) { return $c->name; }, $cities);
            $placeholders = implode(',', array_fill(0, count($city_names), '%s'));
            $params = array_merge($city_names, array($property_type));

            // Use subquery to calculate row numbers and find middle value
            $median_results = $wpdb->get_results($wpdb->prepare(
                "SELECT city, list_price FROM (
                    SELECT city, list_price,
                           ROW_NUMBER() OVER (PARTITION BY city ORDER BY list_price) as rn,
                           COUNT(*) OVER (PARTITION BY city) as total
                    FROM {$summary_table}
                    WHERE city IN ({$placeholders})
                      AND standard_status = 'Active'
                      AND property_type = %s
                ) ranked
                WHERE rn = FLOOR((total + 1) / 2)",
                ...$params
            ));

            foreach ($median_results as $row) {
                $median_prices[$row->city] = (int) $row->list_price;
            }
        }

        // Fill in missing medians with avg_price (fallback for cities without median result)
        foreach ($cities as $city) {
            if (!isset($median_prices[$city->name])) {
                $median_prices[$city->name] = (int) $city->avg_price;
            }
        }

        // Format response
        $formatted_areas = array();
        foreach ($cities as $c) {
            // Calculate market heat based on DOM
            $dom = (float) $c->avg_dom;
            if ($dom < 14) {
                $market_heat = 'hot';
            } elseif ($dom < 30) {
                $market_heat = 'warm';
            } elseif ($dom < 60) {
                $market_heat = 'balanced';
            } elseif ($dom < 90) {
                $market_heat = 'cool';
            } else {
                $market_heat = 'cold';
            }

            $formatted_areas[] = array(
                'name' => $c->name,
                'type' => 'city',
                'center' => array(
                    'lat' => (float) $c->center_lat,
                    'lng' => (float) $c->center_lng,
                ),
                'median_price' => $median_prices[$c->name] ?? (int) $c->avg_price,
                'avg_price' => (int) $c->avg_price,
                'avg_dom' => round((float) $c->avg_dom, 1),
                'listing_count' => (int) $c->listing_count,
                'market_heat' => $market_heat,
            );
        }

        $response_data = array(
            'success' => true,
            'data' => array(
                'neighborhoods' => $formatted_areas,
                'bounds' => array(
                    'south' => $south,
                    'west' => $west,
                    'north' => $north,
                    'east' => $east,
                ),
                'property_type' => $property_type,
            )
        );

        // Cache for 15 minutes
        set_transient($cache_key, $response_data, 15 * MINUTE_IN_SECONDS);

        return new WP_REST_Response($response_data, 200);
    }

    /**
     * Handle get trends
     */
    public static function handle_get_trends($request) {
        global $wpdb;

        $city = urldecode($request->get_param('city'));
        $state = sanitize_text_field($request->get_param('state')) ?: 'MA';
        $months = absint($request->get_param('months')) ?: 12;

        // PERFORMANCE FIX: Add 30-minute cache for trends (data changes slowly)
        $cache_key = 'mld_trends_' . md5($city . '_' . $state . '_' . $months);
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return new WP_REST_Response($cached, 200);
        }

        $table = $wpdb->prefix . 'bme_listing_summary';

        // Get monthly trends from closed listings
        $trends = $wpdb->get_results($wpdb->prepare(
            "SELECT
                DATE_FORMAT(modification_timestamp, '%%Y-%%m') as month,
                COUNT(*) as sales_count,
                AVG(list_price) as median_price,
                AVG(days_on_market) as avg_dom
             FROM {$table}
             WHERE city = %s
               AND standard_status = 'Closed'
               AND modification_timestamp >= DATE_SUB(NOW(), INTERVAL %d MONTH)
             GROUP BY DATE_FORMAT(modification_timestamp, '%%Y-%%m')
             ORDER BY month ASC",
            $city, $months
        ));

        $formatted = array_map(function($trend) {
            return array(
                'month' => $trend->month,
                'sales_count' => (int) $trend->sales_count,
                'median_price' => round((float) $trend->median_price),
                'avg_dom' => round((float) $trend->avg_dom),
            );
        }, $trends);

        $response_data = array(
            'success' => true,
            'data' => array(
                'city' => $city,
                'state' => $state,
                'months' => $months,
                'trends' => $formatted
            )
        );

        // Cache for 30 minutes
        set_transient($cache_key, $response_data, 30 * MINUTE_IN_SECONDS);

        return new WP_REST_Response($response_data, 200);
    }

    /**
     * Handle compare cities
     */
    public static function handle_compare_cities($request) {
        global $wpdb;

        $cities_param = sanitize_text_field($request->get_param('cities'));
        $cities = array_map('trim', explode(',', $cities_param));
        $state = sanitize_text_field($request->get_param('state')) ?: 'MA';

        // PERFORMANCE FIX: Add 15-minute cache for city comparison
        $cache_key = 'mld_compare_' . md5($cities_param . '_' . $state);
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return new WP_REST_Response($cached, 200);
        }

        $table = $wpdb->prefix . 'bme_listing_summary';

        // PERFORMANCE FIX: Single query with GROUP BY instead of N+1 queries
        $placeholders = implode(',', array_fill(0, count($cities), '%s'));
        $summaries = $wpdb->get_results($wpdb->prepare(
            "SELECT
                city,
                COUNT(*) as active_listings,
                AVG(list_price) as avg_price,
                AVG(days_on_market) as avg_dom
             FROM {$table}
             WHERE city IN ({$placeholders}) AND standard_status = 'Active'
             GROUP BY city",
            ...$cities
        ), OBJECT_K); // Key by city name

        $results = array();
        foreach ($cities as $city) {
            $summary = isset($summaries[$city]) ? $summaries[$city] : null;

            $results[] = array(
                'city' => $city,
                'state' => $state,
                'summary' => array(
                    'active_listings' => $summary ? (int) $summary->active_listings : 0,
                    'avg_price' => $summary ? round((float) $summary->avg_price) : 0,
                    'avg_dom' => $summary ? round((float) $summary->avg_dom) : 0,
                )
            );
        }

        $response_data = array(
            'success' => true,
            'data' => array(
                'cities' => $results,
                'count' => count($results)
            )
        );

        // Cache for 15 minutes
        set_transient($cache_key, $response_data, 15 * MINUTE_IN_SECONDS);

        return new WP_REST_Response($response_data, 200);
    }

    /**
     * Handle get market overview
     */
    public static function handle_get_overview($request) {
        global $wpdb;

        $table = $wpdb->prefix . 'bme_listing_summary';

        // Overall stats
        $overall = $wpdb->get_row(
            "SELECT
                COUNT(*) as total_active,
                AVG(list_price) as avg_price,
                MIN(list_price) as min_price,
                MAX(list_price) as max_price,
                AVG(days_on_market) as avg_dom
             FROM {$table}
             WHERE standard_status = 'Active'"
        );

        // By property type
        $property_types = $wpdb->get_results(
            "SELECT
                property_type as type,
                COUNT(*) as count,
                AVG(list_price) as avg_price
             FROM {$table}
             WHERE standard_status = 'Active' AND property_type IS NOT NULL
             GROUP BY property_type
             ORDER BY count DESC"
        );

        // Price distribution
        $price_ranges = array(
            array('min' => 0, 'max' => 300000, 'label' => 'Under $300K'),
            array('min' => 300000, 'max' => 500000, 'label' => '$300K-$500K'),
            array('min' => 500000, 'max' => 750000, 'label' => '$500K-$750K'),
            array('min' => 750000, 'max' => 1000000, 'label' => '$750K-$1M'),
            array('min' => 1000000, 'max' => 2000000, 'label' => '$1M-$2M'),
            array('min' => 2000000, 'max' => 999999999, 'label' => '$2M+'),
        );

        $distribution = array();
        foreach ($price_ranges as $range) {
            $count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table}
                 WHERE standard_status = 'Active'
                   AND list_price >= %d AND list_price < %d",
                $range['min'], $range['max']
            ));
            $distribution[] = array(
                'range' => $range['label'],
                'count' => (int) $count
            );
        }

        return new WP_REST_Response(array(
            'success' => true,
            'data' => array(
                'overall' => array(
                    'total_active' => (int) $overall->total_active,
                    'avg_price' => round((float) $overall->avg_price),
                    'avg_dom' => round((float) $overall->avg_dom),
                    'price_range' => array(
                        'min' => (int) $overall->min_price,
                        'max' => (int) $overall->max_price
                    )
                ),
                'property_types' => array_map(function($type) {
                    return array(
                        'type' => $type->type,
                        'count' => (int) $type->count,
                        'avg_price' => round((float) $type->avg_price)
                    );
                }, $property_types),
                'price_distribution' => $distribution
            )
        ), 200);
    }

    // ============ CMA Handlers ============

    /**
     * Handle get CMA sessions
     */
    public static function handle_get_cma_sessions($request) {
        global $wpdb;

        $user_id = get_current_user_id();
        $table = $wpdb->prefix . 'mld_cma_sessions';

        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table}'");

        if (!$table_exists) {
            return new WP_REST_Response(array(
                'success' => true,
                'data' => array(
                    'sessions' => array(),
                    'count' => 0
                )
            ), 200);
        }

        $user = wp_get_current_user();

        // Get sessions by user_id OR user_email
        $sessions = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE (user_id = %d OR user_email = %s)
             ORDER BY updated_at DESC",
            $user_id, $user->user_email
        ));

        $formatted = array_map(function($session) {
            $subject = json_decode($session->subject_property, true);
            // Validate JSON decode - fallback to empty array if invalid
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($subject)) {
                $subject = array();
            }
            return array(
                'id' => (int) $session->id,
                'name' => $session->session_name,
                'type' => $session->session_type,
                'address' => $subject['address'] ?? '',
                'city' => $subject['city'] ?? '',
                'state' => $subject['state'] ?? '',
                'zip' => $subject['zip'] ?? '',
                'estimated_value' => $session->estimated_value ? (int) $session->estimated_value : null,
                'confidence_score' => $session->confidence_score ? (int) $session->confidence_score : null,
                'comparables_count' => (int) $session->comparables_count,
                'created_at' => $session->created_at,
                'updated_at' => $session->updated_at,
            );
        }, $sessions);

        return new WP_REST_Response(array(
            'success' => true,
            'data' => array(
                'sessions' => $formatted,
                'count' => count($formatted)
            )
        ), 200);
    }

    /**
     * Handle get CMA session detail
     */
    public static function handle_get_cma_session($request) {
        global $wpdb;

        $user_id = get_current_user_id();
        $session_id = absint($request->get_param('id'));

        $sessions_table = $wpdb->prefix . 'mld_cma_sessions';
        $comps_table = $wpdb->prefix . 'mld_cma_comparables';

        $user = wp_get_current_user();

        // Get session
        $session = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$sessions_table}
             WHERE id = %d AND (user_id = %d OR user_email = %s)",
            $session_id, $user_id, $user->user_email
        ));

        if (!$session) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'not_found',
                'message' => 'CMA session not found'
            ), 404);
        }

        // Get comparables
        $comparables = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$comps_table} WHERE session_id = %d ORDER BY distance_miles ASC",
            $session_id
        ));

        $subject = json_decode($session->subject_property, true);
        // Validate JSON decode - fallback to empty arrays if invalid
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($subject)) {
            $subject = array();
        }
        $value_range = json_decode($session->value_range, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($value_range)) {
            $value_range = array();
        }

        $formatted_comps = array_map(function($comp) {
            // Validate adjustments JSON
            $adjustments = json_decode($comp->adjustments, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($adjustments)) {
                $adjustments = null;
            }
            return array(
                'listing_id' => $comp->listing_id,
                'address' => $comp->street_address,
                'city' => $comp->city,
                'state' => $comp->state,
                'beds' => (int) $comp->bedrooms,
                'baths' => (float) $comp->bathrooms,
                'sqft' => (int) $comp->square_feet,
                'sold_price' => (int) $comp->sold_price,
                'sold_date' => $comp->sold_date,
                'distance_miles' => (float) $comp->distance_miles,
                'price_per_sqft' => $comp->square_feet > 0 ? round($comp->sold_price / $comp->square_feet) : null,
                'photo_url' => $comp->photo_url,
                'adjustments' => $adjustments,
                'adjusted_price' => $comp->adjusted_price ? (int) $comp->adjusted_price : null,
            );
        }, $comparables);

        return new WP_REST_Response(array(
            'success' => true,
            'data' => array(
                'session' => array(
                    'id' => (int) $session->id,
                    'name' => $session->session_name,
                    'type' => $session->session_type,
                    'subject' => array(
                        'address' => $subject['address'] ?? '',
                        'city' => $subject['city'] ?? '',
                        'state' => $subject['state'] ?? '',
                        'zip' => $subject['zip'] ?? '',
                        'beds' => (int) ($subject['beds'] ?? $subject['bedrooms'] ?? 0),
                        'baths' => (float) ($subject['baths'] ?? $subject['bathrooms'] ?? 0),
                        'sqft' => (int) ($subject['sqft'] ?? $subject['square_feet'] ?? 0),
                        'year_built' => isset($subject['year_built']) ? (int) $subject['year_built'] : null,
                    ),
                    'estimated_value' => $session->estimated_value ? (int) $session->estimated_value : null,
                    'value_range' => array(
                        'low' => isset($value_range['low']) ? (int) $value_range['low'] : null,
                        'high' => isset($value_range['high']) ? (int) $value_range['high'] : null,
                    ),
                    'confidence_score' => $session->confidence_score ? (int) $session->confidence_score : null,
                    'notes' => $session->notes,
                    'created_at' => $session->created_at,
                    'updated_at' => $session->updated_at,
                ),
                'comparables' => $formatted_comps,
                'web_url' => home_url('/cma/view/' . $session->public_id)
            )
        ), 200);
    }

    /**
     * Calculate comparability score for iOS CMA (0-100)
     * Balances distance and recency equally per requirements
     *
     * @param object $comp Comparable property object
     * @param object $subject Subject property object
     * @return array Array with 'score' (0-100) and 'grade' (A-F)
     */
    private static function calculate_mobile_comparability_score($comp, $subject) {
        $score = 100;

        // Distance penalty (max 20 pts) - closer is better
        $distance = floatval($comp->distance_miles);
        $score -= min($distance * 4, 20);

        // Recency penalty (max 20 pts) - recent is better
        $close_date = strtotime($comp->close_date);
        $days_ago = (current_time('timestamp') - $close_date) / 86400;
        if ($days_ago <= 90) {
            $score += 5; // Bonus for very recent
        } elseif ($days_ago <= 180) {
            $score -= 5;
        } elseif ($days_ago <= 270) {
            $score -= 10;
        } else {
            $score -= min(($days_ago - 270) / 10, 15);
        }

        // Size penalty (max 15 pts)
        $subject_sqft = intval($subject->building_area_total);
        $comp_sqft = intval($comp->building_area_total);
        if ($subject_sqft > 0 && $comp_sqft > 0) {
            $size_diff_pct = abs($comp_sqft - $subject_sqft) / $subject_sqft * 100;
            $score -= min($size_diff_pct / 2, 15);
        }

        // Bedroom match (5 pt bonus for exact, penalty otherwise)
        $bed_diff = abs(intval($comp->bedrooms_total) - intval($subject->bedrooms_total));
        if ($bed_diff == 0) {
            $score += 5;
        } else {
            $score -= $bed_diff * 5;
        }

        // Clamp and grade
        $score = max(0, min(100, round($score, 1)));

        if ($score >= 85) {
            $grade = 'A';
        } elseif ($score >= 70) {
            $grade = 'B';
        } elseif ($score >= 55) {
            $grade = 'C';
        } elseif ($score >= 40) {
            $grade = 'D';
        } else {
            $grade = 'F';
        }

        return array('score' => $score, 'grade' => $grade);
    }

    /**
     * Calculate range quality based on spread relative to midpoint
     *
     * @param int $low Low end of range
     * @param int $high High end of range
     * @return string "tight", "moderate", "wide", or "unknown"
     */
    private static function calculate_range_quality($low, $high) {
        if ($low <= 0 || $high <= 0) {
            return 'unknown';
        }

        $mid = ($low + $high) / 2;
        $spread_pct = (($high - $low) / $mid) * 100;

        if ($spread_pct < 15) {
            return 'tight';
        }
        if ($spread_pct <= 30) {
            return 'moderate';
        }
        return 'wide';
    }

    /**
     * Calculate adjustments for a comparable property relative to the subject
     *
     * @param object $comp Comparable property object
     * @param object $subject Subject property object
     * @return array Array with 'items' containing adjustment details
     */
    private static function calculate_mobile_adjustments($comp, $subject) {
        $items = array();

        // Get comparable price for percentage-based calculations
        $comp_price = $comp->close_price > 0 ? (int) $comp->close_price : (int) $comp->list_price;

        // Square footage adjustment (using market-based $/sqft with diminishing returns)
        $comp_sqft = (int) $comp->building_area_total;
        $subject_sqft = (int) $subject->building_area_total;
        if ($comp_sqft > 0 && $subject_sqft > 0) {
            $sqft_diff = $comp_sqft - $subject_sqft;
            if (abs($sqft_diff) > 50) { // Only adjust if difference > 50 sqft
                // Use $350/sqft as default market rate
                $price_per_sqft = 350;
                $abs_diff = abs($sqft_diff);

                // Apply diminishing returns: first 200sqft at full value, then 75%, then 50%
                $tier1_sqft = min($abs_diff, 200);
                $tier2_sqft = max(0, min($abs_diff - 200, 300));
                $tier3_sqft = max(0, $abs_diff - 500);

                $tier1_value = $tier1_sqft * $price_per_sqft * 1.00;
                $tier2_value = $tier2_sqft * $price_per_sqft * 0.75;
                $tier3_value = $tier3_sqft * $price_per_sqft * 0.50;

                $adjustment = $tier1_value + $tier2_value + $tier3_value;

                // Cap at 10% of property value
                $max_adjustment = $comp_price * 0.10;
                $adjustment = min($adjustment, $max_adjustment);

                // Apply direction (negative if comp is larger)
                if ($sqft_diff > 0) {
                    $adjustment = -$adjustment;
                }

                $items[] = array(
                    'feature' => 'Square Footage',
                    'difference' => ($sqft_diff > 0 ? 'Larger' : 'Smaller') . ' by ' . abs($sqft_diff) . ' sqft',
                    'adjustment' => (int) round($adjustment),
                );
            }
        }

        // Bedroom adjustment (2.5% of property value per bedroom)
        $comp_beds = (int) $comp->bedrooms_total;
        $subject_beds = (int) $subject->bedrooms_total;
        if ($comp_beds > 0 && $subject_beds > 0) {
            $bed_diff = $comp_beds - $subject_beds;
            if ($bed_diff != 0) {
                $bed_value = $comp_price * 0.025; // 2.5% per bedroom
                $bed_value = max(15000, min(75000, $bed_value)); // Bound $15k-$75k
                $adjustment = -($bed_diff * $bed_value);

                $items[] = array(
                    'feature' => 'Bedrooms',
                    'difference' => abs($bed_diff) . ' ' . ($bed_diff > 0 ? 'more' : 'fewer') . ' bedroom' . (abs($bed_diff) > 1 ? 's' : ''),
                    'adjustment' => (int) round($adjustment),
                );
            }
        }

        // Bathroom adjustment (1% of property value per bathroom)
        $comp_baths = (float) $comp->bathrooms_total;
        $subject_baths = (float) $subject->bathrooms_total;
        if ($comp_baths > 0 && $subject_baths > 0) {
            $bath_diff = $comp_baths - $subject_baths;
            if (abs($bath_diff) >= 0.5) { // Only adjust if >= 0.5 bath difference
                $bath_value = $comp_price * 0.01; // 1% per bathroom
                $bath_value = max(5000, min(30000, $bath_value)); // Bound $5k-$30k
                $adjustment = -($bath_diff * $bath_value);

                $items[] = array(
                    'feature' => 'Bathrooms',
                    'difference' => abs($bath_diff) . ' ' . ($bath_diff > 0 ? 'more' : 'fewer') . ' bathroom' . (abs($bath_diff) > 1 ? 's' : ''),
                    'adjustment' => (int) round($adjustment),
                );
            }
        }

        // Year built adjustment (0.4% per year, capped at 20 years)
        $comp_year = !empty($comp->year_built) ? (int) $comp->year_built : 0;
        $subject_year = !empty($subject->year_built) ? (int) $subject->year_built : 0;
        if ($comp_year > 1800 && $subject_year > 1800) {
            $age_diff = $comp_year - $subject_year;
            if (abs($age_diff) >= 5) { // Only adjust if >= 5 years difference
                $years_to_adjust = min(abs($age_diff), 20); // Cap at 20 years
                $year_value = $comp_price * 0.004; // 0.4% per year
                $adjustment = $years_to_adjust * $year_value;

                // Apply direction (negative if comp is newer)
                if ($age_diff > 0) {
                    $adjustment = -$adjustment;
                }

                $items[] = array(
                    'feature' => 'Year Built',
                    'difference' => abs($age_diff) . ' years ' . ($age_diff > 0 ? 'newer' : 'older'),
                    'adjustment' => (int) round($adjustment),
                );
            }
        }

        // Garage spaces adjustment (2.5% first space, 1.5% additional)
        $comp_garage = isset($comp->garage_spaces) ? (int) $comp->garage_spaces : 0;
        $subject_garage = isset($subject->garage_spaces) ? (int) $subject->garage_spaces : 0;
        $garage_diff = $comp_garage - $subject_garage;
        if ($garage_diff != 0) {
            $spaces_to_adjust = abs($garage_diff);
            $garage_value = 0;

            for ($i = 0; $i < $spaces_to_adjust; $i++) {
                if ($i == 0) {
                    // First space: 2.5%, bounded $15k-$60k
                    $first_value = $comp_price * 0.025;
                    $first_value = max(15000, min(60000, $first_value));
                    $garage_value += $first_value;
                } else {
                    // Additional: 1.5%, bounded $10k-$40k
                    $add_value = $comp_price * 0.015;
                    $add_value = max(10000, min(40000, $add_value));
                    $garage_value += $add_value;
                }
            }

            // Apply direction (negative if comp has more)
            $adjustment = $garage_diff > 0 ? -$garage_value : $garage_value;

            $items[] = array(
                'feature' => 'Garage Spaces',
                'difference' => abs($garage_diff) . ' ' . ($garage_diff > 0 ? 'more' : 'fewer') . ' space' . (abs($garage_diff) > 1 ? 's' : ''),
                'adjustment' => (int) round($adjustment),
            );
        }

        return array('items' => $items);
    }

    /**
     * Handle get property CMA
     *
     * Implements tiered filtering with A/B grade requirement:
     * - Tier 1 (tight): ±10% price, ±15% sqft, exact beds
     * - Tier 2 (moderate): ±12% price, ±18% sqft, exact beds
     * - Tier 3 (relaxed): ±15% price, ±20% sqft, ±1 bed
     *
     * Returns max 5 comparables with A or B grades only.
     */
    public static function handle_get_property_cma($request) {
        global $wpdb;

        $listing_id = sanitize_text_field($request->get_param('listing_id'));

        $summary_table = $wpdb->prefix . 'bme_listing_summary';
        $archive_table = $wpdb->prefix . 'bme_listing_summary_archive';

        // Get the subject property by listing_key (check active table first, then archive)
        $subject = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$summary_table} WHERE listing_key = %s",
            $listing_id
        ));

        if (!$subject) {
            // Also check archive table for the subject property
            $subject = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$archive_table} WHERE listing_key = %s",
                $listing_id
            ));
        }

        if (!$subject) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'not_found',
                'message' => 'Property not found'
            ), 404);
        }

        // Define filter tiers: tight, moderate, relaxed
        $filter_tiers = array(
            'tight' => array(
                'sqft_pct' => 0.15,   // ±15% sqft
                'bed_diff' => 0,       // exact beds
            ),
            'moderate' => array(
                'sqft_pct' => 0.18,   // ±18% sqft
                'bed_diff' => 0,       // exact beds
            ),
            'relaxed' => array(
                'sqft_pct' => 0.20,   // ±20% sqft
                'bed_diff' => 1,       // ±1 bed
            ),
        );

        $min_ab_comps = 3;      // Minimum A/B grade comparables needed
        $max_comps = 5;         // Maximum comparables to return
        $filter_tier_used = null;
        $ab_comparables = array();

        // Try each tier until we get enough A/B grade comparables
        foreach ($filter_tiers as $tier_name => $tier) {
            $sqft_low = max(500, intval($subject->building_area_total * (1 - $tier['sqft_pct'])));
            $sqft_high = intval($subject->building_area_total * (1 + $tier['sqft_pct']));
            $bed_low = max(1, intval($subject->bedrooms_total) - $tier['bed_diff']);
            $bed_high = intval($subject->bedrooms_total) + $tier['bed_diff'];

            // Query up to 20 comparables for this tier
            $comparables = $wpdb->get_results($wpdb->prepare(
                "SELECT *,
                    (6371 * acos(cos(radians(%f)) * cos(radians(latitude)) *
                    cos(radians(longitude) - radians(%f)) +
                    sin(radians(%f)) * sin(radians(latitude)))) AS distance_miles
                 FROM {$archive_table}
                 WHERE listing_key != %s
                   AND standard_status = 'Closed'
                   AND property_type = %s
                   AND city = %s
                   AND bedrooms_total BETWEEN %d AND %d
                   AND building_area_total BETWEEN %d AND %d
                   AND close_date >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
                 ORDER BY distance_miles ASC
                 LIMIT 20",
                $subject->latitude,
                $subject->longitude,
                $subject->latitude,
                $listing_id,
                $subject->property_type,
                $subject->city,
                $bed_low,
                $bed_high,
                $sqft_low,
                $sqft_high
            ));

            // Calculate score/grade for each and filter to A/B only
            $ab_comparables = array();
            foreach ($comparables as $comp) {
                $score_data = self::calculate_mobile_comparability_score($comp, $subject);
                $comp->comparability_score = $score_data['score'];
                $comp->comparability_grade = $score_data['grade'];

                // Only keep A and B grades
                if ($score_data['grade'] === 'A' || $score_data['grade'] === 'B') {
                    $ab_comparables[] = $comp;
                }
            }

            // Sort by score descending
            usort($ab_comparables, function($a, $b) {
                return $b->comparability_score <=> $a->comparability_score;
            });

            // If we have enough A/B comps, use this tier
            if (count($ab_comparables) >= $min_ab_comps) {
                $filter_tier_used = $tier_name;
                break;
            }
        }

        // If no tier gave us enough comps, use whatever we have from the last (most relaxed) tier
        if ($filter_tier_used === null) {
            $filter_tier_used = 'relaxed';
        }

        // Take top 5 by score
        $ab_comparables = array_slice($ab_comparables, 0, $max_comps);

        // Calculate estimated value from A/B comparables only
        $prices = array();
        foreach ($ab_comparables as $comp) {
            $price = $comp->close_price > 0 ? $comp->close_price : $comp->list_price;
            if ($price > 0) {
                $prices[] = (int) $price;
            }
        }

        $estimated_value = null;
        $value_range = array('low' => null, 'high' => null);
        $confidence = null;
        $range_quality = 'unknown';

        if (count($prices) >= 3) {
            sort($prices);
            $estimated_value = round(array_sum($prices) / count($prices));
            $value_range = array(
                'low' => $prices[0],
                'high' => $prices[count($prices) - 1]
            );
            // Higher confidence when using only A/B grade comparables
            $confidence = min(95, 70 + (count($prices) * 5));
            $range_quality = self::calculate_range_quality($value_range['low'], $value_range['high']);
        }

        // Get market context
        $market_context = null;
        if (class_exists('MLD_Comparable_Sales')) {
            require_once plugin_dir_path(__FILE__) . 'class-mld-comparable-sales.php';
            $comp_sales = new MLD_Comparable_Sales();
            $market_context = $comp_sales->get_market_context($subject->city, $subject->state_or_province);
        }

        // Calculate average DOM from comparables
        $total_dom = 0;
        $dom_count = 0;
        foreach ($ab_comparables as $comp) {
            if (!empty($comp->days_on_market) && $comp->days_on_market > 0) {
                $total_dom += (int) $comp->days_on_market;
                $dom_count++;
            } elseif (!empty($comp->close_date) && !empty($comp->listing_contract_date)) {
                $dom = (int) ((strtotime($comp->close_date) - strtotime($comp->listing_contract_date)) / 86400);
                if ($dom > 0) {
                    $total_dom += $dom;
                    $dom_count++;
                }
            }
        }
        $avg_dom = $dom_count > 0 ? round($total_dom / $dom_count) : null;

        // Format comparables for response with adjustments
        $formatted_comps = array_map(function($comp) use ($subject) {
            $sold_price = $comp->close_price > 0 ? $comp->close_price : $comp->list_price;
            $address = trim($comp->street_number . ' ' . $comp->street_name);

            // Calculate adjustments for this comparable
            $adjustments = self::calculate_mobile_adjustments($comp, $subject);
            $total_adjustment = 0;
            foreach ($adjustments['items'] as $adj) {
                $total_adjustment += $adj['adjustment'];
            }
            $adjusted_price = (int) $sold_price + $total_adjustment;

            // Calculate gross and net adjustment percentages
            $gross_adjustment = 0;
            foreach ($adjustments['items'] as $adj) {
                $gross_adjustment += abs($adj['adjustment']);
            }
            $gross_pct = $sold_price > 0 ? round(($gross_adjustment / $sold_price) * 100, 1) : 0;
            $net_pct = $sold_price > 0 ? round((abs($total_adjustment) / $sold_price) * 100, 1) : 0;

            // Add warnings if adjustments are too large
            $warnings = array();
            if ($net_pct > 25) {
                $warnings[] = 'Net adjustment exceeds 25% - use with caution';
            }
            if ($gross_pct > 50) {
                $warnings[] = 'Gross adjustment exceeds 50% - significant differences';
            }

            return array(
                'listing_id' => $comp->listing_key,
                'address' => $address,
                'city' => $comp->city,
                'state' => $comp->state_or_province,
                'beds' => (int) $comp->bedrooms_total,
                'baths' => (float) $comp->bathrooms_total,
                'sqft' => (int) $comp->building_area_total,
                'year_built' => !empty($comp->year_built) ? (int) $comp->year_built : null,
                'garage_spaces' => isset($comp->garage_spaces) ? (int) $comp->garage_spaces : null,
                'sold_price' => (int) $sold_price,
                'adjusted_price' => $adjusted_price,
                'sold_date' => $comp->close_date ?: $comp->modification_timestamp,
                'distance_miles' => round((float) $comp->distance_miles, 2),
                'price_per_sqft' => $comp->building_area_total > 0 ? round($sold_price / $comp->building_area_total) : null,
                'photo_url' => $comp->main_photo_url,
                'comparability_score' => $comp->comparability_score,
                'comparability_grade' => $comp->comparability_grade,
                'adjustments' => array(
                    'items' => $adjustments['items'],
                    'total_adjustment' => $total_adjustment,
                    'gross_pct' => $gross_pct,
                    'net_pct' => $net_pct,
                    'warnings' => $warnings,
                ),
            );
        }, $ab_comparables);

        // Build subject address
        $subject_address = trim($subject->street_number . ' ' . $subject->street_name);

        return new WP_REST_Response(array(
            'success' => true,
            'data' => array(
                'subject' => array(
                    'listing_id' => $subject->listing_key,
                    'address' => $subject_address,
                    'city' => $subject->city,
                    'state' => $subject->state_or_province,
                    'beds' => (int) $subject->bedrooms_total,
                    'baths' => (float) $subject->bathrooms_total,
                    'sqft' => (int) $subject->building_area_total,
                    'year_built' => !empty($subject->year_built) ? (int) $subject->year_built : null,
                    'garage_spaces' => isset($subject->garage_spaces) ? (int) $subject->garage_spaces : null,
                    'list_price' => (int) $subject->list_price,
                ),
                'estimated_value' => $estimated_value,
                'value_range' => $value_range,
                'confidence_score' => $confidence,
                'range_quality' => $range_quality,
                'avg_dom' => $avg_dom,
                'filter_tier_used' => $filter_tier_used,
                'market_context' => $market_context,
                'comparables' => $formatted_comps,
                'comparables_count' => count($formatted_comps)
            )
        ), 200);
    }

    /**
     * Handle generate CMA PDF
     * POST /cma/generate-pdf
     */
    public static function handle_generate_cma_pdf($request) {
        global $wpdb;

        $params = $request->get_json_params();
        $listing_id = sanitize_text_field($params['listing_id'] ?? '');
        $prepared_for = sanitize_text_field($params['prepared_for'] ?? '');
        $selected_comparables = isset($params['selected_comparables']) && is_array($params['selected_comparables'])
            ? array_map('sanitize_text_field', $params['selected_comparables'])
            : null;

        // v6.75.1: Get subject condition and manual adjustments from iOS
        $subject_condition = sanitize_text_field($params['subject_condition'] ?? 'some_updates');
        $manual_adjustments = isset($params['manual_adjustments']) && is_array($params['manual_adjustments'])
            ? $params['manual_adjustments']
            : array();

        // Condition adjustment percentages (matches iOS CMACondition enum)
        $condition_percentages = array(
            'new_construction' => 0.20,
            'fully_renovated' => 0.12,
            'some_updates' => 0.0,
            'needs_updating' => -0.12,
            'distressed' => -0.30,
        );

        // Get subject condition percentage
        $subject_condition_pct = isset($condition_percentages[$subject_condition])
            ? $condition_percentages[$subject_condition]
            : 0.0;

        // Pool and waterfront adjustment amounts (matches iOS)
        $pool_adjustment = 50000;
        $waterfront_adjustment = 200000;

        if (empty($listing_id)) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'missing_listing_id',
                'message' => 'Listing ID is required'
            ), 400);
        }

        $summary_table = $wpdb->prefix . 'bme_listing_summary';
        $archive_table = $wpdb->prefix . 'bme_listing_summary_archive';

        // Get the subject property - try multiple lookup strategies:
        // 1. By listing_key (hash) in active summary table
        // 2. By listing_key (hash) in archive summary table
        // 3. By listing_id (MLS number) in active summary table
        // 4. By listing_id (MLS number) in archive summary table
        $subject = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$summary_table} WHERE listing_key = %s",
            $listing_id
        ));

        if (!$subject) {
            $subject = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$archive_table} WHERE listing_key = %s",
                $listing_id
            ));
        }

        // Fallback: Try looking up by listing_id (MLS number) in case iOS sent MLS# instead of hash
        if (!$subject) {
            $subject = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$summary_table} WHERE listing_id = %s",
                $listing_id
            ));
        }

        if (!$subject) {
            $subject = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$archive_table} WHERE listing_id = %s",
                $listing_id
            ));
        }

        if (!$subject) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'not_found',
                'message' => 'Property not found'
            ), 404);
        }

        // Find comparables from archive table
        // If specific comparables were selected, query them directly by listing_key
        // Otherwise, run a general search query based on property criteria
        if (!empty($selected_comparables)) {
            // Query the SPECIFIC selected comparables directly by their listing_keys
            // This ensures we get exactly what the user selected, not a different set
            $placeholders = implode(',', array_fill(0, count($selected_comparables), '%s'));
            $query_params = array_merge(
                array($subject->latitude, $subject->longitude, $subject->latitude),
                $selected_comparables
            );

            $comparables = $wpdb->get_results($wpdb->prepare(
                "SELECT *,
                    (6371 * acos(cos(radians(%f)) * cos(radians(latitude)) *
                    cos(radians(longitude) - radians(%f)) +
                    sin(radians(%f)) * sin(radians(latitude)))) AS distance_miles
                 FROM {$archive_table}
                 WHERE listing_key IN ({$placeholders})
                 ORDER BY distance_miles ASC",
                $query_params
            ));
        } else {
            // No selection provided - run general comparable search
            // Use $subject->listing_key to exclude the subject property
            $comparables = $wpdb->get_results($wpdb->prepare(
                "SELECT *,
                    (6371 * acos(cos(radians(%f)) * cos(radians(latitude)) *
                    cos(radians(longitude) - radians(%f)) +
                    sin(radians(%f)) * sin(radians(latitude)))) AS distance_miles
                 FROM {$archive_table}
                 WHERE listing_key != %s
                   AND standard_status = 'Closed'
                   AND property_type = %s
                   AND city = %s
                   AND bedrooms_total BETWEEN %d AND %d
                   AND building_area_total BETWEEN %d AND %d
                   AND close_date >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
                 ORDER BY distance_miles ASC
                 LIMIT 10",
                $subject->latitude,
                $subject->longitude,
                $subject->latitude,
                $subject->listing_key,
                $subject->property_type,
                $subject->city,
                max(1, $subject->bedrooms_total - 1),
                $subject->bedrooms_total + 1,
                max(500, $subject->building_area_total * 0.75),
                $subject->building_area_total * 1.25
            ));
        }

        // Calculate statistics from comparables for PDF generator
        $prices = array();
        $price_per_sqft_values = array();
        $dom_values = array();

        foreach ($comparables as $comp) {
            $price = $comp->close_price > 0 ? $comp->close_price : $comp->list_price;
            if ($price > 0) {
                $prices[] = (int) $price;

                // Calculate price per sqft
                if ($comp->building_area_total > 0) {
                    $price_per_sqft_values[] = $price / $comp->building_area_total;
                }
            }

            // Get days on market - prefer the stored value, fallback to calculation
            if (!empty($comp->days_on_market) && $comp->days_on_market > 0) {
                $dom_values[] = (int) $comp->days_on_market;
            } elseif (!empty($comp->close_date) && !empty($comp->listing_contract_date)) {
                // Fallback: calculate from listing_contract_date to close_date
                $list_ts = strtotime($comp->listing_contract_date);
                $close_ts = strtotime($comp->close_date);
                if ($list_ts && $close_ts && $close_ts > $list_ts) {
                    $dom_values[] = (int) (($close_ts - $list_ts) / 86400);
                }
            }
        }

        // Calculate value estimates
        $estimated_value = null;
        $value_low = null;
        $value_high = null;
        $confidence_level = 'insufficient';

        if (count($prices) >= 3) {
            sort($prices);
            $estimated_value = round(array_sum($prices) / count($prices));
            $value_low = $prices[0];
            $value_high = $prices[count($prices) - 1];

            // Confidence level based on number of comparables
            if (count($prices) >= 8) {
                $confidence_level = 'high';
            } elseif (count($prices) >= 5) {
                $confidence_level = 'medium';
            } else {
                $confidence_level = 'low';
            }
        }

        // Calculate median price
        $median_price = 0;
        if (count($prices) > 0) {
            $count = count($prices);
            $middle = floor($count / 2);
            if ($count % 2 == 0) {
                $median_price = ($prices[$middle - 1] + $prices[$middle]) / 2;
            } else {
                $median_price = $prices[$middle];
            }
        }

        // Calculate average DOM
        $avg_dom = count($dom_values) > 0 ? round(array_sum($dom_values) / count($dom_values)) : 0;

        // Calculate average price per sqft
        $avg_price_per_sqft = count($price_per_sqft_values) > 0
            ? round(array_sum($price_per_sqft_values) / count($price_per_sqft_values))
            : 0;

        // Build subject property data for PDF generator
        // NOTE: Field names must match what class-mld-cma-pdf-generator.php expects
        $subject_address = trim($subject->street_number . ' ' . $subject->street_name);
        $subject_property = array(
            'listing_id' => $subject->listing_id,
            'listing_key' => $subject->listing_key,
            'address' => $subject_address,
            'city' => $subject->city,
            'state' => $subject->state_or_province,
            'postal_code' => $subject->postal_code,  // PDF expects 'postal_code'
            'beds' => (int) $subject->bedrooms_total,
            'baths' => (float) $subject->bathrooms_total,
            'sqft' => (int) $subject->building_area_total,
            'price' => (int) $subject->list_price,  // PDF expects 'price' for display
            'list_price' => (int) $subject->list_price,  // Keep for backward compatibility
            'property_type' => $subject->property_type,
            'property_sub_type' => $subject->property_sub_type,
            'year_built' => $subject->year_built,
            'lot_size' => $subject->lot_size_acres,
            'garage_spaces' => (int) ($subject->garage_spaces ?? 0),
            'pool' => !empty($subject->has_pool) ? 1 : 0,
            'photo_url' => $subject->main_photo_url,
        );

        // Build CMA data for PDF generator - must match expected structure
        // Build comparables array with all fields required by PDF generator
        $formatted_comparables = array();
        foreach ($comparables as $comp) {
            $sold_price = $comp->close_price > 0 ? $comp->close_price : $comp->list_price;

            // Build full address
            $unparsed_address = trim($comp->street_number . ' ' . $comp->street_name);
            if (!empty($comp->unit_number)) {
                $unparsed_address .= ' #' . $comp->unit_number;
            }

            // Get DOM for this comparable - prefer stored value, fallback to calculation
            $dom = 0;
            if (!empty($comp->days_on_market) && $comp->days_on_market > 0) {
                $dom = (int) $comp->days_on_market;
            } elseif (!empty($comp->close_date) && !empty($comp->listing_contract_date)) {
                $list_ts = strtotime($comp->listing_contract_date);
                $close_ts = strtotime($comp->close_date);
                if ($list_ts && $close_ts && $close_ts > $list_ts) {
                    $dom = (int) (($close_ts - $list_ts) / 86400);
                }
            }

            // Calculate comparability grade based on similarity to subject
            // Grade factors: distance, sqft difference, bed difference, price difference
            $grade_score = 100;

            // Distance penalty: -5 points per 0.5 mile
            $distance = (float) $comp->distance_miles;
            $grade_score -= min(40, $distance * 10);

            // Sqft difference penalty: -5 points per 10% difference
            if ($subject->building_area_total > 0 && $comp->building_area_total > 0) {
                $sqft_diff_pct = abs($comp->building_area_total - $subject->building_area_total) / $subject->building_area_total * 100;
                $grade_score -= min(20, $sqft_diff_pct / 2);
            }

            // Bedroom difference penalty: -10 points per bedroom difference
            $bed_diff = abs($comp->bedrooms_total - $subject->bedrooms_total);
            $grade_score -= $bed_diff * 10;

            // Convert score to grade
            if ($grade_score >= 85) {
                $comparability_grade = 'A';
            } elseif ($grade_score >= 70) {
                $comparability_grade = 'B';
            } elseif ($grade_score >= 55) {
                $comparability_grade = 'C';
            } elseif ($grade_score >= 40) {
                $comparability_grade = 'D';
            } else {
                $comparability_grade = 'F';
            }

            // Build adjustments array with actual price adjustments (v6.75.1)
            $adjustments = array(
                'total_adjustment' => 0,
                'items' => array(),
            );

            // Get manual adjustment for this comparable (keyed by listing_key or listing_id)
            $comp_adjustment = null;
            if (isset($manual_adjustments[$comp->listing_key])) {
                $comp_adjustment = $manual_adjustments[$comp->listing_key];
            } elseif (isset($manual_adjustments[$comp->listing_id])) {
                $comp_adjustment = $manual_adjustments[$comp->listing_id];
            }

            // Start with sold price as base
            $adjusted_price = (int) $sold_price;

            // Apply condition adjustment if manual adjustment exists
            if ($comp_adjustment && !empty($comp_adjustment['condition'])) {
                $comp_condition = sanitize_text_field($comp_adjustment['condition']);
                $comp_condition_pct = isset($condition_percentages[$comp_condition])
                    ? $condition_percentages[$comp_condition]
                    : 0.0;

                // Relative adjustment: (subject - comp) × price
                // If comp is BETTER condition than subject → SUBTRACT from comp price
                // If comp is WORSE condition than subject → ADD to comp price
                $condition_adjustment = (int) round(($subject_condition_pct - $comp_condition_pct) * $sold_price);

                if ($condition_adjustment != 0) {
                    $adjusted_price += $condition_adjustment;
                    $adjustments['items'][] = array(
                        'feature' => 'Condition',
                        'adjustment' => $condition_adjustment,
                        'note' => ucwords(str_replace('_', ' ', $comp_condition))
                    );
                    $adjustments['total_adjustment'] += $condition_adjustment;
                }
            }

            // Apply pool adjustment if specified
            if ($comp_adjustment && isset($comp_adjustment['has_pool'])) {
                $comp_has_pool = (bool) $comp_adjustment['has_pool'];
                // Assume subject doesn't have pool by default (adjust if comp has pool)
                // If comp has pool and subject doesn't → subtract from comp price
                // If comp doesn't have pool and subject does → add to comp price
                if ($comp_has_pool) {
                    // Comp has pool - this makes comp more valuable, so subtract
                    $adjusted_price -= $pool_adjustment;
                    $adjustments['items'][] = array(
                        'feature' => 'Pool',
                        'adjustment' => -$pool_adjustment,
                        'note' => 'Comp has pool'
                    );
                    $adjustments['total_adjustment'] -= $pool_adjustment;
                }
            }

            // Apply waterfront adjustment if specified
            if ($comp_adjustment && isset($comp_adjustment['has_waterfront'])) {
                $comp_has_waterfront = (bool) $comp_adjustment['has_waterfront'];
                // If comp has waterfront → subtract from comp price
                if ($comp_has_waterfront) {
                    $adjusted_price -= $waterfront_adjustment;
                    $adjustments['items'][] = array(
                        'feature' => 'Waterfront',
                        'adjustment' => -$waterfront_adjustment,
                        'note' => 'Comp is waterfront'
                    );
                    $adjustments['total_adjustment'] -= $waterfront_adjustment;
                }
            }

            // Add sqft adjustment note if significant difference
            if ($subject->building_area_total > 0 && $comp->building_area_total > 0) {
                $sqft_diff = $comp->building_area_total - $subject->building_area_total;
                if (abs($sqft_diff) > 100) {
                    $adjustments['items'][] = array(
                        'feature' => 'Square Footage',
                        'adjustment' => 0, // No automatic price adjustment for sqft
                        'note' => ($sqft_diff > 0 ? '+' : '') . number_format($sqft_diff) . ' sqft'
                    );
                }
            }

            // Add bedroom adjustment note if different
            $bed_diff = $comp->bedrooms_total - $subject->bedrooms_total;
            if ($bed_diff != 0) {
                $adjustments['items'][] = array(
                    'feature' => 'Bedrooms',
                    'adjustment' => 0, // No automatic price adjustment for beds
                    'note' => ($bed_diff > 0 ? '+' : '') . $bed_diff . ' bed(s)'
                );
            }

            $formatted_comparables[] = array(
                // Fields used by PDF generator's comparables section
                'listing_id' => $comp->listing_id,
                'comparability_grade' => $comparability_grade,
                'unparsed_address' => $unparsed_address,
                'address' => $unparsed_address,
                'city' => $comp->city,
                'state' => $comp->state_or_province,
                'list_price' => (int) $comp->list_price,
                'adjusted_price' => $adjusted_price, // Now includes condition/pool/waterfront adjustments
                'bedrooms_total' => (int) $comp->bedrooms_total,
                'bathrooms_total' => (float) $comp->bathrooms_total,
                'building_area_total' => (int) $comp->building_area_total,
                'year_built' => $comp->year_built ?: 'N/A',
                'distance_miles' => round($distance, 2) . '',
                'standard_status' => $comp->standard_status ?: 'Closed',
                'days_on_market' => $dom,
                'adjustments' => $adjustments,
                // Additional fields for completeness
                'sold_price' => (int) $sold_price,
                'sold_date' => $comp->close_date ?: $comp->modification_timestamp,
                'price_per_sqft' => $comp->building_area_total > 0 ? round($sold_price / $comp->building_area_total) : null,
                'photo_url' => $comp->main_photo_url,
                'garage_spaces' => (int) ($comp->garage_spaces ?? 0),
                'lot_size_area' => $comp->lot_size_acres ?? null,
            );
        }

        // v6.75.1: Calculate adjusted value estimates using adjusted prices from comparables
        $adjusted_prices = array();
        foreach ($formatted_comparables as $fc) {
            if (isset($fc['adjusted_price']) && $fc['adjusted_price'] > 0) {
                $adjusted_prices[] = $fc['adjusted_price'];
            }
        }

        $adjusted_estimated_value = null;
        $adjusted_value_low = null;
        $adjusted_value_high = null;

        if (count($adjusted_prices) >= 3) {
            sort($adjusted_prices);
            $adjusted_estimated_value = round(array_sum($adjusted_prices) / count($adjusted_prices));
            $adjusted_value_low = $adjusted_prices[0];
            $adjusted_value_high = $adjusted_prices[count($adjusted_prices) - 1];
        }

        // The PDF generator expects $cma_data['summary'] with nested values
        $cma_data = array(
            'summary' => array(
                'estimated_value' => array(
                    'low' => $adjusted_value_low ?: $value_low,
                    'high' => $adjusted_value_high ?: $value_high,
                    'mid' => $adjusted_estimated_value ?: $estimated_value,
                    'confidence' => $confidence_level,
                ),
                'total_found' => count($comparables),
                'avg_price' => count($adjusted_prices) > 0 ? round(array_sum($adjusted_prices) / count($adjusted_prices)) : (count($prices) > 0 ? round(array_sum($prices) / count($prices)) : 0),
                'median_price' => $median_price,
                'avg_dom' => $avg_dom,
                'price_per_sqft' => array(
                    'avg' => $avg_price_per_sqft,
                ),
                // v6.75.1: Include subject condition info for PDF display
                'subject_condition' => $subject_condition,
                'subject_condition_label' => ucwords(str_replace('_', ' ', $subject_condition)),
            ),
            'comparables' => $formatted_comparables,
        );

        // Generate PDF
        $pdf_path = plugin_dir_path(__FILE__) . 'class-mld-cma-pdf-generator.php';
        if (!file_exists($pdf_path)) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'pdf_generator_missing',
                'message' => 'PDF generator not available'
            ), 500);
        }

        require_once $pdf_path;

        if (!class_exists('MLD_CMA_PDF_Generator')) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'pdf_generator_class_missing',
                'message' => 'PDF generator class not found'
            ), 500);
        }

        $pdf_generator = new MLD_CMA_PDF_Generator();

        $pdf_options = array(
            'prepared_for' => $prepared_for,
            'include_photos' => true,
            'include_forecast' => false,
            'include_investment' => false,
        );

        $generated_pdf_path = $pdf_generator->generate_report($cma_data, $subject_property, $pdf_options);

        if ($generated_pdf_path && file_exists($generated_pdf_path)) {
            // Generate download URL
            $upload_dir = wp_upload_dir();
            $pdf_url = str_replace($upload_dir['basedir'], $upload_dir['baseurl'], $generated_pdf_path);

            return new WP_REST_Response(array(
                'success' => true,
                'data' => array(
                    'pdf_url' => $pdf_url,
                    'generated_at' => current_time('c'),
                )
            ), 200);
        } else {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'pdf_generation_failed',
                'message' => 'Failed to generate PDF. Please try again.'
            ), 500);
        }
    }

    /**
     * Handle analyze property condition
     * POST /cma/analyze-condition
     *
     * Uses Claude Vision API to analyze property photos and suggest condition rating.
     *
     * @since 6.75.0
     */
    public static function handle_analyze_condition($request) {
        // Load the condition analyzer
        require_once dirname(__FILE__) . '/class-mld-condition-analyzer.php';

        // Check if analyzer is available
        if (!MLD_Condition_Analyzer::is_available()) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'analyzer_unavailable',
                'message' => 'AI condition analyzer is not configured. Please contact support.'
            ), 503);
        }

        $params = $request->get_json_params();
        $listing_id = sanitize_text_field($params['listing_id'] ?? '');
        $photo_urls = isset($params['photo_urls']) && is_array($params['photo_urls'])
            ? array_map('esc_url_raw', $params['photo_urls'])
            : array();
        $force_refresh = isset($params['force_refresh']) && $params['force_refresh'] === true;

        // Validate required fields
        if (empty($listing_id)) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'missing_listing_id',
                'message' => 'Listing ID is required'
            ), 400);
        }

        if (empty($photo_urls)) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'missing_photo_urls',
                'message' => 'At least one photo URL is required'
            ), 400);
        }

        // Call the analyzer
        $result = MLD_Condition_Analyzer::analyze($listing_id, $photo_urls, $force_refresh);

        if (!$result['success']) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'analysis_failed',
                'message' => $result['error'] ?? 'Failed to analyze property condition'
            ), 500);
        }

        return new WP_REST_Response(array(
            'success' => true,
            'data' => $result['data']
        ), 200);
    }

    // ============ Chatbot Handler ============

    /**
     * Handle chatbot message
     */
    public static function handle_chatbot_message($request) {
        $params = $request->get_json_params();
        $message = isset($params['message']) ? sanitize_text_field($params['message']) : '';
        $context = isset($params['context']) ? $params['context'] : array();

        if (empty($message)) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'empty_message',
                'message' => 'Message is required'
            ), 400);
        }

        // Use the existing chatbot if available
        if (class_exists('MLD_Chatbot_Init')) {
            $response = MLD_Chatbot_Init::process_message($message, $context);

            return new WP_REST_Response(array(
                'success' => true,
                'data' => $response
            ), 200);
        }

        // Fallback response
        return new WP_REST_Response(array(
            'success' => true,
            'data' => array(
                'message' => "I'm sorry, the AI assistant is not available right now. Please try again later or contact support.",
                'type' => 'error'
            )
        ), 200);
    }

    // ============ Price Distribution Handler ============

    /**
     * Handle get price distribution for histogram
     * Returns price buckets with counts for visualization
     */
    public static function handle_get_price_distribution($request) {
        global $wpdb;

        // Get filter parameters (excluding price filters for dynamic range)
        $property_type = $request->get_param('property_type');
        $beds = absint($request->get_param('beds'));
        $baths = $request->get_param('baths');
        $city = sanitize_text_field($request->get_param('city'));

        $table = $wpdb->prefix . 'bme_listing_summary';

        // Build WHERE clause
        $where = array("standard_status = 'Active'");
        $params = array();

        // Apply property type filter
        if (!empty($property_type)) {
            if (is_array($property_type)) {
                $placeholders = array_fill(0, count($property_type), '%s');
                $where[] = "property_type IN (" . implode(',', $placeholders) . ")";
                foreach ($property_type as $pt) {
                    $params[] = sanitize_text_field($pt);
                }
            } else {
                $types = array_map('trim', explode(',', $property_type));
                if (count($types) > 1) {
                    $placeholders = array_fill(0, count($types), '%s');
                    $where[] = "property_type IN (" . implode(',', $placeholders) . ")";
                    foreach ($types as $pt) {
                        $params[] = sanitize_text_field($pt);
                    }
                } else {
                    $where[] = "property_type = %s";
                    $params[] = sanitize_text_field($property_type);
                }
            }
        }

        if ($beds > 0) {
            $where[] = "bedrooms_total >= %d";
            $params[] = $beds;
        }

        if (!empty($baths)) {
            $where[] = "bathrooms_total >= %f";
            $params[] = floatval($baths);
        }

        if (!empty($city)) {
            $where[] = "city = %s";
            $params[] = $city;
        }

        $where_sql = implode(' AND ', $where);

        // Get all prices for distribution
        $sql = "SELECT list_price FROM {$table} WHERE {$where_sql} AND list_price > 0 ORDER BY list_price";
        if (!empty($params)) {
            $sql = $wpdb->prepare($sql, $params);
        }

        $prices = $wpdb->get_col($sql);

        if (empty($prices)) {
            return new WP_REST_Response(array(
                'success' => true,
                'data' => array(
                    'min' => 0,
                    'max' => 0,
                    'display_max' => 0,
                    'distribution' => array_fill(0, 20, 0),
                    'outlier_count' => 0
                )
            ), 200);
        }

        // Calculate statistics
        $min_price = min($prices);
        $max_price = max($prices);
        $count = count($prices);

        // Calculate 95th percentile for display_max (handles outliers)
        $percentile_index = (int) floor($count * 0.95);
        $display_max = $prices[$percentile_index] ?? $max_price;

        // Create 20 buckets
        $num_buckets = 20;
        $bucket_size = ($display_max - $min_price) / $num_buckets;
        $distribution = array_fill(0, $num_buckets, 0);
        $outlier_count = 0;

        foreach ($prices as $price) {
            if ($price > $display_max) {
                $outlier_count++;
            } else {
                $bucket_index = (int) floor(($price - $min_price) / $bucket_size);
                $bucket_index = min($bucket_index, $num_buckets - 1);
                $distribution[$bucket_index]++;
            }
        }

        return new WP_REST_Response(array(
            'success' => true,
            'data' => array(
                'min' => (int) $min_price,
                'max' => (int) $max_price,
                'display_max' => (int) $display_max,
                'distribution' => $distribution,
                'outlier_count' => $outlier_count,
                'total_count' => $count
            )
        ), 200);
    }

    // ============ Autocomplete Handler ============

    /**
     * Handle autocomplete suggestions
     * Returns matching cities, neighborhoods, addresses, street names, and MLS numbers
     *
     * Queries both bme_listing_summary (for city, zip, mls) and
     * bme_listing_location (for neighborhoods, addresses, streets)
     */
    public static function handle_autocomplete($request) {
        // v6.54.4: Check rate limit before processing
        $rate_limited = self::check_public_rate_limit('autocomplete');
        if ($rate_limited !== false) {
            return $rate_limited;
        }

        global $wpdb;

        $term = sanitize_text_field($request->get_param('term'));

        if (strlen($term) < 2) {
            return new WP_REST_Response(array(
                'success' => true,
                'data' => array()
            ), 200);
        }

        $suggestions = array();
        $search_term = '%' . $wpdb->esc_like($term) . '%';
        $starts_with = $wpdb->esc_like($term) . '%';

        $summary_table = $wpdb->prefix . 'bme_listing_summary';
        $location_table = $wpdb->prefix . 'bme_listing_location';

        // 1. City suggestions (from summary table)
        // v6.68.9: Removed standard_status filter to include Pending, Active Under Contract, and Closed properties
        $cities = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT city FROM {$summary_table}
             WHERE city LIKE %s
             ORDER BY city LIMIT 5",
            $starts_with
        ));
        foreach ($cities as $city) {
            $suggestions[] = array(
                'value' => $city,
                'type' => 'City',
                'icon' => 'building.2.fill'
            );
        }

        // 2. Postal Code suggestions (from summary table)
        // v6.68.9: Removed standard_status filter to include Pending, Active Under Contract, and Closed properties
        if (preg_match('/^\d+$/', $term)) {
            $zips = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT postal_code FROM {$summary_table}
                 WHERE postal_code LIKE %s
                 ORDER BY postal_code LIMIT 5",
                $starts_with
            ));
            foreach ($zips as $zip) {
                $suggestions[] = array(
                    'value' => $zip,
                    'type' => 'ZIP Code',
                    'icon' => 'mappin.circle.fill'
                );
            }
        }

        // 3. Neighborhood suggestions (from location table - subdivision_name, mls_area_major, mls_area_minor)
        // v6.49.7 - Search all 3 neighborhood-related columns for better coverage
        // v6.68.9: Removed standard_status filter to include Pending, Active Under Contract, and Closed properties
        $neighborhoods = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT neighborhood FROM (
                SELECT l.subdivision_name AS neighborhood
                FROM {$location_table} l
                INNER JOIN {$summary_table} s ON l.listing_id = s.listing_id
                WHERE l.subdivision_name LIKE %s
                  AND l.subdivision_name != ''
                  AND l.subdivision_name IS NOT NULL
                UNION
                SELECT l.mls_area_major AS neighborhood
                FROM {$location_table} l
                INNER JOIN {$summary_table} s ON l.listing_id = s.listing_id
                WHERE l.mls_area_major LIKE %s
                  AND l.mls_area_major != ''
                  AND l.mls_area_major IS NOT NULL
                UNION
                SELECT l.mls_area_minor AS neighborhood
                FROM {$location_table} l
                INNER JOIN {$summary_table} s ON l.listing_id = s.listing_id
                WHERE l.mls_area_minor LIKE %s
                  AND l.mls_area_minor != ''
                  AND l.mls_area_minor IS NOT NULL
            ) AS all_neighborhoods
            ORDER BY neighborhood LIMIT 5",
            $search_term,
            $search_term,
            $search_term
        ));
        foreach ($neighborhoods as $neighborhood) {
            $suggestions[] = array(
                'value' => $neighborhood,
                'type' => 'Neighborhood',
                'icon' => 'map.fill'
            );
        }

        // 4. Street Name suggestions (from location table)
        // v6.68.9: Removed standard_status filter to include Pending, Active Under Contract, and Closed properties
        $street_names = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT l.street_name
             FROM {$location_table} l
             INNER JOIN {$summary_table} s ON l.listing_id = s.listing_id
             WHERE l.street_name LIKE %s
               AND l.street_name != ''
               AND l.street_name IS NOT NULL
             ORDER BY l.street_name LIMIT 5",
            $starts_with
        ));
        foreach ($street_names as $street) {
            $suggestions[] = array(
                'value' => $street,
                'type' => 'Street Name',
                'icon' => 'road.lanes'
            );
        }

        // 5. Street Address suggestions (from location table)
        // v6.68.3: Search BOTH active and archive tables for addresses
        // v6.68.6: Include listing_id so iOS can navigate directly to property detail
        // v6.68.9: Fixed archive query - summary_archive has address fields directly, no join needed
        // v6.68.10: Handle unit number searches - users type "135 Seaport 1807" but DB has "135 Seaport # 1807"
        // Users should be able to find any property by address regardless of status
        $archive_table_addr = $wpdb->prefix . 'bme_listing_summary_archive';

        // Create alternate search term for unit numbers: "135 Seaport 1807" -> "135 Seaport% # 1807"
        // Detect pattern: street address followed by space and numbers at the end
        // Use wildcard after street name to handle "Seaport" matching "Seaport Blvd" or "Seaport Boulevard"
        // v6.68.11: Also handle when user types # directly (e.g., "135 Seaport #1807" or "135 Seaport Blvd #1807")
        $address_search_term = $search_term;
        $unit_search_term = null;

        // Pattern 1: User typed # (with optional spaces around it): "135 Seaport #1807" or "135 Seaport Blvd # 1807"
        if (preg_match('/^(.+?)\s*#\s*(\d+)$/', $term, $matches)) {
            // User searched with # sign - normalize to "% # unit%" pattern
            $unit_search_term = '%' . $wpdb->esc_like(trim($matches[1])) . '% # ' . $wpdb->esc_like($matches[2]) . '%';
        }
        // Pattern 2: User typed space + digits at end (no #): "135 Seaport 1807"
        elseif (preg_match('/^(.+)\s+(\d+)$/', $term, $matches)) {
            // User searched "135 Seaport 1807", search for "135 Seaport% # 1807%" to match "135 Seaport Blvd # 1807"
            $unit_search_term = '%' . $wpdb->esc_like($matches[1]) . '% # ' . $wpdb->esc_like($matches[2]) . '%';
        }

        // Build query with optional unit number pattern
        if ($unit_search_term) {
            $addresses = $wpdb->get_results($wpdb->prepare(
                "SELECT DISTINCT unparsed_address, street_number, street_name, city, listing_id, listing_key FROM (
                    SELECT l.unparsed_address, l.street_number, l.street_name, l.city, s.listing_id, s.listing_key
                    FROM {$location_table} l
                    INNER JOIN {$summary_table} s ON l.listing_id = s.listing_id
                    WHERE (l.unparsed_address LIKE %s OR l.unparsed_address LIKE %s)
                      AND l.unparsed_address IS NOT NULL
                    UNION
                    SELECT a.unparsed_address, a.street_number, a.street_name, a.city, a.listing_id, a.listing_key
                    FROM {$archive_table_addr} a
                    WHERE (a.unparsed_address LIKE %s OR a.unparsed_address LIKE %s)
                      AND a.unparsed_address IS NOT NULL
                ) AS combined_addresses
                ORDER BY unparsed_address LIMIT 5",
                $address_search_term,
                $unit_search_term,
                $address_search_term,
                $unit_search_term
            ));
        } else {
            $addresses = $wpdb->get_results($wpdb->prepare(
                "SELECT DISTINCT unparsed_address, street_number, street_name, city, listing_id, listing_key FROM (
                    SELECT l.unparsed_address, l.street_number, l.street_name, l.city, s.listing_id, s.listing_key
                    FROM {$location_table} l
                    INNER JOIN {$summary_table} s ON l.listing_id = s.listing_id
                    WHERE l.unparsed_address LIKE %s
                      AND l.unparsed_address IS NOT NULL
                    UNION
                    SELECT a.unparsed_address, a.street_number, a.street_name, a.city, a.listing_id, a.listing_key
                    FROM {$archive_table_addr} a
                    WHERE a.unparsed_address LIKE %s
                      AND a.unparsed_address IS NOT NULL
                ) AS combined_addresses
                ORDER BY unparsed_address LIMIT 5",
                $address_search_term,
                $address_search_term
            ));
        }
        foreach ($addresses as $addr) {
            $suggestions[] = array(
                'value' => $addr->unparsed_address,
                'type' => 'Address',
                'icon' => 'house.fill',
                'subtitle' => $addr->city,
                'listing_id' => $addr->listing_id,      // MLS number for direct navigation
                'listing_key' => $addr->listing_key    // Hash for property detail API
            );
        }

        // 6. MLS Number suggestions (from summary table)
        // v6.68.3: Search BOTH active and archive tables for MLS numbers
        // v6.68.6: Include listing_key so iOS can navigate directly to property detail
        // Users should be able to find any property by MLS number regardless of status
        if (strlen($term) >= 4) {
            $archive_table = $wpdb->prefix . 'bme_listing_summary_archive';
            $mls_results = $wpdb->get_results($wpdb->prepare(
                "SELECT DISTINCT listing_id, listing_key FROM (
                    SELECT listing_id, listing_key FROM {$summary_table} WHERE listing_id LIKE %s
                    UNION
                    SELECT listing_id, listing_key FROM {$archive_table} WHERE listing_id LIKE %s
                ) AS combined_mls
                ORDER BY listing_id LIMIT 5",
                $search_term,
                $search_term
            ));
            foreach ($mls_results as $mls) {
                $suggestions[] = array(
                    'value' => $mls->listing_id,
                    'type' => 'MLS Number',
                    'icon' => 'number.circle.fill',
                    'listing_id' => $mls->listing_id,      // MLS number for direct navigation
                    'listing_key' => $mls->listing_key    // Hash for property detail API
                );
            }
        }

        // Limit total results
        $suggestions = array_slice($suggestions, 0, 15);

        return new WP_REST_Response(array(
            'success' => true,
            'data' => $suggestions
        ), 200);
    }

    /**
     * Handle get property history
     * Returns price changes, status changes for a property
     *
     * @since 6.27.21
     */
    public static function handle_get_property_history($request) {
        // v6.54.4: Check rate limit before processing
        $rate_limited = self::check_public_rate_limit('property_detail');
        if ($rate_limited !== false) {
            return $rate_limited;
        }

        global $wpdb;

        $listing_id = sanitize_text_field($request->get_param('id'));

        $summary_table = $wpdb->prefix . 'bme_listing_summary';
        $archive_table = $wpdb->prefix . 'bme_listing_summary_archive';

        // Try to find the listing in summary table first
        $listing = $wpdb->get_row($wpdb->prepare(
            "SELECT listing_id, listing_key, list_price, original_list_price,
                    listing_contract_date, close_price, close_date, standard_status,
                    days_on_market
             FROM {$summary_table}
             WHERE listing_key = %s",
            $listing_id
        ));

        // If not found in summary, check archive tables
        if (!$listing) {
            $listing = $wpdb->get_row($wpdb->prepare(
                "SELECT listing_id, listing_key, list_price, original_list_price,
                        listing_contract_date, close_price, close_date, standard_status,
                        days_on_market
                 FROM {$archive_table}
                 WHERE listing_key = %s",
                $listing_id
            ));
        }

        if (!$listing) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'not_found',
                'message' => 'Property not found'
            ), 404);
        }

        // v6.67.1: Try to get tracked history from bme_property_history table first
        $tracked_history = MLD_Query::get_tracked_property_history($listing->listing_id);
        $events = array();
        $price_changes_count = 0;
        $status_changes_count = 0;
        $listing_timestamp = null; // For granular time on market
        $listing_timestamp_is_utc = false; // v6.67.3: Track if timestamp is from tracked history (UTC)

        if (!empty($tracked_history)) {
            // Use tracked history (most accurate, includes status changes)
            // v6.67.2: Deduplicate events by (event_type, date, price) to avoid showing duplicates
            $seen_events = array();

            foreach ($tracked_history as $tracked_event) {
                // Create a unique key for deduplication
                $dedup_key = sprintf(
                    '%s|%s|%s',
                    $tracked_event['event_type'],
                    substr($tracked_event['event_date'] ?? '', 0, 10), // Date only (YYYY-MM-DD)
                    $tracked_event['new_price'] ?? '0'
                );

                // Skip if we've already seen this event
                if (isset($seen_events[$dedup_key])) {
                    continue;
                }
                $seen_events[$dedup_key] = true;

                $event_data = self::transform_tracked_event_to_ios($tracked_event, $listing);
                if ($event_data) {
                    $events[] = $event_data;

                    // Count metrics
                    if ($tracked_event['event_type'] === 'price_change') {
                        $price_changes_count++;
                    }
                    if ($tracked_event['event_type'] === 'status_change') {
                        $status_changes_count++;
                    }

                    // Capture the earliest listing timestamp for granular time
                    if ($tracked_event['event_type'] === 'new_listing' && !empty($tracked_event['event_date'])) {
                        if (!$listing_timestamp || strtotime($tracked_event['event_date']) < strtotime($listing_timestamp)) {
                            $listing_timestamp = $tracked_event['event_date'];
                            $listing_timestamp_is_utc = true; // v6.67.3: Tracked history stores dates in UTC
                        }
                    }
                }
            }
        } else {
            // Fallback: Build history events from summary data (basic approach)
            // 1. Listed event (original listing date)
            if (!empty($listing->listing_contract_date)) {
                $events[] = array(
                    'date' => $listing->listing_contract_date,
                    'event' => 'Listed',
                    'event_type' => 'new_listing',
                    'price' => $listing->original_list_price
                        ? (int) $listing->original_list_price
                        : (int) $listing->list_price,
                    'change' => null,
                    'old_status' => null,
                    'new_status' => 'Active',
                    'days_on_market' => null,
                    'agent_name' => null,
                    'office_name' => null,
                    'price_per_sqft' => null,
                    'details' => 'Original listing',
                );
            }

            // 2. Price reduced event (if original > current)
            if (!empty($listing->original_list_price) &&
                (int) $listing->original_list_price > (int) $listing->list_price) {
                $change = (int) $listing->list_price - (int) $listing->original_list_price;
                $change_percent = round(($change / (int) $listing->original_list_price) * 100, 1);
                $events[] = array(
                    'date' => null, // We don't have the exact date of price change
                    'event' => 'Price Reduced',
                    'event_type' => 'price_change',
                    'price' => (int) $listing->list_price,
                    'change' => $change,
                    'old_status' => null,
                    'new_status' => null,
                    'days_on_market' => null,
                    'agent_name' => null,
                    'office_name' => null,
                    'price_per_sqft' => null,
                    'details' => sprintf('From $%s to $%s (%s%.1f%%)',
                        number_format((int) $listing->original_list_price),
                        number_format((int) $listing->list_price),
                        $change > 0 ? '+' : '',
                        $change_percent
                    ),
                );
                $price_changes_count++;
            }

            // 3. Sold/Closed event
            if ($listing->standard_status === 'Closed' && !empty($listing->close_date)) {
                $change = !empty($listing->list_price)
                    ? (int) $listing->close_price - (int) $listing->list_price
                    : null;
                $events[] = array(
                    'date' => $listing->close_date,
                    'event' => 'Sold',
                    'event_type' => 'sold',
                    'price' => (int) $listing->close_price,
                    'change' => $change,
                    'old_status' => 'Pending',
                    'new_status' => 'Closed',
                    'days_on_market' => (int) $listing->days_on_market,
                    'agent_name' => null,
                    'office_name' => null,
                    'price_per_sqft' => null,
                    'details' => 'Property sold',
                );
            }
        }

        // Sort by date (most recent first)
        usort($events, function($a, $b) {
            if (empty($a['date'])) return 1;
            if (empty($b['date'])) return -1;
            return strtotime($b['date']) - strtotime($a['date']);
        });

        // Calculate summary statistics
        $original_price = $listing->original_list_price
            ? (int) $listing->original_list_price
            : (int) $listing->list_price;
        $final_price = ($listing->standard_status === 'Closed' && !empty($listing->close_price))
            ? (int) $listing->close_price
            : (int) $listing->list_price;

        // v6.67.1: Calculate additional market insights
        $highest_price = $original_price;
        $lowest_price = $original_price;
        foreach ($events as $event) {
            if (!empty($event['price'])) {
                $highest_price = max($highest_price, $event['price']);
                $lowest_price = min($lowest_price, $event['price']);
            }
        }
        $price_range_percent = $highest_price > 0 && $highest_price != $lowest_price
            ? round((($highest_price - $lowest_price) / $highest_price) * 100, 1)
            : 0;

        // v6.67.2: Use listing_contract_date as fallback for listing_timestamp
        if (!$listing_timestamp && !empty($listing->listing_contract_date)) {
            $listing_timestamp = $listing->listing_contract_date;
        }

        // v6.67.2: Calculate granular time on market (hours, minutes) for recent listings
        $hours_on_market = null;
        $minutes_on_market = null;
        $time_on_market_text = null;

        if ($listing_timestamp && $listing->standard_status === 'Active') {
            // v6.67.3: Fix timezone calculation
            // listing_timestamp from tracked history is stored in UTC
            // listing_timestamp from summary table (fallback) is stored in WP timezone
            // DateTime->getTimestamp() returns real Unix timestamp (UTC-based)
            // IMPORTANT: Use time() not current_time('timestamp') - the latter returns a fake adjusted timestamp
            if ($listing_timestamp_is_utc) {
                // Tracked history: stored in UTC
                $utc_tz = new DateTimeZone('UTC');
                $listing_time = (new DateTime($listing_timestamp, $utc_tz))->getTimestamp();
            } else {
                // Fallback: stored in WP timezone (America/New_York)
                $listing_time = (new DateTime($listing_timestamp, wp_timezone()))->getTimestamp();
            }
            $now = time();  // Real Unix timestamp (UTC-based)
            $diff_seconds = $now - $listing_time;

            if ($diff_seconds > 0) {
                $total_minutes = floor($diff_seconds / 60);
                $total_hours = floor($diff_seconds / 3600);
                $hours_on_market = $total_hours;
                $minutes_on_market = $total_minutes;

                // Generate human-readable time text
                if ($total_hours < 1) {
                    $time_on_market_text = $total_minutes . ' minute' . ($total_minutes != 1 ? 's' : '');
                } elseif ($total_hours < 24) {
                    $remaining_minutes = $total_minutes - ($total_hours * 60);
                    if ($remaining_minutes > 0) {
                        $time_on_market_text = $total_hours . ' hour' . ($total_hours != 1 ? 's' : '') .
                            ', ' . $remaining_minutes . ' min';
                    } else {
                        $time_on_market_text = $total_hours . ' hour' . ($total_hours != 1 ? 's' : '');
                    }
                } elseif ($total_hours < 48) {
                    $remaining_hours = $total_hours % 24;
                    $time_on_market_text = '1 day' . ($remaining_hours > 0 ? ', ' . $remaining_hours . ' hours' : '');
                } elseif ($total_hours < 168) { // Less than 1 week
                    $days = floor($total_hours / 24);
                    $remaining_hours = $total_hours % 24;
                    $time_on_market_text = $days . ' day' . ($days != 1 ? 's' : '') .
                        ($remaining_hours > 0 ? ', ' . $remaining_hours . ' hr' . ($remaining_hours != 1 ? 's' : '') : '');
                }
            }
        }

        $result = array(
            'listing_id' => $listing->listing_key,
            'mls_number' => $listing->listing_id,
            'current_status' => $listing->standard_status,
            'days_on_market' => (int) $listing->days_on_market,
            'original_price' => $original_price,
            'final_price' => $final_price,
            'total_price_change' => $final_price - $original_price,
            'total_price_change_percent' => $original_price > 0
                ? round(($final_price - $original_price) / $original_price * 100, 1)
                : null,
            // v6.67.1: Enhanced market insights
            'listing_contract_date' => $listing->listing_contract_date,
            'price_changes_count' => $price_changes_count,
            'status_changes_count' => $status_changes_count,
            'price_range_percent' => $price_range_percent,
            'has_tracked_history' => !empty($tracked_history),
            // v6.67.2: Granular time on market
            'listing_timestamp' => $listing_timestamp,
            'hours_on_market' => $hours_on_market,
            'minutes_on_market' => $minutes_on_market,
            'time_on_market_text' => $time_on_market_text,
            'events' => $events,
        );

        return new WP_REST_Response(array(
            'success' => true,
            'data' => $result
        ), 200);
    }

    /**
     * Transform a tracked history event to iOS-compatible format
     *
     * @since 6.67.1
     * @param array $tracked_event Event from bme_property_history table
     * @param object $listing Listing data from summary table
     * @return array|null Transformed event or null if should be skipped
     */
    private static function transform_tracked_event_to_ios($tracked_event, $listing) {
        $event_type = $tracked_event['event_type'];
        // v6.67.3: Convert event_date from UTC (database timezone) to ISO8601 with WP timezone
        // The bme_property_history table stores dates in server/UTC timezone
        $event_date = self::format_utc_to_local_iso8601($tracked_event['event_date']);
        $new_price = !empty($tracked_event['new_price']) ? (int) $tracked_event['new_price'] : null;
        $old_price = !empty($tracked_event['old_price']) ? (int) $tracked_event['old_price'] : null;
        $change = ($new_price && $old_price) ? ($new_price - $old_price) : null;

        // Parse additional_data JSON if present
        $additional = !empty($tracked_event['additional_data'])
            ? json_decode($tracked_event['additional_data'], true)
            : array();

        switch ($event_type) {
            case 'new_listing':
                return array(
                    'date' => $event_date,
                    'event' => 'Listed for Sale',
                    'event_type' => 'new_listing',
                    'price' => $new_price,
                    'change' => null,
                    'old_status' => null,
                    'new_status' => 'Active',
                    'days_on_market' => null,
                    'agent_name' => $tracked_event['agent_name'],
                    'office_name' => $tracked_event['office_name'],
                    'price_per_sqft' => !empty($tracked_event['price_per_sqft']) ? (int) $tracked_event['price_per_sqft'] : null,
                    'details' => 'Original listing',
                );

            case 'price_change':
                $change_percent = $old_price > 0 ? round(($change / $old_price) * 100, 1) : 0;
                $event_name = $change < 0 ? 'Price Reduced' : 'Price Increased';
                return array(
                    'date' => $event_date,
                    'event' => $event_name,
                    'event_type' => 'price_change',
                    'price' => $new_price,
                    'change' => $change,
                    'old_status' => null,
                    'new_status' => null,
                    'days_on_market' => null,
                    'agent_name' => null,
                    'office_name' => null,
                    'price_per_sqft' => !empty($tracked_event['price_per_sqft']) ? (int) $tracked_event['price_per_sqft'] : null,
                    'details' => sprintf('From $%s to $%s (%s%.1f%%)',
                        number_format($old_price),
                        number_format($new_price),
                        $change > 0 ? '+' : '',
                        $change_percent
                    ),
                );

            case 'status_change':
                $old_status = $tracked_event['old_status'];
                $new_status = $tracked_event['new_status'];
                return array(
                    'date' => $event_date,
                    'event' => 'Status: ' . ucfirst(strtolower($new_status)),
                    'event_type' => 'status_change',
                    'price' => $new_price,
                    'change' => null,
                    'old_status' => $old_status,
                    'new_status' => $new_status,
                    'days_on_market' => !empty($tracked_event['days_on_market']) ? (int) $tracked_event['days_on_market'] : null,
                    'agent_name' => null,
                    'office_name' => null,
                    'price_per_sqft' => null,
                    'details' => $old_status . ' → ' . $new_status,
                );

            case 'pending':
                // v6.67.3: Use actual old_status from database (could be Active, Active Under Contract, etc.)
                $actual_old_status = $tracked_event['old_status'] ?: 'Active';
                return array(
                    'date' => $event_date,
                    'event' => 'Pending Sale',
                    'event_type' => 'pending',
                    'price' => $new_price,
                    'change' => null,
                    'old_status' => $actual_old_status,
                    'new_status' => 'Pending',
                    'days_on_market' => !empty($tracked_event['days_on_market']) ? (int) $tracked_event['days_on_market'] : null,
                    'agent_name' => null,
                    'office_name' => null,
                    'price_per_sqft' => null,
                    'details' => $actual_old_status . ' → Pending',
                );

            case 'sold':
                // v6.67.3: Use actual old_status from database
                $sold_old_status = $tracked_event['old_status'] ?: 'Pending';
                return array(
                    'date' => $event_date,
                    'event' => 'Sold',
                    'event_type' => 'sold',
                    'price' => $new_price ?: $old_price,
                    'change' => $change,
                    'old_status' => $sold_old_status,
                    'new_status' => 'Closed',
                    'days_on_market' => !empty($tracked_event['days_on_market']) ? (int) $tracked_event['days_on_market'] : null,
                    'agent_name' => null,
                    'office_name' => null,
                    'price_per_sqft' => null,
                    'details' => $sold_old_status . ' → Closed',
                );

            case 'off_market':
                $new_status = $tracked_event['new_status'];
                $status_labels = array(
                    'Expired' => 'Listing Expired',
                    'Withdrawn' => 'Withdrawn from Market',
                    'Canceled' => 'Listing Canceled',
                );
                $event_name = isset($status_labels[$new_status]) ? $status_labels[$new_status] : 'Off Market';
                return array(
                    'date' => $event_date,
                    'event' => $event_name,
                    'event_type' => 'off_market',
                    'price' => null,
                    'change' => null,
                    'old_status' => $tracked_event['old_status'],
                    'new_status' => $new_status,
                    'days_on_market' => !empty($tracked_event['days_on_market']) ? (int) $tracked_event['days_on_market'] : null,
                    'agent_name' => null,
                    'office_name' => null,
                    'price_per_sqft' => null,
                    'details' => 'Removed from active listings',
                );

            case 'back_on_market':
                $days_off = !empty($additional['days_off_market']) ? (int) $additional['days_off_market'] : null;
                $reason = !empty($additional['off_market_reason']) ? $additional['off_market_reason'] : null;
                $details = 'Relisted for sale';
                if ($days_off) {
                    $details .= " (was off market for {$days_off} days)";
                }
                return array(
                    'date' => $event_date,
                    'event' => 'Back on Market',
                    'event_type' => 'back_on_market',
                    'price' => $new_price,
                    'change' => null,
                    'old_status' => $tracked_event['old_status'],
                    'new_status' => 'Active',
                    'days_on_market' => null,
                    'agent_name' => null,
                    'office_name' => null,
                    'price_per_sqft' => null,
                    'details' => $details,
                );

            case 'agent_change':
                return array(
                    'date' => $event_date,
                    'event' => 'Agent Changed',
                    'event_type' => 'agent_change',
                    'price' => null,
                    'change' => null,
                    'old_status' => null,
                    'new_status' => null,
                    'days_on_market' => null,
                    'agent_name' => $tracked_event['new_value'],
                    'office_name' => null,
                    'price_per_sqft' => null,
                    'details' => $tracked_event['old_value'] . ' → ' . $tracked_event['new_value'],
                    // v6.68.0: Add explicit old/new agent fields for iOS display
                    'old_agent' => $tracked_event['old_value'],
                    'new_agent' => $tracked_event['new_value'],
                );

            case 'contingency_change':
                return array(
                    'date' => $event_date,
                    'event' => 'Contingency Update',
                    'event_type' => 'contingency_change',
                    'price' => null,
                    'change' => null,
                    'old_status' => null,
                    'new_status' => null,
                    'days_on_market' => null,
                    'agent_name' => null,
                    'office_name' => null,
                    'price_per_sqft' => null,
                    'details' => $tracked_event['new_value'] ?: 'Contingencies removed',
                );

            // Skip events that don't need to be shown on iOS timeline
            case 'moved_to_archive':
            case 'moved_to_active':
            case 'property_detail_change':
            case 'showing_update':
            case 'commission_change':
                return null;

            default:
                // Unknown event type - skip
                return null;
        }
    }

    /**
     * Handle GET request for address history (previous sales at same address)
     *
     * @since 6.68.0
     * @since 6.68.1 Rewritten to query archive table directly instead of relying on property_history
     * @param WP_REST_Request $request The request object
     * @return WP_REST_Response|WP_Error
     */
    public static function handle_get_address_history($request) {
        global $wpdb;

        $id = $request->get_param('id');

        // Get the property to find its address
        $summary_table = $wpdb->prefix . 'bme_listing_summary';
        $archive_table = $wpdb->prefix . 'bme_listing_summary_archive';

        // Try active table first, then archive
        $listing = $wpdb->get_row($wpdb->prepare(
            "SELECT listing_id, street_number, street_name, unit_number, city, state_or_province, postal_code
             FROM {$summary_table} WHERE listing_key = %s",
            $id
        ));

        if (!$listing) {
            $listing = $wpdb->get_row($wpdb->prepare(
                "SELECT listing_id, street_number, street_name, unit_number, city, state_or_province, postal_code
                 FROM {$archive_table} WHERE listing_key = %s",
                $id
            ));
        }

        if (!$listing) {
            return new WP_Error('not_found', 'Property not found', array('status' => 404));
        }

        // Build display address
        $display_address = trim($listing->street_number . ' ' . $listing->street_name);
        if (!empty($listing->unit_number)) {
            $display_address .= ' Unit ' . $listing->unit_number;
        }

        $full_address = $display_address;
        if (!empty($listing->city)) {
            $full_address .= ', ' . $listing->city;
        }
        if (!empty($listing->state_or_province)) {
            $full_address .= ', ' . $listing->state_or_province;
        }
        if (!empty($listing->postal_code)) {
            $full_address .= ' ' . $listing->postal_code;
        }

        // Query archive table directly for previous sales at same address
        // Match on street_number + street_name, and optionally unit_number
        $where_conditions = array(
            $wpdb->prepare("street_number = %s", $listing->street_number),
            $wpdb->prepare("street_name = %s", $listing->street_name),
            $wpdb->prepare("listing_id != %s", $listing->listing_id),  // Exclude current listing
            "standard_status = 'Closed'",  // Only sold properties
        );

        // If current listing has a unit number, only match same unit
        // If no unit number, match all units at the address (building sales history)
        if (!empty($listing->unit_number)) {
            $where_conditions[] = $wpdb->prepare("unit_number = %s", $listing->unit_number);
        }

        $where_sql = implode(' AND ', $where_conditions);

        $sql = "SELECT
                    listing_id,
                    listing_contract_date as list_date,
                    list_price,
                    close_date,
                    close_price,
                    original_list_price,
                    days_on_market,
                    standard_status as status,
                    property_sub_type,
                    bedrooms_total,
                    bathrooms_total,
                    building_area_total
                FROM {$archive_table}
                WHERE {$where_sql}
                ORDER BY close_date DESC
                LIMIT 20";

        $results = $wpdb->get_results($sql);

        // Transform results into previous sales format
        $previous_sales = array();

        foreach ($results as $sale) {
            $list_price = !empty($sale->original_list_price) ? (int) $sale->original_list_price : (int) $sale->list_price;
            $close_price = !empty($sale->close_price) ? (int) $sale->close_price : null;

            // Calculate price change
            $price_change = null;
            $price_change_percent = null;
            if ($list_price && $close_price) {
                $price_change = $close_price - $list_price;
                $price_change_percent = round(($price_change / $list_price) * 100, 1);
            }

            $previous_sales[] = array(
                'mls_number' => $sale->listing_id,
                'list_date' => $sale->list_date ? self::format_datetime_iso8601($sale->list_date) : null,
                'list_price' => $list_price,
                'close_date' => $sale->close_date ? self::format_datetime_iso8601($sale->close_date) : null,
                'close_price' => $close_price,
                'days_on_market' => !empty($sale->days_on_market) ? (int) $sale->days_on_market : null,
                'status' => 'Sold',
                'price_change' => $price_change,
                'price_change_percent' => $price_change_percent,
                // Additional context
                'property_type' => $sale->property_sub_type,
                'beds' => !empty($sale->bedrooms_total) ? (int) $sale->bedrooms_total : null,
                'baths' => !empty($sale->bathrooms_total) ? (float) $sale->bathrooms_total : null,
                'sqft' => !empty($sale->building_area_total) ? (int) $sale->building_area_total : null,
            );
        }

        return new WP_REST_Response(array(
            'success' => true,
            'data' => array(
                'listing_id' => $listing->listing_id,
                'address' => $full_address,
                'previous_sales' => $previous_sales,
                'total_count' => count($previous_sales),
            ),
        ), 200);
    }

    /**
     * Get properties from archive tables (for Sold, Closed, Expired, etc.)
     * Used when status filter includes archive statuses
     *
     * @since 6.27.21
     */
    private static function get_archive_properties($request, $status, $page, $per_page, $sort, $bounds, $filters, $cache_key = '') {
        global $wpdb;

        $offset = ($page - 1) * $per_page;

        // v6.35.12: Get user ID from JWT for SQL-level sort prioritization of shared properties
        $shared_agent_map = array();
        $shared_listing_keys = array();
        $current_user_id = get_current_user_id();
        if ($current_user_id <= 0) {
            $auth_header = $request->get_header('Authorization');
            if (!empty($auth_header) && strpos($auth_header, 'Bearer ') === 0) {
                $token = substr($auth_header, 7);
                $payload = self::verify_jwt($token);
                if (!is_wp_error($payload) && isset($payload['sub'])) {
                    $current_user_id = absint($payload['sub']);
                }
            }
        }
        if ($current_user_id > 0) {
            $shared_agent_map = self::get_shared_agent_map_for_user($current_user_id);
            $shared_listing_keys = array_keys($shared_agent_map);
        }

        // USE OPTIMIZED ARCHIVE SUMMARY TABLE (no JOINs needed!)
        $summary_table = $wpdb->prefix . 'bme_listing_summary_archive';

        // Build WHERE clauses - all columns now in single table
        $where = array();
        $params = array();

        // v6.68.1: Extract direct property lookup flag for filter bypass
        $has_direct_property_lookup = !empty($filters['has_direct_property_lookup']);

        // Status filter - map "Sold" to "Closed" for database
        // v6.68.1: Skip status filter for direct property lookups
        $status_map = array('Sold' => 'Closed');
        if (!$has_direct_property_lookup && !empty($status)) {
            $statuses = is_array($status) ? $status : array($status);
            $mapped_statuses = array();
            foreach ($statuses as $s) {
                $mapped = isset($status_map[$s]) ? $status_map[$s] : $s;
                $mapped_statuses[] = $mapped;
            }
            $placeholders = array_fill(0, count($mapped_statuses), '%s');
            $where[] = "standard_status IN (" . implode(',', $placeholders) . ")";
            foreach ($mapped_statuses as $ms) {
                $params[] = sanitize_text_field($ms);
            }
        }

        // City filter (direct column - no JOIN!)
        if (!empty($filters['city'])) {
            $city = $filters['city'];
            if (is_array($city)) {
                $placeholders = array_fill(0, count($city), '%s');
                $where[] = "city IN (" . implode(',', $placeholders) . ")";
                foreach ($city as $c) {
                    $params[] = sanitize_text_field($c);
                }
            } else {
                $where[] = "city = %s";
                $params[] = sanitize_text_field($city);
            }
        }

        // ZIP filter (direct column - no JOIN!)
        if (!empty($filters['zip'])) {
            $zip = $filters['zip'];
            if (is_array($zip)) {
                $placeholders = array_fill(0, count($zip), '%s');
                $where[] = "postal_code IN (" . implode(',', $placeholders) . ")";
                foreach ($zip as $z) {
                    $params[] = sanitize_text_field($z);
                }
            } else {
                $where[] = "postal_code = %s";
                $params[] = sanitize_text_field($zip);
            }
        }

        // Property type filter (direct column - no JOIN!)
        // v6.68.1: Skip for direct property lookups
        if (!$has_direct_property_lookup && !empty($filters['property_type'])) {
            $property_type = $filters['property_type'];
            if (is_array($property_type)) {
                $placeholders = array_fill(0, count($property_type), '%s');
                $where[] = "property_type IN (" . implode(',', $placeholders) . ")";
                foreach ($property_type as $pt) {
                    $params[] = sanitize_text_field($pt);
                }
            } else {
                $where[] = "property_type = %s";
                $params[] = sanitize_text_field($property_type);
            }
        }

        // Price filter - use close_price for sold properties
        // v6.68.1: Skip for direct property lookups
        if (!$has_direct_property_lookup) {
            if (!empty($filters['min_price'])) {
                $where[] = "COALESCE(close_price, list_price) >= %d";
                $params[] = intval($filters['min_price']);
            }
            if (!empty($filters['max_price'])) {
                $where[] = "COALESCE(close_price, list_price) <= %d";
                $params[] = intval($filters['max_price']);
            }
        }

        // Beds filter (direct column - no JOIN!)
        // v6.68.1: Skip for direct property lookups
        if (!$has_direct_property_lookup && !empty($filters['beds'])) {
            $where[] = "bedrooms_total >= %d";
            $params[] = intval($filters['beds']);
        }

        // Baths filter (direct column - no JOIN!)
        // v6.68.1: Skip for direct property lookups
        if (!$has_direct_property_lookup && !empty($filters['baths'])) {
            $where[] = "bathrooms_total >= %f";
            $params[] = floatval($filters['baths']);
        }

        // Square footage (direct column - no JOIN!)
        // v6.68.1: Skip for direct property lookups
        if (!$has_direct_property_lookup) {
            if (!empty($filters['sqft_min'])) {
                $where[] = "building_area_total >= %d";
                $params[] = intval($filters['sqft_min']);
            }
            if (!empty($filters['sqft_max'])) {
                $where[] = "building_area_total <= %d";
                $params[] = intval($filters['sqft_max']);
            }
        }

        // Year built (direct column - no JOIN!)
        // v6.68.1: Skip for direct property lookups
        if (!$has_direct_property_lookup) {
            if (!empty($filters['year_built_min'])) {
                $where[] = "year_built >= %d";
                $params[] = intval($filters['year_built_min']);
            }
            if (!empty($filters['year_built_max'])) {
                $where[] = "year_built <= %d";
                $params[] = intval($filters['year_built_max']);
            }
        }

        // MLS number filter (direct column - no JOIN!)
        if (!empty($filters['mls_number'])) {
            $where[] = "listing_id = %s";
            $params[] = sanitize_text_field($filters['mls_number']);
        }

        // Address filter (direct column - no JOIN!)
        if (!empty($filters['address'])) {
            $where[] = "unparsed_address LIKE %s";
            $params[] = '%' . $wpdb->esc_like($filters['address']) . '%';
        }

        // Neighborhood filter (direct columns in summary table - subdivision_name, mls_area_major, mls_area_minor)
        // v6.49.7 - FIX: Added missing neighborhood filter support for archive queries
        if (!empty($filters['neighborhood'])) {
            $neighborhood = $filters['neighborhood'];
            if (is_array($neighborhood)) {
                // Handle array of neighborhoods (multi-select from iOS)
                $placeholders = array_fill(0, count($neighborhood), '%s');
                $placeholder_str = implode(',', $placeholders);
                $where[] = "(subdivision_name IN ({$placeholder_str}) OR mls_area_major IN ({$placeholder_str}) OR mls_area_minor IN ({$placeholder_str}))";
                // Add params 3 times (once for each field)
                foreach ($neighborhood as $n) {
                    $params[] = sanitize_text_field($n);
                }
                foreach ($neighborhood as $n) {
                    $params[] = sanitize_text_field($n);
                }
                foreach ($neighborhood as $n) {
                    $params[] = sanitize_text_field($n);
                }
            } else {
                // Single neighborhood
                $where[] = "(subdivision_name = %s OR mls_area_major = %s OR mls_area_minor = %s)";
                $params[] = sanitize_text_field($neighborhood);
                $params[] = sanitize_text_field($neighborhood);
                $params[] = sanitize_text_field($neighborhood);
            }
        }

        // Bounds filter for map (direct columns - no JOIN!)
        if (!empty($bounds)) {
            $coords = explode(',', $bounds);
            if (count($coords) === 4) {
                $where[] = "latitude BETWEEN %f AND %f AND longitude BETWEEN %f AND %f";
                $params[] = floatval($coords[0]); // south
                $params[] = floatval($coords[2]); // north
                $params[] = floatval($coords[1]); // west
                $params[] = floatval($coords[3]); // east
            }
        }

        // Polygon filter for draw search (v6.30.24 - iOS parity with web)
        if (!empty($filters['polygon_coords'])) {
            $spatial_service = MLD_Spatial_Filter_Service::get_instance();
            $polygon_condition = $spatial_service->build_summary_polygon_condition($filters['polygon_coords']);
            if ($polygon_condition) {
                $where[] = $polygon_condition;
            }
        }

        // Build WHERE string
        $where_sql = empty($where) ? '1=1' : implode(' AND ', $where);

        // Determine base sort order (all columns in summary table)
        switch ($sort) {
            case 'price_asc':
                $base_order_by = "COALESCE(close_price, list_price) ASC";
                break;
            case 'price_desc':
                $base_order_by = "COALESCE(close_price, list_price) DESC";
                break;
            case 'list_date_asc':
                $base_order_by = "close_date ASC";
                break;
            case 'beds_desc':
                $base_order_by = "bedrooms_total DESC";
                break;
            case 'sqft_desc':
                $base_order_by = "building_area_total DESC";
                break;
            case 'list_date_desc':
            default:
                $base_order_by = "close_date DESC";
                break;
        }

        // v6.65.0: Prioritize exclusive listings (listing_id < 1,000,000)
        // v6.35.12: Prioritize agent-shared properties at top of ALL results
        $exclusive_priority = "CASE WHEN listing_id < 1000000 THEN 0 ELSE 1 END";

        if (!empty($shared_listing_keys)) {
            $key_placeholders = implode(',', array_fill(0, count($shared_listing_keys), '%s'));
            $shared_expression = $wpdb->prepare(
                "CASE WHEN listing_key IN ({$key_placeholders}) THEN 0 ELSE 1 END",
                $shared_listing_keys
            );
            $order_by = "{$shared_expression}, {$exclusive_priority}, {$base_order_by}";
        } else {
            $order_by = "{$exclusive_priority}, {$base_order_by}";
        }

        // FAST COUNT: Single table, no JOINs!
        $count_sql = "SELECT COUNT(*) FROM {$summary_table} WHERE {$where_sql}";
        if (!empty($params)) {
            $total = $wpdb->get_var($wpdb->prepare($count_sql, $params));
        } else {
            $total = $wpdb->get_var($count_sql);
        }

        // FAST DATA QUERY: Single table, no JOINs!
        $sql = "SELECT
            listing_key, listing_id,
            list_price, original_list_price, close_price, close_date,
            property_type, property_sub_type, standard_status,
            listing_contract_date, days_on_market,
            bedrooms_total, bathrooms_total, bathrooms_full, bathrooms_half,
            building_area_total, lot_size_acres, year_built, garage_spaces,
            city, state_or_province, postal_code,
            latitude, longitude, unparsed_address,
            street_number, street_name, unit_number,
            subdivision_name, main_photo_url
            FROM {$summary_table}
            WHERE {$where_sql}
            ORDER BY {$order_by}
            LIMIT %d OFFSET %d";

        // Merge filter params with pagination params
        $query_params = array_merge($params, array($per_page, $offset));
        $listings = $wpdb->get_results($wpdb->prepare($sql, $query_params));

        // Batch fetch photos for all listings (first 5 per listing for carousel)
        $media_table = $wpdb->prefix . 'bme_media';
        $photos_map = array();
        if (!empty($listings)) {
            $listing_ids = array_map(function($l) { return $l->listing_id; }, $listings);
            $placeholders = implode(',', array_fill(0, count($listing_ids), '%s'));

            // Use a subquery with ROW_NUMBER to get first 5 photos per listing
            $photos_sql = "SELECT listing_id, media_url
                FROM (
                    SELECT listing_id, media_url, order_index,
                        @row_num := IF(@prev_listing = listing_id, @row_num + 1, 1) AS rn,
                        @prev_listing := listing_id
                    FROM {$media_table}, (SELECT @row_num := 0, @prev_listing := '') vars
                    WHERE listing_id IN ({$placeholders}) AND media_category = 'Photo'
                    ORDER BY listing_id, order_index ASC
                ) ranked
                WHERE rn <= 5";

            $photos_results = $wpdb->get_results($wpdb->prepare($photos_sql, $listing_ids));

            // Build photos map: listing_id -> array of photo URLs
            foreach ($photos_results as $photo) {
                if (!isset($photos_map[$photo->listing_id])) {
                    $photos_map[$photo->listing_id] = array();
                }
                $photos_map[$photo->listing_id][] = $photo->media_url;
            }
        }

        // v6.35.12: shared_agent_map is now fetched early for SQL sort prioritization
        // (see top of method)

        // Format listings to match regular properties response
        $formatted = array_map(function($listing) use ($photos_map, $shared_agent_map) {
            // Build street address from components
            $street_address = trim($listing->street_number . ' ' . $listing->street_name);
            if (!empty($listing->unit_number)) {
                $street_address .= ' #' . $listing->unit_number;
            }
            if (empty($street_address) && !empty($listing->unparsed_address)) {
                $street_address = $listing->unparsed_address;
            }

            // Use close_price for sold properties, list_price as fallback
            $display_price = !empty($listing->close_price) ? (int) $listing->close_price : (int) $listing->list_price;
            $original_price = isset($listing->original_list_price) ? (int) $listing->original_list_price : null;

            // Convert lot_size_acres to square feet
            $lot_size_sqft = $listing->lot_size_acres ? round(floatval($listing->lot_size_acres) * 43560) : null;

            // Get photos array for this listing (already limited to 5)
            $photos = isset($photos_map[$listing->listing_id]) ? $photos_map[$listing->listing_id] : array();

            return array(
                'id' => $listing->listing_key ?: $listing->listing_id,
                'mls_number' => $listing->listing_id,
                'address' => $street_address,
                'city' => $listing->city,
                'state' => $listing->state_or_province,
                'zip' => $listing->postal_code,
                'neighborhood' => $listing->subdivision_name ?: null,
                'price' => $display_price,
                'original_price' => $original_price,
                'close_price' => !empty($listing->close_price) ? (int) $listing->close_price : null,
                'close_date' => $listing->close_date ?: null,
                'beds' => (int) $listing->bedrooms_total,
                'baths' => (float) $listing->bathrooms_total,
                'baths_full' => isset($listing->bathrooms_full) ? (int) $listing->bathrooms_full : null,
                'baths_half' => isset($listing->bathrooms_half) ? (int) $listing->bathrooms_half : null,
                'sqft' => (int) $listing->building_area_total,
                'property_type' => $listing->property_type,
                'property_subtype' => $listing->property_sub_type,
                'status' => $listing->standard_status,
                'latitude' => (float) $listing->latitude,
                'longitude' => (float) $listing->longitude,
                'list_date' => $listing->listing_contract_date,
                'dom' => (int) $listing->days_on_market,
                'photo_url' => $listing->main_photo_url,
                'photos' => $photos,
                'year_built' => $listing->year_built ? (int) $listing->year_built : null,
                'lot_size' => $lot_size_sqft,
                'garage_spaces' => isset($listing->garage_spaces) ? (int) $listing->garage_spaces : null,
                'has_open_house' => false, // Archive listings don't have open houses
                'next_open_house' => null,
                'is_shared_by_agent' => isset($shared_agent_map[$listing->listing_key]),
                'shared_by_agent_name' => isset($shared_agent_map[$listing->listing_key]) ? $shared_agent_map[$listing->listing_key]['first_name'] : null,
                'shared_by_agent_photo' => isset($shared_agent_map[$listing->listing_key]) ? $shared_agent_map[$listing->listing_key]['photo_url'] : null,
            );
        }, $listings);

        // v6.35.12: Shared property prioritization now handled at SQL level via ORDER BY
        // (see CASE expression in sort logic)

        $response_data = array(
            'success' => true,
            'data' => array(
                'listings' => $formatted,
                'total' => (int) $total,
                'page' => $page,
                'per_page' => $per_page,
                'total_pages' => ceil($total / $per_page)
            )
        );

        // Cache the response for 30 minutes (match web plugin)
        if (!empty($cache_key)) {
            set_transient($cache_key, $response_data, 1800);
        }

        return new WP_REST_Response($response_data, 200);
    }

    /**
     * Get properties from both active and archive tables when mixed statuses are requested
     * This handles cases like "Active + Pending + Sold" where we need to query both table sets
     */
    private static function get_combined_properties($request, $active_statuses, $archive_statuses, $page, $per_page, $sort, $bounds, $filters, $cache_key = '') {
        global $wpdb;

        // v6.35.12: Get user ID from JWT for SQL-level sort prioritization of shared properties
        $shared_agent_map = array();
        $shared_listing_keys = array();
        $current_user_id = get_current_user_id();
        if ($current_user_id <= 0) {
            $auth_header = $request->get_header('Authorization');
            if (!empty($auth_header) && strpos($auth_header, 'Bearer ') === 0) {
                $token = substr($auth_header, 7);
                $payload = self::verify_jwt($token);
                if (!is_wp_error($payload) && isset($payload['sub'])) {
                    $current_user_id = absint($payload['sub']);
                }
            }
        }
        if ($current_user_id > 0) {
            $shared_agent_map = self::get_shared_agent_map_for_user($current_user_id);
            $shared_listing_keys = array_keys($shared_agent_map);
        }

        // OPTIMIZED: Use both summary tables (no JOINs needed!)
        $summary_table = $wpdb->prefix . 'bme_listing_summary';
        $summary_archive = $wpdb->prefix . 'bme_listing_summary_archive';

        // Build common WHERE clauses for filters (works for both tables)
        $filter_where = array();
        $filter_params = array();

        // v6.68.1: Extract direct property lookup flag for filter bypass
        $has_direct_property_lookup = !empty($filters['has_direct_property_lookup']);

        // Property type filter (handle both string and array)
        // v6.68.1: Skip for direct property lookups
        if (!$has_direct_property_lookup && !empty($filters['property_type'])) {
            $property_type = $filters['property_type'];
            if (is_array($property_type)) {
                $placeholders = array_fill(0, count($property_type), '%s');
                $filter_where[] = "property_type IN (" . implode(',', $placeholders) . ")";
                foreach ($property_type as $pt) {
                    $filter_params[] = sanitize_text_field($pt);
                }
            } else {
                $filter_where[] = "property_type = %s";
                $filter_params[] = sanitize_text_field($property_type);
            }
        }

        // City filter - keep active for location context
        if (!empty($filters['city'])) {
            $city = $filters['city'];
            if (is_array($city)) {
                $placeholders = array_fill(0, count($city), '%s');
                $filter_where[] = "city IN (" . implode(',', $placeholders) . ")";
                foreach ($city as $c) {
                    $filter_params[] = sanitize_text_field($c);
                }
            } else {
                $filter_where[] = "city = %s";
                $filter_params[] = sanitize_text_field($city);
            }
        }

        // ZIP filter - keep active for location context
        if (!empty($filters['zip'])) {
            $zip = $filters['zip'];
            if (is_array($zip)) {
                $placeholders = array_fill(0, count($zip), '%s');
                $filter_where[] = "postal_code IN (" . implode(',', $placeholders) . ")";
                foreach ($zip as $z) {
                    $filter_params[] = sanitize_text_field($z);
                }
            } else {
                $filter_where[] = "postal_code = %s";
                $filter_params[] = sanitize_text_field($zip);
            }
        }

        // Price filters
        // v6.68.1: Skip for direct property lookups
        if (!$has_direct_property_lookup) {
            if (!empty($filters['min_price'])) {
                $filter_where[] = "list_price >= %d";
                $filter_params[] = intval($filters['min_price']);
            }
            if (!empty($filters['max_price'])) {
                $filter_where[] = "list_price <= %d";
                $filter_params[] = intval($filters['max_price']);
            }
        }

        // Beds filter
        // v6.68.1: Skip for direct property lookups
        if (!$has_direct_property_lookup && !empty($filters['beds'])) {
            $filter_where[] = "bedrooms_total >= %d";
            $filter_params[] = intval($filters['beds']);
        }

        // Baths filter
        // v6.68.1: Skip for direct property lookups
        if (!$has_direct_property_lookup && !empty($filters['baths'])) {
            $filter_where[] = "bathrooms_total >= %f";
            $filter_params[] = floatval($filters['baths']);
        }

        // Square footage
        // v6.68.1: Skip for direct property lookups
        if (!$has_direct_property_lookup) {
            if (!empty($filters['sqft_min'])) {
                $filter_where[] = "building_area_total >= %d";
                $filter_params[] = intval($filters['sqft_min']);
            }
            if (!empty($filters['sqft_max'])) {
                $filter_where[] = "building_area_total <= %d";
                $filter_params[] = intval($filters['sqft_max']);
            }
        }

        // Year built
        // v6.68.1: Skip for direct property lookups
        if (!$has_direct_property_lookup) {
            if (!empty($filters['year_built_min'])) {
                $filter_where[] = "year_built >= %d";
                $filter_params[] = intval($filters['year_built_min']);
            }
            if (!empty($filters['year_built_max'])) {
                $filter_where[] = "year_built <= %d";
                $filter_params[] = intval($filters['year_built_max']);
            }
        }

        // MLS number filter
        if (!empty($filters['mls_number'])) {
            $filter_where[] = "listing_id = %s";
            $filter_params[] = sanitize_text_field($filters['mls_number']);
        }

        // Address filter - use CONCAT(street_number, street_name) since active summary table
        // doesn't have unparsed_address column (only archive table has it)
        // v6.68.4: Added missing address filter for combined queries
        if (!empty($filters['address'])) {
            $filter_where[] = "CONCAT(street_number, ' ', street_name) LIKE %s";
            $filter_params[] = '%' . $wpdb->esc_like($filters['address']) . '%';
        }

        // Neighborhood filter (both summary tables have subdivision_name, mls_area_major, mls_area_minor)
        // v6.49.7 - FIX: Added missing neighborhood filter support for combined queries
        if (!empty($filters['neighborhood'])) {
            $neighborhood = $filters['neighborhood'];
            if (is_array($neighborhood)) {
                // Handle array of neighborhoods (multi-select from iOS)
                $placeholders = array_fill(0, count($neighborhood), '%s');
                $placeholder_str = implode(',', $placeholders);
                $filter_where[] = "(subdivision_name IN ({$placeholder_str}) OR mls_area_major IN ({$placeholder_str}) OR mls_area_minor IN ({$placeholder_str}))";
                // Add params 3 times (once for each field)
                foreach ($neighborhood as $n) {
                    $filter_params[] = sanitize_text_field($n);
                }
                foreach ($neighborhood as $n) {
                    $filter_params[] = sanitize_text_field($n);
                }
                foreach ($neighborhood as $n) {
                    $filter_params[] = sanitize_text_field($n);
                }
            } else {
                // Single neighborhood
                $filter_where[] = "(subdivision_name = %s OR mls_area_major = %s OR mls_area_minor = %s)";
                $filter_params[] = sanitize_text_field($neighborhood);
                $filter_params[] = sanitize_text_field($neighborhood);
                $filter_params[] = sanitize_text_field($neighborhood);
            }
        }

        // Bounds filter for map (both summary tables have lat/lng directly)
        // v6.68.1: Skip for direct property lookups
        $bounds_where = '';
        $bounds_params = array();
        if (!$has_direct_property_lookup && !empty($bounds)) {
            $coords = explode(',', $bounds);
            if (count($coords) === 4) {
                $south = floatval($coords[0]);
                $west = floatval($coords[1]);
                $north = floatval($coords[2]);
                $east = floatval($coords[3]);
                $bounds_where = " AND latitude BETWEEN %f AND %f AND longitude BETWEEN %f AND %f";
                $bounds_params = array($south, $north, $west, $east);
            }
        }

        // Polygon filter for draw search (v6.30.24 - iOS parity with web)
        // v6.68.1: Skip for direct property lookups
        $polygon_where = '';
        if (!$has_direct_property_lookup && !empty($filters['polygon_coords'])) {
            $spatial_service = MLD_Spatial_Filter_Service::get_instance();
            $polygon_condition = $spatial_service->build_summary_polygon_condition($filters['polygon_coords']);
            if ($polygon_condition) {
                $polygon_where = " AND " . $polygon_condition;
            }
        }

        $filter_sql = empty($filter_where) ? '' : ' AND ' . implode(' AND ', $filter_where);

        // Re-index arrays to ensure sequential keys
        $active_statuses = array_values($active_statuses);
        $archive_statuses = array_values($archive_statuses);

        // v6.68.1: For direct property lookups, skip status filter entirely
        // so we can find the property regardless of its status
        if ($has_direct_property_lookup) {
            // No status filter - search all records
            $active_status_sql = "1=1";
            $active_statuses = array(); // No params needed
            $archive_status_sql = "1=1";
            $mapped_archive_statuses = array(); // No params needed
        } else {
            // Build status placeholders for active statuses
            $active_status_placeholders = array_fill(0, count($active_statuses), '%s');
            $active_status_sql = "standard_status IN (" . implode(',', $active_status_placeholders) . ")";

            // Map archive statuses (Sold -> Closed)
            $status_map = array('Sold' => 'Closed');
            $mapped_archive_statuses = array_values(array_map(function($s) use ($status_map) {
                return isset($status_map[$s]) ? $status_map[$s] : $s;
            }, $archive_statuses));
            $archive_status_placeholders = array_fill(0, count($mapped_archive_statuses), '%s');
            $archive_status_sql = "standard_status IN (" . implode(',', $archive_status_placeholders) . ")";
        }

        // FAST COUNT: Both queries hit single tables with no JOINs!
        $active_count_sql = "SELECT COUNT(*) FROM {$summary_table} WHERE {$active_status_sql}{$filter_sql}{$bounds_where}{$polygon_where}";
        $active_count_params = array_merge($active_statuses, $filter_params, $bounds_params);
        $active_count = (int) $wpdb->get_var($wpdb->prepare($active_count_sql, $active_count_params));

        // Archive count - OPTIMIZED: Single table query, no JOINs!
        $archive_count_sql = "SELECT COUNT(*) FROM {$summary_archive} WHERE {$archive_status_sql}{$filter_sql}{$bounds_where}{$polygon_where}";
        $archive_count_params = array_merge($mapped_archive_statuses, $filter_params, $bounds_params);
        $archive_count = (int) $wpdb->get_var($wpdb->prepare($archive_count_sql, $archive_count_params));

        $total = $active_count + $archive_count;

        // Determine base sort field
        switch ($sort) {
            case 'price_asc':
                $order_field = 'price';
                $order_dir = 'ASC';
                break;
            case 'price_desc':
                $order_field = 'price';
                $order_dir = 'DESC';
                break;
            case 'list_date_asc':
                $order_field = 'list_date';
                $order_dir = 'ASC';
                break;
            case 'beds_desc':
                $order_field = 'beds';
                $order_dir = 'DESC';
                break;
            case 'sqft_desc':
                $order_field = 'sqft';
                $order_dir = 'DESC';
                break;
            case 'list_date_desc':
            default:
                $order_field = 'list_date';
                $order_dir = 'DESC';
                break;
        }

        // v6.65.0: Prioritize exclusive listings (mls_number < 1,000,000)
        // v6.35.12: Build ORDER BY with shared property prioritization
        // UNION result uses 'id' alias (=listing_key) and 'mls_number' (=listing_id)
        $base_order_by = "{$order_field} {$order_dir}";
        $exclusive_priority = "CASE WHEN mls_number < 1000000 THEN 0 ELSE 1 END";

        if (!empty($shared_listing_keys)) {
            $key_placeholders = implode(',', array_fill(0, count($shared_listing_keys), '%s'));
            $shared_expression = $wpdb->prepare(
                "CASE WHEN id IN ({$key_placeholders}) THEN 0 ELSE 1 END",
                $shared_listing_keys
            );
            $order_by = "{$shared_expression}, {$exclusive_priority}, {$base_order_by}";
        } else {
            $order_by = "{$exclusive_priority}, {$base_order_by}";
        }

        // OPTIMIZED UNION: Both queries hit summary tables (no JOINs!)

        // Active listings query from summary table
        $active_sql = "SELECT
            listing_key as id, listing_id as mls_number,
            CONCAT(street_number, ' ', street_name) as address, city, state_or_province as state, postal_code as zip,
            NULL as neighborhood,
            list_price as price, original_list_price as original_price,
            NULL as close_price, NULL as close_date,
            bedrooms_total as beds, bathrooms_total as baths,
            NULL as baths_full, NULL as baths_half,
            building_area_total as sqft, property_type, property_sub_type as property_subtype,
            standard_status as status, latitude, longitude,
            listing_contract_date as list_date, days_on_market as dom,
            main_photo_url as photo_url, year_built,
            lot_size_acres, garage_spaces,
            0 as has_open_house, NULL as next_open_house
            FROM {$summary_table}
            WHERE {$active_status_sql}{$filter_sql}{$bounds_where}{$polygon_where}";

        // Archive listings query from archive summary table - FAST: Single table, no JOINs!
        $archive_sql = "SELECT
            listing_key as id, listing_id as mls_number,
            CONCAT(street_number, ' ', street_name) as address, city, state_or_province as state, postal_code as zip,
            subdivision_name as neighborhood,
            list_price as price, original_list_price as original_price,
            close_price, close_date,
            bedrooms_total as beds, bathrooms_total as baths,
            bathrooms_full as baths_full, bathrooms_half as baths_half,
            building_area_total as sqft, property_type, property_sub_type as property_subtype,
            standard_status as status, latitude, longitude,
            listing_contract_date as list_date, days_on_market as dom,
            main_photo_url as photo_url, year_built,
            lot_size_acres, garage_spaces,
            0 as has_open_house, NULL as next_open_house
            FROM {$summary_archive}
            WHERE {$archive_status_sql}{$filter_sql}{$bounds_where}{$polygon_where}";

        // Combine with UNION ALL
        $offset = ($page - 1) * $per_page;
        $combined_sql = "({$active_sql}) UNION ALL ({$archive_sql}) ORDER BY {$order_by} LIMIT %d OFFSET %d";

        // Combine all params - both queries use same filter_params and bounds_params
        $combined_params = array_merge(
            $active_statuses,
            $filter_params,
            $bounds_params,
            $mapped_archive_statuses,
            $filter_params,
            $bounds_params,
            array($per_page, $offset)
        );

        $listings = $wpdb->get_results($wpdb->prepare($combined_sql, $combined_params));

        // Batch fetch photos for all listings (first 5 per listing for carousel)
        $media_table = $wpdb->prefix . 'bme_media';
        $photos_map = array();
        if (!empty($listings)) {
            $listing_ids = array_map(function($l) { return $l->mls_number; }, $listings);
            $placeholders = implode(',', array_fill(0, count($listing_ids), '%s'));

            // Use a subquery with ROW_NUMBER to get first 5 photos per listing
            $photos_sql = "SELECT listing_id, media_url
                FROM (
                    SELECT listing_id, media_url, order_index,
                        @row_num := IF(@prev_listing = listing_id, @row_num + 1, 1) AS rn,
                        @prev_listing := listing_id
                    FROM {$media_table}, (SELECT @row_num := 0, @prev_listing := '') vars
                    WHERE listing_id IN ({$placeholders}) AND media_category = 'Photo'
                    ORDER BY listing_id, order_index ASC
                ) ranked
                WHERE rn <= 5";

            $photos_results = $wpdb->get_results($wpdb->prepare($photos_sql, $listing_ids));

            // Build photos map: listing_id -> array of photo URLs
            foreach ($photos_results as $photo) {
                if (!isset($photos_map[$photo->listing_id])) {
                    $photos_map[$photo->listing_id] = array();
                }
                $photos_map[$photo->listing_id][] = $photo->media_url;
            }
        }

        // v6.35.12: shared_agent_map is now fetched early for SQL sort prioritization
        // (see top of method)

        // Format results
        $formatted = array_map(function($listing) use ($photos_map, $shared_agent_map) {
            $lot_size_sqft = $listing->lot_size_acres ? round(floatval($listing->lot_size_acres) * 43560) : null;

            // Get photos array for this listing (already limited to 5)
            $photos = isset($photos_map[$listing->mls_number]) ? $photos_map[$listing->mls_number] : array();

            return array(
                'id' => $listing->id ?: $listing->mls_number,
                'mls_number' => $listing->mls_number,
                'address' => trim($listing->address),
                'city' => $listing->city,
                'state' => $listing->state,
                'zip' => $listing->zip,
                'neighborhood' => $listing->neighborhood,
                'price' => (int) $listing->price,
                'original_price' => $listing->original_price ? (int) $listing->original_price : null,
                'close_price' => $listing->close_price ? (int) $listing->close_price : null,
                'close_date' => $listing->close_date,
                'beds' => (int) $listing->beds,
                'baths' => (float) $listing->baths,
                'baths_full' => $listing->baths_full ? (int) $listing->baths_full : null,
                'baths_half' => $listing->baths_half ? (int) $listing->baths_half : null,
                'sqft' => (int) $listing->sqft,
                'property_type' => $listing->property_type,
                'property_subtype' => $listing->property_subtype,
                'status' => $listing->status,
                'latitude' => (float) $listing->latitude,
                'longitude' => (float) $listing->longitude,
                'list_date' => $listing->list_date,
                'dom' => (int) $listing->dom,
                'photo_url' => $listing->photo_url,
                'photos' => $photos,
                'year_built' => $listing->year_built ? (int) $listing->year_built : null,
                'lot_size' => $lot_size_sqft,
                'garage_spaces' => $listing->garage_spaces ? (int) $listing->garage_spaces : null,
                'has_open_house' => (bool) $listing->has_open_house,
                'next_open_house' => $listing->next_open_house,
                'is_shared_by_agent' => isset($shared_agent_map[$listing->id]),
                'shared_by_agent_name' => isset($shared_agent_map[$listing->id]) ? $shared_agent_map[$listing->id]['first_name'] : null,
                'shared_by_agent_photo' => isset($shared_agent_map[$listing->id]) ? $shared_agent_map[$listing->id]['photo_url'] : null,
            );
        }, $listings);

        // v6.35.12: Shared property prioritization now handled at SQL level via ORDER BY
        // (see CASE expression in sort logic)

        $response_data = array(
            'success' => true,
            'data' => array(
                'listings' => $formatted,
                'total' => $total,
                'page' => $page,
                'per_page' => $per_page,
                'total_pages' => ceil($total / $per_page)
            )
        );

        // Cache the response for 30 minutes (match web plugin)
        if (!empty($cache_key)) {
            set_transient($cache_key, $response_data, 1800);
        }

        return new WP_REST_Response($response_data, 200);
    }

    /**
     * Handle get boundary request
     * Returns GeoJSON boundary polygon for city/neighborhood/zipcode
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function handle_get_boundary($request) {
        $location = sanitize_text_field($request->get_param('location'));
        $type = sanitize_text_field($request->get_param('type')) ?: 'city';
        $state = sanitize_text_field($request->get_param('state')) ?: 'Massachusetts';
        $parent_city = sanitize_text_field($request->get_param('parent_city'));

        if (empty($location)) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'missing_location',
                'message' => 'Location parameter is required'
            ), 400);
        }

        // Validate type
        $valid_types = array('city', 'neighborhood', 'zipcode');
        if (!in_array($type, $valid_types)) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'invalid_type',
                'message' => 'Type must be one of: city, neighborhood, zipcode'
            ), 400);
        }

        // Neighborhoods require parent_city
        if ($type === 'neighborhood' && empty($parent_city)) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'missing_parent_city',
                'message' => 'parent_city parameter is required for neighborhood boundaries'
            ), 400);
        }

        // Use the existing MLD_City_Boundaries class
        if (!class_exists('MLD_City_Boundaries')) {
            // Try to include it
            $boundaries_file = MLD_PLUGIN_DIR . 'includes/class-mld-city-boundaries.php';
            if (file_exists($boundaries_file)) {
                require_once $boundaries_file;
            } else {
                return new WP_REST_Response(array(
                    'success' => false,
                    'code' => 'boundaries_unavailable',
                    'message' => 'City boundaries system is not available'
                ), 500);
            }
        }

        // Instantiate the boundaries class to use its methods
        $boundaries = new MLD_City_Boundaries();

        // Get boundary from cache or fetch from Nominatim
        $boundary = self::get_boundary_data($location, $state, $type, $parent_city, $boundaries);

        if (!$boundary) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'boundary_not_found',
                'message' => 'Could not retrieve boundary for ' . $location
            ), 404);
        }

        return new WP_REST_Response(array(
            'success' => true,
            'data' => array(
                'geometry' => $boundary['geometry'],
                'bbox' => $boundary['bbox'],
                'display_name' => isset($boundary['display_name']) ? $boundary['display_name'] : $location,
                'location' => $location,
                'type' => $type
            )
        ), 200);
    }

    /**
     * Get boundary data from cache or fetch from Nominatim
     *
     * @param string $location Location name
     * @param string $state State name
     * @param string $type Boundary type (city, neighborhood, zipcode)
     * @param string $parent_city Parent city for neighborhoods
     * @param MLD_City_Boundaries $boundaries Boundaries instance
     * @return array|null Boundary data or null if not found
     */
    private static function get_boundary_data($location, $state, $type, $parent_city, $boundaries) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'mld_city_boundaries';

        // Check if table exists
        $table_exists = $wpdb->get_var($wpdb->prepare(
            "SHOW TABLES LIKE %s",
            $table_name
        ));

        if (!$table_exists) {
            // Create the table if it doesn't exist
            MLD_City_Boundaries::create_boundaries_table();
        }

        // Check cache first (boundaries less than 30 days old)
        $cached = $wpdb->get_row($wpdb->prepare(
            "SELECT boundary_data, bbox_north, bbox_south, bbox_east, bbox_west, display_name,
                    TIMESTAMPDIFF(DAY, fetched_at, NOW()) as age_days
             FROM $table_name
             WHERE city = %s AND state = %s AND boundary_type = %s
             HAVING age_days < 30",
            $location, $state, $type
        ));

        if ($cached) {
            $geometry = json_decode($cached->boundary_data, true);

            // Validate JSON decode succeeded
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($geometry) || !isset($geometry['type'])) {
                // Invalid cached data - delete and fetch fresh
                $wpdb->delete(
                    $table_name,
                    array('city' => $location, 'state' => $state, 'boundary_type' => $type)
                );
                // Fall through to fetch fresh data from Nominatim
            } elseif (!in_array($geometry['type'], array('Polygon', 'MultiPolygon'), true)) {
                // Validate that cached geometry is a Polygon/MultiPolygon, not a Point
                // If it's a Point, delete the cache entry and fetch fresh data
                // Invalid cache entry (Point geometry) - delete it
                $wpdb->delete(
                    $table_name,
                    array('city' => $location, 'state' => $state, 'boundary_type' => $type)
                );
                // Fall through to fetch fresh data from Nominatim
            } else {
                // Update last_used timestamp
                $wpdb->update(
                    $table_name,
                    array('last_used' => current_time('mysql')),
                    array('city' => $location, 'state' => $state, 'boundary_type' => $type)
                );

                return array(
                    'geometry' => $geometry,
                    'bbox' => array(
                        'north' => floatval($cached->bbox_north),
                        'south' => floatval($cached->bbox_south),
                        'east' => floatval($cached->bbox_east),
                        'west' => floatval($cached->bbox_west)
                    ),
                    'display_name' => $cached->display_name
                );
            }
        }

        // Fetch from Nominatim
        $boundary = self::fetch_boundary_from_nominatim($location, $state, $type, $parent_city);

        if ($boundary) {
            // Cache the result
            self::cache_boundary($location, $state, $type, $boundary);
        }

        return $boundary;
    }

    /**
     * Fetch boundary from OpenStreetMap Nominatim API
     *
     * @param string $location Location name
     * @param string $state State name
     * @param string $type Boundary type
     * @param string $parent_city Parent city for neighborhoods
     * @return array|null Boundary data or null
     */
    private static function fetch_boundary_from_nominatim($location, $state, $type, $parent_city) {
        $query_params = array(
            'format' => 'geojson',
            'polygon_geojson' => '1',
            'limit' => '1'
        );

        // Build query based on type
        switch ($type) {
            case 'city':
                $query_params['city'] = $location;
                $query_params['state'] = $state;
                $query_params['country'] = 'USA';
                break;

            case 'neighborhood':
                // Try multiple query formats for neighborhoods
                $queries = array(
                    array_merge($query_params, array('q' => "$location, $parent_city, $state, USA")),
                    array_merge($query_params, array('suburb' => $location, 'city' => $parent_city, 'state' => $state, 'country' => 'USA')),
                    array_merge($query_params, array('neighbourhood' => $location, 'city' => $parent_city, 'state' => $state, 'country' => 'USA'))
                );

                foreach ($queries as $q) {
                    $result = self::query_nominatim($q);
                    if ($result) {
                        // Validate that the result is for the neighborhood, not the parent city
                        // Check if display_name starts with the neighborhood name (case-insensitive)
                        $display_name = strtolower($result['display_name']);
                        $location_lower = strtolower($location);
                        if (strpos($display_name, $location_lower) === 0) {
                            return $result;
                        }
                        // If display_name doesn't match, continue trying other query formats
                    }
                }
                return null;

            case 'zipcode':
                $query_params['postalcode'] = $location;
                $query_params['state'] = $state;
                $query_params['country'] = 'USA';
                break;

            default:
                $query_params['q'] = "$location, $state, USA";
        }

        return self::query_nominatim($query_params);
    }

    /**
     * Query Nominatim API
     *
     * @param array $query_params Query parameters
     * @return array|null Result or null
     */
    private static function query_nominatim($query_params) {
        $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query($query_params);

        $args = array(
            'timeout' => 10,
            'headers' => array(
                'User-Agent' => 'MLS-Listings-Display-Plugin/' . MLD_VERSION . ' (WordPress)',
                'Referer' => home_url()
            )
        );

        $response = wp_remote_get($url, $args);

        if (is_wp_error($response)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD Boundary API: Nominatim request failed - ' . $response->get_error_message());
            }
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        // Validate JSON decode succeeded
        if (json_last_error() !== JSON_ERROR_NONE) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD Boundary API: Invalid JSON response - ' . json_last_error_msg());
            }
            return null;
        }

        if (empty($data['features']) || empty($data['features'][0]['geometry'])) {
            return null;
        }

        $feature = $data['features'][0];
        $geometry = $feature['geometry'];

        // Only accept Polygon or MultiPolygon geometries
        // Nominatim returns Point for locations without boundary data
        if (!in_array($geometry['type'], array('Polygon', 'MultiPolygon'), true)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD Boundary API: Nominatim returned ' . $geometry['type'] . ' instead of Polygon');
            }
            return null;
        }

        $display_name = isset($feature['properties']['display_name']) ? $feature['properties']['display_name'] : '';

        // Calculate bounding box from geometry
        $bbox = self::calculate_bbox($geometry);

        return array(
            'geometry' => $geometry,
            'bbox' => $bbox,
            'display_name' => $display_name
        );
    }

    /**
     * Calculate bounding box from GeoJSON geometry
     *
     * @param array $geometry GeoJSON geometry
     * @return array Bounding box with north, south, east, west
     */
    private static function calculate_bbox($geometry) {
        $bbox = array(
            'north' => -90,
            'south' => 90,
            'east' => -180,
            'west' => 180
        );

        $process_ring = function($ring) use (&$bbox) {
            foreach ($ring as $coord) {
                // GeoJSON format: [longitude, latitude]
                $lon = $coord[0];
                $lat = $coord[1];
                $bbox['west'] = min($bbox['west'], $lon);
                $bbox['east'] = max($bbox['east'], $lon);
                $bbox['south'] = min($bbox['south'], $lat);
                $bbox['north'] = max($bbox['north'], $lat);
            }
        };

        if ($geometry['type'] === 'Polygon') {
            $process_ring($geometry['coordinates'][0]);
        } elseif ($geometry['type'] === 'MultiPolygon') {
            foreach ($geometry['coordinates'] as $polygon) {
                $process_ring($polygon[0]);
            }
        }

        return $bbox;
    }

    /**
     * Cache boundary in database
     *
     * @param string $location Location name
     * @param string $state State name
     * @param string $type Boundary type
     * @param array $boundary Boundary data
     */
    private static function cache_boundary($location, $state, $type, $boundary) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'mld_city_boundaries';

        $data = array(
            'city' => $location,
            'state' => $state,
            'country' => 'USA',
            'boundary_type' => $type,
            'display_name' => isset($boundary['display_name']) ? $boundary['display_name'] : $location,
            'boundary_data' => json_encode($boundary['geometry']),
            'bbox_north' => $boundary['bbox']['north'],
            'bbox_south' => $boundary['bbox']['south'],
            'bbox_east' => $boundary['bbox']['east'],
            'bbox_west' => $boundary['bbox']['west'],
            'fetched_at' => current_time('mysql'),
            'last_used' => current_time('mysql')
        );

        $wpdb->replace(
            $table_name,
            $data,
            array('%s', '%s', '%s', '%s', '%s', '%s', '%f', '%f', '%f', '%f', '%s', '%s')
        );
    }

    // =========================================================================
    // AGENT & USER TYPE HANDLERS (Added v6.32.0)
    // =========================================================================

    /**
     * Handle get all active agents
     *
     * GET /agents
     *
     * @since 6.32.0
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public static function handle_get_agents($request) {
        // Include the required files if not already loaded
        if (!class_exists('MLD_Agent_Client_Manager')) {
            $includes_path = plugin_dir_path(__FILE__) . 'saved-searches/class-mld-agent-client-manager.php';
            if (file_exists($includes_path)) {
                require_once $includes_path;
            }
        }

        if (!class_exists('MLD_Agent_Client_Manager')) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'agent_manager_unavailable',
                'message' => 'Agent management system is not available.'
            ), 500);
        }

        $agents = MLD_Agent_Client_Manager::get_all_agents_for_api();

        return new WP_REST_Response(array(
            'success' => true,
            'data' => $agents,
            'total' => count($agents)
        ), 200);
    }

    /**
     * Handle get single agent profile
     *
     * GET /agents/{id}
     *
     * @since 6.32.0
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public static function handle_get_agent($request) {
        $agent_id = absint($request->get_param('id'));

        if ($agent_id <= 0) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'invalid_agent_id',
                'message' => 'Invalid agent ID.'
            ), 400);
        }

        // Include the required files if not already loaded
        if (!class_exists('MLD_Agent_Client_Manager')) {
            $includes_path = plugin_dir_path(__FILE__) . 'saved-searches/class-mld-agent-client-manager.php';
            if (file_exists($includes_path)) {
                require_once $includes_path;
            }
        }

        if (!class_exists('MLD_Agent_Client_Manager')) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'agent_manager_unavailable',
                'message' => 'Agent management system is not available.'
            ), 500);
        }

        $agent = MLD_Agent_Client_Manager::get_agent_for_api($agent_id);

        if (!$agent) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'agent_not_found',
                'message' => 'Agent not found.'
            ), 404);
        }

        return new WP_REST_Response(array(
            'success' => true,
            'data' => $agent
        ), 200);
    }

    /**
     * Handle get current user's assigned agent
     *
     * GET /my-agent
     *
     * @since 6.32.0
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public static function handle_get_my_agent($request) {
        // Send no-cache headers for authenticated endpoint
        self::send_no_cache_headers();

        $user = wp_get_current_user();

        // Include the required files if not already loaded
        if (!class_exists('MLD_Agent_Client_Manager')) {
            $includes_path = plugin_dir_path(__FILE__) . 'saved-searches/class-mld-agent-client-manager.php';
            if (file_exists($includes_path)) {
                require_once $includes_path;
            }
        }

        if (!class_exists('MLD_Agent_Client_Manager')) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'agent_manager_unavailable',
                'message' => 'Agent management system is not available.'
            ), 500);
        }

        $agent_data = MLD_Agent_Client_Manager::get_client_agent($user->ID);

        if (!$agent_data) {
            return new WP_REST_Response(array(
                'success' => true,
                'data' => null,
                'message' => 'No agent assigned.'
            ), 200);
        }

        $agent = MLD_Agent_Client_Manager::get_agent_for_api($agent_data['user_id']);

        return new WP_REST_Response(array(
            'success' => true,
            'data' => $agent
        ), 200);
    }

    /**
     * Handle get enhanced user profile
     *
     * GET /users/me
     *
     * Returns enhanced user data including user type and assigned agent.
     * This is the v6.32.0 enhanced version of /auth/me.
     *
     * @since 6.32.0
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public static function handle_get_user_me($request) {
        // Send no-cache headers for authenticated endpoint
        self::send_no_cache_headers();

        $user = wp_get_current_user();

        // Include required files
        $base_path = plugin_dir_path(__FILE__);

        if (!class_exists('MLD_User_Type_Manager')) {
            $type_path = dirname($base_path) . '/class-mld-user-type-manager.php';
            if (file_exists($type_path)) {
                require_once $type_path;
            }
        }

        if (!class_exists('MLD_Agent_Client_Manager')) {
            $agent_path = $base_path . 'saved-searches/class-mld-agent-client-manager.php';
            if (file_exists($agent_path)) {
                require_once $agent_path;
            }
        }

        // Get user type info
        $user_type = 'client';
        $is_agent = false;
        $is_admin = false;
        $agent_profile_id = null;

        if (class_exists('MLD_User_Type_Manager')) {
            $type_data = MLD_User_Type_Manager::get_user_type_for_api($user->ID);
            $user_type = $type_data['type'] ?? 'client';
            $is_agent = $type_data['is_agent'] ?? false;
            $is_admin = $type_data['is_admin'] ?? false;
            $agent_profile_id = $type_data['agent_profile_id'] ?? null;
        }

        // Get assigned agent (for clients)
        $assigned_agent = null;
        if (class_exists('MLD_Agent_Client_Manager') && !$is_agent) {
            $agent_data = MLD_Agent_Client_Manager::get_client_agent($user->ID);
            if ($agent_data) {
                $assigned_agent = MLD_Agent_Client_Manager::get_agent_for_api($agent_data['user_id']);
            }
        }

        // Get agent's own profile (for agents)
        $agent_profile = null;
        if ($is_agent && class_exists('MLD_Agent_Client_Manager')) {
            $agent_profile = MLD_Agent_Client_Manager::get_agent_for_api($user->ID);
        }

        // Build response
        $response_data = array(
            'id' => $user->ID,
            'email' => $user->user_email,
            'name' => $user->display_name,
            'first_name' => get_user_meta($user->ID, 'first_name', true) ?: null,
            'last_name' => get_user_meta($user->ID, 'last_name', true) ?: null,
            'phone' => get_user_meta($user->ID, 'phone', true) ?: null,
            'registered_at' => $user->user_registered,

            // User type info
            'user_type' => $user_type,
            'is_agent' => $is_agent,
            'is_admin' => $is_admin,

            // Agent-related data
            'assigned_agent' => $assigned_agent,
            'agent_profile_id' => $agent_profile_id,
            'agent_profile' => $agent_profile,
        );

        return new WP_REST_Response(array(
            'success' => true,
            'data' => $response_data
        ), 200);
    }

    // =========================================================================
    // AGENT COLLABORATION HANDLERS (Added v6.32.0 Phase 2)
    // =========================================================================

    /**
     * Ensure collaboration dependencies are loaded
     *
     * @since 6.32.0
     */
    private static function ensure_collaboration_classes() {
        $base_path = plugin_dir_path(__FILE__);

        if (!class_exists('MLD_User_Type_Manager')) {
            $type_path = dirname($base_path) . '/class-mld-user-type-manager.php';
            if (file_exists($type_path)) {
                require_once $type_path;
            }
        }

        if (!class_exists('MLD_Agent_Client_Manager')) {
            $agent_path = $base_path . 'saved-searches/class-mld-agent-client-manager.php';
            if (file_exists($agent_path)) {
                require_once $agent_path;
            }
        }

        if (!class_exists('MLD_Saved_Search_Collaboration')) {
            $collab_path = $base_path . 'saved-searches/class-mld-saved-search-collaboration.php';
            if (file_exists($collab_path)) {
                require_once $collab_path;
            }
        }
    }

    /**
     * Handle get agent's clients with search summaries
     *
     * GET /agent/clients
     *
     * @since 6.32.0
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public static function handle_get_agent_clients($request) {
        self::send_no_cache_headers();
        self::ensure_collaboration_classes();

        $user = wp_get_current_user();

        // Verify user is an agent
        if (!class_exists('MLD_User_Type_Manager') || !MLD_User_Type_Manager::is_agent($user->ID)) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'not_an_agent',
                'message' => 'Only agents can access client data.'
            ), 403);
        }

        if (!class_exists('MLD_Saved_Search_Collaboration')) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'collaboration_unavailable',
                'message' => 'Collaboration system is not available.'
            ), 500);
        }

        $raw_clients = MLD_Saved_Search_Collaboration::get_agent_clients_with_searches($user->ID);

        // PERFORMANCE FIX v6.54.3: Batch fetch all data instead of N+1 queries
        // Before: 4 queries per client (user, phone meta, liked prefs, disliked prefs)
        // After: 3 queries total (users batch, phone meta batch, preferences batch)

        // Extract all client IDs
        $client_ids = array_map(function($c) { return (int) $c['client_id']; }, $raw_clients);

        // Batch fetch user objects
        $users_by_id = [];
        if (!empty($client_ids)) {
            $users = get_users(array('include' => $client_ids, 'fields' => 'all'));
            foreach ($users as $u) {
                $users_by_id[$u->ID] = $u;
            }
        }

        // Batch fetch phone numbers
        global $wpdb;
        $phones_by_id = [];
        if (!empty($client_ids)) {
            $placeholders = implode(',', array_fill(0, count($client_ids), '%d'));
            $phone_results = $wpdb->get_results($wpdb->prepare(
                "SELECT user_id, meta_value FROM {$wpdb->usermeta}
                 WHERE user_id IN ($placeholders) AND meta_key = 'phone'",
                $client_ids
            ));
            foreach ($phone_results as $row) {
                $phones_by_id[(int)$row->user_id] = $row->meta_value;
            }
        }

        // Batch fetch preference counts
        $pref_stats = [];
        if (class_exists('MLD_Property_Preferences') && !empty($client_ids)) {
            $pref_stats = MLD_Property_Preferences::get_preference_stats_batch($client_ids);
        }

        // Transform to iOS-compatible format
        $clients = array();
        foreach ($raw_clients as $client) {
            $client_id = (int) $client['client_id'];
            $client_user = isset($users_by_id[$client_id]) ? $users_by_id[$client_id] : null;

            $clients[] = array(
                'id' => $client_id,
                'email' => $client['user_email'],
                'first_name' => $client_user ? $client_user->first_name : null,
                'last_name' => $client_user ? $client_user->last_name : null,
                'phone' => isset($phones_by_id[$client_id]) ? $phones_by_id[$client_id] : null,
                'searches_count' => (int) $client['total_searches'],
                'favorites_count' => isset($pref_stats[$client_id]) ? $pref_stats[$client_id]['liked'] : 0,
                'hidden_count' => isset($pref_stats[$client_id]) ? $pref_stats[$client_id]['disliked'] : 0,
                'last_activity' => $client['last_search_date'],
                'assigned_at' => $client['assigned_date'],
            );
        }

        return new WP_REST_Response(array(
            'success' => true,
            'data' => array(
                'clients' => $clients,
                'count' => count($clients)
            )
        ), 200);
    }

    /**
     * Handle get client's searches (for agent)
     *
     * GET /agent/clients/{client_id}/searches
     *
     * @since 6.32.0
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public static function handle_get_client_searches($request) {
        self::send_no_cache_headers();
        self::ensure_collaboration_classes();

        $user = wp_get_current_user();
        $client_id = absint($request->get_param('client_id'));

        // Verify user is an agent
        if (!class_exists('MLD_User_Type_Manager') || !MLD_User_Type_Manager::is_agent($user->ID)) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'not_an_agent',
                'message' => 'Only agents can access client searches.'
            ), 403);
        }

        if (!class_exists('MLD_Saved_Search_Collaboration')) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'collaboration_unavailable',
                'message' => 'Collaboration system is not available.'
            ), 500);
        }

        $args = array(
            'per_page' => absint($request->get_param('per_page')) ?: 20,
            'page' => absint($request->get_param('page')) ?: 1,
            'status' => sanitize_text_field($request->get_param('status')),
        );

        $result = MLD_Saved_Search_Collaboration::get_client_searches($user->ID, $client_id, $args);

        if (is_wp_error($result)) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => $result->get_error_code(),
                'message' => $result->get_error_message()
            ), $result->get_error_data()['status'] ?? 400);
        }

        // Cast types for iOS compatibility
        $searches = array_map(function($search) {
            return array(
                'id' => (int) ($search['id'] ?? 0),
                'name' => $search['name'] ?? '',
                'filters' => $search['filters'] ?? null,
                'notification_frequency' => $search['notification_frequency'] ?? $search['frequency'] ?? 'daily',
                'is_active' => (bool) ($search['is_active'] ?? true),
                'last_matched_count' => (int) ($search['last_matched_count'] ?? 0),
                'created_at' => $search['created_at'] ?? null,
            );
        }, $result['searches'] ?? []);

        return new WP_REST_Response(array(
            'success' => true,
            'data' => $searches,
            'total' => (int) $result['total'],
            'pages' => (int) $result['pages'],
            'page' => (int) $result['page']
        ), 200);
    }

    /**
     * Handle create saved search for client (as agent)
     *
     * POST /agent/clients/{client_id}/searches
     *
     * @since 6.32.0
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public static function handle_create_search_for_client($request) {
        self::send_no_cache_headers();
        self::ensure_collaboration_classes();

        $user = wp_get_current_user();
        $client_id = absint($request->get_param('client_id'));

        // Verify user is an agent
        if (!class_exists('MLD_User_Type_Manager') || !MLD_User_Type_Manager::is_agent($user->ID)) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'not_an_agent',
                'message' => 'Only agents can create searches for clients.'
            ), 403);
        }

        if (!class_exists('MLD_Saved_Search_Collaboration')) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'collaboration_unavailable',
                'message' => 'Collaboration system is not available.'
            ), 500);
        }

        $body = $request->get_json_params();

        $search_data = array(
            'name' => $body['name'] ?? '',
            'description' => $body['description'] ?? '',
            'filters' => $body['filters'] ?? array(),
            'polygon_shapes' => $body['polygon_shapes'] ?? null,
            'notification_frequency' => self::normalize_frequency($body['notification_frequency'] ?? 'daily'),
            'is_active' => isset($body['is_active']) ? (bool) $body['is_active'] : true,
            'agent_notes' => $body['agent_notes'] ?? '',
            'cc_agent_on_notify' => isset($body['cc_agent_on_notify']) ? (bool) $body['cc_agent_on_notify'] : true,
        );

        // Normalize filter arrays
        if (!empty($search_data['filters'])) {
            $search_data['filters'] = self::normalize_filter_arrays($search_data['filters']);
        }

        $result = MLD_Saved_Search_Collaboration::create_search_for_client($user->ID, $client_id, $search_data);

        if (is_wp_error($result)) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => $result->get_error_code(),
                'message' => $result->get_error_message()
            ), $result->get_error_data()['status'] ?? 400);
        }

        // Get the created search
        $search = MLD_Saved_Search_Collaboration::get_search($result, $user->ID);

        return new WP_REST_Response(array(
            'success' => true,
            'message' => 'Saved search created successfully for client.',
            'data' => is_wp_error($search) ? array('id' => $result) : $search
        ), 201);
    }

    /**
     * Handle batch create saved searches for multiple clients (as agent)
     *
     * POST /agent/searches/batch
     *
     * Request body:
     * {
     *     "client_ids": [1, 2, 3],
     *     "name": "Search Name",
     *     "description": "Optional description",
     *     "filters": {...},
     *     "polygon_shapes": [...],
     *     "notification_frequency": "daily",
     *     "agent_notes": "Note to clients",
     *     "cc_agent_on_notify": true
     * }
     *
     * @since 6.36.0
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public static function handle_create_searches_for_clients_batch($request) {
        self::send_no_cache_headers();
        self::ensure_collaboration_classes();

        $user = wp_get_current_user();

        // Verify user is an agent
        if (!class_exists('MLD_User_Type_Manager') || !MLD_User_Type_Manager::is_agent($user->ID)) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'not_an_agent',
                'message' => 'Only agents can create searches for clients.'
            ), 403);
        }

        if (!class_exists('MLD_Saved_Search_Collaboration')) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'collaboration_unavailable',
                'message' => 'Collaboration system is not available.'
            ), 500);
        }

        $body = $request->get_json_params();
        $client_ids = isset($body['client_ids']) ? array_map('absint', (array) $body['client_ids']) : array();

        if (empty($client_ids)) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'no_clients',
                'message' => 'At least one client must be specified.'
            ), 400);
        }

        $search_data = array(
            'name' => sanitize_text_field($body['name'] ?? ''),
            'description' => sanitize_textarea_field($body['description'] ?? ''),
            'filters' => $body['filters'] ?? array(),
            'polygon_shapes' => $body['polygon_shapes'] ?? null,
            'notification_frequency' => self::normalize_frequency($body['notification_frequency'] ?? 'daily'),
            'is_active' => isset($body['is_active']) ? (bool) $body['is_active'] : true,
            'agent_notes' => sanitize_textarea_field($body['agent_notes'] ?? ''),
            'cc_agent_on_notify' => isset($body['cc_agent_on_notify']) ? (bool) $body['cc_agent_on_notify'] : true,
        );

        // Normalize filter arrays
        if (!empty($search_data['filters'])) {
            $search_data['filters'] = self::normalize_filter_arrays($search_data['filters']);
        }

        $created_searches = array();
        $errors = array();
        $notifications_sent = array('push' => 0, 'email' => 0);

        foreach ($client_ids as $client_id) {
            $result = MLD_Saved_Search_Collaboration::create_search_for_client($user->ID, $client_id, $search_data);

            if (is_wp_error($result)) {
                $errors[] = array(
                    'client_id' => $client_id,
                    'error' => $result->get_error_message()
                );
            } else {
                // Get the formatted search
                $search = MLD_Saved_Search_Collaboration::get_search($result, $user->ID);
                $created_searches[] = is_wp_error($search) ? array('id' => $result) : $search;

                // Send push notification to client
                $notif_result = self::send_search_created_notification($user->ID, $client_id, $result, $search_data);
                $notifications_sent['push'] += $notif_result['push'];
                $notifications_sent['email'] += $notif_result['email'];
            }
        }

        $success = count($created_searches) > 0;
        $message = sprintf(
            'Created %d search%s for %d client%s.',
            count($created_searches),
            count($created_searches) === 1 ? '' : 'es',
            count($client_ids),
            count($client_ids) === 1 ? '' : 's'
        );

        // Extract search IDs for iOS compatibility (must be integers)
        $search_ids = array_map(function($search) {
            return (int) (is_array($search) ? ($search['id'] ?? 0) : $search);
        }, $created_searches);

        return new WP_REST_Response(array(
            'success' => $success,
            'message' => $message,
            'data' => array(
                'created_count' => count($created_searches),
                'search_ids' => $search_ids,
                'searches' => $created_searches,
                'errors' => $errors,
                'notifications_sent' => $notifications_sent
            )
        ), $success ? 201 : 400);
    }

    /**
     * Send push notification when agent creates a search for client
     *
     * @since 6.36.0
     * @param int $agent_id Agent's user ID
     * @param int $client_id Client's user ID
     * @param int $search_id Created search ID
     * @param array $search_data Search details
     * @return array Notification counts ['push' => int, 'email' => int]
     */
    public static function send_search_created_notification($agent_id, $client_id, $search_id, $search_data) {
        $results = array('push' => 0, 'email' => 0);

        // Get agent name
        $agent = get_user_by('id', $agent_id);
        $agent_name = $agent ? $agent->display_name : 'Your agent';

        // Build notification content
        $title = "New Search from {$agent_name}";
        $body = !empty($search_data['agent_notes'])
            ? $search_data['agent_notes']
            : "Check out '{$search_data['name']}'";

        // Truncate body if too long
        if (strlen($body) > 100) {
            $body = substr($body, 0, 97) . '...';
        }

        // Send push notification using SNAB if available
        if (function_exists('snab_push_notifications')) {
            $push = snab_push_notifications();
            $devices = $push->get_user_devices($client_id);

            if (!empty($devices)) {
                $data = array(
                    'type' => 'saved_search_created',
                    'search_id' => $search_id,
                    'agent_id' => $agent_id,
                );

                foreach ($devices as $device) {
                    $sent = $push->send_notification(
                        $device->device_token,
                        $title,
                        $body,
                        $data,
                        (bool) $device->is_sandbox
                    );

                    if ($sent === true) {
                        $results['push']++;
                        break; // One success per user is enough
                    }
                }
            }
        }

        // Trigger action for email notifications (can be hooked by other code)
        do_action('mld_agent_search_created_for_client', $agent_id, $client_id, $search_id, $search_data);

        return $results;
    }

    /**
     * Handle get all client searches for agent
     *
     * GET /agent/searches
     *
     * @since 6.32.0
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public static function handle_get_agent_all_searches($request) {
        self::send_no_cache_headers();
        self::ensure_collaboration_classes();

        $user = wp_get_current_user();

        // Verify user is an agent
        if (!class_exists('MLD_User_Type_Manager') || !MLD_User_Type_Manager::is_agent($user->ID)) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'not_an_agent',
                'message' => 'Only agents can access client searches.'
            ), 403);
        }

        if (!class_exists('MLD_Saved_Search_Collaboration')) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'collaboration_unavailable',
                'message' => 'Collaboration system is not available.'
            ), 500);
        }

        $args = array(
            'per_page' => absint($request->get_param('per_page')) ?: 50,
            'page' => absint($request->get_param('page')) ?: 1,
            'client_id' => absint($request->get_param('client_id')) ?: null,
            'status' => sanitize_text_field($request->get_param('status')),
            'is_agent_recommended' => $request->get_param('agent_recommended') === 'true' ? true : null,
        );

        $result = MLD_Saved_Search_Collaboration::get_all_client_searches($user->ID, $args);

        return new WP_REST_Response(array(
            'success' => true,
            'data' => $result['searches'],
            'total' => $result['total'],
            'pages' => $result['pages'],
            'page' => $result['page']
        ), 200);
    }

    /**
     * Handle get agent dashboard metrics
     *
     * GET /agent/metrics
     *
     * @since 6.32.0
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public static function handle_get_agent_metrics($request) {
        self::send_no_cache_headers();
        self::ensure_collaboration_classes();

        $user = wp_get_current_user();

        // Verify user is an agent
        if (!class_exists('MLD_User_Type_Manager') || !MLD_User_Type_Manager::is_agent($user->ID)) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'not_an_agent',
                'message' => 'Only agents can access metrics.'
            ), 403);
        }

        if (!class_exists('MLD_Saved_Search_Collaboration')) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'collaboration_unavailable',
                'message' => 'Collaboration system is not available.'
            ), 500);
        }

        $period = sanitize_text_field($request->get_param('period')) ?: 'month';
        if (!in_array($period, array('week', 'month', 'quarter', 'year'))) {
            $period = 'month';
        }

        $raw_metrics = MLD_Saved_Search_Collaboration::get_agent_metrics($user->ID, $period);

        // Get clients list to calculate aggregate metrics
        $raw_clients = MLD_Saved_Search_Collaboration::get_agent_clients_with_searches($user->ID);

        // PERFORMANCE FIX v6.54.3: Batch fetch preference counts instead of N+1 queries
        // Before: 2 queries per client (liked prefs, disliked prefs)
        // After: 1 query total for all clients

        // Extract all client IDs
        $client_ids = array_map(function($c) { return (int) $c['client_id']; }, $raw_clients);

        // Batch fetch preference counts
        $pref_stats = [];
        if (class_exists('MLD_Property_Preferences') && !empty($client_ids)) {
            $pref_stats = MLD_Property_Preferences::get_preference_stats_batch($client_ids);
        }

        // Calculate totals across all clients
        $total_searches = 0;
        $total_favorites = 0;
        $total_hidden = 0;
        $active_clients = 0;
        // Use WordPress timezone (current_time) instead of server timezone (date)
        // See CLAUDE.md Pitfall #10: WordPress Timezone vs PHP Timezone
        $week_ago = date('Y-m-d H:i:s', current_time('timestamp') - WEEK_IN_SECONDS);

        foreach ($raw_clients as $client) {
            $client_id = (int) $client['client_id'];
            $total_searches += (int) $client['total_searches'];

            // Count active clients (active = has recent activity)
            if (!empty($client['last_search_date']) && $client['last_search_date'] > $week_ago) {
                $active_clients++;
            }

            // Use batch-fetched preference counts
            if (isset($pref_stats[$client_id])) {
                $total_favorites += $pref_stats[$client_id]['liked'];
                $total_hidden += $pref_stats[$client_id]['disliked'];
            }
        }

        // Transform to iOS-compatible format
        $metrics = array(
            'total_clients' => (int) ($raw_metrics['total_clients'] ?? count($raw_clients)),
            'active_clients' => $active_clients,
            'total_searches' => $total_searches,
            'total_favorites' => $total_favorites,
            'total_hidden' => $total_hidden,
            'new_clients_this_month' => null, // Not yet tracked
            'active_searches_this_week' => (int) ($raw_metrics['total_active_searches'] ?? 0),
        );

        return new WP_REST_Response(array(
            'success' => true,
            'data' => $metrics
        ), 200);
    }

    /**
     * Handle create a new client (as agent)
     *
     * POST /agent/clients
     *
     * @since 6.32.0
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public static function handle_create_client($request) {
        self::send_no_cache_headers();
        self::ensure_collaboration_classes();

        $user = wp_get_current_user();

        // Verify user is an agent
        if (!class_exists('MLD_User_Type_Manager') || !MLD_User_Type_Manager::is_agent($user->ID)) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'not_an_agent',
                'message' => 'Only agents can create clients.'
            ), 403);
        }

        // Get request data
        $email = sanitize_email($request->get_param('email'));
        $first_name = sanitize_text_field($request->get_param('first_name'));
        $last_name = sanitize_text_field($request->get_param('last_name'));
        $phone = sanitize_text_field($request->get_param('phone'));
        $send_notification = (bool) $request->get_param('send_notification');

        // Validate required fields
        if (empty($email) || empty($first_name) || empty($last_name)) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'missing_fields',
                'message' => 'Email, first name, and last name are required.'
            ), 400);
        }

        // Create the client
        $client_data = array(
            'email' => $email,
            'first_name' => $first_name,
            'last_name' => $last_name,
            'phone' => $phone,
            'send_notification' => $send_notification
        );

        $result = MLD_Agent_Client_Manager::create_client($client_data);

        if (is_wp_error($result)) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => $result->get_error_code(),
                'message' => $result->get_error_message()
            ), 400);
        }

        // Handle both array (new format) and int (legacy) return values
        $user_id = is_array($result) ? $result['user_id'] : $result;
        $email_sent = is_array($result) ? $result['email_sent'] : true;

        // Auto-assign the new client to this agent
        MLD_Agent_Client_Manager::assign_agent_to_client($user->ID, $user_id);

        // Get the client details to return
        $client = get_user_by('id', $user_id);
        $now = current_time('mysql');

        // Build success message including email status
        $message = 'Client created and assigned successfully.';
        if ($send_notification && !$email_sent) {
            $message .= ' Note: Welcome email could not be sent.';
        }

        // Return in same format as GET /agent/clients - iOS expects 'client' wrapper
        return new WP_REST_Response(array(
            'success' => true,
            'data' => array(
                'client' => array(
                    'id' => $client->ID,
                    'email' => $client->user_email,
                    'first_name' => $client->first_name,
                    'last_name' => $client->last_name,
                    'phone' => get_user_meta($user_id, 'phone', true) ?: get_user_meta($user_id, 'phone_number', true),
                    'searches_count' => 0,
                    'favorites_count' => 0,
                    'hidden_count' => 0,
                    'last_activity' => null,
                    'assigned_at' => $now,
                ),
                'message' => $message,
                'email_sent' => $email_sent
            )
        ), 201);
    }

    /**
     * Handle get single client details (for agent)
     *
     * GET /agent/clients/{client_id}
     *
     * @since 6.32.0
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public static function handle_get_client_detail($request) {
        self::send_no_cache_headers();
        self::ensure_collaboration_classes();

        $user = wp_get_current_user();
        $client_id = absint($request->get_param('client_id'));

        // Verify user is an agent
        if (!class_exists('MLD_User_Type_Manager') || !MLD_User_Type_Manager::is_agent($user->ID)) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'not_an_agent',
                'message' => 'Only agents can access client data.'
            ), 403);
        }

        // Verify client exists
        $client = get_user_by('id', $client_id);
        if (!$client) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'client_not_found',
                'message' => 'Client not found.'
            ), 404);
        }

        // Verify agent has access to this client
        $clients = MLD_Agent_Client_Manager::get_agent_clients($user->ID, 'active');
        $client_ids = array_column($clients, 'client_id');
        if (!in_array($client_id, $client_ids)) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'unauthorized',
                'message' => 'You do not have access to this client.'
            ), 403);
        }

        // Get client stats
        global $wpdb;
        $saved_searches_table = $wpdb->prefix . 'mld_saved_searches';
        $favorites_table = $wpdb->prefix . 'mld_user_favorites';
        $hidden_table = $wpdb->prefix . 'mld_hidden_properties';

        $searches_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$saved_searches_table} WHERE user_id = %d AND is_active = 1",
            $client_id
        ));

        $favorites_count = 0;
        if ($wpdb->get_var("SHOW TABLES LIKE '{$favorites_table}'") == $favorites_table) {
            $favorites_count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$favorites_table} WHERE user_id = %d",
                $client_id
            ));
        }

        $hidden_count = 0;
        if ($wpdb->get_var("SHOW TABLES LIKE '{$hidden_table}'") == $hidden_table) {
            $hidden_count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$hidden_table} WHERE user_id = %d",
                $client_id
            ));
        }

        return new WP_REST_Response(array(
            'success' => true,
            'data' => array(
                'id' => $client->ID,
                'email' => $client->user_email,
                'name' => $client->display_name,
                'first_name' => $client->first_name,
                'last_name' => $client->last_name,
                'phone' => get_user_meta($client_id, 'phone_number', true),
                'registered' => $client->user_registered,
                'stats' => array(
                    'saved_searches' => (int) $searches_count,
                    'favorites' => (int) $favorites_count,
                    'hidden' => (int) $hidden_count
                )
            )
        ), 200);
    }

    /**
     * Handle get client's favorites (for agent)
     *
     * GET /agent/clients/{client_id}/favorites
     *
     * @since 6.32.0
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public static function handle_get_client_favorites($request) {
        self::send_no_cache_headers();
        self::ensure_collaboration_classes();

        $user = wp_get_current_user();
        $client_id = absint($request->get_param('client_id'));

        // Verify user is an agent
        if (!class_exists('MLD_User_Type_Manager') || !MLD_User_Type_Manager::is_agent($user->ID)) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'not_an_agent',
                'message' => 'Only agents can access client data.'
            ), 403);
        }

        // Verify agent has access to this client
        $clients = MLD_Agent_Client_Manager::get_agent_clients($user->ID, 'active');
        $client_ids = array_column($clients, 'client_id');
        if (!in_array($client_id, $client_ids)) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'unauthorized',
                'message' => 'You do not have access to this client.'
            ), 403);
        }

        // Get client's favorites from property_preferences table
        global $wpdb;
        $prefs_table = $wpdb->prefix . 'mld_property_preferences';
        $summary_table = $wpdb->prefix . 'bme_listing_summary';

        // Get favorites (preference_type = 'liked') with property details
        $favorites = $wpdb->get_results($wpdb->prepare(
            "SELECT p.listing_id, p.created_at as added_at, s.listing_key, s.street_number, s.street_name,
                    s.city, s.state_or_province, s.list_price, s.bedrooms_total,
                    s.bathrooms_total, s.main_photo_url, s.standard_status
             FROM {$prefs_table} p
             LEFT JOIN {$summary_table} s ON p.listing_id = s.listing_id
             WHERE p.user_id = %d AND p.preference_type = 'liked'
             ORDER BY p.created_at DESC",
            $client_id
        ), ARRAY_A);

        // Format the response - use field names iOS expects
        $formatted = array_map(function($fav) {
            return array(
                'id' => $fav['listing_key'] ?: $fav['listing_id'],
                'listing_key' => $fav['listing_key'] ?? '',
                'listing_id' => $fav['listing_id'],
                'address' => trim(($fav['street_number'] ?? '') . ' ' . ($fav['street_name'] ?? '')),
                'city' => $fav['city'] ?? '',
                'state' => $fav['state_or_province'] ?? '',
                'list_price' => (int) ($fav['list_price'] ?? 0),
                'beds' => (int) ($fav['bedrooms_total'] ?? 0),
                'baths' => (float) ($fav['bathrooms_total'] ?? 0),
                'photo_url' => $fav['main_photo_url'] ?? '',
                'status' => $fav['standard_status'] ?? '',
                'added_at' => $fav['added_at']
            );
        }, $favorites);

        return new WP_REST_Response(array(
            'success' => true,
            'data' => $formatted,
            'total' => count($formatted)
        ), 200);
    }

    /**
     * Handle get client's hidden properties (for agent)
     *
     * GET /agent/clients/{client_id}/hidden
     *
     * @since 6.32.0
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public static function handle_get_client_hidden($request) {
        self::send_no_cache_headers();
        self::ensure_collaboration_classes();

        $user = wp_get_current_user();
        $client_id = absint($request->get_param('client_id'));

        // Verify user is an agent
        if (!class_exists('MLD_User_Type_Manager') || !MLD_User_Type_Manager::is_agent($user->ID)) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'not_an_agent',
                'message' => 'Only agents can access client data.'
            ), 403);
        }

        // Verify agent has access to this client
        $clients = MLD_Agent_Client_Manager::get_agent_clients($user->ID, 'active');
        $client_ids = array_column($clients, 'client_id');
        if (!in_array($client_id, $client_ids)) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'unauthorized',
                'message' => 'You do not have access to this client.'
            ), 403);
        }

        // Get client's hidden properties from property_preferences table
        global $wpdb;
        $prefs_table = $wpdb->prefix . 'mld_property_preferences';
        $summary_table = $wpdb->prefix . 'bme_listing_summary';

        // Get hidden (preference_type = 'disliked') with property details
        $hidden = $wpdb->get_results($wpdb->prepare(
            "SELECT p.listing_id, p.created_at as hidden_at, s.listing_key, s.street_number, s.street_name,
                    s.city, s.state_or_province, s.list_price, s.bedrooms_total,
                    s.bathrooms_total, s.main_photo_url, s.standard_status
             FROM {$prefs_table} p
             LEFT JOIN {$summary_table} s ON p.listing_id = s.listing_id
             WHERE p.user_id = %d AND p.preference_type = 'disliked'
             ORDER BY p.created_at DESC",
            $client_id
        ), ARRAY_A);

        // Format the response - use field names iOS expects
        $formatted = array_map(function($item) {
            return array(
                'id' => $item['listing_key'] ?: $item['listing_id'],
                'listing_key' => $item['listing_key'] ?? '',
                'listing_id' => $item['listing_id'],
                'address' => trim(($item['street_number'] ?? '') . ' ' . ($item['street_name'] ?? '')),
                'city' => $item['city'] ?? '',
                'state' => $item['state_or_province'] ?? '',
                'list_price' => (int) ($item['list_price'] ?? 0),
                'beds' => (int) ($item['bedrooms_total'] ?? 0),
                'baths' => (float) ($item['bathrooms_total'] ?? 0),
                'photo_url' => $item['main_photo_url'] ?? '',
                'status' => $item['standard_status'] ?? '',
                'hidden_at' => $item['hidden_at']
            );
        }, $hidden);

        return new WP_REST_Response(array(
            'success' => true,
            'data' => $formatted,
            'total' => count($formatted)
        ), 200);
    }

    /**
     * Handle get saved search activity log
     *
     * GET /saved-searches/{id}/activity
     *
     * @since 6.32.0
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public static function handle_get_search_activity($request) {
        self::send_no_cache_headers();
        self::ensure_collaboration_classes();

        $user = wp_get_current_user();
        $search_id = absint($request->get_param('id'));

        if (!class_exists('MLD_Saved_Search_Collaboration')) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'collaboration_unavailable',
                'message' => 'Collaboration system is not available.'
            ), 500);
        }

        // Verify user can access this search
        if (!MLD_Saved_Search_Collaboration::can_access_search($search_id, $user->ID)) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'unauthorized',
                'message' => 'You do not have permission to view this search activity.'
            ), 403);
        }

        $limit = absint($request->get_param('limit')) ?: 50;
        if ($limit > 200) {
            $limit = 200;
        }

        $activity = MLD_Saved_Search_Collaboration::get_activity_log($search_id, $limit);

        return new WP_REST_Response(array(
            'success' => true,
            'data' => $activity,
            'total' => count($activity)
        ), 200);
    }

    /**
     * Handle assign client to agent
     *
     * POST /agent/clients/{client_id}/assign
     *
     * @since 6.32.0
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public static function handle_assign_client($request) {
        self::send_no_cache_headers();
        self::ensure_collaboration_classes();

        $user = wp_get_current_user();
        $client_id = absint($request->get_param('client_id'));

        // Verify current user is an agent
        if (!class_exists('MLD_User_Type_Manager') || !MLD_User_Type_Manager::is_agent($user->ID)) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'not_an_agent',
                'message' => 'Only agents can assign clients.'
            ), 403);
        }

        if (!$client_id) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'missing_client_id',
                'message' => 'Client ID is required.'
            ), 400);
        }

        // Get optional parameters
        $notes = sanitize_textarea_field($request->get_param('notes') ?? '');
        $email_type = sanitize_text_field($request->get_param('email_type') ?? 'none');

        // Validate email_type
        if (!in_array($email_type, array('none', 'cc', 'bcc'))) {
            $email_type = 'none';
        }

        // Assign the client to this agent
        $result = MLD_Agent_Client_Manager::assign_agent_to_client(
            $user->ID,
            $client_id,
            array(
                'notes' => $notes,
                'email_type' => $email_type
            )
        );

        if (is_wp_error($result)) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => $result->get_error_code(),
                'message' => $result->get_error_message()
            ), 400);
        }

        // Get the updated client info
        $client = get_userdata($client_id);
        $client_data = array(
            'id' => $client_id,
            'name' => $client ? $client->display_name : 'Unknown',
            'email' => $client ? $client->user_email : '',
            'assigned' => true
        );

        return new WP_REST_Response(array(
            'success' => true,
            'message' => 'Client assigned successfully.',
            'data' => $client_data
        ), 200);
    }

    /**
     * Handle unassign client from agent
     *
     * DELETE /agent/clients/{client_id}/assign
     *
     * @since 6.32.0
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public static function handle_unassign_client($request) {
        self::send_no_cache_headers();
        self::ensure_collaboration_classes();

        $user = wp_get_current_user();
        $client_id = absint($request->get_param('client_id'));

        // Verify current user is an agent
        if (!class_exists('MLD_User_Type_Manager') || !MLD_User_Type_Manager::is_agent($user->ID)) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'not_an_agent',
                'message' => 'Only agents can unassign clients.'
            ), 403);
        }

        if (!$client_id) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'missing_client_id',
                'message' => 'Client ID is required.'
            ), 400);
        }

        // Verify this agent is actually assigned to this client
        $current_agent = MLD_Agent_Client_Manager::get_client_agent($client_id);
        if (!$current_agent || (int) $current_agent['user_id'] !== $user->ID) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'not_assigned',
                'message' => 'You are not the assigned agent for this client.'
            ), 403);
        }

        // Unassign the client
        $result = MLD_Agent_Client_Manager::unassign_client($user->ID, $client_id);

        if (!$result) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'unassign_failed',
                'message' => 'Failed to unassign client.'
            ), 500);
        }

        return new WP_REST_Response(array(
            'success' => true,
            'message' => 'Client unassigned successfully.',
            'data' => array(
                'client_id' => $client_id,
                'assigned' => false
            )
        ), 200);
    }

    /**
     * Handle get email preferences
     *
     * GET /email-preferences
     *
     * @since 6.32.0
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public static function handle_get_email_preferences($request) {
        self::send_no_cache_headers();

        $user = wp_get_current_user();
        $user_id = $user->ID;

        global $wpdb;
        $table = $wpdb->prefix . 'mld_user_email_preferences';

        // Get user's preferences
        $prefs = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE user_id = %d",
            $user_id
        ), ARRAY_A);

        // If no preferences exist, return defaults
        if (!$prefs) {
            $prefs = array(
                'user_id' => $user_id,
                'digest_enabled' => false,
                'digest_frequency' => 'daily',
                'digest_time' => '08:00:00',
                'preferred_format' => 'html',
                'global_pause' => false,
                'timezone' => 'America/New_York',
                'unsubscribed_at' => null,
            );
        } else {
            // Convert database values to proper types
            $prefs['digest_enabled'] = (bool) $prefs['digest_enabled'];
            $prefs['global_pause'] = (bool) $prefs['global_pause'];
        }

        // Get digest stats
        $stats = array();
        if (class_exists('MLD_Digest_Processor')) {
            $stats = MLD_Digest_Processor::get_user_digest_stats($user_id);
        }

        return new WP_REST_Response(array(
            'success' => true,
            'data' => array(
                'preferences' => $prefs,
                'stats' => $stats,
            ),
        ), 200);
    }

    /**
     * Handle update email preferences
     *
     * POST /email-preferences
     *
     * @since 6.32.0
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public static function handle_update_email_preferences($request) {
        self::send_no_cache_headers();

        $user = wp_get_current_user();
        $user_id = $user->ID;

        global $wpdb;
        $table = $wpdb->prefix . 'mld_user_email_preferences';

        // Get current preferences
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$table} WHERE user_id = %d",
            $user_id
        ));

        // Prepare update data
        $data = array('user_id' => $user_id);
        $format = array('%d');

        // Process each field if provided
        $params = $request->get_json_params();
        if (empty($params)) {
            $params = $request->get_body_params();
        }

        if (isset($params['digest_enabled'])) {
            $data['digest_enabled'] = $params['digest_enabled'] ? 1 : 0;
            $format[] = '%d';
        }

        if (isset($params['digest_frequency'])) {
            $freq = sanitize_text_field($params['digest_frequency']);
            if (in_array($freq, array('daily', 'weekly'))) {
                $data['digest_frequency'] = $freq;
                $format[] = '%s';
            }
        }

        if (isset($params['digest_time'])) {
            $time = sanitize_text_field($params['digest_time']);
            if (preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $time)) {
                $data['digest_time'] = $time;
                $format[] = '%s';
            }
        }

        if (isset($params['preferred_format'])) {
            $format_val = sanitize_text_field($params['preferred_format']);
            if (in_array($format_val, array('html', 'plain'))) {
                $data['preferred_format'] = $format_val;
                $format[] = '%s';
            }
        }

        if (isset($params['global_pause'])) {
            $data['global_pause'] = $params['global_pause'] ? 1 : 0;
            $format[] = '%d';
        }

        if (isset($params['timezone'])) {
            $tz = sanitize_text_field($params['timezone']);
            // Validate timezone
            try {
                new DateTimeZone($tz);
                $data['timezone'] = $tz;
                $format[] = '%s';
            } catch (Exception $e) {
                // Invalid timezone, ignore
            }
        }

        // Handle unsubscribe
        if (isset($params['unsubscribe']) && $params['unsubscribe']) {
            $data['unsubscribed_at'] = current_time('mysql');
            $format[] = '%s';
        } elseif (isset($params['resubscribe']) && $params['resubscribe']) {
            $data['unsubscribed_at'] = null;
            $format[] = '%s';
        }

        // Insert or update
        if ($existing) {
            $result = $wpdb->update(
                $table,
                $data,
                array('user_id' => $user_id),
                $format,
                array('%d')
            );
        } else {
            $result = $wpdb->insert($table, $data, $format);
        }

        if ($result === false) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'update_failed',
                'message' => 'Failed to update email preferences.',
            ), 500);
        }

        // Return updated preferences
        $prefs = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE user_id = %d",
            $user_id
        ), ARRAY_A);

        $prefs['digest_enabled'] = (bool) $prefs['digest_enabled'];
        $prefs['global_pause'] = (bool) $prefs['global_pause'];

        return new WP_REST_Response(array(
            'success' => true,
            'message' => 'Email preferences updated successfully.',
            'data' => array(
                'preferences' => $prefs,
            ),
        ), 200);
    }

    /**
     * Handle email open tracking
     *
     * GET /email/track/open?eid=xxx
     *
     * Returns a 1x1 transparent GIF and records the open.
     *
     * @since 6.32.0
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public static function handle_email_open_tracking($request) {
        $email_id = sanitize_text_field($request->get_param('eid'));

        if ($email_id && class_exists('MLD_Email_Template_Engine')) {
            MLD_Email_Template_Engine::record_open($email_id);
        }

        // Return 1x1 transparent GIF
        $gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');

        header('Content-Type: image/gif');
        header('Content-Length: ' . strlen($gif));
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

        echo $gif;
        exit;
    }

    /**
     * Handle email click tracking
     *
     * GET /email/track/click?eid=xxx&lt=type&url=encoded_url
     *
     * Records the click and redirects to the target URL.
     *
     * @since 6.32.0
     * @param WP_REST_Request $request Request object
     * @return void
     */
    public static function handle_email_click_tracking($request) {
        $email_id = sanitize_text_field($request->get_param('eid'));
        $url = urldecode($request->get_param('url') ?? '');

        if ($email_id && class_exists('MLD_Email_Template_Engine')) {
            MLD_Email_Template_Engine::record_click($email_id);
        }

        // Validate URL
        if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            $url = home_url();
        }

        // Security check - only allow same-domain or trusted domains
        $allowed_domains = array(
            parse_url(home_url(), PHP_URL_HOST),
        );

        $url_host = parse_url($url, PHP_URL_HOST);
        $is_allowed = false;

        foreach ($allowed_domains as $domain) {
            if ($url_host === $domain || strpos($url_host, '.' . $domain) !== false) {
                $is_allowed = true;
                break;
            }
        }

        if (!$is_allowed) {
            $url = home_url();
        }

        // Redirect
        wp_redirect($url);
        exit;
    }

    // ==========================================
    // SHARED PROPERTIES HANDLERS (v6.35.0)
    // ==========================================

    /**
     * Ensure shared properties manager class is loaded
     */
    private static function ensure_shared_properties_class() {
        if (!class_exists('MLD_Shared_Properties_Manager')) {
            $manager_path = plugin_dir_path(__FILE__) . 'shared-properties/class-mld-shared-properties-manager.php';
            if (file_exists($manager_path)) {
                require_once $manager_path;
            }
        }

        // Also load the notifier
        if (!class_exists('MLD_Shared_Properties_Notifier')) {
            $notifier_path = plugin_dir_path(__FILE__) . 'shared-properties/class-mld-shared-properties-notifier.php';
            if (file_exists($notifier_path)) {
                require_once $notifier_path;
            }
        }
    }

    /**
     * Handle agent sharing properties with client(s)
     *
     * POST /shared-properties
     *
     * Request body:
     * - client_id or client_ids: int or array of client user IDs
     * - listing_keys: array of listing key hashes
     * - note: optional agent note
     *
     * @since 6.35.0
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public static function handle_share_properties($request) {
        self::send_no_cache_headers();
        self::ensure_shared_properties_class();
        self::ensure_collaboration_classes();

        $user = wp_get_current_user();

        // Verify user is an agent
        if (!class_exists('MLD_User_Type_Manager') || !MLD_User_Type_Manager::is_agent($user->ID)) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'not_an_agent',
                'message' => 'Only agents can share properties.'
            ), 403);
        }

        if (!class_exists('MLD_Shared_Properties_Manager')) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'sharing_unavailable',
                'message' => 'Property sharing system is not available.'
            ), 500);
        }

        // Get parameters
        $body = $request->get_json_params();

        // Support both single client_id and array of client_ids
        $client_ids = array();
        if (!empty($body['client_ids']) && is_array($body['client_ids'])) {
            $client_ids = array_map('intval', $body['client_ids']);
        } elseif (!empty($body['client_id'])) {
            $client_ids = array(intval($body['client_id']));
        }

        // Get listing keys
        $listing_keys = array();
        if (!empty($body['listing_keys']) && is_array($body['listing_keys'])) {
            $listing_keys = array_map('sanitize_text_field', $body['listing_keys']);
        }

        // Validate required fields
        if (empty($client_ids)) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'missing_clients',
                'message' => 'At least one client_id is required.'
            ), 400);
        }

        if (empty($listing_keys)) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'missing_listings',
                'message' => 'At least one listing_key is required.'
            ), 400);
        }

        $note = sanitize_textarea_field($body['note'] ?? '');

        // Share the properties
        $result = MLD_Shared_Properties_Manager::share_properties(
            $user->ID,
            $client_ids,
            $listing_keys,
            $note
        );

        // Send notifications for new shares
        $notification_totals = array('push' => 0, 'email' => 0);
        if ($result['success'] && !empty($result['shares'])) {
            // Filter to only new shares (not re-shares)
            $new_shares = array_filter($result['shares'], function($s) { return $s['is_new'] ?? false; });

            if (!empty($new_shares) && class_exists('MLD_Shared_Properties_Notifier')) {
                $notifier = mld_shared_properties_notifier();

                // Group shares by client for notifications
                $shares_by_client = array();
                foreach ($new_shares as $share) {
                    $cid = $share['client_id'];
                    if (!isset($shares_by_client[$cid])) {
                        $shares_by_client[$cid] = array();
                    }
                    // Get property data for notification
                    $shares_by_client[$cid][] = self::get_property_data_for_notification($share['listing_key']);
                }

                // Send notification to each client
                foreach ($shares_by_client as $client_id => $share_data) {
                    $notification_result = $notifier->notify_client($user->ID, $client_id, $share_data, $note);
                    $notification_totals['push'] += $notification_result['push'];
                    $notification_totals['email'] += $notification_result['email'];
                }
            }

            do_action('mld_after_properties_shared', $user->ID, $client_ids, $result['shares']);
        }

        return new WP_REST_Response(array(
            'success' => $result['success'],
            'data' => array(
                'shared_count' => $result['shared_count'],
                'shares' => $result['shares'],
                'errors' => $result['errors'],
                'notifications_sent' => $notification_totals
            )
        ), $result['success'] ? 201 : 400);
    }

    /**
     * Get property data for notification
     *
     * @param string $listing_key Listing key hash
     * @return array Property data for notification
     */
    private static function get_property_data_for_notification($listing_key) {
        global $wpdb;
        $summary_table = $wpdb->prefix . 'bme_listing_summary';

        $property = $wpdb->get_row($wpdb->prepare(
            "SELECT listing_key, listing_id, list_price, street_number, street_name, city,
                    state_or_province, postal_code, bedrooms_total, bathrooms_total,
                    main_photo_url, standard_status
             FROM {$summary_table}
             WHERE listing_key = %s",
            $listing_key
        ), ARRAY_A);

        if (!$property) {
            return array(
                'listing_key' => $listing_key,
                'address' => 'Property',
                'city' => '',
                'price' => 0,
                'beds' => 0,
                'baths' => 0,
                'photo_url' => ''
            );
        }

        $address = trim(sprintf('%s %s', $property['street_number'] ?? '', $property['street_name'] ?? ''));

        return array(
            'id' => $listing_key,
            'listing_key' => $listing_key,
            'listing_id' => $property['listing_id'],
            'address' => $address ?: 'Address unavailable',
            'city' => $property['city'] ?? '',
            'state' => $property['state_or_province'] ?? '',
            'zip' => $property['postal_code'] ?? '',
            'price' => (int) ($property['list_price'] ?? 0),
            'beds' => (int) ($property['bedrooms_total'] ?? 0),
            'baths' => (float) ($property['bathrooms_total'] ?? 0),
            'photo_url' => $property['main_photo_url'] ?? '',
            'status' => $property['standard_status'] ?? ''
        );
    }

    /**
     * Handle getting properties shared by the agent
     *
     * GET /agent/shared-properties
     *
     * @since 6.35.0
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public static function handle_get_agent_shared_properties($request) {
        self::send_no_cache_headers();
        self::ensure_shared_properties_class();
        self::ensure_collaboration_classes();

        $user = wp_get_current_user();

        // Verify user is an agent
        if (!class_exists('MLD_User_Type_Manager') || !MLD_User_Type_Manager::is_agent($user->ID)) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'not_an_agent',
                'message' => 'Only agents can view their shared properties.'
            ), 403);
        }

        if (!class_exists('MLD_Shared_Properties_Manager')) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'sharing_unavailable',
                'message' => 'Property sharing system is not available.'
            ), 500);
        }

        $client_id = $request->get_param('client_id') ? intval($request->get_param('client_id')) : null;
        $limit = $request->get_param('per_page') ? intval($request->get_param('per_page')) : 50;
        $offset = $request->get_param('page') ? (intval($request->get_param('page')) - 1) * $limit : 0;

        $shares = MLD_Shared_Properties_Manager::get_shared_properties_by_agent($user->ID, array(
            'client_id' => $client_id,
            'limit' => $limit,
            'offset' => $offset
        ));

        $stats = MLD_Shared_Properties_Manager::get_agent_share_stats($user->ID);

        return new WP_REST_Response(array(
            'success' => true,
            'data' => $shares,
            'stats' => $stats
        ), 200);
    }

    /**
     * Handle revoking a shared property (agent action)
     *
     * DELETE /shared-properties/{id}
     *
     * @since 6.35.0
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public static function handle_revoke_shared_property($request) {
        self::send_no_cache_headers();
        self::ensure_shared_properties_class();
        self::ensure_collaboration_classes();

        $user = wp_get_current_user();
        $share_id = intval($request->get_param('id'));

        // First check if this is an agent revoking their share
        if (class_exists('MLD_User_Type_Manager') && MLD_User_Type_Manager::is_agent($user->ID)) {
            if (!class_exists('MLD_Shared_Properties_Manager')) {
                return new WP_REST_Response(array(
                    'success' => false,
                    'code' => 'sharing_unavailable',
                    'message' => 'Property sharing system is not available.'
                ), 500);
            }

            $result = MLD_Shared_Properties_Manager::revoke_shared_property($share_id, $user->ID);

            if ($result) {
                return new WP_REST_Response(array(
                    'success' => true,
                    'message' => 'Property share revoked.'
                ), 200);
            } else {
                return new WP_REST_Response(array(
                    'success' => false,
                    'code' => 'revoke_failed',
                    'message' => 'Failed to revoke share. You may not own this share.'
                ), 403);
            }
        }

        // If not an agent, treat this as a client dismissing
        return self::handle_dismiss_shared_property($share_id, $user->ID);
    }

    /**
     * Handle dismissing a shared property (client action)
     *
     * @param int $share_id Share ID
     * @param int $client_id Client user ID
     * @return WP_REST_Response
     */
    private static function handle_dismiss_shared_property($share_id, $client_id) {
        if (!class_exists('MLD_Shared_Properties_Manager')) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'sharing_unavailable',
                'message' => 'Property sharing system is not available.'
            ), 500);
        }

        $result = MLD_Shared_Properties_Manager::dismiss_shared_property($share_id, $client_id);

        if ($result) {
            return new WP_REST_Response(array(
                'success' => true,
                'message' => 'Shared property dismissed.',
                'data' => array(
                    'id' => $share_id,
                    'dismissed' => true
                )
            ), 200);
        } else {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'dismiss_failed',
                'message' => 'Failed to dismiss property.'
            ), 400);
        }
    }

    /**
     * Handle getting properties shared with the client
     *
     * GET /shared-properties
     *
     * @since 6.35.0
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public static function handle_get_shared_properties($request) {
        self::send_no_cache_headers();
        self::ensure_shared_properties_class();

        $user = wp_get_current_user();

        if (!class_exists('MLD_Shared_Properties_Manager')) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'sharing_unavailable',
                'message' => 'Property sharing system is not available.'
            ), 500);
        }

        $include_dismissed = $request->get_param('include_dismissed') === 'true';
        $limit = $request->get_param('per_page') ? intval($request->get_param('per_page')) : 50;
        $offset = $request->get_param('page') ? (intval($request->get_param('page')) - 1) * $limit : 0;

        $shares = MLD_Shared_Properties_Manager::get_shared_properties_for_client($user->ID, array(
            'include_dismissed' => $include_dismissed,
            'limit' => $limit,
            'offset' => $offset
        ));

        $unviewed_count = MLD_Shared_Properties_Manager::get_unviewed_count($user->ID);

        return new WP_REST_Response(array(
            'success' => true,
            'data' => $shares,
            'unviewed_count' => $unviewed_count
        ), 200);
    }

    /**
     * Handle updating client response to a shared property
     *
     * PUT /shared-properties/{id}
     *
     * Request body:
     * - response: 'interested' or 'not_interested'
     * - note: optional client note
     *
     * @since 6.35.0
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public static function handle_update_shared_property_response($request) {
        self::send_no_cache_headers();
        self::ensure_shared_properties_class();

        $user = wp_get_current_user();
        $share_id = intval($request->get_param('id'));

        if (!class_exists('MLD_Shared_Properties_Manager')) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'sharing_unavailable',
                'message' => 'Property sharing system is not available.'
            ), 500);
        }

        $body = $request->get_json_params();
        $response = sanitize_text_field($body['response'] ?? '');
        $note = sanitize_textarea_field($body['note'] ?? '');

        if (!in_array($response, array('interested', 'not_interested', 'none'))) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'invalid_response',
                'message' => 'Response must be "interested", "not_interested", or "none".'
            ), 400);
        }

        $result = MLD_Shared_Properties_Manager::update_client_response($share_id, $user->ID, $response, $note);

        if ($result) {
            // Get updated share data
            $share = MLD_Shared_Properties_Manager::get_shared_property($share_id);

            return new WP_REST_Response(array(
                'success' => true,
                'message' => 'Response updated.',
                'data' => $share
            ), 200);
        } else {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'update_failed',
                'message' => 'Failed to update response.'
            ), 400);
        }
    }

    /**
     * Handle recording a view of a shared property
     *
     * POST /shared-properties/{id}/view
     *
     * @since 6.35.0
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public static function handle_shared_property_view($request) {
        self::send_no_cache_headers();
        self::ensure_shared_properties_class();

        $user = wp_get_current_user();
        $share_id = intval($request->get_param('id'));

        if (!class_exists('MLD_Shared_Properties_Manager')) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'sharing_unavailable',
                'message' => 'Property sharing system is not available.'
            ), 500);
        }

        $result = MLD_Shared_Properties_Manager::record_view($share_id, $user->ID);

        return new WP_REST_Response(array(
            'success' => $result,
            'message' => $result ? 'View recorded.' : 'Failed to record view.'
        ), $result ? 200 : 400);
    }

    // ==========================================
    // CLIENT ANALYTICS HANDLERS (v6.37.0)
    // ==========================================

    /**
     * Ensure analytics class is loaded
     *
     * @since 6.37.0
     */
    private static function ensure_analytics_class() {
        if (!class_exists('MLD_Client_Analytics_Database')) {
            $analytics_file = MLD_PLUGIN_DIR . 'includes/analytics/class-mld-client-analytics-database.php';
            if (file_exists($analytics_file)) {
                require_once $analytics_file;
            }
        }
    }

    /**
     * Handle recording a single activity event
     *
     * POST /analytics/activity
     *
     * @since 6.37.0
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public static function handle_record_activity($request) {
        self::send_no_cache_headers();
        self::ensure_analytics_class();

        $user = wp_get_current_user();

        if (!class_exists('MLD_Client_Analytics_Database')) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'analytics_unavailable',
                'message' => 'Analytics system is not available.'
            ), 500);
        }

        $activity_type = sanitize_text_field($request->get_param('activity_type'));
        $session_id = sanitize_text_field($request->get_param('session_id'));

        if (empty($activity_type) || empty($session_id)) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'missing_params',
                'message' => 'activity_type and session_id are required.'
            ), 400);
        }

        $data = array(
            'entity_id' => $request->get_param('entity_id'),
            'entity_type' => $request->get_param('entity_type'),
            'metadata' => $request->get_param('metadata'),
            'platform' => $request->get_param('platform') ?: 'unknown',
            'device_info' => $request->get_param('device_info'),
        );

        $activity_id = MLD_Client_Analytics_Database::record_activity(
            $user->ID,
            $session_id,
            $activity_type,
            $data
        );

        if ($activity_id) {
            // Fire hook for real-time score updates (v6.41.0+)
            do_action('mld_client_activity_recorded', $user->ID, $activity_type, $data);

            return new WP_REST_Response(array(
                'success' => true,
                'data' => array(
                    'activity_id' => $activity_id
                )
            ), 201);
        } else {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'record_failed',
                'message' => 'Failed to record activity.'
            ), 400);
        }
    }

    /**
     * Handle recording batch of activity events
     *
     * POST /analytics/activity/batch
     *
     * @since 6.37.0
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public static function handle_batch_activities($request) {
        self::send_no_cache_headers();
        self::ensure_analytics_class();

        $user = wp_get_current_user();

        if (!class_exists('MLD_Client_Analytics_Database')) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'analytics_unavailable',
                'message' => 'Analytics system is not available.'
            ), 500);
        }

        // Accept both 'activities' (iOS) and 'events' (web JS) parameter names
        $activities = $request->get_param('activities');
        if (empty($activities)) {
            $activities = $request->get_param('events');
        }

        if (empty($activities) || !is_array($activities)) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'missing_activities',
                'message' => 'activities or events array is required.'
            ), 400);
        }

        // Get session_id from request (web JS sends it at top level)
        $session_id = sanitize_text_field($request->get_param('session_id'));

        // Get platform from request (v6.74.2 - web JS now sends this)
        $platform = sanitize_text_field($request->get_param('platform'));
        if (empty($platform)) {
            $platform = 'unknown';
        }

        // Get device_info from request (v6.74.2)
        $device_info = sanitize_text_field($request->get_param('device_info'));

        // Inject user_id, session_id, platform, and device_info into each activity
        foreach ($activities as &$activity) {
            $activity['user_id'] = $user->ID;
            // Use activity's session_id if present, otherwise use top-level session_id
            if (empty($activity['session_id']) && !empty($session_id)) {
                $activity['session_id'] = $session_id;
            }
            // Inject platform if not already set (v6.74.2)
            if (empty($activity['platform']) && !empty($platform)) {
                $activity['platform'] = $platform;
            }
            // Inject device_info if not already set (v6.74.2)
            if (empty($activity['device_info']) && !empty($device_info)) {
                $activity['device_info'] = $device_info;
            }
        }
        unset($activity); // Break reference

        $result = MLD_Client_Analytics_Database::record_batch_activities($activities);

        // Fire hook for real-time score updates (v6.41.0+)
        // Only fire once per batch to avoid excessive processing
        if (!empty($result['recorded']) && $result['recorded'] > 0) {
            do_action('mld_client_activity_recorded', $user->ID, 'batch', array(
                'count' => $result['recorded'],
                'activities' => array_column($activities, 'activity_type'),
            ));
        }

        return new WP_REST_Response(array(
            'success' => true,
            'data' => $result
        ), 201);
    }

    /**
     * Handle session start/end events
     *
     * POST /analytics/session
     *
     * @since 6.37.0
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public static function handle_session_event($request) {
        self::send_no_cache_headers();
        self::ensure_analytics_class();

        $user = wp_get_current_user();

        if (!class_exists('MLD_Client_Analytics_Database')) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'analytics_unavailable',
                'message' => 'Analytics system is not available.'
            ), 500);
        }

        $action = sanitize_text_field($request->get_param('action')); // 'start' or 'end'
        $session_id = sanitize_text_field($request->get_param('session_id'));

        if (empty($action) || empty($session_id)) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'missing_params',
                'message' => 'action and session_id are required.'
            ), 400);
        }

        if (!in_array($action, array('start', 'end'))) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'invalid_action',
                'message' => 'action must be "start" or "end".'
            ), 400);
        }

        if ($action === 'start') {
            $device_data = array(
                'platform' => $request->get_param('platform') ?: 'unknown',
                'device_type' => $request->get_param('device_type'),
                'app_version' => $request->get_param('app_version'),
            );

            $result = MLD_Client_Analytics_Database::start_session($user->ID, $session_id, $device_data);
        } else {
            $result = MLD_Client_Analytics_Database::end_session($session_id);
        }

        // Include 'data' field for iOS APIClient compatibility
        return new WP_REST_Response(array(
            'success' => $result,
            'message' => $result ? "Session {$action}ed." : "Failed to {$action} session.",
            'data' => array('session_id' => $session_id, 'action' => $action)
        ), $result ? 200 : 400);
    }

    /**
     * Handle getting analytics for a specific client (agent only)
     *
     * GET /agent/clients/{client_id}/analytics
     *
     * @since 6.37.0
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public static function handle_get_client_analytics($request) {
        self::send_no_cache_headers();
        self::ensure_analytics_class();

        $user = wp_get_current_user();
        $client_id = intval($request->get_param('client_id'));

        if (!class_exists('MLD_Client_Analytics_Database')) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'analytics_unavailable',
                'message' => 'Analytics system is not available.'
            ), 500);
        }

        // Verify user is an agent
        if (!class_exists('MLD_User_Type_Manager')) {
            require_once MLD_PLUGIN_DIR . 'includes/class-mld-user-type-manager.php';
        }

        if (!MLD_User_Type_Manager::is_agent($user->ID)) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'not_agent',
                'message' => 'Only agents can view client analytics.'
            ), 403);
        }

        // Verify this client belongs to the agent
        global $wpdb;
        $relationships_table = $wpdb->prefix . 'mld_agent_client_relationships';
        $relationship = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM %i WHERE agent_id = %d AND client_id = %d AND relationship_status = 'active'",
            $relationships_table,
            $user->ID,
            $client_id
        ));

        if (!$relationship) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'not_your_client',
                'message' => 'This client is not assigned to you.'
            ), 403);
        }

        $days = intval($request->get_param('days')) ?: 30;
        $analytics = MLD_Client_Analytics_Database::get_client_analytics($client_id, $days);

        // Add client info
        $client_user = get_userdata($client_id);
        $analytics['email'] = $client_user ? $client_user->user_email : '';
        $analytics['name'] = $client_user ? $client_user->display_name : 'Unknown';

        return new WP_REST_Response(array(
            'success' => true,
            'data' => $analytics
        ), 200);
    }

    /**
     * Handle getting activity timeline for a specific client (agent only)
     *
     * GET /agent/clients/{client_id}/activity
     *
     * @since 6.37.0
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public static function handle_get_client_activity_timeline($request) {
        self::send_no_cache_headers();
        self::ensure_analytics_class();

        $user = wp_get_current_user();
        $client_id = intval($request->get_param('client_id'));

        if (!class_exists('MLD_Client_Analytics_Database')) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'analytics_unavailable',
                'message' => 'Analytics system is not available.'
            ), 500);
        }

        // Verify user is an agent
        if (!class_exists('MLD_User_Type_Manager')) {
            require_once MLD_PLUGIN_DIR . 'includes/class-mld-user-type-manager.php';
        }

        if (!MLD_User_Type_Manager::is_agent($user->ID)) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'not_agent',
                'message' => 'Only agents can view client activity.'
            ), 403);
        }

        // Verify this client belongs to the agent
        global $wpdb;
        $relationships_table = $wpdb->prefix . 'mld_agent_client_relationships';
        $relationship = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM %i WHERE agent_id = %d AND client_id = %d AND relationship_status = 'active'",
            $relationships_table,
            $user->ID,
            $client_id
        ));

        if (!$relationship) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'not_your_client',
                'message' => 'This client is not assigned to you.'
            ), 403);
        }

        $limit = intval($request->get_param('limit')) ?: 50;
        $offset = intval($request->get_param('offset')) ?: 0;

        $activities = MLD_Client_Analytics_Database::get_client_activity_timeline($client_id, $limit, $offset);

        // Enrich property activities with full property details (v6.41.0)
        $activities = self::enrich_activities_with_property_data($activities);

        return new WP_REST_Response(array(
            'success' => true,
            'data' => array(
                'activities' => $activities,
                'count' => count($activities),
                'offset' => $offset,
                'limit' => $limit
            )
        ), 200);
    }

    /**
     * Enrich activity data with full property details
     *
     * For property_view activities, looks up the full property data from
     * bme_listing_summary (and archive for sold properties) to provide
     * rich display data including address, price, photo, beds/baths.
     *
     * @since 6.41.0
     * @param array $activities Array of activity objects
     * @return array Enriched activities
     */
    private static function enrich_activities_with_property_data($activities) {
        if (empty($activities)) {
            return $activities;
        }

        global $wpdb;

        // Collect all property-related entity_ids
        $property_activity_types = array(
            'property_view', 'favorite_add', 'favorite_remove',
            'hidden_add', 'hidden_remove', 'property_share',
            'calculator_use', 'contact_click', 'photo_view',
            'photo_lightbox_open', 'school_info_view', 'similar_homes_click'
        );

        // Collect entity_ids and separate into listing_ids (MLS numbers) and listing_keys (hashes)
        $mls_numbers = array();
        $listing_keys = array();

        foreach ($activities as $activity) {
            $type = is_array($activity) ? ($activity['activity_type'] ?? '') : ($activity->activity_type ?? '');
            $entity_id = is_array($activity) ? ($activity['entity_id'] ?? '') : ($activity->entity_id ?? '');

            if (in_array($type, $property_activity_types) && !empty($entity_id)) {
                // MLS numbers are numeric (e.g., 73462167), listing_keys are 32-char hashes
                if (preg_match('/^\d+$/', $entity_id)) {
                    $mls_numbers[] = $entity_id;
                } elseif (preg_match('/^[a-f0-9]{32}$/i', $entity_id)) {
                    $listing_keys[] = $entity_id;
                }
            }
        }

        if (empty($mls_numbers) && empty($listing_keys)) {
            return $activities;
        }

        $properties_map = array();

        // Query by listing_id (MLS numbers)
        if (!empty($mls_numbers)) {
            $mls_numbers = array_unique($mls_numbers);
            $placeholders = implode(',', array_fill(0, count($mls_numbers), '%s'));

            $active_by_id = $wpdb->get_results($wpdb->prepare(
                "SELECT listing_id, listing_key, street_number, street_name, city,
                        state_or_province, postal_code, list_price, bedrooms_total,
                        bathrooms_total, property_sub_type, main_photo_url,
                        standard_status, days_on_market, building_area_total
                 FROM {$wpdb->prefix}bme_listing_summary
                 WHERE listing_id IN ({$placeholders})",
                ...$mls_numbers
            ));

            $archive_by_id = $wpdb->get_results($wpdb->prepare(
                "SELECT listing_id, listing_key, street_number, street_name, city,
                        state_or_province, postal_code, list_price, bedrooms_total,
                        bathrooms_total, property_sub_type, main_photo_url,
                        standard_status, days_on_market, building_area_total
                 FROM {$wpdb->prefix}bme_listing_summary_archive
                 WHERE listing_id IN ({$placeholders})",
                ...$mls_numbers
            ));

            // Archive first, then active (active takes priority)
            foreach ($archive_by_id as $prop) {
                $properties_map[$prop->listing_id] = $prop;
            }
            foreach ($active_by_id as $prop) {
                $properties_map[$prop->listing_id] = $prop;
            }
        }

        // Query by listing_key (hashes from iOS)
        if (!empty($listing_keys)) {
            $listing_keys = array_unique($listing_keys);
            $placeholders = implode(',', array_fill(0, count($listing_keys), '%s'));

            $active_by_key = $wpdb->get_results($wpdb->prepare(
                "SELECT listing_id, listing_key, street_number, street_name, city,
                        state_or_province, postal_code, list_price, bedrooms_total,
                        bathrooms_total, property_sub_type, main_photo_url,
                        standard_status, days_on_market, building_area_total
                 FROM {$wpdb->prefix}bme_listing_summary
                 WHERE listing_key IN ({$placeholders})",
                ...$listing_keys
            ));

            $archive_by_key = $wpdb->get_results($wpdb->prepare(
                "SELECT listing_id, listing_key, street_number, street_name, city,
                        state_or_province, postal_code, list_price, bedrooms_total,
                        bathrooms_total, property_sub_type, main_photo_url,
                        standard_status, days_on_market, building_area_total
                 FROM {$wpdb->prefix}bme_listing_summary_archive
                 WHERE listing_key IN ({$placeholders})",
                ...$listing_keys
            ));

            // Archive first, then active (active takes priority)
            // Map by listing_key for iOS activities
            foreach ($archive_by_key as $prop) {
                $properties_map[$prop->listing_key] = $prop;
            }
            foreach ($active_by_key as $prop) {
                $properties_map[$prop->listing_key] = $prop;
            }
        }

        // Enrich activities
        foreach ($activities as &$activity) {
            $is_array = is_array($activity);
            $type = $is_array ? ($activity['activity_type'] ?? '') : ($activity->activity_type ?? '');
            $entity_id = $is_array ? ($activity['entity_id'] ?? '') : ($activity->entity_id ?? '');

            if (in_array($type, $property_activity_types) && isset($properties_map[$entity_id])) {
                $prop = $properties_map[$entity_id];

                $property_data = array(
                    'listing_id' => $prop->listing_id,
                    'listing_key' => $prop->listing_key,
                    'address' => trim($prop->street_number . ' ' . $prop->street_name),
                    'full_address' => trim($prop->street_number . ' ' . $prop->street_name . ', ' . $prop->city . ' ' . $prop->state_or_province . ' ' . $prop->postal_code),
                    'street_number' => $prop->street_number,
                    'street_name' => $prop->street_name,
                    'city' => $prop->city,
                    'state' => $prop->state_or_province,
                    'zip' => $prop->postal_code,
                    'price' => (int) $prop->list_price,
                    'beds' => (int) $prop->bedrooms_total,
                    'baths' => (float) $prop->bathrooms_total,
                    'sqft' => (int) $prop->building_area_total,
                    'type' => $prop->property_sub_type,
                    'photo_url' => $prop->main_photo_url,
                    'status' => $prop->standard_status,
                    'dom' => (int) $prop->days_on_market
                );

                if ($is_array) {
                    $activity['property'] = $property_data;
                } else {
                    $activity->property = $property_data;
                }
            }

            // Add human-readable description based on activity type
            $description = self::get_activity_description($type, $is_array ? $activity : (array) $activity);
            if ($is_array) {
                $activity['description'] = $description;
            } else {
                $activity->description = $description;
            }
        }
        unset($activity);

        return $activities;
    }

    /**
     * Get human-readable description for activity type
     *
     * @since 6.41.0
     * @param string $type Activity type
     * @param array $activity Full activity data for context
     * @return string Human-readable description
     */
    private static function get_activity_description($type, $activity = array()) {
        $property = $activity['property'] ?? null;
        $property_text = $property ? " at {$property['address']}, {$property['city']}" : '';

        $descriptions = array(
            'property_view' => $property ? "Viewed {$property['address']}, {$property['city']}" : 'Viewed a property',
            'favorite_add' => $property ? "Saved {$property['address']} to favorites" : 'Added to favorites',
            'favorite_remove' => $property ? "Removed {$property['address']} from favorites" : 'Removed from favorites',
            'hidden_add' => $property ? "Hidden {$property['address']}" : 'Hidden a property',
            'hidden_remove' => $property ? "Unhidden {$property['address']}" : 'Unhidden a property',
            'property_share' => $property ? "Shared {$property['address']}" : 'Shared a property',
            'calculator_use' => $property ? "Used mortgage calculator{$property_text}" : 'Used mortgage calculator',
            'contact_click' => $property ? "Clicked contact{$property_text}" : 'Clicked contact button',
            'photo_view' => $property ? "Viewed photos{$property_text}" : 'Viewed property photos',
            'photo_lightbox_open' => $property ? "Opened photo gallery{$property_text}" : 'Opened photo gallery',
            'school_info_view' => $property ? "Viewed school info{$property_text}" : 'Viewed school information',
            'similar_homes_click' => 'Clicked similar homes',
            'search_run' => 'Ran a property search',
            'search_execute' => 'Executed a search with filters',
            'search_save' => 'Saved a search',
            'saved_search_view' => 'Viewed saved search results',
            'saved_search_edit' => 'Edited a saved search',
            'saved_search_delete' => 'Deleted a saved search',
            'filter_apply' => 'Applied search filters',
            'filter_clear' => 'Cleared search filters',
            'map_zoom' => 'Zoomed the map',
            'map_pan' => 'Panned the map',
            'map_draw_start' => 'Started drawing on map',
            'map_draw_complete' => 'Completed a map draw search',
            'marker_click' => 'Clicked a map marker',
            'cluster_click' => 'Clicked a property cluster',
            'autocomplete_select' => 'Selected an autocomplete suggestion',
            'login' => 'Logged in',
            'page_view' => 'Viewed a page',
            'time_on_page' => 'Spent time on page',
            'scroll_depth' => 'Scrolled on page',
            'contact_form_submit' => 'Submitted contact form',
            'share_click' => 'Clicked share button',
            'video_play' => 'Played a video',
            'street_view_open' => 'Opened street view',
            'alert_toggle' => 'Toggled search alerts',
        );

        return $descriptions[$type] ?? ucwords(str_replace('_', ' ', $type));
    }

    /**
     * Handle getting analytics for all clients (agent dashboard)
     *
     * GET /agent/analytics
     *
     * @since 6.37.0
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public static function handle_get_agent_analytics($request) {
        self::send_no_cache_headers();
        self::ensure_analytics_class();

        $user = wp_get_current_user();

        if (!class_exists('MLD_Client_Analytics_Database')) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'analytics_unavailable',
                'message' => 'Analytics system is not available.'
            ), 500);
        }

        // Verify user is an agent
        if (!class_exists('MLD_User_Type_Manager')) {
            require_once MLD_PLUGIN_DIR . 'includes/class-mld-user-type-manager.php';
        }

        if (!MLD_User_Type_Manager::is_agent($user->ID)) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'not_agent',
                'message' => 'Only agents can view analytics dashboard.'
            ), 403);
        }

        $days = intval($request->get_param('days')) ?: 30;
        $analytics = MLD_Client_Analytics_Database::get_agent_clients_analytics($user->ID, $days);

        return new WP_REST_Response(array(
            'success' => true,
            'data' => $analytics
        ), 200);
    }

    /**
     * Handle getting clients analytics summary with engagement scores
     *
     * GET /agent/clients/analytics/summary
     *
     * @since 6.40.0
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public static function handle_get_clients_analytics_summary($request) {
        self::send_no_cache_headers();

        $user = wp_get_current_user();

        // Load engagement score calculator
        if (!class_exists('MLD_Engagement_Score_Calculator')) {
            require_once MLD_PLUGIN_DIR . 'includes/analytics/class-mld-engagement-score-calculator.php';
        }

        // Verify user is an agent
        if (!class_exists('MLD_User_Type_Manager')) {
            require_once MLD_PLUGIN_DIR . 'includes/class-mld-user-type-manager.php';
        }

        if (!MLD_User_Type_Manager::is_agent($user->ID)) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'not_agent',
                'message' => 'Only agents can view client analytics.'
            ), 403);
        }

        global $wpdb;

        // Get all clients for this agent
        $relationships_table = $wpdb->prefix . 'mld_agent_client_relationships';
        $scores_table = $wpdb->prefix . 'mld_client_engagement_scores';
        $users_table = $wpdb->users;
        $preferences_table = $wpdb->prefix . 'mld_property_preferences';
        $searches_table = $wpdb->prefix . 'mld_saved_searches';
        $activity_table = $wpdb->prefix . 'mld_client_activity';

        $sort_by = sanitize_text_field($request->get_param('sort_by')) ?: 'score';
        $order = strtoupper(sanitize_text_field($request->get_param('order')) ?: 'DESC');
        $order = in_array($order, array('ASC', 'DESC')) ? $order : 'DESC';

        $valid_sorts = array('score', 'last_activity_at', 'days_since_activity', 'trend_change', 'client_name');
        $sort_column = in_array($sort_by, $valid_sorts) ? $sort_by : 'score';

        // Map sort column to actual SQL column
        $sort_map = array(
            'score' => 'es.score',
            'last_activity_at' => 'es.last_activity_at',
            'days_since_activity' => 'es.days_since_activity',
            'trend_change' => 'es.trend_change',
            'client_name' => 'u.display_name'
        );
        $sql_sort = $sort_map[$sort_column];

        // Get clients with their engagement scores
        $clients = $wpdb->get_results($wpdb->prepare("
            SELECT
                r.client_id as id,
                u.display_name as name,
                u.user_email as email,
                r.assigned_date,
                COALESCE(es.score, 0) as engagement_score,
                COALESCE(es.score_trend, 'stable') as score_trend,
                COALESCE(es.trend_change, 0) as trend_change,
                es.last_activity_at,
                COALESCE(es.days_since_activity, 999) as days_since_activity,
                es.time_score,
                es.view_score,
                es.search_score,
                es.engagement_score as intent_score,
                es.frequency_score
            FROM {$relationships_table} r
            INNER JOIN {$users_table} u ON r.client_id = u.ID
            LEFT JOIN {$scores_table} es ON r.client_id = es.user_id
            WHERE r.agent_id = %d
            AND r.relationship_status = 'active'
            ORDER BY {$sql_sort} {$order}
        ", $user->ID), ARRAY_A);

        // Get additional stats for each client
        // Use WordPress timezone (current_time) instead of server timezone (date)
        // See CLAUDE.md Pitfall #10: WordPress Timezone vs PHP Timezone
        $seven_days_ago = date('Y-m-d H:i:s', current_time('timestamp') - (7 * DAY_IN_SECONDS));

        foreach ($clients as &$client) {
            $client_id = (int) $client['id'];

            // Get properties viewed in last 7 days
            $properties_7d = $wpdb->get_var($wpdb->prepare("
                SELECT COUNT(DISTINCT entity_id)
                FROM {$activity_table}
                WHERE user_id = %d
                AND activity_type = 'property_view'
                AND created_at >= %s
            ", $client_id, $seven_days_ago));

            // Get searches in last 7 days
            $searches_7d = $wpdb->get_var($wpdb->prepare("
                SELECT COUNT(*)
                FROM {$activity_table}
                WHERE user_id = %d
                AND activity_type IN ('search_run', 'search_execute')
                AND created_at >= %s
            ", $client_id, $seven_days_ago));

            // Get favorites count
            $favorites = $wpdb->get_var($wpdb->prepare("
                SELECT COUNT(*)
                FROM {$preferences_table}
                WHERE user_id = %d
                AND preference_type = 'liked'
            ", $client_id));

            $client['quick_stats'] = array(
                'properties_viewed_7d' => (int) $properties_7d,
                'searches_7d' => (int) $searches_7d,
                'favorites_count' => (int) $favorites
            );

            // Clean up numeric values
            $client['engagement_score'] = (float) $client['engagement_score'];
            $client['trend_change'] = (float) $client['trend_change'];
            $client['days_since_activity'] = (int) $client['days_since_activity'];
        }
        unset($client); // CRITICAL: Break reference to prevent PHP array corruption

        // Calculate summary stats
        $total_clients = count($clients);
        $active_this_week = 0;
        $highly_engaged = 0;
        $needs_attention = 0;

        foreach ($clients as $client) {
            if ($client['days_since_activity'] <= 7) {
                $active_this_week++;
            }
            if ($client['engagement_score'] >= 60) {
                $highly_engaged++;
            } elseif ($client['engagement_score'] < 20 && $client['engagement_score'] > 0) {
                $needs_attention++;
            }
        }

        return new WP_REST_Response(array(
            'success' => true,
            'data' => array(
                'clients' => $clients,
                'summary' => array(
                    'total_clients' => $total_clients,
                    'active_this_week' => $active_this_week,
                    'highly_engaged' => $highly_engaged,
                    'needs_attention' => $needs_attention
                )
            )
        ), 200);
    }

    /**
     * Handle getting property interests for a specific client
     *
     * GET /agent/clients/{client_id}/property-interests
     *
     * @since 6.40.0
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public static function handle_get_client_property_interests($request) {
        self::send_no_cache_headers();

        $user = wp_get_current_user();
        $client_id = intval($request->get_param('client_id'));

        // Load property interest tracker
        if (!class_exists('MLD_Property_Interest_Tracker')) {
            require_once MLD_PLUGIN_DIR . 'includes/analytics/class-mld-property-interest-tracker.php';
        }

        // Verify user is an agent
        if (!class_exists('MLD_User_Type_Manager')) {
            require_once MLD_PLUGIN_DIR . 'includes/class-mld-user-type-manager.php';
        }

        if (!MLD_User_Type_Manager::is_agent($user->ID)) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'not_agent',
                'message' => 'Only agents can view client property interests.'
            ), 403);
        }

        // Verify this client belongs to the agent
        global $wpdb;
        $relationships_table = $wpdb->prefix . 'mld_agent_client_relationships';
        $relationship = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$relationships_table} WHERE agent_id = %d AND client_id = %d AND relationship_status = 'active'",
            $user->ID,
            $client_id
        ));

        if (!$relationship) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'not_your_client',
                'message' => 'This client is not assigned to you.'
            ), 403);
        }

        $limit = intval($request->get_param('limit')) ?: 20;

        // Get top properties
        $properties = MLD_Property_Interest_Tracker::get_top_properties($client_id, $limit);

        // Format response
        $formatted_properties = array();
        foreach ($properties as $prop) {
            $address = trim(($prop['street_number'] ?? '') . ' ' . ($prop['street_name'] ?? ''));
            if (!empty($prop['city'])) {
                $address .= ($address ? ', ' : '') . $prop['city'];
            }

            $actions = array();
            if (!empty($prop['favorited'])) $actions[] = 'favorited';
            if (!empty($prop['contact_clicked'])) $actions[] = 'contact_clicked';
            if (!empty($prop['calculator_used'])) $actions[] = 'calculator_used';
            if (!empty($prop['shared'])) $actions[] = 'shared';

            $formatted_properties[] = array(
                'listing_id' => $prop['listing_id'],
                'listing_key' => $prop['listing_key'],
                'address' => $address,
                'city' => $prop['city'] ?? '',
                'price' => (int) ($prop['list_price'] ?? 0),
                'beds' => (int) ($prop['bedrooms_total'] ?? 0),
                'baths' => (float) ($prop['bathrooms_total'] ?? 0),
                'sqft' => (int) ($prop['building_area_total'] ?? 0),
                'photo_url' => $prop['main_photo_url'] ?? '',
                'status' => $prop['standard_status'] ?? 'Active',
                'interest_score' => (float) $prop['interest_score'],
                'view_count' => (int) $prop['view_count'],
                'total_view_duration' => (int) $prop['total_view_duration'],
                'photo_views' => (int) $prop['photo_views'],
                'actions' => $actions,
                'first_viewed_at' => $prop['first_viewed_at'],
                'last_viewed_at' => $prop['last_viewed_at']
            );
        }

        // Get cities of interest
        $cities = MLD_Property_Interest_Tracker::get_cities_of_interest($client_id);

        // Get price range
        $price_range = MLD_Property_Interest_Tracker::get_price_range_of_interest($client_id);

        // Get summary
        $summary = MLD_Property_Interest_Tracker::get_user_interest_summary($client_id);

        return new WP_REST_Response(array(
            'success' => true,
            'data' => array(
                'properties' => $formatted_properties,
                'cities_of_interest' => $cities,
                'price_range' => $price_range,
                'summary' => $summary
            )
        ), 200);
    }

    /**
     * Handle getting most viewed properties for a client
     *
     * Returns properties the client has viewed more than once, ordered by view count.
     * Useful for identifying properties the client is most interested in.
     *
     * GET /agent/clients/{client_id}/most-viewed?min_views=2
     *
     * @since 6.41.3
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public static function handle_get_client_most_viewed($request) {
        self::send_no_cache_headers();

        $user = wp_get_current_user();
        $client_id = intval($request->get_param('client_id'));
        $min_views = intval($request->get_param('min_views')) ?: 2;
        $limit = intval($request->get_param('limit')) ?: 20;

        // Verify user is an agent
        if (!class_exists('MLD_User_Type_Manager')) {
            require_once MLD_PLUGIN_DIR . 'includes/class-mld-user-type-manager.php';
        }

        if (!MLD_User_Type_Manager::is_agent($user->ID)) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'not_agent',
                'message' => 'Only agents can view client analytics.'
            ), 403);
        }

        // Verify this client belongs to the agent
        global $wpdb;
        $relationships_table = $wpdb->prefix . 'mld_agent_client_relationships';
        $relationship = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$relationships_table} WHERE agent_id = %d AND client_id = %d AND relationship_status = 'active'",
            $user->ID,
            $client_id
        ));

        if (!$relationship) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'not_your_client',
                'message' => 'This client is not assigned to you.'
            ), 403);
        }

        $activity_table = $wpdb->prefix . 'mld_client_activity';

        // Get property view counts grouped by entity_id, ordered by view count
        // Handle both listing_id (MLS numbers) and listing_key (hashes) formats
        $view_counts = $wpdb->get_results($wpdb->prepare(
            "SELECT entity_id, COUNT(*) as view_count,
                    MIN(created_at) as first_viewed,
                    MAX(created_at) as last_viewed
             FROM {$activity_table}
             WHERE user_id = %d
               AND activity_type = 'property_view'
               AND entity_id IS NOT NULL
               AND entity_id != ''
             GROUP BY entity_id
             HAVING view_count >= %d
             ORDER BY view_count DESC
             LIMIT %d",
            $client_id,
            $min_views,
            $limit
        ));

        if (empty($view_counts)) {
            return new WP_REST_Response(array(
                'success' => true,
                'data' => array(
                    'properties' => array(),
                    'total' => 0
                )
            ), 200);
        }

        // Separate into MLS numbers and listing keys
        $mls_numbers = array();
        $listing_keys = array();
        $view_data = array(); // Store view count data keyed by entity_id

        foreach ($view_counts as $row) {
            $entity_id = $row->entity_id;
            $view_data[$entity_id] = array(
                'view_count' => (int) $row->view_count,
                'first_viewed' => $row->first_viewed,
                'last_viewed' => $row->last_viewed
            );

            if (preg_match('/^\d+$/', $entity_id)) {
                $mls_numbers[] = $entity_id;
            } elseif (preg_match('/^[a-f0-9]{32}$/i', $entity_id)) {
                $listing_keys[] = $entity_id;
            }
        }

        $properties_map = array();

        // Query by listing_id (MLS numbers)
        if (!empty($mls_numbers)) {
            $placeholders = implode(',', array_fill(0, count($mls_numbers), '%s'));

            $active_by_id = $wpdb->get_results($wpdb->prepare(
                "SELECT listing_id, listing_key, street_number, street_name, city,
                        state_or_province, postal_code, list_price, bedrooms_total,
                        bathrooms_total, property_sub_type, main_photo_url,
                        standard_status, days_on_market, building_area_total
                 FROM {$wpdb->prefix}bme_listing_summary
                 WHERE listing_id IN ({$placeholders})",
                ...$mls_numbers
            ));

            $archive_by_id = $wpdb->get_results($wpdb->prepare(
                "SELECT listing_id, listing_key, street_number, street_name, city,
                        state_or_province, postal_code, list_price, bedrooms_total,
                        bathrooms_total, property_sub_type, main_photo_url,
                        standard_status, days_on_market, building_area_total
                 FROM {$wpdb->prefix}bme_listing_summary_archive
                 WHERE listing_id IN ({$placeholders})",
                ...$mls_numbers
            ));

            foreach ($archive_by_id as $prop) {
                $properties_map[$prop->listing_id] = $prop;
            }
            foreach ($active_by_id as $prop) {
                $properties_map[$prop->listing_id] = $prop;
            }
        }

        // Query by listing_key (hashes from iOS)
        if (!empty($listing_keys)) {
            $placeholders = implode(',', array_fill(0, count($listing_keys), '%s'));

            $active_by_key = $wpdb->get_results($wpdb->prepare(
                "SELECT listing_id, listing_key, street_number, street_name, city,
                        state_or_province, postal_code, list_price, bedrooms_total,
                        bathrooms_total, property_sub_type, main_photo_url,
                        standard_status, days_on_market, building_area_total
                 FROM {$wpdb->prefix}bme_listing_summary
                 WHERE listing_key IN ({$placeholders})",
                ...$listing_keys
            ));

            $archive_by_key = $wpdb->get_results($wpdb->prepare(
                "SELECT listing_id, listing_key, street_number, street_name, city,
                        state_or_province, postal_code, list_price, bedrooms_total,
                        bathrooms_total, property_sub_type, main_photo_url,
                        standard_status, days_on_market, building_area_total
                 FROM {$wpdb->prefix}bme_listing_summary_archive
                 WHERE listing_key IN ({$placeholders})",
                ...$listing_keys
            ));

            foreach ($archive_by_key as $prop) {
                $properties_map[$prop->listing_key] = $prop;
            }
            foreach ($active_by_key as $prop) {
                $properties_map[$prop->listing_key] = $prop;
            }
        }

        // Build response array in order of view count
        $formatted_properties = array();
        foreach ($view_counts as $row) {
            $entity_id = $row->entity_id;

            // Look up property data
            $prop = $properties_map[$entity_id] ?? null;
            if (!$prop) {
                continue; // Skip if property not found in database
            }

            $view_info = $view_data[$entity_id];

            $formatted_properties[] = array(
                'listing_id' => $prop->listing_id,
                'listing_key' => $prop->listing_key,
                'address' => trim($prop->street_number . ' ' . $prop->street_name),
                'full_address' => trim($prop->street_number . ' ' . $prop->street_name . ', ' . $prop->city . ' ' . $prop->state_or_province . ' ' . $prop->postal_code),
                'city' => $prop->city,
                'state' => $prop->state_or_province,
                'zip' => $prop->postal_code,
                'price' => (int) $prop->list_price,
                'beds' => (int) $prop->bedrooms_total,
                'baths' => (float) $prop->bathrooms_total,
                'sqft' => (int) $prop->building_area_total,
                'type' => $prop->property_sub_type,
                'photo_url' => $prop->main_photo_url,
                'status' => $prop->standard_status,
                'dom' => (int) $prop->days_on_market,
                'view_count' => $view_info['view_count'],
                'first_viewed' => $view_info['first_viewed'],
                'last_viewed' => $view_info['last_viewed']
            );
        }

        return new WP_REST_Response(array(
            'success' => true,
            'data' => array(
                'properties' => $formatted_properties,
                'total' => count($formatted_properties)
            )
        ), 200);
    }

    /**
     * Handle comparing multiple clients
     *
     * GET /agent/clients/compare?client_ids=1,2,3
     *
     * @since 6.40.0
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public static function handle_compare_clients($request) {
        self::send_no_cache_headers();

        $user = wp_get_current_user();

        // Load engagement score calculator
        if (!class_exists('MLD_Engagement_Score_Calculator')) {
            require_once MLD_PLUGIN_DIR . 'includes/analytics/class-mld-engagement-score-calculator.php';
        }

        // Verify user is an agent
        if (!class_exists('MLD_User_Type_Manager')) {
            require_once MLD_PLUGIN_DIR . 'includes/class-mld-user-type-manager.php';
        }

        if (!MLD_User_Type_Manager::is_agent($user->ID)) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'not_agent',
                'message' => 'Only agents can compare clients.'
            ), 403);
        }

        $client_ids_param = sanitize_text_field($request->get_param('client_ids'));
        if (empty($client_ids_param)) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'missing_client_ids',
                'message' => 'Please provide client_ids parameter (comma-separated).'
            ), 400);
        }

        $client_ids = array_map('intval', explode(',', $client_ids_param));
        $client_ids = array_filter($client_ids); // Remove zeros

        if (count($client_ids) < 2) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'need_multiple_clients',
                'message' => 'Please provide at least 2 client IDs to compare.'
            ), 400);
        }

        if (count($client_ids) > 10) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'too_many_clients',
                'message' => 'Maximum 10 clients can be compared at once.'
            ), 400);
        }

        global $wpdb;
        $relationships_table = $wpdb->prefix . 'mld_agent_client_relationships';
        $scores_table = $wpdb->prefix . 'mld_client_engagement_scores';
        $users_table = $wpdb->users;

        // Verify all clients belong to this agent
        $placeholders = implode(',', array_fill(0, count($client_ids), '%d'));
        $query_params = array_merge(array($user->ID), $client_ids);

        $valid_clients = $wpdb->get_col($wpdb->prepare(
            "SELECT client_id FROM {$relationships_table}
            WHERE agent_id = %d AND client_id IN ({$placeholders}) AND relationship_status = 'active'",
            ...$query_params
        ));

        $invalid_clients = array_diff($client_ids, $valid_clients);
        if (!empty($invalid_clients)) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'invalid_clients',
                'message' => 'Some clients are not assigned to you: ' . implode(', ', $invalid_clients)
            ), 403);
        }

        // Get comparison data
        $comparisons = array();
        foreach ($client_ids as $client_id) {
            $client_user = get_userdata($client_id);
            $score_data = MLD_Engagement_Score_Calculator::get_score($client_id);

            $comparisons[] = array(
                'id' => $client_id,
                'name' => $client_user ? $client_user->display_name : 'Unknown',
                'email' => $client_user ? $client_user->user_email : '',
                'engagement_score' => $score_data ? (float) $score_data['score'] : 0,
                'score_trend' => $score_data ? $score_data['score_trend'] : 'stable',
                'component_scores' => $score_data ? array(
                    'time_investment' => (float) $score_data['time_score'],
                    'view_depth' => (float) $score_data['view_score'],
                    'search_behavior' => (float) $score_data['search_score'],
                    'intent_signals' => (float) $score_data['engagement_score'],
                    'frequency' => (float) $score_data['frequency_score']
                ) : null,
                'last_activity_at' => $score_data ? $score_data['last_activity_at'] : null,
                'days_since_activity' => $score_data ? (int) $score_data['days_since_activity'] : 999
            );
        }

        // Sort by engagement score descending
        usort($comparisons, function($a, $b) {
            return $b['engagement_score'] <=> $a['engagement_score'];
        });

        return new WP_REST_Response(array(
            'success' => true,
            'data' => array(
                'comparisons' => $comparisons,
                'highest_score' => $comparisons[0]['engagement_score'] ?? 0,
                'lowest_score' => end($comparisons)['engagement_score'] ?? 0,
                'average_score' => count($comparisons) > 0
                    ? round(array_sum(array_column($comparisons, 'engagement_score')) / count($comparisons), 2)
                    : 0
            )
        ), 200);
    }

    /**
     * Handle getting client preferences/profile analytics
     *
     * GET /agent/clients/{client_id}/preferences
     *
     * Returns comprehensive profile of client preferences based on:
     * - Properties they've viewed (location, beds, baths, price, sqft, type)
     * - Their favorites and saved searches
     * - Engagement patterns (when they're active)
     *
     * @since 6.42.0
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public static function handle_get_client_preferences($request) {
        self::send_no_cache_headers();

        $user = wp_get_current_user();
        $client_id = (int) $request->get_param('client_id');

        // Verify user is an agent
        if (!class_exists('MLD_User_Type_Manager')) {
            require_once MLD_PLUGIN_DIR . 'includes/class-mld-user-type-manager.php';
        }

        if (!MLD_User_Type_Manager::is_agent($user->ID)) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'not_agent',
                'message' => 'Only agents can view client preferences.'
            ), 403);
        }

        // Verify this client belongs to the agent
        global $wpdb;
        $relationships_table = $wpdb->prefix . 'mld_agent_client_relationships';
        $relationship = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$relationships_table} WHERE agent_id = %d AND client_id = %d AND relationship_status = 'active'",
            $user->ID,
            $client_id
        ));

        if (!$relationship) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'not_your_client',
                'message' => 'This client is not assigned to you.'
            ), 403);
        }

        $activity_table = $wpdb->prefix . 'mld_client_activity';
        $favorites_table = $wpdb->prefix . 'mld_favorites';
        $searches_table = $wpdb->prefix . 'mld_saved_searches';
        $summary_table = $wpdb->prefix . 'bme_listing_summary';
        $archive_table = $wpdb->prefix . 'bme_listing_summary_archive';

        // =========================================
        // 1. Get all property views with property data
        // =========================================
        $property_views = $wpdb->get_results($wpdb->prepare(
            "SELECT entity_id, created_at
             FROM {$activity_table}
             WHERE user_id = %d
               AND activity_type = 'property_view'
               AND entity_id IS NOT NULL
               AND entity_id != ''
             ORDER BY created_at DESC",
            $client_id
        ));

        // Separate into MLS numbers and listing keys
        $mls_numbers = array();
        $listing_keys = array();
        $view_timestamps = array(); // For engagement patterns

        foreach ($property_views as $view) {
            $entity_id = $view->entity_id;
            $view_timestamps[] = $view->created_at;

            if (preg_match('/^\d+$/', $entity_id)) {
                $mls_numbers[] = $entity_id;
            } elseif (preg_match('/^[a-f0-9]{32}$/i', $entity_id)) {
                $listing_keys[] = $entity_id;
            }
        }

        // Fetch property details for aggregation
        $properties = array();

        if (!empty($mls_numbers)) {
            $unique_mls = array_unique($mls_numbers);
            $placeholders = implode(',', array_fill(0, count($unique_mls), '%s'));

            // Note: summary table doesn't have subdivision_name, archive table does
            $active = $wpdb->get_results($wpdb->prepare(
                "SELECT listing_id, city, postal_code, NULL as subdivision_name, list_price,
                        bedrooms_total, bathrooms_total, building_area_total,
                        property_sub_type, garage_spaces
                 FROM {$summary_table}
                 WHERE listing_id IN ({$placeholders})",
                ...$unique_mls
            ));

            $archive = $wpdb->get_results($wpdb->prepare(
                "SELECT listing_id, city, postal_code, subdivision_name, list_price,
                        bedrooms_total, bathrooms_total, building_area_total,
                        property_sub_type, garage_spaces
                 FROM {$archive_table}
                 WHERE listing_id IN ({$placeholders})",
                ...$unique_mls
            ));

            foreach (array_merge($archive, $active) as $prop) {
                $properties[$prop->listing_id] = $prop;
            }
        }

        if (!empty($listing_keys)) {
            $unique_keys = array_unique($listing_keys);
            $placeholders = implode(',', array_fill(0, count($unique_keys), '%s'));

            // Note: summary table doesn't have subdivision_name, archive table does
            $active = $wpdb->get_results($wpdb->prepare(
                "SELECT listing_key, city, postal_code, NULL as subdivision_name, list_price,
                        bedrooms_total, bathrooms_total, building_area_total,
                        property_sub_type, garage_spaces
                 FROM {$summary_table}
                 WHERE listing_key IN ({$placeholders})",
                ...$unique_keys
            ));

            $archive = $wpdb->get_results($wpdb->prepare(
                "SELECT listing_key, city, postal_code, subdivision_name, list_price,
                        bedrooms_total, bathrooms_total, building_area_total,
                        property_sub_type, garage_spaces
                 FROM {$archive_table}
                 WHERE listing_key IN ({$placeholders})",
                ...$unique_keys
            ));

            foreach (array_merge($archive, $active) as $prop) {
                $properties[$prop->listing_key] = $prop;
            }
        }

        // =========================================
        // 2. Aggregate Location Preferences
        // =========================================
        $city_counts = array();
        $zip_counts = array();
        $neighborhood_counts = array();

        // Count views by location (including repeat views)
        foreach ($property_views as $view) {
            $entity_id = $view->entity_id;
            $prop = $properties[$entity_id] ?? null;
            if (!$prop) continue;

            $city = $prop->city ?? '';
            $zip = $prop->postal_code ?? '';
            $neighborhood = $prop->subdivision_name ?? '';

            if ($city) {
                $city_counts[$city] = ($city_counts[$city] ?? 0) + 1;
            }
            if ($zip) {
                $zip_counts[$zip] = ($zip_counts[$zip] ?? 0) + 1;
            }
            if ($neighborhood) {
                $neighborhood_counts[$neighborhood] = ($neighborhood_counts[$neighborhood] ?? 0) + 1;
            }
        }

        // Sort and format location preferences
        arsort($city_counts);
        arsort($zip_counts);
        arsort($neighborhood_counts);

        $total_views = count($property_views);
        $top_cities = array();
        $i = 0;
        foreach ($city_counts as $city => $count) {
            if ($i++ >= 5) break;
            $top_cities[] = array(
                'name' => $city,
                'view_count' => $count,
                'percentage' => $total_views > 0 ? round(($count / $total_views) * 100) : 0
            );
        }

        $top_zips = array();
        $i = 0;
        foreach ($zip_counts as $zip => $count) {
            if ($i++ >= 5) break;
            $top_zips[] = array(
                'code' => $zip,
                'view_count' => $count,
                'percentage' => $total_views > 0 ? round(($count / $total_views) * 100) : 0
            );
        }

        $top_neighborhoods = array();
        $i = 0;
        foreach ($neighborhood_counts as $neighborhood => $count) {
            if ($i++ >= 5) break;
            $top_neighborhoods[] = array(
                'name' => $neighborhood,
                'view_count' => $count,
                'percentage' => $total_views > 0 ? round(($count / $total_views) * 100) : 0
            );
        }

        // =========================================
        // 3. Aggregate Property Characteristics
        // =========================================
        $beds_values = array();
        $baths_values = array();
        $sqft_values = array();
        $price_values = array();
        $garage_values = array();
        $type_counts = array();

        foreach ($property_views as $view) {
            $entity_id = $view->entity_id;
            $prop = $properties[$entity_id] ?? null;
            if (!$prop) continue;

            if ($prop->bedrooms_total > 0) {
                $beds_values[] = (int) $prop->bedrooms_total;
            }
            if ($prop->bathrooms_total > 0) {
                $baths_values[] = (float) $prop->bathrooms_total;
            }
            if ($prop->building_area_total > 0) {
                $sqft_values[] = (int) $prop->building_area_total;
            }
            if ($prop->list_price > 0) {
                $price_values[] = (int) $prop->list_price;
            }
            if ($prop->garage_spaces !== null && $prop->garage_spaces >= 0) {
                $garage_values[] = (int) $prop->garage_spaces;
            }
            if (!empty($prop->property_sub_type)) {
                $type_counts[$prop->property_sub_type] = ($type_counts[$prop->property_sub_type] ?? 0) + 1;
            }
        }

        // Calculate statistics
        $beds_stats = self::calculate_stats($beds_values);
        $baths_stats = self::calculate_stats($baths_values, 1);
        $sqft_stats = self::calculate_stats($sqft_values);
        $price_stats = self::calculate_stats($price_values);
        $garage_stats = self::calculate_stats($garage_values);

        // Property types
        arsort($type_counts);
        $property_types = array();
        foreach ($type_counts as $type => $count) {
            $property_types[] = array(
                'type' => $type,
                'count' => $count,
                'percentage' => $total_views > 0 ? round(($count / $total_views) * 100) : 0
            );
        }

        // =========================================
        // 4. Engagement Patterns
        // =========================================
        $hour_counts = array_fill(0, 24, 0);
        $day_counts = array_fill(0, 7, 0); // 0=Sunday, 6=Saturday
        $week_counts = array(); // Weekly activity

        foreach ($view_timestamps as $timestamp) {
            // Use wp_timezone() to correctly parse timestamps stored in WordPress timezone
            $date = new DateTime($timestamp, wp_timezone());
            $hour = (int) $date->format('G');
            $day = (int) $date->format('w');
            $week = $date->format('Y-W');

            $hour_counts[$hour]++;
            $day_counts[$day]++;
            $week_counts[$week] = ($week_counts[$week] ?? 0) + 1;
        }

        // Find most active hours (top 3)
        arsort($hour_counts);
        $most_active_hours = array_slice(array_keys($hour_counts), 0, 3, true);

        // Find most active days
        arsort($day_counts);
        $day_names = array('Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday');
        $most_active_days = array();
        $i = 0;
        foreach ($day_counts as $day_num => $count) {
            if ($i++ >= 2 || $count == 0) break;
            $most_active_days[] = array(
                'day' => $day_names[$day_num],
                'count' => $count
            );
        }

        // Get favorites count
        $favorites_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$favorites_table} WHERE user_id = %d",
            $client_id
        ));

        // Get saved searches count and analyze search criteria
        $saved_searches = $wpdb->get_results($wpdb->prepare(
            "SELECT filters FROM {$searches_table} WHERE user_id = %d",
            $client_id
        ));

        $search_criteria = array(
            'cities' => array(),
            'price_ranges' => array(),
            'bed_requirements' => array()
        );

        foreach ($saved_searches as $search) {
            $filters = json_decode($search->filters, true);
            if (!$filters) continue;

            // Extract cities from searches
            $city = $filters['city'] ?? $filters['City'] ?? $filters['selected_cities'] ?? null;
            if ($city) {
                if (is_array($city)) {
                    foreach ($city as $c) {
                        $search_criteria['cities'][$c] = ($search_criteria['cities'][$c] ?? 0) + 1;
                    }
                } else {
                    $search_criteria['cities'][$city] = ($search_criteria['cities'][$city] ?? 0) + 1;
                }
            }

            // Extract price range
            $min_price = $filters['min_price'] ?? $filters['price_min'] ?? null;
            $max_price = $filters['max_price'] ?? $filters['price_max'] ?? null;
            if ($min_price || $max_price) {
                $search_criteria['price_ranges'][] = array(
                    'min' => $min_price ? (int) $min_price : null,
                    'max' => $max_price ? (int) $max_price : null
                );
            }

            // Extract beds
            $beds = $filters['beds'] ?? $filters['bedrooms_min'] ?? null;
            if ($beds) {
                $search_criteria['bed_requirements'][$beds] = ($search_criteria['bed_requirements'][$beds] ?? 0) + 1;
            }
        }

        // Format search cities
        arsort($search_criteria['cities']);
        $searched_cities = array();
        foreach (array_slice($search_criteria['cities'], 0, 5, true) as $city => $count) {
            $searched_cities[] = array('name' => $city, 'search_count' => $count);
        }

        // Calculate avg search price range
        $search_price_avg = null;
        if (!empty($search_criteria['price_ranges'])) {
            $mins = array_filter(array_column($search_criteria['price_ranges'], 'min'));
            $maxs = array_filter(array_column($search_criteria['price_ranges'], 'max'));
            $search_price_avg = array(
                'avg_min' => !empty($mins) ? (int) round(array_sum($mins) / count($mins)) : null,
                'avg_max' => !empty($maxs) ? (int) round(array_sum($maxs) / count($maxs)) : null
            );
        }

        // Unique properties viewed
        $unique_properties = count(array_unique(array_merge($mls_numbers, $listing_keys)));

        // =========================================
        // 5. Build Response
        // =========================================
        return new WP_REST_Response(array(
            'success' => true,
            'data' => array(
                'location_preferences' => array(
                    'top_cities' => $top_cities,
                    'top_neighborhoods' => $top_neighborhoods,
                    'top_zips' => $top_zips
                ),
                'property_preferences' => array(
                    'bedrooms' => $beds_stats,
                    'bathrooms' => $baths_stats,
                    'sqft' => $sqft_stats,
                    'price' => $price_stats,
                    'garage' => $garage_stats,
                    'property_types' => $property_types
                ),
                'search_preferences' => array(
                    'searched_cities' => $searched_cities,
                    'price_range' => $search_price_avg,
                    'total_searches' => count($saved_searches)
                ),
                'engagement_stats' => array(
                    'total_views' => $total_views,
                    'unique_properties' => $unique_properties,
                    'favorites_count' => (int) $favorites_count,
                    'saved_searches' => count($saved_searches),
                    'most_active_hours' => array_values($most_active_hours),
                    'most_active_days' => $most_active_days,
                    'activity_by_hour' => $hour_counts,
                    'activity_by_day' => array_combine($day_names, array_values($day_counts))
                ),
                'profile_strength' => self::calculate_profile_strength($total_views, $unique_properties, (int) $favorites_count, count($saved_searches))
            )
        ), 200);
    }

    /**
     * Calculate statistics for a set of numeric values
     *
     * @since 6.42.0
     * @param array $values Array of numeric values
     * @param int $decimals Decimal places for rounding
     * @return array Statistics array
     */
    private static function calculate_stats($values, $decimals = 0) {
        if (empty($values)) {
            return array(
                'average' => null,
                'min' => null,
                'max' => null,
                'most_common' => null,
                'count' => 0
            );
        }

        $counts = array_count_values(array_map(function($v) use ($decimals) {
            return $decimals > 0 ? round($v, $decimals) : (int) $v;
        }, $values));
        arsort($counts);

        return array(
            'average' => round(array_sum($values) / count($values), $decimals),
            'min' => $decimals > 0 ? round(min($values), $decimals) : (int) min($values),
            'max' => $decimals > 0 ? round(max($values), $decimals) : (int) max($values),
            'most_common' => array_key_first($counts),
            'count' => count($values)
        );
    }

    /**
     * Calculate profile strength score based on engagement data
     *
     * @since 6.42.0
     * @param int $views Total property views
     * @param int $unique Unique properties viewed
     * @param int $favorites Favorites count
     * @param int $searches Saved searches count
     * @return array Profile strength data
     */
    private static function calculate_profile_strength($views, $unique, $favorites, $searches) {
        // Score components (0-25 each, total 0-100)
        $view_score = min(25, $views * 1.25); // 20 views = 25
        $unique_score = min(25, $unique * 2.5); // 10 unique = 25
        $favorite_score = min(25, $favorites * 5); // 5 favorites = 25
        $search_score = min(25, $searches * 12.5); // 2 searches = 25

        $total = round($view_score + $unique_score + $favorite_score + $search_score);

        $label = 'Needs More Data';
        if ($total >= 80) {
            $label = 'Very Strong';
        } elseif ($total >= 60) {
            $label = 'Strong';
        } elseif ($total >= 40) {
            $label = 'Moderate';
        } elseif ($total >= 20) {
            $label = 'Building';
        }

        return array(
            'score' => $total,
            'label' => $label,
            'components' => array(
                'views' => round($view_score),
                'unique_properties' => round($unique_score),
                'favorites' => round($favorite_score),
                'searches' => round($search_score)
            )
        );
    }

    // ==========================================
    // AGENT NOTIFICATION PREFERENCES HANDLERS (v6.43.0)
    // ==========================================

    /**
     * GET /agent/notification-preferences
     * Get agent's notification preferences for client activity alerts
     *
     * @since 6.43.0
     */
    public static function handle_get_notification_preferences($request) {
        // Prevent CDN caching
        self::send_no_cache_headers();

        $user = wp_get_current_user();

        // Check if user is an agent
        if (!class_exists('MLD_User_Type_Manager') || !MLD_User_Type_Manager::is_agent($user->ID)) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'not_agent',
                'message' => 'Only agents can manage notification preferences'
            ), 403);
        }

        // Check if preferences class exists
        if (!class_exists('MLD_Agent_Notification_Preferences')) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'feature_unavailable',
                'message' => 'Notification preferences not available'
            ), 500);
        }

        $preferences = MLD_Agent_Notification_Preferences::get_all_preferences($user->ID);
        $labels = MLD_Agent_Notification_Preferences::get_notification_type_labels();

        // Format for iOS consumption
        $formatted = array();
        foreach ($preferences as $type => $settings) {
            $formatted[$type] = array(
                'label' => $labels[$type] ?? $type,
                'email_enabled' => $settings['email_enabled'],
                'push_enabled' => $settings['push_enabled']
            );
        }

        return new WP_REST_Response(array(
            'success' => true,
            'data' => array(
                'preferences' => $formatted
            )
        ), 200);
    }

    /**
     * PUT /agent/notification-preferences
     * Update agent's notification preferences for client activity alerts
     *
     * @since 6.43.0
     */
    public static function handle_update_notification_preferences($request) {
        // Prevent CDN caching
        self::send_no_cache_headers();

        $user = wp_get_current_user();

        // Check if user is an agent
        if (!class_exists('MLD_User_Type_Manager') || !MLD_User_Type_Manager::is_agent($user->ID)) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'not_agent',
                'message' => 'Only agents can manage notification preferences'
            ), 403);
        }

        // Check if preferences class exists
        if (!class_exists('MLD_Agent_Notification_Preferences')) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'feature_unavailable',
                'message' => 'Notification preferences not available'
            ), 500);
        }

        $params = $request->get_json_params();
        $preferences = isset($params['preferences']) ? $params['preferences'] : array();

        if (empty($preferences)) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'missing_preferences',
                'message' => 'Preferences data is required'
            ), 400);
        }

        // Update preferences
        MLD_Agent_Notification_Preferences::update_preferences($user->ID, $preferences);

        // Return updated preferences
        $updated = MLD_Agent_Notification_Preferences::get_all_preferences($user->ID);
        $labels = MLD_Agent_Notification_Preferences::get_notification_type_labels();

        $formatted = array();
        foreach ($updated as $type => $settings) {
            $formatted[$type] = array(
                'label' => $labels[$type] ?? $type,
                'email_enabled' => $settings['email_enabled'],
                'push_enabled' => $settings['push_enabled']
            );
        }

        return new WP_REST_Response(array(
            'success' => true,
            'message' => 'Preferences updated successfully',
            'data' => array(
                'preferences' => $formatted
            )
        ), 200);
    }

    // ==========================================
    // AGENT REFERRAL SYSTEM HANDLERS (v6.52.0)
    // ==========================================

    /**
     * GET /agent/referral-link
     * Get agent's referral link and statistics
     *
     * @since 6.52.0
     */
    public static function handle_get_agent_referral_link($request) {
        // Prevent CDN caching
        self::send_no_cache_headers();

        $user = wp_get_current_user();

        // Check if user is an agent
        if (!class_exists('MLD_User_Type_Manager') || !MLD_User_Type_Manager::is_agent($user->ID)) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'not_agent',
                'message' => 'Only agents can access referral links'
            ), 403);
        }

        // Check if referral manager class exists
        if (!class_exists('MLD_Referral_Manager')) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'feature_unavailable',
                'message' => 'Referral system not available'
            ), 500);
        }

        // Get or create referral code for agent
        $code_data = MLD_Referral_Manager::get_agent_referral_code($user->ID);

        if (!$code_data) {
            // Generate a new code if none exists
            $code_data = MLD_Referral_Manager::generate_referral_code($user->ID);
        }

        if (!$code_data || !isset($code_data['referral_code'])) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'generation_failed',
                'message' => 'Could not generate referral code'
            ), 500);
        }

        // Get referral statistics
        $stats = MLD_Referral_Manager::get_agent_referral_stats($user->ID);

        // Build referral URL
        $referral_url = home_url('/signup/?ref=' . $code_data['referral_code']);

        return new WP_REST_Response(array(
            'success' => true,
            'data' => array(
                'referral_code' => $code_data['referral_code'],
                'referral_url' => $referral_url,
                'is_active' => (bool) $code_data['is_active'],
                'created_at' => $code_data['created_at'],
                'stats' => $stats
            )
        ), 200);
    }

    /**
     * POST /agent/referral-link
     * Update agent's custom referral code
     *
     * @since 6.52.0
     */
    public static function handle_update_agent_referral_code($request) {
        // Prevent CDN caching
        self::send_no_cache_headers();

        $user = wp_get_current_user();

        // Check if user is an agent
        if (!class_exists('MLD_User_Type_Manager') || !MLD_User_Type_Manager::is_agent($user->ID)) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'not_agent',
                'message' => 'Only agents can manage referral links'
            ), 403);
        }

        // Check if referral manager class exists
        if (!class_exists('MLD_Referral_Manager')) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'feature_unavailable',
                'message' => 'Referral system not available'
            ), 500);
        }

        $params = $request->get_json_params();
        $custom_code = isset($params['custom_code']) ? sanitize_text_field($params['custom_code']) : '';

        if (empty($custom_code)) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'missing_code',
                'message' => 'Custom code is required'
            ), 400);
        }

        // Try to update the code
        $result = MLD_Referral_Manager::update_referral_code($user->ID, $custom_code);

        if (is_wp_error($result)) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => $result->get_error_code(),
                'message' => $result->get_error_message()
            ), 400);
        }

        // Get updated code data
        $code_data = MLD_Referral_Manager::get_agent_referral_code($user->ID);
        $referral_url = home_url('/signup/?ref=' . $code_data['referral_code']);

        return new WP_REST_Response(array(
            'success' => true,
            'message' => 'Referral code updated successfully',
            'data' => array(
                'referral_code' => $code_data['referral_code'],
                'referral_url' => $referral_url
            )
        ), 200);
    }

    /**
     * POST /agent/referral-link/regenerate
     * Generate a new referral code for the agent
     *
     * @since 6.52.0
     */
    public static function handle_regenerate_agent_referral_code($request) {
        // Prevent CDN caching
        self::send_no_cache_headers();

        $user = wp_get_current_user();

        // Check if user is an agent
        if (!class_exists('MLD_User_Type_Manager') || !MLD_User_Type_Manager::is_agent($user->ID)) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'not_agent',
                'message' => 'Only agents can manage referral links'
            ), 403);
        }

        // Check if referral manager class exists
        if (!class_exists('MLD_Referral_Manager')) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'feature_unavailable',
                'message' => 'Referral system not available'
            ), 500);
        }

        // Deactivate existing code and generate new one
        $result = MLD_Referral_Manager::regenerate_referral_code($user->ID);

        if (is_wp_error($result)) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => $result->get_error_code(),
                'message' => $result->get_error_message()
            ), 500);
        }

        // Get new code data
        $code_data = MLD_Referral_Manager::get_agent_referral_code($user->ID);
        $referral_url = home_url('/signup/?ref=' . $code_data['referral_code']);

        return new WP_REST_Response(array(
            'success' => true,
            'message' => 'Referral code regenerated successfully',
            'data' => array(
                'referral_code' => $code_data['referral_code'],
                'referral_url' => $referral_url
            )
        ), 200);
    }

    /**
     * GET /agent/referral-stats
     * Get detailed referral statistics for the agent
     *
     * @since 6.52.0
     * @updated 6.52.2 - Fixed response format to match iOS model
     */
    public static function handle_get_agent_referral_stats($request) {
        global $wpdb;

        // Prevent CDN caching
        self::send_no_cache_headers();

        $user = wp_get_current_user();

        // Check if user is an agent
        if (!class_exists('MLD_User_Type_Manager') || !MLD_User_Type_Manager::is_agent($user->ID)) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'not_agent',
                'message' => 'Only agents can access referral statistics'
            ), 403);
        }

        // Check if referral manager class exists
        if (!class_exists('MLD_Referral_Manager')) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'feature_unavailable',
                'message' => 'Referral system not available'
            ), 500);
        }

        $table = $wpdb->prefix . 'mld_referral_signups';
        $agent_user_id = $user->ID;

        // Total referral signups
        $total_referrals = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table
             WHERE agent_user_id = %d AND signup_source = %s",
            $agent_user_id, 'referral_link'
        ));

        // This month's signups
        $this_month = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table
             WHERE agent_user_id = %d
             AND signup_source = %s
             AND YEAR(created_at) = YEAR(CURRENT_DATE())
             AND MONTH(created_at) = MONTH(CURRENT_DATE())",
            $agent_user_id, 'referral_link'
        ));

        // Last 3 months' signups
        $last_three_months = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table
             WHERE agent_user_id = %d
             AND signup_source = %s
             AND created_at >= DATE_SUB(CURRENT_DATE(), INTERVAL 3 MONTH)",
            $agent_user_id, 'referral_link'
        ));

        // Monthly breakdown (last 12 months)
        $by_month_raw = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE_FORMAT(created_at, '%%Y-%%m') as month, COUNT(*) as count
             FROM $table
             WHERE agent_user_id = %d
             AND signup_source = %s
             AND created_at >= DATE_SUB(CURRENT_DATE(), INTERVAL 12 MONTH)
             GROUP BY DATE_FORMAT(created_at, '%%Y-%%m')
             ORDER BY month DESC",
            $agent_user_id, 'referral_link'
        ), ARRAY_A);

        // Format by_month for iOS - convert YYYY-MM to readable format
        $by_month = array();
        foreach ($by_month_raw as $row) {
            $date = DateTime::createFromFormat('Y-m', $row['month']);
            $by_month[] = array(
                'month' => $date ? $date->format('F Y') : $row['month'],
                'count' => (int) $row['count']
            );
        }

        return new WP_REST_Response(array(
            'success' => true,
            'data' => array(
                'total_referrals' => $total_referrals,
                'this_month' => $this_month,
                'last_three_months' => $last_three_months,
                'by_month' => $by_month
            )
        ), 200);
    }

    /**
     * GET /referral/validate
     * Validate a referral code and return agent info (public endpoint)
     *
     * @since 6.52.0
     */
    public static function handle_validate_referral_code($request) {
        $code = sanitize_text_field($request->get_param('code'));

        if (empty($code)) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'missing_code',
                'message' => 'Referral code is required'
            ), 400);
        }

        // Check if referral manager class exists
        if (!class_exists('MLD_Referral_Manager')) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'feature_unavailable',
                'message' => 'Referral system not available'
            ), 500);
        }

        // Validate the code
        $is_valid = MLD_Referral_Manager::validate_referral_code($code);

        if (!$is_valid) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'invalid_code',
                'message' => 'Invalid or inactive referral code'
            ), 404);
        }

        // Get agent info for the code
        $agent_data = MLD_Referral_Manager::get_agent_by_code($code);

        if (!$agent_data) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'agent_not_found',
                'message' => 'Agent not found for this referral code'
            ), 404);
        }

        return new WP_REST_Response(array(
            'success' => true,
            'data' => array(
                'valid' => true,
                'agent' => $agent_data
            )
        ), 200);
    }

    /**
     * POST /app/opened
     * Report that the client has opened the app
     * Triggers notification to assigned agent (with 2-hour debounce)
     *
     * @since 6.43.0
     */
    public static function handle_app_opened($request) {
        // Prevent CDN caching
        self::send_no_cache_headers();

        $user = wp_get_current_user();

        // Trigger the app opened hook
        do_action('mld_app_opened', $user->ID);

        // Include 'data' field for iOS APIClient compatibility
        return new WP_REST_Response(array(
            'success' => true,
            'message' => 'App open recorded',
            'data' => array('recorded' => true)
        ), 200);
    }

    /**
     * GET /notification-preferences
     * Get client's notification preferences for alerts
     *
     * @since 6.48.0
     */
    public static function handle_get_client_notification_preferences($request) {
        // Prevent CDN caching
        self::send_no_cache_headers();

        $user = wp_get_current_user();

        // Check if preferences class exists
        if (!class_exists('MLD_Client_Notification_Preferences')) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'feature_unavailable',
                'message' => 'Notification preferences not available'
            ), 500);
        }

        $preferences = MLD_Client_Notification_Preferences::get_preferences($user->ID);
        $notification_types = MLD_Client_Notification_Preferences::get_notification_types();
        $timezone_options = MLD_Client_Notification_Preferences::get_timezone_options();

        // Format for iOS consumption
        $types_formatted = array();
        foreach ($notification_types as $type => $info) {
            $push_key = $type . '_push';
            $email_key = $type . '_email';
            $types_formatted[$type] = array(
                'label' => $info['label'],
                'description' => $info['description'],
                'icon' => $info['icon'],
                'push_enabled' => isset($preferences[$push_key]) ? (bool) $preferences[$push_key] : true,
                'email_enabled' => isset($preferences[$email_key]) ? (bool) $preferences[$email_key] : true
            );
        }

        return new WP_REST_Response(array(
            'success' => true,
            'data' => array(
                'notification_types' => $types_formatted,
                'quiet_hours' => array(
                    'enabled' => (bool) $preferences['quiet_hours_enabled'],
                    'start' => $preferences['quiet_hours_start'],
                    'end' => $preferences['quiet_hours_end']
                ),
                'timezone' => $preferences['user_timezone'],
                'timezone_options' => $timezone_options
            )
        ), 200);
    }

    /**
     * PUT /notification-preferences
     * Update client's notification preferences for alerts
     *
     * @since 6.48.0
     */
    public static function handle_update_client_notification_preferences($request) {
        // Prevent CDN caching
        self::send_no_cache_headers();

        $user = wp_get_current_user();

        // Check if preferences class exists
        if (!class_exists('MLD_Client_Notification_Preferences')) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'feature_unavailable',
                'message' => 'Notification preferences not available'
            ), 500);
        }

        $params = $request->get_json_params();

        if (empty($params)) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'missing_data',
                'message' => 'Preference data is required'
            ), 400);
        }

        // Build preferences array from params
        $preferences_to_update = array();

        // Handle notification type toggles
        if (isset($params['notification_types']) && is_array($params['notification_types'])) {
            foreach ($params['notification_types'] as $type => $settings) {
                if (isset($settings['push_enabled'])) {
                    $preferences_to_update[$type . '_push'] = (bool) $settings['push_enabled'];
                }
                if (isset($settings['email_enabled'])) {
                    $preferences_to_update[$type . '_email'] = (bool) $settings['email_enabled'];
                }
            }
        }

        // Handle quiet hours
        if (isset($params['quiet_hours'])) {
            $qh = $params['quiet_hours'];
            if (isset($qh['enabled'])) {
                $preferences_to_update['quiet_hours_enabled'] = (bool) $qh['enabled'];
            }
            if (isset($qh['start'])) {
                $preferences_to_update['quiet_hours_start'] = sanitize_text_field($qh['start']);
            }
            if (isset($qh['end'])) {
                $preferences_to_update['quiet_hours_end'] = sanitize_text_field($qh['end']);
            }
        }

        // Handle timezone
        if (isset($params['timezone'])) {
            $preferences_to_update['user_timezone'] = sanitize_text_field($params['timezone']);
        }

        // Update preferences
        $success = MLD_Client_Notification_Preferences::update_preferences($user->ID, $preferences_to_update);

        if (!$success) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'update_failed',
                'message' => 'Failed to update preferences'
            ), 500);
        }

        // Return updated preferences
        $updated = MLD_Client_Notification_Preferences::get_preferences($user->ID);
        $notification_types = MLD_Client_Notification_Preferences::get_notification_types();

        $types_formatted = array();
        foreach ($notification_types as $type => $info) {
            $push_key = $type . '_push';
            $email_key = $type . '_email';
            $types_formatted[$type] = array(
                'label' => $info['label'],
                'description' => $info['description'],
                'icon' => $info['icon'],
                'push_enabled' => isset($updated[$push_key]) ? (bool) $updated[$push_key] : true,
                'email_enabled' => isset($updated[$email_key]) ? (bool) $updated[$email_key] : true
            );
        }

        return new WP_REST_Response(array(
            'success' => true,
            'message' => 'Preferences updated successfully',
            'data' => array(
                'notification_types' => $types_formatted,
                'quiet_hours' => array(
                    'enabled' => (bool) $updated['quiet_hours_enabled'],
                    'start' => $updated['quiet_hours_start'],
                    'end' => $updated['quiet_hours_end']
                ),
                'timezone' => $updated['user_timezone']
            )
        ), 200);
    }

    /**
     * GET /badge-count
     * Get current unread notification badge count for authenticated user
     *
     * @since 6.49.0
     */
    public static function handle_get_badge_count($request) {
        // Prevent CDN caching
        self::send_no_cache_headers();

        $user = wp_get_current_user();

        // Check if push notifications class exists
        if (!class_exists('MLD_Push_Notifications')) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'feature_unavailable',
                'message' => 'Badge count not available'
            ), 500);
        }

        $badge_data = MLD_Push_Notifications::get_badge_data($user->ID);

        return new WP_REST_Response(array(
            'success' => true,
            'data' => array(
                'badge_count' => $badge_data ? $badge_data['unread_count'] : 0,
                'last_notification_at' => $badge_data ? $badge_data['last_notification_at'] : null,
                'last_read_at' => $badge_data ? $badge_data['last_read_at'] : null
            )
        ), 200);
    }

    /**
     * POST /badge-count/reset
     * Reset badge count to 0 (when user opens app or views notifications)
     *
     * @since 6.49.0
     */
    public static function handle_reset_badge_count($request) {
        // Prevent CDN caching
        self::send_no_cache_headers();

        $user = wp_get_current_user();

        // Check if push notifications class exists
        if (!class_exists('MLD_Push_Notifications')) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'feature_unavailable',
                'message' => 'Badge count not available'
            ), 500);
        }

        $success = MLD_Push_Notifications::reset_badge_count($user->ID);

        if (!$success) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'reset_failed',
                'message' => 'Failed to reset badge count'
            ), 500);
        }

        return new WP_REST_Response(array(
            'success' => true,
            'message' => 'Badge count reset successfully',
            'data' => array(
                'badge_count' => 0
            )
        ), 200);
    }

    /**
     * GET /notifications/history
     * Get notification history for in-app notification center
     *
     * Returns successfully sent notifications for the authenticated user,
     * allowing the iOS app to sync missed notifications when opening.
     *
     * @since 6.49.16
     * @param WP_REST_Request $request Request object with optional params:
     *   - limit: int (default 50, max 100)
     *   - offset: int (default 0)
     *   - since: ISO8601 datetime (optional, only return notifications after this time)
     *   - types: comma-separated notification types (optional filter)
     * @return WP_REST_Response Response with notifications array
     */
    public static function handle_get_notification_history($request) {
        global $wpdb;

        self::send_no_cache_headers();

        $user = wp_get_current_user();
        $user_id = $user->ID;

        // Parse parameters
        $limit = min(absint($request->get_param('limit') ?: 50), 100);
        $offset = absint($request->get_param('offset') ?: 0);
        $since = sanitize_text_field($request->get_param('since'));
        $types_param = sanitize_text_field($request->get_param('types'));

        $table_name = $wpdb->prefix . 'mld_push_notification_log';

        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") !== $table_name) {
            return new WP_REST_Response(array(
                'success' => true,
                'data' => array(
                    'notifications' => array(),
                    'total' => 0,
                    'has_more' => false
                )
            ), 200);
        }

        // Check for include_dismissed parameter
        $include_dismissed = filter_var($request->get_param('include_dismissed'), FILTER_VALIDATE_BOOLEAN);

        // Build WHERE clause
        // Include both 'sent' and 'failed' - notification center shows history of all notifications
        // generated for user, regardless of push delivery status (emails still work for failed push)
        $where = array("user_id = %d", "status IN ('sent', 'failed')");
        $params = array($user_id);

        // Filter out dismissed notifications by default
        if (!$include_dismissed) {
            $where[] = "(is_dismissed = 0 OR is_dismissed IS NULL)";
        }

        // Filter by notification types if provided
        if (!empty($types_param)) {
            $types = array_map('trim', explode(',', $types_param));
            $types = array_filter($types);
            if (!empty($types)) {
                $placeholders = implode(',', array_fill(0, count($types), '%s'));
                $where[] = "notification_type IN ($placeholders)";
                $params = array_merge($params, $types);
            }
        }

        // Filter by since datetime if provided
        if (!empty($since)) {
            $since_time = strtotime($since);
            if ($since_time !== false) {
                $where[] = "created_at > %s";
                $params[] = date('Y-m-d H:i:s', $since_time);
            }
        }

        $where_sql = implode(' AND ', $where);

        // v6.53.0: Deduplicate notifications by content signature within same hour
        // This prevents showing duplicate entries when the same notification was sent to multiple devices
        // Group by: user_id, notification_type, listing_id (or title as fallback), hour bucket
        $dedup_group_by = "user_id, notification_type,
                           COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.listing_id')), title),
                           DATE_FORMAT(created_at, '%%Y-%%m-%%d %%H')";

        // Get total count (of unique notifications after deduplication)
        $count_sql = "SELECT COUNT(*) FROM (
                          SELECT 1 FROM {$table_name}
                          WHERE {$where_sql}
                          GROUP BY {$dedup_group_by}
                      ) as unique_notifications";
        $total = (int) $wpdb->get_var($wpdb->prepare($count_sql, $params));

        // Get unread count for badge/UI (deduplicated)
        // Include both 'sent' and 'failed' - show count of all notifications generated for user
        $unread_sql = "SELECT COUNT(*) FROM (
                           SELECT 1 FROM {$table_name}
                           WHERE user_id = %d AND status IN ('sent', 'failed')
                           AND (is_read = 0 OR is_read IS NULL)
                           AND (is_dismissed = 0 OR is_dismissed IS NULL)
                           GROUP BY {$dedup_group_by}
                       ) as unique_unread";
        $unread_count = (int) $wpdb->get_var($wpdb->prepare($unread_sql, $user_id));

        // Get notifications (most recent first, deduplicated)
        // For each unique notification group, we take:
        // - MIN(id) as the representative ID
        // - MIN(created_at) as the earliest send time
        // - MAX(is_read) to capture if ANY copy was read
        // - MAX(is_dismissed) to capture if ANY copy was dismissed
        $query_sql = "SELECT
                          MIN(id) as id,
                          notification_type,
                          title,
                          body,
                          MAX(payload) as payload,
                          MIN(created_at) as created_at,
                          MAX(COALESCE(is_read, 0)) as is_read,
                          MAX(read_at) as read_at,
                          MAX(COALESCE(is_dismissed, 0)) as is_dismissed,
                          MAX(dismissed_at) as dismissed_at
                      FROM {$table_name}
                      WHERE {$where_sql}
                      GROUP BY {$dedup_group_by}
                      ORDER BY MIN(created_at) DESC
                      LIMIT %d OFFSET %d";

        $query_params = array_merge($params, array($limit, $offset));
        $rows = $wpdb->get_results($wpdb->prepare($query_sql, $query_params));

        // Format notifications for iOS
        $notifications = array();
        foreach ($rows as $row) {
            $payload = json_decode($row->payload, true);

            // Extract key fields from payload
            $listing_id = null;
            $listing_key = null;
            $image_url = null;
            $saved_search_id = null;
            $saved_search_name = null;
            $appointment_id = null;
            $client_id = null;
            $open_house_id = null;

            if (is_array($payload)) {
                $listing_id = isset($payload['listing_id']) ? $payload['listing_id'] : null;
                $listing_key = isset($payload['listing_key']) ? $payload['listing_key'] : null;
                $image_url = isset($payload['image_url']) ? $payload['image_url'] : null;
                $saved_search_id = isset($payload['saved_search_id']) ? $payload['saved_search_id'] : null;
                $saved_search_name = isset($payload['saved_search_name']) ? $payload['saved_search_name'] : null;
                $appointment_id = isset($payload['appointment_id']) ? $payload['appointment_id'] : null;
                $client_id = isset($payload['client_id']) ? $payload['client_id'] : null;
                $open_house_id = isset($payload['open_house_id']) ? $payload['open_house_id'] : null;

                // Also check for photo_url as fallback for image
                if (empty($image_url) && isset($payload['photo_url'])) {
                    $image_url = $payload['photo_url'];
                }
            }

            $notifications[] = array(
                'id' => (int) $row->id,
                'notification_type' => $row->notification_type,
                'title' => $row->title,
                'body' => $row->body,
                'listing_id' => $listing_id !== null ? (string) $listing_id : null,
                'listing_key' => $listing_key,
                'image_url' => $image_url,
                'saved_search_id' => $saved_search_id !== null ? (int) $saved_search_id : null,
                'saved_search_name' => $saved_search_name,
                'appointment_id' => $appointment_id !== null ? (int) $appointment_id : null,
                'client_id' => $client_id !== null ? (int) $client_id : null,
                'open_house_id' => $open_house_id !== null ? (int) $open_house_id : null,
                'sent_at' => self::format_datetime_iso8601($row->created_at),
                'is_read' => (bool) $row->is_read,
                'read_at' => self::format_datetime_iso8601($row->read_at),
                'is_dismissed' => (bool) $row->is_dismissed,
                'dismissed_at' => self::format_datetime_iso8601($row->dismissed_at),
            );
        }

        return new WP_REST_Response(array(
            'success' => true,
            'data' => array(
                'notifications' => $notifications,
                'total' => $total,
                'unread_count' => $unread_count,
                'has_more' => ($offset + count($notifications)) < $total
            )
        ), 200);
    }

    /**
     * POST /notifications/{id}/read
     * Mark a single notification as read
     *
     * @since 6.50.0
     * @param WP_REST_Request $request Request object with 'id' parameter
     * @return WP_REST_Response Response
     */
    public static function handle_mark_notification_read($request) {
        global $wpdb;

        self::send_no_cache_headers();

        $user = wp_get_current_user();
        $user_id = $user->ID;
        $notification_id = absint($request->get_param('id'));

        $table_name = $wpdb->prefix . 'mld_push_notification_log';

        // Verify notification belongs to this user
        $notification = $wpdb->get_row($wpdb->prepare(
            "SELECT id, is_read FROM {$table_name} WHERE id = %d AND user_id = %d",
            $notification_id,
            $user_id
        ));

        if (!$notification) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'not_found',
                'message' => 'Notification not found'
            ), 404);
        }

        // Update read status
        $wpdb->update(
            $table_name,
            array(
                'is_read' => 1,
                'read_at' => current_time('mysql')
            ),
            array('id' => $notification_id),
            array('%d', '%s'),
            array('%d')
        );

        // Get updated unread count
        $unread_sql = "SELECT COUNT(DISTINCT id) FROM {$table_name}
                       WHERE user_id = %d AND status = 'sent'
                       AND (is_read = 0 OR is_read IS NULL)
                       AND (is_dismissed = 0 OR is_dismissed IS NULL)";
        $unread_count = (int) $wpdb->get_var($wpdb->prepare($unread_sql, $user_id));

        return new WP_REST_Response(array(
            'success' => true,
            'message' => 'Notification marked as read',
            'data' => array(
                'id' => $notification_id,
                'is_read' => true,
                'read_at' => date('c'),
                'unread_count' => $unread_count
            )
        ), 200);
    }

    /**
     * POST /notifications/{id}/dismiss
     * Dismiss/delete a single notification
     *
     * @since 6.50.0
     * @param WP_REST_Request $request Request object with 'id' parameter
     * @return WP_REST_Response Response
     */
    public static function handle_dismiss_notification($request) {
        global $wpdb;

        self::send_no_cache_headers();

        $user = wp_get_current_user();
        $user_id = $user->ID;
        $notification_id = absint($request->get_param('id'));

        $table_name = $wpdb->prefix . 'mld_push_notification_log';

        // Verify notification belongs to this user
        $notification = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$table_name} WHERE id = %d AND user_id = %d",
            $notification_id,
            $user_id
        ));

        if (!$notification) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'not_found',
                'message' => 'Notification not found'
            ), 404);
        }

        // Update dismissed status
        $wpdb->update(
            $table_name,
            array(
                'is_dismissed' => 1,
                'dismissed_at' => current_time('mysql')
            ),
            array('id' => $notification_id),
            array('%d', '%s'),
            array('%d')
        );

        // Get updated unread count
        $unread_sql = "SELECT COUNT(DISTINCT id) FROM {$table_name}
                       WHERE user_id = %d AND status = 'sent'
                       AND (is_read = 0 OR is_read IS NULL)
                       AND (is_dismissed = 0 OR is_dismissed IS NULL)";
        $unread_count = (int) $wpdb->get_var($wpdb->prepare($unread_sql, $user_id));

        return new WP_REST_Response(array(
            'success' => true,
            'message' => 'Notification dismissed',
            'data' => array(
                'id' => $notification_id,
                'is_dismissed' => true,
                'dismissed_at' => date('c'),
                'unread_count' => $unread_count
            )
        ), 200);
    }

    /**
     * POST /notifications/mark-all-read
     * Mark all notifications as read for the current user
     *
     * @since 6.50.0
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response Response
     */
    public static function handle_mark_all_notifications_read($request) {
        global $wpdb;

        self::send_no_cache_headers();

        $user = wp_get_current_user();
        $user_id = $user->ID;

        $table_name = $wpdb->prefix . 'mld_push_notification_log';

        // Update all unread notifications for this user
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$table_name}
             SET is_read = 1, read_at = %s
             WHERE user_id = %d
             AND status = 'sent'
             AND (is_read = 0 OR is_read IS NULL)
             AND (is_dismissed = 0 OR is_dismissed IS NULL)",
            current_time('mysql'),
            $user_id
        ));

        return new WP_REST_Response(array(
            'success' => true,
            'message' => 'All notifications marked as read',
            'data' => array(
                'updated_count' => (int) $updated,
                'unread_count' => 0
            )
        ), 200);
    }

    /**
     * POST /notifications/dismiss-all
     * Dismiss all notifications for the current user
     *
     * @since 6.50.3
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response Response
     */
    public static function handle_dismiss_all_notifications($request) {
        global $wpdb;

        self::send_no_cache_headers();

        $user = wp_get_current_user();
        $user_id = $user->ID;

        $table_name = $wpdb->prefix . 'mld_push_notification_log';

        // Dismiss all non-dismissed notifications for this user
        // Include both 'sent' and 'failed' status notifications
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$table_name}
             SET is_dismissed = 1, dismissed_at = %s
             WHERE user_id = %d
             AND status IN ('sent', 'failed')
             AND (is_dismissed = 0 OR is_dismissed IS NULL)",
            current_time('mysql'),
            $user_id
        ));

        return new WP_REST_Response(array(
            'success' => true,
            'message' => 'All notifications dismissed',
            'data' => array(
                'dismissed_count' => (int) $updated
            )
        ), 200);
    }

    /**
     * POST /notifications/engagement
     * Track notification engagement (opens, dismissals, clicks)
     *
     * @since 6.49.4
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response Response
     */
    public static function handle_notification_engagement($request) {
        global $wpdb;

        self::send_no_cache_headers();

        $user = wp_get_current_user();

        // Required parameters
        $notification_type = sanitize_text_field($request->get_param('notification_type'));
        $action = sanitize_text_field($request->get_param('action'));

        if (empty($notification_type) || empty($action)) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'missing_params',
                'message' => 'notification_type and action are required'
            ), 400);
        }

        // Validate action
        $valid_actions = array('delivered', 'opened', 'dismissed', 'clicked');
        if (!in_array($action, $valid_actions)) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'invalid_action',
                'message' => 'action must be one of: ' . implode(', ', $valid_actions)
            ), 400);
        }

        // Optional parameters
        $listing_id = sanitize_text_field($request->get_param('listing_id'));
        $saved_search_id = absint($request->get_param('saved_search_id'));
        $appointment_id = absint($request->get_param('appointment_id'));
        $platform = sanitize_text_field($request->get_param('platform')) ?: 'ios';
        $device_model = sanitize_text_field($request->get_param('device_model'));
        $app_version = sanitize_text_field($request->get_param('app_version'));
        $metadata = $request->get_param('metadata');

        $table_name = $wpdb->prefix . 'mld_notification_engagement';

        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") !== $table_name) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'table_missing',
                'message' => 'Engagement tracking table not initialized'
            ), 500);
        }

        $result = $wpdb->insert(
            $table_name,
            array(
                'user_id' => $user->ID,
                'notification_type' => $notification_type,
                'action' => $action,
                'listing_id' => $listing_id ?: null,
                'saved_search_id' => $saved_search_id ?: null,
                'appointment_id' => $appointment_id ?: null,
                'platform' => $platform,
                'device_model' => $device_model ?: null,
                'app_version' => $app_version ?: null,
                'metadata' => $metadata ? json_encode($metadata) : null,
                'created_at' => current_time('mysql'),
            ),
            array('%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s')
        );

        if (!$result) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'insert_failed',
                'message' => 'Failed to track engagement'
            ), 500);
        }

        return new WP_REST_Response(array(
            'success' => true,
            'message' => 'Engagement tracked successfully',
            'data' => array(
                'id' => $wpdb->insert_id
            )
        ), 200);
    }

    /**
     * GET /admin/notification-engagement-stats
     * Get notification engagement statistics for admin dashboard
     *
     * @since 6.49.4
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response Response
     */
    public static function handle_get_engagement_stats($request) {
        global $wpdb;

        self::send_no_cache_headers();

        $period = sanitize_text_field($request->get_param('period')) ?: 'week';
        $table_name = $wpdb->prefix . 'mld_notification_engagement';

        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") !== $table_name) {
            return new WP_REST_Response(array(
                'success' => true,
                'data' => array(
                    'total_delivered' => 0,
                    'total_opened' => 0,
                    'open_rate' => 0,
                    'by_type' => array(),
                    'by_action' => array(),
                )
            ), 200);
        }

        // Determine date filter
        switch ($period) {
            case 'month':
                $date_filter = 'DATE_SUB(NOW(), INTERVAL 30 DAY)';
                break;
            case 'day':
                $date_filter = 'DATE_SUB(NOW(), INTERVAL 1 DAY)';
                break;
            default:
                $date_filter = 'DATE_SUB(NOW(), INTERVAL 7 DAY)';
        }

        // Get overall counts by action
        $by_action = $wpdb->get_results("
            SELECT
                action,
                COUNT(*) as count
            FROM {$table_name}
            WHERE created_at >= {$date_filter}
            GROUP BY action
        ", ARRAY_A);

        $action_counts = array_column($by_action, 'count', 'action');
        $delivered = (int) ($action_counts['delivered'] ?? 0);
        $opened = (int) ($action_counts['opened'] ?? 0);

        // Get counts by notification type
        $by_type = $wpdb->get_results("
            SELECT
                notification_type,
                action,
                COUNT(*) as count
            FROM {$table_name}
            WHERE created_at >= {$date_filter}
            GROUP BY notification_type, action
            ORDER BY notification_type, action
        ", ARRAY_A);

        // Organize by type with actions
        $by_type_organized = array();
        foreach ($by_type as $row) {
            $type = $row['notification_type'];
            if (!isset($by_type_organized[$type])) {
                $by_type_organized[$type] = array(
                    'delivered' => 0,
                    'opened' => 0,
                    'dismissed' => 0,
                    'clicked' => 0,
                );
            }
            $by_type_organized[$type][$row['action']] = (int) $row['count'];
        }

        // Calculate open rates per type
        foreach ($by_type_organized as $type => &$data) {
            $data['open_rate'] = $data['delivered'] > 0
                ? round(($data['opened'] / $data['delivered']) * 100, 1)
                : 0;
        }

        return new WP_REST_Response(array(
            'success' => true,
            'data' => array(
                'period' => $period,
                'total_delivered' => $delivered,
                'total_opened' => $opened,
                'open_rate' => $delivered > 0 ? round(($opened / $delivered) * 100, 1) : 0,
                'by_type' => $by_type_organized,
                'by_action' => $action_counts,
            )
        ), 200);
    }

    /**
     * GET /health
     * Public health check endpoint for monitoring
     *
     * Returns system status, table verification, record counts, and feature flags.
     * This endpoint is public (no authentication required).
     *
     * @since 6.43.1
     * @return WP_REST_Response Health status data
     */
    public static function health_check($request) {
        global $wpdb;

        $health_data = array(
            'status' => 'healthy',
            'version' => defined('MLD_VERSION') ? MLD_VERSION : 'unknown',
            'timestamp' => current_time('c'),
            'tables' => array(),
            'record_counts' => array(),
            'features' => array(),
            'cron_jobs' => array(),
        );

        // ===== Table Verification =====
        $critical_tables = array(
            'bme_listings' => 'Core Listings',
            'bme_listing_summary' => 'Summary Table',
            'bme_media' => 'Media/Photos',
            'mld_saved_searches' => 'Saved Searches',
            'mld_property_preferences' => 'Favorites/Hidden',
            'mld_agent_profiles' => 'Agent Profiles',
            'mld_agent_client_relationships' => 'Agent-Client',
            'mld_shared_properties' => 'Shared Properties',
            'mld_public_sessions' => 'Analytics Sessions',
            'mld_agent_notification_preferences' => 'Agent Notifications',
        );

        $all_tables_ok = true;
        foreach ($critical_tables as $table_suffix => $label) {
            $table_name = $wpdb->prefix . $table_suffix;
            $exists = $wpdb->get_var($wpdb->prepare(
                "SHOW TABLES LIKE %s",
                $table_name
            ));

            $health_data['tables'][$table_suffix] = array(
                'label' => $label,
                'exists' => !empty($exists),
            );

            // Only mark as degraded if core tables are missing
            if (empty($exists) && in_array($table_suffix, array('bme_listings', 'bme_listing_summary', 'mld_saved_searches'))) {
                $all_tables_ok = false;
            }
        }

        // ===== Record Counts =====
        $count_tables = array(
            'bme_listings' => "SELECT COUNT(*) FROM {$wpdb->prefix}bme_listings WHERE standard_status = 'Active'",
            'bme_listing_summary' => "SELECT COUNT(*) FROM {$wpdb->prefix}bme_listing_summary WHERE standard_status = 'Active'",
            'mld_saved_searches' => "SELECT COUNT(*) FROM {$wpdb->prefix}mld_saved_searches",
            'mld_agent_profiles' => "SELECT COUNT(*) FROM {$wpdb->prefix}mld_agent_profiles",
            'mld_agent_client_relationships' => "SELECT COUNT(*) FROM {$wpdb->prefix}mld_agent_client_relationships",
            'mld_shared_properties' => "SELECT COUNT(*) FROM {$wpdb->prefix}mld_shared_properties",
        );

        foreach ($count_tables as $key => $sql) {
            // Only count if table exists
            if (!empty($health_data['tables'][$key]['exists'])) {
                $count = $wpdb->get_var($sql);
                $health_data['record_counts'][$key] = (int) $count;
            }
        }

        // ===== Summary Table Sync Check =====
        if (!empty($health_data['record_counts']['bme_listings']) &&
            !empty($health_data['record_counts']['bme_listing_summary'])) {
            $diff = abs($health_data['record_counts']['bme_listings'] - $health_data['record_counts']['bme_listing_summary']);
            $health_data['summary_sync'] = array(
                'in_sync' => $diff <= 10, // Allow small variance
                'difference' => $diff
            );
            if ($diff > 100) {
                $health_data['status'] = 'degraded';
            }
        }

        // ===== Feature Flags =====
        $health_data['features'] = array(
            'saved_searches' => true,
            'agent_client_system' => class_exists('MLD_Agent_Client_Manager'),
            'shared_properties' => class_exists('MLD_Shared_Properties_Manager'),
            'analytics' => class_exists('MLD_Public_Analytics_Tracker'),
            'agent_notifications' => class_exists('MLD_Agent_Notification_Preferences'),
            'push_notifications' => class_exists('MLD_Push_Notifications'),
        );

        // ===== Cron Job Status =====
        $cron_hooks = array(
            'mld_saved_search_instant' => 'Instant Alerts',
            'mld_saved_search_hourly' => 'Hourly Alerts',
            'mld_saved_search_daily' => 'Daily Alerts',
            'mld_analytics_hourly_refresh' => 'Analytics Refresh',
        );

        foreach ($cron_hooks as $hook => $label) {
            $next = wp_next_scheduled($hook);
            $health_data['cron_jobs'][$hook] = array(
                'label' => $label,
                'scheduled' => !empty($next),
                'next_run' => $next ? wp_date('c', $next) : null,
                'overdue' => $next && $next < (time() - 300), // More than 5 mins overdue
            );
        }

        // Set overall status
        if (!$all_tables_ok) {
            $health_data['status'] = 'degraded';
        }

        return new WP_REST_Response(array(
            'success' => true,
            'data' => $health_data
        ), 200);
    }

    /**
     * GET /unified-health
     * Comprehensive health check across all BMN Boston components.
     *
     * Designed for external monitoring services (Uptime Robot, Pingdom, etc.)
     * Returns status for MLD, BMN Schools, and SN Appointments.
     *
     * Response format:
     * {
     *   "status": "healthy|degraded|unhealthy",
     *   "timestamp": "2026-01-12T10:30:00-05:00",
     *   "response_time_ms": 45,
     *   "components": {
     *     "mld": "ok|warning|error",
     *     "schools": "ok|warning|error",
     *     "snab": "ok|warning|error"
     *   }
     * }
     *
     * Exit codes for monitoring services:
     * - HTTP 200 = healthy
     * - HTTP 207 = degraded (partial issues)
     * - HTTP 503 = unhealthy (critical issues)
     *
     * @since 6.58.0
     * @return WP_REST_Response Health status
     */
    public static function handle_unified_health($request) {
        // Load health monitor
        $health_path = MLD_PLUGIN_PATH . 'includes/health/class-mld-health-monitor.php';
        if (!class_exists('MLD_Health_Monitor') && file_exists($health_path)) {
            require_once $health_path;
        }

        if (!class_exists('MLD_Health_Monitor')) {
            return new WP_REST_Response(array(
                'status' => 'error',
                'message' => 'Health monitor not available',
            ), 500);
        }

        $monitor = MLD_Health_Monitor::get_instance();
        $results = $monitor->run_quick_check();

        // Add CDN bypass headers for fresh response
        $response = new WP_REST_Response(array(
            'status' => $results['status'],
            'timestamp' => current_time('c'),
            'response_time_ms' => $results['response_time_ms'],
            'components' => $results['components'],
        ));

        // Set HTTP status based on health
        if ($results['status'] === 'unhealthy') {
            $response->set_status(503); // Service Unavailable
        } elseif ($results['status'] === 'degraded') {
            $response->set_status(207); // Multi-Status (partial success)
        } else {
            $response->set_status(200);
        }

        // Add CDN bypass headers
        $response->header('Cache-Control', 'no-store, no-cache, must-revalidate, private');
        $response->header('Pragma', 'no-cache');
        $response->header('X-Kinsta-Cache', 'BYPASS');

        return $response;
    }

    /**
     * GET /ping
     * Simple uptime check endpoint.
     *
     * Returns minimal response for basic uptime monitoring.
     * Response format: {"pong": true, "timestamp": "..."}
     *
     * @since 6.58.0
     * @return WP_REST_Response Simple ping response
     */
    public static function handle_ping($request) {
        $response = new WP_REST_Response(array(
            'pong' => true,
            'timestamp' => current_time('c'),
        ), 200);

        // Add CDN bypass headers
        $response->header('Cache-Control', 'no-store, no-cache, must-revalidate, private');
        $response->header('Pragma', 'no-cache');
        $response->header('X-Kinsta-Cache', 'BYPASS');

        return $response;
    }

    /**
     * GET /settings/disclosure
     * Returns MLS disclosure settings for display in iOS app
     *
     * Returns:
     * - enabled: Whether disclosure is enabled
     * - logo_url: URL to MLS logo image (or null if not set)
     * - disclosure_text: HTML disclosure text (or null if not set)
     *
     * @since 6.49.9
     * @return WP_REST_Response Disclosure settings
     */
    public static function handle_get_disclosure_settings($request) {
        $disclosure_settings = get_option('mld_disclosure_settings', array());

        $enabled = !empty($disclosure_settings['enabled']);
        $logo_url = !empty($disclosure_settings['logo_url']) ? $disclosure_settings['logo_url'] : null;
        $disclosure_text = !empty($disclosure_settings['disclosure_text']) ? $disclosure_settings['disclosure_text'] : null;

        // Strip HTML tags for plain text version (iOS can use either)
        $disclosure_text_plain = $disclosure_text ? wp_strip_all_tags($disclosure_text) : null;

        return new WP_REST_Response(array(
            'success' => true,
            'data' => array(
                'enabled' => $enabled,
                'logo_url' => $logo_url,
                'disclosure_text' => $disclosure_text,
                'disclosure_text_plain' => $disclosure_text_plain,
            )
        ), 200);
    }

    /**
     * GET /settings/site-contact
     * Returns default site contact information for iOS app
     * Used when user is not logged in or doesn't have an assigned agent
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function handle_get_site_contact_settings($request) {
        // Get theme mods (customizer settings)
        $theme_mods = get_theme_mods();

        // Get contact info from theme customizer (bne_ prefix = BMN theme settings)
        $name = !empty($theme_mods['bne_agent_name']) ? $theme_mods['bne_agent_name'] : get_bloginfo('name');
        $phone = !empty($theme_mods['bne_phone_number']) ? $theme_mods['bne_phone_number'] : null;
        $email = !empty($theme_mods['bne_agent_email']) ? $theme_mods['bne_agent_email'] : get_option('admin_email');

        // Also get photo URL if available
        $photo_url = !empty($theme_mods['bne_agent_photo']) ? $theme_mods['bne_agent_photo'] : null;

        // Get brokerage info
        $brokerage_name = !empty($theme_mods['bne_group_name']) ? $theme_mods['bne_group_name'] : null;

        return new WP_REST_Response(array(
            'success' => true,
            'data' => array(
                'name' => $name,
                'phone' => $phone,
                'email' => $email,
                'photo_url' => $photo_url,
                'brokerage_name' => $brokerage_name,
            )
        ), 200);
    }

    // ============ Notification Analytics Handlers (v6.48.0) ============

    /**
     * Handle get notification analytics summary
     * Returns overall notification stats for admin dashboard
     */
    public static function handle_get_notification_analytics($request) {
        self::send_no_cache_headers();

        if (!class_exists('MLD_Notification_Analytics')) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'analytics_unavailable',
                'message' => 'Notification analytics not available'
            ), 503);
        }

        $days = absint($request->get_param('days') ?: 30);
        $days = min($days, 90); // Cap at 90 days

        // Calculate date range from days
        $end_date = date('Y-m-d');
        $start_date = date('Y-m-d', strtotime("-{$days} days"));

        $summary = MLD_Notification_Analytics::get_summary($start_date, $end_date);

        return new WP_REST_Response(array(
            'success' => true,
            'data' => array(
                'days' => $days,
                'summary' => $summary
            )
        ), 200);
    }

    /**
     * Handle get notification analytics breakdown by type
     * Returns stats broken down by notification type
     */
    public static function handle_get_notification_analytics_by_type($request) {
        self::send_no_cache_headers();

        if (!class_exists('MLD_Notification_Analytics')) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'analytics_unavailable',
                'message' => 'Notification analytics not available'
            ), 503);
        }

        $days = absint($request->get_param('days') ?: 30);
        $days = min($days, 90);

        // Calculate date range from days
        $end_date = date('Y-m-d');
        $start_date = date('Y-m-d', strtotime("-{$days} days"));

        $by_type = MLD_Notification_Analytics::get_breakdown_by_type($start_date, $end_date);
        $by_channel = MLD_Notification_Analytics::get_breakdown_by_channel($start_date, $end_date);

        return new WP_REST_Response(array(
            'success' => true,
            'data' => array(
                'days' => $days,
                'by_type' => $by_type,
                'by_channel' => $by_channel
            )
        ), 200);
    }

    /**
     * Handle get notification analytics trend
     * Returns daily trend data for charts
     */
    public static function handle_get_notification_analytics_trend($request) {
        self::send_no_cache_headers();

        if (!class_exists('MLD_Notification_Analytics')) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'analytics_unavailable',
                'message' => 'Notification analytics not available'
            ), 503);
        }

        $days = absint($request->get_param('days') ?: 30);
        $days = min($days, 90);
        $notification_type = sanitize_text_field($request->get_param('type') ?: '');

        // Calculate date range from days
        $end_date = date('Y-m-d');
        $start_date = date('Y-m-d', strtotime("-{$days} days"));

        $trend = MLD_Notification_Analytics::get_daily_trend($start_date, $end_date, $notification_type ?: null);

        return new WP_REST_Response(array(
            'success' => true,
            'data' => array(
                'days' => $days,
                'type_filter' => $notification_type ?: 'all',
                'trend' => $trend
            )
        ), 200);
    }

    // ============ Device Token Handlers (Push Notifications) ============

    /**
     * Register device token for push notifications
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function handle_register_device_token($request) {
        global $wpdb;

        $user_id = get_current_user_id();
        $device_token = sanitize_text_field($request->get_param('device_token'));
        $device_type = sanitize_text_field($request->get_param('device_type') ?: 'ios');
        $is_sandbox = (bool) $request->get_param('is_sandbox');

        if (empty($device_token)) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'missing_token',
                'message' => 'Device token is required'
            ), 400);
        }

        $table_name = $wpdb->prefix . 'mld_device_tokens';

        // Create table if it doesn't exist
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") !== $table_name) {
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            $charset_collate = $wpdb->get_charset_collate();
            $sql = "CREATE TABLE {$table_name} (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT(20) UNSIGNED NOT NULL,
                device_token VARCHAR(255) NOT NULL,
                platform ENUM('ios', 'android') DEFAULT 'ios',
                is_sandbox BOOLEAN DEFAULT FALSE,
                app_version VARCHAR(20),
                device_model VARCHAR(100),
                is_active BOOLEAN DEFAULT TRUE,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                last_used_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY unique_user_device (user_id, device_token),
                KEY idx_user_id (user_id),
                KEY idx_device_token (device_token),
                KEY idx_active (is_active)
            ) {$charset_collate}";
            dbDelta($sql);
        }

        // Check if token already exists for this user
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id, is_active FROM {$table_name} WHERE user_id = %d AND device_token = %s",
            $user_id,
            $device_token
        ));

        if ($existing) {
            // Reactivate if inactive
            $wpdb->update(
                $table_name,
                array(
                    'is_active' => 1,
                    'is_sandbox' => $is_sandbox,
                    'platform' => $device_type,
                    'last_used_at' => current_time('mysql')
                ),
                array('id' => $existing->id),
                array('%d', '%d', '%s', '%s'),
                array('%d')
            );

            return new WP_REST_Response(array(
                'success' => true,
                'message' => 'Device token updated',
                'data' => array('id' => $existing->id)
            ), 200);
        }

        // Insert new token
        $result = $wpdb->insert(
            $table_name,
            array(
                'user_id' => $user_id,
                'device_token' => $device_token,
                'platform' => $device_type,
                'is_sandbox' => $is_sandbox,
                'is_active' => 1,
                'created_at' => current_time('mysql'),
                'last_used_at' => current_time('mysql')
            ),
            array('%d', '%s', '%s', '%d', '%d', '%s', '%s')
        );

        if ($result === false) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'insert_failed',
                'message' => 'Failed to register device token'
            ), 500);
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("MLD_Mobile_REST_API: Device token registered for user {$user_id} (sandbox: " . ($is_sandbox ? 'yes' : 'no') . ")");
        }

        return new WP_REST_Response(array(
            'success' => true,
            'message' => 'Device token registered',
            'data' => array('id' => $wpdb->insert_id)
        ), 201);
    }

    /**
     * Unregister device token for push notifications
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function handle_unregister_device_token($request) {
        global $wpdb;

        $user_id = get_current_user_id();
        $device_token = sanitize_text_field($request->get_param('device_token'));

        if (empty($device_token)) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'missing_token',
                'message' => 'Device token is required'
            ), 400);
        }

        $table_name = $wpdb->prefix . 'mld_device_tokens';

        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") !== $table_name) {
            return new WP_REST_Response(array(
                'success' => true,
                'message' => 'No device tokens registered',
                'data' => array()
            ), 200);
        }

        // Deactivate the token (soft delete)
        $result = $wpdb->update(
            $table_name,
            array('is_active' => 0),
            array(
                'user_id' => $user_id,
                'device_token' => $device_token
            ),
            array('%d'),
            array('%d', '%s')
        );

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("MLD_Mobile_REST_API: Device token unregistered for user {$user_id}");
        }

        return new WP_REST_Response(array(
            'success' => true,
            'message' => 'Device token unregistered',
            'data' => array('affected' => $result ?: 0)
        ), 200);
    }

    // ============================================================
    // RECENTLY VIEWED PROPERTIES HANDLERS (v6.57.0)
    // ============================================================

    /**
     * Record a property view
     *
     * POST /recently-viewed
     *
     * Expected body:
     * - listing_id: string (MLS number, required)
     * - listing_key: string (hash, optional - will lookup if not provided)
     * - view_source: string (search, saved_search, shared, notification, direct, favorites)
     * - platform: string (ios, web, admin)
     *
     * @since 6.57.0
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public static function handle_record_property_view($request) {
        global $wpdb;

        // Prevent CDN caching
        self::send_no_cache_headers();

        $user_id = get_current_user_id();
        $listing_id = sanitize_text_field($request->get_param('listing_id'));
        $listing_key = sanitize_text_field($request->get_param('listing_key'));
        $view_source = sanitize_text_field($request->get_param('view_source') ?: 'search');
        $platform = sanitize_text_field($request->get_param('platform') ?: 'ios');

        // Validate listing_id
        if (empty($listing_id)) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'missing_listing_id',
                'message' => 'listing_id is required'
            ), 400);
        }

        // Validate view_source
        $valid_sources = array('search', 'saved_search', 'shared', 'notification', 'direct', 'favorites');
        if (!in_array($view_source, $valid_sources)) {
            $view_source = 'search';
        }

        // Validate platform
        $valid_platforms = array('ios', 'web', 'admin');
        if (!in_array($platform, $valid_platforms)) {
            $platform = 'ios';
        }

        // If listing_key not provided, look it up from the summary table
        if (empty($listing_key)) {
            $summary_table = $wpdb->prefix . 'bme_listing_summary';
            $listing_key = $wpdb->get_var($wpdb->prepare(
                "SELECT listing_key FROM {$summary_table} WHERE listing_id = %s",
                $listing_id
            ));

            // If not in active, check archive
            if (empty($listing_key)) {
                $archive_table = $wpdb->prefix . 'bme_listing_summary_archive';
                $listing_key = $wpdb->get_var($wpdb->prepare(
                    "SELECT listing_key FROM {$archive_table} WHERE listing_id = %s",
                    $listing_id
                ));
            }

            // Still not found - generate a placeholder
            if (empty($listing_key)) {
                $listing_key = 'unknown_' . md5($listing_id);
            }
        }

        $table = $wpdb->prefix . 'mld_recently_viewed_properties';

        // Use current_time() for WordPress timezone (Rule 13)
        $now = current_time('mysql');

        // Insert or update (UNIQUE KEY on user_id, listing_id handles duplicates)
        $result = $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table} (user_id, listing_id, listing_key, viewed_at, view_source, platform)
             VALUES (%d, %s, %s, %s, %s, %s)
             ON DUPLICATE KEY UPDATE viewed_at = %s, view_source = %s, platform = %s",
            $user_id,
            $listing_id,
            $listing_key,
            $now,
            $view_source,
            $platform,
            $now,
            $view_source,
            $platform
        ));

        if ($result === false) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'database_error',
                'message' => 'Failed to record property view'
            ), 500);
        }

        return new WP_REST_Response(array(
            'success' => true,
            'message' => 'Property view recorded',
            'data' => array(
                'listing_id' => $listing_id,
                'viewed_at' => self::format_datetime_iso8601($now)
            )
        ), 200);
    }

    /**
     * Get user's recently viewed properties
     *
     * GET /recently-viewed
     *
     * Query parameters:
     * - limit: int (default 20, max 100)
     * - offset: int (default 0)
     * - days: int (default 7, max 30)
     *
     * @since 6.57.0
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public static function handle_get_recently_viewed($request) {
        global $wpdb;

        // Prevent CDN caching
        self::send_no_cache_headers();

        $user_id = get_current_user_id();
        $limit = min(absint($request->get_param('limit') ?: 20), 100);
        $offset = absint($request->get_param('offset') ?: 0);
        $days = min(absint($request->get_param('days') ?: 7), 30);

        $viewed_table = $wpdb->prefix . 'mld_recently_viewed_properties';
        $summary_table = $wpdb->prefix . 'bme_listing_summary';
        $archive_table = $wpdb->prefix . 'bme_listing_summary_archive';

        // Use current_time() for WordPress timezone (Rule 13)
        $cutoff_date = wp_date('Y-m-d H:i:s', current_time('timestamp') - ($days * DAY_IN_SECONDS));

        // Get recently viewed listing IDs with metadata
        $viewed_records = $wpdb->get_results($wpdb->prepare(
            "SELECT listing_id, listing_key, viewed_at, view_source, platform
             FROM {$viewed_table}
             WHERE user_id = %d AND viewed_at >= %s
             ORDER BY viewed_at DESC
             LIMIT %d OFFSET %d",
            $user_id,
            $cutoff_date,
            $limit,
            $offset
        ));

        if (empty($viewed_records)) {
            return new WP_REST_Response(array(
                'success' => true,
                'data' => array(
                    'properties' => array(),
                    'count' => 0,
                    'total' => 0
                )
            ), 200);
        }

        // Get total count for pagination
        $total = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$viewed_table} WHERE user_id = %d AND viewed_at >= %s",
            $user_id,
            $cutoff_date
        ));

        // Build lookup maps
        $listing_ids = array_column($viewed_records, 'listing_id');
        $view_metadata = array();
        foreach ($viewed_records as $record) {
            $view_metadata[$record->listing_id] = array(
                'viewed_at' => $record->viewed_at,
                'view_source' => $record->view_source,
                'platform' => $record->platform
            );
        }

        // Get property details from active listings
        $placeholders = implode(',', array_fill(0, count($listing_ids), '%s'));
        $active_properties = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$summary_table} WHERE listing_id IN ({$placeholders})",
            $listing_ids
        ), OBJECT_K);

        // Get any missing from archive
        $found_ids = array_keys($active_properties);
        $missing_ids = array_diff($listing_ids, $found_ids);

        if (!empty($missing_ids)) {
            $missing_placeholders = implode(',', array_fill(0, count($missing_ids), '%s'));
            $archived_properties = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$archive_table} WHERE listing_id IN ({$missing_placeholders})",
                array_values($missing_ids)
            ), OBJECT_K);
            $active_properties = array_merge($active_properties, $archived_properties);
        }

        // Format properties in order of recent views
        $formatted = array();
        foreach ($listing_ids as $listing_id) {
            if (!isset($active_properties[$listing_id])) {
                continue;
            }

            $listing = $active_properties[$listing_id];
            $metadata = $view_metadata[$listing_id];

            // Build address from components
            $address_parts = array_filter(array($listing->street_number, $listing->street_name));
            $street_address = implode(' ', $address_parts);

            $formatted[] = array(
                'id' => $listing->listing_key,
                'mls_number' => $listing->listing_id,
                'address' => $street_address,
                'city' => $listing->city,
                'state' => $listing->state_or_province,
                'zip' => $listing->postal_code,
                'price' => (int) $listing->list_price,
                'beds' => (int) $listing->bedrooms_total,
                'baths' => (float) $listing->bathrooms_total,
                'sqft' => (int) $listing->building_area_total,
                'property_type' => $listing->property_type,
                'latitude' => (float) $listing->latitude,
                'longitude' => (float) $listing->longitude,
                'status' => $listing->standard_status,
                'photo_url' => $listing->primary_photo_url,
                'viewed_at' => self::format_datetime_iso8601($metadata['viewed_at']),
                'view_source' => $metadata['view_source'],
                'platform' => $metadata['platform']
            );
        }

        return new WP_REST_Response(array(
            'success' => true,
            'data' => array(
                'properties' => $formatted,
                'count' => count($formatted),
                'total' => (int) $total,
                'days' => $days
            )
        ), 200);
    }
}

// Initialize - use 'init' hook to ensure WordPress functions are available
add_action('init', array('MLD_Mobile_REST_API', 'init'));
