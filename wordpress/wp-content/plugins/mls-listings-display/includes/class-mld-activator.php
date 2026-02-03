<?php
/**
 * Fired during plugin activation.
 *
 * This class defines all code necessary to run during the plugin's activation.
 * Ensures all database tables are created properly.
 *
 * @package MLS_Listings_Display
 * @since 4.4.1
 */

class MLD_Activator {

    /**
     * Plugin activation handler.
     * Creates all necessary database tables and performs initial setup.
     */
    public static function activate() {
        // Create all database tables
        self::create_all_tables();

        // Run installation verifier to ensure everything is set up correctly
        if (file_exists(plugin_dir_path(__FILE__) . 'class-mld-installation-verifier.php')) {
            require_once plugin_dir_path(__FILE__) . 'class-mld-installation-verifier.php';
            if (class_exists('MLD_Installation_Verifier')) {
                MLD_Installation_Verifier::verify_and_repair();
            }
        }

        // Set up rewrite rules
        if (class_exists('MLD_Rewrites')) {
            MLD_Rewrites::activate();
        }

        // Schedule background import for schools if needed
        self::schedule_initial_imports();

        // Schedule cron jobs for saved searches
        self::schedule_saved_search_crons();

        // Run saved search system activation (creates tables and schedules crons)
        self::activate_saved_search_system();

        // Initialize instant notifications
        self::initialize_instant_notifications();

        // Set flag to flush rewrite rules on next init
        // This ensures all rules from MLD_Sitemap_Generator, MLD_State_Pages,
        // MLD_Property_Type_Pages, etc. are registered BEFORE the flush
        set_transient('mld_flush_rewrite_rules', true, 60);

        // Set activation flag
        update_option('mld_plugin_activated', current_time('timestamp'));
    }

    /**
     * Create all database tables required by the plugin
     */
    private static function create_all_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        // 1. Create form submissions table
        self::create_form_submissions_table($charset_collate);

        // 2. Create schools tables
        self::create_schools_tables($charset_collate);

        // 3. Create city boundaries table
        self::create_city_boundaries_table($charset_collate);

        // 4. Create saved search related tables
        self::create_saved_search_tables($charset_collate);

        // 5. Create instant notification tables
        self::create_instant_notification_tables();

        // 6. Create V2 notification system tables
        self::create_v2_notification_tables();

