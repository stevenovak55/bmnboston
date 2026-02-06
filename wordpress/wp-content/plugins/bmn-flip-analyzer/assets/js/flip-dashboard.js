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
            applyFilters();
        } catch (e) {
            console.error('[FlipDashboard] Init error:', e);
        }

        // Event handlers - use delegation on tbody for dynamic rows
        var $tbody = $('#flip-results-body');
        $tbody.on('click', '.flip-toggle', function (e) {
            e.stopPropagation();
            toggleRow($(this));
        });
        $tbody.on('click', 'tr.flip-main-row', function (e) {
            // Don't fire if they clicked the toggle button or a link
            if ($(e.target).closest('.flip-toggle').length) return;
            if ($(e.target).closest('a').length) return;
            toggleRow($(this).find('.flip-toggle'));
        });

        $('#flip-run-analysis').on('click', runAnalysis);
        $('#flip-run-photos').on('click', runPhotoAnalysis);
        $('#flip-export-csv').on('click', exportCSV);
        $('#filter-city, #filter-sort, #filter-show').on('change', applyFilters);
        $('#filter-score').on('input', function () {
            $('#score-display').text(this.value);
            applyFilters();
        });
    });

    // ── Summary Stats ──────────────────────────────

    function renderStats(summary) {
        $('#stat-total').text(summary.total || 0);
        $('#stat-viable').text(summary.viable || 0);
        $('#stat-avg-score').text(summary.avg_score ? summary.avg_score.toFixed(1) : '--');
        $('#stat-avg-roi').text(summary.avg_roi ? summary.avg_roi.toFixed(1) + '%' : '--');
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
        cities.forEach(function (city) {
            $select.append('<option value="' + escapeHtml(city) + '">' + escapeHtml(city) + '</option>');
        });
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
        var dqClass = r.disqualified ? ' flip-row-dq' : '';
        var scoreHtml = r.disqualified
            ? '<span class="flip-score-badge flip-score-poor">DQ</span>'
            : '<span class="flip-score-badge ' + scoreClass(r.total_score) + '">' + r.total_score.toFixed(1) + '</span>';

        var photoHtml = r.photo_score !== null
            ? '<span class="flip-score-badge ' + scoreClass(r.photo_score) + '">' + r.photo_score.toFixed(0) + '</span>'
            : '<span style="color:#ccc">--</span>';

        var profitClass = r.estimated_profit >= 0 ? '' : ' flip-negative';

        var dqNote = r.disqualified && r.disqualify_reason
            ? '<div class="flip-dq-reason">' + escapeHtml(r.disqualify_reason) + '</div>'
            : '';

        var propertyUrl = flipData.siteUrl + '/property/' + r.listing_id + '/';
        var domText = r.days_on_market ? r.days_on_market + 'd' : '--';

        var html = '<tr class="flip-main-row' + dqClass + '" data-idx="' + idx + '">'
            + '<td class="flip-col-toggle"><button class="flip-toggle" data-idx="' + idx + '">+</button></td>'
            + '<td><div class="flip-property-cell">'
            + '<span class="flip-property-address">' + escapeHtml(r.address) + '</span>'
            + '<span class="flip-property-mls">MLS# ' + r.listing_id
            + ' &middot; <a href="' + propertyUrl + '" target="_blank" class="flip-view-link">View</a></span>'
            + dqNote
            + '</div></td>'
            + '<td>' + escapeHtml(r.city) + '</td>'
            + '<td class="flip-col-num">' + scoreHtml + '</td>'
            + '<td class="flip-col-num">' + formatCurrency(r.list_price) + '</td>'
            + '<td class="flip-col-num">' + formatCurrency(r.estimated_arv) + '</td>'
            + '<td class="flip-col-num' + profitClass + '">' + formatCurrency(r.estimated_profit) + '</td>'
            + '<td class="flip-col-num">' + (r.estimated_roi ? r.estimated_roi.toFixed(1) + '%' : '--') + '</td>'
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
        var html = '<tr class="flip-detail-row"><td colspan="11"><div class="flip-details">';

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
        var profitClass = r.estimated_profit >= 0 ? 'flip-positive' : 'flip-negative';
        var roiClass = r.estimated_roi >= 0 ? 'flip-positive' : 'flip-negative';
        var valuation = calcValuationRange(r);

        // Cost breakdown (matches PHP constants)
        var purchaseClosing = r.list_price * 0.015;
        var saleCosts = r.estimated_arv * 0.06;
        var holdingCosts = (r.list_price + r.estimated_rehab_cost) * 0.008 * 6;
        var totalAllIn = r.list_price + r.estimated_rehab_cost + purchaseClosing + saleCosts + holdingCosts;

        var html = '<div class="flip-section"><h4>Financial Summary</h4><div class="flip-kv-list">';

        // Valuation
        html += kv('Floor Value', formatCurrency(valuation.floor));
        var arvLabel = formatCurrency(r.estimated_arv)
            + ' <span class="flip-conf-' + (r.arv_confidence || 'low') + '">(' + (r.arv_confidence || 'n/a') + ')</span>';
        if (r.road_arv_discount && r.road_arv_discount > 0) {
            arvLabel += ' <span style="color:#cc1818;font-size:11px">(-' + Math.round(r.road_arv_discount * 100) + '% road adj.)</span>';
        }
        html += kv('Mid Value (ARV)', arvLabel);
        html += kv('Ceiling Value', formatCurrency(valuation.ceiling));
        html += kv('Comps Used', r.comp_count);

        // Cost breakdown
        html += '<div style="border-top:1px solid #ddd;margin:8px 0 4px;padding-top:6px"></div>';
        html += kv('Purchase Price', formatCurrency(r.list_price));
        html += kv('Rehab Cost', formatCurrency(r.estimated_rehab_cost) + ' <span style="color:#999;font-size:11px">(' + (r.rehab_level || '?') + ')</span>');
        html += kv('Purchase Closing', formatCurrency(purchaseClosing) + ' <span style="color:#999;font-size:11px">(1.5%)</span>');
        html += kv('Sale Costs', formatCurrency(saleCosts) + ' <span style="color:#999;font-size:11px">(5% comm + 1%)</span>');
        html += kv('Holding Costs', formatCurrency(holdingCosts) + ' <span style="color:#999;font-size:11px">(6 mo)</span>');

        // Bottom line
        html += '<div style="border-top:1px solid #ddd;margin:4px 0"></div>';
        html += kv('Total All-In', '<strong>' + formatCurrency(totalAllIn) + '</strong>');
        html += kv('Est. Profit', '<strong class="' + profitClass + '">' + formatCurrency(r.estimated_profit) + '</strong>');
        html += kv('Est. ROI', '<strong class="' + roiClass + '">' + r.estimated_roi.toFixed(1) + '%</strong>');
        html += kv('Max Offer Price', formatCurrency(r.mao) + ' <span style="color:#999;font-size:11px">(70% rule)</span>');

        html += '</div></div>';
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

        var html = '<div class="flip-section"><h4>Comparables (' + r.comps.length + ')</h4>';
        html += '<table class="flip-comp-table"><thead><tr>'
            + '<th>Address</th><th>Price</th><th>$/SqFt</th><th>Dist</th><th>Sold</th>'
            + '</tr></thead><tbody>';

        r.comps.forEach(function (c) {
            html += '<tr>'
                + '<td>' + escapeHtml(c.address || 'N/A') + '</td>'
                + '<td>' + formatCurrency(c.close_price) + '</td>'
                + '<td>$' + (c.ppsf ? c.ppsf.toFixed(0) : '--') + '</td>'
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
            var totalRehab = Math.round(rehabPerSqft * curSqft + addedSqft * 80);
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

    // Shared cost calculation for projections (matches PHP constants)
    function calcProjectionCosts(listPrice, arv, rehab) {
        var purchaseClosing = listPrice * 0.015;
        var saleCosts = arv * 0.06;
        var holdingCosts = (listPrice + rehab) * 0.008 * 6;
        var profit = arv - listPrice - rehab - purchaseClosing - saleCosts - holdingCosts;
        var investment = listPrice + rehab + purchaseClosing + holdingCosts;
        var roi = investment > 0 ? (profit / investment) * 100 : 0;
        return { profit: profit, roi: roi };
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
        var totalRehab = Math.round(rehabPerSqft * curSqft + addedSqft * 80);
        var projCosts = calcProjectionCosts(listPrice, projArv, totalRehab);
        var profitCls = projCosts.profit >= 0 ? 'flip-positive' : 'flip-negative';

        $('#' + pid + '-arv').text(formatCurrency(projArv));
        $('#' + pid + '-profit').attr('class', profitCls).text(formatCurrency(projCosts.profit));
        $('#' + pid + '-roi').attr('class', profitCls).text(projCosts.roi.toFixed(1) + '%');
    });

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
            'MLS#', 'Address', 'City', 'Total Score',
            'Financial', 'Property', 'Location', 'Market', 'Photo',
            'List Price', 'ARV', 'ARV Confidence', 'Comps',
            'Rehab Cost', 'Rehab Level', 'MAO', 'Profit', 'ROI',
            'Road Type', 'Ceiling', 'Ceiling %',
            'Beds', 'Baths', 'SqFt', 'Year', 'Lot Acres',
            'Disqualified', 'DQ Reason'
        ];

        var rows = results.map(function (r) {
            return [
                r.listing_id,
                '"' + (r.address || '').replace(/"/g, '""') + '"',
                '"' + (r.city || '').replace(/"/g, '""') + '"',
                r.total_score.toFixed(2),
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
                r.mao.toFixed(0),
                r.estimated_profit.toFixed(0),
                r.estimated_roi.toFixed(2),
                r.road_type || '',
                r.neighborhood_ceiling ? r.neighborhood_ceiling.toFixed(0) : '',
                r.ceiling_pct ? r.ceiling_pct.toFixed(1) : '',
                r.bedrooms_total,
                r.bathrooms_total,
                r.building_area_total,
                r.year_built || '',
                r.lot_size_acres ? r.lot_size_acres.toFixed(2) : '',
                r.disqualified ? 'Yes' : 'No',
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
