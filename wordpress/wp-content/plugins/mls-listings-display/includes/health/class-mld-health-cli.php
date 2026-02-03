<?php
/**
 * MLD Health CLI - WP-CLI Commands
 *
 * Provides command-line interface for health monitoring:
 *
 * wp health check         - Full health check with table output
 * wp health status        - Quick pass/fail for scripts
 * wp health check --component=mld - Check specific component
 * wp health check --format=json   - JSON output for automation
 * wp health history       - View health history
 * wp health test-alert    - Test email alerts
 *
 * @package MLS_Listings_Display
 * @since 6.58.0
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('WP_CLI') || !WP_CLI) {
    return;
}

/**
 * Manage system health monitoring for BMN Boston platform.
 */
class MLD_Health_CLI {

    /**
     * Run a full health check.
     *
     * ## OPTIONS
     *
     * [--component=<component>]
     * : Check only a specific component (mld, schools, snab)
     *
     * [--format=<format>]
     * : Output format (table, json, yaml, csv)
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     *   - yaml
     *   - csv
     * ---
     *
     * ## EXAMPLES
     *
     *     # Full health check
     *     wp health check
     *
     *     # Check only MLD
     *     wp health check --component=mld
     *
     *     # JSON output for automation
     *     wp health check --format=json
     *
     * @when after_wp_load
     */
    public function check($args, $assoc_args) {
        $monitor = MLD_Health_Monitor::get_instance();
        $results = $monitor->run_full_check('cli');

        $format = isset($assoc_args['format']) ? $assoc_args['format'] : 'table';
        $component = isset($assoc_args['component']) ? $assoc_args['component'] : null;

        // If specific component requested
        if ($component) {
            if (!isset($results['components'][$component])) {
                WP_CLI::error("Unknown component: {$component}. Valid options: mld, schools, snab");
            }
            $results = array(
                'status' => $results['components'][$component]['status'],
                'component' => $results['components'][$component],
            );
        }

        // Output based on format
        if ($format === 'json') {
            WP_CLI::line(wp_json_encode($results, JSON_PRETTY_PRINT));
        } elseif ($format === 'yaml') {
            WP_CLI::line($this->to_yaml($results));
        } else {
            $this->output_table($results);
        }

        // Exit code based on status
        if ($results['status'] === MLD_Health_Monitor::STATUS_UNHEALTHY) {
            WP_CLI::halt(2);
        } elseif ($results['status'] === MLD_Health_Monitor::STATUS_DEGRADED) {
            WP_CLI::halt(1);
        }
    }

    /**
     * Quick status check (for scripts and monitoring).
     *
     * Returns exit code:
     * - 0 = healthy
     * - 1 = degraded
     * - 2 = unhealthy
     *
     * ## OPTIONS
     *
     * [--quiet]
     * : Only return exit code, no output
     *
     * ## EXAMPLES
     *
     *     # Quick status check
     *     wp health status
     *
     *     # Use in script
     *     wp health status --quiet && echo "OK" || echo "PROBLEM"
     *
     * @when after_wp_load
     */
    public function status($args, $assoc_args) {
        $monitor = MLD_Health_Monitor::get_instance();
        $results = $monitor->run_quick_check();

        $quiet = isset($assoc_args['quiet']);

        if (!$quiet) {
            $emoji = MLD_Health_Monitor::get_status_emoji($results['status']);
            $status_upper = strtoupper($results['status']);

            WP_CLI::line("{$emoji} Status: {$status_upper}");
            WP_CLI::line("Response time: {$results['response_time_ms']}ms");

            foreach ($results['components'] as $name => $status) {
                $comp_emoji = MLD_Health_Monitor::get_status_emoji($status);
                WP_CLI::line("  {$comp_emoji} {$name}: {$status}");
            }
        }

        // Exit code based on status
        if ($results['status'] === MLD_Health_Monitor::STATUS_UNHEALTHY) {
            WP_CLI::halt(2);
        } elseif ($results['status'] === MLD_Health_Monitor::STATUS_DEGRADED) {
            WP_CLI::halt(1);
        }
    }

