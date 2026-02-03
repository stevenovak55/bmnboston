/**
 * MLD Instant Notifications Admin JavaScript
 * Dashboard and Activity Monitor functionality
 */

(function($) {
    'use strict';

    // Chart instance storage
    let activityChart = null;
    let deliveryRateChart = null;
    let responseTimeChart = null;
    let channelDistributionChart = null;

    // Auto-refresh interval
    let refreshInterval = null;
    let realtimeInterval = null;

    /**
     * Initialize on document ready
     */
    $(document).ready(function() {
        // Dashboard page
        if ($('.mld-instant-dashboard').length) {
            initDashboard();
        }

        // Activity monitor page
        if ($('.mld-activity-monitor').length) {
            initActivityMonitor();
        }
    });

    /**
     * Initialize Dashboard
     */
    function initDashboard() {
        // System toggle
        $('#system-toggle').on('change', function() {
            toggleSystem($(this).is(':checked'));
        });

        // Quick action buttons
        $('#test-notification-btn').on('click', sendTestNotification);
        $('#clear-queue-btn').on('click', clearQueue);
        $('#export-stats-btn').on('click', exportStatistics);

        // Initialize activity chart
        initActivityChart();

        // Load initial data
        loadDashboardStats();

        // Auto-refresh every 30 seconds
        refreshInterval = setInterval(function() {
            loadDashboardStats();
            loadRecentActivity();
        }, 30000);
    }

    /**
     * Initialize Activity Monitor
     */
    function initActivityMonitor() {
        // Filter button
        $('#apply-filters-btn').on('click', applyFilters);

        // Initialize charts
        initMetricsCharts();

        // Load initial activity log
        loadActivityLog();

        // Start realtime counter updates
        updateRealtimeCounter();
        realtimeInterval = setInterval(updateRealtimeCounter, 5000);
    }

    /**
     * Toggle notification system on/off
     */
    function toggleSystem(enabled) {
        var $card = $('.mld-status-card');
        $card.addClass('loading');

        $.ajax({
            url: mldInstantAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'mld_toggle_notification_system',
                nonce: mldInstantAdmin.nonce,
                enabled: enabled
            },
            success: function(response) {
                $card.removeClass('loading');

                if (response.success) {
                    // Update UI
                    if (enabled) {
                        $card.removeClass('inactive').addClass('active');
                        $card.find('.status-message').html(
                            '<span class="dashicons dashicons-yes-alt"></span> ' +
                            'Instant notifications are <strong>active</strong>'
                        );
                    } else {
                        $card.removeClass('active').addClass('inactive');
                        $card.find('.status-message').html(
                            '<span class="dashicons dashicons-warning"></span> ' +
                            'Instant notifications are <strong>paused</strong>'
                        );
                    }

                    showNotice(response.data.message, 'success');
                } else {
                    showNotice('Failed to update system status', 'error');
                    // Revert toggle
                    $('#system-toggle').prop('checked', !enabled);
                }
            },
            error: function() {
                $card.removeClass('loading');
                showNotice('Network error. Please try again.', 'error');
                $('#system-toggle').prop('checked', !enabled);
            }
        });
    }

    /**
     * Send test notification
     */
    function sendTestNotification() {
        var $btn = $('#test-notification-btn');
        $btn.prop('disabled', true).text('Sending...');

        $.ajax({
            url: mldInstantAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'mld_test_notification',
                nonce: mldInstantAdmin.nonce
            },
            success: function(response) {
                $btn.prop('disabled', false).html(
                    '<span class="dashicons dashicons-megaphone"></span> Send Test Notification'
                );

                if (response.success) {
                    showNotice(response.data.message, 'success');
                    // Refresh activity after a short delay
                    setTimeout(loadRecentActivity, 2000);
                } else {
                    showNotice('Failed to send test notification', 'error');
                }
            },
            error: function() {
                $btn.prop('disabled', false).html(
                    '<span class="dashicons dashicons-megaphone"></span> Send Test Notification'
                );
                showNotice('Network error. Please try again.', 'error');
            }
        });
    }

    /**
     * Clear pending queue
     */
    function clearQueue() {
        if (!confirm('Are you sure you want to clear all pending notifications?')) {
            return;
        }

        var $btn = $('#clear-queue-btn');
        $btn.prop('disabled', true).text('Clearing...');

        $.ajax({
            url: mldInstantAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'mld_clear_notification_queue',
                nonce: mldInstantAdmin.nonce
            },
            success: function(response) {
                $btn.prop('disabled', false).html(
                    '<span class="dashicons dashicons-trash"></span> Clear Pending Queue'
                );

                if (response.success) {
                    showNotice(response.data.message, 'success');
                    loadDashboardStats();
                } else {
                    showNotice('Failed to clear queue', 'error');
                }
            },
            error: function() {
                $btn.prop('disabled', false).html(
                    '<span class="dashicons dashicons-trash"></span> Clear Pending Queue'
                );
                showNotice('Network error. Please try again.', 'error');
            }
        });
    }

    /**
     * Export statistics
     */
    function exportStatistics() {
        var $btn = $('#export-stats-btn');
        $btn.prop('disabled', true).text('Exporting...');

        $.ajax({
            url: mldInstantAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'mld_get_notification_stats',
                nonce: mldInstantAdmin.nonce
            },
            success: function(response) {
                $btn.prop('disabled', false).html(
                    '<span class="dashicons dashicons-download"></span> Export Statistics'
                );

                if (response.success) {
                    // Create CSV content
                    var csv = 'Metric,Value\n';
                    csv += 'Active Searches,' + response.data.active_searches + '\n';
                    csv += 'Notifications Today,' + response.data.notifications_today + '\n';
                    csv += 'Active Users,' + response.data.active_users + '\n';
                    csv += 'Avg Response Time,' + response.data.avg_response_time + 's\n';

                    if (response.data.chart_data) {
                        csv += '\nDaily Activity\n';
                        csv += 'Date,Count\n';
                        response.data.chart_data.forEach(function(item) {
                            csv += item.date + ',' + item.count + '\n';
                        });
                    }

                    // Download file
                    var blob = new Blob([csv], { type: 'text/csv' });
                    var url = window.URL.createObjectURL(blob);
                    var a = document.createElement('a');
                    a.href = url;
                    a.download = 'notification-stats-' + new Date().toISOString().slice(0,10) + '.csv';
                    document.body.appendChild(a);
                    a.click();
                    document.body.removeChild(a);
                    window.URL.revokeObjectURL(url);

                    showNotice('Statistics exported successfully', 'success');
                } else {
                    showNotice('Failed to export statistics', 'error');
                }
            },
            error: function() {
                $btn.prop('disabled', false).html(
                    '<span class="dashicons dashicons-download"></span> Export Statistics'
                );
                showNotice('Network error. Please try again.', 'error');
            }
        });
    }

    /**
     * Load dashboard statistics
     */
    function loadDashboardStats() {
        $.ajax({
            url: mldInstantAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'mld_get_notification_stats',
                nonce: mldInstantAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    updateStatsDisplay(response.data);
                    if (response.data.chart_data) {
                        updateActivityChart(response.data.chart_data);
                    }
                }
            }
        });
    }

    /**
     * Update stats display
     */
    function updateStatsDisplay(data) {
        $('.stat-card').each(function() {
            var $card = $(this);
            var $value = $card.find('h3');
            var label = $card.find('p').text().toLowerCase();

            if (label.includes('active') && label.includes('search')) {
                animateNumber($value, data.active_searches || 0);
            } else if (label.includes('today')) {
                animateNumber($value, data.notifications_today || 0);
            } else if (label.includes('users')) {
                animateNumber($value, data.active_users || 0);
            } else if (label.includes('response')) {
                $value.text((data.avg_response_time || 0) + 's');
            }
        });
    }

    /**
     * Animate number change
     */
    function animateNumber($element, newValue) {
        var currentValue = parseInt($element.text().replace(/,/g, '')) || 0;

        if (currentValue === newValue) return;

        $({ count: currentValue }).animate({ count: newValue }, {
            duration: 500,
            easing: 'swing',
            step: function() {
                $element.text(Math.floor(this.count).toLocaleString());
            },
            complete: function() {
                $element.text(newValue.toLocaleString());
            }
        });
    }

    /**
     * Load recent activity
     */
    function loadRecentActivity() {
        $.ajax({
            url: mldInstantAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'mld_get_recent_activity',
                nonce: mldInstantAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    $('#activity-tbody').html(response.data.html);
                }
            }
        });
    }

    /**
     * Initialize activity chart
     */
    function initActivityChart() {
        var ctx = document.getElementById('activity-chart');
        if (!ctx) return;

        activityChart = new Chart(ctx.getContext('2d'), {
            type: 'line',
            data: {
                labels: [],
                datasets: [{
                    label: 'Notifications',
                    data: [],
                    borderColor: '#2271b1',
                    backgroundColor: 'rgba(34, 113, 177, 0.1)',
                    tension: 0.4,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
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
     * Update activity chart with data
     */
    function updateActivityChart(chartData) {
        if (!activityChart) return;

        var labels = chartData.map(function(item) { return item.date; });
        var data = chartData.map(function(item) { return item.count; });

        activityChart.data.labels = labels;
        activityChart.data.datasets[0].data = data;
        activityChart.update();
    }

    /**
     * Apply filters on activity monitor
     */
    function applyFilters() {
        var filters = {
            date_start: $('#filter-date-start').val(),
            date_end: $('#filter-date-end').val(),
            status: $('#filter-status').val(),
            type: $('#filter-type').val()
        };

        var $container = $('#activity-log-container');
        $container.addClass('loading');

        $.ajax({
            url: mldInstantAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'mld_get_filtered_activity',
                nonce: mldInstantAdmin.nonce,
                filters: filters
            },
            success: function(response) {
                $container.removeClass('loading');
                if (response.success) {
                    $container.html(response.data.html);
                } else {
                    $container.html('<div class="log-entry error">Failed to load activity log</div>');
                }
            },
            error: function() {
                $container.removeClass('loading');
                $container.html('<div class="log-entry error">Network error. Please try again.</div>');
            }
        });
    }

    /**
     * Load activity log
     */
    function loadActivityLog() {
        var $container = $('#activity-log-container');
        if (!$container.length) return;

        $container.html('<div class="log-entry">Loading activity log...</div>');

        $.ajax({
            url: mldInstantAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'mld_get_activity_log',
                nonce: mldInstantAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    $container.html(response.data.html);
                } else {
                    $container.html('<div class="log-entry">No activity to display</div>');
                }
            },
            error: function() {
                $container.html('<div class="log-entry error">Failed to load activity log</div>');
            }
        });
    }

    /**
     * Update realtime counter
     */
    function updateRealtimeCounter() {
        $.ajax({
            url: mldInstantAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'mld_get_realtime_stats',
                nonce: mldInstantAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    var $counter = $('#realtime-counter .counter-value');
                    var newValue = response.data.rate || 0;
                    animateNumber($counter, newValue);
                }
            }
        });
    }

    /**
     * Initialize metrics charts on activity monitor
     */
    function initMetricsCharts() {
        // Delivery Rate Chart
        var deliveryCtx = document.getElementById('delivery-rate-chart');
        if (deliveryCtx) {
            deliveryRateChart = new Chart(deliveryCtx.getContext('2d'), {
                type: 'doughnut',
                data: {
                    labels: ['Sent', 'Failed', 'Throttled'],
                    datasets: [{
                        data: [85, 5, 10],
                        backgroundColor: ['#00ba37', '#d63638', '#f0b849']
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                boxWidth: 12,
                                padding: 10
                            }
                        }
                    }
                }
            });
        }

        // Response Time Chart
        var responseCtx = document.getElementById('response-time-chart');
        if (responseCtx) {
            responseTimeChart = new Chart(responseCtx.getContext('2d'), {
                type: 'bar',
                data: {
                    labels: ['<1s', '1-2s', '2-5s', '>5s'],
                    datasets: [{
                        label: 'Notifications',
                        data: [65, 25, 8, 2],
                        backgroundColor: '#2271b1'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true
                        }
                    }
                }
            });
        }

        // Channel Distribution Chart
        var channelCtx = document.getElementById('channel-distribution-chart');
        if (channelCtx) {
            channelDistributionChart = new Chart(channelCtx.getContext('2d'), {
                type: 'pie',
                data: {
                    labels: ['Push', 'Email', 'In-App'],
                    datasets: [{
                        data: [45, 35, 20],
                        backgroundColor: ['#2271b1', '#00ba37', '#826eb4']
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                boxWidth: 12,
                                padding: 10
                            }
                        }
                    }
                }
            });
        }
    }

    /**
     * Show admin notice
     */
    function showNotice(message, type) {
        var $notice = $('<div class="notice notice-' + type + ' is-dismissible"><p>' + message + '</p></div>');
        $('.wrap > h1').after($notice);

        // Auto-dismiss after 5 seconds
        setTimeout(function() {
            $notice.fadeOut(function() {
                $(this).remove();
            });
        }, 5000);

        // Allow manual dismiss
        $notice.on('click', '.notice-dismiss', function() {
            $notice.fadeOut(function() {
                $(this).remove();
            });
        });
    }

    // Cleanup on page unload
    $(window).on('unload', function() {
        if (refreshInterval) clearInterval(refreshInterval);
        if (realtimeInterval) clearInterval(realtimeInterval);
    });

})(jQuery);
