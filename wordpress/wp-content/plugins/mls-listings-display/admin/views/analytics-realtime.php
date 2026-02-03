<?php
/**
 * Real-time Analytics Dashboard
 *
 * @package MLD_Saved_Search_V2
 */

if (!defined('ABSPATH')) {
    exit;
}

$analytics = MLD_Analytics_Engine::get_instance();
$current_metrics = $analytics->get_metrics('overview', ['start' => date('Y-m-d'), 'end' => date('Y-m-d')]);
?>

<div class="wrap mld-analytics-realtime">
    <h1>Real-Time Analytics Dashboard</h1>
    
    <div class="mld-analytics-header">
        <div class="date-range-selector">
            <label>Time Range:</label>
            <select id="realtime-range">
                <option value="today">Today</option>
                <option value="last-hour">Last Hour</option>
                <option value="last-24h">Last 24 Hours</option>
                <option value="last-7d">Last 7 Days</option>
            </select>
        </div>
        
        <div class="refresh-controls">
            <label>
                <input type="checkbox" id="auto-refresh" checked>
                Auto-refresh (30s)
            </label>
            <button class="button" id="refresh-now">Refresh Now</button>
        </div>
    </div>
    
    <!-- Real-time Metrics Cards -->
    <div class="mld-metrics-grid">
        <div class="metric-card" data-metric="active-users">
            <div class="metric-icon"><span class="dashicons dashicons-groups"></span></div>
            <div class="metric-content">
                <div class="metric-value" id="active-users-value">0</div>
                <div class="metric-label">Active Users</div>
                <div class="metric-trend">
                    <span class="trend-indicator"></span>
                    <span class="trend-value">0%</span>
                </div>
            </div>
        </div>
        
        <div class="metric-card" data-metric="active-sessions">
            <div class="metric-icon"><span class="dashicons dashicons-desktop"></span></div>
            <div class="metric-content">
                <div class="metric-value" id="active-sessions-value">0</div>
                <div class="metric-label">Active Sessions</div>
                <div class="metric-trend">
                    <span class="trend-indicator"></span>
                    <span class="trend-value">0%</span>
                </div>
            </div>
        </div>
        
        <div class="metric-card" data-metric="page-views">
            <div class="metric-icon"><span class="dashicons dashicons-visibility"></span></div>
            <div class="metric-content">
                <div class="metric-value" id="page-views-value">0</div>
                <div class="metric-label">Page Views</div>
                <div class="metric-trend">
                    <span class="trend-indicator"></span>
                    <span class="trend-value">0%</span>
                </div>
            </div>
        </div>
        
        <div class="metric-card" data-metric="conversions">
            <div class="metric-icon"><span class="dashicons dashicons-chart-line"></span></div>
            <div class="metric-content">
                <div class="metric-value" id="conversions-value">0</div>
                <div class="metric-label">Conversions</div>
                <div class="metric-trend">
                    <span class="trend-indicator"></span>
                    <span class="trend-value">0%</span>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Real-time Activity Stream -->
    <div class="mld-activity-section">
        <h2>Live Activity Stream</h2>
        <div class="activity-stream" id="activity-stream">
            <div class="activity-item">
                <span class="activity-time">Loading...</span>
                <span class="activity-user">...</span>
                <span class="activity-action">...</span>
            </div>
        </div>
    </div>
    
    <!-- Real-time Charts -->
    <div class="mld-charts-section">
        <div class="chart-container half-width">
            <h3>Traffic Over Time</h3>
            <canvas id="traffic-chart"></canvas>
        </div>
        
        <div class="chart-container half-width">
            <h3>Conversion Funnel</h3>
            <canvas id="funnel-chart"></canvas>
        </div>
        
        <div class="chart-container half-width">
            <h3>Top Properties</h3>
            <div id="top-properties-list">
                <div class="property-item">
                    <span class="property-address">Loading...</span>
                    <span class="property-views">0 views</span>
                </div>
            </div>
        </div>
        
        <div class="chart-container half-width">
            <h3>Geographic Distribution</h3>
            <div id="geo-map"></div>
        </div>
    </div>
    
    <!-- Performance Metrics -->
    <div class="mld-performance-section">
        <h2>System Performance</h2>
        <div class="performance-grid">
            <div class="perf-metric">
                <label>API Response Time</label>
                <div class="perf-bar">
                    <div class="perf-fill" id="api-response" style="width: 0%"></div>
                </div>
                <span class="perf-value">0ms</span>
            </div>
            
            <div class="perf-metric">
                <label>Database Query Time</label>
                <div class="perf-bar">
                    <div class="perf-fill" id="db-query" style="width: 0%"></div>
                </div>
                <span class="perf-value">0ms</span>
            </div>
            
            <div class="perf-metric">
                <label>Page Load Time</label>
                <div class="perf-bar">
                    <div class="perf-fill" id="page-load" style="width: 0%"></div>
                </div>
                <span class="perf-value">0s</span>
            </div>
            
            <div class="perf-metric">
                <label>Server CPU Usage</label>
                <div class="perf-bar">
                    <div class="perf-fill" id="cpu-usage" style="width: 0%"></div>
                </div>
                <span class="perf-value">0%</span>
            </div>
        </div>
    </div>
    
    <!-- Alerts and Notifications -->
    <div class="mld-alerts-section">
        <h2>Alerts & Anomalies</h2>
        <div id="alerts-container">
            <div class="alert-item alert-info">
                <span class="dashicons dashicons-info"></span>
                <span class="alert-message">System running normally</span>
                <span class="alert-time">Now</span>
            </div>
        </div>
    </div>
</div>

<script type="text/javascript">
jQuery(document).ready(function($) {
    // Initialize real-time analytics
    var realtimeAnalytics = new MLDRealtimeAnalytics({
        refreshInterval: 30000,
        endpoints: {
            metrics: ajaxurl + '?action=mld_get_realtime_metrics',
            activity: ajaxurl + '?action=mld_get_activity_stream',
            performance: ajaxurl + '?action=mld_get_performance_metrics'
        }
    });
    
    realtimeAnalytics.init();
});
</script>