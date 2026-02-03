<?php
/**
 * Logger Class
 *
 * Handles logging for debugging and monitoring.
 *
 * @package SN_Appointment_Booking
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Logger class.
 *
 * @since 1.0.0
 */
class SNAB_Logger {

    /**
     * Log levels.
     */
    const ERROR = 'ERROR';
    const WARNING = 'WARNING';
    const INFO = 'INFO';
    const DEBUG = 'DEBUG';

    /**
     * Log an error message.
     *
     * @param string $message Log message.
     * @param array  $context Additional context data.
     */
    public static function error($message, $context = array()) {
        self::log(self::ERROR, $message, $context);
    }

    /**
     * Log a warning message.
     *
     * @param string $message Log message.
     * @param array  $context Additional context data.
     */
    public static function warning($message, $context = array()) {
        self::log(self::WARNING, $message, $context);
    }

    /**
     * Log an info message.
     *
     * @param string $message Log message.
     * @param array  $context Additional context data.
     */
    public static function info($message, $context = array()) {
        self::log(self::INFO, $message, $context);
    }

    /**
     * Log a debug message.
     *
     * Only logs if WP_DEBUG is enabled.
     *
     * @param string $message Log message.
     * @param array  $context Additional context data.
     */
    public static function debug($message, $context = array()) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            self::log(self::DEBUG, $message, $context);
        }
    }

    /**
     * Main logging function.
     *
     * @param string $level   Log level.
     * @param string $message Log message.
     * @param array  $context Additional context data.
     */
    private static function log($level, $message, $context = array()) {
        // Only log if WP_DEBUG_LOG is enabled
        if (!defined('WP_DEBUG_LOG') || !WP_DEBUG_LOG) {
            // Still log errors even without WP_DEBUG_LOG
            if ($level !== self::ERROR) {
                return;
            }
        }

        $timestamp = current_time('Y-m-d H:i:s');
        $prefix = '[SN Appointment Booking]';

        // Format the log entry
        $log_entry = sprintf(
            '%s %s [%s] %s',
            $timestamp,
            $prefix,
            $level,
            $message
        );

        // Add context if provided
        if (!empty($context)) {
            $log_entry .= ' | Context: ' . wp_json_encode($context);
        }

        // Write to WordPress debug log
        error_log($log_entry);

        // Also store critical errors in options for admin display
        if ($level === self::ERROR) {
            self::store_error($message, $context);
        }
    }

    /**
     * Store error for admin display.
     *
     * @param string $message Error message.
     * @param array  $context Error context.
     */
    private static function store_error($message, $context = array()) {
        $errors = get_option('snab_recent_errors', array());

        // Add new error
        $errors[] = array(
            'time' => current_time('mysql'),
            'message' => $message,
            'context' => $context,
        );

        // Keep only last 20 errors
        if (count($errors) > 20) {
            $errors = array_slice($errors, -20);
        }

        update_option('snab_recent_errors', $errors);
    }

    /**
     * Get recent errors.
     *
     * @param int $count Number of errors to retrieve.
     * @return array Recent errors.
     */
    public static function get_recent_errors($count = 10) {
        $errors = get_option('snab_recent_errors', array());
        return array_slice(array_reverse($errors), 0, $count);
    }

    /**
     * Clear stored errors.
     */
    public static function clear_errors() {
        delete_option('snab_recent_errors');
    }

    /**
     * Log a database query error.
     *
     * @param string $query  The SQL query.
     * @param string $error  The error message.
     */
    public static function db_error($query, $error) {
        self::error('Database error', array(
            'query' => $query,
            'error' => $error,
        ));
    }

    /**
     * Log an API error.
     *
     * @param string $endpoint The API endpoint.
     * @param mixed  $response The API response.
     * @param string $error    The error message.
     */
    public static function api_error($endpoint, $response, $error) {
        self::error('API error', array(
            'endpoint' => $endpoint,
            'response' => is_array($response) ? $response : (string) $response,
            'error' => $error,
        ));
    }
}
