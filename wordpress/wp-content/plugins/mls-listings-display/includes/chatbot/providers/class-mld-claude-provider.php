<?php
/**
 * Claude (Anthropic) Provider Implementation
 *
 * Handles all communication with Anthropic Claude API
 * Supports Claude 3 Haiku, Sonnet, and Opus models
 *
 * v6.11.0: Added tool use support for real-time property data retrieval
 *
 * @package MLS_Listings_Display
 * @subpackage Chatbot/Providers
 * @since 6.6.0
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once dirname(dirname(__FILE__)) . '/abstract-mld-ai-provider.php';

class MLD_Claude_Provider extends MLD_AI_Provider_Base {

    /**
     * Claude API endpoint
     *
     * @var string
     */
    const API_ENDPOINT = 'https://api.anthropic.com/v1/messages';

    /**
     * API version
     *
     * @var string
     */
    const API_VERSION = '2023-06-01';

    /**
     * Claude models pricing (per 1M tokens)
     *
     * @var array
     */
    const PRICING = array(
        'claude-3-haiku' => array('input' => 0.25, 'output' => 1.25),
        'claude-3-sonnet' => array('input' => 3.00, 'output' => 15.00),
        'claude-3-opus' => array('input' => 15.00, 'output' => 75.00),
        'claude-3-5-sonnet' => array('input' => 3.00, 'output' => 15.00),
        'claude-3-5-haiku' => array('input' => 1.00, 'output' => 5.00),
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
        $this->provider_name = 'claude';
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
     * Send chat request to Claude
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
            return $this->format_error('Claude API key not configured', 'missing_api_key');
        }

        // Merge options with defaults
        $options = array_merge($this->default_options, $options);

        // Prepare messages with context
        $prepared = $this->prepare_messages($messages, $context);

        // Build request payload
        $payload = array(
            'model' => $this->model,
            'messages' => $prepared['messages'],
            'max_tokens' => intval($options['max_tokens']),
            'temperature' => floatval($options['temperature']),
            'top_p' => floatval($options['top_p']),
        );

        // Add system message if provided
        if (!empty($prepared['system'])) {
            $payload['system'] = $prepared['system'];
        }

        // Make API request
        $response = wp_remote_post(self::API_ENDPOINT, array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'x-api-key' => $this->api_key,
                'anthropic-version' => self::API_VERSION,
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
     * Send chat request with tool use support
     *
     * Claude uses a different tool format than OpenAI. This method handles
     * the tool use loop for real-time property data retrieval.
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

        // Set conversation context on executor (v6.14.0)
        // This enables context persistence, shown properties tracking, and reference resolution
        if (!empty($context['conversation_id'])) {
            $context_file = dirname(dirname(__FILE__)) . '/class-mld-conversation-context.php';
            if (file_exists($context_file)) {
                require_once $context_file;
                if (class_exists('MLD_Conversation_Context')) {
                    $conversation_context = new MLD_Conversation_Context($context['conversation_id']);
                    $executor->set_context($conversation_context);

                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log('[MLD Claude Provider 6.14.0] Set conversation context for ID: ' . $context['conversation_id']);
                    }
                }
            }
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
            return $this->format_error('Claude API key not configured', 'missing_api_key');
        }

        // Merge options with defaults
        $options = array_merge($this->default_options, $options);

        // Prepare messages with context
        $prepared = $this->prepare_messages($messages, $context);

        // Add tool instructions to system message
        $prepared['system'] .= $this->get_tool_instructions();

        // Convert tools to Claude format
        $tools = $this->convert_tools_to_claude_format($registry->get_tools_for_openai());

        // Tool calling loop
        $iteration = 0;
        $tool_calls_made = array();
        $conversation_messages = $prepared['messages'];

        while ($iteration < self::MAX_TOOL_ITERATIONS) {
            $iteration++;

            // Build request payload
            $payload = array(
                'model' => $this->model,
                'messages' => $conversation_messages,
                'max_tokens' => intval($options['max_tokens']),
                'temperature' => floatval($options['temperature']),
                'top_p' => floatval($options['top_p']),
                'tools' => $tools,
            );

            if (!empty($prepared['system'])) {
                $payload['system'] = $prepared['system'];
            }

            // Make API request
            $response = wp_remote_post(self::API_ENDPOINT, array(
                'headers' => array(
                    'Content-Type' => 'application/json',
                    'x-api-key' => $this->api_key,
                    'anthropic-version' => self::API_VERSION,
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
            if (isset($body['usage'])) {
                $total_tokens['prompt_tokens'] += $body['usage']['input_tokens'];
                $total_tokens['completion_tokens'] += $body['usage']['output_tokens'];
                $total_tokens['total_tokens'] = $total_tokens['prompt_tokens'] + $total_tokens['completion_tokens'];
            }

            // Check if Claude wants to use tools
            $stop_reason = $body['stop_reason'] ?? '';
            $has_tool_use = false;
            $text_content = '';
            $tool_uses = array();

            foreach ($body['content'] as $content_block) {
                if ($content_block['type'] === 'text') {
                    $text_content .= $content_block['text'];
                } elseif ($content_block['type'] === 'tool_use') {
                    $has_tool_use = true;
                    $tool_uses[] = $content_block;
                }
            }

            // Debug logging (v6.14.0)
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("[MLD Claude 6.14.0] Iteration {$iteration}: stop_reason={$stop_reason}, has_tool_use=" . ($has_tool_use ? 'yes' : 'no') . ", text_length=" . strlen($text_content));
            }

            if ($has_tool_use && $stop_reason === 'tool_use') {
                // Add assistant message to conversation
                $conversation_messages[] = array(
                    'role' => 'assistant',
                    'content' => $body['content'],
                );

                // Execute each tool and collect results
                $tool_results = array();
                foreach ($tool_uses as $tool_use) {
                    $tool_name = $tool_use['name'];
                    $tool_input = $tool_use['input'];
                    $tool_id = $tool_use['id'];

                    // Execute the tool
                    $tool_result = $executor->execute($tool_name, $tool_input ?: array());

                    $tool_calls_made[] = array(
                        'name' => $tool_name,
                        'arguments' => $tool_input,
                        'success' => $tool_result['success'],
                    );

                    $tool_results[] = array(
                        'type' => 'tool_result',
                        'tool_use_id' => $tool_id,
                        'content' => json_encode($tool_result),
                    );

                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log("[MLD Claude] Tool call: {$tool_name} - Success: " . ($tool_result['success'] ? 'yes' : 'no'));
                    }
                }

                // Add tool results as user message
                $conversation_messages[] = array(
                    'role' => 'user',
                    'content' => $tool_results,
                );

                continue;
            }

            // Model returned final response
            $response_time_ms = round((microtime(true) - $start_time) * 1000);

            // Debug logging (v6.14.0)
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("[MLD Claude 6.14.0] Final response: text_length=" . strlen($text_content) . ", first 100 chars: " . substr($text_content, 0, 100));
            }

            $result = array(
                'success' => true,
                'text' => $text_content,
                'role' => 'assistant',
                'stop_reason' => $stop_reason,
                'tokens' => $total_tokens,
                'model' => $body['model'],
                'provider' => 'claude',
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
        return $this->format_error('Maximum tool iterations reached', 'max_iterations');
    }

    /**
     * Convert OpenAI tool format to Claude format
     *
     * @param array $openai_tools Tools in OpenAI format
     * @return array Tools in Claude format
     */
    private function convert_tools_to_claude_format($openai_tools) {
        $claude_tools = array();

        foreach ($openai_tools as $tool) {
            if ($tool['type'] !== 'function') {
                continue;
            }

            $claude_tools[] = array(
                'name' => $tool['function']['name'],
                'description' => $tool['function']['description'],
                'input_schema' => $tool['function']['parameters'],
            );
        }

        return $claude_tools;
    }

    /**
     * Get tool usage instructions for system message
     *
     * @return string
     */
    private function get_tool_instructions() {
        return "\n\n## Property Search Tools (v6.14.0)\n" .
            "You have access to tools to search and retrieve real property data. ALWAYS use these tools when appropriate:\n\n" .
            "### When to Use Each Tool:\n" .
            "- **search_properties**: When user wants to find properties (e.g., 'show me condos in Boston')\n" .
            "- **get_property_details**: When user asks for MORE info about a specific property (e.g., 'tell me more about 27 Bowdoin St', 'what are the property taxes?', 'does it have central AC?'). Use the listing_id from shown_properties.\n" .
            "- **resolve_property_reference**: When user refers to a numbered property (e.g., 'number 5', 'the first one', 'option 2')\n" .
            "- **get_property_category**: When user asks detailed questions about a category (e.g., HVAC, rooms, financial, history)\n" .
            "- **text_search**: For free-text searches including addresses and MLS numbers\n\n" .
            "### IMPORTANT:\n" .
            "- When user asks about a property shown in PREVIOUSLY SHOWN PROPERTIES, ALWAYS use get_property_details to get comprehensive data.\n" .
            "- The basic info in context (beds, baths, price) is just a summary. Use get_property_details for HVAC, taxes, HOA, room sizes, etc.\n" .
            "- After getting tool results, summarize the information naturally for the user.\n" .
            "- If no properties match, suggest adjusting the search criteria.";
    }

    /**
     * Check if function calling is supported
     *
     * @return bool
     */
    public function supports_function_calling() {
        // All Claude 3+ models support tool use
        return true;
    }

    /**
     * Prepare messages for Claude API
     *
     * Claude requires alternating user/assistant messages and separate system message
     *
     * @param array $messages User messages
     * @param array $context Context data
     * @return array Formatted messages and system prompt
     */
    protected function prepare_messages($messages, $context) {
        $system_message = $this->build_system_message($context);
        $formatted_messages = array();

        foreach ($messages as $message) {
            $role = isset($message['role']) ? $message['role'] : 'user';

            // Claude doesn't support 'system' role in messages array
            if ($role === 'system') {
                continue;
            }

            $formatted_messages[] = array(
                'role' => $role === 'user' ? 'user' : 'assistant',
                'content' => $message['content'],
            );
        }

        return array(
            'system' => $system_message,
            'messages' => $formatted_messages,
        );
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
        $parts[] = "Current date and time: " . current_time('F j, Y g:i A');

        // Add knowledge context
        $knowledge_text = $this->prepare_context($context);
        if (!empty($knowledge_text)) {
            $parts[] = $knowledge_text;
        }

        return implode("\n\n", $parts);
    }

    /**
     * Parse Claude API response
     *
     * @param array $body Response body
     * @param int $response_time_ms Response time
     * @return array Parsed response
     */
    protected function parse_response($body, $response_time_ms) {
        if (!isset($body['content']) || empty($body['content'])) {
            return $this->format_error('Invalid API response format', 'invalid_response');
        }

        $text = '';
        foreach ($body['content'] as $content_block) {
            if ($content_block['type'] === 'text') {
                $text .= $content_block['text'];
            }
        }

        return array(
            'success' => true,
            'text' => $text,
            'role' => $body['role'],
            'stop_reason' => $body['stop_reason'],
            'tokens' => array(
                'prompt_tokens' => $body['usage']['input_tokens'],
                'completion_tokens' => $body['usage']['output_tokens'],
                'total_tokens' => $body['usage']['input_tokens'] + $body['usage']['output_tokens'],
            ),
            'model' => $body['model'],
            'provider' => 'claude',
            'response_time_ms' => $response_time_ms,
        );
    }

    /**
     * Test Claude API connection
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
     * Get available Claude models
     *
     * Anthropic doesn't have a public models API, so we maintain a curated list.
     * Models are cached and can be refreshed via clear_models_cache().
     *
     * @return array Available models
     */
    public function get_available_models() {
        // Check for cached models (allows for dynamic updates)
        $cache_key = 'mld_claude_models';
        $cached_models = get_transient($cache_key);

        if ($cached_models !== false) {
            return $cached_models;
        }

        // Current Claude models as of November 2025
        // Anthropic model naming: claude-{version}-{variant}-{date}
        $models = array(
            // Claude 3.5 Family (Latest - Recommended)
            'claude-sonnet-4-20250514' => 'Claude Sonnet 4 - $3/$15 per 1M tokens (Latest, Recommended)',
            'claude-3-5-sonnet-20241022' => 'Claude 3.5 Sonnet - $3/$15 per 1M tokens (Most capable)',
            'claude-3-5-haiku-20241022' => 'Claude 3.5 Haiku - $0.25/$1.25 per 1M tokens (Fast)',

            // Claude 3 Family
            'claude-3-opus-20240229' => 'Claude 3 Opus - $15/$75 per 1M tokens (Most intelligent)',
            'claude-3-sonnet-20240229' => 'Claude 3 Sonnet - $3/$15 per 1M tokens (Balanced)',
            'claude-3-haiku-20240307' => 'Claude 3 Haiku - $0.25/$1.25 per 1M tokens (Fastest)',
        );

        // Cache for 7 days (Claude models change less frequently)
        set_transient($cache_key, $models, 7 * DAY_IN_SECONDS);

        return $models;
    }

    /**
     * Clear the models cache (useful when new models are released)
     */
    public function clear_models_cache() {
        delete_transient('mld_claude_models');
    }

    /**
     * Get fallback model
     *
     * @return string Model identifier
     */
    protected function get_fallback_model() {
        return 'claude-3-5-haiku-20241022';
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
                'label' => 'Claude API Key',
                'description' => 'Get your API key from https://console.anthropic.com/',
                'required' => true,
            ),
            'model' => array(
                'type' => 'select',
                'label' => 'Model',
                'options' => $this->get_available_models(),
                'default' => 'claude-3-5-haiku-20241022',
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
                'label' => 'Max Tokens',
                'description' => 'Maximum response length',
                'min' => 50,
                'max' => 4000,
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
            // Default to Haiku pricing if model not found
            $pricing = self::PRICING['claude-3-haiku'];
        }

        $input_cost = ($input_tokens / 1000000) * $pricing['input'];
        $output_cost = ($output_tokens / 1000000) * $pricing['output'];

        return round($input_cost + $output_cost, 6);
    }

    /**
     * Check if Claude is available
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
