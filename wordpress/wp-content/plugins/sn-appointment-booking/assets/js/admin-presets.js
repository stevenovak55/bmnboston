/**
 * Admin Presets JavaScript
 *
 * Handles preset management modal and AJAX operations.
 *
 * @package SN_Appointment_Booking
 * @since 1.2.0
 */

(function($) {
    'use strict';

    var SNABPresets = {
        /**
         * Initialize the module.
         */
        init: function() {
            this.bindEvents();
        },

        /**
         * Bind event handlers.
         */
        bindEvents: function() {
            // Add new preset button
            $(document).on('click', '#snab-add-preset', this.openAddModal);

            // Edit preset button
            $(document).on('click', '.snab-edit-preset', this.openEditModal);

            // Delete preset button
            $(document).on('click', '.snab-delete-preset', this.deletePreset);

            // Toggle preset active status
            $(document).on('click', '.snab-toggle-preset', this.togglePreset);

            // Copy shortcode button
            $(document).on('click', '.snab-copy-shortcode', this.copyShortcode);

            // Close modal
            $(document).on('click', '.snab-modal-close, .snab-modal-overlay', this.closeModal);

            // Auto-generate slug from name
            $(document).on('input', '#snab-preset-name', this.generateSlug);

            // Submit form (via form submit or save button click)
            $(document).on('submit', '#snab-preset-form', this.savePreset);
            $(document).on('click', '.snab-save-preset', function(e) {
                e.preventDefault();
                $('#snab-preset-form').trigger('submit');
            });

            // Prevent modal close when clicking inside
            $(document).on('click', '.snab-modal-content', function(e) {
                e.stopPropagation();
            });

            // ESC key to close modal
            $(document).on('keydown', function(e) {
                if (e.key === 'Escape') {
                    SNABPresets.closeModal();
                }
            });
        },

        /**
         * Open add preset modal.
         */
        openAddModal: function(e) {
            e.preventDefault();

            var $modal = $('#snab-preset-modal');
            var $form = $('#snab-preset-form');

            // Reset form
            $form[0].reset();
            $form.find('input[name="id"]').val('');

            // Update modal title and button text
            $modal.find('.snab-modal-title').text(snabPresets.i18n.addPreset);
            $modal.find('.snab-modal-submit').text(snabPresets.i18n.savePreset);

            // Uncheck all days (default to all days allowed)
            $form.find('input[name="allowed_days[]"]').prop('checked', false);

            // Show modal
            $modal.show();
            $('#snab-preset-name').focus();
        },

        /**
         * Open edit preset modal.
         */
        openEditModal: function(e) {
            e.preventDefault();

            var presetId = $(this).data('id');
            var $modal = $('#snab-preset-modal');
            var $form = $('#snab-preset-form');

            // Show loading state
            $modal.show().addClass('loading');

            // Fetch preset data
            $.ajax({
                url: snabPresets.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'snab_get_preset',
                    id: presetId,
                    nonce: snabPresets.nonce
                },
                success: function(response) {
                    $modal.removeClass('loading');

                    if (response.success) {
                        var preset = response.data.preset;

                        // Populate form
                        $form.find('input[name="id"]').val(preset.id);
                        $('#snab-preset-name').val(preset.name);
                        $('#snab-preset-slug').val(preset.slug);
                        $('#snab-preset-description').val(preset.description);
                        $('#snab-preset-start-hour').val(preset.start_hour || '');
                        $('#snab-preset-end-hour').val(preset.end_hour || '');
                        $('#snab-preset-weeks').val(preset.weeks_to_show);
                        $('#snab-preset-location').val(preset.default_location || '');
                        $('#snab-preset-title').val(preset.custom_title || '');
                        $('#snab-preset-css').val(preset.css_class || '');

                        // Handle appointment types (PHP returns array)
                        $form.find('input[name="appointment_types[]"]').prop('checked', false);
                        if (preset.appointment_types && Array.isArray(preset.appointment_types)) {
                            preset.appointment_types.forEach(function(typeId) {
                                $form.find('input[name="appointment_types[]"][value="' + typeId + '"]').prop('checked', true);
                            });
                        }

                        // Handle allowed days (PHP returns array)
                        $form.find('input[name="allowed_days[]"]').prop('checked', false);
                        if (preset.allowed_days && Array.isArray(preset.allowed_days)) {
                            preset.allowed_days.forEach(function(day) {
                                $form.find('input[name="allowed_days[]"][value="' + day + '"]').prop('checked', true);
                            });
                        }

                        // Update modal title and button
                        $modal.find('.snab-modal-title').text(snabPresets.i18n.editPreset);
                        $modal.find('.snab-modal-submit').text(snabPresets.i18n.updatePreset);

                        $('#snab-preset-name').focus();
                    } else {
                        alert(response.data || snabPresets.i18n.error);
                        SNABPresets.closeModal();
                    }
                },
                error: function() {
                    $modal.removeClass('loading');
                    alert(snabPresets.i18n.error);
                    SNABPresets.closeModal();
                }
            });
        },

        /**
         * Close modal.
         */
        closeModal: function() {
            $('#snab-preset-modal').hide().removeClass('loading');
        },

        /**
         * Generate slug from name.
         */
        generateSlug: function() {
            var $slug = $('#snab-preset-slug');
            var $name = $('#snab-preset-name');

            // Only auto-generate if slug is empty or matches previous auto-generated value
            if (!$slug.data('manual')) {
                var slug = $name.val()
                    .toLowerCase()
                    .replace(/[^a-z0-9]+/g, '-')
                    .replace(/^-|-$/g, '')
                    .substring(0, 50);

                $slug.val(slug);
            }
        },

        /**
         * Save preset.
         */
        savePreset: function(e) {
            e.preventDefault();

            var $form = $('#snab-preset-form');
            var $modal = $('#snab-preset-modal');
            var $submitBtn = $modal.find('.snab-save-preset');
            var originalText = $submitBtn.text();

            // Validate required fields
            var name = $('#snab-preset-name').val().trim();
            var slug = $('#snab-preset-slug').val().trim();

            if (!name || !slug) {
                alert('Name and slug are required.');
                return;
            }

            // Disable button and show loading
            $submitBtn.prop('disabled', true).text(snabPresets.i18n.saving);

            // Gather form data
            var formData = {
                action: 'snab_save_preset',
                nonce: snabPresets.nonce,
                id: $form.find('input[name="id"]').val(),
                name: name,
                slug: slug,
                description: $('#snab-preset-description').val(),
                appointment_types: [],
                allowed_days: [],
                start_hour: $('#snab-preset-start-hour').val(),
                end_hour: $('#snab-preset-end-hour').val(),
                weeks_to_show: $('#snab-preset-weeks').val(),
                default_location: $('#snab-preset-location').val(),
                custom_title: $('#snab-preset-title').val(),
                css_class: $('#snab-preset-css').val()
            };

            // Collect checked appointment types
            $form.find('input[name="appointment_types[]"]:checked').each(function() {
                formData.appointment_types.push($(this).val());
            });

            // Collect checked days
            $form.find('input[name="allowed_days[]"]:checked').each(function() {
                formData.allowed_days.push($(this).val());
            });

            $.ajax({
                url: snabPresets.ajaxUrl,
                type: 'POST',
                data: formData,
                success: function(response) {
                    $submitBtn.prop('disabled', false).text(originalText);

                    if (response.success) {
                        // Reload page to show updated data
                        window.location.reload();
                    } else {
                        alert(response.data || snabPresets.i18n.error);
                    }
                },
                error: function() {
                    $submitBtn.prop('disabled', false).text(originalText);
                    alert(snabPresets.i18n.error);
                }
            });
        },

        /**
         * Delete preset.
         */
        deletePreset: function(e) {
            e.preventDefault();

            if (!confirm(snabPresets.i18n.confirmDelete)) {
                return;
            }

            var $button = $(this);
            var $row = $button.closest('tr');
            var presetId = $button.data('id');

            $button.prop('disabled', true);

            $.ajax({
                url: snabPresets.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'snab_delete_preset',
                    id: presetId,
                    nonce: snabPresets.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $row.fadeOut(300, function() {
                            $(this).remove();

                            // Check if table is empty
                            if ($('.snab-presets-table tbody tr').length === 0) {
                                window.location.reload();
                            }
                        });
                    } else {
                        alert(response.data || snabPresets.i18n.error);
                        $button.prop('disabled', false);
                    }
                },
                error: function() {
                    alert(snabPresets.i18n.error);
                    $button.prop('disabled', false);
                }
            });
        },

        /**
         * Toggle preset active status.
         */
        togglePreset: function(e) {
            e.preventDefault();

            var $button = $(this);
            var presetId = $button.data('id');
            var $statusCell = $button.closest('tr').find('.column-status .snab-status');

            $button.prop('disabled', true);

            $.ajax({
                url: snabPresets.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'snab_toggle_preset',
                    id: presetId,
                    nonce: snabPresets.nonce
                },
                success: function(response) {
                    $button.prop('disabled', false);

                    if (response.success) {
                        // Update status badge
                        if (response.data.is_active) {
                            $statusCell.removeClass('snab-status-inactive').addClass('snab-status-active').text('Active');
                        } else {
                            $statusCell.removeClass('snab-status-active').addClass('snab-status-inactive').text('Inactive');
                        }
                    } else {
                        alert(response.data || snabPresets.i18n.error);
                    }
                },
                error: function() {
                    $button.prop('disabled', false);
                    alert(snabPresets.i18n.error);
                }
            });
        },

        /**
         * Copy shortcode to clipboard.
         */
        copyShortcode: function(e) {
            e.preventDefault();

            var shortcode = $(this).data('shortcode');
            var $button = $(this);

            // Create temporary input
            var $temp = $('<input>');
            $('body').append($temp);
            $temp.val(shortcode).select();
            document.execCommand('copy');
            $temp.remove();

            // Show feedback
            var originalText = $button.text();
            $button.text(snabPresets.i18n.copied);

            setTimeout(function() {
                $button.text(originalText);
            }, 2000);
        }
    };

    // Initialize on document ready
    $(document).ready(function() {
        SNABPresets.init();
    });

})(jQuery);
