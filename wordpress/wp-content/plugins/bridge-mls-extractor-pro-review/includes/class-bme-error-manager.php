<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Enhanced Error Manager for Bridge MLS Extractor Pro
 * Provides structured error handling, classification, and recovery strategies
 * Version: 1.0.0
 */
class BME_Error_Manager {
    
    // Error Categories
    const CATEGORY_API = 'api';
    const CATEGORY_DATABASE = 'database'; 
    const CATEGORY_BATCH = 'batch';
    const CATEGORY_NETWORK = 'network';
    const CATEGORY_CONFIGURATION = 'configuration';
    const CATEGORY_SYSTEM = 'system';
    
    // Error Severity Levels
    const SEVERITY_CRITICAL = 'critical';   // System cannot continue
    const SEVERITY_ERROR = 'error';         // Significant error, but recoverable
    const SEVERITY_WARNING = 'warning';     // Minor issue, system continues
    const SEVERITY_INFO = 'info';           // Informational message
    
    // Recovery Strategies
    const RECOVERY_RETRY = 'retry';
    const RECOVERY_SKIP = 'skip';
    const RECOVERY_PAUSE = 'pause';
    const RECOVERY_RESTART = 'restart';
    const RECOVERY_MANUAL = 'manual';
    
    private static $error_definitions = [
        // API Errors
        'API_001' => [
            'category' => self::CATEGORY_API,
            'severity' => self::SEVERITY_CRITICAL,
            'message' => 'API credentials not configured or invalid',
            'recovery' => self::RECOVERY_MANUAL,
            'description' => 'Bridge API credentials are missing or incorrect'
        ],
        'API_002' => [
            'category' => self::CATEGORY_API,
            'severity' => self::SEVERITY_ERROR,
            'message' => 'API rate limit exceeded',
            'recovery' => self::RECOVERY_PAUSE,
            'description' => 'Too many requests sent to Bridge API'
        ],
        'API_003' => [
            'category' => self::CATEGORY_API,
            'severity' => self::SEVERITY_ERROR,
            'message' => 'API request timeout',
            'recovery' => self::RECOVERY_RETRY,
            'description' => 'Request to Bridge API timed out'
        ],
        'API_004' => [
            'category' => self::CATEGORY_API,
            'severity' => self::SEVERITY_WARNING,
            'message' => 'API returned empty result set',
            'recovery' => self::RECOVERY_SKIP,
            'description' => 'No data returned from API query'
        ],
        'API_005' => [
            'category' => self::CATEGORY_API,
            'severity' => self::SEVERITY_ERROR,
            'message' => 'Invalid API response format',
            'recovery' => self::RECOVERY_RETRY,
            'description' => 'API response is not valid JSON or missing required fields'
        ],
        
        // Batch Processing Errors
        'BATCH_001' => [
            'category' => self::CATEGORY_BATCH,
            'severity' => self::SEVERITY_ERROR,
            'message' => 'Batch session exceeded memory limit',
            'recovery' => self::RECOVERY_RESTART,
            'description' => 'Session consumed too much memory and needs restart'
        ],
        'BATCH_002' => [
            'category' => self::CATEGORY_BATCH,
            'severity' => self::SEVERITY_ERROR,
            'message' => 'Batch session timeout',
            'recovery' => self::RECOVERY_RESTART,
            'description' => 'Session exceeded time limit and needs restart'
        ],
        'BATCH_003' => [
            'category' => self::CATEGORY_BATCH,
            'severity' => self::SEVERITY_CRITICAL,
            'message' => 'Batch state corruption detected',
            'recovery' => self::RECOVERY_MANUAL,
            'description' => 'Batch processing state is corrupted and requires manual intervention'
        ],
        'BATCH_004' => [
            'category' => self::CATEGORY_BATCH,
            'severity' => self::SEVERITY_WARNING,
            'message' => 'Session continuation delayed',
            'recovery' => self::RECOVERY_RETRY,
            'description' => 'WordPress cron failed to trigger session continuation'
        ],
        
        // Database Errors
        'DB_001' => [
            'category' => self::CATEGORY_DATABASE,
            'severity' => self::SEVERITY_CRITICAL,
            'message' => 'Database connection failed',
            'recovery' => self::RECOVERY_MANUAL,
            'description' => 'Unable to connect to WordPress database'
        ],
        'DB_002' => [
            'category' => self::CATEGORY_DATABASE,
            'severity' => self::SEVERITY_ERROR,
            'message' => 'Required database tables missing',
            'recovery' => self::RECOVERY_RESTART,
            'description' => 'BME database tables are missing and need recreation'
        ],
        'DB_003' => [
            'category' => self::CATEGORY_DATABASE,
            'severity' => self::SEVERITY_ERROR,
            'message' => 'Database query failed',
            'recovery' => self::RECOVERY_RETRY,
            'description' => 'Database query execution failed'
        ],
        
        // Network Errors
        'NET_001' => [
            'category' => self::CATEGORY_NETWORK,
            'severity' => self::SEVERITY_ERROR,
            'message' => 'Network connection timeout',
            'recovery' => self::RECOVERY_RETRY,
            'description' => 'Network request timed out'
        ],
        'NET_002' => [
            'category' => self::CATEGORY_NETWORK,
            'severity' => self::SEVERITY_ERROR,
            'message' => 'DNS resolution failed',
            'recovery' => self::RECOVERY_RETRY,
            'description' => 'Unable to resolve Bridge API hostname'
        ],
        
        // Configuration Errors
        'CONF_001' => [
            'category' => self::CATEGORY_CONFIGURATION,
            'severity' => self::SEVERITY_CRITICAL,
            'message' => 'Invalid extraction configuration',
            'recovery' => self::RECOVERY_MANUAL,
            'description' => 'Extraction configuration contains invalid settings'
        ],
        'CONF_002' => [
            'category' => self::CATEGORY_CONFIGURATION,
            'severity' => self::SEVERITY_WARNING,
            'message' => 'Extraction filters too broad',
            'recovery' => self::RECOVERY_MANUAL,
            'description' => 'Extraction may return excessive data due to broad filters'
        ]
    ];
    
