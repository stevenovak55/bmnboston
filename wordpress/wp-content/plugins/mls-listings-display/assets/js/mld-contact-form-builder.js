/**
 * MLD Contact Form Builder
 *
 * Drag-and-drop form builder for creating custom contact forms.
 *
 * @package MLS_Listings_Display
 * @since 6.21.0
 */

(function($) {
    'use strict';

    // Builder class
    class MLDFormBuilder {
        constructor() {
            this.formId = mldFormData.id || 0;
            this.formData = mldFormData;
            this.fields = (mldFormData.fields && mldFormData.fields.fields) ? mldFormData.fields.fields : [];
            this.fieldTypes = mldFieldTypes;
            this.selectedFieldId = null;
            this.isDirty = false;
            this.isSaving = false;

            this.init();
        }

        init() {
            this.bindEvents();
            this.bindConditionalEvents(); // v6.22.0 - Conditional logic
            this.bindMultistepEvents(); // v6.23.0 - Multi-step forms
            this.bindFileUploadEvents(); // v6.24.0 - File upload configuration
            this.renderFields();
            this.setupDragAndDrop();
            this.updatePlaceholderVisibility();
            this.renderStepsList(); // v6.23.0 - Render steps list

            // Warn before leaving with unsaved changes
            $(window).on('beforeunload', (e) => {
                if (this.isDirty) {
                    e.preventDefault();
                    e.returnValue = '';
                    return '';
                }
            });
        }

        bindEvents() {
            // Tab switching
            $('.mld-tab-btn').on('click', (e) => {
                const $btn = $(e.currentTarget);
                const tabId = $btn.data('tab');

                $('.mld-tab-btn').removeClass('active');
                $btn.addClass('active');
                $('.mld-tab-content').removeClass('active');
                $('#' + tabId).addClass('active');
            });

            // Add field on click
            $('.mld-field-type-item').on('click', (e) => {
                const type = $(e.currentTarget).data('field-type');
                this.addField(type);
            });

            // Save form
            $('#save-form').on('click', () => this.saveForm());

            // Preview form
            $('#preview-form').on('click', () => this.previewForm());

            // Form name change
            $('#form-name').on('input', () => {
                this.formData.form_name = $('#form-name').val();
                this.markDirty();
            });

            // Form status change
            $('#form-status').on('change', () => {
                this.formData.status = $('#form-status').val();
                this.markDirty();
            });

            // Settings changes
            $('#setting-description').on('input', () => {
                this.formData.description = $('#setting-description').val();
                this.markDirty();
            });

            $('#setting-submit-text').on('input', () => {
                this.formData.settings.submit_button_text = $('#setting-submit-text').val();
                this.markDirty();
            });

            $('#setting-success-message').on('input', () => {
                this.formData.settings.success_message = $('#setting-success-message').val();
                this.markDirty();
            });

            $('#setting-redirect-url').on('input', () => {
                this.formData.settings.redirect_url = $('#setting-redirect-url').val();
                this.markDirty();
            });

            $('#setting-form-layout').on('change', () => {
                this.formData.settings.form_layout = $('#setting-form-layout').val();
                this.markDirty();
            });

            $('#setting-honeypot').on('change', () => {
                this.formData.settings.honeypot_enabled = $('#setting-honeypot').is(':checked');
                this.markDirty();
            });

            // Notification settings changes
            $('#notif-admin-enabled').on('change', () => {
                this.formData.notification_settings.admin_email_enabled = $('#notif-admin-enabled').is(':checked');
                this.markDirty();
            });

            $('#notif-admin-subject').on('input', () => {
                this.formData.notification_settings.admin_email_subject = $('#notif-admin-subject').val();
                this.markDirty();
            });

            $('#notif-additional-recipients').on('input', () => {
                this.formData.notification_settings.additional_recipients = $('#notif-additional-recipients').val();
                this.markDirty();
            });

            $('#notif-user-enabled').on('change', () => {
                this.formData.notification_settings.user_confirmation_enabled = $('#notif-user-enabled').is(':checked');
                this.markDirty();
            });

            $('#notif-user-subject').on('input', () => {
                this.formData.notification_settings.user_confirmation_subject = $('#notif-user-subject').val();
                this.markDirty();
            });

            $('#notif-user-message').on('input', () => {
                this.formData.notification_settings.user_confirmation_message = $('#notif-user-message').val();
                this.markDirty();
            });

            // Copy shortcode
            $(document).on('click', '.mld-copy-shortcode', (e) => {
                e.preventDefault();
                const shortcode = $(e.currentTarget).data('shortcode');
                this.copyToClipboard(shortcode, $(e.currentTarget));
            });

            // Field card click to select
            $(document).on('click', '.mld-field-card', (e) => {
                if (!$(e.target).closest('.mld-field-card-actions').length) {
                    const fieldId = $(e.currentTarget).data('field-id');
                    this.selectField(fieldId);
                }
            });

            // Remove field
            $(document).on('click', '.mld-remove-field, .mld-field-card-actions .delete', (e) => {
                e.preventDefault();
                e.stopPropagation();
                const fieldId = $(e.currentTarget).data('field-id') ||
                               $(e.currentTarget).closest('.mld-field-card').data('field-id');
                if (confirm(mldContactFormBuilder.strings.confirmDeleteField)) {
                    this.removeField(fieldId);
                }
            });

            // Field property changes
            $(document).on('input change', '.mld-field-prop', (e) => {
                const $input = $(e.currentTarget);
                const prop = $input.data('prop');
                let value = $input.attr('type') === 'checkbox' ? $input.is(':checked') : $input.val();

                // Convert step to integer
                if (prop === 'step') {
                    value = parseInt(value, 10) || 1;
                }

                this.updateFieldProperty(this.selectedFieldId, prop, value);
            });

            // Add option
            $(document).on('click', '.mld-add-option', (e) => {
                const fieldId = $(e.currentTarget).data('field-id');
                this.addOption(fieldId);
            });

            // Remove option
            $(document).on('click', '.mld-remove-option', (e) => {
                const fieldId = $(e.currentTarget).data('field-id');
                const index = $(e.currentTarget).data('index');
                this.removeOption(fieldId, index);
            });

            // Option value change
            $(document).on('input', '.mld-option-input', (e) => {
                const $input = $(e.currentTarget);
                const fieldId = $input.data('field-id');
                const index = $input.data('index');
                this.updateOption(fieldId, index, $input.val());
            });
        }

        setupDragAndDrop() {
            const self = this;

            // Make field types draggable
            $('.mld-field-type-item').each(function() {
                this.addEventListener('dragstart', function(e) {
                    e.dataTransfer.setData('text/plain', $(this).data('field-type'));
                    e.dataTransfer.effectAllowed = 'copy';
                    $(this).addClass('dragging');
                });

                this.addEventListener('dragend', function() {
                    $(this).removeClass('dragging');
                });
            });

            // Setup dropzone
            const dropzone = document.getElementById('field-dropzone');

            dropzone.addEventListener('dragover', function(e) {
                e.preventDefault();
                e.dataTransfer.dropEffect = 'copy';
                $(this).addClass('drag-over');
            });

            dropzone.addEventListener('dragleave', function(e) {
                if (!dropzone.contains(e.relatedTarget)) {
                    $(this).removeClass('drag-over');
                }
            });

            dropzone.addEventListener('drop', function(e) {
                e.preventDefault();
                $(this).removeClass('drag-over');

                const fieldType = e.dataTransfer.getData('text/plain');
                if (fieldType && self.fieldTypes[fieldType]) {
                    self.addField(fieldType);
                }
            });

            // Make existing fields sortable
            this.initSortable();
        }

        initSortable() {
            const self = this;

            $('#fields-container').sortable({
                items: '.mld-field-card',
                handle: '.mld-field-card-header',
                placeholder: 'mld-drag-placeholder',
                tolerance: 'pointer',
                update: function() {
                    self.updateFieldOrder();
                }
            });
        }

        addField(type, position = null) {
            const fieldInfo = this.fieldTypes[type];
            if (!fieldInfo) return;

            const field = {
                id: 'field_' + this.generateId(),
                type: type,
                label: fieldInfo.label,
                placeholder: '',
                required: type === 'email',
                validation: {},
                options: ['dropdown', 'checkbox', 'radio'].includes(type) ? ['Option 1', 'Option 2', 'Option 3'] : [],
                order: this.fields.length + 1,
                width: 'full',
                step: 1  // Multi-step support (v6.23.0)
            };

            // Set type-specific defaults
            switch (type) {
                case 'text':
                    field.placeholder = 'Enter text';
                    break;
                case 'email':
                    field.placeholder = 'Enter your email';
                    break;
                case 'phone':
                    field.placeholder = '(555) 123-4567';
                    break;
                case 'textarea':
                    field.placeholder = 'Enter your message';
                    break;
                case 'date':
                    field.placeholder = 'Select a date';
                    break;
                // New field types added in v6.22.0
                case 'number':
                    field.label = 'Number';
                    field.placeholder = 'Enter a number';
                    field.validation = { min: '', max: '', step: 1 };
                    break;
                case 'currency':
                    field.label = 'Amount';
                    field.placeholder = '0.00';
                    field.validation = { min: 0, max: '', currency_symbol: '$', decimal_places: 2 };
                    break;
                case 'url':
                    field.label = 'Website';
                    field.placeholder = 'https://example.com';
                    break;
                case 'hidden':
                    field.label = 'Hidden Field';
                    field.default_value = '';
                    field.placeholder = '';
                    break;
                case 'section':
                    field.label = 'Section Title';
                    field.description = '';
                    field.required = false;
                    break;
                case 'paragraph':
                    field.label = 'Information';
                    field.content = 'Enter your text content here.';
                    field.required = false;
                    break;

                // v6.24.0: File upload field
                case 'file':
                    field.label = 'Upload Files';
                    field.file_config = {
                        allowed_types: ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'],
                        max_size_mb: 5,
                        max_files: 3
                    };
                    break;
            }

            if (position !== null && position >= 0 && position < this.fields.length) {
                this.fields.splice(position, 0, field);
            } else {
                this.fields.push(field);
            }

            this.renderFields();
            this.selectField(field.id);
            this.markDirty();

            return field;
        }

        removeField(fieldId) {
            const index = this.fields.findIndex(f => f.id === fieldId);
            if (index > -1) {
                this.fields.splice(index, 1);
                this.renderFields();

                if (this.selectedFieldId === fieldId) {
                    this.selectedFieldId = null;
                    this.renderFieldProperties(null);
                }

                this.markDirty();
            }
        }

        selectField(fieldId) {
            this.selectedFieldId = fieldId;

            // Update visual selection
            $('.mld-field-card').removeClass('selected');
            $(`.mld-field-card[data-field-id="${fieldId}"]`).addClass('selected');

            // Switch to Field tab
            $('.mld-tab-btn').removeClass('active');
            $('.mld-tab-btn[data-tab="field-properties"]').addClass('active');
            $('.mld-tab-content').removeClass('active');
            $('#field-properties').addClass('active');

            // Render properties
            const field = this.fields.find(f => f.id === fieldId);
            this.renderFieldProperties(field);
        }

        updateFieldProperty(fieldId, prop, value) {
            const field = this.fields.find(f => f.id === fieldId);
            if (!field) return;

            field[prop] = value;
            this.renderFieldCard(field);
            this.markDirty();
        }

        updateFieldOrder() {
            const newOrder = [];
            $('#fields-container .mld-field-card').each(function(index) {
                const fieldId = $(this).data('field-id');
                newOrder.push(fieldId);
            });

            // Reorder fields array
            const reordered = [];
            newOrder.forEach((id, index) => {
                const field = this.fields.find(f => f.id === id);
                if (field) {
                    field.order = index + 1;
                    reordered.push(field);
                }
            });

            this.fields = reordered;
            this.markDirty();
        }

        renderFields() {
            const $container = $('#fields-container');
            $container.empty();

            // Sort fields by order
            const sortedFields = [...this.fields].sort((a, b) => (a.order || 0) - (b.order || 0));

            sortedFields.forEach(field => {
                const $card = this.createFieldCard(field);
                $container.append($card);
            });

            this.updatePlaceholderVisibility();
            this.initSortable();
        }

        createFieldCard(field) {
            const fieldType = this.fieldTypes[field.type] || {};
            const isSelected = this.selectedFieldId === field.id;

            const $card = $(`
                <div class="mld-field-card ${isSelected ? 'selected' : ''} ${field.width === 'half' ? 'half-width' : ''}"
                     data-field-id="${field.id}">
                    <div class="mld-field-card-header">
                        <span class="mld-field-card-title">
                            <span class="dashicons ${fieldType.icon || 'dashicons-forms'}"></span>
                            ${this.escapeHtml(field.label)}
                        </span>
                        <div class="mld-field-card-actions">
                            <button type="button" class="delete" data-field-id="${field.id}" title="Remove">
                                <span class="dashicons dashicons-trash"></span>
                            </button>
                        </div>
                    </div>
                    <div class="mld-field-card-preview">
                        ${this.renderFieldPreview(field)}
                    </div>
                </div>
            `);

            return $card;
        }

        renderFieldCard(field) {
            const $existingCard = $(`.mld-field-card[data-field-id="${field.id}"]`);
            if ($existingCard.length) {
                const $newCard = this.createFieldCard(field);
                $existingCard.replaceWith($newCard);
            }
        }

        renderFieldPreview(field) {
            // Special handling for display-only fields
            if (field.type === 'section') {
                return `<h4 class="mld-section-preview">${this.escapeHtml(field.label)}</h4>` +
                       (field.description ? `<p class="mld-section-desc-preview">${this.escapeHtml(field.description)}</p>` : '');
            }

            if (field.type === 'paragraph') {
                return `<div class="mld-paragraph-preview">${this.escapeHtml(field.content || 'Enter text content...')}</div>`;
            }

            if (field.type === 'hidden') {
                return `<div class="mld-hidden-preview"><span class="dashicons dashicons-hidden"></span> Hidden field: ${this.escapeHtml(field.default_value || '(empty)')}</div>`;
            }

            let html = `<label>${this.escapeHtml(field.label)}`;
            if (field.required) {
                html += ' <span class="required-indicator">*</span>';
            }
            html += '</label>';

            switch (field.type) {
                case 'text':
                case 'email':
                case 'phone':
                case 'date':
                case 'url':
                    const inputType = field.type === 'phone' ? 'tel' : field.type;
                    html += `<input type="${inputType}" placeholder="${this.escapeHtml(field.placeholder || '')}" disabled>`;
                    break;

                case 'number':
                    html += `<input type="number" placeholder="${this.escapeHtml(field.placeholder || '')}" disabled>`;
                    break;

                case 'currency':
                    const symbol = (field.validation && field.validation.currency_symbol) || '$';
                    html += `<div class="mld-currency-preview"><span>${symbol}</span><input type="number" placeholder="${this.escapeHtml(field.placeholder || '0.00')}" disabled></div>`;
                    break;

                case 'textarea':
                    html += `<textarea placeholder="${this.escapeHtml(field.placeholder || '')}" disabled></textarea>`;
                    break;

                case 'dropdown':
                    html += '<select disabled><option>' + (field.options[0] || 'Select...') + '</option></select>';
                    break;

                case 'checkbox':
                case 'radio':
                    const inputTypeChoice = field.type === 'checkbox' ? 'checkbox' : 'radio';
                    field.options.slice(0, 3).forEach(opt => {
                        html += `<label class="mld-choice-preview"><input type="${inputTypeChoice}" disabled> ${this.escapeHtml(opt)}</label>`;
                    });
                    break;

                case 'file':
                    const fileConfig = field.file_config || { max_files: 3 };
                    html += `<div class="mld-file-preview">
                        <span class="dashicons dashicons-cloud-upload"></span>
                        <span>Drag & drop or browse (max ${fileConfig.max_files} files)</span>
                    </div>`;
                    break;
            }

            return html;
        }

        renderFieldProperties(field) {
            const $form = $('#field-properties-form');
            const $noField = $('.mld-no-field-selected');

            if (!field) {
                $form.hide();
                $noField.show();
                return;
            }

            $noField.hide();
            $form.show();

            const fieldType = this.fieldTypes[field.type] || {};
            const hasOptions = ['dropdown', 'checkbox', 'radio'].includes(field.type);
            const isDisplayOnly = ['section', 'paragraph'].includes(field.type);
            const isHidden = field.type === 'hidden';

            let html = `
                <div class="mld-property-header">
                    <span class="mld-field-type-badge">${fieldType.label || field.type}</span>
                    <button type="button" class="mld-remove-field button-link-delete" data-field-id="${field.id}">
                        <span class="dashicons dashicons-trash"></span>
                    </button>
                </div>

                <div class="mld-settings-group">
                    <label>${field.type === 'section' ? 'Section Title' : (field.type === 'paragraph' ? 'Label' : 'Label')}</label>
                    <input type="text" class="mld-field-prop" data-prop="label" value="${this.escapeHtml(field.label || '')}">
                </div>
            `;

            // Section-specific: description field
            if (field.type === 'section') {
                html += `
                    <div class="mld-settings-group">
                        <label>Description (optional)</label>
                        <textarea class="mld-field-prop" data-prop="description" rows="2">${this.escapeHtml(field.description || '')}</textarea>
                    </div>
                `;
            }

            // Paragraph-specific: content field
            if (field.type === 'paragraph') {
                html += `
                    <div class="mld-settings-group">
                        <label>Content</label>
                        <textarea class="mld-field-prop" data-prop="content" rows="4">${this.escapeHtml(field.content || '')}</textarea>
                        <p class="description">HTML tags allowed: links, bold, italic, lists.</p>
                    </div>
                `;
            }

            // Hidden field: default value
            if (isHidden) {
                html += `
                    <div class="mld-settings-group">
                        <label>Default Value</label>
                        <input type="text" class="mld-field-prop" data-prop="default_value" value="${this.escapeHtml(field.default_value || '')}">
                        <p class="description">Use {page_url}, {page_title}, {utm_source}, etc. for dynamic values.</p>
                    </div>
                `;
            }

            // Regular input fields get placeholder
            if (!isDisplayOnly && !isHidden) {
                html += `
                    <div class="mld-settings-group">
                        <label>Placeholder</label>
                        <input type="text" class="mld-field-prop" data-prop="placeholder" value="${this.escapeHtml(field.placeholder || '')}">
                    </div>
                `;
            }

            // Required checkbox (not for display-only or hidden)
            if (!isDisplayOnly && !isHidden) {
                html += `
                    <div class="mld-settings-group">
                        <label class="mld-checkbox-label">
                            <input type="checkbox" class="mld-field-prop" data-prop="required" ${field.required ? 'checked' : ''}>
                            Required field
                        </label>
                    </div>
                `;
            }

            // Field width (all fields)
            html += `
                <div class="mld-settings-group">
                    <label>Field Width</label>
                    <select class="mld-field-prop" data-prop="width">
                        <option value="full" ${field.width === 'full' ? 'selected' : ''}>Full Width</option>
                        <option value="half" ${field.width === 'half' ? 'selected' : ''}>Half Width</option>
                    </select>
                </div>
            `;

            // Step assignment (only when multi-step is enabled)
            if (this.formData.settings && this.formData.settings.multistep_enabled) {
                const steps = this.formData.settings.steps || [{ title: 'Step 1', description: '' }];
                const currentStep = field.step || 1;

                let stepOptions = '';
                steps.forEach((step, index) => {
                    const stepNum = index + 1;
                    const selected = currentStep === stepNum ? 'selected' : '';
                    const stepTitle = step.title || `Step ${stepNum}`;
                    stepOptions += `<option value="${stepNum}" ${selected}>${stepNum}. ${this.escapeHtml(stepTitle)}</option>`;
                });

                html += `
                    <div class="mld-settings-group">
                        <label>Step</label>
                        <select class="mld-field-prop" data-prop="step">
                            ${stepOptions}
                        </select>
                    </div>
                `;
            }

            // Number field: min, max, step
            if (field.type === 'number') {
                const validation = field.validation || {};
                html += `
                    <div class="mld-settings-group mld-validation-group">
                        <label>Number Validation</label>
                        <div class="mld-inline-inputs">
                            <div>
                                <label class="small">Min</label>
                                <input type="number" class="mld-validation-prop" data-validation-prop="min" value="${validation.min !== undefined ? validation.min : ''}" placeholder="No min">
                            </div>
                            <div>
                                <label class="small">Max</label>
                                <input type="number" class="mld-validation-prop" data-validation-prop="max" value="${validation.max !== undefined ? validation.max : ''}" placeholder="No max">
                            </div>
                            <div>
                                <label class="small">Step</label>
                                <input type="number" class="mld-validation-prop" data-validation-prop="step" value="${validation.step || 1}" min="0.01" step="any">
                            </div>
                        </div>
                    </div>
                `;
            }

            // Currency field: symbol, min, max
            if (field.type === 'currency') {
                const validation = field.validation || {};
                html += `
                    <div class="mld-settings-group mld-validation-group">
                        <label>Currency Settings</label>
                        <div class="mld-inline-inputs">
                            <div>
                                <label class="small">Symbol</label>
                                <input type="text" class="mld-validation-prop" data-validation-prop="currency_symbol" value="${validation.currency_symbol || '$'}" maxlength="3" style="width: 50px;">
                            </div>
                            <div>
                                <label class="small">Min</label>
                                <input type="number" class="mld-validation-prop" data-validation-prop="min" value="${validation.min !== undefined ? validation.min : ''}" placeholder="0">
                            </div>
                            <div>
                                <label class="small">Max</label>
                                <input type="number" class="mld-validation-prop" data-validation-prop="max" value="${validation.max !== undefined ? validation.max : ''}" placeholder="No max">
                            </div>
                        </div>
                    </div>
                `;
            }

            // Options for dropdown, checkbox, radio
            if (hasOptions) {
                html += `
                    <div class="mld-options-editor">
                        <label>Options</label>
                        <div class="mld-options-list" id="options-list-${field.id}">
                            ${this.renderOptionsEditor(field)}
                        </div>
                        <button type="button" class="button mld-add-option" data-field-id="${field.id}">
                            <span class="dashicons dashicons-plus-alt2" style="margin-top: 3px;"></span>
                            Add Option
                        </button>
                    </div>
                `;
            }

            // File upload configuration (v6.24.0)
            if (field.type === 'file') {
                const fileConfig = field.file_config || { allowed_types: ['pdf', 'jpg', 'jpeg', 'png'], max_size_mb: 5, max_files: 3 };
                const allTypes = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'doc', 'docx', 'xls', 'xlsx', 'txt', 'csv'];
                const allowedTypes = fileConfig.allowed_types || [];

                let typesHtml = '';
                allTypes.forEach(type => {
                    const checked = allowedTypes.includes(type) ? 'checked' : '';
                    typesHtml += `<label><input type="checkbox" class="mld-file-type-checkbox" data-field-id="${field.id}" value="${type}" ${checked}> ${type.toUpperCase()}</label>`;
                });

                html += `
                    <div class="mld-settings-group">
                        <h4 style="margin: 16px 0 12px; font-size: 13px; color: #1d2327;">File Upload Settings</h4>
                    </div>
                    <div class="mld-settings-group">
                        <label>Allowed File Types</label>
                        <div class="mld-allowed-types">${typesHtml}</div>
                    </div>
                    <div class="mld-settings-group">
                        <label>Max File Size (MB)</label>
                        <input type="number" class="mld-file-config-prop" data-field-id="${field.id}" data-prop="max_size_mb" min="1" max="50" value="${fileConfig.max_size_mb || 5}">
                    </div>
                    <div class="mld-settings-group">
                        <label>Max Number of Files</label>
                        <input type="number" class="mld-file-config-prop" data-field-id="${field.id}" data-prop="max_files" min="1" max="10" value="${fileConfig.max_files || 3}">
                    </div>
                `;
            }

            // Conditional Logic section (v6.22.0)
            html += this.renderConditionalLogicEditor(field);

            $form.html(html);

            // Bind validation property changes
            $form.find('.mld-validation-prop').off('input').on('input', (e) => {
                const $input = $(e.currentTarget);
                const prop = $input.data('validation-prop');
                let value = $input.val();

                // Convert to number if it's a number input
                if ($input.attr('type') === 'number' && value !== '') {
                    value = parseFloat(value);
                }

                const currentField = this.fields.find(f => f.id === this.selectedFieldId);
                if (currentField) {
                    if (!currentField.validation) currentField.validation = {};
                    currentField.validation[prop] = value;
                    this.renderFieldCard(currentField);
                    this.markDirty();
                }
            });
        }

        renderOptionsEditor(field) {
            if (!field.options || !field.options.length) return '';

            let html = '';
            field.options.forEach((opt, index) => {
                html += `
                    <div class="mld-option-item">
                        <input type="text" class="mld-option-input" data-field-id="${field.id}" data-index="${index}" value="${this.escapeHtml(opt)}">
                        <button type="button" class="mld-remove-option" data-field-id="${field.id}" data-index="${index}">
                            <span class="dashicons dashicons-no-alt"></span>
                        </button>
                    </div>
                `;
            });

            return html;
        }

        /**
         * Render conditional logic editor section (v6.22.0)
         */
        renderConditionalLogicEditor(field) {
            // Get available source fields (exclude current field and display-only types)
            const sourceFields = this.fields.filter(f =>
                f.id !== field.id &&
                !['section', 'paragraph', 'hidden'].includes(f.type)
            );

            // If no source fields available, don't show conditional logic
            if (sourceFields.length === 0) {
                return '';
            }

            const conditional = field.conditional || {
                enabled: false,
                action: 'show',
                logic: 'all',
                rules: []
            };

            const isEnabled = conditional.enabled;

            let html = `
                <div class="mld-conditional-logic-editor">
                    <div class="mld-conditional-header">
                        <label class="mld-checkbox-label">
                            <input type="checkbox" class="mld-conditional-enabled" data-field-id="${field.id}" ${isEnabled ? 'checked' : ''}>
                            Enable Conditional Logic
                        </label>
                    </div>

                    <div class="mld-conditional-body ${isEnabled ? '' : 'hidden'}">
                        <div class="mld-conditional-action-row">
                            <select class="mld-conditional-action" data-field-id="${field.id}">
                                <option value="show" ${conditional.action === 'show' ? 'selected' : ''}>Show</option>
                                <option value="hide" ${conditional.action === 'hide' ? 'selected' : ''}>Hide</option>
                            </select>
                            <span>this field when</span>
                            <select class="mld-conditional-logic" data-field-id="${field.id}">
                                <option value="all" ${conditional.logic === 'all' ? 'selected' : ''}>ALL</option>
                                <option value="any" ${conditional.logic === 'any' ? 'selected' : ''}>ANY</option>
                            </select>
                            <span>of these conditions match:</span>
                        </div>

                        <div class="mld-conditional-rules" id="conditional-rules-${field.id}">
                            ${this.renderConditionalRules(field, sourceFields)}
                        </div>

                        <button type="button" class="button button-small mld-add-rule" data-field-id="${field.id}">
                            <span class="dashicons dashicons-plus-alt2" style="vertical-align: middle; margin-top: -2px;"></span>
                            Add Condition
                        </button>
                    </div>
                </div>
            `;

            return html;
        }

        /**
         * Render conditional rules list
         */
        renderConditionalRules(field, sourceFields) {
            const conditional = field.conditional || { rules: [] };
            const rules = conditional.rules || [];

            if (rules.length === 0) {
                // Add a default empty rule
                return this.renderSingleRule(field, sourceFields, 0, {
                    field_id: '',
                    operator: 'equals',
                    value: ''
                });
            }

            let html = '';
            rules.forEach((rule, index) => {
                html += this.renderSingleRule(field, sourceFields, index, rule);
            });

            return html;
        }

        /**
         * Render a single conditional rule
         */
        renderSingleRule(field, sourceFields, index, rule) {
            const operators = {
                'equals': 'equals',
                'not_equals': 'does not equal',
                'contains': 'contains',
                'not_contains': 'does not contain',
                'is_empty': 'is empty',
                'is_not_empty': 'is not empty',
                'greater_than': 'greater than',
                'less_than': 'less than'
            };

            const noValueOperators = ['is_empty', 'is_not_empty'];
            const showValueField = !noValueOperators.includes(rule.operator || 'equals');

            // Build field options
            let fieldOptions = '<option value="">-- Select Field --</option>';
            sourceFields.forEach(f => {
                const selected = rule.field_id === f.id ? 'selected' : '';
                fieldOptions += `<option value="${f.id}" ${selected}>${this.escapeHtml(f.label)}</option>`;
            });

            // Build operator options
            let operatorOptions = '';
            Object.keys(operators).forEach(op => {
                const selected = rule.operator === op ? 'selected' : '';
                operatorOptions += `<option value="${op}" ${selected}>${operators[op]}</option>`;
            });

            // Get source field options if it's a dropdown/checkbox/radio
            const sourceField = sourceFields.find(f => f.id === rule.field_id);
            const hasOptions = sourceField && ['dropdown', 'checkbox', 'radio'].includes(sourceField.type);

            let valueInput = '';
            if (showValueField) {
                if (hasOptions && sourceField.options && sourceField.options.length > 0) {
                    // Render as dropdown
                    let valueOptions = '<option value="">-- Select Value --</option>';
                    sourceField.options.forEach(opt => {
                        const selected = rule.value === opt ? 'selected' : '';
                        valueOptions += `<option value="${this.escapeHtml(opt)}" ${selected}>${this.escapeHtml(opt)}</option>`;
                    });
                    valueInput = `<select class="mld-rule-value" data-field-id="${field.id}" data-index="${index}">${valueOptions}</select>`;
                } else {
                    // Render as text input
                    valueInput = `<input type="text" class="mld-rule-value" data-field-id="${field.id}" data-index="${index}" value="${this.escapeHtml(rule.value || '')}" placeholder="Value">`;
                }
            }

            return `
                <div class="mld-rule-item" data-index="${index}">
                    <button type="button" class="mld-remove-rule" data-field-id="${field.id}" data-index="${index}" title="Remove condition">
                        <span class="dashicons dashicons-no-alt"></span>
                    </button>
                    <div class="mld-rule-row">
                        <label class="mld-rule-label">When this field:</label>
                        <select class="mld-rule-field" data-field-id="${field.id}" data-index="${index}">
                            ${fieldOptions}
                        </select>
                    </div>
                    <div class="mld-rule-row">
                        <label class="mld-rule-label">Condition:</label>
                        <select class="mld-rule-operator" data-field-id="${field.id}" data-index="${index}">
                            ${operatorOptions}
                        </select>
                    </div>
                    <div class="mld-rule-row mld-rule-value-wrapper ${showValueField ? '' : 'hidden'}">
                        <label class="mld-rule-label">Value:</label>
                        ${valueInput}
                    </div>
                </div>
            `;
        }

        /**
         * Initialize conditional logic bindings
         */
        bindConditionalEvents() {
            const self = this;

            // Enable/disable conditional logic
            $(document).off('change', '.mld-conditional-enabled').on('change', '.mld-conditional-enabled', function() {
                const fieldId = $(this).data('field-id');
                const enabled = $(this).is(':checked');
                self.updateConditionalProperty(fieldId, 'enabled', enabled);

                // Toggle body visibility
                $(this).closest('.mld-conditional-logic-editor').find('.mld-conditional-body')
                    .toggleClass('hidden', !enabled);
            });

            // Action change (show/hide)
            $(document).off('change', '.mld-conditional-action').on('change', '.mld-conditional-action', function() {
                const fieldId = $(this).data('field-id');
                self.updateConditionalProperty(fieldId, 'action', $(this).val());
            });

            // Logic change (all/any)
            $(document).off('change', '.mld-conditional-logic').on('change', '.mld-conditional-logic', function() {
                const fieldId = $(this).data('field-id');
                self.updateConditionalProperty(fieldId, 'logic', $(this).val());
            });

            // Rule field change
            $(document).off('change', '.mld-rule-field').on('change', '.mld-rule-field', function() {
                const fieldId = $(this).data('field-id');
                const index = $(this).data('index');
                self.updateConditionalRule(fieldId, index, 'field_id', $(this).val());

                // Re-render to update value field type
                const field = self.fields.find(f => f.id === fieldId);
                if (field) {
                    self.renderFieldProperties(field);
                }
            });

            // Rule operator change
            $(document).off('change', '.mld-rule-operator').on('change', '.mld-rule-operator', function() {
                const fieldId = $(this).data('field-id');
                const index = $(this).data('index');
                const operator = $(this).val();
                self.updateConditionalRule(fieldId, index, 'operator', operator);

                // Toggle value field visibility
                const noValueOps = ['is_empty', 'is_not_empty'];
                $(this).siblings('.mld-rule-value-wrapper').toggleClass('hidden', noValueOps.includes(operator));
            });

            // Rule value change
            $(document).off('input change', '.mld-rule-value').on('input change', '.mld-rule-value', function() {
                const fieldId = $(this).data('field-id');
                const index = $(this).data('index');
                self.updateConditionalRule(fieldId, index, 'value', $(this).val());
            });

            // Add rule
            $(document).off('click', '.mld-add-rule').on('click', '.mld-add-rule', function() {
                const fieldId = $(this).data('field-id');
                self.addConditionalRule(fieldId);
            });

            // Remove rule
            $(document).off('click', '.mld-remove-rule').on('click', '.mld-remove-rule', function() {
                const fieldId = $(this).data('field-id');
                const index = $(this).data('index');
                self.removeConditionalRule(fieldId, index);
            });
        }

        /**
         * Update conditional property
         */
        updateConditionalProperty(fieldId, prop, value) {
            const field = this.fields.find(f => f.id === fieldId);
            if (!field) return;

            if (!field.conditional) {
                field.conditional = {
                    enabled: false,
                    action: 'show',
                    logic: 'all',
                    rules: []
                };
            }

            field.conditional[prop] = value;
            this.markDirty();
        }

        /**
         * Update a specific rule in conditional logic
         */
        updateConditionalRule(fieldId, index, prop, value) {
            const field = this.fields.find(f => f.id === fieldId);
            if (!field || !field.conditional) return;

            if (!field.conditional.rules) {
                field.conditional.rules = [];
            }

            // Ensure rule exists at index
            while (field.conditional.rules.length <= index) {
                field.conditional.rules.push({ field_id: '', operator: 'equals', value: '' });
            }

            field.conditional.rules[index][prop] = value;
            this.markDirty();
        }

        /**
         * Add a new conditional rule
         */
        addConditionalRule(fieldId) {
            const field = this.fields.find(f => f.id === fieldId);
            if (!field) return;

            if (!field.conditional) {
                field.conditional = {
                    enabled: true,
                    action: 'show',
                    logic: 'all',
                    rules: []
                };
            }

            field.conditional.rules.push({
                field_id: '',
                operator: 'equals',
                value: ''
            });

            this.renderFieldProperties(field);
            this.markDirty();
        }

        /**
         * Remove a conditional rule
         */
        removeConditionalRule(fieldId, index) {
            const field = this.fields.find(f => f.id === fieldId);
            if (!field || !field.conditional || !field.conditional.rules) return;

            // Keep at least one rule
            if (field.conditional.rules.length <= 1) {
                field.conditional.rules = [{
                    field_id: '',
                    operator: 'equals',
                    value: ''
                }];
            } else {
                field.conditional.rules.splice(index, 1);
            }

            this.renderFieldProperties(field);
            this.markDirty();
        }

        /**
         * Bind multi-step form events (v6.23.0)
         */
        bindMultistepEvents() {
            const self = this;

            // Enable/disable multi-step
            $('#setting-multistep-enabled').on('change', function() {
                const enabled = $(this).is(':checked');
                self.formData.settings.multistep_enabled = enabled;
                $('#multistep-settings').toggle(enabled);

                // Initialize steps if not present
                if (enabled && (!self.formData.settings.steps || self.formData.settings.steps.length === 0)) {
                    self.formData.settings.steps = [{ title: 'Step 1', description: '' }];
                    self.renderStepsList();
                }

                // Re-render field properties if a field is selected
                if (self.selectedFieldId) {
                    const field = self.fields.find(f => f.id === self.selectedFieldId);
                    if (field) {
                        self.renderFieldProperties(field);
                    }
                }

                self.markDirty();
            });

            // Progress type change
            $('#setting-progress-type').on('change', function() {
                self.formData.settings.multistep_progress_type = $(this).val();
                self.markDirty();
            });

            // Show step titles toggle
            $('#setting-show-step-titles').on('change', function() {
                self.formData.settings.multistep_show_step_titles = $(this).is(':checked');
                self.markDirty();
            });

            // Previous button text
            $('#setting-prev-button').on('input', function() {
                self.formData.settings.multistep_prev_button_text = $(this).val();
                self.markDirty();
            });

            // Next button text
            $('#setting-next-button').on('input', function() {
                self.formData.settings.multistep_next_button_text = $(this).val();
                self.markDirty();
            });

            // Add step button
            $('#add-step-btn').on('click', function() {
                self.addStep();
            });

            // Remove step
            $(document).on('click', '.mld-remove-step', function() {
                const index = $(this).data('index');
                self.removeStep(index);
            });

            // Step title change
            $(document).on('input', '.mld-step-title', function() {
                const index = $(this).data('index');
                self.updateStepProperty(index, 'title', $(this).val());
            });

            // Step description change
            $(document).on('input', '.mld-step-description', function() {
                const index = $(this).data('index');
                self.updateStepProperty(index, 'description', $(this).val());
            });
        }

        /**
         * Bind file upload field events (v6.24.0)
         */
        bindFileUploadEvents() {
            const self = this;

            // File type checkbox change
            $(document).on('change', '.mld-file-type-checkbox', function() {
                const fieldId = $(this).data('field-id');
                const field = self.fields.find(f => f.id === fieldId);
                if (!field) return;

                if (!field.file_config) {
                    field.file_config = { allowed_types: [], max_size_mb: 5, max_files: 3 };
                }

                // Collect all checked types
                const checkedTypes = [];
                $(`.mld-file-type-checkbox[data-field-id="${fieldId}"]:checked`).each(function() {
                    checkedTypes.push($(this).val());
                });

                field.file_config.allowed_types = checkedTypes;
                self.markDirty();
            });

            // File config property change (max_size_mb, max_files)
            $(document).on('input change', '.mld-file-config-prop', function() {
                const fieldId = $(this).data('field-id');
                const prop = $(this).data('prop');
                const value = parseInt($(this).val(), 10);

                const field = self.fields.find(f => f.id === fieldId);
                if (!field) return;

                if (!field.file_config) {
                    field.file_config = { allowed_types: ['pdf', 'jpg', 'jpeg', 'png'], max_size_mb: 5, max_files: 3 };
                }

                field.file_config[prop] = value;
                self.markDirty();
            });
        }

        /**
         * Render the steps list in settings panel
         */
        renderStepsList() {
            const $list = $('#steps-list');
            if (!$list.length) return;

            const steps = this.formData.settings.steps || [{ title: 'Step 1', description: '' }];

            let html = '';
            steps.forEach((step, index) => {
                const canRemove = steps.length > 1;
                html += `
                    <div class="mld-step-item" data-index="${index}">
                        <div class="mld-step-header">
                            <span class="mld-step-number">${index + 1}</span>
                            <input type="text" class="mld-step-title" data-index="${index}" value="${this.escapeHtml(step.title || '')}" placeholder="Step title">
                            ${canRemove ? `<button type="button" class="mld-remove-step" data-index="${index}" title="Remove step">
                                <span class="dashicons dashicons-no-alt"></span>
                            </button>` : ''}
                        </div>
                        <input type="text" class="mld-step-description" data-index="${index}" value="${this.escapeHtml(step.description || '')}" placeholder="Optional description">
                    </div>
                `;
            });

            $list.html(html);
        }

        /**
         * Add a new step
         */
        addStep() {
            if (!this.formData.settings.steps) {
                this.formData.settings.steps = [];
            }

            const newStepNum = this.formData.settings.steps.length + 1;
            this.formData.settings.steps.push({
                title: `Step ${newStepNum}`,
                description: ''
            });

            this.renderStepsList();

            // Re-render field properties to show updated step dropdown
            if (this.selectedFieldId) {
                const field = this.fields.find(f => f.id === this.selectedFieldId);
                if (field) {
                    this.renderFieldProperties(field);
                }
            }

            this.markDirty();
        }

        /**
         * Remove a step
         */
        removeStep(index) {
            if (!this.formData.settings.steps || this.formData.settings.steps.length <= 1) {
                return;
            }

            const removedStepNum = index + 1;

            // Remove the step
            this.formData.settings.steps.splice(index, 1);

            // Update fields that were assigned to this step or higher
            this.fields.forEach(field => {
                if (field.step === removedStepNum) {
                    // Assign to previous step or step 1
                    field.step = Math.max(1, removedStepNum - 1);
                } else if (field.step > removedStepNum) {
                    // Shift step numbers down
                    field.step = field.step - 1;
                }
            });

            this.renderStepsList();

            // Re-render field properties to show updated step dropdown
            if (this.selectedFieldId) {
                const field = this.fields.find(f => f.id === this.selectedFieldId);
                if (field) {
                    this.renderFieldProperties(field);
                }
            }

            this.markDirty();
        }

        /**
         * Update a step's property (title or description)
         */
        updateStepProperty(index, prop, value) {
            if (!this.formData.settings.steps || !this.formData.settings.steps[index]) {
                return;
            }

            this.formData.settings.steps[index][prop] = value;

            // Re-render field properties to update step dropdown labels
            if (prop === 'title' && this.selectedFieldId) {
                const field = this.fields.find(f => f.id === this.selectedFieldId);
                if (field) {
                    this.renderFieldProperties(field);
                }
            }

            this.markDirty();
        }

        addOption(fieldId) {
            const field = this.fields.find(f => f.id === fieldId);
            if (!field) return;

            if (!field.options) field.options = [];
            field.options.push('New Option');

            this.renderFieldProperties(field);
            this.renderFieldCard(field);
            this.markDirty();
        }

        removeOption(fieldId, index) {
            const field = this.fields.find(f => f.id === fieldId);
            if (!field || !field.options) return;

            field.options.splice(index, 1);

            this.renderFieldProperties(field);
            this.renderFieldCard(field);
            this.markDirty();
        }

        updateOption(fieldId, index, value) {
            const field = this.fields.find(f => f.id === fieldId);
            if (!field || !field.options) return;

            field.options[index] = value;
            this.renderFieldCard(field);
            this.markDirty();
        }

        updatePlaceholderVisibility() {
            const $placeholder = $('#canvas-placeholder');
            if (this.fields.length > 0) {
                $placeholder.addClass('hidden');
            } else {
                $placeholder.removeClass('hidden');
            }
        }

        markDirty() {
            this.isDirty = true;
            $('#save-status').text('').removeClass('saved saving');
        }

        async saveForm() {
            if (this.isSaving) return;

            const formName = $('#form-name').val().trim();
            if (!formName) {
                alert('Please enter a form name.');
                $('#form-name').focus();
                return;
            }

            this.isSaving = true;
            const $saveBtn = $('#save-form');
            const $status = $('#save-status');

            $saveBtn.prop('disabled', true).html('<span class="mld-loading"></span> ' + mldContactFormBuilder.strings.saving);
            $status.text(mldContactFormBuilder.strings.saving).addClass('saving').removeClass('saved');

            // Prepare data
            const data = {
                action: 'mld_save_contact_form',
                nonce: mldContactFormBuilder.nonce,
                form_id: this.formId,
                form_name: formName,
                description: this.formData.description || '',
                fields: JSON.stringify({ fields: this.fields }),
                settings: JSON.stringify(this.formData.settings),
                notification_settings: JSON.stringify(this.formData.notification_settings),
                status: $('#form-status').val()
            };

            try {
                const response = await $.post(mldContactFormBuilder.ajaxUrl, data);

                if (response.success) {
                    this.isDirty = false;
                    this.formId = response.data.form_id;

                    // Update URL if this was a new form
                    if (mldIsNewForm) {
                        const newUrl = new URL(window.location.href);
                        newUrl.searchParams.set('action', 'edit');
                        newUrl.searchParams.set('form_id', this.formId);
                        window.history.replaceState({}, '', newUrl.toString());
                        mldIsNewForm = false;

                        // Show shortcode
                        if (response.data.shortcode) {
                            $('.mld-shortcode-display').remove();
                            $('<span class="mld-shortcode-display" style="margin-left: 15px;"><strong>Shortcode:</strong> <code>' + response.data.shortcode + '</code> <button type="button" class="button button-small mld-copy-shortcode" data-shortcode="' + response.data.shortcode + '"><span class="dashicons dashicons-clipboard" style="font-size: 14px; width: 14px; height: 14px; vertical-align: text-bottom;"></span></button></span>')
                                .insertAfter('.page-title-action');
                        }
                    }

                    $status.text(mldContactFormBuilder.strings.saved).removeClass('saving').addClass('saved');
                    setTimeout(() => $status.text(''), 3000);
                } else {
                    // Extract error message from response
                    let errorMsg = mldContactFormBuilder.strings.error;
                    if (response.data && response.data.message) {
                        errorMsg = response.data.message;
                    } else if (typeof response.data === 'string') {
                        errorMsg = response.data;
                    }
                    throw new Error(errorMsg);
                }
            } catch (error) {
                console.error('Save error:', error);
                alert(error.message || mldContactFormBuilder.strings.error);
                $status.text('').removeClass('saving saved');
            } finally {
                this.isSaving = false;
                $saveBtn.prop('disabled', false).html('<span class="dashicons dashicons-cloud-upload" style="margin-top: 3px;"></span> Save Form');
            }
        }

        previewForm() {
            // Build preview HTML
            const formName = $('#form-name').val() || 'Contact Form';
            const fields = this.fields;

            if (fields.length === 0) {
                alert('Add some fields to preview the form.');
                return;
            }

            let fieldsHtml = '';
            fields.forEach(field => {
                const widthClass = field.width === 'half' ? 'mld-cf-field-half' : 'mld-cf-field-full';
                const requiredMark = field.required ? '<span class="mld-cf-required">*</span>' : '';

                let inputHtml = '';
                switch (field.type) {
                    case 'text':
                        inputHtml = `<input type="text" class="mld-cf-input" placeholder="${this.escapeHtml(field.placeholder || '')}" disabled>`;
                        break;
                    case 'email':
                        inputHtml = `<input type="email" class="mld-cf-input" placeholder="${this.escapeHtml(field.placeholder || '')}" disabled>`;
                        break;
                    case 'phone':
                        inputHtml = `<input type="tel" class="mld-cf-input" placeholder="${this.escapeHtml(field.placeholder || '')}" disabled>`;
                        break;
                    case 'textarea':
                        inputHtml = `<textarea class="mld-cf-textarea" placeholder="${this.escapeHtml(field.placeholder || '')}" rows="4" disabled></textarea>`;
                        break;
                    case 'dropdown':
                        let options = '<option value="">Select...</option>';
                        if (field.options && field.options.length) {
                            field.options.forEach(opt => {
                                options += `<option>${this.escapeHtml(opt)}</option>`;
                            });
                        }
                        inputHtml = `<select class="mld-cf-select" disabled>${options}</select>`;
                        break;
                    case 'checkbox':
                        if (field.options && field.options.length) {
                            inputHtml = '<div class="mld-cf-checkbox-group">';
                            field.options.forEach(opt => {
                                inputHtml += `<label class="mld-cf-checkbox-label"><input type="checkbox" disabled> ${this.escapeHtml(opt)}</label>`;
                            });
                            inputHtml += '</div>';
                        }
                        break;
                    case 'radio':
                        if (field.options && field.options.length) {
                            inputHtml = '<div class="mld-cf-radio-group">';
                            field.options.forEach(opt => {
                                inputHtml += `<label class="mld-cf-radio-label"><input type="radio" disabled> ${this.escapeHtml(opt)}</label>`;
                            });
                            inputHtml += '</div>';
                        }
                        break;
                    case 'date':
                        inputHtml = `<input type="date" class="mld-cf-input" disabled>`;
                        break;
                }

                fieldsHtml += `
                    <div class="mld-cf-field ${widthClass}">
                        <label class="mld-cf-label">${this.escapeHtml(field.label)}${requiredMark}</label>
                        ${inputHtml}
                    </div>
                `;
            });

            const previewHtml = `
                <div id="mld-form-preview-overlay" style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.7); z-index: 100000; display: flex; align-items: center; justify-content: center;">
                    <div style="background: #fff; border-radius: 12px; max-width: 700px; width: 90%; max-height: 90vh; overflow: auto; box-shadow: 0 25px 50px rgba(0,0,0,0.3);">
                        <div style="padding: 20px 24px; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; background: #fff; z-index: 1;">
                            <h2 style="margin: 0; font-size: 18px; color: #1f2937;">Form Preview</h2>
                            <button id="mld-close-preview" style="background: none; border: none; cursor: pointer; font-size: 24px; color: #6b7280; line-height: 1;">&times;</button>
                        </div>
                        <div style="padding: 32px;">
                            <div class="mld-contact-form" style="background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 24px;">
                                <div class="mld-cf-fields" style="display: flex; flex-wrap: wrap; gap: 16px;">
                                    ${fieldsHtml}
                                </div>
                                <div style="margin-top: 24px;">
                                    <button type="button" class="mld-cf-submit" style="background: #0891B2; color: #fff; border: none; padding: 12px 32px; border-radius: 6px; font-size: 16px; font-weight: 500; cursor: pointer;">
                                        Send Message
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <style>
                    #mld-form-preview-overlay .mld-cf-field { box-sizing: border-box; }
                    #mld-form-preview-overlay .mld-cf-field-full { width: 100%; }
                    #mld-form-preview-overlay .mld-cf-field-half { width: calc(50% - 8px); }
                    #mld-form-preview-overlay .mld-cf-label { display: block; margin-bottom: 6px; font-weight: 500; color: #374151; font-size: 14px; }
                    #mld-form-preview-overlay .mld-cf-required { color: #ef4444; margin-left: 2px; }
                    #mld-form-preview-overlay .mld-cf-input,
                    #mld-form-preview-overlay .mld-cf-select,
                    #mld-form-preview-overlay .mld-cf-textarea { width: 100%; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; background: #fff; box-sizing: border-box; }
                    #mld-form-preview-overlay .mld-cf-checkbox-group,
                    #mld-form-preview-overlay .mld-cf-radio-group { display: flex; flex-direction: column; gap: 8px; }
                    #mld-form-preview-overlay .mld-cf-checkbox-label,
                    #mld-form-preview-overlay .mld-cf-radio-label { display: flex; align-items: center; gap: 8px; font-size: 14px; color: #374151; cursor: pointer; }
                    @media (max-width: 600px) {
                        #mld-form-preview-overlay .mld-cf-field-half { width: 100%; }
                    }
                </style>
            `;

            // Remove any existing preview
            $('#mld-form-preview-overlay').remove();

            // Add preview to body
            $('body').append(previewHtml);

            // Close button handler
            $('#mld-close-preview, #mld-form-preview-overlay').on('click', function(e) {
                if (e.target === this) {
                    $('#mld-form-preview-overlay').remove();
                }
            });

            // ESC key to close
            $(document).on('keydown.previewModal', function(e) {
                if (e.key === 'Escape') {
                    $('#mld-form-preview-overlay').remove();
                    $(document).off('keydown.previewModal');
                }
            });
        }

        copyToClipboard(text, $btn) {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(() => {
                    this.showCopiedFeedback($btn);
                });
            } else {
                const $temp = $('<input>');
                $('body').append($temp);
                $temp.val(text).select();
                document.execCommand('copy');
                $temp.remove();
                this.showCopiedFeedback($btn);
            }
        }

        showCopiedFeedback($btn) {
            $btn.addClass('copied');
            $btn.find('.dashicons').removeClass('dashicons-clipboard').addClass('dashicons-yes');

            setTimeout(() => {
                $btn.removeClass('copied');
                $btn.find('.dashicons').removeClass('dashicons-yes').addClass('dashicons-clipboard');
            }, 2000);
        }

        generateId() {
            return Math.random().toString(36).substring(2, 10);
        }

        escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    }

    // Initialize when document is ready
    $(document).ready(function() {
        if ($('#mld-form-builder').length) {
            window.mldFormBuilder = new MLDFormBuilder();
        }
    });

})(jQuery);
