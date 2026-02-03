/**
 * BME Mobile UI Enhancements
 * Version: 1.0.0
 * @param $
 */

(function ($) {
  'use strict';

  const BMEMobileEnhancements = {
    init() {
      this.detectMobile();
      this.initTouchHandlers();
      this.initSwipeGestures();
      this.initMobileNavigation();
      this.initResponsiveImages();
      this.initMobileOptimizations();
      this.initAccessibilityFeatures();
    },

    detectMobile() {
      const isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(
        navigator.userAgent
      );
      const isTablet = /iPad|Android(?=.*Tablet)|(?=.*Android)(?=.*Mobile)/i.test(
        navigator.userAgent
      );
      const hasTouch = 'ontouchstart' in window || navigator.maxTouchPoints > 0;

      $('body')
        .toggleClass('bme-mobile', isMobile)
        .toggleClass('bme-tablet', isTablet)
        .toggleClass('bme-touch', hasTouch);

      // Store device info for other scripts
      window.BMEDevice = {
        isMobile,
        isTablet,
        hasTouch,
        screenWidth: window.innerWidth,
        screenHeight: window.innerHeight,
      };
    },

    initTouchHandlers() {
      // Improve touch responsiveness
      let touchStartTime = 0;

      $(document).on('touchstart', '.bme-property-card, .bme-favorite-item', function (e) {
        touchStartTime = Date.now();
        $(this).addClass('bme-touch-active');
      });

      $(document).on(
        'touchend touchcancel',
        '.bme-property-card, .bme-favorite-item',
        function (e) {
          const $this = $(this);
          const touchDuration = Date.now() - touchStartTime;

          setTimeout(() => {
            $this.removeClass('bme-touch-active');
          }, 150);

          // Handle quick taps differently from long presses
          if (touchDuration < 200) {
            $this.addClass('bme-quick-tap');
            setTimeout(() => {
              $this.removeClass('bme-quick-tap');
            }, 300);
          }
        }
      );

      // Prevent 300ms delay on buttons
      $(document).on('touchstart', '.bme-btn, button', function (e) {
        $(this).addClass('bme-btn-active');
      });

      $(document).on('touchend touchcancel', '.bme-btn, button', function (e) {
        const $this = $(this);
        setTimeout(() => {
          $this.removeClass('bme-btn-active');
        }, 150);
      });
    },

    initSwipeGestures() {
      let startX, startY, startTime;

      $(document).on('touchstart', '.bme-property-card', function (e) {
        const touch = e.originalEvent.touches[0];
        startX = touch.clientX;
        startY = touch.clientY;
        startTime = Date.now();
      });

      $(document).on('touchmove', '.bme-property-card', function (e) {
        if (!startX || !startY) return;

        const touch = e.originalEvent.touches[0];
        const diffX = startX - touch.clientX;
        const diffY = startY - touch.clientY;

        // Prevent vertical scrolling during horizontal swipe
        if (Math.abs(diffX) > Math.abs(diffY) && Math.abs(diffX) > 20) {
          e.preventDefault();
        }
      });

      $(document).on('touchend', '.bme-property-card', function (e) {
        if (!startX || !startY) return;

        const touch = e.originalEvent.changedTouches[0];
        const diffX = startX - touch.clientX;
        const diffY = startY - touch.clientY;
        const diffTime = Date.now() - startTime;

        // Detect swipe gestures
        if (Math.abs(diffX) > Math.abs(diffY) && Math.abs(diffX) > 50 && diffTime < 300) {
          const $card = $(this);

          if (diffX > 0) {
            // Swipe left - add to favorites
            BMEMobileEnhancements.handleSwipeLeft($card);
          } else {
            // Swipe right - add to comparison
            BMEMobileEnhancements.handleSwipeRight($card);
          }
        }

        // Reset
        startX = startY = null;
      });
    },

    handleSwipeLeft($card) {
      const propertyId = $card.data('property-id');
      if (!propertyId) return;

      $card.addClass('bme-swiped-left');

      // Trigger favorite action
      const $favoriteBtn = $card.find('.bme-favorite-btn');
      if ($favoriteBtn.length && !$favoriteBtn.hasClass('active')) {
        $favoriteBtn.trigger('click');
      }

      this.showSwipeFeedback($card, 'Added to favorites', 'favorite');
    },

    handleSwipeRight($card) {
      const propertyId = $card.data('property-id');
      if (!propertyId) return;

      $card.addClass('bme-swiped-right');

      // Trigger comparison action
      const $compareBtn = $card.find('.bme-compare-btn');
      if ($compareBtn.length && !$compareBtn.hasClass('active')) {
        $compareBtn.trigger('click');
      }

      this.showSwipeFeedback($card, 'Added to comparison', 'compare');
    },

    showSwipeFeedback($card, message, type) {
      const $feedback = $(`
                <div class="bme-swipe-feedback bme-swipe-${type}">
                    <div class="bme-swipe-icon"></div>
                    <div class="bme-swipe-message">${message}</div>
                </div>
            `);

      $card.append($feedback);

      setTimeout(() => {
        $feedback.addClass('show');
      }, 50);

      setTimeout(() => {
        $feedback.removeClass('show');
        setTimeout(() => {
          $feedback.remove();
          $card.removeClass('bme-swiped-left bme-swiped-right');
        }, 300);
      }, 2000);
    },

    initMobileNavigation() {
      // Mobile-friendly dropdown menus
      $(document).on('click', '.bme-mobile-menu-toggle', function (e) {
        e.preventDefault();
        const $menu = $(this).next('.bme-menu');
        $menu.toggleClass('show');

        // Close other menus
        $('.bme-menu.show').not($menu).removeClass('show');
      });

      // Close menus when clicking outside
      $(document).on('click', function (e) {
        if (!$(e.target).closest('.bme-menu, .bme-mobile-menu-toggle').length) {
          $('.bme-menu.show').removeClass('show');
        }
      });

      // Handle filter toggles on mobile
      $(document).on('click', '.bme-filter-header', function (e) {
        if (window.innerWidth <= 768) {
          e.preventDefault();
          const $content = $(this).next('.bme-filter-content');
          const $toggle = $(this).find('.bme-filter-toggle');

          $toggle.prop('checked', !$toggle.prop('checked'));
          $content.slideToggle(300);
        }
      });
    },

    initResponsiveImages() {
      // Lazy load images with intersection observer
      if ('IntersectionObserver' in window) {
        const imageObserver = new IntersectionObserver(
          (entries) => {
            entries.forEach((entry) => {
              if (entry.isIntersecting) {
                const img = entry.target;
                this.loadMobileOptimizedImage(img);
                imageObserver.unobserve(img);
              }
            });
          },
          {
            rootMargin: '50px 0px',
          }
        );

        $('.bme-property-image img, .bme-favorite-image img').each(function () {
          if (this.dataset.src) {
            imageObserver.observe(this);
          }
        });
      }

      // Handle image load errors
      $(document).on('error', '.bme-property-image img, .bme-favorite-image img', function () {
        const $img = $(this);
        const $container = $img.parent();

        $img.hide();
        $container
          .addClass('bme-image-error')
          .append('<div class="bme-image-placeholder">No Image Available</div>');
      });
    },

    loadMobileOptimizedImage(img) {
      const $img = $(img);
      const originalSrc = $img.data('src') || $img.attr('src');

      if (!originalSrc) return;

      // Choose appropriate image size based on device
      let optimizedSrc = originalSrc;

      if (window.innerWidth <= 480) {
        // Small mobile - use small image
        optimizedSrc = this.getOptimizedImageUrl(originalSrc, 300, 200);
      } else if (window.innerWidth <= 768) {
        // Mobile/tablet - use medium image
        optimizedSrc = this.getOptimizedImageUrl(originalSrc, 400, 300);
      }

      $img.attr('src', optimizedSrc);
    },

    getOptimizedImageUrl(originalUrl, width, height) {
      // If using WordPress image sizes, modify URL
      if (originalUrl.includes('/wp-content/uploads/')) {
        const extension = originalUrl.split('.').pop();
        const baseUrl = originalUrl.replace(`.${extension}`, '');
        return `${baseUrl}-${width}x${height}.${extension}`;
      }

      // For external images or CDN, add query parameters
      const separator = originalUrl.includes('?') ? '&' : '?';
      return `${originalUrl}${separator}w=${width}&h=${height}&fit=crop&auto=format,compress`;
    },

    initMobileOptimizations() {
      // Prevent zoom on input focus for iOS
      if (/iPad|iPhone|iPod/.test(navigator.userAgent)) {
        $('input[type="text"], input[type="email"], input[type="number"], select, textarea')
          .attr('data-original-size', function () {
            return $(this).css('font-size');
          })
          .css('font-size', '16px');
      }

      // Optimize scrolling performance
      $('.bme-results-grid, .bme-favorites-grid').css({
        '-webkit-overflow-scrolling': 'touch',
        transform: 'translateZ(0)', // Force hardware acceleration
      });

      // Handle orientation changes
      $(window).on('orientationchange', function () {
        setTimeout(() => {
          BMEMobileEnhancements.handleOrientationChange();
        }, 100);
      });

      // Handle viewport changes
      let resizeTimer;
      $(window).on('resize', function () {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(() => {
          BMEMobileEnhancements.handleViewportChange();
        }, 250);
      });

      // Optimize comparison widget for mobile
      this.optimizeMobileWidget();
    },

    handleOrientationChange() {
      // Refresh layouts after orientation change
      $('.bme-results-grid, .bme-favorites-grid').each(function () {
        const $grid = $(this);
        $grid.hide().show(0); // Force reflow
      });

      // Adjust comparison table
      $('.bme-comparison-table-wrapper').scrollLeft(0);

      // Update device info
      window.BMEDevice.screenWidth = window.innerWidth;
      window.BMEDevice.screenHeight = window.innerHeight;
    },

    handleViewportChange() {
      // Update mobile detection
      const wasMobile = $('body').hasClass('bme-mobile');
      const isMobileNow = window.innerWidth <= 768;

      if (wasMobile !== isMobileNow) {
        $('body').toggleClass('bme-mobile', isMobileNow);

        // Trigger layout updates
        $(window).trigger('bme:viewport-change', {
          isMobile: isMobileNow,
          width: window.innerWidth,
        });
      }
    },

    optimizeMobileWidget() {
      // Make comparison widget more mobile-friendly
      $(document).on('touchstart', '#bme-widget-toggle', function (e) {
        e.stopPropagation();
      });

      // Swipe to dismiss widget
      let widgetStartY;

      $(document).on('touchstart', '.bme-comparison-widget', function (e) {
        widgetStartY = e.originalEvent.touches[0].clientY;
      });

      $(document).on('touchmove', '.bme-comparison-widget', function (e) {
        if (!widgetStartY) return;

        const currentY = e.originalEvent.touches[0].clientY;
        const diffY = widgetStartY - currentY;

        if (diffY < -50) {
          // Swipe down
          $(this).addClass('bme-widget-dismissing');
        }
      });

      $(document).on('touchend', '.bme-comparison-widget', function (e) {
        const $widget = $(this);
        if ($widget.hasClass('bme-widget-dismissing')) {
          $widget.slideUp(300);
        }
        widgetStartY = null;
      });
    },

    initAccessibilityFeatures() {
      // Add ARIA labels for mobile screen readers
      $('.bme-favorite-btn').attr('aria-label', 'Add to favorites');
      $('.bme-compare-btn').attr('aria-label', 'Add to comparison');
      $('.bme-remove-favorite').attr('aria-label', 'Remove from favorites');

      // High contrast mode support
      if (window.matchMedia && window.matchMedia('(prefers-contrast: high)').matches) {
        $('body').addClass('bme-high-contrast');
      }

      // Reduced motion support
      if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        $('body').addClass('bme-reduced-motion');
      }

      // Focus management for mobile
      $(document).on('focusin', 'input, select, textarea, button', function () {
        if (window.innerWidth <= 768) {
          const $element = $(this);
          setTimeout(() => {
            if ($element.is(':focus')) {
              $element[0].scrollIntoView({
                behavior: 'smooth',
                block: 'center',
              });
            }
          }, 300); // Wait for keyboard to appear
        }
      });
    },

    // Utility methods
    isInViewport($element) {
      const elementTop = $element.offset().top;
      const elementBottom = elementTop + $element.outerHeight();
      const viewportTop = $(window).scrollTop();
      const viewportBottom = viewportTop + $(window).height();

      return elementBottom > viewportTop && elementTop < viewportBottom;
    },

    smoothScrollTo($element, offset = 0) {
      $('html, body').animate(
        {
          scrollTop: $element.offset().top - offset,
        },
        300
      );
    },
  };

  // Initialize on DOM ready
  $(document).ready(function () {
    BMEMobileEnhancements.init();
  });

  // Make available globally
  window.BMEMobileEnhancements = BMEMobileEnhancements;
})(jQuery);
