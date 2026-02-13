<?php
/**
 * Fired during plugin deactivation.
 *
 * This class defines all code necessary to run during the plugin's deactivation.
 * Handles cleanup of database tables and scheduled events.
 *
 * @package MLS_Listings_Display
 * @since 4.4.1
 */

class MLD_Deactivator {

    /**
     * Plugin deactivation handler.
     * Cleans up scheduled events and optionally removes database tables.
     */
    public static function deactivate() {
        // Clear all scheduled cron jobs
        self::clear_scheduled_events();

        // Clear rewrite rules
        if (class_exists('MLD_Rewrites')) {
            MLD_Rewrites::deactivate();
        }

        // Flush rewrite rules
        flush_rewrite_rules();

        // Set deactivation flag
        update_option('mld_plugin_deactivated', current_time('timestamp'));

        // Note: We don't delete database tables on deactivation
        // Tables are only removed on uninstall to preserve user data
    }

    /**
     * Clear all scheduled events created by the plugin
     */
    private static function clear_scheduled_events() {
        // List of all cron hooks used by the plugin
        $cron_hooks = [
            // Saved search notification crons
            'mld_send_instant_notifications',
            'mld_send_hourly_notifications',
            'mld_send_daily_notifications',
            'mld_send_weekly_notifications',
            'mld_saved_search_cleanup',

            // Instant notification crons
            'mld_instant_notifications_cleanup',
            'mld_process_bulk_instant_notifications',
            'mld_process_notification_queue',
            'mld_cleanup_notification_queue',

            // School import cron
            'mld_import_schools_data',

            // Simple notification system cron
            'mld_simple_notifications_check',

            // Open house notification crons
            'mld_open_house_detection',
            'mld_open_house_reminders',

            // Any other scheduled events
            'mld_cleanup_old_submissions',
            'mld_cleanup_expired_boundaries',
            'mld_optimize_tables'
        ];

        foreach ($cron_hooks as $hook) {
            $timestamp = wp_next_scheduled($hook);
            if ($timestamp) {
                wp_unschedule_event($timestamp, $hook);
            }

            // Also clear all scheduled events for this hook
            wp_clear_scheduled_hook($hook);
        }
    }

    /**
     * Complete uninstall - removes all plugin data
     * This should only be called when the plugin is being deleted
     */
    public static function uninstall() {
        // Only run if called from WordPress uninstall
        if (!defined('WP_UNINSTALL_PLUGIN')) {
            return;
        }

        // Remove all database tables
        self::remove_database_tables();

        // Remove all plugin options
        self::remove_plugin_options();

        // Clear any remaining transients
        self::clear_transients();

        // Remove uploaded files if any
        self::remove_uploaded_files();
    }

    /**
     * Remove all database tables created by the plugin
     */
    private static function remove_database_tables() {
        global $wpdb;

        // List of all tables created by the plugin
        $tables = [
            // Form submissions
            'mld_form_submissions',

            // Schools
            'mld_schools',
            'mld_property_schools',

            // City boundaries
            'mld_city_boundaries',

            // Saved searches
            'mld_saved_searches',
            'mld_saved_search_results',
            'mld_saved_search_email_settings',
            'mld_property_preferences',
            'mld_saved_search_cron_log',

            // Agent relationships
            'mld_agent_client_relationships',
            'mld_agent_profiles',
            'mld_admin_client_preferences',

            // Instant notifications
            'mld_search_activity_matches',
            'mld_notification_preferences',
            'mld_notification_throttle',

            // V2 notification system
            'mld_notification_queue',
            'mld_property_changes',
            'mld_notification_history',
            'mld_user_notification_settings'
        ];

        // Disable foreign key checks to avoid constraint errors
        $wpdb->query('SET FOREIGN_KEY_CHECKS = 0');

        foreach ($tables as $table) {
            $table_name = $wpdb->prefix . $table;
            $wpdb->query("DROP TABLE IF EXISTS $table_name");
        }

        // Re-enable foreign key checks
        $wpdb->query('SET FOREIGN_KEY_CHECKS = 1');
    }

    /**
     * Remove all plugin options from the database
     */
    private static function remove_plugin_options() {
        // List of all options created by the plugin
        $options = [
            // General options
            'mld_plugin_activated',
            'mld_plugin_deactivated',
            'mld_plugin_version',
            'mld_db_version',

            // Saved search options
            'mld_saved_searches_enabled',
            'mld_saved_searches_db_version',
            'mld_email_template_version',

            // Instant notification options
            'mld_instant_notifications_enabled',
            'mld_instant_notifications_db_version',
            'mld_global_quiet_hours_enabled',
            'mld_global_throttling_enabled',
            'mld_override_user_preferences',
            'mld_instant_bulk_threshold',
            'mld_instant_quiet_hours_start',
            'mld_instant_quiet_hours_end',
            'mld_default_quiet_start',
            'mld_default_quiet_end',
            'mld_default_daily_limit',
            'mld_throttle_window_minutes',
            'mld_max_notifications_per_window',
            'mld_enable_bulk_import_throttle',
            'mld_bulk_import_threshold',
            'mld_email_from_name',
            'mld_email_from_address',
            'mld_enable_notification_logs',
            'mld_log_retention_days',

            // School options
            'mld_schools_last_import',
            'mld_schools_import_status',

            // City boundaries options
            'mld_boundaries_cache_duration',

            // API options
            'mld_google_maps_api_key',
            'mld_bridge_api_token',

            // Display options
            'mld_properties_per_page',
            'mld_default_view_type',
            'mld_enable_saved_searches',
            'mld_enable_instant_notifications',

            // V2 notification system options
            'mld_enable_v2_notifications',
            'mld_notification_db_version',
            'mld_v2_notification_system_available'
        ];

        foreach ($options as $option) {
            delete_option($option);
        }

        // Also remove any options that might have been created dynamically
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'mld_%'");
    }

    /**
     * Clear all transients created by the plugin
     */
    private static function clear_transients() {
        global $wpdb;

        // Remove all transients with our prefix
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_mld_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_mld_%'");
    }

    /**
     * Remove uploaded files (if any)
     */
    private static function remove_uploaded_files() {
        // Get upload directory
        $upload_dir = wp_upload_dir();
        $mld_upload_dir = $upload_dir['basedir'] . '/mld-uploads';

        // Remove directory if it exists
        if (is_dir($mld_upload_dir)) {
            self::remove_directory($mld_upload_dir);
        }
    }

    /**
     * Recursively remove a directory
     */
    private static function remove_directory($dir) {
        if (!is_dir($dir)) {
            return;
        }

        $objects = scandir($dir);
        foreach ($objects as $object) {
            if ($object != "." && $object != "..") {
                if (is_dir($dir . "/" . $object)) {
                    self::remove_directory($dir . "/" . $object);
                } else {
                    unlink($dir . "/" . $object);
                }
            }
        }
        rmdir($dir);
    }
}