/**
 * Admin Appointments JavaScript
 *
 * Handles AJAX interactions for the appointments management page.
 *
 * @package SN_Appointment_Booking
 * @since 1.0.0
 * @updated 1.1.0 - Added create and reschedule functionality
 */

(function($) {
    'use strict';

    var SNABAdminAppointments = {
        /**
         * Current appointment ID for modals.
         */
        currentAppointmentId: null,

        /**
         * Current appointment data for reschedule.
         */
        currentAppointment: null,

        /**
         * Initialize.
         */
        init: function() {
            this.bindEvents();
        },

        /**
         * Bind event handlers.
         */
        bindEvents: function() {
            var self = this;

            // View appointment button
            $(document).on('click', '.snab-view-btn', function(e) {
                e.preventDefault();
                var id = $(this).data('id');
                self.openViewModal(id);
            });

            // Complete button
            $(document).on('click', '.snab-complete-btn', function(e) {
                e.preventDefault();
                var id = $(this).data('id');
                self.updateStatus(id, 'completed');
            });

            // Cancel button (opens cancel modal)
            $(document).on('click', '.snab-cancel-btn', function(e) {
                e.preventDefault();
                var id = $(this).data('id');
                self.openCancelModal(id);
            });

            // Reschedule button (opens reschedule modal)
            $(document).on('click', '.snab-reschedule-btn', function(e) {
                e.preventDefault();
                var id = $(this).data('id');
                self.openRescheduleModal(id);
            });

            // Create appointment button (opens create modal)
            $(document).on('click', '#snab-create-appointment', function(e) {
                e.preventDefault();
                self.openCreateModal();
            });

            // Close modals
            $(document).on('click', '.snab-modal-close', function(e) {
                e.preventDefault();
                self.closeModals();
            });

            // Close modal on backdrop click
            $(document).on('click', '.snab-modal', function(e) {
                if ($(e.target).hasClass('snab-modal')) {
                    self.closeModals();
                }
            });

            // Close modal on Escape key
            $(document).on('keydown', function(e) {
                if (e.key === 'Escape') {
                    self.closeModals();
                }
            });

            // Confirm cancel
            $(document).on('click', '.snab-confirm-cancel', function(e) {
                e.preventDefault();
                self.confirmCancel();
            });

            // Submit create appointment
            $(document).on('click', '.snab-submit-create', function(e) {
                e.preventDefault();
                self.submitCreateAppointment();
            });

            // Submit reschedule
            $(document).on('click', '.snab-submit-reschedule', function(e) {
                e.preventDefault();
                self.submitReschedule();
            });

            // Status change in modal
            $(document).on('change', '.snab-status-select', function() {
                var id = $(this).data('id');
                var status = $(this).val();
                if (status) {
                    self.updateStatus(id, status);
                }
            });

            // Save admin notes
            $(document).on('click', '.snab-save-notes-btn', function(e) {
                e.preventDefault();
                self.saveAdminNotes();
            });

            // Export CSV
            $(document).on('click', '#snab-export-csv', function(e) {
                e.preventDefault();
                self.exportCSV();
            });

            // Date change in create modal - load available slots
            $(document).on('change', '#snab-create-date', function() {
                var date = $(this).val();
                var typeId = $('#snab-create-type').val();
                if (date && typeId) {
                    self.loadAvailableSlots(date, typeId, '#snab-create-time');
                } else if (date) {
                    self.loadAvailableSlots(date, 0, '#snab-create-time');
                }
            });

            // Type change in create modal - reload slots if date is set
            $(document).on('change', '#snab-create-type', function() {
                var date = $('#snab-create-date').val();
                var typeId = $(this).val();
                if (date) {
                    self.loadAvailableSlots(date, typeId, '#snab-create-time');
                }
            });

            // Date change in reschedule modal - load available slots
            $(document).on('change', '#snab-reschedule-date', function() {
                var date = $(this).val();
                if (date && self.currentAppointment) {
                    self.loadAvailableSlots(date, self.currentAppointment.appointment_type_id, '#snab-reschedule-time', self.currentAppointmentId);
                }
            });
        },

        /**
         * Open view/edit modal.
         */
        openViewModal: function(id) {
            var self = this;
            var $modal = $('#snab-appointment-modal');
            var $loading = $modal.find('.snab-modal-loading');
            var $details = $modal.find('.snab-appointment-details');

            this.currentAppointmentId = id;

            // Show modal with loading state
            $modal.show();
            $loading.show();
            $details.hide().empty();

            // Fetch appointment details
            $.ajax({
                url: snabAppointments.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'snab_get_appointment',
                    nonce: snabAppointments.nonce,
                    id: id
                },
                success: function(response) {
                    $loading.hide();
                    if (response.success) {
                        $details.html(response.data.html).show();
                    } else {
                        $details.html('<p class="snab-error">' + (response.data || snabAppointments.i18n.error) + '</p>').show();
                    }
                },
                error: function() {
                    $loading.hide();
                    $details.html('<p class="snab-error">' + snabAppointments.i18n.error + '</p>').show();
                }
            });
        },

        /**
         * Open cancel modal.
         */
        openCancelModal: function(id) {
            this.currentAppointmentId = id;
            $('#snab-cancel-reason').val('');
            $('#snab-send-cancel-email').prop('checked', true);
            $('#snab-cancel-modal').show();
        },

        /**
         * Open create appointment modal.
         */
        openCreateModal: function() {
            // Reset form
            $('#snab-create-form')[0].reset();
            $('#snab-create-time').prop('disabled', true).html('<option value="">' + snabAppointments.i18n.selectDateFirst + '</option>');
            $('#snab-create-send-email').prop('checked', true);
            $('.snab-time-hint').hide();

            $('#snab-create-modal').show();
        },

        /**
         * Open reschedule modal.
         */
        openRescheduleModal: function(id) {
            var self = this;
            this.currentAppointmentId = id;

            // Reset form
            $('#snab-reschedule-form')[0].reset();
            $('#snab-reschedule-id').val(id);
            $('#snab-reschedule-time').prop('disabled', true).html('<option value="">' + snabAppointments.i18n.selectDateFirst + '</option>');
            $('#snab-reschedule-send-email').prop('checked', true);

            // Show loading in current details
            $('.snab-current-details').html('<span class="spinner is-active"></span>');
            $('#snab-reschedule-modal').show();

            // Fetch appointment details
            $.ajax({
                url: snabAppointments.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'snab_get_appointment',
                    nonce: snabAppointments.nonce,
                    id: id
                },
                success: function(response) {
                    if (response.success) {
                        self.currentAppointment = response.data.appointment;

                        // Display current appointment details
                        var html = '<table class="snab-detail-table">' +
                            '<tr><th>' + snabAppointments.i18n.type + ':</th><td>' + self.currentAppointment.type_name + '</td></tr>' +
                            '<tr><th>' + snabAppointments.i18n.date + ':</th><td>' + self.currentAppointment.formatted_date + '</td></tr>' +
                            '<tr><th>' + snabAppointments.i18n.time + ':</th><td>' + self.currentAppointment.formatted_time + '</td></tr>' +
                            '<tr><th>' + snabAppointments.i18n.client + ':</th><td>' + self.currentAppointment.client_name + '</td></tr>' +
                            '</table>';

                        if (self.currentAppointment.reschedule_count > 0) {
                            html += '<p class="snab-reschedule-info">' + snabAppointments.i18n.previouslyRescheduled.replace('%d', self.currentAppointment.reschedule_count) + '</p>';
                        }

                        $('.snab-current-details').html(html);
                    } else {
                        $('.snab-current-details').html('<p class="snab-error">' + (response.data || snabAppointments.i18n.error) + '</p>');
                    }
                },
                error: function() {
                    $('.snab-current-details').html('<p class="snab-error">' + snabAppointments.i18n.error + '</p>');
                }
            });
        },

        /**
         * Close all modals.
         */
        closeModals: function() {
            $('.snab-modal').hide();
            this.currentAppointmentId = null;
            this.currentAppointment = null;
        },

        /**
         * Load available time slots for a date.
         */
        loadAvailableSlots: function(date, typeId, selectSelector, excludeAppointmentId) {
            var $select = $(selectSelector);

            $select.prop('disabled', true).html('<option value="">' + snabAppointments.i18n.loading + '</option>');

            $.ajax({
                url: snabAppointments.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'snab_get_available_slots',
                    nonce: snabAppointments.nonce,
                    date: date,
                    appointment_type_id: typeId || 0,
                    exclude_appointment_id: excludeAppointmentId || 0
                },
                success: function(response) {
                    if (response.success && response.data.slots.length > 0) {
                        var html = '<option value="">' + snabAppointments.i18n.selectTime + '</option>';
                        response.data.slots.forEach(function(slot) {
                            html += '<option value="' + slot.time + '">' + slot.label + '</option>';
                        });
                        $select.html(html).prop('disabled', false);
                        $('.snab-time-hint').show();
                    } else {
                        $select.html('<option value="">' + snabAppointments.i18n.noSlotsAvailable + '</option>').prop('disabled', true);
                        $('.snab-time-hint').hide();
                    }
                },
                error: function() {
                    $select.html('<option value="">' + snabAppointments.i18n.error + '</option>').prop('disabled', true);
                }
            });
        },

        /**
         * Submit create appointment form.
         */
        submitCreateAppointment: function() {
            var self = this;
            var $form = $('#snab-create-form');
            var $button = $('.snab-submit-create');

            // Basic validation
            var clientName = $('#snab-create-name').val().trim();
            var clientEmail = $('#snab-create-email').val().trim();
            var typeId = $('#snab-create-type').val();
            var date = $('#snab-create-date').val();
            var time = $('#snab-create-time').val();

            if (!clientName || !clientEmail || !typeId || !date || !time) {
                self.showNotice(snabAppointments.i18n.fillRequired, 'error');
                return;
            }

            $button.prop('disabled', true).text(snabAppointments.i18n.creating);

            $.ajax({
                url: snabAppointments.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'snab_create_appointment',
                    nonce: snabAppointments.nonce,
                    client_name: clientName,
                    client_email: clientEmail,
                    client_phone: $('#snab-create-phone').val(),
                    property_address: $('#snab-create-address').val(),
                    client_notes: $('#snab-create-notes').val(),
                    appointment_type_id: typeId,
                    appointment_date: date,
                    start_time: time,
                    send_confirmation: $('#snab-create-send-email').is(':checked') ? '1' : '0'
                },
                success: function(response) {
                    $button.prop('disabled', false).text(snabAppointments.i18n.createAppointment);

                    if (response.success) {
                        self.closeModals();
                        self.showNotice(response.data.message, 'success');

                        // Reload page to show new appointment
                        setTimeout(function() {
                            window.location.reload();
                        }, 1000);
                    } else {
                        self.showNotice(response.data || snabAppointments.i18n.error, 'error');
                    }
                },
                error: function() {
                    $button.prop('disabled', false).text(snabAppointments.i18n.createAppointment);
                    self.showNotice(snabAppointments.i18n.error, 'error');
                }
            });
        },

        /**
         * Submit reschedule form.
         */
        submitReschedule: function() {
            var self = this;
            var $button = $('.snab-submit-reschedule');

            var newDate = $('#snab-reschedule-date').val();
            var newTime = $('#snab-reschedule-time').val();
            var reason = $('#snab-reschedule-reason').val();
            var sendNotification = $('#snab-reschedule-send-email').is(':checked');

            if (!newDate || !newTime) {
                self.showNotice(snabAppointments.i18n.selectDateAndTime, 'error');
                return;
            }

            $button.prop('disabled', true).text(snabAppointments.i18n.rescheduling);

            $.ajax({
                url: snabAppointments.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'snab_reschedule_appointment',
                    nonce: snabAppointments.nonce,
                    appointment_id: self.currentAppointmentId,
                    new_date: newDate,
                    new_time: newTime,
                    reason: reason,
                    send_notification: sendNotification ? '1' : '0'
                },
                success: function(response) {
                    $button.prop('disabled', false).text(snabAppointments.i18n.reschedule);

                    if (response.success) {
                        self.closeModals();
                        self.showNotice(response.data.message, 'success');

                        // Reload page to show updated appointment
                        setTimeout(function() {
                            window.location.reload();
                        }, 1000);
                    } else {
                        self.showNotice(response.data || snabAppointments.i18n.error, 'error');
                    }
                },
                error: function() {
                    $button.prop('disabled', false).text(snabAppointments.i18n.reschedule);
                    self.showNotice(snabAppointments.i18n.error, 'error');
                }
            });
        },

        /**
         * Update appointment status.
         */
        updateStatus: function(id, status) {
            var self = this;

            $.ajax({
                url: snabAppointments.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'snab_update_appointment_status',
                    nonce: snabAppointments.nonce,
                    id: id,
                    status: status
                },
                success: function(response) {
                    if (response.success) {
                        // Update the row in the table
                        var $row = $('tr[data-id="' + id + '"]');
                        var $statusSpan = $row.find('.snab-status');

                        // Update status badge
                        $statusSpan.removeClass('snab-status-pending snab-status-confirmed snab-status-completed snab-status-no_show')
                                   .addClass('snab-status-' + status)
                                   .text(response.data.status_label);

                        // Update buttons
                        if (status === 'completed' || status === 'no_show') {
                            $row.find('.snab-complete-btn, .snab-cancel-btn, .snab-reschedule-btn').remove();
                        }

                        // Reset select if in modal
                        $('.snab-status-select').val('');

                        // Show success message
                        self.showNotice(response.data.message, 'success');
                    } else {
                        self.showNotice(response.data || snabAppointments.i18n.error, 'error');
                    }
                },
                error: function() {
                    self.showNotice(snabAppointments.i18n.error, 'error');
                }
            });
        },

        /**
         * Confirm and process cancellation.
         */
        confirmCancel: function() {
            var self = this;
            var id = this.currentAppointmentId;
            var reason = $('#snab-cancel-reason').val();
            var sendEmail = $('#snab-send-cancel-email').is(':checked');

            var $button = $('.snab-confirm-cancel');
            $button.prop('disabled', true).text(snabAppointments.i18n.cancelling);

            $.ajax({
                url: snabAppointments.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'snab_cancel_appointment',
                    nonce: snabAppointments.nonce,
                    id: id,
                    reason: reason,
                    send_email: sendEmail ? 'true' : 'false'
                },
                success: function(response) {
                    $button.prop('disabled', false).text(snabAppointments.i18n.cancelAppointment);

                    if (response.success) {
                        self.closeModals();

                        // Update the row in the table
                        var $row = $('tr[data-id="' + id + '"]');
                        var $statusSpan = $row.find('.snab-status');

                        // Update status badge
                        $statusSpan.removeClass('snab-status-pending snab-status-confirmed')
                                   .addClass('snab-status-cancelled')
                                   .text(snabAppointments.i18n.cancelled);

                        // Remove action buttons
                        $row.find('.snab-complete-btn, .snab-cancel-btn, .snab-reschedule-btn').remove();

                        // Show success message
                        self.showNotice(response.data.message, 'success');
                    } else {
                        self.showNotice(response.data || snabAppointments.i18n.error, 'error');
                    }
                },
                error: function() {
                    $button.prop('disabled', false).text(snabAppointments.i18n.cancelAppointment);
                    self.showNotice(snabAppointments.i18n.error, 'error');
                }
            });
        },

        /**
         * Save admin notes.
         */
        saveAdminNotes: function() {
            var self = this;
            var $textarea = $('#snab-admin-notes');
            var $button = $('.snab-save-notes-btn');
            var $saved = $('.snab-notes-saved');
            var id = $textarea.data('id');
            var notes = $textarea.val();

            $button.prop('disabled', true).text(snabAppointments.i18n.saving);

            $.ajax({
                url: snabAppointments.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'snab_save_admin_notes',
                    nonce: snabAppointments.nonce,
                    id: id,
                    notes: notes
                },
                success: function(response) {
                    $button.prop('disabled', false).text(snabAppointments.i18n.saveNotes);

                    if (response.success) {
                        $saved.show().delay(2000).fadeOut();
                    } else {
                        self.showNotice(response.data || snabAppointments.i18n.error, 'error');
                    }
                },
                error: function() {
                    $button.prop('disabled', false).text(snabAppointments.i18n.saveNotes);
                    self.showNotice(snabAppointments.i18n.error, 'error');
                }
            });
        },

        /**
         * Export appointments to CSV.
         */
        exportCSV: function() {
            // Build URL with current filters
            var params = new URLSearchParams(window.location.search);
            params.set('action', 'snab_export_appointments');
            params.set('nonce', snabAppointments.nonce);

            // Open download in new window
            window.location.href = snabAppointments.ajaxUrl + '?' + params.toString();
        },

        /**
         * Show admin notice.
         */
        showNotice: function(message, type) {
            var $notice = $('<div class="notice notice-' + type + ' is-dismissible"><p>' + message + '</p></div>');

            // Remove any existing notices
            $('.wrap .notice.is-dismissible').remove();

            // Add new notice
            $('.wrap h1').first().after($notice);

            // Auto-dismiss after 5 seconds
            setTimeout(function() {
                $notice.fadeOut(function() {
                    $(this).remove();
                });
            }, 5000);

            // Make dismissible
            $notice.on('click', '.notice-dismiss', function() {
                $notice.fadeOut(function() {
                    $(this).remove();
                });
            });
        }
    };

    // Initialize on document ready
    $(document).ready(function() {
        SNABAdminAppointments.init();
    });

})(jQuery);
