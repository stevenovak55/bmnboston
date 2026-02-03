<?php
/**
 * Plugin Deactivator
 *
 * Handles plugin deactivation tasks.
 *
 * @package SN_Appointment_Booking
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Deactivator class.
 *
 * @since 1.0.0
 */
class SNAB_Deactivator {

    /**
     * Deactivate the plugin.
     *
     * Note: This does NOT delete data. Data is preserved for re-activation.
     * Use uninstall.php for complete data removal.
     *
     * @since 1.0.0
     */
    public static function deactivate() {
        // Clear scheduled cron events
        self::clear_scheduled_events();

        // Flush rewrite rules
        flush_rewrite_rules();

        // Log deactivation
        if (class_exists('SNAB_Logger')) {
            SNAB_Logger::info('Plugin deactivated');
        }
    }

    /**
     * Clear all scheduled cron events.
     *
     * @since 1.0.0
     */
    private static function clear_scheduled_events() {
        // Clear reminder cron
        $timestamp = wp_next_scheduled('snab_send_reminders');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'snab_send_reminders');
        }

        // Clear any other scheduled events
        wp_clear_scheduled_hook('snab_send_reminders');
        wp_clear_scheduled_hook('snab_cleanup_expired');
    }
}
