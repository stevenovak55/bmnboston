<?php
/**
 * Notification Analytics Dashboard Template
 *
 * @package MLS_Listings_Display
 * @subpackage Notifications
 * @since 6.48.0
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap mld-notification-analytics-wrap">
    <h1>Notification Analytics</h1>
    <p class="description">Track notification delivery and engagement across push and email channels.</p>

    <!-- Time Range Selector -->
    <div class="mld-na-controls">
        <div class="mld-na-time-range">
            <label for="mld-na-days">Time Range:</label>
            <select id="mld-na-days">
                <option value="7">Last 7 Days</option>
                <option value="14">Last 14 Days</option>
                <option value="30" selected>Last 30 Days</option>
                <option value="60">Last 60 Days</option>
                <option value="90">Last 90 Days</option>
            </select>
        </div>
        <div class="mld-na-refresh">
            <button type="button" id="mld-na-refresh-btn" class="button">
                <span class="dashicons dashicons-update"></span> Refresh
            </button>
            <span id="mld-na-last-updated"></span>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="mld-na-summary-cards">
        <div class="mld-na-card mld-na-card-sent">
            <div class="mld-na-card-icon">
                <span class="dashicons dashicons-email-alt"></span>
            </div>
            <div class="mld-na-card-content">
                <div class="mld-na-card-value" id="mld-na-total-sent">-</div>
                <div class="mld-na-card-label">Total Sent</div>
            </div>
        </div>

        <div class="mld-na-card mld-na-card-delivered">
            <div class="mld-na-card-icon">
                <span class="dashicons dashicons-yes-alt"></span>
            </div>
            <div class="mld-na-card-content">
                <div class="mld-na-card-value" id="mld-na-delivered">-</div>
                <div class="mld-na-card-label">Delivered</div>
                <div class="mld-na-card-rate" id="mld-na-delivery-rate">-</div>
            </div>
        </div>

        <div class="mld-na-card mld-na-card-failed">
            <div class="mld-na-card-icon">
                <span class="dashicons dashicons-dismiss"></span>
            </div>
            <div class="mld-na-card-content">
                <div class="mld-na-card-value" id="mld-na-failed">-</div>
                <div class="mld-na-card-label">Failed</div>
                <div class="mld-na-card-rate" id="mld-na-failure-rate">-</div>
            </div>
        </div>

        <div class="mld-na-card mld-na-card-opened">
            <div class="mld-na-card-icon">
                <span class="dashicons dashicons-visibility"></span>
            </div>
            <div class="mld-na-card-content">
                <div class="mld-na-card-value" id="mld-na-opened">-</div>
                <div class="mld-na-card-label">Opened</div>
                <div class="mld-na-card-rate" id="mld-na-open-rate">-</div>
            </div>
        </div>

        <div class="mld-na-card mld-na-card-clicked">
            <div class="mld-na-card-icon">
                <span class="dashicons dashicons-external"></span>
            </div>
            <div class="mld-na-card-content">
                <div class="mld-na-card-value" id="mld-na-clicked">-</div>
                <div class="mld-na-card-label">Clicked</div>
                <div class="mld-na-card-rate" id="mld-na-click-rate">-</div>
            </div>
        </div>
    </div>

    <!-- Charts Row -->
    <div class="mld-na-charts-row">
        <!-- Trend Chart -->
        <div class="mld-na-chart-container mld-na-chart-trend">
            <h3>Notification Volume Trend</h3>
            <canvas id="mld-na-trend-chart"></canvas>
        </div>

        <!-- By Type Chart -->
        <div class="mld-na-chart-container mld-na-chart-type">
            <h3>By Notification Type</h3>
            <canvas id="mld-na-type-chart"></canvas>
        </div>
    </div>

    <!-- Breakdown Tables Row -->
    <div class="mld-na-tables-row">
        <!-- By Type Table -->
        <div class="mld-na-table-container">
            <h3>Performance by Type</h3>
            <table class="wp-list-table widefat fixed striped" id="mld-na-type-table">
                <thead>
                    <tr>
                        <th>Type</th>
                        <th>Sent</th>
                        <th>Delivered</th>
                        <th>Failed</th>
                        <th>Opened</th>
                        <th>Clicked</th>
                        <th>Open Rate</th>
                        <th>Click Rate</th>
                    </tr>
                </thead>
                <tbody id="mld-na-type-tbody">
                    <tr>
                        <td colspan="8" class="mld-na-loading">Loading...</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- By Channel Table -->
        <div class="mld-na-table-container">
            <h3>Performance by Channel</h3>
            <table class="wp-list-table widefat fixed striped" id="mld-na-channel-table">
                <thead>
                    <tr>
                        <th>Channel</th>
                        <th>Sent</th>
                        <th>Delivered</th>
                        <th>Failed</th>
                        <th>Opened</th>
                        <th>Clicked</th>
                        <th>Delivery Rate</th>
                    </tr>
                </thead>
                <tbody id="mld-na-channel-tbody">
                    <tr>
                        <td colspan="7" class="mld-na-loading">Loading...</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Info Box -->
    <div class="mld-na-info-box">
        <h4><span class="dashicons dashicons-info"></span> About Notification Analytics</h4>
        <ul>
            <li><strong>Sent:</strong> Total notifications queued for delivery</li>
            <li><strong>Delivered:</strong> Successfully delivered to device/mailbox</li>
            <li><strong>Failed:</strong> Delivery failed (invalid token, bounce, etc.)</li>
            <li><strong>Opened:</strong> User opened the notification (push) or email</li>
            <li><strong>Clicked:</strong> User clicked/tapped to view content</li>
        </ul>
        <p class="mld-na-note">Note: Data is retained for 90 days. Daily aggregates are stored permanently.</p>
    </div>
</div>