    private $extraction_id;
    private $error_log = [];
    
    public function __construct($extraction_id = null) {
        $this->extraction_id = $extraction_id;
    }
    
    /**
     * Log a structured error with automatic recovery strategy
     */
    public function log_error($error_code, $context = [], $exception = null) {
        $error_def = self::$error_definitions[$error_code] ?? null;
        
        if (!$error_def) {
            // Unknown error code
            $error_def = [
                'category' => self::CATEGORY_SYSTEM,
                'severity' => self::SEVERITY_ERROR,
                'message' => 'Unknown error occurred',
                'recovery' => self::RECOVERY_MANUAL,
                'description' => 'Error code not found in definitions'
            ];
        }
        
        $error_entry = [
            'code' => $error_code,
            'timestamp' => current_time('mysql'),
            'extraction_id' => $this->extraction_id,
            'category' => $error_def['category'],
            'severity' => $error_def['severity'],
            'message' => $error_def['message'],
            'description' => $error_def['description'],
            'recovery_strategy' => $error_def['recovery'],
            'context' => $context,
            'exception' => $exception ? $exception->getMessage() : null,
            'trace' => $exception ? $exception->getTraceAsString() : null
        ];
        
        // Store error in memory for this instance
        $this->error_log[] = $error_entry;
        
        // Persist error to database
        $this->persist_error($error_entry);
        
        // Log to WordPress error log
        $this->write_to_wp_log($error_entry);
        
        // Execute recovery strategy if applicable
        $this->execute_recovery_strategy($error_entry);
        
        return $error_entry;
    }
    
