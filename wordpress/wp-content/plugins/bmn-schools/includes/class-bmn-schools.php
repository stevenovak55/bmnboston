<?php
/**
 * The core plugin class.
 *
 * This is used to define internationalization, admin-specific hooks, and
 * public-facing site hooks.
 *
 * @package BMN_Schools
 * @since 0.1.0
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * The core plugin class.
 *
 * @since 0.1.0
 */
final class BMN_Schools {

    /**
     * The single instance of the class.
     *
     * @var BMN_Schools|null
     */
    private static $instance = null;

    /**
     * Service container for dependency injection.
     *
     * @var array
     */
    private $container = [];

    /**
     * Plugin version.
     *
     * @var string
     */
    public $version = BMN_SCHOOLS_VERSION;

    /**
     * Main BMN_Schools Instance.
     *
     * Ensures only one instance of BMN_Schools is loaded or can be loaded.
     *
     * @since 0.1.0
     * @static
     * @return BMN_Schools - Main instance.
     */
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Cloning is forbidden.
     *
     * @since 0.1.0
     */
    public function __clone() {
        _doing_it_wrong(__FUNCTION__, __('Cloning is forbidden.', 'bmn-schools'), '0.1.0');
    }

    /**
     * Unserializing instances of this class is forbidden.
     *
     * @since 0.1.0
     */
    public function __wakeup() {
        _doing_it_wrong(__FUNCTION__, __('Unserializing instances of this class is forbidden.', 'bmn-schools'), '0.1.0');
    }

    /**
     * Constructor.
     *
     * @since 0.1.0
     */
    private function __construct() {
        $this->load_dependencies();
        $this->init_hooks();
    }

    /**
     * Load the required dependencies for this plugin.
     *
     * @since 0.1.0
     */
    private function load_dependencies() {
        // Core classes
        require_once BMN_SCHOOLS_PLUGIN_DIR . 'includes/class-logger.php';
        require_once BMN_SCHOOLS_PLUGIN_DIR . 'includes/class-database-manager.php';
        require_once BMN_SCHOOLS_PLUGIN_DIR . 'includes/class-cache-manager.php';
        require_once BMN_SCHOOLS_PLUGIN_DIR . 'includes/class-rest-api.php';
        require_once BMN_SCHOOLS_PLUGIN_DIR . 'includes/class-integration.php';
        require_once BMN_SCHOOLS_PLUGIN_DIR . 'includes/class-geocoder.php';
        require_once BMN_SCHOOLS_PLUGIN_DIR . 'includes/class-school-pages.php';

        // Admin
        if (is_admin()) {
            require_once BMN_SCHOOLS_PLUGIN_DIR . 'admin/class-admin.php';
        }
    }

    /**
     * Initialize hooks.
     *
     * @since 0.1.0
     */
    private function init_hooks() {
        // Initialize components
        add_action('init', [$this, 'init'], 0);

        // Maybe flush rewrite rules (after activation)
        add_action('init', [$this, 'maybe_flush_rewrite_rules'], 99);

        // REST API
        add_action('rest_api_init', [$this, 'init_rest_api']);

        // Admin hooks
        if (is_admin()) {
            add_action('admin_init', [$this, 'check_version']);
        }

        // Register custom cron schedule for annual data sync
        add_filter('cron_schedules', [$this, 'add_cron_schedules']);
    }

    /**
     * Add custom cron schedules for plugin automation.
     *
     * @param array $schedules Existing cron schedules.
     * @return array Modified cron schedules.
     * @since 0.6.16
     */
    public function add_cron_schedules($schedules) {
        // Annual schedule for data sync (September, after DESE releases new data)
        $schedules['annually'] = [
            'interval' => YEAR_IN_SECONDS,
            'display'  => __('Once Yearly', 'bmn-schools'),
        ];
        return $schedules;
    }

