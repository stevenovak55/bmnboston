<?php
/**
 * MLS Listings Display - Saved Search Collaboration
 *
 * Handles agent-client collaboration for saved searches including:
 * - Agent creation of searches for clients
 * - Activity logging and audit trail
 * - Agent metrics and dashboards
 * - Cross-user search management
 *
 * @package MLS_Listings_Display
 * @subpackage Saved_Searches
 * @since 6.32.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Saved_Search_Collaboration {

    /**
     * Action types for activity logging
     */
    const ACTION_CREATED = 'created';
    const ACTION_UPDATED = 'updated';
    const ACTION_PAUSED = 'paused';
    const ACTION_RESUMED = 'resumed';
    const ACTION_DELETED = 'deleted';
    const ACTION_FREQUENCY_CHANGED = 'frequency_changed';
    const ACTION_NOTE_ADDED = 'note_added';
    const ACTION_SHARED = 'shared';

    /**
     * Check if a user is an agent for a specific client
     *
     * Checks if there is any active relationship between this agent and client,
     * allowing clients to have multiple agents.
     *
     * @param int $agent_user_id The agent's user ID
     * @param int $client_user_id The client's user ID
     * @return bool
     */
    public static function is_agent_for_client($agent_user_id, $client_user_id) {
        global $wpdb;

        // Check if there's an active relationship in the relationships table
        $relationships_table = $wpdb->prefix . 'mld_agent_client_relationships';

        // First check if the table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '{$relationships_table}'") !== $relationships_table) {
            return false;
        }

        $result = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$relationships_table}
             WHERE agent_id = %d AND client_id = %d AND is_active = 1",
            $agent_user_id, $client_user_id
        ));

        return $result > 0;
    }

    /**
     * Get all clients assigned to an agent with their search summaries
     *
     * @param int $agent_user_id The agent's user ID
     * @return array Array of clients with search counts
     */
    public static function get_agent_clients_with_searches($agent_user_id) {
        global $wpdb;

        if (!class_exists('MLD_Agent_Client_Manager')) {
            return array();
        }

        $relationships_table = $wpdb->prefix . 'mld_agent_client_relationships';
        $searches_table = $wpdb->prefix . 'mld_saved_searches';

        // Get agent profile ID (returns stdClass object)
        $agent_profile = MLD_Agent_Client_Manager::get_agent_by_user_id($agent_user_id);
        if (!$agent_profile || !isset($agent_profile->id)) {
            return array();
        }

        // Get all active clients for this agent
        // NOTE: agent_id in relationships table may contain either:
        // - the agent profile ID (newer convention)
        // - the WordPress user ID (legacy data)
        // We check both to ensure backward compatibility
        $clients = $wpdb->get_results($wpdb->prepare(
            "SELECT
                r.client_id,
                r.assigned_date,
                r.notes as relationship_notes,
                u.display_name,
                u.user_email,
                (SELECT COUNT(*) FROM {$searches_table} WHERE user_id = r.client_id AND is_active = 1) as active_searches,
                (SELECT COUNT(*) FROM {$searches_table} WHERE user_id = r.client_id) as total_searches,
                (SELECT MAX(created_at) FROM {$searches_table} WHERE user_id = r.client_id) as last_search_date
            FROM {$relationships_table} r
            INNER JOIN {$wpdb->users} u ON r.client_id = u.ID
            WHERE (r.agent_id = %d OR r.agent_id = %d) AND r.relationship_status = 'active'
            ORDER BY r.assigned_date DESC",
            $agent_profile->id,
            $agent_user_id
        ), ARRAY_A);

        return $clients ? $clients : array();
    }

    /**
     * Get all saved searches for a specific client (for agent view)
     *
     * @param int $agent_user_id The agent's user ID
     * @param int $client_user_id The client's user ID
     * @param array $args Optional query arguments
     * @return array|WP_Error
     */
    public static function get_client_searches($agent_user_id, $client_user_id, $args = array()) {
        global $wpdb;

        // Verify agent has access to this client
        if (!self::is_agent_for_client($agent_user_id, $client_user_id)) {
            return new WP_Error('unauthorized', 'You are not assigned to this client', array('status' => 403));
        }

        $defaults = array(
            'per_page' => 20,
            'page' => 1,
            'status' => null, // null = all, 'active', 'paused'
            'order_by' => 'created_at',
            'order' => 'DESC',
        );

        $args = wp_parse_args($args, $defaults);
        $table = $wpdb->prefix . 'mld_saved_searches';
        $offset = ($args['page'] - 1) * $args['per_page'];

        $where = array("user_id = %d");
        $params = array($client_user_id);

        if ($args['status'] === 'active') {
            $where[] = "is_active = 1";
        } elseif ($args['status'] === 'paused') {
            $where[] = "is_active = 0";
        }

        $where_clause = implode(' AND ', $where);
        $order_by = sanitize_sql_orderby($args['order_by'] . ' ' . $args['order']);

        $sql = $wpdb->prepare(
            "SELECT * FROM {$table} WHERE {$where_clause} ORDER BY {$order_by} LIMIT %d OFFSET %d",
            array_merge($params, array($args['per_page'], $offset))
        );

        $searches = $wpdb->get_results($sql, ARRAY_A);

        // Get total count
        $count_sql = $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE {$where_clause}",
            $params
        );
        $total = (int) $wpdb->get_var($count_sql);

        // Enrich with creator info
        foreach ($searches as &$search) {
            $search = self::enrich_search_with_collaboration_data($search);
        }

        return array(
            'searches' => $searches,
            'total' => $total,
            'pages' => ceil($total / $args['per_page']),
            'page' => $args['page'],
        );
    }

    /**
     * Get all client searches for an agent (across all clients)
     *
     * @param int $agent_user_id The agent's user ID
     * @param array $args Optional query arguments
     * @return array
     */
    public static function get_all_client_searches($agent_user_id, $args = array()) {
        global $wpdb;

        $defaults = array(
            'per_page' => 50,
            'page' => 1,
            'client_id' => null, // Filter by specific client
            'status' => null, // 'active', 'paused', or null for all
            'is_agent_recommended' => null, // true = agent-created only
            'order_by' => 'created_at',
            'order' => 'DESC',
        );

        $args = wp_parse_args($args, $defaults);

        // Get all client IDs for this agent
        $clients = self::get_agent_clients_with_searches($agent_user_id);
        if (empty($clients)) {
            return array(
                'searches' => array(),
                'total' => 0,
                'pages' => 0,
                'page' => 1,
            );
        }

        $client_ids = wp_list_pluck($clients, 'client_id');

        // Apply client filter if specified
        if (!empty($args['client_id']) && in_array($args['client_id'], $client_ids)) {
            $client_ids = array((int) $args['client_id']);
        }

        $table = $wpdb->prefix . 'mld_saved_searches';
        $offset = ($args['page'] - 1) * $args['per_page'];

        $placeholders = implode(',', array_fill(0, count($client_ids), '%d'));
        $where = array("user_id IN ({$placeholders})");
        $params = array_map('intval', $client_ids);

        if ($args['status'] === 'active') {
            $where[] = "is_active = 1";
        } elseif ($args['status'] === 'paused') {
            $where[] = "is_active = 0";
        }

        if ($args['is_agent_recommended'] === true) {
            $where[] = "is_agent_recommended = 1";
        }

        $where_clause = implode(' AND ', $where);
        $order_by = sanitize_sql_orderby($args['order_by'] . ' ' . $args['order']);

        $sql = $wpdb->prepare(
            "SELECT s.*, u.display_name as client_name, u.user_email as client_email
            FROM {$table} s
            INNER JOIN {$wpdb->users} u ON s.user_id = u.ID
            WHERE {$where_clause}
            ORDER BY {$order_by}
            LIMIT %d OFFSET %d",
            array_merge($params, array($args['per_page'], $offset))
        );

        $searches = $wpdb->get_results($sql, ARRAY_A);

        // Get total count
        $count_sql = $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE {$where_clause}",
            $params
        );
        $total = (int) $wpdb->get_var($count_sql);

        // Enrich with collaboration data
        foreach ($searches as &$search) {
            $search = self::enrich_search_with_collaboration_data($search);
        }

        return array(
            'searches' => $searches,
            'total' => $total,
            'pages' => ceil($total / $args['per_page']),
            'page' => $args['page'],
        );
    }

    /**
     * Create a saved search for a client (as an agent)
     *
     * @param int $agent_user_id The agent's user ID
     * @param int $client_user_id The client's user ID
     * @param array $search_data The search data
     * @return int|WP_Error Search ID or error
     */
    public static function create_search_for_client($agent_user_id, $client_user_id, $search_data) {
        global $wpdb;

        // Verify agent has access to this client
        if (!self::is_agent_for_client($agent_user_id, $client_user_id)) {
            return new WP_Error('unauthorized', 'You are not assigned to this client', array('status' => 403));
        }

        // Validate required fields
        if (empty($search_data['name'])) {
            return new WP_Error('missing_name', 'Search name is required', array('status' => 400));
        }

        if (empty($search_data['filters'])) {
            return new WP_Error('missing_filters', 'Search filters are required', array('status' => 400));
        }

        $table = $wpdb->prefix . 'mld_saved_searches';
        $now = current_time('mysql');

        // Prepare insert data
        $insert_data = array(
            'user_id' => $client_user_id,
            'created_by_user_id' => $agent_user_id,
            'name' => sanitize_text_field($search_data['name']),
            'description' => isset($search_data['description']) ? sanitize_textarea_field($search_data['description']) : '',
            'filters' => is_array($search_data['filters']) ? wp_json_encode($search_data['filters']) : $search_data['filters'],
            'notification_frequency' => isset($search_data['notification_frequency']) ? $search_data['notification_frequency'] : 'daily',
            'is_active' => isset($search_data['is_active']) ? (int) $search_data['is_active'] : 1,
            'is_agent_recommended' => 1,
            'agent_notes' => isset($search_data['agent_notes']) ? sanitize_textarea_field($search_data['agent_notes']) : null,
            'cc_agent_on_notify' => isset($search_data['cc_agent_on_notify']) ? (int) $search_data['cc_agent_on_notify'] : 1,
            'created_at' => $now,
            'updated_at' => $now,
        );

        if (!empty($search_data['polygon_shapes'])) {
            $insert_data['polygon_shapes'] = is_array($search_data['polygon_shapes'])
                ? wp_json_encode($search_data['polygon_shapes'])
                : $search_data['polygon_shapes'];
        }

        $result = $wpdb->insert($table, $insert_data);

        if ($result === false) {
            return new WP_Error('db_error', 'Failed to create saved search', array('status' => 500));
        }

        $search_id = $wpdb->insert_id;

        // Log the activity
        self::log_activity($search_id, $agent_user_id, self::ACTION_CREATED, array(
            'created_for_client' => $client_user_id,
            'search_name' => $search_data['name'],
        ));

        return $search_id;
    }

    /**
     * Update a saved search (with authorization check)
     *
     * @param int $search_id The search ID
     * @param int $user_id The user making the update
     * @param array $update_data The data to update
     * @return bool|WP_Error
     */
    public static function update_search($search_id, $user_id, $update_data) {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_saved_searches';

        // Get the existing search
        $search = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d",
            $search_id
        ), ARRAY_A);

        if (!$search) {
            return new WP_Error('not_found', 'Saved search not found', array('status' => 404));
        }

        // Check authorization
        $is_owner = (int) $search['user_id'] === (int) $user_id;
        $is_agent = self::is_agent_for_client($user_id, $search['user_id']);
        $is_admin = class_exists('MLD_User_Type_Manager') && MLD_User_Type_Manager::is_admin($user_id);

        if (!$is_owner && !$is_agent && !$is_admin) {
            return new WP_Error('unauthorized', 'You do not have permission to update this search', array('status' => 403));
        }

        $now = current_time('mysql');
        $allowed_fields = array(
            'name', 'description', 'filters', 'polygon_shapes', 'notification_frequency',
            'is_active', 'agent_notes', 'cc_agent_on_notify', 'exclude_disliked'
        );

        $data = array(
            'last_modified_by_user_id' => $user_id,
            'last_modified_at' => $now,
            'updated_at' => $now,
        );

        $changes = array();

        foreach ($allowed_fields as $field) {
            if (isset($update_data[$field])) {
                $old_value = isset($search[$field]) ? $search[$field] : null;
                $new_value = $update_data[$field];

                // Encode arrays as JSON
                if (in_array($field, array('filters', 'polygon_shapes')) && is_array($new_value)) {
                    $new_value = wp_json_encode($new_value);
                }

                // Sanitize text fields
                if (in_array($field, array('name', 'description', 'agent_notes'))) {
                    $new_value = sanitize_text_field($new_value);
                }

                $data[$field] = $new_value;

                // Track changes for activity log
                if ($old_value !== $new_value) {
                    $changes[$field] = array(
                        'old' => $old_value,
                        'new' => $new_value,
                    );
                }
            }
        }

        $result = $wpdb->update($table, $data, array('id' => $search_id));

        if ($result === false) {
            return new WP_Error('db_error', 'Failed to update saved search', array('status' => 500));
        }

        // Determine action type for logging
        $action_type = self::ACTION_UPDATED;
        if (isset($update_data['is_active'])) {
            $action_type = $update_data['is_active'] ? self::ACTION_RESUMED : self::ACTION_PAUSED;
        } elseif (isset($update_data['notification_frequency']) && !empty($changes['notification_frequency'])) {
            $action_type = self::ACTION_FREQUENCY_CHANGED;
        } elseif (isset($update_data['agent_notes']) && !empty($changes['agent_notes'])) {
            $action_type = self::ACTION_NOTE_ADDED;
        }

        // Log the activity
        self::log_activity($search_id, $user_id, $action_type, array(
            'changes' => $changes,
        ));

        return true;
    }

    /**
     * Get activity log for a saved search
     *
     * @param int $search_id The search ID
     * @param int $limit Maximum number of entries
     * @return array
     */
    public static function get_activity_log($search_id, $limit = 50) {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_saved_search_activity';

        $activities = $wpdb->get_results($wpdb->prepare(
            "SELECT a.*, u.display_name as user_name, u.user_email
            FROM {$table} a
            INNER JOIN {$wpdb->users} u ON a.user_id = u.ID
            WHERE a.saved_search_id = %d
            ORDER BY a.created_at DESC
            LIMIT %d",
            $search_id,
            $limit
        ), ARRAY_A);

        // Parse JSON details
        foreach ($activities as &$activity) {
            if (!empty($activity['action_details'])) {
                $activity['action_details'] = json_decode($activity['action_details'], true);
            }
            // Add human-readable action description
            $activity['description'] = self::get_action_description($activity);
        }

        return $activities;
    }

    /**
     * Get agent metrics for dashboard
     *
     * @param int $agent_user_id The agent's user ID
     * @param string $period 'week', 'month', 'quarter', 'year'
     * @return array
     */
    public static function get_agent_metrics($agent_user_id, $period = 'month') {
        global $wpdb;

        // Calculate date range
        $now = current_time('mysql');
        switch ($period) {
            case 'week':
                $start_date = date('Y-m-d 00:00:00', strtotime('-7 days'));
                break;
            case 'quarter':
                $start_date = date('Y-m-d 00:00:00', strtotime('-90 days'));
                break;
            case 'year':
                $start_date = date('Y-m-d 00:00:00', strtotime('-365 days'));
                break;
            case 'month':
            default:
                $start_date = date('Y-m-d 00:00:00', strtotime('-30 days'));
                break;
        }

        // Get client info
        $clients = self::get_agent_clients_with_searches($agent_user_id);
        $client_ids = !empty($clients) ? wp_list_pluck($clients, 'client_id') : array(0);

        $searches_table = $wpdb->prefix . 'mld_saved_searches';
        $activity_table = $wpdb->prefix . 'mld_saved_search_activity';
        $results_table = $wpdb->prefix . 'mld_saved_search_results';

        $placeholders = implode(',', array_fill(0, count($client_ids), '%d'));

        // Total clients
        $total_clients = count($clients);

        // Total active searches across all clients
        $total_searches = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$searches_table} WHERE user_id IN ({$placeholders}) AND is_active = 1",
            array_map('intval', $client_ids)
        ));

        // Agent-recommended searches
        $agent_recommended = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$searches_table} WHERE user_id IN ({$placeholders}) AND is_agent_recommended = 1 AND is_active = 1",
            array_map('intval', $client_ids)
        ));

        // Searches created in period
        $searches_created = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$searches_table} WHERE user_id IN ({$placeholders}) AND created_at >= %s",
            array_merge(array_map('intval', $client_ids), array($start_date))
        ));

        // Total notifications sent in period
        $notifications_sent = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$results_table} r
            INNER JOIN {$searches_table} s ON r.saved_search_id = s.id
            WHERE s.user_id IN ({$placeholders}) AND r.notified_at >= %s",
            array_merge(array_map('intval', $client_ids), array($start_date))
        ));

        // Activity breakdown
        $activity_counts = $wpdb->get_results($wpdb->prepare(
            "SELECT action_type, COUNT(*) as count
            FROM {$activity_table}
            WHERE saved_search_id IN (SELECT id FROM {$searches_table} WHERE user_id IN ({$placeholders}))
            AND created_at >= %s
            GROUP BY action_type",
            array_merge(array_map('intval', $client_ids), array($start_date))
        ), ARRAY_A);

        $activity_by_type = array();
        foreach ($activity_counts as $row) {
            $activity_by_type[$row['action_type']] = (int) $row['count'];
        }

        // Top active clients (by search count)
        $top_clients = array_slice($clients, 0, 5);

        return array(
            'period' => $period,
            'start_date' => $start_date,
            'total_clients' => $total_clients,
            'total_active_searches' => $total_searches,
            'agent_recommended_searches' => $agent_recommended,
            'searches_created_in_period' => $searches_created,
            'notifications_sent_in_period' => $notifications_sent,
            'activity_breakdown' => $activity_by_type,
            'top_clients' => $top_clients,
        );
    }

    /**
     * Log an activity entry for a saved search
     *
     * @param int $search_id The search ID
     * @param int $user_id The user who performed the action
     * @param string $action_type The type of action
     * @param array|null $details Additional details
     * @return bool
     */
    public static function log_activity($search_id, $user_id, $action_type, $details = null) {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_saved_search_activity';

        $result = $wpdb->insert($table, array(
            'saved_search_id' => $search_id,
            'user_id' => $user_id,
            'action_type' => $action_type,
            'action_details' => $details ? wp_json_encode($details) : null,
            'created_at' => current_time('mysql'),
        ));

        return $result !== false;
    }

    /**
     * Enrich a search with collaboration data
     *
     * @param array $search The search data
     * @return array Enriched search data
     */
    private static function enrich_search_with_collaboration_data($search) {
        global $wpdb;

        // Get creator info
        if (!empty($search['created_by_user_id'])) {
            $creator = get_userdata($search['created_by_user_id']);
            $search['created_by_name'] = $creator ? $creator->display_name : 'Unknown';
            $search['created_by_is_agent'] = class_exists('MLD_User_Type_Manager')
                && MLD_User_Type_Manager::is_agent($search['created_by_user_id']);
        } else {
            $owner = get_userdata($search['user_id']);
            $search['created_by_name'] = $owner ? $owner->display_name : 'Unknown';
            $search['created_by_is_agent'] = false;
        }

        // Get last modifier info
        if (!empty($search['last_modified_by_user_id'])) {
            $modifier = get_userdata($search['last_modified_by_user_id']);
            $search['last_modified_by_name'] = $modifier ? $modifier->display_name : 'Unknown';
        }

        // Get recent activity count
        $activity_table = $wpdb->prefix . 'mld_saved_search_activity';
        $recent_activity = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$activity_table} WHERE saved_search_id = %d AND created_at >= %s",
            $search['id'],
            date('Y-m-d H:i:s', strtotime('-7 days'))
        ));
        $search['recent_activity_count'] = $recent_activity;

        // Parse JSON fields
        if (!empty($search['filters']) && is_string($search['filters'])) {
            $search['filters'] = json_decode($search['filters'], true);
        }
        if (!empty($search['polygon_shapes']) && is_string($search['polygon_shapes'])) {
            $search['polygon_shapes'] = json_decode($search['polygon_shapes'], true);
        }

        return $search;
    }

    /**
     * Get human-readable description for an action
     *
     * @param array $activity The activity entry
     * @return string
     */
    private static function get_action_description($activity) {
        $user_name = !empty($activity['user_name']) ? $activity['user_name'] : 'Someone';

        switch ($activity['action_type']) {
            case self::ACTION_CREATED:
                if (!empty($activity['action_details']['created_for_client'])) {
                    return sprintf('%s created this search for the client', $user_name);
                }
                return sprintf('%s created this search', $user_name);

            case self::ACTION_UPDATED:
                return sprintf('%s updated this search', $user_name);

            case self::ACTION_PAUSED:
                return sprintf('%s paused notifications for this search', $user_name);

            case self::ACTION_RESUMED:
                return sprintf('%s resumed notifications for this search', $user_name);

            case self::ACTION_DELETED:
                return sprintf('%s deleted this search', $user_name);

            case self::ACTION_FREQUENCY_CHANGED:
                $details = $activity['action_details'];
                if (!empty($details['changes']['notification_frequency'])) {
                    return sprintf('%s changed notification frequency from %s to %s',
                        $user_name,
                        $details['changes']['notification_frequency']['old'],
                        $details['changes']['notification_frequency']['new']
                    );
                }
                return sprintf('%s changed the notification frequency', $user_name);

            case self::ACTION_NOTE_ADDED:
                return sprintf('%s added a note', $user_name);

            case self::ACTION_SHARED:
                return sprintf('%s shared this search', $user_name);

            default:
                return sprintf('%s performed an action', $user_name);
        }
    }

    /**
     * Check if user can access a saved search
     *
     * @param int $search_id The search ID
     * @param int $user_id The user ID
     * @return bool
     */
    public static function can_access_search($search_id, $user_id) {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_saved_searches';
        $search = $wpdb->get_row($wpdb->prepare(
            "SELECT user_id FROM {$table} WHERE id = %d",
            $search_id
        ));

        if (!$search) {
            return false;
        }

        // Owner can always access
        if ((int) $search->user_id === (int) $user_id) {
            return true;
        }

        // Agent for the client can access
        if (self::is_agent_for_client($user_id, $search->user_id)) {
            return true;
        }

        // Admin can access
        if (class_exists('MLD_User_Type_Manager') && MLD_User_Type_Manager::is_admin($user_id)) {
            return true;
        }

        return false;
    }

    /**
     * Get saved search with authorization check
     *
     * @param int $search_id The search ID
     * @param int $user_id The requesting user ID
     * @return array|WP_Error
     */
    public static function get_search($search_id, $user_id) {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_saved_searches';
        $search = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d",
            $search_id
        ), ARRAY_A);

        if (!$search) {
            return new WP_Error('not_found', 'Saved search not found', array('status' => 404));
        }

        if (!self::can_access_search($search_id, $user_id)) {
            return new WP_Error('unauthorized', 'You do not have permission to view this search', array('status' => 403));
        }

        return self::enrich_search_with_collaboration_data($search);
    }
}
