<?php
/**
 * Bridge MLS Extractor Pro Plugin Upgrader
 *
 * Handles all plugin upgrades including database migrations, cache clearing,
 * and version tracking. Ensures all changes are applied when the plugin
 * is updated on a live server through WordPress's plugin update mechanism.
 *
 * @package Bridge_MLS_Extractor_Pro
 * @since 4.0.11
 */

if (!defined('ABSPATH')) {
    exit;
}

class BME_Upgrader {

    /**
     * Current plugin version - MUST match BME_PRO_VERSION in main plugin file
     */
    const CURRENT_VERSION = '4.0.32';

    /**
     * Option key for storing plugin version
     */
    const VERSION_OPTION = 'bme_pro_plugin_version';

    /**
     * Option key for storing upgrade status
     */
    const UPGRADE_STATUS_OPTION = 'bme_pro_upgrade_status';

    /**
     * Option key for storing migration history
     */
    const MIGRATION_HISTORY_OPTION = 'bme_pro_migration_history';

    /**
     * Database migrator instance
     */
    private $database_migrator;

    /**
     * Upgrade results
     */
    private $results = array();

    /**
     * Constructor
     */
    public function __construct() {
        if (class_exists('BME_Database_Migrator')) {
            $this->database_migrator = new BME_Database_Migrator();
        }
    }

    /**
     * Check if plugin needs upgrade
     *
     * @return bool True if upgrade needed
     */
    public function needs_upgrade() {
        $current_version = get_option(self::VERSION_OPTION, '0.0.0');
        return version_compare($current_version, self::CURRENT_VERSION, '<');
    }

    /**
     * Get stored plugin version
     *
     * @return string Current stored version
     */
    public function get_stored_version() {
        return get_option(self::VERSION_OPTION, '0.0.0');
    }

    /**
     * Check and run necessary upgrades (main entry point)
     */
    public static function check_upgrades() {
        $instance = new self();

        // Check both legacy version and new plugin version
        $current_version = get_option('bme_pro_version', '0.0.0');
        $current_plugin_version = get_option(self::VERSION_OPTION, '0.0.0');

        // Run upgrade if either version is outdated
        if (version_compare($current_version, BME_PRO_VERSION, '<') || $instance->needs_upgrade()) {
            $instance->run_upgrade();
        }
    }

    /**
     * Run the complete upgrade process
     *
     * @return array Upgrade results
     */
    public function run_upgrade() {
        $start_time = microtime(true);
        $stored_version = $this->get_stored_version();

        error_log("BME Upgrader: Starting upgrade from version {$stored_version} to " . self::CURRENT_VERSION);

        // Set upgrade status
        update_option(self::UPGRADE_STATUS_OPTION, array(
            'status' => 'running',
            'started_at' => current_time('mysql'),
            'from_version' => $stored_version,
            'to_version' => self::CURRENT_VERSION
        ));

        try {
            // Phase 1: Pre-upgrade checks
            $this->results['pre_checks'] = $this->run_pre_upgrade_checks();

            // Phase 2: Database migrations
            $this->results['database'] = $this->run_database_migrations($stored_version);

            // Phase 3: Clear all caches
            $this->results['cache'] = $this->clear_all_caches();

            // Phase 4: Update options and settings
            $this->results['options'] = $this->update_plugin_options($stored_version);

            // Phase 5: Run compatibility fixes
            $this->results['compatibility'] = $this->run_compatibility_fixes($stored_version);

            // Phase 6: Update version numbers
            $this->results['version_update'] = $this->update_version_numbers();

            // Calculate upgrade duration
            $duration = round(microtime(true) - $start_time, 2);
            $this->results['duration'] = $duration;

            // Update upgrade status to completed
            update_option(self::UPGRADE_STATUS_OPTION, array(
                'status' => 'completed',
                'completed_at' => current_time('mysql'),
                'from_version' => $stored_version,
                'to_version' => self::CURRENT_VERSION,
                'duration' => $duration,
                'results' => $this->results
            ));

            error_log("BME Upgrader: Upgrade completed successfully in {$duration} seconds");

            // Store migration history
            $this->store_migration_history($stored_version, self::CURRENT_VERSION, $this->results);

            return $this->results;

        } catch (Exception $e) {
            $error_message = 'BME Upgrade failed: ' . $e->getMessage();
            error_log($error_message);

            // Update upgrade status to failed
            update_option(self::UPGRADE_STATUS_OPTION, array(
                'status' => 'failed',
                'failed_at' => current_time('mysql'),
                'from_version' => $stored_version,
                'to_version' => self::CURRENT_VERSION,
                'error' => $error_message,
                'results' => $this->results
            ));

            $this->results['error'] = $error_message;
            return $this->results;
        }
    }

