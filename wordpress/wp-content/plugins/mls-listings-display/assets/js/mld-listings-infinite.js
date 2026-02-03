/**
 * MLD Listings Cards - Infinite Scroll
 *
 * Provides infinite scroll functionality for the [mld_listing_cards] shortcode.
 * Handles scroll detection, AJAX loading, and sort changes.
 *
 * @package MLS_Listings_Display
 * @since 6.11.21
 */
(function($) {
    'use strict';

    const MLDInfiniteCards = {
        // Configuration
        scrollThreshold: 300, // px from bottom to trigger load
        debounceMs: 150,

        // State per grid instance
        grids: {},

        /**
         * Initialize all grid instances on the page
         */
        init: function() {
            const self = this;

            $('.mld-listing-cards-wrapper').each(function() {
                const $wrapper = $(this);
                const gridId = $wrapper.attr('id');

                // Skip if no ID or already initialized
                if (!gridId || self.grids[gridId]) {
                    return;
                }

                self.grids[gridId] = {
                    $wrapper: $wrapper,
                    $grid: $wrapper.find('.mld-cards-grid'),
                    $loading: $wrapper.find('.mld-cards-loading'),
                    $endMessage: $wrapper.find('.mld-cards-end'),
                    $shownCount: $wrapper.find('.mld-shown-count'),
                    $totalCount: $wrapper.find('.mld-total-count'),
                    currentPage: parseInt($wrapper.data('page')) || 1,
                    perPage: parseInt($wrapper.data('per-page')) || 12,
                    total: parseInt($wrapper.data('total')) || 0,
                    filters: $wrapper.data('filters') || {},
                    sortBy: $wrapper.data('sort') || 'newest',
                    hasMore: $wrapper.data('has-more') === true || $wrapper.data('has-more') === 'true',
                    isLoading: false
                };
            });

            this.bindEvents();
        },

        /**
         * Bind event handlers
         */
        bindEvents: function() {
            const self = this;

            // Scroll event for infinite scroll (debounced)
            $(window).on('scroll.mldInfinite', this.debounce(function() {
                self.checkScroll();
            }, this.debounceMs));

            // Sort change
            $(document).on('change', '.mld-cards-sort', function() {
                const gridId = $(this).data('grid-id');
                const sortBy = $(this).val();
                self.changeSort(gridId, sortBy);
            });
        },

        /**
         * Check if we need to load more on scroll
         */
        checkScroll: function() {
            const self = this;

            Object.keys(this.grids).forEach(function(gridId) {
                const grid = self.grids[gridId];

                if (!grid.hasMore || grid.isLoading) {
                    return;
                }

                const $wrapper = grid.$wrapper;
                const wrapperBottom = $wrapper.offset().top + $wrapper.outerHeight();
                const windowBottom = $(window).scrollTop() + $(window).height();

                if (windowBottom >= wrapperBottom - self.scrollThreshold) {
                    self.loadMore(gridId);
                }
            });
        },

        /**
         * Load more listings via AJAX
         *
         * @param {string} gridId The grid instance ID
         */
        loadMore: function(gridId) {
            const self = this;
            const grid = this.grids[gridId];

            if (!grid || !grid.hasMore || grid.isLoading) {
                return;
            }

            grid.isLoading = true;
            grid.currentPage++;

            // Show loading indicator
            grid.$loading.show();

            $.ajax({
                url: mldCardsData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'mld_load_more_cards',
                    security: mldCardsData.nonce,
                    page: grid.currentPage,
                    per_page: grid.perPage,
                    filters: JSON.stringify(grid.filters),
                    sort_by: grid.sortBy
                },
                success: function(response) {
                    if (response.success && response.data.html) {
                        // Append new listings
                        grid.$grid.append(response.data.html);

                        // Update state
                        grid.hasMore = response.data.has_more;
                        grid.$wrapper.data('has-more', response.data.has_more);
                        grid.$wrapper.data('page', grid.currentPage);

                        // Update count display
                        const currentShown = grid.$grid.find('.mld-listing-card-simple').length;
                        grid.$shownCount.text(currentShown);

                        // Show end message if no more results
                        if (!response.data.has_more) {
                            grid.$endMessage.show();
                        }

                        // Trigger event for other scripts to hook into
                        $(document).trigger('mld:cardsLoaded', [gridId, response.data]);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('MLD Cards: Failed to load more listings', error);
                    // Revert page increment on error
                    grid.currentPage--;
                },
                complete: function() {
                    grid.isLoading = false;
                    grid.$loading.hide();
                }
            });
        },

        /**
         * Change sort order and reload from page 1
         *
         * @param {string} gridId The grid instance ID
         * @param {string} sortBy The sort option
         */
        changeSort: function(gridId, sortBy) {
            const self = this;
            const grid = this.grids[gridId];

            if (!grid || grid.isLoading) {
                return;
            }

            // Update sort in state
            grid.sortBy = sortBy;
            grid.currentPage = 0;
            grid.hasMore = true;

            // Clear existing listings
            grid.$grid.empty();
            grid.$endMessage.hide();

            // Show loading
            grid.$loading.show();

            // Load from page 1 with new sort
            this.loadMore(gridId);
        },

        /**
         * Debounce utility function
         *
         * @param {Function} func Function to debounce
         * @param {number} wait Wait time in ms
         * @returns {Function} Debounced function
         */
        debounce: function(func, wait) {
            let timeout;
            return function() {
                const context = this;
                const args = arguments;
                clearTimeout(timeout);
                timeout = setTimeout(function() {
                    func.apply(context, args);
                }, wait);
            };
        },

        /**
         * Manually trigger load for a specific grid
         * Useful for external scripts
         *
         * @param {string} gridId The grid instance ID
         */
        triggerLoad: function(gridId) {
            if (this.grids[gridId]) {
                this.loadMore(gridId);
            }
        },

        /**
         * Get grid state
         *
         * @param {string} gridId The grid instance ID
         * @returns {Object|null} Grid state or null
         */
        getGridState: function(gridId) {
            return this.grids[gridId] || null;
        }
    };

    // Initialize when DOM is ready
    $(document).ready(function() {
        MLDInfiniteCards.init();
    });

    // Expose globally for external access
    window.MLDInfiniteCards = MLDInfiniteCards;

})(jQuery);
