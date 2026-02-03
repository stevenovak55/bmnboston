<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Main extraction engine orchestrating the entire data extraction process
 *
 * Handles the complete extraction workflow including batch processing, session management,
 * API communication, data processing, and database operations. Supports both synchronous
 * and background extraction modes with automatic session continuation.
 *
 * @package Bridge_MLS_Extractor_Pro
 * @since 1.0.0
 * @version 1.4
 */
class BME_Extraction_Engine {

    /**
     * @var BME_API_Client API client for Bridge Interactive communication
     */
    private $api_client;

    /**
     * @var BME_Data_Processor Data processor for validation and transformation
     */
    private $data_processor;

    /**
     * @var BME_Cache_Manager Cache manager for performance optimization
     */
    private $cache_manager;

    /**
     * @var BME_Database_Manager Database manager for schema and operations
     */
    private $db_manager;

    /**
     * @var BME_Batch_Manager Batch manager for large extraction sessions
     */
    private $batch_manager;

    /**
     * @var BME_Activity_Logger Activity logger for tracking operations
     */
    private $activity_logger;

    /**
     * @var array Track fetched listing keys for status detection
     */
    private $all_fetched_keys = [];

    /**
     * Constructor
     *
     * @param BME_API_Client $api_client API client instance
     * @param BME_Data_Processor $data_processor Data processor instance
     * @param BME_Cache_Manager $cache_manager Cache manager instance
     * @param BME_Database_Manager|null $db_manager Database manager instance
     * @param BME_Batch_Manager|null $batch_manager Batch manager instance
     * @param BME_Activity_Logger|null $activity_logger Activity logger instance
     */
    public function __construct(BME_API_Client $api_client, BME_Data_Processor $data_processor, BME_Cache_Manager $cache_manager, BME_Database_Manager $db_manager = null, BME_Batch_Manager $batch_manager = null, BME_Activity_Logger $activity_logger = null) {
        $this->api_client = $api_client;
        $this->data_processor = $data_processor;
        $this->cache_manager = $cache_manager;
        $this->db_manager = $db_manager;
        $this->batch_manager = $batch_manager;
        $this->activity_logger = $activity_logger;
    }

    /**
     * Get activity logger with lazy initialization
     * Ensures activity logger is always available when needed
     *
     * @return BME_Activity_Logger|null
     */
    private function get_activity_logger() {
        if (!$this->activity_logger && function_exists('bme_pro')) {
            $this->activity_logger = bme_pro()->get('activity_logger');
        }
        return $this->activity_logger;
    }

    /**
     * Execute a single extraction profile with batch management
     *
     * Main entry point for running extractions. Automatically determines whether to use
     * batch processing based on extraction size and background mode. Handles both
     * synchronous and asynchronous extraction modes.
     *
     * @param int $extraction_id The extraction profile post ID
     * @param bool $is_resync Whether to perform a full resync (true) or incremental update (false)
     * @param bool $is_background Whether extraction is running in background mode
     * @return array|bool Returns extraction results array on success, false on failure
     * @throws Exception If extraction profile is invalid or locked
     */
    public function run_extraction($extraction_id, $is_resync = false, $is_background = true) {
        // Temporary: Use original logic to prevent memory issues
        // Check if we should use batch processing for large extractions
        if ($is_background && $this->batch_manager && !$this->batch_manager->is_batch_extraction($extraction_id)) {
            return $this->run_batch_extraction($extraction_id, $is_resync);
        }

        // Continue with session-based extraction
        return $this->run_extraction_session($extraction_id, $is_resync);
    }
    
    /**
     * Start batch extraction with automatic session management
     *
     * Initializes batch extraction for large datasets, automatically splitting the work
     * into manageable sessions to prevent timeouts and memory issues. Each session
     * processes up to 1000 listings with automatic continuation.
     *
     * @param int $extraction_id The extraction profile post ID
     * @param bool $is_resync Whether to perform a full resync
     * @return array Extraction initialization results
     * @access private
     */
    private function run_batch_extraction($extraction_id, $is_resync = false) {
        $this->log_extraction_step($extraction_id, 'info', 'Starting batch extraction with automatic session management');
        
        // Log extraction start activity
        if ($this->activity_logger) {
            $this->activity_logger->log_extraction_activity(
                BME_Activity_Logger::ACTION_STARTED,
                $extraction_id,
                [
                    'extraction_type' => 'batch',
                    'is_resync' => $is_resync,
                    'session_management' => 'automatic'
                ],
                ['severity' => BME_Activity_Logger::SEVERITY_INFO]
            );
        }
        
        try {
            // Start the batch extraction process
            $this->batch_manager->start_batch_extraction($extraction_id, $is_resync);
            
            // Run the first session
            return $this->run_extraction_session($extraction_id, $is_resync);
            
        } catch (Exception $e) {
            $this->log_extraction_step($extraction_id, 'error', 'Batch extraction failed: ' . $e->getMessage());
            
            // Log extraction failure activity
            if ($this->activity_logger) {
                $this->activity_logger->log_extraction_activity(
                    BME_Activity_Logger::ACTION_FAILED,
                    $extraction_id,
                    [
                        'extraction_type' => 'batch',
                        'error_message' => $e->getMessage(),
                        'error_trace' => substr($e->getTraceAsString(), 0, 1000)
                    ],
                    ['severity' => BME_Activity_Logger::SEVERITY_ERROR]
                );
            }
            
            return false;
        }
    }
    
