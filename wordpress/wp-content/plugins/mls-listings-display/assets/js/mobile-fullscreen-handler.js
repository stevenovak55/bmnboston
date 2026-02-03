/**
 * Mobile Fullscreen Handler
 * Creates app-like fullscreen experience on mobile devices
 *
 * @package MLS_Listings_Display
 * @since 6.11.17
 */
(function() {
    'use strict';

    const STORAGE_KEY = 'mld_fullscreen_disabled';

    class MobileFullscreenHandler {
        constructor() {
            this.isFullscreen = false;
            this.isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent);
            this.isAndroid = /Android/.test(navigator.userAgent);
            this.supportsFullscreen = document.fullscreenEnabled ||
                                      document.webkitFullscreenEnabled;
            // v6.25.13: Check if we're on a property page
            this.isPropertyPage = document.body.classList.contains('mld-property-mobile-v3');
            this.init();
        }

        init() {
            // v6.25.18: Skip fullscreen entirely on iPhone - iOS doesn't support Fullscreen API
            // The toggle would do nothing, so don't show it at all
            if (/iPhone/.test(navigator.userAgent)) {
                return;
            }

            // Check if user previously disabled fullscreen
            if (localStorage.getItem(STORAGE_KEY) === 'true') {
                this.addEnableButton();
                this.bindEvents(); // v6.25.13: Still bind events for fullscreen state changes
                return;
            }

            // v6.25.13: On property pages, don't auto-enter - just show enable button
            if (this.isPropertyPage) {
                this.addEnableButton();
                this.bindEvents(); // Bind events to handle native fullscreen exit
                return;
            }

            // Auto-enter fullscreen on page load (search pages only)
            this.enterFullscreen();
            this.createExitButton();
            this.bindEvents();
        }

        enterFullscreen(isUserGesture = false) {
            // v6.25.13: On property pages, only use native fullscreen (no CSS changes)
            if (this.isPropertyPage) {
                if (isUserGesture && this.supportsFullscreen) {
                    this.requestNativeFullscreen();
                }
                this.isFullscreen = true;
                return;
            }

            // For search pages: apply CSS fullscreen for consistent experience
            this.enterCSSFullscreen();

            // On Android with user gesture, also try native fullscreen for true immersion
            if (isUserGesture && this.isAndroid && this.supportsFullscreen) {
                this.requestNativeFullscreen();
            }

            this.isFullscreen = true;
        }

        requestNativeFullscreen() {
            const elem = document.documentElement;
            if (elem.requestFullscreen) {
                elem.requestFullscreen().catch(err => {
                    // Fullscreen denied - CSS fallback already applied
                });
            } else if (elem.webkitRequestFullscreen) {
                elem.webkitRequestFullscreen();
            }
        }

        enterCSSFullscreen() {
            document.body.classList.add('mld-fullscreen-mode');
            // Update viewport meta for iOS
            this.updateViewport(true);
        }

        exitFullscreen() {
            // Exit native fullscreen if active
            if (document.fullscreenElement) {
                document.exitFullscreen().catch(() => {});
            }
            // v6.25.13: Only remove CSS class on non-property pages
            if (!this.isPropertyPage) {
                document.body.classList.remove('mld-fullscreen-mode');
                this.updateViewport(false);
            }
            this.isFullscreen = false;
        }

        updateViewport(fullscreen) {
            const viewport = document.querySelector('meta[name="viewport"]');
            if (viewport) {
                if (fullscreen) {
                    viewport.content = 'width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover';
                } else {
                    viewport.content = 'width=device-width, initial-scale=1.0';
                }
            }
        }

        createExitButton() {
            const btn = document.createElement('button');
            btn.id = 'mld-fullscreen-exit';
            btn.className = 'mld-fullscreen-exit-btn';
            btn.innerHTML = `
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M8 3v3a2 2 0 0 1-2 2H3m18 0h-3a2 2 0 0 1-2-2V3m0 18v-3a2 2 0 0 1 2-2h3M3 16h3a2 2 0 0 1 2 2v3"/>
                </svg>
            `;
            btn.setAttribute('aria-label', 'Exit fullscreen');
            btn.addEventListener('click', () => this.disableFullscreen());
            document.body.appendChild(btn);
        }

        addEnableButton() {
            const btn = document.createElement('button');
            btn.id = 'mld-fullscreen-enable';
            btn.className = 'mld-fullscreen-enable-btn';
            btn.innerHTML = `
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M8 3H5a2 2 0 0 0-2 2v3m18 0V5a2 2 0 0 0-2-2h-3m0 18h3a2 2 0 0 0 2-2v-3M3 16v3a2 2 0 0 0 2 2h3"/>
                </svg>
            `;
            btn.setAttribute('aria-label', 'Enable fullscreen');
            btn.addEventListener('click', () => this.enableFullscreen());
            document.body.appendChild(btn);
        }

        disableFullscreen() {
            localStorage.setItem(STORAGE_KEY, 'true');
            this.exitFullscreen();
            document.getElementById('mld-fullscreen-exit')?.remove();
            this.addEnableButton();
        }

        enableFullscreen() {
            localStorage.removeItem(STORAGE_KEY);
            document.getElementById('mld-fullscreen-enable')?.remove();
            this.enterFullscreen(true); // User gesture - can request native fullscreen
            this.createExitButton();
        }

        bindEvents() {
            // Handle fullscreen change (user pressed ESC or system exit)
            document.addEventListener('fullscreenchange', () => {
                if (!document.fullscreenElement && this.isFullscreen) {
                    // v6.25.13: Only remove CSS class on non-property pages
                    if (!this.isPropertyPage) {
                        document.body.classList.remove('mld-fullscreen-mode');
                    }
                    // On property pages, show enable button again when exiting native fullscreen
                    if (this.isPropertyPage) {
                        this.isFullscreen = false;
                        document.getElementById('mld-fullscreen-exit')?.remove();
                        this.addEnableButton();
                    }
                }
            });

            // Handle page visibility change
            document.addEventListener('visibilitychange', () => {
                // v6.25.13: Skip auto re-enter on property pages
                if (this.isPropertyPage) return;

                if (document.visibilityState === 'visible' &&
                    localStorage.getItem(STORAGE_KEY) !== 'true') {
                    // Re-enter fullscreen when coming back to page
                    this.enterFullscreen();
                }
            });
        }
    }

    // Initialize on DOM ready for mobile pages only
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initFullscreen);
    } else {
        initFullscreen();
    }

    function initFullscreen() {
        // Only init on mobile
        if (window.innerWidth <= 768 ||
            /Android|iPhone|iPad|iPod/i.test(navigator.userAgent)) {
            window.mldFullscreenHandler = new MobileFullscreenHandler();
        }
    }
})();
