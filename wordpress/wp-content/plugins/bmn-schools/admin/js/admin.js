/**
 * BMN Schools Admin JavaScript
 *
 * @package BMN_Schools
 * @since 0.1.0
 */

(function($) {
    'use strict';

    // Initialize when document is ready
    $(document).ready(function() {
        BMNSchoolsAdmin.init();
    });

    var BMNSchoolsAdmin = {
        init: function() {
            this.bindEvents();
        },

        bindEvents: function() {
            // Run sync button
            $('#bmn-run-sync').on('click', this.handleRunSync);

            // Clear logs button
            $('#bmn-clear-old-logs').on('click', this.handleClearLogs);
        },

        handleRunSync: function(e) {
            e.preventDefault();

            if (!confirm(bmnSchoolsAdmin.strings.confirmSync)) {
                return;
            }

            var $button = $(this);
            var originalText = $button.text();

            $button.prop('disabled', true).text(bmnSchoolsAdmin.strings.syncing);

            $.ajax({
                url: bmnSchoolsAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'bmn_schools_run_sync',
                    nonce: bmnSchoolsAdmin.nonce,
                    source: 'all'
                },
                success: function(response) {
                    if (response.success) {
                        alert(bmnSchoolsAdmin.strings.syncComplete + '\n' + response.data.message);
                    } else {
                        alert(bmnSchoolsAdmin.strings.syncError);
                    }
                },
                error: function() {
                    alert(bmnSchoolsAdmin.strings.syncError);
                },
                complete: function() {
                    $button.prop('disabled', false).text(originalText);
                }
            });
        },

        handleClearLogs: function(e) {
            e.preventDefault();

            if (!confirm(bmnSchoolsAdmin.strings.confirmClearLogs)) {
                return;
            }

            var $button = $(this);
            var originalText = $button.text();

            $button.prop('disabled', true).text('Clearing...');

            $.ajax({
                url: bmnSchoolsAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'bmn_schools_clear_logs',
                    nonce: bmnSchoolsAdmin.nonce,
                    days: 30
                },
                success: function(response) {
                    if (response.success) {
                        alert(response.data.message);
                        location.reload();
                    } else {
                        alert('Error clearing logs.');
                    }
                },
                error: function() {
                    alert('Error clearing logs.');
                },
                complete: function() {
                    $button.prop('disabled', false).text(originalText);
                }
            });
        },

        // Utility: Show loading state
        showLoading: function($element) {
            $element.addClass('bmn-loading');
        },

        // Utility: Hide loading state
        hideLoading: function($element) {
            $element.removeClass('bmn-loading');
        }
    };

    // Expose to global scope
    window.BMNSchoolsAdmin = BMNSchoolsAdmin;

})(jQuery);