    /**
     * Run pre-upgrade checks
     *
     * @return array Check results
     */
    private function run_pre_upgrade_checks() {
        $checks = array();

        // Check WordPress version
        $wp_version = get_bloginfo('version');
        $checks['wordpress_version'] = array(
            'required' => '5.0',
            'current' => $wp_version,
            'passed' => version_compare($wp_version, '5.0', '>=')
        );

        // Check PHP version
        $php_version = PHP_VERSION;
        $checks['php_version'] = array(
            'required' => '7.4',
            'current' => $php_version,
            'passed' => version_compare($php_version, '7.4', '>=')
        );

        // Check memory limit
        $memory_limit = ini_get('memory_limit');
        $memory_bytes = $this->convert_to_bytes($memory_limit);
        $required_bytes = 256 * 1024 * 1024; // 256MB for Bridge MLS
        $checks['memory_limit'] = array(
            'required' => '256M',
            'current' => $memory_limit,
            'passed' => $memory_bytes >= $required_bytes
        );

        // Check MySQL version
        global $wpdb;
        $mysql_version = $wpdb->get_var("SELECT VERSION()");
        $checks['mysql_version'] = array(
            'required' => '5.7',
            'current' => $mysql_version,
            'passed' => version_compare($mysql_version, '5.7', '>=')
        );

        // Check database permissions
        $checks['database_permissions'] = array(
            'required' => true,
            'current' => $this->check_database_permissions(),
            'passed' => $this->check_database_permissions()
        );

        // Check disk space
        $checks['disk_space'] = array(
            'required' => '100MB',
            'current' => $this->get_available_disk_space(),
            'passed' => $this->check_disk_space()
        );

        error_log('BME Upgrader: Pre-upgrade checks completed');
        return $checks;
    }

    /**
     * Run database migrations
     *
     * @param string $from_version Previous version
     * @return array Migration results
     */
    private function run_database_migrations($from_version) {
        error_log('BME Upgrader: Starting database migrations');

        $migration_results = array();

        try {
            // Run Bridge MLS performance indexes migration
            $migration_results['performance_indexes'] = $this->run_performance_indexes_migration();

            // Run all database migrations via migrator if available
            if ($this->database_migrator) {
                $migration_results['migrator'] = $this->database_migrator->run_all_migrations($from_version, self::CURRENT_VERSION);
            }

            // Run table structure updates
            $migration_results['table_updates'] = $this->run_table_structure_updates($from_version);

            // Run existing migration system
            $migration_results['existing_migrations'] = $this->run_existing_migrations($from_version);

            error_log('BME Upgrader: Database migrations completed successfully');
            return $migration_results;

        } catch (Exception $e) {
            error_log('BME Upgrader: Database migration failed - ' . $e->getMessage());
            throw new Exception('Database migration failed: ' . $e->getMessage());
        }
    }

    /**
     * Run performance indexes migration
     *
     * @return array Migration results
     */
    private function run_performance_indexes_migration() {
        $results = array();

        try {
            // Load and run Bridge MLS performance indexes
            if (file_exists(BME_PLUGIN_DIR . '../../../database/migrations/bridge_mls_performance_indexes.php')) {
                require_once BME_PLUGIN_DIR . '../../../database/migrations/bridge_mls_performance_indexes.php';
                if (class_exists('Bridge_MLS_Performance_Indexes')) {
                    Bridge_MLS_Performance_Indexes::run();
                    $results['bridge_mls_indexes'] = 'created';
                }
            }

            // Run existing performance indexes migration
            if (file_exists(BME_PLUGIN_DIR . 'includes/migrations/add-performance-indexes.php')) {
                require_once BME_PLUGIN_DIR . 'includes/migrations/add-performance-indexes.php';
                if (class_exists('BME_Add_Performance_Indexes')) {
                    BME_Add_Performance_Indexes::run();
                    $results['existing_indexes'] = 'created';
                }
            }

            error_log('BME Upgrader: Performance indexes migration completed');
            return $results;

        } catch (Exception $e) {
            error_log('BME Upgrader: Performance indexes migration failed - ' . $e->getMessage());
            return array('error' => $e->getMessage());
        }
    }

