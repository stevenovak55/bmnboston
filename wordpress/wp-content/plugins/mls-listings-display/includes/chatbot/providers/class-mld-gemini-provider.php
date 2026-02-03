<?php
/**
 * Google Gemini Provider Implementation
 *
 * Handles all communication with Google Gemini API
 * Supports Gemini Pro and Gemini Ultra models
 *
 * v6.11.0: Added function calling support for real-time property data retrieval
 *
 * @package MLS_Listings_Display
 * @subpackage Chatbot/Providers
 * @since 6.6.0
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once dirname(dirname(__FILE__)) . '/abstract-mld-ai-provider.php';

class MLD_Gemini_Provider extends MLD_AI_Provider_Base {

    /**
     * Gemini API endpoint base
     *
     * @var string
     */
    const API_ENDPOINT_BASE = 'https://generativelanguage.googleapis.com/v1beta/models/';

    /**
     * Gemini models pricing (per 1M tokens)
     *
     * @var array
     */
    const PRICING = array(
        'gemini-pro' => array('input' => 0.50, 'output' => 1.50),
        'gemini-1.5-pro' => array('input' => 3.50, 'output' => 10.50),
        'gemini-1.5-flash' => array('input' => 0.075, 'output' => 0.30),
        'gemini-ultra' => array('input' => 10.00, 'output' => 30.00),
    );

    /**
     * Tool registry instance
     *
     * @var MLD_Tool_Registry
     */
    private $tool_registry = null;

    /**
     * Tool executor instance
     *
     * @var MLD_Tool_Executor
     */
    private $tool_executor = null;

    /**
     * Maximum tool call iterations
     *
     * @var int
     */
    const MAX_TOOL_ITERATIONS = 5;

    /**
     * Constructor
     */
    public function __construct($api_key = null, $model = null) {
        $this->provider_name = 'gemini';
        parent::__construct($api_key, $model);
        $this->load_tool_dependencies();
    }

    /**
     * Load tool calling dependencies
     */
    private function load_tool_dependencies() {
        $chatbot_path = dirname(dirname(__FILE__));

        if (!class_exists('MLD_Tool_Registry')) {
            $registry_file = $chatbot_path . '/class-mld-tool-registry.php';
            if (file_exists($registry_file)) {
                require_once $registry_file;
            }
        }

        if (!class_exists('MLD_Tool_Executor')) {
            $executor_file = $chatbot_path . '/class-mld-tool-executor.php';
            if (file_exists($executor_file)) {
                require_once $executor_file;
            }
        }
    }

    /**
     * Get tool registry instance
     *
     * @return MLD_Tool_Registry|null
     */
    private function get_tool_registry() {
        if ($this->tool_registry === null && function_exists('mld_get_tool_registry')) {
            $this->tool_registry = mld_get_tool_registry();
        }
        return $this->tool_registry;
    }

    /**
     * Get tool executor instance
     *
     * @return MLD_Tool_Executor|null
     */
    private function get_tool_executor() {
        if ($this->tool_executor === null && function_exists('mld_get_tool_executor')) {
            $this->tool_executor = mld_get_tool_executor();
        }
        return $this->tool_executor;
    }

    /**
     * Send chat request to Gemini
     *
     * @param array $messages Conversation messages
     * @param array $context Additional context
     * @param array $options Provider options
     * @return array Response data
     */
    public function chat($messages, $context = array(), $options = array()) {
        $start_time = microtime(true);

        // Check rate limits
        $rate_limit = $this->check_rate_limit();
        if (!$rate_limit['allowed']) {
            return $this->format_error(
                'Daily message limit reached. Limit: ' . $rate_limit['limit'],
                'rate_limit_exceeded'
            );
        }

        // Validate API key
        if (empty($this->api_key)) {
            return $this->format_error('Gemini API key not configured', 'missing_api_key');
        }

        // Merge options with defaults
        $options = array_merge($this->default_options, $options);

        // Prepare messages with context
        $prepared_contents = $this->prepare_messages($messages, $context);

        // Build request payload
        $payload = array(
            'contents' => $prepared_contents,
            'generationConfig' => array(
                'temperature' => floatval($options['temperature']),
                'maxOutputTokens' => intval($options['max_tokens']),
                'topP' => floatval($options['top_p']),
            ),
        );

        // Build endpoint URL
        $endpoint = self::API_ENDPOINT_BASE . $this->model . ':generateContent?key=' . $this->api_key;

        // Make API request
        $response = wp_remote_post($endpoint, array(
            'headers' => array(
                'Content-Type' => 'application/json',
            ),
            'body' => json_encode($payload),
            'timeout' => 30,
        ));

        $response_time_ms = round((microtime(true) - $start_time) * 1000);

        // Handle errors
        if (is_wp_error($response)) {
            $error_data = $this->format_error($response->get_error_message(), 'request_failed');
            $this->log_request($payload, $error_data, $response_time_ms);
            return $error_data;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $status_code = wp_remote_retrieve_response_code($response);

        // Check for API errors
        if ($status_code !== 200) {
            $error_message = isset($body['error']['message']) ? $body['error']['message'] : 'Unknown API error';
            $error_data = $this->format_error($error_message, 'api_error_' . $status_code);
            $this->log_request($payload, $error_data, $response_time_ms);
            return $error_data;
        }

        // Parse successful response
        $result = $this->parse_response($body, $response_time_ms);

        // Track usage
        if ($result['success'] && isset($result['tokens'])) {
            $cost = $this->estimate_cost(
                $result['tokens']['prompt_tokens'],
                $result['tokens']['completion_tokens'],
                $this->model
            );

            $this->track_usage(
                $result['tokens']['prompt_tokens'],
                $result['tokens']['completion_tokens'],
                $cost
            );
        }

        $this->log_request($payload, $result, $response_time_ms);

        return $result;
    }

    /**
     * Send chat request with function calling support
     *
     * Gemini uses "functionDeclarations" format for function calling.
     *
     * @param array $messages Conversation messages
     * @param array $context Additional context
     * @param array $options Provider options
     * @return array Response data
     * @since 6.11.0
     */
    public function chat_with_tools($messages, $context = array(), $options = array()) {
        $start_time = microtime(true);
        $total_tokens = array('prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0);

        // Check if tools are available
        $registry = $this->get_tool_registry();
        $executor = $this->get_tool_executor();

        if (!$registry || !$executor || !$registry->is_enabled()) {
            return $this->chat($messages, $context, $options);
        }

        // Check rate limits
        $rate_limit = $this->check_rate_limit();
        if (!$rate_limit['allowed']) {
            return $this->format_error(
                'Daily message limit reached. Limit: ' . $rate_limit['limit'],
                'rate_limit_exceeded'
            );
        }

        // Validate API key
        if (empty($this->api_key)) {
            return $this->format_error('Gemini API key not configured', 'missing_api_key');
        }

        // Merge options with defaults
        $options = array_merge($this->default_options, $options);

        // Prepare messages with context
        $prepared_contents = $this->prepare_messages($messages, $context);

        // Add tool instructions to first message
        $prepared_contents = $this->add_tool_instructions_to_contents($prepared_contents);

        // Convert tools to Gemini format
        $tools = $this->convert_tools_to_gemini_format($registry->get_tools_for_openai());

        // Tool calling loop
        $iteration = 0;
        $tool_calls_made = array();

        while ($iteration < self::MAX_TOOL_ITERATIONS) {
            $iteration++;

            // Build request payload
            $payload = array(
                'contents' => $prepared_contents,
                'generationConfig' => array(
                    'temperature' => floatval($options['temperature']),
                    'maxOutputTokens' => intval($options['max_tokens']),
                    'topP' => floatval($options['top_p']),
                ),
                'tools' => array(
                    array('functionDeclarations' => $tools),
                ),
            );

            // Build endpoint URL
            $endpoint = self::API_ENDPOINT_BASE . $this->model . ':generateContent?key=' . $this->api_key;

            // Make API request
            $response = wp_remote_post($endpoint, array(
                'headers' => array(
                    'Content-Type' => 'application/json',
                ),
                'body' => json_encode($payload),
                'timeout' => 60,
            ));

            // Handle errors
            if (is_wp_error($response)) {
                $response_time_ms = round((microtime(true) - $start_time) * 1000);
                $error_data = $this->format_error($response->get_error_message(), 'request_failed');
                $this->log_request($payload, $error_data, $response_time_ms);
                return $error_data;
            }

            $body = json_decode(wp_remote_retrieve_body($response), true);
            $status_code = wp_remote_retrieve_response_code($response);

            // Check for API errors
            if ($status_code !== 200) {
                $response_time_ms = round((microtime(true) - $start_time) * 1000);
                $error_message = isset($body['error']['message']) ? $body['error']['message'] : 'Unknown API error';
                $error_data = $this->format_error($error_message, 'api_error_' . $status_code);
                $this->log_request($payload, $error_data, $response_time_ms);
                return $error_data;
            }

            // Track token usage
            if (isset($body['usageMetadata'])) {
                $total_tokens['prompt_tokens'] += $body['usageMetadata']['promptTokenCount'] ?? 0;
                $total_tokens['completion_tokens'] += $body['usageMetadata']['candidatesTokenCount'] ?? 0;
                $total_tokens['total_tokens'] = $total_tokens['prompt_tokens'] + $total_tokens['completion_tokens'];
            }

            // Check for function calls
            $candidate = $body['candidates'][0] ?? null;
            if (!$candidate) {
                return $this->format_error('Invalid response from Gemini', 'invalid_response');
            }

            $content = $candidate['content'] ?? null;
            $has_function_call = false;
            $text_content = '';
            $function_calls = array();

            if ($content && isset($content['parts'])) {
                foreach ($content['parts'] as $part) {
                    if (isset($part['text'])) {
                        $text_content .= $part['text'];
                    } elseif (isset($part['functionCall'])) {
                        $has_function_call = true;
                        $function_calls[] = $part['functionCall'];
                    }
                }
            }

            if ($has_function_call) {
                // Add model response to conversation
                $prepared_contents[] = array(
                    'role' => 'model',
                    'parts' => $content['parts'],
                );

                // Execute function calls and add results
                $function_response_parts = array();
                foreach ($function_calls as $fc) {
                    $func_name = $fc['name'];
                    $func_args = $fc['args'] ?? array();

                    // Execute the tool
                    $tool_result = $executor->execute($func_name, $func_args);

                    $tool_calls_made[] = array(
                        'name' => $func_name,
                        'arguments' => $func_args,
                        'success' => $tool_result['success'],
                    );

                    $function_response_parts[] = array(
                        'functionResponse' => array(
                            'name' => $func_name,
                            'response' => $tool_result,
                        ),
                    );

                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log("[MLD Gemini] Function call: {$func_name} - Success: " . ($tool_result['success'] ? 'yes' : 'no'));
                    }
                }

                // Add function response to conversation
                $prepared_contents[] = array(
                    'role' => 'user',
                    'parts' => $function_response_parts,
                );

                continue;
            }

            // Model returned final response
            $response_time_ms = round((microtime(true) - $start_time) * 1000);

            $result = array(
                'success' => true,
                'text' => $text_content,
                'role' => 'assistant',
                'finish_reason' => $candidate['finishReason'] ?? 'STOP',
                'tokens' => $total_tokens,
                'model' => $this->model,
                'provider' => 'gemini',
                'response_time_ms' => $response_time_ms,
                'tool_calls_made' => $tool_calls_made,
                'tool_iterations' => $iteration,
            );

            // Track usage
            if (isset($result['tokens'])) {
                $cost = $this->estimate_cost(
                    $result['tokens']['prompt_tokens'],
                    $result['tokens']['completion_tokens'],
                    $this->model
                );

                $this->track_usage(
                    $result['tokens']['prompt_tokens'],
                    $result['tokens']['completion_tokens'],
                    $cost
                );
            }

            $this->log_request($payload, $result, $response_time_ms);

            return $result;
        }

        // Max iterations reached
        $response_time_ms = round((microtime(true) - $start_time) * 1000);
        return $this->format_error('Maximum function call iterations reached', 'max_iterations');
    }

    /**
     * Convert OpenAI tool format to Gemini functionDeclarations format
     *
     * @param array $openai_tools Tools in OpenAI format
     * @return array Tools in Gemini format
     */
    private function convert_tools_to_gemini_format($openai_tools) {
        $gemini_tools = array();

        foreach ($openai_tools as $tool) {
            if ($tool['type'] !== 'function') {
                continue;
            }

            $gemini_tools[] = array(
                'name' => $tool['function']['name'],
                'description' => $tool['function']['description'],
                'parameters' => $tool['function']['parameters'],
            );
        }

        return $gemini_tools;
    }

    /**
     * Add tool instructions to contents
     *
     * @param array $contents Prepared contents
     * @return array Modified contents
     */
    private function add_tool_instructions_to_contents($contents) {
        $instructions = "\n\n## Property Search Tools\n" .
            "You have access to functions that let you search for real property listings and market data. " .
            "When users ask about properties, prices, or market conditions, use these functions to get current data.\n" .
            "After getting function results, summarize the information naturally for the user.";

        // Add to first user message
        if (!empty($contents[0]) && $contents[0]['role'] === 'user') {
            $contents[0]['parts'][0]['text'] .= $instructions;
        }

        return $contents;
    }

    /**
     * Check if function calling is supported
     *
     * @return bool
     */
    public function supports_function_calling() {
        // Gemini 1.5 models support function calling
        return strpos($this->model, 'gemini-1.5') !== false || strpos($this->model, 'gemini-pro') !== false;
    }

    /**
     * Prepare messages for Gemini API
     *
     * Gemini uses a different message format with "contents" and "parts"
     *
     * @param array $messages User messages
     * @param array $context Context data
     * @return array Formatted contents
     */
    protected function prepare_messages($messages, $context) {
        $formatted_contents = array();

        // Build system instructions (included as first user message in Gemini)
        $system_message = $this->build_system_message($context);

        // Add system message as first user message
        $formatted_contents[] = array(
            'role' => 'user',
            'parts' => array(
                array('text' => $system_message)
            ),
        );

        // Add a model response acknowledging the instructions
        $formatted_contents[] = array(
            'role' => 'model',
            'parts' => array(
                array('text' => 'I understand. I\'m ready to assist with real estate inquiries.')
            ),
        );

        // Add conversation messages
        foreach ($messages as $message) {
            $role = isset($message['role']) ? $message['role'] : 'user';

            // Map roles to Gemini format
            $gemini_role = ($role === 'assistant') ? 'model' : 'user';

            $formatted_contents[] = array(
                'role' => $gemini_role,
                'parts' => array(
                    array('text' => $message['content'])
                ),
            );
        }

        return $formatted_contents;
    }

    /**
     * Build system message with context
     *
     * @param array $context Context data
     * @return string System message
     */
    protected function build_system_message($context) {
        $parts = array();

        // Get custom system prompt (v6.8.0, v6.9.0 with A/B testing)
        $conversation_id = isset($context['conversation_id']) ? $context['conversation_id'] : null;
        $prompt_data = $this->get_custom_system_prompt($context, $conversation_id);
        $parts[] = $prompt_data['content'];

        // Add current date/time
        $parts[] = "Today's date and time: " . current_time('F j, Y g:i A');

        // Add knowledge context
        $knowledge_text = $this->prepare_context($context);
        if (!empty($knowledge_text)) {
            $parts[] = $knowledge_text;
        }

        return implode("\n\n", $parts);
    }

    /**
     * Parse Gemini API response
     *
     * @param array $body Response body
     * @param int $response_time_ms Response time
     * @return array Parsed response
     */
    protected function parse_response($body, $response_time_ms) {
        if (!isset($body['candidates']) || empty($body['candidates'])) {
            return $this->format_error('Invalid API response format', 'invalid_response');
        }

        $candidate = $body['candidates'][0];

        if (!isset($candidate['content']['parts'])) {
            return $this->format_error('Invalid response structure', 'invalid_response');
        }

        $text = '';
        foreach ($candidate['content']['parts'] as $part) {
            if (isset($part['text'])) {
                $text .= $part['text'];
            }
        }

        // Extract token usage if available
        $prompt_tokens = isset($body['usageMetadata']['promptTokenCount']) ? $body['usageMetadata']['promptTokenCount'] : 0;
        $completion_tokens = isset($body['usageMetadata']['candidatesTokenCount']) ? $body['usageMetadata']['candidatesTokenCount'] : 0;

        return array(
            'success' => true,
            'text' => $text,
            'role' => 'assistant',
            'finish_reason' => isset($candidate['finishReason']) ? $candidate['finishReason'] : 'STOP',
            'tokens' => array(
                'prompt_tokens' => $prompt_tokens,
                'completion_tokens' => $completion_tokens,
                'total_tokens' => $prompt_tokens + $completion_tokens,
            ),
            'model' => $this->model,
            'provider' => 'gemini',
            'response_time_ms' => $response_time_ms,
        );
    }

    /**
     * Test Gemini API connection
     *
     * @return array Test result
     */
    public function test_connection() {
        if (empty($this->api_key)) {
            return array(
                'success' => false,
                'message' => 'API key not configured',
            );
        }

        $test_messages = array(
            array('role' => 'user', 'content' => 'Hello'),
        );

        $result = $this->chat($test_messages, array(), array('max_tokens' => 10));

        if ($result['success']) {
            return array(
                'success' => true,
                'message' => 'Connection successful',
                'model' => $this->model,
            );
        } else {
            return array(
                'success' => false,
                'message' => $result['error'],
                'error_code' => $result['error_code'],
            );
        }
    }

    /**
     * Get available Gemini models
     *
     * Fetches models from Google AI API with caching (24 hours)
     * Falls back to static list if API call fails
     *
     * @return array Available models
     */
    public function get_available_models() {
        // Try to get cached models first
        $cache_key = 'mld_gemini_models';
        $cached_models = get_transient($cache_key);

        if ($cached_models !== false) {
            return $cached_models;
        }

        // Try to fetch from API if we have an API key
        if (!empty($this->api_key)) {
            $api_models = $this->fetch_models_from_api();
            if (!empty($api_models)) {
                // Cache for 24 hours
                set_transient($cache_key, $api_models, DAY_IN_SECONDS);
                return $api_models;
            }
        }

        // Fallback to static list
        return $this->get_static_model_list();
    }

    /**
     * Fetch available models from Google AI API
     *
     * @return array|false Models array or false on failure
     */
    private function fetch_models_from_api() {
        $url = 'https://generativelanguage.googleapis.com/v1beta/models?key=' . $this->api_key;

        $response = wp_remote_get($url, array(
            'timeout' => 15,
        ));

        if (is_wp_error($response)) {
            return false;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (empty($body['models'])) {
            return false;
        }

        // Filter and format generative models only
        $chat_models = array();

        foreach ($body['models'] as $model) {
            $model_name = $model['name'] ?? '';
            // Extract model ID from full path (e.g., "models/gemini-1.5-flash" -> "gemini-1.5-flash")
            $model_id = str_replace('models/', '', $model_name);

            // Only include Gemini chat models
            if (strpos($model_id, 'gemini') !== 0) {
                continue;
            }

            // Skip embedding and other non-chat models
            if (preg_match('/(embedding|aqa|text-)/i', $model_id)) {
                continue;
            }

            // Format display name
            $display_name = $this->format_model_display_name($model_id, $model);
            $chat_models[$model_id] = $display_name;
        }

        // Sort models with recommended first
        $sorted_models = $this->sort_gemini_models($chat_models);

        return $sorted_models;
    }

    /**
     * Format model ID into a readable display name
     *
     * @param string $model_id Model identifier
     * @param array $model_data Full model data from API
     * @return string Formatted display name
     */
    private function format_model_display_name($model_id, $model_data = array()) {
        // Pricing info (Gemini has generous free tier)
        $pricing = array(
            'gemini-2.0-flash' => 'Free tier available',
            'gemini-1.5-flash' => 'Free tier available',
            'gemini-1.5-pro' => '$1.25/$5 per 1M tokens',
            'gemini-pro' => 'Legacy',
        );

        // Use display name from API if available
        $name = $model_data['displayName'] ?? ucwords(str_replace(array('-', '_'), ' ', $model_id));

        // Add pricing/description if available
        foreach ($pricing as $key => $price) {
            if (strpos($model_id, $key) === 0) {
                $name .= ' - ' . $price;
                break;
            }
        }

        // Mark recommended models
        if ($model_id === 'gemini-1.5-flash' || $model_id === 'gemini-2.0-flash-exp') {
            $name .= ' (Recommended)';
        }

        return $name;
    }

    /**
     * Sort models with recommended ones first
     *
     * @param array $models Models array
     * @return array Sorted models
     */
    private function sort_gemini_models($models) {
        $priority = array(
            'gemini-2.0' => 1,
            'gemini-1.5-flash' => 2,
            'gemini-1.5-pro' => 3,
            'gemini-pro' => 4,
        );

        uksort($models, function($a, $b) use ($priority) {
            $priority_a = 100;
            $priority_b = 100;

            foreach ($priority as $prefix => $p) {
                if (strpos($a, $prefix) === 0 && $priority_a === 100) {
                    $priority_a = $p;
                }
                if (strpos($b, $prefix) === 0 && $priority_b === 100) {
                    $priority_b = $p;
                }
            }

            if ($priority_a !== $priority_b) {
                return $priority_a - $priority_b;
            }

            return strlen($a) - strlen($b);
        });

        return $models;
    }

    /**
     * Get static fallback model list
     *
     * @return array Static models list
     */
    private function get_static_model_list() {
        return array(
            'gemini-2.0-flash-exp' => 'Gemini 2.0 Flash Experimental (Latest)',
            'gemini-1.5-flash' => 'Gemini 1.5 Flash - Free tier available (Recommended)',
            'gemini-1.5-flash-latest' => 'Gemini 1.5 Flash Latest',
            'gemini-1.5-pro' => 'Gemini 1.5 Pro - $1.25/$5 per 1M tokens',
            'gemini-1.5-pro-latest' => 'Gemini 1.5 Pro Latest',
            'gemini-pro' => 'Gemini Pro (Legacy)',
        );
    }

    /**
     * Clear the models cache (useful after API key change)
     */
    public function clear_models_cache() {
        delete_transient('mld_gemini_models');
    }

    /**
     * Get fallback model
     *
     * @return string Model identifier
     */
    protected function get_fallback_model() {
        return 'gemini-1.5-flash';
    }

    /**
     * Get configuration schema
     *
     * @return array Configuration options
     */
    public function get_config_schema() {
        return array(
            'api_key' => array(
                'type' => 'password',
                'label' => 'Google Gemini API Key',
                'description' => 'Get your API key from https://makersuite.google.com/app/apikey',
                'required' => true,
            ),
            'model' => array(
                'type' => 'select',
                'label' => 'Model',
                'options' => $this->get_available_models(),
                'default' => 'gemini-1.5-flash',
            ),
            'temperature' => array(
                'type' => 'number',
                'label' => 'Temperature',
                'description' => 'Controls randomness (0.0 - 1.0)',
                'min' => 0.0,
                'max' => 1.0,
                'step' => 0.1,
                'default' => 0.7,
            ),
            'max_tokens' => array(
                'type' => 'number',
                'label' => 'Max Output Tokens',
                'description' => 'Maximum response length',
                'min' => 50,
                'max' => 2048,
                'step' => 50,
                'default' => 500,
            ),
        );
    }

    /**
     * Estimate cost for request
     *
     * @param int $input_tokens Input tokens
     * @param int $output_tokens Output tokens
     * @param string $model Model name
     * @return float Estimated cost in USD
     */
    public function estimate_cost($input_tokens, $output_tokens, $model = null) {
        $model = $model ?: $this->model;

        // Find matching pricing
        $pricing = null;
        foreach (self::PRICING as $model_pattern => $rates) {
            if (strpos($model, $model_pattern) !== false) {
                $pricing = $rates;
                break;
            }
        }

        if (!$pricing) {
            // Default to Gemini Pro pricing if model not found
            $pricing = self::PRICING['gemini-pro'];
        }

        $input_cost = ($input_tokens / 1000000) * $pricing['input'];
        $output_cost = ($output_tokens / 1000000) * $pricing['output'];

        return round($input_cost + $output_cost, 6);
    }

    /**
     * Check if Gemini is available
     *
     * @return array Availability status
     */
    public function is_available() {
        if (empty($this->api_key)) {
            return array(
                'available' => false,
                'reason' => 'API key not configured',
            );
        }

        // Check rate limits
        $rate_limit = $this->check_rate_limit();
        if (!$rate_limit['allowed']) {
            return array(
                'available' => false,
                'reason' => 'Daily rate limit exceeded',
            );
        }

        return array(
            'available' => true,
        );
    }
}
