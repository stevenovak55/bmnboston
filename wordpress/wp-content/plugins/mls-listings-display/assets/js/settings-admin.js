/**
 * MLD Instant Notifications Settings - Admin JavaScript
 */

(function($) {
    'use strict';

    let mldSettings = {

        /**
         * Initialize the settings page
         */
        init: function() {
            this.bindEvents();
            this.initializeComponents();
        },

        /**
         * Bind event handlers
         */
        bindEvents: function() {
            // User settings management
            $(document).on('click', '.save-user-settings', this.saveUserSettings);
            $(document).on('click', '.reset-user-throttle', this.resetUserThrottle);

            // Throttle management
            $('#reset-all-throttles').on('click', this.resetAllThrottles);
            $('#export-throttle-data').on('click', this.exportThrottleData);

            // User search
            $('#user-search').on('input', this.filterUsers);
            $('#refresh-users').on('click', this.refreshUserTable);

            // Auto-save user settings on change
            $('.daily-limit-input, .quiet-start-input, .quiet-end-input').on('change', function() {
                const $row = $(this).closest('tr');
                $row.find('.save-user-settings').addClass('button-primary').text('Save*');
            });

            // Form validation
            $('form').on('submit', this.validateForm);

            // Real-time validation
            $('input[type="number"]').on('input', this.validateNumericInput);
            $('input[type="time"]').on('change', this.validateTimeInput);
            $('input[type="email"]').on('blur', this.validateEmailInput);
        },

        /**
         * Initialize components
         */
        initializeComponents: function() {
            // Initialize tooltips
            this.initTooltips();

            // Load Chart.js if available
            if (typeof Chart !== 'undefined') {
                this.initializeChart();
            } else {
                this.loadChartJS();
            }

            // Auto-refresh stats every 30 seconds
            setInterval(this.refreshStats, 30000);
        },

        /**
         * Save individual user settings
         */
        saveUserSettings: function(e) {
            e.preventDefault();

            const $button = $(this);
            const $row = $button.closest('tr');
            const userId = $button.data('user-id');

            const data = {
                action: 'mld_update_user_limits',
                nonce: mldSettingsAjax.nonce,
                user_id: userId,
                daily_limit: $row.find('.daily-limit-input').val(),
                quiet_start: $row.find('.quiet-start-input').val(),
                quiet_end: $row.find('.quiet-end-input').val()
            };

            $button.prop('disabled', true).text('Saving...');

            $.post(mldSettingsAjax.ajaxurl, data)
                .done(function(response) {
                    if (response.success) {
                        mldSettings.showMessage('User settings saved successfully!', 'success');
                        $button.removeClass('button-primary').text('Save');
                    } else {
                        mldSettings.showMessage('Error saving settings: ' + response.data, 'error');
                    }
                })
                .fail(function() {
                    mldSettings.showMessage('Network error occurred', 'error');
                })
                .always(function() {
                    $button.prop('disabled', false);
                });
        },

        /**
         * Reset user throttle data
         */
        resetUserThrottle: function(e) {
            e.preventDefault();

            const $button = $(this);
            const userId = $button.data('user-id');

            if (!confirm('Are you sure you want to reset throttle data for this user?')) {
                return;
            }

            const data = {
                action: 'mld_reset_throttle_data',
                nonce: mldSettingsAjax.nonce,
                user_id: userId
            };

            $button.prop('disabled', true).text('Resetting...');

            $.post(mldSettingsAjax.ajaxurl, data)
                .done(function(response) {
                    if (response.success) {
                        mldSettings.showMessage(response.data.message, 'success');
                        // Update the UI
                        const $row = $button.closest('tr');
                        $row.find('td:nth-child(4)').text('0'); // Sent today
                        $row.find('td:nth-child(5)').text('0'); // Throttled today
                    } else {
                        mldSettings.showMessage('Error resetting throttle: ' + response.data, 'error');
                    }
                })
                .fail(function() {
                    mldSettings.showMessage('Network error occurred', 'error');
                })
                .always(function() {
                    $button.prop('disabled', false).text('Reset Throttle');
                });
        },

        /**
         * Reset all throttle data
         */
        resetAllThrottles: function(e) {
            e.preventDefault();

            if (!confirm('Are you sure you want to reset ALL throttle data for today? This action cannot be undone.')) {
                return;
            }

            const $button = $(this);
            const data = {
                action: 'mld_reset_throttle_data',
                nonce: mldSettingsAjax.nonce
            };

            $button.prop('disabled', true).text('Resetting...');

            $.post(mldSettingsAjax.ajaxurl, data)
                .done(function(response) {
                    if (response.success) {
                        mldSettings.showMessage(response.data.message, 'success');
                        // Refresh the page to update all data
                        setTimeout(function() {
                            location.reload();
                        }, 1500);
                    } else {
                        mldSettings.showMessage('Error resetting throttles: ' + response.data, 'error');
                    }
                })
                .fail(function() {
                    mldSettings.showMessage('Network error occurred', 'error');
                })
                .always(function() {
                    $button.prop('disabled', false).text('Reset All Daily Throttles');
                });
        },

        /**
         * Export throttle data
         */
        exportThrottleData: function(e) {
            e.preventDefault();

            // Create downloadable CSV
            const csvContent = mldSettings.generateThrottleCSV();
            const blob = new Blob([csvContent], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);

            const a = document.createElement('a');
            a.href = url;
            a.download = 'mld-throttle-data-' + new Date().toISOString().split('T')[0] + '.csv';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            window.URL.revokeObjectURL(url);

            mldSettings.showMessage('Throttle data exported successfully!', 'success');
        },

        /**
         * Generate CSV content for throttle data
         */
        generateThrottleCSV: function() {
            let csv = 'Date,Total Notifications,Throttled Count,Throttle Rate,Peak Hour\n';

            $('.mld-settings-card:last table tbody tr').each(function() {
                const row = [];
                $(this).find('td').each(function() {
                    row.push('"' + $(this).text().trim().replace(/"/g, '""') + '"');
                });
                csv += row.join(',') + '\n';
            });

            return csv;
        },

        /**
         * Filter users in the table
         */
        filterUsers: function() {
            const searchTerm = $(this).val().toLowerCase();

            $('#user-notification-table tr').each(function() {
                const $row = $(this);
                const userName = $row.find('td:first strong').text().toLowerCase();
                const userEmail = $row.find('td:first small').text().toLowerCase();

                if (userName.includes(searchTerm) || userEmail.includes(searchTerm)) {
                    $row.show();
                } else {
                    $row.hide();
                }
            });
        },

        /**
         * Refresh user table
         */
        refreshUserTable: function(e) {
            e.preventDefault();

            const $button = $(this);
            $button.prop('disabled', true).text('Refreshing...');

            // Reload the page to refresh data
            setTimeout(function() {
                location.reload();
            }, 500);
        },

        /**
         * Refresh statistics
         */
        refreshStats: function() {
            // This would typically make an AJAX call to get updated stats
            // For now, we'll just update the timestamp
            const now = new Date();
            $('.mld-stats-grid').attr('data-last-updated', now.toLocaleTimeString());
        },

        /**
         * Form validation
         */
        validateForm: function(e) {
            let isValid = true;
            const $form = $(this);

            // Clear previous errors
            $('.mld-error').remove();

            // Validate required fields
            $form.find('input[required]').each(function() {
                if (!$(this).val().trim()) {
                    mldSettings.showFieldError($(this), 'This field is required');
                    isValid = false;
                }
            });

            // Validate numeric fields
            $form.find('input[type="number"]').each(function() {
                const value = parseInt($(this).val());
                const min = parseInt($(this).attr('min'));
                const max = parseInt($(this).attr('max'));

                if (isNaN(value)) {
                    mldSettings.showFieldError($(this), 'Please enter a valid number');
                    isValid = false;
                } else if (min && value < min) {
                    mldSettings.showFieldError($(this), `Value must be at least ${min}`);
                    isValid = false;
                } else if (max && value > max) {
                    mldSettings.showFieldError($(this), `Value must be no more than ${max}`);
                    isValid = false;
                }
            });

            // Validate time fields
            $form.find('input[type="time"]').each(function() {
                if (!mldSettings.isValidTime($(this).val())) {
                    mldSettings.showFieldError($(this), 'Please enter a valid time');
                    isValid = false;
                }
            });

            // Validate email fields
            $form.find('input[type="email"]').each(function() {
                if (!mldSettings.isValidEmail($(this).val())) {
                    mldSettings.showFieldError($(this), 'Please enter a valid email address');
                    isValid = false;
                }
            });

            if (!isValid) {
                e.preventDefault();
                mldSettings.showMessage('Please correct the errors below', 'error');
            }

            return isValid;
        },

        /**
         * Validate numeric input
         */
        validateNumericInput: function() {
            const $input = $(this);
            const value = parseInt($input.val());
            const min = parseInt($input.attr('min'));
            const max = parseInt($input.attr('max'));

            $input.removeClass('error');

            if (isNaN(value) || (min && value < min) || (max && value > max)) {
                $input.addClass('error');
            }
        },

        /**
         * Validate time input
         */
        validateTimeInput: function() {
            const $input = $(this);

            $input.removeClass('error');

            if (!mldSettings.isValidTime($input.val())) {
                $input.addClass('error');
            }
        },

        /**
         * Validate email input
         */
        validateEmailInput: function() {
            const $input = $(this);

            $input.removeClass('error');

            if ($input.val() && !mldSettings.isValidEmail($input.val())) {
                $input.addClass('error');
            }
        },

        /**
         * Check if time is valid
         */
        isValidTime: function(time) {
            return /^([0-1]?[0-9]|2[0-3]):[0-5][0-9]$/.test(time);
        },

        /**
         * Check if email is valid
         */
        isValidEmail: function(email) {
            return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
        },

        /**
         * Show field error
         */
        showFieldError: function($field, message) {
            $field.addClass('error');
            $field.after('<div class="mld-error" style="color: #dc3232; font-size: 12px; margin-top: 2px;">' + message + '</div>');
        },

        /**
         * Show message
         */
        showMessage: function(message, type) {
            const $message = $('<div class="mld-message ' + type + '">' + message + '</div>');
            $('.mld-settings-wrap h1').after($message);

            setTimeout(function() {
                $message.fadeOut(function() {
                    $(this).remove();
                });
            }, 5000);
        },

        /**
         * Initialize tooltips
         */
        initTooltips: function() {
            $('[data-tooltip]').addClass('mld-tooltip');
        },

        /**
         * Load Chart.js library
         */
        loadChartJS: function() {
            const script = document.createElement('script');
            script.src = 'https://cdn.jsdelivr.net/npm/chart.js';
            script.onload = function() {
                mldSettings.initializeChart();
            };
            document.head.appendChild(script);
        },

        /**
         * Initialize activity chart
         */
        initializeChart: function() {
            const ctx = document.getElementById('activityChart');
            if (!ctx) return;

            // Chart initialization is handled in the PHP template
            // This function can be used for additional chart customization
        }
    };

    // Initialize when document is ready
    $(document).ready(function() {
        mldSettings.init();
    });

})(jQuery);