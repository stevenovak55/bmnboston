<?php
/**
 * MLS Listings Display - Update to v6.13.0
 *
 * This update adds:
 * - 15-minute saved search email alert system
 * - Enhanced change detection for new listings, price changes, and status changes
 * - Comprehensive filter matching for all 45+ half-map search filters
 * - Change-type-specific email notifications with 25-listing limit
 *
 * @package MLS_Listings_Display
 * @since 6.13.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Run update to version 6.13.0
 *
 * @return bool True on success
 */
function mld_update_to_6_13_0() {
    global $wpdb;

    $results = array(
        'success' => true,
        'messages' => array()
    );

    // 1. Clear any cached notification data
    delete_transient('mld_notification_cache');
    delete_transient('mld_saved_search_cache');
    $results['messages'][] = 'Cleared notification caches';

    // 2. Ensure 15-minute cron schedule is registered
    // The actual scheduling happens in class-mld-saved-search-cron.php
    // Just clear any stale schedules
    wp_clear_scheduled_hook('mld_saved_search_fifteen_min');
    $results['messages'][] = 'Cleared stale 15-minute cron hook for fresh registration';

    // 3. Verify wp_bme_property_history table exists (dependency on BME plugin)
    $history_table = $wpdb->prefix . 'bme_property_history';
    $table_exists = $wpdb->get_var($wpdb->prepare(
        "SHOW TABLES LIKE %s",
        $history_table
    ));

    if ($table_exists) {
        $results['messages'][] = 'Verified: wp_bme_property_history table exists for change detection';
    } else {
        $results['messages'][] = 'Warning: wp_bme_property_history table not found - BME plugin required';
        error_log('[MLD Update 6.13.0] wp_bme_property_history table not found. Bridge MLS Extractor Pro must be active.');
    }

    // 4. Verify notification_tracker table has required columns
    $tracker_table = $wpdb->prefix . 'mld_notification_tracker';
    $tracker_exists = $wpdb->get_var($wpdb->prepare(
        "SHOW TABLES LIKE %s",
        $tracker_table
    ));

    if ($tracker_exists) {
        // Check if notification_type column exists, add if not
        $columns = $wpdb->get_results("SHOW COLUMNS FROM {$tracker_table} LIKE 'notification_type'");
        if (empty($columns)) {
            $wpdb->query("ALTER TABLE {$tracker_table} ADD COLUMN notification_type VARCHAR(50) DEFAULT 'listing_update' AFTER search_id");
            $results['messages'][] = 'Added notification_type column to tracker table';
        } else {
            $results['messages'][] = 'Verified: notification_type column exists';
        }
    } else {
        $results['messages'][] = 'Note: notification_tracker table will be created on first use';
    }

    // 5. Clear object cache
    if (function_exists('wp_cache_flush')) {
        wp_cache_flush();
        $results['messages'][] = 'Flushed object cache';
    }

    // 6. Log upgrade completion
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[MLD Update 6.13.0] Upgrade completed - 15-minute saved search alerts enabled');
        foreach ($results['messages'] as $msg) {
            error_log('[MLD Update 6.13.0] ' . $msg);
        }
    }

    // Update version
    update_option('mld_db_version', '6.13.0');
    update_option('mld_plugin_version', '6.13.0');

    return $results['success'];
}
