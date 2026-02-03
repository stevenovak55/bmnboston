<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Advanced User Management and Role System
 * Version: 1.0.0 (User Authentication & Role Management)
 */
class BME_User_Manager {
    
    private $user_roles = [];
    private $capabilities = [];
    private $session_manager;
    
    public function __construct() {
        $this->init_user_roles();
        $this->init_capabilities();
        $this->init_hooks();
        $this->session_manager = new BME_Session_Manager();
    }
    
    /**
     * Initialize BME-specific user roles
     */
    private function init_user_roles() {
        $this->user_roles = [
            'bme_admin' => [
                'display_name' => __('BME Administrator', 'bridge-mls-extractor-pro'),
                'capabilities' => [
                    'manage_bme_extractions',
                    'manage_bme_settings',
                    'export_bme_data',
                    'manage_bme_users',
                    'access_bme_api'
                ]
            ],
            'bme_manager' => [
                'display_name' => __('BME Manager', 'bridge-mls-extractor-pro'),
                'capabilities' => [
                    'manage_bme_extractions',
                    'export_bme_data',
                    'access_bme_api'
                ]
            ],
            'bme_agent' => [
                'display_name' => __('Real Estate Agent', 'bridge-mls-extractor-pro'),
                'capabilities' => [
                    'view_bme_listings',
                    'save_bme_searches',
                    'export_bme_listings',
                    'manage_bme_favorites'
                ]
            ],
            'bme_client' => [
                'display_name' => __('Client/Buyer', 'bridge-mls-extractor-pro'),
                'capabilities' => [
                    'view_bme_listings',
                    'save_bme_searches',
                    'manage_bme_favorites'
                ]
            ]
        ];
    }
    
    /**
     * Initialize capability definitions
     */
    private function init_capabilities() {
        $this->capabilities = [
            'manage_bme_extractions' => __('Manage MLS Extractions', 'bridge-mls-extractor-pro'),
            'manage_bme_settings' => __('Manage BME Settings', 'bridge-mls-extractor-pro'),
            'export_bme_data' => __('Export MLS Data', 'bridge-mls-extractor-pro'),
            'manage_bme_users' => __('Manage BME Users', 'bridge-mls-extractor-pro'),
            'access_bme_api' => __('Access BME API', 'bridge-mls-extractor-pro'),
            'view_bme_listings' => __('View MLS Listings', 'bridge-mls-extractor-pro'),
            'save_bme_searches' => __('Save Searches', 'bridge-mls-extractor-pro'),
            'export_bme_listings' => __('Export Listings', 'bridge-mls-extractor-pro'),
            'manage_bme_favorites' => __('Manage Favorites', 'bridge-mls-extractor-pro')
        ];
    }
    
    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        add_action('init', [$this, 'register_user_roles']);
        add_action('wp_login', [$this, 'handle_user_login'], 10, 2);
        add_action('wp_logout', [$this, 'handle_user_logout']);
        add_action('user_register', [$this, 'handle_user_registration']);
        
        // AJAX handlers
        add_action('wp_ajax_bme_update_user_profile', [$this, 'ajax_update_user_profile']);
        add_action('wp_ajax_bme_change_password', [$this, 'ajax_change_password']);
        add_action('wp_ajax_bme_get_user_activity', [$this, 'ajax_get_user_activity']);
        
        // Admin hooks
        add_action('show_user_profile', [$this, 'add_user_profile_fields']);
        add_action('edit_user_profile', [$this, 'add_user_profile_fields']);
        add_action('personal_options_update', [$this, 'save_user_profile_fields']);
        add_action('edit_user_profile_update', [$this, 'save_user_profile_fields']);
        
