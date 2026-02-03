/**
 * MLD Listings Grid - Property Card Actions & View Modes
 *
 * Provides favorite, hide, share functionality and view mode switching
 * for property cards. Used by [mld_listing_cards] shortcode and other grid displays.
 *
 * @package MLS_Listings_Display
 * @since 6.31.12
 * @updated 6.56.0 - Added share button functionality (iOS alignment)
 * @updated 6.57.0 - Added Card/Grid/Compact view modes with localStorage persistence
 */
(function($) {
    'use strict';

    const MLDListingsGrid = {
        // localStorage key for view mode preference
        STORAGE_KEY: 'mld_listings_view_mode',

        /**
         * Initialize event handlers and restore saved view mode
         */
        init: function() {
            this.bindEvents();
            this.restoreViewMode();
        },

        /**
         * Bind event handlers using event delegation
         */
        bindEvents: function() {
            const self = this;

            // Favorite button click - delegate to body for dynamically loaded cards
            $('body').on('click', '.mld-simple-card-actions .bme-favorite-btn', function(e) {
                e.stopPropagation();
                e.preventDefault();
                self.handleFavorite($(this));
            });

            // Hide button click - delegate to body for dynamically loaded cards
            $('body').on('click', '.mld-simple-card-actions .bme-hide-btn', function(e) {
                e.stopPropagation();
                e.preventDefault();
                self.handleHide($(this));
            });

            // Share button click - delegate to body for dynamically loaded cards
            $('body').on('click', '.mld-simple-card-actions .bme-share-btn', function(e) {
                e.stopPropagation();
                e.preventDefault();
                self.handleShare($(this));
            });

            // View mode toggle button click
            $('body').on('click', '.mld-view-mode-btn', function(e) {
                e.preventDefault();
                e.stopPropagation();
                self.handleViewModeChange($(this));
            });
        },

        /**
         * Restore saved view mode from localStorage
         * @since 6.57.0
         */
        restoreViewMode: function() {
            try {
                const savedMode = localStorage.getItem(this.STORAGE_KEY);
                if (savedMode && ['card', 'grid', 'compact'].includes(savedMode)) {
                    // Apply saved mode to all grids on page
                    this.applyViewMode(savedMode, true);
                }
            } catch (e) {
                // localStorage not available
                console.warn('MLD Grid: localStorage not available for view mode persistence');
            }
        },

        /**
         * Handle view mode toggle button click
         * @param {jQuery} $btn The clicked button
         * @since 6.57.0
         */
        handleViewModeChange: function($btn) {
            const viewMode = $btn.data('view');
            if (!viewMode) return;

            // Update active button state in the toggle group
            const $toggle = $btn.closest('.mld-view-mode-toggle');
            $toggle.find('.mld-view-mode-btn').removeClass('active');
            $btn.addClass('active');

            // Apply view mode to the associated grid
            const gridId = $toggle.data('grid-id');
            const $grid = gridId ? $('#' + gridId).find('.mld-cards-grid') : $btn.closest('.mld-listing-cards-wrapper').find('.mld-cards-grid');

            this.applyViewModeToGrid($grid, viewMode);

            // Save preference to localStorage
            this.saveViewMode(viewMode);
        },

        /**
         * Apply view mode to a specific grid
         * @param {jQuery} $grid The grid element
         * @param {string} mode The view mode (card, grid, compact)
         * @since 6.57.0
         */
        applyViewModeToGrid: function($grid, mode) {
            // Remove all view mode classes
            $grid.removeClass('mld-view-grid mld-view-compact');

            // Add appropriate class for non-card modes
            if (mode === 'grid') {
                $grid.addClass('mld-view-grid');
            } else if (mode === 'compact') {
                $grid.addClass('mld-view-compact');
            }
            // Card mode has no additional class (default styling)
        },

        /**
         * Apply view mode to all grids on the page
         * @param {string} mode The view mode
         * @param {boolean} updateButtons Whether to update toggle button states
         * @since 6.57.0
         */
        applyViewMode: function(mode, updateButtons) {
            const self = this;

            // Apply to all listing grids
            $('.mld-cards-grid').each(function() {
                self.applyViewModeToGrid($(this), mode);
            });

            // Update toggle button states if requested
            if (updateButtons) {
                $('.mld-view-mode-toggle').each(function() {
                    const $toggle = $(this);
                    $toggle.find('.mld-view-mode-btn').removeClass('active');
                    $toggle.find('.mld-view-mode-btn[data-view="' + mode + '"]').addClass('active');
                });
            }
        },

        /**
         * Save view mode preference to localStorage
         * @param {string} mode The view mode
         * @since 6.57.0
         */
        saveViewMode: function(mode) {
            try {
                localStorage.setItem(this.STORAGE_KEY, mode);
            } catch (e) {
                // localStorage not available or full
            }
        },

        /**
         * Handle favorite button click
         *
         * @param {jQuery} $btn The clicked button
         */
        handleFavorite: function($btn) {
            const self = this;
            const mlsNumber = $btn.data('mls');

            if (!mlsNumber || $btn.hasClass('loading')) {
                return;
            }

            $btn.addClass('loading');

            $.ajax({
                url: mldListingsData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'mld_save_property',
                    nonce: mldListingsData.nonce,
                    mls_number: mlsNumber,
                    action_type: 'toggle'
                },
                success: function(response) {
                    $btn.removeClass('loading');

                    if (response.success) {
                        const heartFilledIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" viewBox="0 0 16 16"><path fill-rule="evenodd" d="M8 1.314C12.438-3.248 23.534 4.735 8 15-7.534 4.736 3.562-3.248 8 1.314z"/></svg>';
                        const heartOutlineIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" viewBox="0 0 16 16"><path d="m8 2.748-.717-.737C5.6.281 2.514.878 1.4 3.053c-.523 1.023-.641 2.5.314 4.385.92 1.815 2.834 3.989 6.286 6.357 3.452-2.368 5.365-4.542 6.286-6.357.955-1.886.838-3.362.314-4.385C13.486.878 10.4.28 8.717 2.01L8 2.748zM8 15C-7.333 4.868 3.279-3.04 7.824 1.143c.06.055.119.112.176.171a3.12 3.12 0 0 1 .176-.17C12.72-3.042 23.333 4.867 8 15z"/></svg>';

                        if (response.data.is_saved) {
                            $btn.addClass('saved').html(heartFilledIcon);
                        } else {
                            $btn.removeClass('saved').html(heartOutlineIcon);
                        }
                    }
                },
                error: function() {
                    $btn.removeClass('loading');
                    console.error('MLD Grid: Failed to save property');
                }
            });
        },

        /**
         * Handle hide button click
         *
         * @param {jQuery} $btn The clicked button
         */
        handleHide: function($btn) {
            const mlsNumber = $btn.data('mls');

            if (!mlsNumber || $btn.hasClass('loading')) {
                return;
            }

            $btn.addClass('loading');
            const $card = $btn.closest('.mld-listing-card-simple');

            $.ajax({
                url: mldListingsData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'mld_hide_property',
                    nonce: mldListingsData.nonce,
                    mls_number: mlsNumber
                },
                success: function(response) {
                    $btn.removeClass('loading');

                    if (response.success && response.data.is_hidden) {
                        // Animate card out and remove from DOM
                        $card.css({
                            transition: 'opacity 0.3s, transform 0.3s',
                            opacity: 0,
                            transform: 'scale(0.9)'
                        });

                        setTimeout(function() {
                            $card.remove();

                            // Trigger event for other scripts to update counts
                            $(document).trigger('mld:propertyHidden', [mlsNumber]);
                        }, 300);
                    }
                },
                error: function() {
                    $btn.removeClass('loading');
                    console.error('MLD Grid: Failed to hide property');
                }
            });
        },

        /**
         * Handle share button click
         * Uses Web Share API if available, falls back to clipboard copy
         *
         * @param {jQuery} $btn The clicked button
         * @since 6.56.0
         */
        handleShare: function($btn) {
            const url = $btn.data('url');
            const title = $btn.data('title') || 'Property Listing';

            if (!url) {
                console.error('MLD Grid: No URL to share');
                return;
            }

            // Try Web Share API first (mobile-friendly)
            if (navigator.share) {
                navigator.share({
                    title: title,
                    text: 'Check out this property: ' + title,
                    url: url
                }).then(function() {
                    // Success feedback
                    $btn.addClass('shared');
                    setTimeout(function() {
                        $btn.removeClass('shared');
                    }, 1500);
                }).catch(function(err) {
                    // User cancelled or error - fall back to clipboard
                    if (err.name !== 'AbortError') {
                        MLDListingsGrid.copyToClipboard(url, $btn);
                    }
                });
            } else {
                // Fallback: Copy to clipboard
                this.copyToClipboard(url, $btn);
            }
        },

        /**
         * Copy URL to clipboard with visual feedback
         *
         * @param {string} url The URL to copy
         * @param {jQuery} $btn The button for feedback
         * @since 6.56.0
         */
        copyToClipboard: function(url, $btn) {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(url).then(function() {
                    MLDListingsGrid.showCopyFeedback($btn, true);
                }).catch(function() {
                    MLDListingsGrid.showCopyFeedback($btn, false);
                });
            } else {
                // Fallback for older browsers
                const textarea = document.createElement('textarea');
                textarea.value = url;
                textarea.style.position = 'fixed';
                textarea.style.opacity = '0';
                document.body.appendChild(textarea);
                textarea.select();

                try {
                    document.execCommand('copy');
                    MLDListingsGrid.showCopyFeedback($btn, true);
                } catch (err) {
                    MLDListingsGrid.showCopyFeedback($btn, false);
                }

                document.body.removeChild(textarea);
            }
        },

        /**
         * Show visual feedback after copy attempt
         *
         * @param {jQuery} $btn The button
         * @param {boolean} success Whether copy succeeded
         * @since 6.56.0
         */
        showCopyFeedback: function($btn, success) {
            const originalTitle = $btn.attr('title');
            const checkIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" viewBox="0 0 16 16"><path d="M13.854 3.646a.5.5 0 0 1 0 .708l-7 7a.5.5 0 0 1-.708 0l-3.5-3.5a.5.5 0 1 1 .708-.708L6.5 10.293l6.646-6.647a.5.5 0 0 1 .708 0z"/></svg>';

            if (success) {
                $btn.addClass('copied').attr('title', 'Link copied!');
                const originalHtml = $btn.html();
                $btn.html(checkIcon);

                setTimeout(function() {
                    $btn.removeClass('copied').attr('title', originalTitle).html(originalHtml);
                }, 2000);
            } else {
                $btn.attr('title', 'Copy failed');
                setTimeout(function() {
                    $btn.attr('title', originalTitle);
                }, 2000);
            }
        }
    };

    // Initialize when DOM is ready
    $(document).ready(function() {
        MLDListingsGrid.init();
    });

    // Expose globally for external access
    window.MLDListingsGrid = MLDListingsGrid;

})(jQuery);
