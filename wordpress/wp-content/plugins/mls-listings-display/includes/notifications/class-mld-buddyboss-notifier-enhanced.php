<?php
/**
 * Enhanced BuddyBoss Notifier with Rich Property Details
 *
 * @package MLS_Listings_Display
 * @since 5.0.3
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_BuddyBoss_Notifier_Enhanced {

    private static $instance = null;

    const COMPONENT_NAME = 'mls_listings';
    const NOTIFICATION_ACTION = 'new_listing_match';

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init();
    }

    private function init() {
        if (!$this->is_buddyboss_active()) {
            return;
        }

        add_action('bp_setup_globals', [$this, 'register_notification_component']);
        add_filter('bp_notifications_get_registered_components', [$this, 'register_notifications']);
        add_filter('bp_notifications_get_notifications_for_user', [$this, 'format_notifications'], 10, 5);

        // Add custom notification rendering
        add_action('bp_notifications_get_notifications_for_user', [$this, 'format_rich_notifications'], 10, 5);
    }

    private function is_buddyboss_active() {
        return function_exists('bp_is_active') &&
               bp_is_active('notifications') &&
               function_exists('bp_notifications_add_notification');
    }

    public function register_notification_component() {
        if (!$this->is_buddyboss_active()) {
            return;
        }
    }

    public function register_notifications($component_names) {
        if (!$this->is_buddyboss_active()) {
            return $component_names;
        }

        $component_names[] = self::COMPONENT_NAME;
        return $component_names;
    }

    /**
     * Send enhanced BuddyBoss notification with property details
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

        if (!$this->user_wants_buddyboss_notifications($user_id)) {
            return false;
        }

        try {
            // Store listing details in transient for rich display
            $transient_key = 'mld_bb_listings_' . $user_id . '_' . $search->id;
            $listing_data = $this->prepare_listing_data($listings);
            set_transient($transient_key, $listing_data, DAY_IN_SECONDS);

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

            $notification_id = function_exists('bp_notifications_add_notification')
                               ? bp_notifications_add_notification($notification_data)
                               : false;

            if ($notification_id) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('MLD BuddyBoss Enhanced: Notification sent to user ' . $user_id . ' for ' . $listing_count . ' listings');
                }
                return true;
            } else {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('MLD BuddyBoss Enhanced: Failed to send notification to user ' . $user_id);
                }
                return false;
            }

        } catch (Exception $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD BuddyBoss Enhanced: Error sending notification: ' . $e->getMessage());
            }
            return false;
        }
    }

    /**
     * Prepare listing data for rich display
     */
    private function prepare_listing_data($listings) {
        global $wpdb;
        $enhanced_listings = [];

        foreach (array_slice($listings, 0, 5) as $listing) { // Limit to 5 for notifications
            // Get location data
            $location = $wpdb->get_row($wpdb->prepare(
                "SELECT street_number, street_name, city, state_or_province, postal_code
                 FROM {$wpdb->prefix}bme_listing_location WHERE listing_id = %d",
                $listing->listing_id
            ));

            // Get property details
            $details = $wpdb->get_row($wpdb->prepare(
                "SELECT bedrooms_total, bathrooms_total_integer, living_area
                 FROM {$wpdb->prefix}bme_listing_details WHERE listing_id = %d",
                $listing->listing_id
            ));

            // Get first image
            $image = $wpdb->get_var($wpdb->prepare(
                "SELECT media_url FROM {$wpdb->prefix}bme_media
                 WHERE listing_id = %d AND media_category = 'Photo'
                 ORDER BY order_index LIMIT 1",
                $listing->listing_id
            ));

            $enhanced_listings[] = [
                'listing_id' => $listing->listing_id,
                'price' => $listing->list_price,
                'status' => $listing->mls_status,
                'address' => $location ? $location->street_number . ' ' . $location->street_name : '',
                'city' => $location ? $location->city : '',
                'state' => $location ? $location->state_or_province : '',
                'zip' => $location ? $location->postal_code : '',
                'beds' => $details ? $details->bedrooms_total : null,
                'baths' => $details ? $details->bathrooms_total_integer : null,
                'sqft' => $details ? $details->living_area : null,
                'image' => $image,
                'property_type' => $listing->property_type
            ];
        }

        return $enhanced_listings;
    }

    /**
     * Format notifications with rich property details
     */
    public function format_notifications($content, $item_id, $secondary_item_id, $total_items, $format) {
        if (!$this->is_buddyboss_active()) {
            return $content;
        }

        $user_id = bp_loggedin_user_id();
        $transient_key = 'mld_bb_listings_' . $user_id . '_' . $item_id;
        $listing_data = get_transient($transient_key);

        // Get saved search info
        $search = $this->get_saved_search($item_id);
        if (!$search) {
            return $content;
        }

        $search_name = isset($search->name) ? $search->name : (isset($search->search_name) ? $search->search_name : 'Your Saved Search');
        $listing_count = intval($secondary_item_id);

        // Build rich HTML notification
        if ($format === 'string' && $listing_data) {
            $html = '<div class="mld-bb-notification">';

            // Notification header
            $html .= '<div class="mld-notification-header">';
            $html .= sprintf(
                '<strong>%d %s for "%s"</strong>',
                $listing_count,
                $listing_count === 1 ? 'new property' : 'new properties',
                esc_html($search_name)
            );
            $html .= '</div>';

            // Show first 2-3 listings with better formatting
            $html .= '<div class="mld-notification-listings" style="margin-top: 12px;">';
            foreach (array_slice($listing_data, 0, min(3, count($listing_data))) as $index => $listing) {
                // Make the entire card clickable
                $property_url = home_url('/property/' . $listing['listing_id']);

                $html .= '<div style="margin-bottom: 12px; border-bottom: 1px solid #e0e0e0; padding-bottom: 12px;">';
                $html .= '<a href="' . esc_url($property_url) . '" style="text-decoration: none; color: inherit; display: block;">';

                // Property header with price and address
                $html .= '<div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 8px;">';
                $html .= '<div>';
                $html .= '<div style="font-weight: 600; font-size: 16px; color: #1a1a1a;">$' . number_format($listing['price']) . '</div>';
                $html .= '<div style="font-size: 14px; color: #555; margin-top: 2px;">' . esc_html($listing['address']) . '</div>';

                if ($listing['beds'] || $listing['baths'] || $listing['sqft']) {
                    $html .= '<div style="font-size: 12px; color: #999; margin-top: 2px;">';
                    $details = [];
                    if ($listing['beds']) $details[] = $listing['beds'] . ' bed' . ($listing['beds'] != 1 ? 's' : '');
                    if ($listing['baths']) $details[] = $listing['baths'] . ' bath' . ($listing['baths'] != 1 ? 's' : '');
                    if ($listing['sqft']) $details[] = number_format($listing['sqft']) . ' sq ft';
                    $html .= implode(' • ', $details);
                    $html .= '</div>';
                }

                // Status badge
                if ($listing['status']) {
                    $status_color = $listing['status'] === 'New' ? '#28a745' : '#ffc107';
                    $html .= '<span style="display: inline-block; margin-top: 4px; padding: 2px 6px; background: ' . $status_color . '; color: white; font-size: 10px; border-radius: 3px; text-transform: uppercase;">' . esc_html($listing['status']) . '</span>';
                }
                $html .= '</div>';

                // Add thumbnail if available
                if ($listing['image']) {
                    $html .= '<img src="' . esc_url($listing['image']) . '" style="width: 80px; height: 60px; object-fit: cover; border-radius: 4px;">';
                }

                $html .= '</div>';
                $html .= '</a>';
                $html .= '</div>'; // Close the property container
            }

            if ($listing_count > 3) {
                $html .= '<div style="margin-top: 12px; padding-top: 12px; border-top: 1px solid #e0e0e0; font-size: 14px; color: #666; text-align: center;">+ ' . ($listing_count - 3) . ' more properties</div>';
            }

            $html .= '</div>';

            // View all link with admin-configured URL
            global $wpdb;
            $mld_settings = get_option('mld_settings', []);
            $search_page_url = $mld_settings['search_page_url'] ?? '/search/';
            $search_url = home_url($search_page_url);
            if (!empty($item_id)) {
                $search_url = add_query_arg('saved_search', $item_id, $search_url);
            }

            $html .= '<div style="margin-top: 16px; text-align: center;">';
            $html .= '<a href="' . esc_url($search_url) . '" style="display: inline-block; padding: 10px 20px; background: #2c5aa0; color: white; text-decoration: none; border-radius: 4px; font-size: 14px; font-weight: 500;">View All Matching Properties →</a>';
            $html .= '</div>';

            $html .= '</div>';

            // Add custom CSS
            $this->add_notification_styles();

            return $html;
        }

        // Fallback to simple text
        $text = sprintf(
            __('%d new %s match your search "%s"', 'mls-listings-display'),
            $listing_count,
            $listing_count === 1 ? 'property' : 'properties',
            $search_name
        );

        $search_url = home_url('/properties/?search=' . urlencode($search_name));
        return '<a href="' . esc_url($search_url) . '">' . $text . '</a>';
    }

    /**
     * Add custom styles for notifications
     */
    private function add_notification_styles() {
        static $styles_added = false;

        if (!$styles_added) {
            add_action('wp_head', function() {
                ?>
                <style>
                    .mld-bb-notification {
                        padding: 10px;
                        background: white;
                        border-radius: 6px;
                        border: 1px solid #e1e5e9;
                        margin: 10px 0;
                    }
                    .mld-notification-header {
                        margin-bottom: 10px;
                        padding-bottom: 8px;
                        border-bottom: 1px solid #e9ecef;
                    }
                    .bb-ac-notification-content .mld-bb-notification {
                        max-width: 400px;
                    }
                    @media (max-width: 600px) {
                        .mld-listing-mini {
                            flex-direction: column;
                        }
                        .mld-listing-mini img {
                            width: 100% !important;
                            height: 120px !important;
                        }
                    }
                </style>
                <?php
            });
            $styles_added = true;
        }
    }

    /**
     * Format rich notifications for display
     */
    public function format_rich_notifications($content, $item_id, $secondary_item_id, $total_items, $format) {
        // This is handled in format_notifications above
        return $content;
    }

    private function get_saved_search($search_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'mld_saved_searches';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE id = %d",
            $search_id
        ));
    }

    private function user_wants_buddyboss_notifications($user_id) {
        $wants_notifications = get_user_meta($user_id, 'mld_buddyboss_notifications', true);

        // Default to enabled if not set
        if ($wants_notifications === '') {
            $wants_notifications = '1';
        }

        return $wants_notifications === '1';
    }

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
}