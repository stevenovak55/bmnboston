<?php
/**
 * MLS Listings Display - Client Management Admin
 * 
 * Handles admin interface for managing clients and assignments
 * 
 * @package MLS_Listings_Display
 * @subpackage Saved_Searches
 * @since 3.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Client_Management_Admin {
    
    /**
     * Initialize admin functionality
     */
    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu'], 22);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        
        // AJAX handlers
        add_action('wp_ajax_mld_admin_get_clients', [$this, 'ajax_get_clients']);
        add_action('wp_ajax_mld_admin_get_client_details', [$this, 'ajax_get_client_details']);
        add_action('wp_ajax_mld_admin_assign_agent', [$this, 'ajax_assign_agent']);
        add_action('wp_ajax_mld_admin_unassign_agent', [$this, 'ajax_unassign_agent']);
        add_action('wp_ajax_mld_admin_bulk_assign', [$this, 'ajax_bulk_assign']);
        add_action('wp_ajax_mld_admin_update_email_prefs', [$this, 'ajax_update_email_prefs']);
        add_action('wp_ajax_mld_admin_get_client_searches', [$this, 'ajax_get_client_searches']);
        add_action('wp_ajax_mld_admin_create_client', [$this, 'ajax_create_client']);
        add_action('wp_ajax_mld_admin_cleanup_relationships', [$this, 'ajax_cleanup_relationships']);
        add_action('wp_ajax_mld_admin_get_relationship_stats', [$this, 'ajax_get_relationship_stats']);
        add_action('wp_ajax_mld_admin_cleanup_referrals', [$this, 'ajax_cleanup_referrals']);
        add_action('wp_ajax_mld_admin_get_referral_stats', [$this, 'ajax_get_referral_stats']);
    }
    
    /**
     * Add admin menu items
     */
    public function add_admin_menu() {
        add_submenu_page(
            'mls_listings_display',
            'Client Management',
            'Clients',
            'manage_options',
            'mld-client-management',
            [$this, 'render_admin_page']
        );
    }
    
    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets($hook) {
        if ($hook !== 'mls-display_page_mld-client-management') {
            return;
        }
        
        // Enqueue common utilities first
        wp_enqueue_style(
            'mld-common-utils',
            MLD_PLUGIN_URL . 'assets/css/mld-common-utils.css',
            [],
            MLD_VERSION
        );
        
        wp_enqueue_script(
            'mld-common-utils',
            MLD_PLUGIN_URL . 'assets/js/mld-common-utils.js',
            ['jquery'],
            MLD_VERSION,
            true
        );
        
        // Enqueue styles
        // Enqueue MLDLogger first
        wp_enqueue_script(
            'mld-logger',
            MLD_PLUGIN_URL . 'assets/js/mld-logger.js',
            ['jquery'],
            MLD_VERSION,
            true
        );

        wp_enqueue_style(
            'mld-client-management-admin',
            MLD_PLUGIN_URL . 'assets/css/client-management-admin.css',
            ['mld-common-utils'],
            MLD_VERSION
        );

        // Enqueue scripts
        wp_enqueue_script(
            'mld-client-management-admin',
            MLD_PLUGIN_URL . 'assets/js/client-management-admin.js',
            ['jquery', 'mld-common-utils', 'mld-logger'],
            MLD_VERSION . '.1',
            true
        );
        
        // Localize script
        wp_localize_script('mld-client-management-admin', 'mldClientManagementAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('mld_admin_client_management'),
            'strings' => [
                'confirmUnassign' => __('Are you sure you want to unassign this agent from the client?', 'mld'),
                'confirmBulkAssign' => __('Are you sure you want to assign the selected clients to this agent?', 'mld'),
                'saving' => __('Saving...', 'mld'),
                'saved' => __('Changes saved successfully.', 'mld'),
                'error' => __('An error occurred. Please try again.', 'mld'),
                'loading' => __('Loading...', 'mld'),
                'noSelection' => __('Please select at least one client.', 'mld'),
                'selectAgent' => __('Please select an agent.', 'mld')
            ]
        ]);
    }
    
    /**
     * Render admin page
     */
    public function render_admin_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }
        
        include MLD_PLUGIN_PATH . 'admin/views/client-management-page.php';
    }
    
    /**
     * AJAX: Get clients list
     */
    public function ajax_get_clients() {
        check_ajax_referer('mld_admin_client_management', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        $page = isset($_POST['page']) ? intval($_POST['page']) : 1;
        $per_page = isset($_POST['per_page']) ? intval($_POST['per_page']) : 20;
        $search = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
        $assigned = isset($_POST['assigned']) ? sanitize_text_field($_POST['assigned']) : 'all';
        $sort_by = isset($_POST['sort_by']) ? sanitize_text_field($_POST['sort_by']) : 'display_name';
        $sort_order = isset($_POST['sort_order']) ? sanitize_text_field($_POST['sort_order']) : 'ASC';
        
        $args = [
            'assigned' => $assigned,
            'search' => $search,
            'orderby' => $sort_by,
            'order' => $sort_order,
            'limit' => $per_page,
            'offset' => ($page - 1) * $per_page
        ];
        
        $result = MLD_Agent_Client_Manager::get_all_clients($args);
        
        wp_send_json_success($result);
    }
    
    /**
     * AJAX: Get client details
     */
    public function ajax_get_client_details() {
        check_ajax_referer('mld_admin_client_management', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        $client_id = isset($_POST['client_id']) ? intval($_POST['client_id']) : 0;
        
        if (!$client_id) {
            wp_send_json_error('Invalid client ID');
        }
        
        // Get user info
        $user = get_user_by('id', $client_id);
        if (!$user) {
            wp_send_json_error('Client not found');
        }
        
        // Get saved searches count
        global $wpdb;
        $search_table = MLD_Saved_Search_Database::get_table_name('saved_searches');
        $searches = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) as total, 
                    COUNT(CASE WHEN is_active = 1 THEN 1 END) as active
             FROM {$search_table}
             WHERE user_id = %d",
            $client_id
        ), ARRAY_A);
        
        // Get agent assignments
        $assignments = $wpdb->get_results($wpdb->prepare(
            "SELECT acr.*, ap.display_name as agent_name, ap.email as agent_email,
                    acp.default_email_type
             FROM " . MLD_Saved_Search_Database::get_table_name('agent_client_relationships') . " acr
             LEFT JOIN " . MLD_Saved_Search_Database::get_table_name('agent_profiles') . " ap ON acr.agent_id = ap.user_id
             LEFT JOIN " . MLD_Saved_Search_Database::get_table_name('admin_client_preferences') . " acp 
                ON acr.agent_id = acp.admin_id AND acr.client_id = acp.client_id
             WHERE acr.client_id = %d
             ORDER BY acr.relationship_status DESC, acr.assigned_date DESC",
            $client_id
        ), ARRAY_A);
        
        $client_data = [
            'client_id' => $user->ID,
            'display_name' => $user->display_name,
            'user_email' => $user->user_email,
            'user_registered' => $user->user_registered,
            'total_searches' => $searches['total'],
            'active_searches' => $searches['active'],
            'assignments' => $assignments
        ];
        
        wp_send_json_success($client_data);
    }
    
    /**
     * AJAX: Assign agent to client
     */
    public function ajax_assign_agent() {
        check_ajax_referer('mld_admin_client_management', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        $client_id = isset($_POST['client_id']) ? intval($_POST['client_id']) : 0;
        $agent_id = isset($_POST['agent_id']) ? intval($_POST['agent_id']) : 0;
        $email_type = isset($_POST['email_type']) ? sanitize_text_field($_POST['email_type']) : 'none';
        $notes = isset($_POST['notes']) ? sanitize_textarea_field($_POST['notes']) : '';
        
        if (!$client_id || !$agent_id) {
            wp_send_json_error('Invalid client or agent ID');
        }
        
        $result = MLD_Agent_Client_Manager::assign_client($agent_id, $client_id, [
            'status' => 'active',
            'notes' => $notes,
            'email_type' => $email_type
        ]);
        
        if ($result) {
            wp_send_json_success(['message' => 'Agent assigned successfully']);
        } else {
            wp_send_json_error('Failed to assign agent');
        }
    }
    
    /**
     * AJAX: Unassign agent from client
     */
    public function ajax_unassign_agent() {
        check_ajax_referer('mld_admin_client_management', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        $client_id = isset($_POST['client_id']) ? intval($_POST['client_id']) : 0;
        $agent_id = isset($_POST['agent_id']) ? intval($_POST['agent_id']) : 0;
        
        if (!$client_id || !$agent_id) {
            wp_send_json_error('Invalid client or agent ID');
        }
        
        $result = MLD_Agent_Client_Manager::unassign_client($agent_id, $client_id);
        
        if ($result) {
            wp_send_json_success(['message' => 'Agent unassigned successfully']);
        } else {
            wp_send_json_error('Failed to unassign agent');
        }
    }
    
    /**
     * AJAX: Bulk assign clients to agent
     */
    public function ajax_bulk_assign() {
        check_ajax_referer('mld_admin_client_management', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        $client_ids = isset($_POST['client_ids']) ? array_map('intval', $_POST['client_ids']) : [];
        $agent_id = isset($_POST['agent_id']) ? intval($_POST['agent_id']) : 0;
        $email_type = isset($_POST['email_type']) ? sanitize_text_field($_POST['email_type']) : 'none';
        
        if (empty($client_ids) || !$agent_id) {
            wp_send_json_error('Invalid client IDs or agent ID');
        }
        
        $results = MLD_Agent_Client_Manager::bulk_assign_clients($agent_id, $client_ids, [
            'status' => 'active',
            'email_type' => $email_type
        ]);
        
        wp_send_json_success([
            'message' => sprintf(
                '%d clients assigned successfully, %d failed',
                $results['success'],
                $results['failed']
            ),
            'results' => $results
        ]);
    }
    
    /**
     * AJAX: Update email preferences
     */
    public function ajax_update_email_prefs() {
        check_ajax_referer('mld_admin_client_management', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        $client_id = isset($_POST['client_id']) ? intval($_POST['client_id']) : 0;
        $agent_id = isset($_POST['agent_id']) ? intval($_POST['agent_id']) : 0;
        $email_type = isset($_POST['email_type']) ? sanitize_text_field($_POST['email_type']) : 'none';
        
        if (!$client_id || !$agent_id) {
            wp_send_json_error('Invalid client or agent ID');
        }
        
        $result = MLD_Agent_Client_Manager::update_client_email_preferences($agent_id, $client_id, [
            'default_email_type' => $email_type
        ]);
        
        if ($result) {
            wp_send_json_success(['message' => 'Email preferences updated']);
        } else {
            wp_send_json_error('Failed to update preferences');
        }
    }
    
    /**
     * AJAX: Get client's saved searches
     */
    public function ajax_get_client_searches() {
        check_ajax_referer('mld_admin_client_management', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        $client_id = isset($_POST['client_id']) ? intval($_POST['client_id']) : 0;
        
        if (!$client_id) {
            wp_send_json_error('Invalid client ID');
        }
        
        global $wpdb;
        $table = MLD_Saved_Search_Database::get_table_name('saved_searches');
        
        $searches = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE user_id = %d
             ORDER BY created_at DESC",
            $client_id
        ), ARRAY_A);
        
        // Decode filters for display
        foreach ($searches as &$search) {
            if (is_string($search['filters'])) {
                $search['filters_decoded'] = json_decode($search['filters'], true);
            } else {
                $search['filters_decoded'] = $search['filters'];
            }
        }
        
        wp_send_json_success(['searches' => $searches]);
    }
    
    /**
     * AJAX: Create new client
     */
    public function ajax_create_client() {
        check_ajax_referer('mld_admin_client_management', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        $data = [
            'first_name' => isset($_POST['first_name']) ? sanitize_text_field($_POST['first_name']) : '',
            'last_name' => isset($_POST['last_name']) ? sanitize_text_field($_POST['last_name']) : '',
            'email' => isset($_POST['email']) ? sanitize_email($_POST['email']) : '',
            'phone' => isset($_POST['phone']) ? sanitize_text_field($_POST['phone']) : '',
            'send_notification' => !empty($_POST['send_notification'])
        ];
        
        $result = MLD_Agent_Client_Manager::create_client($data);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        // Handle both array (new format) and int (legacy) return values
        $user_id = is_array($result) ? $result['user_id'] : $result;
        $email_sent = is_array($result) ? $result['email_sent'] : true;

        // If agent_id is provided, assign the client to the agent
        if (!empty($_POST['agent_id'])) {
            $agent_id = intval($_POST['agent_id']);
            $email_type = isset($_POST['email_type']) ? sanitize_text_field($_POST['email_type']) : 'none';

            MLD_Agent_Client_Manager::assign_client($agent_id, $user_id, [
                'status' => 'active',
                'email_type' => $email_type
            ]);
        }

        // Build success message including email status
        $message = 'Client created successfully';
        if (!empty($data['send_notification']) && !$email_sent) {
            $message .= '. Note: Welcome email could not be sent.';
        }

        wp_send_json_success([
            'message' => $message,
            'client_id' => $user_id,
            'email_sent' => $email_sent
        ]);
    }

    /**
     * AJAX handler for cleaning up orphaned relationships
     *
     * @since 6.74.8
     */
    public function ajax_cleanup_relationships() {
        check_ajax_referer('mld-admin-nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }

        $dry_run = isset($_POST['dry_run']) ? filter_var($_POST['dry_run'], FILTER_VALIDATE_BOOLEAN) : true;

        $results = MLD_Agent_Client_Manager::cleanup_orphaned_relationships($dry_run);

        wp_send_json_success($results);
    }

    /**
     * AJAX handler for getting relationship statistics
     *
     * @since 6.74.8
     */
    public function ajax_get_relationship_stats() {
        check_ajax_referer('mld-admin-nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }

        $stats = MLD_Agent_Client_Manager::get_relationship_stats();

        wp_send_json_success($stats);
    }

    /**
     * AJAX handler for cleaning up orphaned referral data
     *
     * @since 6.74.8
     */
    public function ajax_cleanup_referrals() {
        check_ajax_referer('mld-admin-nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }

        $dry_run = isset($_POST['dry_run']) ? filter_var($_POST['dry_run'], FILTER_VALIDATE_BOOLEAN) : true;

        if (class_exists('MLD_Referral_Manager')) {
            $results = MLD_Referral_Manager::cleanup_orphaned_referrals($dry_run);
            wp_send_json_success($results);
        } else {
            wp_send_json_error('Referral manager not available');
        }
    }

    /**
     * AJAX handler for getting referral system statistics
     *
     * @since 6.74.8
     */
    public function ajax_get_referral_stats() {
        check_ajax_referer('mld-admin-nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }

        if (class_exists('MLD_Referral_Manager')) {
            $stats = MLD_Referral_Manager::get_referral_stats();
            wp_send_json_success($stats);
        } else {
            wp_send_json_error('Referral manager not available');
        }
    }
}