    /**
     * Get recovery recommendation for an error
     */
    public function get_recovery_recommendation($error_code) {
        $error_def = self::$error_definitions[$error_code] ?? null;
        
        if (!$error_def) {
            return null;
        }
        
        $recommendations = [
            self::RECOVERY_RETRY => 'Automatically retry the operation after a brief delay',
            self::RECOVERY_SKIP => 'Skip this operation and continue with the next batch',
            self::RECOVERY_PAUSE => 'Pause extraction temporarily and resume after delay',
            self::RECOVERY_RESTART => 'Restart the current session from last checkpoint',
            self::RECOVERY_MANUAL => 'Manual intervention required - check configuration and logs'
        ];
        
        return [
            'strategy' => $error_def['recovery'],
            'description' => $recommendations[$error_def['recovery']] ?? 'No recommendation available'
        ];
    }
    
    /**
     * Check error patterns and suggest preventive actions
     */
    public function analyze_error_patterns($extraction_id = null, $hours = 24) {
        $id = $extraction_id ?: $this->extraction_id;
        $since = date('Y-m-d H:i:s', strtotime("-{$hours} hours"));
        
        $errors = $this->get_errors_since($id, $since);
        
        if (empty($errors)) {
            return ['status' => 'healthy', 'message' => 'No errors detected in the specified period'];
        }
        
        $patterns = $this->detect_patterns($errors);
        $suggestions = $this->generate_suggestions($patterns);
        
        return [
            'status' => 'issues_detected',
            'error_count' => count($errors),
            'patterns' => $patterns,
            'suggestions' => $suggestions,
            'last_error' => end($errors)
        ];
    }
    
    /**
     * Execute automated recovery strategy
     */
    private function execute_recovery_strategy($error_entry) {
        switch ($error_entry['recovery_strategy']) {
            case self::RECOVERY_RETRY:
                $this->schedule_retry($error_entry);
                break;
                
            case self::RECOVERY_PAUSE:
                $this->schedule_pause($error_entry);
                break;
                
            case self::RECOVERY_RESTART:
                $this->schedule_restart($error_entry);
                break;
                
            case self::RECOVERY_SKIP:
                $this->log_skip_action($error_entry);
                break;
                
            case self::RECOVERY_MANUAL:
                $this->trigger_manual_alert($error_entry);
                break;
        }
    }
    
    /**
     * Schedule automatic retry for recoverable errors
     */
    private function schedule_retry($error_entry) {
        $retry_count = $this->get_retry_count($error_entry['code']);
        $max_retries = $this->get_max_retries($error_entry['code']);
        
        if ($retry_count < $max_retries) {
            $delay = $this->calculate_retry_delay($retry_count);
            
            // Store retry information
            $this->store_retry_info($error_entry['code'], $retry_count + 1);
            
            // Schedule retry via WordPress cron
            wp_schedule_single_event(
                time() + $delay,
                'bme_error_recovery_retry',
                [$this->extraction_id, $error_entry['code']]
            );
            
            error_log("BME Error: Scheduled retry #{$retry_count} for {$error_entry['code']} in {$delay} seconds");
        } else {
            error_log("BME Error: Maximum retries exceeded for {$error_entry['code']}, escalating to manual intervention");
            $this->trigger_manual_alert($error_entry);
        }
    }
    
    /**
     * Schedule extraction pause for rate limiting or temporary issues
     */
    private function schedule_pause($error_entry) {
        $pause_duration = $this->get_pause_duration($error_entry['code']);
        
        wp_schedule_single_event(
            time() + $pause_duration,
            'bme_error_recovery_resume',
            [$this->extraction_id, $error_entry['code']]
        );
        
        error_log("BME Error: Scheduled extraction resume for {$error_entry['code']} in {$pause_duration} seconds");
    }
    
    /**
     * Schedule session restart from last checkpoint
     */
    private function schedule_restart($error_entry) {
        // Clear current session state to force fresh start
        delete_post_meta($this->extraction_id, '_bme_extraction_state');
        
        // Schedule restart after brief delay
        wp_schedule_single_event(
            time() + 60, // 1 minute delay
            'bme_error_recovery_restart',
            [$this->extraction_id, $error_entry['code']]
        );
        
        error_log("BME Error: Scheduled session restart for {$error_entry['code']}");
    }
    
