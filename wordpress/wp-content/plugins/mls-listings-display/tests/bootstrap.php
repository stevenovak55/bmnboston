<?php
/**
 * PHPUnit Bootstrap File for MLS Listings Display Plugin
 *
 * Sets up the testing environment with Brain Monkey for WordPress function mocking.
 *
 * @package MLSDisplay\Tests
 * @since 6.10.6
 */

// Ensure we're not running in production
if (php_sapi_name() !== 'cli') {
    die('This script can only be run from the command line.');
}

// Load Composer autoloader
$autoloader = dirname(__DIR__) . '/vendor/autoload.php';
if (!file_exists($autoloader)) {
    die(
        "Composer autoloader not found. Please run:\n" .
        "cd " . dirname(__DIR__) . " && composer install\n"
    );
}
require_once $autoloader;

// Initialize Brain Monkey
\Brain\Monkey\setUp();

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
if (!defined('MLD_PLUGIN_FILE')) {
    define('MLD_PLUGIN_FILE', dirname(__DIR__) . '/mls-listings-display.php');
}

if (!defined('MLD_PLUGIN_PATH')) {
    define('MLD_PLUGIN_PATH', dirname(__DIR__) . '/');
}

if (!defined('MLD_PLUGIN_URL')) {
    define('MLD_PLUGIN_URL', 'http://localhost:9002/wp-content/plugins/mls-listings-display/');
}

if (!defined('MLD_VERSION')) {
    define('MLD_VERSION', '6.10.6');
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

        public function get_error_data($code = '') {
            if (empty($code)) {
                $code = $this->get_error_code();
            }
            return isset($this->error_data[$code]) ? $this->error_data[$code] : null;
        }

        public function has_errors() {
            return !empty($this->errors);
        }

        public function add($code, $message, $data = '') {
            $this->errors[$code][] = $message;
            if (!empty($data)) {
                $this->error_data[$code] = $data;
            }
        }

        public function add_data($data, $code = '') {
            if (empty($code)) {
                $code = $this->get_error_code();
            }
            $this->error_data[$code] = $data;
        }

        public function remove($code) {
            unset($this->errors[$code]);
            unset($this->error_data[$code]);
        }
    }
}

