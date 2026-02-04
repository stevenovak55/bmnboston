<?php
/**
 * Analytics Dashboard View
 *
 * @package MLS_Listings_Display
 * @subpackage Analytics
 * @since 6.39.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Get initial stats for page load
$dashboard = MLD_Analytics_Admin_Dashboard::get_instance();
$stats = $dashboard->get_quick_stats();
?>

<div class="wrap mld-analytics-dashboard">
    <h1 class="wp-heading-inline">
        <span class="dashicons dashicons-chart-area"></span>
        Site Analytics
    </h1>

    <!-- Platform Filter -->
    <div class="mld-platform-filter">
        <label>
            <input type="checkbox" id="mld-filter-web" checked>
            <span class="mld-platform-badge mld-platform-web">Web</span>
        </label>
        <label>
            <input type="checkbox" id="mld-filter-ios" checked>
            <span class="mld-platform-badge mld-platform-ios">iOS App</span>
        </label>
        <!-- v6.75.7: Dashboard-wide Date Range Control -->
        <div class="mld-date-range-control">
            <div class="mld-date-presets">
                <button type="button" class="mld-date-btn" data-range="24h">24h</button>
                <button type="button" class="mld-date-btn active" data-range="7d">7 Days</button>
                <button type="button" class="mld-date-btn" data-range="30d">30 Days</button>
                <button type="button" class="mld-date-btn" data-range="custom">Custom</button>
            </div>
            <div class="mld-custom-dates" id="mld-custom-dates" style="display: none;">
                <input type="date" id="mld-start-date" class="mld-date-input"
                       max="<?php echo esc_attr(wp_date('Y-m-d')); ?>">
                <span class="mld-date-separator">to</span>
                <input type="date" id="mld-end-date" class="mld-date-input"
                       value="<?php echo esc_attr(wp_date('Y-m-d')); ?>"
                       max="<?php echo esc_attr(wp_date('Y-m-d')); ?>">
                <button type="button" class="button button-primary" id="mld-apply-dates">Apply</button>
            </div>
        </div>
        <span class="mld-last-updated">
            Last updated: <span id="mld-last-update-time">--</span>
        </span>
    </div>

    <!-- Real-time Active Visitors -->
    <div class="mld-realtime-panel">
        <div class="mld-realtime-count">
            <span id="mld-active-count"><?php echo esc_html($stats['active_visitors']); ?></span>
            <span class="mld-realtime-label">Active Now</span>
        </div>
        <div class="mld-realtime-breakdown">
            <div class="mld-realtime-item" id="mld-active-web">
                <span class="mld-platform-dot mld-dot-web"></span>
                <span class="mld-count">0</span> Web
            </div>
            <div class="mld-realtime-item" id="mld-active-ios">
                <span class="mld-platform-dot mld-dot-ios"></span>
                <span class="mld-count">0</span> iOS
            </div>
        </div>
        <div class="mld-realtime-pages" id="mld-current-pages">
            <!-- Populated by JS -->
        </div>
    </div>

    <!-- Stats Cards Row -->
    <div class="mld-stats-row">
        <div class="mld-stat-card">
            <div class="mld-stat-icon"><span class="dashicons dashicons-groups"></span></div>
            <div class="mld-stat-content">
                <div class="mld-stat-value" id="mld-stat-sessions"><?php echo number_format($stats['sessions']['value']); ?></div>
                <div class="mld-stat-label">Sessions Today</div>
                <div class="mld-stat-change <?php echo $stats['sessions']['change'] >= 0 ? 'positive' : 'negative'; ?>">
                    <span id="mld-change-sessions"><?php echo ($stats['sessions']['change'] >= 0 ? '+' : '') . $stats['sessions']['change']; ?>%</span>
                    vs yesterday
                </div>
            </div>
        </div>

        <div class="mld-stat-card">
            <div class="mld-stat-icon"><span class="dashicons dashicons-visibility"></span></div>
            <div class="mld-stat-content">
                <div class="mld-stat-value" id="mld-stat-pageviews"><?php echo number_format($stats['page_views']['value']); ?></div>
                <div class="mld-stat-label">Page Views</div>
                <div class="mld-stat-change <?php echo $stats['page_views']['change'] >= 0 ? 'positive' : 'negative'; ?>">
                    <span id="mld-change-pageviews"><?php echo ($stats['page_views']['change'] >= 0 ? '+' : '') . $stats['page_views']['change']; ?>%</span>
                    vs yesterday
                </div>
            </div>
        </div>

        <div class="mld-stat-card">
            <div class="mld-stat-icon"><span class="dashicons dashicons-admin-home"></span></div>
            <div class="mld-stat-content">
                <div class="mld-stat-value" id="mld-stat-properties"><?php echo number_format($stats['property_views']['value']); ?></div>
                <div class="mld-stat-label">Property Views</div>
                <div class="mld-stat-change <?php echo $stats['property_views']['change'] >= 0 ? 'positive' : 'negative'; ?>">
                    <span id="mld-change-properties"><?php echo ($stats['property_views']['change'] >= 0 ? '+' : '') . $stats['property_views']['change']; ?>%</span>
                    vs yesterday
                </div>
            </div>
        </div>

        <div class="mld-stat-card">
            <div class="mld-stat-icon"><span class="dashicons dashicons-search"></span></div>
            <div class="mld-stat-content">
                <div class="mld-stat-value" id="mld-stat-searches"><?php echo number_format($stats['searches']['value']); ?></div>
                <div class="mld-stat-label">Searches</div>
                <div class="mld-stat-change <?php echo $stats['searches']['change'] >= 0 ? 'positive' : 'negative'; ?>">
                    <span id="mld-change-searches"><?php echo ($stats['searches']['change'] >= 0 ? '+' : '') . $stats['searches']['change']; ?>%</span>
                    vs yesterday
                </div>
            </div>
        </div>
    </div>

    <!-- Main Dashboard Grid -->
    <div class="mld-dashboard-grid">
        <!-- Traffic Chart -->
        <div class="mld-card mld-card-chart">
            <div class="mld-card-header">
                <h3>Traffic Trends</h3>
                <span class="mld-chart-range-label" id="mld-chart-range-label">Last 7 Days</span>
            </div>
            <div class="mld-card-body">
                <canvas id="mld-traffic-chart" height="300"></canvas>
            </div>
        </div>

        <!-- Activity Stream -->
        <div class="mld-card mld-card-activity">
            <div class="mld-card-header">
                <h3>Live Activity</h3>
                <button type="button" class="mld-btn-icon" id="mld-pause-stream" title="Pause updates">
                    <span class="dashicons dashicons-controls-pause"></span>
                </button>
            </div>
            <!-- v6.47.0: Activity Controls -->
            <div class="mld-activity-controls">
                <select id="mld-activity-range" class="mld-select">
                    <option value="15m" selected>Last 15 min</option>
                    <option value="1h">Last hour</option>
                    <option value="4h">Last 4 hours</option>
                    <option value="24h">Last 24 hours</option>
                    <option value="7d">Last 7 days</option>
                </select>
                <select id="mld-activity-platform" class="mld-select">
                    <option value="">All platforms</option>
                    <option value="web_desktop">Web Desktop</option>
                    <option value="web_mobile">Web Mobile</option>
                    <option value="ios_app">iOS App</option>
                </select>
                <label class="mld-checkbox-label">
                    <input type="checkbox" id="mld-activity-logged-in-only">
                    <span>Logged-in only</span>
                </label>
            </div>
            <div class="mld-card-body">
                <div class="mld-activity-stream" id="mld-activity-stream">
                    <div class="mld-loading">Loading activity...</div>
                </div>
                <!-- v6.47.0: Pagination -->
                <div class="mld-activity-pagination" id="mld-activity-pagination" style="display: none;">
                    <span class="mld-pagination-info" id="mld-pagination-info">Showing 1-50 of 0</span>
                    <div class="mld-pagination-buttons">
                        <button type="button" class="mld-btn-pagination" id="mld-prev-page" disabled>
                            <span class="dashicons dashicons-arrow-left-alt2"></span> Prev
                        </button>
                        <button type="button" class="mld-btn-pagination" id="mld-next-page" disabled>
                            Next <span class="dashicons dashicons-arrow-right-alt2"></span>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- v6.47.0: Session Journey Side Panel -->
        <div id="mld-journey-panel" class="mld-side-panel" style="display: none;">
            <div class="mld-panel-header">
                <h3>Session Journey</h3>
                <button type="button" class="mld-close-panel" id="mld-close-journey">
                    <span class="dashicons dashicons-no-alt"></span>
                </button>
            </div>
            <div class="mld-panel-content">
                <div class="mld-session-meta" id="mld-journey-meta">
                    <!-- User info, device, location -->
                </div>
                <div class="mld-journey-timeline" id="mld-journey-timeline">
                    <div class="mld-loading">Loading journey...</div>
                </div>
            </div>
        </div>

        <!-- Top Pages -->
        <div class="mld-card mld-card-table">
            <div class="mld-card-header">
                <h3>Top Pages</h3>
            </div>
            <div class="mld-card-body">
                <table class="mld-data-table" id="mld-top-pages">
                    <thead>
                        <tr>
                            <th>Page</th>
                            <th>Views</th>
                            <th>Avg Time</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr><td colspan="3" class="mld-loading">Loading...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Top Properties -->
        <div class="mld-card mld-card-table">
            <div class="mld-card-header">
                <h3>Top Properties</h3>
            </div>
            <div class="mld-card-body">
                <table class="mld-data-table" id="mld-top-properties">
                    <thead>
                        <tr>
                            <th>Property</th>
                            <th>Views</th>
                            <th>Contacts</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr><td colspan="3" class="mld-loading">Loading...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Top Searches (v6.54.0) -->
        <div class="mld-card mld-card-table">
            <div class="mld-card-header">
                <h3>Popular Searches</h3>
            </div>
            <div class="mld-card-body">
                <table class="mld-data-table" id="mld-top-searches">
                    <thead>
                        <tr>
                            <th>Search Query</th>
                            <th>Count</th>
                            <th>%</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr><td colspan="3" class="mld-loading">Loading...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Traffic Sources -->
        <div class="mld-card mld-card-sources">
            <div class="mld-card-header">
                <h3>Traffic Sources</h3>
            </div>
            <div class="mld-card-body">
                <div class="mld-sources-chart-container">
                    <canvas id="mld-sources-chart" height="200"></canvas>
                </div>
                <table class="mld-data-table mld-compact" id="mld-traffic-sources">
                    <thead>
                        <tr>
                            <th>Source</th>
                            <th>Sessions</th>
                            <th>%</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr><td colspan="3" class="mld-loading">Loading...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Geographic -->
        <div class="mld-card mld-card-geo">
            <div class="mld-card-header">
                <h3>Geographic Distribution</h3>
            </div>
            <div class="mld-card-body">
                <div class="mld-geo-tabs">
                    <button type="button" class="mld-geo-tab active" data-tab="cities">Cities</button>
                    <button type="button" class="mld-geo-tab" data-tab="countries">Countries</button>
                </div>
                <table class="mld-data-table" id="mld-geo-table">
                    <thead>
                        <tr>
                            <th>Location</th>
                            <th>Sessions</th>
                            <th>%</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr><td colspan="3" class="mld-loading">Loading...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Device Breakdown -->
        <div class="mld-card mld-card-devices">
            <div class="mld-card-header">
                <h3>Devices & Browsers</h3>
            </div>
            <div class="mld-card-body">
                <div class="mld-device-charts">
                    <div class="mld-device-chart">
                        <h4>Device Types</h4>
                        <canvas id="mld-devices-chart" height="180"></canvas>
                    </div>
                    <div class="mld-device-chart">
                        <h4>Browsers</h4>
                        <canvas id="mld-browsers-chart" height="180"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Platform Breakdown -->
        <div class="mld-card mld-card-platforms">
            <div class="mld-card-header">
                <h3>Platform Breakdown</h3>
            </div>
            <div class="mld-card-body">
                <div class="mld-platform-stats">
                    <div class="mld-platform-stat">
                        <div class="mld-platform-icon mld-platform-web">
                            <span class="dashicons dashicons-desktop"></span>
                        </div>
                        <div class="mld-platform-details">
                            <div class="mld-platform-name">Web Desktop</div>
                            <div class="mld-platform-value" id="mld-platform-desktop">0</div>
                        </div>
                    </div>
                    <div class="mld-platform-stat">
                        <div class="mld-platform-icon mld-platform-web">
                            <span class="dashicons dashicons-smartphone"></span>
                        </div>
                        <div class="mld-platform-details">
                            <div class="mld-platform-name">Web Mobile</div>
                            <div class="mld-platform-value" id="mld-platform-mobile">0</div>
                        </div>
                    </div>
                    <div class="mld-platform-stat">
                        <div class="mld-platform-icon mld-platform-ios">
                            <span class="dashicons dashicons-tablet"></span>
                        </div>
                        <div class="mld-platform-details">
                            <div class="mld-platform-name">iOS App</div>
                            <div class="mld-platform-value" id="mld-platform-ios">0</div>
                        </div>
                    </div>
                </div>
                <canvas id="mld-platforms-chart" height="150"></canvas>
            </div>
        </div>
    </div>

    <!-- Database Stats Footer -->
    <div class="mld-db-stats">
        <span class="mld-db-stat">
            <strong>Sessions:</strong> <span id="mld-db-sessions">--</span>
        </span>
        <span class="mld-db-stat">
            <strong>Events:</strong> <span id="mld-db-events">--</span>
        </span>
        <span class="mld-db-stat">
            <strong>Hourly Records:</strong> <span id="mld-db-hourly">--</span>
        </span>
        <span class="mld-db-stat">
            <strong>Daily Records:</strong> <span id="mld-db-daily">--</span>
        </span>
    </div>
</div>
