<?php
/**
 * Optimized Extraction Engine
 *
 * Enhanced extraction engine with advanced chunked/paginated processing for large datasets,
 * memory optimization, intelligent batch sizing, and improved error recovery.
 *
 * @package Bridge_MLS_Extractor_Pro
 * @version 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class BME_Extraction_Engine_Optimized extends BME_Extraction_Engine {

    /**
     * Enhanced batch configuration
     */
    const DEFAULT_CHUNK_SIZE = 50;           // Listings per API call
    const DEFAULT_BATCH_SIZE = 200;          // Listings per database batch
    const DEFAULT_SESSION_LIMIT = 1000;     // Listings per session
    const MEMORY_LIMIT_THRESHOLD = 0.8;     // 80% of memory limit
    const MAX_EXECUTION_TIME = 270;         // 4.5 minutes (safe for 5-min limit)

    /**
     * Adaptive batch sizing configuration
     */
    private $adaptive_config = [
        'min_chunk_size' => 10,
        'max_chunk_size' => 100,
        'min_batch_size' => 50,
        'max_batch_size' => 500,
        'performance_window' => 10, // Track last 10 batches for adaptation
        'target_batch_time' => 5.0, // Target 5 seconds per batch
    ];

    /**
     * Performance tracking
     */
    private $performance_metrics = [
        'batch_times' => [],
        'memory_usage' => [],
        'api_response_times' => [],
        'database_times' => [],
        'error_counts' => [],
    ];

    /**
     * Current processing state
     */
    private $processing_state = [
        'current_chunk_size' => self::DEFAULT_CHUNK_SIZE,
        'current_batch_size' => self::DEFAULT_BATCH_SIZE,
        'total_processed' => 0,
        'session_processed' => 0,
        'errors_this_session' => 0,
        'start_time' => null,
        'last_memory_check' => null,
    ];

    /**
     * Enhanced extraction with intelligent chunking and adaptive batch sizing
     *
     * @param int $extraction_id Extraction profile ID
     * @param bool $is_resync Whether this is a resync operation
     * @param bool $is_background Whether to run in background mode
     * @return array Extraction results
     */
    public function run_extraction_optimized($extraction_id, $is_resync = false, $is_background = true) {
        $this->processing_state['start_time'] = microtime(true);
        $this->processing_state['last_memory_check'] = memory_get_usage(true);

        $this->log_extraction_step($extraction_id, 'info', 'Starting optimized extraction with adaptive batch sizing');

        try {
            // Get extraction profile with enhanced configuration
            $profile = $this->get_extraction_profile($extraction_id);
            if (!$profile) {
                throw new Exception('Invalid extraction profile');
            }

            // Initialize adaptive configuration based on profile
            $this->initialize_adaptive_config($profile);

            // Determine extraction strategy
            $strategy = $this->determine_extraction_strategy($profile, $is_resync);

            switch ($strategy['mode']) {
                case 'chunked_api':
                    return $this->run_chunked_api_extraction($extraction_id, $strategy, $is_resync);

                case 'paginated_database':
                    return $this->run_paginated_database_extraction($extraction_id, $strategy, $is_resync);

                case 'hybrid':
                    return $this->run_hybrid_extraction($extraction_id, $strategy, $is_resync);

                default:
                    return $this->run_standard_extraction($extraction_id, $is_resync, $is_background);
            }

        } catch (Exception $e) {
            $this->log_extraction_step($extraction_id, 'error', 'Optimized extraction failed: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Run chunked API extraction for large datasets
     *
     * @param int $extraction_id Extraction profile ID
     * @param array $strategy Extraction strategy
     * @param bool $is_resync Whether this is a resync
     * @return array Results
     */
    private function run_chunked_api_extraction($extraction_id, $strategy, $is_resync) {
        $this->log_extraction_step($extraction_id, 'info', 'Running chunked API extraction');

        $total_processed = 0;
        $session_metrics = [
            'chunks_processed' => 0,
            'api_calls' => 0,
            'database_batches' => 0,
            'errors' => 0,
        ];

        // Get total count for progress tracking
        $total_count = $this->api_client->get_listing_count($strategy['filters']);
        $this->update_extraction_progress($extraction_id, 0, $total_count, 'Starting chunked extraction...');

        $offset = $strategy['resume_offset'] ?? 0;
        $pending_batch = [];

        while ($offset < $total_count && $this->should_continue_processing()) {
            try {
                // Adaptive chunk sizing based on performance
                $chunk_size = $this->get_adaptive_chunk_size();

                // Fetch chunk from API
                $chunk_start_time = microtime(true);
                $chunk_data = $this->api_client->get_listings_chunk(
                    $strategy['filters'],
                    $offset,
                    $chunk_size
                );

                $api_time = microtime(true) - $chunk_start_time;
                $this->track_api_performance($api_time, count($chunk_data));

                if (empty($chunk_data)) {
                    break;
                }

                // Add to pending batch
                $pending_batch = array_merge($pending_batch, $chunk_data);

                // Process batch when it reaches optimal size
                if (count($pending_batch) >= $this->processing_state['current_batch_size']) {
                    $batch_result = $this->process_optimized_batch($extraction_id, $pending_batch);
                    $total_processed += $batch_result['processed'];
                    $session_metrics['database_batches']++;

                    // Clear processed batch
                    $pending_batch = [];

                    // Update progress
                    $this->update_extraction_progress(
                        $extraction_id,
                        $total_processed,
                        $total_count,
                        "Processed {$total_processed} of {$total_count} listings"
                    );

                    // Adaptive batch size adjustment
                    $this->adjust_batch_sizes($batch_result['processing_time']);
                }

                $offset += $chunk_size;
                $session_metrics['chunks_processed']++;
                $session_metrics['api_calls']++;

                // Memory and time checks
                $this->check_resource_limits($extraction_id);

            } catch (Exception $e) {
                $this->handle_extraction_error($extraction_id, $e, $session_metrics);
                $session_metrics['errors']++;

                // Adaptive error handling
                if ($session_metrics['errors'] > 5) {
                    throw new Exception('Too many errors in chunked extraction: ' . $e->getMessage());
                }

                // Reduce chunk size on errors
                $this->processing_state['current_chunk_size'] = max(
                    $this->adaptive_config['min_chunk_size'],
                    intval($this->processing_state['current_chunk_size'] * 0.8)
                );
            }
        }

        // Process remaining batch
        if (!empty($pending_batch)) {
            $batch_result = $this->process_optimized_batch($extraction_id, $pending_batch);
            $total_processed += $batch_result['processed'];
            $session_metrics['database_batches']++;
        }

        return [
            'success' => true,
            'total_processed' => $total_processed,
            'session_metrics' => $session_metrics,
            'performance_metrics' => $this->get_performance_summary()
        ];
    }

    /**
     * Run paginated database extraction for updates/resyncs
     *
     * @param int $extraction_id Extraction profile ID
     * @param array $strategy Extraction strategy
     * @param bool $is_resync Whether this is a resync
     * @return array Results
     */
    private function run_paginated_database_extraction($extraction_id, $strategy, $is_resync) {
        global $wpdb;

        $this->log_extraction_step($extraction_id, 'info', 'Running paginated database extraction');

        $total_processed = 0;
        $offset = $strategy['resume_offset'] ?? 0;
        $page_size = $this->processing_state['current_batch_size'];

        // Get total count for progress tracking
        $total_count = $wpdb->get_var("
            SELECT COUNT(*)
            FROM {$this->db_manager->get_tables()['listings']}
            WHERE " . $strategy['where_clause']
        );

        while ($offset < $total_count && $this->should_continue_processing()) {
            try {
                // Get batch of listing IDs to update
                $listing_ids = $wpdb->get_col($wpdb->prepare("
                    SELECT listing_id
                    FROM {$this->db_manager->get_tables()['listings']}
                    WHERE " . $strategy['where_clause'] . "
                    ORDER BY modification_timestamp ASC
                    LIMIT %d OFFSET %d",
                    $page_size,
                    $offset
                ));

                if (empty($listing_ids)) {
                    break;
                }

                // Fetch updated data from API in chunks
                $updated_listings = [];
                foreach (array_chunk($listing_ids, $this->processing_state['current_chunk_size']) as $chunk) {
                    $chunk_data = $this->api_client->get_listings_by_ids($chunk);
                    $updated_listings = array_merge($updated_listings, $chunk_data);
                }

                // Process the updated batch
                $batch_result = $this->process_optimized_batch($extraction_id, $updated_listings, 'update');
                $total_processed += $batch_result['processed'];

                $offset += $page_size;

                // Update progress
                $this->update_extraction_progress(
                    $extraction_id,
                    $total_processed,
                    $total_count,
                    "Updated {$total_processed} of {$total_count} listings"
                );

                // Resource checks
                $this->check_resource_limits($extraction_id);

            } catch (Exception $e) {
                $this->log_extraction_step($extraction_id, 'error', 'Paginated extraction error: ' . $e->getMessage());
                $offset += $page_size; // Skip problematic batch
            }
        }

        return [
            'success' => true,
            'total_processed' => $total_processed,
            'mode' => 'paginated_database'
        ];
    }

    /**
     * Run hybrid extraction combining API and database operations
     *
     * @param int $extraction_id Extraction profile ID
     * @param array $strategy Extraction strategy
     * @param bool $is_resync Whether this is a resync
     * @return array Results
     */
    private function run_hybrid_extraction($extraction_id, $strategy, $is_resync) {
        $this->log_extraction_step($extraction_id, 'info', 'Running hybrid extraction');

        $results = [];

        // Phase 1: New listings via chunked API
        if ($strategy['fetch_new']) {
            $results['new_listings'] = $this->run_chunked_api_extraction(
                $extraction_id,
                array_merge($strategy, ['mode' => 'chunked_api']),
                false
            );
        }

        // Phase 2: Update existing listings via paginated database
        if ($strategy['update_existing']) {
            $results['updated_listings'] = $this->run_paginated_database_extraction(
                $extraction_id,
                array_merge($strategy, ['mode' => 'paginated_database']),
                true
            );
        }

        return [
            'success' => true,
            'hybrid_results' => $results,
            'total_processed' => ($results['new_listings']['total_processed'] ?? 0) +
                               ($results['updated_listings']['total_processed'] ?? 0)
        ];
    }

    /**
     * Process optimized batch with enhanced error handling and performance tracking
     *
     * @param int $extraction_id Extraction profile ID
     * @param array $listings Listings to process
     * @param string $operation Type of operation (insert/update)
     * @return array Processing results
     */
    private function process_optimized_batch($extraction_id, $listings, $operation = 'insert') {
        $batch_start_time = microtime(true);
        $processed = 0;
        $errors = 0;

        try {
            // Pre-processing optimizations
            $listings = $this->preprocess_batch($listings);

            // Extract related data in bulk to avoid N+1 queries
            $related_data = $this->extract_related_data_bulk($listings);

            // Process listings with optimized database operations
            $result = $this->data_processor->process_listings_batch_optimized(
                $extraction_id,
                $listings,
                $related_data,
                $operation
            );

            $processed = $result['processed'] ?? count($listings);
            $errors = $result['errors'] ?? 0;

            // Post-processing cleanup
            $this->cleanup_batch_memory($listings);

        } catch (Exception $e) {
            $this->log_extraction_step($extraction_id, 'error', 'Batch processing error: ' . $e->getMessage());
            $errors++;
        }

        $processing_time = microtime(true) - $batch_start_time;
        $this->track_batch_performance($processing_time, count($listings), $processed, $errors);

        return [
            'processed' => $processed,
            'errors' => $errors,
            'processing_time' => $processing_time
        ];
    }

    /**
     * Determine optimal extraction strategy based on profile and data size
     *
     * @param array $profile Extraction profile
     * @param bool $is_resync Whether this is a resync
     * @return array Strategy configuration
     */
    private function determine_extraction_strategy($profile, $is_resync) {
        $estimated_count = $this->estimate_extraction_size($profile);

        $strategy = [
            'estimated_count' => $estimated_count,
            'filters' => $profile['filters'] ?? [],
            'resume_offset' => 0,
        ];

        if ($is_resync) {
            // Resync operations use hybrid approach
            $strategy['mode'] = 'hybrid';
            $strategy['fetch_new'] = true;
            $strategy['update_existing'] = true;
            $strategy['where_clause'] = "modification_timestamp > DATE_SUB(NOW(), INTERVAL 24 HOUR)";
        } elseif ($estimated_count > 10000) {
            // Large datasets use chunked API extraction
            $strategy['mode'] = 'chunked_api';
        } elseif ($estimated_count > 1000) {
            // Medium datasets use paginated database
            $strategy['mode'] = 'paginated_database';
            $strategy['where_clause'] = "1=1"; // All listings
        } else {
            // Small datasets use standard extraction
            $strategy['mode'] = 'standard';
        }

        return $strategy;
    }

    /**
     * Get adaptive chunk size based on recent performance
     *
     * @return int Optimal chunk size
     */
    private function get_adaptive_chunk_size() {
        $recent_api_times = array_slice($this->performance_metrics['api_response_times'], -5);

        if (empty($recent_api_times)) {
            return $this->processing_state['current_chunk_size'];
        }

        $avg_response_time = array_sum($recent_api_times) / count($recent_api_times);

        // Adjust chunk size based on API performance
        if ($avg_response_time > 10.0) {
            // Slow API - reduce chunk size
            $new_size = max(
                $this->adaptive_config['min_chunk_size'],
                intval($this->processing_state['current_chunk_size'] * 0.8)
            );
        } elseif ($avg_response_time < 3.0) {
            // Fast API - increase chunk size
            $new_size = min(
                $this->adaptive_config['max_chunk_size'],
                intval($this->processing_state['current_chunk_size'] * 1.2)
            );
        } else {
            $new_size = $this->processing_state['current_chunk_size'];
        }

        $this->processing_state['current_chunk_size'] = $new_size;
        return $new_size;
    }

    /**
     * Adjust batch sizes based on processing performance
     *
     * @param float $processing_time Last batch processing time
     */
    private function adjust_batch_sizes($processing_time) {
        $target_time = $this->adaptive_config['target_batch_time'];

        if ($processing_time > $target_time * 1.5) {
            // Too slow - reduce batch size
            $this->processing_state['current_batch_size'] = max(
                $this->adaptive_config['min_batch_size'],
                intval($this->processing_state['current_batch_size'] * 0.8)
            );
        } elseif ($processing_time < $target_time * 0.7) {
            // Too fast - increase batch size
            $this->processing_state['current_batch_size'] = min(
                $this->adaptive_config['max_batch_size'],
                intval($this->processing_state['current_batch_size'] * 1.2)
            );
        }
    }

    /**
     * Check resource limits and determine if processing should continue
     *
     * @param int $extraction_id Extraction profile ID
     * @return bool Whether to continue processing
     */
    private function check_resource_limits($extraction_id) {
        $current_time = microtime(true);
        $current_memory = memory_get_usage(true);

        // Check execution time
        if (($current_time - $this->processing_state['start_time']) > self::MAX_EXECUTION_TIME) {
            $this->log_extraction_step($extraction_id, 'info', 'Execution time limit reached, ending session');
            return false;
        }

        // Check memory usage
        $memory_limit = $this->get_memory_limit_bytes();
        if ($current_memory > ($memory_limit * self::MEMORY_LIMIT_THRESHOLD)) {
            $this->log_extraction_step($extraction_id, 'warning', 'Memory limit threshold reached, ending session');
            $this->cleanup_memory();
            return false;
        }

        // Check session limit
        if ($this->processing_state['session_processed'] >= self::DEFAULT_SESSION_LIMIT) {
            $this->log_extraction_step($extraction_id, 'info', 'Session limit reached, ending session');
            return false;
        }

        return true;
    }

    /**
     * Should continue processing check
     *
     * @return bool Whether to continue processing
     */
    private function should_continue_processing() {
        return $this->check_resource_limits(0); // Use 0 as dummy extraction_id for resource checks
    }

    /**
     * Preprocess batch for optimization
     *
     * @param array $listings Raw listings data
     * @return array Preprocessed listings
     */
    private function preprocess_batch($listings) {
        // Remove duplicates
        $unique_listings = [];
        $seen_ids = [];

        foreach ($listings as $listing) {
            $listing_id = $listing['ListingId'] ?? $listing['listing_id'] ?? null;
            if ($listing_id && !in_array($listing_id, $seen_ids)) {
                $unique_listings[] = $listing;
                $seen_ids[] = $listing_id;
            }
        }

        // Sort by listing ID for better database performance
        usort($unique_listings, function($a, $b) {
            $id_a = $a['ListingId'] ?? $a['listing_id'] ?? '';
            $id_b = $b['ListingId'] ?? $b['listing_id'] ?? '';
            return strcmp($id_a, $id_b);
        });

        return $unique_listings;
    }

    /**
     * Extract related data in bulk to avoid N+1 queries
     *
     * @param array $listings Listings data
     * @return array Related data (agents, offices, media, etc.)
     */
    private function extract_related_data_bulk($listings) {
        $agent_ids = [];
        $office_ids = [];
        $listing_ids = [];

        // Extract all IDs
        foreach ($listings as $listing) {
            $listing_ids[] = $listing['ListingId'] ?? $listing['listing_id'] ?? '';

            if (!empty($listing['ListAgentMlsId'])) {
                $agent_ids[] = $listing['ListAgentMlsId'];
            }
            if (!empty($listing['ListOfficeMlsId'])) {
                $office_ids[] = $listing['ListOfficeMlsId'];
            }
        }

        // Fetch related data in bulk
        $related_data = [
            'agents' => $this->fetch_agents_bulk(array_unique($agent_ids)),
            'offices' => $this->fetch_offices_bulk(array_unique($office_ids)),
            'media' => $this->fetch_media_bulk(array_unique($listing_ids)),
        ];

        return $related_data;
    }

    /**
     * Fetch agents in bulk
     *
     * @param array $agent_ids Agent IDs
     * @return array Agent data indexed by ID
     */
    private function fetch_agents_bulk($agent_ids) {
        if (empty($agent_ids)) {
            return [];
        }

        try {
            return $this->api_client->get_agents_bulk($agent_ids);
        } catch (Exception $e) {
            $this->log_extraction_step(0, 'warning', 'Failed to fetch agents in bulk: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Fetch offices in bulk
     *
     * @param array $office_ids Office IDs
     * @return array Office data indexed by ID
     */
    private function fetch_offices_bulk($office_ids) {
        if (empty($office_ids)) {
            return [];
        }

        try {
            return $this->api_client->get_offices_bulk($office_ids);
        } catch (Exception $e) {
            $this->log_extraction_step(0, 'warning', 'Failed to fetch offices in bulk: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Fetch media in bulk
     *
     * @param array $listing_ids Listing IDs
     * @return array Media data indexed by listing ID
     */
    private function fetch_media_bulk($listing_ids) {
        if (empty($listing_ids)) {
            return [];
        }

        try {
            return $this->api_client->get_media_bulk($listing_ids);
        } catch (Exception $e) {
            $this->log_extraction_step(0, 'warning', 'Failed to fetch media in bulk: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Clean up batch memory
     *
     * @param array $listings Processed listings
     */
    private function cleanup_batch_memory($listings) {
        // Clear processed data
        $listings = null;

        // Force garbage collection
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }

        // Update memory tracking
        $this->processing_state['last_memory_check'] = memory_get_usage(true);
    }

    /**
     * Track API performance
     *
     * @param float $response_time Response time in seconds
     * @param int $record_count Number of records returned
     */
    private function track_api_performance($response_time, $record_count) {
        $this->performance_metrics['api_response_times'][] = $response_time;

        // Keep only recent measurements
        if (count($this->performance_metrics['api_response_times']) > 20) {
            $this->performance_metrics['api_response_times'] = array_slice(
                $this->performance_metrics['api_response_times'], -20
            );
        }
    }

    /**
     * Track batch performance
     *
     * @param float $processing_time Processing time in seconds
     * @param int $input_count Input record count
     * @param int $processed_count Processed record count
     * @param int $error_count Error count
     */
    private function track_batch_performance($processing_time, $input_count, $processed_count, $error_count) {
        $this->performance_metrics['batch_times'][] = $processing_time;
        $this->performance_metrics['memory_usage'][] = memory_get_usage(true);
        $this->performance_metrics['error_counts'][] = $error_count;

        // Keep only recent measurements
        foreach (['batch_times', 'memory_usage', 'error_counts'] as $metric) {
            if (count($this->performance_metrics[$metric]) > $this->adaptive_config['performance_window']) {
                $this->performance_metrics[$metric] = array_slice(
                    $this->performance_metrics[$metric],
                    -$this->adaptive_config['performance_window']
                );
            }
        }

        // Update session counters
        $this->processing_state['session_processed'] += $processed_count;
        $this->processing_state['errors_this_session'] += $error_count;
    }

    /**
     * Get performance summary
     *
     * @return array Performance metrics summary
     */
    private function get_performance_summary() {
        $summary = [
            'total_time' => microtime(true) - $this->processing_state['start_time'],
            'total_processed' => $this->processing_state['total_processed'],
            'session_processed' => $this->processing_state['session_processed'],
            'errors_this_session' => $this->processing_state['errors_this_session'],
        ];

        if (!empty($this->performance_metrics['batch_times'])) {
            $summary['avg_batch_time'] = array_sum($this->performance_metrics['batch_times']) /
                                        count($this->performance_metrics['batch_times']);
            $summary['max_batch_time'] = max($this->performance_metrics['batch_times']);
        }

        if (!empty($this->performance_metrics['api_response_times'])) {
            $summary['avg_api_time'] = array_sum($this->performance_metrics['api_response_times']) /
                                      count($this->performance_metrics['api_response_times']);
            $summary['max_api_time'] = max($this->performance_metrics['api_response_times']);
        }

        $summary['peak_memory_mb'] = !empty($this->performance_metrics['memory_usage']) ?
                                    max($this->performance_metrics['memory_usage']) / (1024 * 1024) : 0;

        return $summary;
    }

    /**
     * Initialize adaptive configuration based on extraction profile
     *
     * @param array $profile Extraction profile
     */
    private function initialize_adaptive_config($profile) {
        // Adjust configuration based on profile settings
        if (isset($profile['batch_size'])) {
            $this->processing_state['current_batch_size'] = intval($profile['batch_size']);
        }

        if (isset($profile['chunk_size'])) {
            $this->processing_state['current_chunk_size'] = intval($profile['chunk_size']);
        }

        // Adjust based on server capacity
        $memory_limit = $this->get_memory_limit_bytes();
        if ($memory_limit < 512 * 1024 * 1024) { // Less than 512MB
            $this->adaptive_config['max_batch_size'] = 100;
            $this->adaptive_config['max_chunk_size'] = 25;
        }
    }

    /**
     * Get memory limit in bytes
     *
     * @return int Memory limit in bytes
     */
    private function get_memory_limit_bytes() {
        $memory_limit = ini_get('memory_limit');
        if ($memory_limit == -1) {
            return PHP_INT_MAX;
        }

        $memory_limit = trim($memory_limit);
        $last = strtolower($memory_limit[strlen($memory_limit)-1]);
        $memory_limit = intval($memory_limit);

        switch($last) {
            case 'g': $memory_limit *= 1024;
            case 'm': $memory_limit *= 1024;
            case 'k': $memory_limit *= 1024;
        }

        return $memory_limit;
    }

    /**
     * Estimate extraction size for strategy planning
     *
     * @param array $profile Extraction profile
     * @return int Estimated number of listings
     */
    private function estimate_extraction_size($profile) {
        try {
            return $this->api_client->get_listing_count($profile['filters'] ?? []);
        } catch (Exception $e) {
            // Fallback to conservative estimate
            return 1000;
        }
    }

    /**
     * Handle extraction errors with adaptive recovery
     *
     * @param int $extraction_id Extraction profile ID
     * @param Exception $e Exception that occurred
     * @param array $session_metrics Current session metrics
     */
    private function handle_extraction_error($extraction_id, $e, &$session_metrics) {
        $this->log_extraction_step($extraction_id, 'error', 'Extraction error: ' . $e->getMessage());

        // Adaptive error handling
        $session_metrics['errors']++;

        // Reduce batch sizes on errors
        $this->processing_state['current_batch_size'] = max(
            $this->adaptive_config['min_batch_size'],
            intval($this->processing_state['current_batch_size'] * 0.9)
        );

        $this->processing_state['current_chunk_size'] = max(
            $this->adaptive_config['min_chunk_size'],
            intval($this->processing_state['current_chunk_size'] * 0.9)
        );

        // Add delay on repeated errors
        if ($session_metrics['errors'] > 3) {
            sleep(min($session_metrics['errors'], 10)); // Progressive backoff up to 10 seconds
        }
    }

    /**
     * Clean up memory and resources
     */
    private function cleanup_memory() {
        // Clear performance metrics history
        foreach ($this->performance_metrics as $key => $metric) {
            $this->performance_metrics[$key] = array_slice($metric, -5); // Keep only last 5
        }

        // Force garbage collection
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }

        $this->log_extraction_step(0, 'info', 'Memory cleanup completed. Current usage: ' .
                                  round(memory_get_usage(true) / (1024 * 1024), 2) . 'MB');
    }
}