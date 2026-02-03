/**
 * MLD Contact Form Handler
 *
 * Handles form submission, validation, and UI feedback for contact forms.
 *
 * @package MLS_Listings_Display
 * @since 6.21.0
 */

(function($) {
    'use strict';

    /**
     * Contact Form Handler Class
     */
    class MLDContactFormHandler {
        constructor(form) {
            this.$form = $(form);
            this.$submit = this.$form.find('.mld-cf-submit');
            this.$status = this.$form.find('.mld-cf-status');
            this.formId = this.$form.data('form-id');
            this.isSubmitting = false;

            this.init();
        }

        /**
         * Initialize form handler
         */
        init() {
            this.bindEvents();
            this.setupValidation();
        }

        /**
         * Bind form events
         */
        bindEvents() {
            // Form submission
            this.$form.on('submit', (e) => this.handleSubmit(e));

            // Real-time validation on blur
            this.$form.find('input, select, textarea').on('blur', (e) => {
                this.validateField($(e.target));
            });

            // Clear error on input
            this.$form.find('input, select, textarea').on('input change', (e) => {
                const $field = $(e.target).closest('.mld-cf-field');
                if ($field.hasClass('has-error')) {
                    $field.removeClass('has-error');
                    $field.find('.mld-cf-field-error').text('');
                }
            });
        }

        /**
         * Setup validation patterns
         */
        setupValidation() {
            this.patterns = {
                email: /^[^\s@]+@[^\s@]+\.[^\s@]+$/,
                phone: /^[\d\s\-\+\(\)\.]{7,20}$/
            };
        }

        /**
         * Handle form submission
         */
        handleSubmit(e) {
            e.preventDefault();

            if (this.isSubmitting) {
                return;
            }

            // Clear previous status
            this.hideStatus();

            // Validate all fields
            if (!this.validateForm()) {
                // Scroll to first error
                const $firstError = this.$form.find('.mld-cf-field.has-error').first();
                if ($firstError.length) {
                    $('html, body').animate({
                        scrollTop: $firstError.offset().top - 100
                    }, 300);
                    $firstError.find('input, select, textarea').focus();
                }
                return;
            }

            // Check honeypot
            const honeypot = this.$form.find('input[name="mld_cf_hp"]').val();
            if (honeypot) {
                // Silently fail for bots
                this.showStatus('success', this.getString('success'));
                this.$form[0].reset();
                return;
            }

            // Submit form
            this.submitForm();
        }

        /**
         * Validate entire form
         */
        validateForm() {
            let isValid = true;

            this.$form.find('.mld-cf-field').each((index, field) => {
                const $field = $(field);
                const $input = $field.find('input, select, textarea').not('[type="hidden"]');

                if ($input.length) {
                    if (!this.validateField($input)) {
                        isValid = false;
                    }
                }
            });

            // Validate checkbox groups with required data attribute
            this.$form.find('.mld-cf-checkbox-group[data-required="true"]').each((index, group) => {
                const $group = $(group);
                const $field = $group.closest('.mld-cf-field');
                const $checked = $group.find('input:checked');

                if ($checked.length === 0) {
                    $field.addClass('has-error');
                    $field.find('.mld-cf-field-error').text(this.getString('required'));
                    isValid = false;
                }
            });

            return isValid;
        }

        /**
         * Validate single field
         */
        validateField($input) {
            const $field = $input.closest('.mld-cf-field');
            const $error = $field.find('.mld-cf-field-error');
            const value = $input.val().trim();
            const type = $input.attr('type') || $input.prop('tagName').toLowerCase();
            const isRequired = $input.prop('required');
            const minLength = parseInt($input.attr('minlength')) || 0;
            const maxLength = parseInt($input.attr('maxlength')) || 0;

            // Clear previous error
            $field.removeClass('has-error');
            $error.text('');

            // Required check
            if (isRequired && !value) {
                $field.addClass('has-error');
                $error.text(this.getString('required'));
                return false;
            }

            // Skip further validation if empty and not required
            if (!value) {
                return true;
            }

            // Type-specific validation
            if (type === 'email' && !this.patterns.email.test(value)) {
                $field.addClass('has-error');
                $error.text(this.getString('invalidEmail'));
                return false;
            }

            if (type === 'tel' && !this.patterns.phone.test(value)) {
                $field.addClass('has-error');
                $error.text(this.getString('invalidPhone'));
                return false;
            }

            // Length validation
            if (minLength > 0 && value.length < minLength) {
                $field.addClass('has-error');
                $error.text(this.getString('minLength').replace('%d', minLength));
                return false;
            }

            if (maxLength > 0 && value.length > maxLength) {
                $field.addClass('has-error');
                $error.text(this.getString('maxLength').replace('%d', maxLength));
                return false;
            }

            // Pattern validation
            const pattern = $input.attr('pattern');
            if (pattern) {
                const regex = new RegExp('^' + pattern + '$');
                if (!regex.test(value)) {
                    $field.addClass('has-error');
                    $error.text(this.getString('required'));
                    return false;
                }
            }

            return true;
        }

        /**
         * Submit form via AJAX
         */
        submitForm() {
            this.isSubmitting = true;
            this.setSubmitting(true);

            // Collect form data
            const formData = new FormData(this.$form[0]);
            formData.append('action', 'mld_submit_contact_form');
            formData.append('security', mldContactForm.nonce);

            // Submit via fetch
            fetch(mldContactForm.ajaxUrl, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            })
            .then(response => response.json())
            .then(data => {
                this.isSubmitting = false;
                this.setSubmitting(false);

                if (data.success) {
                    this.showStatus('success', data.data.message || this.getString('success'));

                    // Analytics: Track contact form submit (v6.38.0)
                    document.dispatchEvent(new CustomEvent('mld:contact_form_submit', {
                        detail: {
                            listingId: window.mldPropertyDataV3?.listing_id || null,
                            formType: this.$form.data('form-type') || 'contact'
                        }
                    }));

                    // Reset form
                    this.$form[0].reset();

                    // Handle redirect if specified
                    if (data.data.redirect) {
                        setTimeout(() => {
                            window.location.href = data.data.redirect;
                        }, 1500);
                    }

                    // Trigger custom event
                    this.$form.trigger('mld:contact-form:success', [data.data]);
                } else {
                    this.showStatus('error', data.data.message || this.getString('error'));

                    // Show field-specific errors if provided
                    if (data.data.errors) {
                        this.showFieldErrors(data.data.errors);
                    }

                    // Trigger custom event
                    this.$form.trigger('mld:contact-form:error', [data.data]);
                }
            })
            .catch(error => {
                console.error('Contact form submission error:', error);
                this.isSubmitting = false;
                this.setSubmitting(false);
                this.showStatus('error', this.getString('error'));
            });
        }

        /**
         * Set submitting state
         */
        setSubmitting(submitting) {
            if (submitting) {
                this.$submit.addClass('submitting').prop('disabled', true);

                // Add spinner if not present
                if (!this.$submit.find('.mld-cf-spinner').length) {
                    this.$submit.prepend('<span class="mld-cf-spinner"></span>');
                }

                // Update button text
                this.$submit.data('original-text', this.$submit.text());
                this.$submit.find('.mld-cf-spinner').after(this.getString('submitting'));
            } else {
                this.$submit.removeClass('submitting').prop('disabled', false);
                this.$submit.find('.mld-cf-spinner').remove();

                // Restore button text
                const originalText = this.$submit.data('original-text');
                if (originalText) {
                    this.$submit.text(originalText);
                }
            }
        }

        /**
         * Show status message
         */
        showStatus(type, message) {
            this.$status
                .removeClass('success error visible')
                .addClass(type)
                .html(message);

            // Trigger reflow for animation
            this.$status[0].offsetHeight;

            this.$status.addClass('visible');

            // Auto-hide success after 5 seconds
            if (type === 'success') {
                setTimeout(() => {
                    this.hideStatus();
                }, 5000);
            }
        }

        /**
         * Hide status message
         */
        hideStatus() {
            this.$status.removeClass('visible');
            setTimeout(() => {
                this.$status.removeClass('success error').html('');
            }, 300);
        }

        /**
         * Show field-specific errors from server
         */
        showFieldErrors(errors) {
            Object.keys(errors).forEach(fieldId => {
                const $field = this.$form.find('[data-field-id="' + fieldId + '"]');
                if ($field.length) {
                    $field.addClass('has-error');
                    $field.find('.mld-cf-field-error').text(errors[fieldId]);
                }
            });

            // Scroll to first error
            const $firstError = this.$form.find('.mld-cf-field.has-error').first();
            if ($firstError.length) {
                $('html, body').animate({
                    scrollTop: $firstError.offset().top - 100
                }, 300);
            }
        }

        /**
         * Get localized string
         */
        getString(key) {
            return (mldContactForm && mldContactForm.strings && mldContactForm.strings[key])
                ? mldContactForm.strings[key]
                : key;
        }
    }

    /**
     * Initialize on DOM ready
     */
    $(document).ready(function() {
        // Initialize all contact forms on the page
        $('.mld-contact-form').each(function() {
            new MLDContactFormHandler(this);
        });
    });

    /**
     * Re-initialize for dynamically loaded forms (e.g., in modals)
     */
    $(document).on('mld:contact-form:init', '.mld-contact-form', function() {
        if (!$(this).data('mld-cf-initialized')) {
            new MLDContactFormHandler(this);
            $(this).data('mld-cf-initialized', true);
        }
    });

})(jQuery);
