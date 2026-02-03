/**
 * MLD Navigation Drawer
 *
 * Handles slide-in drawer menu functionality including:
 * - Open/close with animation
 * - Body scroll lock
 * - Focus trap for accessibility
 * - Escape key to close
 * - Mobile hamburger repositioning (after filters button)
 * - Collapsible user menu section (v6.44.0)
 * - Chatbot bubble visibility toggle (v6.45.0)
 *
 * @package MLS_Listings_Display
 * @version 6.45.0
 */

(function() {
    'use strict';

    var SELECTORS = {
        drawer: '#mld-nav-drawer',
        overlay: '#mld-nav-overlay',
        toggle: '#mld-nav-toggle',
        toggleMobile: '#mld-nav-toggle-mobile',
        toggleSticky: '#mld-nav-toggle-sticky', // v6.25.3 - sticky nav hamburger
        closeBtn: '.mld-nav-drawer__close',
        userToggle: '.mld-nav-drawer__user-toggle',
        userNav: '.mld-nav-drawer__user-nav',
        filtersButton: '#bme-filters-button',
        focusableElements: 'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
    };

    var CLASSES = {
        drawerOpen: 'is-open',
        overlayVisible: 'is-visible',
        bodyLock: 'mld-drawer-open'
    };

    var drawer, overlay, toggleBtn, toggleBtnMobile, toggleBtnSticky, closeBtn;
    var activeToggleBtn = null; // Track which button opened the drawer
    var scrollPosition = 0;
    var focusTrapHandler = null;

    /**
     * Initialize drawer functionality
     */
    function init() {
        drawer = document.querySelector(SELECTORS.drawer);
        overlay = document.querySelector(SELECTORS.overlay);
        toggleBtn = document.querySelector(SELECTORS.toggle);
        closeBtn = drawer ? drawer.querySelector(SELECTORS.closeBtn) : null;

        if (!drawer) {
            return;
        }

        // Add mobile hamburger button after filters button
        addMobileToggle();

        toggleBtnMobile = document.querySelector(SELECTORS.toggleMobile);

        // Event listeners for desktop toggle
        if (toggleBtn) {
            toggleBtn.addEventListener('click', function() { openDrawer(this); });
        }

        // Event listeners for mobile toggle
        if (toggleBtnMobile) {
            toggleBtnMobile.addEventListener('click', function() { openDrawer(this); });
        }

        // Event listeners for sticky nav toggle (v6.25.3)
        toggleBtnSticky = document.querySelector(SELECTORS.toggleSticky);
        if (toggleBtnSticky) {
            toggleBtnSticky.addEventListener('click', function() { openDrawer(this); });
        }

        if (closeBtn) {
            closeBtn.addEventListener('click', closeDrawer);
        }

        if (overlay) {
            overlay.addEventListener('click', closeDrawer);
        }

        // Keyboard navigation
        document.addEventListener('keydown', handleKeydown);

        // User menu toggle (v6.44.0)
        initUserMenuToggle();
    }

    /**
     * Initialize collapsible user menu toggle
     */
    function initUserMenuToggle() {
        var userToggle = drawer ? drawer.querySelector(SELECTORS.userToggle) : null;
        var userNav = drawer ? drawer.querySelector(SELECTORS.userNav) : null;

        if (!userToggle || !userNav) {
            return;
        }

        userToggle.addEventListener('click', function() {
            var isExpanded = this.getAttribute('aria-expanded') === 'true';

            if (isExpanded) {
                // Collapse
                this.setAttribute('aria-expanded', 'false');
                userNav.classList.remove('mld-nav-drawer__user-nav--expanded');
                userNav.classList.add('mld-nav-drawer__user-nav--collapsed');
            } else {
                // Expand
                this.setAttribute('aria-expanded', 'true');
                userNav.classList.remove('mld-nav-drawer__user-nav--collapsed');
                userNav.classList.add('mld-nav-drawer__user-nav--expanded');
            }
        });
    }

    /**
     * Add mobile hamburger toggle after filters button
     */
    function addMobileToggle() {
        var filtersButton = document.querySelector(SELECTORS.filtersButton);
        if (!filtersButton) return;

        // Check if mobile toggle already exists
        if (document.querySelector(SELECTORS.toggleMobile)) return;

        // Create mobile hamburger button
        var mobileToggle = document.createElement('button');
        mobileToggle.id = 'mld-nav-toggle-mobile';
        mobileToggle.setAttribute('aria-controls', 'mld-nav-drawer');
        mobileToggle.setAttribute('aria-expanded', 'false');
        mobileToggle.setAttribute('aria-label', 'Open navigation menu');
        mobileToggle.innerHTML = '<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24" aria-hidden="true"><path d="M3 18h18v-2H3v2zm0-5h18v-2H3v2zm0-7v2h18V6H3z"/></svg>';

        // Insert after filters button
        filtersButton.parentNode.insertBefore(mobileToggle, filtersButton.nextSibling);
    }

    /**
     * Open drawer
     * @param {HTMLElement} triggerBtn - The button that triggered the open
     */
    function openDrawer(triggerBtn) {
        if (!drawer) return;

        // Track which button opened the drawer
        activeToggleBtn = triggerBtn || toggleBtn || toggleBtnSticky || toggleBtnMobile;

        // Save scroll position
        scrollPosition = window.pageYOffset || document.documentElement.scrollTop;

        // Lock body scroll
        document.body.classList.add(CLASSES.bodyLock);
        document.body.style.top = '-' + scrollPosition + 'px';

        // Show drawer
        drawer.classList.add(CLASSES.drawerOpen);
        drawer.setAttribute('aria-hidden', 'false');

        if (overlay) {
            overlay.classList.add(CLASSES.overlayVisible);
            overlay.setAttribute('aria-hidden', 'false');
        }

        // Update aria-expanded on the active toggle
        if (activeToggleBtn) {
            activeToggleBtn.setAttribute('aria-expanded', 'true');
        }

        // Focus management
        setupFocusTrap();

        // Focus on close button after animation
        setTimeout(function() {
            if (closeBtn) {
                closeBtn.focus();
            }
        }, 100);

        // Hide chatbot bubble when drawer is open (v6.45.0)
        hideChatbot();
    }

    /**
     * Close drawer
     */
    function closeDrawer() {
        if (!drawer) return;

        // Hide drawer
        drawer.classList.remove(CLASSES.drawerOpen);
        drawer.setAttribute('aria-hidden', 'true');

        if (overlay) {
            overlay.classList.remove(CLASSES.overlayVisible);
            overlay.setAttribute('aria-hidden', 'true');
        }

        // Update aria-expanded on the active toggle
        if (activeToggleBtn) {
            activeToggleBtn.setAttribute('aria-expanded', 'false');
        }

        // Remove focus trap
        removeFocusTrap();

        // Restore scroll
        document.body.classList.remove(CLASSES.bodyLock);
        document.body.style.top = '';
        window.scrollTo(0, scrollPosition);

        // Return focus to toggle button
        if (activeToggleBtn) {
            activeToggleBtn.focus();
        }

        // Clear active toggle
        activeToggleBtn = null;

        // Show chatbot bubble when drawer is closed (v6.45.0)
        showChatbot();
    }

    /**
     * Hide chatbot bubble (v6.45.0)
     */
    function hideChatbot() {
        var chatbot = document.getElementById('mld-chatbot-widget');
        if (chatbot) {
            chatbot.style.display = 'none';
        }
    }

    /**
     * Show chatbot bubble (v6.45.0)
     */
    function showChatbot() {
        var chatbot = document.getElementById('mld-chatbot-widget');
        if (chatbot) {
            chatbot.style.display = '';
        }
    }

    /**
     * Handle keyboard events
     */
    function handleKeydown(e) {
        if (!drawer || !drawer.classList.contains(CLASSES.drawerOpen)) {
            return;
        }

        if (e.key === 'Escape') {
            e.preventDefault();
            closeDrawer();
        }
    }

    /**
     * Setup focus trap within drawer
     */
    function setupFocusTrap() {
        var focusableElements = drawer.querySelectorAll(SELECTORS.focusableElements);
        var firstFocusable = focusableElements[0];
        var lastFocusable = focusableElements[focusableElements.length - 1];

        focusTrapHandler = function(e) {
            if (e.key !== 'Tab') return;

            if (e.shiftKey) {
                // Shift + Tab
                if (document.activeElement === firstFocusable) {
                    lastFocusable.focus();
                    e.preventDefault();
                }
            } else {
                // Tab
                if (document.activeElement === lastFocusable) {
                    firstFocusable.focus();
                    e.preventDefault();
                }
            }
        };

        drawer.addEventListener('keydown', focusTrapHandler);
    }

    /**
     * Remove focus trap
     */
    function removeFocusTrap() {
        if (focusTrapHandler) {
            drawer.removeEventListener('keydown', focusTrapHandler);
            focusTrapHandler = null;
        }
    }

    // Initialize on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // Expose for external use if needed
    window.MLDNavDrawer = {
        open: openDrawer,
        close: closeDrawer
    };

})();