/**
 * WordPress function stubs
 * These provide basic implementations for testing without WordPress loaded
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

if (!function_exists('sanitize_title')) {
    function sanitize_title($title) {
        return strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '-', $title));
    }
}

if (!function_exists('sanitize_key')) {
    function sanitize_key($key) {
        return preg_replace('/[^a-z0-9_\-]/', '', strtolower($key));
    }
}

if (!function_exists('sanitize_file_name')) {
    function sanitize_file_name($filename) {
        return preg_replace('/[^a-zA-Z0-9._-]/', '', $filename);
    }
}

if (!function_exists('wp_kses_post')) {
    function wp_kses_post($content) {
        return $content; // Simplified for testing
    }
}

if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags($string) {
        return strip_tags($string);
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
        global $wpdb;
        if (isset($wpdb) && method_exists($wpdb, '_real_escape')) {
            return $wpdb->_real_escape($data);
        }
        return addslashes($data);
    }
}

if (!function_exists('esc_js')) {
    function esc_js($text) {
        return addslashes($text);
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

if (!function_exists('wp_cache_flush')) {
    function wp_cache_flush() {
        global $_wp_cache_test_data;
        $_wp_cache_test_data = [];
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

// Options (stub implementations using test storage)
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

if (!function_exists('delete_option')) {
    function delete_option($option) {
        global $_wp_options_test_data;
        unset($_wp_options_test_data[$option]);
        return true;
    }
}

if (!function_exists('add_option')) {
    function add_option($option, $value = '', $deprecated = '', $autoload = 'yes') {
        global $_wp_options_test_data;
        if (!isset($_wp_options_test_data)) {
            $_wp_options_test_data = [];
        }
        if (!isset($_wp_options_test_data[$option])) {
            $_wp_options_test_data[$option] = $value;
            return true;
        }
        return false;
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
        return MLD_PLUGIN_URL;
    }
}

if (!function_exists('plugins_url')) {
    function plugins_url($path = '', $plugin = '') {
        return MLD_PLUGIN_URL . ltrim($path, '/');
    }
}

if (!function_exists('plugin_basename')) {
    function plugin_basename($file) {
        return basename(dirname($file)) . '/' . basename($file);
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

// Nonce functions (stub implementations)
if (!function_exists('wp_create_nonce')) {
    function wp_create_nonce($action = -1) {
        return md5($action . 'test_nonce_salt');
    }
}

if (!function_exists('wp_verify_nonce')) {
    function wp_verify_nonce($nonce, $action = -1) {
        return $nonce === wp_create_nonce($action) ? 1 : false;
    }
}

if (!function_exists('check_ajax_referer')) {
    function check_ajax_referer($action = -1, $query_arg = false, $die = true) {
        $nonce = '';
        if ($query_arg && isset($_REQUEST[$query_arg])) {
            $nonce = $_REQUEST[$query_arg];
        } elseif (isset($_REQUEST['_ajax_nonce'])) {
            $nonce = $_REQUEST['_ajax_nonce'];
        } elseif (isset($_REQUEST['_wpnonce'])) {
            $nonce = $_REQUEST['_wpnonce'];
        }

        $result = wp_verify_nonce($nonce, $action);
        if (!$result && $die) {
            if (defined('DOING_AJAX') && DOING_AJAX) {
                wp_die(-1, 403);
            } else {
                die('-1');
            }
        }
        return $result;
    }
}

// User functions (stub implementations)
if (!function_exists('get_current_user_id')) {
    function get_current_user_id() {
        global $_wp_test_current_user_id;
        return isset($_wp_test_current_user_id) ? $_wp_test_current_user_id : 0;
    }
}

if (!function_exists('current_user_can')) {
    function current_user_can($capability) {
        global $_wp_test_user_capabilities;
        return isset($_wp_test_user_capabilities[$capability]) ? $_wp_test_user_capabilities[$capability] : false;
    }
}

if (!function_exists('is_user_logged_in')) {
    function is_user_logged_in() {
        return get_current_user_id() > 0;
    }
}

// AJAX/REST functions
if (!function_exists('wp_send_json')) {
    function wp_send_json($response, $status_code = null) {
        global $_wp_test_json_response;
        $_wp_test_json_response = $response;
        // Don't actually exit in tests
    }
}

if (!function_exists('wp_send_json_success')) {
    function wp_send_json_success($data = null, $status_code = null) {
        wp_send_json(['success' => true, 'data' => $data], $status_code);
    }
}

if (!function_exists('wp_send_json_error')) {
    function wp_send_json_error($data = null, $status_code = null) {
        wp_send_json(['success' => false, 'data' => $data], $status_code);
    }
}

if (!function_exists('wp_die')) {
    function wp_die($message = '', $title = '', $args = []) {
        global $_wp_test_die_called;
        $_wp_test_die_called = [
            'message' => $message,
            'title' => $title,
            'args' => $args
        ];
        // Don't actually exit in tests
    }
}

// Hooks (use Brain Monkey for these in actual tests)
if (!function_exists('add_action')) {
    function add_action($hook, $callback, $priority = 10, $accepted_args = 1) {
        return \Brain\Monkey\Actions\expectAdded($hook);
    }
}

if (!function_exists('add_filter')) {
    function add_filter($hook, $callback, $priority = 10, $accepted_args = 1) {
        return \Brain\Monkey\Filters\expectAdded($hook);
    }
}

if (!function_exists('do_action')) {
    function do_action($hook, ...$args) {
        return \Brain\Monkey\Actions\expectDone($hook);
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters($hook, $value, ...$args) {
        return $value; // Return original value by default
    }
}

if (!function_exists('has_action')) {
    function has_action($hook, $callback = false) {
        return false;
    }
}

if (!function_exists('has_filter')) {
    function has_filter($hook, $callback = false) {
        return false;
    }
}

if (!function_exists('remove_action')) {
    function remove_action($hook, $callback, $priority = 10) {
        return true;
    }
}

if (!function_exists('remove_filter')) {
    function remove_filter($hook, $callback, $priority = 10) {
        return true;
    }
}

// Debug functions
if (!function_exists('error_log') && WP_DEBUG) {
    // Use PHP's built-in error_log
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

if (!function_exists('esc_html_e')) {
    function esc_html_e($text, $domain = 'default') {
        echo esc_html__($text, $domain);
    }
}

if (!function_exists('esc_attr__')) {
    function esc_attr__($text, $domain = 'default') {
        return esc_attr(__($text, $domain));
    }
}

if (!function_exists('esc_attr_e')) {
    function esc_attr_e($text, $domain = 'default') {
        echo esc_attr__($text, $domain);
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

if (!function_exists('maybe_serialize')) {
    function maybe_serialize($data) {
        if (is_array($data) || is_object($data)) {
            return serialize($data);
        }
        return $data;
    }
}

if (!function_exists('maybe_unserialize')) {
    function maybe_unserialize($data) {
        if (is_serialized($data)) {
            return @unserialize($data);
        }
        return $data;
    }
}

if (!function_exists('is_serialized')) {
    function is_serialized($data, $strict = true) {
        if (!is_string($data)) {
            return false;
        }
        $data = trim($data);
        if ($data === 'N;') {
            return true;
        }
        if (strlen($data) < 4) {
            return false;
        }
        if ($data[1] !== ':') {
            return false;
        }
        if ($strict) {
            $lastc = substr($data, -1);
            if ($lastc !== ';' && $lastc !== '}') {
                return false;
            }
        }
        $token = $data[0];
        switch ($token) {
            case 's':
                if ($strict) {
                    if (substr($data, -2, 1) !== '"') {
                        return false;
                    }
                } elseif (strpos($data, '"') === false) {
                    return false;
                }
                // Fall through
            case 'a':
            case 'O':
                return (bool) preg_match("/^{$token}:[0-9]+:/s", $data);
            case 'b':
            case 'i':
            case 'd':
                $end = $strict ? '$' : '';
                return (bool) preg_match("/^{$token}:[0-9.E+-]+;$end/", $data);
        }
        return false;
    }
}

// Database table prefix
if (!isset($GLOBALS['table_prefix'])) {
    $GLOBALS['table_prefix'] = 'wp_';
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

/**
 * Helper function to reset test data between tests
 */
