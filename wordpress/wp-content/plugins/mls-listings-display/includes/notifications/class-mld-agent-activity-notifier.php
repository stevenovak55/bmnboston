<?php
/**
 * Agent Activity Notifier
 *
 * Main orchestrator for agent notifications when clients perform activities.
 * Handles all event types and coordinates email + push notification delivery.
 *
 * @package MLS_Listings_Display
 * @subpackage Notifications
 * @since 6.43.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Agent_Activity_Notifier {

    /**
     * Singleton instance
     */
    private static $instance = null;

    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor
     */
    private function __construct() {
        // Initialize
    }

    /**
     * Initialize hooks
     * Called from main plugin file
     */
    public static function init() {
        $instance = self::get_instance();

        // Client login hook (iOS and web)
        add_action('mld_client_logged_in', array($instance, 'handle_client_login'), 10, 2);

        // WordPress login hook - triggers agent notification for web logins (v6.50.9)
        add_action('wp_login', array($instance, 'handle_wp_login'), 10, 2);

        // App open hook
        add_action('mld_app_opened', array($instance, 'handle_app_opened'), 10, 1);

        // Favorite added hook
        add_action('mld_favorite_added', array($instance, 'handle_favorite_added'), 10, 3);

        // Saved search created hook
        add_action('mld_saved_search_created', array($instance, 'handle_saved_search_created'), 10, 2);

        // Tour/appointment requested hook
        add_action('snab_appointment_created', array($instance, 'handle_tour_requested'), 10, 2);
    }

    /**
     * Handle client login event
     *
     * @param int $client_id WordPress user ID of the client
     * @param string $platform 'ios' or 'web'
     */
    public function handle_client_login($client_id, $platform = 'ios') {
        $this->notify_agent(
            $client_id,
            MLD_Agent_Notification_Preferences::TYPE_CLIENT_LOGIN,
            array(
                'platform' => $platform
            )
        );
    }

    /**
     * Handle WordPress login event (web logins)
     * Triggers agent notification for client web logins (v6.50.9)
     *
     * @param string $user_login Username
     * @param WP_User $user User object
     */
    public function handle_wp_login($user_login, $user) {
        // Only trigger for client users, not agents/admins
        if (class_exists('MLD_User_Type_Manager')) {
            $user_type = MLD_User_Type_Manager::get_user_type($user->ID);

            if ($user_type === 'client') {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log("MLD_Agent_Activity_Notifier: Web login detected for client {$user->ID} ({$user_login})");
                }

                // Trigger the same hook that iOS uses
                do_action('mld_client_logged_in', $user->ID, 'web');
            }
        }
    }

    /**
     * Handle app opened event
     * Includes 2-hour debounce to avoid notification spam
     *
     * @param int $client_id WordPress user ID of the client
     */
    public function handle_app_opened($client_id) {
        // Check 2-hour debounce
        if ($this->should_skip_app_open_notification($client_id)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("MLD_Agent_Activity_Notifier: Skipping app_open for client {$client_id} (debounce)");
            }
            return;
        }

        // Update last notified time
        $this->update_app_open_timestamp($client_id);

        $this->notify_agent(
            $client_id,
            MLD_Agent_Notification_Preferences::TYPE_APP_OPEN,
            array()
        );
    }

    /**
     * Handle favorite added event
     *
     * @param int $client_id WordPress user ID of the client
     * @param string $listing_id MLS listing ID
     * @param array $context Additional context (property_address, listing_key)
     */
    public function handle_favorite_added($client_id, $listing_id, $context = array()) {
        $this->notify_agent(
            $client_id,
            MLD_Agent_Notification_Preferences::TYPE_FAVORITE_ADDED,
            array_merge($context, array(
                'listing_id' => $listing_id
            ))
        );
    }

    /**
     * Handle saved search created event
     *
     * @param int $client_id WordPress user ID of the client
     * @param array $context Additional context (search_id, search_name)
     */
    public function handle_saved_search_created($client_id, $context = array()) {
        $this->notify_agent(
            $client_id,
            MLD_Agent_Notification_Preferences::TYPE_SEARCH_CREATED,
            $context
        );
    }

    /**
     * Handle tour/appointment requested event
     *
     * @param int $appointment_id SNAB appointment ID
     * @param array $context Additional context (client_id, property_address, date, time)
     */
    public function handle_tour_requested($appointment_id, $context = array()) {
        $client_id = isset($context['client_id']) ? (int) $context['client_id'] : 0;

        if (!$client_id) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("MLD_Agent_Activity_Notifier: tour_requested missing client_id");
            }
            return;
        }

        $this->notify_agent(
            $client_id,
            MLD_Agent_Notification_Preferences::TYPE_TOUR_REQUESTED,
            array_merge($context, array(
                'appointment_id' => $appointment_id
            ))
        );
    }

    /**
     * Main notification method - coordinates email and push delivery
     *
     * @param int $client_id WordPress user ID of the client
     * @param string $notification_type Type constant from MLD_Agent_Notification_Preferences
     * @param array $context Additional context data
     */
    private function notify_agent($client_id, $notification_type, $context = array()) {
        // Get agent for this client
        $agent_data = $this->get_agent_for_client($client_id);

        if (!$agent_data) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("MLD_Agent_Activity_Notifier: No agent assigned to client {$client_id}");
            }
            return;
        }

        $agent_id = (int) $agent_data['user_id'];

        // Get client info
        $client = get_user_by('ID', $client_id);
        if (!$client) {
            return;
        }

        // Get notification preferences
        $preferences = MLD_Agent_Notification_Preferences::get_preferences($agent_id, $notification_type);

        if (!$preferences) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("MLD_Agent_Activity_Notifier: Invalid notification type {$notification_type}");
            }
            return;
        }

        // Build client name
        $client_name = $this->get_client_display_name($client);

        // Enrich context with client and agent info
        $context['client_id'] = $client_id;
        $context['client_name'] = $client_name;
        $context['client_email'] = $client->user_email;
        $context['agent_id'] = $agent_id;
        $context['agent_data'] = $agent_data;

        // Enrich with property data for property-related notifications (v6.50.9)
        $context = $this->enrich_property_context($notification_type, $context);

        // Send email notification
        if ($preferences['email_enabled']) {
            $this->send_email_notification($agent_id, $client_id, $notification_type, $context);
        } else {
            MLD_Agent_Notification_Log::log_skipped(
                $agent_id,
                $client_id,
                $notification_type,
                MLD_Agent_Notification_Log::CHANNEL_EMAIL,
                'Email notification disabled by preference'
            );
        }

        // Send push notification
        if ($preferences['push_enabled']) {
            $this->send_push_notification($agent_id, $client_id, $notification_type, $context);
        } else {
            MLD_Agent_Notification_Log::log_skipped(
                $agent_id,
                $client_id,
                $notification_type,
                MLD_Agent_Notification_Log::CHANNEL_PUSH,
                'Push notification disabled by preference'
            );
        }
    }

    /**
     * Send email notification to agent
     *
     * @param int $agent_id Agent user ID
     * @param int $client_id Client user ID
     * @param string $notification_type Notification type
     * @param array $context Context data
     */
    private function send_email_notification($agent_id, $client_id, $notification_type, $context) {
        // Get agent email
        $agent = get_user_by('ID', $agent_id);
        if (!$agent) {
            MLD_Agent_Notification_Log::log(
                $agent_id,
                $client_id,
                $notification_type,
                MLD_Agent_Notification_Log::CHANNEL_EMAIL,
                array('success' => false, 'error' => 'Agent user not found')
            );
            return;
        }

        // Build email content
        $email_data = MLD_Agent_Notification_Email::build($notification_type, $context);

        if (!$email_data) {
            MLD_Agent_Notification_Log::log(
                $agent_id,
                $client_id,
                $notification_type,
                MLD_Agent_Notification_Log::CHANNEL_EMAIL,
                array('success' => false, 'error' => 'Failed to build email content')
            );
            return;
        }

        // Send email
        $headers = array('Content-Type: text/html; charset=UTF-8');

        $sent = wp_mail(
            $agent->user_email,
            $email_data['subject'],
            $email_data['body'],
            $headers
        );

        MLD_Agent_Notification_Log::log(
            $agent_id,
            $client_id,
            $notification_type,
            MLD_Agent_Notification_Log::CHANNEL_EMAIL,
            array(
                'success' => $sent,
                'error' => $sent ? null : 'wp_mail failed'
            ),
            array('subject' => $email_data['subject'])
        );
    }

    /**
     * Send push notification to agent
     *
     * @param int $agent_id Agent user ID
     * @param int $client_id Client user ID
     * @param string $notification_type Notification type
     * @param array $context Context data
     */
    private function send_push_notification($agent_id, $client_id, $notification_type, $context) {
        // Check if push notifications are configured
        if (!class_exists('MLD_Push_Notifications') || !MLD_Push_Notifications::is_configured()) {
            MLD_Agent_Notification_Log::log(
                $agent_id,
                $client_id,
                $notification_type,
                MLD_Agent_Notification_Log::CHANNEL_PUSH,
                array('success' => false, 'error' => 'Push notifications not configured'),
                $context
            );
            return;
        }

        // Check if agent has any devices registered
        $device_count = MLD_Push_Notifications::get_user_device_count($agent_id);
        if ($device_count === 0) {
            MLD_Agent_Notification_Log::log(
                $agent_id,
                $client_id,
                $notification_type,
                MLD_Agent_Notification_Log::CHANNEL_PUSH,
                array('success' => false, 'error' => 'No devices registered for agent'),
                $context
            );
            return;
        }

        // Build push notification content
        $push_content = $this->build_push_content($notification_type, $context);

        // Send push notification
        $result = MLD_Push_Notifications::send_activity_notification(
            $agent_id,
            $push_content['title'],
            $push_content['body'],
            $notification_type,
            $context
        );

        MLD_Agent_Notification_Log::log(
            $agent_id,
            $client_id,
            $notification_type,
            MLD_Agent_Notification_Log::CHANNEL_PUSH,
            array(
                'success' => $result['success'],
                'error' => $result['success'] ? null : implode(', ', $result['errors'])
            ),
            array(
                'title' => $push_content['title'],
                'sent_count' => $result['sent_count'] ?? 0,
                'failed_count' => $result['failed_count'] ?? 0
            )
        );
    }

    /**
     * Build push notification content based on type
     *
     * @param string $notification_type Notification type
     * @param array $context Context data
     * @return array Array with 'title' and 'body'
     */
    private function build_push_content($notification_type, $context) {
        $client_name = $context['client_name'] ?? 'Your client';

        switch ($notification_type) {
            case MLD_Agent_Notification_Preferences::TYPE_CLIENT_LOGIN:
                $platform = $context['platform'] ?? 'the app';
                return array(
                    'title' => "{$client_name} just logged in",
                    'body' => "via {$platform}"
                );

            case MLD_Agent_Notification_Preferences::TYPE_APP_OPEN:
                return array(
                    'title' => "{$client_name} is browsing properties",
                    'body' => "They just opened the app"
                );

            case MLD_Agent_Notification_Preferences::TYPE_FAVORITE_ADDED:
                $address = $context['property_address'] ?? 'a property';
                return array(
                    'title' => "{$client_name} favorited a property",
                    'body' => $address
                );

            case MLD_Agent_Notification_Preferences::TYPE_SEARCH_CREATED:
                $search_name = $context['search_name'] ?? 'a new search';
                return array(
                    'title' => "{$client_name} created a saved search",
                    'body' => $search_name
                );

            case MLD_Agent_Notification_Preferences::TYPE_TOUR_REQUESTED:
                $address = $context['property_address'] ?? 'a property';
                $date = $context['date'] ?? '';
                $time = $context['time'] ?? '';
                $body = $address;
                if ($date || $time) {
                    $body .= " - {$date} {$time}";
                }
                return array(
                    'title' => "{$client_name} requested a tour",
                    'body' => trim($body)
                );

            default:
                return array(
                    'title' => "Client Activity",
                    'body' => "{$client_name} performed an action"
                );
        }
    }

    /**
     * Enrich context with property data for property-related notifications (v6.50.9)
     *
     * Fetches property image URL, listing_key, and address for notifications
     * that involve properties (favorite_added, tour_requested).
     *
     * @param string $notification_type Notification type
     * @param array $context Context data
     * @return array Enriched context with image_url, listing_key, property_address
     */
    private function enrich_property_context($notification_type, $context) {
        global $wpdb;

        // Only enrich property-related notifications
        $property_types = array(
            MLD_Agent_Notification_Preferences::TYPE_FAVORITE_ADDED,
            MLD_Agent_Notification_Preferences::TYPE_TOUR_REQUESTED,
        );

        if (!in_array($notification_type, $property_types, true)) {
            return $context;
        }

        // Get listing_id from context
        $listing_id = isset($context['listing_id']) ? $context['listing_id'] : null;

        if (!$listing_id) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("MLD_Agent_Activity_Notifier: enrich_property_context - no listing_id in context");
            }
            return $context;
        }

        // Query bme_listing_summary for property data
        $summary_table = $wpdb->prefix . 'bme_listing_summary';

        $property = $wpdb->get_row($wpdb->prepare(
            "SELECT listing_key, main_photo_url, street_number, street_name, city, state_or_province, postal_code
             FROM {$summary_table}
             WHERE listing_id = %s
             LIMIT 1",
            $listing_id
        ));

        if ($property) {
            // Add image URL for rich notifications
            if (!empty($property->main_photo_url)) {
                $context['image_url'] = $property->main_photo_url;
            }

            // Add listing_key for deep linking
            if (!empty($property->listing_key)) {
                $context['listing_key'] = $property->listing_key;
            }

            // Build property address if not already in context
            if (empty($context['property_address'])) {
                $address_parts = array_filter(array(
                    trim($property->street_number . ' ' . $property->street_name),
                    $property->city,
                ));
                $context['property_address'] = implode(', ', $address_parts);
            }

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("MLD_Agent_Activity_Notifier: Enriched property context for listing {$listing_id}");
            }
        } else {
            // Try archive table if not found in active
            $archive_table = $wpdb->prefix . 'bme_listing_summary_archive';

            $property = $wpdb->get_row($wpdb->prepare(
                "SELECT listing_key, main_photo_url, street_number, street_name, city, state_or_province, postal_code
                 FROM {$archive_table}
                 WHERE listing_id = %s
                 LIMIT 1",
                $listing_id
            ));

            if ($property) {
                if (!empty($property->main_photo_url)) {
                    $context['image_url'] = $property->main_photo_url;
                }
                if (!empty($property->listing_key)) {
                    $context['listing_key'] = $property->listing_key;
                }
                if (empty($context['property_address'])) {
                    $address_parts = array_filter(array(
                        trim($property->street_number . ' ' . $property->street_name),
                        $property->city,
                    ));
                    $context['property_address'] = implode(', ', $address_parts);
                }
            }
        }

        return $context;
    }

    /**
     * Get the agent assigned to a client
     *
     * @param int $client_id Client user ID
     * @return array|null Agent data or null if not assigned
     */
    private function get_agent_for_client($client_id) {
        if (!class_exists('MLD_Agent_Client_Manager')) {
            return null;
        }

        return MLD_Agent_Client_Manager::get_client_agent($client_id);
    }

    /**
     * Get display name for client
     *
     * @param WP_User $client Client user object
     * @return string Display name
     */
    private function get_client_display_name($client) {
        if (!empty($client->first_name)) {
            return $client->first_name;
        }
        if (!empty($client->display_name)) {
            return $client->display_name;
        }
        return explode('@', $client->user_email)[0];
    }

    /**
     * Check if we should skip app open notification (2-hour debounce)
     *
     * @param int $client_id Client user ID
     * @return bool True if should skip
     */
    private function should_skip_app_open_notification($client_id) {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_client_app_opens';

        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            return false;
        }

        // Get last notified time for this client
        $last_notified = $wpdb->get_var($wpdb->prepare(
            "SELECT last_notified_at FROM {$table} WHERE user_id = %d",
            $client_id
        ));

        if (!$last_notified) {
            return false;
        }

        // Check if 2 hours have passed (use WordPress timezone)
        $threshold = date('Y-m-d H:i:s', current_time('timestamp') - (2 * HOUR_IN_SECONDS));

        return $last_notified >= $threshold;
    }

    /**
     * Update app open timestamp for debounce tracking
     *
     * @param int $client_id Client user ID
     */
    private function update_app_open_timestamp($client_id) {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_client_app_opens';

        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            return;
        }

        $now = current_time('mysql');

        // Use REPLACE to insert or update
        $wpdb->replace(
            $table,
            array(
                'user_id' => $client_id,
                'last_notified_at' => $now,
                'last_opened_at' => $now
            ),
            array('%d', '%s', '%s')
        );
    }

    /**
     * Manually trigger a notification (for testing)
     *
     * @param int $client_id Client user ID
     * @param string $notification_type Notification type
     * @param array $context Context data
     */
    public static function test_notification($client_id, $notification_type, $context = array()) {
        $instance = self::get_instance();
        $instance->notify_agent($client_id, $notification_type, $context);
    }
}
