<?php
/**
 * Chatbot AJAX Handlers
 *
 * Handles all AJAX requests from the frontend chat widget
 *
 * @package MLS_Listings_Display
 * @subpackage Chatbot
 * @since 6.6.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Chatbot_AJAX {

    /**
     * Chatbot engine instance
     *
     * @var MLD_Chatbot_Engine
     */
    private $engine;

    /**
     * Constructor
     */
    public function __construct() {
        // Load chatbot engine
        require_once MLD_PLUGIN_PATH . 'includes/chatbot/class-mld-chatbot-engine.php';
        $this->engine = new MLD_Chatbot_Engine();

        // Register AJAX handlers (both logged in and logged out)
        add_action('wp_ajax_mld_chat_send_message', array($this, 'handle_send_message'));
        add_action('wp_ajax_nopriv_mld_chat_send_message', array($this, 'handle_send_message'));

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[MLD Chatbot AJAX] Handlers registered for mld_chat_send_message');
        }

        add_action('wp_ajax_mld_chat_get_history', array($this, 'handle_get_history'));
        add_action('wp_ajax_nopriv_mld_chat_get_history', array($this, 'handle_get_history'));

        add_action('wp_ajax_mld_chat_end_conversation', array($this, 'handle_end_conversation'));
        add_action('wp_ajax_nopriv_mld_chat_end_conversation', array($this, 'handle_end_conversation'));

        add_action('wp_ajax_mld_chat_update_user_info', array($this, 'handle_update_user_info'));
        add_action('wp_ajax_nopriv_mld_chat_update_user_info', array($this, 'handle_update_user_info'));
    }

    /**
     * Handle send message AJAX request
     */
    public function handle_send_message() {
        // Verify nonce
        check_ajax_referer('mld_chatbot_nonce', 'nonce');

        // Get parameters
        $message = isset($_POST['message']) ? sanitize_textarea_field($_POST['message']) : '';
        $session_id = isset($_POST['session_id']) ? sanitize_text_field($_POST['session_id']) : '';
        $user_data = isset($_POST['user_data']) ? $this->sanitize_user_data($_POST['user_data']) : array();

        // Validate
        if (empty($message)) {
            wp_send_json_error(array('message' => 'Message is required'));
        }

        if (empty($session_id)) {
            wp_send_json_error(array('message' => 'Session ID is required'));
        }

        // Check if chatbot is enabled
        if (!$this->is_chatbot_enabled()) {
            wp_send_json_error(array('message' => 'Chatbot is currently disabled'));
        }

        // Process message through engine
        $response = $this->engine->process_message($message, $session_id, $user_data);

        if ($response['success']) {
            wp_send_json_success($response);
        } else {
            wp_send_json_error($response);
        }
    }

    /**
     * Handle get conversation history AJAX request
     */
    public function handle_get_history() {
        check_ajax_referer('mld_chatbot_nonce', 'nonce');

        $session_id = isset($_POST['session_id']) ? sanitize_text_field($_POST['session_id']) : '';

        if (empty($session_id)) {
            wp_send_json_error(array('message' => 'Session ID is required'));
        }

        global $wpdb;

        // Get conversation for this session
        $conversation = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}mld_chat_conversations
             WHERE session_id = %s
             ORDER BY id DESC
             LIMIT 1",
            $session_id
        ), ARRAY_A);

        if (!$conversation) {
            wp_send_json_success(array('messages' => array()));
        }

        // Get messages
        $messages = $wpdb->get_results($wpdb->prepare(
            "SELECT sender_type, message_text, is_fallback, created_at
             FROM {$wpdb->prefix}mld_chat_messages
             WHERE conversation_id = %d
             ORDER BY id ASC",
            $conversation['id']
        ), ARRAY_A);

        wp_send_json_success(array(
            'conversation_id' => $conversation['id'],
            'messages' => $messages,
        ));
    }

    /**
     * Handle end conversation AJAX request
     *
     * Triggered when user closes chat window
     */
    public function handle_end_conversation() {
        check_ajax_referer('mld_chatbot_nonce', 'nonce');

        $session_id = isset($_POST['session_id']) ? sanitize_text_field($_POST['session_id']) : '';

        if (empty($session_id)) {
            wp_send_json_error(array('message' => 'Session ID is required'));
        }

        global $wpdb;

        // Update conversation status
        $wpdb->update(
            $wpdb->prefix . 'mld_chat_conversations',
            array(
                'conversation_status' => 'closed',
                'ended_at' => current_time('mysql'),
            ),
            array('session_id' => $session_id),
            array('%s', '%s'),
            array('%s')
        );

        // Trigger summary email generation
        do_action('mld_chat_conversation_ended', $session_id);

        wp_send_json_success(array('message' => 'Conversation ended'));
    }

    /**
     * Handle update user info AJAX request
     *
     * Updates user information during conversation (email capture)
     */
    public function handle_update_user_info() {
        check_ajax_referer('mld_chatbot_nonce', 'nonce');

        $session_id = isset($_POST['session_id']) ? sanitize_text_field($_POST['session_id']) : '';
        $user_data = isset($_POST['user_data']) ? $this->sanitize_user_data($_POST['user_data']) : array();

        if (empty($session_id)) {
            wp_send_json_error(array('message' => 'Session ID is required'));
        }

        if (empty($user_data)) {
            wp_send_json_error(array('message' => 'User data is required'));
        }

        global $wpdb;

        // Update conversation with user info
        $update_data = array();
        if (isset($user_data['email'])) {
            $update_data['user_email'] = $user_data['email'];
        }
        if (isset($user_data['name'])) {
            $update_data['user_name'] = $user_data['name'];
        }
        if (isset($user_data['phone'])) {
            $update_data['user_phone'] = $user_data['phone'];
        }

        if (!empty($update_data)) {
            $wpdb->update(
                $wpdb->prefix . 'mld_chat_conversations',
                $update_data,
                array('session_id' => $session_id),
                null,
                array('%s')
            );
        }

        wp_send_json_success(array('message' => 'User info updated'));
    }

    /**
     * Check if chatbot is enabled
     *
     * @return bool
     */
    private function is_chatbot_enabled() {
        global $wpdb;

        $enabled = $wpdb->get_var($wpdb->prepare(
            "SELECT setting_value FROM {$wpdb->prefix}mld_chat_settings
             WHERE setting_key = %s",
            'chatbot_enabled'
        ));

        return $enabled === '1';
    }

    /**
     * Sanitize user data array
     *
     * @param array $user_data Raw user data
     * @return array Sanitized data
     */
    private function sanitize_user_data($user_data) {
        // Handle JSON string from frontend (v6.27.0+)
        if (is_string($user_data)) {
            $user_data = json_decode(stripslashes($user_data), true);
        }

        if (!is_array($user_data)) {
            return array();
        }

        $sanitized = array();

        // Lead capture data
        if (isset($user_data['email'])) {
            $email = sanitize_email($user_data['email']);
            if (is_email($email)) {
                $sanitized['email'] = $email;
            }
        }

        if (isset($user_data['name'])) {
            $sanitized['name'] = sanitize_text_field($user_data['name']);
        }

        if (isset($user_data['phone'])) {
            // Sanitize phone - keep only digits, spaces, dashes, parentheses, plus
            $phone = preg_replace('/[^0-9\s\-\(\)\+]/', '', $user_data['phone']);
            $sanitized['phone'] = sanitize_text_field($phone);
        }

        // Page/browsing context data (v6.27.9)
        if (isset($user_data['page_url'])) {
            $sanitized['page_url'] = esc_url_raw($user_data['page_url']);
        }

        if (isset($user_data['referrer_url'])) {
            $sanitized['referrer_url'] = esc_url_raw($user_data['referrer_url']);
        }

        if (isset($user_data['device_type'])) {
            $sanitized['device_type'] = sanitize_text_field($user_data['device_type']);
        }

        if (isset($user_data['browser'])) {
            $sanitized['browser'] = sanitize_text_field($user_data['browser']);
        }

        // Page context for AI awareness (v6.27.9) - passed to engine for processing
        if (isset($user_data['page_context']) && is_array($user_data['page_context'])) {
            $sanitized['page_context'] = $this->sanitize_page_context($user_data['page_context']);
        }

        return $sanitized;
    }

    /**
     * Sanitize page context data (v6.27.9)
     *
     * @param array $page_context Raw page context
     * @return array Sanitized page context
     */
    private function sanitize_page_context($page_context) {
        $sanitized = array(
            'page_type' => sanitize_text_field($page_context['page_type'] ?? 'unknown'),
            'page_title' => sanitize_text_field($page_context['page_title'] ?? ''),
            'page_url' => esc_url_raw($page_context['page_url'] ?? ''),
        );

        // Pass through nested data - engine will further sanitize
        if (isset($page_context['property_data']) && is_array($page_context['property_data'])) {
            $sanitized['property_data'] = $this->sanitize_array_recursive($page_context['property_data']);
        }

        if (isset($page_context['calculator_info']) && is_array($page_context['calculator_info'])) {
            $sanitized['calculator_info'] = $this->sanitize_array_recursive($page_context['calculator_info']);
        }

        if (isset($page_context['cma_info']) && is_array($page_context['cma_info'])) {
            $sanitized['cma_info'] = $this->sanitize_array_recursive($page_context['cma_info']);
        }

        if (isset($page_context['search_info']) && is_array($page_context['search_info'])) {
            $sanitized['search_info'] = $this->sanitize_array_recursive($page_context['search_info']);
        }

        if (isset($page_context['homepage_info']) && is_array($page_context['homepage_info'])) {
            $sanitized['homepage_info'] = $this->sanitize_array_recursive($page_context['homepage_info']);
        }

        if (isset($page_context['page_content']) && is_array($page_context['page_content'])) {
            $sanitized['page_content'] = $this->sanitize_array_recursive($page_context['page_content']);
        }

        return $sanitized;
    }

    /**
     * Recursively sanitize an array (v6.27.9)
     *
     * @param array $data Array to sanitize
     * @return array Sanitized array
     */
    private function sanitize_array_recursive($data) {
        if (!is_array($data)) {
            return is_string($data) ? sanitize_text_field($data) : $data;
        }

        $sanitized = array();
        foreach ($data as $key => $value) {
            $safe_key = sanitize_text_field($key);
            if (is_array($value)) {
                $sanitized[$safe_key] = $this->sanitize_array_recursive($value);
            } elseif (is_string($value)) {
                // Check if it looks like a URL
                if (filter_var($value, FILTER_VALIDATE_URL)) {
                    $sanitized[$safe_key] = esc_url_raw($value);
                } else {
                    $sanitized[$safe_key] = sanitize_text_field($value);
                }
            } else {
                // Numbers, booleans, etc.
                $sanitized[$safe_key] = $value;
            }
        }
        return $sanitized;
    }
}

// Note: Instantiated by MLD_Chatbot_Init class
