<?php
/**
 * MLD User Type Manager
 *
 * Manages user type designations (client, agent, admin) for the BMN Boston platform.
 * All users default to 'client' type upon registration.
 *
 * @package MLS_Listings_Display
 * @since 6.32.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class MLD_User_Type_Manager
 *
 * Handles user type CRUD operations and provides utility methods for
 * checking user roles in the agent-client collaboration system.
 */
class MLD_User_Type_Manager {

    /**
     * User type constants
     */
    const TYPE_CLIENT = 'client';
    const TYPE_AGENT = 'agent';
    const TYPE_ADMIN = 'admin';

    /**
     * Valid user types
     */
    const VALID_TYPES = [self::TYPE_CLIENT, self::TYPE_AGENT, self::TYPE_ADMIN];

    /**
     * Instance for singleton pattern
     *
     * @var MLD_User_Type_Manager|null
     */
    private static $instance = null;

    /**
     * Cache for user types to minimize database queries
     *
     * @var array
     */
    private static $type_cache = [];

    /**
     * Get singleton instance
     *
     * @return MLD_User_Type_Manager
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor - register hooks
     */
    private function __construct() {
        // Auto-register new users as clients
        add_action('user_register', [__CLASS__, 'on_user_register'], 10, 1);

        // Clear cache when user is deleted
        add_action('delete_user', [__CLASS__, 'on_user_delete'], 10, 1);
    }

    /**
     * Initialize the manager (call on plugin init)
     */
    public static function init() {
        self::get_instance();
    }

    /**
     * Get user type for a given user ID
     *
     * @param int $user_id WordPress user ID
     * @return string User type (client, agent, or admin)
     */
    public static function get_user_type($user_id) {
        $user_id = absint($user_id);

        if ($user_id <= 0) {
            return self::TYPE_CLIENT;
        }

        // Check cache first
        if (isset(self::$type_cache[$user_id])) {
            return self::$type_cache[$user_id];
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'mld_user_types';

        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;

        $user_type = null;
        if ($table_exists) {
            $user_type = $wpdb->get_var($wpdb->prepare(
                "SELECT user_type FROM $table_name WHERE user_id = %d",
                $user_id
            ));
        }

        // If no record in database, fall back to WordPress roles
        if (!$user_type) {
            $user = get_userdata($user_id);
            if ($user) {
                // WordPress administrators are treated as admin type
                if (in_array('administrator', (array) $user->roles)) {
                    $user_type = self::TYPE_ADMIN;
                }
                // Check for agent role (custom role) or editor role
                elseif (in_array('agent', (array) $user->roles) || in_array('editor', (array) $user->roles)) {
                    $user_type = self::TYPE_AGENT;
                }
            }
        }

        // Default to client if still no type determined
        $type = $user_type ?: self::TYPE_CLIENT;

        // Cache the result
        self::$type_cache[$user_id] = $type;

        return $type;
    }

    /**
     * Set user type for a given user ID
     *
     * @param int      $user_id     WordPress user ID
     * @param string   $type        User type (client, agent, admin)
     * @param int|null $promoted_by User ID who made the change (for audit)
     * @return bool True on success, false on failure
     */
    public static function set_user_type($user_id, $type, $promoted_by = null) {
        $user_id = absint($user_id);

        if ($user_id <= 0) {
            return false;
        }

        // Validate type
        if (!in_array($type, self::VALID_TYPES, true)) {
            return false;
        }

        // Verify user exists
        $user = get_userdata($user_id);
        if (!$user) {
            return false;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'mld_user_types';

        // Check if record exists
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table_name WHERE user_id = %d",
            $user_id
        ));

        $now = current_time('mysql');

        if ($exists) {
            // Update existing record
            $result = $wpdb->update(
                $table_name,
                [
                    'user_type'   => $type,
                    'promoted_by' => $promoted_by ? absint($promoted_by) : null,
                    'promoted_at' => $promoted_by ? $now : null,
                    'updated_at'  => $now,
                ],
                ['user_id' => $user_id],
                ['%s', '%d', '%s', '%s'],
                ['%d']
            );
        } else {
            // Insert new record
            $result = $wpdb->insert(
                $table_name,
                [
                    'user_id'     => $user_id,
                    'user_type'   => $type,
                    'promoted_by' => $promoted_by ? absint($promoted_by) : null,
                    'promoted_at' => $promoted_by ? $now : null,
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ],
                ['%d', '%s', '%d', '%s', '%s', '%s']
            );
        }

        if ($result !== false) {
            // Clear cache
            unset(self::$type_cache[$user_id]);
            return true;
        }

        return false;
    }

