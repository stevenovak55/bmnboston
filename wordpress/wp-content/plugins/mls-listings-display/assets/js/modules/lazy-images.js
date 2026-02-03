/**
 * Lazy Loading Module for Images
 * Uses Intersection Observer API for efficient image loading
 *
 * @package
 * @since 4.3.0
 */

(function () {
  'use strict';

  class LazyImageLoader {
    constructor(options = {}) {
      this.options = {
        rootMargin: options.rootMargin || '50px 0px',
        threshold: options.threshold || 0.01,
        loadingClass: options.loadingClass || 'mld-lazy-loading',
        loadedClass: options.loadedClass || 'mld-lazy-loaded',
        errorClass: options.errorClass || 'mld-lazy-error',
        selector: options.selector || '[data-lazy-src]',
        srcAttribute: options.srcAttribute || 'data-lazy-src',
        srcsetAttribute: options.srcsetAttribute || 'data-lazy-srcset',
        sizesAttribute: options.sizesAttribute || 'data-lazy-sizes',
        backgroundAttribute: options.backgroundAttribute || 'data-lazy-bg',
        retryAttempts: options.retryAttempts || 3,
        retryDelay: options.retryDelay || 1000,
      };

      this.images = [];
      this.observer = null;
      this.retryMap = new Map();

      this.init();
    }

    init() {
      if (!('IntersectionObserver' in window)) {
        // Fallback for browsers without Intersection Observer
        MLDLogger.warning('Intersection Observer not supported. Loading all images immediately.');
        this.loadAllImages();
        return;
      }

      this.createObserver();
      this.observeImages();

      // Re-observe on DOM changes
      this.setupMutationObserver();

      // Performance monitoring
      if (window.MLD_Performance_Monitor) {
        window.MLD_Performance_Monitor.recordMetric('lazy_images_initialized', this.images.length);
      }
    }

    createObserver() {
      this.observer = new IntersectionObserver(
        (entries) => {
          entries.forEach((entry) => {
            if (entry.isIntersecting) {
              this.loadImage(entry.target);
            }
          });
        },
        {
          rootMargin: this.options.rootMargin,
          threshold: this.options.threshold,
        }
      );
    }

    observeImages() {
      const images = document.querySelectorAll(this.options.selector);

      images.forEach((img) => {
        if (!this.images.includes(img)) {
          this.images.push(img);
          this.observer.observe(img);

          // Add loading placeholder
          this.addPlaceholder(img);
        }
      });
    }

    addPlaceholder(img) {
      // Skip if already has a src
      if (img.src && !img.src.includes('data:image')) {
        return;
      }

      // Add loading class
      img.classList.add(this.options.loadingClass);

      // Add low-quality placeholder if available
      const placeholder = img.getAttribute('data-placeholder');
      if (placeholder) {
        img.src = placeholder;
      } else {
        // Create a base64 placeholder
        const width = img.getAttribute('width') || 1;
        const height = img.getAttribute('height') || 1;
        const aspectRatio = height / width;

        // Transparent 1x1 pixel with aspect ratio
        img.src = `data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 ${width} ${height}'%3E%3C/svg%3E`;
      }
    }

    loadImage(img) {
      // Stop observing this image
      this.observer.unobserve(img);

      const src = img.getAttribute(this.options.srcAttribute);
      const srcset = img.getAttribute(this.options.srcsetAttribute);
      const sizes = img.getAttribute(this.options.sizesAttribute);
      const backgroundImage = img.getAttribute(this.options.backgroundAttribute);

      if (!src && !srcset && !backgroundImage) {
        return;
      }

      // Track loading time
      const startTime = performance.now();

      if (backgroundImage) {
        this.loadBackgroundImage(img, backgroundImage, startTime);
      } else {
        this.loadRegularImage(img, src, srcset, sizes, startTime);
      }
    }

    loadRegularImage(img, src, srcset, sizes, startTime) {
      const tempImg = new Image();

      tempImg.onload = () => {
        if (src) img.src = src;
        if (srcset) img.srcset = srcset;
        if (sizes) img.sizes = sizes;

        this.onImageLoaded(img, startTime);
      };

      tempImg.onerror = () => {
        this.onImageError(img, src || srcset);
      };

      // Set sources to trigger loading
      if (srcset) {
        tempImg.srcset = srcset;
        if (sizes) tempImg.sizes = sizes;
      } else if (src) {
        tempImg.src = src;
      }
    }

    loadBackgroundImage(element, url, startTime) {
      const tempImg = new Image();

      tempImg.onload = () => {
        element.style.backgroundImage = `url(${url})`;
        this.onImageLoaded(element, startTime);
      };

      tempImg.onerror = () => {
        this.onImageError(element, url);
      };

      tempImg.src = url;
    }

    onImageLoaded(img, startTime) {
      const loadTime = performance.now() - startTime;

      // Remove loading class and add loaded class
      img.classList.remove(this.options.loadingClass);
      img.classList.add(this.options.loadedClass);

      // Clean up attributes
      img.removeAttribute(this.options.srcAttribute);
      img.removeAttribute(this.options.srcsetAttribute);
      img.removeAttribute(this.options.sizesAttribute);
      img.removeAttribute(this.options.backgroundAttribute);
      img.removeAttribute('data-placeholder');

      // Clear retry count
      this.retryMap.delete(img);

      // Trigger custom event
      img.dispatchEvent(
        new CustomEvent('mld-lazy-loaded', {
          detail: { loadTime },
        })
      );

      // Log performance
      if (window.MLD_Performance_Monitor && loadTime > 1000) {
        window.MLD_Performance_Monitor.recordMetric('slow_image_load', {
          src: img.src || img.style.backgroundImage,
          loadTime,
        });
      }
    }

    onImageError(img, src) {
      const retryCount = this.retryMap.get(img) || 0;

      if (retryCount < this.options.retryAttempts) {
        // Retry loading
        this.retryMap.set(img, retryCount + 1);

        setTimeout(
          () => {
            this.loadImage(img);
          },
          this.options.retryDelay * (retryCount + 1)
        );

        MLDLogger.warning(`Retrying image load (${retryCount + 1}/${this.options.retryAttempts}):`, src);
      } else {
        // Max retries reached
        img.classList.remove(this.options.loadingClass);
        img.classList.add(this.options.errorClass);

        // Set fallback image if available
        const fallback = img.getAttribute('data-fallback');
        if (fallback) {
          img.src = fallback;
        }

        // Trigger error event
        img.dispatchEvent(
          new CustomEvent('mld-lazy-error', {
            detail: { src },
          })
        );

        MLDLogger.error('Failed to load image after retries:', src);
      }
    }

    setupMutationObserver() {
      const mutationObserver = new MutationObserver(() => {
        this.observeImages();
      });

      mutationObserver.observe(document.body, {
        childList: true,
        subtree: true,
      });
    }

    loadAllImages() {
      // Fallback for browsers without Intersection Observer
      const images = document.querySelectorAll(this.options.selector);

      images.forEach((img) => {
        const src = img.getAttribute(this.options.srcAttribute);
        const srcset = img.getAttribute(this.options.srcsetAttribute);
        const sizes = img.getAttribute(this.options.sizesAttribute);
        const backgroundImage = img.getAttribute(this.options.backgroundAttribute);

        if (backgroundImage) {
          img.style.backgroundImage = `url(${backgroundImage})`;
        } else {
          if (src) img.src = src;
          if (srcset) img.srcset = srcset;
          if (sizes) img.sizes = sizes;
        }

        img.classList.add(this.options.loadedClass);
      });
    }

    // Public method to manually load specific images
    loadImages(images) {
      images.forEach((img) => {
        if (this.observer) {
          this.observer.unobserve(img);
        }
        this.loadImage(img);
      });
    }

    // Public method to refresh observations
    refresh() {
      this.observeImages();
    }

    // Cleanup method
    destroy() {
      if (this.observer) {
        this.observer.disconnect();
      }
      this.images = [];
      this.retryMap.clear();
    }
  }

  // Initialize on DOM ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initLazyLoading);
  } else {
    initLazyLoading();
  }

  function initLazyLoading() {
    // Make it globally available
    window.MLDLazyLoader = new LazyImageLoader({
      rootMargin: '100px 0px', // Start loading 100px before viewport
      selector: '[data-lazy-src], [data-lazy-bg]',
      retryAttempts: 2,
    });

    // Special handling for property galleries
    if (document.querySelector('.property-gallery')) {
      window.MLDGalleryLazyLoader = new LazyImageLoader({
        rootMargin: '200px 0px', // Load gallery images earlier
        selector: '.property-gallery [data-lazy-src]',
        retryAttempts: 3,
      });
    }
  }

  // Export for use in other modules
  if (typeof module !== 'undefined' && module.exports) {
    module.exports = LazyImageLoader;
  }
})();
