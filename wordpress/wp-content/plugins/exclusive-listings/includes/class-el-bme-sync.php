<?php
/**
 * BME Table Sync Service
 *
 * @package Exclusive_Listings
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class EL_BME_Sync
 *
 * Populates BME tables with exclusive listing data so they appear
 * in search results alongside MLS listings.
 *
 * Tables populated:
 * - wp_bme_listings (core data)
 * - wp_bme_listing_summary (denormalized for fast queries)
 * - wp_bme_listing_details (beds, baths, sqft)
 * - wp_bme_listing_location (coordinates, SPATIAL point)
 * - wp_bme_listing_features (amenities)
 * - wp_bme_media (photos)
 */
class EL_BME_Sync {

    /**
     * WordPress database object
     * @var wpdb
     */
    private $wpdb;

    /**
     * Database manager
     * @var EL_Database
     */
    private $database;

    /**
     * Geocoder
     * @var EL_Geocoder
     */
    private $geocoder;

    /**
     * Map exclusive listing property_sub_type to MLS-compatible values
     * iOS filters use MLS values like "Single Family Residence" not "Single Family"
     * @var array
     */
    const PROPERTY_SUB_TYPE_MAP = array(
        'Single Family'  => 'Single Family Residence',
        'Condo'          => 'Condominium',
        'Townhouse'      => 'Townhouse',
        'Multi-Family'   => 'Multi Family',
        'Land'           => 'Land',
        'Commercial'     => 'Commercial',
        'Apartment'      => 'Condominium',
        'Mobile Home'    => 'Mobile Home',
        'Farm'           => 'Farm',
        'Ranch'          => 'Farm',
        'Other'          => 'Other',
    );

    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->database = exclusive_listings()->get_database();
        $this->geocoder = new EL_Geocoder();
    }

    /**
     * Map exclusive listing property_sub_type to MLS-compatible value
     *
     * @since 1.0.1
     * @param string $sub_type The exclusive listing property sub-type
     * @return string MLS-compatible property sub-type
     */
    public static function map_property_sub_type($sub_type) {
        if (empty($sub_type)) {
            return 'Single Family Residence';
        }
        return isset(self::PROPERTY_SUB_TYPE_MAP[$sub_type])
            ? self::PROPERTY_SUB_TYPE_MAP[$sub_type]
            : $sub_type;
    }

    /**
     * Normalize bathroom fields - auto-calculate missing values
     *
     * If full/half provided: calculate total
     * If only total provided: decompose to full/half
     *
     * @since 1.5.0
     * @param array $data Listing data (passed by reference)
     */
    private function normalize_bathroom_fields(&$data) {
        $total = isset($data['bathrooms_total']) && $data['bathrooms_total'] !== '' ? floatval($data['bathrooms_total']) : null;
        $full = isset($data['bathrooms_full']) && $data['bathrooms_full'] !== '' ? intval($data['bathrooms_full']) : null;
        $half = isset($data['bathrooms_half']) && $data['bathrooms_half'] !== '' ? intval($data['bathrooms_half']) : null;

        // If full or half is provided, calculate total from them
        if ($full !== null || $half !== null) {
            $full = $full ?? 0;
            $half = $half ?? 0;
            $data['bathrooms_total'] = $full + ($half * 0.5);
            $data['bathrooms_full'] = $full;
            $data['bathrooms_half'] = $half;
        }
        // If only total is provided, decompose to full/half
        elseif ($total !== null) {
            $data['bathrooms_half'] = intval(($total - floor($total)) * 2); // 0.5 -> 1
            $data['bathrooms_full'] = intval(floor($total));
            $data['bathrooms_total'] = $total;
        }
    }

    /**
     * Normalize lot size fields - auto-convert between sq ft and acres
     *
     * 1 acre = 43,560 sq ft
     *
     * @since 1.5.0
     * @param array $data Listing data (passed by reference)
     */
    private function normalize_lot_size_fields(&$data) {
        $SQ_FT_PER_ACRE = 43560;
        $acres = isset($data['lot_size_acres']) && $data['lot_size_acres'] !== '' ? floatval($data['lot_size_acres']) : null;
        $sqft = isset($data['lot_size_square_feet']) && $data['lot_size_square_feet'] !== '' ? intval($data['lot_size_square_feet']) : null;

        // If sq ft is provided, calculate acres from it
        if ($sqft !== null && $sqft > 0) {
            $data['lot_size_square_feet'] = $sqft;
            $data['lot_size_acres'] = round($sqft / $SQ_FT_PER_ACRE, 4);
        }
        // If only acres is provided, calculate sq ft from it
        elseif ($acres !== null && $acres > 0) {
            $data['lot_size_acres'] = $acres;
            $data['lot_size_square_feet'] = round($acres * $SQ_FT_PER_ACRE);
        }
    }

    /**
     * Sync a listing to all BME tables
     *
     * @since 1.0.0
     * @since 1.4.0 Added sync to bme_listing_financial table
     * @param int $listing_id The exclusive listing ID
     * @param array $data Listing data (sanitized)
     * @param string $listing_key The listing key hash
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public function sync_listing($listing_id, $data, $listing_key) {
        // v1.5.0: Normalize bathroom and lot size fields before syncing
        $this->normalize_bathroom_fields($data);
        $this->normalize_lot_size_fields($data);

        // Start transaction
        $this->wpdb->query('START TRANSACTION');

        try {
            // 1. Sync to bme_listings
            $result = $this->sync_to_listings($listing_id, $data, $listing_key);
            if (is_wp_error($result)) {
                throw new Exception($result->get_error_message());
            }

            // 2. Sync to bme_listing_summary
            $result = $this->sync_to_summary($listing_id, $data, $listing_key);
            if (is_wp_error($result)) {
                throw new Exception($result->get_error_message());
            }

            // 3. Sync to bme_listing_details
            $result = $this->sync_to_details($listing_id, $data);
            if (is_wp_error($result)) {
                throw new Exception($result->get_error_message());
            }

            // 4. Sync to bme_listing_location
            $result = $this->sync_to_location($listing_id, $data);
            if (is_wp_error($result)) {
                throw new Exception($result->get_error_message());
            }

            // 5. Sync to bme_listing_features
            $result = $this->sync_to_features($listing_id, $data);
            if (is_wp_error($result)) {
                throw new Exception($result->get_error_message());
            }

            // 6. Sync to bme_listing_financial (v1.4.0)
            $result = $this->sync_to_financial($listing_id, $data);
            if (is_wp_error($result)) {
                throw new Exception($result->get_error_message());
            }

            // Commit transaction
            $this->wpdb->query('COMMIT');

            // Clear property detail cache so updates appear immediately
            $this->clear_property_cache($listing_id, $listing_key);

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("Exclusive Listings: Synced listing {$listing_id} to all BME tables");
            }

            return true;

        } catch (Exception $e) {
            // Rollback on error
            $this->wpdb->query('ROLLBACK');
            error_log("Exclusive Listings BME Sync Error: " . $e->getMessage());
            return new WP_Error('bme_sync_failed', $e->getMessage());
        }
    }

    /**
     * Sync listing to wp_bme_listings table
     *
     * @since 1.0.0
     * @since 1.4.0 Added original_list_price, private_remarks, showing_instructions, virtual_tour_url
     * @param int $listing_id Listing ID
     * @param array $data Listing data
     * @param string $listing_key Listing key hash
     * @return bool|WP_Error
     */
    private function sync_to_listings($listing_id, $data, $listing_key) {
        $table = $this->database->get_bme_table('listings');
        $now = current_time('mysql');

        // Check if exists
        $exists = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT listing_id FROM {$table} WHERE listing_id = %s",
            $listing_id
        ));

        $listing_data = array(
            'listing_id' => $listing_id,
            'listing_key' => $listing_key,
            'standard_status' => $data['standard_status'] ?? 'Active',
            'list_price' => $data['list_price'] ?? 0,
            'property_type' => $data['property_type'] ?? 'Residential',
            'property_sub_type' => self::map_property_sub_type($data['property_sub_type'] ?? null),
            'public_remarks' => $data['public_remarks'] ?? '',
            'modification_timestamp' => $now,
            'original_list_price' => $data['original_list_price'] ?? null,
            'private_remarks' => $data['private_remarks'] ?? null,
            'showing_instructions' => $data['showing_instructions'] ?? null,
            'virtual_tour_url_unbranded' => $data['virtual_tour_url'] ?? null,
        );

        if ($exists) {
            // Update existing
            $result = $this->wpdb->update(
                $table,
                $listing_data,
                array('listing_id' => $listing_id),
                null,
                array('%s')
            );
        } else {
            // Insert new
            $listing_data['original_entry_timestamp'] = $now;
            $result = $this->wpdb->insert($table, $listing_data);
        }

        if ($result === false) {
            return new WP_Error('listings_sync_failed', $this->wpdb->last_error);
        }

        return true;
    }

    /**
     * Sync listing to wp_bme_listing_summary table (denormalized)
     *
     * @since 1.0.0
     * @param int $listing_id Listing ID
     * @param array $data Listing data
     * @param string $listing_key Listing key hash
     * @return bool|WP_Error
     */
    private function sync_to_summary($listing_id, $data, $listing_key) {
        $table = $this->database->get_bme_table('summary');
        $now = current_time('mysql');

        // Build unparsed address
        $unparsed_address = $this->build_unparsed_address($data);

        // Check if exists
        $exists = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT listing_id FROM {$table} WHERE listing_id = %d",
            $listing_id
        ));

        $summary_data = array(
            'listing_id' => $listing_id,
            'listing_key' => $listing_key,
            'mls_id' => 'EXCL-' . $listing_id,
            'standard_status' => $data['standard_status'] ?? 'Active',
            'exclusive_tag' => !empty($data['exclusive_tag']) ? $data['exclusive_tag'] : null,
            'list_price' => $data['list_price'] ?? 0,
            'property_type' => $data['property_type'] ?? 'Residential',
            'property_sub_type' => self::map_property_sub_type($data['property_sub_type'] ?? null),
            'street_number' => $data['street_number'] ?? '',
            'street_name' => $data['street_name'] ?? '',
            'unit_number' => $data['unit_number'] ?? '',
            'city' => $data['city'] ?? '',
            'state_or_province' => $data['state_or_province'] ?? 'MA',
            'postal_code' => $data['postal_code'] ?? '',
            'county' => $data['county'] ?? '',
            'bedrooms_total' => isset($data['bedrooms_total']) ? intval($data['bedrooms_total']) : null,
            'bathrooms_total' => isset($data['bathrooms_total']) ? floatval($data['bathrooms_total']) : null,
            'bathrooms_full' => isset($data['bathrooms_full']) ? intval($data['bathrooms_full']) : null,
            'bathrooms_half' => isset($data['bathrooms_half']) ? intval($data['bathrooms_half']) : null,
            'building_area_total' => isset($data['building_area_total']) ? intval($data['building_area_total']) : null,
            'lot_size_acres' => isset($data['lot_size_acres']) ? floatval($data['lot_size_acres']) : null,
            'year_built' => isset($data['year_built']) ? intval($data['year_built']) : null,
            'garage_spaces' => isset($data['garage_spaces']) ? intval($data['garage_spaces']) : 0,
            'latitude' => isset($data['latitude']) ? floatval($data['latitude']) : null,
            'longitude' => isset($data['longitude']) ? floatval($data['longitude']) : null,
            'has_pool' => isset($data['has_pool']) ? intval($data['has_pool']) : 0,
            'has_fireplace' => isset($data['has_fireplace']) ? intval($data['has_fireplace']) : 0,
            'has_basement' => isset($data['has_basement']) ? intval($data['has_basement']) : 0,
            'has_hoa' => isset($data['has_hoa']) ? intval($data['has_hoa']) : 0,
            'main_photo_url' => $data['main_photo_url'] ?? null,
            'photo_count' => isset($data['photo_count']) ? intval($data['photo_count']) : 0,
            'listing_contract_date' => $data['listing_contract_date'] ?? current_time('Y-m-d'),
            'days_on_market' => 0,
            'modification_timestamp' => $now,
        );

        // Calculate days on market
        if (!empty($summary_data['listing_contract_date'])) {
            $contract_date = new DateTime($summary_data['listing_contract_date'], wp_timezone());
            $today = new DateTime('now', wp_timezone());
            $diff = $today->diff($contract_date);
            $summary_data['days_on_market'] = $diff->days;
        }

        // Calculate price per sqft
        if (!empty($summary_data['building_area_total']) && $summary_data['building_area_total'] > 0) {
            $summary_data['price_per_sqft'] = round($summary_data['list_price'] / $summary_data['building_area_total'], 2);
        }

        if ($exists) {
            $result = $this->wpdb->update(
                $table,
                $summary_data,
                array('listing_id' => $listing_id),
                null,
                array('%d')
            );
        } else {
            $result = $this->wpdb->insert($table, $summary_data);
        }

        if ($result === false) {
            return new WP_Error('summary_sync_failed', $this->wpdb->last_error);
        }

        return true;
    }

    /**
     * Sync listing to wp_bme_listing_details table
     *
     * @since 1.0.0
     * @since 1.4.0 Added heating, cooling, basement, roof, foundation, stories, architectural style, construction materials, flooring, laundry
     * @param int $listing_id Listing ID
     * @param array $data Listing data
     * @return bool|WP_Error
     */
    private function sync_to_details($listing_id, $data) {
        $table = $this->database->get_bme_table('details');

        // Check if exists
        $exists = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT listing_id FROM {$table} WHERE listing_id = %s",
            $listing_id
        ));

        $building_area = isset($data['building_area_total']) ? floatval($data['building_area_total']) : null;

        $details_data = array(
            'listing_id' => $listing_id,
            'bedrooms_total' => isset($data['bedrooms_total']) ? intval($data['bedrooms_total']) : null,
            'bathrooms_total_integer' => isset($data['bathrooms_total']) ? intval($data['bathrooms_total']) : null,
            'bathrooms_total_decimal' => isset($data['bathrooms_total']) ? floatval($data['bathrooms_total']) : null,
            'bathrooms_full' => isset($data['bathrooms_full']) ? intval($data['bathrooms_full']) : null,
            'bathrooms_half' => isset($data['bathrooms_half']) ? intval($data['bathrooms_half']) : null,
            'building_area_total' => $building_area,
            'living_area' => $building_area,
            'lot_size_acres' => isset($data['lot_size_acres']) ? floatval($data['lot_size_acres']) : null,
            'year_built' => isset($data['year_built']) ? intval($data['year_built']) : null,
            'garage_spaces' => isset($data['garage_spaces']) ? intval($data['garage_spaces']) : 0,
            'fireplace_yn' => isset($data['has_fireplace']) ? intval($data['has_fireplace']) : 0,
            'architectural_style' => $data['architectural_style'] ?? null,
            'stories_total' => isset($data['stories_total']) ? intval($data['stories_total']) : null,
            'heating' => $data['heating'] ?? null,
            'cooling' => $data['cooling'] ?? null,
            'heating_yn' => isset($data['heating_yn']) ? intval($data['heating_yn']) : 0,
            'cooling_yn' => isset($data['cooling_yn']) ? intval($data['cooling_yn']) : 0,
            'flooring' => $data['flooring'] ?? null,
            'laundry_features' => $data['laundry_features'] ?? null,
            'basement' => $data['basement'] ?? null,
            'interior_features' => $data['interior_features'] ?? null,
            'appliances' => $data['appliances'] ?? null,
            'construction_materials' => $data['construction_materials'] ?? null,
            'roof' => $data['roof'] ?? null,
            'foundation_details' => $data['foundation_details'] ?? null,
            'parking_features' => $data['parking_features'] ?? null,
            'parking_total' => isset($data['parking_total']) ? intval($data['parking_total']) : null,
        );

        if ($exists) {
            $result = $this->wpdb->update(
                $table,
                $details_data,
                array('listing_id' => $listing_id),
                null,
                array('%s')
            );
        } else {
            $result = $this->wpdb->insert($table, $details_data);
        }

        if ($result === false) {
            return new WP_Error('details_sync_failed', $this->wpdb->last_error);
        }

        return true;
    }

    /**
     * Sync listing to wp_bme_listing_location table
     *
     * @since 1.0.0
     * @param int $listing_id Listing ID
     * @param array $data Listing data
     * @return bool|WP_Error
     */
    private function sync_to_location($listing_id, $data) {
        $table = $this->database->get_bme_table('location');

        // Get coordinates - use provided or geocode
        $latitude = isset($data['latitude']) ? floatval($data['latitude']) : null;
        $longitude = isset($data['longitude']) ? floatval($data['longitude']) : null;

        // Geocode if coordinates not provided
        if (empty($latitude) || empty($longitude)) {
            $geocode_result = $this->geocoder->geocode($data);

            if (!is_wp_error($geocode_result)) {
                $latitude = $geocode_result['latitude'];
                $longitude = $geocode_result['longitude'];
            } else {
                // Use default Boston coordinates
                $defaults = EL_Geocoder::get_default_coordinates();
                $latitude = $defaults['latitude'];
                $longitude = $defaults['longitude'];
                error_log("Exclusive Listings: Using default coordinates for listing {$listing_id}");
            }
        }

        // Build unparsed address
        $unparsed_address = $this->build_unparsed_address($data);

        // Check if exists
        $exists = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT listing_id FROM {$table} WHERE listing_id = %s",
            $listing_id
        ));

        // Build the POINT string (longitude first!)
        $point_string = EL_Geocoder::format_point($latitude, $longitude);

        $street_number = $data['street_number'] ?? '';
        $street_name = $data['street_name'] ?? '';
        $unit_number = $data['unit_number'] ?? '';
        $city = $data['city'] ?? '';
        $state = $data['state_or_province'] ?? 'MA';
        $postal_code = $data['postal_code'] ?? '';
        $county = $data['county'] ?? '';
        $subdivision = $data['subdivision_name'] ?? '';

        if ($exists) {
            $sql = $this->wpdb->prepare(
                "UPDATE {$table} SET
                    street_number = %s, street_name = %s, unit_number = %s,
                    city = %s, state_or_province = %s, postal_code = %s,
                    county_or_parish = %s, subdivision_name = %s, unparsed_address = %s,
                    latitude = %f, longitude = %f, coordinates = ST_GeomFromText(%s)
                WHERE listing_id = %d",
                $street_number, $street_name, $unit_number,
                $city, $state, $postal_code,
                $county, $subdivision, $unparsed_address,
                $latitude, $longitude, $point_string,
                $listing_id
            );
        } else {
            $sql = $this->wpdb->prepare(
                "INSERT INTO {$table} (
                    listing_id, street_number, street_name, unit_number,
                    city, state_or_province, postal_code, county_or_parish, subdivision_name,
                    unparsed_address, latitude, longitude, coordinates
                ) VALUES (%d, %s, %s, %s, %s, %s, %s, %s, %s, %s, %f, %f, ST_GeomFromText(%s))",
                $listing_id,
                $street_number, $street_name, $unit_number,
                $city, $state, $postal_code,
                $county, $subdivision, $unparsed_address,
                $latitude, $longitude, $point_string
            );
        }

        $result = $this->wpdb->query($sql);

        if ($result === false) {
            return new WP_Error('location_sync_failed', $this->wpdb->last_error);
        }

        // Update summary table with coordinates
        $summary_table = $this->database->get_bme_table('summary');
        $this->wpdb->update(
            $summary_table,
            array(
                'latitude' => $latitude,
                'longitude' => $longitude,
            ),
            array('listing_id' => $listing_id),
            array('%f', '%f'),
            array('%d')
        );

        return true;
    }

    /**
     * Sync listing to wp_bme_listing_features table
     *
     * @since 1.0.0
     * @since 1.4.0 Added interior_features, appliances, exterior_features, waterfront_features, view, parking_features
     * @param int $listing_id Listing ID
     * @param array $data Listing data
     * @return bool|WP_Error
     */
    private function sync_to_features($listing_id, $data) {
        $table = $this->database->get_bme_table('features');

        // Check if exists
        $exists = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT listing_id FROM {$table} WHERE listing_id = %s",
            $listing_id
        ));

        $features_data = array(
            'listing_id' => $listing_id,
            'pool_private_yn' => isset($data['has_pool']) ? intval($data['has_pool']) : 0,
            'waterfront_yn' => isset($data['waterfront_yn']) ? intval($data['waterfront_yn']) : 0,
            'pets_allowed' => isset($data['pet_friendly']) ? intval($data['pet_friendly']) : null,
            'exterior_features' => $data['exterior_features'] ?? null,
            'waterfront_features' => $data['waterfront_features'] ?? null,
            'view_yn' => isset($data['view_yn']) ? intval($data['view_yn']) : 0,
            'view' => $data['view'] ?? null,
        );

        if ($exists) {
            $result = $this->wpdb->update(
                $table,
                $features_data,
                array('listing_id' => $listing_id),
                null,
                array('%s')
            );
        } else {
            $result = $this->wpdb->insert($table, $features_data);
        }

        if ($result === false) {
            return new WP_Error('features_sync_failed', $this->wpdb->last_error);
        }

        return true;
    }

    /**
     * Sync listing to wp_bme_listing_financial table
     *
     * @since 1.4.0
     * @param int $listing_id Listing ID
     * @param array $data Listing data
     * @return bool|WP_Error
     */
    private function sync_to_financial($listing_id, $data) {
        $table = $this->database->get_bme_table('financial');

        // Check if exists
        $exists = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT listing_id FROM {$table} WHERE listing_id = %s",
            $listing_id
        ));

        $financial_data = array(
            'listing_id' => $listing_id,
            'tax_annual_amount' => isset($data['tax_annual_amount']) ? floatval($data['tax_annual_amount']) : null,
            'tax_year' => isset($data['tax_year']) ? intval($data['tax_year']) : null,
            'association_yn' => isset($data['association_yn']) ? intval($data['association_yn']) : 0,
            'association_fee' => isset($data['association_fee']) ? floatval($data['association_fee']) : null,
            'association_fee_frequency' => $data['association_fee_frequency'] ?? null,
            'association_fee_includes' => $data['association_fee_includes'] ?? null,
        );

        if ($exists) {
            $result = $this->wpdb->update(
                $table,
                $financial_data,
                array('listing_id' => $listing_id),
                null,
                array('%s')
            );
        } else {
            $result = $this->wpdb->insert($table, $financial_data);
        }

        if ($result === false) {
            return new WP_Error('financial_sync_failed', $this->wpdb->last_error);
        }

        return true;
    }

    /**
     * Update photo information in summary table
     *
     * @since 1.0.0
     * @param int $listing_id Listing ID
     * @param string|null $main_photo_url URL of main photo
     * @param int $photo_count Total photo count
     * @return bool
     */
    public function update_photo_info($listing_id, $main_photo_url, $photo_count) {
        $table = $this->database->get_bme_table('summary');

        $result = $this->wpdb->update(
            $table,
            array(
                'main_photo_url' => $main_photo_url,
                'photo_count' => $photo_count,
            ),
            array('listing_id' => $listing_id),
            array('%s', '%d'),
            array('%d')
        );

        return $result !== false;
    }

    /**
     * Delete a listing from all BME tables
     *
     * @since 1.0.0
     * @since 1.4.0 Added financial table
     * @param int $listing_id Listing ID
     * @return bool True on success
     */
    public function delete_listing($listing_id) {
        $tables = array(
            'listings',
            'summary',
            'details',
            'location',
            'features',
            'financial',
            'media',
        );

        foreach ($tables as $table_key) {
            $table = $this->database->get_bme_table($table_key);
            $this->wpdb->delete($table, array('listing_id' => $listing_id));
        }

        return true;
    }

    /**
     * Archive a listing (copy to archive tables, delete from active)
     *
     * @since 1.0.0
     * @since 1.4.0 Added financial table
     * @param int $listing_id Listing ID
     * @return bool|WP_Error
     */
    public function archive_listing($listing_id) {
        $table_pairs = array(
            'listings' => 'listings_archive',
            'summary' => 'summary_archive',
            'details' => 'details_archive',
            'location' => 'location_archive',
            'features' => 'features_archive',
            'financial' => 'financial_archive',
        );

        $this->wpdb->query('START TRANSACTION');

        try {
            foreach ($table_pairs as $active => $archive) {
                $active_table = $this->database->get_bme_table($active);
                $archive_table = $this->database->get_bme_table($archive);

                // Copy to archive
                $this->wpdb->query($this->wpdb->prepare(
                    "INSERT INTO {$archive_table} SELECT * FROM {$active_table} WHERE listing_id = %s",
                    $listing_id
                ));

                // Delete from active
                $this->wpdb->delete($active_table, array('listing_id' => $listing_id));
            }

            // Update media source_table
            $media_table = $this->database->get_bme_table('media');
            $this->wpdb->update(
                $media_table,
                array('source_table' => 'archive'),
                array('listing_id' => $listing_id),
                array('%s'),
                array('%s')
            );

            $this->wpdb->query('COMMIT');

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("Exclusive Listings: Archived listing {$listing_id}");
            }

            return true;

        } catch (Exception $e) {
            $this->wpdb->query('ROLLBACK');
            return new WP_Error('archive_failed', $e->getMessage());
        }
    }

    /**
     * Build unparsed address string
     *
     * @since 1.0.0
     * @param array $data Listing data
     * @return string Full address string
     */
    private function build_unparsed_address($data) {
        $parts = array();

        // Street
        if (!empty($data['street_number'])) {
            $parts[] = $data['street_number'];
        }
        if (!empty($data['street_name'])) {
            $parts[] = $data['street_name'];
        }
        if (!empty($data['unit_number'])) {
            $parts[] = '#' . $data['unit_number'];
        }

        $street = implode(' ', $parts);
        $address_parts = array($street);

        if (!empty($data['city'])) {
            $address_parts[] = $data['city'];
        }
        if (!empty($data['state_or_province'])) {
            $address_parts[] = $data['state_or_province'];
        }
        if (!empty($data['postal_code'])) {
            $address_parts[] = $data['postal_code'];
        }

        return implode(', ', array_filter($address_parts));
    }

    /**
     * Check if a listing exists in BME tables
     *
     * @since 1.0.0
     * @param int $listing_id Listing ID
     * @return bool True if exists
     */
    public function listing_exists($listing_id) {
        $table = $this->database->get_bme_table('summary');

        $exists = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT listing_id FROM {$table} WHERE listing_id = %d",
            $listing_id
        ));

        return (bool) $exists;
    }

    /**
     * Get sync status for a listing
     *
     * @since 1.0.0
     * @since 1.4.0 Added financial table
     * @param int $listing_id Listing ID
     * @return array Sync status for each table
     */
    public function get_sync_status($listing_id) {
        $tables = array('listings', 'summary', 'details', 'location', 'features', 'financial', 'media');
        $status = array();

        foreach ($tables as $table_key) {
            $table = $this->database->get_bme_table($table_key);
            $exists = $this->wpdb->get_var($this->wpdb->prepare(
                "SELECT listing_id FROM {$table} WHERE listing_id = %s LIMIT 1",
                $listing_id
            ));
            $status[$table_key] = (bool) $exists;
        }

        return $status;
    }

    /**
     * Clear property detail cache for both agent and public views
     *
     * The MLD property detail API caches responses for 1 hour. When exclusive
     * listings are updated, this cache must be cleared so changes appear immediately.
     *
     * This clears:
     * 1. WordPress transients (MLD plugin's internal cache)
     * 2. Kinsta edge cache (CDN layer that may cache API responses)
     *
     * @since 1.4.1
     * @param int $listing_id The listing ID
     * @param string $listing_key The listing key hash
     */
    private function clear_property_cache($listing_id, $listing_key) {
        // 1. Clear WordPress transients for mobile REST API
        // Property detail API uses listing_key in cache key
        // Cache key format: mld_property_detail_ + md5(listing_key + '_' + role)
        // Must clear both agent and public variants
        $cache_key_public = 'mld_property_detail_' . md5($listing_key . '_public');
        $cache_key_agent = 'mld_property_detail_' . md5($listing_key . '_agent');

        delete_transient($cache_key_public);
        delete_transient($cache_key_agent);

        // 2. Clear MLD_Query_Cache for listing details (used by SEO/property pages)
        // This cache key format matches MLD_Query::get_listing_details()
        if (class_exists('MLD_Query_Cache')) {
            $query_cache_key = MLD_Query_Cache::generate_key('listing_details', ['id' => $listing_id]);
            MLD_Query_Cache::delete($query_cache_key);

            // Also clear for string version of listing_id (some queries pass as string)
            $query_cache_key_str = MLD_Query_Cache::generate_key('listing_details', ['id' => (string)$listing_id]);
            MLD_Query_Cache::delete($query_cache_key_str);
        }

        // 3. Clear Kinsta edge cache for the property API endpoint
        // Kinsta CDN may cache REST API responses even with no-cache headers
        global $kinsta_cache;
        if (isset($kinsta_cache) && isset($kinsta_cache->kinsta_cache_purge)) {
            // Purge the specific property detail endpoint URLs
            $api_url = rest_url('mld-mobile/v1/properties/' . $listing_key);

            // Use Kinsta's cache purge mechanism
            // Since there's no simple URL-purge API, trigger a site cache purge
            // This is aggressive but ensures the cache is cleared
            $kinsta_cache->kinsta_cache_purge->purge_complete_caches();

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("Exclusive Listings: Triggered Kinsta cache purge for listing {$listing_id}");
            }
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("Exclusive Listings: Cleared property cache for listing {$listing_id} (key: {$listing_key})");
        }
    }
}