    /**
     * View health check history.
     *
     * ## OPTIONS
     *
     * [--days=<days>]
     * : Number of days of history to show
     * ---
     * default: 7
     * ---
     *
     * [--limit=<limit>]
     * : Maximum number of records
     * ---
     * default: 20
     * ---
     *
     * [--format=<format>]
     * : Output format
     * ---
     * default: table
     * ---
     *
     * ## EXAMPLES
     *
     *     # View last 7 days
     *     wp health history
     *
     *     # View last 30 days, 50 records max
     *     wp health history --days=30 --limit=50
     *
     * @when after_wp_load
     */
    public function history($args, $assoc_args) {
        $monitor = MLD_Health_Monitor::get_instance();

        $days = isset($assoc_args['days']) ? (int) $assoc_args['days'] : 7;
        $limit = isset($assoc_args['limit']) ? (int) $assoc_args['limit'] : 20;
        $format = isset($assoc_args['format']) ? $assoc_args['format'] : 'table';

        $history = $monitor->get_health_history($days, $limit);

        if (empty($history)) {
            WP_CLI::warning('No health history found. Run "wp health check" to generate history.');
            return;
        }

        if ($format === 'json') {
            WP_CLI::line(wp_json_encode($history, JSON_PRETTY_PRINT));
            return;
        }

        // Format for table output
        $table_data = array();
        foreach ($history as $record) {
            $table_data[] = array(
                'Time' => $record['check_time'],
                'Status' => $record['overall_status'],
                'MLD' => $record['mld_status'],
                'Schools' => $record['schools_status'],
                'SNAB' => $record['snab_status'],
                'Response' => $record['response_time_ms'] . 'ms',
                'Source' => $record['check_source'],
            );
        }

        WP_CLI\Utils\format_items($format, $table_data, array_keys($table_data[0]));
    }

    /**
     * Test alert system.
     *
     * ## OPTIONS
     *
     * [--email=<email>]
     * : Send test alert to specific email address
     *
     * ## EXAMPLES
     *
     *     # Test with default recipient
     *     wp health test-alert
     *
     *     # Test with specific email
     *     wp health test-alert --email=admin@example.com
     *
     * @when after_wp_load
     */
    public function test_alert($args, $assoc_args) {
        // Check if alerts class is available
        if (!class_exists('MLD_Health_Alerts')) {
            require_once MLD_PLUGIN_PATH . 'includes/health/class-mld-health-alerts.php';
        }

        $alerts = MLD_Health_Alerts::get_instance();

        $email = isset($assoc_args['email']) ? $assoc_args['email'] : null;

        WP_CLI::line('Sending test alert...');

        $result = $alerts->send_test_alert($email);

        if ($result) {
            WP_CLI::success('Test alert sent successfully.');
        } else {
            WP_CLI::error('Failed to send test alert. Check email settings.');
        }
    }

    /**
     * Cleanup old health history records.
     *
     * ## OPTIONS
     *
     * [--days=<days>]
     * : Keep records newer than this many days
     * ---
     * default: 30
     * ---
     *
     * [--yes]
     * : Skip confirmation
     *
     * ## EXAMPLES
     *
     *     # Cleanup records older than 30 days
     *     wp health cleanup
     *
     *     # Cleanup records older than 7 days
     *     wp health cleanup --days=7 --yes
     *
     * @when after_wp_load
     */
    public function cleanup($args, $assoc_args) {
        $days = isset($assoc_args['days']) ? (int) $assoc_args['days'] : 30;

        if (!isset($assoc_args['yes'])) {
            WP_CLI::confirm("Delete health history records older than {$days} days?");
        }

        $monitor = MLD_Health_Monitor::get_instance();
        $deleted = $monitor->cleanup_history($days);

        WP_CLI::success("Deleted {$deleted} old health history records.");
    }

