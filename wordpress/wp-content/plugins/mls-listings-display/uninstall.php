<?php
/**
 * MLS Listings Display Uninstall
 *
 * Uninstalling MLS Listings Display deletes all database tables, options,
 * transients, user meta, and any other plugin data.
 *
 * @package MLS_Listings_Display
 * @since 4.5.46
 */

// If uninstall not called from WordPress, exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    die;
}

/**
 * MLS Listings Display Uninstall Class
 */
class MLD_Uninstall {

    /**
     * Run the uninstall process
     */
    public static function uninstall() {
        global $wpdb;

        // Only proceed if we have the proper permissions
        if (!current_user_can('activate_plugins')) {
            return;
        }

        // Get the main blog id for multisite
        $blog_id = is_multisite() ? get_current_blog_id() : null;

        // If multisite, handle network-wide uninstall
        if (is_multisite()) {
            $blog_ids = $wpdb->get_col("SELECT blog_id FROM $wpdb->blogs");

            foreach ($blog_ids as $blog_id) {
                switch_to_blog($blog_id);
                self::delete_plugin_data();
                restore_current_blog();
            }
        } else {
            self::delete_plugin_data();
        }

        // Clear any cached data
        wp_cache_flush();
    }

    /**
     * Delete all plugin data for the current site
     */
    private static function delete_plugin_data() {
        self::delete_database_tables();
        self::delete_options();
        self::delete_transients();
        self::delete_user_meta();
        self::delete_cron_events();
        self::delete_custom_capabilities();
        self::cleanup_uploads_directory();
        self::cleanup_cache_directory();
    }

