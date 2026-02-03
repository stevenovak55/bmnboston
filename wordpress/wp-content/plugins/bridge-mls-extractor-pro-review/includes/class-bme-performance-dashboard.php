<?php
/**
 * Performance Monitoring Dashboard
 *
 * Provides real-time performance metrics and cache statistics
 * for Phase 2 database optimization monitoring.
 *
 * @package Bridge_MLS_Extractor_Pro
 * @since 4.0.3
 * @version 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class BME_Performance_Dashboard {

    /**
     * @var wpdb WordPress database object
     */
    private $wpdb;

    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
    }

    /**
     * Get summary table health metrics
     *
     * @return array Health status of summary table
     */
    public function get_summary_table_health() {
        $summary_table = $this->wpdb->prefix . 'bme_listing_summary';
        $listings_table = $this->wpdb->prefix . 'bme_listings';

        $metrics = [
            'summary_count' => 0,
            'source_count' => 0,
            'sync_percentage' => 0,
            'last_update' => null,
            'staleness_minutes' => 0,
            'status' => 'unknown'
        ];

        // Get counts
        $summary_count = $this->wpdb->get_var("SELECT COUNT(*) FROM {$summary_table}");
        $source_count = $this->wpdb->get_var("SELECT COUNT(*) FROM {$listings_table}");

        $metrics['summary_count'] = (int) $summary_count;
        $metrics['source_count'] = (int) $source_count;

        // Calculate sync percentage
        if ($source_count > 0) {
            $metrics['sync_percentage'] = round(($summary_count / $source_count) * 100, 2);
        }

        // Get last update time
        $last_update = $this->wpdb->get_var(
            "SELECT MAX(modification_timestamp) FROM {$summary_table}"
        );

        if ($last_update) {
            $metrics['last_update'] = $last_update;
            $metrics['staleness_minutes'] = round(
                (time() - strtotime($last_update)) / 60,
                0
            );
        }

        // Determine status
        if ($metrics['sync_percentage'] >= 99) {
            $metrics['status'] = 'excellent';
        } elseif ($metrics['sync_percentage'] >= 95) {
            $metrics['status'] = 'good';
        } elseif ($metrics['sync_percentage'] >= 90) {
            $metrics['status'] = 'fair';
        } else {
            $metrics['status'] = 'needs_refresh';
        }

        return $metrics;
    }

    /**
     * Get cache performance metrics
     *
     * @return array Cache effectiveness statistics
     */
    public function get_cache_performance() {
        $cache_table = $this->wpdb->prefix . 'bme_search_cache';

        // Check if table exists
        if ($this->wpdb->get_var("SHOW TABLES LIKE '{$cache_table}'") !== $cache_table) {
            return [
                'status' => 'not_available',
                'total_entries' => 0,
                'total_hits' => 0,
                'hit_rate' => 0,
                'avg_hits_per_search' => 0
            ];
        }

        $stats = [
            'total_entries' => 0,
            'active_entries' => 0,
            'expired_entries' => 0,
            'total_hits' => 0,
            'hit_rate' => 0,
            'avg_hits_per_search' => 0,
            'status' => 'active'
        ];

        // Get basic counts
        $stats['total_entries'] = (int) $this->wpdb->get_var(
            "SELECT COUNT(*) FROM {$cache_table}"
        );

        $stats['active_entries'] = (int) $this->wpdb->get_var(
            "SELECT COUNT(*) FROM {$cache_table} WHERE expires_at > NOW()"
        );

        $stats['expired_entries'] = $stats['total_entries'] - $stats['active_entries'];

        // Get hit statistics
        $stats['total_hits'] = (int) $this->wpdb->get_var(
            "SELECT SUM(hit_count) FROM {$cache_table}"
        );

        if ($stats['total_entries'] > 0) {
            $stats['avg_hits_per_search'] = round(
                $stats['total_hits'] / $stats['total_entries'],
                2
            );

            // Calculate hit rate
            $stats['hit_rate'] = round(
                ($stats['total_hits'] / ($stats['total_hits'] + $stats['total_entries'])) * 100,
                2
            );
        }

        return $stats;
    }

    /**
     * Get most popular cached searches
     *
     * @param int $limit Number of results to return
     * @return array Top cached searches
     */
    public function get_popular_searches($limit = 10) {
        $cache_table = $this->wpdb->prefix . 'bme_search_cache';

        $results = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT
                    search_params,
                    result_count,
                    hit_count,
                    created_at,
                    expires_at
                 FROM {$cache_table}
                 WHERE hit_count > 0
                 ORDER BY hit_count DESC
                 LIMIT %d",
                $limit
            )
        );

        $popular = [];
        foreach ($results as $row) {
            $params = json_decode($row->search_params, true);
            $popular[] = [
                'search' => $this->format_search_params($params),
                'results' => (int) $row->result_count,
                'hits' => (int) $row->hit_count,
                'created' => $row->created_at,
                'expires' => $row->expires_at
            ];
        }

        return $popular;
    }

    /**
     * Get query performance trends
     *
     * @param int $days Number of days to analyze
     * @return array Performance trends
     */
    public function get_performance_trends($days = 7) {
        $perf_table = $this->wpdb->prefix . 'bme_query_performance';

        // Check if table exists
        if ($this->wpdb->get_var("SHOW TABLES LIKE '{$perf_table}'") !== $perf_table) {
            return [
                'status' => 'not_available',
                'daily_stats' => []
            ];
        }

        $results = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT
                    DATE(created_at) as date,
                    COUNT(*) as total_queries,
                    AVG(execution_time) as avg_time,
                    MAX(execution_time) as max_time,
                    MIN(execution_time) as min_time,
                    SUM(CASE WHEN used_cache = 1 THEN 1 ELSE 0 END) as cached_queries
                 FROM {$perf_table}
                 WHERE created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
                 GROUP BY DATE(created_at)
                 ORDER BY date DESC",
                $days
            )
        );

        $trends = [
            'status' => 'active',
            'daily_stats' => []
        ];

        foreach ($results as $row) {
            $trends['daily_stats'][] = [
                'date' => $row->date,
                'queries' => (int) $row->total_queries,
                'avg_time_ms' => round((float) $row->avg_time, 2),
                'max_time_ms' => round((float) $row->max_time, 2),
                'min_time_ms' => round((float) $row->min_time, 2),
                'cached' => (int) $row->cached_queries,
                'cache_rate' => round(((int) $row->cached_queries / (int) $row->total_queries) * 100, 2)
            ];
        }

        return $trends;
    }

    /**
     * Get database table sizes
     *
     * @return array Table size information
     */
    public function get_table_sizes() {
        $tables = [
            'listings' => $this->wpdb->prefix . 'bme_listings',
            'listing_summary' => $this->wpdb->prefix . 'bme_listing_summary',
            'search_cache' => $this->wpdb->prefix . 'bme_search_cache',
            'market_stats' => $this->wpdb->prefix . 'bme_market_stats',
            'query_performance' => $this->wpdb->prefix . 'bme_query_performance'
        ];

        $sizes = [];

        foreach ($tables as $key => $table_name) {
            $size_info = $this->wpdb->get_row(
                $this->wpdb->prepare(
                    "SELECT
                        table_rows,
                        ROUND(data_length / 1024 / 1024, 2) as data_mb,
                        ROUND(index_length / 1024 / 1024, 2) as index_mb,
                        ROUND((data_length + index_length) / 1024 / 1024, 2) as total_mb
                     FROM information_schema.TABLES
                     WHERE table_schema = %s AND table_name = %s",
                    DB_NAME,
                    $table_name
                )
            );

            if ($size_info) {
                $sizes[$key] = [
                    'table' => $table_name,
                    'rows' => (int) $size_info->table_rows,
                    'data_mb' => (float) $size_info->data_mb,
                    'index_mb' => (float) $size_info->index_mb,
                    'total_mb' => (float) $size_info->total_mb
                ];
            }
        }

        return $sizes;
    }

    /**
     * Get cron job status
     *
     * @return array Cron job information
     */
    public function get_cron_status() {
        // NOTE: bme_refresh_summary_hook removed in v4.0.17 - summary table written in real-time
        $cron_jobs = [
            'bme_cleanup_cache_hook' => [
                'name' => 'Cache Cleanup',
                'schedule' => 'daily',
                'status' => 'unknown'
            ]
        ];

        foreach ($cron_jobs as $hook => &$job) {
            $next_run = wp_next_scheduled($hook);
            if ($next_run) {
                $job['status'] = 'scheduled';
                $job['next_run'] = date('Y-m-d H:i:s', $next_run);
                $job['next_run_in'] = human_time_diff($next_run, current_time('timestamp'));
            } else {
                $job['status'] = 'not_scheduled';
                $job['next_run'] = null;
                $job['next_run_in'] = 'Never';
            }
        }

        return $cron_jobs;
    }

    /**
     * Get comprehensive dashboard data
     *
     * @return array All dashboard metrics
     */
    public function get_dashboard_data() {
        return [
            'summary_health' => $this->get_summary_table_health(),
            'cache_performance' => $this->get_cache_performance(),
            'popular_searches' => $this->get_popular_searches(5),
            'performance_trends' => $this->get_performance_trends(7),
            'table_sizes' => $this->get_table_sizes(),
            'cron_status' => $this->get_cron_status(),
            'generated_at' => current_time('mysql')
        ];
    }

    /**
     * Format search parameters for display
     *
     * @param array $params Search parameters
     * @return string Formatted search description
     */
    private function format_search_params($params) {
        $parts = [];

        if (!empty($params['city'])) {
            $parts[] = "City: {$params['city']}";
        }

        if (!empty($params['bedrooms'])) {
            $parts[] = "{$params['bedrooms']}+ beds";
        }

        if (!empty($params['bathrooms'])) {
            $parts[] = "{$params['bathrooms']}+ baths";
        }

        if (!empty($params['min_price']) || !empty($params['max_price'])) {
            $min = !empty($params['min_price']) ? '$' . number_format($params['min_price']) : '';
            $max = !empty($params['max_price']) ? '$' . number_format($params['max_price']) : '';
            if ($min && $max) {
                $parts[] = "Price: {$min}-{$max}";
            } elseif ($min) {
                $parts[] = "Price: {$min}+";
            } elseif ($max) {
                $parts[] = "Price: up to {$max}";
            }
        }

        if (!empty($params['property_type'])) {
            $parts[] = $params['property_type'];
        }

        return !empty($parts) ? implode(', ', $parts) : 'All properties';
    }

    /**
     * Generate performance report
     *
     * @return string Formatted text report
     */
    public function generate_text_report() {
        $data = $this->get_dashboard_data();

        $report = "=== BRIDGE MLS EXTRACTOR PRO - PERFORMANCE REPORT ===\n";
        $report .= "Generated: {$data['generated_at']}\n\n";

        // Summary Health
        $report .= "SUMMARY TABLE HEALTH\n";
        $report .= str_repeat('-', 50) . "\n";
        $report .= sprintf("Records in Summary: %d\n", $data['summary_health']['summary_count']);
        $report .= sprintf("Records in Source:  %d\n", $data['summary_health']['source_count']);
        $report .= sprintf("Sync Percentage:    %.2f%%\n", $data['summary_health']['sync_percentage']);
        $report .= sprintf("Status:             %s\n", strtoupper($data['summary_health']['status']));
        $report .= sprintf("Last Update:        %s\n", $data['summary_health']['last_update'] ?? 'Never');
        $report .= sprintf("Staleness:          %d minutes\n\n", $data['summary_health']['staleness_minutes']);

        // Cache Performance
        $report .= "CACHE PERFORMANCE\n";
        $report .= str_repeat('-', 50) . "\n";
        $report .= sprintf("Total Entries:      %d\n", $data['cache_performance']['total_entries']);
        $report .= sprintf("Active Entries:     %d\n", $data['cache_performance']['active_entries']);
        $report .= sprintf("Total Hits:         %d\n", $data['cache_performance']['total_hits']);
        $report .= sprintf("Hit Rate:           %.2f%%\n", $data['cache_performance']['hit_rate']);
        $report .= sprintf("Avg Hits/Search:    %.2f\n\n", $data['cache_performance']['avg_hits_per_search']);

        // Cron Status
        $report .= "CRON JOB STATUS\n";
        $report .= str_repeat('-', 50) . "\n";
        foreach ($data['cron_status'] as $job) {
            $report .= sprintf("%-25s Status: %-12s Next: %s\n",
                $job['name'],
                strtoupper($job['status']),
                $job['next_run'] ?? 'Not scheduled'
            );
        }

        $report .= "\n" . str_repeat('=', 50) . "\n";

        return $report;
    }
}
