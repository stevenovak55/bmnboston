/**
 * SN Appointment Booking - Admin Availability JavaScript
 *
 * Handles availability management in admin.
 *
 * @package SN_Appointment_Booking
 * @since 1.0.0
 */

(function($) {
    'use strict';

    // Main Availability Admin object
    var SNABAvailability = {
        // Cache DOM elements
        $notice: null,
        $overrideModal: null,
        $blockedModal: null,
        $deleteModal: null,
        staffId: 0,
        typeId: 0,

        /**
         * Initialize the availability admin.
         */
        init: function() {
            this.cacheElements();
            this.bindEvents();
        },

        /**
         * Cache DOM elements.
         */
        cacheElements: function() {
            this.$notice = $('#snab-availability-notice');
            this.$overrideModal = $('#snab-override-modal');
            this.$blockedModal = $('#snab-blocked-modal');
            this.$deleteModal = $('#snab-availability-delete-modal');
            this.staffId = $('#snab-staff-id').val();
            this.typeId = $('#snab-selected-type-id').val() || 0;
        },

        /**
         * Bind event handlers.
         */
        bindEvents: function() {
            var self = this;

            // Staff filter change
            $(document).on('change', '#snab-staff-filter', function() {
                var staffId = $(this).val();
                var currentUrl = window.location.href;
                var newUrl = self.updateUrlParam(currentUrl, 'staff_id', staffId);
                // Reset type_id when changing staff
                newUrl = self.updateUrlParam(newUrl, 'type_id', '0');
                window.location.href = newUrl;
            });

            // Appointment type filter change
            $(document).on('change', '#snab-type-filter', function() {
                var typeId = $(this).val();
                var currentUrl = window.location.href;
                var newUrl = self.updateUrlParam(currentUrl, 'type_id', typeId);
                window.location.href = newUrl;
            });

            // Tab navigation
            $(document).on('click', '.snab-availability-tabs .nav-tab', function(e) {
                e.preventDefault();
                self.switchTab($(this).data('tab'));
            });

            // Day toggle switches
            $(document).on('change', '.snab-day-row input[type="checkbox"]', function() {
                self.toggleDayTimes($(this));
            });

            // Save recurring schedule
            $('#snab-recurring-form').on('submit', function(e) {
                e.preventDefault();
                self.saveRecurringSchedule();
            });

            // Add override button
            $(document).on('click', '.snab-add-override-btn', function(e) {
                e.preventDefault();
                self.openOverrideModal();
            });

            // Edit override button
            $(document).on('click', '.snab-edit-override', function(e) {
                e.preventDefault();
                self.openOverrideModal($(this).data());
            });

            // Delete override button
            $(document).on('click', '.snab-delete-override', function(e) {
                e.preventDefault();
                self.openDeleteModal($(this).data('id'), 'override');
            });

            // Save override form
            $('#snab-override-form').on('submit', function(e) {
                e.preventDefault();
                self.saveOverride();
            });

            // Override closed checkbox
            $(document).on('change', '#snab-override-closed', function() {
                self.toggleOverrideTimes($(this).is(':checked'));
            });

            // Add blocked time button
            $(document).on('click', '.snab-add-blocked-btn', function(e) {
                e.preventDefault();
                self.openBlockedModal();
            });

            // Delete blocked button
            $(document).on('click', '.snab-delete-blocked', function(e) {
                e.preventDefault();
                self.openDeleteModal($(this).data('id'), 'blocked');
            });

            // Save blocked form
            $('#snab-blocked-form').on('submit', function(e) {
                e.preventDefault();
                self.saveBlockedTime();
            });

            // Modal close handlers
            $(document).on('click', '.snab-modal-close, .snab-modal-cancel, .snab-modal-overlay', function(e) {
                if ($(e.target).hasClass('snab-modal-content')) return;
                self.closeModals();
            });

            // Stop propagation on modal content
            $(document).on('click', '.snab-modal-content', function(e) {
                e.stopPropagation();
            });

            // Confirm delete button
            $(document).on('click', '#snab-confirm-availability-delete-btn', function(e) {
                e.preventDefault();
                var id = $('#snab-delete-availability-id').val();
                var type = $('#snab-delete-availability-type').val();
                self.confirmDelete(id, type);
            });

            // ESC key to close modals
            $(document).on('keydown', function(e) {
                if (e.key === 'Escape') {
                    self.closeModals();
                }
            });
        },

        /**
         * Switch between tabs.
         *
         * @param {string} tab Tab name.
         */
        switchTab: function(tab) {
            // Update tab navigation
            $('.snab-availability-tabs .nav-tab').removeClass('nav-tab-active');
            $('.snab-availability-tabs .nav-tab[data-tab="' + tab + '"]').addClass('nav-tab-active');

            // Update tab content
            $('.snab-tab-content').removeClass('snab-tab-active');
            $('#snab-tab-' + tab).addClass('snab-tab-active');
        },

        /**
         * Toggle day time inputs.
         *
         * @param {jQuery} $checkbox The checkbox element.
         */
        toggleDayTimes: function($checkbox) {
            var $row = $checkbox.closest('.snab-day-row');
            var $times = $row.find('.snab-day-times');
            var $inputs = $times.find('input');
            var $status = $row.find('.snab-day-status');

            if ($checkbox.is(':checked')) {
                $times.removeClass('snab-disabled');
                $inputs.prop('disabled', false);
                $status.html('<span class="snab-available">' + (snabAvailability.i18n.available || 'Available') + '</span>');
            } else {
                $times.addClass('snab-disabled');
                $inputs.prop('disabled', true);
                $status.html('<span class="snab-unavailable">' + (snabAvailability.i18n.unavailable || 'Unavailable') + '</span>');
            }
        },

        /**
         * Save recurring schedule.
         */
        saveRecurringSchedule: function() {
            var self = this;
            var $btn = $('#snab-save-recurring-btn');

            $btn.prop('disabled', true);
            $btn.find('.snab-btn-text').hide();
            $btn.find('.snab-spinner').show();

            var formData = $('#snab-recurring-form').serialize();
            formData += '&action=snab_save_recurring_schedule';
            formData += '&staff_id=' + this.staffId;
            formData += '&type_id=' + this.typeId;
            formData += '&snab_availability_nonce=' + $('input[name="snab_availability_nonce"]').val();

            $.ajax({
                url: snabAvailability.ajaxUrl,
                type: 'POST',
                data: formData,
                success: function(response) {
                    $btn.prop('disabled', false);
                    $btn.find('.snab-btn-text').show();
                    $btn.find('.snab-spinner').hide();

                    if (response.success) {
                        self.showNotice(response.data.message, 'success');
                    } else {
                        self.showNotice(response.data.message, 'error');
                    }
                },
                error: function() {
                    $btn.prop('disabled', false);
                    $btn.find('.snab-btn-text').show();
                    $btn.find('.snab-spinner').hide();
                    self.showNotice(snabAvailability.i18n.error || 'An error occurred.', 'error');
                }
            });
        },

        /**
         * Open override modal.
         *
         * @param {object} data Optional data for editing.
         */
        openOverrideModal: function(data) {
            // Reset form
            $('#snab-override-form')[0].reset();
            $('#snab-override-id').val('');
            $('#snab-override-times-wrap').show();

            if (data && data.id) {
                // Edit mode
                $('#snab-override-modal-title').text(snabAvailability.i18n.editOverride || 'Edit Date Override');
                $('#snab-override-id').val(data.id);
                $('#snab-override-date').val(data.date);
                $('#snab-override-start').val(data.start);
                $('#snab-override-end').val(data.end);

                if (data.closed === '1') {
                    $('#snab-override-closed').prop('checked', true);
                    $('#snab-override-times-wrap').hide();
                }
            } else {
                // Add mode
                $('#snab-override-modal-title').text(snabAvailability.i18n.addOverride || 'Add Date Override');
            }

            this.$overrideModal.fadeIn(200);
            $('#snab-override-date').focus();
        },

        /**
         * Toggle override times visibility.
         *
         * @param {boolean} isClosed Whether closed all day.
         */
        toggleOverrideTimes: function(isClosed) {
            if (isClosed) {
                $('#snab-override-times-wrap').slideUp(200);
            } else {
                $('#snab-override-times-wrap').slideDown(200);
            }
        },

        /**
         * Save override.
         */
        saveOverride: function() {
            var self = this;
            var $btn = $('#snab-save-override-btn');

            $btn.prop('disabled', true);

            var formData = $('#snab-override-form').serialize();
            formData += '&action=snab_save_date_override';
            formData += '&staff_id=' + this.staffId;
            formData += '&type_id=' + this.typeId;
            formData += '&snab_availability_nonce=' + $('input[name="snab_availability_nonce"]').val();

            $.ajax({
                url: snabAvailability.ajaxUrl,
                type: 'POST',
                data: formData,
                success: function(response) {
                    $btn.prop('disabled', false);

                    if (response.success) {
                        self.closeModals();
                        self.showNotice(response.data.message, 'success');

                        // Update list
                        if (response.data.is_new) {
                            $('#snab-no-overrides').remove();
                            $('#snab-overrides-list').append(response.data.row_html);
                        } else {
                            $('#snab-overrides-list .snab-override-row[data-id="' + response.data.id + '"]')
                                .replaceWith(response.data.row_html);
                        }

                        // Update badge
                        self.updateBadge('overrides');
                    } else {
                        self.showNotice(response.data.message, 'error');
                    }
                },
                error: function() {
                    $btn.prop('disabled', false);
                    self.showNotice(snabAvailability.i18n.error || 'An error occurred.', 'error');
                }
            });
        },

        /**
         * Open blocked time modal.
         */
        openBlockedModal: function() {
            $('#snab-blocked-form')[0].reset();
            $('#snab-blocked-id').val('');
            this.$blockedModal.fadeIn(200);
            $('#snab-blocked-date').focus();
        },

        /**
         * Save blocked time.
         */
        saveBlockedTime: function() {
            var self = this;
            var $btn = $('#snab-save-blocked-btn');

            $btn.prop('disabled', true);

            var formData = $('#snab-blocked-form').serialize();
            formData += '&action=snab_save_blocked_time';
            formData += '&staff_id=' + this.staffId;
            formData += '&type_id=' + this.typeId;
            formData += '&snab_availability_nonce=' + $('input[name="snab_availability_nonce"]').val();

            $.ajax({
                url: snabAvailability.ajaxUrl,
                type: 'POST',
                data: formData,
                success: function(response) {
                    $btn.prop('disabled', false);

                    if (response.success) {
                        self.closeModals();
                        self.showNotice(response.data.message, 'success');

                        // Update list
                        $('#snab-no-blocked').remove();
                        $('#snab-blocked-list').append(response.data.row_html);

                        // Update badge
                        self.updateBadge('blocked');
                    } else {
                        self.showNotice(response.data.message, 'error');
                    }
                },
                error: function() {
                    $btn.prop('disabled', false);
                    self.showNotice(snabAvailability.i18n.error || 'An error occurred.', 'error');
                }
            });
        },

        /**
         * Open delete confirmation modal.
         *
         * @param {number} id Item ID.
         * @param {string} type Item type (override or blocked).
         */
        openDeleteModal: function(id, type) {
            $('#snab-delete-availability-id').val(id);
            $('#snab-delete-availability-type').val(type);

            var message = type === 'override'
                ? (snabAvailability.i18n.confirmDeleteOverride || 'Are you sure you want to delete this date override?')
                : (snabAvailability.i18n.confirmDeleteBlocked || 'Are you sure you want to delete this blocked time?');

            $('#snab-delete-availability-message').text(message);
            this.$deleteModal.fadeIn(200);
        },

        /**
         * Confirm delete.
         *
         * @param {number} id Item ID.
         * @param {string} type Item type.
         */
        confirmDelete: function(id, type) {
            var self = this;
            var $btn = $('#snab-confirm-availability-delete-btn');
            var action = type === 'override' ? 'snab_delete_date_override' : 'snab_delete_blocked_time';

            $btn.prop('disabled', true);

            $.ajax({
                url: snabAvailability.ajaxUrl,
                type: 'POST',
                data: {
                    action: action,
                    id: id,
                    snab_availability_nonce: $('input[name="snab_availability_nonce"]').val()
                },
                success: function(response) {
                    $btn.prop('disabled', false);

                    if (response.success) {
                        self.closeModals();
                        self.showNotice(response.data.message, 'success');

                        // Remove row
                        var selector = type === 'override' ? '.snab-override-row' : '.snab-blocked-row';
                        $(selector + '[data-id="' + id + '"]').fadeOut(300, function() {
                            $(this).remove();

                            // Show empty message if needed
                            if (type === 'override' && $('#snab-overrides-list .snab-override-row').length === 0) {
                                $('#snab-overrides-list').append('<p class="snab-no-data" id="snab-no-overrides">' +
                                    (snabAvailability.i18n.noOverrides || 'No date overrides set.') + '</p>');
                            } else if (type === 'blocked' && $('#snab-blocked-list .snab-blocked-row').length === 0) {
                                $('#snab-blocked-list').append('<p class="snab-no-data" id="snab-no-blocked">' +
                                    (snabAvailability.i18n.noBlocked || 'No blocked times.') + '</p>');
                            }

                            // Update badge
                            self.updateBadge(type === 'override' ? 'overrides' : 'blocked');
                        });
                    } else {
                        self.showNotice(response.data.message, 'error');
                    }
                },
                error: function() {
                    $btn.prop('disabled', false);
                    self.showNotice(snabAvailability.i18n.error || 'An error occurred.', 'error');
                }
            });
        },

        /**
         * Update tab badge count.
         *
         * @param {string} tab Tab name.
         */
        updateBadge: function(tab) {
            var $tab = $('.snab-availability-tabs .nav-tab[data-tab="' + tab + '"]');
            var $badge = $tab.find('.snab-badge');
            var count = tab === 'overrides'
                ? $('#snab-overrides-list .snab-override-row').length
                : $('#snab-blocked-list .snab-blocked-row').length;

            if (count > 0) {
                if ($badge.length) {
                    $badge.text(count);
                } else {
                    $tab.append('<span class="snab-badge">' + count + '</span>');
                }
            } else {
                $badge.remove();
            }
        },

        /**
         * Close all modals.
         */
        closeModals: function() {
            this.$overrideModal.fadeOut(200);
            this.$blockedModal.fadeOut(200);
            this.$deleteModal.fadeOut(200);
        },

        /**
         * Show a notice message.
         *
         * @param {string} message The message.
         * @param {string} type Notice type.
         * @param {number} duration Auto-hide duration.
         */
        showNotice: function(message, type, duration) {
            var self = this;
            type = type || 'success';
            duration = duration || 5000;

            this.$notice
                .removeClass('notice-success notice-error notice-warning')
                .addClass('notice-' + type)
                .html('<p>' + message + '</p>')
                .fadeIn(200);

            if (duration > 0) {
                setTimeout(function() {
                    self.$notice.fadeOut(200);
                }, duration);
            }
        },

        /**
         * Update URL parameter.
         *
         * @param {string} url The current URL.
         * @param {string} param The parameter name.
         * @param {string} value The parameter value.
         * @return {string} The updated URL.
         */
        updateUrlParam: function(url, param, value) {
            var urlObj = new URL(url);
            if (value === '0' || value === '') {
                urlObj.searchParams.delete(param);
            } else {
                urlObj.searchParams.set(param, value);
            }
            return urlObj.toString();
        }
    };

    // Initialize when document is ready
    $(document).ready(function() {
        // Only initialize on availability page
        if ($('.snab-availability-tabs').length) {
            SNABAvailability.init();
        }
    });

})(jQuery);