    /**
     * Check if user is a client
     *
     * @param int $user_id WordPress user ID
     * @return bool
     */
    public static function is_client($user_id) {
        return self::get_user_type($user_id) === self::TYPE_CLIENT;
    }

    /**
     * Check if user is an agent
     *
     * @param int $user_id WordPress user ID
     * @return bool
     */
    public static function is_agent($user_id) {
        return self::get_user_type($user_id) === self::TYPE_AGENT;
    }

    /**
     * Check if user is an admin
     *
     * @param int $user_id WordPress user ID
     * @return bool
     */
    public static function is_admin($user_id) {
        return self::get_user_type($user_id) === self::TYPE_ADMIN;
    }

    /**
     * Promote a user to agent status
     *
     * Creates or updates agent profile alongside type change.
     *
     * @param int   $user_id      WordPress user ID
     * @param int   $promoted_by  User ID who promoted (usually admin)
     * @param array $profile_data Optional agent profile data
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public static function promote_to_agent($user_id, $promoted_by, $profile_data = []) {
        $user_id = absint($user_id);
        $promoted_by = absint($promoted_by);

        if ($user_id <= 0) {
            return new WP_Error('invalid_user', __('Invalid user ID.', 'mls-listings-display'));
        }

        // Verify user exists
        $user = get_userdata($user_id);
        if (!$user) {
            return new WP_Error('user_not_found', __('User not found.', 'mls-listings-display'));
        }

        // Check if already an agent
        if (self::is_agent($user_id)) {
            return new WP_Error('already_agent', __('User is already an agent.', 'mls-listings-display'));
        }

        // Set user type to agent
        $type_result = self::set_user_type($user_id, self::TYPE_AGENT, $promoted_by);
        if (!$type_result) {
            return new WP_Error('type_update_failed', __('Failed to update user type.', 'mls-listings-display'));
        }

        // Create or update agent profile
        if (class_exists('MLD_Agent_Client_Manager')) {
            $default_profile = [
                'user_id'      => $user_id,
                'display_name' => $user->display_name,
                'email'        => $user->user_email,
                'is_active'    => true,
            ];

            $profile = array_merge($default_profile, $profile_data);

            // Check if profile exists
            $existing = MLD_Agent_Client_Manager::get_agent_by_user_id($user_id);
            if ($existing) {
                // Update existing profile
                MLD_Agent_Client_Manager::save_agent($profile);
            } else {
                // Create new profile
                MLD_Agent_Client_Manager::save_agent($profile);
            }
        }

        /**
         * Fires after a user is promoted to agent
         *
         * @param int   $user_id      User ID
         * @param int   $promoted_by  Admin user ID who promoted
         * @param array $profile_data Agent profile data
         */
        do_action('mld_user_promoted_to_agent', $user_id, $promoted_by, $profile_data);

