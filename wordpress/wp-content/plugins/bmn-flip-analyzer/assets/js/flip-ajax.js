/**
 * FlipDashboard AJAX — Analysis execution, PDF generation, force analyze,
 * data refresh, and CSV export.
 *
 * AJAX handlers that write to FD.data after success.
 */
(function (FD, $) {
    'use strict';

    var h = FD.helpers;

    FD.ajax.runAnalysis = function () {
        if (!confirm('Run analysis on all target cities? This may take 1-3 minutes.')) {
            return;
        }

        $('#flip-modal-overlay').show();
        $('#flip-run-analysis').prop('disabled', true);

        $.ajax({
            url: flipData.ajaxUrl,
            method: 'POST',
            timeout: 300000,
            data: {
                action: 'flip_run_analysis',
                nonce: flipData.nonce,
            },
            success: function (response) {
                $('#flip-modal-overlay').hide();
                $('#flip-run-analysis').prop('disabled', false);

                if (response.success) {
                    var d = response.data;
                    var msg = 'Analysis complete: ' + d.analyzed + ' properties analyzed, '
                        + d.disqualified + ' disqualified.';
                    alert(msg);

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
                $('#flip-modal-overlay').hide();
                $('#flip-run-analysis').prop('disabled', false);

                if (status === 'timeout') {
                    alert('Analysis timed out. Check server logs for progress. Refreshing data...');
                    FD.ajax.refreshData();
                } else {
                    alert('Request failed: ' + status);
                }
            }
        });
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

        $('#flip-modal-overlay').find('h3').text('Running Photo Analysis...');
        $('#flip-modal-overlay').find('p').first().html(
            'Analyzing photos for ' + count + ' properties using Claude Vision.<br>'
            + 'This may take 1-5 minutes depending on the number of properties.'
        );
        $('#flip-modal-overlay').show();
        $('#flip-run-photos').prop('disabled', true);

        $.ajax({
            url: flipData.ajaxUrl,
            method: 'POST',
            timeout: 600000,
            data: {
                action: 'flip_run_photo_analysis',
                nonce: flipData.nonce,
            },
            success: function (response) {
                $('#flip-modal-overlay').hide();
                $('#flip-run-photos').prop('disabled', false);
                // Reset modal text for next use
                $('#flip-modal-overlay').find('h3').text('Running Analysis...');
                $('#flip-modal-overlay').find('p').first().html(
                    'Scoring properties across all target cities.<br>'
                    + 'This may take 1-3 minutes depending on the number of properties.'
                );

                if (response.success) {
                    var d = response.data;
                    var msg = 'Photo analysis complete: ' + d.analyzed + ' analyzed, '
                        + d.updated + ' updated, ' + d.errors + ' errors.';
                    alert(msg);

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
                $('#flip-modal-overlay').hide();
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
        $.ajax({
            url: flipData.ajaxUrl,
            method: 'POST',
            data: {
                action: 'flip_refresh_data',
                nonce: flipData.nonce,
            },
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
                r.photo_score !== null ? r.photo_score.toFixed(2) : '',
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