    /**
     * Run table structure updates
     *
     * @param string $from_version Previous version
     * @return array Update results
     */
    private function run_table_structure_updates($from_version) {
        global $wpdb;
        $results = array();

        try {
            // Ensure all 18 database tables are properly created
            if (!class_exists('BME_Database_Manager')) {
                require_once BME_PLUGIN_DIR . 'includes/class-bme-database-manager.php';
            }

            $db_manager = new BME_Database_Manager();
            $db_manager->create_tables();
            $db_manager->verify_installation();

            // Run virtual tours table migration
            $db_manager->migrate_virtual_tours_table();

            $results['table_verification'] = 'completed';

            // v4.0.22: Recreate stored procedures with fixed pet_friendly logic
            // This ensures stored procedures pull pet_friendly from features table
            // instead of hardcoded 0 value
            if (version_compare($from_version, '4.0.22', '<')) {
                error_log('BME Upgrader: Recreating stored procedures for v4.0.22 pet_friendly fix');

                // Drop and recreate stored procedures to apply new pet_friendly logic
                global $wpdb;
                $wpdb->query("DROP PROCEDURE IF EXISTS populate_listing_summary");
                $wpdb->query("DROP PROCEDURE IF EXISTS populate_listing_summary_archive");

                // Recreate with fixed pet_friendly logic
                if (method_exists($db_manager, 'create_summary_stored_procedure')) {
                    $db_manager->create_summary_stored_procedure();
                    $results['summary_proc_recreated'] = true;
                }
                if (method_exists($db_manager, 'create_archive_summary_stored_procedure')) {
                    $db_manager->create_archive_summary_stored_procedure();
                    $results['archive_summary_proc_recreated'] = true;
                }

                error_log('BME Upgrader: Stored procedures recreated with pet_friendly fix');
            }

            // Run update manager migrations
            if (file_exists(BME_PLUGIN_DIR . 'includes/class-bme-update-manager.php')) {
                require_once BME_PLUGIN_DIR . 'includes/class-bme-update-manager.php';
                $update_manager = new BME_Update_Manager($db_manager);
                $update_manager->run_update_process();
                $results['update_manager'] = 'completed';
            }

            return $results;

        } catch (Exception $e) {
            error_log('BME Upgrader: Table structure update failed - ' . $e->getMessage());
            return array('error' => $e->getMessage());
        }
    }

    /**
     * Run existing migrations from the current plugin
     *
     * @param string $from_version Previous version
     * @return array Migration results
     */
    private function run_existing_migrations($from_version) {
        $results = array();

        try {
            // Run open house sync columns migration
            if (file_exists(BME_PLUGIN_DIR . 'includes/migrations/add-open-house-sync-columns.php')) {
                require_once BME_PLUGIN_DIR . 'includes/migrations/add-open-house-sync-columns.php';
                // Assume it has a run method
                if (class_exists('BME_Add_Open_House_Sync_Columns')) {
                    BME_Add_Open_House_Sync_Columns::run();
                    $results['open_house_sync'] = 'completed';
                }
            }

            // Run live site columns migration
            if (file_exists(BME_PLUGIN_DIR . 'includes/migrations/fix-live-site-columns.php')) {
                require_once BME_PLUGIN_DIR . 'includes/migrations/fix-live-site-columns.php';
                // Assume it has a run method
                if (class_exists('BME_Fix_Live_Site_Columns')) {
                    BME_Fix_Live_Site_Columns::run();
                    $results['live_site_columns'] = 'completed';
                }
            }

            return $results;

        } catch (Exception $e) {
            error_log('BME Upgrader: Existing migrations failed - ' . $e->getMessage());
            return array('error' => $e->getMessage());
        }
    }

