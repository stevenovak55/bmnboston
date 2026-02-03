<?php
/**
 * Smart Model Router for Multi-Provider AI
 *
 * Intelligently routes queries to the most appropriate AI model based on:
 * - Query type (property search, FAQ, market analysis, etc.)
 * - Cost optimization (prefer cheaper models when capable)
 * - Availability (fallback chain if primary model unavailable)
 * - Admin configuration
 *
 * @package MLS_Listings_Display
 * @subpackage Chatbot
 * @since 6.11.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Model_Router {

    /**
     * Singleton instance
     *
     * @var MLD_Model_Router
     */
    private static $instance = null;

    /**
     * Available providers with their instances
     *
     * @var array
     */
    private $providers = array();

    /**
     * Routing configuration
     *
     * @var array
     */
    private $routing_config = array();

    /**
     * Query type constants
     */
    const QUERY_TYPE_SIMPLE = 'simple';           // Greetings, basic FAQ
    const QUERY_TYPE_PROPERTY_SEARCH = 'search';  // Property searches (needs tools)
    const QUERY_TYPE_MARKET_ANALYSIS = 'analysis'; // Complex market analysis
    const QUERY_TYPE_GENERAL = 'general';         // General real estate Q&A

    /**
     * Provider priority for cost optimization (cheapest first)
     */
    const COST_PRIORITY = array(
        'gemini' => 1,    // Gemini Flash is cheapest
        'openai' => 2,    // GPT-4o-mini is affordable
        'claude' => 3,    // Claude is more expensive
    );

    /**
     * Get singleton instance
     *
     * @return MLD_Model_Router
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->load_routing_config();
        $this->initialize_providers();
    }

    /**
     * Load routing configuration from database
     */
    private function load_routing_config() {
        global $wpdb;
        $settings_table = $wpdb->prefix . 'mld_chat_settings';

        // Get routing settings
        $routing_json = $wpdb->get_var($wpdb->prepare(
            "SELECT setting_value FROM {$settings_table} WHERE setting_key = %s",
            'model_routing_config'
        ));

        if ($routing_json) {
            $this->routing_config = json_decode($routing_json, true);
        }

        // Use defaults if not configured
        if (empty($this->routing_config)) {
            $this->routing_config = $this->get_default_routing_config();
        }
    }

    /**
     * Get default routing configuration
     *
     * @return array
     */
    private function get_default_routing_config() {
        return array(
            'enabled' => true,
            'cost_optimization' => true,
            'primary_provider' => 'openai',
            'fallback_enabled' => true,
            'query_routing' => array(
                self::QUERY_TYPE_SIMPLE => array(
                    'preferred' => array('openai:gpt-4o-mini', 'gemini:gemini-1.5-flash', 'claude:claude-3-5-haiku'),
                    'reason' => 'Fast and cheap for simple queries',
                ),
                self::QUERY_TYPE_PROPERTY_SEARCH => array(
                    'preferred' => array('openai:gpt-4o', 'openai:gpt-4o-mini', 'claude:claude-3-5-sonnet'),
                    'reason' => 'Best function calling support',
                ),
                self::QUERY_TYPE_MARKET_ANALYSIS => array(
                    'preferred' => array('claude:claude-3-5-sonnet', 'openai:gpt-4o', 'gemini:gemini-1.5-pro'),
                    'reason' => 'Strong reasoning capabilities',
                ),
                self::QUERY_TYPE_GENERAL => array(
                    'preferred' => array('openai:gpt-4o-mini', 'gemini:gemini-1.5-flash', 'claude:claude-3-5-haiku'),
                    'reason' => 'Balanced cost and capability',
                ),
            ),
        );
    }

    /**
     * Initialize available providers
     */
    private function initialize_providers() {
        global $wpdb;
        $settings_table = $wpdb->prefix . 'mld_chat_settings';

        // Get all API keys with encryption status
        $api_keys = $wpdb->get_results(
            "SELECT setting_key, setting_value, is_encrypted FROM {$settings_table}
             WHERE setting_key IN ('openai_api_key', 'claude_api_key', 'gemini_api_key')",
            OBJECT_K
        );

        $chatbot_path = dirname(__FILE__);

        // Initialize OpenAI provider if configured
        if (!empty($api_keys['openai_api_key']->setting_value)) {
            require_once $chatbot_path . '/providers/class-mld-openai-provider.php';
            $model = $this->get_provider_model('openai');
            $api_key = $this->decrypt_api_key(
                $api_keys['openai_api_key']->setting_value,
                !empty($api_keys['openai_api_key']->is_encrypted)
            );
            $this->providers['openai'] = new MLD_OpenAI_Provider($api_key, $model);
        }

        // Initialize Claude provider if configured
        if (!empty($api_keys['claude_api_key']->setting_value)) {
            require_once $chatbot_path . '/providers/class-mld-claude-provider.php';
            $model = $this->get_provider_model('claude');
            $api_key = $this->decrypt_api_key(
                $api_keys['claude_api_key']->setting_value,
                !empty($api_keys['claude_api_key']->is_encrypted)
            );
            $this->providers['claude'] = new MLD_Claude_Provider($api_key, $model);
        }

        // Initialize Gemini provider if configured
        if (!empty($api_keys['gemini_api_key']->setting_value)) {
            require_once $chatbot_path . '/providers/class-mld-gemini-provider.php';
            $model = $this->get_provider_model('gemini');
            $api_key = $this->decrypt_api_key(
                $api_keys['gemini_api_key']->setting_value,
                !empty($api_keys['gemini_api_key']->is_encrypted)
            );
            $this->providers['gemini'] = new MLD_Gemini_Provider($api_key, $model);
        }
    }

    /**
     * Decrypt an API key if it's encrypted
     *
     * @param string $value The stored value
     * @param bool $is_encrypted Whether the value is encrypted
     * @return string Decrypted API key
     */
    private function decrypt_api_key($value, $is_encrypted) {
        if (!$is_encrypted) {
            return $value;
        }

        $key = wp_salt('auth');
        $decrypted = openssl_decrypt($value, 'AES-256-CBC', $key, 0, substr($key, 0, 16));

        // If decryption fails, return original (might be unencrypted legacy key)
        return $decrypted !== false ? $decrypted : $value;
    }

    /**
     * Get configured model for a provider
     *
     * @param string $provider Provider name
     * @return string Model identifier
     */
    private function get_provider_model($provider) {
        global $wpdb;
        $settings_table = $wpdb->prefix . 'mld_chat_settings';

        $model = $wpdb->get_var($wpdb->prepare(
            "SELECT setting_value FROM {$settings_table} WHERE setting_key = %s",
            $provider . '_model'
        ));

        // Default models
        $defaults = array(
            'openai' => 'gpt-4o-mini',
            'claude' => 'claude-3-5-haiku-20241022',
            'gemini' => 'gemini-1.5-flash',
        );

        return $model ?: ($defaults[$provider] ?? null);
    }

    /**
     * Route a query to the best available model
     *
     * @param string $message User message
     * @param array $context Conversation context
     * @param array $options Additional options
     * @return array Response with provider info
     */
    public function route($message, $context = array(), $options = array()) {
        // Classify the query type
        $query_type = $this->classify_query($message);

        // Debug logging (v6.14.0)
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("[MLD Router 6.14.0] Query: '" . substr($message, 0, 50) . "...' => Type: {$query_type}");
        }

        // Get ordered list of providers to try
        $provider_chain = $this->get_provider_chain($query_type);

        if (empty($provider_chain)) {
            return array(
                'success' => false,
                'error' => 'No AI providers configured',
                'error_code' => 'no_providers',
            );
        }

        // Try each provider in order
        foreach ($provider_chain as $provider_spec) {
            list($provider_name, $model) = $this->parse_provider_spec($provider_spec);

            if (!isset($this->providers[$provider_name])) {
                continue;
            }

            $provider = $this->providers[$provider_name];

            // Check if provider is available
            $availability = $provider->is_available();
            if (!$availability['available']) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log("[MLD Router] Skipping {$provider_name}: " . ($availability['reason'] ?? 'unavailable'));
                }
                continue;
            }

            // Override model if specified
            if ($model && method_exists($provider, 'set_model')) {
                $provider->set_model($model);
            }

            // Determine if we should use function calling
            $use_tools = $this->should_use_tools($query_type, $provider_name);

            // Debug logging (v6.14.0)
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("[MLD Router 6.14.0] Using {$provider_name}, use_tools=" . ($use_tools ? 'true' : 'false'));
            }

            // Execute the request
            if ($use_tools && method_exists($provider, 'chat_with_tools')) {
                $result = $provider->chat_with_tools($this->format_messages($message), $context, $options);
            } else {
                $result = $provider->chat($this->format_messages($message), $context, $options);
            }

            // If successful, return with routing metadata
            if (!empty($result['success'])) {
                $result['routing'] = array(
                    'query_type' => $query_type,
                    'provider_used' => $provider_name,
                    'model_used' => $result['model'] ?? $model,
                    'tools_enabled' => $use_tools,
                    'fallback_used' => ($provider_spec !== $provider_chain[0]),
                );
                return $result;
            }

            // Log failure and try next
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("[MLD Router] {$provider_name} failed: " . ($result['error'] ?? 'unknown error'));
            }

            // If fallback is disabled, stop here
            if (empty($this->routing_config['fallback_enabled'])) {
                return $result;
            }
        }

        return array(
            'success' => false,
            'error' => 'All AI providers failed',
            'error_code' => 'all_providers_failed',
        );
    }

    /**
     * Classify query type based on content
     *
     * @param string $message User message
     * @return string Query type constant
     */
    public function classify_query($message) {
        $message_lower = strtolower($message);

        // Simple greetings and basic questions
        $simple_patterns = array(
            '/^(hi|hello|hey|good morning|good afternoon|good evening)[\s!.,]*$/i',
            '/^(thanks|thank you|bye|goodbye)[\s!.,]*$/i',
            '/^(how are you|what\'s up|what can you do)[\s?]*$/i',
        );

        foreach ($simple_patterns as $pattern) {
            if (preg_match($pattern, $message)) {
                return self::QUERY_TYPE_SIMPLE;
            }
        }

        // Property search patterns (need function calling)
        $search_patterns = array(
            'show me',
            'find',
            'search for',
            'looking for',
            'homes in',
            'houses in',
            'properties in',
            'bedroom',
            'bath',
            'under $',
            'less than $',
            'between $',
            'price range',
            'for sale',
            'for rent',
            'listing',
            'mls',
            // Property types - these indicate user wants to search for properties
            'condo',
            'condos',
            'condominium',
            'apartment',
            'apartments',
            'townhouse',
            'townhome',
            'single family',
            'single-family',
            'duplex',
            'multi-family',
            'multifamily',
            // Question patterns about property availability
            'do you have',
            'if you have',
            'have any',
            'are there',
            'any available',
            'what\'s available',
            "what's available",
            // Street/location patterns (v6.14.0)
            'properties on',
            'homes on',
            'houses on',
            'on main street',
            'on grove street',
            'on the street',
        );

        foreach ($search_patterns as $pattern) {
            if (strpos($message_lower, $pattern) !== false) {
                return self::QUERY_TYPE_PROPERTY_SEARCH;
            }
        }

        // Market analysis patterns
        $analysis_patterns = array(
            'market trend',
            'price trend',
            'market analysis',
            'compare',
            'cma',
            'investment',
            'appreciation',
            'forecast',
            'market conditions',
            'average price',
            'median price',
            'inventory',
        );

        foreach ($analysis_patterns as $pattern) {
            if (strpos($message_lower, $pattern) !== false) {
                return self::QUERY_TYPE_MARKET_ANALYSIS;
            }
        }

        // Property detail patterns (v6.14.0) - need tools to resolve references and get details
        $detail_patterns = array(
            'tell me more',
            'tell me about',
            'more details',
            'details on',
            'details about',
            'more info',
            'more information',
            'what about',
            'can you tell me',
            'describe',
            'description',
        );

        foreach ($detail_patterns as $pattern) {
            if (strpos($message_lower, $pattern) !== false) {
                return self::QUERY_TYPE_PROPERTY_SEARCH;
            }
        }

        // Property reference patterns (v6.14.0) - user referring to numbered results
        $reference_patterns = array(
            '/\b(number|option|#)\s*\d+/i',                    // number 5, option 2, #3
            '/\b(first|second|third|fourth|fifth)\s*(one|property|listing)?/i',  // first one, second property
            '/\bthe\s+\d+(st|nd|rd|th)\s*(one|property|listing)?/i',  // the 3rd one
            '/\boption\s+\d+/i',                               // option 5
        );

        foreach ($reference_patterns as $pattern) {
            if (preg_match($pattern, $message)) {
                return self::QUERY_TYPE_PROPERTY_SEARCH;
            }
        }

        // Street address patterns (v6.14.0) - user mentioning a specific address
        $street_patterns = array(
            '/\d+\s+\w+\s+(st|street|ave|avenue|rd|road|dr|drive|ln|lane|blvd|way|pl|place|ct|court)/i',
        );

        foreach ($street_patterns as $pattern) {
            if (preg_match($pattern, $message)) {
                return self::QUERY_TYPE_PROPERTY_SEARCH;
            }
        }

        // Default to general
        return self::QUERY_TYPE_GENERAL;
    }

    /**
     * Get ordered provider chain for a query type
     *
     * @param string $query_type Query type
     * @return array Ordered list of provider:model specs
     */
    private function get_provider_chain($query_type) {
        $chain = array();

        // Get preferred providers for this query type
        if (isset($this->routing_config['query_routing'][$query_type]['preferred'])) {
            $chain = $this->routing_config['query_routing'][$query_type]['preferred'];
        }

        // Filter to only available providers
        $available_chain = array();
        foreach ($chain as $spec) {
            list($provider_name, ) = $this->parse_provider_spec($spec);
            if (isset($this->providers[$provider_name])) {
                $available_chain[] = $spec;
            }
        }

        // If cost optimization is enabled, sort by cost
        if (!empty($this->routing_config['cost_optimization']) && count($available_chain) > 1) {
            usort($available_chain, function($a, $b) {
                list($provider_a, ) = $this->parse_provider_spec($a);
                list($provider_b, ) = $this->parse_provider_spec($b);
                $cost_a = self::COST_PRIORITY[$provider_a] ?? 99;
                $cost_b = self::COST_PRIORITY[$provider_b] ?? 99;
                return $cost_a - $cost_b;
            });
        }

        // Add any remaining providers not in the preferred list (for fallback)
        foreach (array_keys($this->providers) as $provider_name) {
            $found = false;
            foreach ($available_chain as $spec) {
                list($p, ) = $this->parse_provider_spec($spec);
                if ($p === $provider_name) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $available_chain[] = $provider_name;
            }
        }

        return $available_chain;
    }

    /**
     * Parse provider:model spec
     *
     * @param string $spec Provider spec (e.g., "openai:gpt-4o-mini" or just "openai")
     * @return array [provider_name, model_or_null]
     */
    private function parse_provider_spec($spec) {
        if (strpos($spec, ':') !== false) {
            return explode(':', $spec, 2);
        }
        return array($spec, null);
    }

    /**
     * Check if tools should be used for this query type and provider
     *
     * @param string $query_type Query type
     * @param string $provider_name Provider name
     * @return bool
     */
    private function should_use_tools($query_type, $provider_name) {
        // Only use tools for property search and market analysis
        if (!in_array($query_type, array(self::QUERY_TYPE_PROPERTY_SEARCH, self::QUERY_TYPE_MARKET_ANALYSIS))) {
            return false;
        }

        // Check if provider supports function calling
        $provider = $this->providers[$provider_name] ?? null;
        if (!$provider) {
            return false;
        }

        if (method_exists($provider, 'supports_function_calling')) {
            return $provider->supports_function_calling();
        }

        // Default: OpenAI and Claude support it
        return in_array($provider_name, array('openai', 'claude'));
    }

    /**
     * Format message for provider
     *
     * @param string $message User message
     * @return array Formatted messages array
     */
    private function format_messages($message) {
        return array(
            array('role' => 'user', 'content' => $message),
        );
    }

    /**
     * Get available providers
     *
     * @return array Provider names
     */
    public function get_available_providers() {
        return array_keys($this->providers);
    }

    /**
     * Get provider instance
     *
     * @param string $name Provider name
     * @return object|null Provider instance
     */
    public function get_provider($name) {
        return $this->providers[$name] ?? null;
    }

    /**
     * Check if routing is enabled
     *
     * @return bool
     */
    public function is_enabled() {
        return !empty($this->routing_config['enabled']);
    }

    /**
     * Get routing statistics
     *
     * @return array Stats
     */
    public function get_stats() {
        return array(
            'providers_available' => count($this->providers),
            'providers' => array_keys($this->providers),
            'routing_enabled' => $this->is_enabled(),
            'cost_optimization' => !empty($this->routing_config['cost_optimization']),
            'fallback_enabled' => !empty($this->routing_config['fallback_enabled']),
        );
    }

    /**
     * Save routing configuration
     *
     * @param array $config Configuration array
     * @return bool Success
     */
    public function save_routing_config($config) {
        global $wpdb;
        $settings_table = $wpdb->prefix . 'mld_chat_settings';

        $result = $wpdb->replace($settings_table, array(
            'setting_key' => 'model_routing_config',
            'setting_value' => json_encode($config),
        ));

        if ($result !== false) {
            $this->routing_config = $config;
            return true;
        }

        return false;
    }
}

/**
 * Get the model router instance
 *
 * @return MLD_Model_Router
 */
function mld_get_model_router() {
    return MLD_Model_Router::get_instance();
}
