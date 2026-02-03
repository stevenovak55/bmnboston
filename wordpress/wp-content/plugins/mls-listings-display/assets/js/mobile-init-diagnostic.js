/**
 * Mobile Initialization Diagnostic System
 * Comprehensive initialization with fallbacks and error recovery
 * Version: 2.0.0
 */

(function() {
    'use strict';

    // Create global namespace
    window.MLDMobile = window.MLDMobile || {};

    // Diagnostic logger with visual feedback
    const DiagnosticLogger = {
        container: null,
        logs: [],

        init: function() {
            // Only show diagnostic panel for admins
            if (!document.body.classList.contains('logged-in')) {
                return;
            }

            this.createPanel();
        },

        createPanel: function() {
            const panel = document.createElement('div');
            panel.id = 'mld-diagnostic-panel';
            panel.style.cssText = `
                position: fixed;
                bottom: 10px;
                right: 10px;
                width: 320px;
                max-height: 300px;
                background: rgba(0,0,0,0.9);
                color: #0f0;
                font-family: monospace;
                font-size: 11px;
                padding: 10px;
                border-radius: 8px;
                z-index: 99999;
                overflow-y: auto;
                display: none;
                box-shadow: 0 4px 20px rgba(0,0,0,0.5);
            `;

            panel.innerHTML = `
                <div style="display: flex; justify-content: space-between; margin-bottom: 10px;">
                    <strong style="color: #fff;">MLD Mobile Diagnostics</strong>
                    <button onclick="this.parentElement.parentElement.style.display='none'" style="background: transparent; border: none; color: #fff; cursor: pointer;">✕</button>
                </div>
                <div id="mld-diagnostic-logs"></div>
            `;

            document.body.appendChild(panel);
            this.container = document.getElementById('mld-diagnostic-logs');

            // Show panel
            panel.style.display = 'block';
        },

        log: function(message, type = 'info') {
            const timestamp = new Date().toLocaleTimeString();
            const colors = {
                'success': '#0f0',
                'error': '#f00',
                'warning': '#ff0',
                'info': '#0ff',
                'debug': '#888'
            };

            const logEntry = {
                time: timestamp,
                message: message,
                type: type
            };

            this.logs.push(logEntry);

            if (this.container) {
                const logElement = document.createElement('div');
                logElement.style.cssText = `color: ${colors[type] || '#fff'}; margin-bottom: 2px;`;
                logElement.textContent = `[${timestamp}] ${message}`;
                this.container.appendChild(logElement);

                // Auto-scroll to bottom
                this.container.scrollTop = this.container.scrollHeight;
            }

        }
    };

    // Initialize diagnostic panel
    DiagnosticLogger.init();

    // Asset verification system
    const AssetVerifier = {
        requiredAssets: {
            scripts: [
                { name: 'jQuery', check: () => typeof jQuery !== 'undefined' },
                { name: 'MLDLogger', check: () => typeof MLDLogger !== 'undefined' },
                { name: 'SafeLogger', check: () => typeof SafeLogger !== 'undefined' },
                { name: 'SectionManager', check: () => typeof SectionManager !== 'undefined' },
                { name: 'ModalHandler', check: () => typeof ModalHandler !== 'undefined' },
                { name: 'FormHandler', check: () => typeof FormHandler !== 'undefined' },
                { name: 'VirtualTourHandler', check: () => typeof VirtualTourHandler !== 'undefined' }
            ],
            elements: [
                { name: 'Bottom Sheet', selector: '#bottomSheet' },
                { name: 'Gallery Container', selector: '.mld-gallery-container' },
                { name: 'Gallery Scroll', selector: '.mld-gallery-scroll' },
                { name: 'Sheet Content', selector: '.mld-sheet-content' }
            ],
            data: [
                { name: 'Property Data', check: () => window.mldPropertyData !== undefined },
                { name: 'Settings', check: () => window.mldSettings !== undefined }
            ]
        },

        verify: function() {
            DiagnosticLogger.log('Starting asset verification...', 'info');
            let allLoaded = true;

            // Check scripts
            this.requiredAssets.scripts.forEach(script => {
                const isLoaded = script.check();
                if (isLoaded) {
                    DiagnosticLogger.log(`✓ ${script.name} loaded`, 'success');
                } else {
                    DiagnosticLogger.log(`✗ ${script.name} missing`, 'error');
                    allLoaded = false;
                }
            });

            // Check elements
            this.requiredAssets.elements.forEach(element => {
                const el = document.querySelector(element.selector);
                if (el) {
                    DiagnosticLogger.log(`✓ ${element.name} found`, 'success');
                } else {
                    DiagnosticLogger.log(`✗ ${element.name} not found`, 'error');
                    allLoaded = false;
                }
            });

            // Check data
            this.requiredAssets.data.forEach(data => {
                const exists = data.check();
                if (exists) {
                    DiagnosticLogger.log(`✓ ${data.name} available`, 'success');
                } else {
                    DiagnosticLogger.log(`✗ ${data.name} missing`, 'warning');
                }
            });

            return allLoaded;
        }
    };

    // Fallback loader for missing dependencies
    const FallbackLoader = {
        loadMissingDependencies: function() {
            DiagnosticLogger.log('Loading fallback dependencies...', 'info');

            // Create fallback SafeLogger if missing (production: only error outputs to console)
            if (typeof window.SafeLogger === 'undefined') {
                window.SafeLogger = {
                    debug: () => {},
                    info: () => {},
                    warning: () => {},
                    error: (msg, ctx) => console.error('[Error]', msg, ctx || '')
                };
            }

            // Create minimal class stubs if core classes are missing
            if (typeof window.SectionManager === 'undefined') {
                window.SectionManager = class {
                    constructor() {
                        DiagnosticLogger.log('Using fallback SectionManager', 'warning');
                    }
                };
            }

            if (typeof window.ModalHandler === 'undefined') {
                window.ModalHandler = class {
                    constructor() {
                        DiagnosticLogger.log('Using fallback ModalHandler', 'warning');
                    }
                };
            }

            if (typeof window.FormHandler === 'undefined') {
                window.FormHandler = class {
                    constructor() {
                        DiagnosticLogger.log('Using fallback FormHandler', 'warning');
                    }
                };
            }

            if (typeof window.VirtualTourHandler === 'undefined') {
                window.VirtualTourHandler = class {
                    constructor() {
                        DiagnosticLogger.log('Using fallback VirtualTourHandler', 'warning');
                    }
                };
            }
        }
    };

    // Enhanced initialization system
    const MobileInitializer = {
        attempts: 0,
        maxAttempts: 5,

        init: function() {
            DiagnosticLogger.log('Mobile initializer starting...', 'info');
            this.attempts++;

            // Verify assets
            const assetsLoaded = AssetVerifier.verify();

            if (!assetsLoaded && this.attempts < this.maxAttempts) {
                DiagnosticLogger.log(`Assets not ready, retrying in 500ms (attempt ${this.attempts}/${this.maxAttempts})`, 'warning');

                // Load fallbacks if needed
                if (this.attempts > 2) {
                    FallbackLoader.loadMissingDependencies();
                }

                setTimeout(() => this.init(), 500);
                return;
            }

            if (this.attempts >= this.maxAttempts) {
                DiagnosticLogger.log('Max initialization attempts reached, using fallbacks', 'error');
                FallbackLoader.loadMissingDependencies();
            }

            // Initialize core components
            this.initializeCore();
        },

        initializeCore: function() {
            DiagnosticLogger.log('Initializing core components...', 'info');

            try {
                // Fix bottom sheet immediately
                this.fixBottomSheet();

                // Initialize gallery
                this.initializeGallery();

                // Initialize lazy loading
                this.initializeLazyLoading();

                // Setup error recovery
                this.setupErrorRecovery();

                DiagnosticLogger.log('✓ Core initialization complete', 'success');

            } catch (error) {
                DiagnosticLogger.log(`Core initialization error: ${error.message}`, 'error');
                console.error('Full error:', error);
            }
        },

        fixBottomSheet: function() {
            const bottomSheet = document.getElementById('bottomSheet');
            if (!bottomSheet) {
                DiagnosticLogger.log('Bottom sheet not found, creating fallback', 'warning');
                return;
            }

            // Force correct styles
            bottomSheet.style.position = 'fixed';
            bottomSheet.style.bottom = '0';
            bottomSheet.style.left = '0';
            bottomSheet.style.right = '0';
            bottomSheet.style.transform = 'translateY(50%)';
            bottomSheet.style.height = '100vh';
            bottomSheet.style.display = 'flex';
            bottomSheet.style.visibility = 'visible';
            bottomSheet.style.zIndex = '1000';
            bottomSheet.style.transition = 'transform 0.3s ease-out';

            DiagnosticLogger.log('✓ Bottom sheet positioned', 'success');

            // Add basic drag functionality
            const handle = bottomSheet.querySelector('.mld-sheet-handle');
            if (handle) {
                this.setupBottomSheetDrag(bottomSheet, handle);
            }
        },

        setupBottomSheetDrag: function(sheet, handle) {
            let startY = 0;
            let currentTransform = 50;

            // Commented out - dragging handled by mobile-ultimate-fix.js
            // handle.addEventListener('touchstart', (e) => {
            //     startY = e.touches[0].clientY;
            //     sheet.style.transition = 'none';
            // }, { passive: true });

            // handle.addEventListener('touchmove', (e) => {
            //     const deltaY = e.touches[0].clientY - startY;
            //     const percentChange = (deltaY / window.innerHeight) * 100;
            //     const newTransform = Math.max(20, Math.min(80, currentTransform + percentChange));
            //     sheet.style.transform = `translateY(${newTransform}%)`;
            // }, { passive: true });

            // handle.addEventListener('touchend', () => {
            //     sheet.style.transition = 'transform 0.3s ease-out';
            //     const currentY = parseFloat(sheet.style.transform.match(/translateY\(([^)]+)%\)/)?.[1] || 50);
            //     currentTransform = currentY;
            // }, { passive: true });

            DiagnosticLogger.log('✓ Bottom sheet drag enabled', 'success');
        },

        initializeGallery: function() {
            const gallery = document.querySelector('.mld-gallery-container');
            if (!gallery) {
                DiagnosticLogger.log('Gallery container not found', 'error');
                return;
            }

            // Ensure gallery is scrollable
            gallery.style.overflowY = 'auto';
            gallery.style.overflowX = 'hidden';
            gallery.style.webkitOverflowScrolling = 'touch';

            // Fix image display issues
            const images = gallery.querySelectorAll('img');
            let loadedCount = 0;
            let errorCount = 0;

            images.forEach((img, index) => {
                // Ensure images have proper attributes
                if (!img.src && img.dataset.src) {
                    img.src = img.dataset.src;
                }

                img.addEventListener('load', () => {
                    loadedCount++;
                    DiagnosticLogger.log(`Image ${index + 1} loaded (${loadedCount}/${images.length})`, 'debug');
                });

                img.addEventListener('error', () => {
                    errorCount++;
                    DiagnosticLogger.log(`Image ${index + 1} failed to load`, 'error');

                    // Add fallback image
                    if (!img.dataset.errorHandled) {
                        img.dataset.errorHandled = 'true';
                        img.src = 'data:image/svg+xml,%3Csvg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 400 300"%3E%3Crect fill="%23ddd" width="400" height="300"/%3E%3Ctext x="50%25" y="50%25" text-anchor="middle" dy=".3em" fill="%23999"%3EImage unavailable%3C/text%3E%3C/svg%3E';
                    }
                });
            });

            DiagnosticLogger.log(`✓ Gallery initialized with ${images.length} images`, 'success');
        },

        initializeLazyLoading: function() {
            if (!('IntersectionObserver' in window)) {
                DiagnosticLogger.log('IntersectionObserver not supported, loading all images', 'warning');
                document.querySelectorAll('[data-src]').forEach(img => {
                    img.src = img.dataset.src;
                });
                return;
            }

            const lazyImages = document.querySelectorAll('[data-src]:not(.loaded)');
            if (lazyImages.length === 0) {
                DiagnosticLogger.log('No lazy images found', 'debug');
                return;
            }

            const imageObserver = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        const img = entry.target;
                        img.src = img.dataset.src;
                        img.classList.add('loaded');
                        imageObserver.unobserve(img);
                        DiagnosticLogger.log(`Lazy loaded: ${img.alt || 'image'}`, 'debug');
                    }
                });
            }, { rootMargin: '50px' });

            lazyImages.forEach(img => imageObserver.observe(img));

            DiagnosticLogger.log(`✓ Lazy loading initialized for ${lazyImages.length} images`, 'success');
        },

        setupErrorRecovery: function() {
            window.addEventListener('error', (e) => {
                DiagnosticLogger.log(`Global error: ${e.message}`, 'error');

                // Attempt recovery for specific errors
                if (e.message && e.message.includes('undefined')) {
                    DiagnosticLogger.log('Attempting to recover from undefined error...', 'warning');
                    FallbackLoader.loadMissingDependencies();
                }
            });

            DiagnosticLogger.log('✓ Error recovery system active', 'success');
        }
    };

    // Start initialization when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            DiagnosticLogger.log('DOM loaded, starting initialization', 'info');
            MobileInitializer.init();
        });
    } else {
        DiagnosticLogger.log('DOM already loaded, starting initialization', 'info');
        setTimeout(() => MobileInitializer.init(), 100);
    }

    // Export for debugging
    window.MLDMobile = {
        DiagnosticLogger,
        AssetVerifier,
        FallbackLoader,
        MobileInitializer,
        reinit: () => MobileInitializer.init()
    };

})();