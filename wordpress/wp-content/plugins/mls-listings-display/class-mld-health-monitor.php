<?php
/**
 * MLD Health Monitor - Core monitoring logic
 *
 * Provides unified health checking across all plugins:
 * - MLS Listings Display (MLD)
 * - BMN Schools
 * - SN Appointment Booking (SNAB)
 *
 * Used by: WP-CLI commands, Admin Dashboard, External Monitoring, Cron Alerts
 *
 * @package MLS_Listings_Display
 * @since 6.58.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Health_Monitor {

    /**
     * Singleton instance
     */
    private static $instance = null;

    /**
     * Health status constants
     */
    const STATUS_HEALTHY = 'healthy';
    const STATUS_DEGRADED = 'degraded';
    const STATUS_UNHEALTHY = 'unhealthy';

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
     * Run a full health check across all components
     *
     * @param string $source Where the check was triggered from (cli, cron, external, admin)
     * @return array Health check results
     */
    public function run_full_check($source = 'admin') {
        $start_time = microtime(true);

        $results = array(
            'status' => self::STATUS_HEALTHY,
            'timestamp' => current_time('mysql'),
            'timestamp_utc' => gmdate('Y-m-d H:i:s'),
            'response_time_ms' => 0,
            'source' => $source,
            'components' => array(),
            'issues' => array(),
            'summary' => array(),
        );

        // Check each component
        $results['components']['mld'] = $this->check_mld();
        $results['components']['schools'] = $this->check_schools();
        $results['components']['snab'] = $this->check_snab();

        // Aggregate overall status
        $results['status'] = $this->aggregate_status($results['components']);

        // Collect all issues
        foreach ($results['components'] as $component => $data) {
            if (!empty($data['issues'])) {
                foreach ($data['issues'] as $issue) {
                    $issue['component'] = $component;
                    $results['issues'][] = $issue;
                }
            }
        }

        // Generate summary
        $results['summary'] = array(
            'total_components' => count($results['components']),
            'healthy_components' => count(array_filter($results['components'], function($c) {
                return $c['status'] === self::STATUS_HEALTHY;
            })),
            'total_issues' => count($results['issues']),
            'critical_issues' => count(array_filter($results['issues'], function($i) {
                return $i['severity'] === 'critical';
            })),
        );

        // Calculate response time
        $results['response_time_ms'] = round((microtime(true) - $start_time) * 1000);

        // Log to history
        $this->log_health_check($results);

        return $results;
    }

    /**
     * Run a quick status check (minimal checks for external monitoring)
     *
     * @return array Simplified health status
     */
    public function run_quick_check() {
        $start_time = microtime(true);

        $status = self::STATUS_HEALTHY;
        $components = array();

        // Quick MLD check - just verify API responds
        $mld_ok = $this->quick_api_check('/mld-mobile/v1/properties?per_page=1');
        $components['mld'] = $mld_ok ? 'ok' : 'error';

        // Quick Schools check
        $schools_ok = $this->quick_api_check('/bmn-schools/v1/health');
        $components['schools'] = $schools_ok ? 'ok' : 'error';

        // Quick SNAB check
        $snab_ok = $this->quick_api_check('/snab/v1/appointment-types');
        $components['snab'] = $snab_ok ? 'ok' : 'error';

        // Determine overall status
        if (!$mld_ok || !$schools_ok || !$snab_ok) {
            $status = (!$mld_ok) ? self::STATUS_UNHEALTHY : self::STATUS_DEGRADED;
        }

        return array(
            'status' => $status,
            'timestamp' => current_time('c'),
            'response_time_ms' => round((microtime(true) - $start_time) * 1000),
            'components' => $components,
        );
    }

    /**
     * Quick API endpoint check
     *
     * @param string $endpoint REST API endpoint path
     * @return bool True if endpoint responds successfully
     */
    private function quick_api_check($endpoint) {
        $url = rest_url($endpoint);

        $response = wp_remote_get($url, array(
            'timeout' => 5,
            'sslverify' => false,
        ));

        if (is_wp_error($response)) {
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        return ($code >= 200 && $code < 400);
    }

    /**
     * Check MLS Listings Display health
     *
     * @return array Component health data
     */
    private function check_mld() {
        global $wpdb;

        $result = array(
            'status' => self::STATUS_HEALTHY,
            'name' => 'MLS Listings Display',
            'version' => defined('MLD_VERSION') ? MLD_VERSION : 'Unknown',
            'checks' => array(),
            'issues' => array(),
        );

        // Check 1: Database tables exist
        // v6.68.15: Added push notification and device token tables
        $tables = array(
            'bme_listings' => 'Listings',
            'bme_listing_summary' => 'Summary',
            'mld_saved_searches' => 'Saved Searches',
            'mld_device_tokens' => 'Device Tokens',
            'mld_push_notification_log' => 'Push Log',
        );

        foreach ($tables as $table => $label) {
            $table_name = $wpdb->prefix . $table;
            $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name));

            $result['checks'][$table] = array(
                'name' => $label,
                'status' => $exists ? 'ok' : 'error',
            );

            if (!$exists) {
                $result['issues'][] = array(
                    'severity' => 'critical',
                    'message' => "Database table {$label} is missing",
                );
                $result['status'] = self::STATUS_UNHEALTHY;
            }
        }

        // Check 2: Summary table sync
        $listings_count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}bme_listings WHERE standard_status = 'Active'"
        );
        $summary_count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}bme_listing_summary WHERE standard_status = 'Active'"
        );

        $sync_diff = abs($listings_count - $summary_count);
        $sync_ok = ($sync_diff < 100); // Allow small difference

        $result['checks']['summary_sync'] = array(
            'name' => 'Summary Table Sync',
            'status' => $sync_ok ? 'ok' : 'warning',
            'details' => array(
                'listings' => $listings_count,
                'summary' => $summary_count,
                'difference' => $sync_diff,
            ),
        );

        if (!$sync_ok) {
            $result['issues'][] = array(
                'severity' => 'warning',
                'message' => "Summary table out of sync by {$sync_diff} records",
            );
            if ($result['status'] === self::STATUS_HEALTHY) {
                $result['status'] = self::STATUS_DEGRADED;
            }
        }

        // Check 3: Cron jobs scheduled
        // v6.68.15: Added fifteen_min cron job (key for saved search alerts)
        $cron_hooks = array(
            'mld_saved_search_instant' => 'Instant Alerts',
            'mld_saved_search_fifteen_min' => '15-min Alerts',
            'mld_saved_search_hourly' => 'Hourly Alerts',
            'mld_saved_search_daily' => 'Daily Alerts',
        );

        foreach ($cron_hooks as $hook => $label) {
            $next = wp_next_scheduled($hook);
            $overdue = $next && ($next < (time() - 300)); // More than 5 min overdue

            $result['checks'][$hook] = array(
                'name' => $label,
                'status' => $next ? ($overdue ? 'warning' : 'ok') : 'error',
                'next_run' => $next ? wp_date('Y-m-d H:i:s', $next) : null,
            );

            if (!$next) {
                $result['issues'][] = array(
                    'severity' => 'warning',
                    'message' => "Cron job '{$label}' is not scheduled",
                );
                if ($result['status'] === self::STATUS_HEALTHY) {
                    $result['status'] = self::STATUS_DEGRADED;
                }
            }
        }

        // Check 4: API responds
        $api_ok = $this->quick_api_check('/mld-mobile/v1/properties?per_page=1');
        $result['checks']['api'] = array(
            'name' => 'REST API',
            'status' => $api_ok ? 'ok' : 'error',
        );

        if (!$api_ok) {
            $result['issues'][] = array(
                'severity' => 'critical',
                'message' => 'MLD REST API is not responding',
            );
            $result['status'] = self::STATUS_UNHEALTHY;
        }

        return $result;
    }

    /**
     * Check BMN Schools health
     *
     * @return array Component health data
     */
    private function check_schools() {
        global $wpdb;

        $result = array(
            'status' => self::STATUS_HEALTHY,
            'name' => 'BMN Schools',
            'version' => defined('BMN_SCHOOLS_VERSION') ? BMN_SCHOOLS_VERSION : 'Unknown',
            'checks' => array(),
            'issues' => array(),
        );

        // Check if plugin is active
        if (!function_exists('bmn_schools') && !class_exists('BMN_Schools')) {
            $result['status'] = self::STATUS_DEGRADED;
            $result['checks']['plugin'] = array(
                'name' => 'Plugin Active',
                'status' => 'warning',
            );
            $result['issues'][] = array(
                'severity' => 'warning',
                'message' => 'BMN Schools plugin not detected',
            );
            return $result;
        }

        // Check 1: Database tables
        $tables = array(
            'bmn_schools' => 'Schools',
            'bmn_school_rankings' => 'Rankings',
        );

        foreach ($tables as $table => $label) {
            $table_name = $wpdb->prefix . $table;
            $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name));

            $result['checks'][$table] = array(
                'name' => $label,
                'status' => $exists ? 'ok' : 'error',
            );

            if (!$exists) {
                $result['issues'][] = array(
                    'severity' => 'critical',
                    'message' => "Schools table {$label} is missing",
                );
                $result['status'] = self::STATUS_UNHEALTHY;
            }
        }

        // Check 2: School count reasonable
        $school_count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}bmn_schools"
        );

        $result['checks']['school_count'] = array(
            'name' => 'School Count',
            'status' => ($school_count > 100) ? 'ok' : 'warning',
            'value' => $school_count,
        );

        if ($school_count < 100) {
            $result['issues'][] = array(
                'severity' => 'warning',
                'message' => "Only {$school_count} schools in database (expected 1000+)",
            );
            if ($result['status'] === self::STATUS_HEALTHY) {
                $result['status'] = self::STATUS_DEGRADED;
            }
        }

        // Check 3: API responds
        $api_ok = $this->quick_api_check('/bmn-schools/v1/health');
        $result['checks']['api'] = array(
            'name' => 'REST API',
            'status' => $api_ok ? 'ok' : 'error',
        );

        if (!$api_ok) {
            $result['issues'][] = array(
                'severity' => 'critical',
                'message' => 'Schools REST API is not responding',
            );
            $result['status'] = self::STATUS_UNHEALTHY;
        }

        return $result;
    }

    /**
     * Check SN Appointment Booking health
     *
     * @return array Component health data
     */
    private function check_snab() {
        global $wpdb;

        $result = array(
            'status' => self::STATUS_HEALTHY,
            'name' => 'SN Appointment Booking',
            'version' => defined('SNAB_VERSION') ? SNAB_VERSION : 'Unknown',
            'checks' => array(),
            'issues' => array(),
        );

        // Check if plugin is active
        if (!defined('SNAB_VERSION')) {
            $result['status'] = self::STATUS_DEGRADED;
            $result['checks']['plugin'] = array(
                'name' => 'Plugin Active',
                'status' => 'warning',
            );
            $result['issues'][] = array(
                'severity' => 'warning',
                'message' => 'SNAB plugin not detected',
            );
            return $result;
        }

        // Check 1: Database tables
        $tables = array(
            'snab_appointments' => 'Appointments',
            'snab_staff' => 'Staff',
            'snab_appointment_types' => 'Types',
        );

        foreach ($tables as $table => $label) {
            $table_name = $wpdb->prefix . $table;
            $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name));

            $result['checks'][$table] = array(
                'name' => $label,
                'status' => $exists ? 'ok' : 'error',
            );

            if (!$exists) {
                $result['issues'][] = array(
                    'severity' => 'critical',
                    'message' => "SNAB table {$label} is missing",
                );
                $result['status'] = self::STATUS_UNHEALTHY;
            }
        }

        // Check 2: Staff exists
        $staff_count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}snab_staff WHERE status = 'active'"
        );

        $result['checks']['staff_count'] = array(
            'name' => 'Active Staff',
            'status' => ($staff_count > 0) ? 'ok' : 'warning',
            'value' => $staff_count,
        );

        if ($staff_count === 0) {
            $result['issues'][] = array(
                'severity' => 'warning',
                'message' => 'No active staff members configured',
            );
            if ($result['status'] === self::STATUS_HEALTHY) {
                $result['status'] = self::STATUS_DEGRADED;
            }
        }

        // Check 3: API responds
        $api_ok = $this->quick_api_check('/snab/v1/appointment-types');
        $result['checks']['api'] = array(
            'name' => 'REST API',
            'status' => $api_ok ? 'ok' : 'error',
        );

        if (!$api_ok) {
            $result['issues'][] = array(
                'severity' => 'critical',
                'message' => 'SNAB REST API is not responding',
            );
            $result['status'] = self::STATUS_UNHEALTHY;
        }

        return $result;
    }

    /**
     * Aggregate status from components
     *
     * @param array $components Component check results
     * @return string Overall status
     */
    private function aggregate_status($components) {
        $has_unhealthy = false;
        $has_degraded = false;

        foreach ($components as $component) {
            if ($component['status'] === self::STATUS_UNHEALTHY) {
                $has_unhealthy = true;
            } elseif ($component['status'] === self::STATUS_DEGRADED) {
                $has_degraded = true;
            }
        }

        if ($has_unhealthy) {
            return self::STATUS_UNHEALTHY;
        } elseif ($has_degraded) {
            return self::STATUS_DEGRADED;
        }

        return self::STATUS_HEALTHY;
    }

    /**
     * Log health check to database
     *
     * @param array $results Health check results
     */
    private function log_health_check($results) {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_health_history';

        // Check if table exists
        if (!$wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table))) {
            return; // Table doesn't exist yet
        }

        $wpdb->insert($table, array(
            'check_time' => current_time('mysql'),
            'overall_status' => $results['status'],
            'mld_status' => $results['components']['mld']['status'],
            'schools_status' => $results['components']['schools']['status'],
            'snab_status' => $results['components']['snab']['status'],
            'response_time_ms' => $results['response_time_ms'],
            'check_source' => $results['source'],
            'issues_json' => wp_json_encode($results['issues']),
        ), array('%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s'));
    }

    /**
     * Get health history
     *
     * @param int $days Number of days of history
     * @param int $limit Maximum records
     * @return array Health history records
     */
    public function get_health_history($days = 7, $limit = 100) {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_health_history';

        // Check if table exists
        if (!$wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table))) {
            return array();
        }

        $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE check_time > %s
             ORDER BY check_time DESC
             LIMIT %d",
            $cutoff,
            $limit
        ), ARRAY_A);
    }

    /**
     * Cleanup old health history records
     *
     * @param int $days Keep records newer than this many days
     * @return int Number of records deleted
     */
    public function cleanup_history($days = 30) {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_health_history';

        // Check if table exists
        if (!$wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table))) {
            return 0;
        }

        $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        return $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE check_time < %s",
            $cutoff
        ));
    }

    /**
     * Get status badge color for display
     *
     * @param string $status Health status
     * @return string Color code
     */
    public static function get_status_color($status) {
        switch ($status) {
            case self::STATUS_HEALTHY:
                return '#46b450';
            case self::STATUS_DEGRADED:
                return '#ffb900';
            case self::STATUS_UNHEALTHY:
                return '#dc3232';
            default:
                return '#666';
        }
    }

    /**
     * Get status emoji for CLI output
     *
     * @param string $status Health status
     * @return string Emoji
     */
    public static function get_status_emoji($status) {
        switch ($status) {
            case self::STATUS_HEALTHY:
            case 'ok':
                return '✓';
            case self::STATUS_DEGRADED:
            case 'warning':
                return '⚠';
            case self::STATUS_UNHEALTHY:
            case 'error':
                return '✗';
            default:
                return '?';
        }
    }
}