        // Security hooks
        add_action('wp_login_failed', [$this, 'handle_failed_login']);
        add_filter('authenticate', [$this, 'check_user_permissions'], 30, 3);
    }
    
    /**
     * Register custom user roles with WordPress
     */
    public function register_user_roles() {
        foreach ($this->user_roles as $role_key => $role_data) {
            if (!get_role($role_key)) {
                add_role($role_key, $role_data['display_name'], array_fill_keys($role_data['capabilities'], true));
                error_log("BME User: Registered role {$role_key}");
            } else {
                // Update existing role capabilities
                $role = get_role($role_key);
                foreach ($role_data['capabilities'] as $cap) {
                    $role->add_cap($cap);
                }
            }
        }
        
        // Add capabilities to administrator role
        $admin_role = get_role('administrator');
        if ($admin_role) {
            foreach (array_keys($this->capabilities) as $cap) {
                $admin_role->add_cap($cap);
            }
        }
    }
    
    /**
     * Handle user login events
     */
    public function handle_user_login($user_login, $user) {
        // Log successful login
        $this->log_user_activity($user->ID, 'login', [
            'ip_address' => $this->get_user_ip(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
            'timestamp' => current_time('mysql')
        ]);
        
        // Update last login time
        update_user_meta($user->ID, 'bme_last_login', current_time('mysql'));
        update_user_meta($user->ID, 'bme_login_count', (int)get_user_meta($user->ID, 'bme_login_count', true) + 1);
        
        // Initialize user session
        $this->session_manager->init_user_session($user->ID);
        
        // Clear failed login attempts
        delete_user_meta($user->ID, 'bme_failed_login_attempts');
    }
    
    /**
     * Handle user logout events
     */
    public function handle_user_logout() {
        $user_id = get_current_user_id();
        if ($user_id) {
            $this->log_user_activity($user_id, 'logout', [
                'session_duration' => $this->session_manager->get_session_duration($user_id)
            ]);
            
            $this->session_manager->end_user_session($user_id);
        }
    }
    
    /**
     * Handle new user registration
     */
    public function handle_user_registration($user_id) {
        // Set default BME role for new users
        $user = get_user_by('id', $user_id);
        
        // Assign default role based on email domain or other criteria
        $default_role = $this->determine_default_role($user);
        $user->set_role($default_role);
        
        // Initialize user profile
        update_user_meta($user_id, 'bme_registration_date', current_time('mysql'));
        update_user_meta($user_id, 'bme_user_preferences', [
            'notifications' => true,
            'email_alerts' => true,
            'dashboard_layout' => 'default'
        ]);
        
        $this->log_user_activity($user_id, 'registration', [
            'role_assigned' => $default_role,
            'registration_method' => 'manual'
        ]);
    }
    
    /**
     * Determine default role for new user
     */
    private function determine_default_role($user) {
        $email = $user->user_email;
        
        // Check if email belongs to known real estate domains
        $agent_domains = ['realtor.com', 'remax.com', 'coldwellbanker.com', 'kw.com'];
        $email_domain = substr(strrchr($email, "@"), 1);
        
        if (in_array($email_domain, $agent_domains)) {
            return 'bme_agent';
        }
        
        // Default to client role
        return 'bme_client';
    }
    
    /**
     * Check user permissions during authentication
     */
    public function check_user_permissions($user, $username, $password) {
        if (is_wp_error($user)) {
            return $user;
        }
        
        if ($user && is_a($user, 'WP_User')) {
            // Check if user account is active
            $account_status = get_user_meta($user->ID, 'bme_account_status', true);
            if ($account_status === 'suspended') {
                return new WP_Error('account_suspended', __('Your account has been suspended. Please contact administrator.', 'bridge-mls-extractor-pro'));
            }
            
            // Check for too many failed login attempts
            // v3.31: Made less aggressive - 20 attempts, 5 min lockout (was 5 attempts, 30 min)
            $failed_attempts = (int)get_user_meta($user->ID, 'bme_failed_login_attempts', true);
            if ($failed_attempts >= 20) {
                $lockout_time = get_user_meta($user->ID, 'bme_lockout_time', true);
                if ($lockout_time && (time() - $lockout_time) < 300) { // 5 minutes lockout (was 30)
                    return new WP_Error('account_locked', __('Account temporarily locked due to too many failed attempts. Try again in 5 minutes.', 'bridge-mls-extractor-pro'));
                }
            }
        }
        
        return $user;
    }
    
    /**
     * Handle failed login attempts
     */
    public function handle_failed_login($username) {
        $user = get_user_by('login', $username) ?: get_user_by('email', $username);
        
        if ($user) {
            $failed_attempts = (int)get_user_meta($user->ID, 'bme_failed_login_attempts', true) + 1;
            update_user_meta($user->ID, 'bme_failed_login_attempts', $failed_attempts);
            
            if ($failed_attempts >= 20) {
                update_user_meta($user->ID, 'bme_lockout_time', time());
            }
            
            $this->log_user_activity($user->ID, 'failed_login', [
                'ip_address' => $this->get_user_ip(),
                'attempt_count' => $failed_attempts
            ]);
        }
    }
    
    /**
     * Add custom fields to user profile
     */
    public function add_user_profile_fields($user) {
        if (!current_user_can('manage_bme_users') && get_current_user_id() !== $user->ID) {
            return;
        }
        
        $preferences = get_user_meta($user->ID, 'bme_user_preferences', true) ?: [];
        $last_login = get_user_meta($user->ID, 'bme_last_login', true);
        $login_count = get_user_meta($user->ID, 'bme_login_count', true);
        ?>
        <h3><?php _e('BME Profile Information', 'bridge-mls-extractor-pro'); ?></h3>
        <table class="form-table">
            <tr>
                <th><label for="bme_license_number"><?php _e('License Number', 'bridge-mls-extractor-pro'); ?></label></th>
                <td>
                    <input type="text" name="bme_license_number" id="bme_license_number" 
                           value="<?php echo esc_attr(get_user_meta($user->ID, 'bme_license_number', true)); ?>" 
                           class="regular-text" />
                    <p class="description"><?php _e('Real estate license number (for agents).', 'bridge-mls-extractor-pro'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="bme_brokerage"><?php _e('Brokerage', 'bridge-mls-extractor-pro'); ?></label></th>
                <td>
                    <input type="text" name="bme_brokerage" id="bme_brokerage" 
                           value="<?php echo esc_attr(get_user_meta($user->ID, 'bme_brokerage', true)); ?>" 
                           class="regular-text" />
                </td>
            </tr>
            <tr>
                <th><label><?php _e('Notification Preferences', 'bridge-mls-extractor-pro'); ?></label></th>
                <td>
                    <fieldset>
                        <label>
                            <input type="checkbox" name="bme_notifications" value="1" 
                                   <?php checked(!empty($preferences['notifications'])); ?> />
                            <?php _e('Enable system notifications', 'bridge-mls-extractor-pro'); ?>
                        </label><br>
                        <label>
                            <input type="checkbox" name="bme_email_alerts" value="1" 
                                   <?php checked(!empty($preferences['email_alerts'])); ?> />
                            <?php _e('Enable email alerts', 'bridge-mls-extractor-pro'); ?>
                        </label>
                    </fieldset>
                </td>
            </tr>
            <?php if ($last_login): ?>
            <tr>
                <th><?php _e('Last Login', 'bridge-mls-extractor-pro'); ?></th>
                <td><?php echo esc_html(mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $last_login)); ?></td>
            </tr>
            <?php endif; ?>
            <?php if ($login_count): ?>
            <tr>
                <th><?php _e('Total Logins', 'bridge-mls-extractor-pro'); ?></th>
                <td><?php echo esc_html($login_count); ?></td>
            </tr>
            <?php endif; ?>
        </table>
        <?php
    }
    
    /**
     * Save custom user profile fields
     */
    public function save_user_profile_fields($user_id) {
        if (!current_user_can('manage_bme_users') && get_current_user_id() !== $user_id) {
            return;
        }
        
        update_user_meta($user_id, 'bme_license_number', sanitize_text_field($_POST['bme_license_number'] ?? ''));
        update_user_meta($user_id, 'bme_brokerage', sanitize_text_field($_POST['bme_brokerage'] ?? ''));
        
        $preferences = [
            'notifications' => !empty($_POST['bme_notifications']),
            'email_alerts' => !empty($_POST['bme_email_alerts']),
        ];
        
        update_user_meta($user_id, 'bme_user_preferences', $preferences);
    }
    
    /**
     * AJAX: Update user profile
     */
    public function ajax_update_user_profile() {
        check_ajax_referer('bme_user_nonce', 'nonce');
        
        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error(__('User not logged in.', 'bridge-mls-extractor-pro'));
        }
        
        $profile_data = $_POST['profile_data'] ?? [];
        
        // Sanitize and update profile data
        foreach ($profile_data as $key => $value) {
            switch ($key) {
                case 'display_name':
                case 'first_name':
                case 'last_name':
                    wp_update_user([
                        'ID' => $user_id,
                        $key => sanitize_text_field($value)
                    ]);
                    break;
                    
                case 'bme_license_number':
                case 'bme_brokerage':
                    update_user_meta($user_id, $key, sanitize_text_field($value));
                    break;
                    
                case 'preferences':
                    if (is_array($value)) {
                        update_user_meta($user_id, 'bme_user_preferences', $value);
                    }
                    break;
            }
        }
        
        $this->log_user_activity($user_id, 'profile_update');
        
        wp_send_json_success(__('Profile updated successfully.', 'bridge-mls-extractor-pro'));
    }
    
    /**
     * AJAX: Change password
     */
    public function ajax_change_password() {
        check_ajax_referer('bme_user_nonce', 'nonce');
        
        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error(__('User not logged in.', 'bridge-mls-extractor-pro'));
        }
        
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        
        // Validate current password
        $user = get_user_by('id', $user_id);
        if (!wp_check_password($current_password, $user->user_pass, $user_id)) {
            wp_send_json_error(__('Current password is incorrect.', 'bridge-mls-extractor-pro'));
        }
        
        // Validate new password
        if ($new_password !== $confirm_password) {
            wp_send_json_error(__('New passwords do not match.', 'bridge-mls-extractor-pro'));
        }
        
        if (strlen($new_password) < 8) {
            wp_send_json_error(__('New password must be at least 8 characters long.', 'bridge-mls-extractor-pro'));
        }
        
        // Update password
        wp_update_user([
            'ID' => $user_id,
            'user_pass' => $new_password
        ]);
        
        $this->log_user_activity($user_id, 'password_change');
        
        wp_send_json_success(__('Password changed successfully.', 'bridge-mls-extractor-pro'));
    }
    
    /**
     * AJAX: Get user activity log
     */
    public function ajax_get_user_activity() {
        check_ajax_referer('bme_user_nonce', 'nonce');
        
        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error(__('User not logged in.', 'bridge-mls-extractor-pro'));
        }
        
        $activities = $this->get_user_activities($user_id, 20); // Last 20 activities
        
        wp_send_json_success($activities);
    }
    
    /**
     * Log user activity
     */
    private function log_user_activity($user_id, $action, $details = []) {
        $activities = get_user_meta($user_id, 'bme_user_activities', true) ?: [];
        
        $activity = [
            'action' => $action,
            'timestamp' => current_time('mysql'),
            'ip_address' => $this->get_user_ip(),
            'details' => $details
        ];
        
        array_unshift($activities, $activity);
        
        // Keep only last 100 activities
        if (count($activities) > 100) {
            $activities = array_slice($activities, 0, 100);
        }
        
        update_user_meta($user_id, 'bme_user_activities', $activities);
    }
    
    /**
     * Get user activities
     */
    public function get_user_activities($user_id, $limit = 20) {
        $activities = get_user_meta($user_id, 'bme_user_activities', true) ?: [];
        return array_slice($activities, 0, $limit);
    }
    
    /**
     * Get user IP address
     */
    private function get_user_ip() {
        $ip_keys = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'];
        
        foreach ($ip_keys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = $_SERVER[$key];
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        
        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }
    
    /**
     * Check if user has specific BME capability
     */
    public function user_can($user_id, $capability) {
        $user = get_user_by('id', $user_id);
        return $user && $user->has_cap($capability);
    }
    
    /**
     * Get users by BME role
     */
    public function get_users_by_bme_role($role) {
        return get_users(['role' => $role]);
    }
    
    /**
     * Get user statistics
     */
    public function get_user_statistics() {
        $stats = [];
        
        foreach ($this->user_roles as $role_key => $role_data) {
            $users = $this->get_users_by_bme_role($role_key);
            $stats[$role_key] = [
                'count' => count($users),
                'display_name' => $role_data['display_name']
            ];
        }
        
        // Add activity statistics
        $stats['activity'] = [
            'total_logins_today' => $this->get_login_count_today(),
            'active_sessions' => $this->session_manager->get_active_session_count(),
            'new_registrations_week' => $this->get_new_registrations_count(7)
        ];
        
        return $stats;
    }
    
    /**
     * Get login count for today
     */
    private function get_login_count_today() {
        global $wpdb;
        
        $today = date('Y-m-d');
        $count = 0;
        
        $users = get_users();
        foreach ($users as $user) {
            $activities = get_user_meta($user->ID, 'bme_user_activities', true) ?: [];
            foreach ($activities as $activity) {
                if ($activity['action'] === 'login' && strpos($activity['timestamp'], $today) === 0) {
                    $count++;
                }
            }
        }
        
        return $count;
    }
    
    /**
     * Get new registrations count
     */
    private function get_new_registrations_count($days) {
        $since = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        
        $users = get_users([
            'meta_query' => [
                [
                    'key' => 'bme_registration_date',
                    'value' => $since,
                    'compare' => '>='
                ]
            ]
        ]);
        
        return count($users);
    }
}

