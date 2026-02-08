/**
 * FlipDashboard AJAX — Analysis execution, PDF generation, force analyze,
 * data refresh, and CSV export.
 *
 * AJAX handlers that write to FD.data after success.
 */
(function (FD, $) {
    'use strict';

    var h = FD.helpers;
    var BATCH_SIZE = 5;

    // --- Batched analysis state ---
    var _cancelRequested = false;
    var _elapsedTimer = null;
    var _startTime = null;
    var _completedCount = 0;

    /**
     * Show the report name prompt and run analysis when confirmed.
     */
    FD.ajax.runAnalysis = function () {
        FD.reports.promptName(function (reportName) {
            FD.ajax._executeAnalysis(reportName);
        });
    };

    // --- Progress modal helpers ---

    function showProgress(title, total, costPerItem) {
        _cancelRequested = false;
        _completedCount = 0;
        $('#flip-progress-title').text(title || 'Preparing Analysis...');
        $('#flip-progress-bar').css('width', '0%');
        $('#flip-progress-pct').text('0 of ' + (total || '?'));
        $('#flip-prog-viable').text('0');
        $('#flip-prog-dq').text('0');
        $('#flip-prog-elapsed').text('0:00');
        $('#flip-progress-counts').hide();
        $('#flip-progress-log').empty();
        $('#flip-cancel-analysis').show().prop('disabled', false).text('Cancel');
        $('#flip-modal-overlay').show();
        $('#flip-run-analysis').prop('disabled', true);

        // Cost estimate
        if (costPerItem && total) {
            var est = (total * costPerItem).toFixed(2);
            $('#flip-prog-cost').html('Est. cost: <strong>~$' + est + '</strong>');
        } else {
            $('#flip-prog-cost').html('Est. cost: <strong>Free</strong>');
        }

        _startTime = Date.now();
        _elapsedTimer = setInterval(updateElapsed, 1000);
    }

    function logInit(msg) {
        $('#flip-progress-log').append('<div class="flip-log-init">' + msg + '</div>');
    }

    function logProperties(properties, startIdx) {
        var $log = $('#flip-progress-log');
        for (var i = 0; i < properties.length; i++) {
            var p = properties[i];
            var idx = startIdx + i + 1;
            var scoreVal = p.total_score ? p.total_score.toFixed(0) : '--';
            var scoreClass = p.disqualified ? 'score-low'
                : p.total_score >= 65 ? 'score-high'
                : p.total_score >= 45 ? 'score-mid' : 'score-low';
            var strat = p.disqualified ? 'dq' : (p.best_strategy || 'dq');
            var stratLabel = p.disqualified ? 'DQ' : (p.best_strategy ? p.best_strategy.toUpperCase() : '--');

            $log.append(
                '<div class="flip-log-row">'
                + '<span class="flip-log-idx">' + idx + '</span>'
                + '<span class="flip-log-addr">' + (p.address || 'Unknown') + '</span>'
                + '<span class="flip-log-city">' + (p.city || '') + '</span>'
                + '<span class="flip-log-score ' + scoreClass + '">' + scoreVal + '</span>'
                + '<span class="flip-log-strategy strat-' + strat + '">' + stratLabel + '</span>'
                + '</div>'
            );
        }
        // Auto-scroll to bottom
        $log.scrollTop($log[0].scrollHeight);
    }

    function updateProgress(completed, total, viable, dq) {
        var pct = total > 0 ? Math.round(completed / total * 100) : 0;
        $('#flip-progress-bar').css('width', pct + '%');
        $('#flip-progress-pct').text(completed + ' of ' + total);
        $('#flip-prog-viable').text(viable);
        $('#flip-prog-dq').text(dq);
        $('#flip-progress-counts').show();
    }

    function updateElapsed() {
        if (!_startTime) return;
        var secs = Math.floor((Date.now() - _startTime) / 1000);
        var m = Math.floor(secs / 60);
        var s = secs % 60;
        $('#flip-prog-elapsed').text(m + ':' + (s < 10 ? '0' : '') + s);
    }

    function hideProgress() {
        $('#flip-modal-overlay').hide();
        $('#flip-run-analysis').prop('disabled', false);
        if (_elapsedTimer) {
            clearInterval(_elapsedTimer);
            _elapsedTimer = null;
        }
        _startTime = null;
    }

    function finishDashboard(d) {
        if (d.report_id) {
            FD.activeReportId = d.report_id;
        }
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
    }

    // --- Cancel button ---

    $(document).on('click', '#flip-cancel-analysis', function () {
        _cancelRequested = true;
        $(this).prop('disabled', true).text('Cancelling...');
        $('#flip-progress-title').text('Cancelling...');
    });

    // --- Batched analysis execution ---

    /**
     * Execute analysis in batches: init → batch×N → finalize.
     */
    FD.ajax._executeAnalysis = function (reportName) {
        showProgress('Preparing Analysis...');
        logInit('Fetching property list...');

        // Phase 1: Init
        $.ajax({
            url: flipData.ajaxUrl,
            method: 'POST',
            timeout: 30000,
            data: {
                action: 'flip_analysis_init',
                nonce: flipData.nonce,
                report_name: reportName || '',
            },
            success: function (response) {
                if (!response.success) {
                    hideProgress();
                    alert('Error: ' + (response.data || 'Unknown error'));
                    return;
                }

                var d = response.data;
                var listingIds = d.listing_ids || [];
                var total = listingIds.length;

                if (total === 0) {
                    hideProgress();
                    alert('No matching properties found for the current filters.');
                    return;
                }

                $('#flip-progress-title').text('Running Analysis...');
                $('#flip-progress-pct').text('0 of ' + total);
                logInit('Found ' + total + ' properties. Starting analysis...');

                // Split into batches
                var batches = [];
                for (var i = 0; i < listingIds.length; i += BATCH_SIZE) {
                    batches.push(listingIds.slice(i, i + BATCH_SIZE));
                }

                // Run batches sequentially
                var completed = 0;
                var totalViable = 0;
                var totalDQ = 0;
                var batchIdx = 0;

                function runNextBatch() {
                    if (_cancelRequested || batchIdx >= batches.length) {
                        finalize(d.report_id, completed, _cancelRequested);
                        return;
                    }

                    var batch = batches[batchIdx];
                    batchIdx++;

                    $.ajax({
                        url: flipData.ajaxUrl,
                        method: 'POST',
                        timeout: 120000,
                        data: {
                            action: 'flip_analysis_batch',
                            nonce: flipData.nonce,
                            report_id: d.report_id,
                            listing_ids: JSON.stringify(batch),
                            run_date: d.run_date,
                        },
                        success: function (resp) {
                            if (!resp.success) {
                                hideProgress();
                                alert('Batch error: ' + (resp.data || 'Unknown error') + '\nPartial results saved.');
                                finalize(d.report_id, completed, false);
                                return;
                            }

                            var bd = resp.data;
                            var batchCount = bd.batch_analyzed || batch.length;
                            if (bd.properties && bd.properties.length > 0) {
                                logProperties(bd.properties, completed);
                            }
                            completed += batchCount;
                            totalViable += bd.batch_viable || 0;
                            totalDQ += bd.batch_disqualified || 0;

                            updateProgress(completed, total, totalViable, totalDQ);
                            runNextBatch();
                        },
                        error: function (xhr, status) {
                            // Retry once on failure
                            $.ajax({
                                url: flipData.ajaxUrl,
                                method: 'POST',
                                timeout: 120000,
                                data: {
                                    action: 'flip_analysis_batch',
                                    nonce: flipData.nonce,
                                    report_id: d.report_id,
                                    listing_ids: JSON.stringify(batch),
                                    run_date: d.run_date,
                                },
                                success: function (resp) {
                                    if (resp.success) {
                                        var bd = resp.data;
                                        var batchCount = bd.batch_analyzed || batch.length;
                                        if (bd.properties && bd.properties.length > 0) {
                                            logProperties(bd.properties, completed);
                                        }
                                        completed += batchCount;
                                        totalViable += bd.batch_viable || 0;
                                        totalDQ += bd.batch_disqualified || 0;
                                        updateProgress(completed, total, totalViable, totalDQ);
                                    }
                                    runNextBatch();
                                },
                                error: function () {
                                    // Give up on this batch, continue with rest
                                    logInit('Batch failed, skipping ' + batch.length + ' properties...');
                                    completed += batch.length;
                                    updateProgress(completed, total, totalViable, totalDQ);
                                    runNextBatch();
                                }
                            });
                        }
                    });
                }

                runNextBatch();
            },
            error: function (xhr, status) {
                hideProgress();
                alert('Failed to initialize analysis: ' + status);
            }
        });
    };

    /**
     * Phase 3: Finalize — update report metadata and refresh dashboard.
     */
    function finalize(reportId, totalAnalyzed, wasCancelled) {
        $('#flip-progress-title').text(wasCancelled ? 'Saving partial results...' : 'Finalizing...');
        logInit(wasCancelled ? 'Saving partial results...' : 'Updating dashboard...');
        $('#flip-cancel-analysis').hide();

        $.ajax({
            url: flipData.ajaxUrl,
            method: 'POST',
            timeout: 30000,
            data: {
                action: 'flip_analysis_finalize',
                nonce: flipData.nonce,
                report_id: reportId,
                total_analyzed: totalAnalyzed,
                cancelled: wasCancelled ? '1' : '0',
            },
            success: function (response) {
                hideProgress();
                if (response.success) {
                    var d = response.data;
                    finishDashboard(d);

                    var msg = wasCancelled
                        ? 'Analysis cancelled. Partial results saved.'
                        : 'Analysis complete!';
                    alert(msg);
                } else {
                    alert('Finalize error: ' + (response.data || 'Unknown'));
                    FD.ajax.refreshData();
                }
            },
            error: function () {
                hideProgress();
                alert('Failed to finalize. Refreshing data...');
                FD.ajax.refreshData();
            }
        });
    }

    /**
     * Public batched analysis runner for use by report rerun.
     *
     * @param {number[]} listingIds Array of listing IDs to analyze.
     * @param {number}   reportId   Report to scope results to.
     * @param {string}   runDate    Run date string for consistency across batches.
     */
    FD.ajax.runBatchedAnalysis = function (listingIds, reportId, runDate) {
        var total = listingIds.length;
        showProgress('Re-running Analysis...', total);
        logInit('Found ' + total + ' properties. Starting analysis...');

        var batches = [];
        for (var i = 0; i < listingIds.length; i += BATCH_SIZE) {
            batches.push(listingIds.slice(i, i + BATCH_SIZE));
        }

        var completed = 0;
        var totalViable = 0;
        var totalDQ = 0;
        var batchIdx = 0;

        function runNext() {
            if (_cancelRequested || batchIdx >= batches.length) {
                finalize(reportId, completed, _cancelRequested);
                return;
            }

            var batch = batches[batchIdx];
            batchIdx++;

            $.ajax({
                url: flipData.ajaxUrl,
                method: 'POST',
                timeout: 120000,
                data: {
                    action: 'flip_analysis_batch',
                    nonce: flipData.nonce,
                    report_id: reportId,
                    listing_ids: JSON.stringify(batch),
                    run_date: runDate,
                },
                success: function (resp) {
                    if (!resp.success) {
                        finalize(reportId, completed, false);
                        return;
                    }
                    var bd = resp.data;
                    var batchCount = bd.batch_analyzed || batch.length;
                    if (bd.properties && bd.properties.length > 0) {
                        logProperties(bd.properties, completed);
                    }
                    completed += batchCount;
                    totalViable += bd.batch_viable || 0;
                    totalDQ += bd.batch_disqualified || 0;
                    updateProgress(completed, total, totalViable, totalDQ);
                    runNext();
                },
                error: function () {
                    logInit('Batch failed, skipping ' + batch.length + ' properties...');
                    completed += batch.length;
                    updateProgress(completed, total, totalViable, totalDQ);
                    runNext();
                }
            });
        }

        runNext();
    };

    FD.ajax.runPhotoAnalysis = function () {
        var viable = (FD.data.results || []).filter(function (r) {
            return !r.disqualified && r.total_score >= 40;
        });
        var count = viable.length;

        if (count === 0) {
            alert('No viable properties to analyze. Run the data analysis first.');
            return;
        }

        var estCost = (count * 0.04).toFixed(2);
        if (!confirm('Run photo analysis on ' + count + ' viable properties?\n\n'
            + 'Estimated API cost: ~$' + estCost + '\n'
            + 'This uses Claude Vision to analyze property photos and refine rehab estimates.\n\n'
            + 'This may take 1-5 minutes.')) {
            return;
        }

        showProgress('Running Photo Analysis...', count, 0.04);
        logInit('Analyzing photos for ' + count + ' properties...');
        $('#flip-cancel-analysis').hide(); // Photo analysis is single-request, no cancel
        $('#flip-progress-counts').hide();
        $('#flip-run-photos').prop('disabled', true);

        $.ajax({
            url: flipData.ajaxUrl,
            method: 'POST',
            timeout: 600000,
            data: {
                action: 'flip_run_photo_analysis',
                nonce: flipData.nonce,
                report_id: FD.activeReportId || '',
            },
            success: function (response) {
                hideProgress();
                $('#flip-run-photos').prop('disabled', false);

                if (response.success) {
                    var d = response.data;
                    alert('Photo analysis complete: ' + d.analyzed + ' analyzed, '
                        + d.updated + ' updated, ' + d.errors + ' errors.');

                    if (d.dashboard) {
                        FD.data = d.dashboard;
                        FD.stats.renderStats(FD.data.summary);
                        FD.stats.renderChart(FD.data.summary.cities || []);
                        FD.filters.applyFilters();
                    }
                } else {
                    alert('Error: ' + (response.data || 'Unknown error'));
                }
            },
            error: function (xhr, status) {
                hideProgress();
                $('#flip-run-photos').prop('disabled', false);

                if (status === 'timeout') {
                    alert('Photo analysis timed out. Check server logs. Refreshing data...');
                    FD.ajax.refreshData();
                } else {
                    alert('Request failed: ' + status);
                }
            }
        });
    };

    FD.ajax.refreshData = function () {
        var postData = {
            action: 'flip_refresh_data',
            nonce: flipData.nonce,
        };
        if (FD.activeReportId) {
            postData.report_id = FD.activeReportId;
        }

        $.ajax({
            url: flipData.ajaxUrl,
            method: 'POST',
            data: postData,
            success: function (response) {
                if (response.success) {
                    FD.data = response.data;
                    FD.stats.renderStats(FD.data.summary);
                    FD.stats.renderChart(FD.data.summary.cities || []);
                    FD.filters.applyFilters();
                }
            }
        });
    };

    FD.ajax.generatePDF = function ($btn) {
        var listingId = $btn.data('listing');
        $btn.prop('disabled', true).text('Generating PDF...');

        var pdfWindow = window.open('about:blank', '_blank');

        $.ajax({
            url: flipData.ajaxUrl,
            method: 'POST',
            timeout: 60000,
            data: {
                action: 'flip_generate_pdf',
                nonce: flipData.nonce,
                listing_id: listingId,
                report_id: FD.activeReportId || '',
            },
            success: function (response) {
                $btn.prop('disabled', false).html('<span class="dashicons dashicons-pdf"></span> Download PDF Report');
                if (response.success) {
                    if (pdfWindow) {
                        pdfWindow.location.href = response.data.url;
                    } else {
                        window.location.href = response.data.url;
                    }
                } else {
                    if (pdfWindow) pdfWindow.close();
                    alert('PDF generation failed: ' + (response.data || 'Unknown error'));
                }
            },
            error: function (xhr, status, err) {
                $btn.prop('disabled', false).html('<span class="dashicons dashicons-pdf"></span> Download PDF Report');
                if (pdfWindow) pdfWindow.close();
                alert('PDF generation failed: ' + status + (err ? ' - ' + err : ''));
            }
        });
    };

    FD.ajax.forceAnalyze = function ($btn) {
        var listingId = $btn.data('listing');
        if (!confirm('Run full analysis on MLS# ' + listingId + ', bypassing disqualification?')) return;

        $btn.prop('disabled', true).text('Analyzing...');

        $.ajax({
            url: flipData.ajaxUrl,
            method: 'POST',
            timeout: 120000,
            data: {
                action: 'flip_force_analyze',
                nonce: flipData.nonce,
                listing_id: listingId,
                report_id: FD.activeReportId || '',
            },
            success: function (response) {
                if (response.success) {
                    var newResult = response.data.result;
                    var idx = -1;
                    for (var i = 0; i < FD.data.results.length; i++) {
                        if (FD.data.results[i].listing_id === newResult.listing_id) {
                            idx = i;
                            break;
                        }
                    }
                    if (idx !== -1) {
                        FD.data.results[idx] = newResult;
                    } else {
                        FD.data.results.push(newResult);
                    }
                    FD.filters.applyFilters();
                    alert('Analysis complete. Score: ' + newResult.total_score.toFixed(1));
                } else {
                    $btn.prop('disabled', false).html('<span class="dashicons dashicons-update"></span> Force Full Analysis');
                    alert('Error: ' + (response.data || 'Unknown error'));
                }
            },
            error: function (xhr, status) {
                $btn.prop('disabled', false).html('<span class="dashicons dashicons-update"></span> Force Full Analysis');
                alert('Request failed: ' + status);
            }
        });
    };

    FD.ajax.exportCSV = function () {
        var results = FD.filters.getFilteredResults();
        if (results.length === 0) {
            alert('No results to export.');
            return;
        }

        var headers = [
            'MLS#', 'Address', 'City', 'Total Score', 'Risk Grade',
            'Financial', 'Property', 'Location', 'Market', 'Photo',
            'List Price', 'ARV', 'ARV Confidence', 'Comps',
            'Rehab Cost', 'Rehab Level', 'Rehab Multiplier', 'Age Condition Mult', 'Contingency',
            'MAO', 'Cash Profit', 'Cash ROI',
            'Financing Costs', 'Holding Costs', 'Hold Months',
            'Financed Profit', 'Cash-on-Cash ROI', 'Annualized ROI',
            'Breakeven ARV', 'Transfer Tax Buy', 'Transfer Tax Sell',
            'Lead Paint', 'Market Strength', 'Sale/List Ratio',
            'Road Type', 'Ceiling', 'Ceiling %',
            'Beds', 'Baths', 'SqFt', 'Year', 'Lot Acres',
            'Disqualified', 'Near-Viable', 'DQ Reason'
        ];

        var rows = results.map(function (r) {
            return [
                r.listing_id,
                '"' + (r.address || '').replace(/"/g, '""') + '"',
                '"' + (r.city || '').replace(/"/g, '""') + '"',
                r.total_score.toFixed(2),
                r.deal_risk_grade || '',
                r.financial_score.toFixed(2),
                r.property_score.toFixed(2),
                r.location_score.toFixed(2),
                r.market_score.toFixed(2),
                r.photo_score != null ? r.photo_score.toFixed(2) : '',
                r.list_price.toFixed(0),
                r.estimated_arv.toFixed(0),
                r.arv_confidence || '',
                r.comp_count,
                r.estimated_rehab_cost.toFixed(0),
                r.rehab_level || '',
                (r.rehab_multiplier || 1).toFixed(2),
                (r.age_condition_multiplier || 1).toFixed(2),
                (r.rehab_contingency || 0).toFixed(0),
                r.mao.toFixed(0),
                (r.cash_profit || 0).toFixed(0),
                (r.cash_roi || 0).toFixed(2),
                (r.financing_costs || 0).toFixed(0),
                (r.holding_costs || 0).toFixed(0),
                r.hold_months || 6,
                r.estimated_profit.toFixed(0),
                (r.cash_on_cash_roi || 0).toFixed(2),
                (r.annualized_roi || 0).toFixed(2),
                (r.breakeven_arv || 0).toFixed(0),
                (r.transfer_tax_buy || 0).toFixed(0),
                (r.transfer_tax_sell || 0).toFixed(0),
                r.lead_paint_flag ? 'Yes' : 'No',
                r.market_strength || 'balanced',
                (r.avg_sale_to_list || 1).toFixed(3),
                r.road_type || '',
                r.neighborhood_ceiling ? r.neighborhood_ceiling.toFixed(0) : '',
                r.ceiling_pct ? r.ceiling_pct.toFixed(1) : '',
                r.bedrooms_total,
                r.bathrooms_total,
                r.building_area_total,
                r.year_built || '',
                r.lot_size_acres ? r.lot_size_acres.toFixed(2) : '',
                r.disqualified ? 'Yes' : 'No',
                r.near_viable ? 'Yes' : 'No',
                '"' + (r.disqualify_reason || '').replace(/"/g, '""') + '"'
            ].join(',');
        });

        var csv = headers.join(',') + '\n' + rows.join('\n');
        var blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
        var url = URL.createObjectURL(blob);

        var a = document.createElement('a');
        a.href = url;
        a.download = 'flip-analysis-' + new Date().toISOString().slice(0, 10) + '.csv';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    };

})(window.FlipDashboard, jQuery);
