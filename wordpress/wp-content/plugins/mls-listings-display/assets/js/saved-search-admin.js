/**
 * MLS Saved Search Admin JavaScript
 * @param $
 */
(function ($) {
  'use strict';

  // Check if already initialized
  if (window.MLD_SavedSearchAdmin_Initialized) {
    MLDLogger.warning('MLD_SavedSearchAdmin already initialized. Skipping duplicate initialization.');
    return;
  }

  const MLD_SavedSearchAdmin = {
    currentPage: 1,
    perPage: 20,
    sortBy: 'created_at',
    sortOrder: 'DESC',
    filters: {},
    selectedSearches: [],

    init() {
      // Prevent double initialization
      if (window.MLD_SavedSearchAdmin_Initialized) {
        MLDLogger.warning('MLD_SavedSearchAdmin.init() called twice. Skipping.');
        return;
      }
      window.MLD_SavedSearchAdmin_Initialized = true;

      MLDLogger.debug('MLD_SavedSearchAdmin initializing...');
      this.bindEvents();
      this.loadSearches();
      this.loadStatistics();
      this.loadRecentAlerts();
    },

    alertsCurrentPage: 1,
    alertsPerPage: 10,

    bindEvents() {
      // Filters
      $('#apply-filters').on('click', () => this.applyFilters());
      $('#clear-filters').on('click', () => this.clearFilters());
      $('#search-input').on('keypress', (e) => {
        if (e.which === 13) this.applyFilters();
      });

      // Bulk actions
      $('#select-all').on('change', (e) => this.toggleSelectAll(e));
      $('#do-bulk-action').on('click', () => this.executeBulkAction());

      // Table sorting
      $(document).on('click', '.sortable', (e) => this.handleSort(e));

      // Row actions
      $(document).on('click', '.toggle-status', (e) => this.toggleStatus(e));
      $(document).on('click', '.view-details', (e) => this.viewDetails(e));
      $(document).on('click', '.test-notification', (e) => this.sendTestNotification(e));
      $(document).on('click', '.delete-search', (e) => this.deleteSearch(e));
      $(document).on('click', '.view-search-url', (e) => this.viewSearchUrl(e));

      // Notification toggle
      $(document).on('change', '.notifications-toggle', (e) => this.toggleNotifications(e));

      // Checkboxes
      $(document).on('change', '.search-checkbox', () => this.updateSelectedSearches());

      // Modal
      $('.mld-modal-close').on('click', () => this.closeModal());
      $(window).on('click', (e) => {
        if ($(e.target).hasClass('mld-modal')) {
          this.closeModal();
        }
      });
    },

    loadSearches() {
      const data = {
        action: 'mld_admin_get_searches',
        nonce: mldSavedSearchAdmin.nonce,
        page: this.currentPage,
        per_page: this.perPage,
        sort_by: this.sortBy,
        sort_order: this.sortOrder,
        ...this.filters,
      };

      $('#searches-tbody').html($('#loading-row-template').html());

      $.post(mldSavedSearchAdmin.ajaxUrl, data, (response) => {
        if (!response) {
          MLDLogger.error('MLD: Response is null or undefined');
          $('#searches-tbody').html('<tr><td colspan="11" class="error">No response from server</td></tr>');
          return;
        }

        if (response.success) {
          this.renderSearches(response.data);
          this.renderPagination(response.data);
          $('#mld-search-count').text(response.data.total);
        } else {
          MLDLogger.error('MLD: AJAX error response:', response.data);
          this.showError(response.data || mldSavedSearchAdmin.strings.error);
        }
      }).fail((jqXHR, textStatus, errorThrown) => {
        MLDLogger.error('MLD: AJAX request failed:', textStatus, errorThrown);
        $('#searches-tbody').html('<tr><td colspan="11" class="error">AJAX request failed: ' + textStatus + '</td></tr>');
      });
    },

    loadStatistics() {
      const data = {
        action: 'mld_admin_get_dashboard_stats',
        nonce: mldSavedSearchAdmin.nonce
      };

      $.post(mldSavedSearchAdmin.ajaxUrl, data, (response) => {
        if (response.success) {
          $('#stat-active').text(response.data.active);
          $('#stat-notifications').text(response.data.notifications);
          $('#stat-users').text(response.data.users);
          $('#stat-alerts-today').text(response.data.alerts_today || 0);
        }
      });
    },

    loadRecentAlerts() {
      const data = {
        action: 'mld_admin_get_recent_alerts',
        nonce: mldSavedSearchAdmin.nonce,
        page: this.alertsCurrentPage,
        per_page: this.alertsPerPage
      };

      const tbody = $('#recent-alerts-tbody');
      tbody.html('<tr><td colspan="6" class="loading-message">Loading...</td></tr>');

      $.post(mldSavedSearchAdmin.ajaxUrl, data, (response) => {
        if (response.success) {
          this.renderRecentAlerts(response.data);
        } else {
          tbody.html('<tr><td colspan="6" class="error">Failed to load alerts</td></tr>');
        }
      }).fail(() => {
        tbody.html('<tr><td colspan="6" class="error">Failed to load alerts</td></tr>');
      });
    },

    renderRecentAlerts(data) {
      const tbody = $('#recent-alerts-tbody');
      tbody.empty();

      if (!data.alerts || data.alerts.length === 0) {
        tbody.html('<tr><td colspan="6" class="no-results">No alert notifications yet</td></tr>');
        return;
      }

      data.alerts.forEach((alert) => {
        const row = `
          <tr>
            <td>${this.escapeHtml(alert.notified_at_formatted || '')}</td>
            <td>
              <div>${this.escapeHtml(alert.display_name || 'Unknown')}</div>
              <small class="user-email">${this.escapeHtml(alert.user_email || '')}</small>
            </td>
            <td>${this.escapeHtml(alert.search_name || 'Deleted Search')}</td>
            <td>${alert.notification_type_display || alert.notification_type}</td>
            <td><code>${this.escapeHtml(alert.mls_number || '')}</code></td>
            <td><span class="frequency-badge frequency-${alert.notification_frequency}">${alert.frequency_display || alert.notification_frequency}</span></td>
          </tr>
        `;
        tbody.append(row);
      });

      // Render pagination for alerts
      this.renderAlertsPagination(data);
    },

    renderAlertsPagination(data) {
      const container = $('#alerts-pagination');
      container.empty();

      if (data.pages <= 1) return;

      let html = '<span class="displaying-num">' + data.total + ' alerts</span>';
      html += '<span class="pagination-links">';

      if (this.alertsCurrentPage > 1) {
        html += `<a class="prev-alerts-page" href="#" data-page="${this.alertsCurrentPage - 1}">‹</a>`;
      } else {
        html += '<span class="prev-alerts-page disabled">‹</span>';
      }

      html += ` Page ${this.alertsCurrentPage} of ${data.pages} `;

      if (this.alertsCurrentPage < data.pages) {
        html += `<a class="next-alerts-page" href="#" data-page="${this.alertsCurrentPage + 1}">›</a>`;
      } else {
        html += '<span class="next-alerts-page disabled">›</span>';
      }

      html += '</span>';
      container.html(html);

      // Bind pagination events
      container.find('a').on('click', (e) => {
        e.preventDefault();
        this.alertsCurrentPage = parseInt($(e.target).data('page'));
        this.loadRecentAlerts();
      });
    },

    renderSearches(data) {
      const tbody = $('#searches-tbody');
      tbody.empty();

      // Defensive check for null/undefined searches
      if (!data || !data.searches || !Array.isArray(data.searches)) {
        MLDLogger.error('Invalid response data:', data);
        tbody.html('<tr><td colspan="11" class="error">Error loading searches. Please refresh the page.</td></tr>');
        return;
      }

      if (data.searches.length === 0) {
        tbody.html($('#no-results-template').html());
        return;
      }

      const template = $('#search-row-template').html();

      // Track rendered IDs to prevent duplicates
      const renderedIds = new Set();

      data.searches.forEach((search) => {
        // Skip if already rendered
        if (renderedIds.has(search.id)) {
          MLDLogger.warning('Skipping duplicate search ID:', search.id);
          return;
        }
        renderedIds.add(search.id);
        // Prepare filter summary
        const filterSummary = [];
        const filters = search.filters_decoded || {};

        if (filters.city) filterSummary.push(`City: ${filters.city}`);
        if (filters.min_price || filters.max_price) {
          filterSummary.push(`Price: $${filters.min_price || 0} - $${filters.max_price || 'Any'}`);
        }
        if (filters.beds) filterSummary.push(`Beds: ${filters.beds}+`);
        if (filters.baths) filterSummary.push(`Baths: ${filters.baths}+`);
        if (search.polygon_shapes_decoded && search.polygon_shapes_decoded.length > 0) {
          filterSummary.push(`${search.polygon_shapes_decoded.length} polygon(s)`);
        }

        search.filter_summary = filterSummary.join(', ') || 'No filters';

        // Clone the template for each row
        let html = template;

        // Handle conditional first
        html = html.replace(
          /{{#if is_active}}checked{{\/if}}/g,
          search.is_active == 1 ? 'checked' : ''
        );

        // Handle notifications_enabled conditional
        // Check if notification_frequency is set (not null/empty)
        const notificationsEnabled = search.notification_frequency && search.notification_frequency !== '' && search.notification_frequency !== null;
        html = html.replace(
          /{{#if notifications_enabled}}checked{{\/if}}/g,
          notificationsEnabled ? 'checked' : ''
        );
        html = html.replace(
          /{{#if notifications_enabled}}On{{else}}Off{{\/if}}/g,
          notificationsEnabled ? 'On' : 'Off'
        );

        // Create a unique replacement map to avoid any double replacements
        const uniqueId = 'TEMP_' + Date.now() + '_';
        const tempReplacements = {};
        const finalReplacements = {};

        // First pass: Replace with unique temporary placeholders
        tempReplacements[`{{id}}`] = uniqueId + 'ID';
        tempReplacements[`{{name}}`] = uniqueId + 'NAME';
        tempReplacements[`{{display_name}}`] = uniqueId + 'DISPLAY_NAME';
        tempReplacements[`{{user_email}}`] = uniqueId + 'USER_EMAIL';
        tempReplacements[`{{filter_summary}}`] = uniqueId + 'FILTER_SUMMARY';
        tempReplacements[`{{notification_frequency}}`] = uniqueId + 'NOTIFICATION_FREQUENCY';
        tempReplacements[`{{notification_frequency_display}}`] = uniqueId + 'NOTIFICATION_FREQUENCY_DISPLAY';
        tempReplacements[`{{last_notified_formatted}}`] = uniqueId + 'LAST_NOTIFIED';
        tempReplacements[`{{notifications_sent}}`] = uniqueId + 'NOTIFICATIONS_SENT';
        tempReplacements[`{{created_at_formatted}}`] = uniqueId + 'CREATED_AT';
        tempReplacements[`{{search_url}}`] = uniqueId + 'SEARCH_URL';

        // Replace with temporary placeholders
        Object.keys(tempReplacements).forEach((placeholder) => {
          html = html.split(placeholder).join(tempReplacements[placeholder]);
        });

        // Second pass: Replace temporary placeholders with actual values
        finalReplacements[uniqueId + 'ID'] = search.id || '';
        finalReplacements[uniqueId + 'NAME'] = this.escapeHtml(search.name || '');
        finalReplacements[uniqueId + 'DISPLAY_NAME'] = this.escapeHtml(search.display_name || '');
        finalReplacements[uniqueId + 'USER_EMAIL'] = this.escapeHtml(search.user_email || '');
        finalReplacements[uniqueId + 'FILTER_SUMMARY'] = this.escapeHtml(
          search.filter_summary || ''
        );
        finalReplacements[uniqueId + 'NOTIFICATION_FREQUENCY'] = this.escapeHtml(
          search.notification_frequency || ''
        );
        finalReplacements[uniqueId + 'NOTIFICATION_FREQUENCY_DISPLAY'] = this.formatFrequency(
          search.notification_frequency
        );
        finalReplacements[uniqueId + 'LAST_NOTIFIED'] = this.escapeHtml(
          search.last_notified_formatted || ''
        );
        finalReplacements[uniqueId + 'NOTIFICATIONS_SENT'] = search.notifications_sent || '0';
        finalReplacements[uniqueId + 'CREATED_AT'] = this.escapeHtml(
          search.created_at_formatted || ''
        );
        finalReplacements[uniqueId + 'SEARCH_URL'] = this.escapeHtml(search.search_url || '#');

        // Replace temporary placeholders with final values
        Object.keys(finalReplacements).forEach((tempPlaceholder) => {
          html = html.split(tempPlaceholder).join(finalReplacements[tempPlaceholder]);
        });

        tbody.append(html);
      });

      // Update statistics
      this.updateStatistics(data);
    },

    escapeHtml(text) {
      return MLD_Utils.escapeHtml(text);
    },

    formatFrequency(frequency) {
      const frequencies = {
        'instant': '⚡ Instant',
        'fifteen_min': '⏱️ 15 min',
        'hourly': '⏰ Hourly',
        'daily': '📅 Daily',
        'weekly': '📆 Weekly',
        'never': '🔕 Never'
      };
      return frequencies[frequency] || frequency || 'Not Set';
    },

    updateStatistics(data) {
      $('#stat-total').text(data.total);

      // Calculate active searches
      const activeCount = data.searches.filter((s) => s.is_active == 1).length;
      const totalNotifications = data.searches.reduce(
        (sum, s) => sum + parseInt(s.notifications_sent || 0),
        0
      );
      const uniqueUsers = [...new Set(data.searches.map((s) => s.user_id))].length;

      $('#stat-active').text(activeCount);
      $('#stat-notifications').text(totalNotifications);
      $('#stat-users').text(uniqueUsers);
    },

    renderPagination(data) {
      const container = $('#pagination-container');
      container.empty();

      if (data.pages <= 1) return;

      let html = '<span class="displaying-num">' + data.total + ' items</span>';
      html += '<span class="pagination-links">';

      // Previous
      if (this.currentPage > 1) {
        html += `<a class="prev-page" href="#" data-page="${this.currentPage - 1}">‹</a>`;
      } else {
        html += '<span class="prev-page disabled">‹</span>';
      }

      // Page numbers
      for (let i = 1; i <= data.pages; i++) {
        if (i === this.currentPage) {
          html += `<span class="current">${i}</span>`;
        } else if (
          i === 1 ||
          i === data.pages ||
          (i >= this.currentPage - 2 && i <= this.currentPage + 2)
        ) {
          html += `<a href="#" data-page="${i}">${i}</a>`;
        } else if (i === this.currentPage - 3 || i === this.currentPage + 3) {
          html += '<span>...</span>';
        }
      }

      // Next
      if (this.currentPage < data.pages) {
        html += `<a class="next-page" href="#" data-page="${this.currentPage + 1}">›</a>`;
      } else {
        html += '<span class="next-page disabled">›</span>';
      }

      html += '</span>';
      container.html(html);

      // Bind pagination events
      container.find('a').on('click', (e) => {
        e.preventDefault();
        this.currentPage = parseInt($(e.target).data('page'));
        this.loadSearches();
      });
    },

    applyFilters() {
      this.filters = {
        search: $('#search-input').val(),
        status: $('#status-filter').val(),
        frequency: $('#frequency-filter').val(),
      };
      this.currentPage = 1;
      this.loadSearches();
    },

    clearFilters() {
      $('#search-input').val('');
      $('#status-filter').val('');
      $('#frequency-filter').val('');
      this.filters = {};
      this.currentPage = 1;
      this.loadSearches();
    },

    handleSort(e) {
      e.preventDefault();
      const $th = $(e.currentTarget);
      const field = $th.data('sort');

      if (this.sortBy === field) {
        this.sortOrder = this.sortOrder === 'ASC' ? 'DESC' : 'ASC';
      } else {
        this.sortBy = field;
        this.sortOrder = 'DESC';
      }

      // Update UI
      $('.sortable .sorting-indicator').removeClass('asc desc');
      $th.find('.sorting-indicator').addClass(this.sortOrder.toLowerCase());

      this.loadSearches();
    },

    toggleStatus(e) {
      const $input = $(e.currentTarget);
      const searchId = $input.data('id');

      $input.prop('disabled', true);

      $.post(
        mldSavedSearchAdmin.ajaxUrl,
        {
          action: 'mld_admin_toggle_search',
          nonce: mldSavedSearchAdmin.nonce,
          search_id: searchId,
        },
        (response) => {
          $input.prop('disabled', false);

          if (!response.success) {
            // Revert toggle
            $input.prop('checked', !$input.prop('checked'));
            this.showError(response.data || mldSavedSearchAdmin.strings.error);
          }
        }
      );
    },

    viewDetails(e) {
      e.preventDefault();
      const searchId = $(e.currentTarget).data('id');

      $('#modal-content').html('<p>Loading...</p>');
      $('#search-details-modal').show();

      $.post(
        mldSavedSearchAdmin.ajaxUrl,
        {
          action: 'mld_admin_get_search_details',
          nonce: mldSavedSearchAdmin.nonce,
          search_id: searchId,
        },
        (response) => {
          if (response.success) {
            this.renderSearchDetails(response.data);
          } else {
            $('#modal-content').html(
              '<p class="error">' + (response.data || mldSavedSearchAdmin.strings.error) + '</p>'
            );
          }
        }
      );
    },

    renderSearchDetails(search) {
      let html = `
                <div class="detail-section">
                    <h3>Basic Information</h3>
                    <div class="detail-grid">
                        <div class="detail-label">Name:</div>
                        <div>${search.name}</div>
                        <div class="detail-label">User:</div>
                        <div>${search.user_display_name} (${search.user_email})</div>
                        <div class="detail-label">Frequency:</div>
                        <div><span class="frequency-badge frequency-${search.notification_frequency}">${search.notification_frequency}</span></div>
                        <div class="detail-label">Status:</div>
                        <div>${search.is_active == 1 ? 'Active' : 'Inactive'}</div>
                        <div class="detail-label">Created:</div>
                        <div>${search.created_at}</div>
                        <div class="detail-label">Last Notified:</div>
                        <div>${search.last_notified_at || 'Never'}</div>
                    </div>
                </div>

                <div class="detail-section">
                    <h3>Search Filters</h3>
                    <div class="filters-display">${this.formatFiltersForDisplay(search.filters_decoded)}</div>
                </div>
            `;

      if (search.polygon_shapes_decoded && search.polygon_shapes_decoded.length > 0) {
        html += `
                    <div class="detail-section">
                        <h3>Polygon Shapes</h3>
                        <div class="polygon-display">${search.polygon_shapes_decoded.length} polygon(s) drawn on map</div>
                    </div>
                `;
      }

      if (search.recent_notifications && search.recent_notifications.length > 0) {
        html += `
                    <div class="detail-section">
                        <h3>Recent Notifications</h3>
                        <div class="notification-list">
                `;

        search.recent_notifications.forEach((notification) => {
          html += `
                        <div class="notification-item">
                            <strong>MLS #${notification.listing_id}</strong> - 
                            Notified: ${notification.notified_at}
                        </div>
                    `;
        });

        html += `
                        </div>
                    </div>
                `;
      }

      $('#modal-content').html(html);
    },

    sendTestNotification(e) {
      e.preventDefault();
      const searchId = $(e.currentTarget).data('id');

      if (!confirm('Send a test notification for this search?')) {
        return;
      }

      const $link = $(e.currentTarget);

      // Prevent duplicate clicks
      if ($link.prop('disabled') || $link.data('sending')) {
        return;
      }

      $link.text('Sending...').data('sending', true).prop('disabled', true);

      MLDLogger.debug('Sending test notification for search ID:', searchId);
      const ajaxPromise = $.post(mldSavedSearchAdmin.ajaxUrl, {
        action: 'mld_admin_test_notification',
        nonce: mldSavedSearchAdmin.nonce,
        search_id: searchId,
      });

      // Track if we've already shown the alert
      let alertShown = false;

      ajaxPromise.done((response) => {
        MLDLogger.debug('Test notification response received:', response);
        $link.text('Send Test').data('sending', false).prop('disabled', false);

        if (response.success) {
          MLDLogger.debug('About to show success alert, alertShown:', alertShown);
          if (!alertShown) {
            alertShown = true;
            alert(mldSavedSearchAdmin.strings.testSent);
            MLDLogger.debug('Success alert shown');
          } else {
            MLDLogger.warning('Alert already shown, skipping duplicate');
          }
        } else {
          this.showError(response.data || mldSavedSearchAdmin.strings.error);
        }
      });

      ajaxPromise.fail(() => {
        MLDLogger.debug('Test notification failed');
        $link.text('Send Test').data('sending', false).prop('disabled', false);
        this.showError('Network error. Please try again.');
      });
    },

    deleteSearch(e) {
      e.preventDefault();
      const searchId = $(e.currentTarget).data('id');

      if (!confirm(mldSavedSearchAdmin.strings.confirmDelete)) {
        return;
      }

      $.post(
        mldSavedSearchAdmin.ajaxUrl,
        {
          action: 'mld_admin_delete_search',
          nonce: mldSavedSearchAdmin.nonce,
          search_id: searchId,
        },
        (response) => {
          if (response.success) {
            this.loadSearches();
          } else {
            this.showError(response.data || mldSavedSearchAdmin.strings.error);
          }
        }
      );
    },

    viewSearchUrl(e) {
      const url = $(e.currentTarget).data('url');
      window.open(url, '_blank');
    },

    toggleNotifications(e) {
      const $toggle = $(e.currentTarget);
      const searchId = $toggle.data('id');
      const enabled = $toggle.prop('checked');
      const $statusSpan = $toggle.siblings('.notification-status');

      // Send AJAX request to update notification settings
      $.post(
        mldSavedSearchAdmin.ajaxUrl,
        {
          action: 'mld_toggle_search_notifications',
          nonce: mldSavedSearchAdmin.nonce,
          search_id: searchId,
          enabled: enabled ? 1 : 0
        },
        (response) => {
          if (response.success) {
            // Update the status text
            if ($statusSpan.length) {
              $statusSpan.text(enabled ? 'On' : 'Off');
            }
          } else {
            // Revert toggle on error
            $toggle.prop('checked', !enabled);
            if ($statusSpan.length) {
              $statusSpan.text(!enabled ? 'On' : 'Off');
            }
            this.showError(response.data || 'Failed to update notification settings');
          }
        }
      ).fail(() => {
        // Revert toggle on network error
        $toggle.prop('checked', !enabled);
        if ($statusSpan.length) {
          $statusSpan.text(!enabled ? 'On' : 'Off');
        }
        this.showError('Network error. Please try again.');
      });
    },

    toggleSelectAll(e) {
      const isChecked = $(e.currentTarget).prop('checked');
      $('.search-checkbox').prop('checked', isChecked);
      this.updateSelectedSearches();
    },

    updateSelectedSearches() {
      this.selectedSearches = [];
      $('.search-checkbox:checked').each((i, el) => {
        this.selectedSearches.push($(el).val());
      });
    },

    executeBulkAction() {
      const action = $('#bulk-action').val();

      if (!action) {
        alert('Please select a bulk action');
        return;
      }

      if (this.selectedSearches.length === 0) {
        alert(mldSavedSearchAdmin.strings.noSelection);
        return;
      }

      if (action === 'delete' && !confirm(mldSavedSearchAdmin.strings.confirmBulkDelete)) {
        return;
      }

      $.post(
        mldSavedSearchAdmin.ajaxUrl,
        {
          action: 'mld_admin_bulk_action',
          nonce: mldSavedSearchAdmin.nonce,
          action_type: action,
          search_ids: this.selectedSearches,
        },
        (response) => {
          if (response.success) {
            $('#bulk-action').val('');
            $('#select-all').prop('checked', false);
            this.selectedSearches = [];
            this.loadSearches();

            if (response.data && response.data.message) {
              alert(response.data.message);
            }
          } else {
            this.showError(response.data || mldSavedSearchAdmin.strings.error);
          }
        }
      );
    },

    formatFiltersForDisplay(filters) {
      if (!filters || Object.keys(filters).length === 0) {
        return '<em>No filters set</em>';
      }

      let html = '<table class="filter-details-table">';
      const filterLabels = {
        price_min: 'Min Price',
        price_max: 'Max Price',
        beds: 'Bedrooms',
        baths_min: 'Min Bathrooms',
        home_type: 'Home Types',
        status: 'Listing Status',
        sqft_min: 'Min Sqft',
        sqft_max: 'Max Sqft',
        year_built_min: 'Min Year Built',
        year_built_max: 'Max Year Built',
        lot_size_min: 'Min Lot Size',
        lot_size_max: 'Max Lot Size',
        garage_spaces_min: 'Min Garage',
        parking_total_min: 'Min Parking',
        PropertyType: 'Property Type',
        selected_cities: 'Selected Cities',
        selected_neighborhoods: 'Selected Neighborhoods',
        keyword_City: 'Cities (Search)',
        keyword_Neighborhood: 'Neighborhoods (Search)',
        keyword_Address: 'Address Search',
        keyword_ListingId: 'MLS Numbers',
        SpaYN: 'Has Spa',
        WaterfrontYN: 'Waterfront',
        ViewYN: 'Has View',
        PropertyAttachedYN: 'Property Attached',
        SeniorCommunityYN: 'Senior Community',
        structure_type: 'Structure Types',
        architectural_style: 'Architectural Styles'
      };

      // Sort filters for better display
      const sortedKeys = Object.keys(filters).sort((a, b) => {
        // Group related filters together
        const priority = {
          PropertyType: 1,
          selected_cities: 2,
          selected_neighborhoods: 3,
          keyword_City: 4,
          keyword_Neighborhood: 5,
          price_min: 10,
          price_max: 11,
          beds: 20,
          baths_min: 21,
          sqft_min: 30,
          sqft_max: 31,
          status: 40
        };

        return (priority[a] || 100) - (priority[b] || 100);
      });

      sortedKeys.forEach(key => {
        const value = filters[key];
        const label = filterLabels[key] || key;

        let displayValue = value;
        if (Array.isArray(value)) {
          displayValue = value.join(', ');
        } else if (typeof value === 'boolean') {
          displayValue = value ? 'Yes' : 'No';
        } else if (key.includes('price') && value) {
          // Format price with commas
          displayValue = '$' + parseInt(value).toLocaleString();
        } else if ((key.includes('sqft') || key.includes('lot_size')) && value) {
          // Format sqft/lot size with commas
          displayValue = parseInt(value).toLocaleString() + (key.includes('lot_size') ? ' sqft' : ' sqft');
        }

        // Only show non-empty values
        if (displayValue && displayValue !== 'false' && displayValue !== '0' && displayValue !== '') {
          html += `<tr>
                     <td class="filter-label">${label}:</td>
                     <td class="filter-value">${displayValue}</td>
                   </tr>`;
        }
      });

      html += '</table>';
      return html;
    },

    closeModal() {
      $('#search-details-modal').hide();
    },

    showError(message) {
      MLD_Utils.notification.error(message);
    },
  };

  // Initialize when ready
  $(document).ready(() => {
    MLD_SavedSearchAdmin.init();
  });
})(jQuery);
