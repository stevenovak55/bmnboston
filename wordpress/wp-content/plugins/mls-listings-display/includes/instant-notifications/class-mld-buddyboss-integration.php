<?php
/**
 * MLD BuddyBoss Integration for Instant Notifications
 *
 * @package MLS_Listings_Display
 * @subpackage Instant_Notifications
 */

if (!defined('ABSPATH')) {
    exit;
}

// Global function is now defined in main plugin file (mls-listings-display.php) to ensure early loading

class MLD_BuddyBoss_Integration {

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
        // Store instance reference
        self::$instance = $this;

        // Register component with BuddyBoss
        add_action('bp_setup_globals', [$this, 'setup_globals']);
        add_action('bp_setup_nav', [$this, 'setup_nav']);

        // Register notification component
        add_filter('bp_notifications_get_registered_components', [$this, 'register_component']);
        add_filter('bp_notifications_get_notifications_for_user', [$this, 'format_notifications'], 10, 9); // Accept 9 params

        // Add additional notification formatting hooks
        add_filter('bp_notifications_get_component_notification', [$this, 'get_component_notification'], 10, 7);

        // Override BuddyBoss's default description filter with higher priority
        add_filter('bp_get_the_notification_description', [$this, 'override_notification_description'], 100, 2);

        // Add filter for notification links
        add_filter('bp_notifications_get_notification_url', [$this, 'filter_notification_url'], 10, 2);

        // Hook to make notifications linkable
        add_filter('bb_notification_is_read_only', [$this, 'make_notification_linkable'], 10, 2);

