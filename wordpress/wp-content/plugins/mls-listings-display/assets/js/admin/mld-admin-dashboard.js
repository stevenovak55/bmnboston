/**
 * MLD Admin Dashboard JavaScript
 *
 * Comprehensive admin interface functionality for Phase 4
 * @param $
 */

(function ($) {
  'use strict';

  // Dashboard namespace
  window.MLDAdminDashboard = {
    // Configuration
    config: {
      apiUrl: mld_admin.api_url,
      nonce: mld_admin.nonce,
      refreshInterval: 30000, // 30 seconds
      chartColors: {
        primary: '#2271b1',
        secondary: '#135e96',
        success: '#00a32a',
        warning: '#dba617',
        danger: '#d63638',
        info: '#72aee6',
      },
    },

    // Charts storage
    charts: {},

    // DataTables storage
    tables: {},

    // Current filters
    filters: {
      dateFrom: moment().subtract(30, 'days').format('YYYY-MM-DD'),
      dateTo: moment().format('YYYY-MM-DD'),
      agentId: null,
      classification: null,
      status: null,
    },

    /**
     * Initialize dashboard
     */
    init() {
      this.bindEvents();
      this.initDatePickers();
      this.initCharts();
      this.initDataTables();
      this.loadDashboardData();
      this.startAutoRefresh();
      this.initRealTimeUpdates();
    },

    /**
     * Bind event handlers
     */
    bindEvents() {
      const self = this;

      // Filter changes
      $('#date-range-picker').on('apply.daterangepicker', function (ev, picker) {
        self.filters.dateFrom = picker.startDate.format('YYYY-MM-DD');
        self.filters.dateTo = picker.endDate.format('YYYY-MM-DD');
        self.applyFilters();
      });

      $('#agent-filter').on('change', function () {
        self.filters.agentId = $(this).val();
        self.applyFilters();
      });

      $('#classification-filter').on('change', function () {
        self.filters.classification = $(this).val();
        self.applyFilters();
      });

      // Lead actions
      $(document).on('click', '.assign-lead-btn', function () {
        self.showAssignLeadModal($(this).data('lead-id'));
      });

      $(document).on('click', '.view-lead-btn', function () {
        self.showLeadDetails($(this).data('lead-id'));
      });

      $(document).on('click', '.score-details-btn', function () {
        self.showScoreDetails($(this).data('lead-id'));
      });

      // Bulk actions
      $('#bulk-action-btn').on('click', function () {
        self.performBulkAction();
      });

      $('#select-all-leads').on('change', function () {
        $('.lead-checkbox').prop('checked', $(this).prop('checked'));
        self.updateBulkActionButtons();
      });

      $(document).on('change', '.lead-checkbox', function () {
        self.updateBulkActionButtons();
      });

      // Agent management
      $('#add-agent-btn').on('click', function () {
        self.showAddAgentModal();
      });

      $(document).on('click', '.edit-agent-btn', function () {
        self.showEditAgentModal($(this).data('agent-id'));
      });

      $(document).on('click', '.agent-performance-btn', function () {
        self.showAgentPerformance($(this).data('agent-id'));
      });

      // Export actions
      $('#export-leads-btn').on('click', function () {
        self.exportLeads();
      });

      $('#export-analytics-btn').on('click', function () {
        self.exportAnalytics();
      });

      // Tab navigation
      $('.nav-tab').on('click', function (e) {
        e.preventDefault();
        self.switchTab($(this).data('tab'));
      });

      // Search
      $('#lead-search').on(
        'keyup',
        _.debounce(function () {
          self.searchLeads($(this).val());
        }, 300)
      );

      // Notification actions
      $('#send-notification-btn').on('click', function () {
        self.showSendNotificationModal();
      });

      // Settings
      $('#save-settings-btn').on('click', function () {
        self.saveSettings();
      });

      // Real-time activity feed
      $('#activity-feed-refresh').on('click', function () {
        self.refreshActivityFeed();
      });
    },

    /**
     * Initialize date pickers
     */
    initDatePickers() {
      $('#date-range-picker').daterangepicker({
        startDate: moment().subtract(30, 'days'),
        endDate: moment(),
        ranges: {
          Today: [moment(), moment()],
          Yesterday: [moment().subtract(1, 'days'), moment().subtract(1, 'days')],
          'Last 7 Days': [moment().subtract(6, 'days'), moment()],
          'Last 30 Days': [moment().subtract(29, 'days'), moment()],
          'This Month': [moment().startOf('month'), moment().endOf('month')],
          'Last Month': [
            moment().subtract(1, 'month').startOf('month'),
            moment().subtract(1, 'month').endOf('month'),
          ],
          'Last 90 Days': [moment().subtract(89, 'days'), moment()],
          'This Year': [moment().startOf('year'), moment()],
        },
        locale: {
          format: 'YYYY-MM-DD',
        },
      });

      // Initialize single date pickers
      $('.datepicker').datepicker({
        dateFormat: 'yy-mm-dd',
        changeMonth: true,
        changeYear: true,
      });
    },

    /**
     * Initialize charts
     */
    initCharts() {
      // Conversion funnel chart
      this.initConversionFunnelChart();

      // Lead score distribution chart
      this.initLeadScoreChart();

      // Activity timeline chart
      this.initActivityTimelineChart();

      // Revenue chart
      this.initRevenueChart();

      // Channel performance chart
      this.initChannelPerformanceChart();

      // Agent performance chart
      this.initAgentPerformanceChart();
    },

    /**
     * Initialize conversion funnel chart
     */
    initConversionFunnelChart() {
      const ctx = document.getElementById('conversion-funnel-chart');
      if (!ctx) return;

      this.charts.conversionFunnel = new Chart(ctx.getContext('2d'), {
        type: 'funnel',
        data: {
          labels: [],
          datasets: [
            {
              data: [],
              backgroundColor: [
                this.config.chartColors.primary,
                this.config.chartColors.secondary,
                this.config.chartColors.success,
                this.config.chartColors.warning,
                this.config.chartColors.info,
              ],
            },
          ],
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: {
              display: false,
            },
            tooltip: {
              callbacks: {
                label(context) {
                  return (
                    context.label +
                    ': ' +
                    context.parsed +
                    ' (' +
                    context.dataset.percentages[context.dataIndex] +
                    '%)'
                  );
                },
              },
            },
          },
        },
      });
    },

    /**
     * Initialize lead score distribution chart
     */
    initLeadScoreChart() {
      const ctx = document.getElementById('lead-score-chart');
      if (!ctx) return;

      this.charts.leadScore = new Chart(ctx.getContext('2d'), {
        type: 'doughnut',
        data: {
          labels: ['Hot', 'Warm', 'Cold', 'Inactive'],
          datasets: [
            {
              data: [],
              backgroundColor: [
                this.config.chartColors.danger,
                this.config.chartColors.warning,
                this.config.chartColors.info,
                '#cccccc',
              ],
            },
          ],
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: {
              position: 'bottom',
            },
          },
        },
      });
    },

    /**
     * Initialize activity timeline chart
     */
    initActivityTimelineChart() {
      const ctx = document.getElementById('activity-timeline-chart');
      if (!ctx) return;

      this.charts.activityTimeline = new Chart(ctx.getContext('2d'), {
        type: 'line',
        data: {
          labels: [],
          datasets: [
            {
              label: 'Total Activities',
              data: [],
              borderColor: this.config.chartColors.primary,
              backgroundColor: this.config.chartColors.primary + '20',
              tension: 0.4,
            },
            {
              label: 'Searches',
              data: [],
              borderColor: this.config.chartColors.success,
              backgroundColor: this.config.chartColors.success + '20',
              tension: 0.4,
            },
            {
              label: 'Views',
              data: [],
              borderColor: this.config.chartColors.warning,
              backgroundColor: this.config.chartColors.warning + '20',
              tension: 0.4,
            },
          ],
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          scales: {
            y: {
              beginAtZero: true,
            },
          },
          plugins: {
            legend: {
              position: 'bottom',
            },
          },
        },
      });
    },

    /**
     * Initialize revenue chart
     */
    initRevenueChart() {
      const ctx = document.getElementById('revenue-chart');
      if (!ctx) return;

      this.charts.revenue = new Chart(ctx.getContext('2d'), {
        type: 'bar',
        data: {
          labels: [],
          datasets: [
            {
              label: 'Revenue',
              data: [],
              backgroundColor: this.config.chartColors.success,
              borderColor: this.config.chartColors.success,
              borderWidth: 1,
            },
          ],
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          scales: {
            y: {
              beginAtZero: true,
              ticks: {
                callback(value) {
                  return '$' + value.toLocaleString();
                },
              },
            },
          },
          plugins: {
            tooltip: {
              callbacks: {
                label(context) {
                  return context.dataset.label + ': $' + context.parsed.y.toLocaleString();
                },
              },
            },
          },
        },
      });
    },

    /**
     * Initialize channel performance chart
     */
    initChannelPerformanceChart() {
      const ctx = document.getElementById('channel-performance-chart');
      if (!ctx) return;

      this.charts.channelPerformance = new Chart(ctx.getContext('2d'), {
        type: 'radar',
        data: {
          labels: ['Email', 'SMS', 'Push', 'In-App', 'WhatsApp'],
          datasets: [
            {
              label: 'Open Rate',
              data: [],
              borderColor: this.config.chartColors.primary,
              backgroundColor: this.config.chartColors.primary + '20',
            },
            {
              label: 'Click Rate',
              data: [],
              borderColor: this.config.chartColors.success,
              backgroundColor: this.config.chartColors.success + '20',
            },
            {
              label: 'Conversion Rate',
              data: [],
              borderColor: this.config.chartColors.warning,
              backgroundColor: this.config.chartColors.warning + '20',
            },
          ],
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          scales: {
            r: {
              beginAtZero: true,
              max: 100,
            },
          },
        },
      });
    },

    /**
     * Initialize agent performance chart
     */
    initAgentPerformanceChart() {
      const ctx = document.getElementById('agent-performance-chart');
      if (!ctx) return;

      this.charts.agentPerformance = new Chart(ctx.getContext('2d'), {
        type: 'horizontalBar',
        data: {
          labels: [],
          datasets: [
            {
              label: 'Conversion Rate',
              data: [],
              backgroundColor: this.config.chartColors.success,
            },
          ],
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          scales: {
            x: {
              beginAtZero: true,
              max: 100,
              ticks: {
                callback(value) {
                  return value + '%';
                },
              },
            },
          },
        },
      });
    },

    /**
     * Initialize DataTables
     */
    initDataTables() {
      // Leads table
      this.tables.leads = $('#leads-table').DataTable({
        processing: true,
        serverSide: true,
        ajax: {
          url: this.config.apiUrl + '/leads',
          headers: {
            'X-WP-Nonce': this.config.nonce,
          },
          data(d) {
            return $.extend({}, d, MLDAdminDashboard.filters);
          },
        },
        columns: [
          {
            data: null,
            orderable: false,
            render() {
              return '<input type="checkbox" class="lead-checkbox">';
            },
          },
          { data: 'user_id' },
          { data: 'display_name' },
          { data: 'user_email' },
          {
            data: 'score',
            render(data, type, row) {
              let badgeClass = 'badge-secondary';
              if (row.classification === 'hot') badgeClass = 'badge-danger';
              else if (row.classification === 'warm') badgeClass = 'badge-warning';
              else if (row.classification === 'cold') badgeClass = 'badge-info';

              return '<span class="badge ' + badgeClass + '">' + data + '</span>';
            },
          },
          { data: 'classification' },
          { data: 'agent_name' },
          {
            data: 'last_activity',
            render(data) {
              return moment(data).fromNow();
            },
          },
          {
            data: null,
            orderable: false,
            render(data) {
              return (
                '<div class="btn-group">' +
                '<button class="btn btn-sm btn-primary view-lead-btn" data-lead-id="' +
                data.user_id +
                '">View</button>' +
                '<button class="btn btn-sm btn-secondary assign-lead-btn" data-lead-id="' +
                data.user_id +
                '">Assign</button>' +
                '<button class="btn btn-sm btn-info score-details-btn" data-lead-id="' +
                data.user_id +
                '">Score</button>' +
                '</div>'
              );
            },
          },
        ],
        order: [[4, 'desc']],
        pageLength: 25,
        responsive: true,
        dom: 'Bfrtip',
        buttons: ['copy', 'csv', 'excel', 'pdf', 'print'],
      });

      // Agents table
      this.tables.agents = $('#agents-table').DataTable({
        processing: true,
        serverSide: true,
        ajax: {
          url: this.config.apiUrl + '/agents',
          headers: {
            'X-WP-Nonce': this.config.nonce,
          },
        },
        columns: [
          { data: 'user_id' },
          { data: 'display_name' },
          { data: 'user_email' },
          { data: 'active_leads' },
          {
            data: 'recent_conversion_rate',
            render(data) {
              return (data || 0).toFixed(2) + '%';
            },
          },
          {
            data: 'ytd_commission',
            render(data) {
              return '$' + (data || 0).toLocaleString();
            },
          },
          {
            data: 'performance_score',
            render(data) {
              let progressClass = 'bg-secondary';
              if (data >= 80) progressClass = 'bg-success';
              else if (data >= 60) progressClass = 'bg-warning';
              else if (data >= 40) progressClass = 'bg-info';
              else progressClass = 'bg-danger';

              return (
                '<div class="progress">' +
                '<div class="progress-bar ' +
                progressClass +
                '" style="width: ' +
                data +
                '%">' +
                data +
                '</div>' +
                '</div>'
              );
            },
          },
          {
            data: null,
            orderable: false,
            render(data) {
              return (
                '<div class="btn-group">' +
                '<button class="btn btn-sm btn-primary edit-agent-btn" data-agent-id="' +
                data.user_id +
                '">Edit</button>' +
                '<button class="btn btn-sm btn-info agent-performance-btn" data-agent-id="' +
                data.user_id +
                '">Performance</button>' +
                '</div>'
              );
            },
          },
        ],
        order: [[6, 'desc']],
        pageLength: 25,
        responsive: true,
      });

      // Searches table
      this.tables.searches = $('#searches-table').DataTable({
        processing: true,
        serverSide: true,
        ajax: {
          url: this.config.apiUrl + '/searches',
          headers: {
            'X-WP-Nonce': this.config.nonce,
          },
        },
        columns: [
          { data: 'id' },
          { data: 'name' },
          { data: 'user_name' },
          { data: 'match_count' },
          { data: 'notification_frequency' },
          { data: 'status' },
          {
            data: 'created_at',
            render(data) {
              return moment(data).format('YYYY-MM-DD');
            },
          },
          {
            data: null,
            orderable: false,
            render(data) {
              return (
                '<div class="btn-group">' +
                '<button class="btn btn-sm btn-primary view-search-btn" data-search-id="' +
                data.id +
                '">View</button>' +
                '<button class="btn btn-sm btn-secondary edit-search-btn" data-search-id="' +
                data.id +
                '">Edit</button>' +
                '</div>'
              );
            },
          },
        ],
        order: [[6, 'desc']],
        pageLength: 25,
        responsive: true,
      });
    },

    /**
     * Load dashboard data
     */
    loadDashboardData() {
      const self = this;

      // Show loading state
      this.showLoading();

      // Load overview data
      $.ajax({
        url: this.config.apiUrl + '/leads/dashboard',
        method: 'POST',
        headers: {
          'X-WP-Nonce': this.config.nonce,
        },
        data: {
          filters: this.filters,
        },
        success(response) {
          if (response.success) {
            self.updateDashboard(response.data);
          }
        },
        complete() {
          self.hideLoading();
        },
      });

      // Load analytics data
      this.loadAnalyticsData();
    },

    /**
     * Load analytics data
     */
    loadAnalyticsData() {
      const self = this;

      // Load conversion funnel
      $.ajax({
        url: this.config.apiUrl + '/analytics/funnel',
        method: 'GET',
        headers: {
          'X-WP-Nonce': this.config.nonce,
        },
        data: this.filters,
        success(response) {
          if (response.stages) {
            self.updateConversionFunnel(response);
          }
        },
      });

      // Load revenue data
      $.ajax({
        url: this.config.apiUrl + '/analytics/revenue',
        method: 'GET',
        headers: {
          'X-WP-Nonce': this.config.nonce,
        },
        data: this.filters,
        success(response) {
          if (response.trend) {
            self.updateRevenueChart(response.trend);
          }
        },
      });

      // Load channel performance
      $.ajax({
        url: this.config.apiUrl + '/analytics/channels',
        method: 'GET',
        headers: {
          'X-WP-Nonce': this.config.nonce,
        },
        data: this.filters,
        success(response) {
          if (response.channels) {
            self.updateChannelPerformance(response.channels);
          }
        },
      });
    },

    /**
     * Update dashboard with data
     * @param data
     */
    updateDashboard(data) {
      // Update summary cards
      $('#total-leads').text(data.summary.total_leads);
      $('#hot-leads').text(data.summary.hot_leads);
      $('#warm-leads').text(data.summary.warm_leads);
      $('#cold-leads').text(data.summary.cold_leads);
      $('#new-leads').text(data.summary.new_leads);
      $('#active-leads').text(data.summary.active_leads);
      $('#conversion-rate').text(data.summary.conversion_rate + '%');

      // Update lead score chart
      if (this.charts.leadScore) {
        this.charts.leadScore.data.datasets[0].data = [
          data.summary.hot_leads,
          data.summary.warm_leads,
          data.summary.cold_leads,
          data.summary.total_leads -
            data.summary.hot_leads -
            data.summary.warm_leads -
            data.summary.cold_leads,
        ];
        this.charts.leadScore.update();
      }

      // Update activity timeline
      if (data.activity_metrics && data.activity_metrics.trend) {
        this.updateActivityTimeline(data.activity_metrics.trend);
      }

      // Update agent performance
      if (data.agent_performance) {
        this.updateAgentPerformanceChart(data.agent_performance);
      }

      // Update activity feed
      this.updateActivityFeed(data.recent_activity);
    },

    /**
     * Update conversion funnel
     * @param data
     */
    updateConversionFunnel(data) {
      if (!this.charts.conversionFunnel) return;

      const labels = Object.keys(data.stages);
      const values = Object.values(data.stages);
      const percentages = [];

      for (let i = 0; i < values.length; i++) {
        if (i === 0) {
          percentages.push(100);
        } else {
          percentages.push(values[0] > 0 ? ((values[i] / values[0]) * 100).toFixed(2) : 0);
        }
      }

      this.charts.conversionFunnel.data.labels = labels;
      this.charts.conversionFunnel.data.datasets[0].data = values;
      this.charts.conversionFunnel.data.datasets[0].percentages = percentages;
      this.charts.conversionFunnel.update();
    },

    /**
     * Update activity timeline
     * @param data
     */
    updateActivityTimeline(data) {
      if (!this.charts.activityTimeline) return;

      const labels = data.map(function (item) {
        return moment(item.date).format('MMM DD');
      });

      const totalActions = data.map(function (item) {
        return item.total_actions;
      });

      const searches = data.map(function (item) {
        return item.searches;
      });

      const views = data.map(function (item) {
        return item.views;
      });

      this.charts.activityTimeline.data.labels = labels;
      this.charts.activityTimeline.data.datasets[0].data = totalActions;
      this.charts.activityTimeline.data.datasets[1].data = searches;
      this.charts.activityTimeline.data.datasets[2].data = views;
      this.charts.activityTimeline.update();
    },

    /**
     * Update revenue chart
     * @param data
     */
    updateRevenueChart(data) {
      if (!this.charts.revenue) return;

      const labels = data.map(function (item) {
        return moment(item.date).format('MMM DD');
      });

      const revenues = data.map(function (item) {
        return item.daily_revenue;
      });

      this.charts.revenue.data.labels = labels;
      this.charts.revenue.data.datasets[0].data = revenues;
      this.charts.revenue.update();
    },

    /**
     * Update channel performance
     * @param data
     */
    updateChannelPerformance(data) {
      if (!this.charts.channelPerformance) return;

      const channels = Object.keys(data);
      const openRates = channels.map(function (channel) {
        return data[channel].open_rate;
      });
      const clickRates = channels.map(function (channel) {
        return data[channel].click_rate;
      });
      const conversionRates = channels.map(function (channel) {
        return data[channel].conversion_rate;
      });

      this.charts.channelPerformance.data.datasets[0].data = openRates;
      this.charts.channelPerformance.data.datasets[1].data = clickRates;
      this.charts.channelPerformance.data.datasets[2].data = conversionRates;
      this.charts.channelPerformance.update();
    },

    /**
     * Update agent performance chart
     * @param data
     */
    updateAgentPerformanceChart(data) {
      if (!this.charts.agentPerformance) return;

      const labels = data.map(function (agent) {
        return agent.agent_name;
      });

      const conversionRates = data.map(function (agent) {
        return ((agent.conversions / agent.total_leads) * 100).toFixed(2);
      });

      this.charts.agentPerformance.data.labels = labels;
      this.charts.agentPerformance.data.datasets[0].data = conversionRates;
      this.charts.agentPerformance.update();
    },

    /**
     * Update activity feed
     * @param activities
     */
    updateActivityFeed(activities) {
      let feedHtml = '';

      if (!activities || activities.length === 0) {
        feedHtml = '<div class="activity-item">No recent activity</div>';
      } else {
        activities.forEach(function (activity) {
          const icon = self.getActivityIcon(activity.action_type);
          const timeAgo = moment(activity.action_time).fromNow();

          feedHtml +=
            '<div class="activity-item">' +
            '<i class="' +
            icon +
            '"></i> ' +
            '<strong>' +
            activity.display_name +
            '</strong> ' +
            activity.action_type +
            ' ' +
            '<span class="time-ago">' +
            timeAgo +
            '</span>' +
            '</div>';
        });
      }

      $('#activity-feed').html(feedHtml);
    },

    /**
     * Get activity icon
     * @param actionType
     */
    getActivityIcon(actionType) {
      const icons = {
        search: 'dashicons dashicons-search',
        view: 'dashicons dashicons-visibility',
        favorite: 'dashicons dashicons-heart',
        inquiry: 'dashicons dashicons-email',
        tour: 'dashicons dashicons-calendar',
        contact: 'dashicons dashicons-phone',
      };

      return icons[actionType] || 'dashicons dashicons-marker';
    },

    /**
     * Apply filters
     */
    applyFilters() {
      // Reload DataTables
      if (this.tables.leads) {
        this.tables.leads.ajax.reload();
      }

      // Reload dashboard data
      this.loadDashboardData();
    },

    /**
     * Start auto-refresh
     */
    startAutoRefresh() {
      const self = this;

      setInterval(function () {
        if (!document.hidden) {
          self.refreshActivityFeed();
          self.refreshDashboardStats();
        }
      }, this.config.refreshInterval);
    },

    /**
     * Refresh activity feed
     */
    refreshActivityFeed() {
      const self = this;

      $.ajax({
        url: this.config.apiUrl + '/activity-feed',
        method: 'GET',
        headers: {
          'X-WP-Nonce': this.config.nonce,
        },
        data: {
          limit: 20,
        },
        success(response) {
          if (response.success) {
            self.updateActivityFeed(response.data);
          }
        },
      });
    },

    /**
     * Refresh dashboard stats
     */
    refreshDashboardStats() {
      const self = this;

      $.ajax({
        url: this.config.apiUrl + '/dashboard-stats',
        method: 'GET',
        headers: {
          'X-WP-Nonce': this.config.nonce,
        },
        success(response) {
          if (response.success) {
            self.updateDashboardStats(response.data);
          }
        },
      });
    },

    /**
     * Initialize real-time updates
     */
    initRealTimeUpdates() {
      // WebSocket connection for real-time updates
      if (window.WebSocket && mld_admin.websocket_url) {
        this.websocket = new WebSocket(mld_admin.websocket_url);

        this.websocket.onmessage = function (event) {
          const data = JSON.parse(event.data);

          switch (data.type) {
            case 'new_lead':
              MLDAdminDashboard.handleNewLead(data.lead);
              break;
            case 'lead_score_update':
              MLDAdminDashboard.handleLeadScoreUpdate(data);
              break;
            case 'agent_activity':
              MLDAdminDashboard.handleAgentActivity(data);
              break;
          }
        };
      }
    },

    /**
     * Handle new lead notification
     * @param lead
     */
    handleNewLead(lead) {
      // Show notification
      this.showNotification('New Lead', lead.display_name + ' has been added', 'success');

      // Refresh leads table
      if (this.tables.leads) {
        this.tables.leads.ajax.reload();
      }

      // Update stats
      const currentTotal = parseInt($('#total-leads').text());
      $('#total-leads').text(currentTotal + 1);

      const currentNew = parseInt($('#new-leads').text());
      $('#new-leads').text(currentNew + 1);
    },

    /**
     * Handle lead score update
     * @param data
     */
    handleLeadScoreUpdate(data) {
      // Update specific row in table if visible
      if (this.tables.leads) {
        const row = this.tables.leads.row('#lead-' + data.lead_id);
        if (row.length) {
          row.data().score = data.score;
          row.data().classification = data.classification;
          row.invalidate().draw(false);
        }
      }
    },

    /**
     * Handle agent activity
     * @param data
     */
    handleAgentActivity(data) {
      // Add to activity feed
      const activity = {
        display_name: data.agent_name,
        action_type: data.action,
        action_time: new Date().toISOString(),
      };

      this.prependToActivityFeed(activity);
    },

    /**
     * Prepend to activity feed
     * @param activity
     */
    prependToActivityFeed(activity) {
      const icon = this.getActivityIcon(activity.action_type);
      const timeAgo = 'just now';

      const itemHtml =
        '<div class="activity-item new-item">' +
        '<i class="' +
        icon +
        '"></i> ' +
        '<strong>' +
        activity.display_name +
        '</strong> ' +
        activity.action_type +
        ' ' +
        '<span class="time-ago">' +
        timeAgo +
        '</span>' +
        '</div>';

      $('#activity-feed').prepend(itemHtml);

      // Limit feed items
      $('#activity-feed .activity-item').slice(20).remove();

      // Animate new item
      $('.new-item').hide().slideDown().removeClass('new-item');
    },

    /**
     * Show notification
     * @param title
     * @param message
     * @param type
     */
    showNotification(title, message, type) {
      // Using WordPress admin notices
      const noticeClass = 'notice-' + (type || 'info');
      const noticeHtml =
        '<div class="notice ' +
        noticeClass +
        ' is-dismissible">' +
        '<p><strong>' +
        title +
        '</strong>: ' +
        message +
        '</p>' +
        '<button type="button" class="notice-dismiss"></button>' +
        '</div>';

      $('.wrap > h1').after(noticeHtml);

      // Auto-dismiss after 5 seconds
      setTimeout(function () {
        $('.notice').fadeOut(function () {
          $(this).remove();
        });
      }, 5000);
    },

    /**
     * Show loading state
     */
    showLoading() {
      $('#dashboard-loading').show();
    },

    /**
     * Hide loading state
     */
    hideLoading() {
      $('#dashboard-loading').hide();
    },

    /**
     * Switch tab
     * @param tab
     */
    switchTab(tab) {
      $('.nav-tab').removeClass('nav-tab-active');
      $('.nav-tab[data-tab="' + tab + '"]').addClass('nav-tab-active');

      $('.tab-content').hide();
      $('#' + tab + '-content').show();

      // Redraw charts if switching to analytics tab
      if (tab === 'analytics') {
        Object.values(this.charts).forEach(function (chart) {
          if (chart) chart.resize();
        });
      }
    },

    /**
     * Export functions
     */
    exportLeads() {
      window.location.href = this.config.apiUrl + '/leads/export?' + $.param(this.filters);
    },

    exportAnalytics() {
      window.location.href = this.config.apiUrl + '/analytics/export?' + $.param(this.filters);
    },
  };

  // Initialize on document ready
  $(document).ready(function () {
    if ($('#mld-admin-dashboard').length) {
      MLDAdminDashboard.init();
    }
  });
})(jQuery);
