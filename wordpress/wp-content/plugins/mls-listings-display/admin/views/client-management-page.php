<?php
/**
 * Admin View: Client Management
 * 
 * @package MLS_Listings_Display
 */

if (!defined('ABSPATH')) {
    exit;
}

// Get agents for dropdown
$agents = MLD_Agent_Client_Manager::get_agents(['status' => 'active']);
?>

<div class="wrap mld-client-management-admin">
    <h1>
        <?php _e('Client Management', 'mld'); ?>
        <a href="#" class="page-title-action" id="add-new-client"><?php _e('Add New Client', 'mld'); ?></a>
    </h1>
    
    <!-- Statistics Cards -->
    <div class="mld-stats-cards">
        <div class="stats-card">
            <h3><?php _e('Total Clients', 'mld'); ?></h3>
            <p class="stat-number" id="stat-total-clients">0</p>
        </div>
        <div class="stats-card">
            <h3><?php _e('Assigned Clients', 'mld'); ?></h3>
            <p class="stat-number" id="stat-assigned">0</p>
        </div>
        <div class="stats-card">
            <h3><?php _e('Unassigned Clients', 'mld'); ?></h3>
            <p class="stat-number" id="stat-unassigned">0</p>
        </div>
        <div class="stats-card">
            <h3><?php _e('Total Active Searches', 'mld'); ?></h3>
            <p class="stat-number" id="stat-searches">0</p>
        </div>
    </div>
    
    <!-- Filters -->
    <div class="mld-admin-filters">
        <div class="filter-group">
            <input type="search" id="search-input" placeholder="<?php esc_attr_e('Search by name or email...', 'mld'); ?>" />
        </div>
        
        <div class="filter-group">
            <select id="assignment-filter">
                <option value="all"><?php _e('All Clients', 'mld'); ?></option>
                <option value="assigned"><?php _e('Assigned', 'mld'); ?></option>
                <option value="unassigned"><?php _e('Unassigned', 'mld'); ?></option>
            </select>
        </div>
        
        <div class="filter-group">
            <button type="button" class="button" id="apply-filters">
                <?php _e('Apply Filters', 'mld'); ?>
            </button>
            <button type="button" class="button" id="clear-filters">
                <?php _e('Clear', 'mld'); ?>
            </button>
        </div>
    </div>
    
    <!-- Bulk Actions -->
    <div class="mld-bulk-actions">
        <select id="bulk-action">
            <option value=""><?php _e('Bulk Actions', 'mld'); ?></option>
            <option value="assign"><?php _e('Assign to Agent', 'mld'); ?></option>
        </select>
        <select id="bulk-agent" style="display: none;">
            <option value=""><?php _e('Select Agent...', 'mld'); ?></option>
            <?php foreach ($agents as $agent): ?>
                <option value="<?php echo esc_attr($agent['user_id']); ?>">
                    <?php echo esc_html($agent['display_name'] ?? $agent['wp_display_name']); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <select id="bulk-email-type" style="display: none;">
            <option value="none"><?php _e('No Email Copy', 'mld'); ?></option>
            <option value="cc"><?php _e('CC Agent', 'mld'); ?></option>
            <option value="bcc"><?php _e('BCC Agent', 'mld'); ?></option>
        </select>
        <button type="button" class="button" id="do-bulk-action">
            <?php _e('Apply', 'mld'); ?>
        </button>
    </div>
    
    <!-- Clients Table -->
    <table class="wp-list-table widefat fixed striped" id="clients-table">
        <thead>
            <tr>
                <td class="manage-column column-cb check-column">
                    <input type="checkbox" id="select-all" />
                </td>
                <th class="manage-column sortable" data-sort="display_name">
                    <?php _e('Client', 'mld'); ?>
                    <span class="sorting-indicator"></span>
                </th>
                <th class="manage-column"><?php _e('Email', 'mld'); ?></th>
                <th class="manage-column"><?php _e('Searches', 'mld'); ?></th>
                <th class="manage-column"><?php _e('Assigned Agent', 'mld'); ?></th>
                <th class="manage-column"><?php _e('Email Copy', 'mld'); ?></th>
                <th class="manage-column sortable" data-sort="user_registered">
                    <?php _e('Registered', 'mld'); ?>
                    <span class="sorting-indicator"></span>
                </th>
                <th class="manage-column"><?php _e('Actions', 'mld'); ?></th>
            </tr>
        </thead>
        <tbody id="clients-tbody">
            <tr>
                <td colspan="8" class="loading-message">
                    <?php _e('Loading clients...', 'mld'); ?>
                </td>
            </tr>
        </tbody>
    </table>
    
    <!-- Pagination -->
    <div class="tablenav bottom">
        <div class="tablenav-pages" id="pagination-container">
            <!-- Pagination will be inserted here -->
        </div>
    </div>
