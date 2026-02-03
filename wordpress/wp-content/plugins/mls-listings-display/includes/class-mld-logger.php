<?php
/**
 * Centralized logging system for the MLS Listings Display plugin
 * Handles debug, info, warning, and error logging with environment detection
 *
 * @package MLS_Listings_Display
 * @since 3.0.1
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * MLD_Logger class for centralized logging
 */
class MLD_Logger {

    const LEVEL_DEBUG = 0;
    const LEVEL_INFO = 1;
    const LEVEL_WARNING = 2;
    const LEVEL_ERROR = 3;

    private static $instance = null;
    private $log_level;
    private $log_file;

    /**
     * Get singleton instance
     */
    public static function getInstance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor
     */
    private function __construct() {
        $this->log_level = $this->determine_log_level();
        $this->log_file = WP_CONTENT_DIR . '/debug.log';
    }

    /**
     * Determine appropriate log level based on environment
     */
    private function determine_log_level() {
        // Development environment
        if (defined('WP_DEBUG') && WP_DEBUG) {
            return self::LEVEL_DEBUG;
        }
        
        // Staging environment
        if (defined('WP_ENV') && WP_ENV === 'staging') {
            return self::LEVEL_INFO;
        }
        
        // Production environment - only errors and warnings
        return self::LEVEL_WARNING;
    }

    /**
     * Log debug messages (only in development)
     */
    public static function debug($message, $context = []) {
        self::getInstance()->log(self::LEVEL_DEBUG, $message, $context);
    }

    /**
     * Log info messages
     */
    public static function info($message, $context = []) {
        self::getInstance()->log(self::LEVEL_INFO, $message, $context);
    }

    /**
     * Log warning messages
     */
    public static function warning($message, $context = []) {
        self::getInstance()->log(self::LEVEL_WARNING, $message, $context);
    }

    /**
     * Log error messages
     */
    public static function error($message, $context = []) {
        self::getInstance()->log(self::LEVEL_ERROR, $message, $context);
    }

    /**
     * Internal log method
     */
    private function log($level, $message, $context = []) {
        // Only log if level is high enough
        if ($level < $this->log_level) {
            return;
        }

        // Skip all logging during plugin activation to prevent unexpected output
        if (defined('WP_ADMIN') && WP_ADMIN &&
            isset($_GET['action']) && $_GET['action'] === 'activate') {
            return;
        }

        // Skip logging if we're in plugin activation hooks
        if (doing_action('activate_' . plugin_basename(__FILE__)) ||
            doing_action('wp_loaded') && !did_action('wp_loaded')) {
            return;
        }

        $level_names = [
            self::LEVEL_DEBUG => 'DEBUG',
            self::LEVEL_INFO => 'INFO',
            self::LEVEL_WARNING => 'WARNING',
            self::LEVEL_ERROR => 'ERROR'
        ];

        $level_name = $level_names[$level] ?? 'UNKNOWN';
        $timestamp = current_time('Y-m-d H:i:s');

        // Format context data
        $context_str = '';
        if (!empty($context)) {
            $context_str = ' | Context: ' . json_encode($context, JSON_UNESCAPED_SLASHES);
        }

        // Create log entry
        $log_entry = sprintf(
            "[%s] MLD %s: %s%s\n",
            $timestamp,
            $level_name,
            $message,
            $context_str
        );

        // Only log to file, never to browser during web requests
        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG && $this->log_file) {
            error_log($log_entry, 3, $this->log_file);
        } elseif (php_sapi_name() === 'cli') {
            // Only output to error_log if we're in CLI mode (WP-CLI, cron, etc.)
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log($log_entry);
            }
        }
        // Skip logging entirely if we can't write to file and we're in a web request

        // For critical errors, also trigger WordPress admin notice
        if ($level === self::LEVEL_ERROR && is_admin() && !wp_doing_ajax()) {
            $this->add_admin_notice($message);
        }
    }

    /**
     * Add admin notice for critical errors
     */
    private function add_admin_notice($message) {
        add_action('admin_notices', function() use ($message) {
            printf(
                '<div class="notice notice-error"><p><strong>MLS Plugin Error:</strong> %s</p></div>',
                esc_html($message)
            );
        });
    }

    /**
     * Log performance metrics
     */
    public static function performance($operation, $duration, $additional_data = []) {
        $context = array_merge([
            'operation' => $operation,
            'duration_ms' => round($duration * 1000, 2),
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true)
        ], $additional_data);

        if ($duration > 1.0) {
            self::warning("Slow operation detected: {$operation}", $context);
        } else {
            self::debug("Performance: {$operation}", $context);
        }
    }

    /**
     * Log SQL queries (for debugging)
     */
    public static function sql($query, $duration = null, $results = null) {
        $context = ['query' => $query];
        
        if ($duration !== null) {
            $context['duration_ms'] = round($duration * 1000, 2);
        }
        
        if ($results !== null) {
            $context['result_count'] = is_array($results) ? count($results) : $results;
        }

        self::debug('SQL Query executed', $context);
    }

    /**
     * Log AJAX requests
     */
    public static function ajax($action, $data = [], $response_time = null) {
        $context = [
            'action' => $action,
            'user_id' => get_current_user_id(),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ];

        if (!empty($data)) {
            $context['data'] = $data;
        }

        if ($response_time !== null) {
            $context['response_time_ms'] = round($response_time * 1000, 2);
        }

        self::info("AJAX request: {$action}", $context);
    }
}