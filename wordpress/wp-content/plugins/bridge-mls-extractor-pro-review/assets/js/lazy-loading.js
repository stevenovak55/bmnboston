/**
 * BME Asset Optimization - Lazy Loading & Performance Enhancements
 * Version: 1.0.0
 */

(function () {
  'use strict';

  // Configuration from WordPress
  const config = window.BME_AssetOptimization || {};

  /**
   * Intersection Observer API for lazy loading images
   */
  class BMELazyLoader {
    constructor() {
      this.imageObserver = null;
      this.lazyImages = [];
      this.loaded = 0;
      this.total = 0;

      this.init();
    }

    init() {
      if (!config.lazy_loading) {
        return;
      }

      // Wait for DOM to be ready
      if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => this.setupLazyLoading());
      } else {
        this.setupLazyLoading();
      }
    }

    setupLazyLoading() {
      this.lazyImages = document.querySelectorAll('img.bme-lazy-image[loading="lazy"]');
      this.total = this.lazyImages.length;

      if (this.total === 0) {
        return;
      }

      // Use native lazy loading if supported, otherwise use Intersection Observer
      if ('IntersectionObserver' in window) {
        this.setupIntersectionObserver();
      } else {
        // Fallback: load all images immediately
        this.loadAllImages();
      }

      // Monitor loading progress
      this.monitorLoadingProgress();
    }

    setupIntersectionObserver() {
      const options = {
        root: null,
        rootMargin: '50px 0px',
        threshold: 0.01,
      };

      this.imageObserver = new IntersectionObserver((entries, observer) => {
        entries.forEach((entry) => {
          if (entry.isIntersecting) {
            const img = entry.target;
            this.loadImage(img);
            observer.unobserve(img);
          }
        });
      }, options);

      this.lazyImages.forEach((img) => {
        this.imageObserver.observe(img);
      });
    }

    loadImage(img) {
      return new Promise((resolve, reject) => {
        const imageLoader = new Image();

        imageLoader.onload = () => {
          // Apply any additional optimizations
          this.applyImageOptimizations(img);

          // Add loaded class for CSS transitions
          img.classList.add('bme-loaded');
          img.classList.remove('bme-loading');

          this.loaded++;
          this.updateLoadingProgress();

          resolve(img);
        };

        imageLoader.onerror = () => {
          img.classList.add('bme-error');
          img.classList.remove('bme-loading');
          reject(new Error(`Failed to load image: ${img.src}`));
        };

        // Start loading
        img.classList.add('bme-loading');
        imageLoader.src = img.src;
      });
    }

    applyImageOptimizations(img) {
      // WebP fallback handling
      if (config.webp_support && img.dataset.webpSrc) {
        img.src = img.dataset.webpSrc;
      }

      // Apply any CDN optimizations
      if (config.cdn_base && img.src.indexOf(config.cdn_base) === -1) {
        img.src = img.src.replace(window.location.origin, config.cdn_base);
      }
    }

    loadAllImages() {
      this.lazyImages.forEach((img) => {
        this.loadImage(img);
      });
    }

    updateLoadingProgress() {
      if (this.loaded === this.total) {
        this.onAllImagesLoaded();
      }

      // Dispatch progress event
      document.dispatchEvent(
        new CustomEvent('bme:lazyLoadProgress', {
          detail: {
            loaded: this.loaded,
            total: this.total,
            percentage: Math.round((this.loaded / this.total) * 100),
          },
        })
      );
    }

    onAllImagesLoaded() {
      document.dispatchEvent(
        new CustomEvent('bme:lazyLoadComplete', {
          detail: {
            total: this.total,
            loadTime: performance.now(),
          },
        })
      );

      // Cleanup observer
      if (this.imageObserver) {
        this.imageObserver.disconnect();
      }
    }

    monitorLoadingProgress() {
      // Add loading progress indicator if needed
      if (this.total > 10) {
        this.createProgressIndicator();
      }
    }

    createProgressIndicator() {
      const indicator = document.createElement('div');
      indicator.className = 'bme-loading-indicator';
      indicator.innerHTML = `
                <div class="bme-loading-bar">
                    <div class="bme-loading-progress" style="width: 0%"></div>
                </div>
                <div class="bme-loading-text">Loading images...</div>
            `;

      document.body.appendChild(indicator);

      // Update progress
      document.addEventListener('bme:lazyLoadProgress', (e) => {
        const progress = indicator.querySelector('.bme-loading-progress');
        const text = indicator.querySelector('.bme-loading-text');

        if (progress) {
          progress.style.width = e.detail.percentage + '%';
        }

        if (text) {
          text.textContent = `Loading images... ${e.detail.loaded}/${e.detail.total}`;
        }
      });

      // Remove when complete
      document.addEventListener('bme:lazyLoadComplete', () => {
        setTimeout(() => {
          if (indicator && indicator.parentNode) {
            indicator.parentNode.removeChild(indicator);
          }
        }, 1000);
      });
    }
  }

  /**
   * Performance monitoring
   */
  class BMEPerformanceMonitor {
    constructor() {
      this.metrics = {
        loadStart: performance.now(),
        firstPaint: 0,
        domContentLoaded: 0,
        windowLoad: 0,
        lazyLoadComplete: 0,
      };

      this.init();
    }

    init() {
      // Monitor key performance events
      document.addEventListener('DOMContentLoaded', () => {
        this.metrics.domContentLoaded = performance.now();
      });

      window.addEventListener('load', () => {
        this.metrics.windowLoad = performance.now();
        this.reportPerformance();
      });

      document.addEventListener('bme:lazyLoadComplete', () => {
        this.metrics.lazyLoadComplete = performance.now();
        this.reportPerformance();
      });

      // Monitor First Paint if available
      if ('PerformanceObserver' in window) {
        this.observePaintMetrics();
      }
    }

    observePaintMetrics() {
      try {
        const observer = new PerformanceObserver((list) => {
          const entries = list.getEntries();
          entries.forEach((entry) => {
            if (entry.name === 'first-paint') {
              this.metrics.firstPaint = entry.startTime;
            }
          });
        });

        observer.observe({ entryTypes: ['paint'] });
      } catch (e) {
        // Paint metrics not available
      }
    }

    reportPerformance() {
      const report = {
        domContentLoaded: Math.round(this.metrics.domContentLoaded - this.metrics.loadStart),
        windowLoad: Math.round(this.metrics.windowLoad - this.metrics.loadStart),
        firstPaint: Math.round(this.metrics.firstPaint),
        lazyLoadComplete: Math.round(this.metrics.lazyLoadComplete - this.metrics.loadStart),
        timestamp: new Date().toISOString(),
      };

      // Send to WordPress via AJAX if needed
      if (window.wp && window.wp.ajax) {
        wp.ajax
          .post('bme_performance_metrics', {
            data: report,
            nonce: window.bme_nonce,
          })
          .catch((error) => {
            // Failed to send metrics silently
          });
      }

    }
  }

  /**
   * Asset preloading for critical resources
   */
  class BMEAssetPreloader {
    constructor() {
      this.preloadedAssets = new Set();
      this.init();
    }

    init() {
      // Preload critical assets
      this.preloadCriticalAssets();

      // Setup hover preloading for interactive elements
      this.setupHoverPreloading();
    }

    preloadCriticalAssets() {
      const criticalAssets = [
        // Add critical CSS/JS files
        config.cdn_base +
          '/wp-content/plugins/bridge-mls-extractor-pro/assets/css/advanced-search.css',
      ].filter(Boolean);

      criticalAssets.forEach((asset) => {
        this.preloadAsset(asset);
      });
    }

    preloadAsset(url) {
      if (this.preloadedAssets.has(url)) {
        return;
      }

      const link = document.createElement('link');
      link.rel = 'preload';

      if (url.endsWith('.css')) {
        link.as = 'style';
      } else if (url.endsWith('.js')) {
        link.as = 'script';
      } else if (url.match(/\.(jpg|jpeg|png|webp|gif)$/)) {
        link.as = 'image';
      }

      link.href = url;
      document.head.appendChild(link);

      this.preloadedAssets.add(url);
    }

    setupHoverPreloading() {
      // Preload assets on hover for better UX
      document.addEventListener('mouseover', (e) => {
        const link = e.target.closest('a');
        if (link && link.href && !this.preloadedAssets.has(link.href)) {
          // Only preload internal links
          if (link.hostname === window.location.hostname) {
            this.preloadAsset(link.href);
          }
        }
      });
    }
  }

  // Initialize all optimization modules
  document.addEventListener('DOMContentLoaded', () => {
    new BMELazyLoader();
    new BMEPerformanceMonitor();
    new BMEAssetPreloader();

    // Add optimization CSS if not already present
    if (!document.getElementById('bme-optimization-css')) {
      const style = document.createElement('style');
      style.id = 'bme-optimization-css';
      style.textContent = `
                .bme-lazy-image {
                    transition: opacity 0.3s ease-in-out;
                }
                .bme-lazy-image.bme-loading {
                    opacity: 0.5;
                }
                .bme-lazy-image.bme-loaded {
                    opacity: 1;
                }
                .bme-lazy-image.bme-error {
                    opacity: 0.3;
                    filter: grayscale(100%);
                }
                .bme-loading-indicator {
                    position: fixed;
                    top: 20px;
                    right: 20px;
                    background: rgba(0,0,0,0.8);
                    color: white;
                    padding: 10px 15px;
                    border-radius: 5px;
                    z-index: 10000;
                    font-size: 12px;
                }
                .bme-loading-bar {
                    width: 150px;
                    height: 3px;
                    background: rgba(255,255,255,0.3);
                    border-radius: 2px;
                    overflow: hidden;
                    margin-bottom: 5px;
                }
                .bme-loading-progress {
                    height: 100%;
                    background: #00a0d2;
                    transition: width 0.3s ease;
                }
            `;
      document.head.appendChild(style);
    }
  });
})();
