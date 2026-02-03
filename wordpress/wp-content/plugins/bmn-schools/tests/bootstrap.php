<?php
/**
 * PHPUnit Bootstrap File for BMN Schools Plugin
 *
 * Sets up the testing environment with Brain Monkey for WordPress function mocking.
 *
 * @package BMN_Schools\Tests
 * @since 0.6.38
 */

// Ensure we're not running in production
if (php_sapi_name() !== 'cli') {
    die('This script can only be run from the command line.');
}

// Load Composer autoloader (from MLD plugin's vendor since it has Brain Monkey)
$mld_autoloader = dirname(dirname(__DIR__)) . '/mls-listings-display/vendor/autoload.php';
if (file_exists($mld_autoloader)) {
    require_once $mld_autoloader;
}

// Initialize Brain Monkey if available
if (class_exists('\Brain\Monkey\setUp')) {
    \Brain\Monkey\setUp();
}

// Define WordPress constants if not already defined
if (!defined('ABSPATH')) {
    define('ABSPATH', '/var/www/html/');
}

if (!defined('WPINC')) {
    define('WPINC', 'wp-includes');
}

if (!defined('WP_DEBUG')) {
    define('WP_DEBUG', true);
}

if (!defined('WP_DEBUG_LOG')) {
    define('WP_DEBUG_LOG', true);
}

// Plugin-specific constants
if (!defined('BMN_SCHOOLS_PLUGIN_FILE')) {
    define('BMN_SCHOOLS_PLUGIN_FILE', dirname(__DIR__) . '/bmn-schools.php');
}

if (!defined('BMN_SCHOOLS_PLUGIN_PATH')) {
    define('BMN_SCHOOLS_PLUGIN_PATH', dirname(__DIR__) . '/');
}

if (!defined('BMN_SCHOOLS_PLUGIN_URL')) {
    define('BMN_SCHOOLS_PLUGIN_URL', 'http://localhost:9002/wp-content/plugins/bmn-schools/');
}

if (!defined('BMN_SCHOOLS_VERSION')) {
    define('BMN_SCHOOLS_VERSION', '0.6.39');
}

/**
 * WP_Error stub class for testing
 */
if (!class_exists('WP_Error')) {
    class WP_Error {
        private $code;
        private $message;
        private $data;
        private $errors = [];
        private $error_data = [];

        public function __construct($code = '', $message = '', $data = '') {
            if (empty($code)) {
                return;
            }
            $this->code = $code;
            $this->message = $message;
            $this->data = $data;
            $this->errors[$code][] = $message;
            if (!empty($data)) {
                $this->error_data[$code] = $data;
            }
        }

        public function get_error_codes() {
            return array_keys($this->errors);
        }

        public function get_error_code() {
            $codes = $this->get_error_codes();
            return !empty($codes) ? $codes[0] : '';
        }

        public function get_error_messages($code = '') {
            if (empty($code)) {
                $all_messages = [];
                foreach ($this->errors as $messages) {
                    $all_messages = array_merge($all_messages, $messages);
                }
                return $all_messages;
            }
            return isset($this->errors[$code]) ? $this->errors[$code] : [];
        }

        public function get_error_message($code = '') {
            if (empty($code)) {
                $code = $this->get_error_code();
            }
            $messages = $this->get_error_messages($code);
            return !empty($messages) ? $messages[0] : '';
        }

        public function has_errors() {
            return !empty($this->errors);
        }
    }
}

/**
 * WordPress function stubs
 */

// Sanitization functions
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

if (!function_exists('sanitize_key')) {
    function sanitize_key($key) {
        return preg_replace('/[^a-z0-9_\-]/', '', strtolower($key));
    }
}

