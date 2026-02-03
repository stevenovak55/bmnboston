<?php
/**
 * Enhanced Chatbot AJAX Handlers
 *
 * Handles all AJAX requests for the enhanced chatbot system including
 * real-time data queries, conversation management, and agent handoff.
 *
 * @package MLS_Listings_Display
 * @subpackage Chatbot
 * @since 6.7.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Chatbot_Ajax_Enhanced {

    /**
     * Response engine instance
     *
     * @var MLD_Response_Engine
     */
    private $response_engine;

    /**
     * Data provider instance
     *
     * @var MLD_Unified_Data_Provider
     */
    private $data_provider;

    /**
     * Agent handoff manager
     *
     * @var MLD_Agent_Handoff
     */
    private $agent_handoff;

    /**
     * Constructor
     */
    public function __construct() {
        // Initialize components
        $this->init_components();

        // Register AJAX handlers for logged-in users
        add_action('wp_ajax_mld_chatbot_send_message', array($this, 'handle_send_message'));
        add_action('wp_ajax_mld_chatbot_query_data', array($this, 'handle_query_data'));
        add_action('wp_ajax_mld_chatbot_get_suggestions', array($this, 'handle_get_suggestions'));
        add_action('wp_ajax_mld_chatbot_request_agent', array($this, 'handle_request_agent'));
        add_action('wp_ajax_mld_chatbot_update_state', array($this, 'handle_update_state'));
        add_action('wp_ajax_mld_chatbot_search_properties', array($this, 'handle_search_properties'));
        add_action('wp_ajax_mld_chatbot_get_market_stats', array($this, 'handle_get_market_stats'));
        add_action('wp_ajax_mld_chatbot_get_property_details', array($this, 'handle_get_property_details'));
        add_action('wp_ajax_mld_chatbot_save_lead', array($this, 'handle_save_lead'));

        // Register AJAX handlers for non-logged-in users
        add_action('wp_ajax_nopriv_mld_chatbot_send_message', array($this, 'handle_send_message'));
        add_action('wp_ajax_nopriv_mld_chatbot_query_data', array($this, 'handle_query_data'));
        add_action('wp_ajax_nopriv_mld_chatbot_get_suggestions', array($this, 'handle_get_suggestions'));
        add_action('wp_ajax_nopriv_mld_chatbot_request_agent', array($this, 'handle_request_agent'));
        add_action('wp_ajax_nopriv_mld_chatbot_update_state', array($this, 'handle_update_state'));
        add_action('wp_ajax_nopriv_mld_chatbot_search_properties', array($this, 'handle_search_properties'));
        add_action('wp_ajax_nopriv_mld_chatbot_get_market_stats', array($this, 'handle_get_market_stats'));
        add_action('wp_ajax_nopriv_mld_chatbot_get_property_details', array($this, 'handle_get_property_details'));
        add_action('wp_ajax_nopriv_mld_chatbot_save_lead', array($this, 'handle_save_lead'));

        // Register script localization
        add_action('wp_enqueue_scripts', array($this, 'localize_scripts'));
    }

    /**
     * Initialize components
     */
    private function init_components() {
        // Load dependencies if needed
        $plugin_path = dirname(dirname(dirname(__FILE__)));

        $components = array(
            'class-mld-response-engine.php',
            'class-mld-unified-data-provider.php',
            'class-mld-agent-handoff.php',
            'class-mld-conversation-state.php',
            'class-mld-data-reference-mapper.php'
        );

        foreach ($components as $component) {
            $file = $plugin_path . '/includes/chatbot/' . $component;
            if (file_exists($file)) {
                require_once $file;
            }
        }

        // Initialize instances
        if (class_exists('MLD_Response_Engine')) {
            $this->response_engine = new MLD_Response_Engine();
        }

        if (class_exists('MLD_Unified_Data_Provider')) {
            $this->data_provider = new MLD_Unified_Data_Provider();
        }

        if (class_exists('MLD_Agent_Handoff')) {
            $this->agent_handoff = new MLD_Agent_Handoff();
        }
    }

    /**
     * Handle send message request
     */
    public function handle_send_message() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'mld_chatbot_nonce')) {
            wp_send_json_error('Invalid security token');
            return;
        }

        $message = sanitize_text_field($_POST['message'] ?? '');
        $session_id = sanitize_text_field($_POST['session_id'] ?? '');
        $conversation_id = intval($_POST['conversation_id'] ?? 0);
        $user_data = array();

        if (empty($message) || empty($session_id)) {
            wp_send_json_error('Missing required parameters');
            return;
        }

        // Get or create conversation
        if (!$conversation_id) {
            $conversation_id = $this->create_conversation($session_id, $user_data);
        }

        // Save user message
        $this->save_message($conversation_id, $session_id, 'user', $message);

        // Initialize conversation state manager
        $state_manager = new MLD_Conversation_State($conversation_id);

        // Process message through state manager
        $state_response = $state_manager->process_message($message);

        // Process through response engine
        $response_engine = new MLD_Response_Engine($conversation_id);
        $response = $response_engine->processQuestion($message, array(
            'session_id' => $session_id,
            'conversation_state' => $state_response['state'],
            'collected_info' => $state_manager->get_collected_info()
        ));

        // Check if agent handoff is needed
        if ($state_response['agent_notification']) {
            $agent_result = $this->agent_handoff->requestAgent(
                $conversation_id,
                $state_manager->get_collected_info(),
                'normal'
            );

            if ($agent_result['success']) {
                $response['agent_assigned'] = true;
                $response['agent_info'] = array(
                    'name' => $agent_result['agent_name'],
                    'response_time' => $agent_result['expected_response_time']
                );
            }
        }

        // Build complete response
        $final_response = array(
            'success' => true,
            'conversation_id' => $conversation_id,
            'message_id' => $this->save_message($conversation_id, $session_id, 'assistant', $response['answer'] ?: $state_response['next_prompt']),
            'response' => $response['answer'] ?: $state_response['next_prompt'],
            'source' => $response['source'],
            'confidence' => $response['confidence'],
            'state' => $state_response['state'],
            'next_prompt' => $state_response['next_prompt'],
            'collected_field' => $state_response['collected_field'],
            'suggestions' => $response['suggestions'] ?? array(),
            'data' => $response['data'] ?? array(),
            'agent_assigned' => $response['agent_assigned'] ?? false,
            'agent_info' => $response['agent_info'] ?? null
        );

        wp_send_json($final_response);
    }

    /**
     * Handle data query request
     */
    public function handle_query_data() {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'mld_chatbot_nonce')) {
            wp_send_json_error('Invalid security token');
            return;
        }

        $query_type = sanitize_text_field($_POST['query_type'] ?? '');
        $parameters = array_map('sanitize_text_field', $_POST['parameters'] ?? array());

        if (empty($query_type)) {
            wp_send_json_error('Query type required');
            return;
        }

        $result = array();

        switch ($query_type) {
            case 'property_search':
                $result = $this->data_provider->getPropertyData($parameters);
                break;

            case 'market_stats':
                $area = $parameters['area'] ?? null;
                $period = $parameters['period'] ?? 'monthly';
                $result = $this->data_provider->getMarketAnalytics($area, $period);
                break;

            case 'agent_info':
                $agent_id = $parameters['agent_id'] ?? null;
                $result = $this->data_provider->getAgentInfo($agent_id);
                break;

            case 'neighborhood_stats':
                $neighborhood = $parameters['neighborhood'] ?? '';
                $result = $this->data_provider->getNeighborhoodStats($neighborhood);
                break;

            case 'price_trends':
                $criteria = $parameters['criteria'] ?? array();
                $timeframe = $parameters['timeframe'] ?? '90d';
                $result = $this->data_provider->getPriceTrends($criteria, $timeframe);
                break;

            case 'comparables':
                if (!empty($parameters['listing_id'])) {
                    $property = $this->data_provider->getPropertyData(array(
                        'listing_id' => $parameters['listing_id']
                    ));
                    if ($property) {
                        $result = $this->data_provider->getCMAComparables($property[0], 6);
                    }
                }
                break;

            case 'quick_stat':
                $stat_type = $parameters['stat_type'] ?? '';
                $filters = $parameters['filters'] ?? array();
                $result = $this->data_provider->getQuickStat($stat_type, $filters);
                break;

            default:
                wp_send_json_error('Invalid query type');
                return;
        }

        wp_send_json_success(array(
            'query_type' => $query_type,
            'data' => $result,
            'count' => is_array($result) ? count($result) : 0
        ));
    }

    /**
     * Handle get suggestions request
     */
    public function handle_get_suggestions() {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'mld_chatbot_nonce')) {
            wp_send_json_error('Invalid security token');
            return;
        }

        $context = sanitize_text_field($_POST['context'] ?? '');
        $conversation_id = intval($_POST['conversation_id'] ?? 0);

        $suggestions = $this->get_contextual_suggestions($context, $conversation_id);

        wp_send_json_success(array(
            'suggestions' => $suggestions
        ));
    }

    /**
     * Handle agent request
     */
    public function handle_request_agent() {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'mld_chatbot_nonce')) {
            wp_send_json_error('Invalid security token');
            return;
        }

        $conversation_id = intval($_POST['conversation_id'] ?? 0);
        $urgency = sanitize_text_field($_POST['urgency'] ?? 'normal');

        if (!$conversation_id) {
            wp_send_json_error('Conversation ID required');
            return;
        }

        // Get collected information
        $state_manager = new MLD_Conversation_State($conversation_id);
        $collected_info = $state_manager->get_collected_info();

        // Request agent
        $result = $this->agent_handoff->requestAgent($conversation_id, $collected_info, $urgency);

        if ($result['success']) {
            // Mark state as agent connected
            $state_manager->mark_agent_connected();

            wp_send_json_success(array(
                'agent_assigned' => true,
                'agent_name' => $result['agent_name'],
                'expected_response_time' => $result['expected_response_time'],
                'message' => sprintf(
                    "%s will contact you within %s. Is there anything else you'd like to know while you wait?",
                    $result['agent_name'],
                    $result['expected_response_time']
                )
            ));
        } else {
            wp_send_json_error(array(
                'message' => $result['error'] ?? 'Unable to assign agent',
                'fallback' => $result['fallback'] ?? null
            ));
        }
    }

    /**
     * Handle state update request
     */
    public function handle_update_state() {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'mld_chatbot_nonce')) {
            wp_send_json_error('Invalid security token');
            return;
        }

        $conversation_id = intval($_POST['conversation_id'] ?? 0);
        $new_state = sanitize_text_field($_POST['new_state'] ?? '');
        $collected_data = array_map('sanitize_text_field', $_POST['collected_data'] ?? array());

        if (!$conversation_id) {
            wp_send_json_error('Conversation ID required');
            return;
        }

        // Update state
        $state_manager = new MLD_Conversation_State($conversation_id);

        if ($new_state) {
            $state_manager->transition_to($new_state);
        }

        // Save collected data
        if (!empty($collected_data)) {
            global $wpdb;
            $wpdb->update(
                $wpdb->prefix . 'mld_chatbot_conversations',
                array(
                    'collected_info' => json_encode($collected_data),
                    'updated_at' => current_time('mysql')
                ),
                array('id' => $conversation_id),
                array('%s', '%s'),
                array('%d')
            );
        }

        wp_send_json_success(array(
            'current_state' => $state_manager->get_current_state(),
            'updated' => true
        ));
    }

    /**
     * Handle property search request
     */
    public function handle_search_properties() {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'mld_chatbot_nonce')) {
            wp_send_json_error('Invalid security token');
            return;
        }

        $criteria = array();

        // Build search criteria from POST data
        if (!empty($_POST['city'])) {
            $criteria['city'] = sanitize_text_field($_POST['city']);
        }
        if (!empty($_POST['min_price'])) {
            $criteria['min_price'] = intval($_POST['min_price']);
        }
        if (!empty($_POST['max_price'])) {
            $criteria['max_price'] = intval($_POST['max_price']);
        }
        if (!empty($_POST['min_bedrooms'])) {
            $criteria['min_bedrooms'] = intval($_POST['min_bedrooms']);
        }
        if (!empty($_POST['property_type'])) {
            $criteria['property_type'] = sanitize_text_field($_POST['property_type']);
        }
        if (!empty($_POST['limit'])) {
            $criteria['limit'] = intval($_POST['limit']);
        }

        // Search properties
        $properties = $this->data_provider->getPropertyData($criteria);

        // Format results for display
        $formatted = array();
        foreach ($properties as $property) {
            $formatted[] = array(
                'listing_id' => $property['listing_id'],
                'address' => $property['street_address'],
                'city' => $property['city'],
                'price' => $property['list_price'],
                'bedrooms' => $property['bedrooms_total'],
                'bathrooms' => $property['bathrooms_total'],
                'sqft' => $property['living_area'],
                'property_type' => $property['property_type'],
                'photo' => $this->get_property_photo($property['listing_id']),
                'url' => $this->get_property_url($property['listing_id'])
            );
        }

        wp_send_json_success(array(
            'properties' => $formatted,
            'total' => count($formatted),
            'criteria' => $criteria
        ));
    }

    /**
     * Handle market stats request
     */
    public function handle_get_market_stats() {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'mld_chatbot_nonce')) {
            wp_send_json_error('Invalid security token');
            return;
        }

        $area = sanitize_text_field($_POST['area'] ?? '');
        $period = sanitize_text_field($_POST['period'] ?? 'monthly');

        $stats = $this->data_provider->getMarketAnalytics($area, $period);

        wp_send_json_success(array(
            'stats' => $stats,
            'area' => $area,
            'period' => $period
        ));
    }

    /**
     * Handle property details request
     */
    public function handle_get_property_details() {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'mld_chatbot_nonce')) {
            wp_send_json_error('Invalid security token');
            return;
        }

        $listing_id = sanitize_text_field($_POST['listing_id'] ?? '');

        if (empty($listing_id)) {
            wp_send_json_error('Listing ID required');
            return;
        }

        // Get property with all details
        $property = $this->data_provider->getPropertyData(array(
            'listing_id' => $listing_id,
            'include_details' => true
        ));

        if (empty($property)) {
            wp_send_json_error('Property not found');
            return;
        }

        $property_data = $property[0];

        // Get additional information
        $schools = $this->data_provider->getPropertySchools($listing_id);
        $comparables = $this->data_provider->getCMAComparables($property_data, 3);

        wp_send_json_success(array(
            'property' => $property_data,
            'schools' => $schools,
            'comparables' => $comparables
        ));
    }

    /**
     * Handle lead save request
     */
    public function handle_save_lead() {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'mld_chatbot_nonce')) {
            wp_send_json_error('Invalid security token');
            return;
        }

        $conversation_id = intval($_POST['conversation_id'] ?? 0);
        $lead_data = array(
            'name' => sanitize_text_field($_POST['name'] ?? ''),
            'email' => sanitize_email($_POST['email'] ?? ''),
            'phone' => sanitize_text_field($_POST['phone'] ?? ''),
            'message' => sanitize_textarea_field($_POST['message'] ?? ''),
            'property_interest' => sanitize_text_field($_POST['property_interest'] ?? ''),
            'source' => 'chatbot'
        );

        if (empty($lead_data['email']) && empty($lead_data['phone'])) {
            wp_send_json_error('Contact information required');
            return;
        }

        // Save lead to database
        global $wpdb;
        $table = $wpdb->prefix . 'mld_chatbot_leads';

        $result = $wpdb->insert(
            $table,
            array(
                'conversation_id' => $conversation_id,
                'lead_data' => json_encode($lead_data),
                'created_at' => current_time('mysql')
            ),
            array('%d', '%s', '%s')
        );

        if ($result) {
            // Trigger lead notification
            do_action('mld_lead_received', $conversation_id, $lead_data);

            wp_send_json_success(array(
                'lead_id' => $wpdb->insert_id,
                'message' => 'Thank you! We have received your information and will contact you shortly.'
            ));
        } else {
            wp_send_json_error('Failed to save lead information');
        }
    }

    /**
     * Localize scripts for AJAX
     */
    public function localize_scripts() {
        wp_localize_script('mld-chatbot', 'mld_chatbot_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('mld_chatbot_nonce'),
            'session_id' => $this->get_session_id(),
            'endpoints' => array(
                'send_message' => 'mld_chatbot_send_message',
                'query_data' => 'mld_chatbot_query_data',
                'get_suggestions' => 'mld_chatbot_get_suggestions',
                'request_agent' => 'mld_chatbot_request_agent',
                'update_state' => 'mld_chatbot_update_state',
                'search_properties' => 'mld_chatbot_search_properties',
                'get_market_stats' => 'mld_chatbot_get_market_stats',
                'get_property_details' => 'mld_chatbot_get_property_details',
                'save_lead' => 'mld_chatbot_save_lead'
            ),
            'messages' => array(
                'typing' => 'Typing...',
                'error' => 'Sorry, something went wrong. Please try again.',
                'offline' => 'Connection lost. Please check your internet.',
                'agent_requested' => 'Connecting you with an agent...'
            )
        ));
    }

    /**
     * Create new conversation
     *
     * @param string $session_id Session ID
     * @param array $user_data User data
     * @return int Conversation ID
     */
    private function create_conversation($session_id, $user_data) {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_chatbot_conversations';

        $wpdb->insert(
            $table,
            array(
                'session_id' => $session_id,
                'user_email' => $user_data['email'] ?? null,
                'user_name' => $user_data['name'] ?? null,
                'conversation_state' => 'initial_greeting',
                'is_active' => 1,
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ),
            array('%s', '%s', '%s', '%s', '%d', '%s', '%s')
        );

        return $wpdb->insert_id;
    }

    /**
     * Save message to database
     *
     * @param int $conversation_id Conversation ID
     * @param string $session_id Session ID
     * @param string $role Role (user/assistant)
     * @param string $message Message text
     * @return int Message ID
     */
    private function save_message($conversation_id, $session_id, $role, $message) {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_chatbot_messages';

        $wpdb->insert(
            $table,
            array(
                'conversation_id' => $conversation_id,
                'session_id' => $session_id,
                'role' => $role,
                'message' => $message,
                'created_at' => current_time('mysql')
            ),
            array('%d', '%s', '%s', '%s', '%s')
        );

        // Update conversation last activity
        $wpdb->update(
            $wpdb->prefix . 'mld_chatbot_conversations',
            array('updated_at' => current_time('mysql')),
            array('id' => $conversation_id),
            array('%s'),
            array('%d')
        );

        return $wpdb->insert_id;
    }

    /**
     * Get contextual suggestions
     *
     * @param string $context Current context
     * @param int $conversation_id Conversation ID
     * @return array Suggestions
     */
    private function get_contextual_suggestions($context, $conversation_id) {
        $suggestions = array();

        // Get conversation state
        if ($conversation_id) {
            $state_manager = new MLD_Conversation_State($conversation_id);
            $state = $state_manager->get_current_state();

            switch ($state) {
                case 'initial_greeting':
                case 'answering_question':
                    $suggestions = array(
                        'Show me homes for sale',
                        'What\'s the average price in this area?',
                        'I\'m looking for a 3-bedroom house',
                        'Schedule a property viewing',
                        'Connect me with an agent'
                    );
                    break;

                case 'waiting_for_agent':
                    $suggestions = array(
                        'Tell me more about the neighborhood',
                        'What are the schools like?',
                        'Show me similar properties',
                        'What\'s included in the price?',
                        'How long has it been on the market?'
                    );
                    break;

                default:
                    $suggestions = array(
                        'Search for properties',
                        'Get market statistics',
                        'Contact an agent',
                        'Schedule a viewing'
                    );
            }
        } else {
            // Default suggestions
            $suggestions = array(
                'I\'m looking to buy a home',
                'Show me properties under $500k',
                'What areas do you cover?',
                'I need help finding a home',
                'Connect me with an agent'
            );
        }

        return $suggestions;
    }

    /**
     * Get property photo URL
     *
     * @param string $listing_id Listing ID
     * @return string Photo URL
     */
    private function get_property_photo($listing_id) {
        $media = $this->data_provider->getPropertyMedia($listing_id);

        if (!empty($media)) {
            return $media[0]['media_url'];
        }

        return '';
    }

    /**
     * Get property URL
     *
     * @param string $listing_id Listing ID
     * @return string Property URL
     */
    private function get_property_url($listing_id) {
        // Generate property detail page URL
        return home_url('/property/' . $listing_id);
    }

    /**
     * Get or generate session ID
     *
     * @return string Session ID
     */
    private function get_session_id() {
        if (!isset($_COOKIE['mld_chatbot_session'])) {
            $session_id = wp_generate_uuid4();
            setcookie('mld_chatbot_session', $session_id, time() + (86400 * 30), '/');
            return $session_id;
        }

        return $_COOKIE['mld_chatbot_session'];
    }
}