<?php
/**
 * Exclusive Listings - Push Notifications
 *
 * Handles push notifications when an agent creates a new exclusive listing.
 * Notifies the agent's assigned clients.
 *
 * @package Exclusive_Listings
 * @since 1.3.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class EL_Notifications {

    /**
     * Initialize hooks
     */
    public function __construct() {
        add_action('exclusive_listing_created', array($this, 'notify_clients'), 10, 3);
    }

    /**
     * Notify agent's clients when a new exclusive listing is created
     *
     * @param int $listing_id The new listing ID
     * @param array $listing_data The listing data
     * @param int $agent_user_id The WordPress user ID of the agent who created the listing
     */
    public function notify_clients($listing_id, $listing_data, $agent_user_id) {
        // Ensure MLD Push Notifications class exists
        if (!class_exists('MLD_Push_Notifications')) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('EL_Notifications: MLD_Push_Notifications class not available');
            }
            return;
        }

        // Ensure Agent Manager class exists
        if (!class_exists('MLD_Agent_Manager')) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('EL_Notifications: MLD_Agent_Manager class not available');
            }
            return;
        }

        // Get agent's active clients
        $clients = MLD_Agent_Manager::get_agent_clients($agent_user_id, 'active');

        if (empty($clients)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("EL_Notifications: No active clients for agent user ID {$agent_user_id}");
            }
            return;
        }

        // Build notification content
        $title = 'New Exclusive Listing';
        $body = $this->format_notification_body($listing_data);

        // Get listing photo for rich notification
        $image_url = $this->get_listing_main_photo($listing_id);

        // Generate listing key for deep linking
        $listing_key = md5('exclusive_' . $listing_id);

        // Get agent name for notification context
        $agent_user = get_userdata($agent_user_id);
        $agent_name = $agent_user ? $agent_user->display_name : 'Your agent';

        // Track results
        $total_sent = 0;
        $total_failed = 0;

        // Send notification to each client
        foreach ($clients as $client) {
            // PHP 8+ compatible: check object vs array before accessing
            if (is_object($client)) {
                $client_id = $client->client_id ?? null;
            } else {
                $client_id = $client['client_id'] ?? null;
            }

            if (!$client_id) {
                continue;
            }

            // Build context for this notification
            $context = array(
                'listing_id' => $listing_id,
                'listing_key' => $listing_key,
                'is_exclusive' => true,
                'agent_name' => $agent_name,
            );

            // Add image URL if available
            if (!empty($image_url)) {
                $context['image_url'] = $image_url;
            }

            // Send push notification
            $result = MLD_Push_Notifications::send_activity_notification(
                $client_id,
                $title,
                $body,
                'exclusive_listing',
                $context
            );

            if (!empty($result['sent_count'])) {
                $total_sent += $result['sent_count'];
            }
            if (!empty($result['failed_count'])) {
                $total_failed += $result['failed_count'];
            }
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                'EL_Notifications: Notified %d clients about new exclusive listing #%d (sent: %d, failed: %d)',
                count($clients),
                $listing_id,
                $total_sent,
                $total_failed
            ));
        }
    }

    /**
     * Format notification body from listing data
     *
     * @param array $listing_data The listing data
     * @return string Formatted notification body
     */
    private function format_notification_body($listing_data) {
        $parts = array();

        // Build address
        $address_parts = array();
        if (!empty($listing_data['street_number'])) {
            $address_parts[] = $listing_data['street_number'];
        }
        if (!empty($listing_data['street_name'])) {
            $address_parts[] = $listing_data['street_name'];
        }
        if (!empty($listing_data['unit_number'])) {
            $address_parts[] = 'Unit ' . $listing_data['unit_number'];
        }
        if (!empty($address_parts)) {
            $parts[] = implode(' ', $address_parts);
        }

        // Add city
        if (!empty($listing_data['city'])) {
            $parts[] = $listing_data['city'];
        }

        // Add price
        if (!empty($listing_data['list_price'])) {
            $price = floatval($listing_data['list_price']);
            if ($price >= 1000000) {
                $parts[] = '$' . number_format($price / 1000000, 1) . 'M';
            } else {
                $parts[] = '$' . number_format($price);
            }
        }

        // Add beds/baths
        $specs = array();
        if (!empty($listing_data['bedrooms_total'])) {
            $specs[] = $listing_data['bedrooms_total'] . ' bed';
        }
        if (!empty($listing_data['bathrooms_total'])) {
            $specs[] = $listing_data['bathrooms_total'] . ' bath';
        }
        if (!empty($specs)) {
            $parts[] = implode(', ', $specs);
        }

        return implode(' • ', $parts);
    }

    /**
     * Get the main photo URL for a listing
     *
     * @param int $listing_id The listing ID
     * @return string|null Photo URL or null if not available
     */
    private function get_listing_main_photo($listing_id) {
        global $wpdb;

        // Query the exclusive_listing_photos table
        $table_name = $wpdb->prefix . 'exclusive_listing_photos';

        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") !== $table_name) {
            return null;
        }

        $photo = $wpdb->get_var($wpdb->prepare(
            "SELECT image_url FROM {$table_name}
             WHERE listing_id = %d AND is_active = 1
             ORDER BY display_order ASC, id ASC
             LIMIT 1",
            $listing_id
        ));

        return $photo ?: null;
    }
}