        // Add filter to provide notification URL
        add_filter('bp_notifications_get_notifications_for_user', [$this, 'add_notification_link'], 10, 9);
    }

    /**
     * Setup globals for BuddyBoss
     */
    public function setup_globals() {
        if (!defined('BP_MLD_SLUG')) {
            define('BP_MLD_SLUG', 'property-alerts');
        }

        if (!isset(buddypress()->mld_saved_searches)) {
            buddypress()->mld_saved_searches = new stdClass();
            buddypress()->mld_saved_searches->id = 'mld_saved_searches';
            buddypress()->mld_saved_searches->slug = BP_MLD_SLUG;
            buddypress()->mld_saved_searches->name = __('Property Alerts', 'mld');
            buddypress()->mld_saved_searches->notification_callback = [$this, 'format_notifications'];

            // Add format_notification_function that BuddyBoss expects
            buddypress()->mld_saved_searches->format_notification_function = 'mld_format_notification_function';

            // Debug logging
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[MLD BuddyBoss] Component registered with format_notification_function: mld_format_notification_function');
            }
        }
    }

    /**
     * Setup navigation
     */
    public function setup_nav() {
        // Add property alerts tab to user profile if needed
    }

    /**
     * Register component with BuddyBoss
     */
    public function register_component($components) {
        if (!in_array('mld_saved_searches', $components)) {
            $components[] = 'mld_saved_searches';
        }
        return $components;
    }

    /**
     * Send instant notification to BuddyBoss
     */
    public function send_instant_notification($user_id, $listing_data, $search, $type) {
        if (!function_exists('bp_notifications_add_notification')) {
            return false;
        }

        // Ensure we have a valid listing ID first
        $listing_id_string = $listing_data['ListingId'] ??
                             $listing_data['listing_id'] ??
                             $listing_data['ListingID'] ??
                             $listing_data['listing_key'] ??
                             '0';

        // BuddyBoss requires numeric item_id, so convert string listing IDs to a numeric hash
        // Use CRC32 to create a consistent numeric ID from the string
        if (is_numeric($listing_id_string)) {
            $listing_id = intval($listing_id_string);
        } else {
            // Create a numeric hash for non-numeric listing IDs
            $listing_id = crc32($listing_id_string);
            // Ensure positive number
            if ($listing_id < 0) {
                $listing_id = abs($listing_id);
            }
        }

        // Store original listing ID in metadata
        $original_listing_id = $listing_id_string;

        // Prevent duplicates
        $existing = bp_notifications_get_all_notifications_for_user($user_id);
        foreach ($existing as $notification) {
            if ($notification->component_name === 'mld_saved_searches' &&
                $notification->item_id == $listing_id &&
                $notification->secondary_item_id == $search->id) {
                return false; // Already exists
            }
        }

        // Use the listing_id we already got above
        $notification_id = bp_notifications_add_notification([
            'user_id'           => $user_id,
            'item_id'           => $listing_id,
            'secondary_item_id' => $search->id,
            'component_name'    => 'mld_saved_searches',
            'component_action'  => 'instant_' . $type,
            'date_notified'     => bp_core_current_time(),
            'is_new'            => 1,
            'allow_duplicate'   => false,
        ]);

        if ($notification_id) {
            // Store additional metadata
            bp_notifications_add_meta($notification_id, 'listing_id', $original_listing_id);
            bp_notifications_add_meta($notification_id, 'property_address',
                $this->get_property_address($listing_data));
            bp_notifications_add_meta($notification_id, 'property_price',
                $listing_data['ListPrice'] ?? $listing_data['list_price'] ?? 0);
            bp_notifications_add_meta($notification_id, 'search_name', $search->name);
            bp_notifications_add_meta($notification_id, 'property_url',
                $this->get_property_url($listing_data));
            bp_notifications_add_meta($notification_id, 'property_beds',
                $listing_data['BedroomsTotal'] ?? $listing_data['bedrooms_total'] ?? 0);
            bp_notifications_add_meta($notification_id, 'property_baths',
                $listing_data['BathroomsTotalInteger'] ?? $listing_data['bathrooms_total'] ?? 0);
            bp_notifications_add_meta($notification_id, 'property_sqft',
                $listing_data['LivingArea'] ?? $listing_data['living_area'] ?? 0);

            // Store digest metadata if present
            if (isset($listing_data['digest_count'])) {
                bp_notifications_add_meta($notification_id, 'digest_count', $listing_data['digest_count']);
            }
            if (isset($listing_data['digest_type'])) {
                bp_notifications_add_meta($notification_id, 'digest_type', $listing_data['digest_type']);
            }
        }

        return $notification_id;
    }

    /**
     * Format notifications for display
     * Handles both 8 and 9 parameter variations from BuddyBoss
     */
    public function format_notifications($component_action, $item_id, $secondary_item_id,
                                         $total_items, $format = 'string', $component_action_name = '',
                                         $component_name = '', $id = 0, $screen = 'web') {

        // If we receive less than 7th parameter, it means BuddyBoss is using old format
        if (empty($component_name) && is_numeric($component_action_name)) {
            // Shift parameters for old format
            $id = $component_action_name;
            $component_name = 'mld_saved_searches';
            $component_action_name = $component_action;
        }

        // Handle case when called directly without component check
        if (strpos($component_action, 'instant_') === 0) {
            $component_name = 'mld_saved_searches';
        }

        if ($component_name !== 'mld_saved_searches') {
            return $component_action;
        }

        // Handle case where $id might be 0 (BuddyBoss sometimes doesn't pass it)
        if ($id === 0 && $item_id && $secondary_item_id) {
            global $wpdb;
            $notification = $wpdb->get_row($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}bp_notifications
                WHERE item_id = %d AND secondary_item_id = %d
                AND component_name = 'mld_saved_searches'
                ORDER BY id DESC LIMIT 1",
                $item_id,
                $secondary_item_id
            ));
            if ($notification) {
                $id = $notification->id;
            }
        }

        // Get notification metadata
        $search_name = bp_notifications_get_meta($id, 'search_name');
        $property_address = bp_notifications_get_meta($id, 'property_address');
        $property_price = bp_notifications_get_meta($id, 'property_price');
        $property_url = bp_notifications_get_meta($id, 'property_url');
        $property_beds = bp_notifications_get_meta($id, 'property_beds');
        $property_baths = bp_notifications_get_meta($id, 'property_baths');
        $property_sqft = bp_notifications_get_meta($id, 'property_sqft');

        // Debug logging
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf('[MLD BuddyBoss] Formatting notification ID %d: action=%s, address=%s, price=%s',
                $id, $component_action, $property_address, $property_price));
        }

        switch ($component_action) {
            case 'instant_new_listing':
                $property_details = $this->format_property_details($property_beds, $property_baths, $property_sqft);
                $text = sprintf(
                    __('New listing in %s: %s - $%s%s', 'mld'),
                    $this->extract_city($property_address),
                    $property_address,
                    number_format($property_price),
                    $property_details ? ' - ' . $property_details : ''
                );
                $link = $property_url;
                break;

            case 'instant_price_drop':
                $property_details = $this->format_property_details($property_beds, $property_baths, $property_sqft);
                $text = sprintf(
                    __('Price drop! %s: Now $%s%s', 'mld'),
                    $property_address,
                    number_format($property_price),
                    $property_details ? ' - ' . $property_details : ''
                );
                $link = $property_url;
                break;

            case 'instant_status_change':
                $text = sprintf(
                    __('Status change for %s', 'mld'),
                    $property_address
                );
                $link = $property_url;
                break;

            case 'instant_back_on_market':
                $text = sprintf(
                    __('Back on market: %s', 'mld'),
                    $property_address
                );
                $link = $property_url;
                break;

            case 'instant_daily_digest':
                // Check if this is a summary notification
                $digest_count = bp_notifications_get_meta($id, 'digest_count');
                if ($digest_count > 1) {
                    $text = sprintf(
                        __('Daily digest: %d new properties matching "%s"', 'mld'),
                        $digest_count,
                        $search_name
                    );
                } else {
                    $text = sprintf(
                        __('Daily digest: New property in %s - %s', 'mld'),
                        $this->extract_city($property_address),
                        $property_address
                    );
                }
                $link = $property_url ?: home_url('/my-saved-searches/');
                break;

            case 'instant_weekly_digest':
                $digest_count = bp_notifications_get_meta($id, 'digest_count');
                if ($digest_count > 1) {
                    $text = sprintf(
                        __('Weekly digest: %d new properties matching "%s"', 'mld'),
                        $digest_count,
                        $search_name
                    );
                } else {
                    $text = sprintf(
                        __('Weekly digest: New property in %s - %s', 'mld'),
                        $this->extract_city($property_address),
                        $property_address
                    );
                }
                $link = $property_url ?: home_url('/my-saved-searches/');
                break;

            case 'instant_hourly_digest':
                $digest_count = bp_notifications_get_meta($id, 'digest_count');
                if ($digest_count > 1) {
                    $text = sprintf(
                        __('Hourly update: %d new properties matching "%s"', 'mld'),
                        $digest_count,
                        $search_name
                    );
                } else {
                    $text = sprintf(
                        __('Hourly update: New property in %s - %s', 'mld'),
                        $this->extract_city($property_address),
                        $property_address
                    );
                }
                $link = $property_url ?: home_url('/my-saved-searches/');
                break;

            case 'instant_price_increased':
                $property_details = $this->format_property_details($property_beds, $property_baths, $property_sqft);
                $text = sprintf(
                    __('Price increased: %s - Now $%s%s', 'mld'),
                    $property_address,
                    number_format($property_price),
                    $property_details ? ' - ' . $property_details : ''
                );
                $link = $property_url;
                break;

            case 'instant_open_house':
                $text = sprintf(
                    __('Open house scheduled: %s', 'mld'),
                    $property_address
                );
                $link = $property_url;
                break;

            case 'instant_sold':
                $text = sprintf(
                    __('Property sold: %s', 'mld'),
                    $property_address
                );
                $link = $property_url;
                break;

            case 'instant_coming_soon':
                $text = sprintf(
                    __('Coming soon: %s', 'mld'),
                    $property_address
                );
                $link = $property_url;
                break;

            case 'instant_property_updated':
                $property_details = $this->format_property_details($property_beds, $property_baths, $property_sqft);
                $text = sprintf(
                    __('Property updated: %s%s', 'mld'),
                    $property_address,
                    $property_details ? ' - ' . $property_details : ''
                );
                $link = $property_url;
                break;

            default:
                $text = sprintf(
                    __('Property update for "%s"', 'mld'),
                    $search_name
                );
                $link = $property_url ?: home_url();
        }

        // For array format, return both text and link
        if ($format === 'array') {
            return [
                'text' => $text,
                'link' => $link
            ];
        }

        // For string format, just return the text (BuddyBoss will handle the link separately)
        return $text;
    }

    /**
     * Get formatted property address
     */
    private function get_property_address($listing_data) {
        $parts = [];

        if (!empty($listing_data['StreetNumber'])) {
            $parts[] = $listing_data['StreetNumber'];
        }
        if (!empty($listing_data['StreetName'])) {
            $parts[] = $listing_data['StreetName'];
        }
        if (!empty($listing_data['City'])) {
            $parts[] = $listing_data['City'];
        }

        // Fallback to database fields
        if (empty($parts)) {
            if (!empty($listing_data['street_number'])) {
                $parts[] = $listing_data['street_number'];
            }
            if (!empty($listing_data['street_name'])) {
                $parts[] = $listing_data['street_name'];
            }
            if (!empty($listing_data['city'])) {
                $parts[] = $listing_data['city'];
            }
        }

        return implode(' ', $parts) ?: 'Property';
    }

    /**
     * Get property URL
     */
    private function get_property_url($listing_data) {
        $listing_id = $listing_data['ListingId'] ?? $listing_data['listing_id'] ?? '';

        if (empty($listing_id)) {
            return home_url();
        }

        // Build the property URL in the format: /property/{MLS_ID}/
        $property_url = home_url('/property/' . $listing_id . '/');

        // Allow customization via filter
        return apply_filters('mld_property_notification_url', $property_url, $listing_id, $listing_data);
    }

    /**
     * Format property details for notification
     */
    private function format_property_details($beds, $baths, $sqft) {
        $details = [];

        if ($beds > 0) {
            $details[] = $beds . 'bd';
        }
        if ($baths > 0) {
            $details[] = $baths . 'ba';
        }
        if ($sqft > 0) {
            $details[] = number_format($sqft) . ' sq ft';
        }

        return implode('/', $details);
    }

    /**
     * Extract city from address
     */
    private function extract_city($address) {
        // Simple extraction - get the last part which is usually the city
        $parts = explode(',', $address);
        if (count($parts) > 1) {
            return trim(end($parts));
        }

        // Try space separation
        $parts = explode(' ', $address);
        if (count($parts) > 2) {
            return trim(end($parts));
        }

        return 'your area';
    }

    /**
     * Override BuddyBoss notification description display
     */
    public function override_notification_description($description, $notification) {
        // Only handle our component
        if (!isset($notification->component_name) || $notification->component_name !== 'mld_saved_searches') {
            return $description;
        }

        // If it's showing the raw action name, format it properly
        if ($description === $notification->component_action) {
            // Use our formatting function
            $formatted = $this->format_notifications(
                $notification->component_action,
                $notification->item_id,
                $notification->secondary_item_id,
                1,
                'string',
                $notification->component_action,
                'mld_saved_searches',
                $notification->id
            );

            // Return the formatted version if it's different from the action name
            if ($formatted !== $notification->component_action) {
                return $formatted;
            }
        }

        return $description;
    }

    /**
     * Get component notification (additional hook for BuddyBoss)
     */
    public function get_component_notification($content, $item_id, $secondary_item_id, $action_item_count, $format, $notification_id, $screen) {
        global $wpdb;

        // Get the notification to check component
        $notification = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}bp_notifications WHERE id = %d",
            $notification_id
        ));

        if (!$notification || $notification->component_name !== 'mld_saved_searches') {
            return $content;
        }

        // Use our format_notifications method
        return $this->format_notifications(
            $notification->component_action,
            $notification->item_id,
            $notification->secondary_item_id,
            1,
            $format,
            $notification->component_action,
            'mld_saved_searches',
            $notification_id
        );
    }

    /**
     * Filter notification URL to ensure property links work
     */
    public function filter_notification_url($url, $notification) {
        // Only handle our component
        if (!isset($notification->component_name) || $notification->component_name !== 'mld_saved_searches') {
            return $url;
        }

        // Get the property URL from metadata
        $property_url = bp_notifications_get_meta($notification->id, 'property_url');

        if (!empty($property_url)) {
            return $property_url;
        }

        // Fallback to constructing URL if metadata is missing
        $listing_id = bp_notifications_get_meta($notification->id, 'listing_id');
        if ($listing_id) {
            return $this->get_property_url(['listing_id' => $listing_id]);
        }

        return $url;
    }

    /**
     * Make our notifications linkable (not read-only)
     */
    public function make_notification_linkable($read_only, $notification) {
        // If this is our component, make it linkable
        if (isset($notification->component_name) && $notification->component_name === 'mld_saved_searches') {
            // Return false to indicate it's NOT read-only (i.e., it's linkable)
            return false;
        }

        return $read_only;
    }

    /**
     * Add notification link when BuddyBoss requests it
     */
    public function add_notification_link($content, $item_id, $secondary_item_id, $total_items,
                                          $format, $component_action, $component_name, $id, $screen = 'web') {

        // Only handle our component
        if ($component_name !== 'mld_saved_searches') {
            return $content;
        }

        // Get the property URL from metadata
        $property_url = bp_notifications_get_meta($id, 'property_url');

        // If no URL in metadata, try to construct it
        if (empty($property_url)) {
            $listing_id = bp_notifications_get_meta($id, 'listing_id');
            if ($listing_id) {
                $property_url = $this->get_property_url(['listing_id' => $listing_id]);
            }
        }

        // Format based on what BuddyBoss expects
        if ($format === 'array') {
            // Get the text description
            $text = $this->format_notifications($component_action, $item_id, $secondary_item_id,
                                               1, 'string', $component_action, 'mld_saved_searches', $id);
            return [
                'text' => $text,
                'link' => $property_url ?: home_url()
            ];
        } else {
            // Return string format with link
            $text = $this->format_notifications($component_action, $item_id, $secondary_item_id,
                                               1, 'string', $component_action, 'mld_saved_searches', $id);
            if ($property_url) {
                return '<a href="' . esc_url($property_url) . '">' . esc_html($text) . '</a>';
            }
            return $text;
        }
    }
}