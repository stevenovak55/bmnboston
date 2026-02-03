<?php
/**
 * MLS Listings Display - CMA Session Database Handler
 *
 * Handles database table creation and updates for the CMA saved sessions feature
 *
 * @package MLS_Listings_Display
 * @subpackage CMA
 * @since 6.16.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_CMA_Session_Database {

    /**
     * Database version for schema updates
     * 1.0.0 - Initial release (6.16.0)
     * 1.1.0 - Added standalone CMA support (6.17.0)
     */
    const DB_VERSION = '1.1.0';

    /**
     * Option name for storing database version
     */
    const VERSION_OPTION = 'mld_cma_session_db_version';

    /**
     * Check if database upgrade is needed and run it
     */
    public static function check_and_upgrade() {
        $current_version = get_option(self::VERSION_OPTION, '0.0.0');

        if (version_compare($current_version, self::DB_VERSION, '<')) {
            self::create_tables();

            // Run schema upgrades for existing tables
            if (version_compare($current_version, '1.1.0', '<')) {
                self::upgrade_to_1_1_0();
            }
        }
    }

    /**
     * Upgrade schema to version 1.1.0 (Standalone CMA support)
     */
    private static function upgrade_to_1_1_0() {
        global $wpdb;

        $table_name = self::get_table_name();

        // Add is_standalone column if not exists
        $is_standalone_exists = $wpdb->get_var(
            "SHOW COLUMNS FROM $table_name LIKE 'is_standalone'"
        );

        if (!$is_standalone_exists) {
            $wpdb->query(
                "ALTER TABLE $table_name
                 ADD COLUMN is_standalone TINYINT(1) DEFAULT 0
                 AFTER is_favorite"
            );
        }

        // Add standalone_slug column if not exists
        $standalone_slug_exists = $wpdb->get_var(
            "SHOW COLUMNS FROM $table_name LIKE 'standalone_slug'"
        );

        if (!$standalone_slug_exists) {
            $wpdb->query(
                "ALTER TABLE $table_name
                 ADD COLUMN standalone_slug VARCHAR(255) DEFAULT NULL
                 AFTER is_standalone"
            );

            // Add index for standalone_slug
            $wpdb->query(
                "ALTER TABLE $table_name
                 ADD INDEX idx_standalone_slug (standalone_slug)"
            );
        }

        // Add index for is_standalone if not exists
        $is_standalone_index = $wpdb->get_row(
            "SHOW INDEX FROM $table_name WHERE Key_name = 'idx_is_standalone'"
        );

        if (!$is_standalone_index) {
            $wpdb->query(
                "ALTER TABLE $table_name
                 ADD INDEX idx_is_standalone (is_standalone)"
            );
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[MLD CMA Session Database] Upgraded to schema version 1.1.0');
        }
    }

    /**
     * Create or update all CMA session related tables
     */
    public static function create_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        // Create CMA saved sessions table
        self::create_saved_sessions_table($charset_collate);

        // Update database version
        update_option(self::VERSION_OPTION, self::DB_VERSION);
    }

    /**
     * Create CMA saved sessions table
     *
     * @param string $charset_collate Database charset collation
     */
    private static function create_saved_sessions_table($charset_collate) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'mld_cma_saved_sessions';

        $sql = "CREATE TABLE $table_name (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            session_name VARCHAR(255) NOT NULL,
            description TEXT,
            is_favorite TINYINT(1) DEFAULT 0,
            is_standalone TINYINT(1) DEFAULT 0,
            standalone_slug VARCHAR(255) DEFAULT NULL,

            -- Subject property snapshot
            subject_listing_id VARCHAR(50) NOT NULL,
            subject_property_data JSON NOT NULL,
            subject_overrides JSON,

            -- CMA configuration
            cma_filters JSON NOT NULL,

            -- Results snapshot
            comparables_data JSON,
            summary_statistics JSON,

            -- Quick-access metrics
            comparables_count INT DEFAULT 0,
            estimated_value_mid DECIMAL(15,2),

            -- PDF tracking
            pdf_path VARCHAR(500),
            pdf_generated_at DATETIME,

            -- Timestamps
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,

            PRIMARY KEY (id),
            KEY idx_user_id (user_id),
            KEY idx_user_favorite (user_id, is_favorite),
            KEY idx_subject_listing (subject_listing_id),
            KEY idx_created_at (created_at),
            KEY idx_is_standalone (is_standalone),
            KEY idx_standalone_slug (standalone_slug)
        ) $charset_collate;";

        dbDelta($sql);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'");
            if ($table_exists) {
                error_log('[MLD CMA Session Database] Table created/updated successfully: ' . $table_name);
            } else {
                error_log('[MLD CMA Session Database] ERROR: Failed to create table: ' . $table_name);
                error_log('[MLD CMA Session Database] Last error: ' . $wpdb->last_error);
            }
        }
    }

    /**
     * Get table name
     *
     * @return string Table name with prefix
     */
    public static function get_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'mld_cma_saved_sessions';
    }

    /**
     * Check if table exists
     *
     * @return bool True if table exists
     */
    public static function table_exists() {
        global $wpdb;
        $table_name = self::get_table_name();
        return $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
    }

    /**
     * Drop table (for testing/uninstall)
     */
    public static function drop_table() {
        global $wpdb;
        $table_name = self::get_table_name();
        $wpdb->query("DROP TABLE IF EXISTS $table_name");
        delete_option(self::VERSION_OPTION);
    }
}