    /**
     * Clear all caches
     *
     * @return array Cache clearing results
     */
    private function clear_all_caches() {
        error_log('BME Upgrader: Clearing all caches');

        $cache_results = array();

        // Clear WordPress object cache
        if (function_exists('wp_cache_flush')) {
            $cache_results['object_cache'] = wp_cache_flush();
        }

        // Clear transients
        $this->clear_plugin_transients();
        $cache_results['transients'] = true;

        // Clear opcache if available
        if (function_exists('opcache_reset')) {
            opcache_reset();
            $cache_results['opcache'] = true;
        }

        // Clear plugin-specific caches
        $cache_results['plugin_cache'] = $this->clear_plugin_cache();

        // Clear BME cache system
        if (class_exists('BME_Cache_Manager')) {
            try {
                $cache_manager = new BME_Cache_Manager();
                // Check if flush_all method exists, otherwise use clear method
                if (method_exists($cache_manager, 'flush_all')) {
                    $cache_manager->flush_all();
                } elseif (method_exists($cache_manager, 'clear')) {
                    $cache_manager->clear();
                } elseif (method_exists($cache_manager, 'flush')) {
                    $cache_manager->flush();
                }
                $cache_results['bme_cache'] = true;
            } catch (Exception $e) {
                error_log('BME Upgrader: Cache manager error - ' . $e->getMessage());
                $cache_results['bme_cache'] = false;
            }
        }

        error_log('BME Upgrader: Cache clearing completed');
        return $cache_results;
    }

    /**
     * Update plugin options
     *
     * @param string $from_version Previous version
     * @return array Update results
     */
    private function update_plugin_options($from_version) {
        error_log('BME Upgrader: Updating plugin options');

        $option_results = array();

        // Update default settings for new features
        $option_results['settings_update'] = $this->update_default_settings($from_version);

        // Migrate old option names if needed
        $option_results['option_migration'] = $this->migrate_legacy_options($from_version);

        // Update API configurations
        $option_results['api_update'] = $this->update_api_configurations();

        // Update performance settings
        $option_results['performance_update'] = $this->update_performance_settings();

        error_log('BME Upgrader: Plugin options updated');
        return $option_results;
    }

    /**
     * Run compatibility fixes
     *
     * @param string $from_version Previous version
     * @return array Compatibility results
     */
    private function run_compatibility_fixes($from_version) {
        error_log('BME Upgrader: Running compatibility fixes');

        $compatibility_results = array();

        // Fix data structure changes
        $compatibility_results['data_fixes'] = $this->fix_data_structures($from_version);

        // Update cron jobs
        $compatibility_results['cron_fixes'] = $this->fix_cron_schedules($from_version);

        // Fix file permissions
        $compatibility_results['file_fixes'] = $this->fix_file_permissions($from_version);

        error_log('BME Upgrader: Compatibility fixes completed');
        return $compatibility_results;
    }

    /**
     * Update version numbers
     *
     * @return bool Success status
     */
    private function update_version_numbers() {
        error_log('BME Upgrader: Updating version numbers');

        $results = array();

        // Update new plugin version
        $results['plugin_version'] = update_option(self::VERSION_OPTION, self::CURRENT_VERSION);

        // Update legacy version for compatibility
        $results['legacy_version'] = update_option('bme_pro_version', BME_PRO_VERSION);

        // Update database version (used by health dashboard)
        $results['db_version'] = update_option('bme_db_version', self::CURRENT_VERSION);

        if ($results['plugin_version'] && $results['legacy_version'] && $results['db_version']) {
            error_log('BME Upgrader: Version numbers updated successfully');
        } else {
            error_log('BME Upgrader: Failed to update some version numbers');
        }

        return $results;
    }

    /**
     * Store migration history
     *
     * @param string $from_version Previous version
     * @param string $to_version New version
     * @param array $results Migration results
     */
    private function store_migration_history($from_version, $to_version, $results) {
        $history = get_option(self::MIGRATION_HISTORY_OPTION, array());

        $migration_record = array(
            'from_version' => $from_version,
            'to_version' => $to_version,
            'migrated_at' => current_time('mysql'),
            'duration' => $results['duration'] ?? 0,
            'success' => !isset($results['error']),
            'results' => $results
        );

        array_unshift($history, $migration_record);

        // Keep only last 10 migration records
        $history = array_slice($history, 0, 10);

        update_option(self::MIGRATION_HISTORY_OPTION, $history);
    }

    /**
     * Helper methods
     */

