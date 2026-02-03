<?php
/**
 * Admin View: Saved Searches Management
 * 
 * @package MLS_Listings_Display
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap mld-saved-searches-admin">
    <h1>
        <?php _e('Saved Searches', 'mld'); ?>
        <span class="title-count" id="mld-search-count">0</span>
    </h1>
    
    <!-- Statistics Cards -->
    <div class="mld-stats-cards">
        <div class="stats-card">
            <h3><?php _e('Total Searches', 'mld'); ?></h3>
            <p class="stat-number" id="stat-total">0</p>
        </div>
        <div class="stats-card">
            <h3><?php _e('Active Searches', 'mld'); ?></h3>
            <p class="stat-number" id="stat-active">0</p>
        </div>
        <div class="stats-card">
            <h3><?php _e('Notifications Sent', 'mld'); ?></h3>
            <p class="stat-number" id="stat-notifications">0</p>
        </div>
        <div class="stats-card">
            <h3><?php _e('Users with Searches', 'mld'); ?></h3>
            <p class="stat-number" id="stat-users">0</p>
        </div>
        <div class="stats-card">
            <h3><?php _e('Alerts Today', 'mld'); ?></h3>
            <p class="stat-number" id="stat-alerts-today">0</p>
        </div>
    </div>

    <!-- Recent Alerts Log -->
    <div class="mld-recent-alerts">
        <h2><?php _e('Recent Alert Notifications', 'mld'); ?></h2>
        <div class="alerts-log-container">
            <table class="wp-list-table widefat fixed striped" id="recent-alerts-table">
                <thead>
                    <tr>
                        <th><?php _e('Time', 'mld'); ?></th>
                        <th><?php _e('User', 'mld'); ?></th>
                        <th><?php _e('Search Name', 'mld'); ?></th>
                        <th><?php _e('Alert Type', 'mld'); ?></th>
                        <th><?php _e('MLS #', 'mld'); ?></th>
                        <th><?php _e('Frequency', 'mld'); ?></th>
                    </tr>
                </thead>
                <tbody id="recent-alerts-tbody">
                    <tr>
                        <td colspan="6" class="loading-message">
                            <?php _e('Loading recent alerts...', 'mld'); ?>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="alerts-pagination" id="alerts-pagination"></div>
    </div>
    
    <!-- Filters and Search -->
    <div class="mld-admin-filters">
        <div class="filter-group">
            <input type="search" id="search-input" placeholder="<?php esc_attr_e('Search by user email, name, or search name...', 'mld'); ?>" />
        </div>
        
        <div class="filter-group">
            <select id="status-filter">
                <option value=""><?php _e('All Status', 'mld'); ?></option>
                <option value="active"><?php _e('Active', 'mld'); ?></option>
                <option value="inactive"><?php _e('Inactive', 'mld'); ?></option>
            </select>
        </div>
        
        <div class="filter-group">
            <select id="notifications-filter">
                <option value=""><?php _e('All Notifications', 'mld'); ?></option>
                <option value="enabled"><?php _e('Email Updates: On', 'mld'); ?></option>
                <option value="disabled"><?php _e('Email Updates: Off', 'mld'); ?></option>
            </select>
        </div>

        <div class="filter-group">
            <select id="frequency-filter">
                <option value=""><?php _e('All Frequencies', 'mld'); ?></option>
                <option value="instant"><?php _e('Instant (5 min)', 'mld'); ?></option>
                <option value="fifteen_min"><?php _e('Every 15 min', 'mld'); ?></option>
                <option value="hourly"><?php _e('Hourly', 'mld'); ?></option>
                <option value="daily"><?php _e('Daily', 'mld'); ?></option>
                <option value="weekly"><?php _e('Weekly', 'mld'); ?></option>
                <option value="never"><?php _e('Never', 'mld'); ?></option>
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
            <option value="activate"><?php _e('Activate', 'mld'); ?></option>
            <option value="deactivate"><?php _e('Deactivate', 'mld'); ?></option>
            <option value="delete"><?php _e('Delete', 'mld'); ?></option>
        </select>
        <button type="button" class="button" id="do-bulk-action">
            <?php _e('Apply', 'mld'); ?>
        </button>
    </div>
    
    <!-- Searches Table -->
    <table class="wp-list-table widefat fixed striped" id="searches-table">
        <thead>
            <tr>
                <td class="manage-column column-cb check-column">
                    <input type="checkbox" id="select-all" />
                </td>
                <th class="manage-column sortable" data-sort="name">
                    <?php _e('Search Name', 'mld'); ?>
                    <span class="sorting-indicator"></span>
                </th>
                <th class="manage-column sortable" data-sort="user_email">
                    <?php _e('User', 'mld'); ?>
                    <span class="sorting-indicator"></span>
                </th>
                <th class="manage-column"><?php _e('Filters', 'mld'); ?></th>
                <th class="manage-column"><?php _e('Frequency', 'mld'); ?></th>
                <th class="manage-column"><?php _e('Email Updates', 'mld'); ?></th>
                <th class="manage-column"><?php _e('Status', 'mld'); ?></th>
                <th class="manage-column sortable" data-sort="last_notified_at">
                    <?php _e('Last Notified', 'mld'); ?>
                    <span class="sorting-indicator"></span>
                </th>
                <th class="manage-column"><?php _e('Notifications', 'mld'); ?></th>
                <th class="manage-column sortable" data-sort="created_at">
                    <?php _e('Created', 'mld'); ?>
                    <span class="sorting-indicator"></span>
                </th>
                <th class="manage-column"><?php _e('Actions', 'mld'); ?></th>
            </tr>
        </thead>
        <tbody id="searches-tbody">
            <tr>
                <td colspan="11" class="loading-message">
                    <?php _e('Loading saved searches...', 'mld'); ?>
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

<!-- Details Modal -->
<div id="search-details-modal" class="mld-modal" style="display: none;">
    <div class="mld-modal-content">
        <span class="mld-modal-close">&times;</span>
        <h2><?php _e('Search Details', 'mld'); ?></h2>
        <div id="modal-content">
            <!-- Details will be loaded here -->
        </div>
    </div>
</div>

<!-- Loading Template -->
<script type="text/template" id="loading-row-template">
    <tr>
        <td colspan="11" class="loading-message">
            <?php _e('Loading...', 'mld'); ?>
        </td>
    </tr>
</script>

<!-- No Results Template -->
<script type="text/template" id="no-results-template">
    <tr>
        <td colspan="11" class="no-results">
            <?php _e('No saved searches found.', 'mld'); ?>
        </td>
    </tr>
</script>

<!-- Search Row Template -->
<script type="text/template" id="search-row-template">
    <tr data-search-id="{{id}}">
        <th scope="row" class="check-column">
            <input type="checkbox" class="search-checkbox" value="{{id}}" />
        </th>
        <td class="search-name">
            <strong>{{name}}</strong>
            <div class="row-actions">
                <span class="view">
                    <a href="#" class="view-details" data-id="{{id}}"><?php _e('View Details', 'mld'); ?></a> |
                </span>
                <span class="test">
                    <a href="#" class="test-notification" data-id="{{id}}"><?php _e('Send Test', 'mld'); ?></a> |
                </span>
                <span class="delete">
                    <a href="#" class="delete-search" data-id="{{id}}"><?php _e('Delete', 'mld'); ?></a>
                </span>
            </div>
        </td>
        <td class="user-info">
            <div>{{display_name}}</div>
            <div class="user-email">{{user_email}}</div>
        </td>
        <td class="filters">
            <div class="filter-summary">{{filter_summary}}</div>
        </td>
        <td class="frequency">
            <span class="frequency-badge frequency-{{notification_frequency}}">{{notification_frequency_display}}</span>
        </td>
        <td class="email-updates">
            <label class="switch">
                <input type="checkbox" class="toggle-notifications" data-id="{{id}}" {{#if notifications_enabled}}checked{{/if}}>
                <span class="slider round"></span>
            </label>
            <span class="notifications-status">{{#if notifications_enabled}}On{{else}}Off{{/if}}</span>
        </td>
        <td class="status">
            <label class="switch">
                <input type="checkbox" class="toggle-status" data-id="{{id}}" {{#if is_active}}checked{{/if}}>
                <span class="slider round"></span>
            </label>
        </td>
        <td class="last-notified">{{last_notified_formatted}}</td>
        <td class="notifications-count">{{notifications_sent}}</td>
        <td class="created-date">{{created_at_formatted}}</td>
        <td class="actions">
            <button type="button" class="button button-small view-search-url" data-url="{{search_url}}">
                <?php _e('View Search', 'mld'); ?>
            </button>
        </td>
    </tr>
</script>