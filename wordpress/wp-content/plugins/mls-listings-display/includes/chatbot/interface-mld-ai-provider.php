<?php
/**
 * AI Provider Interface
 *
 * Defines the contract that all AI providers must implement
 * to support multiple AI services (OpenAI, Claude, Gemini, etc.)
 *
 * @package MLS_Listings_Display
 * @subpackage Chatbot
 * @since 6.6.0
 */

if (!defined('ABSPATH')) {
    exit;
}

interface MLD_AI_Provider {

    /**
     * Send a chat message and get AI response
     *
     * @param array $messages Array of message objects with 'role' and 'content'
     * @param array $context Additional context to inject (knowledge base, user data, etc.)
     * @param array $options Provider-specific options (temperature, max_tokens, etc.)
     * @return array Response data including text, tokens, metadata
     */
    public function chat($messages, $context = array(), $options = array());

    /**
     * Test the API connection
     *
     * @return array Success status and any error messages
     */
    public function test_connection();

    /**
     * Get provider name
     *
     * @return string Provider name (openai, claude, gemini, etc.)
     */
    public function get_provider_name();

    /**
     * Get available models for this provider
     *
     * @return array List of available model identifiers
     */
    public function get_available_models();

    /**
     * Get current usage statistics
     *
     * @param string $period Time period (day, week, month, all)
     * @return array Usage stats (total_requests, total_tokens, cost_estimate, etc.)
     */
    public function get_usage_stats($period = 'day');

    /**
     * Validate API credentials
     *
     * @param string $api_key API key to validate
     * @return bool True if valid, false otherwise
     */
    public function validate_credentials($api_key);

    /**
     * Get provider-specific configuration options
     *
     * @return array Configuration schema
     */
    public function get_config_schema();

    /**
     * Get estimated cost per request
     *
     * @param int $input_tokens Estimated input tokens
     * @param int $output_tokens Estimated output tokens
     * @param string $model Model identifier
     * @return float Estimated cost in USD
     */
    public function estimate_cost($input_tokens, $output_tokens, $model = null);

    /**
     * Check if provider is currently available
     *
     * @return array Status array with 'available' bool and 'reason' string if unavailable
     */
    public function is_available();
}
