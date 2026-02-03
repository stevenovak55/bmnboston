<?php
/**
 * MLD Notification Router - Multi-channel notification delivery (Refactored)
 *
 * Routes notifications to appropriate channels using integration classes
 *
 * @package MLS_Listings_Display
 * @subpackage Instant_Notifications
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Notification_Router {

    /**
     * Available notification channels
     */
    private $channels = [];

    /**
     * User preferences cache
     */
    private $preferences_cache = [];

    /**
     * BuddyBoss integration instance
     */
    private $buddyboss_integration = null;

    /**
     * Email sender instance
     */
    private $email_sender = null;

    /**
     * Constructor
     */
    public function __construct() {
        $this->init_channels();
    }

    /**
     * Set BuddyBoss integration
     */
    public function set_buddyboss_integration($integration) {
        $this->buddyboss_integration = $integration;
    }

    /**
     * Set email sender
     */
    public function set_email_sender($sender) {
        $this->email_sender = $sender;
    }

    /**
     * Initialize available notification channels
     */
    private function init_channels() {
        // BuddyBoss channel
        if (class_exists('BuddyPress')) {
            $this->channels['buddyboss'] = true;
        }

        // Email channel (always available)
        $this->channels['email'] = true;

        // SMS channel (if configured)
        if (get_option('mld_sms_enabled')) {
            $this->channels['sms'] = true;
        }

        $this->log('Available channels: ' . implode(', ', array_keys($this->channels)), 'debug');
    }

    /**
     * Route notification through appropriate channels
     *
     * @param object $search Saved search object
     * @param array $listing_data Listing data
     * @param string $type Notification type
     * @param bool $force_send Skip quiet hours check (for queued notifications)
     * @return array Result with channels used
     */
    public function route_notification($search, $listing_data, $type, $force_send = false) {
        $channels_used = [];
        $success = false;

        // Debug logging
        $this->log("Route notification called for user {$search->user_id}, search {$search->id}, type: $type", 'info');

        // Get user preferences
        $preferences = $this->get_user_preferences($search->user_id, $search->id);
        $preferences['user_id'] = $search->user_id; // Add user_id to preferences for downstream methods
        $this->log("User preferences loaded: email=" . ($preferences['instant_email_notifications'] ?? 'null') . ", app=" . ($preferences['instant_app_notifications'] ?? 'null'), 'debug');

        // Check if user wants instant notifications
        if (!$this->user_wants_instant($preferences, $type)) {
            $this->log("User {$search->user_id} doesn't want instant notifications for type: $type", 'debug');
            return ['success' => false, 'channels' => []];
        }

        // Check quiet hours (unless force_send is true for queued notifications)
        if (!$force_send && $this->is_quiet_hours($preferences)) {
            $this->log("Quiet hours active for user {$search->user_id}", 'debug');
            return ['success' => false, 'reason' => 'quiet_hours'];
        }

        // Send through BuddyBoss if available and enabled
        if ($this->should_send_buddyboss($preferences)) {
            if ($this->send_buddyboss_notification($search, $listing_data, $type)) {
                $channels_used[] = 'buddyboss';
                $success = true;
            }
        }

        // Send email if enabled
        $should_send_email = $this->should_send_email($preferences);
        $this->log("Should send email: " . ($should_send_email ? 'YES' : 'NO'), 'debug');

        if ($should_send_email) {
            $email_result = $this->send_email_notification($search, $listing_data, $type);
            $this->log("Email send result: " . ($email_result ? 'SUCCESS' : 'FAILED'), 'info');
            if ($email_result) {
                $channels_used[] = 'email';
                $success = true;
            }
        }

        // Send SMS if enabled
        if ($this->should_send_sms($preferences)) {
            if ($this->send_sms_notification($search, $listing_data, $type)) {
                $channels_used[] = 'sms';
                $success = true;
            }
        }

        // Update notification counts if any notification was sent
        if ($success && !empty($channels_used)) {
            $this->update_notification_counts($search, $channels_used);
        }

        return [
            'success' => $success,
            'channels' => $channels_used
        ];
    }

    /**
     * Get user preferences
     */
    private function get_user_preferences($user_id, $search_id) {
        global $wpdb;

        $cache_key = $user_id . '_' . $search_id;

        if (isset($this->preferences_cache[$cache_key])) {
            return $this->preferences_cache[$cache_key];
        }

        // Get search-specific preferences first
        $preferences = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}mld_notification_preferences
             WHERE user_id = %d AND saved_search_id = %d",
            $user_id,
            $search_id
        ), ARRAY_A);

        // Fall back to latest user-level preferences (saved_search_id = 0 or NULL)
        if (!$preferences) {
            $preferences = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}mld_notification_preferences
                 WHERE user_id = %d AND (saved_search_id = 0 OR saved_search_id IS NULL)
                 ORDER BY updated_at DESC, id DESC
                 LIMIT 1",
                $user_id
            ), ARRAY_A);
        }

        // Use defaults if no preferences found
        if (!$preferences) {
            $preferences = [
                'instant_app_notifications' => true,
                'instant_email_notifications' => true,  // Changed to true by default
                'instant_sms_notifications' => false,
                'quiet_hours_start' => '22:00:00',
                'quiet_hours_end' => '06:00:00',
                'notification_types' => json_encode(['new_listing', 'price_drop', 'back_on_market'])
            ];
        }

        $this->preferences_cache[$cache_key] = $preferences;

        return $preferences;
    }

    /**
     * Check if user wants instant notifications
     */
    private function user_wants_instant($preferences, $type) {
        $notification_types = json_decode($preferences['notification_types'] ?? '[]', true);

        $this->log("Checking if user wants instant for type: $type", 'debug');
        $this->log("Notification types enabled: " . json_encode($notification_types), 'debug');

        if (empty($notification_types)) {
            // Default to all types if not specified
            $this->log("No specific types configured - allowing all", 'debug');
            return true;
        }

        $wants_it = in_array($type, $notification_types);
        $this->log("User wants $type notifications: " . ($wants_it ? 'YES' : 'NO'), 'debug');
        return $wants_it;
    }

    /**
     * Check if currently in quiet hours
     */
    private function is_quiet_hours($preferences) {
        // Check if quiet hours are globally disabled
        if (!get_option('mld_global_quiet_hours_enabled', true)) {
            $this->log("Quiet hours globally disabled by admin", 'debug');
            return false;
        }

        // Check if user has quiet hours disabled in their preferences
        if (isset($preferences['quiet_hours_enabled']) && !$preferences['quiet_hours_enabled']) {
            $this->log("Quiet hours disabled for user", 'debug');
            return false;
        }

        // Check if admin is overriding user preferences
        if (get_option('mld_override_user_preferences', false)) {
            // Use global quiet hour settings
            $quiet_start = $this->normalize_time(get_option('mld_default_quiet_start', '22:00'));
            $quiet_end = $this->normalize_time(get_option('mld_default_quiet_end', '06:00'));
        } else {
            // Use user's quiet hour settings
            $quiet_start = $this->normalize_time($preferences['quiet_hours_start'] ?? '22:00:00');
            $quiet_end = $this->normalize_time($preferences['quiet_hours_end'] ?? '06:00:00');
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
     * Check if should send BuddyBoss notification
     */
    private function should_send_buddyboss($preferences) {
        if (!isset($this->channels['buddyboss']) || $this->buddyboss_integration === null) {
            return false;
        }

        // Check both our preferences and BuddyBoss notification settings
        $app_enabled = $preferences['instant_app_notifications'] ?? true;

        // For BuddyBoss, check if the notification type is enabled
        // This will default to true if not set in BuddyBoss settings
        return $app_enabled;
    }

    /**
     * Check if should send email notification
     */
    private function should_send_email($preferences) {
        $this->log("Checking should_send_email", 'debug');

        if (!isset($this->channels['email']) || $this->email_sender === null) {
            $this->log("Email channel not available or sender is null", 'debug');
            return false;
        }

        // BuddyBoss user preference takes precedence and follows BuddyBoss convention:
        // send emails unless the user explicitly set meta to 'no'.
        if (function_exists('bp_get_user_meta') && isset($preferences['user_id'])) {
            $bp_pref = bp_get_user_meta($preferences['user_id'], 'notification_instant_new_listing', true);
            $this->log("BuddyBoss email preference meta: " . var_export($bp_pref, true), 'debug');
            if ($bp_pref === 'no') {
                $this->log("BuddyBoss preference explicitly disables email", 'debug');
                return false;
            }
            if ($bp_pref === 'yes' || $bp_pref === '' || $bp_pref === null) {
                $this->log("BuddyBoss preference allows email (yes/empty)", 'debug');
                return true;
            }
        }

        // Fallback to plugin preference; default to true if unset
        $email_enabled = isset($preferences['instant_email_notifications'])
            ? (bool)$preferences['instant_email_notifications']
            : true;

        $this->log("Final email_enabled value (fallback): " . var_export($email_enabled, true), 'debug');
        return $email_enabled;
    }

    /**
     * Normalize time string to H:i:s format
     */
    private function normalize_time($time) {
        if (!$time) return '00:00:00';
        // Already H:i:s
        if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $time)) {
            return $time;
        }
        // H:i -> add :00 seconds
        if (preg_match('/^\d{2}:\d{2}$/', $time)) {
            return $time . ':00';
        }
        // Fallback: try to parse
        $ts = strtotime($time);
        return $ts ? date('H:i:s', $ts) : '00:00:00';
    }

    /**
     * Check if should send SMS notification
     */
    private function should_send_sms($preferences) {
        return isset($this->channels['sms']) &&
               ($preferences['instant_sms_notifications'] ?? false);
    }

    /**
     * Send BuddyBoss notification using integration class
     */
    private function send_buddyboss_notification($search, $listing_data, $type) {
        if (!$this->buddyboss_integration) {
            $this->log('BuddyBoss integration not available', 'warning');
            return false;
        }

        try {
            $result = $this->buddyboss_integration->send_instant_notification(
                $search->user_id,
                $listing_data,
                $search,
                $type
            );

            if ($result) {
                $this->log("BuddyBoss notification sent for user {$search->user_id}, search {$search->id}", 'info');
            }

            return $result;
        } catch (Exception $e) {
            $this->log('BuddyBoss notification error: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Send email notification using email sender class
     */
    private function send_email_notification($search, $listing_data, $type) {
        if (!$this->email_sender) {
            $this->log('Email sender not available', 'warning');
            return false;
        }

        try {
            // Debug logging
            $user = get_user_by('id', $search->user_id);
            $this->log("Attempting to send email to user {$search->user_id} ({$user->user_email}) for listing " . ($listing_data['listing_id'] ?? 'unknown'), 'info');

            $result = $this->email_sender->send_instant_email(
                $search->user_id,
                $listing_data,
                $search,
                $type
            );

            if ($result) {
                $this->log("Email successfully sent to {$user->user_email} for search {$search->id}", 'info');
            } else {
                $this->log("Email failed to send to {$user->user_email}", 'error');
            }

            return $result;
        } catch (Exception $e) {
            $this->log('Email notification error: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Send SMS notification (placeholder for future implementation)
     */
    private function send_sms_notification($search, $listing_data, $type) {
        // This would integrate with an SMS service like Twilio
        // For now, just return false
        return false;
    }

    /**
     * Update notification counts in database
     */
    private function update_notification_counts($search, $channels) {
        global $wpdb;
        $today = current_time('Y-m-d');
        $now = current_time('mysql');

        // Update throttle table for notification count tracking
        $throttle_table = $wpdb->prefix . 'mld_notification_throttle';

        // Check if record exists for today
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$throttle_table}
             WHERE user_id = %d AND saved_search_id = %d AND notification_date = %s",
            $search->user_id,
            $search->id,
            $today
        ));

        if ($existing) {
            // Update existing record
            $wpdb->query($wpdb->prepare(
                "UPDATE {$throttle_table}
                 SET notification_count = notification_count + 1,
                     last_notification_at = %s,
                     updated_at = %s
                 WHERE user_id = %d AND saved_search_id = %d AND notification_date = %s",
                $now,
                $now,
                $search->user_id,
                $search->id,
                $today
            ));
        } else {
            // Create new record
            $wpdb->insert($throttle_table, [
                'user_id' => $search->user_id,
                'saved_search_id' => $search->id,
                'notification_date' => $today,
                'notification_count' => 1,
                'last_notification_at' => $now,
                'throttled_count' => 0,
                'created_at' => $now,
                'updated_at' => $now
            ]);
        }

        // Update last_notified_at in saved searches table
        $wpdb->update(
            $wpdb->prefix . 'mld_saved_searches',
            [
                'last_notified_at' => $now,
                'last_matched_count' => $wpdb->get_var($wpdb->prepare(
                    "SELECT notification_count FROM {$throttle_table}
                     WHERE user_id = %d AND saved_search_id = %d AND notification_date = %s",
                    $search->user_id,
                    $search->id,
                    $today
                ))
            ],
            ['id' => $search->id]
        );

        // Log the update
        $this->log("Updated notification counts for search {$search->id}: " . implode(', ', $channels), 'info');
    }

    /**
     * Log activity
     */
    private function log($message, $level = 'info') {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            // Skip logging during plugin activation to prevent unexpected output
            if (defined('WP_ADMIN') && WP_ADMIN &&
                isset($_GET['action']) && $_GET['action'] === 'activate') {
                return;
            }

            // Only log to file, never to browser during web requests
            if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                error_log(sprintf('[MLD Notification Router] [%s] %s', $level, $message), 3, WP_CONTENT_DIR . '/debug.log');
            } elseif (php_sapi_name() === 'cli') {
                // Only output to error_log if we're in CLI mode
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log(sprintf('[MLD Notification Router] [%s] %s', $level, $message));
                }
            }
        }
    }
}
