<?php
/**
 * MLS BuddyBoss Notifier
 *
 * Simple BuddyBoss integration for sending in-app notifications
 * about new listing matches.
 *
 * @package MLS_Listings_Display
 * @subpackage Notifications
 * @since 5.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_BuddyBoss_Notifier {

    /**
     * Singleton instance
     */
    private static $instance = null;

    /**
     * Notification component name
     */
    const COMPONENT_NAME = 'mls_listings';

    /**
     * Notification action
     */
    const NOTIFICATION_ACTION = 'new_listing_matches';

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
        $this->init();
    }

    /**
     * Initialize BuddyBoss integration
     */
    private function init() {
        // Only proceed if BuddyBoss is active
        if (!$this->is_buddyboss_active()) {
            return;
        }

        // Register our notification component
        add_action('bp_setup_globals', [$this, 'register_notification_component']);

        // Add notification actions to BuddyBoss
        add_filter('bp_notifications_get_registered_components', [$this, 'register_notifications']);

        // Format notifications for display
        add_filter('bp_notifications_get_notifications_for_user', [$this, 'format_notifications'], 10, 5);
    }

    /**
     * Check if BuddyBoss is active and notifications are enabled
     */
    private function is_buddyboss_active() {
        return function_exists('bp_is_active') &&
               bp_is_active('notifications') &&
               function_exists('bp_notifications_add_notification');
    }

    /**
     * Register our notification component with BuddyBoss
     */
    public function register_notification_component() {
        if (!$this->is_buddyboss_active()) {
            return;
        }

        // BuddyBoss doesn't have bp_register_notification_component
        // Components are registered differently
        // We'll just use the component in our notifications directly
    }

    /**
     * Register our notification types
     */
    public function register_notifications($component_names) {
        if (!$this->is_buddyboss_active()) {
            return $component_names;
        }

        // Add our component to the list
        $component_names[] = self::COMPONENT_NAME;

        return $component_names;
    }

    /**
     * Send BuddyBoss notification for new listing matches
     */
    public function send_notification($search, $listings) {
        if (!$this->is_buddyboss_active()) {
            return false;
        }

        $user_id = $search->user_id;
        $listing_count = count($listings);

        if ($listing_count === 0) {
            return false;
        }

        // Check if user wants BuddyBoss notifications (you might want to add this setting)
        if (!$this->user_wants_buddyboss_notifications($user_id)) {
            return false;
        }

        try {
            // Create notification data
            $notification_data = [
                'user_id' => $user_id,
                'item_id' => $search->id,
                'secondary_item_id' => $listing_count,
                'component_name' => self::COMPONENT_NAME,
                'component_action' => self::NOTIFICATION_ACTION,
                'date_notified' => function_exists('bp_core_current_time') ? bp_core_current_time() : current_time('mysql'),
                'is_new' => 1
            ];

            // Add the notification
            $notification_id = function_exists('bp_notifications_add_notification')
                ? bp_notifications_add_notification($notification_data)
                : false;

            if ($notification_id) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('MLD BuddyBoss: Sent notification to user ' . $user_id . ' for ' . $listing_count . ' listings');
                }
                return true;
            } else {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('MLD BuddyBoss: Failed to send notification to user ' . $user_id);
                }
                return false;
            }

        } catch (Exception $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD BuddyBoss: Error sending notification: ' . $e->getMessage());
            }
            return false;
        }
    }

    /**
     * Format notifications for display in BuddyBoss
     */
    public function format_notifications($content, $item_id, $secondary_item_id, $action_name, $component_name) {
        if ($component_name !== self::COMPONENT_NAME || $action_name !== self::NOTIFICATION_ACTION) {
            return $content;
        }

        // Get the saved search
        $search = $this->get_saved_search($item_id);
        if (!$search) {
            return $content;
        }

        $listing_count = intval($secondary_item_id);
        $search_name = isset($search->name) ? $search->name : (isset($search->search_name) ? $search->search_name : 'Your Saved Search');

        // Format the notification text
        if ($listing_count === 1) {
            $content = sprintf(
                __('1 new property matches your search "%s"', 'mls-listings-display'),
                $search_name
            );
        } else {
            $content = sprintf(
                __('%d new properties match your search "%s"', 'mls-listings-display'),
                $listing_count,
                $search_name
            );
        }

        // Add link to view listings
        $search_url = home_url('/properties/?search=' . urlencode($search_name));
        $content = '<a href="' . esc_url($search_url) . '">' . $content . '</a>';

        return $content;
    }

    /**
     * Get saved search by ID
     */
    private function get_saved_search($search_id) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'mld_saved_searches';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE id = %d",
            $search_id
        ));
    }

    /**
     * Check if user wants BuddyBoss notifications
     */
    private function user_wants_buddyboss_notifications($user_id) {
        // Get user preference (you might want to add this as a user meta option)
        $wants_notifications = get_user_meta($user_id, 'mld_buddyboss_notifications', true);

        // Default to enabled if not set
        if ($wants_notifications === '') {
            $wants_notifications = '1';
        }

        return $wants_notifications === '1';
    }

    /**
     * Mark notifications as read when user views listings
     */
    public function mark_notifications_read($user_id, $search_id = null) {
        if (!$this->is_buddyboss_active()) {
            return false;
        }

        $args = [
            'user_id' => $user_id,
            'component_name' => self::COMPONENT_NAME,
            'component_action' => self::NOTIFICATION_ACTION,
            'is_new' => 1
        ];

        if ($search_id) {
            $args['item_id'] = $search_id;
        }

        return bp_notifications_mark_notifications_by_type($user_id, self::COMPONENT_NAME, self::NOTIFICATION_ACTION, false);
    }

    /**
     * Delete all notifications for a search
     */
    public function delete_search_notifications($search_id) {
        if (!$this->is_buddyboss_active()) {
            return false;
        }

        return bp_notifications_delete_notifications_by_item_id($search_id, self::COMPONENT_NAME, self::NOTIFICATION_ACTION);
    }

    /**
     * Get notification count for user
     */
    public function get_user_notification_count($user_id) {
        if (!$this->is_buddyboss_active()) {
            return 0;
        }

        return bp_notifications_get_unread_notification_count($user_id, self::COMPONENT_NAME);
    }

    /**
     * Send bulk notification for multiple searches
     */
    public function send_bulk_notifications($notifications_data) {
        if (!$this->is_buddyboss_active() || empty($notifications_data)) {
            return false;
        }

        $success_count = 0;

        foreach ($notifications_data as $data) {
            if (isset($data['search']) && isset($data['listings'])) {
                if ($this->send_notification($data['search'], $data['listings'])) {
                    $success_count++;
                }
            }
        }

        return $success_count;
    }

    /**
     * Add user notification preferences
     */
    public function add_user_notification_settings($user_id) {
        if (!$this->is_buddyboss_active()) {
            return;
        }

        // This could be used to add settings to BuddyBoss notification preferences
        // You might want to hook this into BuddyBoss settings pages
    }

    /**
     * Clean up notifications for deleted searches
     */
    public function cleanup_deleted_search_notifications($search_id) {
        if (!$this->is_buddyboss_active()) {
            return false;
        }

        // Delete all notifications for this search
        return $this->delete_search_notifications($search_id);
    }

    /**
     * Get formatted notification link
     */
    private function get_notification_link($search) {
        $search_name = isset($search->name) ? $search->name : (isset($search->search_name) ? $search->search_name : 'Your Saved Search');
        return home_url('/properties/?search=' . urlencode($search_name));
    }

    /**
     * Send test notification (for admin testing)
     */
    public function send_test_notification($user_id, $search_name = 'Test Search') {
        if (!$this->is_buddyboss_active() || !current_user_can('manage_options')) {
            return false;
        }

        // Create a mock search object
        $mock_search = (object) [
            'id' => 999999,
            'user_id' => $user_id,
            'search_name' => $search_name
        ];

        // Create mock listings
        $mock_listings = [
            (object) ['mls_number' => 'TEST123'],
            (object) ['mls_number' => 'TEST456']
        ];

        return $this->send_notification($mock_search, $mock_listings);
    }
}