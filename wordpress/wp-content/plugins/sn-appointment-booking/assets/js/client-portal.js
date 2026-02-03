/**
 * SN Appointment Booking - Client Portal
 *
 * Handles client self-service functionality including viewing,
 * cancelling, and rescheduling appointments.
 *
 * @package SN_Appointment_Booking
 * @since 1.5.0
 */

(function($) {
    'use strict';

    /**
     * Client Portal Widget Class
     */
    class SNABClientPortal {
        /**
         * Constructor
         * @param {jQuery} $container - The portal container element
         */
        constructor($container) {
            this.$container = $container;
            this.options = {
                showPast: $container.data('show-past') === 'true',
                daysPast: parseInt($container.data('days-past')) || 90,
                allowCancel: $container.data('allow-cancel') === 'true',
                allowReschedule: $container.data('allow-reschedule') === 'true'
            };

            // State
            this.currentTab = 'upcoming';
            this.currentPage = 1;
            this.totalPages = 1;
            this.appointments = [];
            this.selectedAppointment = null;
            this.rescheduleSlots = {};
            this.rescheduleWeekStart = null;

            // Cache elements
            this.$loading = $container.find('.snab-portal-loading');
            this.$list = $container.find('.snab-appointments-list');
            this.$empty = $container.find('.snab-no-appointments');
            this.$pagination = $container.find('.snab-portal-pagination');
            this.$cancelModal = $container.find('.snab-cancel-modal');
            this.$rescheduleModal = $container.find('.snab-reschedule-modal');

            this.init();
        }

        /**
         * Initialize the portal
         */
        init() {
            this.bindEvents();
            this.loadAppointments();
        }

        /**
         * Bind event handlers
         */
        bindEvents() {
            const self = this;

            // Tab switching
            this.$container.on('click', '.snab-tab-btn', function() {
                const tab = $(this).data('tab');
                self.switchTab(tab);
            });

            // Pagination
            this.$container.on('click', '.snab-prev-page', function() {
                if (self.currentPage > 1) {
                    self.currentPage--;
                    self.loadAppointments();
                }
            });

            this.$container.on('click', '.snab-next-page', function() {
                if (self.currentPage < self.totalPages) {
                    self.currentPage++;
                    self.loadAppointments();
                }
            });

            // Cancel button
            this.$container.on('click', '.snab-cancel-btn', function() {
                const appointmentId = $(this).closest('.snab-appointment-card').data('appointment-id');
                self.showCancelModal(appointmentId);
            });

            // Reschedule button
            this.$container.on('click', '.snab-reschedule-btn', function() {
                const appointmentId = $(this).closest('.snab-appointment-card').data('appointment-id');
                self.showRescheduleModal(appointmentId);
            });

            // Cancel modal events
            this.$cancelModal.on('click', '.snab-modal-close, .snab-modal-overlay, .snab-cancel-close', function() {
                self.hideCancelModal();
            });

            this.$cancelModal.on('submit', '.snab-cancel-form', function(e) {
                e.preventDefault();
                self.submitCancellation();
            });

            // Reschedule modal events
            this.$rescheduleModal.on('click', '.snab-modal-close, .snab-modal-overlay', function() {
                self.hideRescheduleModal();
            });

            this.$rescheduleModal.on('click', '.snab-prev-week', function() {
                self.navigateRescheduleWeek(-1);
            });

            this.$rescheduleModal.on('click', '.snab-next-week', function() {
                self.navigateRescheduleWeek(1);
            });

            this.$rescheduleModal.on('click', '.snab-calendar-day.available', function() {
                self.selectRescheduleDate($(this).data('date'));
            });

            this.$rescheduleModal.on('click', '.snab-time-slot', function() {
                self.selectRescheduleTime($(this).data('time'));
            });

            this.$rescheduleModal.on('click', '.snab-reschedule-back', function() {
                self.resetRescheduleSelection();
            });

            this.$rescheduleModal.on('submit', '.snab-reschedule-form', function(e) {
                e.preventDefault();
                self.submitReschedule();
            });
        }

        /**
         * Switch between tabs
         * @param {string} tab - Tab name ('upcoming' or 'past')
         */
        switchTab(tab) {
            this.currentTab = tab;
            this.currentPage = 1;

            // Update tab buttons
            this.$container.find('.snab-tab-btn').removeClass('active');
            this.$container.find(`.snab-tab-btn[data-tab="${tab}"]`).addClass('active');

            this.loadAppointments();
        }

        /**
         * Load appointments from server
         */
        loadAppointments() {
            const self = this;

            this.showLoading();

            $.ajax({
                url: snabPortal.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'snab_client_get_appointments',
                    nonce: snabPortal.nonce,
                    status: this.currentTab,
                    days_past: this.options.daysPast,
                    page: this.currentPage,
                    per_page: 10
                },
                success: function(response) {
                    if (response.success) {
                        self.appointments = response.data.appointments;
                        self.totalPages = response.data.pages;
                        self.renderAppointments();
                        self.updateTabCounts();
                    } else {
                        self.showError(response.data || snabPortal.i18n.error);
                    }
                },
                error: function() {
                    self.showError(snabPortal.i18n.error);
                }
            });
        }

        /**
         * Show loading state
         */
        showLoading() {
            this.$loading.show();
            this.$list.hide();
            this.$empty.hide();
            this.$pagination.hide();
        }

        /**
         * Render appointments list
         */
        renderAppointments() {
            this.$loading.hide();

            if (this.appointments.length === 0) {
                this.$list.hide();
                this.$empty.show();
                this.$empty.find('.snab-empty-message').text(
                    this.currentTab === 'upcoming' ? snabPortal.i18n.noUpcoming : snabPortal.i18n.noPast
                );
                this.$pagination.hide();
                return;
            }

            let html = '';
            this.appointments.forEach(apt => {
                html += this.renderAppointmentCard(apt);
            });

            this.$list.html(html).show();
            this.$empty.hide();
            this.updatePagination();
        }

        /**
         * Render a single appointment card
         * @param {Object} apt - Appointment data
         * @returns {string} HTML string
         */
        renderAppointmentCard(apt) {
            const statusClass = `status-${apt.status}`;
            const statusLabel = snabPortal.i18n.statuses[apt.status] || apt.status_label;

            let actionsHtml = '';
            if (apt.is_upcoming) {
                if (this.options.allowCancel && apt.can_cancel) {
                    actionsHtml += `<button type="button" class="snab-action-btn snab-cancel-btn">
                        <span class="dashicons dashicons-no-alt"></span>
                        ${snabPortal.i18n.cancel}
                    </button>`;
                }
                if (this.options.allowReschedule && apt.can_reschedule) {
                    actionsHtml += `<button type="button" class="snab-action-btn snab-reschedule-btn">
                        <span class="dashicons dashicons-calendar-alt"></span>
                        ${snabPortal.i18n.reschedule}
                    </button>`;
                }
            }

            return `
                <div class="snab-appointment-card ${statusClass}" data-appointment-id="${apt.id}">
                    <div class="snab-card-header">
                        <span class="snab-type-indicator" style="background-color: ${apt.type_color}"></span>
                        <span class="snab-type-name">${this.escapeHtml(apt.type_name)}</span>
                        <span class="snab-status-badge ${statusClass}">${this.escapeHtml(statusLabel)}</span>
                    </div>
                    <div class="snab-card-body">
                        <div class="snab-card-datetime">
                            <div class="snab-card-date">
                                <span class="dashicons dashicons-calendar-alt"></span>
                                ${this.escapeHtml(apt.formatted_date)}
                            </div>
                            <div class="snab-card-time">
                                <span class="dashicons dashicons-clock"></span>
                                ${this.escapeHtml(apt.formatted_time)} - ${this.escapeHtml(apt.formatted_end_time)}
                            </div>
                        </div>
                        ${apt.property_address ? `
                            <div class="snab-card-address">
                                <span class="dashicons dashicons-location"></span>
                                ${this.escapeHtml(apt.property_address)}
                            </div>
                        ` : ''}
                        ${apt.reschedule_count > 0 ? `
                            <div class="snab-card-reschedule-count">
                                <span class="dashicons dashicons-update"></span>
                                Rescheduled ${apt.reschedule_count} time${apt.reschedule_count > 1 ? 's' : ''}
                            </div>
                        ` : ''}
                    </div>
                    ${actionsHtml ? `<div class="snab-card-actions">${actionsHtml}</div>` : ''}
                </div>
            `;
        }

        /**
         * Update tab counts
         */
        updateTabCounts() {
            // This is a simplified version - in a full implementation,
            // we'd make separate count requests or get counts from initial load
        }

        /**
         * Update pagination controls
         */
        updatePagination() {
            if (this.totalPages <= 1) {
                this.$pagination.hide();
                return;
            }

            this.$pagination.show();
            this.$pagination.find('.snab-prev-page').prop('disabled', this.currentPage <= 1);
            this.$pagination.find('.snab-next-page').prop('disabled', this.currentPage >= this.totalPages);
            this.$pagination.find('.snab-page-info').text(
                snabPortal.i18n.pageOf.replace('%1$d', this.currentPage).replace('%2$d', this.totalPages)
            );
        }

        /**
         * Show cancel modal
         * @param {number} appointmentId - Appointment ID
         */
        showCancelModal(appointmentId) {
            this.selectedAppointment = this.appointments.find(a => a.id === appointmentId);
            if (!this.selectedAppointment) return;

            const apt = this.selectedAppointment;
            const detailsHtml = `
                <div class="snab-modal-appointment-details">
                    <p><strong>${this.escapeHtml(apt.type_name)}</strong></p>
                    <p><span class="dashicons dashicons-calendar-alt"></span> ${this.escapeHtml(apt.formatted_date)}</p>
                    <p><span class="dashicons dashicons-clock"></span> ${this.escapeHtml(apt.formatted_time)} - ${this.escapeHtml(apt.formatted_end_time)}</p>
                </div>
            `;

            this.$cancelModal.find('.snab-cancel-details').html(detailsHtml);
            this.$cancelModal.find('[name="appointment_id"]').val(appointmentId);
            this.$cancelModal.find('[name="reason"]').val('');
            this.$cancelModal.show();
        }

        /**
         * Hide cancel modal
         */
        hideCancelModal() {
            this.$cancelModal.hide();
            this.selectedAppointment = null;
        }

        /**
         * Submit cancellation
         */
        submitCancellation() {
            const self = this;
            const $form = this.$cancelModal.find('.snab-cancel-form');
            const appointmentId = $form.find('[name="appointment_id"]').val();
            const reason = $form.find('[name="reason"]').val();

            if (snabPortal.requireCancelReason && !reason.trim()) {
                alert(snabPortal.i18n.reasonRequired);
                return;
            }

            const $submitBtn = $form.find('.snab-confirm-cancel');
            $submitBtn.prop('disabled', true).text(snabPortal.i18n.loading);

            $.ajax({
                url: snabPortal.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'snab_client_cancel_appointment',
                    nonce: snabPortal.nonce,
                    appointment_id: appointmentId,
                    reason: reason
                },
                success: function(response) {
                    if (response.success) {
                        self.hideCancelModal();
                        self.showSuccess(snabPortal.i18n.cancelSuccess);
                        self.loadAppointments();
                    } else {
                        alert(response.data || snabPortal.i18n.error);
                        $submitBtn.prop('disabled', false).text(snabPortal.i18n.cancel);
                    }
                },
                error: function() {
                    alert(snabPortal.i18n.error);
                    $submitBtn.prop('disabled', false).text(snabPortal.i18n.cancel);
                }
            });
        }

        /**
         * Show reschedule modal
         * @param {number} appointmentId - Appointment ID
         */
        showRescheduleModal(appointmentId) {
            this.selectedAppointment = this.appointments.find(a => a.id === appointmentId);
            if (!this.selectedAppointment) return;

            const apt = this.selectedAppointment;
            const currentHtml = `
                <div class="snab-current-appointment">
                    <p><strong>Current Appointment:</strong></p>
                    <p><span class="dashicons dashicons-calendar-alt"></span> ${this.escapeHtml(apt.formatted_date)}</p>
                    <p><span class="dashicons dashicons-clock"></span> ${this.escapeHtml(apt.formatted_time)} - ${this.escapeHtml(apt.formatted_end_time)}</p>
                </div>
            `;

            this.$rescheduleModal.find('.snab-reschedule-current').html(currentHtml);
            this.$rescheduleModal.find('[name="appointment_id"]').val(appointmentId);
            this.$rescheduleModal.find('.snab-reschedule-form').hide();
            this.$rescheduleModal.find('.snab-reschedule-times').hide();

            // Set initial week to today
            const today = new Date();
            this.rescheduleWeekStart = this.getWeekStart(today);

            this.$rescheduleModal.show();
            this.loadRescheduleSlots();
        }

        /**
         * Hide reschedule modal
         */
        hideRescheduleModal() {
            this.$rescheduleModal.hide();
            this.selectedAppointment = null;
            this.rescheduleSlots = {};
        }

        /**
         * Get the Monday of the week for a given date
         * @param {Date} date - Input date
         * @returns {Date} Monday of that week
         */
        getWeekStart(date) {
            const d = new Date(date);
            const day = d.getDay();
            const diff = d.getDate() - day + (day === 0 ? -6 : 1);
            return new Date(d.setDate(diff));
        }

        /**
         * Navigate reschedule calendar week
         * @param {number} direction - -1 for previous, 1 for next
         */
        navigateRescheduleWeek(direction) {
            const newStart = new Date(this.rescheduleWeekStart);
            newStart.setDate(newStart.getDate() + (direction * 7));

            // Don't go before today
            const today = new Date();
            today.setHours(0, 0, 0, 0);
            if (newStart < today) {
                return;
            }

            this.rescheduleWeekStart = newStart;
            this.loadRescheduleSlots();
        }

        /**
         * Load available slots for rescheduling
         */
        loadRescheduleSlots() {
            const self = this;
            const startDate = this.formatDate(this.rescheduleWeekStart);
            const endDate = this.formatDate(new Date(this.rescheduleWeekStart.getTime() + 6 * 24 * 60 * 60 * 1000));

            // DEBUG: Log the request
            console.log('SNAB Debug: loadRescheduleSlots called', {
                appointmentId: this.selectedAppointment ? this.selectedAppointment.id : 'none',
                startDate: startDate,
                endDate: endDate,
                nonce: snabPortal.nonce ? snabPortal.nonce.substring(0, 5) + '...' : 'none'
            });

            // Update calendar title
            this.updateRescheduleCalendarTitle();

            // Show loading
            const $grid = this.$rescheduleModal.find('.snab-calendar-grid');
            $grid.html('<div class="snab-calendar-loading"><span class="spinner is-active"></span> ' + snabPortal.i18n.loading + '</div>');

            $.ajax({
                url: snabPortal.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'snab_client_get_reschedule_slots',
                    nonce: snabPortal.nonce,
                    appointment_id: this.selectedAppointment.id,
                    start_date: startDate,
                    end_date: endDate
                },
                success: function(response) {
                    // DEBUG: Log the response
                    console.log('SNAB Debug: AJAX response', response);

                    if (response.success) {
                        self.rescheduleSlots = response.data.slots;
                        console.log('SNAB Debug: Slots received', {
                            slotDates: Object.keys(response.data.slots || {}),
                            totalSlots: Object.values(response.data.slots || {}).reduce((sum, arr) => sum + arr.length, 0)
                        });
                        self.renderRescheduleCalendar();
                        self.updateRescheduleNavigation();
                    } else {
                        console.error('SNAB Debug: AJAX error response', response.data);
                        $grid.html('<div class="snab-error">' + (response.data || snabPortal.i18n.error) + '</div>');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('SNAB Debug: AJAX error', {status: status, error: error, response: xhr.responseText});
                    $grid.html('<div class="snab-error">' + snabPortal.i18n.error + '</div>');
                }
            });
        }

        /**
         * Update reschedule calendar title
         */
        updateRescheduleCalendarTitle() {
            const start = this.rescheduleWeekStart;
            const end = new Date(start.getTime() + 6 * 24 * 60 * 60 * 1000);

            const startMonth = snabPortal.i18n.months[start.getMonth()];
            const endMonth = snabPortal.i18n.months[end.getMonth()];

            let title;
            if (start.getMonth() === end.getMonth()) {
                title = `${startMonth} ${start.getDate()} - ${end.getDate()}, ${start.getFullYear()}`;
            } else {
                title = `${startMonth} ${start.getDate()} - ${endMonth} ${end.getDate()}, ${end.getFullYear()}`;
            }

            this.$rescheduleModal.find('.snab-calendar-title').text(title);
        }

        /**
         * Update reschedule navigation buttons
         */
        updateRescheduleNavigation() {
            const today = new Date();
            today.setHours(0, 0, 0, 0);

            const canGoPrev = this.rescheduleWeekStart > today;
            this.$rescheduleModal.find('.snab-prev-week').prop('disabled', !canGoPrev);
        }

        /**
         * Render reschedule calendar
         */
        renderRescheduleCalendar() {
            const $grid = this.$rescheduleModal.find('.snab-calendar-grid');
            const today = new Date();
            today.setHours(0, 0, 0, 0);

            let html = '<div class="snab-calendar-week">';

            for (let i = 0; i < 7; i++) {
                const date = new Date(this.rescheduleWeekStart);
                date.setDate(date.getDate() + i);
                const dateStr = this.formatDate(date);
                const isToday = date.toDateString() === today.toDateString();
                const isPast = date < today;
                const hasSlots = this.rescheduleSlots[dateStr] && this.rescheduleSlots[dateStr].length > 0;

                let dayClass = 'snab-calendar-day';
                if (isToday) dayClass += ' today';
                if (isPast) dayClass += ' past';
                if (hasSlots) dayClass += ' available';

                html += `
                    <div class="${dayClass}" data-date="${dateStr}">
                        <span class="snab-day-name">${snabPortal.i18n.days[date.getDay()]}</span>
                        <span class="snab-day-number">${date.getDate()}</span>
                        ${hasSlots ? '<span class="snab-day-indicator"></span>' : ''}
                    </div>
                `;
            }

            html += '</div>';
            $grid.html(html);
        }

        /**
         * Select a date for rescheduling
         * @param {string} date - Date in Y-m-d format
         */
        selectRescheduleDate(date) {
            // DEBUG: Log date selection
            console.log('SNAB Debug: selectRescheduleDate called', {
                date: date,
                hasSlots: !!this.rescheduleSlots[date],
                slotCount: this.rescheduleSlots[date] ? this.rescheduleSlots[date].length : 0,
                allSlots: this.rescheduleSlots
            });

            if (!this.rescheduleSlots[date] || this.rescheduleSlots[date].length === 0) {
                console.warn('SNAB Debug: No slots for date', date);
                return;
            }

            // Highlight selected date
            this.$rescheduleModal.find('.snab-calendar-day').removeClass('selected');
            this.$rescheduleModal.find(`.snab-calendar-day[data-date="${date}"]`).addClass('selected');

            // Store selected date
            this.$rescheduleModal.find('[name="new_date"]').val(date);

            // Render time slots
            const slots = this.rescheduleSlots[date];
            let slotsHtml = '';

            slots.forEach(slot => {
                slotsHtml += `
                    <button type="button" class="snab-time-slot" data-time="${slot.value}">
                        ${this.escapeHtml(slot.label)}
                    </button>
                `;
            });

            console.log('SNAB Debug: Rendering slots HTML', { date: date, slotCount: slots.length });
            this.$rescheduleModal.find('.snab-reschedule-times .snab-time-slots').html(slotsHtml);
            this.$rescheduleModal.find('.snab-reschedule-times').show();
        }

        /**
         * Select a time for rescheduling
         * @param {string} time - Time in H:i format
         */
        selectRescheduleTime(time) {
            const date = this.$rescheduleModal.find('[name="new_date"]').val();

            // Highlight selected time
            this.$rescheduleModal.find('.snab-time-slot').removeClass('selected');
            this.$rescheduleModal.find(`.snab-time-slot[data-time="${time}"]`).addClass('selected');

            // Store selected time
            this.$rescheduleModal.find('[name="new_time"]').val(time);

            // Show confirmation
            const apt = this.selectedAppointment;
            const dateObj = new Date(date + 'T12:00:00');
            const formattedDate = snabPortal.i18n.days[dateObj.getDay()] + ', ' +
                snabPortal.i18n.months[dateObj.getMonth()] + ' ' + dateObj.getDate() + ', ' + dateObj.getFullYear();

            const slot = this.rescheduleSlots[date].find(s => s.value === time);
            const formattedTime = slot ? slot.label : time;

            const summaryHtml = `
                <div class="snab-reschedule-summary-content">
                    <p><strong>New Appointment Time:</strong></p>
                    <p><span class="dashicons dashicons-calendar-alt"></span> ${this.escapeHtml(formattedDate)}</p>
                    <p><span class="dashicons dashicons-clock"></span> ${this.escapeHtml(formattedTime)}</p>
                </div>
            `;

            this.$rescheduleModal.find('.snab-reschedule-summary').html(summaryHtml);
            this.$rescheduleModal.find('.snab-reschedule-form').show();
        }

        /**
         * Reset reschedule selection
         */
        resetRescheduleSelection() {
            this.$rescheduleModal.find('.snab-calendar-day').removeClass('selected');
            this.$rescheduleModal.find('.snab-time-slot').removeClass('selected');
            this.$rescheduleModal.find('[name="new_date"]').val('');
            this.$rescheduleModal.find('[name="new_time"]').val('');
            this.$rescheduleModal.find('.snab-reschedule-times').hide();
            this.$rescheduleModal.find('.snab-reschedule-form').hide();
        }

        /**
         * Submit reschedule
         */
        submitReschedule() {
            const self = this;
            const $form = this.$rescheduleModal.find('.snab-reschedule-form');
            const appointmentId = $form.find('[name="appointment_id"]').val();
            const newDate = $form.find('[name="new_date"]').val();
            const newTime = $form.find('[name="new_time"]').val();

            if (!newDate || !newTime) {
                alert(snabPortal.i18n.selectDateTime);
                return;
            }

            const $submitBtn = $form.find('.snab-confirm-reschedule');
            $submitBtn.prop('disabled', true).text(snabPortal.i18n.loading);

            $.ajax({
                url: snabPortal.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'snab_client_reschedule_appointment',
                    nonce: snabPortal.nonce,
                    appointment_id: appointmentId,
                    new_date: newDate,
                    new_time: newTime
                },
                success: function(response) {
                    if (response.success) {
                        self.hideRescheduleModal();
                        self.showSuccess(snabPortal.i18n.rescheduleSuccess);
                        self.loadAppointments();
                    } else {
                        alert(response.data || snabPortal.i18n.error);
                        $submitBtn.prop('disabled', false).text(snabPortal.i18n.reschedule);
                    }
                },
                error: function() {
                    alert(snabPortal.i18n.error);
                    $submitBtn.prop('disabled', false).text(snabPortal.i18n.reschedule);
                }
            });
        }

        /**
         * Show success message
         * @param {string} message - Success message
         */
        showSuccess(message) {
            // Simple implementation - could be enhanced with toast notifications
            const $notice = $('<div class="snab-success-notice">' + this.escapeHtml(message) + '</div>');
            this.$container.prepend($notice);
            setTimeout(() => $notice.fadeOut(() => $notice.remove()), 3000);
        }

        /**
         * Show error message
         * @param {string} message - Error message
         */
        showError(message) {
            this.$loading.hide();
            this.$list.html('<div class="snab-error">' + this.escapeHtml(message) + '</div>').show();
        }

        /**
         * Format date as Y-m-d
         * @param {Date} date - Date object
         * @returns {string} Formatted date
         */
        formatDate(date) {
            const year = date.getFullYear();
            const month = String(date.getMonth() + 1).padStart(2, '0');
            const day = String(date.getDate()).padStart(2, '0');
            return `${year}-${month}-${day}`;
        }

        /**
         * Escape HTML special characters
         * @param {string} str - Input string
         * @returns {string} Escaped string
         */
        escapeHtml(str) {
            if (!str) return '';
            const div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        }
    }

    // Initialize on document ready
    $(function() {
        $('.snab-client-portal').each(function() {
            // Only initialize if user is logged in (has portal content)
            if ($(this).find('.snab-portal-content').length) {
                new SNABClientPortal($(this));
            }
        });
    });

})(jQuery);
