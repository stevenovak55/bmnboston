/**
 * AI Chatbot Settings Admin JavaScript
 *
 * @package MLS_Listings_Display
 * @since 6.6.0
 */

(function($) {
    'use strict';

    $(document).ready(function() {

        // Provider selection - show/hide provider configs
        $('.mld-provider-select').on('change', function() {
            var provider = $(this).val();
            $('.mld-provider-config').hide();
            $('.mld-provider-' + provider).show();
        });

        // Test AI connection
        $('.mld-test-connection').on('click', function() {
            var $button = $(this);
            var provider = $button.data('provider');
            var $statusDiv = $button.siblings('.mld-connection-status');

            // Try to find status div - it might be in different locations depending on context
            if ($statusDiv.length === 0) {
                $statusDiv = $button.closest('.mld-provider-card').find('.mld-connection-status');
            }
            if ($statusDiv.length === 0) {
                $statusDiv = $button.closest('td').find('.mld-connection-status');
            }

            var apiKey = $('#' + provider + '_api_key').val();
            var model = $('#' + provider + '_model').val();

            // For test provider, no API key needed
            // For other providers, we'll use stored key if input is empty
            var useStoredKey = (provider !== 'test' && !apiKey);

            $button.prop('disabled', true);
            var originalText = $button.text();
            $button.text(mldChatbot.strings.testingConnection);
            $statusDiv.removeClass('success error').html('');

            $.ajax({
                url: mldChatbot.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'mld_test_ai_connection',
                    nonce: mldChatbot.nonce,
                    provider: provider,
                    api_key: apiKey,
                    model: model,
                    use_stored_key: useStoredKey ? 1 : 0
                },
                success: function(response) {
                    if (response.success) {
                        $statusDiv.addClass('success').html('✓ ' + mldChatbot.strings.connectionSuccess + ' (Model: ' + response.data.model + ')');
                    } else {
                        $statusDiv.addClass('error').html('✗ ' + mldChatbot.strings.connectionFailed + ': ' + response.data.message);
                    }
                },
                error: function() {
                    $statusDiv.addClass('error').html('✗ Connection test failed');
                },
                complete: function() {
                    $button.prop('disabled', false).text(originalText);
                }
            });
        });

        // Auto-save settings on change
        $('.mld-setting-field').on('change', function() {
            var $field = $(this);
            var key = $field.data('setting-key');
            var value = $field.is(':checkbox') ? ($field.is(':checked') ? '1' : '0') : $field.val();
            var category = $field.data('category');

            // Handle routing settings specially - save full config
            if (category === 'routing') {
                saveRoutingConfig();
            } else {
                saveSetting(key, value, category, $field);
            }
        });

        // Save full routing configuration
        function saveRoutingConfig() {
            var routingEnabled = $('#routing_enabled').is(':checked');
            var costOptimization = $('#cost_optimization').is(':checked');
            var fallbackEnabled = $('#fallback_enabled').is(':checked');

            $.ajax({
                url: mldChatbot.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'mld_save_routing_config',
                    nonce: mldChatbot.nonce,
                    routing_enabled: routingEnabled ? 1 : 0,
                    cost_optimization: costOptimization ? 1 : 0,
                    fallback_enabled: fallbackEnabled ? 1 : 0
                },
                success: function(response) {
                    if (response.success) {
                        showNotice('success', 'Routing configuration saved');
                    } else {
                        showNotice('error', 'Failed to save: ' + response.data.message);
                    }
                },
                error: function() {
                    showNotice('error', 'Save failed');
                }
            });
        }

        // Refresh models from API
        $('.mld-refresh-models').on('click', function() {
            var $button = $(this);
            var provider = $button.data('provider') || 'all';
            var originalText = $button.html();

            $button.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> Refreshing...');

            $.ajax({
                url: mldChatbot.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'mld_refresh_models',
                    nonce: mldChatbot.nonce,
                    provider: provider
                },
                success: function(response) {
                    if (response.success) {
                        showNotice('success', response.data.message);
                        // Reload page after short delay to show updated models
                        setTimeout(function() {
                            location.reload();
                        }, 1500);
                    } else {
                        showNotice('error', response.data.message);
                        $button.prop('disabled', false).html(originalText);
                    }
                },
                error: function() {
                    showNotice('error', 'Failed to refresh models');
                    $button.prop('disabled', false).html(originalText);
                }
            });
        });

        function saveSetting(key, value, category, $field) {
            $.ajax({
                url: mldChatbot.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'mld_save_chatbot_setting',
                    nonce: mldChatbot.nonce,
                    key: key,
                    value: value,
                    category: category
                },
                success: function(response) {
                    if (response.success) {
                        showNotice('success', mldChatbot.strings.saved);
                    } else {
                        showNotice('error', 'Failed to save: ' + response.data.message);
                    }
                },
                error: function() {
                    showNotice('error', 'Save failed');
                }
            });
        }

        // Run knowledge scan
        $('.mld-run-scan').on('click', function() {
            var $button = $(this);
            $button.prop('disabled', true).text(mldChatbot.strings.scanningWebsite);

            $.ajax({
                url: mldChatbot.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'mld_run_knowledge_scan',
                    nonce: mldChatbot.nonce
                },
                success: function(response) {
                    if (response.success) {
                        showNotice('success', mldChatbot.strings.scanComplete);
                        location.reload();
                    } else {
                        showNotice('error', response.data.message);
                    }
                },
                error: function() {
                    showNotice('error', 'Scan failed');
                },
                complete: function() {
                    $button.prop('disabled', false).text('Run Scan Now');
                }
            });
        });

        // FAQ Management
        var $faqModal = $('#mld-faq-modal');
        var $faqForm = $('#mld-faq-form');

        // Add new FAQ
        $('.mld-add-faq').on('click', function() {
            $('#mld-faq-modal-title').text('Add FAQ Entry');
            $faqForm[0].reset();
            $('#faq_id').val('0');
            $faqModal.show();
        });

        // Edit FAQ
        $(document).on('click', '.mld-edit-faq', function() {
            var faqId = $(this).data('faq-id');

            // Load FAQ data via AJAX
            $.ajax({
                url: mldChatbot.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'mld_get_faq',
                    nonce: mldChatbot.nonce,
                    faq_id: faqId
                },
                success: function(response) {
                    if (response.success && response.data) {
                        var faq = response.data;
                        $('#mld-faq-modal-title').text('Edit FAQ Entry');
                        $('#faq_id').val(faqId);
                        $('#faq_question').val(faq.question);
                        $('#faq_answer').val(faq.answer);
                        $('#faq_keywords').val(faq.keywords);
                        $('#faq_category').val(faq.category);
                        $('#faq_priority').val(faq.priority);
                        $faqModal.show();
                    } else {
                        showNotice('error', 'Failed to load FAQ: ' + (response.data || 'Unknown error'));
                    }
                },
                error: function() {
                    showNotice('error', 'Failed to load FAQ data');
                }
            });
        });

        // Delete FAQ
        $(document).on('click', '.mld-delete-faq', function() {
            if (!confirm('Are you sure you want to delete this FAQ?')) {
                return;
            }

            var faqId = $(this).data('faq-id');
            var $row = $(this).closest('tr');

            $.ajax({
                url: mldChatbot.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'mld_delete_faq',
                    nonce: mldChatbot.nonce,
                    faq_id: faqId
                },
                success: function(response) {
                    if (response.success) {
                        $row.fadeOut(function() {
                            $(this).remove();
                        });
                        showNotice('success', 'FAQ deleted');
                    } else {
                        showNotice('error', response.data.message);
                    }
                }
            });
        });

        // Save FAQ
        $faqForm.on('submit', function(e) {
            e.preventDefault();

            var formData = {
                action: 'mld_save_faq',
                nonce: mldChatbot.nonce,
                faq_id: $('#faq_id').val(),
                question: $('#faq_question').val(),
                answer: $('#faq_answer').val(),
                keywords: $('#faq_keywords').val(),
                category: $('#faq_category').val(),
                priority: $('#faq_priority').val()
            };

            $.ajax({
                url: mldChatbot.ajaxUrl,
                type: 'POST',
                data: formData,
                success: function(response) {
                    if (response.success) {
                        showNotice('success', 'FAQ saved');
                        $faqModal.hide();
                        location.reload();
                    } else {
                        showNotice('error', response.data.message);
                    }
                }
            });
        });

        // Close modal
        $('.mld-modal-close').on('click', function() {
            $faqModal.hide();
        });

        // Close modal on outside click
        $(window).on('click', function(e) {
            if ($(e.target).is('.mld-modal')) {
                $faqModal.hide();
            }
        });

        // Helper: Show admin notice
        function showNotice(type, message) {
            var $notice = $('<div class="notice notice-' + type + ' is-dismissible"><p>' + message + '</p></div>');
            $('.mld-chatbot-settings h1').after($notice);

            setTimeout(function() {
                $notice.fadeOut(function() {
                    $(this).remove();
                });
            }, 3000);
        }
    });

})(jQuery);
