<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * High-performance API client with robust, sequential fetching for related data
 *
 * Handles all communication with the Bridge Interactive API including authentication,
 * rate limiting, retry logic, and error handling. Supports fetching listings, media,
 * open houses, and rooms with automatic pagination and filtering.
 *
 * @package Bridge_MLS_Extractor_Pro
 * @since 1.0.0
 * @version 2.1.2
 */
class BME_API_Client {

    /**
     * @var string API authentication token
     */
    private $api_token;

    /**
     * @var string Base URL for API endpoints
     */
    private $base_url;

    /**
     * @var int Request timeout in seconds
     */
    private $timeout;

    /**
     * @var int Delay between API requests to respect rate limits
     */
    private $rate_limit_delay = 2;

    /**
     * @var BME_Error_Manager|null Error manager instance for logging
     */
    private $error_manager;

    /**
     * Constructor
     *
     * Initializes API client with credentials from WordPress options and sets up
     * error handling with fallback support if error manager is not available.
     */
    public function __construct() {
        $credentials = get_option('bme_pro_api_credentials', []);
        $this->api_token = $credentials['server_token'] ?? null;
        $this->base_url = $credentials['endpoint_url'] ?? null;
        $this->timeout = BME_API_TIMEOUT;
        
        // Add class dependency check before instantiation
        if (class_exists('BME_Error_Manager')) {
            $this->error_manager = new BME_Error_Manager();
        } else {
            // Fallback to basic error logging if error manager not available
            $this->error_manager = null;
            error_log('BME API Client: BME_Error_Manager class not found, using fallback error logging');
        }
    }

    /**
     * Safe error logging that handles null error manager
     *
     * Provides fallback error logging when the error manager is not available,
     * ensuring errors are always captured even if dependencies are missing.
     *
     * @param string $code Error code identifier
     * @param array $context Additional context for the error
     * @return void
     * @access private
     */
    private function log_error($code, $context = []) {
        if ($this->error_manager && method_exists($this->error_manager, 'log_error')) {
            $this->error_manager->log_error($code, $context);
        } else {
            // Fallback to standard error logging
            error_log(sprintf('BME API Error [%s]: %s', $code, json_encode($context)));
        }
    }

    /**
     * Validate API credentials with enhanced error handling
     *
     * Tests the API connection by making a simple request to verify that
     * credentials are valid and the API endpoint is accessible.
     *
     * @return bool True if credentials are valid, false otherwise
     * @throws Exception If API endpoint is not configured
     */
    public function validate_credentials() {
        if (empty($this->api_token) || empty($this->base_url)) {
            $this->log_error('API_001', [
                'api_token_present' => !empty($this->api_token),
                'base_url_present' => !empty($this->base_url)
            ]);
            throw new Exception('API credentials not configured');
        }

        $test_url = add_query_arg(['access_token' => $this->api_token, '$top' => 1], $this->base_url);

        $response = wp_remote_get($test_url, ['timeout' => 30, 'headers' => ['Accept' => 'application/json']]);

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $error_code = $response->get_error_code();
            
            // Determine appropriate error code based on the type of error
            if (strpos($error_code, 'http_request_failed') !== false || strpos($error_message, 'timeout') !== false) {
                $this->log_error('API_003', [
                    'wp_error_code' => $error_code,
                    'wp_error_message' => $error_message,
                    'test_url' => $test_url
                ]);
            } else {
                $this->log_error('NET_001', [
                    'wp_error_code' => $error_code,
                    'wp_error_message' => $error_message
                ]);
            }
            
            throw new Exception('API connection failed: ' . $error_message);
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            $response_body = wp_remote_retrieve_body($response);
            
            if ($code === 401 || $code === 403) {
                $this->log_error('API_001', [
                    'response_code' => $code,
                    'response_body' => substr($response_body, 0, 500),
                    'credentials_configured' => true
                ]);
            } elseif ($code === 429) {
                $this->log_error('API_002', [
                    'response_code' => $code,
                    'response_body' => substr($response_body, 0, 500)
                ]);
            } else {
                $this->log_error('API_005', [
                    'response_code' => $code,
                    'response_body' => substr($response_body, 0, 500)
                ]);
            }
            
            throw new Exception('API authentication failed. Response code: ' . $code);
        }

