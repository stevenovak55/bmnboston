<?php
/**
 * Conversation State Manager
 *
 * Manages conversation flow states including greeting, info collection,
 * and agent handoff. Provides natural conversation transitions.
 *
 * @package MLS_Listings_Display
 * @subpackage Chatbot
 * @since 6.7.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Conversation_State {

    /**
     * Conversation states
     */
    const STATE_INITIAL = 'initial_greeting';
    const STATE_ANSWERING = 'answering_question';
    const STATE_COLLECTING_NAME = 'collecting_name';
    const STATE_COLLECTING_PHONE = 'collecting_phone';
    const STATE_COLLECTING_EMAIL = 'collecting_email';
    const STATE_COLLECTING_PROPERTY = 'collecting_property_status';
    const STATE_WAITING_AGENT = 'waiting_for_agent';
    const STATE_AGENT_CONNECTED = 'agent_connected';

    /**
     * State transition map
     *
     * @var array
     */
    private $state_transitions = array();

    /**
     * Info collection fields
     *
     * @var array
     */
    private $collection_fields = array(
        'name' => null,
        'phone' => null,
        'email' => null,
        'has_property_to_sell' => null,
        'property_interest' => null,
        'timeline' => null,
        'preferred_contact' => null
    );

    /**
     * Current conversation ID
     *
     * @var int
     */
    private $conversation_id;

    /**
     * Current state
     *
     * @var string
     */
    private $current_state;

    /**
     * Collected information
     *
     * @var array
     */
    private $collected_info = array();

    /**
     * Constructor
     *
     * @param int $conversation_id Conversation ID
     */
    public function __construct($conversation_id = null) {
        $this->conversation_id = $conversation_id;
        $this->initialize_transitions();

        if ($conversation_id) {
            $this->load_conversation_state();
        } else {
            $this->current_state = self::STATE_INITIAL;
        }
    }

    /**
     * Initialize state transitions
     */
    private function initialize_transitions() {
        $this->state_transitions = array(
            self::STATE_INITIAL => array(
                self::STATE_ANSWERING,
                self::STATE_COLLECTING_NAME
            ),
            self::STATE_ANSWERING => array(
                self::STATE_COLLECTING_NAME,
                self::STATE_WAITING_AGENT,
                self::STATE_ANSWERING
            ),
            self::STATE_COLLECTING_NAME => array(
                self::STATE_COLLECTING_PHONE,
                self::STATE_ANSWERING
            ),
            self::STATE_COLLECTING_PHONE => array(
                self::STATE_COLLECTING_EMAIL,
                self::STATE_ANSWERING
            ),
            self::STATE_COLLECTING_EMAIL => array(
                self::STATE_COLLECTING_PROPERTY,
                self::STATE_ANSWERING
            ),
            self::STATE_COLLECTING_PROPERTY => array(
                self::STATE_WAITING_AGENT,
                self::STATE_ANSWERING
            ),
            self::STATE_WAITING_AGENT => array(
                self::STATE_AGENT_CONNECTED,
                self::STATE_ANSWERING
            ),
            self::STATE_AGENT_CONNECTED => array(
                // Terminal state
            )
        );
    }

    /**
     * Load conversation state from database
     */
    private function load_conversation_state() {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_chatbot_conversations';
        $conversation = $wpdb->get_row($wpdb->prepare(
            "SELECT conversation_state, collected_info FROM {$table} WHERE id = %d",
            $this->conversation_id
        ), ARRAY_A);

        if ($conversation) {
            $this->current_state = $conversation['conversation_state'] ?: self::STATE_INITIAL;
            $this->collected_info = json_decode($conversation['collected_info'], true) ?: array();
        } else {
            $this->current_state = self::STATE_INITIAL;
        }
    }

    /**
     * Save conversation state to database
     */
    private function save_conversation_state() {
        global $wpdb;

        if (!$this->conversation_id) {
            return false;
        }

        $table = $wpdb->prefix . 'mld_chatbot_conversations';
        return $wpdb->update(
            $table,
            array(
                'conversation_state' => $this->current_state,
                'collected_info' => json_encode($this->collected_info),
                'updated_at' => current_time('mysql')
            ),
            array('id' => $this->conversation_id),
            array('%s', '%s', '%s'),
            array('%d')
        );
    }

    /**
     * Get current state
     *
     * @return string Current state
     */
    public function get_current_state() {
        return $this->current_state;
    }

    /**
     * Transition to new state
     *
     * @param string $new_state New state
     * @return bool Success
     */
    public function transition_to($new_state) {
        // Check if transition is valid
        if (!$this->is_valid_transition($new_state)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("[MLD Conversation State] Invalid transition from {$this->current_state} to {$new_state}");
            }
            return false;
        }

        $old_state = $this->current_state;
        $this->current_state = $new_state;

        // Save to database
        $this->save_conversation_state();

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("[MLD Conversation State] Transitioned from {$old_state} to {$new_state}");
        }

        return true;
    }

    /**
     * Check if state transition is valid
     *
     * @param string $new_state Target state
     * @return bool Is valid
     */
    private function is_valid_transition($new_state) {
        if (!isset($this->state_transitions[$this->current_state])) {
            return false;
        }

        return in_array($new_state, $this->state_transitions[$this->current_state]);
    }

    /**
     * Process user message and determine state action
     *
     * @param string $message User message
     * @param string $context Message context
     * @return array Response data
     */
    public function process_message($message, $context = '') {
        $response = array(
            'state' => $this->current_state,
            'action' => 'continue',
            'collected_field' => null,
            'next_prompt' => null,
            'agent_notification' => false
        );

        switch ($this->current_state) {
            case self::STATE_INITIAL:
                $response['action'] = 'greet_and_answer';
                $response['next_prompt'] = $this->get_initial_greeting();
                $this->transition_to(self::STATE_ANSWERING);
                break;

            case self::STATE_ANSWERING:
                // Check if we should start collecting info
                if ($this->should_collect_info($message, $context)) {
                    $this->transition_to(self::STATE_COLLECTING_NAME);
                    $response['action'] = 'start_collection';
                    $response['next_prompt'] = $this->get_collection_prompt('name');
                }
                break;

            case self::STATE_COLLECTING_NAME:
                $this->collected_info['name'] = $this->extract_name($message);
                $response['collected_field'] = 'name';
                $this->transition_to(self::STATE_COLLECTING_PHONE);
                $response['next_prompt'] = $this->get_collection_prompt('phone');
                break;

            case self::STATE_COLLECTING_PHONE:
                $this->collected_info['phone'] = $this->extract_phone($message);
                $response['collected_field'] = 'phone';
                $this->transition_to(self::STATE_COLLECTING_EMAIL);
                $response['next_prompt'] = $this->get_collection_prompt('email');
                break;

            case self::STATE_COLLECTING_EMAIL:
                $this->collected_info['email'] = $this->extract_email($message);
                $response['collected_field'] = 'email';
                $this->transition_to(self::STATE_COLLECTING_PROPERTY);
                $response['next_prompt'] = $this->get_collection_prompt('property');
                break;

            case self::STATE_COLLECTING_PROPERTY:
                $this->collected_info['has_property_to_sell'] = $this->extract_yes_no($message);
                $response['collected_field'] = 'has_property_to_sell';
                $this->transition_to(self::STATE_WAITING_AGENT);
                $response['action'] = 'notify_agent';
                $response['agent_notification'] = true;
                $response['next_prompt'] = $this->get_agent_notification_message();
                break;

            case self::STATE_WAITING_AGENT:
                // Keep user engaged while waiting
                $response['action'] = 'keep_engaged';
                $response['next_prompt'] = $this->get_waiting_message();
                break;

            case self::STATE_AGENT_CONNECTED:
                $response['action'] = 'agent_connected';
                break;
        }

        // Save collected info if any
        if ($response['collected_field']) {
            $this->save_conversation_state();
        }

        return $response;
    }

    /**
     * Check if we should start collecting information
     *
     * @param string $message User message
     * @param string $context Conversation context
     * @return bool Should collect
     */
    private function should_collect_info($message, $context) {
        // Triggers for starting info collection
        $triggers = array(
            'interested',
            'more information',
            'contact',
            'schedule',
            'tour',
            'showing',
            'visit',
            'see the property',
            'price',
            'details about',
            'tell me more'
        );

        $message_lower = strtolower($message);
        foreach ($triggers as $trigger) {
            if (strpos($message_lower, $trigger) !== false) {
                return true;
            }
        }

        // Also check if context indicates property interest
        if (strpos($context, 'property_viewed') !== false) {
            return true;
        }

        return false;
    }

    /**
     * Get initial greeting message
     *
     * @return string Greeting
     */
    private function get_initial_greeting() {
        $greetings = array(
            "Hello! I'm Sarah, your AI real estate assistant. I'm here to help you find your perfect home and answer any questions you may have. How can I assist you today?",
            "Hi there! I'm Sarah, your virtual real estate assistant. Whether you're looking to buy, sell, or just exploring the market, I'm here to help. What brings you here today?",
            "Welcome! I'm Sarah, your AI assistant. I can help you search for properties, provide market insights, and connect you with our expert agents. What can I help you with?"
        );

        return $greetings[array_rand($greetings)];
    }

    /**
     * Get collection prompt for specific field
     *
     * @param string $field Field to collect
     * @return string Prompt
     */
    private function get_collection_prompt($field) {
        $prompts = array(
            'name' => array(
                "I'll be happy to help with that! I'll also connect you with one of our expert agents who can provide personalized assistance. May I have your name?",
                "Great! I'll make sure our agent has all the details. What's your name?",
                "Perfect! Let me get you connected with an agent who can help. May I ask your name?"
            ),
            'phone' => array(
                "Thanks, {name}! What's the best phone number for our agent to reach you?",
                "Nice to meet you, {name}! What number should our agent call you at?",
                "Thank you, {name}! What's your preferred phone number for our agent to contact you?"
            ),
            'email' => array(
                "Got it! And what email should we send property details to?",
                "Perfect! What's your email address for sending listing information?",
                "Thanks! Where should we email property information and updates?"
            ),
            'property' => array(
                "One more thing - do you currently have a property you're looking to sell?",
                "Quick question - are you also selling a home, or just looking to buy?",
                "Almost done! Do you have a home to sell as part of your move?"
            )
        );

        $field_prompts = $prompts[$field] ?? array("Could you provide that information?");
        $prompt = $field_prompts[array_rand($field_prompts)];

        // Replace placeholders
        if (!empty($this->collected_info['name'])) {
            $prompt = str_replace('{name}', $this->collected_info['name'], $prompt);
        }

        return $prompt;
    }

    /**
     * Get agent notification message
     *
     * @return string Message
     */
    private function get_agent_notification_message() {
        $agent_name = $this->get_assigned_agent_name();
        $wait_time = $this->get_estimated_wait_time();

        $messages = array(
            "Perfect! I've notified {agent} about your interest. They'll contact you at {phone} within {time}. While we wait, feel free to ask me any questions about the property or neighborhood!",
            "All set, {name}! {agent} will be calling you at {phone} shortly (usually within {time}). In the meantime, I can help you with property details, neighborhood information, or market insights.",
            "Great! I've sent your information to {agent}, one of our top agents. They'll reach out to you at {phone} within {time}. Is there anything specific you'd like to know about the property while we wait?"
        );

        $message = $messages[array_rand($messages)];

        // Replace placeholders
        $replacements = array(
            '{name}' => $this->collected_info['name'] ?? 'there',
            '{phone}' => $this->collected_info['phone'] ?? 'your phone',
            '{agent}' => $agent_name,
            '{time}' => $wait_time
        );

        foreach ($replacements as $placeholder => $value) {
            $message = str_replace($placeholder, $value, $message);
        }

        return $message;
    }

    /**
     * Get waiting message to keep user engaged
     *
     * @return string Message
     */
    private function get_waiting_message() {
        $messages = array(
            "While we're waiting for the agent, would you like to know about similar properties in the area?",
            "I can tell you more about the neighborhood's schools, shopping, and amenities. What interests you most?",
            "Would you like to see the latest market trends for this area while we wait?",
            "Is there anything specific about the property or area you'd like to know more about?",
            "I have information about recent sales in the neighborhood if you're interested in comparables."
        );

        return $messages[array_rand($messages)];
    }

    /**
     * Extract name from message
     *
     * @param string $message User message
     * @return string Name
     */
    private function extract_name($message) {
        // Simple extraction - can be enhanced with NLP
        $message = trim($message);

        // Remove common prefixes
        $prefixes = array("I'm ", "I am ", "My name is ", "It's ", "This is ", "Call me ");
        foreach ($prefixes as $prefix) {
            if (stripos($message, $prefix) === 0) {
                $message = substr($message, strlen($prefix));
                break;
            }
        }

        // Clean up
        $message = preg_replace('/[^a-zA-Z\s\'-]/', '', $message);
        $message = trim($message);

        // Capitalize properly
        return ucwords(strtolower($message));
    }

    /**
     * Extract phone number from message
     *
     * @param string $message User message
     * @return string Phone
     */
    private function extract_phone($message) {
        // Remove all non-numeric except for common separators
        $phone = preg_replace('/[^0-9\-\(\)\.\s\+]/', '', $message);

        // Remove spaces and format
        $phone = preg_replace('/[\s\-\(\)\.]/', '', $phone);

        // Ensure it's a valid length (10 digits for US)
        if (strlen($phone) == 10) {
            return sprintf('(%s) %s-%s',
                substr($phone, 0, 3),
                substr($phone, 3, 3),
                substr($phone, 6, 4)
            );
        } elseif (strlen($phone) == 11 && $phone[0] == '1') {
            // Remove country code
            $phone = substr($phone, 1);
            return sprintf('(%s) %s-%s',
                substr($phone, 0, 3),
                substr($phone, 3, 3),
                substr($phone, 6, 4)
            );
        }

        return $phone;
    }

    /**
     * Extract email from message
     *
     * @param string $message User message
     * @return string Email
     */
    private function extract_email($message) {
        // Look for email pattern
        $pattern = '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/';
        $matches = array();

        if (preg_match($pattern, $message, $matches)) {
            return strtolower($matches[0]);
        }

        // If no @ symbol, might be a simple format
        $message = trim(strtolower($message));
        $message = str_replace(' ', '', $message);

        // Add common domains if missing
        if (strpos($message, '@') === false && !empty($message)) {
            // Check if they might have meant to include a domain
            if (strpos($message, 'gmail') !== false ||
                strpos($message, 'yahoo') !== false ||
                strpos($message, 'hotmail') !== false ||
                strpos($message, 'outlook') !== false) {
                return $message;
            }
        }

        return $message;
    }

    /**
     * Extract yes/no answer
     *
     * @param string $message User message
     * @return bool|null
     */
    private function extract_yes_no($message) {
        $message = strtolower(trim($message));

        $yes_patterns = array('yes', 'yeah', 'yep', 'sure', 'definitely', 'absolutely', 'i do', 'i am', 'correct', 'selling');
        $no_patterns = array('no', 'nope', 'not', "don't", 'negative', 'just looking', 'just buying', 'only buying');

        foreach ($yes_patterns as $pattern) {
            if (strpos($message, $pattern) !== false) {
                return true;
            }
        }

        foreach ($no_patterns as $pattern) {
            if (strpos($message, $pattern) !== false) {
                return false;
            }
        }

        return null; // Unclear
    }

    /**
     * Get assigned agent name
     *
     * Retrieves the agent name using a fallback chain:
     * 1. Primary agent from Business Settings (mld_agent_name)
     * 2. Default agent from BME (mld_default_agent_id)
     * 3. Any available agent from BME agents table
     * 4. Business name as fallback
     * 5. "Our Team" as final fallback
     *
     * @return string Agent name
     * @since 6.9.6 - Replaced placeholder with real agent data
     */
    private function get_assigned_agent_name() {
        // 1. Check Business Settings primary agent name
        $primary_agent = get_option('mld_agent_name', '');
        if (!empty($primary_agent)) {
            return $primary_agent;
        }

        // 2. Check for default agent ID and fetch from BME
        $default_agent_id = get_option('mld_default_agent_id', '');
        if (!empty($default_agent_id)) {
            global $wpdb;
            $agent = $wpdb->get_row($wpdb->prepare(
                "SELECT agent_full_name, agent_first_name, agent_last_name
                 FROM {$wpdb->prefix}bme_agents
                 WHERE agent_mls_id = %s",
                $default_agent_id
            ), ARRAY_A);

            if ($agent) {
                // Prefer full name, fall back to first + last
                if (!empty($agent['agent_full_name'])) {
                    return $agent['agent_full_name'];
                }
                if (!empty($agent['agent_first_name'])) {
                    return trim($agent['agent_first_name'] . ' ' . ($agent['agent_last_name'] ?? ''));
                }
            }
        }

        // 3. Get any available agent from BME with active listings
        global $wpdb;
        $agent = $wpdb->get_row(
            "SELECT a.agent_full_name, a.agent_first_name, a.agent_last_name
             FROM {$wpdb->prefix}bme_agents a
             WHERE a.agent_full_name IS NOT NULL
               AND a.agent_full_name != ''
             ORDER BY a.last_updated DESC
             LIMIT 1",
            ARRAY_A
        );

        if ($agent && !empty($agent['agent_full_name'])) {
            return $agent['agent_full_name'];
        }

        // 4. Fall back to business name
        $business_name = get_bloginfo('name');
        if (!empty($business_name)) {
            return $business_name;
        }

        // 5. Final fallback
        return 'Our Team';
    }

    /**
     * Get estimated wait time
     *
     * @return string Wait time
     */
    private function get_estimated_wait_time() {
        $hour = date('G');

        // Business hours (9 AM - 6 PM)
        if ($hour >= 9 && $hour < 18) {
            return '15 minutes';
        }
        // Evening (6 PM - 9 PM)
        elseif ($hour >= 18 && $hour < 21) {
            return '30 minutes';
        }
        // Off hours
        else {
            return 'the next business day';
        }
    }

    /**
     * Get collected information
     *
     * @return array Collected info
     */
    public function get_collected_info() {
        return $this->collected_info;
    }

    /**
     * Check if minimum info is collected
     *
     * @return bool Has minimum
     */
    public function has_minimum_info() {
        return !empty($this->collected_info['name']) &&
               (!empty($this->collected_info['phone']) || !empty($this->collected_info['email']));
    }

    /**
     * Mark agent as connected
     *
     * @return bool Success
     */
    public function mark_agent_connected() {
        $this->transition_to(self::STATE_AGENT_CONNECTED);

        global $wpdb;
        $table = $wpdb->prefix . 'mld_chatbot_conversations';

        return $wpdb->update(
            $table,
            array(
                'agent_connected_at' => current_time('mysql'),
                'conversation_state' => self::STATE_AGENT_CONNECTED
            ),
            array('id' => $this->conversation_id),
            array('%s', '%s'),
            array('%d')
        );
    }

    /**
     * Reset conversation state
     */
    public function reset() {
        $this->current_state = self::STATE_INITIAL;
        $this->collected_info = array();
        $this->save_conversation_state();
    }
}