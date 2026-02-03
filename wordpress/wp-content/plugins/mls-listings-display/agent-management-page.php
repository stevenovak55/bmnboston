<?php
/**
 * Admin View: Agent Management
 * 
 * @package MLS_Listings_Display
 */

if (!defined('ABSPATH')) {
    exit;
}

// Get potential agents for dropdown
$potential_agents = MLD_Agent_Client_Manager::get_potential_agents();

// Get all SNAB staff for dropdown (v6.33.0)
$snab_staff = MLD_Agent_Client_Manager::get_all_snab_staff();
?>

<div class="wrap mld-agent-management-admin">
    <h1>
        <?php _e('Agent Management', 'mld'); ?>
        <button type="button" class="page-title-action" id="add-new-agent">
            <?php _e('Add New Agent', 'mld'); ?>
        </button>
    </h1>
    
    <!-- Status Filter -->
    <div class="mld-admin-filters">
        <div class="filter-group">
            <label><?php _e('Status:', 'mld'); ?></label>
            <select id="status-filter">
                <option value="all"><?php _e('All Agents', 'mld'); ?></option>
                <option value="active"><?php _e('Active', 'mld'); ?></option>
                <option value="inactive"><?php _e('Inactive', 'mld'); ?></option>
            </select>
        </div>
    </div>
    
    <!-- Agents Grid -->
    <div class="mld-agents-grid" id="agents-grid">
        <div class="loading-message">
            <?php _e('Loading agents...', 'mld'); ?>
        </div>
    </div>
</div>

