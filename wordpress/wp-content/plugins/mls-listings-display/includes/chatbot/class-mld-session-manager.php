<?php
/**
 * Session Manager
 *
 * Manages chat sessions, tracks activity, detects idle timeouts,
 * and triggers conversation end events
 *
 * @package MLS_Listings_Display
 * @subpackage Chatbot
 * @since 6.6.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Session_Manager {

    /**
     * Idle timeout in minutes (default from settings)
     *
     * @var int
     */
    private $idle_timeout_minutes;

    /**
     * Constructor
     */
    public function __construct() {
        $this->load_settings();
        $this->init_hooks();
    }

    /**
     * Load settings from database
     */
    private function load_settings() {
        global $wpdb;

        $this->idle_timeout_minutes = $wpdb->get_var($wpdb->prepare(
            "SELECT setting_value FROM {$wpdb->prefix}mld_chat_settings
             WHERE setting_key = %s",
            'idle_timeout_minutes'
        ));

        if (!$this->idle_timeout_minutes) {
            $this->idle_timeout_minutes = 10; // Default 10 minutes
        }
    }

    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        // Register AJAX handlers for session management
        add_action('wp_ajax_mld_session_heartbeat', array($this, 'handle_heartbeat'));
        add_action('wp_ajax_nopriv_mld_session_heartbeat', array($this, 'handle_heartbeat'));

        add_action('wp_ajax_mld_session_close', array($this, 'handle_session_close'));
        add_action('wp_ajax_nopriv_mld_session_close', array($this, 'handle_session_close'));

        // Listen for conversation end events
        add_action('mld_chat_conversation_ended', array($this, 'on_conversation_ended'));
    }

    /**
     * Create or update session
     *
     * @param string $session_id Session ID
     * @param int $conversation_id Conversation ID
     * @param array $session_data Additional session data
     * @return int|false Session ID or false on failure
     */
    public function create_or_update_session($session_id, $conversation_id = null, $session_data = array()) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'mld_chat_sessions';

        // Check if session exists
        $existing_session = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE session_id = %s",
            $session_id
        ), ARRAY_A);

        $data = array(
            'session_id' => $session_id,
            'last_activity_at' => current_time('mysql'),
            'session_status' => 'active',
        );

        if ($conversation_id) {
            $data['conversation_id'] = $conversation_id;
        }

        // Add optional session data
        if (isset($session_data['page_url'])) {
            $data['page_url'] = esc_url_raw($session_data['page_url']);
        }
        if (isset($session_data['referrer_url'])) {
            $data['referrer_url'] = esc_url_raw($session_data['referrer_url']);
        }
        if (isset($session_data['device_type'])) {
            $data['device_type'] = sanitize_text_field($session_data['device_type']);
        }
        if (isset($session_data['browser'])) {
            $data['browser'] = sanitize_text_field($session_data['browser']);
        }
        if (isset($session_data['session_data'])) {
            $data['session_data'] = wp_json_encode($session_data['session_data']);
        }

        if ($existing_session) {
            // Update existing session
            $result = $wpdb->update(
                $table_name,
                $data,
                array('session_id' => $session_id)
            );
            return $result !== false ? $existing_session['id'] : false;
        } else {
            // Insert new session
            $data['idle_timeout_minutes'] = $this->idle_timeout_minutes;
            $result = $wpdb->insert($table_name, $data);
            return $result ? $wpdb->insert_id : false;
        }
    }

    /**
     * Update session activity (heartbeat)
     *
     * @param string $session_id Session ID
     * @return bool Success status
     */
    public function update_activity($session_id) {
        global $wpdb;

        $result = $wpdb->update(
            $wpdb->prefix . 'mld_chat_sessions',
            array('last_activity_at' => current_time('mysql')),
            array('session_id' => $session_id),
            array('%s'),
            array('%s')
        );

        return $result !== false;
    }

    /**
     * Mark session as closed
     *
     * @param string $session_id Session ID
     * @param string $reason Close reason (user_closed, idle_timeout, etc.)
     * @return bool Success status
     */
    public function close_session($session_id, $reason = 'user_closed') {
        global $wpdb;

        $result = $wpdb->update(
            $wpdb->prefix . 'mld_chat_sessions',
            array(
                'session_status' => 'closed',
                'window_closed' => 1,
                'window_closed_at' => current_time('mysql'),
            ),
            array('session_id' => $session_id),
            array('%s', '%d', '%s'),
            array('%s')
        );

        if ($result !== false) {
            // Also close the conversation
            $this->close_conversation($session_id, $reason);

            // Trigger conversation ended event
            do_action('mld_chat_conversation_ended', $session_id, $reason);
        }

        return $result !== false;
    }

    /**
     * Close associated conversation
     *
     * @param string $session_id Session ID
     * @param string $reason Close reason
     */
    private function close_conversation($session_id, $reason) {
        global $wpdb;

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

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("[MLD Session Manager] Conversation closed for session {$session_id}. Reason: {$reason}");
        }
    }

    /**
     * Check for idle sessions and close them
     *
     * Called by WP-Cron hourly
     *
     * @return array Results with count of closed sessions
     */
    public function check_idle_sessions() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'mld_chat_sessions';

        // Find sessions that have been inactive longer than timeout
        // Use current_time('mysql') for WordPress timezone consistency
        $timeout_minutes = $this->idle_timeout_minutes;
        $wp_now = current_time('mysql');
        $idle_sessions = $wpdb->get_results($wpdb->prepare(
            "SELECT session_id, TIMESTAMPDIFF(MINUTE, last_activity_at, %s) as idle_minutes
             FROM {$table_name}
             WHERE session_status = 'active'
             AND TIMESTAMPDIFF(MINUTE, last_activity_at, %s) >= %d",
            $wp_now,
            $wp_now,
            $timeout_minutes
        ), ARRAY_A);

        $closed_count = 0;
        foreach ($idle_sessions as $session) {
            $this->close_session($session['session_id'], 'idle_timeout');
            $closed_count++;

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("[MLD Session Manager] Closed idle session {$session['session_id']} after {$session['idle_minutes']} minutes");
            }
        }

        return array(
            'success' => true,
            'closed_sessions' => $closed_count,
            'timeout_minutes' => $timeout_minutes,
        );
    }

    /**
     * Get session by ID
     *
     * @param string $session_id Session ID
     * @return array|null Session data
     */
    public function get_session($session_id) {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}mld_chat_sessions WHERE session_id = %s",
            $session_id
        ), ARRAY_A);
    }

    /**
     * Get active session count
     *
     * @return int Active session count
     */
    public function get_active_session_count() {
        global $wpdb;

        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}mld_chat_sessions
             WHERE session_status = 'active'"
        );
    }

    /**
     * Cleanup old sessions
     *
     * Removes sessions older than specified days
     *
     * @param int $days Days to keep (default 90)
     * @return int Number of deleted sessions
     */
    public function cleanup_old_sessions($days = 90) {
        global $wpdb;

        // Use current_time('mysql') for WordPress timezone consistency
        $wp_now = current_time('mysql');
        $result = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}mld_chat_sessions
             WHERE session_status = 'closed'
             AND window_closed_at < DATE_SUB(%s, INTERVAL %d DAY)",
            $wp_now,
            $days
        ));

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("[MLD Session Manager] Cleaned up {$result} old sessions (>{$days} days)");
        }

        return $result;
    }

    /**
     * Handle heartbeat AJAX request
     *
     * Frontend sends this periodically to keep session alive
     */
    public function handle_heartbeat() {
        check_ajax_referer('mld_chatbot_nonce', 'nonce');

        $session_id = isset($_POST['session_id']) ? sanitize_text_field($_POST['session_id']) : '';

        if (empty($session_id)) {
            wp_send_json_error(array('message' => 'Session ID required'));
        }

        $success = $this->update_activity($session_id);

        if ($success) {
            wp_send_json_success(array(
                'message' => 'Session updated',
                'timeout_minutes' => $this->idle_timeout_minutes,
            ));
        } else {
            wp_send_json_error(array('message' => 'Failed to update session'));
        }
    }

    /**
     * Handle session close AJAX request
     *
     * Called when user closes chat window
     */
    public function handle_session_close() {
        check_ajax_referer('mld_chatbot_nonce', 'nonce');

        $session_id = isset($_POST['session_id']) ? sanitize_text_field($_POST['session_id']) : '';

        if (empty($session_id)) {
            wp_send_json_error(array('message' => 'Session ID required'));
        }

        $success = $this->close_session($session_id, 'user_closed');

        if ($success) {
            wp_send_json_success(array('message' => 'Session closed'));
        } else {
            wp_send_json_error(array('message' => 'Failed to close session'));
        }
    }

    /**
     * Handle conversation ended event
     *
     * Triggered when a conversation ends (idle timeout or user closed)
     *
     * @param string $session_id Session ID
     * @param string $reason Close reason
     */
    public function on_conversation_ended($session_id, $reason = 'unknown') {
        global $wpdb;

        // Get conversation details
        $conversation = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}mld_chat_conversations
             WHERE session_id = %s
             ORDER BY id DESC
             LIMIT 1",
            $session_id
        ), ARRAY_A);

        if (!$conversation) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("[MLD Session Manager] No conversation found for session {$session_id}");
            }
            return;
        }

        // Check if summary already sent
        if ($conversation['summary_sent'] == 1) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("[MLD Session Manager] Summary already sent for conversation {$conversation['id']}");
            }
            return;
        }

        // Only send summary if we have user email
        if (empty($conversation['user_email'])) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("[MLD Session Manager] No email for conversation {$conversation['id']}, skipping summary");
            }
            return;
        }

        // Trigger summary email generation
        do_action('mld_chat_send_summary_email', $conversation['id'], $reason);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("[MLD Session Manager] Summary email triggered for conversation {$conversation['id']}");
        }
    }

    /**
     * Get session statistics
     *
     * @return array Statistics
     */
    public function get_statistics() {
        global $wpdb;

        // Use wp_date() for WordPress timezone consistency
        $today = wp_date('Y-m-d');
        $week_ago = wp_date('Y-m-d', current_time('timestamp') - (7 * DAY_IN_SECONDS));

        $stats = array(
            'active_sessions' => $this->get_active_session_count(),
            'total_sessions_today' => $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}mld_chat_sessions
                 WHERE DATE(created_at) = %s",
                $today
            )),
            'avg_session_duration' => $wpdb->get_var($wpdb->prepare(
                "SELECT AVG(TIMESTAMPDIFF(MINUTE, created_at, window_closed_at))
                 FROM {$wpdb->prefix}mld_chat_sessions
                 WHERE session_status = 'closed'
                 AND window_closed_at IS NOT NULL
                 AND DATE(created_at) >= %s",
                $week_ago
            )),
            'idle_timeout_rate' => $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}mld_chat_conversations
                 WHERE conversation_status = 'closed'
                 AND ended_at IS NOT NULL
                 AND DATE(started_at) >= %s",
                $week_ago
            )),
        );

        $stats['avg_session_duration'] = $stats['avg_session_duration'] ? round($stats['avg_session_duration'], 1) : 0;

        return $stats;
    }
}

// Initialize session manager
global $mld_session_manager;
$mld_session_manager = new MLD_Session_Manager();

/**
 * Get global session manager instance
 *
 * @return MLD_Session_Manager
 */
function mld_get_session_manager() {
    global $mld_session_manager;
    return $mld_session_manager;
}
