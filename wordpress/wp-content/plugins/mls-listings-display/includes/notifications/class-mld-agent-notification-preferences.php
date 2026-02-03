<?php
/**
 * Agent Notification Preferences
 *
 * Manages per-agent, per-type notification settings for client activity alerts.
 *
 * @package MLS_Listings_Display
 * @subpackage Notifications
 * @since 6.43.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Agent_Notification_Preferences {

    /**
     * Notification types
     */
    const TYPE_CLIENT_LOGIN = 'client_login';
    const TYPE_APP_OPEN = 'app_open';
    const TYPE_FAVORITE_ADDED = 'favorite_added';
    const TYPE_SEARCH_CREATED = 'search_created';
    const TYPE_TOUR_REQUESTED = 'tour_requested';

    /**
     * Get all valid notification types
     *
     * @return array
     */
    public static function get_notification_types() {
        return array(
            self::TYPE_CLIENT_LOGIN,
            self::TYPE_APP_OPEN,
            self::TYPE_FAVORITE_ADDED,
            self::TYPE_SEARCH_CREATED,
            self::TYPE_TOUR_REQUESTED
        );
    }

    /**
     * Get human-readable labels for notification types
     *
     * @return array
     */
    public static function get_notification_type_labels() {
        return array(
            self::TYPE_CLIENT_LOGIN => 'Client Login',
            self::TYPE_APP_OPEN => 'App Opened',
            self::TYPE_FAVORITE_ADDED => 'Property Favorited',
            self::TYPE_SEARCH_CREATED => 'Search Created',
            self::TYPE_TOUR_REQUESTED => 'Tour Requested'
        );
    }

    /**
     * Get agent's preferences for a specific notification type
     * Returns defaults if no preference is set
     *
     * @param int $agent_id
     * @param string $type
     * @return array|null Array with email_enabled and push_enabled, or null if type is invalid
     */
    public static function get_preferences($agent_id, $type) {
        // Validate type
        if (!in_array($type, self::get_notification_types())) {
            return null;
        }

        global $wpdb;

        $table = $wpdb->prefix . 'mld_agent_notification_preferences';

        $result = $wpdb->get_row($wpdb->prepare(
            "SELECT email_enabled, push_enabled
             FROM {$table}
             WHERE agent_id = %d AND notification_type = %s",
            $agent_id, $type
        ), ARRAY_A);

        // Return defaults if no preference set (all enabled by default)
        if (!$result) {
            return array(
                'email_enabled' => true,
                'push_enabled' => true
            );
        }

        return array(
            'email_enabled' => (bool) $result['email_enabled'],
            'push_enabled' => (bool) $result['push_enabled']
        );
    }

    /**
     * Get all preferences for an agent
     *
     * @param int $agent_id
     * @return array Keyed by notification type
     */
    public static function get_all_preferences($agent_id) {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_agent_notification_preferences';
        $types = self::get_notification_types();

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT notification_type, email_enabled, push_enabled
             FROM {$table}
             WHERE agent_id = %d",
            $agent_id
        ), ARRAY_A);

        // Build preferences array with defaults
        $preferences = array();
        foreach ($types as $type) {
            $preferences[$type] = array(
                'email_enabled' => true,
                'push_enabled' => true
            );
        }

        // Override with actual settings from database
        foreach ($results as $row) {
            if (isset($preferences[$row['notification_type']])) {
                $preferences[$row['notification_type']] = array(
                    'email_enabled' => (bool) $row['email_enabled'],
                    'push_enabled' => (bool) $row['push_enabled']
                );
            }
        }

        return $preferences;
    }

    /**
     * Update preferences for an agent
     *
     * @param int $agent_id
     * @param array $preferences Array of type => array(email_enabled, push_enabled)
     * @return bool
     */
    public static function update_preferences($agent_id, $preferences) {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_agent_notification_preferences';
        $valid_types = self::get_notification_types();

        foreach ($preferences as $type => $settings) {
            // Skip invalid types
            if (!in_array($type, $valid_types)) {
                continue;
            }

            $email_enabled = isset($settings['email_enabled']) ? ($settings['email_enabled'] ? 1 : 0) : 1;
            $push_enabled = isset($settings['push_enabled']) ? ($settings['push_enabled'] ? 1 : 0) : 1;

            // Use REPLACE to insert or update
            $wpdb->replace(
                $table,
                array(
                    'agent_id' => $agent_id,
                    'notification_type' => $type,
                    'email_enabled' => $email_enabled,
                    'push_enabled' => $push_enabled
                ),
                array('%d', '%s', '%d', '%d')
            );
        }

        return true;
    }

    /**
     * Set a single preference
     *
     * @param int $agent_id
     * @param string $type
     * @param string $channel 'email' or 'push'
     * @param bool $enabled
     * @return bool
     */
    public static function set_preference($agent_id, $type, $channel, $enabled) {
        if (!in_array($type, self::get_notification_types())) {
            return false;
        }

        if (!in_array($channel, array('email', 'push'))) {
            return false;
        }

        global $wpdb;

        $table = $wpdb->prefix . 'mld_agent_notification_preferences';
        $column = $channel . '_enabled';

        // Check if record exists
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE agent_id = %d AND notification_type = %s",
            $agent_id, $type
        ));

        if ($existing) {
            // Update existing
            $wpdb->update(
                $table,
                array($column => $enabled ? 1 : 0),
                array('agent_id' => $agent_id, 'notification_type' => $type),
                array('%d'),
                array('%d', '%s')
            );
        } else {
            // Insert new with defaults, overriding the specified channel
            $data = array(
                'agent_id' => $agent_id,
                'notification_type' => $type,
                'email_enabled' => 1,
                'push_enabled' => 1
            );
            $data[$column] = $enabled ? 1 : 0;

            $wpdb->insert($table, $data, array('%d', '%s', '%d', '%d'));
        }

        return true;
    }

    /**
     * Check if a specific notification is enabled
     *
     * @param int $agent_id
     * @param string $type
     * @param string $channel 'email' or 'push'
     * @return bool
     */
    public static function is_enabled($agent_id, $type, $channel) {
        $prefs = self::get_preferences($agent_id, $type);

        if (!$prefs) {
            return false;
        }

        $key = $channel . '_enabled';
        return isset($prefs[$key]) ? (bool) $prefs[$key] : true;
    }

    /**
     * Disable all notifications for an agent
     *
     * @param int $agent_id
     * @return bool
     */
    public static function disable_all($agent_id) {
        $preferences = array();
        foreach (self::get_notification_types() as $type) {
            $preferences[$type] = array(
                'email_enabled' => false,
                'push_enabled' => false
            );
        }

        return self::update_preferences($agent_id, $preferences);
    }

    /**
     * Enable all notifications for an agent
     *
     * @param int $agent_id
     * @return bool
     */
    public static function enable_all($agent_id) {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_agent_notification_preferences';

        // Just delete all preferences - defaults are all enabled
        $wpdb->delete($table, array('agent_id' => $agent_id), array('%d'));

        return true;
    }

    /**
     * Delete all preferences for an agent (cleanup on user deletion)
     *
     * @param int $agent_id
     * @return bool
     */
    public static function delete_agent_preferences($agent_id) {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_agent_notification_preferences';

        return $wpdb->delete($table, array('agent_id' => $agent_id), array('%d')) !== false;
    }
}
