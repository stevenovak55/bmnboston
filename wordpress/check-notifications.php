<?php
/**
 * Check notification system status
 */

if (!defined('ABSPATH')) {
    echo "This script must be run via WP-CLI\n";
    exit(1);
}

global $wpdb;

echo "=== User Preferences (likes) ===\n";
$prefs = $wpdb->get_results("SELECT id, user_id, liked_listing_ids FROM {$wpdb->prefix}mld_user_preferences WHERE liked_listing_ids IS NOT NULL AND liked_listing_ids != '' LIMIT 5");
foreach ($prefs as $pref) {
    $ids = maybe_unserialize($pref->liked_listing_ids);
    if (is_array($ids)) {
        echo "User {$pref->user_id}: " . count($ids) . " favorites\n";
        echo "  Sample IDs: " . implode(', ', array_slice($ids, 0, 5)) . "\n";
    } else {
        echo "User {$pref->user_id}: " . substr($pref->liked_listing_ids, 0, 100) . "\n";
    }
}

echo "\n=== Check for price reduced properties ===\n";
$reduced = $wpdb->get_results("
    SELECT listing_id, list_price, original_list_price, city, street_number, street_name
    FROM {$wpdb->prefix}bme_listing_summary
    WHERE original_list_price > 0
    AND list_price < original_list_price
    AND standard_status = 'Active'
    ORDER BY (original_list_price - list_price) DESC
    LIMIT 10
");
echo "Total price reduced active listings: " . count($reduced) . "\n";
foreach ($reduced as $p) {
    $reduction = $p->original_list_price - $p->list_price;
    echo "  {$p->listing_id}: {$p->street_number} {$p->street_name}, {$p->city} - ";
    echo "\${" . number_format($p->original_list_price) . "} -> \${" . number_format($p->list_price) . "} ";
    echo "(-\$" . number_format($reduction) . ")\n";
}

echo "\n=== Check property change detector logic ===\n";
if (class_exists('MLD_Property_Change_Detector')) {
    // Check what properties it would monitor
    $user_prefs = $wpdb->get_results("
        SELECT user_id, liked_listing_ids
        FROM {$wpdb->prefix}mld_user_preferences
        WHERE liked_listing_ids IS NOT NULL AND liked_listing_ids != ''
    ");

    $all_listing_ids = [];
    foreach ($user_prefs as $pref) {
        $ids = maybe_unserialize($pref->liked_listing_ids);
        if (is_array($ids)) {
            $all_listing_ids = array_merge($all_listing_ids, $ids);
        }
    }
    $unique_ids = array_unique($all_listing_ids);
    echo "Properties to monitor (from favorites): " . count($unique_ids) . "\n";

    // Check baseline table
    $baseline_table = $wpdb->prefix . 'mld_property_baselines';
    $has_baseline = $wpdb->get_var("SHOW TABLES LIKE '{$baseline_table}'") === $baseline_table;
    if ($has_baseline) {
        $baseline_count = $wpdb->get_var("SELECT COUNT(*) FROM {$baseline_table}");
        echo "Baseline records: {$baseline_count}\n";
    } else {
        echo "Baseline table does not exist!\n";
    }
} else {
    echo "MLD_Property_Change_Detector class not found\n";
}

echo "\n=== Recent saved search notification results ===\n";
// Manually process a saved search notification to test
if (class_exists('MLD_Saved_Search_Notifications')) {
    echo "Testing instant notifications...\n";
    $result = MLD_Saved_Search_Notifications::send_notifications('instant');
    print_r($result);
}
