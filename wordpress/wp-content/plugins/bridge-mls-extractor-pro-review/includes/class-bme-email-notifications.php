<?php

if (!defined('ABSPATH')) {
    exit;
}

class BME_Email_Notifications {
    
    private $db_manager;
    private $cache_manager;
    private $saved_searches;
    
    public function __construct($db_manager, $cache_manager, $saved_searches) {
        $this->db_manager = $db_manager;
        $this->cache_manager = $cache_manager;
        $this->saved_searches = $saved_searches;
        
        $this->init_hooks();
    }
    
    private function init_hooks() {
        // DEPRECATED v4.0.17: Email notifications for saved searches have been moved to MLD plugin
        // The MLD plugin now handles all saved search notifications through its own cron system
        // Keeping these disabled to prevent duplicate notifications

        // Cron hooks for email notifications - DISABLED
        // add_action('bme_send_search_alerts', [$this, 'process_search_alerts']);
        // add_action('bme_send_price_alerts', [$this, 'process_price_alerts']);
        // add_action('bme_send_status_alerts', [$this, 'process_status_alerts']);

        // Admin AJAX handlers - DISABLED
        // add_action('wp_ajax_bme_test_email_notification', [$this, 'ajax_test_email']);
        // add_action('wp_ajax_bme_update_notification_settings', [$this, 'ajax_update_settings']);
        // add_action('wp_ajax_bme_subscribe_property_alerts', [$this, 'ajax_subscribe_property']);
        // add_action('wp_ajax_bme_unsubscribe_property_alerts', [$this, 'ajax_unsubscribe_property']);

        // Cron scheduling - DISABLED (moved to MLD plugin)
        // if (!wp_next_scheduled('bme_send_search_alerts')) {
        //     wp_schedule_event(time(), 'hourly', 'bme_send_search_alerts');
        // }
        //
        // if (!wp_next_scheduled('bme_send_price_alerts')) {
        //     wp_schedule_event(time(), 'daily', 'bme_send_price_alerts');
        // }
        //
        // if (!wp_next_scheduled('bme_send_status_alerts')) {
        //     wp_schedule_event(time(), 'every_15_minutes', 'bme_send_status_alerts');
        // }

        // Keep email template hooks (still used for other BME notifications)
        add_action('wp_mail', [$this, 'customize_email_headers']);

        // DEPRECATED v4.0.17: Shortcode moved to MLD plugin
        // Use MLD settings instead for notification preferences
        // add_shortcode('bme_notification_settings', [$this, 'render_notification_settings']);
    }
    
    public function process_search_alerts() {
        try {
            $searches_with_alerts = $this->saved_searches->get_searches_with_alerts_enabled();
            
            foreach ($searches_with_alerts as $search) {
                $this->process_single_search_alert($search);
            }
            
            error_log('BME Notifications: Processed ' . count($searches_with_alerts) . ' search alerts');
            
        } catch (Exception $e) {
            error_log('BME Notifications Error: ' . $e->getMessage());
        }
    }
    
    private function process_single_search_alert($search) {
        $user = get_user_by('ID', $search->user_id);
        if (!$user) {
            return;
        }
        
        // Check if we've sent alerts recently for this search
        $last_sent_key = "bme_alert_last_sent_{$search->id}";
        $last_sent = get_transient($last_sent_key);
        
        if ($last_sent && (time() - $last_sent) < 3600) { // 1 hour cooldown
            return;
        }
        
        $criteria = json_decode($search->criteria, true);
        if (!$criteria) {
            return;
        }
        
        // Perform the search to get new results
        $advanced_search = bme_pro()->get('advanced_search');
        $results = $advanced_search->perform_advanced_search($criteria, 1, 10);
        
        if (empty($results['properties'])) {
            return;
        }
        
        // Get the last run results to compare
        $last_results_key = "bme_search_results_{$search->id}";
        $last_results = get_option($last_results_key, []);
        
        // Find new properties
        $new_properties = $this->find_new_properties($results['properties'], $last_results);
        
        if (!empty($new_properties)) {
            $this->send_search_alert_email($user, $search, $new_properties);
            
            // Update last sent time and results
            set_transient($last_sent_key, time(), DAY_IN_SECONDS);
            update_option($last_results_key, array_column($results['properties'], 'id'));
        }
    }
    
