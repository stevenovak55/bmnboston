/**
 * FlipDashboard Reports — Saved reports panel, load/rerun/delete,
 * report name prompt, monitor creation dialog.
 *
 * Manages FD.activeReportId and FD.reports.* namespace.
 */
(function (FD, $) {
    'use strict';

    var h = FD.helpers;

    /* ─── Init ──────────────────────────────────────────── */

    FD.reports.init = function () {
        var reports = flipData.reports || [];
        FD.reports.renderList(reports);
        $('#flip-reports-count').text(reports.length);

        // Toggle panel
        $('#flip-reports-toggle').on('click', function () {
            var $body  = $('#flip-reports-body');
            var $arrow = $(this).find('.flip-reports-arrow');
            $body.slideToggle(200);
            $arrow.toggleClass('flip-reports-arrow-open');
        });

        // Monitor dialog
        $('#flip-create-monitor-btn').on('click', FD.reports.showMonitorDialog);
        $('#flip-monitor-cancel').on('click', function () {
            $('#flip-monitor-dialog').slideUp(200);
        });
        $('#flip-monitor-confirm').on('click', FD.reports._createMonitor);

        // Report name prompt
        $('#flip-report-run-cancel').on('click', function () {
            $('#flip-report-name-prompt').slideUp(200);
        });
        $('#flip-report-run-confirm').on('click', function () {
            var name = $('#flip-report-name').val().trim();
            $('#flip-report-name-prompt').slideUp(200);
            if (FD.reports._runCallback) {
                FD.reports._runCallback(name);
                FD.reports._runCallback = null;
            }
        });
        $('#flip-report-name').on('keypress', function (e) {
            if (e.which === 13) {
                e.preventDefault();
                $('#flip-report-run-confirm').click();
            }
        });
    };

    /* ─── Report List Rendering ─────────────────────────── */

    FD.reports.renderList = function (reports) {
        var $list = $('#flip-reports-list');
        $list.empty();

        if (!reports || reports.length === 0) {
            $list.html('<p class="flip-reports-empty">No saved reports yet. Run an analysis to create one.</p>');
            $('#flip-reports-count').text('0');
            return;
        }

        $('#flip-reports-count').text(reports.length);

        reports.forEach(function (r) {
            var isMonitor = r.type === 'monitor';
            var icon = isMonitor ? 'dashicons-visibility' : 'dashicons-media-document';
            var isActive = FD.activeReportId === r.id;
            var date = r.last_run_date || r.run_date || r.created_at;
            var dateStr = date ? new Date(date.replace(/-/g, '/')).toLocaleDateString('en-US', {
                month: 'short', day: 'numeric', year: 'numeric'
            }) : '';

            var meta = r.property_count + ' properties, ' + r.viable_count + ' viable';
            if (r.run_count > 1) {
                meta += ' | ' + r.run_count + ' runs';
            }
            if (isMonitor && r.monitor_last_new_count > 0) {
                meta = '<span class="flip-monitor-new-badge">' + r.monitor_last_new_count + ' new</span> ' + meta;
            }

            var freqBadge = '';
            if (isMonitor && r.monitor_frequency) {
                freqBadge = '<span class="flip-freq-badge">' +
                    r.monitor_frequency.replace('_', ' ') + '</span>';
            }

            var html = '<div class="flip-report-item' + (isActive ? ' flip-report-active' : '') + '" data-id="' + r.id + '">'
                + '<div class="flip-report-info">'
                + '<span class="dashicons ' + icon + ' flip-report-icon"></span>'
                + '<div class="flip-report-text">'
                + '<div class="flip-report-name">' + h.escapeHtml(r.name) + ' ' + freqBadge + '</div>'
                + '<div class="flip-report-meta">' + meta + ' | ' + dateStr + '</div>'
                + '</div>'
                + '</div>'
                + '<div class="flip-report-actions">'
                + '<button class="button button-small flip-report-rerun" data-id="' + r.id + '" title="Re-run with fresh data">'
                + '<span class="dashicons dashicons-update"></span></button>'
                + '<button class="button button-small flip-report-rename" data-id="' + r.id + '" data-name="' + h.escapeHtml(r.name) + '" title="Rename">'
                + '<span class="dashicons dashicons-edit"></span></button>'
                + '<button class="button button-small flip-report-delete" data-id="' + r.id + '" title="Delete">'
                + '<span class="dashicons dashicons-trash"></span></button>'
                + '</div>'
                + '</div>';

            $list.append(html);
        });

        // Bind events
        $list.off('click', '.flip-report-item');
        $list.on('click', '.flip-report-item', function (e) {
            if ($(e.target).closest('button').length) return;
            var id = $(this).data('id');
            FD.reports.loadReport(id);
        });

        $list.off('click', '.flip-report-rerun');
        $list.on('click', '.flip-report-rerun', function (e) {
            e.stopPropagation();
            FD.reports.rerunReport($(this).data('id'));
        });

        $list.off('click', '.flip-report-rename');
        $list.on('click', '.flip-report-rename', function (e) {
            e.stopPropagation();
            var id = $(this).data('id');
            var currentName = $(this).data('name');
            FD.reports.renameReport(id, currentName);
        });

        $list.off('click', '.flip-report-delete');
        $list.on('click', '.flip-report-delete', function (e) {
            e.stopPropagation();
            FD.reports.deleteReport($(this).data('id'));
        });
    };

    /* ─── Load Report ───────────────────────────────────── */

    FD.reports.loadReport = function (id) {
        $.ajax({
            url: flipData.ajaxUrl,
            method: 'POST',
            data: {
                action: 'flip_load_report',
                nonce: flipData.nonce,
                report_id: id,
            },
            success: function (response) {
                if (response.success) {
                    FD.activeReportId = id;
                    FD.data = response.data;
                    FD.stats.renderStats(FD.data.summary);
                    FD.stats.renderChart(FD.data.summary.cities || []);
                    FD.filters.applyFilters();

                    if (response.data.report) {
                        FD.reports.showReportHeader(response.data.report);
                    }

                    // Highlight active in list
                    $('.flip-report-item').removeClass('flip-report-active');
                    $('.flip-report-item[data-id="' + id + '"]').addClass('flip-report-active');
                } else {
                    alert('Error loading report: ' + (response.data || 'Unknown error'));
                }
            },
            error: function (xhr, status) {
                alert('Failed to load report: ' + status);
            }
        });
    };

    /* ─── Re-run Report ─────────────────────────────────── */

    FD.reports.rerunReport = function (id) {
        if (!confirm('Re-run this report with fresh MLS data? This will replace the existing results.')) {
            return;
        }

        $('#flip-modal-overlay').show();

        $.ajax({
            url: flipData.ajaxUrl,
            method: 'POST',
            timeout: 300000,
            data: {
                action: 'flip_rerun_report',
                nonce: flipData.nonce,
                report_id: id,
            },
            success: function (response) {
                $('#flip-modal-overlay').hide();

                if (response.success) {
                    var d = response.data;
                    alert('Re-run complete: ' + d.analyzed + ' analyzed, ' + d.disqualified + ' disqualified.');

                    FD.activeReportId = id;

                    if (d.dashboard) {
                        FD.data = d.dashboard;
                        FD.stats.renderStats(FD.data.summary);
                        FD.stats.renderChart(FD.data.summary.cities || []);
                        FD.filters.applyFilters();
                    }

                    if (d.reports) {
                        FD.reports.renderList(d.reports);
                    }

                    if (d.dashboard && d.dashboard.report) {
                        FD.reports.showReportHeader(d.dashboard.report);
                    }
                } else {
                    alert('Error: ' + (response.data || 'Unknown error'));
                }
            },
            error: function (xhr, status) {
                $('#flip-modal-overlay').hide();
                if (status === 'timeout') {
                    alert('Re-run timed out. Check server logs.');
                } else {
                    alert('Request failed: ' + status);
                }
            }
        });
    };

    /* ─── Rename Report ─────────────────────────────────── */

    FD.reports.renameReport = function (id, currentName) {
        var newName = prompt('Rename report:', currentName);
        if (!newName || newName.trim() === '' || newName.trim() === currentName) return;

        $.ajax({
            url: flipData.ajaxUrl,
            method: 'POST',
            data: {
                action: 'flip_rename_report',
                nonce: flipData.nonce,
                report_id: id,
                name: newName.trim(),
            },
            success: function (response) {
                if (response.success && response.data.reports) {
                    FD.reports.renderList(response.data.reports);
                }
            },
            error: function (xhr, status) {
                alert('Failed to rename report: ' + status);
            }
        });
    };

    /* ─── Delete Report ─────────────────────────────────── */

    FD.reports.deleteReport = function (id) {
        if (!confirm('Delete this report? The analysis data will be preserved in the database.')) {
            return;
        }

        $.ajax({
            url: flipData.ajaxUrl,
            method: 'POST',
            data: {
                action: 'flip_delete_report',
                nonce: flipData.nonce,
                report_id: id,
            },
            success: function (response) {
                if (response.success) {
                    // If viewing the deleted report, clear state before re-rendering
                    var wasViewing = (FD.activeReportId === id);
                    if (wasViewing) {
                        FD.activeReportId = null;
                    }

                    if (response.data.reports) {
                        FD.reports.renderList(response.data.reports);
                    }

                    if (wasViewing) {
                        FD.reports.hideReportHeader();
                        FD.ajax.refreshData();
                    }
                }
            },
            error: function (xhr, status) {
                alert('Failed to delete report: ' + status);
            }
        });
    };

    /* ─── Report Context Bar ────────────────────────────── */

    FD.reports.showReportHeader = function (report) {
        var date = report.last_run_date || report.run_date;
        var dateStr = date ? new Date(date.replace(/-/g, '/')).toLocaleDateString('en-US', {
            month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit'
        }) : '';

        var typeIcon = report.type === 'monitor' ? 'dashicons-visibility' : 'dashicons-portfolio';

        var html = '<span class="dashicons ' + typeIcon + '"></span> '
            + '<strong>' + h.escapeHtml(report.name) + '</strong>'
            + (dateStr ? ' <span class="flip-report-context-date">(Run: ' + dateStr + ')</span>' : '')
            + '<button id="flip-back-to-latest" class="button button-small flip-back-btn">'
            + '<span class="dashicons dashicons-arrow-left-alt"></span> Back to Latest</button>';

        $('#flip-report-context').html(html).slideDown(200);
        $('#flip-back-to-latest').on('click', FD.reports.backToLatest);
    };

    FD.reports.hideReportHeader = function () {
        $('#flip-report-context').slideUp(200);
    };

    FD.reports.backToLatest = function () {
        FD.activeReportId = null;
        FD.reports.hideReportHeader();
        $('.flip-report-item').removeClass('flip-report-active');
        FD.ajax.refreshData();
    };

    /* ─── Report Name Prompt ────────────────────────────── */

    FD.reports._runCallback = null;

    FD.reports.promptName = function (callback) {
        var cities = FD.data.cities || [];
        var defaultName = cities.join(', ') + ' - ' + new Date().toLocaleDateString('en-US', {
            month: 'short', day: 'numeric', year: 'numeric'
        });

        $('#flip-report-name').val(defaultName);
        $('#flip-report-name-prompt').slideDown(200);
        $('#flip-report-name').focus().select();

        FD.reports._runCallback = callback;
    };

    /* ─── Monitor Dialog ────────────────────────────────── */

    FD.reports.showMonitorDialog = function () {
        var cities = FD.data.cities || [];
        $('#flip-monitor-name').val(cities.join(', ') + ' Monitor');
        $('#flip-monitor-dialog').slideDown(200);
        $('#flip-monitor-name').focus();
    };

    FD.reports._createMonitor = function () {
        var name      = $('#flip-monitor-name').val().trim();
        var frequency = $('#flip-monitor-frequency').val();
        var email     = $('#flip-monitor-email').val().trim();

        if (!name) {
            alert('Please enter a monitor name.');
            return;
        }

        $('#flip-monitor-confirm').prop('disabled', true).text('Creating...');

        $.ajax({
            url: flipData.ajaxUrl,
            method: 'POST',
            data: {
                action: 'flip_create_monitor',
                nonce: flipData.nonce,
                name: name,
                frequency: frequency,
                email: email,
            },
            success: function (response) {
                $('#flip-monitor-confirm').prop('disabled', false).text('Create Monitor');
                $('#flip-monitor-dialog').slideUp(200);

                if (response.success) {
                    alert(response.data.message);
                    if (response.data.reports) {
                        FD.reports.renderList(response.data.reports);
                    }
                } else {
                    alert('Error: ' + (response.data || 'Unknown error'));
                }
            },
            error: function () {
                $('#flip-monitor-confirm').prop('disabled', false).text('Create Monitor');
                alert('Request failed.');
            }
        });
    };

})(window.FlipDashboard, jQuery);
