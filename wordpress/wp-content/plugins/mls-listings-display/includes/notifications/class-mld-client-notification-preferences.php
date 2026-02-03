<?php
/**
 * MLD Client Notification Preferences Manager
 *
 * Manages per-user notification preferences including:
 * - Per-type push/email toggles
 * - Quiet hours with timezone support
 * - Notification frequency settings
 *
 * @package MLS_Listings_Display
 * @subpackage Notifications
 * @since 6.48.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Client_Notification_Preferences {

    /**
     * Notification types that can be configured
     */
    const TYPE_NEW_LISTING = 'new_listing';
    const TYPE_PRICE_CHANGE = 'price_change';
    const TYPE_STATUS_CHANGE = 'status_change';
    const TYPE_OPEN_HOUSE = 'open_house';
    const TYPE_SAVED_SEARCH = 'saved_search';

    /**
     * Default preferences for new users
     */
    private static $defaults = [
        'new_listing_push' => true,
        'new_listing_email' => true,
        'price_change_push' => true,
        'price_change_email' => true,
        'status_change_push' => true,
        'status_change_email' => true,
        'open_house_push' => true,
        'open_house_email' => true,
        'saved_search_push' => true,
        'saved_search_email' => true,
        'quiet_hours_enabled' => false,
        'quiet_hours_start' => '22:00',
        'quiet_hours_end' => '08:00',
        'user_timezone' => 'America/New_York'
    ];

    /**
     * Get preferences for a user
     *
     * @param int $user_id WordPress user ID
     * @return array User's notification preferences
     */
    public static function get_preferences($user_id) {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_client_notification_preferences';

        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            self::maybe_create_table();
            return self::$defaults;
        }

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE user_id = %d",
            $user_id
        ), ARRAY_A);

        if (!$row) {
            return self::$defaults;
        }

        // Merge with defaults to ensure all keys exist
        return array_merge(self::$defaults, [
            'new_listing_push' => (bool) $row['new_listing_push'],
            'new_listing_email' => (bool) $row['new_listing_email'],
            'price_change_push' => (bool) $row['price_change_push'],
            'price_change_email' => (bool) $row['price_change_email'],
            'status_change_push' => (bool) $row['status_change_push'],
            'status_change_email' => (bool) $row['status_change_email'],
            'open_house_push' => (bool) $row['open_house_push'],
            'open_house_email' => (bool) $row['open_house_email'],
            'saved_search_push' => (bool) $row['saved_search_push'],
            'saved_search_email' => (bool) $row['saved_search_email'],
            'quiet_hours_enabled' => (bool) $row['quiet_hours_enabled'],
            'quiet_hours_start' => $row['quiet_hours_start'],
            'quiet_hours_end' => $row['quiet_hours_end'],
            'user_timezone' => $row['user_timezone'] ?: 'America/New_York'
        ]);
    }

    /**
     * Update preferences for a user
     *
     * @param int $user_id WordPress user ID
     * @param array $preferences Preferences to update
     * @return bool Success status
     */
    public static function update_preferences($user_id, $preferences) {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_client_notification_preferences';

        // Ensure table exists
        self::maybe_create_table();

        // Sanitize and validate preferences
        $data = self::sanitize_preferences($preferences);
        $data['user_id'] = $user_id;
        $data['updated_at'] = current_time('mysql');

        // Check if user already has preferences
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE user_id = %d",
            $user_id
        ));

        if ($exists) {
            // Update existing
            $result = $wpdb->update(
                $table,
                $data,
                ['user_id' => $user_id],
                self::get_format_array($data),
                ['%d']
            );
        } else {
            // Insert new
            $data['created_at'] = current_time('mysql');
            $result = $wpdb->insert(
                $table,
                $data,
                self::get_format_array($data)
            );
        }

        if ($result === false) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD Client Notification Preferences: Failed to save - ' . $wpdb->last_error);
            }
            return false;
        }

        return true;
    }

    /**
     * Check if a specific notification type is enabled for push
     *
     * @param int $user_id User ID
     * @param string $type Notification type (e.g., 'price_change')
     * @return bool Whether push is enabled for this type
     */
    public static function is_push_enabled($user_id, $type) {
        $prefs = self::get_preferences($user_id);
        $key = $type . '_push';
        return isset($prefs[$key]) ? (bool) $prefs[$key] : true;
    }

    /**
     * Check if a specific notification type is enabled for email
     *
     * @param int $user_id User ID
     * @param string $type Notification type (e.g., 'price_change')
     * @return bool Whether email is enabled for this type
     */
    public static function is_email_enabled($user_id, $type) {
        $prefs = self::get_preferences($user_id);
        $key = $type . '_email';
        return isset($prefs[$key]) ? (bool) $prefs[$key] : true;
    }

    /**
     * Check if current time is within quiet hours for user
     *
     * @param int $user_id User ID
     * @return bool Whether it's currently quiet hours
     */
    public static function is_quiet_hours($user_id) {
        $prefs = self::get_preferences($user_id);

        if (!$prefs['quiet_hours_enabled']) {
            return false;
        }

        try {
            $timezone = new DateTimeZone($prefs['user_timezone']);
            $now = new DateTime('now', $timezone);
            $current_time = $now->format('H:i');

            $start = $prefs['quiet_hours_start'];
            $end = $prefs['quiet_hours_end'];

            // Handle overnight quiet hours (e.g., 22:00 - 08:00)
            if ($start > $end) {
                // Quiet hours span midnight
                return ($current_time >= $start || $current_time < $end);
            } else {
                // Quiet hours within same day
                return ($current_time >= $start && $current_time < $end);
            }
        } catch (Exception $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD Client Notification Preferences: Timezone error - ' . $e->getMessage());
            }
            return false;
        }
    }

    /**
     * Get user's timezone
     *
     * @param int $user_id User ID
     * @return string Timezone string (e.g., 'America/New_York')
     */
    public static function get_user_timezone($user_id) {
        $prefs = self::get_preferences($user_id);
        return $prefs['user_timezone'];
    }

    /**
     * Update user's timezone
     *
     * @param int $user_id User ID
     * @param string $timezone Timezone string
     * @return bool Success status
     */
    public static function update_timezone($user_id, $timezone) {
        // Validate timezone
        try {
            new DateTimeZone($timezone);
        } catch (Exception $e) {
            return false;
        }

        return self::update_preferences($user_id, ['user_timezone' => $timezone]);
    }

    /**
     * Should notification be sent now or queued for later?
     *
     * @param int $user_id User ID
     * @param string $type Notification type
     * @param string $channel 'push' or 'email'
     * @return array ['send' => bool, 'reason' => string|null]
     */
    public static function should_send_now($user_id, $type, $channel = 'push') {
        // Check if channel is enabled for this type
        if ($channel === 'push' && !self::is_push_enabled($user_id, $type)) {
            return ['send' => false, 'reason' => 'push_disabled'];
        }
        if ($channel === 'email' && !self::is_email_enabled($user_id, $type)) {
            return ['send' => false, 'reason' => 'email_disabled'];
        }

        // Check quiet hours (only for push)
        if ($channel === 'push' && self::is_quiet_hours($user_id)) {
            return ['send' => false, 'reason' => 'quiet_hours'];
        }

        return ['send' => true, 'reason' => null];
    }

    /**
     * Sanitize preferences array
     *
     * @param array $preferences Raw preferences
     * @return array Sanitized preferences
     */
    private static function sanitize_preferences($preferences) {
        $sanitized = [];

        // Boolean fields
        $bool_fields = [
            'new_listing_push', 'new_listing_email',
            'price_change_push', 'price_change_email',
            'status_change_push', 'status_change_email',
            'open_house_push', 'open_house_email',
            'saved_search_push', 'saved_search_email',
            'quiet_hours_enabled'
        ];

        foreach ($bool_fields as $field) {
            if (isset($preferences[$field])) {
                $sanitized[$field] = (bool) $preferences[$field] ? 1 : 0;
            }
        }

        // Time fields (HH:MM format)
        if (isset($preferences['quiet_hours_start'])) {
            $sanitized['quiet_hours_start'] = self::sanitize_time($preferences['quiet_hours_start']);
        }
        if (isset($preferences['quiet_hours_end'])) {
            $sanitized['quiet_hours_end'] = self::sanitize_time($preferences['quiet_hours_end']);
        }

        // Timezone field
        if (isset($preferences['user_timezone'])) {
            try {
                new DateTimeZone($preferences['user_timezone']);
                $sanitized['user_timezone'] = $preferences['user_timezone'];
            } catch (Exception $e) {
                // Invalid timezone, don't update
            }
        }

        return $sanitized;
    }

    /**
     * Sanitize time string to HH:MM format
     *
     * @param string $time Time string
     * @return string Sanitized time in HH:MM format
     */
    private static function sanitize_time($time) {
        if (preg_match('/^([0-1]?[0-9]|2[0-3]):([0-5][0-9])$/', $time, $matches)) {
            return sprintf('%02d:%02d', $matches[1], $matches[2]);
        }
        return '00:00';
    }

    /**
     * Get format array for wpdb operations
     *
     * @param array $data Data array
     * @return array Format specifiers
     */
    private static function get_format_array($data) {
        $formats = [];
        foreach ($data as $key => $value) {
            if (in_array($key, ['user_id', 'id'])) {
                $formats[] = '%d';
            } elseif (is_bool($value) || is_int($value) || in_array($key, [
                'new_listing_push', 'new_listing_email',
                'price_change_push', 'price_change_email',
                'status_change_push', 'status_change_email',
                'open_house_push', 'open_house_email',
                'saved_search_push', 'saved_search_email',
                'quiet_hours_enabled'
            ])) {
                $formats[] = '%d';
            } else {
                $formats[] = '%s';
            }
        }
        return $formats;
    }

    /**
     * Create preferences table if it doesn't exist
     */
    public static function maybe_create_table() {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_client_notification_preferences';
        $charset_collate = $wpdb->get_charset_collate();

        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table) {
            return;
        }

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        $sql = "CREATE TABLE {$table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            new_listing_push TINYINT(1) DEFAULT 1,
            new_listing_email TINYINT(1) DEFAULT 1,
            price_change_push TINYINT(1) DEFAULT 1,
            price_change_email TINYINT(1) DEFAULT 1,
            status_change_push TINYINT(1) DEFAULT 1,
            status_change_email TINYINT(1) DEFAULT 1,
            open_house_push TINYINT(1) DEFAULT 1,
            open_house_email TINYINT(1) DEFAULT 1,
            saved_search_push TINYINT(1) DEFAULT 1,
            saved_search_email TINYINT(1) DEFAULT 1,
            quiet_hours_enabled TINYINT(1) DEFAULT 0,
            quiet_hours_start TIME DEFAULT '22:00:00',
            quiet_hours_end TIME DEFAULT '08:00:00',
            user_timezone VARCHAR(50) DEFAULT 'America/New_York',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_user (user_id)
        ) {$charset_collate}";

        dbDelta($sql);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Client Notification Preferences: Created table');
        }
    }

    /**
     * Delete preferences for a user (e.g., when user is deleted)
     *
     * @param int $user_id User ID
     * @return bool Success status
     */
    public static function delete_preferences($user_id) {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_client_notification_preferences';

        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            return true;
        }

        return $wpdb->delete($table, ['user_id' => $user_id], ['%d']) !== false;
    }

    /**
     * Get all available notification types with labels
     *
     * @return array Notification types with labels
     */
    public static function get_notification_types() {
        return [
            self::TYPE_SAVED_SEARCH => [
                'label' => 'Saved Search Matches',
                'description' => 'New listings matching your saved searches',
                'icon' => 'magnifyingglass'
            ],
            self::TYPE_PRICE_CHANGE => [
                'label' => 'Price Drops',
                'description' => 'Price reductions on your saved properties',
                'icon' => 'tag'
            ],
            self::TYPE_STATUS_CHANGE => [
                'label' => 'Status Changes',
                'description' => 'When saved properties go pending or sold',
                'icon' => 'arrow.triangle.swap'
            ],
            self::TYPE_OPEN_HOUSE => [
                'label' => 'Open Houses',
                'description' => 'Open house announcements for saved properties',
                'icon' => 'door.left.hand.open'
            ],
            self::TYPE_NEW_LISTING => [
                'label' => 'New Listings',
                'description' => 'General new listing alerts',
                'icon' => 'house'
            ]
        ];
    }

    /**
     * Get common timezones for UI selection
     *
     * @return array Timezone options
     */
    public static function get_timezone_options() {
        return [
            'America/New_York' => 'Eastern Time (ET)',
            'America/Chicago' => 'Central Time (CT)',
            'America/Denver' => 'Mountain Time (MT)',
            'America/Los_Angeles' => 'Pacific Time (PT)',
            'America/Anchorage' => 'Alaska Time (AKT)',
            'Pacific/Honolulu' => 'Hawaii Time (HT)',
            'UTC' => 'UTC'
        ];
    }

    // ============================================
    // QUIET HOURS NOTIFICATION QUEUING (v6.50.7)
    // ============================================

    /**
     * Get when quiet hours end for a user
     *
     * @param int $user_id User ID
     * @return DateTime|null DateTime when quiet hours end, or null if not in quiet hours
     * @since 6.50.7
     */
    public static function get_quiet_hours_end_time($user_id) {
        $prefs = self::get_preferences($user_id);

        if (!$prefs['quiet_hours_enabled']) {
            return null;
        }

        try {
            $timezone = new DateTimeZone($prefs['user_timezone']);
            $now = new DateTime('now', $timezone);
            $current_time = $now->format('H:i');

            $start = $prefs['quiet_hours_start'];
            $end = $prefs['quiet_hours_end'];

            // Check if currently in quiet hours
            $in_quiet_hours = false;
            if ($start > $end) {
                // Overnight quiet hours (e.g., 22:00 - 08:00)
                $in_quiet_hours = ($current_time >= $start || $current_time < $end);
            } else {
                // Same-day quiet hours
                $in_quiet_hours = ($current_time >= $start && $current_time < $end);
            }

            if (!$in_quiet_hours) {
                return null;
            }

            // Calculate when quiet hours end
            $end_time = new DateTime($end, $timezone);

            // If overnight and we're after midnight, end is today
            // If overnight and we're before midnight, end is tomorrow
            if ($start > $end && $current_time >= $start) {
                // We're before midnight, so end is tomorrow
                $end_time->modify('+1 day');
            }

            // If end time is in the past (shouldn't happen but safety check)
            if ($end_time <= $now) {
                $end_time->modify('+1 day');
            }

            return $end_time;

        } catch (Exception $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD Client Notification Preferences: Error calculating quiet hours end - ' . $e->getMessage());
            }
            return null;
        }
    }

    /**
     * Queue a notification for delivery after quiet hours end
     *
     * @param int $user_id User ID
     * @param string $notification_type Notification type (new_listing, price_change, etc.)
     * @param array $payload Notification payload data
     * @return int|false Inserted ID or false on failure
     * @since 6.50.7
     */
    public static function queue_for_quiet_hours($user_id, $notification_type, $payload) {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_deferred_notifications';

        // Ensure table exists
        self::maybe_create_deferred_table();

        // Calculate when to deliver
        $deliver_after = self::get_quiet_hours_end_time($user_id);
        if (!$deliver_after) {
            // Not in quiet hours, shouldn't queue
            return false;
        }

        // Check for duplicate (same user, type, and listing within 24 hours)
        $listing_id = $payload['listing_id'] ?? null;
        if ($listing_id) {
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table}
                 WHERE user_id = %d
                   AND notification_type = %s
                   AND listing_id = %s
                   AND status = 'pending'
                   AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)",
                $user_id,
                $notification_type,
                $listing_id
            ));

            if ($existing) {
                // Already queued, skip duplicate
                return false;
            }
        }

        $result = $wpdb->insert(
            $table,
            [
                'user_id' => $user_id,
                'notification_type' => $notification_type,
                'listing_id' => $listing_id,
                'payload' => json_encode($payload),
                'deliver_after' => $deliver_after->format('Y-m-d H:i:s'),
                'status' => 'pending',
                'created_at' => current_time('mysql')
            ],
            ['%d', '%s', '%s', '%s', '%s', '%s', '%s']
        );

        if ($result === false) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD Deferred Notifications: Failed to queue - ' . $wpdb->last_error);
            }
            return false;
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                'MLD Deferred Notifications: Queued notification for user %d, type %s, deliver after %s',
                $user_id,
                $notification_type,
                $deliver_after->format('Y-m-d H:i:s')
            ));
        }

        return $wpdb->insert_id;
    }

    /**
     * Get pending deferred notifications ready for delivery
     *
     * @param int $limit Maximum number to retrieve
     * @return array Array of deferred notification records
     * @since 6.50.7
     */
    public static function get_pending_deferred_notifications($limit = 100) {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_deferred_notifications';

        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            return [];
        }

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE status = 'pending'
               AND deliver_after <= NOW()
             ORDER BY deliver_after ASC
             LIMIT %d",
            $limit
        ));
    }

    /**
     * Mark a deferred notification as processed
     *
     * @param int $id Deferred notification ID
     * @param string $status New status ('sent', 'failed', 'skipped')
     * @param string|null $error_message Optional error message
     * @return bool Success status
     * @since 6.50.7
     */
    public static function mark_deferred_processed($id, $status, $error_message = null) {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_deferred_notifications';

        $data = [
            'status' => $status,
            'processed_at' => current_time('mysql')
        ];

        if ($error_message) {
            $data['error_message'] = $error_message;
        }

        return $wpdb->update(
            $table,
            $data,
            ['id' => $id],
            ['%s', '%s', '%s'],
            ['%d']
        ) !== false;
    }

    /**
     * Create deferred notifications table if it doesn't exist
     *
     * @since 6.50.7
     */
    public static function maybe_create_deferred_table() {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_deferred_notifications';
        $charset_collate = $wpdb->get_charset_collate();

        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table) {
            return;
        }

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        $sql = "CREATE TABLE {$table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            notification_type VARCHAR(50) NOT NULL,
            listing_id VARCHAR(50) DEFAULT NULL,
            payload LONGTEXT NOT NULL,
            deliver_after DATETIME NOT NULL,
            status ENUM('pending', 'sent', 'failed', 'skipped') DEFAULT 'pending',
            error_message TEXT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            processed_at DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_user_status (user_id, status),
            KEY idx_deliver_after (deliver_after, status),
            KEY idx_listing (listing_id)
        ) {$charset_collate}";

        dbDelta($sql);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Client Notification Preferences: Created deferred notifications table');
        }
    }

    /**
     * Cleanup old processed deferred notifications (older than 7 days)
     *
     * @return int Number of rows deleted
     * @since 6.50.7
     */
    public static function cleanup_deferred_notifications() {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_deferred_notifications';

        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            return 0;
        }

        $deleted = $wpdb->query(
            "DELETE FROM {$table}
             WHERE status != 'pending'
               AND processed_at < DATE_SUB(NOW(), INTERVAL 7 DAY)"
        );

        return $deleted !== false ? $deleted : 0;
    }

    /**
     * Get deferred notification statistics
     *
     * @return array Statistics about deferred notifications
     * @since 6.50.7
     */
    public static function get_deferred_notification_stats() {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_deferred_notifications';

        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            return [
                'pending' => 0,
                'sent' => 0,
                'failed' => 0,
                'skipped' => 0,
                'oldest_pending' => null
            ];
        }

        $stats = $wpdb->get_row("
            SELECT
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                SUM(CASE WHEN status = 'skipped' THEN 1 ELSE 0 END) as skipped,
                MIN(CASE WHEN status = 'pending' THEN deliver_after ELSE NULL END) as oldest_pending
            FROM {$table}
            WHERE created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
        ");

        return [
            'pending' => (int) ($stats->pending ?? 0),
            'sent' => (int) ($stats->sent ?? 0),
            'failed' => (int) ($stats->failed ?? 0),
            'skipped' => (int) ($stats->skipped ?? 0),
            'oldest_pending' => $stats->oldest_pending
        ];
    }
}
