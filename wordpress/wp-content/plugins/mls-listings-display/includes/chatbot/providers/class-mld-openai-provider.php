<?php
/**
 * OpenAI Provider Implementation
 *
 * Handles all communication with OpenAI API
 * Supports GPT-3.5-turbo, GPT-4, GPT-4o, and other OpenAI models
 *
 * v6.10.9: Added function calling support for real-time property data retrieval
 *
 * @package MLS_Listings_Display
 * @subpackage Chatbot/Providers
 * @since 6.6.0
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once dirname(dirname(__FILE__)) . '/abstract-mld-ai-provider.php';

class MLD_OpenAI_Provider extends MLD_AI_Provider_Base {

    /**
     * OpenAI API endpoint
     *
     * @var string
     */
    const API_ENDPOINT = 'https://api.openai.com/v1/chat/completions';

    /**
     * OpenAI models pricing (per 1M tokens)
     *
     * @var array
     */
    const PRICING = array(
        'gpt-3.5-turbo' => array('input' => 0.50, 'output' => 1.50),
        'gpt-4' => array('input' => 30.00, 'output' => 60.00),
        'gpt-4-turbo' => array('input' => 10.00, 'output' => 30.00),
        'gpt-4o' => array('input' => 5.00, 'output' => 15.00),
        'gpt-4o-mini' => array('input' => 0.15, 'output' => 0.60),
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
     * Maximum tool call iterations to prevent infinite loops
     *
     * @var int
     */
    const MAX_TOOL_ITERATIONS = 5;

    /**
     * Constructor
     */
    public function __construct($api_key = null, $model = null) {
        $this->provider_name = 'openai';
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
     * Send chat request to OpenAI
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
            return $this->format_error('OpenAI API key not configured', 'missing_api_key');
        }

        // Merge options with defaults
        $options = array_merge($this->default_options, $options);

        // Prepare messages with context
        $prepared_messages = $this->prepare_messages($messages, $context);

        // Build request payload
        $payload = array(
            'model' => $this->model,
            'messages' => $prepared_messages,
            'temperature' => floatval($options['temperature']),
            'max_tokens' => intval($options['max_tokens']),
            'top_p' => floatval($options['top_p']),
            'frequency_penalty' => floatval($options['frequency_penalty']),
            'presence_penalty' => floatval($options['presence_penalty']),
        );

        // Make API request
        $response = wp_remote_post(self::API_ENDPOINT, array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->api_key,
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
     * This method enables the AI to call tools (functions) to retrieve real-time
     * property data. It handles the full tool calling loop:
     * 1. Send message with tool definitions
     * 2. If AI returns tool calls, execute them
     * 3. Send tool results back to AI
     * 4. Repeat until AI returns final text response
     *
     * @param array $messages Conversation messages
     * @param array $context Additional context
     * @param array $options Provider options
     * @return array Response data
     * @since 6.10.9
     */
    public function chat_with_tools($messages, $context = array(), $options = array()) {
        $start_time = microtime(true);
        $total_tokens = array('prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0);

        // Check if tools are available
        $registry = $this->get_tool_registry();
        $executor = $this->get_tool_executor();

        if (!$registry || !$executor || !$registry->is_enabled()) {
            // Fall back to regular chat if tools not available
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
                        error_log('[MLD OpenAI Provider 6.14.0] Set conversation context for ID: ' . $context['conversation_id']);
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
            return $this->format_error('OpenAI API key not configured', 'missing_api_key');
        }

        // Merge options with defaults
        $options = array_merge($this->default_options, $options);

        // Prepare messages with context
        $prepared_messages = $this->prepare_messages($messages, $context);

        // Add tool usage instruction to system message
        $prepared_messages = $this->add_tool_instructions($prepared_messages);

        // Get tools in OpenAI format
        $tools = $registry->get_tools_for_openai();

        // Tool calling loop
        $iteration = 0;
        $tool_calls_made = array();

        while ($iteration < self::MAX_TOOL_ITERATIONS) {
            $iteration++;

            // Build request payload
            $payload = array(
                'model' => $this->model,
                'messages' => $prepared_messages,
                'temperature' => floatval($options['temperature']),
                'max_tokens' => intval($options['max_tokens']),
                'top_p' => floatval($options['top_p']),
                'frequency_penalty' => floatval($options['frequency_penalty']),
                'presence_penalty' => floatval($options['presence_penalty']),
                'tools' => $tools,
                'tool_choice' => 'auto', // Let the model decide when to use tools
            );

            // Make API request
            $response = wp_remote_post(self::API_ENDPOINT, array(
                'headers' => array(
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $this->api_key,
                ),
                'body' => json_encode($payload),
                'timeout' => 60, // Longer timeout for tool calls
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
                $total_tokens['prompt_tokens'] += $body['usage']['prompt_tokens'];
                $total_tokens['completion_tokens'] += $body['usage']['completion_tokens'];
                $total_tokens['total_tokens'] += $body['usage']['total_tokens'];
            }

            // Parse response
            $choice = $body['choices'][0];
            $message = $choice['message'];
            $finish_reason = $choice['finish_reason'];

            // Check if the model wants to call tools
            if ($finish_reason === 'tool_calls' && !empty($message['tool_calls'])) {
                // Add assistant message with tool calls to conversation
                $prepared_messages[] = array(
                    'role' => 'assistant',
                    'content' => $message['content'],
                    'tool_calls' => $message['tool_calls'],
                );

                // Execute each tool call
                foreach ($message['tool_calls'] as $tool_call) {
                    $tool_name = $tool_call['function']['name'];
                    $tool_args = json_decode($tool_call['function']['arguments'], true);
                    $tool_id = $tool_call['id'];

                    // Execute the tool
                    $tool_result = $executor->execute($tool_name, $tool_args ?: array());

                    // Track tool call for response metadata
                    $tool_calls_made[] = array(
                        'name' => $tool_name,
                        'arguments' => $tool_args,
                        'success' => $tool_result['success'],
                    );

                    // Add tool result to messages
                    $prepared_messages[] = array(
                        'role' => 'tool',
                        'tool_call_id' => $tool_id,
                        'content' => json_encode($tool_result),
                    );

                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log("[MLD OpenAI] Tool call: {$tool_name} - Success: " . ($tool_result['success'] ? 'yes' : 'no'));
                    }
                }

                // Continue loop to get AI's response to tool results
                continue;
            }

            // Model returned final response (stop or length)
            $response_time_ms = round((microtime(true) - $start_time) * 1000);

            $result = array(
                'success' => true,
                'text' => $message['content'],
                'role' => $message['role'],
                'finish_reason' => $finish_reason,
                'tokens' => $total_tokens,
                'model' => $body['model'],
                'provider' => 'openai',
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

        // Max iterations reached - return what we have
        $response_time_ms = round((microtime(true) - $start_time) * 1000);
        return $this->format_error('Maximum tool iterations reached', 'max_iterations');
    }

    /**
     * Add tool usage instructions to system message
     *
     * @param array $messages Prepared messages
     * @return array Messages with tool instructions
     */
    private function add_tool_instructions($messages) {
        $tool_instructions = "\n\n## Property Search Tools (v6.14.0)\n" .
            "You have access to tools to search and retrieve real property data. ALWAYS use these tools when appropriate:\n\n" .
            "### When to Use Each Tool:\n" .
            "- **search_properties**: When user wants to find properties (e.g., 'show me condos in Boston')\n" .
            "- **get_property_details**: When user asks for MORE info about a specific property (e.g., 'tell me more about 27 Bowdoin St', 'what are the property taxes?', 'does it have central AC?'). Use the listing_id from shown_properties.\n" .
            "- **resolve_property_reference**: When user refers to a numbered property (e.g., 'number 5', 'the first one', 'option 2')\n" .
            "- **get_property_category**: When user asks detailed questions about a category (e.g., HVAC, rooms, financial, history)\n" .
            "- **text_search**: For free-text searches including addresses and MLS numbers\n\n" .
            "### IMPORTANT:\n" .
            "- DO NOT answer property questions from memory. ALWAYS call tools to get real, current data.\n" .
            "- When user asks about a property shown in PREVIOUSLY SHOWN PROPERTIES, ALWAYS use get_property_details to get comprehensive data.\n" .
            "- The basic info in context (beds, baths, price) is just a summary. Use get_property_details for HVAC, taxes, HOA, room sizes, etc.\n" .
            "- After getting tool results, summarize the information naturally for the user.\n" .
            "- If no properties match, suggest adjusting the search criteria.";

        // Find and update system message
        foreach ($messages as $key => $message) {
            if ($message['role'] === 'system') {
                $messages[$key]['content'] .= $tool_instructions;
                break;
            }
        }

        return $messages;
    }

    /**
     * Check if function calling is supported by current model
     *
     * @return bool
     */
    public function supports_function_calling() {
        // Most modern OpenAI models support function calling
        $unsupported_models = array(
            'gpt-3.5-turbo-instruct',
        );

        foreach ($unsupported_models as $model) {
            if (strpos($this->model, $model) === 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * Prepare messages with context injection
     *
     * @param array $messages User messages
     * @param array $context Context data
     * @return array Formatted messages for OpenAI
     */
    protected function prepare_messages($messages, $context) {
        $prepared = array();

        // Add system message with context
        $system_content = $this->build_system_message($context);
        $prepared[] = array(
            'role' => 'system',
            'content' => $system_content,
        );

        // Add conversation messages
        foreach ($messages as $message) {
            $prepared[] = array(
                'role' => isset($message['role']) ? $message['role'] : 'user',
                'content' => $message['content'],
            );
        }

        return $prepared;
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
     * Parse OpenAI API response
     *
     * @param array $body Response body
     * @param int $response_time_ms Response time
     * @return array Parsed response
     */
    protected function parse_response($body, $response_time_ms) {
        if (!isset($body['choices']) || empty($body['choices'])) {
            return $this->format_error('Invalid API response format', 'invalid_response');
        }

        $choice = $body['choices'][0];
        $message = $choice['message'];

        return array(
            'success' => true,
            'text' => $message['content'],
            'role' => $message['role'],
            'finish_reason' => $choice['finish_reason'],
            'tokens' => array(
                'prompt_tokens' => $body['usage']['prompt_tokens'],
                'completion_tokens' => $body['usage']['completion_tokens'],
                'total_tokens' => $body['usage']['total_tokens'],
            ),
            'model' => $body['model'],
            'provider' => 'openai',
            'response_time_ms' => $response_time_ms,
        );
    }

    /**
     * Test OpenAI API connection
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
     * Get available OpenAI models
     *
     * Fetches models from OpenAI API with caching (24 hours)
     * Falls back to static list if API call fails
     *
     * @return array Available models
     */
    public function get_available_models() {
        // Try to get cached models first
        $cache_key = 'mld_openai_models';
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
     * Fetch available models from OpenAI API
     *
     * @return array|false Models array or false on failure
     */
    private function fetch_models_from_api() {
        $response = wp_remote_get('https://api.openai.com/v1/models', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->api_key,
            ),
            'timeout' => 15,
        ));

        if (is_wp_error($response)) {
            return false;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (empty($body['data'])) {
            return false;
        }

        // Filter and format chat models only
        $chat_models = array();
        $chat_prefixes = array('gpt-4', 'gpt-3.5', 'o1', 'o3', 'chatgpt');

        foreach ($body['data'] as $model) {
            $model_id = $model['id'];

            // Only include chat-capable models
            $is_chat_model = false;
            foreach ($chat_prefixes as $prefix) {
                if (strpos($model_id, $prefix) === 0) {
                    $is_chat_model = true;
                    break;
                }
            }

            if (!$is_chat_model) {
                continue;
            }

            // Skip embedding, audio, and other non-chat models
            if (preg_match('/(embedding|whisper|tts|dall-e|davinci|babbage|ada-)/i', $model_id)) {
                continue;
            }

            // Format display name
            $display_name = $this->format_model_display_name($model_id);
            $chat_models[$model_id] = $display_name;
        }

        // Sort models - prioritize recommended ones at top
        $sorted_models = $this->sort_models($chat_models);

        return $sorted_models;
    }

    /**
     * Format model ID into a readable display name
     *
     * @param string $model_id Model identifier
     * @return string Formatted display name
     */
    private function format_model_display_name($model_id) {
        // Pricing info for common models
        $pricing = array(
            'gpt-4o-mini' => '$0.15/$0.60 per 1M tokens',
            'gpt-4o' => '$5/$15 per 1M tokens',
            'gpt-4-turbo' => '$10/$30 per 1M tokens',
            'gpt-4' => '$30/$60 per 1M tokens',
            'gpt-3.5-turbo' => '$0.50/$1.50 per 1M tokens',
            'o1-preview' => 'Advanced reasoning',
            'o1-mini' => 'Fast reasoning',
        );

        // Create readable name
        $name = str_replace(array('-', '_'), ' ', $model_id);
        $name = ucwords($name);

        // Add pricing/description if available
        foreach ($pricing as $key => $price) {
            if (strpos($model_id, $key) === 0) {
                $name .= ' - ' . $price;
                break;
            }
        }

        // Mark recommended models
        if ($model_id === 'gpt-4o-mini') {
            $name .= ' (Recommended)';
        } elseif ($model_id === 'gpt-4o' && strpos($model_id, '-2024') === false) {
            $name .= ' (Best quality)';
        }

        return $name;
    }

    /**
     * Sort models with recommended ones first
     *
     * @param array $models Models array
     * @return array Sorted models
     */
    private function sort_models($models) {
        // Priority order for sorting
        $priority = array(
            'gpt-4o-mini' => 1,
            'gpt-4o' => 2,
            'gpt-4-turbo' => 3,
            'gpt-4' => 4,
            'gpt-3.5-turbo' => 5,
            'o1' => 6,
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

            // For same priority, shorter (base model) comes first
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
            // Recommended Models
            'gpt-4o-mini' => 'GPT-4o Mini - $0.15/$0.60 per 1M tokens (Recommended)',
            'gpt-4o' => 'GPT-4o - $5/$15 per 1M tokens (Best quality)',
            'gpt-3.5-turbo' => 'GPT-3.5 Turbo - $0.50/$1.50 per 1M tokens (Legacy)',

            // GPT-4o Family
            'gpt-4o-2024-11-20' => 'GPT-4o 2024-11-20 (Latest snapshot)',
            'gpt-4o-mini-2024-07-18' => 'GPT-4o Mini 2024-07-18',

            // GPT-4 Turbo Family
            'gpt-4-turbo' => 'GPT-4 Turbo - $10/$30 per 1M tokens',
            'gpt-4-turbo-preview' => 'GPT-4 Turbo Preview',

            // GPT-4 Family (Original)
            'gpt-4' => 'GPT-4 - $30/$60 per 1M tokens (Original)',

            // O1 Reasoning Models
            'o1-preview' => 'O1 Preview - Advanced reasoning',
            'o1-mini' => 'O1 Mini - Fast reasoning',
        );
    }

    /**
     * Clear the models cache (useful after API key change)
     */
    public function clear_models_cache() {
        delete_transient('mld_openai_models');
    }

    /**
     * Get fallback model
     *
     * @return string Model identifier
     */
    protected function get_fallback_model() {
        return 'gpt-4o-mini';
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
                'label' => 'OpenAI API Key',
                'description' => 'Get your API key from https://platform.openai.com/api-keys',
                'required' => true,
            ),
            'model' => array(
                'type' => 'select',
                'label' => 'Model',
                'options' => $this->get_available_models(),
                'default' => 'gpt-3.5-turbo',
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
                'max' => 2000,
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
        foreach (self::PRICING as $model_name => $rates) {
            if (strpos($model, $model_name) === 0) {
                $pricing = $rates;
                break;
            }
        }

        if (!$pricing) {
            // Default to GPT-3.5 pricing if model not found
            $pricing = self::PRICING['gpt-3.5-turbo'];
        }

        $input_cost = ($input_tokens / 1000000) * $pricing['input'];
        $output_cost = ($output_tokens / 1000000) * $pricing['output'];

        return round($input_cost + $output_cost, 6);
    }

    /**
     * Check if OpenAI is available
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
