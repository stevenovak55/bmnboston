<?php
/**
 * MLS Listings Display - Saved Search Cron Jobs
 * 
 * Handles scheduled tasks for saved search notifications
 * 
 * @package MLS_Listings_Display
 * @subpackage Saved_Searches
 * @since 3.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Saved_Search_Cron {
    
    /**
     * Hook names for cron events
     */
    const CRON_HOOK_INSTANT = 'mld_saved_search_instant';
    const CRON_HOOK_FIFTEEN_MIN = 'mld_saved_search_fifteen_min';
    const CRON_HOOK_HOURLY = 'mld_saved_search_hourly';
    const CRON_HOOK_DAILY = 'mld_saved_search_daily';
    const CRON_HOOK_WEEKLY = 'mld_saved_search_weekly';
    const CRON_HOOK_CLEANUP = 'mld_saved_search_cleanup';
    
    /**
     * Initialize cron jobs
     */
    public static function init() {
        // Register cron actions
        add_action(self::CRON_HOOK_INSTANT, [__CLASS__, 'process_instant_notifications']);
        add_action(self::CRON_HOOK_FIFTEEN_MIN, [__CLASS__, 'process_fifteen_minute_notifications']);
        add_action(self::CRON_HOOK_HOURLY, [__CLASS__, 'process_hourly_notifications']);
        add_action(self::CRON_HOOK_DAILY, [__CLASS__, 'process_daily_notifications']);
        add_action(self::CRON_HOOK_WEEKLY, [__CLASS__, 'process_weekly_notifications']);
        add_action(self::CRON_HOOK_CLEANUP, [__CLASS__, 'cleanup_deleted_searches']);

        // Schedule events on activation
        add_action('mld_saved_searches_activated', [__CLASS__, 'schedule_events']);

        // Unschedule events on deactivation
        add_action('mld_saved_searches_deactivated', [__CLASS__, 'unschedule_events']);

        // Ensure cron jobs are scheduled on admin_init (self-healing for existing installs)
        add_action('admin_init', [__CLASS__, 'maybe_schedule_events'], 20);
    }

    /**
     * Schedule events if they're not already scheduled (self-healing)
     * This ensures cron jobs are always active, even on existing installations
     *
     * @since 6.11.10
     */
    public static function maybe_schedule_events() {
        // Only run once per day to avoid overhead
        $last_check = get_transient('mld_cron_schedule_check');
        if ($last_check) {
            return;
        }

        // Check if any cron jobs are missing
        $needs_scheduling = false;
        $hooks = [
            self::CRON_HOOK_INSTANT,
            self::CRON_HOOK_FIFTEEN_MIN,
            self::CRON_HOOK_HOURLY,
            self::CRON_HOOK_DAILY,
            self::CRON_HOOK_WEEKLY,
            self::CRON_HOOK_CLEANUP
        ];

        foreach ($hooks as $hook) {
            if (!wp_next_scheduled($hook)) {
                $needs_scheduling = true;
                break;
            }
        }

        if ($needs_scheduling) {
            self::schedule_events();
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD Saved Search Cron: Self-healed missing cron schedules');
            }
        }

        // Set transient to prevent checking again for 24 hours
        set_transient('mld_cron_schedule_check', time(), DAY_IN_SECONDS);
    }
    
    /**
     * Schedule all cron events
     *
     * Frequency Schedule:
     * - instant: Every 5 minutes
     * - fifteen_min: Every 15 minutes
     * - hourly: Every hour
     * - daily: Once per day at 9 AM
     * - weekly: Once per week on Mondays at 9 AM
     */
    public static function schedule_events() {
        // Schedule instant notifications (every 5 minutes)
        if (!wp_next_scheduled(self::CRON_HOOK_INSTANT)) {
            wp_schedule_event(time(), 'mld_five_minutes', self::CRON_HOOK_INSTANT);
        }

        // Schedule 15-minute notifications (every 15 minutes)
        if (!wp_next_scheduled(self::CRON_HOOK_FIFTEEN_MIN)) {
            wp_schedule_event(time(), 'mld_fifteen_minutes', self::CRON_HOOK_FIFTEEN_MIN);
        }

        // Schedule hourly notifications
        if (!wp_next_scheduled(self::CRON_HOOK_HOURLY)) {
            wp_schedule_event(time(), 'hourly', self::CRON_HOOK_HOURLY);
        }

        // Schedule daily notifications (once per day at 9 AM)
        if (!wp_next_scheduled(self::CRON_HOOK_DAILY)) {
            $timestamp = self::get_next_scheduled_time('9:00am', 'daily');
            wp_schedule_event($timestamp, 'daily', self::CRON_HOOK_DAILY);
        }

        // Schedule weekly notifications (Mondays at 9 AM in WordPress timezone)
        if (!wp_next_scheduled(self::CRON_HOOK_WEEKLY)) {
            $timestamp = self::get_next_scheduled_time('9:00am', 'weekly');
            wp_schedule_event($timestamp, 'weekly', self::CRON_HOOK_WEEKLY);
        }

        // Schedule daily cleanup of soft-deleted searches (3 AM daily)
        if (!wp_next_scheduled(self::CRON_HOOK_CLEANUP)) {
            $timestamp = self::get_next_scheduled_time('3:00am', 'daily');
            wp_schedule_event($timestamp, 'daily', self::CRON_HOOK_CLEANUP);
        }
    }

    /**
     * Get next scheduled time in WordPress timezone
     *
     * @param string $time Time string (e.g., '9:00am')
     * @param string $frequency 'daily' or 'weekly'
     * @return int Unix timestamp
     */
    private static function get_next_scheduled_time($time, $frequency = 'daily') {
        // Get WordPress timezone
        $timezone_string = get_option('timezone_string');
        if (empty($timezone_string)) {
            // Fallback to GMT offset
            $gmt_offset = get_option('gmt_offset', 0);
            $timezone_string = timezone_name_from_abbr('', $gmt_offset * 3600, false);
            if ($timezone_string === false) {
                $timezone_string = 'UTC';
            }
        }

        try {
            $timezone = new DateTimeZone($timezone_string);
            $now = new DateTime('now', $timezone);

            if ($frequency === 'weekly') {
                // Next Monday at specified time
                $target = new DateTime('next monday ' . $time, $timezone);
            } else {
                // Today at specified time
                $target = new DateTime('today ' . $time, $timezone);
                // If already past, use tomorrow
                if ($target <= $now) {
                    $target->modify('+1 day');
                }
            }

            return $target->getTimestamp();
        } catch (Exception $e) {
            // Fallback to server time if timezone operations fail
            if ($frequency === 'weekly') {
                return strtotime('next monday ' . $time);
            } else {
                $timestamp = strtotime('today ' . $time);
                if ($timestamp < time()) {
                    $timestamp = strtotime('tomorrow ' . $time);
                }
                return $timestamp;
            }
        }
    }
    
    /**
     * Unschedule all cron events
     */
    public static function unschedule_events() {
        wp_clear_scheduled_hook(self::CRON_HOOK_INSTANT);
        wp_clear_scheduled_hook(self::CRON_HOOK_FIFTEEN_MIN);
        wp_clear_scheduled_hook(self::CRON_HOOK_HOURLY);
        wp_clear_scheduled_hook(self::CRON_HOOK_DAILY);
        wp_clear_scheduled_hook(self::CRON_HOOK_WEEKLY);
        wp_clear_scheduled_hook(self::CRON_HOOK_CLEANUP);
    }
    
    /**
     * Process instant notifications (every 5 minutes)
     * Uses the unified processor for change detection.
     */
    public static function process_instant_notifications() {
        self::process_with_unified_processor('instant');
    }

    /**
     * Process 15-minute notifications
     * Uses the unified processor for change detection.
     *
     * @since 6.13.0
     */
    public static function process_fifteen_minute_notifications() {
        self::process_with_unified_processor('fifteen_min');
    }

    /**
     * Process hourly notifications
     * Uses the unified processor for change detection.
     */
    public static function process_hourly_notifications() {
        self::process_with_unified_processor('hourly');
    }

    /**
     * Process daily notifications
     * Uses the unified processor for change detection.
     */
    public static function process_daily_notifications() {
        self::process_with_unified_processor('daily');
    }

    /**
     * Process weekly notifications
     * Uses the unified processor for change detection.
     */
    public static function process_weekly_notifications() {
        self::process_with_unified_processor('weekly');
    }

    /**
     * Process notifications using the unified processor
     *
     * The unified processor detects new listings, price changes, and status changes
     * for ALL frequency types using the same change detection logic.
     *
     * @param string $frequency Notification frequency
     * @since 6.13.2
     */
    private static function process_with_unified_processor($frequency) {
        $start_time = microtime(true);

        // Log start
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                'MLD Saved Search Cron: Starting %s notifications at %s',
                $frequency,
                current_time('mysql')
            ));
        }

        // Load the unified processor
        $processor_path = dirname(__FILE__) . '/class-mld-fifteen-minute-processor.php';
        if (file_exists($processor_path)) {
            require_once $processor_path;

            if (class_exists('MLD_Fifteen_Minute_Processor')) {
                $results = MLD_Fifteen_Minute_Processor::process($frequency);

                // Calculate execution time
                $execution_time = round(microtime(true) - $start_time, 2);

                // Log results
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log(sprintf(
                        'MLD Saved Search Cron: Completed %s - Changes=%d, Searches=%d, Sent=%d, Failed=%d, Time=%ss',
                        $frequency,
                        $results['changes_detected'],
                        $results['searches_processed'],
                        $results['sent'],
                        $results['failed'],
                        $execution_time
                    ));
                }

                // Store results for monitoring
                self::store_cron_results($frequency, $results, $execution_time);
                return;
            }
        }

        // Fallback to old system if unified processor not available
        self::process_notifications_legacy($frequency);
    }

    /**
     * Legacy notification processor (fallback only)
     *
     * @param string $frequency Notification frequency
     * @deprecated Use process_with_unified_processor instead
     */
    private static function process_notifications_legacy($frequency) {
        // Start timer
        $start_time = microtime(true);

        // Process notifications using old system
        $results = MLD_Saved_Search_Notifications::send_notifications($frequency);

        // Calculate execution time
        $execution_time = round(microtime(true) - $start_time, 2);

        // Log results
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                'MLD Saved Search Cron (Legacy): Completed %s notifications - Sent: %d, Failed: %d, Searches: %d, Time: %ss',
                $frequency,
                $results['sent'],
                $results['failed'],
                $results['searches_processed'],
                $execution_time
            ));
        }

        // Store results for monitoring
        self::store_cron_results($frequency, $results, $execution_time);
    }
    
    /**
     * Store cron execution results for monitoring
     *
     * @param string $frequency Notification frequency
     * @param array $results Processing results
     * @param float $execution_time Execution time in seconds
     */
    private static function store_cron_results($frequency, $results, $execution_time) {
        $option_name = 'mld_saved_search_cron_results';
        $all_results = get_option($option_name, []);
        
        // Add new result
        $all_results[$frequency] = [
            'last_run' => current_time('mysql'),
            'sent' => $results['sent'],
            'failed' => $results['failed'],
            'searches_processed' => $results['searches_processed'],
            'execution_time' => $execution_time,
            'errors' => array_slice($results['errors'], 0, 10) // Keep only last 10 errors
        ];
        
        // Keep history for last 7 days
        if (!isset($all_results['history'])) {
            $all_results['history'] = [];
        }
        
        $all_results['history'][] = [
            'frequency' => $frequency,
            'timestamp' => current_time('mysql'),
            'sent' => $results['sent'],
            'failed' => $results['failed']
        ];
        
        // Keep only last 100 history entries
        $all_results['history'] = array_slice($all_results['history'], -100);
        
        update_option($option_name, $all_results);
    }
    
    /**
     * Get cron status for all frequencies
     *
     * @return array Cron status information
     */
    public static function get_cron_status() {
        $status = [];

        $hooks = [
            'instant' => self::CRON_HOOK_INSTANT,
            'fifteen_min' => self::CRON_HOOK_FIFTEEN_MIN,
            'hourly' => self::CRON_HOOK_HOURLY,
            'daily' => self::CRON_HOOK_DAILY,
            'weekly' => self::CRON_HOOK_WEEKLY
        ];

        $schedule_labels = [
            'instant' => 'Every 5 minutes',
            'fifteen_min' => 'Every 15 minutes',
            'hourly' => 'Every hour',
            'daily' => 'Once daily at 9 AM',
            'weekly' => 'Weekly on Mondays at 9 AM'
        ];

        foreach ($hooks as $frequency => $hook) {
            $next_run = wp_next_scheduled($hook);
            $status[$frequency] = [
                'hook' => $hook,
                'schedule' => $schedule_labels[$frequency] ?? $frequency,
                'scheduled' => !empty($next_run),
                'next_run' => $next_run ? date('Y-m-d H:i:s', $next_run) : null,
                'next_run_human' => $next_run ? human_time_diff($next_run) : null,
                'next_run_relative' => $next_run ? ($next_run > time() ? 'in ' . human_time_diff($next_run) : 'now') : 'not scheduled'
            ];
        }

        // Add results
        $results = get_option('mld_saved_search_cron_results', []);
        foreach ($status as $frequency => &$data) {
            if (isset($results[$frequency])) {
                $data['last_run'] = $results[$frequency]['last_run'] ?? null;
                $data['last_sent'] = $results[$frequency]['sent'] ?? 0;
                $data['last_failed'] = $results[$frequency]['failed'] ?? 0;
                $data['last_execution_time'] = $results[$frequency]['execution_time'] ?? null;
                $data['last_changes_detected'] = $results[$frequency]['changes_detected'] ?? null;
                $data['last_searches_processed'] = $results[$frequency]['searches_processed'] ?? null;
            }
        }

        return $status;
    }
    
    /**
     * Process digest notifications through unified dispatcher
     *
     * @param string $frequency Notification frequency
     * @param array $cron_results Results from regular cron processing
     * @return array|null Digest processing results
     */
    private static function process_digest_notifications($frequency, $cron_results) {
        // Only process digests for specific frequencies
        $digest_frequencies = ['hourly', 'daily', 'weekly'];
        if (!in_array($frequency, $digest_frequencies)) {
            return null;
        }

        // Check if digest notifications are enabled
        $digest_enabled = get_option('mld_digest_notifications_enabled', false);
        if (!$digest_enabled) {
            return null;
        }

        // Load unified dispatcher
        if (!class_exists('MLD_Notification_Dispatcher')) {
            require_once MLD_PLUGIN_PATH . 'includes/class-mld-notification-dispatcher.php';
        }

        global $wpdb;

        // Get users with digest-enabled searches for this frequency
        $digest_searches = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT user_id, id, name, filters
             FROM {$wpdb->prefix}mld_saved_searches
             WHERE notification_frequency = %s
             AND digest_enabled = 1
             AND is_active = 1",
            $frequency
        ));

        if (empty($digest_searches)) {
            return null;
        }

        // Group searches by user
        $users_searches = [];
        foreach ($digest_searches as $search) {
            $users_searches[$search->user_id][] = $search;
        }

        // Prepare search results for digest
        $search_results = [];
        foreach ($users_searches as $user_id => $user_searches) {
            $user_properties = [];

            foreach ($user_searches as $search) {
                // Get new properties for this search from the last period
                $new_properties = self::get_new_properties_for_digest($search, $frequency);
                if (!empty($new_properties)) {
                    $user_properties = array_merge($user_properties, $new_properties);
                }
            }

            if (!empty($user_properties)) {
                $search_results[$user_id] = array_unique($user_properties, SORT_REGULAR);
            }
        }

        // Send digest notifications
        $dispatcher = MLD_Notification_Dispatcher::get_instance();
        return $dispatcher->dispatch_bulk_notifications($frequency, $search_results, array_keys($search_results));
    }

    /**
     * Get new properties for digest notification
     */
    private static function get_new_properties_for_digest($search, $frequency) {
        global $wpdb;

        // Calculate time window based on frequency
        $interval_map = [
            'hourly' => '1 HOUR',
            'daily' => '10 MINUTE',  // Now runs every 10 minutes
            'weekly' => '1 WEEK'
        ];

        $interval = $interval_map[$frequency] ?? '10 MINUTE';

        // Get properties that match this search and were added/updated in the time window
        $new_properties = MLD_Saved_Search_Notifications::get_new_properties_for_search($search);

        // Filter to only properties added/updated in the digest time window
        $filtered_properties = [];
        // Use WordPress timezone-aware time instead of MySQL NOW()
        $wp_now = current_time('mysql');
        foreach ($new_properties as $property) {
            $listing_id = $property['ListingId'] ?? $property['listing_id'] ?? '';

            // Check if this property was added in our time window
            // Note: $interval is safe as it comes from internal $interval_map, not user input
            $added_recently = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}bme_listings
                 WHERE listing_id = %s
                 AND (creation_timestamp >= DATE_SUB(%s, INTERVAL $interval)
                      OR modification_timestamp >= DATE_SUB(%s, INTERVAL $interval))",
                $listing_id, $wp_now, $wp_now
            ));

            if ($added_recently > 0) {
                $filtered_properties[] = $property;
            }
        }

        return $filtered_properties;
    }

    /**
     * Run cron manually (for testing)
     *
     * @param string $frequency Notification frequency
     * @return array Processing results
     */
    public static function run_manual($frequency) {
        // Remove time limit for manual runs
        set_time_limit(0);

        // Process notifications
        return MLD_Saved_Search_Notifications::send_notifications($frequency);
    }

    /**
     * Cleanup soft-deleted searches older than 30 days
     *
     * Runs daily at 3 AM. Permanently removes saved searches that have been
     * soft-deleted (is_active = 0) for more than 30 days. This ensures GDPR
     * compliance by not retaining user data indefinitely after deletion.
     *
     * @since 6.31.2
     * @return array Results of the cleanup operation
     */
    public static function cleanup_deleted_searches() {
        global $wpdb;

        $start_time = microtime(true);
        $table = $wpdb->prefix . 'mld_saved_searches';

        // Find searches that have been soft-deleted for more than 30 days
        $deleted_count = $wpdb->query(
            "DELETE FROM {$table}
             WHERE is_active = 0
             AND updated_at < DATE_SUB(NOW(), INTERVAL 30 DAY)"
        );

        $execution_time = round(microtime(true) - $start_time, 2);

        // Log results
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                'MLD Saved Search Cleanup: Removed %d soft-deleted searches older than 30 days (Time: %ss)',
                $deleted_count !== false ? $deleted_count : 0,
                $execution_time
            ));
        }

        // Store result for monitoring
        $result = [
            'deleted_count' => $deleted_count !== false ? $deleted_count : 0,
            'execution_time' => $execution_time,
            'last_run' => current_time('mysql'),
            'error' => $deleted_count === false ? $wpdb->last_error : null
        ];

        update_option('mld_saved_search_cleanup_result', $result);

        return $result;
    }
}

// Register custom cron schedules
add_filter('cron_schedules', function($schedules) {
    $schedules['mld_five_minutes'] = [
        'interval' => 300, // 5 minutes in seconds
        'display' => __('Every 5 Minutes', 'mld')
    ];
    $schedules['mld_ten_minutes'] = [
        'interval' => 600, // 10 minutes in seconds
        'display' => __('Every 10 Minutes', 'mld')
    ];
    $schedules['mld_fifteen_minutes'] = [
        'interval' => 900, // 15 minutes in seconds
        'display' => __('Every 15 Minutes', 'mld')
    ];
    return $schedules;
});