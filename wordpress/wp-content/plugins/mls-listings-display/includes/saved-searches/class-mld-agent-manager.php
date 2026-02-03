<?php
/**
 * MLS Listings Display - Agent Manager Class
 * 
 * Handles agent profiles and agent-client relationships
 * 
 * @package MLS_Listings_Display
 * @subpackage Saved_Searches
 * @since 3.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Agent_Manager {
    
    /**
     * Get agent assigned to a client
     *
     * @param int $client_id Client user ID
     * @return object|null Agent data or null if not found
     */
    public static function get_client_agent($client_id) {
        global $wpdb;
        
        $client_id = absint($client_id);
        
        $relationships_table = MLD_Saved_Search_Database::get_table_name('agent_client_relationships');
        $profiles_table = MLD_Saved_Search_Database::get_table_name('agent_profiles');
        $users_table = $wpdb->users;
        
        $agent = $wpdb->get_row($wpdb->prepare(
            "SELECT 
                r.*,
                p.*,
                u.user_email as wp_email,
                u.display_name as wp_display_name
             FROM $relationships_table r
             LEFT JOIN $profiles_table p ON r.agent_id = p.user_id
             LEFT JOIN $users_table u ON r.agent_id = u.ID
             WHERE r.client_id = %d 
             AND r.relationship_status = 'active'
             ORDER BY r.assigned_date DESC
             LIMIT 1",
            $client_id
        ));
        
        if ($agent) {
            // Use profile display name if available, otherwise WordPress display name
            $agent->display_name = !empty($agent->display_name) ? $agent->display_name : $agent->wp_display_name;
            $agent->email = !empty($agent->email) ? $agent->email : $agent->wp_email;
        }
        
        return $agent;
    }
    
    /**
     * Assign an agent to a client
     *
     * @param int $agent_id Agent user ID
     * @param int $client_id Client user ID
     * @param array $data Optional relationship data
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public static function assign_agent_to_client($agent_id, $client_id, $data = []) {
        global $wpdb;
        
        $agent_id = absint($agent_id);
        $client_id = absint($client_id);
        
        // Verify both users exist
        if (!get_userdata($agent_id) || !get_userdata($client_id)) {
            return new WP_Error('invalid_user', 'Invalid agent or client ID');
        }
        
        $relationships_table = MLD_Saved_Search_Database::get_table_name('agent_client_relationships');
        
        // Check if relationship already exists
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $relationships_table WHERE agent_id = %d AND client_id = %d",
            $agent_id,
            $client_id
        ));
        
        if ($existing) {
            // Update existing relationship
            $update_data = [
                'relationship_status' => 'active',
                'assigned_date' => current_time('mysql')
            ];
            
            if (isset($data['notes'])) {
                $update_data['notes'] = sanitize_textarea_field($data['notes']);
            }
            
            $result = $wpdb->update(
                $relationships_table,
                $update_data,
                ['id' => $existing]
            );
        } else {
            // Create new relationship
            $insert_data = [
                'agent_id' => $agent_id,
                'client_id' => $client_id,
                'relationship_status' => isset($data['status']) ? $data['status'] : 'active',
                'notes' => isset($data['notes']) ? sanitize_textarea_field($data['notes']) : ''
            ];
            
            $result = $wpdb->insert($relationships_table, $insert_data);
        }
        
        if ($result === false) {
            return new WP_Error('db_error', 'Failed to assign agent to client');
        }
        
        do_action('mld_agent_assigned_to_client', $agent_id, $client_id);
        
        return true;
    }
    
    /**
     * Get all clients for an agent
     *
     * @param int $agent_id Agent user ID
     * @param string $status Relationship status filter
     * @return array Array of client data
     */
    public static function get_agent_clients($agent_id, $status = 'active') {
        global $wpdb;
        
        $agent_id = absint($agent_id);
        
        $relationships_table = MLD_Saved_Search_Database::get_table_name('agent_client_relationships');
        $users_table = $wpdb->users;
        $usermeta_table = $wpdb->usermeta;
        
        $query = "SELECT 
                    r.*,
                    u.ID as client_id,
                    u.user_email,
                    u.user_login,
                    u.display_name,
                    u.user_registered,
                    first_name.meta_value as first_name,
                    last_name.meta_value as last_name
                  FROM $relationships_table r
                  INNER JOIN $users_table u ON r.client_id = u.ID
                  LEFT JOIN $usermeta_table first_name 
                    ON u.ID = first_name.user_id AND first_name.meta_key = 'first_name'
                  LEFT JOIN $usermeta_table last_name 
                    ON u.ID = last_name.user_id AND last_name.meta_key = 'last_name'
                  WHERE r.agent_id = %d";
        
        $query_args = [$agent_id];
        
        if ($status) {
            $query .= " AND r.relationship_status = %s";
            $query_args[] = $status;
        }
        
        $query .= " ORDER BY r.assigned_date DESC";
        
        $clients = $wpdb->get_results($wpdb->prepare($query, $query_args));
        
        // Add additional client data
        foreach ($clients as $client) {
            // Get property preference stats
            $client->preference_stats = MLD_Property_Preferences::get_preference_stats($client->client_id);
            
            // Get saved search count
            $client->saved_search_count = MLD_Saved_Searches::count_user_searches($client->client_id, true);
        }
        
        return $clients;
    }
    
    /**
     * Update agent profile
     *
     * @param int $agent_id Agent user ID
     * @param array $data Profile data
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public static function update_agent_profile($agent_id, $data) {
        global $wpdb;
        
        $agent_id = absint($agent_id);
        
        // Verify user exists and has appropriate role
        $user = get_userdata($agent_id);
        if (!$user) {
            return new WP_Error('invalid_user', 'Invalid agent ID');
        }
        
        $profiles_table = MLD_Saved_Search_Database::get_table_name('agent_profiles');
        
        // Check if profile exists
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $profiles_table WHERE user_id = %d",
            $agent_id
        ));
        
        // Prepare profile data
        $profile_data = [];
        
        $allowed_fields = [
            'display_name' => 'sanitize_text_field',
            'phone' => 'sanitize_text_field',
            'email' => 'sanitize_email',
            'office_name' => 'sanitize_text_field',
            'office_address' => 'sanitize_textarea_field',
            'bio' => 'wp_kses_post',
            'photo_url' => 'esc_url_raw',
            'license_number' => 'sanitize_text_field',
            'specialties' => 'sanitize_textarea_field'
        ];
        
        foreach ($allowed_fields as $field => $sanitizer) {
            if (isset($data[$field])) {
                $profile_data[$field] = call_user_func($sanitizer, $data[$field]);
            }
        }
        
        if (isset($data['is_active'])) {
            $profile_data['is_active'] = (bool)$data['is_active'];
        }
        
        if ($existing) {
            // Update existing profile
            $result = $wpdb->update(
                $profiles_table,
                $profile_data,
                ['user_id' => $agent_id]
            );
        } else {
            // Create new profile
            $profile_data['user_id'] = $agent_id;
            $result = $wpdb->insert($profiles_table, $profile_data);
        }
        
        if ($result === false) {
            return new WP_Error('db_error', 'Failed to update agent profile');
        }
        
        do_action('mld_agent_profile_updated', $agent_id, $profile_data);
        
        return true;
    }
    
    /**
     * Get agent profile
     *
     * @param int $agent_id Agent user ID
     * @return object|null Agent profile or null if not found
     */
    public static function get_agent_profile($agent_id) {
        global $wpdb;
        
        $agent_id = absint($agent_id);
        
        $profiles_table = MLD_Saved_Search_Database::get_table_name('agent_profiles');
        $users_table = $wpdb->users;
        
        $profile = $wpdb->get_row($wpdb->prepare(
            "SELECT 
                p.*,
                u.user_email as wp_email,
                u.display_name as wp_display_name
             FROM $profiles_table p
             INNER JOIN $users_table u ON p.user_id = u.ID
             WHERE p.user_id = %d",
            $agent_id
        ));
        
        if ($profile) {
            // Use profile data if available, otherwise WordPress data
            $profile->display_name = !empty($profile->display_name) ? $profile->display_name : $profile->wp_display_name;
            $profile->email = !empty($profile->email) ? $profile->email : $profile->wp_email;
        }
        
        return $profile;
    }
    
    /**
     * Get agent activity for a client
     *
     * @param int $agent_id Agent user ID
     * @param int $client_id Optional client ID
     * @return array Activity log
     */
    public static function get_agent_activity($agent_id, $client_id = null) {
        global $wpdb;
        
        $agent_id = absint($agent_id);
        
        $activity = [];
        
        // Get saved searches created by agent
        $searches_table = MLD_Saved_Search_Database::get_table_name('saved_searches');
        
        $query = "SELECT 
                    'saved_search' as activity_type,
                    created_at as activity_date,
                    name as activity_detail,
                    user_id as client_id
                  FROM $searches_table
                  WHERE created_by_admin = %d";
        
        $query_args = [$agent_id];
        
        if ($client_id) {
            $query .= " AND user_id = %d";
            $query_args[] = absint($client_id);
        }
        
        $search_activities = $wpdb->get_results($wpdb->prepare($query, $query_args));
        
        foreach ($search_activities as $activity) {
            $activity->activity_description = sprintf(
                'Created saved search "%s"',
                esc_html($activity->activity_detail)
            );
            $activity[] = $activity;
        }
        
        // Sort activities by date
        usort($activity, function($a, $b) {
            return strtotime($b->activity_date) - strtotime($a->activity_date);
        });
        
        return $activity;
    }
    
    /**
     * Remove agent-client relationship
     *
     * @param int $agent_id Agent WordPress user ID
     * @param int $client_id Client user ID
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public static function remove_agent_client_relationship($agent_id, $client_id) {
        global $wpdb;

        $agent_id = absint($agent_id);
        $client_id = absint($client_id);

        if ($agent_id <= 0 || $client_id <= 0) {
            return new WP_Error('invalid_ids', 'Invalid agent or client ID');
        }

        $relationships_table = MLD_Saved_Search_Database::get_table_name('agent_client_relationships');
        $profiles_table = MLD_Saved_Search_Database::get_table_name('agent_profiles');

        // Get agent's profile ID to check both IDs (legacy compatibility)
        // NOTE: Legacy data may have agent_profile.id stored as agent_id,
        // while newer data uses the WordPress user ID. We check both.
        $agent_profile_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$profiles_table} WHERE user_id = %d",
            $agent_id
        ));

        // Update status to inactive instead of deleting
        // Match either WordPress user ID or profile ID for legacy compatibility
        if ($agent_profile_id && (int) $agent_profile_id !== $agent_id) {
            $result = $wpdb->query($wpdb->prepare(
                "UPDATE {$relationships_table}
                 SET relationship_status = 'inactive'
                 WHERE client_id = %d
                   AND (agent_id = %d OR agent_id = %d)
                   AND relationship_status = 'active'",
                $client_id,
                $agent_id,
                $agent_profile_id
            ));
        } else {
            $result = $wpdb->query($wpdb->prepare(
                "UPDATE {$relationships_table}
                 SET relationship_status = 'inactive'
                 WHERE client_id = %d
                   AND agent_id = %d
                   AND relationship_status = 'active'",
                $client_id,
                $agent_id
            ));
        }

        if ($result === false) {
            return new WP_Error('db_error', 'Failed to remove relationship');
        }

        if ($result === 0) {
            return new WP_Error('not_found', 'No active relationship found to remove');
        }

        do_action('mld_agent_client_relationship_removed', $agent_id, $client_id);

        return true;
    }
    
    /**
     * Get all active agents
     *
     * @return array Array of agent profiles
     */
    public static function get_all_agents() {
        global $wpdb;
        
        $profiles_table = MLD_Saved_Search_Database::get_table_name('agent_profiles');
        $users_table = $wpdb->users;
        
        $agents = $wpdb->get_results(
            "SELECT 
                p.*,
                u.user_email as wp_email,
                u.display_name as wp_display_name
             FROM $profiles_table p
             INNER JOIN $users_table u ON p.user_id = u.ID
             WHERE p.is_active = 1
             ORDER BY p.display_name ASC"
        );
        
        foreach ($agents as $agent) {
            // Use profile data if available, otherwise WordPress data
            $agent->display_name = !empty($agent->display_name) ? $agent->display_name : $agent->wp_display_name;
            $agent->email = !empty($agent->email) ? $agent->email : $agent->wp_email;
            
            // Get client count
            $agent->client_count = self::count_agent_clients($agent->user_id);
        }
        
        return $agents;
    }
    
    /**
     * Count clients for an agent
     *
     * @param int $agent_id Agent user ID
     * @param string $status Relationship status filter
     * @return int Client count
     */
    public static function count_agent_clients($agent_id, $status = 'active') {
        global $wpdb;

        $agent_id = absint($agent_id);

        $relationships_table = MLD_Saved_Search_Database::get_table_name('agent_client_relationships');

        // Join with wp_users to only count clients that still exist
        $query = "SELECT COUNT(*) FROM $relationships_table r
                  INNER JOIN {$wpdb->users} u ON r.client_id = u.ID
                  WHERE r.agent_id = %d";
        $query_args = [$agent_id];

        if ($status) {
            $query .= " AND r.relationship_status = %s";
            $query_args[] = $status;
        }

        return (int)$wpdb->get_var($wpdb->prepare($query, $query_args));
    }
    
    /**
     * Check if user is an agent
     *
     * @param int $user_id User ID
     * @return bool
     */
    public static function is_agent($user_id) {
        // Check if user has agent capabilities or admin role
        $user = get_userdata($user_id);
        if (!$user) {
            return false;
        }
        
        return user_can($user_id, 'mld_manage_saved_searches') || 
               user_can($user_id, 'manage_options');
    }
}