function mld_reset_test_data() {
    global $_wp_cache_test_data, $_wp_transients_test_data, $_wp_options_test_data;
    global $_wp_test_current_user_id, $_wp_test_user_capabilities;
    global $_wp_test_json_response, $_wp_test_die_called;

    $_wp_cache_test_data = [];
    $_wp_transients_test_data = [];
    $_wp_options_test_data = [];
    $_wp_test_current_user_id = 0;
    $_wp_test_user_capabilities = [];
    $_wp_test_json_response = null;
    $_wp_test_die_called = null;

    // Reset Brain Monkey
    \Brain\Monkey\tearDown();
    \Brain\Monkey\setUp();
}

/**
 * Helper function to set test user
 */
function mld_set_test_user($user_id, $capabilities = []) {
    global $_wp_test_current_user_id, $_wp_test_user_capabilities;
    $_wp_test_current_user_id = $user_id;
    $_wp_test_user_capabilities = $capabilities;
}

/**
 * Helper to get last JSON response in tests
 */
function mld_get_test_json_response() {
    global $_wp_test_json_response;
    return $_wp_test_json_response;
}

/**
 * Helper to check if wp_die was called
 */
function mld_get_test_die_info() {
    global $_wp_test_die_called;
    return $_wp_test_die_called;
}

// Register shutdown to tear down Brain Monkey
register_shutdown_function(function() {
    \Brain\Monkey\tearDown();
});
