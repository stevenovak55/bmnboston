<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Background Processor for Large Extractions
 * Handles extraction in background using WordPress cron to avoid timeouts
 * Version: 1.0.2 (Improved stale detection for batch waiting state)
 *
 * Changes in 1.0.2:
 * - Fixed stale detection to not clear locks during batch waiting period
 * - Prevents false stale detection between batch sessions
 */
class BME_Background_Processor {
    
    const BACKGROUND_HOOK = 'bme_background_extraction';
    const BATCH_CONTINUE_HOOK = 'bme_continue_batch_extraction';
    const LOCK_DURATION = 300; // 5 minutes lock duration
    
    private $extraction_engine;
    
    public function __construct($extraction_engine) {
        $this->extraction_engine = $extraction_engine;
        
        // Register background processing hooks
        add_action(self::BACKGROUND_HOOK, [$this, 'process_background_extraction'], 10, 2);
        add_action(self::BATCH_CONTINUE_HOOK, [$this, 'continue_batch_extraction'], 10, 1);
    }
    
    /**
     * Start a background extraction
     */
    public function start_background_extraction($extraction_id, $is_resync = false) {
        // Check if extraction is already running
        if ($this->is_extraction_running($extraction_id)) {
            error_log("BME Background: Extraction {$extraction_id} is already running");
            return false;
        }
        
        // Set lock
        $this->set_extraction_lock($extraction_id);
        
        // Initialize live progress immediately so UI shows it's running
        $progress_key = 'bme_live_progress_' . $extraction_id;
        $initial_data = [
            'status' => 'running',
            'total_processed_current_run' => 0,
            'current_listing_mls_id' => '',
            'current_listing_address' => '',
            'property_subtype_counts' => [],
            'last_update_timestamp' => time(),
            'last_message' => $is_resync ? 'Full resync starting in background...' : 'Extraction starting in background...',
            'extraction_start_time' => microtime(true),
            'error_message' => '',
            'is_background' => true
        ];
        set_transient($progress_key, $initial_data, HOUR_IN_SECONDS);
        
        // Update extraction status
        update_post_meta($extraction_id, '_bme_last_run_status', 'Starting');
        
        // Schedule immediate background job
        $args = [$extraction_id, $is_resync];
        
        // Use wp_schedule_single_event for immediate execution
        wp_schedule_single_event(time(), self::BACKGROUND_HOOK, $args);
        
        // Also spawn cron to ensure it runs immediately if possible
        spawn_cron();
        
        error_log("BME Background: Scheduled background extraction for ID {$extraction_id}");
        
        return true;
    }
    
    /**
     * Process extraction in background
     */
    public function process_background_extraction($extraction_id, $is_resync = false) {
        // Prevent timeout in background processing with proper error handling
        $time_limit_set = set_time_limit(0);
        if ($time_limit_set === false) {
            error_log("BME: Warning - Could not set time limit for extraction {$extraction_id}");
        }
        
        $old_memory_limit = ini_get('memory_limit');
        $current_limit = wp_convert_hr_to_bytes($old_memory_limit);
        $required_limit = wp_convert_hr_to_bytes('512M');

        if ($current_limit < $required_limit) {
            $memory_limit_set = ini_set('memory_limit', '512M');
            if ($memory_limit_set === false) {
                error_log("BME: Warning - Could not increase memory limit for extraction {$extraction_id}, using {$old_memory_limit}");
            }
        }
        
        error_log("BME Background: Starting background extraction for ID {$extraction_id}");
        
        try {
            // Check lock
            if (!$this->is_extraction_running($extraction_id)) {
                error_log("BME Background: Lock expired for extraction {$extraction_id}");
                return;
            }
            
            // Refresh lock
            $this->set_extraction_lock($extraction_id);
            
            // Run extraction with resume capability
            $result = $this->extraction_engine->run_extraction($extraction_id, $is_resync, false);
            
            // Check if extraction was paused (not completed)
            $state = get_post_meta($extraction_id, '_bme_extraction_state', true);
            
            if ($state && in_array($state['status'] ?? '', ['paused_memory', 'paused_time', 'paused_errors'])) {
                // Schedule continuation
                error_log("BME Background: Extraction {$extraction_id} paused - scheduling continuation");
                
                // Wait a bit before retrying
                $retry_delay = $this->get_retry_delay($state['status']);
                wp_schedule_single_event(time() + $retry_delay, self::BACKGROUND_HOOK, [$extraction_id, false]);
                
                // Update status for UI
                update_post_meta($extraction_id, '_bme_last_run_status', 'Paused - Will Resume');
            } else {
                // Extraction completed or failed
                error_log("BME Background: Extraction {$extraction_id} completed");
                $this->clear_extraction_lock($extraction_id);

                // v4.0.14: Summary table refresh removed - now written in real-time during extraction
                // See process_listing_summary() in class-bme-data-processor.php
                error_log("BME Background: Summary table was populated in real-time during extraction");
            }
            
        } catch (Exception $e) {
            error_log("BME Background: Error in extraction {$extraction_id}: " . $e->getMessage());
            $this->clear_extraction_lock($extraction_id);
            
            // Update status
            update_post_meta($extraction_id, '_bme_last_run_status', 'Failed');
            update_post_meta($extraction_id, '_bme_last_error', $e->getMessage());
        }
    }
    