    /**
     * Execute a single extraction session (max 1000 listings)
     */
    private function run_extraction_session($extraction_id, $is_resync = false) {
        global $wpdb;
        $start_time = microtime(true);
        $memory_start = memory_get_usage();
        $session_processed = 0;
        $recovery_attempts = 0;
        $max_recovery_attempts = 3;
        $lock_name = "bme_extraction_lock_{$extraction_id}";
        $lock_timeout = 300; // 5 minutes
        $has_lock = false;

        // Acquire database-level lock to prevent concurrent extractions
        $lock_acquired = $wpdb->get_var($wpdb->prepare(
            "SELECT GET_LOCK(%s, %d)",
            $lock_name,
            10 // Wait up to 10 seconds for lock
        ));
        
        if (!$lock_acquired) {
            $this->log_extraction_step($extraction_id, 'error', "Could not acquire extraction lock - another extraction may be running");
            return false;
        }
        
        $has_lock = true;

        try {
            // Initialize live progress tracking
            $this->init_live_progress($extraction_id, $start_time);

            while ($recovery_attempts <= $max_recovery_attempts) {
                try {
                    // Check for extraction health before starting
                    if (!$this->check_extraction_health($extraction_id)) {
                        throw new Exception('Extraction health check failed - system not ready');
                    }

                    // Validate API credentials first
                    $this->api_client->validate_credentials();

                // Get extraction configuration
                $config = $this->get_extraction_config($extraction_id, $is_resync);

            // Clear existing data if resync (only on first session)
            if ($is_resync && !$this->batch_manager->is_batch_extraction($extraction_id)) {
                $cleared = $this->data_processor->clear_extraction_data($extraction_id);
                $this->log_extraction_step($extraction_id, 'info', "Cleared {$cleared} existing listings for resync");
                $this->update_live_progress($extraction_id, ['last_message' => sprintf('Cleared %d existing listings for resync.', $cleared)]);
            }

            // Build API filter query
            $filter_query = $this->api_client->build_filter_query($config);
            $this->log_extraction_step($extraction_id, 'info', "API Filter Query built: " . $filter_query);
            $this->update_live_progress($extraction_id, ['last_message' => 'Fetching data from MLS API with filter: ' . $filter_query]);

            // Initialize extraction metrics
            $metrics = [
                'total_listings' => 0,
                'total_batches' => 0,
                'api_requests' => 0,
                'errors' => [],
                'last_modified' => $config['last_modified'],
                'session_start_time' => $start_time
            ];

            // Create extraction callback with session limit checking
            $extraction_callback = function($batch_listings, $total_processed) use ($extraction_id, &$metrics, &$session_processed) {
                $result = $this->process_listings_batch($extraction_id, $batch_listings, $metrics);
                $session_processed += $result['processed'];

                // Update live progress after each batch
                $this->update_live_progress($extraction_id, [
                    'total_processed_current_run' => $metrics['total_listings'],
                    'session_processed' => $session_processed,
                    'last_message' => sprintf('Processed batch: %d listings. Session total: %d.', $result['processed'], $session_processed),
                    'property_subtype_counts' => $metrics['property_subtype_counts'] ?? [],
                    'current_listing_mls_id' => $batch_listings[count($batch_listings) - 1]['ListingId'] ?? '',
                    'current_listing_address' => $batch_listings[count($batch_listings) - 1]['UnparsedAddress'] ?? '',
                ]);

                // Perform periodic memory cleanup every 500 listings
                if ($session_processed % 500 === 0) {
                    // Clear accumulated keys array to prevent memory leak
                    if (count($this->all_fetched_keys) > 1000) {
                        // Keep only last 1000 keys for status detection
                        $this->all_fetched_keys = array_slice($this->all_fetched_keys, -1000, 1000, true);
                    }
                    
                    $this->cleanup_memory();
                    $this->log_extraction_step($extraction_id, 'debug', 
                        sprintf('Periodic memory cleanup at %d listings, current usage: %.1fMB', 
                            $session_processed, 
                            memory_get_usage(true) / 1024 / 1024));
                }

                // Check if we should end the session
                if ($this->batch_manager && $this->batch_manager->should_end_session($session_processed)) {
                    $this->log_extraction_step($extraction_id, 'info', "Session limit reached: {$session_processed} listings processed");
                    // Clean up memory before ending session
                    $this->cleanup_memory();
                    return ['stop_session' => true, 'processed' => $result['processed']];
                }

                return $result;
            };

            // Execute main extraction with session limits
            $total_processed = $this->api_client->fetch_listings_with_session_limit($filter_query, $extraction_callback, $extraction_id);

            // Update checkpoints
            if ($total_processed > 0) {
                if (!empty($metrics['last_modified'])) {
                    update_post_meta($extraction_id, '_bme_last_modified', $metrics['last_modified']);
                    error_log("BME: Updated last_modified checkpoint: {$metrics['last_modified']}");
                }
                if (!empty($metrics['last_close_date'])) {
                    update_post_meta($extraction_id, '_bme_last_close_date', $metrics['last_close_date']);
                    error_log("BME: Updated last_close_date checkpoint: {$metrics['last_close_date']}");
                }
            }
            
            // Handle session completion
            if ($this->batch_manager && $this->batch_manager->is_batch_extraction($extraction_id)) {
                $action = $this->batch_manager->end_session(
                    $session_processed, 
                    $total_processed, 
                    $metrics['last_modified'] ?? null,
                    $metrics['last_close_date'] ?? null
                );
                
                if ($action === 'continue') {
                    $this->log_extraction_step($extraction_id, 'info', "Session completed, next session scheduled");
                    $this->finalize_live_progress($extraction_id, 'session_completed', $session_processed);

                    // Log session completion with performance metrics
                    $duration = microtime(true) - $start_time;
                    $memory_peak = memory_get_peak_usage() - $memory_start;

                    // Log session completion to activity_logs for batch extraction tracking
                    if ($logger = $this->get_activity_logger()) {
                        $current_session = $this->batch_manager ? $this->batch_manager->get_current_session($extraction_id) : null;
                        $logger->log_extraction_activity(
                            'session_completed',
                            $extraction_id,
                            [
                                'session_number' => $current_session,
                                'session_processed' => $session_processed,
                                'total_processed' => $total_processed,
                                'duration' => round($duration, 3),
                                'memory_peak_mb' => round($memory_peak / 1024 / 1024, 2),
                                'next_checkpoint' => $metrics['last_modified'] ?? null,
                                'will_continue' => true,
                                'is_resync' => $is_resync
                            ],
                            ['severity' => BME_Activity_Logger::SEVERITY_INFO]
                        );
                    }
                    
                    $this->log_extraction_completion($extraction_id, [
                        'status' => 'Success',
                        'total_listings' => $session_processed,
                        'session_listings' => $session_processed,
                        'duration' => $duration,
                        'memory_peak_mb' => round($memory_peak / 1024 / 1024, 2),
                        'api_requests' => $metrics['api_requests'] ?? 0,
                        'errors' => $metrics['errors'] ?? [],
                        'is_resync' => $is_resync
                    ]);
                    
                    return true; // Session completed successfully
                }
            }
            
            // Phase 2: Check for status changes (only for non-resync and active-type extractions)
            if (!$is_resync && $this->should_check_status_changes($config)) {
                $this->check_for_status_changes($extraction_id, $config);
            }

            // Phase 1.5: Incremental Open House Sync (only for non-resync)
            // Fetch open houses that have been updated since last sync
            // This catches new open houses added to existing properties
            if (!$is_resync && $total_processed >= 0) {
                try {
                    // Get the last open house sync timestamp
                    $last_oh_modified = get_post_meta($extraction_id, '_bme_last_oh_modified', true);
                    
                    // If no open house checkpoint exists, use the main checkpoint
                    if (empty($last_oh_modified)) {
                        $last_oh_modified = $config['last_modified'] ?? '1970-01-01T00:00:00Z';
                    }
                    
                    $this->log_extraction_step($extraction_id, 'info', 
                        "Starting incremental open house sync (since {$last_oh_modified})"
                    );
                    
                    // Fetch updated open houses directly from API
                    $updated_open_houses = $this->api_client->fetch_updated_open_houses($last_oh_modified, $extraction_id);
                    
                    if (!empty($updated_open_houses)) {
                        $oh_processed = 0;
                        $oh_skipped = 0;  // Listings not in extraction filter (expected)
                        $oh_errors = 0;   // Actual processing errors (unexpected)

                        foreach ($updated_open_houses as $listing_id => $open_houses) {
                            try {
                                // Get listing_key for this listing_id
                                $listing_key = $this->data_processor->get_listing_key_by_id($listing_id);

                                if ($listing_key) {
                                    // Process open houses for this listing
                                    $this->data_processor->process_open_houses(
                                        $listing_id,
                                        $listing_key,
                                        $open_houses
                                    );
                                    $oh_processed++;
                                } else {
                                    // Listing not in our database (outside extraction filter - this is expected)
                                    $oh_skipped++;
                                }
                            } catch (Exception $e) {
                                error_log("BME: Error processing open houses for listing {$listing_id}: " . $e->getMessage());
                                $oh_errors++;
                            }
                        }
                        
                        // Update the open house checkpoint with the latest modification timestamp
                        // Get the latest ModificationTimestamp from all processed open houses
                        $latest_oh_timestamp = $last_oh_modified;
                        foreach ($updated_open_houses as $listing_open_houses) {
                            foreach ($listing_open_houses as $oh) {
                                if (isset($oh['ModificationTimestamp']) && $oh['ModificationTimestamp'] > $latest_oh_timestamp) {
                                    $latest_oh_timestamp = $oh['ModificationTimestamp'];
                                }
                            }
                        }
                        
                        update_post_meta($extraction_id, '_bme_last_oh_modified', $latest_oh_timestamp);

                        // Build summary message
                        $summary_parts = [];
                        if ($oh_processed > 0) {
                            $summary_parts[] = "{$oh_processed} updated";
                        }
                        if ($oh_skipped > 0) {
                            $summary_parts[] = "{$oh_skipped} skipped (not in filter)";
                        }
                        if ($oh_errors > 0) {
                            $summary_parts[] = "{$oh_errors} errors";
                        }
                        $summary = !empty($summary_parts) ? implode(', ', $summary_parts) : 'no changes';

                        // Use warning level if there were actual errors, info otherwise
                        $log_level = ($oh_errors > 0) ? 'warning' : 'info';
                        $this->log_extraction_step($extraction_id, $log_level,
                            "Incremental open house sync completed: {$summary}"
                        );

                        // Log activity
                        if ($logger = $this->get_activity_logger()) {
                            $logger->log_extraction_activity(
                                'open_house_sync_completed',
                                $extraction_id,
                                [
                                    'listings_updated' => $oh_processed,
                                    'listings_skipped' => $oh_skipped,
                                    'errors' => $oh_errors,
                                    'checkpoint' => $latest_oh_timestamp
                                ],
                                ['severity' => BME_Activity_Logger::SEVERITY_INFO]
                            );
                        }
                    } else {
                        $this->log_extraction_step($extraction_id, 'info', 
                            "No updated open houses found since {$last_oh_modified}"
                        );
                    }
                } catch (Exception $e) {
                    $this->log_extraction_step($extraction_id, 'warning', 
                        'Incremental open house sync failed: ' . $e->getMessage()
                    );
                }
            }

            // Invalidate cache on successful run
            if ($total_processed > 0 || $is_resync) {
                $this->cache_manager->invalidate_listing_caches();
                $this->log_extraction_step($extraction_id, 'info', "Plugin caches invalidated successfully.");
            }

            // Calculate final metrics
            $duration = microtime(true) - $start_time;
            $memory_peak = memory_get_peak_usage() - $memory_start;

            // Log completion
            $this->log_extraction_completion($extraction_id, [
                'status' => empty($metrics['errors']) ? 'Success' : 'Completed with errors',
                'total_listings' => $total_processed,
                'session_listings' => $session_processed,
                'duration' => $duration,
                'memory_peak_mb' => round($memory_peak / 1024 / 1024, 2),
                'api_requests' => $metrics['api_requests'],
                'errors' => $metrics['errors'],
                'is_resync' => $is_resync
            ]);

            // Finalize live progress
            $this->finalize_live_progress($extraction_id, 'completed', $total_processed);

            // v4.0.14: Summary table refresh removed - now written in real-time during extraction
            // See process_listing_summary() in class-bme-data-processor.php
            // This eliminates the TRUNCATE+INSERT pattern that caused empty tables on Kinsta
            $this->log_extraction_step($extraction_id, 'info',
                'Extraction completed - summary table was populated in real-time during extraction'
            );

                return true;

            } catch (Exception $e) {
                $recovery_attempts++;
                $error_code = $this->classify_extraction_error($e);
                
                $this->log_extraction_step($extraction_id, 'error', 
                    "Session attempt {$recovery_attempts} failed: {$e->getMessage()} (Error: {$error_code})"
                );

                // Determine if recovery is possible
                $recovery_action = $this->determine_recovery_action($error_code, $recovery_attempts, $max_recovery_attempts);
                
                if ($recovery_action === 'retry') {
                    $this->log_extraction_step($extraction_id, 'info', 
                        "Attempting recovery {$recovery_attempts}/{$max_recovery_attempts} after {$recovery_action} delay"
                    );
                    
                    // Progressive delay: 5s, 10s, 20s
                    $delay = min(5 * pow(2, $recovery_attempts - 1), 20);
                    sleep($delay);
                    
                    // Update progress with recovery message
                    $this->update_live_progress($extraction_id, [
                        'last_message' => "Recovering from error (attempt {$recovery_attempts}/{$max_recovery_attempts}): " . substr($e->getMessage(), 0, 100)
                    ]);
                    
                    // Clear any partial state and continue loop
                    $this->cleanup_partial_extraction_state($extraction_id);
                    continue;
                    
                } elseif ($recovery_action === 'fail') {
                    $this->log_extraction_step($extraction_id, 'error', 
                        "Non-recoverable error, failing extraction: {$e->getMessage()}"
                    );
                    break;
                }
            }
        }

        // If we exit the recovery loop, the extraction has failed
        $duration = microtime(true) - $start_time;
        $final_error_message = $e->getMessage() ?? "Maximum recovery attempts exceeded";

        $this->log_extraction_completion($extraction_id, [
            'status' => 'Failure',
            'error_message' => $final_error_message,
            'recovery_attempts' => $recovery_attempts,
            'duration' => $duration,
            'memory_peak_mb' => round((memory_get_peak_usage() - $memory_start) / 1024 / 1024, 2),
            'is_resync' => $is_resync
        ]);

        // Finalize live progress with failure
        $this->finalize_live_progress($extraction_id, 'failed', $session_processed, $final_error_message);

        } finally {
            // Always release the database lock
            if ($has_lock) {
                $wpdb->query($wpdb->prepare(
                    "SELECT RELEASE_LOCK(%s)",
                    $lock_name
                ));
                $this->log_extraction_step($extraction_id, 'info', "Released extraction lock");
            }

            // Always clear the transient running flag
            delete_transient('bme_extraction_running_' . $extraction_id);
        }

        return false;
    }

