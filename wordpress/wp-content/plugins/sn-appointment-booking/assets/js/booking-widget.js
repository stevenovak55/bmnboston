/**
 * SNAB Booking Widget
 *
 * Frontend JavaScript for the appointment booking widget.
 *
 * @package SN_Appointment_Booking
 * @since 1.0.0
 */

(function($) {
    'use strict';

    /**
     * Booking Widget Class
     */
    class SNABBookingWidget {
        constructor(container) {
            this.$container = $(container);
            this.widgetId = this.$container.attr('id');
            this.startDate = this.$container.data('start-date');
            this.endDate = this.$container.data('end-date');
            this.weeks = parseInt(this.$container.data('weeks'), 10) || 2;

            // Filter settings (from preset or shortcode attributes)
            this.allowedDays = this.$container.data('allowed-days') || '';
            this.startHour = this.$container.data('start-hour');
            this.endHour = this.$container.data('end-hour');
            this.defaultLocation = this.$container.data('default-location') || '';

            // Staff selection settings
            this.staffSelectionMode = this.$container.data('staff-selection') || 'disabled';
            this.preselectedStaffId = this.$container.data('preselected-staff') || null;

            // State
            this.currentStep = 1;
            this.lastVisitedStep = 1;
            this.selectedType = null;
            this.selectedStaff = null;
            this.selectedDate = null;
            this.selectedTime = null;
            this.availabilityData = {};
            this.currentWeekStart = new Date(this.startDate);

            // Cache DOM elements
            this.$steps = this.$container.find('.snab-step');
            this.$form = this.$container.find('.snab-booking-form');

            this.init();
        }

        init() {
            this.bindEvents();

            // Set default location if provided
            if (this.defaultLocation) {
                this.$form.find('[name="property_address"]').val(this.defaultLocation);
            }
        }

        bindEvents() {
            const self = this;

            // Type selection
            this.$container.on('click', '.snab-type-option', function() {
                self.selectType($(this));
            });

            // Staff selection
            this.$container.on('click', '.snab-staff-option', function() {
                self.selectStaff($(this));
            });

            // Skip staff selection
            this.$container.on('click', '.snab-skip-staff-btn', function() {
                self.skipStaffSelection();
            });

            // Back buttons
            this.$container.on('click', '.snab-back-btn', function() {
                const backTo = parseInt($(this).data('back'), 10);
                self.goToStep(backTo);
            });

            // Calendar navigation
            this.$container.on('click', '.snab-prev-week', function() {
                if (!$(this).prop('disabled')) {
                    self.navigateWeek(-1);
                }
            });

            this.$container.on('click', '.snab-next-week', function() {
                if (!$(this).prop('disabled')) {
                    self.navigateWeek(1);
                }
            });

            // Date selection
            this.$container.on('click', '.snab-calendar-day.available', function() {
                self.selectDate($(this).data('date'));
            });

            // Time selection
            this.$container.on('click', '.snab-time-slot', function() {
                self.selectTime($(this).data('time'));
            });

            // Form submission
            this.$form.on('submit', function(e) {
                e.preventDefault();
                self.submitBooking();
            });

            // Book another
            this.$container.on('click', '.snab-book-another', function() {
                self.reset();
            });
        }

        /**
         * Go to a specific step
         */
        goToStep(step) {
            this.currentStep = step;
            this.$steps.removeClass('active');
            // Handle decimal steps like 1.5
            this.$container.find('[data-step="' + step + '"]').addClass('active');

            // Update step 2 back button based on whether we showed staff selection
            if (step === 2) {
                const $step2BackBtn = this.$container.find('[data-step="2"] .snab-back-btn');
                // If staff step was actually shown, back goes to 1.5; otherwise back to 1
                const shouldGoBackToStaff = this.staffSelectionMode !== 'disabled' &&
                                            this.$container.find('[data-step="1.5"]').length > 0 &&
                                            this.lastVisitedStep === 1.5;
                $step2BackBtn.data('back', shouldGoBackToStaff ? 1.5 : 1);
            }

            // Track the last visited step for back button logic
            this.lastVisitedStep = step;

            // Scroll to top of widget
            $('html, body').animate({
                scrollTop: this.$container.offset().top - 50
            }, 300);
        }

        /**
         * Select appointment type
         */
        selectType(typeBtn) {
            // Update UI
            this.$container.find('.snab-type-option').removeClass('selected');
            typeBtn.addClass('selected');

            // Store selection
            this.selectedType = {
                id: typeBtn.data('type-id'),
                slug: typeBtn.data('type-slug'),
                duration: typeBtn.data('duration'),
                name: typeBtn.find('.snab-type-name').text(),
                color: typeBtn.find('.snab-type-color').css('background-color')
            };

            // Update display for selected type (used in multiple places including staff step)
            const typeBadgeHtml = '<span class="snab-type-badge" style="background-color: ' + this.selectedType.color + '">' +
                this.selectedType.name + ' (' + this.selectedType.duration + ' ' +
                (this.selectedType.duration === 1 ? snabBooking.i18n.minute : snabBooking.i18n.minutes) +
                ')</span>';

            this.$container.find('.snab-selected-type').html(typeBadgeHtml);

            // Show property address field for property-related types
            if (this.selectedType.slug.includes('showing') || this.selectedType.slug.includes('valuation')) {
                this.$container.find('.snab-property-field').show();
            } else {
                this.$container.find('.snab-property-field').hide();
            }

            // Check if staff is pre-selected via URL parameter
            if (this.preselectedStaffId) {
                // Auto-select the pre-selected staff and skip the staff selection step
                const preselectedStaffId = parseInt(this.preselectedStaffId, 10);
                const $staffBtn = this.$container.find('.snab-staff-option[data-staff-id="' + preselectedStaffId + '"]');

                if ($staffBtn.length > 0) {
                    // Set the selected staff
                    this.selectedStaff = {
                        id: preselectedStaffId,
                        name: $staffBtn.find('.snab-staff-name').text()
                    };
                    // Update hidden form field
                    this.$form.find('[name="staff_id"]').val(preselectedStaffId);
                } else {
                    // Staff not found in the list, but still use the ID
                    this.selectedStaff = {
                        id: preselectedStaffId,
                        name: ''
                    };
                    this.$form.find('[name="staff_id"]').val(preselectedStaffId);
                }

                // Skip staff selection, go directly to calendar
                this.loadAvailability();
                this.goToStep(2);
            } else if (this.staffSelectionMode !== 'disabled') {
                // Check if staff selection is enabled
                const shouldShowStaffStep = this.prepareStaffSelection();
                if (shouldShowStaffStep) {
                    this.goToStep(1.5);
                } else {
                    // No staff options to show, skip to calendar
                    this.loadAvailability();
                    this.goToStep(2);
                }
            } else {
                // Skip staff selection, go directly to calendar
                this.loadAvailability();
                this.goToStep(2);
            }
        }

        /**
         * Prepare staff selection step
         * Filters staff options based on selected appointment type
         *
         * @returns {boolean} True if staff step should be shown, false to skip
         */
        prepareStaffSelection() {
            const self = this;
            const $staffList = this.$container.find('.snab-staff-list');
            const $staffOptions = $staffList.find('.snab-staff-option');
            const selectedTypeId = this.selectedType.id;

            // Reset staff selection
            this.selectedStaff = null;
            $staffOptions.removeClass('selected');

            // Filter staff options based on appointment type
            let visibleCount = 0;
            $staffOptions.each(function() {
                const $option = $(this);
                const staffServices = $option.data('staff-services');

                // "Any Available" option should always be visible
                if ($option.hasClass('snab-any-staff')) {
                    $option.show();
                    visibleCount++;
                    return;
                }

                // Check if staff can handle this appointment type
                if (staffServices) {
                    const serviceIds = String(staffServices).split(',').map(function(id) {
                        return parseInt(id.trim(), 10);
                    });

                    if (serviceIds.includes(selectedTypeId)) {
                        $option.show();
                        visibleCount++;
                    } else {
                        $option.hide();
                    }
                } else {
                    // Staff with no specific services - hide them
                    $option.hide();
                }
            });

            // Return true to show staff step only if we have actual staff choices
            // (more than just "Any Available" option)
            return visibleCount > 1;
        }

        /**
         * Select a staff member
         */
        selectStaff(staffBtn) {
            // Update UI
            this.$container.find('.snab-staff-option').removeClass('selected');
            staffBtn.addClass('selected');

            const staffId = staffBtn.data('staff-id');
            const isAny = staffBtn.hasClass('snab-any-staff');

            // Store selection
            if (isAny || staffId === 0) {
                this.selectedStaff = null;
            } else {
                this.selectedStaff = {
                    id: staffId,
                    name: staffBtn.find('.snab-staff-name').text()
                };
            }

            // Update hidden form field
            this.$form.find('[name="staff_id"]').val(this.selectedStaff ? this.selectedStaff.id : '');

            // Load availability and go to calendar
            this.loadAvailability();
            this.goToStep(2);
        }

        /**
         * Skip staff selection (for optional mode)
         */
        skipStaffSelection() {
            this.selectedStaff = null;
            this.$form.find('[name="staff_id"]').val('');
            this.loadAvailability();
            this.goToStep(2);
        }

        /**
         * Load availability data
         */
        loadAvailability() {
            const self = this;
            const $grid = this.$container.find('.snab-calendar-grid');

            $grid.html('<div class="snab-calendar-loading"><span class="spinner is-active"></span> ' +
                snabBooking.i18n.loading + '</div>');

            // Build request data with filters
            const requestData = {
                action: 'snab_get_availability',
                nonce: snabBooking.nonce,
                start_date: this.startDate,
                end_date: this.endDate,
                type_id: this.selectedType.id
            };

            // Add filter parameters if set
            if (this.allowedDays) {
                requestData.allowed_days = this.allowedDays;
            }
            if (this.startHour !== undefined && this.startHour !== '') {
                requestData.start_hour = this.startHour;
            }
            if (this.endHour !== undefined && this.endHour !== '') {
                requestData.end_hour = this.endHour;
            }

            $.ajax({
                url: snabBooking.ajaxUrl,
                type: 'POST',
                data: requestData,
                success: function(response) {
                    if (response.success) {
                        self.availabilityData = response.data.slots || {};
                        self.renderCalendar();
                    } else {
                        $grid.html('<div class="snab-error">' + (response.data || snabBooking.i18n.error) + '</div>');
                    }
                },
                error: function() {
                    $grid.html('<div class="snab-error">' + snabBooking.i18n.error + '</div>');
                }
            });
        }

        /**
         * Render the calendar grid
         */
        renderCalendar() {
            const $grid = this.$container.find('.snab-calendar-grid');
            const today = new Date();
            today.setHours(0, 0, 0, 0);

            // Calculate week boundaries
            const weekStart = new Date(this.currentWeekStart);
            weekStart.setHours(0, 0, 0, 0);

            // Build calendar HTML
            let html = '<div class="snab-calendar-week">';

            for (let i = 0; i < 7; i++) {
                const date = new Date(weekStart);
                date.setDate(date.getDate() + i);
                const dateStr = this.formatDateISO(date);

                const isToday = date.getTime() === today.getTime();
                const isPast = date < today;
                const hasSlots = this.availabilityData[dateStr] && this.availabilityData[dateStr].length > 0;
                const isSelected = this.selectedDate === dateStr;

                let classes = 'snab-calendar-day';
                if (isToday) classes += ' today';
                if (isPast) classes += ' past';
                if (hasSlots && !isPast) classes += ' available';
                if (isSelected) classes += ' selected';
                if (!hasSlots && !isPast) classes += ' unavailable';

                html += '<div class="' + classes + '" data-date="' + dateStr + '">';
                html += '<span class="snab-day-name">' + snabBooking.i18n.days[date.getDay()] + '</span>';
                html += '<span class="snab-day-number">' + date.getDate() + '</span>';
                if (hasSlots && !isPast) {
                    html += '<span class="snab-slots-count">' + this.availabilityData[dateStr].length + ' slots</span>';
                }
                html += '</div>';
            }

            html += '</div>';
            $grid.html(html);

            // Update navigation
            this.updateCalendarNavigation();
            this.updateCalendarTitle();
        }

        /**
         * Update calendar title
         */
        updateCalendarTitle() {
            const weekStart = new Date(this.currentWeekStart);
            const weekEnd = new Date(weekStart);
            weekEnd.setDate(weekEnd.getDate() + 6);

            const startMonth = snabBooking.i18n.months[weekStart.getMonth()];
            const endMonth = snabBooking.i18n.months[weekEnd.getMonth()];

            let title;
            if (weekStart.getMonth() === weekEnd.getMonth()) {
                title = startMonth + ' ' + weekStart.getDate() + ' - ' + weekEnd.getDate() + ', ' + weekStart.getFullYear();
            } else {
                title = startMonth + ' ' + weekStart.getDate() + ' - ' + endMonth + ' ' + weekEnd.getDate() + ', ' + weekEnd.getFullYear();
            }

            this.$container.find('.snab-calendar-title').text(title);
        }

        /**
         * Update calendar navigation buttons
         */
        updateCalendarNavigation() {
            const today = new Date();
            today.setHours(0, 0, 0, 0);

            const minDate = new Date(this.startDate);
            const maxDate = new Date(this.endDate);
            maxDate.setDate(maxDate.getDate() - 6);

            const weekStart = new Date(this.currentWeekStart);

            // Disable prev if at or before today
            this.$container.find('.snab-prev-week').prop('disabled', weekStart <= today);

            // Disable next if at or after end date
            this.$container.find('.snab-next-week').prop('disabled', weekStart >= maxDate);
        }

        /**
         * Navigate calendar by weeks
         */
        navigateWeek(direction) {
            this.currentWeekStart.setDate(this.currentWeekStart.getDate() + (direction * 7));
            this.renderCalendar();
        }

        /**
         * Select a date
         */
        selectDate(dateStr) {
            this.selectedDate = dateStr;

            // Update UI
            this.$container.find('.snab-calendar-day').removeClass('selected');
            this.$container.find('.snab-calendar-day[data-date="' + dateStr + '"]').addClass('selected');

            // Format date for display
            const date = new Date(dateStr + 'T00:00:00');
            const dayName = snabBooking.i18n.days[date.getDay()];
            const monthName = snabBooking.i18n.months[date.getMonth()];
            const formattedDate = dayName + ', ' + monthName + ' ' + date.getDate() + ', ' + date.getFullYear();

            this.$container.find('.snab-selected-date').html(
                '<span class="snab-type-badge" style="background-color: ' + this.selectedType.color + '">' +
                this.selectedType.name + '</span> ' +
                '<span class="snab-date-text">' + formattedDate + '</span>'
            );

            // Load time slots
            this.loadTimeSlots(dateStr);
            this.goToStep(3);
        }

        /**
         * Load time slots for a date
         */
        loadTimeSlots(dateStr) {
            const self = this;
            const $slotsContainer = this.$container.find('.snab-time-slots');

            // Check if we already have the slots
            if (this.availabilityData[dateStr]) {
                this.renderTimeSlots(this.availabilityData[dateStr]);
                return;
            }

            $slotsContainer.html('<div class="snab-slots-loading"><span class="spinner is-active"></span> ' +
                snabBooking.i18n.loading + '</div>');

            // Build request data with filters
            const requestData = {
                action: 'snab_get_time_slots',
                nonce: snabBooking.nonce,
                date: dateStr,
                type_id: this.selectedType.id
            };

            // Add filter parameters if set
            if (this.allowedDays) {
                requestData.allowed_days = this.allowedDays;
            }
            if (this.startHour !== undefined && this.startHour !== '') {
                requestData.start_hour = this.startHour;
            }
            if (this.endHour !== undefined && this.endHour !== '') {
                requestData.end_hour = this.endHour;
            }

            $.ajax({
                url: snabBooking.ajaxUrl,
                type: 'POST',
                data: requestData,
                success: function(response) {
                    if (response.success && response.data.slots) {
                        // Convert to simple time array
                        const times = response.data.slots.map(function(slot) {
                            return slot.value;
                        });
                        self.availabilityData[dateStr] = times;
                        self.renderTimeSlots(times);
                    } else {
                        $slotsContainer.html('<div class="snab-no-slots">' + snabBooking.i18n.noSlots + '</div>');
                    }
                },
                error: function() {
                    $slotsContainer.html('<div class="snab-error">' + snabBooking.i18n.error + '</div>');
                }
            });
        }

        /**
         * Render time slots
         */
        renderTimeSlots(slots) {
            const $container = this.$container.find('.snab-time-slots');

            if (!slots || slots.length === 0) {
                $container.html('<div class="snab-no-slots">' + snabBooking.i18n.noSlots + '</div>');
                return;
            }

            let html = '<div class="snab-slots-grid">';

            slots.forEach(function(time) {
                const formatted = this.formatTime(time);
                const isSelected = this.selectedTime === time;
                html += '<button type="button" class="snab-time-slot' + (isSelected ? ' selected' : '') + '" data-time="' + time + '">' +
                    formatted + '</button>';
            }, this);

            html += '</div>';
            $container.html(html);
        }

        /**
         * Select a time slot
         */
        selectTime(time) {
            this.selectedTime = time;

            // Update UI
            this.$container.find('.snab-time-slot').removeClass('selected');
            this.$container.find('.snab-time-slot[data-time="' + time + '"]').addClass('selected');

            // Update hidden form fields
            this.$form.find('[name="appointment_type_id"]').val(this.selectedType.id);
            this.$form.find('[name="appointment_date"]').val(this.selectedDate);
            this.$form.find('[name="appointment_time"]').val(time);

            // Build booking summary
            const date = new Date(this.selectedDate + 'T00:00:00');
            const dayName = snabBooking.i18n.days[date.getDay()];
            const monthName = snabBooking.i18n.months[date.getMonth()];
            const formattedDate = dayName + ', ' + monthName + ' ' + date.getDate() + ', ' + date.getFullYear();
            const formattedTime = this.formatTime(time);

            let summaryHtml = '<div class="snab-summary-item">';
            summaryHtml += '<span class="snab-type-badge" style="background-color: ' + this.selectedType.color + '">' +
                this.selectedType.name + '</span>';
            summaryHtml += '</div>';
            summaryHtml += '<div class="snab-summary-item"><strong>' + formattedDate + '</strong> at <strong>' + formattedTime + '</strong></div>';
            summaryHtml += '<div class="snab-summary-item snab-duration">' + this.selectedType.duration + ' ' +
                (this.selectedType.duration === 1 ? snabBooking.i18n.minute : snabBooking.i18n.minutes) + '</div>';

            this.$container.find('.snab-booking-summary').html(summaryHtml);

            // Go to info step
            this.goToStep(4);
        }

        /**
         * Submit booking
         */
        submitBooking() {
            const self = this;
            const $submitBtn = this.$form.find('.snab-submit-btn');
            const $errorDiv = this.$form.find('.snab-form-error');

            // Validate form
            const name = this.$form.find('[name="client_name"]').val().trim();
            const email = this.$form.find('[name="client_email"]').val().trim();

            if (!name) {
                $errorDiv.text(snabBooking.i18n.required).show();
                this.$form.find('[name="client_name"]').focus();
                return;
            }

            if (!email || !this.isValidEmail(email)) {
                $errorDiv.text(snabBooking.i18n.invalidEmail).show();
                this.$form.find('[name="client_email"]').focus();
                return;
            }

            $errorDiv.hide();
            $submitBtn.prop('disabled', true).text(snabBooking.i18n.loading);

            $.ajax({
                url: snabBooking.ajaxUrl,
                type: 'POST',
                data: this.$form.serialize() + '&action=snab_book_appointment',
                success: function(response) {
                    if (response.success) {
                        self.showConfirmation(response.data);
                    } else {
                        $errorDiv.text(response.data || snabBooking.i18n.bookingFailed).show();
                        $submitBtn.prop('disabled', false).text(snabBooking.i18n.confirmBooking || 'Confirm Booking');
                    }
                },
                error: function() {
                    $errorDiv.text(snabBooking.i18n.error).show();
                    $submitBtn.prop('disabled', false).text(snabBooking.i18n.confirmBooking || 'Confirm Booking');
                }
            });
        }

        /**
         * Show confirmation
         */
        showConfirmation(data) {
            let detailsHtml = '<div class="snab-confirmation-item">';
            detailsHtml += '<span class="snab-type-badge" style="background-color: ' + data.type_color + '">' +
                data.type_name + '</span>';
            detailsHtml += '</div>';
            detailsHtml += '<div class="snab-confirmation-item"><strong>' + data.date + '</strong> at <strong>' + data.time + '</strong></div>';
            detailsHtml += '<div class="snab-confirmation-item">' + data.client_name + ' &lt;' + data.client_email + '&gt;</div>';

            if (data.google_synced) {
                detailsHtml += '<div class="snab-confirmation-item snab-gcal-synced">' +
                    '<span class="dashicons dashicons-calendar-alt"></span> Added to Google Calendar</div>';
            }

            this.$container.find('.snab-confirmation-details').html(detailsHtml);
            this.goToStep(5);
        }

        /**
         * Reset the widget
         */
        reset() {
            this.currentStep = 1;
            this.lastVisitedStep = 1;
            this.selectedType = null;
            this.selectedStaff = null;
            this.selectedDate = null;
            this.selectedTime = null;
            this.currentWeekStart = new Date(this.startDate);

            // Clear form
            this.$form[0].reset();
            this.$form.find('.snab-form-error').hide();
            this.$form.find('.snab-submit-btn').prop('disabled', false).text('Confirm Booking');

            // Clear selections
            this.$container.find('.snab-type-option').removeClass('selected');
            this.$container.find('.snab-staff-option').removeClass('selected');
            this.$container.find('.snab-property-field').hide();

            this.goToStep(1);
        }

        /**
         * Format date to ISO string (Y-m-d)
         */
        formatDateISO(date) {
            const year = date.getFullYear();
            const month = String(date.getMonth() + 1).padStart(2, '0');
            const day = String(date.getDate()).padStart(2, '0');
            return year + '-' + month + '-' + day;
        }

        /**
         * Format time for display
         */
        formatTime(time) {
            const parts = time.split(':');
            let hours = parseInt(parts[0], 10);
            const minutes = parts[1];
            const ampm = hours >= 12 ? 'PM' : 'AM';
            hours = hours % 12 || 12;
            return hours + ':' + minutes + ' ' + ampm;
        }

        /**
         * Validate email
         */
        isValidEmail(email) {
            return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
        }
    }

    // Initialize all booking widgets on the page
    $(document).ready(function() {
        $('.snab-booking-widget').each(function() {
            new SNABBookingWidget(this);
        });
    });

})(jQuery);
