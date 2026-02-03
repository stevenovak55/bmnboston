<?php
/**
 * Plugin Name:       Exclusive Listings
 * Plugin URI:        https://bmnboston.com/
 * Description:       Agent-created exclusive listings that integrate seamlessly with MLS data. Allows agents to create, manage, and display non-MLS listings using the same data model as Bridge MLS imports.
 * Version:           1.5.3
 * Author:            BMN Boston
 * Author URI:        https://bmnboston.com/
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       exclusive-listings
 * Requires at least: 6.0
 * Requires PHP:      7.4
 *
 * @package Exclusive_Listings
 *
 * Version 1.5.3 - GEOCODING & MEDIA FIXES (Jan 27, 2026)
 * - Added city/zip fallback geocoding when full address fails (prevents Boston default)
 * - Added delete_attachment hook to clean up BME media records when images deleted from Media Library
 * - Added cleanup_orphaned_media_records() utility to detect/remove orphaned media
 * - Increased max photos per listing from 50 to 100
 *
 * Version 1.0.0 - INITIAL RELEASE (Jan 2026)
 * - Plugin skeleton and foundation
 * - ID generation system (sequential from 1)
 * - Database schema for exclusive listings
 * - Health check REST endpoint
 * - Read-only diagnostic mode
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants
define('EL_VERSION', '1.5.3');
define('EL_DB_VERSION', '1.0.0');
define('EL_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('EL_PLUGIN_URL', plugin_dir_url(__FILE__));
define('EL_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Minimum MLS ID threshold (exclusive listings use IDs below this)
// MLS IDs start at ~60 million, so anything under 1 million is exclusive
define('EL_EXCLUSIVE_ID_THRESHOLD', 1000000);

/**
 * Main plugin class
 */
final class Exclusive_Listings {

    /**
     * Singleton instance
     * @var Exclusive_Listings
     */
    private static $instance = null;

    /**
     * Plugin components
     */
    private $database;
    private $id_generator;
    private $rest_api;
    private $mobile_rest_api;
    private $geocoder;
    private $admin;
    private $notifications;

    /**
     * Get singleton instance
     * @return Exclusive_Listings
     */
    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor - private for singleton
     */
    private function __construct() {
        $this->load_dependencies();
        $this->init_hooks();
    }

    /**
     * Load required files
     */
    private function load_dependencies() {
        // Core classes
        require_once EL_PLUGIN_DIR . 'includes/class-el-activator.php';
        require_once EL_PLUGIN_DIR . 'includes/class-el-database.php';
        require_once EL_PLUGIN_DIR . 'includes/class-el-id-generator.php';
        require_once EL_PLUGIN_DIR . 'includes/class-el-rest-api.php';

        // Data layer classes (v1.1.0)
        require_once EL_PLUGIN_DIR . 'includes/class-el-validator.php';
        require_once EL_PLUGIN_DIR . 'includes/class-el-geocoder.php';
        require_once EL_PLUGIN_DIR . 'includes/class-el-bme-sync.php';
        require_once EL_PLUGIN_DIR . 'includes/class-el-image-handler.php';
        require_once EL_PLUGIN_DIR . 'includes/class-el-mobile-rest-api.php';

        // Admin interface (v1.2.0)
        if (is_admin()) {
            require_once EL_PLUGIN_DIR . 'includes/class-el-admin.php';
        }

        // Notifications (v1.3.0)
        require_once EL_PLUGIN_DIR . 'includes/class-el-notifications.php';
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Activation/deactivation
        register_activation_hook(__FILE__, array('EL_Activator', 'activate'));
        register_deactivation_hook(__FILE__, array('EL_Activator', 'deactivate'));

        // Initialize components - check if plugins_loaded has already fired
        if (did_action('plugins_loaded')) {
            $this->init_components();
        } else {
            add_action('plugins_loaded', array($this, 'init_components'));
        }

        // Register REST API routes
        add_action('rest_api_init', array($this, 'register_rest_routes'));
    }

    /**
     * Initialize plugin components
     */
    public function init_components() {
        // Always initialize database and ID generator (needed for health check)
        $this->database = new EL_Database();
        $this->id_generator = new EL_ID_Generator();
        $this->rest_api = new EL_REST_API();

        // Check if MLS Listings Display plugin is active (required for full functionality)
        if (!defined('MLD_VERSION')) {
            add_action('admin_notices', array($this, 'dependency_notice'));
            // Continue initialization - health endpoint should still work
        }

        // Initialize data layer components (v1.1.0)
        $this->geocoder = new EL_Geocoder();
        $this->mobile_rest_api = new EL_Mobile_REST_API();

        // Initialize admin interface (v1.2.0)
        if (is_admin() && class_exists('EL_Admin')) {
            $this->admin = new EL_Admin();
        }

        // Initialize notifications (v1.3.0)
        if (class_exists('EL_Notifications')) {
            $this->notifications = new EL_Notifications();
        }

        // Check for database updates
        $this->maybe_upgrade_database();
    }

    /**
     * Register REST API routes
     */
    public function register_rest_routes() {
        $this->rest_api->register_routes();
        $this->mobile_rest_api->register_routes();
    }

    /**
     * Show admin notice if MLD plugin is not active
     */
    public function dependency_notice() {
        ?>
        <div class="notice notice-error">
            <p><strong>Exclusive Listings:</strong> This plugin requires the MLS Listings Display plugin to be active. Please activate MLS Listings Display first.</p>
        </div>
        <?php
    }

    /**
     * Check and run database upgrades if needed
     */
    private function maybe_upgrade_database() {
        $installed_version = get_option('el_db_version', '0.0.0');

        if (version_compare($installed_version, EL_DB_VERSION, '<')) {
            $this->database->upgrade();
            update_option('el_db_version', EL_DB_VERSION);
        }
    }

    /**
     * Get database manager
     * @return EL_Database
     */
    public function get_database() {
        return $this->database;
    }

    /**
     * Get ID generator
     * @return EL_ID_Generator
     */
    public function get_id_generator() {
        return $this->id_generator;
    }

    /**
     * Get REST API handler
     * @return EL_REST_API
     */
    public function get_rest_api() {
        return $this->rest_api;
    }

    /**
     * Get mobile REST API handler
     * @return EL_Mobile_REST_API
     */
    public function get_mobile_rest_api() {
        return $this->mobile_rest_api;
    }

    /**
     * Get geocoder
     * @return EL_Geocoder
     */
    public function get_geocoder() {
        return $this->geocoder;
    }

    /**
     * Create a new BME sync service
     * @return EL_BME_Sync
     */
    public function create_bme_sync() {
        return new EL_BME_Sync();
    }

    /**
     * Create a new image handler
     * @return EL_Image_Handler
     */
    public function create_image_handler() {
        return new EL_Image_Handler();
    }

    /**
     * Check if a listing ID is an exclusive listing
     *
     * @param int $listing_id The listing ID to check
     * @return bool True if exclusive listing, false if MLS listing
     */
    public static function is_exclusive_listing($listing_id) {
        return $listing_id < EL_EXCLUSIVE_ID_THRESHOLD;
    }

    /**
     * Prevent cloning
     */
    private function __clone() {}

    /**
     * Prevent unserialization
     */
    public function __wakeup() {
        throw new Exception('Cannot unserialize singleton');
    }
}

/**
 * Get the main plugin instance
 *
 * @return Exclusive_Listings
 */
function exclusive_listings() {
    return Exclusive_Listings::instance();
}

// Initialize the plugin
exclusive_listings();
