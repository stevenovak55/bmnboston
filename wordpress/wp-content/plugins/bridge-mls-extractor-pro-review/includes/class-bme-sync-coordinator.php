<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Unified Sync Coordinator - Central orchestrator for all sync operations
 *
 * Ensures consistent sync behavior across all scenarios: initial sync, incremental sync,
 * batch processing, and cron scheduled sync. Provides comprehensive error handling,
 * retry logic, and data integrity verification.
 *
 * @package Bridge_MLS_Extractor_Pro
 * @since 3.30
 * @version 1.0.0
 */
class BME_Sync_Coordinator {

    /**
     * @var BME_Extraction_Engine Extraction engine instance
     */
    private $extraction_engine;

    /**
     * @var BME_Data_Processor Data processor instance
     */
    private $data_processor;

    /**
     * @var BME_API_Client API client instance
     */
    private $api_client;

    /**
     * @var BME_Activity_Logger Activity logger instance
     */
    private $activity_logger;

    /**
     * @var array Current sync context
     */
    private $sync_context;

    /**
     * @var array Sync metrics for reporting
     */
    private $sync_metrics;

    /**
     * Constructor
     */
    public function __construct() {
        $this->extraction_engine = bme_pro()->get('extractor');
        $this->data_processor = bme_pro()->get('processor');
        $this->api_client = bme_pro()->get('api');
        $this->activity_logger = bme_pro()->get('activity_logger');

        $this->init_sync_metrics();
    }

    /**
     * Unified sync entry point for all scenarios
     *
     * @param int $extraction_id The extraction profile ID
     * @param array $sync_context Context information about the sync
     * @return array Sync results with metrics and status
     */
    public function execute_sync($extraction_id, $sync_context = []) {
        $this->sync_context = array_merge([
            'sync_type' => 'unknown',
            'is_resync' => false,
            'is_background' => true,
            'retry_count' => 0,
            'max_retries' => 3,
            'source' => 'unknown'
        ], $sync_context);

        $this->log_sync_start($extraction_id);

        try {
            // Pre-sync validation
            $this->validate_sync_preconditions($extraction_id);

            // Execute unified extraction logic
            $result = $this->run_unified_extraction($extraction_id);

            // Post-sync verification
            $this->verify_sync_integrity($extraction_id, $result);

            $this->log_sync_success($extraction_id, $result);
            return $result;

        } catch (Exception $e) {
            return $this->handle_sync_failure($extraction_id, $e);
        }
    }

    /**
     * Validate preconditions before starting sync
     */
    private function validate_sync_preconditions($extraction_id) {
        // Check if extraction profile exists
        $extraction = get_post($extraction_id);
        if (!$extraction || $extraction->post_type !== 'bme_extraction') {
            throw new Exception("Invalid extraction profile ID: {$extraction_id}");
        }

        // Check for existing running extraction
        if ($this->is_extraction_running($extraction_id)) {
            throw new Exception("Extraction {$extraction_id} is already running");
        }

        // Validate API connectivity
        if (!$this->validate_api_connectivity()) {
            throw new Exception("API connectivity check failed");
        }

        $this->sync_metrics['preconditions_passed'] = true;
    }

    /**
     * Core unified extraction logic
     */
    private function run_unified_extraction($extraction_id) {
        // Set extraction lock
        $this->set_extraction_lock($extraction_id);

        try {
            // Determine extraction method based on context
            if ($this->sync_context['is_background'] && $this->should_use_batch_processing($extraction_id)) {
                return $this->run_batch_extraction($extraction_id);
            } else {
                return $this->run_single_session_extraction($extraction_id);
            }

        } finally {
            $this->clear_extraction_lock($extraction_id);
        }
    }

    /**
     * Run batch extraction with session management
     */
    private function run_batch_extraction($extraction_id) {
        $this->log_sync_step($extraction_id, 'Starting batch extraction with unified coordinator');

        // Delegate to existing batch manager but with enhanced monitoring
        $batch_manager = bme_pro()->get('batch_manager');

        if (!$batch_manager->is_batch_extraction($extraction_id)) {
            $batch_manager->start_batch_extraction($extraction_id, $this->sync_context['is_resync']);
        }

        // Run extraction session with enhanced error handling
        return $this->run_extraction_session_with_monitoring($extraction_id);
    }

