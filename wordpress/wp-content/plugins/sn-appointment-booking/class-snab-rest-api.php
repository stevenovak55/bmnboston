<?php
/**
 * SNAB REST API
 *
 * Provides REST API endpoints for the SN Appointment Booking plugin.
 * Supports both JWT authentication (iOS/mobile) and WordPress session auth (web).
 * Namespace: snab/v1
 *
 * @package SN_Appointment_Booking
 * @since 1.7.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SNAB_REST_API {

    /**
     * API namespace
     */
    const NAMESPACE = 'snab/v1';

    /**
     * MLD JWT secret key option name (reuse MLD's secret)
     */
    const MLD_JWT_SECRET_OPTION = 'mld_mobile_jwt_secret';

    /**
     * Initialize the REST API
     */
    public static function init() {
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
    }

    /**
     * Send no-cache headers for authenticated endpoints
     * Prevents CDN from caching user-specific data
     */
    private static function send_no_cache_headers() {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
        header('Vary: Authorization');
    }

    /**
     * Get JWT secret key (reuse MLD's secret for unified auth)
     */
    private static function get_jwt_secret() {
        return get_option(self::MLD_JWT_SECRET_OPTION);
    }

    /**
     * Base64 URL-safe encode
     */
    private static function base64url_encode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Base64 URL-safe decode
     */
    private static function base64url_decode($data) {
        return base64_decode(strtr($data, '-_', '+/'));
    }

    /**
     * Verify JWT token
     *
     * @param string $token JWT token
     * @return array|WP_Error Decoded payload or error
     */
    private static function verify_jwt($token) {
        $secret = self::get_jwt_secret();
        if (empty($secret)) {
            return new WP_Error('jwt_not_configured', 'JWT authentication not configured', array('status' => 500));
        }

        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return new WP_Error('invalid_token', 'Invalid token format', array('status' => 401));
        }

        list($header_b64, $payload_b64, $signature_b64) = $parts;

        // Verify signature
        $signature_check = self::base64url_encode(
            hash_hmac('sha256', $header_b64 . '.' . $payload_b64, $secret, true)
        );

        if (!hash_equals($signature_check, $signature_b64)) {
            return new WP_Error('invalid_signature', 'Invalid token signature', array('status' => 401));
        }

        // Decode payload
        $payload = json_decode(self::base64url_decode($payload_b64), true);
        if (!$payload) {
            return new WP_Error('invalid_payload', 'Invalid token payload', array('status' => 401));
        }

        // Check expiration
        if (isset($payload['exp']) && $payload['exp'] < time()) {
            return new WP_Error('token_expired', 'Token has expired', array('status' => 401));
        }

        // Check issuer
        if (isset($payload['iss']) && $payload['iss'] !== get_bloginfo('url')) {
            return new WP_Error('invalid_issuer', 'Invalid token issuer', array('status' => 401));
        }

        // Check token type (only accept 'access' tokens, not 'refresh')
        if (isset($payload['type']) && $payload['type'] !== 'access') {
            return new WP_Error('invalid_token_type', 'Invalid token type', array('status' => 401));
        }

        return $payload;
    }

    /**
     * Check authentication - supports both JWT and WordPress session auth
     *
     * @param WP_REST_Request $request
     * @return true|WP_Error
     */
    public static function check_auth($request) {
        // First, try JWT authentication (for iOS/mobile)
        $auth_header = $request->get_header('Authorization');
        if (!empty($auth_header) && strpos($auth_header, 'Bearer ') === 0) {
            $token = substr($auth_header, 7);
            $payload = self::verify_jwt($token);

            if (is_wp_error($payload)) {
                return new WP_REST_Response(array(
                    'success' => false,
                    'code' => $payload->get_error_code(),
                    'message' => $payload->get_error_message()
                ), 401);
            }

            // Set current user from token
            if (isset($payload['sub'])) {
                wp_set_current_user($payload['sub']);
            }

            return true;
        }

        // Fall back to WordPress session authentication (for web)
        if (is_user_logged_in()) {
            return true;
        }

        return new WP_REST_Response(array(
            'success' => false,
            'code' => 'unauthorized',
            'message' => 'Authentication required'
        ), 401);
    }

    /**
     * Optional authentication - allows guests but enriches with user data if authenticated
     * Always returns true but sets current user if authenticated
     *
     * @param WP_REST_Request $request
     * @return true
     */
    public static function check_optional_auth($request) {
        // Try JWT authentication
        $auth_header = $request->get_header('Authorization');
        if (!empty($auth_header) && strpos($auth_header, 'Bearer ') === 0) {
            $token = substr($auth_header, 7);
            $payload = self::verify_jwt($token);

            if (!is_wp_error($payload) && isset($payload['sub'])) {
                wp_set_current_user($payload['sub']);
            }
        }

        // Or use WordPress session
        // (Already set if user is logged in via cookies)

        return true; // Always allow, guests can book
    }

    /**
     * Check rate limit for booking attempts
     *
     * @param string $email Client email
     * @return false|WP_REST_Response False if allowed, error response if rate limited
     */
    private static function check_booking_rate_limit($email) {
        $max_attempts = 5;
        $window_duration = 15 * MINUTE_IN_SECONDS;

        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field($_SERVER['REMOTE_ADDR']) : 'unknown';
        $rate_key = 'snab_book_' . md5($email . '_' . $ip);

        $attempts = (int) get_transient($rate_key);

        if ($attempts >= $max_attempts) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'rate_limit_exceeded',
                'message' => 'Too many booking attempts. Please try again later.'
            ), 429);
        }

        return false;
    }

    /**
     * Record a booking attempt
     */
    private static function record_booking_attempt($email) {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field($_SERVER['REMOTE_ADDR']) : 'unknown';
        $rate_key = 'snab_book_' . md5($email . '_' . $ip);
        $window_duration = 15 * MINUTE_IN_SECONDS;

        $attempts = (int) get_transient($rate_key);
        set_transient($rate_key, $attempts + 1, $window_duration);
    }

    /**
     * Clear rate limit on successful booking
     */
    private static function clear_booking_rate_limit($email) {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field($_SERVER['REMOTE_ADDR']) : 'unknown';
        $rate_key = 'snab_book_' . md5($email . '_' . $ip);
        delete_transient($rate_key);
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

        // ============ Public Endpoints (No Auth Required) ============

        // Get appointment types
        register_rest_route(self::NAMESPACE, '/appointment-types', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'get_appointment_types'),
            'permission_callback' => '__return_true',
        ));

        // Get staff members
        register_rest_route(self::NAMESPACE, '/staff', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'get_staff'),
            'permission_callback' => '__return_true',
        ));

        // Get availability
        register_rest_route(self::NAMESPACE, '/availability', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'get_availability'),
            'permission_callback' => '__return_true',
        ));

        // Create appointment (guest or authenticated)
        register_rest_route(self::NAMESPACE, '/appointments', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'create_appointment'),
            'permission_callback' => array(__CLASS__, 'check_optional_auth'),
        ));

        // Get portal policy
        register_rest_route(self::NAMESPACE, '/portal/policy', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'get_portal_policy'),
            'permission_callback' => '__return_true',
        ));

        // ============ Authenticated Endpoints ============

        // Get user's appointments
        register_rest_route(self::NAMESPACE, '/appointments', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'get_user_appointments'),
            'permission_callback' => array(__CLASS__, 'check_auth'),
        ));

        // Get single appointment
        register_rest_route(self::NAMESPACE, '/appointments/(?P<id>\d+)', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'get_appointment'),
            'permission_callback' => array(__CLASS__, 'check_auth'),
        ));

        // Cancel appointment
        register_rest_route(self::NAMESPACE, '/appointments/(?P<id>\d+)', array(
            'methods' => 'DELETE',
            'callback' => array(__CLASS__, 'cancel_appointment'),
            'permission_callback' => array(__CLASS__, 'check_auth'),
        ));

        // Reschedule appointment
        register_rest_route(self::NAMESPACE, '/appointments/(?P<id>\d+)/reschedule', array(
            'methods' => array('PATCH', 'POST'),
            'callback' => array(__CLASS__, 'reschedule_appointment'),
            'permission_callback' => array(__CLASS__, 'check_auth'),
        ));

        // Get reschedule slots
        register_rest_route(self::NAMESPACE, '/appointments/(?P<id>\d+)/reschedule-slots', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'get_reschedule_slots'),
            'permission_callback' => array(__CLASS__, 'check_auth'),
        ));

        // Download ICS calendar file for appointment
        register_rest_route(self::NAMESPACE, '/appointments/(?P<id>\d+)/ics', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'download_ics'),
            'permission_callback' => array(__CLASS__, 'check_auth'),
        ));

        // Register device token for push notifications
        register_rest_route(self::NAMESPACE, '/device-tokens', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'register_device_token'),
            'permission_callback' => array(__CLASS__, 'check_auth'),
        ));

        // Unregister device token
        register_rest_route(self::NAMESPACE, '/device-tokens', array(
            'methods' => 'DELETE',
            'callback' => array(__CLASS__, 'unregister_device_token'),
            'permission_callback' => array(__CLASS__, 'check_auth'),
        ));
    }

    // ============ Endpoint Handlers ============

    /**
     * GET /appointment-types
     * List active appointment types
     */
    public static function get_appointment_types($request) {
        global $wpdb;
        $table = $wpdb->prefix . 'snab_appointment_types';

        $slug = sanitize_text_field($request->get_param('slug'));
        $staff_id = absint($request->get_param('staff_id'));

        $where = array('is_active = 1');
        $params = array();

        if (!empty($slug)) {
            $slugs = array_map('sanitize_text_field', explode(',', $slug));
            $placeholders = implode(',', array_fill(0, count($slugs), '%s'));
            $where[] = "slug IN ($placeholders)";
            $params = array_merge($params, $slugs);
        }

        // If staff_id provided, filter to types that staff member handles
        if ($staff_id > 0) {
            $staff_services_table = $wpdb->prefix . 'snab_staff_services';
            $where[] = "id IN (SELECT appointment_type_id FROM $staff_services_table WHERE staff_id = %d AND is_active = 1)";
            $params[] = $staff_id;
        }

        $where_clause = implode(' AND ', $where);
        $sql = "SELECT id, name, slug, description, duration_minutes, color, requires_login, sort_order
                FROM $table
                WHERE $where_clause
                ORDER BY sort_order ASC, name ASC";

        if (!empty($params)) {
            $sql = $wpdb->prepare($sql, $params);
        }

        $types = $wpdb->get_results($sql);

        $data = array();
        foreach ($types as $type) {
            $data[] = array(
                'id' => (int) $type->id,
                'name' => $type->name,
                'slug' => $type->slug,
                'description' => $type->description,
                'duration_minutes' => (int) $type->duration_minutes,
                'color' => $type->color,
                'requires_login' => (bool) $type->requires_login,
                'sort_order' => (int) $type->sort_order,
            );
        }

        return new WP_REST_Response(array(
            'success' => true,
            'data' => $data
        ), 200);
    }

    /**
     * GET /staff
     * List available staff members
     */
    public static function get_staff($request) {
        global $wpdb;
        $table = $wpdb->prefix . 'snab_staff';

        $type_id = absint($request->get_param('appointment_type_id'));
        $active_only = $request->get_param('active_only') !== 'false';

        $where = array();
        $params = array();

        if ($active_only) {
            $where[] = 'is_active = 1';
        }

        // If type_id provided, filter to staff who handle this type
        if ($type_id > 0) {
            $staff_services_table = $wpdb->prefix . 'snab_staff_services';
            $where[] = "id IN (SELECT staff_id FROM $staff_services_table WHERE appointment_type_id = %d AND is_active = 1)";
            $params[] = $type_id;
        }

        $where_clause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
        $sql = "SELECT id, name, title, email, phone, bio, avatar_url, is_primary
                FROM $table
                $where_clause
                ORDER BY is_primary DESC, name ASC";

        if (!empty($params)) {
            $sql = $wpdb->prepare($sql, $params);
        }

        $staff = $wpdb->get_results($sql);

        $data = array();
        foreach ($staff as $member) {
            $data[] = array(
                'id' => (int) $member->id,
                'name' => $member->name,
                'title' => $member->title,
                'email' => $member->email,
                'phone' => $member->phone,
                'bio' => $member->bio,
                'avatar_url' => $member->avatar_url,
                'is_primary' => (bool) $member->is_primary,
            );
        }

        return new WP_REST_Response(array(
            'success' => true,
            'data' => $data
        ), 200);
    }

    /**
     * GET /availability
     * Get available time slots for a date range
     */
    public static function get_availability($request) {
        $start_date = sanitize_text_field($request->get_param('start_date'));
        $end_date = sanitize_text_field($request->get_param('end_date'));
        $type_id = absint($request->get_param('type_id'));
        $staff_id = absint($request->get_param('staff_id'));
        $allowed_days = sanitize_text_field($request->get_param('allowed_days'));
        $start_hour = absint($request->get_param('start_hour'));
        $end_hour = absint($request->get_param('end_hour'));

        // Validate required parameters
        if (empty($start_date) || empty($end_date)) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'missing_params',
                'message' => 'start_date and end_date are required'
            ), 400);
        }

        // Validate date format
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'invalid_date_format',
                'message' => 'Dates must be in Y-m-d format'
            ), 400);
        }

        // Get appointment type duration
        $duration = 60; // Default 1 hour
        if ($type_id > 0) {
            global $wpdb;
            $type = $wpdb->get_row($wpdb->prepare(
                "SELECT duration_minutes FROM {$wpdb->prefix}snab_appointment_types WHERE id = %d",
                $type_id
            ));
            if ($type) {
                $duration = (int) $type->duration_minutes;
            }
        }

        // Use availability service
        $service = new SNAB_Availability_Service();

        $options = array(
            'staff_id' => $staff_id > 0 ? $staff_id : null,
            'appointment_type_id' => $type_id > 0 ? $type_id : null,
            'duration_minutes' => $duration,
        );

        if (!empty($allowed_days)) {
            $options['allowed_days'] = array_map('absint', explode(',', $allowed_days));
        }
        if ($start_hour > 0) {
            $options['start_hour'] = $start_hour;
        }
        if ($end_hour > 0) {
            $options['end_hour'] = $end_hour;
        }

        $slots = $service->get_available_slots($start_date, $end_date, $options);

        // The availability service returns: ['2025-12-30' => ['09:00', '09:30', ...], ...]
        // Transform to the format expected by the frontend
        $dates_with_availability = array();
        $slots_by_date = array();

        foreach ($slots as $date => $times) {
            if (!empty($times)) {
                $dates_with_availability[] = $date;
                $slots_by_date[$date] = array();
                foreach ($times as $time) {
                    $slots_by_date[$date][] = array(
                        'value' => $time,
                        'label' => snab_format_time($date, $time),
                    );
                }
            }
        }

        sort($dates_with_availability);

        return new WP_REST_Response(array(
            'success' => true,
            'data' => array(
                'dates_with_availability' => $dates_with_availability,
                'slots' => $slots_by_date,
            )
        ), 200);
    }

    /**
     * POST /appointments
     * Create a new appointment (guest or authenticated)
     */
    public static function create_appointment($request) {
        global $wpdb;

        $params = $request->get_json_params();

        // Required fields
        $type_id = absint($params['appointment_type_id'] ?? 0);
        $staff_id = absint($params['staff_id'] ?? 0);
        $date = sanitize_text_field($params['date'] ?? '');
        $time = sanitize_text_field($params['time'] ?? '');
        $client_name = sanitize_text_field($params['client_name'] ?? '');
        $client_email = sanitize_email($params['client_email'] ?? '');
        $client_phone = sanitize_text_field($params['client_phone'] ?? '');

        // Optional fields
        $listing_id = sanitize_text_field($params['listing_id'] ?? '');
        $property_address = sanitize_text_field($params['property_address'] ?? '');
        $notes = sanitize_textarea_field($params['notes'] ?? '');

        // Validate required fields
        if ($type_id <= 0) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'missing_type',
                'message' => 'Appointment type is required'
            ), 400);
        }

        if (empty($date) || empty($time)) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'missing_datetime',
                'message' => 'Date and time are required'
            ), 400);
        }

        if (empty($client_name) || empty($client_email)) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'missing_contact',
                'message' => 'Name and email are required'
            ), 400);
        }

        if (!is_email($client_email)) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'invalid_email',
                'message' => 'Please provide a valid email address'
            ), 400);
        }

        // Check rate limit
        $rate_limited = self::check_booking_rate_limit($client_email);
        if ($rate_limited) {
            return $rate_limited;
        }

        // Get appointment type
        $type = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}snab_appointment_types WHERE id = %d AND is_active = 1",
            $type_id
        ));

        if (!$type) {
            self::record_booking_attempt($client_email);
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'invalid_type',
                'message' => 'Invalid appointment type'
            ), 400);
        }

        // Check if type requires login
        if ($type->requires_login && !is_user_logged_in()) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'login_required',
                'message' => 'You must be logged in to book this appointment type'
            ), 401);
        }

        // Get staff member (use primary if not specified)
        if ($staff_id <= 0) {
            $staff = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}snab_staff WHERE is_active = 1 ORDER BY is_primary DESC LIMIT 1");
        } else {
            $staff = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}snab_staff WHERE id = %d AND is_active = 1",
                $staff_id
            ));
        }

        if (!$staff) {
            self::record_booking_attempt($client_email);
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'no_staff',
                'message' => 'No available staff member found'
            ), 400);
        }

        // Validate slot is still available
        $service = new SNAB_Availability_Service();
        $options = array(
            'staff_id' => $staff->id,
            'appointment_type_id' => $type_id,
            'duration_minutes' => $type->duration_minutes,
        );

        $available_slots = $service->get_available_slots($date, $date, $options);

        // The availability service returns: ['2025-12-30' => ['09:00', '09:30', ...], ...]
        // Check if the requested date has slots and if the time is in that array
        $slot_available = isset($available_slots[$date]) && in_array($time, $available_slots[$date]);

        if (!$slot_available) {
            self::record_booking_attempt($client_email);
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'slot_unavailable',
                'message' => 'Sorry, this time slot is no longer available. Please select another time.'
            ), 409);
        }

        // Calculate end time
        $start_datetime = new DateTime($date . ' ' . $time, wp_timezone());
        $end_datetime = clone $start_datetime;
        $end_datetime->add(new DateInterval('PT' . $type->duration_minutes . 'M'));

        // Determine status
        $status = $type->requires_approval ? 'pending' : 'confirmed';

        // Get user ID if authenticated
        $user_id = get_current_user_id();

        // Start transaction to prevent race conditions
        $wpdb->query('START TRANSACTION');

        // Insert appointment
        $result = $wpdb->insert(
            $wpdb->prefix . 'snab_appointments',
            array(
                'staff_id' => $staff->id,
                'appointment_type_id' => $type_id,
                'status' => $status,
                'appointment_date' => $date,
                'start_time' => $time,
                'end_time' => $end_datetime->format('H:i:s'),
                'user_id' => $user_id > 0 ? $user_id : null,
                'client_name' => $client_name,
                'client_email' => $client_email,
                'client_phone' => $client_phone,
                'listing_id' => !empty($listing_id) ? $listing_id : null,
                'property_address' => !empty($property_address) ? $property_address : null,
                'client_notes' => !empty($notes) ? $notes : null,
                'created_by' => 'client',
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ),
            array('%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
        );

        if (!$result) {
            $wpdb->query('ROLLBACK');

            // Check if it's a duplicate key error (race condition - slot was taken)
            $error = $wpdb->last_error;
            if (strpos($error, 'Duplicate entry') !== false || strpos($error, 'unique_slot') !== false) {
                SNAB_Logger::warning('Slot booking race condition detected', array(
                    'type_id' => $type_id,
                    'date' => $date,
                    'time' => $time,
                    'client' => $client_name,
                ));
                self::record_booking_attempt($client_email);
                return new WP_REST_Response(array(
                    'success' => false,
                    'code' => 'slot_unavailable',
                    'message' => 'Sorry, this time slot was just booked by someone else. Please select another time.'
                ), 409);
            }

            SNAB_Logger::error('Failed to create appointment', array(
                'error' => $error,
                'type_id' => $type_id,
                'date' => $date,
                'time' => $time,
            ));
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'database_error',
                'message' => 'Failed to create appointment. Please try again.'
            ), 500);
        }

        $appointment_id = $wpdb->insert_id;

        // Commit the transaction - appointment is now permanently saved
        $wpdb->query('COMMIT');

        // Create Google Calendar event for staff member
        $google_synced = false;
        try {
            $google = snab_google_calendar();
            $staff_connected = $google->is_staff_connected($staff->id);
            if ($staff_connected) {
                // Build event data in Google Calendar API format
                $timezone = wp_timezone_string();
                // Ensure time has seconds (Google Calendar requires RFC3339 format)
                $time_with_seconds = (strlen($time) === 5) ? $time . ':00' : $time;
                $start_datetime = $date . 'T' . $time_with_seconds;
                $end_time_str = $end_datetime->format('H:i:s');
                $end_datetime_str = $date . 'T' . $end_time_str;

                $summary = sprintf('%s - %s', $type->name, $client_name);

                $description_parts = array();
                $description_parts[] = sprintf('Type: %s', $type->name);
                $description_parts[] = sprintf('Client: %s', $client_name);
                $description_parts[] = sprintf('Email: %s', $client_email);
                if (!empty($client_phone)) {
                    $description_parts[] = sprintf('Phone: %s', $client_phone);
                }
                if (!empty($property_address)) {
                    $description_parts[] = sprintf('Property: %s', $property_address);
                }
                if (!empty($notes)) {
                    $description_parts[] = '';
                    $description_parts[] = 'Client Notes:';
                    $description_parts[] = $notes;
                }

                $google_event_data = array(
                    'summary' => $summary,
                    'description' => implode("\n", $description_parts),
                    'start' => array(
                        'dateTime' => $start_datetime,
                        'timeZone' => $timezone,
                    ),
                    'end' => array(
                        'dateTime' => $end_datetime_str,
                        'timeZone' => $timezone,
                    ),
                    'reminders' => array(
                        'useDefault' => false,
                        'overrides' => array(
                            array('method' => 'popup', 'minutes' => 60),
                            array('method' => 'popup', 'minutes' => 15),
                        ),
                    ),
                );

                // Add location if property address exists
                if (!empty($property_address)) {
                    $google_event_data['location'] = $property_address;
                }

                $event_result = $google->create_staff_event($staff->id, $google_event_data);
                if (!is_wp_error($event_result) && isset($event_result['id'])) {
                    $wpdb->update(
                        $wpdb->prefix . 'snab_appointments',
                        array(
                            'google_event_id' => $event_result['id'],
                            'google_calendar_synced' => 1,
                        ),
                        array('id' => $appointment_id),
                        array('%s', '%d'),
                        array('%d')
                    );
                    $google_synced = true;
                    SNAB_Logger::info('Google Calendar event created via REST API', array(
                        'appointment_id' => $appointment_id,
                        'event_id' => $event_result['id'],
                        'staff_id' => $staff->id,
                    ));
                }
            }
        } catch (Exception $e) {
            SNAB_Logger::error('Failed to create Google Calendar event', array(
                'appointment_id' => $appointment_id,
                'staff_id' => $staff->id,
                'error' => $e->getMessage(),
            ));
        }

        // Send confirmation email
        try {
            $notifications = snab_notifications();
            $notifications->send_client_confirmation($appointment_id);
            $notifications->send_admin_confirmation($appointment_id);
        } catch (Exception $e) {
            SNAB_Logger::error('Failed to send confirmation email', array(
                'appointment_id' => $appointment_id,
                'error' => $e->getMessage(),
            ));
        }

        // Clear rate limit on success
        self::clear_booking_rate_limit($client_email);

        // Trigger tour requested notification for agents (v6.43.0)
        // Find the user_id for the client by email
        $client_user = get_user_by('email', $client_email);
        if ($client_user) {
            do_action('snab_appointment_created', $appointment_id, array(
                'client_id' => $client_user->ID,
                'property_address' => $property_address,
                'date' => snab_format_date($date),
                'time' => snab_format_time($date, $time),
                'appointment_type' => $type->name
            ));
        }

        return new WP_REST_Response(array(
            'success' => true,
            'code' => 'appointment_created',
            'message' => 'Your appointment has been booked successfully',
            'data' => array(
                'appointment_id' => $appointment_id,
                'status' => $status,
                'type_name' => $type->name,
                'type_color' => $type->color,
                'date' => snab_format_date($date),
                'time' => snab_format_time($date, $time),
                'date_raw' => $date,  // ISO format for calendar integration
                'time_raw' => $time,  // 24h format for calendar integration
                'duration' => (int) $type->duration_minutes,
                'google_synced' => $google_synced,
            )
        ), 201);
    }

    /**
     * GET /appointments
     * Get user's appointments
     */
    public static function get_user_appointments($request) {
        global $wpdb;

        self::send_no_cache_headers();

        $user_id = get_current_user_id();
        if ($user_id <= 0) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'not_authenticated',
                'message' => 'User not authenticated'
            ), 401);
        }

        $status_filter = sanitize_text_field($request->get_param('status'));
        $days_past = absint($request->get_param('days_past')) ?: 90;
        $page = max(1, absint($request->get_param('page')));
        $per_page = min(50, max(1, absint($request->get_param('per_page')) ?: 20));

        // Check if current user is a staff member
        $staff_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}snab_staff WHERE user_id = %d",
            $user_id
        ));

        // Query appointments where user booked OR where user is the assigned staff
        if ($staff_id) {
            $where = array('(a.user_id = %d OR a.staff_id = %d)');
            $params = array($user_id, $staff_id);
        } else {
            $where = array('a.user_id = %d');
            $params = array($user_id);
        }

        if ($status_filter === 'upcoming') {
            $where[] = "a.appointment_date >= %s";
            $where[] = "a.status IN ('pending', 'confirmed')";
            $params[] = wp_date('Y-m-d');
        } elseif ($status_filter === 'past') {
            $where[] = "(a.appointment_date < %s OR a.status IN ('completed', 'cancelled', 'no_show'))";
            $params[] = wp_date('Y-m-d');
            // Limit to past X days
            $where[] = "a.appointment_date >= %s";
            $params[] = wp_date('Y-m-d', strtotime("-{$days_past} days"));
        }

        $where_clause = implode(' AND ', $where);
        $offset = ($page - 1) * $per_page;

        // Get total count
        $count_sql = $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}snab_appointments a WHERE $where_clause",
            $params
        );
        $total = (int) $wpdb->get_var($count_sql);

        // Get appointments
        $sql = $wpdb->prepare(
            "SELECT a.*, t.name as type_name, t.color as type_color, t.duration_minutes,
                    s.name as staff_name
             FROM {$wpdb->prefix}snab_appointments a
             LEFT JOIN {$wpdb->prefix}snab_appointment_types t ON a.appointment_type_id = t.id
             LEFT JOIN {$wpdb->prefix}snab_staff s ON a.staff_id = s.id
             WHERE $where_clause
             ORDER BY a.appointment_date DESC, a.start_time DESC
             LIMIT %d OFFSET %d",
            array_merge($params, array($per_page, $offset))
        );

        $appointments = $wpdb->get_results($sql);

        // Get portal policy for can_cancel/can_reschedule calculations
        $cancel_hours = (int) get_option('snab_cancellation_hours_before', 24);
        $reschedule_hours = (int) get_option('snab_reschedule_hours_before', 24);
        $max_reschedules = (int) get_option('snab_max_reschedules_per_appointment', 2);

        $data = array();
        $now = new DateTime('now', wp_timezone());

        foreach ($appointments as $appt) {
            $appt_datetime = new DateTime($appt->appointment_date . ' ' . $appt->start_time, wp_timezone());
            $hours_until = ($appt_datetime->getTimestamp() - $now->getTimestamp()) / 3600;

            $is_upcoming = $appt_datetime > $now && in_array($appt->status, array('pending', 'confirmed'));
            $can_cancel = $is_upcoming && $hours_until >= $cancel_hours;
            $can_reschedule = $is_upcoming && $hours_until >= $reschedule_hours && $appt->reschedule_count < $max_reschedules;

            $data[] = array(
                'id' => (int) $appt->id,
                'status' => $appt->status,
                'status_label' => ucfirst(str_replace('_', ' ', $appt->status)),
                'type_id' => (int) $appt->appointment_type_id,
                'type_name' => $appt->type_name,
                'type_color' => $appt->type_color,
                'date' => $appt->appointment_date,
                'formatted_date' => snab_format_date($appt->appointment_date),
                'start_time' => $appt->start_time,
                'end_time' => $appt->end_time,
                'formatted_time' => snab_format_time($appt->appointment_date, $appt->start_time),
                'formatted_end_time' => snab_format_time($appt->appointment_date, $appt->end_time),
                'duration' => (int) $appt->duration_minutes,
                'staff_name' => $appt->staff_name,
                'property_address' => $appt->property_address,
                'listing_id' => $appt->listing_id,
                'client_notes' => $appt->client_notes,
                'can_cancel' => $can_cancel,
                'can_reschedule' => $can_reschedule,
                'is_upcoming' => $is_upcoming,
                'reschedule_count' => (int) $appt->reschedule_count,
                'google_synced' => (bool) $appt->google_calendar_synced,
            );
        }

        return new WP_REST_Response(array(
            'success' => true,
            'data' => array(
                'appointments' => $data,
                'total' => $total,
                'pages' => ceil($total / $per_page),
                'current_page' => $page,
            )
        ), 200);
    }

    /**
     * GET /appointments/{id}
     * Get single appointment details
     */
    public static function get_appointment($request) {
        global $wpdb;

        self::send_no_cache_headers();

        $id = absint($request->get_param('id'));
        $user_id = get_current_user_id();

        $appt = $wpdb->get_row($wpdb->prepare(
            "SELECT a.*, t.name as type_name, t.color as type_color, t.duration_minutes,
                    s.name as staff_name, s.email as staff_email, s.phone as staff_phone
             FROM {$wpdb->prefix}snab_appointments a
             LEFT JOIN {$wpdb->prefix}snab_appointment_types t ON a.appointment_type_id = t.id
             LEFT JOIN {$wpdb->prefix}snab_staff s ON a.staff_id = s.id
             WHERE a.id = %d AND a.user_id = %d",
            $id, $user_id
        ));

        if (!$appt) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'not_found',
                'message' => 'Appointment not found'
            ), 404);
        }

        // Calculate can_cancel/can_reschedule
        $cancel_hours = (int) get_option('snab_cancellation_hours_before', 24);
        $reschedule_hours = (int) get_option('snab_reschedule_hours_before', 24);
        $max_reschedules = (int) get_option('snab_max_reschedules_per_appointment', 2);

        $now = new DateTime('now', wp_timezone());
        $appt_datetime = new DateTime($appt->appointment_date . ' ' . $appt->start_time, wp_timezone());
        $hours_until = ($appt_datetime->getTimestamp() - $now->getTimestamp()) / 3600;

        $is_upcoming = $appt_datetime > $now && in_array($appt->status, array('pending', 'confirmed'));
        $can_cancel = $is_upcoming && $hours_until >= $cancel_hours;
        $can_reschedule = $is_upcoming && $hours_until >= $reschedule_hours && $appt->reschedule_count < $max_reschedules;

        return new WP_REST_Response(array(
            'success' => true,
            'data' => array(
                'id' => (int) $appt->id,
                'status' => $appt->status,
                'status_label' => ucfirst(str_replace('_', ' ', $appt->status)),
                'type_id' => (int) $appt->appointment_type_id,
                'type_name' => $appt->type_name,
                'type_color' => $appt->type_color,
                'date' => $appt->appointment_date,
                'formatted_date' => snab_format_date($appt->appointment_date),
                'start_time' => $appt->start_time,
                'end_time' => $appt->end_time,
                'formatted_time' => snab_format_time($appt->appointment_date, $appt->start_time),
                'formatted_end_time' => snab_format_time($appt->appointment_date, $appt->end_time),
                'duration' => (int) $appt->duration_minutes,
                'staff_name' => $appt->staff_name,
                'staff_email' => $appt->staff_email,
                'staff_phone' => $appt->staff_phone,
                'property_address' => $appt->property_address,
                'listing_id' => $appt->listing_id,
                'client_notes' => $appt->client_notes,
                'admin_notes' => $appt->admin_notes,
                'can_cancel' => $can_cancel,
                'can_reschedule' => $can_reschedule,
                'is_upcoming' => $is_upcoming,
                'reschedule_count' => (int) $appt->reschedule_count,
                'original_datetime' => $appt->original_datetime,
                'google_synced' => (bool) $appt->google_calendar_synced,
                'created_at' => $appt->created_at,
            )
        ), 200);
    }

    /**
     * DELETE /appointments/{id}
     * Cancel an appointment
     */
    public static function cancel_appointment($request) {
        global $wpdb;

        self::send_no_cache_headers();

        $id = absint($request->get_param('id'));
        $user_id = get_current_user_id();

        $params = $request->get_json_params();
        $reason = sanitize_textarea_field($params['reason'] ?? '');

        // Get appointment
        $appt = $wpdb->get_row($wpdb->prepare(
            "SELECT a.*, s.google_calendar_id
             FROM {$wpdb->prefix}snab_appointments a
             LEFT JOIN {$wpdb->prefix}snab_staff s ON a.staff_id = s.id
             WHERE a.id = %d AND a.user_id = %d",
            $id, $user_id
        ));

        if (!$appt) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'not_found',
                'message' => 'Appointment not found'
            ), 404);
        }

        // Check if can be cancelled
        $cancel_hours = (int) get_option('snab_cancellation_hours_before', 24);
        $require_reason = (bool) get_option('snab_require_cancel_reason', false);

        $now = new DateTime('now', wp_timezone());
        $appt_datetime = new DateTime($appt->appointment_date . ' ' . $appt->start_time, wp_timezone());
        $hours_until = ($appt_datetime->getTimestamp() - $now->getTimestamp()) / 3600;

        if (!in_array($appt->status, array('pending', 'confirmed'))) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'cannot_cancel',
                'message' => 'This appointment cannot be cancelled'
            ), 400);
        }

        if ($hours_until < $cancel_hours) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'cancellation_deadline_passed',
                'message' => sprintf('Cancellations must be made at least %d hours before the appointment', $cancel_hours)
            ), 400);
        }

        if ($require_reason && empty($reason)) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'reason_required',
                'message' => 'Please provide a cancellation reason'
            ), 400);
        }

        // Update appointment
        $result = $wpdb->update(
            $wpdb->prefix . 'snab_appointments',
            array(
                'status' => 'cancelled',
                'cancellation_reason' => $reason,
                'cancelled_by' => 'client',
                'cancelled_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ),
            array('id' => $id),
            array('%s', '%s', '%s', '%s', '%s'),
            array('%d')
        );

        if (!$result) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'database_error',
                'message' => 'Failed to cancel appointment'
            ), 500);
        }

        // Delete Google Calendar event
        if ($appt->google_event_id && $appt->google_calendar_id) {
            try {
                $google = snab_google_calendar();
                $google->delete_event($appt->google_event_id, $appt->staff_id);
            } catch (Exception $e) {
                SNAB_Logger::error('Failed to delete Google Calendar event', array(
                    'appointment_id' => $id,
                    'error' => $e->getMessage(),
                ));
            }
        }

        // Send cancellation emails
        try {
            $notifications = snab_notifications();
            $notifications->send_cancellation($id, $reason);
        } catch (Exception $e) {
            SNAB_Logger::error('Failed to send cancellation email', array(
                'appointment_id' => $id,
                'error' => $e->getMessage(),
            ));
        }

        return new WP_REST_Response(array(
            'success' => true,
            'code' => 'appointment_cancelled',
            'message' => 'Your appointment has been cancelled successfully',
            'data' => array(
                'id' => $id,
                'status' => 'cancelled'
            )
        ), 200);
    }

    /**
     * PATCH /appointments/{id}/reschedule
     * Reschedule an appointment
     */
    public static function reschedule_appointment($request) {
        global $wpdb;

        self::send_no_cache_headers();

        $id = absint($request->get_param('id'));
        $user_id = get_current_user_id();

        $params = $request->get_json_params();
        $new_date = sanitize_text_field($params['new_date'] ?? '');
        $new_time = sanitize_text_field($params['new_time'] ?? '');

        if (empty($new_date) || empty($new_time)) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'missing_datetime',
                'message' => 'New date and time are required'
            ), 400);
        }

        // Get appointment
        $appt = $wpdb->get_row($wpdb->prepare(
            "SELECT a.*, t.duration_minutes, s.google_calendar_id
             FROM {$wpdb->prefix}snab_appointments a
             LEFT JOIN {$wpdb->prefix}snab_appointment_types t ON a.appointment_type_id = t.id
             LEFT JOIN {$wpdb->prefix}snab_staff s ON a.staff_id = s.id
             WHERE a.id = %d AND a.user_id = %d",
            $id, $user_id
        ));

        if (!$appt) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'not_found',
                'message' => 'Appointment not found'
            ), 404);
        }

        // Check if can be rescheduled
        $reschedule_hours = (int) get_option('snab_reschedule_hours_before', 24);
        $max_reschedules = (int) get_option('snab_max_reschedules_per_appointment', 2);

        $now = new DateTime('now', wp_timezone());
        $appt_datetime = new DateTime($appt->appointment_date . ' ' . $appt->start_time, wp_timezone());
        $hours_until = ($appt_datetime->getTimestamp() - $now->getTimestamp()) / 3600;

        if (!in_array($appt->status, array('pending', 'confirmed'))) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'cannot_reschedule',
                'message' => 'This appointment cannot be rescheduled'
            ), 400);
        }

        if ($hours_until < $reschedule_hours) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'reschedule_deadline_passed',
                'message' => sprintf('Reschedules must be made at least %d hours before the appointment', $reschedule_hours)
            ), 400);
        }

        if ($appt->reschedule_count >= $max_reschedules) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'max_reschedules_reached',
                'message' => sprintf('This appointment has already been rescheduled %d time(s)', $max_reschedules)
            ), 400);
        }

        // Validate new slot is available
        $service = new SNAB_Availability_Service();
        $filters = array(
            'duration_minutes' => $appt->duration_minutes,
            'exclude_appointment_id' => $id,
        );

        $available_slots = $service->get_available_slots(
            $new_date,
            $new_date,
            $appt->appointment_type_id,
            $appt->staff_id,
            $filters
        );

        // The availability service returns: ['2025-12-30' => ['09:00', '09:30', ...], ...]
        // Check if the requested date has slots and if the time is in that array
        $slot_available = isset($available_slots[$new_date]) && in_array($new_time, $available_slots[$new_date]);

        if (!$slot_available) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'slot_unavailable',
                'message' => 'Sorry, this time slot is no longer available'
            ), 409);
        }

        // Calculate new end time
        $new_start_datetime = new DateTime($new_date . ' ' . $new_time, wp_timezone());
        $new_end_datetime = clone $new_start_datetime;
        $new_end_datetime->add(new DateInterval('PT' . $appt->duration_minutes . 'M'));

        // Store original datetime if first reschedule
        $original_datetime = $appt->original_datetime;
        if (empty($original_datetime)) {
            $original_datetime = $appt->appointment_date . ' ' . $appt->start_time;
        }

        // Update appointment
        $result = $wpdb->update(
            $wpdb->prefix . 'snab_appointments',
            array(
                'appointment_date' => $new_date,
                'start_time' => $new_time,
                'end_time' => $new_end_datetime->format('H:i:s'),
                'reschedule_count' => $appt->reschedule_count + 1,
                'original_datetime' => $original_datetime,
                'rescheduled_by' => 'client',
                'updated_at' => current_time('mysql'),
            ),
            array('id' => $id),
            array('%s', '%s', '%s', '%d', '%s', '%s', '%s'),
            array('%d')
        );

        if (!$result) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'database_error',
                'message' => 'Failed to reschedule appointment'
            ), 500);
        }

        // Update Google Calendar event (v1.10.4: use per-staff method with proper format + attendees)
        if ($appt->google_event_id && $appt->staff_id) {
            try {
                $google = snab_google_calendar();
                if ($google->is_staff_connected($appt->staff_id)) {
                    $timezone = wp_timezone_string();
                    $time_with_seconds = (strlen($new_time) === 5) ? $new_time . ':00' : $new_time;
                    $start_datetime = $new_date . 'T' . $time_with_seconds;
                    $end_datetime_str = $new_date . 'T' . $new_end_datetime->format('H:i:s');

                    $update_data = array(
                        'start' => array(
                            'dateTime' => $start_datetime,
                            'timeZone' => $timezone,
                        ),
                        'end' => array(
                            'dateTime' => $end_datetime_str,
                            'timeZone' => $timezone,
                        ),
                    );

                    // Include all attendees in the update
                    $attendees_array = $google->build_attendees_array($id);
                    if (!empty($attendees_array)) {
                        $update_data['attendees'] = $attendees_array;
                    }

                    $google->update_staff_event($appt->staff_id, $appt->google_event_id, $update_data);
                }
            } catch (Exception $e) {
                SNAB_Logger::error('Failed to update Google Calendar event', array(
                    'appointment_id' => $id,
                    'error' => $e->getMessage(),
                ));
            }
        }

        // Send reschedule emails
        try {
            $notifications = snab_notifications();
            // Pass old date/time for the notification
            $notifications->send_reschedule($id, $appt->appointment_date, $appt->start_time);
        } catch (Exception $e) {
            SNAB_Logger::error('Failed to send reschedule email', array(
                'appointment_id' => $id,
                'error' => $e->getMessage(),
            ));
        }

        // Get updated appointment
        $updated = $wpdb->get_row($wpdb->prepare(
            "SELECT a.*, t.name as type_name, t.color as type_color
             FROM {$wpdb->prefix}snab_appointments a
             LEFT JOIN {$wpdb->prefix}snab_appointment_types t ON a.appointment_type_id = t.id
             WHERE a.id = %d",
            $id
        ));

        return new WP_REST_Response(array(
            'success' => true,
            'code' => 'appointment_rescheduled',
            'message' => 'Your appointment has been rescheduled successfully',
            'data' => array(
                'id' => (int) $updated->id,
                'status' => $updated->status,
                'type_name' => $updated->type_name,
                'type_color' => $updated->type_color,
                'date' => $updated->appointment_date,
                'formatted_date' => snab_format_date($updated->appointment_date),
                'start_time' => $updated->start_time,
                'formatted_time' => snab_format_time($updated->appointment_date, $updated->start_time),
                'reschedule_count' => (int) $updated->reschedule_count,
            )
        ), 200);
    }

    /**
     * GET /appointments/{id}/reschedule-slots
     * Get available slots for rescheduling
     */
    public static function get_reschedule_slots($request) {
        global $wpdb;

        $id = absint($request->get_param('id'));
        $user_id = get_current_user_id();
        $start_date = sanitize_text_field($request->get_param('start_date'));
        $end_date = sanitize_text_field($request->get_param('end_date'));

        // Default to next 2 weeks if not specified
        if (empty($start_date)) {
            $start_date = wp_date('Y-m-d');
        }
        if (empty($end_date)) {
            $end_date = wp_date('Y-m-d', strtotime('+14 days'));
        }

        // Get appointment
        $appt = $wpdb->get_row($wpdb->prepare(
            "SELECT a.*, t.duration_minutes
             FROM {$wpdb->prefix}snab_appointments a
             LEFT JOIN {$wpdb->prefix}snab_appointment_types t ON a.appointment_type_id = t.id
             WHERE a.id = %d AND a.user_id = %d",
            $id, $user_id
        ));

        if (!$appt) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'not_found',
                'message' => 'Appointment not found'
            ), 404);
        }

        // Get available slots (excluding current appointment)
        $service = new SNAB_Availability_Service();
        $options = array(
            'staff_id' => $appt->staff_id,
            'appointment_type_id' => $appt->appointment_type_id,
            'duration_minutes' => $appt->duration_minutes,
            'exclude_appointment_id' => $id,
        );

        $slots = $service->get_available_slots($start_date, $end_date, $options);

        // The availability service returns: ['2025-12-30' => ['09:00', '09:30', ...], ...]
        // Transform to the format expected by the frontend
        $dates_with_availability = array();
        $slots_by_date = array();

        foreach ($slots as $date => $times) {
            if (!empty($times)) {
                $dates_with_availability[] = $date;
                $slots_by_date[$date] = array();
                foreach ($times as $time) {
                    $slots_by_date[$date][] = array(
                        'value' => $time,
                        'label' => snab_format_time($date, $time),
                    );
                }
            }
        }

        sort($dates_with_availability);

        return new WP_REST_Response(array(
            'success' => true,
            'data' => array(
                'dates_with_availability' => $dates_with_availability,
                'slots' => $slots_by_date,
                'current_date' => $appt->appointment_date,
                'current_time' => $appt->start_time,
            )
        ), 200);
    }

    /**
     * GET /portal/policy
     * Get cancellation and reschedule policies
     */
    public static function get_portal_policy($request) {
        $portal_enabled = (bool) get_option('snab_enable_client_portal', true);
        $cancel_enabled = $portal_enabled;
        $reschedule_enabled = $portal_enabled;
        $cancel_hours = (int) get_option('snab_cancellation_hours_before', 24);
        $reschedule_hours = (int) get_option('snab_reschedule_hours_before', 24);
        $max_reschedules = (int) get_option('snab_max_reschedules_per_appointment', 2);
        $require_reason = (bool) get_option('snab_require_cancel_reason', false);

        $cancel_policy = sprintf(
            'Cancellations must be made at least %d hours before your appointment.',
            $cancel_hours
        );

        $reschedule_policy = sprintf(
            'Reschedules must be made at least %d hours before your appointment. Maximum %d reschedule(s) per appointment.',
            $reschedule_hours,
            $max_reschedules
        );

        return new WP_REST_Response(array(
            'success' => true,
            'data' => array(
                'portal_enabled' => $portal_enabled,
                'cancellation' => array(
                    'enabled' => $cancel_enabled,
                    'hours_before' => $cancel_hours,
                    'require_reason' => $require_reason,
                    'policy_text' => $cancel_policy,
                ),
                'reschedule' => array(
                    'enabled' => $reschedule_enabled,
                    'hours_before' => $reschedule_hours,
                    'max_reschedules' => $max_reschedules,
                    'policy_text' => $reschedule_policy,
                ),
            )
        ), 200);
    }

    /**
     * GET /appointments/{id}/ics
     * Download ICS calendar file for an appointment
     */
    public static function download_ics($request) {
        global $wpdb;

        $id = absint($request->get_param('id'));
        $user_id = get_current_user_id();

        // Get appointment (verify ownership)
        $appointment = $wpdb->get_row($wpdb->prepare(
            "SELECT a.*,
                    t.name as type_name,
                    t.duration_minutes,
                    s.name as staff_name,
                    s.email as staff_email
             FROM {$wpdb->prefix}snab_appointments a
             LEFT JOIN {$wpdb->prefix}snab_appointment_types t ON a.appointment_type_id = t.id
             LEFT JOIN {$wpdb->prefix}snab_staff s ON a.staff_id = s.id
             WHERE a.id = %d AND a.user_id = %d",
            $id, $user_id
        ));

        if (!$appointment) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'not_found',
                'message' => 'Appointment not found'
            ), 404);
        }

        // Generate ICS content
        if (!class_exists('SNAB_ICS_Generator')) {
            require_once SNAB_PLUGIN_DIR . 'includes/class-snab-ics-generator.php';
        }

        $ics_content = SNAB_ICS_Generator::generate($appointment);
        $filename = SNAB_ICS_Generator::get_filename($appointment);

        // Return as downloadable file
        return new WP_REST_Response(array(
            'success' => true,
            'data' => array(
                'filename' => $filename,
                'content' => base64_encode($ics_content),
                'content_type' => 'text/calendar',
            )
        ), 200);
    }

    /**
     * POST /device-tokens
     * Register a device token for push notifications
     */
    public static function register_device_token($request) {
        $user_id = get_current_user_id();

        $device_token = sanitize_text_field($request->get_param('device_token'));
        $device_type = sanitize_text_field($request->get_param('device_type')) ?: 'ios';
        $is_sandbox = (bool) $request->get_param('is_sandbox');

        if (empty($device_token)) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'missing_token',
                'message' => 'Device token is required'
            ), 400);
        }

        // Load push notifications class if not already loaded
        if (!class_exists('SNAB_Push_Notifications')) {
            require_once SNAB_PLUGIN_DIR . 'includes/class-snab-push-notifications.php';
        }

        $result = snab_push_notifications()->register_device($user_id, $device_token, $device_type, $is_sandbox);

        if (is_wp_error($result)) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => $result->get_error_code(),
                'message' => $result->get_error_message()
            ), 400);
        }

        return new WP_REST_Response(array(
            'success' => true,
            'message' => 'Device token registered successfully'
        ), 200);
    }

    /**
     * DELETE /device-tokens
     * Unregister a device token
     */
    public static function unregister_device_token($request) {
        $device_token = sanitize_text_field($request->get_param('device_token'));

        if (empty($device_token)) {
            return new WP_REST_Response(array(
                'success' => false,
                'code' => 'missing_token',
                'message' => 'Device token is required'
            ), 400);
        }

        // Load push notifications class if not already loaded
        if (!class_exists('SNAB_Push_Notifications')) {
            require_once SNAB_PLUGIN_DIR . 'includes/class-snab-push-notifications.php';
        }

        snab_push_notifications()->unregister_device($device_token);

        return new WP_REST_Response(array(
            'success' => true,
            'message' => 'Device token unregistered successfully'
        ), 200);
    }

    /**
     * GET /health
     * Public health check endpoint for monitoring
     * No authentication required
     */
    public static function health_check($request) {
        global $wpdb;

        $start_time = microtime(true);
        $issues = array();
        $warnings = array();

        // ============ Table Verification ============
        $tables = array(
            'snab_staff' => 'Staff Members',
            'snab_appointments' => 'Appointments',
            'snab_appointment_types' => 'Appointment Types',
            'snab_availability_rules' => 'Availability Rules',
            'snab_notifications_log' => 'Notification Log',
        );

        $table_status = array();
        foreach ($tables as $table => $label) {
            $full_table = $wpdb->prefix . $table;
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s",
                DB_NAME,
                $full_table
            )) > 0;

            $table_status[$table] = array(
                'exists' => $exists,
                'label' => $label,
            );

            if (!$exists) {
                $issues[] = "Table {$table} is missing";
            }
        }

        // ============ Record Counts ============
        $counts = array();

        // Staff count
        $staff_table = $wpdb->prefix . 'snab_staff';
        if ($table_status['snab_staff']['exists']) {
            $counts['staff'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$staff_table}");
            if ($counts['staff'] === 0) {
                $warnings[] = 'No staff members configured';
            }
        }

        // Appointment type count
        $types_table = $wpdb->prefix . 'snab_appointment_types';
        if ($table_status['snab_appointment_types']['exists']) {
            $counts['appointment_types'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$types_table} WHERE is_active = 1");
            if ($counts['appointment_types'] === 0) {
                $warnings[] = 'No active appointment types';
            }
        }

        // Total appointments
        $appts_table = $wpdb->prefix . 'snab_appointments';
        if ($table_status['snab_appointments']['exists']) {
            $counts['total_appointments'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$appts_table}");

            // Upcoming appointments (next 30 days)
            $counts['upcoming_appointments'] = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$appts_table}
                 WHERE appointment_date >= %s
                   AND appointment_date <= %s
                   AND status IN ('confirmed', 'pending')",
                current_time('Y-m-d'),
                date('Y-m-d', strtotime('+30 days', current_time('timestamp')))
            ));

            // Appointments booked this month
            $counts['this_month'] = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$appts_table}
                 WHERE created_at >= %s",
                date('Y-m-01', current_time('timestamp'))
            ));
        }

        // ============ Google Calendar Status ============
        $google_status = array(
            'global_connected' => false,
            'staff_connected_count' => 0,
        );

        // Check global connection
        $global_token = get_option('snab_google_refresh_token');
        $google_status['global_connected'] = !empty($global_token);

        // Check per-staff connections
        if ($table_status['snab_staff']['exists']) {
            $google_status['staff_connected_count'] = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$staff_table}
                 WHERE google_refresh_token IS NOT NULL AND google_refresh_token != ''"
            );

            if ($google_status['staff_connected_count'] === 0 && ($counts['staff'] ?? 0) > 0) {
                $warnings[] = 'No staff members have Google Calendar connected';
            }
        }

        // ============ Availability Status ============
        $rules_table = $wpdb->prefix . 'snab_availability_rules';
        if ($table_status['snab_availability_rules']['exists']) {
            $counts['availability_rules'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$rules_table}");
            if ($counts['availability_rules'] === 0) {
                $warnings[] = 'No availability rules configured';
            }
        }

        // ============ Determine Overall Status ============
        $status = 'healthy';
        if (count($issues) > 0) {
            $status = 'unhealthy';
        } elseif (count($warnings) > 0) {
            $status = 'degraded';
        }

        // ============ Response ============
        $response_time = round((microtime(true) - $start_time) * 1000, 2);

        return new WP_REST_Response(array(
            'status' => $status,
            'version' => defined('SNAB_VERSION') ? SNAB_VERSION : 'unknown',
            'timestamp' => current_time('c'),
            'response_time_ms' => $response_time,
            'tables' => $table_status,
            'counts' => $counts,
            'google_calendar' => $google_status,
            'issues' => $issues,
            'warnings' => $warnings,
        ), 200);
    }
}
