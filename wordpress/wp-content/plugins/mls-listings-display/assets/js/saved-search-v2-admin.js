/**
 * Saved Search V2 Admin JavaScript
 * @param $
 */

(function ($) {
  'use strict';

  // Main app object
  window.MLDSavedSearchV2 = {
    // Initialize the app
    init() {
      this.currentPage = mldSavedSearchV2.currentPage || 'saved_searches';
      this.bindEvents();
      this.loadPageContent();
    },

    // Bind event handlers
    bindEvents() {
      // Tab navigation
      $(document).on('click', '.mld-tab-nav a', this.handleTabClick.bind(this));

      // Search management
      $(document).on('click', '.mld-create-search', this.showCreateSearchModal.bind(this));
      $(document).on('click', '.mld-edit-search', this.editSearch.bind(this));
      $(document).on('click', '.mld-delete-search', this.deleteSearch.bind(this));
      $(document).on('click', '.mld-toggle-search', this.toggleSearchStatus.bind(this));

      // Filter builder
      $(document).on('click', '.mld-add-filter', this.addFilterRow.bind(this));
      $(document).on('click', '.mld-filter-remove', this.removeFilterRow.bind(this));
      $(document).on('change', '.mld-filter-field', this.updateFilterOperators.bind(this));

      // Analytics
      $(document).on('change', '.mld-chart-period', this.updateChart.bind(this));
      $(document).on('click', '.mld-export-data', this.exportData.bind(this));

      // Lead management
      $(document).on('click', '.mld-lead-action', this.handleLeadAction.bind(this));
      $(document).on('change', '.mld-lead-filter', this.filterLeads.bind(this));

      // Notifications
      $(document).on(
        'change',
        '.mld-channel-toggle input',
        this.toggleNotificationChannel.bind(this)
      );
      $(document).on('click', '.mld-test-notification', this.sendTestNotification.bind(this));

      // Real-time updates
      if (this.currentPage === 'analytics' || this.currentPage === 'leads') {
        this.startRealtimeUpdates();
      }
    },

    // Load page content based on current page
    loadPageContent() {
      switch (this.currentPage) {
        case 'saved_searches':
          this.loadSavedSearches();
          break;
        case 'analytics':
          this.loadAnalyticsDashboard();
          break;
        case 'leads':
          this.loadLeadsDashboard();
          break;
        case 'agents':
          this.loadAgentManagement();
          break;
        case 'reports':
          this.loadReportBuilder();
          break;
        case 'advanced_filters':
          this.loadFilterSettings();
          break;
        case 'notifications':
          this.loadNotificationCenter();
          break;
      }
    },

    // Load saved searches
    loadSavedSearches() {
      this.showLoading();

      $.ajax({
        url: mldSavedSearchV2.ajaxUrl,
        type: 'POST',
        data: {
          action: 'mld_get_saved_searches',
          nonce: mldSavedSearchV2.ajaxNonce,
        },
        success: function (response) {
          if (response.success) {
            this.renderSavedSearches(response.data);
          } else {
            this.showError(response.data.message);
          }
        }.bind(this),
        complete: function () {
          this.hideLoading();
        }.bind(this),
      });
    },

    // Render saved searches list
    renderSavedSearches(searches) {
      const container = $('#mld-saved-searches-list');

      if (!searches || searches.length === 0) {
        container.html(
          this.getEmptyState(
            'No saved searches found',
            'Create your first saved search to get started.',
            'Create Search'
          )
        );
        return;
      }

      let html = '<ul class="mld-search-list">';

      searches.forEach(
        function (search) {
          html += this.renderSearchItem(search);
        }.bind(this)
      );

      html += '</ul>';
      container.html(html);
    },

    // Render individual search item
    renderSearchItem(search) {
      const statusClass = search.status === 'active' ? 'active' : 'paused';
      const statusText = search.status === 'active' ? 'Active' : 'Paused';

      return `
                <li class="mld-search-item" data-search-id="${search.id}">
                    <div class="mld-search-header">
                        <h3 class="mld-search-title">${search.name}</h3>
                        <span class="mld-search-status ${statusClass}">${statusText}</span>
                    </div>
                    <div class="mld-search-meta">
                        <span>Created: ${this.formatDate(search.created_at)}</span>
                        <span>Matches: ${search.match_count || 0}</span>
                        <span>Notifications: ${search.notification_count || 0}</span>
                    </div>
                    <div class="mld-search-actions">
                        <button class="mld-edit-search">Edit</button>
                        <button class="mld-toggle-search">${search.status === 'active' ? 'Pause' : 'Activate'}</button>
                        <button class="mld-preview-search">Preview</button>
                        <button class="mld-delete-search">Delete</button>
                    </div>
                </li>
            `;
    },

    // Load analytics dashboard
    loadAnalyticsDashboard() {
      this.showLoading();

      $.ajax({
        url: mldSavedSearchV2.ajaxUrl,
        type: 'POST',
        data: {
          action: 'mld_get_analytics_data',
          nonce: mldSavedSearchV2.ajaxNonce,
          period: '7days',
        },
        success: function (response) {
          if (response.success) {
            this.renderAnalyticsDashboard(response.data);
          } else {
            this.showError(response.data.message);
          }
        }.bind(this),
        complete: function () {
          this.hideLoading();
        }.bind(this),
      });
    },

    // Render analytics dashboard
    renderAnalyticsDashboard(data) {
      // Render stats cards
      this.renderStatsCards(data.stats);

      // Initialize charts
      if (typeof Chart !== 'undefined') {
        this.initializeCharts(data.charts);
      }

      // Render recent activity
      this.renderRecentActivity(data.activity);
    },

    // Render stats cards
    renderStatsCards(stats) {
      const container = $('#mld-stats-cards');
      let html = '';

      Object.keys(stats).forEach(function (key) {
        const stat = stats[key];
        const changeClass = stat.change >= 0 ? 'positive' : 'negative';
        const changeIcon = stat.change >= 0 ? '↑' : '↓';

        html += `
                    <div class="mld-dashboard-card">
                        <h3>${stat.label}</h3>
                        <div class="stat-value">${stat.value}</div>
                        <div class="stat-change ${changeClass}">
                            ${changeIcon} ${Math.abs(stat.change)}% from last period
                        </div>
                    </div>
                `;
      });

      container.html(html);
    },

    // Initialize charts
    initializeCharts(chartsData) {
      // Engagement chart
      if (chartsData.engagement) {
        this.createChart('mld-engagement-chart', 'line', chartsData.engagement);
      }

      // Conversion chart
      if (chartsData.conversion) {
        this.createChart('mld-conversion-chart', 'bar', chartsData.conversion);
      }

      // Lead source chart
      if (chartsData.leadSource) {
        this.createChart('mld-lead-source-chart', 'doughnut', chartsData.leadSource);
      }
    },

    // Create a chart
    createChart(canvasId, type, data) {
      const canvas = document.getElementById(canvasId);
      if (!canvas) return;

      const ctx = canvas.getContext('2d');
      new Chart(ctx, {
        type,
        data,
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

    // Load leads dashboard
    loadLeadsDashboard() {
      this.showLoading();

      $.ajax({
        url: mldSavedSearchV2.ajaxUrl,
        type: 'POST',
        data: {
          action: 'mld_get_lead_data',
          nonce: mldSavedSearchV2.ajaxNonce,
        },
        success: function (response) {
          if (response.success) {
            this.renderLeadsDashboard(response.data);
          } else {
            this.showError(response.data.message);
          }
        }.bind(this),
        complete: function () {
          this.hideLoading();
        }.bind(this),
      });
    },

    // Render leads dashboard
    renderLeadsDashboard(data) {
      const container = $('#mld-leads-table-container');

      if (!data.leads || data.leads.length === 0) {
        container.html(
          this.getEmptyState(
            'No leads found',
            'Leads will appear here as users interact with saved searches.'
          )
        );
        return;
      }

      let html = `
                <table class="mld-leads-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Score</th>
                            <th>Searches</th>
                            <th>Last Activity</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
            `;

      data.leads.forEach(
        function (lead) {
          const scoreClass = this.getLeadScoreClass(lead.score);
          html += `
                    <tr data-lead-id="${lead.id}">
                        <td>${lead.name}</td>
                        <td>${lead.email}</td>
                        <td><span class="mld-lead-score ${scoreClass}">${lead.score}</span></td>
                        <td>${lead.search_count}</td>
                        <td>${this.formatDate(lead.last_activity)}</td>
                        <td>
                            <button class="mld-lead-action" data-action="view">View</button>
                            <button class="mld-lead-action" data-action="contact">Contact</button>
                        </td>
                    </tr>
                `;
        }.bind(this)
      );

      html += '</tbody></table>';
      container.html(html);
    },

    // Get lead score class
    getLeadScoreClass(score) {
      if (score >= 80) return 'hot';
      if (score >= 50) return 'warm';
      return 'cold';
    },

    // Handle lead action
    handleLeadAction(e) {
      e.preventDefault();
      const $button = $(e.currentTarget);
      const action = $button.data('action');
      const leadId = $button.closest('tr').data('lead-id');

      switch (action) {
        case 'view':
          this.viewLeadDetails(leadId);
          break;
        case 'contact':
          this.contactLead(leadId);
          break;
      }
    },

    // Send test notification
    sendTestNotification(e) {
      e.preventDefault();
      const $button = $(e.currentTarget);
      const channel = $button.data('channel');

      $button.prop('disabled', true).text('Sending...');

      $.ajax({
        url: mldSavedSearchV2.ajaxUrl,
        type: 'POST',
        data: {
          action: 'mld_send_test_notification',
          nonce: mldSavedSearchV2.ajaxNonce,
          channel,
        },
        success: function (response) {
          if (response.success) {
            this.showSuccess('Test notification sent successfully!');
          } else {
            this.showError(response.data.message);
          }
        }.bind(this),
        complete() {
          $button.prop('disabled', false).text('Send Test');
        },
      });
    },

    // Start real-time updates
    startRealtimeUpdates() {
      // Update every 30 seconds
      this.realtimeInterval = setInterval(
        function () {
          this.loadPageContent();
        }.bind(this),
        30000
      );
    },

    // Stop real-time updates
    stopRealtimeUpdates() {
      if (this.realtimeInterval) {
        clearInterval(this.realtimeInterval);
      }
    },

    // Utility functions
    formatDate(dateString) {
      const date = new Date(dateString);
      return date.toLocaleDateString() + ' ' + date.toLocaleTimeString();
    },

    showLoading() {
      $('#mld-loading-overlay').show();
    },

    hideLoading() {
      $('#mld-loading-overlay').hide();
    },

    showSuccess(message) {
      this.showNotice(message, 'success');
    },

    showError(message) {
      this.showNotice(message, 'error');
    },

    showNotice(message, type) {
      const $notice = $(
        '<div class="notice notice-' + type + ' is-dismissible"><p>' + message + '</p></div>'
      );
      $('.wrap > h1').after($notice);

      setTimeout(function () {
        $notice.fadeOut(function () {
          $(this).remove();
        });
      }, 5000);
    },

    getEmptyState(title, message, actionText) {
      let html = `
                <div class="mld-empty-state">
                    <div class="mld-empty-state-icon">📭</div>
                    <h3>${title}</h3>
                    <p class="mld-empty-state-message">${message}</p>
            `;

      if (actionText) {
        html += `<button class="mld-empty-state-action">${actionText}</button>`;
      }

      html += '</div>';
      return html;
    },
  };

  // Initialize when document is ready
  $(document).ready(function () {
    if (
      $(
        '#mld-saved-searches-app, #mld-analytics-dashboard, #mld-lead-management, #mld-agent-management, #mld-report-builder, #mld-advanced-filters, #mld-notification-center'
      ).length > 0
    ) {
      MLDSavedSearchV2.init();
    }
  });
})(jQuery);
