<?php
/**
 * Plugin activation and deactivation handler
 *
 * @package Exclusive_Listings
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class EL_Activator
 *
 * Handles plugin activation and deactivation hooks.
 */
class EL_Activator {

    /**
     * Plugin activation callback
     *
     * Called when the plugin is activated. Creates necessary database tables
     * and sets default options.
     *
     * @since 1.0.0
     */
    public static function activate() {
        self::check_requirements();

        self::create_tables();
        self::set_default_options();
        self::log_activation();

        flush_rewrite_rules();
    }

    /**
     * Check PHP and WordPress version requirements
     */
    private static function check_requirements() {
        if (version_compare(PHP_VERSION, '7.4', '<')) {
            self::activation_error('Exclusive Listings requires PHP 7.4 or higher. Your server is running PHP ' . PHP_VERSION);
        }

        if (version_compare(get_bloginfo('version'), '6.0', '<')) {
            self::activation_error('Exclusive Listings requires WordPress 6.0 or higher.');
        }
    }

    /**
     * Handle activation error
     */
    private static function activation_error($message) {
        deactivate_plugins(EL_PLUGIN_BASENAME);
        wp_die($message, 'Plugin Activation Error', array('back_link' => true));
    }

    /**
     * Plugin deactivation callback
     *
     * Called when the plugin is deactivated. Performs cleanup but preserves data.
     * Data deletion should only happen on uninstall.
     *
     * @since 1.0.0
     */
    public static function deactivate() {
        self::log_deactivation();
        flush_rewrite_rules();
    }

    /**
     * Create required database tables
     *
     * @since 1.0.0
     */
    private static function create_tables() {
        global $wpdb;

        $sequence_table = $wpdb->prefix . 'exclusive_listing_sequence';

        $sql = "CREATE TABLE IF NOT EXISTS {$sequence_table} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
        ) {$wpdb->get_charset_collate()}";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $sequence_table));

        if (!$table_exists) {
            error_log('Exclusive Listings: Failed to create sequence table');
        }
    }

    /**
     * Set default plugin options
     *
     * @since 1.0.0
     */
    private static function set_default_options() {
        // Plugin version
        add_option('el_version', EL_VERSION);

        // Database version
        add_option('el_db_version', EL_DB_VERSION);

        // Activation timestamp
        add_option('el_activated_at', current_time('mysql'));

        // Default settings
        $default_settings = array(
            'enable_geocoding' => true,
            'geocoding_provider' => 'nominatim', // 'nominatim' or 'google'
            'max_photos_per_listing' => 50,
            'image_quality' => 85,
            'max_upload_size_mb' => 10,
            'default_status' => 'Active',
            'default_state' => 'MA',
        );

        add_option('el_settings', $default_settings);
    }

    /**
     * Log plugin activation
     *
     * @since 1.0.0
     */
    private static function log_activation() {
        $log_data = array(
            'event' => 'activation',
            'version' => EL_VERSION,
            'php_version' => PHP_VERSION,
            'wp_version' => get_bloginfo('version'),
            'timestamp' => current_time('mysql'),
            'user_id' => get_current_user_id(),
        );

        error_log('Exclusive Listings activated: ' . wp_json_encode($log_data));
    }

    /**
     * Log plugin deactivation
     *
     * @since 1.0.0
     */
    private static function log_deactivation() {
        $log_data = array(
            'event' => 'deactivation',
            'version' => EL_VERSION,
            'timestamp' => current_time('mysql'),
            'user_id' => get_current_user_id(),
        );

        error_log('Exclusive Listings deactivated: ' . wp_json_encode($log_data));
    }

    /**
     * Check if the plugin has been properly activated
     *
     * @since 1.0.0
     * @return bool True if activated properly, false otherwise
     */
    public static function is_activated() {
        global $wpdb;

        $sequence_table = $wpdb->prefix . 'exclusive_listing_sequence';

        $table_exists = $wpdb->get_var(
            $wpdb->prepare(
                "SHOW TABLES LIKE %s",
                $sequence_table
            )
        );

        return (bool) $table_exists;
    }
}
