/**
 * FlipDashboard Filters & Table — Client-side filtering, sorting, and table rendering.
 *
 * Consolidates filter logic into getFilteredResults() as single source of truth.
 * applyFilters() delegates to getFilteredResults() (fixes duplicate logic bug).
 */
(function (FD, $) {
    'use strict';

    /**
     * Single source of truth for filtering and sorting results.
     * Used by applyFilters (for rendering) and toggleRow (for index lookup).
     */
    FD.filters.getFilteredResults = function () {
        var city = $('#filter-city').val();
        var minScore = parseInt($('#filter-score').val()) || 0;
        var sort = $('#filter-sort').val();
        var show = $('#filter-show').val();

        var filtered = (FD.data.results || []).filter(function (r) {
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
    };

    /**
     * Apply current filters and re-render the table.
     */
    FD.filters.applyFilters = function () {
        FD.filters.renderTable(FD.filters.getFilteredResults());
    };

    FD.filters.renderTable = function (results) {
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
            var $row = FD.filters.buildRow(r, idx);
            $tbody.append($row);
        });
    };

    FD.filters.buildRow = function (r, idx) {
        var h = FD.helpers;
        var dqClass = r.disqualified ? (r.near_viable ? ' flip-row-near-viable' : ' flip-row-dq') : '';
        var scoreHtml = r.disqualified
            ? (r.near_viable
                ? '<span class="flip-score-badge flip-score-near">NV</span>'
                : '<span class="flip-score-badge flip-score-poor">DQ</span>')
            : '<span class="flip-score-badge ' + h.scoreClass(r.total_score) + '">' + r.total_score.toFixed(1) + '</span>';

        var photoHtml = r.photo_score !== null
            ? '<span class="flip-score-badge ' + h.scoreClass(r.photo_score) + '">' + r.photo_score.toFixed(0) + '</span>'
            : '<span style="color:#ccc">--</span>';

        var profitClass = r.estimated_profit >= 0 ? '' : ' flip-negative';

        var riskHtml = r.deal_risk_grade
            ? '<span class="flip-risk-badge flip-risk-' + r.deal_risk_grade + '">' + r.deal_risk_grade + '</span>'
            : '<span style="color:#ccc">--</span>';

        var leadBadge = r.lead_paint_flag ? '<span class="flip-lead-badge">Pb</span>' : '';

        var dqNote = '';
        if (r.disqualified && r.disqualify_reason) {
            var isNewConstDQ = r.disqualify_reason.indexOf('construction') > -1
                || r.disqualify_reason.indexOf('renovation potential') > -1;
            dqNote = '<div class="flip-dq-reason">'
                + (isNewConstDQ ? '<span class="flip-new-badge">NEW</span> ' : '')
                + h.escapeHtml(r.disqualify_reason) + '</div>';
        }

        var propertyUrl = flipData.siteUrl + '/property/' + r.listing_id + '/';
        var domText = r.days_on_market ? r.days_on_market + 'd' : '--';

        var annRoiText = r.annualized_roi ? r.annualized_roi.toFixed(0) + '%' : '--';
        var annRoiClass = r.annualized_roi >= 0 ? '' : ' flip-negative';

        var html = '<tr class="flip-main-row' + dqClass + '" data-idx="' + idx + '">'
            + '<td class="flip-col-toggle"><button class="flip-toggle" data-idx="' + idx + '">+</button></td>'
            + '<td><div class="flip-property-cell">'
            + '<span class="flip-property-address">' + h.escapeHtml(r.address) + leadBadge + '</span>'
            + '<span class="flip-property-mls">MLS# ' + r.listing_id
            + ' &middot; <a href="' + propertyUrl + '" target="_blank" class="flip-view-link">View</a></span>'
            + dqNote
            + '</div></td>'
            + '<td>' + h.escapeHtml(r.city) + '</td>'
            + '<td class="flip-col-num">' + scoreHtml + '</td>'
            + '<td>' + riskHtml + '</td>'
            + '<td class="flip-col-num">' + h.formatCurrency(r.list_price) + '</td>'
            + '<td class="flip-col-num">' + h.formatCurrency(r.estimated_arv) + '</td>'
            + '<td class="flip-col-num' + profitClass + '">' + h.formatCurrency(r.estimated_profit) + '</td>'
            + '<td class="flip-col-num' + annRoiClass + '">' + annRoiText + '</td>'
            + '<td>' + h.roadBadge(r.road_type) + '</td>'
            + '<td class="flip-col-num">' + domText + '</td>'
            + '<td class="flip-col-num">' + photoHtml + '</td>'
            + '</tr>';

        return $(html);
    };

    FD.filters.toggleRow = function ($btn) {
        try {
            var idx = $btn.data('idx');
            var $mainRow = $btn.closest('tr');
            var $detailRow = $mainRow.next('.flip-detail-row');

            if ($detailRow.length) {
                $detailRow.remove();
                $mainRow.removeClass('flip-row-expanded');
                $btn.text('+');
            } else {
                var results = FD.filters.getFilteredResults();
                var r = results[idx];
                if (!r) {
                    console.warn('[FlipDashboard] No result at index', idx, 'of', results.length);
                    return;
                }

                var $detail = FD.detail.buildDetailRow(r);
                $mainRow.after($detail);
                $mainRow.addClass('flip-row-expanded');
                $btn.text('\u2212');
            }
        } catch (e) {
            console.error('[FlipDashboard] toggleRow error:', e);
        }
    };

})(window.FlipDashboard, jQuery);
