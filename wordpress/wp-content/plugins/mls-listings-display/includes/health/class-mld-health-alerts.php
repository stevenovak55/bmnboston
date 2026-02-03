<?php
/**
 * MLD Health Alerts - Email notification system
 *
 * Sends email alerts when health degrades:
 * - Configurable recipients
 * - Throttling to prevent spam
 * - Severity-based filtering
 *
 * @package MLS_Listings_Display
 * @since 6.58.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Health_Alerts {

    /**
     * Singleton instance
     */
    private static $instance = null;

    /**
     * Option keys
     */
    const OPTION_ENABLED = 'mld_health_alerts_enabled';
    const OPTION_RECIPIENTS = 'mld_health_alert_recipients';
    const OPTION_THROTTLE_MINUTES = 'mld_health_alert_throttle';
    const OPTION_MIN_SEVERITY = 'mld_health_alert_min_severity';
    const OPTION_LAST_ALERT = 'mld_health_last_alert_time';
    const OPTION_LAST_STATUS = 'mld_health_last_status';

    /**
     * Severity levels
     */
    const SEVERITY_WARNING = 'warning';
    const SEVERITY_CRITICAL = 'critical';

    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        // Register cron hook
        add_action('mld_health_check_cron', array($this, 'cron_health_check'));

        // Schedule cron if not scheduled
        if (!wp_next_scheduled('mld_health_check_cron')) {
            // Schedule for every 15 minutes
            wp_schedule_event(time(), 'mld_fifteen_minutes', 'mld_health_check_cron');
        }
    }

    /**
     * Check if alerts are enabled
     *
     * @return bool
     */
    public function is_enabled() {
        return (bool) get_option(self::OPTION_ENABLED, false);
    }

    /**
     * Get alert recipients
     *
     * @return array Email addresses
     */
    public function get_recipients() {
        $recipients = get_option(self::OPTION_RECIPIENTS, '');

        if (empty($recipients)) {
            // Default to admin email
            return array(get_option('admin_email'));
        }

        // Parse comma-separated list
        return array_map('trim', explode(',', $recipients));
    }

    /**
     * Get throttle duration in minutes
     *
     * @return int Minutes
     */
    public function get_throttle_minutes() {
        return (int) get_option(self::OPTION_THROTTLE_MINUTES, 60);
    }

    /**
     * Get minimum severity for alerts
     *
     * @return string 'warning' or 'critical'
     */
    public function get_min_severity() {
        return get_option(self::OPTION_MIN_SEVERITY, self::SEVERITY_WARNING);
    }

    /**
     * Cron job: Run health check and send alerts if needed
     */
    public function cron_health_check() {
        if (!$this->is_enabled()) {
            return;
        }

        $monitor = MLD_Health_Monitor::get_instance();
        $results = $monitor->run_full_check('cron');

        $this->process_results($results);
    }

    /**
     * Process health check results and send alerts if needed
     *
     * @param array $results Health check results
     */
    public function process_results($results) {
        $current_status = $results['status'];
        $previous_status = get_option(self::OPTION_LAST_STATUS, MLD_Health_Monitor::STATUS_HEALTHY);

        // Update last known status
        update_option(self::OPTION_LAST_STATUS, $current_status);

        // Check if we should send alert
        if (!$this->should_send_alert($current_status, $previous_status, $results)) {
            return;
        }

        // Send alert
        $this->send_alert($results, $previous_status);
    }

    /**
     * Check if alert should be sent
     *
     * @param string $current_status Current health status
     * @param string $previous_status Previous health status
     * @param array $results Full results
     * @return bool
     */
    private function should_send_alert($current_status, $previous_status, $results) {
        // Don't alert if healthy
        if ($current_status === MLD_Health_Monitor::STATUS_HEALTHY) {
            // But do alert on recovery
            if ($previous_status !== MLD_Health_Monitor::STATUS_HEALTHY) {
                return true; // Recovery alert
            }
            return false;
        }

        // Check minimum severity
        $min_severity = $this->get_min_severity();
        if ($min_severity === self::SEVERITY_CRITICAL &&
            $current_status === MLD_Health_Monitor::STATUS_DEGRADED) {
            return false; // Only critical alerts enabled
        }

        // Check throttle
        if ($this->is_throttled()) {
            return false;
        }

        return true;
    }

    /**
     * Check if we're in throttle period
     *
     * @return bool
     */
    private function is_throttled() {
        $last_alert = get_option(self::OPTION_LAST_ALERT, 0);
        $throttle_minutes = $this->get_throttle_minutes();

        if (empty($last_alert)) {
            return false;
        }

        $throttle_seconds = $throttle_minutes * 60;
        return (time() - $last_alert) < $throttle_seconds;
    }

    /**
     * Send health alert email
     *
     * @param array $results Health check results
     * @param string $previous_status Previous status (for recovery alerts)
     * @return bool Success
     */
    public function send_alert($results, $previous_status = null) {
        $recipients = $this->get_recipients();

        if (empty($recipients)) {
            return false;
        }

        // Determine alert type
        $is_recovery = ($results['status'] === MLD_Health_Monitor::STATUS_HEALTHY &&
                        $previous_status !== MLD_Health_Monitor::STATUS_HEALTHY);

        // Build subject
        $site_name = get_bloginfo('name');
        if ($is_recovery) {
            $subject = "[{$site_name}] Health Recovered - All Systems Normal";
        } else {
            $status_upper = strtoupper($results['status']);
            $subject = "[{$site_name}] Health Alert - Status: {$status_upper}";
        }

        // Build email body
        $body = $this->build_email_body($results, $is_recovery, $previous_status);

        // Headers
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: BMN Boston System <noreply@bmnboston.com>',
        );

        // Send email
        $sent = wp_mail($recipients, $subject, $body, $headers);

        if ($sent) {
            // Update last alert time
            update_option(self::OPTION_LAST_ALERT, time());

            // Log the alert
            $this->log_alert($results['status'], count($results['issues']));
        }

        return $sent;
    }

    /**
     * Build HTML email body
     *
     * @param array $results Health check results
     * @param bool $is_recovery Is this a recovery alert
     * @param string $previous_status Previous status
     * @return string HTML email body
     */
    private function build_email_body($results, $is_recovery, $previous_status) {
        $status_color = MLD_Health_Monitor::get_status_color($results['status']);
        $status_emoji = MLD_Health_Monitor::get_status_emoji($results['status']);
        $status_upper = strtoupper($results['status']);

        ob_start();
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <style>
                body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { text-align: center; padding: 20px; margin-bottom: 20px; }
                .status-badge { display: inline-block; padding: 10px 20px; border-radius: 5px; color: #fff; font-size: 18px; font-weight: bold; }
                .component-table { width: 100%; border-collapse: collapse; margin: 20px 0; }
                .component-table th, .component-table td { padding: 10px 15px; text-align: left; border-bottom: 1px solid #eee; }
                .component-table th { background: #f5f5f5; }
                .status-healthy { color: #46b450; }
                .status-degraded { color: #ffb900; }
                .status-unhealthy { color: #dc3232; }
                .issues-list { background: #fff3f3; padding: 15px; border-radius: 5px; margin: 20px 0; }
                .issues-list h3 { margin-top: 0; color: #dc3232; }
                .issues-list ul { margin: 0; padding-left: 20px; }
                .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; border-top: 1px solid #eee; margin-top: 30px; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h1>BMN Boston System Health</h1>
                    <div class="status-badge" style="background-color: <?php echo esc_attr($status_color); ?>;">
                        <?php echo esc_html($status_emoji); ?> <?php echo esc_html($status_upper); ?>
                    </div>
                    <?php if ($is_recovery): ?>
                    <p style="color: #46b450; font-weight: bold;">System has recovered from <?php echo esc_html(strtoupper($previous_status)); ?> state.</p>
                    <?php endif; ?>
                </div>

                <p><strong>Timestamp:</strong> <?php echo esc_html($results['timestamp']); ?></p>
                <p><strong>Response Time:</strong> <?php echo esc_html($results['response_time_ms']); ?>ms</p>

                <h2>Component Status</h2>
                <table class="component-table">
                    <thead>
                        <tr>
                            <th>Component</th>
                            <th>Version</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($results['components'] as $name => $component): ?>
                        <tr>
                            <td><?php echo esc_html($component['name']); ?></td>
                            <td><?php echo esc_html($component['version']); ?></td>
                            <td class="status-<?php echo esc_attr($component['status']); ?>">
                                <?php echo esc_html(MLD_Health_Monitor::get_status_emoji($component['status'])); ?>
                                <?php echo esc_html(strtoupper($component['status'])); ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <?php if (!empty($results['issues'])): ?>
                <div class="issues-list">
                    <h3>Issues Detected (<?php echo count($results['issues']); ?>)</h3>
                    <ul>
                        <?php foreach ($results['issues'] as $issue): ?>
                        <li>
                            <strong>[<?php echo esc_html(strtoupper($issue['severity'])); ?>]</strong>
                            <?php if (isset($issue['component'])): ?>
                            [<?php echo esc_html($issue['component']); ?>]
                            <?php endif; ?>
                            <?php echo esc_html($issue['message']); ?>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>

                <div class="footer">
                    <p>This is an automated message from the BMN Boston health monitoring system.</p>
                    <p>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=mld-health-dashboard')); ?>">View Health Dashboard</a>
                    </p>
                </div>
            </div>
        </body>
        </html>
        <?php
        return ob_get_clean();
    }

    /**
     * Send a test alert
     *
     * @param string|null $email Override recipient email
     * @return bool Success
     */
    public function send_test_alert($email = null) {
        // Get current health status
        $monitor = MLD_Health_Monitor::get_instance();
        $results = $monitor->run_full_check('test');

        // Override recipients if email provided
        if ($email) {
            $original_recipients = get_option(self::OPTION_RECIPIENTS);
            update_option(self::OPTION_RECIPIENTS, $email);
        }

        // Add test indicator to results
        $results['is_test'] = true;

        // Send the alert
        $sent = $this->send_alert($results);

        // Restore original recipients
        if ($email) {
            update_option(self::OPTION_RECIPIENTS, $original_recipients);
        }

        return $sent;
    }

    /**
     * Log alert to database
     *
     * @param string $status Health status
     * @param int $issues_count Number of issues
     */
    private function log_alert($status, $issues_count) {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_health_alerts';

        // Check if table exists
        if (!$wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table))) {
            return;
        }

        $severity = ($status === MLD_Health_Monitor::STATUS_UNHEALTHY) ? 'critical' : 'warning';

        $wpdb->insert($table, array(
            'alert_time' => current_time('mysql'),
            'severity' => $severity,
            'component' => 'system',
            'message' => "Health status: {$status}, {$issues_count} issues detected",
        ), array('%s', '%s', '%s', '%s'));
    }

    /**
     * Save alert settings
     *
     * @param array $settings Settings array
     */
    public function save_settings($settings) {
        if (isset($settings['enabled'])) {
            update_option(self::OPTION_ENABLED, (bool) $settings['enabled']);
        }

        if (isset($settings['recipients'])) {
            update_option(self::OPTION_RECIPIENTS, sanitize_text_field($settings['recipients']));
        }

        if (isset($settings['throttle_minutes'])) {
            update_option(self::OPTION_THROTTLE_MINUTES, (int) $settings['throttle_minutes']);
        }

        if (isset($settings['min_severity'])) {
            $valid_severities = array(self::SEVERITY_WARNING, self::SEVERITY_CRITICAL);
            if (in_array($settings['min_severity'], $valid_severities)) {
                update_option(self::OPTION_MIN_SEVERITY, $settings['min_severity']);
            }
        }
    }

    /**
     * Get current alert settings
     *
     * @return array Current settings
     */
    public function get_settings() {
        return array(
            'enabled' => $this->is_enabled(),
            'recipients' => get_option(self::OPTION_RECIPIENTS, get_option('admin_email')),
            'throttle_minutes' => $this->get_throttle_minutes(),
            'min_severity' => $this->get_min_severity(),
            'last_alert' => get_option(self::OPTION_LAST_ALERT, null),
            'last_status' => get_option(self::OPTION_LAST_STATUS, MLD_Health_Monitor::STATUS_HEALTHY),
        );
    }
}

// Initialize on plugins_loaded
add_action('plugins_loaded', function() {
    MLD_Health_Alerts::get_instance();
});
