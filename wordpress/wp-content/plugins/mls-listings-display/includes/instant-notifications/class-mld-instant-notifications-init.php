<?php
/**
 * MLD Instant Notifications - Initialization Class
 *
 * Main initialization and coordination class for the instant notification system
 *
 * @package MLS_Listings_Display
 * @subpackage Instant_Notifications
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Instant_Notifications_Init {

    /**
     * Instance of this class
     *
     * @var MLD_Instant_Notifications_Init
     */
    private static $instance = null;

    /**
     * Component instances
     *
     * @var array
     */
    private $components = [];

    /**
     * Whether the system is enabled
     *
     * @var bool
     */
    private $enabled = true;

    /**
     * Get singleton instance
     *
     * @return MLD_Instant_Notifications_Init
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Get a specific component
     *
     * @param string $component Component name
     * @return object|null Component instance or null if not found
     */
    public function get_component($component) {
        return isset($this->components[$component]) ? $this->components[$component] : null;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->init();
    }

    /**
     * Initialize the instant notifications system
     */
    private function init() {
        // Check if system should be enabled
        $this->enabled = get_option('mld_instant_notifications_enabled', true);

        if (!$this->enabled) {
            return;
        }

        // Install database tables if needed
        $this->maybe_install_database();

        // Load required files
        $this->load_dependencies();

        // Initialize components
        $this->init_components();

        // Register hooks
        $this->register_hooks();

        // Log initialization
        $this->log('Instant Notifications System initialized', 'info');
    }

    /**
     * Maybe install database tables
     */
    private function maybe_install_database() {
        require_once dirname(__FILE__) . '/class-mld-database-installer.php';

        if (!MLD_Database_Installer::tables_exist()) {
            MLD_Database_Installer::install();
            $this->log('Database tables installed', 'success');
        }
    }

    /**
     * Load required dependencies
     */
    private function load_dependencies() {
        $base_path = dirname(__FILE__);

        // Core components
        require_once $base_path . '/class-mld-instant-matcher.php';
        require_once $base_path . '/class-mld-notification-router.php';
        require_once $base_path . '/class-mld-throttle-manager.php';
        require_once $base_path . '/class-mld-queue-processor.php';

        // Import notification bridge for proper data handling
        require_once $base_path . '/class-mld-import-notification-bridge.php';

        // Admin interface - always load so hooks can be registered
        if (file_exists($base_path . '/class-mld-instant-admin.php')) {
            require_once $base_path . '/class-mld-instant-admin.php';
        }

        // Settings page
        if (file_exists($base_path . '/class-mld-instant-settings.php')) {
            require_once $base_path . '/class-mld-instant-settings.php';
        }

        // Integration components
        if (file_exists($base_path . '/class-mld-buddyboss-integration.php')) {
            require_once $base_path . '/class-mld-buddyboss-integration.php';
        }

        // Load Modern BuddyBoss Notification API class if available
        if (file_exists($base_path . '/class-mld-buddyboss-notification.php') &&
            class_exists('BP_Core_Notification_Abstract')) {
            require_once $base_path . '/class-mld-buddyboss-notification.php';
        }

        if (file_exists($base_path . '/class-mld-email-sender.php')) {
            require_once $base_path . '/class-mld-email-sender.php';
        }

        // v6.68.13: Load BMN Schools Integration for school filter support in instant notifications
        if (!class_exists('MLD_BMN_Schools_Integration')) {
            $schools_path = dirname(dirname(__FILE__)) . '/class-mld-bmn-schools-integration.php';
            if (file_exists($schools_path)) {
                require_once $schools_path;
            }
        }
    }

    /**
     * Initialize components
     */
    private function init_components() {
        // Initialize core components first
        $this->components['matcher'] = new MLD_Instant_Matcher();
        $this->components['router'] = new MLD_Notification_Router();
        $this->components['throttle'] = new MLD_Throttle_Manager();
        $this->components['queue'] = new MLD_Queue_Processor();

        // Store matcher instance globally for hook registration
        global $mld_instant_matcher_instance;
        $mld_instant_matcher_instance = $this->components['matcher'];

        // Initialize BuddyBoss components
        if (class_exists('BuddyPress')) {
            // Initialize Modern API first if available - MUST be done BEFORE bp_init
            if (class_exists('MLD_BuddyBoss_Notification') && class_exists('BP_Core_Notification_Abstract')) {
                // Create instance immediately - it will register itself with bp_init
                MLD_BuddyBoss_Notification::instance();
                $this->log('Modern BuddyBoss Notification API instance created', 'info');
            }

            // Initialize legacy integration for backward compatibility
            if (class_exists('MLD_BuddyBoss_Integration')) {
                $this->components['buddyboss'] = MLD_BuddyBoss_Integration::get_instance();
            }
        }

        if (class_exists('MLD_Email_Sender')) {
            $this->components['email'] = new MLD_Email_Sender();
        }

        // Connect components with proper dependency injection
        $this->components['matcher']->set_notification_router($this->components['router']);
        $this->components['matcher']->set_throttle_manager($this->components['throttle']);
        $this->components['queue']->set_dependencies($this->components['router'], $this->components['throttle']);

        // Inject integration classes into router
        if (isset($this->components['buddyboss'])) {
            $this->components['router']->set_buddyboss_integration($this->components['buddyboss']);
        }

        if (isset($this->components['email'])) {
            $this->components['router']->set_email_sender($this->components['email']);
        }

        // Initialize admin interface - always initialize so hooks are registered
        if (class_exists('MLD_Instant_Admin')) {
            $this->components['admin'] = new MLD_Instant_Admin();
        }

        // Initialize settings page
        if (class_exists('MLD_Instant_Settings')) {
            $this->components['settings'] = new MLD_Instant_Settings();
        }
    }

    /**
     * Register WordPress hooks
     */
    private function register_hooks() {
        // Admin hooks - menu registration handled by individual components
        add_action('admin_init', [$this, 'register_settings']);

        // AJAX hooks
        add_action('wp_ajax_mld_test_instant_notification', [$this, 'ajax_test_notification']);
        add_action('wp_ajax_mld_get_instant_stats', [$this, 'ajax_get_stats']);

        // Cleanup hooks
        add_action('mld_instant_notifications_cleanup', [$this, 'cleanup_old_data']);

        // Queue processing hooks
        add_action('mld_process_notification_queue', [$this->components['queue'], 'process_queue']);
        add_action('mld_cleanup_notification_queue', [$this->components['queue'], 'cleanup_expired']);

        // Schedule cleanup if not already scheduled
        if (!wp_next_scheduled('mld_instant_notifications_cleanup')) {
            wp_schedule_event(time(), 'daily', 'mld_instant_notifications_cleanup');
        }

        // Schedule queue processing if not already scheduled
        if (!wp_next_scheduled('mld_process_notification_queue')) {
            wp_schedule_event(time(), 'mld_every_15_minutes', 'mld_process_notification_queue');
        }

        // Schedule queue cleanup if not already scheduled
        if (!wp_next_scheduled('mld_cleanup_notification_queue')) {
            wp_schedule_event(time(), 'daily', 'mld_cleanup_notification_queue');
        }

        // Add custom cron schedule
        add_filter('cron_schedules', [$this, 'add_cron_schedules']);
    }


    /**
     * Add custom cron schedules
     */
    public function add_cron_schedules($schedules) {
        if (!isset($schedules['mld_every_15_minutes'])) {
            $schedules['mld_every_15_minutes'] = [
                'interval' => 900, // 15 minutes
                'display' => __('Every 15 Minutes', 'mld')
            ];
        }
        return $schedules;
    }

    /**
     * Register settings
     */
    public function register_settings() {
        register_setting('mld_instant_notifications', 'mld_instant_notifications_enabled');
        register_setting('mld_instant_notifications', 'mld_instant_bulk_threshold');
        register_setting('mld_instant_notifications', 'mld_instant_quiet_hours_start');
        register_setting('mld_instant_notifications', 'mld_instant_quiet_hours_end');
    }


    /**
     * AJAX handler for testing notifications
     */
    public function ajax_test_notification() {
        check_ajax_referer('mld_instant_notifications', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $search_id = intval($_POST['search_id']);
        $listing_id = sanitize_text_field($_POST['listing_id']);

        // Trigger test notification
        $result = $this->trigger_test_notification($search_id, $listing_id);

        wp_send_json($result);
    }

    /**
     * AJAX handler for getting statistics
     */
    public function ajax_get_stats() {
        check_ajax_referer('mld_instant_notifications', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $stats = $this->get_statistics();
        wp_send_json_success($stats);
    }

    /**
     * Get statistics for the dashboard
     */
    public function get_statistics() {
        global $wpdb;

        $stats = [];

        // Get total notifications sent today
        $stats['today_sent'] = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}mld_search_activity_matches
             WHERE DATE(created_at) = %s AND notification_status = 'sent'",
            current_time('Y-m-d')
        ));

        // Get active instant searches
        $stats['active_searches'] = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}mld_saved_searches
             WHERE notification_frequency = 'instant' AND is_active = 1"
        );

        // Get average response time
        // Use WordPress timezone-aware date instead of MySQL CURDATE()
        $wp_week_ago = wp_date('Y-m-d', current_time('timestamp') - (7 * DAY_IN_SECONDS));
        $stats['avg_response_time'] = $wpdb->get_var($wpdb->prepare(
            "SELECT AVG(TIMESTAMPDIFF(SECOND, created_at, notified_at))
             FROM {$wpdb->prefix}mld_search_activity_matches
             WHERE notification_status = 'sent' AND DATE(created_at) >= %s",
            $wp_week_ago
        ));

        // Get throttled count today
        $stats['throttled_today'] = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}mld_search_activity_matches
             WHERE DATE(created_at) = %s AND notification_status = 'throttled'",
            current_time('Y-m-d')
        ));

        return $stats;
    }

    /**
     * Cleanup old data
     */
    public function cleanup_old_data() {
        global $wpdb;

        // Use WordPress timezone-aware dates for cleanup
        $wp_today = wp_date('Y-m-d');
        $wp_now = current_time('mysql');

        // Remove old throttle records (older than 30 days)
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}mld_notification_throttle
             WHERE notification_date < DATE_SUB(%s, INTERVAL 30 DAY)",
            $wp_today
        ));

        // Remove old activity matches (older than 90 days)
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}mld_search_activity_matches
             WHERE created_at < DATE_SUB(%s, INTERVAL 90 DAY)",
            $wp_now
        ));

        $this->log('Cleanup completed', 'info');
    }

    /**
     * Log activity
     *
     * @param string $message Log message
     * @param string $level Log level
     */
    private function log($message, $level = 'info') {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            // Skip logging during plugin activation to prevent unexpected output
            if (defined('WP_ADMIN') && WP_ADMIN &&
                isset($_GET['action']) && $_GET['action'] === 'activate') {
                return;
            }

            // Only log to file, never to browser during web requests
            if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                error_log(sprintf('[MLD Instant Notifications] [%s] %s', $level, $message), 3, WP_CONTENT_DIR . '/debug.log');
            } elseif (php_sapi_name() === 'cli') {
                // Only output to error_log if we're in CLI mode
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log(sprintf('[MLD Instant Notifications] [%s] %s', $level, $message));
                }
            }
        }
    }


    /**
     * Trigger test notification
     *
     * @param int $search_id Saved search ID
     * @param string $listing_id Listing ID
     * @return array Result array
     */
    private function trigger_test_notification($search_id, $listing_id) {
        // Get search data
        $search = MLD_Saved_Searches::get_search($search_id);
        if (!$search) {
            return ['success' => false, 'message' => 'Search not found'];
        }

        // Get listing data from Bridge Extractor tables
        global $wpdb;
        $listing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}bme_listings WHERE listing_id = %s",
            $listing_id
        ), ARRAY_A);

        if (!$listing) {
            return ['success' => false, 'message' => 'Listing not found'];
        }

        // Trigger notification through router
        $router = $this->get_component('router');
        if ($router) {
            $result = $router->route_notification($search, $listing, 'test');
            return ['success' => $result, 'message' => $result ? 'Test notification sent' : 'Failed to send notification'];
        }

        return ['success' => false, 'message' => 'Router not available'];
    }
}