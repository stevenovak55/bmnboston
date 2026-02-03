<?php
/**
 * Abstract AI Provider Base Class
 *
 * Provides common functionality for all AI providers
 * Handles logging, error handling, rate limiting, and usage tracking
 *
 * @package MLS_Listings_Display
 * @subpackage Chatbot
 * @since 6.6.0
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once dirname(__FILE__) . '/interface-mld-ai-provider.php';

abstract class MLD_AI_Provider_Base implements MLD_AI_Provider {

    /**
     * Provider name
     *
     * @var string
     */
    protected $provider_name;

    /**
     * API key
     *
     * @var string
     */
    protected $api_key;

    /**
     * Current model
     *
     * @var string
     */
    protected $model;

    /**
     * Default options
     *
     * @var array
     */
    protected $default_options = array(
        'temperature' => 0.7,
        'max_tokens' => 500,
        'top_p' => 1.0,
        'frequency_penalty' => 0.0,
        'presence_penalty' => 0.0,
    );

    /**
     * Constructor
     *
     * @param string $api_key API key for the provider
     * @param string $model Model identifier
     */
    public function __construct($api_key = null, $model = null) {
        $this->api_key = $api_key ?: $this->get_stored_api_key();
        $this->model = $model ?: $this->get_default_model();
    }

    /**
     * Get stored API key from database
     *
     * @return string|null API key or null if not set
     */
    protected function get_stored_api_key() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'mld_chat_settings';

        $key_name = $this->provider_name . '_api_key';

        $result = $wpdb->get_var($wpdb->prepare(
            "SELECT setting_value FROM {$table_name} WHERE setting_key = %s",
            $key_name
        ));

        // Decrypt if encrypted
        if ($result && $this->is_encrypted_key($key_name)) {
            $result = $this->decrypt_api_key($result);
        }

        return $result;
    }

    /**
     * Get stored model from database
     *
     * @return string Default model identifier
     */
    protected function get_default_model() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'mld_chat_settings';

        // First try provider-specific model
        $model = $wpdb->get_var($wpdb->prepare(
            "SELECT setting_value FROM {$table_name} WHERE setting_key = %s",
            $this->provider_name . '_model'
        ));

        // Fall back to general ai_model if provider matches
        if (!$model) {
            $current_provider = $wpdb->get_var($wpdb->prepare(
                "SELECT setting_value FROM {$table_name} WHERE setting_key = %s",
                'ai_provider'
            ));

            if ($current_provider === $this->provider_name) {
                $model = $wpdb->get_var($wpdb->prepare(
                    "SELECT setting_value FROM {$table_name} WHERE setting_key = %s",
                    'ai_model'
                ));
            }
        }

        return $model ?: $this->get_fallback_model();
    }

    /**
     * Get fallback model when no model is configured
     * Must be implemented by each provider
     *
     * @return string Fallback model identifier
     */
    abstract protected function get_fallback_model();

    /**
     * Check if API key is encrypted
     *
     * @param string $key_name Setting key name
     * @return bool True if encrypted
     */
    protected function is_encrypted_key($key_name) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'mld_chat_settings';

        $is_encrypted = $wpdb->get_var($wpdb->prepare(
            "SELECT is_encrypted FROM {$table_name} WHERE setting_key = %s",
            $key_name
        ));

        return (bool) $is_encrypted;
    }

    /**
     * Decrypt API key
     * Uses WordPress salts for encryption/decryption
     *
     * @param string $encrypted Encrypted value
     * @return string Decrypted value
     */
    protected function decrypt_api_key($encrypted) {
        // Simple encryption using WordPress salts
        // In production, consider using more robust encryption
        $key = wp_salt('auth');
        return openssl_decrypt($encrypted, 'AES-256-CBC', $key, 0, substr($key, 0, 16));
    }

    /**
     * Encrypt API key
     *
     * @param string $plaintext Plaintext value
     * @return string Encrypted value
     */
    protected function encrypt_api_key($plaintext) {
        $key = wp_salt('auth');
        return openssl_encrypt($plaintext, 'AES-256-CBC', $key, 0, substr($key, 0, 16));
    }

    /**
     * Log API request
     *
     * @param array $request_data Request data
     * @param array $response_data Response data
     * @param int $response_time_ms Response time in milliseconds
     */
    protected function log_request($request_data, $response_data, $response_time_ms) {
        $log_entry = array(
            'provider' => $this->provider_name,
            'model' => $this->model,
            'request_time' => current_time('mysql'),
            'response_time_ms' => $response_time_ms,
            'success' => isset($response_data['success']) ? $response_data['success'] : false,
            'tokens_used' => isset($response_data['tokens']) ? $response_data['tokens'] : 0,
            'error' => isset($response_data['error']) ? $response_data['error'] : null,
        );

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[MLD AI ' . $this->provider_name . '] ' . json_encode($log_entry));
        }
    }

    /**
     * Track usage in database
     *
     * @param int $input_tokens Input tokens used
     * @param int $output_tokens Output tokens used
     * @param float $cost Estimated cost
     */
    protected function track_usage($input_tokens, $output_tokens, $cost) {
        global $wpdb;

        // Store daily usage stats in transient
        $today = date('Y-m-d');
        $transient_key = 'mld_ai_usage_' . $this->provider_name . '_' . $today;

        $usage = get_transient($transient_key) ?: array(
            'provider' => $this->provider_name,
            'date' => $today,
            'total_requests' => 0,
            'input_tokens' => 0,
            'output_tokens' => 0,
            'total_tokens' => 0,
            'estimated_cost' => 0.0,
        );

        $usage['total_requests']++;
        $usage['input_tokens'] += $input_tokens;
        $usage['output_tokens'] += $output_tokens;
        $usage['total_tokens'] += ($input_tokens + $output_tokens);
        $usage['estimated_cost'] += $cost;

        // Store for 48 hours
        set_transient($transient_key, $usage, 48 * HOUR_IN_SECONDS);
    }

    /**
     * Get usage statistics
     *
     * @param string $period Time period (day, week, month, all)
     * @return array Usage statistics
     */
    public function get_usage_stats($period = 'day') {
        global $wpdb;

        $stats = array(
            'provider' => $this->provider_name,
            'period' => $period,
            'total_requests' => 0,
            'input_tokens' => 0,
            'output_tokens' => 0,
            'total_tokens' => 0,
            'estimated_cost' => 0.0,
        );

        // Determine date range
        switch ($period) {
            case 'week':
                $days = 7;
                break;
            case 'month':
                $days = 30;
                break;
            case 'all':
                $days = 365;
                break;
            default:
                $days = 1;
        }

        // Aggregate stats from transients
        for ($i = 0; $i < $days; $i++) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $transient_key = 'mld_ai_usage_' . $this->provider_name . '_' . $date;
            $day_usage = get_transient($transient_key);

            if ($day_usage) {
                $stats['total_requests'] += $day_usage['total_requests'];
                $stats['input_tokens'] += $day_usage['input_tokens'];
                $stats['output_tokens'] += $day_usage['output_tokens'];
                $stats['total_tokens'] += $day_usage['total_tokens'];
                $stats['estimated_cost'] += $day_usage['estimated_cost'];
            }
        }

        return $stats;
    }

    /**
     * Check rate limits
     *
     * @return array Status array with 'allowed' bool and 'limit' details
     */
    protected function check_rate_limit() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'mld_chat_settings';

        // Get daily message limit
        $daily_limit = $wpdb->get_var($wpdb->prepare(
            "SELECT setting_value FROM {$table_name} WHERE setting_key = %s",
            'daily_message_limit'
        ));

        $daily_limit = intval($daily_limit ?: 1000);

        // Get today's usage
        $today_stats = $this->get_usage_stats('day');

        $allowed = $today_stats['total_requests'] < $daily_limit;
        $remaining = max(0, $daily_limit - $today_stats['total_requests']);

        return array(
            'allowed' => $allowed,
            'limit' => $daily_limit,
            'used' => $today_stats['total_requests'],
            'remaining' => $remaining,
        );
    }

    /**
     * Prepare context for AI
     *
     * @param array $context Raw context data
     * @return string Formatted context string
     */
    protected function prepare_context($context) {
        if (empty($context)) {
            return '';
        }

        $context_parts = array();

        // Add conversation context string if available (v6.14.0)
        // This is the most important context - includes collected info, search criteria, shown properties
        if (!empty($context['conversation_context_string'])) {
            $context_parts[] = $context['conversation_context_string'];
        }

        // Add collected user info (v6.14.0) - CRITICAL: prevents re-asking for contact info
        if (!empty($context['collected_user_info'])) {
            $info = $context['collected_user_info'];
            $info_text = "## COLLECTED USER INFORMATION (DO NOT ASK FOR THIS AGAIN)\n";
            if (!empty($info['name'])) {
                $info_text .= "- Name: {$info['name']}\n";
            }
            if (!empty($info['phone'])) {
                $info_text .= "- Phone: {$info['phone']}\n";
            }
            if (!empty($info['email'])) {
                $info_text .= "- Email: {$info['email']}\n";
            }
            if (!empty($info['preferences'])) {
                $info_text .= "- Preferences: " . json_encode($info['preferences']) . "\n";
            }
            $info_text .= "\nIMPORTANT: Use this contact information when needed. Do NOT ask the user for this info again.\n";
            $context_parts[] = $info_text;
        }

        // Add current page context (v6.27.7) - enables page-aware responses
        if (!empty($context['current_page'])) {
            $page = $context['current_page'];
            $page_text = "## CURRENT PAGE CONTEXT\n";
            $page_text .= "The user is currently viewing: {$page['page_title']}\n";
            $page_text .= "Page URL: {$page['page_url']}\n";
            $page_text .= "Page Type: {$page['page_type']}\n\n";

            switch ($page['page_type']) {
                case 'property_detail':
                    if (!empty($page['property']['full_details'])) {
                        $prop = $page['property']['full_details'];
                        $page_text .= "### PROPERTY DETAILS (User is viewing this property)\n";
                        $page_text .= "You can answer ANY question about this property using the data below:\n\n";

                        if (!empty($prop['mls_number'])) $page_text .= "- MLS Number: {$prop['mls_number']}\n";
                        if (!empty($prop['address'])) $page_text .= "- Address: {$prop['address']}\n";
                        if (!empty($prop['price'])) $page_text .= "- List Price: {$prop['price']}\n";
                        if (!empty($prop['status'])) $page_text .= "- Status: {$prop['status']}\n";
                        if (!empty($prop['bedrooms'])) $page_text .= "- Bedrooms: {$prop['bedrooms']}\n";
                        if (!empty($prop['bathrooms'])) $page_text .= "- Bathrooms: {$prop['bathrooms']}\n";
                        if (!empty($prop['sqft'])) $page_text .= "- Square Feet: {$prop['sqft']}\n";
                        if (!empty($prop['property_type'])) $page_text .= "- Property Type: {$prop['property_type']}\n";
                        if (!empty($prop['year_built'])) $page_text .= "- Year Built: {$prop['year_built']}\n";
                        if (!empty($prop['lot_size'])) $page_text .= "- Lot Size: {$prop['lot_size']}\n";
                        if (!empty($prop['days_on_market'])) $page_text .= "- Days on Market: {$prop['days_on_market']}\n";
                        if (!empty($prop['annual_taxes'])) $page_text .= "- Annual Taxes: {$prop['annual_taxes']}\n";
                        if (!empty($prop['hoa_fee'])) $page_text .= "- HOA Fee: {$prop['hoa_fee']}\n";
                        if (!empty($prop['heating'])) $page_text .= "- Heating: {$prop['heating']}\n";
                        if (!empty($prop['cooling'])) $page_text .= "- Cooling: {$prop['cooling']}\n";
                        if (!empty($prop['garage_spaces'])) $page_text .= "- Garage Spaces: {$prop['garage_spaces']}\n";
                        if (!empty($prop['elementary_school'])) $page_text .= "- Elementary School: {$prop['elementary_school']}\n";
                        if (!empty($prop['middle_school'])) $page_text .= "- Middle School: {$prop['middle_school']}\n";
                        if (!empty($prop['high_school'])) $page_text .= "- High School: {$prop['high_school']}\n";
                        if (!empty($prop['interior_features'])) $page_text .= "- Interior Features: {$prop['interior_features']}\n";
                        if (!empty($prop['exterior_features'])) $page_text .= "- Exterior Features: {$prop['exterior_features']}\n";
                        if (!empty($prop['appliances'])) $page_text .= "- Appliances: {$prop['appliances']}\n";
                        if (!empty($prop['description'])) $page_text .= "\nDescription:\n{$prop['description']}\n";
                        if (!empty($prop['property_url'])) $page_text .= "\nProperty URL: {$prop['property_url']}\n";

                        $page_text .= "\nWhen the user asks about 'this property', 'this listing', 'the MLS number', etc., use the above data to answer.\n";
                    }
                    break;

                case 'calculator':
                    $page_text .= "### CALCULATOR PAGE\n";
                    $page_text .= "The user is on a calculator page. They may ask questions about how to use it or want help with calculations.\n";
                    if (!empty($page['calculator']['calculator_types'])) {
                        $page_text .= "Available calculators: " . implode(', ', $page['calculator']['calculator_types']) . "\n";
                    }
                    break;

                case 'cma':
                    $page_text .= "### CMA (Comparative Market Analysis) PAGE\n";
                    $page_text .= "The user is viewing a Comparative Market Analysis. They may ask about property values or comparables.\n";
                    if (!empty($page['cma']['comparables_count'])) {
                        $page_text .= "Number of comparable properties: {$page['cma']['comparables_count']}\n";
                    }
                    break;

                case 'search_results':
                    $page_text .= "### SEARCH RESULTS PAGE\n";
                    if (!empty($page['search']['results_count'])) {
                        $page_text .= "Currently showing {$page['search']['results_count']} properties.\n";
                    }
                    if (!empty($page['search']['search_params'])) {
                        $page_text .= "Search filters: " . json_encode($page['search']['search_params']) . "\n";
                    }
                    break;

                case 'homepage':
                    $page_text .= "### HOMEPAGE\n";
                    $page_text .= "The user is on the homepage. They may be starting their property search or looking for information.\n";
                    break;

                case 'booking':
                    $page_text .= "### BOOKING/APPOINTMENT PAGE\n";
                    $page_text .= "The user is on the booking/appointment page. They may want to schedule a showing or meeting.\n";
                    break;

                case 'content_page':
                    $page_text .= "### CONTENT PAGE\n";
                    if (!empty($page['content']['heading'])) {
                        $page_text .= "Page heading: {$page['content']['heading']}\n";
                    }
                    if (!empty($page['content']['summary'])) {
                        $page_text .= "Page content summary: {$page['content']['summary']}\n";
                    }
                    break;
            }

            $context_parts[] = $page_text;
        }

        // Add active search criteria (v6.14.0) - maintains search state between messages
        if (!empty($context['active_search_criteria'])) {
            $criteria = $context['active_search_criteria'];
            $criteria_text = "## CURRENT SEARCH CRITERIA\n";
            $criteria_text .= "The user is searching for:\n";
            if (!empty($criteria['city'])) $criteria_text .= "- Location: {$criteria['city']}\n";
            if (!empty($criteria['neighborhood'])) $criteria_text .= "- Neighborhood: {$criteria['neighborhood']}\n";
            if (!empty($criteria['min_price']) || !empty($criteria['max_price'])) {
                $min = !empty($criteria['min_price']) ? '$' . number_format($criteria['min_price']) : '$0';
                $max = !empty($criteria['max_price']) ? '$' . number_format($criteria['max_price']) : 'any';
                $criteria_text .= "- Price range: {$min} - {$max}\n";
            }
            if (!empty($criteria['min_bedrooms'])) $criteria_text .= "- Minimum bedrooms: {$criteria['min_bedrooms']}\n";
            if (!empty($criteria['min_bathrooms'])) $criteria_text .= "- Minimum bathrooms: {$criteria['min_bathrooms']}\n";
            if (!empty($criteria['property_type'])) $criteria_text .= "- Property type: {$criteria['property_type']}\n";
            $criteria_text .= "\nWhen user asks to refine (e.g., 'what about 2 bedrooms?'), KEEP these existing criteria and ADD the new filter.\n";
            $context_parts[] = $criteria_text;
        }

        // Add shown properties for reference resolution (v6.14.0)
        // Enhanced in v6.27.6 to include property URLs for clickable links
        if (!empty($context['shown_properties'])) {
            $shown = $context['shown_properties'];
            $shown_text = "## PREVIOUSLY SHOWN PROPERTIES\n";
            $shown_text .= "These properties were just shown to the user. Use this data to answer follow-up questions:\n";
            foreach ($shown as $prop) {
                $details = array();
                if (!empty($prop['bedrooms'])) $details[] = $prop['bedrooms'] . ' bed';
                if (!empty($prop['bathrooms'])) $details[] = $prop['bathrooms'] . ' bath';
                if (!empty($prop['sqft'])) $details[] = number_format($prop['sqft']) . ' sqft';
                $details_str = !empty($details) ? ' (' . implode(', ', $details) . ')' : '';
                $url_str = !empty($prop['property_url']) ? "\n   View: {$prop['property_url']}" : '';
                $shown_text .= "#{$prop['index']}: {$prop['address']} - {$prop['price']}{$details_str}{$url_str}\n";
            }
            $shown_text .= "\nWhen user refers to 'number 2', 'option 3', 'the first one', etc., use the data above to answer.\n";
            $shown_text .= "For MORE details (HVAC, taxes, rooms, etc.), call the get_property_details tool with the listing_id.\n";
            $shown_text .= "IMPORTANT: Always include the property URL when mentioning a specific property so the user can click to view details.\n";
            $context_parts[] = $shown_text;
        }

        // Add active property context (v6.14.0) - enables detailed Q&A
        if (!empty($context['active_property'])) {
            $prop = $context['active_property'];
            $prop_text = "## ACTIVE PROPERTY (User is asking about this property)\n";
            $prop_text .= "Full property data is available. Answer any question about this property.\n";
            // Add key summary fields
            if (!empty($prop['street_address'])) $prop_text .= "Address: {$prop['street_address']}\n";
            if (!empty($prop['list_price'])) $prop_text .= "Price: $" . number_format($prop['list_price']) . "\n";
            if (!empty($prop['bedrooms_total'])) $prop_text .= "Beds: {$prop['bedrooms_total']}\n";
            if (!empty($prop['bathrooms_total'])) $prop_text .= "Baths: {$prop['bathrooms_total']}\n";
            if (!empty($prop['living_area'])) $prop_text .= "Sqft: " . number_format($prop['living_area']) . "\n";
            if (!empty($prop['heating'])) $prop_text .= "Heating: {$prop['heating']}\n";
            if (!empty($prop['cooling'])) $prop_text .= "Cooling: {$prop['cooling']}\n";
            if (!empty($prop['tax_annual_amount'])) $prop_text .= "Taxes: $" . number_format($prop['tax_annual_amount']) . "/year\n";
            if (!empty($prop['association_fee'])) $prop_text .= "HOA: $" . $prop['association_fee'] . "/month\n";
            $context_parts[] = $prop_text;
        }

        // Add business info
        if (isset($context['business_info'])) {
            $context_parts[] = "Business Information:\n" . $context['business_info'];
        }

        // Add knowledge base entries (v6.27.4: now includes full content_text)
        // This provides the AI with actual website content to answer questions accurately
        if (isset($context['knowledge']) && !empty($context['knowledge'])) {
            $knowledge_text = "## WEBSITE KNOWLEDGE BASE\n";
            $knowledge_text .= "Use this information to answer user questions about our website and services:\n\n";
            foreach ($context['knowledge'] as $item) {
                $knowledge_text .= "### " . $item['content_title'] . " (" . $item['content_type'] . ")\n";
                // Prefer full content_text if available, fall back to summary
                if (!empty($item['content_text'])) {
                    // Limit content to 2000 chars per entry to avoid token bloat
                    $content = $item['content_text'];
                    if (strlen($content) > 2000) {
                        $content = substr($content, 0, 2000) . '...';
                    }
                    $knowledge_text .= $content . "\n\n";
                } elseif (!empty($item['content_summary'])) {
                    $knowledge_text .= $item['content_summary'] . "\n\n";
                }
            }
            $knowledge_text .= "IMPORTANT: Use the above information to provide accurate, specific answers about our website features and services.\n";
            $context_parts[] = $knowledge_text;
        }

        // Add property listings
        if (isset($context['listings']) && !empty($context['listings'])) {
            $listings_text = "Available Properties:\n";
            foreach ($context['listings'] as $listing) {
                $listings_text .= sprintf(
                    "- %s, %s %s: %s beds, %s baths, $%s\n",
                    $listing['street_address'],
                    $listing['city'],
                    $listing['state'],
                    $listing['bedrooms_total'],
                    $listing['bathrooms_total'],
                    number_format($listing['list_price'])
                );
            }
            $context_parts[] = $listings_text;
        }

        // Add user preferences
        if (isset($context['user_preferences'])) {
            $context_parts[] = "User Preferences:\n" . json_encode($context['user_preferences'], JSON_PRETTY_PRINT);
        }

        // v6.27.6: Add link formatting instructions
        $link_instructions = "## LINK FORMATTING INSTRUCTIONS\n";
        $link_instructions .= "When mentioning properties or website pages, ALWAYS include the full URL so users can click to view:\n";
        $link_instructions .= "- For properties: Include the property_url from the data (e.g., " . home_url('/property/12345/') . ")\n";
        $link_instructions .= "- For website pages: Include the page URL when referring to calculators, booking, or other features\n";
        $link_instructions .= "- Format: 'View this property: [URL]' or 'You can access our calculator at: [URL]'\n";
        $link_instructions .= "- URLs should be on their own line or clearly separated so they're easy to click\n";
        $context_parts[] = $link_instructions;

        return implode("\n\n", $context_parts);
    }

    /**
     * Format error response
     *
     * @param string $message Error message
     * @param string $code Error code
     * @return array Standardized error response
     */
    protected function format_error($message, $code = 'provider_error') {
        return array(
            'success' => false,
            'error' => $message,
            'error_code' => $code,
            'provider' => $this->provider_name,
            'timestamp' => current_time('mysql'),
        );
    }

    /**
     * Validate API credentials
     *
     * @param string $api_key API key to validate
     * @return bool True if valid
     */
    public function validate_credentials($api_key) {
        // Store current key
        $original_key = $this->api_key;

        // Temporarily use provided key
        $this->api_key = $api_key;

        // Test connection
        $result = $this->test_connection();

        // Restore original key
        $this->api_key = $original_key;

        return isset($result['success']) && $result['success'];
    }

    /**
     * Get provider name
     *
     * @return string Provider name
     */
    public function get_provider_name() {
        return $this->provider_name;
    }

    /**
     * Get custom system prompt from database (v6.8.0, enhanced v6.9.0)
     * Replaces placeholders with actual values
     * Supports A/B testing with prompt variants
     *
     * @param array $context Context data for placeholder replacement
     * @param int|null $conversation_id Optional conversation ID for tracking
     * @return array Prompt data with 'content' and 'variant_id'
     */
    protected function get_custom_system_prompt($context = array(), $conversation_id = null) {
        global $wpdb;
        $settings_table = $wpdb->prefix . 'mld_chat_settings';

        $variant_id = null;
        $prompt = '';

        // Check if A/B testing is enabled (v6.9.0)
        $ab_testing_enabled = $wpdb->get_var($wpdb->prepare(
            "SELECT setting_value FROM {$settings_table} WHERE setting_key = %s",
            'enable_ab_testing'
        ));

        if ($ab_testing_enabled == '1') {
            // Get a variant based on weighted distribution
            $variant = $this->select_prompt_variant();
            if ($variant) {
                $variant_id = $variant['id'];
                $prompt = $variant['prompt_content'];

                // Track variant usage
                if ($conversation_id) {
                    $this->track_prompt_usage($conversation_id, $variant_id, $prompt);
                }
            }
        }

        // Fall back to default system prompt if no variant selected
        if (empty($prompt)) {
            $prompt = $wpdb->get_var($wpdb->prepare(
                "SELECT setting_value FROM {$settings_table} WHERE setting_key = %s",
                'system_prompt'
            ));
        }

        // If still no prompt, use hardcoded default
        if (empty($prompt)) {
            $prompt = "You are a professional real estate assistant for {business_name}.

Your role:
- Help users find properties that match their needs
- Answer questions about our listings and services
- Provide helpful real estate information
- Be friendly, professional, and knowledgeable

Guidelines:
- Keep responses concise (2-3 paragraphs max)
- Use a warm, conversational tone
- If you don't know something, be honest
- Always encourage users to contact us for detailed help

Available data:
{current_listings_count} active listings
Price range: {price_range}
Property types: Residential, Commercial, Land

When users ask about specific properties, provide general guidance and suggest they use our search tools for detailed results.";
        }

        // Get extended prompt variables (v6.9.0)
        $prompt_vars = $this->get_prompt_variables();

        // Build complete placeholders array
        $placeholders = array(
            '{business_name}' => isset($context['business_name']) ? $context['business_name'] : get_bloginfo('name'),
            '{current_listings_count}' => isset($context['listings_count']) ? $context['listings_count'] : '0',
            '{price_range}' => isset($context['price_range']) ? $context['price_range'] : '$0 - $0',
            '{site_url}' => home_url(),
            '{business_hours}' => $prompt_vars['business_hours'],
            '{specialties}' => $prompt_vars['specialties'],
            '{service_areas}' => $prompt_vars['service_areas'],
            '{contact_phone}' => $prompt_vars['contact_phone'],
            '{contact_email}' => $prompt_vars['contact_email'],
            '{team_size}' => $prompt_vars['team_size'],
            '{years_in_business}' => $prompt_vars['years_in_business'],
        );

        foreach ($placeholders as $placeholder => $value) {
            $prompt = str_replace($placeholder, $value, $prompt);
        }

        return array(
            'content' => $prompt,
            'variant_id' => $variant_id
        );
    }

    /**
     * Get prompt variables from settings (v6.9.0)
     *
     * @return array Associative array of prompt variables
     */
    protected function get_prompt_variables() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'mld_chat_settings';

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT setting_key, setting_value FROM {$table_name} WHERE setting_category = %s",
            'prompt_variables'
        ), ARRAY_A);

        $variables = array(
            'business_hours' => '',
            'specialties' => '',
            'service_areas' => '',
            'contact_phone' => '',
            'contact_email' => '',
            'team_size' => '',
            'years_in_business' => '',
        );

        foreach ($results as $row) {
            if (isset($variables[$row['setting_key']])) {
                $variables[$row['setting_key']] = $row['setting_value'];
            }
        }

        return $variables;
    }

    /**
     * Select prompt variant based on weighted distribution (v6.9.0)
     *
     * @return array|null Variant data or null if none active
     */
    protected function select_prompt_variant() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'mld_prompt_variants';

        // Get all active variants
        $variants = $wpdb->get_results(
            "SELECT id, variant_name, prompt_content, weight FROM {$table_name} WHERE is_active = 1 ORDER BY id",
            ARRAY_A
        );

        if (empty($variants)) {
            return null;
        }

        // Calculate total weight
        $total_weight = array_sum(array_column($variants, 'weight'));
        if ($total_weight <= 0) {
            return $variants[0]; // Return first if no weights set
        }

        // Select variant based on weighted random
        $random = rand(1, $total_weight);
        $cumulative = 0;

        foreach ($variants as $variant) {
            $cumulative += $variant['weight'];
            if ($random <= $cumulative) {
                return $variant;
            }
        }

        return $variants[0]; // Fallback to first variant
    }

    /**
     * Track prompt usage for analytics (v6.9.0)
     *
     * @param int $conversation_id Conversation ID
     * @param int $variant_id Variant ID used
     * @param string $prompt_used Actual prompt text used
     */
    protected function track_prompt_usage($conversation_id, $variant_id, $prompt_used) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'mld_prompt_usage';

        $wpdb->insert(
            $table_name,
            array(
                'conversation_id' => $conversation_id,
                'variant_id' => $variant_id,
                'prompt_used' => $prompt_used,
            ),
            array('%d', '%d', '%s')
        );

        // Increment usage count on variant
        $variants_table = $wpdb->prefix . 'mld_prompt_variants';
        $wpdb->query($wpdb->prepare(
            "UPDATE {$variants_table} SET total_uses = total_uses + 1 WHERE id = %d",
            $variant_id
        ));
    }
}
