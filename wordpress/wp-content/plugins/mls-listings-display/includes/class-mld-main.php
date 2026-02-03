<?php
/**
 * Main plugin class.
 *
 * @package MLS_Listings_Display
 */
class MLD_Main {

    /**
     * Constructor.
     */
    public function __construct() {
        $this->load_dependencies();
        $this->check_upgrades();
        $this->init_classes();
    }

    /**
     * Check and run database upgrades
     */
    private function check_upgrades() {
        require_once MLD_PLUGIN_PATH . 'includes/class-mld-upgrader.php';
        MLD_Upgrader::check_upgrades();
    }

    /**
     * Load the required dependencies for this plugin.
     * Note: class-mld-rewrites.php is loaded in the main plugin file.
     */
    private function load_dependencies() {
        // Load logger first so other classes can use it
        require_once MLD_PLUGIN_PATH . 'includes/class-mld-logger.php';

        // Load performance monitoring
        require_once MLD_PLUGIN_PATH . 'includes/class-mld-performance-monitor.php';
        require_once MLD_PLUGIN_PATH . 'includes/class-mld-database-optimizer.php';

        // Load WP-CLI commands if available
        if (defined('WP_CLI') && WP_CLI) {
            require_once MLD_PLUGIN_PATH . 'includes/class-mld-wp-cli-cleanup.php';
        }

        require_once MLD_PLUGIN_PATH . 'includes/class-mld-device-detector.php';
        require_once MLD_PLUGIN_PATH . 'includes/class-mld-utils.php';
        require_once MLD_PLUGIN_PATH . 'includes/class-mld-url-helper.php';
        require_once MLD_PLUGIN_PATH . 'includes/class-mld-bme-data-provider.php'; // Load data provider
        require_once MLD_PLUGIN_PATH . 'includes/class-mld-query-cache.php'; // Load cache before query
        require_once MLD_PLUGIN_PATH . 'includes/class-mld-performance-cache.php'; // Performance caching layer (v6.4.0)
        require_once MLD_PLUGIN_PATH . 'includes/class-mld-query-router.php'; // Smart query router for optimization
        require_once MLD_PLUGIN_PATH . 'includes/services/class-mld-spatial-filter-service.php'; // Spatial filtering service (v6.11.5)
        require_once MLD_PLUGIN_PATH . 'includes/class-mld-query.php';
        require_once MLD_PLUGIN_PATH . 'includes/class-mld-ajax.php';
        require_once MLD_PLUGIN_PATH . 'includes/class-mld-shortcodes.php';
        require_once MLD_PLUGIN_PATH . 'includes/class-mld-admin.php';
        require_once MLD_PLUGIN_PATH . 'includes/class-mld-seo.php';
        require_once MLD_PLUGIN_PATH . 'includes/class-mld-business-schema.php';
        require_once MLD_PLUGIN_PATH . 'includes/class-mld-business-settings.php';
        require_once MLD_PLUGIN_PATH . 'includes/class-mld-search-seo.php';
        require_once MLD_PLUGIN_PATH . 'includes/class-mld-settings.php';
        require_once MLD_PLUGIN_PATH . 'includes/class-mld-facebook-fix.php';
        require_once MLD_PLUGIN_PATH . 'includes/class-mld-virtual-tour-detector.php';
        require_once MLD_PLUGIN_PATH . 'includes/class-mld-virtual-tour-utils.php';
        require_once MLD_PLUGIN_PATH . 'includes/class-mld-address-utils.php';
        require_once MLD_PLUGIN_PATH . 'includes/class-mld-city-boundaries.php';
        require_once MLD_PLUGIN_PATH . 'includes/class-mld-schools.php';

        // Load saved search system
        require_once MLD_PLUGIN_PATH . 'includes/saved-searches/class-mld-saved-search-init.php';
        require_once MLD_PLUGIN_PATH . 'includes/saved-searches/class-mld-agent-manager.php';

        // Load CMA frontend (provides AJAX handlers for property characteristics)
        if (file_exists(MLD_PLUGIN_PATH . 'includes/class-mld-cma-frontend.php')) {
            require_once MLD_PLUGIN_PATH . 'includes/class-mld-cma-frontend.php';
        }

        // Load simple notification system
        if (file_exists(MLD_PLUGIN_PATH . 'includes/notifications/class-mld-simple-notifications.php')) {
            require_once MLD_PLUGIN_PATH . 'includes/notifications/class-mld-simple-notifications.php';
        }

        // Admin includes
        if (is_admin()) {
            require_once MLD_PLUGIN_PATH . 'includes/class-mld-ajax-admin.php';
            require_once MLD_PLUGIN_PATH . 'admin/class-mld-admin-settings.php';
            require_once MLD_PLUGIN_PATH . 'admin/class-mld-performance-admin.php';
            require_once MLD_PLUGIN_PATH . 'includes/admin/class-mld-admin-menu.php';
        }

        // Email Template Editor (needed for both admin UI and AJAX handlers)
        // Load early to ensure AJAX handlers are registered
        if (is_admin() || (defined('DOING_AJAX') && DOING_AJAX)) {
            if (file_exists(MLD_PLUGIN_PATH . 'includes/email-template-editor/class-mld-template-editor.php')) {
                require_once MLD_PLUGIN_PATH . 'includes/email-template-editor/class-mld-template-editor.php';
                // The class auto-instantiates at the end of the file
            }
        }
    }

    /**
     * Initialize the classes.
     */
    private function init_classes() {
        new MLD_Shortcodes();
        new MLD_Ajax();
        new MLD_Rewrites();
        new MLD_Admin();
        MLD_Sitemap_Admin::get_instance();
        new MLD_SEO();
        new MLD_Business_Schema();
        new MLD_Business_Settings();
        new MLD_Search_SEO();
        new MLD_Facebook_Fix();
        new MLD_City_Boundaries();
        new MLD_Schools();

        // Initialize CMA Frontend Tool
        if (class_exists('MLD_CMA_Frontend')) {
            new MLD_CMA_Frontend();
        }

        // DISABLED v6.13.14: Legacy notification system replaced by MLD_Fifteen_Minute_Processor
        // This old system was causing duplicate emails - the new unified processor handles all frequencies
        // if (class_exists('MLD_Simple_Notifications')) {
        //     MLD_Simple_Notifications::get_instance();
        //     MLD_Logger::info('Simple Notifications System initialized');
        // } else {
        //     MLD_Logger::debug('Simple Notifications System not available');
        // }
        MLD_Logger::info('Legacy Simple Notifications disabled - using unified processor (MLD_Fifteen_Minute_Processor)');

        // Load and run installation verifier on admin pages
        if (is_admin() && file_exists(MLD_PLUGIN_DIR . 'includes/class-mld-installation-verifier.php')) {
            require_once MLD_PLUGIN_DIR . 'includes/class-mld-installation-verifier.php';
            // The verifier will auto-run via admin_init hook
        }

        // Initialize admin AJAX handler and performance admin
        if (is_admin() || (defined('DOING_AJAX') && DOING_AJAX)) {
            new MLD_Ajax_Admin();
            new MLD_Performance_Admin();
            // Initialize CMA Admin
            if (class_exists('MLD_CMA_Admin')) {
                new MLD_CMA_Admin();
            }
            // Initialize debug zoom page
            if (class_exists('MLD_Debug_Zoom')) {
                MLD_Debug_Zoom::init();
            }
            // Initialize admin menu for data import
            if (class_exists('MLD_Admin_Menu')) {
                new MLD_Admin_Menu();
            }
        }

        // Log successful initialization
        MLD_Logger::info('MLS Listings Display plugin initialized successfully');
    }
}