    /**
     * Run single session extraction
     */
    private function run_single_session_extraction($extraction_id) {
        $this->log_sync_step($extraction_id, 'Starting single session extraction');

        return $this->run_extraction_session_with_monitoring($extraction_id);
    }

    /**
     * Enhanced extraction session with comprehensive monitoring
     */
    private function run_extraction_session_with_monitoring($extraction_id) {
        $session_start = microtime(true);
        $session_results = [];

        try {
            // Fetch listings data with retry logic
            $listings_result = $this->fetch_listings_with_comprehensive_retry($extraction_id);
            $this->sync_metrics['listings_fetched'] = count($listings_result['listings'] ?? []);

            // Fetch related data with validation
            $related_result = $this->fetch_related_data_with_validation($extraction_id, $listings_result);
            $this->sync_metrics['related_data_fetched'] = array_sum(array_map('count', $related_result));

            // Process data with unified logic
            $processing_result = $this->process_sync_data_unified($extraction_id, $listings_result, $related_result);
            $this->sync_metrics['processing_result'] = $processing_result;

            $session_results = [
                'success' => true,
                'listings_processed' => $processing_result['processed'] ?? 0,
                'errors' => $processing_result['errors'] ?? [],
                'duration' => microtime(true) - $session_start,
                'metrics' => $this->sync_metrics
            ];

        } catch (Exception $e) {
            $session_results = [
                'success' => false,
                'error' => $e->getMessage(),
                'duration' => microtime(true) - $session_start,
                'metrics' => $this->sync_metrics
            ];

            // Check if we should retry
            if ($this->should_retry_sync($e)) {
                return $this->retry_sync($extraction_id, $e);
            }

            throw $e;
        }

        return $session_results;
    }

    /**
     * Fetch listings with comprehensive retry logic
     */
    private function fetch_listings_with_comprehensive_retry($extraction_id) {
        $max_retries = 3;
        $retry_count = 0;
        $last_exception = null;

        while ($retry_count < $max_retries) {
            try {
                $this->log_sync_step($extraction_id, "Fetching listings (attempt " . ($retry_count + 1) . "/{$max_retries})");

                // Get extraction profile and build filter query
                $extraction = get_post($extraction_id);
                $filter_query = $this->build_filter_query_from_extraction($extraction);

                // Collect listings using API client
                $collected_listings = [];
                $this->api_client->fetch_listings($filter_query, function($batch) use (&$collected_listings) {
                    $collected_listings = array_merge($collected_listings, $batch);
                }, $extraction_id);

                if (empty($collected_listings)) {
                    throw new Exception("No listings returned from API");
                }

                $this->sync_metrics['api_fetch_attempts'] = $retry_count + 1;
                return ['listings' => $collected_listings];

            } catch (Exception $e) {
                $last_exception = $e;
                $retry_count++;

                if ($retry_count < $max_retries) {
                    $delay = min(60, pow(2, $retry_count) * 5); // Exponential backoff with cap
                    $this->log_sync_step($extraction_id, "Listings fetch failed, retrying in {$delay}s: " . $e->getMessage());
                    sleep($delay);
                } else {
                    $this->log_sync_step($extraction_id, "Listings fetch failed after {$max_retries} attempts: " . $e->getMessage());
                }
            }
        }

        throw $last_exception;
    }

