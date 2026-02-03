<?php
/**
 * MLS Listings Display - Saved Search System Initialization
 * 
 * Initializes all components of the saved search system
 * 
 * @package MLS_Listings_Display
 * @subpackage Saved_Searches
 * @since 3.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Saved_Search_Init {

    /**
     * Flag to prevent multiple initializations
     * @var bool
     */
    private static $initialized = false;

    /**
     * Initialize the saved search system
     */
    public static function init() {
        // Prevent multiple initializations
        if (self::$initialized) {
            return;
        }
        self::$initialized = true;
        try {
            // Check for critical dependencies first
            $base_path = MLD_PLUGIN_PATH . 'includes/saved-searches/';
            $critical_files = [
                'class-mld-saved-search-database.php',
                'class-mld-saved-searches.php',
                'class-mld-saved-search-cron.php'
            ];
            
            foreach ($critical_files as $file) {
                if (!file_exists($base_path . $file)) {
                    if (class_exists('MLD_Logger')) {
                        MLD_Logger::warning("Critical saved search file missing, skipping initialization: {$file}");
                    }
                    return; // Skip initialization if critical files are missing
                }
            }
            
            // Load required files
            self::load_dependencies();
            
            // Initialize components
            self::init_components();
            
            // Register hooks
            self::register_hooks();
            
            if (class_exists('MLD_Logger')) {
                MLD_Logger::info('Saved search system initialized successfully');
            }
            
        } catch (Exception $e) {
            if (class_exists('MLD_Logger')) {
                MLD_Logger::error('Failed to initialize saved search system', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            } elseif (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD: Saved search initialization failed: ' . $e->getMessage());
            }
        }
    }
    
    /**
     * Load required dependencies
     */
    private static function load_dependencies() {
        $base_path = MLD_PLUGIN_PATH . 'includes/saved-searches/';
        
        // Helper function to safely require files
        $safe_require = function($file_path, $silent = false) use ($base_path) {
            $full_path = $base_path . $file_path;
            if (file_exists($full_path)) {
                require_once $full_path;
                return true;
            } else {
                // Don't log warnings for optional files when $silent is true
                if (!$silent) {
                    if (class_exists('MLD_Logger')) {
                        MLD_Logger::warning("Saved search dependency missing: {$file_path}");
                    } elseif (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log("MLD: Missing saved search file: {$full_path}");
                    }
                }
                return false;
            }
        };
        
        // Core classes
        require_once $base_path . 'class-mld-saved-search-database.php';
        require_once $base_path . 'class-mld-database-compatibility.php';
        require_once $base_path . 'class-mld-field-mapper.php';
        require_once $base_path . 'class-mld-saved-searches.php';
        require_once $base_path . 'class-mld-property-preferences.php';
        // Notifications class - bridges cron system with email sending
        require_once $base_path . 'class-mld-saved-search-notifications.php';
        require_once $base_path . 'class-mld-saved-search-cron.php';
        
        // Test utilities (optional - only load if available, no warnings)
        if (is_admin()) {
            // Pass true as second parameter to suppress warnings for this optional file
            $safe_require('class-mld-saved-search-test.php', true);
        }
        
        // Service layer - Repositories
        require_once $base_path . 'repositories/class-mld-search-repository.php';
        // Note: Legacy notification repository removed - using simple notification system
        require_once $base_path . 'repositories/class-mld-property-preferences-repository.php';

        // Service layer - Services
        require_once $base_path . 'services/class-mld-search-service.php';
        // Note: Legacy notification and email services removed - using simple notification system
        require_once $base_path . 'services/class-mld-user-service.php';
        
        // Service container
        require_once $base_path . 'class-mld-service-container.php';
        
        // Cache manager
        require_once $base_path . 'class-mld-cache-manager.php';
        
        // Query optimizer
        require_once $base_path . 'class-mld-query-optimizer.php';
        
        // Agent/Client management
        require_once $base_path . 'class-mld-agent-client-manager.php';
        
        // Frontend components
        require_once $base_path . 'class-mld-saved-searches-frontend.php';
        
        // Admin components
        if (is_admin()) {
            require_once $base_path . 'class-mld-saved-search-admin.php';
            require_once $base_path . 'class-mld-agent-management-admin.php';
            require_once $base_path . 'class-mld-client-management-admin.php';
        }
    }
    
    /**
     * Initialize components
     */
    private static function init_components() {
        // Check and upgrade database schema if needed
        MLD_Saved_Search_Database::check_and_upgrade();

        // Initialize cron jobs
        MLD_Saved_Search_Cron::init();
        
        // Setup cache warming
        MLD_Cache_Manager::setup_cache_warming();
        
        // Initialize frontend components - The frontend class self-initializes
        // No need to explicitly initialize here as new MLD_Saved_Searches_Frontend() is called at the end of the class file
        
        // Initialize email template editor system (needs to be loaded for notifications)
        if (file_exists(MLD_PLUGIN_PATH . 'includes/email-template-editor/class-mld-template-variables.php')) {
            require_once MLD_PLUGIN_PATH . 'includes/email-template-editor/class-mld-template-variables.php';
        }
        if (file_exists(MLD_PLUGIN_PATH . 'includes/email-template-editor/class-mld-alert-types.php')) {
            require_once MLD_PLUGIN_PATH . 'includes/email-template-editor/class-mld-alert-types.php';
        }
        if (file_exists(MLD_PLUGIN_PATH . 'includes/email-template-editor/class-mld-template-customizer.php')) {
            require_once MLD_PLUGIN_PATH . 'includes/email-template-editor/class-mld-template-customizer.php';

            // Run template migration if needed (for backward compatibility)
            if (class_exists('MLD_Template_Customizer')) {
                MLD_Template_Customizer::maybe_run_migration();
            }
        }

        // Initialize admin components
        // Include AJAX handlers for both admin pages and admin-ajax.php requests
        if (is_admin() || (defined('DOING_AJAX') && DOING_AJAX)) {
            new MLD_Saved_Search_Admin();
            new MLD_Agent_Management_Admin();
            new MLD_Client_Management_Admin();
        }

        // Note: Email Template Editor is now loaded earlier in MLD_Main::load_dependencies()
        // This ensures AJAX handlers are registered before any AJAX requests
    }
    
    /**
     * Register hooks
     */
    private static function register_hooks() {
        // Note: Activation/deactivation hooks are handled by the main plugin file
        // This method is reserved for WordPress action/filter hooks
        
        // For now, no additional hooks are needed here
        // Individual saved search classes handle their own hooks
        
        if (class_exists('MLD_Logger')) {
            MLD_Logger::debug('Saved search hooks registered');
        }
    }
    
    /**
     * Plugin activation
     */
    public static function activate() {
        // Load dependencies first before using them
        self::load_dependencies();

        // Create database tables
        MLD_Saved_Search_Database::create_tables();
        
        // Create compatibility layer and indexes
        MLD_Database_Compatibility::create_compatibility_layer();
        MLD_Database_Compatibility::add_performance_indexes();
        MLD_Database_Compatibility::schedule_mapping_refresh();
        
        // Schedule cron events
        MLD_Saved_Search_Cron::schedule_events();
        
        // Create saved search pages
        self::create_pages();

        // Note: Rewrite rules flush is handled by MLD_Activator via transient
        // to ensure all rules are registered before flushing

        // Trigger activation hook for other components
        do_action('mld_saved_searches_activated');
    }
    
    /**
     * Plugin deactivation
     */
    public static function deactivate() {
        // Unschedule cron events
        MLD_Saved_Search_Cron::unschedule_events();
        
        // Unschedule mapping refresh
        MLD_Database_Compatibility::unschedule_mapping_refresh();
        
        // Trigger deactivation hook for other components
        do_action('mld_saved_searches_deactivated');
    }
    
    /**
     * Create required pages
     */
    private static function create_pages() {
        $pages = [
            'my-saved-searches' => [
                'title' => 'My Saved Searches',
                'content' => '[mld_saved_searches]'
            ],
            'my-saved-properties' => [
                'title' => 'My Saved Properties',
                'content' => '[mld_saved_properties]'
            ]
        ];
        
        foreach ($pages as $slug => $page_data) {
            // Check if page exists
            $page = get_page_by_path($slug);
            
            if (!$page) {
                // Create page
                wp_insert_post([
                    'post_title' => $page_data['title'],
                    'post_content' => $page_data['content'],
                    'post_status' => 'publish',
                    'post_type' => 'page',
                    'post_name' => $slug
                ]);
            }
        }
    }
    
    /**
     * Enqueue frontend assets
     */
    public static function enqueue_frontend_assets() {
        // Only load on relevant pages
        if (!self::should_load_assets()) {
            return;
        }
        
        // Styles
        wp_enqueue_style(
            'mld-saved-searches',
            MLD_PLUGIN_URL . 'assets/css/mld-saved-searches.css',
            [],
            MLD_VERSION
        );

        // Mobile fix for save search modal
        wp_enqueue_style(
            'mld-saved-searches-mobile-fix',
            MLD_PLUGIN_URL . 'assets/css/mld-saved-searches-mobile-fix.css',
            ['mld-saved-searches'],
            MLD_VERSION
        );
        
        // Scripts
        wp_enqueue_script(
            'mld-saved-searches',
            MLD_PLUGIN_URL . 'assets/js/mld-saved-searches.js',
            ['jquery'],
            MLD_VERSION,
            true
        );

        // Mobile fix JavaScript
        wp_enqueue_script(
            'mld-saved-searches-mobile-fix',
            MLD_PLUGIN_URL . 'assets/js/mld-saved-searches-mobile-fix.js',
            ['jquery', 'mld-saved-searches'],
            MLD_VERSION,
            true
        );
        
        // Localize script
        wp_localize_script('mld-saved-searches', 'mldSavedSearches', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('mld_saved_searches'),
            'isLoggedIn' => is_user_logged_in(),
            'loginUrl' => wp_login_url(get_permalink()),
            'registerUrl' => home_url('/signup/'),
            'isAdmin' => current_user_can('manage_options'),
            'strings' => [
                'confirmDelete' => __('Are you sure you want to delete this saved search?', 'mld'),
                'confirmUnsubscribe' => __('Are you sure you want to unsubscribe from this search?', 'mld'),
                'saving' => __('Saving...', 'mld'),
                'saved' => __('Saved!', 'mld'),
                'error' => __('An error occurred. Please try again.', 'mld')
            ]
        ]);
    }
    
    /**
     * Enqueue admin assets
     */
    public static function enqueue_admin_assets($hook) {
        // This is now handled by MLD_Saved_Search_Admin class
        // Keeping this method for backwards compatibility but empty
    }
    
    /**
     * Check if assets should be loaded
     */
    private static function should_load_assets() {
        // Always load on property pages
        if (is_singular('property')) {
            return true;
        }
        
        // Load on map/search pages
        if (is_page_template('template-map.php') || is_search()) {
            return true;
        }
        
        // Load on saved search pages
        $saved_search_pages = ['my-saved-searches', 'my-saved-properties'];
        if (is_page($saved_search_pages)) {
            return true;
        }
        
        // Load if shortcode is present
        global $post;
        if ($post && (
            has_shortcode($post->post_content, 'mld_map') ||
            has_shortcode($post->post_content, 'mld_saved_searches') ||
            has_shortcode($post->post_content, 'mld_saved_properties')
        )) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Add body classes
     */
    public static function add_body_classes($classes) {
        if (is_user_logged_in()) {
            $classes[] = 'mld-user-logged-in';
            
            if (current_user_can('manage_options')) {
                $classes[] = 'mld-user-admin';
            }
        }
        
        // Add class for saved search pages
        if (is_page(['my-saved-searches', 'my-saved-properties'])) {
            $classes[] = 'mld-saved-search-page';
        }
        
        return $classes;
    }
    
    /**
     * Get saved search settings
     */
    public static function get_settings() {
        return [
            'max_searches_per_user' => apply_filters('mld_max_saved_searches_per_user', 10),
            'max_saved_properties' => apply_filters('mld_max_saved_properties', 100),
            'enable_instant_notifications' => apply_filters('mld_enable_instant_notifications', true),
            'notification_from_email' => get_option('mld_notification_from_email', get_option('admin_email')),
            'notification_from_name' => get_option('mld_notification_from_name', get_bloginfo('name'))
        ];
    }
}

// Initialize on plugins_loaded
add_action('plugins_loaded', ['MLD_Saved_Search_Init', 'init'], 15);