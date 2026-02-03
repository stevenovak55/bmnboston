<?php
/**
 * Analytics Feature Activator
 *
 * Handles database table creation and setup for neighborhood analytics feature
 *
 * @package    MLS_Listings_Display
 * @subpackage MLS_Listings_Display/includes
 * @since      5.2.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class MLD_Analytics_Activator {

    /**
     * Activate analytics feature
     *
     * Creates necessary database tables and sets up defaults
     */
    public static function activate() {
        require_once plugin_dir_path(__FILE__) . 'class-mld-neighborhood-analytics.php';

        $analytics = new MLD_Neighborhood_Analytics();

        // Create database tables
        $result = $analytics->create_tables();

        if ($result) {
            // Set activation flag
            update_option('mld_analytics_version', '1.0.0');
            update_option('mld_analytics_activated', current_time('mysql'));

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD Analytics: Feature activated successfully');
            }
        } else {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD Analytics: Failed to activate feature');
            }
        }

        // Schedule cron job for daily analytics refresh
        if (!wp_next_scheduled('mld_refresh_analytics_hook')) {
            wp_schedule_event(time(), 'daily', 'mld_refresh_analytics_hook');
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD Analytics: Scheduled daily cron job');
            }
        }
    }

    /**
     * Deactivate analytics feature
     */
    public static function deactivate() {
        // Unschedule cron job
        $timestamp = wp_next_scheduled('mld_refresh_analytics_hook');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'mld_refresh_analytics_hook');
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD Analytics: Unscheduled cron job');
            }
        }
    }

    /**
     * Check if analytics feature is activated
     */
    public static function is_activated() {
        return get_option('mld_analytics_activated') !== false;
    }
}
