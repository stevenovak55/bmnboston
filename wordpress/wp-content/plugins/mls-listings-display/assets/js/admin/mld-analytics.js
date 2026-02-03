/**
 * MLD Analytics Dashboard JavaScript
 *
 * @param $
 * @package
 */

(function ($) {
  'use strict';

  /**
   * Real-time Analytics Manager
   * @param options
   */
  window.MLDRealtimeAnalytics = function (options) {
    this.options = $.extend(
      {
        refreshInterval: 30000,
        endpoints: {},
        charts: {},
      },
      options
    );

    this.timers = {};
    this.charts = {};
    this.data = {};
  };

  MLDRealtimeAnalytics.prototype = {
    /**
     * Initialize
     */
    init() {
      this.setupCharts();
      this.bindEvents();
      this.startAutoRefresh();
      this.loadInitialData();
    },

    /**
     * Setup charts
     */
    setupCharts() {
      // Traffic chart
      const trafficCtx = document.getElementById('traffic-chart');
      if (trafficCtx) {
        this.charts.traffic = new Chart(trafficCtx.getContext('2d'), {
          type: 'line',
          data: {
            labels: [],
            datasets: [
              {
                label: 'Users',
                data: [],
                borderColor: 'rgb(75, 192, 192)',
                tension: 0.1,
              },
              {
                label: 'Sessions',
                data: [],
                borderColor: 'rgb(54, 162, 235)',
                tension: 0.1,
              },
            ],
          },
          options: {
            responsive: true,
            animation: {
              duration: 750,
            },
            scales: {
              y: {
                beginAtZero: true,
              },
            },
          },
        });
      }

      // Funnel chart
      const funnelCtx = document.getElementById('funnel-chart');
      if (funnelCtx) {
        this.charts.funnel = new Chart(funnelCtx.getContext('2d'), {
          type: 'bar',
          data: {
            labels: ['Visitors', 'Searches', 'Views', 'Inquiries', 'Conversions'],
            datasets: [
              {
                label: 'Funnel',
                data: [100, 75, 50, 25, 10],
                backgroundColor: [
                  'rgba(75, 192, 192, 0.6)',
                  'rgba(54, 162, 235, 0.6)',
                  'rgba(255, 206, 86, 0.6)',
                  'rgba(255, 159, 64, 0.6)',
                  'rgba(153, 102, 255, 0.6)',
                ],
              },
            ],
          },
          options: {
            responsive: true,
            indexAxis: 'y',
            scales: {
              x: {
                beginAtZero: true,
                max: 100,
              },
            },
          },
        });
      }
    },

    /**
     * Bind events
     */
    bindEvents() {
      const self = this;

      // Auto-refresh toggle
      $('#auto-refresh').on('change', function () {
        if ($(this).is(':checked')) {
          self.startAutoRefresh();
        } else {
          self.stopAutoRefresh();
        }
      });

      // Manual refresh
      $('#refresh-now').on('click', function () {
        self.refreshAllData();
      });

      // Time range selector
      $('#realtime-range').on('change', function () {
        self.options.timeRange = $(this).val();
        self.refreshAllData();
      });
    },

    /**
     * Start auto refresh
     */
    startAutoRefresh() {
      const self = this;

      this.timers.metrics = setInterval(function () {
        self.updateMetrics();
      }, this.options.refreshInterval);

      this.timers.activity = setInterval(function () {
        self.updateActivityStream();
      }, 10000); // Every 10 seconds

      this.timers.performance = setInterval(function () {
        self.updatePerformance();
      }, 5000); // Every 5 seconds
    },

    /**
     * Stop auto refresh
     */
    stopAutoRefresh() {
      Object.values(this.timers).forEach(function (timer) {
        clearInterval(timer);
      });
      this.timers = {};
    },

    /**
     * Load initial data
     */
    loadInitialData() {
      this.updateMetrics();
      this.updateActivityStream();
      this.updatePerformance();
      this.updateCharts();
    },

    /**
     * Refresh all data
     */
    refreshAllData() {
      this.loadInitialData();
      this.showNotification('Data refreshed', 'success');
    },

    /**
     * Update metrics
     */
    updateMetrics() {
      const self = this;

      $.ajax({
        url: this.options.endpoints.metrics,
        method: 'GET',
        data: {
          range: $('#realtime-range').val(),
          nonce: mld_analytics.nonce,
        },
        success(response) {
          if (response.success) {
            self.renderMetrics(response.data);
          }
        },
      });
    },

    /**
     * Render metrics
     * @param data
     */
    renderMetrics(data) {
      // Update metric cards
      Object.keys(data.metrics).forEach(function (metric) {
        const $card = $('[data-metric="' + metric + '"]');
        if ($card.length) {
          const value = data.metrics[metric];
          const $value = $card.find('.metric-value');
          const $trend = $card.find('.trend-value');
          const $indicator = $card.find('.trend-indicator');

          // Animate value change
          self.animateValue($value[0], parseInt($value.text()), value.current);

          // Update trend
          if (value.trend) {
            $trend.text(value.trend + '%');
            $indicator
              .removeClass('up down stable')
              .addClass(value.trend > 0 ? 'up' : value.trend < 0 ? 'down' : 'stable');
          }
        }
      });

      // Store data for charts
      this.data.metrics = data;
    },

    /**
     * Update activity stream
     */
    updateActivityStream() {
      const self = this;

      $.ajax({
        url: this.options.endpoints.activity,
        method: 'GET',
        data: {
          limit: 20,
          nonce: mld_analytics.nonce,
        },
        success(response) {
          if (response.success) {
            self.renderActivityStream(response.data);
          }
        },
      });
    },

    /**
     * Render activity stream
     * @param activities
     */
    renderActivityStream(activities) {
      const $stream = $('#activity-stream');
      $stream.empty();

      activities.forEach(function (activity) {
        const $item = $('<div class="activity-item">');
        $item.append(
          '<span class="activity-time">' + self.formatTime(activity.timestamp) + '</span>'
        );
        $item.append('<span class="activity-user">' + (activity.user || 'Guest') + '</span>');
        $item.append('<span class="activity-action">' + activity.action + '</span>');

        $item.hide().prependTo($stream).fadeIn(300);
      });

      // Limit to 20 items
      $stream.find('.activity-item:gt(19)').remove();
    },

    /**
     * Update performance metrics
     */
    updatePerformance() {
      const self = this;

      $.ajax({
        url: this.options.endpoints.performance,
        method: 'GET',
        data: {
          nonce: mld_analytics.nonce,
        },
        success(response) {
          if (response.success) {
            self.renderPerformance(response.data);
          }
        },
      });
    },

    /**
     * Render performance metrics
     * @param data
     */
    renderPerformance(data) {
      Object.keys(data).forEach(function (metric) {
        const $bar = $('#' + metric);
        const $value = $bar.siblings('.perf-value');
        const value = data[metric];

        // Update bar width
        const percentage = Math.min(value.percentage || 0, 100);
        $bar.css('width', percentage + '%');

        // Update color based on threshold
        $bar.removeClass('good warning danger');
        if (percentage < 50) {
          $bar.addClass('good');
        } else if (percentage < 80) {
          $bar.addClass('warning');
        } else {
          $bar.addClass('danger');
        }

        // Update value text
        $value.text(value.formatted);
      });
    },

    /**
     * Update charts
     */
    updateCharts() {
      const self = this;

      // Update traffic chart
      if (this.charts.traffic && this.data.metrics) {
        const labels = this.generateTimeLabels();
        const userData = this.generateChartData('users');
        const sessionData = this.generateChartData('sessions');

        this.charts.traffic.data.labels = labels;
        this.charts.traffic.data.datasets[0].data = userData;
        this.charts.traffic.data.datasets[1].data = sessionData;
        this.charts.traffic.update();
      }

      // Update funnel chart
      if (this.charts.funnel && this.data.metrics) {
        if (this.data.metrics.funnel) {
          this.charts.funnel.data.datasets[0].data = this.data.metrics.funnel;
          this.charts.funnel.update();
        }
      }
    },

    /**
     * Generate time labels
     */
    generateTimeLabels() {
      const labels = [];
      const now = new Date();

      for (let i = 23; i >= 0; i--) {
        const time = new Date(now - i * 3600000);
        labels.push(time.getHours() + ':00');
      }

      return labels;
    },

    /**
     * Generate chart data
     * @param metric
     */
    generateChartData(metric) {
      // Generate random data for demonstration
      const data = [];
      for (let i = 0; i < 24; i++) {
        data.push(Math.floor(Math.random() * 100) + 20);
      }
      return data;
    },

    /**
     * Animate value
     * @param element
     * @param start
     * @param end
     */
    animateValue(element, start, end) {
      if (!element) return;

      const duration = 500;
      const range = end - start;
      const startTime = new Date().getTime();

      var timer = setInterval(function () {
        const elapsed = new Date().getTime() - startTime;
        const progress = Math.min(elapsed / duration, 1);
        const value = Math.floor(start + range * progress);

        element.textContent = self.formatNumber(value);

        if (progress >= 1) {
          clearInterval(timer);
        }
      }, 16);
    },

    /**
     * Format number
     * @param num
     */
    formatNumber(num) {
      if (num >= 1000000) {
        return (num / 1000000).toFixed(1) + 'M';
      } else if (num >= 1000) {
        return (num / 1000).toFixed(1) + 'K';
      }
      return num.toString();
    },

    /**
     * Format time
     * @param timestamp
     */
    formatTime(timestamp) {
      const date = new Date(timestamp);
      const now = new Date();
      const diff = now - date;

      if (diff < 60000) {
        return 'Just now';
      } else if (diff < 3600000) {
        return Math.floor(diff / 60000) + 'm ago';
      } else if (diff < 86400000) {
        return Math.floor(diff / 3600000) + 'h ago';
      }
      return date.toLocaleDateString();
    },

    /**
     * Show notification
     * @param message
     * @param type
     */
    showNotification(message, type) {
      const $notification = $('<div class="mld-notification">');
      $notification.addClass('notification-' + type);
      $notification.text(message);

      $('body').append($notification);
      $notification.fadeIn(300);

      setTimeout(function () {
        $notification.fadeOut(300, function () {
          $(this).remove();
        });
      }, 3000);
    },
  };

  /**
   * Report Builder
   * @param options
   */
  window.MLDReportBuilder = function (options) {
    this.options = $.extend(
      {
        container: '#report-builder',
        endpoints: {},
      },
      options
    );

    this.selectedSections = [];
    this.reportData = null;
  };

  MLDReportBuilder.prototype = {
    /**
     * Initialize
     */
    init() {
      this.bindEvents();
      this.loadTemplates();
    },

    /**
     * Bind events
     */
    bindEvents() {
      const self = this;

      // Template selection
      $(document).on('change', '#report-template', function () {
        self.loadTemplate($(this).val());
      });

      // Section selection
      $(document).on('change', '.section-checkbox', function () {
        self.updateSelectedSections();
      });

      // Generate report
      $(document).on('click', '#generate-report', function () {
        self.generateReport();
      });

      // Export report
      $(document).on('click', '.export-btn', function () {
        self.exportReport($(this).data('format'));
      });

      // Schedule report
      $(document).on('click', '#schedule-report', function () {
        self.scheduleReport();
      });
    },

    /**
     * Load templates
     */
    loadTemplates() {
      $.ajax({
        url: this.options.endpoints.templates,
        method: 'GET',
        data: {
          nonce: mld_analytics.nonce,
        },
        success(response) {
          if (response.success) {
            self.renderTemplates(response.data);
          }
        },
      });
    },

    /**
     * Load template
     * @param templateId
     */
    loadTemplate(templateId) {
      const self = this;

      $.ajax({
        url: this.options.endpoints.template,
        method: 'GET',
        data: {
          template: templateId,
          nonce: mld_analytics.nonce,
        },
        success(response) {
          if (response.success) {
            self.renderSections(response.data.sections);
          }
        },
      });
    },

    /**
     * Render sections
     * @param sections
     */
    renderSections(sections) {
      const $container = $('#sections-container');
      $container.empty();

      sections.forEach(function (section) {
        const $section = $('<div class="report-section">');
        $section.append(
          '<label><input type="checkbox" class="section-checkbox" value="' +
            section.id +
            '" checked> ' +
            section.name +
            '</label>'
        );
        $section.append('<p class="section-description">' + section.description + '</p>');
        $container.append($section);
      });

      this.updateSelectedSections();
    },

    /**
     * Update selected sections
     */
    updateSelectedSections() {
      this.selectedSections = [];
      const self = this;

      $('.section-checkbox:checked').each(function () {
        self.selectedSections.push($(this).val());
      });
    },

    /**
     * Generate report
     */
    generateReport() {
      const self = this;

      const data = {
        report_type: $('#report-template').val(),
        sections: this.selectedSections,
        start_date: $('#start-date').val(),
        end_date: $('#end-date').val(),
        filters: this.getFilters(),
        nonce: mld_analytics.nonce,
      };

      // Show loading
      this.showLoading(true);

      $.ajax({
        url: this.options.endpoints.generate,
        method: 'POST',
        data,
        success(response) {
          if (response.success) {
            self.reportData = response.data;
            self.renderReport(response.data);
          } else {
            self.showError('Failed to generate report');
          }
        },
        complete() {
          self.showLoading(false);
        },
      });
    },

    /**
     * Render report
     * @param report
     */
    renderReport(report) {
      const $container = $('#report-preview');
      $container.empty();

      // Render header
      const $header = $('<div class="report-header">');
      $header.append('<h2>' + report.type + ' Report</h2>');
      $header.append('<p>Generated: ' + report.generated_at + '</p>');
      $header.append(
        '<p>Period: ' + report.date_range.start + ' to ' + report.date_range.end + '</p>'
      );
      $container.append($header);

      // Render summary
      if (report.summary) {
        const $summary = $('<div class="report-summary">');
        $summary.append('<h3>Summary</h3>');

        if (report.summary.highlights && report.summary.highlights.length) {
          $summary.append('<h4>Highlights</h4>');
          const $highlights = $('<ul>');
          report.summary.highlights.forEach(function (highlight) {
            $highlights.append('<li>' + highlight + '</li>');
          });
          $summary.append($highlights);
        }

        $container.append($summary);
      }

      // Render sections
      Object.keys(report.data).forEach(function (section) {
        const $section = $('<div class="report-section-content">');
        $section.append('<h3>' + self.formatSectionName(section) + '</h3>');
        $section.append(self.renderSectionData(report.data[section]));
        $container.append($section);
      });

      // Show export options
      $('.export-options').show();
    },

    /**
     * Render section data
     * @param data
     */
    renderSectionData(data) {
      if (typeof data === 'object' && !Array.isArray(data)) {
        return this.renderObject(data);
      } else if (Array.isArray(data)) {
        return this.renderArray(data);
      }
      return '<p>' + data + '</p>';
    },

    /**
     * Render object
     * @param obj
     */
    renderObject(obj) {
      const $table = $('<table class="report-table">');

      Object.keys(obj).forEach(function (key) {
        const $row = $('<tr>');
        $row.append('<td>' + self.formatKey(key) + '</td>');
        $row.append('<td>' + self.formatValue(obj[key]) + '</td>');
        $table.append($row);
      });

      return $table;
    },

    /**
     * Render array
     * @param arr
     */
    renderArray(arr) {
      if (arr.length === 0) {
        return '<p>No data available</p>';
      }

      if (typeof arr[0] === 'object') {
        return this.renderTable(arr);
      }
      const $list = $('<ul>');
      arr.forEach(function (item) {
        $list.append('<li>' + item + '</li>');
      });
      return $list;
    },

    /**
     * Render table
     * @param data
     */
    renderTable(data) {
      const $table = $('<table class="report-table">');

      // Header
      const $thead = $('<thead>');
      const $headerRow = $('<tr>');
      Object.keys(data[0]).forEach(function (key) {
        $headerRow.append('<th>' + self.formatKey(key) + '</th>');
      });
      $thead.append($headerRow);
      $table.append($thead);

      // Body
      const $tbody = $('<tbody>');
      data.forEach(function (row) {
        const $row = $('<tr>');
        Object.values(row).forEach(function (value) {
          $row.append('<td>' + self.formatValue(value) + '</td>');
        });
        $tbody.append($row);
      });
      $table.append($tbody);

      return $table;
    },

    /**
     * Export report
     * @param format
     */
    exportReport(format) {
      if (!this.reportData) {
        this.showError('No report to export');
        return;
      }

      const self = this;

      $.ajax({
        url: this.options.endpoints.export,
        method: 'POST',
        data: {
          report: JSON.stringify(this.reportData),
          format,
          nonce: mld_analytics.nonce,
        },
        success(response) {
          if (response.success) {
            window.location.href = response.data.url;
          } else {
            self.showError('Export failed');
          }
        },
      });
    },

    /**
     * Schedule report
     */
    scheduleReport() {
      const self = this;

      const data = {
        report_type: $('#report-template').val(),
        sections: this.selectedSections,
        schedule: $('#report-schedule').val(),
        recipients: $('#report-recipients').val().split(','),
        nonce: mld_analytics.nonce,
      };

      $.ajax({
        url: this.options.endpoints.schedule,
        method: 'POST',
        data,
        success(response) {
          if (response.success) {
            self.showSuccess('Report scheduled successfully');
          } else {
            self.showError('Failed to schedule report');
          }
        },
      });
    },

    /**
     * Helper methods
     */

    getFilters() {
      const filters = {};

      $('.report-filter').each(function () {
        const $this = $(this);
        filters[$this.attr('name')] = $this.val();
      });

      return filters;
    },

    formatSectionName(section) {
      return section.replace(/_/g, ' ').replace(/\b\w/g, function (l) {
        return l.toUpperCase();
      });
    },

    formatKey(key) {
      return key.replace(/_/g, ' ').replace(/\b\w/g, function (l) {
        return l.toUpperCase();
      });
    },

    formatValue(value) {
      if (typeof value === 'number') {
        return value.toLocaleString();
      } else if (typeof value === 'boolean') {
        return value ? 'Yes' : 'No';
      } else if (value === null || value === undefined) {
        return '-';
      } else if (typeof value === 'object') {
        return JSON.stringify(value);
      }
      return value;
    },

    showLoading(show) {
      if (show) {
        $('#report-preview').html('<div class="loading">Generating report...</div>');
      } else {
        $('.loading').remove();
      }
    },

    showError(message) {
      this.showNotification(message, 'error');
    },

    showSuccess(message) {
      this.showNotification(message, 'success');
    },

    showNotification(message, type) {
      const $notification = $('<div class="mld-notification">');
      $notification.addClass('notification-' + type);
      $notification.text(message);

      $(this.options.container).prepend($notification);
      $notification.fadeIn(300);

      setTimeout(function () {
        $notification.fadeOut(300, function () {
          $(this).remove();
        });
      }, 3000);
    },
  };
})(jQuery);
