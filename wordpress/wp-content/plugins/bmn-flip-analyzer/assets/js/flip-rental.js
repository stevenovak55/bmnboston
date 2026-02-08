/**
 * FlipDashboard Rental — Rental Hold and BRRRR pane builders.
 *
 * Builds the two additional tab panes for multi-exit strategy analysis.
 * Data comes from r.rental_analysis (decoded from rental_analysis_json).
 *
 * v0.16.0: Initial implementation.
 */
(function (FD, $) {
    'use strict';

    var h = FD.helpers;

    /**
     * Build the Rental Hold tab pane content.
     */
    FD.rental.buildRentalPane = function (r) {
        if (!r.rental_analysis || !r.rental_analysis.rental) {
            return '<div class="flip-details"><p class="flip-no-data">Rental analysis not available. Re-run analysis to generate rental data.</p></div>';
        }

        var rental = r.rental_analysis.rental;
        var html = '<div class="flip-details">';

        // Left column: Income & Expenses
        html += '<div class="flip-detail-col">';
        html += buildIncomeSection(rental, r);
        html += buildExpenseSection(rental);
        html += '</div>';

        // Center column: Key Metrics & Tax Benefits
        html += '<div class="flip-detail-col">';
        html += buildMetricsSection(rental);
        html += buildTaxSection(rental);
        html += buildCashFlowSection(rental);
        html += '</div>';

        // Right column: Multi-Year Projections
        html += '<div class="flip-detail-col">';
        html += buildProjectionSection(rental);
        html += '</div>';

        html += '</div>';
        return html;
    };

    /**
     * Build the BRRRR tab pane content.
     */
    FD.rental.buildBRRRRPane = function (r) {
        if (!r.rental_analysis || !r.rental_analysis.brrrr) {
            return '<div class="flip-details"><p class="flip-no-data">BRRRR analysis not available. Re-run analysis to generate BRRRR data.</p></div>';
        }

        var brrrr = r.rental_analysis.brrrr;
        var rental = r.rental_analysis.rental;
        var html = '<div class="flip-details">';

        // Left column: BRRRR Step Flow
        html += '<div class="flip-detail-col">';
        html += buildBRRRRSteps(brrrr, r);
        html += '</div>';

        // Center column: Refinance Details
        html += '<div class="flip-detail-col">';
        html += buildRefiSection(brrrr);
        html += buildBRRRRMetrics(brrrr);
        html += '</div>';

        // Right column: Post-Refi Cash Flow
        html += '<div class="flip-detail-col">';
        html += buildPostRefiSection(brrrr, rental);
        html += buildBRRRRProjections(brrrr);
        html += '</div>';

        html += '</div>';
        return html;
    };

    // ==========================================
    // Rental Pane Sections
    // ==========================================

    function buildIncomeSection(rental, r) {
        var monthlyRent = rental.monthly_rent || 0;
        var html = '<div class="flip-section"><h4>Rental Income</h4><div class="flip-kv-list">';

        var sourceLabel = rental.rent_source || 'unknown';
        var confCls = 'flip-conf-' + (rental.rent_confidence || 'low');
        html += h.kv('Monthly Rent', '<strong>' + h.formatCurrency(monthlyRent) + '</strong>'
            + ' <span class="' + confCls + '">(' + sourceLabel + ')</span>');

        // Show cross-reference indicator if available
        if (rental.cross_reference) {
            var xref = rental.cross_reference;
            var xrefCls = xref.agreement === 'strong' ? 'flip-positive' :
                          xref.agreement === 'moderate' ? '' : 'flip-negative';
            html += h.kv('MLS Cross-Ref', '<span class="' + xrefCls + '">'
                + xref.agreement + ' (' + xref.pct_diff + '% diff)</span>');
        }

        html += h.kv('Annual Gross', h.formatCurrency(rental.annual_gross_income));

        var vacRate = rental.vacancy_rate || 0.05;
        html += h.kv('Vacancy (' + h.formatPercent(vacRate * 100, 0) + ')', '<span class="flip-negative">-' + h.formatCurrency(monthlyRent * 12 * vacRate) + '</span>');
        html += h.kv('Effective Gross', '<strong>' + h.formatCurrency(rental.effective_gross) + '</strong>/yr');

        html += '</div></div>';

        // Rental comp summary (v0.19.0)
        var rc = r.rental_analysis && r.rental_analysis.rental_comps;
        if (rc && rc.comp_count > 0) {
            html += buildRentalCompSection(rc);
        }

        return html;
    }

    /**
     * Build rental comp summary and table (v0.19.0).
     */
    function buildRentalCompSection(rc) {
        var html = '<div class="flip-section"><h4>Rental Comps</h4>';

        // Summary stats
        html += '<div class="flip-kv-list">';
        var confCls = 'flip-conf-' + (rc.confidence || 'low');
        html += h.kv('Comps Found', '<strong>' + rc.comp_count + '</strong>'
            + ' <span style="color:#999;font-size:11px">('
            + rc.active_count + ' active, ' + rc.closed_count + ' leased)</span>');
        html += h.kv('Confidence', '<span class="' + confCls + '">' + rc.confidence + '</span>');
        html += h.kv('Avg Rental $/sqft', '$' + (rc.avg_rental_ppsf || 0).toFixed(2) + '/sqft/mo');
        html += h.kv('Search Radius', rc.search_radius_used + ' mi');

        if (rc.cross_reference) {
            var xref = rc.cross_reference;
            var xrefCls = xref.agreement === 'strong' ? 'flip-positive' :
                          xref.agreement === 'moderate' ? '' : 'flip-negative';
            html += h.kv('vs MLS Income', '<span class="' + xrefCls + '">'
                + xref.agreement + ' (' + xref.pct_diff + '% diff)</span>');
        }
        html += '</div>';

        // Comp table
        var comps = rc.comps;
        if (comps && comps.length > 0) {
            html += '<table class="flip-comp-table flip-rental-comp-table"><thead><tr>'
                + '<th>Address</th><th>Beds</th><th>Sqft</th><th>Rent</th>'
                + '<th>Adj. Rent</th><th>Dist</th><th>Status</th>'
                + '</tr></thead><tbody>';

            comps.forEach(function (c) {
                var status = c.is_closed ? 'Leased' : 'Active';
                var statusCls = c.is_closed ? 'flip-conf-city_lookup' : 'flip-positive';
                var addr = c.address || 'N/A';
                if (addr.length > 22) addr = addr.substring(0, 21) + '~';

                html += '<tr>'
                    + '<td title="' + (c.address || '') + '">' + addr + '</td>'
                    + '<td>' + c.bedrooms + '</td>'
                    + '<td>' + (c.sqft ? c.sqft.toLocaleString() : '--') + '</td>'
                    + '<td>' + h.formatCurrency(c.rent_amount) + '</td>'
                    + '<td>' + h.formatCurrency(c.adjusted_rent) + '</td>'
                    + '<td>' + c.distance_miles + 'mi</td>'
                    + '<td><span class="' + statusCls + '">' + status + '</span></td>'
                    + '</tr>';
            });

            html += '</tbody></table>';
        }

        html += '</div>';
        return html;
    }

    function buildExpenseSection(rental) {
        var exp = rental.expenses;
        if (!exp) return '';

        var html = '<div class="flip-section"><h4>Operating Expenses</h4>';
        html += '<table class="flip-comp-table"><thead><tr><th>Expense</th><th>Annual</th><th>Monthly</th></tr></thead><tbody>';

        var items = [
            { label: 'Property Tax', val: exp.property_tax },
            { label: 'Insurance', val: exp.insurance },
            { label: 'Management', val: exp.management },
            { label: 'Maintenance', val: exp.maintenance },
            { label: 'CapEx Reserve', val: exp.capex_reserve },
        ];

        if (exp.hoa && exp.hoa > 0) {
            items.push({ label: 'HOA', val: exp.hoa });
        }

        items.forEach(function (item) {
            html += '<tr><td>' + item.label + '</td>'
                + '<td>' + h.formatCurrency(item.val) + '</td>'
                + '<td>' + h.formatCurrency(item.val / 12) + '</td></tr>';
        });

        html += '<tr style="font-weight:600;border-top:2px solid #333"><td>Total</td>'
            + '<td>' + h.formatCurrency(exp.total_annual) + '</td>'
            + '<td>' + h.formatCurrency(exp.total_annual / 12) + '</td></tr>';

        html += '</tbody></table></div>';
        return html;
    }

    function buildMetricsSection(rental) {
        var html = '<div class="flip-section"><h4>Key Metrics</h4><div class="flip-kv-list">';

        html += h.kv('NOI', '<strong>' + h.formatCurrency(rental.noi) + '</strong>/yr');

        var capCls = (rental.cap_rate || 0) >= 6 ? 'flip-positive' : ((rental.cap_rate || 0) >= 4 ? '' : 'flip-negative');
        html += h.kv('Cap Rate', '<strong class="' + capCls + '">' + h.formatPercent(rental.cap_rate) + '</strong>');

        var cocCls = (rental.cash_on_cash || 0) >= 8 ? 'flip-positive' : ((rental.cash_on_cash || 0) >= 5 ? '' : 'flip-negative');
        html += h.kv('Cash-on-Cash', '<strong class="' + cocCls + '">' + h.formatPercent(rental.cash_on_cash) + '</strong>');

        if (rental.dscr !== null && rental.dscr !== undefined) {
            var dscrCls = rental.dscr >= 1.25 ? 'flip-positive' : (rental.dscr >= 1.0 ? '' : 'flip-negative');
            html += h.kv('DSCR', '<strong class="' + dscrCls + '">' + rental.dscr.toFixed(2) + '</strong>'
                + ' <span style="color:#999;font-size:11px">(1.25+ good)</span>');
        }

        if (rental.grm) {
            html += h.kv('GRM', rental.grm.toFixed(1) + ' <span style="color:#999;font-size:11px">(lower = better)</span>');
        }

        html += '</div></div>';
        return html;
    }

    function buildTaxSection(rental) {
        var tax = rental.tax_benefits;
        if (!tax) return '';

        var html = '<div class="flip-section"><h4>Tax Benefits</h4><div class="flip-kv-list">';
        html += h.kv('Building Value', h.formatCurrency(tax.depreciable_basis));
        html += h.kv('Annual Depreciation', h.formatCurrency(tax.annual_depreciation)
            + ' <span style="color:#999;font-size:11px">(27.5yr schedule)</span>');
        html += h.kv('Tax Savings', '<strong class="flip-positive">' + h.formatCurrency(tax.annual_tax_savings) + '</strong>/yr'
            + ' <span style="color:#999;font-size:11px">(' + h.formatPercent((tax.marginal_tax_rate || 0.32) * 100, 0) + ' bracket)</span>');
        html += '</div></div>';
        return html;
    }

    function buildCashFlowSection(rental) {
        var html = '<div class="flip-section"><h4>Cash Flow Summary</h4><div class="flip-kv-list">';

        var monthlyCf = (rental.noi || 0) / 12;
        var cfCls = monthlyCf >= 0 ? 'flip-positive' : 'flip-negative';
        html += h.kv('Monthly Cash Flow', '<strong class="' + cfCls + '">' + h.formatMonthly(monthlyCf) + '</strong>');
        html += h.kv('Annual Cash Flow', '<strong class="' + cfCls + '">' + h.formatCurrency(rental.noi) + '</strong>');

        html += h.kv('Total Investment', h.formatCurrency(rental.total_investment));

        html += '</div></div>';
        return html;
    }

    function buildProjectionSection(rental) {
        var proj = rental.projections;
        if (!proj || (typeof proj === 'object' && Object.keys(proj).length === 0)) return '';

        // Projections may be keyed by year (PHP associative array)
        var projArr = Array.isArray(proj) ? proj : Object.values(proj);
        if (projArr.length === 0) return '';

        var html = '<div class="flip-section"><h4>Multi-Year Projections</h4>';
        html += '<table class="flip-comp-table"><thead><tr>'
            + '<th>Year</th><th>Property Value</th><th>Equity</th><th>Cum. Cash Flow</th><th>Total Return</th>'
            + '</tr></thead><tbody>';

        projArr.forEach(function (p) {
            var retCls = (p.total_return_pct || 0) >= 0 ? 'flip-positive' : 'flip-negative';
            html += '<tr>'
                + '<td><strong>' + p.year + '</strong></td>'
                + '<td>' + h.formatCurrency(p.property_value) + '</td>'
                + '<td>' + h.formatCurrency(p.equity_gain) + '</td>'
                + '<td>' + h.formatCurrency(p.cumulative_cf) + '</td>'
                + '<td class="' + retCls + '">' + h.formatPercent(p.total_return_pct) + '</td>'
                + '</tr>';
        });

        html += '</tbody></table></div>';
        return html;
    }

    // ==========================================
    // BRRRR Pane Sections
    // ==========================================

    function buildBRRRRSteps(brrrr, r) {
        var html = '<div class="flip-section"><h4>BRRRR Cycle</h4>';
        html += '<div class="flip-brrrr-steps">';

        var monthlyRent = brrrr.monthly_breakdown ? brrrr.monthly_breakdown.rent : 0;
        var steps = [
            { label: 'Buy', icon: '🏠', value: h.formatCurrency(r.list_price), detail: 'Purchase price' },
            { label: 'Rehab', icon: '🔨', value: h.formatCurrency(r.estimated_rehab_cost), detail: r.rehab_level || 'Rehab' },
            { label: 'Rent', icon: '🔑', value: monthlyRent ? h.formatMonthly(monthlyRent) : '--', detail: 'Monthly income' },
            { label: 'Refinance', icon: '🏦', value: h.formatCurrency(brrrr.refi_loan), detail: h.formatPercent((brrrr.refi_ltv || 0.75) * 100, 0) + ' LTV' },
            { label: 'Repeat', icon: '🔄', value: brrrr.cash_left_in_deal <= 0 ? 'Infinite ROI!' : h.formatCurrency(brrrr.cash_left_in_deal) + ' left', detail: 'Capital recycled' },
        ];

        steps.forEach(function (step, i) {
            html += '<div class="flip-brrrr-step">'
                + '<div class="flip-brrrr-step-icon">' + step.icon + '</div>'
                + '<div class="flip-brrrr-step-label">' + step.label + '</div>'
                + '<div class="flip-brrrr-step-value">' + step.value + '</div>'
                + '<div class="flip-brrrr-step-detail">' + step.detail + '</div>'
                + '</div>';
            if (i < steps.length - 1) {
                html += '<div class="flip-brrrr-arrow">→</div>';
            }
        });

        html += '</div></div>';

        // Total investment summary
        html += '<div class="flip-section"><h4>Capital Required</h4><div class="flip-kv-list">';
        html += h.kv('Purchase + Closing', h.formatCurrency(brrrr.total_cash_in - (brrrr.rehab_cost || 0)));
        html += h.kv('Rehab Cost', h.formatCurrency(brrrr.rehab_cost));
        html += h.kv('Total Cash In', '<strong>' + h.formatCurrency(brrrr.total_cash_in) + '</strong>');
        html += '</div></div>';

        return html;
    }

    function buildRefiSection(brrrr) {
        var refi = brrrr;
        var html = '<div class="flip-section"><h4>Refinance Details</h4><div class="flip-kv-list">';

        html += h.kv('After Repair Value', h.formatCurrency(refi.arv));
        html += h.kv('Refi LTV', h.formatPercent((refi.refi_ltv || 0.75) * 100, 0));
        html += h.kv('Refi Loan Amount', h.formatCurrency(refi.refi_loan));
        html += h.kv('Refi Rate', h.formatPercent((refi.refi_rate || 0.072) * 100, 1));
        html += h.kv('Refi Term', (refi.refi_term || 30) + ' years');
        html += h.kv('Monthly P&I', h.formatMonthly(refi.monthly_payment));

        html += '<div style="border-top:1px solid #ddd;margin:8px 0 4px;padding-top:6px"></div>';

        html += h.kv('Cash Out at Refi', '<strong>' + h.formatCurrency(refi.cash_out) + '</strong>');

        var leftCls = refi.cash_left_in_deal <= 0 ? 'flip-positive' : '';
        var leftLabel = refi.cash_left_in_deal <= 0
            ? '<strong class="flip-positive">$0 — Infinite Return!</strong>'
            : '<strong>' + h.formatCurrency(refi.cash_left_in_deal) + '</strong>';
        html += h.kv('Cash Left in Deal', leftLabel);

        html += h.kv('Equity Captured', '<strong class="flip-positive">' + h.formatCurrency(refi.equity_captured) + '</strong>');

        html += '</div></div>';
        return html;
    }

    function buildBRRRRMetrics(brrrr) {
        var html = '<div class="flip-section"><h4>BRRRR Returns</h4><div class="flip-kv-list">';

        if (brrrr.cash_left_in_deal <= 0) {
            html += h.kv('Cash-on-Cash ROI', '<strong class="flip-positive">∞ Infinite</strong>'
                + ' <span style="color:#999;font-size:11px">(all capital recovered)</span>');
        } else {
            var coc = brrrr.post_refi_cash_on_cash || 0;
            var cocCls = coc >= 10 ? 'flip-positive' : (coc >= 5 ? '' : 'flip-negative');
            html += h.kv('Cash-on-Cash ROI', '<strong class="' + cocCls + '">' + h.formatPercent(coc) + '</strong>');
        }

        // Compute capital recovery % from available data
        var totalIn = brrrr.total_cash_in || 1;
        var capitalRecoveryPct = totalIn > 0 ? ((totalIn - (brrrr.cash_left_in_deal || 0)) / totalIn) * 100 : 0;
        html += h.kv('Capital Recovery', h.formatPercent(capitalRecoveryPct));

        // Compute equity-to-investment %
        var equityToInvPct = totalIn > 0 ? ((brrrr.equity_captured || 0) / totalIn) * 100 : 0;
        html += h.kv('Equity-to-Investment', h.formatPercent(equityToInvPct));

        html += '</div></div>';
        return html;
    }

    function buildPostRefiSection(brrrr, rental) {
        var html = '<div class="flip-section"><h4>Post-Refi Cash Flow</h4>';
        html += '<table class="flip-comp-table"><thead><tr><th>Item</th><th>Monthly</th><th>Annual</th></tr></thead><tbody>';

        var monthlyRent = brrrr.monthly_breakdown ? brrrr.monthly_breakdown.rent : 0;
        var monthlyExpenses = rental && rental.expenses ? rental.expenses.total_annual / 12 : 0;
        var monthlyPI = brrrr.monthly_payment || 0;
        var monthlyCF = monthlyRent - monthlyExpenses - monthlyPI;

        html += '<tr><td>Rental Income</td><td class="flip-positive">' + h.formatCurrency(monthlyRent) + '</td>'
            + '<td class="flip-positive">' + h.formatCurrency(monthlyRent * 12) + '</td></tr>';
        html += '<tr><td>Operating Expenses</td><td class="flip-negative">-' + h.formatCurrency(monthlyExpenses) + '</td>'
            + '<td class="flip-negative">-' + h.formatCurrency(monthlyExpenses * 12) + '</td></tr>';
        html += '<tr><td>Mortgage P&I</td><td class="flip-negative">-' + h.formatCurrency(monthlyPI) + '</td>'
            + '<td class="flip-negative">-' + h.formatCurrency(monthlyPI * 12) + '</td></tr>';

        var cfCls = monthlyCF >= 0 ? 'flip-positive' : 'flip-negative';
        html += '<tr style="font-weight:600;border-top:2px solid #333"><td>Net Cash Flow</td>'
            + '<td class="' + cfCls + '">' + h.formatCurrency(monthlyCF) + '</td>'
            + '<td class="' + cfCls + '">' + h.formatCurrency(monthlyCF * 12) + '</td></tr>';

        html += '</tbody></table></div>';
        return html;
    }

    function buildBRRRRProjections(brrrr) {
        var proj = brrrr.projections;
        if (!proj || (typeof proj === 'object' && Object.keys(proj).length === 0)) return '';

        // Projections may be keyed by year (PHP associative array)
        var projArr = Array.isArray(proj) ? proj : Object.values(proj);
        if (projArr.length === 0) return '';

        var html = '<div class="flip-section"><h4>BRRRR Projections</h4>';
        html += '<table class="flip-comp-table"><thead><tr>'
            + '<th>Year</th><th>Property Value</th><th>Equity</th><th>Cum. Cash Flow</th>'
            + '</tr></thead><tbody>';

        projArr.forEach(function (p) {
            html += '<tr>'
                + '<td><strong>' + p.year + '</strong></td>'
                + '<td>' + h.formatCurrency(p.property_value) + '</td>'
                + '<td class="flip-positive">' + h.formatCurrency(p.equity_gain) + '</td>'
                + '<td>' + h.formatCurrency(p.cumulative_cf) + '</td>'
                + '</tr>';
        });

        html += '</tbody></table></div>';
        return html;
    }

    // ==========================================
    // Rental Defaults Panel (Admin)
    // ==========================================

    /**
     * Initialize the Rental & BRRRR Defaults admin panel.
     */
    FD.rental.initDefaults = function () {
        // Collapsible toggle
        $('#flip-rd-toggle').on('click', function () {
            var $body = $('#flip-rd-body');
            var $arrow = $('.flip-rd-arrow');
            $body.slideToggle(200);
            $arrow.toggleClass('dashicons-arrow-down-alt2 dashicons-arrow-up-alt2');
        });

        // Populate from server data
        var defaults = flipData.rentalDefaults || {};
        $('#rd-vacancy-rate').val(((defaults.vacancy_rate || 0.05) * 100).toFixed(1));
        $('#rd-management-fee').val(((defaults.management_fee_rate || 0.08) * 100).toFixed(1));
        $('#rd-maintenance-rate').val(((defaults.maintenance_rate || 0.01) * 100).toFixed(1));
        $('#rd-capex-reserve').val(((defaults.capex_reserve_rate || 0.05) * 100).toFixed(1));
        $('#rd-insurance-rate').val(((defaults.insurance_rate || 0.006) * 100).toFixed(1));
        $('#rd-appreciation-rate').val(((defaults.appreciation_rate || 0.03) * 100).toFixed(1));
        $('#rd-rent-growth').val(((defaults.rent_growth_rate || 0.02) * 100).toFixed(1));
        $('#rd-tax-rate').val(((defaults.marginal_tax_rate || 0.32) * 100).toFixed(0));
        $('#rd-refi-ltv').val(((defaults.brrrr_refi_ltv || 0.75) * 100).toFixed(0));
        $('#rd-refi-rate').val(((defaults.brrrr_refi_rate || 0.072) * 100).toFixed(1));
        $('#rd-refi-term').val(defaults.brrrr_refi_term || 30);

        // Save
        $('#flip-save-rental-defaults').on('click', function () {
            var data = {
                vacancy_rate: parseFloat($('#rd-vacancy-rate').val()) / 100,
                management_fee_rate: parseFloat($('#rd-management-fee').val()) / 100,
                maintenance_rate: parseFloat($('#rd-maintenance-rate').val()) / 100,
                capex_reserve_rate: parseFloat($('#rd-capex-reserve').val()) / 100,
                insurance_rate: parseFloat($('#rd-insurance-rate').val()) / 100,
                appreciation_rate: parseFloat($('#rd-appreciation-rate').val()) / 100,
                rent_growth_rate: parseFloat($('#rd-rent-growth').val()) / 100,
                marginal_tax_rate: parseFloat($('#rd-tax-rate').val()) / 100,
                brrrr_refi_ltv: parseFloat($('#rd-refi-ltv').val()) / 100,
                brrrr_refi_rate: parseFloat($('#rd-refi-rate').val()) / 100,
                brrrr_refi_term: parseInt($('#rd-refi-term').val(), 10),
            };

            $.post(flipData.ajaxUrl, {
                action: 'flip_save_rental_defaults',
                nonce: flipData.nonce,
                defaults: JSON.stringify(data),
            }, function (resp) {
                if (resp.success) {
                    flipData.rentalDefaults = resp.data.defaults;
                    $('#flip-rd-status').text('Saved!').css('color', '#198754');
                    setTimeout(function () { $('#flip-rd-status').text(''); }, 3000);
                } else {
                    $('#flip-rd-status').text('Error: ' + (resp.data || 'Save failed')).css('color', '#dc3545');
                }
            });
        });

        // Reset
        $('#flip-reset-rental-defaults').on('click', function () {
            $.post(flipData.ajaxUrl, {
                action: 'flip_reset_rental_defaults',
                nonce: flipData.nonce,
            }, function (resp) {
                if (resp.success) {
                    flipData.rentalDefaults = resp.data.defaults;
                    FD.rental.initDefaults();
                    $('#flip-rd-status').text('Reset to defaults.').css('color', '#198754');
                    setTimeout(function () { $('#flip-rd-status').text(''); }, 3000);
                }
            });
        });
    };

})(window.FlipDashboard, jQuery);
