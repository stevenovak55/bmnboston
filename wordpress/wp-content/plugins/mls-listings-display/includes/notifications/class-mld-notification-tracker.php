<?php
/**
 * MLS Notification Tracker
 *
 * Simple tracking system to prevent duplicate notifications
 * and manage notification history.
 *
 * @package MLS_Listings_Display
 * @subpackage Notifications
 * @since 5.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Notification_Tracker {

    /**
     * Singleton instance
     */
    private static $instance = null;

    /**
     * Database table name
     */
    private $table_name;

    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'mld_notification_tracker';
        $this->init();
    }

    /**
     * Initialize tracker
     */
    private function init() {
        add_action('init', [$this, 'maybe_create_table']);
    }

    /**
     * Create database table if it doesn't exist
     */
    public function maybe_create_table() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            mls_number varchar(50) NOT NULL,
            search_id bigint(20) unsigned NOT NULL,
            notification_type varchar(50) DEFAULT 'listing_update',
            sent_at datetime NOT NULL,
            email_sent tinyint(1) DEFAULT 1,
            buddyboss_sent tinyint(1) DEFAULT 0,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY mls_number (mls_number),
            KEY search_id (search_id),
            KEY sent_at (sent_at),
            UNIQUE KEY unique_notification (user_id, mls_number, search_id)
        ) {$charset_collate};";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * Check if a notification was already sent for a specific listing to a user
     */
    public function was_notification_sent($user_id, $mls_number, $search_id = null) {
        global $wpdb;

        $query = "SELECT COUNT(*) FROM {$this->table_name} WHERE user_id = %d AND mls_number = %s";
        $params = [$user_id, $mls_number];

        if ($search_id) {
            $query .= " AND search_id = %d";
            $params[] = $search_id;
        }

        $count = $wpdb->get_var($wpdb->prepare($query, $params));

        return $count > 0;
    }

    /**
     * Mark listings as having notifications sent
     */
    public function mark_listings_sent($user_id, $listings, $search_id = 0) {
        global $wpdb;

        $current_time = current_time('mysql');
        $values = [];
        $placeholders = [];

        foreach ($listings as $listing) {
            // Use listing_id as the identifier (MLS number)
            $mls_id = isset($listing->listing_id) ? $listing->listing_id :
                     (isset($listing->mls_number) ? $listing->mls_number : 0);

            $values[] = $user_id;
            $values[] = $mls_id;
            $values[] = $search_id;
            $values[] = 'listing_update';
            $values[] = $current_time;
            $values[] = 1; // email_sent
            $values[] = function_exists('bp_is_active') ? 1 : 0; // buddyboss_sent

            $placeholders[] = "(%d, %s, %d, %s, %s, %d, %d)";
        }

        if (empty($values)) {
            return false;
        }

        $query = "INSERT INTO {$this->table_name}
                  (user_id, mls_number, search_id, notification_type, sent_at, email_sent, buddyboss_sent)
                  VALUES " . implode(', ', $placeholders) . "
                  ON DUPLICATE KEY UPDATE
                  sent_at = VALUES(sent_at),
                  email_sent = VALUES(email_sent),
                  buddyboss_sent = VALUES(buddyboss_sent)";

        $result = $wpdb->query($wpdb->prepare($query, $values));

        if ($result === false) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD Notification Tracker: Failed to mark listings as sent. Error: ' . $wpdb->last_error);
            }
            return false;
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Notification Tracker: Marked ' . count($listings) . ' listings as sent for user ' . $user_id);
        }
        return true;
    }

    /**
     * Mark a single listing as sent for a user
     */
    public function mark_listing_sent($user_id, $mls_number, $search_id = 0, $email_sent = true, $buddyboss_sent = false) {
        global $wpdb;

        $result = $wpdb->replace(
            $this->table_name,
            [
                'user_id' => $user_id,
                'mls_number' => $mls_number,
                'search_id' => $search_id,
                'notification_type' => 'listing_update',
                'sent_at' => current_time('mysql'),
                'email_sent' => $email_sent ? 1 : 0,
                'buddyboss_sent' => $buddyboss_sent ? 1 : 0
            ],
            ['%d', '%s', '%d', '%s', '%s', '%d', '%d']
        );

        return $result !== false;
    }

    /**
     * Get notification history for a user
     */
    public function get_user_notifications($user_id, $limit = 50) {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare("
            SELECT nt.*, el.list_price, el.address, el.city, el.state_or_province, el.postal_code
            FROM {$this->table_name} nt
            LEFT JOIN {$wpdb->prefix}extractor_listings el ON nt.mls_number = el.mls_number
            WHERE nt.user_id = %d
            ORDER BY nt.sent_at DESC
            LIMIT %d
        ", $user_id, $limit));
    }

    /**
     * Get notification statistics
     */
    public function get_notification_stats($days = 30) {
        global $wpdb;

        // Use WordPress timezone-aware date calculation
        $since_date = wp_date('Y-m-d H:i:s', current_time('timestamp') - ($days * DAY_IN_SECONDS));

        $stats = $wpdb->get_row($wpdb->prepare("
            SELECT
                COUNT(*) as total_notifications,
                COUNT(DISTINCT user_id) as users_notified,
                COUNT(DISTINCT mls_number) as unique_listings,
                SUM(email_sent) as emails_sent,
                SUM(buddyboss_sent) as buddyboss_sent
            FROM {$this->table_name}
            WHERE sent_at >= %s
        ", $since_date));

        return $stats;
    }

    /**
     * Clean up old notification records
     */
    public function cleanup_old_records($days_to_keep = 90) {
        global $wpdb;

        // Use WordPress timezone-aware date calculation
        $cutoff_date = wp_date('Y-m-d H:i:s', current_time('timestamp') - ($days_to_keep * DAY_IN_SECONDS));

        $deleted = $wpdb->query($wpdb->prepare("
            DELETE FROM {$this->table_name}
            WHERE sent_at < %s
        ", $cutoff_date));

        if ($deleted > 0) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD Notification Tracker: Cleaned up ' . $deleted . ' old notification records');
            }
        }

        return $deleted;
    }

    /**
     * Check if notification was sent for a specific change type within time window
     *
     * Allows re-notification for different change types (e.g., new_listing vs price_change)
     *
     * @since 6.13.0
     * @param int $user_id User ID
     * @param string $listing_id Listing ID (MLS number)
     * @param string $notification_type Change type (new_listing, price_change, status_change)
     * @param int $hours_window Time window in hours (default 24)
     * @return bool True if already notified
     */
    public function was_notification_sent_for_type($user_id, $listing_id, $notification_type, $hours_window = 24) {
        global $wpdb;

        // Use WordPress timezone for time window comparison
        $wp_now = current_time('mysql');
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table_name}
             WHERE user_id = %d
               AND mls_number = %s
               AND notification_type = %s
               AND sent_at >= DATE_SUB(%s, INTERVAL %d HOUR)",
            $user_id,
            $listing_id,
            $notification_type,
            $wp_now,
            $hours_window
        ));

        return $count > 0;
    }

    /**
     * Batch check if notifications were sent for multiple listings
     *
     * @since 6.13.0
     * @param int $user_id User ID
     * @param array $checks Array of ['listing_id' => X, 'notification_type' => Y]
     * @param int $hours_window Time window in hours
     * @return array Array of listing_ids that were already notified
     */
    public function batch_check_sent($user_id, $checks, $hours_window = 24) {
        global $wpdb;

        if (empty($checks)) {
            return [];
        }

        $already_sent = [];

        // Build single query for all checks
        $conditions = [];
        $values = [];

        foreach ($checks as $check) {
            $listing_id = $check['listing_id'];
            $notification_type = $check['notification_type'];

            $conditions[] = "(mls_number = %s AND notification_type = %s)";
            $values[] = $listing_id;
            $values[] = $notification_type;
        }

        if (empty($conditions)) {
            return [];
        }

        // Use WordPress timezone for time window comparison
        $wp_now = current_time('mysql');
        $sql = $wpdb->prepare(
            "SELECT mls_number, notification_type FROM {$this->table_name}
             WHERE user_id = %d
               AND sent_at >= DATE_SUB(%s, INTERVAL %d HOUR)
               AND (" . implode(' OR ', $conditions) . ")",
            array_merge([$user_id, $wp_now, $hours_window], $values)
        );

        $results = $wpdb->get_results($sql, ARRAY_A);

        foreach ($results as $row) {
            $key = $row['mls_number'] . ':' . $row['notification_type'];
            $already_sent[$key] = true;
        }

        return $already_sent;
    }

    /**
     * Batch mark multiple listings as sent
     *
     * @since 6.13.0
     * @param int $user_id User ID
     * @param int $search_id Saved search ID
     * @param array $notifications Array of ['listing_id' => X, 'notification_type' => Y]
     * @return bool Success
     */
    public function batch_mark_sent($user_id, $search_id, $notifications) {
        global $wpdb;

        if (empty($notifications)) {
            return true;
        }

        $current_time = current_time('mysql');
        $values = [];
        $placeholders = [];

        foreach ($notifications as $notification) {
            $listing_id = $notification['listing_id'];
            $notification_type = isset($notification['notification_type']) ? $notification['notification_type'] : 'listing_update';

            $values[] = $user_id;
            $values[] = $listing_id;
            $values[] = $search_id;
            $values[] = $notification_type;
            $values[] = $current_time;
            $values[] = 1; // email_sent

            $placeholders[] = "(%d, %s, %d, %s, %s, %d)";
        }

        // Use INSERT IGNORE to skip duplicates (based on unique key)
        $query = "INSERT IGNORE INTO {$this->table_name}
                  (user_id, mls_number, search_id, notification_type, sent_at, email_sent)
                  VALUES " . implode(', ', $placeholders);

        $result = $wpdb->query($wpdb->prepare($query, $values));

        if ($result === false) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD Notification Tracker: Batch insert failed. Error: ' . $wpdb->last_error);
            }
            return false;
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Notification Tracker: Batch marked ' . count($notifications) . ' notifications for user ' . $user_id);
        }

        return true;
    }

    /**
     * Record a single notification with listing_id alias (for 15-min processor compatibility)
     *
     * @since 6.13.0
     * @param int $user_id User ID
     * @param string $listing_id Listing ID
     * @param string $notification_type Change type
     * @param int $search_id Saved search ID
     * @return bool Success
     */
    public function record_notification($user_id, $listing_id, $notification_type, $search_id = 0) {
        global $wpdb;

        $result = $wpdb->insert(
            $this->table_name,
            [
                'user_id' => $user_id,
                'mls_number' => $listing_id,
                'search_id' => $search_id,
                'notification_type' => $notification_type,
                'sent_at' => current_time('mysql'),
                'email_sent' => 1
            ],
            ['%d', '%s', '%d', '%s', '%s', '%d']
        );

        return $result !== false;
    }

    /**
     * Get notifications for a specific search
     *
     * @since 6.13.0
     * @param int $search_id Saved search ID
     * @param int $hours Lookback hours
     * @return array Notifications
     */
    public function get_search_notifications($search_id, $hours = 24) {
        global $wpdb;

        // Use WordPress timezone for time window comparison
        $wp_now = current_time('mysql');
        return $wpdb->get_results($wpdb->prepare(
            "SELECT mls_number as listing_id, notification_type, sent_at
             FROM {$this->table_name}
             WHERE search_id = %d
               AND sent_at >= DATE_SUB(%s, INTERVAL %d HOUR)
             ORDER BY sent_at DESC",
            $search_id,
            $wp_now,
            $hours
        ), ARRAY_A);
    }

    /**
     * Get recent notifications for debugging
     */
    public function get_recent_notifications($limit = 20) {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare("
            SELECT nt.*, u.user_email, u.display_name
            FROM {$this->table_name} nt
            LEFT JOIN {$wpdb->users} u ON nt.user_id = u.ID
            ORDER BY nt.sent_at DESC
            LIMIT %d
        ", $limit));
    }

    /**
     * Remove all notifications for a user (for GDPR compliance)
     */
    public function remove_user_data($user_id) {
        global $wpdb;

        $deleted = $wpdb->delete(
            $this->table_name,
            ['user_id' => $user_id],
            ['%d']
        );

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Notification Tracker: Removed ' . $deleted . ' notification records for user ' . $user_id);
        }
        return $deleted;
    }

    /**
     * Get table name for external access
     */
    public function get_table_name() {
        return $this->table_name;
    }

    /**
     * Drop the tracking table (for uninstall)
     */
    public static function drop_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'mld_notification_tracker';
        $wpdb->query("DROP TABLE IF EXISTS {$table_name}");
    }
}