    /**
     * Process a batch of listings with related data
     */
    private function process_listings_batch($extraction_id, $batch_listings, &$metrics) {
        try {
            // Extract IDs for related data fetching
            $agent_ids = [];
            $office_ids = [];
            $listing_keys_active = [];
            $property_subtypes = $metrics['property_subtype_counts'] ?? []; // Initialize or retrieve existing counts

            foreach ($batch_listings as $listing) {
                if (!empty($listing['ListAgentMlsId'])) $agent_ids[] = $listing['ListAgentMlsId'];
                if (!empty($listing['BuyerAgentMlsId'])) $agent_ids[] = $listing['BuyerAgentMlsId'];
                if (!empty($listing['ListOfficeMlsId'])) $office_ids[] = $listing['ListOfficeMlsId'];
                if (!empty($listing['BuyerOfficeMlsId'])) $office_ids[] = $listing['BuyerOfficeMlsId'];

                // Only check for open houses on active listings to save API calls
                if (isset($listing['StandardStatus']) && !$this->data_processor->is_archived_status($listing['StandardStatus'])) {
                    if (!empty($listing['ListingKey'])) {
                        $listing_keys_active[] = $listing['ListingKey'];
                    }
                }

                // Track PropertySubType counts
                if (!empty($listing['PropertySubType'])) {
                    $sub_type = sanitize_text_field($listing['PropertySubType']);
                    $property_subtypes[$sub_type] = ($property_subtypes[$sub_type] ?? 0) + 1;
                }

                // Track latest modification timestamp
                if (!empty($listing['ModificationTimestamp'])) {
                    $metrics['last_modified'] = $listing['ModificationTimestamp'];
                }
                
                // Track latest close date for archived extractions
                if (!empty($listing['CloseDate'])) {
                    $metrics['last_close_date'] = $listing['CloseDate'];
                }
            }

            // Fetch related data. Open House data will only be fetched for active listings.
            $related_data = $this->api_client->fetch_related_data(
                $agent_ids,
                $office_ids,
                $listing_keys_active,
                $extraction_id
            );

            // Increment API request counter
            $metrics['api_requests'] += 1 + ceil(count(array_unique($agent_ids)) / 50) +
                                       ceil(count(array_unique($office_ids)) / 50) +
                                       ceil(count(array_unique($listing_keys_active)) / 50);

            // Process the batch
            $result = $this->data_processor->process_listings_batch(
                $extraction_id,
                $batch_listings,
                $related_data
            );

            // Update metrics
            $metrics['total_listings'] += $result['processed'];
            $metrics['total_batches']++;
            $metrics['errors'] = array_merge($metrics['errors'], $result['errors']);
            $metrics['property_subtype_counts'] = $property_subtypes; // Update main metrics array

            // Log batch progress
            $this->log_extraction_step(
                $extraction_id,
                'info',
                sprintf(
                    'Processed batch: %d listings, %d errors, %.2f seconds',
                    $result['processed'],
                    count($result['errors']),
                    $result['duration']
                )
            );

            return $result;

        } catch (Exception $e) {
            $this->log_extraction_step($extraction_id, 'error', 'Batch processing failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get extraction configuration from post meta
     */
    private function get_extraction_config($extraction_id, $is_resync) {
        $config = [
            'extraction_id' => $extraction_id,
            'is_resync' => $is_resync,
            'statuses' => get_post_meta($extraction_id, '_bme_statuses', true) ?: [],
            'property_types' => get_post_meta($extraction_id, '_bme_property_types', true) ?: [],
            'cities' => get_post_meta($extraction_id, '_bme_cities', true),
            'states' => get_post_meta($extraction_id, '_bme_states', true) ?: [],
            'list_agent_id' => get_post_meta($extraction_id, '_bme_list_agent_id', true),
            'buyer_agent_id' => get_post_meta($extraction_id, '_bme_buyer_agent_id', true),
            'closed_lookback_months' => get_post_meta($extraction_id, '_bme_lookback_months', true) ?: 12, // Ensure correct meta key
            'last_modified' => get_post_meta($extraction_id, '_bme_last_modified', true) ?: '1970-01-01T00:00:00Z'
        ];

        // Validate required configuration
        if (empty($config['statuses'])) {
            throw new Exception('No listing statuses configured for extraction. Please edit the extraction and select at least one status.');
        }

        // Safety check - if no filters are set, require confirmation
        $has_filters = !empty($config['cities']) ||
                      !empty($config['states']) ||
                      !empty($config['list_agent_id']) ||
                      !empty($config['buyer_agent_id']);

        if (!$has_filters && !$is_resync) {
            // Log warning but don't prevent extraction
            error_log('BME Warning - Extraction ' . $extraction_id . ' has no geographic or agent filters. This may pull a large dataset.');
        }

        return $config;
    }

    /**
     * Initialize live progress transient for an extraction.
     * @param int $extraction_id The ID of the extraction.
     * @param float $start_time The microtime when extraction started.
     */
    private function init_live_progress($extraction_id, $start_time) {
        $progress_key = 'bme_live_progress_' . $extraction_id;
        $initial_data = [
            'status' => 'running',
            'total_processed_current_run' => 0,
            'current_listing_mls_id' => '',
            'current_listing_address' => '',
            'property_subtype_counts' => [],
            'last_update_timestamp' => time(),
            'last_message' => 'Extraction started...',
            'extraction_start_time' => $start_time,
            'error_message' => ''
        ];
        set_transient($progress_key, $initial_data, HOUR_IN_SECONDS); // Keep for 1 hour
        $this->log_extraction_step($extraction_id, 'info', 'Live progress initialized.');
    }

    /**
     * Update live progress transient.
     * @param int $extraction_id The ID of the extraction.
     * @param array $data_to_update Associative array of data to merge into the transient.
     */
    private function update_live_progress($extraction_id, $data_to_update) {
        $progress_key = 'bme_live_progress_' . $extraction_id;
        $current_data = get_transient($progress_key);
        if ($current_data === false) {
            // If transient somehow expired or was deleted, re-initialize minimally
            $current_data = [
                'status' => 'running',
                'total_processed_current_run' => 0,
                'property_subtype_counts' => [],
                'extraction_start_time' => microtime(true),
            ];
        }

        $merged_data = array_merge($current_data, $data_to_update);
        $merged_data['last_update_timestamp'] = time(); // Always update timestamp
        set_transient($progress_key, $merged_data, HOUR_IN_SECONDS);
    }

    /**
     * Finalize live progress transient (set status to completed/failed and remove live data).
     * @param int $extraction_id The ID of the extraction.
     * @param string $final_status 'completed' or 'failed'.
     * @param int $final_count The total number of listings processed.
     * @param string $error_message Optional error message.
     */
    private function finalize_live_progress($extraction_id, $final_status, $final_count, $error_message = '') {
        $progress_key = 'bme_live_progress_' . $extraction_id;
        $final_data = get_transient($progress_key);
        if ($final_data === false) {
            $final_data = []; // Fallback if transient already gone
        }

        $final_data['status'] = $final_status;
        $final_data['total_processed_current_run'] = $final_count;
        $final_data['last_message'] = ($final_status === 'completed') ? 'Extraction completed.' : 'Extraction failed.';
        $final_data['error_message'] = $error_message;
        $final_data['last_update_timestamp'] = time();

        set_transient($progress_key, $final_data, MINUTE_IN_SECONDS * 5); // Keep final status for a short period
        $this->log_extraction_step($extraction_id, 'info', 'Live progress finalized. Status: ' . $final_status);
    }

    /**
     * Get live progress data for a specific extraction.
     * @param int $extraction_id The ID of the extraction.
     * @return array|false The live progress data, or false if not found.
     */
    public function get_live_progress($extraction_id) {
        $progress_key = 'bme_live_progress_' . $extraction_id;
        return get_transient($progress_key);
    }

    /**
     * Log extraction step for debugging
     */
    /**
     * Check extraction health before starting a session
     */
    private function check_extraction_health($extraction_id) {
        try {
            // Check memory availability
            $memory_limit = ini_get('memory_limit');
            $memory_usage = memory_get_usage(true);
            $memory_available = $this->parse_memory_limit($memory_limit) - $memory_usage;
            
            if ($memory_available < 50 * 1024 * 1024) { // 50MB minimum
                $this->log_extraction_step($extraction_id, 'warning',
                    "Low memory available: " . round($memory_available / 1024 / 1024, 1) . "MB"
                );
                return false;
            }

            // Note: Running lock is managed by cron manager (class-bme-cron-manager.php)
            // to avoid duplicate transient checks causing false "already running" errors

            return true;
            
        } catch (Exception $e) {
            $this->log_extraction_step($extraction_id, 'error', 
                "Health check failed: " . $e->getMessage()
            );
            return false;
        }
    }

    /**
     * Parse memory limit string to bytes
     */
    private function parse_memory_limit($limit_string) {
        $limit_string = trim($limit_string);

        // Handle unlimited memory (-1)
        if ($limit_string === '-1') {
            return PHP_INT_MAX;
        }

        $last_char = strtolower($limit_string[strlen($limit_string)-1]);
        $numeric_value = intval($limit_string);

        switch($last_char) {
            case 'g':
                return $numeric_value * 1024 * 1024 * 1024;
            case 'm':
                return $numeric_value * 1024 * 1024;
            case 'k':
                return $numeric_value * 1024;
            default:
                return $numeric_value;
        }
    }

    /**
     * Classify extraction errors for recovery decisions
     */
    private function classify_extraction_error($exception) {
        $message = strtolower($exception->getMessage());
        
        if (strpos($message, 'credential') !== false || strpos($message, 'authentication') !== false) {
            return 'AUTH_ERROR';
        }
        
        if (strpos($message, 'rate limit') !== false || strpos($message, '429') !== false) {
            return 'RATE_LIMIT';
        }
        
        if (strpos($message, 'timeout') !== false || strpos($message, 'connection') !== false) {
            return 'NETWORK_TIMEOUT';
        }
        
        if (strpos($message, 'memory') !== false || strpos($message, 'fatal error') !== false) {
            return 'MEMORY_ERROR';
        }
        
        if (strpos($message, 'database') !== false || strpos($message, 'sql') !== false) {
            return 'DATABASE_ERROR';
        }
        
        if (strpos($message, 'session') !== false || strpos($message, 'batch') !== false) {
            return 'SESSION_ERROR';
        }
        
        return 'UNKNOWN_ERROR';
    }

    /**
     * Determine recovery action based on error classification
     */
    private function determine_recovery_action($error_code, $attempt_count, $max_attempts) {
        if ($attempt_count >= $max_attempts) {
            return 'fail';
        }
        
        switch ($error_code) {
            case 'AUTH_ERROR':
                // Authentication errors - only retry once to avoid account lockout
                return $attempt_count === 1 ? 'retry' : 'fail';
                
            case 'RATE_LIMIT':
            case 'NETWORK_TIMEOUT':
                // Network issues - retry with backoff
                return 'retry';
                
            case 'MEMORY_ERROR':
                // Memory issues - cleanup and retry
                $this->cleanup_memory();
                return $attempt_count <= 2 ? 'retry' : 'fail';
                
            case 'DATABASE_ERROR':
                // Database issues - retry a few times
                return $attempt_count <= 2 ? 'retry' : 'fail';
                
            case 'SESSION_ERROR':
                // Session management errors - safe to retry
                return 'retry';
                
            case 'UNKNOWN_ERROR':
            default:
                // Unknown errors - conservative retry approach
                return $attempt_count === 1 ? 'retry' : 'fail';
        }
    }

    /**
     * Cleanup partial extraction state for recovery
     */
    private function cleanup_partial_extraction_state($extraction_id) {
        try {
            // Clear running flag
            delete_transient('bme_extraction_running_' . $extraction_id);
            
            // Clear temporary progress data
            delete_transient('bme_live_progress_' . $extraction_id);
            
            // Force garbage collection
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
            
            $this->log_extraction_step($extraction_id, 'info', "Cleaned up partial extraction state for recovery");
            
        } catch (Exception $e) {
            $this->log_extraction_step($extraction_id, 'warning', 
                "Failed to cleanup partial state: " . $e->getMessage()
            );
        }
    }

    /**
     * Force memory cleanup with enhanced resource management
     */
    private function cleanup_memory() {
        $memory_before = memory_get_usage(true);
        
        // Clear large internal arrays
        $this->all_fetched_keys = [];
        
        // Clear any cached query results in the database manager
        if ($this->db_manager && method_exists($this->db_manager, 'clear_query_cache')) {
            $this->db_manager->clear_query_cache();
        }
        
        // Clear cache manager buffers
        if ($this->cache_manager && method_exists($this->cache_manager, 'flush_buffers')) {
            $this->cache_manager->flush_buffers();
        }
        
        // Clear data processor internal buffers
        if ($this->data_processor && method_exists($this->data_processor, 'clear_buffers')) {
            $this->data_processor->clear_buffers();
        }
        
        // Force PHP garbage collection
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }
        
        // Clear WordPress object cache selectively (don't flush everything)
        if (function_exists('wp_cache_flush_group')) {
            wp_cache_flush_group(BME_CACHE_GROUP);
        } elseif (function_exists('wp_cache_flush')) {
            // Only flush if no group-specific flush is available
            wp_cache_flush();
        }
        
        // Clear any global variables that might be holding references
        global $wpdb;
        if (isset($wpdb->queries)) {
            $wpdb->queries = [];
        }
        
        $memory_after = memory_get_usage(true);
        $memory_freed = $memory_before - $memory_after;
        
        error_log(sprintf(
            "BME: Memory cleanup performed - Before: %.1fMB, After: %.1fMB, Freed: %.1fMB",
            $memory_before / 1024 / 1024,
            $memory_after / 1024 / 1024,
            $memory_freed / 1024 / 1024
        ));
    }

    private function log_extraction_step($extraction_id, $level, $message) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $prefix = sprintf('[BME Extraction %d]', $extraction_id);
            error_log("{$prefix} [{$level}] {$message}");
        }
    }

