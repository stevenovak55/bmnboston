<?php
/**
 * Neighborhood Analytics Calculator
 *
 * Calculates comprehensive market analytics for neighborhoods/cities
 * including price trends, market velocity, inventory analysis, and sales performance.
 *
 * @package    MLS_Listings_Display
 * @subpackage MLS_Listings_Display/includes
 * @since      5.2.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class MLD_Neighborhood_Analytics {

    /**
     * Database table names
     */
    private $analytics_table;
    private $trends_table;
    private $meta_table;
    private $cache_table;

    /**
     * BME table names (from Bridge MLS Extractor)
     */
    private $bme_listings;
    private $bme_listings_archive;
    private $bme_summary;

    /**
     * Cache expiration times (in seconds)
     */
    const CACHE_EXPIRY_ANALYTICS = 86400; // 24 hours
    const CACHE_EXPIRY_TRENDS = 604800;   // 7 days
    const CACHE_EXPIRY_COMPARISON = 43200; // 12 hours

    /**
     * Market heat index thresholds
     */
    const HEAT_HOT_MIN = 70;
    const HEAT_BALANCED_MIN = 40;
    const HEAT_COLD_MAX = 39;

    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;

        // Set table names
        $this->analytics_table = $wpdb->prefix . 'mld_neighborhood_analytics';
        $this->trends_table = $wpdb->prefix . 'mld_neighborhood_trends';
        $this->meta_table = $wpdb->prefix . 'mld_neighborhood_meta';
        $this->cache_table = $wpdb->prefix . 'mld_analytics_cache';

        // BME tables
        $this->bme_listings = $wpdb->prefix . 'bme_listings';
        $this->bme_listings_archive = $wpdb->prefix . 'bme_listings_archive';
        $this->bme_summary = $wpdb->prefix . 'bme_listing_summary';
    }

    /**
     * Create database tables
     *
     * @return bool Success status
     */
    public function create_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        // Read schema file
        $schema_file = plugin_dir_path(__FILE__) . 'schema/neighborhood-analytics.sql';

        if (!file_exists($schema_file)) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log('MLD Neighborhood Analytics: Schema file not found at ' . $schema_file);
            }
            return false;
        }

        $schema = file_get_contents($schema_file);

        // Replace table prefixes
        $schema = str_replace('wp_mld_', $wpdb->prefix . 'mld_', $schema);

        // Execute each CREATE TABLE statement
        $statements = explode(';', $schema);

        foreach ($statements as $statement) {
            $statement = trim($statement);
            if (empty($statement) || strpos($statement, 'CREATE TABLE') === false) {
                continue;
            }

            dbDelta($statement . ';');
        }

        // Verify tables were created
        $tables_created = array(
            $this->analytics_table,
            $this->trends_table,
            $this->meta_table,
            $this->cache_table
        );

        foreach ($tables_created as $table) {
            if ($wpdb->get_var("SHOW TABLES LIKE '$table'") != $table) {
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log("MLD Neighborhood Analytics: Failed to create table $table");
                }
                return false;
            }
        }

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log('MLD Neighborhood Analytics: All tables created successfully');
        }
        return true;
    }

    /**
     * Calculate analytics for a specific city
     *
     * @param string $city City name
     * @param string $state State abbreviation (optional)
     * @param string $property_type Property type filter (default: 'all')
     * @return array|WP_Error Analytics data or WP_Error
     */
    public function calculate_city_analytics($city, $state = '', $property_type = 'all') {
        global $wpdb;

        if (empty($city)) {
            return new WP_Error('invalid_city', 'City name is required');
        }

        $results = array();

        // Calculate analytics for different time periods
        $periods = array(
            'current' => array('months' => 0, 'label' => 'Current'),
            '6_months' => array('months' => 6, 'label' => '6 Months'),
            '12_months' => array('months' => 12, 'label' => '12 Months'),
            '24_months' => array('months' => 24, 'label' => '24 Months')
        );

        foreach ($periods as $period_key => $period_config) {
            $analytics = $this->calculate_period_analytics(
                $city,
                $state,
                $period_key,
                $period_config['months'],
                $property_type
            );

            if (!is_wp_error($analytics)) {
                $results[$period_key] = $analytics;
            }
        }

        // Calculate historical trends for charting
        $this->calculate_historical_trends($city, $state, $property_type);

        return $results;
    }

    /**
     * Calculate analytics for a specific time period
     *
     * @param string $city City name
     * @param string $state State abbreviation
     * @param string $period Period key
     * @param int $months Number of months (0 = current)
     * @param string $property_type Property type filter
     * @return array|WP_Error Analytics data
     */
    private function calculate_period_analytics($city, $state, $period, $months, $property_type) {
        global $wpdb;

        // Build date condition
        if ($months > 0) {
            $date_condition = $wpdb->prepare(
                "AND modification_timestamp >= DATE_SUB(NOW(), INTERVAL %d MONTH)",
                $months
            );
        } else {
            $date_condition = "";
        }

        // Build property type condition
        $type_condition = $property_type !== 'all'
            ? $wpdb->prepare("AND property_type = %s", $property_type)
            : "";

        // Use summary table if available, otherwise full tables
        $use_summary = $wpdb->get_var("SHOW TABLES LIKE '$this->bme_summary'") == $this->bme_summary;

        if ($use_summary) {
            $listings_table = $this->bme_summary;
        } else {
            $listings_table = "
                (SELECT * FROM {$this->bme_listings}
                 UNION ALL
                 SELECT * FROM {$this->bme_listings_archive})";
        }

        // Calculate all metrics
        $analytics = array();

        // Price metrics
        $analytics['price_metrics'] = $this->calculate_price_metrics(
            $city, $state, $date_condition, $type_condition, $listings_table
        );

        // Market velocity metrics
        $analytics['velocity_metrics'] = $this->calculate_velocity_metrics(
            $city, $state, $date_condition, $type_condition, $listings_table
        );

        // Inventory metrics
        $analytics['inventory_metrics'] = $this->calculate_inventory_metrics(
            $city, $state, $date_condition, $type_condition, $listings_table
        );

        // Sales performance metrics
        $analytics['sales_metrics'] = $this->calculate_sales_performance_metrics(
            $city, $state, $date_condition, $type_condition, $listings_table
        );

        // Calculate market heat index
        $analytics['market_heat'] = $this->calculate_market_heat_index($analytics);

        // Combine all metrics into single array
        $combined = array_merge(
            $analytics['price_metrics'],
            $analytics['velocity_metrics'],
            $analytics['inventory_metrics'],
            $analytics['sales_metrics'],
            $analytics['market_heat']
        );

        // Add metadata
        $combined['city'] = $city;
        $combined['state'] = $state;
        $combined['period'] = $period;
        $combined['property_type'] = $property_type;
        $combined['calculation_date'] = current_time('mysql');

        // Save to database
        $this->save_analytics($combined);

        return $combined;
    }

    /**
     * Calculate price metrics
     */
    private function calculate_price_metrics($city, $state, $date_condition, $type_condition, $listings_table) {
        global $wpdb;

        $city_condition = $wpdb->prepare("city = %s", $city);
        if (!empty($state)) {
            $city_condition .= $wpdb->prepare(" AND state_or_province = %s", $state);
        }

        $query = "
            SELECT
                COUNT(*) as data_points,
                AVG(list_price) as average_price,
                MEDIAN(list_price) as median_price,
                MIN(list_price) as min_price,
                MAX(list_price) as max_price,
                AVG(CASE WHEN building_area_total > 0 THEN list_price / building_area_total ELSE NULL END) as price_per_sqft_average,
                MEDIAN(CASE WHEN building_area_total > 0 THEN list_price / building_area_total ELSE NULL END) as price_per_sqft_median
            FROM $listings_table
            WHERE $city_condition
            $date_condition
            $type_condition
            AND list_price > 0
        ";

        // Note: MySQL doesn't have native MEDIAN, we'll use a workaround
        // For now, use AVG as approximation (will implement proper median later)
        $query = str_replace('MEDIAN(', 'AVG(', $query);

        $results = $wpdb->get_row($query, ARRAY_A);

        if (!$results || $results['data_points'] == 0) {
            return array(
                'median_price' => null,
                'average_price' => null,
                'min_price' => null,
                'max_price' => null,
                'price_per_sqft_median' => null,
                'price_per_sqft_average' => null,
                'data_points' => 0
            );
        }

        return array(
            'median_price' => round($results['median_price'], 2),
            'average_price' => round($results['average_price'], 2),
            'min_price' => round($results['min_price'], 2),
            'max_price' => round($results['max_price'], 2),
            'price_per_sqft_median' => round($results['price_per_sqft_median'], 2),
            'price_per_sqft_average' => round($results['price_per_sqft_average'], 2),
            'data_points' => intval($results['data_points'])
        );
    }

    /**
     * Calculate market velocity metrics (Days on Market, turnover, etc.)
     */
    private function calculate_velocity_metrics($city, $state, $date_condition, $type_condition, $listings_table) {
        global $wpdb;

        $city_condition = $wpdb->prepare("city = %s", $city);
        if (!empty($state)) {
            $city_condition .= $wpdb->prepare(" AND state_or_province = %s", $state);
        }

        $query = "
            SELECT
                AVG(days_on_market) as avg_days_on_market,
                AVG(days_on_market) as median_days_on_market,
                AVG(CASE
                    WHEN close_date IS NOT NULL AND listing_contract_date IS NOT NULL
                    THEN DATEDIFF(close_date, listing_contract_date)
                    ELSE NULL
                END) as avg_days_to_close,
                COUNT(CASE WHEN standard_status = 'Closed' THEN 1 END) as sold_count,
                COUNT(*) as total_count
            FROM $listings_table
            WHERE $city_condition
            $date_condition
            $type_condition
        ";

        $results = $wpdb->get_row($query, ARRAY_A);

        if (!$results) {
            return array(
                'avg_days_on_market' => null,
                'median_days_on_market' => null,
                'avg_days_to_close' => null,
                'listing_turnover_rate' => null
            );
        }

        $turnover_rate = $results['total_count'] > 0
            ? ($results['sold_count'] / $results['total_count']) * 100
            : 0;

        return array(
            'avg_days_on_market' => round($results['avg_days_on_market'], 0),
            'median_days_on_market' => round($results['median_days_on_market'], 0),
            'avg_days_to_close' => round($results['avg_days_to_close'], 0),
            'listing_turnover_rate' => round($turnover_rate, 2)
        );
    }

    /**
     * Calculate inventory metrics
     */
    private function calculate_inventory_metrics($city, $state, $date_condition, $type_condition, $listings_table) {
        global $wpdb;

        $city_condition = $wpdb->prepare("city = %s", $city);
        if (!empty($state)) {
            $city_condition .= $wpdb->prepare(" AND state_or_province = %s", $state);
        }

        $query = "
            SELECT
                COUNT(CASE WHEN standard_status = 'Active' OR standard_status = 'Active Under Contract' THEN 1 END) as active_count,
                COUNT(CASE WHEN standard_status = 'Pending' THEN 1 END) as pending_count,
                COUNT(CASE WHEN standard_status = 'Closed' THEN 1 END) as sold_count,
                COUNT(*) as total_inventory
            FROM $listings_table
            WHERE $city_condition
            $date_condition
            $type_condition
        ";

        $results = $wpdb->get_row($query, ARRAY_A);

        if (!$results) {
            return array(
                'active_listings_count' => 0,
                'pending_listings_count' => 0,
                'sold_listings_count' => 0,
                'total_inventory' => 0,
                'months_of_supply' => null,
                'absorption_rate' => null
            );
        }

        // Calculate months of supply
        // Formula: Active inventory / Average monthly sales
        $monthly_sales = $results['sold_count'] / 12; // Assuming annual data divided by 12
        $months_of_supply = $monthly_sales > 0
            ? $results['active_count'] / $monthly_sales
            : null;

        // Calculate absorption rate (% of inventory sold per month)
        $absorption_rate = $results['total_inventory'] > 0
            ? ($monthly_sales / $results['total_inventory']) * 100
            : 0;

        return array(
            'active_listings_count' => intval($results['active_count']),
            'pending_listings_count' => intval($results['pending_count']),
            'sold_listings_count' => intval($results['sold_count']),
            'total_inventory' => intval($results['total_inventory']),
            'months_of_supply' => $months_of_supply ? round($months_of_supply, 2) : null,
            'absorption_rate' => round($absorption_rate, 2)
        );
    }

    /**
     * Calculate sales performance metrics
     */
    private function calculate_sales_performance_metrics($city, $state, $date_condition, $type_condition, $listings_table) {
        global $wpdb;

        $city_condition = $wpdb->prepare("city = %s", $city);
        if (!empty($state)) {
            $city_condition .= $wpdb->prepare(" AND state_or_province = %s", $state);
        }

        $query = "
            SELECT
                AVG(CASE
                    WHEN close_price > 0 AND list_price > 0
                    THEN close_price / list_price
                    ELSE NULL
                END) as sale_to_list_ratio,
                AVG(CASE
                    WHEN close_price > 0 AND list_price > 0
                    THEN close_price / list_price
                    ELSE NULL
                END) as sale_to_list_median,
                COUNT(CASE WHEN original_list_price > list_price THEN 1 END) as price_reductions,
                COUNT(*) as total_listings,
                AVG(CASE
                    WHEN original_list_price > list_price
                    THEN original_list_price - list_price
                    ELSE NULL
                END) as avg_price_reduction
            FROM $listings_table
            WHERE $city_condition
            $date_condition
            $type_condition
            AND standard_status = 'Closed'
        ";

        $results = $wpdb->get_row($query, ARRAY_A);

        if (!$results || $results['total_listings'] == 0) {
            return array(
                'sale_to_list_ratio' => null,
                'sale_to_list_median' => null,
                'price_reduction_pct' => null,
                'avg_price_reduction_amount' => null
            );
        }

        $price_reduction_pct = $results['total_listings'] > 0
            ? ($results['price_reductions'] / $results['total_listings']) * 100
            : 0;

        return array(
            'sale_to_list_ratio' => round($results['sale_to_list_ratio'], 4),
            'sale_to_list_median' => round($results['sale_to_list_median'], 4),
            'price_reduction_pct' => round($price_reduction_pct, 2),
            'avg_price_reduction_amount' => round($results['avg_price_reduction'], 2)
        );
    }

    /**
     * Calculate market heat index
     *
     * Hot Market (70-100): Low DOM, high turnover, high sale-to-list ratio
     * Balanced (40-69): Moderate metrics
     * Cold Market (0-39): High DOM, low turnover, low sale-to-list ratio
     */
    private function calculate_market_heat_index($analytics) {
        $score = 50; // Start at balanced

        // Days on market factor (lower is better)
        if (isset($analytics['velocity_metrics']['avg_days_on_market'])) {
            $dom = $analytics['velocity_metrics']['avg_days_on_market'];
            if ($dom < 30) {
                $score += 15;
            } elseif ($dom < 60) {
                $score += 5;
            } elseif ($dom > 90) {
                $score -= 10;
            } elseif ($dom > 120) {
                $score -= 20;
            }
        }

        // Turnover rate factor (higher is better)
        if (isset($analytics['velocity_metrics']['listing_turnover_rate'])) {
            $turnover = $analytics['velocity_metrics']['listing_turnover_rate'];
            if ($turnover > 80) {
                $score += 15;
            } elseif ($turnover > 60) {
                $score += 10;
            } elseif ($turnover < 40) {
                $score -= 10;
            } elseif ($turnover < 20) {
                $score -= 15;
            }
        }

        // Sale to list ratio (closer to 1.0 or above is better)
        if (isset($analytics['sales_metrics']['sale_to_list_ratio'])) {
            $ratio = $analytics['sales_metrics']['sale_to_list_ratio'];
            if ($ratio >= 1.0) {
                $score += 10;
            } elseif ($ratio >= 0.98) {
                $score += 5;
            } elseif ($ratio < 0.95) {
                $score -= 10;
            } elseif ($ratio < 0.90) {
                $score -= 15;
            }
        }

        // Inventory months of supply (lower is hotter)
        if (isset($analytics['inventory_metrics']['months_of_supply'])) {
            $supply = $analytics['inventory_metrics']['months_of_supply'];
            if ($supply < 3) {
                $score += 10;
            } elseif ($supply < 6) {
                $score += 5;
            } elseif ($supply > 9) {
                $score -= 10;
            } elseif ($supply > 12) {
                $score -= 15;
            }
        }

        // Ensure score is within 0-100
        $score = max(0, min(100, $score));

        // Classify market
        if ($score >= self::HEAT_HOT_MIN) {
            $classification = 'hot';
            $description = "Seller's Market";
        } elseif ($score >= self::HEAT_BALANCED_MIN) {
            $classification = 'balanced';
            $description = 'Balanced Market';
        } else {
            $classification = 'cold';
            $description = "Buyer's Market";
        }

        return array(
            'market_heat_index' => round($score, 2),
            'market_classification' => $classification,
            'market_description' => $description
        );
    }

    /**
     * Save analytics to database
     */
    private function save_analytics($data) {
        global $wpdb;

        // Check if record exists
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->analytics_table}
             WHERE city = %s AND state = %s AND period = %s AND property_type = %s",
            $data['city'],
            $data['state'],
            $data['period'],
            $data['property_type']
        ));

        if ($existing) {
            // Update
            $wpdb->update(
                $this->analytics_table,
                $data,
                array(
                    'city' => $data['city'],
                    'state' => $data['state'],
                    'period' => $data['period'],
                    'property_type' => $data['property_type']
                ),
                null,
                array('%s', '%s', '%s', '%s')
            );
        } else {
            // Insert
            $wpdb->insert($this->analytics_table, $data);
        }
    }

    /**
     * Calculate historical trends for charting
     */
    private function calculate_historical_trends($city, $state, $property_type) {
        // This will calculate monthly data points for the last 24 months
        // Implementation will be added in next iteration
        // For now, just log that it was called
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log("MLD Analytics: Calculating historical trends for $city");
        }
    }

    /**
     * Get analytics for a city (from database or calculate if needed)
     */
    public function get_city_analytics($city, $state = '', $property_type = 'all', $force_recalculate = false) {
        global $wpdb;

        if (!$force_recalculate) {
            // Try to get from database
            $analytics = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$this->analytics_table}
                 WHERE city = %s AND state = %s AND property_type = %s
                 AND calculation_date >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                 ORDER BY period",
                $city,
                $state,
                $property_type
            ), ARRAY_A);

            if (!empty($analytics)) {
                // Format by period
                $formatted = array();
                foreach ($analytics as $row) {
                    $formatted[$row['period']] = $row;
                }
                return $formatted;
            }
        }

        // Calculate fresh analytics
        return $this->calculate_city_analytics($city, $state, $property_type);
    }

    /**
     * Get list of cities with analytics
     */
    public function get_cities_with_analytics() {
        global $wpdb;

        $results = $wpdb->get_results(
            "SELECT DISTINCT city, state, MAX(calculation_date) as last_calculated
             FROM {$this->analytics_table}
             GROUP BY city, state
             ORDER BY city",
            ARRAY_A
        );

        return $results;
    }

    /**
     * Compare multiple neighborhoods
     */
    public function compare_neighborhoods($cities, $state = '', $property_type = 'all', $period = '12_months') {
        $comparison = array();

        foreach ($cities as $city) {
            $analytics = $this->get_city_analytics($city, $state, $property_type);
            if (isset($analytics[$period])) {
                $comparison[$city] = $analytics[$period];
            }
        }

        return $comparison;
    }

    /**
     * Clear analytics cache
     */
    public function clear_cache($city = null, $state = null) {
        global $wpdb;

        if ($city) {
            $where = $wpdb->prepare("WHERE city = %s", $city);
            if ($state) {
                $where .= $wpdb->prepare(" AND state = %s", $state);
            }
            $wpdb->query("DELETE FROM {$this->analytics_table} $where");
        } else {
            $wpdb->query("TRUNCATE TABLE {$this->analytics_table}");
        }

        return true;
    }
}
