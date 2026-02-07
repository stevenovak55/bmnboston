/**
 * FlipDashboard Helpers — Pure formatting and utility functions.
 *
 * No DOM access, no state mutation. Used by every other module.
 */
(function (FD, $) {
    'use strict';

    FD.helpers.formatCurrency = function (num) {
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
    };

    FD.helpers.scoreClass = function (score) {
        if (score >= 80) return 'flip-score-high';
        if (score >= 65) return 'flip-score-good';
        if (score >= 50) return 'flip-score-mid';
        if (score >= 30) return 'flip-score-low';
        return 'flip-score-poor';
    };

    FD.helpers.marketStrengthBadge = function (strength) {
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
        return '<span style="display:inline-block;padding:1px 6px;border-radius:3px;font-size:11px;font-weight:600;color:#fff;background:' + color + '">' + FD.helpers.escapeHtml(label) + '</span>';
    };

    FD.helpers.roadBadge = function (type) {
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

        return '<span class="flip-road-badge ' + cls + '">' + FD.helpers.escapeHtml(label) + '</span>';
    };

    FD.helpers.kv = function (key, val) {
        return '<div class="flip-kv"><span class="flip-kv-key">' + key + '</span>'
            + '<span class="flip-kv-val">' + val + '</span></div>';
    };

    FD.helpers.escapeHtml = function (str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    };

})(window.FlipDashboard, jQuery);
