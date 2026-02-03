<?php
/**
 * MLS Listings Display - Agent Client Manager
 * 
 * Manages agent-client relationships and assignments
 * 
 * @package MLS_Listings_Display
 * @subpackage Saved_Searches
 * @since 3.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Agent_Client_Manager {
    
    /**
     * Get all agents
     *
     * @param array $args Query arguments
     * @return array Array of agent profiles
     */
    public static function get_agents($args = []) {
        global $wpdb;
        
        $defaults = [
            'status' => 'active',
            'orderby' => 'display_name',
            'order' => 'ASC',
            'limit' => -1,
            'offset' => 0
        ];
        
        $args = wp_parse_args($args, $defaults);
        
        $table_name = MLD_Saved_Search_Database::get_table_name('agent_profiles');
        
        $where_clauses = ['1=1'];
        $where_values = [];
        
        if ($args['status'] === 'active') {
            $where_clauses[] = 'is_active = 1';
        } elseif ($args['status'] === 'inactive') {
            $where_clauses[] = 'is_active = 0';
        }
        
        $where_sql = implode(' AND ', $where_clauses);
        
        $sql = "SELECT ap.*, u.user_email, u.user_login, u.display_name as wp_display_name
                FROM {$table_name} ap
                INNER JOIN {$wpdb->users} u ON ap.user_id = u.ID
                WHERE {$where_sql}
                ORDER BY {$args['orderby']} {$args['order']}";
        
        if ($args['limit'] > 0) {
            $sql .= $wpdb->prepare(" LIMIT %d OFFSET %d", $args['limit'], $args['offset']);
        }
        
        if (!empty($where_values)) {
            $sql = $wpdb->prepare($sql, $where_values);
        }
        
        return $wpdb->get_results($sql, ARRAY_A);
    }
    
    /**
     * Get agent by ID
     *
     * @param int $agent_id Agent ID (user ID)
     * @return array|null Agent profile data or null if not found
     */
    public static function get_agent($agent_id) {
        global $wpdb;
        
        $table_name = MLD_Saved_Search_Database::get_table_name('agent_profiles');
        
        $sql = $wpdb->prepare(
            "SELECT ap.*, u.user_email, u.user_login, u.display_name as wp_display_name
             FROM {$table_name} ap
             INNER JOIN {$wpdb->users} u ON ap.user_id = u.ID
             WHERE ap.user_id = %d",
            $agent_id
        );
        
        return $wpdb->get_row($sql, ARRAY_A);
    }
    
    /**
     * Create or update agent profile
     *
     * @param array $data Agent data
     * @return int|false Agent ID on success, false on failure
     */
    public static function save_agent($data) {
        global $wpdb;
        
        $table_name = MLD_Saved_Search_Database::get_table_name('agent_profiles');
        
        $defaults = [
            'display_name' => '',
            'phone' => '',
            'email' => '',
            'office_name' => '',
            'office_address' => '',
            'bio' => '',
            'photo_url' => '',
            'license_number' => '',
            'specialties' => '',
            'is_active' => 1,
            'snab_staff_id' => null
        ];

        $data = wp_parse_args($data, $defaults);

        // Required fields
        if (empty($data['user_id'])) {
            return false;
        }

        // Check if agent exists
        $existing = self::get_agent($data['user_id']);

        // Handle snab_staff_id (can be null, empty string, or integer)
        $snab_staff_id = null;
        if (!empty($data['snab_staff_id']) && intval($data['snab_staff_id']) > 0) {
            $snab_staff_id = intval($data['snab_staff_id']);
        }

        $db_data = [
            'user_id' => $data['user_id'],
            'display_name' => sanitize_text_field($data['display_name']),
            'phone' => sanitize_text_field($data['phone']),
            'email' => sanitize_email($data['email']),
            'office_name' => sanitize_text_field($data['office_name']),
            'office_address' => sanitize_textarea_field($data['office_address']),
            'bio' => sanitize_textarea_field($data['bio']),
            'photo_url' => esc_url_raw($data['photo_url']),
            'license_number' => sanitize_text_field($data['license_number']),
            'specialties' => sanitize_textarea_field($data['specialties']),
            'is_active' => intval($data['is_active']),
            'snab_staff_id' => $snab_staff_id
        ];

        // Build format array dynamically based on snab_staff_id
        $db_format = ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d'];
        $db_format[] = $snab_staff_id === null ? null : '%d';

        if ($existing) {
            // Update
            $result = $wpdb->update(
                $table_name,
                $db_data,
                ['user_id' => $data['user_id']],
                $db_format,
                ['%d']
            );

            return $result !== false ? $data['user_id'] : false;
        } else {
            // Insert
            $result = $wpdb->insert(
                $table_name,
                $db_data,
                $db_format
            );

            return $result ? $data['user_id'] : false;
        }
    }
    
    /**
     * Delete agent profile
     *
     * @param int $agent_id Agent ID
     * @return bool Success
     */
    public static function delete_agent($agent_id) {
        global $wpdb;
        
        // First remove all client relationships
        self::unassign_all_clients($agent_id);
        
        // Delete agent profile
        $table_name = MLD_Saved_Search_Database::get_table_name('agent_profiles');
        
        return $wpdb->delete(
            $table_name,
            ['user_id' => $agent_id],
            ['%d']
        ) !== false;
    }
    
    /**
     * Get clients assigned to an agent
     *
     * @param int $agent_id Agent ID
     * @param string $status Relationship status (active, inactive, pending, all)
     * @return array Array of client data
     */
    public static function get_agent_clients($agent_id, $status = 'active') {
        global $wpdb;
        
        $rel_table = MLD_Saved_Search_Database::get_table_name('agent_client_relationships');
        $pref_table = MLD_Saved_Search_Database::get_table_name('admin_client_preferences');
        
        $where_clause = "acr.agent_id = %d";
        $where_values = [$agent_id];
        
        if ($status !== 'all') {
            $where_clause .= " AND acr.relationship_status = %s";
            $where_values[] = $status;
        }
        
        $sql = $wpdb->prepare(
            "SELECT acr.*, u.user_email, u.display_name, u.user_registered,
                    acp.default_cc_all, acp.default_email_type,
                    (SELECT COUNT(*) FROM " . MLD_Saved_Search_Database::get_table_name('saved_searches') . " 
                     WHERE user_id = acr.client_id AND is_active = 1) as active_searches
             FROM {$rel_table} acr
             INNER JOIN {$wpdb->users} u ON acr.client_id = u.ID
             LEFT JOIN {$pref_table} acp ON acr.agent_id = acp.admin_id AND acr.client_id = acp.client_id
             WHERE {$where_clause}
             ORDER BY u.display_name ASC",
            ...$where_values
        );
        
        return $wpdb->get_results($sql, ARRAY_A);
    }
    
    /**
     * Create a new client (WordPress user)
     * 
     * @param array $data User data
     * @return int|WP_Error User ID on success, WP_Error on failure
     */
    public static function create_client($data) {
        // Validate required fields
        if (empty($data['email']) || empty($data['first_name']) || empty($data['last_name'])) {
            return new WP_Error('missing_required', 'First name, last name, and email are required.');
        }
        
        // Check if user already exists
        if (email_exists($data['email'])) {
            return new WP_Error('email_exists', 'A user with this email already exists.');
        }
        
        // Generate username from email
        $username = sanitize_user(current(explode('@', $data['email'])));
        $base_username = $username;
        $counter = 1;
        
        // Ensure unique username
        while (username_exists($username)) {
            $username = $base_username . $counter;
            $counter++;
        }
        
        // Create user data
        $userdata = [
            'user_login' => $username,
            'user_email' => $data['email'],
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'display_name' => $data['first_name'] . ' ' . $data['last_name'],
            'role' => 'subscriber'
        ];
        
        // Generate random password
        $userdata['user_pass'] = wp_generate_password();
        
        // Create the user
        $user_id = wp_insert_user($userdata);
        
        if (is_wp_error($user_id)) {
            return $user_id;
        }
        
        // Save phone number as user meta if provided
        if (!empty($data['phone'])) {
            update_user_meta($user_id, 'phone_number', $data['phone']);
        }
        
        // Send welcome email with password setup link
        $email_sent = true;
        if (!empty($data['send_notification'])) {
            $agent_id = !empty($data['agent_id']) ? $data['agent_id'] : get_current_user_id();
            $email_sent = self::send_client_welcome_email($user_id, $agent_id);
        }

        // Return user ID along with email status for API responses
        return array(
            'user_id' => $user_id,
            'email_sent' => $email_sent
        );
    }
    
    /**
     * Get all clients (subscribers)
     *
     * @param array $args Query arguments
     * @return array Array of client data
     */
    public static function get_all_clients($args = []) {
        global $wpdb;
        
        $defaults = [
            'assigned' => 'all', // all, assigned, unassigned
            'search' => '',
            'orderby' => 'display_name',
            'order' => 'ASC',
            'limit' => 20,
            'offset' => 0
        ];
        
        $args = wp_parse_args($args, $defaults);
        
        // Check if the database class exists
        if (!class_exists('MLD_Saved_Search_Database')) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD_Saved_Search_Database class not found');
            }
            // Use fallback table names
            $search_table = $wpdb->prefix . 'mld_saved_searches';
            $rel_table = $wpdb->prefix . 'mld_agent_client_relationships';
            $agent_table = $wpdb->prefix . 'mld_agent_profiles';
        } else {
            $search_table = MLD_Saved_Search_Database::get_table_name('saved_searches');
            $rel_table = MLD_Saved_Search_Database::get_table_name('agent_client_relationships');
            $agent_table = MLD_Saved_Search_Database::get_table_name('agent_profiles');
        }
        
        $where_clauses = ['1=1'];
        $where_values = [];
        
        if ($args['search']) {
            $where_clauses[] = "(u.user_email LIKE %s OR u.display_name LIKE %s)";
            $search_term = '%' . $wpdb->esc_like($args['search']) . '%';
            $where_values[] = $search_term;
            $where_values[] = $search_term;
        }
        
        if ($args['assigned'] === 'assigned') {
            $where_clauses[] = "acr.id IS NOT NULL AND acr.relationship_status = 'active'";
        } elseif ($args['assigned'] === 'unassigned') {
            $where_clauses[] = "(acr.id IS NULL OR acr.relationship_status != 'active')";
        }
        
        $where_sql = implode(' AND ', $where_clauses);

        // Get total count - include subscribers and bme_client roles
        $count_sql = "SELECT COUNT(DISTINCT u.ID)
                      FROM {$wpdb->users} u
                      INNER JOIN {$wpdb->usermeta} um ON u.ID = um.user_id AND um.meta_key = '{$wpdb->prefix}capabilities'
                      LEFT JOIN {$rel_table} acr ON u.ID = acr.client_id AND acr.relationship_status = 'active'
                      WHERE (um.meta_value LIKE %s OR um.meta_value LIKE %s) AND {$where_sql}";

        // Add both role patterns to the beginning of where values for count
        $count_where_values = array_merge(['%subscriber%', '%bme_client%'], $where_values);
        
        if (!empty($count_where_values)) {
            $count_sql = $wpdb->prepare($count_sql, $count_where_values);
        }
        
        $total = $wpdb->get_var($count_sql);
        
        // Get clients - include subscribers and bme_client roles, not just those with saved searches
        $sql = "SELECT u.ID as client_id, u.user_email, u.display_name, u.user_registered,
                       COUNT(DISTINCT s.id) as total_searches,
                       COUNT(DISTINCT CASE WHEN s.is_active = 1 THEN s.id END) as active_searches,
                       acr.agent_id, acr.relationship_status, acr.assigned_date,
                       ap.display_name as agent_name, ap.email as agent_email
                FROM {$wpdb->users} u
                INNER JOIN {$wpdb->usermeta} um ON u.ID = um.user_id AND um.meta_key = '{$wpdb->prefix}capabilities'
                LEFT JOIN {$search_table} s ON u.ID = s.user_id
                LEFT JOIN {$rel_table} acr ON u.ID = acr.client_id AND acr.relationship_status = 'active'
                LEFT JOIN {$agent_table} ap ON acr.agent_id = ap.user_id
                WHERE (um.meta_value LIKE %s OR um.meta_value LIKE %s) AND {$where_sql}
                GROUP BY u.ID
                ORDER BY u.{$args['orderby']} {$args['order']}
                LIMIT %d OFFSET %d";

        $query_values = array_merge(['%subscriber%', '%bme_client%'], $where_values, [$args['limit'], $args['offset']]);

        // Always prepare since we now always have values
        $prepared_sql = $wpdb->prepare($sql, $query_values);
        if ($prepared_sql === false || $prepared_sql === null || $prepared_sql === '') {
            return [
                'clients' => [],
                'total' => $total,
                'pages' => ceil($total / $args['limit']),
                'error' => 'SQL prepare failed'
            ];
        }

        $clients = $wpdb->get_results($prepared_sql, ARRAY_A);

        // Check for database errors
        if ($wpdb->last_error) {
            return [
                'clients' => [],
                'total' => 0,
                'pages' => 0,
                'error' => $wpdb->last_error
            ];
        }

        if (!is_array($clients)) {
            $clients = [];
        }
        
        return [
            'clients' => $clients,
            'total' => $total,
            'pages' => ceil($total / $args['limit'])
        ];
    }
    
    /**
     * Assign client to agent
     *
     * @param int $agent_id Agent ID
     * @param int $client_id Client ID
     * @param array $options Assignment options
     * @return bool Success
     */
    public static function assign_client($agent_id, $client_id, $options = []) {
        global $wpdb;
        
        $defaults = [
            'status' => 'active',
            'notes' => '',
            'email_type' => 'none' // none, cc, bcc
        ];
        
        $options = wp_parse_args($options, $defaults);
        
        $rel_table = MLD_Saved_Search_Database::get_table_name('agent_client_relationships');
        
        // Check if relationship exists
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$rel_table} WHERE agent_id = %d AND client_id = %d",
            $agent_id,
            $client_id
        ));
        
        if ($existing) {
            // Update existing
            $result = $wpdb->update(
                $rel_table,
                [
                    'relationship_status' => $options['status'],
                    'notes' => sanitize_textarea_field($options['notes'])
                ],
                ['agent_id' => $agent_id, 'client_id' => $client_id],
                ['%s', '%s'],
                ['%d', '%d']
            );
        } else {
            // Create new
            $result = $wpdb->insert(
                $rel_table,
                [
                    'agent_id' => $agent_id,
                    'client_id' => $client_id,
                    'relationship_status' => $options['status'],
                    'notes' => sanitize_textarea_field($options['notes'])
                ],
                ['%d', '%d', '%s', '%s']
            );
        }
        
        // Update email preferences if specified
        if ($result !== false && $options['email_type'] !== 'none') {
            self::update_client_email_preferences($agent_id, $client_id, [
                'default_email_type' => $options['email_type']
            ]);
        }
        
        return $result !== false;
    }
    
    /**
     * Unassign client from agent
     *
     * @param int $agent_id Agent WordPress user ID
     * @param int $client_id Client ID
     * @return bool Success (true if at least one row was updated)
     */
    public static function unassign_client($agent_id, $client_id) {
        global $wpdb;

        $agent_id = absint($agent_id);
        $client_id = absint($client_id);

        if ($agent_id <= 0 || $client_id <= 0) {
            return false;
        }

        $rel_table = MLD_Saved_Search_Database::get_table_name('agent_client_relationships');

        // Get agent's profile ID to check both IDs
        // NOTE: Legacy data may have agent_profile.id stored as agent_id,
        // while newer data uses the WordPress user ID. We check both.
        $agent_profile_id = null;
        $agent_profile = self::get_agent_by_user_id($agent_id);
        if ($agent_profile && isset($agent_profile->id)) {
            $agent_profile_id = (int) $agent_profile->id;
        }

        // Build query to match either agent_id format
        // This ensures we can unassign regardless of which ID format was used
        if ($agent_profile_id && $agent_profile_id !== $agent_id) {
            // Check both WordPress user ID and profile ID
            $rows_updated = $wpdb->query($wpdb->prepare(
                "UPDATE {$rel_table}
                 SET relationship_status = 'inactive'
                 WHERE client_id = %d
                   AND (agent_id = %d OR agent_id = %d)
                   AND relationship_status = 'active'",
                $client_id,
                $agent_id,
                $agent_profile_id
            ));
        } else {
            // Only check WordPress user ID
            $rows_updated = $wpdb->query($wpdb->prepare(
                "UPDATE {$rel_table}
                 SET relationship_status = 'inactive'
                 WHERE client_id = %d
                   AND agent_id = %d
                   AND relationship_status = 'active'",
                $client_id,
                $agent_id
            ));
        }

        // Return true only if at least one row was actually updated
        // Previously returned true even when 0 rows matched (silent failure)
        return $rows_updated > 0;
    }
    
    /**
     * Unassign all clients from an agent
     *
     * @param int $agent_id Agent WordPress user ID
     * @return bool Success (true if operation completed, even if 0 clients)
     */
    public static function unassign_all_clients($agent_id) {
        global $wpdb;

        $agent_id = absint($agent_id);

        if ($agent_id <= 0) {
            return false;
        }

        $rel_table = MLD_Saved_Search_Database::get_table_name('agent_client_relationships');

        // Get agent's profile ID to check both IDs (legacy compatibility)
        $agent_profile_id = null;
        $agent_profile = self::get_agent_by_user_id($agent_id);
        if ($agent_profile && isset($agent_profile->id)) {
            $agent_profile_id = (int) $agent_profile->id;
        }

        // Build query to match either agent_id format
        if ($agent_profile_id && $agent_profile_id !== $agent_id) {
            $result = $wpdb->query($wpdb->prepare(
                "UPDATE {$rel_table}
                 SET relationship_status = 'inactive'
                 WHERE (agent_id = %d OR agent_id = %d)
                   AND relationship_status = 'active'",
                $agent_id,
                $agent_profile_id
            ));
        } else {
            $result = $wpdb->query($wpdb->prepare(
                "UPDATE {$rel_table}
                 SET relationship_status = 'inactive'
                 WHERE agent_id = %d
                   AND relationship_status = 'active'",
                $agent_id
            ));
        }

        // Return true if query succeeded (even if 0 rows - agent may have no clients)
        return $result !== false;
    }
    
    /**
     * Update client email preferences
     *
     * @param int $admin_id Admin/Agent ID
     * @param int $client_id Client ID
     * @param array $preferences Preferences to update
     * @return bool Success
     */
    public static function update_client_email_preferences($admin_id, $client_id, $preferences) {
        global $wpdb;
        
        $table_name = MLD_Saved_Search_Database::get_table_name('admin_client_preferences');
        
        $defaults = [
            'default_cc_all' => 0,
            'default_email_type' => 'none',
            'can_view_searches' => 1
        ];
        
        $preferences = wp_parse_args($preferences, $defaults);
        
        // Check if preferences exist
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE admin_id = %d AND client_id = %d",
            $admin_id,
            $client_id
        ));
        
        $db_data = [
            'default_cc_all' => intval($preferences['default_cc_all']),
            'default_email_type' => sanitize_text_field($preferences['default_email_type']),
            'can_view_searches' => intval($preferences['can_view_searches'])
        ];
        
        if ($existing) {
            // Update
            return $wpdb->update(
                $table_name,
                $db_data,
                ['admin_id' => $admin_id, 'client_id' => $client_id],
                ['%d', '%s', '%d'],
                ['%d', '%d']
            ) !== false;
        } else {
            // Insert
            $db_data['admin_id'] = $admin_id;
            $db_data['client_id'] = $client_id;
            
            return $wpdb->insert(
                $table_name,
                $db_data,
                ['%d', '%d', '%d', '%s', '%d']
            ) !== false;
        }
    }
    
    /**
     * Get email recipients for a saved search notification
     *
     * @param int $search_id Saved search ID
     * @return array Array with 'to', 'cc', and 'bcc' email addresses
     */
    public static function get_notification_recipients($search_id) {
        global $wpdb;
        
        // Get search details
        $search = MLD_Saved_Searches::get_search($search_id);
        if (!$search) {
            return ['to' => '', 'cc' => [], 'bcc' => []];
        }

        // Convert to array if object
        if (is_object($search)) {
            $search = (array) $search;
        }

        // Get user email
        $user = get_user_by('id', $search['user_id']);
        if (!$user) {
            return ['to' => '', 'cc' => [], 'bcc' => []];
        }
        
        $recipients = [
            'to' => $user->user_email,
            'cc' => [],
            'bcc' => []
        ];
        
        // Get agents assigned to this client
        $rel_table = MLD_Saved_Search_Database::get_table_name('agent_client_relationships');
        $pref_table = MLD_Saved_Search_Database::get_table_name('admin_client_preferences');
        $agent_table = MLD_Saved_Search_Database::get_table_name('agent_profiles');
        
        $agents = $wpdb->get_results($wpdb->prepare(
            "SELECT acr.*, acp.default_email_type, ap.email as agent_email
             FROM {$rel_table} acr
             LEFT JOIN {$pref_table} acp ON acr.agent_id = acp.admin_id AND acr.client_id = acp.client_id
             LEFT JOIN {$agent_table} ap ON acr.agent_id = ap.user_id
             WHERE acr.client_id = %d AND acr.relationship_status = 'active'
             AND ap.is_active = 1",
            $search['user_id']
        ), ARRAY_A);
        
        foreach ($agents as $agent) {
            if (!empty($agent['agent_email'])) {
                $email_type = $agent['default_email_type'] ?? 'none';
                
                if ($email_type === 'cc') {
                    $recipients['cc'][] = $agent['agent_email'];
                } elseif ($email_type === 'bcc') {
                    $recipients['bcc'][] = $agent['agent_email'];
                }
            }
        }
        
        // Check if search was created by admin and add them
        if (!empty($search['created_by_admin'])) {
            $admin = get_user_by('id', $search['created_by_admin']);
            if ($admin && !in_array($admin->user_email, $recipients['cc']) && !in_array($admin->user_email, $recipients['bcc'])) {
                $recipients['cc'][] = $admin->user_email;
            }
        }
        
        return $recipients;
    }
    
    /**
     * Get all WordPress users who can be agents
     *
     * @return array Array of users
     */
    public static function get_potential_agents() {
        $users = get_users([
            'role__in' => ['administrator', 'editor', 'author'],
            'orderby' => 'display_name',
            'order' => 'ASC'
        ]);
        
        $agents = [];
        foreach ($users as $user) {
            $agents[] = [
                'ID' => $user->ID,
                'display_name' => $user->display_name,
                'user_email' => $user->user_email,
                'user_login' => $user->user_login
            ];
        }
        
        return $agents;
    }
    
    /**
     * Bulk assign clients to agent
     *
     * @param int $agent_id Agent ID
     * @param array $client_ids Array of client IDs
     * @param array $options Assignment options
     * @return array Results array with success/failure counts
     */
    public static function bulk_assign_clients($agent_id, $client_ids, $options = []) {
        $results = [
            'success' => 0,
            'failed' => 0,
            'errors' => []
        ];
        
        foreach ($client_ids as $client_id) {
            if (self::assign_client($agent_id, $client_id, $options)) {
                $results['success']++;
            } else {
                $results['failed']++;
                $results['errors'][] = "Failed to assign client ID: {$client_id}";
            }
        }
        
        return $results;
    }
    
    /**
     * Get agent statistics
     *
     * @param int $agent_id Agent ID
     * @return array Statistics array
     */
    public static function get_agent_stats($agent_id) {
        global $wpdb;

        $rel_table = MLD_Saved_Search_Database::get_table_name('agent_client_relationships');
        $search_table = MLD_Saved_Search_Database::get_table_name('saved_searches');
        $results_table = MLD_Saved_Search_Database::get_table_name('saved_search_results');

        // Get client counts
        $client_stats = $wpdb->get_row($wpdb->prepare(
            "SELECT
                COUNT(DISTINCT CASE WHEN relationship_status = 'active' THEN client_id END) as active_clients,
                COUNT(DISTINCT CASE WHEN relationship_status = 'inactive' THEN client_id END) as inactive_clients,
                COUNT(DISTINCT client_id) as total_clients
             FROM {$rel_table}
             WHERE agent_id = %d",
            $agent_id
        ), ARRAY_A);

        // Get search stats for agent's clients
        $search_stats = $wpdb->get_row($wpdb->prepare(
            "SELECT
                COUNT(DISTINCT s.id) as total_searches,
                COUNT(DISTINCT CASE WHEN s.is_active = 1 THEN s.id END) as active_searches,
                COUNT(DISTINCT sr.id) as notifications_sent
             FROM {$rel_table} acr
             INNER JOIN {$search_table} s ON acr.client_id = s.user_id
             LEFT JOIN {$results_table} sr ON s.id = sr.saved_search_id AND sr.notified_at IS NOT NULL
             WHERE acr.agent_id = %d AND acr.relationship_status = 'active'",
            $agent_id
        ), ARRAY_A);

        return array_merge($client_stats, $search_stats);
    }

    // =========================================================================
    // ONE-AGENT-PER-CLIENT METHODS (Added v6.32.0)
    // =========================================================================

    /**
     * Get the agent assigned to a client
     *
     * Enforces one-agent-per-client model. Returns the single active agent
     * assigned to this client, or null if none.
     *
     * @param int $client_id Client user ID
     * @return array|null Agent data array or null if no agent assigned
     */
    public static function get_client_agent($client_id) {
        global $wpdb;

        $rel_table = MLD_Saved_Search_Database::get_table_name('agent_client_relationships');
        $agent_table = MLD_Saved_Search_Database::get_table_name('agent_profiles');

        $result = $wpdb->get_row($wpdb->prepare(
            "SELECT ap.*, u.user_email, u.display_name as wp_display_name,
                    acr.relationship_status, acr.assigned_date, acr.notes as relationship_notes
             FROM {$rel_table} acr
             INNER JOIN {$agent_table} ap ON acr.agent_id = ap.user_id
             INNER JOIN {$wpdb->users} u ON ap.user_id = u.ID
             WHERE acr.client_id = %d
               AND acr.relationship_status = 'active'
               AND ap.is_active = 1
             ORDER BY acr.assigned_date DESC
             LIMIT 1",
            $client_id
        ), ARRAY_A);

        return $result ?: null;
    }

    /**
     * Assign an agent to a client (one-agent-per-client enforcement)
     *
     * This method enforces the one-agent-per-client model:
     * - If client already has an active agent, that assignment is deactivated first
     * - Then the new agent is assigned
     *
     * @param int   $agent_id  Agent user ID
     * @param int   $client_id Client user ID
     * @param array $options   Assignment options (notes, email_type)
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public static function assign_agent_to_client($agent_id, $client_id, $options = []) {
        global $wpdb;

        $agent_id = absint($agent_id);
        $client_id = absint($client_id);

        if ($agent_id <= 0 || $client_id <= 0) {
            return new WP_Error('invalid_ids', __('Invalid agent or client ID.', 'mls-listings-display'));
        }

        // Verify agent exists and is active
        $agent = self::get_agent($agent_id);
        if (!$agent || !$agent['is_active']) {
            return new WP_Error('agent_not_found', __('Agent not found or inactive.', 'mls-listings-display'));
        }

        // Verify client exists
        $client = get_userdata($client_id);
        if (!$client) {
            return new WP_Error('client_not_found', __('Client not found.', 'mls-listings-display'));
        }

        // Check if client already has an active agent (one-agent-per-client enforcement)
        $current_agent = self::get_client_agent($client_id);

        if ($current_agent) {
            // If same agent, just return success
            if ((int) $current_agent['user_id'] === $agent_id) {
                return true;
            }

            // Deactivate current assignment
            self::unassign_client($current_agent['user_id'], $client_id);

            /**
             * Fires when a client is reassigned from one agent to another
             *
             * @param int $client_id       Client user ID
             * @param int $old_agent_id    Previous agent user ID
             * @param int $new_agent_id    New agent user ID
             */
            do_action('mld_client_agent_changed', $client_id, $current_agent['user_id'], $agent_id);
        }

        // Now assign the new agent
        $result = self::assign_client($agent_id, $client_id, array_merge($options, ['status' => 'active']));

        if ($result) {
            /**
             * Fires when an agent is assigned to a client
             *
             * @param int   $agent_id  Agent user ID
             * @param int   $client_id Client user ID
             * @param array $options   Assignment options
             */
            do_action('mld_agent_assigned_to_client', $agent_id, $client_id, $options);
        }

        return $result ? true : new WP_Error('assignment_failed', __('Failed to assign agent.', 'mls-listings-display'));
    }

    /**
     * Get agent by WordPress user ID
     *
     * Returns agent profile as an object (for compatibility with User Type Manager).
     *
     * @param int $user_id WordPress user ID
     * @return object|null Agent profile object or null
     */
    public static function get_agent_by_user_id($user_id) {
        global $wpdb;

        $table_name = MLD_Saved_Search_Database::get_table_name('agent_profiles');

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE user_id = %d",
            $user_id
        ));
    }

    // =========================================================================
    // SNAB (APPOINTMENT BOOKING) INTEGRATION METHODS (Added v6.32.0)
    // =========================================================================

    /**
     * Link an agent profile to a SNAB staff member
     *
     * This enables the agent's appointments to use the existing SNAB booking system.
     *
     * @param int $agent_user_id Agent's WordPress user ID
     * @param int $snab_staff_id SNAB staff table ID
     * @return bool True on success, false on failure
     */
    public static function link_to_snab_staff($agent_user_id, $snab_staff_id) {
        global $wpdb;

        $agent_user_id = absint($agent_user_id);
        $snab_staff_id = absint($snab_staff_id);

        if ($agent_user_id <= 0 || $snab_staff_id <= 0) {
            return false;
        }

        // Verify SNAB staff exists
        $staff_table = $wpdb->prefix . 'snab_staff';
        $staff_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$staff_table} WHERE id = %d AND is_active = 1",
            $snab_staff_id
        ));

        if (!$staff_exists) {
            return false;
        }

        $agent_table = MLD_Saved_Search_Database::get_table_name('agent_profiles');

        $result = $wpdb->update(
            $agent_table,
            ['snab_staff_id' => $snab_staff_id],
            ['user_id' => $agent_user_id],
            ['%d'],
            ['%d']
        );

        return $result !== false;
    }

    /**
     * Get the SNAB staff member linked to an agent
     *
     * @param int $agent_user_id Agent's WordPress user ID
     * @return object|null SNAB staff object or null
     */
    public static function get_linked_snab_staff($agent_user_id) {
        global $wpdb;

        $agent_user_id = absint($agent_user_id);

        if ($agent_user_id <= 0) {
            return null;
        }

        $agent_table = MLD_Saved_Search_Database::get_table_name('agent_profiles');
        $staff_table = $wpdb->prefix . 'snab_staff';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT st.*
             FROM {$agent_table} ap
             INNER JOIN {$staff_table} st ON ap.snab_staff_id = st.id
             WHERE ap.user_id = %d AND st.is_active = 1",
            $agent_user_id
        ));
    }

    /**
     * Unlink an agent from their SNAB staff member
     *
     * @param int $agent_user_id Agent's WordPress user ID
     * @return bool True on success
     */
    public static function unlink_snab_staff($agent_user_id) {
        global $wpdb;

        $agent_table = MLD_Saved_Search_Database::get_table_name('agent_profiles');

        $result = $wpdb->update(
            $agent_table,
            ['snab_staff_id' => null],
            ['user_id' => absint($agent_user_id)],
            ['%d'],
            ['%d']
        );

        return $result !== false;
    }

    /**
     * Get all SNAB staff members available for linking
     *
     * Returns staff members who aren't already linked to an agent.
     *
     * @return array Array of available SNAB staff
     */
    public static function get_available_snab_staff() {
        global $wpdb;

        $agent_table = MLD_Saved_Search_Database::get_table_name('agent_profiles');
        $staff_table = $wpdb->prefix . 'snab_staff';

        // Check if SNAB table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '{$staff_table}'") !== $staff_table) {
            return [];
        }

        return $wpdb->get_results(
            "SELECT st.id, st.name, st.email, st.title
             FROM {$staff_table} st
             LEFT JOIN {$agent_table} ap ON st.id = ap.snab_staff_id
             WHERE st.is_active = 1
               AND ap.snab_staff_id IS NULL
             ORDER BY st.name ASC"
        );
    }

    /**
     * Get ALL active SNAB staff members
     *
     * Returns all active staff members including those already linked to agents.
     * Used for dropdown selections where we want to show all options.
     *
     * @return array Array of all active SNAB staff
     */
    public static function get_all_snab_staff() {
        global $wpdb;

        $agent_table = MLD_Saved_Search_Database::get_table_name('agent_profiles');
        $staff_table = $wpdb->prefix . 'snab_staff';

        // Check if SNAB table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '{$staff_table}'") !== $staff_table) {
            return [];
        }

        // Get all active staff with their linked agent info (if any)
        return $wpdb->get_results(
            "SELECT st.id, st.name, st.email, st.title, ap.user_id as linked_agent_id
             FROM {$staff_table} st
             LEFT JOIN {$agent_table} ap ON st.id = ap.snab_staff_id
             WHERE st.is_active = 1
             ORDER BY st.name ASC"
        );
    }

    // =========================================================================
    // AGENT PROFILE EXTENDED FIELDS (Added v6.32.0)
    // =========================================================================

    /**
     * Update agent's extended profile fields
     *
     * Updates the new fields added in v6.32.0 (title, social_links, service_areas,
     * email_signature, custom_greeting).
     *
     * @param int   $agent_user_id Agent's WordPress user ID
     * @param array $fields        Fields to update
     * @return bool True on success
     */
    public static function update_agent_extended_profile($agent_user_id, $fields) {
        global $wpdb;

        $agent_table = MLD_Saved_Search_Database::get_table_name('agent_profiles');

        $allowed_fields = ['title', 'social_links', 'service_areas', 'email_signature', 'custom_greeting'];
        $update_data = [];
        $update_format = [];

        foreach ($allowed_fields as $field) {
            if (isset($fields[$field])) {
                if ($field === 'social_links') {
                    // JSON encode social links
                    $update_data[$field] = is_array($fields[$field]) ? wp_json_encode($fields[$field]) : $fields[$field];
                } else {
                    $update_data[$field] = sanitize_textarea_field($fields[$field]);
                }
                $update_format[] = '%s';
            }
        }

        if (empty($update_data)) {
            return false;
        }

        $result = $wpdb->update(
            $agent_table,
            $update_data,
            ['user_id' => absint($agent_user_id)],
            $update_format,
            ['%d']
        );

        return $result !== false;
    }

    /**
     * Get agent's extended profile data
     *
     * Returns only the extended fields added in v6.32.0.
     *
     * @param int $agent_user_id Agent's WordPress user ID
     * @return array Extended profile fields
     */
    public static function get_agent_extended_profile($agent_user_id) {
        global $wpdb;

        $agent_table = MLD_Saved_Search_Database::get_table_name('agent_profiles');

        $result = $wpdb->get_row($wpdb->prepare(
            "SELECT title, social_links, service_areas, email_signature, custom_greeting, snab_staff_id
             FROM {$agent_table}
             WHERE user_id = %d",
            $agent_user_id
        ), ARRAY_A);

        if (!$result) {
            return [];
        }

        // Decode social_links JSON
        if (!empty($result['social_links'])) {
            $result['social_links'] = json_decode($result['social_links'], true) ?: [];
        } else {
            $result['social_links'] = [];
        }

        return $result;
    }

    /**
     * Get agent data formatted for REST API response
     *
     * Returns agent profile in a format suitable for mobile app consumption.
     *
     * @param int $agent_user_id Agent's WordPress user ID
     * @return array|null Agent data for API or null
     */
    public static function get_agent_for_api($agent_user_id) {
        $agent = self::get_agent($agent_user_id);

        if (!$agent) {
            return null;
        }

        $extended = self::get_agent_extended_profile($agent_user_id);
        $snab_staff = self::get_linked_snab_staff($agent_user_id);

        return [
            'id'              => (int) $agent['user_id'],
            'name'            => $agent['display_name'] ?: $agent['wp_display_name'],
            'title'           => $extended['title'] ?? null,
            'phone'           => $agent['phone'] ?: null,
            'email'           => $agent['email'] ?: $agent['user_email'],
            'photo_url'       => $agent['photo_url'] ?: null,
            'office_name'     => $agent['office_name'] ?: null,
            'office_address'  => $agent['office_address'] ?: null,
            'bio'             => $agent['bio'] ?: null,
            'license_number'  => $agent['license_number'] ?: null,
            'specialties'     => $agent['specialties'] ? array_filter(array_map('trim', explode(',', $agent['specialties']))) : [],
            'service_areas'   => $extended['service_areas'] ?? null,
            'social_links'    => $extended['social_links'] ?? [],
            'custom_greeting' => $extended['custom_greeting'] ?? null,
            'is_active'       => (bool) $agent['is_active'],
            'can_book_appointment' => $snab_staff !== null,
            'snab_staff_id'   => $snab_staff ? (int) $snab_staff->id : null,
        ];
    }

    /**
     * Get all active agents formatted for REST API
     *
     * @return array Array of agent data for API
     */
    public static function get_all_agents_for_api() {
        $agents = self::get_agents(['status' => 'active']);
        $result = [];

        foreach ($agents as $agent) {
            $api_agent = self::get_agent_for_api($agent['user_id']);
            if ($api_agent) {
                $result[] = $api_agent;
            }
        }

        return $result;
    }

    // =========================================================================
    // TEAM MEMBER SYNC METHODS (Added v6.32.0)
    // =========================================================================

    /**
     * Sync agent profile from Team Member CPT
     *
     * Called when a Team Member with a linked WordPress user is saved.
     * This method properly routes fields to the correct storage methods:
     * - Basic fields (name, email, phone, etc.) go to save_agent()
     * - Extended fields (title, social_links) go to update_agent_extended_profile()
     *
     * @param int   $post_id Team Member post ID
     * @param int   $user_id WordPress user ID to link
     * @param array $data    Team Member data array with keys:
     *                       - display_name: Agent name
     *                       - email: Email address
     *                       - phone: Phone number
     *                       - title: Position/title (e.g., "Real Estate Agent")
     *                       - bio: Biography text
     *                       - photo_url: Profile photo URL
     *                       - license_number: Real estate license
     *                       - social_links: Array with instagram, facebook, linkedin URLs
     *                       - is_active: Whether agent is active (1/0)
     * @return bool True on success, false on failure
     */
    public static function sync_from_team_member($post_id, $user_id, $data) {
        if (empty($user_id) || $user_id <= 0) {
            return false;
        }

        // Prepare basic agent data for save_agent()
        $basic_data = [
            'user_id'        => $user_id,
            'display_name'   => $data['display_name'] ?? '',
            'email'          => $data['email'] ?? '',
            'phone'          => $data['phone'] ?? '',
            'bio'            => $data['bio'] ?? '',
            'photo_url'      => $data['photo_url'] ?? '',
            'license_number' => $data['license_number'] ?? '',
            'is_active'      => isset($data['is_active']) ? intval($data['is_active']) : 1,
        ];

        // Save basic agent profile
        $result = self::save_agent($basic_data);

        if ($result === false) {
            return false;
        }

        // Prepare extended fields for update_agent_extended_profile()
        $extended_data = [];

        if (!empty($data['title'])) {
            $extended_data['title'] = $data['title'];
        }

        if (!empty($data['social_links'])) {
            // Accept either array or already-encoded JSON
            $extended_data['social_links'] = is_array($data['social_links'])
                ? $data['social_links']
                : json_decode($data['social_links'], true);
        }

        // Update extended profile if we have any extended fields
        if (!empty($extended_data)) {
            self::update_agent_extended_profile($user_id, $extended_data);
        }

        // Set user type to 'agent'
        if (class_exists('MLD_User_Type_Manager')) {
            $manager = MLD_User_Type_Manager::get_instance();
            $manager->set_user_type($user_id, 'agent');
        }

        // Store reverse lookup (user -> team member post)
        update_user_meta($user_id, '_bne_team_member_post_id', $post_id);

        /**
         * Fires when a Team Member is synced to an Agent Profile
         *
         * @param int   $user_id WordPress user ID (agent)
         * @param int   $post_id Team Member post ID
         * @param array $data    The synced data
         */
        do_action('mld_team_member_synced_to_agent', $user_id, $post_id, $data);

        return true;
    }

    /**
     * Get Team Member post ID for a WordPress user
     *
     * @param int $user_id WordPress user ID
     * @return int|null Team Member post ID or null if not linked
     */
    public static function get_team_member_for_user($user_id) {
        $post_id = get_user_meta($user_id, '_bne_team_member_post_id', true);
        return $post_id ? intval($post_id) : null;
    }

    /**
     * Check if a user is linked to a Team Member
     *
     * @param int $user_id WordPress user ID
     * @return bool True if linked
     */
    public static function is_user_linked_to_team_member($user_id) {
        return self::get_team_member_for_user($user_id) !== null;
    }

    /**
     * Send welcome email to new client with password setup link
     *
     * @param int $client_user_id Client user ID
     * @param int $agent_user_id Agent user ID
     * @return bool Success
     */
    public static function send_client_welcome_email($client_user_id, $agent_user_id) {
        $client = get_user_by('id', $client_user_id);
        if (!$client) {
            return false;
        }

        // Get agent info
        $agent = get_user_by('id', $agent_user_id);
        $agent_data = null;
        if ($agent) {
            $agent_profile = self::get_agent($agent_user_id);
            $agent_data = array(
                'name' => $agent_profile['display_name'] ?? $agent->display_name,
                'email' => $agent->user_email,
                'phone' => $agent_profile['phone'] ?? '',
                'photo_url' => $agent_profile['photo_url'] ?? '',
                'title' => $agent_profile['title'] ?? 'Real Estate Agent',
                'bio' => $agent_profile['bio'] ?? '',
            );
        }

        // Generate password reset key
        $reset_key = get_password_reset_key($client);
        if (is_wp_error($reset_key)) {
            // Fallback to wp_new_user_notification
            wp_new_user_notification($client_user_id, null, 'user');
            return false;
        }

        $reset_url = network_site_url("wp-login.php?action=rp&key=$reset_key&login=" . rawurlencode($client->user_login), 'login');

        // Build email HTML
        $subject = $agent_data
            ? sprintf('%s has invited you to BMN Boston', $agent_data['name'])
            : 'Welcome to BMN Boston - Set Up Your Account';

        $html = self::build_welcome_email_html($client, $agent_data, $reset_url);

        // Determine From email - agent's email or fallback to MLD notification settings
        $from_email = '';
        $from_name = 'BMN Boston';

        if ($agent_data && !empty($agent_data['email'])) {
            $from_email = $agent_data['email'];
            $from_name = $agent_data['name'];
        } else {
            // Fallback to MLD admin notification email setting
            $mld_settings = get_option('mld_admin_notification_settings');
            $from_email = isset($mld_settings['notification_email']) ? $mld_settings['notification_email'] : get_option('admin_email');
        }

        // Send email
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $from_name . ' <' . $from_email . '>',
        );

        $sent = wp_mail($client->user_email, $subject, $html, $headers);

        // Log the email
        if ($sent && class_exists('MLD_Email_Template_Engine')) {
            $engine = new MLD_Email_Template_Engine();
            $engine->record_send($client_user_id, 'agent_intro', null, array());
        }

        return $sent;
    }

    /**
     * Build welcome email HTML
     *
     * @param WP_User $client Client user object
     * @param array|null $agent_data Agent data array
     * @param string $reset_url Password reset URL
     * @return string Email HTML
     */
    private static function build_welcome_email_html($client, $agent_data, $reset_url) {
        $client_name = $client->first_name ?: 'there';
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
                            <h1 style="color:#ffffff;margin:0;font-size:28px;">BMN Boston</h1>
                            <p style="color:#94a3b8;margin:10px 0 0 0;font-size:14px;">Your Real Estate Partner</p>
                        </td>
                    </tr>';

        // Agent introduction
        if ($agent_data) {
            $html .= '
                    <!-- Agent Card -->
                    <tr>
                        <td style="padding:30px 40px;">
                            <h2 style="color:#1a1a1a;font-size:24px;margin:0 0 20px 0;text-align:center;">Hi ' . esc_html($client_name) . '!</h2>
                            <p style="color:#4a4a4a;font-size:16px;margin:0 0 25px 0;line-height:1.6;text-align:center;">
                                I\'m excited to invite you to our brand new real estate platform where you can search properties, schedule tours, ask questions, learn about real estate, manage your transaction, and much more! Please create your password by clicking the link below to get started.
                            </p>

                            <div style="background:#f8f9fa;border-radius:10px;padding:25px;text-align:center;margin-bottom:25px;">
                                ' . (!empty($agent_data['photo_url']) ? '<img src="' . esc_url($agent_data['photo_url']) . '" style="width:80px;height:80px;border-radius:50%;margin-bottom:15px;object-fit:cover;">' : '') . '
                                <h3 style="margin:0 0 5px 0;color:#1a1a1a;">' . esc_html($agent_data['name']) . '</h3>
                                <p style="margin:0 0 10px 0;color:#6b7280;font-size:14px;">' . esc_html($agent_data['title']) . '</p>
                                ' . (!empty($agent_data['phone']) ? '<p style="margin:0;color:#1e3a5f;font-size:14px;">📞 ' . esc_html($agent_data['phone']) . '</p>' : '') . '
                            </div>
                        </td>
                    </tr>';
        } else {
            $html .= '
                    <!-- Welcome -->
                    <tr>
                        <td style="padding:30px 40px;">
                            <h2 style="color:#1a1a1a;font-size:24px;margin:0 0 20px 0;text-align:center;">Welcome, ' . esc_html($client_name) . '!</h2>
                            <p style="color:#4a4a4a;font-size:16px;margin:0 0 25px 0;line-height:1.6;text-align:center;">
                                Your account has been created. Set up your password to start exploring properties.
                            </p>
                        </td>
                    </tr>';
        }

        // Password setup section
        $html .= '
                    <!-- Password Setup -->
                    <tr>
                        <td style="padding:0 40px 30px;">
                            <div style="background:#fef3c7;border:1px solid #f59e0b;border-radius:8px;padding:20px;margin-bottom:25px;">
                                <h4 style="margin:0 0 10px 0;color:#92400e;">🔐 Set Up Your Password</h4>
                                <p style="margin:0 0 15px 0;color:#78350f;font-size:14px;">Click the button below to create your password and access your account.</p>
                                <a href="' . esc_url($reset_url) . '" style="display:inline-block;background:#f59e0b;color:#ffffff;padding:12px 25px;border-radius:6px;text-decoration:none;font-weight:600;font-size:14px;">Set My Password</a>
                            </div>

                            <div style="text-align:center;">
                                <h4 style="margin:0 0 15px 0;color:#1a1a1a;">What You Can Do:</h4>
                                <ul style="text-align:left;color:#4a4a4a;font-size:15px;line-height:1.8;padding-left:20px;margin:0;">
                                    <li>Save your favorite properties</li>
                                    <li>Create saved searches with instant alerts</li>
                                    <li>Schedule property showings</li>
                                    <li>Download our iOS app for on-the-go access</li>
                                </ul>
                            </div>
                        </td>
                    </tr>

                    <!-- CTA -->
                    <tr>
                        <td style="padding:0 40px 30px;text-align:center;">
                            <a href="' . esc_url($site_url) . '" style="display:inline-block;background:#1e3a5f;color:#ffffff;padding:14px 30px;border-radius:8px;text-decoration:none;font-weight:600;font-size:16px;">Browse Properties</a>
                        </td>
                    </tr>

                    <!-- Unified Footer with Social Links and App Store -->
                    <tr>
                        <td>';

        // Add unified footer with social links and App Store promotion
        if (class_exists('MLD_Email_Utilities')) {
            $html .= MLD_Email_Utilities::get_unified_footer();
        } else {
            $html .= '
                            <div style="background:#f8f9fa;padding:20px 40px;text-align:center;">
                                <p style="margin:0;color:#6b7280;font-size:12px;">
                                    BMN Boston | <a href="' . esc_url($site_url) . '" style="color:#1e3a5f;">bmnboston.com</a>
                                </p>
                            </div>';
        }

        $html .= '
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>';

        return $html;
    }

    /**
     * Initialize WordPress email hooks
     * Call this from plugin init
     */
    public static function init_password_email_hooks() {
        add_filter('password_change_email', array(__CLASS__, 'customize_password_change_email'), 10, 3);
        add_filter('wp_new_user_notification_email_admin', array(__CLASS__, 'customize_admin_new_user_email'), 10, 3);
        add_filter('email_change_email', array(__CLASS__, 'customize_email_change_email'), 10, 3);
    }

    /**
     * Customize the admin notification email when a new user registers
     *
     * @param array $wp_new_user_notification_email Admin notification email data
     * @param WP_User $user New user object
     * @param string $blogname Site name
     * @return array Modified email data
     * @since 6.63.0
     */
    public static function customize_admin_new_user_email($wp_new_user_notification_email, $user, $blogname) {
        // Build custom HTML email for admin
        $html = self::build_admin_new_user_email_html($user, $blogname);

        // Update the email
        $wp_new_user_notification_email['message'] = $html;
        $wp_new_user_notification_email['subject'] = sprintf('[%s] New User Registration: %s', $blogname, $user->user_email);
        $wp_new_user_notification_email['headers'] = array(
            'Content-Type: text/html; charset=UTF-8',
        );

        // Use site default for admin emails
        if (class_exists('MLD_Email_Utilities')) {
            $from_header = MLD_Email_Utilities::get_from_header(null);
            $wp_new_user_notification_email['headers'][] = 'From: ' . $from_header;
        }

        return $wp_new_user_notification_email;
    }

    /**
     * Build admin new user notification email HTML
     *
     * @param WP_User $user New user object
     * @param string $blogname Site name
     * @return string Email HTML
     * @since 6.63.0
     */
    private static function build_admin_new_user_email_html($user, $blogname) {
        $user_name = trim($user->first_name . ' ' . $user->last_name);
        if (empty($user_name)) {
            $user_name = $user->display_name;
        }
        if (empty($user_name)) {
            $user_name = $user->user_login;
        }

        $site_url = home_url();
        $user_edit_url = admin_url('user-edit.php?user_id=' . $user->ID);
        $dashboard_url = admin_url('admin.php?page=mld-client-management');

        // Check if user has an assigned agent
        $agent_info = '';
        $agent = self::get_client_agent($user->ID);
        if ($agent) {
            $agent_user = get_user_by('id', $agent);
            if ($agent_user) {
                $agent_profile = self::get_agent($agent);
                $agent_name = $agent_profile['display_name'] ?? $agent_user->display_name;
                $agent_info = '<tr>
                    <td style="color:#6b7280;font-size:14px;padding:5px 0;"><strong>Assigned Agent:</strong></td>
                    <td style="color:#4a4a4a;font-size:14px;padding:5px 0;">' . esc_html($agent_name) . '</td>
                </tr>';
            }
        }

        $phone = get_user_meta($user->ID, 'phone', true);
        $phone_row = '';
        if ($phone) {
            $phone_row = '<tr>
                <td style="color:#6b7280;font-size:14px;padding:5px 0;"><strong>Phone:</strong></td>
                <td style="color:#4a4a4a;font-size:14px;padding:5px 0;">' . esc_html($phone) . '</td>
            </tr>';
        }

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
                            <h1 style="color:#ffffff;margin:0;font-size:28px;">' . esc_html($blogname) . '</h1>
                            <p style="color:#94a3b8;margin:10px 0 0 0;font-size:14px;">New User Registration</p>
                        </td>
                    </tr>

                    <!-- Content -->
                    <tr>
                        <td style="padding:30px 40px;">
                            <h2 style="color:#1a1a1a;font-size:22px;margin:0 0 20px 0;">New User Registered</h2>

                            <p style="color:#4a4a4a;font-size:16px;margin:0 0 25px 0;line-height:1.6;">
                                A new user has registered on your site. Here are their details:
                            </p>

                            <!-- User Info Card -->
                            <div style="background:#f8fafc;border-radius:8px;padding:20px;margin-bottom:25px;border-left:4px solid #1e3a5f;">
                                <h3 style="color:#1e3a5f;font-size:18px;margin:0 0 15px 0;">' . esc_html($user_name) . '</h3>
                                <table style="width:100%;">
                                    <tr>
                                        <td style="color:#6b7280;font-size:14px;padding:5px 0;"><strong>Email:</strong></td>
                                        <td style="color:#4a4a4a;font-size:14px;padding:5px 0;">
                                            <a href="mailto:' . esc_attr($user->user_email) . '" style="color:#1e3a5f;">' . esc_html($user->user_email) . '</a>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td style="color:#6b7280;font-size:14px;padding:5px 0;"><strong>Username:</strong></td>
                                        <td style="color:#4a4a4a;font-size:14px;padding:5px 0;">' . esc_html($user->user_login) . '</td>
                                    </tr>
                                    ' . $phone_row . '
                                    ' . $agent_info . '
                                    <tr>
                                        <td style="color:#6b7280;font-size:14px;padding:5px 0;"><strong>Registered:</strong></td>
                                        <td style="color:#4a4a4a;font-size:14px;padding:5px 0;">' . esc_html(current_time('F j, Y \a\t g:i A')) . '</td>
                                    </tr>
                                </table>
                            </div>

                            <!-- CTA Buttons -->
                            <div style="text-align:center;margin:30px 0;">
                                <a href="' . esc_url($user_edit_url) . '" style="display:inline-block;background:#1e3a5f;color:#ffffff;text-decoration:none;padding:14px 30px;border-radius:8px;font-size:16px;font-weight:600;margin:0 10px;">
                                    Edit User
                                </a>
                                <a href="' . esc_url($dashboard_url) . '" style="display:inline-block;background:#f8fafc;color:#1e3a5f;text-decoration:none;padding:14px 30px;border-radius:8px;font-size:16px;font-weight:600;border:2px solid #1e3a5f;margin:0 10px;">
                                    Client Dashboard
                                </a>
                            </div>
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="background:#f8f9fa;padding:20px 40px;text-align:center;">
                            <p style="margin:0;color:#6b7280;font-size:12px;">
                                This is an automated notification from ' . esc_html($blogname) . '<br>
                                <a href="' . esc_url($site_url) . '" style="color:#1e3a5f;">' . esc_url($site_url) . '</a>
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>';

        return $html;
    }

    /**
     * Customize the email change confirmation email
     *
     * @param array $email_change_email Email data array
     * @param WP_User $user User object
     * @param array $userdata User data including new email
     * @return array Modified email data
     * @since 6.63.0
     */
    public static function customize_email_change_email($email_change_email, $user, $userdata) {
        // Build custom HTML email
        $html = self::build_email_change_email_html($user, $userdata);

        // Get agent data if available for dynamic from
        $agent_data = null;
        $agent_id = self::get_client_agent($user->ID);
        if ($agent_id) {
            $agent = get_user_by('id', $agent_id);
            if ($agent) {
                $agent_profile = self::get_agent($agent_id);
                $agent_data = array(
                    'name' => $agent_profile['display_name'] ?? $agent->display_name,
                    'email' => $agent->user_email,
                );
            }
        }

        $email_change_email['message'] = $html;
        $email_change_email['subject'] = 'Email Address Changed - BMN Boston';
        $email_change_email['headers'] = array(
            'Content-Type: text/html; charset=UTF-8',
        );

        // Use agent's email as From if available
        if ($agent_data && !empty($agent_data['email'])) {
            $email_change_email['headers'][] = 'From: ' . $agent_data['name'] . ' <' . $agent_data['email'] . '>';
        } elseif (class_exists('MLD_Email_Utilities')) {
            $email_change_email['headers'][] = 'From: ' . MLD_Email_Utilities::get_from_header($user->ID);
        }

        return $email_change_email;
    }

    /**
     * Build email change notification email HTML
     *
     * @param WP_User $user User object
     * @param array $userdata User data with new email
     * @return string Email HTML
     * @since 6.63.0
     */
    private static function build_email_change_email_html($user, $userdata) {
        $user_name = $user->first_name ?: 'there';
        $old_email = $user->user_email;
        $new_email = isset($userdata['user_email']) ? $userdata['user_email'] : '';
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
                            <h1 style="color:#ffffff;margin:0;font-size:28px;">BMN Boston</h1>
                            <p style="color:#94a3b8;margin:10px 0 0 0;font-size:14px;">Account Update</p>
                        </td>
                    </tr>

                    <!-- Content -->
                    <tr>
                        <td style="padding:30px 40px;">
                            <h2 style="color:#1a1a1a;font-size:22px;margin:0 0 20px 0;">Hi ' . esc_html($user_name) . ',</h2>

                            <p style="color:#4a4a4a;font-size:16px;margin:0 0 25px 0;line-height:1.6;">
                                This notice confirms that the email address on your BMN Boston account has been changed.
                            </p>

                            <!-- Email Change Info -->
                            <div style="background:#f8fafc;border-radius:8px;padding:20px;margin-bottom:25px;border-left:4px solid #1e3a5f;">
                                <table style="width:100%;">
                                    <tr>
                                        <td style="color:#6b7280;font-size:14px;padding:8px 0;"><strong>Previous Email:</strong></td>
                                        <td style="color:#4a4a4a;font-size:14px;padding:8px 0;">' . esc_html($old_email) . '</td>
                                    </tr>
                                    <tr>
                                        <td style="color:#6b7280;font-size:14px;padding:8px 0;"><strong>New Email:</strong></td>
                                        <td style="color:#4a4a4a;font-size:14px;padding:8px 0;">' . esc_html($new_email) . '</td>
                                    </tr>
                                    <tr>
                                        <td style="color:#6b7280;font-size:14px;padding:8px 0;"><strong>Changed:</strong></td>
                                        <td style="color:#4a4a4a;font-size:14px;padding:8px 0;">' . esc_html(current_time('F j, Y \a\t g:i A')) . '</td>
                                    </tr>
                                </table>
                            </div>

                            <!-- Security Notice -->
                            <div style="background:#fef3c7;border-radius:8px;padding:15px;margin-bottom:25px;border-left:4px solid #f59e0b;">
                                <p style="color:#92400e;font-size:14px;margin:0;line-height:1.5;">
                                    <strong>Didn\'t make this change?</strong><br>
                                    If you did not change your email address, your account may have been compromised.
                                    Please contact us immediately at <a href="mailto:' . esc_attr(get_option('admin_email')) . '" style="color:#1e3a5f;">support</a>.
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
                                BMN Boston | <a href="' . esc_url($site_url) . '" style="color:#1e3a5f;">bmnboston.com</a>
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
     * Customize the password change confirmation email
     *
     * @param array $pass_change_email Email data array
     * @param WP_User $user User object
     * @param string $userdata User data
     * @return array Modified email data
     */
    public static function customize_password_change_email($pass_change_email, $user, $userdata) {
        // Check if this is a client (assigned to an agent)
        $agent_id = self::get_client_agent($user->ID);

        // Get agent data if available
        $agent_data = null;
        if ($agent_id) {
            $agent = get_user_by('id', $agent_id);
            if ($agent) {
                $agent_profile = self::get_agent($agent_id);
                $agent_data = array(
                    'name' => $agent_profile['display_name'] ?? $agent->display_name,
                    'email' => $agent->user_email,
                    'phone' => $agent_profile['phone'] ?? '',
                    'photo_url' => $agent_profile['photo_url'] ?? '',
                    'title' => $agent_profile['title'] ?? 'Real Estate Agent',
                );
            }
        }

        // Build custom HTML email
        $html = self::build_password_confirmed_email_html($user, $agent_data);

        // Customize the email
        $pass_change_email['message'] = $html;
        $pass_change_email['subject'] = 'Your BMN Boston Account is Ready!';
        $pass_change_email['headers'] = array(
            'Content-Type: text/html; charset=UTF-8',
        );

        // Use agent's email as From if available
        if ($agent_data && !empty($agent_data['email'])) {
            $pass_change_email['headers'][] = 'From: ' . $agent_data['name'] . ' <' . $agent_data['email'] . '>';
        } else {
            $mld_settings = get_option('mld_admin_notification_settings');
            $from_email = isset($mld_settings['notification_email']) ? $mld_settings['notification_email'] : get_option('admin_email');
            $pass_change_email['headers'][] = 'From: BMN Boston <' . $from_email . '>';
        }

        return $pass_change_email;
    }

    /**
     * Build password confirmed email HTML
     *
     * @param WP_User $user User object
     * @param array|null $agent_data Agent data array
     * @return string Email HTML
     */
    private static function build_password_confirmed_email_html($user, $agent_data) {
        $client_name = $user->first_name ?: 'there';
        $site_url = home_url();
        $login_url = wp_login_url();
        $dashboard_url = home_url('/my-dashboard/');

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
                            <h1 style="color:#ffffff;margin:0;font-size:28px;">BMN Boston</h1>
                            <p style="color:#94a3b8;margin:10px 0 0 0;font-size:14px;">Your Real Estate Partner</p>
                        </td>
                    </tr>

                    <!-- Success Message -->
                    <tr>
                        <td style="padding:30px 40px;">
                            <div style="text-align:center;margin-bottom:25px;">
                                <div style="display:inline-block;background:#10b981;width:60px;height:60px;border-radius:50%;line-height:60px;margin-bottom:15px;">
                                    <span style="color:#ffffff;font-size:30px;">✓</span>
                                </div>
                                <h2 style="color:#1a1a1a;font-size:24px;margin:0;">You\'re All Set, ' . esc_html($client_name) . '!</h2>
                            </div>

                            <p style="color:#4a4a4a;font-size:16px;margin:0 0 25px 0;line-height:1.6;text-align:center;">
                                Your password has been successfully created. You now have full access to your personalized real estate dashboard.
                            </p>';

        // Add agent card if available
        if ($agent_data) {
            $html .= '
                            <div style="background:#f8f9fa;border-radius:10px;padding:20px;text-align:center;margin-bottom:25px;">
                                <p style="color:#6b7280;font-size:14px;margin:0 0 10px 0;">Your Agent</p>
                                ' . (!empty($agent_data['photo_url']) ? '<img src="' . esc_url($agent_data['photo_url']) . '" style="width:60px;height:60px;border-radius:50%;margin-bottom:10px;object-fit:cover;">' : '') . '
                                <h3 style="margin:0 0 5px 0;color:#1a1a1a;font-size:16px;">' . esc_html($agent_data['name']) . '</h3>
                                <p style="margin:0;color:#6b7280;font-size:13px;">' . esc_html($agent_data['title']) . '</p>
                                ' . (!empty($agent_data['phone']) ? '<p style="margin:5px 0 0 0;color:#1e3a5f;font-size:13px;">📞 ' . esc_html($agent_data['phone']) . '</p>' : '') . '
                            </div>';
        }

        $html .= '
                        </td>
                    </tr>

                    <!-- What You Can Do -->
                    <tr>
                        <td style="padding:0 40px 30px;">
                            <h4 style="margin:0 0 15px 0;color:#1a1a1a;font-size:16px;text-align:center;">What You Can Do Now:</h4>
                            <table cellpadding="0" cellspacing="0" width="100%">
                                <tr>
                                    <td style="padding:10px 0;">
                                        <table cellpadding="0" cellspacing="0">
                                            <tr>
                                                <td style="width:40px;vertical-align:top;">
                                                    <span style="font-size:20px;">🏠</span>
                                                </td>
                                                <td style="color:#4a4a4a;font-size:14px;line-height:1.5;">
                                                    <strong>Search Properties</strong><br>
                                                    Browse thousands of listings with advanced filters
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:10px 0;">
                                        <table cellpadding="0" cellspacing="0">
                                            <tr>
                                                <td style="width:40px;vertical-align:top;">
                                                    <span style="font-size:20px;">❤️</span>
                                                </td>
                                                <td style="color:#4a4a4a;font-size:14px;line-height:1.5;">
                                                    <strong>Save Favorites</strong><br>
                                                    Keep track of properties you love
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:10px 0;">
                                        <table cellpadding="0" cellspacing="0">
                                            <tr>
                                                <td style="width:40px;vertical-align:top;">
                                                    <span style="font-size:20px;">🔔</span>
                                                </td>
                                                <td style="color:#4a4a4a;font-size:14px;line-height:1.5;">
                                                    <strong>Get Instant Alerts</strong><br>
                                                    Be first to know about new listings
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:10px 0;">
                                        <table cellpadding="0" cellspacing="0">
                                            <tr>
                                                <td style="width:40px;vertical-align:top;">
                                                    <span style="font-size:20px;">📅</span>
                                                </td>
                                                <td style="color:#4a4a4a;font-size:14px;line-height:1.5;">
                                                    <strong>Schedule Showings</strong><br>
                                                    Book property tours with one click
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <!-- CTAs -->
                    <tr>
                        <td style="padding:0 40px 30px;text-align:center;">
                            <a href="' . esc_url($dashboard_url) . '" style="display:inline-block;background:#1e3a5f;color:#ffffff;padding:14px 30px;border-radius:8px;text-decoration:none;font-weight:600;font-size:16px;margin-right:10px;">View My Dashboard</a>
                            <a href="' . esc_url($site_url) . '/search/" style="display:inline-block;background:#0891b2;color:#ffffff;padding:14px 30px;border-radius:8px;text-decoration:none;font-weight:600;font-size:16px;">Browse Properties</a>
                        </td>
                    </tr>

                    <!-- App Download -->
                    <tr>
                        <td style="padding:0 40px 30px;text-align:center;">
                            <p style="color:#6b7280;font-size:14px;margin:0 0 15px 0;">📱 Download our iOS app for the best experience</p>
                            <a href="https://apps.apple.com/app/bmn-boston" style="display:inline-block;">
                                <img src="https://developer.apple.com/assets/elements/badges/download-on-the-app-store.svg" alt="Download on the App Store" style="height:40px;">
                            </a>
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="background:#f8f9fa;padding:20px 40px;text-align:center;">
                            <p style="margin:0 0 10px 0;color:#6b7280;font-size:12px;">
                                Questions? Reply to this email or contact your agent directly.
                            </p>
                            <p style="margin:0;color:#6b7280;font-size:12px;">
                                BMN Boston | <a href="' . esc_url($site_url) . '" style="color:#1e3a5f;">bmnboston.com</a>
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>';

        return $html;
    }

    /**
     * Send notification email to agent when a client is assigned to them
     *
     * @param int $agent_id Agent's user ID
     * @param int $client_id Client's user ID
     * @param array $options Assignment options
     * @since 6.63.0
     */
    public static function send_agent_assignment_notification($agent_id, $client_id, $options = []) {
        // Get agent profile
        $agent_profile = self::get_agent($agent_id);
        if (!$agent_profile) {
            return false;
        }

        $agent_user = get_user_by('id', $agent_id);
        if (!$agent_user) {
            return false;
        }

        // Get client data
        $client = get_user_by('id', $client_id);
        if (!$client) {
            return false;
        }

        $client_name = trim($client->first_name . ' ' . $client->last_name);
        if (empty($client_name)) {
            $client_name = $client->display_name;
        }

        $agent_name = $agent_profile['display_name'] ?? $agent_user->display_name;
        $agent_email = $agent_profile['email'] ?? $agent_user->user_email;

        // Build email content
        $subject = "New Client Assigned: {$client_name}";
        $message = self::build_agent_assignment_email_html($agent_name, $client, $options);

        // Send email using site default from address (agent notifications come from site)
        $headers = [];
        if (class_exists('MLD_Email_Utilities')) {
            $headers = MLD_Email_Utilities::get_email_headers(null); // null = site default
        } else {
            $headers = [
                'Content-Type: text/html; charset=UTF-8',
                'From: BMN Boston <' . get_option('admin_email') . '>',
            ];
        }

        return wp_mail($agent_email, $subject, $message, $headers);
    }

    /**
     * Build agent assignment notification email HTML
     *
     * @param string $agent_name Agent's name
     * @param WP_User $client Client user object
     * @param array $options Assignment options
     * @return string Email HTML
     * @since 6.63.0
     */
    private static function build_agent_assignment_email_html($agent_name, $client, $options = []) {
        $client_name = trim($client->first_name . ' ' . $client->last_name);
        if (empty($client_name)) {
            $client_name = $client->display_name;
        }

        $site_url = home_url();
        $dashboard_url = admin_url('admin.php?page=mld-client-management');

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
                            <h1 style="color:#ffffff;margin:0;font-size:28px;">BMN Boston</h1>
                            <p style="color:#94a3b8;margin:10px 0 0 0;font-size:14px;">New Client Assignment</p>
                        </td>
                    </tr>

                    <!-- Content -->
                    <tr>
                        <td style="padding:30px 40px;">
                            <h2 style="color:#1a1a1a;font-size:22px;margin:0 0 20px 0;">Hello ' . esc_html($agent_name) . ',</h2>

                            <p style="color:#4a4a4a;font-size:16px;margin:0 0 25px 0;line-height:1.6;">
                                A new client has been assigned to you. Here are their details:
                            </p>

                            <!-- Client Info Card -->
                            <div style="background:#f8fafc;border-radius:8px;padding:20px;margin-bottom:25px;border-left:4px solid #1e3a5f;">
                                <h3 style="color:#1e3a5f;font-size:18px;margin:0 0 15px 0;">' . esc_html($client_name) . '</h3>
                                <table style="width:100%;">
                                    <tr>
                                        <td style="color:#6b7280;font-size:14px;padding:5px 0;">
                                            <strong>Email:</strong>
                                        </td>
                                        <td style="color:#4a4a4a;font-size:14px;padding:5px 0;">
                                            <a href="mailto:' . esc_attr($client->user_email) . '" style="color:#1e3a5f;">' . esc_html($client->user_email) . '</a>
                                        </td>
                                    </tr>';

        // Add phone if available
        $phone = get_user_meta($client->ID, 'phone', true);
        if ($phone) {
            $html .= '
                                    <tr>
                                        <td style="color:#6b7280;font-size:14px;padding:5px 0;">
                                            <strong>Phone:</strong>
                                        </td>
                                        <td style="color:#4a4a4a;font-size:14px;padding:5px 0;">
                                            <a href="tel:' . esc_attr($phone) . '" style="color:#1e3a5f;">' . esc_html($phone) . '</a>
                                        </td>
                                    </tr>';
        }

        $html .= '
                                    <tr>
                                        <td style="color:#6b7280;font-size:14px;padding:5px 0;">
                                            <strong>Assigned:</strong>
                                        </td>
                                        <td style="color:#4a4a4a;font-size:14px;padding:5px 0;">
                                            ' . esc_html(current_time('F j, Y \a\t g:i A')) . '
                                        </td>
                                    </tr>
                                </table>
                            </div>

                            <p style="color:#4a4a4a;font-size:16px;margin:0 0 25px 0;line-height:1.6;">
                                We recommend reaching out to introduce yourself and learn more about their home search needs.
                            </p>

                            <!-- CTA Button -->
                            <div style="text-align:center;margin:30px 0;">
                                <a href="' . esc_url($dashboard_url) . '" style="display:inline-block;background:#1e3a5f;color:#ffffff;text-decoration:none;padding:14px 30px;border-radius:8px;font-size:16px;font-weight:600;">
                                    View Client Dashboard
                                </a>
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
                                BMN Boston | <a href="' . esc_url($site_url) . '" style="color:#1e3a5f;">bmnboston.com</a>
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

    // =========================================================================
    // DATA CLEANUP UTILITIES (Added for relationship integrity)
    // =========================================================================

    /**
     * Clean up orphaned agent-client relationships
     *
     * This function identifies and fixes relationships where:
     * 1. The client WordPress user no longer exists
     * 2. The agent WordPress user no longer exists
     * 3. Relationships that should be inactive but are still active
     *
     * @param bool $dry_run If true, only report what would be cleaned up without making changes
     * @return array Results of the cleanup operation
     */
    public static function cleanup_orphaned_relationships($dry_run = true) {
        global $wpdb;

        $rel_table = MLD_Saved_Search_Database::get_table_name('agent_client_relationships');
        $profiles_table = MLD_Saved_Search_Database::get_table_name('agent_profiles');

        $results = [
            'dry_run' => $dry_run,
            'orphaned_clients' => [],
            'orphaned_agents' => [],
            'mismatched_agent_ids' => [],
            'total_cleaned' => 0,
        ];

        // 1. Find relationships where the client user no longer exists
        $orphaned_clients = $wpdb->get_results(
            "SELECT r.id, r.agent_id, r.client_id, r.relationship_status
             FROM {$rel_table} r
             LEFT JOIN {$wpdb->users} u ON r.client_id = u.ID
             WHERE u.ID IS NULL
               AND r.relationship_status = 'active'"
        );

        foreach ($orphaned_clients as $rel) {
            $results['orphaned_clients'][] = [
                'relationship_id' => $rel->id,
                'agent_id' => $rel->agent_id,
                'client_id' => $rel->client_id,
            ];
        }

        // 2. Find relationships where the agent user no longer exists
        $orphaned_agents = $wpdb->get_results(
            "SELECT r.id, r.agent_id, r.client_id, r.relationship_status
             FROM {$rel_table} r
             LEFT JOIN {$wpdb->users} u ON r.agent_id = u.ID
             LEFT JOIN {$profiles_table} p ON r.agent_id = p.id
             WHERE u.ID IS NULL AND p.id IS NULL
               AND r.relationship_status = 'active'"
        );

        foreach ($orphaned_agents as $rel) {
            $results['orphaned_agents'][] = [
                'relationship_id' => $rel->id,
                'agent_id' => $rel->agent_id,
                'client_id' => $rel->client_id,
            ];
        }

        // 3. Find relationships where agent_id is profile ID instead of user ID
        // These won't match unassign operations that use user_id
        $mismatched = $wpdb->get_results(
            "SELECT r.id, r.agent_id, r.client_id, p.user_id as correct_agent_user_id
             FROM {$rel_table} r
             INNER JOIN {$profiles_table} p ON r.agent_id = p.id
             WHERE r.agent_id != p.user_id
               AND r.relationship_status = 'active'"
        );

        foreach ($mismatched as $rel) {
            $results['mismatched_agent_ids'][] = [
                'relationship_id' => $rel->id,
                'stored_agent_id' => $rel->agent_id,
                'correct_user_id' => $rel->correct_agent_user_id,
                'client_id' => $rel->client_id,
            ];
        }

        // Perform cleanup if not a dry run
        if (!$dry_run) {
            // Mark orphaned client relationships as inactive
            if (!empty($results['orphaned_clients'])) {
                $orphan_client_ids = array_column($results['orphaned_clients'], 'relationship_id');
                $placeholders = implode(',', array_fill(0, count($orphan_client_ids), '%d'));
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$rel_table} SET relationship_status = 'inactive' WHERE id IN ({$placeholders})",
                    $orphan_client_ids
                ));
                $results['total_cleaned'] += count($orphan_client_ids);
            }

            // Mark orphaned agent relationships as inactive
            if (!empty($results['orphaned_agents'])) {
                $orphan_agent_ids = array_column($results['orphaned_agents'], 'relationship_id');
                $placeholders = implode(',', array_fill(0, count($orphan_agent_ids), '%d'));
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$rel_table} SET relationship_status = 'inactive' WHERE id IN ({$placeholders})",
                    $orphan_agent_ids
                ));
                $results['total_cleaned'] += count($orphan_agent_ids);
            }

            // Fix mismatched agent IDs by updating to use WordPress user ID
            if (!empty($results['mismatched_agent_ids'])) {
                foreach ($results['mismatched_agent_ids'] as $mismatch) {
                    $wpdb->update(
                        $rel_table,
                        ['agent_id' => $mismatch['correct_user_id']],
                        ['id' => $mismatch['relationship_id']],
                        ['%d'],
                        ['%d']
                    );
                }
                $results['total_cleaned'] += count($results['mismatched_agent_ids']);
            }
        }

        $results['summary'] = sprintf(
            'Found: %d orphaned clients, %d orphaned agents, %d mismatched agent IDs. %s',
            count($results['orphaned_clients']),
            count($results['orphaned_agents']),
            count($results['mismatched_agent_ids']),
            $dry_run ? 'Dry run - no changes made.' : "Cleaned {$results['total_cleaned']} records."
        );

        return $results;
    }

    /**
     * Get relationship statistics for debugging
     *
     * @return array Statistics about agent-client relationships
     */
    public static function get_relationship_stats() {
        global $wpdb;

        $rel_table = MLD_Saved_Search_Database::get_table_name('agent_client_relationships');
        $profiles_table = MLD_Saved_Search_Database::get_table_name('agent_profiles');

        $stats = [];

        // Total relationships by status
        $status_counts = $wpdb->get_results(
            "SELECT relationship_status, COUNT(*) as count
             FROM {$rel_table}
             GROUP BY relationship_status"
        );
        $stats['by_status'] = [];
        foreach ($status_counts as $row) {
            $stats['by_status'][$row->relationship_status] = (int) $row->count;
        }

        // Active relationships per agent
        $agent_counts = $wpdb->get_results(
            "SELECT r.agent_id, p.display_name, u.display_name as wp_name, COUNT(*) as client_count
             FROM {$rel_table} r
             LEFT JOIN {$profiles_table} p ON r.agent_id = p.user_id
             LEFT JOIN {$wpdb->users} u ON r.agent_id = u.ID
             WHERE r.relationship_status = 'active'
             GROUP BY r.agent_id
             ORDER BY client_count DESC"
        );
        $stats['agents'] = [];
        foreach ($agent_counts as $row) {
            $stats['agents'][] = [
                'agent_id' => $row->agent_id,
                'name' => $row->display_name ?: $row->wp_name ?: "Agent #{$row->agent_id}",
                'client_count' => (int) $row->client_count,
            ];
        }

        // Orphaned relationship counts
        $orphaned_clients = $wpdb->get_var(
            "SELECT COUNT(*)
             FROM {$rel_table} r
             LEFT JOIN {$wpdb->users} u ON r.client_id = u.ID
             WHERE u.ID IS NULL AND r.relationship_status = 'active'"
        );
        $stats['orphaned_clients'] = (int) $orphaned_clients;

        $orphaned_agents = $wpdb->get_var(
            "SELECT COUNT(*)
             FROM {$rel_table} r
             LEFT JOIN {$wpdb->users} u ON r.agent_id = u.ID
             LEFT JOIN {$profiles_table} p ON r.agent_id = p.id
             WHERE u.ID IS NULL AND p.id IS NULL AND r.relationship_status = 'active'"
        );
        $stats['orphaned_agents'] = (int) $orphaned_agents;

        return $stats;
    }
}