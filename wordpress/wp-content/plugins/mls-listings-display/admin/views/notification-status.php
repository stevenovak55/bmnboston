<?php
/**
 * Admin View: Notification Status Dashboard
 * Simple dashboard for the new simplified notification system
 *
 * @package MLS_Listings_Display
 */

if (!defined('ABSPATH')) {
    exit;
}

// Get notification system status
$notifications_enabled = get_option('mld_notifications_enabled', true);
$last_run = get_option('mld_last_notification_run', '');
$notifications_sent_today = get_option('mld_notifications_sent_today', 0);
$next_scheduled = wp_next_scheduled('mld_simple_notifications_check');

// Get basic stats
global $wpdb;
$total_searches = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}mld_saved_searches WHERE is_active = 1");
$total_users = $wpdb->get_var("SELECT COUNT(DISTINCT user_id) FROM {$wpdb->prefix}mld_saved_searches WHERE is_active = 1");

?>

<div class="wrap">
    <h1>Notification Status Dashboard</h1>
    <p>Simple overview of your property notification system health and activity.</p>

    <!-- System Status Cards -->
    <div class="mld-status-cards">
        <div class="status-card <?php echo $notifications_enabled ? 'status-active' : 'status-inactive'; ?>">
            <div class="card-icon">
                <span class="dashicons <?php echo $notifications_enabled ? 'dashicons-yes-alt' : 'dashicons-dismiss'; ?>"></span>
            </div>
            <div class="card-content">
                <h3>System Status</h3>
                <p class="status-text"><?php echo $notifications_enabled ? 'Active' : 'Disabled'; ?></p>
                <button type="button" class="button toggle-notifications" data-enabled="<?php echo $notifications_enabled ? 'false' : 'true'; ?>">
                    <?php echo $notifications_enabled ? 'Disable Notifications' : 'Enable Notifications'; ?>
                </button>
            </div>
        </div>

        <div class="status-card">
            <div class="card-icon">
                <span class="dashicons dashicons-clock"></span>
            </div>
            <div class="card-content">
                <h3>Next Check</h3>
                <p class="status-text">
                    <?php
                    if ($next_scheduled) {
                        echo human_time_diff($next_scheduled, time()) . ' from now';
                    } else {
                        echo 'Not scheduled';
                    }
                    ?>
                </p>
                <small><?php echo $next_scheduled ? date('M j, Y g:i A', $next_scheduled) : 'N/A'; ?></small>
            </div>
        </div>

        <div class="status-card">
            <div class="card-icon">
                <span class="dashicons dashicons-email-alt"></span>
            </div>
            <div class="card-content">
                <h3>Sent Today</h3>
                <p class="status-text"><?php echo number_format($notifications_sent_today); ?></p>
                <small>Email notifications</small>
            </div>
        </div>

        <div class="status-card">
            <div class="card-icon">
                <span class="dashicons dashicons-admin-users"></span>
            </div>
            <div class="card-content">
                <h3>Active Users</h3>
                <p class="status-text"><?php echo number_format($total_users); ?></p>
                <small>With saved searches</small>
            </div>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="mld-quick-actions">
        <h2>Quick Actions</h2>
        <div class="action-buttons">
            <button type="button" class="button button-primary send-test-notification">
                <span class="dashicons dashicons-email"></span>
                Send Test Notification
            </button>
            <a href="<?php echo admin_url('admin.php?page=mld_form_submissions'); ?>" class="button">
                <span class="dashicons dashicons-list-view"></span>
                View Form Submissions
            </a>
            <a href="<?php echo admin_url('admin.php?page=mld_cleanup_tool'); ?>" class="button">
                <span class="dashicons dashicons-admin-tools"></span>
                Cleanup Tool
            </a>
        </div>
    </div>

    <!-- Recent Activity -->
    <div class="mld-recent-activity">
        <h2>System Activity</h2>
        <div class="activity-grid">
            <div class="activity-item">
                <h4>Active Searches</h4>
                <p class="activity-number"><?php echo number_format($total_searches); ?></p>
                <p class="activity-desc">Saved searches monitoring for new listings</p>
            </div>

            <div class="activity-item">
                <h4>Last Check</h4>
                <p class="activity-text">
                    <?php
                    if ($last_run) {
                        echo human_time_diff(strtotime($last_run), time()) . ' ago';
                    } else {
                        echo 'Never';
                    }
                    ?>
                </p>
                <p class="activity-desc">When system last checked for new listings</p>
            </div>

            <div class="activity-item">
                <h4>System Health</h4>
                <p class="activity-text">
                    <span class="health-indicator <?php echo $notifications_enabled ? 'healthy' : 'warning'; ?>">
                        <?php echo $notifications_enabled ? 'Good' : 'Disabled'; ?>
                    </span>
                </p>
                <p class="activity-desc">Overall notification system status</p>
            </div>
        </div>
    </div>

    <!-- Settings Quick Links -->
    <div class="mld-settings-links">
        <h2>Notification Settings</h2>
        <ul>
            <li><a href="<?php echo admin_url('admin.php?page=mls_listings_display'); ?>">General Settings</a> - Configure basic email settings</li>
            <li><a href="<?php echo admin_url('admin.php?page=mld_agent_contacts'); ?>">Agent Contacts</a> - Set up agent information for notifications</li>
            <li><a href="<?php echo admin_url('admin.php?page=mld_form_submissions'); ?>">Form Submissions</a> - View and manage contact form submissions</li>
        </ul>
    </div>