</div>

<!-- Client Details Modal -->
<div id="client-details-modal" class="mld-modal" style="display: none;">
    <div class="mld-modal-content mld-modal-large">
        <span class="mld-modal-close">&times;</span>
        <h2><?php _e('Client Details', 'mld'); ?></h2>
        <div id="client-details-content">
            <!-- Details will be loaded here -->
        </div>
    </div>
</div>

<!-- Assign Agent Modal -->
<div id="assign-agent-modal" class="mld-modal" style="display: none;">
    <div class="mld-modal-content">
        <span class="mld-modal-close">&times;</span>
        <h2><?php _e('Assign Agent', 'mld'); ?></h2>
        
        <form id="assign-agent-form">
            <input type="hidden" id="assign-client-id" value="">
            
            <div class="form-row">
                <label for="assign-agent-id">
                    <?php _e('Select Agent', 'mld'); ?>
                    <span class="required">*</span>
                </label>
                <select id="assign-agent-id" name="agent_id" required>
                    <option value=""><?php _e('Select an agent...', 'mld'); ?></option>
                    <?php foreach ($agents as $agent): ?>
                        <option value="<?php echo esc_attr($agent['user_id']); ?>">
                            <?php echo esc_html($agent['display_name'] ?? $agent['wp_display_name']); ?>
                            <?php if (!empty($agent['office_name'])): ?>
                                - <?php echo esc_html($agent['office_name']); ?>
                            <?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-row">
                <label for="assign-email-type">
                    <?php _e('Email Copy Preference', 'mld'); ?>
                </label>
                <select id="assign-email-type" name="email_type">
                    <option value="none"><?php _e('No Email Copy', 'mld'); ?></option>
                    <option value="cc"><?php _e('CC Agent on Notifications', 'mld'); ?></option>
                    <option value="bcc"><?php _e('BCC Agent on Notifications', 'mld'); ?></option>
                </select>
                <p class="description">
                    <?php _e('Agent will receive copies of saved search notification emails sent to this client.', 'mld'); ?>
                </p>
            </div>
            
            <div class="form-row">
                <label for="assign-notes">
                    <?php _e('Notes', 'mld'); ?>
                </label>
                <textarea id="assign-notes" name="notes" rows="3"></textarea>
            </div>
            
            <div class="modal-actions">
                <button type="submit" class="button button-primary">
                    <?php _e('Assign Agent', 'mld'); ?>
                </button>
                <button type="button" class="button cancel-btn">
                    <?php _e('Cancel', 'mld'); ?>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Client Row Template -->
