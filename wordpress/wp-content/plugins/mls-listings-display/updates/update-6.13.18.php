<?php
/**
 * MLS Listings Display Update to 6.13.18
 *
 * This update adds no-cache headers to all AJAX responses to prevent
 * Kinsta and other hosting providers from caching dynamic listing data.
 *
 * Changes in 6.13.18:
 * - Added set_nocache_headers() method to MLD_Ajax class
 * - Applied no-cache headers to all 15 AJAX callback methods
 * - Headers include: Cache-Control, Pragma, Expires, X-Accel-Expires
 * - Fixes issue where cached AJAX responses returned stale listing data
 *
 * @package MLS_Listings_Display
 * @since 6.13.18
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Run update to version 6.13.18
 *
 * This version is primarily a code fix (no database changes required).
 * The update file exists for version tracking and documentation purposes.
 *
 * @return bool True on success
 */
function mld_update_to_6_13_18() {
    // Log the update
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[MLD Update 6.13.18] Starting update - Adding AJAX no-cache headers');
    }

    // Clear any cached AJAX responses that might exist
    // This ensures fresh data is served after the update
    if (function_exists('wp_cache_flush')) {
        wp_cache_flush();
    }

    // Clear transients related to MLD
    global $wpdb;
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_mld_%'");
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_mld_%'");

    // Update the database version
    update_option('mld_db_version', '6.13.18');

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[MLD Update 6.13.18] Update complete - AJAX responses will now include no-cache headers');
    }

    return true;
}
