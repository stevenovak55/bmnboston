<?php
/**
 * MLS Listings Display - Unified Notification Dispatcher
 *
 * Single entry point for all notification types to prevent duplicates and ensure consistency
 *
 * @package MLS_Listings_Display
 * @since 4.6.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Notification_Dispatcher {

    /**
     * Notification types supported
     */
    const SUPPORTED_TYPES = [
        'new_listing',
        'price_reduced',
        'price_increased',
        'status_change',
        'back_on_market',
        'open_house',
        'sold',
        'coming_soon',
        'property_updated',
        'daily_digest',
        'weekly_digest',
        'hourly_digest'
    ];

    /**
     * Notification channels available
     */
    const CHANNELS = ['email', 'buddyboss'];

    /**
     * Instance of this class
     */
    private static $instance = null;

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
        // Hook into both instant and cron notification systems
        add_action('mld_dispatch_notification', [$this, 'dispatch_notification'], 10, 5);
        add_action('mld_dispatch_bulk_notifications', [$this, 'dispatch_bulk_notifications'], 10, 3);

        // Integrate with existing MLD Alert Types system
        add_action('mld_alert_notification_ready', [$this, 'handle_alert_notification'], 10, 4);
    }

    /**
     * Dispatch a single notification through all appropriate channels
     *
     * @param int $user_id User ID
     * @param array $listing_data Property data
     * @param string $notification_type Alert type
     * @param array $context Additional context data
     * @param object|null $search Saved search object (optional)
     * @return array Results of notification dispatch
     */
    public function dispatch_notification($user_id, $listing_data, $notification_type, $context = [], $search = null) {
        $results = [
            'user_id' => $user_id,
            'notification_type' => $notification_type,
            'channels' => [],
            'success' => false,
            'errors' => []
        ];

        // Validate notification type
        if (!in_array($notification_type, self::SUPPORTED_TYPES)) {
            $results['errors'][] = "Unsupported notification type: $notification_type";
            return $results;
        }

        // Safety check: For new_listing notifications, verify listing is Active/Coming Soon
        // This is defense-in-depth to prevent false alerts after database cleanup
        if ($notification_type === 'new_listing') {
            $listing_status = $listing_data['StandardStatus'] ?? $listing_data['standard_status'] ?? '';
            $allowed_statuses = ['Active', 'Coming Soon', 'active', 'coming soon'];
            if (!empty($listing_status) && !in_array($listing_status, $allowed_statuses)) {
                $listing_id = $listing_data['listing_id'] ?? $listing_data['ListingId'] ?? 'unknown';
                error_log("MLD_Notification_Dispatcher: Blocked new_listing notification for non-active listing {$listing_id} with status: {$listing_status}");
                $results['errors'][] = "Blocked: listing status is {$listing_status}, not Active/Coming Soon";
                return $results;
            }
        }

        // Check if user wants this notification type
        if (!$this->user_wants_notification($user_id, $notification_type)) {
            $results['errors'][] = "User has disabled this notification type";
            return $results;
        }

        // Check quiet hours for instant notifications
        if ($this->is_instant_notification($notification_type) && $this->is_quiet_hours($user_id)) {
            // Queue for later instead of sending now
            $this->queue_for_later($user_id, $listing_data, $notification_type, $context, $search);
            $results['success'] = true;
            $results['channels']['queued'] = 'Queued due to quiet hours';
            return $results;
        }

        // Prevent duplicate notifications within time window
        if ($this->is_duplicate_notification($user_id, $listing_data, $notification_type)) {
            $results['errors'][] = "Duplicate notification prevented";
            return $results;
        }

        // Get user's notification preferences
        $user_channels = $this->get_user_notification_channels($user_id);

        // Dispatch to each enabled channel
        foreach ($user_channels as $channel) {
            try {
                switch ($channel) {
                    case 'email':
                        $email_result = $this->send_email_notification($user_id, $listing_data, $notification_type, $context, $search);
                        $results['channels']['email'] = $email_result;
                        break;

                    case 'buddyboss':
                        $bb_result = $this->send_buddyboss_notification($user_id, $listing_data, $notification_type, $context, $search);
                        $results['channels']['buddyboss'] = $bb_result;
                        break;
                }
            } catch (Exception $e) {
                $results['errors'][] = "Error in $channel channel: " . $e->getMessage();
            }
        }

        // Mark as successful if at least one channel succeeded
        $results['success'] = !empty($results['channels']) && empty(array_filter($results['channels'], function($result) {
            return $result === false;
        }));

        // Log the notification for tracking
        $this->log_notification($user_id, $listing_data, $notification_type, $results);

        // Trigger analytics tracking for successful notifications
        if ($results['success']) {
            do_action('mld_notification_sent', $user_id, $listing_data['listing_id'] ?? $listing_data['ListingId'] ?? '', $notification_type, 'unified', $context);
        } else {
            do_action('mld_notification_failed', $user_id, $listing_data['listing_id'] ?? $listing_data['ListingId'] ?? '', $notification_type, 'unified', implode('; ', $results['errors']));
        }

        return $results;
    }

    /**
     * Dispatch bulk notifications (for digests)
     */
    public function dispatch_bulk_notifications($frequency, $search_results, $users) {
        $results = [
            'frequency' => $frequency,
            'total_sent' => 0,
            'total_failed' => 0,
            'errors' => []
        ];

        foreach ($users as $user_id) {
            if (!isset($search_results[$user_id]) || empty($search_results[$user_id])) {
                continue;
            }

            $user_properties = $search_results[$user_id];
            $notification_type = $frequency . '_digest';

            // Create digest data
            $digest_data = [
                'digest_type' => $frequency,
                'digest_count' => count($user_properties),
                'properties' => $user_properties
            ];

            $context = [
                'digest' => true,
                'frequency' => $frequency,
                'property_count' => count($user_properties)
            ];

            $dispatch_result = $this->dispatch_notification($user_id, $digest_data, $notification_type, $context);

            if ($dispatch_result['success']) {
                $results['total_sent']++;
            } else {
                $results['total_failed']++;
                $results['errors'] = array_merge($results['errors'], $dispatch_result['errors']);
            }
        }

        return $results;
    }

    /**
     * Handle alert notification from MLD Alert Types system
     */
    public function handle_alert_notification($user_id, $listing_data, $alert_type, $context) {
        // Convert alert type to notification type if needed
        $notification_type = $this->map_alert_type_to_notification_type($alert_type);

        // Dispatch through unified system
        return $this->dispatch_notification($user_id, $listing_data, $notification_type, $context);
    }

    /**
     * Send email notification
     */
    private function send_email_notification($user_id, $listing_data, $notification_type, $context, $search) {
        // Use existing MLD Email Sender
        if (!class_exists('MLD_Email_Sender')) {
            return false;
        }

        $email_sender = new MLD_Email_Sender();
        return $email_sender->send_instant_email($user_id, $listing_data, $search, $notification_type);
    }

    /**
     * Send BuddyBoss notification
     */
    private function send_buddyboss_notification($user_id, $listing_data, $notification_type, $context, $search) {
        // Use existing BuddyBoss integration
        if (!class_exists('MLD_BuddyBoss_Integration')) {
            return false;
        }

        $bb_integration = MLD_BuddyBoss_Integration::get_instance();
        return $bb_integration->send_instant_notification($user_id, $listing_data, $search, $notification_type);
    }

    /**
     * Check if user wants this notification type
     */
    private function user_wants_notification($user_id, $notification_type) {
        $preferences = get_user_meta($user_id, 'mld_alert_preferences', true);

        if (!is_array($preferences)) {
            // Set sensible defaults for all notification types
            $default_preferences = [
                'new_listing' => true,
                'price_reduced' => true,
                'price_increased' => true,
                'status_change' => true,
                'back_on_market' => true,
                'open_house' => true,
                'sold' => true,
                'coming_soon' => true,
                'property_updated' => true,
                'daily_digest' => false,  // Digest off by default to avoid spam
                'weekly_digest' => true,
                'hourly_digest' => false
            ];

            // Set the defaults for this user
            update_user_meta($user_id, 'mld_alert_preferences', $default_preferences);

            return $default_preferences[$notification_type] ?? true; // Default to true for new types
        }

        // Return user preference, defaulting to true for new notification types
        return $preferences[$notification_type] ?? true;
    }

    /**
     * Check if this is an instant notification type
     */
    private function is_instant_notification($notification_type) {
        $instant_types = ['new_listing', 'price_reduced', 'price_increased', 'status_change', 'back_on_market', 'open_house', 'sold', 'coming_soon', 'property_updated'];
        return in_array($notification_type, $instant_types);
    }

    /**
     * Check if it's quiet hours for the user
     */
    private function is_quiet_hours($user_id) {
        $quiet_hours = get_user_meta($user_id, 'mld_quiet_hours', true);

        if (empty($quiet_hours) || !is_array($quiet_hours)) {
            return false; // No quiet hours set
        }

        $current_time = current_time('H:i');
        $start = $quiet_hours['start'] ?? '22:00';
        $end = $quiet_hours['end'] ?? '08:00';

        // Handle overnight quiet hours (e.g., 22:00 to 08:00)
        if ($start > $end) {
            return $current_time >= $start || $current_time <= $end;
        } else {
            return $current_time >= $start && $current_time <= $end;
        }
    }

    /**
     * Queue notification for later (during quiet hours)
     */
    private function queue_for_later($user_id, $listing_data, $notification_type, $context, $search) {
        global $wpdb;

        // Calculate next send time (after quiet hours end) using WordPress timezone
        $quiet_hours = get_user_meta($user_id, 'mld_quiet_hours', true);
        $end_time = $quiet_hours['end'] ?? '08:00';

        // Use WordPress timezone-aware date functions
        $wp_today = wp_date('Y-m-d');
        $wp_tomorrow = wp_date('Y-m-d', current_time('timestamp') + DAY_IN_SECONDS);
        $next_send_str = $wp_today . ' ' . $end_time;

        // Compare against WordPress timezone timestamp
        if (strtotime($next_send_str) <= current_time('timestamp')) {
            $next_send_str = $wp_tomorrow . ' ' . $end_time;
        }

        // Insert into queue
        $wpdb->insert(
            $wpdb->prefix . 'mld_notification_queue',
            [
                'user_id' => $user_id,
                'listing_id' => $listing_data['listing_id'] ?? $listing_data['ListingId'] ?? '',
                'notification_type' => $notification_type,
                'listing_data' => json_encode($listing_data),
                'context_data' => json_encode($context),
                'search_data' => $search ? json_encode($search) : null,
                'scheduled_for' => wp_date('Y-m-d H:i:s', strtotime($next_send_str)),
                'created_at' => current_time('mysql'),
                'status' => 'queued'
            ]
        );
    }

    /**
     * Check for duplicate notifications
     */
    private function is_duplicate_notification($user_id, $listing_data, $notification_type) {
        global $wpdb;

        $listing_id = $listing_data['listing_id'] ?? $listing_data['ListingId'] ?? '';

        // Check for notifications in the last hour using WordPress timezone
        $wp_now = current_time('mysql');
        $recent = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}mld_notification_history
             WHERE user_id = %d
             AND listing_id = %s
             AND notification_type = %s
             AND sent_at >= DATE_SUB(%s, INTERVAL 1 HOUR)",
            $user_id,
            $listing_id,
            $notification_type,
            $wp_now
        ));

        return $recent > 0;
    }

    /**
     * Get user's enabled notification channels
     */
    private function get_user_notification_channels($user_id) {
        $preferences = get_user_meta($user_id, 'mld_notification_channels', true);

        if (!is_array($preferences)) {
            // Default to both email and BuddyBoss for comprehensive notifications
            $default_channels = ['email'];

            // Add BuddyBoss if BuddyPress is active
            if (function_exists('bp_is_active')) {
                $default_channels[] = 'buddyboss';
            }

            // Set the default for this user so we don't have to calculate again
            update_user_meta($user_id, 'mld_notification_channels', $default_channels);

            return $default_channels;
        }

        return array_intersect($preferences, self::CHANNELS);
    }

    /**
     * Log notification for tracking
     */
    private function log_notification($user_id, $listing_data, $notification_type, $results) {
        global $wpdb;

        $listing_id = $listing_data['listing_id'] ?? $listing_data['ListingId'] ?? '';

        $wpdb->insert(
            $wpdb->prefix . 'mld_notification_history',
            [
                'user_id' => $user_id,
                'listing_id' => $listing_id,
                'notification_type' => $notification_type,
                'channels_used' => json_encode(array_keys($results['channels'])),
                'success' => $results['success'] ? 1 : 0,
                'error_message' => implode('; ', $results['errors']),
                'sent_at' => current_time('mysql'),
                'template_used' => $notification_type
            ]
        );
    }

    /**
     * Map alert type to notification type
     */
    private function map_alert_type_to_notification_type($alert_type) {
        $mapping = [
            'price_reduced' => 'price_reduced',
            'price_increased' => 'price_increased',
            'status_change' => 'status_change',
            'sold' => 'sold',
            'back_on_market' => 'back_on_market',
            'coming_soon' => 'coming_soon',
            'open_house' => 'open_house',
            'property_updated' => 'property_updated'
        ];

        return $mapping[$alert_type] ?? $alert_type;
    }
}

// Initialize the dispatcher
MLD_Notification_Dispatcher::get_instance();