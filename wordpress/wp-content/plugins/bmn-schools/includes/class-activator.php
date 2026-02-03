<?php
/**
 * Plugin Activator
 *
 * Handles plugin activation: creates database tables and initializes options.
 *
 * @package BMN_Schools
 * @since 0.1.0
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Plugin Activator Class
 *
 * @since 0.1.0
 */
class BMN_Schools_Activator {

    /**
     * Activate the plugin.
     *
     * Creates database tables, sets default options, and logs activation.
     *
     * @since 0.1.0
     */
    public static function activate() {
        // Require dependencies
        require_once BMN_SCHOOLS_PLUGIN_DIR . 'includes/class-database-manager.php';
        require_once BMN_SCHOOLS_PLUGIN_DIR . 'includes/class-logger.php';

        // Create database tables
        $db_manager = new BMN_Schools_Database_Manager();
        $results = $db_manager->create_tables();

        // Verify all tables were created
        $table_status = $db_manager->verify_tables();
        $all_tables_exist = true;

        foreach ($table_status as $key => $status) {
            if (!$status['exists']) {
                $all_tables_exist = false;
                error_log('[BMN Schools] Failed to create table: ' . $status['table']);
            }
        }

        // Set default options
        self::set_default_options();

        // Initialize data sources
        self::init_data_sources($db_manager);

        // Update version
        update_option('bmn_schools_version', BMN_SCHOOLS_VERSION);
        update_option('bmn_schools_db_version', BMN_SCHOOLS_DB_VERSION);
        update_option('bmn_schools_activated', current_time('mysql'));

        // Log activation (if logger table was created successfully)
        if ($db_manager->table_exists($db_manager->get_table('activity_log'))) {
            BMN_Schools_Logger::log('info', 'admin', 'Plugin activated', [
                'version' => BMN_SCHOOLS_VERSION,
                'tables_created' => $all_tables_exist,
                'table_status' => $table_status
            ]);
        }

        // Schedule annual data sync (September 1st, after DESE releases new data)
        self::schedule_annual_sync();

        // Set flag to flush rewrite rules on next init (for school pages)
        update_option('bmn_schools_flush_rewrite_rules', true);

        // Clear any cached data
        wp_cache_flush();
    }

    /**
     * Schedule the annual data sync cron job.
     *
     * @since 0.6.16
     */
    private static function schedule_annual_sync() {
        // Clear any existing schedule first
        wp_clear_scheduled_hook('bmn_schools_annual_sync');

        // Schedule for September 1st at 2:00 AM (after DESE publishes new school year data)
        $next_september = strtotime('first day of September ' . date('Y') . ' 2:00 AM');

        // If September has already passed this year, schedule for next year
        if ($next_september < time()) {
            $next_september = strtotime('first day of September ' . (date('Y') + 1) . ' 2:00 AM');
        }

        wp_schedule_event($next_september, 'annually', 'bmn_schools_annual_sync');
    }

    /**
     * Set default plugin options.
     *
     * @since 0.1.0
     */
    private static function set_default_options() {
        // General settings
        add_option('bmn_schools_settings', [
            'enable_cache' => true,
            'cache_duration' => 1800, // 30 minutes
            'debug_mode' => false,
            'default_state' => 'MA',
            'results_per_page' => 20,
        ]);

        // Sync settings
        add_option('bmn_schools_sync_settings', [
            'auto_sync_enabled' => false,
            'sync_frequency' => 'daily',
            'last_full_sync' => null,
        ]);

        // API credentials (empty by default)
        add_option('bmn_schools_api_credentials', [
            'schooldigger_key' => '',
            'greatschools_key' => '',
            'attom_key' => '',
        ]);
    }

    /**
     * Initialize data sources in the database.
     *
     * @since 0.1.0
     * @param BMN_Schools_Database_Manager $db_manager Database manager instance.
     */
    private static function init_data_sources($db_manager) {
        global $wpdb;

        $table = $db_manager->get_table('data_sources');

        if (!$db_manager->table_exists($table)) {
            return;
        }

        $sources = [
            [
                'source_name' => 'nces_ccd',
                'source_type' => 'bulk_download',
                'source_url' => 'https://nces.ed.gov/ccd/ccddata.asp',
                'status' => 'pending',
            ],
            [
                'source_name' => 'nces_edge',
                'source_type' => 'rest_api',
                'source_url' => 'https://nces.ed.gov/opengis/rest/services/School_District_Boundaries/',
                'status' => 'pending',
            ],
            [
                'source_name' => 'ma_dese',
                'source_type' => 'bulk_download',
                'source_url' => 'https://profiles.doe.mass.edu/',
                'status' => 'pending',
            ],
            [
                'source_name' => 'massgis',
                'source_type' => 'geojson',
                'source_url' => 'https://www.mass.gov/info-details/massgis-data-public-school-districts',
                'status' => 'pending',
            ],
            [
                'source_name' => 'boston_open_data',
                'source_type' => 'rest_api',
                'source_url' => 'https://data.boston.gov/dataset/public-schools',
                'status' => 'pending',
            ],
            [
                'source_name' => 'schooldigger',
                'source_type' => 'rest_api',
                'source_url' => 'https://api.schooldigger.com/',
                'api_key_option' => 'bmn_schools_api_credentials[schooldigger_key]',
                'status' => 'disabled',
            ],
            [
                'source_name' => 'greatschools',
                'source_type' => 'rest_api',
                'source_url' => 'https://api.greatschools.org/',
                'api_key_option' => 'bmn_schools_api_credentials[greatschools_key]',
                'status' => 'disabled',
            ],
            [
                'source_name' => 'attom',
                'source_type' => 'rest_api',
                'source_url' => 'https://api.gateway.attomdata.com/',
                'api_key_option' => 'bmn_schools_api_credentials[attom_key]',
                'status' => 'disabled',
            ],
        ];

        foreach ($sources as $source) {
            // Check if already exists
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table} WHERE source_name = %s",
                $source['source_name']
            ));

            if (!$exists) {
                $wpdb->insert($table, $source);
            }
        }
    }
}
