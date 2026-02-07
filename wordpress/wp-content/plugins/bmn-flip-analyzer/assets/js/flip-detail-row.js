/**
 * FlipDashboard Detail Row — Expanded row builders for score breakdown,
 * financials, property details, comps, photo analysis, and remarks.
 */
(function (FD, $) {
    'use strict';

    var h = FD.helpers;

    FD.detail.buildDetailRow = function (r) {
        var html = '<tr class="flip-detail-row"><td colspan="12">';

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

        html += '<div class="flip-detail-col">';
        html += FD.detail.buildScoreSection(r);
        html += FD.detail.buildFinancialSection(r);
        html += '</div>';

        html += '<div class="flip-detail-col">';
        html += FD.detail.buildPropertySection(r);
        html += FD.detail.buildCompsSection(r);
        html += '</div>';

        html += '<div class="flip-detail-col">';
        html += FD.detail.buildPhotoSection(r);
        html += FD.projections.buildProjectionSection(r);
        html += FD.detail.buildRemarksSection(r);
        html += '</div>';

        html += '</div></td></tr>';
        return $(html);
    };

    FD.detail.buildScoreSection = function (r) {
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
                + '<div class="flip-bar-fill ' + h.scoreClass(b.score) + '" style="width:' + pct + '%">'
                + b.score.toFixed(1) + suffix
                + '</div></div></div>';
        });

        html += '</div></div>';
        return html;
    };

    FD.detail.buildFinancialSection = function (r) {
        var valuation = FD.detail.calcValuationRange(r);

        var holdingCosts = r.holding_costs || 0;
        var financingCosts = r.financing_costs || 0;
        var contingency = r.rehab_contingency || 0;
        var holdMonths = r.hold_months || 6;
        var rehabMultiplier = r.rehab_multiplier || 1.0;
        var transferTaxBuy = r.transfer_tax_buy || 0;
        var transferTaxSell = r.transfer_tax_sell || 0;
        var purchaseClosing = r.list_price * 0.015 + transferTaxBuy;
        var saleCostPct = 0.045 + 0.01;
        var saleCosts = r.estimated_arv * saleCostPct + transferTaxSell;

        var html = '<div class="flip-section"><h4>Financial Summary</h4><div class="flip-kv-list">';

        if (r.deal_risk_grade) {
            html += h.kv('Deal Risk', '<span class="flip-risk-badge flip-risk-' + r.deal_risk_grade + '">' + r.deal_risk_grade + '</span>');
        }

        if (r.market_strength && r.market_strength !== 'balanced') {
            var msBadge = h.marketStrengthBadge(r.market_strength);
            html += h.kv('Market Signal', msBadge + ' <span style="color:#999;font-size:11px">(S/L: ' + (r.avg_sale_to_list || 1).toFixed(3) + ')</span>');
        }

        html += h.kv('Floor Value', h.formatCurrency(valuation.floor));
        var arvLabel = h.formatCurrency(r.estimated_arv)
            + ' <span class="flip-conf-' + (r.arv_confidence || 'low') + '">(' + (r.arv_confidence || 'n/a') + ')</span>';
        if (r.road_arv_discount && r.road_arv_discount > 0) {
            arvLabel += ' <span style="color:#cc1818;font-size:11px">(-' + Math.round(r.road_arv_discount * 100) + '% road adj.)</span>';
        }
        html += h.kv('Mid Value (ARV)', arvLabel);
        html += h.kv('Ceiling Value', h.formatCurrency(valuation.ceiling));

        if (r.breakeven_arv && r.estimated_arv > 0) {
            var beMargin = ((r.estimated_arv - r.breakeven_arv) / r.estimated_arv * 100).toFixed(1);
            var beCls = beMargin >= 10 ? 'flip-positive' : (beMargin >= 5 ? '' : 'flip-negative');
            html += h.kv('Breakeven ARV', h.formatCurrency(r.breakeven_arv)
                + ' <span class="' + beCls + '" style="font-size:11px">(' + beMargin + '% margin)</span>');
        }

        html += h.kv('Comps Used', r.comp_count);

        html += '<div style="border-top:1px solid #ddd;margin:8px 0 4px;padding-top:6px"></div>';
        html += h.kv('Purchase Price', h.formatCurrency(r.list_price));

        var rehabNote = r.rehab_level || '?';
        var ageCondMult = r.age_condition_multiplier || 1.0;
        if (ageCondMult < 1.0) {
            rehabNote += ', <span class="flip-age-mult">' + ageCondMult.toFixed(2) + 'x age</span>';
        }
        if (rehabMultiplier !== 1.0) {
            rehabNote += ', ' + rehabMultiplier.toFixed(2) + 'x remarks';
        }
        var baseRehab = r.estimated_rehab_cost - contingency;
        html += h.kv('Rehab Cost', h.formatCurrency(baseRehab) + ' <span style="color:#999;font-size:11px">(' + rehabNote + ')</span>');

        if (r.lead_paint_flag) {
            html += h.kv('<span class="flip-lead-badge">Pb</span> Lead Paint', '<span style="color:#856404">$8K included in rehab</span>');
        }

        var contPct = contingency > 0 && baseRehab > 0 ? Math.round(contingency / baseRehab * 100) : 10;
        html += h.kv('+ Contingency (' + contPct + '%)', h.formatCurrency(contingency));
        html += h.kv('Purchase Closing', h.formatCurrency(purchaseClosing)
            + ' <span style="color:#999;font-size:11px">(1.5% + ' + h.formatCurrency(transferTaxBuy) + ' transfer tax)</span>');
        html += h.kv('Sale Costs', h.formatCurrency(saleCosts)
            + ' <span style="color:#999;font-size:11px">(4.5% comm + 1% + ' + h.formatCurrency(transferTaxSell) + ' tax)</span>');
        html += h.kv('Holding Costs', h.formatCurrency(holdingCosts) + ' <span style="color:#999;font-size:11px">(' + holdMonths + ' mo: tax+ins+util)</span>');

        html += '<div style="border-top:1px solid #ddd;margin:4px 0"></div>';
        html += '<div style="font-weight:600;font-size:12px;color:#333;margin:4px 0">Cash Purchase:</div>';
        var cashProfitCls = (r.cash_profit || 0) >= 0 ? 'flip-positive' : 'flip-negative';
        html += h.kv('Profit', '<strong class="' + cashProfitCls + '">' + h.formatCurrency(r.cash_profit) + '</strong>');
        html += h.kv('ROI', '<strong class="' + cashProfitCls + '">' + (r.cash_roi || 0).toFixed(1) + '%</strong>');

        html += '<div style="font-weight:600;font-size:12px;color:#333;margin:4px 0">Hard Money (10.5%, 2 pts, 80% LTV):</div>';
        html += h.kv('Financing Costs', h.formatCurrency(financingCosts));
        var finProfitCls = r.estimated_profit >= 0 ? 'flip-positive' : 'flip-negative';
        html += h.kv('Profit', '<strong class="' + finProfitCls + '">' + h.formatCurrency(r.estimated_profit) + '</strong>');
        html += h.kv('Cash-on-Cash ROI', '<strong class="' + finProfitCls + '">' + (r.cash_on_cash_roi || 0).toFixed(1) + '%</strong>');
        if (r.annualized_roi) {
            var annCls = r.annualized_roi >= 0 ? 'flip-positive' : 'flip-negative';
            html += h.kv('Annualized ROI', '<strong class="' + annCls + '">' + r.annualized_roi.toFixed(1) + '%</strong>');
        }

        html += '<div style="border-top:1px solid #ddd;margin:4px 0"></div>';
        html += h.kv('MAO (70% rule)', h.formatCurrency(r.mao));
        if (r.adjusted_mao !== undefined) {
            html += h.kv('Adjusted MAO', h.formatCurrency(r.adjusted_mao || (r.mao - holdingCosts - financingCosts))
                + ' <span style="color:#999;font-size:11px">(incl. holding+financing)</span>');
        } else {
            var adjMao = (r.mao || 0) - holdingCosts - financingCosts;
            html += h.kv('Adjusted MAO', h.formatCurrency(adjMao)
                + ' <span style="color:#999;font-size:11px">(incl. holding+financing)</span>');
        }

        html += FD.detail.buildSensitivitySection(r);

        if (r.applied_thresholds) {
            var t = r.applied_thresholds;
            html += '<div style="border-top:1px solid #ddd;margin:8px 0 4px;padding-top:6px"></div>';
            html += '<div style="font-weight:600;font-size:12px;color:#333;margin:4px 0">Thresholds Applied:</div>';
            var profitNote = t.market_strength !== 'balanced'
                ? ' <span style="color:#856404;font-size:11px">(adj. from $25K)</span>' : '';
            var roiNote = t.market_strength !== 'balanced'
                ? ' <span style="color:#856404;font-size:11px">(adj. from 15%)</span>' : '';
            html += h.kv('Min Profit', h.formatCurrency(t.min_profit) + profitNote);
            html += h.kv('Min ROI', t.min_roi.toFixed(1) + '%' + roiNote);
            html += h.kv('Max Price/ARV', (t.max_price_arv * 100).toFixed(0) + '%');
            html += h.kv('Market', h.marketStrengthBadge(t.market_strength)
                + ' <span style="color:#999;font-size:11px">(x' + t.multiplier.toFixed(3) + ')</span>');
        }

        html += '</div></div>';
        return html;
    };

    FD.detail.buildSensitivitySection = function (r) {
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
            var costs = FD.projections.calcProjectionCosts(r.list_price, adjArv, adjRehab - (r.rehab_contingency || 0));
            var cls = costs.profit >= 0 ? 'flip-positive' : 'flip-negative';

            html += '<tr>'
                + '<td><strong>' + s.label + '</strong></td>'
                + '<td>' + h.formatCurrency(adjArv) + ' <span style="color:#999;font-size:10px">(' + Math.round(s.arvMult * 100) + '%)</span></td>'
                + '<td>' + h.formatCurrency(adjRehab) + ' <span style="color:#999;font-size:10px">(' + Math.round(s.rehabMult * 100) + '%)</span></td>'
                + '<td class="' + cls + '">' + h.formatCurrency(costs.profit) + '</td>'
                + '<td class="' + cls + '">' + costs.roi.toFixed(1) + '%</td>'
                + '</tr>';
        });

        html += '</tbody></table>';
        return html;
    };

    FD.detail.calcValuationRange = function (r) {
        var arv = r.estimated_arv || 0;
        var confidence = r.arv_confidence || 'low';

        var spread;
        switch (confidence) {
            case 'high':   spread = 0.10; break;
            case 'medium': spread = 0.15; break;
            default:       spread = 0.20; break;
        }

        return {
            floor:   Math.round(arv * (1 - spread)),
            mid:     arv,
            ceiling: Math.round(arv * (1 + spread)),
        };
    };

    FD.detail.buildPropertySection = function (r) {
        var html = '<div class="flip-section"><h4>Property Details</h4><div class="flip-kv-list">';
        html += h.kv('Beds / Baths', r.bedrooms_total + ' / ' + r.bathrooms_total.toFixed(1));
        html += h.kv('Sq Ft', r.building_area_total ? r.building_area_total.toLocaleString() : '--');
        html += h.kv('Year Built', r.year_built || '--');
        html += h.kv('Lot Size', r.lot_size_acres ? r.lot_size_acres.toFixed(2) + ' acres' : '--');
        html += h.kv('Road Type', h.roadBadge(r.road_type));
        html += h.kv('Nbhd Ceiling', r.neighborhood_ceiling ? h.formatCurrency(r.neighborhood_ceiling) : '--');

        if (r.ceiling_pct) {
            var ceilClass = r.ceiling_pct > 100 ? 'flip-negative' : 'flip-positive';
            html += h.kv('ARV / Ceiling', '<span class="' + ceilClass + '">' + r.ceiling_pct.toFixed(0) + '%</span>');
        }

        if (r.building_area_total && r.lot_size_acres) {
            var lotSqFt = r.lot_size_acres * 43560;
            var ratio = (lotSqFt / r.building_area_total).toFixed(1);
            html += h.kv('Lot/House Ratio', ratio + 'x');
        }

        html += '</div></div>';
        return html;
    };

    FD.detail.buildCompsSection = function (r) {
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
                    adjCell = '<td>' + h.formatCurrency(c.adjusted_price)
                        + ' <span style="color:' + adjColor + ';font-size:10px">(' + adjSign + h.formatCurrency(c.total_adjustment) + ')</span>';

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
                            adjCell = '<td title="' + h.escapeHtml(details.join('\n')) + '">'
                                + h.formatCurrency(c.adjusted_price)
                                + ' <span style="color:' + adjColor + ';font-size:10px">(' + adjSign + h.formatCurrency(c.total_adjustment) + ')</span>';
                        }
                    }
                    adjCell += '</td>';
                } else {
                    adjCell = '<td>' + h.formatCurrency(c.close_price) + '</td>';
                }
            }

            var ppsf = c.adjusted_ppsf || c.ppsf;

            var compAddr = h.escapeHtml(c.address || 'N/A');
            if (c.listing_id) {
                compAddr = '<a href="' + flipData.siteUrl + '/property/' + c.listing_id + '/" target="_blank" class="flip-view-link">' + compAddr + '</a>';
            }

            html += '<tr>'
                + '<td>' + compAddr + '</td>'
                + '<td>' + h.formatCurrency(c.close_price) + '</td>'
                + '<td>$' + (ppsf ? ppsf.toFixed(0) : '--') + '</td>'
                + adjCell
                + '<td>' + (c.distance_miles ? c.distance_miles.toFixed(2) + 'mi' : '--') + '</td>'
                + '<td>' + (c.close_date || '--') + '</td>'
                + '</tr>';
        });

        html += '</tbody></table></div>';
        return html;
    };

    FD.detail.buildPhotoSection = function (r) {
        if (!r.photo_analysis) {
            return '<div class="flip-section"><h4>Photo Analysis</h4>'
                + '<p style="color:#999;font-size:12px">Not yet analyzed. Click "Run Photo Analysis" above to analyze viable properties.</p></div>';
        }

        var pa = r.photo_analysis;
        var html = '<div class="flip-section"><h4>Photo Analysis</h4>';

        if (r.main_photo_url) {
            html += '<img class="flip-photo-thumb" src="' + h.escapeHtml(r.main_photo_url) + '" alt="Property photo">';
        }

        html += '<div class="flip-kv-list">';
        html += h.kv('Overall Condition', pa.overall_condition ? pa.overall_condition + '/10' : '--');
        html += h.kv('Renovation Level', pa.renovation_level || '--');
        html += h.kv('Est. Cost/SqFt', pa.estimated_cost_per_sqft ? '$' + pa.estimated_cost_per_sqft : '--');
        html += h.kv('Photo Score', r.photo_score !== null ? r.photo_score.toFixed(0) + '/100' : '--');

        var areaKeys = [
            { key: 'kitchen_condition', label: 'Kitchen' },
            { key: 'bathroom_condition', label: 'Bathroom' },
            { key: 'flooring_condition', label: 'Flooring' },
            { key: 'exterior_condition', label: 'Exterior' },
            { key: 'curb_appeal', label: 'Curb Appeal' },
        ];
        areaKeys.forEach(function (item) {
            if (pa[item.key] !== undefined && pa[item.key] !== null) {
                html += h.kv(item.label, pa[item.key] + '/10');
            }
        });

        html += '</div>';

        if (pa.structural_concerns && pa.structural_details) {
            html += '<div style="margin-top:8px;padding:6px 8px;background:#fff3cd;border-radius:4px;font-size:12px">';
            html += '<strong>Structural Concerns:</strong> ' + h.escapeHtml(pa.structural_details);
            html += '</div>';
        }

        if (pa.renovation_summary) {
            html += '<div style="margin-top:6px;font-size:12px;color:#555">' + h.escapeHtml(pa.renovation_summary) + '</div>';
        }

        html += '</div>';
        return html;
    };

    FD.detail.buildRemarksSection = function (r) {
        var signals = r.remarks_signals;
        if (!signals || typeof signals !== 'object') {
            return '';
        }

        var positives = signals.positive || [];
        var negatives = signals.negative || [];

        if (positives.length === 0 && negatives.length === 0) {
            return '';
        }

        var html = '<div class="flip-section"><h4>Remarks Signals</h4>';

        positives.forEach(function (kw) {
            html += '<span class="flip-signal flip-signal-positive">' + h.escapeHtml(kw) + '</span>';
        });

        negatives.forEach(function (kw) {
            html += '<span class="flip-signal flip-signal-negative">' + h.escapeHtml(kw) + '</span>';
        });

        if (signals.adjustment) {
            var adjClass = signals.adjustment > 0 ? 'flip-positive' : 'flip-negative';
            var sign = signals.adjustment > 0 ? '+' : '';
            html += '<div style="margin-top:6px;font-size:12px">Score adjustment: <strong class="' + adjClass + '">'
                + sign + signals.adjustment + '</strong></div>';
        }

        html += '</div>';
        return html;
    };

})(window.FlipDashboard, jQuery);
