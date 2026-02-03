<?php
/**
 * MLD Summary Table Sync Diagnostic & Self-Healing
 *
 * Diagnoses why listings may be missing from the summary table and provides
 * a self-healing mechanism to fix sync issues.
 *
 * @package MLS_Listings_Display
 * @since 6.11.11
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Summary_Sync_Diagnostic {

    /**
     * Singleton instance
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
        // Add admin hooks
        add_action('admin_init', [$this, 'maybe_auto_heal'], 30);
        add_action('wp_ajax_mld_diagnose_summary_sync', [$this, 'ajax_diagnose']);
        add_action('wp_ajax_mld_heal_summary_sync', [$this, 'ajax_heal']);
    }

    /**
     * Auto-heal summary sync issues (runs once per hour max)
     */
    public function maybe_auto_heal() {
        // Only run once per hour
        $last_heal = get_transient('mld_summary_auto_heal');
        if ($last_heal) {
            return;
        }

        // Check if out of sync
        $sync_status = $this->get_sync_status();
        if ($sync_status['is_synced']) {
            return;
        }

        // If more than 5 listings out of sync, try to heal
        if ($sync_status['difference'] > 5) {
            $this->heal_summary_sync();
            set_transient('mld_summary_auto_heal', time(), HOUR_IN_SECONDS);

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf(
                    'MLD Summary Sync: Auto-healed %d missing listings',
                    $sync_status['difference']
                ));
            }
        }
    }

    /**
     * Get current sync status
     */
    public function get_sync_status() {
        global $wpdb;

        $listings_table = $wpdb->prefix . 'bme_listings';
        $summary_table = $wpdb->prefix . 'bme_listing_summary';

        // Check if tables exist
        $listings_exists = $wpdb->get_var("SHOW TABLES LIKE '{$listings_table}'") === $listings_table;
        $summary_exists = $wpdb->get_var("SHOW TABLES LIKE '{$summary_table}'") === $summary_table;

        if (!$listings_exists || !$summary_exists) {
            return [
                'is_synced' => false,
                'active_listings' => 0,
                'summary_count' => 0,
                'difference' => 0,
                'error' => 'Required tables do not exist'
            ];
        }

        $active_count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$listings_table} WHERE standard_status = 'Active'"
        );

        $summary_count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$summary_table} WHERE standard_status = 'Active'"
        );

        return [
            'is_synced' => $active_count === $summary_count,
            'active_listings' => $active_count,
            'summary_count' => $summary_count,
            'difference' => abs($active_count - $summary_count),
            'error' => null
        ];
    }

    /**
     * Get detailed diagnostic information
     */
    public function get_diagnostic_info() {
        global $wpdb;

        $listings_table = $wpdb->prefix . 'bme_listings';
        $summary_table = $wpdb->prefix . 'bme_listing_summary';
        $location_table = $wpdb->prefix . 'bme_listing_location';
        $details_table = $wpdb->prefix . 'bme_listing_details';

        $diagnostic = [
            'sync_status' => $this->get_sync_status(),
            'missing_listings' => [],
            'orphaned_summary' => [],
            'missing_location' => [],
            'missing_details' => [],
            'potential_causes' => []
        ];

        // Find listings that are Active but NOT in summary table
        $missing = $wpdb->get_results("
            SELECT l.listing_id, l.listing_key, l.standard_status, l.mls_status,
                   l.modification_timestamp, l.property_type, l.list_price
            FROM {$listings_table} l
            LEFT JOIN {$summary_table} s ON l.listing_id = s.listing_id
            WHERE l.standard_status = 'Active'
            AND s.listing_id IS NULL
            ORDER BY l.modification_timestamp DESC
            LIMIT 50
        ");

        $diagnostic['missing_listings'] = $missing;

        // Find summary entries that don't have a corresponding active listing
        $orphaned = $wpdb->get_results("
            SELECT s.listing_id, s.listing_key, s.standard_status
            FROM {$summary_table} s
            LEFT JOIN {$listings_table} l ON s.listing_id = l.listing_id AND l.standard_status = 'Active'
            WHERE s.standard_status = 'Active'
            AND l.listing_id IS NULL
            LIMIT 50
        ");

        $diagnostic['orphaned_summary'] = $orphaned;

        // Check for missing location data
        if (!empty($missing)) {
            $missing_ids = array_map(function($l) { return $l->listing_id; }, $missing);
            $ids_str = implode(',', array_map('intval', $missing_ids));

            $no_location = $wpdb->get_results("
                SELECT l.listing_id, l.listing_key
                FROM {$listings_table} l
                LEFT JOIN {$location_table} loc ON l.listing_id = loc.listing_id
                WHERE l.listing_id IN ({$ids_str})
                AND loc.listing_id IS NULL
            ");

            $diagnostic['missing_location'] = $no_location;

            // Check for missing details data
            $no_details = $wpdb->get_results("
                SELECT l.listing_id, l.listing_key
                FROM {$listings_table} l
                LEFT JOIN {$details_table} d ON l.listing_id = d.listing_id
                WHERE l.listing_id IN ({$ids_str})
                AND d.listing_id IS NULL
            ");

            $diagnostic['missing_details'] = $no_details;
        }

        // Analyze potential causes
        if (!empty($missing)) {
            if (!empty($diagnostic['missing_location'])) {
                $diagnostic['potential_causes'][] = sprintf(
                    '%d listings are missing location data (lat/lng, address)',
                    count($diagnostic['missing_location'])
                );
            }

            if (!empty($diagnostic['missing_details'])) {
                $diagnostic['potential_causes'][] = sprintf(
                    '%d listings are missing details data (beds, baths, sqft)',
                    count($diagnostic['missing_details'])
                );
            }

            // Check for recently added listings
            $recent = array_filter($missing, function($l) {
                return strtotime($l->modification_timestamp) > strtotime('-1 hour');
            });

            if (!empty($recent)) {
                $diagnostic['potential_causes'][] = sprintf(
                    '%d listings were recently added (within last hour) - may not have synced yet',
                    count($recent)
                );
            }
        }

        if (!empty($orphaned)) {
            $diagnostic['potential_causes'][] = sprintf(
                '%d summary entries are orphaned (listing no longer Active or deleted)',
                count($orphaned)
            );
        }

        return $diagnostic;
    }

    /**
     * Heal summary sync issues by inserting missing listings
     */
    public function heal_summary_sync() {
        global $wpdb;

        $listings_table = $wpdb->prefix . 'bme_listings';
        $summary_table = $wpdb->prefix . 'bme_listing_summary';
        $location_table = $wpdb->prefix . 'bme_listing_location';
        $details_table = $wpdb->prefix . 'bme_listing_details';
        $media_table = $wpdb->prefix . 'bme_media';

        $results = [
            'inserted' => 0,
            'updated' => 0,
            'deleted_orphans' => 0,
            'errors' => []
        ];

        // First, try the BME refresh hook
        if (has_action('bme_refresh_summary_hook')) {
            do_action('bme_refresh_summary_hook');

            // Check if that fixed it
            $sync_status = $this->get_sync_status();
            if ($sync_status['is_synced']) {
                $results['message'] = 'BME refresh hook successfully synced all listings';
                return $results;
            }
        }

        // If BME hook didn't fully fix it, manually insert missing listings
        $missing = $wpdb->get_results("
            SELECT l.listing_id
            FROM {$listings_table} l
            LEFT JOIN {$summary_table} s ON l.listing_id = s.listing_id
            WHERE l.standard_status = 'Active'
            AND s.listing_id IS NULL
            LIMIT 100
        ");

        foreach ($missing as $listing) {
            $listing_id = $listing->listing_id;

            // Get full listing data
            $listing_data = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$listings_table} WHERE listing_id = %d",
                $listing_id
            ));

            if (!$listing_data) {
                continue;
            }

            // Get location data
            $location = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$location_table} WHERE listing_id = %d",
                $listing_id
            ));

            // Get details data
            $details = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$details_table} WHERE listing_id = %d",
                $listing_id
            ));

            // Get main photo
            $main_photo = $wpdb->get_var($wpdb->prepare(
                "SELECT media_url FROM {$media_table}
                 WHERE listing_id = %d AND media_type = 'Photo'
                 ORDER BY display_order ASC LIMIT 1",
                $listing_id
            ));

            // Build summary record
            $summary_data = [
                'listing_id' => $listing_id,
                'listing_key' => $listing_data->listing_key,
                'mls_id' => $listing_data->listing_id,
                'property_type' => $listing_data->property_type,
                'property_sub_type' => $listing_data->property_sub_type ?? null,
                'standard_status' => $listing_data->standard_status,
                'list_price' => $listing_data->list_price,
                'original_list_price' => $listing_data->original_list_price ?? null,
                'close_price' => $listing_data->close_price ?? null,
                'days_on_market' => $listing_data->days_on_market ?? null,
                'modification_timestamp' => $listing_data->modification_timestamp,
            ];

            // Add location data if available
            if ($location) {
                $summary_data['street_number'] = $location->street_number ?? null;
                $summary_data['street_name'] = $location->street_name ?? null;
                $summary_data['unit_number'] = $location->unit_number ?? null;
                $summary_data['city'] = $location->city ?? null;
                $summary_data['state_or_province'] = $location->state_or_province ?? null;
                $summary_data['postal_code'] = $location->postal_code ?? null;
                $summary_data['county'] = $location->county_or_parish ?? null;
                $summary_data['latitude'] = $location->latitude ?? null;
                $summary_data['longitude'] = $location->longitude ?? null;
            }

            // Add details data if available
            if ($details) {
                $summary_data['bedrooms_total'] = $details->bedrooms_total ?? null;
                $summary_data['bathrooms_total'] = $details->bathrooms_total_integer ?? null;
                $summary_data['bathrooms_full'] = $details->bathrooms_full ?? null;
                $summary_data['bathrooms_half'] = $details->bathrooms_half ?? null;
                $summary_data['building_area_total'] = $details->living_area ?? null;
                $summary_data['lot_size_acres'] = $details->lot_size_acres ?? null;
                $summary_data['year_built'] = $details->year_built ?? null;
            }

            // Add photo
            if ($main_photo) {
                $summary_data['main_photo_url'] = $main_photo;
            }

            // Insert into summary table
            $inserted = $wpdb->replace($summary_table, $summary_data);

            if ($inserted) {
                $results['inserted']++;
            } else {
                $results['errors'][] = "Failed to insert listing {$listing_id}: " . $wpdb->last_error;
            }
        }

        // Clean up orphaned summary entries
        $deleted = $wpdb->query("
            DELETE s FROM {$summary_table} s
            LEFT JOIN {$listings_table} l ON s.listing_id = l.listing_id AND l.standard_status = 'Active'
            WHERE s.standard_status = 'Active'
            AND l.listing_id IS NULL
        ");

        $results['deleted_orphans'] = $deleted ?: 0;

        // Log results
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                'MLD Summary Sync Heal: Inserted %d, Deleted orphans %d, Errors: %d',
                $results['inserted'],
                $results['deleted_orphans'],
                count($results['errors'])
            ));

            if (!empty($results['errors'])) {
                foreach ($results['errors'] as $error) {
                    error_log('MLD Summary Sync Error: ' . $error);
                }
            }
        }

        return $results;
    }

    /**
     * AJAX handler for diagnostics
     */
    public function ajax_diagnose() {
        check_ajax_referer('mld_health_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }

        $diagnostic = $this->get_diagnostic_info();

        // Format for display
        $output = [
            'sync_status' => $diagnostic['sync_status'],
            'missing_count' => count($diagnostic['missing_listings']),
            'orphaned_count' => count($diagnostic['orphaned_summary']),
            'missing_location_count' => count($diagnostic['missing_location']),
            'missing_details_count' => count($diagnostic['missing_details']),
            'potential_causes' => $diagnostic['potential_causes'],
            'missing_listings' => array_map(function($l) {
                return [
                    'listing_id' => $l->listing_id,
                    'listing_key' => $l->listing_key,
                    'list_price' => $l->list_price,
                    'property_type' => $l->property_type,
                    'modified' => $l->modification_timestamp
                ];
            }, array_slice($diagnostic['missing_listings'], 0, 10))
        ];

        wp_send_json_success($output);
    }

    /**
     * AJAX handler for healing
     */
    public function ajax_heal() {
        check_ajax_referer('mld_health_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }

        $results = $this->heal_summary_sync();

        // Get new sync status
        $new_status = $this->get_sync_status();

        wp_send_json_success([
            'heal_results' => $results,
            'new_sync_status' => $new_status
        ]);
    }
}

// Initialize
add_action('plugins_loaded', function() {
    MLD_Summary_Sync_Diagnostic::get_instance();
});
