<?php
/**
 * MLD Instant Notifications Admin Dashboard
 *
 * Provides admin interface for monitoring and managing instant notifications
 *
 * @package MLS_Listings_Display
 * @subpackage Instant_Notifications
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Instant_Admin {

    /**
     * Constructor
     */
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));

        // AJAX handlers
        add_action('wp_ajax_mld_get_notification_stats', array($this, 'ajax_get_stats'));
        add_action('wp_ajax_mld_get_recent_activity', array($this, 'ajax_get_recent_activity'));
        add_action('wp_ajax_mld_toggle_notification_system', array($this, 'ajax_toggle_system'));
        add_action('wp_ajax_mld_clear_notification_queue', array($this, 'ajax_clear_queue'));
        add_action('wp_ajax_mld_test_notification', array($this, 'ajax_test_notification'));
        add_action('wp_ajax_mld_get_filtered_activity', array($this, 'ajax_get_filtered_activity'));
        add_action('wp_ajax_mld_get_activity_log', array($this, 'ajax_get_activity_log'));
        add_action('wp_ajax_mld_get_realtime_stats', array($this, 'ajax_get_realtime_stats'));
    }

    /**
     * Add admin menu items
     */
    public function add_admin_menu() {
        // Add to existing MLS Display menu
        add_submenu_page(
            'mls_listings_display',
            'Push Notifications',
            'Push Notifications',
            'manage_options',
            'mld-instant-notifications',
            array($this, 'render_dashboard_page')
        );

        // Add monitoring page
        add_submenu_page(
            'mls_listings_display',
            'Notification Activity',
            'Notification Activity',
            'manage_options',
            'mld-notification-activity',
            array($this, 'render_activity_page')
        );
    }

    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_scripts($hook) {
        if (strpos($hook, 'mld-instant-notifications') === false &&
            strpos($hook, 'mld-notification-activity') === false) {
            return;
        }

        wp_enqueue_style('mld-instant-admin-style',
            MLD_PLUGIN_URL . 'assets/css/instant-admin.css',
            array(),
            MLD_VERSION . '.' . time()  // Force cache refresh
        );

        wp_enqueue_script('mld-instant-admin-script',
            MLD_PLUGIN_URL . 'assets/js/instant-admin.js',
            array('jquery', 'chart-js'),
            MLD_VERSION . '.' . time(),  // Force cache refresh
            true
        );

        // Enqueue Chart.js for analytics
        wp_enqueue_script('chart-js',
            'https://cdn.jsdelivr.net/npm/chart.js',
            array(),
            '3.9.1',
            true
        );

        wp_localize_script('mld-instant-admin-script', 'mldInstantAdmin', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('mld_instant_admin_nonce')
        ));
    }

    /**
     * Render dashboard page
     */
    public function render_dashboard_page() {
        global $wpdb;

        // Get system status
        $system_enabled = get_option('mld_instant_notifications_enabled', true);

        // Get statistics
        $stats = $this->get_dashboard_stats();

        ?>
        <div class="wrap mld-instant-dashboard">
            <h1>
                <span class="dashicons dashicons-bell"></span>
                Push Notifications Dashboard
            </h1>

            <!-- System Status Card -->
            <div class="mld-status-card <?php echo $system_enabled ? 'active' : 'inactive'; ?>">
                <div class="status-header">
                    <h2>System Status</h2>
                    <label class="toggle-switch">
                        <input type="checkbox" id="system-toggle" <?php checked($system_enabled); ?>>
                        <span class="slider"></span>
                    </label>
                </div>
                <div class="status-body">
                    <p class="status-message">
                        <?php if ($system_enabled): ?>
                            <span class="dashicons dashicons-yes-alt"></span>
                            Instant notifications are <strong>active</strong>
                        <?php else: ?>
                            <span class="dashicons dashicons-warning"></span>
                            Instant notifications are <strong>paused</strong>
                        <?php endif; ?>
                    </p>
                </div>
            </div>

            <!-- Statistics Grid -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <span class="dashicons dashicons-search"></span>
                    </div>
                    <div class="stat-content">
                        <h3><?php echo number_format($stats['active_searches']); ?></h3>
                        <p>Active Saved Searches</p>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon">
                        <span class="dashicons dashicons-yes-alt"></span>
                    </div>
                    <div class="stat-content">
                        <h3><?php echo number_format($stats['notifications_today']); ?></h3>
                        <p>Sent Today</p>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon">
                        <span class="dashicons dashicons-dismiss"></span>
                    </div>
                    <div class="stat-content">
                        <h3><?php echo number_format($stats['failed_today']); ?></h3>
                        <p>Failed Today</p>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon">
                        <span class="dashicons dashicons-chart-pie"></span>
                    </div>
                    <div class="stat-content">
                        <h3><?php echo $stats['success_rate']; ?>%</h3>
                        <p>Success Rate</p>
                    </div>
                </div>
            </div>

            <!-- Recent Activity -->
            <div class="activity-section">
                <h2>Recent Push Notifications</h2>
                <div class="activity-table-wrapper">
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>User</th>
                                <th>Type</th>
                                <th>Listing</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody id="activity-tbody">
                            <?php echo $this->get_recent_activity_rows(); ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Daily Activity Chart -->
            <div class="chart-section">
                <h2>Daily Activity (Last 7 Days)</h2>
                <div class="chart-container" style="position: relative; height: 300px; width: 100%;">
                    <canvas id="activity-chart"></canvas>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="actions-section">
                <h2>Quick Actions</h2>
                <div class="action-buttons">
                    <button class="button button-primary" id="test-notification-btn">
                        <span class="dashicons dashicons-megaphone"></span>
                        Send Test Notification
                    </button>
                    <button class="button" id="clear-queue-btn">
                        <span class="dashicons dashicons-trash"></span>
                        Clear Old Failed Logs
                    </button>
                    <button class="button" id="export-stats-btn">
                        <span class="dashicons dashicons-download"></span>
                        Export Statistics
                    </button>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render activity monitoring page
     */
    public function render_activity_page() {
        ?>
        <div class="wrap mld-activity-monitor">
            <h1>
                <span class="dashicons dashicons-visibility"></span>
                Notification Activity Monitor
            </h1>

            <!-- Real-time Stats -->
            <div class="realtime-stats">
                <div class="realtime-card">
                    <h3>Real-Time Activity</h3>
                    <div id="realtime-counter" class="counter-display">
                        <span class="counter-value">0</span>
                        <span class="counter-label">notifications/minute</span>
                    </div>
                </div>
            </div>

            <!-- Filter Controls -->
            <div class="filter-section">
                <div class="filter-row">
                    <label>
                        Date Range:
                        <input type="date" id="filter-date-start" value="<?php echo wp_date('Y-m-d', current_time('timestamp') - (7 * DAY_IN_SECONDS)); ?>">
                        to
                        <input type="date" id="filter-date-end" value="<?php echo wp_date('Y-m-d'); ?>">
                    </label>
                    <label>
                        Status:
                        <select id="filter-status">
                            <option value="">All</option>
                            <option value="sent">Sent</option>
                            <option value="pending">Pending</option>
                            <option value="failed">Failed</option>
                            <option value="throttled">Throttled</option>
                        </select>
                    </label>
                    <label>
                        Type:
                        <select id="filter-type">
                            <option value="">All</option>
                            <option value="new_listing">New Listing</option>
                            <option value="price_change">Price Change</option>
                            <option value="status_change">Status Change</option>
                            <option value="appointment_reminder">Appointment Reminder</option>
                            <option value="appointment_confirmation">Appointment Confirmation</option>
                            <option value="appointment_cancellation">Appointment Cancellation</option>
                            <option value="test">Test</option>
                        </select>
                    </label>
                    <button class="button" id="apply-filters-btn">Apply Filters</button>
                </div>
            </div>

            <!-- Activity Log -->
            <div class="activity-log-section">
                <h2>Activity Log</h2>
                <div id="activity-log-container">
                    <!-- Populated via AJAX -->
                </div>
            </div>

            <!-- Performance Metrics -->
            <div class="metrics-section">
                <h2>Performance Metrics</h2>
                <div class="metrics-grid">
                    <div class="metric-card">
                        <canvas id="delivery-rate-chart"></canvas>
                        <h4>Delivery Rate</h4>
                    </div>
                    <div class="metric-card">
                        <canvas id="response-time-chart"></canvas>
                        <h4>Response Times</h4>
                    </div>
                    <div class="metric-card">
                        <canvas id="channel-distribution-chart"></canvas>
                        <h4>Channel Distribution</h4>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Get dashboard statistics
     *
     * @since 6.67.0 - Updated to query wp_mld_push_notification_log for real notification data
     */
    private function get_dashboard_stats() {
        global $wpdb;

        $today = current_time('Y-m-d');
        $push_log_table = $wpdb->prefix . 'mld_push_notification_log';

        // All active searches (not just instant frequency)
        $active_searches = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}mld_saved_searches
             WHERE is_active = 1"
        );

        // Notifications sent today (from push notification log)
        $notifications_today = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$push_log_table}
             WHERE status = 'sent' AND DATE(created_at) = %s",
            $today
        ));

        // Failed notifications today
        $failed_today = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$push_log_table}
             WHERE status = 'failed' AND DATE(created_at) = %s",
            $today
        ));

        // Active users with saved searches
        $active_users = $wpdb->get_var(
            "SELECT COUNT(DISTINCT user_id) FROM {$wpdb->prefix}mld_saved_searches
             WHERE is_active = 1"
        );

        // Calculate success rate
        $total_today = ($notifications_today ?: 0) + ($failed_today ?: 0);
        $success_rate = $total_today > 0 ? round(($notifications_today / $total_today) * 100, 1) : 100;

        return [
            'active_searches' => $active_searches ?: 0,
            'notifications_today' => $notifications_today ?: 0,
            'failed_today' => $failed_today ?: 0,
            'active_users' => $active_users ?: 0,
            'success_rate' => $success_rate
        ];
    }

    /**
     * Get recent activity rows for dashboard
     *
     * @since 6.67.0 - Updated to query wp_mld_push_notification_log for real notification data
     */
    private function get_recent_activity_rows() {
        global $wpdb;

        $push_log_table = $wpdb->prefix . 'mld_push_notification_log';

        $activities = $wpdb->get_results(
            "SELECT
                p.id,
                p.user_id,
                p.notification_type,
                p.status,
                p.created_at,
                p.apns_reason,
                p.payload,
                u.display_name,
                JSON_UNQUOTE(JSON_EXTRACT(p.payload, '$.listing_id')) as listing_id
             FROM {$push_log_table} p
             LEFT JOIN {$wpdb->users} u ON p.user_id = u.ID
             ORDER BY p.created_at DESC
             LIMIT 10"
        );

        if (empty($activities)) {
            return '<tr><td colspan="6">No recent activity</td></tr>';
        }

        $html = '';
        foreach ($activities as $activity) {
            // Database stores in WordPress timezone (EST), convert to Unix timestamp for comparison
            $created_timestamp = (new DateTime($activity->created_at, wp_timezone()))->getTimestamp();
            $time_ago = human_time_diff($created_timestamp, time());
            $status_class = $this->get_status_class($activity->status);

            // Format notification type for display
            $type_display = ucwords(str_replace('_', ' ', $activity->notification_type ?: 'unknown'));

            // Format listing ID display
            $listing_display = $activity->listing_id ?: '-';
            if (strlen($listing_display) > 12) {
                $listing_display = substr($listing_display, 0, 10) . '...';
            }

            // For failed notifications, show the APNs reason
            $status_display = ucfirst($activity->status);
            if ($activity->status === 'failed' && !empty($activity->apns_reason)) {
                $status_display .= ' (' . esc_html($activity->apns_reason) . ')';
            }

            $html .= sprintf(
                '<tr>
                    <td>%s ago</td>
                    <td>%s</td>
                    <td>%s</td>
                    <td>%s</td>
                    <td><span class="status-badge %s">%s</span></td>
                </tr>',
                esc_html($time_ago),
                esc_html($activity->display_name ?: 'Unknown'),
                esc_html($type_display),
                esc_html($listing_display),
                esc_attr($status_class),
                $status_display
            );
        }

        return $html;
    }

    /**
     * Get status class for styling
     */
    private function get_status_class($status) {
        switch ($status) {
            case 'sent':
                return 'status-success';
            case 'pending':
                return 'status-warning';
            case 'failed':
                return 'status-error';
            case 'throttled':
                return 'status-info';
            default:
                return '';
        }
    }

    /**
     * AJAX: Get statistics
     */
    public function ajax_get_stats() {
        check_ajax_referer('mld_instant_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $stats = $this->get_dashboard_stats();

        // Add daily chart data
        $stats['chart_data'] = $this->get_chart_data();

        wp_send_json_success($stats);
    }

    /**
     * AJAX: Get recent activity
     */
    public function ajax_get_recent_activity() {
        check_ajax_referer('mld_instant_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $html = $this->get_recent_activity_rows();

        wp_send_json_success(['html' => $html]);
    }

    /**
     * AJAX: Toggle notification system
     */
    public function ajax_toggle_system() {
        check_ajax_referer('mld_instant_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $enabled = $_POST['enabled'] === 'true';
        update_option('mld_instant_notifications_enabled', $enabled);

        wp_send_json_success([
            'enabled' => $enabled,
            'message' => $enabled ? 'System activated' : 'System paused'
        ]);
    }

    /**
     * AJAX: Clear old failed notification logs
     *
     * @since 6.67.0 - Repurposed to clear failed notification logs older than 7 days
     */
    public function ajax_clear_queue() {
        check_ajax_referer('mld_instant_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        global $wpdb;

        $push_log_table = $wpdb->prefix . 'mld_push_notification_log';

        // Clear failed notifications older than 7 days
        $seven_days_ago = wp_date('Y-m-d H:i:s', current_time('timestamp') - (7 * DAY_IN_SECONDS));
        $cleared = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$push_log_table}
             WHERE status = 'failed' AND created_at < %s",
            $seven_days_ago
        ));

        wp_send_json_success([
            'cleared' => $cleared,
            'message' => sprintf('%d old failed notifications cleared', $cleared)
        ]);
    }

    /**
     * AJAX: Send test notification
     */
    public function ajax_test_notification() {
        check_ajax_referer('mld_instant_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        // Create test data
        $test_listing = [
            'ListingId' => 'TEST-' . time(),
            'ListPrice' => 450000,
            'City' => 'Boston',
            'StateOrProvince' => 'MA',
            'BedroomsTotal' => 3,
            'BathroomsTotalInteger' => 2,
            'LivingArea' => 2000,
            'PropertyType' => 'Residential',
            'StandardStatus' => 'Active'
        ];

        // Trigger test notification
        do_action('bme_listing_imported', $test_listing['ListingId'], $test_listing, []);

        wp_send_json_success([
            'message' => 'Test notification triggered',
            'listing_id' => $test_listing['ListingId']
        ]);
    }

    /**
     * Get chart data for last 7 days
     *
     * @since 6.67.0 - Updated to query wp_mld_push_notification_log with sent/failed breakdown
     */
    private function get_chart_data() {
        global $wpdb;

        $push_log_table = $wpdb->prefix . 'mld_push_notification_log';
        $start_date = wp_date('Y-m-d', current_time('timestamp') - (6 * DAY_IN_SECONDS));

        // Get all notification counts grouped by date and status
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(created_at) as notification_date, status, COUNT(*) as count
             FROM {$push_log_table}
             WHERE DATE(created_at) >= %s
             GROUP BY DATE(created_at), status
             ORDER BY notification_date ASC",
            $start_date
        ), ARRAY_A);

        // Initialize arrays for each day
        $labels = [];
        $sent_data = [];
        $failed_data = [];

        for ($i = 6; $i >= 0; $i--) {
            $date = wp_date('Y-m-d', current_time('timestamp') - ($i * DAY_IN_SECONDS));
            $labels[] = wp_date('M j', (new DateTime($date, wp_timezone()))->getTimestamp());
            $sent_data[$date] = 0;
            $failed_data[$date] = 0;
        }

        // Populate data from results
        foreach ($results as $row) {
            $date = $row['notification_date'];
            if (isset($sent_data[$date])) {
                if ($row['status'] === 'sent') {
                    $sent_data[$date] = (int) $row['count'];
                } elseif ($row['status'] === 'failed') {
                    $failed_data[$date] = (int) $row['count'];
                }
            }
        }

        return [
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => 'Sent',
                    'data' => array_values($sent_data),
                    'borderColor' => '#28a745',
                    'backgroundColor' => 'rgba(40, 167, 69, 0.1)',
                    'tension' => 0.4
                ],
                [
                    'label' => 'Failed',
                    'data' => array_values($failed_data),
                    'borderColor' => '#dc3545',
                    'backgroundColor' => 'rgba(220, 53, 69, 0.1)',
                    'tension' => 0.4
                ]
            ]
        ];
    }

    /**
     * AJAX: Get filtered activity
     *
     * @since 6.67.0 - Updated to query wp_mld_push_notification_log
     */
    public function ajax_get_filtered_activity() {
        check_ajax_referer('mld_instant_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        global $wpdb;

        $push_log_table = $wpdb->prefix . 'mld_push_notification_log';

        $filters = $_POST['filters'] ?? [];
        // Use WordPress timezone for default date filters
        $date_start = $filters['date_start'] ?? wp_date('Y-m-d', current_time('timestamp') - (7 * DAY_IN_SECONDS));
        $date_end = $filters['date_end'] ?? wp_date('Y-m-d');
        $status = $filters['status'] ?? '';
        $type = $filters['type'] ?? '';

        $where_clauses = ["DATE(p.created_at) BETWEEN %s AND %s"];
        $prepare_values = [$date_start, $date_end];

        if (!empty($status)) {
            $where_clauses[] = "p.status = %s";
            $prepare_values[] = $status;
        }

        if (!empty($type)) {
            $where_clauses[] = "p.notification_type = %s";
            $prepare_values[] = $type;
        }

        $where_sql = implode(' AND ', $where_clauses);

        $query = "SELECT
                    p.id,
                    p.user_id,
                    p.notification_type,
                    p.status,
                    p.created_at,
                    p.apns_reason,
                    u.display_name,
                    JSON_UNQUOTE(JSON_EXTRACT(p.payload, '$.listing_id')) as listing_id
                  FROM {$push_log_table} p
                  LEFT JOIN {$wpdb->users} u ON p.user_id = u.ID
                  WHERE $where_sql
                  ORDER BY p.created_at DESC
                  LIMIT 100";

        $activities = $wpdb->get_results($wpdb->prepare($query, ...$prepare_values));

        $html = '';
        if (empty($activities)) {
            $html = '<div class="log-entry">No activities found for the selected filters.</div>';
        } else {
            foreach ($activities as $activity) {
                // Database stores in WordPress timezone (EST), use directly for display
                $created_dt = new DateTime($activity->created_at, wp_timezone());
                $time = $created_dt->format('H:i:s');
                $date = $created_dt->format('Y-m-d');
                $class = $activity->status === 'failed' ? 'error' :
                        ($activity->status === 'sent' ? 'success' : '');

                $listing_display = $activity->listing_id ?: '-';
                $type_display = ucwords(str_replace('_', ' ', $activity->notification_type ?: 'unknown'));

                $status_info = ucfirst($activity->status);
                if ($activity->status === 'failed' && !empty($activity->apns_reason)) {
                    $status_info .= ' (' . $activity->apns_reason . ')';
                }

                $html .= sprintf(
                    '<div class="log-entry %s">[%s %s] User: %s | Type: %s | Listing: %s | Status: %s</div>',
                    $class,
                    $date,
                    $time,
                    esc_html($activity->display_name ?: 'Unknown'),
                    esc_html($type_display),
                    esc_html($listing_display),
                    esc_html($status_info)
                );
            }
        }

        wp_send_json_success(['html' => $html]);
    }

    /**
     * AJAX: Get activity log
     *
     * @since 6.67.0 - Updated to query wp_mld_push_notification_log
     */
    public function ajax_get_activity_log() {
        check_ajax_referer('mld_instant_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        global $wpdb;

        $push_log_table = $wpdb->prefix . 'mld_push_notification_log';

        // Get last 50 activities
        $activities = $wpdb->get_results(
            "SELECT
                p.id,
                p.user_id,
                p.notification_type,
                p.status,
                p.created_at,
                p.apns_reason,
                u.display_name,
                JSON_UNQUOTE(JSON_EXTRACT(p.payload, '$.listing_id')) as listing_id
             FROM {$push_log_table} p
             LEFT JOIN {$wpdb->users} u ON p.user_id = u.ID
             ORDER BY p.created_at DESC
             LIMIT 50"
        );

        $html = '';
        if (empty($activities)) {
            $html = '<div class="log-entry">No recent activity to display.</div>';
        } else {
            foreach ($activities as $activity) {
                // Database stores in WordPress timezone (EST), use directly for display
                $created_dt = new DateTime($activity->created_at, wp_timezone());
                $timestamp = $created_dt->format('Y-m-d H:i:s');
                $class = $activity->status === 'failed' ? 'error' :
                        ($activity->status === 'sent' ? 'success' : '');

                $type_display = ucwords(str_replace('_', ' ', $activity->notification_type ?: 'unknown'));
                $listing_display = $activity->listing_id ?: '-';

                $status_info = ucfirst($activity->status);
                if ($activity->status === 'failed' && !empty($activity->apns_reason)) {
                    $status_info .= ' (' . $activity->apns_reason . ')';
                }

                $html .= sprintf(
                    '<div class="log-entry %s">[%s] %s - %s (Listing: %s) - Status: %s</div>',
                    $class,
                    $timestamp,
                    esc_html($activity->display_name ?: 'Unknown'),
                    esc_html($type_display),
                    esc_html($listing_display),
                    esc_html($status_info)
                );
            }
        }

        wp_send_json_success(['html' => $html]);
    }

    /**
     * AJAX: Get realtime stats
     *
     * @since 6.67.0 - Updated to query wp_mld_push_notification_log with sent/failed breakdown
     */
    public function ajax_get_realtime_stats() {
        check_ajax_referer('mld_instant_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        global $wpdb;

        $push_log_table = $wpdb->prefix . 'mld_push_notification_log';

        // Get notifications in last minute using WordPress timezone
        $one_minute_ago = wp_date('Y-m-d H:i:s', current_time('timestamp') - MINUTE_IN_SECONDS);

        // Get sent count in last minute
        $sent_rate = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$push_log_table}
             WHERE created_at > %s AND status = 'sent'",
            $one_minute_ago
        ));

        // Get failed count in last minute
        $failed_rate = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$push_log_table}
             WHERE created_at > %s AND status = 'failed'",
            $one_minute_ago
        ));

        wp_send_json_success([
            'rate' => ($sent_rate ?: 0) + ($failed_rate ?: 0),
            'sent_rate' => $sent_rate ?: 0,
            'failed_rate' => $failed_rate ?: 0
        ]);
    }
}