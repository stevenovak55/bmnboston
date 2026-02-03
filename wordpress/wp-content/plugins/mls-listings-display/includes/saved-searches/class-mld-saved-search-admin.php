<?php
/**
 * MLS Listings Display - Saved Search Admin
 * 
 * Handles admin interface for managing saved searches
 * 
 * @package MLS_Listings_Display
 * @subpackage Saved_Searches
 * @since 3.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Saved_Search_Admin {
    
    /**
     * Initialize admin functionality
     */
    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu'], 20);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        
        // AJAX handlers
        add_action('wp_ajax_mld_admin_get_searches', [$this, 'ajax_get_searches']);
        add_action('wp_ajax_mld_admin_toggle_search', [$this, 'ajax_toggle_search']);
        add_action('wp_ajax_mld_admin_delete_search', [$this, 'ajax_delete_search']);
        add_action('wp_ajax_mld_admin_bulk_action', [$this, 'ajax_bulk_action']);
        add_action('wp_ajax_mld_admin_test_notification', [$this, 'ajax_test_notification']);
        add_action('wp_ajax_mld_admin_get_search_details', [$this, 'ajax_get_search_details']);
        add_action('wp_ajax_mld_admin_get_dashboard_stats', [$this, 'ajax_get_dashboard_stats']);
        add_action('wp_ajax_mld_toggle_search_notifications', [$this, 'ajax_toggle_notifications']);
        add_action('wp_ajax_mld_admin_get_recent_alerts', [$this, 'ajax_get_recent_alerts']);
    }
    
    /**
     * Add admin menu items
     */
    public function add_admin_menu() {
        add_submenu_page(
            'mls_listings_display',
            'Saved Searches',
            'Saved Searches',
            'manage_options',
            'mld-saved-searches',
            [$this, 'render_admin_page']
        );
    }
    
    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets($hook) {
        if ($hook !== 'mls-display_page_mld-saved-searches') {
            return;
        }

        // Enqueue MLDLogger first
        wp_enqueue_script(
            'mld-logger',
            MLD_PLUGIN_URL . 'assets/js/mld-logger.js',
            ['jquery'],
            MLD_VERSION,
            true
        );

        // Enqueue common utilities
        wp_enqueue_style(
            'mld-common-utils',
            MLD_PLUGIN_URL . 'assets/css/mld-common-utils.css',
            [],
            MLD_VERSION
        );

        wp_enqueue_script(
            'mld-common-utils',
            MLD_PLUGIN_URL . 'assets/js/mld-common-utils.js',
            ['jquery', 'mld-logger'],
            MLD_VERSION,
            true
        );

        // Enqueue styles - fix file path
        wp_enqueue_style(
            'mld-saved-search-admin',
            MLD_PLUGIN_URL . 'assets/css/saved-search-admin.css',
            ['mld-common-utils'],
            MLD_VERSION
        );

        // Enqueue scripts - fix file path
        wp_enqueue_script(
            'mld-saved-search-admin',
            MLD_PLUGIN_URL . 'assets/js/saved-search-admin.js',
            ['jquery', 'mld-common-utils', 'mld-logger'],
            MLD_VERSION,
            true
        );
        
        // Localize script
        wp_localize_script('mld-saved-search-admin', 'mldSavedSearchAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('mld_admin_saved_search'),
            'strings' => [
                'confirmDelete' => __('Are you sure you want to delete this saved search?', 'mld'),
                'confirmBulkDelete' => __('Are you sure you want to delete the selected searches?', 'mld'),
                'loading' => __('Loading...', 'mld'),
                'error' => __('An error occurred. Please try again.', 'mld'),
                'testSent' => __('Test notification sent!', 'mld'),
                'noSelection' => __('Please select at least one search.', 'mld')
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
        
        include MLD_PLUGIN_PATH . 'admin/views/saved-searches-page.php';
    }
    
    /**
     * AJAX: Get searches with pagination and filters
     */
    public function ajax_get_searches() {
        // Add error logging
        if (!isset($_POST['nonce'])) {
            wp_send_json_error('No nonce provided');
            return;
        }
        
        check_ajax_referer('mld_admin_saved_search', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        $page = isset($_POST['page']) ? intval($_POST['page']) : 1;
        $per_page = isset($_POST['per_page']) ? intval($_POST['per_page']) : 20;
        $search = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
        $status = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : '';
        $frequency = isset($_POST['frequency']) ? sanitize_text_field($_POST['frequency']) : '';
        $sort_by = isset($_POST['sort_by']) ? sanitize_text_field($_POST['sort_by']) : 'created_at';
        $sort_order = isset($_POST['sort_order']) ? sanitize_text_field($_POST['sort_order']) : 'DESC';
        
        global $wpdb;
        
        // Check if class exists
        if (!class_exists('MLD_Saved_Search_Database')) {
            wp_send_json_error('Database class not found');
            return;
        }
        
        $table = MLD_Saved_Search_Database::get_table_name('saved_searches');
        
        // Check if table exists
        if (!$wpdb->get_var("SHOW TABLES LIKE '$table'")) {
            wp_send_json_error('Database table does not exist. Please deactivate and reactivate the plugin.');
            return;
        }
        
        // Build query
        $where_clauses = ['1=1'];
        $where_values = [];
        
        if ($search) {
            $where_clauses[] = '(u.user_email LIKE %s OR u.display_name LIKE %s OR s.name LIKE %s)';
            $search_term = '%' . $wpdb->esc_like($search) . '%';
            $where_values[] = $search_term;
            $where_values[] = $search_term;
            $where_values[] = $search_term;
        }
        
        if ($status === 'active') {
            $where_clauses[] = 's.is_active = 1';
        } elseif ($status === 'inactive') {
            $where_clauses[] = 's.is_active = 0';
        }
        
        if ($frequency) {
            $where_clauses[] = 's.notification_frequency = %s';
            $where_values[] = $frequency;
        }
        
        $where_sql = implode(' AND ', $where_clauses);
        
        // Get total count
        $count_sql = "SELECT COUNT(*) FROM {$table} s 
                      LEFT JOIN {$wpdb->users} u ON s.user_id = u.ID 
                      WHERE {$where_sql}";
        
        if ($where_values) {
            $count_sql = $wpdb->prepare($count_sql, $where_values);
        }
        
        $total = $wpdb->get_var($count_sql);
        
        // Get searches
        $offset = ($page - 1) * $per_page;
        $allowed_sort_columns = ['created_at', 'last_notified_at', 'name', 'user_email'];
        if (!in_array($sort_by, $allowed_sort_columns)) {
            $sort_by = 'created_at';
        }
        
        // Note: Double %% to escape percent signs in DATE_FORMAT for wpdb->prepare()
        $sql = "SELECT s.*, u.user_email, u.display_name,
                (SELECT COUNT(DISTINCT DATE_FORMAT(sent_at, '%%Y-%%m-%%d %%H:%%i')) FROM {$wpdb->prefix}mld_notification_tracker
                 WHERE search_id = s.id) as notifications_sent
                FROM {$table} s
                LEFT JOIN {$wpdb->users} u ON s.user_id = u.ID
                WHERE {$where_sql}
                ORDER BY {$sort_by} {$sort_order}
                LIMIT %d OFFSET %d";
        
        $query_values = array_merge($where_values, [$per_page, $offset]);
        $searches = $wpdb->get_results($wpdb->prepare($sql, $query_values), ARRAY_A);

        // Check for database errors
        if ($wpdb->last_error) {
            error_log('MLD Saved Search Admin AJAX Error: ' . $wpdb->last_error);
            wp_send_json_error('Database query error: ' . $wpdb->last_error);
            return;
        }

        // Ensure searches is an array (even if empty)
        if ($searches === null) {
            $searches = [];
        }

        // Format data for display
        foreach ($searches as &$search) {
            // Handle filters that might already be decoded
            if (is_string($search['filters'])) {
                $search['filters_decoded'] = json_decode($search['filters'], true);
            } else {
                $search['filters_decoded'] = $search['filters'];
            }
            
            // Handle polygon shapes that might already be decoded
            if (is_string($search['polygon_shapes'])) {
                $search['polygon_shapes_decoded'] = json_decode($search['polygon_shapes'], true);
            } else {
                $search['polygon_shapes_decoded'] = $search['polygon_shapes'];
            }
            // Use wp_date() with proper timezone handling
            // strtotime() assumes UTC but dates are stored in WP timezone - use DateTime to parse correctly
            $created_date = new DateTime($search['created_at'], wp_timezone());
            $search['created_at_formatted'] = wp_date(get_option('date_format') . ' ' . get_option('time_format'), $created_date->getTimestamp());
            if ($search['last_notified_at']) {
                $notified_date = new DateTime($search['last_notified_at'], wp_timezone());
                $search['last_notified_formatted'] = wp_date(get_option('date_format') . ' ' . get_option('time_format'), $notified_date->getTimestamp());
            } else {
                $search['last_notified_formatted'] = 'Never';
            }
        }
        
        wp_send_json_success([
            'searches' => $searches,
            'total' => $total,
            'pages' => ceil($total / $per_page),
            'current_page' => $page
        ]);
    }
    
    /**
     * AJAX: Toggle search active status
     */
    public function ajax_toggle_search() {
        check_ajax_referer('mld_admin_saved_search', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        $search_id = isset($_POST['search_id']) ? intval($_POST['search_id']) : 0;
        if (!$search_id) {
            wp_send_json_error('Invalid search ID');
        }
        
        global $wpdb;
        $table = MLD_Saved_Search_Database::get_table_name('saved_searches');
        
        // Get current status
        $current_status = $wpdb->get_var($wpdb->prepare(
            "SELECT is_active FROM {$table} WHERE id = %d",
            $search_id
        ));
        
        if ($current_status === null) {
            wp_send_json_error('Search not found');
        }
        
        // Toggle status
        $new_status = $current_status ? 0 : 1;
        $updated = $wpdb->update(
            $table,
            ['is_active' => $new_status],
            ['id' => $search_id],
            ['%d'],
            ['%d']
        );
        
        if ($updated === false) {
            wp_send_json_error('Failed to update status');
        }
        
        wp_send_json_success([
            'new_status' => $new_status,
            'message' => $new_status ? 'Search activated' : 'Search deactivated'
        ]);
    }

    /**
     * AJAX: Toggle notification settings
     */
    public function ajax_toggle_notifications() {
        // Accept both nonces since admin.js might use different one
        $nonce_verified = false;

        if (isset($_POST['nonce'])) {
            // Try saved search nonce first
            if (wp_verify_nonce($_POST['nonce'], 'mld_admin_saved_search')) {
                $nonce_verified = true;
            }
            // Try general admin nonce
            elseif (wp_verify_nonce($_POST['nonce'], 'mld_admin_nonce')) {
                $nonce_verified = true;
            }
        }

        if (!$nonce_verified) {
            wp_send_json_error('Security check failed');
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        $search_id = isset($_POST['search_id']) ? intval($_POST['search_id']) : 0;
        $enabled = isset($_POST['enabled']) ? intval($_POST['enabled']) : 0;

        if (!$search_id) {
            wp_send_json_error('Invalid search ID');
        }

        global $wpdb;
        $table = MLD_Saved_Search_Database::get_table_name('saved_searches');

        // Get current search data
        $search = $wpdb->get_row($wpdb->prepare(
            "SELECT id, notification_frequency FROM {$table} WHERE id = %d",
            $search_id
        ));

        if (!$search) {
            wp_send_json_error('Search not found');
        }

        // Update notification status
        // If enabling and frequency is NULL, set to 'daily' as default
        // If disabling, set to NULL
        if ($enabled) {
            // If re-enabling and it was previously NULL, set to daily
            $frequency = (!$search->notification_frequency || $search->notification_frequency === '') ? 'daily' : $search->notification_frequency;
            $update_data = ['notification_frequency' => $frequency];
        } else {
            // Disable by setting to NULL
            $update_data = ['notification_frequency' => null];
        }

        $updated = $wpdb->update(
            $table,
            $update_data,
            ['id' => $search_id],
            $enabled ? ['%s'] : ['%s'],
            ['%d']
        );

        if ($updated === false) {
            wp_send_json_error('Failed to update notification settings');
        }

        wp_send_json_success([
            'enabled' => $enabled,
            'message' => $enabled ? 'Email notifications enabled' : 'Email notifications disabled'
        ]);
    }

    /**
     * AJAX: Delete search
     */
    public function ajax_delete_search() {
        check_ajax_referer('mld_admin_saved_search', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        $search_id = isset($_POST['search_id']) ? intval($_POST['search_id']) : 0;
        if (!$search_id) {
            wp_send_json_error('Invalid search ID');
        }
        
        $deleted = MLD_Saved_Searches::delete_search($search_id);
        
        if (!$deleted) {
            wp_send_json_error('Failed to delete search');
        }
        
        wp_send_json_success(['message' => 'Search deleted successfully']);
    }
    
    /**
     * AJAX: Bulk actions
     */
    public function ajax_bulk_action() {
        check_ajax_referer('mld_admin_saved_search', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        $action = isset($_POST['action_type']) ? sanitize_text_field($_POST['action_type']) : '';
        $search_ids = isset($_POST['search_ids']) ? array_map('intval', $_POST['search_ids']) : [];
        
        if (empty($search_ids)) {
            wp_send_json_error('No searches selected');
        }
        
        global $wpdb;
        $table = MLD_Saved_Search_Database::get_table_name('saved_searches');
        
        switch ($action) {
            case 'activate':
                $updated = $wpdb->query($wpdb->prepare(
                    "UPDATE {$table} SET is_active = 1 WHERE id IN (" . 
                    implode(',', array_fill(0, count($search_ids), '%d')) . ")",
                    $search_ids
                ));
                wp_send_json_success(['message' => sprintf('%d searches activated', $updated)]);
                break;
                
            case 'deactivate':
                $updated = $wpdb->query($wpdb->prepare(
                    "UPDATE {$table} SET is_active = 0 WHERE id IN (" . 
                    implode(',', array_fill(0, count($search_ids), '%d')) . ")",
                    $search_ids
                ));
                wp_send_json_success(['message' => sprintf('%d searches deactivated', $updated)]);
                break;
                
            case 'delete':
                $deleted = 0;
                foreach ($search_ids as $search_id) {
                    if (MLD_Saved_Searches::delete_search($search_id)) {
                        $deleted++;
                    }
                }
                wp_send_json_success(['message' => sprintf('%d searches deleted', $deleted)]);
                break;
                
            default:
                wp_send_json_error('Invalid action');
        }
    }
    
    /**
     * AJAX: Test notification
     */
    public function ajax_test_notification() {
        // Use WP_DEBUG constant instead if debugging is needed
        
        // Wrap in try-catch for better error handling
        try {
            // Enable error reporting for debugging
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_reporting(E_ALL);
                ini_set('display_errors', 1);
            }

            check_ajax_referer('mld_admin_saved_search', 'nonce');

            if (!current_user_can('manage_options')) {
                wp_send_json_error('Insufficient permissions');
            }

            $search_id = isset($_POST['search_id']) ? intval($_POST['search_id']) : 0;
            if (!$search_id) {
                wp_send_json_error('Invalid search ID');
            }

            // Log the attempt
            MLD_Logger::info('Test notification requested', [
                'search_id' => $search_id,
                'user_id' => get_current_user_id()
            ]);

            // Check if notification class exists
            if (!class_exists('MLD_Saved_Search_Notifications')) {
                MLD_Logger::error('Notification class not found');
                wp_send_json_error('Notification class not found - check if file is loaded');
            }

            // Check if method exists
            if (!method_exists('MLD_Saved_Search_Notifications', 'test_notification')) {
                MLD_Logger::error('Test notification method not found');
                wp_send_json_error('Test notification method not found');
            }

            $sent = MLD_Saved_Search_Notifications::test_notification($search_id);

            if (!$sent) {
                MLD_Logger::error('Test notification send failed', ['search_id' => $search_id]);
                wp_send_json_error('Failed to send test notification - check WordPress email configuration and error logs');
            }

            MLD_Logger::info('Test notification sent successfully', ['search_id' => $search_id]);
            wp_send_json_success(['message' => 'Test notification sent successfully']);

        } catch (Exception $e) {
            MLD_Logger::error('Exception in test notification', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            wp_send_json_error('Exception: ' . $e->getMessage());
        }
    }
    
    /**
     * AJAX: Get search details
     */
    public function ajax_get_search_details() {
        check_ajax_referer('mld_admin_saved_search', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        $search_id = isset($_POST['search_id']) ? intval($_POST['search_id']) : 0;
        if (!$search_id) {
            wp_send_json_error('Invalid search ID');
        }
        
        $search = MLD_Saved_Searches::get_search($search_id);
        if (!$search) {
            wp_send_json_error('Search not found');
        }
        
        // Convert object to array if needed
        if (is_object($search)) {
            $search = (array) $search;
        }
        
        // Get user info
        $user = get_user_by('id', $search['user_id']);
        if ($user) {
            $search['user_email'] = $user->user_email;
            $search['user_display_name'] = $user->display_name;
        }
        
        // Decode JSON fields
        // Handle filters that might already be decoded
        if (is_string($search['filters'])) {
            $search['filters_decoded'] = json_decode($search['filters'], true);
        } else {
            $search['filters_decoded'] = $search['filters'];
        }
        
        // Handle polygon shapes that might already be decoded
        if (is_string($search['polygon_shapes'])) {
            $search['polygon_shapes_decoded'] = json_decode($search['polygon_shapes'], true);
        } else {
            $search['polygon_shapes_decoded'] = $search['polygon_shapes'];
        }
        
        // Get recent notifications
        global $wpdb;
        $results_table = MLD_Saved_Search_Database::get_table_name('saved_search_results');
        $recent_notifications = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$results_table} 
             WHERE saved_search_id = %d AND notified_at IS NOT NULL 
             ORDER BY notified_at DESC LIMIT 10",
            $search_id
        ), ARRAY_A);
        
        $search['recent_notifications'] = $recent_notifications;

        wp_send_json_success($search);
    }

    /**
     * AJAX: Get dashboard statistics
     */
    public function ajax_get_dashboard_stats() {
        check_ajax_referer('mld_admin_saved_search', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        global $wpdb;
        $table = MLD_Saved_Search_Database::get_table_name('saved_searches');
        $tracker_table = $wpdb->prefix . 'mld_notification_tracker';

        // Get active searches count
        $active_searches = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table} WHERE is_active = 1"
        );

        // Get total unique emails sent (count unique search_id + minute combinations)
        $notifications_sent = $wpdb->get_var(
            "SELECT COUNT(DISTINCT CONCAT(search_id, DATE_FORMAT(sent_at, '%Y-%m-%d %H:%i'))) FROM {$tracker_table}"
        );

        // Get users with saved searches
        $users_with_searches = $wpdb->get_var(
            "SELECT COUNT(DISTINCT user_id) FROM {$table} WHERE is_active = 1"
        );

        // Get unique emails sent today from notification tracker
        // Use WordPress timezone-aware date instead of MySQL CURDATE()
        $wp_today = wp_date('Y-m-d');
        $alerts_today = 0;
        if ($wpdb->get_var("SHOW TABLES LIKE '{$tracker_table}'") === $tracker_table) {
            $alerts_today = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(DISTINCT CONCAT(search_id, DATE_FORMAT(sent_at, '%%Y-%%m-%%d %%H:%%i')))
                 FROM {$tracker_table} WHERE DATE(sent_at) = %s",
                $wp_today
            ));
        }

        wp_send_json_success([
            'active' => $active_searches ?: 0,
            'notifications' => $notifications_sent ?: 0,
            'users' => $users_with_searches ?: 0,
            'alerts_today' => $alerts_today ?: 0
        ]);
    }

    /**
     * AJAX: Get recent alerts from notification tracker
     */
    public function ajax_get_recent_alerts() {
        check_ajax_referer('mld_admin_saved_search', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        $page = isset($_POST['page']) ? intval($_POST['page']) : 1;
        $per_page = isset($_POST['per_page']) ? intval($_POST['per_page']) : 20;
        $offset = ($page - 1) * $per_page;

        global $wpdb;
        $tracker_table = $wpdb->prefix . 'mld_notification_tracker';
        $searches_table = MLD_Saved_Search_Database::get_table_name('saved_searches');

        // Check if tracker table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '{$tracker_table}'") !== $tracker_table) {
            wp_send_json_success([
                'alerts' => [],
                'total' => 0,
                'pages' => 0,
                'current_page' => 1
            ]);
            return;
        }

        // Get total count
        $total = $wpdb->get_var("SELECT COUNT(*) FROM {$tracker_table}");

        // Get recent alerts with search and user info
        $alerts = $wpdb->get_results($wpdb->prepare(
            "SELECT
                t.id,
                t.user_id,
                t.mls_number,
                t.search_id,
                t.notification_type,
                t.sent_at as notified_at,
                u.user_email,
                u.display_name,
                s.name as search_name,
                s.notification_frequency
            FROM {$tracker_table} t
            LEFT JOIN {$wpdb->users} u ON t.user_id = u.ID
            LEFT JOIN {$searches_table} s ON t.search_id = s.id
            ORDER BY t.sent_at DESC
            LIMIT %d OFFSET %d",
            $per_page,
            $offset
        ), ARRAY_A);

        // Format the alerts for display - use wp_date() with proper timezone handling
        // strtotime() assumes UTC but dates are stored in WP timezone - use DateTime to parse correctly
        foreach ($alerts as &$alert) {
            $notified_date = new DateTime($alert['notified_at'], wp_timezone());
            $alert['notified_at_formatted'] = wp_date(
                get_option('date_format') . ' ' . get_option('time_format'),
                $notified_date->getTimestamp()
            );
            $alert['notification_type_display'] = $this->format_notification_type($alert['notification_type']);
            $alert['frequency_display'] = $this->format_frequency($alert['notification_frequency']);
        }

        wp_send_json_success([
            'alerts' => $alerts,
            'total' => $total,
            'pages' => ceil($total / $per_page),
            'current_page' => $page
        ]);
    }

    /**
     * Format notification type for display
     */
    private function format_notification_type($type) {
        $types = [
            'new_listing' => '🏠 New Listing',
            'price_change' => '💰 Price Change',
            'status_change' => '📋 Status Change',
            'test' => '🧪 Test'
        ];
        return isset($types[$type]) ? $types[$type] : ucfirst($type);
    }

    /**
     * Format frequency for display
     */
    private function format_frequency($frequency) {
        $frequencies = [
            'instant' => '⚡ Instant',
            'fifteen_min' => '⏱️ 15 min',
            'hourly' => '⏰ Hourly',
            'daily' => '📅 Daily',
            'weekly' => '📆 Weekly',
            'never' => '🔕 Never'
        ];
        return isset($frequencies[$frequency]) ? $frequencies[$frequency] : $frequency;
    }
}