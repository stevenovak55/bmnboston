/**
 * SN Appointment Booking - Admin Types JavaScript
 *
 * Handles appointment types CRUD operations in admin.
 *
 * @package SN_Appointment_Booking
 * @since 1.0.0
 */

(function($) {
    'use strict';

    // Main Types Admin object
    var SNABTypes = {
        // Cache DOM elements
        $typeModal: null,
        $deleteModal: null,
        $typeForm: null,
        $typesList: null,
        $notice: null,

        /**
         * Initialize the types admin.
         */
        init: function() {
            this.cacheElements();
            this.bindEvents();
            this.initSortable();
            this.initColorPicker();
        },

        /**
         * Cache DOM elements for better performance.
         */
        cacheElements: function() {
            this.$typeModal = $('#snab-type-modal');
            this.$deleteModal = $('#snab-delete-modal');
            this.$typeForm = $('#snab-type-form');
            this.$typesList = $('#snab-types-list');
            this.$notice = $('#snab-types-notice');
        },

        /**
         * Bind event handlers.
         */
        bindEvents: function() {
            var self = this;

            // Add new type button
            $(document).on('click', '.snab-add-type-btn', function(e) {
                e.preventDefault();
                self.openAddModal();
            });

            // Edit type button
            $(document).on('click', '.snab-edit-type', function(e) {
                e.preventDefault();
                var typeId = $(this).data('type-id');
                self.openEditModal(typeId);
            });

            // Delete type button
            $(document).on('click', '.snab-delete-type', function(e) {
                e.preventDefault();
                var typeId = $(this).data('type-id');
                self.openDeleteModal(typeId);
            });

            // Toggle status button
            $(document).on('click', '.snab-toggle-status', function(e) {
                e.preventDefault();
                var typeId = $(this).data('type-id');
                self.toggleStatus(typeId, $(this));
            });

            // Modal close buttons
            $(document).on('click', '.snab-modal-close, .snab-modal-cancel, .snab-modal-overlay', function(e) {
                e.preventDefault();
                self.closeModals();
            });

            // Stop propagation on modal content
            $(document).on('click', '.snab-modal-content', function(e) {
                e.stopPropagation();
            });

            // Form submission
            this.$typeForm.on('submit', function(e) {
                e.preventDefault();
                self.saveType();
            });

            // Confirm delete button
            $(document).on('click', '#snab-confirm-delete-btn', function(e) {
                e.preventDefault();
                var typeId = $('#snab-delete-type-id').val();
                self.deleteType(typeId);
            });

            // Color picker sync
            $(document).on('input', '#snab-type-color', function() {
                var color = $(this).val();
                $('#snab-type-color-text').val(color);
                $('#snab-color-preview').css('background-color', color);
            });

            $(document).on('input', '#snab-type-color-text', function() {
                var color = $(this).val();
                if (/^#[0-9A-Fa-f]{6}$/.test(color)) {
                    $('#snab-type-color').val(color);
                    $('#snab-color-preview').css('background-color', color);
                }
            });

            // Auto-generate slug from name
            $(document).on('blur', '#snab-type-name', function() {
                var $slug = $('#snab-type-slug');
                if ($slug.val() === '') {
                    var name = $(this).val();
                    var slug = self.generateSlug(name);
                    $slug.attr('placeholder', slug);
                }
            });

            // ESC key to close modals
            $(document).on('keydown', function(e) {
                if (e.key === 'Escape') {
                    self.closeModals();
                }
            });
        },

        /**
         * Initialize sortable for drag and drop ordering.
         */
        initSortable: function() {
            var self = this;

            if (typeof $.fn.sortable !== 'undefined') {
                this.$typesList.sortable({
                    handle: '.snab-drag-handle',
                    items: '.snab-type-row',
                    placeholder: 'snab-sortable-placeholder',
                    update: function(event, ui) {
                        self.updateOrder();
                    }
                });
            }
        },

        /**
         * Initialize color picker preview.
         */
        initColorPicker: function() {
            var color = $('#snab-type-color').val();
            $('#snab-color-preview').css('background-color', color);
        },

        /**
         * Open add type modal.
         */
        openAddModal: function() {
            this.resetForm();
            $('#snab-modal-title').text(snabAdmin.i18n.addType || 'Add Appointment Type');
            $('#snab-save-type-btn .snab-btn-text').text(snabAdmin.i18n.saveType || 'Save Type');
            this.$typeModal.fadeIn(200);
            $('#snab-type-name').focus();
        },

        /**
         * Open edit type modal.
         *
         * @param {number} typeId The type ID to edit.
         */
        openEditModal: function(typeId) {
            var self = this;

            this.showLoading(true);

            $.ajax({
                url: snabAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'snab_get_type',
                    snab_nonce: snabAdmin.nonce,
                    type_id: typeId
                },
                success: function(response) {
                    self.showLoading(false);

                    if (response.success) {
                        self.populateForm(response.data.type);
                        $('#snab-modal-title').text(snabAdmin.i18n.editType || 'Edit Appointment Type');
                        $('#snab-save-type-btn .snab-btn-text').text(snabAdmin.i18n.updateType || 'Update Type');
                        self.$typeModal.fadeIn(200);
                        $('#snab-type-name').focus();
                    } else {
                        self.showNotice(response.data.message, 'error');
                    }
                },
                error: function() {
                    self.showLoading(false);
                    self.showNotice(snabAdmin.i18n.error || 'An error occurred.', 'error');
                }
            });
        },

        /**
         * Open delete confirmation modal.
         *
         * @param {number} typeId The type ID to delete.
         */
        openDeleteModal: function(typeId) {
            $('#snab-delete-type-id').val(typeId);
            this.$deleteModal.fadeIn(200);
        },

        /**
         * Close all modals.
         */
        closeModals: function() {
            this.$typeModal.fadeOut(200);
            this.$deleteModal.fadeOut(200);
        },

        /**
         * Reset the type form.
         */
        resetForm: function() {
            this.$typeForm[0].reset();
            $('#snab-type-id').val('');
            $('#snab-type-slug').attr('placeholder', snabAdmin.i18n.autoGenerated || 'Auto-generated from name');
            $('#snab-type-color').val('#3788d8');
            $('#snab-type-color-text').val('#3788d8');
            $('#snab-color-preview').css('background-color', '#3788d8');
            $('#snab-type-duration').val(60);
            $('#snab-type-buffer-before').val(0);
            $('#snab-type-buffer-after').val(15);
            $('#snab-type-active').prop('checked', true);
            $('#snab-type-requires-approval').prop('checked', false);
            $('#snab-type-requires-login').prop('checked', false);
        },

        /**
         * Populate the form with type data.
         *
         * @param {object} type The type data.
         */
        populateForm: function(type) {
            $('#snab-type-id').val(type.id);
            $('#snab-type-name').val(type.name);
            $('#snab-type-slug').val(type.slug);
            $('#snab-type-description').val(type.description);
            $('#snab-type-duration').val(type.duration_minutes);
            $('#snab-type-buffer-before').val(type.buffer_before_minutes);
            $('#snab-type-buffer-after').val(type.buffer_after_minutes);
            $('#snab-type-color').val(type.color);
            $('#snab-type-color-text').val(type.color);
            $('#snab-color-preview').css('background-color', type.color);
            $('#snab-type-active').prop('checked', type.is_active == 1);
            $('#snab-type-requires-approval').prop('checked', type.requires_approval == 1);
            $('#snab-type-requires-login').prop('checked', type.requires_login == 1);
        },

        /**
         * Save type (create or update).
         */
        saveType: function() {
            var self = this;
            var $btn = $('#snab-save-type-btn');

            $btn.prop('disabled', true);
            $btn.find('.snab-btn-text').hide();
            $btn.find('.snab-spinner').show();

            $.ajax({
                url: snabAdmin.ajaxUrl,
                type: 'POST',
                data: this.$typeForm.serialize() + '&action=snab_save_type',
                success: function(response) {
                    $btn.prop('disabled', false);
                    $btn.find('.snab-btn-text').show();
                    $btn.find('.snab-spinner').hide();

                    if (response.success) {
                        self.closeModals();
                        self.showNotice(response.data.message, 'success');

                        if (response.data.is_new) {
                            // Add new row
                            var $noTypes = self.$typesList.find('.snab-no-types');
                            if ($noTypes.length) {
                                $noTypes.remove();
                            }
                            self.$typesList.append(response.data.row_html);
                        } else {
                            // Update existing row
                            var $existingRow = self.$typesList.find('tr[data-type-id="' + response.data.type_id + '"]');
                            $existingRow.replaceWith(response.data.row_html);
                        }

                        // Re-init sortable
                        self.initSortable();
                    } else {
                        self.showNotice(response.data.message, 'error');
                    }
                },
                error: function() {
                    $btn.prop('disabled', false);
                    $btn.find('.snab-btn-text').show();
                    $btn.find('.snab-spinner').hide();
                    self.showNotice(snabAdmin.i18n.error || 'An error occurred.', 'error');
                }
            });
        },

        /**
         * Delete type.
         *
         * @param {number} typeId The type ID to delete.
         */
        deleteType: function(typeId) {
            var self = this;
            var $btn = $('#snab-confirm-delete-btn');

            $btn.prop('disabled', true);

            $.ajax({
                url: snabAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'snab_delete_type',
                    snab_nonce: snabAdmin.nonce,
                    type_id: typeId
                },
                success: function(response) {
                    $btn.prop('disabled', false);

                    if (response.success) {
                        self.closeModals();
                        self.showNotice(response.data.message, 'success');

                        // Remove the row
                        self.$typesList.find('tr[data-type-id="' + typeId + '"]').fadeOut(300, function() {
                            $(this).remove();

                            // Show no types message if empty
                            if (self.$typesList.find('.snab-type-row').length === 0) {
                                self.$typesList.append('<tr class="snab-no-types"><td colspan="7">' + (snabAdmin.i18n.noTypes || 'No appointment types found.') + '</td></tr>');
                            }
                        });
                    } else {
                        self.showNotice(response.data.message, 'error');
                    }
                },
                error: function() {
                    $btn.prop('disabled', false);
                    self.showNotice(snabAdmin.i18n.error || 'An error occurred.', 'error');
                }
            });
        },

        /**
         * Toggle type status.
         *
         * @param {number} typeId The type ID.
         * @param {jQuery} $btn The toggle button element.
         */
        toggleStatus: function(typeId, $btn) {
            var self = this;

            $btn.prop('disabled', true);

            $.ajax({
                url: snabAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'snab_toggle_type_status',
                    snab_nonce: snabAdmin.nonce,
                    type_id: typeId
                },
                success: function(response) {
                    $btn.prop('disabled', false);

                    if (response.success) {
                        self.showNotice(response.data.message, 'success');

                        // Update button state
                        if (response.data.new_status) {
                            $btn.removeClass('is-inactive').addClass('is-active');
                            $btn.attr('title', snabAdmin.i18n.clickToDeactivate || 'Click to deactivate');
                            $btn.find('.dashicons').removeClass('dashicons-marker').addClass('dashicons-yes-alt');
                        } else {
                            $btn.removeClass('is-active').addClass('is-inactive');
                            $btn.attr('title', snabAdmin.i18n.clickToActivate || 'Click to activate');
                            $btn.find('.dashicons').removeClass('dashicons-yes-alt').addClass('dashicons-marker');
                        }
                    } else {
                        self.showNotice(response.data.message, 'error');
                    }
                },
                error: function() {
                    $btn.prop('disabled', false);
                    self.showNotice(snabAdmin.i18n.error || 'An error occurred.', 'error');
                }
            });
        },

        /**
         * Update types order after drag and drop.
         */
        updateOrder: function() {
            var self = this;
            var order = [];

            this.$typesList.find('.snab-type-row').each(function(index) {
                var typeId = $(this).data('type-id');
                order.push(typeId);
                $(this).find('.snab-sort-order').text(index);
            });

            $.ajax({
                url: snabAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'snab_update_type_order',
                    snab_nonce: snabAdmin.nonce,
                    order: order
                },
                success: function(response) {
                    if (response.success) {
                        self.showNotice(response.data.message, 'success', 2000);
                    } else {
                        self.showNotice(response.data.message, 'error');
                    }
                },
                error: function() {
                    self.showNotice(snabAdmin.i18n.error || 'An error occurred.', 'error');
                }
            });
        },

        /**
         * Show a notice message.
         *
         * @param {string} message The message to show.
         * @param {string} type The notice type (success, error, warning).
         * @param {number} duration Auto-hide duration in ms (0 for no auto-hide).
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
         * Show/hide loading state.
         *
         * @param {boolean} show Whether to show loading.
         */
        showLoading: function(show) {
            if (show) {
                $('body').addClass('snab-loading-active');
            } else {
                $('body').removeClass('snab-loading-active');
            }
        },

        /**
         * Generate a slug from a string.
         *
         * @param {string} str The string to convert.
         * @return {string} The generated slug.
         */
        generateSlug: function(str) {
            return str
                .toLowerCase()
                .trim()
                .replace(/[^\w\s-]/g, '')
                .replace(/[\s_-]+/g, '-')
                .replace(/^-+|-+$/g, '');
        }
    };

    // Initialize when document is ready
    $(document).ready(function() {
        // Only initialize on types page
        if ($('#snab-types-table').length) {
            SNABTypes.init();
        }
    });

})(jQuery);
