/**
 * FlipDashboard Init — Initialization and event binding.
 *
 * Loaded last. Sets up shared state from flipData, calls module init functions,
 * and binds all event handlers via delegation.
 *
 * Data provided via wp_localize_script as flipData:
 *   - ajaxUrl: admin-ajax.php URL
 *   - nonce: security nonce
 *   - siteUrl: home URL for property links
 *   - data.summary: {total, viable, avg_score, avg_roi, disqualified, last_run, cities}
 *   - data.results: array of property objects
 *   - data.cities: array of target city names
 *   - filters: saved analysis filter values
 *   - propertySubTypes: available property sub types from DB
 */
(function (FD, $) {
    'use strict';

    $(document).ready(function () {
        // Initialize shared state from server data
        FD.data = flipData.data;

        try {
            FD.stats.renderStats(FD.data.summary);
            FD.stats.renderChart(FD.data.summary.cities || []);
            FD.stats.populateCityFilter(FD.data.cities || []);
            FD.cities.renderTags(FD.data.cities || []);
            FD.filters.applyFilters();
        } catch (e) {
            console.error('[FlipDashboard] Init error:', e);
        }

        // Analysis filters panel
        FD.analysisFilters.init();

        // Event handlers — use delegation on tbody for dynamic rows
        var $tbody = $('#flip-results-body');
        $tbody.on('click', '.flip-toggle', function (e) {
            e.stopPropagation();
            FD.filters.toggleRow($(this));
        });
        $tbody.on('click', 'tr.flip-main-row', function (e) {
            if ($(e.target).closest('.flip-toggle').length) return;
            if ($(e.target).closest('a').length) return;
            if ($(e.target).closest('.flip-pdf-btn').length) return;
            if ($(e.target).closest('.flip-force-btn').length) return;
            FD.filters.toggleRow($(this).find('.flip-toggle'));
        });
        $(document).on('click', '.flip-pdf-btn', function (e) {
            e.preventDefault();
            e.stopPropagation();
            FD.ajax.generatePDF($(this));
        });
        $(document).on('click', '.flip-force-btn', function (e) {
            e.preventDefault();
            e.stopPropagation();
            FD.ajax.forceAnalyze($(this));
        });

        $('#flip-run-analysis').on('click', FD.ajax.runAnalysis);
        $('#flip-run-photos').on('click', FD.ajax.runPhotoAnalysis);
        $('#flip-export-csv').on('click', FD.ajax.exportCSV);
        $('#flip-city-add-btn').on('click', FD.cities.add);
        $('#flip-city-input').on('keypress', function (e) {
            if (e.which === 13) { e.preventDefault(); FD.cities.add(); }
        });
        $('#filter-city, #filter-sort, #filter-show').on('change', FD.filters.applyFilters);
        $('#filter-score').on('input', function () {
            $('#score-display').text(this.value);
            FD.filters.applyFilters();
        });
    });

})(window.FlipDashboard, jQuery);
