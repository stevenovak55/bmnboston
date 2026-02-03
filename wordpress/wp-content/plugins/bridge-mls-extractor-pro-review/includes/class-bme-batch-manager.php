<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Bridge MLS Batch Manager
 * Handles intelligent batching with 1000-listing sessions to prevent timeouts
 * Version: 1.0.3 (Added proactive stuck extraction detection)
 *
 * Changes in 1.0.3:
 * - Added proactive detection of stuck mid-session extractions
 * - Fallback check now scans ALL batch extractions for stuck states
 * - Auto-recovers extractions stuck in running_session with no lock
 *
 * Changes in 1.0.2:
 * - Added retry scheduling with verification
 * - Added immediate cron ping backup trigger
 * - Extended lock during session waiting periods
 * - Added progress updates during waiting to prevent stale detection
 */
class BME_Batch_Manager {
    
    // Batch configuration constants
    const MAX_SESSION_LISTINGS = 1000;        // Stop sessions after 1000 listings
    const MAX_API_CALL_SIZE = 200;            // Bridge API limit per call
    const MIN_API_CALL_SIZE = 50;             // Minimum for efficiency
    const SESSION_BREAK_MINUTES = 1;          // Wait between sessions
    const PREVIEW_SAMPLE_SIZE = 5;            // For counting available listings
    
    // Performance estimates
    const AVG_PROCESSING_TIME_PER_LISTING = 0.1;  // seconds
    const AVG_API_CALL_TIME = 2.0;               // seconds per API call
    const SESSION_OVERHEAD_TIME = 10;             // seconds setup per session
    
    private $api_client;
    private $extraction_id;
    private $extraction_config;
    
    public function __construct(BME_API_Client $api_client) {
        $this->api_client = $api_client;
    }
    
