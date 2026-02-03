/**
 * MLD Notification Queue Admin JavaScript
 * Queue management tab functionality
 */

(function($) {
    'use strict';

    /**
     * Initialize on document ready
     */
    $(document).ready(function() {
        // Only run on queue management pages
        if ($('.mld-queue-management').length === 0) {
            return;
        }

        initQueueManagement();
    });

    /**
     * Initialize queue management functionality
     */
    function initQueueManagement() {
        // Retry item button
        $(document).on('click', '.retry-item', function(e) {
            e.preventDefault();
            var $btn = $(this);
            var itemId = $btn.data('id');
            retryQueueItem(itemId, $btn);
        });

        // Remove item button
        $(document).on('click', '.remove-item', function(e) {
            e.preventDefault();
            var $btn = $(this);
            var itemId = $btn.data('id');

            if (confirm('Are you sure you want to remove this item from the queue?')) {
                removeQueueItem(itemId, $btn);
            }
        });

        // Auto-refresh queue stats every 30 seconds
        setInterval(refreshQueueStats, 30000);
    }

    /**
     * Retry a single queue item
     */
    function retryQueueItem(itemId, $btn) {
        var originalText = $btn.text();
        $btn.prop('disabled', true).text('Retrying...');

        $.ajax({
            url: mldSettingsAjax.ajaxurl,
            type: 'POST',
            data: {
                action: 'mld_retry_queue_item',
                nonce: mldSettingsAjax.nonce,
                item_id: itemId
            },
            success: function(response) {
                if (response.success) {
                    showNotice('Item queued for retry', 'success');
                    // Refresh the page after a short delay to show updated status
                    setTimeout(function() {
                        location.reload();
                    }, 1500);
                } else {
                    showNotice(response.data || 'Failed to retry item', 'error');
                    $btn.prop('disabled', false).text(originalText);
                }
            },
            error: function() {
                showNotice('Network error. Please try again.', 'error');
                $btn.prop('disabled', false).text(originalText);
            }
        });
    }

    /**
     * Remove a queue item
     */
    function removeQueueItem(itemId, $btn) {
        var $row = $btn.closest('tr');
        $row.addClass('mld-loading');

        $.ajax({
            url: mldSettingsAjax.ajaxurl,
            type: 'POST',
            data: {
                action: 'mld_remove_queue_item',
                nonce: mldSettingsAjax.nonce,
                item_id: itemId
            },
            success: function(response) {
                if (response.success) {
                    $row.fadeOut(300, function() {
                        $(this).remove();
                        // Check if table is now empty
                        if ($('.mld-queue-management tbody tr').length === 0) {
                            $('.mld-queue-management tbody').html(
                                '<tr><td colspan="8" class="no-items">No items currently in queue.</td></tr>'
                            );
                        }
                    });
                    showNotice('Item removed from queue', 'success');
                    updateQueueCounts();
                } else {
                    $row.removeClass('mld-loading');
                    showNotice(response.data || 'Failed to remove item', 'error');
                }
            },
            error: function() {
                $row.removeClass('mld-loading');
                showNotice('Network error. Please try again.', 'error');
            }
        });
    }

    /**
     * Refresh queue statistics
     */
    function refreshQueueStats() {
        // Only refresh if page is visible
        if (document.hidden) {
            return;
        }

        $.ajax({
            url: mldSettingsAjax.ajaxurl,
            type: 'POST',
            data: {
                action: 'mld_get_queue_stats',
                nonce: mldSettingsAjax.nonce
            },
            success: function(response) {
                if (response.success && response.data) {
                    updateQueueCountsDisplay(response.data);
                }
            }
        });
    }

    /**
     * Update queue counts after removal
     */
    function updateQueueCounts() {
        var queued = $('.queue-item-queued').length;
        var processing = $('.queue-item-processing').length;
        var failed = $('.queue-item-failed').length;

        $('.stat-box').each(function() {
            var $box = $(this);
            var label = $box.find('.stat-label').text().toLowerCase();

            if (label.includes('queued')) {
                animateNumber($box.find('.stat-number'), queued);
            } else if (label.includes('processing')) {
                animateNumber($box.find('.stat-number'), processing);
            } else if (label.includes('failed')) {
                animateNumber($box.find('.stat-number'), failed);
            }
        });
    }

    /**
     * Update queue counts display from AJAX data
     */
    function updateQueueCountsDisplay(data) {
        $('.stat-box').each(function() {
            var $box = $(this);
            var label = $box.find('.stat-label').text().toLowerCase();

            if (label.includes('queued') && data.queued !== undefined) {
                animateNumber($box.find('.stat-number'), data.queued);
            } else if (label.includes('processing') && data.processing !== undefined) {
                animateNumber($box.find('.stat-number'), data.processing);
            } else if (label.includes('sent') && data.sent !== undefined) {
                animateNumber($box.find('.stat-number'), data.sent);
            } else if (label.includes('failed') && data.failed !== undefined) {
                animateNumber($box.find('.stat-number'), data.failed);
            }
        });
    }

    /**
     * Animate number change
     */
    function animateNumber($element, newValue) {
        var currentValue = parseInt($element.text()) || 0;
        newValue = parseInt(newValue) || 0;

        if (currentValue === newValue) return;

        $({ count: currentValue }).animate({ count: newValue }, {
            duration: 400,
            easing: 'swing',
            step: function() {
                $element.text(Math.floor(this.count));
            },
            complete: function() {
                $element.text(newValue);
            }
        });
    }

    /**
     * Show admin notice
     */
    function showNotice(message, type) {
        var $notice = $('<div class="notice notice-' + type + ' is-dismissible"><p>' + message + '</p><button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss this notice.</span></button></div>');

        // Insert after the h1 or at the top of the wrap
        var $target = $('.mld-settings-wrap > h1').first();
        if ($target.length) {
            $target.after($notice);
        } else {
            $('.mld-settings-wrap').prepend($notice);
        }

        // Auto-dismiss after 5 seconds
        setTimeout(function() {
            $notice.fadeOut(300, function() {
                $(this).remove();
            });
        }, 5000);

        // Manual dismiss
        $notice.on('click', '.notice-dismiss', function() {
            $notice.fadeOut(300, function() {
                $(this).remove();
            });
        });
    }

})(jQuery);