</div>

<style>
/* Status Dashboard Styles */
.mld-status-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    margin: 20px 0;
}

.status-card {
    background: #fff;
    border: 1px solid #ccd0d4;
    border-radius: 4px;
    padding: 20px;
    display: flex;
    align-items: center;
    gap: 15px;
    transition: box-shadow 0.2s ease;
}

.status-card:hover {
    box-shadow: 0 2px 5px rgba(0,0,0,0.1);
}

.status-card.status-active {
    border-left: 4px solid #46b450;
}

.status-card.status-inactive {
    border-left: 4px solid #dc3232;
}

.card-icon {
    font-size: 32px;
    color: #666;
}

.status-active .card-icon {
    color: #46b450;
}

.status-inactive .card-icon {
    color: #dc3232;
}

.card-content h3 {
    margin: 0 0 5px 0;
    font-size: 14px;
    color: #666;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.status-text {
    font-size: 24px;
    font-weight: bold;
    margin: 0 0 5px 0;
    color: #333;
}

.card-content small {
    color: #666;
    font-size: 12px;
}

/* Quick Actions */
.mld-quick-actions {
    background: #f9f9f9;
    border: 1px solid #e5e5e5;
    border-radius: 4px;
    padding: 20px;
    margin: 30px 0;
}

.mld-quick-actions h2 {
    margin-top: 0;
}

.action-buttons {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.action-buttons .button {
    display: flex;
    align-items: center;
    gap: 5px;
}

/* Recent Activity */
.mld-recent-activity {
    margin: 30px 0;
}

.activity-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
}

.activity-item {
    background: #fff;
    border: 1px solid #e5e5e5;
    border-radius: 4px;
    padding: 20px;
    text-align: center;
}

.activity-item h4 {
    margin: 0 0 10px 0;
    color: #666;
    font-size: 14px;
    text-transform: uppercase;
}

.activity-number {
    font-size: 32px;
    font-weight: bold;
    margin: 0;
    color: #0073aa;
}

.activity-text {
    font-size: 18px;
    font-weight: bold;
    margin: 0;
    color: #333;
}

.activity-desc {
    margin: 5px 0 0 0;
    color: #666;
    font-size: 12px;
}

.health-indicator.healthy {
    color: #46b450;
}

.health-indicator.warning {
    color: #dc3232;
}

/* Settings Links */
.mld-settings-links {
    background: #fff;
    border: 1px solid #e5e5e5;
    border-radius: 4px;
    padding: 20px;
    margin: 30px 0;
}

.mld-settings-links h2 {
    margin-top: 0;
}

.mld-settings-links ul {
    list-style: none;
    padding: 0;
    margin: 0;
}

.mld-settings-links li {
    padding: 10px 0;
    border-bottom: 1px solid #f0f0f0;
}

.mld-settings-links li:last-child {
    border-bottom: none;
}

.mld-settings-links a {
    text-decoration: none;
    font-weight: bold;
    color: #0073aa;
}

.mld-settings-links a:hover {
    color: #005a87;
}

/* Responsive */
@media (max-width: 782px) {
    .mld-status-cards {
        grid-template-columns: 1fr;
    }

    .action-buttons {
        flex-direction: column;
    }

    .action-buttons .button {
        justify-content: center;
    }
}
</style>

<script>
jQuery(document).ready(function($) {
    // Toggle notifications
    $('.toggle-notifications').on('click', function() {
        const $button = $(this);
        const enabled = $button.data('enabled') === 'true';
        const originalText = $button.text();

        $button.prop('disabled', true).text('Updating...');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'mld_toggle_notifications',
                enabled: enabled,
                nonce: '<?php echo wp_create_nonce('mld_admin_nonce'); ?>'
            },
            success: function(response) {
                if (response.success) {
                    // Reload page to show updated status
                    location.reload();
                } else {
                    alert('Error: ' + response.data);
                }
            },
            error: function() {
                alert('Failed to update notification settings');
            },
            complete: function() {
                $button.prop('disabled', false).text(originalText);
            }
        });
    });

    // Send test notification
    $('.send-test-notification').on('click', function() {
        const $button = $(this);
        const originalText = $button.text();

        $button.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> Sending...');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'mld_send_test_notification',
                nonce: '<?php echo wp_create_nonce('mld_admin_nonce'); ?>'
            },
            success: function(response) {
                if (response.success) {
                    alert('Test notification sent successfully!');
                } else {
                    alert('Error: ' + response.data);
                }
            },
            error: function() {
                alert('Failed to send test notification');
            },
            complete: function() {
                $button.prop('disabled', false).html('<span class="dashicons dashicons-email"></span> Send Test Notification');
            }
        });
    });
});
</script>

<style>
.spin {
    animation: spin 1s linear infinite;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}
</style>