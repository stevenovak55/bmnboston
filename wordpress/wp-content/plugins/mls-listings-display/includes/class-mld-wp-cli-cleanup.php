<?php
/**
 * MLS Listings Display - WP-CLI Cleanup Commands
 *
 * @package MLS_Listings_Display
 * @since 4.5.46
 */

if (!defined('ABSPATH')) {
    exit;
}

// Only load if WP-CLI is available
if (!class_exists('WP_CLI')) {
    return;
}

// Include the cleanup class
require_once MLD_PLUGIN_PATH . 'includes/class-mld-plugin-cleanup.php';

/**
 * MLS Listings Display cleanup and diagnostic commands
 */
class MLD_WP_CLI_Cleanup extends WP_CLI_Command {

    /**
     * Run full cleanup and reinitialization of the plugin
     *
     * ## EXAMPLES
     *
     *     wp mld cleanup
     *
     * @synopsis
     */
    public function cleanup($args, $assoc_args) {
        WP_CLI::line('Starting MLS Listings Display cleanup...');

        $success = MLD_Plugin_Cleanup::run_full_cleanup();

        if ($success) {
            WP_CLI::success('Plugin cleanup completed successfully!');
        } else {
            WP_CLI::error('Plugin cleanup encountered errors. Check debug logs for details.');
        }
    }

    /**
     * Test the email template system
     *
     * ## EXAMPLES
     *
     *     wp mld test-email-system
     *
     * @synopsis
     */
    public function test_email_system($args, $assoc_args) {
        WP_CLI::line('Testing email template system...');

        $results = MLD_Plugin_Cleanup::test_email_system();

        $passed = count(array_filter($results));
        $total = count($results);

        WP_CLI::line("Test Results: {$passed}/{$total} tests passed");
        WP_CLI::line('');

        foreach ($results as $test => $passed_test) {
            $status = $passed_test ? '✓' : '✗';
            $color = $passed_test ? '%g' : '%r';
            WP_CLI::line(WP_CLI::colorize("{$color}{$status} " . ucwords(str_replace('_', ' ', $test)) . '%n'));
        }

        if ($passed === $total) {
            WP_CLI::success('All email system tests passed!');
        } else {
            WP_CLI::warning("Some tests failed. See details above.");
        }
    }

    /**
     * Show email template status
     *
     * ## EXAMPLES
     *
     *     wp mld template-status
     *
     * @synopsis
     */
    public function template_status($args, $assoc_args) {
        WP_CLI::line('Email Template Status:');
        WP_CLI::line('');

        $templates = MLD_Plugin_Cleanup::get_template_status();

        $table_data = [];
        foreach ($templates as $type => $status) {
            $table_data[] = [
                'Template' => ucwords(str_replace('_', ' ', $type)),
                'Exists' => $status['exists'] ? '✓' : '✗',
                'Subject' => $status['has_subject'] ? '✓' : '✗',
                'Body' => $status['has_body'] ? '✓' : '✗',
                'Updated' => $status['updated_at']
            ];
        }

        WP_CLI\Utils\format_items('table', $table_data, ['Template', 'Exists', 'Subject', 'Body', 'Updated']);
    }

    /**
     * Send a test notification for a saved search (Quick Test style)
     *
     * ## OPTIONS
     *
     * <search_id>
     * : The ID of the saved search to test
     *
     * ## EXAMPLES
     *
     *     wp mld test-notification 17
     *
     * @synopsis <search_id>
     */
    public function test_notification($args, $assoc_args) {
        $search_id = intval($args[0]);

        if (!$search_id) {
            WP_CLI::error('Please provide a valid search ID');
        }

        WP_CLI::line("Testing notification for saved search ID: {$search_id}");

        if (!class_exists('MLD_Saved_Search_Notifications')) {
            WP_CLI::error('MLD_Saved_Search_Notifications class not found');
        }

        $result = MLD_Saved_Search_Notifications::test_notification($search_id);

        if ($result) {
            WP_CLI::success('Test notification sent successfully!');
        } else {
            WP_CLI::error('Failed to send test notification. Check debug logs for details.');
        }
    }

