<?php
/**
 * MLD BuddyBoss Modern Notification API Integration
 *
 * Implements the Modern BuddyBoss Notification API for property alerts
 * Following the exact pattern used by BP_Messages_Notification
 *
 * @package MLS_Listings_Display
 * @subpackage Instant_Notifications
 * @since 4.5.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class MLD_BuddyBoss_Notification
 *
 * Extends BP_Core_Notification_Abstract to properly integrate with BuddyBoss notification settings
 */
class MLD_BuddyBoss_Notification extends BP_Core_Notification_Abstract {

    /**
     * Instance of this class
     *
     * @var MLD_BuddyBoss_Notification
     */
    private static $instance = null;

    /**
     * Get singleton instance
     *
     * @return MLD_BuddyBoss_Notification
     */
    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor - Following BuddyBoss pattern
     */
    public function __construct() {
        // Initialize with bp_init like BuddyBoss's own notification classes
        add_action('bp_init', array($this, 'start'), 5);
    }

    /**
     * Load notification group, types, and register everything
     *
     * This is called by the parent's start() method
     */
    public function load() {
        // Register the notification group (this appears as a section in settings)
        $this->register_notification_group(
            'mld_property_alerts',                              // Group key
            __('Property Alerts', 'mld'),                       // Frontend label
            __('Property Alert Notifications', 'mld'),          // Admin label
            5                                                    // Position in settings
        );

        // Register individual notification types
        $this->register_property_alert_types();

        // Register notification filters for the notifications page
        $this->register_notification_filter(
            __('Property Alerts', 'mld'),
            array('instant_new_listing', 'instant_price_drop', 'instant_status_change', 'instant_back_on_market'),
            95
        );

        // Add email schema for each notification type
        $this->register_email_types();

        // Debug logging
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[MLD Modern API] Notification group and types registered in load() method');
        }
    }

    /**
     * Register individual notification types
     */
    private function register_property_alert_types() {
        // New Listing Alert
        $this->register_notification_type(
            'instant_new_listing',
            __('A new property matches your saved search', 'mld'),
            __('New property matches saved search', 'mld'),
            'mld_property_alerts'
        );

        // Register the actual notification
        $this->register_notification(
            'mld_saved_searches',
            'instant_new_listing',
            'instant_new_listing'
        );

        // Price Drop Alert
        $this->register_notification_type(
            'instant_price_drop',
            __('A property price has been reduced', 'mld'),
            __('Property price reduction', 'mld'),
            'mld_property_alerts'
        );

        $this->register_notification(
            'mld_saved_searches',
            'instant_price_drop',
            'instant_price_drop'
        );

        // Status Change Alert
        $this->register_notification_type(
            'instant_status_change',
            __('A property status has changed', 'mld'),
            __('Property status change', 'mld'),
            'mld_property_alerts'
        );

        $this->register_notification(
            'mld_saved_searches',
            'instant_status_change',
            'instant_status_change'
        );

        // Back on Market Alert
        $this->register_notification_type(
            'instant_back_on_market',
            __('A property is back on the market', 'mld'),
            __('Property back on market', 'mld'),
            'mld_property_alerts'
        );

        $this->register_notification(
            'mld_saved_searches',
            'instant_back_on_market',
            'instant_back_on_market'
        );
    }

    /**
     * Register email types for each notification
     */
    private function register_email_types() {
        // New Listing Email
        $this->register_email_type(
            'mld-instant-new-listing',
            array(
                /* translators: do not remove {} brackets or translate its contents. */
                'email_title'         => __('[{{{site.name}}}] New property matches your search', 'mld'),
                /* translators: do not remove {} brackets or translate its contents. */
                'email_content'       => __("A new property matching your saved search is now available:\n\n{{{property.address}}}\nPrice: {{{property.price}}}\n\nView property: {{{property.url}}}", 'mld'),
                /* translators: do not remove {} brackets or translate its contents. */
                'email_plain_content' => __("A new property matching your saved search is now available:\n\n{{{property.address}}}\nPrice: {{{property.price}}}\n\nView property: {{{property.url}}}", 'mld'),
                'situation_label'     => __('A new property matches a saved search', 'mld'),
                'unsubscribe_text'    => __('You will no longer receive property alert emails when new listings match your searches.', 'mld'),
            ),
            'instant_new_listing'
        );

        // Price Drop Email
        $this->register_email_type(
            'mld-instant-price-drop',
            array(
                /* translators: do not remove {} brackets or translate its contents. */
                'email_title'         => __('[{{{site.name}}}] Price reduced on a property', 'mld'),
                /* translators: do not remove {} brackets or translate its contents. */
                'email_content'       => __("The price has been reduced on a property from your saved search:\n\n{{{property.address}}}\nNew Price: {{{property.price}}}\n\nView property: {{{property.url}}}", 'mld'),
                /* translators: do not remove {} brackets or translate its contents. */
                'email_plain_content' => __("The price has been reduced on a property from your saved search:\n\n{{{property.address}}}\nNew Price: {{{property.price}}}\n\nView property: {{{property.url}}}", 'mld'),
                'situation_label'     => __('A property price is reduced', 'mld'),
                'unsubscribe_text'    => __('You will no longer receive price reduction alerts.', 'mld'),
            ),
            'instant_price_drop'
        );

        // Status Change Email
        $this->register_email_type(
            'mld-instant-status-change',
            array(
                /* translators: do not remove {} brackets or translate its contents. */
                'email_title'         => __('[{{{site.name}}}] Property status changed', 'mld'),
                /* translators: do not remove {} brackets or translate its contents. */
                'email_content'       => __("The status has changed for a property from your saved search:\n\n{{{property.address}}}\n\nView property: {{{property.url}}}", 'mld'),
                /* translators: do not remove {} brackets or translate its contents. */
                'email_plain_content' => __("The status has changed for a property from your saved search:\n\n{{{property.address}}}\n\nView property: {{{property.url}}}", 'mld'),
                'situation_label'     => __('A property status changes', 'mld'),
                'unsubscribe_text'    => __('You will no longer receive status change alerts.', 'mld'),
            ),
            'instant_status_change'
        );

        // Back on Market Email
        $this->register_email_type(
            'mld-instant-back-on-market',
            array(
                /* translators: do not remove {} brackets or translate its contents. */
                'email_title'         => __('[{{{site.name}}}] Property back on market', 'mld'),
                /* translators: do not remove {} brackets or translate its contents. */
                'email_content'       => __("A property from your saved search is back on the market:\n\n{{{property.address}}}\nPrice: {{{property.price}}}\n\nView property: {{{property.url}}}", 'mld'),
                /* translators: do not remove {} brackets or translate its contents. */
                'email_plain_content' => __("A property from your saved search is back on the market:\n\n{{{property.address}}}\nPrice: {{{property.price}}}\n\nView property: {{{property.url}}}", 'mld'),
                'situation_label'     => __('A property returns to market', 'mld'),
                'unsubscribe_text'    => __('You will no longer receive back on market alerts.', 'mld'),
            ),
            'instant_back_on_market'
        );
    }

    /**
     * Format notifications for display
     *
     * @param string $content               Notification content.
     * @param int    $item_id              The primary item ID (listing ID)
     * @param int    $secondary_item_id    The secondary item ID (saved search ID)
     * @param int    $total_items          Total number of notifications
     * @param string $component_action_name The notification action
     * @param string $component_name       The component name
     * @param int    $notification_id      The notification ID
     * @param string $screen              Screen type (web, web_push, app_push)
     *
     * @return array|string Formatted notification
     */
    public function format_notification($content, $item_id, $secondary_item_id, $total_items,
                                       $component_action_name, $component_name, $notification_id, $screen) {

        // Only handle our component
        if ($component_name !== 'mld_saved_searches') {
            return $content;
        }

        // Get notification metadata
        $property_address = bp_notifications_get_meta($notification_id, 'property_address');
        $property_price = bp_notifications_get_meta($notification_id, 'property_price');
        $property_url = bp_notifications_get_meta($notification_id, 'property_url');
        $search_name = bp_notifications_get_meta($notification_id, 'search_name');
        $property_beds = bp_notifications_get_meta($notification_id, 'property_beds');
        $property_baths = bp_notifications_get_meta($notification_id, 'property_baths');
        $property_sqft = bp_notifications_get_meta($notification_id, 'property_sqft');

        // Format property details
        $property_details = $this->format_property_details($property_beds, $property_baths, $property_sqft);

        // Build notification text based on action and screen type
        $text = '';
        $link = $property_url ?: home_url();

        switch ($component_action_name) {
            case 'instant_new_listing':
                if ($screen === 'web') {
                    $text = sprintf(
                        __('New listing in %s: %s - $%s%s', 'mld'),
                        $search_name,
                        $property_address,
                        number_format($property_price),
                        $property_details ? ' - ' . $property_details : ''
                    );
                } else {
                    // Push notification (shorter)
                    $text = sprintf(
                        __('New: %s - $%s', 'mld'),
                        $property_address,
                        number_format($property_price)
                    );
                }
                break;

            case 'instant_price_drop':
                if ($screen === 'web') {
                    $text = sprintf(
                        __('Price drop! %s: Now $%s%s', 'mld'),
                        $property_address,
                        number_format($property_price),
                        $property_details ? ' - ' . $property_details : ''
                    );
                } else {
                    $text = sprintf(
                        __('Price Drop: %s - $%s', 'mld'),
                        $property_address,
                        number_format($property_price)
                    );
                }
                break;

            case 'instant_status_change':
                $text = sprintf(
                    __('Status change: %s', 'mld'),
                    $property_address
                );
                break;

            case 'instant_back_on_market':
                $text = sprintf(
                    __('Back on market: %s - $%s', 'mld'),
                    $property_address,
                    number_format($property_price)
                );
                break;

            default:
                $text = sprintf(
                    __('Property update for "%s"', 'mld'),
                    $search_name
                );
        }

        // Return formatted based on screen type
        return array(
            'text' => $text,
            'link' => $link
        );
    }

    /**
     * Format property details
     *
     * @param int $beds  Number of bedrooms
     * @param int $baths Number of bathrooms
     * @param int $sqft  Square footage
     *
     * @return string Formatted details
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
}