<?php
/**
 * Update to version 5.4.0
 *
 * Desktop Property Details Page Redesign
 * - No database changes required
 * - UI/UX improvements only
 * - Typography enhancements
 * - 70/30 layout implementation
 *
 * @package MLS_Listings_Display
 * @since 5.4.0
 */

if (!defined('ABSPATH')) {
    exit;
}

function mld_update_to_5_4_0() {
    global $wpdb;

    // Clear any cached assets to ensure new CSS/JS is loaded
    if (function_exists('wp_cache_flush')) {
        wp_cache_flush();
    }

    // Clear transients that might contain old styling
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_mld_%'");
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_mld_%'");

    // Log the update
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[MLD Update 5.4.0] Desktop property details page redesign completed');
    }

    // Update the database version
    update_option('mld_db_version', '5.4.0');

    return true;
}