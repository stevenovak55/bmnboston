/**
 * MLD Referral Signup Page JavaScript
 *
 * Handles the registration form submission via AJAX.
 *
 * @package MLS_Listings_Display
 * @since 6.52.0
 */

(function($) {
    'use strict';

    var config = window.mldReferralConfig || {};

    /**
     * Initialize signup form handler
     */
    function init() {
        var $form = $('#mld-signup-form');
        var $submitBtn = $('#signup-submit');
        var $error = $('#signup-error');
        var $submitText = $submitBtn.find('.mld-signup-form__submit-text');
        var $submitLoading = $submitBtn.find('.mld-signup-form__submit-loading');

        if (!$form.length) {
            return;
        }

        // Form submission handler
        $form.on('submit', function(e) {
            e.preventDefault();

            // Clear previous errors
            $error.hide().text('');

            // Validate passwords match
            var password = $('#signup-password').val();
            var passwordConfirm = $('#signup-password-confirm').val();

            if (password !== passwordConfirm) {
                showError(config.strings?.passwordMismatch || 'Passwords do not match.');
                return;
            }

            if (password.length < 6) {
                showError(config.strings?.passwordShort || 'Password must be at least 6 characters.');
                return;
            }

            // Show loading state
            $submitBtn.prop('disabled', true);
            $submitText.hide();
            $submitLoading.show();

            // Bot protection: Check honeypot (should be empty)
            var honeypotValue = $form.find('input[name="mld_signup_website"]').val();
            if (honeypotValue && honeypotValue.length > 0) {
                // Bot detected - show fake success and do nothing
                $submitLoading.html('<svg class="mld-spinner" viewBox="0 0 24 24" style="color: #059669;"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" fill="none"></circle></svg> ' + (config.strings?.success || 'Account created! Redirecting...'));
                setTimeout(function() {
                    // Just reload page - no actual registration
                    window.location.reload();
                }, 2000);
                return;
            }

            // Collect form data
            var formData = {
                action: 'mld_referral_register',
                nonce: config.nonce,
                email: $('#signup-email').val(),
                password: password,
                first_name: $('#signup-first-name').val(),
                last_name: $('#signup-last-name').val(),
                phone: $('#signup-phone').val(),
                referral_code: $form.find('input[name="referral_code"]').val(),
                mld_signup_website: honeypotValue,
                mld_form_ts: $form.find('input[name="mld_form_ts"]').val()
            };

            // Submit registration
            $.ajax({
                url: config.ajaxUrl,
                type: 'POST',
                data: formData,
                success: function(response) {
                    if (response.success) {
                        // Show success message
                        $submitLoading.html('<svg class="mld-spinner" viewBox="0 0 24 24" style="color: #059669;"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" fill="none"></circle></svg> ' + (config.strings?.success || 'Account created! Redirecting...'));

                        // Redirect to dashboard
                        setTimeout(function() {
                            window.location.href = response.data.redirect || config.dashboardUrl;
                        }, 1000);
                    } else {
                        // Show error
                        showError(response.data?.message || config.strings?.error || 'Registration failed.');
                        resetButton();

                        // If email exists, show login link
                        if (response.data?.code === 'email_exists') {
                            $error.append('<br><a href="' + config.loginUrl + '" style="color: #1a56db;">Click here to sign in instead</a>');
                        }
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Registration error:', error);
                    showError(config.strings?.error || 'An error occurred. Please try again.');
                    resetButton();
                }
            });
        });

        /**
         * Show error message
         */
        function showError(message) {
            $error.text(message).show();
            // Scroll to error
            $('html, body').animate({
                scrollTop: $error.offset().top - 100
            }, 300);
        }

        /**
         * Reset button to normal state
         */
        function resetButton() {
            $submitBtn.prop('disabled', false);
            $submitText.show();
            $submitLoading.hide();
        }

        // Real-time password match validation
        $('#signup-password-confirm').on('input', function() {
            var password = $('#signup-password').val();
            var confirm = $(this).val();

            if (confirm.length > 0 && password !== confirm) {
                $(this).css('border-color', '#dc2626');
            } else {
                $(this).css('border-color', '');
            }
        });

        // Email format validation
        $('#signup-email').on('blur', function() {
            var email = $(this).val();
            var emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

            if (email.length > 0 && !emailRegex.test(email)) {
                $(this).css('border-color', '#dc2626');
            } else {
                $(this).css('border-color', '');
            }
        });
    }

    // Initialize when DOM is ready
    $(document).ready(init);

})(jQuery);