    /**
     * Init plugin when WordPress initializes.
     *
     * @since 0.1.0
     */
    public function init() {
        // Initialize logger
        $this->container['logger'] = new BMN_Schools_Logger();

        // Initialize database manager
        $this->container['db'] = new BMN_Schools_Database_Manager();

        // Initialize integration (MLS hooks + shortcodes)
        $this->container['integration'] = new BMN_Schools_Integration();
        $this->container['integration']->init();

        // Initialize school pages - MUST run on all requests (including admin)
        // Rewrite rules need to be registered on admin requests so they're included
        // when flush_rewrite_rules() is called (typically in admin context).
        // The template loading and front-end logic inside BMN_School_Pages already
        // checks !is_admin() where appropriate.
        $this->container['school_pages'] = BMN_School_Pages::get_instance();

        // Initialize admin
        if (is_admin()) {
            $this->container['admin'] = new BMN_Schools_Admin();
            $this->container['admin']->init();
        }

        // Log plugin initialization in debug mode
        if (defined('BMN_SCHOOLS_DEBUG') && BMN_SCHOOLS_DEBUG) {
            BMN_Schools_Logger::log('debug', 'init', 'Plugin initialized', [
                'version' => $this->version
            ]);
        }
    }

    /**
     * Flush rewrite rules if the flag is set (after plugin activation).
     *
     * @since 0.7.0
     */
    public function maybe_flush_rewrite_rules() {
        if (get_option('bmn_schools_flush_rewrite_rules')) {
            flush_rewrite_rules();
            delete_option('bmn_schools_flush_rewrite_rules');

            if (defined('BMN_SCHOOLS_DEBUG') && BMN_SCHOOLS_DEBUG) {
                BMN_Schools_Logger::log('info', 'init', 'Rewrite rules flushed for school pages');
            }
        }
    }

    /**
     * Initialize REST API.
     *
     * @since 0.1.0
     */
    public function init_rest_api() {
        $this->container['rest_api'] = new BMN_Schools_REST_API();
    }

    /**
     * Check plugin version and run upgrades if needed.
     *
     * @since 0.1.0
     */
    public function check_version() {
        $installed_version = get_option('bmn_schools_version');

        if (version_compare($installed_version, BMN_SCHOOLS_VERSION, '<')) {
            // Run any necessary upgrades
            require_once BMN_SCHOOLS_PLUGIN_DIR . 'includes/class-activator.php';
            BMN_Schools_Activator::activate();

            update_option('bmn_schools_version', BMN_SCHOOLS_VERSION);

            BMN_Schools_Logger::log('info', 'upgrade', 'Plugin upgraded', [
                'from' => $installed_version,
                'to' => BMN_SCHOOLS_VERSION
            ]);
        }
    }

    /**
     * Get a service from the container.
     *
     * @since 0.1.0
     * @param string $key Service key.
     * @return mixed|null Service instance or null.
     */
    public function get($key) {
        return isset($this->container[$key]) ? $this->container[$key] : null;
    }

    /**
     * Get the database manager.
     *
     * @since 0.1.0
     * @return BMN_Schools_Database_Manager
     */
    public function db() {
        return $this->get('db');
    }

    /**
     * Get the logger.
     *
     * @since 0.1.0
     * @return BMN_Schools_Logger
     */
    public function logger() {
        return $this->get('logger');
    }

    /**
     * Get the plugin path.
     *
     * @since 0.1.0
     * @return string
     */
    public function plugin_path() {
        return BMN_SCHOOLS_PLUGIN_DIR;
    }

    /**
     * Get the plugin URL.
     *
     * @since 0.1.0
     * @return string
     */
    public function plugin_url() {
        return BMN_SCHOOLS_PLUGIN_URL;
    }

    /**
     * Get table names with prefix.
     *
     * @since 0.1.0
     * @return array
     */
    public function get_table_names() {
        global $wpdb;

        return [
            'schools' => $wpdb->prefix . 'bmn_schools',
            'districts' => $wpdb->prefix . 'bmn_school_districts',
            'locations' => $wpdb->prefix . 'bmn_school_locations',
            'test_scores' => $wpdb->prefix . 'bmn_school_test_scores',
            'rankings' => $wpdb->prefix . 'bmn_school_rankings',
            'demographics' => $wpdb->prefix . 'bmn_school_demographics',
            'features' => $wpdb->prefix . 'bmn_school_features',
            'attendance_zones' => $wpdb->prefix . 'bmn_school_attendance_zones',
            'data_sources' => $wpdb->prefix . 'bmn_school_data_sources',
            'activity_log' => $wpdb->prefix . 'bmn_schools_activity_log',
            'state_benchmarks' => $wpdb->prefix . 'bmn_state_benchmarks',
            'district_rankings' => $wpdb->prefix . 'bmn_district_rankings',
            'sports' => $wpdb->prefix . 'bmn_school_sports',
        ];
    }
}
