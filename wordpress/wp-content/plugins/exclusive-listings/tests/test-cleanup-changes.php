<?php
/**
 * Comprehensive tests for Exclusive Listings cleanup changes
 *
 * Run with: php test-cleanup-changes.php
 *
 * Tests the specific functions that were modified during the cleanup session.
 */

// Mock WordPress functions needed by the plugin
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str) {
        return trim(strip_tags($str));
    }
}

if (!function_exists('sanitize_email')) {
    function sanitize_email($email) {
        return filter_var($email, FILTER_SANITIZE_EMAIL);
    }
}

if (!function_exists('absint')) {
    function absint($val) {
        return abs(intval($val));
    }
}

if (!function_exists('esc_url_raw')) {
    function esc_url_raw($url) {
        return filter_var($url, FILTER_SANITIZE_URL);
    }
}

if (!function_exists('wp_kses_post')) {
    function wp_kses_post($text) {
        return strip_tags($text, '<p><a><strong><em><br><ul><ol><li>');
    }
}

if (!function_exists('current_time')) {
    function current_time($type) {
        if ($type === 'mysql') {
            return date('Y-m-d H:i:s');
        } elseif ($type === 'timestamp') {
            return time();
        }
        return time();
    }
}

if (!function_exists('get_option')) {
    function get_option($name, $default = false) {
        return $default;
    }
}

if (!function_exists('get_bloginfo')) {
    function get_bloginfo($what) {
        if ($what === 'version') {
            return '6.4.0';
        }
        return '';
    }
}

if (!function_exists('wp_date')) {
    function wp_date($format, $timestamp = null, $timezone = null) {
        return date($format, $timestamp ?? time());
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error($thing) {
        return $thing instanceof WP_Error;
    }
}

if (!function_exists('add_action')) {
    function add_action($hook, $callback, $priority = 10, $args = 1) {
        return true;
    }
}

if (!class_exists('WP_Error')) {
    class WP_Error {
        private $code;
        private $message;
        public function __construct($code = '', $message = '') {
            $this->code = $code;
            $this->message = $message;
        }
        public function get_error_message() {
            return $this->message;
        }
        public function get_error_code() {
            return $this->code;
        }
    }
}

// Mock wpdb
global $wpdb;
$wpdb = new stdClass();
$wpdb->prefix = 'wp_';

// Simulate WordPress environment
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__FILE__) . '/../../../');
}

// Test results tracking
$tests_passed = 0;
$tests_failed = 0;
$test_results = [];

function test_assert($condition, $test_name, $details = '') {
    global $tests_passed, $tests_failed, $test_results;
    if ($condition) {
        $tests_passed++;
        $test_results[] = "✓ PASS: $test_name";
        return true;
    } else {
        $tests_failed++;
        $test_results[] = "✗ FAIL: $test_name" . ($details ? " - $details" : "");
        return false;
    }
}

echo "=== Exclusive Listings Cleanup Tests ===\n\n";

// ============================================
// Test 1: class-el-validator.php helper methods
// ============================================
echo "--- Testing class-el-validator.php ---\n";

require_once dirname(__FILE__) . '/../includes/class-el-validator.php';

$reflection = new ReflectionClass('EL_Validator');

// Check if helper methods exist
$has_property_type_method = $reflection->hasMethod('is_valid_property_type');
test_assert($has_property_type_method, 'is_valid_property_type() method exists');

$has_property_sub_type_method = $reflection->hasMethod('is_valid_property_sub_type');
test_assert($has_property_sub_type_method, 'is_valid_property_sub_type() method exists');

$has_extract_status_method = $reflection->hasMethod('extract_status');
test_assert($has_extract_status_method, 'extract_status() helper method exists');

$has_validate_coords_method = $reflection->hasMethod('validate_coordinates');
test_assert($has_validate_coords_method, 'validate_coordinates() helper method exists');

// Test property type validation if method exists
// NOTE: PROPERTY_TYPES = ['Residential', 'Commercial', 'Land', 'Multi-Family', 'Rental']
// 'Single Family', 'Condo', 'Townhouse' are in PROPERTY_SUB_TYPES
if ($has_property_type_method) {
    $method = $reflection->getMethod('is_valid_property_type');

    // Valid form values (these are the actual PROPERTY_TYPES)
    test_assert($method->invoke(null, 'Residential'), 'Valid property type: Residential');
    test_assert($method->invoke(null, 'Commercial'), 'Valid property type: Commercial');
    test_assert($method->invoke(null, 'Land'), 'Valid property type: Land');

    // Invalid types
    test_assert(!$method->invoke(null, 'InvalidType'), 'Invalid property type rejected');
    test_assert(!$method->invoke(null, ''), 'Empty property type rejected');
}

