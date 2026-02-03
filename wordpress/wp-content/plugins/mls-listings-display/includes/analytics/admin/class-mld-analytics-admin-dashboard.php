<?php
/**
 * MLD Analytics Admin Dashboard
 *
 * Provides a real-time analytics dashboard for site administrators
 * showing visitor activity across both web and iOS platforms.
 *
 * @package MLS_Listings_Display
 * @subpackage Analytics
 * @since 6.39.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class MLD_Analytics_Admin_Dashboard
 *
 * Registers admin menu, enqueues assets, and renders the analytics dashboard.
 */
class MLD_Analytics_Admin_Dashboard {

    /**
     * Singleton instance
     *
     * @var MLD_Analytics_Admin_Dashboard
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
     * @return MLD_Analytics_Admin_Dashboard
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

        // Register admin menu (priority 20 to ensure parent menu exists)
        add_action('admin_menu', array($instance, 'register_admin_menu'), 20);

        // Enqueue assets only on our dashboard page
        add_action('admin_enqueue_scripts', array($instance, 'enqueue_assets'));
    }

    /**
     * Register admin menu
     */
    public function register_admin_menu() {
        $this->page_hook = add_submenu_page(
            'mls_listings_display',           // Parent slug (underscores, not hyphens)
            'Site Analytics',                  // Page title
            'Site Analytics',                  // Menu title
            'manage_options',                  // Capability
            'mld-site-analytics',             // Menu slug
            array($this, 'render_dashboard')  // Callback
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

        // Chart.js date adapter
        wp_enqueue_script(
            'chartjs-adapter-date',
            'https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns@3.0.0/dist/chartjs-adapter-date-fns.bundle.min.js',
            array('chartjs'),
            '3.0.0',
            true
        );

        // Dashboard JavaScript
        wp_enqueue_script(
            'mld-analytics-dashboard',
            MLD_PLUGIN_URL . 'assets/js/admin/mld-analytics-dashboard.js',
            array('jquery', 'chartjs'),
            MLD_VERSION,
            true
        );

        // Dashboard CSS
        wp_enqueue_style(
            'mld-analytics-dashboard',
            MLD_PLUGIN_URL . 'assets/css/admin/mld-analytics-dashboard.css',
            array(),
            MLD_VERSION
        );

        // Pass configuration to JavaScript
        wp_localize_script('mld-analytics-dashboard', 'mldAnalytics', array(
            'ajaxUrl'      => admin_url('admin-ajax.php'),
            'restUrl'      => rest_url('mld-analytics/v1/admin/'),
            'siteUrl'      => home_url(),
            'nonce'        => wp_create_nonce('wp_rest'),
            'refreshRate'  => 15000, // 15 seconds
            'timezone'     => wp_timezone_string(),
            'dateFormat'   => get_option('date_format'),
            'timeFormat'   => get_option('time_format'),
            'i18n'         => array(
                'sessions'      => __('Sessions', 'mls-listings-display'),
                'pageViews'     => __('Page Views', 'mls-listings-display'),
                'propertyViews' => __('Property Views', 'mls-listings-display'),
                'searches'      => __('Searches', 'mls-listings-display'),
                'noData'        => __('No data available', 'mls-listings-display'),
                'loading'       => __('Loading...', 'mls-listings-display'),
                'error'         => __('Error loading data', 'mls-listings-display'),
                'activeNow'     => __('Active Now', 'mls-listings-display'),
                'web'           => __('Web', 'mls-listings-display'),
                'ios'           => __('iOS App', 'mls-listings-display'),
            ),
        ));
    }

    /**
     * Render the dashboard
     */
    public function render_dashboard() {
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to access this page.', 'mls-listings-display'));
        }

        // Include the dashboard view
        include MLD_PLUGIN_PATH . 'includes/analytics/admin/views/analytics-dashboard.php';
    }