    /**
     * Delete all database tables created by the plugin
     */
    private static function delete_database_tables() {
        global $wpdb;

        // Array of all MLD plugin tables
        $tables = array(
            // Main MLD tables
            $wpdb->prefix . 'mld_admin_client_preferences',
            $wpdb->prefix . 'mld_agent_client_relationships',
            $wpdb->prefix . 'mld_agent_profiles',
            $wpdb->prefix . 'mld_city_boundaries',
            $wpdb->prefix . 'mld_form_submissions',
            $wpdb->prefix . 'mld_migration_history',
            $wpdb->prefix . 'mld_notification_history',
            $wpdb->prefix . 'mld_notification_preferences',
            $wpdb->prefix . 'mld_notification_queue',
            $wpdb->prefix . 'mld_notification_throttle',
            $wpdb->prefix . 'mld_property_preferences',
            $wpdb->prefix . 'mld_property_schools',
            $wpdb->prefix . 'mld_saved_search_cron_log',
            $wpdb->prefix . 'mld_saved_search_email_settings',
            $wpdb->prefix . 'mld_saved_search_results',
            $wpdb->prefix . 'mld_saved_searches',
            $wpdb->prefix . 'mld_schools',
            $wpdb->prefix . 'mld_search_activity_matches'
        );

        // Drop each table
        foreach ($tables as $table) {
            $wpdb->query("DROP TABLE IF EXISTS `$table`");
        }

        // Log the table deletion
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Uninstall: Deleted ' . count($tables) . ' database tables');
        }
    }

    /**
     * Delete all options created by the plugin
     */
    private static function delete_options() {
        global $wpdb;

        // List of all MLD options to delete
        $options = array(
            'mld_activation_result',
            'mld_bulk_import_threshold',
            'mld_contact_settings',
            'mld_db_version',
            'mld_default_daily_limit',
            'mld_default_quiet_end',
            'mld_default_quiet_start',
            'mld_display_settings',
            'mld_email_from_address',
            'mld_email_from_name',
            'mld_email_template_instant',
            'mld_email_templates',
            'mld_enable_bulk_import_throttle',
            'mld_enable_instant_notifications',
            'mld_enable_notification_logs',
            'mld_enable_saved_searches',
            'mld_file_checksums',
            'mld_global_quiet_hours_enabled',
            'mld_global_throttling_enabled',
            'mld_instant_bulk_threshold',
            'mld_instant_notifications_db_version',
            'mld_instant_notifications_enabled',
            'mld_instant_quiet_hours_end',
            'mld_instant_quiet_hours_start',
            'mld_last_backup_timestamp',
            'mld_last_installation_verification',
            'mld_last_update',
            'mld_last_update_time',
            'mld_log_retention_days',
            'mld_max_notifications_per_window',
            'mld_override_user_preferences',
            'mld_patch_4_5_46_applied',
            'mld_patch_4_5_46_backup',
            'mld_patch_4_5_46_results',
            'mld_plugin_activated',
            'mld_plugin_deactivated',
            'mld_plugin_version',
            'mld_saved_search_cron_results',
            'mld_saved_search_db_version',
            'mld_saved_searches_enabled',
            'mld_schema_version',
            'mld_settings',
            'mld_template_migration_v1_complete',
            'mld_throttle_window_minutes',
            'mld_upgrade_notices',
            'mld_installation_time',
            'mld_license_key',
            'mld_license_status',
            'mld_api_settings',
            'mld_map_settings',
            'mld_search_settings',
            'mld_performance_settings',
            'mld_cache_settings',
            'mld_seo_settings',
            'mld_property_url_structure',
            'mld_notification_settings',
            'mld_email_settings',
            'mld_agent_settings',
            'mld_client_settings',
            'mld_form_settings',
            'mld_analytics_settings',
            'mld_debug_mode',
            'mld_maintenance_mode'
        );

        // Delete each option
        foreach ($options as $option) {
            delete_option($option);
        }

        // Also delete any options that might have been created dynamically
        // Query for options with mld_ prefix
        $dynamic_options = $wpdb->get_col(
            "SELECT option_name FROM {$wpdb->options}
             WHERE option_name LIKE 'mld_%'
             OR option_name LIKE '%_mld_%'"
        );

        foreach ($dynamic_options as $option) {
            delete_option($option);
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Uninstall: Deleted ' . (count($options) + count($dynamic_options)) . ' options');
        }
    }

    /**
     * Delete all transients created by the plugin
     */
    private static function delete_transients() {
        global $wpdb;

        // Delete transients with MLD prefix
        $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_mld_%'
             OR option_name LIKE '_transient_timeout_mld_%'"
        );

        // Delete site transients for multisite
        if (is_multisite()) {
            $wpdb->query(
                "DELETE FROM {$wpdb->sitemeta}
                 WHERE meta_key LIKE '_site_transient_mld_%'
                 OR meta_key LIKE '_site_transient_timeout_mld_%'"
            );
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Uninstall: Deleted all transients');
        }
    }

    /**
     * Delete user meta created by the plugin
     */
    private static function delete_user_meta() {
        global $wpdb;

        // List of user meta keys used by the plugin
        $user_meta_keys = array(
            'mld_saved_properties',
            'mld_favorite_properties',
            'mld_property_views',
            'mld_search_history',
            'mld_notification_preferences',
            'mld_email_preferences',
            'mld_dashboard_settings',
            'mld_map_preferences',
            'mld_search_filters',
            'mld_last_search',
            'mld_agent_profile',
            'mld_client_profile',
            'mld_user_settings',
            'mld_dismissed_notices'
        );

        // Delete each user meta key for all users
        foreach ($user_meta_keys as $meta_key) {
            $wpdb->delete($wpdb->usermeta, array('meta_key' => $meta_key));
        }

        // Also delete any user meta that might have been created dynamically
        $wpdb->query(
            "DELETE FROM {$wpdb->usermeta}
             WHERE meta_key LIKE 'mld_%'
             OR meta_key LIKE '_mld_%'"
        );

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Uninstall: Deleted all user meta');
        }
    }

    /**
     * Remove scheduled cron events
     */
    private static function delete_cron_events() {
        // List of cron hooks used by the plugin
        $cron_hooks = array(
            'mld_saved_search_cron',
            'mld_hourly_saved_search',
            'mld_daily_saved_search',
            'mld_weekly_saved_search',
            'mld_instant_notification_check',
            'mld_process_notification_queue',
            'mld_cleanup_old_notifications',
            'mld_cleanup_old_logs',
            'mld_optimize_database',
            'mld_update_city_boundaries',
            'mld_update_school_data',
            'mld_generate_sitemap',
            'mld_cache_cleanup',
            'mld_performance_report',
            'mld_sync_agent_data',
            'mld_backup_settings'
        );

        // Clear each scheduled event
        foreach ($cron_hooks as $hook) {
            $timestamp = wp_next_scheduled($hook);
            if ($timestamp) {
                wp_unschedule_event($timestamp, $hook);
            }

            // Clear all events with this hook (in case there are multiple)
            wp_clear_scheduled_hook($hook);
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Uninstall: Removed ' . count($cron_hooks) . ' cron events');
        }
    }

    /**
     * Remove custom capabilities added by the plugin
     */
    private static function delete_custom_capabilities() {
        // Get the administrator role
        $admin_role = get_role('administrator');

        // List of capabilities added by the plugin
        $capabilities = array(
            'mld_manage_settings',
            'mld_manage_properties',
            'mld_manage_searches',
            'mld_manage_clients',
            'mld_manage_agents',
            'mld_view_analytics',
            'mld_manage_notifications',
            'mld_manage_forms',
            'mld_export_data',
            'mld_import_data'
        );

        // Remove each capability
        if ($admin_role) {
            foreach ($capabilities as $cap) {
                $admin_role->remove_cap($cap);
            }
        }

        // Also check other roles that might have been given capabilities
        $roles = array('editor', 'author', 'contributor', 'subscriber');
        foreach ($roles as $role_name) {
            $role = get_role($role_name);
            if ($role) {
                foreach ($capabilities as $cap) {
                    $role->remove_cap($cap);
                }
            }
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Uninstall: Removed custom capabilities');
        }
    }

    /**
     * Clean up uploads directory
     */
    private static function cleanup_uploads_directory() {
        $upload_dir = wp_upload_dir();
        $mld_upload_dir = $upload_dir['basedir'] . '/mld-listings';

        if (is_dir($mld_upload_dir)) {
            self::delete_directory($mld_upload_dir);
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD Uninstall: Deleted uploads directory');
            }
        }

        // Also clean up any MLD directories in uploads
        $mld_directories = array(
            $upload_dir['basedir'] . '/mld-cache',
            $upload_dir['basedir'] . '/mld-temp',
            $upload_dir['basedir'] . '/mld-exports',
            $upload_dir['basedir'] . '/mld-imports',
            $upload_dir['basedir'] . '/mld-logs'
        );

        foreach ($mld_directories as $dir) {
            if (is_dir($dir)) {
                self::delete_directory($dir);
            }
        }
    }

    /**
     * Clean up cache directory
     */
    private static function cleanup_cache_directory() {
        $cache_dir = WP_CONTENT_DIR . '/cache/mld-listings-display';

        if (is_dir($cache_dir)) {
            self::delete_directory($cache_dir);
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD Uninstall: Deleted cache directory');
            }
        }

        // Clean up any other cache locations
        $additional_cache_dirs = array(
            WP_CONTENT_DIR . '/mld-cache',
            WP_CONTENT_DIR . '/uploads/mld-cache'
        );

        foreach ($additional_cache_dirs as $dir) {
            if (is_dir($dir)) {
                self::delete_directory($dir);
            }
        }
    }

    /**
     * Recursively delete a directory and its contents
     *
     * @param string $dir Directory path
     */
    private static function delete_directory($dir) {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), array('.', '..'));

        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                self::delete_directory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }

    /**
     * Log uninstall completion
     */
    private static function log_uninstall() {
        // Create a final log entry before complete removal
        $log_message = sprintf(
            'MLS Listings Display plugin completely uninstalled on %s',
            current_time('mysql')
        );

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log($log_message);
        }

        // If we can write to a file, create an uninstall log
        $log_file = WP_CONTENT_DIR . '/mld-uninstall.log';
        if (is_writable(WP_CONTENT_DIR)) {
            file_put_contents($log_file, $log_message . PHP_EOL, FILE_APPEND);
        }
    }
}

// Run the uninstall process
try {
    MLD_Uninstall::uninstall();
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('MLS Listings Display: Uninstall completed successfully');
    }
} catch (Exception $e) {
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('MLS Listings Display Uninstall Error: ' . $e->getMessage());
    }
}