<?php
/**
 * User Summary Email Generator
 *
 * Generates and sends AI-powered conversation summaries to users
 * when chat ends (window close or idle timeout)
 *
 * @package MLS_Listings_Display
 * @subpackage Chatbot
 * @since 6.6.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Summary_Generator {

    /**
     * Constructor
     */
    public function __construct() {
        // Listen for conversation end events
        add_action('mld_chat_send_summary_email', array($this, 'generate_and_send_summary'), 10, 2);
    }

    /**
     * Generate and send summary email
     *
     * Called when conversation ends (idle timeout or user closed window)
     *
     * @param int $conversation_id Conversation ID
     * @param string $reason Close reason (idle_timeout, user_closed, etc.)
     * @return bool Success status
     */
    public function generate_and_send_summary($conversation_id, $reason = 'unknown') {
        global $wpdb;

        // Get conversation details
        $conversation = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}mld_chat_conversations WHERE id = %d",
            $conversation_id
        ), ARRAY_A);

        if (!$conversation) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("[MLD Summary Generator] Conversation {$conversation_id} not found");
            }
            return false;
        }

        // Check if summary already sent
        if ($conversation['summary_sent'] == 1) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("[MLD Summary Generator] Summary already sent for conversation {$conversation_id}");
            }
            return false;
        }

        // Only send summary if we have user email
        if (empty($conversation['user_email'])) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("[MLD Summary Generator] No email for conversation {$conversation_id}, skipping summary");
            }
            return false;
        }

        // Check if user summaries are enabled
        if (!$this->are_user_summaries_enabled()) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("[MLD Summary Generator] User summaries are disabled");
            }
            return false;
        }

        // Get conversation messages
        $messages = $this->get_conversation_messages($conversation_id);

        if (empty($messages)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("[MLD Summary Generator] No messages for conversation {$conversation_id}");
            }
            return false;
        }

        // Generate summary using AI
        $summary_data = $this->generate_summary($messages, $conversation);

        if (!$summary_data) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("[MLD Summary Generator] Failed to generate summary for conversation {$conversation_id}");
            }
            return false;
        }

        // Save summary to database
        $summary_id = $this->save_summary($conversation_id, $summary_data);

        if (!$summary_id) {
            return false;
        }

        // Send email
        $sent = $this->send_summary_email($conversation, $summary_data, $reason);

        if ($sent) {
            // Mark summary as sent
            $wpdb->update(
                $wpdb->prefix . 'mld_chat_conversations',
                array('summary_sent' => 1),
                array('id' => $conversation_id),
                array('%d'),
                array('%d')
            );

            // Update summary record
            $wpdb->update(
                $wpdb->prefix . 'mld_chat_email_summaries',
                array(
                    'sent_at' => current_time('mysql'),
                    'delivery_status' => 'sent',
                ),
                array('id' => $summary_id),
                array('%s', '%s'),
                array('%d')
            );

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("[MLD Summary Generator] Summary email sent for conversation {$conversation_id}");
            }
            return true;
        } else {
            // Mark as failed
            $wpdb->update(
                $wpdb->prefix . 'mld_chat_email_summaries',
                array('delivery_status' => 'failed'),
                array('id' => $summary_id),
                array('%s'),
                array('%d')
            );

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("[MLD Summary Generator] Failed to send summary email for conversation {$conversation_id}");
            }
            return false;
        }
    }

    /**
     * Get conversation messages
     *
     * @param int $conversation_id Conversation ID
     * @return array Messages
     */
    private function get_conversation_messages($conversation_id) {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT sender_type, message_text, created_at
             FROM {$wpdb->prefix}mld_chat_messages
             WHERE conversation_id = %d
             ORDER BY id ASC",
            $conversation_id
        ), ARRAY_A);
    }

    /**
     * Generate summary using AI
     *
     * @param array $messages Conversation messages
     * @param array $conversation Conversation data
     * @return array|false Summary data or false on failure
     */
    private function generate_summary($messages, $conversation) {
        // Build conversation text for AI
        $conversation_text = '';
        $user_messages_count = 0;
        $ai_messages_count = 0;

        foreach ($messages as $message) {
            $sender = $message['sender_type'] === 'user' ? 'User' : 'Assistant';
            $conversation_text .= "{$sender}: {$message['message_text']}\n\n";

            if ($message['sender_type'] === 'user') {
                $user_messages_count++;
            } else {
                $ai_messages_count++;
            }
        }

        // Get AI provider
        require_once MLD_PLUGIN_PATH . 'includes/chatbot/interface-mld-ai-provider.php';
        require_once MLD_PLUGIN_PATH . 'includes/chatbot/abstract-mld-ai-provider.php';

        $provider = $this->get_ai_provider();

        if (!$provider) {
            // Generate simple fallback summary without AI
            return $this->generate_fallback_summary($messages, $conversation);
        }

        // Build summary prompt
        $system_prompt = "You are an expert at summarizing real estate chatbot conversations. Your task is to create a concise, helpful summary for the user.\n\n";
        $system_prompt .= "Create a summary that includes:\n";
        $system_prompt .= "1. Main topics discussed\n";
        $system_prompt .= "2. Key information provided\n";
        $system_prompt .= "3. Any specific properties or listings mentioned\n";
        $system_prompt .= "4. Recommended next steps for the user\n\n";
        $system_prompt .= "Keep the summary brief but informative (3-5 paragraphs).";

        $user_prompt = "Please summarize this conversation:\n\n{$conversation_text}";

        // Prepare messages for AI
        $ai_messages = array(
            array(
                'role' => 'user',
                'content' => $user_prompt,
            ),
        );

        // Call AI provider
        try {
            $response = $provider->chat($ai_messages, array('system' => $system_prompt));

            if ($response['success']) {
                return array(
                    'summary_text' => $response['text'],
                    'key_topics' => $this->extract_key_topics($messages),
                    'properties_mentioned' => $this->extract_properties($messages),
                    'message_count' => $user_messages_count + $ai_messages_count,
                    'user_message_count' => $user_messages_count,
                    'ai_provider' => $response['provider'],
                    'ai_model' => $response['model'],
                );
            }
        } catch (Exception $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("[MLD Summary Generator] AI error: " . $e->getMessage());
            }
        }

        // Fallback if AI fails
        return $this->generate_fallback_summary($messages, $conversation);
    }

    /**
     * Generate fallback summary without AI
     *
     * @param array $messages Conversation messages
     * @param array $conversation Conversation data
     * @return array Summary data
     */
    private function generate_fallback_summary($messages, $conversation) {
        $user_messages = array_filter($messages, function($m) {
            return $m['sender_type'] === 'user';
        });

        $summary_text = "Thank you for chatting with us! Here's a quick recap of your conversation:\n\n";
        $summary_text .= "You asked " . count($user_messages) . " question(s) about real estate. ";
        $summary_text .= "Our chatbot provided information to help answer your questions.\n\n";

        $key_topics = $this->extract_key_topics($messages);
        if (!empty($key_topics)) {
            $summary_text .= "Topics discussed: " . implode(', ', $key_topics) . "\n\n";
        }

        $summary_text .= "If you have additional questions, feel free to chat with us again or contact our office directly.";

        return array(
            'summary_text' => $summary_text,
            'key_topics' => $key_topics,
            'properties_mentioned' => $this->extract_properties($messages),
            'message_count' => count($messages),
            'user_message_count' => count($user_messages),
            'ai_provider' => 'fallback',
            'ai_model' => 'none',
        );
    }

    /**
     * Extract key topics from conversation
     *
     * @param array $messages Conversation messages
     * @return array Topics
     */
    private function extract_key_topics($messages) {
        $topics = array();

        // Simple keyword matching for common real estate topics
        $keywords = array(
            'price' => 'Pricing',
            'bedroom' => 'Bedrooms',
            'bathroom' => 'Bathrooms',
            'location' => 'Location',
            'school' => 'Schools',
            'mortgage' => 'Financing',
            'market' => 'Market Trends',
            'neighborhood' => 'Neighborhoods',
            'tour' => 'Property Tours',
            'offer' => 'Making Offers',
        );

        foreach ($messages as $message) {
            $text = strtolower($message['message_text']);
            foreach ($keywords as $keyword => $topic) {
                if (strpos($text, $keyword) !== false && !in_array($topic, $topics)) {
                    $topics[] = $topic;
                }
            }
        }

        return array_slice($topics, 0, 5); // Max 5 topics
    }

    /**
     * Extract properties mentioned in conversation
     *
     * @param array $messages Conversation messages
     * @return array Properties (addresses)
     */
    private function extract_properties($messages) {
        $properties = array();

        // Look for addresses or listing IDs mentioned
        foreach ($messages as $message) {
            // Simple pattern matching for addresses
            if (preg_match_all('/\d+\s+[A-Za-z\s]+(?:Street|St|Avenue|Ave|Road|Rd|Drive|Dr|Lane|Ln|Boulevard|Blvd)/i', $message['message_text'], $matches)) {
                foreach ($matches[0] as $address) {
                    if (!in_array($address, $properties)) {
                        $properties[] = $address;
                    }
                }
            }
        }

        return array_slice($properties, 0, 5); // Max 5 properties
    }

    /**
     * Save summary to database
     *
     * @param int $conversation_id Conversation ID
     * @param array $summary_data Summary data
     * @return int|false Summary ID or false on failure
     */
    private function save_summary($conversation_id, $summary_data) {
        global $wpdb;

        $result = $wpdb->insert(
            $wpdb->prefix . 'mld_chat_email_summaries',
            array(
                'conversation_id' => $conversation_id,
                'summary_text' => $summary_data['summary_text'],
                'key_topics' => wp_json_encode($summary_data['key_topics']),
                'properties_mentioned' => wp_json_encode($summary_data['properties_mentioned']),
                'ai_provider' => $summary_data['ai_provider'],
                'ai_model' => $summary_data['ai_model'],
                'delivery_status' => 'pending',
                'created_at' => current_time('mysql'),
            ),
            array('%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
        );

        return $result ? $wpdb->insert_id : false;
    }

    /**
     * Send summary email to user
     *
     * @param array $conversation Conversation data
     * @param array $summary_data Summary data
     * @param string $reason Close reason
     * @return bool Success status
     */
    private function send_summary_email($conversation, $summary_data, $reason) {
        $user_email = $conversation['user_email'];
        $user_name = $conversation['user_name'] ?: 'there';

        // Build subject
        $subject = sprintf(
            'Your Conversation Summary - %s',
            get_bloginfo('name')
        );

        // Build email body
        $body = $this->build_summary_email($conversation, $summary_data, $reason);

        // Set headers
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>',
        );

        // Send email
        return wp_mail($user_email, $subject, $body, $headers);
    }

    /**
     * Build summary email HTML
     *
     * @param array $conversation Conversation data
     * @param array $summary_data Summary data
     * @param string $reason Close reason
     * @return string Email HTML
     */
    private function build_summary_email($conversation, $summary_data, $reason) {
        $site_name = get_bloginfo('name');
        $site_url = home_url();
        $user_name = $conversation['user_name'] ?: 'there';

        ob_start();
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <style>
                body {
                    font-family: Arial, sans-serif;
                    line-height: 1.6;
                    color: #333;
                    max-width: 600px;
                    margin: 0 auto;
                    padding: 20px;
                }
                .header {
                    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                    color: white;
                    padding: 30px 20px;
                    border-radius: 5px 5px 0 0;
                    text-align: center;
                }
                .content {
                    background: #f9f9f9;
                    padding: 30px 20px;
                    border: 1px solid #ddd;
                    border-top: none;
                }
                .summary-box {
                    background: white;
                    padding: 20px;
                    margin: 20px 0;
                    border-radius: 5px;
                    border-left: 4px solid #667eea;
                }
                .topics-list {
                    background: #fff;
                    padding: 15px;
                    margin: 15px 0;
                    border-radius: 3px;
                    border: 1px solid #ddd;
                }
                .topics-list ul {
                    margin: 10px 0;
                    padding-left: 20px;
                }
                .button {
                    display: inline-block;
                    background: #667eea;
                    color: white;
                    padding: 12px 24px;
                    text-decoration: none;
                    border-radius: 5px;
                    margin: 20px 0;
                }
                .footer {
                    background: #f1f1f1;
                    padding: 20px;
                    text-align: center;
                    font-size: 12px;
                    color: #666;
                    border-radius: 0 0 5px 5px;
                }
                .stats {
                    background: #e8eaf6;
                    padding: 15px;
                    border-radius: 3px;
                    margin: 15px 0;
                }
            </style>
        </head>
        <body>
            <div class="header">
                <h1 style="margin: 0; font-size: 28px;">💬 Your Conversation Summary</h1>
                <p style="margin: 10px 0 0 0; font-size: 16px;">Thanks for chatting with us, <?php echo esc_html($user_name); ?>!</p>
            </div>

            <div class="content">
                <p>Here's a summary of your recent conversation with our AI assistant:</p>

                <div class="summary-box">
                    <?php echo nl2br(esc_html($summary_data['summary_text'])); ?>
                </div>

                <?php if (!empty($summary_data['key_topics'])): ?>
                <div class="topics-list">
                    <strong>Topics We Discussed:</strong>
                    <ul>
                        <?php foreach ($summary_data['key_topics'] as $topic): ?>
                            <li><?php echo esc_html($topic); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>

                <?php if (!empty($summary_data['properties_mentioned'])): ?>
                <div class="topics-list">
                    <strong>Properties Mentioned:</strong>
                    <ul>
                        <?php foreach ($summary_data['properties_mentioned'] as $property): ?>
                            <li><?php echo esc_html($property); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>

                <div class="stats">
                    <strong>Conversation Stats:</strong><br>
                    Messages Exchanged: <?php echo esc_html($summary_data['message_count']); ?><br>
                    Your Questions: <?php echo esc_html($summary_data['user_message_count']); ?>
                </div>

                <center>
                    <a href="<?php echo esc_url($site_url); ?>" class="button">Visit Our Website</a>
                </center>

                <p style="margin-top: 30px;">
                    <strong>Need More Help?</strong><br>
                    Feel free to chat with us again or contact us directly. We're here to help!
                </p>
            </div>

            <div class="footer">
                <p>This summary was generated by <?php echo esc_html($site_name); ?></p>
                <p>You received this email because you chatted with our assistant on our website.</p>
                <?php if ($reason === 'idle_timeout'): ?>
                    <p style="font-style: italic;">Your conversation was ended due to inactivity.</p>
                <?php endif; ?>
            </div>
        </body>
        </html>
        <?php
        return ob_get_clean();
    }

    /**
     * Get AI provider instance
     *
     * @return MLD_AI_Provider_Base|null Provider instance
     */
    private function get_ai_provider() {
        global $wpdb;

        // Get current provider setting
        $provider_name = $wpdb->get_var($wpdb->prepare(
            "SELECT setting_value FROM {$wpdb->prefix}mld_chat_settings
             WHERE setting_key = %s",
            'ai_provider'
        ));

        if (!$provider_name) {
            return null;
        }

        // Load provider class files
        $provider_classes = array(
            'openai' => 'class-mld-openai-provider.php',
            'claude' => 'class-mld-claude-provider.php',
            'gemini' => 'class-mld-gemini-provider.php',
        );

        if (!isset($provider_classes[$provider_name])) {
            return null;
        }

        $provider_file = MLD_PLUGIN_PATH . 'includes/chatbot/providers/' . $provider_classes[$provider_name];
        if (file_exists($provider_file)) {
            require_once $provider_file;

            $class_map = array(
                'openai' => 'MLD_OpenAI_Provider',
                'claude' => 'MLD_Claude_Provider',
                'gemini' => 'MLD_Gemini_Provider',
            );

            $class_name = $class_map[$provider_name];
            if (class_exists($class_name)) {
                return new $class_name();
            }
        }

        return null;
    }

    /**
     * Check if user summaries are enabled
     *
     * @return bool
     */
    private function are_user_summaries_enabled() {
        global $wpdb;

        $enabled = $wpdb->get_var($wpdb->prepare(
            "SELECT setting_value FROM {$wpdb->prefix}mld_chat_settings
             WHERE setting_key = %s",
            'user_summaries_enabled'
        ));

        return $enabled === '1';
    }

    /**
     * Get summary statistics
     *
     * @param int $days Days to look back (default 7)
     * @return array Statistics
     */
    public function get_statistics($days = 7) {
        global $wpdb;

        $stats = array(
            'total_sent' => $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}mld_chat_email_summaries
                 WHERE delivery_status = 'sent'
                 AND sent_at >= DATE_SUB(NOW(), INTERVAL %d DAY)",
                $days
            )),
            'total_failed' => $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}mld_chat_email_summaries
                 WHERE delivery_status = 'failed'
                 AND created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)",
                $days
            )),
            'pending' => $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->prefix}mld_chat_email_summaries
                 WHERE delivery_status = 'pending'"
            ),
        );

        $stats['success_rate'] = ($stats['total_sent'] + $stats['total_failed']) > 0
            ? round(($stats['total_sent'] / ($stats['total_sent'] + $stats['total_failed'])) * 100, 2)
            : 0;

        return $stats;
    }
}

// Initialize summary generator
global $mld_summary_generator;
$mld_summary_generator = new MLD_Summary_Generator();

/**
 * Get global summary generator instance
 *
 * @return MLD_Summary_Generator
 */
function mld_get_summary_generator() {
    global $mld_summary_generator;
    return $mld_summary_generator;
}
