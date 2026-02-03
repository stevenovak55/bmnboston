/**
 * BME Property Comparison - Frontend JavaScript
 * Version: 1.0.0
 * @param $
 */

(function ($) {
  'use strict';

  const BMEPropertyComparison = {
    init() {
      this.bindEvents();
      this.updateComparisonCount();
      this.loadComparisonWidget();
    },

    bindEvents() {
      // Add to comparison buttons
      $(document).on('click', '.bme-compare-btn:not(.active)', this.addToComparison);

      // Remove from comparison buttons
      $(document).on(
        'click',
        '.bme-compare-btn.active, .bme-widget-remove, .bme-remove-property',
        this.removeFromComparison
      );

      // Widget controls
      $(document).on('click', '#bme-widget-toggle', this.toggleWidget);
      $(document).on('click', '#bme-view-comparison', this.viewComparison);
      $(document).on('click', '#bme-clear-comparison', this.clearComparison);
      $(document).on('click', '#bme-print-comparison', this.printComparison);

      // Update comparison states on page load
      this.updateComparisonStates();
    },

    addToComparison(e) {
      e.preventDefault();
      e.stopPropagation();

      const $btn = $(this);
      const propertyId = $btn.data('property-id');

      $btn.prop('disabled', true);

      $.ajax({
        url: bme_comparison_ajax.ajax_url,
        type: 'POST',
        dataType: 'json',
        data: {
          action: 'bme_add_to_comparison',
          nonce: bme_comparison_ajax.nonce,
          property_id: propertyId,
        },
        success(response) {
          if (response.success) {
            $btn
              .addClass('active')
              .attr('title', 'Remove from comparison')
              .find('.dashicons')
              .removeClass('dashicons-controls-repeat')
              .addClass('dashicons-yes');

            BMEPropertyComparison.updateComparisonCount(response.data.count);
            BMEPropertyComparison.loadComparisonWidget();

            BMEPropertyComparison.showNotification(response.data.message, 'success');
          } else {
            BMEPropertyComparison.showNotification(response.data, 'error');
          }
        },
        error() {
          BMEPropertyComparison.showNotification('Failed to add property to comparison.', 'error');
        },
        complete() {
          $btn.prop('disabled', false);
        },
      });
    },

    removeFromComparison(e) {
      e.preventDefault();
      e.stopPropagation();

      const $btn = $(this);
      const propertyId = $btn.data('property-id');

      $btn.prop('disabled', true);

      $.ajax({
        url: bme_comparison_ajax.ajax_url,
        type: 'POST',
        dataType: 'json',
        data: {
          action: 'bme_remove_from_comparison',
          nonce: bme_comparison_ajax.nonce,
          property_id: propertyId,
        },
        success(response) {
          if (response.success) {
            // Update comparison button state
            $('.bme-compare-btn[data-property-id="' + propertyId + '"]')
              .removeClass('active')
              .attr('title', 'Add to comparison')
              .find('.dashicons')
              .removeClass('dashicons-yes')
              .addClass('dashicons-controls-repeat');

            // Remove from widget
            $('.bme-widget-property[data-property-id="' + propertyId + '"]').fadeOut(
              300,
              function () {
                $(this).remove();
              }
            );

            // Remove from comparison table
            const $propertyColumn = $('.bme-comparison-property').filter(function () {
              return (
                $(this).find('.bme-remove-property[data-property-id="' + propertyId + '"]').length >
                0
              );
            });

            if ($propertyColumn.length) {
              const columnIndex = $propertyColumn.index() + 1; // +1 because of feature column
              $(
                '.bme-comparison-table th:nth-child(' +
                  columnIndex +
                  '), .bme-comparison-table td:nth-child(' +
                  columnIndex +
                  ')'
              ).fadeOut(300, function () {
                $(this).remove();
              });
            }

            BMEPropertyComparison.updateComparisonCount(response.data.count);
            BMEPropertyComparison.showNotification(response.data.message, 'success');

            // Refresh page if no properties left in comparison view
            if (response.data.count === 0 && window.location.href.indexOf('comparison') !== -1) {
              setTimeout(() => {
                window.location.reload();
              }, 1000);
            }
          } else {
            BMEPropertyComparison.showNotification(response.data, 'error');
          }
        },
        error() {
          BMEPropertyComparison.showNotification(
            'Failed to remove property from comparison.',
            'error'
          );
        },
        complete() {
          $btn.prop('disabled', false);
        },
      });
    },

    clearComparison() {
      if (!confirm(bme_comparison_ajax.messages.clear_confirmation)) {
        return;
      }

      $.ajax({
        url: bme_comparison_ajax.ajax_url,
        type: 'POST',
        dataType: 'json',
        data: {
          action: 'bme_clear_comparison',
          nonce: bme_comparison_ajax.nonce,
        },
        success(response) {
          if (response.success) {
            // Reset all comparison buttons
            $('.bme-compare-btn.active')
              .removeClass('active')
              .attr('title', 'Add to comparison')
              .find('.dashicons')
              .removeClass('dashicons-yes')
              .addClass('dashicons-controls-repeat');

            BMEPropertyComparison.updateComparisonCount(0);
            BMEPropertyComparison.loadComparisonWidget();
            BMEPropertyComparison.showNotification('Comparison cleared.', 'success');

            // Refresh comparison page if we're on it
            if (window.location.href.indexOf('comparison') !== -1) {
              setTimeout(() => {
                window.location.reload();
              }, 1000);
            }
          } else {
            BMEPropertyComparison.showNotification('Failed to clear comparison.', 'error');
          }
        },
        error() {
          BMEPropertyComparison.showNotification('Failed to clear comparison.', 'error');
        },
      });
    },

    toggleWidget() {
      const $widget = $('#bme-comparison-widget');
      const $content = $('#bme-widget-content');
      const $toggle = $('#bme-widget-toggle');

      if ($content.is(':visible')) {
        $content.slideUp(300);
        $toggle
          .find('.dashicons')
          .removeClass('dashicons-arrow-up-alt2')
          .addClass('dashicons-arrow-down-alt2');
        $widget.addClass('collapsed');
      } else {
        $content.slideDown(300);
        $toggle
          .find('.dashicons')
          .removeClass('dashicons-arrow-down-alt2')
          .addClass('dashicons-arrow-up-alt2');
        $widget.removeClass('collapsed');
      }
    },

    viewComparison() {
      // Navigate to comparison page or open modal
      const comparisonUrl = window.location.origin + '/property-comparison/';
      window.open(comparisonUrl, '_blank');
    },

    printComparison() {
      const $table = $('.bme-comparison-table').clone();
      $table.find('.bme-remove-property').remove();

      const printWindow = window.open('', '_blank');
      printWindow.document.write(`
            <html>
                <head>
                    <title>Property Comparison</title>
                    <style>
                        body { font-family: Arial, sans-serif; margin: 20px; }
                        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
                        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                        th { background-color: #f5f5f5; font-weight: bold; }
                        .bme-property-image img { max-width: 100px; height: auto; }
                        .bme-property-price { font-weight: bold; color: #0073aa; }
                        .bme-feature-label { font-weight: bold; background-color: #f9f9f9; }
                        @media print {
                            body { margin: 0; }
                            table { font-size: 12px; }
                        }
                    </style>
                </head>
                <body>
                    <h1>Property Comparison</h1>
                    <p>Generated on ${new Date().toLocaleDateString()}</p>
                    ${$table[0].outerHTML}
                </body>
            </html>
        `);
      printWindow.document.close();
      printWindow.print();
    },

    loadComparisonWidget() {
      $.ajax({
        url: bme_comparison_ajax.ajax_url,
        type: 'POST',
        dataType: 'json',
        data: {
          action: 'bme_get_comparison_data',
          nonce: bme_comparison_ajax.nonce,
        },
        success(response) {
          if (response.success) {
            BMEPropertyComparison.updateWidgetContent(response.data.properties);
            BMEPropertyComparison.updateComparisonCount(response.data.count);
          }
        },
      });
    },

    updateWidgetContent(properties) {
      const $widget = $('#bme-widget-properties');
      const $actions = $('.bme-widget-actions');

      if (properties.length === 0) {
        $widget.html('<p class="bme-widget-empty">No properties selected for comparison.</p>');
        $actions.hide();
      } else {
        let html = '';
        properties.forEach(function (property) {
          const imageUrl = property.images && property.images.length > 0 ? property.images[0] : '';
          html += `
                    <div class="bme-widget-property" data-property-id="${property.id}">
                        <img src="${imageUrl}" alt="${property.address}" />
                        <div class="bme-widget-property-info">
                            <div class="bme-widget-price">$${BMEPropertyComparison.formatPrice(property.list_price)}</div>
                            <div class="bme-widget-address">${property.address}</div>
                        </div>
                        <button class="bme-widget-remove" data-property-id="${property.id}">
                            <span class="dashicons dashicons-no"></span>
                        </button>
                    </div>
                `;
        });
        $widget.html(html);
        $actions.show();
      }
    },

    updateComparisonCount(count) {
      if (typeof count === 'undefined') {
        // Fetch current count from server
        this.loadComparisonWidget();
        return;
      }

      $('#bme-comparison-count').text(count);

      // Show/hide widget based on count
      const $widget = $('#bme-comparison-widget');
      if (count > 0) {
        $widget.fadeIn(300);
      } else {
        $widget.fadeOut(300);
      }
    },

    updateComparisonStates() {
      // Update button states based on current comparison
      $.ajax({
        url: bme_comparison_ajax.ajax_url,
        type: 'POST',
        dataType: 'json',
        data: {
          action: 'bme_get_comparison_data',
          nonce: bme_comparison_ajax.nonce,
        },
        success(response) {
          if (response.success && response.data.properties) {
            response.data.properties.forEach(function (property) {
              $('.bme-compare-btn[data-property-id="' + property.id + '"]')
                .addClass('active')
                .attr('title', 'Remove from comparison')
                .find('.dashicons')
                .removeClass('dashicons-controls-repeat')
                .addClass('dashicons-yes');
            });
          }
        },
      });
    },

    showNotification(message, type) {
      // Create notification element if it doesn't exist
      if (!$('#bme-notification').length) {
        $('body').append('<div id="bme-notification" class="bme-notification"></div>');
      }

      const $notification = $('#bme-notification');
      $notification.removeClass('success error').addClass(type).text(message).fadeIn(300);

      // Auto hide after 3 seconds
      setTimeout(function () {
        $notification.fadeOut(300);
      }, 3000);
    },

    formatPrice(price) {
      return parseInt(price).toLocaleString();
    },
  };

  // Auto-initialize when DOM is ready
  $(document).ready(function () {
    BMEPropertyComparison.init();
  });
})(jQuery);
