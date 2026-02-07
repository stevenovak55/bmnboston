/**
 * FlipDashboard Projections — ARV projection calculator and cost model.
 *
 * Shared calcProjectionCosts() used by both projections table and sensitivity analysis.
 */
(function (FD, $) {
    'use strict';

    var h = FD.helpers;

    FD.projections.buildProjectionSection = function (r) {
        if (r.disqualified || !r.avg_comp_ppsf || r.avg_comp_ppsf <= 0) return '';

        var ppsf = r.avg_comp_ppsf;
        var discount = 1 - (r.road_arv_discount || 0);
        var rehabPerSqft = r.estimated_rehab_cost && r.building_area_total
            ? r.estimated_rehab_cost / r.building_area_total : 45;
        var listPrice = r.list_price || 0;
        var curBeds = r.bedrooms_total || 0;
        var curBaths = r.bathrooms_total || 0;
        var curSqft = r.building_area_total || 0;

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
            var projCosts = FD.projections.calcProjectionCosts(listPrice, projArv, totalRehab);
            var profitCls = projCosts.profit >= 0 ? 'flip-positive' : 'flip-negative';

            html += '<tr>'
                + '<td><strong>' + s.label + '</strong></td>'
                + '<td>' + s.beds + '</td>'
                + '<td>' + s.baths + '</td>'
                + '<td>' + s.sqft.toLocaleString() + '</td>'
                + '<td>' + h.formatCurrency(projArv) + '</td>'
                + '<td class="' + profitCls + '">' + h.formatCurrency(projCosts.profit) + '</td>'
                + '<td class="' + profitCls + '">' + projCosts.roi.toFixed(1) + '%</td>'
                + '</tr>';
        });

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

        html += '<span class="flip-proj-data" style="display:none"'
            + ' data-ppsf="' + ppsf + '"'
            + ' data-discount="' + discount + '"'
            + ' data-rehab="' + rehabPerSqft + '"'
            + ' data-cur-sqft="' + curSqft + '"'
            + ' data-list-price="' + listPrice + '"'
            + ' data-pid="' + pid + '"'
            + '></span>';

        return html;
    };

    /**
     * Shared cost calculation for projections (matches PHP v0.8.0 constants).
     * Also used by flip-detail-row.js buildSensitivitySection.
     */
    FD.projections.calcProjectionCosts = function (listPrice, arv, rehab) {
        var estSqft = arv > 0 ? arv / 350 : 1500;
        var effectivePerSqft = rehab / estSqft;
        var contRate;
        if (effectivePerSqft <= 20) contRate = 0.08;
        else if (effectivePerSqft <= 35) contRate = 0.12;
        else if (effectivePerSqft <= 50) contRate = 0.15;
        else contRate = 0.20;

        var contingency = rehab * contRate;
        var totalRehab = rehab + contingency;

        var transferTaxBuy = listPrice * 0.00456;
        var transferTaxSell = arv * 0.00456;
        var purchaseClosing = listPrice * 0.015 + transferTaxBuy;
        var saleCosts = arv * (0.045 + 0.01) + transferTaxSell;

        var holdMonths;
        if (effectivePerSqft <= 20) holdMonths = 3;
        else if (effectivePerSqft <= 35) holdMonths = 4;
        else if (effectivePerSqft <= 50) holdMonths = 6;
        else holdMonths = 8;

        var monthlyTax = (listPrice * 0.013) / 12;
        var monthlyIns = (listPrice * 0.005) / 12;
        var holdingCosts = (monthlyTax + monthlyIns + 350) * holdMonths;

        var cashProfit = arv - listPrice - totalRehab - purchaseClosing - saleCosts - holdingCosts;
        var cashInvestment = listPrice + totalRehab + purchaseClosing + holdingCosts;
        var cashRoi = cashInvestment > 0 ? (cashProfit / cashInvestment) * 100 : 0;

        var loanAmount = listPrice * 0.80;
        var financingCosts = (loanAmount * 0.02) + (loanAmount * 0.105 / 12 * holdMonths);
        var finProfit = cashProfit - financingCosts;
        var cashInvested = (listPrice * 0.20) + totalRehab + purchaseClosing;
        var cocRoi = cashInvested > 0 ? (finProfit / cashInvested) * 100 : 0;

        return { profit: finProfit, roi: cocRoi, cashProfit: cashProfit, cashRoi: cashRoi };
    };

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
        var projCosts = FD.projections.calcProjectionCosts(listPrice, projArv, totalRehab);
        var profitCls = projCosts.profit >= 0 ? 'flip-positive' : 'flip-negative';

        $('#' + pid + '-arv').text(h.formatCurrency(projArv));
        $('#' + pid + '-profit').attr('class', profitCls).text(h.formatCurrency(projCosts.profit));
        $('#' + pid + '-roi').attr('class', profitCls).text(projCosts.roi.toFixed(1) + '%');
    });

})(window.FlipDashboard, jQuery);
