<?php
/**
 * MLD Error Handler
 *
 * Comprehensive error handling with user-friendly messages and fallback mechanisms
 *
 * @package MLS_Listings_Display
 * @since 4.6.4
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Error_Handler {

    /**
     * Singleton instance
     */
    private static $instance = null;

    /**
     * Error messages for users
     */
    private static $user_messages = [
        'database_connection' => 'We are experiencing technical difficulties. Please try again in a few moments.',
        'query_failed' => 'Unable to load property listings. Please refresh the page or try again later.',
        'property_not_found' => 'The property you are looking for could not be found.',
        'ajax_failed' => 'Unable to complete your request. Please check your connection and try again.',
        'save_failed' => 'Unable to save your changes. Please try again.',
        'email_failed' => 'Unable to send email notification. Please check the email address and try again.',
        'permission_denied' => 'You do not have permission to perform this action.',
        'invalid_data' => 'The information provided appears to be invalid. Please check and try again.',
        'timeout' => 'The request took too long to complete. Please try again.',
        'general' => 'An unexpected error occurred. Please try again or contact support if the problem persists.'
    ];

    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        // Set custom error handler
        set_error_handler([$this, 'handle_error'], E_ALL & ~E_NOTICE & ~E_STRICT & ~E_DEPRECATED);

        // Set exception handler
        set_exception_handler([$this, 'handle_exception']);

        // Register shutdown function for fatal errors
        register_shutdown_function([$this, 'handle_shutdown']);
    }

    /**
     * Handle PHP errors
     *
     * @param int $errno Error number
     * @param string $errstr Error message
     * @param string $errfile File where error occurred
     * @param int $errline Line number
     * @return bool
     */
    public function handle_error($errno, $errstr, $errfile, $errline) {
        // Only handle MLD plugin errors
        if (strpos($errfile, 'mls-listings-display') === false) {
            return false;
        }

        $error_type = $this->get_error_type($errno);

        // Log the error
        if (class_exists('MLD_Logger')) {
            MLD_Logger::error("PHP {$error_type}: {$errstr}", [
                'file' => $errfile,
                'line' => $errline,
                'type' => $errno
            ]);
        }

        // Don't expose internal errors to users
        if (!WP_DEBUG && !is_admin()) {
            return true; // Suppress default error handler
        }

        return false;
    }

    /**
     * Handle uncaught exceptions
     *
     * @param Exception|Throwable $exception
     */
    public function handle_exception($exception) {
        // Only handle MLD plugin exceptions
        $trace = $exception->getTraceAsString();
        if (strpos($trace, 'mls-listings-display') === false) {
            // Re-throw if not our exception
            throw $exception;
        }

        // Log the exception
        if (class_exists('MLD_Logger')) {
            MLD_Logger::error('Uncaught exception: ' . $exception->getMessage(), [
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString()
            ]);
        }

        // Handle AJAX requests
        if (wp_doing_ajax()) {
            wp_send_json_error(self::$user_messages['general']);
            exit;
        }

        // Display user-friendly error
        if (!WP_DEBUG) {
            wp_die(
                self::$user_messages['general'],
                'Error',
                ['response' => 500]
            );
        }
    }

    /**
     * Handle shutdown for fatal errors
     */
    public function handle_shutdown() {
        $error = error_get_last();

        if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            // Check if it's our plugin error
            if (strpos($error['file'], 'mls-listings-display') !== false) {
                // Log fatal error
                if (class_exists('MLD_Logger')) {
                    MLD_Logger::error('Fatal error: ' . $error['message'], [
                        'file' => $error['file'],
                        'line' => $error['line'],
                        'type' => $error['type']
                    ]);
                }

                // Try to send notification to admin
                $this->notify_admin_of_fatal_error($error);
            }
        }
    }

    /**
     * Safe execution wrapper with try-catch
     *
     * @param callable $callback Function to execute
     * @param mixed $default Default return value on error
     * @param string $context Context for error logging
     * @return mixed
     */
    public static function safe_execute($callback, $default = null, $context = '') {
        try {
            return call_user_func($callback);
        } catch (Exception $e) {
            // Log the error
            if (class_exists('MLD_Logger')) {
                MLD_Logger::error("Error in {$context}: " . $e->getMessage(), [
                    'trace' => $e->getTraceAsString()
                ]);
            }
            return $default;
        } catch (Throwable $e) {
            // Handle PHP 7+ errors
            if (class_exists('MLD_Logger')) {
                MLD_Logger::error("Fatal error in {$context}: " . $e->getMessage(), [
                    'trace' => $e->getTraceAsString()
                ]);
            }
            return $default;
        }
    }

    /**
     * Safe database query execution
     *
     * @param string $query SQL query
     * @param array $params Query parameters
     * @param string $return_type Type of return (results, var, row, col)
     * @return mixed
     */
    public static function safe_query($query, $params = [], $return_type = 'results') {
        global $wpdb;

        try {
            // Prepare query if parameters provided
            if (!empty($params)) {
                $query = $wpdb->prepare($query, $params);
            }

            // Execute based on return type
            switch ($return_type) {
                case 'var':
                    return $wpdb->get_var($query);
                case 'row':
                    return $wpdb->get_row($query, ARRAY_A);
                case 'col':
                    return $wpdb->get_col($query);
                default:
                    return $wpdb->get_results($query, ARRAY_A);
            }
        } catch (Exception $e) {
            // Log database error
            if (class_exists('MLD_Logger')) {
                MLD_Logger::error('Database query failed: ' . $e->getMessage(), [
                    'query' => substr($query, 0, 500)
                ]);
            }

            // Return appropriate default based on type
            switch ($return_type) {
                case 'var':
                    return null;
                case 'row':
                    return null;
                case 'col':
                    return [];
                default:
                    return [];
            }
        }
    }

    /**
     * Get user-friendly error message
     *
     * @param string $error_code Error code
     * @param mixed $context Additional context
     * @return string
     */
    public static function get_user_message($error_code, $context = null) {
        if (isset(self::$user_messages[$error_code])) {
            return self::$user_messages[$error_code];
        }
        return self::$user_messages['general'];
    }

    /**
     * Add fallback mechanism for critical features
     *
     * @param callable $primary Primary method to try
     * @param callable $fallback Fallback method if primary fails
     * @param string $context Context for logging
     * @return mixed
     */
    public static function with_fallback($primary, $fallback, $context = '') {
        try {
            $result = call_user_func($primary);
            if ($result !== false && $result !== null) {
                return $result;
            }
        } catch (Exception $e) {
            // Log primary failure
            if (class_exists('MLD_Logger')) {
                MLD_Logger::warning("Primary method failed in {$context}, using fallback: " . $e->getMessage());
            }
        }

        // Try fallback
        try {
            return call_user_func($fallback);
        } catch (Exception $e) {
            // Log fallback failure
            if (class_exists('MLD_Logger')) {
                MLD_Logger::error("Fallback also failed in {$context}: " . $e->getMessage());
            }
            return null;
        }
    }

    /**
     * Get error type string
     *
     * @param int $errno Error number
     * @return string
     */
    private function get_error_type($errno) {
        switch ($errno) {
            case E_ERROR:
                return 'Error';
            case E_WARNING:
                return 'Warning';
            case E_PARSE:
                return 'Parse Error';
            case E_NOTICE:
                return 'Notice';
            case E_CORE_ERROR:
                return 'Core Error';
            case E_CORE_WARNING:
                return 'Core Warning';
            case E_COMPILE_ERROR:
                return 'Compile Error';
            case E_COMPILE_WARNING:
                return 'Compile Warning';
            case E_USER_ERROR:
                return 'User Error';
            case E_USER_WARNING:
                return 'User Warning';
            case E_USER_NOTICE:
                return 'User Notice';
            case E_STRICT:
                return 'Strict';
            case E_RECOVERABLE_ERROR:
                return 'Recoverable Error';
            case E_DEPRECATED:
                return 'Deprecated';
            case E_USER_DEPRECATED:
                return 'User Deprecated';
            default:
                return 'Unknown';
        }
    }

    /**
     * Notify admin of fatal error
     *
     * @param array $error Error details
     */
    private function notify_admin_of_fatal_error($error) {
        // Only notify in production
        if (WP_DEBUG || !is_admin()) {
            return;
        }

        $admin_email = get_option('admin_email');
        if (!$admin_email) {
            return;
        }

        $subject = 'Critical Error in MLS Listings Display Plugin';
        $message = "A fatal error occurred in the MLS Listings Display plugin:\n\n";
        $message .= "Error: {$error['message']}\n";
        $message .= "File: {$error['file']}\n";
        $message .= "Line: {$error['line']}\n";
        $message .= "Time: " . current_time('mysql') . "\n\n";
        $message .= "Please check the error logs for more details.";

        // Use wp_mail with fallback
        @wp_mail($admin_email, $subject, $message);
    }

    /**
     * Validate and sanitize user input
     *
     * @param mixed $input Input to validate
     * @param string $type Type of validation
     * @param mixed $default Default value if validation fails
     * @return mixed
     */
    public static function validate_input($input, $type, $default = null) {
        try {
            switch ($type) {
                case 'email':
                    $email = sanitize_email($input);
                    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : $default;

                case 'url':
                    $url = esc_url_raw($input);
                    return filter_var($url, FILTER_VALIDATE_URL) ? $url : $default;

                case 'int':
                    return filter_var($input, FILTER_VALIDATE_INT) !== false ? intval($input) : $default;

                case 'float':
                    return filter_var($input, FILTER_VALIDATE_FLOAT) !== false ? floatval($input) : $default;

                case 'bool':
                    return filter_var($input, FILTER_VALIDATE_BOOLEAN);

                case 'array':
                    return is_array($input) ? $input : $default;

                case 'json':
                    $decoded = json_decode($input, true);
                    return json_last_error() === JSON_ERROR_NONE ? $decoded : $default;

                default:
                    return sanitize_text_field($input);
            }
        } catch (Exception $e) {
            return $default;
        }
    }
}

// Initialize error handler
add_action('init', function() {
    if (class_exists('MLD_Error_Handler')) {
        MLD_Error_Handler::get_instance();
    }
}, 1); // High priority to catch errors early