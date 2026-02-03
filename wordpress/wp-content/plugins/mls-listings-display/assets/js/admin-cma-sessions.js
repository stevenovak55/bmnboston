/**
 * MLD CMA Sessions Admin JavaScript
 *
 * @package MLS_Listings_Display
 * @subpackage Admin
 * @since 6.17.0
 */

(function($) {
    'use strict';

    var CMASessionsAdmin = {
        /**
         * Configuration
         */
        config: window.mldCMASessionsAdmin || {},

        /**
         * Initialize
         */
        init: function() {
            this.bindEvents();
        },

        /**
         * Bind event handlers
         */
        bindEvents: function() {
            var self = this;

            // Delete session button
            $(document).on('click', '.mld-delete-session-btn', function(e) {
                e.preventDefault();
                var sessionId = $(this).data('session-id');
                self.deleteSession(sessionId, $(this).closest('tr'));
            });

            // Assign session button
            $(document).on('click', '.mld-assign-session-btn', function(e) {
                e.preventDefault();
                var sessionId = $(this).data('session-id');
                self.showAssignModal(sessionId);
            });

            // Confirm assign button
            $('#confirm-assign-btn').on('click', function() {
                self.assignSession();
            });

            // Cancel assign button
            $('#cancel-assign-btn').on('click', function() {
                self.hideAssignModal();
            });

            // Close modal on overlay click
            $('#mld-assign-user-modal').on('click', function(e) {
                if (e.target === this) {
                    self.hideAssignModal();
                }
            });
        },

        /**
         * Delete a session
         */
        deleteSession: function(sessionId, $row) {
            if (!confirm(this.config.confirmDelete)) {
                return;
            }

            $row.addClass('deleting');

            $.ajax({
                url: this.config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'mld_admin_delete_cma_session',
                    nonce: this.config.nonce,
                    session_id: sessionId
                },
                success: function(response) {
                    if (response.success) {
                        $row.fadeOut(300, function() {
                            $(this).remove();
                        });
                    } else {
                        alert('Error: ' + (response.data.message || 'Failed to delete session'));
                        $row.removeClass('deleting');
                    }
                },
                error: function() {
                    alert('Error: Failed to delete session. Please try again.');
                    $row.removeClass('deleting');
                }
            });
        },

        /**
         * Show assign user modal
         */
        showAssignModal: function(sessionId) {
            $('#assign-session-id').val(sessionId);
            $('#assign-user-select').val('');
            $('#mld-assign-user-modal').show();
        },

        /**
         * Hide assign user modal
         */
        hideAssignModal: function() {
            $('#mld-assign-user-modal').hide();
            $('#assign-session-id').val('');
            $('#assign-user-select').val('');
        },

        /**
         * Assign session to user
         */
        assignSession: function() {
            var self = this;
            var sessionId = $('#assign-session-id').val();
            var userId = $('#assign-user-select').val();

            if (!userId) {
                alert('Please select a user.');
                return;
            }

            var $btn = $('#confirm-assign-btn');
            $btn.prop('disabled', true).text('Assigning...');

            $.ajax({
                url: this.config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'mld_admin_assign_cma_session',
                    nonce: this.config.nonce,
                    session_id: sessionId,
                    user_id: userId
                },
                success: function(response) {
                    if (response.success) {
                        self.hideAssignModal();
                        // Reload the page to show updated data
                        window.location.reload();
                    } else {
                        alert('Error: ' + (response.data.message || 'Failed to assign session'));
                        $btn.prop('disabled', false).text('Assign');
                    }
                },
                error: function() {
                    alert('Error: Failed to assign session. Please try again.');
                    $btn.prop('disabled', false).text('Assign');
                }
            });
        }
    };

    // Initialize on document ready
    $(document).ready(function() {
        CMASessionsAdmin.init();
    });

})(jQuery);