    /**
     * Log extraction completion with detailed metrics
     */
    private function log_extraction_completion($extraction_id, $metrics) {
        global $wpdb;

        $db_manager = bme_pro()->get('db');
        $table = $db_manager->get_table('extraction_logs');

        $log_data = [
            'extraction_id' => $extraction_id,
            'status' => $metrics['status'],
            'listings_processed' => $metrics['total_listings'] ?? 0,
            'duration_seconds' => round($metrics['duration'], 3),
            'memory_peak_mb' => $metrics['memory_peak_mb'],
            'api_requests_count' => $metrics['api_requests'] ?? 0,
            'started_at' => wp_date('Y-m-d H:i:s', current_time('timestamp') - intval($metrics['duration'])),
            'completed_at' => current_time('mysql')
        ];

        // Build message
        if ($metrics['status'] === 'Success') {
            $run_type = $metrics['is_resync'] ? 'Full Re-sync' : 'Standard Run';
            $log_data['message'] = sprintf(
                '%s completed successfully. Processed %d listings in %.2f seconds.',
                $run_type,
                $metrics['total_listings'] ?? 0,
                $metrics['duration']
            );
        } elseif ($metrics['status'] === 'Completed with errors') {
            $log_data['message'] = sprintf(
                'Extraction completed with %d errors. Processed %d listings.',
                count($metrics['errors']),
                $metrics['total_listings'] ?? 0
            );
            $log_data['error_details'] = json_encode($metrics['errors']);
        } else {
            $log_data['message'] = $metrics['error_message'] ?? 'Unknown error occurred';
            if (!empty($metrics['error_message'])) {
                $log_data['error_details'] = json_encode(['message' => $metrics['error_message']]);
            }
        }

        // Insert log record
        $wpdb->insert($table, $log_data);

        // Update extraction post meta
        update_post_meta($extraction_id, '_bme_last_run_status', $metrics['status']);
        update_post_meta($extraction_id, '_bme_last_run_time', time());

        // Update performance metrics
        if (!empty($metrics['duration'])) {
            $duration_value = round($metrics['duration'], 2);
            update_post_meta($extraction_id, '_bme_last_run_duration', $duration_value);
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("BME: Updated _bme_last_run_duration for extraction {$extraction_id}: {$duration_value}");
            }
        }

