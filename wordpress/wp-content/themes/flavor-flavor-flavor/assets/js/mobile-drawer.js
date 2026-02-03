/**
 * Mobile Drawer Navigation
 *
 * Handles slide-in drawer menu functionality including:
 * - Open/close with animation
 * - Body scroll lock
 * - Focus trap for accessibility
 * - Escape key to close
 * - Collapsible user menu section (v1.2.0)
 * - Chatbot bubble visibility toggle (v1.3.0)
 *
 * @package flavor_flavor_flavor
 * @version 1.3.0
 */

(function() {
    'use strict';

    const SELECTORS = {
        drawer: '#mobile-drawer',
        overlay: '.bne-drawer-overlay',
        toggle: '.bne-header__menu-toggle',
        closeBtn: '.bne-drawer__close',
        userToggle: '.bne-drawer__user-toggle',
        userNav: '.bne-drawer__user-nav',
        focusableElements: 'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
    };

    let drawer, overlay, toggleBtn, closeBtn;
    let scrollPosition = 0;
    let focusTrapHandler = null;

    /**
     * Initialize drawer functionality
     */
    function init() {
        drawer = document.querySelector(SELECTORS.drawer);
        overlay = document.querySelector(SELECTORS.overlay);
        toggleBtn = document.querySelector(SELECTORS.toggle);
        closeBtn = drawer ? drawer.querySelector(SELECTORS.closeBtn) : null;

        if (!drawer || !toggleBtn) {
            return;
        }

        // Event listeners
        toggleBtn.addEventListener('click', openDrawer);

        if (closeBtn) {
            closeBtn.addEventListener('click', closeDrawer);
        }

        if (overlay) {
            overlay.addEventListener('click', closeDrawer);
        }

        // Keyboard navigation
        document.addEventListener('keydown', handleKeydown);

        // Close drawer on window resize to desktop
        window.addEventListener('resize', handleResize);

        // User menu toggle (v1.2.0)
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
                userNav.classList.remove('bne-drawer__user-nav--expanded');
                userNav.classList.add('bne-drawer__user-nav--collapsed');
            } else {
                // Expand
                this.setAttribute('aria-expanded', 'true');
                userNav.classList.remove('bne-drawer__user-nav--collapsed');
                userNav.classList.add('bne-drawer__user-nav--expanded');
            }
        });
    }

    /**
     * Open drawer
     */
    function openDrawer() {
        if (!drawer) return;

        // Save scroll position
        scrollPosition = window.pageYOffset;

        // Lock body scroll
        document.body.classList.add('drawer-open');
        document.body.style.top = '-' + scrollPosition + 'px';

        // Show drawer
        drawer.classList.add('is-open');
        drawer.setAttribute('aria-hidden', 'false');

        if (overlay) {
            overlay.classList.add('is-visible');
        }

        toggleBtn.setAttribute('aria-expanded', 'true');

        // Focus management
        setupFocusTrap();

        // Focus on close button after animation
        setTimeout(function() {
            if (closeBtn) {
                closeBtn.focus();
            }
        }, 100);

        // Hide chatbot bubble when drawer is open (v1.3.0)
        hideChatbot();
    }

    /**
     * Close drawer
     */
    function closeDrawer() {
        if (!drawer) return;

        // Hide drawer
        drawer.classList.remove('is-open');
        drawer.setAttribute('aria-hidden', 'true');

        if (overlay) {
            overlay.classList.remove('is-visible');
        }

        toggleBtn.setAttribute('aria-expanded', 'false');

        // Remove focus trap
        removeFocusTrap();

        // Restore scroll
        document.body.classList.remove('drawer-open');
        document.body.style.top = '';
        window.scrollTo(0, scrollPosition);

        // Return focus to toggle button
        toggleBtn.focus();

        // Show chatbot bubble when drawer is closed (v1.3.0)
        showChatbot();
    }

    /**
     * Hide chatbot bubble (v1.3.0)
     */
    function hideChatbot() {
        var chatbot = document.getElementById('mld-chatbot-widget');
        if (chatbot) {
            chatbot.style.display = 'none';
        }
    }

    /**
     * Show chatbot bubble (v1.3.0)
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
        if (!drawer || !drawer.classList.contains('is-open')) {
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
        const focusableElements = drawer.querySelectorAll(SELECTORS.focusableElements);
        const firstFocusable = focusableElements[0];
        const lastFocusable = focusableElements[focusableElements.length - 1];

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

    /**
     * Handle window resize
     */
    function handleResize() {
        // Close drawer if window is resized to desktop
        if (window.innerWidth >= 1024 && drawer && drawer.classList.contains('is-open')) {
            closeDrawer();
        }
    }

    // Initialize on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