    private function find_new_properties($current_properties, $last_property_ids) {
        $new_properties = [];
        $current_ids = array_column($current_properties, 'id');
        
        foreach ($current_properties as $property) {
            if (!in_array($property->id, $last_property_ids)) {
                $new_properties[] = $property;
            }
        }
        
        return $new_properties;
    }
    
    public function process_price_alerts() {
        try {
            $price_subscriptions = $this->get_price_alert_subscriptions();
            
            foreach ($price_subscriptions as $subscription) {
                $this->check_property_price_changes($subscription);
            }
            
            error_log('BME Notifications: Processed ' . count($price_subscriptions) . ' price alert subscriptions');
            
        } catch (Exception $e) {
            error_log('BME Notifications Price Alerts Error: ' . $e->getMessage());
        }
    }
    
    public function process_status_alerts() {
        try {
            $status_subscriptions = $this->get_status_alert_subscriptions();
            
            foreach ($status_subscriptions as $subscription) {
                $this->check_property_status_changes($subscription);
            }
            
            error_log('BME Notifications: Processed ' . count($status_subscriptions) . ' status alert subscriptions');
            
        } catch (Exception $e) {
            error_log('BME Notifications Status Alerts Error: ' . $e->getMessage());
        }
    }
    
    private function get_price_alert_subscriptions() {
        global $wpdb;
        
        $table_name = $this->db_manager->get_table('property_subscriptions');
        
        // Create subscriptions table if it doesn't exist
        $this->create_subscriptions_table_if_needed();
        
        return $wpdb->get_results(
            "SELECT * FROM {$table_name} 
             WHERE alert_type = 'price_change' 
             AND is_active = 1 
             AND (last_checked IS NULL OR last_checked < DATE_SUB(NOW(), INTERVAL 1 DAY))"
        );
    }
    
    private function get_status_alert_subscriptions() {
        global $wpdb;
        
        $table_name = $this->db_manager->get_table('property_subscriptions');
        
        return $wpdb->get_results(
            "SELECT * FROM {$table_name} 
             WHERE alert_type = 'status_change' 
             AND is_active = 1 
             AND (last_checked IS NULL OR last_checked < DATE_SUB(NOW(), INTERVAL 15 MINUTE))"
        );
    }
    
