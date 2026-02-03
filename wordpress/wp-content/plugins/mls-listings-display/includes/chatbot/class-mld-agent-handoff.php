<?php
/**
 * Agent Handoff and Notification System
 *
 * Manages the handoff from chatbot to human agents, including
 * agent assignment, notifications, and lead tracking.
 *
 * @package MLS_Listings_Display
 * @subpackage Chatbot
 * @since 6.7.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Agent_Handoff {

    /**
     * Assignment methods
     */
    const ASSIGNMENT_AUTOMATIC = 'automatic';
    const ASSIGNMENT_ROUND_ROBIN = 'round_robin';
    const ASSIGNMENT_AVAILABILITY = 'availability';
    const ASSIGNMENT_MANUAL = 'manual';

    /**
     * Lead status values
     */
    const LEAD_NEW = 'new';
    const LEAD_CONTACTED = 'contacted';
    const LEAD_QUALIFIED = 'qualified';
    const LEAD_CONVERTED = 'converted';
    const LEAD_LOST = 'lost';

    /**
     * Notification channels
     */
    const NOTIFY_EMAIL = 'email';
    const NOTIFY_SMS = 'sms';
    const NOTIFY_WEBHOOK = 'webhook';
    const NOTIFY_DASHBOARD = 'dashboard';

    /**
     * Default response time expectations (seconds)
     */
    const RESPONSE_TIME_URGENT = 300;     // 5 minutes
    const RESPONSE_TIME_NORMAL = 900;     // 15 minutes
    const RESPONSE_TIME_LOW = 3600;       // 1 hour

    /**
     * Constructor
     */
    public function __construct() {
        // Register AJAX handlers
        add_action('wp_ajax_mld_assign_agent', array($this, 'ajax_assign_agent'));
        add_action('wp_ajax_mld_agent_respond', array($this, 'ajax_agent_respond'));
        add_action('wp_ajax_mld_update_lead_status', array($this, 'ajax_update_lead_status'));

        // Register notification hooks
        add_action('mld_agent_assigned', array($this, 'send_agent_notification'), 10, 2);
        add_action('mld_lead_received', array($this, 'process_new_lead'), 10, 2);
    }

    /**
     * Request agent for conversation
     *
     * @param int $conversation_id Conversation ID
     * @param array $collected_info Collected user information
     * @param string $urgency Urgency level
     * @return array Assignment result
     */
    public function requestAgent($conversation_id, $collected_info, $urgency = 'normal') {
        global $wpdb;

        // Check if already assigned
        $existing = $this->getAssignment($conversation_id);
        if ($existing && $existing['agent_id']) {
            return array(
                'success' => true,
                'agent_id' => $existing['agent_id'],
                'agent_name' => $existing['agent_name'],
                'already_assigned' => true
            );
        }

        // Determine assignment method
        $assignment_method = get_option('mld_agent_assignment_method', self::ASSIGNMENT_ROUND_ROBIN);

        // Select agent based on method
        $agent = $this->selectAgent($assignment_method, $collected_info, $urgency);

        if (!$agent) {
            // Fallback to any available agent
            $agent = $this->getAnyAvailableAgent();
        }

        if (!$agent) {
            return array(
                'success' => false,
                'error' => 'No agents available',
                'fallback' => $this->getFallbackContact()
            );
        }

        // Create assignment
        $assignment = $this->createAssignment($conversation_id, $agent, $collected_info, $urgency);

        if ($assignment) {
            // Trigger notification
            do_action('mld_agent_assigned', $assignment['id'], $agent);

            return array(
                'success' => true,
                'agent_id' => $agent['agent_id'],
                'agent_name' => $agent['agent_name'],
                'assignment_id' => $assignment['id'],
                'expected_response_time' => $this->getExpectedResponseTime($urgency)
            );
        }

        return array(
            'success' => false,
            'error' => 'Failed to create assignment'
        );
    }

    /**
     * Select agent based on assignment method
     *
     * @param string $method Assignment method
     * @param array $collected_info User information
     * @param string $urgency Urgency level
     * @return array|null Agent data
     */
    private function selectAgent($method, $collected_info, $urgency) {
        switch ($method) {
            case self::ASSIGNMENT_ROUND_ROBIN:
                return $this->selectRoundRobinAgent();

            case self::ASSIGNMENT_AVAILABILITY:
                return $this->selectAvailableAgent($urgency);

            case self::ASSIGNMENT_AUTOMATIC:
                return $this->selectBestMatchAgent($collected_info);

            default:
                return $this->getDefaultAgent();
        }
    }

    /**
     * Select agent using round-robin method
     *
     * @return array|null Agent data
     */
    private function selectRoundRobinAgent() {
        global $wpdb;

        // Get all active agents
        $agents = $this->getActiveAgents();
        if (empty($agents)) {
            return null;
        }

        // Get last assigned agent ID
        $last_assigned = get_option('mld_last_assigned_agent_id', 0);

        // Find next agent in rotation
        $next_agent = null;
        $found_last = false;

        foreach ($agents as $agent) {
            if ($found_last) {
                $next_agent = $agent;
                break;
            }
            if ($agent['agent_id'] == $last_assigned) {
                $found_last = true;
            }
        }

        // If no next agent (end of list or last not found), use first agent
        if (!$next_agent) {
            $next_agent = $agents[0];
        }

        // Update last assigned
        update_option('mld_last_assigned_agent_id', $next_agent['agent_id']);

        return $next_agent;
    }

    /**
     * Select available agent based on current workload
     *
     * @param string $urgency Urgency level
     * @return array|null Agent data
     */
    private function selectAvailableAgent($urgency) {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_chat_agent_assignments';

        // Get agent workload (active conversations in last 24 hours)
        // Use current_time('mysql') for WordPress timezone consistency
        $wp_now = current_time('mysql');
        $workload_query = $wpdb->prepare("SELECT
                            a.agent_id,
                            a.agent_name,
                            a.agent_email,
                            a.agent_phone,
                            COUNT(aa.id) as active_conversations,
                            AVG(aa.response_time_seconds) as avg_response_time
                          FROM {$wpdb->prefix}bme_agents a
                          LEFT JOIN {$table} aa ON a.agent_id = aa.agent_id
                            AND aa.created_at >= DATE_SUB(%s, INTERVAL 24 HOUR)
                            AND aa.lead_status NOT IN ('converted', 'lost')
                          GROUP BY a.agent_id
                          ORDER BY active_conversations ASC, avg_response_time ASC
                          LIMIT 5", $wp_now);

        $agents = $wpdb->get_results($workload_query, ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- already prepared above

        if (empty($agents)) {
            return null;
        }

        // Check business hours if urgent
        if ($urgency === 'urgent') {
            foreach ($agents as $agent) {
                if ($this->isAgentAvailable($agent['agent_id'])) {
                    return $agent;
                }
            }
        }

        // Return agent with lowest workload
        return $agents[0];
    }

    /**
     * Select best matching agent based on criteria
     *
     * @param array $collected_info User information
     * @return array|null Agent data
     */
    private function selectBestMatchAgent($collected_info) {
        global $wpdb;

        // Get property interest if available
        $property_interest = !empty($collected_info['property_interest']) ?
                           $collected_info['property_interest'] : null;

        if ($property_interest) {
            // Try to match agent who listed the property
            $listing_agent = $this->getListingAgent($property_interest);
            if ($listing_agent) {
                return $listing_agent;
            }

            // Try to match agent specializing in that area
            $area_agent = $this->getAreaSpecialist($property_interest);
            if ($area_agent) {
                return $area_agent;
            }
        }

        // Check for language preferences
        if (!empty($collected_info['preferred_language'])) {
            $language_agent = $this->getLanguageMatchAgent($collected_info['preferred_language']);
            if ($language_agent) {
                return $language_agent;
            }
        }

        // Default to performance-based selection
        return $this->getTopPerformingAgent();
    }

    /**
     * Get listing agent for a property
     *
     * @param string $listing_id Listing ID
     * @return array|null Agent data
     */
    private function getListingAgent($listing_id) {
        global $wpdb;

        $query = $wpdb->prepare(
            "SELECT a.*
             FROM {$wpdb->prefix}bme_agents a
             JOIN {$wpdb->prefix}bme_listings l ON a.agent_id = l.listing_agent_id
             WHERE l.listing_id = %s",
            $listing_id
        );

        $agent = $wpdb->get_row($query, ARRAY_A);
        return $agent ?: null;
    }

    /**
     * Get agent specializing in an area
     *
     * @param string $area Area/property identifier
     * @return array|null Agent data
     */
    private function getAreaSpecialist($area) {
        global $wpdb;

        // Extract city from property ID or use as-is
        $city = $area;
        if (strpos($area, '-') !== false || is_numeric($area)) {
            // Looks like a listing ID, get city
            $city = $wpdb->get_var($wpdb->prepare(
                "SELECT city FROM {$wpdb->prefix}bme_listings WHERE listing_id = %s",
                $area
            ));
        }

        if (!$city) {
            return null;
        }

        // Find agent with most listings in that city
        $query = $wpdb->prepare(
            "SELECT a.*, COUNT(l.listing_id) as listing_count
             FROM {$wpdb->prefix}bme_agents a
             JOIN {$wpdb->prefix}bme_listings l ON a.agent_id = l.listing_agent_id
             WHERE l.city = %s AND l.standard_status = 'Active'
             GROUP BY a.agent_id
             ORDER BY listing_count DESC
             LIMIT 1",
            $city
        );

        $agent = $wpdb->get_row($query, ARRAY_A);
        return $agent ?: null;
    }

    /**
     * Get agent matching language preference
     *
     * @param string $language Language code
     * @return array|null Agent data
     */
    private function getLanguageMatchAgent($language) {
        global $wpdb;

        // Check agent metadata for language skills
        $agents_table = $wpdb->prefix . 'mld_agents';

        $query = $wpdb->prepare(
            "SELECT a.*, ma.languages
             FROM {$wpdb->prefix}bme_agents a
             LEFT JOIN {$agents_table} ma ON a.agent_id = ma.agent_id
             WHERE ma.languages LIKE %s
             LIMIT 1",
            '%' . $wpdb->esc_like($language) . '%'
        );

        $agent = $wpdb->get_row($query, ARRAY_A);
        return $agent ?: null;
    }

    /**
     * Get top performing agent
     *
     * @return array|null Agent data
     */
    private function getTopPerformingAgent() {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_chat_agent_assignments';

        // Get agent with best conversion rate in last 30 days
        // Use current_time('mysql') for WordPress timezone consistency
        $wp_now = current_time('mysql');
        $query = $wpdb->prepare("SELECT
                    a.*,
                    COUNT(aa.id) as total_leads,
                    SUM(CASE WHEN aa.lead_status = 'converted' THEN 1 ELSE 0 END) as conversions,
                    AVG(aa.response_time_seconds) as avg_response_time
                  FROM {$wpdb->prefix}bme_agents a
                  LEFT JOIN {$table} aa ON a.agent_id = aa.agent_id
                    AND aa.created_at >= DATE_SUB(%s, INTERVAL 30 DAY)
                  GROUP BY a.agent_id
                  HAVING total_leads > 0
                  ORDER BY (conversions / total_leads) DESC, avg_response_time ASC
                  LIMIT 1", $wp_now);

        $agent = $wpdb->get_row($query, ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- already prepared above
        return $agent ?: null;
    }

    /**
     * Get all active agents
     *
     * @return array Agents list
     */
    private function getActiveAgents() {
        global $wpdb;

        // Get agents with recent activity
        $query = "SELECT DISTINCT a.*
                  FROM {$wpdb->prefix}bme_agents a
                  WHERE EXISTS (
                      SELECT 1 FROM {$wpdb->prefix}bme_listings l
                      WHERE l.listing_agent_id = a.agent_id
                      AND l.standard_status = 'Active'
                  )
                  ORDER BY a.agent_name";

        return $wpdb->get_results($query, ARRAY_A);
    }

    /**
     * Get any available agent as fallback
     *
     * @return array|null Agent data
     */
    private function getAnyAvailableAgent() {
        $agents = $this->getActiveAgents();
        return !empty($agents) ? $agents[0] : null;
    }

    /**
     * Get default agent
     *
     * @return array|null Agent data
     */
    private function getDefaultAgent() {
        $default_agent_id = get_option('mld_default_agent_id');

        if ($default_agent_id) {
            global $wpdb;
            $agent = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}bme_agents WHERE agent_id = %s",
                $default_agent_id
            ), ARRAY_A);

            if ($agent) {
                return $agent;
            }
        }

        return $this->getAnyAvailableAgent();
    }

    /**
     * Check if agent is currently available
     *
     * @param string $agent_id Agent ID
     * @return bool Is available
     */
    private function isAgentAvailable($agent_id) {
        // Check business hours
        $current_hour = date('G');
        $current_day = date('N'); // 1 (Monday) to 7 (Sunday)

        // Default business hours (9 AM - 6 PM, Mon-Fri)
        if ($current_day <= 5 && $current_hour >= 9 && $current_hour < 18) {
            return true;
        }

        // Check custom availability
        $availability = get_option('mld_agent_availability_' . $agent_id, array());
        if (!empty($availability[$current_day])) {
            $hours = $availability[$current_day];
            if ($current_hour >= $hours['start'] && $current_hour < $hours['end']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Create agent assignment
     *
     * @param int $conversation_id Conversation ID
     * @param array $agent Agent data
     * @param array $collected_info User information
     * @param string $urgency Urgency level
     * @return array|false Assignment data or false
     */
    private function createAssignment($conversation_id, $agent, $collected_info, $urgency) {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_chat_agent_assignments';

        // Prepare assignment data
        $data = array(
            'conversation_id' => $conversation_id,
            'agent_id' => $agent['agent_id'],
            'agent_name' => $agent['agent_name'],
            'agent_email' => $agent['agent_email'],
            'agent_phone' => !empty($agent['agent_phone']) ? $agent['agent_phone'] : null,
            'assignment_type' => get_option('mld_agent_assignment_method', self::ASSIGNMENT_ROUND_ROBIN),
            'assignment_reason' => $this->generateAssignmentReason($agent, $collected_info),
            'lead_status' => self::LEAD_NEW,
            'created_at' => current_time('mysql')
        );

        $result = $wpdb->insert($table, $data);

        if ($result) {
            $assignment_id = $wpdb->insert_id;

            // Update conversation with agent assignment
            $wpdb->update(
                $wpdb->prefix . 'mld_chatbot_conversations',
                array(
                    'agent_assigned_id' => $agent['agent_id'],
                    'agent_assigned_at' => current_time('mysql')
                ),
                array('id' => $conversation_id),
                array('%s', '%s'),
                array('%d')
            );

            // Create lead record
            $this->createLeadRecord($conversation_id, $collected_info, $agent['agent_id']);

            return array_merge($data, array('id' => $assignment_id));
        }

        return false;
    }

    /**
     * Generate assignment reason
     *
     * @param array $agent Agent data
     * @param array $collected_info User information
     * @return string Reason text
     */
    private function generateAssignmentReason($agent, $collected_info) {
        $reasons = array();

        if (!empty($collected_info['property_interest'])) {
            $reasons[] = 'Property interest: ' . $collected_info['property_interest'];
        }

        if (!empty($agent['listing_count'])) {
            $reasons[] = 'Agent has ' . $agent['listing_count'] . ' active listings';
        }

        if (!empty($agent['specialties'])) {
            $reasons[] = 'Specializes in: ' . $agent['specialties'];
        }

        return !empty($reasons) ? implode('; ', $reasons) : 'Standard assignment';
    }

    /**
     * Create lead record
     *
     * @param int $conversation_id Conversation ID
     * @param array $collected_info User information
     * @param string $agent_id Agent ID
     * @return bool Success
     */
    private function createLeadRecord($conversation_id, $collected_info, $agent_id) {
        // Trigger lead creation hook for CRM integration
        do_action('mld_lead_received', $conversation_id, array(
            'user_info' => $collected_info,
            'agent_id' => $agent_id,
            'source' => 'chatbot',
            'timestamp' => current_time('mysql')
        ));

        return true;
    }

    /**
     * Send agent notification
     *
     * @param int $assignment_id Assignment ID
     * @param array $agent Agent data
     */
    public function send_agent_notification($assignment_id, $agent) {
        global $wpdb;

        // Get assignment details
        $table = $wpdb->prefix . 'mld_chat_agent_assignments';
        $assignment = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d",
            $assignment_id
        ), ARRAY_A);

        if (!$assignment) {
            return;
        }

        // Get conversation details
        $conversation = $this->getConversationDetails($assignment['conversation_id']);

        // Get notification preferences
        $notify_methods = get_option('mld_agent_notification_methods', array(self::NOTIFY_EMAIL));

        foreach ($notify_methods as $method) {
            switch ($method) {
                case self::NOTIFY_EMAIL:
                    $this->sendEmailNotification($agent, $assignment, $conversation);
                    break;

                case self::NOTIFY_SMS:
                    $this->sendSMSNotification($agent, $assignment, $conversation);
                    break;

                case self::NOTIFY_WEBHOOK:
                    $this->sendWebhookNotification($agent, $assignment, $conversation);
                    break;

                case self::NOTIFY_DASHBOARD:
                    $this->createDashboardNotification($agent, $assignment, $conversation);
                    break;
            }
        }

        // Update notification sent status
        $wpdb->update(
            $table,
            array(
                'notification_sent' => 1,
                'notification_sent_at' => current_time('mysql')
            ),
            array('id' => $assignment_id),
            array('%d', '%s'),
            array('%d')
        );
    }

    /**
     * Send email notification to agent
     *
     * @param array $agent Agent data
     * @param array $assignment Assignment data
     * @param array $conversation Conversation data
     */
    private function sendEmailNotification($agent, $assignment, $conversation) {
        $to = $agent['agent_email'];
        $subject = '[URGENT] New Lead from Chat - ' . $conversation['user_name'];

        $message = $this->generateEmailContent($agent, $assignment, $conversation);

        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>',
            'Reply-To: ' . $conversation['user_email']
        );

        wp_mail($to, $subject, $message, $headers);
    }

    /**
     * Generate email content
     *
     * @param array $agent Agent data
     * @param array $assignment Assignment data
     * @param array $conversation Conversation data
     * @return string Email HTML
     */
    private function generateEmailContent($agent, $assignment, $conversation) {
        $admin_url = admin_url('admin.php?page=mld-chatbot&tab=conversations&id=' . $conversation['id']);

        $html = '<html><body>';
        $html .= '<h2>New Lead from Website Chat</h2>';

        $html .= '<h3>Contact Information:</h3>';
        $html .= '<ul>';
        $html .= '<li><strong>Name:</strong> ' . esc_html($conversation['user_name']) . '</li>';
        $html .= '<li><strong>Phone:</strong> ' . esc_html($conversation['user_phone']) . '</li>';
        $html .= '<li><strong>Email:</strong> ' . esc_html($conversation['user_email']) . '</li>';
        $html .= '</ul>';

        if (!empty($conversation['property_interest'])) {
            $html .= '<h3>Property Interest:</h3>';
            $html .= '<p>' . esc_html($conversation['property_interest']) . '</p>';
        }

        if (!empty($conversation['has_property_to_sell'])) {
            $html .= '<p><strong>Has property to sell:</strong> Yes</p>';
        }

        $html .= '<h3>Conversation Summary:</h3>';
        $html .= '<p>' . nl2br(esc_html($conversation['summary'])) . '</p>';

        $html .= '<h3>Action Required:</h3>';
        $html .= '<p>Please contact this lead within 15 minutes.</p>';

        $html .= '<p><a href="' . $admin_url . '" style="background: #0073aa; color: white; padding: 10px 20px; text-decoration: none; border-radius: 3px;">View Full Conversation</a></p>';

        $html .= '</body></html>';

        return $html;
    }

    /**
     * Send SMS notification to agent
     *
     * @param array $agent Agent data
     * @param array $assignment Assignment data
     * @param array $conversation Conversation data
     */
    private function sendSMSNotification($agent, $assignment, $conversation) {
        // Integrate with SMS provider (Twilio, etc.)
        $phone = $agent['agent_phone'];
        if (empty($phone)) {
            return;
        }

        $message = sprintf(
            "New lead: %s (%s). Property interest: %s. Contact ASAP!",
            $conversation['user_name'],
            $conversation['user_phone'],
            $conversation['property_interest'] ?: 'General inquiry'
        );

        // Trigger SMS hook for external integration
        do_action('mld_send_sms', $phone, $message);
    }

    /**
     * Send webhook notification
     *
     * @param array $agent Agent data
     * @param array $assignment Assignment data
     * @param array $conversation Conversation data
     */
    private function sendWebhookNotification($agent, $assignment, $conversation) {
        $webhook_url = get_option('mld_agent_webhook_url');
        if (empty($webhook_url)) {
            return;
        }

        $payload = array(
            'event' => 'lead_assigned',
            'agent' => $agent,
            'assignment' => $assignment,
            'conversation' => $conversation,
            'timestamp' => current_time('c')
        );

        wp_remote_post($webhook_url, array(
            'body' => json_encode($payload),
            'headers' => array('Content-Type' => 'application/json'),
            'timeout' => 10
        ));
    }

    /**
     * Create dashboard notification
     *
     * @param array $agent Agent data
     * @param array $assignment Assignment data
     * @param array $conversation Conversation data
     */
    private function createDashboardNotification($agent, $assignment, $conversation) {
        // Store notification for dashboard display
        $notifications = get_option('mld_agent_notifications_' . $agent['agent_id'], array());

        $notifications[] = array(
            'type' => 'new_lead',
            'assignment_id' => $assignment['id'],
            'conversation_id' => $conversation['id'],
            'user_name' => $conversation['user_name'],
            'created_at' => current_time('mysql'),
            'read' => false
        );

        // Keep only last 50 notifications
        $notifications = array_slice($notifications, -50);

        update_option('mld_agent_notifications_' . $agent['agent_id'], $notifications);
    }

    /**
     * Get conversation details
     *
     * @param int $conversation_id Conversation ID
     * @return array Conversation data
     */
    private function getConversationDetails($conversation_id) {
        global $wpdb;

        $conversation = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}mld_chatbot_conversations WHERE id = %d",
            $conversation_id
        ), ARRAY_A);

        if ($conversation) {
            // Decode collected info
            $collected_info = json_decode($conversation['collected_info'], true) ?: array();

            // Merge with conversation data
            $conversation = array_merge($conversation, array(
                'user_name' => $collected_info['name'] ?? 'Unknown',
                'user_phone' => $collected_info['phone'] ?? '',
                'user_email' => $collected_info['email'] ?? '',
                'has_property_to_sell' => $collected_info['has_property_to_sell'] ?? false,
                'property_interest' => $conversation['property_interest'] ?? '',
                'summary' => $this->generateConversationSummary($conversation_id)
            ));
        }

        return $conversation;
    }

    /**
     * Generate conversation summary
     *
     * @param int $conversation_id Conversation ID
     * @return string Summary
     */
    private function generateConversationSummary($conversation_id) {
        global $wpdb;

        // Get recent messages
        $messages = $wpdb->get_results($wpdb->prepare(
            "SELECT role, message FROM {$wpdb->prefix}mld_chatbot_messages
             WHERE conversation_id = %d
             ORDER BY created_at DESC
             LIMIT 10",
            $conversation_id
        ), ARRAY_A);

        $summary = "";
        foreach (array_reverse($messages) as $msg) {
            $role = $msg['role'] === 'user' ? 'User' : 'Bot';
            $summary .= $role . ": " . $msg['message'] . "\n";
        }

        return $summary;
    }

    /**
     * Get expected response time
     *
     * @param string $urgency Urgency level
     * @return string Response time text
     */
    private function getExpectedResponseTime($urgency) {
        switch ($urgency) {
            case 'urgent':
                return '5 minutes';
            case 'high':
                return '15 minutes';
            case 'normal':
                return '30 minutes';
            default:
                return '1 hour';
        }
    }

    /**
     * Get fallback contact information
     *
     * @return array Fallback contact
     */
    private function getFallbackContact() {
        return array(
            'phone' => get_option('mld_business_phone', ''),
            'email' => get_option('admin_email'),
            'message' => 'All agents are currently busy. Please call us directly or we will contact you as soon as possible.'
        );
    }

    /**
     * Get assignment for conversation
     *
     * @param int $conversation_id Conversation ID
     * @return array|null Assignment data
     */
    public function getAssignment($conversation_id) {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_chat_agent_assignments';
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE conversation_id = %d",
            $conversation_id
        ), ARRAY_A);
    }

    /**
     * Mark agent as responded
     *
     * @param int $assignment_id Assignment ID
     * @return bool Success
     */
    public function markAgentResponded($assignment_id) {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_chat_agent_assignments';

        // Get assignment
        $assignment = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d",
            $assignment_id
        ), ARRAY_A);

        if (!$assignment) {
            return false;
        }

        // Calculate response time
        $response_time = time() - strtotime($assignment['created_at']);

        // Update assignment
        $result = $wpdb->update(
            $table,
            array(
                'agent_responded' => 1,
                'agent_responded_at' => current_time('mysql'),
                'response_time_seconds' => $response_time,
                'lead_status' => self::LEAD_CONTACTED
            ),
            array('id' => $assignment_id),
            array('%d', '%s', '%d', '%s'),
            array('%d')
        );

        // Update conversation
        if ($result) {
            $wpdb->update(
                $wpdb->prefix . 'mld_chatbot_conversations',
                array('agent_connected_at' => current_time('mysql')),
                array('id' => $assignment['conversation_id']),
                array('%s'),
                array('%d')
            );
        }

        return $result !== false;
    }

    /**
     * Update lead status
     *
     * @param int $assignment_id Assignment ID
     * @param string $status New status
     * @param string $notes Optional notes
     * @return bool Success
     */
    public function updateLeadStatus($assignment_id, $status, $notes = '') {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_chat_agent_assignments';

        $data = array(
            'lead_status' => $status,
            'updated_at' => current_time('mysql')
        );

        if ($notes) {
            $data['lead_notes'] = $notes;
        }

        $result = $wpdb->update(
            $table,
            $data,
            array('id' => $assignment_id),
            array('%s', '%s', '%s'),
            array('%d')
        );

        return $result !== false;
    }

    /**
     * AJAX handler for agent assignment
     */
    public function ajax_assign_agent() {
        check_ajax_referer('mld_chatbot_nonce', 'nonce');

        $conversation_id = intval($_POST['conversation_id']);
        $urgency = sanitize_text_field($_POST['urgency'] ?? 'normal');

        $result = $this->requestAgent($conversation_id, array(), $urgency);

        wp_send_json($result);
    }

    /**
     * AJAX handler for agent response
     */
    public function ajax_agent_respond() {
        check_ajax_referer('mld_chatbot_nonce', 'nonce');

        $assignment_id = intval($_POST['assignment_id']);
        $result = $this->markAgentResponded($assignment_id);

        wp_send_json_success(array('updated' => $result));
    }

    /**
     * AJAX handler for lead status update
     */
    public function ajax_update_lead_status() {
        check_ajax_referer('mld_chatbot_nonce', 'nonce');

        $assignment_id = intval($_POST['assignment_id']);
        $status = sanitize_text_field($_POST['status']);
        $notes = sanitize_textarea_field($_POST['notes'] ?? '');

        $result = $this->updateLeadStatus($assignment_id, $status, $notes);

        wp_send_json_success(array('updated' => $result));
    }

    /**
     * Process new lead
     *
     * @param int $conversation_id Conversation ID
     * @param array $lead_data Lead data
     */
    public function process_new_lead($conversation_id, $lead_data) {
        // Hook for CRM integration
        // External systems can hook here to sync leads
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[Agent Handoff] New lead received: ' . json_encode($lead_data));
        }
    }
}