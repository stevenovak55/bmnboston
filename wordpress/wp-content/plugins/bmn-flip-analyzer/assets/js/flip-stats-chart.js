/**
 * FlipDashboard Stats & Chart — Summary stat cards and Chart.js city breakdown.
 *
 * Manages FD.chart (Chart.js instance).
 */
(function (FD, $) {
    'use strict';

    FD.stats.renderStats = function (summary) {
        $('#stat-total').text(summary.total || 0);
        $('#stat-viable').text(summary.viable || 0);
        $('#stat-avg-score').text(summary.avg_score ? summary.avg_score.toFixed(1) : '--');
        $('#stat-avg-roi').text(summary.avg_roi ? summary.avg_roi.toFixed(1) + '%' : '--');
        $('#stat-near-viable').text(summary.near_viable || 0);
        $('#stat-disqualified').text(summary.disqualified || 0);

        if (summary.last_run) {
            var d = new Date(summary.last_run + ' UTC');
            $('#flip-last-run').text('Last run: ' + d.toLocaleDateString() + ' ' + d.toLocaleTimeString());
        } else {
            $('#flip-last-run').text('No analysis run yet');
        }
    };

    FD.stats.renderChart = function (cities) {
        if (!cities || cities.length === 0) {
            $('#flip-chart-container').hide();
            $('#flip-chart-empty').show();
            return;
        }

        $('#flip-chart-container').show();
        $('#flip-chart-empty').hide();

        var labels = cities.map(function (c) { return c.city; });
        var viable = cities.map(function (c) { return parseInt(c.viable) || 0; });
        var disqualified = cities.map(function (c) {
            return (parseInt(c.total) || 0) - (parseInt(c.viable) || 0);
        });

        var ctx = document.getElementById('flip-city-chart');
        if (!ctx) return;

        if (FD.chart) {
            FD.chart.destroy();
        }

        FD.chart = new Chart(ctx.getContext('2d'), {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Viable (Score 60+)',
                        data: viable,
                        backgroundColor: '#00a32a',
                        borderRadius: 3,
                    },
                    {
                        label: 'Disqualified / Low Score',
                        data: disqualified,
                        backgroundColor: '#dcdcde',
                        borderRadius: 3,
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                        labels: { padding: 20, usePointStyle: true }
                    },
                    tooltip: {
                        callbacks: {
                            afterBody: function (items) {
                                var idx = items[0].dataIndex;
                                var city = cities[idx];
                                if (city && city.avg_score) {
                                    return 'Avg Score: ' + parseFloat(city.avg_score).toFixed(1);
                                }
                                return '';
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { display: false }
                    },
                    y: {
                        beginAtZero: true,
                        ticks: { stepSize: 1, precision: 0 },
                        grid: { color: '#f0f0f0' }
                    }
                }
            }
        });
    };

    FD.stats.populateCityFilter = function (cities) {
        var $select = $('#filter-city');
        var current = $select.val();
        $select.find('option:not(:first)').remove();
        cities.forEach(function (city) {
            $select.append('<option value="' + FD.helpers.escapeHtml(city) + '">' + FD.helpers.escapeHtml(city) + '</option>');
        });
        if (current) $select.val(current);
    };

})(window.FlipDashboard, jQuery);
