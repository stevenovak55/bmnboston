/**
 * MLD Contact Form Conditional Logic Handler
 *
 * Handles show/hide logic for form fields based on conditional rules.
 * Evaluates rules on field change and updates visibility accordingly.
 *
 * @package MLS_Listings_Display
 * @since 6.22.0
 */

(function($) {
    'use strict';

    /**
     * ConditionalLogic class
     * Manages conditional field visibility for contact forms
     */
    class MLDConditionalLogic {
        constructor($form) {
            this.$form = $form;
            this.formId = $form.data('form-id');
            this.conditionalFields = {};
            this.fieldValues = {};

            this.init();
        }

        /**
         * Initialize conditional logic
         */
        init() {
            // Parse conditional data from fields
            this.parseConditionalFields();

            if (Object.keys(this.conditionalFields).length === 0) {
                return; // No conditional fields
            }

            // Get initial field values
            this.updateAllFieldValues();

            // Bind change events to source fields
            this.bindSourceFieldEvents();

            // Initial evaluation
            this.evaluateAllConditions();
        }

        /**
         * Parse conditional data attributes from form fields
         */
        parseConditionalFields() {
            const self = this;

            this.$form.find('[data-conditional]').each(function() {
                const $field = $(this);
                const fieldId = $field.data('field-id');

                try {
                    const conditional = JSON.parse($field.attr('data-conditional'));
                    if (conditional && conditional.enabled) {
                        self.conditionalFields[fieldId] = {
                            $element: $field,
                            config: conditional
                        };
                    }
                } catch (e) {
                    // Invalid JSON, skip this field
                }
            });
        }

        /**
         * Get all unique source field IDs from conditional rules
         */
        getSourceFieldIds() {
            const sourceIds = new Set();

            Object.values(this.conditionalFields).forEach(item => {
                if (item.config.rules) {
                    item.config.rules.forEach(rule => {
                        if (rule.field_id) {
                            sourceIds.add(rule.field_id);
                        }
                    });
                }
            });

            return Array.from(sourceIds);
        }

        /**
         * Bind change events to source fields
         */
        bindSourceFieldEvents() {
            const self = this;
            const sourceIds = this.getSourceFieldIds();

            sourceIds.forEach(fieldId => {
                const $fieldWrapper = this.$form.find(`[data-field-id="${fieldId}"]`);

                // Bind to inputs within the field wrapper
                $fieldWrapper.find('input, select, textarea').on('input change', function() {
                    self.onSourceFieldChange(fieldId);
                });
            });
        }

        /**
         * Handle source field change
         */
        onSourceFieldChange(fieldId) {
            // Update the value for this field
            this.updateFieldValue(fieldId);

            // Re-evaluate all conditions
            this.evaluateAllConditions();
        }

        /**
         * Update all field values
         */
        updateAllFieldValues() {
            const self = this;
            const sourceIds = this.getSourceFieldIds();

            sourceIds.forEach(fieldId => {
                self.updateFieldValue(fieldId);
            });
        }

        /**
         * Update value for a specific field
         */
        updateFieldValue(fieldId) {
            const $fieldWrapper = this.$form.find(`[data-field-id="${fieldId}"]`);
            const $inputs = $fieldWrapper.find('input, select, textarea');

            if ($inputs.length === 0) {
                this.fieldValues[fieldId] = '';
                return;
            }

            const $firstInput = $inputs.first();
            const inputType = $firstInput.attr('type');

            // Handle different input types
            if (inputType === 'checkbox') {
                // Multiple checkboxes - collect all checked values
                const values = [];
                $inputs.filter(':checked').each(function() {
                    values.push($(this).val());
                });
                this.fieldValues[fieldId] = values;
            } else if (inputType === 'radio') {
                // Radio buttons - get selected value
                this.fieldValues[fieldId] = $inputs.filter(':checked').val() || '';
            } else if ($firstInput.is('select')) {
                // Select dropdown
                this.fieldValues[fieldId] = $firstInput.val() || '';
            } else {
                // Text, email, number, etc.
                this.fieldValues[fieldId] = $firstInput.val() || '';
            }
        }

        /**
         * Evaluate all conditional fields
         */
        evaluateAllConditions() {
            const self = this;
            const visibilityMap = {};

            // First pass: evaluate all fields (respecting dependency order)
            const processed = new Set();
            let changed = true;
            let iterations = 0;
            const maxIterations = Object.keys(this.conditionalFields).length * 2;

            while (changed && iterations < maxIterations) {
                changed = false;
                iterations++;

                Object.keys(this.conditionalFields).forEach(fieldId => {
                    if (processed.has(fieldId)) return;

                    const item = this.conditionalFields[fieldId];
                    const dependencies = this.getFieldDependencies(item.config);

                    // Check if all dependencies have been processed
                    const allDepsProcessed = dependencies.every(depId => {
                        return processed.has(depId) || !this.conditionalFields[depId];
                    });

                    if (!allDepsProcessed) return;

                    // Evaluate this field
                    visibilityMap[fieldId] = this.evaluateFieldVisibility(item.config, visibilityMap);
                    processed.add(fieldId);
                    changed = true;
                });
            }

            // Apply visibility changes with animation
            Object.keys(visibilityMap).forEach(fieldId => {
                const item = this.conditionalFields[fieldId];
                if (item) {
                    this.setFieldVisibility(item.$element, visibilityMap[fieldId], fieldId);
                }
            });
        }

        /**
         * Get dependency field IDs for a conditional config
         */
        getFieldDependencies(config) {
            const deps = [];
            if (config.rules) {
                config.rules.forEach(rule => {
                    if (rule.field_id) {
                        deps.push(rule.field_id);
                    }
                });
            }
            return deps;
        }

        /**
         * Evaluate visibility for a field based on its conditional config
         */
        evaluateFieldVisibility(config, visibilityMap) {
            const rules = config.rules || [];
            const logic = config.logic || 'all';
            const action = config.action || 'show';

            if (rules.length === 0) {
                return true; // No rules, always visible
            }

            // Evaluate rules
            const results = rules.map(rule => this.evaluateRule(rule, visibilityMap));

            // Apply logic
            let conditionsMet;
            if (logic === 'any') {
                conditionsMet = results.some(r => r === true);
            } else {
                conditionsMet = results.every(r => r === true);
            }

            // Apply action
            return action === 'show' ? conditionsMet : !conditionsMet;
        }

        /**
         * Evaluate a single rule
         */
        evaluateRule(rule, visibilityMap) {
            const sourceFieldId = rule.field_id;
            const operator = rule.operator || 'equals';
            const compareValue = rule.value || '';

            // If source field is hidden by conditional logic, treat as empty
            const sourceVisible = visibilityMap[sourceFieldId] !== false;
            let fieldValue = sourceVisible ? this.fieldValues[sourceFieldId] : '';

            // Handle undefined/null
            if (fieldValue === undefined || fieldValue === null) {
                fieldValue = '';
            }

            // Handle array values (checkboxes)
            if (Array.isArray(fieldValue)) {
                return this.evaluateArrayRule(fieldValue, operator, compareValue);
            }

            // String comparison
            fieldValue = String(fieldValue);
            const compareStr = String(compareValue);

            switch (operator) {
                case 'equals':
                    return fieldValue.toLowerCase() === compareStr.toLowerCase();

                case 'not_equals':
                    return fieldValue.toLowerCase() !== compareStr.toLowerCase();

                case 'contains':
                    return fieldValue.toLowerCase().includes(compareStr.toLowerCase());

                case 'not_contains':
                    return !fieldValue.toLowerCase().includes(compareStr.toLowerCase());

                case 'is_empty':
                    return fieldValue === '';

                case 'is_not_empty':
                    return fieldValue !== '';

                case 'greater_than':
                    return !isNaN(fieldValue) && !isNaN(compareStr) &&
                           parseFloat(fieldValue) > parseFloat(compareStr);

                case 'less_than':
                    return !isNaN(fieldValue) && !isNaN(compareStr) &&
                           parseFloat(fieldValue) < parseFloat(compareStr);

                case 'starts_with':
                    return fieldValue.toLowerCase().startsWith(compareStr.toLowerCase());

                case 'ends_with':
                    return fieldValue.toLowerCase().endsWith(compareStr.toLowerCase());

                default:
                    return false;
            }
        }

        /**
         * Evaluate rule for array values (checkboxes)
         */
        evaluateArrayRule(values, operator, compareValue) {
            const compareStr = String(compareValue).toLowerCase();

            switch (operator) {
                case 'equals':
                case 'contains':
                    return values.some(v => String(v).toLowerCase() === compareStr);

                case 'not_equals':
                case 'not_contains':
                    return !values.some(v => String(v).toLowerCase() === compareStr);

                case 'is_empty':
                    return values.length === 0;

                case 'is_not_empty':
                    return values.length > 0;

                default:
                    return false;
            }
        }

        /**
         * Set field visibility with animation
         */
        setFieldVisibility($element, visible, fieldId) {
            const isCurrentlyVisible = !$element.hasClass('mld-cf-hidden');

            if (visible === isCurrentlyVisible) {
                return; // No change needed
            }

            if (visible) {
                // Show field
                $element.removeClass('mld-cf-hidden');
                $element.slideDown(200, function() {
                    // Re-enable inputs
                    $element.find('input, select, textarea').prop('disabled', false);
                });

                // Restore required attribute if needed
                const $inputs = $element.find('input, select, textarea');
                $inputs.each(function() {
                    const $input = $(this);
                    if ($input.data('was-required')) {
                        $input.prop('required', true).attr('aria-required', 'true');
                    }
                });
            } else {
                // Hide field
                $element.addClass('mld-cf-hidden');
                $element.slideUp(200, function() {
                    // Disable inputs to exclude from validation
                    $element.find('input, select, textarea').prop('disabled', true);
                });

                // Store and remove required attribute
                const $inputs = $element.find('input, select, textarea');
                $inputs.each(function() {
                    const $input = $(this);
                    if ($input.prop('required')) {
                        $input.data('was-required', true);
                        $input.prop('required', false).removeAttr('aria-required');
                    }
                });

                // Clear validation errors
                $element.find('.mld-cf-field-error').empty();
                $element.find('input, select, textarea').removeClass('error');
            }
        }
    }

    /**
     * Initialize conditional logic on all forms
     */
    function initConditionalLogic() {
        $('.mld-contact-form').each(function() {
            const $form = $(this);

            // Skip if already initialized
            if ($form.data('conditional-init')) {
                return;
            }

            // Check if form has any conditional fields
            if ($form.find('[data-conditional]').length > 0) {
                new MLDConditionalLogic($form);
                $form.data('conditional-init', true);
            }
        });
    }

    // Initialize on DOM ready
    $(document).ready(function() {
        initConditionalLogic();
    });

    // Re-initialize on AJAX content load (for dynamically loaded forms)
    $(document).on('mld:form:loaded', function() {
        initConditionalLogic();
    });

    // Expose for external use
    window.MLDConditionalLogic = MLDConditionalLogic;

})(jQuery);
