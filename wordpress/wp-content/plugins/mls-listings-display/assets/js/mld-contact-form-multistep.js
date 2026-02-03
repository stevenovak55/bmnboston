/**
 * MLD Contact Form Multi-Step Handler
 *
 * Handles step navigation, progress updates, and per-step validation
 * for multi-step wizard forms.
 *
 * @package MLS_Listings_Display
 * @since 6.23.0
 */

(function($) {
    'use strict';

    /**
     * MultistepForm class
     * Manages multi-step form wizard functionality
     */
    class MLDMultistepForm {
        constructor($form) {
            this.$form = $form;
            this.formId = $form.data('form-id');
            this.config = this.parseConfig();

            if (!this.config || !this.config.enabled) {
                return;
            }

            this.currentStep = 1;
            this.totalSteps = this.config.totalSteps || 1;
            this.$steps = this.$form.find('.mld-cf-step');
            this.$progress = this.$form.find('.mld-cf-progress');

            this.init();
        }

        /**
         * Parse multi-step configuration from data attribute
         */
        parseConfig() {
            try {
                const configStr = this.$form.attr('data-multistep');
                if (!configStr) return null;
                return JSON.parse(configStr);
            } catch (e) {
                return null;
            }
        }

        /**
         * Initialize multi-step form
         */
        init() {
            this.bindEvents();
            this.showStep(1);
            this.updateProgress(1);
        }

        /**
         * Bind navigation events
         */
        bindEvents() {
            const self = this;

            // Next button click
            this.$form.on('click', '.mld-cf-next-step', function(e) {
                e.preventDefault();
                self.goToNextStep();
            });

            // Previous button click
            this.$form.on('click', '.mld-cf-prev-step', function(e) {
                e.preventDefault();
                self.goToPrevStep();
            });

            // Progress step click (for completed steps)
            this.$progress.on('click', '.mld-cf-step-indicator.completed', function() {
                const targetStep = parseInt($(this).data('step'), 10);
                if (targetStep && targetStep < self.currentStep) {
                    self.goToStep(targetStep);
                }
            });

            // Keyboard navigation (Enter key on inputs)
            this.$form.on('keypress', 'input:not([type="submit"])', function(e) {
                if (e.which === 13) {
                    e.preventDefault();
                    if (self.currentStep < self.totalSteps) {
                        self.goToNextStep();
                    }
                }
            });
        }

        /**
         * Go to next step
         */
        goToNextStep() {
            if (this.currentStep >= this.totalSteps) return;

            // Validate current step before proceeding
            if (!this.validateCurrentStep()) {
                this.shakeStep();
                return;
            }

            this.goToStep(this.currentStep + 1);
        }

        /**
         * Go to previous step
         */
        goToPrevStep() {
            if (this.currentStep <= 1) return;
            this.goToStep(this.currentStep - 1);
        }

        /**
         * Go to specific step
         */
        goToStep(stepNum) {
            if (stepNum < 1 || stepNum > this.totalSteps) return;
            if (stepNum === this.currentStep) return;

            const direction = stepNum > this.currentStep ? 'forward' : 'backward';

            // Hide current step
            this.hideStep(this.currentStep, direction);

            // Update current step
            this.currentStep = stepNum;

            // Show new step
            this.showStep(stepNum, direction);

            // Update progress
            this.updateProgress(stepNum);

            // Update hidden input
            this.$form.find('input[name="mld_current_step"]').val(stepNum);

            // Scroll to form top
            this.scrollToForm();

            // Trigger custom event
            this.$form.trigger('mld:step:changed', [stepNum, this.totalSteps]);
        }

        /**
         * Show a step
         */
        showStep(stepNum, direction = 'forward') {
            const $step = this.$steps.filter(`[data-step="${stepNum}"]`);

            // Remove all animation classes first
            $step.removeClass('slide-out-left slide-out-right slide-in-left slide-in-right');

            // Add entering animation
            const animClass = direction === 'forward' ? 'slide-in-right' : 'slide-in-left';
            $step.addClass('active ' + animClass);

            // Focus first input in step
            setTimeout(() => {
                $step.find('input:not([type="hidden"]), select, textarea').first().focus();
            }, 100);
        }

        /**
         * Hide a step
         */
        hideStep(stepNum, direction = 'forward') {
            const $step = this.$steps.filter(`[data-step="${stepNum}"]`);

            // Add leaving animation
            const animClass = direction === 'forward' ? 'slide-out-left' : 'slide-out-right';

            $step.addClass(animClass);

            // After animation, remove active
            setTimeout(() => {
                $step.removeClass('active slide-out-left slide-out-right slide-in-left slide-in-right');
            }, 300);
        }

        /**
         * Update progress indicator
         */
        updateProgress(currentStep) {
            const $indicators = this.$progress.find('.mld-cf-step-indicator');
            const $connectors = this.$progress.find('.mld-cf-step-connector');

            // Update step indicators
            $indicators.each(function() {
                const $indicator = $(this);
                const stepNum = parseInt($indicator.data('step'), 10);

                $indicator.removeClass('completed current upcoming');

                if (stepNum < currentStep) {
                    $indicator.addClass('completed');
                } else if (stepNum === currentStep) {
                    $indicator.addClass('current');
                } else {
                    $indicator.addClass('upcoming');
                }
            });

            // Update connectors
            $connectors.each(function(index) {
                const $connector = $(this);
                if (index + 1 < currentStep) {
                    $connector.addClass('completed');
                } else {
                    $connector.removeClass('completed');
                }
            });

            // Update progress bar (if bar type)
            const $progressBar = this.$progress.find('.mld-cf-progress-bar');
            if ($progressBar.length) {
                const progressPercent = ((currentStep - 1) / (this.totalSteps - 1)) * 100;
                $progressBar.css('width', progressPercent + '%');

                // Update text
                const $progressText = this.$progress.find('.mld-cf-progress-text');
                if ($progressText.length && this.config.steps && this.config.steps[currentStep - 1]) {
                    let text = `Step ${currentStep} of ${this.totalSteps}`;
                    if (this.config.showStepTitles && this.config.steps[currentStep - 1].title) {
                        text += ': <strong>' + this.escapeHtml(this.config.steps[currentStep - 1].title) + '</strong>';
                    }
                    $progressText.html(text);
                }
            }
        }

        /**
         * Validate current step fields
         */
        validateCurrentStep() {
            const $currentStep = this.$steps.filter(`[data-step="${this.currentStep}"]`);
            const $fields = $currentStep.find('.mld-cf-field');
            let isValid = true;

            // Clear previous errors
            $fields.find('.mld-cf-field-error').empty();
            $fields.find('input, select, textarea').removeClass('error');

            $fields.each(function() {
                const $field = $(this);

                // Skip hidden conditional fields
                if ($field.hasClass('mld-cf-hidden')) return;

                // Skip section and paragraph fields
                if ($field.hasClass('mld-cf-section') || $field.hasClass('mld-cf-paragraph')) return;

                const $inputs = $field.find('input:not([type="hidden"]), select, textarea');
                const $errorContainer = $field.find('.mld-cf-field-error');

                $inputs.each(function() {
                    const $input = $(this);

                    // Skip disabled inputs (conditionally hidden)
                    if ($input.prop('disabled')) return;

                    // Check required
                    if ($input.prop('required')) {
                        let value = $input.val();

                        // Handle checkboxes
                        if ($input.attr('type') === 'checkbox') {
                            const $checkboxGroup = $field.find('input[type="checkbox"]');
                            value = $checkboxGroup.filter(':checked').length > 0 ? 'checked' : '';
                        }

                        // Handle radio buttons
                        if ($input.attr('type') === 'radio') {
                            const name = $input.attr('name');
                            value = $field.find(`input[name="${name}"]:checked`).val() || '';
                        }

                        if (!value || (Array.isArray(value) && value.length === 0)) {
                            isValid = false;
                            $input.addClass('error');
                            const label = $field.find('label').first().text().replace('*', '').trim();
                            $errorContainer.text(label + ' is required.');
                            return false; // Break inner loop
                        }
                    }

                    // Check email format
                    if ($input.attr('type') === 'email' && $input.val()) {
                        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                        if (!emailRegex.test($input.val())) {
                            isValid = false;
                            $input.addClass('error');
                            $errorContainer.text('Please enter a valid email address.');
                            return false;
                        }
                    }

                    // Check URL format
                    if ($input.attr('type') === 'url' && $input.val()) {
                        try {
                            new URL($input.val());
                        } catch (e) {
                            isValid = false;
                            $input.addClass('error');
                            $errorContainer.text('Please enter a valid URL.');
                            return false;
                        }
                    }
                });
            });

            return isValid;
        }

        /**
         * Shake current step on validation error
         */
        shakeStep() {
            const $currentStep = this.$steps.filter(`[data-step="${this.currentStep}"]`);
            $currentStep.addClass('shake');
            setTimeout(() => {
                $currentStep.removeClass('shake');
            }, 500);

            // Focus first error field
            $currentStep.find('.error').first().focus();
        }

        /**
         * Scroll to form top
         */
        scrollToForm() {
            const formTop = this.$form.offset().top - 50;
            $('html, body').animate({ scrollTop: formTop }, 300);
        }

        /**
         * Escape HTML entities
         */
        escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    }

    /**
     * Initialize multi-step forms
     */
    function initMultistepForms() {
        $('form[data-multistep]').each(function() {
            const $form = $(this);

            // Skip if already initialized
            if ($form.data('multistep-init')) {
                return;
            }

            new MLDMultistepForm($form);
            $form.data('multistep-init', true);
        });
    }

    // Initialize on DOM ready
    $(document).ready(function() {
        initMultistepForms();
    });

    // Re-initialize on AJAX content load
    $(document).on('mld:form:loaded', function() {
        initMultistepForms();
    });

    // Expose for external use
    window.MLDMultistepForm = MLDMultistepForm;

})(jQuery);
