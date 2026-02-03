<?php
/**
 * MLD Extended Analytics - Enhanced Market Analytics Engine
 *
 * Provides comprehensive market analytics including:
 * - Dynamic city discovery
 * - Pre-computed monthly statistics
 * - City market summaries
 * - Agent/office performance tracking
 * - Property feature premium analysis
 * - Market heat index calculations
 * - Year-over-year comparisons
 *
 * @package MLS_Listings_Display
 * @since 6.12.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Extended_Analytics {

    /**
     * Cache group name
     */
    const CACHE_GROUP = 'mld_analytics';

    /**
     * Default cache TTLs (seconds)
     */
    const TTL_CITIES = 3600;        // 1 hour
    const TTL_SUMMARY = 3600;       // 1 hour
    const TTL_MONTHLY = 604800;     // 7 days
    const TTL_AGENTS = 86400;       // 24 hours
    const TTL_PREMIUMS = 604800;    // 7 days

    /**
     * Market heat thresholds
     */
    const HEAT_HOT_THRESHOLD = 70;
    const HEAT_BALANCED_MIN = 40;

    /**
     * Singleton instance
     */
    private static $instance = null;

    /**
     * Get singleton instance
     *
     * @return MLD_Extended_Analytics
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
        // Register cron handlers
        add_action('mld_analytics_daily_update', array($this, 'daily_update'));
        add_action('mld_analytics_hourly_refresh', array($this, 'hourly_refresh'));
        add_action('mld_analytics_monthly_rebuild', array($this, 'monthly_rebuild'));
        add_action('mld_analytics_agent_update', array($this, 'update_agent_performance'));
    }

    // =========================================================================
    // CITY DISCOVERY
    // =========================================================================

    /**
     * Get all cities with active listings, prioritizing cities with recent sales data
     *
     * @param int $min_listings Minimum listing count (default: 10)
     * @return array Array of cities with listing counts, sorted by recent sales first
     */
    public static function get_available_cities($min_listings = 10) {
        global $wpdb;

        $cache_key = 'available_cities_v2_' . $min_listings;
        $cities = wp_cache_get($cache_key, self::CACHE_GROUP);

        if (false === $cities) {
            // Get cities with active listings AND recent sales data (prioritize analytics-ready cities)
            $cities = $wpdb->get_results($wpdb->prepare("
                SELECT
                    s.city,
                    s.state_or_province as state,
                    COUNT(DISTINCT s.listing_id) as listing_count,
                    COALESCE(archive.recent_sales, 0) as recent_sales
                FROM {$wpdb->prefix}bme_listing_summary s
                LEFT JOIN (
                    SELECT
                        loc.city,
                        loc.state_or_province,
                        COUNT(*) as recent_sales
                    FROM {$wpdb->prefix}bme_listings_archive la
                    JOIN {$wpdb->prefix}bme_listing_location_archive loc ON la.listing_id = loc.listing_id
                    WHERE la.standard_status = 'Closed'
                    AND la.close_date >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
                    AND la.close_price > 10000
                    GROUP BY loc.city, loc.state_or_province
                ) archive ON s.city = archive.city AND s.state_or_province = archive.state_or_province
                WHERE s.standard_status = 'Active'
                    AND s.city IS NOT NULL
                    AND s.city != ''
                    AND TRIM(s.city) != ''
                GROUP BY s.city, s.state_or_province
                HAVING listing_count >= %d
                ORDER BY recent_sales DESC, listing_count DESC
            ", $min_listings), ARRAY_A);

            wp_cache_set($cache_key, $cities, self::CACHE_GROUP, self::TTL_CITIES);
        }

        return $cities ?: array();
    }

    // =========================================================================
    // DYNAMIC FILTER OPTIONS
    // =========================================================================

    /**
     * Get available property types from database
     *
     * @param string $source 'active', 'archive', or 'all'
     * @param string $city Optional city filter
     * @return array Property types with counts
     */
    public static function get_available_property_types($source = 'all', $city = '') {
        global $wpdb;

        $cache_key = 'property_types_' . $source . '_' . sanitize_title($city);
        $types = wp_cache_get($cache_key, self::CACHE_GROUP);

        if (false === $types) {
            $city_filter = !empty($city) ? $wpdb->prepare(" AND loc.city = %s", $city) : '';

            if ($source === 'active' || $source === 'all') {
                $active_types = $wpdb->get_results("
                    SELECT
                        l.property_type,
                        COUNT(*) as count
                    FROM {$wpdb->prefix}bme_listings l
                    JOIN {$wpdb->prefix}bme_listing_location loc ON l.listing_id = loc.listing_id
                    WHERE l.standard_status = 'Active'
                    AND l.property_type IS NOT NULL
                    AND l.property_type != ''
                    {$city_filter}
                    GROUP BY l.property_type
                    ORDER BY count DESC
                ", ARRAY_A);
            }

            if ($source === 'archive' || $source === 'all') {
                $archive_types = $wpdb->get_results("
                    SELECT
                        la.property_type,
                        COUNT(*) as count
                    FROM {$wpdb->prefix}bme_listings_archive la
                    JOIN {$wpdb->prefix}bme_listing_location_archive loc ON la.listing_id = loc.listing_id
                    WHERE la.standard_status = 'Closed'
                    AND la.property_type IS NOT NULL
                    AND la.property_type != ''
                    {$city_filter}
                    GROUP BY la.property_type
                    ORDER BY count DESC
                ", ARRAY_A);
            }

            // Merge and dedupe if 'all'
            if ($source === 'all') {
                $merged = array();
                foreach (($active_types ?? array()) as $type) {
                    $merged[$type['property_type']] = array(
                        'property_type' => $type['property_type'],
                        'active_count' => intval($type['count']),
                        'archive_count' => 0
                    );
                }
                foreach (($archive_types ?? array()) as $type) {
                    if (isset($merged[$type['property_type']])) {
                        $merged[$type['property_type']]['archive_count'] = intval($type['count']);
                    } else {
                        $merged[$type['property_type']] = array(
                            'property_type' => $type['property_type'],
                            'active_count' => 0,
                            'archive_count' => intval($type['count'])
                        );
                    }
                }
                // Sort by total count
                uasort($merged, function($a, $b) {
                    return ($b['active_count'] + $b['archive_count']) - ($a['active_count'] + $a['archive_count']);
                });
                $types = array_values($merged);
            } else {
                $types = ($source === 'active') ? ($active_types ?? array()) : ($archive_types ?? array());
            }

            wp_cache_set($cache_key, $types, self::CACHE_GROUP, self::TTL_CITIES);
        }

        return $types ?: array();
    }

    /**
     * Get available property sub-types
     *
     * @param string $property_type Filter by main property type
     * @param string $city Optional city filter
     * @return array Property sub-types with counts
     */
    public static function get_available_property_subtypes($property_type = '', $city = '') {
        global $wpdb;

        $cache_key = 'property_subtypes_' . sanitize_title($property_type) . '_' . sanitize_title($city);
        $subtypes = wp_cache_get($cache_key, self::CACHE_GROUP);

        if (false === $subtypes) {
            $where_clauses = array("l.property_sub_type IS NOT NULL", "l.property_sub_type != ''");
            $params = array();

            if (!empty($property_type)) {
                $where_clauses[] = "l.property_type = %s";
                $params[] = $property_type;
            }

            if (!empty($city)) {
                $where_clauses[] = "loc.city = %s";
                $params[] = $city;
            }

            $where_sql = implode(' AND ', $where_clauses);

            $query = "
                SELECT
                    l.property_sub_type,
                    COUNT(*) as count
                FROM {$wpdb->prefix}bme_listings l
                JOIN {$wpdb->prefix}bme_listing_location loc ON l.listing_id = loc.listing_id
                WHERE {$where_sql}
                GROUP BY l.property_sub_type
                ORDER BY count DESC
            ";

            if (!empty($params)) {
                $query = $wpdb->prepare($query, ...$params);
            }

            $subtypes = $wpdb->get_results($query, ARRAY_A);
            wp_cache_set($cache_key, $subtypes, self::CACHE_GROUP, self::TTL_CITIES);
        }

        return $subtypes ?: array();
    }

    /**
     * Get price range bounds for a location
     *
     * @param string $city City name
     * @param string $property_type Property type filter
     * @param string $source 'active' or 'archive'
     * @return array Min/max prices and suggested ranges
     */
    public static function get_price_range_bounds($city = '', $property_type = '', $source = 'active') {
        global $wpdb;

        $table = ($source === 'archive')
            ? "{$wpdb->prefix}bme_listings_archive"
            : "{$wpdb->prefix}bme_listings";
        $loc_table = ($source === 'archive')
            ? "{$wpdb->prefix}bme_listing_location_archive"
            : "{$wpdb->prefix}bme_listing_location";
        $price_field = ($source === 'archive') ? 'close_price' : 'list_price';
        $status_filter = ($source === 'archive') ? "l.standard_status = 'Closed'" : "l.standard_status = 'Active'";

        $where_clauses = array($status_filter, "l.{$price_field} > 0");
        $params = array();

        if (!empty($city)) {
            $where_clauses[] = "loc.city = %s";
            $params[] = $city;
        }

        if (!empty($property_type) && $property_type !== 'all') {
            $where_clauses[] = "l.property_type = %s";
            $params[] = $property_type;
        }

        $where_sql = implode(' AND ', $where_clauses);

        $query = "
            SELECT
                MIN(l.{$price_field}) as min_price,
                MAX(l.{$price_field}) as max_price,
                AVG(l.{$price_field}) as avg_price,
                PERCENTILE_CONT(0.25) WITHIN GROUP (ORDER BY l.{$price_field}) as percentile_25,
                PERCENTILE_CONT(0.75) WITHIN GROUP (ORDER BY l.{$price_field}) as percentile_75
            FROM {$table} l
            JOIN {$loc_table} loc ON l.listing_id = loc.listing_id
            WHERE {$where_sql}
        ";

        // MySQL doesn't support PERCENTILE_CONT, use simpler approach
        $query = "
            SELECT
                MIN(l.{$price_field}) as min_price,
                MAX(l.{$price_field}) as max_price,
                AVG(l.{$price_field}) as avg_price,
                COUNT(*) as total_count
            FROM {$table} l
            JOIN {$loc_table} loc ON l.listing_id = loc.listing_id
            WHERE {$where_sql}
        ";

        if (!empty($params)) {
            $query = $wpdb->prepare($query, ...$params);
        }

        $result = $wpdb->get_row($query, ARRAY_A);

        if (!$result || $result['total_count'] == 0) {
            return array(
                'min_price' => 0,
                'max_price' => 0,
                'avg_price' => 0,
                'suggested_ranges' => array()
            );
        }

        // Generate suggested price ranges
        $min = intval($result['min_price']);
        $max = intval($result['max_price']);
        $ranges = self::generate_price_ranges($min, $max);

        return array(
            'min_price' => $min,
            'max_price' => $max,
            'avg_price' => round($result['avg_price']),
            'total_count' => intval($result['total_count']),
            'suggested_ranges' => $ranges
        );
    }

    /**
     * Generate logical price range options
     */
    private static function generate_price_ranges($min, $max) {
        $ranges = array();
        $breakpoints = array(100000, 200000, 300000, 400000, 500000, 600000, 750000,
                            1000000, 1500000, 2000000, 3000000, 5000000, 10000000);

        $prev = 0;
        foreach ($breakpoints as $bp) {
            if ($bp >= $min && $prev < $max) {
                $ranges[] = array(
                    'min' => $prev,
                    'max' => $bp,
                    'label' => ($prev === 0 ? 'Under ' : '$' . number_format($prev/1000) . 'k - ') . '$' . number_format($bp/1000) . 'k'
                );
            }
            $prev = $bp;
            if ($bp > $max) break;
        }

        // Add final range if needed
        if ($prev < $max) {
            $ranges[] = array(
                'min' => $prev,
                'max' => null,
                'label' => '$' . number_format($prev/1000) . 'k+'
            );
        }

        return $ranges;
    }

    /**
     * Get bedroom/bathroom options available in data
     *
     * @param string $city City filter
     * @param string $property_type Property type filter
     * @return array Available bedroom/bathroom counts
     */
    public static function get_bedroom_bathroom_options($city = '', $property_type = '') {
        global $wpdb;

        $where_clauses = array("l.standard_status = 'Active'");
        $params = array();

        if (!empty($city)) {
            $where_clauses[] = "loc.city = %s";
            $params[] = $city;
        }

        if (!empty($property_type) && $property_type !== 'all') {
            $where_clauses[] = "l.property_type = %s";
            $params[] = $property_type;
        }

        $where_sql = implode(' AND ', $where_clauses);

        $query = "
            SELECT
                d.bedrooms_total,
                d.bathrooms_total_integer,
                COUNT(*) as count
            FROM {$wpdb->prefix}bme_listings l
            JOIN {$wpdb->prefix}bme_listing_location loc ON l.listing_id = loc.listing_id
            JOIN {$wpdb->prefix}bme_listing_details d ON l.listing_id = d.listing_id
            WHERE {$where_sql}
            AND d.bedrooms_total IS NOT NULL
            GROUP BY d.bedrooms_total, d.bathrooms_total_integer
            ORDER BY d.bedrooms_total, d.bathrooms_total_integer
        ";

        if (!empty($params)) {
            $query = $wpdb->prepare($query, ...$params);
        }

        $results = $wpdb->get_results($query, ARRAY_A);

        // Aggregate into unique bedroom and bathroom counts
        $bedrooms = array();
        $bathrooms = array();

        foreach ($results as $row) {
            $bed = intval($row['bedrooms_total']);
            $bath = intval($row['bathrooms_total_integer']);

            if (!isset($bedrooms[$bed])) {
                $bedrooms[$bed] = 0;
            }
            $bedrooms[$bed] += intval($row['count']);

            if (!isset($bathrooms[$bath])) {
                $bathrooms[$bath] = 0;
            }
            $bathrooms[$bath] += intval($row['count']);
        }

        ksort($bedrooms);
        ksort($bathrooms);

        return array(
            'bedrooms' => $bedrooms,
            'bathrooms' => $bathrooms
        );
    }

    /**
     * Get year built range for properties
     *
     * @param string $city City filter
     * @param string $property_type Property type filter
     * @return array Year built statistics
     */
    public static function get_year_built_range($city = '', $property_type = '') {
        global $wpdb;

        $where_clauses = array("l.standard_status = 'Active'", "d.year_built IS NOT NULL", "d.year_built > 1800");
        $params = array();

        if (!empty($city)) {
            $where_clauses[] = "loc.city = %s";
            $params[] = $city;
        }

        if (!empty($property_type) && $property_type !== 'all') {
            $where_clauses[] = "l.property_type = %s";
            $params[] = $property_type;
        }

        $where_sql = implode(' AND ', $where_clauses);

        $query = "
            SELECT
                MIN(d.year_built) as min_year,
                MAX(d.year_built) as max_year,
                AVG(d.year_built) as avg_year,
                SUM(CASE WHEN d.year_built >= 2020 THEN 1 ELSE 0 END) as new_construction,
                SUM(CASE WHEN d.year_built BETWEEN 2000 AND 2019 THEN 1 ELSE 0 END) as built_2000s,
                SUM(CASE WHEN d.year_built BETWEEN 1980 AND 1999 THEN 1 ELSE 0 END) as built_80s_90s,
                SUM(CASE WHEN d.year_built < 1980 THEN 1 ELSE 0 END) as built_pre_1980,
                COUNT(*) as total
            FROM {$wpdb->prefix}bme_listings l
            JOIN {$wpdb->prefix}bme_listing_location loc ON l.listing_id = loc.listing_id
            JOIN {$wpdb->prefix}bme_listing_details d ON l.listing_id = d.listing_id
            WHERE {$where_sql}
        ";

        if (!empty($params)) {
            $query = $wpdb->prepare($query, ...$params);
        }

        return $wpdb->get_row($query, ARRAY_A);
    }

    /**
     * Get available date ranges for analysis
     *
     * @return array Standard date range options
     */
    public static function get_date_range_options() {
        return array(
            '3' => array('label' => 'Last 3 Months', 'months' => 3),
            '6' => array('label' => 'Last 6 Months', 'months' => 6),
            '12' => array('label' => 'Last 12 Months', 'months' => 12),
            '24' => array('label' => 'Last 2 Years', 'months' => 24),
            '36' => array('label' => 'Last 3 Years', 'months' => 36),
            'ytd' => array('label' => 'Year to Date', 'months' => null, 'type' => 'ytd'),
            'custom' => array('label' => 'Custom Range', 'months' => null, 'type' => 'custom')
        );
    }

    /**
     * Get all filter options for a city in one call
     *
     * @param string $city City name
     * @return array All available filter options
     */
    public static function get_all_filter_options($city = '') {
        return array(
            'cities' => self::get_available_cities(5),
            'property_types' => self::get_available_property_types('all', $city),
            'price_ranges' => self::get_price_range_bounds($city),
            'bed_bath' => self::get_bedroom_bathroom_options($city),
            'year_built' => self::get_year_built_range($city),
            'date_ranges' => self::get_date_range_options()
        );
    }

    // =========================================================================
    // CITY SUMMARY
    // =========================================================================

    /**
     * Get city summary from cache or calculate
     *
     * @param string $city City name
     * @param string $state State abbreviation
     * @param string $property_type Property type filter (default: 'all')
     * @return array City summary data
     */
    public static function get_city_summary($city, $state = '', $property_type = 'all') {
        global $wpdb;

        // Try to get from pre-computed table first
        $summary = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$wpdb->prefix}mld_city_market_summary
            WHERE city = %s 
            AND state = %s 
            AND property_type = %s
            AND last_updated > DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ", $city, $state, $property_type), ARRAY_A);

        if ($summary) {
            return $summary;
        }

        // Calculate fresh if not in cache
        return self::calculate_city_summary($city, $state, $property_type);
    }

    /**
     * Calculate and store city market summary
     *
     * @param string $city City name
     * @param string $state State abbreviation
     * @param string $property_type Property type filter
     * @return array Calculated summary
     */
    public static function calculate_city_summary($city, $state = '', $property_type = 'all') {
        global $wpdb;

        $property_filter = ($property_type !== 'all') 
            ? $wpdb->prepare(" AND property_type = %s", $property_type) 
            : '';

        // Get current inventory metrics
        $inventory = $wpdb->get_row($wpdb->prepare("
            SELECT
                COUNT(*) as active_count,
                SUM(CASE WHEN standard_status = 'Pending' THEN 1 ELSE 0 END) as pending_count,
                SUM(CASE WHEN listing_contract_date >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) as new_this_week,
                SUM(CASE WHEN listing_contract_date >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as new_this_month,
                AVG(list_price) as avg_list_price,
                AVG(CASE WHEN building_area_total > 0 THEN list_price / building_area_total ELSE NULL END) as avg_price_per_sqft,
                MIN(list_price) as min_list_price,
                MAX(list_price) as max_list_price
            FROM {$wpdb->prefix}bme_listing_summary
            WHERE city = %s
            AND standard_status IN ('Active', 'Pending')
            {$property_filter}
        ", $city), ARRAY_A);

        // Calculate median list price
        $median_list_price = self::calculate_median_price($city, $state, $property_type, 'active');

        // Get 12-month performance from archive
        $performance_12m = $wpdb->get_row($wpdb->prepare("
            SELECT 
                COUNT(*) as sold_12m,
                SUM(l.close_price) as total_volume_12m,
                AVG(l.close_price) as avg_close_price_12m,
                AVG(COALESCE(NULLIF(l.days_on_market, 0), DATEDIFF(l.close_date, l.listing_contract_date))) as avg_dom_12m,
                AVG(CASE WHEN l.list_price > 0 THEN (l.close_price / l.list_price) * 100 ELSE NULL END) as avg_sp_lp_12m
            FROM {$wpdb->prefix}bme_listings_archive l
            JOIN {$wpdb->prefix}bme_listing_location_archive loc ON l.listing_id = loc.listing_id
            WHERE loc.city = %s
            AND l.standard_status = 'Closed'
            AND l.close_date >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
            {$property_filter}
        ", $city), ARRAY_A);

        // Calculate months of supply
        $monthly_sales = ($performance_12m['sold_12m'] > 0) ? $performance_12m['sold_12m'] / 12 : 0;
        $months_of_supply = ($monthly_sales > 0) ? $inventory['active_count'] / $monthly_sales : null;
        $absorption_rate = ($inventory['active_count'] > 0) ? ($monthly_sales / $inventory['active_count']) * 100 : 0;

        // Calculate market heat index
        $market_heat = self::calculate_market_heat_index(
            $performance_12m['avg_dom_12m'] ?? 90,
            $performance_12m['avg_sp_lp_12m'] ?? 95,
            $months_of_supply ?? 6,
            $absorption_rate
        );

        // Get YoY comparison
        $yoy = self::calculate_yoy_metrics($city, $state, $property_type);

        // Price reduction stats
        $reductions = self::calculate_price_reduction_stats($city, $state, $property_type);

        // Build summary array
        $summary = array(
            'city' => $city,
            'state' => $state,
            'property_type' => $property_type,
            'active_count' => intval($inventory['active_count'] ?? 0),
            'pending_count' => intval($inventory['pending_count'] ?? 0),
            'new_this_week' => intval($inventory['new_this_week'] ?? 0),
            'new_this_month' => intval($inventory['new_this_month'] ?? 0),
            'avg_list_price' => floatval($inventory['avg_list_price'] ?? 0),
            'median_list_price' => floatval($median_list_price),
            'min_list_price' => floatval($inventory['min_list_price'] ?? 0),
            'max_list_price' => floatval($inventory['max_list_price'] ?? 0),
            'avg_price_per_sqft' => floatval($inventory['avg_price_per_sqft'] ?? 0),
            'sold_12m' => intval($performance_12m['sold_12m'] ?? 0),
            'total_volume_12m' => floatval($performance_12m['total_volume_12m'] ?? 0),
            'avg_close_price_12m' => floatval($performance_12m['avg_close_price_12m'] ?? 0),
            'avg_dom_12m' => floatval($performance_12m['avg_dom_12m'] ?? 0),
            'avg_sp_lp_12m' => floatval($performance_12m['avg_sp_lp_12m'] ?? 0),
            'months_of_supply' => $months_of_supply,
            'absorption_rate' => $absorption_rate,
            'market_heat_index' => $market_heat['score'],
            'market_classification' => $market_heat['classification'],
            'price_reduction_rate' => $reductions['reduction_rate'] ?? 0,
            'avg_reduction_pct' => $reductions['avg_reduction_pct'] ?? 0,
            'yoy_price_change_pct' => $yoy['price_change_pct'] ?? null,
            'yoy_sales_change_pct' => $yoy['sales_change_pct'] ?? null,
            'yoy_inventory_change_pct' => $yoy['inventory_change_pct'] ?? null,
            'yoy_dom_change_pct' => $yoy['dom_change_pct'] ?? null,
            'listing_count' => intval($inventory['active_count'] ?? 0) + intval($inventory['pending_count'] ?? 0),
            'last_updated' => current_time('mysql')
        );

        // Store in database
        self::store_city_summary($summary);

        return $summary;
    }

    /**
     * Store city summary in database
     *
     * @param array $summary Summary data
     */
    private static function store_city_summary($summary) {
        global $wpdb;

        $wpdb->replace(
            $wpdb->prefix . 'mld_city_market_summary',
            $summary,
            array(
                '%s', '%s', '%s', '%d', '%d', '%d', '%d',
                '%f', '%f', '%f', '%f', '%f',
                '%d', '%f', '%f', '%f', '%f',
                '%f', '%f', '%d', '%s',
                '%f', '%f', '%f', '%f', '%f', '%f',
                '%d', '%s'
            )
        );
    }

    // =========================================================================
    // MARKET TRENDS
    // =========================================================================

    /**
     * Get monthly price trends for a city
     *
     * @param string $city City name
     * @param string $state State abbreviation
     * @param int $months Number of months to retrieve
     * @param string $property_type Property type filter
     * @return array Monthly trend data
     */
    public static function get_price_trends($city, $state = '', $months = 24, $property_type = 'all') {
        global $wpdb;

        // Try pre-computed table first
        $trends = $wpdb->get_results($wpdb->prepare("
            SELECT 
                year, month, 
                avg_close_price, median_close_price, avg_price_per_sqft,
                total_sales, avg_dom, avg_sp_lp_ratio,
                new_listings, price_reductions, months_of_supply
            FROM {$wpdb->prefix}mld_market_stats_monthly
            WHERE city = %s 
            AND state = %s 
            AND property_type = %s
            ORDER BY year DESC, month DESC
            LIMIT %d
        ", $city, $state, $property_type, $months), ARRAY_A);

        if (!empty($trends)) {
            return array_reverse($trends);
        }

        // Calculate from raw data if not pre-computed
        return self::calculate_price_trends_raw($city, $state, $months, $property_type);
    }

    /**
     * Calculate price trends from raw data
     *
     * @param string $city City name
     * @param string $state State abbreviation
     * @param int $months Number of months
     * @param string $property_type Property type filter
     * @return array Monthly trend data
     */
    private static function calculate_price_trends_raw($city, $state, $months, $property_type) {
        global $wpdb;

        $property_filter = ($property_type !== 'all') 
            ? $wpdb->prepare(" AND l.property_type = %s", $property_type) 
            : '';

        return $wpdb->get_results($wpdb->prepare("
            SELECT 
                YEAR(l.close_date) as year,
                MONTH(l.close_date) as month,
                COUNT(*) as total_sales,
                AVG(l.close_price) as avg_close_price,
                AVG(CASE WHEN ld.building_area_total > 0 THEN l.close_price / ld.building_area_total ELSE NULL END) as avg_price_per_sqft,
                AVG(COALESCE(NULLIF(l.days_on_market, 0), DATEDIFF(l.close_date, l.listing_contract_date))) as avg_dom,
                AVG(CASE WHEN l.list_price > 0 THEN (l.close_price / l.list_price) * 100 ELSE NULL END) as avg_sp_lp_ratio
            FROM {$wpdb->prefix}bme_listings_archive l
            JOIN {$wpdb->prefix}bme_listing_location_archive loc ON l.listing_id = loc.listing_id
            LEFT JOIN {$wpdb->prefix}bme_listing_details_archive ld ON l.listing_id = ld.listing_id
            WHERE loc.city = %s
            AND l.standard_status = 'Closed'
            AND l.close_date >= DATE_SUB(NOW(), INTERVAL %d MONTH)
            {$property_filter}
            GROUP BY YEAR(l.close_date), MONTH(l.close_date)
            ORDER BY year, month
        ", $city, $months), ARRAY_A);
    }

    /**
     * Get seasonal patterns for a city
     *
     * @param string $city City name
     * @param string $state State abbreviation
     * @param string $property_type Property type filter
     * @return array Seasonal pattern data by month
     */
    public static function get_seasonal_patterns($city, $state = '', $property_type = 'all') {
        global $wpdb;

        $property_filter = ($property_type !== 'all') 
            ? $wpdb->prepare(" AND l.property_type = %s", $property_type) 
            : '';

        return $wpdb->get_results($wpdb->prepare("
            SELECT 
                MONTH(l.close_date) as month,
                MONTHNAME(l.close_date) as month_name,
                COUNT(*) as total_sales,
                AVG(l.close_price) as avg_price,
                AVG(COALESCE(NULLIF(l.days_on_market, 0), DATEDIFF(l.close_date, l.listing_contract_date))) as avg_dom,
                AVG(CASE WHEN l.list_price > 0 THEN (l.close_price / l.list_price) * 100 ELSE NULL END) as avg_sp_lp
            FROM {$wpdb->prefix}bme_listings_archive l
            JOIN {$wpdb->prefix}bme_listing_location_archive loc ON l.listing_id = loc.listing_id
            WHERE loc.city = %s
            AND l.standard_status = 'Closed'
            AND l.close_date >= DATE_SUB(NOW(), INTERVAL 36 MONTH)
            {$property_filter}
            GROUP BY MONTH(l.close_date)
            ORDER BY month
        ", $city), ARRAY_A);
    }

    // =========================================================================
    // MARKET HEAT INDEX
    // =========================================================================

    /**
     * Calculate market heat index (0-100 scale)
     *
     * @param float $avg_dom Average days on market
     * @param float $sp_lp_ratio Sale-to-list price ratio
     * @param float $months_supply Months of supply
     * @param float $absorption_rate Absorption rate percentage
     * @return array Score and classification
     */
    public static function calculate_market_heat_index($avg_dom, $sp_lp_ratio, $months_supply, $absorption_rate) {
        // Get weights from settings or use defaults
        $weights = get_option('mld_analytics_market_heat_weights');
        if ($weights) {
            $weights = json_decode($weights, true);
        }
        if (!$weights) {
            $weights = array(
                'dom' => 0.25,
                'sp_lp' => 0.30,
                'inventory' => 0.25,
                'absorption' => 0.20
            );
        }

        // DOM component (lower is hotter): 0-90 days mapped to 100-0
        $dom_score = max(0, min(100, (100 - min($avg_dom, 90)) * (100 / 90)));

        // SP/LP component (higher is hotter): 90-105% mapped to 0-100
        $sp_lp_score = max(0, min(100, ($sp_lp_ratio - 90) * (100 / 15)));

        // Inventory component (lower is hotter): 0-6 months mapped to 100-0
        $inventory_score = max(0, min(100, (6 - min($months_supply, 6)) * (100 / 6)));

        // Absorption component (higher is hotter): 0-20% mapped to 0-100
        $absorption_score = max(0, min(100, min($absorption_rate, 20) * 5));

        // Calculate weighted score
        $score = round(
            ($dom_score * $weights['dom']) +
            ($sp_lp_score * $weights['sp_lp']) +
            ($inventory_score * $weights['inventory']) +
            ($absorption_score * $weights['absorption'])
        );

        // Determine classification
        if ($score >= self::HEAT_HOT_THRESHOLD) {
            $classification = 'hot';
        } elseif ($score >= self::HEAT_BALANCED_MIN) {
            $classification = 'balanced';
        } else {
            $classification = 'cold';
        }

        return array(
            'score' => $score,
            'classification' => $classification,
            'components' => array(
                'dom_score' => round($dom_score),
                'sp_lp_score' => round($sp_lp_score),
                'inventory_score' => round($inventory_score),
                'absorption_score' => round($absorption_score)
            )
        );
    }

    // =========================================================================
    // CITY COMPARISON
    // =========================================================================

    /**
     * Compare multiple cities
     *
     * @param array $cities Array of city names (or city/state arrays)
     * @param string $property_type Property type filter
     * @return array Comparison data for all cities
     */
    public static function compare_cities($cities, $property_type = 'all') {
        $comparison = array();

        foreach ($cities as $city_data) {
            if (is_array($city_data)) {
                $city = $city_data['city'] ?? $city_data[0] ?? '';
                $state = $city_data['state'] ?? $city_data[1] ?? '';
            } else {
                $city = $city_data;
                $state = '';
            }

            if (!empty($city)) {
                $summary = self::get_city_summary($city, $state, $property_type);
                $comparison[] = $summary;
            }
        }

        return $comparison;
    }

    /**
     * Get metrics available for comparison
     *
     * @return array Metric definitions
     */
    public static function get_comparison_metrics() {
        return array(
            'median_list_price' => array(
                'label' => 'Median List Price',
                'format' => 'currency',
                'better' => 'context'
            ),
            'avg_price_per_sqft' => array(
                'label' => 'Price Per Sq Ft',
                'format' => 'currency',
                'better' => 'context'
            ),
            'avg_dom_12m' => array(
                'label' => 'Avg Days on Market',
                'format' => 'number',
                'better' => 'lower'
            ),
            'avg_sp_lp_12m' => array(
                'label' => 'Sale-to-List Ratio',
                'format' => 'percent',
                'better' => 'higher'
            ),
            'months_of_supply' => array(
                'label' => 'Months of Supply',
                'format' => 'decimal',
                'better' => 'context'
            ),
            'market_heat_index' => array(
                'label' => 'Market Heat',
                'format' => 'score',
                'better' => 'context'
            ),
            'yoy_price_change_pct' => array(
                'label' => 'YoY Price Change',
                'format' => 'percent',
                'better' => 'context'
            ),
            'active_count' => array(
                'label' => 'Active Listings',
                'format' => 'number',
                'better' => 'context'
            )
        );
    }

    // =========================================================================
    // AGENT PERFORMANCE
    // =========================================================================

    /**
     * Get top agents for a city
     *
     * @param string $city City name
     * @param string $state State abbreviation
     * @param int $limit Number of agents to return
     * @param int $year Year for performance (default: current)
     * @return array Top agents by volume
     */
    public static function get_top_agents($city, $state = '', $limit = 20, $months = 12) {
        global $wpdb;

        // Support legacy year parameter (4-digit number)
        if ($months > 1000) {
            // Legacy year mode - convert to current behavior
            $year = intval($months);
            $agents = $wpdb->get_results($wpdb->prepare("
                SELECT * FROM {$wpdb->prefix}mld_agent_performance
                WHERE city = %s
                AND period_year = %d
                ORDER BY volume_rank ASC
                LIMIT %d
            ", $city, $year, $limit), ARRAY_A);

            if (!empty($agents)) {
                return $agents;
            }
        }

        // Calculate from raw data using date range in months
        return self::calculate_agent_performance_by_months($city, $state, $months, $limit);
    }

    /**
     * Calculate agent performance from raw data by months
     *
     * @param string $city City name
     * @param string $state State abbreviation
     * @param int $months Number of months lookback
     * @param int $limit Limit
     * @return array Agent performance data
     */
    private static function calculate_agent_performance_by_months($city, $state, $months, $limit) {
        global $wpdb;

        $date_threshold = date('Y-m-d', strtotime("-{$months} months"));

        return $wpdb->get_results($wpdb->prepare("
            SELECT
                l.list_agent_mls_id as agent_mls_id,
                a.agent_full_name as agent_name,
                a.agent_email,
                a.agent_phone,
                l.list_office_mls_id as office_mls_id,
                o.office_name,
                COUNT(*) as transaction_count,
                SUM(l.close_price) as total_volume,
                AVG(l.close_price) as avg_sale_price,
                AVG(COALESCE(NULLIF(l.days_on_market, 0), DATEDIFF(l.close_date, l.listing_contract_date))) as avg_dom,
                AVG(CASE WHEN l.list_price > 0 THEN (l.close_price / l.list_price) * 100 ELSE NULL END) as avg_sp_lp_ratio
            FROM {$wpdb->prefix}bme_listings_archive l
            JOIN {$wpdb->prefix}bme_listing_location_archive loc ON l.listing_id = loc.listing_id
            LEFT JOIN {$wpdb->prefix}bme_agents a ON l.list_agent_mls_id = a.agent_mls_id
            LEFT JOIN {$wpdb->prefix}bme_offices o ON l.list_office_mls_id = o.office_mls_id
            WHERE loc.city = %s
            AND l.standard_status = 'Closed'
            AND l.close_date >= %s
            AND l.list_agent_mls_id IS NOT NULL
            AND l.list_agent_mls_id != ''
            GROUP BY l.list_agent_mls_id
            ORDER BY total_volume DESC
            LIMIT %d
        ", $city, $date_threshold, $limit), ARRAY_A);
    }

    /**
     * Calculate agent performance from raw data
     *
     * @param string $city City name
     * @param string $state State abbreviation
     * @param int $year Year
     * @param int $limit Limit
     * @return array Agent performance data
     */
    private static function calculate_agent_performance($city, $state, $year, $limit) {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare("
            SELECT 
                l.list_agent_mls_id as agent_mls_id,
                a.agent_full_name as agent_name,
                a.agent_email,
                a.agent_phone,
                l.list_office_mls_id as office_mls_id,
                o.office_name,
                COUNT(*) as transaction_count,
                SUM(l.close_price) as total_volume,
                AVG(l.close_price) as avg_sale_price,
                AVG(COALESCE(NULLIF(l.days_on_market, 0), DATEDIFF(l.close_date, l.listing_contract_date))) as avg_dom,
                AVG(CASE WHEN l.list_price > 0 THEN (l.close_price / l.list_price) * 100 ELSE NULL END) as avg_sp_lp_ratio
            FROM {$wpdb->prefix}bme_listings_archive l
            JOIN {$wpdb->prefix}bme_listing_location_archive loc ON l.listing_id = loc.listing_id
            LEFT JOIN {$wpdb->prefix}bme_agents a ON l.list_agent_mls_id = a.agent_mls_id
            LEFT JOIN {$wpdb->prefix}bme_offices o ON l.list_office_mls_id = o.office_mls_id
            WHERE loc.city = %s
            AND l.standard_status = 'Closed'
            AND YEAR(l.close_date) = %d
            AND l.list_agent_mls_id IS NOT NULL
            AND l.list_agent_mls_id != ''
            GROUP BY l.list_agent_mls_id
            ORDER BY total_volume DESC
            LIMIT %d
        ", $city, $year, $limit), ARRAY_A);
    }

    /**
     * Get top offices for a city
     *
     * @param string $city City name
     * @param string $state State abbreviation
     * @param int $limit Number of offices to return
     * @param int $months Number of months lookback (default 12)
     * @return array Top offices by volume
     */
    public static function get_top_offices($city, $state = '', $limit = 10, $months = 12) {
        global $wpdb;

        // Support legacy year parameter (4-digit number)
        if ($months > 1000) {
            $year = intval($months);
            return $wpdb->get_results($wpdb->prepare("
                SELECT
                    l.list_office_mls_id as office_mls_id,
                    o.office_name,
                    COUNT(*) as transaction_count,
                    SUM(l.close_price) as total_volume,
                    AVG(l.close_price) as avg_sale_price,
                    AVG(COALESCE(NULLIF(l.days_on_market, 0), DATEDIFF(l.close_date, l.listing_contract_date))) as avg_dom,
                    COUNT(DISTINCT l.list_agent_mls_id) as agent_count
                FROM {$wpdb->prefix}bme_listings_archive l
                JOIN {$wpdb->prefix}bme_listing_location_archive loc ON l.listing_id = loc.listing_id
                LEFT JOIN {$wpdb->prefix}bme_offices o ON l.list_office_mls_id = o.office_mls_id
                WHERE loc.city = %s
                AND l.standard_status = 'Closed'
                AND YEAR(l.close_date) = %d
                AND l.list_office_mls_id IS NOT NULL
                AND l.list_office_mls_id != ''
                GROUP BY l.list_office_mls_id
                ORDER BY total_volume DESC
                LIMIT %d
            ", $city, $year, $limit), ARRAY_A);
        }

        $date_threshold = date('Y-m-d', strtotime("-{$months} months"));

        return $wpdb->get_results($wpdb->prepare("
            SELECT
                l.list_office_mls_id as office_mls_id,
                o.office_name,
                COUNT(*) as transaction_count,
                SUM(l.close_price) as total_volume,
                AVG(l.close_price) as avg_sale_price,
                AVG(COALESCE(NULLIF(l.days_on_market, 0), DATEDIFF(l.close_date, l.listing_contract_date))) as avg_dom,
                COUNT(DISTINCT l.list_agent_mls_id) as agent_count
            FROM {$wpdb->prefix}bme_listings_archive l
            JOIN {$wpdb->prefix}bme_listing_location_archive loc ON l.listing_id = loc.listing_id
            LEFT JOIN {$wpdb->prefix}bme_offices o ON l.list_office_mls_id = o.office_mls_id
            WHERE loc.city = %s
            AND l.standard_status = 'Closed'
            AND l.close_date >= %s
            AND l.list_office_mls_id IS NOT NULL
            AND l.list_office_mls_id != ''
            GROUP BY l.list_office_mls_id
            ORDER BY total_volume DESC
            LIMIT %d
        ", $city, $date_threshold, $limit), ARRAY_A);
    }

    // =========================================================================
    // FEATURE PREMIUMS
    // =========================================================================

    /**
     * Get feature premiums for a city
     *
     * @param string $city City name
     * @param string $state State abbreviation
     * @param string $property_type Property type filter
     * @return array Feature premium data
     */
    public static function get_feature_premiums($city, $state = '', $property_type = 'all') {
        global $wpdb;

        // Try pre-computed table first
        $premiums = $wpdb->get_results($wpdb->prepare("
            SELECT * FROM {$wpdb->prefix}mld_feature_premiums
            WHERE city = %s 
            AND state = %s 
            AND property_type = %s
            AND calculation_date > DATE_SUB(NOW(), INTERVAL 7 DAY)
        ", $city, $state, $property_type), ARRAY_A);

        if (!empty($premiums)) {
            return $premiums;
        }

        // Calculate fresh
        $features = array('waterfront', 'pool', 'view', 'garage_2plus', 'finished_basement');
        $results = array();

        foreach ($features as $feature) {
            $premium = self::calculate_feature_premium($feature, $city, $state, $property_type);
            if ($premium) {
                $results[] = $premium;
            }
        }

        return $results;
    }

    /**
     * Calculate premium for a specific feature
     *
     * @param string $feature Feature name
     * @param string $city City name
     * @param string $state State abbreviation
     * @param string $property_type Property type filter
     * @return array|null Premium data or null if insufficient data
     */
    public static function calculate_feature_premium($feature, $city, $state = '', $property_type = 'all') {
        global $wpdb;

        // Define feature conditions
        $feature_conditions = array(
            'waterfront' => array(
                'with' => "lf.waterfront_yn = 'Yes'",
                'without' => "(lf.waterfront_yn IS NULL OR lf.waterfront_yn != 'Yes')"
            ),
            'pool' => array(
                'with' => "lf.pool_private_yn = 'Yes'",
                'without' => "(lf.pool_private_yn IS NULL OR lf.pool_private_yn != 'Yes')"
            ),
            'view' => array(
                'with' => "lf.view_yn = 'Yes'",
                'without' => "(lf.view_yn IS NULL OR lf.view_yn != 'Yes')"
            ),
            'garage_2plus' => array(
                'with' => "ld.garage_spaces >= 2",
                'without' => "(ld.garage_spaces IS NULL OR ld.garage_spaces < 2)"
            ),
            'finished_basement' => array(
                'with' => "ld.below_grade_finished_area > 0",
                'without' => "(ld.below_grade_finished_area IS NULL OR ld.below_grade_finished_area = 0)"
            )
        );

        if (!isset($feature_conditions[$feature])) {
            return null;
        }

        $conditions = $feature_conditions[$feature];

        // Get stats for properties WITH feature
        $with_stats = $wpdb->get_row($wpdb->prepare("
            SELECT 
                COUNT(*) as sample_size,
                AVG(l.close_price) as avg_price,
                STDDEV(l.close_price) as stddev
            FROM {$wpdb->prefix}bme_listings_archive l
            JOIN {$wpdb->prefix}bme_listing_location_archive loc ON l.listing_id = loc.listing_id
            LEFT JOIN {$wpdb->prefix}bme_listing_features_archive lf ON l.listing_id = lf.listing_id
            LEFT JOIN {$wpdb->prefix}bme_listing_details_archive ld ON l.listing_id = ld.listing_id
            WHERE loc.city = %s
            AND l.standard_status = 'Closed'
            AND l.close_date >= DATE_SUB(NOW(), INTERVAL 24 MONTH)
            AND {$conditions['with']}
        ", $city), ARRAY_A);

        // Get stats for properties WITHOUT feature
        $without_stats = $wpdb->get_row($wpdb->prepare("
            SELECT 
                COUNT(*) as sample_size,
                AVG(l.close_price) as avg_price,
                STDDEV(l.close_price) as stddev
            FROM {$wpdb->prefix}bme_listings_archive l
            JOIN {$wpdb->prefix}bme_listing_location_archive loc ON l.listing_id = loc.listing_id
            LEFT JOIN {$wpdb->prefix}bme_listing_features_archive lf ON l.listing_id = lf.listing_id
            LEFT JOIN {$wpdb->prefix}bme_listing_details_archive ld ON l.listing_id = ld.listing_id
            WHERE loc.city = %s
            AND l.standard_status = 'Closed'
            AND l.close_date >= DATE_SUB(NOW(), INTERVAL 24 MONTH)
            AND {$conditions['without']}
        ", $city), ARRAY_A);

        // Need minimum sample sizes
        if ($with_stats['sample_size'] < 10 || $without_stats['sample_size'] < 10) {
            return null;
        }

        $premium_amount = $with_stats['avg_price'] - $without_stats['avg_price'];
        $premium_pct = ($without_stats['avg_price'] > 0) 
            ? (($premium_amount / $without_stats['avg_price']) * 100) 
            : 0;

        // Calculate confidence score (0-1 based on sample size and variance)
        $min_samples = min($with_stats['sample_size'], $without_stats['sample_size']);
        $confidence = min(1.0, $min_samples / 50); // Full confidence at 50+ samples

        return array(
            'city' => $city,
            'state' => $state,
            'property_type' => $property_type,
            'feature_name' => $feature,
            'premium_amount' => round($premium_amount, 2),
            'premium_pct' => round($premium_pct, 2),
            'sample_size_with' => intval($with_stats['sample_size']),
            'sample_size_without' => intval($without_stats['sample_size']),
            'avg_price_with' => round($with_stats['avg_price'], 2),
            'avg_price_without' => round($without_stats['avg_price'], 2),
            'confidence_score' => round($confidence, 2),
            'calculation_date' => current_time('mysql')
        );
    }

    // =========================================================================
    // HELPER METHODS
    // =========================================================================

    /**
     * Calculate median price for a city
     *
     * @param string $city City name
     * @param string $state State abbreviation
     * @param string $property_type Property type filter
     * @param string $status 'active' or 'sold'
     * @return float Median price
     */
    private static function calculate_median_price($city, $state, $property_type, $status = 'sold') {
        global $wpdb;

        $property_filter = ($property_type !== 'all') 
            ? $wpdb->prepare(" AND property_type = %s", $property_type) 
            : '';

        if ($status === 'active') {
            $prices = $wpdb->get_col($wpdb->prepare("
                SELECT list_price FROM {$wpdb->prefix}bme_listing_summary
                WHERE city = %s AND standard_status = 'Active' {$property_filter}
                ORDER BY list_price
            ", $city));
        } else {
            $prices = $wpdb->get_col($wpdb->prepare("
                SELECT l.close_price 
                FROM {$wpdb->prefix}bme_listings_archive l
                JOIN {$wpdb->prefix}bme_listing_location_archive loc ON l.listing_id = loc.listing_id
                WHERE loc.city = %s AND l.standard_status = 'Closed' 
                AND l.close_date >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
                {$property_filter}
                ORDER BY l.close_price
            ", $city));
        }

        if (empty($prices)) {
            return 0;
        }

        $count = count($prices);
        $middle = floor($count / 2);

        if ($count % 2 === 0) {
            return ($prices[$middle - 1] + $prices[$middle]) / 2;
        }

        return $prices[$middle];
    }

    /**
     * Calculate year-over-year metrics
     *
     * @param string $city City name
     * @param string $state State abbreviation
     * @param string $property_type Property type filter
     * @return array YoY comparison metrics
     */
    private static function calculate_yoy_metrics($city, $state, $property_type) {
        global $wpdb;

        $property_filter = ($property_type !== 'all') 
            ? $wpdb->prepare(" AND l.property_type = %s", $property_type) 
            : '';

        // Current year stats
        $current = $wpdb->get_row($wpdb->prepare("
            SELECT 
                AVG(l.close_price) as avg_price,
                COUNT(*) as sales_count,
                AVG(COALESCE(NULLIF(l.days_on_market, 0), DATEDIFF(l.close_date, l.listing_contract_date))) as avg_dom
            FROM {$wpdb->prefix}bme_listings_archive l
            JOIN {$wpdb->prefix}bme_listing_location_archive loc ON l.listing_id = loc.listing_id
            WHERE loc.city = %s
            AND l.standard_status = 'Closed'
            AND l.close_date >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
            {$property_filter}
        ", $city), ARRAY_A);

        // Previous year stats
        $previous = $wpdb->get_row($wpdb->prepare("
            SELECT 
                AVG(l.close_price) as avg_price,
                COUNT(*) as sales_count,
                AVG(COALESCE(NULLIF(l.days_on_market, 0), DATEDIFF(l.close_date, l.listing_contract_date))) as avg_dom
            FROM {$wpdb->prefix}bme_listings_archive l
            JOIN {$wpdb->prefix}bme_listing_location_archive loc ON l.listing_id = loc.listing_id
            WHERE loc.city = %s
            AND l.standard_status = 'Closed'
            AND l.close_date >= DATE_SUB(NOW(), INTERVAL 24 MONTH)
            AND l.close_date < DATE_SUB(NOW(), INTERVAL 12 MONTH)
            {$property_filter}
        ", $city), ARRAY_A);

        return array(
            'price_change_pct' => ($previous['avg_price'] > 0) 
                ? round((($current['avg_price'] - $previous['avg_price']) / $previous['avg_price']) * 100, 2)
                : null,
            'sales_change_pct' => ($previous['sales_count'] > 0)
                ? round((($current['sales_count'] - $previous['sales_count']) / $previous['sales_count']) * 100, 2)
                : null,
            'dom_change_pct' => ($previous['avg_dom'] > 0)
                ? round((($current['avg_dom'] - $previous['avg_dom']) / $previous['avg_dom']) * 100, 2)
                : null,
            'inventory_change_pct' => null // Would need historical inventory snapshots
        );
    }

    /**
     * Calculate price reduction statistics
     *
     * @param string $city City name
     * @param string $state State abbreviation
     * @param string $property_type Property type filter
     * @return array Price reduction stats
     */
    private static function calculate_price_reduction_stats($city, $state, $property_type) {
        global $wpdb;

        $property_filter = ($property_type !== 'all') 
            ? $wpdb->prepare(" AND property_type = %s", $property_type) 
            : '';

        $stats = $wpdb->get_row($wpdb->prepare("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN original_list_price > list_price THEN 1 ELSE 0 END) as reduced,
                AVG(CASE 
                    WHEN original_list_price > list_price 
                    THEN ((original_list_price - list_price) / original_list_price) * 100 
                    ELSE NULL 
                END) as avg_reduction_pct
            FROM {$wpdb->prefix}bme_listing_summary
            WHERE city = %s
            AND standard_status IN ('Active', 'Pending')
            {$property_filter}
        ", $city), ARRAY_A);

        return array(
            'reduction_rate' => ($stats['total'] > 0) 
                ? round(($stats['reduced'] / $stats['total']) * 100, 2) 
                : 0,
            'avg_reduction_pct' => round($stats['avg_reduction_pct'] ?? 0, 2)
        );
    }

    // =========================================================================
    // CRON JOB HANDLERS
    // =========================================================================

    /**
     * Daily update - refresh city summaries and current month stats
     */
    public function daily_update() {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[MLD Analytics] Running daily update');
        }

        $cities = self::get_available_cities(10);

        foreach ($cities as $city_data) {
            self::calculate_city_summary($city_data['city'], $city_data['state'], 'all');
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[MLD Analytics] Daily update completed - ' . count($cities) . ' cities updated');
        }
    }

    /**
     * Hourly refresh - update city market summaries
     */
    public function hourly_refresh() {
        // Lightweight refresh of critical metrics only
        $cities = self::get_available_cities(50); // Only top cities

        foreach (array_slice($cities, 0, 10) as $city_data) {
            self::calculate_city_summary($city_data['city'], $city_data['state'], 'all');
        }
    }

    /**
     * Monthly rebuild - full historical recalculation
     */
    public function monthly_rebuild() {
        global $wpdb;

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[MLD Analytics] Starting monthly rebuild');
        }

        $cities = self::get_available_cities(10);
        $months = get_option('mld_analytics_history_months', 24);

        foreach ($cities as $city_data) {
            // Calculate and store monthly stats for last N months
            $trends = self::calculate_price_trends_raw(
                $city_data['city'], 
                $city_data['state'], 
                $months, 
                'all'
            );

            foreach ($trends as $month_data) {
                $wpdb->replace(
                    $wpdb->prefix . 'mld_market_stats_monthly',
                    array_merge($month_data, array(
                        'city' => $city_data['city'],
                        'state' => $city_data['state'],
                        'property_type' => 'all',
                        'calculation_date' => current_time('mysql')
                    ))
                );
            }
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[MLD Analytics] Monthly rebuild completed');
        }
    }

    /**
     * Update agent performance data
     */
    public function update_agent_performance() {
        global $wpdb;

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[MLD Analytics] Updating agent performance');
        }

        $cities = self::get_available_cities(20);
        $year = intval(date('Y'));

        foreach ($cities as $city_data) {
            $agents = self::calculate_agent_performance(
                $city_data['city'], 
                $city_data['state'], 
                $year, 
                100
            );

            // Calculate total market volume for market share
            $total_volume = array_sum(array_column($agents, 'total_volume'));

            $rank = 1;
            foreach ($agents as $agent) {
                $market_share = ($total_volume > 0) 
                    ? ($agent['total_volume'] / $total_volume) * 100 
                    : 0;

                $wpdb->replace(
                    $wpdb->prefix . 'mld_agent_performance',
                    array(
                        'agent_mls_id' => $agent['agent_mls_id'],
                        'agent_name' => $agent['agent_name'],
                        'agent_email' => $agent['agent_email'] ?? null,
                        'agent_phone' => $agent['agent_phone'] ?? null,
                        'office_mls_id' => $agent['office_mls_id'],
                        'office_name' => $agent['office_name'],
                        'city' => $city_data['city'],
                        'state' => $city_data['state'],
                        'period_year' => $year,
                        'period_type' => 'annual',
                        'transaction_count' => $agent['transaction_count'],
                        'total_volume' => $agent['total_volume'],
                        'avg_sale_price' => $agent['avg_sale_price'],
                        'avg_dom' => $agent['avg_dom'],
                        'avg_sp_lp_ratio' => $agent['avg_sp_lp_ratio'],
                        'market_share_pct' => round($market_share, 2),
                        'volume_rank' => $rank++,
                        'calculation_date' => current_time('mysql')
                    )
                );
            }
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[MLD Analytics] Agent performance update completed');
        }
    }

    // =========================================================================
    // SUPPLY & DEMAND METRICS
    // =========================================================================

    /**
     * Get supply and demand metrics for a city
     *
     * @param string $city City name
     * @param string $state State abbreviation
     * @param string $property_type Property type filter
     * @return array Supply and demand metrics
     */
    public static function get_supply_demand_metrics($city, $state = '', $property_type = 'all') {
        global $wpdb;

        $property_filter = ($property_type !== 'all')
            ? $wpdb->prepare(" AND property_type = %s", $property_type)
            : '';

        // Get active listings count
        $active_count = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) FROM {$wpdb->prefix}bme_listing_summary
            WHERE city = %s AND standard_status = 'Active' {$property_filter}
        ", $city));

        // Get pending count
        $pending_count = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) FROM {$wpdb->prefix}bme_listing_summary
            WHERE city = %s AND standard_status = 'Pending' {$property_filter}
        ", $city));

        // Get new listings this month
        $new_listings_month = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) FROM {$wpdb->prefix}bme_listing_summary
            WHERE city = %s
            AND standard_status IN ('Active', 'Pending')
            AND listing_contract_date >= DATE_FORMAT(NOW(), '%%Y-%%m-01')
            {$property_filter}
        ", $city));

        // Get sold this month
        $sold_month = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*)
            FROM {$wpdb->prefix}bme_listings_archive l
            JOIN {$wpdb->prefix}bme_listing_location_archive loc ON l.listing_id = loc.listing_id
            WHERE loc.city = %s
            AND l.standard_status = 'Closed'
            AND l.close_date >= DATE_FORMAT(NOW(), '%%Y-%%m-01')
            {$property_filter}
        ", $city));

        // Get average monthly sales (last 6 months)
        $avg_monthly_sales = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) / 6
            FROM {$wpdb->prefix}bme_listings_archive l
            JOIN {$wpdb->prefix}bme_listing_location_archive loc ON l.listing_id = loc.listing_id
            WHERE loc.city = %s
            AND l.standard_status = 'Closed'
            AND l.close_date >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
            {$property_filter}
        ", $city));

        $avg_monthly_sales = floatval($avg_monthly_sales) ?: 1;

        // Calculate metrics
        $months_supply = ($avg_monthly_sales > 0)
            ? round($active_count / $avg_monthly_sales, 1)
            : 0;

        $absorption_rate = ($active_count > 0)
            ? round(($avg_monthly_sales / $active_count) * 100, 2)
            : 0;

        return array(
            'active_count' => intval($active_count),
            'pending_count' => intval($pending_count),
            'new_listings_month' => intval($new_listings_month),
            'sold_month' => intval($sold_month),
            'avg_monthly_sales' => round($avg_monthly_sales, 1),
            'months_supply' => $months_supply,
            'absorption_rate' => $absorption_rate,
            'total_inventory' => intval($active_count) + intval($pending_count)
        );
    }

    /**
     * Get market heat index for a city (wrapper method)
     *
     * Fetches city metrics and calculates the heat index
     *
     * @param string $city City name
     * @param string $state State abbreviation
     * @param string $property_type Property type filter
     * @return array Heat index data with interpretation
     */
    public static function get_market_heat_index($city, $state = '', $property_type = 'all') {
        global $wpdb;

        // Get city summary data
        $summary = self::get_city_summary($city, $state, $property_type);

        if (isset($summary['error'])) {
            return array('error' => $summary['error']);
        }

        // Get supply demand metrics
        $supply_demand = self::get_supply_demand_metrics($city, $state, $property_type);

        // Extract metrics
        $avg_dom = floatval($summary['avg_dom_12m'] ?? 60);
        $sp_lp_ratio = floatval($summary['avg_sp_lp_12m'] ?? 98);
        $months_supply = floatval($supply_demand['months_supply'] ?? 6);
        $absorption_rate = floatval($supply_demand['absorption_rate'] ?? 8);

        // Calculate heat index using existing method
        $heat_result = self::calculate_market_heat_index($avg_dom, $sp_lp_ratio, $months_supply, $absorption_rate);

        // Expand classification for display
        $classification_map = array(
            'hot' => 'Hot',
            'balanced' => 'Balanced',
            'cold' => 'Cold'
        );

        // Generate interpretation
        $interpretation = self::generate_heat_interpretation(
            $heat_result['classification'],
            $avg_dom,
            $sp_lp_ratio,
            $months_supply
        );

        return array(
            'heat_index' => $heat_result['score'],
            'classification' => $classification_map[$heat_result['classification']] ?? ucfirst($heat_result['classification']),
            'interpretation' => $interpretation,
            'components' => array(
                'avg_dom' => round($avg_dom),
                'sp_lp_ratio' => round($sp_lp_ratio, 1),
                'months_supply' => round($months_supply, 1),
                'absorption_rate' => round($absorption_rate, 1)
            ),
            'scores' => $heat_result['components']
        );
    }

    /**
     * Generate human-readable interpretation of market heat
     *
     * @param string $classification Hot, balanced, or cold
     * @param float $avg_dom Average days on market
     * @param float $sp_lp_ratio Sale-to-list price ratio
     * @param float $months_supply Months of inventory
     * @return string Interpretation text
     */
    private static function generate_heat_interpretation($classification, $avg_dom, $sp_lp_ratio, $months_supply) {
        switch ($classification) {
            case 'hot':
                $text = "Strong seller's market. ";
                if ($months_supply < 3) {
                    $text .= "Inventory is very low with only " . round($months_supply, 1) . " months of supply. ";
                }
                if ($sp_lp_ratio >= 100) {
                    $text .= "Homes are selling at or above asking price. ";
                }
                if ($avg_dom < 30) {
                    $text .= "Properties are moving quickly, averaging just " . round($avg_dom) . " days on market.";
                }
                break;

            case 'cold':
                $text = "Buyer's market with more options available. ";
                if ($months_supply > 6) {
                    $text .= "Plenty of inventory with " . round($months_supply, 1) . " months of supply. ";
                }
                if ($sp_lp_ratio < 97) {
                    $text .= "Buyers have negotiating power with homes selling below asking. ";
                }
                if ($avg_dom > 60) {
                    $text .= "Homes take longer to sell, averaging " . round($avg_dom) . " days on market.";
                }
                break;

            default: // balanced
                $text = "Balanced market with fair conditions for both buyers and sellers. ";
                $text .= round($months_supply, 1) . " months of supply indicates equilibrium. ";
                if ($sp_lp_ratio >= 98 && $sp_lp_ratio <= 101) {
                    $text .= "Homes are selling close to asking price.";
                }
        }

        return trim($text);
    }

    // =========================================================================
    // COMPREHENSIVE MARKET METRICS - Added v6.12.1
    // Complete data utilization for maximum analytics coverage
    // =========================================================================

    /**
     * Get comprehensive price analysis for a city
     *
     * @param string $city City name
     * @param string $state State abbreviation
     * @param string $property_type Property type filter
     * @param int $months Number of months to analyze
     * @return array Comprehensive price metrics
     */
    public static function get_price_analysis($city, $state = '', $property_type = 'Residential', $months = 12) {
        global $wpdb;

        $property_filter = ($property_type !== 'all')
            ? $wpdb->prepare(" AND la.property_type = %s", $property_type)
            : '';

        $results = $wpdb->get_row($wpdb->prepare("
            SELECT
                -- Volume metrics
                COUNT(*) as total_sales,
                ROUND(SUM(la.close_price), 0) as total_volume,

                -- Price metrics
                ROUND(AVG(la.close_price), 0) as avg_sale_price,
                ROUND(AVG(la.list_price), 0) as avg_list_price,
                ROUND(AVG(la.original_list_price), 0) as avg_original_price,

                -- Price per sqft
                ROUND(AVG(la.close_price / NULLIF(d.living_area, 0)), 2) as avg_price_per_sqft,
                ROUND(MIN(la.close_price / NULLIF(d.living_area, 0)), 2) as min_price_per_sqft,
                ROUND(MAX(la.close_price / NULLIF(d.living_area, 0)), 2) as max_price_per_sqft,

                -- SP/LP Ratio (Sale Price to List Price)
                ROUND(AVG(
                    CASE WHEN la.close_price / NULLIF(la.list_price, 0) * 100 BETWEEN 50 AND 150
                    THEN la.close_price / NULLIF(la.list_price, 0) * 100 END
                ), 2) as avg_sp_lp_ratio,

                -- SP/OLP Ratio (Sale Price to Original List Price)
                ROUND(AVG(
                    CASE WHEN la.close_price / NULLIF(la.original_list_price, 0) * 100 BETWEEN 50 AND 150
                    THEN la.close_price / NULLIF(la.original_list_price, 0) * 100 END
                ), 2) as avg_sp_olp_ratio,

                -- Price reduction metrics
                SUM(CASE WHEN la.original_list_price > la.list_price THEN 1 ELSE 0 END) as listings_with_reduction,
                ROUND(AVG(
                    CASE WHEN la.original_list_price > la.list_price
                    THEN (la.original_list_price - la.list_price) / la.original_list_price * 100 END
                ), 2) as avg_reduction_pct,

                -- Price ranges
                ROUND(MIN(la.close_price), 0) as min_sale_price,
                ROUND(MAX(la.close_price), 0) as max_sale_price

            FROM {$wpdb->prefix}bme_listings_archive la
            JOIN {$wpdb->prefix}bme_listing_location_archive loc ON la.listing_id = loc.listing_id
            LEFT JOIN {$wpdb->prefix}bme_listing_details_archive d ON la.listing_id = d.listing_id
            WHERE loc.city = %s
            AND la.standard_status = 'Closed'
            AND la.close_date >= DATE_SUB(NOW(), INTERVAL %d MONTH)
            AND la.list_price > 100
            {$property_filter}
        ", $city, $months), ARRAY_A);

        if (empty($results) || $results['total_sales'] == 0) {
            return array('error' => 'No sales data available');
        }

        // Calculate price reduction rate
        $results['price_reduction_rate'] = ($results['total_sales'] > 0)
            ? round(($results['listings_with_reduction'] / $results['total_sales']) * 100, 1)
            : 0;

        return $results;
    }

    /**
     * Get comprehensive DOM (Days on Market) analysis
     *
     * @param string $city City name
     * @param string $state State abbreviation
     * @param string $property_type Property type filter
     * @param int $months Number of months to analyze
     * @return array DOM metrics
     */
    public static function get_dom_analysis($city, $state = '', $property_type = 'Residential', $months = 12) {
        global $wpdb;

        $property_filter = ($property_type !== 'all')
            ? $wpdb->prepare(" AND la.property_type = %s", $property_type)
            : '';

        $results = $wpdb->get_row($wpdb->prepare("
            SELECT
                COUNT(*) as total_sales,

                -- DOM calculated from dates
                ROUND(AVG(DATEDIFF(la.close_date, la.listing_contract_date)), 0) as avg_dom,
                MIN(DATEDIFF(la.close_date, la.listing_contract_date)) as min_dom,
                MAX(DATEDIFF(la.close_date, la.listing_contract_date)) as max_dom,

                -- DOM distribution
                SUM(CASE WHEN DATEDIFF(la.close_date, la.listing_contract_date) <= 7 THEN 1 ELSE 0 END) as sold_under_7_days,
                SUM(CASE WHEN DATEDIFF(la.close_date, la.listing_contract_date) <= 14 THEN 1 ELSE 0 END) as sold_under_14_days,
                SUM(CASE WHEN DATEDIFF(la.close_date, la.listing_contract_date) <= 30 THEN 1 ELSE 0 END) as sold_under_30_days,
                SUM(CASE WHEN DATEDIFF(la.close_date, la.listing_contract_date) <= 60 THEN 1 ELSE 0 END) as sold_under_60_days,
                SUM(CASE WHEN DATEDIFF(la.close_date, la.listing_contract_date) <= 90 THEN 1 ELSE 0 END) as sold_under_90_days,
                SUM(CASE WHEN DATEDIFF(la.close_date, la.listing_contract_date) > 90 THEN 1 ELSE 0 END) as sold_over_90_days

            FROM {$wpdb->prefix}bme_listings_archive la
            JOIN {$wpdb->prefix}bme_listing_location_archive loc ON la.listing_id = loc.listing_id
            WHERE loc.city = %s
            AND la.standard_status = 'Closed'
            AND la.close_date >= DATE_SUB(NOW(), INTERVAL %d MONTH)
            AND la.listing_contract_date IS NOT NULL
            {$property_filter}
        ", $city, $months), ARRAY_A);

        if (empty($results) || $results['total_sales'] == 0) {
            return array('error' => 'No sales data available');
        }

        // Calculate percentages
        $total = $results['total_sales'];
        $results['pct_under_7_days'] = round(($results['sold_under_7_days'] / $total) * 100, 1);
        $results['pct_under_14_days'] = round(($results['sold_under_14_days'] / $total) * 100, 1);
        $results['pct_under_30_days'] = round(($results['sold_under_30_days'] / $total) * 100, 1);
        $results['pct_under_60_days'] = round(($results['sold_under_60_days'] / $total) * 100, 1);
        $results['pct_under_90_days'] = round(($results['sold_under_90_days'] / $total) * 100, 1);
        $results['pct_over_90_days'] = round(($results['sold_over_90_days'] / $total) * 100, 1);

        // Market speed classification
        if ($results['avg_dom'] <= 30) {
            $results['market_speed'] = 'Very Fast';
        } elseif ($results['avg_dom'] <= 60) {
            $results['market_speed'] = 'Fast';
        } elseif ($results['avg_dom'] <= 90) {
            $results['market_speed'] = 'Normal';
        } else {
            $results['market_speed'] = 'Slow';
        }

        return $results;
    }

    /**
     * Compare performance across property types
     *
     * @param string $city City name
     * @param string $state State abbreviation
     * @param int $months Number of months to analyze
     * @return array Property type comparison
     */
    public static function get_property_type_performance($city, $state = '', $months = 12) {
        global $wpdb;

        $results = $wpdb->get_results($wpdb->prepare("
            SELECT
                la.property_type,
                COUNT(*) as sales_count,
                ROUND(AVG(la.close_price), 0) as avg_price,
                ROUND(AVG(DATEDIFF(la.close_date, la.listing_contract_date)), 0) as avg_dom,
                ROUND(AVG(
                    CASE WHEN la.close_price / NULLIF(la.list_price, 0) * 100 BETWEEN 50 AND 150
                    THEN la.close_price / NULLIF(la.list_price, 0) * 100 END
                ), 2) as sp_lp_ratio,
                ROUND(AVG(la.close_price / NULLIF(d.living_area, 0)), 2) as price_per_sqft,
                ROUND(SUM(la.close_price), 0) as total_volume
            FROM {$wpdb->prefix}bme_listings_archive la
            JOIN {$wpdb->prefix}bme_listing_location_archive loc ON la.listing_id = loc.listing_id
            LEFT JOIN {$wpdb->prefix}bme_listing_details_archive d ON la.listing_id = d.listing_id
            WHERE loc.city = %s
            AND la.standard_status = 'Closed'
            AND la.close_date >= DATE_SUB(NOW(), INTERVAL %d MONTH)
            AND la.list_price > 100
            GROUP BY la.property_type
            HAVING sales_count >= 3
            ORDER BY sales_count DESC
        ", $city, $months), ARRAY_A);

        // Rank by speed (DOM)
        $by_speed = $results;
        usort($by_speed, function($a, $b) {
            return $a['avg_dom'] - $b['avg_dom'];
        });

        return array(
            'by_volume' => $results,
            'by_speed' => $by_speed,
            'fastest_selling' => !empty($by_speed) ? $by_speed[0]['property_type'] : null,
            'highest_sp_lp' => self::get_best_by_field($results, 'sp_lp_ratio'),
            'most_sales' => !empty($results) ? $results[0]['property_type'] : null
        );
    }

    /**
     * Helper to get best property type by a specific field
     */
    private static function get_best_by_field($results, $field) {
        if (empty($results)) return null;
        $sorted = $results;
        usort($sorted, function($a, $b) use ($field) {
            return $b[$field] - $a[$field];
        });
        return $sorted[0]['property_type'] ?? null;
    }

    /**
     * Calculate premium/discount for various features
     *
     * @param string $city City name
     * @param string $state State abbreviation
     * @param string $property_type Property type filter
     * @param int $months Number of months lookback
     * @return array Feature premiums
     */
    public static function get_all_feature_premiums($city, $state = '', $property_type = 'Residential', $months = 24) {
        $features = array(
            'waterfront' => array(
                'table' => 'bme_listing_features_archive',
                'field' => 'waterfront_yn',
                'label' => 'Waterfront'
            ),
            'view' => array(
                'table' => 'bme_listing_features_archive',
                'field' => 'view_yn',
                'label' => 'View Property'
            ),
            'pool' => array(
                'table' => 'bme_listing_features_archive',
                'field' => 'pool_private_yn',
                'label' => 'Private Pool'
            ),
            'fireplace' => array(
                'table' => 'bme_listing_details_archive',
                'field' => 'fireplace_yn',
                'label' => 'Fireplace'
            ),
            'garage' => array(
                'table' => 'bme_listing_details_archive',
                'field' => 'garage_yn',
                'label' => 'Garage'
            )
        );

        $premiums = array();

        foreach ($features as $key => $config) {
            $premium = self::calculate_single_feature_premium(
                $city,
                $state,
                $property_type,
                $config['table'],
                $config['field'],
                $months
            );

            if ($premium && !isset($premium['error'])) {
                $premium['label'] = $config['label'];
                $premiums[$key] = $premium;
            }
        }

        // Sort by premium amount
        uasort($premiums, function($a, $b) {
            return ($b['premium_pct'] ?? 0) - ($a['premium_pct'] ?? 0);
        });

        return $premiums;
    }

    /**
     * Calculate premium for a single feature
     *
     * @param string $city City name
     * @param string $state State abbreviation
     * @param string $property_type Property type filter
     * @param string $table Table name
     * @param string $field Field name
     * @param int $months Number of months lookback
     * @return array Premium data
     */
    private static function calculate_single_feature_premium($city, $state, $property_type, $table, $field, $months = 24) {
        global $wpdb;

        $table_name = $wpdb->prefix . $table;

        $property_filter = ($property_type !== 'all')
            ? $wpdb->prepare(" AND la.property_type = %s", $property_type)
            : '';

        // Get average price WITH feature
        $with_feature = $wpdb->get_row($wpdb->prepare("
            SELECT
                COUNT(*) as count,
                ROUND(AVG(la.close_price), 0) as avg_price,
                ROUND(AVG(la.close_price / NULLIF(d.living_area, 0)), 2) as price_per_sqft
            FROM {$wpdb->prefix}bme_listings_archive la
            JOIN {$wpdb->prefix}bme_listing_location_archive loc ON la.listing_id = loc.listing_id
            LEFT JOIN {$wpdb->prefix}bme_listing_details_archive d ON la.listing_id = d.listing_id
            JOIN {$table_name} f ON la.listing_id = f.listing_id
            WHERE loc.city = %s
            AND la.standard_status = 'Closed'
            AND la.close_date >= DATE_SUB(NOW(), INTERVAL %d MONTH)
            AND la.list_price > 100
            AND f.{$field} = 1
            {$property_filter}
        ", $city, $months), ARRAY_A);

        // Get average price WITHOUT feature
        $without_feature = $wpdb->get_row($wpdb->prepare("
            SELECT
                COUNT(*) as count,
                ROUND(AVG(la.close_price), 0) as avg_price,
                ROUND(AVG(la.close_price / NULLIF(d.living_area, 0)), 2) as price_per_sqft
            FROM {$wpdb->prefix}bme_listings_archive la
            JOIN {$wpdb->prefix}bme_listing_location_archive loc ON la.listing_id = loc.listing_id
            LEFT JOIN {$wpdb->prefix}bme_listing_details_archive d ON la.listing_id = d.listing_id
            JOIN {$table_name} f ON la.listing_id = f.listing_id
            WHERE loc.city = %s
            AND la.standard_status = 'Closed'
            AND la.close_date >= DATE_SUB(NOW(), INTERVAL %d MONTH)
            AND la.list_price > 100
            AND (f.{$field} = 0 OR f.{$field} IS NULL)
            {$property_filter}
        ", $city, $months), ARRAY_A);

        // Need minimum sample sizes
        if ($with_feature['count'] < 5 || $without_feature['count'] < 10) {
            return array('error' => 'Insufficient data');
        }

        $premium_amount = $with_feature['avg_price'] - $without_feature['avg_price'];
        $premium_pct = ($without_feature['avg_price'] > 0)
            ? round(($premium_amount / $without_feature['avg_price']) * 100, 1)
            : 0;

        $premium_per_sqft = ($with_feature['price_per_sqft'] ?? 0) - ($without_feature['price_per_sqft'] ?? 0);

        return array(
            'with_feature_count' => intval($with_feature['count']),
            'without_feature_count' => intval($without_feature['count']),
            'with_feature_avg_price' => intval($with_feature['avg_price']),
            'without_feature_avg_price' => intval($without_feature['avg_price']),
            'premium_amount' => intval($premium_amount),
            'premium_pct' => $premium_pct,
            'premium_per_sqft' => round($premium_per_sqft, 2),
            'confidence' => self::calculate_premium_confidence($with_feature['count'], $without_feature['count'])
        );
    }

    /**
     * Calculate confidence level based on sample sizes
     */
    private static function calculate_premium_confidence($with_count, $without_count) {
        $min_count = min($with_count, $without_count);
        if ($min_count >= 50) return 'High';
        if ($min_count >= 20) return 'Medium';
        if ($min_count >= 10) return 'Low';
        return 'Very Low';
    }

    /**
     * Get comprehensive agent performance metrics
     *
     * @param string $city City name
     * @param string $state State abbreviation
     * @param int $limit Number of agents to return
     * @param string $role 'listing' or 'buyer'
     * @return array Agent performance data
     */
    public static function get_agent_performance_detailed($city, $state = '', $limit = 20, $role = 'listing') {
        global $wpdb;

        $agent_field = ($role === 'buyer') ? 'buyer_agent_mls_id' : 'list_agent_mls_id';

        $results = $wpdb->get_results($wpdb->prepare("
            SELECT
                la.{$agent_field} as agent_mls_id,
                a.agent_full_name as agent_name,
                a.agent_email,
                a.agent_phone,
                o.office_name,

                -- Volume metrics
                COUNT(*) as transaction_count,
                ROUND(SUM(la.close_price), 0) as total_volume,
                ROUND(AVG(la.close_price), 0) as avg_sale_price,

                -- Performance metrics
                ROUND(AVG(DATEDIFF(la.close_date, la.listing_contract_date)), 0) as avg_dom,
                ROUND(AVG(
                    CASE WHEN la.close_price / NULLIF(la.list_price, 0) * 100 BETWEEN 50 AND 150
                    THEN la.close_price / NULLIF(la.list_price, 0) * 100 END
                ), 2) as avg_sp_lp_ratio,

                -- Price range
                MIN(la.close_price) as min_sale,
                MAX(la.close_price) as max_sale

            FROM {$wpdb->prefix}bme_listings_archive la
            JOIN {$wpdb->prefix}bme_listing_location_archive loc ON la.listing_id = loc.listing_id
            LEFT JOIN {$wpdb->prefix}bme_agents a ON la.{$agent_field} = a.agent_mls_id
            LEFT JOIN {$wpdb->prefix}bme_offices o ON a.office_mls_id = o.office_mls_id
            WHERE loc.city = %s
            AND la.standard_status = 'Closed'
            AND la.close_date >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
            AND la.property_type = 'Residential'
            AND la.{$agent_field} IS NOT NULL
            GROUP BY la.{$agent_field}, a.agent_full_name, a.agent_email, a.agent_phone, o.office_name
            HAVING transaction_count >= 2
            ORDER BY total_volume DESC
            LIMIT %d
        ", $city, $limit), ARRAY_A);

        // Calculate market share
        $total_volume = array_sum(array_column($results, 'total_volume'));
        $total_transactions = array_sum(array_column($results, 'transaction_count'));

        foreach ($results as &$agent) {
            $agent['market_share_volume'] = ($total_volume > 0)
                ? round(($agent['total_volume'] / $total_volume) * 100, 2)
                : 0;
            $agent['market_share_transactions'] = ($total_transactions > 0)
                ? round(($agent['transaction_count'] / $total_transactions) * 100, 2)
                : 0;
        }

        return array(
            'agents' => $results,
            'market_totals' => array(
                'total_volume' => $total_volume,
                'total_transactions' => $total_transactions,
                'agent_count' => count($results)
            )
        );
    }

    /**
     * Get buyer agent performance (who represents buyers)
     */
    public static function get_buyer_agent_performance($city, $state = '', $limit = 20) {
        return self::get_agent_performance_detailed($city, $state, $limit, 'buyer');
    }

    /**
     * Get tax and financial metrics
     *
     * @param string $city City name
     * @param string $state State abbreviation
     * @param string $property_type Property type filter
     * @param int $months Number of months lookback
     * @return array Financial metrics
     */
    public static function get_financial_analysis($city, $state = '', $property_type = 'Residential', $months = 24) {
        global $wpdb;

        $property_filter = ($property_type !== 'all')
            ? $wpdb->prepare(" AND la.property_type = %s", $property_type)
            : '';

        $results = $wpdb->get_row($wpdb->prepare("
            SELECT
                -- Tax metrics
                COUNT(CASE WHEN f.tax_annual_amount > 0 THEN 1 END) as properties_with_tax_data,
                ROUND(AVG(CASE WHEN f.tax_annual_amount > 0 THEN f.tax_annual_amount END), 0) as avg_annual_tax,
                ROUND(MIN(CASE WHEN f.tax_annual_amount > 0 THEN f.tax_annual_amount END), 0) as min_annual_tax,
                ROUND(MAX(CASE WHEN f.tax_annual_amount > 0 THEN f.tax_annual_amount END), 0) as max_annual_tax,

                -- Tax rate (tax / sale price)
                ROUND(AVG(CASE WHEN f.tax_annual_amount > 0 AND la.close_price > 0
                    THEN f.tax_annual_amount / la.close_price * 100 END), 3) as avg_effective_tax_rate,

                -- HOA metrics
                SUM(f.association_yn) as properties_with_hoa,
                COUNT(*) as total_properties,
                ROUND(AVG(CASE WHEN f.association_fee > 0 THEN f.association_fee END), 0) as avg_hoa_fee,
                ROUND(MIN(CASE WHEN f.association_fee > 0 THEN f.association_fee END), 0) as min_hoa_fee,
                ROUND(MAX(CASE WHEN f.association_fee > 0 THEN f.association_fee END), 0) as max_hoa_fee

            FROM {$wpdb->prefix}bme_listings_archive la
            JOIN {$wpdb->prefix}bme_listing_location_archive loc ON la.listing_id = loc.listing_id
            LEFT JOIN {$wpdb->prefix}bme_listing_financial_archive f ON la.listing_id = f.listing_id
            WHERE loc.city = %s
            AND la.standard_status = 'Closed'
            AND la.close_date >= DATE_SUB(NOW(), INTERVAL %d MONTH)
            {$property_filter}
        ", $city, $months), ARRAY_A);

        if ($results) {
            $results['hoa_percentage'] = ($results['total_properties'] > 0)
                ? round(($results['properties_with_hoa'] / $results['total_properties']) * 100, 1)
                : 0;
        }

        return $results;
    }

    /**
     * Analyze property characteristics in sales
     *
     * @param string $city City name
     * @param string $state State abbreviation
     * @param int $months Number of months
     * @return array Property characteristics
     */
    public static function get_property_characteristics($city, $state = '', $months = 12) {
        global $wpdb;

        $results = $wpdb->get_row($wpdb->prepare("
            SELECT
                -- Size metrics
                ROUND(AVG(d.living_area), 0) as avg_sqft,
                ROUND(MIN(d.living_area), 0) as min_sqft,
                ROUND(MAX(d.living_area), 0) as max_sqft,

                -- Bedroom distribution
                ROUND(AVG(d.bedrooms_total), 1) as avg_bedrooms,
                SUM(CASE WHEN d.bedrooms_total = 1 THEN 1 ELSE 0 END) as one_bed_count,
                SUM(CASE WHEN d.bedrooms_total = 2 THEN 1 ELSE 0 END) as two_bed_count,
                SUM(CASE WHEN d.bedrooms_total = 3 THEN 1 ELSE 0 END) as three_bed_count,
                SUM(CASE WHEN d.bedrooms_total = 4 THEN 1 ELSE 0 END) as four_bed_count,
                SUM(CASE WHEN d.bedrooms_total >= 5 THEN 1 ELSE 0 END) as five_plus_bed_count,

                -- Bathroom metrics
                ROUND(AVG(d.bathrooms_total_integer + COALESCE(d.bathrooms_half, 0) * 0.5), 1) as avg_bathrooms,

                -- Age metrics
                ROUND(AVG(YEAR(NOW()) - d.year_built), 0) as avg_age,
                MIN(d.year_built) as oldest_year_built,
                MAX(d.year_built) as newest_year_built,
                SUM(CASE WHEN d.year_built >= 2020 THEN 1 ELSE 0 END) as new_construction_count,
                SUM(CASE WHEN d.year_built BETWEEN 2000 AND 2019 THEN 1 ELSE 0 END) as built_2000s_count,
                SUM(CASE WHEN d.year_built BETWEEN 1980 AND 1999 THEN 1 ELSE 0 END) as built_1980s_90s_count,
                SUM(CASE WHEN d.year_built < 1980 THEN 1 ELSE 0 END) as built_pre_1980_count,

                -- Features
                SUM(CASE WHEN d.garage_spaces > 0 THEN 1 ELSE 0 END) as has_garage_count,
                ROUND(AVG(CASE WHEN d.garage_spaces > 0 THEN d.garage_spaces END), 1) as avg_garage_spaces,
                SUM(d.fireplace_yn) as has_fireplace_count,

                COUNT(*) as total_count

            FROM {$wpdb->prefix}bme_listings_archive la
            JOIN {$wpdb->prefix}bme_listing_location_archive loc ON la.listing_id = loc.listing_id
            LEFT JOIN {$wpdb->prefix}bme_listing_details_archive d ON la.listing_id = d.listing_id
            WHERE loc.city = %s
            AND la.standard_status = 'Closed'
            AND la.close_date >= DATE_SUB(NOW(), INTERVAL %d MONTH)
            AND la.property_type = 'Residential'
        ", $city, $months), ARRAY_A);

        // Calculate percentages
        if ($results && $results['total_count'] > 0) {
            $total = $results['total_count'];
            $results['pct_has_garage'] = round(($results['has_garage_count'] / $total) * 100, 1);
            $results['pct_has_fireplace'] = round(($results['has_fireplace_count'] / $total) * 100, 1);
            $results['pct_new_construction'] = round(($results['new_construction_count'] / $total) * 100, 1);

            // Most common bedroom count
            $bed_counts = array(
                1 => $results['one_bed_count'],
                2 => $results['two_bed_count'],
                3 => $results['three_bed_count'],
                4 => $results['four_bed_count'],
                '5+' => $results['five_plus_bed_count']
            );
            arsort($bed_counts);
            $results['most_common_bedrooms'] = key($bed_counts);
        }

        return $results;
    }

    /**
     * Get price analysis by bedroom count
     *
     * @param string $city City name
     * @param string $state State abbreviation
     * @param int $months Number of months
     * @return array Price by bedroom count
     */
    public static function get_price_by_bedrooms($city, $state = '', $months = 12) {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare("
            SELECT
                d.bedrooms_total as bedrooms,
                COUNT(*) as sales_count,
                ROUND(AVG(la.close_price), 0) as avg_price,
                ROUND(AVG(la.close_price / NULLIF(d.living_area, 0)), 2) as price_per_sqft,
                ROUND(AVG(d.living_area), 0) as avg_sqft,
                ROUND(AVG(DATEDIFF(la.close_date, la.listing_contract_date)), 0) as avg_dom
            FROM {$wpdb->prefix}bme_listings_archive la
            JOIN {$wpdb->prefix}bme_listing_location_archive loc ON la.listing_id = loc.listing_id
            LEFT JOIN {$wpdb->prefix}bme_listing_details_archive d ON la.listing_id = d.listing_id
            WHERE loc.city = %s
            AND la.standard_status = 'Closed'
            AND la.close_date >= DATE_SUB(NOW(), INTERVAL %d MONTH)
            AND la.property_type = 'Residential'
            AND d.bedrooms_total IS NOT NULL
            AND d.bedrooms_total > 0
            GROUP BY d.bedrooms_total
            HAVING sales_count >= 3
            ORDER BY d.bedrooms_total
        ", $city, $months), ARRAY_A);
    }

    /**
     * Get comprehensive market summary combining all metrics
     *
     * @param string $city City name
     * @param string $state State abbreviation
     * @param string $property_type Property type filter
     * @return array Complete market summary
     */
    public static function get_comprehensive_market_summary($city, $state = '', $property_type = 'Residential') {
        return array(
            'city' => $city,
            'state' => $state,
            'property_type' => $property_type,
            'generated_at' => current_time('mysql'),
            'price_analysis' => self::get_price_analysis($city, $state, $property_type, 12),
            'dom_analysis' => self::get_dom_analysis($city, $state, $property_type, 12),
            'supply_demand' => self::get_supply_demand_metrics($city, $state, $property_type),
            'market_heat' => self::get_market_heat_index($city, $state, $property_type),
            'property_types' => self::get_property_type_performance($city, $state, 12),
            'feature_premiums' => self::get_all_feature_premiums($city, $state, $property_type),
            'financial' => self::get_financial_analysis($city, $state, $property_type),
            'characteristics' => self::get_property_characteristics($city, $state, 12),
            'price_by_bedrooms' => self::get_price_by_bedrooms($city, $state, 12),
            'seasonal_patterns' => self::get_seasonal_patterns($city, $state, $property_type)
        );
    }
}

// Initialize singleton
MLD_Extended_Analytics::get_instance();
