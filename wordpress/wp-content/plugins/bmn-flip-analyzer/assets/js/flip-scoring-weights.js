/**
 * FlipDashboard Scoring Weights — Admin UI for weight tuning.
 *
 * Populates inputs from flipData.scoringWeights, validates group sums,
 * and saves via AJAX. Weights displayed as percentages (×100), stored as decimals.
 */
(function (FD, $) {
    'use strict';

    FD.scoringWeights = {};

    /** Weight groups that must sum to 100% */
    var SUM_GROUPS = ['main', 'financial_sub', 'property_sub', 'location_sub', 'market_sub'];

    /* ─── Init ──────────────────────────────────────────── */

    FD.scoringWeights.init = function () {
        var weights = flipData.scoringWeights || {};
        var digest  = flipData.digestSettings || {};

        // Populate weight groups (display as percentages)
        SUM_GROUPS.forEach(function (group) {
            var data = weights[group] || {};
            var $group = $('.flip-sw-group[data-group="' + group + '"]');
            $group.find('.flip-sw-input').each(function () {
                var key = $(this).data('key');
                if (data[key] !== undefined) {
                    $(this).val(Math.round(data[key] * 1000) / 10); // 0.375 → 37.5
                }
            });
            FD.scoringWeights.validateGroup($group);
        });

        // Thresholds (raw values, no ×100)
        var thresholds = weights.thresholds || {};
        $('#sw-min-profit').val(thresholds.min_profit || 25000);
        $('#sw-min-roi').val(thresholds.min_roi || 15);

        // Remarks cap
        $('#sw-remarks-cap').val(weights.market_remarks_cap || 25);

        // Digest settings
        $('#sw-digest-enabled').prop('checked', !!digest.enabled);
        $('#sw-digest-email').val(digest.email || '');
        $('#sw-digest-frequency').val(digest.frequency || 'daily');

        // Live sum validation
        $('.flip-sw-group').on('input', '.flip-sw-input', function () {
            FD.scoringWeights.validateGroup($(this).closest('.flip-sw-group'));
        });

        // Toggle panel
        $('#flip-sw-toggle').on('click', function () {
            var $body  = $('#flip-sw-body');
            var $arrow = $(this).find('.flip-sw-arrow');
            $body.slideToggle(200);
            $arrow.toggleClass('flip-sw-arrow-open');
        });

        // Save / Reset
        $('#flip-save-weights').on('click', FD.scoringWeights.save);
        $('#flip-reset-weights').on('click', FD.scoringWeights.reset);
        $('#flip-save-digest').on('click', FD.scoringWeights.saveDigest);
    };

    /* ─── Validate Group Sum ────────────────────────────── */

    FD.scoringWeights.validateGroup = function ($group) {
        var group = $group.data('group');
        if (SUM_GROUPS.indexOf(group) === -1) return; // thresholds don't need sum

        var sum = 0;
        $group.find('.flip-sw-input').each(function () {
            sum += parseFloat($(this).val()) || 0;
        });

        var $sumEl = $group.find('.flip-sw-sum-value');
        $sumEl.text(Math.round(sum * 10) / 10);

        var isValid = Math.abs(sum - 100) < 0.5;
        $sumEl.css('color', isValid ? '#00a32a' : '#cc1818');
        $group.find('.flip-sw-sum').css('color', isValid ? '#00a32a' : '#cc1818');
    };

    /* ─── Collect Weights from Inputs ───────────────────── */

    FD.scoringWeights.collect = function () {
        var weights = {};

        // Sum groups: percentage → decimal (37.5% → 0.375)
        SUM_GROUPS.forEach(function (group) {
            weights[group] = {};
            var $group = $('.flip-sw-group[data-group="' + group + '"]');
            $group.find('.flip-sw-input').each(function () {
                var key = $(this).data('key');
                var val = parseFloat($(this).val()) || 0;
                weights[group][key] = Math.round(val * 10) / 1000; // 37.5 → 0.375
            });
        });

        // Thresholds (raw)
        weights.thresholds = {
            min_profit: parseInt($('#sw-min-profit').val()) || 25000,
            min_roi:    parseInt($('#sw-min-roi').val()) || 15,
        };

        // Remarks cap (raw)
        weights.market_remarks_cap = parseInt($('#sw-remarks-cap').val()) || 25;

        return weights;
    };

    /* ─── Save Weights ──────────────────────────────────── */

    FD.scoringWeights.save = function () {
        // Validate all sum groups
        var valid = true;
        SUM_GROUPS.forEach(function (group) {
            var $group = $('.flip-sw-group[data-group="' + group + '"]');
            var sum = 0;
            $group.find('.flip-sw-input').each(function () {
                sum += parseFloat($(this).val()) || 0;
            });
            if (Math.abs(sum - 100) >= 0.5) {
                valid = false;
            }
        });

        if (!valid) {
            FD.scoringWeights.showStatus('#flip-sw-status', 'All weight groups must sum to 100%.', 'error');
            return;
        }

        var weights = FD.scoringWeights.collect();

        $('#flip-save-weights').prop('disabled', true).text('Saving...');

        $.ajax({
            url: flipData.ajaxUrl,
            method: 'POST',
            data: {
                action: 'flip_save_weights',
                nonce: flipData.nonce,
                weights: JSON.stringify(weights),
            },
            success: function (response) {
                $('#flip-save-weights').prop('disabled', false).html(
                    '<span class="dashicons dashicons-saved"></span> Save Weights'
                );
                if (response.success) {
                    flipData.scoringWeights = response.data.weights;
                    FD.scoringWeights.showStatus('#flip-sw-status', 'Weights saved.', 'success');
                } else {
                    FD.scoringWeights.showStatus('#flip-sw-status', response.data || 'Save failed.', 'error');
                }
            },
            error: function () {
                $('#flip-save-weights').prop('disabled', false).html(
                    '<span class="dashicons dashicons-saved"></span> Save Weights'
                );
                FD.scoringWeights.showStatus('#flip-sw-status', 'Request failed.', 'error');
            }
        });
    };

    /* ─── Reset to Defaults ─────────────────────────────── */

    FD.scoringWeights.reset = function () {
        if (!confirm('Reset all scoring weights to defaults?')) return;

        $.ajax({
            url: flipData.ajaxUrl,
            method: 'POST',
            data: {
                action: 'flip_reset_weights',
                nonce: flipData.nonce,
            },
            success: function (response) {
                if (response.success) {
                    flipData.scoringWeights = response.data.weights;
                    FD.scoringWeights.populate(response.data.weights);
                    FD.scoringWeights.showStatus('#flip-sw-status', 'Reset to defaults.', 'success');
                }
            },
        });
    };

    /* ─── Populate Inputs ───────────────────────────────── */

    FD.scoringWeights.populate = function (weights) {
        SUM_GROUPS.forEach(function (group) {
            var data = weights[group] || {};
            var $group = $('.flip-sw-group[data-group="' + group + '"]');
            $group.find('.flip-sw-input').each(function () {
                var key = $(this).data('key');
                if (data[key] !== undefined) {
                    $(this).val(Math.round(data[key] * 1000) / 10);
                }
            });
            FD.scoringWeights.validateGroup($group);
        });

        var thresholds = weights.thresholds || {};
        $('#sw-min-profit').val(thresholds.min_profit || 25000);
        $('#sw-min-roi').val(thresholds.min_roi || 15);
        $('#sw-remarks-cap').val(weights.market_remarks_cap || 25);
    };

    /* ─── Save Digest Settings ──────────────────────────── */

    FD.scoringWeights.saveDigest = function () {
        var settings = {
            enabled:   $('#sw-digest-enabled').is(':checked'),
            email:     $('#sw-digest-email').val().trim(),
            frequency: $('#sw-digest-frequency').val(),
        };

        $('#flip-save-digest').prop('disabled', true).text('Saving...');

        $.ajax({
            url: flipData.ajaxUrl,
            method: 'POST',
            data: {
                action: 'flip_save_digest_settings',
                nonce: flipData.nonce,
                settings: JSON.stringify(settings),
            },
            success: function (response) {
                $('#flip-save-digest').prop('disabled', false).text('Save Digest Settings');
                if (response.success) {
                    FD.scoringWeights.showStatus('#flip-digest-status', 'Digest settings saved.', 'success');
                } else {
                    FD.scoringWeights.showStatus('#flip-digest-status', response.data || 'Save failed.', 'error');
                }
            },
            error: function () {
                $('#flip-save-digest').prop('disabled', false).text('Save Digest Settings');
                FD.scoringWeights.showStatus('#flip-digest-status', 'Request failed.', 'error');
            }
        });
    };

    /* ─── Status Helper ─────────────────────────────────── */

    FD.scoringWeights.showStatus = function (selector, msg, type) {
        var color = type === 'success' ? '#00a32a' : '#cc1818';
        $(selector).text(msg).css('color', color);
        setTimeout(function () { $(selector).text(''); }, 4000);
    };

})(window.FlipDashboard, jQuery);