    /**
     * Build filter query from extraction profile
     */
    private function build_filter_query_from_extraction($extraction) {
        // Get saved filters from extraction meta
        $filters = get_post_meta($extraction->ID, '_bme_filters', true) ?: [];
        $filter_parts = [];

        // Build OData filter query from saved filters
        foreach ($filters as $field => $value) {
            if (!empty($value)) {
                switch ($field) {
                    case 'standard_status':
                        if (is_array($value)) {
                            $status_filters = array_map(function($status) {
                                return "StandardStatus eq '{$status}'";
                            }, $value);
                            $filter_parts[] = '(' . implode(' or ', $status_filters) . ')';
                        } else {
                            $filter_parts[] = "StandardStatus eq '{$value}'";
                        }
                        break;
                    case 'property_type':
                        if (is_array($value)) {
                            $type_filters = array_map(function($type) {
                                return "PropertyType eq '{$type}'";
                            }, $value);
                            $filter_parts[] = '(' . implode(' or ', $type_filters) . ')';
                        } else {
                            $filter_parts[] = "PropertyType eq '{$value}'";
                        }
                        break;
                    case 'modification_timestamp_start':
                        $filter_parts[] = "ModificationTimestamp ge {$value}";
                        break;
                }
            }
        }

        // Default filter if none specified
        if (empty($filter_parts)) {
            $filter_parts[] = "StandardStatus eq 'Active'";
        }

        return implode(' and ', $filter_parts);
    }

    /**
     * Fetch related data with comprehensive validation
     */
    private function fetch_related_data_with_validation($extraction_id, $listings_result) {
        $this->log_sync_step($extraction_id, 'Fetching related data (agents, offices, open houses)');

        $listings = $listings_result['listings'] ?? [];
        if (empty($listings)) {
            return ['agents' => [], 'offices' => [], 'open_houses' => []];
        }

        // Extract IDs for related data
        $agent_ids = [];
        $office_ids = [];
        $listing_keys = [];

        foreach ($listings as $listing) {
            if (!empty($listing['ListingKey'])) {
                $listing_keys[] = $listing['ListingKey'];
            }
            if (!empty($listing['ListAgentMlsId'])) {
                $agent_ids[] = $listing['ListAgentMlsId'];
            }
            if (!empty($listing['BuyerAgentMlsId'])) {
                $agent_ids[] = $listing['BuyerAgentMlsId'];
            }
            if (!empty($listing['ListOfficeMlsId'])) {
                $office_ids[] = $listing['ListOfficeMlsId'];
            }
            if (!empty($listing['BuyerOfficeMlsId'])) {
                $office_ids[] = $listing['BuyerOfficeMlsId'];
            }
        }

        try {
            $related_data = $this->api_client->fetch_related_data(
                array_unique($agent_ids),
                array_unique($office_ids),
                array_unique($listing_keys),
                $extraction_id
            );

            // Validate open houses data specifically
            $this->validate_open_houses_data($related_data['open_houses'] ?? [], $listing_keys);

            return $related_data;

        } catch (Exception $e) {
            $this->log_sync_step($extraction_id, 'Related data fetch failed: ' . $e->getMessage());

            // Return partial data rather than failing completely
            return [
                'agents' => [],
                'offices' => [],
                'open_houses' => []
            ];
        }
    }

    /**
     * Validate open houses data integrity
     */
    private function validate_open_houses_data($open_houses_data, $expected_listing_keys) {
        $validation_errors = [];

        // Check for missing open house data for active listings
        $open_house_listing_keys = array_keys($open_houses_data);
        $missing_keys = array_diff($expected_listing_keys, $open_house_listing_keys);

        if (!empty($missing_keys)) {
            $this->sync_metrics['open_houses_missing_keys'] = count($missing_keys);
            error_log("BME Sync: Missing open house data for " . count($missing_keys) . " listing keys");
        }

        // Validate individual open house records
        $invalid_records = 0;
        foreach ($open_houses_data as $listing_key => $open_houses) {
            if (!is_array($open_houses)) {
                $invalid_records++;
                continue;
            }

            foreach ($open_houses as $open_house) {
                if (!is_array($open_house) || empty($open_house['ListingKey'])) {
                    $invalid_records++;
                }
            }
        }

        if ($invalid_records > 0) {
            $this->sync_metrics['open_houses_invalid_records'] = $invalid_records;
            error_log("BME Sync: Found {$invalid_records} invalid open house records");
        }
    }

