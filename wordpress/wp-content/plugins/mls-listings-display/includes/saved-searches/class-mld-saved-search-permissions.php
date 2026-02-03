<?php
/**
 * MLS Listings Display - Saved Search Permissions Class
 * 
 * Handles access control and permissions for saved searches
 * 
 * @package MLS_Listings_Display
 * @subpackage Saved_Searches
 * @since 3.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Saved_Search_Permissions {
    
    /**
     * Custom capabilities for saved searches
     */
    const CAP_MANAGE_SAVED_SEARCHES = 'mld_manage_saved_searches';
    const CAP_MANAGE_CLIENTS = 'mld_manage_clients';
    const CAP_EDIT_ANY_SEARCH = 'mld_edit_any_search';
    const CAP_DELETE_ANY_SEARCH = 'mld_delete_any_search';
    const CAP_VIEW_CLIENT_SEARCHES = 'mld_view_client_searches';
    
    /**
     * Add custom capabilities to roles
     */
    public static function add_capabilities() {
        // Get administrator role
        $admin_role = get_role('administrator');
        if ($admin_role) {
            $admin_role->add_cap(self::CAP_MANAGE_SAVED_SEARCHES);
            $admin_role->add_cap(self::CAP_MANAGE_CLIENTS);
            $admin_role->add_cap(self::CAP_EDIT_ANY_SEARCH);
            $admin_role->add_cap(self::CAP_DELETE_ANY_SEARCH);
            $admin_role->add_cap(self::CAP_VIEW_CLIENT_SEARCHES);
        }
        
        // Create custom agent role if it doesn't exist
        if (!get_role('mld_agent')) {
            add_role('mld_agent', 'Real Estate Agent', [
                'read' => true,
                self::CAP_MANAGE_SAVED_SEARCHES => true,
                self::CAP_MANAGE_CLIENTS => true,
                self::CAP_VIEW_CLIENT_SEARCHES => true,
                'edit_posts' => false,
                'delete_posts' => false
            ]);
        } else {
            // Update existing agent role
            $agent_role = get_role('mld_agent');
            if ($agent_role) {
                $agent_role->add_cap(self::CAP_MANAGE_SAVED_SEARCHES);
                $agent_role->add_cap(self::CAP_MANAGE_CLIENTS);
                $agent_role->add_cap(self::CAP_VIEW_CLIENT_SEARCHES);
            }
        }
    }
    
    /**
     * Remove custom capabilities (for plugin deactivation)
     */
    public static function remove_capabilities() {
        // Remove from administrator
        $admin_role = get_role('administrator');
        if ($admin_role) {
            $admin_role->remove_cap(self::CAP_MANAGE_SAVED_SEARCHES);
            $admin_role->remove_cap(self::CAP_MANAGE_CLIENTS);
            $admin_role->remove_cap(self::CAP_EDIT_ANY_SEARCH);
            $admin_role->remove_cap(self::CAP_DELETE_ANY_SEARCH);
            $admin_role->remove_cap(self::CAP_VIEW_CLIENT_SEARCHES);
        }
        
        // Remove custom role
        remove_role('mld_agent');
    }
    
    /**
     * Check if user can view a specific saved search
     *
     * @param int $user_id User ID
     * @param int $search_id Search ID
     * @return bool
     */
    public static function can_view_search($user_id, $search_id) {
        if (!$user_id) {
            return false;
        }
        
        // Get the search
        $search = MLD_Saved_Searches::get_search($search_id);
        if (!$search) {
            return false;
        }
        
        // Owner can always view
        if ($search->user_id == $user_id) {
            return true;
        }
        
        // Admin can view any search
        if (user_can($user_id, self::CAP_EDIT_ANY_SEARCH)) {
            return true;
        }
        
        // Agent can view if they created it or if it's their client's
        if (user_can($user_id, self::CAP_VIEW_CLIENT_SEARCHES)) {
            // Check if created by this admin
            if ($search->created_by_admin == $user_id) {
                return true;
            }
            
            // Check if it's their client's search
            if (self::is_users_client($user_id, $search->user_id)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Check if user can edit a specific saved search
     *
     * @param int $user_id User ID
     * @param int $search_id Search ID
     * @return bool
     */
    public static function can_edit_search($user_id, $search_id) {
        if (!$user_id) {
            return false;
        }
        
        // Get the search
        $search = MLD_Saved_Searches::get_search($search_id);
        if (!$search) {
            return false;
        }
        
        // Owner can always edit
        if ($search->user_id == $user_id) {
            return true;
        }
        
        // Admin can edit any search
        if (user_can($user_id, self::CAP_EDIT_ANY_SEARCH)) {
            return true;
        }
        
        // Agent can edit if they created it
        if (user_can($user_id, self::CAP_MANAGE_SAVED_SEARCHES)) {
            if ($search->created_by_admin == $user_id) {
                return true;
            }
            
            // Agent can edit their client's searches if they have the relationship
            if (self::is_users_client($user_id, $search->user_id)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Check if user can delete a specific saved search
     *
     * @param int $user_id User ID
     * @param int $search_id Search ID
     * @return bool
     */
    public static function can_delete_search($user_id, $search_id) {
        if (!$user_id) {
            return false;
        }
        
        // Get the search
        $search = MLD_Saved_Searches::get_search($search_id);
        if (!$search) {
            return false;
        }
        
        // Owner can always delete
        if ($search->user_id == $user_id) {
            return true;
        }
        
        // Admin can delete any search
        if (user_can($user_id, self::CAP_DELETE_ANY_SEARCH)) {
            return true;
        }
        
        // Agent can delete if they created it
        if (user_can($user_id, self::CAP_MANAGE_SAVED_SEARCHES)) {
            if ($search->created_by_admin == $user_id) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Check if user can manage searches for a specific client
     *
     * @param int $agent_id Agent user ID
     * @param int $client_id Client user ID
     * @return bool
     */
    public static function can_manage_client_searches($agent_id, $client_id) {
        if (!$agent_id || !$client_id) {
            return false;
        }
        
        // Admin can manage any client
        if (user_can($agent_id, 'manage_options')) {
            return true;
        }
        
        // Check if user has client management capability
        if (!user_can($agent_id, self::CAP_MANAGE_CLIENTS)) {
            return false;
        }
        
        // Check if client is assigned to this agent
        return self::is_users_client($agent_id, $client_id);
    }
    
    /**
     * Check if a client is assigned to an agent
     *
     * @param int $agent_id Agent user ID
     * @param int $client_id Client user ID
     * @return bool
     */
    public static function is_users_client($agent_id, $client_id) {
        global $wpdb;
        
        $agent_id = absint($agent_id);
        $client_id = absint($client_id);
        
        $relationships_table = MLD_Saved_Search_Database::get_table_name('agent_client_relationships');
        
        $relationship = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $relationships_table 
             WHERE agent_id = %d 
             AND client_id = %d 
             AND relationship_status = 'active'",
            $agent_id,
            $client_id
        ));
        
        return !empty($relationship);
    }
    
    /**
     * Get all clients an agent can manage
     *
     * @param int $agent_id Agent user ID
     * @return array Array of client user IDs
     */
    public static function get_manageable_clients($agent_id) {
        if (!user_can($agent_id, self::CAP_MANAGE_CLIENTS)) {
            return [];
        }
        
        // Admin can manage all users
        if (user_can($agent_id, 'manage_options')) {
            $users = get_users(['fields' => 'ID']);
            return $users;
        }
        
        // Get assigned clients
        $clients = MLD_Agent_Manager::get_agent_clients($agent_id);
        return wp_list_pluck($clients, 'client_id');
    }
    
    /**
     * Check if user can create searches for others
     *
     * @param int $user_id User ID
     * @return bool
     */
    public static function can_create_searches_for_others($user_id) {
        return user_can($user_id, self::CAP_MANAGE_SAVED_SEARCHES) || 
               user_can($user_id, 'manage_options');
    }
    
    /**
     * Check if user can view property preferences for a client
     *
     * @param int $user_id User ID requesting access
     * @param int $client_id Client whose preferences to view
     * @return bool
     */
    public static function can_view_client_preferences($user_id, $client_id) {
        // Users can view their own preferences
        if ($user_id == $client_id) {
            return true;
        }
        
        // Admin can view any preferences
        if (user_can($user_id, 'manage_options')) {
            return true;
        }
        
        // Agent can view their client's preferences
        if (user_can($user_id, self::CAP_VIEW_CLIENT_SEARCHES)) {
            return self::is_users_client($user_id, $client_id);
        }
        
        return false;
    }
    
    /**
     * Filter searches based on user permissions
     *
     * @param array $searches Array of search objects
     * @param int $user_id User ID
     * @return array Filtered searches
     */
    public static function filter_searches_by_permission($searches, $user_id) {
        if (!is_array($searches)) {
            return [];
        }
        
        // Admin can see all
        if (user_can($user_id, self::CAP_EDIT_ANY_SEARCH)) {
            return $searches;
        }
        
        // Filter based on permissions
        return array_filter($searches, function($search) use ($user_id) {
            return self::can_view_search($user_id, $search->id);
        });
    }
    
    /**
     * Get permission error message
     *
     * @param string $action Action attempted
     * @return string Error message
     */
    public static function get_permission_error($action = 'perform this action') {
        return sprintf(
            __('Sorry, you do not have permission to %s.', 'mld'),
            $action
        );
    }
    
    /**
     * Check if current user can access saved search admin pages
     *
     * @return bool
     */
    public static function current_user_can_access_admin() {
        return current_user_can(self::CAP_MANAGE_SAVED_SEARCHES) || 
               current_user_can('manage_options');
    }
    
    /**
     * Check if user is a client (has an assigned agent)
     *
     * @param int $user_id User ID
     * @return bool
     */
    public static function is_client($user_id) {
        $agent = MLD_Agent_Manager::get_client_agent($user_id);
        return !empty($agent);
    }
    
    /**
     * Get user role in the saved search system
     *
     * @param int $user_id User ID
     * @return string 'admin', 'agent', 'client', or 'user'
     */
    public static function get_user_role_type($user_id) {
        if (user_can($user_id, 'manage_options')) {
            return 'admin';
        }
        
        if (user_can($user_id, self::CAP_MANAGE_SAVED_SEARCHES)) {
            return 'agent';
        }
        
        if (self::is_client($user_id)) {
            return 'client';
        }
        
        return 'user';
    }
}