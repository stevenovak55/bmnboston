<?php
/**
 * Database management class
 *
 * @package Exclusive_Listings
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class EL_Database
 *
 * Handles database operations, schema management, and upgrades.
 */
class EL_Database {

    /**
     * WordPress database object
     * @var wpdb
     */
    private $wpdb;

    /**
     * Plugin table names
     * @var array
     */
    private $tables;

    /**
     * BME table names (external, read/write)
     * @var array
     */
    private $bme_tables;

    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;

        // Plugin-owned tables
        $this->tables = array(
            'sequence' => $wpdb->prefix . 'exclusive_listing_sequence',
        );

        // BME tables we populate (owned by Bridge MLS Extractor)
        $this->bme_tables = array(
            'listings' => $wpdb->prefix . 'bme_listings',
            'listings_archive' => $wpdb->prefix . 'bme_listings_archive',
            'summary' => $wpdb->prefix . 'bme_listing_summary',
            'summary_archive' => $wpdb->prefix . 'bme_listing_summary_archive',
            'details' => $wpdb->prefix . 'bme_listing_details',
            'details_archive' => $wpdb->prefix . 'bme_listing_details_archive',
            'location' => $wpdb->prefix . 'bme_listing_location',
            'location_archive' => $wpdb->prefix . 'bme_listing_location_archive',
            'features' => $wpdb->prefix . 'bme_listing_features',
            'features_archive' => $wpdb->prefix . 'bme_listing_features_archive',
            'financial' => $wpdb->prefix . 'bme_listing_financial',
            'financial_archive' => $wpdb->prefix . 'bme_listing_financial_archive',
            'media' => $wpdb->prefix . 'bme_media',
        );
    }

    /**
     * Get plugin table name
     *
     * @param string $table Table key
     * @return string|null Full table name or null if not found
     */
    public function get_table($table) {
        return $this->tables[$table] ?? null;
    }

    /**
     * Get BME table name
     *
     * @param string $table Table key
     * @return string|null Full table name or null if not found
     */
    public function get_bme_table($table) {
        return $this->bme_tables[$table] ?? null;
    }

    /**
     * Get all BME table names
     *
     * @return array
     */
    public function get_bme_tables() {
        return $this->bme_tables;
    }

    /**
     * Run database upgrades
     *
     * @since 1.0.0
     */
    public function upgrade() {
        $installed_version = get_option('el_db_version', '0.0.0');

        // Version-specific upgrades
        if (version_compare($installed_version, '1.0.0', '<')) {
            $this->upgrade_to_1_0_0();
        }

        // v1.5.0: Add exclusive_tag column
        if (version_compare($installed_version, '1.5.0', '<')) {
            $this->upgrade_to_1_5_0();
        }
    }

    /**
     * Upgrade to version 1.5.0
     *
     * Adds exclusive_tag column to bme_listing_summary tables
     * for custom badge text (Coming Soon, Off-Market, etc.)
     *
     * @since 1.5.0
     */
    private function upgrade_to_1_5_0() {
        $summary_table = $this->bme_tables['summary'];
        $summary_archive_table = $this->bme_tables['summary_archive'];

        // Check if column already exists before adding
        $column_exists = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = %s
                 AND TABLE_NAME = %s
                 AND COLUMN_NAME = 'exclusive_tag'",
                DB_NAME,
                $summary_table
            )
        );

        if (!$column_exists) {
            // Add to main summary table
            $this->wpdb->query(
                "ALTER TABLE {$summary_table}
                 ADD COLUMN exclusive_tag VARCHAR(50) DEFAULT NULL
                 AFTER standard_status"
            );

            // Add to archive table
            $this->wpdb->query(
                "ALTER TABLE {$summary_archive_table}
                 ADD COLUMN exclusive_tag VARCHAR(50) DEFAULT NULL
                 AFTER standard_status"
            );

            error_log('Exclusive Listings: Added exclusive_tag column to summary tables');
        }

        update_option('el_db_version', '1.5.0');
        error_log('Exclusive Listings: Database upgraded to 1.5.0');
    }

    /**
     * Upgrade to version 1.0.0
     *
     * @since 1.0.0
     */
    private function upgrade_to_1_0_0() {
        // Ensure sequence table exists
        $this->ensure_sequence_table();

        error_log('Exclusive Listings: Database upgraded to 1.0.0');
    }

    /**
     * Ensure the sequence table exists and is properly configured
     *
     * @since 1.0.0
     */
    private function ensure_sequence_table() {
        $charset_collate = $this->wpdb->get_charset_collate();
        $table = $this->tables['sequence'];

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
        ) {$charset_collate}";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * Check if all required BME tables exist
     *
     * @since 1.0.0
     * @return array Array of missing tables (empty if all exist)
     */
    public function check_bme_tables() {
        $missing = array();

        // Check only the primary active tables (not archive)
        $required_tables = array(
            'listings',
            'summary',
            'details',
            'location',
            'features',
            'media',
        );

        foreach ($required_tables as $key) {
            $table = $this->bme_tables[$key];
            $exists = $this->wpdb->get_var(
                $this->wpdb->prepare(
                    "SHOW TABLES LIKE %s",
                    $table
                )
            );

            if (!$exists) {
                $missing[] = $table;
            }
        }

        return $missing;
    }

    /**
     * Get diagnostic information about the database
     *
     * @since 1.0.0
     * @return array Diagnostic data
     */
    public function get_diagnostics() {
        $diagnostics = array(
            'plugin_tables' => array(),
            'bme_tables' => array(),
            'sequence_status' => null,
            'exclusive_listing_count' => 0,
            'mls_listing_count' => 0,
        );

        // Check plugin tables
        foreach ($this->tables as $key => $table) {
            $exists = $this->wpdb->get_var(
                $this->wpdb->prepare(
                    "SHOW TABLES LIKE %s",
                    $table
                )
            );
            $diagnostics['plugin_tables'][$key] = array(
                'name' => $table,
                'exists' => (bool) $exists,
            );
        }

        // Check BME tables (just active, not archive for brevity)
        $check_tables = array('listings', 'summary', 'details', 'location', 'features', 'media');
        foreach ($check_tables as $key) {
            $table = $this->bme_tables[$key];
            $exists = $this->wpdb->get_var(
                $this->wpdb->prepare(
                    "SHOW TABLES LIKE %s",
                    $table
                )
            );
            $diagnostics['bme_tables'][$key] = array(
                'name' => $table,
                'exists' => (bool) $exists,
            );
        }

        // Get sequence status
        $last_id = $this->wpdb->get_var("SELECT MAX(id) FROM {$this->tables['sequence']}");
        $last_id_int = $last_id ? (int) $last_id : 0;
        $diagnostics['sequence_status'] = array(
            'last_id' => $last_id_int,
            'next_id' => $last_id_int + 1,
        );

        // Count exclusive listings (ID < 1,000,000)
        $summary_table = $this->bme_tables['summary'];
        if ($diagnostics['bme_tables']['summary']['exists']) {
            $diagnostics['exclusive_listing_count'] = (int) $this->wpdb->get_var(
                $this->wpdb->prepare(
                    "SELECT COUNT(*) FROM {$summary_table} WHERE listing_id < %d",
                    EL_EXCLUSIVE_ID_THRESHOLD
                )
            );

            // Count MLS listings (ID >= 1,000,000)
            $diagnostics['mls_listing_count'] = (int) $this->wpdb->get_var(
                $this->wpdb->prepare(
                    "SELECT COUNT(*) FROM {$summary_table} WHERE listing_id >= %d",
                    EL_EXCLUSIVE_ID_THRESHOLD
                )
            );
        }

        return $diagnostics;
    }

    /**
     * Get the current auto-increment value from the sequence table
     *
     * @since 1.0.0
     * @return int Current auto-increment value (next ID to be assigned)
     */
    public function get_next_sequence_id() {
        $table = $this->tables['sequence'];

        // Get the current auto_increment value
        $result = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SHOW TABLE STATUS LIKE %s",
                $table
            )
        );

        if ($result && isset($result->Auto_increment)) {
            return (int) $result->Auto_increment;
        }

        // Fallback: get max ID + 1
        $max_id = $this->wpdb->get_var("SELECT MAX(id) FROM {$table}");
        return $max_id ? (int) $max_id + 1 : 1;
    }
}
