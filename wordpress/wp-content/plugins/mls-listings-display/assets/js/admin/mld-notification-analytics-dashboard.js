/**
 * MLD Notification Analytics Dashboard JavaScript
 *
 * Handles data fetching, chart rendering, and UI updates for the
 * notification analytics admin dashboard.
 *
 * @package MLS_Listings_Display
 * @subpackage Notifications
 * @since 6.48.0
 */

(function($) {
    'use strict';

    // Chart instances
    let trendChart = null;
    let typeChart = null;

    // Current settings
    let currentDays = 30;

    /**
     * Initialize the dashboard
     */
    function init() {
        // Bind event handlers
        $('#mld-na-days').on('change', function() {
            currentDays = parseInt($(this).val(), 10);
            loadAllData();
        });

        $('#mld-na-refresh-btn').on('click', function() {
            loadAllData();
        });

        // Initial load
        loadAllData();
    }

    /**
     * Load all dashboard data
     */
    function loadAllData() {
        showLoading();

        Promise.all([
            fetchSummary(),
            fetchBreakdown(),
            fetchTrend()
        ]).then(function() {
            updateLastUpdated();
        }).catch(function(error) {
            console.error('Error loading notification analytics:', error);
        });
    }

    /**
     * Show loading state
     */
    function showLoading() {
        $('.mld-na-card-value').text('-');
        $('.mld-na-card-rate').text('-');
        $('#mld-na-type-tbody').html('<tr><td colspan="8" class="mld-na-loading">Loading...</td></tr>');
        $('#mld-na-channel-tbody').html('<tr><td colspan="7" class="mld-na-loading">Loading...</td></tr>');
    }

    /**
     * Fetch summary data
     */
    function fetchSummary() {
        return $.ajax({
            url: mldNotificationAnalytics.apiUrl + '/admin/notification-analytics',
            method: 'GET',
            headers: {
                'X-WP-Nonce': mldNotificationAnalytics.nonce
            },
            data: {
                days: currentDays
            }
        }).done(function(response) {
            if (response.success && response.data.summary) {
                renderSummaryCards(response.data.summary);
            }
        });
    }

    /**
     * Fetch breakdown data
     */
    function fetchBreakdown() {
        return $.ajax({
            url: mldNotificationAnalytics.apiUrl + '/admin/notification-analytics/by-type',
            method: 'GET',
            headers: {
                'X-WP-Nonce': mldNotificationAnalytics.nonce
            },
            data: {
                days: currentDays
            }
        }).done(function(response) {
            if (response.success && response.data) {
                renderTypeTable(response.data.by_type || []);
                renderChannelTable(response.data.by_channel || []);
                renderTypeChart(response.data.by_type || []);
            }
        });
    }

    /**
     * Fetch trend data
     */
    function fetchTrend() {
        return $.ajax({
            url: mldNotificationAnalytics.apiUrl + '/admin/notification-analytics/trend',
            method: 'GET',
            headers: {
                'X-WP-Nonce': mldNotificationAnalytics.nonce
            },
            data: {
                days: currentDays
            }
        }).done(function(response) {
            if (response.success && response.data.trend) {
                renderTrendChart(response.data.trend);
            }
        });
    }

    /**
     * Render summary cards
     */
    function renderSummaryCards(summary) {
        const sent = parseInt(summary.total_sent || 0, 10);
        const delivered = parseInt(summary.total_delivered || 0, 10);
        const failed = parseInt(summary.total_failed || 0, 10);
        const opened = parseInt(summary.total_opened || 0, 10);
        const clicked = parseInt(summary.total_clicked || 0, 10);

        $('#mld-na-total-sent').text(formatNumber(sent));
        $('#mld-na-delivered').text(formatNumber(delivered));
        $('#mld-na-failed').text(formatNumber(failed));
        $('#mld-na-opened').text(formatNumber(opened));
        $('#mld-na-clicked').text(formatNumber(clicked));

        // Calculate rates
        const deliveryRate = sent > 0 ? ((delivered / sent) * 100).toFixed(1) : 0;
        const failureRate = sent > 0 ? ((failed / sent) * 100).toFixed(1) : 0;
        const openRate = delivered > 0 ? ((opened / delivered) * 100).toFixed(1) : 0;
        const clickRate = opened > 0 ? ((clicked / opened) * 100).toFixed(1) : 0;

        $('#mld-na-delivery-rate').text(deliveryRate + '% delivery');
        $('#mld-na-failure-rate').text(failureRate + '% failure');
        $('#mld-na-open-rate').text(openRate + '% open rate');
        $('#mld-na-click-rate').text(clickRate + '% click rate');
    }

    /**
     * Render type table
     */
    function renderTypeTable(data) {
        const tbody = $('#mld-na-type-tbody');
        tbody.empty();

        if (!data || data.length === 0) {
            tbody.html('<tr><td colspan="8" class="mld-na-empty">No notification data available</td></tr>');
            return;
        }

        data.forEach(function(row) {
            const sent = parseInt(row.total_sent || 0, 10);
            const delivered = parseInt(row.total_delivered || 0, 10);
            const failed = parseInt(row.total_failed || 0, 10);
            const opened = parseInt(row.total_opened || 0, 10);
            const clicked = parseInt(row.total_clicked || 0, 10);

            const openRate = delivered > 0 ? ((opened / delivered) * 100).toFixed(1) + '%' : '-';
            const clickRate = opened > 0 ? ((clicked / opened) * 100).toFixed(1) + '%' : '-';

            tbody.append(
                '<tr>' +
                '<td><span class="mld-na-type-badge mld-na-type-' + row.notification_type + '">' + formatTypeName(row.notification_type) + '</span></td>' +
                '<td>' + formatNumber(sent) + '</td>' +
                '<td>' + formatNumber(delivered) + '</td>' +
                '<td>' + formatNumber(failed) + '</td>' +
                '<td>' + formatNumber(opened) + '</td>' +
                '<td>' + formatNumber(clicked) + '</td>' +
                '<td>' + openRate + '</td>' +
                '<td>' + clickRate + '</td>' +
                '</tr>'
            );
        });
    }

    /**
     * Render channel table
     */
    function renderChannelTable(data) {
        const tbody = $('#mld-na-channel-tbody');
        tbody.empty();

        if (!data || data.length === 0) {
            tbody.html('<tr><td colspan="7" class="mld-na-empty">No notification data available</td></tr>');
            return;
        }

        data.forEach(function(row) {
            const sent = parseInt(row.total_sent || 0, 10);
            const delivered = parseInt(row.total_delivered || 0, 10);
            const failed = parseInt(row.total_failed || 0, 10);
            const opened = parseInt(row.total_opened || 0, 10);
            const clicked = parseInt(row.total_clicked || 0, 10);

            const deliveryRate = sent > 0 ? ((delivered / sent) * 100).toFixed(1) + '%' : '-';

            const channelIcon = row.channel === 'push' ? 'smartphone' : 'email';
            const channelLabel = row.channel === 'push' ? 'Push Notification' : 'Email';

            tbody.append(
                '<tr>' +
                '<td><span class="dashicons dashicons-' + channelIcon + '"></span> ' + channelLabel + '</td>' +
                '<td>' + formatNumber(sent) + '</td>' +
                '<td>' + formatNumber(delivered) + '</td>' +
                '<td>' + formatNumber(failed) + '</td>' +
                '<td>' + formatNumber(opened) + '</td>' +
                '<td>' + formatNumber(clicked) + '</td>' +
                '<td>' + deliveryRate + '</td>' +
                '</tr>'
            );
        });
    }

    /**
     * Render trend chart
     */
    function renderTrendChart(data) {
        const ctx = document.getElementById('mld-na-trend-chart');
        if (!ctx) return;

        // Destroy existing chart
        if (trendChart) {
            trendChart.destroy();
        }

        const labels = data.map(function(d) {
            return formatDate(d.date);
        });

        const sentData = data.map(function(d) {
            return parseInt(d.total_sent || 0, 10);
        });

        const deliveredData = data.map(function(d) {
            return parseInt(d.total_delivered || 0, 10);
        });

        const openedData = data.map(function(d) {
            return parseInt(d.total_opened || 0, 10);
        });

        trendChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Sent',
                        data: sentData,
                        borderColor: '#2271b1',
                        backgroundColor: 'rgba(34, 113, 177, 0.1)',
                        tension: 0.3,
                        fill: true
                    },
                    {
                        label: 'Delivered',
                        data: deliveredData,
                        borderColor: '#00a32a',
                        backgroundColor: 'rgba(0, 163, 42, 0.1)',
                        tension: 0.3,
                        fill: false
                    },
                    {
                        label: 'Opened',
                        data: openedData,
                        borderColor: '#dba617',
                        backgroundColor: 'rgba(219, 166, 23, 0.1)',
                        tension: 0.3,
                        fill: false
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            precision: 0
                        }
                    }
                }
            }
        });
    }

    /**
     * Render type chart (doughnut)
     */
    function renderTypeChart(data) {
        const ctx = document.getElementById('mld-na-type-chart');
        if (!ctx) return;

        // Destroy existing chart
        if (typeChart) {
            typeChart.destroy();
        }

        if (!data || data.length === 0) {
            return;
        }

        const labels = data.map(function(d) {
            return formatTypeName(d.notification_type);
        });

        const values = data.map(function(d) {
            return parseInt(d.total_sent || 0, 10);
        });

        const colors = [
            '#2271b1', // Blue
            '#00a32a', // Green
            '#dba617', // Yellow
            '#d63638', // Red
            '#72aee6', // Light blue
            '#1d2327', // Dark
        ];

        typeChart = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: labels,
                datasets: [{
                    data: values,
                    backgroundColor: colors.slice(0, data.length),
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'right'
                    }
                }
            }
        });
    }

    /**
     * Format number with commas
     */
    function formatNumber(num) {
        return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }

    /**
     * Format type name for display
     */
    function formatTypeName(type) {
        const typeMap = {
            'price_change': 'Price Change',
            'status_change': 'Status Change',
            'new_listing': 'New Listing',
            'open_house': 'Open House',
            'open_house_reminder': 'Open House Reminder',
            'saved_search': 'Saved Search',
            'appointment_reminder': 'Appointment',
            'agent_activity': 'Agent Activity'
        };
        return typeMap[type] || type.replace(/_/g, ' ').replace(/\b\w/g, function(l) { return l.toUpperCase(); });
    }

    /**
     * Format date for display
     */
    function formatDate(dateStr) {
        const date = new Date(dateStr);
        return (date.getMonth() + 1) + '/' + date.getDate();
    }

    /**
     * Update last updated timestamp
     */
    function updateLastUpdated() {
        const now = new Date();
        const timeStr = now.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
        $('#mld-na-last-updated').text('Updated ' + timeStr);
    }

    // Initialize when document is ready
    $(document).ready(init);

})(jQuery);