    private function create_subscriptions_table_if_needed() {
        global $wpdb;
        
        $table_name = $this->db_manager->get_table('property_subscriptions');
        
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") !== $table_name) {
            $sql = "CREATE TABLE {$table_name} (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT(20) UNSIGNED NOT NULL,
                property_id BIGINT(20) UNSIGNED NOT NULL,
                mls_id VARCHAR(50) NOT NULL,
                alert_type ENUM('price_change', 'status_change', 'new_photos') NOT NULL,
                threshold_value DECIMAL(15,2) NULL,
                threshold_type ENUM('decrease', 'increase', 'any') DEFAULT 'any',
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                last_checked TIMESTAMP NULL,
                last_notified TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_user_id (user_id),
                KEY idx_property_id (property_id),
                KEY idx_mls_id (mls_id),
                KEY idx_alert_type (alert_type),
                KEY idx_active_checked (is_active, last_checked),
                UNIQUE KEY uk_user_property_alert (user_id, property_id, alert_type)
            ) " . $wpdb->get_charset_collate() . ";";
            
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);
        }
    }
    
    private function send_search_alert_email($user, $search, $new_properties) {
        $subject = sprintf('New Properties Found: %s', $search->name);
        
        $email_content = $this->get_search_alert_email_template($user, $search, $new_properties);
        
        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>'
        ];
        
        $sent = wp_mail($user->user_email, $subject, $email_content, $headers);
        
        if ($sent) {
            // Log successful email
            $this->log_email_sent($user->ID, 'search_alert', $search->id, count($new_properties));
        }
        
        return $sent;
    }
    
    private function get_search_alert_email_template($user, $search, $new_properties) {
        ob_start();
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title><?php echo esc_html($search->name); ?> - New Properties</title>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; margin: 0; padding: 20px; background: #f4f4f4; }
                .container { max-width: 600px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; }
                .header { text-align: center; border-bottom: 2px solid #0073aa; padding-bottom: 20px; margin-bottom: 30px; }
                .logo { font-size: 24px; font-weight: bold; color: #0073aa; }
                .property { border: 1px solid #ddd; border-radius: 8px; margin-bottom: 20px; overflow: hidden; }
                .property-image { width: 100%; height: 200px; object-fit: cover; }
                .property-details { padding: 20px; }
                .property-price { font-size: 20px; font-weight: bold; color: #0073aa; margin-bottom: 10px; }
                .property-address { font-size: 16px; margin-bottom: 10px; }
                .property-specs { color: #666; margin-bottom: 15px; }
                .view-property { background: #0073aa; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px; display: inline-block; }
                .footer { text-align: center; margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd; color: #666; }
                .unsubscribe { font-size: 12px; color: #999; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <div class="logo"><?php echo get_bloginfo('name'); ?></div>
                    <h2>New Properties Found: <?php echo esc_html($search->name); ?></h2>
                </div>
                
                <p>Hi <?php echo esc_html($user->display_name); ?>,</p>
                
                <p>We found <?php echo count($new_properties); ?> new <?php echo count($new_properties) == 1 ? 'property' : 'properties'; ?> matching your saved search "<strong><?php echo esc_html($search->name); ?></strong>":</p>
                
                <?php foreach ($new_properties as $property): ?>
                    <div class="property">
                        <?php if (!empty($property->images)): ?>
                            <img src="<?php echo esc_url($property->images[0]); ?>" alt="<?php echo esc_attr($property->address); ?>" class="property-image">
                        <?php endif; ?>
                        
                        <div class="property-details">
                            <div class="property-price">$<?php echo number_format($property->list_price); ?></div>
                            <div class="property-address"><?php echo esc_html($property->address . ', ' . $property->city . ', ' . $property->state); ?></div>
                            <div class="property-specs">
                                <?php echo $property->bedrooms; ?> bed • 
                                <?php echo $property->bathrooms; ?> bath • 
                                <?php echo number_format($property->sqft_total); ?> sqft
                            </div>
                            <a href="<?php echo home_url('/property/' . $property->id); ?>" class="view-property">View Property</a>
                        </div>
                    </div>
                <?php endforeach; ?>
                
                <div class="footer">
                    <p><a href="<?php echo home_url('/saved-searches/'); ?>">Manage your saved searches</a></p>
                    <p class="unsubscribe">
                        <a href="<?php echo home_url('/unsubscribe/?search_id=' . $search->id . '&user_id=' . $user->ID); ?>">
                            Unsubscribe from this search alert
                        </a>
                    </p>
                </div>
            </div>
        </body>
        </html>
        <?php
        return ob_get_clean();
    }
    
    private function log_email_sent($user_id, $email_type, $reference_id = null, $property_count = 0) {
        global $wpdb;
        
        $logs_table = $this->db_manager->get_table('email_logs');
        
        // Create email logs table if it doesn't exist
        $this->create_email_logs_table_if_needed();
        
        $wpdb->insert($logs_table, [
            'user_id' => $user_id,
            'email_type' => $email_type,
            'reference_id' => $reference_id,
            'property_count' => $property_count,
            'sent_at' => current_time('mysql'),
            'status' => 'sent'
        ]);
    }
    
    private function create_email_logs_table_if_needed() {
        global $wpdb;
        
        $table_name = $this->db_manager->get_table('email_logs');
        
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") !== $table_name) {
            $sql = "CREATE TABLE {$table_name} (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT(20) UNSIGNED NOT NULL,
                email_type VARCHAR(50) NOT NULL,
                reference_id BIGINT(20) UNSIGNED NULL,
                property_count INT UNSIGNED DEFAULT 0,
                sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                status ENUM('sent', 'failed', 'bounced') DEFAULT 'sent',
                error_message TEXT NULL,
                PRIMARY KEY (id),
                KEY idx_user_id (user_id),
                KEY idx_email_type (email_type),
                KEY idx_sent_at (sent_at),
                KEY idx_status (status)
            ) " . $wpdb->get_charset_collate() . ";";
            
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);
        }
    }
    
    public function subscribe_to_property_alerts($user_id, $property_id, $mls_id, $alert_type, $threshold_value = null, $threshold_type = 'any') {
        global $wpdb;
        
        $this->create_subscriptions_table_if_needed();
        $table_name = $this->db_manager->get_table('property_subscriptions');
        
        $result = $wpdb->replace($table_name, [
            'user_id' => $user_id,
            'property_id' => $property_id,
            'mls_id' => $mls_id,
            'alert_type' => $alert_type,
            'threshold_value' => $threshold_value,
            'threshold_type' => $threshold_type,
            'is_active' => 1
        ]);
        
        return $result !== false;
    }
    
    public function unsubscribe_from_property_alerts($user_id, $property_id, $alert_type = null) {
        global $wpdb;
        
        $table_name = $this->db_manager->get_table('property_subscriptions');
        
        $where = [
            'user_id' => $user_id,
            'property_id' => $property_id
        ];
        
        if ($alert_type) {
            $where['alert_type'] = $alert_type;
        }
        
        return $wpdb->update($table_name, ['is_active' => 0], $where) !== false;
    }
    
    public function ajax_test_email() {
        if (!wp_verify_nonce($_POST['nonce'], 'bme_email_nonce') || !current_user_can('manage_options')) {
            wp_die('Security check failed');
        }
        
        $user = wp_get_current_user();
        $test_subject = 'BME Email Notification Test';
        $test_message = $this->get_test_email_template($user);
        
        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>'
        ];
        
        $sent = wp_mail($user->user_email, $test_subject, $test_message, $headers);
        
        if ($sent) {
            wp_send_json_success('Test email sent successfully!');
        } else {
            wp_send_json_error('Failed to send test email.');
        }
    }
    
    private function get_test_email_template($user) {
        ob_start();
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="utf-8">
            <title>Email Notification Test</title>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; margin: 0; padding: 20px; background: #f4f4f4; }
                .container { max-width: 600px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; }
                .header { text-align: center; color: #0073aa; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h2><?php echo get_bloginfo('name'); ?> - Email Test</h2>
                </div>
                
                <p>Hi <?php echo esc_html($user->display_name); ?>,</p>
                
                <p>This is a test email to verify that the Bridge MLS Extractor Pro email notification system is working correctly.</p>
                
                <p>If you received this email, your notification system is configured properly.</p>
                
                <p>Best regards,<br>
                The <?php echo get_bloginfo('name'); ?> Team</p>
            </div>
        </body>
        </html>
        <?php
        return ob_get_clean();
    }
    
    public function ajax_subscribe_property() {
        if (!wp_verify_nonce($_POST['nonce'], 'bme_email_nonce') || !is_user_logged_in()) {
            wp_die('Security check failed');
        }
        
        $user_id = get_current_user_id();
        $property_id = intval($_POST['property_id']);
        $mls_id = sanitize_text_field($_POST['mls_id']);
        $alert_type = sanitize_text_field($_POST['alert_type']);
        $threshold_value = !empty($_POST['threshold_value']) ? floatval($_POST['threshold_value']) : null;
        $threshold_type = sanitize_text_field($_POST['threshold_type'] ?? 'any');
        
        $success = $this->subscribe_to_property_alerts($user_id, $property_id, $mls_id, $alert_type, $threshold_value, $threshold_type);
        
        if ($success) {
            wp_send_json_success('Successfully subscribed to property alerts!');
        } else {
            wp_send_json_error('Failed to subscribe to property alerts.');
        }
    }
    
    public function ajax_unsubscribe_property() {
        if (!wp_verify_nonce($_POST['nonce'], 'bme_email_nonce') || !is_user_logged_in()) {
            wp_die('Security check failed');
        }
        
        $user_id = get_current_user_id();
        $property_id = intval($_POST['property_id']);
        $alert_type = sanitize_text_field($_POST['alert_type'] ?? '');
        
        $success = $this->unsubscribe_from_property_alerts($user_id, $property_id, $alert_type ?: null);
        
        if ($success) {
            wp_send_json_success('Successfully unsubscribed from property alerts!');
        } else {
            wp_send_json_error('Failed to unsubscribe from property alerts.');
        }
    }
    
    public function customize_email_headers($args) {
        // Add custom headers for BME emails
        if (strpos($args['subject'], 'BME') !== false || strpos($args['message'], 'Bridge MLS') !== false) {
            $args['headers'][] = 'X-BME-Notification: true';
            $args['headers'][] = 'List-Unsubscribe: <' . home_url('/unsubscribe/') . '>';
        }
        
        return $args;
    }
    
    public function render_notification_settings($atts) {
        if (!is_user_logged_in()) {
            return '<p>Please log in to manage your notification settings.</p>';
        }
        
        $user_id = get_current_user_id();
        
        ob_start();
        ?>
        <div class="bme-notification-settings">
            <h3>Email Notification Settings</h3>
            
            <form id="bme-notification-form" class="bme-form">
                <div class="bme-form-section">
                    <h4>Search Alerts</h4>
                    <p>Get notified when new properties match your saved searches.</p>
                    
                    <label class="bme-checkbox-label">
                        <input type="checkbox" name="search_alerts_enabled" value="1" checked>
                        Enable search alerts
                    </label>
                    
                    <div class="bme-form-group">
                        <label>Alert Frequency:</label>
                        <select name="search_alert_frequency">
                            <option value="immediately">Immediately</option>
                            <option value="daily" selected>Daily Digest</option>
                            <option value="weekly">Weekly Summary</option>
                        </select>
                    </div>
                </div>
                
                <div class="bme-form-section">
                    <h4>Property Alerts</h4>
                    <p>Get notified about price changes and status updates on specific properties.</p>
                    
                    <label class="bme-checkbox-label">
                        <input type="checkbox" name="price_alerts_enabled" value="1" checked>
                        Price change alerts
                    </label>
                    
                    <label class="bme-checkbox-label">
                        <input type="checkbox" name="status_alerts_enabled" value="1" checked>
                        Status change alerts (sold, pending, etc.)
                    </label>
                </div>
                
                <div class="bme-form-actions">
                    <button type="submit" class="bme-btn bme-btn-primary">Save Settings</button>
                    <button type="button" id="bme-test-email" class="bme-btn bme-btn-secondary">Send Test Email</button>
                </div>
            </form>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            $('#bme-test-email').on('click', function() {
                $.post('<?php echo admin_url('admin-ajax.php'); ?>', {
                    action: 'bme_test_email_notification',
                    nonce: '<?php echo wp_create_nonce('bme_email_nonce'); ?>'
                }, function(response) {
                    if (response.success) {
                        alert('Test email sent! Check your inbox.');
                    } else {
                        alert('Failed to send test email: ' + response.data);
                    }
                });
            });
        });
        </script>
        <?php
        return ob_get_clean();
    }
}