/**
 * Session Management Helper Class
 */
class BME_Session_Manager {
    
    private $session_table;
    
    public function __construct() {
        global $wpdb;
        $this->session_table = $wpdb->prefix . 'bme_user_sessions';
        $this->maybe_create_session_table();
    }
    
    /**
     * Create session tracking table
     */
    private function maybe_create_session_table() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$this->session_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            session_token VARCHAR(255) NOT NULL,
            ip_address VARCHAR(45),
            user_agent TEXT,
            login_time DATETIME NOT NULL,
            last_activity DATETIME NOT NULL,
            logout_time DATETIME NULL,
            is_active TINYINT(1) DEFAULT 1,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY session_token (session_token),
            KEY is_active (is_active)
        ) {$charset_collate}";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    /**
     * Initialize user session
     */
    public function init_user_session($user_id) {
        global $wpdb;
        
        $session_token = wp_generate_password(32, false);
        
        $wpdb->insert($this->session_table, [
            'user_id' => $user_id,
            'session_token' => $session_token,
            'ip_address' => $this->get_user_ip(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
            'login_time' => current_time('mysql'),
            'last_activity' => current_time('mysql'),
            'is_active' => 1
        ]);
        
        // Store session token in user meta
        update_user_meta($user_id, '_bme_session_token', $session_token);
        
        return $session_token;
    }
    
    /**
     * End user session
     */
    public function end_user_session($user_id) {
        global $wpdb;
        
        $wpdb->update($this->session_table, [
            'logout_time' => current_time('mysql'),
            'is_active' => 0
        ], [
            'user_id' => $user_id,
            'is_active' => 1
        ]);
        
        delete_user_meta($user_id, '_bme_session_token');
    }
    
    /**
     * Get session duration
     */
    public function get_session_duration($user_id) {
        global $wpdb;
        
        $session = $wpdb->get_row($wpdb->prepare(
            "SELECT login_time, last_activity FROM {$this->session_table} 
             WHERE user_id = %d AND is_active = 1 
             ORDER BY login_time DESC LIMIT 1",
            $user_id
        ));
        
        if ($session) {
            return strtotime($session->last_activity) - strtotime($session->login_time);
        }
        
        return 0;
    }
    
    /**
     * Get active session count
     */
    public function get_active_session_count() {
        global $wpdb;
        
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->session_table} WHERE is_active = 1");
    }
    
    /**
     * Cleanup old sessions
     */
    public function cleanup_old_sessions($days = 30) {
        global $wpdb;
        
        $cutoff_date = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->session_table} WHERE last_activity < %s AND is_active = 0",
            $cutoff_date
        ));
    }
    
    /**
     * Get user IP address
     */
    private function get_user_ip() {
        $ip_keys = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'];
        
        foreach ($ip_keys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = $_SERVER[$key];
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        
        return '127.0.0.1';
    }
}