    /**
     * Check database permissions
     *
     * @return bool True if permissions are adequate
     */
    private function check_database_permissions() {
        global $wpdb;

        try {
            // Test if we can create/modify tables
            $test_table = $wpdb->prefix . 'bme_upgrade_test';

            $wpdb->query("CREATE TEMPORARY TABLE {$test_table} (id INT AUTO_INCREMENT PRIMARY KEY, test_col VARCHAR(50))");
            $wpdb->query("INSERT INTO {$test_table} (test_col) VALUES ('test')");
            $wpdb->query("ALTER TABLE {$test_table} ADD COLUMN test_col2 VARCHAR(50)");
            $wpdb->query("DROP TEMPORARY TABLE {$test_table}");

            return true;
        } catch (Exception $e) {
            error_log('BME Upgrader: Database permission check failed - ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Convert memory limit to bytes
     *
     * @param string $limit Memory limit string
     * @return int Bytes
     */
    private function convert_to_bytes($limit) {
        $limit = trim($limit);
        $last = strtolower($limit[strlen($limit) - 1]);
        $number = (int) $limit;

        switch ($last) {
            case 'g':
                $number *= 1024;
            case 'm':
                $number *= 1024;
            case 'k':
                $number *= 1024;
        }

        return $number;
    }

    /**
     * Get available disk space
     *
     * @return string Disk space
     */
    private function get_available_disk_space() {
        $bytes = disk_free_space(ABSPATH);
        if ($bytes === false) {
            return 'unknown';
        }

        $mb = round($bytes / 1024 / 1024, 2);
        return $mb . 'MB';
    }

    /**
     * Check disk space
     *
     * @return bool True if adequate space
     */
    private function check_disk_space() {
        $bytes = disk_free_space(ABSPATH);
        if ($bytes === false) {
            return true; // Assume OK if can't check
        }

        $required_bytes = 100 * 1024 * 1024; // 100MB
        return $bytes >= $required_bytes;
    }

    /**
     * Clear plugin transients
     */
    private function clear_plugin_transients() {
        global $wpdb;

        // Delete all BME transients
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_bme_%' OR option_name LIKE '_transient_timeout_bme_%'");
    }

    /**
     * Clear plugin-specific cache
     *
     * @return bool Success status
     */
    private function clear_plugin_cache() {
        // Clear any plugin-specific cache here
        delete_option('bme_performance_cache');
        delete_option('bme_query_cache');
        delete_option('bme_api_cache');

        return true;
    }

    /**
     * Update default settings
     *
     * @param string $from_version Previous version
     * @return bool Success status
     */
    private function update_default_settings($from_version) {
        // Add any new default settings based on version changes
        $current_settings = get_option('bme_pro_performance_settings', array());

        // Version-specific setting updates
        if (version_compare($from_version, '3.30.0', '<')) {
            // Add new settings for version 3.30.0
            $current_settings['upgrade_auto_apply'] = true;
            $current_settings['cache_clear_on_upgrade'] = true;
            $current_settings['performance_indexes_enabled'] = true;
        }

        // Phase 3: Analytics Consolidation (v4.0.3)
        if (version_compare($from_version, '4.0.3', '<')) {
            error_log('[BME] Running Phase 3 Analytics Consolidation update...');
            $update_file = BME_PLUGIN_DIR . 'updates/update-4.0.3.php';
            if (file_exists($update_file)) {
                require_once $update_file;
                if (function_exists('bme_update_to_4_0_3')) {
                    $result = bme_update_to_4_0_3();
                    if ($result) {
                        error_log('[BME] ✅ Phase 3 Analytics Consolidation completed successfully');
                    } else {
                        error_log('[BME] ⚠️  Phase 3 Analytics Consolidation completed with warnings');
                    }
                }
            } else {
                error_log('[BME] ⚠️  Phase 3 update file not found: ' . $update_file);
            }
        }

        return update_option('bme_pro_performance_settings', $current_settings);
    }

    /**
     * Migrate legacy options
     *
     * @param string $from_version Previous version
     * @return array Migration results
     */
    private function migrate_legacy_options($from_version) {
        $migration_results = array();

        // Add option migration logic here based on version changes
        if (version_compare($from_version, '3.0.0', '<')) {
            // Migrate old option names
            $old_value = get_option('bme_old_api_settings');
            if ($old_value !== false) {
                update_option('bme_pro_api_credentials', $old_value);
                delete_option('bme_old_api_settings');
                $migration_results['api_settings'] = 'migrated';
            }
        }

        return $migration_results;
    }

    /**
     * Update API configurations
     *
     * @return bool Success status
     */
    private function update_api_configurations() {
        // Ensure proper API configurations are set
        $api_settings = get_option('bme_pro_api_credentials', array());

        // Set defaults if not already configured
        if (!isset($api_settings['timeout'])) {
            $api_settings['timeout'] = BME_API_TIMEOUT;
        }

        if (!isset($api_settings['batch_size'])) {
            $api_settings['batch_size'] = BME_BATCH_SIZE;
        }

        return update_option('bme_pro_api_credentials', $api_settings);
    }

    /**
     * Update performance settings
     *
     * @return bool Success status
     */
    private function update_performance_settings() {
        // Ensure performance settings are properly configured
        $performance_settings = get_option('bme_pro_performance_settings', array());

        // Set defaults for new performance features
        if (!isset($performance_settings['indexes_enabled'])) {
            $performance_settings['indexes_enabled'] = true;
        }

        if (!isset($performance_settings['cache_enabled'])) {
            $performance_settings['cache_enabled'] = true;
        }

        return update_option('bme_pro_performance_settings', $performance_settings);
    }

    /**
     * Fix data structures
     *
     * @param string $from_version Previous version
     * @return array Fix results
     */
    private function fix_data_structures($from_version) {
        $fix_results = array();

        // Add data structure fixes based on version changes
        if (version_compare($from_version, '3.25.0', '<')) {
            // Fix location table data structure
            $fix_results['location_data'] = $this->fix_location_data_structure();
        }

        return $fix_results;
    }

    /**
     * Fix cron schedules
     *
     * @param string $from_version Previous version
     * @return array Fix results
     */
    private function fix_cron_schedules($from_version) {
        $fix_results = array();

        // Reschedule all cron jobs
        $cron_hooks = array(
            'bme_pro_cron_hook',
            'bme_pro_cleanup_hook',
            'bme_pro_cache_cleanup',
            'bme_pro_import_virtual_tours_hook'
        );

        foreach ($cron_hooks as $hook) {
            wp_clear_scheduled_hook($hook);
            $fix_results[$hook] = 'cleared';
        }

        // Reschedule main cron
        if (!wp_next_scheduled('bme_pro_cron_hook')) {
            wp_schedule_event(time(), 'every_15_minutes', 'bme_pro_cron_hook');
            $fix_results['main_cron'] = 'rescheduled';
        }

        return $fix_results;
    }

    /**
     * Fix file permissions
     *
     * @param string $from_version Previous version
     * @return array Fix results
     */
    private function fix_file_permissions($from_version) {
        $fix_results = array();

        // Check and fix critical file permissions
        $critical_files = array(
            BME_PLUGIN_DIR . 'includes/',
            BME_PLUGIN_DIR . 'assets/',
        );

        foreach ($critical_files as $path) {
            if (is_dir($path) && is_writable($path)) {
                $fix_results[basename($path)] = 'writable';
            } else {
                $fix_results[basename($path)] = 'check_permissions';
            }
        }

        return $fix_results;
    }

    /**
     * Fix location data structure
     *
     * @return bool Success status
     */
    private function fix_location_data_structure() {
        // Add location data structure fixes here
        return true;
    }

    /**
     * Get upgrade status
     *
     * @return array Upgrade status
     */
    public function get_upgrade_status() {
        return get_option(self::UPGRADE_STATUS_OPTION, array('status' => 'none'));
    }

    /**
     * Get migration history
     *
     * @return array Migration history
     */
    public function get_migration_history() {
        return get_option(self::MIGRATION_HISTORY_OPTION, array());
    }

    /**
     * Force upgrade (for manual execution)
     *
     * @return array Upgrade results
     */
    public function force_upgrade() {
        // Remove version check for force upgrade
        return $this->run_upgrade();
    }

    /**
     * Rollback to previous version (emergency use)
     *
     * @param string $target_version Version to rollback to
     * @return array Rollback results
     */
    public function rollback($target_version) {
        error_log("BME Upgrader: Starting rollback to version {$target_version}");

        try {
            // Update version numbers
            update_option(self::VERSION_OPTION, $target_version);
            update_option('bme_pro_version', $target_version);

            // Clear caches
            $this->clear_all_caches();

            // Log rollback
            $history = get_option(self::MIGRATION_HISTORY_OPTION, array());
            array_unshift($history, array(
                'from_version' => self::CURRENT_VERSION,
                'to_version' => $target_version,
                'migrated_at' => current_time('mysql'),
                'type' => 'rollback',
                'success' => true
            ));
            update_option(self::MIGRATION_HISTORY_OPTION, array_slice($history, 0, 10));

            error_log("BME Upgrader: Rollback to {$target_version} completed");

            return array(
                'success' => true,
                'version' => $target_version,
                'message' => "Rolled back to version {$target_version}"
            );

        } catch (Exception $e) {
            error_log('BME Upgrader: Rollback failed - ' . $e->getMessage());
            return array(
                'success' => false,
                'error' => $e->getMessage()
            );
        }
    }
}