<?php
/**
 * Query Performance Tracker
 *
 * Intercepts and logs database queries for performance analysis
 *
 * @package Bridge_MLS_Extractor_Pro
 * @since 4.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class BME_Query_Tracker {

    private static $instance = null;
    private $wpdb;
    private $query_performance_table;
    private $slow_query_threshold = 1.0; // 1 second
    private $enabled = true;
    private $current_request_id;
    private $tracked_queries = [];

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
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->query_performance_table = $wpdb->prefix . 'bme_query_performance';
        $this->current_request_id = uniqid('req_', true);

        // Initialize hooks
        $this->init_hooks();
    }

    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        // Track queries during admin, AJAX, cron, or REST API
        if (is_admin() || wp_doing_ajax() || wp_doing_cron() || (defined('REST_REQUEST') && REST_REQUEST)) {
            // Use shutdown hook to capture all queries after execution
            add_action('shutdown', [$this, 'process_queries'], 1);

            // Enable query logging
            if (!defined('SAVEQUERIES')) {
                define('SAVEQUERIES', true);
            }
        }
    }

    /**
     * Process all queries captured by WordPress
     */
    public function process_queries() {
        if (!$this->enabled || empty($this->wpdb->queries)) {
            error_log('BME Query Tracker: Skipping - enabled=' . ($this->enabled ? 'yes' : 'no') . ', queries=' . (empty($this->wpdb->queries) ? 'empty' : count($this->wpdb->queries)));
            return;
        }

        $tracked_count = 0;
        $skipped_count = 0;

        foreach ($this->wpdb->queries as $query_data) {
            list($query, $execution_time, $stack_trace) = $query_data;

            // Only track BME-related queries
            if ($this->should_track_query($query)) {
                $this->log_query_performance($query, $execution_time, $stack_trace);
                $tracked_count++;
            } else {
                $skipped_count++;
            }
        }

        error_log("BME Query Tracker: Processed {$tracked_count} BME queries, skipped {$skipped_count} non-BME queries");
    }

    /**
     * Check if query should be tracked
     */
    private function should_track_query($query) {
        // Only track BME-related queries
        if (strpos($query, 'wp_bme_') === false &&
            strpos($query, 'bme_') === false) {
            return false;
        }

        // Don't track the performance logging itself
        if (strpos($query, 'bme_query_performance') !== false ||
            strpos($query, 'bme_performance_metrics') !== false ||
            strpos($query, 'bme_performance_alerts') !== false) {
            return false;
        }

        return true;
    }

    /**
     * Log query performance to database
     */
    private function log_query_performance($query, $execution_time, $stack_trace) {
        // Only log if over minimum threshold (1ms to capture most queries)
        // Note: Very fast queries (<1ms) are skipped to avoid excessive logging
        if ($execution_time < 0.001) {
            return;
        }

        // Parse query information
        $query_info = $this->parse_query($query);

        // Get additional metadata
        $metadata = $this->get_query_metadata($query);

        // Insert performance record
        $this->wpdb->insert(
            $this->query_performance_table,
            [
                'query_hash' => md5($query_info['normalized']),
                'query_text' => $this->sanitize_query($query),
                'query_type' => $query_info['type'],
                'execution_time' => (float)$execution_time,
                'rows_examined' => $metadata['rows_examined'],
                'rows_returned' => $metadata['rows_returned'],
                'table_names' => $query_info['tables'],
                'index_used' => $metadata['index_used'],
                'user_id' => get_current_user_id() ?: null,
                'page_url' => $this->get_current_url(),
                'request_id' => $this->current_request_id,
                'stack_trace' => $this->simplify_stack_trace($stack_trace),
                'created_at' => current_time('mysql')
            ],
            [
                '%s', '%s', '%s', '%f', '%d', '%d',
                '%s', '%s', '%d', '%s', '%s', '%s', '%s'
            ]
        );

        // Alert on slow queries
        if ($execution_time > $this->slow_query_threshold) {
            $this->alert_slow_query($query, $execution_time, $query_info);
        }
    }

    /**
     * Parse query to extract type and tables
     */
    private function parse_query($query) {
        $normalized = preg_replace('/\s+/', ' ', trim($query));

        // Determine query type
        $type = 'UNKNOWN';
        if (preg_match('/^(SELECT|INSERT|UPDATE|DELETE|REPLACE|ALTER|CREATE|DROP)/i', $normalized, $matches)) {
            $type = strtoupper($matches[1]);
        }

        // Extract table names
        $tables = [];
        if (preg_match_all('/(?:FROM|INTO|UPDATE|JOIN|TABLE)\s+`?(\w+)`?/i', $normalized, $matches)) {
            $tables = array_unique($matches[1]);
            // Filter out WordPress core tables
            $tables = array_filter($tables, function($table) {
                return strpos($table, 'bme') !== false || strpos($table, 'wp_bme') !== false;
            });
        }

        return [
            'type' => $type,
            'tables' => implode(',', $tables),
            'normalized' => $normalized
        ];
    }

    /**
     * Get query metadata using EXPLAIN
     */
    private function get_query_metadata($query) {
        $metadata = [
            'rows_examined' => null,
            'rows_returned' => null,
            'index_used' => null
        ];

        // Only EXPLAIN SELECT queries (safely)
        if (stripos(trim($query), 'SELECT') === 0) {
            try {
                // Disable query tracking for EXPLAIN
                $original_enabled = $this->enabled;
                $this->enabled = false;

                $explain = $this->wpdb->get_results('EXPLAIN ' . $query, ARRAY_A);

                $this->enabled = $original_enabled;

                if (!empty($explain)) {
                    $metadata['rows_examined'] = intval($explain[0]['rows'] ?? 0);
                    $metadata['index_used'] = $explain[0]['key'] ?? null;
                }
            } catch (Exception $e) {
                // Silent fail on EXPLAIN errors
            }
        }

        return $metadata;
    }

    /**
     * Sanitize query for storage
     */
    private function sanitize_query($query) {
        // Truncate very long queries
        if (strlen($query) > 5000) {
            return substr($query, 0, 5000) . '... [TRUNCATED]';
        }
        return $query;
    }

    /**
     * Get current URL
     */
    private function get_current_url() {
        if (wp_doing_ajax()) {
            return 'AJAX: ' . ($_POST['action'] ?? 'unknown');
        }
        if (wp_doing_cron()) {
            return 'CRON';
        }
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return 'REST: ' . ($_SERVER['REQUEST_URI'] ?? 'unknown');
        }
        if (is_admin()) {
            return 'ADMIN: ' . ($_GET['page'] ?? 'unknown');
        }
        return $_SERVER['REQUEST_URI'] ?? 'CLI';
    }

    /**
     * Simplify stack trace for storage
     */
    private function simplify_stack_trace($stack_trace) {
        // Extract BME plugin references from stack trace
        $lines = explode("\n", $stack_trace);
        $relevant = [];

        foreach ($lines as $line) {
            if (strpos($line, 'bridge-mls-extractor') !== false) {
                // Extract file and line number
                if (preg_match('/([^\/]+\.php)\((\d+)\)/', $line, $matches)) {
                    $relevant[] = $matches[1] . ':' . $matches[2];
                }
            }
        }

        return implode(' > ', array_slice($relevant, 0, 5)) ?: 'N/A';
    }

    /**
     * Alert on slow query
     */
    private function alert_slow_query($query, $execution_time, $query_info) {
        // Get performance monitor instance
        if (!class_exists('BME_Performance_Monitor')) {
            return;
        }

        $monitor = BME_Performance_Monitor::get_instance();

        // Determine severity
        $severity = 'warning';
        if ($execution_time > 5.0) {
            $severity = 'critical';
        } elseif ($execution_time > 2.0) {
            $severity = 'error';
        }

        $monitor->create_alert(
            'slow_query',
            $severity,
            sprintf('Slow %s query detected: %.3fs', $query_info['type'], $execution_time),
            [
                'query_type' => $query_info['type'],
                'execution_time' => $execution_time,
                'tables' => $query_info['tables'],
                'query_preview' => substr($query, 0, 200)
            ]
        );
    }

    /**
     * Enable/disable tracking
     */
    public function set_enabled($enabled) {
        $this->enabled = (bool)$enabled;
    }

    /**
     * Set slow query threshold
     */
    public function set_slow_query_threshold($seconds) {
        $this->slow_query_threshold = (float)$seconds;
    }

    /**
     * Get slow queries report
     */
    public function get_slow_queries($limit = 20, $min_execution_time = 1.0) {
        return $this->wpdb->get_results($this->wpdb->prepare("
            SELECT
                id,
                query_type,
                query_text,
                execution_time,
                rows_examined,
                table_names,
                index_used,
                page_url,
                created_at
            FROM {$this->query_performance_table}
            WHERE execution_time >= %f
            ORDER BY execution_time DESC
            LIMIT %d
        ", $min_execution_time, $limit));
    }

    /**
     * Get query statistics by type
     */
    public function get_query_stats_by_type($hours = 24) {
        return $this->wpdb->get_results($this->wpdb->prepare("
            SELECT
                query_type,
                COUNT(*) as total_count,
                AVG(execution_time) as avg_time,
                MAX(execution_time) as max_time,
                SUM(rows_examined) as total_rows_examined
            FROM {$this->query_performance_table}
            WHERE created_at > DATE_SUB(NOW(), INTERVAL %d HOUR)
            GROUP BY query_type
            ORDER BY total_count DESC
        ", $hours));
    }

    /**
     * Get queries by table
     */
    public function get_queries_by_table($hours = 24) {
        return $this->wpdb->get_results($this->wpdb->prepare("
            SELECT
                table_names,
                COUNT(*) as query_count,
                AVG(execution_time) as avg_time,
                MAX(execution_time) as max_time
            FROM {$this->query_performance_table}
            WHERE created_at > DATE_SUB(NOW(), INTERVAL %d HOUR)
                AND table_names IS NOT NULL
                AND table_names != ''
            GROUP BY table_names
            ORDER BY query_count DESC
            LIMIT 20
        ", $hours));
    }
}
