/**
 * Flip Analyzer Dashboard
 *
 * Renders Chart.js city breakdown, results table with expandable rows,
 * handles client-side filtering, AJAX analysis, and CSV export.
 *
 * Data provided via wp_localize_script as flipData:
 *   - ajaxUrl: admin-ajax.php URL
 *   - nonce: security nonce
 *   - data.summary: {total, viable, avg_score, avg_roi, disqualified, last_run, cities}
 *   - data.results: array of property objects
 *   - data.cities: array of target city names
 */

(function ($) {
    'use strict';

    let dashData = flipData.data;
    let cityChart = null;

    // ── Initialization ─────────────────────────────

    $(document).ready(function () {
        try {
            renderStats(dashData.summary);
            renderChart(dashData.summary.cities || []);
            populateCityFilter(dashData.cities || []);
            renderCityTags(dashData.cities || []);
            applyFilters();
        } catch (e) {
            console.error('[FlipDashboard] Init error:', e);
        }

        // Analysis filters panel
        initAnalysisFilters();

        // Event handlers - use delegation on tbody for dynamic rows
        var $tbody = $('#flip-results-body');
        $tbody.on('click', '.flip-toggle', function (e) {
            e.stopPropagation();
            toggleRow($(this));
        });
        $tbody.on('click', 'tr.flip-main-row', function (e) {
            // Don't fire if they clicked the toggle button, link, or PDF button
            if ($(e.target).closest('.flip-toggle').length) return;
            if ($(e.target).closest('a').length) return;
            if ($(e.target).closest('.flip-pdf-btn').length) return;
            if ($(e.target).closest('.flip-force-btn').length) return;
            toggleRow($(this).find('.flip-toggle'));
        });
        $(document).on('click', '.flip-pdf-btn', function (e) {
            e.preventDefault();
            e.stopPropagation();
            generatePDF($(this));
        });
        $(document).on('click', '.flip-force-btn', function (e) {
            e.preventDefault();
            e.stopPropagation();
            forceAnalyze($(this));
        });

        $('#flip-run-analysis').on('click', runAnalysis);
        $('#flip-run-photos').on('click', runPhotoAnalysis);
        $('#flip-export-csv').on('click', exportCSV);
        $('#flip-city-add-btn').on('click', addCity);
        $('#flip-city-input').on('keypress', function (e) {
            if (e.which === 13) { e.preventDefault(); addCity(); }
        });
        $('#filter-city, #filter-sort, #filter-show').on('change', applyFilters);
        $('#filter-score').on('input', function () {
            $('#score-display').text(this.value);
            applyFilters();
        });
    });

    // ── Analysis Filters Panel ────────────────────────

    function initAnalysisFilters() {
        var filters = flipData.filters || {};
        var subTypes = flipData.propertySubTypes || [];

        // Build property sub type checkboxes
        var $container = $('#flip-af-subtypes');
        var checked = filters.property_sub_types || ['Single Family Residence'];
        subTypes.forEach(function (st) {
            var isChecked = checked.indexOf(st) !== -1 ? ' checked' : '';
            $container.append('<label><input type="checkbox" name="af-subtype" value="' + escapeHtml(st) + '"' + isChecked + '> ' + escapeHtml(st) + '</label>');
        });

        // Populate status checkboxes
        var statuses = filters.statuses || ['Active'];
        $('input[name="af-status"]').each(function () {
            $(this).prop('checked', statuses.indexOf($(this).val()) !== -1);
        });

        // Populate range inputs
        $('#af-min-price').val(filters.min_price || '');
        $('#af-max-price').val(filters.max_price || '');
        $('#af-min-sqft').val(filters.min_sqft || '');
        $('#af-max-sqft').val(filters.max_sqft || '');
        $('#af-year-min').val(filters.year_built_min || '');
        $('#af-year-max').val(filters.year_built_max || '');
        $('#af-min-dom').val(filters.min_dom || '');
        $('#af-max-dom').val(filters.max_dom || '');
        $('#af-list-from').val(filters.list_date_from || '');
        $('#af-list-to').val(filters.list_date_to || '');
        $('#af-min-beds').val(filters.min_beds || '');
        $('#af-min-baths').val(filters.min_baths || '');
        $('#af-min-lot').val(filters.min_lot_acres || '');

        // Populate boolean checkboxes
        $('#af-sewer-public').prop('checked', !!filters.sewer_public_only);
        $('#af-has-garage').prop('checked', !!filters.has_garage);

        // Toggle panel
        $('#flip-af-toggle').on('click', function () {
            var $body = $('#flip-af-body');
            var $arrow = $(this).find('.flip-af-arrow');
            $body.slideToggle(200);
            $arrow.toggleClass('flip-af-arrow-open');
        });

        // Save filters
        $('#flip-save-filters').on('click', saveAnalysisFilters);

        // Reset filters
        $('#flip-reset-filters').on('click', resetAnalysisFilters);
    }

    function collectAnalysisFilters() {
        var filters = {};

        // Property sub types
        filters.property_sub_types = [];
        $('input[name="af-subtype"]:checked').each(function () {
            filters.property_sub_types.push($(this).val());
        });
        if (filters.property_sub_types.length === 0) {
            filters.property_sub_types = ['Single Family Residence'];
        }

        // Statuses
        filters.statuses = [];
        $('input[name="af-status"]:checked').each(function () {
            filters.statuses.push($(this).val());
        });
        if (filters.statuses.length === 0) {
            filters.statuses = ['Active'];
        }

        // Range values
        filters.min_price = $('#af-min-price').val() || null;
        filters.max_price = $('#af-max-price').val() || null;
        filters.min_sqft = $('#af-min-sqft').val() || null;
        filters.max_sqft = $('#af-max-sqft').val() || null;
        filters.year_built_min = $('#af-year-min').val() || null;
        filters.year_built_max = $('#af-year-max').val() || null;
        filters.min_dom = $('#af-min-dom').val() || null;
        filters.max_dom = $('#af-max-dom').val() || null;
        filters.list_date_from = $('#af-list-from').val() || null;
        filters.list_date_to = $('#af-list-to').val() || null;
        filters.min_beds = $('#af-min-beds').val() || null;
        filters.min_baths = $('#af-min-baths').val() || null;
        filters.min_lot_acres = $('#af-min-lot').val() || null;

        // Booleans
        filters.sewer_public_only = $('#af-sewer-public').is(':checked');
        filters.has_garage = $('#af-has-garage').is(':checked');

        return filters;
    }

    function saveAnalysisFilters() {
        var filters = collectAnalysisFilters();
        var $status = $('#flip-af-status');
        $status.text('Saving...').css('color', '#666').show();

        $.ajax({
            url: flipData.ajaxUrl,
            method: 'POST',
            data: {
                action: 'flip_save_filters',
                nonce: flipData.nonce,
                filters: JSON.stringify(filters),
            },
            success: function (response) {
                if (response.success) {
                    flipData.filters = response.data.filters;
                    $status.text('Filters saved.').css('color', '#00a32a');
                } else {
                    $status.text('Error: ' + (response.data || 'Unknown')).css('color', '#cc1818');
                }
                setTimeout(function () { $status.fadeOut(); }, 3000);
            },
            error: function () {
                $status.text('Request failed.').css('color', '#cc1818');
                setTimeout(function () { $status.fadeOut(); }, 3000);
            }
        });
    }

    function resetAnalysisFilters() {
        // Reset checkboxes: only SFR checked
        $('input[name="af-subtype"]').prop('checked', false);
        $('input[name="af-subtype"][value="Single Family Residence"]').prop('checked', true);

        // Reset status: only Active checked
        $('input[name="af-status"]').prop('checked', false);
        $('input[name="af-status"][value="Active"]').prop('checked', true);

        // Clear all range inputs
        $('#af-min-price, #af-max-price, #af-min-sqft, #af-max-sqft').val('');
        $('#af-year-min, #af-year-max, #af-min-dom, #af-max-dom').val('');
        $('#af-list-from, #af-list-to, #af-min-beds, #af-min-baths, #af-min-lot').val('');

        // Reset booleans
        $('#af-sewer-public, #af-has-garage').prop('checked', false);

        // Auto-save the defaults
        saveAnalysisFilters();
    }

    // ── Summary Stats ──────────────────────────────

    function renderStats(summary) {
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
    }

    // ── City Breakdown Chart ───────────────────────

    function renderChart(cities) {
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

        if (cityChart) {
            cityChart.destroy();
        }

        cityChart = new Chart(ctx.getContext('2d'), {
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
    }

    // ── City Filter Dropdown ───────────────────────

    function populateCityFilter(cities) {
        var $select = $('#filter-city');
        var current = $select.val();
        $select.find('option:not(:first)').remove();
        cities.forEach(function (city) {
            $select.append('<option value="' + escapeHtml(city) + '">' + escapeHtml(city) + '</option>');
        });
        if (current) $select.val(current);
    }

    // ── Filter & Render Table ──────────────────────

    function applyFilters() {
        var city = $('#filter-city').val();
        var minScore = parseInt($('#filter-score').val()) || 0;
        var sort = $('#filter-sort').val();
        var show = $('#filter-show').val();

        var filtered = (dashData.results || []).filter(function (r) {
            // Show filter
            if (show === 'viable' && r.disqualified) return false;
            if (show === 'near_viable' && !(r.disqualified && r.near_viable)) return false;
            if (show === 'disqualified' && !r.disqualified) return false;

            // City filter
            if (city && r.city !== city) return false;

            // Score filter (only for non-disqualified)
            if (!r.disqualified && minScore > 0 && r.total_score < minScore) return false;

            return true;
        });

        // Sort
        filtered.sort(function (a, b) {
            var va = a[sort] || 0;
            var vb = b[sort] || 0;
            return vb - va; // DESC
        });

        renderTable(filtered);
    }

    function renderTable(results) {
        var $tbody = $('#flip-results-body');
        $tbody.empty();

        if (results.length === 0) {
            $('#flip-results-table').hide();
            $('#flip-table-empty').show();
            $('#result-count').text('');
            return;
        }

        $('#flip-results-table').show();
        $('#flip-table-empty').hide();
        $('#result-count').text('(' + results.length + ')');

        results.forEach(function (r, idx) {
            var $row = buildRow(r, idx);
            $tbody.append($row);
        });
        // Click handlers use event delegation on tbody (bound once in ready())
    }

    function buildRow(r, idx) {
        var dqClass = r.disqualified ? (r.near_viable ? ' flip-row-near-viable' : ' flip-row-dq') : '';
        var scoreHtml = r.disqualified
            ? (r.near_viable
                ? '<span class="flip-score-badge flip-score-near">NV</span>'
                : '<span class="flip-score-badge flip-score-poor">DQ</span>')
            : '<span class="flip-score-badge ' + scoreClass(r.total_score) + '">' + r.total_score.toFixed(1) + '</span>';

        var photoHtml = r.photo_score !== null
            ? '<span class="flip-score-badge ' + scoreClass(r.photo_score) + '">' + r.photo_score.toFixed(0) + '</span>'
            : '<span style="color:#ccc">--</span>';

        var profitClass = r.estimated_profit >= 0 ? '' : ' flip-negative';

        // Risk grade badge
        var riskHtml = r.deal_risk_grade
            ? '<span class="flip-risk-badge flip-risk-' + r.deal_risk_grade + '">' + r.deal_risk_grade + '</span>'
            : '<span style="color:#ccc">--</span>';

        // Lead paint badge
        var leadBadge = r.lead_paint_flag ? '<span class="flip-lead-badge">Pb</span>' : '';

        var dqNote = r.disqualified && r.disqualify_reason
            ? '<div class="flip-dq-reason">' + escapeHtml(r.disqualify_reason) + '</div>'
            : '';

        var propertyUrl = flipData.siteUrl + '/property/' + r.listing_id + '/';
        var domText = r.days_on_market ? r.days_on_market + 'd' : '--';

        // Annualized ROI
        var annRoiText = r.annualized_roi ? r.annualized_roi.toFixed(0) + '%' : '--';
        var annRoiClass = r.annualized_roi >= 0 ? '' : ' flip-negative';

        var html = '<tr class="flip-main-row' + dqClass + '" data-idx="' + idx + '">'
            + '<td class="flip-col-toggle"><button class="flip-toggle" data-idx="' + idx + '">+</button></td>'
            + '<td><div class="flip-property-cell">'
            + '<span class="flip-property-address">' + escapeHtml(r.address) + leadBadge + '</span>'
            + '<span class="flip-property-mls">MLS# ' + r.listing_id
            + ' &middot; <a href="' + propertyUrl + '" target="_blank" class="flip-view-link">View</a></span>'
            + dqNote
            + '</div></td>'
            + '<td>' + escapeHtml(r.city) + '</td>'
            + '<td class="flip-col-num">' + scoreHtml + '</td>'
            + '<td>' + riskHtml + '</td>'
            + '<td class="flip-col-num">' + formatCurrency(r.list_price) + '</td>'
            + '<td class="flip-col-num">' + formatCurrency(r.estimated_arv) + '</td>'
            + '<td class="flip-col-num' + profitClass + '">' + formatCurrency(r.estimated_profit) + '</td>'
            + '<td class="flip-col-num' + annRoiClass + '">' + annRoiText + '</td>'
            + '<td>' + roadBadge(r.road_type) + '</td>'
            + '<td class="flip-col-num">' + domText + '</td>'
            + '<td class="flip-col-num">' + photoHtml + '</td>'
            + '</tr>';

        return $(html);
    }

    function toggleRow($btn) {
        try {
            var idx = $btn.data('idx');
            var $mainRow = $btn.closest('tr');
            var $detailRow = $mainRow.next('.flip-detail-row');

            if ($detailRow.length) {
                // Collapse
                $detailRow.remove();
                $mainRow.removeClass('flip-row-expanded');
                $btn.text('+');
            } else {
                // Expand
                var results = getFilteredResults();
                var r = results[idx];
                if (!r) {
                    console.warn('[FlipDashboard] No result at index', idx, 'of', results.length);
                    return;
                }

                var $detail = buildDetailRow(r);
                $mainRow.after($detail);
                $mainRow.addClass('flip-row-expanded');
                $btn.text('\u2212'); // minus sign
            }
        } catch (e) {
            console.error('[FlipDashboard] toggleRow error:', e);
        }
    }

    function getFilteredResults() {
        var city = $('#filter-city').val();
        var minScore = parseInt($('#filter-score').val()) || 0;
        var sort = $('#filter-sort').val();
        var show = $('#filter-show').val();

        var filtered = (dashData.results || []).filter(function (r) {
            if (show === 'viable' && r.disqualified) return false;
            if (show === 'near_viable' && !(r.disqualified && r.near_viable)) return false;
            if (show === 'disqualified' && !r.disqualified) return false;
            if (city && r.city !== city) return false;
            if (!r.disqualified && minScore > 0 && r.total_score < minScore) return false;
            return true;
        });

        filtered.sort(function (a, b) {
            return (b[sort] || 0) - (a[sort] || 0);
        });

        return filtered;
    }

    // ── Expanded Detail Row ────────────────────────

    function buildDetailRow(r) {
        var html = '<tr class="flip-detail-row"><td colspan="12">';

        // Toolbar with PDF button + Force Analyze for DQ rows
        var forceBtn = r.disqualified
            ? '<button class="button button-small flip-force-btn" data-listing="' + r.listing_id + '">'
              + '<span class="dashicons dashicons-update"></span> Force Full Analysis'
              + '</button>'
            : '';
        html += '<div class="flip-detail-toolbar">'
            + forceBtn
            + '<button class="button button-small flip-pdf-btn" data-listing="' + r.listing_id + '">'
            + '<span class="dashicons dashicons-pdf"></span> Download PDF Report'
            + '</button></div>';

        html += '<div class="flip-details">';

        // Column 1: Score Breakdown + Financial
        html += '<div class="flip-detail-col">';
        html += buildScoreSection(r);
        html += buildFinancialSection(r);
        html += '</div>';

        // Column 2: Property Details + Comps
        html += '<div class="flip-detail-col">';
        html += buildPropertySection(r);
        html += buildCompsSection(r);
        html += '</div>';

        // Column 3: Photo Analysis + Projections + Remarks
        html += '<div class="flip-detail-col">';
        html += buildPhotoSection(r);
        html += buildProjectionSection(r);
        html += buildRemarksSection(r);
        html += '</div>';

        html += '</div></td></tr>';
        return $(html);
    }

    function buildScoreSection(r) {
        var bars = [
            { label: 'Financial (40%)', score: r.financial_score, weight: 0.4 },
            { label: 'Property (25%)', score: r.property_score, weight: 0.25 },
            { label: 'Location (25%)', score: r.location_score, weight: 0.25 },
            { label: 'Market (10%)', score: r.market_score, weight: 0.1 },
        ];

        if (r.photo_score !== null) {
            bars.push({ label: 'Photo', score: r.photo_score, weight: null });
        }

        var html = '<div class="flip-section"><h4>Score Breakdown</h4><div class="flip-score-bars">';

        bars.forEach(function (b) {
            var pct = Math.min(100, Math.max(0, b.score));
            var weighted = b.weight ? (b.score * b.weight).toFixed(1) : '';
            var suffix = weighted ? ' (' + weighted + ')' : '';

            html += '<div class="flip-bar-row">'
                + '<span class="flip-bar-label">' + b.label + '</span>'
                + '<div class="flip-bar-track">'
                + '<div class="flip-bar-fill ' + scoreClass(b.score) + '" style="width:' + pct + '%">'
                + b.score.toFixed(1) + suffix
                + '</div></div></div>';
        });

        html += '</div></div>';
        return html;
    }

    function buildFinancialSection(r) {
        var valuation = calcValuationRange(r);

        // Use stored values from PHP financial model
        var holdingCosts = r.holding_costs || 0;
        var financingCosts = r.financing_costs || 0;
        var contingency = r.rehab_contingency || 0;
        var holdMonths = r.hold_months || 6;
        var rehabMultiplier = r.rehab_multiplier || 1.0;
        var transferTaxBuy = r.transfer_tax_buy || 0;
        var transferTaxSell = r.transfer_tax_sell || 0;
        var purchaseClosing = r.list_price * 0.015 + transferTaxBuy;
        var saleCostPct = 0.045 + 0.01; // 4.5% comm + 1% closing
        var saleCosts = r.estimated_arv * saleCostPct + transferTaxSell;

        var html = '<div class="flip-section"><h4>Financial Summary</h4><div class="flip-kv-list">';

        // Risk grade
        if (r.deal_risk_grade) {
            html += kv('Deal Risk', '<span class="flip-risk-badge flip-risk-' + r.deal_risk_grade + '">' + r.deal_risk_grade + '</span>');
        }

        // Market strength badge
        if (r.market_strength && r.market_strength !== 'balanced') {
            var msBadge = marketStrengthBadge(r.market_strength);
            html += kv('Market Signal', msBadge + ' <span style="color:#999;font-size:11px">(S/L: ' + (r.avg_sale_to_list || 1).toFixed(3) + ')</span>');
        }

        // Valuation
        html += kv('Floor Value', formatCurrency(valuation.floor));
        var arvLabel = formatCurrency(r.estimated_arv)
            + ' <span class="flip-conf-' + (r.arv_confidence || 'low') + '">(' + (r.arv_confidence || 'n/a') + ')</span>';
        if (r.road_arv_discount && r.road_arv_discount > 0) {
            arvLabel += ' <span style="color:#cc1818;font-size:11px">(-' + Math.round(r.road_arv_discount * 100) + '% road adj.)</span>';
        }
        html += kv('Mid Value (ARV)', arvLabel);
        html += kv('Ceiling Value', formatCurrency(valuation.ceiling));

        // Breakeven ARV
        if (r.breakeven_arv && r.estimated_arv > 0) {
            var beMargin = ((r.estimated_arv - r.breakeven_arv) / r.estimated_arv * 100).toFixed(1);
            var beCls = beMargin >= 10 ? 'flip-positive' : (beMargin >= 5 ? '' : 'flip-negative');
            html += kv('Breakeven ARV', formatCurrency(r.breakeven_arv)
                + ' <span class="' + beCls + '" style="font-size:11px">(' + beMargin + '% margin)</span>');
        }

        html += kv('Comps Used', r.comp_count);

        // Cost breakdown
        html += '<div style="border-top:1px solid #ddd;margin:8px 0 4px;padding-top:6px"></div>';
        html += kv('Purchase Price', formatCurrency(r.list_price));

        var rehabNote = r.rehab_level || '?';
        if (rehabMultiplier !== 1.0) {
            rehabNote += ', ' + rehabMultiplier.toFixed(2) + 'x remarks adj.';
        }
        var baseRehab = r.estimated_rehab_cost - contingency;
        html += kv('Rehab Cost', formatCurrency(baseRehab) + ' <span style="color:#999;font-size:11px">(' + rehabNote + ')</span>');

        // Lead paint
        if (r.lead_paint_flag) {
            html += kv('<span class="flip-lead-badge">Pb</span> Lead Paint', '<span style="color:#856404">$8K included in rehab</span>');
        }

        // Scaled contingency
        var contPct = contingency > 0 && baseRehab > 0 ? Math.round(contingency / baseRehab * 100) : 10;
        html += kv('+ Contingency (' + contPct + '%)', formatCurrency(contingency));
        html += kv('Purchase Closing', formatCurrency(purchaseClosing)
            + ' <span style="color:#999;font-size:11px">(1.5% + ' + formatCurrency(transferTaxBuy) + ' transfer tax)</span>');
        html += kv('Sale Costs', formatCurrency(saleCosts)
            + ' <span style="color:#999;font-size:11px">(4.5% comm + 1% + ' + formatCurrency(transferTaxSell) + ' tax)</span>');
        html += kv('Holding Costs', formatCurrency(holdingCosts) + ' <span style="color:#999;font-size:11px">(' + holdMonths + ' mo: tax+ins+util)</span>');

        // Dual profit scenario
        html += '<div style="border-top:1px solid #ddd;margin:4px 0"></div>';
        html += '<div style="font-weight:600;font-size:12px;color:#333;margin:4px 0">Cash Purchase:</div>';
        var cashProfitCls = (r.cash_profit || 0) >= 0 ? 'flip-positive' : 'flip-negative';
        html += kv('Profit', '<strong class="' + cashProfitCls + '">' + formatCurrency(r.cash_profit) + '</strong>');
        html += kv('ROI', '<strong class="' + cashProfitCls + '">' + (r.cash_roi || 0).toFixed(1) + '%</strong>');

        html += '<div style="font-weight:600;font-size:12px;color:#333;margin:4px 0">Hard Money (10.5%, 2 pts, 80% LTV):</div>';
        html += kv('Financing Costs', formatCurrency(financingCosts));
        var finProfitCls = r.estimated_profit >= 0 ? 'flip-positive' : 'flip-negative';
        html += kv('Profit', '<strong class="' + finProfitCls + '">' + formatCurrency(r.estimated_profit) + '</strong>');
        html += kv('Cash-on-Cash ROI', '<strong class="' + finProfitCls + '">' + (r.cash_on_cash_roi || 0).toFixed(1) + '%</strong>');
        if (r.annualized_roi) {
            var annCls = r.annualized_roi >= 0 ? 'flip-positive' : 'flip-negative';
            html += kv('Annualized ROI', '<strong class="' + annCls + '">' + r.annualized_roi.toFixed(1) + '%</strong>');
        }

        html += '<div style="border-top:1px solid #ddd;margin:4px 0"></div>';
        html += kv('MAO (70% rule)', formatCurrency(r.mao));
        if (r.adjusted_mao !== undefined) {
            html += kv('Adjusted MAO', formatCurrency(r.adjusted_mao || (r.mao - holdingCosts - financingCosts))
                + ' <span style="color:#999;font-size:11px">(incl. holding+financing)</span>');
        } else {
            // Compute adjusted MAO client-side as fallback
            var adjMao = (r.mao || 0) - holdingCosts - financingCosts;
            html += kv('Adjusted MAO', formatCurrency(adjMao)
                + ' <span style="color:#999;font-size:11px">(incl. holding+financing)</span>');
        }

        // Sensitivity / stress test table
        html += buildSensitivitySection(r);

        // Thresholds applied (show when market-adjusted)
        if (r.applied_thresholds) {
            var t = r.applied_thresholds;
            html += '<div style="border-top:1px solid #ddd;margin:8px 0 4px;padding-top:6px"></div>';
            html += '<div style="font-weight:600;font-size:12px;color:#333;margin:4px 0">Thresholds Applied:</div>';
            var profitNote = t.market_strength !== 'balanced'
                ? ' <span style="color:#856404;font-size:11px">(adj. from $25K)</span>' : '';
            var roiNote = t.market_strength !== 'balanced'
                ? ' <span style="color:#856404;font-size:11px">(adj. from 15%)</span>' : '';
            html += kv('Min Profit', formatCurrency(t.min_profit) + profitNote);
            html += kv('Min ROI', t.min_roi.toFixed(1) + '%' + roiNote);
            html += kv('Max Price/ARV', (t.max_price_arv * 100).toFixed(0) + '%');
            html += kv('Market', marketStrengthBadge(t.market_strength)
                + ' <span style="color:#999;font-size:11px">(x' + t.multiplier.toFixed(3) + ')</span>');
        }

        html += '</div></div>';
        return html;
    }

    function buildSensitivitySection(r) {
        if (r.disqualified || !r.estimated_arv || r.estimated_arv <= 0) return '';

        var scenarios = [
            { label: 'Base Case', arvMult: 1.00, rehabMult: 1.00 },
            { label: 'Conservative', arvMult: 0.90, rehabMult: 1.20 },
            { label: 'Worst Case', arvMult: 0.85, rehabMult: 1.30 },
        ];

        var html = '<div style="border-top:1px solid #ddd;margin:8px 0 4px;padding-top:6px"></div>';
        html += '<div style="font-weight:600;font-size:12px;color:#333;margin:4px 0">Sensitivity Analysis:</div>';
        html += '<table class="flip-comp-table" style="margin-top:4px"><thead><tr>'
            + '<th>Scenario</th><th>ARV</th><th>Rehab</th><th>Profit</th><th>ROI</th>'
            + '</tr></thead><tbody>';

        scenarios.forEach(function (s) {
            var adjArv = Math.round(r.estimated_arv * s.arvMult);
            var adjRehab = Math.round(r.estimated_rehab_cost * s.rehabMult);
            var costs = calcProjectionCosts(r.list_price, adjArv, adjRehab - (r.rehab_contingency || 0));
            var cls = costs.profit >= 0 ? 'flip-positive' : 'flip-negative';

            html += '<tr>'
                + '<td><strong>' + s.label + '</strong></td>'
                + '<td>' + formatCurrency(adjArv) + ' <span style="color:#999;font-size:10px">(' + Math.round(s.arvMult * 100) + '%)</span></td>'
                + '<td>' + formatCurrency(adjRehab) + ' <span style="color:#999;font-size:10px">(' + Math.round(s.rehabMult * 100) + '%)</span></td>'
                + '<td class="' + cls + '">' + formatCurrency(costs.profit) + '</td>'
                + '<td class="' + cls + '">' + costs.roi.toFixed(1) + '%</td>'
                + '</tr>';
        });

        html += '</tbody></table>';
        return html;
    }

    function calcValuationRange(r) {
        var arv = r.estimated_arv || 0;
        var confidence = r.arv_confidence || 'low';

        // Spread based on confidence — less data = wider uncertainty range
        var spread;
        switch (confidence) {
            case 'high':   spread = 0.10; break; // ±10%
            case 'medium': spread = 0.15; break; // ±15%
            default:       spread = 0.20; break; // ±20%
        }

        return {
            floor:   Math.round(arv * (1 - spread)),
            mid:     arv,
            ceiling: Math.round(arv * (1 + spread)),
        };
    }

    function buildPropertySection(r) {
        var html = '<div class="flip-section"><h4>Property Details</h4><div class="flip-kv-list">';
        html += kv('Beds / Baths', r.bedrooms_total + ' / ' + r.bathrooms_total.toFixed(1));
        html += kv('Sq Ft', r.building_area_total ? r.building_area_total.toLocaleString() : '--');
        html += kv('Year Built', r.year_built || '--');
        html += kv('Lot Size', r.lot_size_acres ? r.lot_size_acres.toFixed(2) + ' acres' : '--');
        html += kv('Road Type', roadBadge(r.road_type));
        html += kv('Nbhd Ceiling', r.neighborhood_ceiling ? formatCurrency(r.neighborhood_ceiling) : '--');

        if (r.ceiling_pct) {
            var ceilClass = r.ceiling_pct > 100 ? 'flip-negative' : 'flip-positive';
            html += kv('ARV / Ceiling', '<span class="' + ceilClass + '">' + r.ceiling_pct.toFixed(0) + '%</span>');
        }

        if (r.building_area_total && r.lot_size_acres) {
            var lotSqFt = r.lot_size_acres * 43560;
            var ratio = (lotSqFt / r.building_area_total).toFixed(1);
            html += kv('Lot/House Ratio', ratio + 'x');
        }

        html += '</div></div>';
        return html;
    }

    function buildCompsSection(r) {
        if (!r.comps || r.comps.length === 0) {
            return '<div class="flip-section"><h4>Comparables</h4><p style="color:#999;font-size:12px">No comp data available.</p></div>';
        }

        var hasAdjustments = r.comps.some(function (c) { return c.adjusted_price; });

        var html = '<div class="flip-section"><h4>Comparables (' + r.comps.length + ')</h4>';
        html += '<table class="flip-comp-table"><thead><tr>'
            + '<th>Address</th><th>Sold</th><th>$/SqFt</th>';
        if (hasAdjustments) {
            html += '<th>Adj. Price</th>';
        }
        html += '<th>Dist</th><th>Date</th>'
            + '</tr></thead><tbody>';

        r.comps.forEach(function (c) {
            var adjCell = '';
            if (hasAdjustments) {
                if (c.adjusted_price && c.total_adjustment) {
                    var adjSign = c.total_adjustment >= 0 ? '+' : '';
                    var adjColor = c.total_adjustment >= 0 ? '#2e7d32' : '#c62828';
                    adjCell = '<td>' + formatCurrency(c.adjusted_price)
                        + ' <span style="color:' + adjColor + ';font-size:10px">(' + adjSign + formatCurrency(c.total_adjustment) + ')</span>';

                    // Show adjustment breakdown on hover via title
                    if (c.adjustments && typeof c.adjustments === 'object') {
                        var details = [];
                        for (var key in c.adjustments) {
                            if (c.adjustments.hasOwnProperty(key) && c.adjustments[key] !== 0) {
                                var label = key.replace(/_/g, ' ');
                                var val = c.adjustments[key];
                                details.push(label + ': ' + (val >= 0 ? '+' : '') + '$' + Math.abs(Math.round(val)).toLocaleString());
                            }
                        }
                        if (details.length > 0) {
                            adjCell = '<td title="' + escapeHtml(details.join('\n')) + '">'
                                + formatCurrency(c.adjusted_price)
                                + ' <span style="color:' + adjColor + ';font-size:10px">(' + adjSign + formatCurrency(c.total_adjustment) + ')</span>';
                        }
                    }
                    adjCell += '</td>';
                } else {
                    adjCell = '<td>' + formatCurrency(c.close_price) + '</td>';
                }
            }

            var ppsf = c.adjusted_ppsf || c.ppsf;

            var compAddr = escapeHtml(c.address || 'N/A');
            if (c.listing_id) {
                compAddr = '<a href="' + flipData.siteUrl + '/property/' + c.listing_id + '/" target="_blank" class="flip-view-link">' + compAddr + '</a>';
            }

            html += '<tr>'
                + '<td>' + compAddr + '</td>'
                + '<td>' + formatCurrency(c.close_price) + '</td>'
                + '<td>$' + (ppsf ? ppsf.toFixed(0) : '--') + '</td>'
                + adjCell
                + '<td>' + (c.distance_miles ? c.distance_miles.toFixed(2) + 'mi' : '--') + '</td>'
                + '<td>' + (c.close_date || '--') + '</td>'
                + '</tr>';
        });

        html += '</tbody></table></div>';
        return html;
    }

    function buildPhotoSection(r) {
        if (!r.photo_analysis) {
            return '<div class="flip-section"><h4>Photo Analysis</h4>'
                + '<p style="color:#999;font-size:12px">Not yet analyzed. Click "Run Photo Analysis" above to analyze viable properties.</p></div>';
        }

        var pa = r.photo_analysis;
        var html = '<div class="flip-section"><h4>Photo Analysis</h4>';

        if (r.main_photo_url) {
            html += '<img class="flip-photo-thumb" src="' + escapeHtml(r.main_photo_url) + '" alt="Property photo">';
        }

        html += '<div class="flip-kv-list">';
        html += kv('Overall Condition', pa.overall_condition ? pa.overall_condition + '/10' : '--');
        html += kv('Renovation Level', pa.renovation_level || '--');
        html += kv('Est. Cost/SqFt', pa.estimated_cost_per_sqft ? '$' + pa.estimated_cost_per_sqft : '--');
        html += kv('Photo Score', r.photo_score !== null ? r.photo_score.toFixed(0) + '/100' : '--');

        var areaKeys = [
            { key: 'kitchen_condition', label: 'Kitchen' },
            { key: 'bathroom_condition', label: 'Bathroom' },
            { key: 'flooring_condition', label: 'Flooring' },
            { key: 'exterior_condition', label: 'Exterior' },
            { key: 'curb_appeal', label: 'Curb Appeal' },
        ];
        areaKeys.forEach(function (item) {
            if (pa[item.key] !== undefined && pa[item.key] !== null) {
                html += kv(item.label, pa[item.key] + '/10');
            }
        });

        html += '</div>';

        if (pa.structural_concerns && pa.structural_details) {
            html += '<div style="margin-top:8px;padding:6px 8px;background:#fff3cd;border-radius:4px;font-size:12px">';
            html += '<strong>Structural Concerns:</strong> ' + escapeHtml(pa.structural_details);
            html += '</div>';
        }

        if (pa.renovation_summary) {
            html += '<div style="margin-top:6px;font-size:12px;color:#555">' + escapeHtml(pa.renovation_summary) + '</div>';
        }

        html += '</div>';
        return html;
    }

    function buildRemarksSection(r) {
        var signals = r.remarks_signals;
        if (!signals || typeof signals !== 'object') {
            return '';
        }

        // Format: {positive: ["keyword",...], negative: ["keyword",...], adjustment: N}
        var positives = signals.positive || [];
        var negatives = signals.negative || [];

        if (positives.length === 0 && negatives.length === 0) {
            return '';
        }

        var html = '<div class="flip-section"><h4>Remarks Signals</h4>';

        positives.forEach(function (kw) {
            html += '<span class="flip-signal flip-signal-positive">' + escapeHtml(kw) + '</span>';
        });

        negatives.forEach(function (kw) {
            html += '<span class="flip-signal flip-signal-negative">' + escapeHtml(kw) + '</span>';
        });

        if (signals.adjustment) {
            var adjClass = signals.adjustment > 0 ? 'flip-positive' : 'flip-negative';
            var sign = signals.adjustment > 0 ? '+' : '';
            html += '<div style="margin-top:6px;font-size:12px">Score adjustment: <strong class="' + adjClass + '">'
                + sign + signals.adjustment + '</strong></div>';
        }

        html += '</div>';
        return html;
    }

    // ── ARV Projection Calculator ──────────────────

    function buildProjectionSection(r) {
        if (r.disqualified || !r.avg_comp_ppsf || r.avg_comp_ppsf <= 0) return '';

        var ppsf = r.avg_comp_ppsf;
        var discount = 1 - (r.road_arv_discount || 0);
        var rehabPerSqft = r.estimated_rehab_cost && r.building_area_total
            ? r.estimated_rehab_cost / r.building_area_total : 45;
        var listPrice = r.list_price || 0;
        var curBeds = r.bedrooms_total || 0;
        var curBaths = r.bathrooms_total || 0;
        var curSqft = r.building_area_total || 0;

        // Unique ID for this property's projection inputs
        var pid = 'proj-' + r.listing_id;

        var scenarios = [
            { label: 'Current', beds: curBeds, baths: curBaths, sqft: curSqft },
            { label: '+1 Bed +300sf', beds: curBeds + 1, baths: curBaths, sqft: curSqft + 300 },
            { label: '+1 Bed +1 Bath +500sf', beds: curBeds + 1, baths: curBaths + 1, sqft: curSqft + 500 },
        ];

        var html = '<div class="flip-section"><h4>ARV Projections</h4>';
        html += '<p style="font-size:11px;color:#888;margin:0 0 8px">Based on comp avg $' + ppsf.toFixed(0) + '/sqft';
        if (r.road_arv_discount > 0) html += ' (after ' + Math.round(r.road_arv_discount * 100) + '% road adj.)';
        html += '</p>';

        html += '<table class="flip-comp-table"><thead><tr>'
            + '<th>Scenario</th><th>Beds</th><th>Baths</th><th>SqFt</th><th>ARV</th><th>Profit</th><th>ROI</th>'
            + '</tr></thead><tbody>';

        scenarios.forEach(function (s) {
            var projArv = Math.round(ppsf * s.sqft * discount);
            var addedSqft = Math.max(0, s.sqft - curSqft);
            var totalRehab = Math.round(rehabPerSqft * curSqft + addedSqft * 250);
            var projCosts = calcProjectionCosts(listPrice, projArv, totalRehab);
            var profitCls = projCosts.profit >= 0 ? 'flip-positive' : 'flip-negative';

            html += '<tr>'
                + '<td><strong>' + s.label + '</strong></td>'
                + '<td>' + s.beds + '</td>'
                + '<td>' + s.baths + '</td>'
                + '<td>' + s.sqft.toLocaleString() + '</td>'
                + '<td>' + formatCurrency(projArv) + '</td>'
                + '<td class="' + profitCls + '">' + formatCurrency(projCosts.profit) + '</td>'
                + '<td class="' + profitCls + '">' + projCosts.roi.toFixed(1) + '%</td>'
                + '</tr>';
        });

        // Custom row with editable inputs
        html += '<tr class="flip-proj-custom" data-pid="' + pid + '">'
            + '<td><strong>Custom</strong></td>'
            + '<td><input type="number" class="flip-proj-input" id="' + pid + '-beds" value="' + (curBeds + 1) + '" min="1" max="12" style="width:40px"></td>'
            + '<td><input type="number" class="flip-proj-input" id="' + pid + '-baths" value="' + (curBaths + 1) + '" min="1" max="8" step="0.5" style="width:45px"></td>'
            + '<td><input type="number" class="flip-proj-input" id="' + pid + '-sqft" value="' + (curSqft + 500) + '" min="500" max="20000" step="100" style="width:60px"></td>'
            + '<td class="flip-proj-arv" id="' + pid + '-arv">--</td>'
            + '<td class="flip-proj-profit" id="' + pid + '-profit">--</td>'
            + '<td class="flip-proj-roi" id="' + pid + '-roi">--</td>'
            + '</tr>';

        html += '</tbody></table></div>';

        // Store calc params for the custom row event handler
        html += '<span class="flip-proj-data" style="display:none"'
            + ' data-ppsf="' + ppsf + '"'
            + ' data-discount="' + discount + '"'
            + ' data-rehab="' + rehabPerSqft + '"'
            + ' data-cur-sqft="' + curSqft + '"'
            + ' data-list-price="' + listPrice + '"'
            + ' data-pid="' + pid + '"'
            + '></span>';

        return html;
    }

    // Shared cost calculation for projections (matches PHP v0.8.0 constants)
    function calcProjectionCosts(listPrice, arv, rehab) {
        // Scaled contingency based on effective $/sqft (approximate from rehab total)
        var estSqft = arv > 0 ? arv / 350 : 1500; // rough sqft estimate
        var effectivePerSqft = rehab / estSqft;
        var contRate;
        if (effectivePerSqft <= 20) contRate = 0.08;
        else if (effectivePerSqft <= 35) contRate = 0.12;
        else if (effectivePerSqft <= 50) contRate = 0.15;
        else contRate = 0.20;

        var contingency = rehab * contRate;
        var totalRehab = rehab + contingency;

        // MA transfer tax (0.456%)
        var transferTaxBuy = listPrice * 0.00456;
        var transferTaxSell = arv * 0.00456;
        var purchaseClosing = listPrice * 0.015 + transferTaxBuy;
        var saleCosts = arv * (0.045 + 0.01) + transferTaxSell; // 4.5% comm + 1% closing + transfer tax

        // Estimate hold months from rehab scope
        var holdMonths;
        if (effectivePerSqft <= 20) holdMonths = 3;
        else if (effectivePerSqft <= 35) holdMonths = 4;
        else if (effectivePerSqft <= 50) holdMonths = 6;
        else holdMonths = 8;

        var monthlyTax = (listPrice * 0.013) / 12;
        var monthlyIns = (listPrice * 0.005) / 12;
        var holdingCosts = (monthlyTax + monthlyIns + 350) * holdMonths;

        // Cash scenario
        var cashProfit = arv - listPrice - totalRehab - purchaseClosing - saleCosts - holdingCosts;
        var cashInvestment = listPrice + totalRehab + purchaseClosing + holdingCosts;
        var cashRoi = cashInvestment > 0 ? (cashProfit / cashInvestment) * 100 : 0;

        // Hard money scenario (10.5%, 2 pts, 80% LTV)
        var loanAmount = listPrice * 0.80;
        var financingCosts = (loanAmount * 0.02) + (loanAmount * 0.105 / 12 * holdMonths);
        var finProfit = cashProfit - financingCosts;
        var cashInvested = (listPrice * 0.20) + totalRehab + purchaseClosing;
        var cocRoi = cashInvested > 0 ? (finProfit / cashInvested) * 100 : 0;

        return { profit: finProfit, roi: cocRoi, cashProfit: cashProfit, cashRoi: cashRoi };
    }

    // Live-update custom projection row when inputs change
    $(document).on('input', '.flip-proj-input', function () {
        var $row = $(this).closest('tr');
        var pid = $row.data('pid');
        var $data = $row.closest('.flip-details').find('.flip-proj-data[data-pid="' + pid + '"]');

        if (!$data.length) return;

        var ppsf = parseFloat($data.data('ppsf'));
        var discount = parseFloat($data.data('discount'));
        var rehabPerSqft = parseFloat($data.data('rehab'));
        var curSqft = parseInt($data.data('cur-sqft'));
        var listPrice = parseFloat($data.data('list-price'));

        var targetSqft = parseInt($('#' + pid + '-sqft').val()) || curSqft;
        var projArv = Math.round(ppsf * targetSqft * discount);
        var addedSqft = Math.max(0, targetSqft - curSqft);
        var totalRehab = Math.round(rehabPerSqft * curSqft + addedSqft * 250);
        var projCosts = calcProjectionCosts(listPrice, projArv, totalRehab);
        var profitCls = projCosts.profit >= 0 ? 'flip-positive' : 'flip-negative';

        $('#' + pid + '-arv').text(formatCurrency(projArv));
        $('#' + pid + '-profit').attr('class', profitCls).text(formatCurrency(projCosts.profit));
        $('#' + pid + '-roi').attr('class', profitCls).text(projCosts.roi.toFixed(1) + '%');
    });

    // ── PDF Generation ─────────────────────────────

    function generatePDF($btn) {
        var listingId = $btn.data('listing');
        $btn.prop('disabled', true).text('Generating PDF...');

        // Open window immediately (user-initiated) to avoid popup blocker
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
                        // Fallback if popup was still blocked
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
    }

    // ── Force Analyze (DQ bypass) ───────────────────

    function forceAnalyze($btn) {
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
                    for (var i = 0; i < dashData.results.length; i++) {
                        if (dashData.results[i].listing_id === newResult.listing_id) {
                            idx = i;
                            break;
                        }
                    }
                    if (idx !== -1) {
                        dashData.results[idx] = newResult;
                    } else {
                        dashData.results.push(newResult);
                    }
                    applyFilters();
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
    }

    // ── City Management ─────────────────────────────

    function renderCityTags(cities) {
        var $container = $('#flip-city-tags');
        $container.empty();

        cities.forEach(function (city) {
            var $tag = $('<span class="flip-city-tag">'
                + escapeHtml(city)
                + '<button class="flip-city-remove" data-city="' + escapeHtml(city) + '" title="Remove ' + escapeHtml(city) + '">&times;</button>'
                + '</span>');
            $container.append($tag);
        });

        // Bind remove buttons
        $container.find('.flip-city-remove').on('click', function () {
            var cityToRemove = $(this).data('city');
            removeCity(cityToRemove);
        });
    }

    function addCity() {
        var city = $.trim($('#flip-city-input').val());
        if (!city) return;

        // Title case
        city = city.replace(/\w\S*/g, function (txt) {
            return txt.charAt(0).toUpperCase() + txt.substr(1).toLowerCase();
        });

        var cities = dashData.cities || [];
        if (cities.indexOf(city) !== -1) {
            $('#flip-city-status').text(city + ' is already in the list.').css('color', '#cc1818');
            setTimeout(function () { $('#flip-city-status').text(''); }, 3000);
            return;
        }

        cities.push(city);
        saveCities(cities);
        $('#flip-city-input').val('');
    }

    function removeCity(city) {
        var cities = (dashData.cities || []).filter(function (c) { return c !== city; });
        if (cities.length === 0) {
            alert('You must have at least one target city.');
            return;
        }
        saveCities(cities);
    }

    function saveCities(cities) {
        $('#flip-city-status').text('Saving...').css('color', '#666');

        $.ajax({
            url: flipData.ajaxUrl,
            method: 'POST',
            data: {
                action: 'flip_update_cities',
                nonce: flipData.nonce,
                cities: cities.join(','),
            },
            success: function (response) {
                if (response.success) {
                    dashData.cities = response.data.cities;
                    renderCityTags(dashData.cities);
                    populateCityFilter(dashData.cities);
                    $('#flip-city-status').text(response.data.message).css('color', '#00a32a');
                } else {
                    $('#flip-city-status').text('Error: ' + (response.data || 'Unknown')).css('color', '#cc1818');
                }
                setTimeout(function () { $('#flip-city-status').text(''); }, 3000);
            },
            error: function () {
                $('#flip-city-status').text('Request failed.').css('color', '#cc1818');
                setTimeout(function () { $('#flip-city-status').text(''); }, 3000);
            }
        });
    }

    // ── Run Analysis ───────────────────────────────

    function runAnalysis() {
        if (!confirm('Run analysis on all target cities? This may take 1-3 minutes.')) {
            return;
        }

        $('#flip-modal-overlay').show();
        $('#flip-run-analysis').prop('disabled', true);

        $.ajax({
            url: flipData.ajaxUrl,
            method: 'POST',
            timeout: 300000, // 5 minutes
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

                    // Refresh dashboard with new data
                    if (d.dashboard) {
                        dashData = d.dashboard;
                        renderStats(dashData.summary);
                        renderChart(dashData.summary.cities || []);
                        applyFilters();
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
                    refreshData();
                } else {
                    alert('Request failed: ' + status);
                }
            }
        });
    }

    // ── Photo Analysis ─────────────────────────────

    function runPhotoAnalysis() {
        // Count viable properties to estimate cost
        var viable = (dashData.results || []).filter(function (r) {
            return !r.disqualified && r.total_score >= 40;
        });
        var count = viable.length;

        if (count === 0) {
            alert('No viable properties to analyze. Run the data analysis first.');
            return;
        }

        var estCost = (count * 0.04).toFixed(2); // ~$0.03-0.05 per property avg
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
            timeout: 600000, // 10 minutes
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
                        dashData = d.dashboard;
                        renderStats(dashData.summary);
                        renderChart(dashData.summary.cities || []);
                        applyFilters();
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
                    refreshData();
                } else {
                    alert('Request failed: ' + status);
                }
            }
        });
    }

    function refreshData() {
        $.ajax({
            url: flipData.ajaxUrl,
            method: 'POST',
            data: {
                action: 'flip_refresh_data',
                nonce: flipData.nonce,
            },
            success: function (response) {
                if (response.success) {
                    dashData = response.data;
                    renderStats(dashData.summary);
                    renderChart(dashData.summary.cities || []);
                    applyFilters();
                }
            }
        });
    }

    // ── Export CSV ──────────────────────────────────

    function exportCSV() {
        var results = getFilteredResults();
        if (results.length === 0) {
            alert('No results to export.');
            return;
        }

        var headers = [
            'MLS#', 'Address', 'City', 'Total Score', 'Risk Grade',
            'Financial', 'Property', 'Location', 'Market', 'Photo',
            'List Price', 'ARV', 'ARV Confidence', 'Comps',
            'Rehab Cost', 'Rehab Level', 'Rehab Multiplier', 'Contingency',
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
    }

    // ── Helpers ────────────────────────────────────

    function formatCurrency(num) {
        if (num === null || num === undefined || isNaN(num)) return '--';
        var neg = num < 0;
        var abs = Math.abs(num);
        var formatted;
        if (abs >= 1000000) {
            formatted = '$' + (abs / 1000000).toFixed(2) + 'M';
        } else if (abs >= 1000) {
            formatted = '$' + Math.round(abs).toLocaleString();
        } else {
            formatted = '$' + abs.toFixed(0);
        }
        return neg ? '-' + formatted : formatted;
    }

    function scoreClass(score) {
        if (score >= 80) return 'flip-score-high';
        if (score >= 65) return 'flip-score-good';
        if (score >= 50) return 'flip-score-mid';
        if (score >= 30) return 'flip-score-low';
        return 'flip-score-poor';
    }

    function marketStrengthBadge(strength) {
        var labels = {
            'very_hot': 'Very Hot',
            'hot': 'Hot',
            'balanced': 'Balanced',
            'soft': 'Soft',
            'cold': 'Cold',
        };
        var colors = {
            'very_hot': '#d63384',
            'hot': '#dc3545',
            'balanced': '#6c757d',
            'soft': '#0d6efd',
            'cold': '#0dcaf0',
        };
        var label = labels[strength] || strength;
        var color = colors[strength] || '#6c757d';
        return '<span style="display:inline-block;padding:1px 6px;border-radius:3px;font-size:11px;font-weight:600;color:#fff;background:' + color + '">' + escapeHtml(label) + '</span>';
    }

    function roadBadge(type) {
        if (!type || type === 'unknown') {
            return '<span class="flip-road-badge flip-road-unknown">Unknown</span>';
        }

        var labels = {
            'cul-de-sac': 'Cul-de-sac',
            'quiet-residential': 'Quiet',
            'moderate-traffic': 'Moderate',
            'busy-road': 'Busy',
            'highway-adjacent': 'Highway',
        };

        var classes = {
            'cul-de-sac': 'flip-road-culdesac',
            'quiet-residential': 'flip-road-quiet',
            'moderate-traffic': 'flip-road-moderate',
            'busy-road': 'flip-road-busy',
            'highway-adjacent': 'flip-road-highway',
        };

        var label = labels[type] || type;
        var cls = classes[type] || 'flip-road-unknown';

        return '<span class="flip-road-badge ' + cls + '">' + escapeHtml(label) + '</span>';
    }

    function kv(key, val) {
        return '<div class="flip-kv"><span class="flip-kv-key">' + key + '</span>'
            + '<span class="flip-kv-val">' + val + '</span></div>';
    }

    function escapeHtml(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

})(jQuery);