    /**
     * Get quick stats for initial page load
     *
     * @return array
     */
    public function get_quick_stats() {
        $db = MLD_Public_Analytics_Database::get_instance();

        // Today's date range (WordPress timezone)
        $today_start = current_time('Y-m-d 00:00:00');
        $today_end = current_time('Y-m-d 23:59:59');

        // Yesterday for comparison
        $yesterday_start = date('Y-m-d 00:00:00', strtotime('-1 day', strtotime($today_start)));
        $yesterday_end = date('Y-m-d 23:59:59', strtotime('-1 day', strtotime($today_end)));

        global $wpdb;

        // Today's session stats (sessions that started today)
        $today_sessions = $wpdb->get_row($wpdb->prepare(
            "SELECT
                COUNT(DISTINCT session_id) as sessions
            FROM {$wpdb->prefix}mld_public_sessions
            WHERE first_seen BETWEEN %s AND %s",
            $today_start, $today_end
        ), ARRAY_A);

        // Today's event stats - query events directly for accurate counts (v6.45.3 fix)
        // Events use event_timestamp which is WordPress timezone
        $today_events = $wpdb->get_row($wpdb->prepare(
            "SELECT
                SUM(CASE WHEN event_type = 'page_view' THEN 1 ELSE 0 END) as page_views,
                SUM(CASE WHEN event_type = 'property_view' THEN 1 ELSE 0 END) as property_views,
                SUM(CASE WHEN event_type IN ('search', 'search_execute') THEN 1 ELSE 0 END) as searches
            FROM {$wpdb->prefix}mld_public_events
            WHERE event_timestamp BETWEEN %s AND %s",
            $today_start, $today_end
        ), ARRAY_A);

        $today = array(
            'sessions' => $today_sessions['sessions'] ?? 0,
            'page_views' => $today_events['page_views'] ?? 0,
            'property_views' => $today_events['property_views'] ?? 0,
            'searches' => $today_events['searches'] ?? 0,
        );

        // Yesterday's session stats
        $yesterday_sessions = $wpdb->get_row($wpdb->prepare(
            "SELECT
                COUNT(DISTINCT session_id) as sessions
            FROM {$wpdb->prefix}mld_public_sessions
            WHERE first_seen BETWEEN %s AND %s",
            $yesterday_start, $yesterday_end
        ), ARRAY_A);

        // Yesterday's event stats
        $yesterday_events = $wpdb->get_row($wpdb->prepare(
            "SELECT
                SUM(CASE WHEN event_type = 'page_view' THEN 1 ELSE 0 END) as page_views,
                SUM(CASE WHEN event_type = 'property_view' THEN 1 ELSE 0 END) as property_views,
                SUM(CASE WHEN event_type IN ('search', 'search_execute') THEN 1 ELSE 0 END) as searches
            FROM {$wpdb->prefix}mld_public_events
            WHERE event_timestamp BETWEEN %s AND %s",
            $yesterday_start, $yesterday_end
        ), ARRAY_A);

        $yesterday = array(
            'sessions' => $yesterday_sessions['sessions'] ?? 0,
            'page_views' => $yesterday_events['page_views'] ?? 0,
            'property_views' => $yesterday_events['property_views'] ?? 0,
            'searches' => $yesterday_events['searches'] ?? 0,
        );

        // Calculate changes
        $stats = array(
            'sessions' => array(
                'value' => (int) ($today['sessions'] ?? 0),
                'change' => $this->calc_change($today['sessions'] ?? 0, $yesterday['sessions'] ?? 0),
            ),
            'page_views' => array(
                'value' => (int) ($today['page_views'] ?? 0),
                'change' => $this->calc_change($today['page_views'] ?? 0, $yesterday['page_views'] ?? 0),
            ),
            'property_views' => array(
                'value' => (int) ($today['property_views'] ?? 0),
                'change' => $this->calc_change($today['property_views'] ?? 0, $yesterday['property_views'] ?? 0),
            ),
            'searches' => array(
                'value' => (int) ($today['searches'] ?? 0),
                'change' => $this->calc_change($today['searches'] ?? 0, $yesterday['searches'] ?? 0),
            ),
        );

        // Active visitors
        $stats['active_visitors'] = $db->get_active_visitors_count();

        // Platform breakdown for today
        $platforms = $wpdb->get_results($wpdb->prepare(
            "SELECT
                platform,
                COUNT(DISTINCT session_id) as sessions
            FROM {$wpdb->prefix}mld_public_sessions
            WHERE first_seen BETWEEN %s AND %s
            GROUP BY platform",
            $today_start, $today_end
        ), ARRAY_A);

        $stats['platforms'] = array();
        foreach ($platforms as $p) {
            $stats['platforms'][$p['platform']] = (int) $p['sessions'];
        }

        return $stats;
    }

    /**
     * Calculate percentage change
     *
     * @param int|float $current Current value
     * @param int|float $previous Previous value
     * @return float Percentage change
     */
    private function calc_change($current, $previous) {
        $current = (float) $current;
        $previous = (float) $previous;

        if ($previous == 0) {
            return $current > 0 ? 100 : 0;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }
}
