<?php
/**
 * Agent Notification Log
 *
 * Logs all agent activity notifications for debugging and analytics.
 *
 * @package MLS_Listings_Display
 * @subpackage Notifications
 * @since 6.43.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Agent_Notification_Log {

    /**
     * Status constants
     */
    const STATUS_SENT = 'sent';
    const STATUS_FAILED = 'failed';
    const STATUS_SKIPPED = 'skipped';

    /**
     * Channel constants
     */
    const CHANNEL_EMAIL = 'email';
    const CHANNEL_PUSH = 'push';

    /**
     * Log a notification attempt
     *
     * @param int $agent_id
     * @param int $client_id
     * @param string $notification_type
     * @param string $channel 'email' or 'push'
     * @param array $result Array with 'success' key, and optionally 'error' key
     * @param array $context Optional context data to store
     * @return int|false Insert ID or false on failure
     */
    public static function log($agent_id, $client_id, $notification_type, $channel, $result, $context = array()) {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_agent_notification_log';

        $status = !empty($result['success']) ? self::STATUS_SENT : self::STATUS_FAILED;
        $error_message = isset($result['error']) ? $result['error'] : null;

        $inserted = $wpdb->insert(
            $table,
            array(
                'agent_id' => $agent_id,
                'client_id' => $client_id,
                'notification_type' => $notification_type,
                'channel' => $channel,
                'status' => $status,
                'context_data' => !empty($context) ? wp_json_encode($context) : null,
                'error_message' => $error_message,
                'created_at' => current_time('mysql')
            ),
            array('%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s')
        );

        return $inserted ? $wpdb->insert_id : false;
    }

    /**
     * Log a skipped notification (e.g., preference disabled)
     *
     * @param int $agent_id
     * @param int $client_id
     * @param string $notification_type
     * @param string $channel
     * @param string $reason Why it was skipped
     * @return int|false
     */
    public static function log_skipped($agent_id, $client_id, $notification_type, $channel, $reason = '') {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_agent_notification_log';

        $inserted = $wpdb->insert(
            $table,
            array(
                'agent_id' => $agent_id,
                'client_id' => $client_id,
                'notification_type' => $notification_type,
                'channel' => $channel,
                'status' => self::STATUS_SKIPPED,
                'error_message' => $reason ?: 'Notification disabled',
                'created_at' => current_time('mysql')
            ),
            array('%d', '%d', '%s', '%s', '%s', '%s', '%s')
        );

        return $inserted ? $wpdb->insert_id : false;
    }

    /**
     * Get recent notifications for an agent
     *
     * @param int $agent_id
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public static function get_agent_notifications($agent_id, $limit = 50, $offset = 0) {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_agent_notification_log';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE agent_id = %d
             ORDER BY created_at DESC
             LIMIT %d OFFSET %d",
            $agent_id, $limit, $offset
        ), ARRAY_A);
    }

    /**
     * Get notifications for a specific client
     *
     * @param int $client_id
     * @param int $limit
     * @return array
     */
    public static function get_client_notifications($client_id, $limit = 50) {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_agent_notification_log';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE client_id = %d
             ORDER BY created_at DESC
             LIMIT %d",
            $client_id, $limit
        ), ARRAY_A);
    }

    /**
     * Get notification counts by status for an agent
     *
     * @param int $agent_id
     * @param string|null $since MySQL datetime string (optional)
     * @return array
     */
    public static function get_status_counts($agent_id, $since = null) {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_agent_notification_log';

        $where = "agent_id = %d";
        $params = array($agent_id);

        if ($since) {
            $where .= " AND created_at >= %s";
            $params[] = $since;
        }

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT status, COUNT(*) as count
             FROM {$table}
             WHERE {$where}
             GROUP BY status",
            $params
        ), ARRAY_A);

        $counts = array(
            self::STATUS_SENT => 0,
            self::STATUS_FAILED => 0,
            self::STATUS_SKIPPED => 0
        );

        foreach ($results as $row) {
            $counts[$row['status']] = (int) $row['count'];
        }

        return $counts;
    }

    /**
     * Get notification counts by type for an agent
     *
     * @param int $agent_id
     * @param string|null $since MySQL datetime string (optional)
     * @return array
     */
    public static function get_type_counts($agent_id, $since = null) {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_agent_notification_log';

        $where = "agent_id = %d AND status = 'sent'";
        $params = array($agent_id);

        if ($since) {
            $where .= " AND created_at >= %s";
            $params[] = $since;
        }

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT notification_type, COUNT(*) as count
             FROM {$table}
             WHERE {$where}
             GROUP BY notification_type",
            $params
        ), ARRAY_A);

        $counts = array();
        foreach ($results as $row) {
            $counts[$row['notification_type']] = (int) $row['count'];
        }

        return $counts;
    }

    /**
     * Check if a notification was recently sent (to prevent duplicates)
     *
     * @param int $agent_id
     * @param int $client_id
     * @param string $notification_type
     * @param int $minutes How many minutes to look back
     * @return bool
     */
    public static function was_recently_sent($agent_id, $client_id, $notification_type, $minutes = 5) {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_agent_notification_log';
        $threshold = date('Y-m-d H:i:s', current_time('timestamp') - ($minutes * 60));

        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
             WHERE agent_id = %d
               AND client_id = %d
               AND notification_type = %s
               AND status = 'sent'
               AND created_at >= %s",
            $agent_id, $client_id, $notification_type, $threshold
        ));

        return (int) $count > 0;
    }

    /**
     * Clean up old log entries
     *
     * @param int $days_to_keep
     * @return int Number of rows deleted
     */
    public static function cleanup_old_entries($days_to_keep = 30) {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_agent_notification_log';
        $threshold = date('Y-m-d H:i:s', current_time('timestamp') - ($days_to_keep * DAY_IN_SECONDS));

        return $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE created_at < %s",
            $threshold
        ));
    }

    /**
     * Get total notification count for an agent
     *
     * @param int $agent_id
     * @return int
     */
    public static function get_total_count($agent_id) {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_agent_notification_log';

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE agent_id = %d",
            $agent_id
        ));
    }
}
