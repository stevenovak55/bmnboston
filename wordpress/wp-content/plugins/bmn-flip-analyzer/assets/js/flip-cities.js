/**
 * FlipDashboard Cities — Target city management (add, remove, save).
 */
(function (FD, $) {
    'use strict';

    var h = FD.helpers;

    FD.cities.renderTags = function (cities) {
        var $container = $('#flip-city-tags');
        $container.empty();

        cities.forEach(function (city) {
            var $tag = $('<span class="flip-city-tag">'
                + h.escapeHtml(city)
                + '<button class="flip-city-remove" data-city="' + h.escapeHtml(city) + '" title="Remove ' + h.escapeHtml(city) + '">&times;</button>'
                + '</span>');
            $container.append($tag);
        });

        $container.find('.flip-city-remove').on('click', function () {
            var cityToRemove = $(this).data('city');
            FD.cities.remove(cityToRemove);
        });
    };

    FD.cities.add = function () {
        var city = $.trim($('#flip-city-input').val());
        if (!city) return;

        // Title case
        city = city.replace(/\w\S*/g, function (txt) {
            return txt.charAt(0).toUpperCase() + txt.substr(1).toLowerCase();
        });

        var cities = FD.data.cities || [];
        if (cities.indexOf(city) !== -1) {
            $('#flip-city-status').text(city + ' is already in the list.').css('color', '#cc1818');
            setTimeout(function () { $('#flip-city-status').text(''); }, 3000);
            return;
        }

        cities.push(city);
        FD.cities.save(cities);
        $('#flip-city-input').val('');
    };

    FD.cities.remove = function (city) {
        var cities = (FD.data.cities || []).filter(function (c) { return c !== city; });
        if (cities.length === 0) {
            alert('You must have at least one target city.');
            return;
        }
        FD.cities.save(cities);
    };

    FD.cities.save = function (cities) {
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
                    FD.data.cities = response.data.cities;
                    FD.cities.renderTags(FD.data.cities);
                    FD.stats.populateCityFilter(FD.data.cities);
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
    };

})(window.FlipDashboard, jQuery);