        error_log('BME API: Credentials validation successful');
        return true;
    }

    /**
     * Fetch listings with pagination and filtering.
     */
    public function fetch_listings($filters, $callback = null, $extraction_id = null) {
        try {
            $this->validate_credentials();
        } catch (Exception $e) {
            $this->log_error('API_001', [
                'validation_error' => $e->getMessage(),
                'filters' => substr($filters, 0, 200) . '...'
            ]);
            throw $e;
        }

        $query_args = [
            'access_token' => $this->api_token,
            '$filter' => $filters,
            '$top' => min(BME_BATCH_SIZE, 200), // Bridge API limit is 200 max
            '$orderby' => 'ModificationTimestamp asc' // Always order by ModificationTimestamp
        ];

        $next_url = add_query_arg($query_args, $this->base_url);
        $total_processed = 0;
        $consecutive_errors = 0;
        $max_consecutive_errors = 5;

        do {
            $start_time = microtime(true);

            try {
                $response = $this->make_request_with_retry($next_url, 3, $extraction_id, ['type' => 'listings_fetch']);
                $data = $this->parse_response($response);
                
                // Reset error counter on successful request
                $consecutive_errors = 0;

                if (!empty($data['value'])) {
                    $batch_count = count($data['value']);
                    $total_processed += $batch_count;

                    if ($callback && is_callable($callback)) {
                        try {
                            $result = $callback($data['value'], $total_processed);
                            
                            // Handle session stop requests from callback
                            if (is_array($result) && !empty($result['stop_session'])) {
                                error_log("BME API: Session stop requested by callback after {$total_processed} listings");
                                break;
                            }
                        } catch (Exception $e) {
                            $this->log_error('BATCH_002', [
                                'callback_error' => $e->getMessage(),
                                'total_processed' => $total_processed,
                                'batch_count' => $batch_count
                            ]);
                            
                            // Continue processing despite callback error
                            error_log('BME API: Callback error, continuing extraction: ' . $e->getMessage());
                        }
                    }

                    $duration = microtime(true) - $start_time;
                    $this->log_performance_metric('listings_batch', $batch_count, $duration);
                    
                    // Log progress for monitoring
                    if ($total_processed % 1000 === 0) {
                        error_log("BME API: Processed {$total_processed} listings successfully");
                    }
                }

                $next_url = $data['@odata.nextLink'] ?? null;

                if ($next_url) {
                    sleep($this->rate_limit_delay);
                }

            } catch (Exception $e) {
                $consecutive_errors++;
                
                $this->log_error('API_005', [
                    'request_error' => $e->getMessage(),
                    'consecutive_errors' => $consecutive_errors,
                    'total_processed' => $total_processed,
                    'next_url' => substr($next_url ?? '', 0, 100) . '...'
                ]);

                if ($consecutive_errors >= $max_consecutive_errors) {
                    $this->log_error('BATCH_003', [
                        'consecutive_errors' => $consecutive_errors,
                        'total_processed' => $total_processed,
                        'final_error' => $e->getMessage()
                    ]);
                    throw new Exception("Extraction failed after {$consecutive_errors} consecutive errors. Last error: " . $e->getMessage());
                }

                // Progressive delay based on error count
                $error_delay = min($this->rate_limit_delay * pow(2, $consecutive_errors - 1), 30);
                error_log("BME API: Error #{$consecutive_errors}, retrying in {$error_delay} seconds: " . $e->getMessage());
                sleep($error_delay);
                
                // Don't advance next_url on error - retry the same request
                continue;
            }

        } while ($next_url);

        error_log("BME API: Fetch listings completed successfully. Total processed: {$total_processed}");
        return $total_processed;
    }

    /**
     * Fetch listings with session limit support for batch processing.
     */
    public function fetch_listings_with_session_limit($filters, $callback = null, $extraction_id = null) {
        try {
            $this->validate_credentials();
        } catch (Exception $e) {
            $this->log_error('API_001', [
                'validation_error' => $e->getMessage(),
                'filters' => substr($filters, 0, 200) . '...',
                'session_limited' => true
            ]);
            throw $e;
        }

        $query_args = [
            'access_token' => $this->api_token,
            '$filter' => $filters,
            '$top' => 200, // Use Bridge API maximum
            '$orderby' => 'ModificationTimestamp asc'
        ];

        $next_url = add_query_arg($query_args, $this->base_url);
        $total_processed = 0;
        $consecutive_errors = 0;
        $max_consecutive_errors = 3; // Lower threshold for session-limited extractions
        $session_start_time = microtime(true);

        do {
            $start_time = microtime(true);

            try {
                $response = $this->make_request_with_retry($next_url, 3, $extraction_id, ['type' => 'session_limited_fetch']);
                $data = $this->parse_response($response);
                
                // Reset error counter on successful request
                $consecutive_errors = 0;

                if (!empty($data['value'])) {
                    $batch_count = count($data['value']);
                    $total_processed += $batch_count;

                    if ($callback && is_callable($callback)) {
                        try {
                            $result = $callback($data['value'], $total_processed);
                            
                            // Check if session should stop
                            if (is_array($result) && !empty($result['stop_session'])) {
                                $session_duration = microtime(true) - $session_start_time;
                                $this->log_performance_metric('session_stopped', $total_processed, $session_duration);
                                error_log("BME API: Session limit reached after {$total_processed} listings in " . round($session_duration, 2) . " seconds");
                                break;
                            }
                        } catch (Exception $e) {
                            $this->log_error('BATCH_002', [
                                'callback_error' => $e->getMessage(),
                                'total_processed' => $total_processed,
                                'batch_count' => $batch_count,
                                'session_limited' => true
                            ]);
                            
                            // For session-limited extractions, callback errors are more critical
                            error_log('BME API: Callback error in session-limited extraction: ' . $e->getMessage());
                            
                            // If this is a session management error, break the session
                            if (strpos($e->getMessage(), 'session') !== false || strpos($e->getMessage(), 'limit') !== false) {
                                error_log('BME API: Session management error detected, ending session gracefully');
                                break;
                            }
                        }
                    }

                    $duration = microtime(true) - $start_time;
                    $this->log_performance_metric('listings_batch', $batch_count, $duration);
                    
                    // Enhanced progress logging for session-limited extractions
                    if ($total_processed % 200 === 0) {
                        $session_elapsed = microtime(true) - $session_start_time;
                        error_log("BME API: Session progress - {$total_processed} listings in " . round($session_elapsed, 2) . " seconds");
                    }
                }

                $next_url = $data['@odata.nextLink'] ?? null;

                if ($next_url) {
                    sleep($this->rate_limit_delay);
                }

            } catch (Exception $e) {
                $consecutive_errors++;
                
                $this->log_error('API_005', [
                    'request_error' => $e->getMessage(),
                    'consecutive_errors' => $consecutive_errors,
                    'total_processed' => $total_processed,
                    'session_limited' => true,
                    'session_elapsed' => round(microtime(true) - $session_start_time, 2)
                ]);

                if ($consecutive_errors >= $max_consecutive_errors) {
                    $this->log_error('BATCH_003', [
                        'consecutive_errors' => $consecutive_errors,
                        'total_processed' => $total_processed,
                        'final_error' => $e->getMessage(),
                        'session_limited' => true,
                        'recovery_action' => 'end_session_gracefully'
                    ]);
                    
                    // For session-limited extractions, end gracefully rather than throwing
                    error_log("BME API: Session ended due to {$consecutive_errors} consecutive errors. Processed: {$total_processed} listings");
                    break;
                }

                // Shorter delays for session-limited extractions
                $error_delay = min($this->rate_limit_delay * $consecutive_errors, 15);
                error_log("BME API: Session error #{$consecutive_errors}, retrying in {$error_delay} seconds: " . $e->getMessage());
                sleep($error_delay);
                
                continue;
            }

        } while ($next_url);

        $session_duration = microtime(true) - $session_start_time;
        error_log("BME API: Session completed - {$total_processed} listings in " . round($session_duration, 2) . " seconds");
        
        return $total_processed;
    }

    /**
     * Fetch related data by making sequential, chunked requests to prevent URL length errors.
     */
    public function fetch_related_data($agents_ids, $offices_ids, $listing_keys, $extraction_id = null) {
        $results = [
            'agents' => $this->fetch_resource_in_chunks('Member', 'MemberMlsId', $agents_ids, false, $extraction_id),
            'offices' => $this->fetch_resource_in_chunks('Office', 'OfficeMlsId', $offices_ids, false, $extraction_id),
            'open_houses' => $this->fetch_resource_in_chunks('OpenHouse', 'ListingKey', $listing_keys, true, $extraction_id),
        ];
        return $results;
    }

    /**
     * Fetch updated open houses based on their ModificationTimestamp
     * This is used during incremental sync to catch new open houses for existing properties
     * 
     * @param string $last_modified_timestamp ISO 8601 timestamp
     * @param int|null $extraction_id Optional extraction ID for logging
     * @return array Array of open house data grouped by ListingId
     */
    public function fetch_updated_open_houses($last_modified_timestamp, $extraction_id = null) {
        $filters = "ModificationTimestamp gt {$last_modified_timestamp}";
        
        $query_args = [
            'access_token' => $this->api_token,
            '$filter' => $filters,
            '$top' => 200,
            '$orderby' => 'ModificationTimestamp asc'
        ];

        $open_house_url = str_replace('/Property', '/OpenHouse', $this->base_url);
        $next_url = add_query_arg($query_args, $open_house_url);
        
        $all_open_houses = [];
        $page = 0;
        $max_pages = 50; // Safety limit
        
        error_log("BME: Fetching updated open houses since {$last_modified_timestamp}");
        
        do {
            $page++;
            if ($page > $max_pages) {
                error_log("BME: Reached max pages limit for open house fetch");
                break;
            }
            
            try {
                $response = $this->make_request_with_retry($next_url, 3, $extraction_id, [
                    'type' => 'open_house_incremental',
                    'page' => $page
                ]);
                
                $data = $this->parse_response($response);
                
                if (!empty($data['value'])) {
                    foreach ($data['value'] as $open_house) {
                        $all_open_houses[] = $open_house;
                    }
                }
                
                $next_url = $data['@odata.nextLink'] ?? null;
                
                // Rate limiting between pages
                if ($next_url && $page < $max_pages) {
                    sleep($this->rate_limit_delay);
                }
                
            } catch (Exception $e) {
                error_log("BME API Error fetching updated open houses: " . $e->getMessage());
                break;
            }
            
        } while ($next_url);
        
        // Group by ListingId for easier processing
        $grouped_by_listing = [];
        foreach ($all_open_houses as $oh) {
            $listing_id = $oh['ListingId'] ?? null;
            if ($listing_id) {
                if (!isset($grouped_by_listing[$listing_id])) {
                    $grouped_by_listing[$listing_id] = [];
                }
                $grouped_by_listing[$listing_id][] = $oh;
            }
        }
        
        $total_count = count($all_open_houses);
        $listing_count = count($grouped_by_listing);
        error_log("BME: Fetched {$total_count} updated open houses for {$listing_count} listings");
        
        return $grouped_by_listing;
    }

    /**
     * Fetches data for a specific resource type in chunks to avoid overly long URLs.
     */
    public function fetch_resource_in_chunks($resource, $key_field, $ids, $group_results = false, $extraction_id = null) {
        if (empty($ids)) {
            return [];
        }

        $resource_url = str_replace('/Property', '/' . $resource, $this->base_url);
        $results_map = [];
        $id_chunks = array_chunk(array_unique($ids), 50); // Process 50 IDs at a time

        foreach ($id_chunks as $chunk_index => $chunk) {
            // Add rate limiting between chunks
            if ($chunk_index > 0) {
                sleep($this->rate_limit_delay);
            }

            $filter_values = "'" . implode("','", array_map('esc_sql', $chunk)) . "'";
            $filter_string = "{$key_field} in ({$filter_values})";

            $query_args = [
                'access_token' => $this->api_token,
                '$filter'      => $filter_string,
                '$top'         => 200
            ];

            $next_url = add_query_arg($query_args, $resource_url);
            $page = 0;
            $max_pages = 10; // Safety limit for pagination

            // Follow pagination to get ALL results for this chunk
            do {
                $page++;
                if ($page > $max_pages) {
                    error_log("BME: Reached max pages for {$resource} chunk fetch (chunk {$chunk_index})");
                    break;
                }

                try {
                    $response = $this->make_request_with_retry($next_url, 3, $extraction_id, [
                        'type' => 'related_data',
                        'resource' => $resource,
                        'page' => $page
                    ]);
                    $data = $this->parse_response($response);

                    if (!empty($data['value'])) {
                        foreach ($data['value'] as $item) {
                            if (isset($item[$key_field])) {
                                $key = $item[$key_field];
                                if ($group_results) {
                                    if (!isset($results_map[$key])) {
                                        $results_map[$key] = [];
                                    }
                                    $results_map[$key][] = $item;
                                } else {
                                    $results_map[$key] = $item;
                                }
                            }
                        }
                    }

                    $next_url = $data['@odata.nextLink'] ?? null;

                    // Rate limiting between pages
                    if ($next_url && $page < $max_pages) {
                        sleep($this->rate_limit_delay);
                    }
                } catch (Exception $e) {
                    error_log("BME API Error fetching {$resource}: " . $e->getMessage());
                    $next_url = null; // Stop pagination on error
                }
            } while ($next_url);
        }
        return $results_map;
    }

    /**
     * Make a single HTTP request
     */
    private function make_request($url) {
        $response = wp_remote_get($url, ['timeout' => $this->timeout, 'headers' => ['Accept' => 'application/json']]);

        if (is_wp_error($response)) {
            throw new Exception('API request failed: ' . $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            throw new Exception("API request failed with code {$code}");
        }

        return $response;
    }

    /**
     * Make HTTP request with retry logic for rate limiting
     */
    private function make_request_with_retry($url, $max_retries = 3, $extraction_id = null, $context = []) {
        $retry_count = 0;
        $base_delay = 2; // Start with 2 seconds
        $request_start_time = microtime(true);
        
        while ($retry_count <= $max_retries) {
            $attempt_start_time = microtime(true);
            
            try {
                $response = wp_remote_get($url, [
                    'timeout' => $this->timeout, 
                    'headers' => ['Accept' => 'application/json'],
                    'user-agent' => 'BME-Pro/' . BME_PRO_VERSION
                ]);

                if (is_wp_error($response)) {
                    $wp_error_code = $response->get_error_code();
                    $wp_error_message = $response->get_error_message();
                    
                    // Classify the WordPress error
                    if (strpos($wp_error_code, 'http_request_failed') !== false || 
                        strpos($wp_error_message, 'timeout') !== false ||
                        strpos($wp_error_message, 'Connection timed out') !== false) {
                        
                        $this->log_error('API_003', [
                            'wp_error_code' => $wp_error_code,
                            'wp_error_message' => $wp_error_message,
                            'retry_count' => $retry_count,
                            'url_preview' => substr($url, 0, 100) . '...'
                        ]);
                    } else {
                        $this->log_error('NET_001', [
                            'wp_error_code' => $wp_error_code,
                            'wp_error_message' => $wp_error_message,
                            'retry_count' => $retry_count
                        ]);
                    }
                    
                    throw new Exception('API request failed: ' . $wp_error_message);
                }

                $code = wp_remote_retrieve_response_code($response);
                $attempt_duration = microtime(true) - $attempt_start_time;
                
                // Handle rate limiting (429) with exponential backoff
                if ($code === 429) {
                    if ($retry_count >= $max_retries) {
                        $this->log_error('API_002', [
                            'retry_count' => $retry_count,
                            'max_retries' => $max_retries,
                            'total_request_time' => round(microtime(true) - $request_start_time, 2),
                            'recovery_action' => 'max_retries_exceeded'
                        ]);
                        throw new Exception("API rate limit exceeded after {$max_retries} retries");
                    }
                    
                    $delay = min($base_delay * pow(2, $retry_count), 60); // Cap at 60 seconds
                    
                    $this->log_error('API_002', [
                        'retry_count' => $retry_count + 1,
                        'delay_seconds' => $delay,
                        'attempt_duration' => round($attempt_duration, 2),
                        'recovery_action' => 'exponential_backoff'
                    ]);
                    
                    error_log("BME API Rate limit hit, retrying in {$delay} seconds (attempt " . ($retry_count + 1) . ")");
                    sleep($delay);
                    $retry_count++;
                    continue;
                }
                
                // Handle other HTTP error codes
                if ($code !== 200) {
                    $response_body = wp_remote_retrieve_body($response);
                    
                    if ($code === 401 || $code === 403) {
                        $this->log_error('API_001', [
                            'response_code' => $code,
                            'response_preview' => substr($response_body, 0, 200),
                            'retry_count' => $retry_count,
                            'recovery_action' => 'check_credentials'
                        ]);
                    } elseif ($code >= 500 && $code < 600) {
                        // Server errors - these might be retryable
                        $this->log_error('API_004', [
                            'response_code' => $code,
                            'response_preview' => substr($response_body, 0, 200),
                            'retry_count' => $retry_count,
                            'recovery_action' => 'server_error_retry'
                        ]);
                        
                        if ($retry_count < $max_retries) {
                            $delay = $base_delay * ($retry_count + 1);
                            error_log("BME API Server error {$code}, retrying in {$delay} seconds");
                            sleep($delay);
                            $retry_count++;
                            continue;
                        }
                    } else {
                        $this->log_error('API_005', [
                            'response_code' => $code,
                            'response_preview' => substr($response_body, 0, 200),
                            'retry_count' => $retry_count
                        ]);
                    }
                    
                    // Log failed API request for tracking
                    $total_duration = microtime(true) - $request_start_time;
                    $this->log_api_request($url, $code, $total_duration, $response, $extraction_id, $context, "API request failed with code {$code}");
                    
                    throw new Exception("API request failed with code {$code}");
                }

                // Success - log performance if this took multiple attempts
                $total_duration = microtime(true) - $request_start_time;
                if ($retry_count > 0) {
                    error_log("BME API: Request succeeded after {$retry_count} retries in " . round($total_duration, 2) . " seconds");
                }
                
                // Log successful API request for tracking
                $this->log_api_request($url, 200, $total_duration, $response, $extraction_id, $context);

                return $response;
                
            } catch (Exception $e) {
                // For non-retryable errors, don't retry
                if (strpos($e->getMessage(), 'rate limit') === false && 
                    strpos($e->getMessage(), '429') === false &&
                    strpos($e->getMessage(), 'server error') === false &&
                    $retry_count >= $max_retries) {
                    
                    $this->log_error('API_005', [
                        'final_error' => $e->getMessage(),
                        'retry_count' => $retry_count,
                        'total_request_time' => round(microtime(true) - $request_start_time, 2),
                        'recovery_action' => 'manual_intervention'
                    ]);
                    
                    // Log final failed API request
                    $total_duration = microtime(true) - $request_start_time;
                    $this->log_api_request($url, 0, $total_duration, null, $extraction_id, $context, $e->getMessage());
                    
                    throw $e;
                }
                
                if ($retry_count >= $max_retries) {
                    throw $e;
                }
                
                $delay = min($base_delay * pow(2, $retry_count), 30);
                error_log("BME API Error, retrying in {$delay} seconds: " . $e->getMessage());
                sleep($delay);
                $retry_count++;
            }
        }
        
        throw new Exception("Maximum retry attempts exceeded");
    }

    /**
     * Parse API response
     */
    private function parse_response($response) {
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON response: ' . json_last_error_msg());
        }

        if (isset($data['error'])) {
            throw new Exception('API Error: ' . ($data['error']['message'] ?? 'Unknown error'));
        }

        return $data;
    }

    /**
     * Build OData filter query
     */
    public function build_filter_query($extraction_config) {
        $filters = [];

        if (!empty($extraction_config['statuses'])) {
            $status_filters = array_map(fn($status) => "StandardStatus eq '{$status}'", $extraction_config['statuses']);
            $filters[] = count($status_filters) > 1 ? '(' . implode(' or ', $status_filters) . ')' : $status_filters[0];
        } else {
            throw new Exception('No statuses selected for extraction.');
        }

        if (!empty($extraction_config['cities'])) {
            $cities_raw = $extraction_config['cities'];
            if (is_string($cities_raw) && !empty(trim($cities_raw))) {
                $cities = array_filter(array_map('trim', explode(',', $cities_raw)));
                if (!empty($cities)) {
                    $city_filters = array_map(fn($city) => "City eq '" . str_replace("'", "''", $city) . "'", $cities);
                    $filters[] = count($city_filters) > 1 ? '(' . implode(' or ', $city_filters) . ')' : $city_filters[0];
                }
            }
        }

        if (!empty($extraction_config['states'])) {
            $state_filters = array_map(fn($state) => "StateOrProvince eq '{$state}'", $extraction_config['states']);
            $filters[] = count($state_filters) > 1 ? '(' . implode(' or ', $state_filters) . ')' : $state_filters[0];
        }

        if (!empty($extraction_config['property_types'])) {
            $property_type_filters = array_map(fn($type) => "PropertyType eq '{$type}'", $extraction_config['property_types']);
            $filters[] = count($property_type_filters) > 1 ? '(' . implode(' or ', $property_type_filters) . ')' : $property_type_filters[0];
        }

        if (!empty($extraction_config['list_agent_id'])) {
            $filters[] = "toupper(ListAgentMlsId) eq '" . strtoupper($extraction_config['list_agent_id']) . "'";
        }

        if (!empty($extraction_config['buyer_agent_id'])) {
            $filters[] = "toupper(BuyerAgentMlsId) eq '" . strtoupper($extraction_config['buyer_agent_id']) . "'";
        }

        // Determine if any selected status is in the archived group
        $archived_group_api_statuses = ['Closed', 'Expired', 'Withdrawn', 'Canceled']; // These are the statuses that indicate an archived state
        $selected_archived_statuses = array_intersect($extraction_config['statuses'], $archived_group_api_statuses);
        $is_archived_extraction = !empty($selected_archived_statuses);

        if ($is_archived_extraction) {
            // Check for existing CloseDate checkpoint to resume extraction
            $last_close_date = get_post_meta($extraction_config['extraction_id'], '_bme_last_close_date', true);
            
            if (!empty($last_close_date) && !$extraction_config['is_resync']) {
                // Resume from last checkpoint - use 'gt' to avoid duplicates
                $filters[] = "CloseDate gt {$last_close_date}";
                error_log("BME: Resuming archived extraction from CloseDate: {$last_close_date}");
            } else {
                // First run or resync - use lookback period
                if (!empty($extraction_config['closed_lookback_months'])) {
                    $months = absint($extraction_config['closed_lookback_months']);
                    $date = new DateTime('now', new DateTimeZone('UTC'));
                    $date->modify("-{$months} months");
                    $iso_date = $date->format('Y-m-d\TH:i:s\Z');
                    $filters[] = "CloseDate ge {$iso_date}";
                    error_log("BME: Starting archived extraction from lookback date: {$iso_date}");
                } else {
                    throw new Exception('Lookback period is required for archived extractions.');
                }
            }
        } elseif (!$extraction_config['is_resync']) {
            // For active listings, only get records modified since the last run, unless it's a full resync
            $last_modified = $extraction_config['last_modified'] ?? '1970-01-01T00:00:00Z';
            
            // If this is the first run (last_modified is default), use the lookback period
            if ($last_modified === '1970-01-01T00:00:00Z' && !empty($extraction_config['closed_lookback_months'])) {
                $months = absint($extraction_config['closed_lookback_months']);
                $date = new DateTime('now', new DateTimeZone('UTC'));
                $date->modify("-{$months} months");
                $iso_date = $date->format('Y-m-d\TH:i:s\Z');
                $filters[] = "ModificationTimestamp gt {$iso_date}";
            } else {
                $filters[] = "ModificationTimestamp gt {$last_modified}";
            }
        }

        $final_filter_query = implode(' and ', $filters);

        // Log the final filter query for debugging
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('BME API Client: Final OData Filter Query: ' . $final_filter_query);
        }

        return $final_filter_query;
    }

    /**
     * Log performance metrics
     */
    private function log_performance_metric($operation, $count, $duration) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf('BME API Performance - %s: %d items in %.3f seconds (%.2f items/sec)', $operation, $count, $duration, $count / max($duration, 0.001)));
        }
    }
    
    /**
     * Log API request for usage tracking and performance monitoring
     */
    private function log_api_request($url, $response_code, $response_time, $response = null, $extraction_id = null, $context = [], $error_message = null) {
        global $wpdb;
        
        try {
            // Get database manager and API requests table
            $db_manager = bme_pro()->get('db');
            $api_table = $db_manager->get_table('api_requests');
            
            // Parse URL to get endpoint
            $parsed_url = parse_url($url);
            $endpoint = $parsed_url['path'] ?? $url;
            
            // Parse request parameters from URL
            $request_params = [];
            if (!empty($parsed_url['query'])) {
                parse_str($parsed_url['query'], $request_params);
                // Remove sensitive token from params for logging
                if (isset($request_params['access_token'])) {
                    $request_params['access_token'] = '***REDACTED***';
                }
            }
            
            // Calculate response size and extract listings count
            $response_size = 0;
            $listings_count = 0;
            if ($response && $response_code === 200) {
                $body = wp_remote_retrieve_body($response);
                $response_size = strlen($body);
                
                // Try to parse JSON to get listings count
                $data = json_decode($body, true);
                if (json_last_error() === JSON_ERROR_NONE && isset($data['value']) && is_array($data['value'])) {
                    $listings_count = count($data['value']);
                }
            }
            
            // Get user agent and IP
            $user_agent = 'BME-Pro/' . BME_PRO_VERSION;
            $ip_address = $this->get_client_ip();
            
            // Prepare data for database insertion
            $api_data = [
                'extraction_id' => $extraction_id,
                'endpoint' => $endpoint,
                'method' => 'GET',
                'request_params' => json_encode($request_params),
                'response_code' => $response_code,
                'response_time' => round($response_time, 3),
                'response_size' => $response_size,
                'listings_count' => $listings_count,
                'error_message' => $error_message,
                'user_agent' => $user_agent,
                'ip_address' => $ip_address,
                'created_at' => current_time('mysql')
            ];
            
            // Insert into API requests table
            $result = $wpdb->insert($api_table, $api_data);
            
            if ($result === false) {
                error_log('BME API Client: Failed to log API request - ' . $wpdb->last_error);
            }
            
        } catch (Exception $e) {
            error_log('BME API Client: Error logging API request - ' . $e->getMessage());
        }
    }
    
    /**
     * Get client IP address for API logging
     */
    private function get_client_ip() {
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            return $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else {
            return $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
        }
    }
}