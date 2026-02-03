<?php
/**
 * MLD Notification Analytics Admin Dashboard
 *
 * Provides an admin dashboard for viewing notification delivery and engagement metrics.
 *
 * @package MLS_Listings_Display
 * @subpackage Notifications
 * @since 6.48.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class MLD_Notification_Analytics_Dashboard
 *
 * Registers admin menu, enqueues assets, and renders the notification analytics dashboard.
 */
class MLD_Notification_Analytics_Dashboard {

    /**
     * Singleton instance
     *
     * @var MLD_Notification_Analytics_Dashboard
     */
    private static $instance = null;

    /**
     * Dashboard page hook suffix
     *
     * @var string
     */
    private $page_hook;

    /**
     * Get singleton instance
     *
     * @return MLD_Notification_Analytics_Dashboard
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor
     */
    private function __construct() {}

    /**
     * Initialize the dashboard
     */
    public static function init() {
        $instance = self::get_instance();

        // Register admin menu (priority 21 to appear after Site Analytics)
        add_action('admin_menu', array($instance, 'register_admin_menu'), 21);

        // Enqueue assets only on our dashboard page
        add_action('admin_enqueue_scripts', array($instance, 'enqueue_assets'));
    }

    /**
     * Register admin menu
     */
    public function register_admin_menu() {
        $this->page_hook = add_submenu_page(
            'mls_listings_display',               // Parent slug
            'Notification Analytics',              // Page title
            'Notification Analytics',              // Menu title
            'manage_options',                      // Capability
            'mld-notification-analytics',          // Menu slug
            array($this, 'render_dashboard')       // Callback
        );
    }

    /**
     * Enqueue dashboard assets
     *
     * @param string $hook Current admin page hook
     */
    public function enqueue_assets($hook) {
        // Only load on our dashboard page
        if ($hook !== $this->page_hook) {
            return;
        }

        // Chart.js from CDN
        wp_enqueue_script(
            'chartjs',
            'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js',
            array(),
            '4.4.1',
            true
        );

        // Dashboard JavaScript
        wp_enqueue_script(
            'mld-notification-analytics-dashboard',
            MLD_PLUGIN_URL . 'assets/js/admin/mld-notification-analytics-dashboard.js',
            array('jquery', 'chartjs'),
            MLD_VERSION,
            true
        );

        // Dashboard CSS
        wp_enqueue_style(
            'mld-notification-analytics-dashboard',
            MLD_PLUGIN_URL . 'assets/css/admin/mld-notification-analytics-dashboard.css',
            array(),
            MLD_VERSION
        );

        // Localize script with API URL and nonce
        wp_localize_script('mld-notification-analytics-dashboard', 'mldNotificationAnalytics', array(
            'apiUrl' => rest_url('mld-mobile/v1'),
            'nonce' => wp_create_nonce('wp_rest'),
            'refreshInterval' => 60000, // 1 minute refresh
        ));
    }

    /**
     * Render the dashboard
     */
    public function render_dashboard() {
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        // Include the dashboard template
        include MLD_PLUGIN_PATH . 'includes/notifications/admin/views/notification-analytics-dashboard.php';
    }
}
