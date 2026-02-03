/**
 * SN Appointment Booking - Analytics Dashboard
 *
 * Handles Chart.js chart rendering and date range filtering.
 *
 * @package SN_Appointment_Booking
 * @since 1.4.0
 */

(function($) {
    'use strict';

    // Chart instances
    let byDayChart = null;
    let byHourChart = null;
    let byTypeChart = null;
    let trendChart = null;
    let statusChart = null;

    // Chart colors
    const chartColors = {
        primary: '#4F46E5',
        primaryLight: 'rgba(79, 70, 229, 0.1)',
        success: '#10B981',
        warning: '#F59E0B',
        danger: '#EF4444',
        info: '#3B82F6',
        gray: '#6B7280',
        palette: [
            '#4F46E5', '#10B981', '#F59E0B', '#EF4444', '#3B82F6',
            '#8B5CF6', '#EC4899', '#14B8A6', '#F97316', '#6366F1'
        ]
    };

    /**
     * Initialize analytics dashboard
     */
    function init() {
        // Apply custom date range
        $('#snab-apply-dates').on('click', function() {
            const startDate = $('#snab-start-date').val();
            const endDate = $('#snab-end-date').val();
            if (startDate && endDate) {
                loadAnalytics('custom', startDate, endDate);
            }
        });

        // Quick range buttons
        $('.snab-quick-ranges .button').on('click', function() {
            const range = $(this).data('range');
            let endDate = new Date();
            let startDate = new Date();

            // Format dates as YYYY-MM-DD
            const formatDate = (d) => d.toISOString().split('T')[0];

            if (range === 'upcoming') {
                // Today to 90 days from now
                startDate = new Date();
                endDate = new Date();
                endDate.setDate(endDate.getDate() + 90);
            } else if (range === 'all') {
                // All time: from 2 years ago to 1 year from now
                startDate.setFullYear(startDate.getFullYear() - 2);
                endDate.setFullYear(endDate.getFullYear() + 1);
            } else {
                // Past X days
                const days = parseInt(range);
                startDate.setDate(endDate.getDate() - days);
            }

            // Update the date inputs
            $('#snab-start-date').val(formatDate(startDate));
            $('#snab-end-date').val(formatDate(endDate));

            // Highlight active button
            $('.snab-quick-ranges .button').removeClass('active');
            $(this).addClass('active');

            // Load data
            loadAnalytics('custom', formatDate(startDate), formatDate(endDate));
        });

        // Export button
        $('#snab-export-csv').on('click', function() {
            exportAnalytics();
        });

        // Initial load with values from date inputs
        const startDate = $('#snab-start-date').val();
        const endDate = $('#snab-end-date').val();
        if (startDate && endDate) {
            loadAnalytics('custom', startDate, endDate);
        }
    }

    /**
     * Load analytics data via AJAX
     */
    function loadAnalytics(range, startDate, endDate) {
        const $loading = $('#snab-loading');
        const $metrics = $('#snab-metrics');

        // Show loading, hide content
        $loading.show();

        const data = {
            action: 'snab_get_analytics',
            nonce: snabAnalytics.nonce
        };

        if (startDate && endDate) {
            data.start_date = startDate;
            data.end_date = endDate;
        }

        $.ajax({
            url: snabAnalytics.ajaxUrl,
            type: 'POST',
            data: data,
            success: function(response) {
                $loading.hide();
                if (response.success) {
                    updateDashboard(response.data);
                } else {
                    showError(response.data.message || snabAnalytics.i18n.error);
                }
            },
            error: function(xhr, status, error) {
                $loading.hide();
                console.error('Analytics AJAX error:', status, error);
                showError(snabAnalytics.i18n.error);
            }
        });
    }

    /**
     * Update dashboard with new data
     */
    function updateDashboard(data) {
        // Update key metrics
        updateMetrics(data.metrics);

        // Update charts
        renderByDayChart(data.by_day);
        renderByHourChart(data.by_hour);
        renderByTypeChart(data.by_type);
        renderTrendChart(data.trend);
        renderStatusChart(data.by_status);

        // Update tables
        updateTopClients(data.top_clients);
        updatePopularSlots(data.popular_slots);
    }

    /**
     * Update key metrics cards
     */
    function updateMetrics(metrics) {
        $('#metric-total').text(metrics.total_appointments);
        $('#metric-completion').text(metrics.completion_rate + '%');
        $('#metric-noshow').text(metrics.no_show_rate + '%');
        $('#metric-cancelled').text(metrics.cancellation_rate + '%');
        $('#metric-leadtime').text(metrics.avg_lead_time);
        $('#metric-clients').text(metrics.unique_clients);
    }

    /**
     * Render appointments by day of week chart
     */
    function renderByDayChart(data) {
        const ctx = document.getElementById('chart-day');
        if (!ctx) return;

        // Destroy existing chart
        if (byDayChart) {
            byDayChart.destroy();
        }

        const labels = data.map(item => item.short || item.name);
        const values = data.map(item => parseInt(item.count));

        byDayChart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: snabAnalytics.i18n.appointments,
                    data: values,
                    backgroundColor: chartColors.primary,
                    borderColor: chartColors.primary,
                    borderWidth: 1,
                    borderRadius: 4
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
                            stepSize: 1
                        }
                    }
                }
            }
        });
    }

    /**
     * Render appointments by hour chart
     */
    function renderByHourChart(data) {
        const ctx = document.getElementById('chart-hour');
        if (!ctx) return;

        // Destroy existing chart
        if (byHourChart) {
            byHourChart.destroy();
        }

        const labels = data.map(item => item.label || item.hour_label);
        const values = data.map(item => parseInt(item.count));

        byHourChart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: snabAnalytics.i18n.appointments,
                    data: values,
                    backgroundColor: chartColors.info,
                    borderColor: chartColors.info,
                    borderWidth: 1,
                    borderRadius: 4
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
                            stepSize: 1
                        }
                    }
                }
            }
        });
    }

    /**
     * Render appointments by type chart (pie)
     */
    function renderByTypeChart(data) {
        const ctx = document.getElementById('chart-type');
        if (!ctx) return;

        // Destroy existing chart
        if (byTypeChart) {
            byTypeChart.destroy();
        }

        const labels = data.map(item => item.name || item.type_name);
        const values = data.map(item => parseInt(item.count));
        const colors = data.map((item, index) => item.color || chartColors.palette[index % chartColors.palette.length]);

        byTypeChart = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: labels,
                datasets: [{
                    data: values,
                    backgroundColor: colors,
                    borderWidth: 2,
                    borderColor: '#ffffff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'right',
                        labels: {
                            boxWidth: 12,
                            padding: 10
                        }
                    }
                }
            }
        });
    }

    /**
     * Render trend chart (line)
     */
    function renderTrendChart(data) {
        const ctx = document.getElementById('chart-trend');
        if (!ctx) return;

        // Destroy existing chart
        if (trendChart) {
            trendChart.destroy();
        }

        const labels = data.map(item => item.label || item.period);
        const values = data.map(item => parseInt(item.total || item.count));

        trendChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: snabAnalytics.i18n.appointments,
                    data: values,
                    borderColor: chartColors.primary,
                    backgroundColor: chartColors.primaryLight,
                    borderWidth: 2,
                    fill: true,
                    tension: 0.3,
                    pointBackgroundColor: chartColors.primary,
                    pointRadius: 4,
                    pointHoverRadius: 6
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
                            stepSize: 1
                        }
                    }
                }
            }
        });
    }

    /**
     * Render status breakdown chart (pie)
     */
    function renderStatusChart(data) {
        const ctx = document.getElementById('chart-status');
        if (!ctx) return;

        // Destroy existing chart
        if (statusChart) {
            statusChart.destroy();
        }

        const labels = data.map(item => item.label || item.status);
        const values = data.map(item => parseInt(item.count));
        const colors = data.map(item => item.color || chartColors.gray);

        statusChart = new Chart(ctx, {
            type: 'pie',
            data: {
                labels: labels,
                datasets: [{
                    data: values,
                    backgroundColor: colors,
                    borderWidth: 2,
                    borderColor: '#ffffff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'right',
                        labels: {
                            boxWidth: 12,
                            padding: 10
                        }
                    }
                }
            }
        });
    }

    /**
     * Update top clients table
     */
    function updateTopClients(clients) {
        const $tbody = $('#table-clients tbody');
        $tbody.empty();

        if (!clients || clients.length === 0) {
            $tbody.append('<tr><td colspan="3" style="text-align: center; color: #6b7280;">' + snabAnalytics.i18n.noData + '</td></tr>');
            return;
        }

        clients.forEach(function(client) {
            $tbody.append(
                '<tr>' +
                '<td>' + escapeHtml(client.client_name) + '</td>' +
                '<td><strong>' + (client.appointment_count || client.count) + '</strong></td>' +
                '<td>' + (client.completed || 0) + '</td>' +
                '</tr>'
            );
        });
    }

    /**
     * Update popular slots table
     */
    function updatePopularSlots(slots) {
        const $tbody = $('#table-slots tbody');
        $tbody.empty();

        if (!slots || slots.length === 0) {
            $tbody.append('<tr><td colspan="3" style="text-align: center; color: #6b7280;">' + snabAnalytics.i18n.noData + '</td></tr>');
            return;
        }

        slots.forEach(function(slot) {
            $tbody.append(
                '<tr>' +
                '<td>' + escapeHtml(slot.day_name) + '</td>' +
                '<td>' + escapeHtml(slot.time_slot) + '</td>' +
                '<td><strong>' + slot.count + '</strong></td>' +
                '</tr>'
            );
        });
    }

    /**
     * Export analytics to CSV
     */
    function exportAnalytics() {
        const startDate = $('#snab-start-date').val();
        const endDate = $('#snab-end-date').val();

        if (!startDate || !endDate) {
            alert('Please select a date range first.');
            return;
        }

        let url = snabAnalytics.ajaxUrl + '?action=snab_export_analytics&nonce=' + snabAnalytics.nonce;
        url += '&start_date=' + startDate;
        url += '&end_date=' + endDate;

        window.location.href = url;
    }

    /**
     * Show error message
     */
    function showError(message) {
        alert(message);
    }

    /**
     * Escape HTML entities
     */
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Initialize on document ready
    $(document).ready(init);

})(jQuery);
