<?php
/**
 * Chatbot Engine Core
 *
 * Main chatbot engine that processes messages, manages conversations,
 * and coordinates with AI providers
 *
 * @package MLS_Listings_Display
 * @subpackage Chatbot
 * @since 6.6.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Chatbot_Engine {

    /**
     * Current conversation
     *
     * @var array
     */
    private $conversation;

    /**
     * Current session
     *
     * @var array
     */
    private $session;

    /**
     * AI provider instance
     *
     * @var MLD_AI_Provider_Base
     */
    private $ai_provider;

    /**
     * Constructor
     */
    public function __construct() {
        $this->load_dependencies();
    }

    /**
     * Load required dependencies
     */
    private function load_dependencies() {
        require_once MLD_PLUGIN_PATH . 'includes/chatbot/interface-mld-ai-provider.php';
        require_once MLD_PLUGIN_PATH . 'includes/chatbot/abstract-mld-ai-provider.php';
    }

    /**
     * Process incoming chat message
     *
     * @param string $message User message
     * @param string $session_id Session ID
     * @param array $user_data User data (email, name, etc.)
     * @return array Response with AI message or error
     */
    public function process_message($message, $session_id, $user_data = array()) {
        global $wpdb;

        try {
            // Get or create conversation
            $conversation = $this->get_or_create_conversation($session_id, $user_data);
            if (!$conversation) {
                return $this->error_response('Failed to create conversation');
            }

            // Save user message
            $user_message_id = $this->save_message(
                $conversation['id'],
                $session_id,
                'user',
                $message
            );

            if (!$user_message_id) {
                return $this->error_response('Failed to save message');
            }

            // Update conversation activity
            $this->update_conversation_activity($conversation['id']);

            // Check FAQs FIRST (faster and cheaper than AI)
            $faq_manager = new MLD_FAQ_Manager();
            $faq_match = $faq_manager->find_matching_faq($message);

            if ($faq_match && !empty($faq_match['answer'])) {
                // Found a matching FAQ - use it!
                $ai_message_id = $this->save_message(
                    $conversation['id'],
                    $session_id,
                    'assistant',
                    $faq_match['answer'],
                    array(
                        'is_faq' => true,
                        'faq_id' => $faq_match['faq_id'],
                        'faq_match_score' => $faq_match['match_score'] ?? 0,
                    )
                );

                // Send admin notification
                $this->send_admin_notification($conversation['id'], $user_message_id, $ai_message_id);

                return array(
                    'success' => true,
                    'message' => $faq_match['answer'],
                    'is_fallback' => true,
                    'conversation_id' => $conversation['id'],
                    'message_id' => $ai_message_id,
                    'metadata' => array(
                        'source' => 'faq',
                        'faq_id' => $faq_match['faq_id'],
                    ),
                );
            }

            // No FAQ match - use AI provider via Model Router (v6.11.0)
            // The router handles provider selection, fallback, and tool calling
            $router = $this->get_model_router();

            if (!$router || empty($router->get_available_providers())) {
                // Try legacy single-provider approach
                $provider = $this->get_ai_provider();
                if (!$provider) {
                    return $this->handle_faq_fallback($message, $conversation['id'], $session_id);
                }
            }

            // Build context from knowledge base (v6.27.4: now passes user message for keyword search)
            $context = $this->build_context($conversation['id'], $user_data, $message);
            $context['conversation_id'] = $conversation['id'];

            // Get conversation history for context
            $history = $this->get_conversation_history($conversation['id']);

            // Call via router if available (handles provider selection & tools)
            $start_time = microtime(true);
            if ($router && $router->is_enabled() && !empty($router->get_available_providers())) {
                // Use smart routing - router picks best provider and handles tools
                $ai_response = $router->route($message, $context);
            } else {
                // Fall back to single provider approach
                $provider = $this->get_ai_provider();
                if (method_exists($provider, 'chat_with_tools') && method_exists($provider, 'supports_function_calling') && $provider->supports_function_calling()) {
                    $ai_response = $provider->chat_with_tools($history, $context);
                } else {
                    $ai_response = $provider->chat($history, $context);
                }
            }
            $response_time_ms = round((microtime(true) - $start_time) * 1000);

            // Check if AI call succeeded
            if (!isset($ai_response['success']) || !$ai_response['success']) {
                // AI failed, use FAQ fallback
                return $this->handle_faq_fallback($message, $conversation['id'], $session_id, $ai_response);
            }

            // Save AI response
            $ai_message_id = $this->save_message(
                $conversation['id'],
                $session_id,
                'assistant',
                $ai_response['text'],
                array(
                    'ai_provider' => $ai_response['provider'],
                    'ai_model' => $ai_response['model'],
                    'ai_tokens_used' => isset($ai_response['tokens']['total_tokens']) ? $ai_response['tokens']['total_tokens'] : 0,
                    'response_time_ms' => $response_time_ms,
                )
            );

            // Send admin notification
            $this->send_admin_notification($conversation['id'], $user_message_id, $ai_message_id);

            // Return success response
            $response_metadata = array(
                'provider' => $ai_response['provider'],
                'model' => $ai_response['model'],
                'tokens' => isset($ai_response['tokens']) ? $ai_response['tokens'] : null,
                'response_time_ms' => $response_time_ms,
            );

            // Include tool call information if function calling was used (v6.10.9)
            if (!empty($ai_response['tool_calls_made'])) {
                $response_metadata['tool_calls'] = $ai_response['tool_calls_made'];
                $response_metadata['tool_iterations'] = $ai_response['tool_iterations'] ?? 1;
            }

            // Include routing information if router was used (v6.11.0)
            if (!empty($ai_response['routing'])) {
                $response_metadata['routing'] = $ai_response['routing'];
            }

            return array(
                'success' => true,
                'message' => $ai_response['text'],
                'conversation_id' => $conversation['id'],
                'message_id' => $ai_message_id,
                'metadata' => $response_metadata,
            );

        } catch (Exception $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[MLD Chatbot Engine] Error: ' . $e->getMessage());
            }
            return $this->error_response('An error occurred processing your message');
        }
    }

    /**
     * Get or create conversation
     *
     * @param string $session_id Session ID
     * @param array $user_data User data
     * @return array|null Conversation data
     */
    private function get_or_create_conversation($session_id, $user_data) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'mld_chat_conversations';

        // Try to find existing conversation for this session
        $conversation = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE session_id = %s ORDER BY id DESC LIMIT 1",
            $session_id
        ), ARRAY_A);

        if ($conversation) {
            return $conversation;
        }

        // Create new conversation
        $user_ip = $this->get_client_ip();
        $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';

        // Check for returning visitor (v6.14.0)
        $returning_visitor_name = null;
        $previous_collected_info = null;
        $user_email = isset($user_data['email']) ? $user_data['email'] : null;
        $user_phone = isset($user_data['phone']) ? $user_data['phone'] : null;

        if ($user_email || $user_phone) {
            $previous_conversation = $this->find_returning_visitor($user_email, $user_phone);
            if ($previous_conversation) {
                $returning_visitor_name = $previous_conversation['user_name'];
                $previous_collected_info = $previous_conversation['collected_info'];
                // Use previous name if not provided in current request
                if (empty($user_data['name']) && !empty($returning_visitor_name)) {
                    $user_data['name'] = $returning_visitor_name;
                }
            }
        }

        $data = array(
            'session_id' => $session_id,
            'user_email' => $user_email,
            'user_name' => isset($user_data['name']) ? $user_data['name'] : null,
            'user_phone' => $user_phone,
            'conversation_status' => 'active',
            'user_ip' => $user_ip,
            'user_agent' => $user_agent,
            'collected_info' => $previous_collected_info, // Carry forward from previous visit
        );

        $result = $wpdb->insert($table_name, $data);

        if ($result) {
            $conversation_id = $wpdb->insert_id;
            return $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table_name} WHERE id = %d",
                $conversation_id
            ), ARRAY_A);
        }

        return null;
    }

    /**
     * Save message to database
     *
     * @param int $conversation_id Conversation ID
     * @param string $session_id Session ID
     * @param string $sender_type Sender type (user/assistant)
     * @param string $message_text Message text
     * @param array $metadata Additional metadata
     * @return int|false Message ID or false
     */
    private function save_message($conversation_id, $session_id, $sender_type, $message_text, $metadata = array()) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'mld_chat_messages';

        $data = array(
            'conversation_id' => $conversation_id,
            'session_id' => $session_id,
            'sender_type' => $sender_type,
            'message_text' => $message_text,
            'ai_provider' => isset($metadata['ai_provider']) ? $metadata['ai_provider'] : null,
            'ai_model' => isset($metadata['ai_model']) ? $metadata['ai_model'] : null,
            'ai_tokens_used' => isset($metadata['ai_tokens_used']) ? $metadata['ai_tokens_used'] : null,
            'response_time_ms' => isset($metadata['response_time_ms']) ? $metadata['response_time_ms'] : null,
            'is_fallback' => isset($metadata['is_fallback']) ? $metadata['is_fallback'] : 0,
            'fallback_reason' => isset($metadata['fallback_reason']) ? $metadata['fallback_reason'] : null,
        );

        $result = $wpdb->insert($table_name, $data);

        if ($result) {
            // Update conversation message count
            // Use current_time('mysql') for WordPress timezone consistency
            $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->prefix}mld_chat_conversations
                 SET total_messages = total_messages + 1,
                     last_message_at = %s
                 WHERE id = %d",
                current_time('mysql'),
                $conversation_id
            ));

            return $wpdb->insert_id;
        }

        return false;
    }

    /**
     * Get conversation history
     *
     * @param int $conversation_id Conversation ID
     * @param int $limit Number of messages to retrieve
     * @return array Messages array
     */
    private function get_conversation_history($conversation_id, $limit = 20) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'mld_chat_messages';

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT sender_type, message_text, created_at
             FROM {$table_name}
             WHERE conversation_id = %d
             ORDER BY id DESC
             LIMIT %d",
            $conversation_id,
            $limit
        ), ARRAY_A);

        // Reverse to get chronological order
        $results = array_reverse($results);

        // Format for AI provider
        $messages = array();
        foreach ($results as $row) {
            $messages[] = array(
                'role' => $row['sender_type'] === 'user' ? 'user' : 'assistant',
                'content' => $row['message_text'],
            );
        }

        return $messages;
    }

    /**
     * Conversation context manager instance
     *
     * @var MLD_Conversation_Context|null
     * @since 6.14.0
     */
    private $conversation_context = null;

    /**
     * Build context from knowledge base and user data
     *
     * Enhanced in v6.14.0 to include:
     * - Collected user info (name, phone, email) - prevents re-asking
     * - Active search criteria - maintains search state between messages
     * - Recently shown properties - enables reference resolution
     * - Active property data - enables detailed property Q&A
     *
     * Enhanced in v6.27.4:
     * - Passes user message to knowledge base search for keyword matching
     * - Returns full content_text instead of just summaries
     *
     * Enhanced in v6.27.7:
     * - Adds page context awareness (property pages, calculators, etc.)
     * - Auto-loads full property data when on property detail pages
     *
     * @param int $conversation_id Conversation ID
     * @param array $user_data User data
     * @param string $user_message Current user message for knowledge search
     * @return array Context array
     */
    private function build_context($conversation_id, $user_data, $user_message = '') {
        $context = array();

        // Add business information
        $context['business_name'] = get_bloginfo('name');
        $context['business_hours'] = $this->get_business_hours();

        // Add user preferences if available (excluding page_context for separate handling)
        if (!empty($user_data)) {
            $user_prefs = $user_data;
            unset($user_prefs['page_context']); // Handle separately
            $context['user_preferences'] = $user_prefs;
        }

        // Process page context (v6.27.7) - enables page-aware responses
        if (!empty($user_data['page_context'])) {
            $page_context = $this->process_page_context($user_data['page_context']);
            if (!empty($page_context)) {
                $context['current_page'] = $page_context;
            }
        }

        // Load conversation context manager (v6.14.0)
        $this->conversation_context = $this->get_conversation_context($conversation_id);
        if ($this->conversation_context) {
            // Add collected user info - CRITICAL: prevents bot from re-asking for info
            $collected_info = $this->conversation_context->get_collected_info();
            if (!empty($collected_info)) {
                $context['collected_user_info'] = $collected_info;
            }

            // Add active search criteria - maintains search state between messages
            $search_criteria = $this->conversation_context->get_search_criteria();
            if (!empty($search_criteria)) {
                $context['active_search_criteria'] = $search_criteria;
            }

            // Add shown properties for reference resolution
            $shown_properties = $this->conversation_context->get_shown_properties();
            if (!empty($shown_properties)) {
                $context['shown_properties'] = $shown_properties;
            }

            // Add active property data for detailed Q&A
            $active_property = $this->conversation_context->get_active_property();
            if (!empty($active_property)) {
                $context['active_property'] = $active_property;
                $context['active_property_id'] = $this->conversation_context->get_active_property_id();
            }

            // Build AI context string for system prompt injection
            $context['conversation_context_string'] = $this->conversation_context->build_ai_context_string();
        }

        // Get relevant knowledge base entries (v6.27.4: now searches by keywords from user message)
        // Returns up to 5 entries with full content_text matching user's question
        $knowledge = $this->get_knowledge_entries(5, $user_message);
        if (!empty($knowledge)) {
            $context['knowledge'] = $knowledge;
        }

        // Get recent listings (sample)
        $listings = $this->get_sample_listings(3);
        if (!empty($listings)) {
            $context['listings'] = $listings;
        }

        return $context;
    }

    /**
     * Get conversation context manager
     *
     * @param int $conversation_id Conversation ID
     * @return MLD_Conversation_Context|null
     * @since 6.14.0
     */
    private function get_conversation_context($conversation_id) {
        if ($this->conversation_context !== null && $this->conversation_context->get_conversation_id() === $conversation_id) {
            return $this->conversation_context;
        }

        // Load context manager class
        $context_file = dirname(__FILE__) . '/class-mld-conversation-context.php';
        if (file_exists($context_file)) {
            require_once $context_file;
            if (class_exists('MLD_Conversation_Context')) {
                return new MLD_Conversation_Context($conversation_id);
            }
        }

        return null;
    }

    /**
     * Get the current conversation context manager (for tool executor)
     *
     * @return MLD_Conversation_Context|null
     * @since 6.14.0
     */
    public function get_current_context() {
        return $this->conversation_context;
    }

    /**
     * Get knowledge base entries relevant to user's message
     *
     * Enhanced in v6.27.4 to:
     * - Search by keywords extracted from user's message
     * - Return actual content_text, not just summaries
     * - Score and rank results by relevance
     *
     * @param int $limit Number of entries
     * @param string $user_message User's message for keyword search
     * @return array Knowledge entries with full content
     */
    private function get_knowledge_entries($limit = 5, $user_message = '') {
        global $wpdb;
        $table_name = $wpdb->prefix . 'mld_chat_knowledge_base';

        // If no message provided, return top entries by scan date
        if (empty($user_message)) {
            $results = $wpdb->get_results($wpdb->prepare(
                "SELECT content_title, content_text, content_summary, content_type
                 FROM {$table_name}
                 WHERE is_active = 1
                 ORDER BY relevance_score DESC, scan_date DESC
                 LIMIT %d",
                $limit
            ), ARRAY_A);
            return $results;
        }

        // Extract keywords from user message (remove common words)
        $keywords = $this->extract_search_keywords($user_message);

        if (empty($keywords)) {
            // Fall back to recent entries if no keywords
            $results = $wpdb->get_results($wpdb->prepare(
                "SELECT content_title, content_text, content_summary, content_type
                 FROM {$table_name}
                 WHERE is_active = 1
                 ORDER BY scan_date DESC
                 LIMIT %d",
                $limit
            ), ARRAY_A);
            return $results;
        }

        // Build search query with keyword matching
        // Search in title, content_text, and content_summary
        $where_clauses = array();
        $params = array();

        foreach ($keywords as $keyword) {
            $like_pattern = '%' . $wpdb->esc_like($keyword) . '%';
            $where_clauses[] = "(content_title LIKE %s OR content_text LIKE %s OR content_summary LIKE %s)";
            $params[] = $like_pattern;
            $params[] = $like_pattern;
            $params[] = $like_pattern;
        }

        // Build relevance scoring: count how many keywords match
        $relevance_cases = array();
        foreach ($keywords as $keyword) {
            $like_pattern = '%' . $wpdb->esc_like($keyword) . '%';
            $relevance_cases[] = $wpdb->prepare(
                "(CASE WHEN content_title LIKE %s THEN 3 ELSE 0 END) + " .
                "(CASE WHEN content_text LIKE %s THEN 2 ELSE 0 END) + " .
                "(CASE WHEN content_summary LIKE %s THEN 1 ELSE 0 END)",
                $like_pattern, $like_pattern, $like_pattern
            );
        }
        $relevance_score = '(' . implode(' + ', $relevance_cases) . ')';

        // Final query: find entries matching ANY keyword, ordered by match score
        $where_sql = '(' . implode(' OR ', $where_clauses) . ')';

        // Note: We're using raw SQL here because the relevance score calculation
        // is too complex for wpdb->prepare format strings
        $sql = "SELECT content_title, content_text, content_summary, content_type,
                       {$relevance_score} as match_score
                FROM {$table_name}
                WHERE is_active = 1 AND {$where_sql}
                ORDER BY match_score DESC, relevance_score DESC
                LIMIT %d";

        // Add limit to params
        $params[] = $limit;

        $results = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);

        // Log for debugging
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[MLD Chatbot v6.27.4] Knowledge search for: "' . $user_message . '"');
            error_log('[MLD Chatbot v6.27.4] Keywords: ' . implode(', ', $keywords));
            error_log('[MLD Chatbot v6.27.4] Found ' . count($results) . ' relevant entries');
        }

        return $results;
    }

    /**
     * Extract search keywords from user message
     *
     * Removes common stop words and returns meaningful search terms
     *
     * @param string $message User message
     * @return array Array of keywords
     * @since 6.27.4
     */
    private function extract_search_keywords($message) {
        // Convert to lowercase and remove punctuation
        $message = strtolower($message);
        $message = preg_replace('/[^\w\s]/', ' ', $message);

        // Split into words
        $words = preg_split('/\s+/', $message, -1, PREG_SPLIT_NO_EMPTY);

        // Common stop words to filter out
        $stop_words = array(
            'a', 'an', 'the', 'is', 'are', 'was', 'were', 'be', 'been', 'being',
            'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would', 'could', 'should',
            'i', 'you', 'he', 'she', 'it', 'we', 'they', 'my', 'your', 'his', 'her', 'its',
            'what', 'which', 'who', 'whom', 'this', 'that', 'these', 'those',
            'am', 'is', 'are', 'was', 'were', 'been', 'being',
            'if', 'or', 'and', 'but', 'because', 'as', 'until', 'while',
            'of', 'at', 'by', 'for', 'with', 'about', 'against', 'between', 'into',
            'through', 'during', 'before', 'after', 'above', 'below', 'to', 'from',
            'up', 'down', 'in', 'out', 'on', 'off', 'over', 'under', 'again',
            'then', 'once', 'here', 'there', 'when', 'where', 'why', 'how',
            'all', 'each', 'few', 'more', 'most', 'other', 'some', 'such',
            'no', 'nor', 'not', 'only', 'own', 'same', 'so', 'than', 'too', 'very',
            'can', 'just', 'dont', 'now', 'me', 'any', 'hi', 'hello', 'hey',
            'please', 'thanks', 'thank', 'yes', 'no', 'ok', 'okay'
        );

        // Filter out stop words and short words
        $keywords = array_filter($words, function($word) use ($stop_words) {
            return strlen($word) >= 3 && !in_array($word, $stop_words);
        });

        // Limit to top 5 keywords
        return array_slice(array_values($keywords), 0, 5);
    }

    /**
     * Process page context for page-aware responses (v6.27.7)
     *
     * This method processes the page context sent from the frontend and enriches it
     * with additional data from the backend. For property pages, it loads full
     * property details so the chatbot can answer questions about the current listing.
     *
     * @param array $page_context Page context from frontend
     * @return array Enriched page context
     * @since 6.27.7
     */
    private function process_page_context($page_context) {
        if (empty($page_context) || !is_array($page_context)) {
            return array();
        }

        $processed = array(
            'page_type' => sanitize_text_field($page_context['page_type'] ?? 'unknown'),
            'page_title' => sanitize_text_field($page_context['page_title'] ?? ''),
            'page_url' => esc_url_raw($page_context['page_url'] ?? ''),
        );

        // Process based on page type
        switch ($processed['page_type']) {
            case 'property_detail':
                $processed['property'] = $this->process_property_page_context($page_context);
                break;

            case 'calculator':
                $processed['calculator'] = $this->sanitize_array($page_context['calculator_info'] ?? array());
                break;

            case 'cma':
                $processed['cma'] = $this->sanitize_array($page_context['cma_info'] ?? array());
                break;

            case 'search_results':
                $processed['search'] = $this->sanitize_array($page_context['search_info'] ?? array());
                break;

            case 'homepage':
                $processed['homepage'] = $this->sanitize_array($page_context['homepage_info'] ?? array());
                break;

            case 'content_page':
                $processed['content'] = $this->sanitize_array($page_context['page_content'] ?? array());
                break;
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[MLD Chatbot v6.27.7] Page context processed: ' . $processed['page_type']);
        }

        return $processed;
    }

    /**
     * Process property page context and load full property data (v6.27.7)
     *
     * When the user is on a property detail page, this loads the complete
     * property information from the database so the chatbot can answer
     * any questions about the property (MLS number, price, features, etc.)
     *
     * @param array $page_context Page context from frontend
     * @return array Property data
     * @since 6.27.7
     */
    private function process_property_page_context($page_context) {
        $property_data = $this->sanitize_array($page_context['property_data'] ?? array());

        // Try to get listing ID from various sources
        $listing_id = null;

        // From frontend extracted data
        if (!empty($property_data['listing_id'])) {
            $listing_id = sanitize_text_field($property_data['listing_id']);
        }

        // From visible details on page
        if (!$listing_id && !empty($property_data['visible_details']['mls_number'])) {
            $listing_id = sanitize_text_field($property_data['visible_details']['mls_number']);
        }

        // From URL pattern
        if (!$listing_id && !empty($page_context['page_url'])) {
            if (preg_match('/\/property\/([^\/]+)/', $page_context['page_url'], $matches)) {
                $listing_id = sanitize_text_field($matches[1]);
            }
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[MLD Chatbot v6.27.7] Property page detected, listing_id: ' . ($listing_id ?: 'not found'));
        }

        // If we have a listing ID, load full property data from database
        if ($listing_id) {
            $full_property = $this->load_property_data($listing_id);
            if ($full_property) {
                $property_data['full_details'] = $full_property;
                $property_data['listing_id'] = $listing_id;

                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('[MLD Chatbot v6.27.7] Loaded full property data for: ' . $listing_id);
                }
            }
        }

        return $property_data;
    }

    /**
     * Load full property data from database (v6.27.7)
     *
     * @param string $listing_id Listing ID
     * @return array|null Property data or null if not found
     * @since 6.27.7
     */
    private function load_property_data($listing_id) {
        global $wpdb;

        if (empty($listing_id)) {
            return null;
        }

        // Try to use unified data provider if available
        if (class_exists('MLD_Unified_Data_Provider')) {
            if (!isset($this->data_provider)) {
                require_once MLD_PLUGIN_PATH . 'includes/chatbot/class-mld-unified-data-provider.php';
                $this->data_provider = new MLD_Unified_Data_Provider();
            }

            // Try to get comprehensive data
            if (method_exists($this->data_provider, 'getComprehensivePropertyData')) {
                $data = $this->data_provider->getComprehensivePropertyData($listing_id);
                if ($data) {
                    return $this->format_property_for_context($data);
                }
            }

            // Fall back to basic property data
            $criteria = array('listing_id' => $listing_id, 'limit' => 1);
            $properties = $this->data_provider->getPropertyData($criteria);
            if (!empty($properties)) {
                return $this->format_property_for_context($properties[0]);
            }
        }

        // Direct database query as last resort
        $summary_table = $wpdb->prefix . 'bme_listing_summary';

        $property = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$summary_table} WHERE listing_id = %s LIMIT 1",
            $listing_id
        ), ARRAY_A);

        if ($property) {
            return $this->format_property_for_context($property);
        }

        return null;
    }

    /**
     * Format property data for AI context (v6.27.7)
     *
     * @param array $property Raw property data
     * @return array Formatted property data
     * @since 6.27.7
     */
    private function format_property_for_context($property) {
        // Build a comprehensive but AI-friendly summary
        // Note: listing_id is the MLS number (e.g., 73454722)
        // listing_key is an internal hash - never expose to users (v6.27.10)
        $formatted = array(
            'listing_id' => $property['listing_id'] ?? null,
            'mls_number' => $property['listing_id'] ?? null,
            'status' => $property['standard_status'] ?? 'Unknown',
        );

        // Address
        $street = $property['street_address'] ?? '';
        if (empty($street) && !empty($property['street_number'])) {
            $street = trim(($property['street_number'] ?? '') . ' ' . ($property['street_name'] ?? ''));
        }
        $formatted['address'] = trim(sprintf(
            '%s, %s, %s %s',
            $street,
            $property['city'] ?? '',
            $property['state_or_province'] ?? $property['state'] ?? '',
            $property['postal_code'] ?? ''
        ), ', ');

        // Price
        if (!empty($property['list_price'])) {
            $formatted['price'] = '$' . number_format(floatval($property['list_price']));
            $formatted['price_raw'] = floatval($property['list_price']);
        }

        // Basic specs
        if (!empty($property['bedrooms_total'])) {
            $formatted['bedrooms'] = intval($property['bedrooms_total']);
        }
        if (!empty($property['bathrooms_total'])) {
            $formatted['bathrooms'] = floatval($property['bathrooms_total']);
        }

        // Square footage
        $sqft = $property['living_area'] ?? $property['building_area_total'] ?? null;
        if ($sqft) {
            $formatted['sqft'] = number_format(intval($sqft));
        }

        // Year built
        if (!empty($property['year_built'])) {
            $formatted['year_built'] = intval($property['year_built']);
        }

        // Property type
        $formatted['property_type'] = $property['property_sub_type'] ?? $property['property_type'] ?? 'Unknown';

        // Lot size
        if (!empty($property['lot_size_area'])) {
            $formatted['lot_size'] = $property['lot_size_area'];
        }

        // Days on market
        if (!empty($property['days_on_market'])) {
            $formatted['days_on_market'] = intval($property['days_on_market']);
        } elseif (!empty($property['original_entry_timestamp'])) {
            $formatted['days_on_market'] = floor((time() - strtotime($property['original_entry_timestamp'])) / 86400);
        }

        // Taxes
        if (!empty($property['tax_annual_amount'])) {
            $formatted['annual_taxes'] = '$' . number_format(floatval($property['tax_annual_amount']));
        }

        // HOA
        if (!empty($property['association_fee'])) {
            $freq = $property['association_fee_frequency'] ?? 'Monthly';
            $formatted['hoa_fee'] = '$' . number_format(floatval($property['association_fee'])) . '/' . strtolower($freq);
        }

        // Heating/Cooling
        if (!empty($property['heating'])) {
            $formatted['heating'] = $property['heating'];
        }
        if (!empty($property['cooling'])) {
            $formatted['cooling'] = $property['cooling'];
        }

        // Garage
        if (!empty($property['garage_spaces'])) {
            $formatted['garage_spaces'] = intval($property['garage_spaces']);
        }

        // Description
        if (!empty($property['public_remarks'])) {
            $formatted['description'] = wp_trim_words($property['public_remarks'], 150);
        } elseif (!empty($property['property_description'])) {
            $formatted['description'] = wp_trim_words($property['property_description'], 150);
        }

        // Features
        if (!empty($property['interior_features'])) {
            $formatted['interior_features'] = $property['interior_features'];
        }
        if (!empty($property['exterior_features'])) {
            $formatted['exterior_features'] = $property['exterior_features'];
        }
        if (!empty($property['appliances'])) {
            $formatted['appliances'] = $property['appliances'];
        }

        // Schools
        if (!empty($property['elementary_school'])) {
            $formatted['elementary_school'] = $property['elementary_school'];
        }
        if (!empty($property['middle_school'])) {
            $formatted['middle_school'] = $property['middle_school'];
        }
        if (!empty($property['high_school'])) {
            $formatted['high_school'] = $property['high_school'];
        }

        // Property URL
        $formatted['property_url'] = home_url('/property/' . ($property['listing_id'] ?? '') . '/');

        return $formatted;
    }

    /**
     * Sanitize an array of values (v6.27.7)
     *
     * @param array $arr Array to sanitize
     * @return array Sanitized array
     * @since 6.27.7
     */
    private function sanitize_array($arr) {
        if (!is_array($arr)) {
            return array();
        }

        $sanitized = array();
        foreach ($arr as $key => $value) {
            $clean_key = sanitize_key($key);
            if (is_array($value)) {
                $sanitized[$clean_key] = $this->sanitize_array($value);
            } elseif (is_string($value)) {
                $sanitized[$clean_key] = sanitize_text_field($value);
            } elseif (is_numeric($value)) {
                $sanitized[$clean_key] = $value;
            } elseif (is_bool($value)) {
                $sanitized[$clean_key] = $value;
            }
        }
        return $sanitized;
    }

    /**
     * Get sample property listings
     *
     * @param int $limit Number of listings
     * @return array Listings
     */
    private function get_sample_listings($limit = 3) {
        global $wpdb;

        // Check if BME plugin is available
        if (!function_exists('bme_pro')) {
            return array();
        }

        $bme = bme_pro();
        $summary_table = $wpdb->prefix . 'bme_listing_summary';

        // Use columns available in summary table (v6.10.9)
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT CONCAT(street_number, ' ', street_name) as street_address,
                    city, state_or_province as state,
                    bedrooms_total, bathrooms_total, list_price
             FROM {$summary_table}
             WHERE standard_status = 'Active'
             ORDER BY list_price DESC
             LIMIT %d",
            $limit
        ), ARRAY_A);

        return $results;
    }

    /**
     * Get business hours from chat settings
     *
     * Retrieves business hours from the prompt_variables category in chat settings.
     * Falls back to a sensible default if not configured.
     *
     * @return string Business hours string
     * @since 6.9.5
     */
    private function get_business_hours() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'mld_chat_settings';

        $hours = $wpdb->get_var($wpdb->prepare(
            "SELECT setting_value FROM {$table_name}
             WHERE setting_key = %s AND setting_category = %s",
            'business_hours',
            'prompt_variables'
        ));

        // Return configured hours or default
        if (!empty($hours)) {
            return $hours;
        }

        // Fallback to legacy option if exists
        $legacy_hours = get_option('mld_business_hours', '');
        if (!empty($legacy_hours)) {
            return $legacy_hours;
        }

        // Default fallback
        return 'Monday - Friday: 9:00 AM - 5:00 PM';
    }

    /**
     * Get AI provider instance
     *
     * @return MLD_AI_Provider_Base|null Provider instance
     */
    /**
     * Get the model router instance
     *
     * @return MLD_Model_Router|null
     * @since 6.11.0
     */
    private function get_model_router() {
        static $router = null;

        if ($router === null) {
            $router_file = dirname(__FILE__) . '/class-mld-model-router.php';
            if (file_exists($router_file)) {
                require_once $router_file;
                if (function_exists('mld_get_model_router')) {
                    $router = mld_get_model_router();
                }
            }
        }

        return $router;
    }

    private function get_ai_provider() {
        if ($this->ai_provider !== null) {
            return $this->ai_provider;
        }

        global $wpdb;
        $settings_table = $wpdb->prefix . 'mld_chat_settings';

        // Get current provider setting
        $provider_name = $wpdb->get_var($wpdb->prepare(
            "SELECT setting_value FROM {$settings_table} WHERE setting_key = %s",
            'ai_provider'
        ));

        if (!$provider_name) {
            $provider_name = 'openai'; // Default
        }

        // Load provider class
        $provider_class = $this->get_provider_class($provider_name);
        if (!$provider_class) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[MLD Chatbot] Provider class not found: ' . $provider_name);
            }
            return null;
        }

        // Check if provider is available
        $this->ai_provider = new $provider_class();
        $availability = $this->ai_provider->is_available();

        if (!$availability['available']) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[MLD Chatbot] Provider not available: ' . $availability['reason']);
            }
            return null;
        }

        return $this->ai_provider;
    }

    /**
     * Get provider class name
     *
     * @param string $provider_name Provider name
     * @return string|null Class name
     */
    private function get_provider_class($provider_name) {
        $providers = array(
            'test' => 'MLD_Test_Provider',
            'openai' => 'MLD_OpenAI_Provider',
            'claude' => 'MLD_Claude_Provider',
            'gemini' => 'MLD_Gemini_Provider',
        );

        if (!isset($providers[$provider_name])) {
            return null;
        }

        $class_file = MLD_PLUGIN_PATH . 'includes/chatbot/providers/class-mld-' . $provider_name . '-provider.php';
        if (file_exists($class_file)) {
            require_once $class_file;
            return $providers[$provider_name];
        }

        return null;
    }

    /**
     * Handle FAQ fallback when AI is unavailable
     *
     * @param string $question User question
     * @param int $conversation_id Conversation ID
     * @param string $session_id Session ID
     * @param array $ai_error AI error details
     * @return array Response
     */
    private function handle_faq_fallback($question, $conversation_id, $session_id, $ai_error = null) {
        global $wpdb;

        // Check if FAQ fallback is enabled
        $fallback_enabled = $wpdb->get_var($wpdb->prepare(
            "SELECT setting_value FROM {$wpdb->prefix}mld_chat_settings WHERE setting_key = %s",
            'fallback_to_faq'
        ));

        if ($fallback_enabled !== '1') {
            return $this->error_response('AI service is temporarily unavailable');
        }

        // Search FAQs using FAQ manager
        $faq_manager = mld_get_faq_manager();
        $faq = $faq_manager ? $faq_manager->find_matching_faq($question) : null;

        if (!$faq) {
            return $this->error_response('I\'m having trouble connecting to my AI service right now. Please try again later or contact us directly.');
        }

        // Save FAQ response as message
        $message_id = $this->save_message(
            $conversation_id,
            $session_id,
            'assistant',
            $faq['answer'],
            array(
                'is_fallback' => 1,
                'fallback_reason' => isset($ai_error['error']) ? $ai_error['error'] : 'AI provider unavailable',
            )
        );

        // FAQ usage is already tracked by the FAQ manager

        return array(
            'success' => true,
            'message' => $faq['answer'] . "\n\n(Note: I'm currently using my FAQ knowledge base. For more detailed help, please contact us directly.)",
            'conversation_id' => $conversation_id,
            'message_id' => $message_id,
            'is_fallback' => true,
        );
    }

    /**
     * Search FAQ library for matching question
     *
     * @param string $question User question
     * @return array|null FAQ entry
     */
    private function search_faq($question) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'mld_chat_faq_library';

        // Simple keyword matching (semantic search planned for Phase 3)
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table_name}
             WHERE is_active = 1
             AND (MATCH(question, answer, keywords) AGAINST (%s IN NATURAL LANGUAGE MODE)
                  OR question LIKE %s
                  OR keywords LIKE %s)
             ORDER BY priority DESC, usage_count DESC
             LIMIT 1",
            $question,
            '%' . $wpdb->esc_like($question) . '%',
            '%' . $wpdb->esc_like($question) . '%'
        ), ARRAY_A);

        return !empty($results) ? $results[0] : null;
    }

    /**
     * Update conversation activity timestamp
     *
     * @param int $conversation_id Conversation ID
     */
    private function update_conversation_activity($conversation_id) {
        global $wpdb;

        $wpdb->update(
            $wpdb->prefix . 'mld_chat_conversations',
            array('last_message_at' => current_time('mysql')),
            array('id' => $conversation_id),
            array('%s'),
            array('%d')
        );
    }

    /**
     * Send admin notification for new user message
     *
     * @param int $conversation_id Conversation ID
     * @param int $user_message_id User message ID
     * @param int $ai_message_id AI response message ID
     */
    private function send_admin_notification($conversation_id, $user_message_id, $ai_message_id) {
        // Use the admin notifier to send immediate notification
        $notifier = mld_get_admin_notifier();
        if ($notifier) {
            $notifier->send_immediate_notification($conversation_id, $user_message_id, $ai_message_id);
        }
    }

    /**
     * Get client IP address
     *
     * @return string IP address
     */
    private function get_client_ip() {
        $ip_keys = array('HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR');
        foreach ($ip_keys as $key) {
            if (array_key_exists($key, $_SERVER) === true) {
                foreach (explode(',', $_SERVER[$key]) as $ip) {
                    $ip = trim($ip);
                    if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                        return $ip;
                    }
                }
            }
        }
        return 'Unknown';
    }

    /**
     * Find returning visitor by email or phone
     *
     * @param string|null $email User email
     * @param string|null $phone User phone
     * @return array|null Previous conversation data
     * @since 6.14.0
     */
    private function find_returning_visitor($email, $phone) {
        global $wpdb;
        $table = $wpdb->prefix . 'mld_chat_conversations';

        // Check if returning visitor recognition is enabled
        $enabled = $wpdb->get_var($wpdb->prepare(
            "SELECT setting_value FROM {$wpdb->prefix}mld_chat_settings
             WHERE setting_key = %s AND setting_category = %s",
            'returning_visitor_recognition',
            'conversation'
        ));

        if ($enabled !== '1') {
            return null;
        }

        // Search for previous conversations with same email or phone
        $where_clauses = array();
        $params = array();

        if (!empty($email)) {
            $where_clauses[] = 'user_email = %s';
            $params[] = $email;
        }
        if (!empty($phone)) {
            $where_clauses[] = 'user_phone = %s';
            $params[] = $phone;
        }

        if (empty($where_clauses)) {
            return null;
        }

        $where_sql = '(' . implode(' OR ', $where_clauses) . ')';

        $sql = $wpdb->prepare(
            "SELECT user_name, user_email, user_phone, collected_info
             FROM {$table}
             WHERE {$where_sql}
             AND user_name IS NOT NULL
             ORDER BY last_message_at DESC
             LIMIT 1",
            ...$params
        );

        $result = $wpdb->get_row($sql, ARRAY_A);

        if ($result && defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[MLD Chatbot 6.14.0] Returning visitor recognized: ' . $result['user_name']);
        }

        return $result;
    }

    /**
     * Format error response
     *
     * @param string $message Error message
     * @return array Error response
     */
    private function error_response($message) {
        return array(
            'success' => false,
            'error' => $message,
        );
    }
}