    /**
     * Get extraction preview with batch execution plan
     */
    public function get_extraction_preview($extraction_id, $config = null) {
        $this->extraction_id = $extraction_id;
        $this->extraction_config = $config ?: $this->get_extraction_config($extraction_id);
        
        try {
            // Get total available listings count
            $total_available = $this->get_total_available_listings();
            
            // Calculate batch execution plan
            $plan = $this->calculate_batch_plan($total_available);
            
            return [
                'success' => true,
                'total_available_listings' => $total_available,
                'batch_plan' => $plan,
                'config_summary' => $this->get_config_summary()
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get total count of available listings for extraction
     */
    private function get_total_available_listings() {
        // Check API credentials first
        $api_token = $this->get_api_token();
        $credentials = get_option('bme_pro_api_credentials', []);
        $base_url = $credentials['endpoint_url'] ?? null;
        
        if (empty($api_token) || empty($base_url)) {
            throw new Exception('API credentials not configured. Please check your settings.');
        }
        
        // Build filter query
        $filter_query = $this->api_client->build_filter_query($this->extraction_config);
        
        // Make a test call with $count=true and $top=1 to get total count
        $query_args = [
            'access_token' => $api_token,
            '$filter' => $filter_query,
            '$count' => 'true',
            '$top' => 1
        ];
        
        $test_url = add_query_arg($query_args, $base_url);
        
        $response = wp_remote_get($test_url, [
            'timeout' => 30,
            'headers' => ['Accept' => 'application/json']
        ]);
        
        if (is_wp_error($response)) {
            throw new Exception('Failed to connect to Bridge API: ' . $response->get_error_message());
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        if ($response_code !== 200) {
            throw new Exception("API request failed with code {$response_code}. Please check your API credentials.");
        }
        
        $data = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON response from Bridge API: ' . json_last_error_msg());
        }
        
        if (isset($data['error'])) {
            $error_msg = $data['error']['message'] ?? 'Unknown API error';
            throw new Exception('Bridge API Error: ' . $error_msg);
        }
        
        // Return the count from @odata.count
        return (int)($data['@odata.count'] ?? 0);
    }
    
    /**
     * Calculate detailed batch execution plan
     */
    private function calculate_batch_plan($total_listings) {
        if ($total_listings === 0) {
            return [
                'total_sessions' => 0,
                'total_api_calls' => 0,
                'estimated_duration_minutes' => 0,
                'sessions' => []
            ];
        }
        
        // Calculate sessions needed
        $total_sessions = ceil($total_listings / self::MAX_SESSION_LISTINGS);
        
        // Calculate API calls per session
        $api_calls_per_session = ceil(self::MAX_SESSION_LISTINGS / self::MAX_API_CALL_SIZE);
        $total_api_calls = $total_sessions * $api_calls_per_session;
        
        // Estimate timing
        $estimated_duration = $this->calculate_estimated_duration($total_listings, $total_sessions, $total_api_calls);
        
        // Build session details
        $sessions = [];
        $remaining_listings = $total_listings;
        
        for ($i = 1; $i <= $total_sessions; $i++) {
            $session_listings = min(self::MAX_SESSION_LISTINGS, $remaining_listings);
            $session_api_calls = ceil($session_listings / self::MAX_API_CALL_SIZE);
            $session_duration = $this->calculate_session_duration($session_listings, $session_api_calls);
            
            $sessions[] = [
                'session_number' => $i,
                'listings_in_session' => $session_listings,
                'api_calls_in_session' => $session_api_calls,
                'estimated_duration_minutes' => round($session_duration, 1),
                'break_after_session_minutes' => ($i < $total_sessions) ? self::SESSION_BREAK_MINUTES : 0
            ];
            
            $remaining_listings -= $session_listings;
        }
        
        return [
            'total_sessions' => $total_sessions,
            'total_api_calls' => $total_api_calls,
            'estimated_duration_minutes' => round($estimated_duration, 1),
            'max_listings_per_session' => self::MAX_SESSION_LISTINGS,
            'max_listings_per_api_call' => self::MAX_API_CALL_SIZE,
            'break_between_sessions_minutes' => self::SESSION_BREAK_MINUTES,
            'sessions' => $sessions
        ];
    }
    
    /**
     * Calculate estimated total duration in minutes
     */
    private function calculate_estimated_duration($total_listings, $total_sessions, $total_api_calls) {
        // Base processing time
        $processing_time = $total_listings * self::AVG_PROCESSING_TIME_PER_LISTING;
        
        // API call time
        $api_time = $total_api_calls * self::AVG_API_CALL_TIME;
        
        // Session overhead
        $session_overhead = $total_sessions * self::SESSION_OVERHEAD_TIME;
        
        // Break time between sessions
        $break_time = max(0, ($total_sessions - 1)) * self::SESSION_BREAK_MINUTES * 60;
        
        $total_seconds = $processing_time + $api_time + $session_overhead + $break_time;
        
        return $total_seconds / 60; // Convert to minutes
    }
    
    /**
     * Calculate estimated duration for a single session
     */
    private function calculate_session_duration($listings, $api_calls) {
        $processing_time = $listings * self::AVG_PROCESSING_TIME_PER_LISTING;
        $api_time = $api_calls * self::AVG_API_CALL_TIME;
        $overhead = self::SESSION_OVERHEAD_TIME;
        
        return ($processing_time + $api_time + $overhead) / 60; // Convert to minutes
    }
    
    /**
     * Get configuration summary for display
     */
    private function get_config_summary() {
        $config = $this->extraction_config;
        
        $summary = [];
        
        // Statuses
        if (!empty($config['statuses'])) {
            $summary['listing_statuses'] = implode(', ', $config['statuses']);
        }
        
        // Geographic filters
        if (!empty($config['cities'])) {
            $cities = is_string($config['cities']) ? 
                array_filter(array_map('trim', explode(',', $config['cities']))) : 
                $config['cities'];
            $summary['cities'] = implode(', ', array_slice($cities, 0, 3)) . 
                (count($cities) > 3 ? ' (and ' . (count($cities) - 3) . ' more)' : '');
        }
        
        if (!empty($config['states'])) {
            $summary['states'] = implode(', ', $config['states']);
        }
        
        // Property types
        if (!empty($config['property_types'])) {
            $summary['property_types'] = implode(', ', $config['property_types']);
        }
        
        // Agent filters
        if (!empty($config['list_agent_id'])) {
            $summary['list_agent'] = $config['list_agent_id'];
        }
        
        if (!empty($config['buyer_agent_id'])) {
            $summary['buyer_agent'] = $config['buyer_agent_id'];
        }
        
        // Date range
        if (!empty($config['closed_lookback_months'])) {
            $summary['lookback_period'] = $config['closed_lookback_months'] . ' months';
        }
        
        return $summary;
    }
    
    /**
     * Start batch extraction with session management
     */
    public function start_batch_extraction($extraction_id, $is_resync = false) {
        $this->extraction_id = $extraction_id;
        
        // Initialize batch state
        $this->init_batch_state($is_resync);
        
        // Start first session
        return $this->start_next_session();
    }
    
    /**
     * Initialize batch extraction state
     */
    private function init_batch_state($is_resync) {
        $state = [
            'is_batch_extraction' => true,
            'is_resync' => $is_resync,
            'current_session' => 1,
            'total_processed_all_sessions' => 0,
            'session_processed_count' => 0,
            'last_modified_checkpoint' => null,
            'last_close_date_checkpoint' => null,
            'started_at' => current_time('mysql'),
            'status' => 'starting'
        ];
        
        update_post_meta($this->extraction_id, '_bme_batch_state', $state);
        
        // Clear any existing extraction state
        delete_post_meta($this->extraction_id, '_bme_extraction_state');
        
        error_log("BME Batch: Initialized batch extraction for ID {$this->extraction_id}");
    }
    
    /**
     * Start the next batch session
     */
    public function start_next_session($extraction_id = null) {
        $this->extraction_id = $extraction_id ?: $this->extraction_id;
        
        $batch_state = get_post_meta($this->extraction_id, '_bme_batch_state', true);
        
        if (!$batch_state) {
            throw new Exception('Batch state not found');
        }
        
        // Update session state
        $batch_state['status'] = 'running_session';
        $batch_state['session_started_at'] = current_time('mysql');
        $batch_state['session_processed_count'] = 0;
        
        update_post_meta($this->extraction_id, '_bme_batch_state', $batch_state);
        
        error_log("BME Batch: Starting session {$batch_state['current_session']} for extraction {$this->extraction_id}");
        
        return true;
    }
    
    /**
     * Check if current session should end
     */
    public function should_end_session($processed_in_session) {
        return $processed_in_session >= self::MAX_SESSION_LISTINGS;
    }
    
    /**
     * End current session and schedule next if needed
     */
    public function end_session($processed_in_session, $total_processed, $last_modified = null, $last_close_date = null) {
        $batch_state = get_post_meta($this->extraction_id, '_bme_batch_state', true);
        
        if (!$batch_state) {
            error_log("BME Batch: No batch state found when ending session");
            return false;
        }
        
        // Update batch state
        $batch_state['session_processed_count'] = $processed_in_session;
        $batch_state['total_processed_all_sessions'] += $processed_in_session;
        $batch_state['status'] = 'session_completed';
        $batch_state['session_completed_at'] = current_time('mysql');
        
        // Update checkpoints
        if ($last_modified) {
            $batch_state['last_modified_checkpoint'] = $last_modified;
        }
        if ($last_close_date) {
            $batch_state['last_close_date_checkpoint'] = $last_close_date;
        }
        
        update_post_meta($this->extraction_id, '_bme_batch_state', $batch_state);
        
        error_log("BME Batch: Completed session {$batch_state['current_session']} - processed {$processed_in_session} listings");
        
        // Check if we should start another session
        if ($processed_in_session >= self::MAX_SESSION_LISTINGS) {
            // Schedule next session
            $this->schedule_next_session();
            return 'continue';
        } else {
            // Extraction is complete
            $this->complete_batch_extraction();
            return 'complete';
        }
    }
    
    /**
     * Schedule the next batch session
     *
     * Implements multiple reliability mechanisms:
     * 1. Retry scheduling up to 3 times with verification
     * 2. Extend lock during waiting period
     * 3. Update progress timestamp to prevent stale detection
     * 4. Multiple cron trigger methods for maximum reliability
     *
     * @since 1.0.2
     */
    private function schedule_next_session() {
        $batch_state = get_post_meta($this->extraction_id, '_bme_batch_state', true);

        // Increment session number
        $batch_state['current_session']++;
        $batch_state['status'] = 'waiting_for_next_session';
        $batch_state['next_session_scheduled_for'] = wp_date('Y-m-d H:i:s', current_time('timestamp') + (self::SESSION_BREAK_MINUTES * 60));

        update_post_meta($this->extraction_id, '_bme_batch_state', $batch_state);

        // Extend lock and update progress during waiting period
        $this->extend_waiting_lock($this->extraction_id);
        $this->update_waiting_progress($this->extraction_id);

        // Schedule next session with retry logic
        $next_run_time = time() + (self::SESSION_BREAK_MINUTES * 60);
        $max_retries = 3;
        $scheduled = false;

        for ($attempt = 1; $attempt <= $max_retries; $attempt++) {
            // Clear any existing scheduled event first to prevent duplicates
            wp_clear_scheduled_hook('bme_continue_batch_extraction', [$this->extraction_id]);

            // Schedule the event
            wp_schedule_single_event($next_run_time, 'bme_continue_batch_extraction', [$this->extraction_id]);

            // Verify it was actually scheduled
            $verified = wp_next_scheduled('bme_continue_batch_extraction', [$this->extraction_id]);

            if ($verified) {
                $scheduled = true;
                error_log("BME Batch: Session {$batch_state['current_session']} scheduled (attempt {$attempt}), verified at timestamp {$verified} for extraction {$this->extraction_id}");
                break;
            }

            error_log("BME Batch: Scheduling attempt {$attempt} failed for extraction {$this->extraction_id}, retrying...");
            usleep(100000); // 100ms delay before retry
        }

        if (!$scheduled) {
            error_log("BME Batch: All scheduling attempts failed for extraction {$this->extraction_id}, using fallback");
            $this->trigger_cron_fallback($next_run_time);
        }

        // Multiple cron trigger methods for maximum reliability
        spawn_cron($next_run_time);
        $this->trigger_immediate_cron_ping();

        // Update live progress for UI
        $this->update_batch_progress($batch_state, 'Session completed. Next session starts in ' . self::SESSION_BREAK_MINUTES . ' minute(s).');
    }

    /**
     * Trigger immediate non-blocking ping to wp-cron.php
     *
     * This ensures the cron event fires even if no page visits occur.
     * Uses non-blocking request to avoid delays.
     *
     * @since 1.0.2
     */
    private function trigger_immediate_cron_ping() {
        $cron_url = site_url('wp-cron.php?doing_wp_cron=' . sprintf('%.22F', microtime(true)));

        wp_remote_post($cron_url, [
            'timeout' => 0.01,
            'blocking' => false,
            'sslverify' => apply_filters('https_local_ssl_verify', false),
            'headers' => [
                'Cache-Control' => 'no-cache',
            ],
        ]);

        error_log("BME Batch: Triggered immediate cron ping for extraction {$this->extraction_id}");
    }

    /**
     * Extend the extraction lock during waiting period
     *
     * Prevents the lock from expiring while waiting for next session.
     * Sets lock to 10 minutes to cover session break + potential delays.
     *
     * @param int $extraction_id The extraction profile post ID
     * @since 1.0.2
     */
    private function extend_waiting_lock($extraction_id) {
        // Set lock for 10 minutes (longer than session break + fallback check interval)
        set_transient('bme_extraction_lock_' . $extraction_id, time(), 600);
        error_log("BME Batch: Extended lock for extraction {$extraction_id} during waiting period");
    }

    /**
     * Update progress timestamp during waiting period
     *
     * Prevents stale progress detection from clearing the lock
     * while extraction is legitimately waiting for next session.
     *
     * @param int $extraction_id The extraction profile post ID
     * @since 1.0.2
     */
    private function update_waiting_progress($extraction_id) {
        $progress_key = 'bme_live_progress_' . $extraction_id;
        $progress = get_transient($progress_key);

        if ($progress) {
            $progress['last_update_timestamp'] = time();
            $progress['status'] = 'waiting_for_next_session';
            $progress['last_message'] = 'Waiting for next session to start...';
            set_transient($progress_key, $progress, HOUR_IN_SECONDS);
        }
    }
    
    /**
     * Complete batch extraction
     */
    private function complete_batch_extraction() {
        $batch_state = get_post_meta($this->extraction_id, '_bme_batch_state', true);

        $batch_state['status'] = 'completed';
        $batch_state['completed_at'] = current_time('mysql');

        update_post_meta($this->extraction_id, '_bme_batch_state', $batch_state);

        // Clear any scheduled events
        $this->clear_scheduled_sessions();

        error_log("BME Batch: Completed batch extraction {$this->extraction_id} - total processed: {$batch_state['total_processed_all_sessions']}");

        // v4.0.14: Summary table refresh removed - now written in real-time during extraction
        // See process_listing_summary() in class-bme-data-processor.php
        error_log("BME Batch: Summary table was populated in real-time during extraction");

        // Update live progress for UI
        $this->update_batch_progress($batch_state, 'Batch extraction completed successfully.');
    }
    
    /**
     * Update live progress with batch information
     */
    private function update_batch_progress($batch_state, $message) {
        $progress_key = 'bme_live_progress_' . $this->extraction_id;
        
        $progress_data = [
            'status' => 'running',
            'is_batch_extraction' => true,
            'current_session' => $batch_state['current_session'],
            'session_processed' => $batch_state['session_processed_count'] ?? 0,
            'total_processed_current_run' => $batch_state['total_processed_all_sessions'] ?? 0,
            'last_message' => $message,
            'last_update_timestamp' => time(),
            'batch_status' => $batch_state['status']
        ];
        
        if ($batch_state['status'] === 'completed') {
            $progress_data['status'] = 'completed';
        }
        
        set_transient($progress_key, $progress_data, HOUR_IN_SECONDS);
    }
    
    /**
     * Get current batch status
     */
    public function get_batch_status($extraction_id) {
        return get_post_meta($extraction_id, '_bme_batch_state', true);
    }
    
    /**
     * Check if extraction is currently in batch mode
     */
    public function is_batch_extraction($extraction_id) {
        $batch_state = get_post_meta($extraction_id, '_bme_batch_state', true);
        return !empty($batch_state['is_batch_extraction']);
    }
    
    /**
     * Cancel batch extraction
     */
    public function cancel_batch_extraction($extraction_id) {
        // Clear scheduled events
        $this->clear_scheduled_sessions($extraction_id);
        
        // Update batch state
        $batch_state = get_post_meta($extraction_id, '_bme_batch_state', true);
        if ($batch_state) {
            $batch_state['status'] = 'canceled';
            $batch_state['canceled_at'] = current_time('mysql');
            update_post_meta($extraction_id, '_bme_batch_state', $batch_state);
        }
        
        error_log("BME Batch: Canceled batch extraction {$extraction_id}");
    }
    
    /**
     * Trigger cron fallback if WordPress cron fails
     */
    private function trigger_cron_fallback($scheduled_time) {
        // Store a fallback trigger in options
        $fallbacks = get_option('bme_batch_fallback_triggers', []);
        $fallbacks[$this->extraction_id] = [
            'scheduled_time' => $scheduled_time,
            'created_at' => time()
        ];
        update_option('bme_batch_fallback_triggers', $fallbacks);
        
        error_log("BME Batch: Added fallback trigger for extraction {$this->extraction_id}");
    }
    
    /**
     * Check and execute fallback triggers
     * Also proactively scans for stuck extractions
     */
    public static function check_fallback_triggers() {
        $fallbacks = get_option('bme_batch_fallback_triggers', []);
        $current_time = time();
        $executed = 0;

        // First, check explicit fallback triggers
        foreach ($fallbacks as $extraction_id => $trigger) {
            if ($current_time >= $trigger['scheduled_time']) {
                // Execute the continuation
                error_log("BME Batch: Executing fallback trigger for extraction {$extraction_id}");

                try {
                    $batch_processor = new BME_Background_Processor(bme_pro()->get('extractor'));
                    $batch_processor->continue_batch_extraction($extraction_id);
                    $executed++;
                } catch (Exception $e) {
                    error_log("BME Batch: Fallback trigger failed for extraction {$extraction_id}: " . $e->getMessage());
                }

                // Remove the executed trigger
                unset($fallbacks[$extraction_id]);
            }
        }

        // Clean up old triggers (older than 2 hours)
        $cutoff = $current_time - (2 * HOUR_IN_SECONDS);
        foreach ($fallbacks as $extraction_id => $trigger) {
            if ($trigger['created_at'] < $cutoff) {
                unset($fallbacks[$extraction_id]);
            }
        }

        update_option('bme_batch_fallback_triggers', $fallbacks);

        // Now proactively check for stuck extractions (v1.0.3)
        $stuck_recovered = self::recover_stuck_extractions();
        $executed += $stuck_recovered;

        if ($executed > 0) {
            error_log("BME Batch: Executed {$executed} fallback/recovery triggers");
        }

        return $executed;
    }

    /**
     * Proactively detect and recover stuck extractions
     * Scans all batch extractions for ones that are stuck mid-session
     *
     * @since 1.0.3
     * @return int Number of extractions recovered
     */
    public static function recover_stuck_extractions() {
        $recovered = 0;
        $current_time = time();

        // Get all extraction profiles
        $extractions = get_posts([
            'post_type' => 'bme_extraction',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'fields' => 'ids'
        ]);

        foreach ($extractions as $extraction_id) {
            $batch_state = get_post_meta($extraction_id, '_bme_batch_state', true);

            // Skip if not a batch extraction or already completed/canceled
            if (empty($batch_state) || !$batch_state['is_batch_extraction']) {
                continue;
            }

            $status = $batch_state['status'] ?? '';

            // Skip completed, canceled, or error states
            if (in_array($status, ['completed', 'canceled', 'error', ''])) {
                continue;
            }

            // Check if extraction is stuck
            $is_stuck = false;
            $stuck_reason = '';

            // Check for lock
            $has_lock = (bool) get_transient('bme_extraction_lock_' . $extraction_id);

            // Check for scheduled continuation
            $has_scheduled = (bool) wp_next_scheduled('bme_continue_batch_extraction', [$extraction_id]);

            // Check progress freshness
            $progress = get_transient('bme_live_progress_' . $extraction_id);
            $last_update = $progress['last_update_timestamp'] ?? 0;
            $progress_stale = ($current_time - $last_update) > 300; // 5 minutes

            // Detect stuck conditions
            if ($status === 'running_session') {
                // Running but no lock = crashed mid-session
                if (!$has_lock && $progress_stale) {
                    $is_stuck = true;
                    $stuck_reason = 'running_session with no lock and stale progress';
                }
            } elseif ($status === 'waiting_for_next_session') {
                // Waiting but no scheduled continuation and past scheduled time
                $scheduled_time = strtotime($batch_state['next_session_scheduled_for'] ?? '');
                if (!$has_scheduled && $scheduled_time && $current_time > ($scheduled_time + 120)) {
                    $is_stuck = true;
                    $stuck_reason = 'waiting_for_next_session but no scheduled event and past due';
                }
            }

            // Recover stuck extraction
            if ($is_stuck) {
                error_log("BME Batch: Detected stuck extraction {$extraction_id} ({$stuck_reason})");

                try {
                    // Reset state to waiting
                    $batch_state['status'] = 'waiting_for_next_session';
                    $batch_state['next_session_scheduled_for'] = date('Y-m-d H:i:s', $current_time + 30);
                    $batch_state['recovery_count'] = ($batch_state['recovery_count'] ?? 0) + 1;
                    $batch_state['last_recovery'] = current_time('mysql');
                    update_post_meta($extraction_id, '_bme_batch_state', $batch_state);

                    // Set a fresh lock
                    set_transient('bme_extraction_lock_' . $extraction_id, $current_time, 600);

                    // Update progress
                    if ($progress) {
                        $progress['status'] = 'waiting_for_next_session';
                        $progress['last_update_timestamp'] = $current_time;
                        $progress['last_message'] = 'Auto-recovered from stuck state. Resuming...';
                        set_transient('bme_live_progress_' . $extraction_id, $progress, HOUR_IN_SECONDS);
                    }

                    // Schedule continuation
                    wp_clear_scheduled_hook('bme_continue_batch_extraction', [$extraction_id]);
                    wp_schedule_single_event($current_time + 30, 'bme_continue_batch_extraction', [$extraction_id]);
                    spawn_cron();

                    error_log("BME Batch: Successfully recovered extraction {$extraction_id}, scheduled in 30 seconds");
                    $recovered++;

                } catch (Exception $e) {
                    error_log("BME Batch: Failed to recover extraction {$extraction_id}: " . $e->getMessage());
                }
            }
        }

        return $recovered;
    }
    
    /**
     * Clear scheduled batch sessions
     */
    private function clear_scheduled_sessions($extraction_id = null) {
        $id = $extraction_id ?: $this->extraction_id;
        
        // Clear any scheduled continuation events
        $timestamp = wp_next_scheduled('bme_continue_batch_extraction', [$id]);
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'bme_continue_batch_extraction', [$id]);
        }
        
        // Also clear fallback triggers
        $fallbacks = get_option('bme_batch_fallback_triggers', []);
        if (isset($fallbacks[$id])) {
            unset($fallbacks[$id]);
            update_option('bme_batch_fallback_triggers', $fallbacks);
        }
    }
    
    /**
     * Get extraction configuration
     */
    private function get_extraction_config($extraction_id, $is_resync = false) {
        return [
            'extraction_id' => $extraction_id,
            'is_resync' => $is_resync,
            'statuses' => get_post_meta($extraction_id, '_bme_statuses', true) ?: [],
            'property_types' => get_post_meta($extraction_id, '_bme_property_types', true) ?: [],
            'cities' => get_post_meta($extraction_id, '_bme_cities', true),
            'states' => get_post_meta($extraction_id, '_bme_states', true) ?: [],
            'list_agent_id' => get_post_meta($extraction_id, '_bme_list_agent_id', true),
            'buyer_agent_id' => get_post_meta($extraction_id, '_bme_buyer_agent_id', true),
            'closed_lookback_months' => get_post_meta($extraction_id, '_bme_lookback_months', true) ?: 12,
            'last_modified' => get_post_meta($extraction_id, '_bme_last_modified', true) ?: '1970-01-01T00:00:00Z'
        ];
    }
    
    /**
     * Get API token
     */
    private function get_api_token() {
        $credentials = get_option('bme_pro_api_credentials', []);
        return $credentials['server_token'] ?? null;
    }
    
    /**
     * Get optimal API call size based on current conditions
     */
    public function get_optimal_api_call_size($current_memory_usage = null) {
        $memory_usage = $current_memory_usage ?: (memory_get_usage(true) / (1024 * 1024));
        
        // Start with maximum allowed
        $size = self::MAX_API_CALL_SIZE;
        
        // Reduce size based on memory pressure
        if ($memory_usage > 400) { // Over 400MB
            $size = 100;
        } elseif ($memory_usage > 300) { // Over 300MB
            $size = 150;
        }
        
        return max(self::MIN_API_CALL_SIZE, $size);
    }
}