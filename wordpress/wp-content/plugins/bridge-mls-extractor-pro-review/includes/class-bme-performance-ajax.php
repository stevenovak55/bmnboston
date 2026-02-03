<?php
/**
 * BME Performance AJAX Handler
 * 
 * Handles AJAX requests for performance monitoring dashboard
 * Version: 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class BME_Performance_Ajax {
    
    /**
     * Instance
     */
    private static $instance = null;
    
    /**
     * Performance monitor instance
     */
    private $monitor;
    
    /**
     * Get instance
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
        $this->monitor = BME_Performance_Monitor::get_instance();
        $this->init_hooks();
    }
    
    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Performance metrics
        add_action('wp_ajax_bme_get_performance_metrics', [$this, 'get_performance_metrics']);
        add_action('wp_ajax_bme_get_realtime_metrics', [$this, 'get_realtime_metrics']);
        
        // Alerts
        add_action('wp_ajax_bme_resolve_alert', [$this, 'resolve_alert']);
        add_action('wp_ajax_bme_get_alerts', [$this, 'get_alerts']);
        
        // Charts data
        add_action('wp_ajax_bme_get_chart_data', [$this, 'get_chart_data']);
        
        // System status
        add_action('wp_ajax_bme_get_system_status', [$this, 'get_system_status']);
    }
    
    /**
     * Get performance metrics
     */
    public function get_performance_metrics() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'bme_performance_nonce')) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }
        
        $time_range = sanitize_text_field($_POST['time_range'] ?? '24hours');
        
        // Convert time range to period string
        $period = $this->get_period_from_range($time_range);
        
        // Get metrics summary
        $summary = $this->monitor->get_performance_summary($period);
        
        // Process metrics for dashboard
        $metrics = $this->process_metrics_summary($summary);
        
        // Get current system stats
        $system_stats = $this->get_current_system_stats();
        
        // Combine data
        $response_data = array_merge($metrics, $system_stats);
        
        wp_send_json_success($response_data);
    }
    
    /**
     * Get real-time metrics
     */
    public function get_realtime_metrics() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'bme_performance_nonce')) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }
        
        global $wpdb;
        
        // Get latest metrics (last 5 minutes)
        $table = $wpdb->prefix . 'bme_performance_metrics';
        $since = date('Y-m-d H:i:s', strtotime('-5 minutes'));
        
        $recent_metrics = $wpdb->get_results($wpdb->prepare("
            SELECT metric_type, metric_name, metric_value, created_at
            FROM $table
            WHERE created_at >= %s
            ORDER BY created_at DESC
            LIMIT 50
        ", $since));
        
        // Format for activity stream
        $activities = [];
        foreach ($recent_metrics as $metric) {
            $activities[] = [
                'time' => human_time_diff(strtotime($metric->created_at), current_time('timestamp')) . ' ago',
                'activity' => $this->format_activity_message($metric)
            ];
        }
        
        wp_send_json_success([
            'activities' => $activities,
            'timestamp' => current_time('timestamp')
        ]);
    }
    
    /**
     * Resolve alert
     */
    public function resolve_alert() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'bme_alert_nonce')) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }
        
        $alert_id = intval($_POST['alert_id'] ?? 0);
        
        if (!$alert_id) {
            wp_send_json_error('Invalid alert ID');
            return;
        }
        
        $result = $this->monitor->resolve_alert($alert_id);
        
        if ($result) {
            wp_send_json_success('Alert resolved');
        } else {
            wp_send_json_error('Failed to resolve alert');
        }
    }
    
    /**
     * Get alerts
     */
    public function get_alerts() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'bme_performance_nonce')) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }
        
        $limit = intval($_POST['limit'] ?? 10);
        $alerts = $this->monitor->get_recent_alerts($limit);
        
        wp_send_json_success($alerts);
    }
    
    /**
     * Get chart data
     */
    public function get_chart_data() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'bme_performance_nonce')) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }
        
        $chart_type = sanitize_text_field($_POST['chart_type'] ?? 'response_time');
        $time_range = sanitize_text_field($_POST['time_range'] ?? '24hours');
        
        global $wpdb;
        $table = $wpdb->prefix . 'bme_performance_metrics';
        
        // Get period
        $period = $this->get_period_from_range($time_range);
        $since = date('Y-m-d H:i:s', strtotime('-' . $period));
        
        $data = [];
        
        switch ($chart_type) {
            case 'response_time':
                $data = $this->get_response_time_chart_data($since);
                break;
                
            case 'resource_usage':
                $data = $this->get_resource_usage_chart_data($since);
                break;
                
            case 'api_performance':
                $data = $this->get_api_performance_chart_data($since);
                break;
                
            case 'query_performance':
                $data = $this->get_query_performance_chart_data($since);
                break;
        }
        
        wp_send_json_success($data);
    }
    
    /**
     * Get system status
     */
    public function get_system_status() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'bme_performance_nonce')) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }
        
        $status = [
            'php_version' => PHP_VERSION,
            'wp_version' => get_bloginfo('version'),
            'plugin_version' => defined('BME_VERSION') ? BME_VERSION : '1.0.0',
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'post_max_size' => ini_get('post_max_size'),
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
            'database_version' => $this->get_database_version(),
            'active_plugins' => count(get_option('active_plugins')),
            'theme' => wp_get_theme()->get('Name')
        ];
        
        wp_send_json_success($status);
    }
    
    /**
     * Convert time range to period string
     */
    private function get_period_from_range($range) {
        $periods = [
            '1hour' => '1 hour',
            '24hours' => '24 hours',
            '7days' => '7 days',
            '30days' => '30 days'
        ];
        
        return $periods[$range] ?? '24 hours';
    }
    
    /**
     * Process metrics summary
     */
    private function process_metrics_summary($summary) {
        $processed = [
            'page_load' => ['avg' => 0, 'min' => 0, 'max' => 0],
            'queries' => ['total' => 0, 'avg_time' => 0],
            'api_response' => ['avg' => 0, 'min' => 0, 'max' => 0],
            'errors' => ['count' => 0]
        ];
        
        foreach ($summary as $metric) {
            switch ($metric->metric_type) {
                case 'page_load':
                    $processed['page_load'] = [
                        'avg' => floatval($metric->avg_value),
                        'min' => floatval($metric->min_value),
                        'max' => floatval($metric->max_value),
                        'count' => intval($metric->count)
                    ];
                    break;
                    
                case 'query':
                    $processed['queries']['total'] += intval($metric->count);
                    $processed['queries']['avg_time'] = floatval($metric->avg_value);
                    break;
                    
                case 'api_call':
                    $processed['api_response'] = [
                        'avg' => floatval($metric->avg_value),
                        'min' => floatval($metric->min_value),
                        'max' => floatval($metric->max_value),
                        'count' => intval($metric->count)
                    ];
                    break;
                    
                case 'error':
                    $processed['errors']['count'] += intval($metric->count);
                    break;
            }
        }
        
        return $processed;
    }
    
    /**
     * Get current system stats
     */
    private function get_current_system_stats() {
        $memory_usage = memory_get_usage(true);
        $memory_limit = $this->get_memory_limit();
        
        return [
            'memory' => [
                'current' => $memory_usage,
                'limit' => $memory_limit,
                'percentage' => ($memory_usage / $memory_limit) * 100
            ],
            'cpu' => [
                'load' => sys_getloadavg()[0] ?? 0
            ]
        ];
    }
    
    /**
     * Get memory limit in bytes
     */
    private function get_memory_limit() {
        $memory_limit = ini_get('memory_limit');
        
        if (preg_match('/^(\d+)(.)$/', $memory_limit, $matches)) {
            if ($matches[2] == 'M') {
                return $matches[1] * 1024 * 1024;
            } else if ($matches[2] == 'K') {
                return $matches[1] * 1024;
            } else if ($matches[2] == 'G') {
                return $matches[1] * 1024 * 1024 * 1024;
            }
        }
        
        return $memory_limit;
    }
    
    /**
     * Format activity message
     */
    private function format_activity_message($metric) {
        $value = number_format($metric->metric_value, 2);
        
        switch ($metric->metric_type) {
            case 'page_load':
                return sprintf('Page loaded in %ss', $value);
                
            case 'api_call':
                return sprintf('API call to %s completed in %ss', $metric->metric_name, $value);
                
            case 'query':
                return sprintf('Database query executed in %ss', $value);
                
            case 'system':
                return sprintf('%s: %s', ucfirst(str_replace('_', ' ', $metric->metric_name)), $value);
                
            default:
                return sprintf('%s: %s', $metric->metric_name, $value);
        }
    }
    
    /**
     * Get response time chart data
     */
    private function get_response_time_chart_data($since) {
        global $wpdb;
        $table = $wpdb->prefix . 'bme_performance_metrics';
        
        $results = $wpdb->get_results($wpdb->prepare("
            SELECT 
                DATE_FORMAT(created_at, '%%Y-%%m-%%d %%H:00:00') as hour,
                AVG(metric_value) as avg_value
            FROM $table
            WHERE metric_type = 'page_load'
            AND created_at >= %s
            GROUP BY hour
            ORDER BY hour
        ", $since));
        
        $labels = [];
        $data = [];
        
        foreach ($results as $row) {
            $labels[] = date('H:i', strtotime($row->hour));
            $data[] = floatval($row->avg_value);
        }
        
        return [
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => 'Response Time (s)',
                    'data' => $data,
                    'borderColor' => '#007cba',
                    'tension' => 0.4
                ]
            ]
        ];
    }
    
    /**
     * Get resource usage chart data
     */
    private function get_resource_usage_chart_data($since) {
        global $wpdb;
        $table = $wpdb->prefix . 'bme_performance_metrics';
        
        $memory = $wpdb->get_var($wpdb->prepare("
            SELECT AVG(metric_value)
            FROM $table
            WHERE metric_type = 'system' 
            AND metric_name = 'memory_usage'
            AND created_at >= %s
        ", $since));
        
        $memory_limit = $this->get_memory_limit();
        $memory_percentage = ($memory / $memory_limit) * 100;
        
        return [
            'labels' => ['Memory Used', 'Memory Free'],
            'datasets' => [
                [
                    'data' => [$memory_percentage, 100 - $memory_percentage],
                    'backgroundColor' => ['#007cba', '#e0e0e0']
                ]
            ]
        ];
    }
    
    /**
     * Get API performance chart data
     */
    private function get_api_performance_chart_data($since) {
        global $wpdb;
        $table = $wpdb->prefix . 'bme_performance_metrics';
        
        $results = $wpdb->get_results($wpdb->prepare("
            SELECT 
                metric_name,
                AVG(metric_value) as avg_value,
                COUNT(*) as count
            FROM $table
            WHERE metric_type = 'api_call'
            AND created_at >= %s
            GROUP BY metric_name
            ORDER BY count DESC
            LIMIT 10
        ", $since));
        
        $labels = [];
        $data = [];
        
        foreach ($results as $row) {
            $labels[] = $row->metric_name;
            $data[] = floatval($row->avg_value);
        }
        
        return [
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => 'Avg Response Time (s)',
                    'data' => $data,
                    'backgroundColor' => '#007cba'
                ]
            ]
        ];
    }
    
    /**
     * Get query performance chart data
     */
    private function get_query_performance_chart_data($since) {
        global $wpdb;
        $table = $wpdb->prefix . 'bme_query_performance';
        
        $results = $wpdb->get_results($wpdb->prepare("
            SELECT 
                query_type,
                AVG(execution_time) as avg_time,
                COUNT(*) as count
            FROM $table
            WHERE created_at >= %s
            GROUP BY query_type
        ", $since));
        
        $labels = [];
        $data = [];
        
        foreach ($results as $row) {
            $labels[] = $row->query_type;
            $data[] = floatval($row->avg_time);
        }
        
        return [
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => 'Avg Execution Time (s)',
                    'data' => $data,
                    'backgroundColor' => ['#007cba', '#28a745', '#ffc107', '#dc3545']
                ]
            ]
        ];
    }
    
    /**
     * Get database version
     */
    private function get_database_version() {
        global $wpdb;
        return $wpdb->get_var("SELECT VERSION()");
    }
}

// Initialize
BME_Performance_Ajax::get_instance();