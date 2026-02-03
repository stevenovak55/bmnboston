<?php
/**
 * Notification Frontend
 *
 * Handles frontend rendering of the web notification center:
 * - Enqueues JavaScript and CSS
 * - Renders bell icon with badge in header
 * - Provides data for JavaScript via localization
 *
 * @package MLS_Listings_Display
 * @subpackage Notifications
 * @since 6.50.9
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Notification_Frontend {

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
     * Private constructor
     */
    private function __construct() {
        // Initialize
    }

    /**
     * Initialize hooks
     */
    public static function init() {
        $instance = self::get_instance();

        // Enqueue scripts and styles
        add_action('wp_enqueue_scripts', array($instance, 'enqueue_assets'));

        // Add bell icon to header - fixed position
        add_action('wp_body_open', array($instance, 'render_bell_container'), 5);

        // Add shortcode for manual placement
        add_shortcode('mld_notification_bell', array($instance, 'shortcode_bell'));

        // Add shortcode for full notifications page
        add_shortcode('mld_notifications_page', array(__CLASS__, 'render_notifications_page'));

        // Add shortcode for notification settings page
        add_shortcode('mld_notification_settings', array(__CLASS__, 'render_preferences_page'));
    }

    /**
     * Enqueue frontend assets
     */
    public function enqueue_assets() {
        // Only enqueue for logged-in users
        if (!is_user_logged_in()) {
            return;
        }

        // CSS
        wp_enqueue_style(
            'mld-notification-center',
            MLD_PLUGIN_URL . 'assets/css/notification-center.css',
            array(),
            MLD_VERSION
        );

        // JavaScript
        wp_enqueue_script(
            'mld-notification-center',
            MLD_PLUGIN_URL . 'assets/js/notification-center.js',
            array('jquery'),
            MLD_VERSION,
            true
        );

        // Pass data to JavaScript
        wp_localize_script('mld-notification-center', 'mldNotifications', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('mld_notification_nonce'),
            'pollInterval' => 15000, // Poll every 15 seconds (reduced from 60s for faster updates)
            'notificationsPageUrl' => home_url('/notifications/'),
            'settingsPageUrl' => home_url('/notification-settings/'),
            'strings' => array(
                'notifications' => __('Notifications', 'mls-listings-display'),
                'markAllRead' => __('Mark all read', 'mls-listings-display'),
                'viewAll' => __('View All', 'mls-listings-display'),
                'settings' => __('Settings', 'mls-listings-display'),
                'noNotifications' => __('No notifications', 'mls-listings-display'),
                'loading' => __('Loading...', 'mls-listings-display'),
                'error' => __('Failed to load notifications', 'mls-listings-display'),
            ),
        ));
    }

    /**
     * Check if current page should hide the notification bell
     * Hide on property detail pages and map search pages (where chatbot is hidden or UI is cluttered)
     *
     * @return bool True if bell should be hidden
     */
    private function should_hide_bell() {
        global $post;

        // Hide on property detail pages (detected by mls_number query var)
        if (get_query_var('mls_number', false) !== false) {
            return true;
        }

        // Hide on pages with map shortcodes
        if (is_a($post, 'WP_Post')) {
            $map_shortcodes = array(
                'mld_full_map',
                'mld_half_map',
                'mld_map_full',
                'mld_map_half',
                'bme_full_map',
                'bme_half_map',
                'bme_listings_map_view',
                'bme_listings_half_map_view',
            );

            foreach ($map_shortcodes as $shortcode) {
                if (has_shortcode($post->post_content, $shortcode)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Render the bell icon container
     * This is a hidden container that JavaScript will position correctly
     */
    public function render_bell_container() {
        if (!is_user_logged_in()) {
            return;
        }

        // Hide bell on property detail and map search pages
        if ($this->should_hide_bell()) {
            return;
        }

        // Get initial unread count
        $unread_count = $this->get_unread_count();

        echo $this->get_bell_html($unread_count);
    }

    /**
     * Add bell icon to navigation menu
     *
     * @param string $items Menu items HTML
     * @param object $args Menu arguments
     * @return string Modified menu items HTML
     */
    public function add_bell_to_menu($items, $args) {
        // Only add to primary/header menu
        if (!is_user_logged_in()) {
            return $items;
        }

        // Check if this is a header menu (common menu locations)
        $header_menus = array('primary', 'main', 'header', 'top', 'main-menu', 'primary-menu');
        if (!isset($args->theme_location) || !in_array($args->theme_location, $header_menus)) {
            return $items;
        }

        $unread_count = $this->get_unread_count();
        $bell_item = '<li class="menu-item mld-notification-menu-item">' . $this->get_bell_html($unread_count, false) . '</li>';

        return $items . $bell_item;
    }

    /**
     * Shortcode for bell icon
     *
     * @param array $atts Shortcode attributes
     * @return string Bell icon HTML
     */
    public function shortcode_bell($atts) {
        if (!is_user_logged_in()) {
            return '';
        }

        $unread_count = $this->get_unread_count();
        return $this->get_bell_html($unread_count);
    }

    /**
     * Get bell icon HTML
     *
     * @param int $unread_count Unread notification count
     * @param bool $include_container Include outer container
     * @return string Bell icon HTML
     */
    private function get_bell_html($unread_count = 0, $include_container = true) {
        $badge_class = $unread_count > 0 ? '' : ' mld-badge-hidden';
        $badge_text = $unread_count > 99 ? '99+' : $unread_count;

        $bell_svg = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
            <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
        </svg>';

        $html = '
        <div class="mld-notification-bell" id="mld-notification-bell">
            <button class="mld-bell-button" id="mld-bell-button" aria-label="' . esc_attr__('Notifications', 'mls-listings-display') . '" aria-expanded="false" aria-haspopup="true">
                ' . $bell_svg . '
                <span class="mld-badge' . $badge_class . '" id="mld-bell-badge" data-count="' . esc_attr($unread_count) . '">' . esc_html($badge_text) . '</span>
            </button>
            <div class="mld-notification-dropdown" id="mld-notification-dropdown" role="menu" aria-hidden="true">
                <div class="mld-dropdown-header">
                    <h3>' . esc_html__('Notifications', 'mls-listings-display') . '</h3>
                    <button class="mld-mark-all-read" id="mld-mark-all-read">' . esc_html__('Mark all read', 'mls-listings-display') . '</button>
                </div>
                <div class="mld-notification-list" id="mld-notification-list">
                    <div class="mld-notification-loading">' . esc_html__('Loading...', 'mls-listings-display') . '</div>
                </div>
                <div class="mld-dropdown-footer">
                    <a href="' . esc_url(home_url('/notifications/')) . '">' . esc_html__('View All', 'mls-listings-display') . '</a>
                    <a href="' . esc_url(home_url('/notification-settings/')) . '">' . esc_html__('Settings', 'mls-listings-display') . '</a>
                </div>
            </div>
        </div>';

        if ($include_container) {
            $html = '<div class="mld-notification-bell-container">' . $html . '</div>';
        }

        return $html;
    }

    /**
     * Get unread notification count for current user
     *
     * @return int Unread count
     */
    private function get_unread_count() {
        global $wpdb;

        $user_id = get_current_user_id();
        if (!$user_id) {
            return 0;
        }

        $table_name = $wpdb->prefix . 'mld_push_notification_log';

        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") !== $table_name) {
            return 0;
        }

        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT id) FROM {$table_name}
             WHERE user_id = %d AND status IN ('sent', 'failed')
             AND (is_read = 0 OR is_read IS NULL)
             AND (is_dismissed = 0 OR is_dismissed IS NULL)",
            $user_id
        ));

        return (int) $count;
    }

    /**
     * Render full notifications page content
     * Used by the /notifications/ page template
     *
     * @return string Page HTML
     */
    public static function render_notifications_page() {
        if (!is_user_logged_in()) {
            return '<p>' . esc_html__('Please log in to view your notifications.', 'mls-listings-display') . '</p>';
        }

        ob_start();
        ?>
        <div class="mld-notifications-page" id="mld-notifications-page">
            <div class="mld-notifications-header">
                <h1><?php esc_html_e('Notifications', 'mls-listings-display'); ?></h1>
                <div class="mld-notifications-actions">
                    <button class="mld-btn mld-btn-secondary" id="mld-page-mark-all-read">
                        <?php esc_html_e('Mark All as Read', 'mls-listings-display'); ?>
                    </button>
                    <button class="mld-btn mld-btn-secondary" id="mld-page-clear-all">
                        <?php esc_html_e('Clear All', 'mls-listings-display'); ?>
                    </button>
                    <a href="<?php echo esc_url(home_url('/notification-settings/')); ?>" class="mld-btn mld-btn-link">
                        <?php esc_html_e('Settings', 'mls-listings-display'); ?>
                    </a>
                </div>
            </div>

            <div class="mld-notifications-filters">
                <button class="mld-filter-btn active" data-filter="all"><?php esc_html_e('All', 'mls-listings-display'); ?></button>
                <button class="mld-filter-btn" data-filter="unread"><?php esc_html_e('Unread', 'mls-listings-display'); ?></button>
                <button class="mld-filter-btn" data-filter="property"><?php esc_html_e('Properties', 'mls-listings-display'); ?></button>
                <button class="mld-filter-btn" data-filter="search"><?php esc_html_e('Searches', 'mls-listings-display'); ?></button>
            </div>

            <div class="mld-notifications-list-full" id="mld-notifications-list-full">
                <div class="mld-notification-loading"><?php esc_html_e('Loading notifications...', 'mls-listings-display'); ?></div>
            </div>

            <div class="mld-notifications-pagination" id="mld-notifications-pagination" style="display: none;">
                <button class="mld-btn mld-btn-secondary" id="mld-load-more">
                    <?php esc_html_e('Load More', 'mls-listings-display'); ?>
                </button>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render notification preferences page content
     * Used by the /notification-settings/ page template
     *
     * @return string Page HTML
     */
    public static function render_preferences_page() {
        if (!is_user_logged_in()) {
            return '<p>' . esc_html__('Please log in to manage your notification preferences.', 'mls-listings-display') . '</p>';
        }

        ob_start();
        ?>
        <div class="mld-notification-settings-page" id="mld-notification-settings-page">
            <div class="mld-settings-header">
                <h1><?php esc_html_e('Notification Settings', 'mls-listings-display'); ?></h1>
                <p><?php esc_html_e('Choose how and when you receive notifications.', 'mls-listings-display'); ?></p>
            </div>

            <div class="mld-settings-section">
                <h2><?php esc_html_e('Property Alerts', 'mls-listings-display'); ?></h2>

                <div class="mld-setting-row">
                    <div class="mld-setting-info">
                        <h3><?php esc_html_e('New Listings', 'mls-listings-display'); ?></h3>
                        <p><?php esc_html_e('Get notified when new properties match your saved searches.', 'mls-listings-display'); ?></p>
                    </div>
                    <div class="mld-setting-toggles">
                        <label class="mld-toggle">
                            <input type="checkbox" data-type="new_listing" data-channel="push" checked>
                            <span class="mld-toggle-slider"></span>
                            <span class="mld-toggle-label"><?php esc_html_e('Push', 'mls-listings-display'); ?></span>
                        </label>
                        <label class="mld-toggle">
                            <input type="checkbox" data-type="new_listing" data-channel="email" checked>
                            <span class="mld-toggle-slider"></span>
                            <span class="mld-toggle-label"><?php esc_html_e('Email', 'mls-listings-display'); ?></span>
                        </label>
                    </div>
                </div>

                <div class="mld-setting-row">
                    <div class="mld-setting-info">
                        <h3><?php esc_html_e('Price Changes', 'mls-listings-display'); ?></h3>
                        <p><?php esc_html_e('Get notified when prices change on your favorited properties.', 'mls-listings-display'); ?></p>
                    </div>
                    <div class="mld-setting-toggles">
                        <label class="mld-toggle">
                            <input type="checkbox" data-type="price_change" data-channel="push" checked>
                            <span class="mld-toggle-slider"></span>
                            <span class="mld-toggle-label"><?php esc_html_e('Push', 'mls-listings-display'); ?></span>
                        </label>
                        <label class="mld-toggle">
                            <input type="checkbox" data-type="price_change" data-channel="email" checked>
                            <span class="mld-toggle-slider"></span>
                            <span class="mld-toggle-label"><?php esc_html_e('Email', 'mls-listings-display'); ?></span>
                        </label>
                    </div>
                </div>

                <div class="mld-setting-row">
                    <div class="mld-setting-info">
                        <h3><?php esc_html_e('Status Changes', 'mls-listings-display'); ?></h3>
                        <p><?php esc_html_e('Get notified when properties go pending or sold.', 'mls-listings-display'); ?></p>
                    </div>
                    <div class="mld-setting-toggles">
                        <label class="mld-toggle">
                            <input type="checkbox" data-type="status_change" data-channel="push" checked>
                            <span class="mld-toggle-slider"></span>
                            <span class="mld-toggle-label"><?php esc_html_e('Push', 'mls-listings-display'); ?></span>
                        </label>
                        <label class="mld-toggle">
                            <input type="checkbox" data-type="status_change" data-channel="email" checked>
                            <span class="mld-toggle-slider"></span>
                            <span class="mld-toggle-label"><?php esc_html_e('Email', 'mls-listings-display'); ?></span>
                        </label>
                    </div>
                </div>
            </div>

            <div class="mld-settings-section">
                <h2><?php esc_html_e('Other Notifications', 'mls-listings-display'); ?></h2>

                <div class="mld-setting-row">
                    <div class="mld-setting-info">
                        <h3><?php esc_html_e('Appointment Reminders', 'mls-listings-display'); ?></h3>
                        <p><?php esc_html_e('Reminders for scheduled tours and showings.', 'mls-listings-display'); ?></p>
                    </div>
                    <div class="mld-setting-toggles">
                        <label class="mld-toggle">
                            <input type="checkbox" data-type="appointment" data-channel="push" checked>
                            <span class="mld-toggle-slider"></span>
                            <span class="mld-toggle-label"><?php esc_html_e('Push', 'mls-listings-display'); ?></span>
                        </label>
                        <label class="mld-toggle">
                            <input type="checkbox" data-type="appointment" data-channel="email" checked>
                            <span class="mld-toggle-slider"></span>
                            <span class="mld-toggle-label"><?php esc_html_e('Email', 'mls-listings-display'); ?></span>
                        </label>
                    </div>
                </div>
            </div>

            <div class="mld-settings-footer">
                <p class="mld-settings-note">
                    <?php esc_html_e('Changes are saved automatically.', 'mls-listings-display'); ?>
                </p>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}
