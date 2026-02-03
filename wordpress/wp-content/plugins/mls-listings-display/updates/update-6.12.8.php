<?php
/**
 * MLS Listings Display - Update to v6.12.8
 *
 * This update adds:
 * - Property Page Market Analytics Integration
 * - REST API endpoints for analytics on property detail pages
 * - Lazy-loaded analytics with IntersectionObserver
 * - Mobile-responsive lite analytics mode
 *
 * @package MLS_Listings_Display
 * @since 6.12.8
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Run update to version 6.12.8
 *
 * @return bool True on success
 */
function mld_update_to_6_12_8() {
    global $wpdb;

    $results = array(
        'success' => true,
        'messages' => array()
    );

    // 1. Clear all analytics caches to ensure fresh data
    if (function_exists('wp_cache_delete_group')) { wp_cache_delete_group('mld_property_analytics'); }
    if (function_exists('wp_cache_delete_group')) { wp_cache_delete_group('mld_analytics'); }
    delete_transient('mld_extended_analytics_cache');
    
    $results['messages'][] = 'Cleared analytics caches';

    // 2. Flush rewrite rules to register new REST endpoints
    flush_rewrite_rules();
    $results['messages'][] = 'Flushed rewrite rules for REST API';

    // 3. Verify required class files exist
    $required_files = array(
        'includes/class-mld-analytics-tabs.php',
        'includes/class-mld-analytics-rest-api.php',
        'assets/js/property-analytics.js',
        'assets/css/property-analytics.css'
    );

    $plugin_path = defined('MLD_PLUGIN_PATH') ? MLD_PLUGIN_PATH : plugin_dir_path(dirname(__FILE__));
    
    foreach ($required_files as $file) {
        $full_path = $plugin_path . $file;
        if (!file_exists($full_path)) {
            $results['messages'][] = 'Warning: Missing file - ' . $file;
            error_log('[MLD Update 6.12.8] Missing required file: ' . $full_path);
        } else {
            $results['messages'][] = 'Verified: ' . $file;
        }
    }

    // 4. Ensure analytics options are set with defaults
    $analytics_options = array(
        'mld_property_analytics_enabled' => 1,
        'mld_property_analytics_lazy_load' => 1,
        'mld_property_analytics_mobile_lite' => 1,
    );

    foreach ($analytics_options as $option => $default) {
        if (get_option($option) === false) {
            add_option($option, $default);
            $results['messages'][] = 'Added option: ' . $option;
        }
    }

    // 5. Clear object cache if available
    if (function_exists('wp_cache_flush')) {
        wp_cache_flush();
        $results['messages'][] = 'Flushed object cache';
    }

    // 6. Log upgrade completion
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[MLD Update 6.12.8] Upgrade completed successfully');
        foreach ($results['messages'] as $msg) {
            error_log('[MLD Update 6.12.8] ' . $msg);
        }
    }

    // Update version
    update_option('mld_db_version', '6.12.8');
    update_option('mld_plugin_version', '6.12.8');

    return $results['success'];
}