        // 7. Use database verification tool to create ALL remaining tables
        // This ensures all 52+ tables are created during activation
        self::create_all_verified_tables();
    }

    /**
     * Create all tables defined in the database verification tool
     * This ensures no tables are missed during activation
     */
    private static function create_all_verified_tables() {
        // Load the database verification class if not already loaded
        $verify_file = plugin_dir_path(__FILE__) . 'class-mld-database-verify.php';
        if (file_exists($verify_file)) {
            require_once $verify_file;

            if (class_exists('MLD_Database_Verify')) {
                $verifier = MLD_Database_Verify::get_instance();
                // repair_tables() will create any missing tables from the verified list
                $verifier->repair_tables();
            }
        }
    }

    /**
     * Create form submissions table
     */
    private static function create_form_submissions_table($charset_collate) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'mld_form_submissions';

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            form_type varchar(50) NOT NULL,
            property_mls varchar(50) DEFAULT NULL,
            property_address text DEFAULT NULL,
            first_name varchar(100) NOT NULL,
            last_name varchar(100) NOT NULL,
            email varchar(100) NOT NULL,
            phone varchar(20) DEFAULT NULL,
            message text DEFAULT NULL,
            tour_type varchar(50) DEFAULT NULL,
            preferred_date date DEFAULT NULL,
            preferred_time varchar(50) DEFAULT NULL,
            ip_address varchar(45) DEFAULT NULL,
            user_agent text DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            status varchar(20) DEFAULT 'new',
            PRIMARY KEY (id),
            KEY form_type (form_type),
            KEY property_mls (property_mls),
            KEY created_at (created_at),
            KEY status (status)
        ) $charset_collate;";

        dbDelta($sql);
    }

    /**
     * Create schools related tables
     */
    private static function create_schools_tables($charset_collate) {
        global $wpdb;

        // Main schools table
        $schools_table = $wpdb->prefix . 'mld_schools';
        $sql = "CREATE TABLE IF NOT EXISTS $schools_table (
            id INT(11) NOT NULL AUTO_INCREMENT,
            osm_id BIGINT UNIQUE,
            name VARCHAR(255) NOT NULL,
            school_type VARCHAR(50),
            grades VARCHAR(50),
            school_level VARCHAR(20),
            address VARCHAR(255),
            city VARCHAR(100),
            state VARCHAR(50) DEFAULT 'Massachusetts',
            postal_code VARCHAR(20),
            latitude DECIMAL(10, 7),
            longitude DECIMAL(10, 7),
            phone VARCHAR(50),
            website VARCHAR(255),
            rating DECIMAL(3, 1),
            rating_source VARCHAR(50),
            student_count INT,
            student_teacher_ratio DECIMAL(4, 1),
            district VARCHAR(255),
            district_id INT,
            data_source VARCHAR(50) DEFAULT 'OpenStreetMap',
            amenities TEXT,
            last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_location (latitude, longitude),
            KEY idx_city (city),
            KEY idx_type (school_type),
            KEY idx_level (school_level),
            KEY idx_rating (rating),
            KEY idx_district (district_id)
        ) $charset_collate;";

        dbDelta($sql);

        // Property-School relationships table
        $property_schools_table = $wpdb->prefix . 'mld_property_schools';
        $sql2 = "CREATE TABLE IF NOT EXISTS $property_schools_table (
            id INT(11) NOT NULL AUTO_INCREMENT,
            listing_id VARCHAR(50) NOT NULL,
            school_id INT NOT NULL,
            distance_miles DECIMAL(4, 2),
            drive_time_minutes INT,
            walk_time_minutes INT,
            assigned_school BOOLEAN DEFAULT FALSE,
            school_level VARCHAR(20),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY listing_school (listing_id, school_id),
            KEY idx_listing (listing_id),
            KEY idx_school (school_id),
            KEY idx_distance (distance_miles),
            KEY idx_assigned (assigned_school)
        ) $charset_collate;";

        dbDelta($sql2);
    }

    /**
     * Create city boundaries table
     */
    private static function create_city_boundaries_table($charset_collate) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'mld_city_boundaries';

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id INT(11) NOT NULL AUTO_INCREMENT,
            city VARCHAR(100) NOT NULL,
            state VARCHAR(50) NOT NULL,
            country VARCHAR(50) DEFAULT 'USA',
            boundary_type VARCHAR(50) DEFAULT 'city',
            display_name VARCHAR(255),
            boundary_data LONGTEXT NOT NULL,
            bbox_north DECIMAL(10, 7),
            bbox_south DECIMAL(10, 7),
            bbox_east DECIMAL(10, 7),
            bbox_west DECIMAL(10, 7),
            fetched_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            last_used TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY city_state_type (city, state, boundary_type),
            KEY last_used_idx (last_used)
        ) $charset_collate;";

        dbDelta($sql);
    }

    /**
     * Create saved search related tables
     */
    private static function create_saved_search_tables($charset_collate) {
        global $wpdb;

        // Saved searches table
        $saved_searches = $wpdb->prefix . 'mld_saved_searches';
        $sql1 = "CREATE TABLE IF NOT EXISTS $saved_searches (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            created_by_admin BIGINT(20) UNSIGNED DEFAULT NULL,
            name VARCHAR(255) NOT NULL,
            description TEXT,
            filters LONGTEXT NOT NULL,
            polygon_shapes LONGTEXT,
            search_url TEXT,
            notification_frequency ENUM('instant', 'hourly', 'daily', 'weekly') DEFAULT 'instant',
            is_active BOOLEAN DEFAULT TRUE,
            exclude_disliked BOOLEAN DEFAULT TRUE,
            last_notified_at DATETIME,
            last_matched_count INT DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_user_id (user_id),
            KEY idx_created_by (created_by_admin),
            KEY idx_frequency (notification_frequency),
            KEY idx_active (is_active),
            KEY idx_last_notified (last_notified_at)
        ) $charset_collate;";

        dbDelta($sql1);

        // Saved search results table
        $search_results = $wpdb->prefix . 'mld_saved_search_results';
        $sql2 = "CREATE TABLE IF NOT EXISTS $search_results (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            saved_search_id BIGINT(20) UNSIGNED NOT NULL,
            listing_id VARCHAR(50) NOT NULL,
            listing_key VARCHAR(128) NOT NULL,
            first_seen_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            notified_at DATETIME,
            PRIMARY KEY (id),
            UNIQUE KEY unique_search_listing (saved_search_id, listing_id),
            KEY idx_notified (notified_at)
        ) $charset_collate;";

        dbDelta($sql2);

        // Email settings table
        $email_settings = $wpdb->prefix . 'mld_saved_search_email_settings';
        $sql3 = "CREATE TABLE IF NOT EXISTS $email_settings (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            saved_search_id BIGINT(20) UNSIGNED NOT NULL,
            admin_id BIGINT(20) UNSIGNED NOT NULL,
            email_type ENUM('cc', 'bcc', 'none') DEFAULT 'none',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_search_admin (saved_search_id, admin_id),
            KEY idx_admin_id (admin_id)
        ) $charset_collate;";

        dbDelta($sql3);

        // Property preferences table
        $preferences = $wpdb->prefix . 'mld_property_preferences';
        $sql4 = "CREATE TABLE IF NOT EXISTS $preferences (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            listing_id VARCHAR(50) NOT NULL,
            listing_key VARCHAR(128) NOT NULL,
            preference_type ENUM('liked', 'disliked') NOT NULL,
            reason TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_user_listing (user_id, listing_id),
            KEY idx_user_preference (user_id, preference_type),
            KEY idx_listing_id (listing_id),
            KEY idx_created_at (created_at)
        ) $charset_collate;";

        dbDelta($sql4);

        // Cron log table
        $cron_log = $wpdb->prefix . 'mld_saved_search_cron_log';
        $sql5 = "CREATE TABLE IF NOT EXISTS $cron_log (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            frequency ENUM('instant', 'hourly', 'daily', 'weekly') NOT NULL,
            execution_time DATETIME NOT NULL,
            searches_processed INT DEFAULT 0,
            notifications_sent INT DEFAULT 0,
            errors INT DEFAULT 0,
            execution_duration FLOAT DEFAULT 0,
            status ENUM('success', 'failed', 'partial') DEFAULT 'success',
            error_details TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_frequency (frequency),
            KEY idx_execution_time (execution_time),
            KEY idx_status (status)
        ) $charset_collate;";

        dbDelta($sql5);

        // Agent-client relationships table (v6.33.0 - full schema)
        $relationships = $wpdb->prefix . 'mld_agent_client_relationships';
        $sql6 = "CREATE TABLE IF NOT EXISTS $relationships (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            agent_id BIGINT(20) UNSIGNED NOT NULL,
            client_id BIGINT(20) UNSIGNED NOT NULL,
            status ENUM('active', 'inactive') DEFAULT 'active',
            relationship_status ENUM('active', 'inactive', 'pending') DEFAULT 'active',
            assigned_date DATETIME DEFAULT CURRENT_TIMESTAMP,
            notes TEXT,
            is_active TINYINT(1) DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_agent_client (agent_id, client_id),
            KEY idx_agent (agent_id),
            KEY idx_client (client_id),
            KEY idx_status (status),
            KEY idx_relationship_status (relationship_status)
        ) $charset_collate;";

        dbDelta($sql6);

        // Agent profiles table (v6.33.0 - full schema with extended fields)
        $agent_profiles = $wpdb->prefix . 'mld_agent_profiles';
        $sql7 = "CREATE TABLE IF NOT EXISTS $agent_profiles (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            snab_staff_id BIGINT(20) UNSIGNED DEFAULT NULL,
            display_name VARCHAR(255),
            title VARCHAR(100),
            agency_name VARCHAR(255),
            office_name VARCHAR(255),
            office_address TEXT,
            license_number VARCHAR(100),
            phone VARCHAR(20),
            email VARCHAR(255),
            bio TEXT,
            email_signature TEXT,
            custom_greeting TEXT,
            specializations TEXT,
            specialties TEXT,
            service_areas TEXT,
            profile_image_url VARCHAR(500),
            photo_url VARCHAR(500),
            social_links LONGTEXT,
            is_verified TINYINT(1) DEFAULT 0,
            is_active TINYINT(1) DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_user (user_id),
            KEY idx_verified (is_verified),
            KEY idx_active (is_active),
            KEY idx_snab_staff (snab_staff_id)
        ) $charset_collate;";

        dbDelta($sql7);

        // Admin client preferences table (v6.33.0 - full schema)
        $admin_prefs = $wpdb->prefix . 'mld_admin_client_preferences';
        $sql8 = "CREATE TABLE IF NOT EXISTS $admin_prefs (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            admin_id BIGINT(20) UNSIGNED NOT NULL,
            client_id BIGINT(20) UNSIGNED NOT NULL,
            email_copy_type ENUM('cc', 'bcc', 'none') DEFAULT 'none',
            default_email_type ENUM('cc', 'bcc', 'none') DEFAULT 'none',
            default_cc_all TINYINT(1) DEFAULT 0,
            can_view_searches TINYINT(1) DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_admin_client (admin_id, client_id),
            KEY idx_admin (admin_id),
            KEY idx_client (client_id)
        ) $charset_collate;";

        dbDelta($sql8);

        // User types table (v6.33.0 - agent/client classification)
        $user_types = $wpdb->prefix . 'mld_user_types';
        $sql9 = "CREATE TABLE IF NOT EXISTS $user_types (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            user_type ENUM('client', 'agent', 'admin') DEFAULT 'client',
            promoted_by BIGINT(20) UNSIGNED DEFAULT NULL,
            promoted_at DATETIME DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_user (user_id),
            KEY idx_type (user_type)
        ) $charset_collate;";

        dbDelta($sql9);

        // Shared properties table (v6.35.0 - agent property sharing)
        $shared_properties = $wpdb->prefix . 'mld_shared_properties';
        $sql10 = "CREATE TABLE IF NOT EXISTS $shared_properties (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            agent_id BIGINT(20) UNSIGNED NOT NULL,
            client_id BIGINT(20) UNSIGNED NOT NULL,
            listing_id VARCHAR(50) NOT NULL,
            listing_key VARCHAR(128) NOT NULL,
            agent_note TEXT,
            shared_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            viewed_at DATETIME DEFAULT NULL,
            view_count INT UNSIGNED DEFAULT 0,
            client_response ENUM('none', 'interested', 'not_interested') DEFAULT 'none',
            client_note TEXT,
            is_dismissed TINYINT(1) DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_share (agent_id, client_id, listing_key),
            KEY idx_agent (agent_id),
            KEY idx_client (client_id),
            KEY idx_listing (listing_key),
            KEY idx_shared_at (shared_at),
            KEY idx_dismissed (is_dismissed)
        ) $charset_collate;";

        dbDelta($sql10);
    }

    /**
     * Schedule initial data imports if needed
     */
    private static function schedule_initial_imports() {
        global $wpdb;

        // Check if schools data needs to be imported
        $schools_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}mld_schools");

        if ($schools_count == 0) {
            // Schedule background import instead of doing it during activation
            if (!wp_next_scheduled('mld_import_schools_data')) {
                wp_schedule_single_event(time() + 10, 'mld_import_schools_data');
            }
        }
    }

    /**
     * Create instant notification tables
     */
    private static function create_instant_notification_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        // Create search activity matches table
        $table_matches = $wpdb->prefix . 'mld_search_activity_matches';
        $sql_matches = "CREATE TABLE IF NOT EXISTS $table_matches (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            activity_log_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            saved_search_id BIGINT UNSIGNED NOT NULL,
            listing_id VARCHAR(50) NOT NULL,
            match_type ENUM('new_listing', 'price_drop', 'price_reduced', 'price_increase', 'price_increased', 'status_change', 'back_on_market', 'open_house', 'sold', 'coming_soon', 'property_updated', 'daily_digest', 'weekly_digest', 'hourly_digest') NOT NULL DEFAULT 'new_listing',
            match_score INT DEFAULT 100,
            notification_status ENUM('pending', 'sent', 'failed', 'throttled') DEFAULT 'pending',
            notified_at DATETIME,
            notification_channels TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY idx_activity (activity_log_id),
            KEY idx_search (saved_search_id),
            KEY idx_listing (listing_id),
            KEY idx_status (notification_status),
            KEY idx_created (created_at)
        ) $charset_collate";

        dbDelta($sql_matches);

        // Create notification preferences table
        $table_preferences = $wpdb->prefix . 'mld_notification_preferences';
        $sql_preferences = "CREATE TABLE IF NOT EXISTS $table_preferences (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            saved_search_id BIGINT UNSIGNED DEFAULT NULL,
            instant_app_notifications BOOLEAN DEFAULT TRUE,
            instant_email_notifications BOOLEAN DEFAULT TRUE,
            instant_sms_notifications BOOLEAN DEFAULT FALSE,
            quiet_hours_enabled BOOLEAN DEFAULT TRUE,
            quiet_hours_start TIME DEFAULT '22:00:00',
            quiet_hours_end TIME DEFAULT '08:00:00',
            throttling_enabled BOOLEAN DEFAULT TRUE,
            max_daily_notifications INT DEFAULT 50,
            notification_types TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_user_search (user_id, saved_search_id),
            KEY idx_user (user_id)
        ) $charset_collate";

        dbDelta($sql_preferences);

        // Create notification throttle table
        $table_throttle = $wpdb->prefix . 'mld_notification_throttle';
        $sql_throttle = "CREATE TABLE IF NOT EXISTS $table_throttle (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            saved_search_id BIGINT UNSIGNED NOT NULL,
            notification_date DATE NOT NULL,
            notification_count INT DEFAULT 0,
            last_notification_at DATETIME,
            throttled_count INT DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_user_search_date (user_id, saved_search_id, notification_date),
            KEY idx_date (notification_date)
        ) $charset_collate";

        dbDelta($sql_throttle);

        // Also try to load from separate file if it exists
        $instant_notifications_path = plugin_dir_path(__FILE__) . 'instant-notifications/class-mld-database-installer.php';
        if (file_exists($instant_notifications_path)) {
            require_once $instant_notifications_path;
            if (class_exists('MLD_Database_Installer')) {
                MLD_Database_Installer::install();
            }
        }
    }

    /**
     * Schedule cron jobs for saved searches
     */
    private static function schedule_saved_search_crons() {
        // Register custom schedules
        add_filter('cron_schedules', function($schedules) {
            if (!isset($schedules['every_minute'])) {
                $schedules['every_minute'] = [
                    'interval' => 60,
                    'display' => __('Every Minute', 'mld')
                ];
            }
            if (!isset($schedules['weekly'])) {
                $schedules['weekly'] = [
                    'interval' => 604800,
                    'display' => __('Weekly', 'mld')
                ];
            }
            return $schedules;
        });

        // Schedule the cron jobs
        // DISABLED v6.13.14: These legacy hooks have no handlers - replaced by mld_saved_search_* hooks
        // The new unified processor (MLD_Fifteen_Minute_Processor) handles all notification frequencies
        $cron_jobs = [
            // 'mld_process_instant_searches' => 'every_minute',  // No handler - deprecated
            // 'mld_process_hourly_searches' => 'hourly',         // No handler - deprecated
            // 'mld_process_daily_searches' => 'daily',           // No handler - deprecated
            // 'mld_process_weekly_searches' => 'weekly',         // No handler - deprecated
            'mld_instant_notifications_cleanup' => 'daily'        // Keep cleanup job
        ];

        foreach ($cron_jobs as $hook => $schedule) {
            if (!wp_next_scheduled($hook)) {
                wp_schedule_event(time(), $schedule, $hook);
            }
        }
    }

    /**
     * Activate saved search system
     */
    private static function activate_saved_search_system() {
        // Load saved search init class if not already loaded
        $init_path = plugin_dir_path(__FILE__) . 'saved-searches/class-mld-saved-search-init.php';
        if (file_exists($init_path)) {
            require_once $init_path;

            // Call the activation method which schedules all cron events
            if (class_exists('MLD_Saved_Search_Init')) {
                MLD_Saved_Search_Init::activate();
            }
        }
    }

    /**
     * Initialize instant notifications system
     */
    private static function initialize_instant_notifications() {
        // Set default options
        $options = [
            'mld_instant_notifications_enabled' => true,
            'mld_global_quiet_hours_enabled' => true,
            'mld_global_throttling_enabled' => true,
            'mld_override_user_preferences' => false,
            'mld_instant_bulk_threshold' => 10,
            'mld_instant_quiet_hours_start' => '22:00',
            'mld_instant_quiet_hours_end' => '08:00',
            'mld_default_daily_limit' => 50,
            'mld_throttle_window_minutes' => 5,
            'mld_max_notifications_per_window' => 10,
            'mld_enable_bulk_import_throttle' => true,
            'mld_bulk_import_threshold' => 50,
            'mld_enable_notification_logs' => true,
            'mld_log_retention_days' => 30,
            'mld_instant_notifications_db_version' => '1.1.0'
        ];

        foreach ($options as $option_name => $default_value) {
            if (get_option($option_name) === false) {
                update_option($option_name, $default_value);
            }
        }

        // Try to initialize the instant notifications system
        $instant_init_path = plugin_dir_path(__FILE__) . 'instant-notifications/class-mld-instant-notifications-init.php';
        if (file_exists($instant_init_path)) {
            require_once $instant_init_path;

            // Load core dependencies first
            $core_dependencies = [
                'class-mld-instant-matcher.php',
                'class-mld-notification-router.php',
                'class-mld-throttle-manager.php',
                'class-mld-email-sender.php'
            ];

            $base_path = plugin_dir_path(__FILE__) . 'instant-notifications/';
            foreach ($core_dependencies as $file) {
                if (file_exists($base_path . $file)) {
                    require_once $base_path . $file;
                }
            }

            // Only load BuddyBoss dependencies if BuddyBoss is active
            if (class_exists('BuddyPress') && class_exists('BP_Core_Notification_Abstract')) {
                $buddyboss_dependencies = [
                    'class-mld-buddyboss-integration.php',
                    'class-mld-buddyboss-notification.php' // Modern API
                ];

                foreach ($buddyboss_dependencies as $file) {
                    if (file_exists($base_path . $file)) {
                        require_once $base_path . $file;
                    }
                }

                // Register Modern BuddyBoss Notification API
                if (class_exists('MLD_BuddyBoss_Notification')) {
                    // Create instance - it will self-register with bp_init
                    MLD_BuddyBoss_Notification::instance();
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log('[MLD Activator] Modern BuddyBoss Notification API instance created on activation');
                    }
                }
            }

            if (class_exists('MLD_Instant_Notifications_Init')) {
                MLD_Instant_Notifications_Init::get_instance();
            }
        }
    }

    /**
     * Create V2 notification system tables
     */
    private static function create_v2_notification_tables() {
        // Ensure plugin path is defined
        if (!defined('MLD_PLUGIN_PATH')) {
            define('MLD_PLUGIN_PATH', plugin_dir_path(dirname(__FILE__)));
        }

        // Load and initialize the V2 database system
        $database_path = MLD_PLUGIN_PATH . 'includes/notifications/class-mld-notification-database.php';
        if (file_exists($database_path)) {
            require_once $database_path;

            // Force database creation and versioning
            MLD_Notification_Database::check_database_version();

            // Set V2 system as available
            update_option('mld_v2_notification_system_available', true);
        }
    }
}
