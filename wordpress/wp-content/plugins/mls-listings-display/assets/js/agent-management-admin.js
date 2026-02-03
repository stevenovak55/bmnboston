/**
 * MLS Agent Management Admin JavaScript
 * @param $
 */
(function ($) {
  'use strict';

  const MLD_AgentManagementAdmin = {
    agents: [],
    currentEditId: null,

    init() {
      this.bindEvents();
      this.loadAgents();
    },

    bindEvents() {
      // Filter change
      $('#status-filter').on('change', () => this.loadAgents());

      // Add new agent
      $('#add-new-agent, #add-first-agent').on('click', () => this.openAddModal());

      // Form submission
      $('#agent-form').on('submit', (e) => this.handleFormSubmit(e));

      // Modal controls
      $('.mld-modal-close, .cancel-btn').on('click', () => this.closeModals());

      // Photo upload
      $('#upload-photo-btn').on('click', () => this.openMediaUploader());
      $('#remove-photo-btn').on('click', () => this.removePhoto());

      // Agent actions (delegated)
      $(document).on('click', '.edit-agent', (e) => this.editAgent(e));
      $(document).on('click', '.view-details', (e) => this.viewAgentDetails(e));

      // Referral system actions (v6.52.0)
      $(document).on('click', '.set-default-agent', (e) => this.setDefaultAgent(e));
      $(document).on('click', '.copy-referral', (e) => this.copyReferralLink(e));

      // User selection change
      $('#agent-user-id').on('change', (e) => this.handleUserSelection(e));

      // Close modal on outside click
      $(window).on('click', (e) => {
        if ($(e.target).hasClass('mld-modal')) {
          this.closeModals();
        }
      });
    },

    loadAgents() {
      const status = $('#status-filter').val();

      $('#agents-grid').html(
        '<div class="loading-message">' + mldAgentManagementAdmin.strings.loading + '</div>'
      );

      $.post(
        mldAgentManagementAdmin.ajaxUrl,
        {
          action: 'mld_admin_get_agents',
          nonce: mldAgentManagementAdmin.nonce,
          status,
        },
        (response) => {
          if (response.success) {
            this.agents = response.data.agents;
            this.renderAgents();
          } else {
            this.showError(response.data || mldAgentManagementAdmin.strings.error);
          }
        }
      );
    },

    renderAgents() {
      const grid = $('#agents-grid');
      grid.empty();

      if (this.agents.length === 0) {
        grid.html($('#no-agents-template').html());
        return;
      }

      const template = $('#agent-card-template').html();

      this.agents.forEach((agent) => {
        // Generate initials
        const names = (agent.display_name || agent.wp_display_name || '').split(' ');
        agent.initials = names
          .map((n) => n.charAt(0).toUpperCase())
          .join('')
          .substr(0, 2);

        // Ensure we have display values
        agent.display_name = agent.display_name || agent.wp_display_name;
        agent.email = agent.email || agent.user_email;
        agent.is_active = parseInt(agent.is_active);

        // Replace template variables
        let html = template;

        // Handle conditionals
        if (agent.photo_url) {
          // Keep the content before {{else}}, remove the else block
          html = html.replace(/{{#if photo_url}}([\s\S]*?){{else}}[\s\S]*?{{\/if}}/g, '$1');
          // Handle simple {{#if}}...{{/if}} without else
          html = html.replace(/{{#if photo_url}}([\s\S]*?){{\/if}}/g, '$1');
        } else {
          // Keep the content after {{else}}, remove the if block
          html = html.replace(/{{#if photo_url}}[\s\S]*?{{else}}([\s\S]*?){{\/if}}/g, '$1');
          // Remove simple {{#if}}...{{/if}} without else
          html = html.replace(/{{#if photo_url}}[\s\S]*?{{\/if}}/g, '');
        }

        if (agent.office_name) {
          html = html.replace(/{{#if office_name}}([\s\S]*?){{\/if}}/g, '$1');
        } else {
          html = html.replace(/{{#if office_name}}[\s\S]*?{{\/if}}/g, '');
        }

        if (!agent.is_active) {
          html = html.replace(/{{#unless is_active}}([\s\S]*?){{\/unless}}/g, '$1');
        } else {
          html = html.replace(/{{#unless is_active}}[\s\S]*?{{\/unless}}/g, '');
        }

        // Handle is_default conditional (v6.52.0)
        if (agent.is_default) {
          html = html.replace(/{{#if is_default}}([\s\S]*?){{\/if}}/g, '$1');
          html = html.replace(/{{#unless is_default}}[\s\S]*?{{\/unless}}/g, '');
        } else {
          html = html.replace(/{{#if is_default}}[\s\S]*?{{\/if}}/g, '');
          html = html.replace(/{{#unless is_default}}([\s\S]*?){{\/unless}}/g, '$1');
        }

        // Handle referral_signups conditional (v6.52.0)
        if (agent.referral_signups && agent.referral_signups > 0) {
          html = html.replace(/{{#if referral_signups}}([\s\S]*?){{\/if}}/g, '$1');
        } else {
          html = html.replace(/{{#if referral_signups}}[\s\S]*?{{\/if}}/g, '');
        }

        // Handle referral_url conditional (v6.52.0)
        if (agent.referral_url) {
          html = html.replace(/{{#if referral_url}}([\s\S]*?){{\/if}}/g, '$1');
        } else {
          html = html.replace(/{{#if referral_url}}[\s\S]*?{{\/if}}/g, '');
        }

        // Replace all variables
        const replacements = {
          '{{user_id}}': agent.user_id,
          '{{photo_url}}': agent.photo_url || '',
          '{{display_name}}': this.escapeHtml(agent.display_name),
          '{{initials}}': this.escapeHtml(agent.initials),
          '{{email}}': this.escapeHtml(agent.email),
          '{{office_name}}': this.escapeHtml(agent.office_name || ''),
          '{{stats.active_clients}}': agent.stats?.active_clients || 0,
          '{{stats.active_searches}}': agent.stats?.active_searches || 0,
          '{{referral_signups}}': agent.referral_signups || 0,
          '{{referral_url}}': agent.referral_url || '',
          '{{referral_code}}': agent.referral_code || '',
        };

        Object.keys(replacements).forEach((placeholder) => {
          html = html.split(placeholder).join(replacements[placeholder]);
        });

        grid.append(html);
      });
    },

    openAddModal() {
      this.currentEditId = null;
      $('#modal-title').text('Add New Agent');
      $('#edit-agent-id').val('');
      $('#agent-form')[0].reset();
      $('#agent-is-active').prop('checked', true);
      $('#agent-user-id').prop('disabled', false);
      // Reset SNAB staff dropdown (v6.33.0)
      if ($('#agent-snab-staff').length) {
        $('#agent-snab-staff').val('');
      }
      this.updatePhotoPreview('');
      $('#agent-modal').show();
    },

    editAgent(e) {
      e.preventDefault();
      const agentId = $(e.currentTarget).data('id');

      $.post(
        mldAgentManagementAdmin.ajaxUrl,
        {
          action: 'mld_admin_get_agent_details',
          nonce: mldAgentManagementAdmin.nonce,
          agent_id: agentId,
        },
        (response) => {
          if (response.success) {
            this.populateEditForm(response.data);
          } else {
            this.showError(response.data || mldAgentManagementAdmin.strings.error);
          }
        }
      );
    },

    populateEditForm(agent) {
      this.currentEditId = agent.user_id;
      $('#modal-title').text('Edit Agent');
      $('#edit-agent-id').val(agent.user_id);

      // Populate form fields
      $('#agent-user-id').val(agent.user_id).prop('disabled', true);
      $('#agent-display-name').val(agent.display_name || '');
      $('#agent-email').val(agent.email || '');
      $('#agent-phone').val(agent.phone || '');
      $('#agent-office-name').val(agent.office_name || '');
      $('#agent-office-address').val(agent.office_address || '');
      $('#agent-bio').val(agent.bio || '');
      $('#agent-license').val(agent.license_number || '');
      $('#agent-specialties').val(agent.specialties || '');
      $('#agent-is-active').prop('checked', agent.is_active == 1);

      // Set SNAB staff dropdown (v6.33.0)
      if ($('#agent-snab-staff').length) {
        $('#agent-snab-staff').val(agent.snab_staff_id || '');
      }

      this.updatePhotoPreview(agent.photo_url || '');

      $('#agent-modal').show();
    },

    handleFormSubmit(e) {
      e.preventDefault();

      const formData = new FormData(e.target);
      const data = {
        action: 'mld_admin_save_agent',
        nonce: mldAgentManagementAdmin.nonce,
      };

      // Convert FormData to object
      for (const [key, value] of formData.entries()) {
        data[key] = value;
      }

      // Handle checkbox
      data.is_active = $('#agent-is-active').is(':checked') ? 1 : 0;

      // If editing, include the agent ID
      if (this.currentEditId) {
        data.user_id = this.currentEditId;
      }

      // Show saving state
      const $submitBtn = $('#agent-form button[type="submit"]');
      const originalText = $submitBtn.text();
      $submitBtn.text(mldAgentManagementAdmin.strings.saving).prop('disabled', true);

      $.post(mldAgentManagementAdmin.ajaxUrl, data, (response) => {
        $submitBtn.text(originalText).prop('disabled', false);

        if (response.success) {
          alert(mldAgentManagementAdmin.strings.saved);
          this.closeModals();
          this.loadAgents();
        } else {
          this.showError(response.data || mldAgentManagementAdmin.strings.error);
        }
      });
    },

    handleUserSelection(e) {
      const userId = $(e.target).val();
      if (!userId) return;

      // Find the selected user
      const $option = $(e.target).find('option:selected');
      const userName = $option.text().split(' (')[0];
      const userEmail = $option.text().match(/\((.*?)\)/)[1];

      // Auto-fill if fields are empty
      if (!$('#agent-display-name').val()) {
        $('#agent-display-name').val(userName);
      }

      if (!$('#agent-email').val()) {
        $('#agent-email').val(userEmail);
      }
    },

    viewAgentDetails(e) {
      e.preventDefault();
      const agentId = $(e.currentTarget).data('id');

      $('#agent-details-content').html('<p>Loading...</p>');
      $('#agent-details-modal').show();

      $.post(
        mldAgentManagementAdmin.ajaxUrl,
        {
          action: 'mld_admin_get_agent_details',
          nonce: mldAgentManagementAdmin.nonce,
          agent_id: agentId,
        },
        (response) => {
          if (response.success) {
            this.renderAgentDetails(response.data);

            // Load agent's clients
            this.loadAgentClients(agentId);
          } else {
            $('#agent-details-content').html(
              '<p class="error">' +
                (response.data || mldAgentManagementAdmin.strings.error) +
                '</p>'
            );
          }
        }
      );
    },

    renderAgentDetails(agent) {
      const initials = (agent.display_name || agent.wp_display_name || '')
        .split(' ')
        .map((n) => n.charAt(0).toUpperCase())
        .join('')
        .substr(0, 2);

      // Store agent ID for later use
      $('#agent-details-modal').data('agent-id', agent.user_id);

      const html = `
                <div class="agent-details-header">
                    <div class="agent-details-photo">
                        ${
                          agent.photo_url
                            ? `<img src="${agent.photo_url}" alt="${agent.display_name}">`
                            : `<div class="agent-avatar">${initials}</div>`
                        }
                    </div>
                    <div class="agent-details-info">
                        <h2>${agent.display_name || agent.wp_display_name}</h2>
                        <div class="agent-details-meta">
                            <p><strong>Email:</strong> ${agent.email || agent.user_email}</p>
                            ${agent.phone ? `<p><strong>Phone:</strong> ${agent.phone}</p>` : ''}
                            ${agent.office_name ? `<p><strong>Office:</strong> ${agent.office_name}</p>` : ''}
                            ${agent.license_number ? `<p><strong>License:</strong> ${agent.license_number}</p>` : ''}
                        </div>
                        <div class="agent-details-actions">
                            <button type="button" class="button button-primary edit-agent" data-id="${agent.user_id}">
                                Edit Agent
                            </button>
                            <button type="button" class="button delete-agent" data-id="${agent.user_id}">
                                Delete Agent
                            </button>
                        </div>
                    </div>
                </div>
                
                <div class="stats-grid">
                    <div class="stat-card">
                        <h4>Active Clients</h4>
                        <div class="stat-value">${agent.stats?.active_clients || 0}</div>
                    </div>
                    <div class="stat-card">
                        <h4>Total Clients</h4>
                        <div class="stat-value">${agent.stats?.total_clients || 0}</div>
                    </div>
                    <div class="stat-card">
                        <h4>Active Searches</h4>
                        <div class="stat-value">${agent.stats?.active_searches || 0}</div>
                    </div>
                    <div class="stat-card">
                        <h4>Notifications Sent</h4>
                        <div class="stat-value">${agent.stats?.notifications_sent || 0}</div>
                    </div>
                </div>
                
                <div class="agent-clients-section">
                    <h3>
                        Assigned Clients
                        <button type="button" class="button button-secondary add-client-to-agent" style="float: right;">
                            Add New Client
                        </button>
                    </h3>
                    <div class="clients-filter">
                        <select id="client-status-filter">
                            <option value="active">Active Clients</option>
                            <option value="all">All Clients</option>
                            <option value="inactive">Inactive Clients</option>
                        </select>
                    </div>
                    <div id="agent-clients-list">
                        <p>Loading clients...</p>
                    </div>
                </div>
            `;

      $('#agent-details-content').html(html);

      // Bind detail modal events
      $('#client-status-filter').on('change', () => this.loadAgentClients(agent.user_id));
      $('.delete-agent').on('click', (e) => this.deleteAgent(e));
      $('.add-client-to-agent').on('click', () => this.openCreateClientModal(agent.user_id));
    },

    loadAgentClients(agentId) {
      const status = $('#client-status-filter').val() || 'active';

      $.post(
        mldAgentManagementAdmin.ajaxUrl,
        {
          action: 'mld_admin_get_agent_clients',
          nonce: mldAgentManagementAdmin.nonce,
          agent_id: agentId,
          status,
        },
        (response) => {
          if (response.success) {
            this.renderAgentClients(response.data.clients);
          } else {
            $('#agent-clients-list').html('<p class="error">Failed to load clients</p>');
          }
        }
      );
    },

    renderAgentClients(clients) {
      if (clients.length === 0) {
        $('#agent-clients-list').html('<p>No clients found.</p>');
        return;
      }

      let html = `
                <table class="wp-list-table widefat fixed striped clients-table">
                    <thead>
                        <tr>
                            <th>Client</th>
                            <th>Email</th>
                            <th>Active Searches</th>
                            <th>Email Preference</th>
                            <th>Assigned Date</th>
                        </tr>
                    </thead>
                    <tbody>
            `;

      clients.forEach((client) => {
        const emailType = client.default_email_type || 'none';
        html += `
                    <tr>
                        <td>${this.escapeHtml(client.display_name)}</td>
                        <td>${this.escapeHtml(client.user_email)}</td>
                        <td>${client.active_searches || 0}</td>
                        <td><span class="email-type-badge ${emailType}">${emailType}</span></td>
                        <td>${new Date(client.assigned_date).toLocaleDateString()}</td>
                    </tr>
                `;
      });

      html += `
                    </tbody>
                </table>
            `;

      $('#agent-clients-list').html(html);
    },

    deleteAgent(e) {
      const agentId = $(e.currentTarget).data('id');

      if (!confirm(mldAgentManagementAdmin.strings.confirmDelete)) {
        return;
      }

      $.post(
        mldAgentManagementAdmin.ajaxUrl,
        {
          action: 'mld_admin_delete_agent',
          nonce: mldAgentManagementAdmin.nonce,
          agent_id: agentId,
        },
        (response) => {
          if (response.success) {
            this.closeModals();
            this.loadAgents();
          } else {
            this.showError(response.data || mldAgentManagementAdmin.strings.error);
          }
        }
      );
    },

    openMediaUploader() {
      const frame = wp.media({
        title: mldAgentManagementAdmin.strings.selectImage,
        button: {
          text: mldAgentManagementAdmin.strings.useImage,
        },
        multiple: false,
      });

      frame.on('select', () => {
        const attachment = frame.state().get('selection').first().toJSON();
        $('#agent-photo-url').val(attachment.url);
        this.updatePhotoPreview(attachment.url);
      });

      frame.open();
    },

    updatePhotoPreview(url) {
      const $preview = $('#agent-photo-preview');
      const $img = $preview.find('img');
      const $noPhoto = $preview.find('.no-photo');
      const $removeBtn = $('#remove-photo-btn');

      if (url) {
        $img.attr('src', url).show();
        $noPhoto.hide();
        $removeBtn.show();
        $('#agent-photo-url').val(url);
      } else {
        $img.hide();
        $noPhoto.show();
        $removeBtn.hide();
        $('#agent-photo-url').val('');
      }
    },

    removePhoto() {
      this.updatePhotoPreview('');
    },

    closeModals() {
      $('.mld-modal').hide();
      $('#agent-form')[0].reset();
      $('#agent-user-id').prop('disabled', false);
      this.currentEditId = null;
    },

    showError(message) {
      MLD_Utils.notification.error(message);
    },

    escapeHtml(text) {
      return MLD_Utils.escapeHtml(text);
    },

    openCreateClientModal(agentId) {
      // First close the agent details modal
      $('#agent-details-modal').hide();

      // If client management admin exists, use its modal
      if (window.MLD_ClientManagementAdmin && window.MLD_ClientManagementAdmin.openCreateModal) {
        // Pre-select the agent
        window.MLD_ClientManagementAdmin.openCreateModal({ preventDefault: () => {} });
        setTimeout(() => {
          $('#new-client-agent').val(agentId).trigger('change');
        }, 100);
      } else {
        // Fallback: redirect to client management page
        window.location.href =
          mldAgentManagementAdmin.adminUrl + 'admin.php?page=bme-client-management#add-new';
      }
    },

    // ==========================================
    // REFERRAL SYSTEM METHODS (v6.52.0)
    // ==========================================

    setDefaultAgent(e) {
      e.preventDefault();
      const agentId = $(e.currentTarget).data('id');
      const $btn = $(e.currentTarget);

      // Disable button to prevent double-clicks
      $btn.prop('disabled', true);

      $.post(
        mldAgentManagementAdmin.ajaxUrl,
        {
          action: 'mld_admin_set_default_agent',
          nonce: mldAgentManagementAdmin.nonce,
          agent_id: agentId,
        },
        (response) => {
          $btn.prop('disabled', false);
          if (response.success) {
            MLD_Utils.notification.success(
              response.data.message || 'Default agent set successfully'
            );
            // Reload agents to update badges
            this.loadAgents();
          } else {
            MLD_Utils.notification.error(response.data || 'Failed to set default agent');
          }
        }
      ).fail(() => {
        $btn.prop('disabled', false);
        MLD_Utils.notification.error('Request failed. Please try again.');
      });
    },

    copyReferralLink(e) {
      e.preventDefault();
      const url = $(e.currentTarget).data('url');
      const $btn = $(e.currentTarget);

      // Use modern Clipboard API if available
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(url).then(
          () => {
            this.showCopyFeedback($btn, true);
          },
          () => {
            this.showCopyFeedback($btn, false);
          }
        );
      } else {
        // Fallback for older browsers
        const $temp = $('<input>');
        $('body').append($temp);
        $temp.val(url).select();
        try {
          document.execCommand('copy');
          this.showCopyFeedback($btn, true);
        } catch (err) {
          this.showCopyFeedback($btn, false);
        }
        $temp.remove();
      }
    },

    showCopyFeedback($btn, success) {
      const originalHtml = $btn.html();
      if (success) {
        $btn.html('<span class="dashicons dashicons-yes"></span>');
        MLD_Utils.notification.success('Referral link copied to clipboard');
      } else {
        $btn.html('<span class="dashicons dashicons-no"></span>');
        MLD_Utils.notification.error('Failed to copy link');
      }
      setTimeout(() => {
        $btn.html(originalHtml);
      }, 1500);
    },
  };

  // Initialize when ready
  $(document).ready(() => {
    MLD_AgentManagementAdmin.init();
  });
})(jQuery);
