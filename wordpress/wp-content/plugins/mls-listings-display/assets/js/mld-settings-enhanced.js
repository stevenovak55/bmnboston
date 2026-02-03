/**
 * Enhanced settings page JavaScript functionality
 * Handles copy to clipboard and quick page creation
 */

(function($) {
    'use strict';

    // Initialize MLDLogger fallback if not available
    if (typeof window.MLDLogger === 'undefined') {
        window.MLDLogger = {
            debug: function() {},
            info: function() {},
            warning: function() {},
            error: function() {}
        };
    }

    $(document).ready(function() {

        // Copy to clipboard functionality
        $('.mld-copy-shortcode').on('click', function(e) {
            e.preventDefault();

            var $button = $(this);
            var shortcode = $button.data('shortcode');
            var originalText = $button.text();

            // Create temporary textarea to copy text
            var $temp = $('<textarea>');
            $('body').append($temp);
            $temp.val(shortcode).select();

            try {
                document.execCommand('copy');
                $button.text('Copied!').addClass('copied');

                setTimeout(function() {
                    $button.text(originalText).removeClass('copied');
                }, 2000);
            } catch(err) {
                MLDLogger.error('Failed to copy: ', err);
                $button.text('Failed').addClass('error');

                setTimeout(function() {
                    $button.text(originalText).removeClass('error');
                }, 2000);
            }

            $temp.remove();
        });

        // Quick page creation
        $('.mld-create-page').on('click', function(e) {
            e.preventDefault();

            var $button = $(this);
            var title = $button.data('title');
            var shortcode = $button.data('shortcode');

            // Confirm action
            if (!confirm('Create a new page titled "' + title + '"?')) {
                return;
            }

            // Disable button and show loading
            $button.prop('disabled', true).text('Creating...');

            // Determine AJAX URL - ajaxurl is a global in WP admin
            var ajax_url = (typeof ajaxurl !== 'undefined' ? ajaxurl : (mld_admin.ajax_url || '/wp-admin/admin-ajax.php'));

            // Check if mld_admin object exists
            if (typeof mld_admin === 'undefined' || !mld_admin.nonce) {
                MLDLogger.error('Admin object not properly initialized');
                showNotice('Configuration error. Please refresh the page and try again.', 'error');
                $button.prop('disabled', false).text('Create Page');
                return;
            }

            MLDLogger.debug('AJAX Configuration:', {
                action: 'mld_create_page',
                title: title,
                shortcode: shortcode,
                nonce: mld_admin.nonce,
                ajax_url: ajax_url,
                is_admin: mld_admin.is_admin,
                ajaxurl_defined: typeof ajaxurl !== 'undefined',
                using_global: typeof ajaxurl !== 'undefined' ? 'yes' : 'no'
            });

            // Send AJAX request with credentials
            $.ajax({
                url: ajax_url,
                type: 'POST',
                data: {
                    action: 'mld_create_page',
                    title: title,
                    shortcode: shortcode,
                    nonce: mld_admin.nonce
                },
                xhrFields: {
                    withCredentials: true
                },
                credentials: 'same-origin'
            })
            .done(function(response) {
                MLDLogger.debug('Response:', response);
                if (response.success) {
                    // Update button to show success
                    $button.text('Created!').addClass('success');

                    // Show success message with links
                    var message = response.data.message +
                        ' <a href="' + response.data.page_url + '" target="_blank">View Page</a> | ' +
                        '<a href="' + response.data.edit_url + '">Edit Page</a>';

                    showNotice(message, 'success');

                    // Disable button permanently for this page
                    setTimeout(function() {
                        $button.text('Page Created').prop('disabled', true);
                    }, 2000);
                } else {
                    // Show error with debug info
                    MLDLogger.error('Create page error:', response);
                    if (response.data && response.data.debug) {
                        MLDLogger.debug('Debug info:', response.data.debug);
                    }
                    var errorMsg = response.data && response.data.message ? response.data.message : 'Failed to create page';
                    showNotice(errorMsg, 'error');

                    // Reset button
                    $button.prop('disabled', false).text('Create Page');
                }
            })
            .fail(function(jqXHR, textStatus, errorThrown) {
                MLDLogger.error('AJAX Error:', textStatus, errorThrown, jqXHR.responseText);
                showNotice('Network error: ' + textStatus + '. Please try again.', 'error');
                $button.prop('disabled', false).text('Create Page');
            });
        });

        // Tab switching functionality
        $('.mld-tabs a').on('click', function(e) {
            e.preventDefault();

            var $tab = $(this);
            var target = $tab.attr('href');

            // Update active tab
            $('.mld-tabs a').removeClass('nav-tab-active');
            $tab.addClass('nav-tab-active');

            // Show corresponding content
            $('.mld-tab-content').removeClass('active');
            $(target).addClass('active');

            // Save active tab in localStorage
            if (typeof(Storage) !== "undefined") {
                localStorage.setItem('mld_active_shortcode_tab', target);
            }
        });

        // Restore last active tab from localStorage
        if (typeof(Storage) !== "undefined") {
            var lastTab = localStorage.getItem('mld_active_shortcode_tab');
            if (lastTab && $(lastTab).length) {
                $('.mld-tabs a[href="' + lastTab + '"]').trigger('click');
            }
        }

        // Search/filter shortcodes
        $('#mld-shortcode-search').on('keyup', function() {
            var searchTerm = $(this).val().toLowerCase();

            if (searchTerm === '') {
                $('.shortcode-item').show();
            } else {
                $('.shortcode-item').each(function() {
                    var $item = $(this);
                    var text = $item.text().toLowerCase();

                    if (text.indexOf(searchTerm) !== -1) {
                        $item.show();
                    } else {
                        $item.hide();
                    }
                });
            }
        });

        // Expand/collapse parameter details
        $('.shortcode-item .dashicons-arrow-down').on('click', function() {
            var $icon = $(this);
            var $item = $icon.closest('.shortcode-item');
            var $params = $item.find('.shortcode-params');

            if ($params.is(':visible')) {
                $params.slideUp();
                $icon.removeClass('dashicons-arrow-up').addClass('dashicons-arrow-down');
            } else {
                $params.slideDown();
                $icon.removeClass('dashicons-arrow-down').addClass('dashicons-arrow-up');
            }
        });

        /**
         * Show admin notice
         * @param {string} message - Message to display
         * @param {string} type - Notice type (success, error, warning, info)
         */
        function showNotice(message, type) {
            var $notice = $('<div class="notice notice-' + type + ' is-dismissible">' +
                          '<p>' + message + '</p>' +
                          '<button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss</span></button>' +
                          '</div>');

            // Insert after page title
            $('.wrap h1').first().after($notice);

            // Make dismissible
            $notice.find('.notice-dismiss').on('click', function() {
                $notice.fadeOut(function() {
                    $(this).remove();
                });
            });

            // Auto-dismiss after 10 seconds for success messages
            if (type === 'success') {
                setTimeout(function() {
                    $notice.fadeOut(function() {
                        $(this).remove();
                    });
                }, 10000);
            }
        }
    });

})(jQuery);