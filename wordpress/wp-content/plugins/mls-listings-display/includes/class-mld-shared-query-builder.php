<?php
/**
 * Shared Query Builder for MLS Listings Display
 *
 * This class provides unified filter building logic used by both:
 * - Mobile REST API (class-mld-mobile-rest-api.php) - iOS app
 * - Web AJAX Query (class-mld-query.php) - Web map
 *
 * By sharing this logic, we ensure feature parity between platforms.
 *
 * @package MLS_Listings_Display
 * @since 6.30.20
 * @see /docs/CODE_PATH_PARITY_AUDIT.md for filter documentation
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Shared_Query_Builder {

    /**
     * Filter key normalization map
     * Maps various input formats to canonical snake_case keys
     */
    private static $key_map = array(
        // Location filters
        'City' => 'city',
        'Postal Code' => 'postal_code',
        'PostalCode' => 'postal_code',
        'zip' => 'postal_code',
        'Neighborhood' => 'neighborhood',
        'MLS Number' => 'mls_number',
        'ListingId' => 'mls_number',
        'listing_id' => 'mls_number',
        'Street Name' => 'street_name',
        'StreetName' => 'street_name',
        'Street Address' => 'address',
        'Address' => 'address',

        // Property type
        'PropertyType' => 'property_type',
        'home_type' => 'property_sub_type',

        // Price
        'price_min' => 'min_price',
        'price_max' => 'max_price',

        // Rooms
        'bedrooms' => 'beds',
        'beds_min' => 'beds',
        'bathrooms' => 'baths',
        'baths_min' => 'baths',

        // Size
        'square_feet' => 'sqft',

        // Amenities - normalize YN flags to boolean
        'PoolPrivateYN' => 'has_pool',
        'WaterfrontYN' => 'has_waterfront',
        'ViewYN' => 'has_view',
        'MLSPIN_WATERVIEW_FLAG' => 'has_water_view',
        'SpaYN' => 'has_spa',
        'MLSPIN_OUTDOOR_SPACE_AVAILABLE' => 'has_outdoor_space',
        'SeniorCommunityYN' => 'is_senior_community',
        'FireplaceYN' => 'has_fireplace',
        'GarageYN' => 'has_garage',
        'CoolingYN' => 'has_cooling',
    );

    /**
     * Normalize filter keys to canonical format
     *
     * @param array $filters Input filters (may have various key formats)
     * @return array Normalized filters with snake_case keys
     */
    public static function normalize_filters($filters) {
        if (empty($filters) || !is_array($filters)) {
            return array();
        }

        $normalized = array();

        foreach ($filters as $key => $value) {
            // Skip empty values (but allow 0 and '0')
            if ($value === null || $value === '' || (is_array($value) && empty($value))) {
                continue;
            }

            // Handle nested filter format: ['value' => X, 'min' => Y, 'max' => Z]
            if (is_array($value) && isset($value['value'])) {
                $value = $value['value'];
            }

            // Normalize the key
            $normalized_key = isset(self::$key_map[$key]) ? self::$key_map[$key] : $key;

            // Handle special nested cases (price, bedrooms, etc. with min/max)
            if (is_array($value) && (isset($value['min']) || isset($value['max']))) {
                if (isset($value['min']) && $value['min'] > 0) {
                    $normalized[$normalized_key . '_min'] = $value['min'];
                }
                if (isset($value['max']) && $value['max'] > 0 && $value['max'] < PHP_INT_MAX) {
                    $normalized[$normalized_key . '_max'] = $value['max'];
                }
            } else {
                $normalized[$normalized_key] = $value;
            }
        }

        return $normalized;
    }

    /**
     * Build WHERE conditions for property queries
     *
     * @param array $filters Normalized filter array
     * @param string $table_alias Table alias for summary table (default 's')
     * @param string $location_alias Table alias for location table (default 'loc')
     * @return array ['conditions' => array of SQL conditions, 'params' => array of values, 'needs_location_join' => bool]
     */
    public static function build_conditions($filters, $table_alias = 's', $location_alias = 'loc') {
        global $wpdb;

        $filters = self::normalize_filters($filters);
        $conditions = array();
        $params = array();
        $needs_location_join = false;

        // === Location Filters ===

        // City filter (supports array)
        if (!empty($filters['city'])) {
            $city = $filters['city'];
            if (is_array($city)) {
                $placeholders = array_fill(0, count($city), '%s');
                $conditions[] = "{$table_alias}.city IN (" . implode(',', $placeholders) . ")";
                foreach ($city as $c) {
                    $params[] = sanitize_text_field($c);
                }
            } else {
                $conditions[] = "{$table_alias}.city = %s";
                $params[] = sanitize_text_field($city);
            }
        }

        // Postal code / ZIP filter (supports array)
        if (!empty($filters['postal_code'])) {
            $zip = $filters['postal_code'];
            if (is_array($zip)) {
                $placeholders = array_fill(0, count($zip), '%s');
                $conditions[] = "{$table_alias}.postal_code IN (" . implode(',', $placeholders) . ")";
                foreach ($zip as $z) {
                    $params[] = sanitize_text_field($z);
                }
            } else {
                $conditions[] = "{$table_alias}.postal_code = %s";
                $params[] = sanitize_text_field($zip);
            }
        }

        // Neighborhood filter (subdivision_name)
        if (!empty($filters['neighborhood'])) {
            $neighborhood = $filters['neighborhood'];
            if (is_array($neighborhood)) {
                $placeholders = array_fill(0, count($neighborhood), '%s');
                $conditions[] = "{$table_alias}.subdivision_name IN (" . implode(',', $placeholders) . ")";
                foreach ($neighborhood as $n) {
                    $params[] = sanitize_text_field($n);
                }
            } else {
                $conditions[] = "{$table_alias}.subdivision_name = %s";
                $params[] = sanitize_text_field($neighborhood);
            }
        }

        // MLS Number filter (exact match)
        if (!empty($filters['mls_number'])) {
            $mls = $filters['mls_number'];
            if (is_array($mls)) {
                $placeholders = array_fill(0, count($mls), '%s');
                $conditions[] = "{$table_alias}.listing_id IN (" . implode(',', $placeholders) . ")";
                foreach ($mls as $m) {
                    $params[] = sanitize_text_field($m);
                }
            } else {
                $conditions[] = "{$table_alias}.listing_id = %s";
                $params[] = sanitize_text_field($mls);
            }
        }

        // Address filter (exact match on unparsed_address - requires location join)
        if (!empty($filters['address'])) {
            $needs_location_join = true;
            $conditions[] = "{$location_alias}.unparsed_address = %s";
            $params[] = sanitize_text_field($filters['address']);
        }

        // Street name filter (partial match)
        if (!empty($filters['street_name'])) {
            $street = $filters['street_name'];
            if (is_array($street)) {
                $street_conditions = array();
                foreach ($street as $s) {
                    $street_conditions[] = "{$table_alias}.street_name LIKE %s";
                    $params[] = '%' . $wpdb->esc_like(sanitize_text_field($s)) . '%';
                }
                $conditions[] = '(' . implode(' OR ', $street_conditions) . ')';
            } else {
                $conditions[] = "{$table_alias}.street_name LIKE %s";
                $params[] = '%' . $wpdb->esc_like(sanitize_text_field($street)) . '%';
            }
        }

        // === Price Filters ===

        if (!empty($filters['min_price']) && $filters['min_price'] > 0) {
            $conditions[] = "{$table_alias}.list_price >= %d";
            $params[] = absint($filters['min_price']);
        }

        if (!empty($filters['max_price']) && $filters['max_price'] > 0) {
            $conditions[] = "{$table_alias}.list_price <= %d";
            $params[] = absint($filters['max_price']);
        }

        // Price reduced filter
        if (!empty($filters['price_reduced'])) {
            $conditions[] = "({$table_alias}.original_list_price IS NOT NULL AND {$table_alias}.original_list_price > 0 AND {$table_alias}.list_price < {$table_alias}.original_list_price)";
        }

        // === Property Filters ===

        // Property type
        if (!empty($filters['property_type']) && $filters['property_type'] !== 'all') {
            $type = $filters['property_type'];
            if (is_array($type)) {
                $placeholders = array_fill(0, count($type), '%s');
                $conditions[] = "{$table_alias}.property_type IN (" . implode(',', $placeholders) . ")";
                foreach ($type as $t) {
                    $params[] = sanitize_text_field($t);
                }
            } else {
                // Special handling: "Residential" includes both Residential and Residential Income
                if ($type === 'Residential') {
                    $conditions[] = "({$table_alias}.property_type = 'Residential' OR {$table_alias}.property_type = 'Residential Income')";
                } else {
                    $conditions[] = "{$table_alias}.property_type = %s";
                    $params[] = sanitize_text_field($type);
                }
            }
        }

        // Property sub type
        if (!empty($filters['property_sub_type'])) {
            $subtype = $filters['property_sub_type'];
            if (is_array($subtype)) {
                $placeholders = array_fill(0, count($subtype), '%s');
                $conditions[] = "{$table_alias}.property_sub_type IN (" . implode(',', $placeholders) . ")";
                foreach ($subtype as $st) {
                    $params[] = sanitize_text_field($st);
                }
            } else {
                $conditions[] = "{$table_alias}.property_sub_type = %s";
                $params[] = sanitize_text_field($subtype);
            }
        }

        // Beds filter
        if (!empty($filters['beds'])) {
            $beds = $filters['beds'];
            if (is_array($beds)) {
                // Array means exact matches (e.g., [2, 3, 4])
                $placeholders = array_fill(0, count($beds), '%d');
                $conditions[] = "{$table_alias}.bedrooms_total IN (" . implode(',', $placeholders) . ")";
                foreach ($beds as $b) {
                    $params[] = absint($b);
                }
            } else {
                // Single value means minimum
                $conditions[] = "{$table_alias}.bedrooms_total >= %d";
                $params[] = absint($beds);
            }
        }

        // Baths filter
        if (!empty($filters['baths'])) {
            $conditions[] = "{$table_alias}.bathrooms_total >= %f";
            $params[] = floatval($filters['baths']);
        }

        // === Size Filters ===

        if (!empty($filters['sqft_min']) && $filters['sqft_min'] > 0) {
            $conditions[] = "{$table_alias}.building_area_total >= %d";
            $params[] = absint($filters['sqft_min']);
        }

        if (!empty($filters['sqft_max']) && $filters['sqft_max'] > 0) {
            $conditions[] = "{$table_alias}.building_area_total <= %d";
            $params[] = absint($filters['sqft_max']);
        }

        // === Year Built Filters ===

        if (!empty($filters['year_built_min']) && $filters['year_built_min'] > 0) {
            $conditions[] = "{$table_alias}.year_built >= %d";
            $params[] = absint($filters['year_built_min']);
        }

        if (!empty($filters['year_built_max']) && $filters['year_built_max'] > 0) {
            $conditions[] = "{$table_alias}.year_built <= %d";
            $params[] = absint($filters['year_built_max']);
        }

        // === Lot Size Filters ===
        // Note: REST API sends lot_size in sqft, converts to acres
        // Query class expects acres directly
        // We'll detect and handle both

        if (!empty($filters['lot_size_min']) && $filters['lot_size_min'] > 0) {
            $lot_min = floatval($filters['lot_size_min']);
            // If value > 100, assume it's sqft and convert to acres
            if ($lot_min > 100) {
                $lot_min = $lot_min / 43560.0;
            }
            $conditions[] = "{$table_alias}.lot_size_acres >= %f";
            $params[] = $lot_min;
        }

        if (!empty($filters['lot_size_max']) && $filters['lot_size_max'] > 0) {
            $lot_max = floatval($filters['lot_size_max']);
            // If value > 100, assume it's sqft and convert to acres
            if ($lot_max > 100) {
                $lot_max = $lot_max / 43560.0;
            }
            $conditions[] = "{$table_alias}.lot_size_acres <= %f";
            $params[] = $lot_max;
        }

        // === Parking Filters ===

        if (!empty($filters['garage_spaces_min']) && $filters['garage_spaces_min'] > 0) {
            $conditions[] = "{$table_alias}.garage_spaces >= %d";
            $params[] = absint($filters['garage_spaces_min']);
        }

        // === Time-Based Filters ===

        // Days on market
        if (!empty($filters['max_dom']) && $filters['max_dom'] > 0) {
            $conditions[] = "{$table_alias}.days_on_market <= %d";
            $params[] = absint($filters['max_dom']);
        }
        if (!empty($filters['min_dom']) && $filters['min_dom'] > 0) {
            $conditions[] = "{$table_alias}.days_on_market >= %d";
            $params[] = absint($filters['min_dom']);
        }

        // New listings (within X days)
        if (!empty($filters['new_listing_days']) && $filters['new_listing_days'] > 0) {
            $conditions[] = "{$table_alias}.listing_contract_date >= DATE_SUB(CURDATE(), INTERVAL %d DAY)";
            $params[] = absint($filters['new_listing_days']);
        }

        // === Status Filter ===

        if (!empty($filters['status'])) {
            $status = $filters['status'];
            if (is_array($status)) {
                $placeholders = array_fill(0, count($status), '%s');
                $conditions[] = "{$table_alias}.standard_status IN (" . implode(',', $placeholders) . ")";
                foreach ($status as $s) {
                    $params[] = sanitize_text_field($s);
                }
            } else {
                $conditions[] = "{$table_alias}.standard_status = %s";
                $params[] = sanitize_text_field($status);
            }
        }

        // === Boolean Amenity Filters ===

        if (!empty($filters['has_pool'])) {
            $conditions[] = "{$table_alias}.has_pool = 1";
        }

        if (!empty($filters['has_fireplace'])) {
            $conditions[] = "{$table_alias}.has_fireplace = 1";
        }

        if (!empty($filters['has_basement'])) {
            $conditions[] = "{$table_alias}.has_basement = 1";
        }

        if (!empty($filters['pet_friendly'])) {
            $conditions[] = "{$table_alias}.pet_friendly = 1";
        }

        return array(
            'conditions' => $conditions,
            'params' => $params,
            'needs_location_join' => $needs_location_join,
        );
    }

    /**
     * Build prepared WHERE clause string
     *
     * @param array $filters Normalized filter array
     * @param string $table_alias Table alias for summary table
     * @param string $location_alias Table alias for location table
     * @return array ['where' => prepared SQL string, 'needs_location_join' => bool]
     */
    public static function build_where_clause($filters, $table_alias = 's', $location_alias = 'loc') {
        global $wpdb;

        $result = self::build_conditions($filters, $table_alias, $location_alias);

        if (empty($result['conditions'])) {
            return array(
                'where' => '1=1',
                'needs_location_join' => false,
            );
        }

        // Build the prepared statement
        $where_template = implode(' AND ', $result['conditions']);

        if (!empty($result['params'])) {
            $where = $wpdb->prepare($where_template, $result['params']);
        } else {
            $where = $where_template;
        }

        return array(
            'where' => $where,
            'needs_location_join' => $result['needs_location_join'],
        );
    }

    /**
     * Check if filters contain school-related criteria
     *
     * @param array $filters Filter array
     * @return bool True if school filters are present
     */
    public static function has_school_filters($filters) {
        if (empty($filters)) {
            return false;
        }

        // Check for school grade (district rating) filter
        if (!empty($filters['school_grade'])) {
            return true;
        }

        // Check for proximity filters
        $school_filter_keys = array(
            'near_a_elementary', 'near_ab_elementary',
            'near_a_middle', 'near_ab_middle',
            'near_a_high', 'near_ab_high',
            'school_district_id',
        );

        foreach ($school_filter_keys as $key) {
            if (!empty($filters[$key])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build school filter criteria array for BMN Schools integration
     *
     * @param array $filters Filter array
     * @return array School criteria for MLD_BMN_Schools_Integration
     */
    public static function build_school_criteria($filters) {
        return array(
            'school_grade' => !empty($filters['school_grade']) ? $filters['school_grade'] : null,
            'school_district_id' => !empty($filters['school_district_id']) ? absint($filters['school_district_id']) : null,
            'near_a_elementary' => !empty($filters['near_a_elementary']),
            'near_ab_elementary' => !empty($filters['near_ab_elementary']),
            'near_a_middle' => !empty($filters['near_a_middle']),
            'near_ab_middle' => !empty($filters['near_ab_middle']),
            'near_a_high' => !empty($filters['near_a_high']),
            'near_ab_high' => !empty($filters['near_ab_high']),
        );
    }

    /**
     * Get sort clause for property queries
     *
     * @param string $sort Sort parameter value
     * @param string $table_alias Table alias
     * @return string ORDER BY clause (without ORDER BY keyword)
     */
    public static function get_sort_clause($sort, $table_alias = 's') {
        $sort_map = array(
            'price_asc' => "{$table_alias}.list_price ASC",
            'price_desc' => "{$table_alias}.list_price DESC",
            'list_date_asc' => "{$table_alias}.listing_contract_date ASC",
            'list_date_desc' => "{$table_alias}.listing_contract_date DESC",
            'beds_desc' => "{$table_alias}.bedrooms_total DESC, {$table_alias}.list_price DESC",
            'sqft_desc' => "{$table_alias}.building_area_total DESC",
            'dom_asc' => "{$table_alias}.days_on_market ASC",
            'dom_desc' => "{$table_alias}.days_on_market DESC",
        );

        return isset($sort_map[$sort]) ? $sort_map[$sort] : "{$table_alias}.listing_contract_date DESC";
    }
}
