<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * BME Update Manager - Handles plugin updates and database migrations
 *
 * Automatically runs when plugin is updated to ensure database integrity
 * and apply any necessary corrections or enhancements.
 *
 * @package Bridge_MLS_Extractor_Pro
 * @since 3.30.2
 * @version 1.0.0
 */
class BME_Update_Manager {

    /**
     * @var BME_Database_Manager Database manager instance
     */
    private $db_manager;

    /**
     * @var string Current plugin version
     */
    private $current_version;

    /**
     * @var string Stored version in database
     */
    private $stored_version;

    /**
     * Constructor
     */
    public function __construct($db_manager) {
        $this->db_manager = $db_manager;
        $this->current_version = BME_PRO_VERSION;
        $this->stored_version = get_option('bme_plugin_version', '0');

        // Hook into plugin activation and admin_init
        add_action('admin_init', array($this, 'check_for_updates'));
        register_activation_hook(BME_PLUGIN_DIR . 'bridge-mls-extractor-pro.php', array($this, 'on_plugin_activation'));
    }

    /**
     * Check if plugin needs updates
     */
    public function check_for_updates() {
        if (version_compare($this->stored_version, $this->current_version, '<')) {
            $this->run_update_process();
        }
    }

    /**
     * Run on plugin activation
     */
    public function on_plugin_activation() {
        $this->run_update_process();
    }

    /**
     * Main update process
     */
    public function run_update_process() {
        error_log("BME Update Manager: Starting update process from {$this->stored_version} to {$this->current_version}");

        try {
            // Step 1: Run database migrations
            $this->run_database_migrations();

            // Step 2: Clean and correct existing data
            $this->clean_and_correct_data();

            // Step 3: Clear any stuck extraction locks
            $this->clear_stuck_extraction_locks();

            // Step 4: Verify database integrity
            $this->verify_database_integrity();

            // Step 4: Update stored version
            update_option('bme_plugin_version', $this->current_version);

            error_log("BME Update Manager: Update process completed successfully");

        } catch (Exception $e) {
            error_log("BME Update Manager: Update process failed: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Run necessary database migrations
     */
    private function run_database_migrations() {
        error_log("BME Update Manager: Running database migrations");

        // Ensure all tables exist with correct structure
        $this->db_manager->create_tables();

        // Run open house sync columns migration if needed
        if (!get_option('bme_open_house_sync_columns_added', false)) {
            $migration_file = BME_PLUGIN_DIR . 'includes/migrations/add-open-house-sync-columns.php';
            if (file_exists($migration_file)) {
                require_once $migration_file;
                BME_Add_Open_House_Sync_Columns::run();
            }
        }

        // Emergency fix for live site missing columns (v3.30.8)
        if (!get_option('bme_live_site_columns_fixed', false)) {
            $migration_file = BME_PLUGIN_DIR . 'includes/migrations/fix-live-site-columns.php';
            if (file_exists($migration_file)) {
                require_once $migration_file;
                BME_Fix_Live_Site_Columns::run();
                update_option('bme_live_site_columns_fixed', true);
            }
        }

        // Add any future migrations here
        $this->migrate_to_330();
    }

    /**
     * Migration specific to version 3.30+
     */
    private function migrate_to_330() {
        global $wpdb;

        // Ensure open houses table has sync columns
        $table = $wpdb->prefix . 'bme_open_houses';
        $columns = $wpdb->get_results("SHOW COLUMNS FROM {$table}");
        $column_names = array_column($columns, 'Field');

        if (!in_array('sync_status', $column_names)) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN sync_status VARCHAR(20) DEFAULT 'current'");
            error_log("BME Update Manager: Added sync_status column to open houses table");
        }

        if (!in_array('sync_timestamp', $column_names)) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN sync_timestamp DATETIME");
            error_log("BME Update Manager: Added sync_timestamp column to open houses table");
        }

        if (!in_array('open_house_key', $column_names)) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN open_house_key VARCHAR(128)");
            error_log("BME Update Manager: Added open_house_key column to open houses table");
        }
    }

