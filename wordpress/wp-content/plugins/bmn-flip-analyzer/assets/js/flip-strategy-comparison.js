/**
 * FlipDashboard Strategy Comparison — Side-by-side strategy analysis page.
 *
 * Loaded only on the Strategy Comparison sub-page.
 * Uses flipData.data.results to populate the property selector and comparison table.
 *
 * v0.16.0: Initial implementation.
 */
(function ($) {
    'use strict';

    var h = window.FlipDashboard ? window.FlipDashboard.helpers : null;

    $(document).ready(function () {
        if (!h) return;

        var results = (flipData && flipData.data && flipData.data.results) || [];

        // Populate property selector
        var $select = $('#flip-comparison-property');
        results.forEach(function (r) {
            if (r.rental_analysis) {
                var label = r.address + ', ' + r.city + ' — ' + h.formatCurrency(r.list_price)
                    + ' (Score: ' + r.total_score.toFixed(0) + ')';
                $select.append('<option value="' + r.listing_id + '">' + h.escapeHtml(label) + '</option>');
            }
        });

        // Handle selection change
        $select.on('change', function () {
            var listingId = parseInt($(this).val(), 10);
            if (!listingId) {
                $('#flip-comparison-content').hide();
                $('#flip-comparison-empty').show();
                return;
            }

            var r = results.find(function (item) { return item.listing_id === listingId; });
            if (!r || !r.rental_analysis) {
                $('#flip-comparison-content').hide();
                $('#flip-comparison-empty').show().text('No rental analysis data for this property.');
                return;
            }

            renderComparison(r);
            $('#flip-comparison-empty').hide();
            $('#flip-comparison-content').show();
        });
    });

    function renderComparison(r) {
        var rental = r.rental_analysis.rental || {};
        var brrrr = r.rental_analysis.brrrr || {};
        var strategy = r.rental_analysis.strategy || {};

        $('#flip-comparison-title').text(r.address + ', ' + r.city);

        // Compute capital recovery % for BRRRR
        var brrrrTotalIn = brrrr.total_cash_in || 1;
        var brrrrCapRecoveryPct = brrrrTotalIn > 0
            ? ((brrrrTotalIn - (brrrr.cash_left_in_deal || 0)) / brrrrTotalIn) * 100 : 0;

        var rows = [
            {
                label: 'Total Cash Required',
                flip: h.formatCurrency(r.list_price + r.estimated_rehab_cost + (r.list_price * 0.015)),
                rental: h.formatCurrency(rental.total_investment),
                brrrr: h.formatCurrency(brrrr.total_cash_in),
                best: 'min',
            },
            {
                label: 'Year 1 Cash Flow',
                flip: h.formatCurrency(r.estimated_profit) + ' <span style="font-size:11px;color:#999">(one-time)</span>',
                rental: h.formatCurrency(rental.noi),
                brrrr: h.formatCurrency(brrrr.post_refi_annual_cf),
                best: 'max',
            },
            {
                label: 'Cash-on-Cash Return',
                flip: h.formatPercent(r.cash_on_cash_roi),
                rental: h.formatPercent(rental.cash_on_cash),
                brrrr: brrrr.cash_left_in_deal <= 0 ? '∞' : h.formatPercent(brrrr.post_refi_cash_on_cash),
                best: 'max',
            },
            {
                label: 'Annualized Return',
                flip: h.formatPercent(r.annualized_roi),
                rental: h.formatPercent(rental.cap_rate),
                brrrr: h.formatPercent(brrrr.post_refi_cash_on_cash),
                best: 'max',
            },
            {
                label: 'Capital Recovery',
                flip: '100%' + ' <span style="font-size:11px;color:#999">(at sale)</span>',
                rental: 'N/A <span style="font-size:11px;color:#999">(held)</span>',
                brrrr: h.formatPercent(brrrrCapRecoveryPct),
                best: 'none',
            },
            {
                label: 'Risk Level',
                flip: getRiskLabel(r.deal_risk_grade),
                rental: '<span style="color:#198754">Low</span> <span style="font-size:11px;color:#999">(steady income)</span>',
                brrrr: '<span style="color:#fd7e14">Medium</span> <span style="font-size:11px;color:#999">(refi dependent)</span>',
                best: 'none',
            },
            {
                label: 'Time to Returns',
                flip: (r.hold_months || 6) + ' months',
                rental: 'Ongoing <span style="font-size:11px;color:#999">(monthly)</span>',
                brrrr: (r.hold_months || 6) + '+ months <span style="font-size:11px;color:#999">(after refi)</span>',
                best: 'none',
            },
            {
                label: 'Tax Benefits',
                flip: 'Short-term capital gains',
                rental: h.formatCurrency(rental.tax_benefits ? rental.tax_benefits.annual_tax_savings : 0) + '/yr',
                brrrr: h.formatCurrency(rental.tax_benefits ? rental.tax_benefits.annual_tax_savings : 0) + '/yr',
                best: 'none',
            },
        ];

        // Add 5-year projection if available
        // Projections are keyed by year in PHP (associative array), so access by key
        var rentalProj = rental.projections || {};
        var yr5 = rentalProj[5] || rentalProj['5'] || null;
        if (yr5) {
            var brrrrProj = brrrr.projections || {};
            var byr5 = brrrrProj[5] || brrrrProj['5'] || null;
            rows.push({
                label: '5-Year Total Return',
                flip: h.formatCurrency(r.estimated_profit) + ' <span style="font-size:11px;color:#999">(one deal)</span>',
                rental: h.formatPercent(yr5.total_return_pct) + ' (' + h.formatCurrency((yr5.equity_gain || 0) + (yr5.cumulative_cf || 0)) + ')',
                brrrr: byr5 ? h.formatCurrency((byr5.equity_gain || 0) + (byr5.cumulative_cf || 0)) : '--',
                best: 'none',
            });
        }

        // Strategy scores
        if (strategy.scores) {
            rows.push({
                label: '<strong>Strategy Score</strong>',
                flip: '<strong>' + (strategy.scores.flip || 0) + '</strong>/100',
                rental: '<strong>' + (strategy.scores.rental || 0) + '</strong>/100',
                brrrr: '<strong>' + (strategy.scores.brrrr || 0) + '</strong>/100',
                best: 'max_score',
            });
        }

        var $tbody = $('#flip-comparison-body').empty();
        rows.forEach(function (row) {
            var flipCls = '', rentalCls = '', brrrrCls = '';

            if (row.best === 'max_score' && strategy.scores) {
                var max = Math.max(strategy.scores.flip || 0, strategy.scores.rental || 0, strategy.scores.brrrr || 0);
                if ((strategy.scores.flip || 0) === max) flipCls = 'flip-best-cell';
                if ((strategy.scores.rental || 0) === max) rentalCls = 'flip-best-cell';
                if ((strategy.scores.brrrr || 0) === max) brrrrCls = 'flip-best-cell';
            }

            $tbody.append(
                '<tr>'
                + '<td>' + row.label + '</td>'
                + '<td class="' + flipCls + '">' + row.flip + '</td>'
                + '<td class="' + rentalCls + '">' + row.rental + '</td>'
                + '<td class="' + brrrrCls + '">' + row.brrrr + '</td>'
                + '</tr>'
            );
        });

        // Recommendation
        var $rec = $('#flip-comparison-recommendation');
        if (strategy.recommended && strategy.reasoning) {
            var badge = h.strategyBadge(strategy);
            $rec.html(
                '<div style="font-size:16px;margin-bottom:8px">' + badge + '</div>'
                + '<p style="font-size:14px;color:#333">' + h.escapeHtml(strategy.reasoning) + '</p>'
            );
        } else {
            $rec.html('<p style="color:#999">No strategy recommendation available.</p>');
        }
    }

    function getRiskLabel(grade) {
        if (!grade) return '--';
        var colors = { A: '#198754', B: '#198754', C: '#fd7e14', D: '#dc3545', F: '#dc3545' };
        var labels = { A: 'Very Low', B: 'Low', C: 'Medium', D: 'High', F: 'Very High' };
        return '<span style="color:' + (colors[grade] || '#666') + ';font-weight:600">'
            + (labels[grade] || grade) + '</span> (' + grade + ')';
    }

})(jQuery);