    /**
     * Test live alert flow with custom templates (simulates real alert execution)
     *
     * ## OPTIONS
     *
     * <search_id>
     * : The ID of the saved search to test
     *
     * ## EXAMPLES
     *
     *     wp mld test-live-alerts 17
     *
     * @synopsis <search_id>
     */
    public function test_live_alerts($args, $assoc_args) {
        $search_id = intval($args[0]);

        if (!$search_id) {
            WP_CLI::error('Please provide a valid search ID');
        }

        WP_CLI::line("Testing LIVE ALERT FLOW for saved search ID: {$search_id}");
        WP_CLI::line("This simulates the exact execution path of real property alerts...");
        WP_CLI::line("");

        if (!class_exists('MLD_Saved_Search_Notifications')) {
            WP_CLI::error('MLD_Saved_Search_Notifications class not found');
        }

        $results = MLD_Saved_Search_Notifications::test_live_alert_flow($search_id);

        // Display results
        WP_CLI::line("Test Results:");
        WP_CLI::line("=============");

        $status_icon = $results['success'] ? '✓' : '✗';
        $status_color = $results['success'] ? '%g' : '%r';
        WP_CLI::line(WP_CLI::colorize("{$status_color}{$status_icon} Overall Success: " . ($results['success'] ? 'YES' : 'NO') . '%n'));

        WP_CLI::line(WP_CLI::colorize(($results['search_found'] ? '%g✓' : '%r✗') . " Search Found%n"));
        WP_CLI::line(WP_CLI::colorize(($results['custom_template_used'] ? '%g✓' : '%r✗') . " Custom Template Used%n"));
        WP_CLI::line(WP_CLI::colorize(($results['email_sent'] ? '%g✓' : '%r✗') . " Email Sent%n"));

        if (!empty($results['debug_info'])) {
            WP_CLI::line("");
            WP_CLI::line("Debug Info:");
            foreach ($results['debug_info'] as $key => $value) {
                WP_CLI::line("  {$key}: {$value}");
            }
        }

        if (!empty($results['error_message'])) {
            WP_CLI::line("");
            WP_CLI::error("Error: " . $results['error_message']);
        }

        WP_CLI::line("");
        if ($results['success'] && $results['custom_template_used']) {
            WP_CLI::success('Live alerts are working with custom templates! 🎉');
        } else if ($results['success'] && !$results['custom_template_used']) {
            WP_CLI::warning('Live alerts are working but using hardcoded templates. Check debug logs for why custom templates were not used.');
        } else {
            WP_CLI::error('Live alert test failed. Check debug logs for details.');
        }
    }

    /**
     * Check system information
     *
     * ## EXAMPLES
     *
     *     wp mld system-info
     *
     * @synopsis
     */
    public function system_info($args, $assoc_args) {
        WP_CLI::line('MLS Listings Display System Information:');
        WP_CLI::line('');

        $checks = [
            'Template Customizer Class' => class_exists('MLD_Template_Customizer'),
            'Template Variables Class' => class_exists('MLD_Template_Variables'),
            'Field Mapper Class' => class_exists('MLD_Field_Mapper'),
            'Saved Search Notifications Class' => class_exists('MLD_Saved_Search_Notifications'),
            'WordPress External Cache' => wp_using_ext_object_cache(),
            'PHP OPcache' => function_exists('opcache_get_status') && opcache_get_status(),
            'WordPress Debug Mode' => defined('WP_DEBUG') && WP_DEBUG,
            'WordPress Debug Logging' => defined('WP_DEBUG_LOG') && WP_DEBUG_LOG
        ];

        foreach ($checks as $check => $status) {
            $icon = $status ? '✓' : '✗';
            $color = $status ? '%g' : '%r';
            WP_CLI::line(WP_CLI::colorize("{$color}{$icon} {$check}%n"));
        }

        WP_CLI::line('');
        WP_CLI::line('Plugin Version: ' . (defined('MLD_VERSION') ? MLD_VERSION : 'Unknown'));
        WP_CLI::line('WordPress Version: ' . get_bloginfo('version'));
        WP_CLI::line('PHP Version: ' . phpversion());
    }
}

// Register the command
WP_CLI::add_command('mld', 'MLD_WP_CLI_Cleanup');