    /**
     * Continue batch extraction (called by WordPress cron)
     */
    public function continue_batch_extraction($extraction_id) {
        error_log("BME Background: Continuing batch extraction for ID {$extraction_id}");
        
        // Prevent timeout in background processing with proper error handling
        $time_limit_set = set_time_limit(0);
        if ($time_limit_set === false) {
            error_log("BME: Warning - Could not set time limit for batch extraction {$extraction_id}");
        }
        
        $old_memory_limit = ini_get('memory_limit');
        $current_limit = wp_convert_hr_to_bytes($old_memory_limit);
        $required_limit = wp_convert_hr_to_bytes('512M');

        if ($current_limit < $required_limit) {
            $memory_limit_set = ini_set('memory_limit', '512M');
            if ($memory_limit_set === false) {
                error_log("BME: Warning - Could not increase memory limit for batch extraction {$extraction_id}, using {$old_memory_limit}");
            }
        }
        
        try {
            // Check if extraction should continue
            $batch_manager = bme_pro()->get('batch_manager');
            if (!$batch_manager || !$batch_manager->is_batch_extraction($extraction_id)) {
                error_log("BME Background: Batch extraction {$extraction_id} not found or completed");
                return;
            }
            
            // Start the next session
            $batch_manager->start_next_session($extraction_id);
            
            // Run the extraction session
            $result = $this->extraction_engine->run_extraction($extraction_id, false, true);
            
            if (!$result) {
                error_log("BME Background: Batch session failed for extraction {$extraction_id}");
                // The batch manager will handle rescheduling if needed
            }
            
        } catch (Exception $e) {
            error_log("BME Background: Error continuing batch extraction {$extraction_id}: " . $e->getMessage());
            
            // Update batch state with error
            $batch_state = get_post_meta($extraction_id, '_bme_batch_state', true);
            if ($batch_state) {
                $batch_state['status'] = 'error';
                $batch_state['error_message'] = $e->getMessage();
                update_post_meta($extraction_id, '_bme_batch_state', $batch_state);
            }
        }
    }
    
    /**
     * Check if extraction is running
     *
     * Improved in v1.0.2 to check for batch waiting state before clearing locks.
     * This prevents false stale detection during the 1-minute breaks between sessions.
     *
     * @param int $extraction_id The extraction profile post ID
     * @return bool True if extraction is running or waiting for next session
     * @since 1.0.2
     */
    public function is_extraction_running($extraction_id) {
        $lock = get_transient('bme_extraction_lock_' . $extraction_id);

        // Check if lock exists and is not stale
        if (!empty($lock)) {
            // First check if this is a batch extraction in waiting state
            // This is expected behavior - don't clear the lock
            $batch_state = get_post_meta($extraction_id, '_bme_batch_state', true);
            if ($batch_state && isset($batch_state['status'])) {
                // If waiting for next session, the extraction is still "running"
                if ($batch_state['status'] === 'waiting_for_next_session') {
                    error_log("BME Background: Extraction {$extraction_id} is in waiting state - lock preserved");
                    return true;
                }

                // If completed or canceled, clear the lock
                if (in_array($batch_state['status'], ['completed', 'canceled', 'error'])) {
                    $this->clear_extraction_lock($extraction_id);
                    return false;
                }
            }

            // Check if there's actually live progress
            $progress = get_transient('bme_live_progress_' . $extraction_id);

            // If no progress or progress is old (more than 5 minutes), consider lock stale
            // But only if not in batch waiting state (already checked above)
            if (!$progress || (isset($progress['last_update_timestamp']) &&
                (time() - $progress['last_update_timestamp']) > 300)) {

                // Clear stale lock
                $this->clear_extraction_lock($extraction_id);
                error_log("BME Background: Cleared stale lock for extraction {$extraction_id}");
                return false;
            }
        }

        return !empty($lock);
    }
    
    /**
     * Set extraction lock
     */
    private function set_extraction_lock($extraction_id) {
        set_transient('bme_extraction_lock_' . $extraction_id, time(), self::LOCK_DURATION);
    }
    
    /**
     * Clear extraction lock
     */
    private function clear_extraction_lock($extraction_id) {
        delete_transient('bme_extraction_lock_' . $extraction_id);
    }
    
