<?php
/**
 * PHPUnit Bootstrap File for SN Appointment Booking Plugin
 *
 * Sets up the testing environment with WordPress function stubs.
 *
 * @package SN_Appointment_Booking\Tests
 * @since 1.9.4
 */

// Ensure we're not running in production
if (php_sapi_name() !== 'cli') {
    die('This script can only be run from the command line.');
}

// Load Composer autoloader from MLD plugin if available
$mld_autoloader = dirname(dirname(__DIR__)) . '/mls-listings-display/vendor/autoload.php';
if (file_exists($mld_autoloader)) {
    require_once $mld_autoloader;
}

// Initialize Brain Monkey if available
if (class_exists('\Brain\Monkey\setUp')) {
    \Brain\Monkey\setUp();
}

// Define WordPress constants
if (!defined('ABSPATH')) {
    define('ABSPATH', '/var/www/html/');
}

if (!defined('WPINC')) {
    define('WPINC', 'wp-includes');
}

if (!defined('WP_DEBUG')) {
    define('WP_DEBUG', true);
}

// Plugin-specific constants
if (!defined('SNAB_PLUGIN_FILE')) {
    define('SNAB_PLUGIN_FILE', dirname(__DIR__) . '/sn-appointment-booking.php');
}

if (!defined('SNAB_PLUGIN_PATH')) {
    define('SNAB_PLUGIN_PATH', dirname(__DIR__) . '/');
}

if (!defined('SNAB_PLUGIN_URL')) {
    define('SNAB_PLUGIN_URL', 'http://localhost:9002/wp-content/plugins/sn-appointment-booking/');
}

if (!defined('SNAB_VERSION')) {
    define('SNAB_VERSION', '1.9.5');
}

/**
 * WP_Error stub class
 */
if (!class_exists('WP_Error')) {
    class WP_Error {
        private $errors = [];
        private $error_data = [];

        public function __construct($code = '', $message = '', $data = '') {
            if (!empty($code)) {
                $this->errors[$code][] = $message;
                if (!empty($data)) {
                    $this->error_data[$code] = $data;
                }
            }
        }

        public function get_error_code() {
            $codes = array_keys($this->errors);
            return !empty($codes) ? $codes[0] : '';
        }

        public function get_error_message($code = '') {
            if (empty($code)) {
                $code = $this->get_error_code();
            }
            $messages = isset($this->errors[$code]) ? $this->errors[$code] : [];
            return !empty($messages) ? $messages[0] : '';
        }

        public function has_errors() {
            return !empty($this->errors);
        }
    }
}

/**
 * WP_REST_Response stub class
 */
if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response {
        public $data;
        public $status;
        public $headers = [];

        public function __construct($data = null, $status = 200) {
            $this->data = $data;
            $this->status = $status;
        }

        public function get_data() {
            return $this->data;
        }

        public function get_status() {
            return $this->status;
        }

        public function header($key, $value) {
            $this->headers[$key] = $value;
        }

        public function get_headers() {
            return $this->headers;
        }
    }
}

/**
 * WordPress function stubs
 */

// Sanitization
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
    function absint($value) {
        return abs((int) $value);
    }
}

