<?php
/**
 * Quick initialization script for Performance features
 * Run this once to ensure everything is set up correctly
 *
 * Access: yoursite.com/wp-content/plugins/mls-listings-display/admin/init-performance.php
 */

// Load WordPress
$wp_load_path = dirname(dirname(dirname(dirname(dirname(__FILE__))))) . '/wp-load.php';
if (!file_exists($wp_load_path)) {
    wp_die('Error: Cannot find wp-load.php', 'Configuration Error', ['response' => 500]);
}
require_once($wp_load_path);

// Check admin permission
if (!current_user_can('manage_options')) {
    wp_die('You must be logged in as an administrator.', 'Access Denied', ['response' => 403]);
}

echo "<h2>Initializing Performance Features...</h2>";

// 1. Create initial indexes (safe to run multiple times)
echo "<h3>Creating Database Indexes:</h3>";
if (class_exists('MLD_Database_Optimizer')) {
    $results = MLD_Database_Optimizer::optimizeIndexes();
    // Results processed
    echo "<p style='color:green;'>✓ Database optimization attempted</p>";
} else {
    echo "<p style='color:red;'>✗ Database Optimizer class not found</p>";
}

// 2. Initialize cache
echo "<h3>Testing Cache:</h3>";
if (class_exists('MLD_Query_Cache')) {
    $test_key = 'init_test_' . time();
    MLD_Query_Cache::set($test_key, 'test_data', 60);
    $result = MLD_Query_Cache::get($test_key);
    if ($result === 'test_data') {
        echo "<p style='color:green;'>✓ Cache is working</p>";
    } else {
        echo "<p style='color:red;'>✗ Cache test failed</p>";
    }
} else {
    echo "<p style='color:red;'>✗ Query Cache class not found</p>";
}

// 3. Clear any stale transients
echo "<h3>Clearing Stale Data:</h3>";
global $wpdb;
$cleared = $wpdb->query(
    "DELETE FROM {$wpdb->options}
    WHERE option_name LIKE '_transient_timeout_mld_%'
    AND option_value < UNIX_TIMESTAMP()"
);
echo "<p>Cleared $cleared stale transients</p>";

// 4. Schedule optimization cron
echo "<h3>Scheduling Optimization Tasks:</h3>";
if (class_exists('MLD_Database_Optimizer')) {
    MLD_Database_Optimizer::scheduleOptimization();
    echo "<p style='color:green;'>✓ Scheduled weekly optimization</p>";
}

echo "<hr>";
echo "<h2>Setup Complete!</h2>";
echo "<p>You can now:</p>";
echo "<ul>";
echo "<li><a href='" . admin_url('admin.php?page=mls_listings_display') . "'>Go to MLS Display Settings</a></li>";
echo "<li><a href='" . admin_url('admin.php?page=mld-performance') . "'>Go to Performance Dashboard</a></li>";
echo "</ul>";
?>