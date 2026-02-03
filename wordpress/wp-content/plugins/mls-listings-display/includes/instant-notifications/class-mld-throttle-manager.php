<?php
/**
 * MLD Throttle Manager - Prevent notification spam
 *
 * Manages notification throttling and rate limiting
 *
 * @package MLS_Listings_Display
 * @subpackage Instant_Notifications
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Throttle_Manager {

    /**
     * User notification cache
     */
    private $user_notification_cache = [];

    /**
     * Is bulk import active
     */
    private $is_bulk_import_active = false;

    /**
     * Constructor
     */
    public function __construct() {
        // Check for bulk import on initialization
        $this->check_bulk_import_status();
    }

    /**
     * Check if notification should be sent instantly
     *
     * @param int $user_id User ID
     * @param int $search_id Search ID
     * @param string $type Notification type
     * @param array $listing_data Optional listing data for queueing
     * @return bool|array True if can send, array with queue info if throttled
     */
    public function should_send_instant($user_id, $search_id, $type = 'new_listing', $listing_data = []) {
        // Check if throttling is globally disabled
        if (!get_option('mld_global_throttling_enabled', true)) {
            // Throttling disabled by admin - send all notifications
            $this->record_notification($user_id, $search_id);
            return true;
        }

        // Check user's throttling preference
        $preferences = $this->get_user_preferences($user_id);
        if (isset($preferences['throttling_enabled']) && !$preferences['throttling_enabled']) {
            // User has throttling disabled
            $this->record_notification($user_id, $search_id);
            return true;
        }

        // Check quiet hours (already checks global settings internally)
        if ($this->is_quiet_hours($user_id)) {
            $this->log_throttle($user_id, $search_id, 'quiet_hours');
            // Calculate retry time (end of quiet hours)
            $preferences = $this->get_user_preferences($user_id);
            $quiet_end = $preferences['quiet_hours_end'] ?? '06:00:00';
            $retry_after = $this->calculate_retry_after_quiet_hours($quiet_end);
            return ['blocked' => true, 'reason' => 'quiet_hours', 'retry_after' => $retry_after];
        }

        // Check daily limit
        if ($this->exceeds_daily_limit($user_id, $search_id)) {
            $this->log_throttle($user_id, $search_id, 'daily_limit');
            // Retry tomorrow at 6 AM using WordPress timezone
            $wp_tomorrow = wp_date('Y-m-d', current_time('timestamp') + DAY_IN_SECONDS);
            $retry_after = $wp_tomorrow . ' 06:00:00';
            return ['blocked' => true, 'reason' => 'daily_limit', 'retry_after' => $retry_after];
        }

        // Check rate limiting (prevent spam)
        if ($this->is_rate_limited($user_id, $search_id)) {
            $this->log_throttle($user_id, $search_id, 'rate_limited');
            // Retry in 5 minutes using WordPress timezone
            $retry_after = wp_date('Y-m-d H:i:s', current_time('timestamp') + (5 * MINUTE_IN_SECONDS));
            return ['blocked' => true, 'reason' => 'rate_limited', 'retry_after' => $retry_after];
        }

        // Check if bulk import is active (triggers at >90 listings/minute)
        if ($this->is_bulk_import_active && get_option('mld_enable_bulk_import_throttle', true)) {
            $this->queue_for_batch($user_id, $search_id, $type, $listing_data);
            // Retry in 30 minutes when bulk import should be done using WordPress timezone
            $retry_after = wp_date('Y-m-d H:i:s', current_time('timestamp') + (30 * MINUTE_IN_SECONDS));
            return ['blocked' => true, 'reason' => 'bulk_import', 'retry_after' => $retry_after];
        }

        // OK to send instantly
        $this->record_notification($user_id, $search_id);
        return true;
    }

    /**
     * Check if current time is within user's quiet hours
     */
    private function is_quiet_hours($user_id) {
        // Check if quiet hours are globally disabled
        if (!get_option('mld_global_quiet_hours_enabled', true)) {
            return false;
        }

        $preferences = $this->get_user_preferences($user_id);

        // Check if user has quiet hours disabled
        if (isset($preferences['quiet_hours_enabled']) && !$preferences['quiet_hours_enabled']) {
            return false;
        }

        // Determine which quiet hour settings to use
        if (get_option('mld_override_user_preferences', false)) {
            // Use global settings
            $quiet_start = get_option('mld_default_quiet_start', '22:00') . ':00';
            $quiet_end = get_option('mld_default_quiet_end', '06:00') . ':00';
        } else {
            // Use user's settings or defaults
            $quiet_start = $preferences['quiet_hours_start'] ?? '22:00:00';
            $quiet_end = $preferences['quiet_hours_end'] ?? '06:00:00';
        }

        $current_time = current_time('H:i:s');

        // Handle overnight quiet hours
        if ($quiet_start > $quiet_end) {
            return ($current_time >= $quiet_start || $current_time <= $quiet_end);
        } else {
            return ($current_time >= $quiet_start && $current_time <= $quiet_end);
        }
    }

    /**
     * Check if user has exceeded daily notification limit
     */
    private function exceeds_daily_limit($user_id, $search_id) {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_notification_throttle';
        $today = current_time('Y-m-d');

        // Get or create today's record
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT notification_count FROM {$table}
             WHERE user_id = %d AND saved_search_id = %d AND notification_date = %s",
            $user_id, $search_id, $today
        ));

        if ($count === null) {
            // Create record for today
            $wpdb->insert($table, [
                'user_id' => $user_id,
                'saved_search_id' => $search_id,
                'notification_date' => $today,
                'notification_count' => 0,
                'created_at' => current_time('mysql')
            ]);
            $count = 0;
        }

        $preferences = $this->get_user_preferences($user_id);
        $max_daily = $preferences['max_daily_notifications'] ?? 50;

        return ($count >= $max_daily);
    }

    /**
     * Implement rate limiting to prevent notification spam
     */
    private function is_rate_limited($user_id, $search_id) {
        $cache_key = sprintf('mld_rate_%d_%d', $user_id, $search_id);
        $last_sent = get_transient($cache_key);

        // During bulk imports, allow up to 30 notifications without rate limiting
        $bulk_import_cache_key = sprintf('mld_bulk_notify_%d_%d', $user_id, $search_id);
        $bulk_count = get_transient($bulk_import_cache_key);

        if ($bulk_count === false) {
            $bulk_count = 0;
        }

        // If we're in a bulk import scenario (multiple listings within 5 seconds)
        if ($last_sent && (time() - $last_sent) < 5) {
            $bulk_count++;
            set_transient($bulk_import_cache_key, $bulk_count, 300); // Remember for 5 minutes

            // Allow up to 30 notifications during bulk import
            if ($bulk_count <= 30) {
                return false; // Not rate limited
            }
            return true; // Rate limited after 30 notifications
        } else {
            // Reset bulk count if more than 5 seconds since last notification
            delete_transient($bulk_import_cache_key);

            // Normal rate limiting: prevent more than 1 notification per 60 seconds
            if ($last_sent && (time() - $last_sent) < 60) {
                return true;
            }
        }

        return false;
    }

    /**
     * Record that a notification was sent
     */
    private function record_notification($user_id, $search_id) {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_notification_throttle';
        $today = current_time('Y-m-d');

        // Update count
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
             SET notification_count = notification_count + 1,
                 last_notification_at = %s,
                 updated_at = %s
             WHERE user_id = %d AND saved_search_id = %d AND notification_date = %s",
            current_time('mysql'),
            current_time('mysql'),
            $user_id,
            $search_id,
            $today
        ));

        // Set rate limiting transient with shorter duration during bulk imports
        $cache_key = sprintf('mld_rate_%d_%d', $user_id, $search_id);

        // Check if we're in a bulk notification scenario
        $bulk_import_cache_key = sprintf('mld_bulk_notify_%d_%d', $user_id, $search_id);
        $bulk_count = get_transient($bulk_import_cache_key);

        // Use shorter rate limit during bulk imports to allow multiple notifications
        $rate_limit_duration = ($bulk_count !== false && $bulk_count > 0) ? 2 : 60;
        set_transient($cache_key, time(), $rate_limit_duration);
    }

    /**
     * Log throttled notification
     */
    private function log_throttle($user_id, $search_id, $reason) {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_notification_throttle';
        $today = current_time('Y-m-d');

        // Update throttled count
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
             SET throttled_count = throttled_count + 1,
                 updated_at = %s
             WHERE user_id = %d AND saved_search_id = %d AND notification_date = %s",
            current_time('mysql'),
            $user_id,
            $search_id,
            $today
        ));

        $this->log("Notification throttled for user $user_id, search $search_id: $reason", 'debug');
    }

    /**
     * Calculate retry time after quiet hours end
     */
    private function calculate_retry_after_quiet_hours($quiet_end) {
        $current_time = current_time('H:i:s');
        $current_date = current_time('Y-m-d');

        // Parse quiet end time
        $end_hour = substr($quiet_end, 0, 2);
        $end_minute = substr($quiet_end, 3, 2);

        // If we're before the quiet end time today, use today
        if ($current_time < $quiet_end) {
            return $current_date . ' ' . $quiet_end;
        } else {
            // Otherwise, use tomorrow's quiet end time using WordPress timezone
            $wp_tomorrow = wp_date('Y-m-d', current_time('timestamp') + DAY_IN_SECONDS);
            return $wp_tomorrow . ' ' . $quiet_end;
        }
    }

    /**
     * Queue notification for batch processing
     */
    private function queue_for_batch($user_id, $search_id, $type, $listing_data = []) {
        // This method is called by the instant matcher, which will handle the actual queueing
        $this->log("Marking notification for batch processing: user $user_id, search $search_id, type $type", 'debug');
    }

    /**
     * Check if bulk import is active
     */
    private function check_bulk_import_status() {
        // Check transient for bulk import flag
        $this->is_bulk_import_active = get_transient('mld_bulk_import_active');

        if (!$this->is_bulk_import_active) {
            // Check recent import count
            $this->detect_bulk_import();
        }
    }

    /**
     * Detect bulk import and switch to batch mode
     */
    public function detect_bulk_import() {
        $recent_imports = get_transient('mld_recent_imports_count');

        if (!$recent_imports) {
            $recent_imports = 0;
        }

        $recent_imports++;
        set_transient('mld_recent_imports_count', $recent_imports, 60);

        // If more than 90 imports in last minute, switch to batch mode
        if ($recent_imports > 90) {
            $this->is_bulk_import_active = true;
            set_transient('mld_bulk_import_active', true, 300); // 5 minutes
            $this->log('Bulk import detected (>90 listings), switching to batch mode', 'info');
        }
    }

    /**
     * Get user preferences
     */
    private function get_user_preferences($user_id) {
        global $wpdb;

        if (isset($this->user_notification_cache[$user_id])) {
            return $this->user_notification_cache[$user_id];
        }

        $preferences = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}mld_notification_preferences
             WHERE user_id = %d AND (saved_search_id = 0 OR saved_search_id IS NULL)
             ORDER BY updated_at DESC, id DESC
             LIMIT 1",
            $user_id
        ), ARRAY_A);

        if (!$preferences) {
            // Return defaults
            $preferences = [
                'quiet_hours_enabled' => false,
                'quiet_hours_start' => '22:00:00',
                'quiet_hours_end' => '08:00:00',
                'max_daily_notifications' => 50
            ];
        }

        $this->user_notification_cache[$user_id] = $preferences;

        return $preferences;
    }

    /**
     * Reset daily counts (for testing)
     */
    public function reset_daily_counts($user_id = null, $search_id = null) {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_notification_throttle';
        $today = current_time('Y-m-d');

        if ($user_id && $search_id) {
            $wpdb->update(
                $table,
                ['notification_count' => 0, 'throttled_count' => 0],
                ['user_id' => $user_id, 'saved_search_id' => $search_id, 'notification_date' => $today]
            );
        } else {
            // Reset all for today
            $wpdb->update(
                $table,
                ['notification_count' => 0, 'throttled_count' => 0],
                ['notification_date' => $today]
            );
        }

        $this->log('Daily counts reset', 'info');
    }

    /**
     * Get throttle statistics
     */
    public function get_statistics($user_id = null) {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_notification_throttle';
        $today = current_time('Y-m-d');

        if ($user_id) {
            return $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$table}
                 WHERE user_id = %d AND notification_date = %s",
                $user_id, $today
            ));
        } else {
            return $wpdb->get_results($wpdb->prepare(
                "SELECT
                    COUNT(DISTINCT user_id) as total_users,
                    SUM(notification_count) as total_sent,
                    SUM(throttled_count) as total_throttled
                 FROM {$table}
                 WHERE notification_date = %s",
                $today
            ));
        }
    }

    /**
     * Log activity
     */
    private function log($message, $level = 'info') {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf('[MLD Throttle Manager] [%s] %s', $level, $message));
        }
    }
}