<script type="text/template" id="client-row-template">
    <tr data-client-id="{{client_id}}">
        <th scope="row" class="check-column">
            <input type="checkbox" class="client-checkbox" value="{{client_id}}" />
        </th>
        <td class="client-name">
            <strong>{{display_name}}</strong>
            <div class="row-actions">
                <span class="view">
                    <a href="#" class="view-details" data-id="{{client_id}}"><?php _e('View Details', 'mld'); ?></a> |
                </span>
                <span class="assign">
                    <a href="#" class="assign-agent" data-id="{{client_id}}"><?php _e('Manage Agent', 'mld'); ?></a>
                </span>
            </div>
        </td>
        <td class="client-email">{{user_email}}</td>
        <td class="client-searches">
            {{active_searches}} / {{total_searches}}
            <span class="description"><?php _e('active / total', 'mld'); ?></span>
        </td>
        <td class="assigned-agent">
            {{#if agent_name}}
                <span class="agent-name">{{agent_name}}</span>
            {{else}}
                <span class="no-agent"><?php _e('Unassigned', 'mld'); ?></span>
            {{/if}}
        </td>
        <td class="email-copy">
            {{#if agent_name}}
                <span class="email-type-badge {{email_type}}">{{email_type}}</span>
            {{else}}
                -
            {{/if}}
        </td>
        <td class="registered-date">{{registered_date}}</td>
        <td class="actions">
            {{#if active_searches}}
                <a href="<?php echo admin_url('admin.php?page=mld-saved-searches&user='); ?>{{client_id}}" 
                   class="button button-small">
                    <?php _e('View Searches', 'mld'); ?>
                </a>
            {{/if}}
        </td>
    </tr>
</script>

<!-- No Results Template -->
<script type="text/template" id="no-results-template">
    <tr>
        <td colspan="8" class="no-results">
            <?php _e('No clients found.', 'mld'); ?>
        </td>
    </tr>
</script>

<!-- Create Client Modal -->
<div id="create-client-modal" class="mld-modal" style="display: none;">
    <div class="mld-modal-content">
        <span class="mld-modal-close">&times;</span>
        <h2><?php _e('Create New Client', 'mld'); ?></h2>
        
        <form id="create-client-form">
            <div class="form-row">
                <label for="new-client-first-name">
                    <?php _e('First Name', 'mld'); ?>
                    <span class="required">*</span>
                </label>
                <input type="text" id="new-client-first-name" name="first_name" required>
            </div>
            
            <div class="form-row">
                <label for="new-client-last-name">
                    <?php _e('Last Name', 'mld'); ?>
                    <span class="required">*</span>
                </label>
                <input type="text" id="new-client-last-name" name="last_name" required>
            </div>
            
            <div class="form-row">
                <label for="new-client-email">
                    <?php _e('Email Address', 'mld'); ?>
                    <span class="required">*</span>
                </label>
                <input type="email" id="new-client-email" name="email" required>
                <p class="description">
                    <?php _e('This will be used as their login email.', 'mld'); ?>
                </p>
            </div>
            
            <div class="form-row">
                <label for="new-client-phone">
                    <?php _e('Phone Number', 'mld'); ?>
                </label>
                <input type="tel" id="new-client-phone" name="phone">
            </div>
            
            <div class="form-row">
                <label for="new-client-agent">
                    <?php _e('Assign to Agent', 'mld'); ?>
                </label>
                <select id="new-client-agent" name="agent_id">
                    <option value=""><?php _e('Do not assign to agent', 'mld'); ?></option>
                    <?php foreach ($agents as $agent): ?>
                        <option value="<?php echo esc_attr($agent['user_id']); ?>">
                            <?php echo esc_html($agent['display_name'] ?? $agent['wp_display_name']); ?>
                            <?php if (!empty($agent['office_name'])): ?>
                                - <?php echo esc_html($agent['office_name']); ?>
                            <?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-row" id="new-client-email-type-row" style="display: none;">
                <label for="new-client-email-type">
                    <?php _e('Email Copy Preference', 'mld'); ?>
                </label>
                <select id="new-client-email-type" name="email_type">
                    <option value="none"><?php _e('No Email Copy', 'mld'); ?></option>
                    <option value="cc"><?php _e('CC Agent on Notifications', 'mld'); ?></option>
                    <option value="bcc"><?php _e('BCC Agent on Notifications', 'mld'); ?></option>
                </select>
            </div>
            
            <div class="form-row">
                <label>
                    <input type="checkbox" id="new-client-send-notification" name="send_notification" value="1" checked>
                    <?php _e('Send welcome email with login details', 'mld'); ?>
                </label>
            </div>
            
            <div class="modal-actions">
                <button type="submit" class="button button-primary">
                    <?php _e('Create Client', 'mld'); ?>
                </button>
                <button type="button" class="button cancel-btn">
                    <?php _e('Cancel', 'mld'); ?>
                </button>
            </div>
        </form>
    </div>
</div>