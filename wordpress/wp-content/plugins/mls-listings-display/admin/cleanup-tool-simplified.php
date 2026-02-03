<?php
/**
 * MLS Listings Display - Simplified Cleanup Tool
 * For v5.0.0 simplified notification system
 *
 * @package MLS_Listings_Display
 */

if (!defined('ABSPATH')) {
    exit;
}

// Load notification classes for testing
if (!class_exists('MLD_Notification_Tracker')) {
    $tracker_file = MLD_PLUGIN_PATH . 'includes/notifications/class-mld-notification-tracker.php';
    if (file_exists($tracker_file)) {
        require_once $tracker_file;
    }
}

// Handle actions
$message = '';
$notice_class = '';

if (isset($_POST['mld_cleanup_action']) && wp_verify_nonce($_POST['mld_cleanup_nonce'], 'mld_cleanup_action')) {
    $action = sanitize_text_field($_POST['mld_cleanup_action']);

    switch ($action) {
        case 'clear_cache':
            // Clear WordPress cache
            wp_cache_flush();

            // Clear any transients
            global $wpdb;
            $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_mld_%'");
            $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_mld_%'");

            $message = 'Cache cleared successfully!';
            $notice_class = 'notice-success';
            break;

        case 'reset_cron':
            // Clear existing legacy cron
            wp_clear_scheduled_hook('mld_simple_notifications_check');

            // Also ensure modern notification crons are scheduled
            $crons_to_schedule = array(
                'mld_saved_search_instant' => 'five_minutes',
                'mld_saved_search_hourly' => 'hourly',
                'mld_saved_search_daily' => 'daily',
            );

            $scheduled_count = 0;
            foreach ($crons_to_schedule as $hook => $recurrence) {
                if (!wp_next_scheduled($hook)) {
                    wp_schedule_event(time() + 60, $recurrence, $hook);
                    $scheduled_count++;
                }
            }

            $message = $scheduled_count > 0
                ? "Notification cron jobs rescheduled! ({$scheduled_count} jobs scheduled)"
                : 'All notification cron jobs are already scheduled.';
            $notice_class = 'notice-success';
            break;

        case 'test_system':
            $tests_passed = 0;
            $tests_total = 5;
            $test_results = [];

            // Test 1: Check if notification class exists
            if (class_exists('MLD_Simple_Notifications')) {
                $tests_passed++;
                $test_results['notification_class'] = true;
            } else {
                $test_results['notification_class'] = false;
            }

            // Test 2: Check if tracker class exists
            if (class_exists('MLD_Notification_Tracker')) {
                $tests_passed++;
                $test_results['tracker_class'] = true;
            } else {
                $test_results['tracker_class'] = false;
            }

            // Test 3: Check email template
            $template_path = MLD_PLUGIN_PATH . 'templates/emails/listing-updates.php';
            if (file_exists($template_path)) {
                $tests_passed++;
                $test_results['email_template'] = true;
            } else {
                $test_results['email_template'] = false;
            }

            // Test 4: Check database tables
            global $wpdb;
            $tracker_table = $wpdb->prefix . 'mld_notification_tracker';
            if ($wpdb->get_var("SHOW TABLES LIKE '$tracker_table'") === $tracker_table) {
                $tests_passed++;
                $test_results['database_table'] = true;
            } else {
                $test_results['database_table'] = false;
            }

            // Test 5: Check cron schedule (check for any notification cron job)
            $notification_crons = array(
                'mld_saved_search_instant',
                'mld_saved_search_hourly',
                'mld_saved_search_daily',
                'mld_simple_notifications_check' // Legacy hook
            );
            $cron_scheduled = false;
            foreach ($notification_crons as $cron_hook) {
                if (wp_next_scheduled($cron_hook)) {
                    $cron_scheduled = true;
                    break;
                }
            }
            if ($cron_scheduled) {
                $tests_passed++;
                $test_results['cron_schedule'] = true;
            } else {
                $test_results['cron_schedule'] = false;
            }

            $message = "System test completed: {$tests_passed}/{$tests_total} tests passed.";
            $notice_class = ($tests_passed === $tests_total) ? 'notice-success' : 'notice-warning';
            break;

        case 'cleanup_tracker':
            // Clean up old notification tracker records
            global $wpdb;
            $table = $wpdb->prefix . 'mld_notification_tracker';
            // Use current_time('mysql') instead of MySQL NOW() for WordPress timezone consistency
            $cutoff = current_time('mysql');
            $deleted = $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE sent_at < DATE_SUB(%s, INTERVAL 30 DAY)", $cutoff));

            $message = "Cleaned up {$deleted} old notification records.";
            $notice_class = 'notice-success';
            break;
    }
}

// Get system status
$notifications_enabled = get_option('mld_notifications_enabled', true);

// Check for any notification cron job
$notification_crons = array(
    'mld_saved_search_instant',
    'mld_saved_search_hourly',
    'mld_saved_search_daily',
    'mld_simple_notifications_check'
);
$next_scheduled = false;
foreach ($notification_crons as $cron_hook) {
    $scheduled = wp_next_scheduled($cron_hook);
    if ($scheduled && (!$next_scheduled || $scheduled < $next_scheduled)) {
        $next_scheduled = $scheduled;
    }
}

$last_run = get_option('mld_last_notification_run', '');

// Get database stats
global $wpdb;
$tracker_table = $wpdb->prefix . 'mld_notification_tracker';
$tracker_exists = $wpdb->get_var("SHOW TABLES LIKE '$tracker_table'") === $tracker_table;
$tracker_count = $tracker_exists ? $wpdb->get_var("SELECT COUNT(*) FROM {$tracker_table}") : 0;
$saved_searches_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}mld_saved_searches WHERE is_active = 1");
?>

