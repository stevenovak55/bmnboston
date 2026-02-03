<?php
/**
 * Notifications Tab View
 *
 * Configuration for admin notifications and user email summaries
 *
 * @package MLS_Listings_Display
 * @subpackage Admin/Chatbot/Views
 * @since 6.6.0
 */

if (!defined('ABSPATH')) {
    exit;
}

function mld_render_notifications_tab($settings) {
    $notification_settings = isset($settings['notifications']) ? $settings['notifications'] : array();

    $admin_enabled = isset($notification_settings['admin_notification_enabled']) ? $notification_settings['admin_notification_enabled'] : '1';
    $admin_emails = isset($notification_settings['admin_notification_emails']) ? $notification_settings['admin_notification_emails'] : get_option('admin_email');
    $user_summary_enabled = isset($notification_settings['user_summary_enabled']) ? $notification_settings['user_summary_enabled'] : '1';
    $idle_timeout = isset($notification_settings['idle_timeout_minutes']) ? $notification_settings['idle_timeout_minutes'] : '10';

    ?>
    <form method="post" action="options.php" class="mld-chatbot-form">
        <?php settings_fields('mld_chatbot_settings'); ?>

        <!-- Admin Notifications -->
        <div class="mld-settings-section">
            <h2><?php _e('Admin Notifications', 'mls-listings-display'); ?></h2>
            <p class="description">
                <?php _e('Configure real-time notifications sent to admins for every user message.', 'mls-listings-display'); ?>
            </p>
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="admin_notification_enabled"><?php _e('Enable Admin Notifications', 'mls-listings-display'); ?></label>
                    </th>
                    <td>
                        <label class="mld-toggle-switch">
                            <input type="checkbox"
                                   id="admin_notification_enabled"
                                   name="admin_notification_enabled"
                                   value="1"
                                   <?php checked($admin_enabled, '1'); ?>
                                   class="mld-setting-field"
                                   data-setting-key="admin_notification_enabled"
                                   data-category="notifications">
                            <span class="mld-toggle-slider"></span>
                        </label>
                        <p class="description">
                            <?php _e('Send email notification to admin(s) after every user message', 'mls-listings-display'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="admin_notification_emails"><?php _e('Admin Email Addresses', 'mls-listings-display'); ?></label>
                    </th>
                    <td>
                        <textarea id="admin_notification_emails"
                                  name="admin_notification_emails"
                                  rows="3"
                                  class="large-text mld-setting-field"
                                  data-setting-key="admin_notification_emails"
                                  data-category="notifications"><?php echo esc_textarea($admin_emails); ?></textarea>
                        <p class="description">
                            <?php _e('Enter one or more email addresses (comma-separated) to receive chat notifications', 'mls-listings-display'); ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>

        <!-- User Summaries -->
        <div class="mld-settings-section">
            <h2><?php _e('User Email Summaries', 'mls-listings-display'); ?></h2>
            <p class="description">
                <?php _e('Users receive ONE summary email at the end of their conversation.', 'mls-listings-display'); ?>
            </p>
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="user_summary_enabled"><?php _e('Enable User Summaries', 'mls-listings-display'); ?></label>
                    </th>
                    <td>
                        <label class="mld-toggle-switch">
                            <input type="checkbox"
                                   id="user_summary_enabled"
                                   name="user_summary_enabled"
                                   value="1"
                                   <?php checked($user_summary_enabled, '1'); ?>
                                   class="mld-setting-field"
                                   data-setting-key="user_summary_enabled"
                                   data-category="notifications">
                            <span class="mld-toggle-slider"></span>
                        </label>
                        <p class="description">
                            <?php _e('Send conversation summary to users when chat ends', 'mls-listings-display'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="idle_timeout_minutes"><?php _e('Idle Timeout', 'mls-listings-display'); ?></label>
                    </th>
                    <td>
                        <input type="number"
                               id="idle_timeout_minutes"
                               name="idle_timeout_minutes"
                               value="<?php echo esc_attr($idle_timeout); ?>"
                               min="1"
                               max="60"
                               class="small-text mld-setting-field"
                               data-setting-key="idle_timeout_minutes"
                               data-category="notifications">
                        <span><?php _e('minutes', 'mls-listings-display'); ?></span>
                        <p class="description">
                            <?php _e('Send summary email after this many minutes of inactivity (OR when user closes window)', 'mls-listings-display'); ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>

        <!-- Summary Email Template -->
        <div class="mld-settings-section">
            <h2><?php _e('Summary Email Preview', 'mls-listings-display'); ?></h2>
            <div class="mld-email-preview">
                <div class="mld-email-header">
                    <h3><?php _e('Your Real Estate Conversation Summary', 'mls-listings-display'); ?></h3>
                </div>
                <div class="mld-email-body">
                    <p><strong><?php _e('Hi [User Name],', 'mls-listings-display'); ?></strong></p>
                    <p><?php _e('Thanks for chatting with us! Here\'s a summary of our conversation:', 'mls-listings-display'); ?></p>

                    <h4><?php _e('Conversation Highlights:', 'mls-listings-display'); ?></h4>
                    <ul>
                        <li><?php _e('[AI-generated summary bullet points]', 'mls-listings-display'); ?></li>
                    </ul>

                    <h4><?php _e('Properties You Viewed:', 'mls-listings-display'); ?></h4>
                    <ul>
                        <li><?php _e('[Property listings discussed]', 'mls-listings-display'); ?></li>
                    </ul>

                    <h4><?php _e('Next Steps:', 'mls-listings-display'); ?></h4>
                    <ul>
                        <li><?php _e('[Suggested actions]', 'mls-listings-display'); ?></li>
                    </ul>

                    <p><?php _e('Need more help? Reply to this email or start a new chat on our website.', 'mls-listings-display'); ?></p>
                </div>
            </div>
        </div>

        <?php submit_button(__('Save Notification Settings', 'mls-listings-display')); ?>
    </form>
    <?php
}
