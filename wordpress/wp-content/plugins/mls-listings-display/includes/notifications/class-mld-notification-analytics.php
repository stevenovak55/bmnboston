<?php
/**
 * MLD Notification Analytics
 *
 * Tracks notification delivery and engagement metrics including:
 * - Send events (push/email)
 * - Delivery status
 * - Open/click tracking
 * - Aggregated statistics by type, channel, and time period
 *
 * @package MLS_Listings_Display
 * @subpackage Notifications
 * @since 6.48.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Notification_Analytics {

    /**
     * Notification channels
     */
    const CHANNEL_PUSH = 'push';
    const CHANNEL_EMAIL = 'email';

    /**
     * Event statuses
     */
    const STATUS_SENT = 'sent';
    const STATUS_DELIVERED = 'delivered';
    const STATUS_FAILED = 'failed';
    const STATUS_BOUNCED = 'bounced';
    const STATUS_OPENED = 'opened';
    const STATUS_CLICKED = 'clicked';

    /**
     * Initialize the analytics system
     */
    public static function init() {
        // Create table on plugin activation
        add_action('mld_plugin_activate', array(__CLASS__, 'maybe_create_tables'));

        // Schedule daily cleanup of old data
        if (!wp_next_scheduled('mld_notification_analytics_cleanup')) {
            wp_schedule_event(time(), 'daily', 'mld_notification_analytics_cleanup');
        }
        add_action('mld_notification_analytics_cleanup', array(__CLASS__, 'cleanup_old_data'));

        // Schedule hourly aggregation
        if (!wp_next_scheduled('mld_notification_analytics_aggregate')) {
            wp_schedule_event(time(), 'hourly', 'mld_notification_analytics_aggregate');
        }
        add_action('mld_notification_analytics_aggregate', array(__CLASS__, 'aggregate_hourly_stats'));
    }

    /**
     * Log a notification send event
     *
     * @param int $user_id User ID
     * @param string $notification_type Type (e.g., 'price_change', 'saved_search')
     * @param string $channel 'push' or 'email'
     * @param string $listing_id Optional listing ID
     * @param array $metadata Optional additional data
     * @return int|false Insert ID or false on failure
     */
    public static function log_send($user_id, $notification_type, $channel, $listing_id = null, $metadata = array()) {
        global $wpdb;

        self::maybe_create_tables();

        $table = $wpdb->prefix . 'mld_notification_analytics';

        $data = array(
            'user_id' => $user_id,
            'notification_type' => $notification_type,
            'channel' => $channel,
            'status' => self::STATUS_SENT,
            'listing_id' => $listing_id,
            'metadata' => !empty($metadata) ? wp_json_encode($metadata) : null,
            'sent_at' => current_time('mysql'),
            'created_at' => current_time('mysql')
        );

        $result = $wpdb->insert($table, $data, array('%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s'));

        if ($result === false) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD Notification Analytics: Failed to log send - ' . $wpdb->last_error);
            }
            return false;
        }

        return $wpdb->insert_id;
    }

    /**
     * Update notification status
     *
     * @param int $analytics_id Analytics record ID
     * @param string $status New status
     * @return bool Success
     */
    public static function update_status($analytics_id, $status) {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_notification_analytics';

        $data = array('status' => $status);
        $update_field = null;

        switch ($status) {
            case self::STATUS_DELIVERED:
                $update_field = 'delivered_at';
                break;
            case self::STATUS_OPENED:
                $update_field = 'opened_at';
                break;
            case self::STATUS_CLICKED:
                $update_field = 'clicked_at';
                break;
        }

        if ($update_field) {
            $data[$update_field] = current_time('mysql');
        }

        return $wpdb->update($table, $data, array('id' => $analytics_id)) !== false;
    }

    /**
     * Mark notification as delivered
     *
     * @param int $analytics_id Analytics record ID
     * @return bool Success
     */
    public static function mark_delivered($analytics_id) {
        return self::update_status($analytics_id, self::STATUS_DELIVERED);
    }

    /**
     * Mark notification as failed
     *
     * @param int $analytics_id Analytics record ID
     * @param string $error_message Optional error message
     * @return bool Success
     */
    public static function mark_failed($analytics_id, $error_message = '') {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_notification_analytics';

        $data = array(
            'status' => self::STATUS_FAILED
        );

        if (!empty($error_message)) {
            // Get existing metadata and add error
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT metadata FROM {$table} WHERE id = %d",
                $analytics_id
            ));
            $metadata = $existing ? json_decode($existing, true) : array();
            $metadata['error'] = $error_message;
            $data['metadata'] = wp_json_encode($metadata);
        }

        return $wpdb->update($table, $data, array('id' => $analytics_id)) !== false;
    }

    /**
     * Mark notification as opened
     *
     * @param int $analytics_id Analytics record ID
     * @return bool Success
     */
    public static function mark_opened($analytics_id) {
        return self::update_status($analytics_id, self::STATUS_OPENED);
    }

    /**
     * Mark notification as clicked (user tapped/clicked to view content)
     *
     * @param int $analytics_id Analytics record ID
     * @return bool Success
     */
    public static function mark_clicked($analytics_id) {
        return self::update_status($analytics_id, self::STATUS_CLICKED);
    }

    /**
     * Log a batch of notifications sent
     *
     * @param array $notifications Array of notification data
     * @return array Array of insert IDs
     */
    public static function log_batch($notifications) {
        $ids = array();
        foreach ($notifications as $notification) {
            $id = self::log_send(
                $notification['user_id'],
                $notification['notification_type'],
                $notification['channel'],
                $notification['listing_id'] ?? null,
                $notification['metadata'] ?? array()
            );
            if ($id) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    /**
     * Get analytics summary for a time period
     *
     * @param string $start_date Start date (Y-m-d)
     * @param string $end_date End date (Y-m-d)
     * @param string $notification_type Optional filter by type
     * @param string $channel Optional filter by channel
     * @return array Summary statistics
     */
    public static function get_summary($start_date, $end_date, $notification_type = null, $channel = null) {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_notification_analytics';

        $where = array("DATE(sent_at) BETWEEN %s AND %s");
        $params = array($start_date, $end_date);

        if ($notification_type) {
            $where[] = "notification_type = %s";
            $params[] = $notification_type;
        }

        if ($channel) {
            $where[] = "channel = %s";
            $params[] = $channel;
        }

        $where_clause = implode(' AND ', $where);

        $sql = $wpdb->prepare(
            "SELECT
                COUNT(*) as total_sent,
                SUM(CASE WHEN status = 'delivered' OR status = 'opened' OR status = 'clicked' THEN 1 ELSE 0 END) as total_delivered,
                SUM(CASE WHEN status = 'failed' OR status = 'bounced' THEN 1 ELSE 0 END) as total_failed,
                SUM(CASE WHEN opened_at IS NOT NULL THEN 1 ELSE 0 END) as total_opened,
                SUM(CASE WHEN clicked_at IS NOT NULL THEN 1 ELSE 0 END) as total_clicked,
                COUNT(DISTINCT user_id) as unique_users
            FROM {$table}
            WHERE {$where_clause}",
            ...$params
        );

        $result = $wpdb->get_row($sql, ARRAY_A);

        if (!$result) {
            return array(
                'total_sent' => 0,
                'total_delivered' => 0,
                'total_failed' => 0,
                'total_opened' => 0,
                'total_clicked' => 0,
                'unique_users' => 0,
                'delivery_rate' => 0,
                'open_rate' => 0,
                'click_rate' => 0
            );
        }

        // Calculate rates
        $total_sent = (int) $result['total_sent'];
        $total_delivered = (int) $result['total_delivered'];
        $total_opened = (int) $result['total_opened'];
        $total_clicked = (int) $result['total_clicked'];

        $result['delivery_rate'] = $total_sent > 0 ? round(($total_delivered / $total_sent) * 100, 1) : 0;
        $result['open_rate'] = $total_delivered > 0 ? round(($total_opened / $total_delivered) * 100, 1) : 0;
        $result['click_rate'] = $total_opened > 0 ? round(($total_clicked / $total_opened) * 100, 1) : 0;

        return $result;
    }

    /**
     * Get breakdown by notification type
     *
     * @param string $start_date Start date (Y-m-d)
     * @param string $end_date End date (Y-m-d)
     * @return array Breakdown by type
     */
    public static function get_breakdown_by_type($start_date, $end_date) {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_notification_analytics';

        $sql = $wpdb->prepare(
            "SELECT
                notification_type,
                COUNT(*) as total_sent,
                SUM(CASE WHEN status = 'delivered' OR status = 'opened' OR status = 'clicked' THEN 1 ELSE 0 END) as total_delivered,
                SUM(CASE WHEN opened_at IS NOT NULL THEN 1 ELSE 0 END) as total_opened,
                SUM(CASE WHEN clicked_at IS NOT NULL THEN 1 ELSE 0 END) as total_clicked
            FROM {$table}
            WHERE DATE(sent_at) BETWEEN %s AND %s
            GROUP BY notification_type
            ORDER BY total_sent DESC",
            $start_date,
            $end_date
        );

        $results = $wpdb->get_results($sql, ARRAY_A);

        // Add rates to each row
        foreach ($results as &$row) {
            $total_sent = (int) $row['total_sent'];
            $total_delivered = (int) $row['total_delivered'];
            $total_opened = (int) $row['total_opened'];
            $total_clicked = (int) $row['total_clicked'];

            $row['delivery_rate'] = $total_sent > 0 ? round(($total_delivered / $total_sent) * 100, 1) : 0;
            $row['open_rate'] = $total_delivered > 0 ? round(($total_opened / $total_delivered) * 100, 1) : 0;
            $row['click_rate'] = $total_opened > 0 ? round(($total_clicked / $total_opened) * 100, 1) : 0;
        }

        return $results;
    }

    /**
     * Get breakdown by channel
     *
     * @param string $start_date Start date (Y-m-d)
     * @param string $end_date End date (Y-m-d)
     * @return array Breakdown by channel
     */
    public static function get_breakdown_by_channel($start_date, $end_date) {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_notification_analytics';

        $sql = $wpdb->prepare(
            "SELECT
                channel,
                COUNT(*) as total_sent,
                SUM(CASE WHEN status = 'delivered' OR status = 'opened' OR status = 'clicked' THEN 1 ELSE 0 END) as total_delivered,
                SUM(CASE WHEN opened_at IS NOT NULL THEN 1 ELSE 0 END) as total_opened,
                SUM(CASE WHEN clicked_at IS NOT NULL THEN 1 ELSE 0 END) as total_clicked
            FROM {$table}
            WHERE DATE(sent_at) BETWEEN %s AND %s
            GROUP BY channel
            ORDER BY total_sent DESC",
            $start_date,
            $end_date
        );

        $results = $wpdb->get_results($sql, ARRAY_A);

        // Add rates
        foreach ($results as &$row) {
            $total_sent = (int) $row['total_sent'];
            $total_delivered = (int) $row['total_delivered'];
            $total_opened = (int) $row['total_opened'];
            $total_clicked = (int) $row['total_clicked'];

            $row['delivery_rate'] = $total_sent > 0 ? round(($total_delivered / $total_sent) * 100, 1) : 0;
            $row['open_rate'] = $total_delivered > 0 ? round(($total_opened / $total_delivered) * 100, 1) : 0;
            $row['click_rate'] = $total_opened > 0 ? round(($total_clicked / $total_opened) * 100, 1) : 0;
        }

        return $results;
    }

    /**
     * Get daily trend data
     *
     * @param string $start_date Start date (Y-m-d)
     * @param string $end_date End date (Y-m-d)
     * @return array Daily statistics
     */
    public static function get_daily_trend($start_date, $end_date) {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_notification_analytics';

        $sql = $wpdb->prepare(
            "SELECT
                DATE(sent_at) as date,
                COUNT(*) as total_sent,
                SUM(CASE WHEN status = 'delivered' OR status = 'opened' OR status = 'clicked' THEN 1 ELSE 0 END) as total_delivered,
                SUM(CASE WHEN opened_at IS NOT NULL THEN 1 ELSE 0 END) as total_opened,
                SUM(CASE WHEN clicked_at IS NOT NULL THEN 1 ELSE 0 END) as total_clicked
            FROM {$table}
            WHERE DATE(sent_at) BETWEEN %s AND %s
            GROUP BY DATE(sent_at)
            ORDER BY date ASC",
            $start_date,
            $end_date
        );

        return $wpdb->get_results($sql, ARRAY_A);
    }

    /**
     * Get top performing notifications (highest click rate)
     *
     * @param string $start_date Start date (Y-m-d)
     * @param string $end_date End date (Y-m-d)
     * @param int $limit Number of results
     * @return array Top notifications
     */
    public static function get_top_performing($start_date, $end_date, $limit = 10) {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_notification_analytics';

        $sql = $wpdb->prepare(
            "SELECT
                notification_type,
                channel,
                listing_id,
                COUNT(*) as total_sent,
                SUM(CASE WHEN clicked_at IS NOT NULL THEN 1 ELSE 0 END) as total_clicked,
                ROUND((SUM(CASE WHEN clicked_at IS NOT NULL THEN 1 ELSE 0 END) / COUNT(*)) * 100, 1) as click_rate
            FROM {$table}
            WHERE DATE(sent_at) BETWEEN %s AND %s
            GROUP BY notification_type, channel, listing_id
            HAVING total_sent >= 5
            ORDER BY click_rate DESC
            LIMIT %d",
            $start_date,
            $end_date,
            $limit
        );

        return $wpdb->get_results($sql, ARRAY_A);
    }

    /**
     * Get user engagement summary
     *
     * @param int $user_id User ID
     * @param int $days Number of days to look back
     * @return array User engagement data
     */
    public static function get_user_engagement($user_id, $days = 30) {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_notification_analytics';
        $start_date = date('Y-m-d', strtotime("-{$days} days"));

        $sql = $wpdb->prepare(
            "SELECT
                notification_type,
                channel,
                COUNT(*) as total_received,
                SUM(CASE WHEN opened_at IS NOT NULL THEN 1 ELSE 0 END) as total_opened,
                SUM(CASE WHEN clicked_at IS NOT NULL THEN 1 ELSE 0 END) as total_clicked,
                MAX(sent_at) as last_notification
            FROM {$table}
            WHERE user_id = %d AND DATE(sent_at) >= %s
            GROUP BY notification_type, channel
            ORDER BY last_notification DESC",
            $user_id,
            $start_date
        );

        return $wpdb->get_results($sql, ARRAY_A);
    }

    /**
     * Aggregate hourly stats into daily aggregates
     * Called by cron job
     */
    public static function aggregate_hourly_stats() {
        global $wpdb;

        $analytics_table = $wpdb->prefix . 'mld_notification_analytics';
        $daily_table = $wpdb->prefix . 'mld_notification_analytics_daily';

        // Aggregate yesterday's data
        $yesterday = date('Y-m-d', strtotime('-1 day'));

        $sql = $wpdb->prepare(
            "INSERT INTO {$daily_table}
                (date, notification_type, channel, total_sent, total_delivered, total_failed, total_opened, total_clicked, unique_users)
            SELECT
                DATE(sent_at) as date,
                notification_type,
                channel,
                COUNT(*) as total_sent,
                SUM(CASE WHEN status IN ('delivered', 'opened', 'clicked') THEN 1 ELSE 0 END) as total_delivered,
                SUM(CASE WHEN status IN ('failed', 'bounced') THEN 1 ELSE 0 END) as total_failed,
                SUM(CASE WHEN opened_at IS NOT NULL THEN 1 ELSE 0 END) as total_opened,
                SUM(CASE WHEN clicked_at IS NOT NULL THEN 1 ELSE 0 END) as total_clicked,
                COUNT(DISTINCT user_id) as unique_users
            FROM {$analytics_table}
            WHERE DATE(sent_at) = %s
            GROUP BY DATE(sent_at), notification_type, channel
            ON DUPLICATE KEY UPDATE
                total_sent = VALUES(total_sent),
                total_delivered = VALUES(total_delivered),
                total_failed = VALUES(total_failed),
                total_opened = VALUES(total_opened),
                total_clicked = VALUES(total_clicked),
                unique_users = VALUES(unique_users)",
            $yesterday
        );

        $wpdb->query($sql);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Notification Analytics: Aggregated stats for ' . $yesterday);
        }
    }

    /**
     * Clean up old detailed analytics data
     * Keeps daily aggregates, removes detailed records older than 90 days
     */
    public static function cleanup_old_data() {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_notification_analytics';
        $cutoff_date = date('Y-m-d', strtotime('-90 days'));

        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE DATE(sent_at) < %s",
            $cutoff_date
        ));

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("MLD Notification Analytics: Cleaned up {$deleted} old records");
        }
    }

    /**
     * Create analytics tables if they don't exist
     */
    public static function maybe_create_tables() {
        global $wpdb;

        $analytics_table = $wpdb->prefix . 'mld_notification_analytics';
        $daily_table = $wpdb->prefix . 'mld_notification_analytics_daily';
        $charset_collate = $wpdb->get_charset_collate();

        // Check if main table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '{$analytics_table}'") === $analytics_table) {
            return;
        }

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        // Detailed analytics table (90-day retention)
        $sql1 = "CREATE TABLE {$analytics_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            notification_type VARCHAR(50) NOT NULL,
            channel ENUM('push', 'email') NOT NULL,
            status ENUM('sent', 'delivered', 'failed', 'bounced', 'opened', 'clicked') DEFAULT 'sent',
            listing_id VARCHAR(50) DEFAULT NULL,
            metadata JSON DEFAULT NULL,
            sent_at DATETIME NOT NULL,
            delivered_at DATETIME DEFAULT NULL,
            opened_at DATETIME DEFAULT NULL,
            clicked_at DATETIME DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_user_date (user_id, sent_at),
            KEY idx_type_date (notification_type, sent_at),
            KEY idx_channel_date (channel, sent_at),
            KEY idx_status (status),
            KEY idx_sent_at (sent_at)
        ) {$charset_collate}";

        dbDelta($sql1);

        // Daily aggregates table (permanent)
        $sql2 = "CREATE TABLE {$daily_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            date DATE NOT NULL,
            notification_type VARCHAR(50) NOT NULL,
            channel ENUM('push', 'email') NOT NULL,
            total_sent INT UNSIGNED DEFAULT 0,
            total_delivered INT UNSIGNED DEFAULT 0,
            total_failed INT UNSIGNED DEFAULT 0,
            total_opened INT UNSIGNED DEFAULT 0,
            total_clicked INT UNSIGNED DEFAULT 0,
            unique_users INT UNSIGNED DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY uk_date_type_channel (date, notification_type, channel),
            KEY idx_date (date)
        ) {$charset_collate}";

        dbDelta($sql2);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Notification Analytics: Created tables');
        }
    }

    /**
     * Get notification type labels for display
     *
     * @return array Type labels
     */
    public static function get_type_labels() {
        return array(
            'saved_search' => 'Saved Search Matches',
            'price_change' => 'Price Drops',
            'status_change' => 'Status Changes',
            'open_house' => 'Open Houses',
            'new_listing' => 'New Listings',
            'appointment_reminder' => 'Appointment Reminders',
            'agent_activity' => 'Agent Activity'
        );
    }

    /**
     * Get recent notifications for a user
     *
     * @param int $user_id User ID
     * @param int $limit Number of records
     * @return array Recent notifications
     */
    public static function get_recent_for_user($user_id, $limit = 20) {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_notification_analytics';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT id, notification_type, channel, status, listing_id, sent_at, opened_at, clicked_at
            FROM {$table}
            WHERE user_id = %d
            ORDER BY sent_at DESC
            LIMIT %d",
            $user_id,
            $limit
        ), ARRAY_A);
    }
}
