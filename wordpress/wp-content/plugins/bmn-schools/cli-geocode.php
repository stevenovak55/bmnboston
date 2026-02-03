<?php
/**
 * CLI script to batch geocode schools without coordinates.
 *
 * Usage: php cli-geocode.php [limit]
 *
 * @package BMN_Schools
 * @since 0.5.1
 */

// Check if running from CLI
if (php_sapi_name() !== 'cli') {
    die('This script must be run from the command line.');
}

// Load WordPress
$wp_load = realpath(dirname(__FILE__) . '/../../..') . '/wp-load.php';
if (!file_exists($wp_load)) {
    // Try alternate path
    $wp_load = '/www/stevenovakcom_662/public/wp-load.php';
}
if (!file_exists($wp_load)) {
    die("Error: Cannot find wp-load.php at {$wp_load}\n");
}

require_once $wp_load;

// Check if plugin is loaded
if (!class_exists('BMN_Schools_Geocoder')) {
    require_once dirname(__FILE__) . '/includes/class-geocoder.php';
}

// Get limit from argument
$limit = isset($argv[1]) ? intval($argv[1]) : 100;

echo "BMN Schools Geocoder\n";
echo "====================\n\n";

// Get pending count
$pending = BMN_Schools_Geocoder::get_pending_count();
echo "Schools needing geocoding: {$pending}\n";
echo "Processing limit: {$limit}\n\n";

if ($pending === 0) {
    echo "No schools need geocoding. All done!\n";
    exit(0);
}

echo "Starting geocoding...\n";
echo "Note: Nominatim rate limit is 1 request/second\n\n";

$start_time = microtime(true);

// Run geocoding with progress callback
$stats = BMN_Schools_Geocoder::geocode_schools($limit, function($current, $total, $name) {
    echo "  [{$current}/{$total}] {$name}\n";
});

$duration = round(microtime(true) - $start_time, 2);

echo "\n";
echo "Results:\n";
echo "--------\n";
echo "Total processed: {$stats['total']}\n";
echo "Success: {$stats['success']}\n";
echo "Failed: {$stats['failed']}\n";
echo "Skipped: {$stats['skipped']}\n";
echo "Duration: {$duration} seconds\n";

$remaining = BMN_Schools_Geocoder::get_pending_count();
echo "\nRemaining: {$remaining} schools\n";

if ($remaining > 0) {
    echo "\nRun again to geocode more schools:\n";
    echo "  php cli-geocode.php {$limit}\n";
}