        return true;
    }

    /**
     * Demote an agent back to client status
     *
     * Does NOT delete agent profile (preserves history).
     * Removes client assignments and marks profile inactive.
     *
     * @param int $agent_id   WordPress user ID of the agent
     * @param int $demoted_by User ID who demoted (usually admin)
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public static function demote_to_client($agent_id, $demoted_by) {
        $agent_id = absint($agent_id);
        $demoted_by = absint($demoted_by);

        if ($agent_id <= 0) {
            return new WP_Error('invalid_user', __('Invalid user ID.', 'mls-listings-display'));
        }

        // Check if actually an agent
        if (!self::is_agent($agent_id)) {
            return new WP_Error('not_agent', __('User is not currently an agent.', 'mls-listings-display'));
        }

        // Set user type back to client
        $type_result = self::set_user_type($agent_id, self::TYPE_CLIENT, $demoted_by);
        if (!$type_result) {
            return new WP_Error('type_update_failed', __('Failed to update user type.', 'mls-listings-display'));
        }

        // Deactivate agent profile (don't delete - preserve history)
        if (class_exists('MLD_Agent_Client_Manager')) {
            $agent = MLD_Agent_Client_Manager::get_agent_by_user_id($agent_id);
            if ($agent) {
                // Mark as inactive
                MLD_Agent_Client_Manager::save_agent([
                    'id'        => $agent->id,
                    'is_active' => false,
                ]);

                // Remove all client assignments
                global $wpdb;
                $relationships_table = $wpdb->prefix . 'mld_agent_client_relationships';
                $wpdb->update(
                    $relationships_table,
                    ['status' => 'inactive', 'updated_at' => current_time('mysql')],
                    ['agent_user_id' => $agent_id, 'status' => 'active'],
                    ['%s', '%s'],
                    ['%d', '%s']
                );
            }
        }

        /**
         * Fires after an agent is demoted to client
         *
         * @param int $agent_id   User ID
         * @param int $demoted_by Admin user ID who demoted
         */
        do_action('mld_agent_demoted_to_client', $agent_id, $demoted_by);

        return true;
    }

    /**
     * Hook: Auto-register new users as clients
     *
     * @param int $user_id New user ID
     */
    public static function on_user_register($user_id) {
        self::set_user_type($user_id, self::TYPE_CLIENT);
    }

    /**
     * Hook: Clean up all MLD-related data when user is deleted
     *
     * @param int $user_id Deleted user ID
     */
    public static function on_user_delete($user_id) {
        global $wpdb;

        $user_id = absint($user_id);
        if ($user_id <= 0) {
            return;
        }

        // Clear type cache
        unset(self::$type_cache[$user_id]);

        // Delete from user_types table
        $table_name = $wpdb->prefix . 'mld_user_types';
        $wpdb->delete($table_name, ['user_id' => $user_id], ['%d']);

        // Clean up agent-client relationships (as client)
        $rel_table = $wpdb->prefix . 'mld_agent_client_relationships';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$rel_table}'") === $rel_table) {
            $wpdb->update(
                $rel_table,
                ['relationship_status' => 'inactive'],
                ['client_id' => $user_id],
                ['%s'],
                ['%d']
            );
        }

        // Clean up referral signups (as client)
        $referral_signups = $wpdb->prefix . 'mld_referral_signups';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$referral_signups}'") === $referral_signups) {
            $wpdb->delete($referral_signups, ['client_user_id' => $user_id], ['%d']);
        }

        // If user was an agent, deactivate their referral codes and handle relationships
        $user_type = self::get_user_type($user_id);
        if ($user_type === self::TYPE_AGENT) {
            // Deactivate referral codes
            $referral_codes = $wpdb->prefix . 'mld_agent_referral_codes';
            if ($wpdb->get_var("SHOW TABLES LIKE '{$referral_codes}'") === $referral_codes) {
                $wpdb->update(
                    $referral_codes,
                    ['is_active' => 0],
                    ['agent_user_id' => $user_id],
                    ['%d'],
                    ['%d']
                );
            }

            // Mark all their client relationships as inactive
            if ($wpdb->get_var("SHOW TABLES LIKE '{$rel_table}'") === $rel_table) {
                $wpdb->update(
                    $rel_table,
                    ['relationship_status' => 'inactive'],
                    ['agent_id' => $user_id],
                    ['%s'],
                    ['%d']
                );
            }

            // Clear default agent if this was the default
            $default = get_option('mld_default_agent_user_id');
            if ((int) $default === $user_id) {
                delete_option('mld_default_agent_user_id');
            }
        }
    }

    /**
     * Get all users of a specific type
     *
     * @param string $type    User type to filter by
     * @param array  $args    Additional query arguments
     * @return array Array of user objects with type info
     */
    public static function get_users_by_type($type, $args = []) {
        if (!in_array($type, self::VALID_TYPES, true)) {
            return [];
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'mld_user_types';

        $defaults = [
            'limit'   => 100,
            'offset'  => 0,
            'orderby' => 'created_at',
            'order'   => 'DESC',
        ];

        $args = wp_parse_args($args, $defaults);

        $limit = absint($args['limit']);
        $offset = absint($args['offset']);
        $orderby = in_array($args['orderby'], ['created_at', 'user_id', 'promoted_at']) ? $args['orderby'] : 'created_at';
        $order = strtoupper($args['order']) === 'ASC' ? 'ASC' : 'DESC';

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT ut.*, u.display_name, u.user_email
             FROM $table_name ut
             INNER JOIN {$wpdb->users} u ON ut.user_id = u.ID
             WHERE ut.user_type = %s
             ORDER BY ut.$orderby $order
             LIMIT %d OFFSET %d",
            $type,
            $limit,
            $offset
        ));

        return $results ?: [];
    }

    /**
     * Count users by type
     *
     * @param string|null $type Specific type to count, or null for all
     * @return int|array Count for specific type, or array of counts by type
     */
    public static function count_users_by_type($type = null) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'mld_user_types';

        if ($type !== null) {
            if (!in_array($type, self::VALID_TYPES, true)) {
                return 0;
            }

            return (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table_name WHERE user_type = %s",
                $type
            ));
        }

        // Return counts for all types
        $results = $wpdb->get_results(
            "SELECT user_type, COUNT(*) as count FROM $table_name GROUP BY user_type",
            OBJECT_K
        );

        $counts = [
            self::TYPE_CLIENT => 0,
            self::TYPE_AGENT  => 0,
            self::TYPE_ADMIN  => 0,
        ];

        if ($results) {
            foreach ($results as $type => $row) {
                $counts[$type] = (int) $row->count;
            }
        }

        return $counts;
    }

    /**
     * Get user type record with full details
     *
     * @param int $user_id WordPress user ID
     * @return object|null User type record or null
     */
    public static function get_user_type_record($user_id) {
        $user_id = absint($user_id);

        if ($user_id <= 0) {
            return null;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'mld_user_types';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT ut.*,
                    u.display_name,
                    u.user_email,
                    p.display_name as promoted_by_name
             FROM $table_name ut
             INNER JOIN {$wpdb->users} u ON ut.user_id = u.ID
             LEFT JOIN {$wpdb->users} p ON ut.promoted_by = p.ID
             WHERE ut.user_id = %d",
            $user_id
        ));
    }

    /**
     * Clear the type cache for a specific user or all users
     *
     * @param int|null $user_id User ID to clear, or null to clear all
     */
    public static function clear_cache($user_id = null) {
        if ($user_id !== null) {
            unset(self::$type_cache[absint($user_id)]);
        } else {
            self::$type_cache = [];
        }
    }

    /**
     * Get user type for REST API response (includes additional context)
     *
     * @param int $user_id WordPress user ID
     * @return array User type data for API
     */
    public static function get_user_type_for_api($user_id) {
        $user_id = absint($user_id);
        $type = self::get_user_type($user_id);

        $data = [
            'type'     => $type,
            'is_agent' => $type === self::TYPE_AGENT,
            'is_admin' => $type === self::TYPE_ADMIN,
        ];

        // For agents, include profile ID
        if ($type === self::TYPE_AGENT && class_exists('MLD_Agent_Client_Manager')) {
            $agent = MLD_Agent_Client_Manager::get_agent_by_user_id($user_id);
            if ($agent) {
                $data['agent_profile_id'] = $agent->id;
                $data['agent_is_active'] = (bool) $agent->is_active;
            }
        }

        return $data;
    }

    /**
     * Migrate existing WordPress admin users to admin type
     *
     * Call this during plugin activation/upgrade to sync with WP roles.
     *
     * @return int Number of users migrated
     */
    public static function migrate_wordpress_admins() {
        $admin_users = get_users(['role' => 'administrator']);
        $migrated = 0;

        foreach ($admin_users as $user) {
            $current_type = self::get_user_type($user->ID);
            if ($current_type !== self::TYPE_ADMIN) {
                self::set_user_type($user->ID, self::TYPE_ADMIN);
                $migrated++;
            }
        }

        return $migrated;
    }
}