// Escaping
if (!function_exists('esc_html')) {
    function esc_html($text) {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_sql')) {
    function esc_sql($data) {
        return addslashes($data);
    }
}

// Error checking
if (!function_exists('is_wp_error')) {
    function is_wp_error($thing) {
        return $thing instanceof WP_Error;
    }
}

// Cache stubs
if (!function_exists('wp_cache_get')) {
    function wp_cache_get($key, $group = '') {
        global $_snab_cache_test_data;
        return isset($_snab_cache_test_data[$group][$key]) ? $_snab_cache_test_data[$group][$key] : false;
    }
}

if (!function_exists('wp_cache_set')) {
    function wp_cache_set($key, $data, $group = '', $expire = 0) {
        global $_snab_cache_test_data;
        $_snab_cache_test_data[$group][$key] = $data;
        return true;
    }
}

// Options stubs
if (!function_exists('get_option')) {
    function get_option($option, $default = false) {
        global $_snab_options_test_data;
        return isset($_snab_options_test_data[$option]) ? $_snab_options_test_data[$option] : $default;
    }
}

if (!function_exists('update_option')) {
    function update_option($option, $value) {
        global $_snab_options_test_data;
        $_snab_options_test_data[$option] = $value;
        return true;
    }
}

// User stubs
if (!function_exists('get_current_user_id')) {
    function get_current_user_id() {
        global $_snab_test_current_user_id;
        return isset($_snab_test_current_user_id) ? $_snab_test_current_user_id : 0;
    }
}

if (!function_exists('wp_set_current_user')) {
    function wp_set_current_user($user_id) {
        global $_snab_test_current_user_id;
        $_snab_test_current_user_id = $user_id;
    }
}

if (!function_exists('current_user_can')) {
    function current_user_can($capability) {
        global $_snab_test_user_capabilities;
        return isset($_snab_test_user_capabilities[$capability]) ? $_snab_test_user_capabilities[$capability] : false;
    }
}

// Time functions - CRITICAL: Use WordPress timezone (Pitfall #10)
if (!function_exists('current_time')) {
    function current_time($type, $gmt = 0) {
        // Simulate America/New_York timezone
        $tz = new DateTimeZone('America/New_York');
        $now = new DateTime('now', $tz);

        if ($type === 'mysql') {
            return $now->format('Y-m-d H:i:s');
        } elseif ($type === 'timestamp') {
            return $now->getTimestamp();
        }
        return $now->format($type);
    }
}

if (!function_exists('wp_timezone')) {
    function wp_timezone() {
        return new DateTimeZone('America/New_York');
    }
}

// i18n
if (!function_exists('__')) {
    function __($text, $domain = 'default') {
        return $text;
    }
}

// Path utilities
if (!function_exists('trailingslashit')) {
    function trailingslashit($string) {
        return rtrim($string, '/\\') . '/';
    }
}

if (!function_exists('plugin_dir_path')) {
    function plugin_dir_path($file) {
        return trailingslashit(dirname($file));
    }
}

// Database constants
if (!defined('OBJECT')) {
    define('OBJECT', 'OBJECT');
}

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

// Table prefix
if (!isset($GLOBALS['table_prefix'])) {
    $GLOBALS['table_prefix'] = 'wp_';
}

/**
 * Mock wpdb class
 */
class MockWPDB {
    public $prefix = 'wp_';
    public $last_error = '';
    public $insert_id = 0;

    public function get_var($query) {
        global $_snab_mock_wpdb_results;
        if (isset($_snab_mock_wpdb_results['get_var'])) {
            foreach ($_snab_mock_wpdb_results['get_var'] as $pattern => $result) {
                if (strpos($query, $pattern) !== false) {
                    return $result;
                }
            }
        }
        return null;
    }

    public function get_row($query, $output = OBJECT) {
        global $_snab_mock_wpdb_results;
        if (isset($_snab_mock_wpdb_results['get_row'])) {
            foreach ($_snab_mock_wpdb_results['get_row'] as $pattern => $result) {
                if (strpos($query, $pattern) !== false) {
                    return $result;
                }
            }
        }
        return null;
    }

    public function get_results($query, $output = OBJECT) {
        global $_snab_mock_wpdb_results;
        if (isset($_snab_mock_wpdb_results['get_results'])) {
            foreach ($_snab_mock_wpdb_results['get_results'] as $pattern => $result) {
                if (strpos($query, $pattern) !== false) {
                    return $result;
                }
            }
        }
        return [];
    }

    public function prepare($query, ...$args) {
        $prepared = $query;
        foreach ($args as $arg) {
            if (is_array($arg)) {
                $arg = $arg[0] ?? '';
            }
            $prepared = preg_replace('/%[sd]/', is_numeric($arg) ? $arg : "'" . addslashes($arg) . "'", $prepared, 1);
        }
        return $prepared;
    }

    public function query($query) {
        return true;
    }

    public function insert($table, $data, $format = null) {
        $this->insert_id = rand(1, 10000);
        return 1;
    }

    public function update($table, $data, $where, $format = null, $where_format = null) {
        return 1;
    }

    public function delete($table, $where, $where_format = null) {
        return 1;
    }
}

/**
 * Reset test data
 */
function snab_reset_test_data() {
    global $_snab_cache_test_data, $_snab_options_test_data;
    global $_snab_test_current_user_id, $_snab_test_user_capabilities;
    global $_snab_mock_wpdb_results;

    $_snab_cache_test_data = [];
    $_snab_options_test_data = [];
    $_snab_test_current_user_id = 0;
    $_snab_test_user_capabilities = [];
    $_snab_mock_wpdb_results = [];
}

/**
 * Set mock wpdb result
 */
function snab_set_mock_wpdb_result($method, $sql_pattern, $result) {
    global $_snab_mock_wpdb_results;
    if (!isset($_snab_mock_wpdb_results[$method])) {
        $_snab_mock_wpdb_results[$method] = [];
    }
    $_snab_mock_wpdb_results[$method][$sql_pattern] = $result;
}

/**
 * Set test user
 */
function snab_set_test_user($user_id, $capabilities = []) {
    global $_snab_test_current_user_id, $_snab_test_user_capabilities;
    $_snab_test_current_user_id = $user_id;
    $_snab_test_user_capabilities = $capabilities;
}

// Shutdown cleanup
register_shutdown_function(function() {
    if (class_exists('\Brain\Monkey\tearDown')) {
        \Brain\Monkey\tearDown();
    }
});