// Test property SUB-type validation
if ($has_property_sub_type_method) {
    $sub_method = $reflection->getMethod('is_valid_property_sub_type');

    // Valid form values (PROPERTY_SUB_TYPES)
    test_assert($sub_method->invoke(null, 'Single Family'), 'Valid property sub-type: Single Family');
    test_assert($sub_method->invoke(null, 'Condo'), 'Valid property sub-type: Condo');
    test_assert($sub_method->invoke(null, 'Townhouse'), 'Valid property sub-type: Townhouse');

    // Valid MLS aliases
    test_assert($sub_method->invoke(null, 'Single Family Residence'), 'Valid MLS sub-type alias: Single Family Residence');
    test_assert($sub_method->invoke(null, 'Condominium'), 'Valid MLS sub-type alias: Condominium');
}

// Test extract_status if method exists
if ($has_extract_status_method) {
    $method = $reflection->getMethod('extract_status');

    // Test with standard_status
    $data1 = ['standard_status' => 'Active'];
    test_assert($method->invoke(null, $data1) === 'Active', 'extract_status gets standard_status');

    // Test with status fallback
    $data2 = ['status' => 'Pending'];
    test_assert($method->invoke(null, $data2) === 'Pending', 'extract_status falls back to status');

    // Test with neither
    $data3 = ['other' => 'value'];
    test_assert($method->invoke(null, $data3) === null, 'extract_status returns null when neither present');
}

// Test validate_coordinates if method exists
if ($has_validate_coords_method) {
    $method = $reflection->getMethod('validate_coordinates');

    // Valid coordinates
    $valid_coords = ['latitude' => '42.3601', 'longitude' => '-71.0589'];
    $errors = $method->invoke(null, $valid_coords);
    test_assert(empty($errors), 'Valid coordinates pass validation');

    // Invalid latitude (> 90)
    $invalid_lat = ['latitude' => '95.0', 'longitude' => '-71.0589'];
    $errors = $method->invoke(null, $invalid_lat);
    test_assert(!empty($errors), 'Invalid latitude (>90) rejected');

    // Invalid longitude (< -180)
    $invalid_lng = ['latitude' => '42.0', 'longitude' => '-200.0'];
    $errors = $method->invoke(null, $invalid_lng);
    test_assert(!empty($errors), 'Invalid longitude (<-180) rejected');
}

// Test sanitize method still works
$test_data = [
    'street_number' => '  123  ',
    'street_name' => '  Main Street  ',
    'city' => '  Boston  ',
    'list_price' => '$500,000',
    'latitude' => '42.3601',
    'longitude' => '-71.0589',
];
$sanitized = EL_Validator::sanitize($test_data);
test_assert($sanitized['street_number'] === '123', 'sanitize() trims street_number');
test_assert($sanitized['street_name'] === 'Main Street', 'sanitize() trims street_name');
test_assert($sanitized['city'] === 'Boston', 'sanitize() trims city');
test_assert($sanitized['list_price'] == 500000, 'sanitize() converts price string to number');

// Test validate_create still works
// NOTE: property_type must be 'Residential', 'Commercial', 'Land', etc.
// property_sub_type can be 'Single Family', 'Condo', 'Townhouse', etc.
$valid_listing = [
    'street_number' => '123',
    'street_name' => 'Main Street',
    'city' => 'Boston',
    'state_or_province' => 'MA',
    'postal_code' => '02101',
    'list_price' => 500000,
    'bedrooms_total' => 3,
    'bathrooms_total' => 2,
    'property_type' => 'Residential',  // Must be from PROPERTY_TYPES
    'property_sub_type' => 'Single Family',  // Must be from PROPERTY_SUB_TYPES
    'standard_status' => 'Active',
];
$result = EL_Validator::validate_create($valid_listing);
// validate_create returns array('valid' => bool, 'errors' => array())
$error_str = '';
if (!empty($result['errors'])) {
    foreach ($result['errors'] as $key => $value) {
        $error_str .= "$key: $value; ";
    }
}
test_assert($result['valid'] === true, 'validate_create() passes for valid listing', $error_str);

// Test validate_create rejects invalid data
$invalid_listing = [
    'street_number' => '', // Missing required
    'city' => 'Boston',
];
$invalid_result = EL_Validator::validate_create($invalid_listing);
test_assert($invalid_result['valid'] === false, 'validate_create() rejects listing with missing street_number');

echo "\n";

// ============================================
// Test 2: class-el-notifications.php helper methods
// ============================================
echo "--- Testing class-el-notifications.php ---\n";

require_once dirname(__FILE__) . '/../includes/class-el-notifications.php';

$notif_reflection = new ReflectionClass('EL_Notifications');

