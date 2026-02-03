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
        if (!class_exists('MLD_Push_Notifications') || !class_exists('MLD_Agent_Manager')) {
            $this->debug_log('Required notification classes not available');
            return;
        }

        $clients = MLD_Agent_Manager::get_agent_clients($agent_user_id, 'active');

        if (empty($clients)) {
            $this->debug_log("No active clients for agent user ID {$agent_user_id}");
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

        foreach ($clients as $client) {
            $client_id = is_object($client)
                ? ($client->client_id ?? null)
                : ($client['client_id'] ?? null);

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

        $this->debug_log(sprintf(
            'Notified %d clients about new exclusive listing #%d (sent: %d, failed: %d)',
            count($clients),
            $listing_id,
            $total_sent,
            $total_failed
        ));
    }

    /**
     * Log debug message if WP_DEBUG is enabled
     *
     * @param string $message The message to log
     */
    private function debug_log($message) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('EL_Notifications: ' . $message);
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
        $address = $this->build_address($listing_data);
        if ($address) {
            $parts[] = $address;
        }

        if (!empty($listing_data['city'])) {
            $parts[] = $listing_data['city'];
        }

        if (!empty($listing_data['list_price'])) {
            $parts[] = $this->format_price($listing_data['list_price']);
        }

        $specs = $this->build_specs($listing_data);
        if ($specs) {
            $parts[] = $specs;
        }

        return implode(' • ', $parts);
    }

    /**
     * Build address string from listing data
     */
    private function build_address($listing_data) {
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

        return $address_parts ? implode(' ', $address_parts) : '';
    }

    /**
     * Format price for display
     */
    private function format_price($price) {
        $price = floatval($price);
        if ($price >= 1000000) {
            return '$' . number_format($price / 1000000, 1) . 'M';
        }
        return '$' . number_format($price);
    }

    /**
     * Build bed/bath specs string
     */
    private function build_specs($listing_data) {
        $specs = array();

        if (!empty($listing_data['bedrooms_total'])) {
            $specs[] = $listing_data['bedrooms_total'] . ' bed';
        }
        if (!empty($listing_data['bathrooms_total'])) {
            $specs[] = $listing_data['bathrooms_total'] . ' bath';
        }

        return $specs ? implode(', ', $specs) : '';
    }

    /**
     * Get the main photo URL for a listing
     *
     * @param int $listing_id The listing ID
     * @return string|null Photo URL or null if not available
     */
    private function get_listing_main_photo($listing_id) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'exclusive_listing_photos';

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
