<?php
/**
 * PHPUnit Bootstrap File for BMN Flip Analyzer Plugin
 *
 * Sets up the testing environment with WordPress function stubs
 * and loads class files in dependency order.
 *
 * @package BMN_Flip_Analyzer\Tests
 * @since 0.20.0
 */

if (php_sapi_name() !== 'cli') {
    die('This script can only be run from the command line.');
}

// ---------------------------------------------------------------
// WordPress constants
// ---------------------------------------------------------------

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
if (!defined('FLIP_PLUGIN_FILE')) {
    define('FLIP_PLUGIN_FILE', dirname(__DIR__) . '/bmn-flip-analyzer.php');
}

if (!defined('FLIP_PLUGIN_PATH')) {
    define('FLIP_PLUGIN_PATH', dirname(__DIR__) . '/');
}

if (!defined('FLIP_VERSION')) {
    define('FLIP_VERSION', '0.20.0');
}

// Database constants
if (!defined('OBJECT')) {
    define('OBJECT', 'OBJECT');
}

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

// ---------------------------------------------------------------
// Global test data stores
// ---------------------------------------------------------------

$_flip_options_test_data = [];
$_flip_test_current_time = null;  // Override for current_time()
$_flip_test_wp_date_year = null;  // Override for wp_date('Y')

// ---------------------------------------------------------------
// WordPress function stubs
// ---------------------------------------------------------------

if (!function_exists('current_time')) {
    function current_time($type, $gmt = 0) {
        global $_flip_test_current_time;
        $tz = new DateTimeZone('America/New_York');

        if ($_flip_test_current_time !== null) {
            $now = new DateTime($_flip_test_current_time, $tz);
        } else {
            $now = new DateTime('now', $tz);
        }

        if ($type === 'mysql') {
            return $now->format('Y-m-d H:i:s');
        } elseif ($type === 'timestamp') {
            return $now->getTimestamp();
        }
        return $now->format($type);
    }
}

if (!function_exists('wp_date')) {
    function wp_date($format, $timestamp = null) {
        global $_flip_test_wp_date_year;

        // If asking for year and we have an override, use it
        if ($format === 'Y' && $_flip_test_wp_date_year !== null) {
            return (string) $_flip_test_wp_date_year;
        }

        $tz = new DateTimeZone('America/New_York');
        if ($timestamp !== null) {
            $dt = new DateTime('@' . $timestamp);
            $dt->setTimezone($tz);
        } else {
            $dt = new DateTime('now', $tz);
        }

        // If we have a year override, inject it into the datetime for Y format
        if ($_flip_test_wp_date_year !== null && str_contains($format, 'Y')) {
            $dt->setDate((int) $_flip_test_wp_date_year, (int) $dt->format('m'), (int) $dt->format('d'));
        }

        return $dt->format($format);
    }
}

if (!function_exists('wp_timezone')) {
    function wp_timezone() {
        return new DateTimeZone('America/New_York');
    }
}

if (!function_exists('get_option')) {
    function get_option($option, $default = false) {
        global $_flip_options_test_data;
        return isset($_flip_options_test_data[$option]) ? $_flip_options_test_data[$option] : $default;
    }
}

if (!function_exists('update_option')) {
    function update_option($option, $value) {
        global $_flip_options_test_data;
        $_flip_options_test_data[$option] = $value;
        return true;
    }
}

if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, $options = 0, $depth = 512) {
        return json_encode($data, $options | JSON_UNESCAPED_UNICODE, $depth);
    }
}

if (!function_exists('is_plugin_active')) {
    function is_plugin_active($plugin) {
        return false;
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str) {
        return trim(strip_tags($str));
    }
}

if (!function_exists('absint')) {
    function absint($value) {
        return abs((int) $value);
    }
}

if (!function_exists('esc_html')) {
    function esc_html($text) {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('__')) {
    function __($text, $domain = 'default') {
        return $text;
    }
}

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

// ---------------------------------------------------------------
// WP_Error stub
// ---------------------------------------------------------------

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

// ---------------------------------------------------------------
// Mock wpdb class
// ---------------------------------------------------------------

class MockWPDB {
    public $prefix = 'wp_';
    public $last_error = '';
    public $insert_id = 0;

    public function get_var($query) {
        return null;
    }

    public function get_row($query, $output = OBJECT) {
        return null;
    }

    public function get_results($query, $output = OBJECT) {
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

// ---------------------------------------------------------------
// Test helper functions
// ---------------------------------------------------------------

function flip_reset_test_data(): void {
    global $_flip_options_test_data, $_flip_test_current_time, $_flip_test_wp_date_year;
    $_flip_options_test_data = [];
    $_flip_test_current_time = null;
    $_flip_test_wp_date_year = null;
}

function flip_set_current_year(int $year): void {
    global $_flip_test_wp_date_year;
    $_flip_test_wp_date_year = $year;
}

function flip_set_current_time(string $datetime): void {
    global $_flip_test_current_time;
    $_flip_test_current_time = $datetime;
}

// ---------------------------------------------------------------
// Set global $wpdb
// ---------------------------------------------------------------

$GLOBALS['wpdb'] = new MockWPDB();

// ---------------------------------------------------------------
// Load class files in dependency order (matching bmn-flip-analyzer.php)
// ---------------------------------------------------------------

$includes_dir = dirname(__DIR__) . '/includes/';

require_once $includes_dir . 'class-flip-database.php';
require_once $includes_dir . 'class-flip-arv-calculator.php';
require_once $includes_dir . 'class-flip-financial-scorer.php';
require_once $includes_dir . 'class-flip-property-scorer.php';
require_once $includes_dir . 'class-flip-market-scorer.php';
require_once $includes_dir . 'class-flip-disqualifier.php';
require_once $includes_dir . 'class-flip-analyzer.php';
