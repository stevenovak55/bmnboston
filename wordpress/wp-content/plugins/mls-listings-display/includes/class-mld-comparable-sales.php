<?php
/**
 * Enhanced Comparable Sales Engine
 * With feature-based adjustments, customizable filters, and comparability scoring
 *
 * @package    MLS_Listings_Display
 * @subpackage MLS_Listings_Display/includes
 * @since      5.3.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class MLD_Comparable_Sales {

    /**
     * FHA/Fannie Mae Style Adjustment Thresholds
     * Warning thresholds trigger UI warnings but don't cap values
     * Hard caps prevent extreme adjustments
     *
     * @since 6.10.6
     */
    const INDIVIDUAL_ADJ_WARNING_THRESHOLD = 0.10;  // 10% of comp price triggers warning
    const NET_ADJ_WARNING_THRESHOLD = 0.15;         // 15% net adjustment triggers warning
    const GROSS_ADJ_WARNING_THRESHOLD = 0.25;       // 25% gross adjustment triggers warning
    const INDIVIDUAL_ADJ_HARD_CAP = 0.20;           // 20% hard cap per individual adjustment
    const NET_ADJ_HARD_CAP = 0.30;                  // 30% net adjustment hard cap
    const GROSS_ADJ_HARD_CAP = 0.40;                // 40% gross adjustment hard cap

    /**
     * Adjustment scaling percentages (percentage of property value)
     * @since 6.10.6
     */
    const BEDROOM_PCT_MIN = 0.02;    // 2% minimum per bedroom
    const BEDROOM_PCT_MAX = 0.03;    // 3% maximum per bedroom
    const BATHROOM_PCT_MIN = 0.0066; // 0.66% minimum per bathroom
    const BATHROOM_PCT_MAX = 0.01;   // 1% maximum per bathroom
    const YEAR_BUILT_PCT = 0.004;    // 0.4% per year
    const YEAR_BUILT_MAX_YEARS = 20; // Maximum years to adjust
    const YEAR_BUILT_MAX_PCT = 0.10; // 10% max total age adjustment
    const SQFT_MAX_PCT = 0.10;       // 10% max sqft adjustment
    const GARAGE_FIRST_PCT = 0.025;  // 2.5% for first garage space
    const GARAGE_ADD_PCT = 0.015;    // 1.5% for additional garage spaces
    const ROAD_TYPE_DEFAULT_PCT = 0.05; // 5% default road type premium (down from 25%)

    /**
     * Market data cache
     */
    private $market_data = null;

    /**
     * Market data calculator instance
     */
    private $calculator = null;

    /**
     * Get similar properties with customizable filters
     *
     * @param array $subject_property Subject property data
     * @param array $filters User-selected filters
     * @return array Array of comparable properties with adjustments
     */
    public function find_comparables($subject_property, $filters = array()) {
        global $wpdb;

        // Merge with defaults
        $filters = wp_parse_args($filters, $this->get_default_filters());

        // Load market data for adjustments
        $this->load_market_data($subject_property['city'], $subject_property['state']);

        // Build query
        $query = $this->build_query($subject_property, $filters);

        // Execute query
        $results = $wpdb->get_results($query, ARRAY_A);

        if (empty($results)) {
            return array(
                'comparables' => array(),
                'summary' => array(
                    'total_found' => 0,
                    'avg_price' => 0,
                    'price_range' => array('min' => 0, 'max' => 0),
                    'estimated_value' => array('low' => 0, 'mid' => 0, 'high' => 0)
                )
            );
        }

        // Process each comparable
        $comparables = array();
        foreach ($results as $property) {
            $comparable = $this->process_comparable($property, $subject_property, $filters);
            // v6.68.23: Skip comparables with invalid price data (returns null)
            if ($comparable !== null) {
                $comparables[] = $comparable;
            }
        }

        // Add the subject property as the first item
        $subject_comp = $this->get_subject_as_comparable($subject_property, $filters);
        if ($subject_comp) {
            array_unshift($comparables, $subject_comp);
        }

        // Sort by comparability score (subject will stay first with score of 100)
        usort($comparables, function($a, $b) {
            return $b['comparability_score'] <=> $a['comparability_score'];
        });

        // Calculate summary statistics
        $summary = $this->calculate_summary($comparables, $subject_property);

        // Get market forecast
        $forecast = $this->get_market_forecast($subject_property);

        return array(
            'comparables' => $comparables,
            'summary' => $summary,
            'forecast' => $forecast,
            'filters_applied' => $filters
        );
    }

    /**
     * Get default filter settings
     */
    private function get_default_filters() {
        return array(
            'radius' => 3, // miles
            'price_range_pct' => 15, // ±15%
            'sqft_range_pct' => 20, // ±20%
            'beds_min' => null,
            'beds_max' => null,
            'beds_exact' => false,
            'baths_min' => null,
            'baths_max' => null,
            'garage_min' => null,
            'garage_exact' => false,
            'year_built_range' => 10, // ±10 years
            'lot_size_min' => null,
            'lot_size_max' => null,
            'pool_required' => null, // true/false/null
            'waterfront_only' => false,
            'same_city_only' => false,
            'statuses' => array('Closed'), // Active, Pending, Closed
            'months_back' => 12, // For closed sales
            'max_dom' => null, // For active listings
            'hoa_max' => null,
            'exclude_hoa' => false,
            'sort_by' => 'similarity', // similarity, price, distance, date
            'limit' => 20
        );
    }

    /**
     * Build query to find comparable properties (OPTIMIZED for summary table)
     *
     * @param array $subject Subject property data
     * @param array $filters Search filters
     * @return string SQL query
     */
    private function build_query($subject, $filters) {
        global $wpdb;

        // Determine which table(s) to query based on status filter
        // Closed listings are in the archive table, Active/Pending in the main table
        $statuses = $filters['statuses'];
        $has_closed = in_array('Closed', $statuses);
        $has_active_statuses = count(array_diff($statuses, ['Closed'])) > 0;

        // Choose table based on status filter
        if ($has_closed && !$has_active_statuses) {
            // Only looking for Closed - use archive table
            $summary_table = $wpdb->prefix . 'bme_listing_summary_archive';
        } else {
            // Looking for Active/Pending/etc or mixed - use main table
            // Note: For mixed status queries, Closed listings in archive won't be found
            // This is acceptable as comparable sales typically search one status type
            $summary_table = $wpdb->prefix . 'bme_listing_summary';
        }

        // Use the optimized summary table which already has all data pre-joined
        $select = "
            SELECT
                s.listing_id,
                s.list_price,
                s.close_price,
                s.standard_status,
                s.property_type,
                s.property_sub_type,
                s.days_on_market,
                s.close_date,
                s.photo_count as photos_count,
                s.listing_contract_date as original_entry_timestamp,
                -- Construct address from components
                CONCAT(s.street_number, ' ', s.street_name) as unparsed_address,
                s.street_number,
                s.street_name,
                s.city,
                s.state_or_province as state,
                s.postal_code,
                s.latitude,
                s.longitude,
                s.bedrooms_total,
                s.bathrooms_total as bathrooms_total_decimal,
                s.building_area_total,
                s.lot_size_acres,
                s.year_built,
                s.garage_spaces,
                s.garage_spaces as parking_total, -- Use garage_spaces as fallback
                -- Convert boolean fields to Yes/No
                CASE WHEN s.has_pool = 1 THEN 'Yes' ELSE 'No' END as pool_private_yn,
                CASE WHEN s.has_hoa = 1 THEN 'Yes' ELSE 'No' END as association_yn,
                s.main_photo_url,
                -- Get additional data from related tables (only 3 small JOINs!)
                COALESCE(lf.waterfront_yn, lfa.waterfront_yn) as waterfront_yn,
                COALESCE(lfi.association_fee, lfia.association_fee) as association_fee,
                upd.road_type,
                upd.property_condition,
                -- Calculate distance using same formula as before
                (3959 * acos(cos(radians({$subject['lat']})) * cos(radians(s.latitude))
                * cos(radians(s.longitude) - radians({$subject['lng']}))
                + sin(radians({$subject['lat']})) * sin(radians(s.latitude)))) AS distance_miles
            FROM {$summary_table} s
            -- Only need 5 lightweight LEFT JOINs instead of 5+ heavy table joins
            LEFT JOIN {$wpdb->prefix}bme_listing_features lf ON s.listing_id = lf.listing_id
            LEFT JOIN {$wpdb->prefix}bme_listing_features_archive lfa ON s.listing_id = lfa.listing_id
            LEFT JOIN {$wpdb->prefix}bme_listing_financial lfi ON s.listing_id = lfi.listing_id
            LEFT JOIN {$wpdb->prefix}bme_listing_financial_archive lfia ON s.listing_id = lfia.listing_id
            LEFT JOIN {$wpdb->prefix}mld_user_property_data upd ON s.listing_id = upd.listing_id
        ";

        // Build WHERE conditions
        $where_conditions = array();

        // Exclude subject property
        if (!empty($subject['listing_id'])) {
            $where_conditions[] = $wpdb->prepare("s.listing_id != %s", $subject['listing_id']);
        }

        // Status filter (using indexed column)
        $status_placeholders = implode(',', array_fill(0, count($filters['statuses']), '%s'));
        $where_conditions[] = $wpdb->prepare("s.standard_status IN ($status_placeholders)", ...$filters['statuses']);

        // Radius filter with bounding box (uses idx_cma_geo_status index)
        // SKIP if at "no limit" (25+ miles) - user wants all results regardless of distance
        if ($filters['radius'] < 25) {
            $radius = $filters['radius'];
            $lat_range = $radius / 69;
            $lng_range = $radius / (69 * cos(deg2rad($subject['lat'])));

            $where_conditions[] = $wpdb->prepare(
                "s.latitude BETWEEN %f AND %f",
                $subject['lat'] - $lat_range,
                $subject['lat'] + $lat_range
            );
            $where_conditions[] = $wpdb->prepare(
                "s.longitude BETWEEN %f AND %f",
                $subject['lng'] - $lng_range,
                $subject['lng'] + $lng_range
            );
        }

        // City filter (uses idx_cma_location_search index)
        if ($filters['same_city_only'] && !empty($subject['city'])) {
            $where_conditions[] = $wpdb->prepare("s.city = %s", $subject['city']);
        }

        // Property type filter (uses idx_cma_location_search index)
        // Note: User-facing values like "Single Family Residence", "Condominium" are stored in
        // property_sub_type, while property_type contains broader categories like "Residential"
        if (!empty($subject['property_type'])) {
            // Common sub-types that should match property_sub_type instead of property_type
            $sub_types = array(
                'Single Family Residence',
                'Condominium',
                'Townhouse',
                'Multi Family',
                'Condex',
                'Two Family',
                'Three Family',
                'Four Family',
                'Mobile Home'
            );

            if (in_array($subject['property_type'], $sub_types)) {
                // Search by property_sub_type
                $where_conditions[] = $wpdb->prepare("s.property_sub_type = %s", $subject['property_type']);
            } else {
                // Search by property_type (for broader categories like "Residential", "Land", etc.)
                $where_conditions[] = $wpdb->prepare("s.property_type = %s", $subject['property_type']);
            }
        }

        // Price range filter (uses idx_cma_price_status_date index)
        // SKIP if at "no limit" (50%+) - user wants all results regardless of price
        if (!empty($subject['price']) && $subject['price'] > 0 && $filters['price_range_pct'] < 50) {
            $price_range_pct = $filters['price_range_pct'] / 100;
            $price_min = $subject['price'] * (1 - $price_range_pct);
            $price_max = $subject['price'] * (1 + $price_range_pct);

            if (in_array('Closed', $filters['statuses'])) {
                // Use COALESCE to fall back to list_price when close_price is NULL
                $where_conditions[] = $wpdb->prepare("COALESCE(s.close_price, s.list_price) BETWEEN %f AND %f", $price_min, $price_max);
            } else {
                $where_conditions[] = $wpdb->prepare("s.list_price BETWEEN %f AND %f", $price_min, $price_max);
            }
        }

        // Square footage filter (uses idx_cma_size_filter index)
        // SKIP if at "no limit" (50%+) - user wants all results regardless of size
        if (!empty($subject['sqft']) && $subject['sqft'] > 0 && $filters['sqft_range_pct'] < 50) {
            $sqft_range_pct = $filters['sqft_range_pct'] / 100;
            $sqft_min = $subject['sqft'] * (1 - $sqft_range_pct);
            $sqft_max = $subject['sqft'] * (1 + $sqft_range_pct);
            $where_conditions[] = $wpdb->prepare("s.building_area_total BETWEEN %d AND %d", $sqft_min, $sqft_max);
        }

        // Bedrooms filter (uses idx_cma_size_filter index)
        if ($filters['beds_exact'] && !empty($subject['beds'])) {
            $where_conditions[] = $wpdb->prepare("s.bedrooms_total = %d", $subject['beds']);
        } else {
            if (!is_null($filters['beds_min'])) {
                $where_conditions[] = $wpdb->prepare("s.bedrooms_total >= %d", $filters['beds_min']);
            }
            if (!is_null($filters['beds_max'])) {
                $where_conditions[] = $wpdb->prepare("s.bedrooms_total <= %d", $filters['beds_max']);
            }
        }

        // Bathrooms filter (uses idx_cma_size_filter index)
        if (!empty($filters['baths_exact']) && !empty($subject['baths'])) {
            $where_conditions[] = $wpdb->prepare("s.bathrooms_total = %f", $subject['baths']);
        } else {
            if (!is_null($filters['baths_min'])) {
                $where_conditions[] = $wpdb->prepare("s.bathrooms_total >= %f", $filters['baths_min']);
            }
            if (!is_null($filters['baths_max'])) {
                $where_conditions[] = $wpdb->prepare("s.bathrooms_total <= %f", $filters['baths_max']);
            }
        }

        // Year built filter
        // SKIP if at "no limit" (30+ years) - user wants all results regardless of age
        if (!empty($subject['year_built']) && $filters['year_built_range'] < 30) {
            $age_diff = $filters['year_built_range'];
            $year_min = $subject['year_built'] - $age_diff;
            $year_max = $subject['year_built'] + $age_diff;
            $where_conditions[] = $wpdb->prepare("s.year_built BETWEEN %d AND %d", $year_min, $year_max);
        }

        // Days on market filter (for active listings)
        if (in_array('Active', $filters['statuses']) && !is_null($filters['dom_max'])) {
            $where_conditions[] = $wpdb->prepare("s.days_on_market <= %d", $filters['dom_max']);
        }

        // Date range filter for closed sales (uses idx_cma_price_status_date index)
        // Note: months_back comes from the AJAX handler, convert to days for the query
        if (in_array('Closed', $filters['statuses']) && !empty($filters['months_back'])) {
            $days_back = intval($filters['months_back']) * 30; // Approximate days from months
            $date_cutoff = wp_date('Y-m-d', strtotime("-{$days_back} days")); // Use WP timezone
            $where_conditions[] = $wpdb->prepare("s.close_date >= %s", $date_cutoff);
        }

        // Pool filter
        if (!empty($filters['pool_required'])) {
            $where_conditions[] = "s.has_pool = 1";
        }

        // Waterfront filter (needs JOIN)
        if (!empty($filters['waterfront_required'])) {
            $where_conditions[] = "(lf.waterfront_yn = 'Yes' OR lfa.waterfront_yn = 'Yes')";
        }

        // Build final query
        $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);

        // HAVING clause for distance filter (calculated column)
        // SKIP if at "no limit" (25+ miles) - user wants all results regardless of distance
        $having_clause = '';
        if ($filters['radius'] < 25) {
            $having_clause = $wpdb->prepare("HAVING distance_miles <= %f", $filters['radius']);
        }

        // ORDER BY closest properties first
        $order_clause = "ORDER BY distance_miles ASC";

        // LIMIT results
        $limit_clause = $wpdb->prepare("LIMIT %d", $filters['limit']);

        $query = $select . $where_clause . " " . $having_clause . " " . $order_clause . " " . $limit_clause;

        return $query;
    }


    /**
     * Get ORDER BY clause
     */
    private function get_order_by($sort_by) {
        switch ($sort_by) {
            case 'price':
                return 'ORDER BY COALESCE(close_price, list_price) ASC';
            case 'distance':
                return 'ORDER BY distance_miles ASC';
            case 'date':
                return 'ORDER BY COALESCE(close_date, original_entry_timestamp) DESC';
            case 'similarity':
            default:
                return 'ORDER BY distance_miles ASC'; // Will re-sort by score after processing
        }
    }

    /**
     * Process a comparable property with adjustments
     * @since 6.10.6 - Added FHA-style adjustment validation with warnings
     * @since 6.68.23 - Added price validation to prevent silent failures
     */
    private function process_comparable($property, $subject, $filters) {
        $price = !empty($property['close_price']) ? $property['close_price'] : $property['list_price'];

        // v6.68.23: Validate price exists before processing
        // Without a price, adjustment caps become 0 and calculations silently fail
        if (empty($price) || $price <= 0) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[MLD Comparable Sales] Skipping comparable ' . ($property['listing_id'] ?? 'unknown') . ' - no valid price');
            }
            return null;
        }

        // Calculate adjustments
        $adjustments = $this->calculate_adjustments($property, $subject);

        // Validate adjustments against FHA/Fannie Mae thresholds
        // This applies caps and generates warnings for extreme adjustments
        $validation = $this->validate_adjustments($adjustments, $price);
        $adjustments = $validation['adjustments'];

        // Calculate adjusted price (using validated/capped adjustments)
        $adjusted_price = $price + $adjustments['total_adjustment'];

        // Calculate comparability score
        $score = $this->calculate_comparability_score($property, $subject, $adjustments);

        // Get grade
        $grade = $this->get_comparability_grade($score);

        // Calculate price per sqft
        $price_per_sqft = 0;
        $adjusted_price_per_sqft = 0;
        if (!empty($property['building_area_total']) && $property['building_area_total'] > 0) {
            $price_per_sqft = round($price / $property['building_area_total'], 2);
            $adjusted_price_per_sqft = round($adjusted_price / $property['building_area_total'], 2);
        }

        // Calculate subject property price per sqft for comparison
        $subject_price_per_sqft = 0;
        if (!empty($subject['sqft']) && $subject['sqft'] > 0 && !empty($subject['price'])) {
            $subject_price_per_sqft = round($subject['price'] / $subject['sqft'], 2);
        }

        $price_per_sqft_diff = $price_per_sqft - $subject_price_per_sqft;
        $price_per_sqft_diff_pct = $subject_price_per_sqft > 0 ? round(($price_per_sqft_diff / $subject_price_per_sqft) * 100, 1) : 0;

        return array(
            'listing_id' => $property['listing_id'],
            'property_url' => home_url('/property/' . $property['listing_id'] . '/'),
            'unparsed_address' => $property['unparsed_address'] ?: trim($property['street_number'] . ' ' . $property['street_name']),
            'city' => $property['city'],
            'state' => $property['state'],
            'postal_code' => $property['postal_code'],
            'latitude' => $property['latitude'],
            'longitude' => $property['longitude'],
            'list_price' => $property['list_price'],
            'close_price' => $property['close_price'],
            'adjusted_price' => round($adjusted_price, 0),
            'price_per_sqft' => $price_per_sqft,
            'adjusted_price_per_sqft' => $adjusted_price_per_sqft,
            'price_per_sqft_diff' => $price_per_sqft_diff,
            'price_per_sqft_diff_pct' => $price_per_sqft_diff_pct,
            'standard_status' => $property['standard_status'],
            'close_date' => $property['close_date'],
            'days_on_market' => $property['days_on_market'],
            'bedrooms_total' => $property['bedrooms_total'],
            'bathrooms_total' => $property['bathrooms_total_decimal'] ?? 0,
            'building_area_total' => $property['building_area_total'],
            'lot_size_acres' => $property['lot_size_acres'],
            'year_built' => $property['year_built'],
            'garage_spaces' => $property['garage_spaces'],
            'pool_private_yn' => $property['pool_private_yn'],
            'waterfront_yn' => $property['waterfront_yn'],
            'association_fee' => $property['association_fee'],
            'photos_count' => $property['photos_count'],
            'main_photo_url' => $property['main_photo_url'],
            'distance_miles' => round($property['distance_miles'], 2),
            'road_type' => $property['road_type'] ?? null,
            'property_condition' => $property['property_condition'] ?? null,
            'adjustments' => $adjustments,
            'adjustment_warnings' => $validation['warnings'],
            'adjustment_gross_pct' => $validation['gross_pct'],
            'adjustment_net_pct' => $validation['net_pct'],
            'has_adjustment_warnings' => $validation['has_warnings'],
            'has_adjustment_caps' => $validation['has_caps'],
            'comparability_score' => $score,
            'comparability_grade' => $grade,
            // Weight for weighted averaging (v6.19.0)
            // Default weight calculated from comparability score: A=2.0, B=1.5, C=1.0, D=0.5, F=0.25
            'weight' => $this->calculate_default_weight($score, $grade),
            'weight_override' => null  // Allows manual weight override from UI
        );
    }

    /**
     * Calculate default weight based on comparability score and grade
     *
     * Weight scale:
     * - A grade (85-100): 2.0x weight (most reliable)
     * - B grade (70-84): 1.5x weight (very reliable)
     * - C grade (55-69): 1.0x weight (baseline)
     * - D grade (40-54): 0.5x weight (less reliable)
     * - F grade (0-39): 0.25x weight (least reliable)
     *
     * @since 6.19.0
     * @param float $score Comparability score (0-100)
     * @param string $grade Comparability grade (A-F)
     * @return float Weight multiplier (0.25-2.0)
     */
    private function calculate_default_weight($score, $grade) {
        switch ($grade) {
            case 'A':
                return 2.0;
            case 'B':
                return 1.5;
            case 'C':
                return 1.0;
            case 'D':
                return 0.5;
            case 'F':
            default:
                return 0.25;
        }
    }

    /**
     * Calculate feature-based adjustments
     */
    private function calculate_adjustments($comp, $subject) {
        $adjustments = array();
        $total = 0;

        // Size adjustment (market-driven $/sqft with diminishing returns)
        // @since 6.10.6 - Added diminishing returns tiers and 10% cap
        if (!empty($comp['building_area_total']) && !empty($subject['sqft'])) {
            $sqft_diff = $comp['building_area_total'] - $subject['sqft'];
            $price_per_sqft = $this->market_data['price_per_sqft'] ?? 350;
            $abs_diff = abs($sqft_diff);

            // Get comp price for cap calculation
            $comp_price = !empty($comp['close_price']) ? $comp['close_price'] : $comp['list_price'];

            // Apply diminishing returns: first 200sqft at full value, then 75%, then 50%
            // This reflects reality: adding more sqft doesn't add value linearly
            $tier1_sqft = min($abs_diff, 200);
            $tier2_sqft = max(0, min($abs_diff - 200, 300));
            $tier3_sqft = max(0, $abs_diff - 500);

            $tier1_value = $tier1_sqft * $price_per_sqft * 1.00;  // First 200 sqft at 100%
            $tier2_value = $tier2_sqft * $price_per_sqft * 0.75;  // Next 300 sqft at 75%
            $tier3_value = $tier3_sqft * $price_per_sqft * 0.50;  // Remaining at 50%

            $raw_adjustment = $tier1_value + $tier2_value + $tier3_value;

            // Apply 10% cap of property value
            $max_adjustment = $comp_price * self::SQFT_MAX_PCT;
            $capped = $raw_adjustment > $max_adjustment;
            $adjustment = min($raw_adjustment, $max_adjustment);

            // Apply direction (negative if comp is larger)
            if ($sqft_diff > 0) {
                $adjustment = -$adjustment;
            }

            // Build explanation
            $explanation = ($sqft_diff > 0 ? 'Larger' : 'Smaller') . ' by ' . abs($sqft_diff) . ' sqft @ $' . number_format($price_per_sqft, 0) . '/sqft';
            if ($abs_diff > 200) {
                $explanation .= ' (diminishing returns applied)';
            }
            if ($capped) {
                $explanation .= ' [capped at 10%]';
            }

            $adjustments[] = array(
                'feature' => 'Square Footage',
                'difference' => $sqft_diff . ' sqft',
                'adjustment' => round($adjustment, 0),
                'explanation' => $explanation,
                'diminishing_returns' => $abs_diff > 200,
                'capped' => $capped,
                'raw_adjustment' => $capped ? round($raw_adjustment, 0) : null
            );
            $total += $adjustment;
        }

        // Garage adjustment (percentage-based tiered pricing)
        // @since 6.10.6 - Now uses percentage of property value with bounds
        if (isset($comp['garage_spaces']) && isset($subject['garage_spaces'])) {
            $garage_diff = $comp['garage_spaces'] - $subject['garage_spaces'];
            if ($garage_diff != 0) {
                // Get comp price for percentage calculation
                $comp_price = !empty($comp['close_price']) ? $comp['close_price'] : $comp['list_price'];

                // Calculate tiered garage value using percentage-based scaling
                $garage_value = 0;
                $spaces_to_adjust = abs($garage_diff);

                // Use market data if available, otherwise use percentage-based defaults
                $first_pct = $this->market_data['garage_first_pct'] ?? self::GARAGE_FIRST_PCT;
                $add_pct = $this->market_data['garage_add_pct'] ?? self::GARAGE_ADD_PCT;

                for ($i = 0; $i < $spaces_to_adjust; $i++) {
                    if ($i == 0) {
                        // First space: 2.5% of property value, bounded $15k-$60k
                        $first_value = $comp_price * $first_pct;
                        $first_value = max(15000, min(60000, $first_value));
                        $garage_value += $first_value;
                    } else {
                        // Additional spaces: 1.5% of property value, bounded $10k-$40k
                        $add_value = $comp_price * $add_pct;
                        $add_value = max(10000, min(40000, $add_value));
                        $garage_value += $add_value;
                    }
                }

                // If comp has more spaces, adjustment is negative
                $adjustment = -($garage_diff > 0 ? $garage_value : -$garage_value);

                // Calculate effective values for display
                $first_display = max(15000, min(60000, $comp_price * $first_pct));
                $add_display = max(10000, min(40000, $comp_price * $add_pct));

                $adjustments[] = array(
                    'feature' => 'Garage Spaces',
                    'difference' => abs($garage_diff) . ' space' . (abs($garage_diff) > 1 ? 's' : ''),
                    'adjustment' => round($adjustment, 0),
                    'explanation' => ($garage_diff > 0 ? 'More' : 'Fewer') . ' garage spaces @ $' . number_format($first_display, 0) . '/first + $' . number_format($add_display, 0) . '/add\'l'
                );
                $total += $adjustment;
            }
        }

        // Year built adjustment (percentage-based with cap)
        // @since 6.10.6 - Now uses percentage of property value with 20-year max cap
        if (!empty($comp['year_built']) && !empty($subject['year_built'])) {
            $age_diff = $comp['year_built'] - $subject['year_built'];
            if (abs($age_diff) > 5) {
                // Get comp price for percentage calculation
                $comp_price = !empty($comp['close_price']) ? $comp['close_price'] : $comp['list_price'];

                // Cap years at maximum (default 20 years)
                $years_to_adjust = min(abs($age_diff), self::YEAR_BUILT_MAX_YEARS);

                // Calculate percentage-based adjustment (0.4% per year default)
                $year_pct = $this->market_data['year_built_pct'] ?? self::YEAR_BUILT_PCT;
                $raw_adjustment = $years_to_adjust * ($comp_price * $year_pct);

                // Apply maximum cap (10% of property value)
                $max_adjustment = $comp_price * self::YEAR_BUILT_MAX_PCT;
                $adjustment = min($raw_adjustment, $max_adjustment);

                // Apply direction (negative if comp is newer)
                if ($age_diff > 0) {
                    $adjustment = -$adjustment;
                }

                // Calculate effective per-year value for display
                $effective_year_value = $comp_price * $year_pct;

                // Build explanation showing if capped
                $explanation = 'Built in ' . $comp['year_built'] . ' vs ' . $subject['year_built'];
                if (abs($age_diff) > self::YEAR_BUILT_MAX_YEARS) {
                    $explanation .= ' (capped at ' . self::YEAR_BUILT_MAX_YEARS . ' years)';
                }
                $explanation .= ' @ ' . number_format($year_pct * 100, 1) . '%/year';

                $adjustments[] = array(
                    'feature' => 'Year Built',
                    'difference' => abs($age_diff) . ' years ' . ($age_diff > 0 ? 'newer' : 'older'),
                    'adjustment' => round($adjustment, 0),
                    'explanation' => $explanation,
                    'years_capped' => abs($age_diff) > self::YEAR_BUILT_MAX_YEARS,
                    'original_years' => abs($age_diff),
                    'adjusted_years' => $years_to_adjust
                );
                $total += $adjustment;
            }
        }

        // Pool adjustment (market-driven pool value)
        if (isset($comp['pool_private_yn']) && isset($subject['pool'])) {
            // Convert to integers for comparison (handle Y/N, true/false, 1/0)
            $comp_has_pool = (in_array($comp['pool_private_yn'], ['Y', 'Yes', true, 1, '1'], true)) ? 1 : 0;
            $subject_has_pool = (in_array($subject['pool'], ['Y', 'Yes', true, 1, '1'], true)) ? 1 : 0;
            $has_pool_diff = $comp_has_pool - $subject_has_pool;
            if ($has_pool_diff != 0) {
                $pool_value = $this->market_data['pool'] ?? 50000; // Market-driven pool value
                $adjustment = -($has_pool_diff * $pool_value);

                $adjustments[] = array(
                    'feature' => 'Pool',
                    'difference' => $has_pool_diff > 0 ? 'Has pool' : 'No pool',
                    'adjustment' => round($adjustment, 0),
                    'explanation' => ($has_pool_diff > 0 ? 'Property has' : 'Property lacks') . ' private pool ($' . number_format($pool_value, 0) . ' value)'
                );
                $total += $adjustment;
            }
        }

        // Bedroom adjustment (percentage-based scaling)
        // @since 6.10.6 - Now uses 2-3% of property value per bedroom
        if (isset($comp['bedrooms_total']) && isset($subject['beds'])) {
            $bed_diff = $comp['bedrooms_total'] - $subject['beds'];
            if ($bed_diff != 0) {
                // Get comp price for percentage calculation
                $comp_price = !empty($comp['close_price']) ? $comp['close_price'] : $comp['list_price'];

                // Calculate percentage-based value (2-3% of property value)
                // Use market data if available, otherwise use constant default
                $bed_pct = $this->market_data['bedroom_pct'] ?? ((self::BEDROOM_PCT_MIN + self::BEDROOM_PCT_MAX) / 2);
                $bed_value = $comp_price * $bed_pct;

                // Apply floor and ceiling bounds ($15k-$75k per bedroom)
                $bed_value = max(15000, min(75000, $bed_value));

                $adjustment = -($bed_diff * $bed_value);

                $adjustments[] = array(
                    'feature' => 'Bedrooms',
                    'difference' => abs($bed_diff) . ' bedroom' . (abs($bed_diff) > 1 ? 's' : ''),
                    'adjustment' => round($adjustment, 0),
                    'explanation' => ($bed_diff > 0 ? 'More' : 'Fewer') . ' bedrooms @ $' . number_format($bed_value, 0) . '/bed (' . number_format($bed_pct * 100, 1) . '%)'
                );
                $total += $adjustment;
            }
        }

        // Bathroom adjustment (percentage-based scaling)
        // @since 6.10.6 - Now uses 0.66-1% of property value per bathroom
        if (isset($comp['bathrooms_total_decimal']) && isset($subject['baths'])) {
            $bath_diff = $comp['bathrooms_total_decimal'] - $subject['baths'];
            if (abs($bath_diff) >= 0.5) {
                // Get comp price for percentage calculation
                $comp_price = !empty($comp['close_price']) ? $comp['close_price'] : $comp['list_price'];

                // Calculate percentage-based value (0.66-1% of property value)
                $bath_pct = $this->market_data['bathroom_pct'] ?? ((self::BATHROOM_PCT_MIN + self::BATHROOM_PCT_MAX) / 2);
                $bath_value = $comp_price * $bath_pct;

                // Apply floor and ceiling bounds ($5k-$30k per bathroom)
                $bath_value = max(5000, min(30000, $bath_value));

                $adjustment = -($bath_diff * $bath_value);

                $adjustments[] = array(
                    'feature' => 'Bathrooms',
                    'difference' => abs($bath_diff) . ' bath' . (abs($bath_diff) > 1 ? 's' : ''),
                    'adjustment' => round($adjustment, 0),
                    'explanation' => ($bath_diff > 0 ? 'More' : 'Fewer') . ' bathrooms @ $' . number_format($bath_value, 0) . '/bath (' . number_format($bath_pct * 100, 2) . '%)'
                );
                $total += $adjustment;
            }
        }

        // Waterfront adjustment (market-driven waterfront premium)
        if (isset($comp['waterfront_yn']) && isset($subject['waterfront'])) {
            $waterfront_diff = ($comp['waterfront_yn'] === 'Yes' ? 1 : 0) - $subject['waterfront'];
            if ($waterfront_diff != 0) {
                $waterfront_value = $this->market_data['waterfront'] ?? 200000; // Market-driven waterfront value
                $adjustment = -($waterfront_diff * $waterfront_value);

                $adjustments[] = array(
                    'feature' => 'Waterfront',
                    'difference' => $waterfront_diff > 0 ? 'Has waterfront' : 'No waterfront',
                    'adjustment' => round($adjustment, 0),
                    'explanation' => ($waterfront_diff > 0 ? 'Property is' : 'Property is not') . ' on waterfront ($' . number_format($waterfront_value, 0) . ' premium)'
                );
                $total += $adjustment;
            }
        }

        // Location adjustment (market-driven distance penalty)
        if (isset($comp['distance_miles'])) {
            // Slight adjustment for distance (properties further away may be in different micro-markets)
            if ($comp['distance_miles'] > 1) {
                $location_rate = $this->market_data['location_rate'] ?? 5000; // Market-driven per-mile penalty (default $5000/mile)
                $distance_penalty = min($comp['distance_miles'] * $location_rate, $location_rate * 5); // Max 5-mile penalty
                $adjustment = -$distance_penalty;

                $adjustments[] = array(
                    'feature' => 'Location',
                    'difference' => round($comp['distance_miles'], 1) . ' miles away',
                    'adjustment' => round($adjustment, 0),
                    'explanation' => 'Distance from subject property @ $' . number_format($location_rate, 0) . '/mile'
                );
                $total += $adjustment;
            }
        }

        // Road type adjustment (percentage-based)
        // @since 6.10.6 - Reduced default from 25% to 5% (more conservative)
        // Only calculate if subject has road type set and it's not unknown
        if (!empty($subject['road_type']) && $subject['road_type'] !== 'unknown') {
            // Get road type discount from admin settings (default 5%, reduced from 25%)
            $road_type_premium = floatval(get_option('mld_cma_road_type_discount', self::ROAD_TYPE_DEFAULT_PCT * 100));

            // Define road type hierarchy values (percentage impact on value)
            // Unknown/Main road is baseline (0%), neighborhood road is premium
            // Neighborhood roads are MORE desirable (quieter, safer, less traffic)
            $road_type_values = array(
                'unknown' => 0,                      // Unknown - baseline (no data yet)
                'main_road' => 0,                    // Main road - baseline
                'neighborhood_road' => $road_type_premium,   // Neighborhood road premium (from admin settings)
                '' => 0,                             // Empty - treat as unknown
                null => 0                            // Null - treat as unknown
            );

            // If comp doesn't have road type, assume baseline (0)
            $comp_road_type = !empty($comp['road_type']) ? $comp['road_type'] : '';
            $comp_road_value = isset($road_type_values[$comp_road_type]) ? $road_type_values[$comp_road_type] : 0;
            $subject_road_value = isset($road_type_values[$subject['road_type']]) ? $road_type_values[$subject['road_type']] : 0;

            // Skip adjustment if comparable has unknown/empty road type
            $comp_road_known = !empty($comp['road_type']) && $comp['road_type'] !== 'unknown';

            $road_diff = $comp_road_value - $subject_road_value;

            if ($road_diff != 0 && $comp_road_known) {
                // Get the comparable's price to calculate percentage
                $comp_price = !empty($comp['close_price']) ? $comp['close_price'] : $comp['list_price'];

                // Calculate percentage adjustment
                $percentage = $road_diff / 100;
                $adjustment = -($comp_price * $percentage);

                $road_type_labels = array(
                    'main_road' => 'Main Road',
                    'neighborhood_road' => 'Neighborhood Road',
                    '' => 'Unknown',
                    null => 'Unknown'
                );

                $comp_label = isset($road_type_labels[$comp_road_type]) ? $road_type_labels[$comp_road_type] : ucfirst(str_replace('_', ' ', $comp_road_type));
                $subject_label = isset($road_type_labels[$subject['road_type']]) ? $road_type_labels[$subject['road_type']] : ucfirst(str_replace('_', ' ', $subject['road_type']));

                $adjustments[] = array(
                    'feature' => 'Road Type',
                    'difference' => $comp_label . ' vs ' . $subject_label,
                    'adjustment' => round($adjustment, 0),
                    'explanation' => $comp_label . ' (' . ($road_diff > 0 ? '+' : '') . $road_diff . '%) vs ' . $subject_label
                );
                $total += $adjustment;
            }
        }

        // Condition adjustment (percentage-based with value scale)
        // Only calculate if subject has property condition set and it's not unknown
        if (!empty($subject['property_condition']) && $subject['property_condition'] !== 'unknown') {
            // Define condition value scale (percentage impact on value)
            $condition_values = array(
                'unknown' => 0,             // Unknown/baseline
                'new' => 20,
                'fully_renovated' => 12,
                'some_updates' => 0,        // Baseline
                'needs_updating' => -12,
                'distressed' => -30,
                '' => 0,                    // Unknown/baseline
                null => 0                   // Unknown/baseline
            );

            // If comp doesn't have condition, assume baseline (0)
            $comp_condition = !empty($comp['property_condition']) ? $comp['property_condition'] : '';
            $comp_condition_value = isset($condition_values[$comp_condition]) ? $condition_values[$comp_condition] : 0;
            $subject_condition_value = isset($condition_values[$subject['property_condition']]) ? $condition_values[$subject['property_condition']] : 0;

            // Skip adjustment if comparable has unknown/empty property condition
            $comp_condition_known = !empty($comp['property_condition']) && $comp['property_condition'] !== 'unknown';

            $condition_diff = $comp_condition_value - $subject_condition_value;

            if ($condition_diff != 0 && $comp_condition_known) {
                // Get the comparable's price to calculate percentage
                $comp_price = !empty($comp['close_price']) ? $comp['close_price'] : $comp['list_price'];

                // Calculate percentage adjustment
                $percentage = $condition_diff / 100;
                $adjustment = -($comp_price * $percentage);

                $condition_labels = array(
                    'new' => 'New Construction',
                    'fully_renovated' => 'Fully Renovated',
                    'some_updates' => 'Some Updates',
                    'needs_updating' => 'Needs Updating',
                    'distressed' => 'Distressed',
                    '' => 'Unknown',
                    null => 'Unknown'
                );

                $comp_label = isset($condition_labels[$comp_condition]) ? $condition_labels[$comp_condition] : ucfirst(str_replace('_', ' ', $comp_condition));
                $subject_label = isset($condition_labels[$subject['property_condition']]) ? $condition_labels[$subject['property_condition']] : ucfirst(str_replace('_', ' ', $subject['property_condition']));

                $adjustments[] = array(
                    'feature' => 'Condition',
                    'difference' => $comp_label . ' vs ' . $subject_label,
                    'adjustment' => round($adjustment, 0),
                    'explanation' => $comp_label . ' (' . ($condition_diff > 0 ? '+' : '') . $condition_diff . '%) vs ' . $subject_label
                );
                $total += $adjustment;
            }
        }

        // TIME-BASED MARKET ADJUSTMENT
        // Adjust for market appreciation/depreciation between comp sale date and current date
        if (!empty($comp['close_date']) && $comp['standard_status'] === 'Closed') {
            $time_adjustment_result = $this->calculate_time_adjustment($comp, $subject);
            if ($time_adjustment_result['adjustment'] != 0) {
                $adjustments[] = $time_adjustment_result;
                $total += $time_adjustment_result['adjustment'];
            }
        }

        return array(
            'items' => $adjustments,
            'total_adjustment' => round($total, 0)
        );
    }

    /**
     * Validate adjustments against FHA/Fannie Mae thresholds
     *
     * Applies warning flags and hard caps to prevent extreme adjustments.
     * Based on industry guidelines: 10% individual, 15% net, 25% gross thresholds.
     *
     * @since 6.10.6
     * @param array $adjustments The adjustments array from calculate_adjustments()
     * @param float $comp_price The comparable property's price
     * @return array Validated adjustments with warnings and caps applied
     */
    private function validate_adjustments($adjustments, $comp_price) {
        if ($comp_price <= 0) {
            return array(
                'adjustments' => $adjustments,
                'warnings' => array(),
                'gross_pct' => 0,
                'net_pct' => 0,
                'has_warnings' => false,
                'has_caps' => false
            );
        }

        $warnings = array();
        $gross_total = 0;
        $net_total = 0;
        $has_caps = false;

        // Process each individual adjustment
        foreach ($adjustments['items'] as $key => &$adj) {
            $adj_amount = abs($adj['adjustment']);
            $adj_pct = $adj_amount / $comp_price;
            $gross_total += $adj_amount;
            $net_total += $adj['adjustment'];

            // Individual adjustment warning (>10% of comp price)
            if ($adj_pct > self::INDIVIDUAL_ADJ_WARNING_THRESHOLD) {
                $adj['warning'] = sprintf(
                    'Large adjustment: %.1f%% of comp price (FHA threshold: %.0f%%)',
                    $adj_pct * 100,
                    self::INDIVIDUAL_ADJ_WARNING_THRESHOLD * 100
                );
                $adj['warning_level'] = 'caution';
                $warnings[] = array(
                    'type' => 'individual',
                    'feature' => $adj['feature'],
                    'message' => $adj['warning'],
                    'pct' => round($adj_pct * 100, 1)
                );
            }

            // Apply hard cap if exceeded (>20% of comp price)
            if ($adj_pct > self::INDIVIDUAL_ADJ_HARD_CAP) {
                $max_adj = $comp_price * self::INDIVIDUAL_ADJ_HARD_CAP;
                $adj['original_adjustment'] = $adj['adjustment'];
                $adj['adjustment'] = ($adj['adjustment'] > 0) ? $max_adj : -$max_adj;
                $adj['capped'] = true;
                $adj['cap_warning'] = sprintf(
                    'Capped from $%s to $%s (%.0f%% max)',
                    number_format(abs($adj['original_adjustment'])),
                    number_format(abs($adj['adjustment'])),
                    self::INDIVIDUAL_ADJ_HARD_CAP * 100
                );
                $adj['warning_level'] = 'capped';
                $has_caps = true;

                // Recalculate totals with capped value
                $gross_total = $gross_total - $adj_amount + abs($adj['adjustment']);
                $net_total = $net_total - $adj['original_adjustment'] + $adj['adjustment'];
            }
        }
        unset($adj); // Break reference

        // Recalculate total_adjustment after any caps
        $new_total = 0;
        foreach ($adjustments['items'] as $adj) {
            $new_total += $adj['adjustment'];
        }
        $adjustments['total_adjustment'] = round($new_total, 0);

        // Gross adjustment warning (sum of absolute values >25%)
        $gross_pct = $gross_total / $comp_price;
        if ($gross_pct > self::GROSS_ADJ_WARNING_THRESHOLD) {
            $warnings[] = array(
                'type' => 'gross',
                'feature' => 'Total Gross',
                'message' => sprintf(
                    'High gross adjustments: %.1f%% (FHA threshold: %.0f%%)',
                    $gross_pct * 100,
                    self::GROSS_ADJ_WARNING_THRESHOLD * 100
                ),
                'pct' => round($gross_pct * 100, 1)
            );
        }

        // Net adjustment warning (>15%)
        $net_pct = abs($net_total) / $comp_price;
        if ($net_pct > self::NET_ADJ_WARNING_THRESHOLD) {
            $warnings[] = array(
                'type' => 'net',
                'feature' => 'Total Net',
                'message' => sprintf(
                    'High net adjustment: %.1f%% (FHA threshold: %.0f%%)',
                    $net_pct * 100,
                    self::NET_ADJ_WARNING_THRESHOLD * 100
                ),
                'pct' => round($net_pct * 100, 1)
            );
        }

        return array(
            'adjustments' => $adjustments,
            'warnings' => $warnings,
            'gross_pct' => round($gross_pct * 100, 1),
            'net_pct' => round($net_pct * 100, 1),
            'has_warnings' => !empty($warnings),
            'has_caps' => $has_caps
        );
    }

    /**
     * Calculate comparability score (0-100)
     */
    /**
     * Calculate time-based market adjustment
     *
     * Adjusts comparable prices for market appreciation/depreciation between
     * the sale date and current date using market trend data.
     *
     * @param array $comp Comparable property data
     * @param array $subject Subject property data
     * @return array Adjustment data (feature, difference, adjustment, explanation)
     */
    private function calculate_time_adjustment($comp, $subject) {
        $adjustment_data = array(
            'feature' => 'Time/Market Trend',
            'difference' => '',
            'adjustment' => 0,
            'explanation' => ''
        );

        // Only apply to closed sales (not active listings)
        if (empty($comp['close_date']) || $comp['standard_status'] !== 'Closed') {
            return $adjustment_data;
        }

        // Calculate months since sale
        $close_timestamp = strtotime($comp['close_date']);
        $current_timestamp = time();
        $months_ago = round(($current_timestamp - $close_timestamp) / (60 * 60 * 24 * 30));

        // Don't adjust if sale is very recent (< 1 month)
        if ($months_ago < 1) {
            return $adjustment_data;
        }

        // Get market appreciation rate from settings (default: 0.5% per month = 6% annual)
        // This can be customized per market area in future versions
        $annual_appreciation_rate = floatval(get_option('mld_cma_annual_appreciation_rate', 6.0));
        $monthly_rate = $annual_appreciation_rate / 12 / 100; // Convert to monthly decimal

        // Cap at 24 months to avoid excessive adjustments
        $months_to_adjust = min($months_ago, 24);

        // Get comp price
        $comp_price = !empty($comp['close_price']) ? $comp['close_price'] : $comp['list_price'];

        // Calculate appreciation/depreciation adjustment
        // Positive rate = market went up since sale, so comp should be adjusted UP
        $adjustment = $comp_price * ($monthly_rate * $months_to_adjust);

        $adjustment_data['difference'] = $months_ago . ' month' . ($months_ago > 1 ? 's' : '') . ' ago';
        $adjustment_data['adjustment'] = round($adjustment, 0);

        $rate_display = number_format($annual_appreciation_rate, 1);
        $adjustment_data['explanation'] = 'Market appreciation since sale date (' . $rate_display . '% annual rate)';

        return $adjustment_data;
    }

    private function calculate_comparability_score($comp, $subject, $adjustments) {
        $score = 100;

        // Distance penalty (closer is better)
        $distance = $comp['distance_miles'] ?? 0;
        $score -= min($distance * 5, 20); // Max 20 point penalty

        // Size similarity (closer in size is better)
        if (!empty($comp['building_area_total']) && !empty($subject['sqft'])) {
            $size_diff_pct = abs($comp['building_area_total'] - $subject['sqft']) / $subject['sqft'] * 100;
            $score -= min($size_diff_pct / 2, 15); // Max 15 point penalty
        }

        // Age similarity
        if (!empty($comp['year_built']) && !empty($subject['year_built'])) {
            $age_diff = abs($comp['year_built'] - $subject['year_built']);
            $score -= min($age_diff / 2, 10); // Max 10 point penalty
        }

        // Bed/bath match
        if (isset($comp['bedrooms_total']) && isset($subject['beds'])) {
            if ($comp['bedrooms_total'] == $subject['beds']) {
                $score += 5; // Bonus for exact match
            } else {
                $score -= abs($comp['bedrooms_total'] - $subject['beds']) * 3;
            }
        }

        // Recent sale bonus (for closed properties)
        if ($comp['standard_status'] === 'Closed' && !empty($comp['close_date'])) {
            $days_ago = (strtotime('now') - strtotime($comp['close_date'])) / (60 * 60 * 24);
            if ($days_ago < 90) {
                $score += 10; // Recent sale bonus
            } else if ($days_ago > 365) {
                $score -= 5; // Older sale penalty
            }
        }

        // Total adjustment magnitude penalty (larger adjustments = less comparable)
        // v6.68.23: Removed /2 divisor to better differentiate low-quality comps
        // 30% adjustment now gets 20pt penalty vs 50% also getting 20pt (was both 15pt)
        $adjustment_magnitude = abs($adjustments['total_adjustment']);
        $comp_price = !empty($comp['close_price']) ? $comp['close_price'] : $comp['list_price'];
        if ($comp_price > 0) {
            $adjustment_pct = ($adjustment_magnitude / $comp_price) * 100;
            $score -= min($adjustment_pct, 20); // Max 20 point penalty, direct percentage
        }

        return max(0, min(100, round($score, 1)));
    }

    /**
     * Get letter grade from score
     */
    private function get_comparability_grade($score) {
        if ($score >= 90) return 'A';
        if ($score >= 80) return 'B';
        if ($score >= 70) return 'C';
        if ($score >= 60) return 'D';
        return 'F';
    }

    /**
     * Calculate summary statistics
     */
    private function calculate_summary($comparables, $subject) {
        if (empty($comparables)) {
            return array();
        }

        $prices = array_column($comparables, 'price');
        $adjusted_prices = array_column($comparables, 'adjusted_price');

        // Get top comparables (A/B grades only)
        $top_comps = array_filter($comparables, function($c) {
            return in_array($c['comparability_grade'], array('A', 'B'));
        });

        // Calculate unweighted (simple) average
        if (!empty($top_comps)) {
            $top_prices = array_column($top_comps, 'adjusted_price');
            $estimated_low = min($top_prices);
            $estimated_high = max($top_prices);
            $estimated_mid_unweighted = array_sum($top_prices) / count($top_prices);
        } else {
            $estimated_low = min($adjusted_prices);
            $estimated_high = max($adjusted_prices);
            $estimated_mid_unweighted = array_sum($adjusted_prices) / count($adjusted_prices);
        }

        // Calculate weighted average (v6.19.0)
        // Uses comparability-based weights: A=2.0x, B=1.5x, C=1.0x, D=0.5x, F=0.25x
        $weighted_sum = 0;
        $weight_total = 0;
        $weight_breakdown = array();

        $comps_to_weight = !empty($top_comps) ? $top_comps : $comparables;
        foreach ($comps_to_weight as $comp) {
            // Use override weight if set, otherwise use default weight
            $weight = isset($comp['weight_override']) && $comp['weight_override'] !== null
                ? floatval($comp['weight_override'])
                : floatval($comp['weight']);

            $weighted_sum += $comp['adjusted_price'] * $weight;
            $weight_total += $weight;

            // Track weight breakdown for transparency
            $weight_breakdown[] = array(
                'listing_id' => $comp['listing_id'],
                'address' => $comp['unparsed_address'],
                'grade' => $comp['comparability_grade'],
                'weight' => $weight,
                'is_override' => isset($comp['weight_override']) && $comp['weight_override'] !== null,
                'adjusted_price' => $comp['adjusted_price'],
                'weighted_contribution' => $comp['adjusted_price'] * $weight
            );
        }

        // Calculate weighted mid value
        $estimated_mid_weighted = $weight_total > 0 ? $weighted_sum / $weight_total : $estimated_mid_unweighted;

        // Determine which value to use as primary (weighted by default)
        $estimated_mid = $estimated_mid_weighted;

        $comp_count = count($comparables);
        $price_count = count($prices);

        // Calculate median values
        $median_price = 0;
        $median_adjusted_price = 0;
        if ($price_count > 0) {
            $sorted_prices = $prices;
            sort($sorted_prices);
            $mid = floor($price_count / 2);
            $median_price = $price_count % 2 == 0
                ? round(($sorted_prices[$mid - 1] + $sorted_prices[$mid]) / 2, 0)
                : round($sorted_prices[$mid], 0);
        }
        if ($comp_count > 0) {
            $sorted_adjusted = $adjusted_prices;
            sort($sorted_adjusted);
            $mid = floor($comp_count / 2);
            $median_adjusted_price = $comp_count % 2 == 0
                ? round(($sorted_adjusted[$mid - 1] + $sorted_adjusted[$mid]) / 2, 0)
                : round($sorted_adjusted[$mid], 0);
        }

        // Calculate price per sqft statistics
        $price_per_sqft_values = array_filter(array_column($comparables, 'price_per_sqft'));
        $avg_price_per_sqft = 0;
        $median_price_per_sqft = 0;
        $price_per_sqft_range = array('min' => 0, 'max' => 0);

        if (!empty($price_per_sqft_values)) {
            $avg_price_per_sqft = round(array_sum($price_per_sqft_values) / count($price_per_sqft_values), 2);
            $sorted_ppsf = $price_per_sqft_values;
            sort($sorted_ppsf);
            $mid = floor(count($sorted_ppsf) / 2);
            $median_price_per_sqft = count($sorted_ppsf) % 2 == 0
                ? round(($sorted_ppsf[$mid - 1] + $sorted_ppsf[$mid]) / 2, 2)
                : round($sorted_ppsf[$mid], 2);
            $price_per_sqft_range = array(
                'min' => round(min($price_per_sqft_values), 2),
                'max' => round(max($price_per_sqft_values), 2)
            );
        }

        // Calculate standard deviation for confidence
        $price_std_dev = 0;
        if ($comp_count > 1) {
            $avg = array_sum($adjusted_prices) / $comp_count;
            $variance = array_sum(array_map(function($p) use ($avg) {
                return pow($p - $avg, 2);
            }, $adjusted_prices)) / $comp_count;
            $price_std_dev = round(sqrt($variance), 0);
        }

        // Calculate comprehensive confidence using statistical analyzer
        $confidence_calculator = new MLD_CMA_Confidence_Calculator();
        $confidence_data = $confidence_calculator->calculate_confidence(
            $comparables,
            $subject,
            array(
                'price_std_dev' => $price_std_dev,
                'avg_adjusted_price' => $comp_count > 0 ? array_sum($adjusted_prices) / $comp_count : 0
            )
        );

        $confidence_score = $confidence_data['score'];
        $confidence_level = $confidence_data['level'];
        
        // Keep recent sales count for backward compatibility
        $recent_sales = array_filter($comparables, function($c) {
            if ($c['standard_status'] === 'Closed' && !empty($c['close_date'])) {
                $days_ago = (strtotime('now') - strtotime($c['close_date'])) / (60 * 60 * 24);
                return $days_ago < 180; // Within 6 months
            }
            return false;
        });

        return array(
            'total_found' => $comp_count,
            'top_comps_count' => count($top_comps),
            'avg_price' => $price_count > 0 ? round(array_sum($prices) / $price_count, 0) : 0,
            'median_price' => $median_price,
            'avg_adjusted_price' => $comp_count > 0 ? round(array_sum($adjusted_prices) / $comp_count, 0) : 0,
            'median_adjusted_price' => $median_adjusted_price,
            'price_std_dev' => $price_std_dev,
            'price_range' => array(
                'min' => $price_count > 0 ? min($prices) : 0,
                'max' => $price_count > 0 ? max($prices) : 0
            ),
            'price_per_sqft' => array(
                'avg' => $avg_price_per_sqft,
                'median' => $median_price_per_sqft,
                'range' => $price_per_sqft_range
            ),
            'estimated_value' => array(
                'low' => round($estimated_low, -3), // Round to nearest thousand
                'mid' => round($estimated_mid, -3),
                'high' => round($estimated_high, -3),
                // Weighted averaging data (v6.19.0)
                'mid_weighted' => round($estimated_mid_weighted, -3),
                'mid_unweighted' => round($estimated_mid_unweighted, -3),
                'weight_difference' => round($estimated_mid_weighted - $estimated_mid_unweighted, -3),
                'weight_total' => round($weight_total, 2),
                'weight_breakdown' => $weight_breakdown,
                'using_weighted' => true, // Indicates weighted value is primary
                'confidence' => $confidence_level,
                'confidence_score' => round($confidence_score, 1),
                'confidence_breakdown' => $confidence_data['breakdown'] ?? array(),
                'confidence_recommendations' => $confidence_data['recommendations'] ?? array(),
                'reliability' => $confidence_data['reliability_percentage'] ?? array()
            ),
            'avg_distance' => $comp_count > 0 ? round(array_sum(array_column($comparables, 'distance_miles')) / $comp_count, 2) : 0,
            'avg_comparability_score' => $comp_count > 0 ? round(array_sum(array_column($comparables, 'comparability_score')) / $comp_count, 1) : 0,
            'recent_sales_count' => count($recent_sales)
        );
    }

    /**
     * Load market data for adjustments
     * Uses intelligent market calculator for dynamic, data-driven adjustment values
     */
    private function load_market_data($city, $state, $property_type = 'all') {
        if ($this->market_data !== null) {
            return;
        }

        // Initialize calculator
        if ($this->calculator === null) {
            require_once plugin_dir_path(__FILE__) . 'class-mld-market-data-calculator.php';
            $this->calculator = new MLD_Market_Data_Calculator();
        }

        // Get all market-driven adjustment values
        $adjustments = $this->calculator->get_all_adjustments($city, $state, $property_type, 12);

        // Get market trends for context
        require_once plugin_dir_path(__FILE__) . 'class-mld-market-trends.php';
        $trends = new MLD_Market_Trends();
        $summary = $trends->get_market_summary($city, $state, 'all', 12);

        // Merge calculator data with trends data
        $this->market_data = array_merge($adjustments, array(
            'avg_close_price' => $summary['avg_close_price'] ?? 700000,
            'avg_dom' => $summary['avg_dom'] ?? 60,
            'avg_sp_lp_ratio' => $summary['avg_sp_lp_ratio'] ?? 100,
            'monthly_sales_velocity' => $summary['monthly_sales_velocity'] ?? 0
        ));

        // Add fallback defaults if calculator couldn't determine values
        // v6.68.23: Log when using defaults so admins have visibility into market data gaps
        $defaults_used = array();
        $defaults = array(
            'price_per_sqft' => 350,
            'garage_first' => 100000,
            'garage_additional' => 50000,
            'pool' => 50000,
            'bedroom' => 75000,
            'bathroom' => 25000,
            'waterfront' => 200000,
            'year_built_rate' => 25000,
            'location_rate' => 5000
        );

        foreach ($defaults as $key => $default_value) {
            if (!isset($this->market_data[$key]) || $this->market_data[$key] === null) {
                $this->market_data[$key] = $default_value;
                $defaults_used[] = $key;
            }
        }

        // Log warning if multiple defaults were used (indicates insufficient market data)
        if (count($defaults_used) >= 3) {
            error_log(sprintf(
                '[MLD Comparable Sales] Market data lookup incomplete for %s, %s. Using defaults for: %s',
                $city,
                $state,
                implode(', ', $defaults_used)
            ));
        }
    }

    /**
     * Get market context for display
     */
    public function get_market_context($city, $state) {
        $this->load_market_data($city, $state);

        require_once plugin_dir_path(__FILE__) . 'class-mld-market-trends.php';
        $trends = new MLD_Market_Trends();
        $summary = $trends->get_market_summary($city, $state, 'all', 12);

        $market_type = 'balanced';
        if ($summary['avg_sp_lp_ratio'] > 102) {
            $market_type = 'seller';
        } else if ($summary['avg_sp_lp_ratio'] < 98) {
            $market_type = 'buyer';
        }

        return array(
            'market_type' => $market_type,
            'avg_sp_lp_ratio' => $summary['avg_sp_lp_ratio'],
            'avg_dom' => $summary['avg_dom'],
            'avg_price_per_sqft' => $summary['avg_price_per_sqft'],
            'monthly_velocity' => $summary['monthly_sales_velocity'],
            'description' => $this->get_market_description($summary)
        );
    }

    /**
     * Get market description
     */
    private function get_market_description($summary) {
        $desc = array();

        if ($summary['avg_sp_lp_ratio'] > 102) {
            $desc[] = "Competitive seller's market with homes selling above asking";
        } else if ($summary['avg_sp_lp_ratio'] < 98) {
            $desc[] = "Buyer's market with negotiation opportunities";
        } else {
            $desc[] = "Balanced market conditions";
        }

        if ($summary['avg_dom'] < 30) {
            $desc[] = "properties moving quickly";
        } else if ($summary['avg_dom'] > 90) {
            $desc[] = "properties taking longer to sell";
        }

        $desc[] = "averaging " . round($summary['avg_dom']) . " days on market";

        return implode(', ', $desc);
    }

    /**
     * Get market forecast for the subject property
     *
     * @param array $subject_property Subject property data
     * @return array Forecast data
     */
    private function get_market_forecast($subject_property) {
        $city = $subject_property['city'] ?? '';
        $state = $subject_property['state'] ?? '';
        $property_type = $subject_property['property_type'] ?? 'all';

        // Load forecasting engine
        require_once plugin_dir_path(__FILE__) . 'class-mld-market-forecasting.php';
        $forecaster = new MLD_Market_Forecasting();

        // Get price forecast
        $price_forecast = $forecaster->get_price_forecast($city, $state, $property_type, 24, 12);

        // Get investment analysis if we have a subject price
        $investment = array();
        if (isset($subject_property['price']) && $subject_property['price'] > 0) {
            $investment = $forecaster->get_investment_analysis(
                $subject_property['price'],
                $city,
                $state,
                $property_type
            );
        }

        return array(
            'price_forecast' => $price_forecast,
            'investment_analysis' => $investment
        );
    }

    /**
     * Get subject property data formatted as a comparable
     *
     * @param array $subject Subject property basic data
     * @param array $filters Filter settings
     * @return array|null Formatted subject property or null if not found
     */
    private function get_subject_as_comparable($subject, $filters) {
        global $wpdb;

        $listing_id = $subject['listing_id'];
        if (empty($listing_id)) {
            return null;
        }

        // Try active tables first
        $query = $wpdb->prepare("
            SELECT DISTINCT
                l.listing_id,
                l.list_price,
                l.close_price,
                l.standard_status,
                l.property_type,
                l.property_sub_type,
                l.days_on_market,
                l.close_date,
                l.photos_count,
                l.original_entry_timestamp,
                loc.unparsed_address,
                loc.street_number,
                loc.street_name,
                loc.city,
                loc.state_or_province as state,
                loc.postal_code,
                loc.latitude,
                loc.longitude,
                ld.bedrooms_total,
                ld.bathrooms_total_decimal as bathrooms_total,
                ld.building_area_total,
                ld.lot_size_acres,
                ld.year_built,
                ld.garage_spaces,
                ld.parking_total,
                lf.pool_private_yn,
                lf.waterfront_yn,
                lfi.association_fee,
                lfi.association_yn,
                upd.road_type,
                upd.property_condition,
                (SELECT media_url FROM {$wpdb->prefix}bme_media
                 WHERE listing_id = l.listing_id
                 ORDER BY order_index ASC
                 LIMIT 1) as main_photo_url
            FROM {$wpdb->prefix}bme_listings l
            JOIN {$wpdb->prefix}bme_listing_location loc ON l.listing_id = loc.listing_id
            LEFT JOIN {$wpdb->prefix}bme_listing_details ld ON l.listing_id = ld.listing_id
            LEFT JOIN {$wpdb->prefix}bme_listing_features lf ON l.listing_id = lf.listing_id
            LEFT JOIN {$wpdb->prefix}bme_listing_financial lfi ON l.listing_id = lfi.listing_id
            LEFT JOIN {$wpdb->prefix}mld_user_property_data upd ON l.listing_id = upd.listing_id
            WHERE l.listing_id = %s
            LIMIT 1
        ", $listing_id);

        $property_data = $wpdb->get_row($query, ARRAY_A);

        // If not found in active, try archive
        if (!$property_data) {
            $query = $wpdb->prepare("
                SELECT DISTINCT
                    l.listing_id,
                    l.list_price,
                    l.close_price,
                    l.standard_status,
                    l.property_type,
                    l.property_sub_type,
                    l.days_on_market,
                    l.close_date,
                    l.photos_count,
                    l.original_entry_timestamp,
                    loc.unparsed_address,
                    loc.street_number,
                    loc.street_name,
                    loc.city,
                    loc.state_or_province as state,
                    loc.postal_code,
                    loc.latitude,
                    loc.longitude,
                    ld.bedrooms_total,
                    ld.bathrooms_total_decimal as bathrooms_total,
                    ld.building_area_total,
                    ld.lot_size_acres,
                    ld.year_built,
                    ld.garage_spaces,
                    ld.parking_total,
                    lf.pool_private_yn,
                    lf.waterfront_yn,
                    lfi.association_fee,
                    lfi.association_yn,
                    upd.road_type,
                    upd.property_condition,
                    (SELECT media_url FROM {$wpdb->prefix}bme_media
                     WHERE listing_id = l.listing_id
                     ORDER BY order_index ASC
                     LIMIT 1) as main_photo_url
                FROM {$wpdb->prefix}bme_listings_archive l
                JOIN {$wpdb->prefix}bme_listing_location_archive loc ON l.listing_id = loc.listing_id
                LEFT JOIN {$wpdb->prefix}bme_listing_details_archive ld ON l.listing_id = ld.listing_id
                LEFT JOIN {$wpdb->prefix}bme_listing_features_archive lf ON l.listing_id = lf.listing_id
                LEFT JOIN {$wpdb->prefix}bme_listing_financial_archive lfi ON l.listing_id = lfi.listing_id
                LEFT JOIN {$wpdb->prefix}mld_user_property_data upd ON l.listing_id = upd.listing_id
                WHERE l.listing_id = %s
                LIMIT 1
            ", $listing_id);

            $property_data = $wpdb->get_row($query, ARRAY_A);
        }

        if (!$property_data) {
            return null;
        }

        // Add distance of 0 for subject property
        $property_data['distance_miles'] = 0;

        // Format subject property without calling process_comparable to avoid self-comparison
        // The subject property should not have adjustments since it's the baseline
        $price = !empty($property_data['close_price']) ? $property_data['close_price'] : $property_data['list_price'];

        // Calculate price per sqft
        $price_per_sqft = 0;
        if (!empty($property_data['building_area_total']) && $property_data['building_area_total'] > 0) {
            $price_per_sqft = round($price / $property_data['building_area_total'], 2);
        }

        $comparable = array(
            'listing_id' => $property_data['listing_id'],
            'property_url' => home_url('/property/' . $property_data['listing_id'] . '/'),
            'unparsed_address' => $property_data['unparsed_address'] ?: trim($property_data['street_number'] . ' ' . $property_data['street_name']),
            'city' => $property_data['city'],
            'state' => $property_data['state'],
            'postal_code' => $property_data['postal_code'],
            'latitude' => $property_data['latitude'],
            'longitude' => $property_data['longitude'],
            'list_price' => $property_data['list_price'],
            'close_price' => $property_data['close_price'],
            'adjusted_price' => $price, // No adjustments for subject property
            'price_per_sqft' => $price_per_sqft,
            'adjusted_price_per_sqft' => $price_per_sqft, // Same as regular since no adjustments
            'price_per_sqft_diff' => 0, // No difference from itself
            'price_per_sqft_diff_pct' => 0, // No difference from itself
            'standard_status' => $property_data['standard_status'],
            'close_date' => $property_data['close_date'],
            'days_on_market' => $property_data['days_on_market'],
            'bedrooms_total' => $property_data['bedrooms_total'],
            'bathrooms_total' => $property_data['bathrooms_total'],
            'building_area_total' => $property_data['building_area_total'],
            'lot_size_acres' => $property_data['lot_size_acres'],
            'year_built' => $property_data['year_built'],
            'garage_spaces' => $property_data['garage_spaces'],
            'pool_private_yn' => $property_data['pool_private_yn'],
            'waterfront_yn' => $property_data['waterfront_yn'],
            'association_fee' => $property_data['association_fee'],
            'photos_count' => $property_data['photos_count'],
            'main_photo_url' => $property_data['main_photo_url'],
            'distance_miles' => 0,
            'road_type' => $property_data['road_type'] ?? null,
            'property_condition' => $property_data['property_condition'] ?? null,
            'adjustments' => array(
                'items' => array(), // No adjustments for subject property
                'total_adjustment' => 0
            ),
            'comparability_score' => 100, // Perfect match - it's the subject!
            'comparability_grade' => 'SUBJECT',
            'is_subject' => true
        );

        return $comparable;
    }
}