<!-- Add/Edit Agent Modal -->
<div id="agent-modal" class="mld-modal" style="display: none;">
    <div class="mld-modal-content">
        <span class="mld-modal-close">&times;</span>
        <h2 id="modal-title"><?php _e('Add New Agent', 'mld'); ?></h2>
        
        <form id="agent-form">
            <input type="hidden" id="edit-agent-id" value="">
            
            <div class="form-row">
                <label for="agent-user-id">
                    <?php _e('WordPress User', 'mld'); ?>
                    <span class="required">*</span>
                </label>
                <select id="agent-user-id" name="user_id" required>
                    <option value=""><?php _e('Select a user...', 'mld'); ?></option>
                    <?php foreach ($potential_agents as $user): ?>
                        <option value="<?php echo esc_attr($user['ID']); ?>">
                            <?php echo esc_html($user['display_name']); ?> (<?php echo esc_html($user['user_email']); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-row">
                <label for="agent-display-name">
                    <?php _e('Display Name', 'mld'); ?>
                    <span class="description"><?php _e('Leave empty to use WordPress display name', 'mld'); ?></span>
                </label>
                <input type="text" id="agent-display-name" name="display_name" />
            </div>
            
            <div class="form-row form-row-half">
                <div>
                    <label for="agent-email">
                        <?php _e('Email', 'mld'); ?>
                        <span class="description"><?php _e('For notifications', 'mld'); ?></span>
                    </label>
                    <input type="email" id="agent-email" name="email" />
                </div>
                <div>
                    <label for="agent-phone"><?php _e('Phone', 'mld'); ?></label>
                    <input type="tel" id="agent-phone" name="phone" />
                </div>
            </div>
            
            <div class="form-row form-row-half">
                <div>
                    <label for="agent-office-name"><?php _e('Office Name', 'mld'); ?></label>
                    <input type="text" id="agent-office-name" name="office_name" />
                </div>
                <div>
                    <label for="agent-license"><?php _e('License Number', 'mld'); ?></label>
                    <input type="text" id="agent-license" name="license_number" />
                </div>
            </div>

            <div class="form-row form-row-half">
                <div>
                    <label for="agent-mls-id">
                        <?php _e('MLS Agent ID', 'mld'); ?>
                        <span class="description"><?php _e('For ShowingTime integration', 'mld'); ?></span>
                    </label>
                    <input type="text" id="agent-mls-id" name="mls_agent_id" placeholder="e.g., CT004645" />
                </div>
                <div>
                    <!-- Empty for layout balance -->
                </div>
            </div>

            <div class="form-row">
                <label for="agent-office-address"><?php _e('Office Address', 'mld'); ?></label>
                <textarea id="agent-office-address" name="office_address" rows="2"></textarea>
            </div>

            <?php if (!empty($snab_staff)): ?>
            <div class="form-row">
                <label for="agent-snab-staff">
                    <?php _e('Link to Booking Staff', 'mld'); ?>
                    <span class="description"><?php _e('Connect agent to appointment booking system', 'mld'); ?></span>
                </label>
                <select id="agent-snab-staff" name="snab_staff_id">
                    <option value=""><?php _e('— No booking staff linked —', 'mld'); ?></option>
                    <?php foreach ($snab_staff as $staff): ?>
                        <option value="<?php echo esc_attr($staff->id); ?>"
                                <?php if (!empty($staff->linked_agent_id)): ?>
                                data-linked-agent="<?php echo esc_attr($staff->linked_agent_id); ?>"
                                <?php endif; ?>>
                            <?php
                            echo esc_html($staff->name);
                            if (!empty($staff->title)) {
                                echo ' (' . esc_html($staff->title) . ')';
                            }
                            if (!empty($staff->linked_agent_id)) {
                                echo ' — ' . esc_html__('Already linked', 'mld');
                            }
                            ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="description" style="margin-top: 5px;">
                    <?php _e('When linked, clients can book appointments with this agent through the booking system.', 'mld'); ?>
                </p>
            </div>
            <?php endif; ?>
            
            <div class="form-row">
                <label for="agent-bio"><?php _e('Bio', 'mld'); ?></label>
                <textarea id="agent-bio" name="bio" rows="4"></textarea>
            </div>
            
            <div class="form-row">
                <label for="agent-specialties"><?php _e('Specialties', 'mld'); ?></label>
                <textarea id="agent-specialties" name="specialties" rows="2" 
                          placeholder="<?php esc_attr_e('e.g., First-time buyers, Luxury homes, Investment properties', 'mld'); ?>"></textarea>
            </div>
            
            <div class="form-row">
                <label><?php _e('Agent Photo', 'mld'); ?></label>
                <div class="agent-photo-wrapper">
                    <div id="agent-photo-preview" class="agent-photo-preview">
                        <img src="" alt="" style="display: none;">
                        <span class="no-photo"><?php _e('No photo', 'mld'); ?></span>
                    </div>
                    <div class="agent-photo-actions">
                        <button type="button" class="button" id="upload-photo-btn">
                            <?php _e('Upload Photo', 'mld'); ?>
                        </button>
                        <button type="button" class="button" id="remove-photo-btn" style="display: none;">
                            <?php _e('Remove', 'mld'); ?>
                        </button>
                        <input type="hidden" id="agent-photo-url" name="photo_url" />
                    </div>
                </div>
            </div>
            
            <div class="form-row">
                <label class="checkbox-label">
                    <input type="checkbox" id="agent-is-active" name="is_active" value="1" checked>
                    <?php _e('Active', 'mld'); ?>
                </label>
            </div>
            
            <div class="modal-actions">
                <button type="submit" class="button button-primary">
                    <?php _e('Save Agent', 'mld'); ?>
                </button>
                <button type="button" class="button cancel-btn">
                    <?php _e('Cancel', 'mld'); ?>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Agent Details Modal -->
<div id="agent-details-modal" class="mld-modal" style="display: none;">
    <div class="mld-modal-content mld-modal-large">
        <span class="mld-modal-close">&times;</span>
        <div id="agent-details-content">
            <!-- Agent details will be loaded here -->
        </div>
    </div>
</div>

<!-- Agent Card Template -->
<script type="text/template" id="agent-card-template">
    <div class="agent-card {{#if is_default}}is-default-agent{{/if}}" data-agent-id="{{user_id}}">
        {{#if is_default}}
            <div class="default-agent-badge" title="<?php esc_attr_e('Default agent for organic signups', 'mld'); ?>">
                <span class="dashicons dashicons-star-filled"></span>
                <?php _e('Default', 'mld'); ?>
            </div>
        {{/if}}
        <div class="agent-photo">
            {{#if photo_url}}
                <img src="{{photo_url}}" alt="{{display_name}}">
            {{else}}
                <div class="agent-avatar">{{initials}}</div>
            {{/if}}
        </div>
        <div class="agent-info">
            <h3>{{display_name}}</h3>
            <p class="agent-email">{{email}}</p>
            {{#if office_name}}
                <p class="agent-office">{{office_name}}</p>
            {{/if}}
            <div class="agent-stats">
                <span class="stat">
                    <strong>{{stats.active_clients}}</strong> <?php _e('Active Clients', 'mld'); ?>
                </span>
                <span class="stat">
                    <strong>{{stats.active_searches}}</strong> <?php _e('Active Searches', 'mld'); ?>
                </span>
                {{#if referral_signups}}
                <span class="stat referral-stat">
                    <strong>{{referral_signups}}</strong> <?php _e('Referrals', 'mld'); ?>
                </span>
                {{/if}}
            </div>
            {{#if referral_url}}
            <div class="agent-referral-link">
                <input type="text" value="{{referral_url}}" readonly class="referral-url-input" />
                <button type="button" class="button button-small copy-referral" data-url="{{referral_url}}" title="<?php esc_attr_e('Copy referral link', 'mld'); ?>">
                    <span class="dashicons dashicons-clipboard"></span>
                </button>
            </div>
            {{/if}}
        </div>
        <div class="agent-actions">
            <button type="button" class="button button-small view-details" data-id="{{user_id}}">
                <?php _e('View Details', 'mld'); ?>
            </button>
            <button type="button" class="button button-small edit-agent" data-id="{{user_id}}">
                <?php _e('Edit', 'mld'); ?>
            </button>
            {{#unless is_default}}
            <button type="button" class="button button-small set-default-agent" data-id="{{user_id}}" title="<?php esc_attr_e('Set as default agent for new signups', 'mld'); ?>">
                <span class="dashicons dashicons-star-empty"></span>
            </button>
            {{/unless}}
            {{#unless is_active}}
                <span class="agent-inactive"><?php _e('Inactive', 'mld'); ?></span>
            {{/unless}}
        </div>
    </div>
</script>

<!-- No Agents Template -->
<script type="text/template" id="no-agents-template">
    <div class="no-agents">
        <p><?php _e('No agents found.', 'mld'); ?></p>
        <button type="button" class="button button-primary" id="add-first-agent">
            <?php _e('Add Your First Agent', 'mld'); ?>
        </button>
    </div>
</script>