        if (!empty($metrics['total_listings'])) {
            $count_value = (int) $metrics['total_listings'];
            update_post_meta($extraction_id, '_bme_last_run_count', $count_value);
            // Also update the total processed count used elsewhere
            update_post_meta($extraction_id, '_bme_total_processed', $count_value);
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("BME: Updated _bme_last_run_count for extraction {$extraction_id}: {$count_value}");
            }
        }
        
        // Additional debug info
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("BME: Extraction completion metrics - Duration: " . ($metrics['duration'] ?? 'missing') . ", Total Listings: " . ($metrics['total_listings'] ?? 'missing'));
        }

        // Log completion to activity_logs for comprehensive audit trail
        if ($logger = $this->get_activity_logger()) {
            $action = ($metrics['status'] === 'Success')
                ? BME_Activity_Logger::ACTION_COMPLETED
                : BME_Activity_Logger::ACTION_FAILED;

            $severity = ($metrics['status'] === 'Success')
                ? BME_Activity_Logger::SEVERITY_SUCCESS
                : (($metrics['status'] === 'Completed with errors')
                    ? BME_Activity_Logger::SEVERITY_WARNING
                    : BME_Activity_Logger::SEVERITY_ERROR);

            $logger->log_extraction_activity(
                $action,
                $extraction_id,
                [
                    'listings_processed' => $metrics['total_listings'] ?? 0,
                    'session_listings' => $metrics['session_listings'] ?? 0,
                    'duration_seconds' => round($metrics['duration'], 3),
                    'memory_peak_mb' => $metrics['memory_peak_mb'],
                    'api_requests' => $metrics['api_requests'] ?? 0,
                    'error_count' => count($metrics['errors'] ?? []),
                    'is_resync' => $metrics['is_resync'] ?? false,
                    'status' => $metrics['status'],
                    'error_message' => $metrics['error_message'] ?? null
                ],
                ['severity' => $severity]
            );
        }
    }

    /**
     * Get extraction statistics
     */
    public function get_extraction_stats($extraction_id) {
        return $this->data_processor->get_extraction_stats($extraction_id);
    }

    /**
     * Run multiple extractions (deprecated - use BME_Cron_Manager::run_scheduled_extractions)
     * @deprecated 3.30 Use cron manager instead
     */
    public function run_scheduled_extractions() {
        trigger_error('run_scheduled_extractions() is deprecated. Use BME_Cron_Manager::run_scheduled_extractions() instead.', E_USER_DEPRECATED);

        // Simple fallback - just return 0 to avoid issues
        return 0;
    }

    /**
     * Test extraction configuration without running full extraction
     */
    public function test_extraction_config($extraction_id) {
        try {
            // Validate API credentials
            $this->api_client->validate_credentials();

            // Get extraction configuration
            $config = $this->get_extraction_config($extraction_id, false);

            // Build filter query
            $filter_query = $this->api_client->build_filter_query($config);

            // Test with a small sample
            $test_config = $config;
            $test_config['limit'] = 5;

            // This would be a modified version of fetch_listings that returns just a sample
            // For now, we'll just validate the configuration and filter

            return [
                'success' => true,
                'config' => $config,
                'filter_query' => $filter_query,
                'message' => 'Configuration is valid and ready for extraction'
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Get recent extraction logs
     */
    public function get_recent_logs($extraction_id = null, $limit = 20) {
        global $wpdb;

        $db_manager = bme_pro()->get('db');
        $table = $db_manager->get_table('extraction_logs');

        $sql = "SELECT * FROM {$table}";
        $params = [];

        if ($extraction_id) {
            $sql .= " WHERE extraction_id = %d";
            $params[] = $extraction_id;
        }

        $sql .= " ORDER BY created_at DESC LIMIT %d";
        $params[] = $limit;

        return $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
    }

    /**
     * Clear old extraction logs
     */
    public function cleanup_old_logs($days_to_keep = 30) {
        global $wpdb;

        $db_manager = bme_pro()->get('db');
        $table = $db_manager->get_table('extraction_logs');

        $cutoff_date = wp_date('Y-m-d H:i:s', current_time('timestamp') - ($days_to_keep * DAY_IN_SECONDS));

        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE created_at < %s",
            $cutoff_date
        ));

        return $deleted;
    }

    /**
     * Get system performance metrics
     */
    public function get_performance_metrics() {
        global $wpdb;

        $db_manager = bme_pro()->get('db');
        $logs_table = $db_manager->get_table('extraction_logs');

        // Get metrics for the last 24 hours
        $metrics = $wpdb->get_row("
            SELECT
                COUNT(*) as total_runs,
                SUM(listings_processed) as total_listings,
                AVG(duration_seconds) as avg_duration,
                AVG(memory_peak_mb) as avg_memory,
                SUM(api_requests_count) as total_api_requests,
                SUM(CASE WHEN status = 'Success' THEN 1 ELSE 0 END) as successful_runs
            FROM {$logs_table}
            WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ", ARRAY_A);

        // Calculate success rate
        $metrics['success_rate'] = $metrics['total_runs'] > 0
            ? round(($metrics['successful_runs'] / $metrics['total_runs']) * 100, 2)
            : 0;

        // Get database statistics
        $db_stats = $db_manager->get_stats();

        return [
            'extraction_metrics' => $metrics,
            'database_stats' => $db_stats
        ];
    }
    
    /**
     * Check if status change detection should run
     */
    private function should_check_status_changes($config) {
        // Check if feature is enabled
        $enabled = get_post_meta($config['extraction_id'], '_bme_detect_status_changes', true);
        if ($enabled === 'disabled') {
            return false;
        }
        
        // Only check for Active-type extractions
        $active_statuses = ['Active', 'Active Under Contract', 'Pending'];
        $has_active = !empty(array_intersect($config['statuses'], $active_statuses));
        
        return $has_active;
    }
    
    /**
     * Phase 2: Check for status changes on all modified listings
     */
    private function check_for_status_changes($extraction_id, $original_config) {
        $this->log_extraction_step($extraction_id, 'info', 'Phase 2: Starting status change detection');
        
        try {
            // Build filter without status restriction
            $status_check_config = $original_config;
            $status_check_config['check_status_only'] = true;
            $status_check_config['statuses'] = []; // Remove status filter
            
            $filter_query = $this->build_status_check_filter($status_check_config);
            
            // Fetch ALL modified listings regardless of status
            $all_modified_listings = [];
            $this->api_client->fetch_listings($filter_query, function($batch) use (&$all_modified_listings) {
                $all_modified_listings = array_merge($all_modified_listings, $batch);
            }, $extraction_id);
            
            if (empty($all_modified_listings)) {
                $this->log_extraction_step($extraction_id, 'info', 'Phase 2: No modified listings found');
                return;
            }
            
            $this->log_extraction_step(
                $extraction_id, 
                'info', 
                sprintf('Phase 2: Checking %d modified listings for status changes', count($all_modified_listings))
            );
            
            // Process ONLY status updates for existing listings
            $results = $this->data_processor->process_status_updates_only($extraction_id, $all_modified_listings);
            
            $this->log_extraction_step(
                $extraction_id,
                'info',
                sprintf(
                    'Phase 2 Complete: %d existing listings checked, %d status changes processed, %d new listings skipped',
                    $results['processed'],
                    $results['moved'],
                    $results['skipped']
                )
            );
            
        } catch (Exception $e) {
            $this->log_extraction_step(
                $extraction_id,
                'error',
                'Phase 2 Error: ' . $e->getMessage()
            );
        }
    }
    
    /**
     * Build filter for status checking (no status filter)
     */
    private function build_status_check_filter($config) {
        $filters = [];
        
        // Only filter by modification timestamp
        $last_modified = $config['last_modified'] ?? '1970-01-01T00:00:00Z';
        $filters[] = "ModificationTimestamp gt {$last_modified}";
        
        // Keep geographic filters if present
        if (!empty($config['cities'])) {
            $cities = array_filter(array_map('trim', explode(',', $config['cities'])));
            if (!empty($cities)) {
                $city_filters = array_map(fn($city) => "City eq '" . str_replace("'", "''", $city) . "'", $cities);
                $filters[] = count($city_filters) > 1 ? '(' . implode(' or ', $city_filters) . ')' : $city_filters[0];
            }
        }
        
        if (!empty($config['states'])) {
            $state_filters = array_map(fn($state) => "StateOrProvince eq '{$state}'", $config['states']);
            $filters[] = count($state_filters) > 1 ? '(' . implode(' or ', $state_filters) . ')' : $state_filters[0];
        }
        
        // Do NOT add status filter
        
        return implode(' and ', $filters);
    }
    
    /**
     * Get extraction preview with batch execution plan
     */
    public function get_extraction_preview($extraction_id) {
        if (!$this->batch_manager) {
            return [
                'success' => false,
                'error' => 'Batch manager not available'
            ];
        }
        
        try {
            $config = $this->get_extraction_config($extraction_id, false);
            return $this->batch_manager->get_extraction_preview($extraction_id, $config);
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}