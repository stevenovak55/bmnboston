/**
 * MLS Listings Display - Common JavaScript Utilities
 *
 * Shared utilities used across multiple files to avoid code duplication
 *
 * @param window
 * @param document
 * @param $
 * @package
 * @since 3.3.0
 */

(function (window, document, $) {
  'use strict';

  // Create namespace
  window.MLD_Utils = window.MLD_Utils || {};

  /**
   * Escape HTML entities to prevent XSS
   *
   * @param {string} str String to escape
   * @return {string} Escaped string
   */
  MLD_Utils.escapeHtml = function (str) {
    if (!str) return '';

    const map = {
      '&': '&amp;',
      '<': '&lt;',
      '>': '&gt;',
      '"': '&quot;',
      "'": '&#39;',
      '/': '&#x2F;',
    };

    return String(str).replace(/[&<>"'\/]/g, function (s) {
      return map[s];
    });
  };

  /**
   * Format number with commas for thousands
   *
   * @param {number|string} num Number to format
   * @return {string} Formatted number
   */
  MLD_Utils.numberWithCommas = function (num) {
    if (!num) return '0';
    return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  };

  /**
   * Format date to readable string
   *
   * @param {string|Date} date   Date to format
   * @param {string}      format Format type ('short', 'long', 'relative')
   * @return {string} Formatted date
   */
  MLD_Utils.formatDate = function (date, format = 'short') {
    if (!date) return '';

    const d = date instanceof Date ? date : new Date(date);
    if (isNaN(d.getTime())) return '';

    switch (format) {
      case 'long':
        return d.toLocaleDateString('en-US', {
          year: 'numeric',
          month: 'long',
          day: 'numeric',
        });

      case 'relative':
        const now = new Date();
        const diffMs = now - d;
        const diffDays = Math.floor(diffMs / (1000 * 60 * 60 * 24));

        if (diffDays === 0) return 'Today';
        if (diffDays === 1) return 'Yesterday';
        if (diffDays < 7) return diffDays + ' days ago';
        if (diffDays < 30) return Math.floor(diffDays / 7) + ' weeks ago';
        if (diffDays < 365) return Math.floor(diffDays / 30) + ' months ago';
        return Math.floor(diffDays / 365) + ' years ago';

      case 'short':
      default:
        return d.toLocaleDateString('en-US', {
          month: 'short',
          day: 'numeric',
          year: 'numeric',
        });
    }
  };

  /**
   * Unified notification system
   */
  MLD_Utils.notification = {
    container: null,

    /**
     * Initialize notification container
     */
    init() {
      if (!this.container) {
        this.container = $('<div class="bme-notification-container"></div>').appendTo('body');
      }
    },

    /**
     * Show notification
     *
     * @param {string} message  Message to display
     * @param {string} type     Type of notification ('success', 'error', 'warning', 'info')
     * @param {number} duration Duration in milliseconds (0 = permanent)
     */
    show(message, type = 'info', duration = 5000) {
      this.init();

      const notification = $(
        '<div class="bme-notification bme-notification-' +
          type +
          '">' +
          '<div class="bme-notification-content">' +
          '<span class="bme-notification-message">' +
          MLD_Utils.escapeHtml(message) +
          '</span>' +
          '<button class="bme-notification-close">&times;</button>' +
          '</div>' +
          '</div>'
      );

      notification.find('.bme-notification-close').on('click', function () {
        notification.fadeOut(300, function () {
          $(this).remove();
        });
      });

      notification.hide().appendTo(this.container).fadeIn(300);

      if (duration > 0) {
        setTimeout(function () {
          notification.fadeOut(300, function () {
            $(this).remove();
          });
        }, duration);
      }

      return notification;
    },

    /**
     * Show success notification
     * @param message
     * @param duration
     */
    success(message, duration) {
      return this.show(message, 'success', duration);
    },

    /**
     * Show error notification
     * @param message
     * @param duration
     */
    error(message, duration) {
      return this.show(message, 'error', duration);
    },

    /**
     * Show warning notification
     * @param message
     * @param duration
     */
    warning(message, duration) {
      return this.show(message, 'warning', duration);
    },

    /**
     * Show info notification
     * @param message
     * @param duration
     */
    info(message, duration) {
      return this.show(message, 'info', duration);
    },
  };

  /**
   * AJAX utilities
   */
  MLD_Utils.ajax = {
    /**
     * Make AJAX POST request with standard error handling
     *
     * @param {string} action  WordPress AJAX action
     * @param {Object} data    Data to send
     * @param {Object} options Additional options
     * @return {Promise} jQuery promise
     */
    post(action, data = {}, options = {}) {
      const defaults = {
        url: mldAjax.ajaxUrl || '/wp-admin/admin-ajax.php',
        retries: 3,
        retryDelay: 1000,
        showError: true,
        showSuccess: false,
        loadingMessage: 'Processing...',
        successMessage: 'Success!',
        errorMessage: 'An error occurred. Please try again.',
      };

      const settings = $.extend({}, defaults, options);
      let retryCount = 0;

      // Add action and nonce to data
      data.action = action;
      if (mldAjax && mldAjax.nonce) {
        data.nonce = mldAjax.nonce;
      }

      // Show loading notification if specified
      let loadingNotification = null;
      if (settings.loadingMessage) {
        loadingNotification = MLD_Utils.notification.info(settings.loadingMessage, 0);
      }

      const makeRequest = function () {
        return $.post(settings.url, data)
          .done(function (response) {
            if (loadingNotification) {
              loadingNotification.remove();
            }

            if (response.success) {
              if (settings.showSuccess && response.data && response.data.message) {
                MLD_Utils.notification.success(response.data.message);
              } else if (settings.showSuccess && settings.successMessage) {
                MLD_Utils.notification.success(settings.successMessage);
              }
            } else if (settings.showError) {
              const errorMsg = response.data || settings.errorMessage;
              MLD_Utils.notification.error(errorMsg);
            }
          })
          .fail(function (xhr, status, error) {
            retryCount++;

            if (retryCount < settings.retries) {
              // Retry after delay
              setTimeout(makeRequest, settings.retryDelay * retryCount);
            } else {
              if (loadingNotification) {
                loadingNotification.remove();
              }

              if (settings.showError) {
                let errorMsg = settings.errorMessage;

                if (xhr.status === 403) {
                  errorMsg = 'Security check failed. Please refresh the page and try again.';
                } else if (xhr.status === 404) {
                  errorMsg = 'The requested action was not found.';
                } else if (xhr.status === 500) {
                  errorMsg = 'Server error. Please try again later.';
                } else if (status === 'timeout') {
                  errorMsg = 'Request timed out. Please try again.';
                }

                MLD_Utils.notification.error(errorMsg);
              }
            }
          });
      };

      return makeRequest();
    },
  };

  /**
   * Modal dialog utilities
   */
  MLD_Utils.modal = {
    /**
     * Show modal dialog
     *
     * @param {Object} options Modal options
     * @return {jQuery} Modal element
     */
    show(options) {
      const defaults = {
        title: '',
        content: '',
        size: 'medium', // small, medium, large
        buttons: [],
        closeButton: true,
        overlay: true,
        onClose: null,
      };

      const settings = $.extend({}, defaults, options);

      // Remove any existing modal
      $('.bme-modal-overlay').remove();

      // Create modal HTML
      let modalHtml = '<div class="bme-modal-overlay">';
      modalHtml += '<div class="bme-modal bme-modal-' + settings.size + '">';

      if (settings.title || settings.closeButton) {
        modalHtml += '<div class="bme-modal-header">';
        if (settings.title) {
          modalHtml +=
            '<h3 class="bme-modal-title">' + MLD_Utils.escapeHtml(settings.title) + '</h3>';
        }
        if (settings.closeButton) {
          modalHtml += '<button class="bme-modal-close">&times;</button>';
        }
        modalHtml += '</div>';
      }

      modalHtml += '<div class="bme-modal-content">' + settings.content + '</div>';

      if (settings.buttons.length > 0) {
        modalHtml += '<div class="bme-modal-footer">';
        settings.buttons.forEach(function (button) {
          const btnClass = button.class || 'bme-btn-secondary';
          modalHtml +=
            '<button class="bme-btn ' +
            btnClass +
            '" data-action="' +
            (button.action || '') +
            '">' +
            MLD_Utils.escapeHtml(button.text) +
            '</button>';
        });
        modalHtml += '</div>';
      }

      modalHtml += '</div></div>';

      const $modal = $(modalHtml).appendTo('body');

      // Bind events
      $modal.find('.bme-modal-close, .bme-modal-overlay').on('click', function (e) {
        if (e.target === this) {
          MLD_Utils.modal.close($modal, settings.onClose);
        }
      });

      // Bind button events
      settings.buttons.forEach(function (button) {
        if (button.action && button.onClick) {
          $modal.find('[data-action="' + button.action + '"]').on('click', function () {
            button.onClick($modal);
          });
        }
      });

      // Show modal with animation
      $modal.hide().fadeIn(200);

      return $modal;
    },

    /**
     * Close modal
     *
     * @param {jQuery}   $modal  Modal element
     * @param {Function} onClose Callback function
     */
    close($modal, onClose) {
      $modal.fadeOut(200, function () {
        $(this).remove();
        if (typeof onClose === 'function') {
          onClose();
        }
      });
    },
  };

  /**
   * Debounce function execution
   *
   * @param {Function} func      Function to debounce
   * @param {number}   wait      Wait time in milliseconds
   * @param {boolean}  immediate Execute immediately
   * @return {Function} Debounced function
   */
  MLD_Utils.debounce = function (func, wait, immediate) {
    let timeout;
    return function () {
      const context = this;
      const args = arguments;
      const later = function () {
        timeout = null;
        if (!immediate) func.apply(context, args);
      };
      const callNow = immediate && !timeout;
      clearTimeout(timeout);
      timeout = setTimeout(later, wait);
      if (callNow) func.apply(context, args);
    };
  };

  /**
   * Format price with currency symbol
   *
   * @param {number} price    Price value
   * @param {string} currency Currency symbol (default: '$')
   * @return {string} Formatted price
   */
  MLD_Utils.formatPrice = function (price, currency = '$') {
    if (!price || isNaN(price)) return currency + '0';
    return currency + MLD_Utils.numberWithCommas(Math.round(price));
  };

  /**
   * Get URL parameter value
   *
   * @param {string} name Parameter name
   * @param {string} url  URL to parse (default: current URL)
   * @return {string|null} Parameter value
   */
  MLD_Utils.getUrlParam = function (name, url = window.location.href) {
    const regex = new RegExp('[?&]' + name + '(=([^&#]*)|&|#|$)');
    const results = regex.exec(url);
    if (!results) return null;
    if (!results[2]) return '';
    return decodeURIComponent(results[2].replace(/\+/g, ' '));
  };

  /**
   * Update URL parameter
   *
   * @param {string} key   Parameter key
   * @param {string} value Parameter value
   * @param {string} url   URL to update (default: current URL)
   * @return {string} Updated URL
   */
  MLD_Utils.updateUrlParam = function (key, value, url = window.location.href) {
    const regex = new RegExp('([?&])' + key + '=.*?(&|$)', 'i');
    const separator = url.indexOf('?') !== -1 ? '&' : '?';

    if (url.match(regex)) {
      return url.replace(regex, '$1' + key + '=' + value + '$2');
    }
    return url + separator + key + '=' + value;
  };
})(window, document, jQuery);
