<?php
/**
 * Plugin Deactivator
 *
 * Handles plugin deactivation: clears scheduled tasks and caches.
 *
 * @package BMN_Schools
 * @since 0.1.0
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Plugin Deactivator Class
 *
 * @since 0.1.0
 */
class BMN_Schools_Deactivator {

    /**
     * Deactivate the plugin.
     *
     * Clears scheduled cron jobs and flushes caches.
     * NOTE: Does NOT delete database tables or options (that's for uninstall).
     *
     * @since 0.1.0
     */
    public static function deactivate() {
        // Clear all scheduled cron jobs
        self::clear_scheduled_events();

        // Flush caches
        wp_cache_flush();

        // Log deactivation
        require_once BMN_SCHOOLS_PLUGIN_DIR . 'includes/class-logger.php';

        BMN_Schools_Logger::log('info', 'admin', 'Plugin deactivated', [
            'version' => BMN_SCHOOLS_VERSION
        ]);

        // Update deactivation timestamp
        update_option('bmn_schools_deactivated', current_time('mysql'));
    }

    /**
     * Clear all scheduled cron events.
     *
     * @since 0.1.0
     */
    private static function clear_scheduled_events() {
        $cron_hooks = [
            'bmn_schools_annual_sync',      // Annual data refresh (September 1st)
            'bmn_schools_daily_sync',
            'bmn_schools_weekly_validate',
            'bmn_schools_monthly_full_sync',
            'bmn_schools_cleanup_logs',
        ];

        foreach ($cron_hooks as $hook) {
            $timestamp = wp_next_scheduled($hook);
            if ($timestamp) {
                wp_unschedule_event($timestamp, $hook);
            }
            // Also clear any recurring events
            wp_clear_scheduled_hook($hook);
        }
    }
}