// Check if helper methods exist (private methods added during cleanup)
$has_build_address = $notif_reflection->hasMethod('build_address');
test_assert($has_build_address, 'build_address() helper method exists');

$has_format_price = $notif_reflection->hasMethod('format_price');
test_assert($has_format_price, 'format_price() helper method exists');

$has_build_specs = $notif_reflection->hasMethod('build_specs');
test_assert($has_build_specs, 'build_specs() helper method exists');

$has_debug_log = $notif_reflection->hasMethod('debug_log');
test_assert($has_debug_log, 'debug_log() helper method exists');

// These are instance methods, so just verify they exist
// In production, they're called via $this->method()

echo "\n";

// ============================================
// Test 3: class-el-bme-sync.php property type mapping
// ============================================
echo "--- Testing class-el-bme-sync.php ---\n";

require_once dirname(__FILE__) . '/../includes/class-el-bme-sync.php';

$sync_reflection = new ReflectionClass('EL_BME_Sync');

// Check if PROPERTY_SUB_TYPE_MAP constant exists
$has_map = $sync_reflection->hasConstant('PROPERTY_SUB_TYPE_MAP');
test_assert($has_map, 'PROPERTY_SUB_TYPE_MAP constant exists');

if ($has_map) {
    $map = $sync_reflection->getConstant('PROPERTY_SUB_TYPE_MAP');

    // Test mappings
    test_assert(isset($map['Single Family']) && $map['Single Family'] === 'Single Family Residence',
        'Single Family maps to Single Family Residence');
    test_assert(isset($map['Condo']) && $map['Condo'] === 'Condominium',
        'Condo maps to Condominium');
    test_assert(isset($map['Multi-Family']) && $map['Multi-Family'] === 'Multi Family',
        'Multi-Family maps to Multi Family');
}

// Check if map_property_sub_type method exists
$has_map_method = $sync_reflection->hasMethod('map_property_sub_type');
test_assert($has_map_method, 'map_property_sub_type() method exists');

if ($has_map_method) {
    $method = $sync_reflection->getMethod('map_property_sub_type');

    // Test mappings (static method)
    test_assert($method->invoke(null, 'Single Family') === 'Single Family Residence',
        'map_property_sub_type converts Single Family');
    test_assert($method->invoke(null, 'Condo') === 'Condominium',
        'map_property_sub_type converts Condo');

    // Test pass-through for already-MLS values
    test_assert($method->invoke(null, 'Condominium') === 'Condominium',
        'map_property_sub_type passes through MLS values');

    // Test default for empty
    $default = $method->invoke(null, '');
    test_assert($default === 'Single Family Residence',
        'map_property_sub_type returns default for empty');
}

echo "\n";

// ============================================
// Test 4: class-el-image-handler.php
// ============================================
echo "--- Testing class-el-image-handler.php ---\n";

require_once dirname(__FILE__) . '/../includes/class-el-image-handler.php';

$img_reflection = new ReflectionClass('EL_Image_Handler');

// Check constants exist
test_assert($img_reflection->hasConstant('MAX_DIMENSION'), 'MAX_DIMENSION constant exists');
test_assert($img_reflection->hasConstant('JPEG_QUALITY'), 'JPEG_QUALITY constant exists');
test_assert($img_reflection->hasConstant('WEBP_QUALITY'), 'WEBP_QUALITY constant exists');
test_assert($img_reflection->hasConstant('CONVERT_TO_WEBP'), 'CONVERT_TO_WEBP constant exists');

// Verify reasonable values
if ($img_reflection->hasConstant('MAX_DIMENSION')) {
    $max_dim = $img_reflection->getConstant('MAX_DIMENSION');
    test_assert($max_dim > 0 && $max_dim <= 4096, 'MAX_DIMENSION is reasonable (0 < x <= 4096)');
}

if ($img_reflection->hasConstant('JPEG_QUALITY')) {
    $quality = $img_reflection->getConstant('JPEG_QUALITY');
    test_assert($quality >= 60 && $quality <= 100, 'JPEG_QUALITY is reasonable (60-100)');
}

echo "\n";

// ============================================
// Test 5: class-el-database.php
// ============================================
echo "--- Testing class-el-database.php ---\n";

require_once dirname(__FILE__) . '/../includes/class-el-database.php';

$db_reflection = new ReflectionClass('EL_Database');

// Check required methods exist
test_assert($db_reflection->hasMethod('get_table'), 'get_table() method exists');
test_assert($db_reflection->hasMethod('get_bme_table'), 'get_bme_table() method exists');
test_assert($db_reflection->hasMethod('get_diagnostics'), 'get_diagnostics() method exists');

echo "\n";

// ============================================
// Test 6: class-el-activator.php
// ============================================
echo "--- Testing class-el-activator.php ---\n";

