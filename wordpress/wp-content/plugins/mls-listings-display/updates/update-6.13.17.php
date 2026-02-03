<?php
/**
 * MLD Update to version 6.13.17
 *
 * This update includes:
 * - Dark mode CSS fixes (removed prefers-color-scheme media queries)
 * - Comparable sales script enqueue fix for mobile
 * - Analytics REST API fix (was empty file)
 * - Market Velocity DOM bars calculation fix (exclusive vs cumulative)
 * - Property Analytics parseFloat fixes for string values
 *
 * @package MLS_Listings_Display
 * @since 6.13.17
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Run the 6.13.17 update
 *
 * @return bool True on success
 */
function mld_update_to_6.13.17() {
    global $wpdb;

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('MLD Update 6.13.17: Starting update - Dark mode fixes, Analytics REST API, Mobile comparable sales');
    }

    $success = true;

    try {
        // 1. Clear all transients related to MLD
        $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_mld_%'
             OR option_name LIKE '_transient_timeout_mld_%'"
        );

        // 2. Clear WordPress object cache
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }

        // 3. Force browser cache refresh for updated CSS/JS
        update_option('mld_asset_version', '6.13.17.' . time());

        // 4. Clear any cached REST API responses
        $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_mld_analytics_%'
             OR option_name LIKE '_transient_timeout_mld_analytics_%'"
        );

        // 5. Flush rewrite rules to ensure REST endpoints are registered
        set_transient('mld_flush_rewrite_rules', true, 60);

        // 6. Update version tracking
        update_option('mld_db_version', '6.13.17');
        update_option('mld_plugin_version', '6.13.17');

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Update 6.13.17: Update completed successfully');
            error_log('MLD Update 6.13.17: - Dark mode CSS fixes applied');
            error_log('MLD Update 6.13.17: - Analytics REST API endpoints registered');
            error_log('MLD Update 6.13.17: - Mobile comparable sales script enabled');
            error_log('MLD Update 6.13.17: - Market Velocity DOM bars calculation fixed');
        }

    } catch (Exception $e) {
        $success = false;
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Update 6.13.17: Error - ' . $e->getMessage());
        }
    }

    return $success;
}
