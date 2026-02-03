<?php
/**
 * AI Configuration Tab View - Comprehensive Multi-Provider Dashboard
 *
 * Enhanced admin interface that clearly shows:
 * - Which AI providers are configured (with visual status indicators)
 * - Current routing logic and settings
 * - Per-provider configuration with test capabilities
 * - Smart routing configuration
 *
 * @package MLS_Listings_Display
 * @subpackage Admin/Chatbot/Views
 * @since 6.6.0
 * @updated 6.11.1 - Comprehensive multi-provider dashboard
 */

if (!defined('ABSPATH')) {
    exit;
}

function mld_render_ai_config_tab($settings) {
    global $wpdb;
    $settings_table = $wpdb->prefix . 'mld_chat_settings';

    $ai_settings = isset($settings['ai_config']) ? $settings['ai_config'] : array();
    $general_settings = isset($settings['general']) ? $settings['general'] : array();

    // Get all provider API keys and settings
    $openai_api_key = $wpdb->get_var($wpdb->prepare(
        "SELECT setting_value FROM {$settings_table} WHERE setting_key = %s",
        'openai_api_key'
    ));
    $claude_api_key = $wpdb->get_var($wpdb->prepare(
        "SELECT setting_value FROM {$settings_table} WHERE setting_key = %s",
        'claude_api_key'
    ));
    $gemini_api_key = $wpdb->get_var($wpdb->prepare(
        "SELECT setting_value FROM {$settings_table} WHERE setting_key = %s",
        'gemini_api_key'
    ));

    // Get per-provider models
    $openai_model = $wpdb->get_var($wpdb->prepare(
        "SELECT setting_value FROM {$settings_table} WHERE setting_key = %s",
        'openai_model'
    )) ?: 'gpt-4o-mini';
    $claude_model = $wpdb->get_var($wpdb->prepare(
        "SELECT setting_value FROM {$settings_table} WHERE setting_key = %s",
        'claude_model'
    )) ?: 'claude-3-5-haiku-20241022';
    $gemini_model = $wpdb->get_var($wpdb->prepare(
        "SELECT setting_value FROM {$settings_table} WHERE setting_key = %s",
        'gemini_model'
    )) ?: 'gemini-1.5-flash';

    // Get routing configuration
    $routing_config_json = $wpdb->get_var($wpdb->prepare(
        "SELECT setting_value FROM {$settings_table} WHERE setting_key = %s",
        'model_routing_config'
    ));
    $routing_config = $routing_config_json ? json_decode($routing_config_json, true) : array();

    // Default routing config
    $routing_enabled = isset($routing_config['enabled']) ? $routing_config['enabled'] : true;
    $cost_optimization = isset($routing_config['cost_optimization']) ? $routing_config['cost_optimization'] : true;
    $fallback_enabled = isset($routing_config['fallback_enabled']) ? $routing_config['fallback_enabled'] : true;
    $primary_provider = isset($routing_config['primary_provider']) ? $routing_config['primary_provider'] : 'openai';

    $current_provider = isset($ai_settings['ai_provider']) ? $ai_settings['ai_provider'] : 'openai';
    $current_model = isset($ai_settings['ai_model']) ? $ai_settings['ai_model'] : 'gpt-4o-mini';
    $temperature = isset($ai_settings['ai_temperature']) ? $ai_settings['ai_temperature'] : '0.7';
    $max_tokens = isset($ai_settings['ai_max_tokens']) ? $ai_settings['ai_max_tokens'] : '500';
    $chatbot_enabled = isset($general_settings['chatbot_enabled']) ? $general_settings['chatbot_enabled'] : '1';
    $greeting = isset($general_settings['chatbot_greeting']) ? $general_settings['chatbot_greeting'] : '';

    // Count configured providers
    $configured_count = 0;
    if (!empty($openai_api_key)) $configured_count++;
    if (!empty($claude_api_key)) $configured_count++;
    if (!empty($gemini_api_key)) $configured_count++;

    // Load provider classes for model lists
    $chatbot_path = plugin_dir_path(dirname(dirname(dirname(__FILE__)))) . 'includes/chatbot/providers/';
    require_once $chatbot_path . 'class-mld-openai-provider.php';
    require_once $chatbot_path . 'class-mld-claude-provider.php';
    require_once $chatbot_path . 'class-mld-gemini-provider.php';

    $openai_provider = new MLD_OpenAI_Provider();
    $claude_provider = new MLD_Claude_Provider();
    $gemini_provider = new MLD_Gemini_Provider();
    ?>

    <style>
    /* Provider Dashboard Styles */
    .mld-ai-dashboard {
        background: #f8f9fa;
        border-radius: 8px;
        padding: 20px;
        margin-bottom: 25px;
    }
    .mld-ai-dashboard h2 {
        margin-top: 0;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .mld-provider-cards {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 20px;
        margin-top: 20px;
    }
    .mld-provider-card {
        background: #fff;
        border: 2px solid #ddd;
        border-radius: 8px;
        padding: 20px;
        transition: all 0.3s ease;
    }
    .mld-provider-card.configured {
        border-color: #46b450;
    }
    .mld-provider-card.not-configured {
        border-color: #ddd;
        opacity: 0.8;
    }
    .mld-provider-card:hover {
        box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    }
    .mld-provider-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 15px;
    }
    .mld-provider-name {
        display: flex;
        align-items: center;
        gap: 10px;
        font-size: 16px;
        font-weight: 600;
    }
    .mld-provider-logo {
        width: 32px;
        height: 32px;
        border-radius: 6px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 18px;
    }
    .mld-provider-logo.openai { background: #10a37f; color: #fff; }
    .mld-provider-logo.claude { background: #d97706; color: #fff; }
    .mld-provider-logo.gemini { background: #4285f4; color: #fff; }
    .mld-provider-logo.test { background: #6b7280; color: #fff; }

    .mld-status-badge {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 500;
    }
    .mld-status-badge.active {
        background: #d4edda;
        color: #155724;
    }
    .mld-status-badge.configured {
        background: #cce5ff;
        color: #004085;
    }
    .mld-status-badge.not-configured {
        background: #f8d7da;
        color: #721c24;
    }
    .mld-status-badge .dashicons {
        font-size: 14px;
        width: 14px;
        height: 14px;
    }

    .mld-provider-info {
        font-size: 13px;
        color: #666;
        margin-bottom: 15px;
    }
    .mld-provider-info strong {
        color: #333;
    }
    .mld-provider-model {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 8px 12px;
        background: #f1f3f4;
        border-radius: 6px;
        margin-bottom: 15px;
    }
    .mld-provider-model .dashicons {
        color: #666;
    }
    .mld-provider-actions {
        display: flex;
        gap: 10px;
    }
    .mld-provider-actions .button {
        flex: 1;
        text-align: center;
    }

    /* Routing Configuration */
    .mld-routing-config {
        background: #fff;
        border: 1px solid #ddd;
        border-radius: 8px;
        padding: 20px;
        margin-bottom: 25px;
    }
    .mld-routing-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
    }
    .mld-routing-status {
        display: flex;
        gap: 20px;
    }
    .mld-routing-stat {
        text-align: center;
        padding: 10px 20px;
        background: #f8f9fa;
        border-radius: 6px;
    }
    .mld-routing-stat-value {
        font-size: 24px;
        font-weight: 700;
        color: #2271b1;
    }
    .mld-routing-stat-label {
        font-size: 12px;
        color: #666;
    }

    /* Query Type Table */
    .mld-query-routing-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 15px;
    }
    .mld-query-routing-table th,
    .mld-query-routing-table td {
        padding: 12px 15px;
        text-align: left;
        border-bottom: 1px solid #eee;
    }
    .mld-query-routing-table th {
        background: #f8f9fa;
        font-weight: 600;
    }
    .mld-query-routing-table .query-type-badge {
        display: inline-block;
        padding: 4px 10px;
        border-radius: 4px;
        font-size: 12px;
        font-weight: 500;
    }
    .mld-query-routing-table .query-type-badge.simple { background: #d4edda; color: #155724; }
    .mld-query-routing-table .query-type-badge.search { background: #cce5ff; color: #004085; }
    .mld-query-routing-table .query-type-badge.analysis { background: #fff3cd; color: #856404; }
    .mld-query-routing-table .query-type-badge.general { background: #e2e3e5; color: #383d41; }

    .mld-provider-chain {
        display: flex;
        align-items: center;
        gap: 5px;
        flex-wrap: wrap;
    }
    .mld-provider-chain-item {
        display: inline-flex;
        align-items: center;
        padding: 3px 8px;
        background: #f1f3f4;
        border-radius: 4px;
        font-size: 12px;
    }
    .mld-provider-chain-item.available { background: #d4edda; }
    .mld-provider-chain-item.unavailable { background: #f8d7da; text-decoration: line-through; opacity: 0.6; }
    .mld-provider-chain-arrow {
        color: #999;
    }

    /* Provider Configuration Sections */
    .mld-provider-config-section {
        background: #fff;
        border: 1px solid #ddd;
        border-radius: 8px;
        margin-bottom: 20px;
        overflow: hidden;
    }
    .mld-provider-config-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 15px 20px;
        background: #f8f9fa;
        border-bottom: 1px solid #ddd;
        cursor: pointer;
    }
    .mld-provider-config-header:hover {
        background: #f0f1f2;
    }
    .mld-provider-config-title {
        display: flex;
        align-items: center;
        gap: 10px;
        font-weight: 600;
    }
    .mld-provider-config-body {
        padding: 20px;
    }
    .mld-provider-config-body.collapsed {
        display: none;
    }
    .mld-expand-icon {
        transition: transform 0.3s ease;
    }
    .mld-provider-config-header.expanded .mld-expand-icon {
        transform: rotate(180deg);
    }

    /* API Key Display */
    .mld-api-key-display {
        display: flex;
        align-items: center;
        gap: 10px;
        margin-bottom: 10px;
    }
    .mld-api-key-masked {
        font-family: monospace;
        padding: 8px 12px;
        background: #f1f3f4;
        border-radius: 4px;
        flex: 1;
    }
    .mld-api-key-status {
        display: flex;
        align-items: center;
        gap: 5px;
    }
    .mld-api-key-status.verified { color: #46b450; }
    .mld-api-key-status.unverified { color: #dc3232; }

    /* Summary Card */
    .mld-summary-card {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: #fff;
        border-radius: 8px;
        padding: 20px;
        margin-bottom: 25px;
    }
    .mld-summary-card h3 {
        margin: 0 0 15px 0;
        font-size: 18px;
    }
    .mld-summary-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 15px;
    }
    .mld-summary-item {
        text-align: center;
    }
    .mld-summary-value {
        font-size: 28px;
        font-weight: 700;
    }
    .mld-summary-label {
        font-size: 12px;
        opacity: 0.9;
    }

    /* Toggle improvements */
    .mld-toggle-row {
        display: flex;
        align-items: center;
        gap: 15px;
        padding: 10px 0;
    }
    .mld-toggle-label {
        font-weight: 500;
    }
    .mld-toggle-description {
        color: #666;
        font-size: 13px;
    }

    /* Connection status styles */
    .mld-connection-status {
        min-height: 20px;
    }
    .mld-connection-status.success {
        color: #46b450;
    }
    .mld-connection-status.error {
        color: #dc3232;
    }

    /* Spinning animation for refresh button */
    .dashicons.spin {
        animation: mld-spin 1s linear infinite;
    }
    @keyframes mld-spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }

    /* Refresh button styling */
    .mld-refresh-models {
        display: inline-flex;
        align-items: center;
        gap: 5px;
    }
    .mld-refresh-models .dashicons {
        font-size: 16px;
        width: 16px;
        height: 16px;
    }
    </style>

    <form method="post" action="options.php" class="mld-chatbot-form">
        <?php settings_fields('mld_chatbot_settings'); ?>

        <!-- System Status Summary -->
        <div class="mld-summary-card">
            <h3><?php _e('AI Chatbot System Status', 'mls-listings-display'); ?></h3>
            <div class="mld-summary-grid">
                <div class="mld-summary-item">
                    <div class="mld-summary-value"><?php echo $chatbot_enabled == '1' ? '✓' : '✗'; ?></div>
                    <div class="mld-summary-label"><?php _e('Chatbot', 'mls-listings-display'); ?></div>
                </div>
                <div class="mld-summary-item">
                    <div class="mld-summary-value"><?php echo $configured_count; ?>/3</div>
                    <div class="mld-summary-label"><?php _e('Providers Configured', 'mls-listings-display'); ?></div>
                </div>
                <div class="mld-summary-item">
                    <div class="mld-summary-value"><?php echo $routing_enabled ? '✓' : '✗'; ?></div>
                    <div class="mld-summary-label"><?php _e('Smart Routing', 'mls-listings-display'); ?></div>
                </div>
                <div class="mld-summary-item">
                    <div class="mld-summary-value"><?php echo $fallback_enabled ? '✓' : '✗'; ?></div>
                    <div class="mld-summary-label"><?php _e('Auto Fallback', 'mls-listings-display'); ?></div>
                </div>
            </div>
        </div>

        <!-- Chatbot Status Section -->
        <div class="mld-settings-section">
            <h2><?php _e('Chatbot Status', 'mls-listings-display'); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="chatbot_enabled"><?php _e('Enable Chatbot', 'mls-listings-display'); ?></label>
                    </th>
                    <td>
                        <label class="mld-toggle-switch">
                            <input type="checkbox"
                                   id="chatbot_enabled"
                                   name="chatbot_enabled"
                                   value="1"
                                   <?php checked($chatbot_enabled, '1'); ?>
                                   class="mld-setting-field"
                                   data-setting-key="chatbot_enabled"
                                   data-category="general">
                            <span class="mld-toggle-slider"></span>
                        </label>
                        <p class="description">
                            <?php _e('Enable or disable the AI chatbot on your website', 'mls-listings-display'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="chatbot_greeting"><?php _e('Greeting Message', 'mls-listings-display'); ?></label>
                    </th>
                    <td>
                        <textarea id="chatbot_greeting"
                                  name="chatbot_greeting"
                                  rows="3"
                                  class="large-text mld-setting-field"
                                  data-setting-key="chatbot_greeting"
                                  data-category="general"><?php echo esc_textarea($greeting); ?></textarea>
                        <p class="description">
                            <?php _e('Initial message shown to users when they open the chat', 'mls-listings-display'); ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>

        <!-- AI Provider Dashboard -->
        <div class="mld-ai-dashboard">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <h2 style="margin: 0;">
                    <span class="dashicons dashicons-cloud"></span>
                    <?php _e('AI Provider Dashboard', 'mls-listings-display'); ?>
                </h2>
                <button type="button" class="button mld-refresh-models" data-provider="all">
                    <span class="dashicons dashicons-update"></span>
                    <?php _e('Refresh Model Lists', 'mls-listings-display'); ?>
                </button>
            </div>
            <p><?php _e('Configure multiple AI providers. The system will intelligently route queries to the best available model. Model lists are fetched from provider APIs and cached for 24 hours.', 'mls-listings-display'); ?></p>

            <div class="mld-provider-cards">
                <!-- OpenAI Card -->
                <div class="mld-provider-card <?php echo !empty($openai_api_key) ? 'configured' : 'not-configured'; ?>">
                    <div class="mld-provider-header">
                        <div class="mld-provider-name">
                            <div class="mld-provider-logo openai">⚡</div>
                            <span>OpenAI</span>
                        </div>
                        <?php if (!empty($openai_api_key)): ?>
                            <span class="mld-status-badge configured">
                                <span class="dashicons dashicons-yes-alt"></span>
                                <?php _e('Configured', 'mls-listings-display'); ?>
                            </span>
                        <?php else: ?>
                            <span class="mld-status-badge not-configured">
                                <span class="dashicons dashicons-warning"></span>
                                <?php _e('Not Set', 'mls-listings-display'); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <div class="mld-provider-info">
                        <strong><?php _e('Best for:', 'mls-listings-display'); ?></strong> <?php _e('Property searches, function calling', 'mls-listings-display'); ?>
                    </div>
                    <?php if (!empty($openai_api_key)): ?>
                        <div class="mld-provider-model">
                            <span class="dashicons dashicons-admin-generic"></span>
                            <strong><?php _e('Model:', 'mls-listings-display'); ?></strong>
                            <?php echo esc_html($openai_model); ?>
                        </div>
                    <?php endif; ?>
                    <div class="mld-provider-actions">
                        <button type="button" class="button mld-configure-provider" data-provider="openai">
                            <span class="dashicons dashicons-admin-settings"></span>
                            <?php _e('Configure', 'mls-listings-display'); ?>
                        </button>
                        <?php if (!empty($openai_api_key)): ?>
                            <button type="button" class="button mld-test-connection" data-provider="openai">
                                <span class="dashicons dashicons-yes"></span>
                                <?php _e('Test', 'mls-listings-display'); ?>
                            </button>
                        <?php endif; ?>
                    </div>
                    <div class="mld-connection-status" style="margin-top: 10px; font-size: 13px;"></div>
                </div>

                <!-- Claude Card -->
                <div class="mld-provider-card <?php echo !empty($claude_api_key) ? 'configured' : 'not-configured'; ?>">
                    <div class="mld-provider-header">
                        <div class="mld-provider-name">
                            <div class="mld-provider-logo claude">🔶</div>
                            <span>Claude (Anthropic)</span>
                        </div>
                        <?php if (!empty($claude_api_key)): ?>
                            <span class="mld-status-badge configured">
                                <span class="dashicons dashicons-yes-alt"></span>
                                <?php _e('Configured', 'mls-listings-display'); ?>
                            </span>
                        <?php else: ?>
                            <span class="mld-status-badge not-configured">
                                <span class="dashicons dashicons-warning"></span>
                                <?php _e('Not Set', 'mls-listings-display'); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <div class="mld-provider-info">
                        <strong><?php _e('Best for:', 'mls-listings-display'); ?></strong> <?php _e('Complex analysis, market research', 'mls-listings-display'); ?>
                    </div>
                    <?php if (!empty($claude_api_key)): ?>
                        <div class="mld-provider-model">
                            <span class="dashicons dashicons-admin-generic"></span>
                            <strong><?php _e('Model:', 'mls-listings-display'); ?></strong>
                            <?php echo esc_html($claude_model); ?>
                        </div>
                    <?php endif; ?>
                    <div class="mld-provider-actions">
                        <button type="button" class="button mld-configure-provider" data-provider="claude">
                            <span class="dashicons dashicons-admin-settings"></span>
                            <?php _e('Configure', 'mls-listings-display'); ?>
                        </button>
                        <?php if (!empty($claude_api_key)): ?>
                            <button type="button" class="button mld-test-connection" data-provider="claude">
                                <span class="dashicons dashicons-yes"></span>
                                <?php _e('Test', 'mls-listings-display'); ?>
                            </button>
                        <?php endif; ?>
                    </div>
                    <div class="mld-connection-status" style="margin-top: 10px; font-size: 13px;"></div>
                </div>

                <!-- Gemini Card -->
                <div class="mld-provider-card <?php echo !empty($gemini_api_key) ? 'configured' : 'not-configured'; ?>">
                    <div class="mld-provider-header">
                        <div class="mld-provider-name">
                            <div class="mld-provider-logo gemini">✦</div>
                            <span>Google Gemini</span>
                        </div>
                        <?php if (!empty($gemini_api_key)): ?>
                            <span class="mld-status-badge configured">
                                <span class="dashicons dashicons-yes-alt"></span>
                                <?php _e('Configured', 'mls-listings-display'); ?>
                            </span>
                        <?php else: ?>
                            <span class="mld-status-badge not-configured">
                                <span class="dashicons dashicons-warning"></span>
                                <?php _e('Not Set', 'mls-listings-display'); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <div class="mld-provider-info">
                        <strong><?php _e('Best for:', 'mls-listings-display'); ?></strong> <?php _e('Cost-effective, simple queries', 'mls-listings-display'); ?>
                    </div>
                    <?php if (!empty($gemini_api_key)): ?>
                        <div class="mld-provider-model">
                            <span class="dashicons dashicons-admin-generic"></span>
                            <strong><?php _e('Model:', 'mls-listings-display'); ?></strong>
                            <?php echo esc_html($gemini_model); ?>
                        </div>
                    <?php endif; ?>
                    <div class="mld-provider-actions">
                        <button type="button" class="button mld-configure-provider" data-provider="gemini">
                            <span class="dashicons dashicons-admin-settings"></span>
                            <?php _e('Configure', 'mls-listings-display'); ?>
                        </button>
                        <?php if (!empty($gemini_api_key)): ?>
                            <button type="button" class="button mld-test-connection" data-provider="gemini">
                                <span class="dashicons dashicons-yes"></span>
                                <?php _e('Test', 'mls-listings-display'); ?>
                            </button>
                        <?php endif; ?>
                    </div>
                    <div class="mld-connection-status" style="margin-top: 10px; font-size: 13px;"></div>
                </div>

                <!-- Test Provider Card -->
                <div class="mld-provider-card configured">
                    <div class="mld-provider-header">
                        <div class="mld-provider-name">
                            <div class="mld-provider-logo test">🧪</div>
                            <span><?php _e('Test Provider', 'mls-listings-display'); ?></span>
                        </div>
                        <span class="mld-status-badge active">
                            <span class="dashicons dashicons-yes-alt"></span>
                            <?php _e('Always Ready', 'mls-listings-display'); ?>
                        </span>
                    </div>
                    <div class="mld-provider-info">
                        <strong><?php _e('Use for:', 'mls-listings-display'); ?></strong> <?php _e('Testing without API costs', 'mls-listings-display'); ?>
                    </div>
                    <div class="mld-provider-model">
                        <span class="dashicons dashicons-info"></span>
                        <?php _e('No API key required', 'mls-listings-display'); ?>
                    </div>
                    <div class="mld-provider-actions">
                        <button type="button" class="button mld-test-connection" data-provider="test">
                            <span class="dashicons dashicons-yes"></span>
                            <?php _e('Test Demo', 'mls-listings-display'); ?>
                        </button>
                    </div>
                    <div class="mld-connection-status" style="margin-top: 10px; font-size: 13px;"></div>
                </div>
            </div>
        </div>

        <!-- Smart Routing Configuration -->
        <div class="mld-routing-config">
            <div class="mld-routing-header">
                <h2><?php _e('Smart Model Routing', 'mls-listings-display'); ?></h2>
                <div class="mld-routing-status">
                    <div class="mld-routing-stat">
                        <div class="mld-routing-stat-value"><?php echo $configured_count; ?></div>
                        <div class="mld-routing-stat-label"><?php _e('Active Providers', 'mls-listings-display'); ?></div>
                    </div>
                </div>
            </div>

            <p><?php _e('When enabled, the system automatically selects the best AI model based on query type and cost optimization.', 'mls-listings-display'); ?></p>

            <table class="form-table">
                <tr>
                    <th scope="row"><?php _e('Enable Smart Routing', 'mls-listings-display'); ?></th>
                    <td>
                        <label class="mld-toggle-switch">
                            <input type="checkbox"
                                   id="routing_enabled"
                                   name="routing_enabled"
                                   value="1"
                                   <?php checked($routing_enabled, true); ?>
                                   class="mld-setting-field mld-routing-toggle"
                                   data-setting-key="routing_enabled"
                                   data-category="routing">
                            <span class="mld-toggle-slider"></span>
                        </label>
                        <p class="description">
                            <?php _e('Automatically route queries to the most appropriate AI model.', 'mls-listings-display'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Cost Optimization', 'mls-listings-display'); ?></th>
                    <td>
                        <label class="mld-toggle-switch">
                            <input type="checkbox"
                                   id="cost_optimization"
                                   name="cost_optimization"
                                   value="1"
                                   <?php checked($cost_optimization, true); ?>
                                   class="mld-setting-field"
                                   data-setting-key="cost_optimization"
                                   data-category="routing">
                            <span class="mld-toggle-slider"></span>
                        </label>
                        <p class="description">
                            <?php _e('Prefer cheaper models when they can handle the query adequately. (Gemini Flash → GPT-4o-mini → Claude Haiku)', 'mls-listings-display'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Automatic Fallback', 'mls-listings-display'); ?></th>
                    <td>
                        <label class="mld-toggle-switch">
                            <input type="checkbox"
                                   id="fallback_enabled"
                                   name="fallback_enabled"
                                   value="1"
                                   <?php checked($fallback_enabled, true); ?>
                                   class="mld-setting-field"
                                   data-setting-key="fallback_enabled"
                                   data-category="routing">
                            <span class="mld-toggle-slider"></span>
                        </label>
                        <p class="description">
                            <?php _e('If the preferred provider fails, automatically try the next available provider.', 'mls-listings-display'); ?>
                        </p>
                    </td>
                </tr>
            </table>

            <!-- Query Type Routing Table -->
            <h3><?php _e('Query Type Routing Logic', 'mls-listings-display'); ?></h3>
            <p class="description"><?php _e('How different types of user queries are routed to AI providers:', 'mls-listings-display'); ?></p>

            <table class="mld-query-routing-table">
                <thead>
                    <tr>
                        <th><?php _e('Query Type', 'mls-listings-display'); ?></th>
                        <th><?php _e('Examples', 'mls-listings-display'); ?></th>
                        <th><?php _e('Provider Priority', 'mls-listings-display'); ?></th>
                        <th><?php _e('Reason', 'mls-listings-display'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><span class="query-type-badge simple"><?php _e('Simple', 'mls-listings-display'); ?></span></td>
                        <td><?php _e('"Hi", "Thanks", "What can you do?"', 'mls-listings-display'); ?></td>
                        <td>
                            <div class="mld-provider-chain">
                                <?php mld_render_provider_chain(array('openai', 'gemini', 'claude'), $openai_api_key, $claude_api_key, $gemini_api_key); ?>
                            </div>
                        </td>
                        <td><?php _e('Fast and cheap', 'mls-listings-display'); ?></td>
                    </tr>
                    <tr>
                        <td><span class="query-type-badge search"><?php _e('Property Search', 'mls-listings-display'); ?></span></td>
                        <td><?php _e('"Show me 3-bed homes under $500k"', 'mls-listings-display'); ?></td>
                        <td>
                            <div class="mld-provider-chain">
                                <?php mld_render_provider_chain(array('openai', 'claude'), $openai_api_key, $claude_api_key, $gemini_api_key); ?>
                            </div>
                        </td>
                        <td><?php _e('Best function calling', 'mls-listings-display'); ?></td>
                    </tr>
                    <tr>
                        <td><span class="query-type-badge analysis"><?php _e('Market Analysis', 'mls-listings-display'); ?></span></td>
                        <td><?php _e('"What are market trends in Boston?"', 'mls-listings-display'); ?></td>
                        <td>
                            <div class="mld-provider-chain">
                                <?php mld_render_provider_chain(array('claude', 'openai', 'gemini'), $openai_api_key, $claude_api_key, $gemini_api_key); ?>
                            </div>
                        </td>
                        <td><?php _e('Strong reasoning', 'mls-listings-display'); ?></td>
                    </tr>
                    <tr>
                        <td><span class="query-type-badge general"><?php _e('General Q&A', 'mls-listings-display'); ?></span></td>
                        <td><?php _e('"How do mortgages work?"', 'mls-listings-display'); ?></td>
                        <td>
                            <div class="mld-provider-chain">
                                <?php mld_render_provider_chain(array('openai', 'gemini', 'claude'), $openai_api_key, $claude_api_key, $gemini_api_key); ?>
                            </div>
                        </td>
                        <td><?php _e('Balanced cost/quality', 'mls-listings-display'); ?></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Provider Configuration Sections (Collapsible) -->
        <div id="provider-configurations">
            <!-- OpenAI Configuration -->
            <div class="mld-provider-config-section" id="config-openai">
                <div class="mld-provider-config-header" onclick="mldToggleProviderConfig('openai')">
                    <div class="mld-provider-config-title">
                        <div class="mld-provider-logo openai">⚡</div>
                        <span><?php _e('OpenAI Configuration', 'mls-listings-display'); ?></span>
                        <?php if (!empty($openai_api_key)): ?>
                            <span class="mld-status-badge configured"><?php _e('Configured', 'mls-listings-display'); ?></span>
                        <?php endif; ?>
                    </div>
                    <span class="dashicons dashicons-arrow-down-alt2 mld-expand-icon"></span>
                </div>
                <div class="mld-provider-config-body collapsed">
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="openai_api_key"><?php _e('API Key', 'mls-listings-display'); ?></label>
                            </th>
                            <td>
                                <?php if (!empty($openai_api_key)): ?>
                                    <div class="mld-api-key-display">
                                        <span class="mld-api-key-masked">sk-...<?php echo substr($openai_api_key, -4); ?></span>
                                        <span class="mld-api-key-status verified">
                                            <span class="dashicons dashicons-yes-alt"></span>
                                            <?php _e('Saved', 'mls-listings-display'); ?>
                                        </span>
                                    </div>
                                <?php endif; ?>
                                <input type="password"
                                       id="openai_api_key"
                                       name="openai_api_key"
                                       class="regular-text mld-setting-field mld-api-key"
                                       data-setting-key="openai_api_key"
                                       data-category="ai_config"
                                       placeholder="<?php echo !empty($openai_api_key) ? __('Enter new key to update...', 'mls-listings-display') : 'sk-...'; ?>">
                                <button type="button" class="button mld-test-connection" data-provider="openai">
                                    <?php _e('Test Connection', 'mls-listings-display'); ?>
                                </button>
                                <p class="description">
                                    <?php _e('Get your API key from', 'mls-listings-display'); ?>
                                    <a href="https://platform.openai.com/api-keys" target="_blank">OpenAI Platform</a>
                                </p>
                                <div class="mld-connection-status"></div>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="openai_model"><?php _e('Model', 'mls-listings-display'); ?></label>
                            </th>
                            <td>
                                <select id="openai_model"
                                        name="openai_model"
                                        class="regular-text mld-setting-field mld-model-select"
                                        data-setting-key="openai_model"
                                        data-category="ai_config">
                                    <?php
                                    $available_models = $openai_provider->get_available_models();
                                    foreach ($available_models as $model_id => $model_name) {
                                        $selected = selected($openai_model, $model_id, false);
                                        echo '<option value="' . esc_attr($model_id) . '" ' . $selected . '>';
                                        echo esc_html($model_name);
                                        echo '</option>';
                                    }
                                    ?>
                                </select>
                                <p class="description">
                                    <?php _e('Recommended: gpt-4o-mini for cost-effective performance, gpt-4o for best quality.', 'mls-listings-display'); ?>
                                </p>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>

            <!-- Claude Configuration -->
            <div class="mld-provider-config-section" id="config-claude">
                <div class="mld-provider-config-header" onclick="mldToggleProviderConfig('claude')">
                    <div class="mld-provider-config-title">
                        <div class="mld-provider-logo claude">🔶</div>
                        <span><?php _e('Claude (Anthropic) Configuration', 'mls-listings-display'); ?></span>
                        <?php if (!empty($claude_api_key)): ?>
                            <span class="mld-status-badge configured"><?php _e('Configured', 'mls-listings-display'); ?></span>
                        <?php endif; ?>
                    </div>
                    <span class="dashicons dashicons-arrow-down-alt2 mld-expand-icon"></span>
                </div>
                <div class="mld-provider-config-body collapsed">
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="claude_api_key"><?php _e('API Key', 'mls-listings-display'); ?></label>
                            </th>
                            <td>
                                <?php if (!empty($claude_api_key)): ?>
                                    <div class="mld-api-key-display">
                                        <span class="mld-api-key-masked">sk-ant-...<?php echo substr($claude_api_key, -4); ?></span>
                                        <span class="mld-api-key-status verified">
                                            <span class="dashicons dashicons-yes-alt"></span>
                                            <?php _e('Saved', 'mls-listings-display'); ?>
                                        </span>
                                    </div>
                                <?php endif; ?>
                                <input type="password"
                                       id="claude_api_key"
                                       name="claude_api_key"
                                       class="regular-text mld-setting-field mld-api-key"
                                       data-setting-key="claude_api_key"
                                       data-category="ai_config"
                                       placeholder="<?php echo !empty($claude_api_key) ? __('Enter new key to update...', 'mls-listings-display') : 'sk-ant-...'; ?>">
                                <button type="button" class="button mld-test-connection" data-provider="claude">
                                    <?php _e('Test Connection', 'mls-listings-display'); ?>
                                </button>
                                <p class="description">
                                    <?php _e('Get your API key from', 'mls-listings-display'); ?>
                                    <a href="https://console.anthropic.com/" target="_blank">Anthropic Console</a>
                                </p>
                                <div class="mld-connection-status"></div>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="claude_model"><?php _e('Model', 'mls-listings-display'); ?></label>
                            </th>
                            <td>
                                <select id="claude_model"
                                        name="claude_model"
                                        class="regular-text mld-setting-field mld-model-select"
                                        data-setting-key="claude_model"
                                        data-category="ai_config">
                                    <?php
                                    $claude_models = $claude_provider->get_available_models();
                                    foreach ($claude_models as $model_id => $model_name) {
                                        $selected = selected($claude_model, $model_id, false);
                                        echo '<option value="' . esc_attr($model_id) . '" ' . $selected . '>';
                                        echo esc_html($model_name);
                                        echo '</option>';
                                    }
                                    ?>
                                </select>
                                <p class="description">
                                    <?php _e('Recommended: claude-3-5-haiku for fast responses, claude-3-5-sonnet for complex analysis.', 'mls-listings-display'); ?>
                                </p>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>

            <!-- Gemini Configuration -->
            <div class="mld-provider-config-section" id="config-gemini">
                <div class="mld-provider-config-header" onclick="mldToggleProviderConfig('gemini')">
                    <div class="mld-provider-config-title">
                        <div class="mld-provider-logo gemini">✦</div>
                        <span><?php _e('Google Gemini Configuration', 'mls-listings-display'); ?></span>
                        <?php if (!empty($gemini_api_key)): ?>
                            <span class="mld-status-badge configured"><?php _e('Configured', 'mls-listings-display'); ?></span>
                        <?php endif; ?>
                    </div>
                    <span class="dashicons dashicons-arrow-down-alt2 mld-expand-icon"></span>
                </div>
                <div class="mld-provider-config-body collapsed">
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="gemini_api_key"><?php _e('API Key', 'mls-listings-display'); ?></label>
                            </th>
                            <td>
                                <?php if (!empty($gemini_api_key)): ?>
                                    <div class="mld-api-key-display">
                                        <span class="mld-api-key-masked">AIza...<?php echo substr($gemini_api_key, -4); ?></span>
                                        <span class="mld-api-key-status verified">
                                            <span class="dashicons dashicons-yes-alt"></span>
                                            <?php _e('Saved', 'mls-listings-display'); ?>
                                        </span>
                                    </div>
                                <?php endif; ?>
                                <input type="password"
                                       id="gemini_api_key"
                                       name="gemini_api_key"
                                       class="regular-text mld-setting-field mld-api-key"
                                       data-setting-key="gemini_api_key"
                                       data-category="ai_config"
                                       placeholder="<?php echo !empty($gemini_api_key) ? __('Enter new key to update...', 'mls-listings-display') : 'AIza...'; ?>">
                                <button type="button" class="button mld-test-connection" data-provider="gemini">
                                    <?php _e('Test Connection', 'mls-listings-display'); ?>
                                </button>
                                <p class="description">
                                    <?php _e('Get your API key from', 'mls-listings-display'); ?>
                                    <a href="https://makersuite.google.com/app/apikey" target="_blank">Google AI Studio</a>
                                </p>
                                <div class="mld-connection-status"></div>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="gemini_model"><?php _e('Model', 'mls-listings-display'); ?></label>
                            </th>
                            <td>
                                <select id="gemini_model"
                                        name="gemini_model"
                                        class="regular-text mld-setting-field mld-model-select"
                                        data-setting-key="gemini_model"
                                        data-category="ai_config">
                                    <?php
                                    $gemini_models = $gemini_provider->get_available_models();
                                    foreach ($gemini_models as $model_id => $model_name) {
                                        $selected = selected($gemini_model, $model_id, false);
                                        echo '<option value="' . esc_attr($model_id) . '" ' . $selected . '>';
                                        echo esc_html($model_name);
                                        echo '</option>';
                                    }
                                    ?>
                                </select>
                                <p class="description">
                                    <?php _e('Recommended: gemini-1.5-flash for cost-effective performance, gemini-1.5-pro for best quality.', 'mls-listings-display'); ?>
                                </p>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>

        <!-- Model Parameters -->
        <div class="mld-settings-section">
            <h2><?php _e('Model Parameters', 'mls-listings-display'); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="ai_temperature"><?php _e('Temperature', 'mls-listings-display'); ?></label>
                    </th>
                    <td>
                        <input type="number"
                               id="ai_temperature"
                               name="ai_temperature"
                               value="<?php echo esc_attr($temperature); ?>"
                               min="0"
                               max="1"
                               step="0.1"
                               class="small-text mld-setting-field"
                               data-setting-key="ai_temperature"
                               data-category="ai_config">
                        <span class="mld-range-value"><?php echo esc_html($temperature); ?></span>
                        <p class="description">
                            <?php _e('Controls randomness. Lower = more focused, Higher = more creative (0.0 - 1.0)', 'mls-listings-display'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="ai_max_tokens"><?php _e('Max Tokens', 'mls-listings-display'); ?></label>
                    </th>
                    <td>
                        <input type="number"
                               id="ai_max_tokens"
                               name="ai_max_tokens"
                               value="<?php echo esc_attr($max_tokens); ?>"
                               min="50"
                               max="2000"
                               step="50"
                               class="small-text mld-setting-field"
                               data-setting-key="ai_max_tokens"
                               data-category="ai_config">
                        <p class="description">
                            <?php _e('Maximum length of AI responses (50-2000 tokens)', 'mls-listings-display'); ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>

        <!-- System Prompt Editor -->
        <div class="mld-settings-section">
            <h2><?php _e('AI System Prompt', 'mls-listings-display'); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="system_prompt"><?php _e('Custom System Prompt', 'mls-listings-display'); ?></label>
                    </th>
                    <td>
                        <?php
                        $system_prompt = $wpdb->get_var($wpdb->prepare(
                            "SELECT setting_value FROM {$settings_table} WHERE setting_key = %s",
                            'system_prompt'
                        ));

                        if (empty($system_prompt)) {
                            $system_prompt = "You are a professional real estate assistant for {business_name}.

Your role:
- Help users find properties that match their needs
- Answer questions about our listings and services
- Provide helpful real estate information
- Be friendly, professional, and knowledgeable

Guidelines:
- Keep responses concise (2-3 paragraphs max)
- Use a warm, conversational tone
- If you don't know something, be honest
- Always encourage users to contact us for detailed help

Available data:
{current_listings_count} active listings
Price range: {price_range}
Property types: Residential, Commercial, Land

When users ask about specific properties, provide general guidance and suggest they use our search tools for detailed results.";
                        }
                        ?>
                        <textarea id="system_prompt"
                                  name="system_prompt"
                                  rows="15"
                                  class="large-text code mld-setting-field"
                                  data-setting-key="system_prompt"
                                  data-category="ai_config"><?php echo esc_textarea($system_prompt); ?></textarea>
                        <p class="description">
                            <?php _e('Customize the system prompt that guides how the AI responds.', 'mls-listings-display'); ?>
                        </p>
                        <div class="mld-prompt-help" style="margin-top: 15px;">
                            <p><strong><?php _e('Available Placeholders:', 'mls-listings-display'); ?></strong></p>
                            <ul style="list-style: disc; margin-left: 20px; line-height: 1.8;">
                                <li><code>{business_name}</code> - Your business name from WordPress settings</li>
                                <li><code>{current_listings_count}</code> - Total number of active listings</li>
                                <li><code>{price_range}</code> - Price range of available properties</li>
                                <li><code>{site_url}</code> - Your website URL</li>
                            </ul>
                        </div>
                    </td>
                </tr>
            </table>
        </div>

        <!-- Prompt Variables -->
        <div class="mld-settings-section">
            <h2><?php _e('Prompt Variables', 'mls-listings-display'); ?></h2>
            <p class="description">
                <?php _e('Define custom values for placeholders used in your system prompt.', 'mls-listings-display'); ?>
            </p>
            <?php
            $prompt_vars = $wpdb->get_results($wpdb->prepare(
                "SELECT setting_key, setting_value FROM {$settings_table} WHERE setting_category = %s",
                'prompt_variables'
            ), ARRAY_A);

            $vars = array();
            foreach ($prompt_vars as $var) {
                $vars[$var['setting_key']] = $var['setting_value'];
            }
            ?>
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="business_hours"><?php _e('Business Hours', 'mls-listings-display'); ?></label>
                    </th>
                    <td>
                        <input type="text" id="business_hours" name="business_hours" class="large-text mld-setting-field"
                               data-setting-key="business_hours" data-category="prompt_variables"
                               value="<?php echo esc_attr($vars['business_hours'] ?? ''); ?>"
                               placeholder="Monday - Friday: 9:00 AM - 6:00 PM">
                        <p class="description">Use placeholder: <code>{business_hours}</code></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="service_areas"><?php _e('Service Areas', 'mls-listings-display'); ?></label>
                    </th>
                    <td>
                        <input type="text" id="service_areas" name="service_areas" class="large-text mld-setting-field"
                               data-setting-key="service_areas" data-category="prompt_variables"
                               value="<?php echo esc_attr($vars['service_areas'] ?? ''); ?>"
                               placeholder="Greater Boston, Cambridge, Somerville">
                        <p class="description">Use placeholder: <code>{service_areas}</code></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="contact_phone"><?php _e('Contact Phone', 'mls-listings-display'); ?></label>
                    </th>
                    <td>
                        <input type="text" id="contact_phone" name="contact_phone" class="regular-text mld-setting-field"
                               data-setting-key="contact_phone" data-category="prompt_variables"
                               value="<?php echo esc_attr($vars['contact_phone'] ?? ''); ?>"
                               placeholder="(555) 123-4567">
                        <p class="description">Use placeholder: <code>{contact_phone}</code></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="contact_email"><?php _e('Contact Email', 'mls-listings-display'); ?></label>
                    </th>
                    <td>
                        <input type="email" id="contact_email" name="contact_email" class="regular-text mld-setting-field"
                               data-setting-key="contact_email" data-category="prompt_variables"
                               value="<?php echo esc_attr($vars['contact_email'] ?? ''); ?>"
                               placeholder="info@example.com">
                        <p class="description">Use placeholder: <code>{contact_email}</code></p>
                    </td>
                </tr>
            </table>
        </div>

        <?php submit_button(__('Save AI Configuration', 'mls-listings-display')); ?>
    </form>

    <script>
    function mldToggleProviderConfig(provider) {
        var section = document.getElementById('config-' + provider);
        var header = section.querySelector('.mld-provider-config-header');
        var body = section.querySelector('.mld-provider-config-body');

        if (body.classList.contains('collapsed')) {
            body.classList.remove('collapsed');
            header.classList.add('expanded');
        } else {
            body.classList.add('collapsed');
            header.classList.remove('expanded');
        }
    }

    // Configure provider button handler
    document.querySelectorAll('.mld-configure-provider').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var provider = this.getAttribute('data-provider');

            // Collapse all sections first
            document.querySelectorAll('.mld-provider-config-body').forEach(function(body) {
                body.classList.add('collapsed');
            });
            document.querySelectorAll('.mld-provider-config-header').forEach(function(header) {
                header.classList.remove('expanded');
            });

            // Expand the clicked provider's section
            var section = document.getElementById('config-' + provider);
            if (section) {
                var body = section.querySelector('.mld-provider-config-body');
                var header = section.querySelector('.mld-provider-config-header');
                body.classList.remove('collapsed');
                header.classList.add('expanded');

                // Scroll to section
                section.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });
    });
    </script>
    <?php
}

/**
 * Helper function to render provider chain with availability indicators
 */
function mld_render_provider_chain($providers, $openai_key, $claude_key, $gemini_key) {
    $availability = array(
        'openai' => !empty($openai_key),
        'claude' => !empty($claude_key),
        'gemini' => !empty($gemini_key),
    );

    $names = array(
        'openai' => 'OpenAI',
        'claude' => 'Claude',
        'gemini' => 'Gemini',
    );

    $first = true;
    foreach ($providers as $provider) {
        if (!$first) {
            echo '<span class="mld-provider-chain-arrow">→</span>';
        }
        $available = $availability[$provider] ?? false;
        $class = $available ? 'available' : 'unavailable';
        echo '<span class="mld-provider-chain-item ' . $class . '">' . esc_html($names[$provider] ?? $provider) . '</span>';
        $first = false;
    }
}