    /**
     * Output results as table
     *
     * @param array $results Health check results
     */
    private function output_table($results) {
        // Overall status header
        $emoji = MLD_Health_Monitor::get_status_emoji($results['status']);
        $status_upper = strtoupper($results['status']);

        WP_CLI::line('');
        WP_CLI::line("{$emoji} Overall Status: {$status_upper}");
        WP_CLI::line("Timestamp: {$results['timestamp']}");
        WP_CLI::line("Response time: {$results['response_time_ms']}ms");
        WP_CLI::line('');

        // Components table
        WP_CLI::line('Components:');
        WP_CLI::line(str_repeat('-', 60));

        foreach ($results['components'] as $name => $component) {
            $comp_emoji = MLD_Health_Monitor::get_status_emoji($component['status']);
            $version = isset($component['version']) ? "v{$component['version']}" : '';

            WP_CLI::line(sprintf(
                "  %s %-25s %-12s %s",
                $comp_emoji,
                $component['name'],
                strtoupper($component['status']),
                $version
            ));

            // Show individual checks
            if (!empty($component['checks'])) {
                foreach ($component['checks'] as $check_name => $check) {
                    $check_emoji = MLD_Health_Monitor::get_status_emoji($check['status']);
                    $check_label = isset($check['name']) ? $check['name'] : $check_name;
                    WP_CLI::line(sprintf(
                        "      %s %s",
                        $check_emoji,
                        $check_label
                    ));
                }
            }
        }

        WP_CLI::line('');

        // Issues
        if (!empty($results['issues'])) {
            WP_CLI::line('Issues:');
            WP_CLI::line(str_repeat('-', 60));

            foreach ($results['issues'] as $issue) {
                $severity_emoji = ($issue['severity'] === 'critical') ? '✗' : '⚠';
                $component = isset($issue['component']) ? "[{$issue['component']}] " : '';
                WP_CLI::line("  {$severity_emoji} {$component}{$issue['message']}");
            }

            WP_CLI::line('');
        }

        // Summary
        WP_CLI::line('Summary:');
        WP_CLI::line(sprintf(
            "  Components: %d total, %d healthy",
            $results['summary']['total_components'],
            $results['summary']['healthy_components']
        ));
        WP_CLI::line(sprintf(
            "  Issues: %d total, %d critical",
            $results['summary']['total_issues'],
            $results['summary']['critical_issues']
        ));
        WP_CLI::line('');
    }

    /**
     * Convert array to YAML string
     *
     * @param array $data Data to convert
     * @param int $indent Indentation level
     * @return string YAML string
     */
    private function to_yaml($data, $indent = 0) {
        $yaml = '';
        $prefix = str_repeat('  ', $indent);

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                if (empty($value)) {
                    $yaml .= "{$prefix}{$key}: []\n";
                } elseif (array_keys($value) === range(0, count($value) - 1)) {
                    // Indexed array
                    $yaml .= "{$prefix}{$key}:\n";
                    foreach ($value as $item) {
                        if (is_array($item)) {
                            $yaml .= "{$prefix}-\n" . $this->to_yaml($item, $indent + 2);
                        } else {
                            $yaml .= "{$prefix}  - {$item}\n";
                        }
                    }
                } else {
                    // Associative array
                    $yaml .= "{$prefix}{$key}:\n" . $this->to_yaml($value, $indent + 1);
                }
            } elseif (is_bool($value)) {
                $yaml .= "{$prefix}{$key}: " . ($value ? 'true' : 'false') . "\n";
            } elseif (is_null($value)) {
                $yaml .= "{$prefix}{$key}: null\n";
            } else {
                $yaml .= "{$prefix}{$key}: {$value}\n";
            }
        }

        return $yaml;
    }
}

// Register WP-CLI commands
WP_CLI::add_command('health', 'MLD_Health_CLI');
