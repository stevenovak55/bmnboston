<?php
/**
 * MLD Import Notification Bridge
 *
 * Ensures proper data format and completeness when Bridge MLS Extractor
 * triggers the bme_listing_imported hook during actual imports.
 *
 * @package MLS_Listings_Display
 * @subpackage Instant_Notifications
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Import_Notification_Bridge {

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
        // Hook with higher priority to intercept and enhance data before instant matcher
        add_action('bme_listing_imported', [$this, 'enhance_import_data'], 5, 3);

        // Add debug logging
        add_action('bme_listing_imported', [$this, 'debug_import_hook'], 1, 3);
    }

    /**
     * Debug logging for import hook
     */
    public function debug_import_hook($listing_id, $listing_data, $metadata) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[MLD Import Bridge] Hook fired for listing: ' . $listing_id);
        }
    }

    /**
     * Enhance import data before it reaches the instant matcher
     */
    public function enhance_import_data($listing_id, $listing_data, $metadata) {
        global $wpdb;

        // Skip if already processed
        if (isset($metadata['enhanced'])) {
            return;
        }

        // Fetch complete listing data from database
        $db_listing = $wpdb->get_row($wpdb->prepare(
            "SELECT l.*,
                    ld.lot_size_acres,
                    ld.year_built,
                    ld.bedrooms_total,
                    ld.bathrooms_total_integer as bathrooms_total,
                    ld.living_area,
                    ll.city,
                    ll.state_or_province,
                    ll.postal_code,
                    ll.latitude,
                    ll.longitude,
                    ll.subdivision_name
             FROM {$wpdb->prefix}bme_listings l
             LEFT JOIN {$wpdb->prefix}bme_listing_details ld ON l.listing_id = ld.listing_id
             LEFT JOIN {$wpdb->prefix}bme_listing_location ll ON l.listing_id = ll.listing_id
             WHERE l.listing_id = %s",
            $listing_id
        ), ARRAY_A);

        if (!$db_listing) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[MLD Import Bridge] Warning: Could not find listing in database: ' . $listing_id);
            }
            return;
        }

        // Create enhanced listing data with both formats for compatibility
        $enhanced_data = array_merge($db_listing, (array)$listing_data);

        // Ensure all required fields are present in both formats
        $field_mappings = [
            'property_type' => 'PropertyType',
            'property_sub_type' => 'PropertySubType',
            'standard_status' => 'StandardStatus',
            'list_price' => 'ListPrice',
            'bedrooms_total' => 'BedroomsTotal',
            'bathrooms_total' => 'BathroomsTotalInteger',
            'living_area' => 'LivingArea',
            'city' => 'City',
            'state_or_province' => 'StateOrProvince',
            'postal_code' => 'PostalCode',
            'latitude' => 'Latitude',
            'longitude' => 'Longitude',
            'street_number' => 'StreetNumber',
            'street_name' => 'StreetName',
            'subdivision_name' => 'Subdivision'
        ];

        foreach ($field_mappings as $snake_case => $camel_case) {
            // Ensure both formats exist
            if (isset($enhanced_data[$snake_case]) && !isset($enhanced_data[$camel_case])) {
                $enhanced_data[$camel_case] = $enhanced_data[$snake_case];
            } elseif (isset($enhanced_data[$camel_case]) && !isset($enhanced_data[$snake_case])) {
                $enhanced_data[$snake_case] = $enhanced_data[$camel_case];
            }
        }

        // Set proper defaults for critical fields
        $enhanced_data['StandardStatus'] = $enhanced_data['StandardStatus'] ?? $enhanced_data['standard_status'] ?? 'Active';
        $enhanced_data['standard_status'] = $enhanced_data['StandardStatus'];

        $enhanced_data['PropertyType'] = $enhanced_data['PropertyType'] ?? $enhanced_data['property_type'] ?? 'Residential';
        $enhanced_data['property_type'] = strtolower($enhanced_data['PropertyType']);

        // Ensure numeric fields are properly typed
        $enhanced_data['ListPrice'] = floatval($enhanced_data['ListPrice'] ?? $enhanced_data['list_price'] ?? 0);
        $enhanced_data['list_price'] = $enhanced_data['ListPrice'];

        $enhanced_data['BedroomsTotal'] = intval($enhanced_data['BedroomsTotal'] ?? $enhanced_data['bedrooms_total'] ?? 0);
        $enhanced_data['bedrooms_total'] = $enhanced_data['BedroomsTotal'];

        $enhanced_data['BathroomsTotalInteger'] = intval($enhanced_data['BathroomsTotalInteger'] ?? $enhanced_data['bathrooms_total'] ?? 0);
        $enhanced_data['bathrooms_total'] = $enhanced_data['BathroomsTotalInteger'];

        $enhanced_data['LivingArea'] = floatval($enhanced_data['LivingArea'] ?? $enhanced_data['living_area'] ?? 0);
        $enhanced_data['living_area'] = $enhanced_data['LivingArea'];

        // Ensure coordinates are included
        $enhanced_data['Latitude'] = floatval($enhanced_data['Latitude'] ?? $enhanced_data['latitude'] ?? 0);
        $enhanced_data['latitude'] = $enhanced_data['Latitude'];

        $enhanced_data['Longitude'] = floatval($enhanced_data['Longitude'] ?? $enhanced_data['longitude'] ?? 0);
        $enhanced_data['longitude'] = $enhanced_data['Longitude'];

        // Mark as enhanced
        $metadata['enhanced'] = true;

        // Log enhanced data for debugging
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[MLD Import Bridge] Enhanced data for ' . $listing_id . ':');
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[MLD Import Bridge] - Status: ' . $enhanced_data['StandardStatus']);
            }
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[MLD Import Bridge] - Type: ' . $enhanced_data['PropertyType']);
            }
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[MLD Import Bridge] - City: ' . ($enhanced_data['City'] ?? 'N/A'));
            }
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[MLD Import Bridge] - Price: $' . number_format($enhanced_data['ListPrice']));
            }
        }

        // Get the instant matcher instance if it exists
        global $mld_instant_matcher_instance;

        if (!isset($mld_instant_matcher_instance)) {
            // Try to find it through the notification init system
            $init = class_exists('MLD_Instant_Notifications_Init') ? MLD_Instant_Notifications_Init::get_instance() : null;
            if ($init && method_exists($init, 'get_component')) {
                $mld_instant_matcher_instance = $init->get_component('matcher');
            }
        }

        // Call the matcher directly with enhanced data
        // This ensures the matcher sees the complete data including status from BME metadata
        if ($mld_instant_matcher_instance && method_exists($mld_instant_matcher_instance, 'handle_new_listing')) {
            $mld_instant_matcher_instance->handle_new_listing($listing_id, $enhanced_data, $metadata);
        }

        // Remove the matcher from priority 10 to prevent duplicate processing when do_action continues
        if ($mld_instant_matcher_instance) {
            remove_action('bme_listing_imported', [$mld_instant_matcher_instance, 'handle_new_listing'], 10);
        }

        // Also check if we should trigger instant notifications immediately
        $this->check_instant_notifications($listing_id, $enhanced_data, $metadata);
    }

    /**
     * Additional check to ensure notifications are triggered
     */
    private function check_instant_notifications($listing_id, $listing_data, $metadata) {
        global $wpdb;

        // Only process active listings
        if ($listing_data['standard_status'] !== 'Active') {
            return;
        }

        // Check if this listing has already triggered notifications recently
        // Use WordPress timezone-aware time instead of MySQL NOW()
        $wp_now = current_time('mysql');
        $recent_match = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}mld_search_activity_matches
             WHERE listing_id = %s
             AND created_at >= DATE_SUB(%s, INTERVAL 1 HOUR)",
            $listing_id, $wp_now
        ));

        if ($recent_match > 0) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[MLD Import Bridge] Skipping duplicate notification for ' . $listing_id);
            }
            return;
        }

        // Get all active instant searches
        $instant_searches = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}mld_saved_searches
             WHERE notification_frequency = 'instant'
             AND is_active = 1"
        );

        if (empty($instant_searches)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[MLD Import Bridge] No active instant searches found');
            }
            return;
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[MLD Import Bridge] Checking ' . count($instant_searches) . ' instant searches for listing ' . $listing_id);
        }

        // Force a match check for each search
        foreach ($instant_searches as $search) {
            $filters = json_decode($search->filters, true);
            if (empty($filters)) {
                continue;
            }

            // Log filter criteria for debugging
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[MLD Import Bridge] Checking search ' . $search->id . ' (' . $search->name . ')');
                if (isset($filters['selected_cities'])) {
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log('[MLD Import Bridge] - Cities: ' . implode(', ', $filters['selected_cities']));
                    }
                }
                if (isset($filters['price_min']) || isset($filters['price_max'])) {
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log('[MLD Import Bridge] - Price: $' . ($filters['price_min'] ?? 0) . ' - $' . ($filters['price_max'] ?? 'unlimited'));
                    }
                }
            }
        }
    }
}

// Initialize the bridge
MLD_Import_Notification_Bridge::get_instance();