<div class="wrap">
    <h1>MLS Listings Display - Cleanup Tool</h1>
    <p>System maintenance and troubleshooting for the simplified notification system (v5.0.0)</p>

    <?php if ($message): ?>
        <div class="notice <?php echo $notice_class; ?> is-dismissible">
            <p><?php echo esc_html($message); ?></p>
            <?php if (isset($test_results)): ?>
                <ul style="margin-left: 20px;">
                    <li>Notification Class: <?php echo $test_results['notification_class'] ? '✅ Loaded' : '❌ Missing'; ?></li>
                    <li>Tracker Class: <?php echo $test_results['tracker_class'] ? '✅ Loaded' : '❌ Missing'; ?></li>
                    <li>Email Template: <?php echo $test_results['email_template'] ? '✅ Found' : '❌ Missing'; ?></li>
                    <li>Database Table: <?php echo $test_results['database_table'] ? '✅ Exists' : '❌ Missing'; ?></li>
                    <li>Cron Schedule: <?php echo $test_results['cron_schedule'] ? '✅ Active' : '❌ Not scheduled'; ?></li>
                </ul>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="card">
        <h2>📊 System Status</h2>
        <table class="form-table">
            <tr>
                <th scope="row">Notification System</th>
                <td>
                    <?php if ($notifications_enabled): ?>
                        <span style="color: green; font-weight: bold;">✅ Enabled</span>
                    <?php else: ?>
                        <span style="color: red; font-weight: bold;">❌ Disabled</span>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th scope="row">Next Scheduled Check</th>
                <td>
                    <?php if ($next_scheduled): ?>
                        <?php echo date('Y-m-d H:i:s', $next_scheduled); ?>
                        (<?php echo human_time_diff($next_scheduled, current_time('timestamp')); ?> from now)
                    <?php else: ?>
                        <span style="color: orange;">⚠️ Not scheduled</span>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th scope="row">Last Run</th>
                <td><?php echo $last_run ?: 'Never'; ?></td>
            </tr>
            <tr>
                <th scope="row">Active Saved Searches</th>
                <td><?php echo number_format($saved_searches_count); ?></td>
            </tr>
            <tr>
                <th scope="row">Notification Records</th>
                <td><?php echo number_format($tracker_count); ?> records in tracker table</td>
            </tr>
        </table>
    </div>

    <div class="card">
        <h2>🧹 Cleanup Actions</h2>
        <p>Use these tools to maintain and troubleshoot the notification system:</p>

        <form method="post" style="display: inline-block; margin-right: 10px;">
            <?php wp_nonce_field('mld_cleanup_action', 'mld_cleanup_nonce'); ?>
            <input type="hidden" name="mld_cleanup_action" value="clear_cache">
            <button type="submit" class="button">
                🗑️ Clear Cache
            </button>
            <p class="description" style="margin-top: 5px;">Clears WordPress cache and MLD transients</p>
        </form>

        <form method="post" style="display: inline-block; margin-right: 10px;">
            <?php wp_nonce_field('mld_cleanup_action', 'mld_cleanup_nonce'); ?>
            <input type="hidden" name="mld_cleanup_action" value="reset_cron">
            <button type="submit" class="button">
                ⏰ Reset Cron Schedule
            </button>
            <p class="description" style="margin-top: 5px;">Reschedules the 30-minute notification check</p>
        </form>

        <form method="post" style="display: inline-block; margin-right: 10px;">
            <?php wp_nonce_field('mld_cleanup_action', 'mld_cleanup_nonce'); ?>
            <input type="hidden" name="mld_cleanup_action" value="test_system">
            <button type="submit" class="button button-primary">
                🧪 Test System
            </button>
            <p class="description" style="margin-top: 5px;">Runs diagnostic tests on the notification system</p>
        </form>

        <form method="post" style="display: inline-block;">
            <?php wp_nonce_field('mld_cleanup_action', 'mld_cleanup_nonce'); ?>
            <input type="hidden" name="mld_cleanup_action" value="cleanup_tracker">
            <button type="submit" class="button">
                📋 Clean Old Records
            </button>
            <p class="description" style="margin-top: 5px;">Removes notification records older than 30 days</p>
        </form>
    </div>

    <div class="card">
        <h2>📝 Quick Reference</h2>
        <h3>Simplified Notification System (v5.0.0)</h3>
        <ul>
            <li><strong>Single Template:</strong> One unified "Listing Updates" email template</li>
            <li><strong>Fixed Schedule:</strong> Runs every 30 minutes automatically</li>
            <li><strong>Simple Toggles:</strong> Users can only turn notifications on/off</li>
            <li><strong>Automatic Cleanup:</strong> Old records are removed after 7 days</li>
        </ul>

        <h3>Troubleshooting</h3>
        <ul>
            <li><strong>Notifications not sending?</strong> Click "Test System" to check all components</li>
            <li><strong>Cron not running?</strong> Click "Reset Cron Schedule" to reschedule</li>
            <li><strong>Database growing?</strong> Click "Clean Old Records" to remove old data</li>
            <li><strong>Changes not showing?</strong> Click "Clear Cache" to refresh</li>
        </ul>
    </div>

    <style>
    .card {
        background: #fff;
        border: 1px solid #ccd0d4;
        border-radius: 4px;
        padding: 20px;
        margin: 20px 0;
        max-width: 800px;
    }

    .card h2 {
        margin-top: 0;
        padding-bottom: 10px;
        border-bottom: 1px solid #eee;
    }

    .form-table th {
        width: 200px;
    }

    form {
        margin: 10px 0;
    }

    .description {
        color: #666;
        font-size: 13px;
        font-style: italic;
    }
    </style>
</div>