<?php
/**
 * MLD Market Data Calculator
 *
 * Calculates market-driven data for CMA adjustments and analysis
 * Replaces hardcoded values with intelligent calculations based on actual market data
 *
 * @package MLS_Listings_Display
 * @subpackage CMA
 * @since 5.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Market_Data_Calculator {

    /**
     * Cache group for transients
     *
     * @var string
     */
    private $cache_group = 'mld_market_data';

    /**
     * Cache duration (1 hour default)
     *
     * @var int
     */
    private $cache_duration = 3600;

    /**
     * Database instance
     *
     * @var wpdb
     */
    private $wpdb;

    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;

        // Allow customization of cache duration via filter
        $this->cache_duration = apply_filters('mld_market_data_cache_duration', $this->cache_duration);
    }

    /**
     * Get average price per square foot for a market
     *
     * @param string $city City name
     * @param string $state State abbreviation
     * @param string $property_type Property type (optional, default 'all')
     * @param int $months Number of months to analyze (default 12)
     * @return float|null Average price per sqft or null if no data
     */
    public function get_avg_price_per_sqft($city, $state = '', $property_type = 'all', $months = 12) {
        // Build cache key
        $cache_key = "avg_psf_{$city}_{$state}_{$property_type}_{$months}";

        // Check cache first
        $cached = $this->get_cached($cache_key);
        if ($cached !== false) {
            return $cached;
        }

        // Build query
        $table_prefix = $this->wpdb->prefix;
        $query = "
            SELECT AVG(
                CASE
                    WHEN l.close_price > 0 AND ld.building_area_total > 0
                    THEN l.close_price / ld.building_area_total
                    WHEN l.list_price > 0 AND ld.building_area_total > 0
                    THEN l.list_price / ld.building_area_total
                    ELSE NULL
                END
            ) as avg_price_per_sqft
            FROM {$table_prefix}bme_listings l
            LEFT JOIN {$table_prefix}bme_listing_details ld ON l.listing_id = ld.listing_id
            LEFT JOIN {$table_prefix}bme_listing_location loc ON l.listing_id = loc.listing_id
            WHERE 1=1
        ";

        $params = array();

        // Add city filter
        if (!empty($city)) {
            $query .= " AND loc.city = %s";
            $params[] = $city;
        }

        // Add state filter
        if (!empty($state)) {
            $query .= " AND loc.state_or_province = %s";
            $params[] = $state;
        }

        // Add property type filter
        if ($property_type !== 'all') {
            $query .= " AND l.property_type = %s";
            $params[] = $property_type;
        }

        // Add date filter
        $query .= " AND (
            (l.standard_status = 'Closed' AND l.close_date >= DATE_SUB(NOW(), INTERVAL %d MONTH))
            OR (l.standard_status IN ('Active', 'Pending') AND l.listing_contract_date >= DATE_SUB(NOW(), INTERVAL %d MONTH))
        )";
        $params[] = $months;
        $params[] = $months;

        // Add building area validation
        $query .= " AND ld.building_area_total > 500 AND ld.building_area_total < 20000";

        // Execute query
        if (!empty($params)) {
            $query = $this->wpdb->prepare($query, $params);
        }

        $result = $this->wpdb->get_var($query);
        $avg_psf = $result ? floatval($result) : null;

        // Cache result
        $this->set_cached($cache_key, $avg_psf);

        return $avg_psf;
    }

    /**
     * Get median price per square foot for a market
     *
     * @param string $city City name
     * @param string $state State abbreviation
     * @param string $property_type Property type
     * @param int $months Number of months
     * @return float|null Median price per sqft or null
     */
    public function get_median_price_per_sqft($city, $state = '', $property_type = 'all', $months = 12) {
        $cache_key = "median_psf_{$city}_{$state}_{$property_type}_{$months}";

        $cached = $this->get_cached($cache_key);
        if ($cached !== false) {
            return $cached;
        }

        // Get all price per sqft values
        $table_prefix = $this->wpdb->prefix;
        $query = "
            SELECT
                CASE
                    WHEN l.close_price > 0 AND ld.building_area_total > 0
                    THEN l.close_price / ld.building_area_total
                    WHEN l.list_price > 0 AND ld.building_area_total > 0
                    THEN l.list_price / ld.building_area_total
                    ELSE NULL
                END as price_per_sqft
            FROM {$table_prefix}bme_listings l
            LEFT JOIN {$table_prefix}bme_listing_details ld ON l.listing_id = ld.listing_id
            LEFT JOIN {$table_prefix}bme_listing_location loc ON l.listing_id = loc.listing_id
            WHERE 1=1
        ";

        $params = array();

        if (!empty($city)) {
            $query .= " AND loc.city = %s";
            $params[] = $city;
        }

        if (!empty($state)) {
            $query .= " AND loc.state_or_province = %s";
            $params[] = $state;
        }

        if ($property_type !== 'all') {
            $query .= " AND l.property_type = %s";
            $params[] = $property_type;
        }

        $query .= " AND (
            (l.standard_status = 'Closed' AND l.close_date >= DATE_SUB(NOW(), INTERVAL %d MONTH))
            OR (l.standard_status IN ('Active', 'Pending') AND l.listing_contract_date >= DATE_SUB(NOW(), INTERVAL %d MONTH))
        )";
        $params[] = $months;
        $params[] = $months;

        $query .= " AND ld.building_area_total > 500 AND ld.building_area_total < 20000
            HAVING price_per_sqft IS NOT NULL
            ORDER BY price_per_sqft";

        if (!empty($params)) {
            $query = $this->wpdb->prepare($query, $params);
        }

        $results = $this->wpdb->get_col($query);

        if (empty($results)) {
            return null;
        }

        // Calculate median
        $count = count($results);
        $middle = floor($count / 2);

        if ($count % 2 == 0) {
            $median = ($results[$middle - 1] + $results[$middle]) / 2;
        } else {
            $median = $results[$middle];
        }

        $this->set_cached($cache_key, $median);

        return $median;
    }

    /**
     * Calculate market-driven adjustment value for a feature
     *
     * Uses regression analysis to determine the value impact of a feature
     *
     * @param string $feature Feature name (garage, pool, bedrooms, bathrooms)
     * @param string $city City name
     * @param string $state State abbreviation
     * @param string $property_type Property type
     * @param int $months Number of months to analyze
     * @return float|null Adjustment value or null if insufficient data
     */
    public function calculate_feature_adjustment($feature, $city, $state = '', $property_type = 'all', $months = 12) {
        $cache_key = "adj_{$feature}_{$city}_{$state}_{$property_type}_{$months}";

        $cached = $this->get_cached($cache_key);
        if ($cached !== false) {
            return $cached;
        }

        $table_prefix = $this->wpdb->prefix;
        $adjustment_value = null;

        switch ($feature) {
            case 'garage':
                $adjustment_value = $this->calculate_garage_adjustment($city, $state, $property_type, $months);
                break;

            case 'pool':
                $adjustment_value = $this->calculate_pool_adjustment($city, $state, $property_type, $months);
                break;

            case 'bedroom':
                $adjustment_value = $this->calculate_bedroom_adjustment($city, $state, $property_type, $months);
                break;

            case 'bathroom':
                $adjustment_value = $this->calculate_bathroom_adjustment($city, $state, $property_type, $months);
                break;

            case 'waterfront':
                $adjustment_value = $this->calculate_waterfront_adjustment($city, $state, $property_type, $months);
                break;

            default:
                $adjustment_value = null;
        }

        $this->set_cached($cache_key, $adjustment_value);

        return $adjustment_value;
    }

    /**
     * Calculate garage space value
     *
     * Compares properties with/without garage to determine value impact
     *
     * @param string $city City name
     * @param string $state State abbreviation
     * @param string $property_type Property type
     * @param int $months Number of months
     * @return array Adjustment values [first_space, additional_space]
     */
    private function calculate_garage_adjustment($city, $state, $property_type, $months) {
        $table_prefix = $this->wpdb->prefix;

        // Compare average prices: 0 garage vs 1 garage vs 2+ garage
        $query = "
            SELECT
                CASE
                    WHEN ld.garage_spaces = 0 THEN '0'
                    WHEN ld.garage_spaces = 1 THEN '1'
                    ELSE '2+'
                END as garage_category,
                AVG(
                    CASE
                        WHEN l.close_price > 0 THEN l.close_price
                        ELSE l.list_price
                    END
                ) as avg_price,
                COUNT(*) as sample_size
            FROM {$table_prefix}bme_listings l
            LEFT JOIN {$table_prefix}bme_listing_details ld ON l.listing_id = ld.listing_id
            LEFT JOIN {$table_prefix}bme_listing_location loc ON l.listing_id = loc.listing_id
            WHERE 1=1
        ";

        $params = array();

        if (!empty($city)) {
            $query .= " AND loc.city = %s";
            $params[] = $city;
        }

        if (!empty($state)) {
            $query .= " AND loc.state_or_province = %s";
            $params[] = $state;
        }

        if ($property_type !== 'all') {
            $query .= " AND l.property_type = %s";
            $params[] = $property_type;
        }

        $query .= " AND (
                (l.standard_status = 'Closed' AND l.close_date >= DATE_SUB(NOW(), INTERVAL %d MONTH))
                OR (l.standard_status IN ('Active', 'Pending') AND l.listing_contract_date >= DATE_SUB(NOW(), INTERVAL %d MONTH))
            )
            AND ld.garage_spaces IS NOT NULL
            GROUP BY garage_category
            HAVING sample_size >= 5
        ";
        $params[] = $months;
        $params[] = $months;

        if (!empty($params)) {
            $query = $this->wpdb->prepare($query, $params);
        }

        $results = $this->wpdb->get_results($query);

        if (count($results) < 2) {
            // Insufficient data, return defaults with market adjustment
            $market_psf = $this->get_avg_price_per_sqft($city, $state, $property_type, $months);
            $base_multiplier = ($market_psf && $market_psf > 200) ? ($market_psf / 350) : 1.0;

            return array(
                'first_space' => 100000 * $base_multiplier,
                'additional_space' => 50000 * $base_multiplier
            );
        }

        // Extract prices
        $prices = array();
        foreach ($results as $row) {
            $prices[$row->garage_category] = floatval($row->avg_price);
        }

        // Calculate adjustments
        $first_space = isset($prices['1'], $prices['0']) ? $prices['1'] - $prices['0'] : 40000;
        $additional_space = isset($prices['2+'], $prices['1']) ? ($prices['2+'] - $prices['1']) : 25000;

        // Ensure reasonable bounds (reduced in v6.10.6 for more conservative estimates)
        // @since 6.10.6 - Reduced from $50k-$200k to $15k-$60k for first space
        $first_space = max(15000, min(60000, $first_space));
        $additional_space = max(10000, min(40000, $additional_space));

        return array(
            'first_space' => $first_space,
            'additional_space' => $additional_space
        );
    }

    /**
     * Calculate pool value
     *
     * @param string $city City name
     * @param string $state State abbreviation
     * @param string $property_type Property type
     * @param int $months Number of months
     * @return float Pool adjustment value
     */
    private function calculate_pool_adjustment($city, $state, $property_type, $months) {
        $table_prefix = $this->wpdb->prefix;

        $query = "
            SELECT
                CASE
                    WHEN lf.pool_private_yn = 'Yes' THEN 'with_pool'
                    ELSE 'no_pool'
                END as pool_category,
                AVG(
                    CASE
                        WHEN l.close_price > 0 THEN l.close_price
                        ELSE l.list_price
                    END
                ) as avg_price,
                COUNT(*) as sample_size
            FROM {$table_prefix}bme_listings l
            LEFT JOIN {$table_prefix}bme_listing_features lf ON l.listing_id = lf.listing_id
            LEFT JOIN {$table_prefix}bme_listing_location loc ON l.listing_id = loc.listing_id
            WHERE 1=1
        ";

        $params = array();

        if (!empty($city)) {
            $query .= " AND loc.city = %s";
            $params[] = $city;
        }

        if (!empty($state)) {
            $query .= " AND loc.state_or_province = %s";
            $params[] = $state;
        }

        if ($property_type !== 'all') {
            $query .= " AND l.property_type = %s";
            $params[] = $property_type;
        }

        $query .= " AND (
                (l.standard_status = 'Closed' AND l.close_date >= DATE_SUB(NOW(), INTERVAL %d MONTH))
                OR (l.standard_status IN ('Active', 'Pending') AND l.listing_contract_date >= DATE_SUB(NOW(), INTERVAL %d MONTH))
            )
            AND lf.pool_private_yn IS NOT NULL
            GROUP BY pool_category
            HAVING sample_size >= 10
        ";
        $params[] = $months;
        $params[] = $months;

        if (!empty($params)) {
            $query = $this->wpdb->prepare($query, $params);
        }

        $results = $this->wpdb->get_results($query);

        if (count($results) < 2) {
            // Default with market adjustment
            $market_psf = $this->get_avg_price_per_sqft($city, $state, $property_type, $months);
            $base_multiplier = ($market_psf && $market_psf > 200) ? ($market_psf / 350) : 1.0;

            return 50000 * $base_multiplier;
        }

        $prices = array();
        foreach ($results as $row) {
            $prices[$row->pool_category] = floatval($row->avg_price);
        }

        $pool_value = isset($prices['with_pool'], $prices['no_pool']) ?
            $prices['with_pool'] - $prices['no_pool'] : 50000;

        // Ensure reasonable bounds (pools worth $20k-$150k)
        return max(20000, min(150000, $pool_value));
    }

    /**
     * Calculate bedroom value
     *
     * @param string $city City name
     * @param string $state State abbreviation
     * @param string $property_type Property type
     * @param int $months Number of months
     * @return float Per-bedroom adjustment value
     */
    private function calculate_bedroom_adjustment($city, $state, $property_type, $months) {
        $table_prefix = $this->wpdb->prefix;

        // Compare 3-bed vs 4-bed properties
        $query = "
            SELECT
                ld.bedrooms_total,
                AVG(
                    CASE
                        WHEN l.close_price > 0 THEN l.close_price
                        ELSE l.list_price
                    END
                ) as avg_price,
                COUNT(*) as sample_size
            FROM {$table_prefix}bme_listings l
            LEFT JOIN {$table_prefix}bme_listing_details ld ON l.listing_id = ld.listing_id
            LEFT JOIN {$table_prefix}bme_listing_location loc ON l.listing_id = loc.listing_id
            WHERE 1=1
        ";

        $params = array();

        if (!empty($city)) {
            $query .= " AND loc.city = %s";
            $params[] = $city;
        }

        if (!empty($state)) {
            $query .= " AND loc.state_or_province = %s";
            $params[] = $state;
        }

        if ($property_type !== 'all') {
            $query .= " AND l.property_type = %s";
            $params[] = $property_type;
        }

        $query .= " AND (
                (l.standard_status = 'Closed' AND l.close_date >= DATE_SUB(NOW(), INTERVAL %d MONTH))
                OR (l.standard_status IN ('Active', 'Pending') AND l.listing_contract_date >= DATE_SUB(NOW(), INTERVAL %d MONTH))
            )
            AND ld.bedrooms_total BETWEEN 2 AND 5
            GROUP BY ld.bedrooms_total
            HAVING sample_size >= 10
            ORDER BY ld.bedrooms_total
        ";
        $params[] = $months;
        $params[] = $months;

        if (!empty($params)) {
            $query = $this->wpdb->prepare($query, $params);
        }

        $results = $this->wpdb->get_results($query);

        if (count($results) < 2) {
            // Default with market adjustment
            $market_psf = $this->get_avg_price_per_sqft($city, $state, $property_type, $months);
            $base_multiplier = ($market_psf && $market_psf > 200) ? ($market_psf / 350) : 1.0;

            return 75000 * $base_multiplier;
        }

        // Calculate average price difference per bedroom
        $total_diff = 0;
        $diff_count = 0;

        for ($i = 1; $i < count($results); $i++) {
            $price_diff = floatval($results[$i]->avg_price) - floatval($results[$i-1]->avg_price);
            $bed_diff = intval($results[$i]->bedrooms_total) - intval($results[$i-1]->bedrooms_total);

            if ($bed_diff > 0) {
                $total_diff += ($price_diff / $bed_diff);
                $diff_count++;
            }
        }

        $bed_value = $diff_count > 0 ? ($total_diff / $diff_count) : 25000;

        // Ensure reasonable bounds (reduced in v6.10.6 for more conservative estimates)
        // @since 6.10.6 - Reduced from $30k-$150k to $15k-$60k per bedroom
        return max(15000, min(60000, $bed_value));
    }

    /**
     * Calculate bathroom value
     *
     * @param string $city City name
     * @param string $state State abbreviation
     * @param string $property_type Property type
     * @param int $months Number of months
     * @return float Per-bathroom adjustment value
     */
    private function calculate_bathroom_adjustment($city, $state, $property_type, $months) {
        // Similar logic to bedroom, but for bathrooms
        // Simplified: return 1/3 of bedroom value as default
        $bedroom_value = $this->calculate_bedroom_adjustment($city, $state, $property_type, $months);
        $bath_value = $bedroom_value / 3;

        // Ensure reasonable bounds (reduced in v6.10.6 for more conservative estimates)
        // @since 6.10.6 - Reduced from $10k-$50k to $5k-$30k per bathroom
        return max(5000, min(30000, $bath_value));
    }

    /**
     * Calculate waterfront value
     *
     * @param string $city City name
     * @param string $state State abbreviation
     * @param string $property_type Property type
     * @param int $months Number of months
     * @return float Waterfront adjustment value
     */
    private function calculate_waterfront_adjustment($city, $state, $property_type, $months) {
        $table_prefix = $this->wpdb->prefix;

        $query = "
            SELECT
                CASE
                    WHEN lf.waterfront_yn = 'Yes' THEN 'waterfront'
                    ELSE 'no_waterfront'
                END as waterfront_category,
                AVG(
                    CASE
                        WHEN l.close_price > 0 THEN l.close_price
                        ELSE l.list_price
                    END
                ) as avg_price,
                COUNT(*) as sample_size
            FROM {$table_prefix}bme_listings l
            LEFT JOIN {$table_prefix}bme_listing_features lf ON l.listing_id = lf.listing_id
            LEFT JOIN {$table_prefix}bme_listing_location loc ON l.listing_id = loc.listing_id
            WHERE 1=1
        ";

        $params = array();

        if (!empty($city)) {
            $query .= " AND loc.city = %s";
            $params[] = $city;
        }

        if (!empty($state)) {
            $query .= " AND loc.state_or_province = %s";
            $params[] = $state;
        }

        if ($property_type !== 'all') {
            $query .= " AND l.property_type = %s";
            $params[] = $property_type;
        }

        $query .= " AND (
                (l.standard_status = 'Closed' AND l.close_date >= DATE_SUB(NOW(), INTERVAL %d MONTH))
                OR (l.standard_status IN ('Active', 'Pending') AND l.listing_contract_date >= DATE_SUB(NOW(), INTERVAL %d MONTH))
            )
            AND lf.waterfront_yn IS NOT NULL
            GROUP BY waterfront_category
            HAVING sample_size >= 5
        ";
        $params[] = $months;
        $params[] = $months;

        if (!empty($params)) {
            $query = $this->wpdb->prepare($query, $params);
        }

        $results = $this->wpdb->get_results($query);

        if (count($results) < 2) {
            // Default: waterfront premium is typically 20-50% of property value
            // Use a moderate $200k default
            return 200000;
        }

        $prices = array();
        foreach ($results as $row) {
            $prices[$row->waterfront_category] = floatval($row->avg_price);
        }

        $waterfront_value = isset($prices['waterfront'], $prices['no_waterfront']) ?
            $prices['waterfront'] - $prices['no_waterfront'] : 200000;

        // Ensure reasonable bounds ($50k-$1M for waterfront premium)
        return max(50000, min(1000000, $waterfront_value));
    }

    /**
     * Get all adjustment values for a market
     *
     * @param string $city City name
     * @param string $state State abbreviation
     * @param string $property_type Property type
     * @param int $months Number of months
     * @return array Associative array of all adjustment values
     */
    public function get_all_adjustments($city, $state = '', $property_type = 'all', $months = 12) {
        $cache_key = "all_adj_{$city}_{$state}_{$property_type}_{$months}";

        $cached = $this->get_cached($cache_key);
        if ($cached !== false) {
            return $this->apply_overrides($cached);
        }

        $garage_adj = $this->calculate_garage_adjustment($city, $state, $property_type, $months);

        $adjustments = array(
            'price_per_sqft' => $this->get_avg_price_per_sqft($city, $state, $property_type, $months),
            'garage_first' => $garage_adj['first_space'],
            'garage_additional' => $garage_adj['additional_space'],
            'pool' => $this->calculate_feature_adjustment('pool', $city, $state, $property_type, $months),
            'bedroom' => $this->calculate_feature_adjustment('bedroom', $city, $state, $property_type, $months),
            'bathroom' => $this->calculate_feature_adjustment('bathroom', $city, $state, $property_type, $months),
            'waterfront' => $this->calculate_feature_adjustment('waterfront', $city, $state, $property_type, $months),
            'year_built_rate' => 25000, // Default: $25k per year (could be calculated)
            'location_rate' => 5000 // Default: $5k per mile (could be calculated)
        );

        // Allow filtering/customization
        $adjustments = apply_filters('mld_market_adjustments', $adjustments, $city, $state, $property_type, $months);

        $this->set_cached($cache_key, $adjustments);

        // Apply user overrides before returning
        return $this->apply_overrides($adjustments);
    }

    /**
     * Apply user-defined overrides to calculated adjustments
     *
     * @param array $adjustments Calculated adjustments
     * @return array Adjustments with overrides applied
     */
    private function apply_overrides($adjustments) {
        $override_fields = array(
            'price_per_sqft',
            'garage_first',
            'garage_additional',
            'pool',
            'bedroom',
            'bathroom',
            'waterfront',
            'year_built_rate',
            'location_rate'
        );

        foreach ($override_fields as $field) {
            $override_value = get_option('mld_cma_override_' . $field);
            if ($override_value !== false && $override_value !== '') {
                $adjustments[$field] = floatval($override_value);
            }
        }

        return $adjustments;
    }

    /**
     * Clear cached market data
     *
     * @param string $city Optional city to clear specific cache
     * @param string $state Optional state
     * @return bool Success
     */
    public function clear_cache($city = null, $state = null) {
        if ($city && $state) {
            // Clear specific market cache
            $pattern = "all_adj_{$city}_{$state}_";
            return delete_transient($pattern);
        }

        // Clear all market data cache
        global $wpdb;
        $table_prefix = $wpdb->prefix;

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table_prefix}options
                 WHERE option_name LIKE %s",
                '_transient_mld_market_%'
            )
        );

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table_prefix}options
                 WHERE option_name LIKE %s",
                '_transient_timeout_mld_market_%'
            )
        );

        return true;
    }

    /**
     * Get cached value
     *
     * @param string $key Cache key
     * @return mixed Cached value or false if not found
     */
    private function get_cached($key) {
        $transient_key = 'mld_market_' . md5($key);
        return get_transient($transient_key);
    }

    /**
     * Set cached value
     *
     * @param string $key Cache key
     * @param mixed $value Value to cache
     * @return bool Success
     */
    private function set_cached($key, $value) {
        $transient_key = 'mld_market_' . md5($key);
        return set_transient($transient_key, $value, $this->cache_duration);
    }
}