    /**
     * Process sync data with unified logic for all scenarios
     */
    private function process_sync_data_unified($extraction_id, $listings_result, $related_data) {
        $this->log_sync_step($extraction_id, 'Processing listings and related data');

        $listings = $listings_result['listings'] ?? [];
        if (empty($listings)) {
            throw new Exception("No listings to process");
        }

        // Use the enhanced data processor
        return $this->data_processor->process_listings_batch($extraction_id, $listings, $related_data);
    }

    /**
     * Verify sync integrity after completion
     */
    private function verify_sync_integrity($extraction_id, $sync_result) {
        $this->log_sync_step($extraction_id, 'Verifying sync integrity');

        try {
            // Use comprehensive sync verifier
            $sync_verifier = bme_pro()->get('sync_verifier');
            $verification_results = $sync_verifier->verify_sync_integrity($extraction_id);

            // Store verification metrics
            $this->sync_metrics['verification_summary'] = $sync_verifier->get_verification_summary($verification_results);

            // Check for processing errors from current sync
            if (!empty($sync_result['errors'])) {
                $error_count = count($sync_result['errors']);
                $this->sync_metrics['processing_errors'] = $error_count;
                error_log("BME Sync: Found {$error_count} processing errors during sync");
            }

            // Auto-recover from minor issues if recommended
            if ($sync_verifier->should_auto_recover($verification_results)) {
                $this->log_sync_step($extraction_id, 'Running auto-recovery for detected sync issues');

                $recovery_results = $sync_verifier->auto_recover_sync_issues($verification_results);
                $this->sync_metrics['auto_recovery'] = $recovery_results;

                if ($recovery_results['recovered_issues'] > 0) {
                    $this->log_sync_step($extraction_id, "Auto-recovery completed: {$recovery_results['recovered_issues']} issues resolved");
                }
            }

            $this->sync_metrics['integrity_check_passed'] = true;

        } catch (Exception $e) {
            error_log("BME Sync: Integrity verification failed: " . $e->getMessage());
            $this->sync_metrics['integrity_check_passed'] = false;
            $this->sync_metrics['verification_error'] = $e->getMessage();
        }
    }

