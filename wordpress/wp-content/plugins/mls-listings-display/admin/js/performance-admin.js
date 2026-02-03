/**
 * Performance Administration JavaScript
 *
 * @package
 * @since 4.3.0
 */

jQuery(document).ready(function ($) {
  'use strict';

  /**
   * Database optimization
   */
  $('#mld-optimize-database').on('click', function () {
    const $button = $(this);
    const $results = $('#optimization-results');

    $button.prop('disabled', true).text('Optimizing...');
    $results.html('<div class="notice notice-info"><p>Running database optimization...</p></div>');

    $.ajax({
      url: mld_performance.ajax_url,
      type: 'POST',
      data: {
        action: 'mld_optimize_database',
        nonce: mld_performance.nonce,
      },
      success(response) {
        if (response.success) {
          let html = '<div class="notice notice-success"><p>Optimization complete!</p></div>';
          html += '<div class="optimization-details">';

          for (const table in response.data) {
            html += '<h4>' + table + '</h4><ul>';
            for (const index in response.data[table]) {
              const result = response.data[table][index];
              const statusClass =
                result.status === 'created'
                  ? 'success'
                  : result.status === 'error'
                    ? 'error'
                    : 'info';
              html += '<li class="' + statusClass + '">';
              html += index + ': ' + result.status;
              if (result.message) {
                html += ' - ' + result.message;
              }
              html += '</li>';
            }
            html += '</ul>';
          }
          html += '</div>';

          $results.html(html);
        } else {
          $results.html(
            '<div class="notice notice-error"><p>Optimization failed: ' +
              response.data +
              '</p></div>'
          );
        }
      },
      error() {
        $results.html(
          '<div class="notice notice-error"><p>An error occurred during optimization.</p></div>'
        );
      },
      complete() {
        $button.prop('disabled', false).text('Run Optimization');
      },
    });
  });

  /**
   * Table analysis
   */
  $('#analyze-table').on('click', function () {
    const $button = $(this);
    const $results = $('#table-analysis-results');
    const table = $('#table-selector').val();

    if (!table) {
      alert('Please select a table to analyze.');
      return;
    }

    $button.prop('disabled', true).text('Analyzing...');
    $results.html('<div class="notice notice-info"><p>Analyzing table...</p></div>');

    $.ajax({
      url: mld_performance.ajax_url,
      type: 'POST',
      data: {
        action: 'mld_analyze_table',
        table,
        nonce: mld_performance.nonce,
      },
      success(response) {
        if (response.success) {
          let html = '<div class="notice notice-success"><p>Analysis complete!</p></div>';
          html += '<div class="analysis-details">';

          if (response.data.size) {
            html += '<h4>Table Statistics</h4><ul>';
            html += '<li>Rows: ' + formatNumber(response.data.size.row_count) + '</li>';
            html += '<li>Data Size: ' + response.data.size.data_size_mb + ' MB</li>';
            html += '<li>Index Size: ' + response.data.size.index_size_mb + ' MB</li>';
            html += '<li>Total Size: ' + response.data.size.total_size_mb + ' MB</li>';
            html += '</ul>';
          }

          if (response.data.fragmentation !== undefined) {
            html += '<h4>Fragmentation</h4>';
            const fragClass = response.data.fragmentation > 10 ? 'warning' : 'success';
            html +=
              '<p class="' +
              fragClass +
              '">Fragmentation: ' +
              response.data.fragmentation +
              '%</p>';

            if (response.data.optimized) {
              html += '<p class="success">Table has been optimized!</p>';
            }
          }

          html += '</div>';
          $results.html(html);
        } else {
          $results.html(
            '<div class="notice notice-error"><p>Analysis failed: ' + response.data + '</p></div>'
          );
        }
      },
      error() {
        $results.html(
          '<div class="notice notice-error"><p>An error occurred during analysis.</p></div>'
        );
      },
      complete() {
        $button.prop('disabled', false).text('Analyze Table');
      },
    });
  });

  /**
   * Cache clearing
   */
  $('.clear-cache').on('click', function () {
    const $button = $(this);
    const cacheType = $button.data('cache');
    const $results = $('#cache-results');

    $button.prop('disabled', true).text('Clearing...');

    $.ajax({
      url: mld_performance.ajax_url,
      type: 'POST',
      data: {
        action: 'mld_clear_cache',
        cache_type: cacheType,
        nonce: mld_performance.nonce,
      },
      success(response) {
        if (response.success) {
          let message = 'Cache cleared successfully!';
          if (response.data.query_cache !== undefined) {
            message += ' Cleared ' + response.data.query_cache + ' query cache entries.';
          }
          if (response.data.transients !== undefined) {
            message += ' Cleared ' + response.data.transients + ' transient entries.';
          }
          if (response.data.all_caches !== undefined) {
            message += ' Cleared ' + response.data.all_caches + ' total cache entries.';
          }

          $results.html('<div class="notice notice-success"><p>' + message + '</p></div>');

          // Auto-hide success message after 5 seconds
          setTimeout(function () {
            $results.fadeOut();
          }, 5000);
        } else {
          $results.html(
            '<div class="notice notice-error"><p>Failed to clear cache: ' +
              response.data +
              '</p></div>'
          );
        }
      },
      error() {
        $results.html(
          '<div class="notice notice-error"><p>An error occurred while clearing cache.</p></div>'
        );
      },
      complete() {
        $button
          .prop('disabled', false)
          .text('Clear ' + cacheType.charAt(0).toUpperCase() + cacheType.slice(1) + ' Cache');
      },
    });
  });

  /**
   * Helper function to format numbers
   * @param num
   */
  function formatNumber(num) {
    return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  }

  /**
   * Auto-refresh metrics every 30 seconds if on the page
   */
  let metricsRefreshInterval;

  function startMetricsRefresh() {
    metricsRefreshInterval = setInterval(function () {
      // Only refresh if the page is visible
      if (!document.hidden) {
        updateMetrics();
      }
    }, 30000);
  }

  function updateMetrics() {
    $.ajax({
      url: mld_performance.ajax_url,
      type: 'POST',
      data: {
        action: 'mld_get_current_metrics',
        nonce: mld_performance.nonce,
      },
      success(response) {
        if (response.success) {
          // Update metric values on the page
          $('.mld-metrics .metric').each(function () {
            const label = $(this).find('.label').text();
            if (response.data[label]) {
              $(this).find('.value').text(response.data[label]);
            }
          });
        }
      },
    });
  }

  // Start auto-refresh
  startMetricsRefresh();

  // Stop refresh when leaving the page
  $(window).on('beforeunload', function () {
    if (metricsRefreshInterval) {
      clearInterval(metricsRefreshInterval);
    }
  });

  // Handle visibility change
  document.addEventListener('visibilitychange', function () {
    if (document.hidden) {
      if (metricsRefreshInterval) {
        clearInterval(metricsRefreshInterval);
      }
    } else {
      startMetricsRefresh();
    }
  });
});
