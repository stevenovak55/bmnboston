/**
 * MLS Client Management Admin JavaScript
 * @param $
 */
(function ($) {
  'use strict';

  const MLD_ClientManagementAdmin = {
    currentPage: 1,
    perPage: 20,
    sortBy: 'display_name',
    sortOrder: 'ASC',
    filters: {},
    selectedClients: [],
    totalClients: 0,

    init() {
      this.bindEvents();
      this.loadClients();
    },

    bindEvents() {
      // Filters
      $('#apply-filters').on('click', () => this.applyFilters());
      $('#clear-filters').on('click', () => this.clearFilters());
      $('#search-input').on('keypress', (e) => {
        if (e.which === 13) this.applyFilters();
      });

      // Bulk actions
      $('#select-all').on('change', (e) => this.toggleSelectAll(e));
      $('#bulk-action').on('change', (e) => this.handleBulkActionChange(e));
      $('#do-bulk-action').on('click', () => this.executeBulkAction());

      // Table sorting
      $(document).on('click', '.sortable', (e) => this.handleSort(e));

      // Row actions
      $(document).on('click', '.view-details', (e) => this.viewClientDetails(e));
      $(document).on('click', '.assign-agent', (e) => this.openAssignModal(e));

      // Checkboxes
      $(document).on('change', '.client-checkbox', () => this.updateSelectedClients());

      // Modal
      $('.bme-modal-close, .cancel-btn').on('click', () => this.closeModals());
      $(window).on('click', (e) => {
        if ($(e.target).hasClass('bme-modal')) {
          this.closeModals();
        }
      });

      // Assign form
      $('#assign-agent-form').on('submit', (e) => this.handleAssignSubmit(e));

      // New client
      MLDLogger.debug(
        'Binding add-new-client click event, element found:',
        $('#add-new-client').length
      );
      $('#add-new-client').on('click', (e) => {
        MLDLogger.debug('Add new client clicked');
        this.openCreateModal(e);
      });
      $('#create-client-form').on('submit', (e) => this.handleCreateSubmit(e));
      $('#new-client-agent').on('change', (e) => this.toggleEmailTypeField(e));
    },

    loadClients() {
      const data = {
        action: 'mld_admin_get_clients',
        nonce: mldClientManagementAdmin.nonce,
        page: this.currentPage,
        per_page: this.perPage,
        sort_by: this.sortBy,
        sort_order: this.sortOrder,
        ...this.filters,
      };

      $('#clients-tbody').html(
        '<tr><td colspan="8" class="loading-message">' +
          mldClientManagementAdmin.strings.loading +
          '</td></tr>'
      );

      $.post(mldClientManagementAdmin.ajaxUrl, data, (response) => {
        if (response.success && response.data) {
          this.totalClients = response.data.total || 0;
          this.renderClients(response.data);
          this.renderPagination(response.data);
          this.updateStatistics(response.data);
        } else {
          MLDLogger.error('Load clients error:', response);
          $('#clients-tbody').html(
            '<tr><td colspan="8" class="error-message">Failed to load clients</td></tr>'
          );
          this.showError(response.data || mldClientManagementAdmin.strings.error);
        }
      }).fail((xhr) => {
        MLDLogger.error('AJAX error:', xhr);
        MLDLogger.error('Response text:', xhr.responseText);
        $('#clients-tbody').html(
          '<tr><td colspan="8" class="error-message">Failed to load clients</td></tr>'
        );
      });
    },

    renderClients(data) {
      const tbody = $('#clients-tbody');
      tbody.empty();

      // Check if data.clients exists and is an array
      if (!data || !data.clients || !Array.isArray(data.clients)) {
        MLDLogger.error('Invalid data structure:', data);
        tbody.html($('#no-results-template').html());
        return;
      }

      if (data.clients.length === 0) {
        tbody.html($('#no-results-template').html());
        return;
      }

      const template = $('#client-row-template').html();

      data.clients.forEach((client) => {
        // Format data
        client.registered_date = new Date(client.user_registered).toLocaleDateString();
        client.active_searches = client.active_searches || 0;
        client.total_searches = client.total_searches || 0;
        client.email_type =
          client.relationship_status === 'active' ? (client.agent_email ? 'cc' : 'none') : 'none';

        // Replace template
        let html = template;

        // Handle conditionals
        if (client.agent_name) {
          html = html.replace(/{{#if agent_name}}([\s\S]*?){{else}}[\s\S]*?{{\/if}}/g, '$1');
          html = html.replace(/{{#if agent_name}}([\s\S]*?){{\/if}}/g, '$1');
        } else {
          html = html.replace(/{{#if agent_name}}[\s\S]*?{{else}}([\s\S]*?){{\/if}}/g, '$1');
          html = html.replace(/{{#if agent_name}}[\s\S]*?{{\/if}}/g, '');
        }

        if (client.active_searches > 0) {
          html = html.replace(/{{#if active_searches}}([\s\S]*?){{\/if}}/g, '$1');
        } else {
          html = html.replace(/{{#if active_searches}}[\s\S]*?{{\/if}}/g, '');
        }

        // Replace variables
        const replacements = {
          '{{client_id}}': client.client_id,
          '{{display_name}}': this.escapeHtml(client.display_name),
          '{{user_email}}': this.escapeHtml(client.user_email),
          '{{active_searches}}': client.active_searches,
          '{{total_searches}}': client.total_searches,
          '{{agent_name}}': this.escapeHtml(client.agent_name || ''),
          '{{email_type}}': client.email_type,
          '{{registered_date}}': client.registered_date,
        };

        Object.keys(replacements).forEach((placeholder) => {
          html = html.split(placeholder).join(replacements[placeholder]);
        });

        tbody.append(html);
      });
    },

    updateStatistics(data) {
      if (!data || !data.clients) {
        return;
      }

      const totalClients = data.total || 0;
      let assigned = 0;
      let unassigned = 0;
      let totalSearches = 0;

      if (Array.isArray(data.clients)) {
        data.clients.forEach((client) => {
          if (client.agent_id) {
            assigned++;
          } else {
            unassigned++;
          }
          totalSearches += parseInt(client.active_searches) || 0;
        });
      }

      $('#stat-total-clients').text(totalClients);
      $('#stat-assigned').text(assigned);
      $('#stat-unassigned').text(unassigned);
      $('#stat-searches').text(totalSearches);
    },

    renderPagination(data) {
      const container = $('#pagination-container');
      container.empty();

      if (!data || !data.pages || data.pages <= 1) return;

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
        this.loadClients();
      });
    },

    applyFilters() {
      this.filters = {
        search: $('#search-input').val(),
        assigned: $('#assignment-filter').val(),
      };
      this.currentPage = 1;
      this.loadClients();
    },

    clearFilters() {
      $('#search-input').val('');
      $('#assignment-filter').val('all');
      this.filters = {};
      this.currentPage = 1;
      this.loadClients();
    },

    handleSort(e) {
      e.preventDefault();
      const $th = $(e.currentTarget);
      const field = $th.data('sort');

      if (this.sortBy === field) {
        this.sortOrder = this.sortOrder === 'ASC' ? 'DESC' : 'ASC';
      } else {
        this.sortBy = field;
        this.sortOrder = 'ASC';
      }

      // Update UI
      $('.sortable .sorting-indicator').removeClass('asc desc');
      $th.find('.sorting-indicator').addClass(this.sortOrder.toLowerCase());

      this.loadClients();
    },

    viewClientDetails(e) {
      e.preventDefault();
      const clientId = $(e.currentTarget).data('id');

      $('#client-details-content').html('<p>Loading...</p>');
      $('#client-details-modal').show();

      $.post(
        mldClientManagementAdmin.ajaxUrl,
        {
          action: 'mld_admin_get_client_details',
          nonce: mldClientManagementAdmin.nonce,
          client_id: clientId,
        },
        (response) => {
          if (response.success) {
            this.renderClientDetails(response.data);

            // Load client's searches
            this.loadClientSearches(clientId);
          } else {
            $('#client-details-content').html(
              '<p class="error">' +
                (response.data || mldClientManagementAdmin.strings.error) +
                '</p>'
            );
          }
        }
      );
    },

    renderClientDetails(client) {
      let html = `
                <div class="client-details-header">
                    <h3>${this.escapeHtml(client.display_name)}</h3>
                    <div class="client-details-meta">
                        <span><strong>Email:</strong> ${this.escapeHtml(client.user_email)}</span>
                        <span><strong>Registered:</strong> ${new Date(client.user_registered).toLocaleDateString()}</span>
                        <span><strong>Active Searches:</strong> ${client.active_searches}</span>
                        <span><strong>Total Searches:</strong> ${client.total_searches}</span>
                    </div>
                </div>
                
                <div class="assignments-section">
                    <h4>Agent Assignments</h4>
                    <div id="assignments-list">
            `;

      if (client.assignments && client.assignments.length > 0) {
        client.assignments.forEach((assignment) => {
          const isActive = assignment.relationship_status === 'active';
          html += `
                        <div class="assignment-card ${isActive ? 'active' : 'inactive'}">
                            <div class="assignment-status ${isActive ? 'active' : 'inactive'}">
                                ${isActive ? 'Active' : 'Inactive'}
                            </div>
                            <div class="assignment-info">
                                <div>
                                    <strong>Agent:</strong> ${this.escapeHtml(assignment.agent_name || 'Unknown')}
                                </div>
                                <div>
                                    <strong>Email:</strong> ${this.escapeHtml(assignment.agent_email || 'N/A')}
                                </div>
                                <div>
                                    <strong>Email Copy:</strong> 
                                    <span class="email-type-badge ${assignment.default_email_type || 'none'}">
                                        ${assignment.default_email_type || 'none'}
                                    </span>
                                </div>
                                <div>
                                    <strong>Assigned:</strong> ${new Date(assignment.assigned_date).toLocaleDateString()}
                                </div>
                            </div>
                            <div class="assignment-actions">
                                ${
                                  isActive
                                    ? `
                                    <button class="button button-small update-email-pref" 
                                            data-client="${client.client_id}" 
                                            data-agent="${assignment.agent_id}">
                                        Update Email Preference
                                    </button>
                                    <button class="button button-small unassign-agent" 
                                            data-client="${client.client_id}" 
                                            data-agent="${assignment.agent_id}">
                                        Unassign
                                    </button>
                                `
                                    : ''
                                }
                            </div>
                        </div>
                    `;
        });
      } else {
        html += '<p>No agent assignments found.</p>';
      }

      html += `
                    </div>
                    <button class="button button-primary assign-agent" data-id="${client.client_id}">
                        Assign New Agent
                    </button>
                </div>
                
                <div class="searches-section">
                    <h4>Saved Searches</h4>
                    <div id="client-searches-list">
                        <p>Loading searches...</p>
                    </div>
                </div>
            `;

      $('#client-details-content').html(html);

      // Bind detail events
      $('.unassign-agent').on('click', (e) => this.unassignAgent(e));
      $('.update-email-pref').on('click', (e) => this.updateEmailPreference(e));
    },

    loadClientSearches(clientId) {
      $.post(
        mldClientManagementAdmin.ajaxUrl,
        {
          action: 'mld_admin_get_client_searches',
          nonce: mldClientManagementAdmin.nonce,
          client_id: clientId,
        },
        (response) => {
          if (response.success) {
            this.renderClientSearches(response.data.searches);
          } else {
            $('#client-searches-list').html('<p class="error">Failed to load searches</p>');
          }
        }
      );
    },

    renderClientSearches(searches) {
      if (searches.length === 0) {
        $('#client-searches-list').html('<p>No saved searches found.</p>');
        return;
      }

      let html = '<div class="searches-list">';

      searches.forEach((search) => {
        const isActive = search.is_active == 1;
        html += `
                    <div class="search-item">
                        <div>
                            <div class="search-name">${this.escapeHtml(search.name)}</div>
                            <div class="search-filters">
                                ${this.getFilterSummary(search.filters_decoded)}
                            </div>
                        </div>
                        <div class="search-status">
                            <span class="search-frequency ${search.notification_frequency}">
                                ${search.notification_frequency}
                            </span>
                            <span class="${isActive ? 'search-active' : 'search-inactive'}">
                                ${isActive ? 'Active' : 'Inactive'}
                            </span>
                        </div>
                    </div>
                `;
      });

      html += '</div>';
      $('#client-searches-list').html(html);
    },

    getFilterSummary(filters) {
      if (!filters) return 'No filters';

      const summary = [];
      if (filters.city) summary.push(`City: ${filters.city}`);
      if (filters.min_price || filters.max_price) {
        summary.push(`Price: $${filters.min_price || 0} - $${filters.max_price || 'Any'}`);
      }
      if (filters.beds) summary.push(`Beds: ${filters.beds}+`);
      if (filters.baths) summary.push(`Baths: ${filters.baths}+`);

      return summary.join(', ') || 'No filters';
    },

    openAssignModal(e) {
      e.preventDefault();
      const clientId = $(e.currentTarget).data('id');

      $('#assign-client-id').val(clientId);
      $('#assign-agent-form')[0].reset();
      $('#assign-agent-modal').show();
    },

    handleAssignSubmit(e) {
      e.preventDefault();

      const data = {
        action: 'mld_admin_assign_agent',
        nonce: mldClientManagementAdmin.nonce,
        client_id: $('#assign-client-id').val(),
        agent_id: $('#assign-agent-id').val(),
        email_type: $('#assign-email-type').val(),
        notes: $('#assign-notes').val(),
      };

      const $submitBtn = $('#assign-agent-form button[type="submit"]');
      const originalText = $submitBtn.text();
      $submitBtn.text(mldClientManagementAdmin.strings.saving).prop('disabled', true);

      $.post(mldClientManagementAdmin.ajaxUrl, data, (response) => {
        $submitBtn.text(originalText).prop('disabled', false);

        if (response.success) {
          alert(mldClientManagementAdmin.strings.saved);
          this.closeModals();
          this.loadClients();
        } else {
          this.showError(response.data || mldClientManagementAdmin.strings.error);
        }
      });
    },

    unassignAgent(e) {
      if (!confirm(mldClientManagementAdmin.strings.confirmUnassign)) {
        return;
      }

      const $btn = $(e.currentTarget);
      const clientId = $btn.data('client');
      const agentId = $btn.data('agent');

      $.post(
        mldClientManagementAdmin.ajaxUrl,
        {
          action: 'mld_admin_unassign_agent',
          nonce: mldClientManagementAdmin.nonce,
          client_id: clientId,
          agent_id: agentId,
        },
        (response) => {
          if (response.success) {
            this.viewClientDetails({
              preventDefault: () => {},
              currentTarget: { dataset: { id: clientId } },
            });
          } else {
            this.showError(response.data || mldClientManagementAdmin.strings.error);
          }
        }
      );
    },

    updateEmailPreference(e) {
      const $btn = $(e.currentTarget);
      const clientId = $btn.data('client');
      const agentId = $btn.data('agent');

      const newType = prompt('Enter email preference (none, cc, or bcc):', 'none');
      if (!newType || !['none', 'cc', 'bcc'].includes(newType)) {
        return;
      }

      $.post(
        mldClientManagementAdmin.ajaxUrl,
        {
          action: 'mld_admin_update_email_prefs',
          nonce: mldClientManagementAdmin.nonce,
          client_id: clientId,
          agent_id: agentId,
          email_type: newType,
        },
        (response) => {
          if (response.success) {
            this.viewClientDetails({
              preventDefault: () => {},
              currentTarget: { dataset: { id: clientId } },
            });
          } else {
            this.showError(response.data || mldClientManagementAdmin.strings.error);
          }
        }
      );
    },

    handleBulkActionChange(e) {
      const action = $(e.target).val();

      if (action === 'assign') {
        $('#bulk-agent, #bulk-email-type').show();
      } else {
        $('#bulk-agent, #bulk-email-type').hide();
      }
    },

    toggleSelectAll(e) {
      const isChecked = $(e.currentTarget).prop('checked');
      $('.client-checkbox').prop('checked', isChecked);
      this.updateSelectedClients();
    },

    updateSelectedClients() {
      this.selectedClients = [];
      $('.client-checkbox:checked').each((i, el) => {
        this.selectedClients.push($(el).val());
      });
    },

    executeBulkAction() {
      const action = $('#bulk-action').val();

      if (!action) {
        alert('Please select a bulk action');
        return;
      }

      if (this.selectedClients.length === 0) {
        alert(mldClientManagementAdmin.strings.noSelection);
        return;
      }

      if (action === 'assign') {
        const agentId = $('#bulk-agent').val();
        if (!agentId) {
          alert(mldClientManagementAdmin.strings.selectAgent);
          return;
        }

        if (!confirm(mldClientManagementAdmin.strings.confirmBulkAssign)) {
          return;
        }

        $.post(
          mldClientManagementAdmin.ajaxUrl,
          {
            action: 'mld_admin_bulk_assign',
            nonce: mldClientManagementAdmin.nonce,
            client_ids: this.selectedClients,
            agent_id: agentId,
            email_type: $('#bulk-email-type').val(),
          },
          (response) => {
            if (response.success) {
              alert(response.data.message);
              $('#bulk-action').val('');
              $('#bulk-agent, #bulk-email-type').hide();
              $('#select-all').prop('checked', false);
              this.selectedClients = [];
              this.loadClients();
            } else {
              this.showError(response.data || mldClientManagementAdmin.strings.error);
            }
          }
        );
      }
    },

    closeModals() {
      $('.bme-modal').hide();
    },

    showError(message) {
      MLD_Utils.notification.error(message);
    },

    escapeHtml(text) {
      return MLD_Utils.escapeHtml(text);
    },

    openCreateModal(e) {
      MLDLogger.debug('openCreateModal called');
      e.preventDefault();
      $('#create-client-form')[0].reset();
      $('#new-client-email-type-row').hide();
      MLDLogger.debug('Modal element found:', $('#create-client-modal').length);
      $('#create-client-modal').show();
    },

    toggleEmailTypeField(e) {
      const agentId = $(e.target).val();
      if (agentId) {
        $('#new-client-email-type-row').show();
      } else {
        $('#new-client-email-type-row').hide();
      }
    },

    handleCreateSubmit(e) {
      e.preventDefault();

      const $form = $(e.target);
      const data = {
        action: 'mld_admin_create_client',
        nonce: mldClientManagementAdmin.nonce,
        first_name: $('#new-client-first-name').val(),
        last_name: $('#new-client-last-name').val(),
        email: $('#new-client-email').val(),
        phone: $('#new-client-phone').val(),
        agent_id: $('#new-client-agent').val(),
        email_type: $('#new-client-email-type').val(),
        send_notification: $('#new-client-send-notification').is(':checked') ? 1 : 0,
      };

      const $submitBtn = $form.find('button[type="submit"]');
      const originalText = $submitBtn.text();
      $submitBtn.text(mldClientManagementAdmin.strings.saving).prop('disabled', true);

      $.post(mldClientManagementAdmin.ajaxUrl, data, (response) => {
        $submitBtn.text(originalText).prop('disabled', false);

        if (response.success) {
          alert('Client created successfully!');
          this.closeModals();
          this.loadClients();
        } else {
          this.showError(response.data || mldClientManagementAdmin.strings.error);
        }
      }).fail(() => {
        $submitBtn.text(originalText).prop('disabled', false);
        this.showError(mldClientManagementAdmin.strings.error);
      });
    },
  };

  // Initialize when ready
  $(document).ready(() => {
    MLD_ClientManagementAdmin.init();
  });
})(jQuery);