    /**
     * Verify open house sync integrity specifically
     */
    private function verify_open_house_sync_integrity($extraction_id) {
        global $wpdb;

        $open_houses_table = bme_pro()->get('db')->get_table('open_houses');

        // Check for records with pending_deletion status (indicates incomplete sync)
        $pending_deletion_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$open_houses_table} WHERE sync_status = %s",
            'pending_deletion'
        ));

        if ($pending_deletion_count > 0) {
            error_log("BME Sync Warning: Found {$pending_deletion_count} open house records with pending_deletion status");
            $this->sync_metrics['open_houses_pending_deletion'] = $pending_deletion_count;
        }

        // Check for very old sync timestamps (indicates stale data)
        $stale_records_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$open_houses_table} WHERE sync_timestamp < %s",
            date('Y-m-d H:i:s', strtotime('-7 days'))
        ));

        if ($stale_records_count > 0) {
            $this->sync_metrics['open_houses_stale_records'] = $stale_records_count;
        }
    }

    /**
     * Handle sync failure with comprehensive logging and retry logic
     */
    private function handle_sync_failure($extraction_id, $exception) {
        $this->log_sync_failure($extraction_id, $exception);

        $failure_result = [
            'success' => false,
            'error' => $exception->getMessage(),
            'error_type' => get_class($exception),
            'sync_context' => $this->sync_context,
            'metrics' => $this->sync_metrics
        ];

        // Log to activity logger
        if ($this->activity_logger) {
            $this->activity_logger->log_extraction_activity(
                BME_Activity_Logger::ACTION_FAILED,
                $extraction_id,
                [
                    'sync_type' => $this->sync_context['sync_type'],
                    'error_message' => $exception->getMessage(),
                    'error_trace' => substr($exception->getTraceAsString(), 0, 2000),
                    'sync_metrics' => $this->sync_metrics
                ],
                ['severity' => BME_Activity_Logger::SEVERITY_ERROR]
            );
        }

        return $failure_result;
    }

    /**
     * Initialize sync metrics tracking
     */
    private function init_sync_metrics() {
        $this->sync_metrics = [
            'start_time' => microtime(true),
            'preconditions_passed' => false,
            'listings_fetched' => 0,
            'related_data_fetched' => 0,
            'api_fetch_attempts' => 0,
            'processing_errors' => 0,
            'integrity_check_passed' => false
        ];
    }

    /**
     * Utility methods for extraction management
     */
    private function is_extraction_running($extraction_id) {
        return get_transient("bme_extraction_running_{$extraction_id}") !== false;
    }

    private function set_extraction_lock($extraction_id) {
        set_transient("bme_extraction_running_{$extraction_id}", time(), 2 * HOUR_IN_SECONDS);
    }

    private function clear_extraction_lock($extraction_id) {
        delete_transient("bme_extraction_running_{$extraction_id}");
    }

    private function should_use_batch_processing($extraction_id) {
        // Use existing batch manager logic
        $batch_manager = bme_pro()->get('batch_manager');
        return $batch_manager && !$batch_manager->is_batch_extraction($extraction_id);
    }

    private function validate_api_connectivity() {
        // Basic API connectivity check
        try {
            // This would be a lightweight API call to verify connectivity
            return true; // Placeholder
        } catch (Exception $e) {
            return false;
        }
    }

    private function should_retry_sync($exception) {
        // Determine if sync should be retried based on exception type
        $retryable_errors = [
            'Connection timeout',
            'API rate limit',
            'Temporary unavailable'
        ];

        $error_message = $exception->getMessage();
        foreach ($retryable_errors as $retryable_error) {
            if (stripos($error_message, $retryable_error) !== false) {
                return $this->sync_context['retry_count'] < $this->sync_context['max_retries'];
            }
        }

        return false;
    }

    private function retry_sync($extraction_id, $exception) {
        $this->sync_context['retry_count']++;
        $delay = min(300, pow(2, $this->sync_context['retry_count']) * 30); // Max 5 minute delay

        $this->log_sync_step($extraction_id, "Retrying sync (attempt {$this->sync_context['retry_count']}) in {$delay}s: " . $exception->getMessage());

        sleep($delay);
        return $this->execute_sync($extraction_id, $this->sync_context);
    }

    /**
     * Logging methods
     */
    private function log_sync_start($extraction_id) {
        $message = "Starting unified sync - Type: {$this->sync_context['sync_type']}, Source: {$this->sync_context['source']}";
        error_log("BME Sync Coordinator: {$message} for extraction {$extraction_id}");

        if ($this->activity_logger) {
            $this->activity_logger->log_extraction_activity(
                BME_Activity_Logger::ACTION_STARTED,
                $extraction_id,
                $this->sync_context,
                ['severity' => BME_Activity_Logger::SEVERITY_INFO]
            );
        }
    }

    private function log_sync_step($extraction_id, $message) {
        error_log("BME Sync Coordinator: {$message} (extraction {$extraction_id})");
    }

    private function log_sync_success($extraction_id, $result) {
        $processed = $result['listings_processed'] ?? 0;
        $duration = $result['duration'] ?? 0;

        $message = "Sync completed successfully - Processed: {$processed} listings in " . round($duration, 2) . "s";
        error_log("BME Sync Coordinator: {$message} (extraction {$extraction_id})");

        if ($this->activity_logger) {
            $this->activity_logger->log_extraction_activity(
                BME_Activity_Logger::ACTION_COMPLETED,
                $extraction_id,
                array_merge($this->sync_context, $result),
                ['severity' => BME_Activity_Logger::SEVERITY_SUCCESS]
            );
        }
    }

    private function log_sync_failure($extraction_id, $exception) {
        $message = "Sync failed: " . $exception->getMessage();
        error_log("BME Sync Coordinator: {$message} (extraction {$extraction_id})");
    }
}