    /**
     * Get retry delay based on pause reason
     */
    private function get_retry_delay($pause_reason) {
        switch ($pause_reason) {
            case 'paused_memory':
                return 60; // Wait 1 minute for memory
            case 'paused_time':
                return 30; // Wait 30 seconds for time
            case 'paused_errors':
                return 120; // Wait 2 minutes for errors
            default:
                return 60;
        }
    }
    
    /**
     * Cancel a running extraction
     */
    public function cancel_extraction($extraction_id) {
        // Clear the lock
        $this->clear_extraction_lock($extraction_id);
        
        // Clear any scheduled events
        $timestamp = wp_next_scheduled(self::BACKGROUND_HOOK, [$extraction_id, false]);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::BACKGROUND_HOOK, [$extraction_id, false]);
        }
        
        $timestamp = wp_next_scheduled(self::BACKGROUND_HOOK, [$extraction_id, true]);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::BACKGROUND_HOOK, [$extraction_id, true]);
        }
        
        // Update status
        update_post_meta($extraction_id, '_bme_last_run_status', 'Canceled');
        delete_post_meta($extraction_id, '_bme_extraction_state');
        
        error_log("BME Background: Canceled extraction {$extraction_id}");
        
        return true;
    }
    
    /**
     * Get status of all running extractions
     */
    public function get_running_extractions() {
        global $wpdb;
        
        $running = [];
        
        // Query all extraction posts
        $extractions = get_posts([
            'post_type' => 'bme_extraction',
            'posts_per_page' => -1,
            'post_status' => 'publish'
        ]);
        
        foreach ($extractions as $extraction) {
            if ($this->is_extraction_running($extraction->ID)) {
                $state = get_post_meta($extraction->ID, '_bme_extraction_state', true);
                $running[] = [
                    'id' => $extraction->ID,
                    'title' => $extraction->post_title,
                    'status' => $state['status'] ?? 'running',
                    'last_update' => $state['last_update'] ?? '',
                    'progress' => $state['total_api_calls'] ?? 0
                ];
            }
        }
        
        return $running;
    }
    
    /**
     * Check system capability for background processing
     */
    public static function check_background_capability() {
        $issues = [];
        
        // Check if WP Cron is disabled
        if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) {
            $issues[] = 'WP Cron is disabled. Background processing requires WP Cron or an external cron job.';
        }
        
        // Check memory limit
        $memory_limit = ini_get('memory_limit');
        if ($memory_limit != -1) {
            $bytes = wp_convert_hr_to_bytes($memory_limit);
            if ($bytes < 256 * 1024 * 1024) { // Less than 256MB
                $issues[] = 'Memory limit is low (' . $memory_limit . '). Recommend at least 256MB for large extractions.';
            }
        }
        
        // Check max execution time
        $max_time = ini_get('max_execution_time');
        if ($max_time > 0 && $max_time < 60) {
            $issues[] = 'Max execution time is low (' . $max_time . ' seconds). Background processing may be limited.';
        }
        
        return $issues;
    }
    
    /**
     * Cleanup old extraction states
     */
    public function cleanup_old_states() {
        global $wpdb;

        // Clean up extraction states older than 24 hours
        // Use wp_date() for WordPress timezone consistency
        $cutoff = wp_date('Y-m-d H:i:s', current_time('timestamp') - DAY_IN_SECONDS);
        
        $query = $wpdb->prepare("
            DELETE FROM {$wpdb->postmeta} 
            WHERE meta_key = '_bme_extraction_state' 
            AND post_id IN (
                SELECT post_id FROM (
                    SELECT post_id FROM {$wpdb->postmeta} 
                    WHERE meta_key = '_bme_extraction_state' 
                    AND meta_value LIKE %s
                ) AS temp
            )
        ", '%"last_update":"' . $cutoff . '%');
        
        $deleted = $wpdb->query($query);
        
        if ($deleted > 0) {
            error_log("BME Background: Cleaned up {$deleted} old extraction states");
        }
    }
    
    /**
     * Clear all extraction locks and progress (admin utility)
     */
    public function clear_all_extraction_locks() {
        global $wpdb;
        
        // Get all extraction IDs
        $extractions = get_posts([
            'post_type' => 'bme_extraction',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'fields' => 'ids'
        ]);
        
        $cleared = 0;
        foreach ($extractions as $extraction_id) {
            // Clear lock
            if (get_transient('bme_extraction_lock_' . $extraction_id)) {
                delete_transient('bme_extraction_lock_' . $extraction_id);
                $cleared++;
            }
            
            // Clear live progress
            delete_transient('bme_live_progress_' . $extraction_id);
            
            // Reset status
            update_post_meta($extraction_id, '_bme_last_run_status', 'Stopped');
        }
        
        error_log("BME Background: Cleared {$cleared} extraction locks");
        return $cleared;
    }
}