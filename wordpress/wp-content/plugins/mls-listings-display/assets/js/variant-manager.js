/**
 * Variant Manager
 * Handles A/B testing prompt variant management
 *
 * @since 6.9.0
 */

(function($) {
    'use strict';

    const VariantManager = {
        init: function() {
            this.bindEvents();
        },

        bindEvents: function() {
            // Add variant button
            $('#add-variant-btn').on('click', () => this.openAddModal());

            // Edit variant button
            $('.edit-variant').on('click', (e) => {
                e.preventDefault();
                const variantId = $(e.currentTarget).data('variant-id');
                this.openEditModal(variantId);
            });

            // Delete variant button
            $('.delete-variant').on('click', (e) => {
                e.preventDefault();
                const variantId = $(e.currentTarget).data('variant-id');
                this.deleteVariant(variantId);
            });

            // Toggle variant status
            $('.variant-toggle').on('change', (e) => {
                const variantId = $(e.currentTarget).data('variant-id');
                const isActive = $(e.currentTarget).is(':checked') ? 1 : 0;
                this.toggleVariant(variantId, isActive);
            });

            // Update variant weight (debounced)
            let weightTimeout;
            $('.variant-weight').on('input', (e) => {
                clearTimeout(weightTimeout);
                const variantId = $(e.currentTarget).data('variant-id');
                const weight = $(e.currentTarget).val();

                weightTimeout = setTimeout(() => {
                    this.updateWeight(variantId, weight);
                }, 1000); // Wait 1 second after user stops typing
            });

            // Modal close button
            $('#variant-modal .close-modal').on('click', () => this.closeModal());

            // Modal cancel button
            $('#cancel-variant-btn').on('click', () => this.closeModal());

            // Modal save button
            $('#save-variant-btn').on('click', (e) => {
                e.preventDefault();
                this.saveVariant();
            });

            // Close modal on outside click
            $('#variant-modal').on('click', (e) => {
                if ($(e.target).is('#variant-modal')) {
                    this.closeModal();
                }
            });
        },

        openAddModal: function() {
            $('#variant-modal-title').text('Add Prompt Variant');
            $('#variant-id').val('');
            $('#variant-name').val('');
            $('#variant-prompt').val('');
            $('#variant-weight-input').val(50);
            $('#variant-modal').fadeIn(200);
        },

        openEditModal: function(variantId) {
            // Show loading state
            $('#variant-modal-title').text('Loading...');
            $('#variant-modal').fadeIn(200);

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'mld_get_variant',
                    variant_id: variantId,
                    nonce: mldChatbot.nonce
                },
                success: (response) => {
                    if (response.success) {
                        const variant = response.data.variant;
                        $('#variant-modal-title').text('Edit Prompt Variant');
                        $('#variant-id').val(variant.id);
                        $('#variant-name').val(variant.variant_name);
                        $('#variant-prompt').val(variant.prompt_content);
                        $('#variant-weight-input').val(variant.weight);
                    } else {
                        this.showNotice(response.data.message, 'error');
                        this.closeModal();
                    }
                },
                error: () => {
                    this.showNotice('Failed to load variant data', 'error');
                    this.closeModal();
                }
            });
        },

        closeModal: function() {
            $('#variant-modal').fadeOut(200);
        },

        saveVariant: function() {
            const variantId = $('#variant-id').val();
            const variantName = $('#variant-name').val().trim();
            const promptContent = $('#variant-prompt').val().trim();
            const weight = $('#variant-weight-input').val();

            // Validate
            if (!variantName) {
                this.showNotice('Variant name is required', 'error');
                return;
            }

            if (!promptContent) {
                this.showNotice('Prompt content is required', 'error');
                return;
            }

            // Disable save button and show loading
            $('#save-variant-btn').prop('disabled', true).text('Saving...');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'mld_save_variant',
                    variant_id: variantId,
                    variant_name: variantName,
                    prompt_content: promptContent,
                    weight: weight,
                    nonce: mldChatbot.nonce
                },
                success: (response) => {
                    if (response.success) {
                        this.showNotice(response.data.message, 'success');
                        this.closeModal();
                        // Reload page to show updated variant list
                        setTimeout(() => location.reload(), 1000);
                    } else {
                        this.showNotice(response.data.message, 'error');
                    }
                },
                error: () => {
                    this.showNotice('Failed to save variant', 'error');
                },
                complete: () => {
                    $('#save-variant-btn').prop('disabled', false).text('Save Variant');
                }
            });
        },

        deleteVariant: function(variantId) {
            if (!confirm('Are you sure you want to delete this variant? This action cannot be undone.')) {
                return;
            }

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'mld_delete_variant',
                    variant_id: variantId,
                    nonce: mldChatbot.nonce
                },
                success: (response) => {
                    if (response.success) {
                        this.showNotice(response.data.message, 'success');
                        // Remove row from table
                        $(`tr[data-variant-id="${variantId}"]`).fadeOut(300, function() {
                            $(this).remove();
                        });
                    } else {
                        this.showNotice(response.data.message, 'error');
                    }
                },
                error: () => {
                    this.showNotice('Failed to delete variant', 'error');
                }
            });
        },

        toggleVariant: function(variantId, isActive) {
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'mld_toggle_variant',
                    variant_id: variantId,
                    is_active: isActive,
                    nonce: mldChatbot.nonce
                },
                success: (response) => {
                    if (response.success) {
                        this.showNotice(response.data.message, 'success');
                        // Update status badge
                        const badge = $(`tr[data-variant-id="${variantId}"] .variant-status`);
                        if (isActive) {
                            badge.removeClass('inactive').addClass('active').text('Active');
                        } else {
                            badge.removeClass('active').addClass('inactive').text('Inactive');
                        }
                    } else {
                        this.showNotice(response.data.message, 'error');
                        // Revert toggle
                        $(`.variant-toggle[data-variant-id="${variantId}"]`).prop('checked', !isActive);
                    }
                },
                error: () => {
                    this.showNotice('Failed to update variant status', 'error');
                    // Revert toggle
                    $(`.variant-toggle[data-variant-id="${variantId}"]`).prop('checked', !isActive);
                }
            });
        },

        updateWeight: function(variantId, weight) {
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'mld_update_variant_weight',
                    variant_id: variantId,
                    weight: weight,
                    nonce: mldChatbot.nonce
                },
                success: (response) => {
                    if (response.success) {
                        this.showNotice('Weight updated', 'success', 2000);
                    } else {
                        this.showNotice(response.data.message, 'error');
                    }
                },
                error: () => {
                    this.showNotice('Failed to update weight', 'error');
                }
            });
        },

        showNotice: function(message, type = 'info', duration = 4000) {
            // Remove existing notices
            $('.mld-admin-notice').remove();

            const noticeClass = type === 'error' ? 'notice-error' :
                               type === 'success' ? 'notice-success' : 'notice-info';

            const notice = $(`
                <div class="notice ${noticeClass} is-dismissible mld-admin-notice">
                    <p>${message}</p>
                </div>
            `);

            // Insert after page title
            $('.wrap h1').first().after(notice);

            // Auto-dismiss after duration
            setTimeout(() => {
                notice.fadeOut(300, function() {
                    $(this).remove();
                });
            }, duration);

            // Make dismissible
            notice.on('click', '.notice-dismiss', function() {
                notice.fadeOut(300, function() {
                    $(this).remove();
                });
            });
        }
    };

    // Initialize on document ready
    $(document).ready(() => {
        VariantManager.init();
    });

})(jQuery);
