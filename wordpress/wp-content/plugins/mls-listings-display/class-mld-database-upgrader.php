<?php
/**
 * Database Upgrader for MLS Listings Display
 *
 * Handles database migrations and updates when plugin is upgraded
 *
 * @package MLS_Listings_Display
 * @since 5.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Database_Upgrader {

    /**
     * Current database version
     * Updated to match plugin version for version consistency
     */
    const DB_VERSION = '6.68.18';

    /**
     * Run database upgrades
     */
    public static function upgrade() {
        $current_db_version = get_option('mld_db_version', '0');

        // If current version is up to date, return
        if (version_compare($current_db_version, self::DB_VERSION, '>=')) {
            return;
        }

        // Run upgrades based on version
        if (version_compare($current_db_version, '5.0.0', '<')) {
            self::upgrade_to_5_0_0();
        }

        if (version_compare($current_db_version, '5.1.0', '<')) {
            self::upgrade_to_5_1_0();
        }

        if (version_compare($current_db_version, '5.1.1', '<')) {
            self::upgrade_to_5_1_1();
        }

        if (version_compare($current_db_version, '5.1.2', '<')) {
            self::upgrade_to_5_1_2();
        }

        if (version_compare($current_db_version, '5.1.3', '<')) {
            self::upgrade_to_5_1_3();
        }

        if (version_compare($current_db_version, '5.2.1', '<')) {
            self::upgrade_to_5_2_1();
        }

        if (version_compare($current_db_version, '5.2.2', '<')) {
            self::upgrade_to_5_2_2();
        }

        if (version_compare($current_db_version, '5.2.3', '<')) {
            self::upgrade_to_5_2_3();
        }

        if (version_compare($current_db_version, '6.0.3', '<')) {
            self::upgrade_to_6_0_3();
        }

        if (version_compare($current_db_version, '6.9.0', '<')) {
            self::upgrade_to_6_9_0();
        }

        if (version_compare($current_db_version, '6.9.1', '<')) {
            self::upgrade_to_6_9_1();
        }

        if (version_compare($current_db_version, '6.9.2', '<')) {
            self::upgrade_to_6_9_2();
        }

        // Always run comprehensive table verification on upgrade
        // This ensures any missing tables are created
        if (version_compare($current_db_version, '6.11.31', '<')) {
            self::upgrade_to_6_11_31();
        }

        // v6.54.1 - Comprehensive update with notification and referral tables
        if (version_compare($current_db_version, '6.54.1', '<')) {
            self::upgrade_to_6_54_1();
        }

        // v6.54.2 - Clean up duplicate notifications from before deduplication fix
        if (version_compare($current_db_version, '6.54.2', '<')) {
            self::upgrade_to_6_54_2();
        }

        // Update database version
        update_option('mld_db_version', self::DB_VERSION);

        // Fire upgrade completed action for other components to hook into
        do_action('mld_database_upgrade_completed', $current_db_version, self::DB_VERSION);
    }

    /**
     * Run on plugin activation - ensures all tables exist
     */
    public static function activate() {
        $current_db_version = get_option('mld_db_version', '0');

        // If this is a fresh install or upgrade, run all migrations
        if (version_compare($current_db_version, self::DB_VERSION, '<')) {
            self::upgrade();
        }

        // Fire activation completed action
        do_action('mld_database_activation_completed');
    }

    /**
     * Upgrade to version 5.0.0
     * Creates notification tracking tables
     */
    private static function upgrade_to_5_0_0() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        // Create notification tracker table
        $table_name = $wpdb->prefix . 'mld_notification_tracker';
        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            mls_number varchar(50) NOT NULL,
            search_id bigint(20) unsigned NOT NULL,
            notification_type varchar(50) DEFAULT 'listing_update',
            sent_at datetime NOT NULL,
            email_sent tinyint(1) DEFAULT 1,
            buddyboss_sent tinyint(1) DEFAULT 0,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY mls_number (mls_number),
            KEY search_id (search_id),
            KEY sent_at (sent_at),
            UNIQUE KEY unique_notification (user_id, mls_number, search_id)
        ) {$charset_collate};";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        // Create saved searches table if it doesn't exist
        $saved_searches_table = $wpdb->prefix . 'mld_saved_searches';
        $sql = "CREATE TABLE IF NOT EXISTS {$saved_searches_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            search_name varchar(255) NOT NULL,
            search_criteria longtext NOT NULL,
            notification_frequency varchar(50) DEFAULT 'instant',
            is_active tinyint(1) DEFAULT 1,
            last_notified datetime DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY is_active (is_active),
            KEY notification_frequency (notification_frequency)
        ) {$charset_collate};";

        dbDelta($sql);

        // Update existing saved searches table columns if needed
        self::ensure_column_exists($saved_searches_table, 'notification_frequency', "varchar(50) DEFAULT 'instant'");
        self::ensure_column_exists($saved_searches_table, 'is_active', "tinyint(1) DEFAULT 1");

        // Rename old columns if they exist
        self::rename_column_if_exists($saved_searches_table, 'status', 'is_active');
        self::rename_column_if_exists($saved_searches_table, 'notifications_enabled', 'notification_frequency');
    }

    /**
     * Upgrade to version 5.1.0
     * Adds URL settings and ensures all columns exist
     */
    private static function upgrade_to_5_1_0() {
        // Add default URL settings if not exist
        $settings = get_option('mld_settings', []);

        $defaults = [
            'search_page_url' => '/search/',
            'saved_searches_url' => '/saved-search/',
            'login_url' => '/wp-login.php',
            'register_url' => '/register/'
        ];

        $updated = false;
        foreach ($defaults as $key => $value) {
            if (!isset($settings[$key])) {
                $settings[$key] = $value;
                $updated = true;
            }
        }

        if ($updated) {
            update_option('mld_settings', $settings);
        }

        // Ensure notification tracker table has all required columns
        global $wpdb;
        $table_name = $wpdb->prefix . 'mld_notification_tracker';

        self::ensure_column_exists($table_name, 'email_sent', "tinyint(1) DEFAULT 1");
        self::ensure_column_exists($table_name, 'buddyboss_sent', "tinyint(1) DEFAULT 0");

        // Clean up old notification data older than 90 days
        $cutoff_date = date('Y-m-d H:i:s', strtotime('-90 days'));
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table_name} WHERE sent_at < %s",
            $cutoff_date
        ));
    }

    /**
     * Helper function to check if column exists and add if not
     */
    private static function ensure_column_exists($table, $column, $definition) {
        global $wpdb;

        $column_exists = $wpdb->get_results($wpdb->prepare(
            "SHOW COLUMNS FROM `{$table}` LIKE %s",
            $column
        ));

        if (empty($column_exists)) {
            $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        }
    }

    /**
     * Upgrade to version 5.1.1
     * Fixes missing schools table columns and memory issues
     */
    private static function upgrade_to_5_1_1() {
        global $wpdb;

        // Add missing level column to schools table
        $schools_table = $wpdb->prefix . 'mld_schools';
        self::ensure_column_exists($schools_table, 'level', "VARCHAR(50) DEFAULT 'Elementary'");

        // Add other potentially missing columns
        self::ensure_column_exists($schools_table, 'rating', "INT(2) DEFAULT NULL");
        self::ensure_column_exists($schools_table, 'grades', "VARCHAR(50) DEFAULT NULL");
        self::ensure_column_exists($schools_table, 'type', "VARCHAR(50) DEFAULT 'Public'");

        // Create schools table if it doesn't exist
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE IF NOT EXISTS {$schools_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            school_id varchar(100) NOT NULL,
            name varchar(255) NOT NULL,
            level varchar(50) DEFAULT 'Elementary',
            rating int(2) DEFAULT NULL,
            grades varchar(50) DEFAULT NULL,
            type varchar(50) DEFAULT 'Public',
            address varchar(255) DEFAULT NULL,
            city varchar(100) DEFAULT NULL,
            state varchar(50) DEFAULT NULL,
            zip varchar(20) DEFAULT NULL,
            phone varchar(50) DEFAULT NULL,
            website varchar(255) DEFAULT NULL,
            latitude decimal(10,8) DEFAULT NULL,
            longitude decimal(11,8) DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY school_id (school_id),
            KEY name (name),
            KEY level (level)
        ) {$charset_collate};";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        // Create property_schools relationship table if missing
        $property_schools_table = $wpdb->prefix . 'mld_property_schools';
        $sql = "CREATE TABLE IF NOT EXISTS {$property_schools_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            listing_id varchar(50) NOT NULL,
            school_id bigint(20) unsigned NOT NULL,
            distance decimal(5,2) DEFAULT NULL,
            PRIMARY KEY (id),
            KEY listing_id (listing_id),
            KEY school_id (school_id)
        ) {$charset_collate};";

        dbDelta($sql);
    }

    /**
     * Upgrade to version 5.1.2
     * Fixes database index creation errors
     */
    private static function upgrade_to_5_1_2() {
        global $wpdb;

        // Check if database migrator exists and run safe index creation
        $migrator_file = plugin_dir_path(__FILE__) . 'class-mld-database-migrator.php';
        if (file_exists($migrator_file)) {
            require_once $migrator_file;

            // Only try to create indexes with column validation
            // The updated migrator will handle column checking automatically
            $migrator = new MLD_Database_Migrator();

            // Run only the index creation phase with column validation
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD Database Upgrader: Running safe index creation for v5.1.2');
            }

            // We don't need to do anything special here because the migrator
            // now checks for column existence before creating indexes
        }

        // Clean up any duplicate indexes from previous attempts
        $tables_to_check = array(
            $wpdb->prefix . 'mld_saved_searches',
            $wpdb->prefix . 'mld_notification_tracker',
            $wpdb->prefix . 'mld_schools',
            $wpdb->prefix . 'mld_property_schools'
        );

        foreach ($tables_to_check as $table) {
            if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table) {
                // Remove duplicate indexes if they exist
                $indexes = $wpdb->get_results("SHOW INDEX FROM {$table}");
                $index_counts = array();

                foreach ($indexes as $index) {
                    if (!isset($index_counts[$index->Key_name])) {
                        $index_counts[$index->Key_name] = 0;
                    }
                    $index_counts[$index->Key_name]++;
                }

                // No need to remove duplicates, MySQL handles this
            }
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Database Upgrader: Version 5.1.2 upgrade completed');
        }
    }

    /**
     * Upgrade to version 5.1.3
     * Ensures distance column exists in property_schools table
     */
    private static function upgrade_to_5_1_3() {
        global $wpdb;

        // Ensure property_schools table has distance column
        $property_schools_table = $wpdb->prefix . 'mld_property_schools';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$property_schools_table}'") === $property_schools_table) {
            self::ensure_column_exists($property_schools_table, 'distance', 'DECIMAL(5,2) DEFAULT NULL');
        }

        // Ensure form submissions table has required columns
        $form_submissions_table = $wpdb->prefix . 'mld_form_submissions';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$form_submissions_table}'") === $form_submissions_table) {
            // Add source column if missing (needed for utm_source AFTER clause)
            self::ensure_column_exists($form_submissions_table, 'source', 'VARCHAR(100) DEFAULT NULL');
        }

        // Ensure agent relationships table has is_active column
        $agent_relationships_table = $wpdb->prefix . 'mld_agent_client_relationships';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$agent_relationships_table}'") === $agent_relationships_table) {
            self::ensure_column_exists($agent_relationships_table, 'is_active', 'TINYINT(1) DEFAULT 1');
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Database Upgrader: Version 5.1.3 upgrade completed - fixed missing columns');
        }
    }

    /**
     * Helper function to rename column if it exists
     */
    private static function rename_column_if_exists($table, $old_column, $new_column) {
        global $wpdb;

        // Check if old column exists
        $old_exists = $wpdb->get_results($wpdb->prepare(
            "SHOW COLUMNS FROM `{$table}` LIKE %s",
            $old_column
        ));

        // Check if new column already exists
        $new_exists = $wpdb->get_results($wpdb->prepare(
            "SHOW COLUMNS FROM `{$table}` LIKE %s",
            $new_column
        ));

        if (!empty($old_exists) && empty($new_exists)) {
            // Get column type
            $column_info = $wpdb->get_row($wpdb->prepare(
                "SHOW COLUMNS FROM `{$table}` WHERE Field = %s",
                $old_column
            ));

            if ($column_info) {
                $type = $column_info->Type;
                $null = $column_info->Null === 'YES' ? 'NULL' : 'NOT NULL';
                $default = $column_info->Default ? "DEFAULT '{$column_info->Default}'" : '';

                $wpdb->query("ALTER TABLE `{$table}` CHANGE COLUMN `{$old_column}` `{$new_column}` {$type} {$null} {$default}");
            }
        }
    }

    /**
     * Upgrade to version 5.2.1
     * Bug fixes: Duplicate admin menus, version mismatch, CMA email configuration
     * No database changes required - version tracking only
     */
    private static function upgrade_to_5_2_1() {
        // Version 5.2.1 fixes:
        // - Fixed duplicate admin menu items (MLD_Admin and MLD_CMA_Admin instantiated twice)
        // - Fixed version mismatch (MLD_VERSION now matches plugin header)
        // - Fixed CMA email sending (MailHog mu-plugin deployment)
        // No database schema changes in this version
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD: Upgraded to version 5.2.1 - Bug fixes applied');
        }
    }

    /**
     * Upgrade to version 5.2.2
     * Bug fix: Prevent multiple admin class instantiations in saved search system
     * No database changes required - version tracking only
     */
    private static function upgrade_to_5_2_2() {
        // Version 5.2.2 fixes:
        // - Added initialization guard to MLD_Saved_Search_Init to prevent duplicate admin menu items
        // - Saved search admin classes (MLD_Saved_Search_Admin, MLD_Agent_Management_Admin,
        //   MLD_Client_Management_Admin) now only instantiate once
        // No database schema changes in this version
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD: Upgraded to version 5.2.2 - Saved search initialization guard added');
        }
    }

    /**
     * Upgrade to version 5.2.3
     * Property details UI enhancement - Frontend only changes
     * No database changes required - version tracking only
     */
    private static function upgrade_to_5_2_3() {
        // Version 5.2.3 changes:
        // - REDESIGNED: Image gallery with 1 large + 2 preview images layout
        // - REDESIGNED: Floating message widget (smaller, positioned at bottom)
        // - IMPROVED: Design alignment with CMA tool (colors, typography, spacing)
        // - ENHANCED: Full-width property details layout (removed sidebar)
        // - FIXED: Site header and navigation completely hidden on property pages
        // - FIXED: Horizontal rules removed above gallery
        // - OPTIMIZED: Gallery navigation with infinite loop and clickable previews
        // No database schema changes in this version
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD: Upgraded to version 5.2.3 - Property details UI enhancements applied');
        }
    }

    /**
     * Upgrade to version 6.0.3
     * Mobile calculator enhancements - Frontend only changes
     * No database changes required - version tracking only
     */
    private static function upgrade_to_6_0_3() {
        // Version 6.0.3 changes:
        // - ADDED: Desktop-style mortgage calculator to mobile property pages
        // - ADDED: Summary cards showing monthly payment, loan amount, total interest, total cost
        // - ADDED: Rate impact analysis (compare ±0.5% scenarios)
        // - ADDED: Amortization visualization with payment breakdown over time
        // - ADDED: New calculator JavaScript (property-calculator-mobile-v3.js)
        // - ENHANCED: Calculator CSS with mobile-responsive design
        // - FIXED: Input field sizing and number formatting on mobile
        // - FIXED: Save & Share button styling with gradient design
        // No database schema changes in this version
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD: Upgraded to version 6.0.3 - Mobile calculator enhancements applied');
        }
    }

    /**
     * Upgrade to version 6.9.0
     * Comprehensive update with chatbot enhancements, SEO fixes, and performance improvements
     */
    private static function upgrade_to_6_9_0() {
        global $wpdb;

        // Version 6.9.0 changes:
        // - FIXED: Rewrite rules timing issue (SEO pages now work without saving permalinks)
        // - ADDED: A/B testing system for chatbot prompts
        // - ADDED: Advanced training examples with complex filtering
        // - ADDED: Extended prompt variables (business_hours, specialties, service_areas)
        // - IMPROVED: Class initialization timing for plugins_loaded hook

        // Ensure chatbot tables exist
        self::ensure_chatbot_tables();

        // Ensure all rewrite rules are registered
        set_transient('mld_flush_rewrite_rules', true, 60);

        // Clear all caches to ensure fresh state
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }

        // Clear plugin transients (except the flush transient we just set)
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_mld_%' AND option_name != '_transient_mld_flush_rewrite_rules'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_mld_%' AND option_name != '_transient_timeout_mld_flush_rewrite_rules'");

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD: Upgraded to version 6.9.0 - Chatbot enhancements and SEO fixes applied');
        }
    }

    /**
     * Upgrade to version 6.9.1
     * Fixes: Map auto-centering, autocomplete filters, summary table query optimization
     */
    private static function upgrade_to_6_9_1() {
        // Version 6.9.1 changes:
        // 1. Fixed autocomplete filters (MLS Number, Address, etc.) with summary table queries
        // 2. Added map auto-centering when filters are applied
        // 3. Enhanced shouldFitBounds logic for better map UX

        // Clear all caches to ensure fresh state
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD: Upgraded to version 6.9.1 - Map auto-centering and autocomplete filter fixes applied');
        }
    }

    /**
     * Upgrade to version 6.9.2
     * Fixes: Map auto-panning for Building/Neighborhood/Address filters (Redfin/Zillow UX)
     */
    private static function upgrade_to_6_9_2() {
        // Version 6.9.2 changes:
        // 1. Fixed map not panning to Building/Neighborhood when outside current view
        // 2. Added fitToResults parameter to refreshMapListings for explicit fit requests
        // 3. City boundaries now use explicitFitRequested flag for reliable panning
        // 4. Bounds are no longer sent when applying new filters (allows finding results anywhere)

        // Clear all caches to ensure fresh JavaScript is loaded
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD: Upgraded to version 6.9.2 - Map auto-panning fixes for all filter types (Redfin/Zillow UX)');
        }
    }

    /**
     * Ensure chatbot tables exist
     */
    private static function ensure_chatbot_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        // Chat sessions table
        $table_name = $wpdb->prefix . 'mld_chat_sessions';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") != $table_name) {
            $sql = "CREATE TABLE {$table_name} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                session_id varchar(64) NOT NULL,
                user_id bigint(20) unsigned DEFAULT NULL,
                listing_id varchar(50) DEFAULT NULL,
                status enum('active','idle','closed') DEFAULT 'active',
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY session_id (session_id),
                KEY user_id (user_id),
                KEY listing_id (listing_id),
                KEY status (status)
            ) {$charset_collate};";
            dbDelta($sql);
        }

        // Chat messages table
        $table_name = $wpdb->prefix . 'mld_chat_messages';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") != $table_name) {
            $sql = "CREATE TABLE {$table_name} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                session_id varchar(64) NOT NULL,
                role enum('user','assistant','system') NOT NULL,
                content longtext NOT NULL,
                metadata longtext DEFAULT NULL,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY session_id (session_id),
                KEY role (role),
                KEY created_at (created_at)
            ) {$charset_collate};";
            dbDelta($sql);
        }

        // Chat settings table - COMPLETE schema matching class-mld-database-verify.php
        // Fixed in v6.11.44 - was missing setting_type, setting_category, is_encrypted, description columns
        $table_name = $wpdb->prefix . 'mld_chat_settings';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") != $table_name) {
            $sql = "CREATE TABLE {$table_name} (
                id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                setting_key varchar(100) NOT NULL,
                setting_value longtext DEFAULT NULL,
                setting_type varchar(50) DEFAULT 'string',
                setting_category varchar(50) DEFAULT 'general',
                is_encrypted tinyint(1) DEFAULT 0,
                description text DEFAULT NULL,
                updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY unique_setting_key (setting_key),
                KEY setting_category (setting_category)
            ) {$charset_collate};";
            dbDelta($sql);
        }
    }

    /**
     * Upgrade to version 6.11.31
     * Comprehensive table verification and creation
     * Uses the database verification tool to ensure all tables exist
     */
    private static function upgrade_to_6_11_31() {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Database Upgrader: Running comprehensive table verification for v6.11.31');
        }

        // Use the database verification tool to ensure all tables exist
        if (file_exists(plugin_dir_path(__FILE__) . 'class-mld-database-verify.php')) {
            require_once plugin_dir_path(__FILE__) . 'class-mld-database-verify.php';

            if (class_exists('MLD_Database_Verify')) {
                $verifier = MLD_Database_Verify::get_instance();
                $verification = $verifier->verify_tables();

                // Find missing tables
                $missing_tables = array();
                foreach ($verification as $table_suffix => $info) {
                    if (!$info['exists']) {
                        $missing_tables[] = $table_suffix;
                    }
                }

                // If there are missing tables, repair them
                if (!empty($missing_tables)) {
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log('MLD Database Upgrader: Found ' . count($missing_tables) . ' missing tables, creating...');
                    }

                    $repair_result = $verifier->repair_tables($missing_tables);

                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        $success_count = 0;
                        $error_count = 0;
                        foreach ($repair_result as $result) {
                            if ($result['success']) {
                                $success_count++;
                            } else {
                                $error_count++;
                            }
                        }
                        error_log("MLD Database Upgrader: Repair complete - {$success_count} created, {$error_count} errors");
                    }
                } else {
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log('MLD Database Upgrader: All tables exist, no repairs needed');
                    }
                }
            }
        }

        // Clear all caches to ensure fresh data
        self::clear_all_caches();

        // Flush rewrite rules on next page load
        set_transient('mld_flush_rewrite_rules', true, 60);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Database Upgrader: Version 6.11.31 upgrade completed');
        }
    }

    /**
     * Upgrade to version 6.54.1
     * Comprehensive update including:
     * - Deferred notifications table (v6.50.7)
     * - Client notification preferences table (v6.48.2)
     * - Agent referral codes table (v6.52.0)
     * - Referral signups table (v6.52.0)
     * - Push notification log columns (v6.50.0)
     */
    private static function upgrade_to_6_54_1() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Database Upgrader: Running comprehensive upgrade to v6.54.1');
        }

        // Create deferred notifications table (v6.50.7)
        $table_name = $wpdb->prefix . 'mld_deferred_notifications';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") !== $table_name) {
            $sql = "CREATE TABLE {$table_name} (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT(20) UNSIGNED NOT NULL,
                notification_type VARCHAR(50) NOT NULL,
                listing_id VARCHAR(50) DEFAULT NULL,
                payload LONGTEXT NOT NULL,
                deliver_after DATETIME NOT NULL,
                status ENUM('pending', 'sent', 'failed', 'skipped') DEFAULT 'pending',
                error_message TEXT DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                processed_at DATETIME DEFAULT NULL,
                PRIMARY KEY (id),
                KEY idx_user_status (user_id, status),
                KEY idx_deliver_after (deliver_after, status),
                KEY idx_listing (listing_id)
            ) {$charset_collate}";
            dbDelta($sql);
        }

        // Create client notification preferences table (v6.48.2)
        $table_name = $wpdb->prefix . 'mld_client_notification_preferences';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") !== $table_name) {
            $sql = "CREATE TABLE {$table_name} (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT(20) UNSIGNED NOT NULL,
                new_listing_push TINYINT(1) DEFAULT 1,
                new_listing_email TINYINT(1) DEFAULT 1,
                price_change_push TINYINT(1) DEFAULT 1,
                price_change_email TINYINT(1) DEFAULT 1,
                status_change_push TINYINT(1) DEFAULT 1,
                status_change_email TINYINT(1) DEFAULT 1,
                open_house_push TINYINT(1) DEFAULT 1,
                open_house_email TINYINT(1) DEFAULT 1,
                saved_search_push TINYINT(1) DEFAULT 1,
                saved_search_email TINYINT(1) DEFAULT 1,
                quiet_hours_enabled TINYINT(1) DEFAULT 0,
                quiet_hours_start TIME DEFAULT '22:00:00',
                quiet_hours_end TIME DEFAULT '08:00:00',
                user_timezone VARCHAR(50) DEFAULT 'America/New_York',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uk_user (user_id)
            ) {$charset_collate}";
            dbDelta($sql);
        }

        // Create agent referral codes table (v6.52.0)
        $table_name = $wpdb->prefix . 'mld_agent_referral_codes';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") !== $table_name) {
            $sql = "CREATE TABLE {$table_name} (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                agent_user_id BIGINT(20) UNSIGNED NOT NULL,
                referral_code VARCHAR(50) NOT NULL,
                is_active TINYINT(1) DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY unique_code (referral_code),
                KEY idx_agent (agent_user_id),
                KEY idx_active (is_active)
            ) {$charset_collate}";
            dbDelta($sql);
        }

        // Create referral signups table (v6.52.0)
        $table_name = $wpdb->prefix . 'mld_referral_signups';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") !== $table_name) {
            $sql = "CREATE TABLE {$table_name} (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                client_user_id BIGINT(20) UNSIGNED NOT NULL,
                agent_user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
                referral_code VARCHAR(50) DEFAULT NULL,
                signup_source ENUM('organic', 'referral_link', 'agent_created') NOT NULL DEFAULT 'organic',
                platform ENUM('web', 'ios', 'admin') DEFAULT 'web',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY unique_client (client_user_id),
                KEY idx_agent (agent_user_id),
                KEY idx_code (referral_code),
                KEY idx_source (signup_source),
                KEY idx_created (created_at)
            ) {$charset_collate}";
            dbDelta($sql);
        }

        // Add new columns to push notification log table (v6.50.0)
        $log_table = $wpdb->prefix . 'mld_push_notification_log';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$log_table}'") === $log_table) {
            self::ensure_column_exists($log_table, 'is_read', "TINYINT(1) DEFAULT 0");
            self::ensure_column_exists($log_table, 'read_at', "DATETIME DEFAULT NULL");
            self::ensure_column_exists($log_table, 'is_dismissed', "TINYINT(1) DEFAULT 0");
            self::ensure_column_exists($log_table, 'dismissed_at', "DATETIME DEFAULT NULL");
        }

        // Run database verification to catch any other missing tables
        if (file_exists(plugin_dir_path(__FILE__) . 'class-mld-database-verify.php')) {
            require_once plugin_dir_path(__FILE__) . 'class-mld-database-verify.php';
            if (class_exists('MLD_Database_Verify')) {
                $verifier = MLD_Database_Verify::get_instance();
                $verification = $verifier->verify_tables();

                $missing_tables = array();
                foreach ($verification as $table_suffix => $info) {
                    if (!$info['exists']) {
                        $missing_tables[] = $table_suffix;
                    }
                }

                if (!empty($missing_tables)) {
                    $verifier->repair_tables($missing_tables);
                }
            }
        }

        // Clear all caches
        self::clear_all_caches();

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Database Upgrader: Version 6.54.1 upgrade completed');
        }
    }

    /**
     * Upgrade to version 6.54.2
     * Clean up duplicate notifications from before v6.53.0 deduplication fix
     *
     * Prior to v6.53.0, push notifications were logged per-device without deduplication.
     * Users with multiple devices could accumulate many duplicate notifications.
     * The history API now deduplicates on query, but old duplicates waste DB space.
     *
     * This cleanup keeps the oldest entry in each duplicate group and deletes the rest.
     * Duplicates are identified by: user_id, notification_type, listing_id (from payload), hour
     */
    private static function upgrade_to_6_54_2() {
        global $wpdb;

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Database Upgrader: Running duplicate notification cleanup for v6.54.2');
        }

        $log_table = $wpdb->prefix . 'mld_push_notification_log';

        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '{$log_table}'") !== $log_table) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD Database Upgrader: Notification log table does not exist, skipping cleanup');
            }
            return;
        }

        // Count duplicates before cleanup
        $before_count = $wpdb->get_var("SELECT COUNT(*) FROM {$log_table}");

        // Find all IDs to keep (oldest in each duplicate group)
        // Group by: user_id, notification_type, listing_id (from payload), hour
        $ids_to_keep_sql = "
            SELECT MIN(id) as keep_id
            FROM {$log_table}
            GROUP BY
                user_id,
                notification_type,
                COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.listing_id')), title),
                DATE_FORMAT(created_at, '%Y-%m-%d %H')
        ";

        // Delete duplicates (all IDs not in the keep list)
        $delete_sql = "
            DELETE FROM {$log_table}
            WHERE id NOT IN (
                SELECT keep_id FROM ({$ids_to_keep_sql}) AS keepers
            )
        ";

        $deleted = $wpdb->query($delete_sql);

        // Count after cleanup
        $after_count = $wpdb->get_var("SELECT COUNT(*) FROM {$log_table}");

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("MLD Database Upgrader: Notification cleanup complete - deleted {$deleted} duplicates ({$before_count} -> {$after_count} rows)");
        }

        // Clear caches
        self::clear_all_caches();

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Database Upgrader: Version 6.54.2 upgrade completed');
        }
    }

    /**
     * Clear all plugin caches
     */
    private static function clear_all_caches() {
        global $wpdb;

        // Clear search cache
        $search_cache_table = $wpdb->prefix . 'mld_search_cache';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$search_cache_table}'") === $search_cache_table) {
            $wpdb->query("TRUNCATE TABLE {$search_cache_table}");
        }

        // Clear response cache
        $response_cache_table = $wpdb->prefix . 'mld_chat_response_cache';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$response_cache_table}'") === $response_cache_table) {
            $wpdb->query("TRUNCATE TABLE {$response_cache_table}");
        }

        // Clear WordPress transients related to MLD
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_mld_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_mld_%'");

        // Clear object cache if available
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }
    }

    /**
     * Run on plugin deactivation
     */
    public static function deactivate() {
        // Clear scheduled cron jobs
        wp_clear_scheduled_hook('mld_process_notifications');
    }
}