require_once dirname(__FILE__) . '/../includes/class-el-activator.php';

$act_reflection = new ReflectionClass('EL_Activator');

// Check if helper methods exist (just check existence, don't invoke - needs full WP)
$has_check_req = $act_reflection->hasMethod('check_requirements');
test_assert($has_check_req, 'check_requirements() helper method exists');

$has_act_error = $act_reflection->hasMethod('activation_error');
test_assert($has_act_error, 'activation_error() helper method exists');

// Check method signatures
if ($has_check_req) {
    $method = $act_reflection->getMethod('check_requirements');
    test_assert($method->isStatic(), 'check_requirements() is static');
    test_assert($method->isPrivate(), 'check_requirements() is private');
}

echo "\n";

// ============================================
// Test 7: Main plugin file - component initialization
// ============================================
echo "--- Testing exclusive-listings.php ---\n";

// Read the main plugin file and check for removed properties
$main_file = file_get_contents(dirname(__FILE__) . '/../exclusive-listings.php');

// Check that unused properties were removed
test_assert(strpos($main_file, 'private $validator;') === false,
    'Unused $validator property was removed');
test_assert(strpos($main_file, 'private $bme_sync;') === false,
    'Unused $bme_sync property was removed');
test_assert(strpos($main_file, 'private $image_handler;') === false,
    'Unused $image_handler property was removed');

// Check that used properties still exist
test_assert(strpos($main_file, 'private $database;') !== false,
    'Used $database property still exists');
test_assert(strpos($main_file, 'private $rest_api;') !== false,
    'Used $rest_api property still exists');
test_assert(strpos($main_file, 'private $mobile_rest_api;') !== false,
    'Used $mobile_rest_api property still exists');

// Check register_rest_routes was simplified (no null checks)
test_assert(strpos($main_file, 'if ($this->rest_api)') === false,
    'Redundant null check for $rest_api removed');
test_assert(strpos($main_file, 'if ($this->mobile_rest_api)') === false,
    'Redundant null check for $mobile_rest_api removed');

echo "\n";

// ============================================
// Test 8: class-el-admin.php timezone fix
// ============================================
echo "--- Testing class-el-admin.php (timezone fix) ---\n";

$admin_file = file_get_contents(dirname(__FILE__) . '/../includes/class-el-admin.php');

// Check that time() was replaced with current_time('timestamp')
$current_time_occurrences = substr_count($admin_file, "current_time('timestamp')");

// Should have current_time in the media_key generation
test_assert(strpos($admin_file, "current_time('timestamp')") !== false,
    'Uses current_time(\'timestamp\') for timezone consistency');

// Should not have bare time() being used for timestamps (except in non-timestamp contexts)
// Looking for patterns like time() used where current_time should be
$problematic_patterns = [
    "md5(" . '$listing_id . \'_\' . $url . \'_\' . time()' // Old pattern
];
$has_old_pattern = false;
foreach ($problematic_patterns as $pattern) {
    if (strpos($admin_file, $pattern) !== false) {
        $has_old_pattern = true;
        break;
    }
}
test_assert(!$has_old_pattern, 'No old time() pattern in media_key generation');

echo "\n";

// ============================================
// Test 9: Verify null coalescing was used correctly
// ============================================
echo "--- Testing null coalescing patterns ---\n";

// Check class-el-admin.php uses ?? operator
test_assert(strpos($admin_file, '??') !== false,
    'class-el-admin.php uses null coalescing operator');

// Check class-el-bme-sync.php uses ?? operator
$bme_sync_file = file_get_contents(dirname(__FILE__) . '/../includes/class-el-bme-sync.php');
test_assert(strpos($bme_sync_file, '??') !== false,
    'class-el-bme-sync.php uses null coalescing operator');

// Check class-el-mobile-rest-api.php uses ?? operator
$mobile_api_file = file_get_contents(dirname(__FILE__) . '/../includes/class-el-mobile-rest-api.php');
test_assert(strpos($mobile_api_file, '??') !== false,
    'class-el-mobile-rest-api.php uses null coalescing operator');

echo "\n";

// ============================================
// Summary
// ============================================
echo "=== TEST SUMMARY ===\n";
echo "Passed: $tests_passed\n";
echo "Failed: $tests_failed\n";
echo "Total:  " . ($tests_passed + $tests_failed) . "\n\n";

if ($tests_failed > 0) {
    echo "Failed tests:\n";
    foreach ($test_results as $result) {
        if (strpos($result, 'FAIL') !== false) {
            echo "  $result\n";
        }
    }
    echo "\n";
}

// Print all results
echo "All test results:\n";
foreach ($test_results as $result) {
    echo "  $result\n";
}

exit($tests_failed > 0 ? 1 : 0);
