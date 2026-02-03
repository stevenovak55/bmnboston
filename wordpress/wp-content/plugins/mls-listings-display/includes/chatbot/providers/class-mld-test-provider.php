<?php
/**
 * Test/Demo AI Provider
 *
 * Mock provider for testing chatbot functionality without API keys
 * Returns realistic demo responses based on common real estate questions
 *
 * @package MLS_Listings_Display
 * @subpackage Chatbot
 * @since 6.6.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Test_Provider extends MLD_AI_Provider_Base {

    /**
     * Provider name
     *
     * @var string
     */
    protected $provider_name = 'test';

    /**
     * Demo responses for common questions
     *
     * @var array
     */
    private $demo_responses = array();

    /**
     * Constructor
     */
    public function __construct() {
        parent::__construct();
        $this->initialize_demo_responses();
    }

    /**
     * Initialize demo response templates
     */
    private function initialize_demo_responses() {
        $this->demo_responses = array(
            'greeting' => array(
                "Hello! 👋 I'm your AI property assistant. I can help you find homes, answer questions about the market, and provide information about neighborhoods. What would you like to know?",
                "Hi there! Welcome to our real estate chatbot. I'm here to help you discover your dream home. How can I assist you today?",
            ),
            'properties' => array(
                "I'd be happy to help you find properties! Based on our current listings, we have several excellent options available. Could you tell me more about what you're looking for? For example:\n\n• Number of bedrooms and bathrooms\n• Preferred location or neighborhood\n• Price range\n• Any specific features (pool, garage, etc.)\n\nThis will help me narrow down the best matches for you!",
                "Great question about our available properties! We currently have a diverse selection of homes ranging from cozy condos to spacious family homes. To provide you with the most relevant options, could you share your preferences regarding location, size, and budget?",
            ),
            'price' => array(
                "Property prices in our area vary based on location, size, and amenities. Here's a general overview:\n\n• Entry-level homes: $300,000 - $450,000\n• Mid-range properties: $450,000 - $750,000\n• Luxury homes: $750,000+\n\nThe market is currently showing steady appreciation with competitive interest rates. Would you like information about a specific neighborhood or property type?",
                "Pricing in the current market depends on several factors. Generally, you can expect to find homes in various price ranges depending on the area and property features. I recommend getting pre-approved for a mortgage to understand your budget better. Would you like me to connect you with one of our preferred lenders?",
            ),
            'location' => array(
                "Location is one of the most important factors in real estate! Our service area includes several wonderful communities, each with unique characteristics:\n\n• Urban areas with walkability and amenities\n• Suburban neighborhoods with great schools\n• Quiet residential areas with larger lots\n• Waterfront and scenic locations\n\nWhat type of environment appeals to you most?",
            ),
            'schools' => array(
                "School quality is a top priority for many families! The areas we serve include some highly-rated school districts. Most neighborhoods have elementary, middle, and high schools nearby. For specific school ratings and test scores, I recommend checking GreatSchools.org or contacting the local school district. Would you like information about family-friendly neighborhoods?",
            ),
            'market' => array(
                "The current real estate market is showing interesting trends:\n\n📈 Market Activity: Moderate to strong depending on price point\n🏠 Inventory: Balanced with new listings coming regularly\n💰 Prices: Steady appreciation in most areas\n⏱️ Days on Market: Averaging 30-45 days\n\nIt's a good time for both buyers and sellers, though working with an experienced agent is crucial. Would you like to schedule a consultation to discuss your specific situation?",
            ),
            'mortgage' => array(
                "Financing is an important consideration! Here are some key points about mortgages:\n\n• Interest rates are currently competitive\n• Getting pre-approved helps strengthen your offer\n• Various loan types available (Conventional, FHA, VA, etc.)\n• Down payment requirements vary (typically 3-20%)\n\nI recommend connecting with a mortgage lender to explore your options. Would you like a referral to one of our trusted lending partners?",
            ),
            'default' => array(
                "That's a great question! As an AI assistant, I'm here to help with information about properties, neighborhoods, pricing, and the home buying process. Could you provide a bit more detail about what you'd like to know? I'm happy to help with:\n\n• Available properties\n• Neighborhood information\n• Market conditions\n• The buying/selling process\n• Mortgage and financing basics",
                "I'd love to help you with that! To give you the most accurate and helpful information, could you rephrase your question or provide a bit more context? I'm knowledgeable about our property listings, local market trends, neighborhoods, and the real estate process in general.",
            ),
        );
    }

    /**
     * Send chat message
     *
     * @param array $messages Message history
     * @param array $context Additional context
     * @param array $options Additional options
     * @return array Response with success status and message
     */
    public function chat($messages, $context = array(), $options = array()) {
        // Add artificial delay to simulate API call
        usleep(500000); // 0.5 seconds

        // Get last user message
        $last_message = end($messages);
        $user_message = isset($last_message['content']) ? strtolower($last_message['content']) : '';

        // Determine response category
        $category = $this->categorize_message($user_message);

        // Get appropriate response
        $response_text = $this->get_demo_response($category);

        // Add context-aware enhancement if available
        if (isset($context['business_info'])) {
            $response_text = $this->enhance_with_context($response_text, $context);
        }

        // Track mock usage
        $this->track_usage(50, 100, 0.002); // Mock token counts and cost

        return array(
            'success' => true,
            'text' => $response_text,
            'provider' => 'test',
            'model' => 'demo-v1',
            'tokens' => array(
                'prompt_tokens' => 50,
                'completion_tokens' => 100,
                'total_tokens' => 150,
            ),
            'cost' => 0.002,
        );
    }

    /**
     * Categorize user message to determine response type
     *
     * @param string $message User message (lowercase)
     * @return string Category
     */
    private function categorize_message($message) {
        // Greeting patterns
        if (preg_match('/\b(hi|hello|hey|greetings|good morning|good afternoon)\b/', $message)) {
            return 'greeting';
        }

        // Property search patterns
        if (preg_match('/\b(property|properties|home|homes|house|houses|listing|listings|available|for sale|buy|purchase)\b/', $message)) {
            return 'properties';
        }

        // Price/cost patterns
        if (preg_match('/\b(price|cost|expensive|affordable|budget|how much|pricing)\b/', $message)) {
            return 'price';
        }

        // Location patterns
        if (preg_match('/\b(location|area|neighborhood|city|town|where|region|district)\b/', $message)) {
            return 'location';
        }

        // School patterns
        if (preg_match('/\b(school|schools|education|district|elementary|high school|rating)\b/', $message)) {
            return 'schools';
        }

        // Market patterns
        if (preg_match('/\b(market|trend|trends|appreciation|inventory|competitive|buyers|sellers)\b/', $message)) {
            return 'market';
        }

        // Mortgage/financing patterns
        if (preg_match('/\b(mortgage|loan|financing|lender|interest rate|down payment|pre-approved|qualify)\b/', $message)) {
            return 'mortgage';
        }

        return 'default';
    }

    /**
     * Get demo response for category
     *
     * @param string $category Response category
     * @return string Response text
     */
    private function get_demo_response($category) {
        if (!isset($this->demo_responses[$category])) {
            $category = 'default';
        }

        $responses = $this->demo_responses[$category];
        return $responses[array_rand($responses)];
    }

    /**
     * Enhance response with context data
     *
     * @param string $response Base response
     * @param array $context Context data
     * @return string Enhanced response
     */
    private function enhance_with_context($response, $context) {
        // Add business info footer if available
        if (isset($context['business_info'])) {
            $response .= "\n\n📞 Feel free to contact us for personalized assistance!";
        }

        // Add sample listings if available in context
        if (isset($context['sample_listings']) && !empty($context['sample_listings'])) {
            $response .= "\n\n🏠 Here are a few examples from our current inventory:";

            $count = 0;
            foreach ($context['sample_listings'] as $listing) {
                if ($count >= 2) break; // Limit to 2 examples

                $response .= sprintf(
                    "\n• %s, %s - %d bed, %d bath - $%s",
                    $listing['street_address'],
                    $listing['city'],
                    $listing['bedrooms_total'],
                    $listing['bathrooms_total'],
                    number_format($listing['list_price'])
                );

                $count++;
            }
        }

        return $response;
    }

    /**
     * Test API connection
     *
     * @param string $api_key API key (not used for test provider)
     * @return array Result with success status
     */
    public function test_connection($api_key = '') {
        // Simulate connection test
        usleep(300000); // 0.3 seconds

        return array(
            'success' => true,
            'message' => '✅ Test/Demo Provider is ready! No API key required. This provider returns realistic demo responses for testing the chatbot system.',
            'provider' => 'test',
            'model' => 'demo-v1',
        );
    }

    /**
     * Get available models
     *
     * @return array Available models
     */
    public function get_available_models() {
        return array(
            'demo-v1' => array(
                'name' => 'Demo Responder v1',
                'description' => 'Mock provider for testing - returns realistic real estate responses',
                'pricing' => array(
                    'input' => 0,
                    'output' => 0,
                ),
            ),
        );
    }

    /**
     * Validate credentials (always passes for test provider)
     *
     * @param string $api_key API key
     * @return bool
     */
    public function validate_credentials($api_key = '') {
        return true; // No credentials needed
    }

    /**
     * Get usage statistics
     *
     * @param string $period The period for stats (day, week, month)
     * @return array Usage stats
     */
    public function get_usage_stats($period = 'day') {
        global $wpdb;

        $today = date('Y-m-d');
        $transient_key = 'mld_ai_usage_test_' . $today;

        $usage = get_transient($transient_key);

        if ($usage === false) {
            return array(
                'requests' => 0,
                'tokens' => 0,
                'cost' => 0,
            );
        }

        return $usage;
    }

    /**
     * Get provider-specific configuration schema
     *
     * @return array Configuration schema
     */
    public function get_config_schema() {
        return array(
            'api_key' => array(
                'type' => 'password',
                'label' => 'API Key',
                'description' => 'Not required for test provider',
                'required' => false,
            ),
            'model' => array(
                'type' => 'select',
                'label' => 'Model',
                'description' => 'Demo model (no actual API calls)',
                'options' => $this->get_available_models(),
                'default' => 'test-demo-v1',
            ),
        );
    }

    /**
     * Estimate cost per request
     *
     * @param int $input_tokens Input tokens
     * @param int $output_tokens Output tokens
     * @param string $model Model identifier
     * @return float Cost estimate (always 0 for test provider)
     */
    public function estimate_cost($input_tokens, $output_tokens, $model = null) {
        return 0.0; // Test provider is free
    }

    /**
     * Check if provider is available
     *
     * @return array Status array
     */
    public function is_available() {
        return array(
            'available' => true,
            'reason' => 'Test provider is always available',
        );
    }

    /**
     * Get fallback model
     *
     * @return string Fallback model identifier
     */
    protected function get_fallback_model() {
        return 'test-demo-v1';
    }
}
