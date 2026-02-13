/**
 * MLD Open House Admin JavaScript
 *
 * Handles CSV export via AJAX + Blob download.
 *
 * @package MLS_Listings_Display
 * @subpackage Admin
 * @since 6.76.0
 */

(function($) {
    'use strict';

    var OpenHouseAdmin = {
        config: window.mldOpenHouseAdmin || {},

        init: function() {
            this.bindEvents();
        },

        bindEvents: function() {
            var self = this;

            $(document).on('click', '.mld-oh-export-csv', function(e) {
                e.preventDefault();
                self.exportCSV($(this));
            });
        },

        /**
         * Export CSV via AJAX and trigger client-side download
         */
        exportCSV: function($btn) {
            var self = this;
            var scope = $btn.data('scope');
            var originalText = $btn.html();

            $btn.addClass('loading').html('<span class="dashicons dashicons-update" style="vertical-align: middle; margin-top: -2px; animation: rotation 1s linear infinite;"></span> Exporting...');

            var data = {
                action: 'mld_admin_export_open_house_csv',
                nonce: self.config.nonce,
                scope: scope
            };

            if (scope === 'detail') {
                data.oh_id = $btn.data('oh-id');
            } else {
                // Pass current filter params from URL
                var urlParams = new URLSearchParams(window.location.search);
                data.agent_id = urlParams.get('agent_id') || 0;
                data.city = urlParams.get('city') || '';
                data.date_from = urlParams.get('date_from') || '';
                data.date_to = urlParams.get('date_to') || '';
                data.status = urlParams.get('status') || 'all';
            }

            $.ajax({
                url: self.config.ajaxUrl,
                type: 'POST',
                data: data,
                success: function(response) {
                    if (response.success) {
                        self.downloadBlob(response.data.csv, response.data.filename);
                    } else {
                        alert('Export failed: ' + (response.data.message || 'Unknown error'));
                    }
                    $btn.removeClass('loading').html(originalText);
                },
                error: function() {
                    alert('Export failed. Please try again.');
                    $btn.removeClass('loading').html(originalText);
                }
            });
        },

        /**
         * Create Blob and trigger download
         */
        downloadBlob: function(csvContent, filename) {
            var blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            var link = document.createElement('a');
            var url = URL.createObjectURL(blob);
            link.setAttribute('href', url);
            link.setAttribute('download', filename);
            link.style.visibility = 'hidden';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            URL.revokeObjectURL(url);
        }
    };

    $(document).ready(function() {
        OpenHouseAdmin.init();
    });

})(jQuery);