    /**
     * Clean and correct existing data
     */
    private function clean_and_correct_data() {
        error_log("BME Update Manager: Cleaning and correcting existing data");

        global $wpdb;

        // Clean up orphaned open houses
        $this->cleanup_orphaned_open_houses();

        // Fix any pending deletion records
        $this->fix_pending_deletion_records();

        // Standardize sync status for existing records
        $this->standardize_sync_status();

        // Clean up old activity logs (keep last 30 days)
        $this->cleanup_old_activity_logs();
    }

    /**
     * Clean up orphaned open houses
     */
    private function cleanup_orphaned_open_houses() {
        global $wpdb;

        $open_houses_table = $this->db_manager->get_table('open_houses');
        $listings_table = $this->db_manager->get_table('listings');

        $deleted = $wpdb->query("
            DELETE oh FROM {$open_houses_table} oh
            LEFT JOIN {$listings_table} l ON oh.listing_id = l.listing_id
            WHERE l.listing_id IS NULL
        ");

        if ($deleted > 0) {
            error_log("BME Update Manager: Cleaned up {$deleted} orphaned open house records");
        }
    }

    /**
     * Fix pending deletion records
     */
    private function fix_pending_deletion_records() {
        global $wpdb;

        $table = $this->db_manager->get_table('open_houses');

        $deleted = $wpdb->delete($table, ['sync_status' => 'pending_deletion']);

        if ($deleted > 0) {
            error_log("BME Update Manager: Removed {$deleted} pending deletion records");
        }
    }

    /**
     * Standardize sync status for existing records
     */
    private function standardize_sync_status() {
        global $wpdb;

        $table = $this->db_manager->get_table('open_houses');

        // Set all existing records without sync_status to 'current'
        $updated = $wpdb->query($wpdb->prepare("
            UPDATE {$table}
            SET sync_status = %s
            WHERE sync_status IS NULL OR sync_status = ''
        ", 'current'));

        if ($updated > 0) {
            error_log("BME Update Manager: Standardized sync_status for {$updated} open house records");
        }
    }

    /**
     * Clean up old activity logs
     */
    private function cleanup_old_activity_logs() {
        global $wpdb;

        $table = $this->db_manager->get_table('activity_logs');
        $threshold = date('Y-m-d H:i:s', strtotime('-30 days'));

        $deleted = $wpdb->query($wpdb->prepare("
            DELETE FROM {$table}
            WHERE created_at < %s
        ", $threshold));

        if ($deleted > 0) {
            error_log("BME Update Manager: Cleaned up {$deleted} old activity log records");
        }
    }

    /**
     * Clear any stuck extraction locks
     */
    private function clear_stuck_extraction_locks() {
        error_log("BME Update Manager: Clearing stuck extraction locks");

        // Get all extraction posts
        $extractions = get_posts([
            'post_type' => 'bme_extraction',
            'post_status' => 'publish',
            'numberposts' => -1,
            'fields' => 'ids'
        ]);

        $cleared_locks = 0;

        foreach ($extractions as $extraction_id) {
            $lock_key = "bme_extraction_running_{$extraction_id}";
            $lock_value = get_transient($lock_key);

            if ($lock_value) {
                $lock_age_hours = (time() - $lock_value) / 3600;

                // Clear locks older than 30 minutes (normal extractions should complete faster)
                if ($lock_age_hours > 0.5) {
                    delete_transient($lock_key);
                    $cleared_locks++;
                    error_log("BME Update Manager: Cleared stuck lock for extraction {$extraction_id} (age: {$lock_age_hours} hours)");
                }
            }
        }

        if ($cleared_locks > 0) {
            error_log("BME Update Manager: Cleared {$cleared_locks} stuck extraction locks");
        } else {
            error_log("BME Update Manager: No stuck extraction locks found");
        }
    }

    /**
     * Verify database integrity
     */
    private function verify_database_integrity() {
        error_log("BME Update Manager: Verifying database integrity");

        $issues = [];

        // Check table existence
        $issues = array_merge($issues, $this->verify_table_existence());

        // Check column integrity
        $issues = array_merge($issues, $this->verify_column_integrity());

        // Check data consistency
        $issues = array_merge($issues, $this->verify_data_consistency());

        // Check index integrity
        $issues = array_merge($issues, $this->verify_index_integrity());

        if (empty($issues)) {
            error_log("BME Update Manager: Database integrity verification passed");
            update_option('bme_database_integrity_verified', current_time('mysql'));
        } else {
            error_log("BME Update Manager: Database integrity issues found: " . implode(', ', $issues));
            update_option('bme_database_integrity_issues', $issues);
        }

        return empty($issues);
    }

    /**
     * Verify all required tables exist
     */
    private function verify_table_existence() {
        global $wpdb;
        $issues = [];

        $required_tables = [
            'listings', 'listings_archive',
            'listing_details', 'listing_details_archive',
            'listing_location', 'listing_location_archive',
            'listing_financial', 'listing_financial_archive',
            'listing_features', 'listing_features_archive',
            'agents', 'offices', 'open_houses', 'media', 'rooms',
            'property_history', 'activity_logs', 'api_requests'
        ];

        foreach ($required_tables as $table) {
            $full_table_name = $wpdb->prefix . 'bme_' . $table;
            $exists = $wpdb->get_var("SHOW TABLES LIKE '{$full_table_name}'");

            if (!$exists) {
                $issues[] = "Missing table: {$full_table_name}";
            }
        }

        return $issues;
    }

    /**
     * Verify column integrity for critical tables
     */
    private function verify_column_integrity() {
        global $wpdb;
        $issues = [];

        // Check open houses table columns
        $open_houses_table = $wpdb->prefix . 'bme_open_houses';
        $columns = $wpdb->get_results("SHOW COLUMNS FROM {$open_houses_table}");
        $column_names = array_column($columns, 'Field');

        $required_columns = ['sync_status', 'sync_timestamp', 'open_house_key'];
        foreach ($required_columns as $column) {
            if (!in_array($column, $column_names)) {
                $issues[] = "Missing column {$column} in {$open_houses_table}";
            }
        }

        return $issues;
    }

    /**
     * Verify data consistency
     */
    private function verify_data_consistency() {
        global $wpdb;
        $issues = [];

        // Check for listings without details
        $listings_table = $this->db_manager->get_table('listings');
        $details_table = $this->db_manager->get_table('listing_details');

        $orphaned_listings = $wpdb->get_var("
            SELECT COUNT(*) FROM {$listings_table} l
            LEFT JOIN {$details_table} ld ON l.listing_id = ld.listing_id
            WHERE ld.listing_id IS NULL
        ");

        if ($orphaned_listings > 0) {
            $issues[] = "{$orphaned_listings} listings without details records";
        }

        return $issues;
    }

    /**
     * Verify index integrity
     */
    private function verify_index_integrity() {
        global $wpdb;
        $issues = [];

        // Check important indexes exist
        $open_houses_table = $wpdb->prefix . 'bme_open_houses';
        $indexes = $wpdb->get_results("SHOW INDEX FROM {$open_houses_table}");
        $index_names = array_column($indexes, 'Key_name');

        $required_indexes = ['idx_sync_status', 'idx_sync_timestamp'];
        foreach ($required_indexes as $index) {
            if (!in_array($index, $index_names)) {
                // Try to create missing index
                try {
                    if ($index === 'idx_sync_status') {
                        $wpdb->query("ALTER TABLE {$open_houses_table} ADD INDEX idx_sync_status (sync_status)");
                    } elseif ($index === 'idx_sync_timestamp') {
                        $wpdb->query("ALTER TABLE {$open_houses_table} ADD INDEX idx_sync_timestamp (sync_timestamp)");
                    }
                    error_log("BME Update Manager: Created missing index {$index}");
                } catch (Exception $e) {
                    $issues[] = "Missing index {$index} in {$open_houses_table}";
                }
            }
        }

        return $issues;
    }

    /**
     * Get update status for admin display
     */
    public function get_update_status() {
        $last_update = get_option('bme_plugin_version', 'never');
        $last_verification = get_option('bme_database_integrity_verified', 'never');
        $issues = get_option('bme_database_integrity_issues', []);

        return [
            'current_version' => $this->current_version,
            'last_update' => $last_update,
            'last_verification' => $last_verification,
            'has_issues' => !empty($issues),
            'issues' => $issues,
            'needs_update' => version_compare($last_update, $this->current_version, '<')
        ];
    }

    /**
     * Manual update trigger for admin
     */
    public function manual_update() {
        $this->run_update_process();
        return $this->get_update_status();
    }
}