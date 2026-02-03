<?php
/**
 * MLS Listings Display - Agent Management Admin
 * 
 * Handles admin interface for managing agents
 * 
 * @package MLS_Listings_Display
 * @subpackage Saved_Searches
 * @since 3.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Agent_Management_Admin {
    
    /**
     * Initialize admin functionality
     */
    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu'], 21);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        
        // AJAX handlers
        add_action('wp_ajax_mld_admin_get_agents', [$this, 'ajax_get_agents']);
        add_action('wp_ajax_mld_admin_save_agent', [$this, 'ajax_save_agent']);
        add_action('wp_ajax_mld_admin_delete_agent', [$this, 'ajax_delete_agent']);
        add_action('wp_ajax_mld_admin_get_agent_details', [$this, 'ajax_get_agent_details']);
        add_action('wp_ajax_mld_admin_get_agent_clients', [$this, 'ajax_get_agent_clients']);

        // Referral system AJAX handlers (v6.52.0)
        add_action('wp_ajax_mld_admin_set_default_agent', [$this, 'ajax_set_default_agent']);
        add_action('wp_ajax_mld_admin_get_agent_referral', [$this, 'ajax_get_agent_referral']);
        add_action('wp_ajax_mld_admin_update_referral_code', [$this, 'ajax_update_referral_code']);
        add_action('wp_ajax_mld_admin_regenerate_referral_code', [$this, 'ajax_regenerate_referral_code']);
    }
    
    /**
     * Add admin menu items
     */
    public function add_admin_menu() {
        add_submenu_page(
            'mls_listings_display',
            'Agent Management',
            'Agents',
            'manage_options',
            'mld-agent-management',
            [$this, 'render_admin_page']
        );
    }
    
    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets($hook) {
        if ($hook !== 'mls-display_page_mld-agent-management') {
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
        wp_enqueue_style(
            'mld-agent-management-admin',
            MLD_PLUGIN_URL . 'assets/css/agent-management-admin.css',
            ['mld-common-utils'],
            MLD_VERSION
        );
        
        // Enqueue media uploader
        wp_enqueue_media();
        
        // Enqueue scripts
        wp_enqueue_script(
            'mld-agent-management-admin',
            MLD_PLUGIN_URL . 'assets/js/agent-management-admin.js',
            ['jquery', 'wp-util', 'mld-common-utils'],
            MLD_VERSION,
            true
        );
        
        // Localize script
        wp_localize_script('mld-agent-management-admin', 'mldAgentManagementAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'adminUrl' => admin_url(),
            'nonce' => wp_create_nonce('mld_admin_agent_management'),
            'strings' => [
                'confirmDelete' => __('Are you sure you want to delete this agent? All client assignments will be removed.', 'mld'),
                'saving' => __('Saving...', 'mld'),
                'saved' => __('Agent saved successfully.', 'mld'),
                'error' => __('An error occurred. Please try again.', 'mld'),
                'selectImage' => __('Select Agent Photo', 'mld'),
                'useImage' => __('Use This Image', 'mld'),
                'loading' => __('Loading...', 'mld')
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
        
        include MLD_PLUGIN_PATH . 'admin/views/agent-management-page.php';
    }
    
    /**
     * AJAX: Get agents list
     */
    public function ajax_get_agents() {
        check_ajax_referer('mld_admin_agent_management', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        $status = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : 'all';

        $agents = MLD_Agent_Client_Manager::get_agents(['status' => $status]);

        // Get default agent ID
        $default_agent_id = null;
        if (class_exists('MLD_Referral_Manager')) {
            $default_agent_id = MLD_Referral_Manager::get_default_agent();
        }

        // Get stats and referral info for each agent
        foreach ($agents as &$agent) {
            $agent['stats'] = MLD_Agent_Client_Manager::get_agent_stats($agent['user_id']);
            $agent['is_default'] = ($default_agent_id === (int) $agent['user_id']);

            // Get referral code and stats
            if (class_exists('MLD_Referral_Manager')) {
                $code_data = MLD_Referral_Manager::get_agent_referral_code($agent['user_id']);
                if ($code_data) {
                    $agent['referral_code'] = $code_data['referral_code'];
                    $agent['referral_url'] = home_url('/signup/?ref=' . $code_data['referral_code']);
                }
                $referral_stats = MLD_Referral_Manager::get_agent_referral_stats($agent['user_id']);
                $agent['referral_signups'] = $referral_stats['total_signups'] ?? 0;
            }
        }

        wp_send_json_success([
            'agents' => $agents,
            'default_agent_id' => $default_agent_id
        ]);
    }
    
    /**
     * AJAX: Save agent
     */
    public function ajax_save_agent() {
        check_ajax_referer('mld_admin_agent_management', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        $agent_data = [
            'user_id' => isset($_POST['user_id']) ? intval($_POST['user_id']) : 0,
            'display_name' => isset($_POST['display_name']) ? sanitize_text_field($_POST['display_name']) : '',
            'phone' => isset($_POST['phone']) ? sanitize_text_field($_POST['phone']) : '',
            'email' => isset($_POST['email']) ? sanitize_email($_POST['email']) : '',
            'office_name' => isset($_POST['office_name']) ? sanitize_text_field($_POST['office_name']) : '',
            'office_address' => isset($_POST['office_address']) ? sanitize_textarea_field($_POST['office_address']) : '',
            'bio' => isset($_POST['bio']) ? sanitize_textarea_field($_POST['bio']) : '',
            'photo_url' => isset($_POST['photo_url']) ? esc_url_raw($_POST['photo_url']) : '',
            'license_number' => isset($_POST['license_number']) ? sanitize_text_field($_POST['license_number']) : '',
            'mls_agent_id' => isset($_POST['mls_agent_id']) ? sanitize_text_field($_POST['mls_agent_id']) : '',
            'specialties' => isset($_POST['specialties']) ? sanitize_textarea_field($_POST['specialties']) : '',
            'is_active' => isset($_POST['is_active']) ? intval($_POST['is_active']) : 1,
            'snab_staff_id' => isset($_POST['snab_staff_id']) && !empty($_POST['snab_staff_id']) ? intval($_POST['snab_staff_id']) : null
        ];
        
        if (empty($agent_data['user_id'])) {
            wp_send_json_error('Please select a user');
        }
        
        // If display name is empty, use WordPress display name
        if (empty($agent_data['display_name'])) {
            $user = get_user_by('id', $agent_data['user_id']);
            if ($user) {
                $agent_data['display_name'] = $user->display_name;
            }
        }
        
        // If email is empty, use WordPress email
        if (empty($agent_data['email'])) {
            $user = get_user_by('id', $agent_data['user_id']);
            if ($user) {
                $agent_data['email'] = $user->user_email;
            }
        }
        
        $result = MLD_Agent_Client_Manager::save_agent($agent_data);
        
        if ($result) {
            wp_send_json_success([
                'message' => 'Agent saved successfully',
                'agent_id' => $result
            ]);
        } else {
            wp_send_json_error('Failed to save agent');
        }
    }
    
    /**
     * AJAX: Delete agent
     */
    public function ajax_delete_agent() {
        check_ajax_referer('mld_admin_agent_management', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        $agent_id = isset($_POST['agent_id']) ? intval($_POST['agent_id']) : 0;
        
        if (!$agent_id) {
            wp_send_json_error('Invalid agent ID');
        }
        
        $result = MLD_Agent_Client_Manager::delete_agent($agent_id);
        
        if ($result) {
            wp_send_json_success(['message' => 'Agent deleted successfully']);
        } else {
            wp_send_json_error('Failed to delete agent');
        }
    }
    
    /**
     * AJAX: Get agent details
     */
    public function ajax_get_agent_details() {
        check_ajax_referer('mld_admin_agent_management', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        $agent_id = isset($_POST['agent_id']) ? intval($_POST['agent_id']) : 0;
        
        if (!$agent_id) {
            wp_send_json_error('Invalid agent ID');
        }
        
        $agent = MLD_Agent_Client_Manager::get_agent($agent_id);
        
        if (!$agent) {
            wp_send_json_error('Agent not found');
        }
        
        // Get stats
        $agent['stats'] = MLD_Agent_Client_Manager::get_agent_stats($agent_id);
        
        wp_send_json_success($agent);
    }
    
    /**
     * AJAX: Get agent's clients
     */
    public function ajax_get_agent_clients() {
        check_ajax_referer('mld_admin_agent_management', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        $agent_id = isset($_POST['agent_id']) ? intval($_POST['agent_id']) : 0;
        $status = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : 'active';
        
        if (!$agent_id) {
            wp_send_json_error('Invalid agent ID');
        }
        
        $clients = MLD_Agent_Client_Manager::get_agent_clients($agent_id, $status);

        wp_send_json_success(['clients' => $clients]);
    }

    // =========================================
    // REFERRAL SYSTEM AJAX HANDLERS (v6.52.0)
    // =========================================

    /**
     * AJAX: Set default agent for organic signups
     */
    public function ajax_set_default_agent() {
        check_ajax_referer('mld_admin_agent_management', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        $agent_id = isset($_POST['agent_id']) ? intval($_POST['agent_id']) : 0;

        if (!class_exists('MLD_Referral_Manager')) {
            wp_send_json_error('Referral system not available');
        }

        if ($agent_id === 0) {
            // Clear default agent
            MLD_Referral_Manager::clear_default_agent();
            wp_send_json_success([
                'message' => 'Default agent cleared',
                'default_agent_id' => null
            ]);
        }

        // Verify agent exists
        $agent = MLD_Agent_Client_Manager::get_agent($agent_id);
        if (!$agent) {
            wp_send_json_error('Agent not found');
        }

        // Set as default
        $result = MLD_Referral_Manager::set_default_agent($agent_id);

        if ($result) {
            wp_send_json_success([
                'message' => 'Default agent set successfully',
                'default_agent_id' => $agent_id,
                'agent_name' => $agent['display_name']
            ]);
        } else {
            wp_send_json_error('Failed to set default agent');
        }
    }

    /**
     * AJAX: Get agent's referral info
     */
    public function ajax_get_agent_referral() {
        check_ajax_referer('mld_admin_agent_management', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        $agent_id = isset($_POST['agent_id']) ? intval($_POST['agent_id']) : 0;

        if (!$agent_id) {
            wp_send_json_error('Invalid agent ID');
        }

        if (!class_exists('MLD_Referral_Manager')) {
            wp_send_json_error('Referral system not available');
        }

        // Get or create referral code
        $code_data = MLD_Referral_Manager::get_or_create_agent_code($agent_id);

        if (!$code_data) {
            wp_send_json_error('Could not get referral code');
        }

        // Get stats
        $stats = MLD_Referral_Manager::get_agent_referral_stats($agent_id);

        // Get default agent status
        $default_agent_id = MLD_Referral_Manager::get_default_agent();

        wp_send_json_success([
            'referral_code' => $code_data['referral_code'],
            'referral_url' => home_url('/signup/?ref=' . $code_data['referral_code']),
            'is_active' => (bool) $code_data['is_active'],
            'created_at' => $code_data['created_at'],
            'is_default' => ($default_agent_id === $agent_id),
            'stats' => $stats
        ]);
    }

    /**
     * AJAX: Update agent's referral code
     */
    public function ajax_update_referral_code() {
        check_ajax_referer('mld_admin_agent_management', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        $agent_id = isset($_POST['agent_id']) ? intval($_POST['agent_id']) : 0;
        $new_code = isset($_POST['custom_code']) ? sanitize_text_field($_POST['custom_code']) : '';

        if (!$agent_id) {
            wp_send_json_error('Invalid agent ID');
        }

        if (empty($new_code)) {
            wp_send_json_error('Custom code is required');
        }

        if (!class_exists('MLD_Referral_Manager')) {
            wp_send_json_error('Referral system not available');
        }

        $result = MLD_Referral_Manager::update_referral_code($agent_id, $new_code);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        // Get updated code data
        $code_data = MLD_Referral_Manager::get_agent_referral_code($agent_id);

        wp_send_json_success([
            'message' => 'Referral code updated successfully',
            'referral_code' => $code_data['referral_code'],
            'referral_url' => home_url('/signup/?ref=' . $code_data['referral_code'])
        ]);
    }

    /**
     * AJAX: Regenerate agent's referral code
     */
    public function ajax_regenerate_referral_code() {
        check_ajax_referer('mld_admin_agent_management', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        $agent_id = isset($_POST['agent_id']) ? intval($_POST['agent_id']) : 0;

        if (!$agent_id) {
            wp_send_json_error('Invalid agent ID');
        }

        if (!class_exists('MLD_Referral_Manager')) {
            wp_send_json_error('Referral system not available');
        }

        $result = MLD_Referral_Manager::regenerate_referral_code($agent_id);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        // Get new code data
        $code_data = MLD_Referral_Manager::get_agent_referral_code($agent_id);

        wp_send_json_success([
            'message' => 'Referral code regenerated successfully',
            'referral_code' => $code_data['referral_code'],
            'referral_url' => home_url('/signup/?ref=' . $code_data['referral_code'])
        ]);
    }
}