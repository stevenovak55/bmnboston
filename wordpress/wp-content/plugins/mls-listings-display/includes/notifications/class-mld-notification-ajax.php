<?php
/**
 * Notification AJAX Handlers
 *
 * Provides AJAX endpoints for the web notification center.
 * Uses WordPress nonce authentication instead of JWT.
 *
 * @package MLS_Listings_Display
 * @subpackage Notifications
 * @since 6.50.9
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Notification_Ajax {

    /**
     * Initialize AJAX handlers
     */
    public static function init() {
        // Get notifications
        add_action('wp_ajax_mld_get_notifications', array(__CLASS__, 'get_notifications'));

        // Get unread count
        add_action('wp_ajax_mld_get_unread_count', array(__CLASS__, 'get_unread_count'));

        // Mark notification as read
        add_action('wp_ajax_mld_mark_notification_read', array(__CLASS__, 'mark_read'));

        // Dismiss notification
        add_action('wp_ajax_mld_dismiss_notification', array(__CLASS__, 'dismiss'));

        // Mark all as read
        add_action('wp_ajax_mld_mark_all_read', array(__CLASS__, 'mark_all_read'));

        // Dismiss all notifications
        add_action('wp_ajax_mld_dismiss_all', array(__CLASS__, 'dismiss_all'));

        // Get notification preferences
        add_action('wp_ajax_mld_get_notification_preferences', array(__CLASS__, 'get_preferences'));

        // Update notification preferences
        add_action('wp_ajax_mld_update_notification_preferences', array(__CLASS__, 'update_preferences'));
    }

    /**
     * Get notifications for current user
     */
    public static function get_notifications() {
        check_ajax_referer('mld_notification_nonce', 'nonce');

        global $wpdb;

        $user_id = get_current_user_id();

        if (!$user_id) {
            wp_send_json_error(array('message' => 'Not authenticated'), 401);
            return;
        }

        // Parse parameters
        $limit = min(absint($_POST['limit'] ?? 50), 100);
        $offset = absint($_POST['offset'] ?? 0);
        $include_dismissed = filter_var($_POST['include_dismissed'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $table_name = $wpdb->prefix . 'mld_push_notification_log';

        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") !== $table_name) {
            wp_send_json_success(array(
                'notifications' => array(),
                'total' => 0,
                'unread_count' => 0,
                'has_more' => false
            ));
            return;
        }

        // Build WHERE clause
        $where = array("user_id = %d", "status IN ('sent', 'failed')");
        $params = array($user_id);

        // Filter out dismissed notifications by default
        if (!$include_dismissed) {
            $where[] = "(is_dismissed = 0 OR is_dismissed IS NULL)";
        }

        $where_sql = implode(' AND ', $where);

        // Deduplicate notifications by content signature within same hour
        // This prevents showing duplicate entries when the same notification was sent to multiple devices
        // Matches the iOS endpoint deduplication logic (v6.53.0+)
        $dedup_group_by = "user_id, notification_type,
                           COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.listing_id')), title),
                           DATE_FORMAT(created_at, '%%Y-%%m-%%d %%H')";

        // Get total count (of unique notifications after deduplication)
        $count_sql = "SELECT COUNT(*) FROM (
                          SELECT 1 FROM {$table_name}
                          WHERE {$where_sql}
                          GROUP BY {$dedup_group_by}
                      ) as unique_notifications";
        $total = (int) $wpdb->get_var($wpdb->prepare($count_sql, $params));

        // Get unread count (deduplicated)
        $unread_sql = "SELECT COUNT(*) FROM (
                           SELECT 1 FROM {$table_name}
                           WHERE user_id = %d AND status IN ('sent', 'failed')
                           AND (is_read = 0 OR is_read IS NULL)
                           AND (is_dismissed = 0 OR is_dismissed IS NULL)
                           GROUP BY {$dedup_group_by}
                       ) as unique_unread";
        $unread_count = (int) $wpdb->get_var($wpdb->prepare($unread_sql, $user_id));

        // Get notifications (most recent first, deduplicated)
        // For each unique notification group, we take:
        // - MIN(id) as the representative ID
        // - MIN(created_at) as the earliest send time
        // - MAX(is_read) to capture if ANY copy was read
        // - MAX(is_dismissed) to capture if ANY copy was dismissed
        $query_sql = "SELECT
                          MIN(id) as id,
                          notification_type,
                          title,
                          body,
                          MAX(payload) as payload,
                          MIN(created_at) as created_at,
                          MAX(COALESCE(is_read, 0)) as is_read,
                          MAX(read_at) as read_at,
                          MAX(COALESCE(is_dismissed, 0)) as is_dismissed,
                          MAX(dismissed_at) as dismissed_at
                      FROM {$table_name}
                      WHERE {$where_sql}
                      GROUP BY {$dedup_group_by}
                      ORDER BY MIN(created_at) DESC
                      LIMIT %d OFFSET %d";

        $query_params = array_merge($params, array($limit, $offset));
        $rows = $wpdb->get_results($wpdb->prepare($query_sql, $query_params));

        // Format notifications
        $notifications = array();
        foreach ($rows as $row) {
            $payload = json_decode($row->payload, true);

            // Extract key fields from payload
            $listing_id = null;
            $listing_key = null;
            $image_url = null;
            $saved_search_id = null;
            $saved_search_name = null;
            $property_address = null;
            $appointment_id = null;
            $client_id = null;

            if (is_array($payload)) {
                $listing_id = $payload['listing_id'] ?? null;
                $listing_key = $payload['listing_key'] ?? null;
                $image_url = $payload['image_url'] ?? ($payload['photo_url'] ?? null);
                $saved_search_id = $payload['saved_search_id'] ?? null;
                $saved_search_name = $payload['saved_search_name'] ?? null;
                $property_address = $payload['property_address'] ?? null;
                $appointment_id = $payload['appointment_id'] ?? null;
                $client_id = $payload['client_id'] ?? null;
            }

            // Determine notification icon/type for web display
            $icon = self::get_notification_icon($row->notification_type);

            $notifications[] = array(
                'id' => (int) $row->id,
                'notification_type' => $row->notification_type,
                'title' => $row->title,
                'body' => $row->body,
                'listing_id' => $listing_id !== null ? (string) $listing_id : null,
                'listing_key' => $listing_key,
                'image_url' => $image_url,
                'saved_search_id' => $saved_search_id !== null ? (int) $saved_search_id : null,
                'saved_search_name' => $saved_search_name,
                'property_address' => $property_address,
                'appointment_id' => $appointment_id !== null ? (int) $appointment_id : null,
                'client_id' => $client_id !== null ? (int) $client_id : null,
                'sent_at' => self::format_time_ago($row->created_at),
                'sent_at_full' => self::format_full_date($row->created_at),
                'is_read' => (bool) $row->is_read,
                'is_dismissed' => (bool) $row->is_dismissed,
                'icon' => $icon,
                'url' => self::get_notification_url($row->notification_type, $listing_id, $listing_key, $saved_search_id, $appointment_id),
            );
        }

        wp_send_json_success(array(
            'notifications' => $notifications,
            'total' => $total,
            'unread_count' => $unread_count,
            'has_more' => ($offset + count($notifications)) < $total
        ));
    }

    /**
     * Get unread notification count
     */
    public static function get_unread_count() {
        check_ajax_referer('mld_notification_nonce', 'nonce');

        global $wpdb;

        $user_id = get_current_user_id();

        if (!$user_id) {
            wp_send_json_error(array('message' => 'Not authenticated'), 401);
            return;
        }

        $table_name = $wpdb->prefix . 'mld_push_notification_log';

        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") !== $table_name) {
            wp_send_json_success(array('count' => 0));
            return;
        }

        // Deduplicate by content signature within same hour (matches iOS endpoint)
        $dedup_group_by = "user_id, notification_type,
                           COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.listing_id')), title),
                           DATE_FORMAT(created_at, '%%Y-%%m-%%d %%H')";

        $unread_sql = "SELECT COUNT(*) FROM (
                           SELECT 1 FROM {$table_name}
                           WHERE user_id = %d AND status IN ('sent', 'failed')
                           AND (is_read = 0 OR is_read IS NULL)
                           AND (is_dismissed = 0 OR is_dismissed IS NULL)
                           GROUP BY {$dedup_group_by}
                       ) as unique_unread";
        $count = (int) $wpdb->get_var($wpdb->prepare($unread_sql, $user_id));

        wp_send_json_success(array('count' => $count));
    }

    /**
     * Mark a notification as read
     * Updates ALL related notification rows (multi-device support)
     */
    public static function mark_read() {
        check_ajax_referer('mld_notification_nonce', 'nonce');

        global $wpdb;

        $user_id = get_current_user_id();
        $notification_id = absint($_POST['notification_id'] ?? 0);

        if (!$user_id) {
            wp_send_json_error(array('message' => 'Not authenticated'), 401);
            return;
        }

        if (!$notification_id) {
            wp_send_json_error(array('message' => 'Notification ID required'), 400);
            return;
        }

        $table_name = $wpdb->prefix . 'mld_push_notification_log';

        // Get the notification to find its content signature
        $notification = $wpdb->get_row($wpdb->prepare(
            "SELECT id, notification_type, title, payload, created_at
             FROM {$table_name}
             WHERE id = %d AND user_id = %d",
            $notification_id,
            $user_id
        ));

        if (!$notification) {
            wp_send_json_error(array('message' => 'Notification not found'), 404);
            return;
        }

        // Extract listing_id from payload for matching related notifications
        $payload = json_decode($notification->payload, true);
        $listing_id = isset($payload['listing_id']) ? $payload['listing_id'] : null;

        // Get the hour bucket for deduplication matching
        // v6.75.4: Use DateTime with wp_timezone() instead of strtotime() which assumes UTC
        $dt = new DateTime($notification->created_at, wp_timezone());
        $hour_bucket = $dt->format('Y-m-d H');

        // Update ALL related notification rows (same type, listing/title, hour)
        // This keeps iOS and web in sync since they show deduplicated views
        if ($listing_id !== null) {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$table_name}
                 SET is_read = 1, read_at = %s
                 WHERE user_id = %d
                 AND notification_type = %s
                 AND JSON_UNQUOTE(JSON_EXTRACT(payload, '$.listing_id')) = %s
                 AND DATE_FORMAT(created_at, '%%Y-%%m-%%d %%H') = %s",
                current_time('mysql'),
                $user_id,
                $notification->notification_type,
                (string) $listing_id,
                $hour_bucket
            ));
        } else {
            // Fallback: match by title if no listing_id
            $wpdb->query($wpdb->prepare(
                "UPDATE {$table_name}
                 SET is_read = 1, read_at = %s
                 WHERE user_id = %d
                 AND notification_type = %s
                 AND title = %s
                 AND DATE_FORMAT(created_at, '%%Y-%%m-%%d %%H') = %s",
                current_time('mysql'),
                $user_id,
                $notification->notification_type,
                $notification->title,
                $hour_bucket
            ));
        }

        // Get updated unread count (deduplicated)
        $dedup_group_by = "user_id, notification_type,
                           COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.listing_id')), title),
                           DATE_FORMAT(created_at, '%%Y-%%m-%%d %%H')";

        $unread_sql = "SELECT COUNT(*) FROM (
                           SELECT 1 FROM {$table_name}
                           WHERE user_id = %d AND status IN ('sent', 'failed')
                           AND (is_read = 0 OR is_read IS NULL)
                           AND (is_dismissed = 0 OR is_dismissed IS NULL)
                           GROUP BY {$dedup_group_by}
                       ) as unique_unread";
        $unread_count = (int) $wpdb->get_var($wpdb->prepare($unread_sql, $user_id));

        wp_send_json_success(array(
            'notification_id' => $notification_id,
            'unread_count' => $unread_count
        ));
    }

    /**
     * Dismiss a notification
     * Updates ALL related notification rows (multi-device support)
     */
    public static function dismiss() {
        check_ajax_referer('mld_notification_nonce', 'nonce');

        global $wpdb;

        $user_id = get_current_user_id();
        $notification_id = absint($_POST['notification_id'] ?? 0);

        if (!$user_id) {
            wp_send_json_error(array('message' => 'Not authenticated'), 401);
            return;
        }

        if (!$notification_id) {
            wp_send_json_error(array('message' => 'Notification ID required'), 400);
            return;
        }

        $table_name = $wpdb->prefix . 'mld_push_notification_log';

        // Get the notification to find its content signature
        $notification = $wpdb->get_row($wpdb->prepare(
            "SELECT id, notification_type, title, payload, created_at
             FROM {$table_name}
             WHERE id = %d AND user_id = %d",
            $notification_id,
            $user_id
        ));

        if (!$notification) {
            wp_send_json_error(array('message' => 'Notification not found'), 404);
            return;
        }

        // Extract listing_id from payload for matching related notifications
        $payload = json_decode($notification->payload, true);
        $listing_id = isset($payload['listing_id']) ? $payload['listing_id'] : null;

        // Get the hour bucket for deduplication matching
        // v6.75.4: Use DateTime with wp_timezone() instead of strtotime() which assumes UTC
        $dt_dismiss = new DateTime($notification->created_at, wp_timezone());
        $hour_bucket = $dt_dismiss->format('Y-m-d H');

        // Update ALL related notification rows (same type, listing/title, hour)
        // This keeps iOS and web in sync since they show deduplicated views
        if ($listing_id !== null) {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$table_name}
                 SET is_dismissed = 1, dismissed_at = %s
                 WHERE user_id = %d
                 AND notification_type = %s
                 AND JSON_UNQUOTE(JSON_EXTRACT(payload, '$.listing_id')) = %s
                 AND DATE_FORMAT(created_at, '%%Y-%%m-%%d %%H') = %s",
                current_time('mysql'),
                $user_id,
                $notification->notification_type,
                (string) $listing_id,
                $hour_bucket
            ));
        } else {
            // Fallback: match by title if no listing_id
            $wpdb->query($wpdb->prepare(
                "UPDATE {$table_name}
                 SET is_dismissed = 1, dismissed_at = %s
                 WHERE user_id = %d
                 AND notification_type = %s
                 AND title = %s
                 AND DATE_FORMAT(created_at, '%%Y-%%m-%%d %%H') = %s",
                current_time('mysql'),
                $user_id,
                $notification->notification_type,
                $notification->title,
                $hour_bucket
            ));
        }

        // Get updated unread count (deduplicated)
        $dedup_group_by = "user_id, notification_type,
                           COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.listing_id')), title),
                           DATE_FORMAT(created_at, '%%Y-%%m-%%d %%H')";

        $unread_sql = "SELECT COUNT(*) FROM (
                           SELECT 1 FROM {$table_name}
                           WHERE user_id = %d AND status IN ('sent', 'failed')
                           AND (is_read = 0 OR is_read IS NULL)
                           AND (is_dismissed = 0 OR is_dismissed IS NULL)
                           GROUP BY {$dedup_group_by}
                       ) as unique_unread";
        $unread_count = (int) $wpdb->get_var($wpdb->prepare($unread_sql, $user_id));

        wp_send_json_success(array(
            'notification_id' => $notification_id,
            'unread_count' => $unread_count
        ));
    }

    /**
     * Mark all notifications as read
     */
    public static function mark_all_read() {
        check_ajax_referer('mld_notification_nonce', 'nonce');

        global $wpdb;

        $user_id = get_current_user_id();

        if (!$user_id) {
            wp_send_json_error(array('message' => 'Not authenticated'), 401);
            return;
        }

        $table_name = $wpdb->prefix . 'mld_push_notification_log';

        // Mark all unread as read
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$table_name}
             SET is_read = 1, read_at = %s
             WHERE user_id = %d
             AND status IN ('sent', 'failed')
             AND (is_read = 0 OR is_read IS NULL)",
            current_time('mysql'),
            $user_id
        ));

        wp_send_json_success(array(
            'marked_count' => (int) $updated,
            'unread_count' => 0
        ));
    }

    /**
     * Dismiss all notifications
     */
    public static function dismiss_all() {
        check_ajax_referer('mld_notification_nonce', 'nonce');

        global $wpdb;

        $user_id = get_current_user_id();

        if (!$user_id) {
            wp_send_json_error(array('message' => 'Not authenticated'), 401);
            return;
        }

        $table_name = $wpdb->prefix . 'mld_push_notification_log';

        // Dismiss all
        $dismissed = $wpdb->query($wpdb->prepare(
            "UPDATE {$table_name}
             SET is_dismissed = 1, dismissed_at = %s
             WHERE user_id = %d
             AND status IN ('sent', 'failed')
             AND (is_dismissed = 0 OR is_dismissed IS NULL)",
            current_time('mysql'),
            $user_id
        ));

        wp_send_json_success(array(
            'dismissed_count' => (int) $dismissed,
            'unread_count' => 0
        ));
    }

    /**
     * Get notification preferences
     */
    public static function get_preferences() {
        check_ajax_referer('mld_notification_nonce', 'nonce');

        $user_id = get_current_user_id();

        if (!$user_id) {
            wp_send_json_error(array('message' => 'Not authenticated'), 401);
            return;
        }

        // Check if preferences class exists
        if (!class_exists('MLD_Client_Notification_Preferences')) {
            wp_send_json_error(array('message' => 'Preferences system not available'), 500);
            return;
        }

        $preferences = MLD_Client_Notification_Preferences::get_preferences($user_id);

        // Transform flat array to structured format for frontend
        $structured = array(
            'new_listing' => array(
                'push_enabled' => $preferences['new_listing_push'] ?? true,
                'email_enabled' => $preferences['new_listing_email'] ?? true,
            ),
            'price_change' => array(
                'push_enabled' => $preferences['price_change_push'] ?? true,
                'email_enabled' => $preferences['price_change_email'] ?? true,
            ),
            'status_change' => array(
                'push_enabled' => $preferences['status_change_push'] ?? true,
                'email_enabled' => $preferences['status_change_email'] ?? true,
            ),
            'appointment' => array(
                'push_enabled' => $preferences['open_house_push'] ?? true,
                'email_enabled' => $preferences['open_house_email'] ?? true,
            ),
        );

        wp_send_json_success(array('preferences' => $structured));
    }

    /**
     * Update notification preferences
     */
    public static function update_preferences() {
        check_ajax_referer('mld_notification_nonce', 'nonce');

        $user_id = get_current_user_id();

        if (!$user_id) {
            wp_send_json_error(array('message' => 'Not authenticated'), 401);
            return;
        }

        // Check if preferences class exists
        if (!class_exists('MLD_Client_Notification_Preferences')) {
            wp_send_json_error(array('message' => 'Preferences system not available'), 500);
            return;
        }

        $notification_type = sanitize_text_field($_POST['notification_type'] ?? '');
        $push_enabled = filter_var($_POST['push_enabled'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $email_enabled = filter_var($_POST['email_enabled'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if (empty($notification_type)) {
            wp_send_json_error(array('message' => 'Notification type required'), 400);
            return;
        }

        // Map notification types to preference keys
        $type_map = array(
            'new_listing' => 'new_listing',
            'price_change' => 'price_change',
            'status_change' => 'status_change',
            'appointment' => 'open_house', // appointment maps to open_house in DB
        );

        $db_type = $type_map[$notification_type] ?? $notification_type;

        // Build preferences array for update
        $update_data = array();
        if ($push_enabled !== null) {
            $update_data[$db_type . '_push'] = $push_enabled;
        }
        if ($email_enabled !== null) {
            $update_data[$db_type . '_email'] = $email_enabled;
        }

        if (empty($update_data)) {
            wp_send_json_error(array('message' => 'No preference values provided'), 400);
            return;
        }

        $result = MLD_Client_Notification_Preferences::update_preferences($user_id, $update_data);

        if ($result !== false) {
            wp_send_json_success(array('message' => 'Preferences updated'));
        } else {
            wp_send_json_error(array('message' => 'Failed to update preferences'), 500);
        }
    }

    /**
     * Get notification icon based on type
     *
     * @param string $notification_type Notification type
     * @return string Icon name/class for web display
     */
    private static function get_notification_icon($notification_type) {
        $icons = array(
            'new_listing' => 'home',
            'price_change' => 'tag',
            'status_change' => 'bell',
            'saved_search' => 'search',
            'appointment' => 'calendar',
            'appointment_reminder' => 'calendar',
            'appointment_confirm' => 'calendar-check',
            'client_login' => 'user',
            'app_open' => 'smartphone',
            'favorite_added' => 'heart',
            'search_created' => 'search-plus',
            'tour_requested' => 'map-marker',
            'agent_activity' => 'user',
        );

        return $icons[$notification_type] ?? 'bell';
    }

    /**
     * Get URL for notification click
     *
     * @param string $notification_type Notification type
     * @param string|null $listing_id Listing ID
     * @param string|null $listing_key Listing key
     * @param int|null $saved_search_id Saved search ID
     * @param int|null $appointment_id Appointment ID
     * @return string URL
     */
    private static function get_notification_url($notification_type, $listing_id, $listing_key, $saved_search_id, $appointment_id = null) {
        // Property-related notifications
        if (in_array($notification_type, array('new_listing', 'price_change', 'status_change')) && $listing_id) {
            return home_url('/property/' . $listing_id . '/');
        }

        // Saved search notifications
        if ($notification_type === 'saved_search' && $saved_search_id) {
            return home_url('/saved-searches/?search_id=' . $saved_search_id);
        }

        // Appointment-related notifications
        if (in_array($notification_type, array('appointment_reminder', 'appointment_confirm', 'tour_requested')) && $appointment_id) {
            return home_url('/my-appointments/?appointment_id=' . $appointment_id);
        }

        // Agent activity notifications (for agent users viewing client activity)
        if (in_array($notification_type, array('client_login', 'app_open', 'favorite_added', 'search_created'))) {
            return home_url('/my-clients/');
        }

        // Default to notifications page
        return home_url('/notifications/');
    }

    /**
     * Format datetime as human-readable "time ago"
     *
     * @param string $datetime MySQL datetime
     * @return string Human-readable time
     */
    private static function format_time_ago($datetime) {
        if (empty($datetime)) {
            return '';
        }

        $timestamp = strtotime(get_date_from_gmt($datetime));
        $diff = current_time('timestamp') - $timestamp;

        if ($diff < 60) {
            return 'Just now';
        } elseif ($diff < 3600) {
            $mins = floor($diff / 60);
            return $mins . ' min' . ($mins > 1 ? 's' : '') . ' ago';
        } elseif ($diff < 86400) {
            $hours = floor($diff / 3600);
            return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
        } elseif ($diff < 604800) {
            $days = floor($diff / 86400);
            return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
        } else {
            return date_i18n('M j', $timestamp);
        }
    }

    /**
     * Format full date for tooltip
     *
     * @param string $datetime MySQL datetime
     * @return string Full formatted date
     */
    private static function format_full_date($datetime) {
        if (empty($datetime)) {
            return '';
        }

        $timestamp = strtotime(get_date_from_gmt($datetime));
        return date_i18n('F j, Y \a\t g:i a', $timestamp);
    }
}
