<?php
/**
 * MLD Open House REST API
 *
 * Provides REST API endpoints for the Open House Sign-In system.
 * Used by the iOS app for agents to manage open houses and capture attendees.
 *
 * @package MLS_Listings_Display
 * @since 6.69.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Open_House_REST_API {

    /**
     * API namespace
     */
    const NAMESPACE = 'mld-mobile/v1';

    /**
     * Database version for schema migrations
     * @since 6.71.0
     */
    const DB_VERSION = '6.71.0';

    /**
     * Initialize the REST API
     */
    public static function init() {
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
        add_action('init', array(__CLASS__, 'maybe_upgrade_database'));
    }

    /**
     * Check and upgrade database schema if needed
     * @since 6.71.0
     */
    public static function maybe_upgrade_database() {
        $current_version = get_option('mld_open_house_db_version', '0');

        if (version_compare($current_version, self::DB_VERSION, '<')) {
            self::upgrade_database();
            update_option('mld_open_house_db_version', self::DB_VERSION);
        }
    }

    /**
     * Upgrade database schema for Enhanced Open House Sign-In System
     * Adds columns for: other_agent_phone, other_agent_email, auto_crm_processed,
     * auto_search_created, auto_search_id, ma_disclosure_acknowledged, ma_disclosure_timestamp
     * @since 6.71.0
     */
    private static function upgrade_database() {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_open_house_attendees';

        // Check if columns already exist
        $columns = $wpdb->get_col("DESCRIBE {$table}");

        // Add new columns if they don't exist
        $new_columns = array(
            'other_agent_phone' => "ALTER TABLE {$table} ADD COLUMN other_agent_phone VARCHAR(20) DEFAULT NULL AFTER other_agent_brokerage",
            'other_agent_email' => "ALTER TABLE {$table} ADD COLUMN other_agent_email VARCHAR(100) DEFAULT NULL AFTER other_agent_phone",
            'auto_crm_processed' => "ALTER TABLE {$table} ADD COLUMN auto_crm_processed TINYINT(1) DEFAULT 0 AFTER user_id",
            'auto_search_created' => "ALTER TABLE {$table} ADD COLUMN auto_search_created TINYINT(1) DEFAULT 0 AFTER auto_crm_processed",
            'auto_search_id' => "ALTER TABLE {$table} ADD COLUMN auto_search_id INT DEFAULT NULL AFTER auto_search_created",
            'ma_disclosure_acknowledged' => "ALTER TABLE {$table} ADD COLUMN ma_disclosure_acknowledged TINYINT(1) DEFAULT 0 AFTER auto_search_id",
            'ma_disclosure_timestamp' => "ALTER TABLE {$table} ADD COLUMN ma_disclosure_timestamp DATETIME DEFAULT NULL AFTER ma_disclosure_acknowledged",
        );

        foreach ($new_columns as $column => $sql) {
            if (!in_array($column, $columns)) {
                $wpdb->query($sql);
            }
        }
    }

    /**
     * Register REST routes for Open House management
     */
    public static function register_routes() {
        // List agent's open houses
        register_rest_route(self::NAMESPACE, '/open-houses', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'handle_list_open_houses'),
            'permission_callback' => array(__CLASS__, 'check_agent_permission'),
        ));

        // Create open house
        register_rest_route(self::NAMESPACE, '/open-houses', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'handle_create_open_house'),
            'permission_callback' => array(__CLASS__, 'check_agent_permission'),
        ));

        // Get single open house with attendees
        register_rest_route(self::NAMESPACE, '/open-houses/(?P<id>\d+)', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'handle_get_open_house'),
            'permission_callback' => array(__CLASS__, 'check_agent_permission'),
        ));

        // Update open house
        register_rest_route(self::NAMESPACE, '/open-houses/(?P<id>\d+)', array(
            'methods' => 'PUT',
            'callback' => array(__CLASS__, 'handle_update_open_house'),
            'permission_callback' => array(__CLASS__, 'check_agent_permission'),
        ));

        // Delete open house
        register_rest_route(self::NAMESPACE, '/open-houses/(?P<id>\d+)', array(
            'methods' => 'DELETE',
            'callback' => array(__CLASS__, 'handle_delete_open_house'),
            'permission_callback' => array(__CLASS__, 'check_agent_permission'),
        ));

        // Start open house (mark as active)
        register_rest_route(self::NAMESPACE, '/open-houses/(?P<id>\d+)/start', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'handle_start_open_house'),
            'permission_callback' => array(__CLASS__, 'check_agent_permission'),
        ));

        // End open house (mark as completed)
        register_rest_route(self::NAMESPACE, '/open-houses/(?P<id>\d+)/end', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'handle_end_open_house'),
            'permission_callback' => array(__CLASS__, 'check_agent_permission'),
        ));

        // Add single attendee
        register_rest_route(self::NAMESPACE, '/open-houses/(?P<id>\d+)/attendees', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'handle_add_attendee'),
            'permission_callback' => array(__CLASS__, 'check_agent_permission'),
        ));

        // Bulk sync attendees (for offline mode)
        register_rest_route(self::NAMESPACE, '/open-houses/(?P<id>\d+)/attendees/bulk', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'handle_bulk_sync_attendees'),
            'permission_callback' => array(__CLASS__, 'check_agent_permission'),
        ));

        // Update attendee (interest level, notes)
        register_rest_route(self::NAMESPACE, '/open-houses/(?P<id>\d+)/attendees/(?P<attendee_id>\d+)', array(
            'methods' => 'PUT',
            'callback' => array(__CLASS__, 'handle_update_attendee'),
            'permission_callback' => array(__CLASS__, 'check_agent_permission'),
        ));

        // Export attendees as CSV
        register_rest_route(self::NAMESPACE, '/open-houses/(?P<id>\d+)/export', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'handle_export_attendees'),
            'permission_callback' => array(__CLASS__, 'check_agent_permission'),
        ));

        // Get nearby properties for location-based suggestion
        register_rest_route(self::NAMESPACE, '/properties/nearby', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'handle_get_nearby_properties'),
            'permission_callback' => array(__CLASS__, 'check_agent_permission'),
        ));

        // Convert attendee to CRM client
        register_rest_route(self::NAMESPACE, '/open-houses/attendees/(?P<attendee_id>\d+)/convert-to-client', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'handle_convert_to_client'),
            'permission_callback' => array(__CLASS__, 'check_agent_permission'),
        ));

        // Get CRM status for attendee
        register_rest_route(self::NAMESPACE, '/open-houses/attendees/(?P<attendee_id>\d+)/crm-status', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'handle_get_crm_status'),
            'permission_callback' => array(__CLASS__, 'check_agent_permission'),
        ));

        // Get attendee history (all open houses attended by email)
        register_rest_route(self::NAMESPACE, '/open-houses/attendees/(?P<attendee_id>\d+)/history', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'handle_get_attendee_history'),
            'permission_callback' => array(__CLASS__, 'check_agent_permission'),
        ));

        // Get property images for slideshow (v6.71.0 - Enhanced Open House Sign-In)
        register_rest_route(self::NAMESPACE, '/open-houses/(?P<id>\d+)/property-images', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'handle_get_property_images'),
            'permission_callback' => array(__CLASS__, 'check_agent_permission'),
        ));
    }

    /**
     * Check if user is authenticated and is an agent
     */
    public static function check_agent_permission($request) {
        // Use the main MLD auth check
        if (!class_exists('MLD_Mobile_REST_API')) {
            return new WP_Error('server_error', 'Authentication module not loaded', array('status' => 500));
        }

        $auth_result = MLD_Mobile_REST_API::check_auth($request);
        if (is_wp_error($auth_result)) {
            return $auth_result;
        }

        // Check if user is an agent
        $user_id = get_current_user_id();
        if (!$user_id) {
            return new WP_Error('unauthorized', 'Authentication required', array('status' => 401));
        }

        // Check user_types table for agent status
        global $wpdb;
        $is_agent = $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM {$wpdb->prefix}mld_user_types WHERE user_id = %d AND user_type = 'agent'",
            $user_id
        ));

        if (!$is_agent) {
            return new WP_Error('forbidden', 'Agent access required', array('status' => 403));
        }

        return true;
    }

    /**
     * Add cache-bypass headers to prevent CDN from caching authenticated responses
     *
     * Fixes CLAUDE.md Pitfall #21: CDN Caching of Authenticated Endpoints
     * Kinsta CDN was caching user-specific responses, causing data leakage.
     *
     * @since 6.70.1
     * @param WP_REST_Response $response The response to add headers to
     * @return WP_REST_Response The response with cache headers added
     */
    private static function add_no_cache_headers($response) {
        $response->header('Cache-Control', 'no-store, no-cache, must-revalidate, private');
        $response->header('Pragma', 'no-cache');
        $response->header('X-Kinsta-Cache', 'BYPASS');
        return $response;
    }

    /**
     * Handle GET /open-houses - List agent's open houses
     */
    public static function handle_list_open_houses($request) {
        global $wpdb;
        $user_id = get_current_user_id();

        $table = $wpdb->prefix . 'mld_open_houses';
        $attendees_table = $wpdb->prefix . 'mld_open_house_attendees';

        // Get optional filters
        $status = $request->get_param('status');
        $date_from = $request->get_param('date_from');
        $date_to = $request->get_param('date_to');

        $where = array("agent_user_id = %d");
        $params = array($user_id);

        if ($status) {
            $where[] = "status = %s";
            $params[] = $status;
        }

        if ($date_from) {
            $where[] = "event_date >= %s";
            $params[] = $date_from;
        }

        if ($date_to) {
            $where[] = "event_date <= %s";
            $params[] = $date_to;
        }

        $where_clause = implode(' AND ', $where);

        // Get open houses with attendee counts
        $sql = $wpdb->prepare(
            "SELECT oh.*,
                    (SELECT COUNT(*) FROM {$attendees_table} WHERE open_house_id = oh.id) as attendee_count
             FROM {$table} oh
             WHERE {$where_clause}
             ORDER BY oh.event_date DESC, oh.start_time DESC",
            $params
        );

        $open_houses = $wpdb->get_results($sql);

        // Format for API response
        $formatted = array_map(function($oh) {
            return self::format_open_house($oh);
        }, $open_houses);

        return self::add_no_cache_headers(new WP_REST_Response(array(
            'success' => true,
            'data' => array(
                'open_houses' => $formatted,
                'count' => count($formatted)
            )
        ), 200));
    }

    /**
     * Handle POST /open-houses - Create new open house
     */
    public static function handle_create_open_house($request) {
        global $wpdb;
        $user_id = get_current_user_id();
        $table = $wpdb->prefix . 'mld_open_houses';

        // Required fields
        $property_address = sanitize_text_field($request->get_param('property_address'));
        $property_city = sanitize_text_field($request->get_param('property_city'));
        $property_state = sanitize_text_field($request->get_param('property_state')) ?: 'MA';
        $property_zip = sanitize_text_field($request->get_param('property_zip'));
        $event_date = sanitize_text_field($request->get_param('date'));
        $start_time = sanitize_text_field($request->get_param('start_time'));
        $end_time = sanitize_text_field($request->get_param('end_time'));

        if (empty($property_address) || empty($property_city) || empty($property_zip) ||
            empty($event_date) || empty($start_time) || empty($end_time)) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Missing required fields: property_address, property_city, property_zip, date, start_time, end_time'
            ), 400);
        }

        // Optional fields
        $data = array(
            'agent_user_id' => $user_id,
            'listing_id' => sanitize_text_field($request->get_param('listing_id')) ?: null,
            'property_address' => $property_address,
            'property_city' => $property_city,
            'property_state' => $property_state,
            'property_zip' => $property_zip,
            'property_type' => sanitize_text_field($request->get_param('property_type')) ?: null,
            'beds' => intval($request->get_param('beds')) ?: null,
            'baths' => floatval($request->get_param('baths')) ?: null,
            'list_price' => intval($request->get_param('list_price')) ?: null,
            'photo_url' => esc_url_raw($request->get_param('photo_url')) ?: null,
            'latitude' => floatval($request->get_param('latitude')) ?: null,
            'longitude' => floatval($request->get_param('longitude')) ?: null,
            'event_date' => $event_date,
            'start_time' => $start_time,
            'end_time' => $end_time,
            'status' => 'scheduled',
            'notes' => sanitize_textarea_field($request->get_param('notes')) ?: null,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql')
        );

        $result = $wpdb->insert($table, $data);

        if ($result === false) {
            return self::add_no_cache_headers(new WP_REST_Response(array(
                'success' => false,
                'message' => 'Failed to create open house: ' . $wpdb->last_error
            ), 500));
        }

        $open_house_id = $wpdb->insert_id;
        $open_house = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d",
            $open_house_id
        ));

        return self::add_no_cache_headers(new WP_REST_Response(array(
            'success' => true,
            'data' => array(
                'open_house' => self::format_open_house($open_house),
                'message' => 'Open house created successfully'
            )
        ), 201));
    }

    /**
     * Handle GET /open-houses/{id} - Get single open house with attendees
     */
    public static function handle_get_open_house($request) {
        global $wpdb;
        $user_id = get_current_user_id();
        $open_house_id = intval($request->get_param('id'));

        $table = $wpdb->prefix . 'mld_open_houses';
        $attendees_table = $wpdb->prefix . 'mld_open_house_attendees';

        $open_house = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d AND agent_user_id = %d",
            $open_house_id, $user_id
        ));

        if (!$open_house) {
            return self::add_no_cache_headers(new WP_REST_Response(array(
                'success' => false,
                'message' => 'Open house not found'
            ), 404));
        }

        // Get filter parameters (v6.70.0)
        $filter_type = $request->get_param('filter'); // all, buyers, agents, hot
        $sort_by = $request->get_param('sort') ?: 'signed_in_at'; // signed_in_at, priority_score

        // Build query with optional filtering
        $where = "open_house_id = %d";
        $params = array($open_house_id);

        if ($filter_type === 'buyers') {
            $where .= " AND is_agent = 0";
        } elseif ($filter_type === 'agents') {
            $where .= " AND is_agent = 1";
        } elseif ($filter_type === 'hot') {
            $where .= " AND priority_score >= 80";
        }

        $order_by = $sort_by === 'priority_score' ? 'priority_score DESC' : 'signed_in_at ASC';

        // Get attendees
        $attendees = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$attendees_table} WHERE {$where} ORDER BY {$order_by}",
            ...$params
        ));

        $formatted_attendees = array_map(function($a) {
            return self::format_attendee($a);
        }, $attendees);

        $formatted_oh = self::format_open_house($open_house);
        $formatted_oh['attendees'] = $formatted_attendees;
        $formatted_oh['attendee_count'] = count($attendees);

        return self::add_no_cache_headers(new WP_REST_Response(array(
            'success' => true,
            'data' => $formatted_oh
        ), 200));
    }

    /**
     * Handle PUT /open-houses/{id} - Update open house
     */
    public static function handle_update_open_house($request) {
        global $wpdb;
        $user_id = get_current_user_id();
        $open_house_id = intval($request->get_param('id'));

        $table = $wpdb->prefix . 'mld_open_houses';

        // Verify ownership
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE id = %d AND agent_user_id = %d",
            $open_house_id, $user_id
        ));

        if (!$exists) {
            return self::add_no_cache_headers(new WP_REST_Response(array(
                'success' => false,
                'message' => 'Open house not found'
            ), 404));
        }

        // Build update data
        $data = array('updated_at' => current_time('mysql'));
        $format = array('%s');

        $fields = array(
            'listing_id' => 's',
            'property_address' => 's',
            'property_city' => 's',
            'property_state' => 's',
            'property_zip' => 's',
            'property_type' => 's',
            'beds' => 'd',
            'baths' => 'f',
            'list_price' => 'd',
            'photo_url' => 's',
            'latitude' => 'f',
            'longitude' => 'f',
            'event_date' => 's',
            'start_time' => 's',
            'end_time' => 's',
            'status' => 's',
            'notes' => 's'
        );

        foreach ($fields as $field => $type) {
            $value = $request->get_param($field);
            if ($value !== null) {
                switch ($type) {
                    case 'd':
                        $data[$field] = intval($value);
                        $format[] = '%d';
                        break;
                    case 'f':
                        $data[$field] = floatval($value);
                        $format[] = '%f';
                        break;
                    default:
                        $data[$field] = sanitize_text_field($value);
                        $format[] = '%s';
                }
            }
        }

        $result = $wpdb->update($table, $data, array('id' => $open_house_id), $format, array('%d'));

        if ($result === false) {
            return self::add_no_cache_headers(new WP_REST_Response(array(
                'success' => false,
                'message' => 'Failed to update open house: ' . $wpdb->last_error
            ), 500));
        }

        $open_house = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d",
            $open_house_id
        ));

        return self::add_no_cache_headers(new WP_REST_Response(array(
            'success' => true,
            'data' => array(
                'open_house' => self::format_open_house($open_house),
                'message' => 'Open house updated successfully'
            )
        ), 200));
    }

    /**
     * Handle DELETE /open-houses/{id} - Delete open house
     */
    public static function handle_delete_open_house($request) {
        global $wpdb;
        $user_id = get_current_user_id();
        $open_house_id = intval($request->get_param('id'));

        $table = $wpdb->prefix . 'mld_open_houses';

        // Verify ownership
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE id = %d AND agent_user_id = %d",
            $open_house_id, $user_id
        ));

        if (!$exists) {
            return self::add_no_cache_headers(new WP_REST_Response(array(
                'success' => false,
                'message' => 'Open house not found'
            ), 404));
        }

        // Delete (cascades to attendees via FK)
        $result = $wpdb->delete($table, array('id' => $open_house_id), array('%d'));

        if ($result === false) {
            return self::add_no_cache_headers(new WP_REST_Response(array(
                'success' => false,
                'message' => 'Failed to delete open house: ' . $wpdb->last_error
            ), 500));
        }

        return self::add_no_cache_headers(new WP_REST_Response(array(
            'success' => true,
            'data' => array(
                'id' => $open_house_id,
                'message' => 'Open house deleted successfully'
            )
        ), 200));
    }

    /**
     * Handle POST /open-houses/{id}/start - Mark as active
     */
    public static function handle_start_open_house($request) {
        global $wpdb;
        $user_id = get_current_user_id();
        $open_house_id = intval($request->get_param('id'));

        $table = $wpdb->prefix . 'mld_open_houses';

        $result = $wpdb->update(
            $table,
            array('status' => 'active', 'updated_at' => current_time('mysql')),
            array('id' => $open_house_id, 'agent_user_id' => $user_id),
            array('%s', '%s'),
            array('%d', '%d')
        );

        if ($result === false) {
            return self::add_no_cache_headers(new WP_REST_Response(array(
                'success' => false,
                'message' => 'Failed to start open house'
            ), 500));
        }

        if ($result === 0) {
            return self::add_no_cache_headers(new WP_REST_Response(array(
                'success' => false,
                'message' => 'Open house not found'
            ), 404));
        }

        // Fetch the updated open house to return full object
        $open_house = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d",
            $open_house_id
        ));

        return self::add_no_cache_headers(new WP_REST_Response(array(
            'success' => true,
            'data' => array(
                'open_house' => self::format_open_house($open_house),
                'message' => 'Open house started'
            )
        ), 200));
    }

    /**
     * Handle POST /open-houses/{id}/end - Mark as completed
     */
    public static function handle_end_open_house($request) {
        global $wpdb;
        $user_id = get_current_user_id();
        $open_house_id = intval($request->get_param('id'));

        $table = $wpdb->prefix . 'mld_open_houses';

        $result = $wpdb->update(
            $table,
            array('status' => 'completed', 'updated_at' => current_time('mysql')),
            array('id' => $open_house_id, 'agent_user_id' => $user_id),
            array('%s', '%s'),
            array('%d', '%d')
        );

        if ($result === false) {
            return self::add_no_cache_headers(new WP_REST_Response(array(
                'success' => false,
                'message' => 'Failed to end open house'
            ), 500));
        }

        if ($result === 0) {
            return self::add_no_cache_headers(new WP_REST_Response(array(
                'success' => false,
                'message' => 'Open house not found'
            ), 404));
        }

        // Fetch the updated open house to return full object
        $open_house = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d",
            $open_house_id
        ));

        return self::add_no_cache_headers(new WP_REST_Response(array(
            'success' => true,
            'data' => array(
                'open_house' => self::format_open_house($open_house),
                'message' => 'Open house ended'
            )
        ), 200));
    }

    /**
     * Handle POST /open-houses/{id}/attendees - Add single attendee
     */
    public static function handle_add_attendee($request) {
        global $wpdb;
        $user_id = get_current_user_id();
        $open_house_id = intval($request->get_param('id'));

        $table = $wpdb->prefix . 'mld_open_houses';
        $attendees_table = $wpdb->prefix . 'mld_open_house_attendees';

        // Verify ownership
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE id = %d AND agent_user_id = %d",
            $open_house_id, $user_id
        ));

        if (!$exists) {
            return self::add_no_cache_headers(new WP_REST_Response(array(
                'success' => false,
                'message' => 'Open house not found'
            ), 404));
        }

        // Required fields
        $first_name = sanitize_text_field($request->get_param('first_name'));
        $last_name = sanitize_text_field($request->get_param('last_name'));
        $email = sanitize_email($request->get_param('email'));
        $phone = sanitize_text_field($request->get_param('phone'));

        if (empty($first_name) || empty($last_name) || empty($email) || empty($phone)) {
            return self::add_no_cache_headers(new WP_REST_Response(array(
                'success' => false,
                'message' => 'Missing required fields: first_name, last_name, email, phone'
            ), 400));
        }

        // Validate email format
        if (!is_email($email)) {
            return self::add_no_cache_headers(new WP_REST_Response(array(
                'success' => false,
                'message' => 'Invalid email address format'
            ), 400));
        }

        // Validate phone has at least 10 digits
        $phone_digits = preg_replace('/[^0-9]/', '', $phone);
        if (strlen($phone_digits) < 10) {
            return self::add_no_cache_headers(new WP_REST_Response(array(
                'success' => false,
                'message' => 'Phone number must have at least 10 digits'
            ), 400));
        }

        // Generate local_uuid if not provided
        $local_uuid = sanitize_text_field($request->get_param('local_uuid'));
        if (empty($local_uuid)) {
            $local_uuid = wp_generate_uuid4();
        }

        // Check for duplicate
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$attendees_table} WHERE local_uuid = %s",
            $local_uuid
        ));

        if ($existing) {
            return self::add_no_cache_headers(new WP_REST_Response(array(
                'success' => true,
                'data' => array(
                    'id' => intval($existing),
                    'local_uuid' => $local_uuid,
                    'message' => 'Attendee already exists (deduplicated)'
                )
            ), 200));
        }

        // Check if visitor is a real estate agent (v6.70.0)
        // v6.72.0: Also check working_with_agent = 'i_am_agent' for agent identification
        $working_with_agent_raw = sanitize_text_field($request->get_param('working_with_agent'));
        $is_agent = $request->get_param('is_agent') ? 1 : 0;
        if ($working_with_agent_raw === 'i_am_agent') {
            $is_agent = 1;
        }

        // Insert attendee
        $data = array(
            'open_house_id' => $open_house_id,
            'local_uuid' => $local_uuid,
            'first_name' => $first_name,
            'last_name' => $last_name,
            'email' => $email,
            'phone' => $phone,
            // Agent visitor fields (v6.70.0)
            'is_agent' => $is_agent,
            'agent_brokerage' => $is_agent ? sanitize_text_field($request->get_param('visitor_agent_brokerage')) : null,
            'agent_visit_purpose' => $is_agent ? sanitize_text_field($request->get_param('agent_visit_purpose')) : null,
            'agent_has_buyer' => $is_agent ? ($request->get_param('agent_has_buyer') ? 1 : 0) : null,
            'agent_buyer_timeline' => $is_agent ? sanitize_text_field($request->get_param('agent_buyer_timeline')) : null,
            'agent_network_interest' => $is_agent ? ($request->get_param('agent_network_interest') ? 1 : 0) : null,
            // Buyer path fields (only for non-agent visitors)
            'working_with_agent' => !$is_agent ? (sanitize_text_field($request->get_param('working_with_agent')) ?: 'no') : 'no',
            'other_agent_name' => !$is_agent ? sanitize_text_field($request->get_param('agent_name')) : null,
            'other_agent_brokerage' => !$is_agent ? sanitize_text_field($request->get_param('agent_brokerage')) : null,
            // v6.71.0: Enhanced agent contact fields
            'other_agent_phone' => !$is_agent ? sanitize_text_field($request->get_param('agent_phone')) : null,
            'other_agent_email' => !$is_agent ? sanitize_email($request->get_param('agent_email')) : null,
            'buying_timeline' => !$is_agent ? (sanitize_text_field($request->get_param('buying_timeline')) ?: 'just_browsing') : 'just_browsing',
            'pre_approved' => !$is_agent ? (sanitize_text_field($request->get_param('pre_approved')) ?: 'not_sure') : 'not_sure',
            'lender_name' => !$is_agent ? sanitize_text_field($request->get_param('lender_name')) : null,
            'how_heard_about' => sanitize_text_field($request->get_param('how_heard_about')) ?: null,
            'consent_to_follow_up' => $request->get_param('consent_to_follow_up') ? 1 : 0,
            'consent_to_email' => $request->get_param('consent_to_email') ? 1 : 0,
            'consent_to_text' => $request->get_param('consent_to_text') ? 1 : 0,
            // v6.71.0: Massachusetts disclosure acknowledgment
            'ma_disclosure_acknowledged' => $request->get_param('ma_disclosure_acknowledged') ? 1 : 0,
            'ma_disclosure_timestamp' => $request->get_param('ma_disclosure_acknowledged') ? current_time('mysql') : null,
            'interest_level' => sanitize_text_field($request->get_param('interest_level')) ?: 'unknown',
            'agent_notes' => sanitize_textarea_field($request->get_param('notes')) ?: null,
            'signed_in_at' => sanitize_text_field($request->get_param('signed_in_at')) ?: current_time('mysql'),
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql')
        );

        // If agent has a buyer interested, auto-set interest to very_interested
        // NOTE: Must be set BEFORE priority score calculation so +25 interest bonus is included
        if ($is_agent && $data['agent_has_buyer']) {
            $data['interest_level'] = 'very_interested';
        }

        // Calculate priority score (v6.70.0)
        $data['priority_score'] = self::calculate_priority_score($data);

        // v6.72.0: Defensive check for updated_at column - auto-add if missing
        $column_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'updated_at'",
            DB_NAME,
            $attendees_table
        ));
        if (!$column_exists) {
            // Add the column if it doesn't exist
            $wpdb->query("ALTER TABLE {$attendees_table} ADD COLUMN updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at");
        }

        $result = $wpdb->insert($attendees_table, $data);

        if ($result === false) {
            return self::add_no_cache_headers(new WP_REST_Response(array(
                'success' => false,
                'message' => 'Failed to add attendee: ' . $wpdb->last_error
            ), 500));
        }

        $attendee_id = $wpdb->insert_id;

        // v6.69.0: Send push notification to agent when attendee signs in
        // Get agent user_id and property address from open house record
        $oh_data = $wpdb->get_row($wpdb->prepare(
            "SELECT agent_user_id, property_address, listing_id FROM {$table} WHERE id = %d",
            $open_house_id
        ));
        $agent_user_id = $oh_data ? $oh_data->agent_user_id : null;

        // v6.75.11: Look up listing_key for deep linking from push notifications
        $oh_listing_id = $oh_data ? $oh_data->listing_id : null;
        $oh_listing_key = null;
        if ($oh_listing_id) {
            $summary_table = $wpdb->prefix . 'bme_listing_summary';
            $oh_listing_key = $wpdb->get_var($wpdb->prepare(
                "SELECT listing_key FROM {$summary_table} WHERE listing_id = %s",
                $oh_listing_id
            ));
        }

        // v6.72.0: Get device token to exclude from notification (prevents kiosk from notifying itself)
        $exclude_device_token = sanitize_text_field($request->get_param('exclude_device_token'));

        // Send push notification with 60-second debounce to prevent spam
        if ($agent_user_id && class_exists('MLD_Push_Notifications')) {
            // v6.70.0: Special high-priority notification when agent has buyer interested
            if ($is_agent && $data['agent_has_buyer']) {
                $brokerage = $data['agent_brokerage'] ?: 'Unknown Brokerage';
                MLD_Push_Notifications::send_activity_notification(
                    $agent_user_id,
                    'Agent Has Buyer!',
                    "Agent from {$brokerage} has a buyer interested in " . ($oh_data->property_address ?: 'your listing'),
                    'open_house_agent_buyer',
                    array(
                        'open_house_id' => $open_house_id,
                        'attendee_id' => $attendee_id,
                        'is_high_priority' => true,
                        'listing_id' => $oh_listing_id,
                        'listing_key' => $oh_listing_key,
                        'exclude_device_token' => $exclude_device_token  // v6.72.0
                    )
                );
            } else {
                // Standard sign-in notification with debounce
                $debounce_key = "mld_oh_signin_notify_{$open_house_id}";
                if (!get_transient($debounce_key)) {
                    $visitor_type = $is_agent ? 'Agent' : 'Visitor';
                    MLD_Push_Notifications::send_activity_notification(
                        $agent_user_id,
                        "New {$visitor_type} Sign-In",
                        "{$first_name} {$last_name} just signed in",
                        'open_house_signin',
                        array(
                            'open_house_id' => $open_house_id,
                            'listing_id' => $oh_listing_id,
                            'listing_key' => $oh_listing_key,
                            'exclude_device_token' => $exclude_device_token  // v6.72.0
                        )
                    );
                    set_transient($debounce_key, time(), 60); // 60 sec debounce
                }
            }
        }

        // v6.71.0: Auto-processing for Enhanced Open House Sign-In System
        $auto_processing_result = null;
        $working_with_agent = $data['working_with_agent'];
        $consent_to_follow_up = $data['consent_to_follow_up'];

        // Only process non-agent visitors
        if (!$is_agent) {
            // For buyers WITH an agent - send notification with agent contact details
            if ($working_with_agent === 'yes_other' && $agent_user_id && class_exists('MLD_Push_Notifications')) {
                $buyer_agent_name = $data['other_agent_name'] ?: 'Unknown';
                $buyer_agent_brokerage = $data['other_agent_brokerage'] ?: '';
                $buyer_agent_phone = $data['other_agent_phone'] ?: '';
                $buyer_agent_email = $data['other_agent_email'] ?: '';

                $body_parts = array("Represented by: {$buyer_agent_name}");
                if ($buyer_agent_brokerage) {
                    $body_parts[] = "Brokerage: {$buyer_agent_brokerage}";
                }
                if ($buyer_agent_phone) {
                    $body_parts[] = "Phone: {$buyer_agent_phone}";
                }

                MLD_Push_Notifications::send_activity_notification(
                    $agent_user_id,
                    'Represented Buyer Visit',
                    implode(' | ', $body_parts),
                    'open_house_represented_buyer',
                    array(
                        'open_house_id' => $open_house_id,
                        'attendee_id' => $attendee_id,
                        'listing_id' => $oh_listing_id,
                        'listing_key' => $oh_listing_key,
                        'buyer_agent_name' => $buyer_agent_name,
                        'buyer_agent_brokerage' => $buyer_agent_brokerage,
                        'buyer_agent_phone' => $buyer_agent_phone,
                        'buyer_agent_email' => $buyer_agent_email
                    )
                );
            }

            // For buyers WITHOUT an agent who consent to follow-up - auto-create account and saved search
            if ($working_with_agent === 'no' && $consent_to_follow_up) {
                $auto_processing_result = self::process_unrepresented_buyer(
                    $attendee_id,
                    $open_house_id,
                    $agent_user_id,
                    $data,
                    $attendees_table
                );
            }
        }

        // v6.72.0: Fetch the full attendee record to return to iOS
        // iOS AddAttendeeResponse expects {attendee: OpenHouseAttendee, message: String?}
        $attendee_row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$attendees_table} WHERE id = %d",
            $attendee_id
        ), ARRAY_A);

        // Format attendee for iOS (snake_case keys match CodingKeys)
        $attendee_response = array(
            'id' => intval($attendee_row['id']),
            'local_uuid' => $attendee_row['local_uuid'],
            'open_house_id' => intval($attendee_row['open_house_id']),
            'first_name' => $attendee_row['first_name'],
            'last_name' => $attendee_row['last_name'],
            'email' => $attendee_row['email'],
            'phone' => $attendee_row['phone'],
            'is_agent' => (bool) $attendee_row['is_agent'],
            'visitor_agent_brokerage' => $attendee_row['agent_brokerage'],
            'agent_visit_purpose' => $attendee_row['agent_visit_purpose'],
            'agent_has_buyer' => $attendee_row['agent_has_buyer'] ? true : false,
            'agent_buyer_timeline' => $attendee_row['agent_buyer_timeline'],
            'agent_network_interest' => $attendee_row['agent_network_interest'] ? true : false,
            'working_with_agent' => $attendee_row['working_with_agent'] ?: 'no',
            'other_agent_name' => $attendee_row['other_agent_name'],
            'other_agent_brokerage' => $attendee_row['other_agent_brokerage'],
            'other_agent_phone' => $attendee_row['other_agent_phone'],
            'other_agent_email' => $attendee_row['other_agent_email'],
            'buying_timeline' => $attendee_row['buying_timeline'] ?: 'just_browsing',
            'pre_approved' => $attendee_row['pre_approved'] ?: 'not_sure',
            'lender_name' => $attendee_row['lender_name'],
            'how_heard_about' => $attendee_row['how_heard_about'],
            'consent_to_follow_up' => (bool) $attendee_row['consent_to_follow_up'],
            'consent_to_email' => (bool) $attendee_row['consent_to_email'],
            'consent_to_text' => (bool) $attendee_row['consent_to_text'],
            'interest_level' => $attendee_row['interest_level'] ?: 'unknown',
            'agent_notes' => $attendee_row['agent_notes'],
            'user_id' => $attendee_row['user_id'] ? intval($attendee_row['user_id']) : null,
            'priority_score' => intval($attendee_row['priority_score'] ?? 0),
            'auto_crm_processed' => (bool) ($attendee_row['auto_crm_processed'] ?? false),
            'auto_search_created' => (bool) ($attendee_row['auto_search_created'] ?? false),
            'auto_search_id' => $attendee_row['auto_search_id'] ? intval($attendee_row['auto_search_id']) : null,
            'ma_disclosure_acknowledged' => (bool) ($attendee_row['ma_disclosure_acknowledged'] ?? false),
            'ma_disclosure_timestamp' => $attendee_row['ma_disclosure_timestamp'],
            'signed_in_at' => $attendee_row['signed_in_at'] ?: current_time('mysql'),
        );

        $response_data = array(
            'attendee' => $attendee_response,
            'message' => 'Attendee added successfully'
        );

        if ($auto_processing_result) {
            $response_data['auto_processing'] = $auto_processing_result;
        }

        return self::add_no_cache_headers(new WP_REST_Response(array(
            'success' => true,
            'data' => $response_data
        ), 201));
    }

    /**
     * Process unrepresented buyer - creates account, relationship, and saved search
     *
     * @since 6.71.0
     * @param int $attendee_id The attendee ID
     * @param int $open_house_id The open house ID
     * @param int $agent_user_id The agent's user ID
     * @param array $data The attendee data
     * @param string $attendees_table The attendees table name
     * @return array Processing result
     */
    private static function process_unrepresented_buyer($attendee_id, $open_house_id, $agent_user_id, $data, $attendees_table) {
        global $wpdb;

        $result = array(
            'user_created' => false,
            'search_created' => false,
            'user_id' => null,
            'search_id' => null,
            'errors' => array()
        );

        // Get open house property details for saved search
        $oh_table = $wpdb->prefix . 'mld_open_houses';
        $open_house = $wpdb->get_row($wpdb->prepare(
            "SELECT property_address, property_city, property_type, list_price, beds FROM {$oh_table} WHERE id = %d",
            $open_house_id
        ));

        if (!$open_house) {
            $result['errors'][] = 'Could not retrieve open house details';
            return $result;
        }

        // Check if user already exists
        $existing_user = get_user_by('email', $data['email']);
        $client_user_id = null;

        if ($existing_user) {
            $client_user_id = $existing_user->ID;
            $result['user_id'] = $client_user_id;
            $result['user_created'] = false;
        } else {
            // Create new user account
            $username = sanitize_user(strtolower($data['first_name'] . '.' . $data['last_name']));
            $original_username = $username;
            $counter = 1;

            while (username_exists($username)) {
                $username = $original_username . $counter;
                $counter++;
            }

            $random_password = wp_generate_password(12, true);

            $client_user_id = wp_insert_user(array(
                'user_login' => $username,
                'user_email' => $data['email'],
                'user_pass' => $random_password,
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'role' => 'subscriber',
                'display_name' => $data['first_name'] . ' ' . $data['last_name']
            ));

            if (is_wp_error($client_user_id)) {
                $result['errors'][] = 'Failed to create user: ' . $client_user_id->get_error_message();
                return $result;
            }

            // Store phone and lead source in user meta
            update_user_meta($client_user_id, 'phone', $data['phone']);
            update_user_meta($client_user_id, 'mld_lead_source', 'open_house_signin');
            update_user_meta($client_user_id, 'mld_lead_source_details', json_encode(array(
                'open_house_id' => $open_house_id,
                'property_address' => $open_house->property_address ?: $open_house->property_city,
                'signed_in_at' => $data['signed_in_at']
            )));

            $result['user_created'] = true;
            $result['user_id'] = $client_user_id;

            // Send welcome email with password setup link
            wp_new_user_notification($client_user_id, null, 'user');
        }

        // Create agent-client relationship if not exists
        $relationships_table = $wpdb->prefix . 'mld_agent_client_relationships';
        $existing_relationship = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$relationships_table}
             WHERE agent_id = %d AND client_id = %d",
            $agent_user_id, $client_user_id
        ));

        if (!$existing_relationship) {
            $rel_result = $wpdb->insert($relationships_table, array(
                'agent_id' => $agent_user_id,
                'client_id' => $client_user_id,
                'status' => 'active',
                'notes' => "Auto-created from open house sign-in #{$attendee_id}",
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ));

            if ($rel_result === false) {
                $result['errors'][] = 'Failed to create agent-client relationship: ' . $wpdb->last_error;
            }
        }

        // Set user type to client (preserve existing created_at if record exists)
        $user_types_table = $wpdb->prefix . 'mld_user_types';
        $existing_type = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$user_types_table} WHERE user_id = %d",
            $client_user_id
        ));

        if ($existing_type) {
            $wpdb->update($user_types_table, array(
                'user_type' => 'client',
                'updated_at' => current_time('mysql')
            ), array('user_id' => $client_user_id));
        } else {
            $wpdb->insert($user_types_table, array(
                'user_id' => $client_user_id,
                'user_type' => 'client',
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ));
        }

        // Create property-based saved search
        if (class_exists('MLD_Saved_Searches')) {
            $search_filters = array();

            // City filter
            if (!empty($open_house->property_city)) {
                $search_filters['city'] = $open_house->property_city;
            }

            // Price range: ±20% of list price
            if (!empty($open_house->list_price) && $open_house->list_price > 0) {
                $price = intval($open_house->list_price);
                $search_filters['min_price'] = intval($price * 0.8);
                $search_filters['max_price'] = intval($price * 1.2);
            }

            // Beds: same or more
            if (!empty($open_house->beds) && $open_house->beds > 0) {
                $search_filters['beds'] = intval($open_house->beds);
            }

            // Property type
            if (!empty($open_house->property_type)) {
                $search_filters['property_type'] = $open_house->property_type;
            }

            // Only create search if we have meaningful filters
            if (!empty($search_filters)) {
                $search_name = "Homes like " . ($open_house->property_city ?: 'Open House Property');

                $search_data = array(
                    'user_id' => $client_user_id,
                    'name' => $search_name,
                    'description' => 'Auto-created from open house sign-in',
                    'filters' => $search_filters,
                    'notification_frequency' => 'instant',
                    'is_active' => true,
                    'exclude_disliked' => true,
                    'created_by_admin' => $agent_user_id
                );

                $search_id = MLD_Saved_Searches::create_search($search_data);

                if (!is_wp_error($search_id)) {
                    $result['search_created'] = true;
                    $result['search_id'] = $search_id;
                } else {
                    $result['errors'][] = 'Failed to create saved search: ' . $search_id->get_error_message();
                }
            }
        }

        // Update attendee record with auto-processing flags
        $update_data = array(
            'user_id' => $client_user_id,
            'auto_crm_processed' => 1,
            'updated_at' => current_time('mysql')
        );

        if ($result['search_created'] && $result['search_id']) {
            $update_data['auto_search_created'] = 1;
            $update_data['auto_search_id'] = $result['search_id'];
        }

        $wpdb->update($attendees_table, $update_data, array('id' => $attendee_id));

        return $result;
    }

    /**
     * Handle POST /open-houses/{id}/attendees/bulk - Bulk sync attendees
     */
    public static function handle_bulk_sync_attendees($request) {
        global $wpdb;
        $user_id = get_current_user_id();
        $open_house_id = intval($request->get_param('id'));

        $table = $wpdb->prefix . 'mld_open_houses';
        $attendees_table = $wpdb->prefix . 'mld_open_house_attendees';

        // Verify ownership
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE id = %d AND agent_user_id = %d",
            $open_house_id, $user_id
        ));

        if (!$exists) {
            return self::add_no_cache_headers(new WP_REST_Response(array(
                'success' => false,
                'message' => 'Open house not found'
            ), 404));
        }

        $attendees = $request->get_param('attendees');
        if (!is_array($attendees)) {
            return self::add_no_cache_headers(new WP_REST_Response(array(
                'success' => false,
                'message' => 'attendees must be an array'
            ), 400));
        }

        $synced = array();
        $errors = array();

        foreach ($attendees as $attendee) {
            $local_uuid = sanitize_text_field($attendee['local_uuid'] ?? '');
            if (empty($local_uuid)) {
                $errors[] = array('error' => 'Missing local_uuid');
                continue;
            }

            // Check for duplicate
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$attendees_table} WHERE local_uuid = %s",
                $local_uuid
            ));

            if ($existing) {
                $synced[] = array(
                    'id' => intval($existing),
                    'local_uuid' => $local_uuid,
                    'status' => 'exists'
                );
                continue;
            }

            // Validate required fields
            $first_name = sanitize_text_field($attendee['first_name'] ?? '');
            $last_name = sanitize_text_field($attendee['last_name'] ?? '');
            $email = sanitize_email($attendee['email'] ?? '');
            $phone = sanitize_text_field($attendee['phone'] ?? '');

            if (empty($first_name) || empty($last_name) || empty($email) || empty($phone)) {
                $errors[] = array(
                    'local_uuid' => $local_uuid,
                    'error' => 'Missing required fields'
                );
                continue;
            }

            // Validate email format
            if (!is_email($email)) {
                $errors[] = array(
                    'local_uuid' => $local_uuid,
                    'error' => 'Invalid email address format'
                );
                continue;
            }

            // Validate phone has at least 10 digits
            $phone_digits = preg_replace('/[^0-9]/', '', $phone);
            if (strlen($phone_digits) < 10) {
                $errors[] = array(
                    'local_uuid' => $local_uuid,
                    'error' => 'Phone number must have at least 10 digits'
                );
                continue;
            }

            // Check if visitor is an agent (v6.70.0)
            $is_agent = !empty($attendee['is_agent']) ? 1 : 0;

            // Insert
            $data = array(
                'open_house_id' => $open_house_id,
                'local_uuid' => $local_uuid,
                'first_name' => $first_name,
                'last_name' => $last_name,
                'email' => $email,
                'phone' => $phone,
                // Agent visitor fields (v6.70.0)
                'is_agent' => $is_agent,
                'agent_brokerage' => $is_agent ? sanitize_text_field($attendee['visitor_agent_brokerage'] ?? '') : null,
                'agent_visit_purpose' => $is_agent ? sanitize_text_field($attendee['agent_visit_purpose'] ?? '') : null,
                'agent_has_buyer' => $is_agent ? (!empty($attendee['agent_has_buyer']) ? 1 : 0) : null,
                'agent_buyer_timeline' => $is_agent ? sanitize_text_field($attendee['agent_buyer_timeline'] ?? '') : null,
                'agent_network_interest' => $is_agent ? (!empty($attendee['agent_network_interest']) ? 1 : 0) : null,
                // Buyer path fields
                'working_with_agent' => !$is_agent ? sanitize_text_field($attendee['working_with_agent'] ?? 'no') : 'no',
                'other_agent_name' => !$is_agent ? (sanitize_text_field($attendee['agent_name'] ?? '') ?: null) : null,
                'other_agent_brokerage' => !$is_agent ? (sanitize_text_field($attendee['agent_brokerage'] ?? '') ?: null) : null,
                // v6.71.0: Enhanced agent contact fields
                'other_agent_phone' => !$is_agent ? (sanitize_text_field($attendee['agent_phone'] ?? '') ?: null) : null,
                'other_agent_email' => !$is_agent ? (sanitize_email($attendee['agent_email'] ?? '') ?: null) : null,
                'buying_timeline' => !$is_agent ? sanitize_text_field($attendee['buying_timeline'] ?? 'just_browsing') : 'just_browsing',
                'pre_approved' => !$is_agent ? sanitize_text_field($attendee['pre_approved'] ?? 'not_sure') : 'not_sure',
                'lender_name' => !$is_agent ? (sanitize_text_field($attendee['lender_name'] ?? '') ?: null) : null,
                'how_heard_about' => sanitize_text_field($attendee['how_heard_about'] ?? '') ?: null,
                'consent_to_follow_up' => !empty($attendee['consent_to_follow_up']) ? 1 : 0,
                'consent_to_email' => !empty($attendee['consent_to_email']) ? 1 : 0,
                'consent_to_text' => ($attendee['consent_to_text'] ?? false) ? 1 : 0,
                // v6.71.0: Massachusetts disclosure
                'ma_disclosure_acknowledged' => !empty($attendee['ma_disclosure_acknowledged']) ? 1 : 0,
                'ma_disclosure_timestamp' => !empty($attendee['ma_disclosure_acknowledged']) ? current_time('mysql') : null,
                'interest_level' => sanitize_text_field($attendee['interest_level'] ?? 'unknown'),
                'agent_notes' => sanitize_textarea_field($attendee['notes'] ?? '') ?: null,
                'signed_in_at' => sanitize_text_field($attendee['signed_in_at'] ?? current_time('mysql')),
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            );

            // If agent has buyer, auto-set interest level (BEFORE priority score)
            if ($is_agent && $data['agent_has_buyer']) {
                $data['interest_level'] = 'very_interested';
            }

            // Calculate priority score (v6.70.0)
            $data['priority_score'] = self::calculate_priority_score($data);

            $result = $wpdb->insert($attendees_table, $data);

            if ($result === false) {
                $errors[] = array(
                    'local_uuid' => $local_uuid,
                    'error' => $wpdb->last_error
                );
            } else {
                $new_attendee_id = $wpdb->insert_id;
                $synced[] = array(
                    'id' => $new_attendee_id,
                    'local_uuid' => $local_uuid,
                    'status' => 'created'
                );

                // v6.75.9: Auto-CRM processing for unrepresented buyers (parity with single-attendee endpoint)
                $working_with_agent = $data['working_with_agent'] ?? 'no';
                $consent_to_follow_up = $data['consent_to_follow_up'] ?? 0;

                if (!$is_agent && $working_with_agent === 'no' && $consent_to_follow_up) {
                    self::process_unrepresented_buyer(
                        $new_attendee_id,
                        $open_house_id,
                        $user_id,
                        $data,
                        $attendees_table
                    );
                }
            }
        }

        // v6.75.9: Send summary push notification to agent after bulk sync
        if (!empty($synced) && class_exists('MLD_Push_Notifications')) {
            $count = count($synced);
            $notification_body = $count === 1
                ? "1 attendee synced from offline mode"
                : "{$count} attendees synced from offline mode";

            // v6.75.11: Fetch listing data for deep linking
            $sync_oh_data = $wpdb->get_row($wpdb->prepare(
                "SELECT listing_id FROM {$table} WHERE id = %d",
                $open_house_id
            ));
            $sync_listing_id = $sync_oh_data ? $sync_oh_data->listing_id : null;
            $sync_listing_key = null;
            if ($sync_listing_id) {
                $summary_table = $wpdb->prefix . 'bme_listing_summary';
                $sync_listing_key = $wpdb->get_var($wpdb->prepare(
                    "SELECT listing_key FROM {$summary_table} WHERE listing_id = %s",
                    $sync_listing_id
                ));
            }

            MLD_Push_Notifications::send_activity_notification(
                $user_id,
                'Open House Sync Complete',
                $notification_body,
                'open_house_signin',
                array(
                    'open_house_id' => $open_house_id,
                    'listing_id' => $sync_listing_id,
                    'listing_key' => $sync_listing_key,
                    'synced_count' => $count
                )
            );
        }

        return self::add_no_cache_headers(new WP_REST_Response(array(
            'success' => true,
            'data' => array(
                'synced' => $synced,
                'errors' => $errors,
                'synced_count' => count($synced),
                'error_count' => count($errors)
            )
        ), 200));
    }

    /**
     * Handle PUT /open-houses/{id}/attendees/{attendee_id} - Update attendee
     */
    public static function handle_update_attendee($request) {
        global $wpdb;
        $user_id = get_current_user_id();
        $open_house_id = intval($request->get_param('id'));
        $attendee_id = intval($request->get_param('attendee_id'));

        $table = $wpdb->prefix . 'mld_open_houses';
        $attendees_table = $wpdb->prefix . 'mld_open_house_attendees';

        // Verify ownership
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE id = %d AND agent_user_id = %d",
            $open_house_id, $user_id
        ));

        if (!$exists) {
            return self::add_no_cache_headers(new WP_REST_Response(array(
                'success' => false,
                'message' => 'Open house not found'
            ), 404));
        }

        // Verify attendee belongs to this open house
        $attendee_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$attendees_table} WHERE id = %d AND open_house_id = %d",
            $attendee_id, $open_house_id
        ));

        if (!$attendee_exists) {
            return self::add_no_cache_headers(new WP_REST_Response(array(
                'success' => false,
                'message' => 'Attendee not found'
            ), 404));
        }

        // Update fields
        $data = array('updated_at' => current_time('mysql'));
        $format = array('%s');

        // Interest level
        $interest_level = $request->get_param('interest_level');
        if ($interest_level !== null) {
            $data['interest_level'] = sanitize_text_field($interest_level);
            $format[] = '%s';
        }

        // Agent notes
        $notes = $request->get_param('notes');
        if ($notes !== null) {
            $data['agent_notes'] = sanitize_textarea_field($notes);
            $format[] = '%s';
        }

        $result = $wpdb->update(
            $attendees_table,
            $data,
            array('id' => $attendee_id),
            $format,
            array('%d')
        );

        if ($result === false) {
            return self::add_no_cache_headers(new WP_REST_Response(array(
                'success' => false,
                'message' => 'Failed to update attendee: ' . $wpdb->last_error
            ), 500));
        }

        $attendee = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$attendees_table} WHERE id = %d",
            $attendee_id
        ));

        return self::add_no_cache_headers(new WP_REST_Response(array(
            'success' => true,
            'data' => array(
                'attendee' => self::format_attendee($attendee),
                'message' => 'Attendee updated successfully'
            )
        ), 200));
    }

    /**
     * Handle GET /open-houses/{id}/export - Export attendees as CSV
     */
    public static function handle_export_attendees($request) {
        global $wpdb;
        $user_id = get_current_user_id();
        $open_house_id = intval($request->get_param('id'));

        $table = $wpdb->prefix . 'mld_open_houses';
        $attendees_table = $wpdb->prefix . 'mld_open_house_attendees';

        // Get open house with attendees
        $open_house = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d AND agent_user_id = %d",
            $open_house_id, $user_id
        ));

        if (!$open_house) {
            return self::add_no_cache_headers(new WP_REST_Response(array(
                'success' => false,
                'message' => 'Open house not found'
            ), 404));
        }

        $attendees = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$attendees_table} WHERE open_house_id = %d ORDER BY signed_in_at ASC",
            $open_house_id
        ));

        // Build CSV content (v6.70.0: added agent visitor fields)
        $csv_lines = array();
        $csv_lines[] = implode(',', array(
            'First Name', 'Last Name', 'Email', 'Phone',
            'Visitor Type', 'Priority Score', 'Priority Tier',
            // Agent visitor fields
            'Agent Brokerage (Visitor)', 'Visit Purpose', 'Has Buyer', 'Buyer Timeline', 'Network Interest',
            // Buyer fields
            'Working With Agent', 'Agent Name', 'Agent Brokerage', 'Agent Phone', 'Agent Email',
            'Buying Timeline', 'Pre-Approved', 'Lender',
            'How Heard About', 'Interest Level', 'Notes',
            'Consent Follow-Up', 'Consent Email', 'Consent Text',
            'MA Disclosure', 'Auto CRM', 'Auto Search',
            'Signed In At'
        ));

        foreach ($attendees as $a) {
            $is_agent = isset($a->is_agent) && $a->is_agent;
            $priority_score = isset($a->priority_score) ? intval($a->priority_score) : 0;

            $csv_lines[] = implode(',', array(
                self::csv_escape($a->first_name),
                self::csv_escape($a->last_name),
                self::csv_escape($a->email),
                self::csv_escape($a->phone),
                $is_agent ? 'Agent' : 'Buyer',
                $priority_score,
                self::get_priority_tier($priority_score),
                // Agent visitor fields
                self::csv_escape($is_agent && isset($a->agent_brokerage) ? $a->agent_brokerage : ''),
                self::csv_escape($is_agent && isset($a->agent_visit_purpose) ? $a->agent_visit_purpose : ''),
                $is_agent && isset($a->agent_has_buyer) && $a->agent_has_buyer ? 'Yes' : '',
                self::csv_escape($is_agent && isset($a->agent_buyer_timeline) ? $a->agent_buyer_timeline : ''),
                $is_agent && isset($a->agent_network_interest) && $a->agent_network_interest ? 'Yes' : '',
                // Buyer fields
                self::csv_escape(!$is_agent ? $a->working_with_agent : ''),
                self::csv_escape(!$is_agent && $a->other_agent_name ? $a->other_agent_name : ''),
                self::csv_escape(!$is_agent && $a->other_agent_brokerage ? $a->other_agent_brokerage : ''),
                self::csv_escape(!$is_agent && isset($a->other_agent_phone) ? $a->other_agent_phone : ''),
                self::csv_escape(!$is_agent && isset($a->other_agent_email) ? $a->other_agent_email : ''),
                self::csv_escape(!$is_agent ? $a->buying_timeline : ''),
                self::csv_escape(!$is_agent ? $a->pre_approved : ''),
                self::csv_escape(!$is_agent && $a->lender_name ? $a->lender_name : ''),
                self::csv_escape($a->how_heard_about ?: ''),
                self::csv_escape($a->interest_level),
                self::csv_escape($a->agent_notes ?: ''),
                $a->consent_to_follow_up ? 'Yes' : 'No',
                $a->consent_to_email ? 'Yes' : 'No',
                $a->consent_to_text ? 'Yes' : 'No',
                isset($a->ma_disclosure_acknowledged) && $a->ma_disclosure_acknowledged ? 'Yes' : 'No',
                isset($a->auto_crm_processed) && $a->auto_crm_processed ? 'Yes' : 'No',
                isset($a->auto_search_created) && $a->auto_search_created ? 'Yes' : 'No',
                $a->signed_in_at
            ));
        }

        $csv_content = implode("\n", $csv_lines);

        // Return CSV data
        return self::add_no_cache_headers(new WP_REST_Response(array(
            'success' => true,
            'data' => array(
                'csv' => $csv_content,
                'filename' => sprintf(
                    'open-house-%s-%s.csv',
                    sanitize_title($open_house->property_address),
                    $open_house->event_date
                ),
                'attendee_count' => count($attendees)
            )
        ), 200));
    }

    /**
     * Handle GET /properties/nearby - Get nearby properties for location-based suggestion
     */
    public static function handle_get_nearby_properties($request) {
        global $wpdb;

        $lat = floatval($request->get_param('lat'));
        $lng = floatval($request->get_param('lng'));
        $radius = floatval($request->get_param('radius')) ?: 0.1; // Default 0.1 miles

        if (!$lat || !$lng) {
            return self::add_no_cache_headers(new WP_REST_Response(array(
                'success' => false,
                'message' => 'Missing required parameters: lat, lng'
            ), 400));
        }

        // Convert radius from miles to degrees (approximate)
        $lat_delta = $radius / 69.0;
        $lng_delta = $radius / (69.0 * cos(deg2rad($lat)));

        $summary_table = $wpdb->prefix . 'bme_listing_summary';

        // Get nearby active listings
        $listings = $wpdb->get_results($wpdb->prepare(
            "SELECT listing_id, unparsed_address, city, state_or_province as state, postal_code as zip,
                    property_type, bedrooms_total as beds, bathrooms_total as baths,
                    list_price, main_photo_url as photo_url, latitude, longitude
             FROM {$summary_table}
             WHERE standard_status = 'Active'
               AND latitude BETWEEN %f AND %f
               AND longitude BETWEEN %f AND %f
             ORDER BY ABS(latitude - %f) + ABS(longitude - %f) ASC
             LIMIT 5",
            $lat - $lat_delta, $lat + $lat_delta,
            $lng - $lng_delta, $lng + $lng_delta,
            $lat, $lng
        ));

        $formatted = array_map(function($l) {
            return array(
                'listing_id' => $l->listing_id,
                'address' => $l->unparsed_address,
                'city' => $l->city,
                'state' => $l->state,
                'zip' => $l->zip,
                'property_type' => $l->property_type,
                'beds' => intval($l->beds),
                'baths' => floatval($l->baths),
                'list_price' => intval($l->list_price),
                'photo_url' => $l->photo_url,
                'latitude' => floatval($l->latitude),
                'longitude' => floatval($l->longitude)
            );
        }, $listings);

        return self::add_no_cache_headers(new WP_REST_Response(array(
            'success' => true,
            'data' => array(
                'properties' => $formatted,
                'count' => count($formatted)
            )
        ), 200));
    }

    /**
     * Format open house for API response
     */
    private static function format_open_house($oh) {
        return array(
            'id' => intval($oh->id),
            'agent_id' => intval($oh->agent_user_id),
            'listing_id' => $oh->listing_id,
            'property_address' => $oh->property_address,
            'property_city' => $oh->property_city,
            'property_state' => $oh->property_state,
            'property_zip' => $oh->property_zip,
            'property_type' => $oh->property_type,
            'beds' => $oh->beds ? intval($oh->beds) : null,
            'baths' => $oh->baths ? floatval($oh->baths) : null,
            'list_price' => $oh->list_price ? intval($oh->list_price) : null,
            'photo_url' => $oh->photo_url,
            'latitude' => $oh->latitude ? floatval($oh->latitude) : null,
            'longitude' => $oh->longitude ? floatval($oh->longitude) : null,
            'date' => $oh->event_date,
            'start_time' => substr($oh->start_time, 0, 5), // HH:mm
            'end_time' => substr($oh->end_time, 0, 5),
            'status' => $oh->status,
            'notes' => $oh->notes,
            'attendee_count' => isset($oh->attendee_count) ? intval($oh->attendee_count) : 0,
            'created_at' => $oh->created_at,
            'updated_at' => $oh->updated_at
        );
    }

    /**
     * Format attendee for API response
     */
    private static function format_attendee($a) {
        $formatted = array(
            'id' => intval($a->id),
            'local_uuid' => $a->local_uuid,
            'first_name' => $a->first_name,
            'last_name' => $a->last_name,
            'email' => $a->email,
            'phone' => $a->phone,
            // Agent visitor indicator (v6.70.0)
            'is_agent' => (bool)(isset($a->is_agent) ? $a->is_agent : 0),
            // Agent visitor fields (v6.70.0)
            'visitor_agent_brokerage' => isset($a->agent_brokerage) ? $a->agent_brokerage : null,
            'agent_visit_purpose' => isset($a->agent_visit_purpose) ? $a->agent_visit_purpose : null,
            'agent_has_buyer' => isset($a->agent_has_buyer) ? (bool)$a->agent_has_buyer : null,
            'agent_buyer_timeline' => isset($a->agent_buyer_timeline) ? $a->agent_buyer_timeline : null,
            'agent_network_interest' => isset($a->agent_network_interest) ? (bool)$a->agent_network_interest : null,
            // Buyer path fields
            'working_with_agent' => $a->working_with_agent,
            'agent_name' => $a->other_agent_name,
            'agent_brokerage' => $a->other_agent_brokerage,
            // v6.71.0: Enhanced agent contact fields
            'agent_phone' => isset($a->other_agent_phone) ? $a->other_agent_phone : null,
            'agent_email' => isset($a->other_agent_email) ? $a->other_agent_email : null,
            'buying_timeline' => $a->buying_timeline,
            'pre_approved' => $a->pre_approved,
            'lender_name' => $a->lender_name,
            'how_heard_about' => $a->how_heard_about,
            'consent_to_follow_up' => (bool)$a->consent_to_follow_up,
            'consent_to_email' => (bool)$a->consent_to_email,
            'consent_to_text' => (bool)$a->consent_to_text,
            // v6.71.0: Massachusetts disclosure
            'ma_disclosure_acknowledged' => isset($a->ma_disclosure_acknowledged) ? (bool)$a->ma_disclosure_acknowledged : false,
            'ma_disclosure_timestamp' => isset($a->ma_disclosure_timestamp) ? $a->ma_disclosure_timestamp : null,
            'interest_level' => $a->interest_level,
            'notes' => $a->agent_notes,
            // CRM fields (v6.70.0)
            'user_id' => isset($a->user_id) ? ($a->user_id ? intval($a->user_id) : null) : null,
            'priority_score' => isset($a->priority_score) ? intval($a->priority_score) : 0,
            'priority_tier' => self::get_priority_tier(isset($a->priority_score) ? intval($a->priority_score) : 0),
            // v6.71.0: Auto-processing flags
            'auto_crm_processed' => isset($a->auto_crm_processed) ? (bool)$a->auto_crm_processed : false,
            'auto_search_created' => isset($a->auto_search_created) ? (bool)$a->auto_search_created : false,
            'auto_search_id' => isset($a->auto_search_id) ? ($a->auto_search_id ? intval($a->auto_search_id) : null) : null,
            'signed_in_at' => $a->signed_in_at,
            'created_at' => $a->created_at
        );

        return $formatted;
    }

    /**
     * Calculate priority score for an attendee (v6.70.0)
     *
     * Scoring:
     * - Pre-approved: Yes = +25
     * - Timeline: 0-3 months = +20
     * - Working with agent: No = +15
     * - Interest: Very Interested = +25
     * - Consent to follow-up = +10
     * - Agent with buyer = +25
     *
     * Tiers: Hot (80-100), Warm (50-79), Cool (0-49)
     */
    private static function calculate_priority_score($data) {
        $score = 0;

        // Agent with buyer gets high priority
        if (!empty($data['is_agent']) && !empty($data['agent_has_buyer'])) {
            $score += 25;
        }

        // Pre-approval status
        if (isset($data['pre_approved']) && $data['pre_approved'] === 'yes') {
            $score += 25;
        }

        // Buying timeline
        if (isset($data['buying_timeline']) && $data['buying_timeline'] === '0_to_3_months') {
            $score += 20;
        } elseif (isset($data['buying_timeline']) && $data['buying_timeline'] === '3_to_6_months') {
            $score += 10;
        }

        // Not working with another agent
        if (isset($data['working_with_agent']) && $data['working_with_agent'] === 'no') {
            $score += 15;
        }

        // Interest level
        if (isset($data['interest_level']) && $data['interest_level'] === 'very_interested') {
            $score += 25;
        } elseif (isset($data['interest_level']) && $data['interest_level'] === 'somewhat') {
            $score += 10;
        }

        // Consent to follow-up
        if (!empty($data['consent_to_follow_up'])) {
            $score += 10;
        }

        return min(100, $score); // Cap at 100
    }

    /**
     * Get priority tier from score (v6.70.0)
     */
    private static function get_priority_tier($score) {
        if ($score >= 80) return 'hot';
        if ($score >= 50) return 'warm';
        return 'cool';
    }

    /**
     * Handle POST /open-houses/attendees/{attendee_id}/convert-to-client (v6.70.0)
     *
     * Convert an open house attendee to a CRM client
     */
    public static function handle_convert_to_client($request) {
        global $wpdb;
        $user_id = get_current_user_id();
        $attendee_id = intval($request->get_param('attendee_id'));

        $attendees_table = $wpdb->prefix . 'mld_open_house_attendees';
        $open_houses_table = $wpdb->prefix . 'mld_open_houses';

        // Get attendee and verify agent ownership
        $attendee = $wpdb->get_row($wpdb->prepare(
            "SELECT a.*, oh.agent_user_id
             FROM {$attendees_table} a
             JOIN {$open_houses_table} oh ON a.open_house_id = oh.id
             WHERE a.id = %d AND oh.agent_user_id = %d",
            $attendee_id, $user_id
        ));

        if (!$attendee) {
            return self::add_no_cache_headers(new WP_REST_Response(array(
                'success' => false,
                'message' => 'Attendee not found or access denied'
            ), 404));
        }

        // Check if already converted
        if ($attendee->user_id) {
            return self::add_no_cache_headers(new WP_REST_Response(array(
                'success' => false,
                'message' => 'Attendee already converted to client',
                'data' => array('user_id' => intval($attendee->user_id))
            ), 400));
        }

        // Check if email exists in WordPress
        $existing_user = get_user_by('email', $attendee->email);

        if ($existing_user) {
            // Check if user is already assigned to another agent
            $relationships_table = $wpdb->prefix . 'mld_agent_client_relationships';
            $existing_assignment = $wpdb->get_row($wpdb->prepare(
                "SELECT agent_id FROM {$relationships_table}
                 WHERE client_id = %d AND status = 'active'",
                $existing_user->ID
            ));

            if ($existing_assignment) {
                if ($existing_assignment->agent_id == $user_id) {
                    // Already this agent's client - just link
                    $wpdb->update(
                        $attendees_table,
                        array('user_id' => $existing_user->ID, 'updated_at' => current_time('mysql')),
                        array('id' => $attendee_id),
                        array('%d', '%s'),
                        array('%d')
                    );

                    return self::add_no_cache_headers(new WP_REST_Response(array(
                        'success' => true,
                        'data' => array(
                            'user_id' => $existing_user->ID,
                            'status' => 'linked_existing',
                            'message' => 'Attendee linked to your existing client'
                        )
                    ), 200));
                } else {
                    // Another agent's client - warning
                    return self::add_no_cache_headers(new WP_REST_Response(array(
                        'success' => false,
                        'message' => 'This email belongs to another agent\'s client',
                        'data' => array(
                            'user_id' => $existing_user->ID,
                            'conflict' => true
                        )
                    ), 409));
                }
            }

            // Exists but unassigned - assign to this agent
            $client_user_id = $existing_user->ID;
            $status = 'assigned_existing';

        } else {
            // Create new user
            $username = sanitize_user(strtolower($attendee->first_name . '.' . $attendee->last_name));
            $original_username = $username;
            $counter = 1;

            while (username_exists($username)) {
                $username = $original_username . $counter;
                $counter++;
            }

            $random_password = wp_generate_password(12, true);

            $client_user_id = wp_insert_user(array(
                'user_login' => $username,
                'user_email' => $attendee->email,
                'user_pass' => $random_password,
                'first_name' => $attendee->first_name,
                'last_name' => $attendee->last_name,
                'role' => 'subscriber',
                'display_name' => $attendee->first_name . ' ' . $attendee->last_name
            ));

            if (is_wp_error($client_user_id)) {
                return self::add_no_cache_headers(new WP_REST_Response(array(
                    'success' => false,
                    'message' => 'Failed to create user: ' . $client_user_id->get_error_message()
                ), 500));
            }

            // Store phone and lead source in user meta
            update_user_meta($client_user_id, 'phone', $attendee->phone);
            update_user_meta($client_user_id, 'mld_lead_source', 'open_house_manual_convert');
            update_user_meta($client_user_id, 'mld_lead_source_details', json_encode(array(
                'attendee_id' => $attendee_id,
                'open_house_id' => $attendee->open_house_id,
                'signed_in_at' => $attendee->signed_in_at
            )));

            // Send welcome email with password setup link
            wp_new_user_notification($client_user_id, null, 'user');

            $status = 'created_new';
        }

        // Create agent-client relationship if needed
        $relationships_table = $wpdb->prefix . 'mld_agent_client_relationships';
        $existing_relationship = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$relationships_table}
             WHERE agent_id = %d AND client_id = %d",
            $user_id, $client_user_id
        ));

        if (!$existing_relationship) {
            $wpdb->insert($relationships_table, array(
                'agent_id' => $user_id,
                'client_id' => $client_user_id,
                'status' => 'active',
                'notes' => "Converted from open house attendee #{$attendee_id}",
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ));
        }

        // Link attendee to user
        $wpdb->update(
            $attendees_table,
            array('user_id' => $client_user_id, 'updated_at' => current_time('mysql')),
            array('id' => $attendee_id),
            array('%d', '%s'),
            array('%d')
        );

        // Set user type to client (preserve existing created_at if record exists)
        $user_types_table = $wpdb->prefix . 'mld_user_types';
        $existing_type = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$user_types_table} WHERE user_id = %d",
            $client_user_id
        ));

        if ($existing_type) {
            $wpdb->update($user_types_table, array(
                'user_type' => 'client',
                'updated_at' => current_time('mysql')
            ), array('user_id' => $client_user_id));
        } else {
            $wpdb->insert($user_types_table, array(
                'user_id' => $client_user_id,
                'user_type' => 'client',
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ));
        }

        return self::add_no_cache_headers(new WP_REST_Response(array(
            'success' => true,
            'data' => array(
                'user_id' => $client_user_id,
                'status' => $status,
                'message' => $status === 'created_new'
                    ? 'New client account created and assigned'
                    : 'Existing user assigned as client'
            )
        ), 200));
    }

    /**
     * Handle GET /open-houses/attendees/{attendee_id}/crm-status (v6.70.0)
     *
     * Get CRM status for an attendee
     */
    public static function handle_get_crm_status($request) {
        global $wpdb;
        $user_id = get_current_user_id();
        $attendee_id = intval($request->get_param('attendee_id'));

        $attendees_table = $wpdb->prefix . 'mld_open_house_attendees';
        $open_houses_table = $wpdb->prefix . 'mld_open_houses';

        // Get attendee and verify agent ownership
        $attendee = $wpdb->get_row($wpdb->prepare(
            "SELECT a.*, oh.agent_user_id
             FROM {$attendees_table} a
             JOIN {$open_houses_table} oh ON a.open_house_id = oh.id
             WHERE a.id = %d AND oh.agent_user_id = %d",
            $attendee_id, $user_id
        ));

        if (!$attendee) {
            return self::add_no_cache_headers(new WP_REST_Response(array(
                'success' => false,
                'message' => 'Attendee not found or access denied'
            ), 404));
        }

        $crm_status = array(
            'is_converted' => !empty($attendee->user_id),
            'user_id' => $attendee->user_id ? intval($attendee->user_id) : null,
            'email_exists' => false,
            'is_my_client' => false,
            'is_other_agent_client' => false
        );

        // Check if email exists
        $existing_user = get_user_by('email', $attendee->email);
        if ($existing_user) {
            $crm_status['email_exists'] = true;

            // Check assignments
            $relationships_table = $wpdb->prefix . 'mld_agent_client_relationships';
            $assignment = $wpdb->get_row($wpdb->prepare(
                "SELECT agent_user_id FROM {$relationships_table}
                 WHERE client_user_id = %d AND status = 'active'",
                $existing_user->ID
            ));

            if ($assignment) {
                $crm_status['is_my_client'] = ($assignment->agent_user_id == $user_id);
                $crm_status['is_other_agent_client'] = ($assignment->agent_user_id != $user_id);
            }
        }

        return self::add_no_cache_headers(new WP_REST_Response(array(
            'success' => true,
            'data' => $crm_status
        ), 200));
    }

    /**
     * Handle GET /open-houses/attendees/{attendee_id}/history (v6.70.0)
     *
     * Get all open houses attended by this email
     */
    public static function handle_get_attendee_history($request) {
        global $wpdb;
        $user_id = get_current_user_id();
        $attendee_id = intval($request->get_param('attendee_id'));

        $attendees_table = $wpdb->prefix . 'mld_open_house_attendees';
        $open_houses_table = $wpdb->prefix . 'mld_open_houses';

        // Get attendee email and verify agent ownership
        $attendee = $wpdb->get_row($wpdb->prepare(
            "SELECT a.email, oh.agent_user_id
             FROM {$attendees_table} a
             JOIN {$open_houses_table} oh ON a.open_house_id = oh.id
             WHERE a.id = %d AND oh.agent_user_id = %d",
            $attendee_id, $user_id
        ));

        if (!$attendee) {
            return self::add_no_cache_headers(new WP_REST_Response(array(
                'success' => false,
                'message' => 'Attendee not found or access denied'
            ), 404));
        }

        // Get all open houses this email has attended (for this agent)
        $history = $wpdb->get_results($wpdb->prepare(
            "SELECT a.id as attendee_id, a.signed_in_at, a.interest_level,
                    oh.id as open_house_id, oh.property_address, oh.property_city,
                    oh.event_date, oh.list_price
             FROM {$attendees_table} a
             JOIN {$open_houses_table} oh ON a.open_house_id = oh.id
             WHERE a.email = %s AND oh.agent_user_id = %d
             ORDER BY a.signed_in_at DESC",
            $attendee->email, $user_id
        ));

        $formatted_history = array_map(function($h) {
            return array(
                'attendee_id' => intval($h->attendee_id),
                'open_house_id' => intval($h->open_house_id),
                'property_address' => $h->property_address,
                'property_city' => $h->property_city,
                'event_date' => $h->event_date,
                'list_price' => $h->list_price ? intval($h->list_price) : null,
                'signed_in_at' => $h->signed_in_at,
                'interest_level' => $h->interest_level
            );
        }, $history);

        return self::add_no_cache_headers(new WP_REST_Response(array(
            'success' => true,
            'data' => array(
                'email' => $attendee->email,
                'total_visits' => count($formatted_history),
                'history' => $formatted_history
            )
        ), 200));
    }

    /**
     * Handle GET /open-houses/{id}/property-images (v6.71.0)
     *
     * Returns property images for slideshow background in kiosk mode.
     * Uses the MLS listing_id from the open house to fetch images from wp_bme_media.
     *
     * @since 6.71.0
     * @param WP_REST_Request $request The request object
     * @return WP_REST_Response
     */
    public static function handle_get_property_images($request) {
        global $wpdb;
        $user_id = get_current_user_id();
        $open_house_id = intval($request->get_param('id'));

        $table = $wpdb->prefix . 'mld_open_houses';

        // Verify ownership and get listing_id
        $open_house = $wpdb->get_row($wpdb->prepare(
            "SELECT id, listing_id, photo_url FROM {$table} WHERE id = %d AND agent_user_id = %d",
            $open_house_id, $user_id
        ));

        if (!$open_house) {
            return self::add_no_cache_headers(new WP_REST_Response(array(
                'success' => false,
                'message' => 'Open house not found'
            ), 404));
        }

        $images = array();
        $image_source = 'none';

        // v6.72.0: Diagnostic logging
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("MLD_Open_House_REST_API: property-images for OH#{$open_house_id}, listing_id=" . ($open_house->listing_id ?: 'NULL'));
        }

        // If open house has an MLS listing_id, fetch photos from bme_media
        if (!empty($open_house->listing_id)) {
            $media_table = $wpdb->prefix . 'bme_media';
            $images = $wpdb->get_col($wpdb->prepare(
                "SELECT media_url FROM {$media_table}
                 WHERE listing_id = %s AND media_category = 'Photo'
                 ORDER BY order_index ASC
                 LIMIT 10",
                $open_house->listing_id
            ));

            if (!empty($images)) {
                $image_source = 'bme_media';
            } else {
                // v6.72.0: Fallback to listing summary main photo
                $summary_table = $wpdb->prefix . 'bme_listing_summary';
                $main_photo = $wpdb->get_var($wpdb->prepare(
                    "SELECT main_photo_url FROM {$summary_table} WHERE listing_id = %s",
                    $open_house->listing_id
                ));
                if (!empty($main_photo)) {
                    $images = array($main_photo);
                    $image_source = 'bme_listing_summary';
                }
            }
        }

        // Fallback to open house photo_url if no MLS images found
        if (empty($images) && !empty($open_house->photo_url)) {
            $images = array($open_house->photo_url);
            $image_source = 'open_house_record';
        }

        // v6.72.0: Enhanced diagnostic logging
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("MLD_Open_House_REST_API: property-images result - count=" . count($images) . ", source={$image_source}");
        }

        return self::add_no_cache_headers(new WP_REST_Response(array(
            'success' => true,
            'data' => array(
                'images' => $images,
                'count' => count($images),
                'listing_id' => $open_house->listing_id,
                'source' => $image_source  // v6.72.0: Helps with debugging
            )
        ), 200));
    }

    /**
     * Escape value for CSV
     */
    private static function csv_escape($value) {
        if (strpos($value, ',') !== false || strpos($value, '"') !== false || strpos($value, "\n") !== false) {
            return '"' . str_replace('"', '""', $value) . '"';
        }
        return $value;
    }
}
