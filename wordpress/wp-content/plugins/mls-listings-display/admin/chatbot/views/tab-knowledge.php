<?php
/**
 * Knowledge Base Tab View
 *
 * Configuration for daily website scanning and knowledge base building
 *
 * @package MLS_Listings_Display
 * @subpackage Admin/Chatbot/Views
 * @since 6.6.0
 */

if (!defined('ABSPATH')) {
    exit;
}

function mld_render_knowledge_tab($settings) {
    $knowledge_settings = isset($settings['knowledge']) ? $settings['knowledge'] : array();

    $scan_enabled = isset($knowledge_settings['knowledge_scan_enabled']) ? $knowledge_settings['knowledge_scan_enabled'] : '1';
    $scan_time = isset($knowledge_settings['knowledge_scan_time']) ? $knowledge_settings['knowledge_scan_time'] : '02:00';
    $scan_listings = isset($knowledge_settings['knowledge_scan_listings']) ? $knowledge_settings['knowledge_scan_listings'] : '1';
    $scan_pages = isset($knowledge_settings['knowledge_scan_pages']) ? $knowledge_settings['knowledge_scan_pages'] : '1';
    $scan_analytics = isset($knowledge_settings['knowledge_scan_analytics']) ? $knowledge_settings['knowledge_scan_analytics'] : '1';
    $scan_faqs = isset($knowledge_settings['knowledge_scan_faqs']) ? $knowledge_settings['knowledge_scan_faqs'] : '1';

    // Get last scan info
    global $wpdb;
    $table_name = $wpdb->prefix . 'mld_chat_knowledge_base';
    $last_scan = $wpdb->get_var("SELECT MAX(scan_date) FROM {$table_name}");
    $total_entries = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name} WHERE is_active = 1");

    ?>
    <form method="post" action="options.php" class="mld-chatbot-form">
        <?php settings_fields('mld_chatbot_settings'); ?>

        <!-- Scanner Status -->
        <div class="mld-settings-section">
            <h2><?php _e('Knowledge Base Status', 'mls-listings-display'); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php _e('Current Status', 'mls-listings-display'); ?></th>
                    <td>
                        <div class="mld-status-box">
                            <p><strong><?php _e('Total Knowledge Entries:', 'mls-listings-display'); ?></strong> <?php echo esc_html($total_entries ? $total_entries : 0); ?></p>
                            <p><strong><?php _e('Last Scan:', 'mls-listings-display'); ?></strong>
                                <?php echo $last_scan ? esc_html(date('F j, Y g:i A', strtotime($last_scan))) : __('Never', 'mls-listings-display'); ?>
                            </p>
                            <button type="button" class="button button-secondary mld-run-scan">
                                <?php _e('Run Scan Now', 'mls-listings-display'); ?>
                            </button>
                        </div>
                    </td>
                </tr>
            </table>
        </div>

        <!-- Scanner Configuration -->
        <div class="mld-settings-section">
            <h2><?php _e('Daily Scan Configuration', 'mls-listings-display'); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="knowledge_scan_enabled"><?php _e('Enable Daily Scanning', 'mls-listings-display'); ?></label>
                    </th>
                    <td>
                        <label class="mld-toggle-switch">
                            <input type="checkbox"
                                   id="knowledge_scan_enabled"
                                   name="knowledge_scan_enabled"
                                   value="1"
                                   <?php checked($scan_enabled, '1'); ?>
                                   class="mld-setting-field"
                                   data-setting-key="knowledge_scan_enabled"
                                   data-category="knowledge">
                            <span class="mld-toggle-slider"></span>
                        </label>
                        <p class="description">
                            <?php _e('Automatically scan your website daily to update the AI knowledge base', 'mls-listings-display'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="knowledge_scan_time"><?php _e('Scan Time', 'mls-listings-display'); ?></label>
                    </th>
                    <td>
                        <input type="time"
                               id="knowledge_scan_time"
                               name="knowledge_scan_time"
                               value="<?php echo esc_attr($scan_time); ?>"
                               class="regular-text mld-setting-field"
                               data-setting-key="knowledge_scan_time"
                               data-category="knowledge">
                        <p class="description">
                            <?php _e('Time of day to run the daily scan (24-hour format)', 'mls-listings-display'); ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>

        <!-- Content Sources -->
        <div class="mld-settings-section">
            <h2><?php _e('Content Sources to Scan', 'mls-listings-display'); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php _e('Property Listings', 'mls-listings-display'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox"
                                   name="knowledge_scan_listings"
                                   value="1"
                                   <?php checked($scan_listings, '1'); ?>
                                   class="mld-setting-field"
                                   data-setting-key="knowledge_scan_listings"
                                   data-category="knowledge">
                            <?php _e('Scan active property listings (addresses, prices, features)', 'mls-listings-display'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Pages & Posts', 'mls-listings-display'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox"
                                   name="knowledge_scan_pages"
                                   value="1"
                                   <?php checked($scan_pages, '1'); ?>
                                   class="mld-setting-field"
                                   data-setting-key="knowledge_scan_pages"
                                   data-category="knowledge">
                            <?php _e('Scan WordPress pages and blog posts (About, Services, etc.)', 'mls-listings-display'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Market Analytics', 'mls-listings-display'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox"
                                   name="knowledge_scan_analytics"
                                   value="1"
                                   <?php checked($scan_analytics, '1'); ?>
                                   class="mld-setting-field"
                                   data-setting-key="knowledge_scan_analytics"
                                   data-category="knowledge">
                            <?php _e('Scan market trends and neighborhood data', 'mls-listings-display'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('FAQ Content', 'mls-listings-display'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox"
                                   name="knowledge_scan_faqs"
                                   value="1"
                                   <?php checked($scan_faqs, '1'); ?>
                                   class="mld-setting-field"
                                   data-setting-key="knowledge_scan_faqs"
                                   data-category="knowledge">
                            <?php _e('Scan FAQ pages and common questions', 'mls-listings-display'); ?>
                        </label>
                    </td>
                </tr>
            </table>
        </div>

        <?php submit_button(__('Save Knowledge Base Settings', 'mls-listings-display')); ?>
    </form>
    <?php
}
