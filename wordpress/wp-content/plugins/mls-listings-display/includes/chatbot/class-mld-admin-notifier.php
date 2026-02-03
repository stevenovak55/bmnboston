<?php
/**
 * Admin Notification Manager
 *
 * Processes and sends email notifications to admins after each user message
 *
 * @package MLS_Listings_Display
 * @subpackage Chatbot
 * @since 6.6.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Admin_Notifier {

    /**
     * Maximum retry attempts for failed notifications
     *
     * @var int
     */
    const MAX_RETRY_ATTEMPTS = 3;

    /**
     * Constructor
     */
    public function __construct() {
        // Register WP-Cron hook for processing queued notifications
        add_action('mld_process_admin_notifications', array($this, 'process_notification_queue'));
    }

    /**
     * Send immediate notification to admin about new user message
     *
     * Called directly from chatbot engine after each message
     *
     * @param int $conversation_id Conversation ID
     * @param int $user_message_id User message ID
     * @param int $ai_message_id AI response message ID
     * @return bool Success status
     */
    public function send_immediate_notification($conversation_id, $user_message_id, $ai_message_id) {
        global $wpdb;

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log("[MLD Admin Notifier] send_immediate_notification called - Conv: {$conversation_id}, User Msg: {$user_message_id}, AI Msg: {$ai_message_id}");
        }

        // Get conversation details
        $conversation = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}mld_chat_conversations WHERE id = %d",
            $conversation_id
        ), ARRAY_A);

        if (!$conversation) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log("[MLD Admin Notifier] Conversation {$conversation_id} not found");
            }
            return false;
        }

        // Get user message
        $user_message = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}mld_chat_messages WHERE id = %d",
            $user_message_id
        ), ARRAY_A);

        // Get AI response
        $ai_message = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}mld_chat_messages WHERE id = %d",
            $ai_message_id
        ), ARRAY_A);

        if (!$user_message) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log("[MLD Admin Notifier] User message {$user_message_id} not found");
            }
            return false;
        }

        // Get admin email from settings
        $admin_email = $this->get_admin_email();
        if (!$admin_email) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log("[MLD Admin Notifier] No admin email configured");
            }
            return false;
        }

        // Check if admin notifications are enabled
        if (!$this->are_admin_notifications_enabled()) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log("[MLD Admin Notifier] Admin notifications are disabled");
            }
            return false;
        }

        // Prepare notification data
        $notification_data = array(
            'conversation_id' => $conversation_id,
            'user_name' => $conversation['user_name'] ?: 'Anonymous',
            'user_email' => $conversation['user_email'] ?: 'Not provided',
            'user_message' => $user_message['message_text'],
            'ai_response' => $ai_message ? $ai_message['message_text'] : 'No response yet',
            'session_url' => isset($conversation['page_url']) ? $conversation['page_url'] : get_site_url(),
            'timestamp' => current_time('mysql'),
        );

        // Queue the notification
        $notification_id = $this->queue_notification($conversation_id, $user_message_id, $admin_email, $notification_data);

        if (!$notification_id) {
            return false;
        }

        // Send immediately (don't wait for cron)
        return $this->send_notification($notification_id);
    }

    /**
     * Queue a notification for sending
     *
     * @param int $conversation_id Conversation ID
     * @param int $message_id Message ID
     * @param string $admin_email Admin email address
     * @param array $notification_data Notification data
     * @return int|false Notification ID or false on failure
     */
    private function queue_notification($conversation_id, $message_id, $admin_email, $notification_data) {
        global $wpdb;

        $result = $wpdb->insert(
            $wpdb->prefix . 'mld_chat_admin_notifications',
            array(
                'conversation_id' => $conversation_id,
                'message_id' => $message_id,
                'admin_email' => $admin_email,
                'notification_status' => 'pending',
                'notification_data' => wp_json_encode($notification_data),
                'created_at' => current_time('mysql'),
            ),
            array('%d', '%d', '%s', '%s', '%s', '%s')
        );

        return $result ? $wpdb->insert_id : false;
    }

    /**
     * Send a specific notification
     *
     * @param int $notification_id Notification ID
     * @return bool Success status
     */
    private function send_notification($notification_id) {
        global $wpdb;

        // Get notification details
        $notification = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}mld_chat_admin_notifications WHERE id = %d",
            $notification_id
        ), ARRAY_A);

        if (!$notification) {
            return false;
        }

        // Don't resend if already sent
        if ($notification['notification_status'] === 'sent') {
            return true;
        }

        // Parse notification data
        $data = json_decode($notification['notification_data'], true);

        // Build email subject
        $subject = sprintf(
            '[Chatbot Alert] New message from %s',
            $data['user_name']
        );

        // Build email body
        $body = $this->build_notification_email($data, $notification['conversation_id']);

        // Set email headers
        // Note: FROM address is set by PHPMailer SMTP configuration
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
        );

        // Send email
        $sent = wp_mail($notification['admin_email'], $subject, $body, $headers);

        // Get detailed error if it failed
        if (!$sent) {
            global $phpmailer;
            $error_message = 'Unknown error';
            if (isset($phpmailer) && !empty($phpmailer->ErrorInfo)) {
                $error_message = $phpmailer->ErrorInfo;
            }
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log("[MLD Admin Notifier] wp_mail failed: {$error_message}");
            }
        }

        // Update notification status
        if ($sent) {
            $wpdb->update(
                $wpdb->prefix . 'mld_chat_admin_notifications',
                array(
                    'notification_status' => 'sent',
                    'sent_at' => current_time('mysql'),
                ),
                array('id' => $notification_id),
                array('%s', '%s'),
                array('%d')
            );

            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log("[MLD Admin Notifier] Notification {$notification_id} sent successfully");
            }
            return true;
        } else {
            // Mark as failed
            $wpdb->update(
                $wpdb->prefix . 'mld_chat_admin_notifications',
                array(
                    'notification_status' => 'failed',
                    'error_message' => isset($error_message) ? $error_message : null,
                ),
                array('id' => $notification_id),
                array('%s', '%s'),
                array('%d')
            );

            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log("[MLD Admin Notifier] Failed to send notification {$notification_id}");
            }
            return false;
        }
    }

    /**
     * Build notification email HTML
     *
     * @param array $data Notification data
     * @param int $conversation_id Conversation ID
     * @return string Email HTML
     */
    private function build_notification_email($data, $conversation_id) {
        $site_name = get_bloginfo('name');
        $conversation_url = admin_url('admin.php?page=mld-chatbot-settings&tab=analytics&conversation_id=' . $conversation_id);

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
                    background: #0073aa;
                    color: white;
                    padding: 20px;
                    border-radius: 5px 5px 0 0;
                }
                .content {
                    background: #f9f9f9;
                    padding: 20px;
                    border: 1px solid #ddd;
                    border-top: none;
                }
                .message-box {
                    background: white;
                    padding: 15px;
                    margin: 10px 0;
                    border-left: 4px solid #0073aa;
                    border-radius: 3px;
                }
                .user-info {
                    background: #fff;
                    padding: 15px;
                    margin: 10px 0;
                    border-radius: 3px;
                    border: 1px solid #ddd;
                }
                .label {
                    font-weight: bold;
                    color: #555;
                }
                .button {
                    display: inline-block;
                    background: #0073aa;
                    color: white;
                    padding: 10px 20px;
                    text-decoration: none;
                    border-radius: 3px;
                    margin: 15px 0;
                }
                .footer {
                    background: #f1f1f1;
                    padding: 15px;
                    text-align: center;
                    font-size: 12px;
                    color: #666;
                    border-radius: 0 0 5px 5px;
                }
            </style>
        </head>
        <body>
            <div class="header">
                <h2 style="margin: 0;">🤖 New Chatbot Message</h2>
                <p style="margin: 5px 0 0 0;">A user has sent a new message via your chatbot</p>
            </div>

            <div class="content">
                <div class="user-info">
                    <p><span class="label">Name:</span> <?php echo esc_html($data['user_name']); ?></p>
                    <p><span class="label">Email:</span> <?php echo esc_html($data['user_email']); ?></p>
                    <p><span class="label">Page:</span> <?php echo esc_html($data['session_url']); ?></p>
                    <p><span class="label">Time:</span> <?php echo esc_html($data['timestamp']); ?></p>
                </div>

                <div class="message-box">
                    <p class="label">User Message:</p>
                    <p><?php echo nl2br(esc_html($data['user_message'])); ?></p>
                </div>

                <div class="message-box" style="border-left-color: #46b450;">
                    <p class="label">AI Response:</p>
                    <p><?php echo nl2br(esc_html($data['ai_response'])); ?></p>
                </div>

                <a href="<?php echo esc_url($conversation_url); ?>" class="button">View Full Conversation</a>
            </div>

            <div class="footer">
                <p>This is an automated notification from <?php echo esc_html($site_name); ?></p>
                <p>To disable these notifications, visit <a href="<?php echo admin_url('admin.php?page=mld-chatbot-settings&tab=notifications'); ?>">Chatbot Settings</a></p>
            </div>
        </body>
        </html>
        <?php
        return ob_get_clean();
    }

    /**
     * Process notification queue (called by WP-Cron)
     *
     * Processes any pending or failed notifications
     *
     * @return array Results with counts
     */
    public function process_notification_queue() {
        global $wpdb;

        // Get pending and failed notifications (with retry limit)
        $notifications = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}mld_chat_admin_notifications
             WHERE notification_status IN ('pending', 'failed')
             AND (retry_count IS NULL OR retry_count < " . self::MAX_RETRY_ATTEMPTS . ")
             ORDER BY created_at ASC
             LIMIT 50"
        , ARRAY_A);

        $sent_count = 0;
        $failed_count = 0;

        foreach ($notifications as $notification) {
            $success = $this->send_notification($notification['id']);

            if ($success) {
                $sent_count++;
            } else {
                $failed_count++;

                // Increment retry count
                $retry_count = isset($notification['retry_count']) ? (int) $notification['retry_count'] : 0;
                $wpdb->update(
                    $wpdb->prefix . 'mld_chat_admin_notifications',
                    array('retry_count' => $retry_count + 1),
                    array('id' => $notification['id']),
                    array('%d'),
                    array('%d')
                );
            }
        }

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log("[MLD Admin Notifier] Queue processed: {$sent_count} sent, {$failed_count} failed");
        }

        return array(
            'success' => true,
            'sent' => $sent_count,
            'failed' => $failed_count,
        );
    }

    /**
     * Get admin email address from settings
     *
     * @return string|false Admin email or false if not configured
     */
    private function get_admin_email() {
        global $wpdb;

        // Check for setting key used by admin panel (admin_notification_emails - plural)
        $email = $wpdb->get_var($wpdb->prepare(
            "SELECT setting_value FROM {$wpdb->prefix}mld_chat_settings
             WHERE setting_key = %s",
            'admin_notification_emails'
        ));

        // Fallback to legacy key (singular)
        if (!$email) {
            $email = $wpdb->get_var($wpdb->prepare(
                "SELECT setting_value FROM {$wpdb->prefix}mld_chat_settings
                 WHERE setting_key = %s",
                'admin_notification_email'
            ));
        }

        // Fallback to WordPress admin email
        if (!$email) {
            $email = get_option('admin_email');
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("[MLD Admin Notifier] Admin email from settings: " . ($email ? $email : 'not set, using WP admin'));
        }

        // If multiple emails (comma-separated), just use the first one
        if ($email && strpos($email, ',') !== false) {
            $emails = array_map('trim', explode(',', $email));
            $email = $emails[0];
        }

        return is_email($email) ? $email : false;
    }

    /**
     * Check if admin notifications are enabled
     *
     * @return bool
     */
    private function are_admin_notifications_enabled() {
        global $wpdb;

        // Check for the setting key used by admin panel (admin_notification_enabled)
        // Also check legacy key (admin_notifications_enabled) for backwards compatibility
        $enabled = $wpdb->get_var($wpdb->prepare(
            "SELECT setting_value FROM {$wpdb->prefix}mld_chat_settings
             WHERE setting_key = %s",
            'admin_notification_enabled'
        ));

        // Fallback to legacy key if not found
        if ($enabled === null) {
            $enabled = $wpdb->get_var($wpdb->prepare(
                "SELECT setting_value FROM {$wpdb->prefix}mld_chat_settings
                 WHERE setting_key = %s",
                'admin_notifications_enabled'
            ));
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("[MLD Admin Notifier] admin_notification_enabled setting value: " . var_export($enabled, true));
        }

        return $enabled === '1';
    }

    /**
     * Get notification statistics
     *
     * @param int $days Days to look back (default 7)
     * @return array Statistics
     */
    public function get_statistics($days = 7) {
        global $wpdb;

        // Use current_time('mysql') for WordPress timezone consistency
        $wp_now = current_time('mysql');

        $stats = array(
            'total_sent' => $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}mld_chat_admin_notifications
                 WHERE notification_status = 'sent'
                 AND sent_at >= DATE_SUB(%s, INTERVAL %d DAY)",
                $wp_now, $days
            )),
            'total_failed' => $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}mld_chat_admin_notifications
                 WHERE notification_status = 'failed'
                 AND created_at >= DATE_SUB(%s, INTERVAL %d DAY)",
                $wp_now, $days
            )),
            'pending' => $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->prefix}mld_chat_admin_notifications
                 WHERE notification_status = 'pending'"
            ),
            'avg_delivery_time' => $wpdb->get_var(
                "SELECT AVG(TIMESTAMPDIFF(SECOND, created_at, sent_at))
                 FROM {$wpdb->prefix}mld_chat_admin_notifications
                 WHERE notification_status = 'sent'
                 AND sent_at IS NOT NULL"
            ),
        );

        $stats['success_rate'] = ($stats['total_sent'] + $stats['total_failed']) > 0
            ? round(($stats['total_sent'] / ($stats['total_sent'] + $stats['total_failed'])) * 100, 2)
            : 0;

        return $stats;
    }

    /**
     * Cleanup old notifications
     *
     * Removes sent notifications older than specified days
     *
     * @param int $days Days to keep (default 30)
     * @return int Number of deleted notifications
     */
    public function cleanup_old_notifications($days = 30) {
        global $wpdb;

        // Use current_time('mysql') for WordPress timezone consistency
        $wp_now = current_time('mysql');

        $result = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}mld_chat_admin_notifications
             WHERE notification_status = 'sent'
             AND sent_at < DATE_SUB(%s, INTERVAL %d DAY)",
            $wp_now, $days
        ));

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log("[MLD Admin Notifier] Cleaned up {$result} old notifications (>{$days} days)");
        }

        return $result;
    }
}

// Initialize admin notifier
global $mld_admin_notifier;
$mld_admin_notifier = new MLD_Admin_Notifier();

/**
 * Get global admin notifier instance
 *
 * @return MLD_Admin_Notifier
 */
function mld_get_admin_notifier() {
    global $mld_admin_notifier;
    return $mld_admin_notifier;
}
