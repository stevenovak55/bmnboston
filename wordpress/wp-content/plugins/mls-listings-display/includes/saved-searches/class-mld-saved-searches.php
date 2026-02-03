<?php
/**
 * MLS Listings Display - Saved Searches Core Class
 * 
 * Handles CRUD operations for saved searches
 * 
 * @package MLS_Listings_Display
 * @subpackage Saved_Searches
 * @since 3.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Saved_Searches {
    
    /**
     * Create a new saved search
     *
     * @param array $data Search data
     * @return int|WP_Error Search ID on success, WP_Error on failure
     */
    public static function create_search($data) {
        global $wpdb;
        
        // Debug logging
        MLD_Logger::debug('Creating new saved search', $data);
        
        // Validate required fields
        if (empty($data['user_id']) || empty($data['name']) || empty($data['filters'])) {
            MLD_Logger::error('Missing required fields for saved search creation', [
                'provided_fields' => array_keys($data)
            ]);
            return new WP_Error('missing_fields', 'Required fields are missing');
        }

        // Normalize filters for cross-platform consistency
        $data['filters'] = self::normalize_filters($data['filters']);

        // Prepare data for insertion
        $insert_data = [
            'user_id' => absint($data['user_id']),
            'name' => sanitize_text_field($data['name']),
            'description' => isset($data['description']) ? sanitize_textarea_field($data['description']) : '',
            'filters' => is_array($data['filters']) ? wp_json_encode($data['filters']) : $data['filters'],
            'polygon_shapes' => isset($data['polygon_shapes']) ? wp_json_encode($data['polygon_shapes']) : null,
            'search_url' => isset($data['search_url']) ? esc_url_raw($data['search_url']) : '',
            'notification_frequency' => isset($data['notification_frequency']) ? $data['notification_frequency'] : 'instant',
            'is_active' => isset($data['is_active']) ? (bool)$data['is_active'] : true,
            'exclude_disliked' => isset($data['exclude_disliked']) ? (bool)$data['exclude_disliked'] : true,
            'created_by_admin' => isset($data['created_by_admin']) ? absint($data['created_by_admin']) : null
        ];
        
        // Insert the search
        $table_name = MLD_Saved_Search_Database::get_table_name('saved_searches');
        MLD_Logger::debug('Inserting saved search into database', [
            'table' => $table_name,
            'insert_data' => $insert_data
        ]);
        
        $result = $wpdb->insert($table_name, $insert_data);
        
        if ($result === false) {
            MLD_Logger::error('Database insert failed for saved search', [
                'error' => $wpdb->last_error,
                'table' => $table_name
            ]);
            return new WP_Error('db_error', 'Failed to save search: ' . $wpdb->last_error);
        }
        
        $search_id = $wpdb->insert_id;
        
        // Trigger action for other components
        do_action('mld_saved_search_created', $search_id, $insert_data);
        
        return $search_id;
    }
    
    /**
     * Update an existing saved search
     *
     * @param int $search_id Search ID
     * @param array $data Updated data
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public static function update_search($search_id, $data) {
        global $wpdb;
        
        $search_id = absint($search_id);
        
        // Check if search exists
        $existing = self::get_search($search_id);
        if (!$existing) {
            return new WP_Error('not_found', 'Search not found');
        }
        
        // Prepare update data
        $update_data = [];
        
        if (isset($data['name'])) {
            $update_data['name'] = sanitize_text_field($data['name']);
        }
        
        if (isset($data['description'])) {
            $update_data['description'] = sanitize_textarea_field($data['description']);
        }
        
        if (isset($data['filters'])) {
            $update_data['filters'] = is_array($data['filters']) ? wp_json_encode($data['filters']) : $data['filters'];
        }
        
        if (isset($data['polygon_shapes'])) {
            $update_data['polygon_shapes'] = wp_json_encode($data['polygon_shapes']);
        }
        
        if (isset($data['search_url'])) {
            $update_data['search_url'] = esc_url_raw($data['search_url']);
        }
        
        if (isset($data['notification_frequency'])) {
            $update_data['notification_frequency'] = $data['notification_frequency'];
        }
        
        if (isset($data['is_active'])) {
            $update_data['is_active'] = (bool)$data['is_active'];
        }
        
        if (isset($data['exclude_disliked'])) {
            $update_data['exclude_disliked'] = (bool)$data['exclude_disliked'];
        }
        
        // Update the search
        $table_name = MLD_Saved_Search_Database::get_table_name('saved_searches');
        $result = $wpdb->update(
            $table_name,
            $update_data,
            ['id' => $search_id]
        );
        
        if ($result === false) {
            return new WP_Error('db_error', 'Failed to update search');
        }
        
        // Trigger action for other components
        do_action('mld_saved_search_updated', $search_id, $update_data);
        
        return true;
    }
    
    /**
     * Delete a saved search
     *
     * @param int $search_id Search ID
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public static function delete_search($search_id) {
        global $wpdb;
        
        $search_id = absint($search_id);
        
        // Check if search exists
        $existing = self::get_search($search_id);
        if (!$existing) {
            return new WP_Error('not_found', 'Search not found');
        }
        
        // Delete the search
        $table_name = MLD_Saved_Search_Database::get_table_name('saved_searches');
        $result = $wpdb->delete($table_name, ['id' => $search_id]);
        
        if ($result === false) {
            return new WP_Error('db_error', 'Failed to delete search');
        }
        
        // Trigger action for other components
        do_action('mld_saved_search_deleted', $search_id);
        
        return true;
    }
    
    /**
     * Get a single saved search
     *
     * @param int $search_id Search ID
     * @return object|null Search object or null if not found
     */
    public static function get_search($search_id) {
        global $wpdb;
        
        $search_id = absint($search_id);
        $table_name = MLD_Saved_Search_Database::get_table_name('saved_searches');
        
        $search = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE id = %d",
            $search_id
        ));
        
        if ($search) {
            // Decode JSON fields
            $search->filters = json_decode($search->filters, true);
            $search->polygon_shapes = $search->polygon_shapes ? json_decode($search->polygon_shapes, true) : null;
        }
        
        return $search;
    }
    
    /**
     * Get searches for a user
     *
     * @param int $user_id User ID
     * @param array $args Query arguments
     * @return array Array of search objects
     */
    public static function get_user_searches($user_id, $args = []) {
        global $wpdb;
        
        $user_id = absint($user_id);
        $table_name = MLD_Saved_Search_Database::get_table_name('saved_searches');
        
        $defaults = [
            'status' => 'all', // all, active, inactive
            'orderby' => 'created_at',
            'order' => 'DESC',
            'limit' => -1,
            'offset' => 0
        ];
        
        $args = wp_parse_args($args, $defaults);
        
        // Build query
        $query = "SELECT * FROM $table_name WHERE user_id = %d";
        $query_args = [$user_id];
        
        // Add status filter
        if ($args['status'] === 'active') {
            $query .= " AND is_active = 1";
        } elseif ($args['status'] === 'inactive') {
            $query .= " AND is_active = 0";
        }
        
        // Add ordering
        $allowed_orderby = ['created_at', 'updated_at', 'name', 'last_notified_at'];
        if (in_array($args['orderby'], $allowed_orderby)) {
            $order = $args['order'] === 'ASC' ? 'ASC' : 'DESC';
            $query .= " ORDER BY {$args['orderby']} $order";
        }
        
        // Add limit
        if ($args['limit'] > 0) {
            $query .= " LIMIT %d OFFSET %d";
            $query_args[] = $args['limit'];
            $query_args[] = $args['offset'];
        }
        
        // Execute query
        $searches = $wpdb->get_results($wpdb->prepare($query, $query_args));
        
        // Decode JSON fields
        foreach ($searches as $search) {
            $search->filters = json_decode($search->filters, true);
            $search->polygon_shapes = $search->polygon_shapes ? json_decode($search->polygon_shapes, true) : null;
        }
        
        return $searches;
    }
    
    /**
     * Get searches by admin (including client searches)
     *
     * @param int $admin_id Admin user ID
     * @param bool $include_clients Include client searches
     * @return array Array of search objects
     */
    public static function get_searches_by_admin($admin_id, $include_clients = true) {
        global $wpdb;
        
        $admin_id = absint($admin_id);
        $searches_table = MLD_Saved_Search_Database::get_table_name('saved_searches');
        
        if (!$include_clients) {
            // Only get admin's personal searches
            return self::get_user_searches($admin_id);
        }
        
        // Get admin's searches and searches they created for clients
        $query = "SELECT s.* FROM $searches_table s 
                  WHERE s.user_id = %d 
                  OR s.created_by_admin = %d 
                  ORDER BY s.created_at DESC";
        
        $searches = $wpdb->get_results($wpdb->prepare($query, $admin_id, $admin_id));
        
        // Decode JSON fields
        foreach ($searches as $search) {
            $search->filters = json_decode($search->filters, true);
            $search->polygon_shapes = $search->polygon_shapes ? json_decode($search->polygon_shapes, true) : null;
        }
        
        return $searches;
    }
    
    /**
     * Get client searches for an admin
     *
     * @param int $admin_id Admin user ID
     * @param int $client_id Optional specific client ID
     * @return array Array of search objects
     */
    public static function get_client_searches($admin_id, $client_id = null) {
        global $wpdb;
        
        $admin_id = absint($admin_id);
        $searches_table = MLD_Saved_Search_Database::get_table_name('saved_searches');
        $relationships_table = MLD_Saved_Search_Database::get_table_name('agent_client_relationships');
        
        // Build query to get searches for admin's clients
        $query = "SELECT s.* FROM $searches_table s
                  INNER JOIN $relationships_table r ON s.user_id = r.client_id
                  WHERE r.agent_id = %d AND r.relationship_status = 'active'";
        
        $query_args = [$admin_id];
        
        if ($client_id) {
            $query .= " AND s.user_id = %d";
            $query_args[] = absint($client_id);
        }
        
        $query .= " ORDER BY s.created_at DESC";
        
        $searches = $wpdb->get_results($wpdb->prepare($query, $query_args));
        
        // Decode JSON fields
        foreach ($searches as $search) {
            $search->filters = json_decode($search->filters, true);
            $search->polygon_shapes = $search->polygon_shapes ? json_decode($search->polygon_shapes, true) : null;
        }
        
        return $searches;
    }
    
    /**
     * Create a search for a client as an admin
     *
     * @param array $search_data Search data
     * @param int $client_id Client user ID
     * @param int $admin_id Admin user ID
     * @return int|WP_Error Search ID on success, WP_Error on failure
     */
    public static function create_search_for_client($search_data, $client_id, $admin_id) {
        // Add admin and client info to search data
        $search_data['user_id'] = $client_id;
        $search_data['created_by_admin'] = $admin_id;
        
        return self::create_search($search_data);
    }
    
    /**
     * Toggle search active status
     *
     * @param int $search_id Search ID
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public static function toggle_active_status($search_id) {
        global $wpdb;
        
        $search = self::get_search($search_id);
        if (!$search) {
            return new WP_Error('not_found', 'Search not found');
        }
        
        $new_status = !$search->is_active;
        
        return self::update_search($search_id, ['is_active' => $new_status]);
    }
    
    /**
     * Update last notified timestamp
     *
     * @param int $search_id Search ID
     * @param int $matched_count Number of matches found
     * @return bool
     */
    public static function update_last_notified($search_id, $matched_count = 0) {
        global $wpdb;
        
        $table_name = MLD_Saved_Search_Database::get_table_name('saved_searches');
        
        return $wpdb->update(
            $table_name,
            [
                'last_notified_at' => current_time('mysql'),
                'last_matched_count' => absint($matched_count)
            ],
            ['id' => absint($search_id)]
        );
    }
    
    /**
     * Get searches by notification frequency
     *
     * @param string $frequency Notification frequency
     * @return array Array of search objects
     */
    public static function get_searches_by_frequency($frequency) {
        global $wpdb;
        
        $table_name = MLD_Saved_Search_Database::get_table_name('saved_searches');
        
        $searches = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name 
             WHERE notification_frequency = %s 
             AND is_active = 1 
             ORDER BY last_notified_at ASC",
            $frequency
        ));
        
        // Decode JSON fields
        foreach ($searches as $search) {
            $search->filters = json_decode($search->filters, true);
            $search->polygon_shapes = $search->polygon_shapes ? json_decode($search->polygon_shapes, true) : null;
        }
        
        return $searches;
    }
    
    /**
     * Count user's saved searches
     *
     * @param int $user_id User ID
     * @param bool $active_only Count only active searches
     * @return int Count
     */
    public static function count_user_searches($user_id, $active_only = false) {
        global $wpdb;

        $table_name = MLD_Saved_Search_Database::get_table_name('saved_searches');

        $query = "SELECT COUNT(*) FROM $table_name WHERE user_id = %d";
        $query_args = [absint($user_id)];

        if ($active_only) {
            $query .= " AND is_active = 1";
        }

        return (int) $wpdb->get_var($wpdb->prepare($query, $query_args));
    }

    /**
     * Normalize filters for cross-platform consistency
     *
     * Handles differences between iOS and web filter formats:
     * - beds: iOS sends integer, web sends array - normalize to integer minimum
     * - Keys: Handle both formats (city/City, min_price/price_min)
     *
     * @param array|string $filters Filters (array or JSON string)
     * @return array Normalized filters
     */
    private static function normalize_filters($filters) {
        // Decode if JSON string
        if (is_string($filters)) {
            $filters = json_decode($filters, true);
            if (!is_array($filters)) {
                return [];
            }
        }

        // Normalize beds: convert array to integer minimum
        // Web sends: beds = [2, 3, 4] or ["5+"]
        // iOS sends: beds = 3
        // Normalize to: beds = 2 (minimum value)
        if (isset($filters['beds']) && is_array($filters['beds'])) {
            $numeric_beds = array_filter($filters['beds'], function($val) {
                return is_numeric($val) || (is_string($val) && preg_match('/^\d+/', $val));
            });
            if (!empty($numeric_beds)) {
                // Extract numeric portion from values like "5+"
                $numeric_values = array_map(function($val) {
                    preg_match('/(\d+)/', (string)$val, $matches);
                    return isset($matches[1]) ? (int)$matches[1] : 0;
                }, $numeric_beds);
                $filters['beds'] = min($numeric_values);
            }
        }

        // Normalize baths: ensure numeric value
        $baths_keys = ['baths', 'baths_min', 'min_baths'];
        foreach ($baths_keys as $key) {
            if (isset($filters[$key]) && !is_numeric($filters[$key])) {
                if (is_array($filters[$key]) && !empty($filters[$key])) {
                    $filters[$key] = min(array_filter($filters[$key], 'is_numeric'));
                }
            }
        }

        return $filters;
    }
}