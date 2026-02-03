/**
 * Mobile Image Fallback System
 * Comprehensive image loading with multiple fallback strategies
 * Version: 1.0.0
 */

(function() {
    'use strict';

    const ImageFallbackSystem = {
        config: {
            retryAttempts: 3,
            retryDelay: 1000,
            fallbackImage: 'data:image/svg+xml,%3Csvg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 400 300"%3E%3Crect fill="%23f0f0f0" width="400" height="300"/%3E%3Ctext x="50%25" y="50%25" text-anchor="middle" dy=".3em" fill="%23999" font-family="sans-serif" font-size="16"%3EImage unavailable%3C/text%3E%3C/svg%3E',
            loadingImage: 'data:image/svg+xml,%3Csvg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 400 300"%3E%3Crect fill="%23f8f8f8" width="400" height="300"/%3E%3Ccircle cx="200" cy="150" r="20" fill="none" stroke="%23ddd" stroke-width="4"%3E%3Canimate attributeName="stroke-dasharray" values="0 126;126 126" dur="1.5s" repeatCount="indefinite"/%3E%3C/circle%3E%3C/svg%3E'
        },

        imageStates: new Map(),

        init: function() {

            // Process all images
            this.processAllImages();

            // Setup mutation observer for dynamically added images
            this.setupMutationObserver();

            // Setup global error handler
            this.setupGlobalErrorHandler();
        },

        processAllImages: function() {
            const images = document.querySelectorAll('img');

            images.forEach((img, index) => {
                this.processImage(img, index);
            });
        },

        processImage: function(img, index) {
            // Skip if already processed
            if (img.dataset.fallbackProcessed === 'true') {
                return;
            }

            img.dataset.fallbackProcessed = 'true';
            img.dataset.imageIndex = index;

            // Store original source
            const originalSrc = img.src || img.dataset.src;
            if (originalSrc) {
                img.dataset.originalSrc = originalSrc;
            }

            // Initialize state
            this.imageStates.set(img, {
                attempts: 0,
                loaded: false,
                error: false
            });

            // Add loading class
            img.classList.add('mld-image-loading');

            // Handle different loading scenarios
            if (img.complete) {
                // Image already loaded or failed
                if (img.naturalWidth === 0) {
                    this.handleImageError(img);
                } else {
                    this.handleImageLoad(img);
                }
            } else {
                // Setup event listeners
                img.addEventListener('load', () => this.handleImageLoad(img), { once: true });
                img.addEventListener('error', () => this.handleImageError(img), { once: true });

                // Set loading placeholder if image has no src yet
                if (!img.src && img.dataset.src) {
                    img.src = this.config.loadingImage;
                    // Trigger lazy loading
                    this.lazyLoadImage(img);
                }
            }
        },

        handleImageLoad: function(img) {
            const state = this.imageStates.get(img);
            if (state) {
                state.loaded = true;
                state.error = false;
            }

            img.classList.remove('mld-image-loading', 'mld-image-error');
            img.classList.add('mld-image-loaded');

        },

        handleImageError: function(img) {
            const state = this.imageStates.get(img) || { attempts: 0 };
            state.attempts++;
            state.error = true;


            // Try different strategies
            if (state.attempts <= this.config.retryAttempts) {
                this.retryImage(img, state.attempts);
            } else {
                this.applyFallback(img);
            }

            this.imageStates.set(img, state);
        },

        retryImage: function(img, attemptNumber) {

            setTimeout(() => {
                const originalSrc = img.dataset.originalSrc;

                if (originalSrc) {
                    // Try different strategies based on attempt number
                    switch (attemptNumber) {
                        case 1:
                            // Retry original URL
                            this.reloadImage(img, originalSrc);
                            break;
                        case 2:
                            // Try with cache buster
                            this.reloadImage(img, this.addCacheBuster(originalSrc));
                            break;
                        case 3:
                            // Try alternative CDN or proxy
                            this.tryAlternativeSource(img, originalSrc);
                            break;
                        default:
                            this.applyFallback(img);
                    }
                } else {
                    this.applyFallback(img);
                }
            }, this.config.retryDelay * attemptNumber);
        },

        reloadImage: function(img, src) {
            // Create new image to test loading
            const testImg = new Image();

            testImg.onload = () => {
                img.src = src;
                this.handleImageLoad(img);
            };

            testImg.onerror = () => {
                this.handleImageError(img);
            };

            testImg.src = src;
        },

        addCacheBuster: function(url) {
            const separator = url.includes('?') ? '&' : '?';
            return `${url}${separator}_cb=${Date.now()}`;
        },

        tryAlternativeSource: function(img, originalSrc) {
            // Check if it's a relative URL that needs fixing
            if (!originalSrc.startsWith('http')) {
                const baseUrl = window.location.origin;
                const fullUrl = new URL(originalSrc, baseUrl).href;
                this.reloadImage(img, fullUrl);
                return;
            }

            // Try removing query parameters
            const urlWithoutParams = originalSrc.split('?')[0];
            if (urlWithoutParams !== originalSrc) {
                this.reloadImage(img, urlWithoutParams);
                return;
            }

            // If all else fails, use fallback
            this.applyFallback(img);
        },

        applyFallback: function(img) {

            img.src = this.config.fallbackImage;
            img.classList.remove('mld-image-loading');
            img.classList.add('mld-image-error', 'mld-image-fallback');

            // Add error message overlay if in gallery
            if (img.closest('.mld-photo-item')) {
                this.addErrorOverlay(img);
            }
        },

        addErrorOverlay: function(img) {
            const container = img.closest('.mld-photo-item');
            if (!container || container.querySelector('.image-error-overlay')) {
                return;
            }

            const overlay = document.createElement('div');
            overlay.className = 'image-error-overlay';
            overlay.style.cssText = `
                position: absolute;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
                background: rgba(0,0,0,0.8);
                color: white;
                padding: 20px;
                border-radius: 8px;
                text-align: center;
                z-index: 10;
            `;
            overlay.innerHTML = `
                <div style="margin-bottom: 10px;">⚠️ Image unavailable</div>
                <button onclick="ImageFallbackSystem.retryManual(this)" style="
                    background: #4CAF50;
                    color: white;
                    border: none;
                    padding: 8px 16px;
                    border-radius: 4px;
                    cursor: pointer;
                ">Retry</button>
            `;

            container.appendChild(overlay);
        },

        retryManual: function(button) {
            const container = button.closest('.mld-photo-item');
            const img = container.querySelector('img');
            const overlay = container.querySelector('.image-error-overlay');

            if (overlay) {
                overlay.remove();
            }

            // Reset state and retry
            const state = this.imageStates.get(img);
            if (state) {
                state.attempts = 0;
            }

            const originalSrc = img.dataset.originalSrc;
            if (originalSrc) {
                this.reloadImage(img, originalSrc);
            }
        },

        lazyLoadImage: function(img) {
            if ('IntersectionObserver' in window) {
                const observer = new IntersectionObserver((entries) => {
                    entries.forEach(entry => {
                        if (entry.isIntersecting) {
                            const lazyImg = entry.target;
                            if (lazyImg.dataset.src) {
                                lazyImg.src = lazyImg.dataset.src;
                                lazyImg.removeAttribute('data-src');
                                observer.unobserve(lazyImg);
                            }
                        }
                    });
                }, { rootMargin: '50px' });

                observer.observe(img);
            } else {
                // Fallback for browsers without IntersectionObserver
                if (img.dataset.src) {
                    img.src = img.dataset.src;
                }
            }
        },

        setupMutationObserver: function() {
            if (!('MutationObserver' in window)) {
                return;
            }

            const observer = new MutationObserver((mutations) => {
                mutations.forEach(mutation => {
                    mutation.addedNodes.forEach(node => {
                        if (node.tagName === 'IMG') {
                            this.processImage(node, Date.now());
                        } else if (node.querySelectorAll) {
                            const images = node.querySelectorAll('img');
                            images.forEach(img => this.processImage(img, Date.now()));
                        }
                    });
                });
            });

            observer.observe(document.body, {
                childList: true,
                subtree: true
            });
        },

        setupGlobalErrorHandler: function() {
            window.addEventListener('error', (e) => {
                if (e.target && e.target.tagName === 'IMG') {
                    this.handleImageError(e.target);
                }
            }, true);
        }
    };

    // Auto-initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => ImageFallbackSystem.init());
    } else {
        ImageFallbackSystem.init();
    }

    // Export for manual use
    window.ImageFallbackSystem = ImageFallbackSystem;

})();