<?php
/**
 * Performance Monitoring System for MLS Listings Display
 *
 * Tracks query performance, memory usage, and provides detailed metrics
 * for optimization efforts.
 *
 * @package MLS_Listings_Display
 * @since 4.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Performance_Monitor {

    /**
     * Singleton instance
     */
    private static $instance = null;

    /**
     * Performance metrics storage
     */
    private $metrics = [];

    /**
     * Query tracking
     */
    private $queries = [];

    /**
     * Timer storage
     */
    private $timers = [];

    /**
     * Memory baseline
     */
    private $memory_baseline;

    /**
     * Constructor
     */
    private function __construct() {
        $this->memory_baseline = memory_get_usage(true);

        // Hook into WordPress for query monitoring
        if (defined('MLD_PERFORMANCE_MONITORING') && MLD_PERFORMANCE_MONITORING) {
            add_filter('query', [$this, 'log_query']);
            add_action('shutdown', [$this, 'output_performance_report']);
        }
    }

    /**
     * Get singleton instance
     */
    public static function getInstance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Start a timer
     *
     * @param string $name Timer name
     * @param array $metadata Optional metadata
     */
    public static function startTimer($name, $metadata = []) {
        $instance = self::getInstance();
        $instance->timers[$name] = [
            'start' => microtime(true),
            'metadata' => $metadata
        ];
    }

    /**
     * End a timer and record the metric
     *
     * @param string $name Timer name
     * @return float Duration in milliseconds
     */
    public static function endTimer($name) {
        $instance = self::getInstance();

        if (!isset($instance->timers[$name])) {
            MLD_Logger::warning("Timer '$name' was not started");
            return 0;
        }

        $duration = (microtime(true) - $instance->timers[$name]['start']) * 1000;

        $instance->metrics[] = [
            'type' => 'timer',
            'name' => $name,
            'duration' => $duration,
            'memory' => memory_get_usage(true) - $instance->memory_baseline,
            'metadata' => $instance->timers[$name]['metadata'],
            'timestamp' => current_time('mysql')
        ];

        unset($instance->timers[$name]);

        // Log slow operations
        if ($duration > 1000) { // More than 1 second
            MLD_Logger::warning("Slow operation detected: $name took {$duration}ms");
        }

        return $duration;
    }

    /**
     * Record a database query
     *
     * @param string $query SQL query
     * @return string Unchanged query
     */
    public function log_query($query) {
        if (strpos($query, 'mld_') !== false || strpos($query, 'bme_') !== false) {
            $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5);
            $caller = isset($backtrace[4]['class']) ? $backtrace[4]['class'] : 'Unknown';

            $this->queries[] = [
                'query' => $query,
                'caller' => $caller,
                'time' => microtime(true)
            ];
        }
        return $query;
    }

    /**
     * Record a custom metric
     *
     * @param string $name Metric name
     * @param mixed $value Metric value
     * @param string $type Metric type (counter, gauge, etc.)
     */
    public static function recordMetric($name, $value, $type = 'gauge') {
        $instance = self::getInstance();

        $instance->metrics[] = [
            'type' => $type,
            'name' => $name,
            'value' => $value,
            'timestamp' => current_time('mysql')
        ];
    }

    /**
     * Get current memory usage
     *
     * @return array Memory usage details
     */
    public static function getMemoryUsage() {
        $instance = self::getInstance();

        return [
            'current' => memory_get_usage(true),
            'peak' => memory_get_peak_usage(true),
            'baseline' => $instance->memory_baseline,
            'increase' => memory_get_usage(true) - $instance->memory_baseline
        ];
    }

    /**
     * Get performance summary
     *
     * @return array Performance metrics summary
     */
    public static function getSummary() {
        $instance = self::getInstance();

        $summary = [
            'total_queries' => count($instance->queries),
            'total_metrics' => count($instance->metrics),
            'memory' => self::getMemoryUsage(),
            'timers' => [],
            'slow_operations' => []
        ];

        // Aggregate timer metrics
        foreach ($instance->metrics as $metric) {
            if ($metric['type'] === 'timer') {
                if (!isset($summary['timers'][$metric['name']])) {
                    $summary['timers'][$metric['name']] = [
                        'count' => 0,
                        'total' => 0,
                        'avg' => 0,
                        'max' => 0,
                        'min' => PHP_INT_MAX
                    ];
                }

                $summary['timers'][$metric['name']]['count']++;
                $summary['timers'][$metric['name']]['total'] += $metric['duration'];
                $summary['timers'][$metric['name']]['max'] = max($summary['timers'][$metric['name']]['max'], $metric['duration']);
                $summary['timers'][$metric['name']]['min'] = min($summary['timers'][$metric['name']]['min'], $metric['duration']);

                if ($metric['duration'] > 500) { // Operations over 500ms
                    $summary['slow_operations'][] = [
                        'name' => $metric['name'],
                        'duration' => $metric['duration'],
                        'metadata' => $metric['metadata']
                    ];
                }
            }
        }

        // Calculate averages
        foreach ($summary['timers'] as $name => &$timer) {
            $timer['avg'] = $timer['total'] / $timer['count'];
        }

        return $summary;
    }

    /**
     * Output performance report on shutdown
     */
    public function output_performance_report() {
        if (!defined('MLD_PERFORMANCE_MONITORING') || !MLD_PERFORMANCE_MONITORING) {
            return;
        }

        $summary = self::getSummary();

        // Log to file if in debug mode
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $report = "=== MLD Performance Report ===\n";
            $report .= "URL: " . $_SERVER['REQUEST_URI'] . "\n";
            $report .= "Queries: " . $summary['total_queries'] . "\n";
            $report .= "Memory Used: " . number_format($summary['memory']['increase'] / 1048576, 2) . " MB\n";
            $report .= "Peak Memory: " . number_format($summary['memory']['peak'] / 1048576, 2) . " MB\n";

            if (!empty($summary['slow_operations'])) {
                $report .= "\nSlow Operations:\n";
                foreach ($summary['slow_operations'] as $op) {
                    $report .= "  - {$op['name']}: {$op['duration']}ms\n";
                }
            }

            if (!empty($summary['timers'])) {
                $report .= "\nTimer Summary:\n";
                foreach ($summary['timers'] as $name => $timer) {
                    $report .= "  - $name: {$timer['count']} calls, avg: " . number_format($timer['avg'], 2) . "ms\n";
                }
            }

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log($report);
            }
        }

        // Store metrics for admin dashboard
        if (is_admin_bar_showing()) {
            set_transient('mld_last_performance_report', $summary, 300);
        }
    }

    /**
     * Clear all metrics
     */
    public static function reset() {
        $instance = self::getInstance();
        $instance->metrics = [];
        $instance->queries = [];
        $instance->timers = [];
        $instance->memory_baseline = memory_get_usage(true);
    }
}