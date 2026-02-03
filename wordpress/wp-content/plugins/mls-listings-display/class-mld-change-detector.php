<?php
/**
 * MLS Listings Display - Change Detector
 *
 * Detects new listings, price changes, and status changes using the
 * wp_bme_property_history table maintained by Bridge MLS Extractor Pro.
 *
 * @package MLS_Listings_Display
 * @subpackage Saved_Searches
 * @since 6.13.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Change_Detector {

    /**
     * Change type constants
     */
    const CHANGE_TYPE_NEW = 'new_listing';
    const CHANGE_TYPE_PRICE = 'price_change';
    const CHANGE_TYPE_STATUS = 'status_change';

    /**
     * Get all listings with changes in the specified time window
     *
     * Queries the wp_bme_property_history table for events within the time window
     * and enriches with full listing data from the summary table.
     *
     * @param int $minutes Time window in minutes (default 15)
     * @return array Array of listings with change data, keyed by listing_id
     */
    public static function get_recent_changes($minutes = 15) {
        global $wpdb;

        // Use wp_date() for WordPress timezone consistency
        $cutoff = wp_date('Y-m-d H:i:s', current_time('timestamp') - ($minutes * 60));
        $history_table = $wpdb->prefix . 'bme_property_history';
        $summary_table = $wpdb->prefix . 'bme_listing_summary';

        // Check if tables exist
        $history_exists = $wpdb->get_var("SHOW TABLES LIKE '{$history_table}'");
        $summary_exists = $wpdb->get_var("SHOW TABLES LIKE '{$summary_table}'");

        if (!$history_exists) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD Change Detector: wp_bme_property_history table not found');
            }
            return [];
        }

        if (!$summary_exists) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD Change Detector: wp_bme_listing_summary table not found');
            }
            return [];
        }

        // Query property history for recent events
        // Use created_at (when we DETECTED the change) not event_date (when it happened in MLS)
        // Note: For new_listing events, we filter to only Active/Coming Soon statuses
        // This prevents false alerts after database cleanup (Pending/Closed listings re-inserted)
        $changes = $wpdb->get_results($wpdb->prepare("
            SELECT
                h.listing_id,
                h.event_type,
                h.event_date,
                h.created_at as change_detected_at,
                h.old_price,
                h.new_price,
                h.old_status,
                h.new_status,
                s.listing_key,
                s.standard_status,
                s.list_price,
                s.street_number,
                s.street_name,
                s.city,
                s.state_or_province,
                s.postal_code,
                s.bedrooms_total,
                s.bathrooms_total,
                s.building_area_total,
                s.property_type,
                s.property_sub_type,
                s.latitude,
                s.longitude,
                s.modification_timestamp,
                s.main_photo_url,
                s.lot_size_acres,
                s.year_built,
                s.garage_spaces,
                s.has_pool,
                s.has_fireplace,
                s.has_hoa
            FROM {$history_table} h
            INNER JOIN {$summary_table} s ON h.listing_id = s.listing_id
            WHERE h.created_at >= %s
              AND h.event_type IN ('new_listing', 'price_change', 'sold', 'pending')
              AND NOT (h.event_type = 'new_listing' AND s.standard_status NOT IN ('Active', 'Coming Soon'))
            ORDER BY h.created_at DESC
            LIMIT 500
        ", $cutoff), ARRAY_A);

        if ($wpdb->last_error) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD Change Detector SQL Error: ' . $wpdb->last_error);
            }
            return [];
        }

        // Categorize changes and deduplicate by listing_id (keep first/most recent)
        $result = [];
        foreach ($changes as $change) {
            $listing_id = $change['listing_id'];

            // Skip if we already have this listing (first entry is most recent)
            if (isset($result[$listing_id])) {
                continue;
            }

            // Determine change type
            $change_type = self::map_event_type($change['event_type']);

            // Build result entry
            // v6.68.15: Include change_detected_at for search creation date filtering
            $result[$listing_id] = [
                'listing_id' => $listing_id,
                'change_type' => $change_type,
                'event_date' => $change['event_date'],
                'change_detected_at' => $change['change_detected_at'],
                'old_price' => $change['old_price'],
                'new_price' => $change['new_price'],
                'old_status' => $change['old_status'],
                'new_status' => $change['new_status'],
                'price_diff' => null,
                'price_diff_pct' => null,
                'listing_data' => [
                    'listing_id' => $listing_id,
                    'listing_key' => $change['listing_key'],
                    'standard_status' => $change['standard_status'],
                    'list_price' => $change['list_price'],
                    'street_number' => $change['street_number'],
                    'street_name' => $change['street_name'],
                    // Build street_address for email builder compatibility
                    'street_address' => trim(($change['street_number'] ?? '') . ' ' . ($change['street_name'] ?? '')),
                    'city' => $change['city'],
                    'state_or_province' => $change['state_or_province'],
                    'postal_code' => $change['postal_code'],
                    'bedrooms_total' => $change['bedrooms_total'],
                    'bathrooms_total' => $change['bathrooms_total'],
                    'building_area_total' => $change['building_area_total'],
                    'property_type' => $change['property_type'],
                    'property_sub_type' => $change['property_sub_type'],
                    'latitude' => $change['latitude'],
                    'longitude' => $change['longitude'],
                    'modification_timestamp' => $change['modification_timestamp'],
                    'main_photo_url' => $change['main_photo_url'],
                    // Add photo_url alias for email builder compatibility
                    'photo_url' => $change['main_photo_url'],
                    'lot_size_acres' => $change['lot_size_acres'],
                    'year_built' => $change['year_built'],
                    'garage_spaces' => $change['garage_spaces'],
                    'has_pool' => $change['has_pool'],
                    'has_fireplace' => $change['has_fireplace'],
                    'has_hoa' => $change['has_hoa']
                ]
            ];

            // Calculate price difference if it's a price change
            if ($change_type === self::CHANGE_TYPE_PRICE && $change['old_price'] && $change['new_price']) {
                $result[$listing_id]['price_diff'] = floatval($change['new_price']) - floatval($change['old_price']);
                if (floatval($change['old_price']) > 0) {
                    $result[$listing_id]['price_diff_pct'] = round(
                        ($result[$listing_id]['price_diff'] / floatval($change['old_price'])) * 100,
                        1
                    );
                }
            }
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                'MLD Change Detector: Found %d changes in last %d minutes',
                count($result),
                $minutes
            ));
        }

        return $result;
    }

    /**
     * Map event_type from property_history to our change type constants
     *
     * @param string $event_type Event type from property_history table
     * @return string Our change type constant
     */
    private static function map_event_type($event_type) {
        switch ($event_type) {
            case 'new_listing':
                return self::CHANGE_TYPE_NEW;
            case 'price_change':
                return self::CHANGE_TYPE_PRICE;
            case 'sold':
            case 'pending':
            case 'showing_update':
                return self::CHANGE_TYPE_STATUS;
            default:
                return self::CHANGE_TYPE_NEW;
        }
    }

    /**
     * Group changes by type for email sections
     *
     * @param array $changes Array of changes from get_recent_changes()
     * @return array Grouped changes: ['new' => [...], 'price' => [...], 'status' => [...]]
     */
    public static function group_by_type($changes) {
        $grouped = [
            'new' => [],
            'price' => [],
            'status' => []
        ];

        foreach ($changes as $listing_id => $change) {
            switch ($change['change_type']) {
                case self::CHANGE_TYPE_NEW:
                    $grouped['new'][$listing_id] = $change;
                    break;
                case self::CHANGE_TYPE_PRICE:
                    $grouped['price'][$listing_id] = $change;
                    break;
                case self::CHANGE_TYPE_STATUS:
                    $grouped['status'][$listing_id] = $change;
                    break;
            }
        }

        return $grouped;
    }

    /**
     * Get statistics about recent changes
     *
     * @param int $minutes Time window in minutes
     * @return array Statistics array
     */
    public static function get_change_stats($minutes = 15) {
        global $wpdb;

        // Use WordPress timezone-aware date calculation
        $cutoff = wp_date('Y-m-d H:i:s', current_time('timestamp') - ($minutes * 60));
        $history_table = $wpdb->prefix . 'bme_property_history';

        $stats = $wpdb->get_results($wpdb->prepare("
            SELECT event_type, COUNT(*) as count
            FROM {$history_table}
            WHERE event_date >= %s
              AND event_type IN ('new_listing', 'price_change', 'sold', 'pending')
            GROUP BY event_type
        ", $cutoff), ARRAY_A);

        $result = [
            'new_listing' => 0,
            'price_change' => 0,
            'status_change' => 0,
            'total' => 0
        ];

        foreach ($stats as $stat) {
            $count = intval($stat['count']);
            $result['total'] += $count;

            switch ($stat['event_type']) {
                case 'new_listing':
                    $result['new_listing'] = $count;
                    break;
                case 'price_change':
                    $result['price_change'] = $count;
                    break;
                case 'sold':
                case 'pending':
                    $result['status_change'] += $count;
                    break;
            }
        }

        return $result;
    }
}
