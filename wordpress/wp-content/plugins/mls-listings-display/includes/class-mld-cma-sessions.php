<?php
/**
 * MLS Listings Display - CMA Sessions Core Class
 *
 * Handles CRUD operations for saved CMA sessions
 *
 * @package MLS_Listings_Display
 * @subpackage CMA
 * @since 6.16.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_CMA_Sessions {

    /**
     * Create a new CMA session
     *
     * @param array $data Session data
     * @return int|WP_Error Session ID on success, WP_Error on failure
     */
    public static function save_session($data) {
        global $wpdb;

        // Validate required fields
        if (empty($data['user_id']) || empty($data['session_name']) || empty($data['subject_listing_id'])) {
            return new WP_Error('missing_fields', 'Required fields are missing (user_id, session_name, subject_listing_id)');
        }

        // Prepare data for insertion
        $insert_data = array(
            'user_id' => absint($data['user_id']),
            'session_name' => sanitize_text_field($data['session_name']),
            'description' => isset($data['description']) ? sanitize_textarea_field($data['description']) : '',
            'is_favorite' => isset($data['is_favorite']) ? absint($data['is_favorite']) : 0,
            'subject_listing_id' => sanitize_text_field($data['subject_listing_id']),
            'subject_property_data' => wp_json_encode($data['subject_property_data'] ?? array()),
            'subject_overrides' => wp_json_encode($data['subject_overrides'] ?? null),
            'cma_filters' => wp_json_encode($data['cma_filters'] ?? array()),
            'comparables_data' => wp_json_encode($data['comparables_data'] ?? array()),
            'summary_statistics' => wp_json_encode($data['summary_statistics'] ?? array()),
            'comparables_count' => absint($data['comparables_count'] ?? 0),
            'estimated_value_mid' => floatval($data['estimated_value_mid'] ?? 0),
            'pdf_path' => isset($data['pdf_path']) ? sanitize_text_field($data['pdf_path']) : null,
            'pdf_generated_at' => isset($data['pdf_generated_at']) ? $data['pdf_generated_at'] : null,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        );

        $table_name = MLD_CMA_Session_Database::get_table_name();

        $result = $wpdb->insert($table_name, $insert_data);

        if ($result === false) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[MLD CMA Sessions] Insert failed: ' . $wpdb->last_error);
            }
            return new WP_Error('db_error', 'Failed to save CMA session: ' . $wpdb->last_error);
        }

        $session_id = $wpdb->insert_id;

        // Record in CMA value history (v6.20.0)
        self::record_value_history($session_id, $data, $insert_data);

        // Trigger action for other components
        do_action('mld_cma_session_saved', $session_id, $insert_data);

        return $session_id;
    }

    /**
     * Record CMA valuation in history table
     *
     * @since 6.20.0
     * @param int $session_id The session ID
     * @param array $original_data Original request data
     * @param array $insert_data Processed insert data
     */
    private static function record_value_history($session_id, $original_data, $insert_data) {
        global $wpdb;

        // Only record if we have valuation data
        $summary = $original_data['summary_statistics'] ?? array();
        $subject = $original_data['subject_property_data'] ?? array();

        if (empty($summary) || empty($subject)) {
            return;
        }

        $estimated = $summary['estimated_value'] ?? array();
        $listing_id = $original_data['subject_listing_id'] ?? '';

        // Get property address from multiple sources
        $property_address = $subject['address'] ?? ($subject['unparsed_address'] ?? '');
        $property_city = $subject['city'] ?? '';
        $property_state = $subject['state'] ?? '';
        $property_zip = $subject['postal_code'] ?? ($subject['zip'] ?? '');

        // If address is empty and we have a listing_id, get it from the database
        if (empty($property_address) && !empty($listing_id) && strpos($listing_id, 'STANDALONE') === false) {
            // Try the summary table first (has more address components)
            $listing_data = $wpdb->get_row($wpdb->prepare(
                "SELECT street_number, street_name, city, state_or_province, postal_code
                 FROM {$wpdb->prefix}bme_listing_summary
                 WHERE listing_id = %s",
                $listing_id
            ));

            if ($listing_data) {
                $property_address = trim($listing_data->street_number . ' ' . $listing_data->street_name);
                $property_city = $property_city ?: $listing_data->city;
                $property_state = $property_state ?: $listing_data->state_or_province;
                $property_zip = $property_zip ?: $listing_data->postal_code;
            }
        }

        // Fallback: construct address from city/state if still empty
        if (empty($property_address) && !empty($property_city)) {
            $property_address = $property_city . ', ' . $property_state;
        }

        // Build history record
        $history_data = array(
            'property_address'     => $property_address,
            'property_city'        => $property_city,
            'property_state'       => $property_state,
            'property_zip'         => $property_zip,
            'listing_id'           => $listing_id,
            'session_id'           => $session_id,
            'user_id'              => $insert_data['user_id'],
            'estimated_value_low'  => $estimated['low'] ?? 0,
            'estimated_value_mid'  => $estimated['mid'] ?? 0,
            'estimated_value_high' => $estimated['high'] ?? 0,
            'weighted_value_mid'   => $estimated['mid_weighted'] ?? ($estimated['mid'] ?? 0),
            'comparables_count'    => $summary['total_found'] ?? 0,
            'top_comps_count'      => $summary['top_comps_count'] ?? 0,
            'confidence_score'     => $estimated['confidence_score'] ?? 0,
            'confidence_level'     => $estimated['confidence'] ?? '',
            'avg_price_per_sqft'   => $summary['price_per_sqft']['avg'] ?? 0,
            'filters_used'         => $original_data['cma_filters'] ?? array(),
            'is_arv_mode'          => !empty($original_data['subject_overrides']),
            'arv_overrides'        => $original_data['subject_overrides'] ?? null,
            'notes'                => ''
        );

        // Use CMA History class to record
        if (class_exists('MLD_CMA_History')) {
            $history = new MLD_CMA_History();
            $history_id = $history->record_valuation($history_data);

            if (defined('WP_DEBUG') && WP_DEBUG) {
                if ($history_id) {
                    error_log("[MLD CMA Sessions] Recorded value history ID {$history_id} for session {$session_id}");
                } else {
                    error_log("[MLD CMA Sessions] Failed to record value history for session {$session_id}");
                }
            }
        }
    }

    /**
     * Update an existing CMA session
     *
     * @param int $session_id Session ID
     * @param array $data Updated data
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public static function update_session($session_id, $data) {
        global $wpdb;

        $session_id = absint($session_id);

        // Check if session exists and user owns it
        $existing = self::get_session($session_id);
        if (!$existing) {
            return new WP_Error('not_found', 'CMA session not found');
        }

        // Prepare update data
        $update_data = array(
            'updated_at' => current_time('mysql'),
        );

        if (isset($data['session_name'])) {
            $update_data['session_name'] = sanitize_text_field($data['session_name']);
        }

        if (isset($data['description'])) {
            $update_data['description'] = sanitize_textarea_field($data['description']);
        }

        if (isset($data['is_favorite'])) {
            $update_data['is_favorite'] = absint($data['is_favorite']);
        }

        if (isset($data['subject_property_data'])) {
            $update_data['subject_property_data'] = wp_json_encode($data['subject_property_data']);
        }

        if (isset($data['subject_overrides'])) {
            $update_data['subject_overrides'] = wp_json_encode($data['subject_overrides']);
        }

        if (isset($data['cma_filters'])) {
            $update_data['cma_filters'] = wp_json_encode($data['cma_filters']);
        }

        if (isset($data['comparables_data'])) {
            $update_data['comparables_data'] = wp_json_encode($data['comparables_data']);
        }

        if (isset($data['summary_statistics'])) {
            $update_data['summary_statistics'] = wp_json_encode($data['summary_statistics']);
        }

        if (isset($data['comparables_count'])) {
            $update_data['comparables_count'] = absint($data['comparables_count']);
        }

        if (isset($data['estimated_value_mid'])) {
            $update_data['estimated_value_mid'] = floatval($data['estimated_value_mid']);
        }

        if (isset($data['pdf_path'])) {
            $update_data['pdf_path'] = sanitize_text_field($data['pdf_path']);
            $update_data['pdf_generated_at'] = current_time('mysql');
        }

        $table_name = MLD_CMA_Session_Database::get_table_name();
        $result = $wpdb->update(
            $table_name,
            $update_data,
            array('id' => $session_id)
        );

        if ($result === false) {
            return new WP_Error('db_error', 'Failed to update CMA session');
        }

        do_action('mld_cma_session_updated', $session_id, $update_data);

        return true;
    }

    /**
     * Delete a CMA session
     *
     * @param int $session_id Session ID
     * @param int $user_id User ID (for ownership verification)
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public static function delete_session($session_id, $user_id = null) {
        global $wpdb;

        $session_id = absint($session_id);

        // Get session to verify ownership
        $session = self::get_session($session_id);
        if (!$session) {
            return new WP_Error('not_found', 'CMA session not found');
        }

        // Verify ownership if user_id provided
        if ($user_id !== null && absint($session['user_id']) !== absint($user_id)) {
            return new WP_Error('unauthorized', 'You do not have permission to delete this CMA session');
        }

        // Delete associated PDF file if exists
        if (!empty($session['pdf_path'])) {
            $upload_dir = wp_upload_dir();
            $pdf_full_path = $upload_dir['basedir'] . '/' . $session['pdf_path'];
            if (file_exists($pdf_full_path)) {
                unlink($pdf_full_path);
            }
        }

        $table_name = MLD_CMA_Session_Database::get_table_name();
        $result = $wpdb->delete($table_name, array('id' => $session_id));

        if ($result === false) {
            return new WP_Error('db_error', 'Failed to delete CMA session');
        }

        do_action('mld_cma_session_deleted', $session_id);

        return true;
    }

    /**
     * Get a single CMA session
     *
     * @param int $session_id Session ID
     * @return array|null Session data or null if not found
     */
    public static function get_session($session_id) {
        global $wpdb;

        $session_id = absint($session_id);
        $table_name = MLD_CMA_Session_Database::get_table_name();

        $session = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $session_id),
            ARRAY_A
        );

        if ($session) {
            // Decode JSON fields
            $session['subject_property_data'] = json_decode($session['subject_property_data'], true);
            $session['subject_overrides'] = json_decode($session['subject_overrides'], true);
            $session['cma_filters'] = json_decode($session['cma_filters'], true);
            $session['comparables_data'] = json_decode($session['comparables_data'], true);
            $session['summary_statistics'] = json_decode($session['summary_statistics'], true);
        }

        return $session;
    }

    /**
     * Get all CMA sessions for a user
     *
     * @param int $user_id User ID
     * @param array $args Optional arguments (limit, offset, order_by, order)
     * @return array Array of sessions
     */
    public static function get_user_sessions($user_id, $args = array()) {
        global $wpdb;

        $user_id = absint($user_id);
        $table_name = MLD_CMA_Session_Database::get_table_name();

        // Default args
        $defaults = array(
            'limit' => 50,
            'offset' => 0,
            'order_by' => 'created_at',
            'order' => 'DESC',
            'favorites_first' => true,
        );
        $args = wp_parse_args($args, $defaults);

        // Sanitize order_by to prevent SQL injection
        $allowed_order_by = array('created_at', 'updated_at', 'session_name', 'is_favorite', 'estimated_value_mid');
        if (!in_array($args['order_by'], $allowed_order_by)) {
            $args['order_by'] = 'created_at';
        }

        $order = strtoupper($args['order']) === 'ASC' ? 'ASC' : 'DESC';

        // Build order clause
        $order_clause = '';
        if ($args['favorites_first']) {
            $order_clause = "ORDER BY is_favorite DESC, {$args['order_by']} $order";
        } else {
            $order_clause = "ORDER BY {$args['order_by']} $order";
        }

        $query = $wpdb->prepare(
            "SELECT id, user_id, session_name, description, is_favorite, is_standalone, standalone_slug,
                    subject_listing_id, comparables_count, estimated_value_mid, pdf_path, created_at, updated_at
             FROM $table_name
             WHERE user_id = %d
             $order_clause
             LIMIT %d OFFSET %d",
            $user_id,
            absint($args['limit']),
            absint($args['offset'])
        );

        $sessions = $wpdb->get_results($query, ARRAY_A);

        return $sessions ?: array();
    }

    /**
     * Get count of user's sessions
     *
     * @param int $user_id User ID
     * @return int Count of sessions
     */
    public static function get_user_session_count($user_id) {
        global $wpdb;

        $user_id = absint($user_id);
        $table_name = MLD_CMA_Session_Database::get_table_name();

        return (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM $table_name WHERE user_id = %d", $user_id)
        );
    }

    /**
     * Toggle favorite status
     *
     * @param int $session_id Session ID
     * @param int $user_id User ID for ownership verification
     * @return bool|WP_Error New favorite status or error
     */
    public static function toggle_favorite($session_id, $user_id) {
        global $wpdb;

        $session = self::get_session($session_id);
        if (!$session) {
            return new WP_Error('not_found', 'CMA session not found');
        }

        if (absint($session['user_id']) !== absint($user_id)) {
            return new WP_Error('unauthorized', 'You do not have permission to modify this CMA session');
        }

        $new_status = $session['is_favorite'] ? 0 : 1;

        $table_name = MLD_CMA_Session_Database::get_table_name();
        $result = $wpdb->update(
            $table_name,
            array(
                'is_favorite' => $new_status,
                'updated_at' => current_time('mysql'),
            ),
            array('id' => $session_id)
        );

        if ($result === false) {
            return new WP_Error('db_error', 'Failed to update favorite status');
        }

        return (bool) $new_status;
    }

    /**
     * Check if user owns a session
     *
     * @param int $session_id Session ID
     * @param int $user_id User ID
     * @return bool True if user owns the session
     */
    public static function user_owns_session($session_id, $user_id) {
        global $wpdb;

        $table_name = MLD_CMA_Session_Database::get_table_name();

        $owner_id = $wpdb->get_var(
            $wpdb->prepare("SELECT user_id FROM $table_name WHERE id = %d", $session_id)
        );

        return $owner_id !== null && absint($owner_id) === absint($user_id);
    }

    // ============================================
    // STANDALONE CMA METHODS (Added in 6.17.0)
    // ============================================

    /**
     * Save a standalone CMA session (no MLS listing required)
     *
     * @param array $data Session data including subject property details
     * @return array|WP_Error Array with session_id and slug on success, WP_Error on failure
     */
    public static function save_standalone_session($data) {
        global $wpdb;

        // Validate required fields for standalone CMA
        $required = array('address', 'lat', 'lng', 'city', 'beds', 'baths', 'sqft', 'property_type', 'price');
        foreach ($required as $field) {
            if (empty($data['subject_property_data'][$field]) && $data['subject_property_data'][$field] !== 0) {
                return new WP_Error('missing_fields', "Required field missing: {$field}");
            }
        }

        // Validate coordinates
        $lat = floatval($data['subject_property_data']['lat']);
        $lng = floatval($data['subject_property_data']['lng']);
        if ($lat == 0 || $lng == 0) {
            return new WP_Error('invalid_coordinates', 'Valid coordinates (lat/lng) are required');
        }

        // Generate unique slug from address
        $address = $data['subject_property_data']['address'];
        $city = $data['subject_property_data']['city'];
        $slug = self::generate_unique_slug($address, $city);

        // Generate a unique subject_listing_id for standalone CMAs
        $subject_listing_id = 'STANDALONE-' . strtoupper(substr(md5($slug . time()), 0, 8));

        // Prepare session name
        $session_name = !empty($data['session_name'])
            ? sanitize_text_field($data['session_name'])
            : $address . ' - Standalone CMA';

        // Prepare data for insertion
        $insert_data = array(
            'user_id' => absint($data['user_id'] ?? 0), // 0 for anonymous
            'session_name' => $session_name,
            'description' => isset($data['description']) ? sanitize_textarea_field($data['description']) : '',
            'is_favorite' => 0,
            'is_standalone' => 1,
            'standalone_slug' => $slug,
            'subject_listing_id' => $subject_listing_id,
            'subject_property_data' => wp_json_encode($data['subject_property_data']),
            'subject_overrides' => wp_json_encode(null),
            'cma_filters' => wp_json_encode($data['cma_filters'] ?? array()),
            'comparables_data' => wp_json_encode(array()),
            'summary_statistics' => wp_json_encode(array()),
            'comparables_count' => 0,
            'estimated_value_mid' => floatval($data['subject_property_data']['price'] ?? 0),
            'pdf_path' => null,
            'pdf_generated_at' => null,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        );

        $table_name = MLD_CMA_Session_Database::get_table_name();

        $result = $wpdb->insert($table_name, $insert_data);

        if ($result === false) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[MLD CMA Sessions] Standalone insert failed: ' . $wpdb->last_error);
            }
            return new WP_Error('db_error', 'Failed to create standalone CMA: ' . $wpdb->last_error);
        }

        $session_id = $wpdb->insert_id;

        do_action('mld_standalone_cma_created', $session_id, $insert_data);

        return array(
            'session_id' => $session_id,
            'slug' => $slug,
            'subject_listing_id' => $subject_listing_id
        );
    }

    /**
     * Get a session by its standalone slug
     *
     * @param string $slug The URL slug
     * @return array|null Session data or null if not found
     */
    public static function get_session_by_slug($slug) {
        global $wpdb;

        $slug = sanitize_title($slug);
        $table_name = MLD_CMA_Session_Database::get_table_name();

        $session = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM $table_name WHERE standalone_slug = %s AND is_standalone = 1",
                $slug
            ),
            ARRAY_A
        );

        if ($session) {
            // Decode JSON fields
            $session['subject_property_data'] = json_decode($session['subject_property_data'], true);
            $session['subject_overrides'] = json_decode($session['subject_overrides'], true);
            $session['cma_filters'] = json_decode($session['cma_filters'], true);
            $session['comparables_data'] = json_decode($session['comparables_data'], true);
            $session['summary_statistics'] = json_decode($session['summary_statistics'], true);
        }

        return $session;
    }

    /**
     * Claim an anonymous standalone CMA for a user
     *
     * @param int $session_id Session ID
     * @param int $user_id User ID to claim the session
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public static function claim_session($session_id, $user_id) {
        global $wpdb;

        $session_id = absint($session_id);
        $user_id = absint($user_id);

        $session = self::get_session($session_id);
        if (!$session) {
            return new WP_Error('not_found', 'CMA session not found');
        }

        // Only allow claiming anonymous sessions (user_id = 0)
        if (absint($session['user_id']) !== 0) {
            return new WP_Error('already_claimed', 'This CMA session is already owned by a user');
        }

        $table_name = MLD_CMA_Session_Database::get_table_name();
        $result = $wpdb->update(
            $table_name,
            array(
                'user_id' => $user_id,
                'updated_at' => current_time('mysql'),
            ),
            array('id' => $session_id)
        );

        if ($result === false) {
            return new WP_Error('db_error', 'Failed to claim CMA session');
        }

        do_action('mld_cma_session_claimed', $session_id, $user_id);

        return true;
    }

    /**
     * Generate a unique URL slug from address and city
     *
     * @param string $address Street address
     * @param string $city City name
     * @return string Unique slug
     */
    public static function generate_unique_slug($address, $city) {
        global $wpdb;

        // Create base slug from address and city
        $base_slug = sanitize_title($address . ' ' . $city);

        // Limit length
        if (strlen($base_slug) > 100) {
            $base_slug = substr($base_slug, 0, 100);
        }

        $table_name = MLD_CMA_Session_Database::get_table_name();
        $slug = $base_slug;
        $counter = 1;

        // Check for duplicates and append number if needed
        while (true) {
            $exists = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM $table_name WHERE standalone_slug = %s",
                    $slug
                )
            );

            if (!$exists) {
                break;
            }

            $counter++;
            $slug = $base_slug . '-' . $counter;
        }

        return $slug;
    }

    /**
     * Get all CMA sessions (for admin) with filtering and pagination
     *
     * @param array $args Filter arguments
     * @return array Array of sessions
     */
    public static function get_all_sessions($args = array()) {
        global $wpdb;

        $table_name = MLD_CMA_Session_Database::get_table_name();

        // Default args
        $defaults = array(
            'limit' => 50,
            'offset' => 0,
            'order_by' => 'created_at',
            'order' => 'DESC',
            'type' => 'all', // 'all', 'property', 'standalone'
            'user_id' => null,
            'search' => '',
            'date_from' => null,
            'date_to' => null,
        );
        $args = wp_parse_args($args, $defaults);

        // Build WHERE clause
        $where_clauses = array('1=1');
        $where_values = array();

        // Filter by type
        if ($args['type'] === 'standalone') {
            $where_clauses[] = 'is_standalone = 1';
        } elseif ($args['type'] === 'property') {
            $where_clauses[] = 'is_standalone = 0';
        }

        // Filter by user
        if ($args['user_id'] !== null) {
            $where_clauses[] = 'user_id = %d';
            $where_values[] = absint($args['user_id']);
        }

        // Search
        if (!empty($args['search'])) {
            $search_term = '%' . $wpdb->esc_like($args['search']) . '%';
            $where_clauses[] = '(session_name LIKE %s OR subject_listing_id LIKE %s OR standalone_slug LIKE %s)';
            $where_values[] = $search_term;
            $where_values[] = $search_term;
            $where_values[] = $search_term;
        }

        // Date range
        if (!empty($args['date_from'])) {
            $where_clauses[] = 'created_at >= %s';
            $where_values[] = $args['date_from'];
        }

        if (!empty($args['date_to'])) {
            $where_clauses[] = 'created_at <= %s';
            $where_values[] = $args['date_to'];
        }

        $where_sql = implode(' AND ', $where_clauses);

        // Sanitize order_by
        $allowed_order_by = array('id', 'created_at', 'updated_at', 'session_name', 'user_id', 'estimated_value_mid', 'comparables_count');
        if (!in_array($args['order_by'], $allowed_order_by)) {
            $args['order_by'] = 'created_at';
        }

        $order = strtoupper($args['order']) === 'ASC' ? 'ASC' : 'DESC';

        // Build query
        $query = "SELECT s.*, u.display_name as user_display_name, u.user_email
                  FROM $table_name s
                  LEFT JOIN {$wpdb->users} u ON s.user_id = u.ID
                  WHERE $where_sql
                  ORDER BY s.{$args['order_by']} $order
                  LIMIT %d OFFSET %d";

        $where_values[] = absint($args['limit']);
        $where_values[] = absint($args['offset']);

        $prepared_query = $wpdb->prepare($query, $where_values);
        $sessions = $wpdb->get_results($prepared_query, ARRAY_A);

        return $sessions ?: array();
    }

    /**
     * Get count of all sessions with filtering (for admin pagination)
     *
     * @param array $args Filter arguments (same as get_all_sessions)
     * @return int Count of sessions
     */
    public static function get_all_sessions_count($args = array()) {
        global $wpdb;

        $table_name = MLD_CMA_Session_Database::get_table_name();

        // Default args
        $defaults = array(
            'type' => 'all',
            'user_id' => null,
            'search' => '',
            'date_from' => null,
            'date_to' => null,
        );
        $args = wp_parse_args($args, $defaults);

        // Build WHERE clause (same logic as get_all_sessions)
        $where_clauses = array('1=1');
        $where_values = array();

        if ($args['type'] === 'standalone') {
            $where_clauses[] = 'is_standalone = 1';
        } elseif ($args['type'] === 'property') {
            $where_clauses[] = 'is_standalone = 0';
        }

        if ($args['user_id'] !== null) {
            $where_clauses[] = 'user_id = %d';
            $where_values[] = absint($args['user_id']);
        }

        if (!empty($args['search'])) {
            $search_term = '%' . $wpdb->esc_like($args['search']) . '%';
            $where_clauses[] = '(session_name LIKE %s OR subject_listing_id LIKE %s OR standalone_slug LIKE %s)';
            $where_values[] = $search_term;
            $where_values[] = $search_term;
            $where_values[] = $search_term;
        }

        if (!empty($args['date_from'])) {
            $where_clauses[] = 'created_at >= %s';
            $where_values[] = $args['date_from'];
        }

        if (!empty($args['date_to'])) {
            $where_clauses[] = 'created_at <= %s';
            $where_values[] = $args['date_to'];
        }

        $where_sql = implode(' AND ', $where_clauses);

        if (!empty($where_values)) {
            $count = $wpdb->get_var(
                $wpdb->prepare("SELECT COUNT(*) FROM $table_name WHERE $where_sql", $where_values)
            );
        } else {
            $count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE $where_sql");
        }

        return (int) $count;
    }

    /**
     * Assign a session to a user (admin action)
     *
     * @param int $session_id Session ID
     * @param int $user_id User ID to assign
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public static function assign_session_to_user($session_id, $user_id) {
        global $wpdb;

        $session_id = absint($session_id);
        $user_id = absint($user_id);

        // Verify user exists
        if (!get_user_by('id', $user_id)) {
            return new WP_Error('invalid_user', 'User not found');
        }

        $session = self::get_session($session_id);
        if (!$session) {
            return new WP_Error('not_found', 'CMA session not found');
        }

        $table_name = MLD_CMA_Session_Database::get_table_name();
        $result = $wpdb->update(
            $table_name,
            array(
                'user_id' => $user_id,
                'updated_at' => current_time('mysql'),
            ),
            array('id' => $session_id)
        );

        if ($result === false) {
            return new WP_Error('db_error', 'Failed to assign session to user');
        }

        do_action('mld_cma_session_assigned', $session_id, $user_id);

        return true;
    }
}
