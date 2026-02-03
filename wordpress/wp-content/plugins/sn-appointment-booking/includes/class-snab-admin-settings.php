<?php
/**
 * Admin Settings Class
 *
 * Handles the settings page with Google Calendar integration configuration.
 *
 * @package SN_Appointment_Booking
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin Settings class.
 *
 * @since 1.0.0
 */
class SNAB_Admin_Settings {

    /**
     * Google Calendar instance.
     *
     * @var SNAB_Google_Calendar
     */
    private $google_calendar;

    /**
     * Appearance settings instance.
     *
     * @var SNAB_Admin_Appearance
     * @since 1.3.0
     */
    private $appearance;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->google_calendar = snab_google_calendar();
        $this->appearance = new SNAB_Admin_Appearance();

        // Handle OAuth callback
        add_action('admin_init', array($this, 'handle_oauth_callback'));

        // Register AJAX handlers
        add_action('wp_ajax_snab_save_settings', array($this, 'ajax_save_settings'));
        add_action('wp_ajax_snab_save_calendar_selection', array($this, 'ajax_save_calendar_selection'));
        add_action('wp_ajax_snab_save_client_portal_settings', array($this, 'ajax_save_client_portal_settings'));
        add_action('wp_ajax_snab_save_notification_settings', array($this, 'ajax_save_notification_settings'));
        add_action('wp_ajax_snab_save_booking_widget_settings', array($this, 'ajax_save_booking_widget_settings'));
    }

    /**
     * AJAX handler to save notification settings.
     *
     * @since 1.6.0
     */
    public function ajax_save_notification_settings() {
        check_ajax_referer('snab_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'sn-appointment-booking'));
        }

        // Sanitize and save settings
        $bool_settings = array(
            'snab_notify_new_booking',
            'snab_notify_cancellation',
            'snab_notify_reschedule',
            'snab_notify_reminder',
        );

        foreach ($bool_settings as $setting) {
            $key = str_replace('snab_', '', $setting);
            $value = isset($_POST[$key]) ? (bool) $_POST[$key] : false;
            update_option($setting, $value);
        }

        // Email settings
        $notification_email = isset($_POST['notification_email']) ? sanitize_email($_POST['notification_email']) : '';
        $secondary_email = isset($_POST['secondary_notification_email']) ? sanitize_email($_POST['secondary_notification_email']) : '';
        $frequency = isset($_POST['notification_frequency']) ? sanitize_key($_POST['notification_frequency']) : 'instant';

        if ($notification_email) {
            update_option('snab_notification_email', $notification_email);
        }
        update_option('snab_secondary_notification_email', $secondary_email);
        update_option('snab_notification_frequency', $frequency);

        wp_send_json_success(array(
            'message' => __('Notification settings saved successfully.', 'sn-appointment-booking'),
        ));
    }

    /**
     * AJAX handler to save booking widget settings.
     *
     * @since 1.6.0
     */
    public function ajax_save_booking_widget_settings() {
        check_ajax_referer('snab_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'sn-appointment-booking'));
        }

        // Sanitize and save settings
        $staff_selection_mode = isset($_POST['staff_selection_mode']) ? sanitize_key($_POST['staff_selection_mode']) : 'disabled';
        if (!in_array($staff_selection_mode, array('disabled', 'optional', 'required'), true)) {
            $staff_selection_mode = 'disabled';
        }

        $show_staff_avatar = isset($_POST['show_staff_avatar']) ? (bool) $_POST['show_staff_avatar'] : true;
        $show_staff_bio = isset($_POST['show_staff_bio']) ? (bool) $_POST['show_staff_bio'] : false;

        update_option('snab_staff_selection_mode', $staff_selection_mode);
        update_option('snab_show_staff_avatar', $show_staff_avatar);
        update_option('snab_show_staff_bio', $show_staff_bio);

        wp_send_json_success(array(
            'message' => __('Booking widget settings saved successfully.', 'sn-appointment-booking'),
        ));
    }

    /**
     * AJAX handler to save client portal settings.
     *
     * @since 1.5.0
     */
    public function ajax_save_client_portal_settings() {
        check_ajax_referer('snab_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'sn-appointment-booking'));
        }

        // Sanitize and save settings
        $settings = array(
            'enable_client_portal' => isset($_POST['enable_client_portal']) ? (bool) $_POST['enable_client_portal'] : false,
            'cancellation_hours_before' => isset($_POST['cancellation_hours_before']) ? absint($_POST['cancellation_hours_before']) : 24,
            'reschedule_hours_before' => isset($_POST['reschedule_hours_before']) ? absint($_POST['reschedule_hours_before']) : 24,
            'max_reschedules_per_appointment' => isset($_POST['max_reschedules_per_appointment']) ? absint($_POST['max_reschedules_per_appointment']) : 0,
            'require_cancel_reason' => isset($_POST['require_cancel_reason']) ? (bool) $_POST['require_cancel_reason'] : false,
            'notify_admin_on_client_changes' => isset($_POST['notify_admin_on_client_changes']) ? (bool) $_POST['notify_admin_on_client_changes'] : true,
        );

        foreach ($settings as $key => $value) {
            update_option('snab_' . $key, $value);
        }

        wp_send_json_success(array(
            'message' => __('Client Portal settings saved successfully.', 'sn-appointment-booking'),
        ));
    }

    /**
     * Handle OAuth callback from Google.
     */
    public function handle_oauth_callback() {
        // Check if this is an OAuth callback
        if (!isset($_GET['page']) || $_GET['page'] !== 'snab-settings') {
            return;
        }

        if (!isset($_GET['snab_oauth_callback'])) {
            return;
        }

        // Handle error response
        if (isset($_GET['error'])) {
            $error = sanitize_text_field($_GET['error']);
            $error_description = isset($_GET['error_description'])
                ? sanitize_text_field($_GET['error_description'])
                : __('Authorization was denied.', 'sn-appointment-booking');

            SNAB_Logger::error('OAuth callback error', array('error' => $error, 'description' => $error_description));

            // Redirect with error message
            wp_safe_redirect(add_query_arg(array(
                'page' => 'snab-settings',
                'snab_error' => urlencode($error_description),
            ), admin_url('admin.php')));
            exit;
        }

        // Handle authorization code
        if (isset($_GET['code'])) {
            $state = isset($_GET['state']) ? sanitize_text_field($_GET['state']) : '';
            $code = sanitize_text_field($_GET['code']);

            // Check if this is a staff connection (state contains |staff_id)
            if (strpos($state, '|') !== false) {
                // Staff connection
                $state_parts = explode('|', $state);
                if (count($state_parts) !== 2) {
                    SNAB_Logger::error('OAuth callback: Invalid staff state format');
                    wp_safe_redirect(add_query_arg(array(
                        'page' => 'snab-staff',
                        'snab_error' => urlencode(__('Invalid security token. Please try again.', 'sn-appointment-booking')),
                    ), admin_url('admin.php')));
                    exit;
                }

                $nonce = $state_parts[0];
                $staff_id = absint($state_parts[1]);

                // Verify staff nonce
                if (!wp_verify_nonce($nonce, 'snab_staff_google_oauth_' . $staff_id)) {
                    SNAB_Logger::error('OAuth callback: Invalid staff nonce', array('staff_id' => $staff_id));
                    wp_safe_redirect(add_query_arg(array(
                        'page' => 'snab-staff',
                        'snab_error' => urlencode(__('Invalid security token. Please try again.', 'sn-appointment-booking')),
                    ), admin_url('admin.php')));
                    exit;
                }

                // Exchange code for staff tokens
                $result = $this->google_calendar->exchange_code_for_staff_tokens($code, $staff_id);

                if (is_wp_error($result)) {
                    wp_safe_redirect(add_query_arg(array(
                        'page' => 'snab-staff',
                        'snab_error' => urlencode($result->get_error_message()),
                    ), admin_url('admin.php')));
                    exit;
                }

                // Success - redirect to staff page
                wp_safe_redirect(add_query_arg(array(
                    'page' => 'snab-staff',
                    'calendar_connected' => $staff_id,
                ), admin_url('admin.php')));
                exit;
            }

            // Main connection - verify state nonce
            if (!wp_verify_nonce($state, 'snab_google_oauth')) {
                SNAB_Logger::error('OAuth callback: Invalid state nonce');
                wp_safe_redirect(add_query_arg(array(
                    'page' => 'snab-settings',
                    'snab_error' => urlencode(__('Invalid security token. Please try again.', 'sn-appointment-booking')),
                ), admin_url('admin.php')));
                exit;
            }

            $result = $this->google_calendar->exchange_code_for_tokens($code);

            if (is_wp_error($result)) {
                wp_safe_redirect(add_query_arg(array(
                    'page' => 'snab-settings',
                    'snab_error' => urlencode($result->get_error_message()),
                ), admin_url('admin.php')));
                exit;
            }

            // Success - redirect to settings page
            wp_safe_redirect(add_query_arg(array(
                'page' => 'snab-settings',
                'snab_success' => 'connected',
            ), admin_url('admin.php')));
            exit;
        }
    }

    /**
     * AJAX handler to save settings.
     */
    public function ajax_save_settings() {
        check_ajax_referer('snab_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'sn-appointment-booking'));
        }

        $client_id = isset($_POST['client_id']) ? sanitize_text_field($_POST['client_id']) : '';
        $client_secret = isset($_POST['client_secret']) ? sanitize_text_field($_POST['client_secret']) : '';

        // Validate
        if (empty($client_id) || empty($client_secret)) {
            wp_send_json_error(__('Both Client ID and Client Secret are required.', 'sn-appointment-booking'));
        }

        // Save credentials
        update_option(SNAB_Google_Calendar::OPTION_CLIENT_ID, $client_id);
        update_option(SNAB_Google_Calendar::OPTION_CLIENT_SECRET, $client_secret);

        // If credentials changed and we were connected, disconnect
        $old_id = get_option(SNAB_Google_Calendar::OPTION_CLIENT_ID);
        if ($this->google_calendar->is_connected() && $old_id !== $client_id) {
            $this->google_calendar->disconnect();
        }

        wp_send_json_success(array(
            'message' => __('Settings saved successfully.', 'sn-appointment-booking'),
            'auth_url' => $this->google_calendar->get_auth_url(),
        ));
    }

    /**
     * AJAX handler to save calendar selection.
     */
    public function ajax_save_calendar_selection() {
        check_ajax_referer('snab_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'sn-appointment-booking'));
        }

        $calendar_id = isset($_POST['calendar_id']) ? sanitize_text_field($_POST['calendar_id']) : '';

        if (empty($calendar_id)) {
            wp_send_json_error(__('Please select a calendar.', 'sn-appointment-booking'));
        }

        $this->google_calendar->set_selected_calendar($calendar_id);

        wp_send_json_success(array(
            'message' => __('Calendar selection saved.', 'sn-appointment-booking'),
        ));
    }

    /**
     * Render the settings page.
     */
    public function render() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'sn-appointment-booking'));
        }

        $status = $this->google_calendar->get_connection_status();
        $client_id = get_option(SNAB_Google_Calendar::OPTION_CLIENT_ID, '');

        // Get success/error messages from URL
        $success_message = '';
        $error_message = '';

        if (isset($_GET['snab_success']) && $_GET['snab_success'] === 'connected') {
            $success_message = __('Successfully connected to Google Calendar!', 'sn-appointment-booking');
        }

        if (isset($_GET['snab_error'])) {
            $error_message = sanitize_text_field(urldecode($_GET['snab_error']));
        }

        // Get current tab
        $current_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'google-calendar';

        ?>
        <div class="wrap snab-admin-wrap">
            <h1><?php esc_html_e('Settings', 'sn-appointment-booking'); ?></h1>

            <?php if ($success_message): ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php echo esc_html($success_message); ?></p>
                </div>
            <?php endif; ?>

            <?php if ($error_message): ?>
                <div class="notice notice-error is-dismissible">
                    <p><?php echo esc_html($error_message); ?></p>
                </div>
            <?php endif; ?>

            <!-- Settings Tabs -->
            <nav class="nav-tab-wrapper snab-settings-tabs">
                <a href="<?php echo esc_url(add_query_arg('tab', 'google-calendar', remove_query_arg(array('snab_success', 'snab_error')))); ?>"
                   class="nav-tab <?php echo $current_tab === 'google-calendar' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-calendar-alt"></span>
                    <?php esc_html_e('Google Calendar', 'sn-appointment-booking'); ?>
                </a>
                <a href="<?php echo esc_url(add_query_arg('tab', 'appearance', remove_query_arg(array('snab_success', 'snab_error')))); ?>"
                   class="nav-tab <?php echo $current_tab === 'appearance' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-admin-customizer"></span>
                    <?php esc_html_e('Appearance', 'sn-appointment-booking'); ?>
                </a>
                <a href="<?php echo esc_url(add_query_arg('tab', 'client-portal', remove_query_arg(array('snab_success', 'snab_error')))); ?>"
                   class="nav-tab <?php echo $current_tab === 'client-portal' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-groups"></span>
                    <?php esc_html_e('Client Portal', 'sn-appointment-booking'); ?>
                </a>
                <a href="<?php echo esc_url(add_query_arg('tab', 'notifications', remove_query_arg(array('snab_success', 'snab_error')))); ?>"
                   class="nav-tab <?php echo $current_tab === 'notifications' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-email-alt"></span>
                    <?php esc_html_e('Notifications', 'sn-appointment-booking'); ?>
                </a>
                <a href="<?php echo esc_url(add_query_arg('tab', 'booking-widget', remove_query_arg(array('snab_success', 'snab_error')))); ?>"
                   class="nav-tab <?php echo $current_tab === 'booking-widget' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-calendar"></span>
                    <?php esc_html_e('Booking Widget', 'sn-appointment-booking'); ?>
                </a>
            </nav>

            <?php if ($current_tab === 'appearance'): ?>
                <?php $this->appearance->render(); ?>
            <?php elseif ($current_tab === 'client-portal'): ?>
                <?php $this->render_client_portal_tab(); ?>
            <?php elseif ($current_tab === 'notifications'): ?>
                <?php $this->render_notifications_tab(); ?>
            <?php elseif ($current_tab === 'booking-widget'): ?>
                <?php $this->render_booking_widget_tab(); ?>
            <?php else: ?>
            <!-- Google Calendar Integration -->
            <div class="snab-settings-section">
                <h2><?php esc_html_e('Google Calendar Integration', 'sn-appointment-booking'); ?></h2>
                <p class="description">
                    <?php esc_html_e('Connect your Google Calendar to automatically sync appointments and check for conflicts.', 'sn-appointment-booking'); ?>
                </p>

                <!-- Connection Status -->
                <div class="snab-connection-status">
                    <?php if ($status['connected']): ?>
                        <div class="snab-status-connected">
                            <span class="dashicons dashicons-yes-alt"></span>
                            <strong><?php esc_html_e('Connected', 'sn-appointment-booking'); ?></strong>
                            <?php if ($status['calendar_name']): ?>
                                <span class="snab-calendar-name">
                                    <?php echo esc_html(sprintf(__('Calendar: %s', 'sn-appointment-booking'), $status['calendar_name'])); ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    <?php elseif ($status['configured']): ?>
                        <div class="snab-status-configured">
                            <span class="dashicons dashicons-warning"></span>
                            <strong><?php esc_html_e('Configured but not connected', 'sn-appointment-booking'); ?></strong>
                        </div>
                    <?php else: ?>
                        <div class="snab-status-not-configured">
                            <span class="dashicons dashicons-info"></span>
                            <strong><?php esc_html_e('Not configured', 'sn-appointment-booking'); ?></strong>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Setup Instructions -->
                <div class="snab-setup-instructions">
                    <h3><?php esc_html_e('Setup Instructions', 'sn-appointment-booking'); ?></h3>
                    <ol>
                        <li>
                            <?php
                            printf(
                                wp_kses(
                                    __('Go to the <a href="%s" target="_blank">Google Cloud Console</a> and create a new project (or select an existing one).', 'sn-appointment-booking'),
                                    array('a' => array('href' => array(), 'target' => array()))
                                ),
                                'https://console.cloud.google.com/'
                            );
                            ?>
                        </li>
                        <li><?php esc_html_e('Enable the Google Calendar API for your project.', 'sn-appointment-booking'); ?></li>
                        <li><?php esc_html_e('Go to "Credentials" and create an OAuth 2.0 Client ID.', 'sn-appointment-booking'); ?></li>
                        <li><?php esc_html_e('Set the application type to "Web application".', 'sn-appointment-booking'); ?></li>
                        <li>
                            <?php esc_html_e('Add the following Authorized redirect URI:', 'sn-appointment-booking'); ?>
                            <code class="snab-redirect-uri"><?php echo esc_html($this->google_calendar->get_redirect_uri()); ?></code>
                            <button type="button" class="button-link snab-copy-uri" data-uri="<?php echo esc_attr($this->google_calendar->get_redirect_uri()); ?>">
                                <span class="dashicons dashicons-clipboard"></span>
                                <?php esc_html_e('Copy', 'sn-appointment-booking'); ?>
                            </button>
                        </li>
                        <li><?php esc_html_e('Copy the Client ID and Client Secret and paste them below.', 'sn-appointment-booking'); ?></li>
                    </ol>
                </div>

                <!-- Credentials Form -->
                <form id="snab-credentials-form" class="snab-settings-form">
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="snab-client-id"><?php esc_html_e('Client ID', 'sn-appointment-booking'); ?></label>
                            </th>
                            <td>
                                <input type="text" id="snab-client-id" name="client_id"
                                       value="<?php echo esc_attr($client_id); ?>"
                                       class="regular-text" autocomplete="off">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="snab-client-secret"><?php esc_html_e('Client Secret', 'sn-appointment-booking'); ?></label>
                            </th>
                            <td>
                                <input type="password" id="snab-client-secret" name="client_secret"
                                       value="<?php echo $status['configured'] ? '••••••••' : ''; ?>"
                                       class="regular-text" autocomplete="new-password">
                                <p class="description"><?php esc_html_e('Enter a new secret to update, or leave as-is to keep the existing one.', 'sn-appointment-booking'); ?></p>
                            </td>
                        </tr>
                    </table>

                    <p class="submit">
                        <button type="submit" class="button button-primary" id="snab-save-credentials">
                            <?php esc_html_e('Save Credentials', 'sn-appointment-booking'); ?>
                        </button>
                        <span class="spinner"></span>
                    </p>
                </form>

                <!-- Connect/Disconnect Button -->
                <div class="snab-connection-actions">
                    <?php if ($status['connected']): ?>
                        <button type="button" class="button button-secondary" id="snab-disconnect-google">
                            <span class="dashicons dashicons-no"></span>
                            <?php esc_html_e('Disconnect from Google Calendar', 'sn-appointment-booking'); ?>
                        </button>
                    <?php elseif ($status['configured']): ?>
                        <a href="<?php echo esc_url($this->google_calendar->get_auth_url()); ?>" class="button button-primary">
                            <span class="dashicons dashicons-admin-links"></span>
                            <?php esc_html_e('Connect to Google Calendar', 'sn-appointment-booking'); ?>
                        </a>
                    <?php endif; ?>
                </div>

                <!-- Calendar Selection (shown only when connected) -->
                <?php if ($status['connected']): ?>
                    <div class="snab-calendar-selection">
                        <h3><?php esc_html_e('Select Calendar', 'sn-appointment-booking'); ?></h3>
                        <p class="description">
                            <?php esc_html_e('Choose which calendar to use for appointments. Events will be created here and busy times will be checked.', 'sn-appointment-booking'); ?>
                        </p>

                        <div class="snab-calendar-selector">
                            <select id="snab-calendar-select" class="regular-text">
                                <option value=""><?php esc_html_e('Loading calendars...', 'sn-appointment-booking'); ?></option>
                            </select>
                            <button type="button" class="button" id="snab-save-calendar">
                                <?php esc_html_e('Save Selection', 'sn-appointment-booking'); ?>
                            </button>
                            <span class="spinner"></span>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Shortcode Reference -->
            <div class="snab-settings-section">
                <h2><?php esc_html_e('Shortcode Reference', 'sn-appointment-booking'); ?></h2>
                <p class="description">
                    <?php esc_html_e('Use this shortcode to display the booking form on any page:', 'sn-appointment-booking'); ?>
                </p>
                <code>[snab_booking_form]</code>

                <h3><?php esc_html_e('Available Attributes', 'sn-appointment-booking'); ?></h3>
                <table class="widefat">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Attribute', 'sn-appointment-booking'); ?></th>
                            <th><?php esc_html_e('Description', 'sn-appointment-booking'); ?></th>
                            <th><?php esc_html_e('Default', 'sn-appointment-booking'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><code>type</code></td>
                            <td><?php esc_html_e('Filter to specific appointment type slug(s). Separate multiple with commas.', 'sn-appointment-booking'); ?></td>
                            <td><?php esc_html_e('All types', 'sn-appointment-booking'); ?></td>
                        </tr>
                        <tr>
                            <td><code>weeks</code></td>
                            <td><?php esc_html_e('Number of weeks to show in the calendar.', 'sn-appointment-booking'); ?></td>
                            <td>2</td>
                        </tr>
                        <tr>
                            <td><code>show_timezone</code></td>
                            <td><?php esc_html_e('Show timezone selector to visitors.', 'sn-appointment-booking'); ?></td>
                            <td>true</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Version Info -->
            <div class="snab-settings-section snab-version-info">
                <p>
                    <strong><?php esc_html_e('Version:', 'sn-appointment-booking'); ?></strong>
                    <?php echo esc_html(SNAB_VERSION); ?>
                </p>
            </div>
            <?php endif; // End Google Calendar tab ?>
        </div>

        <style>
            .snab-settings-tabs {
                margin-bottom: 0;
            }

            .snab-settings-tabs .nav-tab {
                display: inline-flex;
                align-items: center;
                gap: 5px;
            }

            .snab-settings-tabs .nav-tab .dashicons {
                font-size: 16px;
                width: 16px;
                height: 16px;
            }
            .snab-settings-section {
                background: #fff;
                padding: 20px;
                margin: 20px 0;
                border: 1px solid #ccd0d4;
                box-shadow: 0 1px 1px rgba(0,0,0,.04);
            }

            .snab-settings-section h2 {
                margin-top: 0;
                padding-bottom: 10px;
                border-bottom: 1px solid #eee;
            }

            .snab-connection-status {
                padding: 15px;
                margin: 15px 0;
                border-radius: 4px;
            }

            .snab-status-connected {
                background: #d4edda;
                color: #155724;
            }

            .snab-status-configured {
                background: #fff3cd;
                color: #856404;
            }

            .snab-status-not-configured {
                background: #f8f9fa;
                color: #495057;
            }

            .snab-connection-status .dashicons {
                vertical-align: middle;
                margin-right: 5px;
            }

            .snab-calendar-name {
                margin-left: 15px;
                color: inherit;
                opacity: 0.8;
            }

            .snab-setup-instructions {
                background: #f8f9fa;
                padding: 15px 20px;
                margin: 15px 0;
                border-left: 4px solid #0073aa;
            }

            .snab-setup-instructions h3 {
                margin-top: 0;
            }

            .snab-setup-instructions ol {
                margin-bottom: 0;
            }

            .snab-setup-instructions li {
                margin-bottom: 8px;
            }

            .snab-redirect-uri {
                display: inline-block;
                padding: 5px 10px;
                background: #fff;
                border: 1px solid #ddd;
                word-break: break-all;
            }

            .snab-copy-uri {
                vertical-align: middle;
                margin-left: 5px;
            }

            .snab-copy-uri .dashicons {
                vertical-align: middle;
            }

            .snab-connection-actions {
                margin: 20px 0;
                padding-top: 15px;
                border-top: 1px solid #eee;
            }

            .snab-connection-actions .dashicons {
                vertical-align: middle;
                margin-right: 3px;
            }

            .snab-calendar-selection {
                margin-top: 20px;
                padding-top: 15px;
                border-top: 1px solid #eee;
            }

            .snab-calendar-selection h3 {
                margin-top: 0;
            }

            .snab-calendar-selector {
                display: flex;
                align-items: center;
                gap: 10px;
            }

            .snab-calendar-selector select {
                min-width: 300px;
            }

            .snab-calendar-selector .spinner {
                float: none;
                margin: 0;
            }

            .snab-settings-form .spinner {
                float: none;
                margin-left: 10px;
                visibility: hidden;
            }

            .snab-settings-form.loading .spinner {
                visibility: visible;
            }

            .snab-version-info {
                background: #f8f9fa;
                border: none;
                box-shadow: none;
            }
        </style>

        <script>
        jQuery(document).ready(function($) {
            var nonce = '<?php echo wp_create_nonce('snab_admin_nonce'); ?>';
            var selectedCalendar = '<?php echo esc_js($this->google_calendar->get_selected_calendar()); ?>';

            // Copy redirect URI
            $('.snab-copy-uri').on('click', function() {
                var uri = $(this).data('uri');
                navigator.clipboard.writeText(uri).then(function() {
                    alert('<?php echo esc_js(__('Redirect URI copied to clipboard!', 'sn-appointment-booking')); ?>');
                });
            });

            // Save credentials
            $('#snab-credentials-form').on('submit', function(e) {
                e.preventDefault();

                var $form = $(this);
                var $button = $('#snab-save-credentials');
                var clientId = $('#snab-client-id').val();
                var clientSecret = $('#snab-client-secret').val();

                // Don't send placeholder
                if (clientSecret === '••••••••') {
                    clientSecret = '';
                }

                $form.addClass('loading');
                $button.prop('disabled', true);

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'snab_save_settings',
                        nonce: nonce,
                        client_id: clientId,
                        client_secret: clientSecret
                    },
                    success: function(response) {
                        if (response.success) {
                            alert(response.data.message);
                            if (response.data.auth_url) {
                                location.reload();
                            }
                        } else {
                            alert(response.data || '<?php echo esc_js(__('An error occurred.', 'sn-appointment-booking')); ?>');
                        }
                    },
                    error: function() {
                        alert('<?php echo esc_js(__('An error occurred. Please try again.', 'sn-appointment-booking')); ?>');
                    },
                    complete: function() {
                        $form.removeClass('loading');
                        $button.prop('disabled', false);
                    }
                });
            });

            // Disconnect
            $('#snab-disconnect-google').on('click', function() {
                if (!confirm('<?php echo esc_js(__('Are you sure you want to disconnect from Google Calendar?', 'sn-appointment-booking')); ?>')) {
                    return;
                }

                var $button = $(this);
                $button.prop('disabled', true);

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'snab_google_disconnect',
                        nonce: nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            location.reload();
                        } else {
                            alert(response.data || '<?php echo esc_js(__('An error occurred.', 'sn-appointment-booking')); ?>');
                            $button.prop('disabled', false);
                        }
                    },
                    error: function() {
                        alert('<?php echo esc_js(__('An error occurred. Please try again.', 'sn-appointment-booking')); ?>');
                        $button.prop('disabled', false);
                    }
                });
            });

            // Load calendars when connected
            <?php if ($status['connected']): ?>
            (function loadCalendars() {
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'snab_get_calendars',
                        nonce: nonce
                    },
                    success: function(response) {
                        var $select = $('#snab-calendar-select');
                        $select.empty();

                        if (response.success && response.data) {
                            response.data.forEach(function(cal) {
                                var label = cal.summary + (cal.primary ? ' (Primary)' : '');
                                var selected = cal.id === selectedCalendar ? 'selected' : '';
                                $select.append('<option value="' + cal.id + '" ' + selected + '>' + label + '</option>');
                            });
                        } else {
                            $select.append('<option value=""><?php echo esc_js(__('Error loading calendars', 'sn-appointment-booking')); ?></option>');
                        }
                    },
                    error: function() {
                        $('#snab-calendar-select').html('<option value=""><?php echo esc_js(__('Error loading calendars', 'sn-appointment-booking')); ?></option>');
                    }
                });
            })();

            // Save calendar selection
            $('#snab-save-calendar').on('click', function() {
                var $button = $(this);
                var $spinner = $button.next('.spinner');
                var calendarId = $('#snab-calendar-select').val();

                if (!calendarId) {
                    alert('<?php echo esc_js(__('Please select a calendar.', 'sn-appointment-booking')); ?>');
                    return;
                }

                $button.prop('disabled', true);
                $spinner.css('visibility', 'visible');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'snab_save_calendar_selection',
                        nonce: nonce,
                        calendar_id: calendarId
                    },
                    success: function(response) {
                        if (response.success) {
                            alert(response.data.message);
                        } else {
                            alert(response.data || '<?php echo esc_js(__('An error occurred.', 'sn-appointment-booking')); ?>');
                        }
                    },
                    error: function() {
                        alert('<?php echo esc_js(__('An error occurred. Please try again.', 'sn-appointment-booking')); ?>');
                    },
                    complete: function() {
                        $button.prop('disabled', false);
                        $spinner.css('visibility', 'hidden');
                    }
                });
            });
            <?php endif; ?>
        });
        </script>
        <?php
    }

    /**
     * Render the Client Portal settings tab.
     *
     * @since 1.5.0
     */
    private function render_client_portal_tab() {
        // Get current settings
        $enable_portal = get_option('snab_enable_client_portal', false);
        $cancel_hours = get_option('snab_cancellation_hours_before', 24);
        $reschedule_hours = get_option('snab_reschedule_hours_before', 24);
        $max_reschedules = get_option('snab_max_reschedules_per_appointment', 0);
        $require_reason = get_option('snab_require_cancel_reason', false);
        $notify_admin = get_option('snab_notify_admin_on_client_changes', true);
        ?>

        <div class="snab-settings-section">
            <h2><?php esc_html_e('Client Portal Settings', 'sn-appointment-booking'); ?></h2>
            <p class="description">
                <?php esc_html_e('Allow logged-in users to view, reschedule, and cancel their own appointments using the [snab_my_appointments] shortcode.', 'sn-appointment-booking'); ?>
            </p>

            <form id="snab-client-portal-form" class="snab-settings-form">
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <?php esc_html_e('Enable Client Portal', 'sn-appointment-booking'); ?>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox" name="enable_client_portal" value="1" <?php checked($enable_portal, true); ?>>
                                <?php esc_html_e('Allow clients to manage their own appointments', 'sn-appointment-booking'); ?>
                            </label>
                            <p class="description">
                                <?php esc_html_e('When enabled, logged-in users can view, reschedule, and cancel their appointments.', 'sn-appointment-booking'); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="snab-cancel-hours"><?php esc_html_e('Cancellation Deadline', 'sn-appointment-booking'); ?></label>
                        </th>
                        <td>
                            <input type="number" id="snab-cancel-hours" name="cancellation_hours_before"
                                   value="<?php echo esc_attr($cancel_hours); ?>"
                                   min="0" max="168" class="small-text"> <?php esc_html_e('hours before appointment', 'sn-appointment-booking'); ?>
                            <p class="description">
                                <?php esc_html_e('Minimum hours before an appointment that a client can cancel. Set to 0 to allow anytime.', 'sn-appointment-booking'); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="snab-reschedule-hours"><?php esc_html_e('Reschedule Deadline', 'sn-appointment-booking'); ?></label>
                        </th>
                        <td>
                            <input type="number" id="snab-reschedule-hours" name="reschedule_hours_before"
                                   value="<?php echo esc_attr($reschedule_hours); ?>"
                                   min="0" max="168" class="small-text"> <?php esc_html_e('hours before appointment', 'sn-appointment-booking'); ?>
                            <p class="description">
                                <?php esc_html_e('Minimum hours before an appointment that a client can reschedule. Set to 0 to allow anytime.', 'sn-appointment-booking'); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="snab-max-reschedules"><?php esc_html_e('Maximum Reschedules', 'sn-appointment-booking'); ?></label>
                        </th>
                        <td>
                            <input type="number" id="snab-max-reschedules" name="max_reschedules_per_appointment"
                                   value="<?php echo esc_attr($max_reschedules); ?>"
                                   min="0" max="10" class="small-text">
                            <p class="description">
                                <?php esc_html_e('Maximum times a client can reschedule an appointment. Set to 0 for unlimited.', 'sn-appointment-booking'); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <?php esc_html_e('Require Cancellation Reason', 'sn-appointment-booking'); ?>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox" name="require_cancel_reason" value="1" <?php checked($require_reason, true); ?>>
                                <?php esc_html_e('Require clients to provide a reason when cancelling', 'sn-appointment-booking'); ?>
                            </label>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <?php esc_html_e('Admin Notifications', 'sn-appointment-booking'); ?>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox" name="notify_admin_on_client_changes" value="1" <?php checked($notify_admin, true); ?>>
                                <?php esc_html_e('Send email notifications when clients reschedule or cancel', 'sn-appointment-booking'); ?>
                            </label>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" class="button button-primary" id="snab-save-portal-settings">
                        <?php esc_html_e('Save Settings', 'sn-appointment-booking'); ?>
                    </button>
                    <span class="spinner"></span>
                </p>
            </form>
        </div>

        <!-- Shortcode Reference -->
        <div class="snab-settings-section">
            <h2><?php esc_html_e('Client Portal Shortcode', 'sn-appointment-booking'); ?></h2>
            <p class="description">
                <?php esc_html_e('Use this shortcode to display the client appointment portal on any page:', 'sn-appointment-booking'); ?>
            </p>
            <code>[snab_my_appointments]</code>

            <h3><?php esc_html_e('Available Attributes', 'sn-appointment-booking'); ?></h3>
            <table class="widefat">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Attribute', 'sn-appointment-booking'); ?></th>
                        <th><?php esc_html_e('Description', 'sn-appointment-booking'); ?></th>
                        <th><?php esc_html_e('Default', 'sn-appointment-booking'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><code>show_past</code></td>
                        <td><?php esc_html_e('Show past appointments in the list.', 'sn-appointment-booking'); ?></td>
                        <td>true</td>
                    </tr>
                    <tr>
                        <td><code>days_past</code></td>
                        <td><?php esc_html_e('Number of days of past appointments to show.', 'sn-appointment-booking'); ?></td>
                        <td>90</td>
                    </tr>
                    <tr>
                        <td><code>allow_cancel</code></td>
                        <td><?php esc_html_e('Show cancel button on appointments.', 'sn-appointment-booking'); ?></td>
                        <td>true</td>
                    </tr>
                    <tr>
                        <td><code>allow_reschedule</code></td>
                        <td><?php esc_html_e('Show reschedule button on appointments.', 'sn-appointment-booking'); ?></td>
                        <td>true</td>
                    </tr>
                    <tr>
                        <td><code>class</code></td>
                        <td><?php esc_html_e('Custom CSS class for the container.', 'sn-appointment-booking'); ?></td>
                        <td><?php esc_html_e('(none)', 'sn-appointment-booking'); ?></td>
                    </tr>
                </tbody>
            </table>

            <h3><?php esc_html_e('Example Usage', 'sn-appointment-booking'); ?></h3>
            <code>[snab_my_appointments show_past="true" days_past="60" allow_cancel="true" allow_reschedule="false"]</code>
        </div>

        <script>
        jQuery(document).ready(function($) {
            var nonce = '<?php echo wp_create_nonce('snab_admin_nonce'); ?>';

            // Save client portal settings
            $('#snab-client-portal-form').on('submit', function(e) {
                e.preventDefault();

                var $form = $(this);
                var $button = $('#snab-save-portal-settings');
                var $spinner = $button.next('.spinner');

                $form.addClass('loading');
                $button.prop('disabled', true);
                $spinner.css('visibility', 'visible');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'snab_save_client_portal_settings',
                        nonce: nonce,
                        enable_client_portal: $('input[name="enable_client_portal"]').is(':checked') ? 1 : 0,
                        cancellation_hours_before: $('input[name="cancellation_hours_before"]').val(),
                        reschedule_hours_before: $('input[name="reschedule_hours_before"]').val(),
                        max_reschedules_per_appointment: $('input[name="max_reschedules_per_appointment"]').val(),
                        require_cancel_reason: $('input[name="require_cancel_reason"]').is(':checked') ? 1 : 0,
                        notify_admin_on_client_changes: $('input[name="notify_admin_on_client_changes"]').is(':checked') ? 1 : 0
                    },
                    success: function(response) {
                        if (response.success) {
                            alert(response.data.message);
                        } else {
                            alert(response.data || '<?php echo esc_js(__('An error occurred.', 'sn-appointment-booking')); ?>');
                        }
                    },
                    error: function() {
                        alert('<?php echo esc_js(__('An error occurred. Please try again.', 'sn-appointment-booking')); ?>');
                    },
                    complete: function() {
                        $form.removeClass('loading');
                        $button.prop('disabled', false);
                        $spinner.css('visibility', 'hidden');
                    }
                });
            });
        });
        </script>
        <?php
    }

    /**
     * Render the Notifications settings tab.
     *
     * @since 1.6.0
     */
    private function render_notifications_tab() {
        // Get current settings
        $notify_new = get_option('snab_notify_new_booking', true);
        $notify_cancel = get_option('snab_notify_cancellation', true);
        $notify_reschedule = get_option('snab_notify_reschedule', true);
        $notify_reminder = get_option('snab_notify_reminder', false);
        $notification_email = get_option('snab_notification_email', get_option('admin_email'));
        $secondary_email = get_option('snab_secondary_notification_email', '');
        $frequency = get_option('snab_notification_frequency', 'instant');
        ?>

        <div class="snab-settings-section">
            <h2><?php esc_html_e('Admin Notification Preferences', 'sn-appointment-booking'); ?></h2>
            <p class="description">
                <?php esc_html_e('Configure which email notifications you want to receive as an administrator.', 'sn-appointment-booking'); ?>
            </p>

            <form id="snab-notifications-form" class="snab-settings-form">
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Notification Types', 'sn-appointment-booking'); ?></th>
                        <td>
                            <fieldset>
                                <label style="display: block; margin-bottom: 8px;">
                                    <input type="checkbox" name="notify_new_booking" value="1" <?php checked($notify_new, true); ?>>
                                    <?php esc_html_e('New appointment booked', 'sn-appointment-booking'); ?>
                                </label>
                                <label style="display: block; margin-bottom: 8px;">
                                    <input type="checkbox" name="notify_cancellation" value="1" <?php checked($notify_cancel, true); ?>>
                                    <?php esc_html_e('Appointment cancelled', 'sn-appointment-booking'); ?>
                                </label>
                                <label style="display: block; margin-bottom: 8px;">
                                    <input type="checkbox" name="notify_reschedule" value="1" <?php checked($notify_reschedule, true); ?>>
                                    <?php esc_html_e('Appointment rescheduled', 'sn-appointment-booking'); ?>
                                </label>
                                <label style="display: block; margin-bottom: 8px;">
                                    <input type="checkbox" name="notify_reminder" value="1" <?php checked($notify_reminder, true); ?>>
                                    <?php esc_html_e('Daily appointment reminders', 'sn-appointment-booking'); ?>
                                </label>
                            </fieldset>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="snab-notification-email"><?php esc_html_e('Primary Email', 'sn-appointment-booking'); ?></label>
                        </th>
                        <td>
                            <input type="email" id="snab-notification-email" name="notification_email"
                                   value="<?php echo esc_attr($notification_email); ?>"
                                   class="regular-text">
                            <p class="description"><?php esc_html_e('Primary email address for admin notifications.', 'sn-appointment-booking'); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="snab-secondary-email"><?php esc_html_e('Secondary Email', 'sn-appointment-booking'); ?></label>
                        </th>
                        <td>
                            <input type="email" id="snab-secondary-email" name="secondary_notification_email"
                                   value="<?php echo esc_attr($secondary_email); ?>"
                                   class="regular-text">
                            <p class="description"><?php esc_html_e('Optional secondary email to receive copies of notifications.', 'sn-appointment-booking'); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="snab-notification-frequency"><?php esc_html_e('Email Frequency', 'sn-appointment-booking'); ?></label>
                        </th>
                        <td>
                            <select id="snab-notification-frequency" name="notification_frequency">
                                <option value="instant" <?php selected($frequency, 'instant'); ?>><?php esc_html_e('Instant (send immediately)', 'sn-appointment-booking'); ?></option>
                                <option value="daily_digest" <?php selected($frequency, 'daily_digest'); ?>><?php esc_html_e('Daily Digest (once per day)', 'sn-appointment-booking'); ?></option>
                            </select>
                            <p class="description"><?php esc_html_e('Choose when to receive notification emails.', 'sn-appointment-booking'); ?></p>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" class="button button-primary" id="snab-save-notifications">
                        <?php esc_html_e('Save Settings', 'sn-appointment-booking'); ?>
                    </button>
                    <span class="spinner"></span>
                </p>
            </form>
        </div>

        <script>
        jQuery(document).ready(function($) {
            var nonce = '<?php echo wp_create_nonce('snab_admin_nonce'); ?>';

            // Save notification settings
            $('#snab-notifications-form').on('submit', function(e) {
                e.preventDefault();

                var $form = $(this);
                var $button = $('#snab-save-notifications');
                var $spinner = $button.next('.spinner');

                $form.addClass('loading');
                $button.prop('disabled', true);
                $spinner.css('visibility', 'visible');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'snab_save_notification_settings',
                        nonce: nonce,
                        notify_new_booking: $('input[name="notify_new_booking"]').is(':checked') ? 1 : 0,
                        notify_cancellation: $('input[name="notify_cancellation"]').is(':checked') ? 1 : 0,
                        notify_reschedule: $('input[name="notify_reschedule"]').is(':checked') ? 1 : 0,
                        notify_reminder: $('input[name="notify_reminder"]').is(':checked') ? 1 : 0,
                        notification_email: $('input[name="notification_email"]').val(),
                        secondary_notification_email: $('input[name="secondary_notification_email"]').val(),
                        notification_frequency: $('select[name="notification_frequency"]').val()
                    },
                    success: function(response) {
                        if (response.success) {
                            alert(response.data.message);
                        } else {
                            alert(response.data || '<?php echo esc_js(__('An error occurred.', 'sn-appointment-booking')); ?>');
                        }
                    },
                    error: function() {
                        alert('<?php echo esc_js(__('An error occurred. Please try again.', 'sn-appointment-booking')); ?>');
                    },
                    complete: function() {
                        $form.removeClass('loading');
                        $button.prop('disabled', false);
                        $spinner.css('visibility', 'hidden');
                    }
                });
            });
        });
        </script>
        <?php
    }

    /**
     * Render the Booking Widget settings tab.
     *
     * @since 1.6.0
     */
    private function render_booking_widget_tab() {
        // Get current settings
        $staff_selection_mode = get_option('snab_staff_selection_mode', 'disabled');
        $show_staff_avatar = get_option('snab_show_staff_avatar', true);
        $show_staff_bio = get_option('snab_show_staff_bio', false);

        // Get active staff count
        global $wpdb;
        $staff_table = $wpdb->prefix . 'snab_staff';
        $active_staff_count = $wpdb->get_var("SELECT COUNT(*) FROM {$staff_table} WHERE is_active = 1");
        ?>

        <div class="snab-settings-section">
            <h2><?php esc_html_e('Booking Widget Settings', 'sn-appointment-booking'); ?></h2>
            <p class="description">
                <?php esc_html_e('Configure how the booking widget appears to your clients.', 'sn-appointment-booking'); ?>
            </p>

            <form id="snab-booking-widget-form" class="snab-settings-form">
                <h3><?php esc_html_e('Staff Selection', 'sn-appointment-booking'); ?></h3>

                <?php if ($active_staff_count <= 1): ?>
                    <div class="notice notice-info inline">
                        <p>
                            <?php esc_html_e('You currently have only one active staff member. Staff selection will automatically be enabled when you add more staff.', 'sn-appointment-booking'); ?>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=snab-staff')); ?>">
                                <?php esc_html_e('Manage Staff', 'sn-appointment-booking'); ?>
                            </a>
                        </p>
                    </div>
                <?php endif; ?>

                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="snab-staff-selection-mode"><?php esc_html_e('Staff Selection Mode', 'sn-appointment-booking'); ?></label>
                        </th>
                        <td>
                            <select id="snab-staff-selection-mode" name="staff_selection_mode" <?php disabled($active_staff_count <= 1); ?>>
                                <option value="disabled" <?php selected($staff_selection_mode, 'disabled'); ?>>
                                    <?php esc_html_e('Disabled - Assign to primary staff automatically', 'sn-appointment-booking'); ?>
                                </option>
                                <option value="optional" <?php selected($staff_selection_mode, 'optional'); ?>>
                                    <?php esc_html_e('Optional - Let clients choose, with "Any Available" option', 'sn-appointment-booking'); ?>
                                </option>
                                <option value="required" <?php selected($staff_selection_mode, 'required'); ?>>
                                    <?php esc_html_e('Required - Clients must select a specific staff member', 'sn-appointment-booking'); ?>
                                </option>
                            </select>
                            <p class="description">
                                <?php esc_html_e('Choose whether clients can select which staff member to book with.', 'sn-appointment-booking'); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <?php esc_html_e('Display Options', 'sn-appointment-booking'); ?>
                        </th>
                        <td>
                            <fieldset>
                                <label style="display: block; margin-bottom: 8px;">
                                    <input type="checkbox" name="show_staff_avatar" value="1" <?php checked($show_staff_avatar, true); ?>>
                                    <?php esc_html_e('Show staff avatar/photo', 'sn-appointment-booking'); ?>
                                </label>
                                <label style="display: block; margin-bottom: 8px;">
                                    <input type="checkbox" name="show_staff_bio" value="1" <?php checked($show_staff_bio, true); ?>>
                                    <?php esc_html_e('Show staff bio/description', 'sn-appointment-booking'); ?>
                                </label>
                            </fieldset>
                            <p class="description">
                                <?php esc_html_e('Choose what information to display about staff members in the booking widget.', 'sn-appointment-booking'); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" class="button button-primary" id="snab-save-booking-widget">
                        <?php esc_html_e('Save Settings', 'sn-appointment-booking'); ?>
                    </button>
                    <span class="spinner"></span>
                </p>
            </form>
        </div>

        <!-- Shortcode Override Info -->
        <div class="snab-settings-section">
            <h2><?php esc_html_e('Shortcode Overrides', 'sn-appointment-booking'); ?></h2>
            <p class="description">
                <?php esc_html_e('You can override these settings per-widget using shortcode attributes:', 'sn-appointment-booking'); ?>
            </p>
            <code>[snab_booking_form staff_selection="optional"]</code>
            <p class="description">
                <?php esc_html_e('Valid values: disabled, optional, required', 'sn-appointment-booking'); ?>
            </p>
        </div>

        <script>
        jQuery(document).ready(function($) {
            var nonce = '<?php echo wp_create_nonce('snab_admin_nonce'); ?>';

            // Save booking widget settings
            $('#snab-booking-widget-form').on('submit', function(e) {
                e.preventDefault();

                var $form = $(this);
                var $button = $('#snab-save-booking-widget');
                var $spinner = $button.next('.spinner');

                $form.addClass('loading');
                $button.prop('disabled', true);
                $spinner.css('visibility', 'visible');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'snab_save_booking_widget_settings',
                        nonce: nonce,
                        staff_selection_mode: $('select[name="staff_selection_mode"]').val(),
                        show_staff_avatar: $('input[name="show_staff_avatar"]').is(':checked') ? 1 : 0,
                        show_staff_bio: $('input[name="show_staff_bio"]').is(':checked') ? 1 : 0
                    },
                    success: function(response) {
                        if (response.success) {
                            alert(response.data.message);
                        } else {
                            alert(response.data || '<?php echo esc_js(__('An error occurred.', 'sn-appointment-booking')); ?>');
                        }
                    },
                    error: function() {
                        alert('<?php echo esc_js(__('An error occurred. Please try again.', 'sn-appointment-booking')); ?>');
                    },
                    complete: function() {
                        $form.removeClass('loading');
                        $button.prop('disabled', false);
                        $spinner.css('visibility', 'hidden');
                    }
                });
            });
        });
        </script>
        <?php
    }
}
