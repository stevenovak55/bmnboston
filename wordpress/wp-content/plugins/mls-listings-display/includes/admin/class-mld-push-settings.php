<?php
/**
 * MLD Push Notification Settings
 *
 * Admin settings page for Apple Push Notification service (APNs) configuration.
 *
 * @package MLS_Listings_Display
 * @subpackage Admin
 * @since 6.31.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Push_Settings {

    /**
     * Singleton instance
     */
    private static $instance = null;

    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_styles']);
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_options_page(
            'Push Notifications',
            'Push Notifications',
            'manage_options',
            'mld-push-settings',
            [$this, 'render_settings_page']
        );
    }

    /**
     * Register settings
     */
    public function register_settings() {
        // Register settings
        register_setting('mld_push_settings', 'mld_apns_key_id', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field'
        ]);
        register_setting('mld_push_settings', 'mld_apns_team_id', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field'
        ]);
        register_setting('mld_push_settings', 'mld_apns_private_key', [
            'type' => 'string',
            'sanitize_callback' => [$this, 'sanitize_private_key']
        ]);
        register_setting('mld_push_settings', 'mld_apns_bundle_id', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => 'com.bmnboston.app'
        ]);
        register_setting('mld_push_settings', 'mld_apns_environment', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => 'production'
        ]);

        // Add settings section
        add_settings_section(
            'mld_apns_section',
            'Apple Push Notification Service (APNs)',
            [$this, 'render_section_intro'],
            'mld-push-settings'
        );

        // Add settings fields
        add_settings_field(
            'mld_apns_key_id',
            'Key ID',
            [$this, 'render_key_id_field'],
            'mld-push-settings',
            'mld_apns_section'
        );

        add_settings_field(
            'mld_apns_team_id',
            'Team ID',
            [$this, 'render_team_id_field'],
            'mld-push-settings',
            'mld_apns_section'
        );

        add_settings_field(
            'mld_apns_private_key',
            'Private Key (.p8)',
            [$this, 'render_private_key_field'],
            'mld-push-settings',
            'mld_apns_section'
        );

        add_settings_field(
            'mld_apns_bundle_id',
            'Bundle ID',
            [$this, 'render_bundle_id_field'],
            'mld-push-settings',
            'mld_apns_section'
        );

        add_settings_field(
            'mld_apns_environment',
            'Environment',
            [$this, 'render_environment_field'],
            'mld-push-settings',
            'mld_apns_section'
        );
    }

    /**
     * Sanitize private key
     */
    public function sanitize_private_key($value) {
        // Preserve newlines in the private key
        return wp_kses_post($value);
    }

    /**
     * Enqueue admin styles
     */
    public function enqueue_admin_styles($hook) {
        if ($hook !== 'settings_page_mld-push-settings') {
            return;
        }

        wp_add_inline_style('wp-admin', '
            .mld-push-status {
                display: inline-flex;
                align-items: center;
                gap: 8px;
                padding: 10px 15px;
                border-radius: 4px;
                margin-bottom: 20px;
            }
            .mld-push-status.configured {
                background: #d4edda;
                border: 1px solid #c3e6cb;
                color: #155724;
            }
            .mld-push-status.not-configured {
                background: #fff3cd;
                border: 1px solid #ffeeba;
                color: #856404;
            }
            .mld-push-stats {
                display: grid;
                grid-template-columns: repeat(3, 1fr);
                gap: 20px;
                margin: 20px 0;
            }
            .mld-push-stat {
                background: #f8f9fa;
                border: 1px solid #dee2e6;
                border-radius: 8px;
                padding: 20px;
                text-align: center;
            }
            .mld-push-stat-value {
                font-size: 32px;
                font-weight: bold;
                color: #0073aa;
            }
            .mld-push-stat-label {
                color: #666;
                margin-top: 5px;
            }
            .mld-private-key-field {
                font-family: monospace;
                font-size: 12px;
            }
            .mld-help-text {
                color: #666;
                font-style: italic;
                margin-top: 5px;
            }
        ');
    }

    /**
     * Render settings page
     */
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Check for test notification action
        if (isset($_GET['test_push']) && isset($_GET['user_id'])) {
            $this->handle_test_push();
        }

        // Load push notifications class
        $push_file = plugin_dir_path(__FILE__) . '../notifications/class-mld-push-notifications.php';
        if (file_exists($push_file) && !class_exists('MLD_Push_Notifications')) {
            require_once $push_file;
        }

        $is_configured = class_exists('MLD_Push_Notifications') && MLD_Push_Notifications::is_configured();
        $device_count = $is_configured ? MLD_Push_Notifications::get_total_device_count() : 0;
        ?>
        <div class="wrap">
            <h1>Push Notification Settings</h1>

            <?php if ($is_configured): ?>
                <div class="mld-push-status configured">
                    <span class="dashicons dashicons-yes-alt"></span>
                    APNs is configured and ready to send notifications
                </div>
            <?php else: ?>
                <div class="mld-push-status not-configured">
                    <span class="dashicons dashicons-warning"></span>
                    APNs is not fully configured. Fill in all fields below.
                </div>
            <?php endif; ?>

            <?php if ($is_configured): ?>
            <div class="mld-push-stats">
                <div class="mld-push-stat">
                    <div class="mld-push-stat-value"><?php echo esc_html($device_count); ?></div>
                    <div class="mld-push-stat-label">Registered Devices</div>
                </div>
                <div class="mld-push-stat">
                    <div class="mld-push-stat-value"><?php echo esc_html(get_option('mld_apns_environment', 'production')); ?></div>
                    <div class="mld-push-stat-label">Environment</div>
                </div>
                <div class="mld-push-stat">
                    <div class="mld-push-stat-value"><?php echo $is_configured ? '✓' : '✗'; ?></div>
                    <div class="mld-push-stat-label">Status</div>
                </div>
            </div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php
                settings_fields('mld_push_settings');
                do_settings_sections('mld-push-settings');
                submit_button();
                ?>
            </form>

            <?php if ($is_configured): ?>
            <hr>
            <h2>Test Push Notification</h2>
            <p>Send a test notification to verify the configuration is working.</p>
            <form method="get">
                <input type="hidden" name="page" value="mld-push-settings">
                <input type="hidden" name="test_push" value="1">
                <table class="form-table">
                    <tr>
                        <th scope="row">User ID</th>
                        <td>
                            <input type="number" name="user_id" value="<?php echo get_current_user_id(); ?>" class="regular-text">
                            <p class="description">Enter the WordPress user ID to send a test notification to.</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Send Test Notification', 'secondary'); ?>
            </form>
            <?php endif; ?>

            <hr>
            <h2>Setup Instructions</h2>
            <ol>
                <li><strong>Create APNs Key:</strong>
                    <ol>
                        <li>Go to <a href="https://developer.apple.com/account/resources/authkeys/list" target="_blank">Apple Developer → Keys</a></li>
                        <li>Click "+" to create a new key</li>
                        <li>Enter a key name (e.g., "BMN Boston Push Key")</li>
                        <li>Check "Apple Push Notifications service (APNs)"</li>
                        <li>Click "Continue" then "Register"</li>
                        <li>Download the .p8 file (you can only download it once!)</li>
                        <li>Copy the Key ID shown on the page</li>
                    </ol>
                </li>
                <li><strong>Get Your Team ID:</strong>
                    <ol>
                        <li>Go to <a href="https://developer.apple.com/account" target="_blank">Apple Developer Account</a></li>
                        <li>Your Team ID is shown in the top right, or go to "Membership" section</li>
                    </ol>
                </li>
                <li><strong>Enter Credentials Above:</strong>
                    <ul>
                        <li>Key ID: The 10-character ID shown when you created the key</li>
                        <li>Team ID: Your 10-character Apple Developer Team ID</li>
                        <li>Private Key: Open the .p8 file in a text editor and paste the entire contents including -----BEGIN/END PRIVATE KEY-----</li>
                        <li>Bundle ID: Your app's bundle identifier (com.bmnboston.app)</li>
                    </ul>
                </li>
            </ol>
        </div>
        <?php
    }

    /**
     * Handle test push
     */
    private function handle_test_push() {
        $user_id = intval($_GET['user_id']);

        if ($user_id < 1) {
            add_settings_error('mld_push_settings', 'invalid_user', 'Invalid user ID.', 'error');
            return;
        }

        $push_file = plugin_dir_path(__FILE__) . '../notifications/class-mld-push-notifications.php';
        if (file_exists($push_file) && !class_exists('MLD_Push_Notifications')) {
            require_once $push_file;
        }

        if (!class_exists('MLD_Push_Notifications')) {
            add_settings_error('mld_push_settings', 'class_not_found', 'Push notifications class not found.', 'error');
            return;
        }

        $result = MLD_Push_Notifications::send_test($user_id);

        if ($result['success']) {
            add_settings_error(
                'mld_push_settings',
                'test_success',
                sprintf('Test notification sent successfully! Sent: %d, Failed: %d', $result['sent_count'], $result['failed_count']),
                'success'
            );
        } else {
            add_settings_error(
                'mld_push_settings',
                'test_failed',
                'Test notification failed: ' . implode(', ', $result['errors']),
                'error'
            );
        }
    }

    /**
     * Render section intro
     */
    public function render_section_intro() {
        echo '<p>Configure credentials for sending push notifications to the iOS app. These credentials are obtained from the <a href="https://developer.apple.com" target="_blank">Apple Developer Portal</a>.</p>';
    }

    /**
     * Render Key ID field
     */
    public function render_key_id_field() {
        $value = get_option('mld_apns_key_id', '');
        ?>
        <input type="text" name="mld_apns_key_id" value="<?php echo esc_attr($value); ?>" class="regular-text" placeholder="ABCDEF1234">
        <p class="mld-help-text">10-character Key ID from Apple Developer Portal</p>
        <?php
    }

    /**
     * Render Team ID field
     */
    public function render_team_id_field() {
        $value = get_option('mld_apns_team_id', '');
        ?>
        <input type="text" name="mld_apns_team_id" value="<?php echo esc_attr($value); ?>" class="regular-text" placeholder="TEAMID1234">
        <p class="mld-help-text">10-character Team ID from Apple Developer Portal (TH87BB2YU9)</p>
        <?php
    }

    /**
     * Render Private Key field
     */
    public function render_private_key_field() {
        $value = get_option('mld_apns_private_key', '');
        $has_key = !empty($value);
        ?>
        <textarea name="mld_apns_private_key" rows="10" cols="50" class="mld-private-key-field" placeholder="-----BEGIN PRIVATE KEY-----
...
-----END PRIVATE KEY-----"><?php echo esc_textarea($value); ?></textarea>
        <?php if ($has_key): ?>
            <p class="mld-help-text" style="color: green;">✓ Private key is set</p>
        <?php else: ?>
            <p class="mld-help-text">Paste the entire contents of your .p8 file including the BEGIN/END lines</p>
        <?php endif; ?>
        <?php
    }

    /**
     * Render Bundle ID field
     */
    public function render_bundle_id_field() {
        $value = get_option('mld_apns_bundle_id', 'com.bmnboston.app');
        ?>
        <input type="text" name="mld_apns_bundle_id" value="<?php echo esc_attr($value); ?>" class="regular-text">
        <p class="mld-help-text">Your iOS app's bundle identifier (default: com.bmnboston.app)</p>
        <?php
    }

    /**
     * Render Environment field
     */
    public function render_environment_field() {
        $value = get_option('mld_apns_environment', 'production');
        ?>
        <select name="mld_apns_environment">
            <option value="production" <?php selected($value, 'production'); ?>>Production</option>
            <option value="sandbox" <?php selected($value, 'sandbox'); ?>>Sandbox (Development)</option>
        </select>
        <p class="mld-help-text">Use Sandbox for TestFlight/development builds, Production for App Store builds</p>
        <?php
    }
}

// Initialize
MLD_Push_Settings::get_instance();
