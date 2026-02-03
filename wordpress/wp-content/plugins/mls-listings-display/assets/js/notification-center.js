/**
 * Web Notification Center JavaScript
 *
 * Handles the notification bell dropdown, full page, and settings.
 *
 * @package MLS_Listings_Display
 * @since 6.50.9
 */

(function($) {
    'use strict';

    // State
    let notifications = [];
    let unreadCount = 0;
    let isDropdownOpen = false;
    let isLoading = false;
    let hasMore = false;
    let currentOffset = 0;
    let pollInterval = null;
    let currentFilter = 'all';

    // DOM elements (cached after init)
    let $bell, $badge, $dropdown, $list, $markAllRead;

    /**
     * Initialize notification center
     */
    function init() {
        // Cache DOM elements
        $bell = $('#mld-bell-button');
        $badge = $('#mld-bell-badge');
        $dropdown = $('#mld-notification-dropdown');
        $list = $('#mld-notification-list');
        $markAllRead = $('#mld-mark-all-read');

        // Exit if bell not found
        if (!$bell.length) {
            return;
        }

        // Bind events
        bindEvents();

        // Load initial unread count
        fetchUnreadCount();

        // Start polling for new notifications
        startPolling();

        // Check if we're on the full notifications page
        if ($('#mld-notifications-page').length) {
            initFullPage();
        }

        // Check if we're on the settings page
        if ($('#mld-notification-settings-page').length) {
            initSettingsPage();
        }
    }

    /**
     * Bind event handlers
     */
    function bindEvents() {
        // Toggle dropdown
        $bell.on('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            toggleDropdown();
        });

        // Close dropdown on outside click
        $(document).on('click', function(e) {
            if (isDropdownOpen && !$(e.target).closest('.mld-notification-bell').length) {
                closeDropdown();
            }
        });

        // Close on escape key
        $(document).on('keydown', function(e) {
            if (e.key === 'Escape' && isDropdownOpen) {
                closeDropdown();
            }
        });

        // Mark all as read
        $markAllRead.on('click', function(e) {
            e.preventDefault();
            markAllRead();
        });

        // Click on notification item
        $(document).on('click', '.mld-notification-item', function(e) {
            const $item = $(this);
            const notificationId = $item.data('id');
            const url = $item.data('url');

            // Mark as read
            markNotificationRead(notificationId);

            // Navigate if URL exists
            if (url && !$(e.target).closest('.mld-notification-dismiss').length) {
                window.location.href = url;
            }
        });

        // Dismiss notification
        $(document).on('click', '.mld-notification-dismiss', function(e) {
            e.preventDefault();
            e.stopPropagation();
            const notificationId = $(this).closest('.mld-notification-item').data('id');
            dismissNotification(notificationId);
        });
    }

    /**
     * Toggle dropdown visibility
     */
    function toggleDropdown() {
        if (isDropdownOpen) {
            closeDropdown();
        } else {
            openDropdown();
        }
    }

    /**
     * Open dropdown
     */
    function openDropdown() {
        $dropdown.addClass('active');
        $bell.attr('aria-expanded', 'true');
        $dropdown.attr('aria-hidden', 'false');
        isDropdownOpen = true;

        // Load notifications on first open
        if (notifications.length === 0 && !isLoading) {
            fetchNotifications();
        }
    }

    /**
     * Close dropdown
     */
    function closeDropdown() {
        $dropdown.removeClass('active');
        $bell.attr('aria-expanded', 'false');
        $dropdown.attr('aria-hidden', 'true');
        isDropdownOpen = false;
    }

    /**
     * Fetch notifications from server
     */
    function fetchNotifications(append = false) {
        if (isLoading) return;

        isLoading = true;

        if (!append) {
            currentOffset = 0;
            $list.html('<div class="mld-notification-loading">' + mldNotifications.strings.loading + '</div>');
        }

        $.ajax({
            url: mldNotifications.ajaxUrl,
            type: 'POST',
            data: {
                action: 'mld_get_notifications',
                nonce: mldNotifications.nonce,
                limit: 20,
                offset: currentOffset
            },
            success: function(response) {
                isLoading = false;

                if (response.success) {
                    if (append) {
                        notifications = notifications.concat(response.data.notifications);
                    } else {
                        notifications = response.data.notifications;
                    }

                    unreadCount = response.data.unread_count;
                    hasMore = response.data.has_more;
                    currentOffset += response.data.notifications.length;

                    renderNotifications();
                    updateBadge(unreadCount);
                } else {
                    $list.html('<div class="mld-notification-error">' + mldNotifications.strings.error + '</div>');
                }
            },
            error: function() {
                isLoading = false;
                $list.html('<div class="mld-notification-error">' + mldNotifications.strings.error + '</div>');
            }
        });
    }

    /**
     * Fetch unread count only
     */
    function fetchUnreadCount() {
        $.ajax({
            url: mldNotifications.ajaxUrl,
            type: 'POST',
            data: {
                action: 'mld_get_unread_count',
                nonce: mldNotifications.nonce
            },
            success: function(response) {
                if (response.success) {
                    const newCount = response.data.count;

                    // Animate bell if new notifications
                    if (newCount > unreadCount && unreadCount !== 0) {
                        animateBell();
                    }

                    unreadCount = newCount;
                    updateBadge(unreadCount);
                }
            }
        });
    }

    /**
     * Render notifications in dropdown
     */
    function renderNotifications() {
        if (notifications.length === 0) {
            $list.html('<div class="mld-notification-empty">' + mldNotifications.strings.noNotifications + '</div>');
            $markAllRead.prop('disabled', true);
            return;
        }

        $markAllRead.prop('disabled', unreadCount === 0);

        let html = '';
        notifications.forEach(function(notification) {
            html += renderNotificationItem(notification);
        });

        $list.html(html);
    }

    /**
     * Render single notification item
     */
    function renderNotificationItem(notification) {
        const unreadClass = !notification.is_read ? ' unread' : '';
        const iconClass = 'type-' + notification.icon;

        let imageHtml;
        if (notification.image_url) {
            imageHtml = '<div class="mld-notification-image"><img src="' + escapeHtml(notification.image_url) + '" alt="" loading="lazy"></div>';
        } else {
            imageHtml = '<div class="mld-notification-icon ' + iconClass + '">' + getIconSvg(notification.icon) + '</div>';
        }

        return '<div class="mld-notification-item' + unreadClass + '" data-id="' + notification.id + '" data-url="' + escapeHtml(notification.url || '') + '">' +
            imageHtml +
            '<div class="mld-notification-content">' +
                '<p class="mld-notification-title">' + escapeHtml(notification.title) + '</p>' +
                '<p class="mld-notification-body">' + escapeHtml(notification.body) + '</p>' +
                '<span class="mld-notification-time" title="' + escapeHtml(notification.sent_at_full) + '">' + escapeHtml(notification.sent_at) + '</span>' +
            '</div>' +
            '<div class="mld-notification-actions">' +
                '<button class="mld-notification-dismiss" aria-label="Dismiss">' +
                    '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>' +
                '</button>' +
            '</div>' +
        '</div>';
    }

    /**
     * Get icon SVG by type
     */
    function getIconSvg(icon) {
        const icons = {
            'home': '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg>',
            'tag': '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"></path><line x1="7" y1="7" x2="7.01" y2="7"></line></svg>',
            'heart': '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"></path></svg>',
            'search': '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>',
            'calendar': '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>',
            'bell': '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg>'
        };
        return icons[icon] || icons['bell'];
    }

    /**
     * Update badge count
     */
    function updateBadge(count) {
        if (count > 0) {
            $badge.text(count > 99 ? '99+' : count).removeClass('mld-badge-hidden');
        } else {
            $badge.addClass('mld-badge-hidden');
        }
        $badge.attr('data-count', count);
    }

    /**
     * Animate bell icon
     */
    function animateBell() {
        $bell.addClass('shake');
        setTimeout(function() {
            $bell.removeClass('shake');
        }, 500);
    }

    /**
     * Mark notification as read
     */
    function markNotificationRead(notificationId) {
        // Optimistic update
        const $item = $('.mld-notification-item[data-id="' + notificationId + '"]');
        if ($item.hasClass('unread')) {
            $item.removeClass('unread');
            unreadCount = Math.max(0, unreadCount - 1);
            updateBadge(unreadCount);
        }

        $.ajax({
            url: mldNotifications.ajaxUrl,
            type: 'POST',
            data: {
                action: 'mld_mark_notification_read',
                nonce: mldNotifications.nonce,
                notification_id: notificationId
            },
            success: function(response) {
                if (response.success) {
                    unreadCount = response.data.unread_count;
                    updateBadge(unreadCount);
                    $markAllRead.prop('disabled', unreadCount === 0);
                }
            }
        });
    }

    /**
     * Mark all notifications as read
     */
    function markAllRead() {
        // Optimistic update
        $('.mld-notification-item.unread').removeClass('unread');
        unreadCount = 0;
        updateBadge(0);
        $markAllRead.prop('disabled', true);

        $.ajax({
            url: mldNotifications.ajaxUrl,
            type: 'POST',
            data: {
                action: 'mld_mark_all_read',
                nonce: mldNotifications.nonce
            },
            success: function(response) {
                if (response.success) {
                    // Update notifications array
                    notifications.forEach(function(n) {
                        n.is_read = true;
                    });
                }
            }
        });
    }

    /**
     * Dismiss notification
     */
    function dismissNotification(notificationId) {
        // Optimistic update
        const $item = $('.mld-notification-item[data-id="' + notificationId + '"]');
        const wasUnread = $item.hasClass('unread');

        $item.slideUp(200, function() {
            $(this).remove();

            // Update count
            if (wasUnread) {
                unreadCount = Math.max(0, unreadCount - 1);
                updateBadge(unreadCount);
            }

            // Remove from array
            notifications = notifications.filter(function(n) {
                return n.id !== notificationId;
            });

            // Show empty state if needed
            if (notifications.length === 0) {
                $list.html('<div class="mld-notification-empty">' + mldNotifications.strings.noNotifications + '</div>');
            }
        });

        $.ajax({
            url: mldNotifications.ajaxUrl,
            type: 'POST',
            data: {
                action: 'mld_dismiss_notification',
                nonce: mldNotifications.nonce,
                notification_id: notificationId
            }
        });
    }

    /**
     * Start polling for new notifications
     */
    function startPolling() {
        if (pollInterval) return;

        pollInterval = setInterval(function() {
            fetchUnreadCount();
        }, mldNotifications.pollInterval);
    }

    /**
     * Stop polling
     */
    function stopPolling() {
        if (pollInterval) {
            clearInterval(pollInterval);
            pollInterval = null;
        }
    }

    /**
     * Initialize full notifications page
     */
    function initFullPage() {
        const $pageList = $('#mld-notifications-list-full');
        const $pagination = $('#mld-notifications-pagination');
        const $loadMore = $('#mld-load-more');
        const $pageMarkAllRead = $('#mld-page-mark-all-read');
        const $pageClearAll = $('#mld-page-clear-all');

        // Load initial notifications
        fetchPageNotifications();

        // Filter buttons
        $('.mld-filter-btn').on('click', function() {
            const filter = $(this).data('filter');
            if (filter !== currentFilter) {
                currentFilter = filter;
                $('.mld-filter-btn').removeClass('active');
                $(this).addClass('active');
                currentOffset = 0;
                fetchPageNotifications();
            }
        });

        // Load more
        $loadMore.on('click', function() {
            fetchPageNotifications(true);
        });

        // Mark all read
        $pageMarkAllRead.on('click', function() {
            markAllRead();
            $('.mld-notification-item.unread').removeClass('unread');
        });

        // Clear all
        $pageClearAll.on('click', function() {
            if (confirm('Are you sure you want to clear all notifications?')) {
                dismissAll();
            }
        });

        function fetchPageNotifications(append) {
            if (isLoading) return;

            isLoading = true;

            if (!append) {
                currentOffset = 0;
                $pageList.html('<div class="mld-notification-loading">' + mldNotifications.strings.loading + '</div>');
            }

            $.ajax({
                url: mldNotifications.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'mld_get_notifications',
                    nonce: mldNotifications.nonce,
                    limit: 50,
                    offset: currentOffset
                },
                success: function(response) {
                    isLoading = false;

                    if (response.success) {
                        let filteredNotifications = response.data.notifications;

                        // Apply filter
                        if (currentFilter === 'unread') {
                            filteredNotifications = filteredNotifications.filter(function(n) {
                                return !n.is_read;
                            });
                        } else if (currentFilter === 'property') {
                            filteredNotifications = filteredNotifications.filter(function(n) {
                                return ['new_listing', 'price_change', 'status_change'].includes(n.notification_type);
                            });
                        } else if (currentFilter === 'search') {
                            filteredNotifications = filteredNotifications.filter(function(n) {
                                return n.notification_type === 'saved_search';
                            });
                        }

                        hasMore = response.data.has_more;
                        currentOffset += response.data.notifications.length;

                        let html = '';
                        filteredNotifications.forEach(function(notification) {
                            html += renderNotificationItem(notification);
                        });

                        if (append) {
                            $pageList.append(html);
                        } else {
                            if (filteredNotifications.length === 0) {
                                $pageList.html('<div class="mld-notification-empty">' + mldNotifications.strings.noNotifications + '</div>');
                            } else {
                                $pageList.html(html);
                            }
                        }

                        // Show/hide pagination
                        $pagination.toggle(hasMore);
                    } else {
                        $pageList.html('<div class="mld-notification-error">' + mldNotifications.strings.error + '</div>');
                    }
                },
                error: function() {
                    isLoading = false;
                    $pageList.html('<div class="mld-notification-error">' + mldNotifications.strings.error + '</div>');
                }
            });
        }

        function dismissAll() {
            $pageList.html('<div class="mld-notification-loading">Clearing notifications...</div>');

            $.ajax({
                url: mldNotifications.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'mld_dismiss_all',
                    nonce: mldNotifications.nonce
                },
                success: function(response) {
                    if (response.success) {
                        notifications = [];
                        unreadCount = 0;
                        updateBadge(0);
                        $pageList.html('<div class="mld-notification-empty">' + mldNotifications.strings.noNotifications + '</div>');
                        $pagination.hide();
                    }
                }
            });
        }
    }

    /**
     * Initialize settings page
     */
    function initSettingsPage() {
        // Load current preferences
        loadPreferences();

        // Toggle change handler
        $(document).on('change', '.mld-toggle input', function() {
            const $input = $(this);
            const type = $input.data('type');
            const channel = $input.data('channel');
            const enabled = $input.is(':checked');

            updatePreference(type, channel, enabled);
        });

        function loadPreferences() {
            $.ajax({
                url: mldNotifications.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'mld_get_notification_preferences',
                    nonce: mldNotifications.nonce
                },
                success: function(response) {
                    if (response.success && response.data.preferences) {
                        const prefs = response.data.preferences;

                        // Update toggles based on preferences
                        Object.keys(prefs).forEach(function(type) {
                            const pref = prefs[type];
                            $('input[data-type="' + type + '"][data-channel="push"]').prop('checked', pref.push_enabled);
                            $('input[data-type="' + type + '"][data-channel="email"]').prop('checked', pref.email_enabled);
                        });
                    }
                }
            });
        }

        function updatePreference(type, channel, enabled) {
            const data = {
                action: 'mld_update_notification_preferences',
                nonce: mldNotifications.nonce,
                notification_type: type
            };

            if (channel === 'push') {
                data.push_enabled = enabled;
            } else {
                data.email_enabled = enabled;
            }

            $.ajax({
                url: mldNotifications.ajaxUrl,
                type: 'POST',
                data: data
            });
        }
    }

    /**
     * Escape HTML for safe output
     */
    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Initialize when DOM is ready
    $(document).ready(init);

    // Stop polling when page is hidden
    document.addEventListener('visibilitychange', function() {
        if (document.hidden) {
            stopPolling();
        } else {
            startPolling();
        }
    });

})(jQuery);
