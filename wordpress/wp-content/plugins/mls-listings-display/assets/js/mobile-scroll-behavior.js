/**
 * Mobile Scroll Behavior for List View
 * Handles hiding/showing Map Options panel (#bme-map-controls-panel) on scroll
 */
(function($) {
    'use strict';

    let lastScrollTop = 0;
    let scrollDelta = 5;
    let hideThreshold = 20;
    let $mapOptionsPanel = null;
    let isListView = false;
    let scrollTimer = null;

    function initMobileScrollBehavior() {
        // Only run on mobile devices
        if (window.innerWidth > 768) {
            return;
        }

        // Find the Map Options panel
        $mapOptionsPanel = $('#bme-map-controls-panel');

        if (!$mapOptionsPanel.length) {
            // Retry if panel not found
            setTimeout(initMobileScrollBehavior, 1000);
            return;
        }

        // Panel found and initialized

        // Set up view mode detection
        setupViewModeDetection();

        // Start scroll monitoring
        setupScrollListeners();
    }

    function setupViewModeDetection() {
        // Initial check
        checkViewMode();

        // Listen for clicks on view toggle buttons
        $(document).on('click', '.bme-view-mode-btn', function(e) {
            const $btn = $(this);
            // Use data-mode not data-view!
            const viewType = $btn.data('mode') || $btn.attr('data-mode');

            // Direct detection from button click
            if (viewType === 'list') {
                isListView = true;
                // Re-setup scroll listeners when switching to list view
                setupScrollListeners();
                // Reset scroll position tracking
                lastScrollTop = 0;
                // Save view mode to visitor state
                if (typeof MLD_VisitorState !== 'undefined') {
                    MLD_VisitorState.saveViewState({
                        mode: 'list',
                        scrollPosition: 0
                    });
                }
            } else if (viewType === 'map') {
                isListView = false;
                showMapOptionsPanel();
                // Save view mode to visitor state
                if (typeof MLD_VisitorState !== 'undefined') {
                    MLD_VisitorState.saveViewState({
                        mode: 'map',
                        scrollPosition: 0
                    });
                }
            }

            // Double-check after a delay
            setTimeout(checkViewMode, 300);
        });

        // Watch for class changes
        const observer = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
                if (mutation.type === 'attributes') {
                    checkViewMode();
                }
            });
        });

        // Observe multiple elements for changes
        const elementsToObserve = [
            document.getElementById('bme-half-map-wrapper'),
            document.getElementById('bme-map-container'),
            document.querySelector('.bme-view-mode-toggle'),
            document.body
        ].filter(Boolean);

        elementsToObserve.forEach(el => {
            if (el) {
                observer.observe(el, {
                    attributes: true,
                    attributeFilter: ['class', 'style'],
                    subtree: false
                });
            }
        });
    }

    function checkViewMode() {
        // Multiple detection methods
        const $wrapper = $('#bme-half-map-wrapper');
        const $mapContainer = $('#bme-map-container');
        const $listingsContainer = $('#bme-listings-container');
        const $listViewBtn = $('.bme-view-mode-btn[data-mode="list"]');
        const $mapViewBtn = $('.bme-view-mode-btn[data-mode="map"]');

        // Method 1: Check active button
        const listBtnActive = $listViewBtn.hasClass('active');
        const mapBtnActive = $mapViewBtn.hasClass('active');

        // Method 2: Check wrapper classes
        const hasListClass = $wrapper.hasClass('list-view') || $wrapper.hasClass('bme-list-view');
        const hasMapClass = $wrapper.hasClass('map-view') || $wrapper.hasClass('bme-map-view');

        // Method 3: Check visibility
        const mapHidden = $mapContainer.css('display') === 'none' || $mapContainer.is(':hidden');
        const mapHeight = $mapContainer.height();
        const listingsVisible = $listingsContainer.is(':visible');

        // Method 4: Check z-index (list view often has higher z-index)
        const listingsZIndex = parseInt($listingsContainer.css('z-index')) || 0;
        const mapZIndex = parseInt($mapContainer.css('z-index')) || 0;

        // Determine view mode
        const wasListView = isListView;
        isListView = listBtnActive || hasListClass || (mapHidden && listingsVisible) ||
                     (mapHeight === 0 && listingsVisible) || (listingsZIndex > mapZIndex && listingsVisible);

        if (wasListView !== isListView) {
            if (!isListView) {
                // Switched to map view - show panel
                showMapOptionsPanel();
            }
        }
    }

    function setupScrollListeners() {
        // Remove any existing listeners
        $(window).off('.mldScroll');
        $(document).off('.mldScroll');
        $('.mld-fixed-wrapper').off('.mldScroll');
        $('#bme-listings-container').off('.mldScroll');
        $('#bme-half-map-wrapper').off('.mldScroll');
        $('.bme-listings-wrapper').off('.mldScroll');

        // Add scroll listener to multiple containers
        $(window).on('scroll.mldScroll', handleScroll);
        $(document).on('scroll.mldScroll', handleScroll);
        $('.mld-fixed-wrapper').on('scroll.mldScroll', handleScroll);
        $('#bme-listings-container').on('scroll.mldScroll', handleScroll);
        $('#bme-half-map-wrapper').on('scroll.mldScroll', handleScroll);
        $('.bme-listings-wrapper').on('scroll.mldScroll', handleScroll);

        // Also handle touch events
        $(document).on('touchstart.mldScroll', function(e) {
            if (e.originalEvent.touches) {
                window.lastTouchY = e.originalEvent.touches[0].clientY;
            }
        });

        $(document).on('touchmove.mldScroll', function(e) {
            if (!isListView) return;

            if (e.originalEvent.touches && window.lastTouchY !== undefined) {
                const currentY = e.originalEvent.touches[0].clientY;
                const deltaY = window.lastTouchY - currentY;

                if (Math.abs(deltaY) > scrollDelta) {
                    if (deltaY > 0) {
                        // Scrolling down
                        hideMapOptionsPanel();
                    } else {
                        // Scrolling up
                        showMapOptionsPanel();
                    }
                }

                window.lastTouchY = currentY;
            }
        });
    }

    function handleScroll(e) {
        if (!isListView) return;

        clearTimeout(scrollTimer);

        // Try to get scroll position from various sources
        const scrollTop = $(window).scrollTop() ||
                         $(document).scrollTop() ||
                         $(e.target).scrollTop() ||
                         $('#bme-half-map-wrapper').scrollTop() ||
                         $('#bme-listings-container').scrollTop() ||
                         $('.bme-listings-wrapper').scrollTop() ||
                         window.pageYOffset ||
                         document.documentElement.scrollTop ||
                         document.body.scrollTop ||
                         0;

        // Check scroll direction - lower threshold for better responsiveness
        if (Math.abs(lastScrollTop - scrollTop) <= 2) {
            return;
        }

        if (scrollTop > lastScrollTop && scrollTop > 10) {
            // Scrolling down - hide immediately
            hideMapOptionsPanel();
        } else if (scrollTop < lastScrollTop || scrollTop <= 10) {
            // Scrolling up or at top - show immediately
            showMapOptionsPanel();
        }

        lastScrollTop = scrollTop;

        // Save scroll position to visitor state
        if (typeof MLD_VisitorState !== 'undefined' && isListView) {
            // Debounce the scroll position saving
            clearTimeout(window.scrollSaveTimer);
            window.scrollSaveTimer = setTimeout(() => {
                MLD_VisitorState.saveViewState({
                    mode: 'list',
                    scrollPosition: scrollTop
                });
            }, 500);
        }

        // Show panel if scroll stops at top
        scrollTimer = setTimeout(() => {
            if (scrollTop <= 10) {
                showMapOptionsPanel();
            }
        }, 150);
    }

    function hideMapOptionsPanel() {
        if (!$mapOptionsPanel || !$mapOptionsPanel.length) return;

        $mapOptionsPanel.addClass('hidden-on-scroll');
        // Don't hide the view mode toggle - it should always be visible
    }

    function showMapOptionsPanel() {
        if (!$mapOptionsPanel || !$mapOptionsPanel.length) return;

        $mapOptionsPanel.removeClass('hidden-on-scroll');
        // View mode toggle remains unaffected
    }

    // Initialize
    $(document).ready(function() {
        setTimeout(initMobileScrollBehavior, 500);

        // Reinitialize on resize
        let resizeTimer;
        $(window).on('resize', function() {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(function() {
                lastScrollTop = 0;
                initMobileScrollBehavior();
            }, 250);
        });
    });

    // Also initialize when map is ready
    $(document).on('mldMapReady bmeMapReady mapInitialized', function() {
        setTimeout(initMobileScrollBehavior, 1000);
    });

    // Global debug object
    window.MLD_ScrollDebug = {
        checkView: checkViewMode,
        hide: hideMapOptionsPanel,
        show: showMapOptionsPanel,
        setListView: () => { isListView = true; },
        setMapView: () => { isListView = false; },
        getState: () => ({
            isListView,
            lastScrollTop,
            panelExists: !!$mapOptionsPanel && $mapOptionsPanel.length > 0,
            panelHidden: $mapOptionsPanel && $mapOptionsPanel.hasClass('hidden-on-scroll')
        })
    };

})(jQuery);