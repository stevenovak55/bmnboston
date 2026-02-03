<?php
/**
 * MLD Market Conditions Calculator
 *
 * Calculates comprehensive market conditions analysis including:
 * - Days on Market trends
 * - List-to-Sale price ratios
 * - Inventory levels (months of supply)
 * - Price per square foot trends
 * - Market health indicators
 *
 * @package MLS_Listings_Display
 * @subpackage CMA
 * @since 6.18.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Market_Conditions {

    /**
     * Cache group for transients
     *
     * @var string
     */
    private $cache_group = 'mld_market_conditions';

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
        $this->cache_duration = apply_filters('mld_market_conditions_cache_duration', $this->cache_duration);
    }

    /**
     * Map detailed property subtypes to database categories
     *
     * The database uses broad categories (Residential, Commercial, etc.)
     * but CMAs may use detailed subtypes (Single Family Residence, Condo, etc.)
     *
     * @since 6.20.3
     * @param string $property_type The property type to normalize
     * @return string The normalized property type for database queries
     */
    private function normalize_property_type($property_type) {
        if (empty($property_type) || $property_type === 'all') {
            return 'all';
        }

        // Map detailed subtypes to broad database categories
        $residential_types = array(
            'single family residence',
            'single family',
            'single-family',
            'sfr',
            'detached',
            'condo',
            'condominium',
            'townhouse',
            'townhome',
            'town house',
            'attached',
            'duplex',
            'triplex',
            'quadruplex',
            'multi-family',
            'multifamily',
            'two family',
            'three family',
            'four family',
            'ranch',
            'colonial',
            'cape',
            'cape cod',
            'split level',
            'contemporary',
            'victorian',
        );

        $commercial_types = array(
            'commercial',
            'office',
            'retail',
            'industrial',
            'warehouse',
            'mixed use',
            'mixed-use',
        );

        $land_types = array(
            'land',
            'lot',
            'vacant land',
            'acreage',
            'farm',
            'ranch land',
        );

        $lower_type = strtolower(trim($property_type));

        // Check if it's already a database category
        $db_categories = array('residential', 'residential lease', 'residential income',
                               'commercial sale', 'commercial lease', 'land', 'business opportunity');
        if (in_array($lower_type, $db_categories)) {
            return $property_type; // Return as-is
        }

        // Map to broad categories
        foreach ($residential_types as $res_type) {
            if (strpos($lower_type, $res_type) !== false) {
                return 'Residential';
            }
        }

        foreach ($commercial_types as $com_type) {
            if (strpos($lower_type, $com_type) !== false) {
                return 'Commercial Sale';
            }
        }

        foreach ($land_types as $land_type) {
            if (strpos($lower_type, $land_type) !== false) {
                return 'Land';
            }
        }

        // If no match, return 'all' to skip filtering
        // This ensures we still get market data even for unknown types
        error_log("[MLD Market Conditions] Unknown property type: {$property_type} - using 'all'");
        return 'all';
    }

    /**
     * Get comprehensive market conditions for a location
     *
     * @param string $city City name
     * @param string $state State abbreviation
     * @param string $property_type Property type (optional)
     * @param int $months Number of months to analyze (default 12)
     * @return array Market conditions data
     */
    public function get_market_conditions($city, $state = '', $property_type = 'all', $months = 12) {
        // Normalize property type to match database categories (v6.20.3)
        $original_property_type = $property_type;
        $property_type = $this->normalize_property_type($property_type);

        $cache_key = "conditions_{$city}_{$state}_{$property_type}_{$months}";

        $cached = $this->get_cached($cache_key);
        if ($cached !== false) {
            return $cached;
        }

        // Gather all market data
        $dom_trends = $this->get_dom_trends($city, $state, $property_type, $months);
        $list_sale_ratio = $this->get_list_to_sale_ratio($city, $state, $property_type, $months);
        $inventory = $this->get_inventory_analysis($city, $state, $property_type);
        $price_trends = $this->get_price_trends($city, $state, $property_type, $months);
        $market_health = $this->calculate_market_health($dom_trends, $list_sale_ratio, $inventory, $price_trends);

        $conditions = array(
            'success' => true,
            'location' => array(
                'city' => $city,
                'state' => $state,
                'property_type' => $property_type,
            ),
            'analysis_period' => array(
                'months' => $months,
                'start_date' => wp_date('Y-m-d', current_time('timestamp') - ($months * 30 * DAY_IN_SECONDS)),
                'end_date' => wp_date('Y-m-d'),
            ),
            'days_on_market' => $dom_trends,
            'list_to_sale_ratio' => $list_sale_ratio,
            'inventory' => $inventory,
            'price_trends' => $price_trends,
            'market_health' => $market_health,
            'generated_at' => current_time('mysql'),
        );

        // Cache result
        $this->set_cached($cache_key, $conditions);

        return $conditions;
    }

    /**
     * Get Days on Market trends by month
     *
     * @param string $city City name
     * @param string $state State abbreviation
     * @param string $property_type Property type
     * @param int $months Number of months
     * @return array DOM trend data
     */
    public function get_dom_trends($city, $state = '', $property_type = 'all', $months = 12) {
        $table_prefix = $this->wpdb->prefix;
        $wp_now = current_time('mysql');

        $query = "
            SELECT
                DATE_FORMAT(close_date, '%%Y-%%m') as month,
                ROUND(AVG(days_on_market)) as avg_dom,
                MIN(days_on_market) as min_dom,
                MAX(days_on_market) as max_dom,
                COUNT(*) as sales_count
            FROM {$table_prefix}bme_listing_summary
            WHERE standard_status = 'Closed'
            AND close_date >= DATE_SUB(%s, INTERVAL %d MONTH)
            AND close_date <= %s
            AND days_on_market IS NOT NULL
            AND days_on_market > 0
            AND days_on_market < 365
        ";

        $params = array($wp_now, $months, $wp_now);

        // Add location filters
        if (!empty($city)) {
            $query .= " AND city = %s";
            $params[] = $city;
        }

        if (!empty($state)) {
            $query .= " AND state_or_province = %s";
            $params[] = $state;
        }

        if ($property_type !== 'all') {
            $query .= " AND property_type = %s";
            $params[] = $property_type;
        }

        $query .= " GROUP BY DATE_FORMAT(close_date, '%%Y-%%m')
                    ORDER BY month ASC";

        $results = $this->wpdb->get_results($this->wpdb->prepare($query, $params));

        // Calculate summary statistics
        $monthly_data = array();
        $total_dom = 0;
        $total_sales = 0;

        foreach ($results as $row) {
            $monthly_data[] = array(
                'month' => $row->month,
                'month_label' => wp_date('M Y', strtotime($row->month . '-01')),
                'avg_dom' => intval($row->avg_dom),
                'min_dom' => intval($row->min_dom),
                'max_dom' => intval($row->max_dom),
                'sales_count' => intval($row->sales_count),
            );
            $total_dom += $row->avg_dom * $row->sales_count;
            $total_sales += $row->sales_count;
        }

        // Calculate trend (compare first half to second half)
        $trend = $this->calculate_trend($monthly_data, 'avg_dom');

        return array(
            'monthly' => $monthly_data,
            'average' => $total_sales > 0 ? round($total_dom / $total_sales) : null,
            'trend' => $trend,
            'trend_description' => $this->get_dom_trend_description($trend),
            'sample_size' => $total_sales,
        );
    }

    /**
     * Get List-to-Sale price ratio
     *
     * @param string $city City name
     * @param string $state State abbreviation
     * @param string $property_type Property type
     * @param int $months Number of months
     * @return array List-to-sale ratio data
     */
    public function get_list_to_sale_ratio($city, $state = '', $property_type = 'all', $months = 12) {
        $table_prefix = $this->wpdb->prefix;
        $wp_now = current_time('mysql');

        $query = "
            SELECT
                DATE_FORMAT(close_date, '%%Y-%%m') as month,
                AVG(close_price / NULLIF(list_price, 0)) as avg_ratio,
                COUNT(*) as sales_count
            FROM {$table_prefix}bme_listing_summary
            WHERE standard_status = 'Closed'
            AND close_date >= DATE_SUB(%s, INTERVAL %d MONTH)
            AND close_date <= %s
            AND close_price > 0
            AND list_price > 0
            AND close_price / list_price BETWEEN 0.5 AND 1.5
        ";

        $params = array($wp_now, $months, $wp_now);

        if (!empty($city)) {
            $query .= " AND city = %s";
            $params[] = $city;
        }

        if (!empty($state)) {
            $query .= " AND state_or_province = %s";
            $params[] = $state;
        }

        if ($property_type !== 'all') {
            $query .= " AND property_type = %s";
            $params[] = $property_type;
        }

        $query .= " GROUP BY DATE_FORMAT(close_date, '%%Y-%%m')
                    ORDER BY month ASC";

        $results = $this->wpdb->get_results($this->wpdb->prepare($query, $params));

        $monthly_data = array();
        $weighted_sum = 0;
        $total_sales = 0;

        foreach ($results as $row) {
            $ratio = floatval($row->avg_ratio);
            $monthly_data[] = array(
                'month' => $row->month,
                'month_label' => wp_date('M Y', strtotime($row->month . '-01')),
                'ratio' => round($ratio, 4),
                'percentage' => round($ratio * 100, 1),
                'sales_count' => intval($row->sales_count),
            );
            $weighted_sum += $ratio * $row->sales_count;
            $total_sales += $row->sales_count;
        }

        $average_ratio = $total_sales > 0 ? $weighted_sum / $total_sales : null;
        $trend = $this->calculate_trend($monthly_data, 'ratio');

        return array(
            'monthly' => $monthly_data,
            'average' => $average_ratio ? round($average_ratio, 4) : null,
            'average_percentage' => $average_ratio ? round($average_ratio * 100, 1) : null,
            'trend' => $trend,
            'trend_description' => $this->get_ratio_trend_description($average_ratio, $trend),
            'sample_size' => $total_sales,
        );
    }

    /**
     * Get inventory analysis (months of supply)
     *
     * @param string $city City name
     * @param string $state State abbreviation
     * @param string $property_type Property type
     * @return array Inventory data
     */
    public function get_inventory_analysis($city, $state = '', $property_type = 'all') {
        $table_prefix = $this->wpdb->prefix;
        $wp_now = current_time('mysql');

        // Count active listings
        $active_query = "
            SELECT COUNT(*) as active_count
            FROM {$table_prefix}bme_listing_summary
            WHERE standard_status = 'Active'
        ";

        $params = array();

        if (!empty($city)) {
            $active_query .= " AND city = %s";
            $params[] = $city;
        }

        if (!empty($state)) {
            $active_query .= " AND state_or_province = %s";
            $params[] = $state;
        }

        if ($property_type !== 'all') {
            $active_query .= " AND property_type = %s";
            $params[] = $property_type;
        }

        if (!empty($params)) {
            $active_count = $this->wpdb->get_var($this->wpdb->prepare($active_query, $params));
        } else {
            $active_count = $this->wpdb->get_var($active_query);
        }

        // Count pending listings
        $pending_query = str_replace("standard_status = 'Active'", "standard_status = 'Pending'", $active_query);
        if (!empty($params)) {
            $pending_count = $this->wpdb->get_var($this->wpdb->prepare($pending_query, $params));
        } else {
            $pending_count = $this->wpdb->get_var($pending_query);
        }

        // Get average monthly sales (last 3 months for more current data)
        $sales_query = "
            SELECT COUNT(*) / 3 as avg_monthly_sales
            FROM {$table_prefix}bme_listing_summary
            WHERE standard_status = 'Closed'
            AND close_date >= DATE_SUB(%s, INTERVAL 3 MONTH)
            AND close_date <= %s
        ";

        $sales_params = array($wp_now, $wp_now);

        if (!empty($city)) {
            $sales_query .= " AND city = %s";
            $sales_params[] = $city;
        }

        if (!empty($state)) {
            $sales_query .= " AND state_or_province = %s";
            $sales_params[] = $state;
        }

        if ($property_type !== 'all') {
            $sales_query .= " AND property_type = %s";
            $sales_params[] = $property_type;
        }

        $avg_monthly_sales = $this->wpdb->get_var($this->wpdb->prepare($sales_query, $sales_params));

        // Calculate months of supply
        $months_supply = ($avg_monthly_sales > 0) ?
            round($active_count / $avg_monthly_sales, 1) : null;

        // Determine market type
        $market_type = $this->get_market_type_from_supply($months_supply);

        return array(
            'active_listings' => intval($active_count),
            'pending_listings' => intval($pending_count),
            'avg_monthly_sales' => $avg_monthly_sales ? round($avg_monthly_sales, 1) : null,
            'months_of_supply' => $months_supply,
            'market_type' => $market_type['type'],
            'market_description' => $market_type['description'],
            'absorption_rate' => $avg_monthly_sales ? round($avg_monthly_sales * 100 / max($active_count, 1), 1) : null,
        );
    }

    /**
     * Get price trends over time
     *
     * @param string $city City name
     * @param string $state State abbreviation
     * @param string $property_type Property type
     * @param int $months Number of months
     * @return array Price trend data
     */
    public function get_price_trends($city, $state = '', $property_type = 'all', $months = 12) {
        $table_prefix = $this->wpdb->prefix;
        $wp_now = current_time('mysql');

        // Use simple average query - median calculation moved to fallback if needed
        // PERCENTILE_CONT is not supported in all MySQL versions (v6.20.2 fix)
        $query = "
            SELECT
                DATE_FORMAT(close_date, '%%Y-%%m') as month,
                AVG(close_price) as avg_price,
                AVG(close_price / NULLIF(building_area_total, 0)) as avg_price_per_sqft,
                COUNT(*) as sales_count
            FROM {$table_prefix}bme_listing_summary
            WHERE standard_status = 'Closed'
            AND close_date >= DATE_SUB(%s, INTERVAL %d MONTH)
            AND close_date <= %s
            AND close_price > 0
            AND building_area_total > 500
        ";

        $params = array($wp_now, $months, $wp_now);

        if (!empty($city)) {
            $query .= " AND city = %s";
            $params[] = $city;
        }

        if (!empty($state)) {
            $query .= " AND state_or_province = %s";
            $params[] = $state;
        }

        if ($property_type !== 'all') {
            $query .= " AND property_type = %s";
            $params[] = $property_type;
        }

        $query .= " GROUP BY DATE_FORMAT(close_date, '%%Y-%%m')
                    ORDER BY month ASC";

        $results = $this->wpdb->get_results($this->wpdb->prepare($query, $params));

        // If PERCENTILE_CONT is not supported (older MySQL), use fallback
        if ($this->wpdb->last_error) {
            $results = $this->get_price_trends_fallback($city, $state, $property_type, $months);
        }

        $monthly_data = array();
        $first_price = null;
        $last_price = null;

        foreach ($results as $row) {
            $avg_price = floatval($row->avg_price);
            if ($first_price === null) {
                $first_price = $avg_price;
            }
            $last_price = $avg_price;

            $monthly_data[] = array(
                'month' => $row->month,
                'month_label' => wp_date('M Y', strtotime($row->month . '-01')),
                'avg_price' => round($avg_price),
                'avg_price_per_sqft' => $row->avg_price_per_sqft ? round($row->avg_price_per_sqft) : null,
                'sales_count' => intval($row->sales_count),
            );
        }

        // Calculate appreciation
        $appreciation = ($first_price > 0 && $last_price > 0) ?
            (($last_price - $first_price) / $first_price) * 100 : null;

        // Annualized appreciation
        $annualized = $appreciation ? ($appreciation / $months) * 12 : null;

        $trend = $this->calculate_trend($monthly_data, 'avg_price');

        return array(
            'monthly' => $monthly_data,
            'period_appreciation' => $appreciation ? round($appreciation, 2) : null,
            'annualized_appreciation' => $annualized ? round($annualized, 2) : null,
            'trend' => $trend,
            'trend_description' => $this->get_price_trend_description($trend, $annualized),
            'sample_size' => count($results),
        );
    }

    /**
     * Fallback for price trends without window functions
     */
    private function get_price_trends_fallback($city, $state, $property_type, $months) {
        $table_prefix = $this->wpdb->prefix;
        $wp_now = current_time('mysql');

        $query = "
            SELECT
                DATE_FORMAT(close_date, '%%Y-%%m') as month,
                AVG(close_price) as avg_price,
                AVG(close_price / NULLIF(building_area_total, 0)) as avg_price_per_sqft,
                COUNT(*) as sales_count
            FROM {$table_prefix}bme_listing_summary
            WHERE standard_status = 'Closed'
            AND close_date >= DATE_SUB(%s, INTERVAL %d MONTH)
            AND close_date <= %s
            AND close_price > 0
            AND building_area_total > 500
        ";

        $params = array($wp_now, $months, $wp_now);

        if (!empty($city)) {
            $query .= " AND city = %s";
            $params[] = $city;
        }

        if (!empty($state)) {
            $query .= " AND state_or_province = %s";
            $params[] = $state;
        }

        if ($property_type !== 'all') {
            $query .= " AND property_type = %s";
            $params[] = $property_type;
        }

        $query .= " GROUP BY DATE_FORMAT(close_date, '%%Y-%%m')
                    ORDER BY month ASC";

        return $this->wpdb->get_results($this->wpdb->prepare($query, $params));
    }

    /**
     * Calculate overall market health
     *
     * @param array $dom_trends Days on market data
     * @param array $list_sale_ratio List-to-sale ratio data
     * @param array $inventory Inventory data
     * @param array $price_trends Price trend data
     * @return array Market health assessment
     */
    private function calculate_market_health($dom_trends, $list_sale_ratio, $inventory, $price_trends) {
        $score = 50; // Start neutral
        $factors = array();

        // DOM factor (-10 to +10)
        if (isset($dom_trends['average'])) {
            if ($dom_trends['average'] < 30) {
                $score += 10;
                $factors[] = 'Fast-moving market (low DOM)';
            } elseif ($dom_trends['average'] > 90) {
                $score -= 10;
                $factors[] = 'Slow market (high DOM)';
            }
        }

        // List-to-sale ratio factor (-10 to +10)
        if (isset($list_sale_ratio['average'])) {
            if ($list_sale_ratio['average'] >= 1.0) {
                $score += 10;
                $factors[] = 'Properties selling at or above list price';
            } elseif ($list_sale_ratio['average'] < 0.95) {
                $score -= 5;
                $factors[] = 'Properties selling below list price';
            }
        }

        // Inventory factor (-15 to +15)
        if (isset($inventory['months_of_supply'])) {
            $supply = $inventory['months_of_supply'];
            if ($supply < 3) {
                $score += 15;
                $factors[] = 'Low inventory (seller\'s market)';
            } elseif ($supply > 6) {
                $score -= 10;
                $factors[] = 'High inventory (buyer\'s market)';
            } else {
                $factors[] = 'Balanced inventory';
            }
        }

        // Price appreciation factor (-10 to +15)
        if (isset($price_trends['annualized_appreciation'])) {
            $appreciation = $price_trends['annualized_appreciation'];
            if ($appreciation > 10) {
                $score += 15;
                $factors[] = 'Strong price appreciation';
            } elseif ($appreciation > 5) {
                $score += 10;
                $factors[] = 'Healthy price growth';
            } elseif ($appreciation > 0) {
                $score += 5;
                $factors[] = 'Modest price growth';
            } elseif ($appreciation < -5) {
                $score -= 10;
                $factors[] = 'Price depreciation';
            }
        }

        // Determine health status
        $status = $this->get_health_status($score);

        return array(
            'score' => $score,
            'status' => $status['label'],
            'status_color' => $status['color'],
            'indicator' => $status['indicator'],
            'factors' => $factors,
            'summary' => $this->generate_market_summary($inventory['market_type'] ?? 'balanced', $score, $factors),
        );
    }

    /**
     * Get health status based on score
     */
    private function get_health_status($score) {
        if ($score >= 70) {
            return array(
                'label' => 'Hot Market',
                'color' => '#28a745',
                'indicator' => 'seller_market',
            );
        } elseif ($score >= 55) {
            return array(
                'label' => 'Healthy Market',
                'color' => '#5cb85c',
                'indicator' => 'slight_seller',
            );
        } elseif ($score >= 45) {
            return array(
                'label' => 'Balanced Market',
                'color' => '#f0ad4e',
                'indicator' => 'balanced',
            );
        } elseif ($score >= 30) {
            return array(
                'label' => 'Soft Market',
                'color' => '#fd7e14',
                'indicator' => 'slight_buyer',
            );
        } else {
            return array(
                'label' => 'Buyer\'s Market',
                'color' => '#dc3545',
                'indicator' => 'buyer_market',
            );
        }
    }

    /**
     * Get market type from months of supply
     */
    private function get_market_type_from_supply($months_supply) {
        if ($months_supply === null) {
            return array(
                'type' => 'unknown',
                'description' => 'Insufficient data to determine market type',
            );
        }

        if ($months_supply < 3) {
            return array(
                'type' => 'seller',
                'description' => 'Strong seller\'s market with high demand and limited inventory',
            );
        } elseif ($months_supply < 4) {
            return array(
                'type' => 'slight_seller',
                'description' => 'Slight seller\'s market with more buyers than available homes',
            );
        } elseif ($months_supply <= 6) {
            return array(
                'type' => 'balanced',
                'description' => 'Balanced market with equal supply and demand',
            );
        } elseif ($months_supply <= 8) {
            return array(
                'type' => 'slight_buyer',
                'description' => 'Slight buyer\'s market with more homes than active buyers',
            );
        } else {
            return array(
                'type' => 'buyer',
                'description' => 'Strong buyer\'s market with high inventory and negotiating power for buyers',
            );
        }
    }

    /**
     * Calculate trend (comparing first half to second half)
     */
    private function calculate_trend($monthly_data, $metric_key) {
        if (count($monthly_data) < 4) {
            return array(
                'direction' => 'insufficient_data',
                'change_percent' => null,
            );
        }

        $midpoint = (int) floor(count($monthly_data) / 2);
        $first_half = array_slice($monthly_data, 0, $midpoint);
        $second_half = array_slice($monthly_data, $midpoint);

        $first_avg = array_sum(array_column($first_half, $metric_key)) / count($first_half);
        $second_avg = array_sum(array_column($second_half, $metric_key)) / count($second_half);

        if ($first_avg == 0) {
            return array(
                'direction' => 'stable',
                'change_percent' => 0,
            );
        }

        $change = (($second_avg - $first_avg) / $first_avg) * 100;

        $direction = 'stable';
        if ($change > 5) {
            $direction = 'increasing';
        } elseif ($change < -5) {
            $direction = 'decreasing';
        }

        return array(
            'direction' => $direction,
            'change_percent' => round($change, 2),
        );
    }

    /**
     * Get DOM trend description
     */
    private function get_dom_trend_description($trend) {
        if ($trend['direction'] === 'increasing') {
            return 'Properties are taking longer to sell than earlier in the period.';
        } elseif ($trend['direction'] === 'decreasing') {
            return 'Properties are selling faster than earlier in the period.';
        }
        return 'Time on market has remained relatively stable.';
    }

    /**
     * Get ratio trend description
     */
    private function get_ratio_trend_description($average_ratio, $trend) {
        $base = '';
        if ($average_ratio >= 1.0) {
            $base = 'Sellers are getting full asking price or higher on average.';
        } elseif ($average_ratio >= 0.97) {
            $base = 'Sellers are achieving close to their asking price.';
        } else {
            $base = 'Buyers have negotiating power, with sales below list price.';
        }

        if ($trend['direction'] === 'increasing') {
            $base .= ' This ratio is trending upward.';
        } elseif ($trend['direction'] === 'decreasing') {
            $base .= ' This ratio is trending downward.';
        }

        return $base;
    }

    /**
     * Get price trend description
     */
    private function get_price_trend_description($trend, $annualized) {
        if ($annualized === null) {
            return 'Insufficient data to determine price trends.';
        }

        if ($annualized > 10) {
            return 'Prices are appreciating rapidly at ' . round($annualized, 1) . '% annually.';
        } elseif ($annualized > 5) {
            return 'Prices are appreciating at a healthy rate of ' . round($annualized, 1) . '% annually.';
        } elseif ($annualized > 0) {
            return 'Prices are showing modest growth at ' . round($annualized, 1) . '% annually.';
        } elseif ($annualized > -5) {
            return 'Prices are relatively flat with slight decline of ' . round(abs($annualized), 1) . '% annually.';
        } else {
            return 'Prices are declining at ' . round(abs($annualized), 1) . '% annually.';
        }
    }

    /**
     * Generate market summary narrative
     */
    private function generate_market_summary($market_type, $score, $factors) {
        $summary = '';

        if ($market_type === 'seller' || $market_type === 'slight_seller') {
            $summary = 'This is currently a seller\'s market. ';
            $summary .= 'Sellers may expect strong interest and competitive offers. ';
            $summary .= 'Buyers should be prepared to act quickly and potentially offer above asking price.';
        } elseif ($market_type === 'buyer' || $market_type === 'slight_buyer') {
            $summary = 'This is currently a buyer\'s market. ';
            $summary .= 'Buyers have more negotiating leverage and selection. ';
            $summary .= 'Sellers may need to price competitively and consider concessions.';
        } else {
            $summary = 'This market is relatively balanced. ';
            $summary .= 'Neither buyers nor sellers have a significant advantage. ';
            $summary .= 'Fair pricing and reasonable negotiations are typical.';
        }

        return $summary;
    }

    /**
     * Get sparkline data for charting
     * Returns simplified data points for mini-charts
     *
     * @param array $monthly_data Monthly data array
     * @param string $metric_key Key to extract
     * @return array Sparkline-ready data points
     */
    public function get_sparkline_data($monthly_data, $metric_key) {
        $data = array();
        foreach ($monthly_data as $month) {
            $data[] = array(
                'x' => $month['month'],
                'y' => isset($month[$metric_key]) ? $month[$metric_key] : 0,
            );
        }
        return $data;
    }

    /**
     * Get cached value
     */
    private function get_cached($key) {
        $transient_key = 'mld_mktcond_' . md5($key);
        return get_transient($transient_key);
    }

    /**
     * Set cached value
     */
    private function set_cached($key, $value) {
        $transient_key = 'mld_mktcond_' . md5($key);
        return set_transient($transient_key, $value, $this->cache_duration);
    }

    /**
     * Clear cache for a location
     */
    public function clear_cache($city = null, $state = null) {
        global $wpdb;
        $table_prefix = $wpdb->prefix;

        $wpdb->query(
            "DELETE FROM {$table_prefix}options
             WHERE option_name LIKE '_transient_mld_mktcond_%'
                OR option_name LIKE '_transient_timeout_mld_mktcond_%'"
        );

        return true;
    }
}
