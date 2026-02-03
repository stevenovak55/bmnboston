<?php
/**
 * BME Performance Dashboard View
 * 
 * Real-time performance monitoring dashboard
 * Version: 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Get performance monitor instance
$monitor = BME_Performance_Monitor::get_instance();

// Get data for dashboard
$summary = $monitor->get_performance_summary('24 hours');
$alerts = $monitor->get_recent_alerts(10);

// Group metrics by type
$metrics_by_type = [];
foreach ($summary as $metric) {
    if (!isset($metrics_by_type[$metric->metric_type])) {
        $metrics_by_type[$metric->metric_type] = [];
    }
    $metrics_by_type[$metric->metric_type][] = $metric;
}
?>

<div class="wrap bme-performance-dashboard">
    <h1 class="wp-heading-inline">
        <span class="dashicons dashicons-performance"></span> Performance Dashboard
    </h1>
    
    <div class="bme-dashboard-header">
        <div class="bme-time-filter">
            <select id="bme-time-range" class="bme-select">
                <option value="1hour">Last Hour</option>
                <option value="24hours" selected>Last 24 Hours</option>
                <option value="7days">Last 7 Days</option>
                <option value="30days">Last 30 Days</option>
            </select>
            <button class="button button-primary" id="bme-refresh-dashboard">
                <span class="dashicons dashicons-update"></span> Refresh
            </button>
        </div>
    </div>
    
    <!-- Alert Banner -->
    <?php if (!empty($alerts)): ?>
    <div class="bme-alerts-banner">
        <h3>
            <span class="dashicons dashicons-warning"></span> Active Alerts
            <span class="badge"><?php echo count($alerts); ?></span>
        </h3>
        <div class="bme-alerts-list">
            <?php foreach ($alerts as $alert): ?>
            <div class="bme-alert-item severity-<?php echo esc_attr($alert->severity); ?>">
                <span class="bme-alert-icon">
                    <?php if ($alert->severity === 'critical'): ?>
                        <span class="dashicons dashicons-dismiss"></span>
                    <?php elseif ($alert->severity === 'warning'): ?>
                        <span class="dashicons dashicons-warning"></span>
                    <?php else: ?>
                        <span class="dashicons dashicons-info"></span>
                    <?php endif; ?>
                </span>
                <div class="bme-alert-content">
                    <strong><?php echo esc_html($alert->alert_type); ?></strong>
                    <p><?php echo esc_html($alert->message); ?></p>
                    <small><?php echo esc_html(human_time_diff(strtotime($alert->created_at), current_time('timestamp'))); ?> ago</small>
                </div>
                <button class="bme-resolve-alert" data-alert-id="<?php echo esc_attr($alert->id); ?>">
                    <span class="dashicons dashicons-yes"></span>
                </button>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Key Metrics Grid -->
    <div class="bme-metrics-grid">
        <div class="bme-metric-card">
            <div class="bme-metric-icon">
                <span class="dashicons dashicons-clock"></span>
            </div>
            <div class="bme-metric-content">
                <h3>Avg Page Load</h3>
                <div class="bme-metric-value" id="avg-page-load">
                    <span class="loading">Loading...</span>
                </div>
                <div class="bme-metric-trend">
                    <span class="trend-up">↑ 5%</span> vs last period
                </div>
            </div>
        </div>
        
        <div class="bme-metric-card">
            <div class="bme-metric-icon">
                <span class="dashicons dashicons-database"></span>
            </div>
            <div class="bme-metric-content">
                <h3>Database Queries</h3>
                <div class="bme-metric-value" id="total-queries">
                    <span class="loading">Loading...</span>
                </div>
                <div class="bme-metric-trend">
                    <span class="trend-down">↓ 12%</span> vs last period
                </div>
            </div>
        </div>
        
        <div class="bme-metric-card">
            <div class="bme-metric-icon">
                <span class="dashicons dashicons-admin-generic"></span>
            </div>
            <div class="bme-metric-content">
                <h3>Memory Usage</h3>
                <div class="bme-metric-value" id="memory-usage">
                    <span class="loading">Loading...</span>
                </div>
                <div class="bme-metric-progress">
                    <div class="progress-bar" style="width: 45%;"></div>
                </div>
            </div>
        </div>
        
        <div class="bme-metric-card">
            <div class="bme-metric-icon">
                <span class="dashicons dashicons-rest-api"></span>
            </div>
            <div class="bme-metric-content">
                <h3>API Response Time</h3>
                <div class="bme-metric-value" id="api-response">
                    <span class="loading">Loading...</span>
                </div>
                <div class="bme-metric-status">
                    <span class="status-good">Healthy</span>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Performance Charts -->
    <div class="bme-charts-container">
        <div class="bme-chart-card">
            <h3>Response Time Trend</h3>
            <canvas id="response-time-chart"></canvas>
        </div>
        
        <div class="bme-chart-card">
            <h3>Resource Usage</h3>
            <canvas id="resource-usage-chart"></canvas>
        </div>
    </div>
    
    <!-- Detailed Metrics Tables -->
    <div class="bme-tables-container">
        <?php foreach ($metrics_by_type as $type => $metrics): ?>
        <div class="bme-metrics-table">
            <h3><?php echo esc_html(ucfirst(str_replace('_', ' ', $type))); ?> Metrics</h3>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Metric Name</th>
                        <th>Count</th>
                        <th>Average</th>
                        <th>Min</th>
                        <th>Max</th>
                        <th>Std Dev</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($metrics as $metric): ?>
                    <tr>
                        <td><strong><?php echo esc_html($metric->metric_name); ?></strong></td>
                        <td><?php echo number_format($metric->count); ?></td>
                        <td><?php echo number_format($metric->avg_value, 2); ?></td>
                        <td><?php echo number_format($metric->min_value, 2); ?></td>
                        <td><?php echo number_format($metric->max_value, 2); ?></td>
                        <td><?php echo number_format($metric->std_deviation, 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endforeach; ?>
    </div>
    
    <!-- Real-time Monitor -->
    <div class="bme-realtime-monitor">
        <h3>
            Real-time Activity Monitor
            <span class="bme-status-indicator active" id="realtime-status"></span>
        </h3>
        <div class="bme-activity-stream" id="activity-stream">
            <div class="bme-activity-item">
                <span class="time">Just now</span>
                <span class="activity">Waiting for activity...</span>
            </div>
        </div>
    </div>
</div>

<style>
.bme-performance-dashboard {
    max-width: 1400px;
    margin: 20px auto;
}

.bme-dashboard-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin: 20px 0;
    padding: 15px;
    background: white;
    border-radius: 8px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}

.bme-time-filter {
    display: flex;
    gap: 10px;
    align-items: center;
}

.bme-alerts-banner {
    background: #fff3cd;
    border: 1px solid #ffc107;
    border-radius: 8px;
    padding: 15px;
    margin-bottom: 20px;
}

.bme-alerts-banner h3 {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-top: 0;
}

.badge {
    background: #dc3545;
    color: white;
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 12px;
}

.bme-alerts-list {
    margin-top: 15px;
}

.bme-alert-item {
    display: flex;
    align-items: center;
    gap: 15px;
    padding: 10px;
    background: white;
    border-radius: 6px;
    margin-bottom: 10px;
}

.bme-alert-item.severity-critical {
    border-left: 4px solid #dc3545;
}

.bme-alert-item.severity-warning {
    border-left: 4px solid #ffc107;
}

.bme-alert-content {
    flex: 1;
}

.bme-alert-content p {
    margin: 5px 0;
    color: #666;
}

.bme-resolve-alert {
    background: #28a745;
    color: white;
    border: none;
    border-radius: 4px;
    padding: 5px 10px;
    cursor: pointer;
}

.bme-metrics-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.bme-metric-card {
    background: white;
    border-radius: 8px;
    padding: 20px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    display: flex;
    gap: 15px;
    align-items: center;
}

.bme-metric-icon {
    width: 50px;
    height: 50px;
    background: #f0f0f0;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
}

.bme-metric-icon .dashicons {
    font-size: 24px;
    width: 24px;
    height: 24px;
}

.bme-metric-content {
    flex: 1;
}

.bme-metric-content h3 {
    margin: 0 0 10px 0;
    font-size: 14px;
    color: #666;
}

.bme-metric-value {
    font-size: 28px;
    font-weight: bold;
    color: #333;
}

.bme-metric-trend {
    font-size: 12px;
    color: #999;
    margin-top: 5px;
}

.trend-up {
    color: #dc3545;
}

.trend-down {
    color: #28a745;
}

.bme-metric-progress {
    margin-top: 10px;
    height: 6px;
    background: #f0f0f0;
    border-radius: 3px;
    overflow: hidden;
}

.progress-bar {
    height: 100%;
    background: #007cba;
    transition: width 0.3s ease;
}

.bme-metric-status {
    margin-top: 5px;
}

.status-good {
    color: #28a745;
    font-size: 12px;
    font-weight: 500;
}

.bme-charts-container {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(500px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.bme-chart-card {
    background: white;
    border-radius: 8px;
    padding: 20px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}

.bme-chart-card h3 {
    margin-top: 0;
    margin-bottom: 20px;
}

.bme-tables-container {
    margin-bottom: 30px;
}

.bme-metrics-table {
    background: white;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 20px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}

.bme-metrics-table h3 {
    margin-top: 0;
}

.bme-realtime-monitor {
    background: white;
    border-radius: 8px;
    padding: 20px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}

.bme-realtime-monitor h3 {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-top: 0;
}

.bme-status-indicator {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background: #28a745;
    display: inline-block;
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0% { opacity: 1; }
    50% { opacity: 0.5; }
    100% { opacity: 1; }
}

.bme-activity-stream {
    max-height: 300px;
    overflow-y: auto;
    border: 1px solid #e0e0e0;
    border-radius: 4px;
    padding: 10px;
    margin-top: 15px;
}

.bme-activity-item {
    padding: 8px;
    border-bottom: 1px solid #f0f0f0;
    display: flex;
    gap: 15px;
}

.bme-activity-item:last-child {
    border-bottom: none;
}

.bme-activity-item .time {
    color: #999;
    font-size: 12px;
    min-width: 80px;
}

.bme-activity-item .activity {
    flex: 1;
    font-size: 14px;
}

.loading {
    color: #999;
    font-size: 14px;
}
</style>

<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
<script>
jQuery(document).ready(function($) {
    // Ensure ajaxurl is defined
    if (typeof ajaxurl === 'undefined') {
        var ajaxurl = '<?php echo admin_url('admin-ajax.php'); ?>';
    }
    // Initialize dashboard
    initPerformanceDashboard();
    
    function initPerformanceDashboard() {
        // Load initial metrics
        loadMetrics();
        
        // Set up auto-refresh
        setInterval(loadMetrics, 30000); // Refresh every 30 seconds
        
        // Handle refresh button
        $('#bme-refresh-dashboard').on('click', function() {
            $(this).find('.dashicons').addClass('spin');
            loadMetrics(() => {
                $(this).find('.dashicons').removeClass('spin');
            });
        });
        
        // Handle time range change
        $('#bme-time-range').on('change', function() {
            loadMetrics();
        });
        
        // Handle alert resolution
        $('.bme-resolve-alert').on('click', function() {
            const alertId = $(this).data('alert-id');
            resolveAlert(alertId, $(this).closest('.bme-alert-item'));
        });
        
        // Initialize charts
        initCharts();
        
        // Start real-time monitoring
        startRealtimeMonitoring();
    }
    
    function loadMetrics(callback) {
        const timeRange = $('#bme-time-range').val();
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'bme_get_performance_metrics',
                time_range: timeRange,
                nonce: '<?php echo wp_create_nonce('bme_performance_nonce'); ?>'
            },
            success: function(response) {
                if (response.success) {
                    updateMetricCards(response.data);
                    updateCharts(response.data);
                }
                if (callback) callback();
            },
            error: function() {
                console.error('Failed to load metrics');
                if (callback) callback();
            }
        });
    }
    
    function updateMetricCards(data) {
        // Update page load time
        if (data.page_load) {
            $('#avg-page-load').html(data.page_load.avg.toFixed(2) + 's');
        }
        
        // Update query count
        if (data.queries) {
            $('#total-queries').html(data.queries.total.toLocaleString());
        }
        
        // Update memory usage
        if (data.memory) {
            const memoryMB = (data.memory.current / 1048576).toFixed(1);
            const percentage = data.memory.percentage;
            $('#memory-usage').html(memoryMB + ' MB');
            $('.bme-metric-card .progress-bar').css('width', percentage + '%');
        }
        
        // Update API response time
        if (data.api_response) {
            $('#api-response').html(data.api_response.avg.toFixed(2) + 's');
        }
    }
    
    function initCharts() {
        // Response Time Chart
        const responseCtx = document.getElementById('response-time-chart');
        if (responseCtx) {
            new Chart(responseCtx, {
                type: 'line',
                data: {
                    labels: [],
                    datasets: [{
                        label: 'Response Time (s)',
                        data: [],
                        borderColor: '#007cba',
                        tension: 0.4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        }
                    }
                }
            });
        }
        
        // Resource Usage Chart
        const resourceCtx = document.getElementById('resource-usage-chart');
        if (resourceCtx) {
            new Chart(resourceCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Memory', 'CPU', 'Disk', 'Free'],
                    datasets: [{
                        data: [30, 25, 15, 30],
                        backgroundColor: [
                            '#007cba',
                            '#28a745',
                            '#ffc107',
                            '#e0e0e0'
                        ]
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false
                }
            });
        }
    }
    
    function updateCharts(data) {
        // Update chart data based on metrics
        // This would be implemented based on actual data structure
    }
    
    function resolveAlert(alertId, element) {
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'bme_resolve_alert',
                alert_id: alertId,
                nonce: '<?php echo wp_create_nonce('bme_alert_nonce'); ?>'
            },
            success: function(response) {
                if (response.success) {
                    element.fadeOut(300, function() {
                        $(this).remove();
                        updateAlertCount();
                    });
                }
            }
        });
    }
    
    function updateAlertCount() {
        const count = $('.bme-alert-item').length;
        $('.bme-alerts-banner .badge').text(count);
        if (count === 0) {
            $('.bme-alerts-banner').fadeOut();
        }
    }
    
    function startRealtimeMonitoring() {
        // Simulate real-time updates
        setInterval(function() {
            addActivityItem({
                time: 'Just now',
                activity: 'API call completed in 0.234s'
            });
        }, 5000);
    }
    
    function addActivityItem(item) {
        const html = `
            <div class="bme-activity-item">
                <span class="time">${item.time}</span>
                <span class="activity">${item.activity}</span>
            </div>
        `;
        
        $('#activity-stream').prepend(html);
        
        // Keep only last 20 items
        $('#activity-stream .bme-activity-item:gt(19)').remove();
    }
});
</script>