// Escaping functions
if (!function_exists('esc_html')) {
    function esc_html($text) {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr($text) {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_url')) {
    function esc_url($url) {
        return filter_var($url, FILTER_SANITIZE_URL);
    }
}

if (!function_exists('esc_sql')) {
    function esc_sql($data) {
        return addslashes($data);
    }
}

// Cache functions (stub implementations)
if (!function_exists('wp_cache_get')) {
    function wp_cache_get($key, $group = '', $force = false, &$found = null) {
        global $_wp_cache_test_data;
        $found = isset($_wp_cache_test_data[$group][$key]);
        return $found ? $_wp_cache_test_data[$group][$key] : false;
    }
}

if (!function_exists('wp_cache_set')) {
    function wp_cache_set($key, $data, $group = '', $expire = 0) {
        global $_wp_cache_test_data;
        if (!isset($_wp_cache_test_data)) {
            $_wp_cache_test_data = [];
        }
        if (!isset($_wp_cache_test_data[$group])) {
            $_wp_cache_test_data[$group] = [];
        }
        $_wp_cache_test_data[$group][$key] = $data;
        return true;
    }
}

if (!function_exists('wp_cache_delete')) {
    function wp_cache_delete($key, $group = '') {
        global $_wp_cache_test_data;
        unset($_wp_cache_test_data[$group][$key]);
        return true;
    }
}

// Transients (stub implementations)
if (!function_exists('get_transient')) {
    function get_transient($transient) {
        global $_wp_transients_test_data;
        return isset($_wp_transients_test_data[$transient]) ? $_wp_transients_test_data[$transient] : false;
    }
}

if (!function_exists('set_transient')) {
    function set_transient($transient, $value, $expiration = 0) {
        global $_wp_transients_test_data;
        if (!isset($_wp_transients_test_data)) {
            $_wp_transients_test_data = [];
        }
        $_wp_transients_test_data[$transient] = $value;
        return true;
    }
}

if (!function_exists('delete_transient')) {
    function delete_transient($transient) {
        global $_wp_transients_test_data;
        unset($_wp_transients_test_data[$transient]);
        return true;
    }
}

// Options (stub implementations)
if (!function_exists('get_option')) {
    function get_option($option, $default = false) {
        global $_wp_options_test_data;
        return isset($_wp_options_test_data[$option]) ? $_wp_options_test_data[$option] : $default;
    }
}

if (!function_exists('update_option')) {
    function update_option($option, $value, $autoload = null) {
        global $_wp_options_test_data;
        if (!isset($_wp_options_test_data)) {
            $_wp_options_test_data = [];
        }
        $_wp_options_test_data[$option] = $value;
        return true;
    }
}

// Plugin functions
if (!function_exists('plugin_dir_path')) {
    function plugin_dir_path($file) {
        return trailingslashit(dirname($file));
    }
}

if (!function_exists('plugin_dir_url')) {
    function plugin_dir_url($file) {
        return BMN_SCHOOLS_PLUGIN_URL;
    }
}

// Path utilities
if (!function_exists('trailingslashit')) {
    function trailingslashit($string) {
        return rtrim($string, '/\\') . '/';
    }
}

if (!function_exists('untrailingslashit')) {
    function untrailingslashit($string) {
        return rtrim($string, '/\\');
    }
}

// Error checking
if (!function_exists('is_wp_error')) {
    function is_wp_error($thing) {
        return $thing instanceof WP_Error;
    }
}

// Internationalization
if (!function_exists('__')) {
    function __($text, $domain = 'default') {
        return $text;
    }
}

if (!function_exists('_e')) {
    function _e($text, $domain = 'default') {
        echo $text;
    }
}

if (!function_exists('esc_html__')) {
    function esc_html__($text, $domain = 'default') {
        return esc_html(__($text, $domain));
    }
}

// Misc WordPress functions
if (!function_exists('absint')) {
    function absint($value) {
        return abs((int) $value);
    }
}

if (!function_exists('wp_parse_args')) {
    function wp_parse_args($args, $defaults = []) {
        if (is_object($args)) {
            $args = get_object_vars($args);
        } elseif (is_string($args)) {
            parse_str($args, $args);
        }
        return array_merge($defaults, $args);
    }
}

if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, $options = 0, $depth = 512) {
        return json_encode($data, $options, $depth);
    }
}

// Database output type constants
if (!defined('OBJECT')) {
    define('OBJECT', 'OBJECT');
}

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

if (!defined('ARRAY_N')) {
    define('ARRAY_N', 'ARRAY_N');
}

// Database table prefix
if (!isset($GLOBALS['table_prefix'])) {
    $GLOBALS['table_prefix'] = 'wp_';
}

/**
 * Helper function to reset test data between tests
 */
function bmn_schools_reset_test_data() {
    global $_wp_cache_test_data, $_wp_transients_test_data, $_wp_options_test_data;
    global $_bmn_mock_wpdb_results;

    $_wp_cache_test_data = [];
    $_wp_transients_test_data = [];
    $_wp_options_test_data = [];
    $_bmn_mock_wpdb_results = [];

    // Reset Brain Monkey if available
    if (class_exists('\Brain\Monkey\tearDown')) {
        \Brain\Monkey\tearDown();
        \Brain\Monkey\setUp();
    }
}

/**
 * Mock wpdb class for unit testing
 */
class MockWPDB {
    public $prefix = 'wp_';
    public $last_error = '';
    public $num_rows = 0;
    public $insert_id = 0;

    private $mock_results = [];

    public function set_mock_result($method, $sql_pattern, $result) {
        $this->mock_results[$method][$sql_pattern] = $result;
    }

    public function get_var($query, $x = 0, $y = 0) {
        global $_bmn_mock_wpdb_results;

        // Check for mock results
        if (isset($_bmn_mock_wpdb_results['get_var'])) {
            foreach ($_bmn_mock_wpdb_results['get_var'] as $pattern => $result) {
                if (strpos($query, $pattern) !== false) {
                    return $result;
                }
            }
        }

        return null;
    }

    public function get_row($query, $output = OBJECT, $y = 0) {
        global $_bmn_mock_wpdb_results;

        if (isset($_bmn_mock_wpdb_results['get_row'])) {
            foreach ($_bmn_mock_wpdb_results['get_row'] as $pattern => $result) {
                if (strpos($query, $pattern) !== false) {
                    return $result;
                }
            }
        }

        return null;
    }

    public function get_results($query, $output = OBJECT) {
        global $_bmn_mock_wpdb_results;

        if (isset($_bmn_mock_wpdb_results['get_results'])) {
            foreach ($_bmn_mock_wpdb_results['get_results'] as $pattern => $result) {
                if (strpos($query, $pattern) !== false) {
                    return $result;
                }
            }
        }

        return [];
    }

    public function prepare($query, ...$args) {
        // Simple prepare implementation
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
 * Helper to set mock wpdb results
 */
function bmn_set_mock_wpdb_result($method, $sql_pattern, $result) {
    global $_bmn_mock_wpdb_results;
    if (!isset($_bmn_mock_wpdb_results)) {
        $_bmn_mock_wpdb_results = [];
    }
    if (!isset($_bmn_mock_wpdb_results[$method])) {
        $_bmn_mock_wpdb_results[$method] = [];
    }
    $_bmn_mock_wpdb_results[$method][$sql_pattern] = $result;
}

// Register shutdown to tear down Brain Monkey if available
register_shutdown_function(function() {
    if (class_exists('\Brain\Monkey\tearDown')) {
        \Brain\Monkey\tearDown();
    }
});
