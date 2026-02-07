/**
 * FlipDashboard Analysis Filters — Pre-analysis filter panel UI.
 *
 * Manages the collapsible filter panel with 17 configurable filters.
 */
(function (FD, $) {
    'use strict';

    var h = FD.helpers;

    FD.analysisFilters.init = function () {
        var filters = flipData.filters || {};
        var subTypes = flipData.propertySubTypes || [];

        // Build property sub type checkboxes
        var $container = $('#flip-af-subtypes');
        var checked = filters.property_sub_types || ['Single Family Residence'];
        subTypes.forEach(function (st) {
            var isChecked = checked.indexOf(st) !== -1 ? ' checked' : '';
            $container.append('<label><input type="checkbox" name="af-subtype" value="' + h.escapeHtml(st) + '"' + isChecked + '> ' + h.escapeHtml(st) + '</label>');
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
        $('#flip-save-filters').on('click', FD.analysisFilters.save);

        // Reset filters
        $('#flip-reset-filters').on('click', FD.analysisFilters.reset);
    };

    FD.analysisFilters.collect = function () {
        var filters = {};

        filters.property_sub_types = [];
        $('input[name="af-subtype"]:checked').each(function () {
            filters.property_sub_types.push($(this).val());
        });
        if (filters.property_sub_types.length === 0) {
            filters.property_sub_types = ['Single Family Residence'];
        }

        filters.statuses = [];
        $('input[name="af-status"]:checked').each(function () {
            filters.statuses.push($(this).val());
        });
        if (filters.statuses.length === 0) {
            filters.statuses = ['Active'];
        }

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

        filters.sewer_public_only = $('#af-sewer-public').is(':checked');
        filters.has_garage = $('#af-has-garage').is(':checked');

        return filters;
    };

    FD.analysisFilters.save = function () {
        var filters = FD.analysisFilters.collect();
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
    };

    FD.analysisFilters.reset = function () {
        $('input[name="af-subtype"]').prop('checked', false);
        $('input[name="af-subtype"][value="Single Family Residence"]').prop('checked', true);

        $('input[name="af-status"]').prop('checked', false);
        $('input[name="af-status"][value="Active"]').prop('checked', true);

        $('#af-min-price, #af-max-price, #af-min-sqft, #af-max-sqft').val('');
        $('#af-year-min, #af-year-max, #af-min-dom, #af-max-dom').val('');
        $('#af-list-from, #af-list-to, #af-min-beds, #af-min-baths, #af-min-lot').val('');

        $('#af-sewer-public, #af-has-garage').prop('checked', false);

        FD.analysisFilters.save();
    };

})(window.FlipDashboard, jQuery);