    /**
     * Trigger manual intervention alert
     */
    private function trigger_manual_alert($error_entry) {
        // Store alert for admin dashboard
        $alerts = get_option('bme_critical_alerts', []);
        $alerts[] = [
            'timestamp' => $error_entry['timestamp'],
            'extraction_id' => $this->extraction_id,
            'error_code' => $error_entry['code'],
            'message' => $error_entry['message'],
            'severity' => $error_entry['severity']
        ];
        
        // Keep only last 50 alerts
        if (count($alerts) > 50) {
            $alerts = array_slice($alerts, -50);
        }
        
        update_option('bme_critical_alerts', $alerts);
        
        // Send email notification if configured
        $this->send_admin_notification($error_entry);
        
        error_log("BME Error: Critical error {$error_entry['code']} requires manual intervention");
    }
    
    /**
     * Persist error to database for analytics
     */
    private function persist_error($error_entry) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'bme_error_log';
        
        // Create table if it doesn't exist
        $this->ensure_error_log_table();
        
        $wpdb->insert(
            $table,
            [
                'timestamp' => $error_entry['timestamp'],
                'extraction_id' => $error_entry['extraction_id'],
                'error_code' => $error_entry['code'],
                'category' => $error_entry['category'],
                'severity' => $error_entry['severity'],
                'message' => $error_entry['message'],
                'context' => json_encode($error_entry['context']),
                'exception_message' => $error_entry['exception'],
                'recovery_strategy' => $error_entry['recovery_strategy']
            ],
            ['%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );
    }
    
    /**
     * Write structured error to WordPress error log
     */
    private function write_to_wp_log($error_entry) {
        $log_message = sprintf(
            'BME Error [%s]: %s (Code: %s, Category: %s, Severity: %s, Recovery: %s)',
            $error_entry['timestamp'],
            $error_entry['message'],
            $error_entry['code'],
            $error_entry['category'],
            $error_entry['severity'],
            $error_entry['recovery_strategy']
        );
        
        if (!empty($error_entry['context'])) {
            $log_message .= ' | Context: ' . json_encode($error_entry['context']);
        }
        
        error_log($log_message);
    }
    
    /**
     * Create error log table if it doesn't exist
     */
    private function ensure_error_log_table() {
        global $wpdb;
        
        $table = $wpdb->prefix . 'bme_error_log';
        
        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id int(11) NOT NULL AUTO_INCREMENT,
            timestamp datetime NOT NULL,
            extraction_id int(11) NULL,
            error_code varchar(20) NOT NULL,
            category varchar(50) NOT NULL,
            severity varchar(20) NOT NULL,
            message text NOT NULL,
            context text NULL,
            exception_message text NULL,
            recovery_strategy varchar(20) NOT NULL,
            created_at timestamp DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_timestamp (timestamp),
            INDEX idx_extraction (extraction_id),
            INDEX idx_code (error_code),
            INDEX idx_category (category),
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        
        $wpdb->query($sql);
    }
    
    /**
     * Get errors for analysis
     */
    private function get_errors_since($extraction_id, $since) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'bme_error_log';
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} 
             WHERE extraction_id = %d AND timestamp >= %s 
             ORDER BY timestamp DESC",
            $extraction_id,
            $since
        ), ARRAY_A);
    }
    
    /**
     * Helper methods for recovery strategies
     */
    private function get_retry_count($error_code) {
        return (int) get_transient("bme_retry_count_{$error_code}_{$this->extraction_id}") ?: 0;
    }
    
    private function get_max_retries($error_code) {
        $max_retries = [
            'API_003' => 3,  // API timeout
            'API_005' => 2,  // Invalid response
            'NET_001' => 3,  // Network timeout
            'DB_003' => 2,   // Database query failed
        ];
        
        return $max_retries[$error_code] ?? 1;
    }
    
    private function calculate_retry_delay($retry_count) {
        // Exponential backoff: 30s, 60s, 120s, 240s
        return min(30 * pow(2, $retry_count), 300);
    }
    
    private function store_retry_info($error_code, $retry_count) {
        set_transient("bme_retry_count_{$error_code}_{$this->extraction_id}", $retry_count, HOUR_IN_SECONDS);
    }
    
    private function get_pause_duration($error_code) {
        $durations = [
            'API_002' => 600,  // Rate limit: 10 minutes
            'BATCH_004' => 300  // Session delay: 5 minutes
        ];
        
        return $durations[$error_code] ?? 300;
    }
    
    /**
     * Send admin notification for critical errors
     */
    private function send_admin_notification($error_entry) {
        $admin_email = get_option('admin_email');
        $site_name = get_bloginfo('name');
        
        $subject = "[{$site_name}] Bridge MLS Critical Error - {$error_entry['code']}";
        
        $message = "A critical error has occurred in the Bridge MLS Extractor:\n\n";
        $message .= "Error Code: {$error_entry['code']}\n";
        $message .= "Message: {$error_entry['message']}\n";
        $message .= "Severity: {$error_entry['severity']}\n";
        $message .= "Extraction ID: {$error_entry['extraction_id']}\n";
        $message .= "Time: {$error_entry['timestamp']}\n";
        $message .= "Recovery: {$error_entry['recovery_strategy']}\n\n";
        
        if ($error_entry['context']) {
            $message .= "Context: " . json_encode($error_entry['context'], JSON_PRETTY_PRINT) . "\n\n";
        }
        
        $message .= "Please check the admin dashboard for more details and take appropriate action.";
        
        wp_mail($admin_email, $subject, $message);
    }
    
    /**
     * Get all error definitions for reference
     */
    public static function get_error_definitions() {
        return self::$error_definitions;
    }
    
    /**
     * Detect error patterns for analysis
     */
    private function detect_patterns($errors) {
        $patterns = [
            'frequent_codes' => [],
            'error_categories' => [],
            'time_clustering' => false,
            'escalation_trends' => []
        ];
        
        // Analyze frequent error codes
        $code_counts = array_count_values(array_column($errors, 'error_code'));
        arsort($code_counts);
        $patterns['frequent_codes'] = array_slice($code_counts, 0, 5, true);
        
        // Analyze error categories
        $category_counts = array_count_values(array_column($errors, 'category'));
        arsort($category_counts);
        $patterns['error_categories'] = $category_counts;
        
        return $patterns;
    }
    
    /**
     * Generate suggestions based on error patterns
     */
    private function generate_suggestions($patterns) {
        $suggestions = [];
        
        // Suggestions based on frequent error codes
        foreach ($patterns['frequent_codes'] as $code => $count) {
            if ($count > 5) {
                $error_def = self::$error_definitions[$code] ?? null;
                if ($error_def) {
                    $suggestions[] = "Frequent {$code} errors ({$count}x) suggest: " . $this->get_prevention_tip($code);
                }
            }
        }
        
        // Category-based suggestions
        if (($patterns['error_categories']['api'] ?? 0) > 3) {
            $suggestions[] = "Multiple API errors detected - check Bridge API status and credentials";
        }
        
        if (($patterns['error_categories']['network'] ?? 0) > 2) {
            $suggestions[] = "Network issues detected - verify server connectivity and DNS resolution";
        }
        
        return $suggestions;
    }
    
    /**
     * Get prevention tips for specific error codes
     */
    private function get_prevention_tip($error_code) {
        $tips = [
            'API_002' => 'Consider increasing rate limiting delays or reducing batch sizes',
            'API_003' => 'Check network stability and consider increasing timeout values',
            'BATCH_001' => 'Reduce batch size or increase available memory',
            'BATCH_002' => 'Optimize processing logic or reduce session size',
            'NET_001' => 'Verify network stability and firewall configuration'
        ];
        
        return $tips[$error_code] ?? 'Review logs and system configuration';
    }
}