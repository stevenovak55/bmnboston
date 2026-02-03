<?php
/**
 * Chatbot Cron Manager
 *
 * Manages WP-Cron scheduled tasks for the chatbot system
 *
 * @package MLS_Listings_Display
 * @subpackage Chatbot
 * @since 6.6.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Chatbot_Cron {

    /**
     * Cron hook names
     */
    const HOOK_KNOWLEDGE_SCAN = 'mld_chatbot_knowledge_scan';
    const HOOK_IDLE_SESSION_CHECK = 'mld_chatbot_idle_session_check';
    const HOOK_DATA_CLEANUP = 'mld_chatbot_data_cleanup';
    const HOOK_ADMIN_NOTIFICATIONS = 'mld_process_admin_notifications';

    /**
     * Constructor
     */
    public function __construct() {
        // Register cron hooks
        add_action(self::HOOK_KNOWLEDGE_SCAN, array($this, 'run_knowledge_scan'));
        add_action(self::HOOK_IDLE_SESSION_CHECK, array($this, 'run_idle_session_check'));
        add_action(self::HOOK_DATA_CLEANUP, array($this, 'run_data_cleanup'));
        add_action(self::HOOK_ADMIN_NOTIFICATIONS, array($this, 'run_admin_notifications'));

        // Schedule events on plugin activation (if not already scheduled)
        add_action('init', array($this, 'maybe_schedule_events'));
    }

    /**
     * Schedule cron events if not already scheduled
     */
    public function maybe_schedule_events() {
        // Knowledge scan - Daily at 2 AM
        if (!wp_next_scheduled(self::HOOK_KNOWLEDGE_SCAN)) {
            wp_schedule_event(
                strtotime('tomorrow 2:00 AM'),
                'daily',
                self::HOOK_KNOWLEDGE_SCAN
            );
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[MLD Chatbot Cron] Scheduled daily knowledge scan');
            }
        }

        // Idle session check - Every 10 minutes
        if (!wp_next_scheduled(self::HOOK_IDLE_SESSION_CHECK)) {
            wp_schedule_event(
                time(),
                'mld_every_10_minutes',
                self::HOOK_IDLE_SESSION_CHECK
            );
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[MLD Chatbot Cron] Scheduled idle session checks');
            }
        }

        // Data cleanup - Daily at 3 AM
        if (!wp_next_scheduled(self::HOOK_DATA_CLEANUP)) {
            wp_schedule_event(
                strtotime('tomorrow 3:00 AM'),
                'daily',
                self::HOOK_DATA_CLEANUP
            );
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[MLD Chatbot Cron] Scheduled daily data cleanup');
            }
        }

        // Admin notifications processing - Every 5 minutes
        if (!wp_next_scheduled(self::HOOK_ADMIN_NOTIFICATIONS)) {
            wp_schedule_event(
                time(),
                'mld_every_5_minutes',
                self::HOOK_ADMIN_NOTIFICATIONS
            );
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[MLD Chatbot Cron] Scheduled admin notification processing');
            }
        }
    }

    /**
     * Unschedule all cron events
     *
     * Called on plugin deactivation
     */
    public static function unschedule_all_events() {
        $hooks = array(
            self::HOOK_KNOWLEDGE_SCAN,
            self::HOOK_IDLE_SESSION_CHECK,
            self::HOOK_DATA_CLEANUP,
            self::HOOK_ADMIN_NOTIFICATIONS,
        );

        foreach ($hooks as $hook) {
            $timestamp = wp_next_scheduled($hook);
            if ($timestamp) {
                wp_unschedule_event($timestamp, $hook);
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log("[MLD Chatbot Cron] Unscheduled {$hook}");
                }
            }
        }
    }

    /**
     * Run knowledge base scan
     *
     * Scans website content and updates knowledge base
     */
    public function run_knowledge_scan() {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[MLD Chatbot Cron] Running scheduled knowledge scan');
        }

        $scanner = mld_get_knowledge_scanner();
        if ($scanner) {
            $results = $scanner->run_full_scan();

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf(
                    '[MLD Chatbot Cron] Knowledge scan complete: %d scanned, %d updated, %d errors',
                    $results['scanned'],
                    $results['updated'],
                    $results['errors']
                ));
            }

            return $results;
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[MLD Chatbot Cron] Knowledge scanner not available');
        }
        return false;
    }

    /**
     * Run idle session check
     *
     * Closes sessions that have been idle too long
     */
    public function run_idle_session_check() {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[MLD Chatbot Cron] Running idle session check');
        }

        $session_manager = mld_get_session_manager();
        if ($session_manager) {
            $results = $session_manager->check_idle_sessions();

            if ($results['closed_sessions'] > 0 && defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf(
                    '[MLD Chatbot Cron] Closed %d idle sessions (timeout: %d minutes)',
                    $results['closed_sessions'],
                    $results['timeout_minutes']
                ));
            }

            return $results;
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[MLD Chatbot Cron] Session manager not available');
        }
        return false;
    }

    /**
     * Run data cleanup
     *
     * Removes old conversations, notifications, and knowledge entries
     */
    public function run_data_cleanup() {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[MLD Chatbot Cron] Running data cleanup');
        }

        global $wpdb;
        $cleaned = array(
            'conversations' => 0,
            'messages' => 0,
            'sessions' => 0,
            'notifications' => 0,
            'summaries' => 0,
            'knowledge' => 0,
        );

        // Get retention settings
        $conversation_retention = $this->get_retention_days('conversations', 90);
        $notification_retention = $this->get_retention_days('notifications', 30);
        $knowledge_retention = $this->get_retention_days('knowledge', 180);

        // Cleanup old conversations (and cascade to messages)
        $cleaned['conversations'] = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}mld_chat_conversations
             WHERE conversation_status = 'closed'
             AND ended_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $conversation_retention
        ));

        // Cleanup orphaned messages (messages without conversations)
        $cleaned['messages'] = $wpdb->query(
            "DELETE m FROM {$wpdb->prefix}mld_chat_messages m
             LEFT JOIN {$wpdb->prefix}mld_chat_conversations c ON m.conversation_id = c.id
             WHERE c.id IS NULL"
        );

        // Cleanup old sessions
        $session_manager = mld_get_session_manager();
        if ($session_manager) {
            $cleaned['sessions'] = $session_manager->cleanup_old_sessions($conversation_retention);
        }

        // Cleanup old admin notifications
        $admin_notifier = mld_get_admin_notifier();
        if ($admin_notifier) {
            $cleaned['notifications'] = $admin_notifier->cleanup_old_notifications($notification_retention);
        }

        // Cleanup old email summaries
        $cleaned['summaries'] = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}mld_chat_email_summaries
             WHERE delivery_status = 'sent'
             AND sent_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $notification_retention
        ));

        // Cleanup old knowledge entries
        $knowledge_scanner = mld_get_knowledge_scanner();
        if ($knowledge_scanner) {
            $cleaned['knowledge'] = $knowledge_scanner->cleanup_old_entries($knowledge_retention);
        }

        $total_cleaned = array_sum($cleaned);
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("[MLD Chatbot Cron] Cleanup complete: {$total_cleaned} total records removed");
        }

        return $cleaned;
    }

    /**
     * Run admin notification queue processing
     *
     * Processes any pending or failed notifications
     */
    public function run_admin_notifications() {
        $admin_notifier = mld_get_admin_notifier();
        if ($admin_notifier) {
            $results = $admin_notifier->process_notification_queue();

            if ($results['sent'] > 0 || $results['failed'] > 0) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log(sprintf(
                        '[MLD Chatbot Cron] Processed notification queue: %d sent, %d failed',
                        $results['sent'],
                        $results['failed']
                    ));
                }
            }

            return $results;
        }

        return false;
    }

    /**
     * Get retention days from settings
     *
     * @param string $type Data type
     * @param int $default Default days
     * @return int Retention days
     */
    private function get_retention_days($type, $default = 90) {
        global $wpdb;

        $setting_key = 'retention_days_' . $type;
        $days = $wpdb->get_var($wpdb->prepare(
            "SELECT setting_value FROM {$wpdb->prefix}mld_chat_settings
             WHERE setting_key = %s",
            $setting_key
        ));

        return $days ? (int) $days : $default;
    }

    /**
     * Get cron schedule status
     *
     * @return array Schedule information
     */
    public function get_schedule_status() {
        $status = array();

        $hooks = array(
            self::HOOK_KNOWLEDGE_SCAN => 'Knowledge Scan',
            self::HOOK_IDLE_SESSION_CHECK => 'Idle Session Check',
            self::HOOK_DATA_CLEANUP => 'Data Cleanup',
            self::HOOK_ADMIN_NOTIFICATIONS => 'Admin Notifications',
        );

        foreach ($hooks as $hook => $label) {
            $next_run = wp_next_scheduled($hook);
            $status[$hook] = array(
                'label' => $label,
                'scheduled' => (bool) $next_run,
                'next_run' => $next_run ? date('Y-m-d H:i:s', $next_run) : 'Not scheduled',
                'next_run_relative' => $next_run ? human_time_diff($next_run, time()) : 'N/A',
            );
        }

        return $status;
    }

    /**
     * Manually run a specific cron job
     *
     * @param string $hook Cron hook name
     * @return mixed Job results
     */
    public function manual_run($hook) {
        switch ($hook) {
            case self::HOOK_KNOWLEDGE_SCAN:
                return $this->run_knowledge_scan();

            case self::HOOK_IDLE_SESSION_CHECK:
                return $this->run_idle_session_check();

            case self::HOOK_DATA_CLEANUP:
                return $this->run_data_cleanup();

            case self::HOOK_ADMIN_NOTIFICATIONS:
                return $this->run_admin_notifications();

            default:
                return new WP_Error('invalid_hook', 'Invalid cron hook');
        }
    }
}

/**
 * Register custom cron schedules
 */
add_filter('cron_schedules', function($schedules) {
    // Every 5 minutes
    $schedules['mld_every_5_minutes'] = array(
        'interval' => 300,
        'display' => __('Every 5 Minutes', 'mls-listings-display'),
    );

    // Every 10 minutes
    $schedules['mld_every_10_minutes'] = array(
        'interval' => 600,
        'display' => __('Every 10 Minutes', 'mls-listings-display'),
    );

    return $schedules;
});

// Initialize cron manager
global $mld_chatbot_cron;
$mld_chatbot_cron = new MLD_Chatbot_Cron();

/**
 * Get global cron manager instance
 *
 * @return MLD_Chatbot_Cron
 */
function mld_get_chatbot_cron() {
    global $mld_chatbot_cron;
    return $mld_chatbot_cron;
}

/**
 * Unschedule cron events on plugin deactivation
 */
register_deactivation_hook(MLD_PLUGIN_FILE, array('MLD_Chatbot_Cron', 'unschedule_all_events'));
