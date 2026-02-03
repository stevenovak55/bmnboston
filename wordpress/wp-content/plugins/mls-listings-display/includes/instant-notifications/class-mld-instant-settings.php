<?php
/**
 * MLD Instant Notifications Settings - Admin Configuration Page
 *
 * Provides admin interface for fine-tuning notification system settings
 *
 * @package MLS_Listings_Display
 * @subpackage Instant_Notifications
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Instant_Settings {

    /**
     * Constructor
     */
    public function __construct() {
        add_action('admin_menu', [$this, 'add_settings_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('wp_ajax_mld_update_user_limits', [$this, 'ajax_update_user_limits']);
        add_action('wp_ajax_mld_reset_throttle_data', [$this, 'ajax_reset_throttle_data']);
        add_action('wp_ajax_mld_retry_queue_item', [$this, 'ajax_retry_queue_item']);
        add_action('wp_ajax_mld_remove_queue_item', [$this, 'ajax_remove_queue_item']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
    }

    /**
     * Add settings menu to admin
     */
    public function add_settings_menu() {
        add_submenu_page(
            'mls_listings_display',
            'Notification Settings',
            'Notification Settings',
            'manage_options',
            'mld-notification-settings',
            [$this, 'render_settings_page']
        );
    }

    /**
     * Register settings
     */
    public function register_settings() {
        // Global Settings
        register_setting('mld_notification_settings', 'mld_global_quiet_hours_enabled');
        register_setting('mld_notification_settings', 'mld_global_throttling_enabled');
        register_setting('mld_notification_settings', 'mld_override_user_preferences');
        register_setting('mld_notification_settings', 'mld_default_daily_limit');
        register_setting('mld_notification_settings', 'mld_default_quiet_start');
        register_setting('mld_notification_settings', 'mld_default_quiet_end');
        register_setting('mld_notification_settings', 'mld_throttle_window_minutes');
        register_setting('mld_notification_settings', 'mld_max_notifications_per_window');
        register_setting('mld_notification_settings', 'mld_enable_bulk_import_throttle');
        register_setting('mld_notification_settings', 'mld_bulk_import_threshold');
        register_setting('mld_notification_settings', 'mld_email_from_name');
        register_setting('mld_notification_settings', 'mld_email_from_address');
        register_setting('mld_notification_settings', 'mld_enable_notification_logs');
        register_setting('mld_notification_settings', 'mld_log_retention_days');
    }

    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_scripts($hook) {
        if (strpos($hook, 'mld-notification-settings') === false) {
            return;
        }

        wp_enqueue_script('chartjs', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.js', [], '4.4.0', true);
        wp_enqueue_script('mld-settings-admin', plugin_dir_url(__FILE__) . '../../assets/js/settings-admin.js', ['jquery', 'chartjs'], '1.0.0', true);
        wp_enqueue_script('mld-queue-admin', plugin_dir_url(__FILE__) . '../../assets/js/queue-admin.js', ['jquery'], '1.0.0', true);
        wp_enqueue_style('mld-settings-admin', plugin_dir_url(__FILE__) . '../../assets/css/settings-admin.css', [], '1.0.0');

        wp_localize_script('mld-settings-admin', 'mldSettingsAjax', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('mld_settings_nonce')
        ]);

        wp_localize_script('mld-queue-admin', 'mldSettingsAjax', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('mld_settings_nonce')
        ]);
    }

    /**
     * Render the settings page
     */
    public function render_settings_page() {
        if (isset($_POST['submit']) && wp_verify_nonce($_POST['mld_settings_nonce'], 'mld_settings_save')) {
            $this->save_settings();
            echo '<div class="notice notice-success"><p>Settings saved successfully!</p></div>';
        }

        // Get current tab
        $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'settings';

        // Get current settings
        $settings = $this->get_current_settings();
        $usage_stats = $this->get_usage_statistics();
        $user_stats = $this->get_user_notification_stats();
        ?>
        <div class="wrap mld-settings-wrap">
            <h1>
                <span class="dashicons dashicons-admin-settings"></span>
                Instant Notification Settings
            </h1>
            <p class="description">Fine-tune your instant notification system for optimal performance and user experience.</p>

            <!-- Tabs Navigation -->
            <nav class="nav-tab-wrapper">
                <a href="?page=mld-notification-settings&tab=settings"
                   class="nav-tab <?php echo $active_tab == 'settings' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-admin-generic"></span> Settings
                </a>
                <a href="?page=mld-notification-settings&tab=queue"
                   class="nav-tab <?php echo $active_tab == 'queue' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-clock"></span> Queue Management
                </a>
                <a href="?page=mld-notification-settings&tab=stats"
                   class="nav-tab <?php echo $active_tab == 'stats' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-chart-bar"></span> Statistics
                </a>
            </nav>

            <?php
            // Display content based on active tab
            switch ($active_tab) {
                case 'queue':
                    $this->render_queue_tab();
                    break;
                case 'stats':
                    $this->render_stats_tab($usage_stats, $user_stats);
                    break;
                default:
                    $this->render_settings_tab($settings, $usage_stats, $user_stats);
                    break;
            }
            ?>
        </div>
        <?php
    }

    /**
     * Render the main settings tab
     *
     * @param array $settings Settings array
     * @param array $usage_stats Usage statistics
     * @param array $user_stats User statistics
     */
    private function render_settings_tab($settings, $usage_stats, $user_stats) {
        ?>
        <div class="mld-settings-grid">
                <!-- Global Settings -->
                <div class="mld-settings-card">
                    <h2><span class="dashicons dashicons-admin-generic"></span> Global Settings</h2>
                    <form method="post" action="">
                        <?php wp_nonce_field('mld_settings_save', 'mld_settings_nonce'); ?>

                        <h3>Master Controls</h3>
                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="global_quiet_hours">Global Quiet Hours</label>
                                    <p class="description">Enable/disable quiet hours for all users</p>
                                </th>
                                <td>
                                    <label class="switch">
                                        <input type="checkbox" id="global_quiet_hours" name="mld_global_quiet_hours_enabled"
                                               value="1" <?php checked($settings['global_quiet_hours_enabled']); ?> />
                                        <span class="slider round"></span>
                                    </label>
                                    <span class="status-text"><?php echo $settings['global_quiet_hours_enabled'] ? 'Enabled' : 'Disabled'; ?></span>
                                    <p class="description">When disabled, notifications will be sent 24/7 regardless of user quiet hour settings</p>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">
                                    <label for="global_throttling">Global Throttling</label>
                                    <p class="description">Enable/disable throttling for all users</p>
                                </th>
                                <td>
                                    <label class="switch">
                                        <input type="checkbox" id="global_throttling" name="mld_global_throttling_enabled"
                                               value="1" <?php checked($settings['global_throttling_enabled']); ?> />
                                        <span class="slider round"></span>
                                    </label>
                                    <span class="status-text"><?php echo $settings['global_throttling_enabled'] ? 'Enabled' : 'Disabled'; ?></span>
                                    <p class="description">When disabled, all throttling limits are bypassed (use with caution)</p>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">
                                    <label for="override_preferences">Override User Preferences</label>
                                    <p class="description">Force global settings over user preferences</p>
                                </th>
                                <td>
                                    <label>
                                        <input type="checkbox" id="override_preferences" name="mld_override_user_preferences"
                                               value="1" <?php checked($settings['override_user_preferences']); ?> />
                                        Apply global settings to all users regardless of their personal preferences
                                    </label>
                                </td>
                            </tr>
                        </table>

                        <h3>Default Settings</h3>
                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="default_daily_limit">Default Daily Notification Limit</label>
                                    <p class="description">Maximum notifications per user per day</p>
                                </th>
                                <td>
                                    <input type="number" id="default_daily_limit" name="mld_default_daily_limit"
                                           value="<?php echo esc_attr($settings['default_daily_limit']); ?>"
                                           min="1" max="1000" class="small-text" />
                                    <span class="description">notifications/day</span>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">
                                    <label for="default_quiet_hours">Default Quiet Hours</label>
                                    <p class="description">When notifications should not be sent</p>
                                </th>
                                <td>
                                    <input type="time" id="default_quiet_start" name="mld_default_quiet_start"
                                           value="<?php echo esc_attr($settings['default_quiet_start']); ?>" />
                                    <span class="description">to</span>
                                    <input type="time" id="default_quiet_end" name="mld_default_quiet_end"
                                           value="<?php echo esc_attr($settings['default_quiet_end']); ?>" />
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">
                                    <label for="throttle_window">Throttle Window</label>
                                    <p class="description">Time window for rate limiting</p>
                                </th>
                                <td>
                                    <input type="number" id="throttle_window" name="mld_throttle_window_minutes"
                                           value="<?php echo esc_attr($settings['throttle_window_minutes']); ?>"
                                           min="1" max="60" class="small-text" />
                                    <span class="description">minutes</span>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">
                                    <label for="max_per_window">Max Notifications per Window</label>
                                    <p class="description">Maximum notifications within throttle window</p>
                                </th>
                                <td>
                                    <input type="number" id="max_per_window" name="mld_max_notifications_per_window"
                                           value="<?php echo esc_attr($settings['max_notifications_per_window']); ?>"
                                           min="1" max="100" class="small-text" />
                                    <span class="description">notifications</span>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">
                                    <label for="bulk_import_throttle">Bulk Import Protection</label>
                                    <p class="description">Prevent notification spam during bulk imports</p>
                                </th>
                                <td>
                                    <label>
                                        <input type="checkbox" id="bulk_import_throttle" name="mld_enable_bulk_import_throttle"
                                               value="1" <?php checked($settings['enable_bulk_import_throttle']); ?> />
                                        Enable bulk import detection
                                    </label>
                                    <br><br>
                                    <label for="bulk_threshold">Trigger threshold:</label>
                                    <input type="number" id="bulk_threshold" name="mld_bulk_import_threshold"
                                           value="<?php echo esc_attr($settings['bulk_import_threshold']); ?>"
                                           min="5" max="1000" class="small-text" />
                                    <span class="description">listings imported within 10 minutes</span>
                                </td>
                            </tr>
                        </table>

                        <h3>Email Configuration</h3>
                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="email_from_name">Email From Name</label>
                                </th>
                                <td>
                                    <input type="text" id="email_from_name" name="mld_email_from_name"
                                           value="<?php echo esc_attr($settings['email_from_name']); ?>"
                                           class="regular-text" />
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">
                                    <label for="email_from_address">Email From Address</label>
                                </th>
                                <td>
                                    <input type="email" id="email_from_address" name="mld_email_from_address"
                                           value="<?php echo esc_attr($settings['email_from_address']); ?>"
                                           class="regular-text" />
                                </td>
                            </tr>
                        </table>

                        <h3>Logging & Debugging</h3>
                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="enable_logs">Notification Logging</label>
                                </th>
                                <td>
                                    <label>
                                        <input type="checkbox" id="enable_logs" name="mld_enable_notification_logs"
                                               value="1" <?php checked($settings['enable_notification_logs']); ?> />
                                        Enable detailed notification logging
                                    </label>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">
                                    <label for="log_retention">Log Retention</label>
                                </th>
                                <td>
                                    <input type="number" id="log_retention" name="mld_log_retention_days"
                                           value="<?php echo esc_attr($settings['log_retention_days']); ?>"
                                           min="1" max="365" class="small-text" />
                                    <span class="description">days</span>
                                </td>
                            </tr>
                        </table>

                        <?php submit_button('Save Settings'); ?>
                    </form>
                </div>

                <!-- Usage Statistics -->
                <div class="mld-settings-card">
                    <h2><span class="dashicons dashicons-chart-bar"></span> Push Notification Statistics</h2>
                    <div class="mld-stats-grid">
                        <div class="mld-stat-item">
                            <div class="mld-stat-value"><?php echo number_format($usage_stats['total_sent']); ?></div>
                            <div class="mld-stat-label">Total Sent (All Time)</div>
                        </div>
                        <div class="mld-stat-item">
                            <div class="mld-stat-value"><?php echo number_format($usage_stats['sent_today']); ?></div>
                            <div class="mld-stat-label">Sent Today</div>
                        </div>
                        <div class="mld-stat-item">
                            <div class="mld-stat-value"><?php echo number_format($usage_stats['failed_today']); ?></div>
                            <div class="mld-stat-label">Failed Today</div>
                        </div>
                        <div class="mld-stat-item">
                            <div class="mld-stat-value"><?php echo $usage_stats['success_rate']; ?>%</div>
                            <div class="mld-stat-label">Success Rate (7d)</div>
                        </div>
                        <div class="mld-stat-item">
                            <div class="mld-stat-value"><?php echo number_format($usage_stats['active_users']); ?></div>
                            <div class="mld-stat-label">Active Users</div>
                        </div>
                        <div class="mld-stat-item">
                            <div class="mld-stat-value"><?php echo number_format($usage_stats['active_searches']); ?></div>
                            <div class="mld-stat-label">Active Searches</div>
                        </div>
                    </div>

                    <h3>Recent Activity (Last 7 Days)</h3>
                    <canvas id="activityChart" width="400" height="200"></canvas>
                </div>
            </div>

            <!-- User Management -->
            <div class="mld-settings-card full-width">
                <h2><span class="dashicons dashicons-admin-users"></span> User Notification Management</h2>
                <p class="description">Manage individual user notification limits and preferences.</p>

                <div class="mld-user-controls">
                    <label for="user-search">Search Users:</label>
                    <input type="text" id="user-search" placeholder="Search by name or email..." />
                    <button type="button" class="button" id="refresh-users">Refresh</button>
                </div>

                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Instant Searches</th>
                            <th>Daily Limit</th>
                            <th>Sent Today</th>
                            <th>Throttled Today</th>
                            <th>Quiet Hours</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="user-notification-table">
                        <?php foreach ($user_stats as $user): ?>
                        <tr data-user-id="<?php echo $user['user_id']; ?>">
                            <td>
                                <strong><?php echo esc_html($user['display_name']); ?></strong><br>
                                <small><?php echo esc_html($user['user_email']); ?></small>
                            </td>
                            <td><?php echo $user['instant_searches']; ?></td>
                            <td>
                                <input type="number" class="small-text daily-limit-input"
                                       value="<?php echo $user['daily_limit']; ?>"
                                       min="0" max="1000"
                                       data-user-id="<?php echo $user['user_id']; ?>" />
                            </td>
                            <td><?php echo $user['sent_today']; ?></td>
                            <td><?php echo $user['throttled_today']; ?></td>
                            <td>
                                <input type="time" class="quiet-start-input"
                                       value="<?php echo $user['quiet_start']; ?>"
                                       data-user-id="<?php echo $user['user_id']; ?>" />
                                <span>to</span>
                                <input type="time" class="quiet-end-input"
                                       value="<?php echo $user['quiet_end']; ?>"
                                       data-user-id="<?php echo $user['user_id']; ?>" />
                            </td>
                            <td>
                                <button type="button" class="button button-small save-user-settings"
                                        data-user-id="<?php echo $user['user_id']; ?>">Save</button>
                                <button type="button" class="button button-small reset-user-throttle"
                                        data-user-id="<?php echo $user['user_id']; ?>">Reset Throttle</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Throttle Management -->
            <div class="mld-settings-card full-width">
                <h2><span class="dashicons dashicons-update"></span> Throttle Management</h2>
                <p class="description">Monitor and manage notification throttling across the system.</p>

                <div class="mld-throttle-actions">
                    <button type="button" class="button button-primary" id="reset-all-throttles">
                        Reset All Daily Throttles
                    </button>
                    <button type="button" class="button" id="export-throttle-data">
                        Export Throttle Data
                    </button>
                </div>

                <h3>Current Throttle Status</h3>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Total Notifications</th>
                            <th>Throttled Count</th>
                            <th>Throttle Rate</th>
                            <th>Peak Hour</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $throttle_history = $this->get_throttle_history();
                        foreach ($throttle_history as $day):
                        ?>
                        <tr>
                            <td><?php echo date('M j, Y', strtotime($day['date'])); ?></td>
                            <td><?php echo number_format($day['total_notifications']); ?></td>
                            <td><?php echo number_format($day['throttled_count']); ?></td>
                            <td><?php echo $day['throttle_rate']; ?>%</td>
                            <td><?php echo $day['peak_hour']; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }

    /**
     * Render the queue management tab
     */
    private function render_queue_tab() {
        global $wpdb;

        // Handle queue actions
        if (isset($_POST['process_queue_now']) && wp_verify_nonce($_POST['queue_action_nonce'], 'mld_queue_action')) {
            $this->process_queue_manually();
            echo '<div class="notice notice-success"><p>Queue processing triggered!</p></div>';
        }

        if (isset($_POST['clear_failed']) && wp_verify_nonce($_POST['queue_action_nonce'], 'mld_queue_action')) {
            $this->clear_failed_items();
            echo '<div class="notice notice-success"><p>Failed items cleared!</p></div>';
        }

        // Get queue statistics
        $queue_stats = $this->get_queue_statistics();
        $queue_items = $this->get_queue_items();
        $next_run = wp_next_scheduled('mld_process_notification_queue');
        ?>
        <div class="mld-queue-management">
            <!-- Queue Overview -->
            <div class="mld-settings-card">
                <h2><span class="dashicons dashicons-dashboard"></span> Queue Overview</h2>

                <div class="queue-stats-grid">
                    <div class="stat-box">
                        <div class="stat-number"><?php echo $queue_stats->queued ?? 0; ?></div>
                        <div class="stat-label">Queued</div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-number"><?php echo $queue_stats->processing ?? 0; ?></div>
                        <div class="stat-label">Processing</div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-number"><?php echo $queue_stats->sent ?? 0; ?></div>
                        <div class="stat-label">Sent Today</div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-number"><?php echo $queue_stats->failed ?? 0; ?></div>
                        <div class="stat-label">Failed</div>
                    </div>
                </div>

                <p class="queue-info">
                    <strong>Next Processing:</strong>
                    <?php echo $next_run ? date('M j, Y g:i A', $next_run) : 'Not scheduled'; ?>
                    <?php if ($next_run): ?>
                        <span class="countdown">(in <?php echo human_time_diff(time(), $next_run); ?>)</span>
                    <?php endif; ?>
                </p>

                <form method="post" style="display: inline-block;">
                    <?php wp_nonce_field('mld_queue_action', 'queue_action_nonce'); ?>
                    <button type="submit" name="process_queue_now" class="button button-primary">
                        <span class="dashicons dashicons-update"></span> Process Queue Now
                    </button>
                    <?php if (($queue_stats->failed ?? 0) > 0): ?>
                    <button type="submit" name="clear_failed" class="button">
                        Clear Failed Items
                    </button>
                    <?php endif; ?>
                </form>
            </div>

            <!-- Current Queue Items -->
            <div class="mld-settings-card full-width">
                <h2><span class="dashicons dashicons-list-view"></span> Queue Items</h2>

                <?php if (empty($queue_items)): ?>
                    <p class="no-items">No items currently in queue.</p>
                <?php else: ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>User</th>
                                <th>Listing ID</th>
                                <th>Type</th>
                                <th>Reason Blocked</th>
                                <th>Retry After</th>
                                <th>Attempts</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($queue_items as $item): ?>
                            <tr class="queue-item-<?php echo $item->status; ?>">
                                <td>
                                    <?php
                                    $user = get_userdata($item->user_id);
                                    echo $user ? esc_html($user->display_name) : 'Unknown';
                                    ?>
                                </td>
                                <td><code><?php echo esc_html($item->listing_id); ?></code></td>
                                <td><?php echo esc_html(str_replace('_', ' ', $item->match_type)); ?></td>
                                <td>
                                    <span class="reason-badge reason-<?php echo esc_attr($item->reason_blocked); ?>">
                                        <?php echo esc_html(str_replace('_', ' ', $item->reason_blocked)); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php echo date('M j, g:i A', strtotime($item->retry_after)); ?>
                                    <?php if (strtotime($item->retry_after) > time()): ?>
                                        <br><small>(<?php echo human_time_diff(time(), strtotime($item->retry_after)); ?> from now)</small>
                                    <?php else: ?>
                                        <br><small class="ready">Ready to process</small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo $item->retry_attempts; ?>/<?php echo $item->max_attempts; ?>
                                </td>
                                <td>
                                    <span class="status-badge status-<?php echo esc_attr($item->status); ?>">
                                        <?php echo esc_html($item->status); ?>
                                    </span>
                                </td>
                                <td>
                                    <button type="button" class="button button-small retry-item"
                                            data-id="<?php echo $item->id; ?>">
                                        Retry Now
                                    </button>
                                    <button type="button" class="button button-small button-link-delete remove-item"
                                            data-id="<?php echo $item->id; ?>">
                                        Remove
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <style>
        .queue-stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 20px;
            margin: 20px 0;
        }
        .stat-box {
            text-align: center;
            padding: 20px;
            background: #f0f0f1;
            border-radius: 5px;
        }
        .stat-number {
            font-size: 32px;
            font-weight: bold;
            color: #2271b1;
        }
        .stat-label {
            color: #646970;
            margin-top: 5px;
        }
        .reason-badge {
            padding: 2px 8px;
            border-radius: 3px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }
        .reason-quiet_hours { background: #f0b849; color: #fff; }
        .reason-daily_limit { background: #d63638; color: #fff; }
        .reason-rate_limited { background: #00a0d2; color: #fff; }
        .reason-bulk_import { background: #826eb4; color: #fff; }
        .status-badge {
            padding: 2px 8px;
            border-radius: 3px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }
        .status-queued { background: #f0f0f1; }
        .status-processing { background: #f0b849; color: #fff; }
        .status-sent { background: #00ba37; color: #fff; }
        .status-failed { background: #d63638; color: #fff; }
        .ready { color: #00ba37; font-weight: bold; }
        </style>
        <?php
    }

    /**
     * Render the statistics tab
     */
    private function render_stats_tab($usage_stats, $user_stats) {
        ?>
        <div class="mld-stats-tab">
            <!-- Activity Overview -->
            <div class="mld-settings-card">
                <h2><span class="dashicons dashicons-chart-line"></span> Activity Overview</h2>
                <canvas id="activityChart" width="400" height="150"></canvas>
            </div>

            <!-- Usage Statistics -->
            <div class="mld-settings-card">
                <h2><span class="dashicons dashicons-admin-network"></span> System Statistics</h2>
                <table class="form-table">
                    <tr>
                        <th>Total Notifications (Last 7 Days)</th>
                        <td><strong><?php echo number_format($usage_stats['total_week']); ?></strong></td>
                    </tr>
                    <tr>
                        <th>Active Instant Searches</th>
                        <td><strong><?php echo number_format($usage_stats['active_searches']); ?></strong></td>
                    </tr>
                    <tr>
                        <th>Active Users</th>
                        <td><strong><?php echo number_format($usage_stats['active_users']); ?></strong></td>
                    </tr>
                    <tr>
                        <th>Average Response Time</th>
                        <td><strong><?php echo $usage_stats['avg_response_time']; ?> seconds</strong></td>
                    </tr>
                    <tr>
                        <th>Success Rate</th>
                        <td><strong><?php echo $usage_stats['success_rate']; ?>%</strong></td>
                    </tr>
                </table>
            </div>
        </div>

        <script>
        // Initialize activity chart
        document.addEventListener('DOMContentLoaded', function() {
            const ctx = document.getElementById('activityChart').getContext('2d');
            const chartData = <?php echo json_encode($this->get_chart_data()); ?>;

            new Chart(ctx, {
                type: 'line',
                data: chartData,
                options: {
                    responsive: true,
                    scales: {
                        y: {
                            beginAtZero: true
                        }
                    }
                }
            });
        });
        </script>
        <?php
    }

    /**
     * Get current settings with defaults
     */
    private function get_current_settings() {
        return [
            'global_quiet_hours_enabled' => get_option('mld_global_quiet_hours_enabled', true),
            'global_throttling_enabled' => get_option('mld_global_throttling_enabled', true),
            'override_user_preferences' => get_option('mld_override_user_preferences', false),
            'default_daily_limit' => get_option('mld_default_daily_limit', 50),
            'default_quiet_start' => get_option('mld_default_quiet_start', '22:00'),
            'default_quiet_end' => get_option('mld_default_quiet_end', '06:00'),
            'throttle_window_minutes' => get_option('mld_throttle_window_minutes', 5),
            'max_notifications_per_window' => get_option('mld_max_notifications_per_window', 10),
            'enable_bulk_import_throttle' => get_option('mld_enable_bulk_import_throttle', true),
            'bulk_import_threshold' => get_option('mld_bulk_import_threshold', 50),
            'email_from_name' => get_option('mld_email_from_name', get_bloginfo('name')),
            'email_from_address' => get_option('mld_email_from_address', get_option('admin_email')),
            'enable_notification_logs' => get_option('mld_enable_notification_logs', true),
            'log_retention_days' => get_option('mld_log_retention_days', 30)
        ];
    }

    /**
     * Save settings
     */
    private function save_settings() {
        $settings_map = [
            'mld_global_quiet_hours_enabled' => 'boolval',
            'mld_global_throttling_enabled' => 'boolval',
            'mld_override_user_preferences' => 'boolval',
            'mld_default_daily_limit' => 'absint',
            'mld_default_quiet_start' => 'sanitize_text_field',
            'mld_default_quiet_end' => 'sanitize_text_field',
            'mld_throttle_window_minutes' => 'absint',
            'mld_max_notifications_per_window' => 'absint',
            'mld_enable_bulk_import_throttle' => 'boolval',
            'mld_bulk_import_threshold' => 'absint',
            'mld_email_from_name' => 'sanitize_text_field',
            'mld_email_from_address' => 'sanitize_email',
            'mld_enable_notification_logs' => 'boolval',
            'mld_log_retention_days' => 'absint'
        ];

        foreach ($settings_map as $setting => $sanitize_func) {
            if ($sanitize_func === 'boolval') {
                // Handle checkboxes - they're not sent if unchecked
                $value = isset($_POST[$setting]) ? 1 : 0;
                update_option($setting, $value);
            } elseif (isset($_POST[$setting])) {
                $value = call_user_func($sanitize_func, $_POST[$setting]);
                update_option($setting, $value);
            }
        }
    }

    /**
     * Get usage statistics
     *
     * @since 6.67.0 - Updated to query wp_mld_push_notification_log for real notification data
     */
    private function get_usage_statistics() {
        global $wpdb;

        $stats = [];
        $push_log_table = $wpdb->prefix . 'mld_push_notification_log';
        $today = current_time('Y-m-d');
        $week_ago = wp_date('Y-m-d', current_time('timestamp') - (7 * DAY_IN_SECONDS));

        // Total sent (all time from push notification log)
        $stats['total_sent'] = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$push_log_table}
             WHERE status = 'sent'"
        ) ?: 0;

        // Sent today (from push notification log)
        $stats['sent_today'] = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$push_log_table}
             WHERE status = 'sent' AND DATE(created_at) = %s",
            $today
        )) ?: 0;

        // Failed today (from push notification log)
        $stats['failed_today'] = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$push_log_table}
             WHERE status = 'failed' AND DATE(created_at) = %s",
            $today
        )) ?: 0;

        // Keep throttled_today for backwards compatibility (from throttle table)
        $stats['throttled_today'] = $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(throttled_count) FROM {$wpdb->prefix}mld_notification_throttle
             WHERE notification_date = %s",
            $today
        )) ?: 0;

        // Active users with saved searches (any frequency, not just instant)
        $stats['active_users'] = $wpdb->get_var(
            "SELECT COUNT(DISTINCT user_id) FROM {$wpdb->prefix}mld_saved_searches
             WHERE is_active = 1"
        ) ?: 0;

        // Instant searches only (for display as "Instant Searches")
        $stats['instant_searches'] = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}mld_saved_searches
             WHERE notification_frequency = 'instant' AND is_active = 1"
        ) ?: 0;

        // All active searches
        $stats['active_searches'] = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}mld_saved_searches
             WHERE is_active = 1"
        ) ?: 0;

        // Total all searches (alias for active_searches)
        $stats['total_searches'] = $stats['active_searches'];

        // Total notifications sent in the last 7 days (from push notification log)
        $stats['total_week'] = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$push_log_table}
             WHERE status = 'sent' AND DATE(created_at) >= %s",
            $week_ago
        )) ?: 0;

        // Average response time - not tracked in push notification log, estimate based on typical APNs latency
        $stats['avg_response_time'] = 0.2;

        // Success rate (last 7 days from push notification log)
        $total_attempts = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$push_log_table}
             WHERE DATE(created_at) >= %s",
            $week_ago
        )) ?: 0;
        $successful = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$push_log_table}
             WHERE status = 'sent' AND DATE(created_at) >= %s",
            $week_ago
        )) ?: 0;
        $stats['success_rate'] = $total_attempts > 0 ? round(($successful / $total_attempts) * 100, 1) : 100;

        // Legacy fields for backwards compatibility
        $stats['total_opened'] = 0;
        $stats['total_clicked'] = 0;
        $stats['open_rate'] = 0;
        $stats['click_rate'] = 0;

        return $stats;
    }

    /**
     * Get user notification statistics
     */
    private function get_user_notification_stats() {
        global $wpdb;

        $users = $wpdb->get_results("
            SELECT DISTINCT u.ID, u.display_name, u.user_email,
                   COUNT(DISTINCT ss.id) as instant_searches,
                   COALESCE(np.max_daily_notifications, " . get_option('mld_default_daily_limit', 50) . ") as daily_limit,
                   COALESCE(np.quiet_hours_start, '" . get_option('mld_default_quiet_start', '22:00') . "') as quiet_start,
                   COALESCE(np.quiet_hours_end, '" . get_option('mld_default_quiet_end', '06:00') . "') as quiet_end
            FROM {$wpdb->users} u
            LEFT JOIN {$wpdb->prefix}mld_saved_searches ss ON u.ID = ss.user_id
                AND ss.notification_frequency = 'instant' AND ss.is_active = 1
            LEFT JOIN {$wpdb->prefix}mld_notification_preferences np ON u.ID = np.user_id AND np.saved_search_id = 0
            WHERE u.ID IN (
                SELECT DISTINCT user_id FROM {$wpdb->prefix}mld_saved_searches
                WHERE notification_frequency = 'instant' AND is_active = 1
            )
            GROUP BY u.ID
            ORDER BY instant_searches DESC, u.display_name
        ");

        foreach ($users as &$user) {
            // Get today's sent count
            $user->sent_today = $wpdb->get_var($wpdb->prepare("
                SELECT COUNT(*) FROM {$wpdb->prefix}mld_search_activity_matches sam
                JOIN {$wpdb->prefix}mld_saved_searches ss ON sam.saved_search_id = ss.id
                WHERE ss.user_id = %d
                AND sam.notification_status = 'sent'
                AND DATE(sam.notified_at) = %s
            ", $user->ID, current_time('Y-m-d')));

            // Get today's throttled count
            $user->throttled_today = $wpdb->get_var($wpdb->prepare("
                SELECT COALESCE(SUM(throttled_count), 0) FROM {$wpdb->prefix}mld_notification_throttle
                WHERE user_id = %d AND notification_date = %s
            ", $user->ID, current_time('Y-m-d')));

            // Convert to array for easier handling in template
            $user = [
                'user_id' => $user->ID,
                'display_name' => $user->display_name,
                'user_email' => $user->user_email,
                'instant_searches' => $user->instant_searches,
                'daily_limit' => $user->daily_limit,
                'quiet_start' => $user->quiet_start,
                'quiet_end' => $user->quiet_end,
                'sent_today' => $user->sent_today,
                'throttled_today' => $user->throttled_today
            ];
        }

        return $users;
    }

    /**
     * Get throttle history
     */
    private function get_throttle_history() {
        global $wpdb;

        // Use WordPress timezone-aware date instead of MySQL CURDATE()
        $wp_week_ago = wp_date('Y-m-d', current_time('timestamp') - (7 * DAY_IN_SECONDS));
        return $wpdb->get_results($wpdb->prepare("
            SELECT
                notification_date as date,
                SUM(notification_count) as total_notifications,
                SUM(throttled_count) as throttled_count,
                ROUND(SUM(throttled_count) / NULLIF(SUM(notification_count), 0) * 100, 1) as throttle_rate,
                HOUR(MAX(last_notification_at)) as peak_hour
            FROM {$wpdb->prefix}mld_notification_throttle
            WHERE notification_date >= %s
            GROUP BY notification_date
            ORDER BY notification_date DESC
        ", $wp_week_ago), ARRAY_A);
    }

    /**
     * Get chart data for activity visualization
     *
     * @since 6.9.8 - Optimized from 14 queries to 2 queries using GROUP BY
     * @since 6.67.0 - Updated to query wp_mld_push_notification_log for real data, showing sent vs failed
     */
    private function get_chart_data() {
        global $wpdb;

        $push_log_table = $wpdb->prefix . 'mld_push_notification_log';

        // Calculate date range using WordPress timezone
        $start_date = wp_date('Y-m-d', current_time('timestamp') - (6 * DAY_IN_SECONDS));
        $end_date = wp_date('Y-m-d');

        // Build labels array and initialize data arrays
        $days = [];
        $sent_lookup = [];
        $failed_lookup = [];

        for ($i = 6; $i >= 0; $i--) {
            $date = wp_date('Y-m-d', current_time('timestamp') - ($i * DAY_IN_SECONDS));
            $days[] = wp_date('M j', (new DateTime($date, wp_timezone()))->getTimestamp());
            $sent_lookup[$date] = 0;
            $failed_lookup[$date] = 0;
        }

        // Single query for all notifications grouped by date and status
        $results = $wpdb->get_results($wpdb->prepare("
            SELECT DATE(created_at) as notification_date, status, COUNT(*) as count
            FROM {$push_log_table}
            WHERE DATE(created_at) BETWEEN %s AND %s
            GROUP BY DATE(created_at), status
        ", $start_date, $end_date), ARRAY_A);

        foreach ($results as $row) {
            $date = $row['notification_date'];
            if ($row['status'] === 'sent' && isset($sent_lookup[$date])) {
                $sent_lookup[$date] = (int) $row['count'];
            } elseif ($row['status'] === 'failed' && isset($failed_lookup[$date])) {
                $failed_lookup[$date] = (int) $row['count'];
            }
        }

        // Build ordered data arrays
        $sent_data = array_values($sent_lookup);
        $failed_data = array_values($failed_lookup);

        return [
            'labels' => $days,
            'datasets' => [
                [
                    'label' => 'Sent',
                    'data' => $sent_data,
                    'borderColor' => '#28a745',
                    'backgroundColor' => 'rgba(40, 167, 69, 0.1)',
                    'tension' => 0.4
                ],
                [
                    'label' => 'Failed',
                    'data' => $failed_data,
                    'borderColor' => '#dc3545',
                    'backgroundColor' => 'rgba(220, 53, 69, 0.1)',
                    'tension' => 0.4
                ]
            ]
        ];
    }

    /**
     * AJAX handler for updating user limits
     */
    public function ajax_update_user_limits() {
        check_ajax_referer('mld_settings_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }

        $user_id = absint($_POST['user_id']);
        $daily_limit = absint($_POST['daily_limit']);
        $quiet_start = sanitize_text_field($_POST['quiet_start']);
        $quiet_end = sanitize_text_field($_POST['quiet_end']);

        global $wpdb;

        // Update or insert user preferences
        // Use WordPress timezone-aware time instead of MySQL NOW()
        $wp_now = current_time('mysql');
        $wpdb->query($wpdb->prepare("
            INSERT INTO {$wpdb->prefix}mld_notification_preferences
            (user_id, saved_search_id, max_daily_notifications, quiet_hours_start, quiet_hours_end, updated_at)
            VALUES (%d, 0, %d, %s, %s, %s)
            ON DUPLICATE KEY UPDATE
            max_daily_notifications = VALUES(max_daily_notifications),
            quiet_hours_start = VALUES(quiet_hours_start),
            quiet_hours_end = VALUES(quiet_hours_end),
            updated_at = %s
        ", $user_id, $daily_limit, $quiet_start, $quiet_end, $wp_now, $wp_now));

        wp_send_json_success(['message' => 'User settings updated successfully']);
    }

    /**
     * AJAX handler for resetting throttle data
     */
    public function ajax_reset_throttle_data() {
        check_ajax_referer('mld_settings_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }

        $user_id = isset($_POST['user_id']) ? absint($_POST['user_id']) : null;

        global $wpdb;

        if ($user_id) {
            // Reset specific user's throttle data for today
            $wpdb->update(
                $wpdb->prefix . 'mld_notification_throttle',
                ['notification_count' => 0, 'throttled_count' => 0],
                ['user_id' => $user_id, 'notification_date' => current_time('Y-m-d')]
            );
            $message = 'User throttle data reset successfully';
        } else {
            // Reset all throttle data for today
            $wpdb->update(
                $wpdb->prefix . 'mld_notification_throttle',
                ['notification_count' => 0, 'throttled_count' => 0],
                ['notification_date' => current_time('Y-m-d')]
            );
            $message = 'All throttle data reset successfully';
        }

        wp_send_json_success(['message' => $message]);
    }

    /**
     * Get queue statistics
     */
    private function get_queue_statistics() {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_notification_queue';

        // Use WordPress timezone-aware dates instead of MySQL CURDATE()/NOW()
        $wp_today = wp_date('Y-m-d');
        $wp_now = current_time('mysql');

        return $wpdb->get_row($wpdb->prepare("
            SELECT
                COUNT(CASE WHEN status = 'queued' THEN 1 END) as queued,
                COUNT(CASE WHEN status = 'processing' THEN 1 END) as processing,
                COUNT(CASE WHEN status = 'sent' AND DATE(processed_at) = %s THEN 1 END) as sent,
                COUNT(CASE WHEN status = 'failed' THEN 1 END) as failed,
                COUNT(CASE WHEN status = 'expired' THEN 1 END) as expired
            FROM {$table}
            WHERE created_at >= DATE_SUB(%s, INTERVAL 7 DAY)
        ", $wp_today, $wp_now));
    }

    /**
     * Get queue items
     */
    private function get_queue_items() {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_notification_queue';

        return $wpdb->get_results("
            SELECT * FROM {$table}
            WHERE status IN ('queued', 'processing', 'failed')
            ORDER BY
                CASE
                    WHEN status = 'processing' THEN 1
                    WHEN status = 'queued' THEN 2
                    WHEN status = 'failed' THEN 3
                END,
                retry_after ASC
            LIMIT 50
        ");
    }

    /**
     * Process queue manually
     */
    private function process_queue_manually() {
        // Load queue processor if needed
        if (!class_exists('MLD_Queue_Processor')) {
            require_once plugin_dir_path(__FILE__) . 'class-mld-queue-processor.php';
        }

        // Initialize necessary components
        $init = MLD_Instant_Notifications_Init::get_instance();
        $router = $init->get_component('router');
        $throttle = $init->get_component('throttle');

        $processor = new MLD_Queue_Processor();
        $processor->set_dependencies($router, $throttle);

        // Process the queue
        $result = $processor->process_queue();

        // Log the result
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Manual queue processing: ' . json_encode($result));
        }

        return $result;
    }

    /**
     * Clear failed items from queue
     */
    private function clear_failed_items() {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_notification_queue';

        return $wpdb->delete($table, ['status' => 'failed']);
    }

    /**
     * AJAX handler to retry a single queue item
     */
    public function ajax_retry_queue_item() {
        check_ajax_referer('mld_settings_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $item_id = intval($_POST['item_id']);

        global $wpdb;
        $table = $wpdb->prefix . 'mld_notification_queue';

        // Reset the item for immediate retry
        $updated = $wpdb->update(
            $table,
            [
                'status' => 'queued',
                'retry_after' => current_time('mysql'),
                'retry_attempts' => 0
            ],
            ['id' => $item_id]
        );

        if ($updated) {
            // Process immediately
            $this->process_queue_manually();
            wp_send_json_success('Item queued for retry');
        } else {
            wp_send_json_error('Failed to retry item');
        }
    }

    /**
     * AJAX handler to remove a queue item
     */
    public function ajax_remove_queue_item() {
        check_ajax_referer('mld_settings_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $item_id = intval($_POST['item_id']);

        global $wpdb;
        $table = $wpdb->prefix . 'mld_notification_queue';

        $deleted = $wpdb->delete($table, ['id' => $item_id]);

        if ($deleted) {
            wp_send_json_success('Item removed from queue');
        } else {
            wp_send_json_error('Failed to remove item');
        }
    }
}
