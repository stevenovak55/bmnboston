<?php
/**
 * Performance Monitoring System
 * 
 * Tracks and analyzes system performance metrics for the MLS extractor
 * 
 * @package Bridge_MLS_Extractor_Pro
 * @since 3.0.0
 */

class BME_Performance_Monitor {
    
    private static $instance = null;
    private $wpdb;
    private $table_name;
    private $start_times = [];
    
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
        $this->table_name = $wpdb->prefix . 'bme_performance_metrics';
        
        // Hook into WordPress
        add_action('init', [$this, 'init']);
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('wp_ajax_bme_get_performance_data', [$this, 'ajax_get_performance_data']);
        
        // Monitor key operations
        add_action('bme_sync_start', [$this, 'track_sync_start']);
        add_action('bme_sync_complete', [$this, 'track_sync_complete']);
        add_action('bme_api_request', [$this, 'track_api_request'], 10, 2);
    }
    
    /**
     * Initialize performance monitoring
     */
    public function init() {
        $this->create_tables();
        $this->schedule_cleanup();
    }
    
    /**
     * Create database tables for metrics storage
     */
    private function create_tables() {
        $charset_collate = $this->wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            metric_type varchar(50) NOT NULL,
            metric_name varchar(100) NOT NULL,
            metric_value float NOT NULL,
            metadata longtext,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY metric_type (metric_type),
            KEY metric_name (metric_name),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        // Create alerts table
        $alerts_table = $this->wpdb->prefix . 'bme_performance_alerts';
        $sql_alerts = "CREATE TABLE IF NOT EXISTS {$alerts_table} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            alert_type varchar(50) NOT NULL,
            severity varchar(20) NOT NULL,
            message text NOT NULL,
            details longtext,
            resolved tinyint(1) DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            resolved_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            KEY alert_type (alert_type),
            KEY severity (severity),
            KEY resolved (resolved),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        dbDelta($sql_alerts);
    }
    
    /**
     * Schedule cleanup of old metrics
     */
    private function schedule_cleanup() {
        if (!wp_next_scheduled('bme_cleanup_performance_metrics')) {
            wp_schedule_event(time(), 'daily', 'bme_cleanup_performance_metrics');
        }
        add_action('bme_cleanup_performance_metrics', [$this, 'cleanup_old_metrics']);
    }
    
    /**
     * Add admin menu items
     */
    public function add_admin_menu() {
        add_submenu_page(
            'bme-admin',
            'Performance Monitor',
            'Performance',
            'manage_options',
            'bme-performance',
            [$this, 'render_dashboard']
        );
    }
    
    /**
     * Start timing an operation
     */
    public function start_timer($operation_name) {
        $this->start_times[$operation_name] = microtime(true);
    }
    
    /**
     * End timing and record the metric
     */
    public function end_timer($operation_name, $metadata = []) {
        if (!isset($this->start_times[$operation_name])) {
            return false;
        }
        
        $duration = microtime(true) - $this->start_times[$operation_name];
        unset($this->start_times[$operation_name]);
        
        $this->record_metric('timing', $operation_name, $duration, $metadata);
        
        // Check for performance issues
        $this->check_performance_thresholds($operation_name, $duration);
        
        return $duration;
    }
    
    /**
     * Record a metric
     */
    public function record_metric($type, $name, $value, $metadata = []) {
        $this->wpdb->insert(
            $this->table_name,
            [
                'metric_type' => $type,
                'metric_name' => $name,
                'metric_value' => $value,
                'metadata' => json_encode($metadata),
                'created_at' => current_time('mysql')
            ]
        );
    }
    
    /**
     * Track sync start
     */
    public function track_sync_start() {
        $this->start_timer('full_sync');
        $this->record_metric('event', 'sync_started', 1);
    }
    
    /**
     * Track sync completion
     */
    public function track_sync_complete($stats = []) {
        $duration = $this->end_timer('full_sync', $stats);
        
        if (isset($stats['total_listings'])) {
            $this->record_metric('gauge', 'listings_synced', $stats['total_listings']);
        }
        
        if (isset($stats['errors'])) {
            $this->record_metric('counter', 'sync_errors', $stats['errors']);
        }
        
        // Record memory usage
        $this->record_metric('gauge', 'memory_peak', memory_get_peak_usage(true) / 1048576); // Convert to MB
    }
    
    /**
     * Track API requests
     */
    public function track_api_request($endpoint, $response_time) {
        $this->record_metric('timing', 'api_request', $response_time, [
            'endpoint' => $endpoint
        ]);
        
        // Track API request count
        $this->record_metric('counter', 'api_requests_total', 1);
    }
    
    /**
     * Check performance thresholds and create alerts
     */
    private function check_performance_thresholds($operation, $duration) {
        $thresholds = [
            'full_sync' => 300, // 5 minutes
            'api_request' => 5, // 5 seconds
            'database_query' => 1, // 1 second
        ];
        
        if (isset($thresholds[$operation]) && $duration > $thresholds[$operation]) {
            $this->create_alert(
                'performance',
                'warning',
                "Operation '{$operation}' exceeded threshold",
                [
                    'operation' => $operation,
                    'duration' => $duration,
                    'threshold' => $thresholds[$operation]
                ]
            );
        }
    }
    
    /**
     * Create an alert
     */
    public function create_alert($type, $severity, $message, $details = []) {
        $alerts_table = $this->wpdb->prefix . 'bme_performance_alerts';
        
        $this->wpdb->insert(
            $alerts_table,
            [
                'alert_type' => $type,
                'severity' => $severity,
                'message' => $message,
                'details' => json_encode($details),
                'created_at' => current_time('mysql')
            ]
        );
        
        // Send email notification for critical alerts
        if ($severity === 'critical') {
            $this->send_alert_notification($message, $details);
        }
    }
    
    /**
     * Send alert notification email
     */
    private function send_alert_notification($message, $details) {
        $to = get_option('admin_email');
        $subject = 'BME Performance Alert: ' . $message;
        $body = "A critical performance issue has been detected:\n\n";
        $body .= $message . "\n\n";
        $body .= "Details:\n" . json_encode($details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        
        wp_mail($to, $subject, $body);
    }
    
    /**
     * Get metrics for a specific period
     */
    public function get_metrics($type = null, $name = null, $hours = 24) {
        $where = ["created_at > DATE_SUB(NOW(), INTERVAL %d HOUR)"];
        $values = [$hours];
        
        if ($type) {
            $where[] = "metric_type = %s";
            $values[] = $type;
        }
        
        if ($name) {
            $where[] = "metric_name = %s";
            $values[] = $name;
        }
        
        $where_clause = implode(' AND ', $where);
        
        $query = $this->wpdb->prepare(
            "SELECT * FROM {$this->table_name} 
             WHERE {$where_clause}
             ORDER BY created_at DESC",
            $values
        );
        
        return $this->wpdb->get_results($query);
    }
    
    /**
     * Get aggregated metrics
     */
    public function get_aggregated_metrics($type, $name, $aggregation = 'AVG', $hours = 24) {
        $query = $this->wpdb->prepare(
            "SELECT 
                {$aggregation}(metric_value) as value,
                COUNT(*) as count,
                MIN(metric_value) as min,
                MAX(metric_value) as max,
                STD(metric_value) as std_dev
             FROM {$this->table_name}
             WHERE metric_type = %s 
                AND metric_name = %s
                AND created_at > DATE_SUB(NOW(), INTERVAL %d HOUR)",
            $type,
            $name,
            $hours
        );
        
        return $this->wpdb->get_row($query);
    }
    
    /**
     * Clean up old metrics
     */
    public function cleanup_old_metrics() {
        // Keep metrics for 30 days
        $this->wpdb->query(
            "DELETE FROM {$this->table_name} 
             WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)"
        );
        
        // Keep alerts for 90 days
        $alerts_table = $this->wpdb->prefix . 'bme_performance_alerts';
        $this->wpdb->query(
            "DELETE FROM {$alerts_table} 
             WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)"
        );
    }
    
    /**
     * AJAX handler for getting performance data
     */
    public function ajax_get_performance_data() {
        check_ajax_referer('bme_performance_nonce', 'nonce');
        
        $type = sanitize_text_field($_POST['type'] ?? 'all');
        $period = intval($_POST['period'] ?? 24);
        
        $data = [];
        
        switch ($type) {
            case 'overview':
                $data = $this->get_overview_data($period);
                break;
            case 'api':
                $data = $this->get_api_metrics($period);
                break;
            case 'sync':
                $data = $this->get_sync_metrics($period);
                break;
            case 'alerts':
                $data = $this->get_recent_alerts();
                break;
            default:
                $data = $this->get_all_metrics($period);
        }
        
        wp_send_json_success($data);
    }
    
    /**
     * Get overview data
     */
    private function get_overview_data($hours) {
        return [
            'api_performance' => $this->get_aggregated_metrics('timing', 'api_request', 'AVG', $hours),
            'sync_performance' => $this->get_aggregated_metrics('timing', 'full_sync', 'AVG', $hours),
            'total_api_calls' => $this->get_aggregated_metrics('counter', 'api_requests_total', 'SUM', $hours),
            'memory_usage' => $this->get_aggregated_metrics('gauge', 'memory_peak', 'AVG', $hours),
            'error_count' => $this->get_aggregated_metrics('counter', 'sync_errors', 'SUM', $hours),
        ];
    }
    
    /**
     * Get API metrics
     */
    private function get_api_metrics($hours) {
        return $this->get_metrics('timing', 'api_request', $hours);
    }
    
    /**
     * Get sync metrics
     */
    private function get_sync_metrics($hours) {
        return $this->get_metrics('timing', 'full_sync', $hours);
    }
    
    /**
     * Get recent alerts
     */
    private function get_recent_alerts($limit = 50) {
        $alerts_table = $this->wpdb->prefix . 'bme_performance_alerts';
        
        return $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$alerts_table}
                 ORDER BY created_at DESC
                 LIMIT %d",
                $limit
            )
        );
    }
    
    /**
     * Render the performance dashboard
     */
    public function render_dashboard() {
        ?>
        <div class="wrap">
            <h1>Performance Monitor</h1>
            
            <div id="bme-performance-dashboard">
                <div class="bme-perf-controls">
                    <label>Time Period:
                        <select id="bme-perf-period">
                            <option value="1">Last Hour</option>
                            <option value="24" selected>Last 24 Hours</option>
                            <option value="168">Last Week</option>
                            <option value="720">Last Month</option>
                        </select>
                    </label>
                    <button class="button" id="bme-perf-refresh">Refresh</button>
                </div>
                
                <div class="bme-perf-grid">
                    <div class="bme-perf-card">
                        <h3>API Performance</h3>
                        <div class="bme-perf-metric">
                            <span class="label">Avg Response Time:</span>
                            <span class="value" id="api-avg-time">-</span>
                        </div>
                        <div class="bme-perf-metric">
                            <span class="label">Total Requests:</span>
                            <span class="value" id="api-total">-</span>
                        </div>
                    </div>
                    
                    <div class="bme-perf-card">
                        <h3>Sync Performance</h3>
                        <div class="bme-perf-metric">
                            <span class="label">Avg Sync Time:</span>
                            <span class="value" id="sync-avg-time">-</span>
                        </div>
                        <div class="bme-perf-metric">
                            <span class="label">Error Count:</span>
                            <span class="value" id="sync-errors">-</span>
                        </div>
                    </div>
                    
                    <div class="bme-perf-card">
                        <h3>System Resources</h3>
                        <div class="bme-perf-metric">
                            <span class="label">Avg Memory Usage:</span>
                            <span class="value" id="memory-avg">-</span>
                        </div>
                        <div class="bme-perf-metric">
                            <span class="label">Peak Memory:</span>
                            <span class="value" id="memory-peak">-</span>
                        </div>
                    </div>
                </div>
                
                <div class="bme-perf-alerts">
                    <h3>Recent Alerts</h3>
                    <div id="bme-alerts-list">Loading...</div>
                </div>
            </div>
        </div>
        
        <style>
            #bme-performance-dashboard {
                margin-top: 20px;
            }
            .bme-perf-controls {
                background: #fff;
                padding: 15px;
                border: 1px solid #ccc;
                margin-bottom: 20px;
            }
            .bme-perf-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
                gap: 20px;
                margin-bottom: 20px;
            }
            .bme-perf-card {
                background: #fff;
                padding: 20px;
                border: 1px solid #ccc;
            }
            .bme-perf-card h3 {
                margin-top: 0;
                border-bottom: 1px solid #eee;
                padding-bottom: 10px;
            }
            .bme-perf-metric {
                display: flex;
                justify-content: space-between;
                padding: 10px 0;
                border-bottom: 1px solid #f0f0f0;
            }
            .bme-perf-metric:last-child {
                border-bottom: none;
            }
            .bme-perf-metric .value {
                font-weight: bold;
                color: #0073aa;
            }
            .bme-perf-alerts {
                background: #fff;
                padding: 20px;
                border: 1px solid #ccc;
            }
            .alert-item {
                padding: 10px;
                margin-bottom: 10px;
                border-left: 4px solid #ccc;
            }
            .alert-item.severity-critical {
                border-left-color: #dc3545;
                background: #fff5f5;
            }
            .alert-item.severity-warning {
                border-left-color: #ffc107;
                background: #fffdf5;
            }
            .alert-item.severity-info {
                border-left-color: #17a2b8;
                background: #f0f8ff;
            }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            function loadPerformanceData() {
                const period = $('#bme-perf-period').val();
                
                $.post(ajaxurl, {
                    action: 'bme_get_performance_data',
                    type: 'overview',
                    period: period,
                    nonce: '<?php echo wp_create_nonce('bme_performance_nonce'); ?>'
                }, function(response) {
                    if (response.success) {
                        const data = response.data;
                        
                        // Update API metrics
                        if (data.api_performance) {
                            $('#api-avg-time').text((data.api_performance.value || 0).toFixed(2) + 's');
                            $('#api-total').text(data.total_api_calls?.value || 0);
                        }
                        
                        // Update sync metrics
                        if (data.sync_performance) {
                            $('#sync-avg-time').text((data.sync_performance.value || 0).toFixed(2) + 's');
                            $('#sync-errors').text(data.error_count?.value || 0);
                        }
                        
                        // Update memory metrics
                        if (data.memory_usage) {
                            $('#memory-avg').text((data.memory_usage.value || 0).toFixed(2) + ' MB');
                            $('#memory-peak').text((data.memory_usage.max || 0).toFixed(2) + ' MB');
                        }
                    }
                });
                
                // Load alerts
                $.post(ajaxurl, {
                    action: 'bme_get_performance_data',
                    type: 'alerts',
                    nonce: '<?php echo wp_create_nonce('bme_performance_nonce'); ?>'
                }, function(response) {
                    if (response.success) {
                        const alerts = response.data;
                        let html = '';
                        
                        if (alerts && alerts.length > 0) {
                            alerts.forEach(function(alert) {
                                html += '<div class="alert-item severity-' + alert.severity + '">';
                                html += '<strong>' + alert.message + '</strong><br>';
                                html += '<small>' + alert.created_at + '</small>';
                                html += '</div>';
                            });
                        } else {
                            html = '<p>No recent alerts</p>';
                        }
                        
                        $('#bme-alerts-list').html(html);
                    }
                });
            }
            
            // Initial load
            loadPerformanceData();
            
            // Refresh button
            $('#bme-perf-refresh').on('click', loadPerformanceData);
            
            // Period change
            $('#bme-perf-period').on('change', loadPerformanceData);
            
            // Auto-refresh every 30 seconds
            setInterval(loadPerformanceData, 30000);
        });
        </script>
        <?php
    }
}

// Initialize the performance monitor
BME_Performance_Monitor::get_instance();